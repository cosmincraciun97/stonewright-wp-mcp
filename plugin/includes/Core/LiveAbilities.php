<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Core;

/**
 * Counts abilities from the registry WordPress holds, not from the classes the plugin ships.
 *
 * WordPress refuses an ability whose name or category it does not accept, and the plugin does
 * not register abilities that are disabled or that the current surface leaves out. The admin
 * screens use this class so every tool count describes the tools an MCP client can call.
 */
final class LiveAbilities {

	/**
	 * Names of the abilities registered with the Abilities API, or null when that API is not
	 * loaded and the registry cannot be consulted.
	 *
	 * @return array<string, true>|null
	 */
	public static function registered_names(): ?array {
		if ( ! function_exists( 'wp_get_abilities' ) ) {
			return null;
		}

		$names = [];
		foreach ( (array) wp_get_abilities() as $ability ) {
			if ( is_object( $ability ) && method_exists( $ability, 'get_name' ) ) {
				$names[ (string) $ability->get_name() ] = true;
			}
		}

		return $names;
	}

	/**
	 * Whether the Abilities API holds the ability. True when the API cannot be consulted.
	 *
	 * @param array<string, true>|null $registered Result of registered_names(); read again when omitted.
	 */
	public static function is_registered( string $name, ?array $registered = null ): bool {
		$registered ??= self::registered_names();

		return null === $registered || isset( $registered[ $name ] );
	}

	/**
	 * Number of the named abilities that are registered.
	 *
	 * @param list<string> $names Ability names.
	 */
	public static function count_registered( array $names ): int {
		$registered = self::registered_names();

		return count( array_filter( $names, static fn( string $name ): bool => self::is_registered( $name, $registered ) ) );
	}

	/**
	 * Number of tools an MCP client can call: on the current surface, not disabled, registered.
	 */
	public static function exposed_count(): int {
		return self::count_registered( AbilityRegistry::mcp_server_ability_names() );
	}

	/**
	 * Whether a catalog row counts as enabled: the operator has not disabled it and it is live.
	 *
	 * @param array<string, mixed> $row                Catalog row (`name`, optional `registered`).
	 * @param array<int, string>   $disabled_abilities Names the operator disabled.
	 */
	public static function counts_as_enabled( array $row, array $disabled_abilities ): bool {
		return ! in_array( (string) ( $row['name'] ?? '' ), $disabled_abilities, true )
			&& false !== ( $row['registered'] ?? true );
	}
}
