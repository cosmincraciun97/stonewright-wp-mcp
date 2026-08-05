<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Design\Diagnostics;

use Stonewright\WpMcp\Support\Json;

/** Identifies controls that must survive a native patch or replace operation. */
final class ThirdPartyControlRiskMap {

	/** @param array<string,mixed> $before @param array<string,mixed> $patch @param array<string,mixed> $context @return array<string,mixed> */
	public static function analyze( array $before, array $patch, array $context = [] ): array {
		$known = array_values( array_filter( array_map( 'strval', (array) ( $context['known_controls'] ?? [] ) ) ) );
		$actions = self::actions( $context['actions'] ?? $before['actions_after_submit'] ?? $before['submit_actions'] ?? [] );
		$mode = in_array( (string) ( $context['operation_mode'] ?? 'replace' ), [ 'merge', 'replace' ], true ) ? (string) ( $context['operation_mode'] ?? 'replace' ) : 'replace';
		$registered = array_values( array_filter( array_map( 'strval', (array) ( $context['plugin_registered_controls'] ?? [] ) ) ) );
		$all_keys = array_keys( $before );
		$unknown = [];
		$owned = [];
		foreach ( $all_keys as $key ) {
			$key = (string) $key;
			if ( in_array( $key, $known, true ) || self::owned_key( $key ) ) {
				$owned[] = $key;
			} else {
				$unknown[] = $key;
			}
		}
		$plugin_registered = array_values( array_intersect( $registered, array_map( 'strval', $all_keys ) ) );
		$protected = array_values(
			array_unique(
				array_merge(
					$unknown,
					$plugin_registered,
					array_values( array_filter( array_map( 'strval', $all_keys ), [ self::class, 'high_risk_key' ] ) )
				)
			)
		);
		$removed = 'replace' === $mode ? array_values( array_diff( array_map( 'strval', array_keys( $before ) ), array_map( 'strval', array_keys( $patch ) ) ) ) : [];
		$removed_protected = array_values( array_intersect( $removed, $protected ) );
		$high_risk_actions = array_values( array_intersect( $actions, [ 'newsman', 'webhook', 'mailchimp', 'activecampaign', 'getresponse' ] ) );
		$projected = 'replace' === $mode ? $patch : self::merge( $before, $patch );
		$preserved_before = array_intersect_key( $before, array_fill_keys( $protected, true ) );
		$preserved_after  = array_intersect_key( $projected, array_fill_keys( $protected, true ) );
		$preservation_hash_before = Json::hash( $preserved_before );
		$preservation_hash_after  = Json::hash( $preserved_after );
		return [
			'owned_controls'             => $owned,
			'unknown_controls'           => $unknown,
			'plugin_registered_controls' => $plugin_registered,
			'actions_referenced'         => $actions,
			'high_risk_actions'          => $high_risk_actions,
			'removed_keys'               => $removed,
			'removed_protected_keys'     => $removed_protected,
			'destructive_replace_risk'   => 'replace' === $mode && ( [] !== $removed_protected || [] !== $high_risk_actions || ! hash_equals( $preservation_hash_before, $preservation_hash_after ) ),
			'safe_patch_keys'            => array_values( array_diff( array_intersect( array_map( 'strval', array_keys( $patch ) ), $known ), $protected ) ),
			'before_hash'                => Json::hash( $before ),
			'preservation_hash'          => $preservation_hash_before,
			'preservation_hash_before'   => $preservation_hash_before,
			'preservation_hash_after'    => $preservation_hash_after,
			'operation_mode'             => $mode,
			'preserve_unknown'           => true,
		];
	}

	/** @return list<string> */
	private static function actions( mixed $value ): array {
		$values = is_array( $value ) ? $value : ( is_scalar( $value ) ? preg_split( '/[\s,|]+/', (string) $value, -1, PREG_SPLIT_NO_EMPTY ) : [] );
		$out = [];
		foreach ( is_array( $values ) ? $values : [] as $item ) {
			if ( is_scalar( $item ) ) {
				$key = sanitize_key( (string) $item );
				if ( '' !== $key ) {
					$out[] = $key;
				}
			}
		}
		return array_values( array_unique( $out ) );
	}

	private static function high_risk_key( string $key ): bool {
		return 1 === preg_match( '/actions?_after_submit|submit_actions|form_fields|newsman|webhook|mail|email|smtp|recipient|reply[_-]?to|conditional|field_id/i', $key );
	}

	/** @param array<string,mixed> $before @param array<string,mixed> $patch @return array<string,mixed> */
	private static function merge( array $before, array $patch ): array {
		$out = $before;
		foreach ( $patch as $key => $value ) {
			$out[ $key ] = $value;
		}
		return $out;
	}

	private static function owned_key( string $key ): bool {
		return in_array( $key, [ 'id', 'elType', 'widgetType', 'settings', 'elements', 'blockName', 'attrs', 'innerHTML', 'innerBlocks' ], true );
	}
}
