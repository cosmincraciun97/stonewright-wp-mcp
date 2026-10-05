<?php
/**
 * SPDX-FileCopyrightText: 2026 Stonewright contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * @package Stonewright\WpMcp
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\OAuth;

use League\OAuth2\Server\Exception\OAuthServerException;
use PDO;
use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\OAuth\Repositories\RefreshTokenRepository;

/**
 * Concurrent MCP client processes share one refresh token. A second use of a
 * just-rotated token must not revoke the whole grant family, while genuine
 * replays still do.
 */
final class RefreshReuseGraceTest extends TestCase {

	private mixed $original_wpdb;

	private PDO $pdo;

	protected function setUp(): void {
		if ( ! extension_loaded( 'pdo_sqlite' ) ) {
			self::markTestSkipped( 'pdo_sqlite is required for the refresh-token table double.' );
		}
		$this->original_wpdb = $GLOBALS['wpdb'] ?? null;
		unset( $GLOBALS['stonewright_test_filters']['stonewright_oauth_refresh_reuse_grace_seconds'] );

		$this->pdo = new PDO( 'sqlite::memory:' );
		$this->pdo->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
		$this->pdo->exec(
			'CREATE TABLE wp_stonewright_oauth_refresh_tokens (
				identifier_hash TEXT PRIMARY KEY, access_token_hash TEXT NOT NULL, grant_family_hash TEXT NOT NULL,
				client_id TEXT, user_id INTEGER, parent_identifier_hash TEXT, family_expires_at TEXT,
				consumed_at TEXT, revoked_reason TEXT, expires_at TEXT NOT NULL, revoked INTEGER NOT NULL DEFAULT 0
			)'
		);
		$this->pdo->exec( 'CREATE TABLE wp_stonewright_oauth_access_tokens ( identifier_hash TEXT PRIMARY KEY, revoked INTEGER NOT NULL DEFAULT 0 )' );
		$GLOBALS['wpdb'] = new SqliteWpdbDouble( $this->pdo );
	}

	protected function tearDown(): void {
		$GLOBALS['wpdb'] = $this->original_wpdb;
		unset( $GLOBALS['stonewright_test_filters']['stonewright_oauth_refresh_reuse_grace_seconds'] );
	}

	public function test_second_use_moments_after_rotation_keeps_the_family(): void {
		$this->seed( 'r1', null );
		$this->rotate( 'r1', 'r2' );

		$sibling = new RefreshTokenRepository();
		self::assertFalse( $sibling->isRefreshTokenRevoked( 'r1' ) );
		$sibling->revokeRefreshToken( 'r1' );

		self::assertSame( 'reused_within_grace', $sibling->last_revoked_reason() );
		self::assertSame( 0, $this->revoked_count( 'replayed' ) );
		self::assertSame( 0, (int) $this->row( 'r2' )['revoked'] );
	}

	public function test_claim_race_between_validation_and_rotation_is_not_a_replay(): void {
		$this->seed( 'r1', null );
		$late = new RefreshTokenRepository();
		self::assertFalse( $late->isRefreshTokenRevoked( 'r1' ) );

		// Another process rotates between this request's validation and claim.
		$this->rotate( 'r1', 'r2' );

		$late->revokeRefreshToken( 'r1' );
		self::assertSame( 0, $this->revoked_count( 'replayed' ) );
	}

	public function test_reuse_after_the_grace_window_still_revokes_the_family(): void {
		$this->seed( 'r1', null );
		$this->rotate( 'r1', 'r2' );
		$this->age_consumption( 'r1', 120 );

		self::assertTrue( ( new RefreshTokenRepository() )->isRefreshTokenRevoked( 'r1' ) );
		self::assertSame( 1, (int) $this->row( 'r2' )['revoked'] );
		self::assertSame( 'replayed', $this->row( 'r2' )['revoked_reason'] );
	}

	public function test_reuse_after_the_child_was_used_is_a_replay(): void {
		$this->seed( 'r1', null );
		$this->rotate( 'r1', 'r2' );
		$this->rotate( 'r2', 'r3' );

		self::assertTrue( ( new RefreshTokenRepository() )->isRefreshTokenRevoked( 'r1' ) );
		self::assertSame( 1, (int) $this->row( 'r3' )['revoked'] );
	}

	public function test_revoked_token_is_never_reusable(): void {
		$this->seed( 'r1', null );
		$this->pdo->exec( "UPDATE wp_stonewright_oauth_refresh_tokens SET revoked = 1, revoked_reason = 'revoked', consumed_at = '" . gmdate( 'Y-m-d H:i:s' ) . "'" );

		self::assertTrue( ( new RefreshTokenRepository() )->isRefreshTokenRevoked( 'r1' ) );
	}

	public function test_grace_can_be_disabled(): void {
		$GLOBALS['stonewright_test_filters']['stonewright_oauth_refresh_reuse_grace_seconds'] = static fn(): int => 0;
		$this->seed( 'r1', null );
		$this->rotate( 'r1', 'r2' );

		$repository = new RefreshTokenRepository();
		$this->expectException( OAuthServerException::class );
		$repository->revokeRefreshToken( 'r1' );
	}

	public function test_grace_is_capped(): void {
		$GLOBALS['stonewright_test_filters']['stonewright_oauth_refresh_reuse_grace_seconds'] = static fn(): int => 86400;

		self::assertSame( RefreshTokenRepository::REUSE_GRACE_MAX_SECONDS, RefreshTokenRepository::reuse_grace_seconds() );
	}

	private function seed( string $token, ?string $parent ): void {
		$expiry = gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS );
		$insert = $this->pdo->prepare(
			'INSERT INTO wp_stonewright_oauth_refresh_tokens
			(identifier_hash, access_token_hash, grant_family_hash, client_id, user_id, parent_identifier_hash, family_expires_at, expires_at, revoked)
			VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0)'
		);
		$insert->execute( [ hash( 'sha256', $token ), 'access-' . $token, 'family-one', 'client-a', 1, null === $parent ? null : hash( 'sha256', $parent ), $expiry, $expiry ] );
	}

	private function rotate( string $from, string $to ): void {
		$repository = new RefreshTokenRepository();
		self::assertFalse( $repository->isRefreshTokenRevoked( $from ) );
		$repository->revokeRefreshToken( $from );
		$this->seed( $to, $from );
	}

	private function age_consumption( string $token, int $seconds ): void {
		$update = $this->pdo->prepare( 'UPDATE wp_stonewright_oauth_refresh_tokens SET consumed_at = ? WHERE identifier_hash = ?' );
		$update->execute( [ gmdate( 'Y-m-d H:i:s', time() - $seconds ), hash( 'sha256', $token ) ] );
	}

	/** @return array<string, mixed> */
	private function row( string $token ): array {
		$select = $this->pdo->prepare( 'SELECT * FROM wp_stonewright_oauth_refresh_tokens WHERE identifier_hash = ?' );
		$select->execute( [ hash( 'sha256', $token ) ] );
		return (array) $select->fetch( PDO::FETCH_ASSOC );
	}

	private function revoked_count( string $reason ): int {
		$select = $this->pdo->prepare( 'SELECT COUNT(*) FROM wp_stonewright_oauth_refresh_tokens WHERE revoked_reason = ?' );
		$select->execute( [ $reason ] );
		return (int) $select->fetchColumn();
	}
}

