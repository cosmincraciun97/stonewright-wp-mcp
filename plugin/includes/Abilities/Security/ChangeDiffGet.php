<?php
/**
 * The diff of one change and what an undo of it would do.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Security;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Security\ChangeLedger;
use Stonewright\WpMcp\Security\ChangeRollback;
use Stonewright\WpMcp\Security\Permissions;
use Stonewright\WpMcp\Support\ChangeHistoryView;
use Stonewright\WpMcp\Support\Diff\ChangeDiff;

/**
 * The diff of one change as the Changes page shows it: masked, capped, and never a stored image. Beside it, a summary of the
 * plan of an undo: whether the item changed since, what changed it, whether it can be restored and whether an administrator
 * is needed. The plan is read without an audit row and writes nothing.
 *
 * @stonewright-status stable
 */
final class ChangeDiffGet extends AbilityKernel {

	public function name(): string {
		return 'stonewright/change-diff-get';
	}

	public function label(): string {
		return __( 'Change diff', 'stonewright' );
	}

	public function description(): string {
		return __( 'Returns the diff of one change by change_id: the lines, blocks, elements or fields that differ between the content before and after it, with secrets masked and the size capped (max_lines, default 400; truncated says when anything was left out). Beside it is the summary of what an undo would do: restorable, drift (the item was edited after the change), newer changes to the same item, whether an administrator is needed (approval_required, for code) and whether a confirmation token is needed. Read-only; it never returns a stored copy of the content. Needs manage_options.', 'stonewright' );
	}

	public function category(): string {
		return 'security';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'change_id' => [
					'type'        => 'string',
					'pattern'     => '^cs-[a-f0-9]{24}$',
					'description' => 'The change id from stonewright-change-history-list.',
				],
				'max_lines' => [
					'type'        => 'integer',
					'minimum'     => ChangeHistoryView::MIN_DIFF_LINES,
					'maximum'     => ChangeHistoryView::MAX_DIFF_LINES,
					'default'     => ChangeHistoryView::DEFAULT_DIFF_LINES,
					'description' => 'Most lines of text diff to return.',
				],
			],
			'required'             => [ 'change_id' ],
		];
	}

	public function output_schema(): array {
		$row = ( new ChangeHistoryList() )->output_schema()['properties']['items']['items'];
		return [
			'type'       => 'object',
			'properties' => [
				'ok'     => [ 'type' => 'boolean' ],
				'change' => $row,
				'diff'   => [
					'type'        => 'object',
					'description' => 'status ok, before_only, no_images or unreadable; message when there is nothing to compare; sections of text hunks, block items, element changes or field changes; changed, truncated, masked (values replaced by [redacted], including values the ledger masked when it stored them), image_masked, and deleted (the change removed the resource: the diff is the content before against nothing).',
					'properties'  => [
						'status'       => [ 'type' => 'string' ],
						'message'      => [ 'type' => 'string' ],
						'sections'     => [ 'type' => 'array' ],
						'changed'      => [ 'type' => 'boolean' ],
						'truncated'    => [ 'type' => 'boolean' ],
						'masked'       => [ 'type' => 'integer' ],
						'image_masked' => [ 'type' => 'boolean' ],
						'deleted'      => [ 'type' => 'boolean' ],
					],
				],
				'plan'   => [
					'type'        => 'object',
					'description' => 'What an undo (or a redo, for a rollback row) would do. available is false with error_code and message when it cannot run, for example stonewright_change_not_restorable or stonewright_change_already_rolled_back (redo_change_id names the rollback to redo).',
					'properties'  => [
						'available'             => [ 'type' => 'boolean' ],
						'restorable'            => [ 'type' => 'boolean' ],
						'restorable_reason'     => [ 'type' => 'string' ],
						'kind'                  => [ 'type' => 'string', 'enum' => [ 'rollback', 'redo' ] ],
						'path'                  => [ 'type' => 'string' ],
						'approval_required'     => [ 'type' => 'boolean', 'description' => 'True for code: only an administrator at wp-admin can undo it.' ],
						'approval_url'          => [ 'type' => 'string' ],
						'drift'                 => [ 'type' => 'boolean' ],
						'drift_known'           => [ 'type' => 'boolean' ],
						'requires_force'        => [ 'type' => 'boolean' ],
						'already_restored'      => [ 'type' => 'boolean' ],
						'newer_changes'         => [
							'type'  => 'array',
							'items' => [
								'type'       => 'object',
								'properties' => [
									'change_id' => [ 'type' => 'string' ],
									'ability'   => [ 'type' => 'string' ],
									'since'     => [ 'type' => 'string' ],
								],
							],
						],
						'warnings'              => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
						'confirmation_required' => [ 'type' => 'boolean' ],
						'would_apply'           => [ 'type' => 'string' ],
						'current_sha256'        => [ 'type' => 'string', 'description' => 'The start of the hash of the item as it is now; pass it as expected_current_sha256 to undo exactly this state.' ],
						'error_code'            => [ 'type' => 'string' ],
						'message'               => [ 'type' => 'string' ],
						'redo_change_id'        => [ 'type' => 'string' ],
					],
					'required'    => [ 'available', 'restorable' ],
				],
			],
			'required'   => [ 'ok', 'change', 'diff', 'plan' ],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::manage_options();
	}

	public function execute( array $args ): array|\WP_Error {
		return $this->audit_read(
			$args,
			function ( array $a ): array|\WP_Error {
				if ( ! Permissions::manage_options() ) {
					return new \WP_Error( 'stonewright_change_forbidden', __( 'Only an administrator can read the change history.', 'stonewright' ), [ 'status' => 403 ] );
				}
				$id = isset( $a['change_id'] ) && is_string( $a['change_id'] ) ? $a['change_id'] : '';
				if ( ! ChangeLedger::is_valid_id( $id ) ) {
					return new \WP_Error( 'stonewright_change_invalid_id', __( 'That is not a change id.', 'stonewright' ), [ 'status' => 400 ] );
				}
				$lines = ChangeHistoryView::diff_lines( $a );
				if ( $lines instanceof \WP_Error ) {
					return $lines;
				}
				$row = ChangeLedger::get( $id );
				if ( null === $row ) {
					return new \WP_Error( 'stonewright_change_not_found', __( 'No change in the history has that id. Retention may have removed it.', 'stonewright' ), [ 'status' => 404 ] );
				}

				return $this->ok(
					[
						'change' => ChangeHistoryView::row( $row ),
						'diff'   => ChangeDiff::for_row( $row, ChangeHistoryView::diff_options( $lines ) ),
						'plan'   => ChangeHistoryView::plan( ChangeRollback::plan( $id ), $row ),
					]
				);
			}
		);
	}
}
