<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\ElementorV3;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Elementor\CssAssetTransaction;
use Stonewright\WpMcp\Elementor\CssRegenerator;
use Stonewright\WpMcp\Elementor\PostCacheInvalidator;
use Stonewright\WpMcp\Elementor\Write\PostWriteLock;
use Stonewright\WpMcp\Security\Permissions;

/**
 * Closes an Elementor write by invalidating post-scoped caches, warming the
 * official frontend renderer, and checking bounded non-content assertions.
 *
 * @stonewright-status stable
 */
final class PostWriteVerify extends AbilityKernel {

	public function name(): string {
		return 'stonewright/elementor-post-write-verify';
	}

	public function label(): string {
		return __( 'Verify Elementor post write', 'stonewright' );
	}

	public function description(): string {
		return __( 'Invalidates one post HTML cache, regenerates only its CSS inside a guarded asset transaction, renders Elementor without a second CSS pass, and verifies requested element ids or content markers with automatic rollback on failure.', 'stonewright' );
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
				'confirmation_token' => [ 'type' => 'string' ],
				'post_id'        => [ 'type' => 'integer', 'minimum' => 1 ],
				'element_ids'    => [
					'type'     => 'array',
					'maxItems' => 50,
					'items'    => [ 'type' => 'string', 'minLength' => 1, 'maxLength' => 80 ],
					'default'  => [],
				],
				'html_contains'  => [
					'type'     => 'array',
					'maxItems' => 20,
					'items'    => [ 'type' => 'string', 'minLength' => 1, 'maxLength' => 200 ],
					'default'  => [],
				],
				'write_receipt'  => [ 'type' => 'object', 'description' => 'Receipt returned by the originating batch transaction.' ],
			],
		];
	}

	public function output_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => true,
			'properties'           => [
				'ok'                  => [ 'type' => 'boolean' ],
				'post_id'             => [ 'type' => 'integer' ],
				'verification_status' => [ 'type' => 'string', 'enum' => [ 'passed', 'failed' ] ],
				'effect_verified'     => [ 'type' => 'boolean' ],
				'rendered_bytes'      => [ 'type' => 'integer' ],
				'render_sha256'       => [ 'type' => 'string' ],
				'cache'               => [ 'type' => 'object' ],
				'css'                 => [ 'type' => 'object' ],
				'element_checks'      => [ 'type' => 'array' ],
				'content_checks'      => [ 'type' => 'array' ],
				'browser_required'    => [ 'type' => 'boolean' ],
				'browser_recipe'      => [ 'type' => 'object' ],
				'write_receipt'       => [ 'type' => 'object' ],
			],
			'required'             => [ 'ok', 'post_id', 'verification_status', 'effect_verified', 'rendered_bytes', 'render_sha256', 'cache', 'css', 'element_checks', 'content_checks', 'browser_required', 'browser_recipe' ],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::edit_post( (int) ( $args['post_id'] ?? 0 ) );
	}

	public function execute( array $args ): array|\WP_Error {
		return $this->audit_write(
			$args,
			function ( array $args ): array|\WP_Error {
				$post_id = (int) ( $args['post_id'] ?? 0 );
				if ( ! get_post( $post_id ) ) {
					return $this->error( 'not_found', __( 'Post not found.', 'stonewright' ), [ 'status' => 404 ] );
				}

				if ( ! did_action( 'elementor/loaded' ) || ! class_exists( '\\Elementor\\Plugin' ) ) {
					return $this->error(
						'elementor_unavailable',
						__( 'Elementor is not loaded, so frontend verification cannot run.', 'stonewright' ),
						[ 'status' => 409, 'repair' => 'Activate Elementor, then retry this verification ability.' ]
					);
				}

				$frontend = \Elementor\Plugin::$instance->frontend ?? null;
				if ( ! is_object( $frontend ) || ! method_exists( $frontend, 'get_builder_content_for_display' ) ) {
					return $this->error(
						'elementor_frontend_unavailable',
						__( 'Elementor frontend renderer is unavailable.', 'stonewright' ),
						[ 'status' => 409, 'repair' => 'Reload WordPress with Elementor fully initialized, then retry.' ]
					);
				}

				$owner = 'verify-' . substr( hash( 'sha256', wp_generate_uuid4() . '|' . $post_id ), 0, 32 );
				$lease = PostWriteLock::acquire( $post_id, $owner, 120 );
				if ( $lease instanceof \WP_Error ) {
					return $lease;
				}
				try {
				$cache_snapshot = PostCacheInvalidator::snapshot( $post_id );
				$cache = PostCacheInvalidator::invalidate( $post_id );
				if ( ! (bool) ( $cache['ok'] ?? false ) ) {
					$cache_rollback_status = 'not_attempted_lock_lost';
					$renewed = PostWriteLock::renew( $lease, 120 );
					if ( ! $renewed instanceof \WP_Error ) {
						$lease = $renewed;
						$cache_rollback = PostCacheInvalidator::restore( $post_id, $cache_snapshot );
						$cache_rollback_status = $cache_rollback['ok'] ? 'succeeded' : 'failed';
					}
					return $this->error(
						'elementor_post_cache_invalidation_failed',
						__( 'Elementor post HTML cache invalidation failed.', 'stonewright' ),
						[ 'status' => 500, 'verification_status' => 'failed', 'cache_rollback_status' => $cache_rollback_status ]
					);
				}

				$renewed = PostWriteLock::renew( $lease, 120 );
				if ( $renewed instanceof \WP_Error ) {
					return $renewed;
				}
				$lease = $renewed;

				$transaction = CssAssetTransaction::run(
					$post_id,
					static function () use ( $post_id, $frontend, $args, $cache ): array {
						$css = CssRegenerator::regenerate_post( $post_id );
						if ( ! (bool) ( $css['ok'] ?? false ) ) {
							return [
								'ok'         => false,
								'error_code' => 'css_regeneration_failed',
							];
						}
						// CSS was already regenerated explicitly. Passing false prevents
						// Elementor from starting another CSS write path during rendering.
						$html = (string) $frontend->get_builder_content_for_display( $post_id, false );
						$verification = self::verification_evidence( $html, $args );
						$checks       = array_merge( $verification['element_checks'], $verification['content_checks'] );
						$passed       = '' !== $html
							&& (bool) ( $cache['ok'] ?? false )
							&& ! in_array( false, array_column( $checks, 'present' ), true );
						if ( ! $passed ) {
							return [ 'ok' => false, 'error_code' => 'frontend_verification_failed', 'verification' => $verification ];
						}
						return [
							'ok'           => true,
							'css'          => $css,
							'html'         => $html,
							'verification' => $verification,
						];
					}
				);
				if ( $transaction instanceof \WP_Error ) {
					$data = $transaction->get_error_data();
					$data = is_array( $data ) ? $data : [];
					$data['cache_rollback_status'] = 'not_attempted_lock_lost';
					$restore_lease = PostWriteLock::renew( $lease, 120 );
					if ( ! $restore_lease instanceof \WP_Error ) {
						$lease = $restore_lease;
						$cache_rollback = PostCacheInvalidator::restore( $post_id, $cache_snapshot );
						$data['cache_rollback_status'] = $cache_rollback['ok'] ? 'succeeded' : 'failed';
					}
					$transaction->add_data( $data );
					$verification = is_array( $data['operation_evidence']['verification'] ?? null )
						? $data['operation_evidence']['verification']
						: [ 'rendered_bytes' => 0, 'render_sha256' => '', 'element_checks' => [], 'content_checks' => [] ];
					return self::failure_response( $post_id, $cache, $verification, $data, $args );
				}
				$renewed = PostWriteLock::renew( $lease, 120 );
				if ( $renewed instanceof \WP_Error ) {
					return $this->error(
						'elementor_write_lock_lost',
						__( 'The Elementor write lease was lost before verification could be closed.', 'stonewright' ),
						[ 'status' => 409, 'verification_status' => 'failed', 'rollback_status' => 'not_attempted_lock_lost' ]
					);
				}
				$lease = $renewed;
				$operation = is_array( $transaction['operation_result'] ?? null ) ? $transaction['operation_result'] : [];
				$html      = (string) ( $operation['html'] ?? '' );
				$css       = array_merge(
					is_array( $operation['css'] ?? null ) ? $operation['css'] : [],
					is_array( $transaction['css_evidence'] ?? null ) ? $transaction['css_evidence'] : []
				);

				$element_ids = self::bounded_strings( $args['element_ids'] ?? [], 50, 80 );
				$markers     = self::bounded_strings( $args['html_contains'] ?? [], 20, 200 );
				$element_checks = [];
				foreach ( $element_ids as $element_id ) {
					$element_checks[] = [
						'element_id' => $element_id,
						'selector'   => '.elementor-element-' . $element_id,
						'present'    => str_contains( $html, 'elementor-element-' . $element_id ),
					];
				}
				$content_checks = [];
				foreach ( $markers as $marker ) {
					$content_checks[] = [
						'sha256'  => hash( 'sha256', $marker ),
						'length'  => strlen( $marker ),
						'present' => str_contains( $html, $marker ),
					];
				}

				$checks = array_merge( $element_checks, $content_checks );
				$passed = '' !== $html
					&& (bool) ( $cache['ok'] ?? false )
					&& (bool) ( $css['ok'] ?? false )
					&& ! in_array( false, array_column( $checks, 'present' ), true );

				$write_receipt = isset( $args['write_receipt'] ) && is_array( $args['write_receipt'] ) ? self::sanitize_receipt( $args['write_receipt'] ) : [];
				if ( [] !== $write_receipt ) {
					$write_receipt['verification_status'] = $passed ? 'verified' : 'failed';
					$write_receipt['root_error_code'] = $passed ? '' : 'stonewright_elementor_frontend_verification_failed';
					$write_receipt['root_error_path'] = $passed ? '' : 'verify.frontend';
					$write_receipt['rollback_status'] = $passed ? (string) ( $write_receipt['rollback_status'] ?? 'not_needed' ) : 'manual_required';
					$write_receipt['recovery'] = $passed ? [] : [ 'action' => 'inspect_render_and_restore_snapshot_if_required', 'automatic_rollback' => false ];
				}

				return [
					'ok'                  => $passed,
					'post_id'             => $post_id,
					'verification_status' => $passed ? 'passed' : 'failed',
					'effect_verified'     => $passed,
					'rendered_bytes'      => strlen( $html ),
					'render_sha256'       => hash( 'sha256', $html ),
					'cache'               => $cache,
					'css'                 => $css,
					'element_checks'      => $element_checks,
					'content_checks'      => $content_checks,
					'browser_required'    => true,
					'browser_recipe'      => [
						'desktop_tablet_mobile' => true,
						'outer_selector'        => '.elementor-element-<element_id>',
						'boxed_inner_selector'  => '.elementor-element-<element_id> > .e-con-inner',
						'rule'                  => 'For boxed containers measure both outer and .e-con-inner. A builder render pass is not visual acceptance.',
					],
					'write_receipt'       => $write_receipt,
				];
				} finally {
					PostWriteLock::release( $post_id, $owner );
				}
			}
		);
	}

	/**
	 * Build assertion evidence without retaining caller-provided marker text.
	 *
	 * @param array<string,mixed> $args
	 * @return array{rendered_bytes:int,render_sha256:string,element_checks:list<array<string,mixed>>,content_checks:list<array<string,mixed>>}
	 */
	private static function verification_evidence( string $html, array $args ): array {
		$element_checks = [];
		foreach ( self::bounded_strings( $args['element_ids'] ?? [], 50, 80 ) as $element_id ) {
			$element_checks[] = [
				'element_id' => $element_id,
				'selector'   => '.elementor-element-' . $element_id,
				'present'    => str_contains( $html, 'elementor-element-' . $element_id ),
			];
		}
		$content_checks = [];
		foreach ( self::bounded_strings( $args['html_contains'] ?? [], 20, 200 ) as $marker ) {
			$content_checks[] = [
				'sha256'  => hash( 'sha256', $marker ),
				'length'  => strlen( $marker ),
				'present' => str_contains( $html, $marker ),
			];
		}
		return [
			'rendered_bytes'  => strlen( $html ),
			'render_sha256'   => hash( 'sha256', $html ),
			'element_checks'  => $element_checks,
			'content_checks'  => $content_checks,
		];
	}

	/** @param array<string,mixed> $verification @param array<string,mixed> $data @param array<string,mixed> $args */
	private static function failure_response( int $post_id, array $cache, array $verification, array $data, array $args ): array {
		$write_receipt = isset( $args['write_receipt'] ) && is_array( $args['write_receipt'] ) ? self::sanitize_receipt( $args['write_receipt'] ) : [];
		$rollback_status = (string) ( $data['rollback_status'] ?? 'failed' );
		if ( [] !== $write_receipt ) {
			$write_receipt['verification_status'] = 'failed';
			$write_receipt['root_error_code'] = sanitize_key( (string) ( $data['root_error_code'] ?? 'stonewright_elementor_frontend_verification_failed' ) );
			$write_receipt['root_error_path'] = 'verify.frontend';
			$write_receipt['rollback_status'] = $rollback_status;
		}
		return [
			'ok'                  => false,
			'post_id'             => $post_id,
			'verification_status' => 'failed',
			'effect_verified'     => false,
			'rendered_bytes'      => (int) ( $verification['rendered_bytes'] ?? 0 ),
			'render_sha256'       => (string) ( $verification['render_sha256'] ?? '' ),
			'cache'               => array_merge( $cache, [ 'rollback_status' => (string) ( $data['cache_rollback_status'] ?? 'failed' ) ] ),
			'css'                 => [
				'ok'                       => false,
				'rollback_status'          => $rollback_status,
				'manifest_rollback_status' => (string) ( $data['manifest_rollback_status'] ?? 'failed' ),
				'metadata_rollback_status' => (string) ( $data['metadata_rollback_status'] ?? 'failed' ),
				'root_error_code'          => sanitize_key( (string) ( $data['root_error_code'] ?? 'stonewright_elementor_frontend_verification_failed' ) ),
			],
			'element_checks'      => is_array( $verification['element_checks'] ?? null ) ? $verification['element_checks'] : [],
			'content_checks'      => is_array( $verification['content_checks'] ?? null ) ? $verification['content_checks'] : [],
			'browser_required'    => true,
			'browser_recipe'      => self::browser_recipe(),
			'write_receipt'       => $write_receipt,
		];
	}

	/** @return array<string,mixed> */
	private static function browser_recipe(): array {
		return [
			'desktop_tablet_mobile' => true,
			'outer_selector'        => '.elementor-element-<element_id>',
			'boxed_inner_selector'  => '.elementor-element-<element_id> > .e-con-inner',
			'rule'                  => 'For boxed containers measure both outer and .e-con-inner. A builder render pass is not visual acceptance.',
		];
	}

	/**
	 * @return list<string>
	 */
	private static function bounded_strings( mixed $values, int $limit, int $max_length ): array {
		if ( ! is_array( $values ) ) {
			return [];
		}

		$out = [];
		foreach ( array_slice( $values, 0, $limit ) as $value ) {
			if ( ! is_scalar( $value ) ) {
				continue;
			}
			$value = trim( mb_substr( (string) $value, 0, $max_length ) );
			if ( '' !== $value ) {
				$out[] = $value;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Keep the verification receipt useful for correlation without echoing
	 * arbitrary caller-controlled data back through the MCP response.
	 *
	 * @param array<string,mixed> $receipt
	 * @return array<string,mixed>
	 */
	private static function sanitize_receipt( array $receipt ): array {
		$allowed = [
			'transaction_id',
			'change_set_id',
			'post_id',
			'architecture',
			'target_ids',
			'dry_run',
			'snapshot_id',
			'before_hash',
			'planned_hash',
			'after_hash',
			'readback_hash',
			'verification_status',
			'rollback_status',
			'root_error_code',
			'root_error_path',
			'retryable',
			'retry_after_seconds',
			'audit_id',
			'cause_fingerprint',
			'strategy_fingerprint',
		];
		$out = [];
		foreach ( $allowed as $key ) {
			if ( ! array_key_exists( $key, $receipt ) ) {
				continue;
			}
			$value = $receipt[ $key ];
			if ( in_array( $key, [ 'before_hash', 'planned_hash', 'after_hash', 'readback_hash', 'cause_fingerprint', 'strategy_fingerprint' ], true ) ) {
				$value = is_scalar( $value ) && 1 === preg_match( '/^[a-f0-9]{64}$/i', (string) $value ) ? strtolower( (string) $value ) : '';
			} elseif ( 'target_ids' === $key ) {
				$value = self::bounded_strings( $value, 100, 80 );
			} elseif ( 'post_id' === $key ) {
				$value = max( 0, (int) $value );
			} elseif ( 'dry_run' === $key || 'retryable' === $key ) {
				$value = (bool) $value;
			} elseif ( 'retry_after_seconds' === $key ) {
				$value = max( 0, min( 86400, (int) $value ) );
			} elseif ( is_scalar( $value ) || null === $value ) {
				$value = mb_substr( sanitize_text_field( (string) $value ), 0, 255 );
			} else {
				continue;
			}
			$out[ $key ] = $value;
		}
		if ( isset( $receipt['lock'] ) && is_array( $receipt['lock'] ) ) {
			$out['lock'] = [
				'status'      => sanitize_key( (string) ( $receipt['lock']['status'] ?? '' ) ),
				'fingerprint' => is_scalar( $receipt['lock']['fingerprint'] ?? null ) && 1 === preg_match( '/^[a-f0-9]{64}$/i', (string) $receipt['lock']['fingerprint'] ) ? strtolower( (string) $receipt['lock']['fingerprint'] ) : '',
				'age_seconds' => max( 0, (int) ( $receipt['lock']['age_seconds'] ?? 0 ) ),
				'retry_after' => max( 0, (int) ( $receipt['lock']['retry_after'] ?? 0 ) ),
			];
		}
		return $out;
	}
}
