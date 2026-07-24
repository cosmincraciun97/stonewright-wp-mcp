<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\OAuth;

use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\OAuth\Bridge;

final class BridgeTest extends TestCase {

	public function test_converts_psr7_json_response_to_wordpress_response(): void {
		$response = Bridge::from_psr7(
			new Response(
				201,
				[ 'X-OAuth-Test' => 'ok' ],
				'{"access_token":"secret"}'
			)
		);

		self::assertSame( 201, $response->get_status() );
		self::assertSame( [ 'access_token' => 'secret' ], $response->get_data() );
	}
}
