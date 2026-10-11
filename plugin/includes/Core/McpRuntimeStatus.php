<?php
/**
 * What the loaded MCP adapter means for Stonewright's route, in words for an administrator or an agent.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Core;

/**
 * Turns the compatibility preflight into one of three states.
 *
 * - `ok`: Stonewright's own copy of the MCP adapter serves the route.
 * - `foreign_adapter`: another plugin loaded its own copy first and Stonewright serves its route through it.
 * - `blocked`: no usable adapter is loaded, so the route is not served. The owner and the version of the
 *   copy that was loaded are named when they can be found, with what to do about it.
 *
 * Nothing here reads a path: owners are plugin folder names or the names the plugin header gives.
 */
final class McpRuntimeStatus {

	public const OK      = 'ok';
	public const FOREIGN = 'foreign_adapter';
	public const BLOCKED = 'blocked';

	/** The oldest adapter release that is accepted when another plugin ships it; the remedy says so. */
	private const MIN_FOREIGN_VERSION = '0.5.0';

	/**
	 * The state of the adapter of this request, from the preflight that ran when the plugins were loaded.
	 * Before that preflight ran nothing is known, and nothing is reported.
	 *
	 * @return array{state:string,adapter_owner:string,adapter_plugin:string,adapter_version:string,blocking_reasons:list<string>,abi_issues:list<string>,summary:string,remedy:string}
	 */
	public static function current(): array {
		return self::from_report( McpAbilitiesCompatibilityPreflight::current() ?? [ 'compatible' => true ] );
	}

	/**
	 * @param array<string,mixed> $report A report of McpAbilitiesCompatibilityPreflight::inspect().
	 * @return array{state:string,adapter_owner:string,adapter_plugin:string,adapter_version:string,blocking_reasons:list<string>,abi_issues:list<string>,summary:string,remedy:string}
	 */
	public static function from_report( array $report ): array {
		$compatible = true === ( $report['compatible'] ?? false );
		$adapter    = is_array( $report['adapter'] ?? null ) ? $report['adapter'] : [];
		$reasons    = array_values( array_filter( array_map( 'sanitize_key', array_map( 'strval', (array) ( $report['blocking_reasons'] ?? [] ) ) ) ) );
		$issues     = array_values( array_filter( array_map( 'sanitize_key', array_map( 'strval', (array) ( $adapter['abi']['issues'] ?? [] ) ) ) ) );

		if ( $compatible ) {
			return self::compatible_status( $adapter );
		}

		return self::blocked_status( $report, $adapter, $reasons, $issues );
	}

	/**
	 * The short form an agent is told while the route is blocked. Empty while a route is served.
	 *
	 * @param array<string,mixed> $status
	 * @return array<string,string>
	 */
	public static function agent_notice( array $status ): array {
		if ( self::BLOCKED !== ( $status['state'] ?? '' ) ) {
			return [];
		}

		return self::agent_report( $status );
	}

	/**
	 * The short form of any state.
	 *
	 * @param array<string,mixed> $status
	 * @return array<string,string>
	 */
	public static function agent_report( array $status ): array {
		$state = (string) ( $status['state'] ?? self::OK );
		$out   = [ 'state' => $state ];
		if ( self::OK === $state ) {
			return $out;
		}
		foreach ( [ 'adapter_plugin', 'adapter_version', 'summary', 'remedy' ] as $key ) {
			if ( '' !== (string) ( $status[ $key ] ?? '' ) ) {
				$out[ $key ] = (string) $status[ $key ];
			}
		}
		if ( self::FOREIGN === $state ) {
			unset( $out['remedy'] );
		}

		return $out;
	}

