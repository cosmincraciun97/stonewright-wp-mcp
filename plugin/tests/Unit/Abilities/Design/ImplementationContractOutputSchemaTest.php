<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Abilities\Design;

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\Design\ImplementationContract;

/**
 * Each action of design-implementation-contract answers with the shape the ability declares.
 *
 * @covers \Stonewright\WpMcp\Abilities\Design\ImplementationContract
 */
final class ImplementationContractOutputSchemaTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_options']        = [ 'stonewright_mode' => 'development' ];
		$GLOBALS['stonewright_test_user_caps']      = [ 'read' => true, 'manage_options' => true ];
		$GLOBALS['stonewright_test_user_logged_in'] = true;
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_options']        = [];
		$GLOBALS['stonewright_test_user_caps']      = [];
		$GLOBALS['stonewright_test_user_logged_in'] = false;
	}

	/**
	 * @param mixed $result
	 * @return list<string>
	 */
	private static function problems( mixed $result ): array {
		$ability = new ImplementationContract();
		$outcome = ( new Validator() )->validate(
			json_decode( (string) json_encode( $result ), false, 512, JSON_THROW_ON_ERROR ),
			json_decode( (string) json_encode( $ability->output_schema() ), false, 512, JSON_THROW_ON_ERROR )
		);

		return $outcome->isValid() ? [] : ( new ErrorFormatter() )->formatFlat( $outcome->error() );
	}

	public function test_the_contract_action_satisfies_the_output_schema(): void {
		$result = ( new ImplementationContract() )->execute( [ 'action' => 'contract' ] );

		self::assertIsArray( $result );
		self::assertSame( [], self::problems( $result ) );
	}

	public function test_the_validate_action_satisfies_the_output_schema(): void {
		$result = ( new ImplementationContract() )->execute( [ 'action' => 'validate', 'spec' => [] ] );

		self::assertIsArray( $result );
		self::assertTrue( $result['ok'] );
		self::assertArrayNotHasKey( 'sequence', $result, 'A validation verdict carries no workflow sequence.' );
		self::assertSame( [], self::problems( $result ) );
	}

	public function test_only_the_fields_both_actions_return_are_required(): void {
		$schema = ( new ImplementationContract() )->output_schema();

		self::assertSame( [ 'version' ], $schema['required'] );
		self::assertArrayNotHasKey( 'oneOf', $schema );
		self::assertStringContainsString( 'action=contract', $schema['properties']['sequence']['description'] );
		self::assertStringContainsString( 'action=validate', $schema['properties']['ok']['description'] );
	}
}
