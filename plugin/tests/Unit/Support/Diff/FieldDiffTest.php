<?php
/**
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Support\Diff;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Support\Diff\FieldDiff;

/**
 * @covers \Stonewright\WpMcp\Support\Diff\FieldDiff
 */
final class FieldDiffTest extends TestCase {

	/**
	 * @param array<string, mixed> $result
	 * @return array<string, array<string, mixed>>
	 */
	private function by_path( array $result ): array {
		$out = [];
		foreach ( $result['fields'] as $field ) {
			$out[ $field['path'] ] = $field;
		}
		return $out;
	}

	public function test_nested_changes_added_and_removed_key_paths(): void {
		$old = [
			'blogname' => 'Site A',
			'layout'   => [ 'width' => 1200, 'gap' => [ 'x' => 10, 'y' => 20 ], 'legacy' => 'yes' ],
			'menu'     => [ 'main', 'footer' ],
		];
		$new = [
			'blogname' => 'Site B',
			'layout'   => [ 'width' => 1200, 'gap' => [ 'x' => 12, 'y' => 20 ], 'columns' => 3 ],
			'menu'     => [ 'main', 'social' ],
		];

		$r = FieldDiff::diff( $old, $new );

		$this->assertSame( 'fields', $r['kind'] );
		$this->assertSame( 'ok', $r['status'] );
		$this->assertSame( [ 'added' => 1, 'removed' => 1, 'moved' => 0, 'changed' => 3 ], $r['summary'] );
		$fields = $this->by_path( $r );
		$this->assertSame( [ 'changed', 'Site A', 'Site B', false ], [ $fields['blogname']['op'], $fields['blogname']['before'], $fields['blogname']['after'], $fields['blogname']['redacted'] ] );
		$this->assertSame( [ 'changed', '10', '12' ], [ $fields['layout.gap.x']['op'], $fields['layout.gap.x']['before'], $fields['layout.gap.x']['after'] ] );
		$this->assertSame( [ 'added', null, '3' ], [ $fields['layout.columns']['op'], $fields['layout.columns']['before'], $fields['layout.columns']['after'] ] );
		$this->assertSame( [ 'removed', 'yes', null ], [ $fields['layout.legacy']['op'], $fields['layout.legacy']['before'], $fields['layout.legacy']['after'] ] );
		$this->assertSame( [ 'footer', 'social' ], [ $fields['menu.1']['before'], $fields['menu.1']['after'] ] );
		$this->assertArrayNotHasKey( 'layout.width', $fields );
		$this->assertFalse( $r['truncated'] );
	}

	public function test_identical_input(): void {
		$r = FieldDiff::diff( [ 'a' => [ 'b' => 1 ] ], [ 'a' => [ 'b' => 1 ] ] );
		$this->assertSame( 'identical', $r['status'] );
		$this->assertSame( [], $r['fields'] );
	}

	public function test_key_order_and_numeric_strings_are_not_changes(): void {
		$r = FieldDiff::diff( [ 'a' => 1, 'b' => [ 'x' => '2', 'y' => 3 ] ], [ 'b' => [ 'y' => '3', 'x' => 2 ], 'a' => '1' ] );
		$this->assertSame( 'identical', $r['status'] );
		$this->assertSame( 'changed', FieldDiff::diff( [ 'a' => true ], [ 'a' => '1' ] )['fields'][0]['op'] );
		$this->assertSame( 'changed', FieldDiff::diff( [ 'a' => null ], [ 'a' => '' ] )['fields'][0]['op'] );
	}

	public function test_a_secret_option_is_changed_but_its_value_is_never_present(): void {
		$old = [ 'stonewright_api_token' => 'old-token-value-111', 'blogname' => 'A' ];
		$new = [ 'stonewright_api_token' => 'new-token-value-222', 'blogname' => 'A' ];

		$r    = FieldDiff::diff( $old, $new );
		$json = (string) json_encode( $r );

		$this->assertStringNotContainsString( 'old-token-value', $json );
		$this->assertStringNotContainsString( 'new-token-value', $json );
		$this->assertCount( 1, $r['fields'] );
		$field = $r['fields'][0];
		$this->assertSame( [ 'stonewright_api_token', 'changed', '[redacted]', '[redacted]', true ], [ $field['path'], $field['op'], $field['before'], $field['after'], $field['redacted'] ] );
		$this->assertSame( 1, $r['summary']['changed'] );
		$this->assertSame( 1, $r['masked'] );
	}

	public function test_an_unchanged_secret_is_not_listed(): void {
		$r = FieldDiff::diff( [ 'smtp_password' => 'same-value-123' ], [ 'smtp_password' => 'same-value-123' ] );
		$this->assertSame( 'identical', $r['status'] );
	}

	public function test_added_and_removed_secrets_are_masked(): void {
		$r = FieldDiff::diff( [ 'old_secret' => 'zzzz-old-1234' ], [ 'new_secret' => 'zzzz-new-5678' ] );

		$this->assertStringNotContainsString( 'zzzz', (string) json_encode( $r ) );
		$fields = $this->by_path( $r );
		$this->assertSame( [ 'removed', '[redacted]', null ], [ $fields['old_secret']['op'], $fields['old_secret']['before'], $fields['old_secret']['after'] ] );
		$this->assertSame( [ 'added', null, '[redacted]' ], [ $fields['new_secret']['op'], $fields['new_secret']['before'], $fields['new_secret']['after'] ] );
	}