	/**
	 * The schema of the field the agent abilities carry.
	 *
	 * @return array<string,mixed>
	 */
	public static function output_schema(): array {
		return [
			'type'        => 'object',
			'description' => 'Which MCP adapter serves Stonewright\'s route. While state is blocked, MCP clients cannot connect: adapter_plugin and adapter_version name the copy that was loaded and remedy says what the site owner can do.',
			'properties'  => [
				'state'           => [ 'type' => 'string', 'enum' => [ self::OK, self::FOREIGN, self::BLOCKED ] ],
				'adapter_plugin'  => [ 'type' => 'string' ],
				'adapter_version' => [ 'type' => 'string' ],
				'summary'         => [ 'type' => 'string' ],
				'remedy'          => [ 'type' => 'string' ],
			],
			'required'    => [ 'state' ],
		];
	}

	/**
	 * @param array<string,mixed> $adapter
	 * @return array{state:string,adapter_owner:string,adapter_plugin:string,adapter_version:string,blocking_reasons:list<string>,abi_issues:list<string>,summary:string,remedy:string}
	 */
	private static function compatible_status( array $adapter ): array {
		$owner   = self::text( $adapter['selected_owner'] ?? '' );
		$version = self::text( $adapter['selected_version'] ?? ( $adapter['abi']['version'] ?? '' ) );
		if ( true !== ( $adapter['foreign_runtime'] ?? false ) ) {
			return [
				'state'            => self::OK,
				'adapter_owner'    => $owner,
				'adapter_plugin'   => '',
				'adapter_version'  => $version,
				'blocking_reasons' => [],
				'abi_issues'       => [],
				'summary'          => __( 'The MCP adapter bundled with Stonewright serves the MCP route.', 'stonewright' ),
				'remedy'           => '',
			];
		}
		$who = self::owner( $owner );

		return [
			'state'            => self::FOREIGN,
			'adapter_owner'    => $owner,
			'adapter_plugin'   => $who['name'],
			'adapter_version'  => $version,
			'blocking_reasons' => [],
			'abi_issues'       => [],
			'summary'          => sprintf(
				/* translators: 1: who bundles the adapter, 2: adapter version */
				__( 'The MCP adapter in use is the copy bundled with %1$s (version %2$s). Stonewright serves its MCP route through it.', 'stonewright' ),
				$who['phrase'],
				'' === $version ? __( 'unknown', 'stonewright' ) : $version
			),
			'remedy'           => '',
		];
	}

	/**
	 * @param array<string,mixed> $report
	 * @param array<string,mixed> $adapter
	 * @param list<string>        $reasons
	 * @param list<string>        $issues
	 * @return array{state:string,adapter_owner:string,adapter_plugin:string,adapter_version:string,blocking_reasons:list<string>,abi_issues:list<string>,summary:string,remedy:string}
	 */
	private static function blocked_status( array $report, array $adapter, array $reasons, array $issues ): array {
		$role = 'adapter';
		foreach ( $reasons as $reason ) {
			if ( str_starts_with( $reason, 'abilities_registry_' ) ) {
				$role = 'abilities_registry';
			} elseif ( str_starts_with( $reason, 'ability_' ) ) {
				$role = 'ability';
			}
			break;
		}

		return 'adapter' === $role
			? self::blocked_adapter( $adapter, $reasons, $issues )
			: self::blocked_abilities( $report, $role, $reasons );
	}

