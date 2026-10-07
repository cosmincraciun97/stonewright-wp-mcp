<?php
/**
 * The ChangeSetV1 shape, its builder, its validator and its published schema.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Security\ChangeSet;

require_once __DIR__ . '/ChangeSetAssertions.php';

/**
 * @covers \Stonewright\WpMcp\Security\ChangeSet
 */
final class ChangeSetTest extends TestCase {
	use ChangeSetAssertions;

	private const HASH_A = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
	private const HASH_B = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

	/** @return array<string, mixed> */
	private static function inputs(): array {
		$entry = ChangeSet::entry( 'element', 'hero', 'update_element', 0 );
		return [
			'change_set_id'       => 'cs-0123456789abcdef01234567',
			'planned'             => [ $entry ],
			'applied'             => [ $entry ],
			'before_hash'         => self::HASH_A,
			'after_hash'          => self::HASH_B,
			'verification'        => [ 'status' => 'verified', 'evidence' => [ 'method' => 'readback_hash' ] ],
			'rollback_available'  => true,
			'rollback_recipe_ref' => [ 'kind' => 'post_snapshot', 'ref' => 'snap_1', 'target' => '42' ],
			'approval_reason'     => 'confirmation_token',
		];
	}

	public function test_build_returns_every_core_field_in_the_declared_order(): void {
		$change_set = ChangeSet::build( self::inputs() );

		self::assertSame( ChangeSet::REQUIRED_FIELDS, array_keys( $change_set ) );
		self::assertSame( 'ChangeSetV1', $change_set['schema'] );
		self::assertSame( 'cs-0123456789abcdef01234567', $change_set['change_set_id'] );
		self::assertSame( [], $change_set['missing'] );
		self::assertSame( [], $change_set['unexpected'] );
		self::assertNull( $change_set['repair_of'] );
		self::assertNull( $change_set['supersedes'] );
		self::assertSame( 'verified', $change_set['verification']['status'] );
		self::assertSame(
			[ 'planned' => 1, 'applied' => 1, 'missing' => 0, 'unexpected' => 0 ],
			$change_set['verification']['evidence']['counts']
		);
		self::assertValidChangeSet( $change_set );
	}

	public function test_an_empty_input_still_yields_a_valid_unverified_change_set(): void {
		$change_set = ChangeSet::build( [] );

		self::assertSame( 'unverified', $change_set['verification']['status'] );
		self::assertSame( '', $change_set['before_hash'] );
		self::assertFalse( $change_set['rollback_available'] );
		self::assertNull( $change_set['rollback_recipe_ref'] );
		self::assertNull( $change_set['approval_reason'] );
		self::assertMatchesRegularExpression( '/^cs-[a-f0-9]{24}$/', $change_set['change_set_id'] );
		self::assertValidChangeSet( $change_set );
	}

	public function test_the_id_is_derived_deterministically_from_the_seed_when_none_is_given(): void {
		$first  = ChangeSet::build( [ 'seed' => [ 'file', 'style.css', self::HASH_A ] ] );
		$replay = ChangeSet::build( [ 'seed' => [ 'file', 'style.css', self::HASH_A ] ] );
		$other  = ChangeSet::build( [ 'seed' => [ 'file', 'style.css', self::HASH_B ] ] );

		self::assertSame( $first['change_set_id'], $replay['change_set_id'] );
		self::assertNotSame( $first['change_set_id'], $other['change_set_id'] );
		self::assertSame( $first['change_set_id'], ChangeSet::derive_id( [ 'file', 'style.css', self::HASH_A ] ) );
		self::assertSame( 'cs-given', ChangeSet::build( [ 'change_set_id' => 'cs-given', 'seed' => [ 'x' ] ] )['change_set_id'] );
	}

