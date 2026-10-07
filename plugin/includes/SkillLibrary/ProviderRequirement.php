<?php
/**
 * Provider requirements a skill can declare in its front matter.
 *
 * @package Stonewright
 * @license GPL-2.0-or-later
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\SkillLibrary;

use Stonewright\WpMcp\Elementor\Provider\ProviderRouter;

/**
 * `requires_provider: <id>` compiles to the visibility constraint `provider:<id>` = `required`.
 *
 * The constraint uses the same runtime check as plugin components: while the provider is absent the
 * skill is hidden from agents and prompts, and it is shown again as soon as the provider is present.
 * A requirement never blocks saving, enabling or exporting a skill.
 */
final class ProviderRequirement {

	/** Constraint component prefix; a colon cannot occur in a plugin slug, so it never collides with one. */
	public const PREFIX = 'provider:';

	/**
	 * Accepted provider ids.
	 *
	 * - `elementor-native`: Elementor's own MCP abilities are registered on this site.
	 *
	 * @var list<string>
	 */
	public const IDS = [ 'elementor-native' ];

	/** @var (callable(string): bool)|null */
	private static $resolver = null;

	/** Replaces the runtime provider check; null restores it. */
	public static function use_resolver( ?callable $resolver ): void {
		self::$resolver = $resolver;
	}

	public static function is_known( string $id ): bool {
		return in_array( $id, self::IDS, true );
	}

	public static function component( string $id ): string {
		return self::PREFIX . $id;
	}

	public static function is_component( string $component ): bool {
		return str_starts_with( $component, self::PREFIX );
	}

	public static function id_of( string $component ): ?string {
		return self::is_component( $component ) ? substr( $component, strlen( self::PREFIX ) ) : null;
	}

	/** A provider constraint is well formed when it names a known provider and its expression is `required`. */
	public static function valid_constraint( string $component, mixed $expression ): bool {
		$id = self::id_of( $component );
		return null !== $id && self::is_known( $id ) && is_string( $expression ) && 'required' === strtolower( trim( $expression ) );
	}

	/** True when the constraint is well formed and the provider is present on this runtime. */
	public static function satisfied( string $component, mixed $expression ): bool {
		return self::valid_constraint( $component, $expression ) && self::present( (string) self::id_of( $component ) );
	}

	public static function present( string $id ): bool {
		if ( ! self::is_known( $id ) ) {
			return false;
		}
		if ( null !== self::$resolver ) {
			return (bool) ( self::$resolver )( $id );
		}
		return self::provided_by( $id, ( new ProviderRouter() )->native_elementor() );
	}

	/** @param array<string,mixed> $native_elementor Report from ProviderRouter::native_elementor(). */
	public static function provided_by( string $id, array $native_elementor ): bool {
		return 'elementor-native' === $id && in_array( $native_elementor['state'] ?? '', [ 'available', 'available_uncertified' ], true );
	}
}
