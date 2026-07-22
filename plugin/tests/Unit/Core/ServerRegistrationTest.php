<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Core\ServerRegistration;

/**
 * @covers \Stonewright\WpMcp\Core\ServerRegistration
 * @covers \Stonewright\WpMcp\Core\AgentInstructions
 */
final class ServerRegistrationTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_options'] = [
			'stonewright_enabled'                      => true,
			'stonewright_custom_instructions_enabled'  => true,
			'stonewright_custom_instructions'          => '',
			'stonewright_disabled_abilities'           => [],
			'stonewright_essential_tools_mode'         => true,
			'stonewright_essential_extra_abilities'    => [],
		];
	}

	public function test_register_server_uses_compact_startup_description_without_duplicate_custom_instructions(): void {
		$GLOBALS['stonewright_test_options']['stonewright_custom_instructions'] = 'Site rule unique.';

		$adapter = new CapturingMcpAdapter();

		ServerRegistration::register_server( $adapter );

		$description = $this->created_server_argument( $adapter, 4 );

		self::assertIsString( $description );
		self::assertStringContainsString( 'Stonewright fast start:', $description );
		self::assertStringContainsString( 'stonewright-context-bootstrap', $description );
		self::assertStringContainsString( 'stonewright-workflow-preflight', $description );
		self::assertStringContainsString( 'Site-specific instructions', $description );
		self::assertSame( 1, substr_count( $description, 'Site rule unique.' ) );
		self::assertStringNotContainsString( 'visual_build_gate', $description );
		// Compact bootstrap summary (task-start + Elementor integrity rules); keep under 3k.
		self::assertLessThan( 3000, strlen( $description ) );
	}

	public function test_register_server_exposes_only_current_public_tools(): void {
		$GLOBALS['stonewright_test_options']['stonewright_disabled_abilities'] = [
			'stonewright/wp-cli-run',
		];

		$adapter = new CapturingMcpAdapter();

		ServerRegistration::register_server( $adapter );

		$tools = $this->created_server_argument( $adapter, 9 );

		self::assertIsArray( $tools );
		self::assertContains( 'stonewright/context-bootstrap', $tools );
		self::assertContains( 'stonewright/elementor-v3-build-page-from-spec', $tools );
		self::assertContains( 'stonewright/wp-cli-batch-run', $tools );
		self::assertContains( 'stonewright/php-execute', $tools );
		self::assertContains( 'stonewright/security-issue-confirmation-token', $tools );
		self::assertNotContains( 'stonewright/wp-cli-run', $tools );
		self::assertNotContains( 'stonewright/elementor-v3-save-template', $tools );
		self::assertNotContains( 'stonewright/sandbox-write', $tools );
		self::assertCount( 30, $tools );
	}

	/**
	 * @return mixed
	 */
	private function created_server_argument( CapturingMcpAdapter $adapter, int $index ) {
		self::assertCount( 1, $adapter->calls );
		self::assertArrayHasKey( $index, $adapter->calls[0] );

		return $adapter->calls[0][ $index ];
	}
}

final class CapturingMcpAdapter {

	/**
	 * @var list<list<mixed>>
	 */
	public array $calls = [];

	public function create_server( mixed ...$args ): void {
		$this->calls[] = $args;
	}
}
