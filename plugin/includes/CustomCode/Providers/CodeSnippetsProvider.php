<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\CustomCode\Providers;

use Stonewright\WpMcp\CustomCode\ProviderInterface;
use Stonewright\WpMcp\CustomCode\ProviderSupport;

/**
 * Code Snippets plugin adapter (Shea Bunge / Code Snippets Pro).
 *
 * Prefers the public `code_snippets()` DB API. Never performs blind raw SQL
 * updates outside that API surface.
 */
final class CodeSnippetsProvider implements ProviderInterface {

	public const PLUGIN_FILE = 'code-snippets/code-snippets.php';
	public const MIN_VERSION = '3.0.0';

	/** @var callable|null */
	private $backend;

	public function __construct( ?callable $backend = null ) {
		$this->backend = $backend;
	}

	public function id(): string {
		return 'code-snippets';
	}

	public function label(): string {
		return 'Code Snippets';
	}

	public function discover(): array {
		$version = $this->plugin_version();
		$active  = $this->is_active();
		$api     = $this->api_available();
		return [
			'id'           => $this->id(),
			'label'        => $this->label(),
			'available'    => '' !== $version || $api,
			'active'       => $active,
			'version'      => $version,
			'supported'    => $active && $api && ( '' === $version || version_compare( $version, self::MIN_VERSION, '>=' ) ),
			'plugin_file'  => self::PLUGIN_FILE,
			'capabilities' => [ 'discover', 'list', 'read', 'dry-run', 'apply', 'verify', 'rollback' ],
			'notes'        => $api
				? 'Uses Code Snippets public DB API for read/write with grant gate.'
				: 'Code Snippets API not detected; install/activate Code Snippets 3.x+.',
		];
	}

	/** @param array<string, mixed> $args */
	public function list( array $args = [] ) {
		$guard = $this->require_supported();
		if ( $guard instanceof \WP_Error ) {
			return $guard;
		}
		$limit = max( 1, min( 200, (int) ( $args['limit'] ?? 50 ) ) );
		if ( null !== $this->backend ) {
			$result = ( $this->backend )( 'list', [ 'limit' => $limit ] );
			return is_array( $result ) || $result instanceof \WP_Error ? $result : new \WP_Error( 'stonewright_code_snippets_backend_invalid', 'Invalid backend list result.', [ 'status' => 500 ] );
		}

		$items = [];
		$db    = $this->db();
		if ( null === $db || ! method_exists( $db, 'get_snippets' ) ) {
			return new \WP_Error(
				'stonewright_code_snippets_list_unavailable',
				__( 'Code Snippets list API is unavailable.', 'stonewright' ),
				[ 'status' => 503, 'provider' => $this->id() ]
			);
		}
		$snippets = $db->get_snippets();
		if ( ! is_array( $snippets ) && ! $snippets instanceof \Traversable ) {
			$snippets = [];
		}
		$count = 0;
		foreach ( $snippets as $snippet ) {
			if ( $count >= $limit ) {
				break;
			}
			$items[] = $this->summarize( $snippet );
			++$count;
		}

		return [
			'ok'       => true,
			'provider' => $this->id(),
			'count'    => count( $items ),
			'items'    => $items,
		];
	}

	public function read( string $target_id ) {
		$guard = $this->require_supported();
		if ( $guard instanceof \WP_Error ) {
			return $guard;
		}
		$snippet = $this->load( $target_id );
		if ( $snippet instanceof \WP_Error ) {
			return $snippet;
		}
		$code = (string) $snippet['code'];
		return [
			'ok'            => true,
			'provider'      => $this->id(),
			'id'            => (string) $snippet['id'],
			'title'         => (string) $snippet['title'],
			'language'      => (string) $snippet['language'],
			'active'        => (bool) $snippet['active'],
			'scope'         => (string) $snippet['scope'],
			'path'          => $this->path_for( (string) $snippet['id'] ),
			'bytes'         => strlen( $code ),
			'content_sha256'=> ProviderSupport::content_hash( $code ),
			'code'          => $code,
		];
	}

