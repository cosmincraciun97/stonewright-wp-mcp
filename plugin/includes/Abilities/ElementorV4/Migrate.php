<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\ElementorV4;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Abilities\Common\ConfirmationGuard;
use Stonewright\WpMcp\Elementor\V4\MigrationPlanner;
use Stonewright\WpMcp\Elementor\V4\V4FeatureGate;
use Stonewright\WpMcp\Elementor\Write\PostWriteLock;
use Stonewright\WpMcp\Elementor\Write\TreeHasher;
use Stonewright\WpMcp\Security\Backup;
use Stonewright\WpMcp\Security\Permissions;
use Stonewright\WpMcp\Support\ElementorData;

/** @stonewright-status experimental */
final class Migrate extends AbilityKernel {

	use ConfirmationGuard;

	public function name(): string {
		return 'stonewright/elementor-v4-migrate';
	}

	public function label(): string {
		return __( 'Plan or apply Elementor V3 to V4 migration', 'stonewright' );
	}

	public function description(): string {
		return __( 'Inventories a page, returns an explicit loss report, and applies only a zero-loss V4 migration after approval. An apply needs confirm_migration=true after the loss report was reviewed, and in production-safe mode a confirmation_token bound to the post and the other arguments. It takes the page write lock, refuses a page that changed since the plan, snapshots first, and reports rollback_status when a write has to be undone.', 'stonewright' );
	}

	public function category(): string {
		return 'elementor';
	}

