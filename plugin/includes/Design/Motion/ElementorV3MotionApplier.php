<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Design\Motion;

use Stonewright\WpMcp\Elementor\Schema\WidgetSchemaRepository;

/**
 * Resolves a compiled elementor-v3 motion plan into batch-mutate update
 * operations using caller-supplied schema evidence.
 *
 * The compiler never invents Elementor setting keys or enum values. For every
 * settings-evidence-patch operation the agent supplies the control key and
 * native value it read from stonewright/elementor-schema; this applier
 * re-validates both against the live widget schema before any write. Unknown
 * controls, out-of-enum values, and Pro controls without an active Pro
 * runtime are refused — never approximated.
 */
final class ElementorV3MotionApplier {

	/**
	 * @param list<array{target_id:string, element_id:string, widget_type:string}> $requested_targets
	 * @param array<int, array<string, mixed>>                                     $evidence target_id => live schema evidence
	 * @param array<string, mixed>                                                 $plan
	 * @param array<string, mixed>|null                                            $digest capability digest (pro_active check)
	 * @return array{operations: list<array<string, mixed>>, resolved: list<string>, touched_element_ids: list<string>}|\WP_Error
	 */
	public static function build_operations( array $requested_targets, array $evidence, array $plan, ?array $digest = null ) {
		if ( (string) ( $plan['renderer'] ?? '' ) !== 'elementor-v3' ) {
			return new \WP_Error(
				'stonewright_motion_renderer_mismatch',
				'This applier accepts elementor-v3 plans only.',
				[ 'status' => 400 ]
			);
		}

		$plan_ops = array_values(
			array_filter(
				(array) ( $plan['operations'] ?? [] ),
				static fn( $op ): bool => is_array( $op ) && 'settings-evidence-patch' === ( $op['op'] ?? '' )
			)
		);
		if ( [] === $plan_ops ) {
			return new \WP_Error(
				'stonewright_motion_plan_has_no_v3_operations',
				'The plan contains no settings-evidence-patch operations for Elementor V3.',
				[ 'status' => 400 ]
			);
		}

		$targets_by_id = [];
		foreach ( $requested_targets as $entry ) {
			$id = (string) ( $entry['target_id'] ?? '' );
			if (
				'' === $id
				|| isset( $targets_by_id[ $id ] )
				|| '' === (string) ( $entry['element_id'] ?? '' )
				|| '' === (string) ( $entry['widget_type'] ?? '' )
			) {
				return new \WP_Error(
					'stonewright_motion_target_invalid',
					sprintf( 'Target "%s" is empty, duplicated, or missing element_id/widget_type.', $id ),
					[ 'status' => 400 ]
				);
			}
			$targets_by_id[ $id ] = $entry;
		}

		$pro_active = is_array( $digest ) ? (bool) ( $digest['renderers']['elementor-v3']['pro_active'] ?? false ) : false;

		$operations = [];
		$resolved   = [];
		$touched    = [];

		foreach ( $plan_ops as $op ) {
			$target_id = (string) ( $op['target_id'] ?? '' );
			if ( ! isset( $targets_by_id[ $target_id ] ) ) {
				return new \WP_Error(
					'stonewright_motion_target_missing_from_page',
					sprintf( 'Plan target "%s" has no element mapping.', $target_id ),
					[ 'status' => 422, 'target_id' => $target_id ]
				);
			}

			$ev = $evidence[ $target_id ] ?? null;
			$required_evidence = [ 'control_key', 'value', 'capability', 'semantic_effect', 'schema_hash', 'runtime_fingerprint', 'source_plugin', 'source_version' ];
			if ( ! is_array( $ev ) || [] !== array_diff( $required_evidence, array_keys( $ev ) ) ) {
				return new \WP_Error(
					'stonewright_motion_evidence_missing',
					sprintf( 'Target "%s" needs complete live schema evidence, including capability, semantic effect, schema/runtime hashes, and source identity.', $target_id ),
					[ 'status' => 422, 'target_id' => $target_id ]
				);
			}
			if ( (string) $ev['capability'] !== (string) ( $op['capability'] ?? '' )
				|| (string) $ev['semantic_effect'] !== (string) ( $op['semantic_effect'] ?? '' ) ) {
				return new \WP_Error(
					'stonewright_motion_evidence_semantic_mismatch',
					sprintf( 'Target "%s" evidence does not match the signed motion operation.', $target_id ),
					[ 'status' => 409, 'target_id' => $target_id ]
				);
			}

			$validated = self::validate_against_schema( $targets_by_id[ $target_id ], $ev, (string) ( $op['capability'] ?? '' ), $pro_active );
			if ( is_wp_error( $validated ) ) {
				return $validated;
			}

			$operations[] = [
				'action'     => 'update_element',
				'element_id' => (string) $targets_by_id[ $target_id ]['element_id'],
				'settings'   => [ (string) $ev['control_key'] => $ev['value'] ],
				'mode'       => 'merge',
			];
			$resolved[]   = $target_id;
			$touched[]    = (string) $targets_by_id[ $target_id ]['element_id'];
		}

		return [
			'operations'         => $operations,
			'resolved'           => $resolved,
			'touched_element_ids'=> array_values( array_unique( $touched ) ),
		];
	}

