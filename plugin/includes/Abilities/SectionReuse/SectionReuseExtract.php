<?php
/**
 * Extracts one section of a saved page as a portable payload.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\SectionReuse;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\SectionReuse\Builder;
use Stonewright\WpMcp\SectionReuse\PortableSection;
use Stonewright\WpMcp\SectionReuse\ReferenceCatalog;
use Stonewright\WpMcp\SectionReuse\ReuseWarnings;
use Stonewright\WpMcp\SectionReuse\SectionReuseSetting;
use Stonewright\WpMcp\SectionReuse\SectionSource;
use Stonewright\WpMcp\Security\Permissions;

/**
 * Returns the chosen section in its own builder's format with nothing that ties it to its source, the full
 * list of references it carries (global colors and fonts, global classes and variables, dynamic tags, media,
 * forms, synced patterns, nested templates, global widgets, third-party widgets) and its layout summary.
 * Read-only: neither the source nor any other post is changed.
 *
 * @stonewright-status stable
 */
final class SectionReuseExtract extends AbilityKernel {

	public function name(): string {
		return 'stonewright/section-reuse-extract';
	}

	public function label(): string {
		return __( 'Extract a section for reuse', 'stonewright' );
	}

	public function description(): string {
		return __( 'Returns one section of a saved page, post, Elementor template or Gutenberg pattern as a portable payload in its own builder format: Elementor element ids and V4 local style ids are replaced by placeholders, Gutenberg anchors are listed, and every reference (global colors, fonts, classes, variables, dynamic tags, media, forms, synced patterns, templates) is reported with whether it exists here. Changes nothing. Pass the payload as the section of an insert_section operation in the same batch as your adaptations.', 'stonewright' );
	}

	public function category(): string {
		return 'site';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'post_id' => [ 'type' => 'integer', 'minimum' => 1, 'description' => 'The source post, from a candidate of stonewright-section-reuse-find.' ],
				'locator' => [
					'type'                 => 'object',
					'additionalProperties' => false,
					'description'          => 'The candidate locator: {kind:"element", id} for Elementor, {kind:"block", path} or {anchor} for Gutenberg, {kind:"pattern"} for a pattern.',
					'properties'           => [
						'kind'   => [ 'type' => 'string', 'enum' => [ 'element', 'block', 'pattern' ] ],
						'id'     => [ 'type' => 'string', 'maxLength' => 64 ],
						'path'   => [ 'type' => 'array', 'maxItems' => 12, 'items' => [ 'type' => 'integer', 'minimum' => 0 ] ],
						'anchor' => [ 'type' => 'string', 'maxLength' => 64 ],
						'synced' => [ 'type' => 'boolean' ],
					],
				],
				'builder' => [ 'type' => 'string', 'enum' => Builder::all(), 'description' => 'Optional. Fails when the section belongs to another builder.' ],
			],
			'required'             => [ 'post_id', 'locator' ],
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'enabled'      => [ 'type' => 'boolean' ],
				'instruction'  => [ 'type' => 'string' ],
				'ok'           => [ 'type' => 'boolean' ],
				'builder'      => [ 'type' => 'string' ],
				'source'       => [ 'type' => 'object' ],
				'section'      => [ 'type' => 'object' ],
				'references'   => [ 'type' => 'array', 'items' => [ 'type' => 'object' ] ],
				'layout'       => [ 'type' => 'object' ],
				'outline'      => [ 'type' => 'object' ],
				'warnings'     => [ 'type' => 'array', 'items' => [ 'type' => 'object' ] ],
				'stats'        => [ 'type' => 'object' ],
				'next_step'    => [ 'type' => 'string' ],
			],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		$post_id = (int) ( $args['post_id'] ?? 0 );

		return Permissions::edit_post( $post_id ) && current_user_can( 'read_post', $post_id );
	}

	public function execute( array $args ): array|\WP_Error {
		return $this->audit_read(
			$args,
			function ( array $args ) {
				// The live option, not the tool list: a client may keep a stale list.
				if ( ! SectionReuseSetting::is_enabled() ) {
					return SectionReuseSetting::off_error();
				}
				$post_id = (int) ( $args['post_id'] ?? 0 );
				$post    = get_post( $post_id );
				if ( ! is_object( $post ) || ! in_array( (string) $post->post_status, [ 'publish', 'draft' ], true ) || in_array( (string) $post->post_type, [ 'revision', 'attachment' ], true ) ) {
					return $this->error( 'not_found', __( 'The source post was not found, or it is not a published or draft page, post, template or pattern.', 'stonewright' ), [ 'status' => 404 ] );
				}
				$locator = $this->locator( is_array( $args['locator'] ?? null ) ? $args['locator'] : [], $post );
				$section = SectionSource::find( $post, $locator );
				if ( null === $section ) {
					return $this->error( 'section_not_found', __( 'No section of that source matches the locator. Use a locator from stonewright-section-reuse-find.', 'stonewright' ), [ 'status' => 404, 'locator' => $locator ] );
				}
				$requested = (string) ( $args['builder'] ?? '' );
				if ( '' !== $requested && $requested !== $section['builder'] ) {
					return $this->error(
						'builder_mismatch',
						__( 'The section belongs to a different builder; reuse never converts between builders.', 'stonewright' ),
						[ 'status' => 409, 'section_builder' => $section['builder'], 'requested_builder' => $requested ]
					);
				}
				$extracted = PortableSection::extract( $post, $section );
				if ( $extracted instanceof \WP_Error ) {
					return $extracted;
				}
				$references = ReferenceCatalog::annotate( $extracted['inspection']['references'] );
				$layout     = $extracted['layout'];
				$gutenberg  = Builder::GUTENBERG === $section['builder'];

				return [
					'enabled'    => true,
					'ok'         => true,
					'builder'    => $section['builder'],
					'source'     => [
						'post_id'      => $post_id,
						'type'         => (string) $post->post_type,
						'kind'         => SectionSource::kind( $post ),
						'title'        => mb_substr( trim( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( (string) $post->post_title ) ) ), 0, 80 ),
						'status'       => (string) $post->post_status,
						'edit_url'     => (string) get_edit_post_link( $post_id, 'raw' ),
						'modified_gmt' => SectionSource::modified( $post ),
					],
					'section'    => $extracted['section'],
					'references' => $references,
					'layout'     => $layout,
					'outline'    => $extracted['inspection']['outline'],
					'warnings'   => ReuseWarnings::from( $references ),
					'stats'      => $extracted['stats'],
					'next_step'  => $gutenberg
						? 'Insert it with an insert_section operation of stonewright-blocks-batch-mutate. Address its blocks in the same batch with section_ref and relative_path.'
						: 'Insert it with an insert_section operation (elementor-v3-batch-mutate) or the V4 operations of elementor-v4-update-node. Address its elements in the same batch as @<op_id>.<placeholder>.',
				];
			}
		);
	}

	/**
	 * The locator with its kind filled in when the caller left it out.
	 *
	 * @param array<string, mixed> $locator
	 * @return array<string, mixed>
	 */
	private function locator( array $locator, object $post ): array {
		if ( isset( $locator['kind'] ) && is_string( $locator['kind'] ) ) {
			return $locator;
		}
		if ( isset( $locator['id'] ) ) {
			$locator['kind'] = 'element';
		} elseif ( isset( $locator['path'] ) || isset( $locator['anchor'] ) ) {
			$locator['kind'] = 'block';
		} elseif ( SectionSource::PATTERN_POST_TYPE === (string) $post->post_type ) {
			$locator['kind'] = 'pattern';
		}

		return $locator;
	}
}
