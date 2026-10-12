<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\Security\RescueRollback as RescueRollbackAbility;
use Stonewright\WpMcp\Security\ChangeJournal;
use Stonewright\WpMcp\Security\HealthProbe;
use Stonewright\WpMcp\Security\ProbeToken;
use Stonewright\WpMcp\Security\RescueRollback;

/**
 * "Check again" closes an incident only when the site is shown to be as healthy as it was before the change. A check that
 * passed before the change and does not answer now is not recovery, and a probe that could not start is not either. A site
 * whose checks never all passed is not held to checks it never had.
 *
 * @covers \Stonewright\WpMcp\Security\RescueRollback
 * @covers \Stonewright\WpMcp\Security\HealthProbe
 */
final class RescueRecheckBaselineTest extends TestCase {

	/** @var array<string, string|int> What each leg answers: ok, timeout, login, a status code, or wall. */
	private array $legs = [];

	/** @var list<string> The legs the transport was asked about, in order. */
	private array $asked = [];

	protected function setUp(): void {
		$GLOBALS['stonewright_test_options']         = [ 'stonewright_mode' => 'development' ];
		$GLOBALS['stonewright_test_transients']      = [];
		$GLOBALS['stonewright_test_filters']         = [];
		$GLOBALS['stonewright_test_posts']           = [ 31 => (object) [ 'ID' => 31, 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Fixture' ] ];
		$GLOBALS['stonewright_test_permalinks']      = [];
		$GLOBALS['stonewright_test_user_caps']       = [ 'manage_options' => true ];
		$GLOBALS['stonewright_test_current_user_id'] = 7;
		$GLOBALS['stonewright_test_user_caps_by_id'] = [ 7 => [ 'manage_options' => true, 'read' => true, 'edit_post' => true ] ];
		$GLOBALS['stonewright_test_upload_dir']      = [ 'basedir' => sys_get_temp_dir() . '/sw-recheck-baseline', 'baseurl' => 'https://example.test/uploads', 'error' => false ];
		unset( $GLOBALS['stonewright_test_set_transient_fails'] );
		ChangeJournal::reset_for_tests();
		ProbeToken::reset_for_tests();
		$this->asked = [];
		$this->legs  = [ 'home' => 'ok', 'admin' => 'ok', 'rest' => 'ok', 'post' => 'ok' ];
		HealthProbe::set_transport(
			function ( string $url ) {
				$leg = match ( true ) {
					str_contains( $url, 'page=stonewright-rescue' ) => 'admin',
					str_contains( $url, 'wp-json' )                  => 'rest',
					str_contains( $url, '?p=31' )                    => 'post',
					default                                          => 'home',
				};
				$this->asked[] = $leg;
				$answer        = $this->legs[ $leg ] ?? 'ok';
				return match ( true ) {
					'timeout' === $answer => new \WP_Error( 'http_request_failed', 'cURL error 28' ),
					'login' === $answer   => [ 'response' => [ 'code' => 200 ], 'body' => '<form id="loginform"></form>', 'headers' => [] ],
					is_int( $answer )     => [ 'response' => [ 'code' => $answer ], 'body' => 500 === $answer ? '<body id="error-page"></body>' : '', 'headers' => [] ],
					default               => [ 'response' => [ 'code' => 200 ], 'body' => 'rest' === $leg ? '{"namespaces":[]}' : ( 'admin' === $leg ? 'data-sw-rescue-probe="ok"' : 'ok' ), 'headers' => [] ],
				};
			}
		);
	}

	protected function tearDown(): void {
		HealthProbe::set_transport( null );
		ChangeJournal::reset_for_tests();
		ProbeToken::reset_for_tests();
		unset( $GLOBALS['stonewright_test_set_transient_fails'], $GLOBALS['stonewright_test_upload_dir'], $GLOBALS['stonewright_test_permalinks'] );
		$GLOBALS['stonewright_test_options']    = [];
		$GLOBALS['stonewright_test_transients'] = [];
		$GLOBALS['stonewright_test_posts']      = [];
		$GLOBALS['stonewright_test_user_caps']  = [];
	}

	/**
	 * An incident whose baseline is the probe taken before the change.
	 *
	 * @param array<string, string> $before Leg name to its state before the change: passed, or any other status.
	 * @return string The entry id.
	 */
	private function incident( array $before, string $scope = 'site', string $state = 'rollback_failed' ): string {
		$entry = ChangeJournal::arm(
			[
				'ability'       => 'stonewright/theme-file-patch',
				'resource_type' => 'post' === $scope ? 'post' : 'theme_file',
				'resource_key'  => 'post' === $scope ? '31' : 'functions.php',
				'recipe'        => [ 'type' => 'none', 'ref' => '' ],
				'scope'         => $scope,
			]
		);
		self::assertIsArray( $entry );
		if ( [] !== $before ) {
			$legs = [];
			foreach ( $before as $name => $status ) {
				$legs[] = [ 'leg' => $name, 'status' => $status, 'http' => 'passed' === $status ? 200 : 0, 'reason' => 'passed' === $status ? 'ok' : 'no_response', 'ms' => 5 ];
			}
			ChangeJournal::record_baseline( $entry['id'], HealthProbe::summary_of( $legs ) );
		}
		if ( 'armed' !== $state ) {
			ChangeJournal::settle( $entry['id'], $state );
		}
		$this->asked = [];
		return $entry['id'];
	}

	private const ALL_PASSED = [ 'home' => 'passed', 'admin' => 'passed', 'rest' => 'passed' ];

	/** @return array<string, mixed> */
	private function recheck( string $id ): array {
		$result = RescueRollback::recheck( $id, [ 'user_id' => 7 ] );
		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() : '' );
		return $result;
	}

	/** @param array<string, mixed> $result */
	private function assert_not_recovered( array $result, string $id, string $state = 'rollback_failed' ): void {
		self::assertFalse( $result['resolved'], 'The incident is not closed.' );
		self::assertSame( $state, $result['state'] );
		self::assertSame( $state, ChangeJournal::get( $id )['state'] );
		if ( 'armed' !== $state ) {
			self::assertNotNull( ChangeJournal::banner(), 'The banner stays.' );
		}
		self::assertNotSame( 'verified', $result['verification_status'] );
	}

	// -- F3: a check that passed before and does not answer now --------------------------------------

	public function test_a_lost_admin_check_does_not_close_the_incident(): void {
		$id               = $this->incident( self::ALL_PASSED );
		$this->legs['admin'] = 'timeout';

		$result = $this->recheck( $id );

		$this->assert_not_recovered( $result, $id );
		self::assertSame( 'still_failing', $result['site_status'], 'The same verdict an after-write probe gives for a check that was healthy before.' );
		self::assertSame( 'failed', $result['verification_status'] );
		self::assertSame( 'check_lost', $result['verification_reason'] );
		$admin = array_values( array_filter( $result['probe']['legs'], static fn ( array $leg ): bool => 'admin' === $leg['leg'] ) )[0];
		self::assertSame( 'failed', $admin['status'] );
		self::assertStringStartsWith( 'degraded_', $admin['reason'] );
		$kept = ChangeJournal::get( $id )['probe'];
		self::assertSame( 'failed', $kept['status'], 'What is kept on the entry is the judged probe.' );
	}

	public function test_the_incident_closes_once_the_lost_check_answers_again(): void {
		$id               = $this->incident( self::ALL_PASSED );
		$this->legs['admin'] = 'timeout';
		$this->recheck( $id );

		$this->legs['admin'] = 'ok';
		$result              = $this->recheck( $id );

		self::assertTrue( $result['resolved'] );
		self::assertSame( 'verified', $result['state'] );
		self::assertSame( 'healthy', $result['site_status'] );
		self::assertSame( 'verified', $result['verification_reason'] );
		self::assertNull( ChangeJournal::banner() );
		self::assertSame( 'confirmed_by_probe', ChangeJournal::get( $id )['note'] );
	}

	public function test_a_lost_rest_check_does_not_close_the_incident_either(): void {
		$id                 = $this->incident( self::ALL_PASSED );
		$this->legs['rest'] = 'timeout';

		$result = $this->recheck( $id );

		$this->assert_not_recovered( $result, $id );
		self::assertSame( 'check_lost', $result['verification_reason'] );
	}

	public function test_a_lost_post_check_does_not_close_a_post_incident(): void {
		$id                 = $this->incident( [ 'post' => 'passed' ], 'post' );
		$this->legs['post'] = 'timeout';

		$result = $this->recheck( $id );

		$this->assert_not_recovered( $result, $id );
		self::assertSame( 'check_lost', $result['verification_reason'] );
	}

	public function test_every_check_lost_is_not_recovery(): void {
		$id         = $this->incident( self::ALL_PASSED );
		$this->legs = [ 'home' => 'timeout', 'admin' => 'timeout', 'rest' => 'timeout' ];

		$result = $this->recheck( $id );

		$this->assert_not_recovered( $result, $id );
		self::assertSame( 'check_lost', $result['verification_reason'] );
	}

	public function test_an_armed_change_that_never_reported_is_not_confirmed_by_a_lost_check_either(): void {
		$id               = $this->incident( self::ALL_PASSED, 'site', 'armed' );
		$this->legs['admin'] = 'timeout';

		$result = $this->recheck( $id );

		$this->assert_not_recovered( $result, $id, 'armed' );
	}

	public function test_the_second_ask_for_a_silent_check_is_bounded_to_one(): void {
		$id               = $this->incident( self::ALL_PASSED );
		$this->legs['admin'] = 'timeout';

		$this->recheck( $id );

		self::assertSame( [ 'home', 'admin', 'rest', 'admin' ], $this->asked, 'The silent check is asked once more and no more.' );
	}

	public function test_a_check_that_answers_with_a_failure_is_not_asked_twice(): void {
		$id                 = $this->incident( self::ALL_PASSED );
		$this->legs['rest'] = 500;

		$result = $this->recheck( $id );

		self::assertSame( [ 'home', 'admin', 'rest' ], $this->asked );
		$this->assert_not_recovered( $result, $id );
		self::assertSame( 'site_failed', $result['verification_reason'] );
		self::assertSame( 'still_failing', $result['site_status'] );
	}

	// -- What a site that never had every check is not held to ----------------------------------------

	public function test_a_check_that_never_passed_does_not_stop_recovery(): void {
		$id               = $this->incident( [ 'home' => 'passed', 'admin' => 'unavailable', 'rest' => 'passed' ] );
		$this->legs['admin'] = 'login';

		$result = $this->recheck( $id );

		self::assertTrue( $result['resolved'], 'The admin check never passed before the change, so it is not required now.' );
		self::assertSame( 'verified', $result['verification_reason'] );
		self::assertSame( 'verified', ChangeJournal::get( $id )['state'] );
	}

	public function test_without_a_baseline_a_passing_check_is_enough_as_before(): void {
		$id               = $this->incident( [] );
		$this->legs['admin'] = 'timeout';
		$this->legs['rest']  = 'timeout';

		$result = $this->recheck( $id );

		self::assertTrue( $result['resolved'] );
		self::assertSame( 'verified', ChangeJournal::get( $id )['state'] );
	}

	public function test_when_no_check_can_answer_and_none_passed_before_nothing_is_proved_and_nothing_is_claimed(): void {
		$id         = $this->incident( [ 'home' => 'unavailable', 'admin' => 'unavailable', 'rest' => 'unavailable' ] );
		$this->legs = [ 'home' => 'timeout', 'admin' => 'timeout', 'rest' => 'timeout' ];

		$result = $this->recheck( $id );

		$this->assert_not_recovered( $result, $id );
		self::assertSame( 'unknown', $result['site_status'], 'Not a failing site and not a healthy one.' );
		self::assertSame( 'unverified', $result['verification_status'] );
		self::assertSame( 'transport_unavailable', $result['verification_reason'] );
	}

	// -- A probe that could not start ------------------------------------------------------------------

	public function test_a_probe_that_could_not_issue_its_token_did_not_start_and_is_not_recovery(): void {
		$id = $this->incident( self::ALL_PASSED );
		$GLOBALS['stonewright_test_set_transient_fails'] = [ 'stonewright_probe_' ];

		$result = $this->recheck( $id );

		self::assertNotContains( 'admin', $this->asked, 'No authenticated request was sent without its token.' );
		$this->assert_not_recovered( $result, $id );
		self::assertSame( 'unknown', $result['site_status'] );
		self::assertSame( 'unverified', $result['verification_status'] );
		self::assertSame( 'not_started', $result['verification_reason'], 'The admin check that passed before could not even be asked.' );
	}

	public function test_a_token_that_cannot_be_stored_does_not_hold_a_site_to_a_check_it_never_had(): void {
		$id = $this->incident( [ 'home' => 'passed', 'rest' => 'passed' ] );
		$GLOBALS['stonewright_test_set_transient_fails'] = [ 'stonewright_probe_' ];

		$result = $this->recheck( $id );

		self::assertTrue( $result['resolved'] );
		self::assertSame( 'verified', $result['verification_reason'] );
	}

	// -- The same verdict code for the probe a rollback takes -------------------------------------------

	public function test_the_ability_answers_with_the_reason_code(): void {
		$id               = $this->incident( self::ALL_PASSED );
		$this->legs['admin'] = 'timeout';

		$result = ( new RescueRollbackAbility() )->execute( [ 'incident_id' => $id, 'action' => 'recheck' ] );

		self::assertIsArray( $result );
		self::assertSame( 'check_lost', $result['verification_reason'] );
		self::assertContains( $result['verification_reason'], HealthProbe::VERDICTS );
	}
}
