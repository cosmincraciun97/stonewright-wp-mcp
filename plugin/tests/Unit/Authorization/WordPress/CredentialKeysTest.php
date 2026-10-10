<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Authorization\WordPress;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Authorization\WordPress\CredentialKeys;
use Stonewright\WpMcp\Authorization\WordPress\Database;
use Stonewright\WpMcp\Tests\Unit\Authorization\WordPress\Fixtures\FakeTables;
use Stonewright\WpMcp\Tests\Unit\Authorization\WordPress\Fixtures\FakeWpdb;
use Stonewright\WpMcp\Tests\Unit\Authorization\WordPress\Fixtures\StorageRig;

/**
 * @covers \Stonewright\WpMcp\Authorization\WordPress\CredentialKeys
 */
final class CredentialKeysTest extends TestCase {

	private static ?string $second_key = null;

	private FakeTables $space;
	private string $log_file = '';
	private string|false $previous_log = false;

	protected function setUp(): void {
		StorageRig::reset_globals();
		$this->space = new FakeTables();
		$this->log_file = (string) tempnam( sys_get_temp_dir(), 'sw-oauth-keys-' );
		$this->previous_log = ini_get( 'error_log' );
		ini_set( 'error_log', $this->log_file );
	}

	protected function tearDown(): void {
		ini_set( 'error_log', (string) $this->previous_log );
		@unlink( $this->log_file );
		StorageRig::reset_globals();
	}

	private static function second_key(): string {
		if ( null === self::$second_key ) {
			[ $pem ] = CredentialKeys::generate_rsa( CredentialKeys::default_configurations() );
			self::$second_key = (string) $pem;
		}
		return self::$second_key;
	}

	private function keys( ?\Closure $rsa = null, ?\Closure $random = null, ?FakeWpdb $wpdb = null ): CredentialKeys {
		return new CredentialKeys(
			new Database( $wpdb ?? new FakeWpdb( $this->space ) ),
			$rsa ?? static fn ( ?string $configuration ): array => [ StorageRig::private_key(), '' ],
			$random ?? static fn ( int $length ): string => str_repeat( "\x01", $length ),
			[ null ]
		);
	}

	public function test_creates_both_keys_once_with_autoload_off(): void {
		$keys = $this->keys();

		self::assertTrue( $keys->ensure() );

		StorageRig::assert_pkcs8_rsa_key( get_option( CredentialKeys::PRIVATE_KEY_OPTION ) );
		$encryption = (string) get_option( CredentialKeys::ENCRYPTION_KEY_OPTION );
		self::assertSame( 44, strlen( $encryption ) );
		self::assertSame( 32, strlen( (string) base64_decode( $encryption, true ) ) );
		self::assertSame( 'off', $this->space->option_autoload[ CredentialKeys::PRIVATE_KEY_OPTION ] );
		self::assertSame( 'off', $this->space->option_autoload[ CredentialKeys::ENCRYPTION_KEY_OPTION ] );
		self::assertFalse( get_option( CredentialKeys::ERROR_OPTION ) );
		self::assertTrue( $keys->ready() );
	}

	public function test_existing_keys_are_never_overwritten(): void {
		StorageRig::seed_keys();
		$before = $GLOBALS['stonewright_test_options'];
		$keys = $this->keys( static function (): array {
			throw new \LogicException( 'No generation may run when keys exist.' );
		}, static function (): string {
			throw new \LogicException( 'No random key may be drawn when keys exist.' );
		} );

		self::assertTrue( $keys->ensure() );
		self::assertSame( $before, $GLOBALS['stonewright_test_options'] );
	}

	public function test_concurrent_activations_end_with_the_first_written_keys(): void {
		$first = new FakeWpdb( $this->space );
		$second_keys = $this->keys( static fn (): array => [ self::second_key(), '' ], static fn ( int $length ): string => str_repeat( "\x02", $length ), new FakeWpdb( $this->space ) );
		$first->before = static function ( string $sql ) use ( $second_keys ): void {
			if ( str_starts_with( $sql, 'INSERT INTO wptests_options' ) && str_contains( $sql, CredentialKeys::PRIVATE_KEY_OPTION ) ) {
				self::assertTrue( $second_keys->ensure() );
			}
		};
		$first_keys = $this->keys( null, null, $first );

		self::assertTrue( $first_keys->ensure() );

		self::assertSame( self::second_key(), get_option( CredentialKeys::PRIVATE_KEY_OPTION ) );
		self::assertSame( base64_encode( str_repeat( "\x02", 32 ) ), get_option( CredentialKeys::ENCRYPTION_KEY_OPTION ) );
		self::assertSame( self::second_key(), $first_keys->private_key() );
		self::assertSame( $second_keys->public_key(), $first_keys->public_key() );
	}

