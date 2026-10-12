<?php
/**
 * The Rescue page and the rollback engine: the page passes the confirmation on and shows what the engine refuses.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Admin;

use Stonewright\WpMcp\Admin\MenuRegistry;
use Stonewright\WpMcp\Admin\RescuePage;
use Stonewright\WpMcp\Security\Adapters\PostAdapter;
use Stonewright\WpMcp\Security\Backup;
use Stonewright\WpMcp\Security\ChangeJournal;
use Stonewright\WpMcp\Security\ConfirmationToken;
use Stonewright\WpMcp\Tests\Unit\Security\Rollback\RollbackTestCase;

/**
 * A is the first body of a page, B the change that was never verified, C a later edit by hand. Rolling B back writes A
 * over C, so the engine asks the person first and the page has to carry the answer.
 *
 * @covers \Stonewright\WpMcp\Admin\RescuePage
 */
final class RescuePageEngineTest extends RollbackTestCase {

	protected function setUp(): void {
		parent::setUp();
		ChangeJournal::reset_for_tests();
		RescuePage::reset_for_tests();
		MenuRegistry::reset_for_tests();
		RescuePage::set_safe_mode_resolver( false );
		$GLOBALS['stonewright_test_user_caps'] = [ 'manage_options' => true ];
		$_GET                                  = [];
		$_POST                                 = [];
		$this->site( static fn (): string => 'healthy' );
	}

	protected function tearDown(): void {
		RescuePage::set_safe_mode_resolver( null );
		RescuePage::reset_for_tests();
		MenuRegistry::reset_for_tests();
		$_GET  = [];
		$_POST = [];
		parent::tearDown();
	}

	/** The change that was never verified, in the ledger and the journal under one id. */
	private function chain(): string {
		$this->make_post( self::POST, [ 'post_title' => 'Fixture page', 'post_content' => 'A body' ] );
		$id       = PostAdapter::record_before( 'stonewright/content-update-page', self::POST );
		$snapshot = Backup::snapshot_post( self::POST, [], false );
		ChangeJournal::arm(
			[
				'change_set_id' => $id,
				'ability'       => 'stonewright/content-update-page',
				'resource_type' => 'post',
				'resource_key'  => (string) self::POST,
				'recipe'        => [ 'type' => 'post_snapshot', 'ref' => $snapshot ],
				'recipe_detail' => [ 'post_id' => self::POST, 'snapshot_id' => $snapshot ],
				'scope'         => 'post',
			]
		);
		$this->edit_post( 'post_content', 'B body' );
		PostAdapter::settle( $id, PostAdapter::capture_after( self::POST ), 'armed' );

		return $id;
	}

	private function review( string $id ): string {
		$_GET = [ 'review' => $id ];
		ob_start();
		try {
			RescuePage::render();
			return (string) ob_get_contents();
		} finally {
			ob_end_clean();
			$_GET = [];
		}
	}

	/** @param array<string, string> $more */
	private function post_rollback( string $id, array $more = [] ): array {
		$_POST = array_merge( [ '_stonewright_nonce' => 'x', 'incident_id' => $id ], $more );

		return RescuePage::process_rollback_request();
	}

	private function hash_in( string $html ): string {
		self::assertSame( 1, preg_match( '/name="expected_current_sha256" value="([a-f0-9]{32})"/', $html, $found ), 'The review carries the state the person is shown.' );

		return $found[1];
	}