	public function meta(): array {
		return [
			'experimental' => true,
			'annotations'  => [ 'destructive' => true ],
		];
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => [ 'post_id' ],
			'properties'           => [
				'post_id'            => [ 'type' => 'integer', 'minimum' => 1 ],
				'apply'              => [ 'type' => 'boolean', 'default' => false ],
				'confirm_migration'  => [ 'type' => 'boolean', 'description' => 'Must be true to apply, after the loss report was reviewed.' ],
				'confirmation_token' => [ 'type' => 'string', 'description' => 'Production-safe mode only: a token from stonewright/security-issue-confirmation-token for this ability, bound to post_id and every other argument.' ],
			],
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'applied'             => [ 'type' => 'boolean' ],
				'write_ready'         => [ 'type' => 'boolean' ],
				'converted_tree'      => [ 'type' => 'array' ],
				'loss_report'         => [ 'type' => 'array' ],
				'counts'              => [ 'type' => 'object' ],
				'snapshot_id'         => [ 'type' => 'string' ],
				'readback_hash'       => [ 'type' => 'string' ],
				'before_hash'         => [ 'type' => 'string' ],
				'rollback_status'     => [ 'type' => 'string', 'enum' => [ 'not_attempted', 'succeeded', 'failed' ] ],
				'implicit_conversion' => [ 'type' => 'boolean' ],
				'schema_fingerprint'  => [ 'type' => 'string' ],
			],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		$gate = V4FeatureGate::check( (bool) ( $args['apply'] ?? false ) );
		return is_wp_error( $gate ) ? $gate : Permissions::edit_post( (int) ( $args['post_id'] ?? 0 ) );
	}

	public function execute( array $args ): array|\WP_Error {
		return $this->audit(
			$args,
			function ( array $args ): array|\WP_Error {
				$post_id = (int) $args['post_id'];
				if ( ! get_post( $post_id ) ) {
					return $this->error( 'not_found', __( 'Post not found.', 'stonewright' ) );
				}
				$original                = ElementorData::read( $post_id );
				$before_hash             = TreeHasher::hash( $original );
				$plan                    = MigrationPlanner::plan( $original );
				$plan['applied']         = false;
				$plan['snapshot_id']     = '';
				$plan['readback_hash']   = '';
				$plan['before_hash']     = $before_hash;
				$plan['rollback_status'] = 'not_attempted';
				if ( ! (bool) ( $args['apply'] ?? false ) ) {
					return $plan;
				}
				if ( true !== ( $args['confirm_migration'] ?? false ) ) {
					return $this->error( 'v4_migration_confirmation_required', __( 'Set confirm_migration=true after reviewing the loss report.', 'stonewright' ) );
				}
				if ( ! $plan['write_ready'] ) {
					return $this->error( 'v4_migration_has_loss', __( 'Migration is blocked because at least one element lacks a lossless V4 mapping.', 'stonewright' ), [ 'loss_report' => $plan['loss_report'] ] );
				}
				// Every refusal above comes before the token is looked at, so a refused call never spends one.
				$verify_args = array_filter(
					$args,
					static fn( string $key ): bool => 'confirmation_token' !== $key,
					ARRAY_FILTER_USE_KEY
				);
				$token_error = $this->confirmation_token_error( $args, $verify_args );
				if ( $token_error instanceof \WP_Error ) {
					return $token_error;
				}

				$owner = 'v4-migrate-' . substr( hash( 'sha256', $post_id . '|' . $before_hash . '|' . hrtime( true ) ), 0, 24 );
				$lease = PostWriteLock::acquire( $post_id, $owner );
				if ( $lease instanceof \WP_Error ) {
					return $lease;
				}
				try {
					return $this->apply( $post_id, $plan, $before_hash, $owner );
				} finally {
					PostWriteLock::release( $post_id, $owner );
				}
			}
		);
	}

	/**
	 * The write, under the page lock: the page must still be the one that was planned, then snapshot, write and readback.
	 *
	 * @param array<string, mixed> $plan
	 * @return array<string, mixed>|\WP_Error
	 */
	private function apply( int $post_id, array $plan, string $before_hash, string $owner ): array|\WP_Error {
		$current = TreeHasher::hash( ElementorData::read( $post_id ) );
		if ( ! hash_equals( $before_hash, $current ) ) {
			return $this->error(
				'elementor_revision_conflict',
				__( 'The page changed before the migration took its write lock; plan it again and retry.', 'stonewright' ),
				[ 'status' => 409, 'expected_tree_hash' => $before_hash, 'current_tree_hash' => $current, 'retryable' => true ]
			);
		}
		$snapshot_id = Backup::snapshot_post( $post_id );
		if ( '' === $snapshot_id ) {
			return $this->backup_failed_error();
		}
		$tree = (array) $plan['converted_tree'];
		if ( ! ElementorData::write( $post_id, $tree, [ 'lock_owner' => $owner ] ) ) {
			return $this->rolled_back_error( $post_id, $snapshot_id, 'v4_migration_write_failed', __( 'Migration write failed', 'stonewright' ) );
		}
		$readback_hash = self::hash( ElementorData::read( $post_id ) );
		if ( self::hash( $tree ) !== $readback_hash ) {
			return $this->rolled_back_error( $post_id, $snapshot_id, 'v4_migration_readback_failed', __( 'Migration readback differed', 'stonewright' ) );
		}
		$plan['applied']       = true;
		$plan['snapshot_id']   = $snapshot_id;
		$plan['readback_hash'] = $readback_hash;
		return $plan;
	}

	/** The error of a failed migration write, after the snapshot was restored; rollback_status says whether that worked. */
	private function rolled_back_error( int $post_id, string $snapshot_id, string $code, string $what ): \WP_Error {
		$restored = Backup::restore( $post_id, $snapshot_id );
		return $this->error(
			$code,
			$what . ( $restored ? ' and the snapshot was restored.' : ', and the snapshot could not be restored; restore it by hand.' ),
			[
				'status'          => 500,
				'snapshot_id'     => $snapshot_id,
				'rollback_status' => $restored ? 'succeeded' : 'failed',
			]
		);
	}

	/** @param array<int, mixed> $tree */
	private static function hash( array $tree ): string {
		$json = wp_json_encode( $tree, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return hash( 'sha256', false === $json ? '' : $json );
	}
}
