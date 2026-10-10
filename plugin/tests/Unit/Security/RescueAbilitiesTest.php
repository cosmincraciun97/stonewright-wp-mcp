<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\Security\RescueRollback;
use Stonewright\WpMcp\Abilities\Security\RescueStatus;
use Stonewright\WpMcp\Security\Backup;
use Stonewright\WpMcp\Security\ChangeJournal;
use Stonewright\WpMcp\Security\ConfirmationToken;
use Stonewright\WpMcp\Security\HealthProbe;

/**
 * @covers \Stonewright\WpMcp\Abilities\Security\RescueRollback
 * @covers \Stonewright\WpMcp\Abilities\Security\RescueStatus
 * @covers \Stonewright\WpMcp\Security\RescueRollback
 */
final class RescueAbilitiesTest extends TestCase {

	private string $uploads;

	/** @var callable():string */
	private $site_state;

	protected function setUp(): void {
		$this->uploads = sys_get_temp_dir() . '/sw-abilities-' . bin2hex( random_bytes( 5 ) );
		mkdir( $this->uploads, 0700, true );
		$GLOBALS['stonewright_test_upload_dir']      = [ 'basedir' => $this->uploads, 'baseurl' => 'https://example.test/uploads', 'error' => false ];
		$GLOBALS['stonewright_test_options']         = [ 'stonewright_mode' => 'development' ];
		$GLOBALS['stonewright_test_posts']           = [];
		$GLOBALS['stonewright_test_transients']      = [];
		$GLOBALS['stonewright_test_filters']         = [];
		$GLOBALS['stonewright_test_wpdb_inserts']    = [];
		$GLOBALS['stonewright_test_user_caps']       = [ 'manage_options' => true ];
		$GLOBALS['stonewright_test_current_user_id'] = 7;
		$GLOBALS['stonewright_test_user_caps_by_id'] = [ 7 => [ 'manage_options' => true, 'read' => true, 'edit_post' => true ] ];
		ChangeJournal::reset_for_tests();
		$this->site( static fn (): string => 'healthy' );
	}

	protected function tearDown(): void {
		HealthProbe::set_transport( null );
		ChangeJournal::reset_for_tests();
		unset( $GLOBALS['stonewright_test_upload_dir'] );
		$GLOBALS['stonewright_test_options']    = [];
		$GLOBALS['stonewright_test_user_caps']  = [];
		$GLOBALS['stonewright_test_transients'] = [];
		self::remove_tree( $this->uploads );
	}

	/** @param callable():string $state */
	private function site( callable $state ): void {
		$this->site_state = $state;
		HealthProbe::set_transport(
			function ( string $url, array $args ) {
				return match ( ( $this->site_state )() ) {
					'broken'      => [ 'response' => [ 'code' => 500 ], 'body' => '<body id="error-page"></body>', 'headers' => [] ],
					'unavailable' => new \WP_Error( 'http_request_failed', 'cURL error 7' ),
					default       => [ 'response' => [ 'code' => 200 ], 'body' => str_contains( $url, 'wp-json' ) ? '{"namespaces":[]}' : ( str_contains( $url, 'stonewright-rescue' ) ? 'data-sw-rescue-probe="ok"' : 'ok' ), 'headers' => [] ],
				};
			}
		);
	}

	private function post( int $id, string $content ): void {
		$GLOBALS['stonewright_test_posts'][ $id ] = (object) [
			'ID' => $id, 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Fixture', 'post_content' => $content,
			'post_excerpt' => '', 'post_parent' => 0, 'post_name' => 'fixture', 'meta' => [],
		];
	}

	/** An open incident whose recipe can run: a changed post and its snapshot. */
	private function open_post_incident( string $state = 'rollback_failed' ): string {
		$this->post( 31, 'original body' );
		$snapshot = Backup::snapshot_post( 31 );
		$entry    = ChangeJournal::arm(
			[
				'ability'       => 'stonewright/elementor-v3-batch-mutate',
				'resource_type' => 'post',
				'resource_key'  => '31',
				'recipe'        => [ 'type' => 'post_snapshot', 'ref' => $snapshot ],
				'recipe_detail' => [ 'post_id' => 31, 'snapshot_id' => $snapshot ],
				'scope'         => 'post',
			]
		);
		$GLOBALS['stonewright_test_posts'][31]->post_content = 'broken body';
		if ( 'armed' !== $state ) {
			ChangeJournal::settle( $entry['id'], $state );
		}
		return $entry['id'];
	}

	// -- rescue-status ---------------------------------------------------------

	public function test_status_requires_manage_options_and_is_read_only(): void {
		$ability = new RescueStatus();

		$GLOBALS['stonewright_test_user_caps']['manage_options'] = false;
		self::assertFalse( $ability->permission_callback( [] ) );
		$GLOBALS['stonewright_test_user_caps']['manage_options'] = true;
		self::assertTrue( $ability->permission_callback( [] ) );
		self::assertSame( 'stonewright/rescue-status', $ability->name() );
		self::assertSame( 'security', $ability->category() );
	}

