<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Seo;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\Seo\SeoAdapter;
use Stonewright\WpMcp\Abilities\Seo\SeoMetaGet;
use Stonewright\WpMcp\Abilities\Seo\SeoMetaUpdate;

/**
 * Each SEO plugin keeps "do not index" in its own shape. The adapter reads and writes that shape, and a plugin
 * that cannot be written through a safe API is reported as unsupported instead of as a success.
 *
 * @covers \Stonewright\WpMcp\Abilities\Seo\SeoAdapter
 * @covers \Stonewright\WpMcp\Abilities\Seo\SeoMetaGet
 * @covers \Stonewright\WpMcp\Abilities\Seo\SeoMetaUpdate
 */
final class SeoNoindexCodecTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_user_logged_in'] = true;
		$GLOBALS['stonewright_test_user_caps']      = [ 'read' => true, 'edit_post' => true, 'edit_posts' => true ];
		$GLOBALS['stonewright_test_options']['stonewright_mode'] = 'development';
		$GLOBALS['stonewright_test_posts']          = [
			9 => (object) [ 'ID' => 9, 'post_title' => 'P', 'post_status' => 'publish', 'post_content' => '', 'post_excerpt' => '', 'post_type' => 'post', 'meta' => [] ],
		];
		$GLOBALS['stonewright_test_post_meta_calls'] = [];
		$GLOBALS['stonewright_test_backup_calls']    = [];
		SeoAdapter::use_aioseo_model_for_tests( null );
	}

	protected function tearDown(): void {
		SeoAdapter::use_aioseo_model_for_tests( null );
		unset( $GLOBALS['stonewright_test_seo_plugin'] );
		$GLOBALS['stonewright_test_posts'] = [];
	}

	private function meta( string $key, mixed $value ): void {
		$GLOBALS['stonewright_test_posts'][9]->meta[ $key ] = $value;
	}

	private function stored( string $key ): mixed {
		return $GLOBALS['stonewright_test_posts'][9]->meta[ $key ] ?? null;
	}

	// --- Rank Math: an array of robots values --------------------------------------------------------

	public function test_rank_math_reads_noindex_from_the_robots_array(): void {
		$GLOBALS['stonewright_test_seo_plugin'] = 'rankmath';

		$this->meta( 'rank_math_robots', [ 'index', 'nofollow' ] );
		self::assertFalse( SeoAdapter::get_meta( 9 )['noindex'] );

		$this->meta( 'rank_math_robots', [ 'noindex', 'nofollow' ] );
		self::assertTrue( SeoAdapter::get_meta( 9 )['noindex'] );

		$this->meta( 'rank_math_robots', '' );
		self::assertFalse( SeoAdapter::get_meta( 9 )['noindex'] );
	}

	public function test_rank_math_writes_noindex_into_the_array_and_keeps_the_other_values(): void {
		$GLOBALS['stonewright_test_seo_plugin'] = 'rankmath';
		$this->meta( 'rank_math_robots', [ 'index', 'nofollow' ] );

		$on = SeoAdapter::update_meta( 9, [ 'noindex' => true ] );
		self::assertTrue( $on['noindex'] );
		self::assertSame( [ 'nofollow', 'noindex' ], $this->stored( 'rank_math_robots' ) );

		$off = SeoAdapter::update_meta( 9, [ 'noindex' => false ] );
		self::assertFalse( $off['noindex'] );
		self::assertSame( [ 'nofollow', 'index' ], $this->stored( 'rank_math_robots' ) );
	}

	public function test_rank_math_noindex_on_a_post_with_no_robots_value_stores_a_one_item_array(): void {
		$GLOBALS['stonewright_test_seo_plugin'] = 'rankmath';

		SeoAdapter::update_meta( 9, [ 'noindex' => true ] );

		self::assertSame( [ 'noindex' ], $this->stored( 'rank_math_robots' ) );
	}

	// --- SEOPress: "yes" ----------------------------------------------------------------------------

	public function test_seopress_uses_yes_for_noindex(): void {
		$GLOBALS['stonewright_test_seo_plugin'] = 'seopress';

		$this->meta( '_seopress_robots_index', 'yes' );
		self::assertTrue( SeoAdapter::get_meta( 9 )['noindex'] );

		$this->meta( '_seopress_robots_index', '' );
		self::assertFalse( SeoAdapter::get_meta( 9 )['noindex'] );

		$on = SeoAdapter::update_meta( 9, [ 'noindex' => true ] );
		self::assertTrue( $on['noindex'] );
		self::assertSame( 'yes', $this->stored( '_seopress_robots_index' ) );

		$off = SeoAdapter::update_meta( 9, [ 'noindex' => false ] );
		self::assertFalse( $off['noindex'] );
		self::assertNull( $this->stored( '_seopress_robots_index' ) );
	}

	// --- Yoast stays as it was -----------------------------------------------------------------------

	public function test_yoast_keeps_its_codec(): void {
		$GLOBALS['stonewright_test_seo_plugin'] = 'yoast';

		$on = SeoAdapter::update_meta( 9, [ 'noindex' => true ] );
		self::assertTrue( $on['noindex'] );
		self::assertSame( '1', $this->stored( '_yoast_wpseo_meta-robots-noindex' ) );

		$off = SeoAdapter::update_meta( 9, [ 'noindex' => false ] );
		self::assertFalse( $off['noindex'] );
		self::assertSame( '0', $this->stored( '_yoast_wpseo_meta-robots-noindex' ) );
	}

	// --- AIOSEO: its own table, through its model, or unsupported ------------------------------------

	public function test_aioseo_without_its_model_reports_unsupported_and_writes_nothing(): void {
		$GLOBALS['stonewright_test_seo_plugin'] = 'aioseo';

		$read = SeoAdapter::get_meta( 9 );
		self::assertNull( $read['noindex'] );
		self::assertFalse( $read['write_supported'] );

		$result = ( new SeoMetaUpdate() )->execute( [ 'post_id' => 9, 'title' => 'T', 'noindex' => true ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_seo_write_unsupported', $result->get_error_code() );
		self::assertSame( [], $GLOBALS['stonewright_test_post_meta_calls'] );
	}

	public function test_aioseo_with_its_model_reads_and_writes_through_it(): void {
		$GLOBALS['stonewright_test_seo_plugin'] = 'aioseo';
		FakeAioseoPost::reset();
		SeoAdapter::use_aioseo_model_for_tests( FakeAioseoPost::class );

		$before = SeoAdapter::get_meta( 9 );
		self::assertFalse( $before['noindex'] );
		self::assertTrue( $before['write_supported'] );

		$result = SeoAdapter::update_meta( 9, [ 'title' => 'New title', 'noindex' => true ] );

		self::assertIsArray( $result );
		self::assertTrue( $result['noindex'] );
		self::assertSame( 'New title', $result['title'] );
		self::assertSame( 1, FakeAioseoPost::$saves );
		self::assertFalse( FakeAioseoPost::$row['robots_default'] );
		self::assertSame( [], $GLOBALS['stonewright_test_post_meta_calls'] );
	}

	public function test_aioseo_with_its_model_refuses_a_focus_keyword_it_cannot_store(): void {
		$GLOBALS['stonewright_test_seo_plugin'] = 'aioseo';
		FakeAioseoPost::reset();
		SeoAdapter::use_aioseo_model_for_tests( FakeAioseoPost::class );

		$result = SeoAdapter::update_meta( 9, [ 'focus_keyword' => 'stone' ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_seo_write_unsupported', $result->get_error_code() );
		self::assertSame( 0, FakeAioseoPost::$saves );
	}

	// --- a write that did not take is not a success ---------------------------------------------------

	public function test_a_noindex_write_that_does_not_read_back_is_an_error(): void {
		$GLOBALS['stonewright_test_seo_plugin']             = 'seopress';
		$GLOBALS['stonewright_test_update_post_meta_returns'] = [ '_seopress_robots_index' => true ];

		try {
			$result = ( new SeoMetaUpdate() )->execute( [ 'post_id' => 9, 'noindex' => true ] );
		} finally {
			unset( $GLOBALS['stonewright_test_update_post_meta_returns'] );
		}

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_seo_noindex_not_applied', $result->get_error_code() );
	}

	public function test_the_get_ability_returns_the_codec_result(): void {
		$GLOBALS['stonewright_test_seo_plugin'] = 'rankmath';
		$this->meta( 'rank_math_robots', [ 'noindex' ] );

		$result = ( new SeoMetaGet() )->execute( [ 'post_id' => 9 ] );

		self::assertIsArray( $result );
		self::assertTrue( $result['noindex'] );
	}
}

/**
 * A stand-in for the AIOSEO post model: a row, a static getter that returns an object over it, and save().
 */
final class FakeAioseoPost {

	/** @var array<string, mixed> */
	public static array $row   = [];
	public static int $saves   = 0;

	public bool $robots_default = true;
	public bool $robots_noindex = false;
	public string $title         = '';
	public string $description   = '';
	public string $canonical_url = '';

	public static function reset(): void {
		self::$row   = [ 'robots_default' => true, 'robots_noindex' => false, 'title' => '', 'description' => '', 'canonical_url' => '' ];
		self::$saves = 0;
	}

	public static function getPost( int $post_id ): self {
		$post = new self();
		foreach ( self::$row as $key => $value ) {
			$post->{$key} = $value;
		}
		return $post;
	}

	public function save(): void {
		++self::$saves;
		self::$row = [
			'robots_default' => $this->robots_default,
			'robots_noindex' => $this->robots_noindex,
			'title'          => $this->title,
			'description'    => $this->description,
			'canonical_url'  => $this->canonical_url,
		];
	}
}
