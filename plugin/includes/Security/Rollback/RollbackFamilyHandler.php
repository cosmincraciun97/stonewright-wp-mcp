<?php
/**
 * What the rollback engine asks of the code that knows one family of resources.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Security\Rollback;

/**
 * A handler serves one or more ledger families (ChangeLedger::FAMILIES). The engine (ChangeRollback) owns every
 * gate: permission, production-safe token, the approval a person gives for code, drift, newer changes, the claim,
 * the health probe, the audit and the ledger rows. A handler only reads and writes the resource, and it checks no
 * permission, token or newer change.
 *
 * The method live_image() returns the resource as it is now in the shape of the images the ledger stores for the family
 * (string or array, unmasked: the engine masks before it compares or shows anything), null when the resource does
 * not exist, or a WP_Error when it cannot be read. A handler that cannot read the live state returns a WP_Error
 * with the code LIVE_UNSUPPORTED: the engine then has no diff and cannot tell drift, and says so.
 *
 * The method restore() puts the resource into the state of $image (null: remove what the change created) and returns
 * { status: succeeded | noop | reverted | failed | not_available, detail: short code, resource_id?: the id of the
 * resource when the restore gave it a new one, limits?: list of short sentences about what was not restored,
 * rollback_change_id?: the row the handler wrote itself, probe?: the failing site check, site_after_revert?: how the
 * site was judged afterwards }. The status `reverted` is for a handler that checks the site itself: it wrote the
 * state, found the site failing, put the earlier state back and recorded that on its own row. The engine then answers
 * `stonewright_change_rollback_reverted` and writes and restores nothing more. A handler that writes its own rollback row says so in
 * records_own_row(); the engine then writes none and settles the row the handler returned. Options: kind
 * (rollback or redo; the kind of the row a handler writes), expected_current_sha256 (the live hash the engine
 * checked, or ''), permanent (the caller asked for a permanent removal where the family has the choice) and actor.
 */
interface RollbackFamilyHandler {

	/** Error code of a live_image() that cannot say what the resource is now. */
	public const LIVE_UNSUPPORTED = 'stonewright_change_live_unsupported';

	/** @return list<string> Ledger families this handler serves. */
	public function families(): array;

	/**
	 * @param array<string, mixed> $row The ledger row the engine acts on.
	 * @return string|array<mixed>|\WP_Error|null
	 */
	public function live_image( array $row ): string|array|\WP_Error|null;

	/**
	 * @param array<string, mixed>     $row
	 * @param string|array<mixed>|null $image
	 * @param array<string, mixed>     $options
	 * @return array{status:string,detail?:string,resource_id?:string,limits?:list<string>,rollback_change_id?:string|null,probe?:array<string, mixed>,site_after_revert?:string}
	 */
	public function restore( array $row, string|array|null $image, array $options ): array;

	/**
	 * One sentence for the plan: what the restore does.
	 *
	 * @param array<string, mixed>     $row
	 * @param string|array<mixed>|null $image
	 */
	public function describe( array $row, string|array|null $image ): string;

	/**
	 * @param array<string, mixed> $row
	 */
	public function records_own_row( array $row ): bool;

	/**
	 * Whether undoing or redoing a row of this family needs a person at wp-admin. The engine adds the code
	 * families (CodeAdapter::FAMILIES) whatever a handler answers.
	 *
	 * @param array<string, mixed> $row
	 */
	public function requires_human( array $row ): bool;
}
