<?php
/**
 * plugin-activate, plugin-deactivate and plugin-delete: the plugin must exist, Stonewright is told apart by its
 * basename, the checks run before a confirmation token is spent, and a delete is reported only when it happened.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Abilities\PluginsManage;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\PluginsManage\PluginActivate;
use Stonewright\WpMcp\Abilities\PluginsManage\PluginDeactivate;
use Stonewright\WpMcp\Abilities\PluginsManage\PluginDelete;
use Stonewright\WpMcp\Security\ConfirmationToken;

/**
 * @covers \Stonewright\WpMcp\Abilities\PluginsManage\PluginActivate
 * @covers \Stonewright\WpMcp\Abilities\PluginsManage\PluginDeactivate
 * @covers \Stonewright\WpMcp\Abilities\PluginsManage\PluginDelete
 * @covers \Stonewright\WpMcp\Abilities\PluginsManage\PluginTarget
 */
final class PluginManageGuardsTest extends TestCase {

	private const OTHER = 'stonewright-addons/stonewright-addons.php';

	private const UNRELATED = 'hello-dolly/hello.php';

	protected function setUp(): void {
		$GLOBALS['stonewright_test_user_logged_in']  = true;
		$GLOBALS['stonewright_test_current_user_id'] = 1;
		$GLOBALS['stonewright_test_user_caps']       = [ 'activate_plugins' => true, 'delete_plugins' => true, 'manage_options' => true ];
		$GLOBALS['stonewright_test_options']         = [ 'stonewright_mode' => 'development' ];
		$GLOBALS['stonewright_test_active_plugins']  = [];
		$GLOBALS['stonewright_test_deleted_plugins'] = [];
		$GLOBALS['stonewright_test_extra_plugins']   = [
			self::OTHER     => [ 'Name' => 'Add-ons for another product', 'Version' => '1.0.0' ],
			self::UNRELATED => [ 'Name' => 'Hello Dolly', 'Version' => '1.7.2' ],
		];
		unset( $GLOBALS['stonewright_test_delete_plugins_result'] );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['stonewright_test_delete_plugins_result'], $GLOBALS['stonewright_test_extra_plugins'] );
		$GLOBALS['stonewright_test_user_caps']       = [];
		$GLOBALS['stonewright_test_options']         = [];
		$GLOBALS['stonewright_test_active_plugins']  = [];
		$GLOBALS['stonewright_test_deleted_plugins'] = [];
		$GLOBALS['stonewright_test_transients']      = [];
	}

	/** @return array<string, array{0: class-string<\Stonewright\WpMcp\Abilities\AbilityKernel>}> */
	public static function abilities(): array {
		return [
			'plugin-activate'   => [ PluginActivate::class ],
			'plugin-deactivate' => [ PluginDeactivate::class ],
			'plugin-delete'     => [ PluginDelete::class ],
		];
	}

	/**
	 * @param class-string<\Stonewright\WpMcp\Abilities\AbilityKernel> $class
	 * @dataProvider abilities
	 */
	public function test_an_unknown_plugin_is_refused_before_anything_runs( string $class ): void {
		$result = ( new $class() )->execute( [ 'plugin' => 'no-such-plugin/no-such-plugin.php' ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_plugin_not_found', $result->get_error_code() );
		self::assertSame( 404, $result->get_error_data()['status'] ?? null );
		self::assertSame( [], $GLOBALS['stonewright_test_active_plugins'] );
		self::assertSame( [], $GLOBALS['stonewright_test_deleted_plugins'] );
	}

	/**
	 * @param class-string<\Stonewright\WpMcp\Abilities\AbilityKernel> $class
	 * @dataProvider abilities
	 */
	public function test_an_unknown_plugin_is_refused_before_the_token_is_looked_at( string $class ): void {
		$GLOBALS['stonewright_test_options']['stonewright_mode'] = 'production-safe';

		$result = ( new $class() )->execute( [ 'plugin' => 'no-such-plugin/no-such-plugin.php' ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_plugin_not_found', $result->get_error_code(), 'Not the missing-token error.' );
	}

	/** @return array<string, array{0: class-string<\Stonewright\WpMcp\Abilities\AbilityKernel>}> */
	public static function token_gated_abilities(): array {
		return [
			'plugin-activate'   => [ PluginActivate::class ],
			'plugin-deactivate' => [ PluginDeactivate::class ],
		];
	}

	/**
	 * @param class-string<\Stonewright\WpMcp\Abilities\AbilityKernel> $class
	 * @dataProvider token_gated_abilities
	 */
	public function test_the_description_names_the_production_safe_token( string $class ): void {
		self::assertStringContainsString( 'confirmation token in production-safe mode', ( new $class() )->description() );
	}

	/** @return array<string, array{0: class-string<\Stonewright\WpMcp\Abilities\AbilityKernel>}> */
	public static function destructive_abilities(): array {
		return [
			'plugin-deactivate' => [ PluginDeactivate::class ],
			'plugin-delete'     => [ PluginDelete::class ],
		];
	}

	/**
	 * @param class-string<\Stonewright\WpMcp\Abilities\AbilityKernel> $class
	 * @dataProvider destructive_abilities
	 */
	public function test_stonewright_itself_is_refused_before_the_token_is_spent( string $class ): void {
		$GLOBALS['stonewright_test_options']['stonewright_mode'] = 'production-safe';
		$args  = [ 'plugin' => 'stonewright/stonewright.php' ];
		$token = ConfirmationToken::issue( ( new $class() )->name(), $args );

		$refused = ( new $class() )->execute( $args + [ 'confirmation_token' => $token ] );

		self::assertInstanceOf( \WP_Error::class, $refused );
		self::assertSame( 'stonewright_self_protection', $refused->get_error_code() );
		// The token was not consumed: verifying it still works.
		self::assertNotInstanceOf( \WP_Error::class, ConfirmationToken::verify_or_error( $token, ( new $class() )->name(), $args ), 'The refusal did not use up the token.' );
	}

	public function test_a_plugin_whose_name_merely_contains_stonewright_is_not_treated_as_stonewright(): void {
		$result = ( new PluginDeactivate() )->execute( [ 'plugin' => self::OTHER ] );

		self::assertIsArray( $result, 'Only the plugin itself is protected, not every plugin with a similar name.' );
		self::assertSame( self::OTHER, $result['plugin'] );
	}

	public function test_the_self_check_normalises_the_plugin_path(): void {
		foreach ( [ '/stonewright/stonewright.php', 'stonewright\\stonewright.php', 'stonewright/stonewright.php' ] as $variant ) {
			$result = ( new PluginDeactivate() )->execute( [ 'plugin' => $variant ] );

			self::assertInstanceOf( \WP_Error::class, $result, $variant );
			self::assertSame( 'stonewright_self_protection', $result->get_error_code(), $variant );
		}
	}

	public function test_delete_reports_success_only_when_delete_plugins_returns_true(): void {
		$GLOBALS['stonewright_test_delete_plugins_result'] = true;
		$done = ( new PluginDelete() )->execute( [ 'plugin' => self::UNRELATED ] );
		self::assertSame( [ 'deleted' => true, 'plugin' => self::UNRELATED ], $done );
	}

	/** @return array<string, array{0: mixed, 1: string}> */
	public static function failed_deletes(): array {
		return [
			'null: credentials are needed' => [ null, 'stonewright_plugin_delete_incomplete' ],
			'false: it failed'             => [ false, 'stonewright_plugin_delete_failed' ],
			'an error'                     => [ new \WP_Error( 'fs_unavailable', 'No filesystem.' ), 'fs_unavailable' ],
		];
	}

	/**
	 * @dataProvider failed_deletes
	 */
	public function test_delete_does_not_report_success_when_it_did_not_happen( mixed $returned, string $code ): void {
		$GLOBALS['stonewright_test_delete_plugins_result'] = $returned;

		$result = ( new PluginDelete() )->execute( [ 'plugin' => self::UNRELATED ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( $code, $result->get_error_code() );
	}
}
