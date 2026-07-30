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

	public function test_render_shows_exact_staged_candidate_and_nonce_form(): void {
		$proposal = CustomCodeGrant::stage_proposal(
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
			]
		);
		self::assertIsArray( $proposal );
		$_GET['proposal_id'] = $proposal['proposal_id'];

		ob_start();
		CustomCodeApprovalPage::render();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'functions.php', $html );
		self::assertStringContainsString( hash( 'sha256', 'candidate' ), $html );
		self::assertStringContainsString( 'stonewright_custom_code_approve', $html );
		self::assertStringContainsString( 'Issue one-time grant', $html );
		self::assertStringNotContainsString( 'candidate</textarea>', $html );
	}

	public function test_grant_result_has_a_functional_copy_control_and_consumes_the_secret(): void {
		$_GET['result_id'] = 'grant-result';
		$GLOBALS['stonewright_test_transients']['sw_cc_result_grant-result'] = [
			'ok'           => true,
			'token'        => 'one-time-secret-token',
			'path'         => 'style.css',
			'after_sha256' => hash( 'sha256', 'candidate' ),
		];

		ob_start();
		CustomCodeApprovalPage::render();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'id="stonewright-custom-code-grant"', $html );
		self::assertStringContainsString( 'data-stonewright-copy="stonewright-custom-code-grant"', $html );
		self::assertStringContainsString( 'Copy approval token', $html );
		self::assertStringContainsString( 'one-time-secret-token', $html );
		self::assertArrayNotHasKey( 'sw_cc_result_grant-result', $GLOBALS['stonewright_test_transients'] );
	}
}
