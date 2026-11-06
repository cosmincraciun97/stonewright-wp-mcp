<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\Themes\ThemeFilePatch;
use Stonewright\WpMcp\Security\ChangeJournal;
use Stonewright\WpMcp\Security\CustomCodeGrant;
use Stonewright\WpMcp\Security\HealthProbe;
use Stonewright\WpMcp\Security\RescueGuard;
use Stonewright\WpMcp\Security\RescueRollback;
use Stonewright\WpMcp\Support\AgentNotices;

/**
 * A theme write on a slow host is judged against what the site did before it, not against what the host cannot do.
 *
 * The site answers its REST index quickly, its front page close to the probe timeout, and its wp-admin
 * screen not at all before the write. A PHP write that changes nothing about that used to be judged
 * "check_lost", rolled back, reported as "still_failing" without the change, and the human grant was spent.
 * Now: the one retry of a silent check is not starved by a check that cannot be judged; a page that only just
 * answered before the write is a slow witness that does not judge silence after it; and a rollback the product
 * itself finds unattributable names its reason and gives the grant back, once.
 *
 * @covers \Stonewright\WpMcp\Security\HealthProbe
 * @covers \Stonewright\WpMcp\Security\RescueRollback
 * @covers \Stonewright\WpMcp\Security\ThemeWriteTransaction
 * @covers \Stonewright\WpMcp\Abilities\Themes\ThemeFilePatch
 */
final class ThemeWriteSmokeBaselineTest extends TestCase {

	private string $base;

	/** @var list<array{url:string,args:array<string,mixed>}> */
	private array $requests = [];

	/** @var mixed */
	private $original_stylesheet_dir;

	protected function setUp(): void {
		$this->base = sys_get_temp_dir() . '/sw-smoke-repro-' . bin2hex( random_bytes( 5 ) );
		mkdir( $this->base . '/uploads', 0700, true );
		mkdir( $this->base . '/themes/site-a/inc', 0700, true );
		$this->original_stylesheet_dir                    = $GLOBALS['stonewright_test_stylesheet_directory'] ?? null;
		$GLOBALS['stonewright_test_stylesheet_directory'] = $this->base . '/themes/site-a';
		$GLOBALS['stonewright_test_stylesheet']           = 'site-a';
		$GLOBALS['stonewright_test_upload_dir']           = [ 'basedir' => $this->base . '/uploads', 'baseurl' => 'https://example.test/uploads', 'error' => false ];
		$GLOBALS['stonewright_test_options']              = [ 'stonewright_mode' => 'development', 'stonewright_disabled_abilities' => [] ];
		$GLOBALS['stonewright_test_posts']                = [];
		$GLOBALS['stonewright_test_transients']           = [];
		$GLOBALS['stonewright_test_filters']              = [];
		$GLOBALS['stonewright_test_wpdb_inserts']         = [];
		$GLOBALS['stonewright_test_user_logged_in']       = true;
		$GLOBALS['stonewright_test_current_user_id']      = 7;
		$GLOBALS['stonewright_test_user_caps']            = [ 'read' => true, 'manage_options' => true, 'edit_theme_options' => true, 'edit_css' => true ];
		$GLOBALS['stonewright_test_user_caps_by_id']      = [ 7 => [ 'manage_options' => true, 'read' => true, 'edit_post' => true ] ];
		$this->requests                                   = [];
		ChangeJournal::reset_for_tests();
		RescueGuard::reset_for_tests();
		AgentNotices::reset_for_tests();
	}

	protected function tearDown(): void {
		HealthProbe::set_transport( null );
		RescueGuard::reset_for_tests();
		ChangeJournal::reset_for_tests();
		AgentNotices::reset_for_tests();
		if ( null === $this->original_stylesheet_dir ) {
			unset( $GLOBALS['stonewright_test_stylesheet_directory'] );
		} else {
			$GLOBALS['stonewright_test_stylesheet_directory'] = $this->original_stylesheet_dir;
		}
		unset( $GLOBALS['stonewright_test_upload_dir'], $GLOBALS['stonewright_test_stylesheet'] );
		$GLOBALS['stonewright_test_options']    = [];
		$GLOBALS['stonewright_test_filters']    = [];
		$GLOBALS['stonewright_test_transients'] = [];
		$GLOBALS['stonewright_test_user_caps']  = [];
		self::remove_tree( $this->base );
	}

	// -- 1. The one retry of a silent leg is starved by a leg that cannot be judged ------------------

