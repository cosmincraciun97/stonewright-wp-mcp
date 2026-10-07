<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\RescueRuntime;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Core\RescueInstaller;
use Stonewright\WpMcp\Security\RescueRecoveryEmail;
use Stonewright\WpMcp\Tests\Unit\RescueRuntime\Support\MuRuntime;

/**
 * The Stonewright rescue link in the WordPress recovery mode email: added only when the fatal
 * error belongs to a Stonewright change, only for one administrator, and never without a
 * helper that can open it.
 *
 * @covers \Stonewright\WpMcp\Security\RescueRecoveryEmail
 */
final class RescueRecoveryEmailTest extends TestCase {

	private const NOW = 2_000_000_000;

	protected function setUp(): void {
		MuRuntime::begin();
		MuRuntime::admin( 7 );
		MuRuntime::boot();
		RescueInstaller::install();
		MuRuntime::write_journal( [ MuRuntime::entry( 'cs-1', [ 'armed_at' => time() - 30 ] ) ] );
		MuRuntime::boot();
		RescueRecoveryEmail::$user_lookup = static function ( string $address ): ?object {
			// Any text that names the owner finds the owner, so a recipient list must be refused before the lookup.
			return str_contains( $address, 'owner@example.test' ) ? new \WP_User( 7 ) : ( str_contains( $address, 'editor@example.test' ) ? new \WP_User( 8 ) : null );
		};
		MuRuntime::subscriber( 8 );
		$GLOBALS['stonewright_test_wpdb_inserts'] = [];
	}

	protected function tearDown(): void {
		RescueRecoveryEmail::$user_lookup = null;
		RescueInstaller::remove();
		MuRuntime::end();
	}

	/** @return array<string, mixed> */
	private static function email( string $to = 'owner@example.test' ): array {
		return [
			'to'          => $to,
			'subject'     => '[Example] Your Site is Experiencing a Technical Issue',
			'message'     => "Howdy!\n\nWordPress has a built-in feature that detects when a plugin or theme causes a fatal error.\n\nhttps://example.test/wp-login.php?action=enter_recovery_mode&rm_token=abc&rm_key=def\n",
			'headers'     => '',
			'attachments' => '',
		];
	}

	/** @return array{type:int,message:string,file:string,line:int} */
	private static function fatal(): array {
		return MuRuntime::fatal();
	}

	public function test_the_link_is_added_below_the_core_link_when_the_fatal_belongs_to_a_change(): void {
		$filtered = RescueRecoveryEmail::filter( self::email(), 'https://example.test/core-link', self::fatal() );

		self::assertStringStartsWith( self::email()['message'], $filtered['message'], 'the core message is kept as it was' );
		self::assertMatchesRegularExpression( '#https://example\.test/wp-login\.php\?stonewright_rescue=[a-f0-9]{16}\.[a-f0-9]{32}&redirect_to=#', $filtered['message'] );
		self::assertStringContainsString( 'Stonewright', $filtered['message'] );
		self::assertStringContainsString( '15 minutes', $filtered['message'] );
		self::assertStringContainsString( 'After you sign in', $filtered['message'], 'safe mode starts after the normal sign-in' );
		self::assertStringContainsString( 'recovery mode link above', $filtered['message'], 'and the way out when the sign-in page does not load' );
		self::assertStringNotContainsString( 'opens the site in safe mode', $filtered['message'] );
		self::assertSame( self::email()['subject'], $filtered['subject'] );
		self::assertSame( 'owner@example.test', $filtered['to'] );
	}

	public function test_the_fatal_is_recorded_in_the_journal_as_a_side_effect(): void {
		RescueRecoveryEmail::filter( self::email(), '', self::fatal() );

		self::assertSame( 'incident', MuRuntime::journal_entry( 'cs-1' )['state'] );
	}

	public function test_the_key_in_the_email_is_bound_to_the_administrator_who_gets_it(): void {
		$filtered = RescueRecoveryEmail::filter( self::email(), '', self::fatal() );
		preg_match( '/stonewright_rescue=([a-f0-9]{16})\./', $filtered['message'], $match );

		$stored = json_decode( (string) MuRuntime::option( 'stonewright_rescue_key_' . $match[1] ), true );
		self::assertSame( 7, $stored['u'] );
	}

	public function test_no_link_for_a_fatal_that_belongs_to_no_change(): void {
		$email = self::email();

		self::assertSame( $email, RescueRecoveryEmail::filter( $email, '', MuRuntime::fatal( 'wp-content/plugins/other/other.php' ) ) );
		self::assertSame( [], MuRuntime::option_names( 'stonewright_rescue_key_' ) );
	}

	public function test_no_link_without_a_fatal_error(): void {
		$email = self::email();

		self::assertSame( $email, RescueRecoveryEmail::filter( $email, '', [ 'type' => E_WARNING, 'message' => 'x', 'file' => MuRuntime::absolute( 'wp-content/themes/site-a/functions.php' ), 'line' => 1 ] ) );
	}

	public function test_no_link_when_the_helper_cannot_open_it(): void {
		unlink( RescueInstaller::target_path() );
		$email = self::email();

		self::assertSame( $email, RescueRecoveryEmail::filter( $email, '', self::fatal() ) );
		self::assertSame( [], MuRuntime::option_names( 'stonewright_rescue_key_' ) );
	}

	/** @return array<string, array{0: string}> */
	public static function recipients_without_a_link(): array {
		return [
			'an address that is no user'      => [ 'nobody@example.test' ],
			'a user who is not an administrator' => [ 'editor@example.test' ],
			'several recipients'              => [ 'owner@example.test, editor@example.test' ],
			'no recipient'                    => [ '' ],
		];
	}

	/** @dataProvider recipients_without_a_link */
	public function test_the_link_goes_to_exactly_one_administrator_or_nowhere( string $to ): void {
		$email = self::email( $to );

		self::assertSame( $email, RescueRecoveryEmail::filter( $email, '', self::fatal() ) );
		self::assertSame( [], MuRuntime::option_names( 'stonewright_rescue_key_' ) );
	}

	public function test_a_value_that_is_not_an_email_array_is_returned_as_it_is(): void {
		self::assertSame( 'text', RescueRecoveryEmail::filter( 'text', '', self::fatal() ) );
		self::assertSame( [], RescueRecoveryEmail::filter( [], '', self::fatal() ) );
		self::assertSame( [ 'to' => [ 'owner@example.test' ], 'message' => 'x' ], RescueRecoveryEmail::filter( [ 'to' => [ 'owner@example.test' ], 'message' => 'x' ], '', self::fatal() ) );
	}

	public function test_the_filter_is_registered_after_the_default_priority_with_both_arguments(): void {
		RescueRecoveryEmail::register();

		self::assertTrue( MuRuntime::has_filter( 'recovery_mode_email' ) );
	}

	public function test_a_failure_while_building_the_link_leaves_the_email_unchanged(): void {
		RescueRecoveryEmail::$user_lookup = static function (): never {
			throw new \RuntimeException( 'lookup failed' );
		};
		$email = self::email();

		self::assertSame( $email, RescueRecoveryEmail::filter( $email, '', self::fatal() ) );
	}
}
