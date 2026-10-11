<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\ThemeBuilder;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\ThemeBuilder\ApplyTemplate;
use Stonewright\WpMcp\Abilities\ThemeBuilder\CreateTemplate;
use Stonewright\WpMcp\Abilities\ThemeBuilder\DeleteTemplate;
use Stonewright\WpMcp\Abilities\ThemeBuilder\SetConditions;
use Stonewright\WpMcp\Elementor\Write\PostWriteLock;
use Stonewright\WpMcp\Support\ElementorData;

/**
 * theme-builder-apply-template and the other Theme Builder writers: the snapshot is checked, append mode never
 * repeats an element id, a failed write answers with its real error and leaves no template that was created by
 * the call, and a stored change points to the CSS regeneration and purges the template's cached page.
 *
 * @covers \Stonewright\WpMcp\Abilities\ThemeBuilder\ApplyTemplate
 * @covers \Stonewright\WpMcp\Abilities\ThemeBuilder\CreateTemplate
 * @covers \Stonewright\WpMcp\Abilities\ThemeBuilder\SetConditions
 * @covers \Stonewright\WpMcp\Abilities\ThemeBuilder\DeleteTemplate
 */
final class ApplyTemplateWriteSafetyTest extends TestCase {

	private const EXISTING = 8200;

	protected function setUp(): void {
		$GLOBALS['stonewright_test_options']          = [];
		$GLOBALS['stonewright_test_posts']            = [
			self::EXISTING => (object) [
				'ID'           => self::EXISTING,
				'post_type'    => 'elementor_library',
				'post_status'  => 'publish',
				'post_title'   => 'Existing header',
				'post_content' => '',
				'post_excerpt' => '',
				'post_parent'  => 0,
				'post_name'    => 'existing-header',
				'meta'         => [
					'_elementor_template_type' => 'header',
					'_elementor_edit_mode'     => 'builder',
					'_elementor_data'          => '[]',
				],
			],
		];
		$GLOBALS['stonewright_test_post_meta_calls']  = [];
		$GLOBALS['stonewright_test_inserted_posts']   = [];
		$GLOBALS['stonewright_test_deleted_posts']    = [];
		$GLOBALS['stonewright_test_actions']          = [];
		$GLOBALS['stonewright_test_next_post_id']     = 8300;
		$GLOBALS['stonewright_test_user_logged_in']   = true;
		$GLOBALS['stonewright_test_user_caps']        = [ 'edit_posts' => true ];
		$GLOBALS['stonewright_test_user_can_callback'] = static fn( string $cap ): bool => in_array( $cap, [ 'edit_posts', 'edit_post', 'delete_post', 'edit_theme_options' ], true );
		unset( $GLOBALS['stonewright_test_update_post_meta_returns'] );
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_options']          = [];
		$GLOBALS['stonewright_test_posts']            = [];
		$GLOBALS['stonewright_test_post_meta_calls']  = [];
		$GLOBALS['stonewright_test_inserted_posts']   = [];
		$GLOBALS['stonewright_test_deleted_posts']    = [];
		$GLOBALS['stonewright_test_actions']          = [];
		$GLOBALS['stonewright_test_user_logged_in']   = false;
		$GLOBALS['stonewright_test_user_caps']        = [];
		unset( $GLOBALS['stonewright_test_user_can_callback'], $GLOBALS['stonewright_test_update_post_meta_returns'] );
	}

	/** @return array<string, mixed> */
	private static function spec(): array {
		return [
			'page'     => [ 'title' => 'Template' ],
			'sections' => [ [ 'id' => 'content', 'blocks' => [ [ 'type' => 'heading', 'text' => 'Header' ] ] ] ],
		];
	}

	/** @return array<string, mixed> */
	private static function args( array $extra = [] ): array {
		return array_merge(
			[
				'title'         => 'Site header',
				'template_type' => 'header',
				'conditions'    => [ [ 'type' => 'include', 'name' => 'general' ] ],
				'spec'          => self::spec(),
			],
			$extra
		);
	}

	/** @return list<string> */
	private static function all_ids( int $template_id ): array {
		return array_keys( ElementorData::flatten( ElementorData::read( $template_id ) ) );
	}

	public function test_a_failed_snapshot_stops_the_write_on_an_existing_template(): void {
		$GLOBALS['stonewright_test_update_post_meta_returns'] = [ '_stonewright_backups' => true ];
		$before = (array) $GLOBALS['stonewright_test_posts'][ self::EXISTING ]->meta;

		$result = ( new ApplyTemplate() )->execute( self::args( [ 'template_id' => self::EXISTING ] ) );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_backup_failed', $result->get_error_code() );
		self::assertSame( $before, (array) $GLOBALS['stonewright_test_posts'][ self::EXISTING ]->meta );
		self::assertSame( [], $GLOBALS['stonewright_test_deleted_posts'] );
	}

	public function test_a_failed_snapshot_removes_the_template_the_call_created(): void {
		$GLOBALS['stonewright_test_update_post_meta_returns'] = [ '_stonewright_backups' => true ];

		$result = ( new ApplyTemplate() )->execute( self::args() );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_backup_failed', $result->get_error_code() );
		self::assertSame( [ [ 'post_id' => 8300, 'force' => true ] ], $GLOBALS['stonewright_test_deleted_posts'], 'The empty template this call created is not left behind.' );
		self::assertArrayNotHasKey( 8300, $GLOBALS['stonewright_test_posts'] );
	}

