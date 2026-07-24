<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\ElementorV3;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Elementor\Schema\SettingsValidator;
use Stonewright\WpMcp\Elementor\V4\AtomicTreeInspector;
use Stonewright\WpMcp\Security\Permissions;
use Stonewright\WpMcp\Support\ElementorData;

/**
 * Bounded diagnostics for one Elementor document.
 *
 * @stonewright-status stable
 */
final class DocumentHealth extends AbilityKernel {

	public function name(): string {
		return 'stonewright/elementor-document-health';
	}

	public function label(): string {
		return __( 'Elementor document health', 'stonewright' );
	}

	public function description(): string {
		return __( 'Reports bounded Elementor document size, architecture, node counts, atomic paragraphs, and invalid settings without returning content.', 'stonewright' );
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
				'post_id'    => [ 'type' => 'integer', 'minimum' => 1 ],
				'max_issues' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 20 ],
			],
		];
	}

	public function output_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => true,
			'properties'           => [
				'post_id'          => [ 'type' => 'integer' ],
				'architecture'      => [ 'type' => 'string' ],
				'serialized_bytes'  => [ 'type' => 'integer' ],
				'serialized_kib'    => [ 'type' => 'number' ],
				'counts'            => [ 'type' => 'object' ],
				'widget_counts'     => [ 'type' => 'object' ],
				'e_paragraph_count' => [ 'type' => 'integer' ],
				'e_paragraph_ids'   => [ 'type' => 'array' ],
				'issues'            => [ 'type' => 'array' ],
				'issues_truncated'  => [ 'type' => 'boolean' ],
				'warnings'          => [ 'type' => 'array' ],
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

		$max_issues = max( 1, min( 100, (int) ( $args['max_issues'] ?? 20 ) ) );
		$raw        = get_post_meta( $post_id, '_elementor_data', true );
		$raw        = is_string( $raw ) ? $raw : '';
		$tree       = ElementorData::read( $post_id );
		$inspection = AtomicTreeInspector::inspect( $tree );
		$counts     = [
			'total'      => 0,
			'containers' => 0,
			'widgets'    => 0,
			'v3'         => 0,
			'v4'         => 0,
		];
		$widget_counts    = [];
		$paragraph_ids    = [];
		$paragraph_count  = 0;
		$issues           = [];
		$issues_truncated = false;

		$this->walk(
			$tree,
			$counts,
			$widget_counts,
			$paragraph_ids,
			$paragraph_count,
			$issues,
			$issues_truncated,
			$max_issues
		);
		ksort( $widget_counts );

		$warnings = [];
		if ( strlen( $raw ) >= 512 * 1024 ) {
			$warnings[] = 'large_document';
		}
		if ( 'mixed' === (string) ( $inspection['architecture'] ?? '' ) ) {
			$warnings[] = 'mixed_architecture';
		}
		if ( $paragraph_count >= 24 ) {
			$warnings[] = 'excessive_e_paragraph_nodes';
		}
		if ( [] !== $issues ) {
			$warnings[] = 'invalid_settings';
		}

		return [
			'post_id'           => $post_id,
			'architecture'       => (string) ( $inspection['architecture'] ?? 'empty' ),
			'serialized_bytes'   => strlen( $raw ),
			'serialized_kib'     => round( strlen( $raw ) / 1024, 2 ),
			'counts'             => $counts,
			'widget_counts'      => $widget_counts,
			'e_paragraph_count'  => $paragraph_count,
			'e_paragraph_ids'    => $paragraph_ids,
			'e_paragraph_ids_truncated' => $paragraph_count > count( $paragraph_ids ),
			'issues'             => $issues,
			'issues_truncated'   => $issues_truncated,
			'warnings'           => $warnings,
		];
	}

	/**
	 * @param array<int, mixed>       $nodes
	 * @param array<string, int>      $counts
	 * @param array<string, int>      $widget_counts
	 * @param list<string>            $paragraph_ids
	 * @param list<array<string,mixed>> $issues
	 */
	private function walk(
		array $nodes,
		array &$counts,
		array &$widget_counts,
		array &$paragraph_ids,
		int &$paragraph_count,
		array &$issues,
		bool &$issues_truncated,
		int $max_issues
	): void {
		foreach ( $nodes as $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}

			++$counts['total'];
			$element_id = isset( $node['id'] ) && is_scalar( $node['id'] ) ? (string) $node['id'] : '';
			$el_type    = (string) ( $node['elType'] ?? '' );
			$widget_type = (string) ( $node['widgetType'] ?? '' );
			$atomic_type = 'widget' === $el_type ? $widget_type : $el_type;
			$is_atomic   = str_starts_with( $atomic_type, 'e-' );
			++$counts[ $is_atomic ? 'v4' : 'v3' ];

			if ( in_array( $el_type, [ 'container', 'section', 'column' ], true ) ) {
				++$counts['containers'];
			}
			if ( 'widget' === $el_type ) {
				++$counts['widgets'];
				$widget_counts[ $widget_type ] = ( $widget_counts[ $widget_type ] ?? 0 ) + 1;
			}
			if ( 'e-paragraph' === $widget_type ) {
				++$paragraph_count;
				if ( count( $paragraph_ids ) < 100 ) {
					$paragraph_ids[] = $element_id;
				}
			}

			if ( ! $is_atomic ) {
				$settings = isset( $node['settings'] ) && is_array( $node['settings'] ) ? $node['settings'] : [];
				$validated = null;
				if ( in_array( $el_type, [ 'container', 'section', 'column' ], true ) ) {
					$validated = SettingsValidator::validate_container( $settings, $el_type, false );
				} elseif ( 'widget' === $el_type && '' !== $widget_type && 'html' !== $widget_type ) {
					$validated = SettingsValidator::validate( $widget_type, $settings, false, false );
				}
				if ( $validated instanceof \WP_Error ) {
					$this->collect_issues( $validated, $element_id, $widget_type, $issues, $issues_truncated, $max_issues );
				}
			}

			$children = isset( $node['elements'] ) && is_array( $node['elements'] ) ? $node['elements'] : [];
			$this->walk( $children, $counts, $widget_counts, $paragraph_ids, $paragraph_count, $issues, $issues_truncated, $max_issues );
		}
	}

	/**
	 * @param list<array<string,mixed>> $issues
	 */
	private function collect_issues(
		\WP_Error $error,
		string $element_id,
		string $widget_type,
		array &$issues,
		bool &$issues_truncated,
		int $max_issues
	): void {
		$data       = (array) $error->get_error_data();
		$violations = isset( $data['violations'] ) && is_array( $data['violations'] )
			? $data['violations']
			: [
				[
					'path'     => 'settings',
					'code'     => $error->get_error_code(),
					'expected' => 'a live Elementor widget schema',
					'got_type' => 'unknown',
				],
			];

		foreach ( $violations as $violation ) {
			if ( count( $issues ) >= $max_issues ) {
				$issues_truncated = true;
				return;
			}
			$violation = is_array( $violation ) ? $violation : [];
			$issues[]  = [
				'element_id' => $element_id,
				'widget_type' => $widget_type,
				'path'       => (string) ( $violation['path'] ?? 'settings' ),
				'code'       => (string) ( $violation['code'] ?? $error->get_error_code() ),
				'expected'   => (string) ( $violation['expected'] ?? 'a live Elementor widget schema' ),
				'got_type'   => (string) ( $violation['got_type'] ?? 'unknown' ),
			];
		}
	}
}
