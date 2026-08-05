<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Elementor\Write;

/**
 * One compact, machine-readable receipt for an Elementor transaction.
 */
final class ElementorWriteReceipt {

	/** @var array<string, mixed> */
	private array $data;

	/** @param list<string> $target_ids */
	public function __construct( int $post_id, string $architecture, array $target_ids, bool $dry_run, string $change_set_id = '' ) {
		$this->data = [
			'transaction_id'      => function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : substr( hash( 'sha256', uniqid( 'elementor-', true ) ), 0, 36 ),
			'change_set_id'       => sanitize_text_field( $change_set_id ),
			'post_id'             => $post_id,
			'architecture'        => sanitize_key( $architecture ),
			'target_ids'          => array_values( array_map( 'strval', $target_ids ) ),
			'dry_run'             => $dry_run,
			'lock'                => [ 'status' => 'not_acquired' ],
			'lock_status'         => 'not_acquired',
			'snapshot_id'         => '',
			'before_hash'         => '',
			'before_sha256'       => '',
			'planned_hash'        => '',
			'planned_sha256'      => '',
			'after_hash'          => '',
			'after_sha256'        => '',
			'readback_hash'       => '',
			'verification_status' => $dry_run ? 'planned' : 'pending',
			'rollback_attempted'  => false,
			'rollback_status'     => 'not_needed',
			'root_error_code'     => '',
			'root_error_path'     => '',
			'retryable'           => false,
			'retry_after_seconds' => 0,
			'recovery'            => [],
			'recovery_tool'       => '',
			'warnings'            => [],
			'audit_id'            => '',
			'audit_event_id'      => '',
		];
	}

	public function set( string $key, mixed $value ): self {
		$key = sanitize_key( $key );
		if ( '' === $key || str_contains( $key, 'token' ) || str_contains( $key, 'password' ) || str_contains( $key, 'secret' ) ) {
			return $this;
		}
		$this->data[ $key ] = is_array( $value ) ? self::safe_map( $value ) : ( is_scalar( $value ) || null === $value ? $value : '[' . gettype( $value ) . ']' );
		return $this;
	}

	public function set_hashes( string $before, string $planned, string $after = '', string $readback = '' ): self {
		$this->data['before_hash']   = self::hash_or_empty( $before );
		$this->data['before_sha256'] = $this->data['before_hash'];
		$this->data['planned_hash']  = self::hash_or_empty( $planned );
		$this->data['planned_sha256'] = $this->data['planned_hash'];
		$this->data['after_hash']    = self::hash_or_empty( $after );
		$this->data['after_sha256']  = $this->data['after_hash'];
		$this->data['readback_hash'] = self::hash_or_empty( $readback );
		return $this;
	}

	/** @param array<string, mixed> $lock */
	public function set_lock( array $lock ): self {
		$status = sanitize_key( (string) ( $lock['status'] ?? 'acquired' ) );
		$this->data['lock'] = [
			'status'       => $status,
			'owner'        => sanitize_key( (string) ( $lock['owner'] ?? '' ) ),
			'fingerprint'  => self::hash_or_empty( $lock['fingerprint'] ?? '' ),
			'age_seconds'  => max( 0, (int) ( $lock['age_seconds'] ?? 0 ) ),
			'retry_after'  => max( 0, (int) ( $lock['retry_after'] ?? 0 ) ),
			'expires_at'   => max( 0, (int) ( $lock['expires_at'] ?? 0 ) ),
		];
		$this->data['lock_status'] = $status;
		return $this;
	}

	public function set_snapshot( string $snapshot_id ): self {
		$this->data['snapshot_id'] = sanitize_text_field( $snapshot_id );
		return $this;
	}

	public function verified( string $status = 'verified' ): self {
		$this->data['verification_status'] = sanitize_key( $status );
		return $this;
	}

	/** @param array<string, mixed> $recovery */
	public function rollback( string $status, array $recovery = [] ): self {
		$this->data['rollback_attempted'] = true;
		$this->data['rollback_status'] = sanitize_key( $status );
		$this->data['recovery']        = self::safe_map( $recovery );
		return $this;
	}

	public function fail( \WP_Error $error, string $path = '' ): self {
		$data = $error->get_error_data();
		$data = is_array( $data ) ? $data : [];
		$this->data['root_error_code'] = sanitize_key( (string) $error->get_error_code() );
		$this->data['root_error_path'] = mb_substr( sanitize_text_field( $path !== '' ? $path : (string) ( $data['root_error_path'] ?? '' ) ), 0, 255 );
		$this->data['retryable'] = ! empty( $data['retryable'] );
		$this->data['retry_after_seconds'] = max( 0, min( 86400, (int) ( $data['retry_after_seconds'] ?? $data['retry_after'] ?? 0 ) ) );
		$this->data['verification_status'] = 'failed' === (string) ( $data['verification_status'] ?? '' ) ? 'failed' : $this->data['verification_status'];
		$this->data['rollback_status'] = sanitize_key( (string) ( $data['rollback_status'] ?? $this->data['rollback_status'] ) );
		if ( isset( $data['recovery'] ) && is_array( $data['recovery'] ) ) {
			$this->data['recovery'] = self::safe_map( $data['recovery'] );
		}
		return $this;
	}

	/** @return array<string, mixed> */
	public function to_array(): array {
		return $this->data;
	}

	private static function hash_or_empty( mixed $value ): string {
		$value = is_scalar( $value ) ? strtolower( trim( (string) $value ) ) : '';
		return 1 === preg_match( '/^[a-f0-9]{64}$/', $value ) ? $value : '';
	}

	/** @param array<string, mixed> $value @return array<string, mixed> */
	private static function safe_map( array $value ): array {
		$out = [];
		foreach ( $value as $key => $item ) {
			$key = sanitize_key( (string) $key );
			if ( '' === $key || str_contains( $key, 'token' ) || str_contains( $key, 'password' ) || str_contains( $key, 'secret' ) ) {
				continue;
			}
			if ( is_scalar( $item ) || null === $item ) {
				$out[ $key ] = is_string( $item ) ? mb_substr( sanitize_text_field( $item ), 0, 255 ) : $item;
			}
		}
		return $out;
	}
}
