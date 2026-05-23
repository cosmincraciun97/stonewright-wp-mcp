<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Abilities\System;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\System\RunWpCli;

/**
 * @covers \Stonewright\WpMcp\Abilities\System\RunWpCli
 */
class RunWpCliTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['stonewright_test_home_url'] = 'http://mcp-test.local';
		$GLOBALS['current_user_id']           = 42;
		$_SERVER['HTTP_HOST']                 = 'mcp-test.local';
		update_option( 'stonewright_mode', 'development' );
	}

	protected function tearDown(): void {
		delete_option( 'stonewright_mode' );
		parent::tearDown();
	}

	public function test_name_label_category(): void {
		$ability = new RunWpCli();
		self::assertSame( 'stonewright/system-run-wpcli', $ability->name() );
		self::assertSame( 'Run WP-CLI command', $ability->label() );
		self::assertSame( 'system', $ability->category() );
	}

	public function test_input_schema_has_required_command(): void {
		$ability = new RunWpCli();
		$schema  = $ability->input_schema();
		self::assertContains( 'command', $schema['required'] );
		self::assertArrayHasKey( 'command', $schema['properties'] );
		self::assertArrayHasKey( 'args', $schema['properties'] );
		self::assertArrayHasKey( 'cwd', $schema['properties'] );
	}

	public function test_missing_command_returns_invalid_input_error(): void {
		$ability = new RunWpCli();
		$result  = $ability->execute( [] );
		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_invalid_input', $result->get_error_code() );
	}

	public function test_empty_command_returns_invalid_input_error(): void {
		$ability = new RunWpCli();
		$result  = $ability->execute( [ 'command' => '' ] );
		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_invalid_input', $result->get_error_code() );
	}

	public function test_write_subcommand_blocked_in_production_safe_mode(): void {
		update_option( 'stonewright_mode', 'production-safe' );
		$ability = new RunWpCli();
		$result  = $ability->execute( [ 'command' => 'option', 'args' => [ 'update', 'siteurl', 'http://evil.com' ] ] );
		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_production_safe', $result->get_error_code() );
	}

	public function test_delete_subcommand_blocked_in_production_safe_mode(): void {
		update_option( 'stonewright_mode', 'production-safe' );
		$ability = new RunWpCli();
		$result  = $ability->execute( [ 'command' => 'option', 'args' => [ 'delete', 'my-option' ] ] );
		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_production_safe', $result->get_error_code() );
	}

	public function test_readonly_subcommand_not_blocked_in_production_safe_mode(): void {
		update_option( 'stonewright_mode', 'production-safe' );
		$ability = new RunWpCli();
		$result  = $ability->execute( [ 'command' => 'option', 'args' => [ 'get', 'siteurl' ] ] );
		// 'get' is read-only — must NOT return production_safe error.
		// Will be a WP_Error because companion is unreachable in tests, or an array if stubbed.
		if ( $result instanceof \WP_Error ) {
			self::assertNotSame(
				'stonewright_production_safe',
				$result->get_error_code(),
				'Read-only subcommand "get" must not be blocked in production-safe mode.'
			);
		} else {
			self::assertIsArray( $result, 'Expected array or WP_Error from RunWpCli::execute().' );
		}
	}

	public function test_list_subcommand_not_blocked_in_production_safe_mode(): void {
		update_option( 'stonewright_mode', 'production-safe' );
		$ability = new RunWpCli();
		$result  = $ability->execute( [ 'command' => 'plugin', 'args' => [ 'list' ] ] );
		if ( $result instanceof \WP_Error ) {
			self::assertNotSame(
				'stonewright_production_safe',
				$result->get_error_code(),
				'Read-only subcommand "list" must not be blocked in production-safe mode.'
			);
		} else {
			self::assertIsArray( $result, 'Expected array or WP_Error from RunWpCli::execute().' );
		}
	}

	public function test_companion_returns_wpcli_result_in_development(): void {
		// In unit tests the companion is stubbed via bootstrap globals.
		// CompanionClient::post() returns the stubbed /wpcli response.
		$ability = new RunWpCli();
		$result  = $ability->execute( [ 'command' => 'option', 'args' => [ 'get', 'siteurl' ] ] );
		// Either a WP_Error (companion truly unreachable) or an array with stdout/stderr/exit_code.
		if ( is_array( $result ) ) {
			self::assertArrayHasKey( 'stdout', $result );
			self::assertArrayHasKey( 'exit_code', $result );
		} else {
			self::assertInstanceOf( \WP_Error::class, $result );
		}
	}

	public function test_development_mode_allows_write_subcommands(): void {
		// In development mode there is no subcommand restriction.
		// The companion stub returns a result (or WP_Error if truly unreachable),
		// but the error MUST NOT be stonewright_production_safe.
		$ability = new RunWpCli();
		$result  = $ability->execute( [ 'command' => 'option', 'args' => [ 'update', 'my-option', 'value' ] ] );
		self::assertNotInstanceOf( \WP_Error::class, $result,
			'Development mode should not block write subcommands with production_safe error.' );
	}
}
