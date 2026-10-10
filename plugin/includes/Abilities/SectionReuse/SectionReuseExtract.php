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
use Stonewright\WpMcp\SectionReuse\ElementorInsertWarnings;
use Stonewright\WpMcp\SectionReuse\LegacyBlockAttributes;
use Stonewright\WpMcp\SectionReuse\PortableSection;
use Stonewright\WpMcp\SectionReuse\ReferenceCatalog;
use Stonewright\WpMcp\SectionReuse\ReuseWarnings;
use Stonewright\WpMcp\SectionReuse\SectionReuseSetting;
use Stonewright\WpMcp\SectionReuse\SectionSource;
use Stonewright\WpMcp\SectionReuse\SourceScanner;
use Stonewright\WpMcp\SectionReuse\SourceWarnings;
use Stonewright\WpMcp\Security\Permissions;

/**
 * Returns the chosen section, a top-level one or an Elementor container nested inside one, in its own builder's format with nothing that ties it to its source, the full
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
		return __( 'Returns one section of a saved page, post, Elementor template or Gutenberg pattern as a portable payload in its own builder format: Elementor element ids and V4 local style ids are replaced by placeholders, Gutenberg anchors are listed, and every reference (global colors, fonts, classes, variables, dynamic tags, media, forms, synced patterns, templates) is reported with whether it exists here. The source may be published, draft, pending, scheduled or private when the current user can edit it; never change the status of a post to extract from it. For Elementor, the locator is the id of a top-level element or of a container nested at any depth (a V3 container or section, or a V4 div block or flexbox, with its children). Changes nothing. Pass the payload as the section of an insert_section operation in the same batch as your adaptations.', 'stonewright' );
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
					'description'          => 'The candidate locator: {kind:"element", id} for Elementor (a top-level element or a nested container, by its element id), {kind:"block", path} or {anchor} for Gutenberg, {kind:"pattern"} for a pattern.',
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
				// A post that exists but may not be edited answers like one that does not exist.
				if ( ! SectionSource::is_source( $post ) || ! SourceScanner::may_use( $post_id ) ) {
					return $this->error( 'not_found', self::source_not_found(), [ 'status' => 404, 'repair' => self::source_repair() ] );
				}
				$locator = $this->locator( is_array( $args['locator'] ?? null ) ? $args['locator'] : [], $post );
				$found   = SectionSource::resolve( $post, $locator );
				$section = $found['section'];
				if ( null === $section ) {
					return $this->locator_error( $found['problem'] ?? [ 'code' => 'not_found', 'reason' => 'no_match' ], $locator );
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
					'warnings'   => array_merge(
						ReuseWarnings::from( $references ),
						SourceWarnings::for_post( $post ),
						LegacyBlockAttributes::warnings( $extracted['migrated'] ),
						ElementorInsertWarnings::for_section( $section['builder'], is_array( $extracted['section']['element'] ?? null ) ? $extracted['section']['element'] : [] )
					),
					'stats'      => $extracted['stats'],
					'next_step'  => $gutenberg
						? 'Insert it with an insert_section operation of stonewright-blocks-batch-mutate. Address its blocks in the same batch with section_ref and relative_path.'
						: 'Insert it with an insert_section operation (elementor-v3-batch-mutate) or the V4 operations of elementor-v4-update-node. Address its elements in the same batch as @<op_id>.<placeholder>.',
				];
			}
		);
	}

	private static function source_not_found(): string {
		return __( 'The source post was not found, or you may not edit it. A source is a page, post, Elementor template or Gutenberg pattern that you can edit, with the status publish, draft, pending, future or private. Trashed posts, revisions and attachments are not sources.', 'stonewright' );
	}

	private static function source_repair(): string {
		return __( 'Use a post_id from stonewright-section-reuse-find. A private, pending or scheduled post is extracted as it is: never change the status of a post to extract from it.', 'stonewright' );
	}

	private static function locator_repair(): string {
		return __( 'A locator is {kind:"element", id} with the id of a top-level element or of a container nested at any depth (a V3 container or section, or a V4 div block or flexbox, with its children), {kind:"block", path} or {anchor} for Gutenberg, or {kind:"pattern"}.', 'stonewright' );
	}

	/**
	 * The error for a locator that matched no section, saying what is wrong and what a locator may be.
	 *
	 * Only the message reaches a client that flattens errors, so it carries the whole repair.
	 *
	 * @param array<string, string> $problem From {@see SectionSource::resolve()}.
	 * @param array<string, mixed>  $locator
	 */
	private function locator_error( array $problem, array $locator ): \WP_Error {
		$reason = (string) ( $problem['reason'] ?? '' );
		$data   = [ 'reason' => $reason, 'locator' => $locator ];
		if ( 'no_document' === $problem['code'] ) {
			return $this->error( 'no_elementor_document', self::no_document_text( $reason ), $data + [ 'status' => 422 ] );
		}
		if ( 'not_copyable' === $problem['code'] ) {
			return $this->error(
				'section_not_copyable',
				sprintf( self::not_copyable_text( $reason ), (string) ( $problem['element_id'] ?? '' ), (string) ( $problem['element_type'] ?? '' ) ),
				$data + [ 'status' => 422, 'repair' => self::locator_repair() ]
			);
		}
		if ( 'template_type' === $reason ) {
			return $this->error( 'section_not_found', __( 'This Elementor template is not a section or container template, so it holds no section to copy. Only saved templates of the type section or container are sources; pick another source.', 'stonewright' ), $data + [ 'status' => 404 ] );
		}

		return $this->error(
			'section_not_found',
			__( 'No section of that source matches the locator.', 'stonewright' ) . ' ' . self::locator_repair() . ' ' . __( 'Candidates of stonewright-section-reuse-find list their nested containers under inner; other ids come from the element tree of the page.', 'stonewright' ),
			$data + [ 'status' => 404, 'repair' => self::locator_repair() ]
		);
	}

	private static function no_document_text( string $reason ): string {
		$lead = __( 'The source has no valid Elementor document', 'stonewright' );

		return match ( $reason ) {
			'elementor_mode_missing'       => $lead . __( ': it holds Elementor data but is not marked as built with Elementor, so it is not read as an Elementor page. Open it in Elementor and save it again, or pick another source. Do not change the status of a post to work around this.', 'stonewright' ),
			'document_empty_or_unreadable' => $lead . __( ': its Elementor data is empty or cannot be read. Pick another source, or repair the page in Elementor first.', 'stonewright' ),
			default                        => $lead . __( ': the post is not built with Elementor. Use a Gutenberg locator ({kind:"block", path or anchor}, or {kind:"pattern"}), or pick a source built with Elementor.', 'stonewright' ),
		};
	}

	/** @return string A format string taking the element id and its type. */
	private static function not_copyable_text( string $reason ): string {
		$tail = ' ' . self::locator_repair();

		return match ( $reason ) {
			'widget'         => __( 'Element %1$s (%2$s) is a widget, which is not copied on its own: a section is a container with its children.', 'stonewright' ) . $tail,
			'column'         => __( 'Element %1$s (%2$s) is a legacy column, which is not copied on its own: a section is a container or section with its children.', 'stonewright' ) . $tail,
			'mixed_builders' => __( 'Element %1$s (%2$s) holds both V3 and V4 elements, and reuse never converts between them. Copy a container that holds only one kind.', 'stonewright' ) . $tail,
			default          => __( 'Element %1$s (%2$s) is not a container, so it is not a section.', 'stonewright' ) . $tail,
		};
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
