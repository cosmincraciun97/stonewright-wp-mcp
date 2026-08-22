<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Design\Motion;

use Stonewright\WpMcp\Design\Motion\Providers\GutenbergCapabilities;
use Stonewright\WpMcp\Design\Motion\Providers\ElementorV3Capabilities;
use Stonewright\WpMcp\Design\Motion\Providers\ElementorV4Capabilities;

/**
 * Read-only motion capability resolver.
 *
 * Produces a bounded capability digest from the real loaded runtime: which
 * renderers are available, which native motion capabilities each exposes,
 * per-device support, a schema fingerprint, fallback availability, approval
 * requirements, warnings, and unsupported reasons. Missing runtime evidence
 * always produces an unsupported reason — never an invented capability.
 *
 * The digest is cached keyed by the version fingerprint it was computed from,
 * so any runtime upgrade invalidates it automatically.
 */
final class MotionCapabilityResolver {

	public const MODE_SUMMARY = 'summary';
	public const MODE_FULL    = 'full';

	private const CACHE_TRANSIENT_PREFIX = 'stonewright_motion_capability_';
	private const CACHE_TTL              = HOUR_IN_SECONDS;

	/**
	 * Synthetic runtime probes. When null, every probe reads the live
	 * WordPress/Elementor runtime. Tests inject deterministic snapshots here;
	 * injected resolvers never touch or populate the cache.
	 *
	 * @var array{wordpress_version?:string, elementor?:array<string,mixed>|null}|null
	 */
	private ?array $probes;

	/**
	 * @param array{wordpress_version?:string, elementor?:array<string,mixed>|null}|null $probes
	 */
	public function __construct( ?array $probes = null ) {
		$this->probes = $probes;
	}

	/**
	 * Bounded capability digest.
	 *
	 * @return array<string, mixed>
	 */
	public function digest( string $mode = self::MODE_SUMMARY ): array {
		$mode      = self::MODE_FULL === $mode ? self::MODE_FULL : self::MODE_SUMMARY;
		$wordpress = $this->wordpress_version();
		$elementor = $this->elementor_runtime();

		$fingerprint = self::fingerprint( $wordpress, $elementor );

		if ( null === $this->probes ) {
			$cached = get_transient( self::CACHE_TRANSIENT_PREFIX . $fingerprint );
			if ( is_array( $cached ) && ( $cached['mode'] ?? '' ) === $mode ) {
				return $cached['digest'];
			}
		}

		$digest = $this->build( $mode, $wordpress, $elementor, $fingerprint );

		if ( null === $this->probes ) {
			set_transient(
				self::CACHE_TRANSIENT_PREFIX . $fingerprint,
				[ 'mode' => $mode, 'digest' => $digest ],
				self::CACHE_TTL
			);
		}

		return $digest;
	}

