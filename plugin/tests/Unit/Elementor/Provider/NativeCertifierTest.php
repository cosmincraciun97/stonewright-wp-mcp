<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Elementor\Provider;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Elementor\Provider\NativeCertifier;
use Stonewright\WpMcp\Elementor\Provider\NativeContracts;

/**
 * @covers \Stonewright\WpMcp\Elementor\Provider\NativeCertifier
 */
final class NativeCertifierTest extends TestCase {

	/** @return array<string,array{0:string}> */
	public static function certifiable_abilities(): array {
		return [
			'manage-default-styles'  => [ 'elementor/manage-default-styles' ],
			'manage-classes'         => [ 'elementor/manage-classes' ],
			'manage-global-variable' => [ 'elementor/manage-global-variable' ],
			'get-page-structure'     => [ 'elementor/get-page-structure' ],
			'build-composition'      => [ 'elementor/build-composition' ],
		];
	}

	/** @dataProvider certifiable_abilities */
	public function test_recorded_runtime_abilities_are_certified_against_their_contract( string $name ): void {
		$result = NativeCertifier::certify( self::ability( $name, '4.3.4' ), NativeContracts::for_ability( $name ) );

		self::assertSame( 'certified', $result['state'], wp_json_encode( $result['issues'] ) );
		self::assertSame( 'official_contract_certified', $result['reason'] );
		self::assertSame( [], $result['issues'] );
		self::assertSame( [], $result['contract']['issues'] );
		self::assertSame( NativeContracts::for_ability( $name )['access'], $result['contract']['access'] );
		self::assertNotSame( [], $result['contract']['side_effects'] );
	}

	/** @dataProvider certifiable_abilities */
	public function test_every_version_in_the_verified_minor_line_is_certified_and_the_next_minor_is_not( string $name ): void {
		foreach ( [ '4.3.0', '4.3.1', '4.3.2', '4.3.3', '4.3.4', '4.3.5', '4.3.12', '4.3.5-beta1' ] as $version ) {
			$source = 'elementor/get-page-structure' === $name && version_compare( $version, '4.3.4', '<' ) ? '4.3.3' : '4.3.4';
			$ability = self::ability( $name, $source );
			$ability['source_version'] = $version;
			self::assertSame( 'certified', NativeCertifier::certify( $ability, NativeContracts::for_ability( $name ) )['state'], $name . ' ' . $version );
		}
		foreach ( [ '4.4.0', '4.2.9', '5.0.0', '4.30.0', '4.4.0-beta1' ] as $version ) {
			$ability = self::ability( $name, '4.3.4' );
			$ability['source_version'] = $version;
			$result = NativeCertifier::certify( $ability, NativeContracts::for_ability( $name ) );
			self::assertSame( 'rejected', $result['state'], $name . ' ' . $version );
			self::assertContains( 'elementor_version_out_of_range', $result['issues'], $name . ' ' . $version );
		}
	}

	public function test_get_page_structure_selects_the_schema_variant_for_the_observed_version(): void {
		$name     = 'elementor/get-page-structure';
		$contract = NativeContracts::for_ability( $name );

		$old = self::ability( $name, '4.3.3' );
		$old['source_version'] = '4.3.3';
		self::assertSame( 'certified', NativeCertifier::certify( $old, $contract )['state'] );

		$new_on_old_version = self::ability( $name, '4.3.4' );
		$new_on_old_version['source_version'] = '4.3.3';
		$result = NativeCertifier::certify( $new_on_old_version, $contract );
		self::assertSame( 'rejected', $result['state'] );
		self::assertContains( 'input_schema_mismatch', $result['issues'] );

		$old_on_new_version = self::ability( $name, '4.3.3' );
		$old_on_new_version['source_version'] = '4.3.4';
		self::assertSame( 'rejected', NativeCertifier::certify( $old_on_new_version, $contract )['state'] );
	}