	public function test_a_secret_nested_inside_an_added_array_is_masked(): void {
		$r = FieldDiff::diff( [], [ 'mailer' => [ 'host' => 'smtp.example.com', 'pass' . 'word' => 'hunter2hunter2' ] ] );

		$this->assertStringNotContainsString( 'hunter2', (string) json_encode( $r ) );
		$this->assertStringContainsString( 'smtp.example.com', (string) json_encode( $r ) );
	}

	public function test_a_credential_inside_an_innocent_value_is_masked(): void {
		$r = FieldDiff::diff( [ 'note' => 'a' ], [ 'note' => 'password: hunter2hunter2' ] );

		$this->assertStringNotContainsString( 'hunter2', (string) json_encode( $r ) );
		$this->assertTrue( $r['fields'][0]['redacted'] );
	}

	public function test_typed_props_are_leaves_with_readable_values(): void {
		$old = [ 'title' => [ '$$type' => 'string', 'value' => 'Hello' ], 'gap' => [ '$$type' => 'size', 'value' => [ 'size' => 8, 'unit' => 'px' ] ] ];
		$new = [ 'title' => [ '$$type' => 'string', 'value' => 'Hi' ], 'gap' => [ '$$type' => 'size', 'value' => [ 'size' => 8, 'unit' => 'px' ] ] ];

		$r = FieldDiff::diff( $old, $new );

		$this->assertCount( 1, $r['fields'] );
		$this->assertSame( [ 'title', 'Hello', 'Hi' ], [ $r['fields'][0]['path'], $r['fields'][0]['before'], $r['fields'][0]['after'] ] );
	}

	public function test_a_type_change_with_the_same_text_stays_visible(): void {
		$r = FieldDiff::diff(
			[ 'x' => [ '$$type' => 'string', 'value' => 'Same' ] ],
			[ 'x' => [ '$$type' => 'html', 'value' => 'Same' ] ]
		);

		$this->assertSame( 'Same (string)', $r['fields'][0]['before'] );
		$this->assertSame( 'Same (html)', $r['fields'][0]['after'] );
	}

	public function test_field_cap_cuts_and_counts_but_the_summary_stays_exact(): void {
		$old = [];
		$new = [];
		for ( $i = 0; $i < 30; $i++ ) {
			$old[ 'k' . $i ] = 'a';
			$new[ 'k' . $i ] = 'b';
		}

		$r = FieldDiff::diff( $old, $new, [ 'max_fields' => 10 ] );

		$this->assertCount( 10, $r['fields'] );
		$this->assertTrue( $r['truncated'] );
		$this->assertSame( 20, $r['cut']['fields'] );
		$this->assertSame( 30, $r['summary']['changed'] );
	}

	public function test_long_values_are_clipped_with_their_size(): void {
		$r = FieldDiff::diff( [ 'css' => 'a' ], [ 'css' => str_repeat( 'b', 5000 ) ], [ 'max_value_chars' => 200 ] );

		$this->assertLessThan( 260, strlen( $r['fields'][0]['after'] ) );
		$this->assertStringContainsString( '5000 bytes', $r['fields'][0]['after'] );
	}

	public function test_default_value_cap_is_five_hundred_characters(): void {
		$r = FieldDiff::diff( [ 'css' => 'a' ], [ 'css' => str_repeat( 'b', 5000 ) ] );

		$this->assertLessThan( 560, strlen( $r['fields'][0]['after'] ) );
	}

	public function test_too_many_nodes_returns_a_summary(): void {
		$big = array_fill( 0, 500, 'x' );
		$r   = FieldDiff::diff( $big, array_fill( 0, 500, 'y' ), [ 'max_nodes' => 100 ] );

		$this->assertSame( 'too_large', $r['status'] );
		$this->assertSame( [], $r['fields'] );
		$this->assertTrue( $r['truncated'] );
	}

	public function test_depth_limit_turns_deep_arrays_into_one_value(): void {
		$deep_old = [ 'a' => [ 'b' => [ 'c' => [ 'd' => 1 ] ] ] ];
		$deep_new = [ 'a' => [ 'b' => [ 'c' => [ 'd' => 2 ] ] ] ];

		$r = FieldDiff::diff( $deep_old, $deep_new, [ 'max_depth' => 2 ] );

		$this->assertCount( 1, $r['fields'] );
		$this->assertSame( 'a.b', $r['fields'][0]['path'] );
		$this->assertStringContainsString( '"d":2', $r['fields'][0]['after'] );
	}

	public function test_objects_and_non_utf8_values_are_safe(): void {
		$r = FieldDiff::diff( [ 'o' => (object) [ 'a' => 1 ], 'b' => "x" ], [ 'o' => (object) [ 'a' => 2 ], 'b' => "bad \xB1" ] );

		$this->assertNotFalse( json_encode( $r ) );
		$this->assertSame( 2, $r['summary']['changed'] );
	}
}
