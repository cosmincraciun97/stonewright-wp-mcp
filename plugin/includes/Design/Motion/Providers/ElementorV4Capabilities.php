<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Design\Motion\Providers;

/**
 * Read-only Elementor V4 (Atomic) motion capability descriptor.
 *
 * Interactions are stored separately from settings, styles, editor_settings,
 * and elements. The interactions module must be detected in the loaded
 * runtime before any trigger is claimed; otherwise every V4 motion capability
 * is unsupported — never invented from a hardcoded schema.
 */
final class ElementorV4Capabilities {

	/**
	 * @param array<string, mixed>|null $elementor  Runtime snapshot or null when absent.
	 * @param string                    $mode      summary|full.
	 * @return array{renderer:array<string,mixed>, warnings:list<array<string,mixed>>, unsupported:list<array<string,mixed>>}
	 */
	public static function describe( ?array $elementor, string $mode ): array {
		if ( null === $elementor || empty( $elementor['active'] ) ) {
			return [
				'renderer'    => [
					'architecture' => 'elementor-v4-atomic',
					'available'    => false,
					'native_motion_capabilities' => [],
				],
				'warnings'    => [],
				'unsupported' => [
					self::unsupported( 'interactions', 'elementor_not_active' ),
				],
			];
		}

		if ( empty( $elementor['atomic_module'] ) ) {
			return [
				'renderer'    => [
					'architecture' => 'elementor-v4-atomic',
					'available'    => false,
					'core_version' => (string) ( $elementor['core_version'] ?? '' ),
					'native_motion_capabilities' => [],
				],
				'warnings'    => [],
				'unsupported' => [
					self::unsupported( 'interactions', 'atomic_module_not_detected' ),
				],
			];
		}

		$interactions = ! empty( $elementor['interactions'] );
		$triggers     = array_values( array_unique( array_map( 'strval', (array) ( $elementor['interaction_triggers'] ?? [] ) ) ) );
		$write_ready  = ! empty( $elementor['v4_write_adapter_ready'] );

		$capabilities = [];
		if ( $interactions ) {
			$capabilities[] = [
				'capability'        => 'interactions',
				'status'            => 'available',
				'triggers'          => $triggers,
				'trigger_evidence'  => 'Re-verified from the live interactions schema at every dry-run; unknown triggers are refused, never approximated.',
				'store_separation'  => true,
				'write_status'      => $write_ready ? 'available' : 'unsupported',
			];
		}

		$renderer = [
			'architecture'               => 'elementor-v4-atomic',
			'available'                  => true,
			'experimental_in_stonewright'=> true,
			'core_version'               => (string) ( $elementor['core_version'] ?? '' ),
			'interactions_store_separate'=> true,
			'interaction_triggers'        => $triggers,
			'interaction_schema_fingerprint' => (string) ( $elementor['interaction_schema_fingerprint'] ?? '' ),
			'write_adapter_ready'         => $write_ready,
			'write_primitives'            => (array) ( $elementor['v4_write_primitives'] ?? [] ),
			'native_motion_capabilities' => $capabilities,
			'device_support'             => [
				'mode'    => ! empty( $elementor['breakpoint_exclusions_supported'] ) ? 'breakpoint-exclusions' : 'all-devices-only',
				'devices' => [ 'desktop', 'tablet', 'mobile' ],
				'method'  => ! empty( $elementor['breakpoint_exclusions_supported'] )
					? 'Live interaction schema exposes excluded breakpoints; exact lowering still requires the official write adapter.'
					: 'No breakpoint exclusion contract was proven; per-device motion is refused, not simulated.',
			],
			'reduced_motion_support'     => [
				'supported' => 'unproven',
				'method'    => 'No native reduced-motion control is proven by the current schema; native interactions stay unsupported for nonessential motion until runtime evidence shows equivalent behavior.',
			],
			'style_system'               => [
				'classes_and_variables' => 'Discovered separately through runtime repositories and list/read abilities at dry-run time.',
				'update_node_scope'     => 'Settings-only; top-level interactions use a dedicated patch path, never UpdateNode.',
			],
			'write_path'                 => $write_ready
				? 'Official Document_Mutator plus Interactions_Applier and Plain_Values_Resolver; never direct _elementor_data writes.'
				: 'Unavailable: one or more official mutation primitives are absent in the loaded runtime.',
		];

		$unsupported = [];
		$warnings    = [];

		if ( ! $interactions ) {
			$unsupported[] = self::unsupported( 'interactions', 'interactions_module_not_detected' );
		}
		if ( $interactions && ! $write_ready ) {
			$unsupported[] = self::unsupported( 'interactions-write', 'official_v4_write_primitives_missing' );
		}

		$warnings[] = [
			'code'      => 'v4_experimental',
			'message'   => 'Elementor V4 remains experimental in Stonewright regardless of the installed Elementor stability; production-safe stays locked until native-save fixtures, editor reopen, CSS frontend parity, rollback, and mixed V3/V4 gates are green.',
		];

		if ( 'full' === $mode ) {
			$renderer['detail'] = [
				'removals_destructive' => 'Interaction replace/clear operations list kept/added/removed in dry-run; removals require explicit approval.',
				'idempotency'          => 'Every patch carries an idempotency key plus expected tree and interactions hashes.',
			];
		}

		return [
			'renderer'    => $renderer,
			'warnings'    => $warnings,
			'unsupported' => $unsupported,
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function unsupported( string $capability, string $reason ): array {
		return [ 'renderer' => 'elementor-v4', 'capability' => $capability, 'reason' => $reason ];
	}
}
