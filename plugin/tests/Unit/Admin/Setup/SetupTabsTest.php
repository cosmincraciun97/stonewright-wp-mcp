<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Admin\Setup;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Admin\Setup\SetupTabs;

/**
 * @covers \Stonewright\WpMcp\Admin\Setup\SetupTabs
 */
final class SetupTabsTest extends TestCase {

	protected function setUp(): void {
		$_GET = [];
	}

	protected function tearDown(): void {
		$_GET = [];
	}

	public function test_the_views_are_these_four_in_this_order(): void {
		self::assertSame( [ 'get-started', 'settings', 'connections', 'updates' ], SetupTabs::ids() );
	}

	public function test_the_first_view_is_the_default(): void {
		self::assertSame( 'get-started', SetupTabs::current() );
	}

	public function test_the_tab_argument_chooses_the_view(): void {
		$_GET['tab'] = 'connections';

		self::assertSame( 'connections', SetupTabs::current() );
	}

	public function test_an_unknown_or_hostile_value_falls_back_to_the_default(): void {
		foreach ( [ 'nope', '', '../settings', [ 'settings' ], 'SETTINGS<script>' ] as $value ) {
			$_GET['tab'] = $value;
			self::assertSame( 'get-started', SetupTabs::current(), is_array( $value ) ? 'array' : (string) $value );
		}
	}

	public function test_a_url_names_the_page_and_the_view(): void {
		self::assertSame( 'https://example.test/wp-admin/admin.php?page=stonewright', SetupTabs::url() );
		self::assertSame( 'https://example.test/wp-admin/admin.php?page=stonewright', SetupTabs::url( 'get-started' ) );
		self::assertSame( 'https://example.test/wp-admin/admin.php?page=stonewright&tab=settings', SetupTabs::url( 'settings' ) );
	}

	public function test_a_url_can_carry_arguments_and_an_anchor(): void {
		self::assertSame(
			'https://example.test/wp-admin/admin.php?page=stonewright&tab=settings&lock_reset=1#stonewright-domain-lock',
			SetupTabs::url( 'settings', [ 'lock_reset' => '1' ], 'stonewright-domain-lock' )
		);
	}

	public function test_an_unknown_view_in_a_url_is_dropped(): void {
		self::assertSame( 'https://example.test/wp-admin/admin.php?page=stonewright', SetupTabs::url( 'nope' ) );
	}
}
