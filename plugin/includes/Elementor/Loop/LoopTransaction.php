<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Elementor\Loop;

use Stonewright\WpMcp\DesignSpec\Validator;
use Stonewright\WpMcp\Elementor\Renderer;
use Stonewright\WpMcp\Elementor\V4\AtomicTreeInspector;
use Stonewright\WpMcp\Elementor\Write\IdempotencyStore;
use Stonewright\WpMcp\Elementor\Write\PostWriteLock;
use Stonewright\WpMcp\Elementor\Write\TreeHasher;
use Stonewright\WpMcp\Elementor\Write\V3MutationCompiler;
use Stonewright\WpMcp\Security\Backup;
use Stonewright\WpMcp\Support\ElementorData;
use Stonewright\WpMcp\ThemeBuilder\TemplateStore;

/**
 * Transactionally wires one native loop widget to a V3 parent.
 */
final class LoopTransaction {
	private static string $fail_at = '';

	public static function fail_at_for_test( string $phase ): void {
		self::$fail_at = sanitize_key( $phase );
	}

	/**
	 * @param array<string, mixed> $args
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function run( array $args ): array|\WP_Error {
		$post_id         = (int) ( $args['post_id'] ?? 0 );
		$idempotency_key = trim( (string) ( $args['idempotency_key'] ?? '' ) );
		$request_hash    = TreeHasher::hash( self::request_payload( $args ) );
		$dry_run         = ! empty( $args['dry_run'] );

		if ( ! $dry_run ) {
			$replay = IdempotencyStore::lookup( $post_id, $idempotency_key, $request_hash );
			if ( $replay instanceof \WP_Error || is_array( $replay ) ) {
				return $replay;
			}
		}

		$plan = self::plan( $args );
		if ( $plan instanceof \WP_Error ) {
			return $plan;
		}
		if ( $dry_run ) {
			return self::planned_response( $plan );
		}

		$owner = 'loop-' . substr( $request_hash, 0, 24 );
		$lease = PostWriteLock::acquire( $post_id, $owner );
		if ( $lease instanceof \WP_Error ) {
			return $lease;
		}

		$page_snapshot   = '';
		$template_id     = (int) $plan['template_id'];
		$created_template = false;
		try {
			$current_hash = TreeHasher::hash( ElementorData::read( $post_id ) );
			if ( ! hash_equals( (string) $plan['before_hash'], $current_hash ) ) {
				return self::phase_error(
					'page_precondition',
					'none',
					'not_required',
					__( 'The Elementor page changed after loop planning.', 'stonewright' ),
					[ 'status' => 409, 'retryable' => true, 'current_tree_hash' => $current_hash ]
				);
			}

			if ( ! empty( $plan['template_tree'] ) ) {
				$staged = self::stage_template( $plan, $owner );
				if ( $staged instanceof \WP_Error ) {
					return $staged;
				}
				$template_id      = $staged;
				$created_template = true;
				$plan             = self::plan( $args, $template_id );
				if ( $plan instanceof \WP_Error ) {
					return self::rollback_error( $plan, $post_id, '', $template_id, $owner, true, 'page_compile' );
				}
			}

			if ( self::should_fail( 'page_write' ) ) {
				return self::rollback_error( self::phase_error( 'page_write' ), $post_id, '', $template_id, $owner, $created_template, 'page_write' );
			}
			$page_snapshot = Backup::snapshot_post( $post_id );
			if ( '' === $page_snapshot ) {
				return self::rollback_error( self::phase_error( 'page_snapshot' ), $post_id, '', $template_id, $owner, $created_template, 'page_snapshot' );
			}
			if ( ! ElementorData::write( $post_id, $plan['tree'], [ 'touched_ids' => [ (string) $plan['parent_id'] ] ] ) ) {
				$error = ElementorData::write_error_for_ability();
				return self::rollback_error( $error, $post_id, $page_snapshot, $template_id, $owner, $created_template, 'page_write' );
			}

			if ( self::should_fail( 'page_readback' ) ) {
				return self::rollback_error( self::phase_error( 'page_readback' ), $post_id, $page_snapshot, $template_id, $owner, $created_template, 'page_readback' );
			}
			$readback = LoopReadbackVerifier::verify( ElementorData::read( $post_id ), $plan['expected_readback'] );
			if ( $readback instanceof \WP_Error ) {
				return self::rollback_error( $readback, $post_id, $page_snapshot, $template_id, $owner, $created_template, 'page_readback' );
			}

			if ( $created_template ) {
				if ( self::should_fail( 'template_publish' ) ) {
					return self::rollback_error( self::phase_error( 'template_publish' ), $post_id, $page_snapshot, $template_id, $owner, true, 'template_publish' );
				}
				$published = TemplateStore::publish_staged( $template_id, $owner );
				if ( $published instanceof \WP_Error || ! $published ) {
					$error = $published instanceof \WP_Error ? $published : self::phase_error( 'template_publish' );
					return self::rollback_error( $error, $post_id, $page_snapshot, $template_id, $owner, true, 'template_publish' );
				}
			}

			if ( self::should_fail( 'final_readback' ) ) {
				return self::rollback_error( self::phase_error( 'final_readback' ), $post_id, $page_snapshot, $template_id, $owner, $created_template, 'final_readback' );
			}
			$final_readback = LoopReadbackVerifier::verify( ElementorData::read( $post_id ), $plan['expected_readback'] );
			if ( $final_readback instanceof \WP_Error ) {
				return self::rollback_error( $final_readback, $post_id, $page_snapshot, $template_id, $owner, $created_template, 'final_readback' );
			}

			if ( $created_template ) {
				if ( self::should_fail( 'template_finalize' ) ) {
					return self::rollback_error( self::phase_error( 'template_finalize' ), $post_id, $page_snapshot, $template_id, $owner, true, 'template_finalize' );
				}
				$finalized = TemplateStore::finalize_staged( $template_id, $owner );
				if ( $finalized instanceof \WP_Error || ! $finalized ) {
					$error = $finalized instanceof \WP_Error ? $finalized : self::phase_error( 'template_finalize' );
					return self::rollback_error( $error, $post_id, $page_snapshot, $template_id, $owner, true, 'template_finalize' );
				}
			}

			$response = [
				'ok'                  => true,
				'status'              => 'applied',
				'post_id'             => $post_id,
				'parent_id'           => (string) $plan['parent_id'],
				'template_id'         => $template_id,
				'created_template'    => $created_template,
				'widget_id'           => (string) $plan['widget_id'],
				'widget_type'         => (string) $plan['widget_type'],
				'snapshot_id'         => $page_snapshot,
				'before_hash'         => (string) $plan['before_hash'],
				'after_hash'          => (string) $plan['after_hash'],
				'readback_hash'       => (string) $final_readback['readback_hash'],
				'readback'            => $final_readback,
				'query_probe'         => $plan['query_probe'],
				'schema_hash'         => (string) $plan['schema_hash'],
				'resolved_controls'   => $plan['resolved_controls'],
				'execution_status'    => 'applied',
				'verification_status' => 'verified',
				'rollback_status'     => 'not_required',
				'effect_verified'     => true,
				'idempotent_replay'   => false,
			];
			IdempotencyStore::remember( $post_id, $idempotency_key, $request_hash, $response );

			return $response;
		} finally {
			PostWriteLock::release( $post_id, $owner );
			self::$fail_at = '';
		}
	}

	/**
	 * @param array<string, mixed> $args
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function plan( array $args, int $resolved_template_id = 0 ): array|\WP_Error {
		$post_id   = (int) ( $args['post_id'] ?? 0 );
		$parent_id = sanitize_key( (string) ( $args['parent_id'] ?? '' ) );
		$post      = get_post( $post_id );
		if ( ! $post || '' === $parent_id ) {
			return self::phase_error(
				'validation',
				'none',
				'not_required',
				__( 'Loop wiring requires an existing page and explicit parent.', 'stonewright' ),
				[ 'status' => 404, 'retryable' => false ]
			);
		}

		$tree        = ElementorData::read( $post_id );
		$before_hash = TreeHasher::hash( $tree );
		$expected    = (string) ( $args['expected_tree_hash'] ?? '' );
		if ( '' !== $expected && ! hash_equals( $expected, $before_hash ) ) {
			return self::phase_error(
				'page_precondition',
				'none',
				'not_required',
				__( 'The Elementor page changed after planning.', 'stonewright' ),
				[ 'status' => 409, 'retryable' => true, 'current_tree_hash' => $before_hash ]
			);
		}
		if ( null === ElementorData::find_path( $tree, $parent_id ) ) {
			return self::phase_error(
				'parent_validation',
				'none',
				'not_required',
				__( 'The requested loop parent was not found.', 'stonewright' ),
				[ 'status' => 404, 'retryable' => false, 'parent_id' => $parent_id ]
			);
		}
		$parent_architecture = AtomicTreeInspector::subtree_architecture( $tree, $parent_id );
		if ( in_array( $parent_architecture, [ 'v4', 'mixed' ], true ) ) {
			return self::phase_error(
				'parent_validation',
				'none',
				'not_required',
				__( 'Loop wiring requires a V3-only parent.', 'stonewright' ),
				[ 'status' => 409, 'retryable' => false, 'parent_architecture' => $parent_architecture ]
			);
		}

		$template_tree = [];
		$template_id   = $resolved_template_id > 0 ? $resolved_template_id : (int) ( $args['template_id'] ?? 0 );
		if ( is_array( $args['template_spec'] ?? null ) ) {
			$normalized = Validator::validate( $args['template_spec'] );
			if ( $normalized instanceof \WP_Error ) {
				return $normalized;
			}
			$diagnostics   = [];
			$template_tree = Renderer::render( $normalized, $diagnostics );
		} elseif ( $template_id < 1 ) {
			return self::phase_error(
				'template_validation',
				'none',
				'not_required',
				__( 'Provide an existing loop-item template or a template spec.', 'stonewright' ),
				[ 'status' => 400, 'retryable' => false ]
			);
		}

		if ( $template_id > 0 ) {
			$template = get_post( $template_id );
			if ( ! $template || 'elementor_library' !== (string) $template->post_type || 'loop-item' !== TemplateStore::get_type( $template_id ) ) {
				return self::phase_error(
					'template_validation',
					'none',
					'not_required',
					__( 'The selected template is not a loop-item template.', 'stonewright' ),
					[ 'status' => 409, 'retryable' => false, 'template_id' => $template_id ]
				);
			}
		}

		$query = is_array( $args['query'] ?? null ) ? $args['query'] : [];
		$query_probe = LoopQueryProbe::probe(
			sanitize_key( (string) ( $args['post_type'] ?? '' ) ),
			$query,
			! empty( $args['require_results'] )
		);
		if ( $query_probe instanceof \WP_Error ) {
			return $query_probe;
		}

		$compile_template_id = $template_id > 0 ? $template_id : 1;
		$intent = [
			'query'      => $query,
			'responsive' => is_array( $args['responsive'] ?? null ) ? $args['responsive'] : [],
		];
		foreach ( [ 'slides_to_scroll', 'arrows', 'pagination' ] as $key ) {
			if ( array_key_exists( $key, $args ) ) {
				$intent[ $key ] = $args[ $key ];
			}
		}
		$compiled_intent = LoopIntentCompiler::compile(
			sanitize_key( (string) ( $args['display'] ?? '' ) ),
			$compile_template_id,
			sanitize_key( (string) ( $args['post_type'] ?? '' ) ),
			$intent
		);
		if ( $compiled_intent instanceof \WP_Error ) {
			return $compiled_intent;
		}

		$widget_id = substr(
			hash( 'sha256', $post_id . '|' . $parent_id . '|' . (string) ( $args['idempotency_key'] ?? '' ) ),
			0,
			8
		);
		$compiled_tree = ( new V3MutationCompiler() )->compile(
			$tree,
			[
				[
					'action'      => 'add_widget',
					'parent_id'   => $parent_id,
					'element_id'  => $widget_id,
					'widget_type' => (string) $compiled_intent['widget_type'],
					'settings'    => $compiled_intent['settings'],
				],
			]
		);
		if ( $compiled_tree instanceof \WP_Error ) {
			return $compiled_tree;
		}
		$after_hash       = TreeHasher::hash( $compiled_tree['tree'] );
		$template_control = (string) ( $compiled_intent['resolved_controls']['template'] ?? '' );

		return [
			'post_id'             => $post_id,
			'parent_id'           => $parent_id,
			'template_id'         => $template_id,
			'template_tree'       => $template_tree,
			'template_title'      => sanitize_text_field( (string) ( $args['template_title'] ?? 'Stonewright Loop Item' ) ),
			'widget_id'           => $widget_id,
			'widget_type'         => (string) $compiled_intent['widget_type'],
			'tree'                => $compiled_tree['tree'],
			'before_hash'         => $before_hash,
			'after_hash'          => $after_hash,
			'schema_hash'         => (string) $compiled_intent['schema_hash'],
			'runtime_fingerprint' => (string) $compiled_intent['runtime_fingerprint'],
			'resolved_controls'   => $compiled_intent['resolved_controls'],
			'query_probe'         => $query_probe,
			'warnings'            => array_values( array_unique( array_merge( $compiled_intent['warnings'], $query_probe['warnings'] ) ) ),
			'expected_readback'   => [
				'tree_hash'        => $after_hash,
				'parent_id'        => $parent_id,
				'widget_id'        => $widget_id,
				'widget_type'      => (string) $compiled_intent['widget_type'],
				'template_id'      => $compile_template_id,
				'template_control' => $template_control,
				'settings'         => $compiled_intent['settings'],
			],
		];
	}

	/**
	 * @param array<string, mixed> $plan
	 * @return int|\WP_Error
	 */
	private static function stage_template( array $plan, string $owner ): int|\WP_Error {
		if ( self::should_fail( 'template_create' ) ) {
			return self::phase_error( 'template_create' );
		}
		$template_id = TemplateStore::create_staged(
			(string) $plan['template_title'],
			'loop-item',
			$owner
		);
		if ( $template_id instanceof \WP_Error ) {
			return self::phase_error( 'template_create', 'none', 'not_required', $template_id->get_error_message() );
		}
		$snapshot_id = Backup::snapshot_post( $template_id );
		if ( '' === $snapshot_id || self::should_fail( 'template_write' ) ) {
			TemplateStore::delete_staged( $template_id, $owner );
			return self::phase_error( 'template_write', 'template_created', 'completed' );
		}
		if ( ! ElementorData::write( $template_id, $plan['template_tree'] ) ) {
			TemplateStore::delete_staged( $template_id, $owner );
			return self::phase_error( 'template_write', 'template_created', 'completed' );
		}
		if ( self::should_fail( 'template_readback' ) || ! hash_equals( TreeHasher::hash( $plan['template_tree'] ), TreeHasher::hash( ElementorData::read( $template_id ) ) ) ) {
			TemplateStore::delete_staged( $template_id, $owner );
			return self::phase_error( 'template_readback', 'template_written', 'completed' );
		}

		return $template_id;
	}

