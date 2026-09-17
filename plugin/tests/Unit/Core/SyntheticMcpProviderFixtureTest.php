<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
final class SyntheticMcpProviderFixtureTest extends TestCase {

	public function test_fixture_emits_no_output_and_registers_plugins_loaded(): void {
		$file = dirname( __DIR__, 4 ) . '/e2e/fixtures/synthetic-mcp-provider/synthetic-mcp-provider.php';
		self::assertFileExists( $file );
		self::assertStringStartsWith( "<?php\n", (string) file_get_contents( $file ) );

		$before = count( $GLOBALS['stonewright_test_actions']['plugins_loaded'] ?? [] );
		ob_start();
		require $file;
		$output = (string) ob_get_clean();

		self::assertSame( '', $output );
		$registered = $GLOBALS['stonewright_test_actions']['plugins_loaded'] ?? [];
		self::assertGreaterThan( $before, count( $registered ) );
		self::assertSame( 1, (int) $registered[ array_key_last( $registered ) ]['priority'] );
	}
}
