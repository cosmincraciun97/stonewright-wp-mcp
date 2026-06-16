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
}
