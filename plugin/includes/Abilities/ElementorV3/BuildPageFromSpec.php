<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\ElementorV3;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Abilities\Common\ConfirmationGuard;
use Stonewright\WpMcp\Design\Evidence\Validator as DesignEvidenceValidator;
use Stonewright\WpMcp\DesignSpec\Validator;
use Stonewright\WpMcp\Elementor\Renderer;
use Stonewright\WpMcp\Elementor\Schema\SettingsValidator;
use Stonewright\WpMcp\Elementor\V4\AtomicTreeInspector;
use Stonewright\WpMcp\Elementor\Write\TreeHasher;
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
				'design_evidence'    => [ 'type' => 'object', 'description' => 'Required when spec style_policy is strict.' ],
				'replace'            => [ 'type' => 'boolean', 'default' => true ],
				'mode'               => [ 'type' => 'string', 'enum' => [ 'replace', 'append', 'replace_section' ] ],
				'expected_tree_hash' => [ 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$' ],
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
				'before_hash'   => [ 'type' => 'string' ],
				'after_hash'    => [ 'type' => 'string' ],
				'readback_hash' => [ 'type' => 'string' ],
				'evidence_hash' => [ 'type' => 'string' ],
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
				$style_policy = (string) ( $normalized['style_policy'] ?? ( $normalized['meta']['style_policy'] ?? '' ) );
				$evidence_hash = '';
				if ( 'strict' === $style_policy ) {
					$evidence = isset( $args['design_evidence'] ) && is_array( $args['design_evidence'] ) ? $args['design_evidence'] : [];
					$validated_evidence = DesignEvidenceValidator::validate( $evidence );
					if ( $validated_evidence instanceof \WP_Error ) {
						return $validated_evidence;
					}
					$evidence_hash = (string) $validated_evidence['evidence_hash'];
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
				$existing     = ElementorData::read( $post_id );
				$before_hash  = TreeHasher::hash( $existing );
				$architecture = (string) ( AtomicTreeInspector::inspect( $existing )['architecture'] ?? 'empty' );
				if ( in_array( $architecture, [ 'v4', 'mixed' ], true ) ) {
					return $this->error( 'v3_architecture_mismatch', __( 'This document contains Elementor V4 Atomic nodes. V3 rendering is blocked to prevent a mixed tree.', 'stonewright' ), [ 'status' => 409, 'architecture' => $architecture, 'before_hash' => $before_hash ] );
				}
				$expected_hash = isset( $args['expected_tree_hash'] ) ? (string) $args['expected_tree_hash'] : '';
				if ( '' !== $expected_hash && ! hash_equals( $expected_hash, $before_hash ) ) {
					return $this->error( 'tree_conflict', __( 'Elementor page changed after planning; refresh structure before writing.', 'stonewright' ), [ 'status' => 409, 'expected_tree_hash' => $expected_hash, 'current_tree_hash' => $before_hash ] );
				}
				$tree       = self::merge_tree( $existing, $rendered, $mode );
				$after_hash = TreeHasher::hash( $tree );

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
						'before_hash'   => $before_hash,
						'after_hash'    => $after_hash,
						'readback_hash' => $after_hash,
						'evidence_hash' => $evidence_hash,
					];
				}

				// Backup before any mutation (AGENTS.md hard rule #3).
				$snapshot_id = Backup::snapshot_post( $post_id );

				$write_started_at = microtime( true );
				if ( ! ElementorData::write( $post_id, $tree ) ) {
					$restored = Backup::restore( $post_id, $snapshot_id );
					return $this->error( 'write_failed', __( 'Could not save Elementor data; the snapshot was restored.', 'stonewright' ), [ 'restored' => $restored, 'validation_error' => SettingsValidator::last_error() ] );
				}
				$write_ms = self::elapsed_ms( $write_started_at );
				$readback_hash = TreeHasher::hash( ElementorData::read( $post_id ) );
				if ( ! hash_equals( $after_hash, $readback_hash ) ) {
					$restored = Backup::restore( $post_id, $snapshot_id );
					return $this->error( 'readback_mismatch', __( 'Elementor write readback differed from the compiled tree; the snapshot was restored.', 'stonewright' ), [ 'status' => 500, 'expected_hash' => $after_hash, 'readback_hash' => $readback_hash, 'restored' => $restored ] );
				}

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
					'before_hash'   => $before_hash,
					'after_hash'    => $after_hash,
					'readback_hash' => $readback_hash,
					'evidence_hash' => $evidence_hash,
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