	/** @return array<string, array{0: string, 1: string}> */
	public static function legacy_statuses(): array {
		return [
			'verified'       => [ 'verified', 'verified' ],
			'passed'         => [ 'passed', 'verified' ],
			'failed'         => [ 'failed', 'failed' ],
			'missing'        => [ 'missing', 'failed' ],
			'mismatch'       => [ 'mismatch', 'failed' ],
			'planned'        => [ 'planned', 'unverified' ],
			'dry run'        => [ 'dry_run', 'unverified' ],
			'pending'        => [ 'pending', 'unverified' ],
			'queued'         => [ 'queued', 'unverified' ],
			'unchanged'      => [ 'unchanged', 'unverified' ],
			'blocked'        => [ 'blocked', 'unverified' ],
			'not applied'    => [ 'not_applied', 'unverified' ],
			'stale'          => [ 'stale_candidate', 'unverified' ],
			'empty'          => [ '', 'unverified' ],
			'upper case'     => [ 'VERIFIED', 'verified' ],
		];
	}

	/** @dataProvider legacy_statuses */
	public function test_legacy_verification_words_map_onto_the_three_statuses( string $legacy, string $expected ): void {
		self::assertSame( $expected, ChangeSet::normalize_status( $legacy ) );
		$change_set = ChangeSet::build( [ 'verification' => [ 'status' => $legacy ] ] );
		self::assertSame( $expected, $change_set['verification']['status'] );
	}

	public function test_hashes_are_lowercase_sha256_or_empty(): void {
		$change_set = ChangeSet::build( [ 'before_hash' => strtoupper( self::HASH_A ), 'after_hash' => 'not-a-hash' ] );

		self::assertSame( self::HASH_A, $change_set['before_hash'] );
		self::assertSame( '', $change_set['after_hash'] );
		self::assertValidChangeSet( $change_set );
	}

	public function test_entries_are_sanitized_deduplicated_and_cut_with_exact_counts(): void {
		$planned = [];
		for ( $i = 0; $i < 60; ++$i ) {
			$planned[] = ChangeSet::entry( 'element', 'el-' . $i, 'update_element', $i );
		}
		$planned[] = ChangeSet::entry( 'element', 'el-0', 'update_element', 0 );
		$planned[] = [ 'kind' => 'Bad Kind', 'ref' => '', 'action' => '' ];
		$planned[] = 'not an entry';
		$planned[] = [ 'kind' => 'element', 'ref' => str_repeat( 'r', 400 ), 'action' => 'remove_element', 'note' => str_repeat( 'n', 400 ) ];

		$change_set = ChangeSet::build( [ 'planned' => $planned ] );

		self::assertCount( ChangeSet::MAX_ENTRIES, $change_set['planned'] );
		self::assertSame( 61, $change_set['verification']['evidence']['counts']['planned'] );
		self::assertSame( [ 'planned' ], $change_set['verification']['evidence']['truncated'] );
		self::assertValidChangeSet( $change_set );

		$only_last = ChangeSet::build( [ 'planned' => [ end( $planned ) ] ] );
		self::assertSame( 190, strlen( $only_last['planned'][0]['ref'] ) );
		self::assertSame( 160, strlen( $only_last['planned'][0]['note'] ) );
	}

	public function test_a_rollback_recipe_exists_exactly_when_rollback_is_available(): void {
		$recipe = [ 'kind' => 'theme_backup', 'ref' => 'sw-theme-backup-1' ];

		$available = ChangeSet::build( [ 'rollback_available' => true, 'rollback_recipe_ref' => $recipe ] );
		self::assertTrue( $available['rollback_available'] );
		self::assertSame( $recipe, $available['rollback_recipe_ref'] );

		$no_recipe = ChangeSet::build( [ 'rollback_available' => true ] );
		self::assertFalse( $no_recipe['rollback_available'] );
		self::assertNull( $no_recipe['rollback_recipe_ref'] );

		$not_available = ChangeSet::build( [ 'rollback_available' => false, 'rollback_recipe_ref' => $recipe ] );
		self::assertFalse( $not_available['rollback_available'] );
		self::assertNull( $not_available['rollback_recipe_ref'] );

		$bad_kind = ChangeSet::build( [ 'rollback_available' => true, 'rollback_recipe_ref' => [ 'kind' => '!!!', 'ref' => 'x' ] ] );
		self::assertFalse( $bad_kind['rollback_available'] );

		foreach ( [ $available, $no_recipe, $not_available, $bad_kind ] as $change_set ) {
			self::assertValidChangeSet( $change_set );
		}
	}

