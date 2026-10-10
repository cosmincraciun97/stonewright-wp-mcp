<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Admin\Ui;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Admin\Ui\Button;
use Stonewright\WpMcp\Admin\Ui\EmptyState;
use Stonewright\WpMcp\Admin\Ui\Html;

/**
 * @covers \Stonewright\WpMcp\Admin\Ui\EmptyState
 */
final class EmptyStateTest extends TestCase {

	protected function setUp(): void {
		Html::reset_ids();
	}

	public function test_an_empty_state_says_what_why_and_what_next(): void {
		self::assertSame(
			'<div class="sw-ui-empty"><span class="sw-ui-empty__icon"><svg class="sw-ui-icon sw-ui-icon--lg" aria-hidden="true"><use href="#sw-ui-icon-info"></use></svg></span>'
			. '<h3 class="sw-ui-empty__title">Nothing to approve</h3>'
			. '<p class="sw-ui-empty__text">When an agent proposes custom code it stops here for you.</p>'
			. '<div class="sw-ui-actions"><a class="sw-ui-btn sw-ui-btn--primary" href="https://example.test/custom-code">Open Custom code</a></div></div>',
			EmptyState::render(
				'Nothing to approve',
				'When an agent proposes custom code it stops here for you.',
				[ 'actions_html' => Button::render( 'Open Custom code', [ 'href' => 'https://example.test/custom-code', 'variant' => 'primary' ] ) ]
			)
		);
	}

	/** @dataProvider variants */
	public function test_each_variant_has_a_class_and_a_fitting_icon( string $variant, string $class, string $icon ): void {
		$html = EmptyState::render( 'T', 'Text', [ 'variant' => $variant ] );

		self::assertStringContainsString( $class, $html );
		self::assertStringContainsString( '#sw-ui-icon-' . $icon, $html );
	}

	/** @return array<string, array{0: string, 1: string, 2: string}> */
	public static function variants(): array {
		return [
			'first run'  => [ 'first-run', 'sw-ui-empty sw-ui-empty--first-run', 'bolt' ],
			'error'      => [ 'error', 'sw-ui-empty sw-ui-empty--error', 'alert' ],
			'no results' => [ 'no-results', 'sw-ui-empty sw-ui-empty--no-results', 'search' ],
		];
	}

	public function test_the_inline_variant_is_one_row_with_no_heading(): void {
		self::assertSame(
			'<div class="sw-ui-empty sw-ui-empty--inline"><svg class="sw-ui-icon sw-ui-icon--lg" aria-hidden="true"><use href="#sw-ui-icon-info"></use></svg><div>No sandbox files are active. Nothing runs until you activate one.</div></div>',
			EmptyState::render( 'No sandbox files are active.', 'Nothing runs until you activate one.', [ 'variant' => 'inline' ] )
		);
	}

	public function test_the_heading_level_is_clamped_to_two_through_six(): void {
		self::assertStringContainsString( '<h2 class="sw-ui-empty__title">', EmptyState::render( 'T', '', [ 'heading' => 1 ] ) );
		self::assertStringContainsString( '<h4 class="sw-ui-empty__title">', EmptyState::render( 'T', '', [ 'heading' => 4 ] ) );
		self::assertStringContainsString( '<h6 class="sw-ui-empty__title">', EmptyState::render( 'T', '', [ 'heading' => 99 ] ) );
	}

	public function test_a_state_without_text_has_no_empty_paragraph(): void {
		self::assertStringNotContainsString( '<p', EmptyState::render( 'Nothing here' ) );
	}

	public function test_title_and_text_are_escaped(): void {
		$html = EmptyState::render( '<script>x</script>', '<img src=x onerror=alert(1)>' );

		self::assertStringNotContainsString( '<script', $html );
		self::assertStringNotContainsString( '<img', $html );
	}

	public function test_an_unknown_variant_is_the_default(): void {
		self::assertStringStartsWith( '<div class="sw-ui-empty">', EmptyState::render( 'T', '', [ 'variant' => 'wild' ] ) );
	}
}
