<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Authorization;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Authorization\WordPress\CredentialKeys;
use Stonewright\WpMcp\Authorization\WordPress\Database;
use Stonewright\WpMcp\Tests\Unit\Authorization\WordPress\Fixtures\FakeTables;
use Stonewright\WpMcp\Tests\Unit\Authorization\WordPress\Fixtures\FakeWpdb;
use Stonewright\WpMcp\Tests\Unit\Authorization\WordPress\Fixtures\StorageRig;

/**
 * @covers \Stonewright\WpMcp\Authorization\WordPress\CredentialKeys
 */
final class CredentialKeysLazyConfigurationTest extends TestCase {

	protected function setUp(): void {
		StorageRig::reset_globals();
		CredentialKeys::set_candidate_resolver( null );
		// Built with the real candidates before any test replaces the resolver.
		StorageRig::private_key();
	}

	protected function tearDown(): void {
		CredentialKeys::set_candidate_resolver( null );
		StorageRig::reset_globals();
	}

	private function keys( int &$resolved, ?array &$attempted = null ): CredentialKeys {
		CredentialKeys::set_candidate_resolver(
			static function () use ( &$resolved ): array {
				++$resolved;
				return [ null ];
			}
		);
		return new CredentialKeys(
			new Database( new FakeWpdb( new FakeTables() ) ),
			static function ( ?string $configuration ) use ( &$attempted ): array {
				$attempted[] = $configuration;
				return [ StorageRig::private_key(), '' ];
			},
			static fn ( int $length ): string => str_repeat( "\x01", $length )
		);
	}

	public function test_constructor_does_not_resolve_configuration_candidates(): void {
		$resolved = 0;

		$keys = $this->keys( $resolved );

		self::assertSame( 0, $resolved, 'construction probes nothing' );
		self::assertFalse( $keys->ready() );
		self::assertSame( 0, $resolved, 'reading the stored keys probes nothing' );
	}

	public function test_candidates_are_resolved_once_when_a_key_is_generated(): void {
		$resolved = 0;
		$attempted = [];
		$keys = $this->keys( $resolved, $attempted );

		self::assertTrue( $keys->ensure(), (string) get_option( CredentialKeys::ERROR_OPTION ) );

		self::assertSame( 1, $resolved );
		self::assertSame( [ null ], $attempted );

		self::assertTrue( $keys->ensure(), (string) get_option( CredentialKeys::ERROR_OPTION ) );
		self::assertSame( 1, $resolved, 'stored keys need no candidates' );
	}

	public function test_stored_keys_never_resolve_candidates(): void {
		$resolved = 0;
		$this->keys( $resolved )->ensure();
		$resolved = 0;

		self::assertTrue( $this->keys( $resolved )->ensure() );

		self::assertSame( 0, $resolved );
	}

	public function test_explicit_configurations_skip_the_resolver(): void {
		$resolved = 0;
		CredentialKeys::set_candidate_resolver(
			static function () use ( &$resolved ): array {
				++$resolved;
				return [ null ];
			}
		);
		$keys = new CredentialKeys(
			new Database( new FakeWpdb( new FakeTables() ) ),
			static fn ( ?string $configuration ): array => [ StorageRig::private_key(), '' ],
			static fn ( int $length ): string => str_repeat( "\x01", $length ),
			[ null ]
		);

		self::assertTrue( $keys->ensure(), (string) get_option( CredentialKeys::ERROR_OPTION ) );

		self::assertSame( 0, $resolved );
	}

	public function test_candidates_skip_paths_outside_open_basedir_without_touching_them(): void {
		$touched = [];
		$readable = static function ( string $path ) use ( &$touched ): bool {
			$touched[] = $path;
			return true;
		};
		$allowed = static fn ( string $path ): bool => str_starts_with( $path, '/var/www/site/' );

		$candidates = CredentialKeys::configuration_candidates(
			'/etc/ssl/openssl.cnf',
			'/usr/local/php/bin/php',
			'/var/www/site/plugin/data/openssl/openssl.cnf',
			$readable,
			$allowed
		);

		self::assertSame( [ null, '/var/www/site/plugin/data/openssl/openssl.cnf' ], $candidates );
		self::assertSame( [ '/var/www/site/plugin/data/openssl/openssl.cnf' ], $touched, 'a refused path never reaches the readability probe' );
		self::assertNotContains( '/usr/local/php/bin/extras/ssl/openssl.cnf', $touched );
		self::assertNotContains( '/etc/ssl/openssl.cnf', $touched );
	}

	public function test_candidates_default_to_the_running_open_basedir(): void {
		$touched = [];
		$readable = static function ( string $path ) use ( &$touched ): bool {
			$touched[] = $path;
			return true;
		};

		$candidates = CredentialKeys::configuration_candidates( '/a/openssl.cnf', '/php/php.exe', '/b/openssl.cnf', $readable );

		if ( '' === (string) ini_get( 'open_basedir' ) ) {
			self::assertSame( [ null, '/a/openssl.cnf', '/php/extras/ssl/openssl.cnf', '/b/openssl.cnf' ], $candidates );
		}
		self::assertSame( array_values( array_filter( $candidates ) ), $touched );
	}
}
