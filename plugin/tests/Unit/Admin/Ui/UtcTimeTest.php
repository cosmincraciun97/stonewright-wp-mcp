<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Admin\Ui;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Admin\Ui\UtcTime;

/**
 * @covers \Stonewright\WpMcp\Admin\Ui\UtcTime
 */
final class UtcTimeTest extends TestCase {

	public function test_a_stored_utc_time_is_a_time_element_with_the_utc_instant_in_the_title(): void {
		self::assertSame(
			'<time datetime="2026-10-07T06:01:41Z" title="2026-10-07 06:01:41 UTC">Oct 7, 2026, 06:01</time>',
			UtcTime::render( '2026-10-07 06:01:41' )
		);
	}

	public function test_a_value_that_is_not_a_time_is_printed_as_text(): void {
		self::assertSame( 'never', UtcTime::render( 'never' ) );
		self::assertSame( '&lt;b&gt;', UtcTime::render( '<b>' ) );
	}

	public function test_an_empty_value_prints_nothing(): void {
		self::assertSame( '', UtcTime::render( '' ) );
		self::assertSame( '', UtcTime::render( '0000-00-00 00:00:00' ) );
	}
}
