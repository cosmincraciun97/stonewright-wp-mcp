<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\OAuth;

use League\OAuth2\Server\CryptKey;
use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\OAuth\Keys;

final class KeysTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_options'] = [];
	}

	public function test_keys_are_generated_once_and_stored_in_stonewright_options(): void {
		$keys = Keys::get();

		self::assertInstanceOf( CryptKey::class, $keys['private'] );
		self::assertInstanceOf( CryptKey::class, $keys['public'] );
		self::assertNotSame( '', $keys['encryption'] );
		self::assertArrayHasKey( Keys::PRIVATE_KEY_OPTION, $GLOBALS['stonewright_test_options'] );
		self::assertArrayHasKey( Keys::ENCRYPTION_KEY_OPTION, $GLOBALS['stonewright_test_options'] );

		$first_private = $GLOBALS['stonewright_test_options'][ Keys::PRIVATE_KEY_OPTION ];
		Keys::get();
		self::assertSame( $first_private, $GLOBALS['stonewright_test_options'][ Keys::PRIVATE_KEY_OPTION ] );
	}
}
