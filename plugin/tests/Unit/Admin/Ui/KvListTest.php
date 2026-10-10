<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Admin\Ui;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Admin\Ui\KvList;

/**
 * @covers \Stonewright\WpMcp\Admin\Ui\KvList
 */
final class KvListTest extends TestCase {

	public function test_facts_are_a_description_list(): void {
		self::assertSame(
			'<dl class="sw-ui-kv"><dt>Mode</dt><dd>Production-safe</dd><dt>Surface</dt><dd>Essential</dd></dl>',
			KvList::render( [ [ 'label' => 'Mode', 'value' => 'Production-safe' ], [ 'label' => 'Surface', 'value' => 'Essential' ] ] )
		);
	}

	public function test_the_inline_variant_wraps_each_pair_so_pairs_stay_together(): void {
		self::assertSame(
			'<dl class="sw-ui-kv sw-ui-kv--inline"><div><dt>Mode</dt><dd>Staging</dd></div><div><dt>Calls</dt><dd>245</dd></div></dl>',
			KvList::render( [ [ 'label' => 'Mode', 'value' => 'Staging' ], [ 'label' => 'Calls', 'value' => '245' ] ], [ 'inline' => true ] )
		);
	}

	public function test_a_value_can_be_markup_the_caller_has_built(): void {
		$html = KvList::render( [ [ 'label' => 'When', 'value_html' => '<time datetime="2026-10-06T21:17:35Z">just now</time>' ] ] );

		self::assertStringContainsString( '<dd><time datetime="2026-10-06T21:17:35Z">just now</time></dd>', $html );
	}

	public function test_labels_and_text_values_are_escaped(): void {
		$html = KvList::render( [ [ 'label' => '<b>L</b>', 'value' => '<script>alert(1)</script>' ] ] );

		self::assertStringNotContainsString( '<script', $html );
		self::assertStringNotContainsString( '<b>', $html );
	}

	public function test_a_name_for_assistive_technology_can_be_given(): void {
		self::assertStringStartsWith( '<dl class="sw-ui-kv" aria-label="Request facts">', KvList::render( [], [ 'label' => 'Request facts' ] ) );
	}

	public function test_an_empty_list_is_still_valid_markup(): void {
		self::assertSame( '<dl class="sw-ui-kv"></dl>', KvList::render( [] ) );
	}
}
