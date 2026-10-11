<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Blueprints;

use Stonewright\WpMcp\DesignSpec\Migrator;
use Stonewright\WpMcp\DesignSpec\Validator;
use Stonewright\WpMcp\DesignTokens\BrandKit;
use Stonewright\WpMcp\Elementor\ElementorWriter;
use Stonewright\WpMcp\FSE\TemplateStore;
use Stonewright\WpMcp\Gutenberg\EditorSnapshot;
use Stonewright\WpMcp\Gutenberg\FseTransactionQueue;
use Stonewright\WpMcp\Renderers\GutenbergSpecRenderer;
use Stonewright\WpMcp\Security\Backup;
use Stonewright\WpMcp\Security\Permissions;
use Stonewright\WpMcp\Security\RescueGuard;
use Stonewright\WpMcp\Security\RuleEnforcer;
use Stonewright\WpMcp\Support\BlockSerializer;

/**
 * Applies a bundled blueprint DesignSpec to a new or existing WordPress page.
 *
 * Pipeline: load → optional brand kit / palette merge → Validator → create/update
 * page → Backup (when mutating an existing post) → render via Gutenberg, Elementor, or FSE.
 */
final class BlueprintApplier {

	/**
	 * @param array<string, mixed> $args {
	 *     @type string               $blueprint_id Required.
	 *     @type string               $page_title   Optional page title override.
	 *     @type string               $mode         draft|publish. A new page defaults to draft; an existing page keeps its status unless this is passed.
	 *     @type array<string,string> $palette_override Optional color map merged into tokens.colors.
	 *     @type string               $brand_kit    Optional brand kit id.
	 *     @type string               $engine       auto|gutenberg|elementor|fse. Default auto.
	 *     @type int                  $post_id      Optional existing page id.
	 * }
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function apply( array $args ) {
		$blueprint_id = sanitize_key( (string) ( $args['blueprint_id'] ?? '' ) );
		if ( '' === $blueprint_id ) {
			return new \WP_Error(
				'stonewright_blueprint_invalid_id',
				__( 'blueprint_id is required.', 'stonewright' )
			);
		}

		$blueprint = BlueprintStore::get( $blueprint_id );
		if ( is_wp_error( $blueprint ) ) {
			return $blueprint;
		}

		$spec = is_array( $blueprint['spec'] ?? null ) ? $blueprint['spec'] : [];
		if ( [] === $spec ) {
			return new \WP_Error(
				'stonewright_blueprint_empty_spec',
				__( 'Blueprint is missing a DesignSpec payload.', 'stonewright' )
			);
		}

		$brand_kit_id = sanitize_key( (string) ( $args['brand_kit'] ?? '' ) );
		if ( '' !== $brand_kit_id ) {
			$kit = BrandKit::get( $brand_kit_id );
			if ( is_wp_error( $kit ) ) {
				return $kit;
			}
			$spec = BrandKit::merge_into_spec( $spec, $kit );
		} elseif ( ! empty( $blueprint['palette'] ) || ! empty( $blueprint['fonts'] ) ) {
			$spec = self::merge_blueprint_tokens( $spec, $blueprint );
		}

		$palette_override = isset( $args['palette_override'] ) && is_array( $args['palette_override'] )
			? $args['palette_override']
			: [];
		if ( [] !== $palette_override ) {
			$spec = self::merge_palette_override( $spec, $palette_override );
		}

		$page_title = isset( $args['page_title'] ) ? trim( (string) $args['page_title'] ) : '';
		if ( '' === $page_title ) {
			$page_title = (string) ( $blueprint['name'] ?? $blueprint_id );
		}
		$spec['page'] = isset( $spec['page'] ) && is_array( $spec['page'] ) ? $spec['page'] : [];
		$spec['page']['title'] = $page_title;

		// Promote v1 specs so content_facts / native_policy become real constraints.
		if ( class_exists( Migrator::class ) && method_exists( Migrator::class, 'v1_to_v2' ) ) {
			$version = (string) ( $spec['version'] ?? '1.0.0' );
			if ( ! str_starts_with( $version, '2.' ) ) {
				$spec = Migrator::v1_to_v2( $spec );
			}
		}

		$validated = Validator::validate( $spec );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}
		$spec = $validated;

		// A page that already exists keeps its status unless the caller names one; a new page is a draft.
		$mode_passed = isset( $args['mode'] ) && in_array( (string) $args['mode'], [ 'draft', 'publish' ], true );
		$mode        = $mode_passed ? (string) $args['mode'] : 'draft';

		$engine_requested = isset( $args['engine'] ) ? (string) $args['engine'] : 'auto';
		if ( ! in_array( $engine_requested, [ 'auto', 'gutenberg', 'elementor', 'fse' ], true ) ) {
			$engine_requested = 'auto';
		}
		$engine = $engine_requested;
		if ( 'auto' === $engine ) {
			$engine = self::elementor_available() ? 'elementor' : 'gutenberg';
		}
		if ( 'elementor' === $engine && ! self::elementor_available() ) {
			return new \WP_Error(
				'stonewright_engine_unavailable',
				__( 'Elementor is not active on this site. Install/activate Elementor or pass engine=gutenberg.', 'stonewright' ),
				[
					'status'           => 400,
					'engine_requested' => 'elementor',
				]
			);
		}
		if ( 'fse' === $engine ) {
			if ( ! Permissions::can_manage_fse() ) {
				return new \WP_Error(
					'stonewright_forbidden',
					__( 'The fse engine writes a template and needs the site editor capabilities (manage_options and edit_theme_options).', 'stonewright' ),
					[
						'status'           => 403,
						'engine_requested' => 'fse',
					]
				);
			}
			if ( ! function_exists( 'parse_blocks' ) && ! class_exists( BlockSerializer::class ) ) {
				return new \WP_Error(
					'stonewright_engine_unavailable',
					__( 'FSE/block editor APIs are not available on this site.', 'stonewright' ),
					[
						'status'           => 400,
						'engine_requested' => 'fse',
					]
				);
			}
		}

		$post_id     = isset( $args['post_id'] ) ? (int) $args['post_id'] : 0;
		$snapshot_id        = '';
		$before_snapshot_id = '';
		$created            = false;
		$fse_meta    = [];
		$title_done  = false;
		$final_status = null;

		if ( $post_id > 0 ) {
			$existing = get_post( $post_id );
			if ( ! $existing ) {
				return new \WP_Error(
					'stonewright_blueprint_post_not_found',
					__( 'Target post was not found.', 'stonewright' )
				);
			}
			// The page as it is now, before the title and status change below and before the engine writes.
			// This is the snapshot the result returns: the engines snapshot again, but only after the change.
			$snapshot_id = Backup::snapshot_post( $post_id );
			if ( '' === $snapshot_id ) {
				return RuleEnforcer::attribute(
					new \WP_Error(
						'stonewright_backup_failed',
						sprintf( 'Backup::snapshot_post failed for post %d. Write aborted.', $post_id )
					),
					'backup-before-write'
				);
			}
			$before_snapshot_id = $snapshot_id;
			// The title, and the status when the caller passed one, change after the engine has written.
			$final_status = $mode_passed ? $mode : null;
		} else {
			// A new page starts as a draft: nothing is public until the render and the write have worked.
			$insert = wp_insert_post(
				wp_slash(
					[
						'post_type'    => 'page',
						'post_title'   => $page_title,
						'post_status'  => 'draft',
						'post_content' => '',
					]
				),
				true
			);
			if ( is_wp_error( $insert ) ) {
				return $insert;
			}
			$post_id = (int) $insert;
			$created = true;
			RescueGuard::note_post_created( $post_id );
			$final_status = 'draft' !== $mode ? $mode : null;
			if ( 'fse' !== $engine ) {
				// Snapshot the empty page so restore remains available after the write.
				$snapshot_id = Backup::snapshot_post( $post_id );
			}
		}

		$diagnostics = [];
		if ( 'elementor' === $engine ) {
			$write = ElementorWriter::write_transactional( $post_id, $spec, $diagnostics, true );
			if ( is_wp_error( $write ) ) {
				return self::abort( $created, $post_id, $write );
			}
			if ( '' === $before_snapshot_id && is_array( $write ) && ! empty( $write['snapshot_id'] ) ) {
				$snapshot_id = (string) $write['snapshot_id'];
			}
		} elseif ( 'fse' === $engine ) {
			$fse_result = self::apply_fse( $post_id, $spec, $page_title, $diagnostics );
			if ( is_wp_error( $fse_result ) ) {
				return self::abort( $created, $post_id, $fse_result );
			}
			if ( '' === $before_snapshot_id ) {
				$snapshot_id = (string) ( $fse_result['snapshot_id'] ?? '' );
			}
			$fse_meta = $fse_result;
		} else {
			$blocks = GutenbergSpecRenderer::render( $spec, $diagnostics );
			if ( is_wp_error( $blocks ) ) {
				return self::abort( $created, $post_id, $blocks );
			}
			$content = BlockSerializer::serialize( $blocks );
			if ( ! $created && '' === $snapshot_id ) {
				$snapshot_id = Backup::snapshot_post( $post_id );
			}
			$payload = [
				'ID'           => $post_id,
				'post_content' => $content,
				'post_title'   => $page_title,
			];
			// An existing page takes its new status together with its content; a new page gets it below.
			if ( ! $created && null !== $final_status ) {
				$payload['post_status'] = $final_status;
				$final_status           = null;
			}
			$failure = self::update_failure( wp_update_post( wp_slash( $payload ), true ) );
			if ( null !== $failure ) {
				return self::abort( $created, $post_id, $failure );
			}
			$title_done = true;
		}

		// The page is written: now it gets the status it was asked for (and the title, for the engines that
		// wrote through their own transaction).
		$finish = [ 'ID' => $post_id ];
		if ( ! $created && ! $title_done ) {
			$finish['post_title'] = $page_title;
		}
		if ( null !== $final_status ) {
			$finish['post_status'] = $final_status;
		}
		if ( count( $finish ) > 1 ) {
			$failure = self::update_failure( wp_update_post( wp_slash( $finish ), true ) );
			if ( null !== $failure ) {
				return self::abort( $created, $post_id, $failure );
			}
		}

		$edit_link = get_edit_post_link( $post_id, 'raw' );
		if ( ! is_string( $edit_link ) ) {
			$edit_link = admin_url( 'post.php?post=' . $post_id . '&action=edit' );
		}

		$spec_json = (string) wp_json_encode( $spec, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		$out = [
			'ok'               => true,
			'page_id'          => $post_id,
			'post_id'          => $post_id,
			'blueprint_id'     => $blueprint_id,
			'brand_kit'        => $brand_kit_id,
			'engine'           => $engine,
			'engine_requested' => $engine_requested,
			'engine_used'      => $engine,
			'mode'             => (string) ( get_post( $post_id )->post_status ?? $mode ),
			'created'          => $created,
			'snapshot_id'      => $snapshot_id,
			'spec_sha8'        => substr( sha1( $spec_json ), 0, 8 ),
			'edit_link'        => $edit_link,
			'diagnostics'      => $diagnostics,
			'qa'               => \Stonewright\WpMcp\DesignSpec\QaReport::for_spec( $spec ),
		];

		if ( [] !== $fse_meta ) {
			$out['fse'] = [
				'editor_snapshot' => $fse_meta['editor_snapshot'] ?? null,
				'transaction'     => $fse_meta['transaction'] ?? null,
				'template_id'     => $fse_meta['template_id'] ?? 0,
			];
		}

		return $out;
	}

	/**
	 * FSE engine: constrained block markup + EditorSnapshot + FseTransactionQueue write.
	 *
	 * Writes the page content and, when possible, a companion wp_template post so the
	 * site can adopt the layout in a block theme. Never silently remaps to "gutenberg".
	 *
	 * @param array<string, mixed>             $spec
	 * @param array<int, array<string, mixed>> $diagnostics
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function apply_fse( int $post_id, array $spec, string $page_title, array &$diagnostics ) {
		$editor_snapshot = EditorSnapshot::capture();

		$blocks = GutenbergSpecRenderer::render( $spec, $diagnostics );
		if ( is_wp_error( $blocks ) ) {
			return $blocks;
		}

		// Wrap in a constrained layout group for FSE / block-theme content width.
		$wrapped = [
			[
				'blockName'    => 'core/group',
				'attrs'        => [
					'layout'      => [
						'type'           => 'constrained',
						'contentSize'    => '720px',
						'wideSize'       => '1100px',
						'justifyContent' => 'center',
					],
					'className'   => 'stonewright-fse-root',
					'metadata'    => [
						'name' => 'Stonewright FSE blueprint',
					],
					'align'       => 'full',
				],
				'innerBlocks'  => $blocks,
				'innerHTML'    => '',
				'innerContent' => array_fill( 0, max( 1, count( $blocks ) ), null ),
			],
		];
		// Ensure innerContent has correct placeholders for serializer.
		$wrapped[0]['innerContent'] = [];
		foreach ( $blocks as $_ ) {
			$wrapped[0]['innerContent'][] = null;
		}

		$content = BlockSerializer::serialize( $wrapped );
		if ( '' === trim( $content ) ) {
			return new \WP_Error(
				'stonewright_fse_render_empty',
				__( 'FSE renderer produced empty block markup.', 'stonewright' ),
				[ 'status' => 500 ]
			);
		}

		$template_created = false;
		$template_id      = self::ensure_fse_template_post( $page_title, $content, $template_created );

		$queue = ( new FseTransactionQueue() )
			->stop_on_error( true )
			->rollback_on_error( true )
			->enqueue(
				[
					'type'    => 'post',
					'post_id' => $post_id,
					'content' => $content,
					'label'   => 'blueprint-page',
				]
			);

		if ( $template_id > 0 ) {
			$queue->enqueue(
				[
					'type'    => 'template',
					'post_id' => $template_id,
					'content' => $content,
					'label'   => 'blueprint-template',
				]
			);
		}

		$txn = $queue->apply();
		if ( is_wp_error( $txn ) ) {
			if ( $template_created && $template_id > 0 ) {
				wp_delete_post( $template_id, true );
			}
			return $txn;
		}

		$snapshots   = is_array( $txn['snapshots'] ?? null ) ? $txn['snapshots'] : [];
		$snapshot_id = '';
		if ( isset( $snapshots[0]['snapshot_id'] ) ) {
			$snapshot_id = (string) $snapshots[0]['snapshot_id'];
		}

		return [
			'snapshot_id'     => $snapshot_id,
			'editor_snapshot' => [
				'ok'          => (bool) ( $editor_snapshot['ok'] ?? false ),
				'theme_type'  => (string) ( $editor_snapshot['theme']['type'] ?? '' ),
				'block_theme' => (bool) ( $editor_snapshot['capabilities']['block_theme'] ?? false ),
			],
			'transaction'     => [
				'phase'         => (string) ( $txn['phase'] ?? 'applied' ),
				'target_count'  => count( $queue->targets() ),
				'written'       => $txn['written'] ?? [],
				'snapshot_ids'  => array_column( $snapshots, 'snapshot_id' ),
			],
			'template_id'     => $template_id,
		];
	}

	/**
	 * Returns the error of a write that failed, and removes the page this apply created: a page whose
	 * render or write failed is not left behind. A page that existed before is never deleted.
	 */
	private static function abort( bool $created, int $post_id, \WP_Error $error ): \WP_Error {
		if ( $created && $post_id > 0 ) {
			wp_delete_post( $post_id, true );
		}
		return $error;
	}