	/**
	 * @param array<string, mixed> $plan
	 * @return array<string, mixed>
	 */
	private static function planned_response( array $plan ): array {
		$resolved_settings = (array) $plan['expected_readback']['settings'];
		if ( 0 === (int) $plan['template_id'] ) {
			$template_control = (string) ( $plan['expected_readback']['template_control'] ?? '' );
			if ( '' !== $template_control ) {
				$resolved_settings[ $template_control ] = 0;
			}
		}

		return [
			'ok'                 => true,
			'status'             => 'planned',
			'post_id'            => (int) $plan['post_id'],
			'parent_id'          => (string) $plan['parent_id'],
			'template_id'        => (int) $plan['template_id'],
			'template_id_source' => 0 === (int) $plan['template_id'] ? 'transaction_created' : 'existing',
			'created_template'   => false,
			'widget_id'          => (string) $plan['widget_id'],
			'widget_type'        => (string) $plan['widget_type'],
			'snapshot_id'        => '',
			'before_hash'        => (string) $plan['before_hash'],
			'after_hash'         => (string) $plan['after_hash'],
			'schema_hash'        => (string) $plan['schema_hash'],
			'resolved_controls'  => $plan['resolved_controls'],
			'resolved_settings'  => $resolved_settings,
			'query_probe'        => $plan['query_probe'],
			'warnings'           => $plan['warnings'],
			'diff'               => [
				'action'      => 'add_widget',
				'parent_id'   => (string) $plan['parent_id'],
				'widget_id'   => (string) $plan['widget_id'],
				'widget_type' => (string) $plan['widget_type'],
			],
			'idempotent_replay'  => false,
		];
	}

