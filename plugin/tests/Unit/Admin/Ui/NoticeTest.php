<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Admin\Ui;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Admin\Ui\Button;
use Stonewright\WpMcp\Admin\Ui\Html;
use Stonewright\WpMcp\Admin\Ui\Notice;

/**
 * @covers \Stonewright\WpMcp\Admin\Ui\Notice
 */
final class NoticeTest extends TestCase {

	protected function setUp(): void {
		Html::reset_ids();
	}

	public function test_a_notice_is_announced_and_carries_an_icon_title_and_text(): void {
		self::assertSame(
			'<div class="sw-ui-notice sw-ui-notice--ok" role="status"><svg class="sw-ui-icon sw-ui-icon--lg" aria-hidden="true"><use href="#sw-ui-icon-check"></use></svg><div><div class="sw-ui-notice__title">Settings saved</div><div class="sw-ui-notice__text">Connected clients pick up the change.</div></div></div>',
			Notice::render( 'ok', 'Settings saved', 'Connected clients pick up the change.' )
		);
	}

	/** @dataProvider roles */
	public function test_errors_and_warnings_interrupt_and_the_rest_wait( string $variant, string $role, string $icon ): void {
		$html = Notice::render( $variant, 'T' );

		self::assertStringContainsString( ' role="' . $role . '"', $html );
		self::assertStringContainsString( '#sw-ui-icon-' . $icon, $html );
		self::assertStringContainsString( 'sw-ui-notice--' . $variant, $html );
	}

	/** @return array<string, array{0: string, 1: string, 2: string}> */
	public static function roles(): array {
		return [
			'ok'     => [ 'ok', 'status', 'check' ],
			'info'   => [ 'info', 'status', 'info' ],
			'warn'   => [ 'warn', 'alert', 'alert' ],
			'danger' => [ 'danger', 'alert', 'x' ],
		];
	}

	public function test_an_unknown_variant_is_informational(): void {
		$html = Notice::render( 'loud', 'T' );

		self::assertStringContainsString( 'sw-ui-notice--info', $html );
		self::assertStringContainsString( ' role="status"', $html );
	}

	public function test_a_callout_is_static_guidance_without_a_live_role(): void {
		$html = Notice::callout( 'warn', 'Human approval only', 'Agents must stop here.' );

		self::assertStringStartsWith( '<div class="sw-ui-callout sw-ui-callout--warn">', $html );
		self::assertStringNotContainsString( 'role=', $html );
		self::assertStringContainsString( 'sw-ui-notice__title', $html, 'Title and text styles are shared.' );
	}

	public function test_actions_and_html_text_are_markup_the_caller_has_built(): void {
		$html = Notice::render(
			'danger',
			'The MCP endpoint answered 403',
			'',
			[
				'text_html'    => 'Ask the host to <a href="https://example.test/help">allow POST</a>.',
				'actions_html' => Button::render( 'Run again', [ 'size' => 'sm' ] ),
			]
		);

		self::assertStringContainsString( '<div class="sw-ui-notice__text">Ask the host to <a href="https://example.test/help">allow POST</a>.</div>', $html );
		self::assertStringContainsString( '<div class="sw-ui-notice__actions sw-ui-actions"><button type="button" class="sw-ui-btn sw-ui-btn--sm">Run again</button></div>', $html );
	}

	public function test_title_and_text_are_escaped(): void {
		$html = Notice::render( 'info', '<script>alert(1)</script>', '<img src=x onerror=alert(1)>' );

		self::assertStringNotContainsString( '<script', $html );
		self::assertStringNotContainsString( '<img', $html );
		self::assertStringContainsString( '&lt;script&gt;alert(1)&lt;/script&gt;', $html );
	}

	public function test_the_icon_can_be_chosen_and_an_unknown_one_falls_back(): void {
		self::assertStringContainsString( '#sw-ui-icon-lock', Notice::render( 'info', 'T', '', [ 'icon' => 'lock' ] ) );
		self::assertStringContainsString( '#sw-ui-icon-info', Notice::render( 'info', 'T', '', [ 'icon' => 'nope' ] ) );
	}

	public function test_the_role_can_be_raised_but_not_made_up(): void {
		self::assertStringContainsString( ' role="alert"', Notice::render( 'info', 'T', '', [ 'role' => 'alert' ] ) );
		self::assertStringContainsString( ' role="status"', Notice::render( 'info', 'T', '', [ 'role' => 'dialog' ] ) );
	}

	public function test_a_caller_cannot_remove_the_role_or_class(): void {
		$html = Notice::render( 'danger', 'T', '', [ 'attrs' => [ 'role' => 'presentation', 'class' => 'x', 'data-case' => '1' ] ] );

		self::assertStringContainsString( 'role="alert"', $html );
		self::assertStringNotContainsString( 'presentation', $html );
		self::assertStringContainsString( ' data-case="1"', $html );
	}
}
