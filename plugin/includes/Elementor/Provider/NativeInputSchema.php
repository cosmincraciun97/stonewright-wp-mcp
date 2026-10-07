<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Elementor\Provider;

/**
 * Input schemas for the native bridge, taken from the certified contracts so agents do not guess fields.
 *
 * Only abilities whose contract routes a native write or read, and whose embedded certified
 * input schema fits the provider schema limits, are offered.
 */
final class NativeInputSchema {

	/**
	 * @return list<string> Routable ability names, sorted.
	 */
	public static function routable_abilities(): array {
		return array_keys( self::schemas() );
	}

	/**
	 * Certified input schemas of the routable abilities, one `anyOf` branch each.
	 *
	 * @return array<string,mixed>
	 */
	public static function input(): array {
		$schemas = array_values( self::schemas() );
		if ( [] === $schemas ) {
			return [ 'type' => 'object', 'description' => 'The native ability\'s own input.' ];
		}
		return [
			'description' => 'The native Elementor ability\'s own input, in the certified shape for the chosen ability.',
			'anyOf'       => $schemas,
		];
	}

	/** @return array<string, array<string,mixed>> */
	private static function schemas(): array {
		$out = [];
		foreach ( NativeContracts::names() as $name ) {
			$contract = NativeContracts::for_ability( $name );
			if ( null === $contract || 'certifiable' !== $contract['status'] || ! in_array( $contract['routing']['native_write'] ?? '', [ 'allowed', 'read_only' ], true ) ) {
				continue;
			}
			$variant = end( $contract['schemas'] );
			$schema  = is_array( $variant ) ? ( $variant['input_schema'] ?? null ) : null;
			if ( is_array( $schema ) && ProviderRouter::schema_within_limits( $schema ) ) {
				$out[ $name ] = $schema;
			}
		}
		ksort( $out );
		return $out;
	}
}
