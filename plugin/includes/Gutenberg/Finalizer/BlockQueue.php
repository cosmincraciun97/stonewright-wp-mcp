<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Gutenberg\Finalizer;

/**
 * Persistent queue of Gutenberg block specs waiting for browser-side save().
 *
 * Distinct from FseTransactionQueue (FSE snapshot targets). This queue stores
 * `{name, attributes, innerBlocks}` until the hidden finalizer serializes them
 * against the live JS block registry.
 */
final class BlockQueue {

	public const OPTION = 'stonewright_block_finalizer_queue';

	/** @var list<string> */
	private const TERMINAL = [ 'persisted', 'failed', 'cancelled' ];

	/**
	 * Static / third-party blocks need the browser finalizer. Dynamic blocks
	 * (`save: null` / render_callback / is_dynamic) stay on the PHP fast path.
	 */
	public static function requires_finalizer( string $block_name ): bool {
		$block_name = sanitize_text_field( $block_name );
		if ( '' === $block_name ) {
			return true;
		}
		if ( ! class_exists( '\WP_Block_Type_Registry' ) || ! method_exists( '\WP_Block_Type_Registry', 'get_instance' ) ) {
			return true;
		}
		try {
			$registered = \WP_Block_Type_Registry::get_instance()->get_registered( $block_name );
		} catch ( \Throwable $_throwable ) {
			return true;
		}
		if ( ! is_object( $registered ) ) {
			return true;
		}
		if ( ! empty( $registered->is_dynamic ) ) {
			return false;
		}
		if ( isset( $registered->render_callback ) && is_callable( $registered->render_callback ) ) {
			return false;
		}
		if ( method_exists( $registered, 'is_dynamic' ) && $registered->is_dynamic() ) {
			return false;
		}
		return true;
	}

	/**
	 * @param array<string, mixed> $args
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function enqueue( array $args ): array|\WP_Error {
		$post_id = (int) ( $args['post_id'] ?? 0 );
		$post    = $post_id > 0 ? get_post( $post_id ) : null;
		if ( ! $post ) {
			return new \WP_Error(
				'stonewright_not_found',
				__( 'Post not found.', 'stonewright' ),
				[ 'status' => 404 ]
			);
		}

		$allow_raw = ! empty( $args['allow_raw_html'] );
		$spec      = self::normalize_spec( $args['block_spec'] ?? null, $allow_raw );
		if ( $spec instanceof \WP_Error ) {
			return $spec;
		}

		$current_hash = hash( 'sha256', (string) $post->post_content );
		$expected     = (string) ( $args['expected_content_hash'] ?? '' );
		if ( '' !== $expected && ! hash_equals( $expected, $current_hash ) ) {
			return new \WP_Error(
				'stonewright_content_conflict',
				__( 'The post content changed after planning; parse it again before queueing.', 'stonewright' ),
				[
					'status'                => 409,
					'expected_content_hash' => $expected,
					'current_content_hash'  => $current_hash,
					'retryable'             => true,
				]
			);
		}

		$pending = self::pending_for_target( $post_id );
		if ( null !== $pending ) {
			return new \WP_Error(
				'stonewright_finalizer_pending_change',
				__( 'This post already has a non-terminal block finalizer change. Finalize or cancel it first.', 'stonewright' ),
				[
					'status'    => 409,
					'change_id' => $pending['id'],
					'post_id'   => $post_id,
				]
			);
		}

		$state = self::state();
		$id    = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : substr( hash( 'sha256', uniqid( 'finalizer-', true ) ), 0, 36 );
		$record = [
			'id'                    => $id,
			'post_id'               => $post_id,
			'status'                => 'queued',
			'block_spec'            => $spec,
			'action'                => sanitize_key( (string) ( $args['action'] ?? 'insert' ) ),
			'path'                  => isset( $args['path'] ) && is_array( $args['path'] ) ? array_values( array_map( 'intval', $args['path'] ) ) : [],
			'position'              => isset( $args['position'] ) ? (int) $args['position'] : null,
			'expected_content_hash' => '' !== $expected ? $expected : $current_hash,
			'serialized_html'       => '',
			'serialized_html_hash'  => '',
			'session_id'            => self::session_id( $state ),
			'created_at'            => time(),
			'allow_raw_html'        => $allow_raw,
		];
		$state['changes'][ $id ] = $record;
		self::save( $state );

		return self::compact( $record );
	}

	/**
	 * @return array<string, mixed>|null Full record including block_spec.
	 */
	public static function get( string $id ): ?array {
		$state = self::state();
		$record = $state['changes'][ $id ] ?? null;
		return is_array( $record ) ? $record : null;
	}

	/**
	 * Bounded list/status: never includes full block_spec.
	 *
	 * @return list<array<string, mixed>>
	 */
	public static function list(): array {
		$out = [];
		foreach ( self::state()['changes'] as $record ) {
			if ( is_array( $record ) ) {
				$out[] = self::compact( $record );
			}
		}
		return $out;
	}

