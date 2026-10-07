<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Admin\Ui;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Admin\Ui\Html;
use Stonewright\WpMcp\Admin\Ui\HubNav;

/**
 * @covers \Stonewright\WpMcp\Admin\Ui\HubNav
 */
final class HubNavTest extends TestCase {

	protected function setUp(): void {
		Html::reset_ids();
	}

	public function test_a_hub_is_a_named_navigation_of_plain_links(): void {
		$html = HubNav::render(
			[
				[ 'label' => 'Skills', 'url' => 'https://example.test/wp-admin/admin.php?page=stonewright-skills', 'current' => true, 'count' => null, 'count_label' => '' ],
				[ 'label' => 'Memory', 'url' => 'https://example.test/wp-admin/admin.php?page=stonewright-memory', 'current' => false, 'count' => null, 'count_label' => '' ],
			],
			'Knowledge sections'
		);

		self::assertSame(
			'<nav aria-label="Knowledge sections"><ul class="sw-ui-hubnav">'
			. '<li><a class="sw-ui-hubnav__link" href="https://example.test/wp-admin/admin.php?page=stonewright-skills" aria-current="page">Skills</a></li>'
			. '<li><a class="sw-ui-hubnav__link" href="https://example.test/wp-admin/admin.php?page=stonewright-memory">Memory</a></li>'
			. '</ul></nav>',
			$html
		);
	}

	public function test_one_tab_or_none_is_no_navigation(): void {
		self::assertSame( '', HubNav::render( [], 'x' ) );
		self::assertSame( '', HubNav::render( [ [ 'label' => 'Only', 'url' => 'https://example.test/', 'current' => true, 'count' => null, 'count_label' => '' ] ], 'x' ) );
	}

	public function test_a_count_is_a_number_with_words_for_assistive_technology(): void {
		$html = HubNav::render(
			[
				[ 'label' => 'Audit log', 'url' => 'https://example.test/a', 'current' => true, 'count' => 3, 'count_label' => 'open incidents' ],
				[ 'label' => 'Rescue', 'url' => 'https://example.test/b', 'current' => false, 'count' => null, 'count_label' => '' ],
			],
			'Activity sections'
		);

		self::assertStringContainsString( 'Audit log <span class="sw-ui-count sw-ui-num">3</span><span class="sw-ui-visually-hidden"> open incidents</span>', $html );
		self::assertSame( 1, substr_count( $html, 'sw-ui-count' ) );
	}

	public function test_text_and_urls_are_escaped(): void {
		$html = HubNav::render(
			[
				[ 'label' => '<b>x</b>', 'url' => 'javascript:alert(1)', 'current' => false, 'count' => 1, 'count_label' => '<i>y</i>' ],
				[ 'label' => 'Two', 'url' => 'https://example.test/', 'current' => false, 'count' => null, 'count_label' => '' ],
			],
			'<u>l</u>'
		);

		self::assertStringNotContainsString( '<b>', $html );
		self::assertStringNotContainsString( '<i>', $html );
		self::assertStringNotContainsString( '<u>', $html );
		self::assertStringNotContainsString( 'javascript:', $html );
	}
}