	/** @param array<string, mixed> $args */
	public function dry_run( array $args ) {
		$guard = $this->require_supported();
		if ( $guard instanceof \WP_Error ) {
			return $guard;
		}
		$target = sanitize_text_field( (string) ( $args['target_id'] ?? $args['id'] ?? '' ) );
		$code   = (string) ( $args['code'] ?? $args['content'] ?? '' );
		if ( '' === $target ) {
			return new \WP_Error( 'stonewright_code_snippets_target_required', __( 'target_id is required.', 'stonewright' ), [ 'status' => 400 ] );
		}
		$snippet = $this->load( $target );
		if ( $snippet instanceof \WP_Error ) {
			return $snippet;
		}
		$language = sanitize_key( (string) ( $args['language'] ?? $snippet['language'] ?? 'php' ) );
		if ( ! in_array( $language, [ 'php', 'css', 'js', 'html' ], true ) ) {
			$language = 'php';
		}
		return ProviderSupport::dry_run_handoff(
			$this->id(),
			$this->path_for( $target ),
			$language,
			(string) $snippet['code'],
			$code,
			[
				'max_changed_bytes' => (int) ( $args['max_changed_bytes'] ?? ProviderSupport::DEFAULT_MAX_CHANGED_BYTES ),
				'risk_class'        => 'custom_code_code_snippets',
				'rollback_scope'    => 'code_snippets:' . $target,
				'response_extra'    => [
					'target_id' => $target,
					'title'     => (string) $snippet['title'],
					'scope'     => (string) $snippet['scope'],
				],
			]
		);
	}

	/** @param array<string, mixed> $args */
	public function apply( array $args ) {
		$guard = $this->require_supported();
		if ( $guard instanceof \WP_Error ) {
			return $guard;
		}
		$target = sanitize_text_field( (string) ( $args['target_id'] ?? $args['id'] ?? '' ) );
		$code   = (string) ( $args['code'] ?? $args['content'] ?? '' );
		if ( '' === $target ) {
			return new \WP_Error( 'stonewright_code_snippets_target_required', __( 'target_id is required.', 'stonewright' ), [ 'status' => 400 ] );
		}
		$snippet = $this->load( $target );
		if ( $snippet instanceof \WP_Error ) {
			return $snippet;
		}
		$path     = $this->path_for( $target );
		$language = sanitize_key( (string) ( $args['language'] ?? $snippet['language'] ?? 'php' ) );
		if ( ! in_array( $language, [ 'php', 'css', 'js', 'html' ], true ) ) {
			$language = 'php';
		}
		$before        = (string) $snippet['code'];
		$after_hash    = ProviderSupport::content_hash( $code );
		$changed_bytes = ProviderSupport::changed_bytes( $before, $code );

		$conflict = ProviderSupport::assert_expected_hash(
			$before,
			(string) ( $args['expected_before_sha256'] ?? $args['before_sha256'] ?? '' ),
			$path
		);
		if ( $conflict instanceof \WP_Error ) {
			return $conflict;
		}

		$validation = ProviderSupport::validate_code( $code, $language );
		if ( $validation instanceof \WP_Error ) {
			return $validation;
		}

		$grant = ProviderSupport::consume_grant(
			(string) ( $args['custom_code_grant'] ?? '' ),
			$path,
			$after_hash,
			$language,
			$changed_bytes
		);
		if ( $grant instanceof \WP_Error ) {
			return $grant;
		}

		$snapshot = ProviderSupport::snapshot_record( $this->id(), $target, $path, $before );
		$saved    = $this->save( $target, $code, $snippet );
		if ( $saved instanceof \WP_Error ) {
			return $saved;
		}

		$verify = $this->verify(
			[
				'target_id'       => $target,
				'expected_sha256' => $after_hash,
			]
		);
		if ( $verify instanceof \WP_Error || true !== ( $verify['effect_verified'] ?? false ) ) {
			$rollback = $this->rollback(
				[
					'snapshot_id' => $snapshot['snapshot_id'],
					'target_id'   => $target,
				]
			);
			return new \WP_Error(
				'stonewright_code_snippets_verify_failed_restored',
				__( 'Code Snippets write verification failed; snapshot rollback attempted.', 'stonewright' ),
				[
					'status'              => 500,
					'provider'            => $this->id(),
					'target_id'           => $target,
					'before_sha256'       => $snapshot['before_sha256'],
					'after_sha256'        => $after_hash,
					'verification_status' => 'failed',
					'rollback_status'     => is_array( $rollback ) && ! empty( $rollback['effect_verified'] ) ? 'restored' : 'failed',
					'snapshot_id'         => $snapshot['snapshot_id'],
					'execution_status'    => 'ok',
					'effect_verified'     => false,
				]
			);
		}

		return [
			'ok'                  => true,
			'applied'             => true,
			'provider'            => $this->id(),
			'target_id'           => $target,
			'path'                => $path,
			'before_sha256'       => $snapshot['before_sha256'],
			'after_sha256'        => $after_hash,
			'readback_sha256'     => (string) ( $verify['content_sha256'] ?? $after_hash ),
			'changed_bytes'       => $changed_bytes,
			'snapshot_id'         => $snapshot['snapshot_id'],
			'execution_status'    => 'ok',
			'verification_status' => 'verified',
			'rollback_status'     => 'not_needed',
			'effect_verified'     => true,
			'operation_class'     => 'custom_code',
			'resource_type'       => 'custom_code_code_snippets',
			'resource_ref'        => $path,
		];
	}

