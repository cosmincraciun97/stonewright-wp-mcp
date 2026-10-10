<?php
/**
 * Line diff of two texts, with hunks, caps and masking.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Support\Diff;

/**
 * Compares two texts line by line and returns the changes as hunks.
 *
 * Result shape (a plain array, safe to encode as JSON):
 *
 *     kind            'text'
 *     status          'ok' | 'identical' | 'eol_only' | 'binary' | 'too_large'
 *     reason          '' | 'lines' | 'bytes' | 'changes'   (only for too_large)
 *     message         short sentence for every status except ok, '' otherwise
 *     changed         bool, false when both sides are byte-identical
 *     summary         { added, removed, moved: 0, changed }  in lines. A replaced line
 *                     counts once as changed; added and removed count the rest.
 *     lines_changed   int|null  added + removed lines found without a diff (too_large),
 *                     an estimate that ignores order; null when not computed
 *     old, new        { lines, bytes, eol }  eol is 'lf' | 'crlf' | 'mixed' | 'none'
 *     whitespace_only bool, true when ignoring whitespace leaves no difference
 *     truncated       bool, true when anything was left out or the diff was not computed
 *     cut             { hunks, changed_lines }  what the output caps left out
 *     masked          int, number of shown lines replaced by `[redacted]`
 *     hunks           list of
 *         old_start, old_lines, new_start, new_lines   as in a unified diff
 *         truncated   bool, true when the output cap cut this hunk short
 *         lines       list of { op: 'ctx' | 'add' | 'del', old: int|null, new: int|null,
 *                     text: string }, plus masked: true when the text was redacted and
 *                     no_eol: true when the line has no line ending in its file
 *
 * Line numbers start at 1. Line text never contains the line ending. CRLF and LF
 * are not told apart when lines are compared; a change of only the line endings
 * gives status `eol_only`. A missing final newline is a change of the last line.
 * A text with a NUL byte or invalid UTF-8 is binary and is not diffed.
 *
 * Options (each is clamped to a ceiling, so a caller can lower a cap but not lift it):
 *
 *     context           3      context lines around each change
 *     max_lines         2000   most lines on either side
 *     max_bytes         262144 most bytes on either side
 *     max_edit_distance 1000   most line edits searched for
 *     max_hunks         50     most hunks in the output
 *     max_output_lines  1000   most lines in the output
 *     max_output_bytes  262144 most bytes of line text in the output
 *     max_line_chars    500    longest line text; the rest is clipped with its size
 */
final class TextDiff {

	private const DEFAULTS = [
		'context'           => 3,
		'max_lines'         => 2000,
		'max_bytes'         => 262144,
		'max_edit_distance' => 1000,
		'max_hunks'         => 50,
		'max_output_lines'  => 1000,
		'max_output_bytes'  => 262144,
		'max_line_chars'    => 500,
	];

	private const CEILING = [
		'context'           => 20,
		'max_lines'         => 10000,
		'max_bytes'         => 1048576,
		'max_edit_distance' => 2500,
		'max_hunks'         => 500,
		'max_output_lines'  => 5000,
		'max_output_bytes'  => 1048576,
		'max_line_chars'    => 2000,
	];

	/** Largest side the over-cap estimate reads, in bytes and in lines. */
	private const ESTIMATE_BYTES = 4194304;
	private const ESTIMATE_LINES = 200000;

	/** Output bytes charged to a line beyond its text. */
	private const LINE_OVERHEAD = 48;

