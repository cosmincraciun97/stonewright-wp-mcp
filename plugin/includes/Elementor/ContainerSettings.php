<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Elementor;

use Stonewright\WpMcp\Elementor\Schema\SettingsKeyAliases;

/**
 * Normalizes Elementor container settings accepted by direct tree mutations.
 *
 * Layout semantics are delegated to {@see LayoutNormalizer}:
 * - `row` / legacy `horizontal` → flex row
 * - `stack` / legacy `vertical` → flex column
 * - `grid` → grid
 * - breakpoint overrides independent (tablet/mobile direction preserved)
 */
final class ContainerSettings {

	/**
	 * @param array<string, mixed> $settings
	 * @return array<string, mixed>
	 */
	public static function normalize( array $settings ): array {
		return LayoutNormalizer::normalize_settings( $settings );
	}

	/**
	 * @return array<string, string>
	 */
	public static function safe_aliases(): array {
		return SettingsKeyAliases::all();
	}

	/**
	 * @return list<string>
	 */
	public static function blocked_settings(): array {
		return [];
	}

	/**
	 * Validate parent/child container settings before mutation.
	 *
	 * @param array<string, mixed> $parent_settings
	 * @param array<string, mixed> $child_settings
	 * @return list<array<string, mixed>>
	 */
	public static function validate_nested( array $parent_settings, array $child_settings ): array {
		return LayoutNormalizer::validate_nested(
			self::normalize( $parent_settings ),
			self::normalize( $child_settings )
		);
	}
}
