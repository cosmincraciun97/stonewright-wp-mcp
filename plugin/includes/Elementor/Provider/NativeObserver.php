<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Elementor\Provider;

use Stonewright\WpMcp\Elementor\V4\V4FeatureGate;
use Stonewright\WpMcp\Elementor\Write\TreeHasher;
use Stonewright\WpMcp\Security\Adapters\PostAdapter;
use Stonewright\WpMcp\Security\AuditLog;
use Stonewright\WpMcp\Security\Backup;
use Stonewright\WpMcp\Support\ElementorData;
use Stonewright\WpMcp\Support\Logger;

/**
 * Watches Elementor's own write abilities when they run outside stonewright-elementor-native-execute,
 * for example through Elementor's own MCP server.
 *
 * It is observation only. It hooks core's `wp_before_execute_ability` (name, input, ability) and
 * `wp_after_execute_ability` (name, input, result, ability), which WP_Ability::execute() fires after the
 * input and permission checks, just before and just after the ability's callback; the after action fires
 * only when the callback and the output check succeeded
 * (https://developer.wordpress.org/reference/classes/wp_ability/execute/). Before the call it takes a
 * snapshot of the affected post or kit when the input names one (the active kit for a kit-level ability).
 * After the call it records a change-ledger row, when the document changed, and an audit row. It never
 * blocks, refuses, rewrites or retries the call, never throws into it, and opens no Rescue frame
 * (the after action does not fire when the call fails, so a frame could never be closed).
 *
 * A call that comes through stonewright-elementor-native-execute is recorded by that tool, so it carries a
 * mark ({@see self::mark_native_execute()}) and is skipped here.
 */
final class NativeObserver {

	public const SOURCE = 'observed_native';

	/** Abilities that write the active kit when the input names no post. */
	private const KIT_ABILITIES = [ 'manage-default-styles', 'manage-classes', 'reorder-classes', 'manage-global-variable' ];

	/** Input keys that name the document an ability works on. */
	private const POST_KEYS = [ 'post_id', 'postId', 'document_id', 'documentId', 'page_id', 'pageId' ];

	private const MAX_PENDING = 20;

	/** @var int Calls of stonewright-elementor-native-execute in progress. */
	private static int $inside = 0;

	/** @var list<array{key:string,ability:string,post_id:int,snapshot_id:string,before:array<string,mixed>|null,before_hash:string}> Calls seen before and not yet closed. */
	private static array $pending = [];

	/** @var (callable():int)|null */
	private static $kit_resolver = null;

	public static function register(): void {
		add_action( 'wp_before_execute_ability', [ self::class, 'before' ], 10, 3 );
		add_action( 'wp_after_execute_ability', [ self::class, 'after' ], 10, 4 );
	}

	/** Whether the current call comes through stonewright-elementor-native-execute, which records itself. */
	public static function inside_native_execute(): bool {
		return self::$inside > 0;
	}

	/**
	 * Runs a callback marked as coming through stonewright-elementor-native-execute.
	 *
	 * @template T
	 * @param callable():T $callback
	 * @return T
	 */
	public static function mark_native_execute( callable $callback ): mixed {
		++self::$inside;
		try {
			return $callback();
		} finally {
			--self::$inside;
		}
	}