	public function test_repair_of_supersedes_and_approval_reason_are_bounded_or_null(): void {
		$change_set = ChangeSet::build(
			[
				'repair_of'       => '  cs-failed-1 ',
				'supersedes'      => str_repeat( 's', 200 ),
				'approval_reason' => 'Custom Code Grant',
			]
		);

		self::assertSame( 'cs-failed-1', $change_set['repair_of'] );
		self::assertSame( 96, strlen( (string) $change_set['supersedes'] ) );
		self::assertSame( 'custom_code_grant', $change_set['approval_reason'] );

		$empty = ChangeSet::build( [ 'repair_of' => '', 'supersedes' => '   ', 'approval_reason' => '' ] );
		self::assertNull( $empty['repair_of'] );
		self::assertNull( $empty['supersedes'] );
		self::assertNull( $empty['approval_reason'] );
		self::assertValidChangeSet( $change_set );
		self::assertValidChangeSet( $empty );
	}

	public function test_evidence_is_bounded_and_never_carries_secrets(): void {
		$evidence = [
			'method'             => 'readback_hash',
			'long'               => str_repeat( 'x', 500 ),
			'confirmation_token' => 'do-not-log-me',
			'api_secret'         => 'do-not-log-me',
			'password_hint'      => 'do-not-log-me',
			'flag'               => true,
			'number'             => 3.5,
			'list'               => array_merge( range( 1, 30 ), [ [ 'nested' => 'dropped' ] ] ),
			'map'                => [ 'a' => 'one', 'b' => [ 'deep' => 'dropped' ], 'token_x' => 'dropped' ],
			'empty_map'          => [],
			'object'             => new \stdClass(),
		];
		$change_set = ChangeSet::build( [ 'verification' => [ 'status' => 'failed', 'evidence' => $evidence ] ] );
		$out        = $change_set['verification']['evidence'];

		self::assertSame( 'readback_hash', $out['method'] );
		self::assertSame( 200, strlen( $out['long'] ) );
		self::assertTrue( $out['flag'] );
		self::assertSame( 3.5, $out['number'] );
		self::assertCount( 20, $out['list'] );
		self::assertSame( [ 'a' => 'one' ], $out['map'] );
		foreach ( [ 'confirmation_token', 'api_secret', 'password_hint', 'empty_map', 'object' ] as $dropped ) {
			self::assertArrayNotHasKey( $dropped, $out );
		}
		self::assertStringNotContainsString( 'do-not-log-me', (string) json_encode( $change_set ) );
		self::assertValidChangeSet( $change_set );
	}

