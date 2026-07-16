<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Seo;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\Seo\SeoMetaGet;
use Stonewright\WpMcp\Abilities\Seo\SeoMetaUpdate;
use Stonewright\WpMcp\Abilities\Seo\SeoStatus;

final class SeoAbilitiesTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_user_logged_in'] = true;
		$GLOBALS['stonewright_test_user_caps']      = [
			'read'       => true,
			'edit_post'  => true,
			'edit_posts' => true,
		];
		$GLOBALS['stonewright_test_options']['stonewright_mode'] = 'development';
		$GLOBALS['stonewright_test_post_meta']                   = [];
		$GLOBALS['stonewright_test_backup_calls']                = [];
		// Clear SEO plugin probes.
		foreach ( [ 'WPSEO_VERSION', 'RANK_MATH_VERSION', 'AIOSEO_VERSION', 'SEOPRESS_VERSION' ] as $c ) {
			// constants cannot be undefined; use test flags instead via adapter detect overrides if needed.
		}
	}

	public function test_status_without_plugin(): void {
		$GLOBALS['stonewright_test_seo_plugin'] = null;
		$result = ( new SeoStatus() )->execute( [] );
		$this->assertIsArray( $result );
		$this->assertFalse( $result['active'] );
	}

	public function test_meta_get_missing_plugin(): void {
		$GLOBALS['stonewright_test_seo_plugin'] = null;
		$result = ( new SeoMetaGet() )->execute( [ 'post_id' => 1 ] );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'stonewright_plugin_missing', $result->get_error_code() );
	}

	public function test_meta_update_with_yoast_flag(): void {
		$GLOBALS['stonewright_test_seo_plugin'] = 'yoast';
		$GLOBALS['stonewright_test_posts'][9] = (object) [
			'ID'           => 9,
			'post_title'   => 'P',
			'post_status'  => 'publish',
			'post_content' => '',
			'post_excerpt' => '',
			'post_type'    => 'post',
		];
		$result = ( new SeoMetaUpdate() )->execute(
			[
				'post_id'     => 9,
				'title'       => 'Hello SEO',
				'description' => 'Desc',
			]
		);
		$this->assertIsArray( $result );
		$this->assertSame( 'yoast', $result['plugin'] );
		// Read back via get_post_meta path used by the adapter.
		$this->assertSame( 'Hello SEO', (string) get_post_meta( 9, '_yoast_wpseo_title', true ) );
		$this->assertSame( 'Hello SEO', $result['title'] );
	}
}
