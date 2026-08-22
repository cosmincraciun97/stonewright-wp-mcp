<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Security;

/**
 * Thrown by php-execute runtime write guards so a blocked mutation cannot continue.
 */
final class GuardedRuntimeWriteException extends \RuntimeException {

	/** @param array<string, mixed> $data */
	public function __construct(
		private string $wp_error_code,
		string $message,
		private array $data = []
	) {
		parent::__construct( $message );
	}

	public function wp_error_code(): string {
		return $this->wp_error_code;
	}

	/** @return array<string, mixed> */
	public function error_data(): array {
		return $this->data;
	}

	public function to_wp_error(): \WP_Error {
		return new \WP_Error( $this->wp_error_code, $this->getMessage(), $this->data );
	}
}