	/** @param array<string, mixed> $args */
	public function verify( array $args ) {
		$target   = sanitize_text_field( (string) ( $args['target_id'] ?? $args['id'] ?? '' ) );
		$expected = strtolower( (string) ( $args['expected_sha256'] ?? $args['after_sha256'] ?? '' ) );
		$snippet  = $this->load( $target );
		if ( $snippet instanceof \WP_Error ) {
			return $snippet;
		}
		$hash = ProviderSupport::content_hash( (string) $snippet['code'] );
		$ok   = '' === $expected || hash_equals( $expected, $hash );
		return [
			'ok'                  => true,
			'provider'            => $this->id(),
			'target_id'           => $target,
			'path'                => $this->path_for( $target ),
			'content_sha256'      => $hash,
			'expected_sha256'     => $expected,
			'effect_verified'     => $ok,
			'verification_status' => $ok ? 'verified' : 'failed',
		];
	}

	/** @param array<string, mixed> $args */
	public function rollback( array $args ) {
		$snapshot_id = sanitize_text_field( (string) ( $args['snapshot_id'] ?? '' ) );
		$target      = sanitize_text_field( (string) ( $args['target_id'] ?? $args['id'] ?? '' ) );
		$snap        = ProviderSupport::load_snapshot( $snapshot_id );
		if ( null === $snap || (string) ( $snap['provider'] ?? '' ) !== $this->id() ) {
			return new \WP_Error(
				'stonewright_code_snippets_snapshot_missing',
				__( 'Code Snippets rollback snapshot not found or expired.', 'stonewright' ),
				[ 'status' => 404, 'provider' => $this->id() ]
			);
		}
		$snap_target = sanitize_text_field( (string) ( $snap['target_id'] ?? '' ) );
		if ( '' === $target ) {
			$target = $snap_target;
		} elseif ( '' !== $snap_target && $target !== $snap_target ) {
			return new \WP_Error(
				'stonewright_code_snippets_snapshot_target_mismatch',
				__( 'Rollback target_id does not match the snapshot target.', 'stonewright' ),
				[
					'status'          => 400,
					'provider'        => $this->id(),
					'target_id'       => $target,
					'snapshot_target' => $snap_target,
					'snapshot_id'     => $snapshot_id,
				]
			);
		}
		$snippet = $this->load( $target );
		if ( $snippet instanceof \WP_Error ) {
			return $snippet;
		}
		$saved = $this->save( $target, (string) $snap['body'], $snippet );
		if ( $saved instanceof \WP_Error ) {
			return $saved;
		}
		$verify = $this->verify(
			[
				'target_id'       => $target,
				'expected_sha256' => (string) $snap['before_sha256'],
			]
		);
		return [
			'ok'                  => true,
			'rolled_back'         => true,
			'provider'            => $this->id(),
			'target_id'           => $target,
			'snapshot_id'         => $snapshot_id,
			'effect_verified'     => is_array( $verify ) && ! empty( $verify['effect_verified'] ),
			'verification_status' => is_array( $verify ) ? (string) ( $verify['verification_status'] ?? '' ) : 'failed',
			'before_sha256'       => (string) $snap['before_sha256'],
		];
	}

