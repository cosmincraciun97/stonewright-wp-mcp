<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Diagnostics;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\Diagnostics\CapabilityPreflight;
use Stonewright\WpMcp\Abilities\Diagnostics\FormDeliveryDiagnostic;
use Stonewright\WpMcp\Abilities\Diagnostics\OAuthHeaderDiagnostic;

/**
	 * @covers \Stonewright\WpMcp\Abilities\Diagnostics\FormDeliveryDiagnostic
	 * @covers \Stonewright\WpMcp\Abilities\Diagnostics\CapabilityPreflight
	 * @covers \Stonewright\WpMcp\Abilities\Diagnostics\OAuthHeaderDiagnostic
 */
final class FormAndCapabilityDiagnosticTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_posts'] = [];
		$GLOBALS['stonewright_test_options'] = [];
		$GLOBALS['stonewright_test_filters'] = [];
		$GLOBALS['stonewright_test_user_caps'] = [];
		$_SERVER = [];
		$_GET = [];
		$GLOBALS['stonewright_test_current_user_id'] = 17;
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_posts'] = [];
		$GLOBALS['stonewright_test_options'] = [];
		$GLOBALS['stonewright_test_filters'] = [];
		$GLOBALS['stonewright_test_user_caps'] = [];
		$_SERVER = [];
		$_GET = [];
	}

	public function test_form_delivery_report_is_content_free_and_does_not_send(): void {
		$GLOBALS['stonewright_test_options'] = [
			'active_plugins' => [ 'smtp-mailer/smtp-mailer.php', 'newsman/newsman.php' ],
			'stonewright_mail_failure_count' => 3,
		];
		$GLOBALS['stonewright_test_posts'][55] = (object) [
			'ID' => 55,
			'post_type' => 'page',
			'post_status' => 'publish',
			'post_author' => 17,
			'meta' => [
				'_elementor_data' => (string) wp_json_encode( [
					[
						'id' => 'form-1',
						'elType' => 'widget',
						'widgetType' => 'form',
						'settings' => [
							'submit_actions' => 'email,newsman',
							'email_to' => 'owner@example.test',
							'email_from' => 'not-an-email',
							'email_reply_to' => 'reply@example.test',
							'email_body' => 'Never return this body.',
						],
						'elements' => [],
					],
				] ),
			],
		];

		$result = ( new FormDeliveryDiagnostic() )->execute( [ 'post_id' => 55 ] );

		self::assertIsArray( $result );
		self::assertTrue( $result['ok'] );
		self::assertFalse( $result['send_attempted'] );
		self::assertSame( [ 'email', 'newsman' ], $result['forms'][0]['configured_actions'] );
		self::assertTrue( $result['forms'][0]['mail_action_present'] );
		self::assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $result['forms'][0]['recipient_domain_hash'] );
		self::assertFalse( $result['forms'][0]['from_valid'] );
		self::assertTrue( $result['providers']['smtp_plugin_detected'] );
		self::assertTrue( $result['providers']['newsman_plugin_detected'] );
		self::assertStringNotContainsString( 'owner@example.test', (string) wp_json_encode( $result ) );
		self::assertStringNotContainsString( 'Never return this body', (string) wp_json_encode( $result ) );
	}

	public function test_capability_preflight_reports_object_denial_without_bypassing_it(): void {
		$GLOBALS['stonewright_test_posts'][56] = (object) [
			'ID' => 56,
			'post_type' => 'page',
			'post_status' => 'publish',
			'post_author' => 23,
		];
		$GLOBALS['stonewright_test_user_caps'] = [ 'read' => true, 'edit_posts' => true, 'edit_post' => false ];
		$GLOBALS['stonewright_test_options'] = [ 'active_plugins' => [ 'publishpress-permissions/publishpress.php' ] ];
		$GLOBALS['stonewright_test_filters']['stonewright_permission_deny_source'] = static fn( mixed $value, int $post_id ): string => 56 === $post_id ? 'object_rule' : (string) $value;

		$result = ( new CapabilityPreflight() )->execute( [ 'post_id' => 56 ] );

		self::assertIsArray( $result );
		self::assertTrue( $result['ok'] );
		self::assertFalse( $result['object_editable'] );
		self::assertFalse( $result['capabilities']['edit_post'] );
		self::assertTrue( $result['permission_filters']['publishpress_detected'] );
		self::assertSame( 'object_rule', $result['deny_source'] );
		self::assertStringContainsString( 'No bypass', $result['remediation'] );
		self::assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $result['user_id_hash'] );
		self::assertArrayHasKey( 'author_id_hash', $result['post'] );
		self::assertArrayNotHasKey( 'author_id', $result['post'] );
	}

	public function test_form_diagnostic_does_not_mistake_fluent_forms_for_smtp_and_handles_dynamic_headers(): void {
		$GLOBALS['stonewright_test_options'] = [ 'active_plugins' => [ 'fluentform/fluentform.php' ] ];
		$GLOBALS['stonewright_test_posts'][57] = (object) [
			'ID' => 57,
			'post_type' => 'page',
			'post_status' => 'publish',
			'meta' => [ '_elementor_data' => (string) wp_json_encode( [ [
				'id' => 'form-dynamic', 'elType' => 'widget', 'widgetType' => 'form', 'elements' => [],
				'settings' => [ 'actions_after_submit' => [ 'email' ], 'email_to' => 'Team <team@example.test>', 'email_from' => '{{site_email}}', 'email_reply_to' => 'Reply <reply@example.test>' ],
			] ] ) ],
		];

		$result = ( new FormDeliveryDiagnostic() )->execute( [ 'post_id' => 57, 'widget_id' => 'form-dynamic' ] );
		self::assertIsArray( $result );
		self::assertFalse( $result['providers']['smtp_plugin_detected'] );
		self::assertSame( 'dynamic', $result['forms'][0]['from_status'] );
		self::assertNull( $result['forms'][0]['from_valid'] );
		self::assertSame( 'valid', $result['forms'][0]['reply_to_status'] );
		self::assertSame( 'not_checked', $result['mailbox_status'] );
		self::assertNull( $result['recent_failure_count'] );

		$missing = ( new FormDeliveryDiagnostic() )->execute( [ 'post_id' => 57, 'widget_id' => 'missing' ] );
		self::assertInstanceOf( \WP_Error::class, $missing );
		self::assertSame( 'stonewright_form_widget_not_found', $missing->get_error_code() );
	}

	public function test_oauth_header_diagnostic_separates_oauth_and_application_password_paths(): void {
		$GLOBALS['stonewright_test_user_caps'] = [ 'manage_options' => true ];
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer SECRET-MUST-NOT-RETURN';

		$result = ( new OAuthHeaderDiagnostic() )->execute( [ 'route' => '/mcp/stonewright-oauth/tools/list' ] );

		self::assertIsArray( $result );
		self::assertTrue( $result['oauth_route'] );
		self::assertFalse( $result['ordinary_rest_route'] );
		self::assertTrue( $result['header_seen_by_server'] );
		self::assertTrue( $result['bearer_parsed'] );
		self::assertTrue( $result['auth_succeeded'] );
		self::assertFalse( $result['application_password_path_succeeded'] );
		self::assertStringNotContainsString( 'SECRET-MUST-NOT-RETURN', (string) wp_json_encode( $result ) );

		$_SERVER = [ 'HTTP_AUTHORIZATION' => 'Basic SECRET-APP-PASSWORD', 'HTTP_X_FORWARDED_PROTO' => 'https' ];
		$result = ( new OAuthHeaderDiagnostic() )->execute( [ 'route' => '/wp-json/wp/v2/pages' ] );

		self::assertTrue( $result['ordinary_rest_route'] );
		self::assertTrue( $result['application_password_path_succeeded'] );
		self::assertFalse( $result['oauth_route'] );
	}

	public function test_oauth_header_diagnostic_flags_proxy_stripping_without_exposing_headers(): void {
		$_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';

		$result = ( new OAuthHeaderDiagnostic() )->execute( [ 'route' => '/mcp/stonewright-oauth' ] );

		self::assertIsArray( $result );
		self::assertTrue( $result['proxy_strip_suspected'] );
		self::assertSame( 'missing', $result['header_source'] );
		self::assertFalse( $result['auth_succeeded'] );
	}
}
