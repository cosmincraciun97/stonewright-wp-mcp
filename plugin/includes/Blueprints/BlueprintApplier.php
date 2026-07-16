<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Blueprints;

use Stonewright\WpMcp\DesignSpec\Migrator;
use Stonewright\WpMcp\DesignSpec\Validator;
use Stonewright\WpMcp\DesignTokens\BrandKit;
use Stonewright\WpMcp\Elementor\ElementorWriter;
use Stonewright\WpMcp\Gutenberg\EditorSnapshot;
use Stonewright\WpMcp\Gutenberg\FseTransactionQueue;
use Stonewright\WpMcp\Renderers\GutenbergSpecRenderer;
use Stonewright\WpMcp\Security\Backup;
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
	 *     @type string               $mode         draft|publish. Default draft.
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

		$mode = isset( $args['mode'] ) ? (string) $args['mode'] : 'draft';
		if ( ! in_array( $mode, [ 'draft', 'publish' ], true ) ) {
			$mode = 'draft';
		}

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
		$snapshot_id = '';
		$created     = false;
		$fse_meta    = [];

		if ( $post_id > 0 ) {
			$existing = get_post( $post_id );
			if ( ! $existing ) {
				return new \WP_Error(
					'stonewright_blueprint_post_not_found',
					__( 'Target post was not found.', 'stonewright' )
				);
			}
			// Pre-create snapshot for non-FSE paths; FSE path uses FseTransactionQueue.
			if ( 'fse' !== $engine ) {
				$snapshot_id = Backup::snapshot_post( $post_id );
				if ( '' === $snapshot_id ) {
					return new \WP_Error(
						'stonewright_backup_failed',
						sprintf( 'Backup::snapshot_post failed for post %d. Write aborted.', $post_id )
					);
				}
			}
			wp_update_post(
				[
					'ID'          => $post_id,
					'post_title'  => $page_title,
					'post_status' => $mode,
				],
				true
			);
		} else {
			$post_type = 'fse' === $engine ? 'page' : 'page';
			$insert    = wp_insert_post(
				[
					'post_type'    => $post_type,
					'post_title'   => $page_title,
					'post_status'  => $mode,
					'post_content' => '',
				],
				true
			);
			if ( is_wp_error( $insert ) ) {
				return $insert;
			}
			$post_id = (int) $insert;
			$created = true;
			if ( 'fse' !== $engine ) {
				// Snapshot the empty page so restore remains available after the write.
				$snapshot_id = Backup::snapshot_post( $post_id );
			}
		}

		$diagnostics = [];
		if ( 'elementor' === $engine ) {
			$write = ElementorWriter::write_transactional( $post_id, $spec, $diagnostics, true );
			if ( is_wp_error( $write ) ) {
				return $write;
			}
			if ( is_array( $write ) && ! empty( $write['snapshot_id'] ) ) {
				$snapshot_id = (string) $write['snapshot_id'];
			}
		} elseif ( 'fse' === $engine ) {
			$fse_result = self::apply_fse( $post_id, $spec, $page_title, $mode, $diagnostics );
			if ( is_wp_error( $fse_result ) ) {
				return $fse_result;
			}
			$snapshot_id = (string) ( $fse_result['snapshot_id'] ?? '' );
			$fse_meta    = $fse_result;
		} else {
			$blocks = GutenbergSpecRenderer::render( $spec, $diagnostics );
			if ( is_wp_error( $blocks ) ) {
				return $blocks;
			}
			$content = BlockSerializer::serialize( $blocks );
			if ( ! $created && '' === $snapshot_id ) {
				$snapshot_id = Backup::snapshot_post( $post_id );
			}
			$result = wp_update_post(
				[
					'ID'           => $post_id,
					'post_content' => $content,
					'post_title'   => $page_title,
					'post_status'  => $mode,
				],
				true
			);
			if ( is_wp_error( $result ) ) {
				return $result;
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
			'mode'             => $mode,
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
	private static function apply_fse( int $post_id, array $spec, string $page_title, string $mode, array &$diagnostics ) {
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

		$template_id = self::ensure_fse_template_post( $page_title, $content );

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
			return $txn;
		}

		// Keep page title/status in sync after content write.
		wp_update_post(
			[
				'ID'          => $post_id,
				'post_title'  => $page_title,
				'post_status' => $mode,
			],
			true
		);

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
	 * Create or reuse a custom wp_template post for the blueprint (best-effort).
	 */
	private static function ensure_fse_template_post( string $title, string $content ): int {
		$slug  = sanitize_title( $title );
		$slug  = '' !== $slug ? 'stonewright-blueprint-' . $slug : 'stonewright-blueprint';
		$theme = function_exists( 'get_stylesheet' ) ? sanitize_key( (string) get_stylesheet() ) : 'stonewright';
		if ( '' === $theme ) {
			$theme = 'stonewright';
		}

		$existing = get_posts(
			[
				'post_type'      => 'wp_template',
				'name'           => $theme . '//' . $slug,
				'posts_per_page' => 1,
				'post_status'    => [ 'publish', 'draft', 'auto-draft' ],
			]
		);
		$found = self::first_post_id( is_array( $existing ) ? $existing : [] );
		if ( $found > 0 ) {
			return $found;
		}

		// Also match by post_name slug alone in unit bootstrap.
		$by_slug = get_posts(
			[
				'post_type'      => 'wp_template',
				'name'           => $slug,
				'posts_per_page' => 1,
				'post_status'    => [ 'publish', 'draft', 'auto-draft' ],
			]
		);
		$found = self::first_post_id( is_array( $by_slug ) ? $by_slug : [] );
		if ( $found > 0 ) {
			return $found;
		}

		$insert = wp_insert_post(
			[
				'post_type'    => 'wp_template',
				'post_name'    => $theme . '//' . $slug,
				'post_title'   => $title,
				'post_content' => $content,
				'post_status'  => 'publish',
				'post_excerpt' => 'Stonewright blueprint FSE template',
			],
			true
		);
		if ( is_wp_error( $insert ) ) {
			return 0;
		}
		$template_id = (int) $insert;
		if ( $template_id > 0 ) {
			update_post_meta( $template_id, 'theme', $theme );
			update_post_meta( $template_id, '_stonewright_blueprint_template', '1' );
		}
		return $template_id;
	}

	/**
	 * @param list<mixed> $posts
	 */
	private static function first_post_id( array $posts ): int {
		if ( ! isset( $posts[0] ) || ! is_object( $posts[0] ) ) {
			return 0;
		}
		/** @var object{ID?: int|string} $post */
		$post = $posts[0];
		return isset( $post->ID ) ? (int) $post->ID : 0;
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