	public function test_the_retry_of_a_leg_that_passed_before_is_not_starved_by_a_leg_that_did_not(): void {
		// The field probe after the write: home silent at the 10 s timeout (it passed before), admin silent at the
		// 10 s timeout (it did not pass before, so it can be neither retried nor judged), REST fine in half a second.
		$probe = HealthProbe::summary_of(
			[
				[ 'leg' => 'home', 'status' => 'unavailable', 'http' => 0, 'reason' => 'http_request_failed', 'ms' => 10013 ],
				[ 'leg' => 'admin', 'status' => 'unavailable', 'http' => 0, 'reason' => 'http_request_failed', 'ms' => 10016 ],
				[ 'leg' => 'rest', 'status' => 'passed', 'http' => 200, 'reason' => 'ok', 'ms' => 482 ],
			]
		);
		$this->answer_everything();

		$retried = HealthProbe::retry_silent_legs( $probe, [ 'home', 'rest' ], [ 'legs' => [ 'home', 'admin', 'rest' ], 'user_id' => 7 ] );

		self::assertCount( 1, $this->requests, 'The home leg passed before and got no answer: it must be asked once more. The 10 s the unjudged admin leg spent must not use up that one retry.' );
		self::assertTrue( $retried['legs'][0]['retried'] ?? false );
		self::assertGreaterThanOrEqual( HealthProbe::TIMEOUT, $this->requests[0]['args']['timeout'] );
	}

	// -- 2. A leg that barely answered before the write cannot judge silence after it ----------------

	public function test_a_leg_that_answered_close_to_the_timeout_before_the_write_does_not_judge_silence_after_it(): void {
		$entry = [
			'baseline'     => HealthProbe::summary_of( [ [ 'leg' => 'home', 'status' => 'passed', 'http' => 200, 'reason' => 'ok', 'ms' => 9000 ] ] ),
			'resource_key' => 'inc/custom.php',
			'scope'        => 'site',
		];
		$after = HealthProbe::summary_of( [ [ 'leg' => 'home', 'status' => 'unavailable', 'http' => 0, 'reason' => 'http_request_failed', 'ms' => 10010 ] ] );

		$judged = RescueRollback::judge( $after, [ $entry ], [ 'legs' => [ 'home' ], 'user_id' => 7 ] );

		self::assertSame( 'unavailable', $judged['legs'][0]['status'], 'A page that took 9.0 s of a 10 s timeout before the write is a slow witness: its silence afterwards is not the change\'s doing.' );
		self::assertSame( 'unavailable', $judged['status'] );
	}

	// -- 3. The grant is spent on a rollback the product itself calls unattributed --------------------

	public function test_a_rollback_whose_lost_check_still_fails_without_the_change_names_its_reason(): void {
		[ $error ] = $this->apply_php_patch_on_the_field_host();

		$data = $error->get_error_data();
		self::assertSame( 'check_lost', $data['verification_reason'] ?? null, 'No leg answered with a failure: the only failed leg is a lost check, and it is lost without the change too. The error must say so (as rescue-rollback does with verification_reason).' );
	}

	public function test_a_grant_is_not_spent_by_a_rollback_whose_lost_check_still_fails_without_the_change(): void {
		[ $error, $token, $after_hash, $changed_bytes ] = $this->apply_php_patch_on_the_field_host();

		$data = $error->get_error_data();
		self::assertSame( 'still_failing', $data['site_status'], 'Precondition: the site fails the same check with the original file back.' );
		$again = CustomCodeGrant::verify_and_consume( $token, 'inc/custom.php', $after_hash, 'php', $changed_bytes, true );
		self::assertTrue( $again, 'The human approved exactly these bytes and the rollback was not attributable to them: the same grant must still apply the same candidate once. Got: ' . ( $again instanceof \WP_Error ? $again->get_error_code() : 'true' ) );
	}

	public function test_a_grant_stays_spent_when_the_lost_check_fails_outright_without_the_change(): void {
		[ $error, $token, $after_hash, $changed_bytes ] = $this->apply_php_patch_on_the_field_host( true );

		$data = $error->get_error_data();
		self::assertSame( 'site_failed', $data['post_rollback_reason'], 'Precondition: with the original file back, the front page answers with a server error.' );
		self::assertSame( 'unknown', $data['attribution'], 'A check that fails outright without the change shows nothing about whose fault the lost check was.' );
		self::assertSame( 'spent', $data['custom_code_grant_status'] );
		$again = CustomCodeGrant::verify_and_consume( $token, 'inc/custom.php', $after_hash, 'php', $changed_bytes, true );
		self::assertInstanceOf( \WP_Error::class, $again );
		self::assertSame( 'stonewright_custom_code_grant_reused', $again->get_error_code() );
	}

