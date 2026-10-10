<?php
/**
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Support\Diff;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Support\Diff\ElementorTreeDiff;

/**
 * @covers \Stonewright\WpMcp\Support\Diff\ElementorTreeDiff
 */
final class ElementorTreeDiffTest extends TestCase {

	/**
	 * @param array<string, mixed>     $settings
	 * @param list<array<string, mixed>> $children
	 * @return array<string, mixed>
	 */
	private function container( string $id, array $children = [], array $settings = [] ): array {
		return [ 'id' => $id, 'elType' => 'container', 'settings' => $settings, 'elements' => $children ];
	}

	/**
	 * @param array<string, mixed> $settings
	 * @return array<string, mixed>
	 */
	private function widget( string $id, string $type, array $settings = [] ): array {
		return [ 'id' => $id, 'elType' => 'widget', 'widgetType' => $type, 'settings' => $settings, 'elements' => [] ];
	}

	/**
	 * @param array<string, mixed> $result
	 * @return array<string, array<string, mixed>>
	 */
	private function by_id( array $result ): array {
		$out = [];
		foreach ( $result['elements'] as $element ) {
			$out[ $element['id'] ] = $element;
		}
		return $out;
	}

	public function test_v3_setting_changes_are_key_paths_with_old_and_new_values(): void {
		$old = [ $this->container( 'c1', [ $this->widget( 'w1', 'heading', [ 'title' => 'Old', 'align' => 'left', 'size' => [ 'unit' => 'px', 'size' => 10 ] ] ) ] ) ];
		$new = [ $this->container( 'c1', [ $this->widget( 'w1', 'heading', [ 'title' => 'New', 'title_color' => '#fff', 'size' => [ 'unit' => 'px', 'size' => 12 ] ] ) ] ) ];

		$r = ElementorTreeDiff::diff( $old, $new );

		$this->assertSame( 'elementor', $r['kind'] );
		$this->assertSame( 'ok', $r['status'] );
		$this->assertSame( [ 'added' => 0, 'removed' => 0, 'moved' => 0, 'changed' => 1 ], $r['summary'] );
		$this->assertCount( 1, $r['elements'] );
		$el = $r['elements'][0];
		$this->assertSame( [ 'changed', 'w1', 'heading' ], [ $el['op'], $el['id'], $el['type'] ] );
		$fields = [];
		foreach ( $el['settings'] as $field ) {
			$fields[ $field['path'] ] = $field;
		}
		$this->assertSame( [ 'changed', 'Old', 'New' ], [ $fields['title']['op'], $fields['title']['before'], $fields['title']['after'] ] );
		$this->assertSame( [ 'added', null, '#fff' ], [ $fields['title_color']['op'], $fields['title_color']['before'], $fields['title_color']['after'] ] );
		$this->assertSame( [ 'removed', 'left', null ], [ $fields['align']['op'], $fields['align']['before'], $fields['align']['after'] ] );
		$this->assertSame( [ 'changed', '10', '12' ], [ $fields['size.size']['op'], $fields['size.size']['before'], $fields['size.size']['after'] ] );
	}

	public function test_a_container_is_not_changed_because_its_children_changed(): void {
		$old = [ $this->container( 'c1', [ $this->widget( 'w1', 'heading', [ 'title' => 'A' ] ) ] ) ];
		$new = [ $this->container( 'c1', [ $this->widget( 'w1', 'heading', [ 'title' => 'B' ] ) ] ) ];

		$r = ElementorTreeDiff::diff( $old, $new );

		$this->assertSame( [ 'w1' ], array_keys( $this->by_id( $r ) ) );
	}

	public function test_a_widget_moved_to_another_container(): void {
		$old = [
			$this->container( 'c1', [ $this->widget( 'w1', 'heading' ), $this->widget( 'w2', 'button', [ 'text' => 'Go' ] ) ] ),
			$this->container( 'c2', [] ),
		];
		$new = [
			$this->container( 'c1', [ $this->widget( 'w1', 'heading' ) ] ),
			$this->container( 'c2', [ $this->widget( 'w2', 'button', [ 'text' => 'Go' ] ) ] ),
		];

		$r = ElementorTreeDiff::diff( $old, $new );

		$this->assertSame( [ 'added' => 0, 'removed' => 0, 'moved' => 1, 'changed' => 0 ], $r['summary'] );
		$el = $r['elements'][0];
		$this->assertSame( [ 'moved', 'w2', 'button', 'parent' ], [ $el['op'], $el['id'], $el['type'], $el['reason'] ] );
		$this->assertSame( [ 'c1', 'c2', 1, 0 ], [ $el['from_parent'], $el['to_parent'], $el['from_index'], $el['to_index'] ] );
	}

