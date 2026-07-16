<?php
/**
 * Compatibility gate for the frozen public ability contract.
 *
 * @package Stonewright\WpMcp
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Contracts;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Support\PublicApiContractSnapshot;

/**
 * @covers \Stonewright\WpMcp\Support\PublicApiContractSnapshot
 */
final class PublicApiContractTest extends TestCase {

	public function test_frozen_public_api_contract_is_compatible_with_live_registry(): void {
		$path = PublicApiContractSnapshot::contract_path();
		$this->assertFileExists( $path, 'docs/contracts/public-api-v1.json must be committed' );

		$frozen = PublicApiContractSnapshot::load( $path );
		$live   = PublicApiContractSnapshot::collect();

		$this->assertSame( PublicApiContractSnapshot::CONTRACT_VERSION, (int) ( $frozen['version'] ?? 0 ) );
		$this->assertNotEmpty( $frozen['abilities'] );
		$this->assertGreaterThanOrEqual( 308, count( $frozen['abilities'] ) );
		$this->assertGreaterThanOrEqual( 308, count( $live['abilities'] ) );

		$violations = PublicApiContractSnapshot::compatibility_violations( $frozen, $live );
		$this->assertSame(
			[],
			$violations,
			"Public API contract incompatibilities:\n - " . implode( "\n - ", $violations )
		);
	}

	public function test_live_registry_snapshot_has_expected_shape(): void {
		$live = PublicApiContractSnapshot::collect();

		$this->assertSame( 1, $live['version'] );
		$this->assertNotEmpty( $live['abilities'] );

		$names = [];
		foreach ( $live['abilities'] as $row ) {
			$this->assertIsArray( $row );
			$this->assertArrayHasKey( 'ability_name', $row );
			$this->assertArrayHasKey( 'mcp_name', $row );
			$this->assertArrayHasKey( 'kind', $row );
			$this->assertArrayHasKey( 'input_schema_hash', $row );
			$this->assertArrayHasKey( 'output_schema_hash', $row );
			$this->assertArrayHasKey( 'permission_class', $row );
			$this->assertArrayHasKey( 'gates', $row );
			$this->assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', (string) $row['input_schema_hash'] );
			$this->assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', (string) $row['output_schema_hash'] );
			$this->assertContains( $row['kind'], [ 'Read', 'Write' ] );
			$this->assertSame( str_replace( '/', '-', (string) $row['ability_name'] ), $row['mcp_name'] );
			$this->assertIsArray( $row['gates'] );
			foreach ( [ 'backup', 'token', 'validator', 'audit' ] as $gate ) {
				$this->assertArrayHasKey( $gate, $row['gates'] );
				$this->assertIsBool( $row['gates'][ $gate ] );
			}
			$names[] = (string) $row['ability_name'];
		}

		$sorted = $names;
		sort( $sorted, SORT_STRING );
		$this->assertSame( $sorted, $names, 'abilities must be sorted by ability_name' );
		$this->assertSame( $names, array_values( array_unique( $names ) ), 'ability_name values must be unique' );
	}

	public function test_allowlist_permits_intentional_removal(): void {
		$live = [
			'version'   => 1,
			'abilities' => [
				[
					'ability_name'       => 'stonewright/ping',
					'mcp_name'           => 'stonewright-ping',
					'kind'               => 'Read',
					'input_schema_hash'  => str_repeat( 'a', 64 ),
					'output_schema_hash' => str_repeat( 'b', 64 ),
					'permission_class'   => 'Permissions::read()',
					'gates'              => [
						'backup'    => false,
						'token'     => false,
						'validator' => false,
						'audit'     => false,
					],
				],
			],
		];

		$frozen = [
			'version'   => 1,
			'allowlist' => [
				'removed'        => [ 'stonewright/legacy' ],
				'renamed'        => [],
				'schema_changes' => [],
			],
			'abilities' => [
				$live['abilities'][0],
				[
					'ability_name'       => 'stonewright/legacy',
					'mcp_name'           => 'stonewright-legacy',
					'kind'               => 'Read',
					'input_schema_hash'  => str_repeat( 'c', 64 ),
					'output_schema_hash' => str_repeat( 'd', 64 ),
					'permission_class'   => 'Permissions::read()',
					'gates'              => [
						'backup'    => false,
						'token'     => false,
						'validator' => false,
						'audit'     => false,
					],
				],
			],
		];

		$this->assertSame( [], PublicApiContractSnapshot::compatibility_violations( $frozen, $live ) );
	}

	public function test_schema_change_without_allowlist_fails(): void {
		$row = [
			'ability_name'       => 'stonewright/ping',
			'mcp_name'           => 'stonewright-ping',
			'kind'               => 'Read',
			'input_schema_hash'  => str_repeat( 'a', 64 ),
			'output_schema_hash' => str_repeat( 'b', 64 ),
			'permission_class'   => 'Permissions::read()',
			'gates'              => [
				'backup'    => false,
				'token'     => false,
				'validator' => false,
				'audit'     => false,
			],
		];

		$frozen = [
			'version'   => 1,
			'allowlist' => [
				'removed'        => [],
				'renamed'        => [],
				'schema_changes' => [],
			],
			'abilities' => [ $row ],
		];

		$live_row                      = $row;
		$live_row['input_schema_hash'] = str_repeat( 'e', 64 );
		$live                          = [
			'version'   => 1,
			'abilities' => [ $live_row ],
		];

		$violations = PublicApiContractSnapshot::compatibility_violations( $frozen, $live );
		$this->assertNotEmpty( $violations );
		$this->assertStringContainsString( 'input_schema_hash', $violations[0] );
	}

	public function test_additions_are_allowed(): void {
		$base = [
			'ability_name'       => 'stonewright/ping',
			'mcp_name'           => 'stonewright-ping',
			'kind'               => 'Read',
			'input_schema_hash'  => str_repeat( 'a', 64 ),
			'output_schema_hash' => str_repeat( 'b', 64 ),
			'permission_class'   => 'Permissions::read()',
			'gates'              => [
				'backup'    => false,
				'token'     => false,
				'validator' => false,
				'audit'     => false,
			],
		];

		$frozen = [
			'version'   => 1,
			'allowlist' => [
				'removed'        => [],
				'renamed'        => [],
				'schema_changes' => [],
			],
			'abilities' => [ $base ],
		];

		$extra = $base;
		$extra['ability_name'] = 'stonewright/new-tool';
		$extra['mcp_name']     = 'stonewright-new-tool';

		$live = [
			'version'   => 1,
			'abilities' => [ $base, $extra ],
		];

		$this->assertSame( [], PublicApiContractSnapshot::compatibility_violations( $frozen, $live ) );
	}
}
