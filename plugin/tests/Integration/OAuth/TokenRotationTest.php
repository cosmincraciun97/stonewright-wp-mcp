<?php
/**
 * SPDX-FileCopyrightText: 2026 Stonewright contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * @package Stonewright\WpMcp
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Integration\OAuth;

use League\OAuth2\Server\Exception\OAuthServerException;
use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\OAuth\Repositories\AuthCodeRepository;
use Stonewright\WpMcp\OAuth\Repositories\RefreshTokenRepository;

final class TokenRotationTest extends TestCase {

	private mixed $original_wpdb;

	protected function setUp(): void {
		$this->original_wpdb = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb']     = new class() {
			public $prefix = 'wp_';

			/**
			 * @var array<string, bool>
			 */
			private array $claimed = [];

			/**
			 * @param array<string, mixed> $data  Updated values.
			 * @param array<string, mixed> $where Match conditions.
			 */
			public function update( string $table, array $data, array $where ): int {
				if ( 1 !== (int) ( $data['revoked'] ?? 0 ) || 0 !== (int) ( $where['revoked'] ?? -1 ) ) {
					return 0;
				}

				$key = $table . ':' . (string) ( $where['identifier_hash'] ?? '' );
				if ( true === ( $this->claimed[ $key ] ?? false ) ) {
					return 0;
				}

				$this->claimed[ $key ] = true;
				return 1;
			}

			public function prepare( string $query, mixed ...$args ): string {
				foreach ( $args as $arg ) {
					$query = preg_replace( '/%[sd]/', "'" . addslashes( (string) $arg ) . "'", $query, 1 ) ?? $query;
				}
				return $query;
			}

			public function query( string $query ): int {
				if ( ! str_contains( $query, 'consumed_at' ) || ! preg_match( "/identifier_hash = '([^']+)'/", $query, $matches ) ) {
					if ( preg_match( "/grant_family_hash = '([^']+)'/", $query ) ) {
						return 1;
					}
					return 0;
				}
				$key = 'wp_stonewright_oauth_refresh_tokens:' . $matches[1];
				if ( true === ( $this->claimed[ $key ] ?? false ) ) {
					return 0;
				}
				$this->claimed[ $key ] = true;
				return 1;
			}

			/** @return array<string, mixed>|null */
			public function get_row( string $query, mixed $output = null ): ?array {
				unset( $output, $query );
				return [ 'grant_family_hash' => 'family-test' ];
			}

			public function get_col( string $query ): array {
				unset( $query );
				return [];
			}
		};
	}

	protected function tearDown(): void {
		$GLOBALS['wpdb'] = $this->original_wpdb;
	}

	public function test_authorization_code_can_be_redeemed_once(): void {
		$repository = new AuthCodeRepository();

		$repository->revokeAuthCode( 'authorization-code' );

		$this->assert_reuse_is_invalid_grant(
			static fn() => $repository->revokeAuthCode( 'authorization-code' )
		);
	}

	public function test_refresh_token_rotates_once(): void {
		$repository = new RefreshTokenRepository();

		$repository->revokeRefreshToken( 'refresh-token' );

		$this->assert_reuse_is_invalid_grant(
			static fn() => $repository->revokeRefreshToken( 'refresh-token' )
		);
	}

	private function assert_reuse_is_invalid_grant( callable $reuse ): void {
		try {
			$reuse();
			self::fail( 'Token reuse was accepted.' );
		} catch ( OAuthServerException $exception ) {
			self::assertSame( 'invalid_grant', $exception->getErrorType() );
		}
	}
}
