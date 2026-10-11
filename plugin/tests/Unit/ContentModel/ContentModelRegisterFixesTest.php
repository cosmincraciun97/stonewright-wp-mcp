<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\ContentModel;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\ContentModel\CptAcfLoopGridFlow;
use Stonewright\WpMcp\Abilities\ContentModel\CptRegister;
use Stonewright\WpMcp\Abilities\ContentModel\TaxonomyRegister;

/**
 * Custom post type and taxonomy registration: the names core limits or reserves are refused, the result of the
 * register call is checked, an existing CPT UI entry is merged, and the description says what persists.
 *
 * @covers \Stonewright\WpMcp\Abilities\ContentModel\CptRegister
 * @covers \Stonewright\WpMcp\Abilities\ContentModel\TaxonomyRegister
 * @covers \Stonewright\WpMcp\Abilities\ContentModel\ReservedNames
 * @covers \Stonewright\WpMcp\Abilities\ContentModel\CptUiStore
 * @covers \Stonewright\WpMcp\Abilities\ContentModel\CptAcfLoopGridFlow::permission_callback
 */
final class ContentModelRegisterFixesTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_user_logged_in'] = true;
		$GLOBALS['stonewright_test_user_caps']      = [ 'manage_options' => true, 'edit_posts' => true ];
		$GLOBALS['stonewright_test_options']        = [
			'stonewright_mode' => 'development',
			'cptui_post_types' => [],
			'cptui_taxonomies' => [],
		];
		$GLOBALS['stonewright_test_post_types']                = [];
		$GLOBALS['stonewright_test_taxonomies']                = [];
		$GLOBALS['stonewright_test_registered_post_types']     = [];
		$GLOBALS['stonewright_test_registered_taxonomies']     = [];
		$GLOBALS['stonewright_test_cptui_active']              = false;
		unset( $GLOBALS['stonewright_test_register_post_type_return'], $GLOBALS['stonewright_test_register_taxonomy_return'] );
	}

	protected function tearDown(): void {
		unset(
			$GLOBALS['stonewright_test_cptui_active'],
			$GLOBALS['stonewright_test_register_post_type_return'],
			$GLOBALS['stonewright_test_register_taxonomy_return']
		);
		$GLOBALS['stonewright_test_post_types'] = [];
		$GLOBALS['stonewright_test_taxonomies'] = [];
		$GLOBALS['stonewright_test_options']    = [];
	}

	private function cpt( array $overrides = [] ): array|\WP_Error {
		return ( new CptRegister() )->execute( $overrides + [ 'slug' => 'recipe', 'singular' => 'Recipe', 'plural' => 'Recipes' ] );
	}

	private function tax( array $overrides = [] ): array|\WP_Error {
		return ( new TaxonomyRegister() )->execute( $overrides + [ 'slug' => 'cuisine', 'object_types' => [ 'recipe' ], 'singular' => 'Cuisine', 'plural' => 'Cuisines' ] );
	}

	// --- slug limits and reserved names -----------------------------------------------------------------

	public function test_the_schemas_carry_the_core_length_limits(): void {
		self::assertSame( 20, ( new CptRegister() )->input_schema()['properties']['slug']['maxLength'] );
		self::assertSame( 32, ( new TaxonomyRegister() )->input_schema()['properties']['slug']['maxLength'] );
		self::assertSame( 20, ( new CptAcfLoopGridFlow() )->input_schema()['properties']['post_type']['properties']['slug']['maxLength'] );
	}

	public function test_a_post_type_slug_over_twenty_characters_is_refused_before_anything_is_written(): void {
		$result = $this->cpt( [ 'slug' => str_repeat( 'a', 21 ) ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_cpt_slug_invalid', $result->get_error_code() );
		self::assertSame( [], get_option( 'cptui_post_types' ) );
		self::assertSame( [], $GLOBALS['stonewright_test_registered_post_types'] );
	}

	public function test_a_taxonomy_slug_over_thirty_two_characters_is_refused(): void {
		$result = $this->tax( [ 'slug' => str_repeat( 'a', 33 ) ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_taxonomy_invalid', $result->get_error_code() );
		self::assertSame( [], get_option( 'cptui_taxonomies' ) );
	}

	public function test_reserved_names_are_refused(): void {
		foreach ( [ 'post', 'page', 'attachment', 'action', 'author', 'order', 'wp_block', 'nav_menu_item' ] as $slug ) {
			$result = $this->cpt( [ 'slug' => $slug ] );
			self::assertInstanceOf( \WP_Error::class, $result, $slug );
			self::assertSame( 'stonewright_cpt_slug_invalid', $result->get_error_code(), $slug );
		}
		foreach ( [ 'category', 'post_tag', 'post_format', 'term', 's', 'wp_theme', 'nav_menu' ] as $slug ) {
			$result = $this->tax( [ 'slug' => $slug ] );
			self::assertInstanceOf( \WP_Error::class, $result, $slug );
			self::assertSame( 'stonewright_taxonomy_invalid', $result->get_error_code(), $slug );
		}
		self::assertSame( [], $GLOBALS['stonewright_test_registered_post_types'] );
		self::assertSame( [], $GLOBALS['stonewright_test_registered_taxonomies'] );
	}

	public function test_a_name_another_plugin_registered_is_refused_but_our_own_entry_may_be_updated(): void {
		$GLOBALS['stonewright_test_post_types']['product'] = (object) [ 'cap' => (object) [] ];
		$GLOBALS['stonewright_test_taxonomies']['genre']   = true;

		$taken = $this->cpt( [ 'slug' => 'product' ] );
		self::assertInstanceOf( \WP_Error::class, $taken );
		self::assertSame( 'stonewright_cpt_slug_invalid', $taken->get_error_code() );
		$taken_tax = $this->tax( [ 'slug' => 'genre' ] );
		self::assertInstanceOf( \WP_Error::class, $taken_tax );

		// A type we stored earlier is registered in the same request by CPT UI, so it exists and may still be updated.
		self::assertIsArray( $this->cpt() );
		$GLOBALS['stonewright_test_post_types']['recipe'] = (object) [ 'cap' => (object) [] ];
		self::assertIsArray( $this->cpt( [ 'plural' => 'Many recipes' ] ) );
		self::assertIsArray( $this->tax() );
		$GLOBALS['stonewright_test_taxonomies']['cuisine'] = true;
		self::assertIsArray( $this->tax( [ 'plural' => 'More cuisines' ] ) );
	}

	// --- the register call result -------------------------------------------------------------------------

	public function test_a_failed_register_call_is_an_error_and_stores_nothing(): void {
		$GLOBALS['stonewright_test_register_post_type_return'] = new \WP_Error( 'post_type_length_invalid', 'Too long.' );
		$result = $this->cpt();
		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'post_type_length_invalid', $result->get_error_code() );
		self::assertSame( [], get_option( 'cptui_post_types' ) );

		$GLOBALS['stonewright_test_register_taxonomy_return'] = new \WP_Error( 'invalid_taxonomy', 'Bad.' );
		$tax = $this->tax();
		self::assertInstanceOf( \WP_Error::class, $tax );
		self::assertSame( [], get_option( 'cptui_taxonomies' ) );
	}

	// --- merge, not replace --------------------------------------------------------------------------------

	public function test_an_existing_cpt_ui_entry_is_merged(): void {
		$GLOBALS['stonewright_test_options']['cptui_post_types'] = [
			'recipe' => [
				'name'           => 'recipe',
				'label'          => 'Old label',
				'description'    => 'Kept description',
				'menu_icon'      => 'dashicons-carrot',
				'supports'       => [ 'title', 'editor', 'thumbnail' ],
				'rewrite_slug'   => 'recipes',
			],
			'other' => [ 'name' => 'other' ],
		];

		$result = $this->cpt( [ 'plural' => 'New label' ] );

		self::assertIsArray( $result );
		$entry = get_option( 'cptui_post_types' )['recipe'];
		self::assertSame( 'New label', $entry['label'] );
		self::assertSame( 'Kept description', $entry['description'] );
		self::assertSame( 'dashicons-carrot', $entry['menu_icon'] );
		self::assertSame( 'recipes', $entry['rewrite_slug'] );
		self::assertSame( [ 'title', 'editor', 'thumbnail' ], $entry['supports'], 'supports was not sent, so the stored value stays.' );
		self::assertArrayHasKey( 'other', get_option( 'cptui_post_types' ) );
		self::assertTrue( $result['updated'] );
		self::assertFalse( $result['created'] );
	}

	public function test_a_value_that_is_sent_replaces_the_stored_value(): void {
		$GLOBALS['stonewright_test_options']['cptui_post_types'] = [ 'recipe' => [ 'name' => 'recipe', 'supports' => [ 'title' ] ] ];

		$this->cpt( [ 'supports' => [ 'title', 'editor' ] ] );

		self::assertSame( [ 'title', 'editor' ], get_option( 'cptui_post_types' )['recipe']['supports'] );
	}

	public function test_a_taxonomy_entry_is_merged_too(): void {
		$GLOBALS['stonewright_test_options']['cptui_taxonomies'] = [ 'cuisine' => [ 'name' => 'cuisine', 'label' => 'Old', 'rewrite_slug' => 'food' ] ];

		$result = $this->tax();

		self::assertIsArray( $result );
		self::assertSame( 'food', get_option( 'cptui_taxonomies' )['cuisine']['rewrite_slug'] );
		self::assertSame( 'Cuisines', get_option( 'cptui_taxonomies' )['cuisine']['label'] );
	}

	// --- persistence is described as it is -------------------------------------------------------------------

	public function test_the_result_and_the_description_say_what_persists(): void {
		$without = $this->cpt();
		self::assertIsArray( $without );
		self::assertFalse( $without['persistent'] );
		self::assertStringContainsString( 'not active', $without['persistence'] );
		self::assertTrue( $without['created'] );

		$GLOBALS['stonewright_test_cptui_active'] = true;
		$with = $this->cpt( [ 'slug' => 'event' ] );
		self::assertTrue( $with['persistent'] );

		$taxonomy = $this->tax();
		self::assertTrue( $taxonomy['persistent'] );

		foreach ( [ new CptRegister(), new TaxonomyRegister() ] as $ability ) {
			self::assertStringContainsString( 'CPT UI plugin is active', $ability->description(), get_class( $ability ) );
			self::assertStringNotContainsString( 'persistent custom', $ability->description(), get_class( $ability ) );
		}
	}

	// --- content-model-loop-grid-flow permission --------------------------------------------------------------

	public function test_the_loop_grid_flow_needs_manage_options_and_edit_theme_options(): void {
		$flow = new CptAcfLoopGridFlow();

		$GLOBALS['stonewright_test_user_caps'] = [ 'edit_posts' => true ];
		self::assertFalse( $flow->permission_callback( [] ) );

		$GLOBALS['stonewright_test_user_caps'] = [ 'edit_posts' => true, 'manage_options' => true ];
		self::assertFalse( $flow->permission_callback( [] ), 'The Elementor template needs edit_theme_options too.' );

		$GLOBALS['stonewright_test_user_caps'] = [ 'edit_posts' => true, 'edit_theme_options' => true ];
		self::assertFalse( $flow->permission_callback( [] ), 'The options writes need manage_options.' );

		$GLOBALS['stonewright_test_user_caps'] = [ 'manage_options' => true, 'edit_theme_options' => true ];
		self::assertTrue( $flow->permission_callback( [] ) );
	}
}
