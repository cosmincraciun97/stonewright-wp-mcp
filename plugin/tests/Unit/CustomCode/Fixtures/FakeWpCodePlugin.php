<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\CustomCode\Fixtures;

/**
 * The object wpcode() returns. Like WPCode it has the snippet cache and no snippet list API, so the
 * provider lists snippets through a WP_Query on the `wpcode` post type.
 */
final class FakeWpCodePlugin {

	public FakeWpCodeCache $cache;

	public function __construct() {
		$this->cache = new FakeWpCodeCache();
	}
}
