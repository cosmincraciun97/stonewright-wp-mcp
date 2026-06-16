<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\WpCli;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\WpCli\BatchRun;
use Stonewright\WpMcp\Abilities\WpCli\Discover;
use Stonewright\WpMcp\Abilities\WpCli\JobStart;
use Stonewright\WpMcp\Abilities\WpCli\JobStatus;
use Stonewright\WpMcp\Abilities\WpCli\Run;
use Stonewright\WpMcp\Abilities\WpCli\Status;
use Stonewright\WpMcp\Security\ConfirmationToken;

/**
 * @covers \Stonewright\WpMcp\Abilities\WpCli\Run
 * @covers \Stonewright\WpMcp\Abilities\WpCli\BatchRun
 * @covers \Stonewright\WpMcp\Abilities\WpCli\Status
 * @covers \Stonewright\WpMcp\Abilities\WpCli\Discover
 * @covers \Stonewright\WpMcp\Abilities\WpCli\JobStart
 * @covers \Stonewright\WpMcp\Abilities\WpCli\JobStatus
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

	public function test_batch_run_posts_commands_to_companion_batch_endpoint_with_summary_mode(): void {
		$GLOBALS['stonewright_test_companion_responses']['/wp-cli/batch'] = [
			'ok'        => true,
			'count'     => 2,
			'succeeded' => 2,
			'failed'    => 0,
			'stopped'   => false,
			'results'   => [
				[
					'ok'           => true,
					'available'    => true,
					'exit_code'    => 0,
					'duration_ms'  => 12,
					'stdout_bytes' => 1200,
					'stderr_bytes' => 0,
				],
			],
		];

		$result = ( new BatchRun() )->execute(
			[
				'commands'     => [
					[ 'post', 'create', '--post_type=page', '--post_title=Home' ],
					[ 'post', 'meta', 'update', '42', '_elementor_edit_mode', 'builder' ],
				],
				'path'         => 'D:/Sites/site',
				'responseMode' => 'summary',
			]
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['ok'] );
		$this->assertSame( '/wp-cli/batch', $GLOBALS['stonewright_test_companion_requests'][1]['path'] );
		$this->assertSame( 'summary', $GLOBALS['stonewright_test_companion_requests'][1]['body']['responseMode'] );
		$this->assertSame( [ 'post', 'meta', 'update', '42', '_elementor_edit_mode', 'builder' ], $GLOBALS['stonewright_test_companion_requests'][1]['body']['commands'][1] );
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

	public function test_batch_run_requires_confirmation_in_production_safe_mode_and_does_not_forward_token(): void {
		$GLOBALS['stonewright_test_options']['stonewright_mode'] = 'production-safe';

		$args = [
			'commands' => [
				[ 'option', 'update', 'cptui_post_types', '{"speaker":{"name":"speaker"}}', '--format=json' ],
				[ 'post', 'delete', '42' ],
			],
		];

		$missing = ( new BatchRun() )->execute( $args );
		$this->assertInstanceOf( \WP_Error::class, $missing );
		$this->assertSame( 'stonewright_confirmation_required', $missing->get_error_code() );

		$GLOBALS['stonewright_test_companion_responses']['/wp-cli/batch'] = [
			'ok'        => true,
			'count'     => 2,
			'succeeded' => 2,
			'failed'    => 0,
			'stopped'   => false,
			'results'   => [],
		];

		$token  = ConfirmationToken::issue( 'stonewright/wp-cli-batch-run', $args );
		$result = ( new BatchRun() )->execute( $args + [ 'confirmation_token' => $token ] );

		$this->assertIsArray( $result );
		$this->assertSame( '/wp-cli/batch', $GLOBALS['stonewright_test_companion_requests'][1]['path'] );
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
		$this->assertSame( 'summary', $GLOBALS['stonewright_test_companion_requests'][2]['body']['responseMode'] );
		$this->assertSame( 80, $GLOBALS['stonewright_test_companion_requests'][2]['body']['maxCommands'] );
	}

	public function test_discover_forwards_summary_filters_to_companion(): void {
		$GLOBALS['stonewright_test_companion_responses']['/wp-cli/discover'] = [
			'ok'                     => true,
			'available'              => true,
			'exit_code'              => 0,
			'duration_ms'            => 9,
			'stdout_bytes'           => 120000,
			'stderr_bytes'           => 0,
			'command_count'          => 140,
			'returned_command_count' => 3,
			'truncated'              => false,
			'command_paths'          => [ 'wp acf', 'wp acf field', 'wp post meta' ],
			'root_commands'          => [ 'wp' ],
			'command_filter'         => [ 'acf', 'post meta' ],
		];

		$result = ( new Discover() )->execute(
			[
				'commandFilter' => [ 'acf', 'post meta' ],
				'maxCommands'   => 10,
				'responseMode'  => 'summary',
			]
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['ok'] );
		$this->assertSame( [ 'wp acf', 'wp acf field', 'wp post meta' ], $result['command_paths'] );
		$this->assertSame( [ 'acf', 'post meta' ], $GLOBALS['stonewright_test_companion_requests'][1]['body']['commandFilter'] );
		$this->assertSame( 10, $GLOBALS['stonewright_test_companion_requests'][1]['body']['maxCommands'] );
	}

	public function test_wp_cli_background_jobs_proxy_to_companion(): void {
		$GLOBALS['stonewright_test_companion_responses']['/wp-cli/job-start'] = [
			'ok'            => true,
			'job_id'        => 'wpcli_123',
			'status'        => 'running',
			'kind'          => 'batch',
			'command_count' => 2,
			'started_at'    => '2026-06-16T20:00:00.000Z',
			'completed_at'  => null,
			'duration_ms'   => 1,
			'result'        => null,
		];
		$GLOBALS['stonewright_test_companion_responses']['/wp-cli/job-status'] = [
			'ok'            => true,
			'job_id'        => 'wpcli_123',
			'status'        => 'succeeded',
			'kind'          => 'batch',
			'command_count' => 2,
			'started_at'    => '2026-06-16T20:00:00.000Z',
			'completed_at'  => '2026-06-16T20:00:02.000Z',
			'duration_ms'   => 2000,
			'result'        => [ 'ok' => true, 'count' => 2, 'succeeded' => 2, 'failed' => 0 ],
		];

		$start = ( new JobStart() )->execute(
			[
				'commands'     => [
					[ 'post', 'list' ],
					[ 'cache', 'flush' ],
				],
				'responseMode' => 'summary',
			]
		);
		$status = ( new JobStatus() )->execute( [ 'jobId' => 'wpcli_123' ] );

		$this->assertIsArray( $start );
		$this->assertSame( 'wpcli_123', $start['job_id'] );
		$this->assertSame( '/wp-cli/job-start', $GLOBALS['stonewright_test_companion_requests'][1]['path'] );
		$this->assertSame( 'summary', $GLOBALS['stonewright_test_companion_requests'][1]['body']['responseMode'] );
		$this->assertIsArray( $status );
		$this->assertSame( 'succeeded', $status['status'] );
		$this->assertSame( '/wp-cli/job-status', $GLOBALS['stonewright_test_companion_requests'][2]['path'] );
		$this->assertSame( 'wpcli_123', $GLOBALS['stonewright_test_companion_requests'][2]['body']['jobId'] );
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
		$this->assertStringContainsString( 'STONEWRIGHT_HTTP_ENABLE=1', $status['setup_hint'] );
		$this->assertStringContainsString( 'PORT=8765', $status['setup_hint'] );

		$run = ( new Run() )->execute( [ 'command' => [ 'post', 'list' ] ] );
		$this->assertIsArray( $run );
		$this->assertFalse( $run['ok'] );
		$this->assertFalse( $run['available'] );
		$this->assertSame( [ 'post', 'list' ], $run['command'] );
	}
}
