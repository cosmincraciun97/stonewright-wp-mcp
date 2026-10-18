<?php
/**
 * stonewright/security-issue-confirmation-token: no token for an ability the current surface does not list.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Abilities\Security;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\Security\IssueConfirmationToken;
use Stonewright\WpMcp\Core\FullProfileOnlyAbilities;
use Stonewright\WpMcp\Security\ConfirmationToken;

/**
 * @covers \Stonewright\WpMcp\Abilities\Security\IssueConfirmationToken
 */
final class IssueConfirmationTokenSurfaceTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_options']         = [
			'stonewright_enabled'              => true,
			'stonewright_disabled_abilities'   => [],
			'stonewright_mode'                 => 'production-safe',
			'stonewright_essential_tools_mode' => true,
			'stonewright_mcp_surface'          => 'essential',
		];
		$GLOBALS['stonewright_test_user_caps']       = [
			'read'           => true,
			'manage_options' => true,
		];
		$GLOBALS['stonewright_test_user_logged_in']  = true;
		$GLOBALS['stonewright_test_current_user_id'] = 1;
		$GLOBALS['stonewright_test_transients']      = [];
		unset( $_SERVER['HTTP_MCP_SESSION_ID'] );
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_options']         = [];
		$GLOBALS['stonewright_test_user_caps']       = [];
		$GLOBALS['stonewright_test_user_logged_in']  = false;
		$GLOBALS['stonewright_test_current_user_id'] = 0;
		$GLOBALS['stonewright_test_transients']      = [];
	}

	/**
	 * Every Full-only ability except wp-cli-batch-run, which the essential surface lists.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function full_only_abilities(): array {
		$rows = [];
		foreach ( FullProfileOnlyAbilities::names() as $name ) {
			if ( 'stonewright/wp-cli-batch-run' !== $name ) {
				$rows[ $name ] = [ $name ];
			}
		}
		return $rows;
	}

	/**
	 * @dataProvider full_only_abilities
	 */
	public function test_it_refuses_a_token_for_a_full_only_ability_off_the_surface( string $name ): void {
		$result = ( new IssueConfirmationToken() )->execute( [ 'ability' => $name, 'args' => [ 'code' => 'return 1;' ] ] );

		$this->assert_refused( $result );
	}

	/**
	 * @dataProvider full_only_abilities
	 */
	public function test_it_refuses_a_rest_run_token_that_targets_a_full_only_ability( string $name ): void {
		$result = ( new IssueConfirmationToken() )->execute(
			[
				'ability' => 'stonewright/rest-abilities-run',
				'args'    => [ 'name' => $name, 'input' => [ 'code' => 'return 1;' ] ],
			]
		);

		$this->assert_refused( $result );
	}

	public function test_it_refuses_a_hyphenated_tool_name_and_mixed_case(): void {
		foreach ( [ 'stonewright-php-execute', ' Stonewright/PHP-Execute ' ] as $ability ) {
			$this->assert_refused( ( new IssueConfirmationToken() )->execute( [ 'ability' => $ability ] ) );
		}
		$this->assert_refused(
			( new IssueConfirmationToken() )->execute(
				[ 'ability' => 'stonewright/rest-abilities-run', 'args' => [ 'name' => 'stonewright-php-execute' ] ]
			)
		);
	}

	public function test_it_issues_a_token_for_a_full_only_ability_the_surface_lists(): void {
		$GLOBALS['stonewright_test_options']['stonewright_mcp_surface'] = 'full';

		$result = ( new IssueConfirmationToken() )->execute( [ 'ability' => 'stonewright/php-execute', 'args' => [ 'code' => 'return 1;' ] ] );

		self::assertIsArray( $result );
		self::assertNotSame( '', $result['token'] );
		self::assertTrue( ConfirmationToken::verify_or_error( $result['token'], 'stonewright/php-execute', [ 'code' => 'return 1;' ] ) );
	}

	public function test_it_issues_a_rest_run_token_for_a_full_only_ability_the_surface_lists(): void {
		$GLOBALS['stonewright_test_options']['stonewright_mcp_surface'] = 'full';

		$result = ( new IssueConfirmationToken() )->execute(
			[ 'ability' => 'stonewright/rest-abilities-run', 'args' => [ 'name' => 'stonewright/php-execute', 'input' => [] ] ]
		);

		self::assertIsArray( $result );
		self::assertNotSame( '', $result['token'] );
	}

	public function test_it_issues_tokens_for_ordinary_abilities_as_before(): void {
		foreach ( [
			[ 'ability' => 'stonewright/wp-cli-batch-run', 'args' => [] ],
			[ 'ability' => 'stonewright/theme-file-patch', 'args' => [ 'path' => 'style.css' ] ],
			[ 'ability' => 'stonewright/rest-abilities-run', 'args' => [ 'name' => 'stonewright/system-instructions-get', 'input' => [] ] ],
			[ 'ability' => 'stonewright/rest-abilities-run', 'args' => [] ],
			[ 'ability' => 'stonewright/not-an-ability', 'args' => [] ],
		] as $args ) {
			$result = ( new IssueConfirmationToken() )->execute( $args );
			self::assertIsArray( $result, $args['ability'] );
			self::assertNotSame( '', $result['token'] );
		}
	}

	private function assert_refused( mixed $result ): void {
		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_ability_not_on_surface', $result->get_error_code() );
		$data = $result->get_error_data();
		self::assertSame( 403, $data['status'] );
		self::assertTrue( $data['agent_must_stop'] );
		self::assertNotSame( '', (string) $data['approval_url'] );
		self::assertStringContainsString( 'administrator', $result->get_error_message() );
	}
}
