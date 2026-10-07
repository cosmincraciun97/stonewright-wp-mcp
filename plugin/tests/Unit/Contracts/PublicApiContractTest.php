<?php
/**
 * Compatibility gate for the frozen public ability contract.
 *
 * @package Stonewright\WpMcp
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Contracts;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\Comments\CommentCreate;
use Stonewright\WpMcp\Abilities\Comments\CommentDelete;
use Stonewright\WpMcp\Abilities\ElementorV3\CssRegenerate;
use Stonewright\WpMcp\Abilities\ElementorV3\PostWriteVerify;
use Stonewright\WpMcp\Abilities\Memory\MemorySave;
use Stonewright\WpMcp\Abilities\Site\Ping;
use Stonewright\WpMcp\Core\AbilityRegistry;
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
			$this->assertIsArray( $row['annotations'] ?? null, $row['ability_name'] . ' has no annotations in the contract.' );
			foreach ( [ 'readonly', 'destructive', 'idempotent', 'openWorldHint' ] as $hint ) {
				$this->assertArrayHasKey( $hint, $row['annotations'] );
				$this->assertIsBool( $row['annotations'][ $hint ] );
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

	public function test_audit_write_wrapper_counts_as_audit_gate(): void {
		$row = PublicApiContractSnapshot::collect_ability( MemorySave::class );
		$this->assertIsArray( $row );
		$this->assertTrue(
			(bool) $row['gates']['audit'],
			'Writers that call $this->audit_write( must keep gates.audit true.'
		);
	}

	public function test_audit_write_wrapper_also_counts_as_confirmation_token_gate(): void {
		$row = PublicApiContractSnapshot::collect_ability( CssRegenerate::class );
		$this->assertIsArray( $row );
		$this->assertTrue(
			(bool) $row['gates']['token'],
			'CSS regeneration uses audit_write, so its production-safe confirmation gate must be public contract data.'
		);
	}

	public function test_post_write_verify_is_not_a_confirmation_gated_write(): void {
		$row = PublicApiContractSnapshot::collect_ability( PostWriteVerify::class );
		$this->assertIsArray( $row );
		$this->assertFalse(
			(bool) $row['gates']['token'],
			'Post write verification is observation-only and must not require a confirmation token.'
		);
		$this->assertSame( 'Read', $row['kind'] );
	}

	public function test_removing_an_audit_call_is_still_a_contract_violation(): void {
		$row = [
			'ability_name'       => 'stonewright/memory-save',
			'mcp_name'           => 'stonewright-memory-save',
			'kind'               => 'Write',
			'input_schema_hash'  => str_repeat( 'a', 64 ),
			'output_schema_hash' => str_repeat( 'b', 64 ),
			'permission_class'   => 'Permissions::edit_posts()',
			'gates'              => [
				'backup'    => false,
				'token'     => true,
				'validator' => false,
				'audit'     => true,
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

		$live_row                   = $row;
		$live_row['gates']['audit'] = false;
		$live                       = [
			'version'   => 1,
			'abilities' => [ $live_row ],
		];

		$violations = PublicApiContractSnapshot::compatibility_violations( $frozen, $live );
		$this->assertNotEmpty( $violations );
		$this->assertStringContainsString( 'gates.audit changed (true -> false)', $violations[0] );
	}

	public function test_annotation_drift_is_a_contract_violation(): void {
		$row = [
			'ability_name'       => 'stonewright/comment-delete',
			'mcp_name'           => 'stonewright-comment-delete',
			'kind'               => 'Write',
			'input_schema_hash'  => str_repeat( 'a', 64 ),
			'output_schema_hash' => str_repeat( 'b', 64 ),
			'permission_class'   => 'Permissions::manage_options()',
			'gates'              => [
				'backup'    => false,
				'token'     => true,
				'validator' => false,
				'audit'     => true,
			],
			'annotations'        => [
				'readonly'      => false,
				'destructive'   => true,
				'idempotent'    => true,
				'openWorldHint' => false,
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

		$live_row                                  = $row;
		$live_row['annotations']['destructive']   = false;
		$live_row['annotations']['openWorldHint'] = true;
		$live                                      = [
			'version'   => 1,
			'abilities' => [ $live_row ],
		];

		$violations = PublicApiContractSnapshot::compatibility_violations( $frozen, $live );
		$this->assertCount( 2, $violations );
		$this->assertStringContainsString( 'annotations.destructive changed (true -> false)', $violations[0] );
		$this->assertStringContainsString( 'annotations.openWorldHint changed (false -> true)', $violations[1] );
	}

	public function test_a_contract_frozen_before_annotations_existed_is_still_accepted(): void {
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

		$live_row                = $row;
		$live_row['annotations'] = [
			'readonly'      => true,
			'destructive'   => false,
			'idempotent'    => true,
			'openWorldHint' => false,
		];

		$this->assertSame( [], PublicApiContractSnapshot::compatibility_violations( $frozen, [ 'version' => 1, 'abilities' => [ $live_row ] ] ) );
	}

	public function test_an_ability_that_records_through_the_write_wrapper_is_a_write(): void {
		$create = PublicApiContractSnapshot::collect_ability( CommentCreate::class );
		$this->assertIsArray( $create );
		$this->assertSame( 'Write', $create['kind'], 'comment-create records through audit_write() and creates a comment.' );

		$ping = PublicApiContractSnapshot::collect_ability( Ping::class );
		$this->assertIsArray( $ping );
		$this->assertSame( 'Read', $ping['kind'] );
	}

	public function test_the_contract_annotations_are_the_ones_the_ability_registers(): void {
		foreach ( [ Ping::class, CommentCreate::class, CommentDelete::class, MemorySave::class, CssRegenerate::class ] as $class ) {
			$row = PublicApiContractSnapshot::collect_ability( $class );
			$this->assertIsArray( $row );

			$registered = AbilityRegistry::registration_args( new $class() )['meta']['annotations'];
			$this->assertSame( $registered, $row['annotations'], $class );
		}
	}

	public function test_encoded_contract_keeps_recorded_renames_and_writes_an_empty_map_as_an_object(): void {
		$document = [
			'version'   => 1,
			'allowlist' => [
				'removed'        => [],
				'renamed'        => (object) [ 'stonewright/old-name' => 'stonewright/new-name' ],
				'schema_changes' => [],
			],
			'abilities' => [],
		];

		$decoded = json_decode( PublicApiContractSnapshot::encode_document( $document ), true, 512, JSON_THROW_ON_ERROR );
		$this->assertSame( [ 'stonewright/old-name' => 'stonewright/new-name' ], $decoded['allowlist']['renamed'] );

		$document['allowlist']['renamed'] = [];
		$this->assertStringContainsString( '"renamed": {}', PublicApiContractSnapshot::encode_document( $document ) );
	}
}