	public function test_a_rollback_over_a_later_edit_is_refused_by_the_engine_until_the_review_is_confirmed_with_its_state(): void {
		$id = $this->chain();
		$this->edit_post( 'post_content', 'C body' );
		$html = $this->review( $id );
		$hash = $this->hash_in( $html );

		self::assertMatchesRegularExpression( '/<input type="checkbox" id="sw-rescue-review-drift" name="force_drift" value="1" required/', $html, 'The engine says a confirmation is needed, so the review has the box.' );

		self::assertSame( 'drift', $this->post_rollback( $id )['code'] );
		self::assertSame( 'drift_hash_required', $this->post_rollback( $id, [ 'force_drift' => '1' ] )['code'], 'The override without the state it was given for is refused.' );
		self::assertSame( 'C body', $this->post_field( 'post_content' ), 'The later edit is kept through every refusal.' );
		self::assertSame( 'changed_since_preview', $this->post_rollback( $id, [ 'force_drift' => '1', 'expected_current_sha256' => str_repeat( 'a', 32 ) ] )['code'] );
		self::assertSame( 'C body', $this->post_field( 'post_content' ) );

		self::assertSame( 'rolled_back', $this->post_rollback( $id, [ 'force_drift' => '1', 'expected_current_sha256' => $hash ] )['code'] );
		self::assertSame( 'A body', $this->post_field( 'post_content' ) );
	}

	public function test_a_state_that_changed_after_the_review_was_drawn_is_refused_and_the_next_review_shows_the_new_state(): void {
		$id = $this->chain();
		$this->edit_post( 'post_content', 'C body' );
		$first = $this->hash_in( $this->review( $id ) );
		$this->edit_post( 'post_content', 'D body' );

		self::assertSame( 'changed_since_preview', $this->post_rollback( $id, [ 'force_drift' => '1', 'expected_current_sha256' => $first ] )['code'] );
		self::assertSame( 'D body', $this->post_field( 'post_content' ) );
		self::assertNotSame( $first, $this->hash_in( $this->review( $id ) ), 'The review it returns to has the current state filled in.' );
	}

	public function test_a_refusal_before_the_token_does_not_use_the_token_up(): void {
		$id = $this->chain();
		$this->edit_post( 'post_content', 'C body' );
		$this->production_safe();
		$token = ConfirmationToken::issue( 'stonewright/rescue-rollback', [ 'incident_id' => $id ] );
		$post  = [ 'confirmation_token' => $token, 'confirm_phrase' => 'ROLL BACK' ];

		self::assertSame( 'drift', $this->post_rollback( $id, $post )['code'] );
		self::assertSame( 'C body', $this->post_field( 'post_content' ) );

		$hash = $this->hash_in( $this->review( $id ) );
		self::assertSame( 'rolled_back', $this->post_rollback( $id, $post + [ 'force_drift' => '1', 'expected_current_sha256' => $hash ] )['code'], 'The same token still works.' );
	}

	public function test_a_rollback_over_no_later_edit_needs_no_box_and_no_hash(): void {
		$id   = $this->chain();
		$html = $this->review( $id );

		self::assertStringNotContainsString( 'name="force_drift"', $html );
		self::assertSame( 'rolled_back', $this->post_rollback( $id )['code'] );
		self::assertSame( 'A body', $this->post_field( 'post_content' ) );
	}

	public function test_the_messages_for_the_new_refusals_say_nothing_was_changed_and_the_review_comes_back(): void {
		foreach ( [ 'drift_hash_required', 'changed_since_preview', 'live_unreadable' ] as $code ) {
			$_GET = [ 'rescue' => $code, 'incident' => 'cs-abc' ];
			ob_start();
			RescuePage::render();
			$html = (string) ob_get_clean();
			self::assertStringContainsString( 'Nothing was changed', $html, $code );
			self::assertStringContainsString( 'data-sw-rescue-result', $html, $code );
		}
		$_GET = [];
		self::assertStringContainsString( 'review=cs-abc', RescuePage::outcome_url( [ 'code' => 'drift_hash_required', 'incident_id' => 'cs-abc' ] ) );
	}

	public function test_every_verification_reason_of_the_engine_has_a_kind_and_only_a_failing_check_says_the_site_failed(): void {
		self::assertSame( 'healthy', RescuePage::reason_kind( 'verified' ) );
		self::assertSame( 'not_started', RescuePage::reason_kind( 'not_started' ) );
		self::assertSame( 'unreachable', RescuePage::reason_kind( 'transport_unavailable' ) );
		self::assertSame( 'check_lost', RescuePage::reason_kind( 'check_lost' ), 'A check that passed before no longer answers: not verified, and not a failed site.' );
		self::assertSame( 'site_failed', RescuePage::reason_kind( 'site_failed' ) );
		self::assertSame( 'unreachable', RescuePage::reason_kind( 'a_reason_nobody_has_met' ) );
	}

