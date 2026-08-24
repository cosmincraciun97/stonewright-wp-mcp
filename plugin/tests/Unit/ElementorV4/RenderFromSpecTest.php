<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\ElementorV4;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\ElementorV4\RenderFromSpec;
use Stonewright\WpMcp\Elementor\V4\AtomicSchemaRepository;
use Stonewright\WpMcp\Elementor\V4\V4FeatureGate;

/** @covers \Stonewright\WpMcp\Abilities\ElementorV4\RenderFromSpec */
final class RenderFromSpecTest extends TestCase {

	private const POST_ID = 9120;

	protected function setUp(): void {
		AtomicSchemaRepository::invalidate();
		V4FeatureGate::set_atomic_module_present_for_tests( true );
		$GLOBALS['stonewright_test_options'] = [
			'stonewright_mode'                => 'development',
			'stonewright_elementor_v4_atomic' => true,
		];
		$GLOBALS['stonewright_test_post_meta_calls'] = [];
		$GLOBALS['stonewright_test_update_post_meta_returns'] = [ '_stonewright_backups' => false ];
		$GLOBALS['stonewright_test_posts'] = [
			self::POST_ID => (object) [
				'ID'           => self::POST_ID,
				'post_type'    => 'page',
				'post_status'  => 'draft',
				'post_title'   => 'Synthetic page',
				'post_content' => '',
				'post_excerpt' => '',
				'meta'         => [ '_elementor_data' => '[]' ],
			],
		];
	}

	protected function tearDown(): void {
		V4FeatureGate::set_atomic_module_present_for_tests( null );
		AtomicSchemaRepository::invalidate();
		$GLOBALS['stonewright_test_options'] = [];
		$GLOBALS['stonewright_test_posts'] = [];
		$GLOBALS['stonewright_test_post_meta_calls'] = [];
		unset( $GLOBALS['stonewright_test_update_post_meta_returns'] );
	}

	public function test_failed_backup_snapshot_aborts_before_elementor_write(): void {
		$result = ( new RenderFromSpec() )->execute(
			[
				'post_id' => self::POST_ID,
				'dry_run' => false,
				'spec'    => [
					'page' => [ 'title' => 'Synthetic page' ],
					'sections' => [
						[
							'id' => 'hero',
							'blocks' => [ [ 'type' => 'heading', 'text' => 'Safe heading', 'level' => 2 ] ],
						],
					],
				],
			]
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_backup_failed', $result->get_error_code() );
		self::assertNotContains( '_elementor_data', array_column( $GLOBALS['stonewright_test_post_meta_calls'], 'meta_key' ) );
	}
}
