<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\ElementorV3;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Elementor\Schema\ResponsiveScope;
use Stonewright\WpMcp\Elementor\Schema\SettingsValidator;
use Stonewright\WpMcp\Security\Permissions;
use Stonewright\WpMcp\Support\ElementorData;

/**
 * Bounded, read-only Elementor document size and complexity metrics.
 *
 * @stonewright-status stable
 */
final class PerformanceAudit extends AbilityKernel {

	private const NESTED_WIDGET_TYPES = [
		'nested-accordion',
		'nested-tabs',
		'nested-carousel',
		'loop-carousel',
	];

	private const INVALID_SAMPLE_LIMIT = 20;

	public function name(): string {
		return 'stonewright/elementor-performance-audit';
	}

	public function label(): string {
		return __( 'Elementor performance audit', 'stonewright' );
	}

	public function description(): string {
		return __( 'Reports bounded Elementor document, settings, backup, and revision size metrics without exposing content.', 'stonewright' );
	}

	public function category(): string {
		return 'elementor';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => [ 'post_id' ],
			'properties'           => [
				'post_id' => [ 'type' => 'integer', 'minimum' => 1 ],
			],
		];
	}

	public function output_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'post_id'                   => [ 'type' => 'integer' ],
				'elementor_data_bytes'      => [ 'type' => 'integer' ],
				'node_count'                => [ 'type' => 'integer' ],
				'widget_count'              => [ 'type' => 'integer' ],
				'container_count'           => [ 'type' => 'integer' ],
				'max_depth'                 => [ 'type' => 'integer' ],
				'architecture'              => [ 'type' => 'object' ],
				'e_paragraph_count'         => [ 'type' => 'integer' ],
				'nested_widget_counts'      => [ 'type' => 'object' ],
				'settings_key_count'        => [ 'type' => 'integer' ],
				'responsive_settings_count' => [ 'type' => 'integer' ],
				'empty_setting_count'       => [ 'type' => 'integer' ],
				'invalid_setting_sample'    => [ 'type' => 'object' ],
				'stonewright_backups'       => [ 'type' => 'object' ],
				'revision_count'            => [ 'type' => 'integer' ],
				'largest_meta_keys'         => [ 'type' => 'array' ],
				'warnings'                  => [ 'type' => 'array' ],
			],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::edit_post( (int) ( $args['post_id'] ?? 0 ) );
	}

	public function execute( array $args ): array|\WP_Error {
		$post_id = (int) ( $args['post_id'] ?? 0 );
		if ( ! get_post( $post_id ) ) {
			return $this->error( 'not_found', __( 'Post not found.', 'stonewright' ) );
		}

		$raw  = get_post_meta( $post_id, '_elementor_data', true );
		$raw  = is_string( $raw ) ? $raw : '';
		$tree = ElementorData::read( $post_id );

		$nested = [];
		foreach ( self::NESTED_WIDGET_TYPES as $type ) {
			$nested[ $type ] = 0;
		}

		$metrics = [
			'node_count'                => 0,
			'widget_count'              => 0,
			'container_count'           => 0,
			'max_depth'                 => 0,
			'v3_nodes'                  => 0,
			'v4_atomic_nodes'           => 0,
			'e_paragraph_count'         => 0,
			'nested_widget_counts'      => $nested,
			'settings_key_count'        => 0,
			'responsive_settings_count' => 0,
			'empty_setting_count'       => 0,
			'invalid_keys'              => [],
			'invalid_count'             => 0,
		];
		$this->walk( $tree, 1, $metrics );

		$backups_meta = get_post_meta( $post_id, '_stonewright_backups', true );
		$backups      = is_array( $backups_meta ) ? $backups_meta : [];
		$backup_bytes = strlen( (string) maybe_serialize( $backups_meta ) );

		$revisions = wp_get_post_revisions( $post_id, [ 'fields' => 'ids' ] );
		$revision_count = is_countable( $revisions ) ? count( $revisions ) : 0;

		$invalid_keys = array_values( array_unique( $metrics['invalid_keys'] ) );
		$invalid_keys = array_slice( $invalid_keys, 0, self::INVALID_SAMPLE_LIMIT );

		$bytes    = strlen( $raw );
		$warnings = [];
		if ( $metrics['v3_nodes'] > 0 && $metrics['v4_atomic_nodes'] > 0 ) {
			$warnings[] = 'mixed_architecture';
		}
		if ( $bytes >= 512 * 1024 ) {
			$warnings[] = 'large_document';
		}
		if ( $metrics['node_count'] >= 250 ) {
			$warnings[] = 'high_node_count';
		}
		if ( $metrics['e_paragraph_count'] >= 24 ) {
			$warnings[] = 'excessive_e_paragraph_nodes';
		}
		if ( $backup_bytes >= 1024 * 1024 ) {
			$warnings[] = 'large_backup_meta';
		}
		if ( $revision_count >= 50 ) {
			$warnings[] = 'high_revision_count';
		}

		return [
			'post_id'                   => $post_id,
			'elementor_data_bytes'      => $bytes,
			'node_count'                => $metrics['node_count'],
			'widget_count'              => $metrics['widget_count'],
			'container_count'           => $metrics['container_count'],
			'max_depth'                 => $metrics['max_depth'],
			'architecture'              => [
				'v3_nodes'        => $metrics['v3_nodes'],
				'v4_atomic_nodes' => $metrics['v4_atomic_nodes'],
				'mixed'           => $metrics['v3_nodes'] > 0 && $metrics['v4_atomic_nodes'] > 0,
			],
			'e_paragraph_count'         => $metrics['e_paragraph_count'],
			'nested_widget_counts'      => $metrics['nested_widget_counts'],
			'settings_key_count'        => $metrics['settings_key_count'],
			'responsive_settings_count' => $metrics['responsive_settings_count'],
			'empty_setting_count'       => $metrics['empty_setting_count'],
			'invalid_setting_sample'    => [
				'count'       => $metrics['invalid_count'],
				'sample_keys' => $invalid_keys,
			],
			'stonewright_backups'       => [
				'snapshot_count'   => count( $backups ),
				'serialized_bytes' => $backup_bytes,
			],
			'revision_count'            => $revision_count,
			'largest_meta_keys'         => $this->largest_meta_keys( $post_id ),
			'warnings'                  => $warnings,
		];
	}

	/**
	 * @param array<int, mixed>            $nodes
	 * @param array<string, mixed>         $metrics
	 */
	private function walk( array $nodes, int $depth, array &$metrics ): void {
		if ( [] === $nodes ) {
			return;
		}
		$metrics['max_depth'] = max( (int) $metrics['max_depth'], $depth );

		foreach ( $nodes as $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}

			++$metrics['node_count'];
			$el_type     = (string) ( $node['elType'] ?? '' );
			$widget_type = (string) ( $node['widgetType'] ?? '' );
			$atomic_type = 'widget' === $el_type ? $widget_type : $el_type;
			$is_atomic   = str_starts_with( $atomic_type, 'e-' );
			++$metrics[ $is_atomic ? 'v4_atomic_nodes' : 'v3_nodes' ];

			if ( in_array( $el_type, [ 'container', 'section', 'column' ], true ) ) {
				++$metrics['container_count'];
			}
			if ( 'widget' === $el_type ) {
				++$metrics['widget_count'];
			}
			if ( 'e-paragraph' === $widget_type ) {
				++$metrics['e_paragraph_count'];
			}
			if ( isset( $metrics['nested_widget_counts'][ $widget_type ] ) ) {
				++$metrics['nested_widget_counts'][ $widget_type ];
			}

			$settings = isset( $node['settings'] ) && is_array( $node['settings'] ) ? $node['settings'] : [];
			$this->count_settings( $settings, $metrics );

			if ( ! $is_atomic ) {
				$this->collect_invalid_keys( $el_type, $widget_type, $settings, $metrics );
			}

			$children = isset( $node['elements'] ) && is_array( $node['elements'] ) ? $node['elements'] : [];
			$this->walk( $children, $depth + 1, $metrics );
		}
	}

	/**
	 * @param array<string, mixed> $settings
	 * @param array<string, mixed> $metrics
	 */
	private function count_settings( array $settings, array &$metrics ): void {
		foreach ( $settings as $key => $value ) {
			++$metrics['settings_key_count'];
			$key = (string) $key;
			if ( $this->is_responsive_key( $key ) ) {
				++$metrics['responsive_settings_count'];
			}
			if ( $this->is_empty_setting( $value ) ) {
				++$metrics['empty_setting_count'];
			}
		}
	}

	private function is_responsive_key( string $key ): bool {
		foreach ( ResponsiveScope::DEFAULT_SUFFIXES as $suffix ) {
			if ( '' !== $suffix && str_ends_with( $key, $suffix ) ) {
				return true;
			}
		}
		return in_array( $key, ResponsiveScope::visibility_controls(), true );
	}

	private function is_empty_setting( mixed $value ): bool {
		return null === $value
			|| '' === $value
			|| [] === $value;
	}

	/**
	 * @param array<string, mixed> $settings
	 * @param array<string, mixed> $metrics
	 */
	private function collect_invalid_keys( string $el_type, string $widget_type, array $settings, array &$metrics ): void {
		$validated = null;
		if ( in_array( $el_type, [ 'container', 'section', 'column' ], true ) ) {
			$validated = SettingsValidator::validate_container( $settings, $el_type, false );
		} elseif ( 'widget' === $el_type && '' !== $widget_type && 'html' !== $widget_type ) {
			$validated = SettingsValidator::validate( $widget_type, $settings, false, false );
		}
		if ( ! $validated instanceof \WP_Error ) {
			return;
		}

		$data       = (array) $validated->get_error_data();
		$violations = isset( $data['violations'] ) && is_array( $data['violations'] )
			? $data['violations']
			: [ [ 'path' => 'settings' ] ];

		foreach ( $violations as $violation ) {
			++$metrics['invalid_count'];
			$violation = is_array( $violation ) ? $violation : [];
			$key       = $this->setting_key_from_path( (string) ( $violation['path'] ?? 'settings' ) );
			if ( '' === $key ) {
				continue;
			}
			if ( count( $metrics['invalid_keys'] ) < self::INVALID_SAMPLE_LIMIT && ! in_array( $key, $metrics['invalid_keys'], true ) ) {
				$metrics['invalid_keys'][] = $key;
			}
		}
	}

	private function setting_key_from_path( string $path ): string {
		$path  = (string) preg_replace( '/^settings\.?/', '', $path );
		$parts = explode( '.', $path );
		return (string) ( $parts[0] ?? '' );
	}

	/**
	 * @return list<array{key: string, serialized_bytes: int}>
	 */
	private function largest_meta_keys( int $post_id ): array {
		$all = get_post_meta( $post_id );
		if ( ! is_array( $all ) ) {
			return [];
		}

		$rows = [];
		foreach ( $all as $key => $value ) {
			$payload = $value;
			if ( is_array( $value ) && 1 === count( $value ) && array_key_exists( 0, $value ) ) {
				$payload = $value[0];
			}
			$rows[] = [
				'key'              => (string) $key,
				'serialized_bytes' => strlen( (string) maybe_serialize( $payload ) ),
			];
		}

		usort(
			$rows,
			static fn( array $a, array $b ): int => $b['serialized_bytes'] <=> $a['serialized_bytes']
		);

		return array_slice( $rows, 0, 10 );
	}
}