	public function test_a_reorder_inside_a_container_moves_one_element(): void {
		$old = [ $this->container( 'c1', [ $this->widget( 'w1', 'heading' ), $this->widget( 'w2', 'button' ), $this->widget( 'w3', 'text-editor' ) ] ) ];
		$new = [ $this->container( 'c1', [ $this->widget( 'w2', 'button' ), $this->widget( 'w3', 'text-editor' ), $this->widget( 'w1', 'heading' ) ] ) ];

		$r = ElementorTreeDiff::diff( $old, $new );

		$this->assertSame( 1, $r['summary']['moved'] );
		$el = $r['elements'][0];
		$this->assertSame( [ 'moved', 'w1', 'order', 'c1', 'c1', 0, 2 ], [ $el['op'], $el['id'], $el['reason'], $el['from_parent'], $el['to_parent'], $el['from_index'], $el['to_index'] ] );
	}

	public function test_an_insertion_does_not_make_its_siblings_moved(): void {
		$old = [ $this->container( 'c1', [ $this->widget( 'w1', 'heading' ), $this->widget( 'w2', 'button' ) ] ) ];
		$new = [ $this->container( 'c1', [ $this->widget( 'w9', 'image' ), $this->widget( 'w1', 'heading' ), $this->widget( 'w2', 'button' ) ] ) ];

		$r = ElementorTreeDiff::diff( $old, $new );

		$this->assertSame( [ 'added' => 1, 'removed' => 0, 'moved' => 0, 'changed' => 0 ], $r['summary'] );
		$this->assertSame( [ 'added', 'w9', 'image', 'c1', 0 ], [ $r['elements'][0]['op'], $r['elements'][0]['id'], $r['elements'][0]['type'], $r['elements'][0]['parent'], $r['elements'][0]['index'] ] );
	}

	public function test_added_and_removed_elements_including_a_removed_subtree(): void {
		$old = [
			$this->container( 'c1', [ $this->widget( 'w1', 'heading' ) ] ),
			$this->container( 'c2', [ $this->widget( 'w2', 'button' ), $this->widget( 'w3', 'image' ) ] ),
		];
		$new = [
			$this->container( 'c1', [ $this->widget( 'w1', 'heading' ), $this->widget( 'w4', 'divider' ) ] ),
		];

		$r = ElementorTreeDiff::diff( $old, $new );

		$this->assertSame( [ 'added' => 1, 'removed' => 3, 'moved' => 0, 'changed' => 0 ], $r['summary'] );
		$by = $this->by_id( $r );
		$this->assertSame( 'added', $by['w4']['op'] );
		$this->assertSame( 'removed', $by['c2']['op'] );
		$this->assertSame( 'removed', $by['w3']['op'] );
		$this->assertNull( $by['c2']['parent'] );
		$this->assertSame( 'c2', $by['w3']['parent'] );
	}

	public function test_v4_typed_props_are_shown_readably(): void {
		$old = [ $this->widget( 'h1', 'e-heading', [
			'title'   => [ '$$type' => 'string', 'value' => 'Hello' ],
			'classes' => [ '$$type' => 'classes', 'value' => [ 'e-a' ] ],
			'gap'     => [ '$$type' => 'size', 'value' => [ 'size' => 8, 'unit' => 'px' ] ],
		] ) ];
		$new = [ $this->widget( 'h1', 'e-heading', [
			'title'   => [ '$$type' => 'string', 'value' => 'Hello there' ],
			'classes' => [ '$$type' => 'classes', 'value' => [ 'e-a', 'e-b' ] ],
			'gap'     => [ '$$type' => 'size', 'value' => [ 'size' => 16, 'unit' => 'px' ] ],
		] ) ];

		$r = ElementorTreeDiff::diff( $old, $new );

		$fields = [];
		foreach ( $r['elements'][0]['settings'] as $field ) {
			$fields[ $field['path'] ] = $field;
		}
		$this->assertSame( [ 'Hello', 'Hello there' ], [ $fields['title']['before'], $fields['title']['after'] ] );
		$this->assertSame( [ 'e-a', 'e-a, e-b' ], [ $fields['classes']['before'], $fields['classes']['after'] ] );
		$this->assertSame( [ '8px', '16px' ], [ $fields['gap']['before'], $fields['gap']['after'] ] );
		$this->assertStringNotContainsString( '$$type', (string) json_encode( $r ) );
	}