	/**
	 * @param array<string,mixed> $adapter
	 * @param list<string>        $reasons
	 * @param list<string>        $issues
	 * @return array{state:string,adapter_owner:string,adapter_plugin:string,adapter_version:string,blocking_reasons:list<string>,abi_issues:list<string>,summary:string,remedy:string}
	 */
	private static function blocked_adapter( array $adapter, array $reasons, array $issues ): array {
		$owner   = self::text( $adapter['selected_owner'] ?? '' );
		$version = self::text( $adapter['selected_version'] ?? ( $adapter['abi']['version'] ?? '' ) );
		$who     = self::owner( $owner );
		$shown   = '' === $version ? __( 'unknown', 'stonewright' ) : $version;
		$prefix  = __( 'Stonewright cannot serve its MCP route:', 'stonewright' );
		$tail    = __( 'Abilities still run inside WordPress, but MCP clients cannot connect to Stonewright until this is resolved.', 'stonewright' );

		if ( in_array( 'adapter_unavailable', $reasons, true ) && '' === $owner ) {
			$summary = $prefix . ' ' . __( 'no WordPress MCP Adapter could be loaded.', 'stonewright' ) . ' ' . $tail;
			$remedy  = __( 'Reinstall Stonewright from its release ZIP, which bundles the adapter. If this stays, run the connection checks on the Troubleshoot page.', 'stonewright' );
		} elseif ( in_array( 'adapter_multiple_owners', $reasons, true ) ) {
			$names   = self::owner_names( (array) ( $adapter['owner_details'] ?? [] ) );
			$summary = $prefix . ' ' . sprintf(
				/* translators: %s: names of the plugins that each bundle a copy of the adapter */
				__( 'several copies of the WordPress MCP Adapter are installed (%s) and none could be chosen.', 'stonewright' ),
				'' === $names ? __( 'owners unknown', 'stonewright' ) : $names
			) . ' ' . $tail;
			$remedy  = __( 'Update the plugins that bundle their own MCP Adapter to releases with the same current version, or deactivate all but one of them. Stonewright does not change other plugins.', 'stonewright' );
		} else {
			$summary = $prefix . ' ' . sprintf(
				/* translators: 1: who bundles the adapter, 2: adapter version */
				__( '%1$s loaded its own copy of the WordPress MCP Adapter (version %2$s) first, and Stonewright cannot use it.', 'stonewright' ),
				$who['phrase'],
				$shown
			) . ' ' . $tail;
			$remedy  = self::adapter_remedy( $who );
		}

		return [
			'state'            => self::BLOCKED,
			'adapter_owner'    => $owner,
			'adapter_plugin'   => $who['name'],
			'adapter_version'  => $version,
			'blocking_reasons' => $reasons,
			'abi_issues'       => $issues,
			'summary'          => $summary,
			'remedy'           => $remedy,
		];
	}

	/**
	 * @param array<string,mixed> $report
	 * @param list<string>        $reasons
	 * @return array{state:string,adapter_owner:string,adapter_plugin:string,adapter_version:string,blocking_reasons:list<string>,abi_issues:list<string>,summary:string,remedy:string}
	 */
	private static function blocked_abilities( array $report, string $role, array $reasons ): array {
		$symbol  = is_array( $report['abilities'][ 'abilities_registry' === $role ? 'registry' : 'ability' ] ?? null ) ? $report['abilities'][ 'abilities_registry' === $role ? 'registry' : 'ability' ] : [];
		$details = array_values( array_filter( (array) ( $symbol['owner_details'] ?? [] ), 'is_array' ) );
		$others  = array_values( array_filter( $details, static fn( array $row ): bool => 'plugin:stonewright' !== ( $row['owner'] ?? '' ) ) );
		$names   = self::owner_names( [] === $others ? $details : $others );
		$issues  = array_values( array_filter( array_map( 'sanitize_key', array_map( 'strval', (array) ( $symbol['abi']['issues'] ?? [] ) ) ) ) );
		$summary = __( 'Stonewright cannot serve its MCP route:', 'stonewright' ) . ' ' . sprintf(
			/* translators: %s: names of the owners of the Abilities API classes */
			__( 'the Abilities API that is loaded (%s) is not compatible. Abilities still run inside WordPress, but MCP clients cannot connect to Stonewright until this is resolved.', 'stonewright' ),
			'' === $names ? __( 'owner unknown', 'stonewright' ) : $names
		);

		return [
			'state'            => self::BLOCKED,
			'adapter_owner'    => '',
			'adapter_plugin'   => '',
			'adapter_version'  => '',
			'blocking_reasons' => $reasons,
			'abi_issues'       => $issues,
			'summary'          => $summary,
			'remedy'           => __( 'Update or deactivate only the plugin that bundles an incompatible Abilities API. WordPress 6.9 and newer provide the Abilities API themselves.', 'stonewright' ),
		];
	}