	public function test_a_write_that_fails_returns_its_own_error_and_removes_the_created_template(): void {
		// The document meta cannot be stored: the integrity readback fails and ElementorData reports it.
		$GLOBALS['stonewright_test_update_post_meta_returns'] = [ '_elementor_data' => false ];

		$result = ( new ApplyTemplate() )->execute( self::args() );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertNotSame( 'stonewright_write_failed', $result->get_error_code(), 'The real error is returned, not a generic one.' );
		self::assertStringStartsWith( 'stonewright_elementor_readback_failed', $result->get_error_code() );
		self::assertSame( [ [ 'post_id' => 8300, 'force' => true ] ], $GLOBALS['stonewright_test_deleted_posts'] );
	}

	public function test_a_write_that_fails_on_an_existing_template_keeps_the_template(): void {
		PostWriteLock::acquire( self::EXISTING, 'other-writer', 30 );

		$result = ( new ApplyTemplate() )->execute( self::args( [ 'template_id' => self::EXISTING ] ) );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_elementor_write_busy', $result->get_error_code() );
		self::assertSame( [], $GLOBALS['stonewright_test_deleted_posts'], 'A template that was there before the call is never deleted.' );
		self::assertTrue( PostWriteLock::owned_by( self::EXISTING, 'other-writer' ) );
	}

	public function test_conditions_that_cannot_be_stored_remove_the_created_template(): void {
		$GLOBALS['stonewright_test_update_post_meta_returns'] = [ '_elementor_conditions' => false ];

		$result = ( new ApplyTemplate() )->execute( self::args() );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_write_failed', $result->get_error_code() );
		self::assertSame( [ [ 'post_id' => 8300, 'force' => true ] ], $GLOBALS['stonewright_test_deleted_posts'] );
	}

	public function test_append_mode_never_repeats_an_element_id(): void {
		$first = ( new ApplyTemplate() )->execute( self::args( [ 'template_id' => self::EXISTING, 'mode' => 'append' ] ) );
		self::assertIsArray( $first, $first instanceof \WP_Error ? $first->get_error_code() : '' );
		$count_after_first = count( self::all_ids( self::EXISTING ) );

		// The renderer derives the ids from the position in the spec, so the same spec renders the same ids again.
		$second = ( new ApplyTemplate() )->execute( self::args( [ 'template_id' => self::EXISTING, 'mode' => 'append' ] ) );

		self::assertIsArray( $second, $second instanceof \WP_Error ? $second->get_error_code() : '' );
		$tree = ElementorData::read( self::EXISTING );
		self::assertCount( 2, $tree, 'Both sections are there.' );
		$ids = [];
		ElementorData::walk( $tree, static function ( array $element ) use ( &$ids ): void {
			$ids[] = (string) $element['id'];
		} );
		self::assertSame( count( $ids ), count( array_unique( $ids ) ), 'No id appears twice.' );
		self::assertSame( $count_after_first * 2, count( $ids ) );
	}

	public function test_an_applied_template_points_to_css_regeneration_and_purges_its_cached_page(): void {
		$purged = [];
		add_action(
			'litespeed_purge_post',
			static function ( int $post_id ) use ( &$purged ): void {
				$purged[] = $post_id;
			}
		);

		$result = ( new ApplyTemplate() )->execute( self::args() );

		self::assertIsArray( $result );
		self::assertSame( 'stonewright/elementor-css-regenerate', $result['next_step']['tool'] );
		self::assertSame( 8300, $result['next_step']['post_id'] );
		self::assertSame( [ 8300 ], $purged );
		self::assertContains( 'litespeed_cache', $result['cache_purge']['ran'] );
	}

	public function test_a_dry_run_has_no_next_step_and_purges_nothing(): void {
		$purged = [];
		add_action(
			'litespeed_purge_post',
			static function ( int $post_id ) use ( &$purged ): void {
				$purged[] = $post_id;
			}
		);

		$result = ( new ApplyTemplate() )->execute( self::args( [ 'dry_run' => true ] ) );

		self::assertIsArray( $result );
		self::assertArrayNotHasKey( 'next_step', $result );
		self::assertSame( [], $purged );
	}

	public function test_set_conditions_points_to_css_regeneration_and_purges_the_template(): void {
		$purged = [];
		add_action(
			'litespeed_purge_post',
			static function ( int $post_id ) use ( &$purged ): void {
				$purged[] = $post_id;
			}
		);

		$result = ( new SetConditions() )->execute( [ 'template_id' => self::EXISTING, 'conditions' => [ [ 'type' => 'include', 'name' => 'general' ] ] ] );

		self::assertIsArray( $result );
		self::assertSame( 'stonewright/elementor-css-regenerate', $result['next_step']['tool'] );
		self::assertSame( self::EXISTING, $result['next_step']['post_id'] );
		self::assertSame( [ self::EXISTING ], $purged );
	}

	public function test_create_template_points_to_css_regeneration_and_purges_the_template(): void {
		$purged = [];
		add_action(
			'litespeed_purge_post',
			static function ( int $post_id ) use ( &$purged ): void {
				$purged[] = $post_id;
			}
		);

		$result = ( new CreateTemplate() )->execute( [ 'title' => 'New footer', 'template_type' => 'footer' ] );

		self::assertIsArray( $result );
		self::assertSame( 'stonewright/elementor-css-regenerate', $result['next_step']['tool'] );
		self::assertSame( 8300, $result['next_step']['post_id'] );
		self::assertSame( [ 8300 ], $purged );
	}

	public function test_delete_template_purges_the_template_page_cache(): void {
		$purged = [];
		add_action(
			'litespeed_purge_post',
			static function ( int $post_id ) use ( &$purged ): void {
				$purged[] = $post_id;
			}
		);

		$result = ( new DeleteTemplate() )->execute( [ 'template_id' => self::EXISTING ] );

		self::assertIsArray( $result );
		self::assertTrue( $result['deleted'] );
		self::assertSame( [ self::EXISTING ], $purged );
		self::assertArrayHasKey( 'cache_purge', $result );
	}
}
