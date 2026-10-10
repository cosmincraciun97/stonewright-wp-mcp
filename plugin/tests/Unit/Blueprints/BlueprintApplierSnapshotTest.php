<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Blueprints;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Blueprints\BlueprintApplier;
use Stonewright\WpMcp\Security\Backup;

/**
 * The snapshot a blueprint apply returns is the page as it was before the apply touched it.
 *
 * @covers \Stonewright\WpMcp\Blueprints\BlueprintApplier
 */
final class BlueprintApplierSnapshotTest extends TestCase {

	private const POST_ID = 6101;

	protected function setUp(): void {
		$GLOBALS['stonewright_test_posts']           = [
			self::POST_ID => (object) [
				'ID'           => self::POST_ID,
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => 'Old title',
				'post_content' => 'old content',
				'post_excerpt' => '',
				'post_parent'  => 0,
				'post_name'    => 'old-title',
				'meta'         => [],
			],
		];
		$GLOBALS['stonewright_test_post_meta_calls'] = [];
		$GLOBALS['stonewright_test_options']         = [ 'stonewright_mode' => 'development' ];
		$GLOBALS['stonewright_test_user_caps']       = [ 'edit_posts' => true, 'edit_post' => true ];
		$GLOBALS['stonewright_test_user_logged_in']  = true;
		BlueprintApplier::$test_elementor_available  = true;
	}

	protected function tearDown(): void {
		BlueprintApplier::$test_elementor_available = null;
		$GLOBALS['stonewright_test_posts']           = [];
		$GLOBALS['stonewright_test_post_meta_calls'] = [];
		$GLOBALS['stonewright_test_options']         = [];
		$GLOBALS['stonewright_test_user_caps']       = [];
		$GLOBALS['stonewright_test_user_logged_in']  = false;
	}

	/** @return iterable<string, array{string}> */
	public static function engines(): iterable {
		yield 'elementor' => [ 'elementor' ];
		yield 'gutenberg' => [ 'gutenberg' ];
		yield 'fse'       => [ 'fse' ];
	}

	/** @dataProvider engines */
	public function test_applying_to_an_existing_page_returns_the_snapshot_taken_before_any_change( string $engine ): void {
		$result = BlueprintApplier::apply(
			[
				'blueprint_id' => 'dental',
				'engine'       => $engine,
				'post_id'      => self::POST_ID,
				'page_title'   => 'New title',
				'mode'         => 'draft',
			]
		);

		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() : '' );
		self::assertSame( 'New title', get_post( self::POST_ID )->post_title, 'The apply changed the page.' );
		$snapshot = Backup::get_snapshot( self::POST_ID, (string) $result['snapshot_id'] );
		self::assertIsArray( $snapshot, 'The returned id names a snapshot of the page.' );
		self::assertSame( 'Old title', $snapshot['post_title'], 'The snapshot holds the title from before the apply.' );
		self::assertSame( 'publish', $snapshot['post_status'], 'The snapshot holds the status from before the apply.' );
		self::assertSame( 'old content', $snapshot['post_content'] );
		self::assertTrue( Backup::restore( self::POST_ID, (string) $result['snapshot_id'] ) );
		self::assertSame( 'Old title', get_post( self::POST_ID )->post_title );
		self::assertSame( 'publish', get_post( self::POST_ID )->post_status );
	}
}