	/** @param array{kind:string,name:string,phrase:string} $who */
	private static function adapter_remedy( array $who ): string {
		if ( 'plugin' === $who['kind'] || 'mu-plugin' === $who['kind'] || 'theme' === $who['kind'] ) {
			return sprintf(
				/* translators: 1: plugin, theme or must-use plugin that bundles the adapter, 2: oldest accepted adapter version */
				__( 'Update %1$s to a release that bundles MCP Adapter %2$s or newer, or deactivate it until it does. Stonewright does not change other plugins.', 'stonewright' ),
				$who['phrase'],
				self::MIN_FOREIGN_VERSION
			);
		}

		return sprintf(
			/* translators: %s: oldest accepted adapter version */
			__( 'Find the plugin, theme or must-use plugin that bundles its own MCP Adapter (the MCP runtime compatibility card on the Troubleshoot page lists the owners), update it to a release with MCP Adapter %s or newer or deactivate it. Stonewright does not change other plugins.', 'stonewright' ),
			self::MIN_FOREIGN_VERSION
		);
	}

	/**
	 * @param list<mixed> $details owner_details rows of a preflight symbol.
	 */
	private static function owner_names( array $details ): string {
		$names = [];
		foreach ( $details as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$who = self::owner( self::text( $row['owner'] ?? '' ) );
			$version = self::text( $row['version'] ?? '' );
			$label = '' !== $who['name'] ? $who['name'] : ( 'core' === $who['kind'] ? 'WordPress' : '' );
			if ( '' === $label ) {
				continue;
			}
			$names[] = '' === $version ? $label : $label . ' ' . $version;
		}

		return implode( ', ', array_values( array_unique( $names ) ) );
	}

	/**
	 * @return array{kind:string,name:string,phrase:string}
	 */
	private static function owner( string $owner ): array {
		$unknown = [
			'kind'   => 'unknown',
			'name'   => '',
			'phrase' => __( 'another plugin, theme or must-use plugin', 'stonewright' ),
		];
		if ( 'wordpress-core' === $owner ) {
			return [ 'kind' => 'core', 'name' => 'WordPress', 'phrase' => 'WordPress' ];
		}
		if ( 1 !== preg_match( '/^(plugin|mu-plugin|theme):([a-z0-9._-]+)$/', $owner, $matches ) ) {
			return $unknown;
		}
		$kind = $matches[1];
		$slug = $matches[2];
		if ( 'plugin' === $kind ) {
			$name = self::plugin_name( $slug );

			return [ 'kind' => 'plugin', 'name' => $name, 'phrase' => $name ];
		}
		if ( 'mu-plugin' === $kind ) {
			return [
				'kind'   => 'mu-plugin',
				'name'   => $slug,
				/* translators: %s: folder name of a must-use plugin */
				'phrase' => sprintf( __( 'the must-use plugin %s', 'stonewright' ), $slug ),
			];
		}

		return [
			'kind'   => 'theme',
			'name'   => $slug,
			/* translators: %s: folder name of a theme */
			'phrase' => sprintf( __( 'the theme %s', 'stonewright' ), $slug ),
		];
	}

	/** The name the plugin header gives, or the folder name when the plugin is not listed. */
	private static function plugin_name( string $slug ): string {
		if ( ! function_exists( 'get_plugins' ) && defined( 'ABSPATH' ) && is_readable( ABSPATH . 'wp-admin/includes/plugin.php' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		if ( function_exists( 'get_plugins' ) ) {
			foreach ( (array) get_plugins() as $file => $data ) {
				if ( ! is_array( $data ) || sanitize_key( dirname( (string) $file ) ) !== $slug ) {
					continue;
				}
				$name = self::text( $data['Name'] ?? '' );
				if ( '' !== $name ) {
					return $name;
				}
			}
		}

		return $slug;
	}

	private static function text( mixed $value ): string {
		return is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '';
	}
}