	private function path_for( string $id ): string {
		return 'code-snippets/snippet/' . sanitize_text_field( $id );
	}

	/** @return true|\WP_Error */
	private function require_supported() {
		$info = $this->discover();
		if ( ! empty( $info['supported'] ) ) {
			return true;
		}
		return new \WP_Error(
			'stonewright_plugin_missing',
			__( 'Code Snippets is not active or its public API is unavailable.', 'stonewright' ),
			[ 'status' => 503, 'provider' => $this->id(), 'discover' => $info ]
		);
	}

	/**
	 * @return array{id:string,title:string,code:string,language:string,active:bool,scope:string}|\WP_Error
	 */
	private function load( string $id ) {
		$id = sanitize_text_field( $id );
		if ( null !== $this->backend ) {
			$result = ( $this->backend )( 'read', [ 'id' => $id ] );
			if ( $result instanceof \WP_Error ) {
				return $result;
			}
			if ( ! is_array( $result ) ) {
				return new \WP_Error( 'stonewright_code_snippets_not_found', __( 'Snippet not found.', 'stonewright' ), [ 'status' => 404 ] );
			}
			return [
				'id'       => (string) ( $result['id'] ?? $id ),
				'title'    => (string) ( $result['title'] ?? '' ),
				'code'     => (string) ( $result['code'] ?? '' ),
				'language' => sanitize_key( (string) ( $result['language'] ?? 'php' ) ),
				'active'   => (bool) ( $result['active'] ?? false ),
				'scope'    => sanitize_key( (string) ( $result['scope'] ?? 'global' ) ),
			];
		}

		$db = $this->db();
		if ( null === $db || ! method_exists( $db, 'get_snippet' ) ) {
			return new \WP_Error(
				'stonewright_code_snippets_api_unavailable',
				__( 'Code Snippets DB API is unavailable.', 'stonewright' ),
				[ 'status' => 503 ]
			);
		}
		$snippet = $db->get_snippet( (int) $id );
		if ( ! is_object( $snippet ) ) {
			return new \WP_Error( 'stonewright_code_snippets_not_found', __( 'Snippet not found.', 'stonewright' ), [ 'status' => 404, 'target_id' => $id ] );
		}
		return [
			'id'       => (string) ( $snippet->id ?? $id ),
			'title'    => (string) ( $snippet->name ?? $snippet->title ?? '' ),
			'code'     => (string) ( $snippet->code ?? '' ),
			'language' => $this->map_language( (string) ( $snippet->code_type ?? $snippet->type ?? 'php' ) ),
			'active'   => (bool) ( $snippet->active ?? false ),
			'scope'    => sanitize_key( (string) ( $snippet->scope ?? 'global' ) ),
		];
	}

	/**
	 * @param array<string, mixed> $current
	 * @return true|\WP_Error
	 */
	private function save( string $id, string $code, array $current ) {
		if ( null !== $this->backend ) {
			$result = ( $this->backend )( 'save', [ 'id' => $id, 'code' => $code, 'current' => $current ] );
			return $result instanceof \WP_Error ? $result : true;
		}

		$db = $this->db();
		if ( null === $db || ! method_exists( $db, 'save_snippet' ) ) {
			return new \WP_Error(
				'stonewright_code_snippets_save_unavailable',
				__( 'Code Snippets save API is unavailable.', 'stonewright' ),
				[ 'status' => 503 ]
			);
		}

		// Prefer reconstructing via the public Snippet class when present.
		if ( class_exists( '\Code_Snippets\Snippet' ) ) {
			$existing = method_exists( $db, 'get_snippet' ) ? $db->get_snippet( (int) $id ) : null;
			$snippet  = $existing instanceof \Code_Snippets\Snippet
				? $existing
				: new \Code_Snippets\Snippet( [ 'id' => (int) $id ] );
			$snippet->id   = (int) $id;
			$snippet->code = $code;
			if ( isset( $current['title'] ) && property_exists( $snippet, 'name' ) ) {
				$snippet->name = (string) $current['title'];
			}
			$result = $db->save_snippet( $snippet );
			if ( false === $result || ( is_object( $result ) && is_wp_error( $result ) ) ) {
				return new \WP_Error(
					'stonewright_code_snippets_save_failed',
					__( 'Code Snippets failed to save the snippet.', 'stonewright' ),
					[ 'status' => 500, 'target_id' => $id ]
				);
			}
			return true;
		}

		// Generic object path for forks that expose save_snippet with stdClass.
		$obj       = (object) [
			'id'    => (int) $id,
			'code'  => $code,
			'name'  => (string) ( $current['title'] ?? '' ),
			'scope' => (string) ( $current['scope'] ?? 'global' ),
		];
		$result = $db->save_snippet( $obj );
		if ( false === $result ) {
			return new \WP_Error(
				'stonewright_code_snippets_save_failed',
				__( 'Code Snippets failed to save the snippet.', 'stonewright' ),
				[ 'status' => 500, 'target_id' => $id ]
			);
		}
		return true;
	}