	/**
	 * @param array<string, int> $options See the class description.
	 * @return array<string, mixed>
	 */
	public static function diff( string $old, string $new, array $options = [] ): array {
		$opt = self::options( $options );
		$res = self::base( $old, $new );

		if ( strlen( $old ) > self::ESTIMATE_BYTES || strlen( $new ) > self::ESTIMATE_BYTES ) {
			return self::too_large( $res, 'bytes', null );
		}
		if ( self::is_binary( $old ) || self::is_binary( $new ) ) {
			$res['status']  = 'binary';
			$res['message'] = 'Binary content, not shown.';
			return $res;
		}
		if ( $old === $new ) {
			$res['status']  = 'identical';
			$res['message'] = 'No changes.';
			return $res;
		}

		$too_many_bytes = strlen( $old ) > $opt['max_bytes'] || strlen( $new ) > $opt['max_bytes'];
		$too_many_lines = $res['old']['lines'] > $opt['max_lines'] || $res['new']['lines'] > $opt['max_lines'];
		if ( $too_many_bytes || $too_many_lines ) {
			return self::too_large( $res, $too_many_bytes ? 'bytes' : 'lines', $opt, $old, $new );
		}

		$a = self::split( $old );
		$b = self::split( $new );

		$keys_a = [];
		foreach ( $a as [ $text, $final ] ) {
			$keys_a[] = $final ? $text : $text . "\0eof";
		}
		$keys_b = [];
		foreach ( $b as [ $text, $final ] ) {
			$keys_b[] = $final ? $text : $text . "\0eof";
		}

		$pairs = Myers::matches( $keys_a, $keys_b, $opt['max_edit_distance'] );
		if ( null === $pairs ) {
			return self::too_large( $res, 'changes', $opt, $old, $new );
		}

		$ops = self::script( $pairs, count( $a ), count( $b ) );
		$res = self::summarize( $res, $ops );
		if ( 0 === $res['summary']['added'] + $res['summary']['removed'] + $res['summary']['changed'] ) {
			$res['status']  = 'eol_only';
			$res['message'] = 'Only the line endings differ (' . $res['old']['eol'] . ' to ' . $res['new']['eol'] . ').';
			return $res;
		}

		$res['whitespace_only'] = preg_replace( '/\s+/', '', $old ) === preg_replace( '/\s+/', '', $new );

		return self::fill_hunks( $res, $ops, $a, $b, $opt );
	}

	/**
	 * The result as a unified diff, for export.
	 *
	 * The text is the masked and clipped text of the result, so the patch never
	 * holds more than the result does. Lines are joined with LF whatever the
	 * original line endings were.
	 *
	 * @param array<string, mixed> $result A result of {@see TextDiff::diff()}.
	 */
	public static function unified( array $result, string $old_label = 'a', string $new_label = 'b' ): string {
		$old_label = self::label( $old_label );
		$new_label = self::label( $new_label );
		$status    = (string) ( $result['status'] ?? '' );

		if ( 'binary' === $status ) {
			return 'Binary files ' . $old_label . ' and ' . $new_label . " differ\n";
		}
		if ( 'too_large' === $status ) {
			return '# ' . self::label( (string) ( $result['message'] ?? 'Too large to show.' ) ) . "\n";
		}
		if ( 'eol_only' === $status ) {
			return '# ' . self::label( (string) ( $result['message'] ?? '' ) ) . "\n";
		}
		if ( 'ok' !== $status ) {
			return '';
		}

		$out = '--- ' . $old_label . "\n+++ " . $new_label . "\n";
		foreach ( (array) ( $result['hunks'] ?? [] ) as $hunk ) {
			$out .= '@@ -' . self::range( (int) $hunk['old_start'], (int) $hunk['old_lines'] )
				. ' +' . self::range( (int) $hunk['new_start'], (int) $hunk['new_lines'] ) . " @@\n";
			foreach ( (array) $hunk['lines'] as $line ) {
				$marker = 'add' === $line['op'] ? '+' : ( 'del' === $line['op'] ? '-' : ' ' );
				$out   .= $marker . $line['text'] . "\n";
				if ( ! empty( $line['no_eol'] ) ) {
					$out .= "\\ No newline at end of file\n";
				}
			}
		}
		if ( ! empty( $result['truncated'] ) ) {
			$cut  = (array) ( $result['cut'] ?? [] );
			$out .= '# Diff truncated: ' . (int) ( $cut['hunks'] ?? 0 ) . ' hunks and ' . (int) ( $cut['changed_lines'] ?? 0 ) . " changed lines not shown\n";
		}

		return $out;
	}