	public function test_v4_local_styles_and_other_keys_are_reported_under_other(): void {
		$old = [ array_merge( $this->widget( 'h1', 'e-heading' ), [ 'styles' => [ 's1' => [ 'label' => 'local', 'variants' => [ [ 'props' => [ 'color' => [ '$$type' => 'color', 'value' => '#000' ] ] ] ] ] ] ] ) ];
		$new = [ array_merge( $this->widget( 'h1', 'e-heading' ), [ 'styles' => [ 's1' => [ 'label' => 'local', 'variants' => [ [ 'props' => [ 'color' => [ '$$type' => 'color', 'value' => '#f00' ] ] ] ] ] ] ] ) ];

		$r = ElementorTreeDiff::diff( $old, $new );

		$this->assertSame( [], $r['elements'][0]['settings'] );
		$this->assertCount( 1, $r['elements'][0]['other'] );
		$field = $r['elements'][0]['other'][0];
		$this->assertSame( [ 'styles.s1.variants.0.props.color', '#000', '#f00' ], [ $field['path'], $field['before'], $field['after'] ] );
	}

	public function test_a_widget_type_change_is_visible(): void {
		$old = [ $this->widget( 'w1', 'heading' ) ];
		$new = [ $this->widget( 'w1', 'text-editor' ) ];

		$r = ElementorTreeDiff::diff( $old, $new );

		$other = $r['elements'][0]['other'][0];
		$this->assertSame( [ 'widgetType', 'heading', 'text-editor' ], [ $other['path'], $other['before'], $other['after'] ] );
	}

	public function test_a_moved_and_edited_element_is_one_entry_counted_in_both(): void {
		$old = [ $this->container( 'c1', [ $this->widget( 'w1', 'heading', [ 'title' => 'A' ] ) ] ), $this->container( 'c2' ) ];
		$new = [ $this->container( 'c1' ), $this->container( 'c2', [ $this->widget( 'w1', 'heading', [ 'title' => 'B' ] ) ] ) ];

		$r = ElementorTreeDiff::diff( $old, $new );

		$this->assertCount( 1, $r['elements'] );
		$this->assertSame( 'moved', $r['elements'][0]['op'] );
		$this->assertCount( 1, $r['elements'][0]['settings'] );
		$this->assertSame( [ 'added' => 0, 'removed' => 0, 'moved' => 1, 'changed' => 1 ], $r['summary'] );
	}

	public function test_secret_settings_are_masked_by_key_and_by_content(): void {
		$old = [ $this->widget( 'w1', 'form', [ 'mailchimp_api_key' => 'old-key-12345678', 'note' => 'a' ] ) ];
		$new = [ $this->widget( 'w1', 'form', [ 'mailchimp_api_key' => 'new-key-87654321', 'note' => 'token = abcdef123456' ] ) ];

		$r    = ElementorTreeDiff::diff( $old, $new );
		$json = (string) json_encode( $r );

		$this->assertStringNotContainsString( 'old-key', $json );
		$this->assertStringNotContainsString( 'new-key', $json );
		$this->assertStringNotContainsString( 'abcdef123456', $json );
		$this->assertSame( 2, $r['masked'] );
	}

	public function test_a_secret_key_nested_in_a_setting_value_is_counted_as_masked(): void {
		$old = [ $this->widget( 'w1', 'form', [ 'title' => 'a' ] ) ];
		$new = [ $this->widget( 'w1', 'form', [ 'title' => 'a', 'mailer' => [ 'host' => 'smtp.example.com', 'servers' => [ [ 'pass' . 'word' => 'zz-leak-10' ] ] ] ] ) ];

		$r    = ElementorTreeDiff::diff( $old, $new );
		$json = (string) json_encode( $r );

		$this->assertStringNotContainsString( 'zz-leak-10', $json );
		$this->assertStringContainsString( 'smtp.example.com', $json );
		$this->assertSame( 1, $r['masked'] );
	}

