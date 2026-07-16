<?php
/**
 * Feature-detection contract for the vendored Abilities API compat package.
 *
 * @package Stonewright\WpMcp
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Asserts the wordpress/abilities-api package is loaded only when the native
 * Abilities API is absent (class_exists guards), not by sniffing $wp_version.
 *
 * @coversNothing
 */
final class AbilitiesApiCompatLoadTest extends TestCase {

	private const BOOTSTRAP = __DIR__ . '/../../vendor/wordpress/abilities-api/includes/bootstrap.php';

	public function test_vendor_bootstrap_uses_class_exists_feature_detection(): void {
		$this->assertFileExists(
			self::BOOTSTRAP,
			'Vendor abilities-api not installed — run `composer install` in plugin/.'
		);

		$contents = (string) file_get_contents( self::BOOTSTRAP );

		$this->assertStringContainsString(
			"class_exists( 'WP_Ability' )",
			$contents,
			'Compat package must skip loading core classes when WP_Ability already exists (native API).'
		);
		$this->assertStringContainsString(
			"class_exists( 'WP_Abilities_Registry' )",
			$contents,
			'Compat package must skip registry load when WP_Abilities_Registry already exists.'
		);
		$this->assertStringContainsString(
			"function_exists( 'wp_register_ability' )",
			$contents,
			'Compat package must skip procedural helpers when wp_register_ability already exists.'
		);
	}

	public function test_vendor_bootstrap_does_not_version_sniff_wordpress(): void {
		$contents = (string) file_get_contents( self::BOOTSTRAP );

		$this->assertStringNotContainsString(
			'version_compare',
			$contents,
			'Compat package must not use version_compare for load decisions — feature detection only.'
		);
		$this->assertStringNotContainsString(
			'$wp_version',
			$contents,
			'Compat package must not read $wp_version to decide whether to load.'
		);
		$this->assertStringNotContainsString(
			'get_bloginfo',
			$contents,
			'Compat package must not call get_bloginfo for version sniffing.'
		);
	}

	public function test_vendor_bootstrap_exits_outside_wordpress_context(): void {
		$contents = (string) file_get_contents( self::BOOTSTRAP );
		$this->assertStringContainsString(
			"if ( ! defined( 'ABSPATH' ) )",
			$contents,
			'Bootstrap must no-op when ABSPATH is undefined (Composer install outside WP).'
		);
	}
}
