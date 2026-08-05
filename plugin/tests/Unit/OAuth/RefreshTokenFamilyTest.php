<?php
/**
 * SPDX-FileCopyrightText: 2026 Stonewright contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * @package Stonewright\WpMcp
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\OAuth;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\OAuth\Repositories\AccessTokenEntity;
use Stonewright\WpMcp\OAuth\Repositories\RefreshTokenEntity;
use Stonewright\WpMcp\OAuth\Repositories\RefreshTokenRepository;

require_once dirname( __DIR__, 3 ) . '/includes/OAuth/Repositories/AccessTokenRepository.php';

final class RefreshTokenFamilyTest extends TestCase {

	private mixed $original_wpdb;

	protected function setUp(): void {
		$this->original_wpdb = $GLOBALS['wpdb'] ?? null;
	}

	protected function tearDown(): void {
		$GLOBALS['wpdb'] = $this->original_wpdb;
	}

	public function test_replayed_refresh_revokes_entire_grant_family(): void {
		$replayed_hash = hash( 'sha256', 'replayed-refresh' );
		$current_hash  = hash( 'sha256', 'current-refresh' );
		$database      = new class( $replayed_hash, $current_hash ) {
			public string $prefix = 'wp_';

			/** @var array<string, array{revoked:int,expires_at:string,grant_family_hash:string,access_token_hash:string}> */
			public array $refresh_rows;

			/** @var array<string, int> */
			public array $access_rows = [
				'access-old'     => 0,
				'access-current' => 0,
			];

			public function __construct( string $replayed_hash, string $current_hash ) {
				$this->refresh_rows = [
					$replayed_hash => [
						'revoked'           => 1,
						'expires_at'         => '2099-01-01 00:00:00',
						'grant_family_hash'  => 'family-one',
						'access_token_hash'  => 'access-old',
					],
					$current_hash => [
						'revoked'           => 0,
						'expires_at'         => '2099-01-01 00:00:00',
						'grant_family_hash'  => 'family-one',
						'access_token_hash'  => 'access-current',
					],
				];
			}

			public function prepare( string $query, mixed ...$args ): string {
				foreach ( $args as $arg ) {
					$query = preg_replace( '/%[sd]/', "'" . addslashes( (string) $arg ) . "'", $query, 1 ) ?? $query;
				}
				return $query;
			}

			/** @return array<string, mixed>|null */
			public function get_row( string $query, mixed $output = null ): ?array {
				unset( $output );
				if ( 1 !== preg_match( "/identifier_hash = '([^']+)'/", $query, $matches ) ) {
					return null;
				}
				return $this->refresh_rows[ $matches[1] ] ?? null;
			}

			/** @return list<string> */
			public function get_col( string $query ): array {
				if ( 1 !== preg_match( "/grant_family_hash = '([^']+)'/", $query, $matches ) ) {
					return [];
				}
				$hashes = [];
				foreach ( $this->refresh_rows as $row ) {
					if ( $row['grant_family_hash'] === $matches[1] ) {
						$hashes[] = $row['access_token_hash'];
					}
				}
				return $hashes;
			}

			/** @param array<string, mixed> $data @param array<string, mixed> $where */
			public function update( string $table, array $data, array $where ): int {
				$changed = 0;
				if ( str_ends_with( $table, 'refresh_tokens' ) ) {
					foreach ( $this->refresh_rows as &$row ) {
						if ( isset( $where['grant_family_hash'] ) && $row['grant_family_hash'] !== $where['grant_family_hash'] ) {
							continue;
						}
						$row = array_merge( $row, $data );
						++$changed;
					}
					unset( $row );
					return $changed;
				}
				if ( str_ends_with( $table, 'access_tokens' ) ) {
					$hash = (string) ( $where['identifier_hash'] ?? '' );
					if ( array_key_exists( $hash, $this->access_rows ) ) {
						$this->access_rows[ $hash ] = (int) ( $data['revoked'] ?? 0 );
						return 1;
					}
				}
				return 0;
			}
		};
		$GLOBALS['wpdb'] = $database;

		self::assertTrue( ( new RefreshTokenRepository() )->isRefreshTokenRevoked( 'replayed-refresh' ) );
		self::assertSame( 1, $database->refresh_rows[ $current_hash ]['revoked'] );
		self::assertSame( [ 'access-old' => 1, 'access-current' => 1 ], $database->access_rows );
	}

	public function test_rotated_refresh_inherits_the_active_grant_family(): void {
		$current_hash = hash( 'sha256', 'current-refresh' );
		$database     = new class( $current_hash ) {
			public string $prefix = 'wp_';

			/** @var array<string, mixed> */
			public array $inserted = [];

			public function __construct( private readonly string $current_hash ) {
			}

			public function prepare( string $query, mixed ...$args ): string {
				foreach ( $args as $arg ) {
					$query = preg_replace( '/%[sd]/', "'" . addslashes( (string) $arg ) . "'", $query, 1 ) ?? $query;
				}
				return $query;
			}

			/** @return array<string, mixed>|null */
			public function get_row( string $query, mixed $output = null ): ?array {
				unset( $output );
				if ( ! str_contains( $query, $this->current_hash ) ) {
					return null;
				}
				return [
					'revoked'          => 0,
					'expires_at'        => '2099-01-01 00:00:00',
					'grant_family_hash' => 'family-one',
				];
			}

			/** @param array<string, mixed> $data */
			public function insert( string $table, array $data ): int {
				unset( $table );
				$this->inserted = $data;
				return 1;
			}
		};
		$GLOBALS['wpdb'] = $database;
		$repository      = new RefreshTokenRepository();

		self::assertFalse( $repository->isRefreshTokenRevoked( 'current-refresh' ) );

		$access = new AccessTokenEntity();
		$access->setIdentifier( 'next-access' );
		$refresh = new RefreshTokenEntity();
		$refresh->setIdentifier( 'next-refresh' );
		$refresh->setAccessToken( $access );
		$refresh->setExpiryDateTime( new DateTimeImmutable( '2099-01-02 00:00:00 UTC' ) );
		$repository->persistNewRefreshToken( $refresh );

		self::assertSame( 'family-one', $database->inserted['grant_family_hash'] ?? null );
	}
}