	/**
	 * Stable fingerprint over every input the digest depends on. A runtime
	 * upgrade changes at least one component and invalidates the cache key.
	 *
	 * @param array<string, mixed>              $wordpress
	 * @param array<string, mixed>|null         $elementor
	 */
	public static function fingerprint( array $wordpress, ?array $elementor ): string {
		return hash(
			'sha256',
			wp_json_encode(
				[
					'v' => 1,
					'w' => $wordpress,
					'e' => $elementor,
				]
			) ?: ''
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function build( string $mode, array $wordpress, ?array $elementor, string $fingerprint ): array {
		$gutenberg = GutenbergCapabilities::describe( $wordpress );
		$v3        = ElementorV3Capabilities::describe( $elementor, $mode );
		$v4        = ElementorV4Capabilities::describe( $elementor, $mode );

		$renderers = [
			'gutenberg-fse' => $gutenberg,
			'elementor-v3'  => $v3['renderer'],
			'elementor-v4'  => $v4['renderer'],
		];

		$warnings    = array_values( array_merge( $v3['warnings'], $v4['warnings'] ) );
		$unsupported = array_values( array_merge( $v3['unsupported'], $v4['unsupported'] ) );

		return [
			'ok'                    => true,
			'mode'                  => $mode,
			'read_only'             => true,
			'versions'              => [
				'wordpress'      => $wordpress,
				'elementor_core' => (string) ( $elementor['core_version'] ?? '' ),
				'elementor_pro'  => (string) ( $elementor['pro_version'] ?? '' ),
			],
			'renderers'             => $renderers,
			'schema_fingerprint'    => $fingerprint,
			'fallbacks'             => [
				'bundled_presets' => true,
				'description'     => 'Stonewright bundled static-first CSS presets are available on every renderer.',
			],
			'approval_requirements' => [
				[
					'requirement' => 'custom_code_grant',
					'applies_to'  => 'Free-form CSS/JS outside bundled presets.',
				],
				[
					'requirement' => 'explicit_user_approval',
					'applies_to'  => 'Third-party provider plugins and the future GSAP adapter.',
				],
				[
					'requirement' => 'loop_control_approval',
					'applies_to'  => 'Any playback=loop motion: requires a persistent, keyboard- and touch-operable control target.',
				],
			],
			'warnings'              => $warnings,
			'unsupported'           => $unsupported,
		];
	}

	/**
	 * @return array{version:string}
	 */
	private function wordpress_version(): array {
		if ( isset( $this->probes['wordpress_version'] ) ) {
			return [ 'version' => (string) $this->probes['wordpress_version'] ];
		}
		return [ 'version' => (string) get_bloginfo( 'version' ) ];
	}

	/**
	 * Snapshot of the loaded Elementor runtime, or null when Elementor is not
	 * active. Never invents versions or capabilities for an absent runtime.
	 *
	 * @return array<string, mixed>|null
	 */
	private function elementor_runtime(): ?array {
		if ( array_key_exists( 'elementor', $this->probes ?? [] ) ) {
			$injected = $this->probes['elementor'];
			return is_array( $injected ) ? $injected : null;
		}

		$installed = defined( 'ELEMENTOR_VERSION' ) || class_exists( '\\Elementor\\Plugin' );
		$active    = $installed && did_action( 'elementor/loaded' ) > 0;
		if ( ! $installed || ! $active ) {
			return null;
		}

		$interaction_schema = [];
		$triggers           = [];
		if ( is_callable( [ '\\Elementor\\Modules\\Interactions\\Presets', 'triggers_options' ] ) ) {
			try {
				$options  = (array) call_user_func( [ '\\Elementor\\Modules\\Interactions\\Presets', 'triggers_options' ] );
				$triggers = array_is_list( $options ) ? array_values( array_map( 'strval', $options ) ) : array_values( array_map( 'strval', array_keys( $options ) ) );
			} catch ( \Throwable $throwable ) {
				unset( $throwable );
			}
		}
		if ( class_exists( '\\Elementor\\Modules\\Interactions\\Props\\Interaction_Item_Prop_Type' ) ) {
			try {
				$prop = \Elementor\Modules\Interactions\Props\Interaction_Item_Prop_Type::make();
				if ( $prop instanceof \JsonSerializable ) {
					$serialized = $prop->jsonSerialize();
					$interaction_schema = is_array( $serialized ) ? $serialized : [];
				}
			} catch ( \Throwable $throwable ) {
				unset( $throwable );
			}
		}

		$write_primitives = [
			'document_mutator'     => class_exists( '\\Elementor\\Core\\Utils\\Document\\Document_Mutator' ),
			'interactions_applier' => class_exists( '\\Elementor\\Modules\\Mcp\\Abilities\\Appliers\\Interactions_Applier' ),
			'plain_values_resolver'=> class_exists( '\\Elementor\\Modules\\AtomicWidgets\\PlainResolvers\\Plain_Values_Resolver' ),
			'interactions_schema'  => class_exists( '\\Elementor\\Modules\\Interactions\\Schema\\Interactions_Schema' ),
		];

		return [
			'active'          => true,
			'core_version'    => defined( 'ELEMENTOR_VERSION' ) ? (string) ELEMENTOR_VERSION : '',
			'pro_active'      => defined( 'ELEMENTOR_PRO_VERSION' ) || class_exists( '\\ElementorPro\\Plugin' ),
			'pro_version'     => defined( 'ELEMENTOR_PRO_VERSION' ) ? (string) ELEMENTOR_PRO_VERSION : '',
			'atomic_module'   => class_exists( '\\Elementor\\Modules\\AtomicWidgets\\Module' ),
			'interactions'    => class_exists( '\\Elementor\\Modules\\Interactions\\Module' ),
			'interaction_triggers' => $triggers,
			'interaction_schema_fingerprint' => [] === $interaction_schema ? '' : hash( 'sha256', wp_json_encode( $interaction_schema ) ?: '' ),
			'breakpoint_exclusions_supported' => str_contains( wp_json_encode( $interaction_schema ) ?: '', 'excluded' ),
			'v4_write_primitives' => $write_primitives,
			'v4_write_adapter_ready' => ! in_array( false, $write_primitives, true ),
		];
	}
}
