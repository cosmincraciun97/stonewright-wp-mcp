<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\Security\IssueConfirmationToken;
use Stonewright\WpMcp\Abilities\Site\BackupPage;
use Stonewright\WpMcp\Security\AuditLog;
use Stonewright\WpMcp\Security\IncidentStore;

/**
 * site-backup-page and security-issue-confirmation-token leave an audit row on every call, and the row
 * never holds the token it issued.
 *
 * @covers \Stonewright\WpMcp\Abilities\Site\BackupPage
 * @covers \Stonewright\WpMcp\Abilities\Security\IssueConfirmationToken
 */
final class PlainWriteAuditRowsTest extends TestCase {

	private const POST_ID = 101;

	protected function setUp(): void {
		$GLOBALS['stonewright_test_user_caps']       = [ 'read' => true, 'manage_options' => true, 'edit_post' => true, 'edit_posts' => true ];
		$GLOBALS['stonewright_test_user_logged_in']  = true;
		$GLOBALS['stonewright_test_current_user_id'] = 17;
		$GLOBALS['stonewright_test_wpdb_inserts']    = [];
		$GLOBALS['stonewright_test_options']         = [ 'stonewright_mode' => 'development' ];
		$GLOBALS['stonewright_test_transients']      = [];
		$GLOBALS['stonewright_test_posts']           = [
			self::POST_ID => (object) [
				'ID'           => self::POST_ID,
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => 'Audit fixture',
				'post_content' => 'Hello',
				'post_excerpt' => '',
				'post_parent'  => 0,
				'post_name'    => 'audit-fixture',
				'meta'         => [],
			],
		];
		AuditLog::reset_request_state();
		IncidentStore::reset_for_tests();
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_user_caps']    = [];
		$GLOBALS['stonewright_test_wpdb_inserts'] = [];
		$GLOBALS['stonewright_test_options']      = [];
		$GLOBALS['stonewright_test_transients']   = [];
		$GLOBALS['stonewright_test_posts']        = [];
		AuditLog::reset_request_state();
		IncidentStore::reset_for_tests();
	}

	public function test_a_page_backup_writes_an_audit_row(): void {
		$result = ( new BackupPage() )->execute( [ 'post_id' => self::POST_ID ] );

		self::assertIsArray( $result );
		self::assertNotSame( '', $result['snapshot_id'] );
		$row = $this->audit_row( 'stonewright/site-backup-page' );
		self::assertSame( 'ok', $row['result_status'] );
		self::assertSame( 17, (int) $row['user_id'] );
	}

	public function test_issuing_a_token_writes_an_audit_row_without_the_token(): void {
		$result = ( new IssueConfirmationToken() )->execute( [ 'ability' => 'stonewright/memory-delete', 'args' => [ 'id' => 5 ] ] );

		self::assertIsArray( $result );
		self::assertNotSame( '', $result['token'] );
		$row = $this->audit_row( 'stonewright/security-issue-confirmation-token' );
		self::assertSame( 'ok', $row['result_status'] );
		self::assertStringNotContainsString( (string) $result['token'], (string) json_encode( $row ) );
	}

	public function test_issuing_a_token_needs_no_token_itself_in_production_safe_mode(): void {
		$GLOBALS['stonewright_test_options']['stonewright_mode'] = 'production-safe';

		$result = ( new IssueConfirmationToken() )->execute( [ 'ability' => 'stonewright/memory-delete', 'args' => [ 'id' => 5 ] ] );

		self::assertIsArray( $result );
		self::assertArrayHasKey( 'token', $result );
		$this->audit_row( 'stonewright/security-issue-confirmation-token' );
	}

	/** @return array<string, mixed> */
	private function audit_row( string $ability ): array {
		foreach ( array_reverse( $GLOBALS['stonewright_test_wpdb_inserts'] ) as $insert ) {
			if ( str_contains( (string) $insert['table'], 'stonewright_audit_log' ) && $ability === ( $insert['data']['ability_name'] ?? '' ) ) {
				return $insert['data'];
			}
		}
		self::fail( 'No audit row recorded for ' . $ability );
	}
}
