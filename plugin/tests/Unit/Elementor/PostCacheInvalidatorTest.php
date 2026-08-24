<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Elementor;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Elementor\PostCacheInvalidator;

/** @covers \Stonewright\WpMcp\Elementor\PostCacheInvalidator */
final class PostCacheInvalidatorTest extends TestCase {
	private object $elementor_instance;

	protected function setUp(): void {
		$this->elementor_instance = \Elementor\Plugin::$instance;
		$GLOBALS['stonewright_test_posts'][701] = (object) [
			'ID'   => 701,
			'meta' => [
				'_elementor_element_cache' => '<div>stale</div>',
				'_elementor_css'           => [ 'time' => 123, 'status' => 'file' ],
			],
		];
		\Elementor\Plugin::$instance = (object) [
			'posts_css_manager' => new class() {
				public function clear_cache_post(): void {
					throw new \RuntimeException( 'CSS cache must not be touched.' );
				}
			},
		];
	}

	protected function tearDown(): void {
		\Elementor\Plugin::$instance = $this->elementor_instance;
		unset( $GLOBALS['stonewright_test_posts'][701] );
	}

	public function test_invalidates_only_post_html_cache_and_preserves_all_css_state(): void {
		$result = PostCacheInvalidator::invalidate( 701 );

		self::assertTrue( $result['ok'] );
		self::assertTrue( $result['element_cache']['deleted'] );
		self::assertArrayNotHasKey( '_elementor_element_cache', $GLOBALS['stonewright_test_posts'][701]->meta );
		self::assertSame( [ 'time' => 123, 'status' => 'file' ], $GLOBALS['stonewright_test_posts'][701]->meta['_elementor_css'] );
		self::assertSame( 'not_touched', $result['css_cache']['method'] );
		self::assertFalse( $result['css_cache']['cleared'] );
		self::assertFalse( $result['atomic_styles_notified'] );
	}

	public function test_treats_an_empty_cache_value_as_present_and_verifies_absence(): void {
		$GLOBALS['stonewright_test_posts'][701]->meta['_elementor_element_cache'] = '';

		$result = PostCacheInvalidator::invalidate( 701 );

		self::assertTrue( $result['element_cache']['existed'] );
		self::assertTrue( $result['element_cache']['deleted'] );
		self::assertTrue( $result['ok'] );
	}

	public function test_treats_an_already_absent_cache_as_a_closed_state(): void {
		unset( $GLOBALS['stonewright_test_posts'][701]->meta['_elementor_element_cache'] );

		$result = PostCacheInvalidator::invalidate( 701 );

		self::assertTrue( $result['ok'] );
		self::assertFalse( $result['element_cache']['existed'] );
		self::assertTrue( $result['element_cache']['absent_after'] );
	}

	public function test_fails_when_meta_delete_reports_success_but_readback_still_exists(): void {
		$GLOBALS['stonewright_test_filters']['delete_post_metadata'] = static fn( mixed $value, int $post_id, string $key ): mixed =>
			'_elementor_element_cache' === $key ? true : $value;

		try {
			$result = PostCacheInvalidator::invalidate( 701 );
		} finally {
			unset( $GLOBALS['stonewright_test_filters']['delete_post_metadata'] );
		}

		self::assertFalse( $result['ok'] );
		self::assertFalse( $result['element_cache']['deleted'] );
		self::assertFalse( $result['element_cache']['absent_after'] );
	}

	public function test_restores_an_exact_cache_snapshot(): void {
		$snapshot = PostCacheInvalidator::snapshot( 701 );
		self::assertTrue( PostCacheInvalidator::invalidate( 701 )['ok'] );

		$restored = PostCacheInvalidator::restore( 701, $snapshot );

		self::assertTrue( $restored['ok'] );
		self::assertTrue( $restored['present'] );
		self::assertSame( '<div>stale</div>', get_post_meta( 701, '_elementor_element_cache', true ) );
	}

	public function test_restores_exact_absence_without_treating_delete_noop_as_failure(): void {
		unset( $GLOBALS['stonewright_test_posts'][701]->meta['_elementor_element_cache'] );
		$snapshot = PostCacheInvalidator::snapshot( 701 );

		self::assertTrue( PostCacheInvalidator::restore( 701, $snapshot )['ok'] );
		self::assertFalse( PostCacheInvalidator::restore( 701, $snapshot )['present'] );
	}
}
