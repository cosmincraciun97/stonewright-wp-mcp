<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\ElementorV3;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\ElementorV3\AddContainer;

/**
 * @covers \Stonewright\WpMcp\Abilities\ElementorV3\AddContainer
 */
final class AddContainerTest extends TestCase {

	public function test_schema_only_allows_container_element_type(): void {
		$schema = ( new AddContainer() )->input_schema();

		self::assertSame( [ 'container' ], $schema['properties']['el_type']['enum'] );
	}

	public function test_execute_never_writes_legacy_section_even_when_requested(): void {
		$GLOBALS['stonewright_test_posts'][123] = (object) [
			'ID'           => 123,
			'post_type'    => 'page',
			'post_status'  => 'draft',
			'post_title'   => 'Target',
			'post_content' => '',
			'post_excerpt' => '',
		];
		$GLOBALS['stonewright_test_post_meta_calls'] = [];

		$result = ( new AddContainer() )->execute( [
			'post_id'  => 123,
			'el_type'  => 'section',
			'settings' => [ 'layout' => 'grid' ],
		] );

		self::assertIsArray( $result );
		$data_call = null;
		foreach ( $GLOBALS['stonewright_test_post_meta_calls'] as $call ) {
			if ( '_elementor_data' === $call['meta_key'] ) {
				$data_call = $call;
				break;
			}
		}

		self::assertNotNull( $data_call );
		$tree = json_decode( stripslashes( (string) $data_call['value'] ), true );
		self::assertSame( 'container', $tree[0]['elType'] );
	}

	public function test_execute_sanitizes_container_settings_that_break_flex_output(): void {
		$GLOBALS['stonewright_test_posts'][124] = (object) [
			'ID'           => 124,
			'post_type'    => 'page',
			'post_status'  => 'draft',
			'post_title'   => 'Target',
			'post_content' => '',
			'post_excerpt' => '',
		];
		$GLOBALS['stonewright_test_post_meta_calls'] = [];

		$result = ( new AddContainer() )->execute( [
			'post_id'  => 124,
			'settings' => [
				'layout'       => 'flex',
				'direction'    => 'row',
				'flex_wrap'    => 'wrap',
				'_flex_size'   => 'grow',
				'_flex_grow'   => '1',
				'_flex_shrink' => '0',
			],
		] );

		self::assertIsArray( $result );
		$data_call = null;
		foreach ( $GLOBALS['stonewright_test_post_meta_calls'] as $call ) {
			if ( '_elementor_data' === $call['meta_key'] ) {
				$data_call = $call;
				break;
			}
		}

		self::assertNotNull( $data_call );
		$tree     = json_decode( stripslashes( (string) $data_call['value'] ), true );
		$settings = $tree[0]['settings'];

		self::assertSame( 'flex', $settings['container_type'] );
		self::assertSame( 'row', $settings['flex_direction'] );
		self::assertArrayNotHasKey( 'direction', $settings );
		self::assertArrayNotHasKey( 'flex_wrap', $settings );
		self::assertArrayNotHasKey( '_flex_size', $settings );
		self::assertArrayNotHasKey( '_flex_grow', $settings );
		self::assertArrayNotHasKey( '_flex_shrink', $settings );
	}

	public function test_execute_maps_common_flex_aliases_to_elementor_container_keys(): void {
		$GLOBALS['stonewright_test_posts'][125] = (object) [
			'ID'           => 125,
			'post_type'    => 'page',
			'post_status'  => 'draft',
			'post_title'   => 'Target',
			'post_content' => '',
			'post_excerpt' => '',
		];
		$GLOBALS['stonewright_test_post_meta_calls'] = [];

		$result = ( new AddContainer() )->execute( [
			'post_id'  => 125,
			'settings' => [
				'layout'          => 'flex',
				'justify_content' => 'center',
				'align_items'     => 'stretch',
				'align_content'   => 'space-between',
			],
		] );

		self::assertIsArray( $result );
		$data_call = null;
		foreach ( $GLOBALS['stonewright_test_post_meta_calls'] as $call ) {
			if ( '_elementor_data' === $call['meta_key'] ) {
				$data_call = $call;
				break;
			}
		}

		self::assertNotNull( $data_call );
		$tree     = json_decode( stripslashes( (string) $data_call['value'] ), true );
		$settings = $tree[0]['settings'];

		self::assertArrayHasKey( 'flex_justify_content', $settings );
		self::assertArrayHasKey( 'flex_align_items', $settings );
		self::assertArrayHasKey( 'flex_align_content', $settings );
		self::assertSame( 'center', $settings['flex_justify_content'] );
		self::assertSame( 'stretch', $settings['flex_align_items'] );
		self::assertSame( 'space-between', $settings['flex_align_content'] );
		self::assertArrayNotHasKey( 'justify_content', $settings );
		self::assertArrayNotHasKey( 'align_items', $settings );
		self::assertArrayNotHasKey( 'align_content', $settings );
	}

	public function test_unknown_container_setting_is_rejected_before_backup_or_write(): void {
		$GLOBALS['stonewright_test_posts'][126] = (object) [
			'ID'           => 126,
			'post_type'    => 'page',
			'post_status'  => 'draft',
			'post_title'   => 'Target',
			'post_content' => '',
			'post_excerpt' => '',
		];
		$GLOBALS['stonewright_test_post_meta_calls'] = [];

		$result = ( new AddContainer() )->execute(
			[
				'post_id'  => 126,
				'settings' => [ 'invented_layout_key' => 'bad' ],
			]
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_elementor_settings_invalid', $result->get_error_code() );
		self::assertSame( [], $GLOBALS['stonewright_test_post_meta_calls'] );
	}
}