	/**
	 * @param array<string, mixed> $options
	 * @return array<string, int>
	 */
	private static function options( array $options ): array {
		$out = [];
		foreach ( self::DEFAULTS as $key => $default ) {
			$value       = isset( $options[ $key ] ) && is_int( $options[ $key ] ) ? $options[ $key ] : $default;
			$out[ $key ] = max( 'context' === $key ? 0 : 1, min( $value, self::CEILING[ $key ] ) );
		}
		return $out;
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function base( string $old, string $new ): array {
		return [
			'kind'            => 'text',
			'status'          => 'ok',
			'reason'          => '',
			'message'         => '',
			'changed'         => $old !== $new,
			'summary'         => [ 'added' => 0, 'removed' => 0, 'moved' => 0, 'changed' => 0 ],
			'lines_changed'   => null,
			'old'             => self::describe( $old ),
			'new'             => self::describe( $new ),
			'whitespace_only' => false,
			'truncated'       => false,
			'cut'             => [ 'hunks' => 0, 'changed_lines' => 0 ],
			'masked'          => 0,
			'hunks'           => [],
		];
	}

	/**
	 * @return array{lines: int, bytes: int, eol: string}
	 */
	private static function describe( string $text ): array {
		$bytes = strlen( $text );
		$lf    = substr_count( $text, "\n" );
		$crlf  = substr_count( $text, "\r\n" );
		if ( 0 === $lf ) {
			$eol = 'none';
		} elseif ( $crlf === $lf ) {
			$eol = 'crlf';
		} elseif ( 0 === $crlf ) {
			$eol = 'lf';
		} else {
			$eol = 'mixed';
		}
		$lines = 0 === $bytes ? 0 : $lf + ( "\n" === $text[ $bytes - 1 ] ? 0 : 1 );
		return [ 'lines' => $lines, 'bytes' => $bytes, 'eol' => $eol ];
	}

	private static function is_binary( string $text ): bool {
		return str_contains( $text, "\0" ) || 1 !== preg_match( '//u', $text );
	}

	/**
	 * @return list<array{0: string, 1: bool}> Line text and whether the line ends with a line ending.
	 */
	private static function split( string $text ): array {
		if ( '' === $text ) {
			return [];
		}
		$parts = preg_split( '/\r\n|\n/', $text );
		if ( ! is_array( $parts ) ) {
			return [];
		}
		$final = '' === $parts[ count( $parts ) - 1 ];
		if ( $final ) {
			array_pop( $parts );
		}
		$last = count( $parts ) - 1;
		$out  = [];
		foreach ( $parts as $i => $part ) {
			$out[] = [ $part, $i < $last || $final ];
		}
		return $out;
	}

	/**
	 * Edit script from the matched line pairs. Deletions come before insertions
	 * inside a run of changes.
	 *
	 * @param list<array{0: int, 1: int}> $pairs
	 * @return list<array{op: string, old: int|null, new: int|null, o: int, n: int}>
	 *         `o` and `n` count the old and new lines before the entry.
	 */
	private static function script( array $pairs, int $old_count, int $new_count ): array {
		$ops = [];
		$i   = 0;
		$j   = 0;
		$add = static function ( string $op, ?int $old, ?int $new ) use ( &$ops, &$i, &$j ): void {
			$ops[] = [ 'op' => $op, 'old' => $old, 'new' => $new, 'o' => $i, 'n' => $j ];
			if ( null !== $old ) {
				++$i;
			}
			if ( null !== $new ) {
				++$j;
			}
		};
		$pairs[] = [ $old_count, $new_count ];
		foreach ( $pairs as [ $a, $b ] ) {
			while ( $i < $a ) {
				$add( 'del', $i, null );
			}
			while ( $j < $b ) {
				$add( 'add', null, $j );
			}
			if ( $i < $old_count && $j < $new_count ) {
				$add( 'eq', $i, $j );
			}
		}
		return $ops;
	}

	/**
	 * @param array<string, mixed>        $res
	 * @param list<array<string, mixed>>  $ops
	 * @return array<string, mixed>
	 */
	private static function summarize( array $res, array $ops ): array {
		$added   = 0;
		$removed = 0;
		$changed = 0;
		$del     = 0;
		$ins     = 0;
		$close   = static function () use ( &$added, &$removed, &$changed, &$del, &$ins ): void {
			$pair     = min( $del, $ins );
			$changed += $pair;
			$removed += $del - $pair;
			$added   += $ins - $pair;
			$del      = 0;
			$ins      = 0;
		};
		foreach ( $ops as $op ) {
			if ( 'del' === $op['op'] ) {
				++$del;
			} elseif ( 'add' === $op['op'] ) {
				++$ins;
			} else {
				$close();
			}
		}
		$close();

		$res['summary'] = [ 'added' => $added, 'removed' => $removed, 'moved' => 0, 'changed' => $changed ];
		return $res;
	}

	/**
	 * @param array<string, mixed>              $res
	 * @param list<array<string, mixed>>        $ops
	 * @param list<array{0: string, 1: bool}>   $a
	 * @param list<array{0: string, 1: bool}>   $b
	 * @param array<string, int>                $opt
	 * @return array<string, mixed>
	 */
	private static function fill_hunks( array $res, array $ops, array $a, array $b, array $opt ): array {
		$ranges = self::ranges( $ops, $opt['context'] );

		$pem_old = self::pem( $a );
		$pem_new = self::pem( $b );

		$hunks         = [];
		$cut_hunks     = 0;
		$cut_changed   = 0;
		$masked        = 0;
		$room_lines    = $opt['max_output_lines'];
		$room_bytes    = $opt['max_output_bytes'];
		$exhausted     = false;

		foreach ( $ranges as [ $from, $to ] ) {
			$slice = array_slice( $ops, $from, $to - $from + 1 );

			if ( $exhausted || count( $hunks ) >= $opt['max_hunks'] ) {
				++$cut_hunks;
				$cut_changed += self::count_changes( $slice );
				continue;
			}

			$lines     = [];
			$cut_inner = false;
			foreach ( $slice as $op ) {
				if ( $cut_inner ) {
					if ( 'eq' !== $op['op'] ) {
						++$cut_changed;
					}
					continue;
				}
				$side  = 'del' === $op['op'] ? $a : $b;
				$index = 'del' === $op['op'] ? (int) $op['old'] : (int) $op['new'];
				$flag  = 'del' === $op['op'] ? $pem_old : $pem_new;
				$raw   = $side[ $index ][0];

				$hidden = isset( $flag[ $index ] ) || DiffMask::sensitive( $raw );
				$text   = $hidden ? DiffMask::REDACTED : DiffMask::clip( $raw, $opt['max_line_chars'] );
				$cost   = strlen( $text ) + self::LINE_OVERHEAD;

				if ( $room_lines < 1 || $room_bytes < $cost ) {
					$cut_inner = true;
					$exhausted = true;
					if ( 'eq' !== $op['op'] ) {
						++$cut_changed;
					}
					continue;
				}
				--$room_lines;
				$room_bytes -= $cost;

				$line = [
					'op'   => $op['op'],
					'old'  => null === $op['old'] ? null : $op['old'] + 1,
					'new'  => null === $op['new'] ? null : $op['new'] + 1,
					'text' => $text,
				];
				if ( $hidden ) {
					$line['masked'] = true;
					++$masked;
				}
				if ( ! $side[ $index ][1] ) {
					$line['no_eol'] = true;
				}
				$lines[] = $line;
			}

			if ( [] === $lines ) {
				++$cut_hunks;
				continue;
			}

			$old_lines = 0;
			$new_lines = 0;
			foreach ( $lines as $line ) {
				$old_lines += null === $line['old'] ? 0 : 1;
				$new_lines += null === $line['new'] ? 0 : 1;
			}
			$first   = $slice[0];
			$hunks[] = [
				'old_start' => $old_lines > 0 ? $first['o'] + 1 : $first['o'],
				'old_lines' => $old_lines,
				'new_start' => $new_lines > 0 ? $first['n'] + 1 : $first['n'],
				'new_lines' => $new_lines,
				'truncated' => $cut_inner,
				'lines'     => $lines,
			];
		}

		$res['hunks']     = $hunks;
		$res['masked']    = $masked;
		$res['cut']       = [ 'hunks' => $cut_hunks, 'changed_lines' => $cut_changed ];
		$res['truncated'] = $cut_hunks > 0 || $cut_changed > 0;

		return $res;
	}

	/**
	 * Index ranges of the script that make one hunk each: every change with its
	 * context, merged when the unchanged stretch between two changes is no longer
	 * than the context on both sides.
	 *
	 * @param list<array<string, mixed>> $ops
	 * @return list<array{0: int, 1: int}>
	 */
	private static function ranges( array $ops, int $context ): array {
		$last   = count( $ops ) - 1;
		$ranges = [];
		$first  = null;
		$prev   = null;
		foreach ( $ops as $index => $op ) {
			if ( 'eq' === $op['op'] ) {
				continue;
			}
			if ( null === $first ) {
				$first = $index;
			} elseif ( $index - $prev - 1 > 2 * $context ) {
				$ranges[] = [ max( 0, $first - $context ), min( $last, $prev + $context ) ];
				$first    = $index;
			}
			$prev = $index;
		}
		if ( null !== $first ) {
			$ranges[] = [ max( 0, $first - $context ), min( $last, $prev + $context ) ];
		}
		return $ranges;
	}

	/**
	 * @param list<array<string, mixed>> $ops
	 */
	private static function count_changes( array $ops ): int {
		$count = 0;
		foreach ( $ops as $op ) {
			if ( 'eq' !== $op['op'] ) {
				++$count;
			}
		}
		return $count;
	}

	/**
	 * @param list<array{0: string, 1: bool}> $lines
	 * @return array<int, true>
	 */
	private static function pem( array $lines ): array {
		foreach ( $lines as [ $text ] ) {
			if ( str_contains( $text, 'PRIVATE KEY-----' ) ) {
				return DiffMask::pem_lines( array_column( $lines, 0 ) );
			}
		}
		return [];
	}

	/**
	 * Summary result for input the caps do not allow to diff.
	 *
	 * @param array<string, mixed>     $res
	 * @param array<string, int>|null  $opt Null when the input is too big even to count lines.
	 * @return array<string, mixed>
	 */
	private static function too_large( array $res, string $reason, ?array $opt, string $old = '', string $new = '' ): array {
		$res['status']    = 'too_large';
		$res['reason']    = $reason;
		$res['truncated'] = true;

		$estimate = null === $opt ? null : self::estimate( $old, $new );
		if ( null !== $estimate ) {
			$res['summary']       = [ 'added' => $estimate[0], 'removed' => $estimate[1], 'moved' => 0, 'changed' => 0 ];
			$res['lines_changed'] = $estimate[0] + $estimate[1];
		}

		$what = 'changes' === $reason
			? 'more than ' . ( null === $opt ? '' : $opt['max_edit_distance'] ) . ' line edits'
			: ( 'bytes' === $reason
				? max( $res['old']['bytes'], $res['new']['bytes'] ) . ' bytes' . ( null === $opt ? '' : ' (limit ' . $opt['max_bytes'] . ')' )
				: max( $res['old']['lines'], $res['new']['lines'] ) . ' lines' . ( null === $opt ? '' : ' (limit ' . $opt['max_lines'] . ')' ) );
		$res['message'] = 'Too large to show: ' . $what . ( null !== $estimate ? ', about ' . ( $estimate[0] + $estimate[1] ) . ' lines changed' : '' ) . '.';

		return $res;
	}

	/**
	 * Lines added and removed, found without ordering: common ends are skipped and
	 * what is left is matched as two bags of lines.
	 *
	 * @return array{0: int, 1: int}|null Added and removed line counts.
	 */
	private static function estimate( string $old, string $new ): ?array {
		if ( strlen( $old ) > self::ESTIMATE_BYTES || strlen( $new ) > self::ESTIMATE_BYTES ) {
			return null;
		}
		if ( substr_count( $old, "\n" ) > self::ESTIMATE_LINES || substr_count( $new, "\n" ) > self::ESTIMATE_LINES ) {
			return null;
		}
		$a = array_column( self::split( $old ), 0 );
		$b = array_column( self::split( $new ), 0 );
		$n = count( $a );
		$m = count( $b );

		$prefix = 0;
		while ( $prefix < $n && $prefix < $m && $a[ $prefix ] === $b[ $prefix ] ) {
			++$prefix;
		}
		$suffix = 0;
		while ( $suffix < $n - $prefix && $suffix < $m - $prefix && $a[ $n - 1 - $suffix ] === $b[ $m - 1 - $suffix ] ) {
			++$suffix;
		}

		$bag = [];
		for ( $i = $prefix; $i < $n - $suffix; $i++ ) {
			$bag[ $a[ $i ] ] = ( $bag[ $a[ $i ] ] ?? 0 ) + 1;
		}
		$added   = 0;
		$matched = 0;
		for ( $j = $prefix; $j < $m - $suffix; $j++ ) {
			if ( ( $bag[ $b[ $j ] ] ?? 0 ) > 0 ) {
				--$bag[ $b[ $j ] ];
				++$matched;
			} else {
				++$added;
			}
		}

		return [ $added, ( $n - $prefix - $suffix ) - $matched ];
	}

	private static function range( int $start, int $length ): string {
		return 1 === $length ? (string) $start : $start . ',' . $length;
	}

	private static function label( string $label ): string {
		$label = (string) preg_replace( '/[\x00-\x1F\x7F]+/', '_', $label );
		return DiffMask::clip( $label, 200 );
	}
}
