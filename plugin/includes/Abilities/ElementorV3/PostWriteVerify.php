<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\ElementorV3;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Security\Permissions;

/**
 * Observes an Elementor frontend render. It does not regenerate CSS, invalidate
 * caches, or roll back files. CSS mutation belongs to elementor-css-regenerate.
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
		return __( 'Renders Elementor with CSS generation disabled and checks bounded element ids or content markers. Observation only: it does not regenerate CSS, invalidate caches, or roll back files. Call after apply and CSS regenerate, then complete Browser QA.', 'stonewright' );
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
				'post_id'       => [ 'type' => 'integer', 'minimum' => 1 ],
				'element_ids'   => [
					'type'     => 'array',
					'maxItems' => 50,
					'items'    => [ 'type' => 'string', 'minLength' => 1, 'maxLength' => 80 ],
					'default'  => [],
				],
				'html_contains' => [
					'type'     => 'array',
					'maxItems' => 20,
					'items'    => [ 'type' => 'string', 'minLength' => 1, 'maxLength' => 200 ],
					'default'  => [],
				],
				'write_receipt' => [ 'type' => 'object', 'description' => 'Receipt returned by the originating batch transaction.' ],
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
				'element_checks'      => [ 'type' => 'array' ],
				'content_checks'      => [ 'type' => 'array' ],
				'browser_required'    => [ 'type' => 'boolean' ],
				'browser_recipe'      => [ 'type' => 'object' ],
				'write_receipt'       => [ 'type' => 'object' ],
			],
			'required'             => [ 'ok', 'post_id', 'verification_status', 'effect_verified', 'rendered_bytes', 'render_sha256', 'element_checks', 'content_checks', 'browser_required', 'browser_recipe' ],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::edit_post( (int) ( $args['post_id'] ?? 0 ) );
	}

	public function execute( array $args ): array|\WP_Error {
		return $this->audit(
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

				try {
					$html = (string) $frontend->get_builder_content_for_display( $post_id, false );
				} catch ( \Throwable $error ) {
					return self::failure_response( $post_id, '', $args, 'renderer_failed' );
				}

				$verification   = self::verification_evidence( $html, $args );
				$element_checks = $verification['element_checks'];
				$content_checks = $verification['content_checks'];
				$checks         = array_merge( $element_checks, $content_checks );
				$passed         = '' !== $html && ! in_array( false, array_column( $checks, 'present' ), true );

				$write_receipt = isset( $args['write_receipt'] ) && is_array( $args['write_receipt'] ) ? self::sanitize_receipt( $args['write_receipt'] ) : [];
				if ( [] !== $write_receipt ) {
					$write_receipt['verification_status'] = $passed ? 'verified' : 'failed';
					$write_receipt['root_error_code']     = $passed ? '' : 'stonewright_elementor_frontend_verification_failed';
				}

				return [
					'ok'                  => $passed,
					'post_id'             => $post_id,
					'verification_status' => $passed ? 'passed' : 'failed',
					'effect_verified'     => $passed,
					'rendered_bytes'      => $verification['rendered_bytes'],
					'render_sha256'       => $verification['render_sha256'],
					'element_checks'      => $element_checks,
					'content_checks'      => $content_checks,
					'browser_required'    => true,
					'browser_recipe'      => self::browser_recipe(),
					'write_receipt'       => $write_receipt,
				];
			}
		);
	}

	/**
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

	/** @param array<string,mixed> $args */
	private static function failure_response( int $post_id, string $html, array $args, string $code ): array {
		$verification = self::verification_evidence( $html, $args );
		$write_receipt = isset( $args['write_receipt'] ) && is_array( $args['write_receipt'] ) ? self::sanitize_receipt( $args['write_receipt'] ) : [];
		if ( [] !== $write_receipt ) {
			$write_receipt['verification_status'] = 'failed';
			$write_receipt['root_error_code']     = sanitize_key( $code );
		}
		return [
			'ok'                  => false,
			'post_id'             => $post_id,
			'verification_status' => 'failed',
			'effect_verified'     => false,
			'rendered_bytes'      => $verification['rendered_bytes'],
			'render_sha256'       => $verification['render_sha256'],
			'element_checks'      => $verification['element_checks'],
			'content_checks'      => $verification['content_checks'],
			'browser_required'    => true,
			'browser_recipe'      => self::browser_recipe(),
			'write_receipt'       => $write_receipt,
			'root_error_code'     => sanitize_key( $code ),
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
		return $out;
	}
}
