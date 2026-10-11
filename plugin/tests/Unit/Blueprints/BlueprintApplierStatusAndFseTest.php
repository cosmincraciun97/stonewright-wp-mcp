<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Blueprints;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\Blueprints\ApplyBlueprint;
use Stonewright\WpMcp\Blueprints\BlueprintApplier;

/**
 * What a blueprint apply does to the status of a page, to a page it created when the apply fails, and
 * how the FSE engine stores its template.
 *
 * @covers \Stonewright\WpMcp\Blueprints\BlueprintApplier
 * @covers \Stonewright\WpMcp\Abilities\Blueprints\ApplyBlueprint
 */
final class BlueprintApplierStatusAndFseTest extends TestCase {

	private const POST_ID = 6201;

	protected function setUp(): void {
		$GLOBALS['stonewright_test_posts']                 = [
			self::POST_ID => (object) [
				'ID'           => self::POST_ID,
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => 'Live page',
				'post_content' => 'live content',
				'post_excerpt' => '',
				'post_parent'  => 0,
				'post_name'    => 'live-page',
				'meta'         => [],
			],
		];
		$GLOBALS['stonewright_test_next_post_id']          = 6300;
		$GLOBALS['stonewright_test_inserted_posts']        = [];
		$GLOBALS['stonewright_test_wp_insert_post_calls']  = [];
		$GLOBALS['stonewright_test_wp_update_post_calls']  = [];
		$GLOBALS['stonewright_test_deleted_posts']         = [];
		$GLOBALS['stonewright_test_object_terms']          = [];
		$GLOBALS['stonewright_test_stylesheet']            = 'active-theme';
		$GLOBALS['stonewright_test_post_meta_calls']       = [];
		$GLOBALS['stonewright_test_options']               = [ 'stonewright_mode' => 'development' ];
		$GLOBALS['stonewright_test_user_caps']             = [
			'edit_posts'         => true,
			'edit_post'          => true,
			'edit_pages'         => true,
			'manage_options'     => true,
			'edit_theme_options' => true,
		];
		$GLOBALS['stonewright_test_user_logged_in']        = true;
		$GLOBALS['stonewright_test_current_user_id']       = 1;
		BlueprintApplier::$test_elementor_available        = true;
	}

	protected function tearDown(): void {
		BlueprintApplier::$test_elementor_available = null;
		unset( $GLOBALS['stonewright_test_stylesheet'] );
		$GLOBALS['stonewright_test_wp_update_post_return'] = null;
		$GLOBALS['stonewright_test_posts']                 = [];
		$GLOBALS['stonewright_test_options']               = [];
		$GLOBALS['stonewright_test_user_caps']             = [];
		$GLOBALS['stonewright_test_user_logged_in']        = false;
		$GLOBALS['stonewright_test_object_terms']          = [];
	}

	/** @return iterable<string, array{string}> */
	public static function engines(): iterable {
		yield 'elementor' => [ 'elementor' ];
		yield 'gutenberg' => [ 'gutenberg' ];
		yield 'fse'       => [ 'fse' ];
	}

	/** @param array<string, mixed> $extra */
	private function apply( string $engine, array $extra = [] ) {
		return BlueprintApplier::apply( array_merge( [ 'blueprint_id' => 'dental', 'engine' => $engine, 'page_title' => 'Applied' ], $extra ) );
	}