	public function test_required_version_policy_rejects_an_unobserved_or_malformed_version(): void {
		$name = 'elementor/manage-classes';
		foreach ( [ '', 'unknown', '3afafe33', '4.3', 'v4.3.4' ] as $version ) {
			$ability = self::ability( $name, '4.3.4' );
			$ability['source_version'] = $version;
			$result = NativeCertifier::certify( $ability, NativeContracts::for_ability( $name ) );

			self::assertSame( 'rejected', $result['state'], $version );
			self::assertContains( 'elementor_version_unverified', $result['issues'], $version );
		}
	}

	public function test_a_patch_release_certifies_only_when_every_fingerprint_matches_exactly(): void {
		foreach ( [ 'elementor/manage-default-styles', 'elementor/build-composition' ] as $name ) {
			$ability = self::ability( $name, '4.3.4' );
			$ability['source_version'] = '4.3.9';
			self::assertSame( 'certified', NativeCertifier::certify( $ability, NativeContracts::for_ability( $name ) )['state'], $name );

			$ability['output_schema']['properties']['extra'] = [ 'type' => 'string' ];
			$result = NativeCertifier::certify( $ability, NativeContracts::for_ability( $name ) );
			self::assertSame( 'rejected', $result['state'], $name );
			self::assertContains( 'output_schema_mismatch', $result['issues'], $name );
		}
	}

	public function test_default_styles_no_longer_certifies_without_a_readable_version(): void {
		$name = 'elementor/manage-default-styles';
		$ability = self::ability( $name, '4.3.4' );
		$ability['source_version'] = '';

		$result = NativeCertifier::certify( $ability, NativeContracts::for_ability( $name ) );

		self::assertSame( 'rejected', $result['state'] );
		self::assertContains( 'elementor_version_unverified', $result['issues'] );
	}

	public function test_a_contract_that_ignores_the_description_certifies_a_changed_description(): void {
		$name = 'elementor/build-composition';
		$ability = self::ability( $name, '4.3.4' );
		$ability['description'] = 'Entirely different wording.';

		self::assertSame( 'certified', NativeCertifier::certify( $ability, NativeContracts::for_ability( $name ) )['state'] );
	}

	/** @dataProvider certifiable_abilities */
	public function test_each_class_provider_owner_and_annotation_mismatch_fails_closed_with_its_exact_reason( string $name ): void {
		$contract = NativeContracts::for_ability( $name );
		$cases    = [];

		$ability = self::ability( $name, '4.3.4' );
		$ability['runtime_class'] = 'Elementor\\Modules\\Mcp\\Abilities\\Other_Ability';
		$cases['runtime_identity_mismatch'] = $ability;

		$ability = self::ability( $name, '4.3.4' );
		$ability['name'] = 'elementor/other-ability';
		$cases['runtime_identity_mismatch_name'] = $ability;

		$ability = self::ability( $name, '4.3.4' );
		$ability['source_plugin'] = 'third-party/bootstrap.php';
		$ability['meta']['source_plugin'] = 'third-party/bootstrap.php';
		$cases['official_owner_mismatch'] = $ability;

		$ability = self::ability( $name, '4.3.4' );
		$ability['meta']['annotations']['idempotent'] = ! $ability['meta']['annotations']['idempotent'];
		$cases['annotations_mismatch'] = $ability;

		$ability = self::ability( $name, '4.3.4' );
		$ability['provenance']['ownership'] = 'explicit_registration_metadata';
		$cases['ownership_unverified'] = $ability;

		$ability = self::ability( $name, '4.3.4' );
		$ability['runtime_contract']['runtime_operation_limit'] = 999;
		$cases['runtime_constants_mismatch'] = $ability;

		$ability = self::ability( $name, '4.3.4' );
		$ability['description'] .= ' Changed.';
		$cases['ability_semantics_mismatch'] = $ability;

		$ability = self::ability( $name, '4.3.4' );
		$ability['input_schema']['additionalProperties'] = false;
		$cases['input_schema_mismatch'] = $ability;

		$ability = self::ability( $name, '4.3.4' );
		$ability['output_schema']['properties']['extra'] = [ 'type' => 'string' ];
		$cases['output_schema_mismatch'] = $ability;

		foreach ( $cases as $label => $candidate ) {
			$expected = str_replace( '_name', '', $label );
			if ( 'runtime_constants_mismatch' === $expected && [] === ( $contract['runtime_contract'] ?? [] ) ) {
				continue;
			}
			if ( 'ability_semantics_mismatch' === $expected && 'ignored' === ( $contract['description_policy'] ?? '' ) ) {
				continue;
			}
			$result = NativeCertifier::certify( $candidate, $contract );

			self::assertSame( 'rejected', $result['state'], $name . ' ' . $label );
			self::assertSame( 'upstream_contract_not_certified', $result['reason'], $name . ' ' . $label );
			self::assertContains( $expected, $result['issues'], $name . ' ' . $label );
			self::assertSame( $result['issues'], $result['contract']['issues'], $name . ' ' . $label );
		}
	}

