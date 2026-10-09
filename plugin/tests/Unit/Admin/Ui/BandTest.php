<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Admin\Ui;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Admin\Ui\Band;
use Stonewright\WpMcp\Admin\Ui\Html;

/**
 * @covers \Stonewright\WpMcp\Admin\Ui\Band
 */
final class BandTest extends TestCase {

	private const LOGO = 'https://example.test/wp-content/plugins/stonewright/assets/admin/stonewright-logo.png';

	protected function setUp(): void {
		Html::reset_ids();
	}

	/** @return array{label: string, url: string, current: bool, count: int|null, count_label: string, beta: bool} */
	private static function link( string $label, bool $current = false, bool $beta = false, ?int $count = null, string $count_label = '' ): array {
		return [
			'label'       => $label,
			'url'         => 'https://example.test/wp-admin/admin.php?page=' . strtolower( str_replace( ' ', '-', $label ) ),
			'current'     => $current,
			'count'       => $count,
			'count_label' => $count_label,
			'beta'        => $beta,
		];
	}

	/** @return list<array{hub: string, label: string, links: list<array{label: string, url: string, current: bool, count: int|null, count_label: string, beta: bool}>}> */
	private static function groups(): array {
		return [
			[ 'hub' => 'overview', 'label' => 'Overview', 'links' => [ self::link( 'Overview', true ) ] ],
			[ 'hub' => 'setup', 'label' => 'Setup', 'links' => [ self::link( 'Setup' ), self::link( 'Troubleshoot', false, true ) ] ],
			[ 'hub' => 'activity', 'label' => 'Activity', 'links' => [ self::link( 'Audit log', false, false, 3, 'open incidents' ), self::link( 'Rescue' ) ] ],
		];
	}

	private static function band( ?array $groups = null ): string {
		return Band::render( $groups ?? self::groups(), [ 'logo_url' => self::LOGO ] );
	}

	public function test_the_band_is_the_banner_of_the_page_with_the_mark_and_the_name_and_one_named_navigation(): void {
		$html = self::band();

		self::assertStringStartsWith( '<header class="sw-ui-band" role="banner" data-sw-ui-band>', $html );
		self::assertStringContainsString( '<img class="sw-ui-band__logo" src="' . self::LOGO . '" alt="Stonewright" width="28" height="28">', $html );
		self::assertStringContainsString( '<span class="sw-ui-band__name">Stonewright</span>', $html );
		self::assertSame( 1, substr_count( $html, '<nav ' ) );
		self::assertStringContainsString( '<nav class="sw-ui-band__nav" aria-label="Stonewright admin">', $html );
		self::assertStringNotContainsString( '<h1', $html, 'The product name is not a heading.' );
		self::assertDoesNotMatchRegularExpression( '/<a[^>]*>[^<]*<img/', $html, 'The mark and the name are not links.' );
	}

	public function test_a_hub_with_two_links_or_more_gets_its_label_and_a_single_link_gets_none(): void {
		$html = self::band();

		self::assertSame( 2, substr_count( $html, 'sw-ui-band__label' ), 'Setup and Activity have a label; Overview does not.' );
		self::assertStringContainsString( '<span class="sw-ui-band__label" aria-hidden="true">Setup</span>', $html );
		self::assertStringContainsString( '<span class="sw-ui-band__label" aria-hidden="true">Activity</span>', $html );
		self::assertSame( 2, substr_count( $html, 'sw-ui-band__group--labelled' ) );
		self::assertMatchesRegularExpression( '/<div class="sw-ui-band__group"><a class="sw-ui-band__link" href="[^"]*page=overview" aria-current="page">Overview<\/a><\/div>/', $html );
	}

	public function test_exactly_the_current_link_is_marked_as_the_current_page(): void {
		$html = self::band();

		self::assertSame( 1, substr_count( $html, 'aria-current="page"' ) );
		self::assertMatchesRegularExpression( '/href="[^"]*page=overview" aria-current="page">Overview</', $html );
		self::assertSame( 0, substr_count( self::band( [ [ 'hub' => 'setup', 'label' => 'Setup', 'links' => [ self::link( 'Setup' ) ] ] ] ), 'aria-current' ) );
	}

	public function test_an_experimental_link_has_the_marker_the_tooltip_hook_and_a_permanent_description(): void {
		$html = self::band();

		self::assertSame( 1, substr_count( $html, 'sw-ui-band__exp' ) );
		self::assertStringContainsString(
			'<a class="sw-ui-band__link sw-ui-band__link--exp" href="https://example.test/wp-admin/admin.php?page=troubleshoot" data-sw-ui-tip="This feature is experimental.">'
			. 'Troubleshoot<span class="sw-ui-band__exp" aria-hidden="true">EXP</span><span class="sw-ui-visually-hidden"> This feature is experimental.</span></a>',
			$html
		);
		self::assertSame( 1, substr_count( $html, 'data-sw-ui-tip' ), 'Other links never show a tooltip.' );
		self::assertStringNotContainsString( 'role="tooltip"', $html, 'The tooltip exists only while it is shown.' );
		self::assertStringNotContainsString( 'aria-describedby', $html, 'The script sets the description while the tooltip is shown.' );
	}

	public function test_a_count_is_quiet_and_has_words_for_assistive_technology(): void {
		$html = self::band();

		self::assertStringContainsString( 'Audit log <span class="sw-ui-band__count sw-ui-num">3</span><span class="sw-ui-visually-hidden"> open incidents</span>', $html );
		self::assertSame( 1, substr_count( $html, 'sw-ui-band__count' ) );
		self::assertStringNotContainsString( 'sw-ui-count', $html, 'The light count of the page content does not belong on the dark band.' );
	}

	public function test_text_and_urls_are_escaped(): void {
		$html = Band::render(
			[ [ 'hub' => 'x', 'label' => '<u>hub</u>', 'links' => [ [ 'label' => '<b>x</b>', 'url' => 'javascript:alert(1)', 'current' => false, 'count' => 1, 'count_label' => '<i>y</i>', 'beta' => true ], self::link( 'Two' ) ] ] ],
			[ 'logo_url' => 'javascript:alert(1)' ]
		);

		foreach ( [ '<b>', '<i>', '<u>', 'javascript:' ] as $unsafe ) {
			self::assertStringNotContainsString( $unsafe, $html, $unsafe );
		}
	}

	public function test_no_groups_still_prints_the_brand_and_an_empty_navigation_is_left_out(): void {
		$html = Band::render( [], [ 'logo_url' => self::LOGO ] );

		self::assertStringContainsString( 'sw-ui-band__brand', $html );
		self::assertStringNotContainsString( '<nav', $html );
	}

	public function test_the_marker_and_its_words_are_translatable_strings_shared_with_the_sidebar(): void {
		self::assertSame( 'EXP', Band::exp_text() );
		self::assertSame( 'This feature is experimental.', Band::exp_hint() );
	}
}
