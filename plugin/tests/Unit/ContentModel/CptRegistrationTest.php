<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\ContentModel;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\ContentModel\CptList;
use Stonewright\WpMcp\Abilities\ContentModel\CptRegister;
use Stonewright\WpMcp\Abilities\ContentModel\TaxonomyRegister;

final class CptRegistrationTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_user_logged_in'] = true;
		$GLOBALS['stonewright_test_user_caps']      = [
			'manage_options' => true,
			'edit_posts'     => true,
		];
		$GLOBALS['stonewright_test_options'] = [
			'stonewright_mode' => 'development',
			'cptui_post_types' => [],
			'cptui_taxonomies' => [],
		];
	}

	public function test_cpt_register_and_list(): void {
		$result = ( new CptRegister() )->execute(
			[
				'slug'     => 'recipe',
				'singular' => 'Recipe',
				'plural'   => 'Recipes',
			]
		);
		$this->assertIsArray( $result );
		$this->assertTrue( $result['ok'] );
		$config = get_option( 'cptui_post_types', [] );
		$this->assertArrayHasKey( 'recipe', $config );

		// Idempotent update.
		$result2 = ( new CptRegister() )->execute(
			[
				'slug'     => 'recipe',
				'singular' => 'Recipe',
				'plural'   => 'Recipes',
				'supports' => [ 'title', 'editor', 'thumbnail' ],
			]
		);
		$this->assertIsArray( $result2 );
		$config = get_option( 'cptui_post_types', [] );
		$this->assertContains( 'thumbnail', (array) ( $config['recipe']['supports'] ?? [] ) );

		$list = ( new CptList() )->execute( [] );
		$this->assertIsArray( $list );
	}

	public function test_taxonomy_register(): void {
		$result = ( new TaxonomyRegister() )->execute(
			[
				'slug'         => 'cuisine',
				'object_types' => [ 'recipe' ],
				'singular'     => 'Cuisine',
				'plural'       => 'Cuisines',
			]
		);
		$this->assertIsArray( $result );
		$this->assertTrue( $result['ok'] );
		$config = get_option( 'cptui_taxonomies', [] );
		$this->assertArrayHasKey( 'cuisine', $config );
	}

	public function test_production_safe_requires_token(): void {
		$GLOBALS['stonewright_test_options']['stonewright_mode'] = 'production-safe';
		$result = ( new CptRegister() )->execute(
			[
				'slug'     => 'x',
				'singular' => 'X',
				'plural'   => 'Xs',
			]
		);
		$this->assertInstanceOf( \WP_Error::class, $result );
	}
}
