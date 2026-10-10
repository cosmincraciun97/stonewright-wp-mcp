<?php
/**
 * Key-path diff of options, metadata and other nested arrays.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Support\Diff;

/**
 * Lists the key paths that differ between two nested arrays.
 *
 * Result shape (a plain array, safe to encode as JSON):
 *
 *     kind      'fields'
 *     status    'ok' | 'identical' | 'too_large'
 *     reason    '' | 'nodes'   (only for too_large)
 *     message   short sentence for too_large, '' otherwise
 *     summary   { added, removed, moved: 0, changed }  in key paths, exact even when cut
 *     truncated bool, true when anything was left out or the diff was not computed
 *     cut       { fields }  key paths the cap left out
 *     masked    int, number of shown fields with a redacted side
 *     fields    list of { path: string, op: 'added' | 'removed' | 'changed',
 *               before: string|null, after: string|null, redacted: bool }
 *
 * A path joins the keys with dots (`layout.gap.x`); list items use their index.
 * `before` is null for an added field and `after` is null for a removed one.
 * Both are short strings: a value that is an array is shown as JSON, a typed prop
 * (`$$type` and `value`) is one field shown as its content, and nothing is longer
 * than `max_value_chars` plus a size marker.
 *
 * A field is `redacted` when any key on its path names a secret, or when its text
 * matches the credential masker. Its values are then `[redacted]` on both sides,
 * so the result says that the field changed and never what it held. A subtree that
 * exists on one side only is one field; secret keys inside it are redacted in its
 * JSON.
 *
 * Scalars compare by their string form (`1` equals `"1"`), except that booleans
 * and null only equal themselves. Key order does not matter. Lists compare index
 * by index.
 *
 * Options (each is clamped to a ceiling):
 *
 *     max_fields       200    most fields in the output
 *     max_value_chars  500    longest value text
 *     max_depth        12     deepest level compared key by key; deeper arrays are one value
 *     max_nodes        20000  most keys visited before the diff gives up
 */
final class FieldDiff {

	private const DEFAULTS = [
		'max_fields'      => 200,
		'max_value_chars' => 500,
		'max_depth'       => 12,
		'max_nodes'       => 20000,
	];

	private const CEILING = [
		'max_fields'      => 1000,
		'max_value_chars' => 2000,
		'max_depth'       => 20,
		'max_nodes'       => 100000,
	];

	private const MAX_PATH_CHARS = 300;

	/**
	 * @param array<int|string, mixed> $old     Values before.
	 * @param array<int|string, mixed> $new     Values after.
	 * @param array<string, int>       $options See the class description.
	 * @return array<string, mixed>
	 */
	public static function diff( array $old, array $new, array $options = [] ): array {
		$opt = [];
		foreach ( self::DEFAULTS as $key => $default ) {
			$value       = isset( $options[ $key ] ) && is_int( $options[ $key ] ) ? $options[ $key ] : $default;
			$opt[ $key ] = max( 1, min( $value, self::CEILING[ $key ] ) );
		}

		$state = [
			'fields'  => [],
			'counts'  => [ 'added' => 0, 'removed' => 0, 'changed' => 0 ],
			'cut'     => 0,
			'masked'  => 0,
			'nodes'   => 0,
			'aborted' => false,
		];
		self::walk( $old, $new, [], 0, $state, $opt );

		$result = [
			'kind'      => 'fields',
			'status'    => 'ok',
			'reason'    => '',
			'message'   => '',
			'summary'   => [ 'added' => 0, 'removed' => 0, 'moved' => 0, 'changed' => 0 ],
			'truncated' => false,
			'cut'       => [ 'fields' => 0 ],
			'masked'    => 0,
			'fields'    => [],
		];

		if ( $state['aborted'] ) {
			$result['status']    = 'too_large';
			$result['reason']    = 'nodes';
			$result['message']   = 'Too large to show: more than ' . $opt['max_nodes'] . ' keys.';
			$result['truncated'] = true;
			return $result;
		}

		$result['summary']   = [
			'added'   => $state['counts']['added'],
			'removed' => $state['counts']['removed'],
			'moved'   => 0,
			'changed' => $state['counts']['changed'],
		];
		$result['fields']    = $state['fields'];
		$result['masked']    = $state['masked'];
		$result['cut']       = [ 'fields' => $state['cut'] ];
		$result['truncated'] = $state['cut'] > 0;
		if ( 0 === array_sum( $state['counts'] ) ) {
			$result['status'] = 'identical';
		}

		return $result;
	}

