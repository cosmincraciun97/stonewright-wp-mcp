<?php
/**
 * The families the rollback engine can restore, and the handler of each.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Security\Rollback;

use Stonewright\WpMcp\Security\ChangeLedger;

/**
 * A registry from ledger family to handler. The built-in handlers (posts and their kinds, options with theme switches,
 * menus, widgets, the code families, and users, comments, media, WooCommerce, plugins, skills, design directions and
 * memory through OtherRollbackFamilies) are there from the first lookup. Another family joins by calling register() with a handler
 * (or a CallableRollbackFamily around a restore function); the engine does not change. A later registration for a
 * family replaces the earlier one. A family with no handler is refused by the engine, never guessed.
 */
final class RollbackFamilies {

	/** @var array<string, RollbackFamilyHandler>|null */
	private static ?array $handlers = null;

	public static function register( RollbackFamilyHandler $handler ): void {
		self::boot();
		self::add( $handler );
	}

	public static function handler_for( string $family ): ?RollbackFamilyHandler {
		self::boot();
		return self::$handlers[ $family ] ?? null;
	}

	/** @return list<string> */
	public static function families(): array {
		self::boot();
		return array_keys( self::$handlers ?? [] );
	}

	/** Forget every registration, built-in handlers included, so that the next lookup starts again. For tests. */
	public static function reset_for_tests(): void {
		self::$handlers = null;
	}

	/**
	 * The row at the start of the chain of a row: the change that a rollback or a redo descends from. A row that has
	 * no parent in the ledger is its own root.
	 *
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	public static function root_of( array $row ): array {
		$chain = ChangeLedger::chain( (string) $row['change_id'] );
		return [] !== $chain && 'change' === $chain[0]['kind'] ? $chain[0] : $row;
	}

	private static function boot(): void {
		if ( null !== self::$handlers ) {
			return;
		}
		self::$handlers = [];
		foreach ( array_merge( [ new PostRollbackFamily(), new OptionsRollbackFamily( OtherRollbackFamilies::theme() ), new MenuRollbackFamily(), new WidgetRollbackFamily(), new CodeRollbackFamily() ], OtherRollbackFamilies::handlers() ) as $handler ) {
			self::add( $handler );
		}
	}

	private static function add( RollbackFamilyHandler $handler ): void {
		foreach ( $handler->families() as $family ) {
			self::$handlers[ $family ] = $handler;
		}
	}
}
