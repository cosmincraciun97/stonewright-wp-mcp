<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\ElementorV3;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Elementor\Schema\LegacyDebtMigrator;
use Stonewright\WpMcp\Security\ConfirmationToken;
use Stonewright\WpMcp\Security\Permissions;
use Stonewright\WpMcp\Support\ElementorData;
use Stonewright\WpMcp\Support\Json;

/** Explicit dry-run/apply path for mapped Elementor legacy settings. */
final class LegacyDebtMigrate extends AbilityKernel {

	public function name(): string {
		return 'stonewright/elementor-v3-legacy-debt-migrate';
	}

	public function label(): string {
		return __( 'Migrate mapped Elementor legacy debt', 'stonewright' );
	}

	public function description(): string {
		return __( 'Plans and applies only explicit live-schema-backed Elementor legacy normalizations, through the transactional V3 batch writer.', 'stonewright' );
	}

	public function category(): string {
		return 'elementor';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => [ 'post_id', 'element_id', 'paths' ],
			'properties'           => [
				'post_id'    => [ 'type' => 'integer', 'minimum' => 1 ],
				'element_id' => [ 'type' => 'string', 'minLength' => 1, 'maxLength' => 96 ],
				'paths'      => [
					'type'     => 'array',
					'minItems' => 1,
					'maxItems' => 50,
					'items'    => [ 'type' => 'string', 'minLength' => 1, 'maxLength' => 255 ],
				],
				'action'             => [ 'type' => 'string', 'enum' => [ 'dry_run', 'apply' ], 'default' => 'dry_run' ],
				'expected_tree_hash' => [ 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$' ],
				'approved_plan_hash' => [ 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$' ],
				'idempotency_key'    => [ 'type' => 'string', 'minLength' => 8, 'maxLength' => 128 ],
				'change_set_id'      => [ 'type' => 'string', 'maxLength' => 96 ],
				'confirmation_token' => [ 'type' => 'string' ],
			],
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'ok'                       => [ 'type' => 'boolean' ],
				'action'                   => [ 'type' => 'string' ],
				'post_id'                  => [ 'type' => 'integer' ],
				'element_id'               => [ 'type' => 'string' ],
				'widget_type'              => [ 'type' => 'string' ],
				'schema_hash'              => [ 'type' => 'string' ],
				'before_tree_hash'         => [ 'type' => 'string' ],
				'plan_hash'                => [ 'type' => 'string' ],
				'issue_count'              => [ 'type' => 'integer' ],
				'safe_migration_count'     => [ 'type' => 'integer' ],
				'unavailable_count'        => [ 'type' => 'integer' ],
				'issues'                   => [ 'type' => 'array' ],
				'operations_count'         => [ 'type' => 'integer' ],
				'batch'                    => [ 'type' => 'object' ],
				'write_performed'          => [ 'type' => 'boolean' ],
				'apply_contract'           => [ 'type' => 'object' ],
				'delegated_audit_ability'  => [ 'type' => 'string' ],
				'write_receipt'            => [ 'type' => 'object' ],
				'verification_status'      => [ 'type' => 'string' ],
			],
			'required'   => [ 'ok', 'action', 'post_id', 'element_id', 'widget_type', 'schema_hash', 'before_tree_hash', 'plan_hash', 'issue_count', 'safe_migration_count', 'unavailable_count', 'issues', 'operations_count', 'batch', 'write_performed', 'apply_contract', 'delegated_audit_ability', 'write_receipt', 'verification_status' ],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::edit_post( (int) ( $args['post_id'] ?? 0 ) );
	}

	public function execute( array $args ): array|\WP_Error {
		$post_id    = (int) ( $args['post_id'] ?? 0 );
		$element_id = sanitize_key( (string) ( $args['element_id'] ?? '' ) );
		$paths      = array_values( array_filter( array_map( static fn ( mixed $path ): string => is_scalar( $path ) ? mb_substr( trim( (string) $path ), 0, 255 ) : '', (array) ( $args['paths'] ?? [] ) ) ) );
		$action     = sanitize_key( (string) ( $args['action'] ?? 'dry_run' ) );
		if ( ! get_post( $post_id ) ) {
			return new \WP_Error( 'stonewright_not_found', __( 'Post not found.', 'stonewright' ), [ 'status' => 404 ] );
		}
		if ( '' === $element_id || [] === $paths || ! in_array( $action, [ 'dry_run', 'apply' ], true ) ) {
			return new \WP_Error( 'stonewright_legacy_migration_request_invalid', __( 'Provide one element, at least one setting path, and a valid action.', 'stonewright' ), [ 'status' => 400 ] );
		}

		$tree = ElementorData::read( $post_id );
		$plan = LegacyDebtMigrator::plan( $tree, $element_id, $paths );
		if ( $plan instanceof \WP_Error ) {
			return $plan;
		}
		$plan_hash = self::plan_hash( $post_id, $paths, $plan );
		$operations = is_array( $plan['operations'] ?? null ) ? $plan['operations'] : [];
		$safe_count = count( array_filter( (array) ( $plan['issues'] ?? [] ), static fn ( mixed $issue ): bool => is_array( $issue ) && ! empty( $issue['safe_migration_available'] ) ) );

		if ( 'dry_run' === $action ) {
			$batch = [];
			if ( [] !== $operations ) {
				$batch = ( new BatchMutate() )->execute(
					[
						'post_id'           => $post_id,
						'dry_run'           => true,
						'expected_tree_hash' => (string) $plan['before_tree_hash'],
						'require_evidence'   => true,
						'stop_on_error'      => true,
						'change_set_id'      => self::change_set_id( $args, $plan_hash ),
						'operations'         => $operations,
					]
				);
				if ( $batch instanceof \WP_Error ) {
					return $batch;
				}
			}
			return self::response( 'dry_run', $post_id, $plan, $plan_hash, $safe_count, $batch, false );
		}

		$expected_tree_hash = trim( (string) ( $args['expected_tree_hash'] ?? '' ) );
		$approved_plan_hash = trim( (string) ( $args['approved_plan_hash'] ?? '' ) );
		$idempotency_key    = trim( (string) ( $args['idempotency_key'] ?? '' ) );
		if ( [] === $operations ) {
			return new \WP_Error( 'stonewright_legacy_migration_unavailable', __( 'None of the requested legacy paths has an explicit safe migration.', 'stonewright' ), [ 'status' => 409, 'issues' => $plan['issues'] ] );
		}
		if ( ! hash_equals( (string) $plan['before_tree_hash'], $expected_tree_hash ) ) {
			return new \WP_Error( 'stonewright_legacy_migration_tree_conflict', __( 'The Elementor tree changed after the reviewed dry run.', 'stonewright' ), [ 'status' => 409, 'current_tree_hash' => $plan['before_tree_hash'], 'retryable' => true ] );
		}
		if ( ! hash_equals( $plan_hash, $approved_plan_hash ) ) {
			return new \WP_Error( 'stonewright_legacy_migration_plan_conflict', __( 'The current legacy migration plan does not match the reviewed plan.', 'stonewright' ), [ 'status' => 409, 'current_plan_hash' => $plan_hash, 'retryable' => true ] );
		}
		if ( strlen( $idempotency_key ) < 8 ) {
			return new \WP_Error( 'stonewright_idempotency_key_required', __( 'Apply requires an idempotency key of at least eight characters.', 'stonewright' ), [ 'status' => 400 ] );
		}
		if ( 'production-safe' === (string) get_option( 'stonewright_mode', 'development' ) ) {
			$verify_args = array_filter( $args, static fn ( string $key ): bool => 'confirmation_token' !== $key, ARRAY_FILTER_USE_KEY );
			$token = (string) ( $args['confirmation_token'] ?? '' );
			if ( '' === $token ) {
				return new \WP_Error( 'stonewright_confirmation_required', __( 'Production-safe mode requires a confirmation_token.', 'stonewright' ), [ 'status' => 403 ] );
			}
			$verified = ConfirmationToken::verify_or_error( $token, $this->name(), $verify_args );
			if ( $verified instanceof \WP_Error ) {
				return $verified;
			}
		}

		$batch = ( new BatchMutate() )->execute(
			[
				'post_id'           => $post_id,
				'dry_run'           => false,
				'idempotency_key'    => $idempotency_key,
				'expected_tree_hash' => $expected_tree_hash,
				'require_evidence'   => true,
				'stop_on_error'      => true,
				'change_set_id'      => self::change_set_id( $args, $plan_hash ),
				'operations'         => $operations,
			]
		);
		if ( $batch instanceof \WP_Error ) {
			return $batch;
		}
		return self::response( 'apply', $post_id, $plan, $plan_hash, $safe_count, $batch, true );
	}

	/** @param array<string,mixed> $plan @param array<string,mixed> $batch @return array<string,mixed> */
	private static function response( string $action, int $post_id, array $plan, string $plan_hash, int $safe_count, array $batch, bool $write_performed ): array {
		$before_tree_hash = (string) ( $plan['before_tree_hash'] ?? '' );
		return [
			'ok'                      => true,
			'action'                  => $action,
			'post_id'                 => $post_id,
			'element_id'              => (string) ( $plan['element_id'] ?? '' ),
			'widget_type'             => (string) ( $plan['widget_type'] ?? '' ),
			'schema_hash'             => (string) ( $plan['schema_hash'] ?? '' ),
			'before_tree_hash'        => $before_tree_hash,
			'plan_hash'               => $plan_hash,
			'issue_count'             => count( (array) ( $plan['issues'] ?? [] ) ),
			'safe_migration_count'    => $safe_count,
			'unavailable_count'       => (int) ( $plan['unavailable_count'] ?? 0 ),
			'issues'                  => array_values( (array) ( $plan['issues'] ?? [] ) ),
			'operations_count'        => count( (array) ( $plan['operations'] ?? [] ) ),
			'batch'                   => $batch,
			'write_performed'         => $write_performed,
			'apply_contract'          => [
				'action'             => 'apply',
				'expected_tree_hash' => $before_tree_hash,
				'approved_plan_hash' => $plan_hash,
				'idempotency_key_required' => true,
			],
			'delegated_audit_ability' => [] === $batch ? '' : 'stonewright/elementor-v3-batch-mutate',
			'write_receipt'           => is_array( $batch['write_receipt'] ?? null ) ? $batch['write_receipt'] : [],
			'verification_status'     => (string) ( $batch['verification_status'] ?? ( 'dry_run' === $action ? 'not_applicable' : 'passed' ) ),
		];
	}

	/** @param list<string> $paths @param array<string,mixed> $plan */
	private static function plan_hash( int $post_id, array $paths, array $plan ): string {
		return Json::hash(
			[
				'post_id'          => $post_id,
				'element_id'       => (string) ( $plan['element_id'] ?? '' ),
				'paths'            => array_values( $paths ),
				'schema_hash'      => (string) ( $plan['schema_hash'] ?? '' ),
				'before_tree_hash' => (string) ( $plan['before_tree_hash'] ?? '' ),
				'operations'       => (array) ( $plan['operations'] ?? [] ),
			]
		);
	}

	private static function change_set_id( array $args, string $plan_hash ): string {
		$value = sanitize_text_field( (string) ( $args['change_set_id'] ?? '' ) );
		return '' !== $value ? mb_substr( $value, 0, 96 ) : 'legacy-' . substr( $plan_hash, 0, 24 );
	}
}
