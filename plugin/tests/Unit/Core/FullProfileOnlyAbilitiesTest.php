<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\System\ToolProfile;
use Stonewright\WpMcp\Core\AbilityRegistry;
use Stonewright\WpMcp\Core\FullProfileOnlyAbilities;

/**
 * @covers \Stonewright\WpMcp\Core\FullProfileOnlyAbilities
 * @covers \Stonewright\WpMcp\Abilities\System\ToolProfile::apply_extras
 */
final class FullProfileOnlyAbilitiesTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_transients'] = [];
		$GLOBALS['stonewright_test_options']    = [
			'stonewright_enabled'                   => true,
			'stonewright_disabled_abilities'        => [],
			'stonewright_essential_tools_mode'      => true,
			'stonewright_essential_extra_abilities' => [],
			'stonewright_mcp_surface'               => 'essential',
		];
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_transients'] = [];
		$GLOBALS['stonewright_test_options']    = [];
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function restricted_abilities(): array {
		$rows = [];
		foreach ( [
			'stonewright/php-execute',
			'stonewright/sandbox-write',
			'stonewright/sandbox-edit',
			'stonewright/sandbox-delete',
			'stonewright/sandbox-activate',
			'stonewright/sandbox-deactivate',
			'stonewright/sandbox-toggle',
			'stonewright/wp-cli-run',
			'stonewright/wp-cli-batch-run',
			'stonewright/wp-cli-job-start',
			'stonewright/custom-code-provider',
			'stonewright/elementor-widget-define',
			'stonewright/elementor-widget-register',
			'stonewright/elementor-create-custom-widget',
			'stonewright/elementor-v4-atomic-widget-define',
		] as $name ) {
			$rows[ $name ] = [ $name ];
		}
		return $rows;
	}

	/**
	 * @dataProvider restricted_abilities
	 */
	public function test_policy_lists_every_code_running_ability( string $name ): void {
		self::assertTrue( FullProfileOnlyAbilities::is_restricted( $name ) );
		self::assertContains( $name, FullProfileOnlyAbilities::names() );
	}

	public function test_policy_names_only_registered_abilities(): void {
		$registered = [];
		foreach ( AbilityRegistry::list() as $class ) {
			$registered[ ( new $class() )->name() ] = true;
		}
		foreach ( FullProfileOnlyAbilities::names() as $name ) {
			self::assertArrayHasKey( $name, $registered, $name . ' is in the policy list but not registered.' );
		}
	}

	public function test_read_only_sandbox_and_wp_cli_abilities_are_not_restricted(): void {
		foreach ( [ 'stonewright/sandbox-list', 'stonewright/sandbox-read', 'stonewright/wp-cli-status', 'stonewright/wp-cli-discover', 'stonewright/wp-cli-job-status', 'stonewright/ping' ] as $name ) {
			self::assertFalse( FullProfileOnlyAbilities::is_restricted( $name ), $name );
		}
	}

	/**
	 * @dataProvider restricted_abilities
	 */
	public function test_extras_refuse_restricted_abilities( string $name ): void {
		$result = ToolProfile::apply_extras( [ $name ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_tool_profile_extras_restricted', $result->get_error_code() );
		self::assertSame( [], (array) get_option( 'stonewright_essential_extra_abilities', [] ) );
	}

	public function test_extras_refuse_a_whole_list_when_one_entry_is_restricted(): void {
		$result = ToolProfile::apply_extras( [ 'stonewright/media-get', 'stonewright/php-execute' ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( [], (array) get_option( 'stonewright_essential_extra_abilities', [] ) );
	}

	public function test_extras_still_accept_an_ordinary_ability(): void {
		$result = ToolProfile::apply_extras( [ 'stonewright/media-get' ] );

		self::assertIsArray( $result );
		self::assertTrue( $result['changed'] );
		self::assertSame( [ 'stonewright/media-get' ], $result['extras'] );
	}

	public function test_activate_with_php_execute_extra_leaves_it_off_the_essential_surface(): void {
		$result = ( new ToolProfile() )->execute(
			[
				'action'  => 'activate',
				'profile' => 'essential',
				'extras'  => [ 'stonewright/php-execute' ],
			]
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertNotContains( 'stonewright/php-execute', array_column( AbilityRegistry::enabled_abilities(), 'name' ) );
	}

	public function test_a_restricted_extra_stored_earlier_does_not_reach_the_public_surface(): void {
		$GLOBALS['stonewright_test_options']['stonewright_essential_extra_abilities'] = [ 'stonewright/php-execute', 'stonewright/sandbox-write', 'stonewright/media-get' ];

		$names = array_column( AbilityRegistry::enabled_abilities(), 'name' );

		self::assertNotContains( 'stonewright/php-execute', $names );
		self::assertNotContains( 'stonewright/sandbox-write', $names );
		self::assertContains( 'stonewright/media-get', $names );
	}

	public function test_no_profile_other_than_full_lists_php_execute_or_the_sandbox_writers(): void {
		foreach ( ToolProfile::profile_names() as $profile ) {
			if ( in_array( $profile, [ 'full', 'auto' ], true ) ) {
				continue;
			}
			$names = ToolProfile::profile_tools( $profile );
			foreach ( [ 'stonewright/php-execute', 'stonewright/sandbox-write', 'stonewright/sandbox-edit', 'stonewright/sandbox-delete', 'stonewright/custom-code-provider' ] as $name ) {
				self::assertNotContains( $name, $names, $name . ' on ' . $profile );
			}
		}
	}
}