	/**
	 * @param mixed $name    Ability name.
	 * @param mixed $input   Ability input.
	 * @param mixed $ability The ability object, passed by newer cores.
	 */
	public static function before( mixed $name = null, mixed $input = null, mixed $ability = null ): void {
		try {
			if ( ! self::watched( $name, $ability ) ) {
				return;
			}
			/** @var string $name */
			$input   = is_array( $input ) ? $input : [];
			$post_id = self::target( $name, $input );
			$entry   = [
				'key'         => self::key( $name, $input ),
				'ability'     => $name,
				'post_id'     => $post_id,
				'snapshot_id' => '',
				'before'      => null,
				'before_hash' => '',
			];
			if ( $post_id > 0 ) {
				// The snapshot arms nothing: no rescue frame is open around a call Stonewright does not run.
				$entry['snapshot_id'] = Backup::snapshot_post( $post_id, [], false );
				$entry['before']      = PostAdapter::image( $post_id );
				$entry['before_hash'] = TreeHasher::hash( ElementorData::read( $post_id ) );
			}
			self::$pending[] = $entry;
			if ( count( self::$pending ) > self::MAX_PENDING ) {
				// Calls that failed never reach the after action; keep the list from growing.
				self::$pending = array_slice( self::$pending, -self::MAX_PENDING );
			}
		} catch ( \Throwable $failure ) {
			Logger::warning( 'native_observer_before_failed', [ 'error' => $failure::class ] );
		}
	}

	/**
	 * @param mixed $name    Ability name.
	 * @param mixed $input   Ability input.
	 * @param mixed $result  What the ability returned.
	 * @param mixed $ability The ability object, passed by newer cores.
	 */
	public static function after( mixed $name = null, mixed $input = null, mixed $result = null, mixed $ability = null ): void {
		try {
			if ( ! self::watched( $name, $ability ) ) {
				return;
			}
			/** @var string $name */
			$input = is_array( $input ) ? $input : [];
			$entry = self::take( self::key( $name, $input ) );
			if ( null === $entry ) {
				return;
			}
			$failed  = $result instanceof \WP_Error || ( is_array( $result ) && false === ( $result['success'] ?? $result['ok'] ?? true ) );
			$post_id = $entry['post_id'];
			$created = false;
			if ( $post_id < 1 && is_array( $result ) && isset( $result['post_id'] ) && is_numeric( $result['post_id'] ) && (int) $result['post_id'] > 0 && null !== get_post( (int) $result['post_id'] ) ) {
				$post_id = (int) $result['post_id'];
				$created = true;
			}
			$after_image = $post_id > 0 ? PostAdapter::capture_after( $post_id ) : null;
			$after_hash  = $post_id > 0 ? TreeHasher::hash( ElementorData::read( $post_id ) ) : '';
			$changed     = $created || ( $post_id > 0 && ( $after_hash !== $entry['before_hash'] || self::differs( $entry['before'], $after_image ) ) );
			$change_id   = '';
			if ( $changed && $post_id > 0 && ! $failed ) {
				$change_id = PostAdapter::record_observed( $name, $post_id, $created ? null : $entry['before'], $after_image );
			}
			self::audit( $name, $input, $entry, $post_id, $failed ? 'error' : 'ok', $failed ? 'failed' : ( $changed ? 'observed' : 'unchanged' ), $change_id, $after_hash );
		} catch ( \Throwable $failure ) {
			Logger::warning( 'native_observer_after_failed', [ 'error' => $failure::class ] );
		}
	}

	/** @internal Tests only. */
	public static function reset_for_tests(): void {
		self::$inside       = 0;
		self::$pending      = [];
		self::$kit_resolver = null;
	}

	/**
	 * @internal Tests only: names the active kit.
	 *
	 * @param callable():int $resolver
	 */
	public static function use_kit_resolver_for_tests( callable $resolver ): void {
		self::$kit_resolver = $resolver;
	}

	/** Whether this call is an Elementor write that Stonewright did not run itself. */
	private static function watched( mixed $name, mixed $ability ): bool {
		if ( ! is_string( $name ) || ! str_starts_with( $name, 'elementor/' ) || self::inside_native_execute() ) {
			return false;
		}
		return self::is_write( $name, $ability );
	}

