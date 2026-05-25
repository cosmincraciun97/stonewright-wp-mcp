<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\WpCli;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\WpCli\Discover;
use Stonewright\WpMcp\Abilities\WpCli\Run;
use Stonewright\WpMcp\Abilities\WpCli\Status;
use Stonewright\WpMcp\Security\ConfirmationToken;

/**
 * @covers \Stonewright\WpMcp\Abilities\WpCli\Run
 * @covers \Stonewright\WpMcp\Abilities\WpCli\Status
 * @covers \Stonewright\WpMcp\Abilities\WpCli\Discover
 */
final class WpCliAbilitiesTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_user_logged_in']       = true;
		$GLOBALS['stonewright_test_user_caps']            = [ 'manage_options' => true, 'read' => true ];
		$GLOBALS['stonewright_test_companion_responses']  = [];
		$GLOBALS['stonewright_test_companion_requests']   = [];
		$GLOBALS['stonewright_test_transients']           = [];
		$GLOBALS['stonewright_test_options']              = [
			'stonewright_companion_url'   => 'http://127.0.0.1:8765',
			'stonewright_companion_token' => 'test-token',
		];
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_user_logged_in']      = false;
		$GLOBALS['stonewright_test_user_caps']           = [];
		$GLOBALS['stonewright_test_companion_responses'] = [];
		$GLOBALS['stonewright_test_companion_requests']  = [];
		$GLOBALS['stonewright_test_transients']          = [];
		$GLOBALS['stonewright_test_options']             = [];
	}

	public function test_run_posts_command_to_companion_wp_cli_endpoint(): void {
		$GLOBALS['stonewright_test_companion_responses']['/wp-cli/run'] = [
			'ok'        => true,
			'stdout'    => "42\n",
			'stderr'    => '',
			'exit_code' => 0,
			'command'   => [ 'wp', 'post', 'create', '--post_type=page' ],
		];

		$result = ( new Run() )->execute(
			[
				'command' => [ 'post', 'create', '--post_type=page', '--post_title=Home' ],
				'path'    => 'D:/Sites/site',
				'url'     => 'https://example.test',
				'user'    => 'admin',
			]
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['ok'] );
		$this->assertSame( "42\n", $result['stdout'] );
		$this->assertSame( '/wp-cli/run', $GLOBALS['stonewright_test_companion_requests'][1]['path'] );
		$this->assertSame( [ 'post', 'create', '--post_type=page', '--post_title=Home' ], $GLOBALS['stonewright_test_companion_requests'][1]['body']['command'] );
	}

	public function test_run_requires_confirmation_in_production_safe_mode_and_does_not_forward_token(): void {
		$GLOBALS['stonewright_test_options']['stonewright_mode'] = 'production-safe';

		$args = [
			'command' => [ 'post', 'create', '--post_type=page', '--post_title=Home' ],
			'path'    => 'D:/Sites/site',
		];

		$missing = ( new Run() )->execute( $args );
		$this->assertInstanceOf( \WP_Error::class, $missing );
		$this->assertSame( 'stonewright_confirmation_required', $missing->get_error_code() );

		$GLOBALS['stonewright_test_companion_responses']['/wp-cli/run'] = [
			'ok'        => true,
			'stdout'    => "42\n",
			'stderr'    => '',
			'exit_code' => 0,
		];

		$token  = ConfirmationToken::issue( 'stonewright/wp-cli-run', $args );
		$result = ( new Run() )->execute( $args + [ 'confirmation_token' => $token ] );

		$this->assertIsArray( $result );
		$this->assertSame( '/wp-cli/run', $GLOBALS['stonewright_test_companion_requests'][1]['path'] );
		$this->assertArrayNotHasKey( 'confirmation_token', $GLOBALS['stonewright_test_companion_requests'][1]['body'] );
	}

	public function test_status_and_discover_have_dedicated_endpoints(): void {
		$GLOBALS['stonewright_test_companion_responses']['/wp-cli/status'] = [
			'ok'        => true,
			'available' => true,
			'stdout'    => '{"wp_cli_version":"2.12.0"}',
			'stderr'    => '',
			'exit_code' => 0,
		];
		$GLOBALS['stonewright_test_companion_responses']['/wp-cli/discover'] = [
			'ok'          => true,
			'available'   => true,
			'parsed_json' => [ 'name' => 'wp', 'subcommands' => [ [ 'name' => 'post' ] ] ],
			'stdout'      => '{"name":"wp"}',
			'stderr'      => '',
			'exit_code'   => 0,
		];

		$status   = ( new Status() )->execute( [] );
		$discover = ( new Discover() )->execute( [] );

		$this->assertIsArray( $status );
		$this->assertTrue( $status['available'] );
		$this->assertIsArray( $discover );
		$this->assertSame( 'wp', $discover['parsed_json']['name'] );
		$this->assertSame( '/wp-cli/status', $GLOBALS['stonewright_test_companion_requests'][1]['path'] );
		$this->assertSame( '/wp-cli/discover', $GLOBALS['stonewright_test_companion_requests'][2]['path'] );
	}

	public function test_wp_cli_companion_unavailable_returns_structured_fallbacks(): void {
		$GLOBALS['stonewright_test_companion_responses']['/wp-cli/status'] = new \WP_Error(
			'http_request_failed',
			'Connection refused'
		);
		$GLOBALS['stonewright_test_companion_responses']['/wp-cli/run'] = new \WP_Error(
			'http_request_failed',
			'Connection refused'
		);

		$status = ( new Status() )->execute( [] );
		$this->assertIsArray( $status );
		$this->assertFalse( $status['ok'] );
		$this->assertFalse( $status['available'] );
		$this->assertSame( 'http://127.0.0.1:8765', $status['companion_url'] );
		$this->assertContains( 'companion_wp_cli_run', $status['recommended_fallbacks'] );
		$this->assertStringContainsString( 'PORT=8765', $status['setup_hint'] );

		$run = ( new Run() )->execute( [ 'command' => [ 'post', 'list' ] ] );
		$this->assertIsArray( $run );
		$this->assertFalse( $run['ok'] );
		$this->assertFalse( $run['available'] );
		$this->assertSame( [ 'post', 'list' ], $run['command'] );
	}
}
