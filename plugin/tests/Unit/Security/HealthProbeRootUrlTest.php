<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Security\HealthProbe;
use Stonewright\WpMcp\Security\ProbeToken;

/**
 * A page at the root of the site has no path in its address. The probe asks for it with a token bound to "/" once the
 * address is known to be on the site's own origin, sends no token anywhere else, and never sends an authenticated
 * request that has no token.
 *
 * @covers \Stonewright\WpMcp\Security\HealthProbe
 * @covers \Stonewright\WpMcp\Security\ProbeToken
 */
final class HealthProbeRootUrlTest extends TestCase {

	/** @var list<array{url:string,args:array<string,mixed>}> */
	private array $requests = [];

	/** @var callable(string,array<string,mixed>):mixed */
	private $reply;

	/** @var list<string> What the stored tokens of the requests the transport saw were accepted for: "accepted:<user>" or "refused". */
	private array $redeemed = [];

	protected function setUp(): void {
		$GLOBALS['stonewright_test_options']         = [ 'stonewright_mode' => 'development' ];
		$GLOBALS['stonewright_test_transients']      = [];
		$GLOBALS['stonewright_test_filters']         = [];
		$GLOBALS['stonewright_test_current_user_id'] = 7;
		$GLOBALS['stonewright_test_user_caps_by_id'] = [ 7 => [ 'manage_options' => true, 'read' => true, 'edit_post' => true ] ];
		$GLOBALS['stonewright_test_posts']           = [ 31 => (object) [ 'ID' => 31, 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Fixture' ] ];
		$GLOBALS['stonewright_test_permalinks']      = [];
		$GLOBALS['stonewright_test_preview_links']   = [];
		unset( $GLOBALS['stonewright_test_set_transient_fails'], $GLOBALS['stonewright_test_home_url'] );
		$this->requests = [];
		$this->redeemed = [];
		$this->reply    = static fn (): array => [ 'response' => [ 'code' => 200 ], 'body' => 'ok', 'headers' => [] ];
		ProbeToken::reset_for_tests();
		HealthProbe::set_transport(
			function ( string $url, array $args ) {
				$this->requests[] = [ 'url' => $url, 'args' => $args ];
				$token            = (string) ( $args['headers'][ ProbeToken::HEADER ] ?? '' );
				if ( '' !== $token ) {
					// What the site does with the request: its own path (never empty) and the nonce in the query.
					$path = (string) wp_parse_url( $url, PHP_URL_PATH );
					$path = '' === $path ? '/' : $path;
					parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
					$nonce = (string) ( $query[ ProbeToken::PARAM ] ?? '' );
					$user  = ProbeToken::consume( $token, $path, $nonce );
					$mark  = 0 === $user ? ProbeToken::consume_mark( $token, $path, $nonce ) : false;
					$this->redeemed[] = $user > 0 ? 'accepted:' . $user : ( $mark ? 'accepted:0' : 'refused' );
				}
				return ( $this->reply )( $url, $args );
			}
		);
	}

	protected function tearDown(): void {
		HealthProbe::set_transport( null );
		ProbeToken::reset_for_tests();
		unset( $GLOBALS['stonewright_test_set_transient_fails'], $GLOBALS['stonewright_test_home_url'], $GLOBALS['stonewright_test_permalinks'], $GLOBALS['stonewright_test_preview_links'] );
		$GLOBALS['stonewright_test_transients'] = [];
		$GLOBALS['stonewright_test_posts']      = [];
		$GLOBALS['stonewright_test_filters']    = [];
	}

	/** @return list<string> The names of the probe tokens that are stored. */
	private function stored_tokens(): array {
		return array_values( array_filter( array_map( 'strval', array_keys( (array) $GLOBALS['stonewright_test_transients'] ) ), static fn ( string $name ): bool => str_starts_with( $name, 'stonewright_probe_' ) ) );
	}

	/** @return iterable<string, array{string}> */
	public static function root_addresses(): iterable {
		yield 'no path'              => [ 'https://example.test' ];
		yield 'a slash'              => [ 'https://example.test/' ];
		yield 'a query on the root'  => [ 'https://example.test/?p=31' ];
		yield 'a query without path' => [ 'https://example.test?p=31' ];
	}

	/** @dataProvider root_addresses */
	public function test_a_public_page_at_the_site_root_is_probed_with_a_token_bound_to_the_root( string $permalink ): void {
		$GLOBALS['stonewright_test_permalinks'][31] = $permalink;

		$probe = HealthProbe::run( [ 'legs' => [ 'post' ], 'post_id' => 31, 'user_id' => 7 ] );

		self::assertSame( 'passed', $probe['status'], (string) wp_json_encode( $probe['legs'] ) );
		self::assertCount( 1, $this->requests, 'One request.' );
		self::assertNotSame( '', (string) $this->requests[0]['args']['headers'][ ProbeToken::HEADER ] );
		self::assertSame( [ 'accepted:0' ], $this->redeemed, 'The token of a public page names nobody and is accepted once, for the root path.' );
		self::assertSame( [], $this->stored_tokens(), 'It was used up.' );
		self::assertSame( 0, $this->requests[0]['args']['redirection'], 'A request that carries a token never follows a redirect by itself.' );
	}

	public function test_a_page_in_a_subdirectory_keeps_its_own_path(): void {
		$GLOBALS['stonewright_test_home_url']       = 'https://example.test/blog/';
		$GLOBALS['stonewright_test_permalinks'][31] = 'https://example.test/blog';

		$probe = HealthProbe::run( [ 'legs' => [ 'post' ], 'post_id' => 31, 'user_id' => 7 ] );

		self::assertSame( 'passed', $probe['status'] );
		self::assertSame( [ 'accepted:0' ], $this->redeemed );
		// A token for "/" must not open "/blog": issue one for the root and present it at the subdirectory.
		$token = (string) ProbeToken::issue_mark( '/', 'abcdef0123456789' );
		self::assertFalse( ProbeToken::consume_mark( $token, '/blog', 'abcdef0123456789' ) );
	}

	public function test_a_draft_at_the_site_root_is_probed_as_the_user_with_a_token_bound_to_the_root(): void {
		$GLOBALS['stonewright_test_posts'][31]->post_status = 'draft';
		$GLOBALS['stonewright_test_preview_links'][31]      = 'https://example.test?p=31&preview=true';

		$probe = HealthProbe::run( [ 'legs' => [ 'post' ], 'post_id' => 31, 'user_id' => 7 ] );

		self::assertSame( 'passed', $probe['status'] );
		self::assertSame( [ 'accepted:7' ], $this->redeemed );
		self::assertCount( 1, $this->requests );
	}

	/** @return iterable<string, array{string}> */
	public static function foreign_addresses(): iterable {
		yield 'another host'        => [ 'https://other.test/' ];
		yield 'another host no path' => [ 'https://other.test' ];
		yield 'a subdomain'         => [ 'https://www.example.test/' ];
		yield 'credentials'         => [ 'https://fixture-user:fixture-secret@example.test/' ];
		yield 'another scheme'      => [ 'http://example.test/' ];
		yield 'not http'            => [ 'ftp://example.test/' ];
		yield 'another port'        => [ 'https://example.test:8443/' ];
		yield 'scheme relative'     => [ '//example.test/' ];
		yield 'a path only'         => [ '/just/a/path' ];
		yield 'nonsense'            => [ 'https:///' ];
	}

	/** @dataProvider foreign_addresses */
	public function test_an_address_that_is_not_on_the_site_origin_is_never_requested_and_no_token_is_issued( string $permalink ): void {
		$GLOBALS['stonewright_test_permalinks'][31] = $permalink;

		$probe = HealthProbe::run( [ 'legs' => [ 'post' ], 'post_id' => 31, 'user_id' => 7 ] );

		self::assertSame( [], $this->requests, 'Nothing was requested.' );
		self::assertSame( [], $this->stored_tokens(), 'No token was issued for it.' );
		self::assertSame( 'skipped', $probe['legs'][0]['status'] );
		self::assertSame( 'foreign_host', $probe['legs'][0]['reason'] );
		self::assertSame( 'unavailable', $probe['status'] );
	}

	/** @dataProvider foreign_addresses */
	public function test_a_draft_address_that_is_not_on_the_site_origin_is_never_requested_either( string $preview ): void {
		$GLOBALS['stonewright_test_posts'][31]->post_status = 'draft';
		$GLOBALS['stonewright_test_preview_links'][31]      = $preview;

		HealthProbe::run( [ 'legs' => [ 'post' ], 'post_id' => 31, 'user_id' => 7 ] );

		self::assertSame( [], $this->requests );
		self::assertSame( [], $this->stored_tokens() );
	}

	// -- Redirects -----------------------------------------------------------------------------------

	public function test_a_redirect_to_the_root_without_a_path_is_followed_with_a_new_token(): void {
		$GLOBALS['stonewright_test_permalinks'][31] = 'https://example.test/old-page/';
		$this->reply                                = static fn ( string $url ): array => str_contains( $url, '/old-page/' )
			? [ 'response' => [ 'code' => 301 ], 'body' => '', 'headers' => [ 'location' => 'https://example.test' ] ]
			: [ 'response' => [ 'code' => 200 ], 'body' => 'ok', 'headers' => [] ];

		$probe = HealthProbe::run( [ 'legs' => [ 'post' ], 'post_id' => 31, 'user_id' => 7 ] );

		self::assertSame( 'passed', $probe['status'], (string) wp_json_encode( $probe['legs'] ) );
		self::assertCount( 2, $this->requests );
		self::assertNotSame( $this->requests[0]['args']['headers'][ ProbeToken::HEADER ], $this->requests[1]['args']['headers'][ ProbeToken::HEADER ], 'The token of one request is never sent twice.' );
		self::assertSame( [ 'accepted:0', 'accepted:0' ], $this->redeemed );
	}

	public function test_a_redirect_to_another_origin_is_not_followed_and_the_token_goes_nowhere_else(): void {
		$GLOBALS['stonewright_test_permalinks'][31] = 'https://example.test/old-page/';
		$this->reply                                = static fn (): array => [ 'response' => [ 'code' => 301 ], 'body' => '', 'headers' => [ 'location' => 'https://evil.test/' ] ];

		$probe = HealthProbe::run( [ 'legs' => [ 'post' ], 'post_id' => 31, 'user_id' => 7 ] );

		self::assertCount( 1, $this->requests );
		self::assertStringStartsWith( 'https://example.test/', $this->requests[0]['url'] );
		self::assertSame( 'unavailable', $probe['legs'][0]['status'] );
		self::assertSame( 'redirect', $probe['legs'][0]['reason'] );
	}

	// -- A token that cannot be stored ------------------------------------------------------------------

	private function tokens_cannot_be_stored(): void {
		$GLOBALS['stonewright_test_set_transient_fails'] = [ 'stonewright_probe_' ];
	}

	public function test_when_the_token_cannot_be_stored_no_authenticated_request_is_sent(): void {
		$this->tokens_cannot_be_stored();
		$GLOBALS['stonewright_test_posts'][33]              = (object) [ 'ID' => 33, 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'Draft' ];
		$GLOBALS['stonewright_test_permalinks'][31]         = 'https://example.test';

		$admin  = HealthProbe::run( [ 'legs' => [ 'admin' ], 'user_id' => 7 ] );
		$public = HealthProbe::run( [ 'legs' => [ 'post' ], 'post_id' => 31, 'user_id' => 7 ] );
		$draft  = HealthProbe::run( [ 'legs' => [ 'post' ], 'post_id' => 33, 'user_id' => 7 ] );

		self::assertSame( [], $this->requests, 'No request was sent at all.' );
		foreach ( [ $admin, $public, $draft ] as $probe ) {
			self::assertSame( 'unavailable', $probe['legs'][0]['status'] );
			self::assertSame( 'token_unavailable', $probe['legs'][0]['reason'] );
			self::assertSame( 0, $probe['legs'][0]['http'] );
			self::assertSame( 'unavailable', $probe['status'] );
			self::assertSame( 'token_unavailable', $probe['unavailable_reason'] );
		}
	}

	public function test_legs_that_need_no_token_still_run_when_tokens_cannot_be_stored(): void {
		$this->tokens_cannot_be_stored();
		$this->reply = static fn ( string $url ): array => [ 'response' => [ 'code' => 200 ], 'body' => str_contains( $url, 'wp-json' ) ? '{"namespaces":[]}' : 'ok', 'headers' => [] ];

		$probe = HealthProbe::run( [ 'legs' => [ 'home', 'admin', 'rest' ], 'user_id' => 7 ] );

		self::assertCount( 2, $this->requests, 'The home page and the REST index carry no token.' );
		foreach ( $this->requests as $request ) {
			self::assertArrayNotHasKey( ProbeToken::HEADER, $request['args']['headers'] );
		}
		self::assertSame( [ 'passed', 'unavailable', 'passed' ], array_column( $probe['legs'], 'status' ) );
		self::assertSame( 'token_unavailable', $probe['legs'][1]['reason'] );
	}

	public function test_a_token_that_cannot_be_stored_for_a_redirect_hop_stops_the_leg_without_a_second_request(): void {
		$GLOBALS['stonewright_test_permalinks'][31] = 'https://example.test/old-page/';
		$this->reply                                = function (): array {
			// The first request has its token; the store fails from now on.
			$this->tokens_cannot_be_stored();
			return [ 'response' => [ 'code' => 301 ], 'body' => '', 'headers' => [ 'location' => 'https://example.test/new-page/' ] ];
		};

		$probe = HealthProbe::run( [ 'legs' => [ 'post' ], 'post_id' => 31, 'user_id' => 7 ] );

		self::assertCount( 1, $this->requests, 'The hop was not requested without its token.' );
		self::assertSame( 'token_unavailable', $probe['legs'][0]['reason'] );
		self::assertSame( 'unavailable', $probe['legs'][0]['status'] );
	}

	// -- What the probe says about itself ----------------------------------------------------------------

	/** @return iterable<string, array{array<string,mixed>, string}> */
	public static function verdicts(): iterable {
		$leg = static fn ( string $name, string $status, int $http, string $reason ): array => [ 'leg' => $name, 'status' => $status, 'http' => $http, 'reason' => $reason, 'ms' => 1 ];
		$sum = static fn ( array $legs ): array => HealthProbe::summary_of( $legs );

		yield 'every leg passed'                    => [ $sum( [ $leg( 'home', 'passed', 200, 'ok' ), $leg( 'rest', 'passed', 200, 'ok' ) ] ), 'verified' ];
		yield 'one passed, one never available'     => [ $sum( [ $leg( 'home', 'passed', 200, 'ok' ), $leg( 'admin', 'unavailable', 200, 'login_required' ) ] ), 'verified' ];
		yield 'a fatal on the home page'            => [ $sum( [ $leg( 'home', 'failed', 500, 'critical_error_page' ) ] ), 'site_failed' ];
		yield 'a fatal and a lost leg'              => [ $sum( [ $leg( 'home', 'failed', 500, 'http_500' ), $leg( 'admin', 'failed', 0, 'degraded_http_request_failed' ) ] ), 'site_failed' ];
		yield 'a leg that passed before went silent' => [ $sum( [ $leg( 'home', 'passed', 200, 'ok' ), $leg( 'admin', 'failed', 0, 'degraded_http_request_failed' ) ] ), 'check_lost' ];
		yield 'no token could be issued'            => [ $sum( [ $leg( 'admin', 'unavailable', 0, 'token_unavailable' ) ] ), 'not_started' ];
		yield 'nothing was asked for'               => [ $sum( [ $leg( 'admin', 'skipped', 0, 'no_user' ) ] ), 'not_started' ];
		yield 'a mix of the two that stopped it'    => [ $sum( [ $leg( 'admin', 'unavailable', 0, 'token_unavailable' ), $leg( 'post', 'skipped', 0, 'foreign_host' ) ] ), 'not_started' ];
		yield 'asked and never answered'            => [ $sum( [ $leg( 'home', 'unavailable', 0, 'http_request_failed' ), $leg( 'rest', 'unavailable', 0, 'http_request_failed' ) ] ), 'transport_unavailable' ];
		yield 'asked and met a login wall'          => [ $sum( [ $leg( 'admin', 'unavailable', 200, 'login_required' ) ] ), 'transport_unavailable' ];
		yield 'no probe at all'                     => [ [], 'not_started' ];
	}

	/**
	 * @dataProvider verdicts
	 * @param array<string, mixed> $probe
	 */
	public function test_the_verdict_names_why_a_probe_proves_nothing_or_something( array $probe, string $expected ): void {
		self::assertSame( $expected, HealthProbe::verdict( $probe ) );
		self::assertContains( $expected, HealthProbe::VERDICTS, 'The codes are a closed list.' );
	}

	public function test_a_probe_that_is_disabled_did_not_start(): void {
		$GLOBALS['stonewright_test_filters']['stonewright_rescue_probe_enabled'] = static fn (): bool => false;

		$probe = HealthProbe::run( [ 'legs' => [ 'home' ] ] );

		self::assertSame( 'not_started', HealthProbe::verdict( $probe ) );
		self::assertSame( [], $this->requests );
	}
}
