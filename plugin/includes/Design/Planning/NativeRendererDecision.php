<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Design\Planning;

use Stonewright\WpMcp\Design\Manifest\SectionManifest;
use Stonewright\WpMcp\Support\Json;

/** Chooses a native renderer exclusively from required intent and verified live-schema candidates. */
final class NativeRendererDecision {

	/** @param array<string,mixed> $manifest @param array<string,mixed> $live_schema @return array<string,mixed>|\WP_Error */
	public static function choose( array $manifest, array $live_schema = [] ): array|\WP_Error {
		$validated = SectionManifest::validate( $manifest );
		if ( $validated instanceof \WP_Error ) {
			return $validated;
		}
		$manifest = $validated['manifest'];
		if ( 'page' === (string) ( $manifest['manifest_type'] ?? '' ) || [] !== (array) ( $manifest['sections'] ?? [] ) ) {
			return new \WP_Error(
				'stonewright_renderer_section_required',
				__( 'Decompose a page manifest and plan each ordered section independently.', 'stonewright' ),
				[ 'status' => 400, 'section_count' => count( (array) ( $manifest['sections'] ?? [] ) ) ]
			);
		}

		$requested_renderer = (string) ( $manifest['target_renderer'] ?? 'auto' );
		$required           = self::required_capabilities( $manifest );
		$candidates         = self::candidates( $live_schema, $requested_renderer );
		$evaluated          = [];
		foreach ( $candidates as $candidate ) {
			$evaluated[] = self::evaluate( $candidate, $required );
		}
		usort(
			$evaluated,
			static fn( array $a, array $b ): int => [ count( $b['matched_capabilities'] ), (string) $a['native_target'] ] <=> [ count( $a['matched_capabilities'] ), (string) $b['native_target'] ]
		);

		$selected = $evaluated[0] ?? null;
		if ( ! is_array( $selected ) ) {
			$gap = [
				[
					'code'                => 'native_gap_verified_renderer_missing',
					'capability'          => null,
					'reason'              => 'No registered renderer candidate with a valid live schema hash was supplied.',
					'custom_code_allowed' => false,
					'requires_approval'   => true,
				],
			];
			return self::receipt( $requested_renderer, null, $required, [], $required, $gap, $validated['digest_hash'], count( $candidates ) );
		}

		$gap = [];
		foreach ( $selected['unmatched_capabilities'] as $capability ) {
			$gap[] = [
				'code'                => 'native_gap_missing_capability',
				'capability'          => $capability,
				'reason'              => 'The selected live schema exposes neither this capability nor a verified control mapping for it.',
				'custom_code_allowed' => false,
				'requires_approval'   => true,
			];
		}

		$renderer = (string) $selected['renderer'];
		return self::receipt(
			$renderer,
			$selected,
			$required,
			$selected['matched_capabilities'],
			$selected['unmatched_capabilities'],
			$gap,
			$validated['digest_hash'],
			count( $candidates )
		);
	}

	/** @param array<string,mixed> $manifest @return list<string> */
	private static function required_capabilities( array $manifest ): array {
		$required = [];
		foreach ( (array) ( $manifest['interaction_intents'] ?? [] ) as $intent ) {
			if ( ! is_array( $intent ) ) {
				continue;
			}
			foreach ( (array) ( $intent['required_controls'] ?? [] ) as $control ) {
				$key = is_scalar( $control ) ? sanitize_key( (string) $control ) : '';
				if ( '' !== $key ) {
					$required[] = $key;
				}
			}
			if ( 'carousel' !== (string) ( $intent['type'] ?? '' ) ) {
				continue;
			}
			$required[] = 'slides_visible';
			$required[] = 'gap';
			if ( in_array( true, (array) ( $intent['arrows_enabled'] ?? [] ), true ) ) {
				$required[] = 'arrows';
				$required[] = 'arrow_asset';
			}
			if ( in_array( true, (array) ( $intent['dots_enabled'] ?? [] ), true ) ) {
				$required[] = 'dots';
			}
			foreach ( [ 'loop', 'autoplay', 'pause_on_hover', 'pause_on_focus', 'swipe', 'keyboard', 'rtl' ] as $key ) {
				if ( null !== ( $intent[ $key ] ?? null ) ) {
					$required[] = $key;
				}
			}
			if ( true === ( $intent['autoplay'] ?? null ) ) {
				$required[] = 'duration_ms';
			}
		}
		$required = array_values( array_unique( array_filter( $required ) ) );
		sort( $required );
		return $required;
	}

