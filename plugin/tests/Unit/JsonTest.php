<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Support\Json;

/**
 * @covers \Stonewright\WpMcp\Support\Json
 */
final class JsonTest extends TestCase {

	public function test_encode_returns_string_for_array(): void {
		$result = Json::encode( [ 'key' => 'value', 'num' => 42 ] );
		$this->assertIsString( $result );
		$this->assertStringContainsString( 'value', $result );
	}

	public function test_decode_returns_array_for_valid_json(): void {
		$result = Json::decode( '{"foo":"bar","baz":1}' );
		$this->assertIsArray( $result );
		$this->assertSame( 'bar', $result['foo'] );
		$this->assertSame( 1, $result['baz'] );
	}

	public function test_decode_returns_empty_array_for_invalid_json(): void {
		$result = Json::decode( 'not valid json {{{{' );
		$this->assertIsArray( $result );
		$this->assertEmpty( $result );
	}

	public function test_hash_is_deterministic_for_same_input(): void {
		$input = [ 'a' => 1, 'b' => 'hello' ];
		$this->assertSame( Json::hash( $input ), Json::hash( $input ) );
	}

	public function test_hash_differs_for_different_input(): void {
		$this->assertNotSame(
			Json::hash( [ 'x' => 1 ] ),
			Json::hash( [ 'x' => 2 ] )
		);
	}
}