	private static function rollback_error(
		\WP_Error $error,
		int $post_id,
		string $page_snapshot,
		int $template_id,
		string $owner,
		bool $created_template,
		string $phase
	): \WP_Error {
		$page_restored = '' === $page_snapshot || Backup::restore( $post_id, $page_snapshot );
		$template_removed = true;
		if ( $created_template && $template_id > 0 ) {
			$deleted          = TemplateStore::delete_staged( $template_id, $owner );
			$template_removed = true === $deleted;
		}
		$rollback_status = $page_restored && $template_removed ? 'completed' : 'failed';
		$data = array_merge(
			(array) $error->get_error_data(),
			[
				'transaction_phase' => $phase,
				'mutation_state'    => '' === $page_snapshot ? 'template_only' : 'page_written',
				'rollback_status'   => $rollback_status,
				'page_restored'     => $page_restored,
				'template_removed'  => $template_removed,
				'retryable'         => false,
				'repair'            => 'Read the current page and live loop widget schema before retrying.',
			]
		);

		return new \WP_Error( $error->get_error_code(), $error->get_error_message(), $data );
	}

	/** @param array<string, mixed> $extra */
	private static function phase_error(
		string $phase,
		string $mutation_state = 'none',
		string $rollback_status = 'not_started',
		string $message = '',
		array $extra = []
	): \WP_Error {
		return new \WP_Error(
			'stonewright_loop_transaction_failed',
			'' !== $message ? $message : sprintf( __( 'Loop transaction failed during %s.', 'stonewright' ), $phase ),
			array_merge(
				[
					'status'            => 500,
					'transaction_phase' => $phase,
					'mutation_state'    => $mutation_state,
					'rollback_status'   => $rollback_status,
					'retryable'         => false,
					'repair'            => 'Read the current page and live loop widget schema before retrying.',
				],
				$extra
			)
		);
	}

	private static function should_fail( string $phase ): bool {
		return '' !== self::$fail_at && self::$fail_at === $phase;
	}

	/**
	 * @param array<string, mixed> $args
	 * @return array<string, mixed>
	 */
	private static function request_payload( array $args ): array {
		unset( $args['confirmation_token'], $args['stonewright_context_token'], $args['dry_run'] );
		ksort( $args );

		return $args;
	}
}
