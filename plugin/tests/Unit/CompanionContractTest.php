<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Companion\CompanionContract;

/**
 * @covers \Stonewright\WpMcp\Companion\CompanionContract
 */
final class CompanionContractTest extends TestCase {

	public function test_health_response_valid(): void {
		$result = CompanionContract::validate(
			'health',
			'response',
			[
				'status'                     => 'ok',
				'contract_version'           => '1.0.0',
				'version'                    => '1.0.0-beta.12',
				'expected_companion_package' => 'https://github.com/cosmincraciun97/stonewright-wp-mcp/releases/download/v1.0.0-beta.12/stonewright-companion-1.0.0-beta.12.tgz',
			]
		);

		$this->assertTrue( $result );
	}

	public function test_unknown_endpoint_returns_contract_error(): void {
		$result = CompanionContract::validate( 'unknown', 'response', [] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'stonewright_companion_contract_violation', $result->get_error_code() );
	}
}
