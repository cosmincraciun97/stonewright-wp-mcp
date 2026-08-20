<?php
/**
 * Versioned MCP client catalog loaded from plugin/data/clients/*.json.
 *
 * @package Stonewright\WpMcp
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Admin;

/**
 * Loads and validates per-client connection definitions.
 */
final class ClientCatalog {

	/**
	 * @var list<array<string, mixed>>|null
	 */
	private static ?array $cache = null;

	/**
	 * Absolute directory containing client JSON definitions.
	 */
	public static function data_dir(): string {
		$dir = defined( 'STONEWRIGHT_DIR' ) ? (string) constant( 'STONEWRIGHT_DIR' ) : dirname( __DIR__, 2 ) . '/';
		return $dir . 'data/clients';
	}

	/**
	 * @return list<array{
	 *   slug:string,
	 *   label:string,
	 *   kind:string,
	 *   snippet_kind:string,
	 *   preferred_method:string,
	 *   official_cli_add:string,
	 *   config_paths:array<string,string>,
	 *   config_path:string,
	 *   notes:string,
	 *   verified_against_docs_on:string,
	 *   secret_storage:string,
	 *   transport:string,
	 *   config_format:string,
	 *   official_cli_update:string,
	 *   official_cli_remove:string,
	 *   oauth_support:bool,
	 *   app_password_support:bool,
	 *   relist_behavior:string,
	 *   new_task_required_after_catalog_change:bool,
	 *   safe_tool_budget:int,
	 *   default_profile:string,
	 *   support_tier:string,
	 *   certification_tier:string,
	 *   evidence:array{
	 *     manual_smoke:string,
	 *     oauth_http:string,
	 *     stdio:string,
	 *     restart_required:bool,
	 *     certification_report:string
	 *   }
	 * }>
	 */
	public static function all(): array {
		if ( null !== self::$cache ) {
			return self::$cache;
		}

		$dir = self::data_dir();
		if ( ! is_dir( $dir ) ) {
			self::$cache = [];
			return self::$cache;
		}

		$files = glob( $dir . '/*.json' );
		if ( ! is_array( $files ) ) {
			self::$cache = [];
			return self::$cache;
		}

		$clients = [];
		foreach ( $files as $file ) {
			$client = self::load_file( $file );
			if ( null !== $client ) {
				$clients[] = $client;
			}
		}

		usort(
			$clients,
			static fn( array $a, array $b ): int => strcasecmp( (string) $a['label'], (string) $b['label'] )
		);

		self::$cache = $clients;
		return self::$cache;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public static function get( string $slug ): ?array {
		$slug = sanitize_key( $slug );
		$aliases = [
			'vscode'  => 'vscode-copilot',
			'vs-code' => 'vscode-copilot',
			'claude'  => 'claude-desktop',
		];
		$resolved = $aliases[ $slug ] ?? $slug;
		foreach ( self::all() as $client ) {
			if ( (string) $client['slug'] === $resolved || (string) $client['slug'] === $slug ) {
				return $client;
			}
		}
		return null;
	}

	/**
	 * @return list<string>
	 */
	public static function slugs(): array {
		return array_values(
			array_map(
				static fn( array $c ): string => (string) $c['slug'],
				self::all()
			)
		);
	}

	/**
	 * Reset cache (tests only).
	 *
	 * @internal
	 */
	public static function reset_for_tests(): void {
		self::$cache = null;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private static function load_file( string $path ): ?array {
		$raw = file_get_contents( $path );
		if ( false === $raw || '' === $raw ) {
			return null;
		}

		try {
			$data = json_decode( $raw, true, 512, JSON_THROW_ON_ERROR );
		} catch ( \JsonException $e ) {
			return null;
		}

		if ( ! is_array( $data ) ) {
			return null;
		}

		return self::normalize( $data );
	}

	/**
	 * @param array<string, mixed> $data
	 * @return array<string, mixed>|null
	 */
	private static function normalize( array $data ): ?array {
		$slug  = isset( $data['slug'] ) ? sanitize_key( (string) $data['slug'] ) : '';
		$label = isset( $data['label'] ) ? (string) $data['label'] : '';
		if ( '' === $slug || '' === $label ) {
			return null;
		}

		$paths = [];
		if ( isset( $data['config_paths'] ) && is_array( $data['config_paths'] ) ) {
			foreach ( $data['config_paths'] as $os => $path ) {
				$paths[ sanitize_key( (string) $os ) ] = (string) $path;
			}
		}

		// Back-compat single config_path for ConnectClientConfig consumers.
		$config_path = isset( $data['config_path'] ) ? (string) $data['config_path'] : '';
		if ( '' === $config_path && isset( $paths['macos'] ) ) {
			$config_path = (string) $paths['macos'];
		}
		if ( '' === $config_path && [] !== $paths ) {
			$config_path = (string) reset( $paths );
		}

		$kind = isset( $data['kind'] ) ? sanitize_key( (string) $data['kind'] ) : 'editor';
		if ( ! in_array( $kind, [ 'cli', 'desktop', 'editor', 'generic' ], true ) ) {
			$kind = 'editor';
		}

		$snippet_kind = isset( $data['snippet_kind'] ) ? sanitize_key( (string) $data['snippet_kind'] ) : 'json';
		if ( ! in_array( $snippet_kind, [ 'json', 'toml', 'cli', 'mixed' ], true ) ) {
			$snippet_kind = 'json';
		}

		$method = isset( $data['preferred_method'] ) ? sanitize_key( (string) $data['preferred_method'] ) : 'stdio';
		if ( ! in_array( $method, [ 'stdio', 'http', 'application-password' ], true ) ) {
			$method = 'stdio';
		}

		$secret = isset( $data['secret_storage'] ) ? sanitize_key( (string) $data['secret_storage'] ) : 'user-level';
		if ( ! in_array( $secret, [ 'user-level', 'project-discouraged', 'none' ], true ) ) {
			$secret = 'user-level';
		}

		$verified = isset( $data['verified_against_docs_on'] ) ? (string) $data['verified_against_docs_on'] : '';
		if ( '' !== $verified && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $verified ) ) {
			$verified = '';
		}

		$transport = isset( $data['transport'] ) ? sanitize_key( (string) $data['transport'] ) : $method;
		if ( ! in_array( $transport, [ 'stdio', 'http', 'application-password', 'mixed' ], true ) ) {
			$transport = $method;
		}

		$config_format = isset( $data['config_format'] ) ? sanitize_key( (string) $data['config_format'] ) : $snippet_kind;
		if ( ! in_array( $config_format, [ 'json', 'toml', 'cli', 'mixed', 'json-mcp', 'json-servers', 'toml-codex', 'cli-only', 'unknown' ], true ) ) {
			$config_format = $snippet_kind;
		}

		$support_tier = isset( $data['support_tier'] ) ? sanitize_key( (string) $data['support_tier'] ) : 'compatible';
		if ( ! in_array( $support_tier, [ 'certified', 'compatible', 'community', 'unknown' ], true ) ) {
			$support_tier = 'compatible';
		}

		$relist = isset( $data['relist_behavior'] ) ? (string) $data['relist_behavior'] : 'reload-or-restart';
		$budget = isset( $data['safe_tool_budget'] ) ? (int) $data['safe_tool_budget'] : 40;
		if ( $budget < 1 ) {
			$budget = 40;
		}
		$default_profile = isset( $data['default_profile'] ) ? sanitize_key( (string) $data['default_profile'] ) : 'essential-static';
		if ( ! in_array( $default_profile, [ 'bootstrap', 'essential-static', 'essential', 'low-tools', 'full' ], true ) ) {
			$default_profile = 'essential-static';
		}

		$oauth_support = array_key_exists( 'oauth_support', $data )
			? (bool) $data['oauth_support']
			: false;
		$app_password_support = array_key_exists( 'app_password_support', $data )
			? (bool) $data['app_password_support']
			: true;
		$new_task_required = array_key_exists( 'new_task_required_after_catalog_change', $data )
			? (bool) $data['new_task_required_after_catalog_change']
			: true;

		$certification_tier = isset( $data['certification_tier'] ) ? sanitize_key( (string) $data['certification_tier'] ) : 'compatible';
		if ( ! in_array( $certification_tier, [ 'tier-1', 'tier-2', 'compatible', 'experimental' ], true ) ) {
			$certification_tier = 'compatible';
		}

		$evidence_raw = ( isset( $data['evidence'] ) && is_array( $data['evidence'] ) ) ? $data['evidence'] : [];
		$manual_smoke = isset( $evidence_raw['manual_smoke'] ) ? sanitize_key( (string) $evidence_raw['manual_smoke'] ) : 'pending';
		if ( ! in_array( $manual_smoke, [ 'pending', 'pass', 'fail', 'skipped' ], true ) ) {
			$manual_smoke = 'pending';
		}
		$oauth_http = isset( $evidence_raw['oauth_http'] ) ? sanitize_key( (string) $evidence_raw['oauth_http'] ) : 'untested';
		if ( ! in_array( $oauth_http, [ 'untested', 'compatible', 'certified', 'unsupported' ], true ) ) {
			$oauth_http = 'untested';
		}
		$stdio = isset( $evidence_raw['stdio'] ) ? sanitize_key( (string) $evidence_raw['stdio'] ) : 'untested';
		if ( ! in_array( $stdio, [ 'untested', 'compatible', 'certified', 'unsupported' ], true ) ) {
			$stdio = 'untested';
		}
		$restart_required = array_key_exists( 'restart_required', $evidence_raw )
			? (bool) $evidence_raw['restart_required']
			: true;
		$certification_report = isset( $evidence_raw['certification_report'] )
			? sanitize_text_field( (string) $evidence_raw['certification_report'] )
			: '';

		$has_certified_transport = in_array( 'certified', [ $oauth_http, $stdio ], true );
		if ( 'certified' === $support_tier && ( 'pass' !== $manual_smoke || ! $has_certified_transport || '' === $certification_report ) ) {
			$support_tier = 'compatible';
		}

		return [
			'slug'                     => $slug,
			'label'                    => $label,
			'kind'                     => $kind,
			'snippet_kind'             => $snippet_kind,
			'preferred_method'         => $method,
			'official_cli_add'         => isset( $data['official_cli_add'] ) ? (string) $data['official_cli_add'] : '',
			'official_cli_update'      => isset( $data['official_cli_update'] ) ? (string) $data['official_cli_update'] : '',
			'official_cli_remove'      => isset( $data['official_cli_remove'] ) ? (string) $data['official_cli_remove'] : '',
			'config_paths'             => $paths,
			'config_path'              => $config_path,
			'notes'                    => isset( $data['notes'] ) ? (string) $data['notes'] : '',
			'verified_against_docs_on' => $verified,
			'secret_storage'           => $secret,
			'transport'                => $transport,
			'config_format'            => $config_format,
			'oauth_support'            => $oauth_support,
			'app_password_support'     => $app_password_support,
			'relist_behavior'          => $relist,
			'new_task_required_after_catalog_change' => $new_task_required,
			'safe_tool_budget'         => $budget,
			'default_profile'          => $default_profile,
			'support_tier'             => $support_tier,
			'certification_tier'       => $certification_tier,
			'evidence'                 => [
				'manual_smoke'         => $manual_smoke,
				'oauth_http'           => $oauth_http,
				'stdio'                => $stdio,
				'restart_required'     => $restart_required,
				'certification_report' => $certification_report,
			],
		];
	}
}
