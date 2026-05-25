<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Context;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Abilities\Memory\LearningRecord;
use Stonewright\WpMcp\Abilities\Site\Info;
use Stonewright\WpMcp\Abilities\WpCli\Discover as WpCliDiscover;
use Stonewright\WpMcp\Abilities\WpCli\Run as WpCliRun;
use Stonewright\WpMcp\Abilities\WpCli\Status as WpCliStatus;
use Stonewright\WpMcp\Context\ContextToken;
use Stonewright\WpMcp\Core\AbilityRegistry;

/**
 * @covers \Stonewright\WpMcp\Core\AbilityRegistry
 * @covers \Stonewright\WpMcp\Context\ContextToken
 */
final class ContextGateTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_current_user_id'] = 11;
		$GLOBALS['stonewright_test_transients'] = [];
	}

	public function test_write_ability_requires_context_token(): void {
		$result = AbilityRegistry::execute_with_context_guard(
			$this->make_write_ability(),
			[ 'value' => 'x' ]
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_context_required', $result->get_error_code() );
	}

	public function test_write_ability_accepts_valid_context_token_and_strips_it(): void {
		$issued = ContextToken::issue( 'Update page', 'stonewright/test-write' );
		$result = AbilityRegistry::execute_with_context_guard(
			$this->make_write_ability(),
			[
				'value'                    => 'x',
				'stonewright_context_token' => $issued['token'],
			]
		);

		self::assertSame( [ 'received_keys' => [ 'value' ] ], $result );
	}

	public function test_read_ability_does_not_require_context_token(): void {
		$result = AbilityRegistry::execute_with_context_guard( new Info(), [] );

		self::assertIsArray( $result );
		self::assertArrayHasKey( 'wp_version', $result );
	}

	public function test_learning_record_requires_context_token(): void {
		$result = AbilityRegistry::execute_with_context_guard(
			new LearningRecord(),
			[
				'topic'      => 'Repeated mistake',
				'correction' => 'Remember this next time.',
			]
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_context_required', $result->get_error_code() );
	}

	public function test_wp_cli_run_requires_context_token_but_status_and_discover_are_discovery(): void {
		$run = AbilityRegistry::execute_with_context_guard(
			new WpCliRun(),
			[ 'command' => [ 'post', 'create', '--post_type=page' ] ]
		);
		self::assertInstanceOf( \WP_Error::class, $run );
		self::assertSame( 'stonewright_context_required', $run->get_error_code() );

		$GLOBALS['stonewright_test_user_logged_in']      = true;
		$GLOBALS['stonewright_test_user_caps']           = [ 'manage_options' => true ];
		$GLOBALS['stonewright_test_companion_responses'] = [
			'/wp-cli/status' => [
				'ok'        => true,
				'available' => true,
				'stdout'    => '{}',
				'stderr'    => '',
				'exit_code' => 0,
			],
			'/wp-cli/discover' => [
				'ok'        => true,
				'available' => true,
				'stdout'    => '{}',
				'stderr'    => '',
				'exit_code' => 0,
			],
		];

		$status = AbilityRegistry::execute_with_context_guard( new WpCliStatus(), [] );
		self::assertIsArray( $status );
		self::assertTrue( $status['available'] );

		$discover = AbilityRegistry::execute_with_context_guard( new WpCliDiscover(), [] );
		self::assertIsArray( $discover );
		self::assertTrue( $discover['available'] );
	}

	public function test_context_token_is_published_in_schemas_for_gated_abilities(): void {
		$abilities = AbilityRegistry::enabled_abilities();
		$by_name   = [];
		foreach ( $abilities as $ability ) {
			$by_name[ $ability['name'] ] = $ability;
			self::assertArrayHasKey( 'mcp_tool_name', $ability );
			self::assertStringNotContainsString( '/', $ability['mcp_tool_name'] );
			self::assertSame( str_replace( '/', '-', $ability['name'] ), $ability['mcp_tool_name'] );
		}

		self::assertSame( 'stonewright-context-bootstrap', $by_name['stonewright/context-bootstrap']['mcp_tool_name'] );

		$run_schema = $by_name['stonewright/wp-cli-run']['input_schema'];
		self::assertArrayHasKey( 'stonewright_context_token', $run_schema['properties'] );
		self::assertContains( 'stonewright_context_token', $run_schema['required'] );

		$status_schema = $by_name['stonewright/wp-cli-status']['input_schema'];
		self::assertArrayNotHasKey( 'stonewright_context_token', $status_schema['properties'] ?? [] );
	}

	private function make_write_ability(): AbilityKernel {
		return new class() extends AbilityKernel {
			public function name(): string {
				return 'stonewright/test-write';
			}

			public function label(): string {
				return 'Test write';
			}

			public function description(): string {
				return 'Test write ability.';
			}

			public function category(): string {
				return 'content';
			}

			public function execute( array $args ): array|\WP_Error {
				return [ 'received_keys' => array_keys( $args ) ];
			}
		};
	}
}
