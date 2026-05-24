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
}