	/** @return array<string, array{0: callable(array<string, mixed>): array<string, mixed>}> */
	public static function invalid_documents(): array {
		return [
			'missing schema'             => [ static function ( array $d ): array { unset( $d['schema'] ); return $d; } ],
			'wrong schema'               => [ static function ( array $d ): array { $d['schema'] = 'ChangeSetV2'; return $d; } ],
			'missing change_set_id'      => [ static function ( array $d ): array { unset( $d['change_set_id'] ); return $d; } ],
			'empty change_set_id'        => [ static function ( array $d ): array { $d['change_set_id'] = ''; return $d; } ],
			'long change_set_id'         => [ static function ( array $d ): array { $d['change_set_id'] = str_repeat( 'a', 97 ); return $d; } ],
			'missing planned'            => [ static function ( array $d ): array { unset( $d['planned'] ); return $d; } ],
			'planned not a list'         => [ static function ( array $d ): array { $d['planned'] = 'x'; return $d; } ],
			'51 planned entries'         => [ static function ( array $d ): array { $d['planned'] = array_fill( 0, 51, [ 'kind' => 'element', 'ref' => 'a', 'action' => 'b' ] ); return $d; } ],
			'entry without ref'          => [ static function ( array $d ): array { $d['applied'] = [ [ 'kind' => 'element', 'action' => 'b' ] ]; return $d; } ],
			'entry with extra key'       => [ static function ( array $d ): array { $d['missing'] = [ [ 'kind' => 'element', 'ref' => 'a', 'action' => 'b', 'x' => 1 ] ]; return $d; } ],
			'entry kind upper case'      => [ static function ( array $d ): array { $d['unexpected'] = [ [ 'kind' => 'Element', 'ref' => 'a', 'action' => 'b' ] ]; return $d; } ],
			'entry negative index'       => [ static function ( array $d ): array { $d['planned'] = [ [ 'kind' => 'element', 'ref' => 'a', 'action' => 'b', 'index' => -1 ] ]; return $d; } ],
			'entry note too long'        => [ static function ( array $d ): array { $d['planned'] = [ [ 'kind' => 'element', 'ref' => 'a', 'action' => 'b', 'note' => str_repeat( 'n', 161 ) ] ]; return $d; } ],
			'short hash'                 => [ static function ( array $d ): array { $d['before_hash'] = 'abc'; return $d; } ],
			'upper case hash'            => [ static function ( array $d ): array { $d['after_hash'] = strtoupper( self::HASH_A ); return $d; } ],
			'missing verification'       => [ static function ( array $d ): array { unset( $d['verification'] ); return $d; } ],
			'unknown status'             => [ static function ( array $d ): array { $d['verification']['status'] = 'passed'; return $d; } ],
			'missing evidence'           => [ static function ( array $d ): array { unset( $d['verification']['evidence'] ); return $d; } ],
			'evidence without counts'    => [ static function ( array $d ): array { $d['verification']['evidence'] = [ 'method' => 'x' ]; return $d; } ],
			'negative count'             => [ static function ( array $d ): array { $d['verification']['evidence']['counts']['planned'] = -1; return $d; } ],
			'evidence nested too deep'   => [ static function ( array $d ): array { $d['verification']['evidence']['deep'] = [ 'a' => [ 'b' => 'c' ] ]; return $d; } ],
			'evidence string too long'   => [ static function ( array $d ): array { $d['verification']['evidence']['long'] = str_repeat( 'x', 201 ); return $d; } ],
			'rollback_available string'  => [ static function ( array $d ): array { $d['rollback_available'] = 'yes'; return $d; } ],
			'available without recipe'   => [ static function ( array $d ): array { $d['rollback_recipe_ref'] = null; return $d; } ],
			'recipe without availability' => [ static function ( array $d ): array { $d['rollback_available'] = false; return $d; } ],
			'recipe kind upper case'     => [ static function ( array $d ): array { $d['rollback_recipe_ref']['kind'] = 'Kind'; return $d; } ],
			'recipe extra key'           => [ static function ( array $d ): array { $d['rollback_recipe_ref']['x'] = 'y'; return $d; } ],
			'empty repair_of'            => [ static function ( array $d ): array { $d['repair_of'] = ''; return $d; } ],
			'repair_of number'           => [ static function ( array $d ): array { $d['repair_of'] = 7; return $d; } ],
			'supersedes too long'        => [ static function ( array $d ): array { $d['supersedes'] = str_repeat( 's', 97 ); return $d; } ],
			'approval_reason spaced'     => [ static function ( array $d ): array { $d['approval_reason'] = 'Bad Reason'; return $d; } ],
			'unknown top-level field'    => [ static function ( array $d ): array { $d['reuse_source'] = null; return $d; } ],
		];
	}

