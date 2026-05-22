<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\ThemeBuilder;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\ThemeBuilder\CreateTemplate;
use Stonewright\WpMcp\ThemeBuilder\TemplateStore;

/**
 * @covers \Stonewright\WpMcp\Abilities\ThemeBuilder\CreateTemplate
 * @covers \Stonewright\WpMcp\ThemeBuilder\TemplateStore
 */
final class CreateTemplateTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_post_meta_calls'] = [];
		$GLOBALS['stonewright_test_next_post_id']    = 5001;
		$GLOBALS['stonewright_test_user_caps']       = [ 'edit_posts' => true ];
		$GLOBALS['stonewright_test_user_logged_in']  = true;
	}

	public function test_name_uses_stonewright_prefix(): void {
		$this->assertSame(
			'stonewright/theme-builder-create-template',
			( new CreateTemplate() )->name()
		);
	}

	public function test_category_is_theme_builder(): void {
		$this->assertSame( 'theme-builder', ( new CreateTemplate() )->category() );
	}

	public function test_schema_requires_title_and_template_type(): void {
		$schema = ( new CreateTemplate() )->input_schema();
		$this->assertContains( 'title', $schema['required'] );
		$this->assertContains( 'template_type', $schema['required'] );
		$this->assertFalse( $schema['additionalProperties'] );
	}

	public function test_schema_template_type_enum_covers_every_theme_builder_type(): void {
		$schema   = ( new CreateTemplate() )->input_schema();
		$allowed  = $schema['properties']['template_type']['enum'];
		$required = [
			'header', 'footer',
			'single', 'single-post', 'single-page',
			'archive', 'search-results', 'error-404',
			'loop-item',
		];
		foreach ( $required as $type ) {
			$this->assertContains( $type, $allowed, "template_type enum must include {$type}" );
		}
	}

	public function test_output_schema_returns_template_id_and_template_type(): void {
		$schema = ( new CreateTemplate() )->output_schema();
		$this->assertContains( 'template_id', $schema['required'] );
		$this->assertContains( 'template_type', $schema['required'] );
	}

	public function test_execute_creates_elementor_library_post_with_template_type_meta(): void {
		$result = ( new CreateTemplate() )->execute(
			[
				'title'         => 'Site Header',
				'template_type' => 'header',
			]
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'header', $result['template_type'] );
		$this->assertIsInt( $result['template_id'] );
		$this->assertGreaterThanOrEqual( 5001, $result['template_id'] );

		$id          = $result['template_id'];
		$inserted    = $GLOBALS['stonewright_test_inserted_posts'] ?? [];
		$ours        = array_filter( $inserted, static fn ( array $p ): bool => (int) $p['ID'] === $id );
		$this->assertNotEmpty( $ours, 'wp_insert_post was not invoked for the new template.' );
		$post = array_values( $ours )[0];
		$this->assertSame( 'elementor_library', $post['post_type'] );
		$this->assertSame( 'publish', $post['post_status'] );
		$this->assertSame( 'Site Header', $post['post_title'] );

		$meta_keys = array_map(
			static fn ( array $call ): string => (string) $call['meta_key'],
			array_filter(
				$GLOBALS['stonewright_test_post_meta_calls'],
				static fn ( array $call ): bool => (int) $call['post_id'] === $id && 'update' === $call['action']
			)
		);
		$this->assertContains( '_elementor_template_type', $meta_keys );
		$this->assertContains( '_elementor_edit_mode', $meta_keys );
		$this->assertContains( '_elementor_data', $meta_keys );
	}

	public function test_execute_rejects_invalid_template_type_via_template_store(): void {
		$result = ( new CreateTemplate() )->execute(
			[
				'title'         => 'Bad',
				'template_type' => 'not-a-real-type',
			]
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'stonewright_invalid_template_type', $result->get_error_code() );
	}

	public function test_template_store_is_allowed_type_rejects_unknown_strings(): void {
		$this->assertTrue( TemplateStore::is_allowed_type( 'header' ) );
		$this->assertTrue( TemplateStore::is_allowed_type( 'loop-item' ) );
		$this->assertFalse( TemplateStore::is_allowed_type( '' ) );
		$this->assertFalse( TemplateStore::is_allowed_type( 'HEADER' ) );
		$this->assertFalse( TemplateStore::is_allowed_type( 'sidebar' ) );
	}

	public function test_template_store_create_returns_wp_error_for_unknown_type(): void {
		$err = TemplateStore::create( 'X', 'sidebar' );
		$this->assertInstanceOf( \WP_Error::class, $err );
		$data = $err->get_error_data();
		$this->assertIsArray( $data );
		$this->assertSame( 400, $data['status'] );
		$this->assertContains( 'header', $data['allowed'] );
	}

	public function test_template_store_set_conditions_writes_meta(): void {
		$rules = [
			[ 'type' => 'include', 'name' => 'general', 'sub_name' => 'site' ],
		];
		$ok = TemplateStore::set_conditions( 5050, $rules );
		$this->assertTrue( $ok );
		$matching = array_filter(
			$GLOBALS['stonewright_test_post_meta_calls'],
			static fn ( array $c ): bool => (int) $c['post_id'] === 5050 && '_elementor_conditions' === $c['meta_key']
		);
		$this->assertNotEmpty( $matching );
	}
}
