<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\SkillLibrary\Site;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\SkillLibrary\Site\SkillTables;

/**
 * @covers \Stonewright\WpMcp\SkillLibrary\Site\SkillTables
 */
final class SkillTablesTest extends TestCase {

	private mixed $original_wpdb;

	private SkillTablesDouble $tables;

	protected function setUp(): void {
		$this->original_wpdb                          = $GLOBALS['wpdb'] ?? null;
		$this->tables                                 = new SkillTablesDouble();
		$GLOBALS['wpdb']                              = $this->tables;
		$GLOBALS['stonewright_test_options']          = [];
		$GLOBALS['stonewright_test_dbdelta_queries'] = [];
	}

	protected function tearDown(): void {
		$GLOBALS['wpdb']                              = $this->original_wpdb;
		$GLOBALS['stonewright_test_options']          = [];
		$GLOBALS['stonewright_test_dbdelta_queries'] = [];
	}

	public function test_install_creates_both_tables_and_records_schema_versions(): void {
		SkillTables::install();

		$queries = $GLOBALS['stonewright_test_dbdelta_queries'];
		self::assertCount( 2, $queries );
		self::assertStringContainsString( 'CREATE TABLE wp_stonewright_skills (', $queries[0] );
		self::assertStringContainsString( 'CREATE TABLE wp_stonewright_skill_versions (', $queries[1] );
		self::assertSame( '1.3', get_option( 'stonewright_skills_db_version' ) );
		self::assertSame( '1.0', get_option( 'stonewright_skill_versions_db_version' ) );
	}

	public function test_skills_definition_matches_the_existing_table(): void {
		$sql = SkillTables::skills_definition();

		foreach (
			[
				'id bigint(20) unsigned NOT NULL AUTO_INCREMENT',
				'slug varchar(191) NOT NULL',
				"title varchar(255) NOT NULL DEFAULT ''",
				'description text NOT NULL',
				'content mediumtext NOT NULL',
				'enabled tinyint(1) NOT NULL DEFAULT 1',
				'enable_agentic tinyint(1) NOT NULL DEFAULT 1',
				'enable_prompt tinyint(1) NOT NULL DEFAULT 1',
				"source varchar(20) NOT NULL DEFAULT 'user'",
				"status varchar(20) NOT NULL DEFAULT 'active'",
				"topic varchar(191) NOT NULL DEFAULT ''",
				"semantic_fingerprint char(64) NOT NULL DEFAULT ''",
				'version_constraints_json text NOT NULL',
				'verification_count int(10) unsigned NOT NULL DEFAULT 0',
				'revision int(10) unsigned NOT NULL DEFAULT 1',
				'conflict_json text NOT NULL',
				'trashed_at datetime DEFAULT NULL',
				'created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP',
				'updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
				'PRIMARY KEY  (id)',
				'UNIQUE KEY slug (slug)',
				'KEY topic_status (topic,status)',
				'KEY trashed_at (trashed_at)',
				'KEY semantic_fingerprint (semantic_fingerprint)',
			] as $fragment
		) {
			self::assertStringContainsString( $fragment, $sql );
		}

		$versions = SkillTables::versions_definition();
		foreach (
			[
				'skill_id bigint(20) unsigned NOT NULL',
				'revision int(10) unsigned NOT NULL',
				'snapshot_json longtext NOT NULL',
				'created_by bigint(20) unsigned NOT NULL DEFAULT 0',
				'UNIQUE KEY skill_revision (skill_id,revision)',
				'KEY skill_id (skill_id)',
			] as $fragment
		) {
			self::assertStringContainsString( $fragment, $versions );
		}
	}

	public function test_ensure_runs_only_when_a_recorded_version_differs(): void {
		update_option( 'stonewright_skills_db_version', '1.3' );
		update_option( 'stonewright_skill_versions_db_version', '1.0' );

		SkillTables::ensure();
		self::assertSame( [], $GLOBALS['stonewright_test_dbdelta_queries'] );

		update_option( 'stonewright_skill_versions_db_version', '0.9' );
		SkillTables::ensure();
		self::assertCount( 2, $GLOBALS['stonewright_test_dbdelta_queries'] );
		self::assertSame( '1.0', get_option( 'stonewright_skill_versions_db_version' ) );
	}

	public function test_upgrade_from_an_older_schema_keeps_rows_and_fills_new_json_columns(): void {
		update_option( 'stonewright_skills_db_version', '1.2' );
		$this->tables->seed_skill( [ 'slug' => 'kept', 'content' => '# Kept', 'version_constraints_json' => '', 'conflict_json' => '' ] );
		$this->tables->seed_skill( [ 'slug' => 'shaped', 'content' => '# Shaped', 'version_constraints_json' => '{"acf":"required"}' ] );

		SkillTables::install();

		$kept = $this->tables->skill_by_slug( 'kept' );
		self::assertIsArray( $kept );
		self::assertSame( '# Kept', $kept['content'] );
		self::assertSame( '[]', $kept['version_constraints_json'] );
		self::assertSame( '[]', $kept['conflict_json'] );
		self::assertSame( '{"acf":"required"}', $this->tables->skill_by_slug( 'shaped' )['version_constraints_json'] ?? null );
		self::assertSame( '1.3', get_option( 'stonewright_skills_db_version' ) );
	}

	public function test_a_fresh_install_does_not_rewrite_rows(): void {
		SkillTables::install();

		self::assertSame( [], array_filter( $this->tables->statements, static fn( string $statement ): bool => str_contains( $statement, 'UPDATE' ) ) );
		self::assertSame( '1.3', get_option( 'stonewright_skills_db_version' ) );
	}
}
