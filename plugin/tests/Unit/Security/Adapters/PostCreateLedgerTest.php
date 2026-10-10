<?php
/**
 * Abilities that create posts, or overwrite them without a snapshot, are recorded so they can be undone.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Security\Adapters;

use Stonewright\WpMcp\Abilities\Content\BulkCreate;
use Stonewright\WpMcp\Abilities\Content\BulkUpsertPosts;
use Stonewright\WpMcp\Abilities\Content\CreatePage;
use Stonewright\WpMcp\Abilities\Content\CreatePost;
use Stonewright\WpMcp\Abilities\Content\DuplicatePage;
use Stonewright\WpMcp\Abilities\Content\UpdatePage;
use Stonewright\WpMcp\Abilities\FSE\Navigation;
use Stonewright\WpMcp\Abilities\Patterns\CreatePattern;
use Stonewright\WpMcp\FSE\TemplateStore;
use Stonewright\WpMcp\Security\RescueGuard;
use Stonewright\WpMcp\Security\Adapters\PostAdapter;
use Stonewright\WpMcp\Security\ChangeLedger;

/**
 * @covers \Stonewright\WpMcp\Abilities\Content\CreatePage
 * @covers \Stonewright\WpMcp\Abilities\Content\CreatePost
 * @covers \Stonewright\WpMcp\Abilities\Content\BulkCreate
 * @covers \Stonewright\WpMcp\Abilities\Content\DuplicatePage
 * @covers \Stonewright\WpMcp\Abilities\Content\BulkUpsertPosts
 * @covers \Stonewright\WpMcp\Abilities\Content\UpdatePage
 * @covers \Stonewright\WpMcp\Security\Adapters\PostAdapter
 */
final class PostCreateLedgerTest extends PostLedgerTestCase {

