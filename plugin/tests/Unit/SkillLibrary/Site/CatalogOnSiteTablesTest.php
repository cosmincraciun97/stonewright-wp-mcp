<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\SkillLibrary\Site;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\SkillLibrary\MutationBoundary;
use Stonewright\WpMcp\SkillLibrary\SkillCatalog;
use Stonewright\WpMcp\SkillLibrary\Site\WordPressRepository;

/**
 * The observed lifecycle of a site skill, end to end through the catalog and
 * the table adapter.
 *
 * @covers \Stonewright\WpMcp\SkillLibrary\Site\WordPressRepository
 */
final class CatalogOnSiteTablesTest extends TestCase {

	private mixed $original_wpdb;

	private SkillTablesDouble $tables;

	private SkillCatalog $catalog;

	protected function setUp(): void {
		$this->original_wpdb                         = $GLOBALS['wpdb'] ?? null;
		$this->tables                                = new SkillTablesDouble();
		$GLOBALS['wpdb']                             = $this->tables;
		$GLOBALS['stonewright_test_current_user_id'] = 1;
		$this->catalog                               = new SkillCatalog(
			new WordPressRepository(),
			new class() implements MutationBoundary {
				public function authorize( string $action, array $summary, string $token ): bool|\WP_Error {
					return true;
				}

				public function record( string $action, array $summary, string $outcome ): void {
				}
			},
			static fn( array $constraints ): bool => true
		);
	}

	protected function tearDown(): void {
		$GLOBALS['wpdb']                             = $this->original_wpdb;
		$GLOBALS['stonewright_test_current_user_id'] = 0;
	}

	public function test_create_update_toggle_trash_restore_and_rollback_keep_the_observed_shapes(): void {
		$id = $this->catalog->store( $this->input( '# First body' ) );
		self::assertSame( 1, $id );
		$row = $this->tables->skills[1];
		self::assertSame( [ 'user', 'active', '1' ], [ $row['source'], $row['status'], $row['revision'] ] );
		self::assertSame( [ '1', '1', '1' ], [ $row['enabled'], $row['enable_agentic'], $row['enable_prompt'] ] );
		self::assertSame( '', $row['topic'] );
		self::assertNull( $row['trashed_at'] );
		self::assertSame( [], $this->tables->versions );

		self::assertSame( 1, $this->catalog->store( [ 'slug' => 'site-note', 'title' => 'Site note', 'content' => '# Second body' ] ) );
		self::assertSame( 1, $this->catalog->store( [ 'slug' => 'site-note', 'title' => 'Site note', 'content' => '# Third body' ] ) );
		self::assertSame( '3', $this->tables->skills[1]['revision'] );
		$versions = array_values( $this->tables->versions );
		self::assertSame( [ '1', '2' ], array_column( $versions, 'revision' ) );
		self::assertSame( [ '1', '1' ], array_column( $versions, 'created_by' ) );
		self::assertSame( '# First body', json_decode( (string) $versions[0]['snapshot_json'], true )['content'] );
		self::assertSame( '# Second body', json_decode( (string) $versions[1]['snapshot_json'], true )['content'] );

		self::assertTrue( $this->catalog->set_exposure( 1, false ) );
		self::assertSame( [ '0', '3' ], [ $this->tables->skills[1]['enabled'], $this->tables->skills[1]['revision'] ] );
		self::assertTrue( $this->catalog->set_exposure( 1, true ) );
		self::assertSame( [ '1', '3' ], [ $this->tables->skills[1]['enabled'], $this->tables->skills[1]['revision'] ] );
		self::assertCount( 2, $this->tables->versions );

		self::assertTrue( $this->catalog->put_in_trash( 1 ) );
		$row = $this->tables->skills[1];
		self::assertSame( 'trashed', $row['status'] );
		self::assertNotNull( $row['trashed_at'] );
		self::assertSame( [ '0', '0', '0', '3' ], [ $row['enabled'], $row['enable_agentic'], $row['enable_prompt'], $row['revision'] ] );
		self::assertNull( $this->catalog->lookup( 'site-note' ) );

		self::assertTrue( $this->catalog->recover( 1 ) );
		$row = $this->tables->skills[1];
		self::assertSame( [ 'draft', null ], [ $row['status'], $row['trashed_at'] ] );
		self::assertSame( [ '0', '0', '0', '3' ], [ $row['enabled'], $row['enable_agentic'], $row['enable_prompt'], $row['revision'] ] );
		self::assertCount( 2, $this->tables->versions );

		self::assertTrue( $this->catalog->restore_revision( 'site-note', 1 ) );
		$row = $this->tables->skills[1];
		self::assertSame( '# First body', $row['content'] );
		self::assertSame( '4', $row['revision'] );
		self::assertCount( 3, $this->tables->versions );
	}

	public function test_concurrent_saves_of_one_revision_cannot_both_win(): void {
		$this->catalog->store( $this->input( '# Base' ) );
		$this->tables->before_update = function ( SkillTablesDouble $tables ): void {
			$tables->skills[1]['content']  = '# Someone else';
			$tables->skills[1]['revision'] = '2';
		};

		$result = $this->catalog->store( [ 'slug' => 'site-note', 'title' => 'Site note', 'content' => '# Mine' ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_skill_write_conflict', $result->get_error_code() );
		self::assertSame( [], $this->tables->versions );
	}

	/** @return array<string, mixed> */
	private function input( string $content ): array {
		return [
			'slug'           => 'site-note',
			'title'          => 'Site note',
			'description'    => 'Use when testing the site lifecycle.',
			'content'        => $content,
			'enabled'        => true,
			'enable_agentic' => true,
			'enable_prompt'  => true,
			'source'         => 'user',
		];
	}
}