	public function test_status_with_nothing_open_is_small(): void {
		$result = ( new RescueStatus() )->execute( [] );

		self::assertTrue( $result['ok'] );
		self::assertSame( [], $result['open_incidents'] );
		self::assertSame( [], $result['unconfirmed'] );
		self::assertSame( [], $result['recent'] );
		self::assertArrayHasKey( 'journal', $result );
		self::assertLessThan( 600, strlen( (string) wp_json_encode( $result ) ) );
	}

	public function test_status_reports_the_state_of_the_rescue_helper_when_it_is_healthy_too(): void {
		$result = ( new RescueStatus() )->execute( [] );

		self::assertSame( [ 'state', 'safe_mode' ], array_keys( $result['helper'] ) );
		self::assertSame( 'missing', $result['helper']['state'], 'No helper is installed in the unit environment.' );
		self::assertFalse( $result['helper']['safe_mode'] );
		self::assertStringNotContainsString( 'mu-plugins', (string) wp_json_encode( $result ), 'No path is reported.' );
		self::assertArrayHasKey( 'helper', ( new RescueStatus() )->output_schema()['properties'] );
	}

	public function test_status_lists_an_open_incident_with_its_plan(): void {
		$id = $this->open_post_incident();

		$result = ( new RescueStatus() )->execute( [] );

		self::assertCount( 1, $result['open_incidents'] );
		$incident = $result['open_incidents'][0];
		self::assertSame( $id, $incident['incident_id'] );
		self::assertSame( 'stonewright/elementor-v3-batch-mutate', $incident['ability'] );
		self::assertSame( 'rollback_failed', $incident['state'] );
		self::assertSame( 'post_snapshot', $incident['recipe']['type'] );
		self::assertTrue( $incident['recipe']['available'] );
		self::assertStringContainsString( 'post 31', $incident['recipe']['plan'] );
		self::assertSame( [ $id ], array_column( $result['recent'], 'incident_id' ) );
	}

	public function test_status_shows_changes_that_were_armed_but_never_verified(): void {
		$id = $this->open_post_incident( 'armed' );
		$GLOBALS['stonewright_test_filters']['stonewright_rescue_now'] = static fn (): int => time() + 4000;

		$result = ( new RescueStatus() )->execute( [] );
		unset( $GLOBALS['stonewright_test_filters']['stonewright_rescue_now'] );

		self::assertSame( [], $result['open_incidents'] );
		self::assertSame( [ $id ], array_column( $result['unconfirmed'], 'incident_id' ), 'An armed entry past its deadline is listed as unconfirmed.' );
	}

	public function test_the_status_output_names_no_secret_and_no_file_contents(): void {
		$this->open_post_incident();

		$json = (string) wp_json_encode( ( new RescueStatus() )->execute( [] ) );

		self::assertStringNotContainsString( 'broken body', $json );
		self::assertStringNotContainsString( 'original body', $json );
		self::assertStringNotContainsString( $this->uploads, $json );
	}

	// -- rescue-rollback -------------------------------------------------------

	public function test_rollback_requires_manage_options(): void {
		$ability = new RescueRollback();

		$GLOBALS['stonewright_test_user_caps']['manage_options'] = false;
		self::assertFalse( $ability->permission_callback( [ 'incident_id' => 'cs-x' ] ) );
		$GLOBALS['stonewright_test_user_caps']['manage_options'] = true;
		self::assertTrue( $ability->permission_callback( [ 'incident_id' => 'cs-x' ] ) );
		self::assertSame( 'stonewright/rescue-rollback', $ability->name() );
	}

	public function test_the_input_schema_is_strict_and_names_the_incident(): void {
		$schema = ( new RescueRollback() )->input_schema();

		self::assertFalse( $schema['additionalProperties'] );
		self::assertSame( [ 'incident_id' ], $schema['required'] );
		self::assertSame( [ 'rollback', 'recheck' ], $schema['properties']['action']['enum'] );
		self::assertArrayHasKey( 'dry_run', $schema['properties'] );
		self::assertArrayHasKey( 'confirmation_token', $schema['properties'] );
	}

	public function test_a_dry_run_describes_the_rollback_and_changes_nothing(): void {
		$id = $this->open_post_incident();

		$result = ( new RescueRollback() )->execute( [ 'incident_id' => $id, 'dry_run' => true ] );

		self::assertTrue( $result['ok'] );
		self::assertTrue( $result['dry_run'] );
		self::assertSame( $id, $result['incident_id'] );
		self::assertStringContainsString( 'post 31', $result['recipe']['plan'] );
		self::assertSame( 'broken body', $GLOBALS['stonewright_test_posts'][31]->post_content );
		self::assertSame( 'rollback_failed', ChangeJournal::get( $id )['state'] );
	}