	/**
	 * A human-approved replace_between_markers PHP patch of a child-theme include on a host whose front page
	 * answers just inside the timeout before the write and not at all afterwards, with or without the change.
	 *
	 * @param bool $fatal_after_rollback Whether the front page answers with a server error once the original file is back.
	 * @return array{0:\WP_Error,1:string,2:string,3:int}
	 */
	private function apply_php_patch_on_the_field_host( bool $fatal_after_rollback = false ): array {
		$path   = $this->base . '/themes/site-a/inc/custom.php';
		$before = "<?php\n// SW:START\n// old\n// SW:END\n";
		file_put_contents( $path, $before );
		$written = false;
		$phase   = static function () use ( $path, &$written ): string {
			$content = (string) file_get_contents( $path );
			if ( str_contains( $content, 'sw_repro' ) ) {
				$written = true;
				return 'after_write';
			}
			return $written ? 'after_rollback' : 'baseline';
		};
		$this->field_host( $phase, $fatal_after_rollback );

		$ability = new ThemeFilePatch();
		$args    = [
			'path'         => 'inc/custom.php',
			'mode'         => 'replace_between_markers',
			'start_marker' => '// SW:START',
			'end_marker'   => '// SW:END',
			'content'      => "function sw_repro() {\n\treturn 1;\n}",
		];
		$dry = $ability->execute( array_merge( $args, [ 'dry_run' => true, 'native_gap' => [ 'reason' => 'A theme hook has no typed API.', 'methods_tried' => [ 'typed_api' ] ] ] ) );
		self::assertIsArray( $dry, 'The dry run stages the proposal.' );
		self::assertTrue( $dry['approval_required'] );
		$after_hash    = (string) $dry['after_sha256'];
		$changed_bytes = (int) $dry['changed_bytes'];

		// The administrator approves the proposal in wp-admin: a grant bound to this path and hash.
		$issued = CustomCodeGrant::issue( [ 'path' => 'inc/custom.php', 'after_sha256' => $after_hash, 'language' => 'php', 'high_risk' => true ] );
		self::assertIsArray( $issued );
		$token = (string) $issued['token'];

		$result = $ability->execute( array_merge( $args, [ 'custom_code_grant' => $token ] ) );

		// What happens today, as the field report says.
		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_theme_write_smoke_failed', $result->get_error_code() );
		$data = $result->get_error_data();
		self::assertSame( 'succeeded', $data['rollback_status'] );
		self::assertSame( 'still_failing', $data['site_status'] );
		self::assertSame( $before, file_get_contents( $path ), 'The original file is back.' );
		$legs = array_column( $data['probe']['legs'], null, 'leg' );
		self::assertSame( 'failed', $legs['home']['status'] );
		self::assertStringStartsWith( 'degraded_', $legs['home']['reason'] );
		self::assertTrue( $legs['home']['retried'] ?? false, 'The home leg was asked once more and stayed silent.' );
		self::assertSame( 'unavailable', $legs['admin']['status'] );
		self::assertSame( 'passed', $legs['rest']['status'], 'The REST index, a full bootstrap that loads the theme, passed after the write.' );
		self::assertSame( 'partial', $data['probe']['coverage'] );
		self::assertSame( 'failed', $data['post_rollback_smoke']['status'] );
		self::assertSame( 'rolled_back', ChangeJournal::get( $data['incident_id'] )['state'] );

		return [ $result, $token, $after_hash, $changed_bytes ];
	}

	/**
	 * The field host: REST always answers; wp-admin times out before the write and after it, and answers once the
	 * original file is back; the front page answers before the write and times out after it, with or without the change.
	 *
	 * @param callable():string $phase                baseline, after_write or after_rollback.
	 * @param bool              $fatal_after_rollback Whether the front page answers with a server error once the original file is back.
	 */
	private function field_host( callable $phase, bool $fatal_after_rollback = false ): void {
		HealthProbe::set_transport(
			function ( string $url, array $args ) use ( $phase, $fatal_after_rollback ) {
				$this->requests[] = [ 'url' => $url, 'args' => $args ];
				$leg              = match ( true ) {
					str_contains( $url, 'page=stonewright-rescue' ), str_contains( $url, '/wp-admin/' ) => 'admin',
					str_contains( $url, '/wp-json/' )                                                     => 'rest',
					default                                                                              => 'home',
				};
				$timeout = new \WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out after 10000 milliseconds' );
				$ok      = static fn ( string $body ): array => [ 'response' => [ 'code' => 200 ], 'body' => $body, 'headers' => [] ];
				return match ( $leg ) {
					'rest'  => $ok( '{"name":"Site","namespaces":["wp/v2"]}' ),
					'admin' => 'after_rollback' === $phase() ? $ok( '<html><body class="wp-admin"><div data-sw-rescue-probe="ok"></div></body></html>' ) : $timeout,
					default => match ( true ) {
						'baseline' === $phase()                                => $ok( '<html><body>home</body></html>' ),
						$fatal_after_rollback && 'after_rollback' === $phase() => [ 'response' => [ 'code' => 500 ], 'body' => '<html><body>There has been a critical error on this website.</body></html>', 'headers' => [] ],
						default                                                 => $timeout,
					},
				};
			}
		);
	}

	private function answer_everything(): void {
		HealthProbe::set_transport(
			function ( string $url, array $args ) {
				$this->requests[] = [ 'url' => $url, 'args' => $args ];
				return [ 'response' => [ 'code' => 200 ], 'body' => str_contains( $url, '/wp-json/' ) ? '{"namespaces":["wp/v2"]}' : 'ok', 'headers' => [] ];
			}
		);
	}

	private static function remove_tree( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$items = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::CHILD_FIRST );
		foreach ( $items as $item ) {
			$item->isDir() ? @rmdir( $item->getPathname() ) : @unlink( $item->getPathname() );
		}
		@rmdir( $dir );
	}
}
