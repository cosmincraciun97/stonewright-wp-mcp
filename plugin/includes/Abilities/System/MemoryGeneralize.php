<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\System;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Abilities\Common\ConfirmationGuard;
use Stonewright\WpMcp\Memory\Memory;
use Stonewright\WpMcp\Memory\Scrubber;
use Stonewright\WpMcp\Security\Permissions;

/**
 * Rewrites stored memory into portable lessons, one reviewed batch at a time.
 *
 * Stored memory is instruction: every row is read back to an agent as context.
 * A bulk rewrite of it therefore gets the same treatment as any other bulk
 * mutation — it reports before it writes, it walks the table with an explicit
 * cursor instead of claiming one page covered everything, and in production-safe
 * mode an apply needs a confirmation token issued for these exact arguments.
 *
 * Deletion is deliberately not implemented here. Rows whose only content was the
 * site's own identity come back as `review_for_deletion` proposals; removing
 * stored context stays an explicit act through `stonewright/memory-delete` with
 * reviewed ids.
 *
 * @stonewright-status stable
 */
final class MemoryGeneralize extends AbilityKernel {

	use ConfirmationGuard;

	/** Largest batch a single call will scan. */
	private const MAX_LIMIT = 200;

	/** Default batch size. */
	private const DEFAULT_LIMIT = 100;

	public function name(): string {
		return 'stonewright/memory-generalize';
	}

	public function label(): string {
		return __( 'Generalize stored memory', 'stonewright' );
	}

	public function description(): string {
		return __( 'Reports, and on request applies, de-identification of stored memory in bounded batches. Defaults to a dry run. Continue with next_cursor until done is true. In production-safe mode an apply requires a confirmation token issued for the same arguments.', 'stonewright' );
	}

	public function category(): string {
		return 'memory';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'apply'              => [
					'type'        => 'boolean',
					'description' => 'Write the proposed changes. Defaults to false (report only).',
				],
				'limit'              => [
					'type'        => 'integer',
					'minimum'     => 1,
					'maximum'     => self::MAX_LIMIT,
					'description' => 'Rows to scan in this batch.',
				],
				'cursor'             => [
					'type'        => [ 'string', 'null' ],
					'description' => 'Opaque cursor from a previous next_cursor. Omit to start at the beginning.',
				],
				'confirmation_token' => [
					'type'        => 'string',
					'description' => 'Required for apply in production-safe mode. Issue it for the same apply, limit, and cursor values.',
				],
			],
			'required'             => [],
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'applied'     => [ 'type' => 'boolean' ],
				'scanned'     => [ 'type' => 'integer' ],
				'changed'     => [ 'type' => 'integer' ],
				'proposals'   => [
					'type'  => 'array',
					'items' => [
						'type'       => 'object',
						'properties' => [
							'id'      => [ 'type' => 'integer' ],
							'action'  => [ 'type' => 'string' ],
							'reason'  => [ 'type' => 'string' ],
							'changes' => [ 'type' => 'object' ],
							'before'  => [ 'type' => 'string' ],
							'after'   => [ 'type' => 'string' ],
						],
						'required'   => [ 'id', 'action', 'reason', 'before', 'after' ],
					],
				],
				'next_cursor' => [ 'type' => [ 'string', 'null' ] ],
				'done'        => [ 'type' => 'boolean' ],
			],
			'required'   => [ 'applied', 'scanned', 'changed', 'proposals', 'next_cursor', 'done' ],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::manage_options();
	}

	public function execute( array $args ): array|\WP_Error {
		$apply = isset( $args['apply'] ) && true === $args['apply'];

		if ( $apply ) {
			// Verified inside execute rather than through Permissions::destructive(),
			// which refuses production-safe writes before a token can be checked.
			$refusal = $this->confirmation_token_error( $args, $args );
			if ( null !== $refusal ) {
				return $refusal;
			}
		}

		return $this->audit(
			$args,
			function ( array $a ) use ( $apply ): array|\WP_Error {
				$limit  = isset( $a['limit'] ) ? max( 1, min( self::MAX_LIMIT, (int) $a['limit'] ) ) : self::DEFAULT_LIMIT;
				$offset = self::decode_cursor( $a['cursor'] ?? null );

				$rows      = Memory::list_all( $limit, $offset );
				$scanned   = count( $rows );
				$proposals = [];
				$changed   = 0;
				$failed    = [];

				foreach ( $rows as $row ) {
					$plan = Scrubber::plan( $row );
					if ( null === $plan ) {
						continue;
					}

					$proposals[] = $plan;

					if ( ! $apply || [] === $plan['changes'] ) {
						continue;
					}

					if ( Memory::update_by_id( (int) $plan['id'], $plan['changes'] ) ) {
						++$changed;
					} else {
						$failed[] = (int) $plan['id'];
					}
				}

				// A full page proves nothing about what follows it, so the cursor
				// stays open until a short page shows the table is exhausted.
				$done        = $scanned < $limit;
				$next_cursor = $done ? null : (string) ( $offset + $scanned );

				if ( [] !== $failed ) {
					return new \WP_Error(
						'stonewright_memory_generalize_partial_failure',
						__( 'Some memory entries could not be generalized. Review the failed ids before continuing.', 'stonewright' ),
						[
							'status'      => 500,
							'changed'     => $changed,
							'failed_ids'  => $failed,
							'next_cursor' => $next_cursor,
							'done'        => $done,
						]
					);
				}

				return [
					'applied'     => $apply,
					'scanned'     => $scanned,
					'changed'     => $changed,
					'proposals'   => $proposals,
					'next_cursor' => $next_cursor,
					'done'        => $done,
				];
			}
		);
	}

	/**
	 * Cursors are opaque to callers but are plain offsets internally.
	 *
	 * Offsets are safe here because the sweep only updates rows; it never deletes
	 * or inserts, so the ordering by descending id does not shift under it. Rows
	 * returned as `review_for_deletion` must therefore be deleted after the sweep
	 * finishes, not between batches.
	 */
	private static function decode_cursor( mixed $cursor ): int {
		if ( ! is_scalar( $cursor ) ) {
			return 0;
		}

		return max( 0, (int) $cursor );
	}
}