	public function test_a_rollback_restores_the_change_probes_and_closes_the_incident(): void {
		$id = $this->open_post_incident();
		self::assertSame( $id, ChangeJournal::banner()['id'] );

		$result = ( new RescueRollback() )->execute( [ 'incident_id' => $id ] );

		self::assertTrue( $result['ok'] );
		self::assertSame( 'succeeded', $result['rollback_status'] );
		self::assertSame( 'rolled_back', $result['state'] );
		self::assertSame( 'healthy', $result['site_status'] );
		self::assertSame( 'verified', $result['verification_status'] );
		self::assertSame( 'passed', $result['probe']['status'] );
		self::assertSame( 'original body', $GLOBALS['stonewright_test_posts'][31]->post_content );
		self::assertNull( ChangeJournal::banner(), 'The banner goes away with the incident.' );
		$entry = ChangeJournal::get( $id );
		self::assertSame( 'rolled_back', $entry['state'] );
		self::assertSame( 'ability', $entry['rollback']['by'] );
		self::assertSame( 'healthy', $entry['rollback']['site'] );
	}

	public function test_the_outcome_is_recorded_in_the_audit_log(): void {
		$id = $this->open_post_incident();

		( new RescueRollback() )->execute( [ 'incident_id' => $id ] );

		$rows = array_values(
			array_filter(
				array_map( static fn ( array $row ): array => $row['data'], $GLOBALS['stonewright_test_wpdb_inserts'] ),
				static fn ( array $row ): bool => 'stonewright/rescue-rollback' === ( $row['ability_name'] ?? '' )
			)
		);
		self::assertCount( 1, $rows );
		self::assertSame( 'ok', $rows[0]['result_status'] );
		self::assertSame( 'succeeded', $rows[0]['rollback_status'] );
		self::assertSame( $id, $rows[0]['change_set_id'] );
	}

	public function test_a_site_that_still_fails_after_the_rollback_is_reported(): void {
		$id = $this->open_post_incident();
		$this->site( static fn (): string => 'broken' );

		$result = ( new RescueRollback() )->execute( [ 'incident_id' => $id ] );

		self::assertTrue( $result['ok'], 'The rollback itself worked.' );
		self::assertSame( 'still_failing', $result['site_status'] );
		self::assertSame( 'failed', $result['verification_status'] );
		self::assertTrue( ChangeJournal::get( $id )['residual'] );
	}