	/** @dataProvider engines */
	public function test_an_existing_page_keeps_its_status_unless_mode_is_passed( string $engine ): void {
		$result = $this->apply( $engine, [ 'post_id' => self::POST_ID ] );

		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() : '' );
		self::assertSame( 'publish', get_post( self::POST_ID )->post_status, 'A live page stays live.' );
		self::assertSame( 'Applied', get_post( self::POST_ID )->post_title );
		self::assertSame( 'publish', $result['mode'], 'The result names the status the page has.' );
	}

	/** @dataProvider engines */
	public function test_mode_changes_the_status_of_an_existing_page_when_it_is_passed( string $engine ): void {
		$result = $this->apply( $engine, [ 'post_id' => self::POST_ID, 'mode' => 'draft' ] );

		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() : '' );
		self::assertSame( 'draft', get_post( self::POST_ID )->post_status );
		self::assertSame( 'draft', $result['mode'] );
	}

	/** @dataProvider engines */
	public function test_a_new_page_is_inserted_as_a_draft_and_gets_its_final_status_after_the_write( string $engine ): void {
		$result = $this->apply( $engine, [ 'mode' => 'publish' ] );

		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() : '' );
		$pages = array_values( array_filter( $GLOBALS['stonewright_test_wp_insert_post_calls'], static fn( array $c ): bool => 'page' === ( $c['post_type'] ?? '' ) ) );
		self::assertCount( 1, $pages );
		self::assertSame( 'draft', $pages[0]['post_status'], 'The page is inserted as a draft, before anything is rendered.' );
		self::assertSame( 'publish', get_post( (int) $result['post_id'] )->post_status );
		self::assertSame( 'publish', $result['mode'] );
	}

	/** @dataProvider engines */
	public function test_a_new_page_stays_a_draft_by_default( string $engine ): void {
		$result = $this->apply( $engine );

		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() : '' );
		self::assertSame( 'draft', get_post( (int) $result['post_id'] )->post_status );
		self::assertSame( 'draft', $result['mode'] );
	}

	public function test_a_failed_write_removes_the_page_the_apply_created(): void {
		// The first wp_update_post of the Gutenberg engine is the content write.
		$GLOBALS['stonewright_test_wp_update_post_return'] = new \WP_Error( 'db_failure', 'The content could not be saved.' );

		$result = $this->apply( 'gutenberg' );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'db_failure', $result->get_error_code() );
		self::assertSame( [ self::POST_ID ], array_keys( $GLOBALS['stonewright_test_posts'] ), 'No empty page is left behind.' );
		self::assertSame( [ [ 'post_id' => 6300, 'force' => true ] ], $GLOBALS['stonewright_test_deleted_posts'] );
	}

	public function test_an_update_that_does_not_save_is_an_error_and_leaves_an_existing_page_alone(): void {
		// wp_update_post returns 0 when it did not save.
		$GLOBALS['stonewright_test_wp_update_post_return'] = 0;

		$result = $this->apply( 'gutenberg', [ 'post_id' => self::POST_ID ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_blueprint_update_failed', $result->get_error_code() );
		self::assertSame( 'live content', get_post( self::POST_ID )->post_content );
		self::assertSame( [], $GLOBALS['stonewright_test_deleted_posts'], 'An existing page is never deleted.' );
	}

	public function test_the_fse_engine_stores_its_template_the_way_core_does(): void {
		$result = $this->apply( 'fse' );

		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() : '' );
		$template_id = (int) $result['fse']['template_id'];
		self::assertGreaterThan( 0, $template_id );
		$template = $GLOBALS['stonewright_test_posts'][ $template_id ];
		self::assertSame( 'wp_template', $template->post_type );
		self::assertStringStartsWith( 'stonewright-blueprint-', $template->post_name );
		self::assertStringNotContainsString( '//', $template->post_name, 'post_name is the bare slug.' );
		self::assertSame( [ 'active-theme' ], array_values( (array) ( $GLOBALS['stonewright_test_object_terms'][ $template_id ]['wp_theme'] ?? [] ) ), 'The wp_theme term names the theme.' );
	}

	public function test_the_fse_engine_needs_the_site_editor_capabilities(): void {
		$GLOBALS['stonewright_test_user_caps'] = [ 'edit_posts' => true, 'edit_post' => true, 'edit_pages' => true, 'edit_theme_options' => true ];

		$result = $this->apply( 'fse' );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_forbidden', $result->get_error_code() );
		self::assertSame( [ self::POST_ID ], array_keys( $GLOBALS['stonewright_test_posts'] ), 'Nothing was created.' );
		self::assertSame( [], $GLOBALS['stonewright_test_wp_insert_post_calls'] );

		self::assertFalse( ( new ApplyBlueprint() )->permission_callback( [ 'blueprint_id' => 'dental', 'engine' => 'fse' ] ) );
		self::assertTrue( ( new ApplyBlueprint() )->permission_callback( [ 'blueprint_id' => 'dental', 'engine' => 'gutenberg' ] ) );
	}

	public function test_mode_has_no_default_in_the_input_schema_so_that_an_absent_mode_is_detectable(): void {
		$schema = ( new ApplyBlueprint() )->input_schema();

		self::assertArrayNotHasKey( 'default', $schema['properties']['mode'] );
		self::assertStringContainsString( 'keeps', (string) $schema['properties']['mode']['description'] );
	}
}