	/** @param mixed $snippet */
	private function summarize( $snippet ): array {
		if ( is_object( $snippet ) ) {
			$id = (string) ( $snippet->id ?? '' );
			return [
				'id'       => $id,
				'title'    => (string) ( $snippet->name ?? $snippet->title ?? '' ),
				'language' => $this->map_language( (string) ( $snippet->code_type ?? $snippet->type ?? 'php' ) ),
				'active'   => (bool) ( $snippet->active ?? false ),
				'scope'    => sanitize_key( (string) ( $snippet->scope ?? 'global' ) ),
				'path'     => $this->path_for( $id ),
			];
		}
		if ( is_array( $snippet ) ) {
			$id = (string) ( $snippet['id'] ?? '' );
			return [
				'id'       => $id,
				'title'    => (string) ( $snippet['name'] ?? $snippet['title'] ?? '' ),
				'language' => $this->map_language( (string) ( $snippet['code_type'] ?? $snippet['language'] ?? 'php' ) ),
				'active'   => (bool) ( $snippet['active'] ?? false ),
				'scope'    => sanitize_key( (string) ( $snippet['scope'] ?? 'global' ) ),
				'path'     => $this->path_for( $id ),
			];
		}
		return [ 'id' => '', 'title' => '', 'language' => 'php', 'active' => false, 'scope' => 'global', 'path' => '' ];
	}

	private function map_language( string $type ): string {
		$type = strtolower( $type );
		if ( str_contains( $type, 'css' ) ) {
			return 'css';
		}
		if ( str_contains( $type, 'js' ) || str_contains( $type, 'javascript' ) ) {
			return 'js';
		}
		if ( str_contains( $type, 'html' ) ) {
			return 'html';
		}
		return 'php';
	}

	private function db(): ?object {
		if ( function_exists( 'code_snippets' ) ) {
			$plugin = code_snippets();
			if ( is_object( $plugin ) && isset( $plugin->db ) && is_object( $plugin->db ) ) {
				return $plugin->db;
			}
		}
		return null;
	}

	private function api_available(): bool {
		if ( null !== $this->backend ) {
			return true;
		}
		return function_exists( 'code_snippets' ) || class_exists( '\Code_Snippets\Plugin' ) || class_exists( '\Code_Snippets\Snippet' );
	}

	private function is_active(): bool {
		if ( null !== $this->backend ) {
			$d = ( $this->backend )( 'discover', [] );
			return is_array( $d ) ? ! empty( $d['active'] ) : true;
		}
		if ( function_exists( 'is_plugin_active' ) && is_plugin_active( self::PLUGIN_FILE ) ) {
			return true;
		}
		return $this->api_available();
	}

	private function plugin_version(): string {
		if ( null !== $this->backend ) {
			$d = ( $this->backend )( 'discover', [] );
			return is_array( $d ) ? (string) ( $d['version'] ?? '3.6.0' ) : '3.6.0';
		}
		if ( defined( 'CODE_SNIPPETS_VERSION' ) ) {
			return (string) CODE_SNIPPETS_VERSION;
		}
		if ( ! function_exists( 'get_plugins' ) ) {
			return '';
		}
		$plugins = get_plugins();
		return isset( $plugins[ self::PLUGIN_FILE ]['Version'] ) ? (string) $plugins[ self::PLUGIN_FILE ]['Version'] : '';
	}
}
