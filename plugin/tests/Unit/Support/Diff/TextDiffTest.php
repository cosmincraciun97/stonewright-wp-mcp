<?php
/**
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Support\Diff;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Support\Diff\TextDiff;

/**
 * @covers \Stonewright\WpMcp\Support\Diff\TextDiff
 */
final class TextDiffTest extends TestCase {

	/**
	 * @param array<string, mixed> $result
	 * @return list<string> One entry per shown line, prefixed with its marker.
	 */
	private function flat( array $result ): array {
		$out = [];
		foreach ( $result['hunks'] as $hunk ) {
			foreach ( $hunk['lines'] as $line ) {
				$out[] = ( 'add' === $line['op'] ? '+' : ( 'del' === $line['op'] ? '-' : ' ' ) ) . $line['text'];
			}
		}
		return $out;
	}

	private function lines( int $count, string $prefix = 'line' ): string {
		$out = '';
		for ( $i = 1; $i <= $count; $i++ ) {
			$out .= $prefix . $i . "\n";
		}
		return $out;
	}

	public function test_insert(): void {
		$r = TextDiff::diff( "a\nb\nc\n", "a\nb\nX\nc\n" );

		$this->assertSame( 'text', $r['kind'] );
		$this->assertSame( 'ok', $r['status'] );
		$this->assertSame( [ 'added' => 1, 'removed' => 0, 'moved' => 0, 'changed' => 0 ], $r['summary'] );
		$this->assertFalse( $r['truncated'] );
		$this->assertCount( 1, $r['hunks'] );
		$this->assertSame( [ ' a', ' b', '+X', ' c' ], $this->flat( $r ) );
		$hunk = $r['hunks'][0];
		$this->assertSame( [ 1, 3, 1, 4 ], [ $hunk['old_start'], $hunk['old_lines'], $hunk['new_start'], $hunk['new_lines'] ] );
		$added = $hunk['lines'][2];
		$this->assertNull( $added['old'] );
		$this->assertSame( 3, $added['new'] );
		$this->assertSame( 3, $hunk['lines'][3]['old'] );
		$this->assertSame( 4, $hunk['lines'][3]['new'] );
	}

	public function test_delete(): void {
		$r = TextDiff::diff( "a\nb\nc\n", "a\nc\n" );

		$this->assertSame( [ 'added' => 0, 'removed' => 1, 'moved' => 0, 'changed' => 0 ], $r['summary'] );
		$this->assertSame( [ ' a', '-b', ' c' ], $this->flat( $r ) );
		$this->assertSame( 2, $r['hunks'][0]['lines'][1]['old'] );
		$this->assertNull( $r['hunks'][0]['lines'][1]['new'] );
	}

	public function test_replace_counts_as_changed_lines(): void {
		$r = TextDiff::diff( "a\nb\nc\n", "a\nB\nc\n" );

		$this->assertSame( [ 'added' => 0, 'removed' => 0, 'moved' => 0, 'changed' => 1 ], $r['summary'] );
		$this->assertSame( [ ' a', '-b', '+B', ' c' ], $this->flat( $r ) );
	}

	public function test_a_move_is_a_delete_plus_an_insert(): void {
		$r = TextDiff::diff( "a\nb\nc\nd\n", "b\nc\nd\na\n" );

		$this->assertSame( [ '-a', ' b', ' c', ' d', '+a' ], $this->flat( $r ) );
		$this->assertSame( [ 'added' => 1, 'removed' => 1, 'moved' => 0, 'changed' => 0 ], $r['summary'] );
	}

	public function test_empty_old_side(): void {
		$r = TextDiff::diff( '', "a\nb\n" );

		$this->assertSame( 'ok', $r['status'] );
		$this->assertSame( [ '+a', '+b' ], $this->flat( $r ) );
		$hunk = $r['hunks'][0];
		$this->assertSame( [ 0, 0, 1, 2 ], [ $hunk['old_start'], $hunk['old_lines'], $hunk['new_start'], $hunk['new_lines'] ] );
		$this->assertSame( 2, $r['summary']['added'] );
	}

	public function test_empty_new_side(): void {
		$r = TextDiff::diff( "a\nb\n", '' );

		$this->assertSame( [ '-a', '-b' ], $this->flat( $r ) );
		$hunk = $r['hunks'][0];
		$this->assertSame( [ 1, 2, 0, 0 ], [ $hunk['old_start'], $hunk['old_lines'], $hunk['new_start'], $hunk['new_lines'] ] );
	}

