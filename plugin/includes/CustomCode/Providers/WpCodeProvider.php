<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\CustomCode\Providers;

use Stonewright\WpMcp\CustomCode\ProviderInterface;
use Stonewright\WpMcp\CustomCode\ProviderSupport;

/**
 * WPCode (Insert Headers and Footers / WPCodebox successor) adapter.
 *
 * Uses WPCode public snippet APIs when present — never blind table writes.
 * Supported when `wpcode()` / `WPCode` / `wpcode_get_snippet` surfaces are available.
 */
final class WpCodeProvider implements ProviderInterface {

	public const PLUGIN_FILE = 'insert-headers-and-footers/ihaf.php';
	public const PLUGIN_FILE_ALT = 'wpcode-premium/wpcode.php';
	public const MIN_VERSION = '2.0.0';

	/** @var callable|null */
	private $backend;

	/**
	 * @param callable|null $backend Optional test double: fn(string $op, array $args): mixed
	 */
	public function __construct( ?callable $backend = null ) {
		$this->backend = $backend;
	}

	public function id(): string {
		return 'wpcode';
	}

	public function label(): string {
		return 'WPCode';
	}

	public function discover(): array {
		$version = $this->plugin_version();
		$active  = $this->is_active();
		$api     = $this->api_available();
		return [
			'id'          => $this->id(),
			'label'       => $this->label(),
			'available'   => '' !== $version || $api,
			'active'      => $active,
			'version'     => $version,
			'supported'   => $active && $api && ( '' === $version || version_compare( $version, self::MIN_VERSION, '>=' ) ),
			'plugin_file' => $this->resolved_plugin_file(),
			'capabilities'=> [ 'discover', 'list', 'read', 'dry-run', 'apply', 'verify', 'rollback' ],
			'notes'       => $api
				? 'Uses WPCode public snippet APIs for read/write with grant gate.'
				: 'WPCode public API not detected; install/activate WPCode 2.x+.',
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
			return is_array( $result ) || $result instanceof \WP_Error ? $result : new \WP_Error( 'stonewright_wpcode_backend_invalid', 'Invalid WPCode backend list result.', [ 'status' => 500 ] );
		}

		$items = [];
		if ( function_exists( 'wpcode' ) && is_object( wpcode() ) && isset( wpcode()->snippets ) && is_object( wpcode()->snippets ) && method_exists( wpcode()->snippets, 'get_list' ) ) {
			$list = wpcode()->snippets->get_list( [ 'per_page' => $limit ] );
			if ( is_array( $list ) ) {
				foreach ( array_slice( $list, 0, $limit ) as $snippet ) {
					$items[] = $this->summarize_snippet( $snippet );
				}
			}
		} else {
			// Fallback: public CPT query when API list is unavailable but post type exists.
			$post_type = '';
			if ( function_exists( 'post_type_exists' ) ) {
				$post_type = post_type_exists( 'wpcode' ) ? 'wpcode' : ( post_type_exists( 'wpcode-snippets' ) ? 'wpcode-snippets' : '' );
			}
			if ( '' === $post_type ) {
				return new \WP_Error(
					'stonewright_wpcode_list_unavailable',
					__( 'WPCode snippet list API is unavailable on this site.', 'stonewright' ),
					[ 'status' => 503, 'provider' => $this->id() ]
				);
			}
			$query = new \WP_Query(
				[
					'post_type'      => $post_type,
					'post_status'    => [ 'publish', 'draft', 'private' ],
					'posts_per_page' => $limit,
					'orderby'        => 'ID',
					'order'          => 'DESC',
					'fields'         => 'ids',
				]
			);
			foreach ( (array) $query->posts as $id ) {
				$items[] = [
					'id'       => (string) $id,
					'title'    => get_the_title( (int) $id ),
					'language' => sanitize_key( (string) get_post_meta( (int) $id, '_wpcode_auto_insert_code_type', true ) ?: 'php' ),
					'active'   => 'publish' === get_post_status( (int) $id ),
					'path'     => $this->path_for( (string) $id ),
				];
			}
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
		$snippet = $this->load_snippet( $target_id );
		if ( $snippet instanceof \WP_Error ) {
			return $snippet;
		}
		$code = (string) $snippet['code'];
		return [
			'ok'           => true,
			'provider'     => $this->id(),
			'id'           => (string) $snippet['id'],
			'title'        => (string) $snippet['title'],
			'language'     => (string) $snippet['language'],
			'active'       => (bool) $snippet['active'],
			'path'         => $this->path_for( (string) $snippet['id'] ),
			'bytes'        => strlen( $code ),
			'content_sha256'=> ProviderSupport::content_hash( $code ),
			// Code body is returned to the authenticated operator only; never audited.
			'code'         => $code,
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
			return new \WP_Error( 'stonewright_wpcode_target_required', __( 'target_id is required.', 'stonewright' ), [ 'status' => 400 ] );
		}
		$snippet = $this->load_snippet( $target );
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
				'risk_class'        => 'custom_code_wpcode',
				'rollback_scope'    => 'wpcode_snippet:' . $target,
				'response_extra'    => [
					'target_id' => $target,
					'title'     => (string) $snippet['title'],
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
			return new \WP_Error( 'stonewright_wpcode_target_required', __( 'target_id is required.', 'stonewright' ), [ 'status' => 400 ] );
		}
		$snippet = $this->load_snippet( $target );
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

		$expected = (string) ( $args['expected_before_sha256'] ?? $args['before_sha256'] ?? '' );
		$conflict = ProviderSupport::assert_expected_hash( $before, $expected, $path );
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
		$saved    = $this->save_snippet( $target, $code, $language, $snippet );
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
				'stonewright_wpcode_verify_failed_restored',
				__( 'WPCode write verification failed; provider snapshot rollback attempted.', 'stonewright' ),
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
			'resource_type'       => 'custom_code_wpcode',
			'resource_ref'        => $path,
		];
	}

