<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Patterns;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\Patterns\CreatePattern;
use Stonewright\WpMcp\Abilities\Patterns\DeletePattern;
use Stonewright\WpMcp\Abilities\Patterns\PatternCategories;
use Stonewright\WpMcp\Abilities\Patterns\UpdatePattern;
use Stonewright\WpMcp\Security\ConfirmationToken;

/**
 * @covers \Stonewright\WpMcp\Abilities\Patterns\CreatePattern
 * @covers \Stonewright\WpMcp\Abilities\Patterns\UpdatePattern
 * @covers \Stonewright\WpMcp\Abilities\Patterns\DeletePattern
 * @covers \Stonewright\WpMcp\Abilities\Patterns\PatternCategories
 */
final class PatternLifecycleTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_user_caps'] = [
			'edit_posts'         => true,
			'edit_post'          => true,
			'edit_theme_options' => true,
			'delete_posts'       => true,
			'manage_options'     => true,
		];
		$GLOBALS['stonewright_test_user_logged_in']  = true;
		$GLOBALS['stonewright_test_current_user_id'] = 1;
		$GLOBALS['stonewright_test_options']         = [ 'stonewright_mode' => 'development' ];
		$GLOBALS['stonewright_test_posts']           = [];
		$GLOBALS['stonewright_test_next_post_id']    = 5200;
		$GLOBALS['stonewright_test_inserted_posts']  = [];
		$GLOBALS['stonewright_test_deleted_posts']   = [];
		$GLOBALS['stonewright_test_post_meta_calls'] = [];
		$GLOBALS['stonewright_test_object_terms']    = [];
		$GLOBALS['stonewright_test_terms']           = [];
		$GLOBALS['stonewright_test_audit_rows']      = [];
		$GLOBALS['stonewright_test_wpdb_inserts']    = [];
	}

	public function test_ability_names_are_stable(): void {
		self::assertSame( 'stonewright/patterns-create', ( new CreatePattern() )->name() );
		self::assertSame( 'stonewright/patterns-update', ( new UpdatePattern() )->name() );
		self::assertSame( 'stonewright/patterns-delete', ( new DeletePattern() )->name() );
		self::assertSame( 'stonewright/patterns-categories', ( new PatternCategories() )->name() );
	}

	public function test_create_snapshots_sanitizes_and_audits(): void {
		$result = ( new CreatePattern() )->execute(
			[
				'title'   => 'Hero',
				'content' => '<!-- wp:paragraph --><p>Hello</p><!-- /wp:paragraph -->',
				'slug'    => 'hero',
			]
		);

		self::assertIsArray( $result );
		self::assertGreaterThan( 0, $result['id'] );
		self::assertSame( 'hero', $result['slug'] );
		self::assertNotEmpty( $result['snapshot_id'] );
		self::assertSame( 'wp_block', $GLOBALS['stonewright_test_posts'][ $result['id'] ]->post_type );
		$audit_writes = array_filter(
			$GLOBALS['stonewright_test_wpdb_inserts'] ?? [],
			static fn( array $row ): bool => str_contains( (string) ( $row['table'] ?? '' ), 'stonewright_audit_log' )
		);
		self::assertNotEmpty( $audit_writes );
	}

	public function test_create_refuses_empty_content(): void {
		$result = ( new CreatePattern() )->execute( [ 'title' => 'Hero', 'content' => '  ' ] );
		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_empty_content', $result->get_error_code() );
	}

	public function test_create_requires_confirmation_in_production_safe(): void {
		$GLOBALS['stonewright_test_options']['stonewright_mode'] = 'production-safe';
		$result = ( new CreatePattern() )->execute(
			[
				'title'   => 'Hero',
				'content' => '<!-- wp:paragraph --><p>Hello</p><!-- /wp:paragraph -->',
			]
		);
		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_confirmation_required', $result->get_error_code() );
	}

	public function test_update_backups_before_write(): void {
		$created = ( new CreatePattern() )->execute(
			[
				'title'   => 'Hero',
				'content' => '<!-- wp:paragraph --><p>Hello</p><!-- /wp:paragraph -->',
			]
		);
		self::assertIsArray( $created );
		$GLOBALS['stonewright_test_post_meta_calls'] = [];

		$updated = ( new UpdatePattern() )->execute(
			[
				'id'      => $created['id'],
				'content' => '<!-- wp:paragraph --><p>Updated</p><!-- /wp:paragraph -->',
			]
		);

		self::assertIsArray( $updated );
		self::assertNotEmpty( $updated['snapshot_id'] );
		$backup_writes = array_filter(
			$GLOBALS['stonewright_test_post_meta_calls'],
			static fn( array $c ): bool => '_stonewright_backups' === ( $c['meta_key'] ?? '' )
		);
		self::assertNotEmpty( $backup_writes );
		self::assertStringContainsString( 'Updated', $GLOBALS['stonewright_test_posts'][ $created['id'] ]->post_content );
	}

	public function test_update_requires_confirmation_in_production_safe(): void {
		$created = ( new CreatePattern() )->execute(
			[
				'title'   => 'Hero',
				'content' => '<!-- wp:paragraph --><p>Hello</p><!-- /wp:paragraph -->',
			]
		);
		self::assertIsArray( $created );
		$GLOBALS['stonewright_test_options']['stonewright_mode'] = 'production-safe';
		$args = [
			'id'      => $created['id'],
			'content' => '<!-- wp:paragraph --><p>Updated</p><!-- /wp:paragraph -->',
		];
		$blocked = ( new UpdatePattern() )->execute( $args );
		self::assertInstanceOf( \WP_Error::class, $blocked );
		self::assertSame( 'stonewright_confirmation_required', $blocked->get_error_code() );

		$token  = ConfirmationToken::issue( 'stonewright/patterns-update', $args );
		$result = ( new UpdatePattern() )->execute( array_merge( $args, [ 'confirmation_token' => $token ] ) );
		self::assertIsArray( $result );
	}

	public function test_delete_snapshots_then_trashes(): void {
		$created = ( new CreatePattern() )->execute(
			[
				'title'   => 'Hero',
				'content' => '<!-- wp:paragraph --><p>Hello</p><!-- /wp:paragraph -->',
			]
		);
		self::assertIsArray( $created );

		$deleted = ( new DeletePattern() )->execute( [ 'id' => $created['id'] ] );
		self::assertIsArray( $deleted );
		self::assertNotEmpty( $deleted['snapshot_id'] );
		self::assertSame( 'trash', $GLOBALS['stonewright_test_posts'][ $created['id'] ]->post_status );
	}

	public function test_categories_list_and_assign(): void {
		$created = ( new CreatePattern() )->execute(
			[
				'title'   => 'Hero',
				'content' => '<!-- wp:paragraph --><p>Hello</p><!-- /wp:paragraph -->',
			]
		);
		self::assertIsArray( $created );

		$assigned = ( new PatternCategories() )->execute(
			[
				'action'     => 'assign',
				'id'         => $created['id'],
				'categories' => [ 'featured', 'headers' ],
			]
		);
		self::assertIsArray( $assigned );
		self::assertSame( [ 'featured', 'headers' ], $assigned['categories'] );

		$listed = ( new PatternCategories() )->execute( [ 'action' => 'list' ] );
		self::assertIsArray( $listed );
		self::assertContains( 'featured', $listed['categories'] );
	}

	public function test_create_keeps_title_slug_status_fields(): void {
		$schema = ( new CreatePattern() )->input_schema();
		self::assertArrayHasKey( 'title', $schema['properties'] );
		self::assertArrayHasKey( 'content', $schema['properties'] );
		self::assertArrayHasKey( 'slug', $schema['properties'] );
		self::assertArrayHasKey( 'status', $schema['properties'] );
		self::assertArrayHasKey( 'confirmation_token', $schema['properties'] );
	}
}
