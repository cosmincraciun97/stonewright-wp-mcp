<?php
/**
 * Finds sections the site already has that could be reused on a new page.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\SectionReuse;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\SectionReuse\Builder;
use Stonewright\WpMcp\SectionReuse\ReferenceCatalog;
use Stonewright\WpMcp\SectionReuse\ReuseWarnings;
use Stonewright\WpMcp\SectionReuse\RoleGuesser;
use Stonewright\WpMcp\SectionReuse\RoleOutline;
use Stonewright\WpMcp\SectionReuse\SectionReuseSetting;
use Stonewright\WpMcp\SectionReuse\SectionSource;
use Stonewright\WpMcp\SectionReuse\Similarity;
use Stonewright\WpMcp\SectionReuse\SourceScanner;
use Stonewright\WpMcp\Security\Permissions;

/**
 * Candidates for reusing an existing section, for the roles a new page needs. Read-only: it changes nothing.
 *
 * Candidates come only from posts the current user may read and edit. Each carries its source, where the
 * section sits, a role guess, a layout summary, a layout-only similarity score, a short outline and reuse
 * warnings. While the site setting is off, the answer is only the instruction not to offer reuse.
 *
 * @stonewright-status stable
 */
final class SectionReuseFind extends AbilityKernel {

	private const MAX_ROLES        = 12;
	private const DEFAULT_LIMIT    = 3;
	private const MAX_LIMIT        = 10;
	private const MAX_TITLE_CHARS  = 80;
	/** Similarity at or above which a section of another role still counts when a layout was asked for. */
	private const LAYOUT_MATCH     = 0.9;

	public function name(): string {
		return 'stonewright/section-reuse-find';
	}

	public function label(): string {
		return __( 'Find reusable sections', 'stonewright' );
	}

	public function description(): string {
		return __( 'Lists sections already on this site that could be reused for the sections a new page needs, from pages, posts, Elementor saved templates and Gutenberg patterns the current user can read and edit. Call it before asking the user anything about reuse. Candidates carry the source, the section locator, a role guess, a layout summary, a layout-only similarity score, a short outline and reuse warnings. When the site setting is off the answer is only enabled:false with an instruction; build normally and do not mention reuse.', 'stonewright' );
	}

	public function category(): string {
		return 'site';
	}