	public function test_identical_input_has_no_hunks(): void {
		$r = TextDiff::diff( "a\nb\n", "a\nb\n" );

		$this->assertSame( 'identical', $r['status'] );
		$this->assertSame( [], $r['hunks'] );
		$this->assertSame( [ 'added' => 0, 'removed' => 0, 'moved' => 0, 'changed' => 0 ], $r['summary'] );
		$this->assertSame( 'identical', TextDiff::diff( '', '' )['status'] );
	}

	public function test_whitespace_only_change_is_reported_and_flagged(): void {
		$r = TextDiff::diff( "a b\nc\n", "a  b\nc\n" );

		$this->assertSame( 'ok', $r['status'] );
		$this->assertTrue( $r['whitespace_only'] );
		$this->assertSame( [ '-a b', '+a  b', ' c' ], $this->flat( $r ) );

		$this->assertFalse( TextDiff::diff( "a b\n", "a c\n" )['whitespace_only'] );
	}

	public function test_crlf_content_is_compared_without_the_carriage_return(): void {
		$r = TextDiff::diff( "a\r\nb\r\nc\r\n", "a\r\nX\r\nc\r\n" );

		$this->assertSame( [ ' a', '-b', '+X', ' c' ], $this->flat( $r ) );
		$this->assertSame( 'crlf', $r['old']['eol'] );
		$this->assertSame( 'crlf', $r['new']['eol'] );
	}

	public function test_a_line_ending_only_change_is_not_a_line_change(): void {
		$r = TextDiff::diff( "a\r\nb\r\n", "a\nb\n" );

		$this->assertSame( 'eol_only', $r['status'] );
		$this->assertSame( [], $r['hunks'] );
		$this->assertSame( 'crlf', $r['old']['eol'] );
		$this->assertSame( 'lf', $r['new']['eol'] );
	}

	public function test_missing_final_newline_is_a_change_of_the_last_line(): void {
		$r = TextDiff::diff( "a\nb", "a\nb\n" );

		$this->assertSame( 'ok', $r['status'] );
		$this->assertSame( [ ' a', '-b', '+b' ], $this->flat( $r ) );
		$lines = $r['hunks'][0]['lines'];
		$this->assertTrue( $lines[1]['no_eol'] );
		$this->assertArrayNotHasKey( 'no_eol', $lines[2] );
		$this->assertSame( 'lf', $r['old']['eol'] );
		$this->assertSame( 'none', TextDiff::diff( 'a', 'b' )['old']['eol'] );
	}

	public function test_context_lines_are_configurable(): void {
		$old = $this->lines( 10 );
		$new = str_replace( "line5\n", "LINE5\n", $old );

		$default = TextDiff::diff( $old, $new );
		$this->assertCount( 8, $default['hunks'][0]['lines'] );
		$this->assertSame( 2, $default['hunks'][0]['old_start'] );

		$one = TextDiff::diff( $old, $new, [ 'context' => 1 ] );
		$this->assertSame( [ ' line4', '-line5', '+LINE5', ' line6' ], $this->flat( $one ) );

		$none = TextDiff::diff( $old, $new, [ 'context' => 0 ] );
		$this->assertSame( [ '-line5', '+LINE5' ], $this->flat( $none ) );
	}

	public function test_distant_changes_make_separate_hunks_and_near_ones_merge(): void {
		$old = $this->lines( 30 );
		$far = str_replace( [ "line3\n", "line28\n" ], [ "X3\n", "X28\n" ], $old );
		$this->assertCount( 2, TextDiff::diff( $old, $far )['hunks'] );

		$near = str_replace( [ "line10\n", "line15\n" ], [ "X10\n", "X15\n" ], $old );
		$this->assertCount( 1, TextDiff::diff( $old, $near )['hunks'] );
	}

	public function test_over_the_line_cap_returns_a_summary_instead_of_a_diff(): void {
		$old = $this->lines( 2001 );
		$new = str_replace( "line7\n", "changed7\n", $old );

		$r = TextDiff::diff( $old, $new );

		$this->assertSame( 'too_large', $r['status'] );
		$this->assertSame( 'lines', $r['reason'] );
		$this->assertSame( [], $r['hunks'] );
		$this->assertSame( 2, $r['lines_changed'] );
		$this->assertSame( 2001, $r['old']['lines'] );
		$this->assertStringContainsString( 'too large', strtolower( $r['message'] ) );
		$this->assertStringContainsString( '2', $r['message'] );
		$this->assertTrue( $r['truncated'] );
	}

	public function test_exactly_at_the_line_cap_still_diffs(): void {
		$old = $this->lines( 2000 );
		$r   = TextDiff::diff( $old, str_replace( "line7\n", "x\n", $old ) );
		$this->assertSame( 'ok', $r['status'] );
	}