	/**
	 * The PHP validator and the published schema must reject the same documents.
	 *
	 * @dataProvider invalid_documents
	 * @param callable(array<string, mixed>): array<string, mixed> $mutate
	 */
	public function test_php_validator_and_schema_reject_the_same_invalid_documents( callable $mutate ): void {
		$base = ChangeSet::build( self::inputs() );
		self::assertValidChangeSet( $base, 'base document' );

		$document = $mutate( $base );

		self::assertNotSame( [], ChangeSet::validate( $document ), 'The PHP validator must reject this document.' );
		self::assertNotSame( [], self::change_set_schema_errors( $document ), 'The JSON schema must reject this document.' );
	}

	public function test_the_schema_requires_exactly_the_core_fields(): void {
		$schema = json_decode( (string) file_get_contents( self::change_set_schema_path() ), true );

		self::assertIsArray( $schema );
		self::assertSame( 'ChangeSetV1', $schema['title'] );
		self::assertSame( ChangeSet::REQUIRED_FIELDS, $schema['required'] );
		self::assertSame( ChangeSet::REQUIRED_FIELDS, array_keys( array_filter( $schema['properties'], static fn ( array $p ): bool => empty( $p['x-extension'] ) ) ) );
		self::assertFalse( $schema['additionalProperties'] );
		self::assertSame( ChangeSet::MAX_ENTRIES, $schema['$defs']['changeList']['maxItems'] );
	}

	public function test_extension_declarations_match_between_php_and_the_schema(): void {
		$schema    = json_decode( (string) file_get_contents( self::change_set_schema_path() ), true );
		$in_schema = [];
		foreach ( $schema['properties'] as $name => $property ) {
			if ( ! empty( $property['x-extension'] ) ) {
				$in_schema[] = $name;
				self::assertNotContains( $name, $schema['required'], 'An extension field is never required.' );
			}
		}

		self::assertSame( $in_schema, array_keys( ChangeSet::extension_fields() ) );
		foreach ( ChangeSet::extension_fields() as $name => $declaration ) {
			self::assertSame( $declaration['schema'], array_diff_key( $schema['properties'][ $name ], [ 'x-extension' => true ] ) );
		}
	}

	public function test_a_declared_extension_field_is_added_validated_and_optional(): void {
		$declared = [
			'example_origin' => [
				'nullable' => true,
				'schema'   => [
					'type'                 => [ 'object', 'null' ],
					'required'             => [ 'post_id', 'locator' ],
					'additionalProperties' => false,
					'properties'           => [
						'post_id' => [ 'type' => 'integer', 'minimum' => 1 ],
						'locator' => [ 'type' => 'string' ],
					],
				],
			],
		];
		$origin = [ 'post_id' => 7, 'locator' => 'section-3' ];

		$with = ChangeSet::build( self::inputs() + [ 'extensions' => [ 'example_origin' => $origin, 'improvised' => 'dropped' ] ], $declared );
		self::assertSame( array_merge( ChangeSet::REQUIRED_FIELDS, [ 'example_origin' ] ), array_keys( $with ) );
		self::assertSame( $origin, $with['example_origin'] );
		self::assertSame( [], ChangeSet::validate( $with, $declared ) );
		self::assertSame( [], self::change_set_schema_errors( $with, [ 'example_origin' => $declared['example_origin']['schema'] ] ) );

		$without = ChangeSet::build( self::inputs(), $declared );
		self::assertArrayNotHasKey( 'example_origin', $without );
		self::assertSame( [], ChangeSet::validate( $without, $declared ) );

		$nulled = ChangeSet::build( self::inputs() + [ 'extensions' => [ 'example_origin' => null ] ], $declared );
		self::assertArrayHasKey( 'example_origin', $nulled );
		self::assertNull( $nulled['example_origin'] );
		self::assertSame( [], ChangeSet::validate( $nulled, $declared ) );

		$malformed = $with;
		$malformed['example_origin'] = [ 'post_id' => 0, 'locator' => 'x' ];
		self::assertNotSame( [], ChangeSet::validate( $malformed, $declared ) );
		self::assertNotSame( [], self::change_set_schema_errors( $malformed, [ 'example_origin' => $declared['example_origin']['schema'] ] ) );

		// Version 1 itself declares no extension: the same field is rejected without the declaration.
		self::assertNotSame( [], ChangeSet::validate( $with ) );
		self::assertNotSame( [], self::change_set_schema_errors( $with ) );
	}