	public function input_schema(): array {
		$roles = RoleGuesser::ROLES;

		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'builder'        => [
					'type'        => 'string',
					'enum'        => Builder::all(),
					'description' => 'The builder of the page being built. Reuse never converts between builders.',
				],
				'sections'       => [
					'type'        => 'array',
					'maxItems'    => self::MAX_ROLES,
					'description' => 'The sections the page needs, each with a role and optionally the layout wanted.',
					'items'       => [
						'type'                 => 'object',
						'additionalProperties' => false,
						'properties'           => [
							'role'    => [ 'type' => 'string', 'enum' => $roles ],
							'outline' => [ 'type' => 'string', 'maxLength' => 160 ],
							'layout'  => [
								'type'                 => 'object',
								'additionalProperties' => false,
								'properties'           => [
									'columns' => [ 'type' => 'integer', 'minimum' => 0, 'maximum' => 24 ],
									'items'   => [ 'type' => 'integer', 'minimum' => 0, 'maximum' => 48 ],
									'depth'   => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 12 ],
									'types'   => [ 'type' => 'array', 'maxItems' => 12, 'items' => [ 'type' => 'string', 'maxLength' => 64 ] ],
								],
							],
						],
						'required'             => [ 'role' ],
					],
				],
				'roles'          => [
					'type'     => 'array',
					'maxItems' => self::MAX_ROLES,
					'items'    => [ 'type' => 'string', 'enum' => $roles ],
				],
				'outline'        => [
					'type'        => 'string',
					'maxLength'   => 400,
					'description' => 'A short outline of the page, for example "hero, 3 feature cards, testimonials, contact form". Roles are read from it.',
				],
				'target_post_id' => [ 'type' => 'integer', 'minimum' => 1, 'description' => 'The page being built; its own sections are never offered.' ],
				'limit_per_role' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_LIMIT, 'default' => self::DEFAULT_LIMIT ],
			],
			'required'             => [ 'builder' ],
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'enabled'        => [ 'type' => 'boolean' ],
				'instruction'    => [ 'type' => 'string' ],
				'builder'        => [ 'type' => 'string' ],
				'target_post_id' => [ 'type' => 'integer' ],
				'roles'          => [ 'type' => 'array', 'items' => [ 'type' => 'object' ] ],
				'scan'           => [ 'type' => 'object' ],
				'next_step'      => [ 'type' => 'string' ],
			],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::edit_posts();
	}

	public function execute( array $args ): array|\WP_Error {
		return $this->audit_read(
			$args,
			function ( array $args ) {
				// The live option, not the tool list: a client may keep a stale list.
				if ( ! SectionReuseSetting::is_enabled() ) {
					return [ 'enabled' => false, 'instruction' => SectionReuseSetting::OFF_INSTRUCTION ];
				}
				$builder = (string) ( $args['builder'] ?? '' );
				if ( ! Builder::is_valid( $builder ) ) {
					return $this->error( 'invalid_builder', __( 'builder must be gutenberg, elementor-v3 or elementor-v4.', 'stonewright' ), [ 'status' => 400 ] );
				}
				$wanted = $this->wanted_sections( $args );
				if ( [] === $wanted ) {
					return $this->error( 'roles_required', __( 'Name at least one section role in sections, roles or outline.', 'stonewright' ), [ 'status' => 400 ] );
				}
				$limit  = max( 1, min( self::MAX_LIMIT, (int) ( $args['limit_per_role'] ?? self::DEFAULT_LIMIT ) ) );
				$target = max( 0, (int) ( $args['target_post_id'] ?? 0 ) );
				$found  = SourceScanner::scan( $builder, $target );

				$roles = [];
				foreach ( $wanted as $want ) {
					$roles[] = [
						'role'       => $want['role'],
						'candidates' => $this->candidates( $found['sections'], $want, $limit ),
					];
				}
				$total = array_sum( array_map( static fn( array $row ): int => count( $row['candidates'] ), $roles ) );

				return array_filter(
					[
						'enabled'        => true,
						'builder'        => $builder,
						'target_post_id' => $target > 0 ? $target : null,
						'roles'          => $roles,
						'scan'           => $found['scan'],
						'next_step'      => $total > 0
							? 'Ask the user one short question with at most three candidates per role, then call stonewright-section-reuse-extract for the ones they pick.'
							: 'No candidates. Build the page normally and do not mention reuse.',
					],
					static fn( mixed $value ): bool => null !== $value
				);
			}
		);
	}

	/**
	 * The sections asked for: explicit sections first, then plain roles, then roles named in the outline.
	 *
	 * @param array<string, mixed> $args
	 * @return list<array{role:string,layout:array<string,mixed>}>
	 */
	private function wanted_sections( array $args ): array {
		$wanted = [];
		$add    = static function ( string $role, array $layout ) use ( &$wanted ): void {
			if ( in_array( $role, RoleGuesser::ROLES, true ) && count( $wanted ) < self::MAX_ROLES && ! isset( $wanted[ $role ] ) ) {
				$wanted[ $role ] = [ 'role' => $role, 'layout' => $layout ];
			}
		};
		foreach ( is_array( $args['sections'] ?? null ) ? $args['sections'] : [] as $section ) {
			if ( is_array( $section ) && is_string( $section['role'] ?? null ) ) {
				$add( $section['role'], is_array( $section['layout'] ?? null ) ? $section['layout'] : [] );
			}
		}
		foreach ( is_array( $args['roles'] ?? null ) ? $args['roles'] : [] as $role ) {
			if ( is_string( $role ) ) {
				$add( $role, [] );
			}
		}
		if ( is_string( $args['outline'] ?? null ) ) {
			foreach ( RoleOutline::parse( $args['outline'] ) as $entry ) {
				$add( $entry['role'], $entry['layout'] ?? [] );
			}
		}

		return array_values( $wanted );
	}

	/**
	 * @param list<array<string, mixed>>                $sections Scanned sections of the requested builder.
	 * @param array{role:string,layout:array<string,mixed>} $want
	 * @return list<array<string, mixed>>
	 */
	private function candidates( array $sections, array $want, int $limit ): array {
		$target = [] !== $want['layout'] ? $want['layout'] : Similarity::profile( $want['role'] );
		$ranked = [];
		foreach ( $sections as $section ) {
			$score      = Similarity::score( $section['layout'], $target );
			$role_match = $section['role'] === $want['role'];
			if ( ! $role_match && ! ( [] !== $want['layout'] && $score >= self::LAYOUT_MATCH ) ) {
				continue;
			}
			$ranked[] = [ 'score' => $score, 'role_match' => $role_match, 'section' => $section ];
		}
		usort(
			$ranked,
			static function ( array $left, array $right ): int {
				$a = $left['section'];
				$b = $right['section'];
				return [ $right['score'], (int) $right['role_match'], SectionSource::modified( $b['post'] ), (int) $a['post']->ID, (int) $a['index'] ]
					<=> [ $left['score'], (int) $left['role_match'], SectionSource::modified( $a['post'] ), (int) $b['post']->ID, (int) $b['index'] ];
			}
		);

		$out = [];
		foreach ( array_slice( $ranked, 0, $limit ) as $row ) {
			$out[] = $this->candidate( $row['section'], $row['score'] );
		}

		return $out;
	}

	/**
	 * @param array<string, mixed> $section
	 * @return array<string, mixed>
	 */
	private function candidate( array $section, float $score ): array {
		$post       = $section['post'];
		$references = ReferenceCatalog::annotate( $section['references'] );
		$locator    = $section['locator'];
		$source     = [
			'post_id'      => (int) $post->ID,
			'type'         => (string) $post->post_type,
			'kind'         => SectionSource::kind( $post ),
			'title'        => mb_substr( trim( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( (string) $post->post_title ) ) ), 0, self::MAX_TITLE_CHARS ),
			'status'       => (string) $post->post_status,
			'edit_url'     => (string) get_edit_post_link( (int) $post->ID, 'raw' ),
			'modified_gmt' => SectionSource::modified( $post ),
		];
		$layout = $section['layout'];
		unset( $layout['capped'] );

		return [
			'source'     => $source,
			'locator'    => $locator,
			'builder'    => $section['builder'],
			'role'       => $section['role'],
			'layout'     => $layout,
			'capped'     => ! empty( $section['layout']['capped'] ),
			'similarity' => $score,
			'outline'    => $section['outline'],
			'warnings'   => ReuseWarnings::from( $references ),
		];
	}
}