	public function test_a_live_schema_that_holds_empty_objects_certifies_like_its_decoded_json_recording(): void {
		$name    = 'elementor/build-composition';
		$ability = self::ability( $name, '4.3.4' );
		// The live runtime registers `default => (object) []`; a JSON recording decodes it to [].
		foreach ( [ 'element_config', 'style', 'classes', 'interactions' ] as $property ) {
			self::assertSame( [], $ability['input_schema']['properties'][ $property ]['default'] );
			$ability['input_schema']['properties'][ $property ]['default'] = new \stdClass();
		}

		$result = NativeCertifier::certify( $ability, NativeContracts::for_ability( $name ) );

		self::assertSame( 'certified', $result['state'], wp_json_encode( $result['issues'] ) );
		self::assertSame( NativeCertifier::fingerprint( self::ability( $name, '4.3.4' )['input_schema'] ), NativeCertifier::fingerprint( $ability['input_schema'] ) );
		self::assertNotSame( NativeCertifier::fingerprint( [ 'default' => [ 'a' => 1 ] ] ), NativeCertifier::fingerprint( [ 'default' => new \stdClass() ] ) );
		self::assertSame( NativeCertifier::fingerprint( [ 'default' => [ 'a' => 1 ] ] ), NativeCertifier::fingerprint( [ 'default' => (object) [ 'a' => 1 ] ] ) );
	}

	public function test_named_probes_report_the_exact_part_of_the_contract_that_changed(): void {
		$name     = 'elementor/manage-classes';
		$contract = NativeContracts::for_ability( $name );

		$ability = self::ability( $name, '4.3.4' );
		$ability['input_schema']['properties']['operations']['items']['properties']['action']['enum'] = [ 'create', 'update', 'delete', 'merge' ];
		$result = NativeCertifier::certify( $ability, $contract );
		self::assertContains( 'action_contract_mismatch', $result['issues'] );
		self::assertContains( 'input_schema_mismatch', $result['issues'] );

		$ability = self::ability( $name, '4.3.4' );
		$ability['input_schema']['properties']['operations']['items']['properties']['mode']['default'] = 'replace';
		self::assertContains( 'mode_contract_mismatch', NativeCertifier::certify( $ability, $contract )['issues'] );

		$ability = self::ability( 'elementor/manage-global-variable', '4.3.4' );
		$ability['input_schema']['properties']['operations']['items']['properties']['type']['enum'][] = 'global-image-variable';
		self::assertContains( 'variable_type_contract_mismatch', NativeCertifier::certify( $ability, NativeContracts::for_ability( 'elementor/manage-global-variable' ) )['issues'] );

		$ability = self::ability( 'elementor/get-page-structure', '4.3.4' );
		$ability['input_schema']['required'] = [ 'post_id', 'element_id' ];
		self::assertContains( 'input_required_mismatch', NativeCertifier::certify( $ability, NativeContracts::for_ability( 'elementor/get-page-structure' ) )['issues'] );
	}

