<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Elementor\Provider;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Elementor\Provider\NativeContracts;

/**
 * @covers \Stonewright\WpMcp\Elementor\Provider\NativeContracts
 */
final class NativeContractsTest extends TestCase {

	private const CERTIFIABLE = [
		'elementor/manage-default-styles',
		'elementor/manage-classes',
		'elementor/manage-global-variable',
		'elementor/get-page-structure',
		'elementor/build-composition',
	];

	private const UNSUPPORTED = [
		'elementor/manage-elements'    => [ 'upstream_global_clear_cache', [ 'upstream_global_clear_cache', 'staged_in_autosave' ] ],
	];

	/** @var list<string> */
	private array $temporary = [];

	protected function tearDown(): void {
		foreach ( $this->temporary as $directory ) {
			foreach ( glob( $directory . '/*' ) ?: [] as $file ) {
				unlink( $file );
			}
			rmdir( $directory );
		}
		$this->temporary = [];
	}

	public function test_one_contract_file_exists_per_native_ability_and_loads_cleanly(): void {
		$names = NativeContracts::names();

		self::assertSame( [], NativeContracts::errors() );
		foreach ( array_merge( self::CERTIFIABLE, array_keys( self::UNSUPPORTED ) ) as $name ) {
			self::assertContains( $name, $names, $name );
			self::assertFileExists( NativeContracts::path() . '/' . substr( $name, strlen( 'elementor/' ) ) . '.json', $name );
		}
		self::assertCount( 6, $names );
	}

