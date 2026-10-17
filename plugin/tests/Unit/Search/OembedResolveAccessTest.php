<?php
/**
 * oembed-resolve fetches third-party URLs from the server, as WordPress core's own oEmbed proxy does: edit_posts.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Search;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\Search\OembedResolve;

/**
 * @covers \Stonewright\WpMcp\Abilities\Search\OembedResolve
 */
final class OembedResolveAccessTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_user_logged_in']    = true;
		$GLOBALS['stonewright_test_user_can_callback'] = null;
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_user_caps'] = [];
	}

	public function test_a_user_who_can_only_read_is_refused(): void {
		$GLOBALS['stonewright_test_user_caps'] = [ 'read' => true ];

		self::assertNotTrue( ( new OembedResolve() )->permission_callback( [ 'url' => 'https://example.com/video' ] ) );
	}

	public function test_a_user_who_can_edit_posts_may_resolve(): void {
		$GLOBALS['stonewright_test_user_caps'] = [ 'read' => true, 'edit_posts' => true ];

		self::assertTrue( ( new OembedResolve() )->permission_callback( [ 'url' => 'https://example.com/video' ] ) );
	}

	public function test_a_caller_nobody_signed_in_for_is_refused(): void {
		$GLOBALS['stonewright_test_user_logged_in'] = false;
		$GLOBALS['stonewright_test_user_caps']      = [];

		self::assertNotTrue( ( new OembedResolve() )->permission_callback( [] ) );
	}
}
