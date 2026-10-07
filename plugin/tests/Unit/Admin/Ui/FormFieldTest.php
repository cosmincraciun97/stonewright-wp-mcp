<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Admin\Ui;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Admin\Ui\FormField;
use Stonewright\WpMcp\Admin\Ui\Html;

/**
 * @covers \Stonewright\WpMcp\Admin\Ui\FormField
 */
final class FormFieldTest extends TestCase {

	protected function setUp(): void {
		Html::reset_ids();
	}

	public function test_an_input_is_a_label_a_control_and_help_in_that_order(): void {
		self::assertSame(
			'<div class="sw-ui-field sw-ui-field--md"><label class="sw-ui-field__label" for="f-name">Name</label><input class="sw-ui-input" type="text" id="f-name" name="name" value="Brand voice" required aria-describedby="f-name-help"><span class="sw-ui-field__help" id="f-name-help">Shown in the list.</span></div>',
			FormField::input( 'Name', 'name', [ 'id' => 'f-name', 'value' => 'Brand voice', 'required' => true, 'help' => 'Shown in the list.', 'size' => 'md' ] )
		);
	}

	public function test_every_control_is_named_by_a_label_that_points_at_it(): void {
		foreach ( [
			FormField::input( 'Key', 'key' ),
			FormField::textarea( 'Value', 'value' ),
			FormField::select( 'Type', 'type', [ 'user' => 'User' ] ),
		] as $html ) {
			self::assertSame( 1, preg_match( '/<label[^>]*for="([^"]+)"/', $html, $label ), $html );
			self::assertStringContainsString( ' id="' . $label[1] . '"', $html );
		}
	}

	public function test_an_error_marks_the_control_invalid_and_says_the_cause_with_an_icon(): void {
		$html = FormField::input( 'Key', 'key', [ 'id' => 'k', 'error' => 'A key is required. Enter one and try again.' ] );

		self::assertStringContainsString( 'aria-invalid="true"', $html );
		self::assertStringContainsString( 'aria-describedby="k-error"', $html );
		self::assertMatchesRegularExpression( '/<span class="sw-ui-field__error" id="k-error"><svg[^>]*aria-hidden="true">.*<\/svg>A key is required\./', $html );
	}

	public function test_values_and_labels_are_escaped(): void {
		$html = FormField::input( '<b>Name</b>', 'name', [ 'value' => '"><script>x</script>' ] )
			. FormField::textarea( 'T', 't', [ 'value' => '</textarea><script>x</script>' ] );

		self::assertStringNotContainsString( '<script>', $html );
		self::assertStringNotContainsString( '<b>', $html );
	}

	public function test_a_textarea_keeps_its_rows_and_text(): void {
		$html = FormField::textarea( 'Value', 'value', [ 'id' => 'v', 'value' => "line 1\nline 2", 'rows' => 8, 'code' => true, 'maxlength' => 4000 ] );

		self::assertStringContainsString( '<textarea class="sw-ui-textarea sw-ui-textarea--code" id="v" name="value" rows="8" maxlength="4000">line 1' . "\n" . 'line 2</textarea>', $html );
	}

	public function test_a_select_marks_the_chosen_option(): void {
		$html = FormField::select( 'Type', 'type', [ 'user' => 'User', 'project' => 'Project' ], [ 'id' => 't', 'value' => 'project' ] );

		self::assertStringContainsString( '<select class="sw-ui-select" id="t" name="type">', $html );
		self::assertStringContainsString( '<option value="user">User</option>', $html );
		self::assertStringContainsString( '<option value="project" selected>Project</option>', $html );
	}

	public function test_a_switch_is_a_native_checkbox_with_the_switch_role_a_track_and_its_own_name(): void {
		$html = FormField::switch( 'Enable memory abilities', 'abilities', [ 'id' => 's', 'checked' => true, 'help' => 'Agents can read and write entries.', 'hidden_zero' => true ] );

		self::assertStringContainsString( '<input type="hidden" name="abilities" value="0">', $html );
		self::assertStringContainsString( '<label class="sw-ui-switch" for="s"><input type="checkbox" role="switch" id="s" name="abilities" value="1" checked aria-describedby="s-help"><span class="sw-ui-switch__track" aria-hidden="true"></span><span>Enable memory abilities</span></label>', $html );
		self::assertStringContainsString( '<span class="sw-ui-field__help" id="s-help">Agents can read and write entries.</span>', $html );
	}

	public function test_an_unchecked_switch_prints_no_checked_attribute(): void {
		self::assertStringNotContainsString( ' checked', FormField::switch( 'On', 'on', [ 'id' => 's' ] ) );
	}

	public function test_a_checkbox_is_wrapped_by_a_label_that_is_a_target_of_its_own(): void {
		$html = FormField::checkbox( 'I exported the bundle', 'export_confirmed', [ 'id' => 'c', 'required' => true ] );

		self::assertSame( '<label class="sw-ui-checkbox" for="c"><input type="checkbox" id="c" name="export_confirmed" value="1" required><span>I exported the bundle</span></label>', $html );
	}

	public function test_a_nonce_is_a_hidden_input_without_an_id_so_repeated_forms_share_no_id(): void {
		$html = FormField::nonce( 'stonewright_memory', '_stonewright_nonce' );

		self::assertSame( '<input type="hidden" name="_stonewright_nonce" value="' . wp_create_nonce( 'stonewright_memory' ) . '">', $html );
		self::assertStringNotContainsString( ' id=', FormField::nonce( 'a' ) );
	}

	public function test_a_post_form_posts_to_admin_post_with_its_action_nonce_and_hidden_values(): void {
		$html = FormField::post_form(
			'stonewright_memory_delete',
			'stonewright_memory',
			'_stonewright_nonce',
			'<button type="submit">Go</button>',
			[ 'hidden' => [ 'id' => '12' ], 'class' => 'x' ]
		);

		self::assertStringStartsWith( '<form class="x" method="post" action="', $html );
		self::assertStringContainsString( 'admin-post.php">', $html );
		self::assertStringContainsString( '<input type="hidden" name="action" value="stonewright_memory_delete">', $html );
		self::assertStringContainsString( '<input type="hidden" name="id" value="12">', $html );
		self::assertStringContainsString( 'name="_stonewright_nonce"', $html );
		self::assertStringEndsWith( '<button type="submit">Go</button></form>', $html );
	}

	public function test_generated_ids_are_unique_and_named_for_the_field(): void {
		$a = FormField::input( 'A', 'a' );
		$b = FormField::input( 'B', 'b' );

		preg_match( '/id="([^"]+)"/', $a . '', $first );
		preg_match( '/id="([^"]+)"/', $b . '', $second );
		self::assertNotSame( $first[1], $second[1] );
	}
}
