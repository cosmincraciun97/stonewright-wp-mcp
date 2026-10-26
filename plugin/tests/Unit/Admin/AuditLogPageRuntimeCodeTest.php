<?php
/**
 * The audit page's View code for php-execute rows.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Admin\AuditLogPage;
use Stonewright\WpMcp\Security\IncidentStore;
use Stonewright\WpMcp\Security\RuntimeCodeHistory;

/**
 * @covers \Stonewright\WpMcp\Admin\AuditLogPage
 */
final class AuditLogPageRuntimeCodeTest extends TestCase {

	private mixed $original_wpdb;

	private string $uploads = '';

	protected function setUp(): void {
		$this->original_wpdb = $GLOBALS['wpdb'] ?? null;
		$this->uploads       = str_replace( '\\', '/', sys_get_temp_dir() ) . '/sw-code-view-' . bin2hex( random_bytes( 5 ) );
		mkdir( $this->uploads, 0777, true );
		$GLOBALS['stonewright_test_upload_dir']      = [
			'basedir' => $this->uploads,
			'baseurl' => 'https://example.test/wp-content/uploads',
			'error'   => false,
		];
		$GLOBALS['stonewright_test_user_caps']       = [ 'manage_options' => true ];
		$GLOBALS['stonewright_test_options']         = [ 'stonewright_mode' => 'development' ];
		$GLOBALS['stonewright_test_current_user_id'] = 7;
		$GLOBALS['stonewright_test_nonce_invalid']   = false;
		$_GET                                        = [];
		$_POST                                       = [];
		IncidentStore::reset_for_tests();
	}

	protected function tearDown(): void {
		$GLOBALS['wpdb'] = $this->original_wpdb;
		unset( $GLOBALS['stonewright_test_upload_dir'] );
		$GLOBALS['stonewright_test_user_caps']       = [];
		$GLOBALS['stonewright_test_options']         = [];
		$GLOBALS['stonewright_test_current_user_id'] = 0;
		$GLOBALS['stonewright_test_nonce_invalid']   = false;
		$_GET                                        = [];
		$_POST                                       = [];
		self::remove_tree( $this->uploads );
	}

	private static function remove_tree( string $path ): void {
		if ( is_link( $path ) || is_file( $path ) ) {
			@unlink( $path );
			return;
		}
		if ( ! is_dir( $path ) ) {
			return;
		}
		foreach ( scandir( $path ) ?: [] as $item ) {
			if ( '.' !== $item && '..' !== $item ) {
				self::remove_tree( $path . '/' . $item );
			}
		}
		@rmdir( $path );
	}

	/**
	 * @param list<array<string, mixed>> $rows
	 */
	private function log_with( array $rows ): void {
		$GLOBALS['wpdb'] = new class( $rows ) {
			public $prefix = 'wp_';

			/** @param list<array<string, mixed>> $rows */
			public function __construct( private array $rows ) {}

			public function prepare( string $query, mixed ...$args ): string {
				return $query;
			}

			public function get_var( string $query = '' ): string|int|null {
				return count( $this->rows );
			}

			/** @return list<array<string, mixed>> */
			public function get_results( string $query, string $output = 'OBJECT' ): array {
				return str_contains( $query, 'stonewright_oauth_clients' ) ? [] : $this->rows;
			}
		};
	}

	/** @return array<string, mixed> */
	private static function row( int $id, string $ability, string $operation_class, string $ref = '' ): array {
		$meta = [ 'operation_class' => $operation_class ];
		if ( '' !== $ref ) {
			$meta['code_ref'] = $ref;
		}
		return [
			'id'              => (string) $id,
			'ability_name'    => $ability,
			'user_id'         => '1',
			'result_status'   => 'ok',
			'operation_class' => $operation_class,
			'sanitized_args'  => (string) json_encode( [ 'code' => '[redacted, length=9, sha256=abcdef12]', '_meta' => $meta ] ),
			'created_at'      => '2026-07-15 10:00:00',
		];
	}

	private function render_log(): string {
		ob_start();
		AuditLogPage::render();
		return (string) ob_get_clean();
	}

	public function test_a_php_execute_row_with_a_kept_copy_offers_view_code_to_an_administrator(): void {
		$kept = RuntimeCodeHistory::keep( 'return 1;' );
		self::assertIsArray( $kept );
		$this->log_with(
			[
				self::row( 12, 'stonewright/php-execute', 'runtime_php', $kept['ref'] ),
				self::row( 11, 'stonewright/content-update', 'content_write' ),
			]
		);

		$html = $this->render_log();

		self::assertSame( 1, substr_count( $html, '>View code<' ), 'one row has a copy' );
		self::assertMatchesRegularExpression( '/<a[^>]*href="[^"]*view_code=' . $kept['ref'] . '[^"]*event=12[^"]*_wpnonce=test-nonce-[a-f0-9]{32}[^"]*"[^>]*>View code<span class="sw-ui-visually-hidden"> of event 12, stonewright\/php-execute<\/span>/', $html );
		self::assertSame( 2, substr_count( $html, 'data-sw-audit-open=' ), 'both rows keep their Details button' );
	}