	public function test_over_the_byte_cap_returns_a_summary(): void {
		$r = TextDiff::diff( str_repeat( 'a', 600 ) . "\n", "b\n", [ 'max_bytes' => 500 ] );

		$this->assertSame( 'too_large', $r['status'] );
		$this->assertSame( 'bytes', $r['reason'] );
		$this->assertSame( [], $r['hunks'] );
	}

	public function test_too_many_changes_returns_a_summary_instead_of_hanging(): void {
		$old = '';
		$new = '';
		for ( $i = 0; $i < 300; $i++ ) {
			$old .= 'row' . $i . "\n";
			$new .= 'row' . ( 299 - $i ) . "\n";
		}

		$r = TextDiff::diff( $old, $new, [ 'max_edit_distance' => 50 ] );

		$this->assertSame( 'too_large', $r['status'] );
		$this->assertSame( 'changes', $r['reason'] );
		$this->assertSame( [], $r['hunks'] );
	}

	public function test_binary_input_gets_a_marker_not_a_diff(): void {
		$r = TextDiff::diff( "PNG\0\1\2", "PNG\0\1\3" );

		$this->assertSame( 'binary', $r['status'] );
		$this->assertSame( [], $r['hunks'] );
		$this->assertSame( 6, $r['old']['bytes'] );
		$this->assertTrue( $r['changed'] );
		$this->assertFalse( TextDiff::diff( "a\0", "a\0" )['changed'] );
		$this->assertSame( 'binary', TextDiff::diff( "ok\n", "bad \xB1\xB2\n" )['status'] );
	}

	public function test_a_token_inside_a_code_line_is_redacted_everywhere(): void {
		$old = "<?php\n\$api_" . "key = 'old-secret-0000';\n\$debug = false;\n";
		$new = "<?php\n\$api_" . "key = 'abcd1234efgh5678';\n\$debug = true;\n";

		$r    = TextDiff::diff( $old, $new );
		$json = (string) json_encode( $r );

		$this->assertStringNotContainsString( 'abcd1234efgh5678', $json );
		$this->assertStringNotContainsString( 'old-secret-0000', $json );
		$this->assertSame( [ ' <?php', '-[redacted]', '-$debug = false;', '+[redacted]', '+$debug = true;' ], $this->flat( $r ) );
		$this->assertSame( 2, $r['masked'] );
		$this->assertTrue( $r['hunks'][0]['lines'][1]['masked'] );

		$patch = TextDiff::unified( $r );
		$this->assertStringNotContainsString( 'abcd1234efgh5678', $patch );
		$this->assertStringContainsString( "+[redacted]\n", $patch );
	}

	public function test_a_private_key_block_is_redacted_line_by_line(): void {
		$key = '-----BEGIN ' . "PRIVATE KEY-----\nMIIEvQIBADANBgkqhkiG9w0BAQEFAASC\nZZZZZZZZZZ\n-----END PRIVATE KEY-----\n";

		$r = TextDiff::diff( "name\n", "name\n" . $key );

		$this->assertStringNotContainsString( 'MIIEvQ', (string) json_encode( $r ) );
		$this->assertStringNotContainsString( 'ZZZZZZ', (string) json_encode( $r ) );
		$this->assertSame( 4, $r['masked'] );
	}

	public function test_the_hunk_cap_cuts_and_counts_what_was_left_out(): void {
		$old = $this->lines( 100 );
		$new = $old;
		for ( $i = 10; $i <= 100; $i += 10 ) {
			$new = str_replace( "line{$i}\n", "X{$i}\n", $new );
		}

		$r = TextDiff::diff( $old, $new, [ 'context' => 0, 'max_hunks' => 3 ] );

		$this->assertCount( 3, $r['hunks'] );
		$this->assertTrue( $r['truncated'] );
		$this->assertSame( 7, $r['cut']['hunks'] );
		$this->assertSame( 14, $r['cut']['changed_lines'] );
		$this->assertSame( 10, $r['summary']['changed'] );
	}

	public function test_the_output_line_cap_cuts_inside_a_hunk(): void {
		$r = TextDiff::diff( $this->lines( 500, 'old' ), $this->lines( 500, 'new' ), [ 'max_output_lines' => 100 ] );

		$this->assertSame( 'ok', $r['status'] );
		$shown = 0;
		foreach ( $r['hunks'] as $hunk ) {
			$shown += count( $hunk['lines'] );
		}
		$this->assertSame( 100, $shown );
		$this->assertTrue( $r['truncated'] );
		$this->assertSame( 900, $r['cut']['changed_lines'] );
		$this->assertSame( 0, $r['cut']['hunks'] );
		$this->assertSame( 500, $r['summary']['changed'] );
	}

