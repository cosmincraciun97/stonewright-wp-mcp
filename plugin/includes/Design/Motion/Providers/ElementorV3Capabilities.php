<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Design\Motion\Providers;

/**
 * Read-only Elementor V3 motion capability descriptor.
 *
 * Entrance and exit animations are Core capabilities; Motion Effects
 * (scroll/transparency/mouse track/sticky) exist only when Elementor Pro is
 * active. Control keys are never hardcoded here — the live widget schema is
 * read again at every dry-run; this digest only reports what the loaded
 * runtime can prove.
 */
final class ElementorV3Capabilities {

	/**
	 * @param array<string, mixed>|null $elementor  Runtime snapshot or null when absent.
	 * @param string                    $mode      summary|full.
	 * @return array{renderer:array<string,mixed>, warnings:list<array<string,mixed>>, unsupported:list<array<string,mixed>>}
	 */
	public static function describe( ?array $elementor, string $mode ): array {
		if ( null === $elementor || empty( $elementor['active'] ) ) {
			return [
				'renderer'    => [
					'architecture' => 'elementor-v3',
					'available'    => false,
					'native_motion_capabilities' => [],
				],
				'warnings'    => [],
				'unsupported' => [
					self::unsupported( 'entrance_animations', 'elementor_not_active' ),
					self::unsupported( 'exit_animations', 'elementor_not_active' ),
					self::unsupported( 'hover_controls', 'elementor_not_active' ),
					self::unsupported( 'motion_effects', 'elementor_not_active' ),
				],
			];
		}

		$pro = ! empty( $elementor['pro_active'] );

		$capabilities = [
			[
				'capability'   => 'entrance_animations',
				'status'       => 'available',
				'triggers'     => [ 'load', 'viewport-enter' ],
				'pro_required' => false,
			],
			[
				'capability'   => 'exit_animations',
				'status'       => 'available',
				'triggers'     => [ 'state-change' ],
				'pro_required' => false,
			],
			[
				'capability'   => 'hover_controls',
				'status'       => 'available',
				'triggers'     => [ 'hover' ],
				'description'  => 'Widget-specific hover controls; focus-visible parity is verified separately at plan time.',
				'pro_required' => false,
			],
			[
				'capability'   => 'motion_effects',
				'status'       => $pro ? 'available' : 'unsupported',
				'triggers'     => [ 'viewport-progress', 'hover' ],
				'pro_required' => true,
				'description'  => 'Scroll, transparency, blur, rotate, scale, mouse track, 3D tilt, sticky. Presence of individual controls is re-verified from live schema at dry-run.',
			],
		];

		if ( ! $pro ) {
			$capabilities = array_map(
				static fn( array $cap ): array => 'motion_effects' === $cap['capability']
					? array_merge( $cap, [ 'reason' => 'pro_required' ] )
					: $cap,
				$capabilities
			);
		}

		$renderer = [
			'architecture'               => 'elementor-v3',
			'available'                  => true,
			'core_version'               => (string) ( $elementor['core_version'] ?? '' ),
			'pro_active'                 => $pro,
			'native_motion_capabilities' => $capabilities,
			'device_support'             => [
				'mode'    => 'schema-verified-at-dry-run',
				'devices' => [ 'desktop', 'tablet', 'mobile' ],
				'method'  => 'Per-device values only where the live control schema exposes responsive controls.',
			],
			'reduced_motion_support'     => [
				'supported' => 'unproven',
				'method'    => 'No native reduced-motion control is assumed; bundled CSS replacement requires separate user approval.',
			],
			'write_path'                 => 'stonewright/elementor-v3-batch-mutate with sparse settings evidence; never full-tree rewrite.',
		];

		$unsupported = [];
		if ( ! $pro ) {
			$unsupported[] = self::unsupported( 'motion_effects', 'pro_required' );
		}

		$warnings = [
			[
				'code'      => 'v3_entrance_scroll_conflicts',
				'message'   => 'Entrance animations are not combined automatically with scrolling/mouse effects; platform conflict guidance is respected at compile time.',
			],
		];

		if ( 'full' === $mode ) {
			$renderer['detail'] = [
				'loop_playback'        => 'Blocked by default; requires justified purpose, explicit approval, and a persistent keyboard/touch control target.',
				'mixed_v4_subtree'     => 'Refused by the V3 provider.',
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
		return [ 'renderer' => 'elementor-v3', 'capability' => $capability, 'reason' => $reason ];
	}
}