	/**
	 * The error for a wp_update_post() result that is not a saved post: a WP_Error, or the 0 it returns when
	 * it did not save. Null when the update worked.
	 *
	 * @param int|\WP_Error $result
	 */
	private static function update_failure( $result ): ?\WP_Error {
		if ( $result instanceof \WP_Error ) {
			return $result;
		}
		if ( (int) $result < 1 ) {
			return new \WP_Error(
				'stonewright_blueprint_update_failed',
				__( 'WordPress did not save the page.', 'stonewright' ),
				[ 'status' => 500 ]
			);
		}
		return null;
	}

	/**
	 * Create or reuse a custom wp_template post for the blueprint (best-effort).
	 *
	 * The post is stored the way core stores a customized template: post_name is the bare slug and the
	 * wp_theme term names the theme.
	 *
	 * @param bool $created Set to true when this call created the template post.
	 */
	private static function ensure_fse_template_post( string $title, string $content, bool &$created = false ): int {
		$slug  = sanitize_title( $title );
		$slug  = '' !== $slug ? 'stonewright-blueprint-' . $slug : 'stonewright-blueprint';
		$theme = function_exists( 'get_stylesheet' ) ? sanitize_key( (string) get_stylesheet() ) : 'stonewright';
		if ( '' === $theme ) {
			$theme = 'stonewright';
		}

		// TemplateStore resolves the template through core and also finds one an earlier version stored
		// under a composite post_name, so a repeated apply reuses it.
		$existing = TemplateStore::find( $slug, $theme, TemplateStore::TEMPLATE );
		if ( null !== $existing ) {
			return (int) $existing->ID;
		}

		$insert = TemplateStore::insert(
			TemplateStore::TEMPLATE,
			$slug,
			$theme,
			[
				'post_title'   => $title,
				'post_content' => $content,
				'post_excerpt' => 'Stonewright blueprint FSE template',
			]
		);
		if ( is_wp_error( $insert ) ) {
			return 0;
		}
		$created = true;
		update_post_meta( $insert, '_stonewright_blueprint_template', '1' );
		return $insert;
	}

