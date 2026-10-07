<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Abilities\System;

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\System\ToolProfile;

/**
 * Both actions of tool-profile answer with the shape the ability declares as its output.
 *
 * @covers \Stonewright\WpMcp\Abilities\System\ToolProfile
 */
final class ToolProfileOutputSchemaTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_options']        = [
			'stonewright_disabled_abilities'        => [],
			'stonewright_essential_tools_mode'      => true,
			'stonewright_essential_extra_abilities' => [],
			'stonewright_last_tool_profile'         => '',
			'stonewright_mcp_surface'               => 'essential',
			'stonewright_mode'                      => 'development',
		];
		$GLOBALS['stonewright_test_user_caps']      = [ 'read' => true, 'manage_options' => true ];
		$GLOBALS['stonewright_test_user_logged_in'] = true;
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_options']        = [];
		$GLOBALS['stonewright_test_user_caps']      = [];
		$GLOBALS['stonewright_test_user_logged_in'] = false;
	}

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function actions_and_profiles(): array {
		$cases = [];
		foreach ( [ 'resolve', 'activate' ] as $action ) {
			foreach ( ToolProfile::profile_names() as $profile ) {
				$cases[ $action . ' ' . $profile ] = [ $action, $profile ];
			}
		}

		return $cases;
	}

	/**
	 * @dataProvider actions_and_profiles
	 */
	public function test_the_result_satisfies_the_declared_output_schema( string $action, string $profile ): void {
		$ability = new ToolProfile();
		$result  = $ability->execute( [ 'action' => $action, 'profile' => $profile ] );

		self::assertIsArray( $result );
		self::assertTrue( $result['ok'] ?? false );

		$validator = new Validator();
		$outcome   = $validator->validate(
			json_decode( (string) json_encode( $result ), false, 512, JSON_THROW_ON_ERROR ),
			json_decode( (string) json_encode( $ability->output_schema() ), false, 512, JSON_THROW_ON_ERROR )
		);

		$problems = $outcome->isValid() ? [] : ( new ErrorFormatter() )->formatFlat( $outcome->error() );
		self::assertSame( [], $problems, $action . ' ' . $profile . ' does not match the output schema.' );
	}

	public function test_resolve_lists_the_profiles_available(): void {
		$result = ( new ToolProfile() )->execute( [ 'action' => 'resolve', 'profile' => 'gutenberg' ] );

		self::assertIsArray( $result );
		self::assertSame( ToolProfile::profile_names(), $result['profiles_available'] );
	}
}
