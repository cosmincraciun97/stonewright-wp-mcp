<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Blueprints;

use Stonewright\WpMcp\DesignSpec\Validator;
use Stonewright\WpMcp\DesignTokens\BrandKit;
use Stonewright\WpMcp\Elementor\ElementorWriter;
use Stonewright\WpMcp\Renderers\GutenbergSpecRenderer;
use Stonewright\WpMcp\Security\Backup;
use Stonewright\WpMcp\Support\BlockSerializer;

/**
 * Applies a bundled blueprint DesignSpec to a new or existing WordPress page.
 *
 * Pipeline: load → optional brand kit / palette merge → Validator → create/update
 * page → Backup (when mutating an existing post) → render via Gutenberg or Elementor.
 */
final class BlueprintApplier {

	/**
	 * @param array<string, mixed> $args {
	 *     @type string               $blueprint_id Required.
	 *     @type string               $page_title   Optional page title override.
	 *     @type string               $mode         draft|publish. Default draft.
	 *     @type array<string,string> $palette_override Optional color map merged into tokens.colors.
	 *     @type string               $brand_kit    Optional brand kit id.
	 *     @type string               $engine       auto|gutenberg|elementor. Default auto.
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

		$validated = Validator::validate( $spec );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}
		$spec = $validated;

		$mode = isset( $args['mode'] ) ? (string) $args['mode'] : 'draft';
		if ( ! in_array( $mode, [ 'draft', 'publish' ], true ) ) {
			$mode = 'draft';
		}

		$engine = isset( $args['engine'] ) ? (string) $args['engine'] : 'auto';
		if ( ! in_array( $engine, [ 'auto', 'gutenberg', 'elementor' ], true ) ) {
			$engine = 'auto';
		}
		if ( 'auto' === $engine ) {
			$engine = self::elementor_available() ? 'elementor' : 'gutenberg';
		}
		if ( 'elementor' === $engine && ! self::elementor_available() ) {
			$engine = 'gutenberg';
		}

		$post_id     = isset( $args['post_id'] ) ? (int) $args['post_id'] : 0;
		$snapshot_id = '';
		$created     = false;

		if ( $post_id > 0 ) {
			$existing = get_post( $post_id );
			if ( ! $existing ) {
				return new \WP_Error(
					'stonewright_blueprint_post_not_found',
					__( 'Target post was not found.', 'stonewright' )
				);
			}
			$snapshot_id = Backup::snapshot_post( $post_id );
			if ( '' === $snapshot_id ) {
				return new \WP_Error(
					'stonewright_backup_failed',
					sprintf( 'Backup::snapshot_post failed for post %d. Write aborted.', $post_id )
				);
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
			$insert = wp_insert_post(
				[
					'post_type'    => 'page',
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
			// Snapshot the empty page so restore remains available after the write.
			$snapshot_id = Backup::snapshot_post( $post_id );
		}

		$diagnostics = [];
		if ( 'elementor' === $engine ) {
			$write = ElementorWriter::write( $post_id, $spec, $diagnostics );
			if ( is_wp_error( $write ) ) {
				return $write;
			}
		} else {
			$blocks = GutenbergSpecRenderer::render( $spec, $diagnostics );
			if ( is_wp_error( $blocks ) ) {
				return $blocks;
			}
			$content = BlockSerializer::serialize( $blocks );
			// ElementorWriter already snapshots; for Gutenberg on existing posts we already did.
			// On newly created pages the empty snapshot is already taken above.
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

		return [
			'ok'            => true,
			'page_id'       => $post_id,
			'post_id'       => $post_id,
			'blueprint_id'  => $blueprint_id,
			'brand_kit'     => $brand_kit_id,
			'engine'        => $engine,
			'mode'          => $mode,
			'created'       => $created,
			'snapshot_id'   => $snapshot_id,
			'spec_sha8'     => substr( sha1( $spec_json ), 0, 8 ),
			'edit_link'     => $edit_link,
			'diagnostics'   => $diagnostics,
		];
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

	private static function elementor_available(): bool {
		return defined( 'ELEMENTOR_VERSION' ) || class_exists( '\\Elementor\\Plugin' );
	}
}
