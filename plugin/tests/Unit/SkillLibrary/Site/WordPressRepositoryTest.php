<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\SkillLibrary\Site;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\SkillLibrary\LifecycleDecisions;
use Stonewright\WpMcp\SkillLibrary\Site\RowFormat;
use Stonewright\WpMcp\SkillLibrary\Site\WordPressRepository;

/**
 * @covers \Stonewright\WpMcp\SkillLibrary\Site\WordPressRepository
 * @covers \Stonewright\WpMcp\SkillLibrary\Site\RowFormat
 */
final class WordPressRepositoryTest extends TestCase {

	private mixed $original_wpdb;

	private SkillTablesDouble $tables;

	private WordPressRepository $repository;

	protected function setUp(): void {
		$this->original_wpdb                         = $GLOBALS['wpdb'] ?? null;
		$this->tables                                = new SkillTablesDouble();
		$GLOBALS['wpdb']                             = $this->tables;
		$GLOBALS['stonewright_test_current_user_id'] = 3;
		$this->repository                            = new WordPressRepository();
	}

	protected function tearDown(): void {
		$GLOBALS['wpdb']                             = $this->original_wpdb;
		$GLOBALS['stonewright_test_current_user_id'] = 0;
	}

	public function test_insert_stores_text_columns_utc_times_and_no_version_row(): void {
		$id = $this->repository->insert_unique( $this->record() );

		self::assertSame( 1, $id );
		$row = $this->tables->skills[1];
		self::assertSame( 'example-skill', $row['slug'] );
		self::assertSame( [ '1', '0', '1' ], [ $row['enabled'], $row['enable_agentic'], $row['enable_prompt'] ] );
		self::assertSame( [ 'user', 'active', '1' ], [ $row['source'], $row['status'], $row['revision'] ] );
		self::assertSame( '[]', $row['version_constraints_json'] );
		self::assertSame( '[]', $row['conflict_json'] );
		self::assertSame( '0', $row['verification_count'] );
		self::assertNull( $row['trashed_at'] );
		self::assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string) $row['created_at'] );
		self::assertSame( $row['created_at'], $row['updated_at'] );
		self::assertSame( [], $this->tables->versions );
		self::assertSame( 1, $this->tables->inserts[0]['data']['enabled'] );
		self::assertSame( 0, $this->tables->inserts[0]['data']['enable_agentic'] );
	}

	public function test_insert_never_replaces_a_taken_slug(): void {
		$this->repository->insert_unique( $this->record() );

		$second = $this->repository->insert_unique( $this->record( [ 'content' => '# Replacement' ] ) );

		self::assertInstanceOf( \WP_Error::class, $second );
		self::assertSame( 'stonewright_skill_slug_taken', $second->get_error_code() );
		self::assertSame( "# Example\n", $this->tables->skills[1]['content'] );
		self::assertCount( 1, $this->tables->skills );
	}

	public function test_reads_return_typed_logical_records(): void {
		$this->tables->seed_skill(
			[
				'id'                       => 9,
				'slug'                     => 'shaped',
				'title'                    => 'Shaped',
				'content'                  => '# Shaped',
				'enabled'                  => 0,
				'version_constraints_json' => '{"any_of":"acf|pods"}',
				'conflict_json'            => '["conflict-a"]',
				'verification_count'       => 2,
				'revision'                 => 4,
			]
		);

		$record = $this->repository->find_slug( 'shaped' );

		self::assertIsArray( $record );
		self::assertSame( 9, $record['id'] );
		self::assertSame( 4, $record['revision'] );
		self::assertSame( 2, $record['verification_count'] );
		self::assertFalse( $record['enabled'] );
		self::assertTrue( $record['enable_agentic'] );
		self::assertSame( [ 'any_of' => 'acf|pods' ], $record['version_constraints'] );
		self::assertSame( [ 'conflict-a' ], $record['conflicts'] );
		self::assertSame( $record, $this->repository->find_id( 9 ) );
		self::assertNull( $this->repository->find_id( 10 ) );
		self::assertCount( 1, $this->repository->all_records() );
	}

	public function test_content_update_bumps_revision_and_archives_the_prior_row_as_text(): void {
		$this->repository->insert_unique( $this->record( [ 'version_constraints' => [ 'elementor' => 'required' ] ] ) );
		$previous = $this->repository->find_id( 1 );
		self::assertIsArray( $previous );

		$result = $this->repository->exchange_record( 1, array_replace( $previous, [ 'content' => "# Second\n", 'revision' => 2 ] ), 1 );

		self::assertTrue( $result );
		self::assertSame( '2', $this->tables->skills[1]['revision'] );
		self::assertSame( "# Second\n", $this->tables->skills[1]['content'] );
		self::assertCount( 1, $this->tables->versions );
		$version = array_values( $this->tables->versions )[0];
		self::assertSame( [ '1', '1', '3' ], [ $version['skill_id'], $version['revision'], $version['created_by'] ] );
		$snapshot = json_decode( (string) $version['snapshot_json'], true );
		self::assertIsArray( $snapshot );
		self::assertSame(
			[ 'id', 'slug', 'title', 'description', 'content', 'enabled', 'enable_agentic', 'enable_prompt', 'source', 'status', 'topic', 'semantic_fingerprint', 'version_constraints_json', 'verification_count', 'revision', 'conflict_json', 'trashed_at', 'created_at', 'updated_at', 'version_constraints', 'conflicts' ],
			array_keys( $snapshot )
		);
		foreach ( RowFormat::COLUMNS as $column ) {
			if ( 'trashed_at' === $column ) {
				self::assertNull( $snapshot[ $column ] );
				continue;
			}
			self::assertIsString( $snapshot[ $column ], $column );
		}
		self::assertSame( "# Example\n", $snapshot['content'] );
		self::assertSame( '1', $snapshot['revision'] );
		self::assertSame( '{"elementor":"required"}', $snapshot['version_constraints_json'] );
		self::assertSame( [ 'elementor' => 'required' ], $snapshot['version_constraints'] );
		self::assertSame( [], $snapshot['conflicts'] );
	}

	public function test_enable_and_disable_change_flags_only(): void {
		$this->repository->insert_unique( $this->record() );
		$previous = $this->repository->find_id( 1 );
		self::assertIsArray( $previous );

		self::assertTrue( $this->repository->exchange_record( 1, array_replace( $previous, [ 'enabled' => false, 'revision' => 2 ] ), 1 ) );

		self::assertSame( '0', $this->tables->skills[1]['enabled'] );
		self::assertSame( '1', $this->tables->skills[1]['revision'] );
		self::assertSame( [], $this->tables->versions );
	}

	public function test_trash_sets_trashed_at_and_clears_every_flag_without_a_revision(): void {
		$this->repository->insert_unique( $this->record() );
		$previous = $this->repository->find_id( 1 );
		self::assertIsArray( $previous );
		$trashed = LifecycleDecisions::trash( $previous );
		self::assertIsArray( $trashed );

		self::assertTrue( $this->repository->exchange_record( 1, $trashed, 1 ) );

		$row = $this->tables->skills[1];
		self::assertSame( 'trashed', $row['status'] );
		self::assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string) $row['trashed_at'] );
		self::assertSame( [ '0', '0', '0' ], [ $row['enabled'], $row['enable_agentic'], $row['enable_prompt'] ] );
		self::assertSame( '1', $row['revision'] );
		self::assertSame( [], $this->tables->versions );

		$current = $this->repository->find_id( 1 );
		self::assertIsArray( $current );
		$restored = LifecycleDecisions::restore( $current );
		self::assertIsArray( $restored );
		self::assertTrue( $this->repository->exchange_record( 1, $restored, 1 ) );

		$row = $this->tables->skills[1];
		self::assertSame( 'draft', $row['status'] );
		self::assertNull( $row['trashed_at'] );
		self::assertSame( [ '0', '0', '0' ], [ $row['enabled'], $row['enable_agentic'], $row['enable_prompt'] ] );
		self::assertSame( '1', $row['revision'] );
		self::assertSame( [], $this->tables->versions );
	}

	public function test_exposure_preference_change_is_a_new_revision(): void {
		$this->repository->insert_unique( $this->record() );
		$previous = $this->repository->find_id( 1 );
		self::assertIsArray( $previous );

		self::assertTrue( $this->repository->exchange_record( 1, array_replace( $previous, [ 'enable_agentic' => true ] ), 1 ) );

		self::assertSame( '2', $this->tables->skills[1]['revision'] );
		self::assertCount( 1, $this->tables->versions );
	}

	public function test_an_unchanged_record_writes_nothing(): void {
		$this->repository->insert_unique( $this->record() );
		$previous = $this->repository->find_id( 1 );
		self::assertIsArray( $previous );
		$before = $this->tables->skills;

		self::assertTrue( $this->repository->exchange_record( 1, $previous, 1 ) );

		self::assertSame( $before, $this->tables->skills );
		self::assertSame( [], $this->tables->versions );
	}

	public function test_a_stale_expected_revision_changes_nothing(): void {
		$this->repository->insert_unique( $this->record() );
		$previous = $this->repository->find_id( 1 );
		self::assertIsArray( $previous );
		$before = $this->tables->skills;

		$result = $this->repository->exchange_record( 1, array_replace( $previous, [ 'content' => '# Late' ] ), 7 );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_skill_write_conflict', $result->get_error_code() );
		self::assertSame( $before, $this->tables->skills );
		self::assertSame( [], $this->tables->versions );
	}

	public function test_a_concurrent_change_between_read_and_write_is_refused_and_rolled_back(): void {
		$this->repository->insert_unique( $this->record() );
		$previous = $this->repository->find_id( 1 );
		self::assertIsArray( $previous );
		$this->tables->before_update = static function ( SkillTablesDouble $tables ): void {
			$tables->skills[1]['enabled'] = '0';
		};

		$result = $this->repository->exchange_record( 1, array_replace( $previous, [ 'content' => '# Racing' ] ), 1 );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_skill_write_conflict', $result->get_error_code() );
		self::assertSame( "# Example\n", $this->tables->skills[1]['content'] );
		self::assertSame( '1', $this->tables->skills[1]['revision'] );
		self::assertSame( [], $this->tables->versions );
		self::assertContains( 'ROLLBACK', $this->tables->statements );
	}

	public function test_a_failed_version_row_leaves_the_record_unchanged(): void {
		$this->repository->insert_unique( $this->record() );
		$previous = $this->repository->find_id( 1 );
		self::assertIsArray( $previous );
		$this->tables->fail_version_insert = true;

		$result = $this->repository->exchange_record( 1, array_replace( $previous, [ 'content' => '# Never' ] ), 1 );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( "# Example\n", $this->tables->skills[1]['content'] );
		self::assertSame( '1', $this->tables->skills[1]['revision'] );
	}

	public function test_a_failed_row_update_removes_the_version_row_it_wrote(): void {
		$this->repository->insert_unique( $this->record() );
		$previous = $this->repository->find_id( 1 );
		self::assertIsArray( $previous );
		$this->tables->fail_skill_update = true;

		$result = $this->repository->exchange_record( 1, array_replace( $previous, [ 'content' => '# Never' ] ), 1 );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( [], $this->tables->versions );
		self::assertSame( "# Example\n", $this->tables->skills[1]['content'] );
	}

	public function test_storage_limits_are_refused_before_any_write(): void {
		$result = $this->repository->insert_unique( $this->record( [ 'title' => str_repeat( 'x', 256 ) ] ) );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_skill_record_invalid', $result->get_error_code() );
		self::assertSame( [], $this->tables->skills );
	}

	public function test_remove_erases_a_current_trashed_row_and_its_versions_only(): void {
		$this->repository->insert_unique( $this->record() );
		$this->repository->insert_unique( $this->record( [ 'slug' => 'other-skill' ] ) );
		$previous = $this->repository->find_id( 1 );
		self::assertIsArray( $previous );
		$this->repository->exchange_record( 1, array_replace( $previous, [ 'content' => '# Two' ] ), 1 );
		$this->tables->seed_version( [ 'skill_id' => 2, 'revision' => 1, 'snapshot_json' => '{}', 'created_by' => 0, 'created_at' => '2026-10-01 00:00:00' ] );

		$active = $this->repository->remove_record( 1, 2 );
		self::assertInstanceOf( \WP_Error::class, $active, 'Only a trashed skill can be erased.' );

		$current = $this->repository->find_id( 1 );
		self::assertIsArray( $current );
		$trashed = LifecycleDecisions::trash( $current );
		self::assertIsArray( $trashed );
		$this->repository->exchange_record( 1, $trashed, 2 );

		self::assertInstanceOf( \WP_Error::class, $this->repository->remove_record( 1, 1 ) );
		self::assertTrue( $this->repository->remove_record( 1, 2 ) );

		self::assertArrayNotHasKey( 1, $this->tables->skills );
		self::assertArrayHasKey( 2, $this->tables->skills );
		self::assertSame( [ '2' ], array_values( array_column( $this->tables->versions, 'skill_id' ) ) );
	}

	public function test_snapshots_written_by_earlier_releases_are_readable(): void {
		$this->tables->seed_skill( [ 'id' => 39, 'slug' => 'compat-note', 'title' => 'Note', 'content' => '# Now', 'revision' => 3 ] );
		$earlier = array_map( static fn( mixed $value ): ?string => null === $value ? null : (string) $value, SkillTablesDouble::defaults() );
		$this->tables->seed_version(
			[
				'skill_id'      => 39,
				'revision'      => 2,
				'snapshot_json' => (string) wp_json_encode(
					array_replace(
						$earlier,
						[
							'id'                       => '39',
							'slug'                     => 'compat-note',
							'title'                    => 'Note',
							'content'                  => '# Then',
							'revision'                 => '2',
							'version_constraints_json' => '{"elementor":">=3.16"}',
							'conflict_json'            => '[]',
							'trashed_at'               => null,
							'version_constraints'      => '{"elementor":">=3.16"}',
							'conflicts'                => '[]',
						]
					)
				),
				'created_by'    => 1,
				'created_at'    => '2026-10-06 08:30:45',
			]
		);
		$this->tables->seed_version( [ 'skill_id' => 39, 'revision' => 1, 'snapshot_json' => 'not json', 'created_by' => 1, 'created_at' => '2026-10-06 08:30:44' ] );

		$snapshot = $this->repository->read_snapshot( 'compat-note', 2 );

		self::assertIsArray( $snapshot );
		self::assertSame( 39, $snapshot['id'] );
		self::assertSame( 2, $snapshot['revision'] );
		self::assertSame( '# Then', $snapshot['content'] );
		self::assertSame( [ 'elementor' => '>=3.16' ], $snapshot['version_constraints'] );
		self::assertTrue( $snapshot['enabled'] );
		self::assertCount( 1, $this->repository->snapshots( 'compat-note' ), 'An unreadable snapshot is skipped.' );
		self::assertNull( $this->repository->read_snapshot( 'compat-note', 1 ) );
		self::assertSame( [], $this->repository->snapshots( 'missing' ) );
	}

	public function test_constraint_shapes_are_stored_as_written_by_earlier_releases(): void {
		$shapes = [
			'none'    => [ [], '[]' ],
			'map'     => [ [ 'elementor' => 'required' ], '{"elementor":"required"}' ],
			'any_of'  => [ [ 'any_of' => 'acf|pods' ], '{"any_of":"acf|pods"}' ],
			'version' => [ [ 'elementor' => '>=3.31' ], '{"elementor":">=3.31"}' ],
		];
		foreach ( $shapes as $name => [ $constraints, $stored ] ) {
			$id = $this->repository->insert_unique( $this->record( [ 'slug' => 'shape-' . $name, 'version_constraints' => $constraints ] ) );
			self::assertIsInt( $id );
			self::assertSame( $stored, $this->tables->skills[ $id ]['version_constraints_json'], $name );
			$record = $this->repository->find_id( $id );
			self::assertIsArray( $record );
			self::assertSame( $constraints, $record['version_constraints'], $name );
		}
	}

	public function test_exposed_rows_carry_every_column_as_text_plus_decoded_fields(): void {
		$this->repository->insert_unique( $this->record( [ 'version_constraints' => [ 'acf' => 'required' ] ] ) );
		$record = $this->repository->find_id( 1 );
		self::assertIsArray( $record );

		$row = RowFormat::exposed( $record );

		self::assertSame( '1', $row['id'] );
		self::assertSame( '1', $row['enabled'] );
		self::assertSame( '0', $row['enable_agentic'] );
		self::assertSame( '1', $row['revision'] );
		self::assertSame( '{"acf":"required"}', $row['version_constraints_json'] );
		self::assertSame( [ 'acf' => 'required' ], $row['version_constraints'] );
		self::assertSame( [], $row['conflicts'] );
		self::assertSame( $record, RowFormat::logical( $row ) );
	}

	/** @param array<string, mixed> $changes @return array<string, mixed> */
	private function record( array $changes = [] ): array {
		return array_replace(
			[
				'slug'           => 'example-skill',
				'title'          => 'Example skill',
				'description'    => 'Use when testing the skill tables.',
				'content'        => "# Example\n",
				'enabled'        => true,
				'enable_agentic' => false,
				'enable_prompt'  => true,
				'source'         => 'user',
				'status'         => 'active',
				'revision'       => 1,
			],
			$changes
		);
	}
}