	/** @param array<string, mixed> $args */
	public function verify( array $args ) {
		$target = sanitize_text_field( (string) ( $args['target_id'] ?? $args['id'] ?? '' ) );
		$expected = strtolower( (string) ( $args['expected_sha256'] ?? $args['after_sha256'] ?? '' ) );
		$snippet  = $this->load_snippet( $target );
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
				'stonewright_wpcode_snapshot_missing',
				__( 'WPCode rollback snapshot not found or expired.', 'stonewright' ),
				[ 'status' => 404, 'provider' => $this->id() ]
			);
		}
		if ( '' === $target ) {
			$target = (string) ( $snap['target_id'] ?? '' );
		}
		$snippet = $this->load_snippet( $target );
		if ( $snippet instanceof \WP_Error ) {
			return $snippet;
		}
		$saved = $this->save_snippet( $target, (string) $snap['body'], (string) ( $snippet['language'] ?? 'php' ), $snippet );
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
		return 'wpcode/snippet/' . sanitize_text_field( $id );
	}

	/** @return true|\WP_Error */
	private function require_supported() {
		$info = $this->discover();
		if ( ! empty( $info['supported'] ) ) {
			return true;
		}
		return new \WP_Error(
			'stonewright_plugin_missing',
			__( 'WPCode is not active or the public snippet API is unavailable.', 'stonewright' ),
			[ 'status' => 503, 'provider' => $this->id(), 'discover' => $info ]
		);
	}

	/**
	 * @return array{id:string,title:string,code:string,language:string,active:bool}|\WP_Error
	 */
	private function load_snippet( string $id ) {
		$id = sanitize_text_field( $id );
		if ( null !== $this->backend ) {
			$result = ( $this->backend )( 'read', [ 'id' => $id ] );
			if ( $result instanceof \WP_Error ) {
				return $result;
			}
			if ( ! is_array( $result ) ) {
				return new \WP_Error( 'stonewright_wpcode_not_found', __( 'WPCode snippet not found.', 'stonewright' ), [ 'status' => 404 ] );
			}
			return [
				'id'       => (string) ( $result['id'] ?? $id ),
				'title'    => (string) ( $result['title'] ?? '' ),
				'code'     => (string) ( $result['code'] ?? '' ),
				'language' => sanitize_key( (string) ( $result['language'] ?? 'php' ) ),
				'active'   => (bool) ( $result['active'] ?? false ),
			];
		}

		// Prefer public getters.
		if ( function_exists( 'wpcode_get_snippet' ) ) {
			$snippet = wpcode_get_snippet( (int) $id );
			if ( is_object( $snippet ) ) {
				$code = method_exists( $snippet, 'get_code' ) ? (string) $snippet->get_code() : (string) ( $snippet->code ?? '' );
				$title = method_exists( $snippet, 'get_title' ) ? (string) $snippet->get_title() : (string) ( $snippet->title ?? '' );
				$type  = method_exists( $snippet, 'get_code_type' ) ? (string) $snippet->get_code_type() : (string) ( $snippet->code_type ?? 'php' );
				$active = method_exists( $snippet, 'is_active' ) ? (bool) $snippet->is_active() : ! empty( $snippet->active );
				return [
					'id'       => (string) $id,
					'title'    => $title,
					'code'     => $code,
					'language' => $this->map_language( $type ),
					'active'   => $active,
				];
			}
		}

		$post = get_post( (int) $id );
		if ( ! $post instanceof \WP_Post ) {
			return new \WP_Error( 'stonewright_wpcode_not_found', __( 'WPCode snippet not found.', 'stonewright' ), [ 'status' => 404, 'target_id' => $id ] );
		}
		$code = (string) get_post_meta( (int) $id, '_wpcode_snippet_code', true );
		if ( '' === $code ) {
			$code = (string) $post->post_content;
		}
		$type = (string) get_post_meta( (int) $id, '_wpcode_auto_insert_code_type', true );
		return [
			'id'       => (string) $id,
			'title'    => (string) $post->post_title,
			'code'     => $code,
			'language' => $this->map_language( $type ),
			'active'   => 'publish' === $post->post_status,
		];
	}

	/**
	 * @param array<string, mixed> $current
	 * @return true|\WP_Error
	 */
	private function save_snippet( string $id, string $code, string $language, array $current ) {
		if ( null !== $this->backend ) {
			$result = ( $this->backend )(
				'save',
				[
					'id'       => $id,
					'code'     => $code,
					'language' => $language,
					'current'  => $current,
				]
			);
			if ( $result instanceof \WP_Error ) {
				return $result;
			}
			return true;
		}

		if ( function_exists( 'wpcode_get_snippet' ) ) {
			$snippet = wpcode_get_snippet( (int) $id );
			if ( is_object( $snippet ) ) {
				if ( method_exists( $snippet, 'set_code' ) ) {
					$snippet->set_code( $code );
				} else {
					$snippet->code = $code;
				}
				if ( method_exists( $snippet, 'save' ) ) {
					$snippet->save();
					return true;
				}
			}
		}

		// Last-resort public meta update for known WPCode post types only.
		$post = get_post( (int) $id );
		if ( ! $post instanceof \WP_Post ) {
			return new \WP_Error( 'stonewright_wpcode_not_found', __( 'WPCode snippet not found.', 'stonewright' ), [ 'status' => 404 ] );
		}
		update_post_meta( (int) $id, '_wpcode_snippet_code', $code );
		return true;
	}

	/** @param mixed $snippet */
	private function summarize_snippet( $snippet ): array {
		if ( is_object( $snippet ) ) {
			$id = method_exists( $snippet, 'get_id' ) ? (string) $snippet->get_id() : (string) ( $snippet->id ?? '' );
			$title = method_exists( $snippet, 'get_title' ) ? (string) $snippet->get_title() : (string) ( $snippet->title ?? '' );
			$type = method_exists( $snippet, 'get_code_type' ) ? (string) $snippet->get_code_type() : (string) ( $snippet->code_type ?? 'php' );
			$active = method_exists( $snippet, 'is_active' ) ? (bool) $snippet->is_active() : ! empty( $snippet->active );
			return [
				'id'       => $id,
				'title'    => $title,
				'language' => $this->map_language( $type ),
				'active'   => $active,
				'path'     => $this->path_for( $id ),
			];
		}
		if ( is_array( $snippet ) ) {
			$id = (string) ( $snippet['id'] ?? '' );
			return [
				'id'       => $id,
				'title'    => (string) ( $snippet['title'] ?? '' ),
				'language' => $this->map_language( (string) ( $snippet['code_type'] ?? $snippet['language'] ?? 'php' ) ),
				'active'   => (bool) ( $snippet['active'] ?? false ),
				'path'     => $this->path_for( $id ),
			];
		}
		return [ 'id' => '', 'title' => '', 'language' => 'php', 'active' => false, 'path' => '' ];
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

	private function is_active(): bool {
		if ( null !== $this->backend ) {
			$d = ( $this->backend )( 'discover', [] );
			return is_array( $d ) ? ! empty( $d['active'] ) : true;
		}
		foreach ( [ self::PLUGIN_FILE, self::PLUGIN_FILE_ALT, 'wpcode/wpcode.php' ] as $file ) {
			if ( function_exists( 'is_plugin_active' ) && is_plugin_active( $file ) ) {
				return true;
			}
		}
		return function_exists( 'wpcode' ) || class_exists( '\WPCode' ) || function_exists( 'wpcode_get_snippet' );
	}

	private function api_available(): bool {
		if ( null !== $this->backend ) {
			return true;
		}
		return function_exists( 'wpcode_get_snippet' )
			|| function_exists( 'wpcode' )
			|| class_exists( '\WPCode' )
			|| ( function_exists( 'post_type_exists' ) && ( post_type_exists( 'wpcode' ) || post_type_exists( 'wpcode-snippets' ) ) );
	}

	private function plugin_version(): string {
		if ( null !== $this->backend ) {
			$d = ( $this->backend )( 'discover', [] );
			return is_array( $d ) ? (string) ( $d['version'] ?? '2.1.0' ) : '2.1.0';
		}
		if ( defined( 'WPCODE_VERSION' ) ) {
			return (string) WPCODE_VERSION;
		}
		if ( ! function_exists( 'get_plugins' ) ) {
			return '';
		}
		$plugins = get_plugins();
		foreach ( [ self::PLUGIN_FILE, self::PLUGIN_FILE_ALT, 'wpcode/wpcode.php' ] as $file ) {
			if ( isset( $plugins[ $file ]['Version'] ) ) {
				return (string) $plugins[ $file ]['Version'];
			}
		}
		return '';
	}

	private function resolved_plugin_file(): string {
		foreach ( [ self::PLUGIN_FILE, self::PLUGIN_FILE_ALT, 'wpcode/wpcode.php' ] as $file ) {
			if ( function_exists( 'is_plugin_active' ) && is_plugin_active( $file ) ) {
				return $file;
			}
		}
		return self::PLUGIN_FILE;
	}
}
