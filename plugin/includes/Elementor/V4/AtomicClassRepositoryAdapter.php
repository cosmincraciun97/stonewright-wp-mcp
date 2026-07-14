<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Elementor\V4;

/** Adapter for Elementor's public Global_Classes_Repository runtime API. */
final class AtomicClassRepositoryAdapter {

	public function __construct( private readonly object $repository ) {}

	public static function runtime(): self|\WP_Error {
		$class = '\\Elementor\\Modules\\GlobalClasses\\Global_Classes_Repository';
		if ( ! class_exists( $class ) || ! is_callable( [ $class, 'make' ] ) ) {
			return new \WP_Error( 'stonewright_v4_classes_unavailable', 'The installed Elementor runtime does not expose Global_Classes_Repository.' );
		}
		try {
			$repository = call_user_func( [ $class, 'make' ] );
			return is_object( $repository ) ? new self( $repository ) : new \WP_Error( 'stonewright_v4_classes_unavailable', 'Global classes repository factory returned no object.' );
		} catch ( \Throwable $error ) {
			return new \WP_Error( 'stonewright_v4_classes_unavailable', $error->getMessage() );
		}
	}

	/** @return array<string, array<string, mixed>>|\WP_Error */
	public function all(): array|\WP_Error {
		if ( ! method_exists( $this->repository, 'all_labels' ) || ! method_exists( $this->repository, 'get_by_ids' ) ) {
			return new \WP_Error( 'stonewright_v4_classes_api_mismatch', 'Global classes runtime methods do not match the verified adapter.' );
		}
		try {
			$labels = $this->repository->all_labels();
			$items  = $this->repository->get_by_ids( array_keys( $labels ) );
			return is_array( $items ) ? $items : [];
		} catch ( \Throwable $error ) {
			return new \WP_Error( 'stonewright_v4_classes_read_failed', $error->getMessage() );
		}
	}

	/** @param array<string, mixed> $item */
	public function create( array $item ): array|\WP_Error {
		$id         = (string) ( $item['id'] ?? '' );
		$validation = AtomicStyleValidator::validate( $item );
		if ( is_wp_error( $validation ) ) {
			return $validation;
		}
		try {
			$order   = $this->repository->get_order();
			$order[] = $id;
			$this->repository->apply_changes( [ $id => $item ], [ 'added' => [ $id ], 'deleted' => [], 'modified' => [], 'order' => true ], $order );
			$readback = $this->repository->get( $id );
			return is_array( $readback ) ? $readback : new \WP_Error( 'stonewright_v4_class_readback_failed', 'The class was not returned after creation.' );
		} catch ( \Throwable $error ) {
			return new \WP_Error( 'stonewright_v4_class_write_failed', $error->getMessage() );
		}
	}

	/** @param array<string, mixed> $item */
	public function update( string $id, array $item ): array|\WP_Error {
		$validation = AtomicStyleValidator::validate( $item );
		if ( is_wp_error( $validation ) ) {
			return $validation;
		}
		try {
			if ( null === $this->repository->get( $id ) ) {
				return new \WP_Error( 'class_not_found', 'Class not found.' );
			}
			$order = $this->repository->get_order();
			$this->repository->apply_changes( [ $id => $item ], [ 'added' => [], 'deleted' => [], 'modified' => [ $id ], 'order' => false ], $order );
			$readback = $this->repository->get( $id );
			return is_array( $readback ) ? $readback : new \WP_Error( 'stonewright_v4_class_readback_failed', 'The class was not returned after update.' );
		} catch ( \Throwable $error ) {
			return new \WP_Error( 'stonewright_v4_class_write_failed', $error->getMessage() );
		}
	}
}
