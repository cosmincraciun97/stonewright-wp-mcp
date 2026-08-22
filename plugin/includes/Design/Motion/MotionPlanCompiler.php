<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Design\Motion;

use Stonewright\WpMcp\DesignSpec\Validator as SpecValidator;

/**
 * Deterministic motion plan compiler.
 *
 * Takes a validated DesignSpec plus renderer context and produces
 * renderer-specific operations without writing anything. Byte-for-byte
 * deterministic for identical input. The plan hash binds the spec, the
 * preset registry, asset checksums, the capability digest fingerprint, and
 * the design-direction reference — a stale plan is refused at apply time.
 *
 * Result semantics (explicit, no hidden state):
 * - every resolver returns either an OPERATION (`blocked => false`) or a
 *   BLOCKED result (`blocked => true` + structured reason);
 * - auto lowering may fall back from native controls to bundled presets
 *   (Stonewright product code) — never to custom code;
 * - a blocked result whose reason carries `propose_css_fallback` is surfaced
 *   as a warning proposing the bundled path for SEPARATE approval; no
 *   operation is created;
 * - per-device motion requests are refused wherever the renderer cannot
 *   represent them faithfully — scope is never silently widened;
 * - loops are refused until a renderer binds a persistent control.
 */
final class MotionPlanCompiler {

	private const RENDERERS      = [ 'gutenberg-fse', 'elementor-v3', 'elementor-v4' ];
	private const ALL_DEVICES    = [ 'desktop', 'mobile', 'tablet' ];

