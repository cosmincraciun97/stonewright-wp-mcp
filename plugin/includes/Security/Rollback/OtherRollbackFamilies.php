<?php
/**
 * The rollback handlers of the user, comment, media, WooCommerce, theme, plugin, skill, design direction and memory families.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Security\Rollback;

use Stonewright\WpMcp\Security\Adapters\OtherFamilies;

/**
 * Each handler is a CallableRollbackFamily around OtherFamilies::restore(), the one entry point of these adapters.
 * The adapters write their own rollback rows, so the engine writes none. A handler reads the live resource through
 * OtherFamilies::live_image(), so the plan shows a diff and drift is checked; the engine passes `kind` (rollback or
 * redo) on to the adapter, which writes its row under that kind.
 *
 * Who needs a person at wp-admin:
 *
 * - A theme switch does not. The undo only activates the theme that was active before, through the same call as the
 *   `theme-activate` ability, which an agent may run with the same token in production-safe mode. It writes no code, and
 *   the engine probes the site afterwards and puts the earlier theme back when the site stops loading.
 * - A plugin does. A plugin delete is recorded as not restorable, so no row of the family reaches a restore, but a handler
 *   that ever restored plugin files would put code on the site, and code needs an administrator.
 *
 * The family `other` has no handler: its rows are events (a snippet that ran, a setting written from an admin screen) with
 * nothing to restore.
 */
final class OtherRollbackFamilies {

	/**
	 * Handlers for the families that have their own ledger family.
	 *
	 * @return list<RollbackFamilyHandler>
	 */
	public static function handlers(): array {
		return [
			self::handler( [ 'user' ], __( 'Writes the account fields and roles back as they were. A password is never written and no email is sent.', 'stonewright' ) ),
			self::handler( [ 'comment' ], __( 'Writes the comment fields and status back as they were. A comment that was deleted is created again under a new id.', 'stonewright' ) ),
			self::handler( [ 'media' ], __( 'Writes the attachment fields and metadata back as they were. The file itself is not touched.', 'stonewright' ) ),
			self::handler( [ 'woocommerce' ], __( 'Writes the product, variation, term or attribute back as it was, through the WooCommerce objects.', 'stonewright' ) ),
			self::handler( [ 'memory' ], __( 'Puts the memory entry back as it was. A deleted entry is inserted again under its id.', 'stonewright' ) ),
			self::handler( [ 'skill', 'design_direction' ], __( 'Restores the earlier revision that the store keeps. The restore is a new revision, so the history stays.', 'stonewright' ) ),
			new CallableRollbackFamily( [ 'plugin' ], self::restore(), null, true, __( 'Plugin files cannot be restored by Stonewright.', 'stonewright' ) ),
		];
	}

	/** The handler of theme switches. Their ledger family is `option`, so OptionsRollbackFamily routes them here. */
	public static function theme(): RollbackFamilyHandler {
		return self::handler( [ 'option' ], __( 'Switches the site back to the theme that was active before the change. The settings of both themes are not touched.', 'stonewright' ) );
	}

	/**
	 * @param list<string> $families
	 */
	private static function handler( array $families, string $description ): CallableRollbackFamily {
		return new CallableRollbackFamily(
			$families,
			self::restore(),
			static fn ( array $row ): array|\WP_Error|null => OtherFamilies::live_image( $row ),
			false,
			$description
		);
	}

	/** @return callable(string, array<string, mixed>):array<string, mixed> */
	private static function restore(): callable {
		return static fn ( string $change_id, array $options ): array => OtherFamilies::restore( $change_id, $options );
	}
}
