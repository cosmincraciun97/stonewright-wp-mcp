<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\ThemeBuilder;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\ThemeBuilder\DeleteTemplate;
use Stonewright\WpMcp\Abilities\ThemeBuilder\GetTemplate;
use Stonewright\WpMcp\Abilities\ThemeBuilder\ListTemplates;
use Stonewright\WpMcp\Abilities\ThemeBuilder\SetConditions;

/**
 * @covers \Stonewright\WpMcp\Abilities\ThemeBuilder\SetConditions
 * @covers \Stonewright\WpMcp\Abilities\ThemeBuilder\ListTemplates
 * @covers \Stonewright\WpMcp\Abilities\ThemeBuilder\GetTemplate
 * @covers \Stonewright\WpMcp\Abilities\ThemeBuilder\DeleteTemplate
 */
final class ThemeBuilderAbilitiesTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_post_meta_calls'] = [];
		$GLOBALS['stonewright_test_deleted_posts']   = [];
		$GLOBALS['stonewright_test_user_caps']       = [ 'edit_posts' => true ];
		$GLOBALS['stonewright_test_user_logged_in']  = true;
		$GLOBALS['stonewright_test_posts'][ 7700 ]   = (object) [
			'ID'           => 7700,
			'post_type'    => 'elementor_library',
			'post_status'  => 'publish',
			'post_title'   => 'Site Header',
			'post_content' => '',
			'post_excerpt' => '',
			'post_parent'  => 0,
			'post_name'    => 'site-header',
			'meta'         => [
				'_elementor_template_type' => 'header',
				'_elementor_data'          => '[]',
				'_elementor_conditions'    => [
					[ 'type' => 'include', 'name' => 'general' ],
				],
			],
		];
		$GLOBALS['stonewright_test_posts'][ 7701 ] = (object) [
			'ID'           => 7701,
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_title'   => 'Plain page (NOT a template)',
			'post_content' => '',
			'post_excerpt' => '',
			'post_parent'  => 0,
			'post_name'    => 'plain-page',
			'meta'         => [],
		];
	}

	// -----------------------------------------------------------------------
	// Names + categories — fast sanity that the four classes exist + register.
	// -----------------------------------------------------------------------

	public function test_ability_names(): void {
		$this->assertSame( 'stonewright/theme-builder-set-conditions', ( new SetConditions() )->name() );
		$this->assertSame( 'stonewright/theme-builder-list-templates', ( new ListTemplates() )->name() );
		$this->assertSame( 'stonewright/theme-builder-get-template', ( new GetTemplate() )->name() );
		$this->assertSame( 'stonewright/theme-builder-delete-template', ( new DeleteTemplate() )->name() );
	}

	public function test_all_share_theme_builder_category(): void {
		foreach ( [ new SetConditions(), new ListTemplates(), new GetTemplate(), new DeleteTemplate() ] as $a ) {
			$this->assertSame( 'theme-builder', $a->category(), get_class( $a ) );
		}
	}

	// -----------------------------------------------------------------------
	// SetConditions
	// -----------------------------------------------------------------------

	public function test_set_conditions_schema_requires_template_id_and_conditions(): void {
		$schema = ( new SetConditions() )->input_schema();
		$this->assertContains( 'template_id', $schema['required'] );
		$this->assertContains( 'conditions', $schema['required'] );
	}

	public function test_set_conditions_writes_meta_for_real_template(): void {
		$result = ( new SetConditions() )->execute(
			[
				'template_id' => 7700,
				'conditions'  => [
					[ 'type' => 'include', 'name' => 'singular', 'sub_name' => 'post' ],
				],
			]
		);
		$this->assertIsArray( $result );
		$this->assertSame( 7700, $result['template_id'] );
		$this->assertTrue( $result['updated'] );

		$meta_writes = array_filter(
			$GLOBALS['stonewright_test_post_meta_calls'],
			static fn ( array $c ): bool => (int) $c['post_id'] === 7700 && '_elementor_conditions' === $c['meta_key']
		);
		$this->assertNotEmpty( $meta_writes );
	}

	public function test_set_conditions_rejects_non_template_post(): void {
		$result = ( new SetConditions() )->execute(
			[
				'template_id' => 7701,
				'conditions'  => [ [ 'type' => 'include', 'name' => 'general' ] ],
			]
		);
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'stonewright_not_a_template', $result->get_error_code() );
	}

	// -----------------------------------------------------------------------
	// ListTemplates
	// -----------------------------------------------------------------------

	public function test_list_templates_schema_accepts_optional_template_type_filter(): void {
		$schema = ( new ListTemplates() )->input_schema();
		$this->assertArrayHasKey( 'template_type', $schema['properties'] );
		$this->assertArrayNotHasKey( 'required', $schema );
	}

	public function test_list_templates_returns_wrapped_templates_array(): void {
		$result = ( new ListTemplates() )->execute( [] );
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'templates', $result );
		$this->assertIsArray( $result['templates'] );
	}

	// -----------------------------------------------------------------------
	// GetTemplate
	// -----------------------------------------------------------------------

	public function test_get_template_returns_id_title_type_conditions_tree(): void {
		$result = ( new GetTemplate() )->execute( [ 'template_id' => 7700 ] );
		$this->assertIsArray( $result );
		$this->assertSame( 7700, $result['template_id'] );
		$this->assertSame( 'Site Header', $result['title'] );
		$this->assertSame( 'header', $result['template_type'] );
		$this->assertIsArray( $result['conditions'] );
		$this->assertSame( 'general', $result['conditions'][0]['name'] );
		$this->assertIsArray( $result['tree'] );
	}

	public function test_get_template_rejects_non_template_post(): void {
		$result = ( new GetTemplate() )->execute( [ 'template_id' => 7701 ] );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'stonewright_not_a_template', $result->get_error_code() );
	}

	public function test_get_template_missing_post_returns_error(): void {
		$result = ( new GetTemplate() )->execute( [ 'template_id' => 99999 ] );
		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	// -----------------------------------------------------------------------
	// DeleteTemplate
	// -----------------------------------------------------------------------

	public function test_delete_template_trashes_by_default_and_returns_snapshot_id(): void {
		$result = ( new DeleteTemplate() )->execute( [ 'template_id' => 7700 ] );
		$this->assertIsArray( $result );
		$this->assertSame( 7700, $result['template_id'] );
		$this->assertTrue( $result['deleted'] );
		$this->assertIsString( $result['snapshot_id'] );

		$matching = array_filter(
			$GLOBALS['stonewright_test_deleted_posts'] ?? [],
			static fn ( array $c ): bool => $c['post_id'] === 7700
		);
		$this->assertNotEmpty( $matching );
		$only = array_values( $matching )[0];
		$this->assertFalse( $only['force'], 'Default behaviour is trash (force=false).' );
	}

	public function test_delete_template_force_true_permadeletes(): void {
		( new DeleteTemplate() )->execute( [ 'template_id' => 7700, 'force' => true ] );
		$matching = array_filter(
			$GLOBALS['stonewright_test_deleted_posts'] ?? [],
			static fn ( array $c ): bool => $c['post_id'] === 7700
		);
		$only = array_values( $matching )[0];
		$this->assertTrue( $only['force'] );
		$this->assertArrayNotHasKey( 7700, $GLOBALS['stonewright_test_posts'] );
	}

	public function test_delete_template_rejects_non_template_post(): void {
		$result = ( new DeleteTemplate() )->execute( [ 'template_id' => 7701 ] );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'stonewright_not_a_template', $result->get_error_code() );
	}
}