	/**
	 * @param array<string, mixed> $spec    Raw DesignSpec payload.
	 * @param array<string, mixed> $context renderer, capability_digest?, direction?
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function compile( array $spec, array $context ) {
		// Gate one: the semantic spec must be valid before any lowering.
		$validated = SpecValidator::validate( $spec );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}

		$renderer = (string) ( $context['renderer'] ?? 'gutenberg-fse' );
		if ( ! in_array( $renderer, self::RENDERERS, true ) ) {
			return new \WP_Error(
				'stonewright_motion_renderer_unknown',
				'Unknown motion renderer.',
				[ 'status' => 400, 'renderer' => $renderer ]
			);
		}

		$digest    = is_array( $context['capability_digest'] ?? null ) ? $context['capability_digest'] : null;
		$direction = is_array( $context['direction'] ?? null ) ? $context['direction'] : null;
		$entrance  = self::entrance_policy( $direction );

		$items = self::collect_items( $validated );
		if ( [] === $items ) {
			return self::empty_plan( $renderer, $validated, $digest, $direction, 'Spec declares no motion.' );
		}

		$target_map = self::target_map( $validated );
		if ( is_wp_error( $target_map ) ) {
			return $target_map;
		}

		$operations  = [];
		$unsupported = [];
		$warnings    = [];

		foreach ( $items as $entry ) {
			$item     = $entry['item'];
			$id       = (string) ( $item['id'] ?? '' );

			if ( 'blocked' === $entrance ) {
				$unsupported[] = [ 'id' => $id, 'reason' => 'motion_blocked_by_direction' ];
				continue;
			}
			if ( 'hero_only' === $entrance && ! self::in_hero_scope( $validated, $entry ) ) {
				$unsupported[] = [ 'id' => $id, 'reason' => 'motion_direction_hero_only' ];
				continue;
			}

			$resolved = self::resolve_item( $item, $renderer, $digest );

			if ( ! empty( $resolved['blocked'] ) ) {
				/** @var array{reason: array{code:string, message:string, propose_css_fallback:bool}} $resolved */
				$reason        = $resolved['reason'];
				$unsupported[] = [ 'id' => $id, 'reason' => $reason['code'] ];
				if ( $reason['propose_css_fallback'] ) {
					// Proposed alternative — requires the caller to resubmit
					// with engine=css after explicit approval. No operation.
					$warnings[] = [
						'id'                   => $id,
						'code'                 => $reason['code'],
						'message'              => $reason['message'],
						'proposed_alternative' => 'bundled-css-explicit-approval',
					];
				} elseif ( '' !== $reason['message'] ) {
					$warnings[] = [
						'id'                   => $id,
						'code'                 => $reason['code'],
						'message'              => $reason['message'],
						'proposed_alternative' => null,
					];
				}
				continue;
			}

			unset( $resolved['blocked'] );
			if ( 'add-classes' === ( $resolved['op'] ?? '' ) ) {
				foreach ( $target_map as $target ) {
					if ( (string) $target['id'] === (string) ( $resolved['target_id'] ?? '' ) ) {
						$resolved['classes'][] = (string) $target['marker'];
						$resolved['classes']   = array_values( array_unique( array_map( 'strval', (array) $resolved['classes'] ) ) );
						break;
					}
				}
			}
			$operations[] = $resolved;
		}

		usort(
			$operations,
			static fn( array $a, array $b ): int => [ (string) $a['target_id'], (string) $a['op'] ] <=> [ (string) $b['target_id'], (string) $b['op'] ]
		);

		$bindings = [
			'spec_hash'              => self::canonical_hash( $validated ),
			'registry_fingerprint'   => MotionPresetRegistry::fingerprint(),
			'asset_checksums'        => [
				'css' => MotionPresetRegistry::manifest()['assets']['css']['sha256'] ?? '',
				'js'  => MotionPresetRegistry::manifest()['assets']['js']['sha256'] ?? '',
			],
			'capability_fingerprint' => is_string( $digest['schema_fingerprint'] ?? null ) ? $digest['schema_fingerprint'] : '',
			'direction'              => self::direction_ref( $direction ),
			'renderer'               => $renderer,
			'target_map'             => $target_map,
		];

		return [
			'ok'          => true,
			'read_only'   => true,
			'mode'        => 'plan',
			'renderer'    => $renderer,
			'plan_hash'   => self::plan_hash( $bindings, $operations ),
			'bindings'    => $bindings,
			'operations'  => $operations,
			'unsupported' => $unsupported,
			'warnings'    => $warnings,
			'summary'     => [
				'motion_items' => count( $items ),
				'operations'   => count( $operations ),
				'unsupported'  => count( $unsupported ),
			],
		];
	}

	/**
	 * Item resolution.
	 */

	/**
	 * @param array<string, mixed> $item
	 * @return array<string, mixed> Operation (blocked=false) or blocked result.
	 */
	private static function resolve_item( array $item, string $renderer, ?array $digest ): array {
		$trigger  = (string) ( $item['trigger'] ?? '' );
		$playback = (string) ( $item['playback'] ?? 'once' );
		$engine   = (string) ( $item['engine'] ?? 'auto' );

		// Playback lowering happens before any dry-run on every renderer.
		if ( 'loop' === $playback ) {
			return self::blocked( $item, 'loop_control_binding_unavailable', 'No bundled renderer binds a persistent keyboard/touch pause control yet.', false );
		}
		if ( 'every-enter' === $playback ) {
			return self::blocked( $item, 'every_enter_requires_runtime_retrigger', 'Re-triggering on every entry is not supported by the current core runtime.', false );
		}
		if ( 'viewport-progress' === $trigger ) {
			return self::resolve_viewport_progress( $item, $renderer );
		}
		if ( in_array( $trigger, [ 'press', 'state-change' ], true ) ) {
			return self::blocked( $item, 'trigger_lowering_unsupported', "Trigger {$trigger} has no safe lowering on this renderer version.", false );
		}

		// Per-device faithfulness (DesignSpec contract): refuse rather than
		// widen scope. Only V3 native controls can prove per-device values,
		// and only at dry-run against live schema evidence.
		if ( ! self::devices_are_full( $item ) ) {
			if ( 'elementor-v3' === $renderer && in_array( $engine, [ 'auto', 'native' ], true ) ) {
				// Fall through: native path keeps the device requirement and
				// live schema evidence decides fidelity.
			} else {
				return self::blocked(
					$item,
					'device_variation_not_representable',
					'This renderer cannot represent per-device motion faithfully; refusing instead of widening the item to all devices.',
					false
				);
			}
		}

		return match ( $engine ) {
			'gsap'     => self::blocked( $item, 'gsap_pro_opt_in_required', 'GSAP is an opt-in Pro adapter behind a separate decision gate.', true ),
			'waapi'    => self::blocked( $item, 'waapi_not_in_core_registry', 'The core motion registry uses CSS transitions; WAAPI needs an approved imperative case.', true ),
			'provider' => self::blocked( $item, 'provider_unavailable', 'Provider adapters are opt-in; provider_id must appear in the capability digest.', true ),
			'native'   => self::resolve_native_explicit( $item, $renderer, $digest ),
			'css'      => self::css_operation( $item, 'explicit-css-engine' ),
			default    => self::resolve_auto( $item, $renderer, $digest ),
		};
	}

	/**
	 * Auto: native first, then bundled presets — never custom code.
	 *
	 * @param array<string, mixed> $item
	 */
	private static function resolve_auto( array $item, string $renderer, ?array $digest ): array {
		if ( 'elementor-v3' === $renderer ) {
			$native = self::v3_native( $item );
			if ( null !== $native ) {
				return $native;
			}
			// Device-subset requests survive only on the native path; a
			// bundled-class fallback would widen them to every device.
			if ( ! self::devices_are_full( $item ) ) {
				return self::blocked( $item, 'device_variation_requires_native_controls', 'Per-device motion needs native V3 controls; none resolved for this item.', false );
			}
			return self::css_operation( $item, 'auto-bundled-fallback' );
		}

		if ( 'elementor-v4' === $renderer ) {
			// v4_interactions returns operations and its own blocked results;
			// reduced-motion blocks propagate untouched — auto never
			// substitutes the bundled tier for them.
			if ( ! self::devices_are_full( $item ) ) {
				return self::blocked( $item, 'device_variation_not_representable', 'V4 cannot represent per-device motion; refusing instead of widening scope.', false );
			}
			return self::v4_interactions( $item, $digest );
		}

		return self::css_operation( $item, 'auto-css' );
	}

	/**
	 * Explicit engine=native never downgrades silently.
	 *
	 * @param array<string, mixed> $item
	 */
	private static function resolve_native_explicit( array $item, string $renderer, ?array $digest ): array {
		$native = 'elementor-v3' === $renderer
			? self::v3_native( $item )
			: self::v4_interactions( $item, $digest );

		if ( null !== $native && empty( $native['blocked'] ) ) {
			return $native;
		}
		if ( null !== $native && ! empty( $native['blocked'] ) ) {
			// Native-specific refusals keep their own precise reasons.
			return $native;
		}
		return self::blocked(
			$item,
			'native_lowering_unsupported',
			'No native motion control resolves for this item on this renderer; approve the bundled CSS fallback explicitly if intended.',
			true
		);
	}

	/**
	 * Elementor V3 native entrance lowering. Control keys and values resolve
	 * from live widget schema at dry-run/apply time — the compiler emits an
	 * evidence requirement, never a hardcoded setting key.
	 *
	 * @param array<string, mixed> $item
	 */
	private static function v3_native( ?array $item ): ?array {
		if ( null === $item ) {
			return null;
		}
		$trigger = (string) ( $item['trigger'] ?? '' );
		if ( ! in_array( $trigger, [ 'load', 'viewport-enter' ], true ) ) {
			// Native hover styling goes through class states; hover motion
			// effects are Pro territory reached via viewport-progress only.
			return null;
		}

		return self::op(
			[
				'op'                       => 'settings-evidence-patch',
				'target_id'                => (string) ( $item['target_id'] ?? '' ),
				'capability'               => 'entrance_animations',
				'semantic_effect'          => (string) ( $item['effect'] ?? '' ),
				'requires_schema_evidence' => true,
				'pro_required'             => false,
			]
		);
	}

	/**
	 * Elementor V4 interactions lowering with all-devices and reduced-motion
	 * truth enforcement. Returns an operation or a blocked result.
	 *
	 * @param array<string, mixed> $item
	 */
	private static function v4_interactions( array $item, ?array $digest ): array {
		$v4 = is_array( $digest['renderers']['elementor-v4'] ?? null ) ? $digest['renderers']['elementor-v4'] : [];
		if ( empty( $v4['write_adapter_ready'] ) ) {
			return self::blocked(
				$item,
				'v4_write_adapter_unavailable',
				'Elementor V4 motion writes require the official document mutator, interactions applier, plain-value resolver, and a matching live schema digest.',
				false
			);
		}

		// Reduced-motion truth: native interactions cannot prove equivalent
		// behavior yet, so NONESSENTIAL motion refuses native. The bundled
		// CSS replacement is proposed for separate approval — auto never
		// substitutes it silently.
		if ( 'preserve-essential' !== ( $item['reduced_motion'] ?? '' ) ) {
			return self::blocked(
				$item,
				'v4_reduced_motion_unproven',
				'Native V4 interactions cannot prove prefers-reduced-motion equivalence; approve the bundled CSS fallback explicitly for nonessential motion.',
				true
			);
		}

		$map = [
			'load'           => 'load',
			'viewport-enter' => 'scrollIn',
			'hover'          => 'hover',
		];
		$trigger = (string) ( $item['trigger'] ?? '' );
		if ( ! isset( $map[ $trigger ] ) ) {
			return self::blocked( $item, 'trigger_lowering_unsupported', "Trigger {$trigger} has no native V4 interaction equivalent.", false );
		}
		$live_triggers = array_values( array_map( 'strval', (array) ( $v4['interaction_triggers'] ?? [] ) ) );
		if ( ! in_array( $map[ $trigger ], $live_triggers, true ) ) {
			return self::blocked( $item, 'trigger_missing_from_live_v4_schema', "Trigger {$map[$trigger]} is not exposed by the live V4 interactions schema.", false );
		}

		return self::op(
			[
				'op'               => 'interactions-replace',
				'target_id'        => (string) ( $item['target_id'] ?? '' ),
				'interaction'      => [
					'trigger'         => $map[ $trigger ],
					'semantic_effect' => (string) ( $item['effect'] ?? '' ),
					'duration_ms'     => self::duration_ms( $item ),
					'delay_ms'        => (int) ( $item['delay_ms'] ?? 0 ),
				],
				'all_devices_only' => true,
			]
		);
	}

	/**
	 * Viewport-progress lowering: progressive enhancement only on Gutenberg,
	 * Pro-gated evidence patch on V3, unavailable on V4.
	 *
	 * @param array<string, mixed> $item
	 */
	private static function resolve_viewport_progress( array $item, string $renderer ): array {
		if ( 'gutenberg-fse' === $renderer ) {
			return self::blocked(
				$item,
				'viewport_progress_enhancement_only',
				'CSS scroll-driven timelines ship only inside an approved @supports progressive-enhancement preset; no hidden JS fallback exists.',
				false
			);
		}
		if ( 'elementor-v3' === $renderer ) {
			return self::op(
				[
					'op'                       => 'settings-evidence-patch',
					'target_id'                => (string) ( $item['target_id'] ?? '' ),
					'capability'               => 'motion_effects',
					'semantic_effect'          => (string) ( $item['effect'] ?? '' ),
					'requires_schema_evidence' => true,
					'pro_required'             => true,
				]
			);
		}
		return self::blocked( $item, 'viewport_progress_unsupported_v4', 'While Scrolling interactions are not exposed by the current V4 schema digest.', false );
	}

	/**
	 * Bundled preset operation: original product CSS classes applied through
	 * the renderer's class surface — gated by the same write pipeline, never
	 * treated as user-supplied custom code.
	 *
	 * @param array<string, mixed> $item
	 */
	private static function css_operation( array $item, string $tier ): array {
		$preset  = MotionPresetRegistry::get( (string) ( $item['effect'] ?? '' ) );
		$trigger = (string) ( $item['trigger'] ?? '' );
		if ( ! is_array( $preset ) || ! in_array( $trigger, array_map( 'strval', (array) ( $preset['triggers'] ?? [] ) ), true ) ) {
			return self::blocked(
				$item,
				'preset_trigger_incompatible',
				sprintf( 'Bundled preset "%s" does not support trigger "%s".', (string) ( $item['effect'] ?? '' ), $trigger ),
				false
			);
		}

		$duration = self::duration_ms( $item );
		$delay    = (int) ( $item['delay_ms'] ?? 0 );
		$classes  = [
			(string) ( $preset['class'] ?? '' ),
			'stw-motion-duration--' . $duration,
			'stw-motion-trigger--' . $trigger,
		];
		if ( $delay > 0 ) {
			$classes[] = 'stw-motion-delay--' . $delay;
		}
		if ( isset( $item['stagger']['interval_ms'] ) ) {
			$classes[] = 'stw-motion-stagger-interval--' . (int) $item['stagger']['interval_ms'];
		}
		$runtime = ! empty( $preset['requires_runtime'] )
			|| $delay > 0
			|| ! in_array( $duration, [ 0, 160, 280, 480 ], true );

		return self::op(
			[
				'op'         => 'add-classes',
				'target_id'  => (string) ( $item['target_id'] ?? '' ),
				'classes'    => array_values( array_unique( $classes ) ),
				'runtime'    => $runtime,
				'trigger'    => $trigger,
				'playback'   => (string) ( $item['playback'] ?? 'once' ),
				'tier'       => $tier,
			]
		);
	}

	/**
	 * Result constructors.
	 */

	/**
	 * @param array<string, mixed> $operation
	 * @return array<string, mixed>
	 */
	private static function op( array $operation ): array {
		$operation['blocked'] = false;
		return $operation;
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function blocked( array $item, string $code, string $message, bool $propose_css ): array {
		return [
			'blocked'   => true,
			'target_id' => (string) ( $item['target_id'] ?? '' ),
			'reason'    => [
				'code'                 => $code,
				'message'              => $message,
				'propose_css_fallback' => $propose_css,
			],
		];
	}

	/**
	 * Spec helpers.
	 */

	/**
	 * Collects declared motion items from a validated spec.
	 *
	 * @return list<array{si:int, bi:int|null, owner:string, scope:string, item:array<string, mixed>, path:list<int|string>}>
	 */
	private static function collect_items( array $spec ): array {
		$out = [];
		foreach ( (array) ( $spec['sections'] ?? [] ) as $si => $section ) {
			if ( ! is_array( $section ) ) {
				continue;
			}
			foreach ( (array) ( $section['motion'] ?? [] ) as $mi => $item ) {
				if ( is_array( $item ) ) {
					$out[] = [
						'si' => (int) $si,
						'bi' => null,
						'owner' => (string) ( $section['id'] ?? '' ),
						'scope' => 'section',
						'item' => $item,
						'path' => [ 'sections', $si, 'motion', $mi ],
					];
				}
			}
			foreach ( (array) ( $section['blocks'] ?? [] ) as $bi => $block ) {
				if ( ! is_array( $block ) ) {
					continue;
				}
				foreach ( (array) ( $block['motion'] ?? [] ) as $mi => $item ) {
					if ( is_array( $item ) ) {
						$out[] = [
							'si' => (int) $si,
							'bi' => (int) $bi,
							'owner' => (string) ( $block['id'] ?? '' ),
							'scope' => 'block',
							'item' => $item,
							'path' => [ 'sections', $si, 'blocks', $bi, 'motion', $mi ],
						];
					}
				}
			}
		}
		return $out;
	}

	/**
	 * Builds the sanitized marker map and refuses sanitization collisions.
	 *
	 * @return list<array{id:string, kind:string, owner_section:int, marker:string}>|\WP_Error
	 */
	private static function target_map( array $spec ) {
		$targets   = [];
		$by_marker = [];

		foreach ( (array) ( $spec['sections'] ?? [] ) as $si => $section ) {
			if ( ! is_array( $section ) ) {
				continue;
			}
			$targets[] = [ 'id' => (string) ( $section['id'] ?? '' ), 'kind' => 'section', 'owner_section' => (int) $si ];
			foreach ( (array) ( $section['blocks'] ?? [] ) as $block ) {
				if ( is_array( $block ) ) {
					$targets[] = [ 'id' => (string) ( $block['id'] ?? '' ), 'kind' => 'block', 'owner_section' => (int) $si ];
				}
			}
		}

		$map = [];
		foreach ( $targets as $target ) {
			$id = $target['id'];
			if ( '' === $id ) {
				continue;
			}
			$marker = 'stw-motion-target--' . self::sanitize_marker( $id );
			if ( isset( $by_marker[ $marker ] ) && $by_marker[ $marker ] !== $id ) {
				return new \WP_Error(
					'stonewright_motion_marker_collision',
					sprintf( 'Motion targets "%1$s" and "%2$s" sanitize to the same marker "%3$s"; rename one before rendering.', $by_marker[ $marker ], $id, $marker ),
					[ 'status' => 422 ]
				);
			}
			$by_marker[ $marker ] = $id;
			$map[]                = [
				'id'            => $id,
				'kind'          => $target['kind'],
				'owner_section' => $target['owner_section'],
				'marker'        => $marker,
			];
		}
		return $map;
	}

	private static function sanitize_marker( string $id ): string {
		$sanitized = strtolower( preg_replace( '/[^a-z0-9_-]/i', '-', $id ) ?? '' );
		$sanitized = trim( preg_replace( '/-{2,}/', '-', $sanitized ) ?? '', '-' );
		return '' !== $sanitized ? substr( $sanitized, 0, 64 ) : 'unnamed';
	}

	/**
	 * Whether the motion item's owning section carries hero role (used by the
	 * hero_only direction policy).
	 *
	 * @param array<string, mixed> $spec
	 * @param array{si:int} $entry
	 */
	private static function in_hero_scope( array $spec, array $entry ): bool {
		$sections = array_values( (array) ( $spec['sections'] ?? [] ) );
		$section  = $sections[ $entry['si'] ] ?? null;
		if ( ! is_array( $section ) ) {
			return false;
		}
		return 'hero' === ( $section['role'] ?? '' );
	}

	private static function duration_ms( array $item ): int {
		$duration = $item['duration'] ?? 'standard';
		if ( is_int( $duration ) ) {
			return $duration;
		}
		return match ( $duration ) {
			'instant' => 0,
			'fast'    => 160,
			'slow'    => 480,
			default   => 280,
		};
	}

	/**
	 * @param array<string, mixed> $item
	 */
	private static function devices_are_full( array $item ): bool {
		$devices = $item['devices'] ?? null;
		if ( null === $devices ) {
			return true;
		}
		if ( ! is_array( $devices ) ) {
			return false;
		}
		$sorted = array_values( array_map( 'strval', $devices ) );
		sort( $sorted );
		return self::ALL_DEVICES === $sorted;
	}

	/**
	 * @param array<string, mixed>|null $direction
	 */
	private static function entrance_policy( ?array $direction ): string {
		if ( null === $direction ) {
			return 'allowed';
		}
		$value = $direction['entrance_animation'] ?? null;
		return in_array( $value, [ 'blocked', 'hero_only', 'allowed' ], true ) ? (string) $value : 'allowed';
	}

	/**
	 * @param array<string, mixed>|null $direction
	 * @return array<string, string>|null
	 */
	private static function direction_ref( ?array $direction ): ?array {
		if ( null === $direction ) {
			return null;
		}
		return [
			'id'      => (string) ( $direction['id'] ?? '' ),
			'version' => (string) ( $direction['version'] ?? '' ),
			'hash'    => (string) ( $direction['hash'] ?? '' ),
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function empty_plan( string $renderer, array $spec, ?array $digest, ?array $direction, string $note ): array {
		$bindings = [
			'spec_hash'              => self::canonical_hash( $spec ),
			'registry_fingerprint'   => MotionPresetRegistry::fingerprint(),
			'asset_checksums'        => [
				'css' => MotionPresetRegistry::manifest()['assets']['css']['sha256'] ?? '',
				'js'  => MotionPresetRegistry::manifest()['assets']['js']['sha256'] ?? '',
			],
			'capability_fingerprint' => is_string( $digest['schema_fingerprint'] ?? null ) ? $digest['schema_fingerprint'] : '',
			'direction'              => self::direction_ref( $direction ),
			'renderer'               => $renderer,
			'target_map'             => [],
		];

		return [
			'ok'          => true,
			'read_only'   => true,
			'mode'        => 'plan',
			'renderer'    => $renderer,
			'plan_hash'   => self::plan_hash( $bindings, [] ),
			'bindings'    => $bindings,
			'operations'  => [],
			'unsupported' => [],
			'warnings'    => [ [ 'code' => 'no_motion', 'message' => $note ] ],
			'summary'     => [ 'motion_items' => 0, 'operations' => 0, 'unsupported' => 0 ],
		];
	}

	/**
	 * @param list<array<string, mixed>> $operations
	 */
	public static function plan_hash( array $bindings, array $operations ): string {
		return hash_hmac(
			'sha256',
			wp_json_encode( [ 'bindings' => $bindings, 'operations' => $operations ] ) ?: '',
			wp_salt( 'stonewright_motion_plan' )
		);
	}

	private static function canonical_hash( array $payload ): string {
		return hash( 'sha256', wp_json_encode( $payload ) ?: '' );
	}
}