	public function test_a_rollback_that_cannot_run_is_an_error_and_leaves_the_incident_open(): void {
		$id = $this->open_post_incident();
		delete_post_meta( 31, '_stonewright_backups' );

		$result = ( new RescueRollback() )->execute( [ 'incident_id' => $id ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_rescue_rollback_failed', $result->get_error_code() );
		self::assertSame( 'failed', $result->get_error_data()['rollback_status'] );
		self::assertSame( 'rollback_failed', ChangeJournal::get( $id )['state'] );
		self::assertSame( $id, ChangeJournal::banner()['id'] );
		self::assertStringContainsString( '"rollback_status":"failed"', $result->get_error_message() );
	}

	public function test_an_unknown_incident_is_not_found(): void {
		$result = ( new RescueRollback() )->execute( [ 'incident_id' => 'cs-unknown' ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_rescue_incident_not_found', $result->get_error_code() );
		self::assertSame( 404, $result->get_error_data()['status'] );
	}

	public function test_a_change_that_is_already_rolled_back_has_nothing_to_roll_back(): void {
		$id = $this->open_post_incident( 'rolled_back' );

		$result = ( new RescueRollback() )->execute( [ 'incident_id' => $id ] );

		self::assertSame( 'stonewright_rescue_not_open', $result->get_error_code() );
		self::assertSame( 'broken body', $GLOBALS['stonewright_test_posts'][31]->post_content );
	}

	public function test_a_change_without_a_recipe_says_so_and_points_to_recheck(): void {
		$entry = ChangeJournal::arm( [ 'ability' => 'stonewright/custom-code-provider', 'resource_type' => 'custom_code', 'resource_key' => 'wpcode:9', 'recipe' => [ 'type' => 'none', 'ref' => '' ] ] );
		ChangeJournal::settle( $entry['id'], 'rollback_failed' );

		$result = ( new RescueRollback() )->execute( [ 'incident_id' => $entry['id'] ] );

		self::assertSame( 'stonewright_rescue_no_recipe', $result->get_error_code() );
		self::assertStringContainsString( 'recheck', $result->get_error_message() );
		self::assertSame( 'rollback_failed', ChangeJournal::get( $entry['id'] )['state'] );
	}

	public function test_a_recheck_closes_the_incident_when_the_site_loads_again(): void {
		$id = $this->open_post_incident();

		$result = ( new RescueRollback() )->execute( [ 'incident_id' => $id, 'action' => 'recheck' ] );

		self::assertTrue( $result['ok'] );
		self::assertTrue( $result['resolved'] );
		self::assertSame( 'verified', $result['state'] );
		self::assertSame( 'healthy', $result['site_status'] );
		self::assertSame( 'broken body', $GLOBALS['stonewright_test_posts'][31]->post_content, 'A re-check never touches the site.' );
		self::assertNull( ChangeJournal::banner() );
		self::assertSame( 'confirmed_by_probe', ChangeJournal::get( $id )['note'] );
	}

	public function test_a_recheck_leaves_the_incident_open_while_the_site_still_fails(): void {
		$id = $this->open_post_incident();
		$this->site( static fn (): string => 'broken' );

		$result = ( new RescueRollback() )->execute( [ 'incident_id' => $id, 'action' => 'recheck' ] );

		self::assertTrue( $result['ok'] );
		self::assertFalse( $result['resolved'] );
		self::assertSame( 'still_failing', $result['site_status'] );
		self::assertSame( 'rollback_failed', ChangeJournal::get( $id )['state'] );
		self::assertSame( $id, ChangeJournal::banner()['id'] );
	}

	public function test_a_recheck_with_no_evidence_does_not_close_anything(): void {
		$id = $this->open_post_incident();
		$this->site( static fn (): string => 'unavailable' );

		$result = ( new RescueRollback() )->execute( [ 'incident_id' => $id, 'action' => 'recheck' ] );

		self::assertFalse( $result['resolved'] );
		self::assertSame( 'unknown', $result['site_status'] );
		self::assertSame( 'rollback_failed', ChangeJournal::get( $id )['state'] );
	}

	// -- production-safe -------------------------------------------------------

	public function test_production_safe_mode_needs_a_confirmation_token_for_a_rollback(): void {
		$GLOBALS['stonewright_test_options']['stonewright_mode'] = 'production-safe';
		$id = $this->open_post_incident();

		$blocked = ( new RescueRollback() )->execute( [ 'incident_id' => $id ] );
		self::assertInstanceOf( \WP_Error::class, $blocked );
		self::assertSame( 'stonewright_confirmation_required', $blocked->get_error_code() );
		self::assertSame( 'broken body', $GLOBALS['stonewright_test_posts'][31]->post_content );

		$args = [ 'incident_id' => $id ];
		$args['confirmation_token'] = ConfirmationToken::issue( 'stonewright/rescue-rollback', $args );
		$result = ( new RescueRollback() )->execute( $args );

		self::assertTrue( $result['ok'] );
		self::assertSame( 'original body', $GLOBALS['stonewright_test_posts'][31]->post_content );
	}

	public function test_a_token_for_a_rollback_does_not_authorise_a_recheck_or_another_incident(): void {
		$GLOBALS['stonewright_test_options']['stonewright_mode'] = 'production-safe';
		$id = $this->open_post_incident();
		$token = ConfirmationToken::issue( 'stonewright/rescue-rollback', [ 'incident_id' => $id ] );

		$recheck = ( new RescueRollback() )->execute( [ 'incident_id' => $id, 'action' => 'recheck', 'confirmation_token' => $token ] );

		self::assertInstanceOf( \WP_Error::class, $recheck );
		self::assertSame( 'stonewright_confirmation_args_mismatch', $recheck->get_error_code() );
	}

	public function test_a_dry_run_needs_no_token_even_in_production_safe_mode(): void {
		$GLOBALS['stonewright_test_options']['stonewright_mode'] = 'production-safe';
		$id = $this->open_post_incident();

		$result = ( new RescueRollback() )->execute( [ 'incident_id' => $id, 'dry_run' => true ] );

		self::assertTrue( $result['ok'] );
		self::assertTrue( $result['dry_run'] );
	}

	// -- A rollback runs once --------------------------------------------------------------------

	public function test_a_rollback_that_is_already_running_is_not_started_again(): void {
		$id = $this->open_post_incident();
		ChangeJournal::claim( $id, 'page' );

		$second = ( new RescueRollback() )->execute( [ 'incident_id' => $id ] );

		self::assertInstanceOf( \WP_Error::class, $second );
		self::assertSame( 'stonewright_rescue_in_progress', $second->get_error_code() );
		self::assertSame( 409, $second->get_error_data()['status'] );
		self::assertSame( 'broken body', $GLOBALS['stonewright_test_posts'][31]->post_content, 'The recipe did not run a second time.' );
		self::assertSame( 'rollback_failed', ChangeJournal::get( $id )['state'] );
	}

	public function test_a_recheck_waits_for_a_rollback_that_is_running(): void {
		$id = $this->open_post_incident();
		ChangeJournal::claim( $id, 'page' );

		$recheck = ( new RescueRollback() )->execute( [ 'incident_id' => $id, 'action' => 'recheck' ] );

		self::assertInstanceOf( \WP_Error::class, $recheck );
		self::assertSame( 'stonewright_rescue_in_progress', $recheck->get_error_code() );
	}

	public function test_a_finished_rollback_releases_the_claim_and_the_next_call_sees_it_settled(): void {
		$id = $this->open_post_incident();

		$first  = ( new RescueRollback() )->execute( [ 'incident_id' => $id ] );
		$second = ( new RescueRollback() )->execute( [ 'incident_id' => $id ] );

		self::assertTrue( $first['ok'] );
		self::assertInstanceOf( \WP_Error::class, $second );
		self::assertSame( 'stonewright_rescue_not_open', $second->get_error_code() );
		self::assertSame( 0, ChangeJournal::get( $id )['claim_at'] );
	}

	public function test_a_failed_rollback_also_releases_the_claim(): void {
		$id = $this->open_post_incident();
		delete_post_meta( 31, '_stonewright_backups' );

		$result = ( new RescueRollback() )->execute( [ 'incident_id' => $id ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 0, ChangeJournal::get( $id )['claim_at'], 'It can be tried again, by hand or by an agent.' );
	}

	public function test_the_plan_says_how_old_the_change_is_and_warns_about_a_newer_one_on_the_same_item(): void {
		$GLOBALS['stonewright_test_filters']['stonewright_rescue_now'] = static fn (): int => time() - 7200;
		$id = $this->open_post_incident();
		unset( $GLOBALS['stonewright_test_filters']['stonewright_rescue_now'] );
		$newer = ChangeJournal::arm( [ 'ability' => 'stonewright/design-apply-to-post', 'resource_type' => 'post', 'resource_key' => '31', 'scope' => 'post' ] );

		$plan = ( new RescueRollback() )->execute( [ 'incident_id' => $id, 'dry_run' => true ] );

		self::assertGreaterThanOrEqual( 7200, $plan['age_seconds'] );
		self::assertSame( [ $newer['id'] ], array_column( $plan['newer_changes'], 'incident_id' ) );
		self::assertCount( 1, $plan['warnings'] );
		self::assertStringContainsString( 'newer change', $plan['warnings'][0] );
		self::assertStringContainsString( $newer['id'], $plan['warnings'][0] );
	}

	public function test_a_plan_with_nothing_newer_has_no_warning(): void {
		$id = $this->open_post_incident();

		$plan = ( new RescueRollback() )->execute( [ 'incident_id' => $id, 'dry_run' => true ] );

		self::assertSame( [], $plan['newer_changes'] );
		self::assertArrayNotHasKey( 'warnings', $plan );
	}
	public function test_a_recheck_needs_a_token_in_production_safe_mode_even_when_dry_run_is_set(): void {
		$GLOBALS['stonewright_test_options']['stonewright_mode'] = 'production-safe';
		$id   = $this->open_post_incident();
		$args = [ 'incident_id' => $id, 'action' => 'recheck', 'dry_run' => true ];

		$blocked = ( new RescueRollback() )->execute( $args );

		self::assertInstanceOf( \WP_Error::class, $blocked, 'A recheck changes the incident, so "dry_run" does not make it free.' );
		self::assertSame( 'stonewright_confirmation_required', $blocked->get_error_code() );
		self::assertSame( 'rollback_failed', ChangeJournal::get( $id )['state'], 'Nothing was probed or closed.' );
		self::assertSame( $id, ChangeJournal::banner()['id'] );

		$args['confirmation_token'] = ConfirmationToken::issue( 'stonewright/rescue-rollback', [ 'incident_id' => $id, 'action' => 'recheck', 'dry_run' => true ] );
		$result = ( new RescueRollback() )->execute( $args );

		self::assertTrue( $result['ok'] );
		self::assertSame( 'verified', ChangeJournal::get( $id )['state'], 'With its token the recheck runs, and it closes the incident.' );
	}

	public function test_only_a_dry_run_of_the_rollback_action_skips_the_token(): void {
		$GLOBALS['stonewright_test_options']['stonewright_mode'] = 'production-safe';
		$id = $this->open_post_incident();

		$plan = ( new RescueRollback() )->execute( [ 'incident_id' => $id, 'action' => 'rollback', 'dry_run' => true ] );

		self::assertTrue( $plan['ok'] );
		self::assertTrue( $plan['dry_run'] );
		self::assertSame( 'broken body', $GLOBALS['stonewright_test_posts'][31]->post_content );
	}

	public function test_outside_production_safe_mode_a_recheck_needs_no_token(): void {
		$id = $this->open_post_incident();

		$result = ( new RescueRollback() )->execute( [ 'incident_id' => $id, 'action' => 'recheck', 'dry_run' => true ] );

		self::assertTrue( $result['ok'] );
	}

	// -- Undo of a verified change -----------------------------------------------------------------

	/**
	 * A settled change of the kind that is code, with a recipe that runs without touching a file.
	 *
	 * @return string The entry id.
	 */
	private function code_change( string $kind, string $state = 'verified' ): string {
		if ( 'css' === $kind ) {
			// The Customizer CSS is a post; its way back is a snapshot of it.
			$this->post( 44, 'body { color: red; }' );
			$snapshot = Backup::snapshot_post( 44 );
			$entry    = ChangeJournal::arm(
				[
					'ability'       => 'stonewright/theme-custom-css',
					'resource_type' => 'post',
					'resource_key'  => '44',
					'recipe'        => [ 'type' => 'post_snapshot', 'ref' => $snapshot ],
					'recipe_detail' => [ 'post_id' => 44, 'snapshot_id' => $snapshot ],
					'scope'         => 'post',
				]
			);
			$GLOBALS['stonewright_test_posts'][44]->post_content = 'body { color: blue; }';
			if ( 'armed' !== $state ) {
				ChangeJournal::settle( $entry['id'], $state );
			}
			return $entry['id'];
		}
		$spec = match ( $kind ) {
			'sandbox'    => [ 'ability' => 'stonewright/sandbox-activate', 'resource_type' => 'sandbox', 'resource_key' => 'example-snippet.php', 'recipe' => [ 'type' => 'sandbox_file', 'ref' => 'example-snippet.php' ] ],
			'theme_file' => [ 'ability' => 'stonewright/theme-file-patch', 'resource_type' => 'theme_file', 'resource_key' => 'wp-content/themes/site-a/style.css', 'recipe' => [ 'type' => 'theme_backup', 'ref' => 'absent:style.css' ], 'recipe_detail' => [ 'absolute' => '/nonexistent/site-a/style.css' ] ],
			'snippet'    => [ 'ability' => 'stonewright/custom-code-provider', 'resource_type' => 'custom_code', 'resource_key' => 'wpcode:9', 'recipe' => [ 'type' => 'none', 'ref' => '' ], 'recipe_detail' => [ 'provider' => 'wpcode', 'snapshot_id' => 'snap-1', 'target_id' => '9' ] ],
			default      => throw new \InvalidArgumentException( $kind ),
		};
		$entry = ChangeJournal::arm( $spec );
		if ( 'armed' !== $state ) {
			ChangeJournal::settle( $entry['id'], $state );
		}
		return $entry['id'];
	}

	/** @return iterable<string, array{string}> */
	public static function code_kinds(): iterable {
		yield 'theme file'     => [ 'theme_file' ];
		yield 'custom code'    => [ 'snippet' ];
		yield 'sandbox file'   => [ 'sandbox' ];
		yield 'Customizer CSS' => [ 'css' ];
	}

	public function test_a_verified_change_can_be_rolled_back_through_the_ability(): void {
		$id = $this->open_post_incident( 'verified' );

		$result = ( new RescueRollback() )->execute( [ 'incident_id' => $id ] );

		self::assertIsArray( $result );
		self::assertTrue( $result['ok'] );
		self::assertSame( 'succeeded', $result['rollback_status'] );
		self::assertSame( 'rolled_back', $result['state'] );
		self::assertSame( 'healthy', $result['site_status'] );
		self::assertSame( 'original body', $GLOBALS['stonewright_test_posts'][31]->post_content );
		$entry = ChangeJournal::get( $id );
		self::assertSame( 'rolled_back', $entry['state'] );
		self::assertSame( 'ability', $entry['rollback']['by'] );
		self::assertSame( 7, $entry['rollback']['user'], 'The change records who undid it.' );
		self::assertSame( 'undone_after_verified', $entry['note'] );
		self::assertSame( 0, $entry['claim_at'] );
		$rows = array_values(
			array_filter(
				array_map( static fn ( array $row ): array => $row['data'], $GLOBALS['stonewright_test_wpdb_inserts'] ),
				static fn ( array $row ): bool => 'stonewright/rescue-rollback' === ( $row['ability_name'] ?? '' )
			)
		);
		self::assertCount( 1, $rows, 'The receipt is the audit row.' );
		self::assertSame( 'succeeded', $rows[0]['rollback_status'] );
		self::assertSame( $id, $rows[0]['change_set_id'] );
	}

	public function test_a_rolled_back_verified_change_cannot_be_rolled_back_twice(): void {
		$id = $this->open_post_incident( 'verified' );
		( new RescueRollback() )->execute( [ 'incident_id' => $id ] );
		$GLOBALS['stonewright_test_posts'][31]->post_content = 'edited later';

		$second = ( new RescueRollback() )->execute( [ 'incident_id' => $id ] );

		self::assertInstanceOf( \WP_Error::class, $second );
		self::assertSame( 'stonewright_rescue_not_open', $second->get_error_code() );
		self::assertSame( 'edited later', $GLOBALS['stonewright_test_posts'][31]->post_content );
	}

	public function test_a_dry_run_of_a_verified_change_shows_the_plan_and_changes_nothing(): void {
		$id = $this->open_post_incident( 'verified' );

		$result = ( new RescueRollback() )->execute( [ 'incident_id' => $id, 'dry_run' => true ] );

		self::assertTrue( $result['ok'] );
		self::assertTrue( $result['dry_run'] );
		self::assertSame( 'verified', $result['state'] );
		self::assertStringContainsString( 'post 31', $result['recipe']['plan'] );
		self::assertArrayNotHasKey( 'approval_required', $result, 'Content is undone without a human.' );
		self::assertSame( 'broken body', $GLOBALS['stonewright_test_posts'][31]->post_content );
		self::assertSame( 'verified', ChangeJournal::get( $id )['state'] );
	}

	public function test_a_verified_change_keeps_the_token_and_the_newer_change_warning(): void {
		$GLOBALS['stonewright_test_options']['stonewright_mode'] = 'production-safe';
		$id    = $this->open_post_incident( 'verified' );
		$newer = ChangeJournal::arm( [ 'ability' => 'stonewright/content-update-page', 'resource_type' => 'post', 'resource_key' => '31', 'recipe' => [ 'type' => 'none', 'ref' => '' ] ] );
		ChangeJournal::settle( $newer['id'], 'verified' );

		$plan    = ( new RescueRollback() )->execute( [ 'incident_id' => $id, 'dry_run' => true ] );
		$blocked = ( new RescueRollback() )->execute( [ 'incident_id' => $id ] );

		self::assertSame( [ $newer['id'] ], array_column( $plan['newer_changes'], 'incident_id' ) );
		self::assertStringContainsString( 'newer change', $plan['warnings'][0] );
		self::assertInstanceOf( \WP_Error::class, $blocked );
		self::assertSame( 'stonewright_confirmation_required', $blocked->get_error_code() );
		self::assertSame( 'broken body', $GLOBALS['stonewright_test_posts'][31]->post_content );
		self::assertSame( 'verified', ChangeJournal::get( $id )['state'] );
	}

	public function test_a_failed_undo_of_a_verified_change_is_an_open_incident_like_any_failed_rollback(): void {
		$id = $this->open_post_incident( 'verified' );
		// The recipe is on offer, but the snapshot it names is gone.
		delete_post_meta( 31, '_stonewright_backups' );

		$result = ( new RescueRollback() )->execute( [ 'incident_id' => $id ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_rescue_rollback_failed', $result->get_error_code() );
		$after = ChangeJournal::get( $id );
		self::assertSame( 'rollback_failed', $after['state'] );
		self::assertSame( 0, $after['claim_at'] );
		self::assertSame( $id, ChangeJournal::banner()['id'] );
	}

	/** @dataProvider code_kinds */
	public function test_an_agent_cannot_undo_a_verified_code_change( string $kind ): void {
		$id = $this->code_change( $kind );

		$result = ( new RescueRollback() )->execute( [ 'incident_id' => $id ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_rescue_approval_required', $result->get_error_code() );
		$data = $result->get_error_data();
		self::assertTrue( $data['approval_required'] );
		self::assertTrue( $data['agent_must_stop'] );
		self::assertSame( 'custom_code', $data['operation_class'] );
		self::assertStringContainsString( 'page=stonewright-rescue', $data['approval_url'] );
		self::assertSame( $id, $data['incident_id'] );
		self::assertStringContainsString( 'Stonewright > Activity > Rescue', $result->get_error_message() );
		$entry = ChangeJournal::get( $id );
		self::assertSame( 'verified', $entry['state'], 'Nothing ran.' );
		self::assertSame( 0, $entry['claim_at'] );
		self::assertNull( $entry['rollback'] );
	}

	/** @dataProvider code_kinds */
	public function test_the_approval_stop_holds_in_production_safe_mode_even_with_a_token( string $kind ): void {
		$GLOBALS['stonewright_test_options']['stonewright_mode'] = 'production-safe';
		$id                         = $this->code_change( $kind );
		$args                       = [ 'incident_id' => $id ];
		$args['confirmation_token'] = ConfirmationToken::issue( 'stonewright/rescue-rollback', $args );

		$result = ( new RescueRollback() )->execute( $args );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_rescue_approval_required', $result->get_error_code(), 'A token is no approval.' );
		self::assertSame( 'verified', ChangeJournal::get( $id )['state'] );
	}

	public function test_the_approval_stop_comes_before_the_request_for_a_token(): void {
		$GLOBALS['stonewright_test_options']['stonewright_mode'] = 'production-safe';
		$id = $this->code_change( 'sandbox' );

		$result = ( new RescueRollback() )->execute( [ 'incident_id' => $id ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_rescue_approval_required', $result->get_error_code(), 'No token is worth issuing for a call that cannot run.' );
	}

	/** @dataProvider code_kinds */
	public function test_a_dry_run_of_a_verified_code_change_shows_the_plan_and_the_approval_stop( string $kind ): void {
		$id = $this->code_change( $kind );

		$result = ( new RescueRollback() )->execute( [ 'incident_id' => $id, 'dry_run' => true ] );

		self::assertIsArray( $result );
		self::assertTrue( $result['ok'] );
		self::assertTrue( $result['dry_run'] );
		self::assertTrue( $result['approval_required'] );
		self::assertStringContainsString( 'page=stonewright-rescue', $result['approval_url'] );
		self::assertSame( 'verified', ChangeJournal::get( $id )['state'] );
	}

	/** @dataProvider code_kinds */
	public function test_a_code_change_that_is_not_verified_is_still_an_incident_the_ability_can_handle( string $kind ): void {
		foreach ( [ 'rollback_failed', 'armed' ] as $state ) {
			ChangeJournal::reset_for_tests();
			$id     = $this->code_change( $kind, $state );
			$result = ( new RescueRollback() )->execute( [ 'incident_id' => $id, 'dry_run' => true ] );

			self::assertIsArray( $result, $state );
			self::assertArrayNotHasKey( 'approval_required', $result, $state . ' is an incident, not an undo.' );
		}
	}

	/** @return iterable<string, array{string}> */
	public static function runnable_code_kinds(): iterable {
		yield 'sandbox file'   => [ 'sandbox' ];
		yield 'Customizer CSS' => [ 'css' ];
	}

	/** @dataProvider runnable_code_kinds */
	public function test_an_incident_rollback_of_code_still_runs_through_the_ability( string $kind ): void {
		foreach ( [ 'rollback_failed', 'armed' ] as $state ) {
			ChangeJournal::reset_for_tests();
			$id     = $this->code_change( $kind, $state );
			$result = ( new RescueRollback() )->execute( [ 'incident_id' => $id ] );

			self::assertIsArray( $result, $state );
			self::assertTrue( $result['ok'], $state );
			self::assertSame( 'rolled_back', ChangeJournal::get( $id )['state'], $state );
		}
	}

	public function test_an_incident_recorded_by_the_helper_is_rolled_back_by_an_agent_as_before(): void {
		$id = $this->code_change( 'sandbox', 'armed' );
		$this->flip_to_incident( $id );
		self::assertSame( 'incident', ChangeJournal::get( $id )['state'] );

		$result = ( new RescueRollback() )->execute( [ 'incident_id' => $id ] );

		self::assertIsArray( $result );
		self::assertTrue( $result['ok'] );
		self::assertSame( 'rolled_back', ChangeJournal::get( $id )['state'] );
	}

	private function flip_to_incident( string $id ): void {
		$file = new \Stonewright\WpMcp\Security\ChangeJournalFile( $this->uploads . '/stonewright-state/' . (string) get_option( ChangeJournal::FILE_OPTION, '' ) );
		$file->transaction(
			static function ( array $document ) use ( $id ): array {
				foreach ( $document['entries'] as $index => $entry ) {
					if ( $entry['id'] === $id ) {
						$document['entries'][ $index ]['state']    = 'incident';
						$document['entries'][ $index ]['incident'] = [
							'recorded_at'    => time(),
							'file'           => 'wp-content/mu-plugins/example-snippet.php',
							'line'           => 3,
							'type'           => 1,
							'message_sha256' => str_repeat( 'c', 64 ),
							'source'         => 'shutdown',
						];
					}
				}
				return $document;
			}
		);
		clearstatcache();
		ChangeJournal::sync_from_file();
	}

	public function test_a_verified_change_that_is_being_rolled_back_is_not_started_again(): void {
		$id = $this->open_post_incident( 'verified' );
		self::assertNotNull( ChangeJournal::claim( $id, 'admin-page' ), 'A verified change can be claimed.' );
		self::assertNull( ChangeJournal::claim( $id, 'ability' ), 'Only one caller gets the claim.' );

		$second = ( new RescueRollback() )->execute( [ 'incident_id' => $id ] );

		self::assertInstanceOf( \WP_Error::class, $second );
		self::assertSame( 'stonewright_rescue_in_progress', $second->get_error_code() );
		self::assertSame( 'broken body', $GLOBALS['stonewright_test_posts'][31]->post_content, 'The recipe ran once, not twice.' );
		self::assertSame( 'verified', ChangeJournal::get( $id )['state'] );
	}

	public function test_the_service_never_undoes_verified_code_unless_an_administrator_approved_it(): void {
		$id = $this->code_change( 'sandbox' );

		$refused = \Stonewright\WpMcp\Security\RescueRollback::run( $id, [ 'by' => 'wp-cli' ] );
		$allowed = \Stonewright\WpMcp\Security\RescueRollback::run( $id, [ 'by' => 'admin-page', 'human_approved' => true ] );

		self::assertInstanceOf( \WP_Error::class, $refused );
		self::assertSame( 'stonewright_rescue_approval_required', $refused->get_error_code() );
		self::assertIsArray( $allowed );
		self::assertTrue( $allowed['ok'] );
		self::assertSame( 'rolled_back', ChangeJournal::get( $id )['state'] );
	}

	private static function remove_tree( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		foreach ( scandir( $dir ) ?: [] as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}
			$path = $dir . '/' . $item;
			is_dir( $path ) ? self::remove_tree( $path ) : @unlink( $path );
		}
		@rmdir( $dir );
	}
}