	protected function setUp(): void {
		parent::setUp();
		\Stonewright\WpMcp\CustomCode\ProviderRegistry::reset_for_tests();
		$GLOBALS['stonewright_test_post_types'] = [];
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_post_types'] = [];
		\Stonewright\WpMcp\CustomCode\ProviderRegistry::reset_for_tests();
		parent::tearDown();
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private static function assert_create_row( array $row, string $ability, int $post_id ): void {
		self::assertSame( $ability, $row['ability'] );
		self::assertSame( 'post', $row['resource_type'] );
		self::assertSame( (string) $post_id, $row['resource_id'] );
		self::assertSame( 'verified', $row['status'] );
		self::assertTrue( $row['restorable'], 'A created post is undone by moving it to the trash.' );
		self::assertSame( '', $row['restorable_reason'] );
		self::assertSame( '', $row['before_ref'], 'There is no before image of a post that did not exist.' );
		self::assertNotSame( '', $row['after_ref'] );
		self::assertTrue( PostAdapter::is_created_row( $row ) );
	}

	public function test_create_page_records_a_create_with_a_trash_undo(): void {
		$result = ( new CreatePage() )->execute( [ 'title' => 'New page', 'content' => 'Body', 'slug' => 'new-page' ] );

		self::assertIsArray( $result );
		$id = $result['id'];
		self::assert_create_row( $this->only_row(), 'stonewright/content-create-page', $id );
		$after = ChangeLedger::read_image( $this->only_row()['change_id'], 'after' );
		self::assertSame( 'New page', $after['post']['post_title'] );
		self::assertSame( 'new-page', $after['post']['post_name'] );

		$undo = PostAdapter::undo( $this->only_row()['change_id'] );

		self::assertIsArray( $undo );
		self::assertTrue( $undo['ok'] );
		self::assertSame( 'trash', $GLOBALS['stonewright_test_posts'][ $id ]->post_status );
	}

	public function test_create_post_records_a_create(): void {
		$result = ( new CreatePost() )->execute( [ 'title' => 'New post', 'content' => 'Body' ] );

		self::assertIsArray( $result );
		self::assert_create_row( $this->only_row(), 'stonewright/content-create-post', $result['id'] );
	}

	public function test_bulk_create_records_every_post_it_created_and_not_the_one_that_failed(): void {
		$GLOBALS['stonewright_test_wp_insert_post_return'] = new \WP_Error( 'insert_failed', 'No.' );

		$result = ( new BulkCreate() )->execute( [ 'items' => [ [ 'title' => 'Refused' ], [ 'title' => 'First' ], [ 'title' => 'Second' ] ] ] );

		self::assertIsArray( $result );
		self::assertCount( 2, $result['created'] );
		self::assertCount( 1, $result['errors'] );
		$rows = $this->ledger_rows();
		self::assertCount( 2, $rows );
		foreach ( $rows as $index => $row ) {
			self::assert_create_row( $row, 'stonewright/content-bulk-create', $result['created'][ $index ] );
		}
	}

	public function test_duplicate_page_records_the_copy_as_a_create(): void {
		$this->make_post( 60, [ 'post_title' => 'Source', 'meta' => [ 'seo' => 'x' ] ] );

		$result = ( new DuplicatePage() )->execute( [ 'id' => 60 ] );

		self::assertIsArray( $result );
		self::assert_create_row( $this->only_row(), 'stonewright/content-duplicate-page', $result['new_id'] );
		self::assertSame( 'publish', $GLOBALS['stonewright_test_posts'][60]->post_status, 'The source is not part of the change.' );
	}

	public function test_creates_of_patterns_navigation_and_templates_are_creates_and_not_changes_of_a_fresh_post(): void {
		$pattern    = ( new CreatePattern() )->execute( [ 'title' => 'Hero', 'content' => '<!-- wp:paragraph --><p>Hello</p><!-- /wp:paragraph -->', 'slug' => 'hero' ] );
		$navigation = ( new Navigation() )->execute( [ 'action' => 'create', 'title' => 'Primary', 'content' => '<!-- wp:navigation-link {"label":"Home","url":"https://example.test/"} /-->' ] );
		RescueGuard::enter( 'stonewright/fse-write-template' );
		$template = TemplateStore::insert( 'wp_template', 'home', 'synthetic-theme', [ 'post_content' => '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->' ] );
		RescueGuard::leave( [ 'ok' => true ] );

		$rows = array_column( $this->ledger_rows(), null, 'resource_id' );
		self::assertCount( 3, $rows );
		self::assert_create_row( $rows[ (string) $pattern['id'] ], 'stonewright/patterns-create', $pattern['id'] );
		self::assertSame( 'gutenberg', $rows[ (string) $pattern['id'] ]['family'] );
		self::assertTrue( PostAdapter::is_created_row( $rows[ (string) ( $navigation['id'] ?? $navigation['navigation_id'] ?? 0 ) ] ?? [] ) );
		self::assert_create_row( $rows[ (string) $template ], 'stonewright/fse-write-template', $template );
		self::assertSame( 'fse', $rows[ (string) $template ]['family'] );
	}

	public function test_a_create_that_failed_records_nothing(): void {
		$GLOBALS['stonewright_test_wp_insert_post_return'] = new \WP_Error( 'insert_failed', 'No.' );

		$result = ( new CreatePage() )->execute( [ 'title' => 'New page' ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( [], $this->ledger_rows() );
	}

	public function test_bulk_upsert_records_the_before_image_of_every_post_it_overwrites_and_a_create_for_new_ones(): void {
		$GLOBALS['stonewright_test_post_types']['homepage_section'] = (object) [ 'name' => 'homepage_section' ];
		$this->make_post( 42, [ 'post_type' => 'homepage_section', 'post_name' => 'hero', 'post_title' => 'Hero', 'post_status' => 'draft', 'post_content' => 'Old hero', 'menu_order' => 1 ] );
		$this->set_meta( 42, 'section_slug', 'old-hero' );

		$result = ( new BulkUpsertPosts() )->execute(
			[
				'post_type' => 'homepage_section',
				'items'     => [
					[ 'slug' => 'hero', 'title' => 'Hero Updated', 'status' => 'publish', 'content' => 'New hero', 'menu_order' => 9, 'meta' => [ 'section_slug' => 'new-hero', 'section_order' => 4 ] ],
					[ 'slug' => 'proof', 'title' => 'Proof', 'status' => 'publish' ],
				],
			]
		);
		self::assertIsArray( $result );

		self::assertSame( 1, $result['created'] );
		self::assertSame( 1, $result['updated'] );
		$rows = array_column( $this->ledger_rows(), null, 'resource_id' );
		self::assertCount( 2, $rows );

		$existing = $rows['42'];
		self::assertSame( 'stonewright/content-bulk-upsert-posts', $existing['ability'] );
		self::assertSame( 'verified', $existing['status'] );
		self::assertTrue( $existing['restorable'] );
		self::assertFalse( PostAdapter::is_created_row( $existing ) );
		$before = ChangeLedger::read_image( $existing['change_id'], 'before' );
		self::assertSame( 'Hero', $before['post']['post_title'] );
		self::assertSame( 'draft', $before['post']['post_status'] );
		self::assertSame( 'Old hero', $before['post']['post_content'] );
		self::assertSame( 1, $before['post']['menu_order'] );
		self::assertSame( 'old-hero', $before['meta']['section_slug'], 'A meta key the item writes is part of the image.' );
		self::assertContains( 'section_order', $before['meta_absent'] );
		$after = ChangeLedger::read_image( $existing['change_id'], 'after' );
		self::assertSame( 'Hero Updated', $after['post']['post_title'] );
		self::assertSame( 'new-hero', $after['meta']['section_slug'] );
		self::assertSame( 4, $after['meta']['section_order'] );

		$new_id = $result['items'][1]['id'];
		self::assert_create_row( $rows[ (string) $new_id ], 'stonewright/content-bulk-upsert-posts', $new_id );

		$undo = PostAdapter::undo( $existing['change_id'] );
		self::assertTrue( $undo['ok'], 'Differences: ' . implode( ',', $undo['differences'] ?? [] ) );
		$post = $GLOBALS['stonewright_test_posts'][42];
		self::assertSame( 'Hero', $post->post_title );
		self::assertSame( 'draft', $post->post_status );
		self::assertSame( 'Old hero', $post->post_content );
		self::assertSame( 1, (int) $post->menu_order );
		self::assertSame( 'old-hero', $this->meta( 42, 'section_slug' ) );
		self::assertNull( $this->meta( 42, 'section_order' ) );
	}

	public function test_bulk_upsert_does_not_snapshot_or_probe_the_site(): void {
		$GLOBALS['stonewright_test_post_types']['homepage_section'] = (object) [ 'name' => 'homepage_section' ];
		$this->make_post( 42, [ 'post_type' => 'homepage_section', 'post_name' => 'hero', 'post_title' => 'Hero' ] );

		( new BulkUpsertPosts() )->execute( [ 'post_type' => 'homepage_section', 'items' => [ [ 'slug' => 'hero', 'title' => 'Hero Updated' ] ] ] );

		self::assertSame( [], \Stonewright\WpMcp\Security\ChangeJournal::recent(), 'The journal and its probes are as before.' );
		self::assertSame( [], \Stonewright\WpMcp\Security\Backup::list_snapshots( 42 ) );
	}

	public function test_update_page_names_the_meta_it_writes_so_that_the_image_covers_it(): void {
		$this->make_post( 70, [ 'post_content' => 'Old' ] );
		$this->set_meta( 70, 'hero_note', 'before' );

		( new UpdatePage() )->execute( [ 'id' => 70, 'content' => 'New', 'meta' => [ 'hero_note' => 'after' ] ] );

		$row    = $this->only_row();
		$before = ChangeLedger::read_image( $row['change_id'], 'before' );
		self::assertSame( 'stonewright/content-update-page', $row['ability'] );
		self::assertSame( 'before', $before['meta']['hero_note'] );
		self::assertSame( 'after', ChangeLedger::read_image( $row['change_id'], 'after' )['meta']['hero_note'] );
	}

	public function test_a_ledger_failure_leaves_every_create_and_upsert_result_as_it_was(): void {
		$GLOBALS['stonewright_test_post_types']['homepage_section'] = (object) [ 'name' => 'homepage_section' ];
		$run = static function (): array {
			$GLOBALS['stonewright_test_next_post_id']   = 2001;
			$GLOBALS['stonewright_test_posts']          = [];
			$GLOBALS['stonewright_test_posts'][42]      = (object) [ 'ID' => 42, 'post_type' => 'homepage_section', 'post_name' => 'hero', 'post_title' => 'Hero', 'post_status' => 'draft', 'post_content' => '', 'post_excerpt' => '', 'post_parent' => 0, 'meta' => [] ];
			return [
				( new CreatePage() )->execute( [ 'title' => 'Page' ] ),
				( new CreatePost() )->execute( [ 'title' => 'Post' ] ),
				( new BulkUpsertPosts() )->execute( [ 'post_type' => 'homepage_section', 'items' => [ [ 'slug' => 'hero', 'title' => 'Hero 2' ], [ 'slug' => 'new', 'title' => 'New' ] ] ] ),
			];
		};
		$expected = $run();
		self::assertNotSame( [], $this->ledger_rows(), 'The ledger records on the first run.' );
		ChangeLedger::purge_all();

		$db = new class() extends PostLedgerWpdb {
			public function insert( $table, $data, $format = null ) {
				if ( str_contains( (string) $table, 'stonewright_changes' ) ) {
					throw new \RuntimeException( 'database gone' );
				}
				return parent::insert( $table, $data, $format );
			}
		};
		$db->unique      = $this->db->unique;
		$GLOBALS['wpdb'] = $db;
		$GLOBALS['stonewright_test_wp_insert_post_calls'] = [];

		self::assertSame( $expected, $run() );
		self::assertSame( 'Hero 2', $GLOBALS['stonewright_test_posts'][42]->post_title );
	}
}
