<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Authorization\WordPress;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Authorization\WordPress\CredentialKeys;
use Stonewright\WpMcp\Authorization\WordPress\Database;
use Stonewright\WpMcp\Authorization\WordPress\KeyRecoveryNotice;
use Stonewright\WpMcp\Tests\Unit\Authorization\WordPress\Fixtures\FakeTables;
use Stonewright\WpMcp\Tests\Unit\Authorization\WordPress\Fixtures\FakeWpdb;
use Stonewright\WpMcp\Tests\Unit\Authorization\WordPress\Fixtures\StorageRig;

/**
 * @covers \Stonewright\WpMcp\Authorization\WordPress\KeyRecoveryNotice
 */
final class KeyRecoveryNoticeTest extends TestCase {

	private bool $generation_works = false;
	private string $log_file = '';
	private string|false $previous_log = false;

	protected function setUp(): void {
		StorageRig::reset_globals();
		$_POST = [];
		$GLOBALS['stonewright_test_user_caps'] = [ 'manage_options' => true ];
		$this->log_file = (string) tempnam( sys_get_temp_dir(), 'sw-oauth-notice-' );
		$this->previous_log = ini_get( 'error_log' );
		ini_set( 'error_log', $this->log_file );
	}

	protected function tearDown(): void {
		$_POST = [];
		ini_set( 'error_log', (string) $this->previous_log );
		@unlink( $this->log_file );
		StorageRig::reset_globals();
	}

	private function notice(): KeyRecoveryNotice {
		$keys = new CredentialKeys(
			new Database( new FakeWpdb( new FakeTables() ) ),
			fn ( ?string $configuration ): array => $this->generation_works ? [ StorageRig::private_key(), '' ] : [ null, 'no configuration' ],
			static fn ( int $length ): string => str_repeat( "\x03", $length ),
			[ null ]
		);
		return new KeyRecoveryNotice( $keys );
	}

	private function render( KeyRecoveryNotice $notice ): string {
		ob_start();
		$notice->render();
		return (string) ob_get_clean();
	}

	public function test_administrators_see_the_error_and_a_nonce_protected_retry(): void {
		update_option( CredentialKeys::ERROR_OPTION, 'OpenSSL <b>failed</b> & stopped', false );

		$html = $this->render( $this->notice() );

		self::assertStringContainsString( 'notice-error', $html );
		self::assertStringContainsString( 'Application Passwords', $html );
		self::assertStringContainsString( 'OpenSSL &lt;b&gt;failed&lt;/b&gt; &amp; stopped', $html );
		self::assertStringNotContainsString( '<b>failed</b>', $html );
		self::assertStringContainsString( 'method="post"', $html );
		self::assertStringContainsString( 'https://example.test/wp-admin/admin-post.php', $html );
		self::assertStringContainsString( 'name="action" value="' . KeyRecoveryNotice::ACTION . '"', $html );
		self::assertStringContainsString( 'name="_wpnonce"', $html );
	}

	public function test_missing_keys_without_a_recorded_error_still_offer_the_retry(): void {
		self::assertStringContainsString( KeyRecoveryNotice::ACTION, $this->render( $this->notice() ) );
	}

	public function test_nothing_is_shown_without_manage_options_or_when_keys_are_ready(): void {
		update_option( CredentialKeys::ERROR_OPTION, 'Failure', false );
		$GLOBALS['stonewright_test_user_caps'] = [ 'read' => true ];
		self::assertSame( '', $this->render( $this->notice() ) );

		$GLOBALS['stonewright_test_user_caps'] = [ 'manage_options' => true ];
		delete_option( CredentialKeys::ERROR_OPTION );
		StorageRig::seed_keys();
		self::assertSame( '', $this->render( $this->notice() ) );
	}

	public function test_retry_requires_manage_options(): void {
		$GLOBALS['stonewright_test_user_caps'] = [ 'read' => true ];
		$_POST['_wpnonce'] = 'nonce';

		$this->expectException( \RuntimeException::class );
		$this->notice()->retry();
	}

	public function test_retry_requires_a_valid_nonce(): void {
		$GLOBALS['stonewright_test_nonce_invalid'] = true;
		$_POST['_wpnonce'] = 'forged';
		$this->generation_works = true;

		try {
			$this->notice()->retry();
			self::fail( 'A forged retry must stop.' );
		} catch ( \RuntimeException $stopped ) {
			self::assertStringContainsString( 'wp_die', $stopped->getMessage() );
		}
		self::assertFalse( get_option( CredentialKeys::PRIVATE_KEY_OPTION ) );
	}

	public function test_successful_retry_creates_keys_and_clears_the_error(): void {
		update_option( CredentialKeys::ERROR_OPTION, 'Failure', false );
		$_POST['_wpnonce'] = 'nonce';
		$this->generation_works = true;

		$location = $this->notice()->retry();

		self::assertStringContainsString( KeyRecoveryNotice::RESULT_ARG . '=ready', $location );
		self::assertFalse( get_option( CredentialKeys::ERROR_OPTION ) );
		StorageRig::assert_pkcs8_rsa_key( get_option( CredentialKeys::PRIVATE_KEY_OPTION ) );
	}

	public function test_failed_retry_reports_failure(): void {
		$_POST['_wpnonce'] = 'nonce';

		$location = $this->notice()->retry();

		self::assertStringContainsString( KeyRecoveryNotice::RESULT_ARG . '=failed', $location );
		self::assertNotFalse( get_option( CredentialKeys::ERROR_OPTION ) );
	}

	public function test_register_hooks_the_notice_and_the_retry_handler(): void {
		$this->notice()->register();

		self::assertArrayHasKey( 'admin_notices', $GLOBALS['stonewright_test_actions'] );
		self::assertArrayHasKey( 'admin_post_' . KeyRecoveryNotice::ACTION, $GLOBALS['stonewright_test_actions'] );
	}
}
