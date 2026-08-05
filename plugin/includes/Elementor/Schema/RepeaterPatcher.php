<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Elementor\Schema;

use Stonewright\WpMcp\Support\Json;

/**
 * Applies one surgical Elementor repeater-row patch without rebuilding the
 * repeater. Unknown row fields, row order, action settings, and identities are
 * preservation invariants, not best-effort behavior.
 */
final class RepeaterPatcher {

	/**
	 * @param array<string,mixed> $settings
	 * @param array<string,mixed> $selector
	 * @param array<string,mixed> $row_patch
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function patch( array $settings, string $repeater_key, array $selector, array $row_patch, string $expected_row_hash = '' ): array|\WP_Error {
		$repeater_key = sanitize_key( $repeater_key );
		if ( '' === $repeater_key || ! isset( $settings[ $repeater_key ] ) || ! is_array( $settings[ $repeater_key ] ) || ! array_is_list( $settings[ $repeater_key ] ) ) {
			return new \WP_Error(
				'stonewright_elementor_repeater_invalid',
				__( 'The requested Elementor repeater setting is missing or is not a row list.', 'stonewright' ),
				[ 'status' => 400, 'repeater_key' => $repeater_key ]
			);
		}

		$selector_keys = array_values( array_intersect( [ 'custom_id', '_id' ], array_keys( $selector ) ) );
		if ( 1 !== count( $selector_keys ) ) {
			return new \WP_Error(
				'stonewright_elementor_repeater_selector_invalid',
				__( 'Select an Elementor repeater row by exactly one stable custom_id or _id.', 'stonewright' ),
				[ 'status' => 400, 'allowed_selectors' => [ 'custom_id', '_id' ] ]
			);
		}
		$selector_key   = $selector_keys[0];
		$selector_value = is_scalar( $selector[ $selector_key ] ) ? trim( (string) $selector[ $selector_key ] ) : '';
		if ( '' === $selector_value ) {
			return new \WP_Error(
				'stonewright_elementor_repeater_selector_invalid',
				__( 'The Elementor repeater selector value cannot be empty.', 'stonewright' ),
				[ 'status' => 400, 'selector' => $selector_key ]
			);
		}

		$matches = [];
		foreach ( $settings[ $repeater_key ] as $index => $row ) {
			if ( is_array( $row ) && is_scalar( $row[ $selector_key ] ?? null ) && hash_equals( $selector_value, trim( (string) $row[ $selector_key ] ) ) ) {
				$matches[] = (int) $index;
			}
		}
		if ( 1 !== count( $matches ) ) {
			return new \WP_Error(
				'stonewright_ambiguous_repeater_row',
				__( 'The Elementor repeater selector must match exactly one row.', 'stonewright' ),
				[
					'status'        => 409,
					'repeater_key'  => $repeater_key,
					'selector'      => $selector_key,
					'matches'       => count( $matches ),
					'retryable'     => true,
					'remediation'   => 'Refresh the widget settings and select one unique custom_id, falling back to _id only when custom_id is unavailable.',
				]
			);
		}

		$index  = $matches[0];
		$before = $settings[ $repeater_key ][ $index ];
		if ( ! is_array( $before ) ) {
			return new \WP_Error( 'stonewright_elementor_repeater_invalid', __( 'The matched Elementor repeater row is malformed.', 'stonewright' ), [ 'status' => 400 ] );
		}
		$row_hash = Json::hash( $before );
		if ( '' !== $expected_row_hash && ( 1 !== preg_match( '/^[a-f0-9]{64}$/', $expected_row_hash ) || ! hash_equals( $expected_row_hash, $row_hash ) ) ) {
			return new \WP_Error(
				'stonewright_elementor_repeater_conflict',
				__( 'The Elementor repeater row changed after planning; refresh it before patching.', 'stonewright' ),
				[ 'status' => 409, 'expected_row_hash' => $expected_row_hash, 'current_row_hash' => $row_hash, 'retryable' => true ]
			);
		}

		foreach ( [ 'custom_id', '_id' ] as $identity_key ) {
			if ( array_key_exists( $identity_key, $row_patch ) && (string) $row_patch[ $identity_key ] !== (string) ( $before[ $identity_key ] ?? '' ) ) {
				return new \WP_Error(
					'stonewright_elementor_repeater_identity_protected',
					__( 'A surgical repeater patch cannot change the row identity.', 'stonewright' ),
					[ 'status' => 400, 'path' => $repeater_key . '.' . $index . '.' . $identity_key ]
				);
			}
			unset( $row_patch[ $identity_key ] );
		}
		if ( [] === $row_patch ) {
			return new \WP_Error( 'stonewright_no_effective_changes', __( 'The repeater row patch contains no mutable fields.', 'stonewright' ), [ 'status' => 400 ] );
		}

		$preserved_keys = array_values( array_diff( array_keys( $before ), array_keys( $row_patch ) ) );
		$preserved_before = array_intersect_key( $before, array_fill_keys( $preserved_keys, true ) );
		$after = self::merge( $before, $row_patch );
		$preserved_after = array_intersect_key( $after, array_fill_keys( $preserved_keys, true ) );
		$unknown_before_hash = Json::hash( $preserved_before );
		$unknown_after_hash  = Json::hash( $preserved_after );
		if ( ! hash_equals( $unknown_before_hash, $unknown_after_hash ) ) {
			return new \WP_Error( 'stonewright_elementor_repeater_preservation_failed', __( 'The repeater patch would alter fields outside the requested row patch.', 'stonewright' ), [ 'status' => 409 ] );
		}

		$actions_before_hash = self::actions_hash( $settings );
		$next = $settings;
		$next[ $repeater_key ][ $index ] = $after;
		$actions_after_hash = self::actions_hash( $next );
		if ( ! hash_equals( $actions_before_hash, $actions_after_hash ) ) {
			return new \WP_Error( 'stonewright_elementor_form_actions_changed', __( 'The repeater patch would alter protected form actions.', 'stonewright' ), [ 'status' => 409 ] );
		}

		$changed_paths = [];
		foreach ( array_keys( $row_patch ) as $key ) {
			if ( ! array_key_exists( $key, $before ) || $before[ $key ] !== $after[ $key ] ) {
				$changed_paths[] = sprintf( '%s[%s=%s].%s', $repeater_key, $selector_key, $selector_value, (string) $key );
			}
		}
		if ( [] === $changed_paths ) {
			return new \WP_Error( 'stonewright_no_effective_changes', __( 'The requested repeater patch produces no effective changes.', 'stonewright' ), [ 'status' => 400 ] );
		}

		return [
			'settings'                  => $next,
			'repeater_key'              => $repeater_key,
			'row_index'                 => $index,
			'selector'                  => [ $selector_key => $selector_value ],
			'row_hash_before'           => $row_hash,
			'row_hash_after'            => Json::hash( $after ),
			'unknown_fields_hash_before'=> $unknown_before_hash,
			'unknown_fields_hash_after' => $unknown_after_hash,
			'actions_after_submit_hash_before' => $actions_before_hash,
			'actions_after_submit_hash_after'  => $actions_after_hash,
			'changed_paths'             => $changed_paths,
		];
	}

	/** @param array<string,mixed> $before @param array<string,mixed> $patch @return array<string,mixed> */
	private static function merge( array $before, array $patch ): array {
		$out = $before;
		foreach ( $patch as $key => $value ) {
			if ( is_array( $value ) && ! array_is_list( $value ) && is_array( $out[ $key ] ?? null ) && ! array_is_list( $out[ $key ] ) ) {
				$out[ $key ] = self::merge( $out[ $key ], $value );
				continue;
			}
			$out[ $key ] = $value;
		}
		return $out;
	}

	/** @param array<string,mixed> $settings */
	private static function actions_hash( array $settings ): string {
		$protected = [];
		foreach ( $settings as $key => $value ) {
			$key = (string) $key;
			if ( preg_match( '/actions?_after_submit|submit_actions|newsman|mail|email|smtp|recipient|reply[_-]?to|conditional/i', $key ) ) {
				$protected[ $key ] = $value;
			}
		}
		return Json::hash( $protected );
	}
}