	public function test_certifiable_contracts_carry_exact_fingerprints_provider_class_versions_and_side_effects(): void {
		foreach ( self::CERTIFIABLE as $name ) {
			$contract = NativeContracts::for_ability( $name );

			self::assertIsArray( $contract, $name );
			self::assertSame( 'certifiable', $contract['status'], $name );
			self::assertSame( 'elementor-core', $contract['provider'], $name );
			self::assertSame( 'elementor/elementor.php', $contract['source_plugin'], $name );
			self::assertMatchesRegularExpression( '/^Elementor\\\\Modules\\\\Mcp\\\\Abilities\\\\[A-Za-z_]+$/', $contract['runtime_class'], $name );
			self::assertContains( $contract['access'], [ 'read', 'write' ], $name );
			self::assertSame( 'required', $contract['version_policy'], $name . ' every native contract pins the Elementor version' );
			self::assertNotSame( [], $contract['side_effects'], $name );
			self::assertNotSame( [], $contract['schemas'], $name );
			foreach ( $contract['schemas'] as $schema ) {
				self::assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $schema['input_fingerprint'], $name );
				self::assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $schema['output_fingerprint'], $name );
				if ( 'ignored' !== ( $contract['description_policy'] ?? '' ) ) {
					self::assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $schema['description_fingerprint'], $name );
				}
				self::assertMatchesRegularExpression( '/^\d+\.\d+\.\d+$/', $schema['elementor_versions']['min'], $name );
				self::assertMatchesRegularExpression( '/^\d+\.\d+\.(\d+|\*)$/', $schema['elementor_versions']['max'], $name );
			}
			self::assertNotSame( '', $contract['evidence']['source_file'], $name );
		}
	}

	public function test_global_cache_clear_is_recorded_as_a_known_side_effect_where_the_source_calls_it(): void {
		$with_clear = [ 'elementor/manage-classes', 'elementor/manage-global-variable', 'elementor/manage-elements' ];
		$without    = [ 'elementor/manage-default-styles', 'elementor/get-page-structure', 'elementor/build-composition' ];

		foreach ( $with_clear as $name ) {
			self::assertContains( 'global_css_cache_clear', array_column( NativeContracts::for_ability( $name )['side_effects'], 'id' ), $name );
		}
		foreach ( $without as $name ) {
			self::assertNotContains( 'global_css_cache_clear', array_column( NativeContracts::for_ability( $name )['side_effects'], 'id' ), $name );
		}
		self::assertContains( 'css_containment', NativeContracts::for_ability( 'elementor/manage-classes' )['closure_requirements'] );
		self::assertContains( 'autosave_truth', NativeContracts::for_ability( 'elementor/get-page-structure' )['closure_requirements'] );
	}

	public function test_manage_elements_stays_explicitly_unsupported_with_machine_readable_reasons(): void {
		foreach ( self::UNSUPPORTED as $name => [ $reason, $reasons ] ) {
			$contract = NativeContracts::for_ability( $name );

			self::assertSame( 'unsupported', $contract['status'], $name );
			self::assertSame( $reason, $contract['reason'], $name );
			self::assertSame( $reasons, $contract['reasons'], $name );
			self::assertArrayNotHasKey( 'schemas', $contract, $name );
			self::assertContains( 'staged_in_autosave', array_column( $contract['side_effects'], 'id' ), $name );
			self::assertContains( 'autosave_truth', $contract['unblock_requires'], $name );
		}
		self::assertContains( 'css_containment', NativeContracts::for_ability( 'elementor/manage-elements' )['unblock_requires'] );
	}

	public function test_fingerprints_in_the_contract_files_match_the_recorded_runtime_schemas(): void {
		$recorded = [
			'4.3.4' => self::recorded( '4.3.4' ),
			'4.3.3' => self::recorded( '4.3.3' ),
		];
		foreach ( self::CERTIFIABLE as $name ) {
			foreach ( NativeContracts::for_ability( $name )['schemas'] as $schema ) {
				$version = str_ends_with( $schema['elementor_versions']['max'], '.*' ) ? '4.3.4' : $schema['elementor_versions']['max'];
				$ability = $recorded[ $version ][ $name ] ?? null;
				self::assertIsArray( $ability, $name . ' ' . $version );
				self::assertSame( $schema['input_fingerprint'], self::fingerprint( $ability['input_schema'] ), $name . ' input ' . $version );
				self::assertSame( $schema['output_fingerprint'], self::fingerprint( $ability['output_schema'] ), $name . ' output ' . $version );
				if ( 'ignored' !== ( NativeContracts::for_ability( $name )['description_policy'] ?? '' ) ) {
					self::assertSame( $schema['description_fingerprint'], self::fingerprint( $ability['description'] ), $name . ' description ' . $version );
				}
			}
		}
	}

	public function test_every_contract_declares_its_native_routing(): void {
		$expected = [
			'elementor/manage-default-styles'  => [ 'kit_defaults', 'allowed', null ],
			'elementor/build-composition'      => [ 'tree_composition', 'allowed', null ],
			'elementor/get-page-structure'     => [ 'structure_read', 'read_only', null ],
			'elementor/manage-classes'         => [ 'global_kit', 'refused', 'upstream_global_clear_cache' ],
			'elementor/manage-global-variable' => [ 'global_kit', 'refused', 'upstream_global_clear_cache' ],
		];
		foreach ( $expected as $name => [ $family, $write, $reason ] ) {
			$routing = NativeContracts::for_ability( $name )['routing'];

			self::assertSame( $family, $routing['family'], $name );
			self::assertSame( $write, $routing['native_write'], $name );
			self::assertSame( $reason, $routing['refusal_reason'] ?? null, $name );
		}
	}

	public function test_a_native_contract_never_allows_a_write_that_clears_generated_css_site_wide(): void {
		foreach ( NativeContracts::names() as $name ) {
			$contract = NativeContracts::for_ability( $name );
			$effects  = array_column( $contract['side_effects'], 'id' );
			if ( in_array( 'global_css_cache_clear', $effects, true ) ) {
				self::assertNotSame( 'allowed', $contract['routing']['native_write'] ?? 'refused', $name );
			}
		}
	}

	public function test_embedded_certified_input_schemas_hash_to_their_fingerprints(): void {
		foreach ( [ 'elementor/manage-default-styles', 'elementor/build-composition', 'elementor/get-page-structure' ] as $name ) {
			foreach ( NativeContracts::for_ability( $name )['schemas'] as $schema ) {
				self::assertIsArray( $schema['input_schema'], $name );
				self::assertSame( $schema['input_fingerprint'], self::fingerprint( $schema['input_schema'] ), $name );
			}
		}
	}

	public function test_a_contract_whose_embedded_schema_does_not_match_its_fingerprint_is_ignored(): void {
		$directory = $this->directory();
		$contract  = NativeContracts::for_ability( 'elementor/build-composition' );
		$contract['schemas'][0]['input_schema']['properties']['injected'] = [ 'type' => 'string' ];
		file_put_contents( $directory . '/build-composition.json', wp_json_encode( $contract ) );

		$loaded = NativeContracts::load_from( $directory );

		self::assertSame( [], $loaded['contracts'] );
		self::assertSame( 'contract_invalid', $loaded['errors'][0]['code'] );
	}

	public function test_a_certifiable_contract_without_routing_or_with_an_allowed_global_clear_is_ignored(): void {
		$directory = $this->directory();
		$base      = NativeContracts::for_ability( 'elementor/manage-classes' );
		$missing   = $base;
		unset( $missing['routing'] );
		file_put_contents( $directory . '/manage-classes.json', wp_json_encode( $missing ) );
		$allowed = NativeContracts::for_ability( 'elementor/manage-global-variable' );
		$allowed['routing']['native_write'] = 'allowed';
		unset( $allowed['routing']['refusal_reason'] );
		file_put_contents( $directory . '/manage-global-variable.json', wp_json_encode( $allowed ) );

		$loaded = NativeContracts::load_from( $directory );

		self::assertSame( [], $loaded['contracts'] );
		self::assertSame( [ 'contract_invalid', 'contract_invalid' ], array_column( $loaded['errors'], 'code' ) );
	}

	public function test_a_certifiable_contract_with_a_loose_version_policy_is_ignored(): void {
		$directory = $this->directory();
		$contract  = NativeContracts::for_ability( 'elementor/manage-default-styles' );
		$contract['version_policy'] = 'when_observed';
		file_put_contents( $directory . '/manage-default-styles.json', wp_json_encode( $contract ) );

		self::assertSame( [], NativeContracts::load_from( $directory )['contracts'] );
	}

	public function test_unknown_ability_has_no_contract(): void {
		self::assertNull( NativeContracts::for_ability( 'elementor/does-not-exist' ) );
		self::assertNull( NativeContracts::for_ability( '' ) );
		self::assertNull( NativeContracts::for_ability( '../manage-classes' ) );
	}

	public function test_invalid_or_mismatched_contract_files_fail_closed(): void {
		$directory = $this->directory();
		$valid     = NativeContracts::for_ability( 'elementor/manage-classes' );
		file_put_contents( $directory . '/manage-classes.json', wp_json_encode( $valid ) );
		file_put_contents( $directory . '/broken.json', '{not json' );
		$wrong_name = $valid;
		$wrong_name['ability'] = 'elementor/manage-variable-other';
		file_put_contents( $directory . '/misnamed.json', wp_json_encode( $wrong_name ) );
		$no_fingerprint = $valid;
		$no_fingerprint['ability'] = 'elementor/no-fingerprint';
		$no_fingerprint['schemas'][0]['input_fingerprint'] = 'not-a-hash';
		file_put_contents( $directory . '/no-fingerprint.json', wp_json_encode( $no_fingerprint ) );
		$no_reason = NativeContracts::for_ability( 'elementor/manage-elements' );
		$no_reason['ability'] = 'elementor/no-reason';
		unset( $no_reason['reason'] );
		file_put_contents( $directory . '/no-reason.json', wp_json_encode( $no_reason ) );

		$loaded = NativeContracts::load_from( $directory );

		self::assertSame( [ 'elementor/manage-classes' ], array_keys( $loaded['contracts'] ) );
		$codes = array_column( $loaded['errors'], 'code', 'file' );
		self::assertSame( 'contract_file_unreadable', $codes['broken.json'] );
		self::assertSame( 'contract_name_mismatch', $codes['misnamed.json'] );
		self::assertSame( 'contract_invalid', $codes['no-fingerprint.json'] );
		self::assertSame( 'contract_invalid', $codes['no-reason.json'] );
	}

	public function test_missing_directory_loads_nothing_and_reports_the_error(): void {
		$loaded = NativeContracts::load_from( sys_get_temp_dir() . '/stonewright-no-such-contract-directory' );

		self::assertSame( [], $loaded['contracts'] );
		self::assertSame( 'contract_directory_unreadable', $loaded['errors'][0]['code'] );
	}

	private function directory(): string {
		$directory = sys_get_temp_dir() . '/stonewright-contracts-' . bin2hex( random_bytes( 4 ) );
		mkdir( $directory );
		$this->temporary[] = $directory;
		return $directory;
	}

	/** @return array<string,array<string,mixed>> */
	private static function recorded( string $version ): array {
		$decoded = json_decode( (string) file_get_contents( dirname( __DIR__, 3 ) . '/fixtures/elementor-native/elementor-' . $version . '-abilities.json' ), true );
		return (array) $decoded['abilities'];
	}

	private static function fingerprint( mixed $value ): string {
		$canonicalize = static function ( mixed $item ) use ( &$canonicalize ): mixed {
			if ( ! is_array( $item ) ) {
				return $item;
			}
			if ( ! array_is_list( $item ) ) {
				ksort( $item );
			}
			foreach ( $item as $key => $child ) {
				$item[ $key ] = $canonicalize( $child );
			}
			return $item;
		};
		return hash( 'sha256', (string) wp_json_encode( $canonicalize( $value ) ) );
	}
}
