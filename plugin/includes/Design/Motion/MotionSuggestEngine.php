<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Design\Motion;

/**
 * Deterministic motion suggestion engine.
 *
 * Produces at most three proposals for a page intent — two motion proposals
 * derived from section roles and block repetition, plus the always-valid
 * "no motion" option. Exactly one proposal is recommended. Output is pure:
 * identical input produces an identical suggestion payload.
 *
 * Purpose before effect: every proposal declares why the motion exists.
 */
final class MotionSuggestEngine {

	private const NO_MOTION_ID = 'no-motion';

	/**
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>
	 */
	public static function suggest( array $input ): array {
		$renderer   = self::renderer( $input );
		$sections   = self::sections( $input );
		$entrance   = self::entrance_policy( $input );
		$level      = self::level( $input );

		if ( 'blocked' === $entrance || 'none' === $level ) {
			$rationale = 'blocked' === $entrance
				? 'Design direction blocks entrance motion; "no motion" is the only valid plan.'
				: 'Motion preference is none; no motion is the only proposal.';
			return self::payload( $renderer, [ self::no_motion( $rationale ) ], self::NO_MOTION_ID );
		}

		$proposals = [];

		$hero_targets     = self::targets_in_hero( $sections );
		$interactive      = self::interactive_targets( $sections );
		$repeated_groups  = self::repeated_groups( $sections );
		if ( [] !== $hero_targets || [] !== $interactive ) {
			$proposals[] = self::orientation_proposal( $renderer, $entrance, $hero_targets, $interactive );
		}
		if ( [] !== $repeated_groups ) {
			$proposals[] = self::rhythm_proposal( $renderer, $repeated_groups );
		}

		// Cap at two motion proposals, then always append no-motion.
		$proposals = array_slice( $proposals, 0, 2 );
		if ( 'hero_only' === $entrance ) {
			$proposals = array_values(
				array_filter(
					$proposals,
					static fn( array $p ): bool => 'stagger-rhythm' !== ( $p['template'] ?? '' )
				)
			);
		}
		$proposals[] = self::no_motion();

		$recommended = self::pick_recommended( $proposals, $hero_targets, $interactive, $entrance );

		return self::payload( $renderer, array_values( $proposals ), $recommended );
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function orientation_proposal( string $renderer, string $entrance, array $hero_targets, array $interactive ): array {
		$items    = [];
		$purposes = [];

		foreach ( $hero_targets as $target ) {
			$items[] = [
				'target_id'      => $target['id'],
				'effect'         => 'fade-up',
				'trigger'        => 'viewport-enter',
				'purpose'        => 'orient',
				'reduced_motion' => 'replace-with-fade',
			];
			$purposes[] = 'orient';
		}
		foreach ( $interactive as $target ) {
			$is_linkish = in_array( $target['type'], [ 'link', 'paragraph' ], true );
			$effect     = $is_linkish ? 'link-underline' : 'card-lift';
			$items[]    = [
				'target_id'      => $target['id'],
				'effect'         => $effect,
				'trigger'        => 'hover',
				'purpose'        => 'feedback',
				'reduced_motion' => 'static-end-state',
			];
			$items[]    = [
				'target_id'      => $target['id'],
				'effect'         => $effect,
				'trigger'        => 'focus-visible',
				'purpose'        => 'feedback',
				'reduced_motion' => 'static-end-state',
			];
			$purposes[] = 'feedback';
		}

		return [
			'id'            => 'subtle-orientation',
			'template'      => 'subtle-orientation',
			'label'         => 'Subtle orientation + interaction feedback',
			'rationale'     => 'One entrance vocabulary for above-the-fold content plus focus-parity hover feedback on interactive elements.',
			'purposes'      => array_values( array_unique( $purposes ) ),
			'motion_items'  => $items,
			'native_path'   => self::native_path( $renderer ),
			'fallback'      => 'Bundled Stonewright CSS presets (static-first) when native controls are unavailable.',
			'device_behavior' => [
				'desktop'  => 'Full effect.',
				'tablet'   => 'Full effect.',
				'mobile'   => 'Reduced distance; entrance duration unchanged.',
			],
			'reduced_motion_behavior' => 'Nonessential motion replaced with fade/static end state via prefers-reduced-motion; information never hidden.',
			'approval_requirements'   => [],
			'estimated'               => [
				'tool_calls' => 2,
				'assets'     => self::asset_estimate( $items ),
			],
			'recommended'   => false,
			'scope_note'    => 'hero_only' === $entrance ? 'Entrances restricted to the hero section by design direction.' : '',
		];
	}

	/**
	 * @param array<int, array{id:string, count:int, type:string}> $groups
	 * @return array<string, mixed>
	 */
	private static function rhythm_proposal( string $renderer, array $groups ): array {
		$items = [];
		foreach ( $groups as $group ) {
			$items[] = [
				'target_id'      => $group['id'],
				'effect'         => 'stagger-reveal',
				'trigger'        => 'viewport-enter',
				'purpose'        => 'continuity',
				'reduced_motion' => 'replace-with-fade',
				'stagger'        => [
					'interval_ms' => 80,
					'span_ms'     => min( 2000, 80 * max( 2, $group['count'] ) ),
				],
			];
		}

		return [
			'id'            => 'stagger-rhythm',
			'template'      => 'stagger-rhythm',
			'label'         => 'Staggered reveal for repeated groups',
			'rationale'     => 'Repeated cards enter as one composed group instead of competing individual effects.',
			'purposes'      => [ 'continuity' ],
			'motion_items'  => $items,
			'native_path'   => self::native_path( $renderer ),
			'fallback'      => 'Bundled stagger orchestration over fade-up children.',
			'device_behavior' => [
				'desktop'  => 'Full sequence.',
				'tablet'   => 'Full sequence.',
				'mobile'   => 'Interval halved to shorten total span.',
			],
			'reduced_motion_behavior' => 'Children resolve directly to final static state.',
			'approval_requirements'   => [],
			'estimated'               => [
				'tool_calls' => 2,
				'assets'     => self::asset_estimate( $items ),
			],
			'recommended'   => false,
			'scope_note'    => '',
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function no_motion( string $rationale = '' ): array {
		return [
			'id'            => self::NO_MOTION_ID,
			'template'      => 'no-motion',
			'label'         => 'No motion',
			'rationale'     => '' !== $rationale ? $rationale : 'A valid choice: static pages are fast, accessible by default, and never fight Core Web Vitals.',
			'purposes'      => [],
			'motion_items'  => [],
			'native_path'   => null,
			'fallback'      => null,
			'device_behavior' => [],
			'reduced_motion_behavior' => 'Not applicable.',
			'approval_requirements'   => [],
			'estimated'     => [ 'tool_calls' => 0, 'assets' => [ 'css' => false, 'js' => false ] ],
			'recommended'   => false,
			'scope_note'    => '',
		];
	}

	/**
	 * @param list<array<string, mixed>> $proposals
	 * @param list<array<string, mixed>> $hero
	 * @param list<array<string, mixed>> $interactive
	 */
	private static function pick_recommended( array $proposals, array $hero, array $interactive, string $entrance ): string {
		if ( [] === $hero && [] === $interactive ) {
			return self::NO_MOTION_ID;
		}
		foreach ( $proposals as $proposal ) {
			if ( ( $proposal['template'] ?? '' ) === 'subtle-orientation' ) {
				return (string) $proposal['id'];
			}
		}
		foreach ( $proposals as $proposal ) {
			if ( ( $proposal['template'] ?? '' ) === 'stagger-rhythm' && 'blocked' !== $entrance ) {
				return (string) $proposal['id'];
			}
		}
		return self::NO_MOTION_ID;
	}

	/**
	 * @param list<array<string, mixed>> $items
	 * @return array<string, mixed>
	 */
	private static function asset_estimate( array $items ): array {
		$needs_runtime = false;
		foreach ( $items as $item ) {
			if ( 'viewport-enter' === ( $item['trigger'] ?? '' ) ) {
				$needs_runtime = true;
			}
		}
		return [ 'css' => true, 'js' => $needs_runtime ];
	}

	private static function native_path( string $renderer ): string {
		return match ( $renderer ) {
			'elementor-v3' => 'Elementor V3 native entrance/hover controls via sparse batch-mutate settings evidence.',
			'elementor-v4' => 'Elementor V4 native interactions patch (all-devices limitation applies).',
			default        => 'Gutenberg/FSE block classes with conditional bundled assets.',
		};
	}

	/**
	 * @param list<array<string, mixed>> $sections
	 * @return list<array<string, mixed>>
	 */
	private static function targets_in_hero( array $sections ): array {
		$out = [];
		foreach ( $sections as $section ) {
			$is_hero = 'hero' === ( $section['role'] ?? '' );
			if ( ! $is_hero && ! empty( $section['is_first'] ) ) {
				$is_hero = true;
			}
			if ( ! $is_hero ) {
				continue;
			}
			foreach ( (array) ( $section['blocks'] ?? [] ) as $block ) {
				$type = (string) ( $block['type'] ?? '' );
				if ( in_array( $type, [ 'heading', 'paragraph', 'image' ], true ) ) {
					$out[] = [ 'id' => (string) ( $block['id'] ?? '' ), 'type' => $type ];
				}
			}
		}
		return self::with_ids( $out );
	}

	/**
	 * @param list<array<string, mixed>> $sections
	 * @return list<array<string, mixed>>
	 */
	private static function interactive_targets( array $sections ): array {
		$out = [];
		foreach ( $sections as $section ) {
			foreach ( (array) ( $section['blocks'] ?? [] ) as $block ) {
				$type = (string) ( $block['type'] ?? '' );
				if ( in_array( $type, [ 'button', 'link', 'card', 'image-box' ], true ) ) {
					$out[] = [ 'id' => (string) ( $block['id'] ?? '' ), 'type' => $type ];
				}
			}
		}
		return self::with_ids( $out );
	}

	/**
	 * Groups of three or more same-type sibling blocks are stagger candidates.
	 *
	 * @param list<array<string, mixed>> $sections
	 * @return list<array{id:string, count:int, type:string}>
	 */
	private static function repeated_groups( array $sections ): array {
		$out = [];
		foreach ( $sections as $section ) {
			$counts = [];
			foreach ( (array) ( $section['blocks'] ?? [] ) as $block ) {
				$type = (string) ( $block['type'] ?? '' );
				if ( in_array( $type, [ 'card', 'image-box', 'icon-box', 'testimonial' ], true ) ) {
					$counts[ $type ] = ( $counts[ $type ] ?? 0 ) + 1;
				}
			}
			foreach ( $counts as $type => $count ) {
				if ( $count >= 3 ) {
					$out[] = [ 'id' => (string) ( $section['id'] ?? '' ), 'count' => $count, 'type' => $type ];
				}
			}
		}
		return $out;
	}

	/**
	 * @param list<array<string, mixed>> $sections
	 * @return list<array<string, mixed>>
	 */
	private static function image_targets( array $sections ): array {
		$out = [];
		foreach ( $sections as $section ) {
			foreach ( (array) ( $section['blocks'] ?? [] ) as $block ) {
				if ( 'image' === ( $block['type'] ?? '' ) ) {
					$out[] = [ 'id' => (string) ( $block['id'] ?? '' ), 'type' => 'image' ];
				}
			}
		}
		return self::with_ids( $out );
	}

	/**
	 * @param list<array<string, mixed>> $targets
	 * @return list<array<string, mixed>>
	 */
	private static function with_ids( array $targets ): array {
		return array_values(
			array_filter(
				$targets,
				static fn( array $t ): bool => '' !== $t['id']
			)
		);
	}

	/**
	 * @param list<array<string, mixed>> $proposals
	 * @return array<string, mixed>
	 */
	private static function payload( string $renderer, array $proposals, string $recommended ): array {
		$out = [];
		foreach ( $proposals as $proposal ) {
			$proposal['recommended'] = ( $proposal['id'] ?? '' ) === $recommended;
			$out[]                   = $proposal;
		}

		return [
			'ok'              => true,
			'read_only'       => true,
			'renderer'        => $renderer,
			'proposal_count'  => count( $out ),
			'recommended_id'  => $recommended,
			'no_motion_valid' => true,
			'proposals'       => $out,
		];
	}

	private static function renderer( array $input ): string {
		$renderer = (string) ( $input['renderer'] ?? 'gutenberg-fse' );
		return in_array( $renderer, [ 'gutenberg-fse', 'elementor-v3', 'elementor-v4' ], true )
			? $renderer
			: 'gutenberg-fse';
	}

	/**
	 * @param array<string, mixed> $input
	 * @return list<array<string, mixed>>
	 */
	private static function sections( array $input ): array {
		$sections = $input['sections'] ?? [];
		if ( ! is_array( $sections ) ) {
			return [];
		}
		$out = [];
		foreach ( array_values( $sections ) as $i => $section ) {
			if ( ! is_array( $section ) ) {
				continue;
			}
			$section['is_first'] = 0 === $i;
			$out[]               = $section;
		}
		return $out;
	}

	/**
	 * Design direction owns the politics: DialTranslator's motion dial maps to
	 * blocked / hero_only / allowed. Absent direction defaults to allowed so
	 * suggestions remain possible without an active direction.
	 */
	private static function entrance_policy( array $input ): string {
		$direction = $input['direction'] ?? null;
		if ( ! is_array( $direction ) ) {
			return 'allowed';
		}
		$entrance = $direction['entrance_animation'] ?? null;
		return in_array( $entrance, [ 'blocked', 'hero_only', 'allowed' ], true ) ? (string) $entrance : 'allowed';
	}

	private static function level( array $input ): string {
		$level = (string) ( $input['preferences']['level'] ?? 'subtle' );
		return in_array( $level, [ 'none', 'subtle', 'expressive' ], true ) ? $level : 'subtle';
	}
}
