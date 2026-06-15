<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\ElementorV3;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Abilities\Common\ConfirmationGuard;
use Stonewright\WpMcp\DesignSpec\Validator;
use Stonewright\WpMcp\Elementor\Renderer;
use Stonewright\WpMcp\Security\Backup;
use Stonewright\WpMcp\Security\Permissions;
use Stonewright\WpMcp\Support\ElementorData;

/**
 * Contract decision: keep output_schema aligned to the handler response shape.
 *
 * @stonewright-status stable
 */
final class BuildPageFromSpec extends AbilityKernel {
	use ConfirmationGuard;

	public function name(): string {
		return 'stonewright/elementor-v3-build-page-from-spec';
	}

	public function label(): string {
		return __( 'Build Elementor page from Stonewright spec', 'stonewright' );
	}

	public function description(): string {
		return __( 'Renders a validated Stonewright Design Spec into Elementor V3 elements and writes it to a post.', 'stonewright' );
	}

	public function category(): string {
		return 'elementor';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'post_id' => [ 'type' => 'integer', 'minimum' => 1 ],
				'spec'               => [ 'type' => 'object' ],
				'replace'            => [ 'type' => 'boolean', 'default' => true ],
				'mode'               => [ 'type' => 'string', 'enum' => [ 'replace', 'append', 'replace_section' ] ],
				'dry_run'            => [ 'type' => 'boolean', 'default' => false ],
				'confirmation_token' => [ 'type' => 'string' ],
			],
			'required'             => [ 'post_id', 'spec' ],
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'post_id'     => [ 'type' => 'integer' ],
				'snapshot_id' => [ 'type' => 'string' ],
				'elements'      => [ 'type' => 'integer' ],
				'element_count' => [ 'type' => 'integer' ],
				'diagnostics'   => [ 'type' => 'array' ],
				'dry_run'       => [ 'type' => 'boolean' ],
				'mode'          => [ 'type' => 'string' ],
				'metrics'       => [ 'type' => 'object' ],
				'preview'       => [ 'type' => 'array' ],
			],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		$id = (int) ( $args['post_id'] ?? 0 );
		return Permissions::edit_post( $id );
	}

	public function execute( array $args ): array|\WP_Error {
		return $this->audit(
			$args,
			function ( array $args ) {
				$started_at = microtime( true );
				$post_id = (int) $args['post_id'];
				if ( ! get_post( $post_id ) ) {
					return $this->error( 'not_found', __( 'Post not found.', 'stonewright' ) );
				}

				$validate_started_at = microtime( true );
				$normalized = Validator::validate( (array) $args['spec'] );
				$validate_ms = self::elapsed_ms( $validate_started_at );
				if ( is_wp_error( $normalized ) ) {
					return $normalized;
				}

				$dry_run = ! empty( $args['dry_run'] );
				$mode    = self::write_mode( $args );
				if ( ! $dry_run && in_array( $mode, [ 'replace', 'replace_section' ], true ) ) {
					$verify_args = array_filter(
						$args,
						static fn( string $key ): bool => 'confirmation_token' !== $key,
						ARRAY_FILTER_USE_KEY
					);
					$token_error = $this->confirmation_token_error( $args, $verify_args );
					if ( null !== $token_error ) {
						return $token_error;
					}
				}

				$diagnostics       = [];
				$render_started_at = microtime( true );
				$rendered          = Renderer::render( $normalized, $diagnostics );
				$render_ms         = self::elapsed_ms( $render_started_at );
				$existing    = ElementorData::read( $post_id );
				$tree        = self::merge_tree( $existing, $rendered, $mode );

				if ( $dry_run ) {
					$element_count = count( ElementorData::flatten( $tree ) );
					return [
						'post_id'       => $post_id,
						'snapshot_id'   => '',
						'elements'      => $element_count,
						'element_count' => $element_count,
						'diagnostics'   => $diagnostics,
						'dry_run'       => true,
						'mode'          => $mode,
						'metrics'       => [
							'elapsed_ms'  => self::elapsed_ms( $started_at ),
							'validate_ms' => $validate_ms,
							'render_ms'   => $render_ms,
							'write_ms'    => 0.0,
						],
						'preview'       => $tree,
					];
				}

				// Backup before any mutation (AGENTS.md hard rule #3).
				$snapshot_id = Backup::snapshot_post( $post_id );

				$write_started_at = microtime( true );
				if ( ! ElementorData::write( $post_id, $tree ) ) {
					return $this->error( 'write_failed', __( 'Could not save Elementor data.', 'stonewright' ) );
				}
				$write_ms = self::elapsed_ms( $write_started_at );

				$element_count = count( ElementorData::flatten( $tree ) );
				return [
					'post_id'       => $post_id,
					'snapshot_id'   => $snapshot_id,
					'elements'      => $element_count,
					'element_count' => $element_count,
					'diagnostics'   => $diagnostics,
					'dry_run'       => false,
					'mode'          => $mode,
					'metrics'       => [
						'elapsed_ms'  => self::elapsed_ms( $started_at ),
						'validate_ms' => $validate_ms,
						'render_ms'   => $render_ms,
						'write_ms'    => $write_ms,
					],
				];
			}
		);
	}

	/**
	 * @param array<string, mixed> $args
	 */
	private static function write_mode( array $args ): string {
		if ( isset( $args['mode'] ) && in_array( $args['mode'], [ 'replace', 'append', 'replace_section' ], true ) ) {
			return (string) $args['mode'];
		}

		$replace = ! isset( $args['replace'] ) || (bool) $args['replace'];
		return $replace ? 'replace' : 'append';
	}

	/**
	 * @param array<int, array<string, mixed>> $existing
	 * @param array<int, array<string, mixed>> $rendered
	 * @return array<int, array<string, mixed>>
	 */
	private static function merge_tree( array $existing, array $rendered, string $mode ): array {
		if ( 'append' === $mode ) {
			return array_merge( $existing, $rendered );
		}

		if ( 'replace_section' !== $mode ) {
			return $rendered;
		}

		$rendered_by_id = [];
		foreach ( $rendered as $section ) {
			$id = isset( $section['id'] ) ? (string) $section['id'] : '';
			if ( '' !== $id ) {
				$rendered_by_id[ $id ] = $section;
			}
		}

		if ( [] === $rendered_by_id ) {
			return $existing;
		}

		foreach ( $existing as $index => $section ) {
			$id = isset( $section['id'] ) ? (string) $section['id'] : '';
			if ( '' !== $id && isset( $rendered_by_id[ $id ] ) ) {
				$existing[ $index ] = $rendered_by_id[ $id ];
				unset( $rendered_by_id[ $id ] );
			}
		}

		return $existing;
	}

	private static function elapsed_ms( float $start ): float {
		return round( ( microtime( true ) - $start ) * 1000, 3 );
	}
}
