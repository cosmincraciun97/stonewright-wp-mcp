<?php
/**
 * A duplicate op_id in one Gutenberg batch is refused.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\SectionReuse;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\Gutenberg\BlocksBatchMutate;
use Stonewright\WpMcp\Abilities\SectionReuse\SectionReuseExtract;
use Stonewright\WpMcp\SectionReuse\ReferenceCatalog;
use Stonewright\WpMcp\Security\IncidentStore;

require_once __DIR__ . '/SectionFixtures.php';

/**
 * @covers \Stonewright\WpMcp\Abilities\Gutenberg\BlocksBatchMutate
 * @covers \Stonewright\WpMcp\SectionReuse\BatchOperationIds
 */
final class GutenbergDuplicateOpIdTest extends TestCase {

	private const TARGET = 701;
	private const SOURCE = 30;

	private const INTRO = "<!-- wp:paragraph -->\n<p>Intro</p>\n<!-- /wp:paragraph -->";

	protected function setUp(): void {
		IncidentStore::reset_for_tests();
		ReferenceCatalog::set_provider( static fn( string $type, string $id ): ?bool => true );
		$GLOBALS['stonewright_test_options']              = [ 'stonewright_mode' => 'development' ];
		$GLOBALS['stonewright_test_post_meta_calls']      = [];
		$GLOBALS['stonewright_test_wp_update_post_calls'] = [];
		$GLOBALS['stonewright_test_user_caps']            = [ 'edit_post' => true, 'read_post' => true, 'edit_posts' => true, 'read' => true ];
		$GLOBALS['stonewright_test_user_can_callback']    = null;
		$GLOBALS['stonewright_test_user_logged_in']       = true;
		$GLOBALS['stonewright_test_current_user_id']      = 1;
		$GLOBALS['stonewright_test_posts']                = [
			self::TARGET => SectionFixtures::post( self::TARGET, 'page', 'draft', 'New page', self::INTRO ),
			self::SOURCE => SectionFixtures::post( self::SOURCE, 'page', 'publish', 'Source page', self::INTRO . "\n\n" . SectionFixtures::gutenberg_features_content( 'Why choose us', 3, 'features' ) ),
		];
	}

	protected function tearDown(): void {
		ReferenceCatalog::set_provider( null );
		IncidentStore::reset_for_tests();
		$GLOBALS['stonewright_test_posts']                = [];
		$GLOBALS['stonewright_test_options']              = [];
		$GLOBALS['stonewright_test_post_meta_calls']      = [];
		$GLOBALS['stonewright_test_wp_update_post_calls'] = [];
		$GLOBALS['stonewright_test_user_caps']            = [];
		$GLOBALS['stonewright_test_user_can_callback']    = null;
		$GLOBALS['stonewright_test_user_logged_in']       = false;
		$GLOBALS['stonewright_test_current_user_id']      = 0;
	}

	/** @return array<string, mixed> */
	private static function section(): array {
		$GLOBALS['stonewright_test_user_can_callback'] = static fn( string $cap, mixed ...$args ): bool => in_array( $cap, [ 'edit_posts', 'read_post', 'edit_post' ], true );
		$result = ( new SectionReuseExtract() )->execute( [ 'post_id' => self::SOURCE, 'locator' => [ 'kind' => 'block', 'anchor' => 'features' ] ] );
		$GLOBALS['stonewright_test_user_can_callback'] = null;
		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() : '' );

		return $result['section'];
	}

	public function test_two_operations_with_the_same_op_id_are_refused_in_the_dry_run_and_the_apply(): void {
		$section = self::section();
		$before  = (string) get_post( self::TARGET )->post_content;
		$ops     = [
			[ 'action' => 'insert_section', 'op_id' => 'feat', 'path' => [], 'position' => 1, 'section' => $section ],
			[ 'action' => 'insert_section', 'op_id' => 'feat', 'path' => [], 'position' => 2, 'section' => $section ],
		];

		foreach ( [ [ 'dry_run' => true ], [ 'expected_content_hash' => hash( 'sha256', $before ) ] ] as $extra ) {
			$result = ( new BlocksBatchMutate() )->execute( array_merge( [ 'post_id' => self::TARGET, 'operations' => $ops ], $extra ) );

			self::assertInstanceOf( \WP_Error::class, $result );
			self::assertSame( 'stonewright_duplicate_op_id', $result->get_error_code() );
			self::assertSame( 'feat', $result->get_error_data()['op_id'] );
			self::assertSame( [ 0, 1 ], $result->get_error_data()['indexes'] );
		}
		self::assertSame( $before, (string) get_post( self::TARGET )->post_content );
		self::assertSame( [], $GLOBALS['stonewright_test_wp_update_post_calls'] );
	}

	public function test_different_op_ids_are_accepted(): void {
		$section = self::section();

		$result = ( new BlocksBatchMutate() )->execute(
			[
				'post_id'    => self::TARGET,
				'dry_run'    => true,
				'operations' => [
					[ 'action' => 'insert_section', 'op_id' => 'one', 'path' => [], 'position' => 1, 'section' => $section ],
					[ 'action' => 'insert_section', 'op_id' => 'two', 'path' => [], 'position' => 2, 'section' => $section ],
				],
			]
		);

		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_code() : '' );
	}
}