	public function test_a_setting_value_with_nested_keys_that_are_not_secrets_is_not_counted_as_masked(): void {
		$old = [ $this->widget( 'w1', 'form', [ 'title' => 'a' ] ) ];
		$new = [ $this->widget( 'w1', 'form', [ 'title' => 'a', 'mailer' => [ 'host' => 'smtp.example.com', 'servers' => [ [ 'name' => 'one' ] ] ] ] ) ];

		$this->assertSame( 0, ElementorTreeDiff::diff( $old, $new )['masked'] );
	}

	public function test_accepts_stored_meta_strings(): void {
		$old = (string) json_encode( [ $this->widget( 'w1', 'heading', [ 'title' => 'A' ] ) ] );
		$new = (string) json_encode( [ $this->widget( 'w1', 'heading', [ 'title' => 'B' ] ) ] );

		$this->assertSame( 1, ElementorTreeDiff::diff( $old, $new )['summary']['changed'] );
		$this->assertSame( 'identical', ElementorTreeDiff::diff( $old, $old )['status'] );
		$this->assertSame( 'identical', ElementorTreeDiff::diff( 'not json', '' )['status'] );
	}

	public function test_empty_settings_shapes_are_equal(): void {
		$r = ElementorTreeDiff::diff( [ $this->widget( 'w1', 'heading', [] ) ], [ array_merge( $this->widget( 'w1', 'heading' ), [ 'settings' => (object) [] ] ) ] );
		$this->assertSame( 'identical', $r['status'] );
	}

	public function test_element_cap_cuts_and_counts_but_the_summary_is_exact(): void {
		$children = [];
		for ( $i = 0; $i < 30; $i++ ) {
			$children[] = $this->widget( 'n' . $i, 'heading' );
		}

		$r = ElementorTreeDiff::diff( [ $this->container( 'c1' ) ], [ $this->container( 'c1', $children ) ], [ 'max_elements' => 10 ] );

		$this->assertCount( 10, $r['elements'] );
		$this->assertTrue( $r['truncated'] );
		$this->assertSame( 20, $r['cut']['elements'] );
		$this->assertSame( 30, $r['summary']['added'] );
	}

	public function test_setting_changes_per_element_are_capped(): void {
		$old = [];
		$new = [];
		for ( $i = 0; $i < 40; $i++ ) {
			$old[ 's' . $i ] = 'a';
			$new[ 's' . $i ] = 'b';
		}

		$r = ElementorTreeDiff::diff( [ $this->widget( 'w1', 'heading', $old ) ], [ $this->widget( 'w1', 'heading', $new ) ], [ 'max_fields' => 8 ] );

		$this->assertCount( 8, $r['elements'][0]['settings'] );
		$this->assertSame( 32, $r['elements'][0]['fields_cut'] );
		$this->assertTrue( $r['truncated'] );
	}

	public function test_a_very_large_tree_returns_a_summary(): void {
		$children = [];
		for ( $i = 0; $i < 50; $i++ ) {
			$children[] = $this->widget( 'n' . $i, 'heading' );
		}

		$r = ElementorTreeDiff::diff( [ $this->container( 'c1', $children ) ], [ $this->container( 'c1' ) ], [ 'max_nodes' => 20 ] );

		$this->assertSame( 'too_large', $r['status'] );
		$this->assertSame( [], $r['elements'] );
		$this->assertSame( 51, $r['old_nodes'] );
	}

	public function test_elements_without_an_id_are_skipped_but_their_children_are_read(): void {
		$old = [ [ 'elType' => 'section', 'elements' => [ $this->widget( 'w1', 'heading', [ 'title' => 'A' ] ) ] ] ];
		$new = [ [ 'elType' => 'section', 'elements' => [ $this->widget( 'w1', 'heading', [ 'title' => 'B' ] ) ] ] ];

		$this->assertSame( 1, ElementorTreeDiff::diff( $old, $new )['summary']['changed'] );
	}

	public function test_a_label_is_taken_from_a_text_setting_and_masked(): void {
		$r = ElementorTreeDiff::diff( [], [ $this->widget( 'w1', 'heading', [ 'title' => 'Welcome home' ] ) ] );
		$this->assertSame( 'Welcome home', $r['elements'][0]['label'] );
	}
}
