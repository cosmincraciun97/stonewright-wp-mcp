<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\ElementorV3;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\ElementorV3\DocumentHealth;
use Stonewright\WpMcp\Abilities\System\ToolProfile;
use Stonewright\WpMcp\Core\AbilityRegistry;

/**
 * @covers \Stonewright\WpMcp\Abilities\ElementorV3\DocumentHealth
 */
final class DocumentHealthTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_user_caps'] = [ 'edit_post' => true ];
		$GLOBALS['stonewright_test_posts']     = [];
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_user_caps'] = [];
		$GLOBALS['stonewright_test_posts']     = [];
	}

	public function test_document_health_is_registered(): void {
		self::assertContains( DocumentHealth::class, AbilityRegistry::list() );
		self::assertContains( 'stonewright/elementor-document-health', ToolProfile::profile_tools( 'elementor-design' ) );
	}

	public function test_mixed_document_report_is_bounded_and_content_free(): void {
		$children = [];
		for ( $index = 0; $index < 48; ++$index ) {
			$children[] = [
				'id'         => 'atomic-' . (string) $index,
				'elType'     => 'widget',
				'widgetType' => 'e-paragraph',
				'settings'   => [ 'content' => 'Private paragraph ' . (string) $index ],
				'elements'   => [],
			];
		}
		for ( $index = 0; $index < 3; ++$index ) {
			$children[] = [
				'id'         => 'invalid-' . (string) $index,
				'elType'     => 'widget',
				'widgetType' => 'third-party-card',
				'settings'   => [ 'flex_gap' => [ 'unit' => '%' ] ],
				'elements'   => [],
			];
		}
		$tree = [
			[
				'id'       => 'legacy-root',
				'elType'   => 'container',
				'settings' => [ 'container_type' => 'flex' ],
				'elements' => $children,
			],
		];
		$raw = (string) wp_json_encode( $tree, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		$GLOBALS['stonewright_test_posts'][9049] = (object) [
			'ID'   => 9049,
			'meta' => [
				'_elementor_data'      => $raw,
				'_elementor_edit_mode' => 'builder',
			],
		];

		$result = ( new DocumentHealth() )->execute( [ 'post_id' => 9049, 'max_issues' => 2 ] );

		self::assertIsArray( $result );
		self::assertSame( 'mixed', $result['architecture'] );
		self::assertSame( strlen( $raw ), $result['serialized_bytes'] );
		self::assertSame( 52, $result['counts']['total'] );
		self::assertSame( 48, $result['counts']['v4'] );
		self::assertSame( 4, $result['counts']['v3'] );
		self::assertSame( 48, $result['e_paragraph_count'] );
		self::assertCount( 2, $result['issues'] );
		self::assertTrue( $result['issues_truncated'] );
		self::assertSame( 'settings.flex_gap', $result['issues'][0]['path'] );
		self::assertContains( 'mixed_architecture', $result['warnings'] );
		self::assertContains( 'excessive_e_paragraph_nodes', $result['warnings'] );
		self::assertContains( 'invalid_settings', $result['warnings'] );
		self::assertArrayNotHasKey( 'tree', $result );
		self::assertStringNotContainsString( 'Private paragraph', (string) wp_json_encode( $result ) );
	}

	public function test_missing_post_returns_not_found(): void {
		$result = ( new DocumentHealth() )->execute( [ 'post_id' => 999 ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_not_found', $result->get_error_code() );
	}
}
