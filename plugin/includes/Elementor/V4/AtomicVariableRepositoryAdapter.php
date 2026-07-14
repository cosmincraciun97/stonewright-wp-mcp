<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Elementor\V4;

/** Adapter for Elementor's Variables_Service; never writes guessed kit keys. */
final class AtomicVariableRepositoryAdapter {

	public function __construct( private readonly object $service ) {}

	public static function runtime(): self|\WP_Error {
		$service_class   = '\\Elementor\\Modules\\Variables\\Services\\Variables_Service';
		$repository_class = '\\Elementor\\Modules\\Variables\\Storage\\Variables_Repository';
		$batch_class     = '\\Elementor\\Modules\\Variables\\Services\\Batch_Operations\\Batch_Processor';
		if ( ! class_exists( $service_class ) || ! class_exists( $repository_class ) || ! class_exists( $batch_class ) || ! class_exists( '\\Elementor\\Plugin' ) ) {
			return new \WP_Error( 'stonewright_v4_variables_unavailable', 'The installed Elementor runtime does not expose the verified Variables API.' );
		}
		try {
			$kit = \Elementor\Plugin::$instance->kits_manager->get_active_kit();
			if ( ! $kit ) {
				return new \WP_Error( 'no_kit', 'No active Elementor kit found.' );
			}
			return new self( new $service_class( new $repository_class( $kit ), new $batch_class() ) );
		} catch ( \Throwable $error ) {
			return new \WP_Error( 'stonewright_v4_variables_unavailable', $error->getMessage() );
		}
	}

	/** @return array<string, array<string, mixed>>|\WP_Error */
	public function all(): array|\WP_Error {
		if ( ! method_exists( $this->service, 'get_variables_list' ) ) {
			return new \WP_Error( 'stonewright_v4_variables_api_mismatch', 'Variables runtime methods do not match the verified adapter.' );
		}
		try {
			$items = $this->service->get_variables_list();
			return is_array( $items ) ? $items : [];
		} catch ( \Throwable $error ) {
			return new \WP_Error( 'stonewright_v4_variables_read_failed', $error->getMessage() );
		}
	}

	/** @param array<string, mixed> $data */
	public function create( array $data ): array|\WP_Error {
		return $this->mutate( 'create', '', $data );
	}

	/** @param array<string, mixed> $data */
	public function update( string $id, array $data ): array|\WP_Error {
		return $this->mutate( 'update', $id, $data );
	}

	/** @param array<string, mixed> $data */
	private function mutate( string $operation, string $id, array $data ): array|\WP_Error {
		if ( ! method_exists( $this->service, $operation ) ) {
			return new \WP_Error( 'stonewright_v4_variables_api_mismatch', 'Variables mutation API does not match the verified adapter.' );
		}
		try {
			$result = 'create' === $operation ? $this->service->create( $data ) : $this->service->update( $id, $data );
			$variable = is_array( $result ) && isset( $result['variable'] ) && is_array( $result['variable'] ) ? $result['variable'] : null;
			if ( null === $variable ) {
				return new \WP_Error( 'stonewright_v4_variable_readback_failed', 'Elementor did not return the variable after mutation.' );
			}
			$all = $this->all();
			if ( is_wp_error( $all ) ) {
				return $all;
			}
			$target_id = (string) ( $variable['id'] ?? $id );
			if ( '' === $target_id || ! isset( $all[ $target_id ] ) ) {
				return new \WP_Error( 'stonewright_v4_variable_readback_failed', 'The variable was not found after mutation.' );
			}
			return array_merge( [ 'id' => $target_id ], $all[ $target_id ] );
		} catch ( \Throwable $error ) {
			return new \WP_Error( 'stonewright_v4_variable_write_failed', $error->getMessage() );
		}
	}
}