	public function test_a_php_execute_row_without_a_kept_copy_has_no_view_code(): void {
		$this->log_with( [ self::row( 12, 'stonewright/php-execute', 'runtime_php' ) ] );

		self::assertStringNotContainsString( 'View code', $this->render_log() );
	}

	public function test_a_reference_that_is_not_a_sha256_gets_no_link(): void {
		$this->log_with( [ self::row( 12, 'stonewright/php-execute', 'runtime_php', '../../wp-config' ) ] );

		self::assertStringNotContainsString( 'View code', $this->render_log() );
	}

	public function test_a_row_of_another_operation_class_gets_no_link_even_with_a_reference(): void {
		$this->log_with( [ self::row( 12, 'stonewright/content-update', 'content_write', str_repeat( 'a', 64 ) ) ] );

		self::assertStringNotContainsString( 'View code', $this->render_log() );
	}

	public function test_the_code_view_shows_the_masked_copy_as_text(): void {
		$kept = RuntimeCodeHistory::keep( "\$token = 'abcdefghijklmnop';\necho '<b>x</b>';" );
		self::assertIsArray( $kept );
		$_GET = [ 'view_code' => $kept['ref'], 'event' => '12', '_wpnonce' => 'test-nonce-valid' ];

		$html = $this->render_log();

		self::assertStringContainsString( 'sw-ui-code__body', $html );
		self::assertStringContainsString( '$token = &#039;ab…op&#039;;', $html );
		self::assertStringNotContainsString( 'abcdefghijklmnop', $html );
		self::assertStringContainsString( '&lt;b&gt;x&lt;/b&gt;', $html, 'the code is text, never markup' );
		self::assertStringContainsString( 'Event 12', $html );
		self::assertStringContainsString( 'Back to the audit log', $html );
		self::assertSame( 1, substr_count( $html, '<h1' ) );
		self::assertSame( 'stonewright_view_code_' . $kept['ref'], $GLOBALS['stonewright_test_last_nonce_action'] );
	}

	public function test_the_code_view_refuses_a_link_without_a_valid_nonce(): void {
		$kept = RuntimeCodeHistory::keep( 'return 1;' );
		self::assertIsArray( $kept );
		$_GET = [ 'view_code' => $kept['ref'], '_wpnonce' => 'stale' ];
		$GLOBALS['stonewright_test_nonce_invalid'] = true;

		try {
			$this->render_log();
			self::fail( 'the view must stop' );
		} catch ( \RuntimeException $stop ) {
			self::assertSame( 403, $GLOBALS['stonewright_test_last_wp_die_response'] );
		} finally {
			ob_end_clean();
		}
	}

	public function test_the_code_view_refuses_a_reference_that_is_not_a_sha256(): void {
		$_GET = [ 'view_code' => '../../../wp-config', '_wpnonce' => 'test-nonce-valid' ];

		try {
			$this->render_log();
			self::fail( 'the view must stop' );
		} catch ( \RuntimeException $stop ) {
			self::assertSame( 403, $GLOBALS['stonewright_test_last_wp_die_response'] );
		} finally {
			ob_end_clean();
		}
	}

	public function test_a_user_who_cannot_manage_options_sees_neither_the_log_nor_the_code(): void {
		$kept = RuntimeCodeHistory::keep( 'return "private";' );
		self::assertIsArray( $kept );
		$GLOBALS['stonewright_test_user_caps'] = [ 'manage_options' => false ];
		$_GET                                  = [ 'view_code' => $kept['ref'], '_wpnonce' => 'test-nonce-valid' ];

		try {
			$html = $this->render_log();
		} catch ( \RuntimeException $stop ) {
			$html = '';
			ob_end_clean();
		}

		self::assertStringNotContainsString( 'private', $html );
		self::assertStringNotContainsString( 'sw-ui-code__body', $html );
	}

	public function test_a_copy_that_is_gone_is_said_so_instead_of_shown(): void {
		$_GET = [ 'view_code' => str_repeat( 'b', 64 ), '_wpnonce' => 'test-nonce-valid' ];

		$html = $this->render_log();

		self::assertStringContainsString( 'This copy is no longer kept', $html );
		self::assertStringNotContainsString( 'sw-ui-code__body', $html );
	}
}
