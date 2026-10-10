<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Admin\CustomCodeApprovalPage;
use Stonewright\WpMcp\Security\CustomCodeGrant;

/**
 * @covers \Stonewright\WpMcp\Admin\CustomCodeApprovalPage
 */
final class CustomCodeApprovalPageTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_user_caps'] = [ 'manage_options' => true ];
		$GLOBALS['stonewright_test_current_user_id'] = 9;
		$GLOBALS['stonewright_test_transients'] = [];
		$_GET = [];
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_user_caps'] = [];
		$GLOBALS['stonewright_test_transients'] = [];
		$_GET = [];
	}

	private static function html(): string {
		ob_start();
		CustomCodeApprovalPage::render();

		return (string) ob_get_clean();
	}

	/** @return array{0: \DOMDocument, 1: \DOMXPath} */
	private static function dom( ?string $html = null ): array {
		$previous = libxml_use_internal_errors( true );
		$dom      = new \DOMDocument();
		$dom->loadHTML( '<?xml encoding="utf-8" ?>' . ( $html ?? self::html() ) );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		return [ $dom, new \DOMXPath( $dom ) ];
	}

	/** @param array<string, mixed> $overrides @return array<string, mixed> */
	private static function stage( array $overrides = [] ): array {
		$proposal = CustomCodeGrant::stage_proposal(
			array_merge(
				[
					'path'          => 'functions.php',
					'language'      => 'php',
					'after_sha256'  => hash( 'sha256', 'candidate' ),
					'changed_bytes' => 18,
					'risk_class'    => 'high_risk_active_theme_php',
					'native_gap'    => [
						'reason'        => 'No typed API covers the bootstrap hook.',
						'methods_tried' => [ 'typed_api' ],
					],
					'diff_preview'  => [ 'changed_lines' => 1, 'preview' => '+ candidate' ],
				],
				$overrides
			)
		);
		self::assertIsArray( $proposal );

		return $proposal;
	}

	private static function assert_clean( string $html ): void {
		foreach ( [ 'class="notice', 'notice notice-', 'sw-card', 'stonewright-panel', 'sw-audit-payload', 'sw-actions', 'class="button', 'data-stonewright-copy', '<dl>', '<strong>' ] as $legacy ) {
			self::assertStringNotContainsString( $legacy, $html, $legacy );
		}
		self::assertDoesNotMatchRegularExpression( '/\sstyle=/', $html, 'No inline style attribute.' );
	}

	// ---------------------------------------------------------------------------------------------
	// The safety warning is content on every view
	// ---------------------------------------------------------------------------------------------

	public function test_the_human_approval_warning_is_a_callout_on_every_view_and_not_a_notice_that_gets_moved(): void {
		$views = [
			[],
			[ 'proposal_id' => 'does-not-exist' ],
			[ 'result_id' => 'expired-result' ],
		];
		foreach ( $views as $query ) {
			$_GET = $query;
			$html = self::html();

			self::assertStringContainsString( 'sw-ui-callout--warn', $html );
			self::assertStringContainsString( 'Human approval only.', $html );
			self::assertStringContainsString( 'Agents must show you the proposal and stop.', $html );
			self::assertSame( 1, substr_count( $html, 'Human approval only.' ) );
		}
	}

	// ---------------------------------------------------------------------------------------------
	// Nothing selected
	// ---------------------------------------------------------------------------------------------

	public function test_with_no_proposal_the_page_teaches_what_it_is_for_and_what_to_do(): void {
		[ , $xpath ] = self::dom();
		$html        = self::html();

		self::assertStringContainsString( 'Nothing to approve', $html );
		self::assertStringContainsString( 'When an agent proposes custom code it stops here for you.', $html );
		self::assertStringContainsString( 'dry run', $html );
		self::assertSame( 1, $xpath->query( '//*[@class="sw-ui-empty"]' )->length );
		self::assertSame( 1, $xpath->query( '//a[contains(@class,"sw-ui-btn--primary")][contains(@href,"page=stonewright-sandbox")]' )->length, 'One primary action: go to Custom code.' );
		self::assertSame( 1, $xpath->query( '//*[contains(@class,"sw-ui-btn--primary")]' )->length );
		self::assertSame( 1, $xpath->query( '//a[contains(@class,"sw-ui-link")][contains(@href,"docs")]' )->length, 'One link: how approvals work.' );
		self::assertSame( 0, $xpath->query( '//form' )->length, 'Nothing can be approved from here.' );
		self::assertStringNotContainsString( 'No proposal selected.', $html );
		self::assert_clean( $html );
	}

	// ---------------------------------------------------------------------------------------------
	// A proposal
	// ---------------------------------------------------------------------------------------------

	public function test_render_shows_exact_staged_candidate_and_nonce_form(): void {
		$proposal            = self::stage();
		$_GET['proposal_id'] = $proposal['proposal_id'];

		$html = self::html();

		self::assertStringContainsString( 'functions.php', $html );
		self::assertStringContainsString( hash( 'sha256', 'candidate' ), $html );
		self::assertStringContainsString( 'stonewright_custom_code_approve', $html );
		self::assertStringContainsString( 'Issue one-time grant', $html );
		self::assertStringContainsString( 'Human approval only.', $html );
		self::assertStringContainsString( 'Agents must show you the proposal and stop.', $html );
		self::assertStringNotContainsString( 'candidate</textarea>', $html );
	}

	public function test_the_candidate_is_a_list_of_facts_with_a_risk_badge_and_a_bounded_diff(): void {
		$proposal            = self::stage();
		$_GET['proposal_id'] = $proposal['proposal_id'];
		[ , $xpath ]         = self::dom();
		$html                = self::html();

		self::assertSame( 1, $xpath->query( '//dl[contains(@class,"sw-ui-kv")]' )->length );
		foreach ( [ 'Path', 'Language', 'Risk', 'Changed bytes', 'Candidate SHA-256', 'Native gap' ] as $term ) {
			self::assertSame( 1, $xpath->query( '//dt[normalize-space(.)="' . $term . '"]' )->length, $term );
		}
		self::assertSame( 1, $xpath->query( '//dd[contains(.,"functions.php")]//code' )->length );
		self::assertStringContainsString( 'PHP', $html );
		self::assertStringContainsString( 'No typed API covers the bootstrap hook.', $html );
		self::assertMatchesRegularExpression( '/sw-ui-badge--danger[^>]*>.*?High risk</s', $html, 'A high risk class reads as a danger badge with words.' );
		self::assertStringContainsString( 'high_risk_active_theme_php', $html, 'The exact class stays visible.' );
		self::assertSame( 1, $xpath->query( '//pre[contains(@class,"sw-ui-code__body")][@tabindex="0"][@aria-label]' )->length );
		self::assertStringContainsString( '+ candidate', $html );
		self::assert_clean( $html );
	}

	public function test_the_risk_class_decides_the_badge(): void {
		foreach ( [
			'high_risk_active_theme_php' => [ 'sw-ui-badge--danger', 'High risk' ],
			'elevated'                   => [ 'sw-ui-badge--warn', 'Elevated risk' ],
			'customizer_css'             => [ 'sw-ui-badge--info', 'Standard review' ],
		] as $class => [ $badge, $words ] ) {
			$proposal            = self::stage( [ 'risk_class' => $class ] );
			$_GET['proposal_id'] = $proposal['proposal_id'];
			$html                = self::html();

			self::assertMatchesRegularExpression( '/' . $badge . '[^>]*>.*?' . $words . '</s', $html, $class );
		}
	}

	public function test_the_form_has_one_primary_action_and_still_posts_the_proposal_with_its_nonce(): void {
		$proposal            = self::stage();
		$_GET['proposal_id'] = $proposal['proposal_id'];
		[ , $xpath ]         = self::dom();

		$form = $xpath->query( '//form[.//input[@name="action"][@value="stonewright_custom_code_approve"]]' )->item( 0 );
		self::assertInstanceOf( \DOMElement::class, $form );
		self::assertSame( 'post', $form->getAttribute( 'method' ) );
		self::assertStringContainsString( 'admin-post.php', $form->getAttribute( 'action' ) );
		self::assertSame( $proposal['proposal_id'], $xpath->query( './/input[@name="proposal_id"]', $form )->item( 0 )?->getAttribute( 'value' ) );
		self::assertSame( 1, $xpath->query( './/input[@name="_stonewright_nonce"]', $form )->length );
		self::assertSame( 1, $xpath->query( './/button[@type="submit"][contains(@class,"sw-ui-btn--primary")][contains(normalize-space(.),"Issue one-time grant")]', $form )->length );
		self::assertSame( 1, $xpath->query( '//*[contains(@class,"sw-ui-btn--primary")]' )->length );
		self::assertGreaterThanOrEqual( 1, $xpath->query( '//a[normalize-space(.)="Back to Custom code"]' )->length );
	}

	public function test_a_proposal_that_cannot_be_found_is_an_error_with_the_cause_and_a_way_back(): void {
		$_GET['proposal_id'] = 'does-not-exist';
		$html                = self::html();

		self::assertStringContainsString( 'sw-ui-notice--danger', $html );
		self::assertStringContainsString( 'role="alert"', $html );
		self::assertStringNotContainsString( 'Issue one-time grant', $html );
		self::assertStringContainsString( 'page=stonewright-sandbox', $html );
		self::assert_clean( $html );
	}

	// ---------------------------------------------------------------------------------------------
	// A grant
	// ---------------------------------------------------------------------------------------------

	public function test_grant_result_has_a_functional_copy_control_and_consumes_the_secret(): void {
		$_GET['result_id'] = 'grant-result';
		$GLOBALS['stonewright_test_transients']['sw_cc_result_grant-result'] = [
			'ok'           => true,
			'token'        => 'one-time-secret-token',
			'path'         => 'style.css',
			'after_sha256' => hash( 'sha256', 'candidate' ),
		];

		$html = self::html();

		self::assertStringContainsString( 'data-sw-ui-copy="#stonewright-custom-code-grant"', $html );
		self::assertStringContainsString( 'id="stonewright-custom-code-grant"', $html );
		self::assertStringContainsString( 'Copy approval token', $html );
		self::assertStringContainsString( 'one-time-secret-token', $html );
		self::assertArrayNotHasKey( 'sw_cc_result_grant-result', $GLOBALS['stonewright_test_transients'] );
	}

	public function test_the_token_is_never_obscured_and_the_receipt_binds_it_to_one_path_and_hash(): void {
		$_GET['result_id'] = 'grant-result';
		$GLOBALS['stonewright_test_transients']['sw_cc_result_grant-result'] = [
			'ok'           => true,
			'token'        => 'one-time-secret-token',
			'path'         => 'style.css',
			'after_sha256' => hash( 'sha256', 'candidate' ),
		];
		$html        = self::html(); // The grant is shown once: read the page once.
		[ , $xpath ] = self::dom( $html );

		self::assertSame( 0, $xpath->query( '//input[@type="password"]' )->length, 'The approval token is shown, not masked.' );
		self::assertSame( 1, $xpath->query( '//code[@id="stonewright-custom-code-grant"]' )->length );
		self::assertSame( 1, $xpath->query( '//dl[contains(@class,"sw-ui-kv")]//dd[contains(.,"style.css")]' )->length );
		self::assertSame( 1, $xpath->query( '//dl[contains(@class,"sw-ui-kv")]//dd[contains(.,"' . hash( 'sha256', 'candidate' ) . '")]' )->length );
		self::assertStringContainsString( 'Copy it now; this screen will not show it again.', $html );
		self::assertStringContainsString( 'Expires quickly and works once for this exact path and hash.', $html );
		self::assertStringContainsString( 'sw-ui-notice--ok', $html );
		self::assertSame( 1, preg_match_all( '/role="status"/', $html ) - preg_match_all( '/class="sw-ui-copy__status" role="status"/', $html ), 'The success is announced once, beside the copy field\'s own status line.' );
		self::assertSame( 0, $xpath->query( '//*[contains(@class,"sw-ui-btn--primary")]' )->length, 'Copying is the action; nothing on this screen is primary.' );
		self::assert_clean( $html );
	}

	public function test_an_expired_result_and_a_failed_approval_are_errors_with_the_next_step(): void {
		$_GET['result_id'] = 'gone';
		$html              = self::html();
		self::assertStringContainsString( 'Grant result expired. Run dry_run again.', $html );
		self::assertStringContainsString( 'sw-ui-notice--danger', $html );

		$_GET['result_id'] = 'failed';
		$GLOBALS['stonewright_test_transients']['sw_cc_result_failed'] = [ 'ok' => false, 'code' => 'x', 'message' => 'The proposal has expired.' ];
		$html = self::html();
		self::assertStringContainsString( 'The proposal has expired.', $html );
		self::assertStringContainsString( 'role="alert"', $html );
	}

	public function test_a_user_without_manage_options_cannot_open_the_page(): void {
		$GLOBALS['stonewright_test_user_caps'] = [];

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'You do not have permission to approve custom code.' );
		CustomCodeApprovalPage::render();
	}
}
