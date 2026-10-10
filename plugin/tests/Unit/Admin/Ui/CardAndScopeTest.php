<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Admin\Ui;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Admin\Ui\Button;
use Stonewright\WpMcp\Admin\Ui\Card;
use Stonewright\WpMcp\Admin\Ui\Html;
use Stonewright\WpMcp\Admin\Ui\Scope;

/**
 * @covers \Stonewright\WpMcp\Admin\Ui\Card
 * @covers \Stonewright\WpMcp\Admin\Ui\Scope
 */
final class CardAndScopeTest extends TestCase {

	protected function setUp(): void {
		Html::reset_ids();
	}

	public function test_a_card_is_a_region_named_by_its_heading(): void {
		self::assertSame(
			'<section class="sw-ui-card" aria-labelledby="sw-ui-card-title-1"><div class="sw-ui-card__header"><div><h2 class="sw-ui-card__title" id="sw-ui-card-title-1">Needs attention</h2><p class="sw-ui-card__desc">3 items</p></div>'
			. '<div class="sw-ui-actions"><a class="sw-ui-link" href="https://example.test/all">View all</a></div></div>'
			. '<div class="sw-ui-card__body"><p>Body</p></div>'
			. '<div class="sw-ui-card__footer">Updated just now</div></section>',
			Card::render(
				'Needs attention',
				'<p>Body</p>',
				[
					'desc'         => '3 items',
					'actions_html' => '<a class="sw-ui-link" href="https://example.test/all">View all</a>',
					'footer_html'  => 'Updated just now',
				]
			)
		);
	}

	public function test_two_cards_do_not_share_a_heading_id(): void {
		$first  = Card::render( 'A', '' );
		$second = Card::render( 'B', '' );

		self::assertStringContainsString( 'id="sw-ui-card-title-1"', $first );
		self::assertStringContainsString( 'id="sw-ui-card-title-2"', $second );
	}

	public function test_flush_compact_and_the_heading_level(): void {
		$html = Card::render( 'Clients', '<table></table>', [ 'flush' => true, 'compact' => true, 'heading' => 3 ] );

		self::assertStringContainsString( 'class="sw-ui-card sw-ui-card--compact"', $html );
		self::assertStringContainsString( '<div class="sw-ui-card__body sw-ui-card__body--flush">', $html );
		self::assertStringContainsString( '<h3 class="sw-ui-card__title"', $html );
	}

	public function test_the_title_is_escaped_and_the_body_is_taken_as_given(): void {
		$html = Card::render( '<script>x</script>', Button::render( 'Go' ) );

		self::assertStringNotContainsString( '<script', $html );
		self::assertStringContainsString( '<button type="button" class="sw-ui-btn">Go</button>', $html );
	}

	public function test_a_scope_wraps_markup_in_the_root_the_layer_applies_inside(): void {
		self::assertSame( '<div class="sw-ui"><p>x</p></div>', Scope::wrap( '<p>x</p>' ) );
		self::assertSame( '<div class="sw-ui sw-ui-page extra" id="rescue"><p>x</p></div>', Scope::wrap( '<p>x</p>', [ 'page' => true, 'class' => 'extra', 'id' => 'rescue' ] ) );
	}
}