	public function test_the_output_byte_cap_holds(): void {
		$line = str_repeat( 'x', 200 );
		$old  = '';
		$new  = '';
		for ( $i = 0; $i < 400; $i++ ) {
			$old .= $line . "a$i\n";
			$new .= $line . "b$i\n";
		}

		$r = TextDiff::diff( $old, $new, [ 'max_output_bytes' => 10000 ] );

		$this->assertTrue( $r['truncated'] );
		$this->assertLessThan( 14000, strlen( (string) json_encode( $r['hunks'] ) ) );
		$this->assertGreaterThan( 0, $r['cut']['changed_lines'] );
	}

	public function test_long_lines_are_clipped(): void {
		$r = TextDiff::diff( "a\n", str_repeat( 'z', 3000 ) . "\n", [ 'max_line_chars' => 100 ] );

		$text = $r['hunks'][0]['lines'][1]['text'];
		$this->assertLessThanOrEqual( 150, strlen( $text ) );
		$this->assertStringContainsString( '3000 bytes', $text );
	}

	public function test_options_cannot_lift_the_hard_ceilings(): void {
		$r = TextDiff::diff( $this->lines( 10001 ), "x\n", [ 'max_lines' => 999999 ] );
		$this->assertSame( 'too_large', $r['status'] );
	}

	public function test_unified_rendering(): void {
		$r = TextDiff::diff( "a\nb\nc\n", "a\nB\nc\nd\n" );

		$this->assertSame(
			"--- a/file.txt\n+++ b/file.txt\n@@ -1,3 +1,4 @@\n a\n-b\n+B\n c\n+d\n",
			TextDiff::unified( $r, 'a/file.txt', 'b/file.txt' )
		);
	}

	public function test_unified_rendering_marks_a_missing_final_newline(): void {
		$r = TextDiff::diff( "a\nb", "a\nb\n" );

		$this->assertSame(
			"--- a\n+++ b\n@@ -1,2 +1,2 @@\n a\n-b\n\\ No newline at end of file\n+b\n",
			TextDiff::unified( $r )
		);
	}

	public function test_unified_rendering_of_empty_and_summary_results(): void {
		$this->assertSame( '', TextDiff::unified( TextDiff::diff( "a\n", "a\n" ) ) );
		$this->assertStringContainsString( 'Binary files', TextDiff::unified( TextDiff::diff( "\0", "\1" ) ) );
		$this->assertStringContainsString( 'too large', strtolower( TextDiff::unified( TextDiff::diff( $this->lines( 2001 ), "x\n" ) ) ) );
	}

	public function test_unified_labels_cannot_inject_lines(): void {
		$r = TextDiff::diff( "a\n", "b\n" );

		$patch = TextDiff::unified( $r, "a/x\n+++ evil", "b/y\r\n@@ -1 +1 @@" );

		$rows = explode( "\n", $patch );
		$this->assertCount( 6, $rows );
		$this->assertStringStartsWith( '--- ', $rows[0] );
		$this->assertStringStartsWith( '+++ ', $rows[1] );
		$this->assertStringStartsWith( '@@ -1 +1 @@', $rows[2] );
		$this->assertStringNotContainsString( "\r", $patch );
	}

	public function test_a_two_thousand_line_file_diffs_quickly(): void {
		$old = '';
		$new = '';
		for ( $i = 0; $i < 2000; $i++ ) {
			$old .= "    \$value_{$i} = compute( {$i} ); // step {$i}\n";
			$new .= 0 === $i % 20
				? "    \$value_{$i} = recompute( {$i} ); // step {$i}\n"
				: "    \$value_{$i} = compute( {$i} ); // step {$i}\n";
		}

		$start   = microtime( true );
		$r       = TextDiff::diff( $old, $new );
		$elapsed = microtime( true ) - $start;

		$this->assertSame( 'ok', $r['status'] );
		$this->assertSame( 100, $r['summary']['changed'] );
		$this->assertLessThan( 1.5, $elapsed, 'diff took ' . round( $elapsed, 3 ) . 's' );
	}

	public function test_a_fully_reversed_file_stays_bounded(): void {
		$old = '';
		$new = '';
		for ( $i = 0; $i < 2000; $i++ ) {
			$old .= 'row' . $i . "\n";
			$new .= 'row' . ( 1999 - $i ) . "\n";
		}

		$start   = microtime( true );
		$r       = TextDiff::diff( $old, $new );
		$elapsed = microtime( true ) - $start;

		$this->assertContains( $r['status'], [ 'ok', 'too_large' ] );
		$this->assertLessThan( 5.0, $elapsed, 'diff took ' . round( $elapsed, 3 ) . 's' );
	}
}