	/**
	 * @param array<string, mixed> $spec
	 * @param array<string, mixed> $blueprint
	 * @return array<string, mixed>
	 */
	private static function merge_blueprint_tokens( array $spec, array $blueprint ): array {
		$tokens     = isset( $spec['tokens'] ) && is_array( $spec['tokens'] ) ? $spec['tokens'] : [];
		$colors     = isset( $tokens['colors'] ) && is_array( $tokens['colors'] ) ? $tokens['colors'] : [];
		$typography = isset( $tokens['typography'] ) && is_array( $tokens['typography'] ) ? $tokens['typography'] : [];

		foreach ( (array) ( $blueprint['palette'] ?? [] ) as $key => $value ) {
			if ( is_string( $key ) && is_string( $value ) && '' !== $value ) {
				$colors[ $key ] = $value;
			}
		}
		foreach ( (array) ( $blueprint['fonts'] ?? [] ) as $key => $value ) {
			if ( is_string( $key ) && is_string( $value ) && '' !== $value ) {
				$typography[ $key ] = [ 'font_family' => $value ];
			}
		}

		// Schema allows colors/typography/spacing/radius/shadow only — never freeform fonts.
		unset( $tokens['fonts'] );

		if ( [] !== $colors ) {
			$tokens['colors'] = $colors;
		}
		if ( [] !== $typography ) {
			$tokens['typography'] = $typography;
		}
		if ( [] !== $tokens ) {
			$spec['tokens'] = $tokens;
		}
		return $spec;
	}

	/**
	 * @param array<string, mixed>  $spec
	 * @param array<string, mixed>  $palette
	 * @return array<string, mixed>
	 */
	private static function merge_palette_override( array $spec, array $palette ): array {
		$tokens = isset( $spec['tokens'] ) && is_array( $spec['tokens'] ) ? $spec['tokens'] : [];
		$colors = isset( $tokens['colors'] ) && is_array( $tokens['colors'] ) ? $tokens['colors'] : [];
		foreach ( $palette as $key => $value ) {
			if ( is_string( $key ) && is_string( $value ) && '' !== $value ) {
				$colors[ sanitize_key( $key ) ] = $value;
			}
		}
		$tokens['colors'] = $colors;
		$spec['tokens']   = $tokens;
		return $spec;
	}

	/**
	 * Test hook: when non-null, overrides live Elementor detection.
	 *
	 * @var bool|null
	 */
	public static $test_elementor_available = null;

	private static function elementor_available(): bool {
		if ( null !== self::$test_elementor_available ) {
			return (bool) self::$test_elementor_available;
		}
		return defined( 'ELEMENTOR_VERSION' ) || class_exists( '\\Elementor\\Plugin' );
	}
}
