<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Abilities\System;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\System\ToolProfile;

/**
 * @covers \Stonewright\WpMcp\Abilities\System\ToolProfile
 */
final class ToolProfileOrderingTest extends TestCase {

	public function test_elementor_design_write_tools_survive_default_cap(): void {
		$first_fifty = array_slice( ToolProfile::profile_tools( 'elementor-design' ), 0, 50 );

		$write_critical = [
			'stonewright/security-issue-confirmation-token',
			'stonewright/design-validate-spec',
			'stonewright/elementor-v3-build-page-from-spec',
			'stonewright/elementor-v3-batch-mutate',
			'stonewright/elementor-post-write-verify',
			'stonewright/elementor-wire-loop',
			'stonewright/elementor-v3-apply-bundle',
			'stonewright/elementor-v4-read-atomic-tree',
			'stonewright/elementor-v4-update-node',
			'stonewright/theme-file-read',
			'stonewright/theme-file-patch',
			'stonewright/theme-custom-css',
			'stonewright/elementor-v3-update-page-settings',
			'stonewright/elementor-v3-update-kit-colors',
			'stonewright/elementor-v3-update-kit-typography',
			'stonewright/elementor-page-digest',
			'stonewright/gutenberg-apply-to-post',
		];
		foreach ( $write_critical as $name ) {
			self::assertContains( $name, $first_fifty, "Write-critical {$name} must survive max_tools=50." );
		}
	}

	public function test_elementor_design_set_is_unchanged_by_reorder(): void {
		$names = ToolProfile::profile_tools( 'elementor-design' );

		self::assertCount( 78, $names );
		self::assertSame( $names, array_values( array_unique( $names ) ) );
		self::assertContains( 'stonewright/design-direction-brief', $names );
		self::assertContains( 'stonewright/form-delivery-diagnostic', $names );
		self::assertContains( 'stonewright/capability-preflight', $names );
		self::assertContains( 'stonewright/elementor-v3-legacy-debt-migrate', $names );
	}

	public function test_gutenberg_and_site_admin_profiles_expose_new_safe_workflows(): void {
		$gutenberg = ToolProfile::profile_tools( 'gutenberg' );
		self::assertContains( 'stonewright/blocks-batch-mutate', $gutenberg );
		self::assertContains( 'stonewright/design-section-manifest', $gutenberg );
		self::assertContains( 'stonewright/design-visual-compare', $gutenberg );

		$admin = ToolProfile::profile_tools( 'site-admin' );
		self::assertContains( 'stonewright/security-audit-reconcile', $admin );
		self::assertContains( 'stonewright/security-runtime-data-purge', $admin );
		self::assertContains( 'stonewright/oauth-header-diagnostic', $admin );
		self::assertContains( 'stonewright/capability-preflight', $admin );
		self::assertContains( 'stonewright/form-delivery-diagnostic', $admin );
	}
}
