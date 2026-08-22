<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Design\Motion;

/**
 * Verifies compiled motion plans immediately before dry-run or apply.
 *
 * The HMAC prevents callers from editing operations and recomputing a plain
 * digest. Runtime bindings make registry, asset, capability, direction, and
 * renderer drift fail closed instead of applying a stale plan.
 */
final class MotionPlanVerifier {

	/**
	 * @param array<string, mixed>      $plan
	 * @param array<string, mixed>|null $digest
	 * @param array<string, mixed>|null $direction
	 */
	public static function verify( array $plan, ?array $digest = null, ?array $direction = null ): bool|\WP_Error {
		$bindings   = is_array( $plan['bindings'] ?? null ) ? $plan['bindings'] : [];
		$operations = is_array( $plan['operations'] ?? null ) ? array_values( $plan['operations'] ) : [];
		$provided   = (string) ( $plan['plan_hash'] ?? '' );

		if ( ! preg_match( '/^[a-f0-9]{64}$/', $provided ) || [] === $bindings ) {
			return self::error( 'motion_plan_invalid', 'The motion plan is missing a valid signed hash or bindings.' );
		}

		$expected = MotionPlanCompiler::plan_hash( $bindings, $operations );
		if ( ! hash_equals( $expected, $provided ) ) {
			return self::error( 'motion_plan_signature_mismatch', 'The motion plan was modified after compilation.' );
		}

		$renderer = (string) ( $plan['renderer'] ?? '' );
		if ( '' === $renderer || $renderer !== (string) ( $bindings['renderer'] ?? '' ) ) {
			return self::error( 'motion_plan_renderer_drift', 'The plan renderer no longer matches its signed binding.' );
		}

		$manifest = MotionPresetRegistry::manifest();
		$current  = [
			'css' => (string) ( $manifest['assets']['css']['sha256'] ?? '' ),
			'js'  => (string) ( $manifest['assets']['js']['sha256'] ?? '' ),
		];
		if ( (string) ( $bindings['registry_fingerprint'] ?? '' ) !== MotionPresetRegistry::fingerprint()
			|| $current !== (array) ( $bindings['asset_checksums'] ?? [] ) ) {
			return self::error( 'motion_plan_assets_stale', 'The motion preset registry or bundled assets changed; compile a fresh plan.' );
		}

		$bound_capability = (string) ( $bindings['capability_fingerprint'] ?? '' );
		$current_capability = is_array( $digest ) ? (string) ( $digest['schema_fingerprint'] ?? '' ) : '';
		if ( '' !== $bound_capability && ! hash_equals( $bound_capability, $current_capability ) ) {
			return self::error( 'motion_plan_capability_stale', 'The bound capability digest is missing or changed; compile a fresh plan.' );
		}

		$bound_direction = $bindings['direction'] ?? null;
		if ( null !== $bound_direction ) {
			$current_direction = self::direction_ref( $direction );
			if ( wp_json_encode( $bound_direction ) !== wp_json_encode( $current_direction ) ) {
				return self::error( 'motion_plan_direction_stale', 'The bound design direction is missing or changed; compile a fresh plan.' );
			}
		}

		return true;
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

	private static function error( string $code, string $message ): \WP_Error {
		return new \WP_Error( 'stonewright_' . $code, $message, [ 'status' => 409 ] );
	}
}
