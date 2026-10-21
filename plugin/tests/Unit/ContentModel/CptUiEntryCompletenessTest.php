<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\ContentModel;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\ContentModel\CptRegister;
use Stonewright\WpMcp\Abilities\ContentModel\TaxonomyRegister;
use Stonewright\WpMcp\Tests\Unit\ContentModel\Fixtures\CptUiRegistration;

/**
 * An entry Stonewright stores in the CPT UI options is what CPT UI registers on every later request. CPT UI
 * reads many of its keys without a guard, so an entry that lacks one logs PHP warnings on every request, and
 * a missing visibility key turns into false: the type the ability registered as a public, visible one came
 * back hidden from wp-admin and without permalinks. A stored entry has to give CPT UI every key it reads,
 * with the values that describe the type the ability registered in its own request.
 *
 * CptUiRegistration is CPT UI 1.19.3's own translation of an entry into register_* arguments, and PHPUnit
 * turns the warning a missing key raises into a failure.
 *
 * @covers \Stonewright\WpMcp\Abilities\ContentModel\CptUiStore
 * @covers \Stonewright\WpMcp\Abilities\ContentModel\CptRegister
 * @covers \Stonewright\WpMcp\Abilities\ContentModel\TaxonomyRegister
 */
final class CptUiEntryCompletenessTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_user_logged_in'] = true;
		$GLOBALS['stonewright_test_user_caps']      = [ 'manage_options' => true, 'edit_posts' => true ];
		$GLOBALS['stonewright_test_options']        = [
			'stonewright_mode' => 'development',
			'cptui_post_types' => [],
			'cptui_taxonomies' => [],
		];
		$GLOBALS['stonewright_test_post_types']            = [];
		$GLOBALS['stonewright_test_taxonomies']            = [];
		$GLOBALS['stonewright_test_registered_post_types'] = [];
		$GLOBALS['stonewright_test_registered_taxonomies'] = [];
		$GLOBALS['stonewright_test_cptui_active']          = true;
	}

	protected function tearDown(): void {
		unset( $GLOBALS['stonewright_test_cptui_active'] );
		$GLOBALS['stonewright_test_post_types'] = [];
		$GLOBALS['stonewright_test_taxonomies'] = [];
		$GLOBALS['stonewright_test_options']    = [];
	}

	public function test_cpt_ui_registers_a_stored_post_type_the_way_the_ability_registered_it(): void {
		self::assertIsArray( ( new CptRegister() )->execute( [ 'slug' => 'recipe', 'singular' => 'Recipe', 'plural' => 'Recipes' ] ) );

		// What WordPress makes of the arguments the ability registers in its own request: a public type that
		// shows in wp-admin and in menus, with its own permalinks and query var.
		self::assertSame(
			[
				'labels'                => [
					'name'          => 'Recipes',
					'singular_name' => 'Recipe',
				],
				'description'           => '',
				'public'                => true,
				'publicly_queryable'    => true,
				'show_ui'               => true,
				'show_in_nav_menus'     => true,
				'has_archive'           => true,
				'show_in_menu'          => true,
				'delete_with_user'      => null,
				'show_in_rest'          => true,
				'rest_base'             => null,
				'rest_controller_class' => null,
				'rest_namespace'        => null,
				'exclude_from_search'   => false,
				'capability_type'       => 'post',
				'map_meta_cap'          => true,
				'hierarchical'          => false,
				'can_export'            => true,
				'rewrite'               => [
					'slug'       => 'recipe',
					'with_front' => true,
				],
				'menu_position'         => null,
				'menu_icon'             => null,
				'register_meta_box_cb'  => null,
				'query_var'             => true,
				'supports'              => [ 'title', 'editor' ],
				'taxonomies'            => [],
			],
			CptUiRegistration::post_type_args( get_option( 'cptui_post_types' )['recipe'] )
		);
	}

	public function test_a_post_type_that_is_not_public_stays_out_of_wp_admin_and_search(): void {
		self::assertIsArray( ( new CptRegister() )->execute( [ 'slug' => 'internal', 'singular' => 'Internal', 'plural' => 'Internals', 'public' => false ] ) );

		$args = CptUiRegistration::post_type_args( get_option( 'cptui_post_types' )['internal'] );

		// WordPress derives these from public when the ability registers the type.
		self::assertFalse( $args['public'] );
		self::assertFalse( $args['publicly_queryable'] );
		self::assertFalse( $args['show_ui'] );
		self::assertFalse( $args['show_in_menu'] );
		self::assertFalse( $args['show_in_nav_menus'] );
		self::assertTrue( $args['exclude_from_search'] );
	}

	public function test_cpt_ui_registers_a_stored_taxonomy_the_way_the_ability_registered_it(): void {
		self::assertIsArray( ( new TaxonomyRegister() )->execute( [ 'slug' => 'cuisine', 'object_types' => [ 'recipe' ], 'singular' => 'Cuisine', 'plural' => 'Cuisines' ] ) );

		self::assertSame(
			[
				'labels'                => [
					'name'          => 'Cuisines',
					'singular_name' => 'Cuisine',
				],
				'label'                 => 'Cuisines',
				'description'           => '',
				'public'                => true,
				'publicly_queryable'    => true,
				'hierarchical'          => false,
				'show_ui'               => true,
				'show_in_menu'          => true,
				'show_in_nav_menus'     => true,
				'show_tagcloud'         => true,
				'query_var'             => true,
				'rewrite'               => [
					'slug'         => 'cuisine',
					'with_front'   => true,
					'hierarchical' => false,
				],
				'show_admin_column'     => false,
				'show_in_rest'          => true,
				'rest_base'             => null,
				'rest_controller_class' => null,
				'rest_namespace'        => null,
				'show_in_quick_edit'    => true,
				'sort'                  => false,
				'meta_box_cb'           => null,
				'default_term'          => null,
			],
			CptUiRegistration::taxonomy_args( get_option( 'cptui_taxonomies' )['cuisine'] )
		);
	}

	public function test_an_incomplete_stored_entry_gets_the_missing_keys_and_keeps_the_ones_it_has(): void {
		// The shape earlier versions stored: a handful of keys, and settings an administrator chose since.
		$GLOBALS['stonewright_test_options']['cptui_post_types'] = [
			'story' => [
				'name'           => 'story',
				'label'          => 'Stories',
				'singular_label' => 'Story',
				'public'         => true,
				'has_archive'    => false,
				'supports'       => [ 'title', 'thumbnail' ],
				'taxonomies'     => [],
				'show_in_rest'   => true,
				'show_ui'        => 'false',
				'menu_icon'      => 'dashicons-book-alt',
			],
		];
		$GLOBALS['stonewright_test_post_types']['story'] = (object) [ 'cap' => (object) [] ];

		self::assertIsArray( ( new CptRegister() )->execute( [ 'slug' => 'story', 'singular' => 'Story', 'plural' => 'Story Center' ] ) );

		$entry = get_option( 'cptui_post_types' )['story'];
		$args  = CptUiRegistration::post_type_args( $entry );

		self::assertSame( 'Story Center', $args['labels']['name'], 'The sent label replaces the stored one.' );
		self::assertFalse( $args['has_archive'], 'has_archive was not sent, so the stored value stays.' );
		self::assertSame( [ 'title', 'thumbnail' ], $args['supports'] );
		self::assertSame( 'dashicons-book-alt', $args['menu_icon'] );
		self::assertFalse( $args['show_ui'], 'A setting the entry already holds is not overwritten.' );
		self::assertFalse( $args['show_in_menu'], 'The menu follows the stored show_ui, as WordPress derives it.' );
		self::assertSame( [ 'slug' => 'story', 'with_front' => true ], $args['rewrite'] );
		self::assertTrue( $args['query_var'] );
	}

	public function test_an_incomplete_stored_taxonomy_gets_the_missing_keys(): void {
		$GLOBALS['stonewright_test_options']['cptui_taxonomies'] = [
			'story_tag' => [
				'name'           => 'story_tag',
				'label'          => 'Story tags',
				'singular_label' => 'Story tag',
				'object_types'   => [ 'story' ],
				'hierarchical'   => false,
				'show_in_rest'   => true,
			],
		];
		$GLOBALS['stonewright_test_taxonomies']['story_tag'] = true;

		self::assertIsArray( ( new TaxonomyRegister() )->execute( [ 'slug' => 'story_tag', 'object_types' => [ 'story' ], 'singular' => 'Story tag', 'plural' => 'Story tags' ] ) );

		$args = CptUiRegistration::taxonomy_args( get_option( 'cptui_taxonomies' )['story_tag'] );

		self::assertTrue( $args['show_ui'] );
		self::assertTrue( $args['show_in_menu'] );
		self::assertSame( [ 'slug' => 'story_tag', 'with_front' => true, 'hierarchical' => false ], $args['rewrite'] );
		self::assertTrue( $args['query_var'] );
	}
}
