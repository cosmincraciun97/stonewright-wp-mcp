<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Elementor\Provider;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\Elementor\ProviderDiscovery;
use Stonewright\WpMcp\Elementor\Provider\ProviderRouter;

/** @covers \Stonewright\WpMcp\Abilities\Elementor\ProviderDiscovery */
final class ElementorProviderDiscoveryTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['stonewright_test_user_caps'] = [ 'read' => true ];
		$GLOBALS['stonewright_test_user_logged_in'] = true;
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_user_caps'] = [];
		$GLOBALS['stonewright_test_user_logged_in'] = false;
	}

	public function test_registered_ability_exposes_read_only_provider_evidence(): void {
		$router = new ProviderRouter(
			static fn(): array => [ 'document_architecture' => 'v3', 'write_target' => 'v3', 'write_blocked' => false ],
			static fn(): array => [],
			static fn(): array => [ 'items' => [], 'issues' => [] ],
			static fn(): array => []
		);
		$ability = new ProviderDiscovery( $router );

		self::assertSame( 'stonewright/elementor-provider-discovery', $ability->name() );
		self::assertSame( 'elementor', $ability->category() );
		self::assertTrue( $ability->permission_callback( [] ) );
		$result = $ability->execute( [ 'post_id' => 0, 'architecture' => 'auto' ] );
		self::assertFalse( $result['writes_enabled'] );
		self::assertContains( 'frontend_verification', $result['safety_closure'] );
	}

	public function test_registry_and_matrix_include_live_provider_discovery(): void {
		$registry = (string) file_get_contents( dirname( __DIR__, 4 ) . '/includes/Core/AbilityRegistry.php' );
		$matrix   = (string) file_get_contents( dirname( __DIR__, 5 ) . '/docs/ability-truth-matrix.md' );

		self::assertStringContainsString( 'ProviderDiscovery::class', $registry );
		self::assertStringContainsString( 'stonewright/elementor-provider-discovery', $matrix );
		self::assertStringContainsString( '`elementor/manage-default-styles`: **native-preferred**, certified only', $matrix );
	}
}
