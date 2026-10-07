<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Admin\Ui;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Admin\Ui\Html;

/**
 * @covers \Stonewright\WpMcp\Admin\Ui\Html
 */
final class HtmlTest extends TestCase {

	protected function setUp(): void {
		Html::reset_ids();
	}

	public function test_attribute_values_are_escaped(): void {
		self::assertSame( ' title="a &quot;quoted&quot; &lt;b&gt; &amp; c"', Html::attrs( [ 'title' => 'a "quoted" <b> & c' ] ) );
	}

	public function test_true_renders_a_bare_attribute_and_false_and_null_drop_it(): void {
		self::assertSame( ' disabled', Html::attrs( [ 'disabled' => true ] ) );
		self::assertSame( '', Html::attrs( [ 'disabled' => false, 'hidden' => null ] ) );
		self::assertSame( ' value=""', Html::attrs( [ 'value' => '' ] ), 'An empty value is a value.' );
	}

	public function test_event_handlers_and_inline_style_are_never_rendered(): void {
		$html = Html::attrs( [ 'onclick' => 'evil()', 'onmouseover' => 'evil()', 'style' => 'color:red', 'STYLE' => 'x', 'data-ok' => '1' ] );

		self::assertSame( ' data-ok="1"', $html );
	}

	public function test_only_allowlisted_attributes_data_and_aria_pass(): void {
		self::assertSame(
			' id="a" data-sw-ui-copy="#a" aria-label="Copy"',
			Html::attrs( [ 'id' => 'a', 'srcdoc' => '<script>', 'data-sw-ui-copy' => '#a', 'formaction' => 'x', 'aria-label' => 'Copy', 'xlink:href' => 'x' ] )
		);
	}

	public function test_a_form_can_carry_its_method_and_a_checked_url_as_its_action(): void {
		self::assertSame( ' method="post" action="https://example.test/wp-admin/admin-post.php"', Html::attrs( [ 'method' => 'post', 'action' => 'https://example.test/wp-admin/admin-post.php' ] ) );
		self::assertSame( ' method="post"', Html::attrs( [ 'method' => 'post', 'action' => 'javascript:alert(1)' ] ) );
		self::assertSame( '', Html::attrs( [ 'formaction' => 'https://example.test/' ] ), 'A button never redirects the form it belongs to.' );
	}

	public function test_a_text_field_can_carry_its_pattern_and_a_textarea_its_rows_and_spellcheck(): void {
		self::assertSame( ' pattern="[a-z0-9_-]+.php" rows="12" spellcheck="false"', Html::attrs( [ 'pattern' => '[a-z0-9_-]+.php', 'rows' => '12', 'spellcheck' => 'false' ] ) );
	}

	public function test_an_option_can_be_selected_and_a_choice_checked(): void {
		self::assertSame( ' value="a" selected', Html::attrs( [ 'value' => 'a', 'selected' => true ] ) );
		self::assertSame( ' value="a"', Html::attrs( [ 'value' => 'a', 'selected' => false ] ) );
	}

	public function test_a_time_element_can_carry_its_machine_readable_value(): void {
		self::assertSame( ' datetime="2026-10-07 06:00:00"', Html::attrs( [ 'datetime' => '2026-10-07 06:00:00' ] ) );
		self::assertSame( ' datetime="&quot;&gt;x"', Html::attrs( [ 'datetime' => '">x' ] ) );
	}

	public function test_malformed_attribute_names_are_dropped(): void {
		self::assertSame( '', Html::attrs( [ 'a b' => 'x', '"><script>' => 'x', '' => 'x', '1abc' => 'x' ] ) );
	}

	public function test_urls_are_escaped_and_a_script_url_drops_the_attribute(): void {
		self::assertSame( ' href="https://example.test/x"', Html::attrs( [ 'href' => 'https://example.test/x' ] ) );
		self::assertSame( '', Html::attrs( [ 'href' => 'javascript:alert(1)' ] ) );
	}

	public function test_classes_are_sanitized_deduplicated_and_empty_ones_are_dropped(): void {
		self::assertSame( 'a b c', Html::classes( 'a b', [ 'b', 'c' ], '', null ) );
		self::assertDoesNotMatchRegularExpression( '/["<>=\']/', Html::classes( 'x"<script onclick=1>' ), 'Markup characters never survive in a class name.' );
		self::assertSame( '', Html::attrs( [ 'class' => '' ] ), 'An empty class attribute is not printed.' );
		self::assertSame( ' class="a b"', Html::attrs( [ 'class' => [ 'a', 'b' ] ] ) );
	}

	public function test_text_is_escaped(): void {
		self::assertSame( '&lt;script&gt;alert(1)&lt;/script&gt; &amp; &quot;x&quot;', Html::text( '<script>alert(1)</script> & "x"' ) );
	}

	public function test_elements_and_void_elements(): void {
		self::assertSame( '<p class="a">x &amp; y</p>', Html::element( 'p', [ 'class' => 'a' ], Html::text( 'x & y' ) ) );
		self::assertSame( '<input type="text" readonly>', Html::void( 'input', [ 'type' => 'text', 'readonly' => true ] ) );
	}

	public function test_unique_ids_count_up_and_can_be_reset(): void {
		self::assertSame( 'sw-ui-copy-1', Html::unique_id( 'copy' ) );
		self::assertSame( 'sw-ui-copy-2', Html::unique_id( 'copy' ) );
		Html::reset_ids();
		self::assertSame( 'sw-ui-x-1', Html::unique_id( '' ) );
	}

	public function test_without_removes_the_attributes_a_helper_owns(): void {
		self::assertSame( [ 'data-a' => '1' ], Html::without( [ 'class' => 'x', 'data-a' => '1', 'type' => 'submit' ], [ 'class', 'type' ] ) );
	}
}