	public static function pending_count(): int {
		$count = 0;
		foreach ( self::state()['changes'] as $record ) {
			if ( is_array( $record ) && self::is_open( $record ) ) {
				++$count;
			}
		}
		return $count;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public static function pending_for_target( int $post_id ): ?array {
		foreach ( self::state()['changes'] as $record ) {
			if ( is_array( $record ) && (int) ( $record['post_id'] ?? 0 ) === $post_id && self::is_open( $record ) ) {
				return $record;
			}
		}
		return null;
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public static function pending_for_session( string $session_id, bool $include_spec = false ): array {
		$out = [];
		foreach ( self::state()['changes'] as $record ) {
			if ( ! is_array( $record ) || (string) ( $record['session_id'] ?? '' ) !== $session_id || ! self::is_open( $record ) ) {
				continue;
			}
			$out[] = $include_spec ? $record : self::compact( $record );
		}
		return $out;
	}

	/**
	 * @return true|\WP_Error
	 */
	public static function store_serialized( string $id, string $html, string $hash ): true|\WP_Error {
		$record = self::get( $id );
		if ( null === $record ) {
			return new \WP_Error(
				'stonewright_finalizer_not_found',
				__( 'Finalizer change not found.', 'stonewright' ),
				[ 'status' => 404 ]
			);
		}
		if ( ! self::is_open( $record ) || 'persisted' === (string) $record['status'] ) {
			return new \WP_Error(
				'stonewright_finalizer_terminal',
				__( 'This finalizer change is no longer open for serialization.', 'stonewright' ),
				[ 'status' => 409 ]
			);
		}
		$expect = hash( 'sha256', $html );
		if ( '' === $hash || ! hash_equals( $expect, $hash ) ) {
			return new \WP_Error(
				'stonewright_finalizer_hash_mismatch',
				__( 'Serialized HTML hash does not match the payload.', 'stonewright' ),
				[ 'status' => 400 ]
			);
		}
		$state = self::state();
		$state['changes'][ $id ]['serialized_html']      = $html;
		$state['changes'][ $id ]['serialized_html_hash'] = $expect;
		$state['changes'][ $id ]['status']               = 'serialized';
		self::save( $state );
		return true;
	}

	public static function mark_persisted( string $id ): void {
		$state = self::state();
		if ( isset( $state['changes'][ $id ] ) && is_array( $state['changes'][ $id ] ) ) {
			$state['changes'][ $id ]['status'] = 'persisted';
			self::save( $state );
		}
	}

	public static function mark_failed( string $id, string $message = '' ): void {
		$state = self::state();
		if ( isset( $state['changes'][ $id ] ) && is_array( $state['changes'][ $id ] ) ) {
			$state['changes'][ $id ]['status'] = 'failed';
			$state['changes'][ $id ]['error']  = sanitize_text_field( $message );
			self::save( $state );
		}
	}

	/**
	 * @return array{token:string,session_id:string,expires_at:int}
	 */
	public static function issue_token(): array {
		$state   = self::state();
		$session = self::session_id( $state );
		$exp     = time() + ( defined( 'HOUR_IN_SECONDS' ) ? (int) HOUR_IN_SECONDS : 3600 );
		$payload = wp_json_encode(
			[
				's' => $session,
				'u' => (int) get_current_user_id(),
				'e' => $exp,
			],
			JSON_UNESCAPED_SLASHES
		);
		$body = rtrim( strtr( base64_encode( (string) $payload ), '+/', '-_' ), '=' );
		$sig  = hash_hmac( 'sha256', $body, wp_salt( 'auth' ) );
		return [
			'token'      => $body . '.' . $sig,
			'session_id' => $session,
			'expires_at' => $exp,
		];
	}

	/**
	 * @return array{s:string,u:int,e:int}|\WP_Error
	 */
	public static function verify_token( string $token ): array|\WP_Error {
		$forbidden = new \WP_Error(
			'stonewright_finalizer_forbidden',
			__( 'Invalid block finalizer token.', 'stonewright' ),
			[ 'status' => 403 ]
		);
		$parts = explode( '.', $token, 2 );
		if ( 2 !== count( $parts ) || '' === $parts[0] || '' === $parts[1] ) {
			return $forbidden;
		}
		$expect = hash_hmac( 'sha256', $parts[0], wp_salt( 'auth' ) );
		if ( ! hash_equals( $expect, $parts[1] ) ) {
			return $forbidden;
		}
		$padded = strtr( $parts[0], '-_', '+/' );
		$pad    = strlen( $padded ) % 4;
		if ( 0 !== $pad ) {
			$padded .= str_repeat( '=', 4 - $pad );
		}
		$decoded = base64_decode( $padded, true );
		$data    = is_string( $decoded ) ? json_decode( $decoded, true ) : null;
		if ( ! is_array( $data ) || empty( $data['s'] ) || empty( $data['e'] ) ) {
			return $forbidden;
		}
		if ( (int) $data['e'] < time() ) {
			return $forbidden;
		}
		$uid = (int) ( $data['u'] ?? 0 );
		if ( $uid > 0 && $uid !== (int) get_current_user_id() ) {
			return $forbidden;
		}
		return [
			's' => (string) $data['s'],
			'u' => $uid,
			'e' => (int) $data['e'],
		];
	}

	/**
	 * @param array<string, mixed> $record
	 * @return array<string, mixed>
	 */
	public static function compact( array $record ): array {
		return [
			'id'                    => (string) ( $record['id'] ?? '' ),
			'post_id'               => (int) ( $record['post_id'] ?? 0 ),
			'status'                => (string) ( $record['status'] ?? '' ),
			'block_name'            => (string) ( $record['block_spec']['name'] ?? '' ),
			'action'                => (string) ( $record['action'] ?? 'insert' ),
			'expected_content_hash' => (string) ( $record['expected_content_hash'] ?? '' ),
			'created_at'            => (int) ( $record['created_at'] ?? 0 ),
		];
	}

	/**
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function normalize_spec( mixed $raw, bool $allow_raw_html ): array|\WP_Error {
		if ( is_string( $raw ) || ( is_array( $raw ) && self::is_raw_html_spec( $raw ) ) ) {
			if ( ! $allow_raw_html ) {
				return new \WP_Error(
					'stonewright_raw_html_refused',
					__( 'Top-level raw HTML is refused unless allow_raw_html is true. Queue {name, attributes, innerBlocks} instead.', 'stonewright' ),
					[ 'status' => 400 ]
				);
			}
			$html = is_string( $raw ) ? $raw : (string) ( $raw['innerHTML'] ?? $raw['html'] ?? '' );
			return [
				'name'        => 'core/html',
				'attributes'  => [ 'content' => $html ],
				'innerBlocks' => [],
			];
		}
		if ( ! is_array( $raw ) ) {
			return new \WP_Error(
				'stonewright_invalid_block_spec',
				__( 'A block spec object with name, attributes, and innerBlocks is required.', 'stonewright' ),
				[ 'status' => 400 ]
			);
		}

		$name = (string) ( $raw['name'] ?? $raw['blockName'] ?? '' );
		if ( '' === $name ) {
			return new \WP_Error(
				'stonewright_invalid_block_spec',
				__( 'A block spec requires a block name.', 'stonewright' ),
				[ 'status' => 400 ]
			);
		}

		$attributes = [];
		if ( isset( $raw['attributes'] ) && is_array( $raw['attributes'] ) ) {
			$attributes = $raw['attributes'];
		} elseif ( isset( $raw['attrs'] ) && is_array( $raw['attrs'] ) ) {
			$attributes = $raw['attrs'];
		}

		$children = [];
		$inner    = isset( $raw['innerBlocks'] ) && is_array( $raw['innerBlocks'] ) ? $raw['innerBlocks'] : [];
		foreach ( $inner as $child ) {
			$normalized = self::normalize_spec( $child, $allow_raw_html );
			if ( $normalized instanceof \WP_Error ) {
				return $normalized;
			}
			$children[] = $normalized;
		}

		return [
			'name'        => sanitize_text_field( $name ),
			'attributes'  => $attributes,
			'innerBlocks' => $children,
		];
	}

	/** @param array<string, mixed> $raw */
	private static function is_raw_html_spec( array $raw ): bool {
		$name = (string) ( $raw['name'] ?? $raw['blockName'] ?? '' );
		if ( '' !== $name ) {
			return false;
		}
		$html = (string) ( $raw['innerHTML'] ?? $raw['html'] ?? '' );
		return '' !== $html || str_contains( (string) wp_json_encode( $raw ), '<!-- wp:' );
	}

	/** @param array<string, mixed> $record */
	private static function is_open( array $record ): bool {
		return ! in_array( (string) ( $record['status'] ?? '' ), self::TERMINAL, true );
	}

	/**
	 * @return array{session_id:string,changes:array<string, array<string, mixed>>}
	 */
	private static function state(): array {
		$stored = get_option( self::OPTION, [] );
		if ( ! is_array( $stored ) ) {
			$stored = [];
		}
		$changes = isset( $stored['changes'] ) && is_array( $stored['changes'] ) ? $stored['changes'] : [];
		$session = isset( $stored['session_id'] ) ? (string) $stored['session_id'] : '';
		return [
			'session_id' => $session,
			'changes'    => $changes,
		];
	}

	/** @param array{session_id:string,changes:array<string, array<string, mixed>>} $state */
	private static function save( array $state ): void {
		update_option( self::OPTION, $state, false );
	}

	/** @param array{session_id:string,changes:array<string, array<string, mixed>>} $state */
	private static function session_id( array &$state ): string {
		if ( '' !== $state['session_id'] ) {
			return $state['session_id'];
		}
		$state['session_id'] = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : substr( hash( 'sha256', uniqid( 'session-', true ) ), 0, 36 );
		self::save( $state );
		return $state['session_id'];
	}
}