	public function test_a_non_nullable_extension_rejects_null(): void {
		$declared = [ 'example_flag' => [ 'nullable' => false, 'schema' => [ 'type' => 'boolean' ] ] ];
		$built    = ChangeSet::build( self::inputs() + [ 'extensions' => [ 'example_flag' => null ] ], $declared );

		self::assertArrayNotHasKey( 'example_flag', $built );
		$document                 = ChangeSet::build( self::inputs(), $declared );
		$document['example_flag'] = null;
		self::assertNotSame( [], ChangeSet::validate( $document, $declared ) );
	}

	public function test_ability_declarations_expose_the_lineage_inputs_and_the_output_property(): void {
		$inputs = ChangeSet::input_properties();
		self::assertSame( [ 'repair_of', 'supersedes' ], array_keys( $inputs ) );
		foreach ( $inputs as $property ) {
			self::assertSame( 'string', $property['type'] );
			self::assertSame( 96, $property['maxLength'] );
			self::assertNotSame( '', $property['description'] );
		}

		$output = ChangeSet::output_property();
		self::assertSame( 'object', $output['type'] );
		self::assertSame( ChangeSet::REQUIRED_FIELDS, $output['required'] );
		self::assertStringContainsString( 'change-set-v1.schema.json', $output['description'] );
	}

	public function test_lineage_args_read_repair_of_and_supersedes_from_ability_input(): void {
		self::assertSame( [ 'repair_of' => 'cs-a', 'supersedes' => null ], ChangeSet::lineage_args( [ 'repair_of' => ' cs-a ' ] ) );
		self::assertSame( [ 'repair_of' => null, 'supersedes' => 'cs-b' ], ChangeSet::lineage_args( [ 'supersedes' => 'cs-b' ] ) );
		self::assertSame( [ 'repair_of' => null, 'supersedes' => null ], ChangeSet::lineage_args( [ 'repair_of' => [ 'x' ], 'supersedes' => 7 ] ) );
		self::assertSame( [], ChangeSet::lineage_input( [ 'repair_of' => '', 'post_id' => 1 ] ) );
		self::assertSame( [ 'repair_of' => 'cs-a' ], ChangeSet::lineage_input( [ 'repair_of' => 'cs-a', 'supersedes' => '' ] ) );
	}

	public function test_audit_metadata_projects_identity_lineage_and_missing_hashes(): void {
		$change_set = ChangeSet::build( self::inputs() + [ 'repair_of' => 'cs-failed', 'supersedes' => 'cs-old' ] );
		$metadata   = ChangeSet::audit_metadata( $change_set );

		self::assertSame( 'cs-0123456789abcdef01234567', $metadata['change_set_id'] );
		self::assertSame( 'cs-failed', $metadata['repair_of'] );
		self::assertSame( 'cs-old', $metadata['supersedes'] );
		self::assertSame( self::HASH_A, $metadata['before_sha256'] );
		self::assertSame( self::HASH_B, $metadata['after_sha256'] );

		$plain = ChangeSet::audit_metadata( ChangeSet::build( [ 'change_set_id' => 'cs-plain' ] ) );
		self::assertSame( [ 'change_set_id' => 'cs-plain' ], $plain );
	}
}