	/**
	 * @param array<int|string, mixed> $a
	 * @param array<int|string, mixed> $b
	 * @param list<string>             $path
	 * @param array<string, mixed>     $state
	 * @param array<string, int>       $opt
	 */
	private static function walk( array $a, array $b, array $path, int $depth, array &$state, array $opt ): void {
		$keys = array_keys( $a );
		foreach ( array_keys( $b ) as $key ) {
			if ( ! array_key_exists( $key, $a ) ) {
				$keys[] = $key;
			}
		}

		foreach ( $keys as $key ) {
			if ( $state['aborted'] ) {
				return;
			}
			if ( ++$state['nodes'] > $opt['max_nodes'] ) {
				$state['aborted'] = true;
				return;
			}
			$here = array_merge( $path, [ (string) $key ] );
			$in_a = array_key_exists( $key, $a );
			$in_b = array_key_exists( $key, $b );

			if ( $in_a && $in_b ) {
				self::compare( $a[ $key ], $b[ $key ], $here, $depth + 1, $state, $opt );
			} elseif ( $in_a ) {
				self::record( 'removed', $here, $a[ $key ], null, $state, $opt );
			} else {
				self::record( 'added', $here, null, $b[ $key ], $state, $opt );
			}
		}
	}

	/**
	 * @param list<string>         $path
	 * @param array<string, mixed> $state
	 * @param array<string, int>   $opt
	 */
	private static function compare( mixed $a, mixed $b, array $path, int $depth, array &$state, array $opt ): void {
		$a = self::plain( $a );
		$b = self::plain( $b );

		if ( is_array( $a ) && is_array( $b ) && null === DiffMask::typed_name( $a ) && null === DiffMask::typed_name( $b ) && $depth < $opt['max_depth'] ) {
			self::walk( $a, $b, $path, $depth, $state, $opt );
			return;
		}
		if ( ! self::equal( $a, $b ) ) {
			self::record( 'changed', $path, $a, $b, $state, $opt );
		}
	}

	/**
	 * @param list<string>         $path
	 * @param array<string, mixed> $state
	 * @param array<string, int>   $opt
	 */
	private static function record( string $op, array $path, mixed $before, mixed $after, array &$state, array $opt ): void {
		++$state['counts'][ $op ];
		if ( count( $state['fields'] ) >= $opt['max_fields'] ) {
			++$state['cut'];
			return;
		}

		$secret = false;
		foreach ( $path as $segment ) {
			if ( DiffMask::is_secret_key( $segment ) ) {
				$secret = true;
				break;
			}
		}

		$before_text = null;
		$after_text  = null;
		$redacted    = $secret;
		if ( 'added' !== $op ) {
			[ $before_text, $hit ] = $secret ? [ DiffMask::REDACTED, true ] : DiffMask::value( $before, '', $opt['max_value_chars'] );
			$redacted              = $redacted || $hit;
		}
		if ( 'removed' !== $op ) {
			[ $after_text, $hit ] = $secret ? [ DiffMask::REDACTED, true ] : DiffMask::value( $after, '', $opt['max_value_chars'] );
			$redacted             = $redacted || $hit;
		}

		if ( 'changed' === $op && ! $redacted && $before_text === $after_text ) {
			$before_text .= ' (' . ( DiffMask::typed_name( $before ) ?? 'plain' ) . ')';
			$after_text  .= ' (' . ( DiffMask::typed_name( $after ) ?? 'plain' ) . ')';
		}

		$state['fields'][] = [
			'path'     => DiffMask::clip( implode( '.', array_map( [ DiffMask::class, 'key' ], $path ) ), self::MAX_PATH_CHARS ),
			'op'       => $op,
			'before'   => $before_text,
			'after'    => $after_text,
			'redacted' => $redacted,
		];
		if ( $redacted ) {
			++$state['masked'];
		}
	}

	private static function plain( mixed $value ): mixed {
		return is_object( $value ) ? get_object_vars( $value ) : $value;
	}

	private static function equal( mixed $a, mixed $b ): bool {
		$a = self::plain( $a );
		$b = self::plain( $b );
		if ( is_array( $a ) && is_array( $b ) ) {
			if ( count( $a ) !== count( $b ) ) {
				return false;
			}
			foreach ( $a as $key => $item ) {
				if ( ! array_key_exists( $key, $b ) || ! self::equal( $item, $b[ $key ] ) ) {
					return false;
				}
			}
			return true;
		}
		if ( is_array( $a ) || is_array( $b ) ) {
			return false;
		}
		if ( null === $a || null === $b || is_bool( $a ) || is_bool( $b ) ) {
			return $a === $b;
		}
		if ( is_scalar( $a ) && is_scalar( $b ) ) {
			return (string) $a === (string) $b;
		}
		return $a === $b;
	}
}
