<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Admin\AdminShell;
use Stonewright\WpMcp\Support\PromptCatalog;

/**
 * Design Library admin surface and catalog prompts are hidden; MCP abilities stay.
 */
final class DesignLibraryHiddenTest extends TestCase {

	public function test_design_library_group_and_pages_absent(): void {
		$groups = AdminShell::menu_groups();
		$ids    = array_column( $groups, 'id' );
		self::assertNotContains( 'design-library', $ids );

		$pages = AdminShell::pages();
		self::assertArrayNotHasKey( 'stonewright-design-studio', $pages );
		self::assertArrayNotHasKey( 'stonewright-visual-workspace', $pages );
		self::assertArrayNotHasKey( 'stonewright-blueprints', $pages );
		// Prompt library remains under workflows.
		self::assertArrayHasKey( 'stonewright-prompts', $pages );
	}

	public function test_design_library_catalog_prompts_removed_figma_kept(): void {
		$ids = array_column( PromptCatalog::all(), 'id' );
		self::assertNotContains( 'brand-kit-apply', $ids );
		self::assertNotContains( 'blueprint-industry-site', $ids );
		self::assertNotContains( 'blueprint-engine-elementor', $ids );
		self::assertNotContains( 'blueprint-engine-gutenberg', $ids );
		self::assertNotContains( 'blueprint-engine-fse', $ids );
		self::assertNotContains( 'brand-kit-preview-restore', $ids );
		self::assertContains( 'figma-to-native-pixel', $ids );
		// Unrelated inspect prompt remains stable.
		self::assertContains( 'inspect-site-snapshot', $ids );
	}

	public function test_admin_bootstrap_does_not_register_design_studio_pages(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 3 ) . '/includes/Admin/AdminBootstrap.php' );
		self::assertStringNotContainsString( 'DesignStudioPage::register()', $source );
		self::assertStringNotContainsString( 'VisualWorkspacePage::register()', $source );
		self::assertStringNotContainsString( 'BlueprintsPage::register()', $source );
		self::assertStringContainsString( 'PromptLibraryPage::register()', $source );
	}
}