	public function test_configuration_candidates_follow_the_fallback_order(): void {
		$readable = static fn ( string $path ): bool => true;

		self::assertSame(
			[ null, '/env/openssl.cnf', '/php/extras/ssl/openssl.cnf', '/plugin/data/openssl/openssl.cnf' ],
			CredentialKeys::configuration_candidates( '/env/openssl.cnf', '/php/php.exe', '/plugin/data/openssl/openssl.cnf', $readable )
		);
		self::assertSame(
			[ null, '/plugin/data/openssl/openssl.cnf' ],
			CredentialKeys::configuration_candidates( null, '/php/php.exe', '/plugin/data/openssl/openssl.cnf', static fn ( string $path ): bool => ! str_contains( $path, 'extras' ) )
		);
		self::assertSame(
			[ null, '/same/openssl.cnf' ],
			CredentialKeys::configuration_candidates( '/same/openssl.cnf', '/php/php.exe', '/same/openssl.cnf', static fn ( string $path ): bool => ! str_contains( $path, 'extras' ) )
		);
	}

	public function test_generation_tries_each_configuration_until_one_works(): void {
		$tried = [];
		$attempt = static function ( ?string $configuration ) use ( &$tried ): array {
			$tried[] = $configuration;
			return '/third.cnf' === $configuration ? [ StorageRig::private_key(), '' ] : [ null, 'no configuration at ' . ( $configuration ?? 'default' ) ];
		};

		[ $pem, $notes ] = CredentialKeys::generate_rsa( [ null, '/second.cnf', '/third.cnf', '/fourth.cnf' ], $attempt );

		self::assertSame( StorageRig::private_key(), $pem );
		self::assertSame( [ null, '/second.cnf', '/third.cnf' ], $tried );
		self::assertCount( 2, $notes );
	}

	public function test_the_bundled_configuration_can_generate_a_key(): void {
		$bundled = dirname( __DIR__, 4 ) . '/data/openssl/openssl.cnf';
		self::assertFileExists( $bundled );

		[ $pem ] = CredentialKeys::generate_rsa( [ $bundled ] );

		StorageRig::assert_pkcs8_rsa_key( $pem );
	}

	public function test_generation_failure_is_recorded_without_throwing(): void {
		$keys = $this->keys( static fn ( ?string $configuration ): array => [ null, str_repeat( 'error:0E064002:configuration file routines:no such file ', 40 ) ] );

		self::assertFalse( $keys->ensure() );

		self::assertFalse( get_option( CredentialKeys::PRIVATE_KEY_OPTION ) );
		$error = (string) get_option( CredentialKeys::ERROR_OPTION );
		self::assertStringContainsString( 'signing key', $error );
		self::assertLessThanOrEqual( CredentialKeys::ERROR_LIMIT, strlen( $error ) );
		self::assertSame( 'off', $this->space->option_autoload[ CredentialKeys::ERROR_OPTION ] ?? 'off' );
		self::assertFalse( $keys->ready() );
		self::assertSame( $error, $keys->error() );
	}

	public function test_a_throwing_generator_never_escapes_activation(): void {
		$keys = $this->keys( static function (): array {
			throw new \ValueError( 'Synthetic OpenSSL failure.' );
		}, static function (): string {
			throw new \Exception( 'Synthetic entropy failure.' );
		} );

		self::assertFalse( $keys->ensure() );
		self::assertNotFalse( get_option( CredentialKeys::ERROR_OPTION ) );
	}

	public function test_success_clears_a_previous_error(): void {
		update_option( CredentialKeys::ERROR_OPTION, 'Earlier failure.', false );

		self::assertTrue( $this->keys()->ensure() );
		self::assertFalse( get_option( CredentialKeys::ERROR_OPTION ) );
	}

	public function test_an_empty_stored_value_is_filled_once(): void {
		$GLOBALS['stonewright_test_options'][ CredentialKeys::ENCRYPTION_KEY_OPTION ] = '';

		self::assertTrue( $this->keys()->ensure() );
		self::assertSame( base64_encode( str_repeat( "\x01", 32 ) ), get_option( CredentialKeys::ENCRYPTION_KEY_OPTION ) );
	}

	public function test_an_unusable_stored_key_is_reported_and_left_in_place(): void {
		$GLOBALS['stonewright_test_options'][ CredentialKeys::PRIVATE_KEY_OPTION ] = 'not a key';
		$keys = $this->keys( static function (): array {
			throw new \LogicException( 'A present key is never replaced.' );
		} );

		self::assertFalse( $keys->ensure() );
		self::assertSame( 'not a key', get_option( CredentialKeys::PRIVATE_KEY_OPTION ) );
		self::assertNull( $keys->private_key() );
		self::assertStringContainsString( 'unusable', (string) get_option( CredentialKeys::ERROR_OPTION ) );
	}

	public function test_public_key_is_derived_from_the_stored_private_key(): void {
		StorageRig::seed_keys();
		$expected = openssl_pkey_get_details( openssl_pkey_get_private( StorageRig::private_key() ) )['key'];

		self::assertSame( $expected, $this->keys()->public_key() );
		self::assertSame( base64_encode( str_repeat( "\x5a", 32 ) ), $this->keys()->encryption_key() );
	}
}
