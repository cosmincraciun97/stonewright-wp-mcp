<?php
/**
 * MCP tool annotations of Stonewright abilities.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Core;

use Stonewright\WpMcp\Abilities\Ability;

/**
 * Derives the `meta.annotations` that every Stonewright ability registers with the Abilities API.
 * The bundled MCP adapter maps them to the tool annotations of MCP: `readonly` becomes
 * `readOnlyHint`, `destructive` becomes `destructiveHint`, `idempotent` becomes `idempotentHint`,
 * and `openWorldHint` and `title` pass through. An MCP client that finds no annotation assumes the
 * tool is destructive, not idempotent and open-world, so every ability states all four hints.
 *
 * The hints come from two facts that the ability truth matrix records for each ability (see
 * AbilitySourceFacts and plugin/data/ability-traits.php) and from the verb in the ability name:
 *
 * - an ability that cannot change state is read-only, not destructive and idempotent;
 * - any other ability is not read-only. It is not destructive when the last verb of its name only
 *   adds something (create, add, insert, upload, duplicate, backup or queue), and it is destructive
 *   otherwise, including when the name holds no verb that is known and when the verb can replace
 *   an earlier entry (record, capture, register, define, activate). It is idempotent only when its
 *   name says delete, remove or deactivate;
 * - an ability that fetches URLs or talks to a third-party service is open-world.
 *
 * An ability whose nature differs overrides any hint through `annotations` in its `meta()`. An
 * ability that has no recorded facts gets the conservative hints: it may change state, may be
 * destructive and may reach hosts outside the site.
 *
 * WordPress's REST run endpoint selects the HTTP method from these hints: GET for a read-only
 * ability, DELETE for a destructive and idempotent one, and POST for the rest.
 */
final class AbilityAnnotations {

	/** Hints of an ability that has no recorded facts. */
	private const CONSERVATIVE = [
		'readonly'      => false,
		'destructive'   => true,
		'idempotent'    => false,
		'openWorldHint' => true,
	];

	/** Verbs of abilities that only add something; nothing that exists is overwritten or removed. */
	private const ADDITIVE_VERBS = [ 'create', 'add', 'insert', 'upload', 'duplicate', 'backup', 'queue' ];

	/**
	 * Verbs of abilities that change or remove what exists, or whose result can replace an earlier
	 * entry of the same name: a record, a capture, a registration or a definition can overwrite the
	 * entry it names, and an activation replaces the item that was active. They are listed so that in
	 * a name that also holds an additive verb, such as theme-backup-restore, the last verb of the name
	 * decides.
	 */
	private const CHANGING_VERBS = [ 'update', 'delete', 'remove', 'restore', 'apply', 'write', 'patch', 'set', 'save', 'mutate', 'move', 'repair', 'replace', 'purge', 'reset', 'deactivate', 'toggle', 'regenerate', 'cancel', 'finalize', 'optimize', 'reconcile', 'generalize', 'promote', 'import', 'refresh', 'migrate', 'upsert', 'sync', 'edit', 'record', 'capture', 'register', 'define', 'activate' ];

	/** Verbs of abilities that can be repeated with the same arguments without a further effect. */
	private const IDEMPOTENT_VERBS = [ 'delete', 'remove', 'deactivate' ];

	/** Annotation keys an ability may override, with the names they are stored under. */
	private const OVERRIDE_KEYS = [
		'readonly'        => 'readonly',
		'readOnlyHint'    => 'readonly',
		'destructive'     => 'destructive',
		'destructiveHint' => 'destructive',
		'idempotent'      => 'idempotent',
		'idempotentHint'  => 'idempotent',
		'openWorldHint'   => 'openWorldHint',
	];

	/** @var array<string, array{write: bool, external: bool}>|null */
	private static ?array $traits = null;