	public function test_a_check_that_was_lost_keeps_the_incident_open_and_says_so_without_calling_the_site_broken(): void {
		$id = $this->chain();
		ChangeJournal::settle( $id, 'rollback_failed' );
		$this->site( static fn (): string => 'healthy' );
		\Stonewright\WpMcp\Security\HealthProbe::set_transport( static fn ( string $url, array $args ): array => [ 'response' => [ 'code' => 0 ], 'body' => '', 'headers' => [] ] );
		$_POST = [ '_stonewright_nonce' => 'x', 'incident_id' => $id ];

		$outcome = RescuePage::process_recheck_request();

		self::assertNotSame( 'rechecked_resolved', $outcome['code'] );
		$_GET = [ 'rescue' => 'rechecked_check_lost', 'incident' => $id ];
		ob_start();
		RescuePage::render();
		$html = (string) ob_get_clean();
		self::assertStringContainsString( 'passed before this change no longer answers', $html );
		self::assertStringContainsString( 'does not mean the site is broken', $html );
	}

	public function test_the_post_goes_back_to_where_it_came_from_and_nothing_else_is_followed(): void {
		self::assertStringContainsString( 'page=stonewright-rescue', RescuePage::outcome_url( [ 'code' => 'rolled_back', 'incident_id' => 'cs-abc' ] ) );
		$tab = RescuePage::outcome_url( [ 'code' => 'rolled_back', 'incident_id' => 'cs-abc' ], 'changes' );
		self::assertStringContainsString( 'page=stonewright-changes&tab=rescue', $tab );
		self::assertStringContainsString( 'rescue=rolled_back', $tab );
		foreach ( [ 'https://evil.example/', '../x', 'changes&page=other', '' ] as $value ) {
			self::assertStringContainsString( 'page=stonewright-rescue', RescuePage::outcome_url( [ 'code' => 'rolled_back', 'incident_id' => '' ], $value ), 'A value outside the list is the standalone page.' );
		}
	}

	public function test_every_form_and_link_of_the_rescue_tab_stays_on_the_tab(): void {
		$id = $this->chain();
		$this->edit_post( 'post_content', 'C body' );
		RescuePage::add_to_menu_registry();
		// The change has been waiting for its health check long enough to be listed as not verified.
		$GLOBALS['stonewright_test_filters']['stonewright_rescue_now'] = static fn (): int => time() + 4000;
		RescuePage::reset_for_tests();
		$_GET = [];
		ob_start();
		RescuePage::render_tab();
		$html = (string) ob_get_clean();

		unset( $GLOBALS['stonewright_test_filters']['stonewright_rescue_now'] );
		self::assertStringContainsString( 'name="return_to" value="changes"', $html );
		self::assertStringNotContainsString( 'name="return_to" value="rescue"', $html );
		self::assertMatchesRegularExpression( '/href="[^"]*page=stonewright-changes&tab=rescue&review=' . preg_quote( $id, '/' ) . '"/', $html, 'Roll back goes to the review inside the tab.' );

		$GLOBALS['stonewright_test_filters']['stonewright_rescue_now'] = static fn (): int => time() + 4000;
		RescuePage::reset_for_tests();
		ob_start();
		RescuePage::render();
		unset( $GLOBALS['stonewright_test_filters']['stonewright_rescue_now'] );
		$standalone = (string) ob_get_clean();
		self::assertStringContainsString( 'name="return_to" value="rescue"', $standalone );
		self::assertMatchesRegularExpression( '/href="[^"]*page=stonewright-rescue&review=' . preg_quote( $id, '/' ) . '"/', $standalone );
	}
}