	public function test_oversized_schemas_that_arrive_truncated_are_rejected_not_certified(): void {
		$name = 'elementor/manage-classes';
		$ability = self::ability( $name, '4.3.4' );
		$ability['input_schema'] = [];
		$ability['output_schema'] = [];

		$result = NativeCertifier::certify( $ability, NativeContracts::for_ability( $name ) );

		self::assertSame( 'rejected', $result['state'] );
		self::assertContains( 'input_schema_mismatch', $result['issues'] );
		self::assertContains( 'output_schema_mismatch', $result['issues'] );
	}

	public function test_unsupported_contracts_report_their_machine_readable_reasons_even_when_the_ability_is_registered(): void {
		$cases = [
			'elementor/manage-elements'   => [ 'upstream_global_clear_cache', [ 'upstream_global_clear_cache', 'staged_in_autosave' ] ],
		];
		foreach ( $cases as $name => [ $reason, $reasons ] ) {
			$ability = self::ability( 'elementor/manage-classes', '4.3.4' );
			$ability['name'] = $name;
			$result = NativeCertifier::certify( $ability, NativeContracts::for_ability( $name ) );

			self::assertSame( 'unsupported', $result['state'], $name );
			self::assertSame( $reason, $result['reason'], $name );
			self::assertSame( $reasons, $result['reasons'], $name );
			self::assertSame( $reasons, $result['contract']['reasons'], $name );
			self::assertContains( 'staged_in_autosave', array_column( $result['contract']['side_effects'], 'id' ), $name );
		}
	}

	public function test_an_ability_without_a_contract_is_unsupported_and_never_synthesized(): void {
		$result = NativeCertifier::certify( self::ability( 'elementor/manage-classes', '4.3.4' ), null );

		self::assertSame( 'unsupported', $result['state'] );
		self::assertSame( 'not_available_for_certification', $result['reason'] );
		self::assertSame( [], $result['issues'] );
		self::assertSame( [], $result['contract'] );
	}

	public function test_contract_summary_is_computed_from_the_raw_schema_and_bounded(): void {
		$name = 'elementor/manage-classes';
		$ability = self::ability( $name, '4.3.4' );
		$ability['input_schema']['properties']['operations']['items']['properties']['action']['enum'] = array_merge( [ ' CREATE ', 'Update' ], array_fill( 0, 5000, str_repeat( 'x', 100 ) ) );

		$summary = NativeCertifier::summarize( NativeContracts::for_ability( $name ), $ability );

		self::assertSame( [ 'create', 'update' ], array_slice( $summary['actions'], 0, 2 ) );
		self::assertCount( 20, $summary['actions'] );
		self::assertSame( 5002, $summary['actions_count'] );
		self::assertTrue( $summary['actions_truncated'] );
		self::assertTrue( $summary['responsive_css'] );
		self::assertTrue( $summary['pseudo_states'] );

		$ability['input_schema'] = [];
		$ability['contract_summary'] = $summary;
		$result = NativeCertifier::certify( $ability, NativeContracts::for_ability( $name ) );
		self::assertSame( 5002, $result['contract']['actions_count'], 'a summary computed before truncation survives schema truncation' );
	}

	/** @return array<string,mixed> */
	private static function ability( string $name, string $recorded_version ): array {
		$path    = dirname( __DIR__, 3 ) . '/fixtures/elementor-native/elementor-' . $recorded_version . '-abilities.json';
		$decoded = json_decode( (string) file_get_contents( $path ), true );
		$ability = $decoded['abilities'][ $name ] ?? null;
		self::assertIsArray( $ability, $name . ' ' . $recorded_version );
		return $ability;
	}
}