/**
 * Minimal $wpdb backed by SQLite for repository-level OAuth tests.
 */
final class SqliteWpdbDouble {

	public string $prefix = 'wp_';

	public function __construct( private PDO $pdo ) {}

	public function prepare( string $query, mixed ...$args ): string {
		foreach ( $args as $arg ) {
			$query = (string) preg_replace( '/%[sd]/', str_replace( '\\', '\\\\', $this->pdo->quote( (string) $arg ) ), $query, 1 );
		}
		return $query;
	}

	public function query( string $query ): int {
		return (int) $this->pdo->exec( $query );
	}

	public function get_var( string $query ): mixed {
		$value = $this->pdo->query( $query )->fetchColumn();
		return false === $value ? null : $value;
	}

	/** @return array<string, mixed>|null */
	public function get_row( string $query, mixed $output = null ): ?array {
		unset( $output );
		$row = $this->pdo->query( $query )->fetch( PDO::FETCH_ASSOC );
		return is_array( $row ) ? $row : null;
	}

	/** @return list<mixed> */
	public function get_col( string $query ): array {
		return $this->pdo->query( $query )->fetchAll( PDO::FETCH_COLUMN );
	}

	/**
	 * @param array<string, mixed> $data
	 * @param array<string, mixed> $where
	 */
	public function update( string $table, array $data, array $where ): int {
		$set    = implode( ', ', array_map( static fn( string $column ): string => $column . ' = ?', array_keys( $data ) ) );
		$filter = implode( ' AND ', array_map( static fn( string $column ): string => $column . ' = ?', array_keys( $where ) ) );
		$update = $this->pdo->prepare( "UPDATE {$table} SET {$set} WHERE {$filter}" );
		$update->execute( array_merge( array_values( $data ), array_values( $where ) ) );
		return $update->rowCount();
	}
}