	/**
	 * The annotations an ability registers: derived hints with the overrides of its meta().
	 *
	 * @param Ability                                   $ability Ability to annotate.
	 * @param array{write: bool, external: bool}|null   $traits  Facts to use instead of the recorded ones.
	 * @return array<string, bool|string>
	 */
	public static function for_ability( Ability $ability, ?array $traits = null ): array {
		$name   = $ability->name();
		$traits = $traits ?? self::recorded_traits()[ $name ] ?? null;
		$hints  = null === $traits ? self::CONSERVATIVE : self::derive( $name, $traits );
		$meta   = $ability->meta();

		return self::with_overrides( $hints, $meta['annotations'] ?? null );
	}

	/**
	 * The hints of an ability from its facts and its name.
	 *
	 * @param string                                  $ability_name Ability name, such as stonewright/content-update-page.
	 * @param array{write?: bool, external?: bool}    $traits       What the ability does; a missing fact is assumed true.
	 * @return array{readonly: bool, destructive: bool, idempotent: bool, openWorldHint: bool}
	 */
	public static function derive( string $ability_name, array $traits ): array {
		$write    = (bool) ( $traits['write'] ?? true );
		$external = (bool) ( $traits['external'] ?? true );

		if ( ! $write ) {
			return [
				'readonly'      => true,
				'destructive'   => false,
				'idempotent'    => true,
				'openWorldHint' => $external,
			];
		}

		$verb = self::action_verb( $ability_name );

		return [
			'readonly'      => false,
			'destructive'   => ! in_array( $verb, self::ADDITIVE_VERBS, true ),
			'idempotent'    => in_array( $verb, self::IDEMPOTENT_VERBS, true ),
			'openWorldHint' => $external,
		];
	}

	/**
	 * Replaces the recorded facts. Pass null to read them from plugin/data/ability-traits.php again.
	 *
	 * @param array<string, array{write: bool, external: bool}>|null $traits Facts by ability name.
	 */
	public static function use_traits( ?array $traits ): void {
		self::$traits = $traits;
	}

	/**
	 * Path of the file that records the facts of every ability.
	 */
	public static function traits_path(): string {
		return dirname( __DIR__, 2 ) . '/data/ability-traits.php';
	}

	/**
	 * The last verb of the ability name that is known, or an empty string.
	 */
	private static function action_verb( string $ability_name ): string {
		$slash = strpos( $ability_name, '/' );
		$slug  = false === $slash ? $ability_name : substr( $ability_name, $slash + 1 );
		$verb  = '';
		foreach ( explode( '-', $slug ) as $token ) {
			if ( in_array( $token, self::ADDITIVE_VERBS, true ) || in_array( $token, self::CHANGING_VERBS, true ) ) {
				$verb = $token;
			}
		}

		return $verb;
	}

	/**
	 * @param array<string, bool> $hints     Derived hints.
	 * @param mixed               $overrides The `annotations` entry of the ability's meta().
	 * @return array<string, bool|string>
	 */
	private static function with_overrides( array $hints, mixed $overrides ): array {
		if ( ! is_array( $overrides ) ) {
			return $hints;
		}

		// An override spelled the WordPress way beats the MCP spelling of the same hint.
		$accepted = [];
		foreach ( self::OVERRIDE_KEYS as $given => $stored ) {
			if ( isset( $overrides[ $given ] ) && is_bool( $overrides[ $given ] ) && ( $given === $stored || ! array_key_exists( $stored, $accepted ) ) ) {
				$accepted[ $stored ] = $overrides[ $given ];
			}
		}

		$final = array_merge( $hints, $accepted );
		if ( true === $final['readonly'] ) {
			// A tool that changes nothing cannot be destructive.
			$final['destructive'] = false;
		}

		if ( isset( $overrides['title'] ) && is_string( $overrides['title'] ) && '' !== trim( $overrides['title'] ) ) {
			$final['title'] = trim( $overrides['title'] );
		}

		return $final;
	}

	/**
	 * @return array<string, array{write: bool, external: bool}>
	 */
	private static function recorded_traits(): array {
		if ( null === self::$traits ) {
			$path         = self::traits_path();
			$loaded       = is_file( $path ) ? include $path : [];
			self::$traits = is_array( $loaded ) ? $loaded : [];
		}

		return self::$traits;
	}
}