	private static function is_write( string $name, mixed $ability ): bool {
		$object = is_object( $ability ) ? $ability : ( function_exists( 'wp_get_ability' ) ? wp_get_ability( $name ) : null );
		$meta   = is_object( $object ) && method_exists( $object, 'get_meta' ) ? $object->get_meta() : [];
		$flags  = is_array( $meta ) && is_array( $meta['annotations'] ?? null ) ? $meta['annotations'] : [];
		if ( array_key_exists( 'readonly', $flags ) ) {
			return true !== $flags['readonly'];
		}
		$contract = NativeContracts::for_ability( $name );
		if ( is_array( $contract ) && isset( $contract['access'] ) ) {
			return 'write' === $contract['access'];
		}
		$slug = substr( $name, strlen( 'elementor/' ) );
		return 1 !== preg_match( '/^(get|list|read|describe|search)-|-(guide|resource|best-practices)$/', $slug );
	}

	/** The post or kit a call writes, when the input says: the document it names, else the active kit for a kit-level ability. */
	private static function target( string $name, array $input ): int {
		foreach ( self::POST_KEYS as $key ) {
			if ( isset( $input[ $key ] ) && is_numeric( $input[ $key ] ) && (int) $input[ $key ] > 0 && null !== get_post( (int) $input[ $key ] ) ) {
				return (int) $input[ $key ];
			}
		}
		if ( in_array( substr( $name, strlen( 'elementor/' ) ), self::KIT_ABILITIES, true ) ) {
			$kit = null !== self::$kit_resolver ? (int) ( self::$kit_resolver )() : V4FeatureGate::active_kit_id();
			return $kit > 0 && null !== get_post( $kit ) ? $kit : 0;
		}
		return 0;
	}

	private static function key( string $name, array $input ): string {
		return $name . '|' . hash( 'sha256', (string) wp_json_encode( $input ) );
	}

	/**
	 * The oldest-first list holds calls that never closed; the call that did is the newest with its key,
	 * and everything after it is dropped with it.
	 *
	 * @return array{key:string,ability:string,post_id:int,snapshot_id:string,before:array<string,mixed>|null,before_hash:string}|null
	 */
	private static function take( string $key ): ?array {
		for ( $index = count( self::$pending ) - 1; $index >= 0; --$index ) {
			if ( self::$pending[ $index ]['key'] === $key ) {
				$entry         = self::$pending[ $index ];
				self::$pending = array_slice( self::$pending, 0, $index );
				return $entry;
			}
		}
		return null;
	}

	/**
	 * @param array<string,mixed>|null $before
	 * @param array<string,mixed>|null $after
	 */
	private static function differs( ?array $before, ?array $after ): bool {
		return wp_json_encode( $before ) !== wp_json_encode( $after );
	}

	/**
	 * One audit row for the observed call. It names the ability, the document, the outcome and the hashes, not the content of the input.
	 *
	 * @param array<string,mixed>                                                                                  $input
	 * @param array{key:string,ability:string,post_id:int,snapshot_id:string,before:array<string,mixed>|null,before_hash:string} $entry
	 */
	private static function audit( string $name, array $input, array $entry, int $post_id, string $status, string $outcome, string $change_id, string $after_hash ): void {
		$meta = [
			'operation_kind'   => 'write',
			'operation_class'  => self::SOURCE,
			'execution_status' => $outcome,
			'source'           => self::SOURCE,
			'resource_type'    => $post_id > 0 ? 'post' : 'elementor_ability',
			'observed_native'  => true,
		];
		if ( $post_id > 0 ) {
			$meta['target_id']     = (string) $post_id;
			$meta['resource_ref']  = 'post:' . $post_id;
			$meta['before_sha256'] = $entry['before_hash'];
			$meta['after_sha256']  = $after_hash;
		}
		if ( '' !== $entry['snapshot_id'] ) {
			$meta['snapshot_id'] = $entry['snapshot_id'];
		}
		if ( '' !== $change_id ) {
			$meta['change_set_id'] = $change_id;
		}
		AuditLog::record(
			$name,
			[
				'observed_native' => true,
				'post_id'         => $post_id,
				'input_keys'      => array_slice( array_map( 'strval', array_keys( $input ) ), 0, 20 ),
				'input_bytes'     => strlen( (string) wp_json_encode( $input ) ),
				'_meta'           => $meta,
			],
			$status
		);
	}
}
