<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Gutenberg;

use Stonewright\WpMcp\Security\Backup;

/**
 * Skeleton multi-target FSE transaction queue.
 *
 * Supports ordered write targets (template / template_part / global_styles /
 * post content). Each write snapshots the post first. Full apply/rollback
 * wiring lands with the precision engine; this class establishes the contract.
 *
 * @phpstan-type Target array{
 *   type: 'template'|'template_part'|'global_styles'|'post',
 *   post_id: int,
 *   content?: string,
 *   label?: string
 * }
 */
final class FseTransactionQueue {

	/** @var list<Target> */
	private array $targets = [];

	/** @var list<array{post_id: int, snapshot_id: string, label: string}> */
	private array $snapshots = [];

	private bool $stop_on_error = true;

	private bool $rollback_on_error = true;

	public function stop_on_error( bool $stop ): self {
		$this->stop_on_error = $stop;
		return $this;
	}

	public function rollback_on_error( bool $rollback ): self {
		$this->rollback_on_error = $rollback;
		return $this;
	}

	/**
	 * @param Target $target
	 */
	public function enqueue( array $target ): self {
		$this->targets[] = $target;
		return $this;
	}

	/**
	 * @return list<Target>
	 */
	public function targets(): array {
		return $this->targets;
	}

	/**
	 * Dry structural validation of the queue (no writes).
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function validate() {
		if ( [] === $this->targets ) {
			return new \WP_Error(
				'stonewright_fse_txn_empty',
				__( 'FSE transaction queue has no targets.', 'stonewright' ),
				[ 'status' => 400 ]
			);
		}

		$allowed = [ 'template', 'template_part', 'global_styles', 'post' ];
		foreach ( $this->targets as $index => $target ) {
			$type = (string) ( $target['type'] ?? '' );
			if ( ! in_array( $type, $allowed, true ) ) {
				return new \WP_Error(
					'stonewright_fse_txn_invalid_target',
					sprintf(
						/* translators: %d: target index */
						__( 'FSE transaction target %d has an invalid type.', 'stonewright' ),
						(int) $index
					),
					[ 'status' => 400, 'index' => (int) $index ]
				);
			}
			if ( (int) ( $target['post_id'] ?? 0 ) < 1 ) {
				return new \WP_Error(
					'stonewright_fse_txn_invalid_post',
					sprintf(
						/* translators: %d: target index */
						__( 'FSE transaction target %d requires a positive post_id.', 'stonewright' ),
						(int) $index
					),
					[ 'status' => 400, 'index' => (int) $index ]
				);
			}
		}

		return [
			'ok'                => true,
			'target_count'      => count( $this->targets ),
			'stop_on_error'     => $this->stop_on_error,
			'rollback_on_error' => $this->rollback_on_error,
			'targets'           => array_map(
				static fn( array $t ): array => [
					'type'    => (string) $t['type'],
					'post_id' => (int) $t['post_id'],
					'label'   => (string) ( $t['label'] ?? $t['type'] ),
				],
				$this->targets
			),
		];
	}

	/**
	 * Snapshot every target before any content write (skeleton apply).
	 *
	 * Does not yet mutate content — establishes snapshot handles for the
	 * forthcoming multi-target precision writer.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function snapshot_all() {
		$validated = $this->validate();
		if ( $validated instanceof \WP_Error ) {
			return $validated;
		}

		$this->snapshots = [];
		foreach ( $this->targets as $target ) {
			$post_id = (int) $target['post_id'];
			if ( ! get_post( $post_id ) ) {
				$error = new \WP_Error(
					'stonewright_fse_txn_not_found',
					sprintf(
						/* translators: %d: post id */
						__( 'FSE transaction target post %d was not found.', 'stonewright' ),
						$post_id
					),
					[ 'status' => 404, 'post_id' => $post_id ]
				);
				if ( $this->rollback_on_error ) {
					$this->rollback();
				}
				return $error;
			}
			$snapshot_id = Backup::snapshot_post( $post_id );
			if ( '' === $snapshot_id ) {
				$error = new \WP_Error(
					'stonewright_fse_txn_snapshot_failed',
					sprintf(
						/* translators: %d: post id */
						__( 'Could not snapshot FSE target post %d.', 'stonewright' ),
						$post_id
					),
					[ 'status' => 500, 'post_id' => $post_id ]
				);
				if ( $this->rollback_on_error ) {
					$this->rollback();
				}
				return $error;
			}
			$this->snapshots[] = [
				'post_id'     => $post_id,
				'snapshot_id' => $snapshot_id,
				'label'       => (string) ( $target['label'] ?? $target['type'] ),
			];
		}

		return [
			'ok'        => true,
			'snapshots' => $this->snapshots,
			'phase'     => 'snapshotted',
			'note'      => 'Content apply is intentionally not performed by this skeleton; use WriteTemplate/WriteGlobalStyles after snapshot handles are recorded, or the future fse-transaction-run ability.',
		];
	}

	/**
	 * Restore all recorded snapshots (LIFO).
	 *
	 * @return array{ok: bool, restored: list<array{post_id: int, snapshot_id: string, ok: bool}>}
	 */
	public function rollback(): array {
		$restored = [];
		foreach ( array_reverse( $this->snapshots ) as $row ) {
			$ok         = Backup::restore( (int) $row['post_id'], (string) $row['snapshot_id'] );
			$restored[] = [
				'post_id'     => (int) $row['post_id'],
				'snapshot_id' => (string) $row['snapshot_id'],
				'ok'          => $ok,
			];
		}
		return [
			'ok'       => ! in_array( false, array_column( $restored, 'ok' ), true ),
			'restored' => $restored,
		];
	}

	/**
	 * @return list<array{post_id: int, snapshot_id: string, label: string}>
	 */
	public function snapshots(): array {
		return $this->snapshots;
	}
}