	/** @param array<string,mixed> $schema @return list<array<string,mixed>> */
	private static function candidates( array $schema, string $requested_renderer ): array {
		$rows = is_array( $schema['candidates'] ?? null ) ? $schema['candidates'] : [ $schema ];
		$out  = [];
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$candidate = self::normalize_candidate( $row );
			if ( null === $candidate ) {
				continue;
			}
			if ( 'auto' !== $requested_renderer && ! in_array( $requested_renderer, $candidate['renderers'], true ) ) {
				continue;
			}
			$out[] = $candidate;
		}
		return $out;
	}

	/** @param array<string,mixed> $row @return array<string,mixed>|null */
	private static function normalize_candidate( array $row ): ?array {
		$schema_hash = strtolower( trim( (string) ( $row['schema_hash'] ?? '' ) ) );
		$registered  = true === ( $row['registered'] ?? false ) || 'registered' === (string) ( $row['availability'] ?? '' );
		if ( ! $registered || 1 !== preg_match( '/^[a-f0-9]{64}$/', $schema_hash ) ) {
			return null;
		}

		$native_target = self::text( $row['native_target'] ?? $row['widget_type'] ?? $row['block_name'] ?? $row['element_type'] ?? '', 190 );
		if ( '' === $native_target ) {
			return null;
		}
		$renderers = self::renderer_list( $row );
		if ( [] === $renderers ) {
			return null;
		}

		$controls     = self::string_keys( $row['controls'] ?? $row['settings'] ?? $row['fields'] ?? [] );
		$capabilities = self::strings( $row['capabilities'] ?? [] );
		$control_map  = self::control_map( $row['control_map'] ?? [] );
		return [
			'native_target' => $native_target,
			'renderer'      => $renderers[0],
			'renderers'     => $renderers,
			'schema_hash'   => $schema_hash,
			'source_plugin' => self::text( $row['source_plugin'] ?? $row['source'] ?? '', 190 ),
			'controls'      => $controls,
			'capabilities'  => $capabilities,
			'control_map'   => $control_map,
		];
	}

	/** @param array<string,mixed> $row @return list<string> */
	private static function renderer_list( array $row ): array {
		$values = $row['renderers'] ?? [];
		if ( ! is_array( $values ) ) {
			$values = [];
		}
		if ( is_scalar( $row['renderer'] ?? null ) ) {
			$values[] = $row['renderer'];
		}
		if ( [] === $values && '' !== self::text( $row['widget_type'] ?? '', 190 ) ) {
			$values[] = 'elementor-v3';
		}
		if ( [] === $values && '' !== self::text( $row['block_name'] ?? '', 190 ) ) {
			$values = [ 'gutenberg', 'fse' ];
		}
		$out = [];
		foreach ( $values as $value ) {
			$renderer = is_scalar( $value ) ? strtolower( trim( (string) $value ) ) : '';
			if ( in_array( $renderer, [ 'elementor-v3', 'elementor-v4', 'gutenberg', 'fse' ], true ) ) {
				$out[] = $renderer;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/** @param array<string,mixed> $candidate @param list<string> $required @return array<string,mixed> */
	private static function evaluate( array $candidate, array $required ): array {
		$matched   = [];
		$unmatched = [];
		$mappings  = [];
		foreach ( $required as $capability ) {
			if ( in_array( $capability, $candidate['capabilities'], true ) ) {
				$matched[]              = $capability;
				$mappings[ $capability ] = [ 'evidence' => 'declared_capability', 'controls' => [] ];
				continue;
			}
			$mapped_controls = $candidate['control_map'][ $capability ] ?? [];
			if ( [] !== $mapped_controls && [] === array_diff( $mapped_controls, $candidate['controls'] ) ) {
				$matched[]              = $capability;
				$mappings[ $capability ] = [ 'evidence' => 'verified_control_map', 'controls' => $mapped_controls ];
				continue;
			}
			if ( in_array( $capability, $candidate['controls'], true ) ) {
				$matched[]              = $capability;
				$mappings[ $capability ] = [ 'evidence' => 'exact_live_control', 'controls' => [ $capability ] ];
				continue;
			}
			$unmatched[] = $capability;
		}
		$candidate['matched_capabilities']   = $matched;
		$candidate['unmatched_capabilities'] = $unmatched;
		$candidate['capability_evidence']     = $mappings;
		return $candidate;
	}

	/**
	 * @param array<string,mixed>|null $selected
	 * @param list<string> $required
	 * @param list<string> $matched
	 * @param list<string> $unmatched
	 * @param list<array<string,mixed>> $gap
	 * @return array<string,mixed>
	 */
	private static function receipt( string $renderer, ?array $selected, array $required, array $matched, array $unmatched, array $gap, string $manifest_digest, int $candidate_count ): array {
		$target = is_array( $selected ) ? (string) $selected['native_target'] : null;
		$hash   = is_array( $selected ) ? (string) $selected['schema_hash'] : null;
		return [
			'ok'                    => null !== $selected && [] === $unmatched,
			'target_renderer'       => $renderer,
			'native_target'         => $target,
			'schema_hash'           => $hash,
			'source_plugin'         => is_array( $selected ) ? (string) $selected['source_plugin'] : null,
			'required_capabilities' => $required,
			'required_controls'     => $required,
			'matched_capabilities'  => $matched,
			'matched_controls'      => $matched,
			'unmatched_capabilities' => $unmatched,
			'unmatched_controls'    => $unmatched,
			'capability_evidence'   => is_array( $selected ) ? $selected['capability_evidence'] : [],
			'native_gap'            => $gap,
			'confidence'            => [] === $required ? ( null !== $selected ? 1.0 : 0.0 ) : round( count( $matched ) / count( $required ), 4 ),
			'custom_code_approval'  => [] !== $gap,
			'custom_code_approved'  => false,
			'candidate_count'       => $candidate_count,
			'token_estimate'        => count( $required ) + $candidate_count,
			'manifest_digest'       => $manifest_digest,
			'decision_hash'         => Json::hash( [ $renderer, $target, $hash, $matched, $unmatched, $manifest_digest ] ),
		];
	}

	/** @return list<string> */
	private static function string_keys( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return [];
		}
		$out = [];
		foreach ( $value as $key => $item ) {
			if ( is_string( $key ) ) {
				$normalized = sanitize_key( $key );
				if ( '' !== $normalized ) {
					$out[] = $normalized;
				}
			}
			if ( is_string( $item ) ) {
				$normalized = sanitize_key( $item );
				if ( '' !== $normalized ) {
					$out[] = $normalized;
				}
			}
		}
		return array_values( array_unique( $out ) );
	}

	/** @return list<string> */
	private static function strings( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return [];
		}
		$out = [];
		foreach ( $value as $key => $item ) {
			if ( is_string( $key ) && true === $item ) {
				$item = $key;
			}
			$normalized = is_scalar( $item ) ? sanitize_key( (string) $item ) : '';
			if ( '' !== $normalized ) {
				$out[] = $normalized;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/** @return array<string,list<string>> */
	private static function control_map( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return [];
		}
		$out = [];
		foreach ( $value as $capability => $controls ) {
			$key = is_string( $capability ) ? sanitize_key( $capability ) : '';
			if ( '' === $key ) {
				continue;
			}
			$controls = is_array( $controls ) ? $controls : [ $controls ];
			$out[ $key ] = self::strings( $controls );
		}
		return $out;
	}

	private static function text( mixed $value, int $length ): string {
		return is_scalar( $value ) ? mb_substr( sanitize_text_field( (string) $value ), 0, $length ) : '';
	}
}