	/**
	 * Re-validates caller evidence against the live widget schema.
	 */
	private static function validate_against_schema( array $target, array $evidence, string $capability, bool $pro_active ): \WP_Error|null {
		$schema = WidgetSchemaRepository::get( (string) $target['widget_type'] );
		if ( is_wp_error( $schema ) ) {
			return new \WP_Error(
				'stonewright_motion_schema_unavailable',
				sprintf( 'Live widget schema for "%s" is unavailable; refusing to guess control keys.', (string) $target['widget_type'] ),
				[ 'status' => 422, 'reason' => $schema->get_error_code() ]
			);
		}
		foreach ( [ 'schema_hash', 'runtime_fingerprint', 'source_plugin', 'source_version' ] as $field ) {
			if ( (string) ( $evidence[ $field ] ?? '' ) !== (string) ( $schema[ $field ] ?? '' ) ) {
				return new \WP_Error(
					'stonewright_motion_schema_evidence_stale',
					sprintf( 'Live schema evidence field "%s" changed for widget "%s".', $field, (string) $target['widget_type'] ),
					[ 'status' => 409, 'field' => $field ]
				);
			}
		}

		$control_key = (string) ( $evidence['control_key'] ?? '' );
		$value       = (string) ( $evidence['value'] ?? '' );
		$allowed_key = 'entrance_animations' === $capability
			? ( '_animation' === $control_key || str_starts_with( $control_key, '_animation_' ) )
			: ( 'motion_effects' === $capability && str_starts_with( $control_key, '_motion_fx_' ) );
		if ( ! $allowed_key ) {
			return new \WP_Error(
				'stonewright_motion_control_outside_capability',
				sprintf( 'Control "%s" is outside motion capability "%s".', $control_key, $capability ),
				[ 'status' => 422 ]
			);
		}

		$controls = (array) ( $schema['controls'] ?? [] );
		if ( ! isset( $controls[ $control_key ] ) || ! is_array( $controls[ $control_key ] ) ) {
			return new \WP_Error(
				'stonewright_motion_control_unknown',
				sprintf( 'Control "%s" does not exist on widget "%s" in the live schema.', $control_key, (string) $target['widget_type'] ),
				[ 'status' => 422 ]
			);
		}

		$control = $controls[ $control_key ];
		$options = $control['options'] ?? null;
		if ( is_array( $options ) && [] !== $options && ! in_array( $value, array_map( 'strval', array_keys( $options ) ), true ) ) {
			return new \WP_Error(
				'stonewright_motion_value_not_in_enum',
				sprintf( 'Value "%s" is not offered by control "%s" in the live schema.', $value, $control_key ),
				[ 'status' => 422 ]
			);
		}

		$requires_pro = (bool) ( $control['pro_required'] ?? false );
		if ( $requires_pro && ! $pro_active ) {
			return new \WP_Error(
				'stonewright_motion_pro_required',
				sprintf( 'Control "%s" requires Elementor Pro, which is not active.', $control_key ),
				[ 'status' => 422 ]
			);
		}

		return null;
	}
}
