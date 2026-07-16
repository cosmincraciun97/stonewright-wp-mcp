<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Gutenberg;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\Gutenberg\EditorSnapshotAbility;
use Stonewright\WpMcp\Gutenberg\EditorSnapshot;
use Stonewright\WpMcp\Gutenberg\FseTransactionQueue;
use Stonewright\WpMcp\Support\BlockSerializer;

/**
 * @covers \Stonewright\WpMcp\Gutenberg\EditorSnapshot
 * @covers \Stonewright\WpMcp\Gutenberg\FseTransactionQueue
 * @covers \Stonewright\WpMcp\Abilities\Gutenberg\EditorSnapshotAbility
 */
final class EditorSnapshotAndFseTxnTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_posts'] = [
			701 => (object) [
				'ID'           => 701,
				'post_type'    => 'wp_template',
				'post_status'  => 'publish',
				'post_title'   => 'Index',
				'post_content' => '<!-- wp:paragraph --><p>Hello</p><!-- /wp:paragraph -->',
				'post_excerpt' => '',
				'meta'         => [],
			],
			702 => (object) [
				'ID'           => 702,
				'post_type'    => 'wp_template_part',
				'post_status'  => 'publish',
				'post_title'   => 'Header',
				'post_content' => '<!-- wp:site-title /-->',
				'post_excerpt' => '',
				'meta'         => [],
			],
		];
		$GLOBALS['stonewright_test_options']        = [ 'stonewright_mode' => 'development' ];
		$GLOBALS['stonewright_test_user_caps']      = [ 'edit_posts' => true, 'edit_post' => true ];
		$GLOBALS['stonewright_test_user_logged_in'] = true;
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_posts']          = [];
		$GLOBALS['stonewright_test_options']        = [];
		$GLOBALS['stonewright_test_user_caps']      = [];
		$GLOBALS['stonewright_test_user_logged_in'] = false;
	}

	public function test_editor_snapshot_shape(): void {
		$snap = EditorSnapshot::capture();
		self::assertTrue( $snap['ok'] );
		self::assertArrayHasKey( 'theme', $snap );
		self::assertArrayHasKey( 'type', $snap['theme'] );
		self::assertArrayHasKey( 'templates', $snap );
		self::assertArrayHasKey( 'global_styles', $snap );
		self::assertArrayHasKey( 'present', $snap['global_styles'] );
		self::assertArrayHasKey( 'blocks', $snap );
		self::assertArrayHasKey( 'registered_count', $snap['blocks'] );
		self::assertIsInt( $snap['blocks']['registered_count'] );

		$ability = new EditorSnapshotAbility();
		self::assertSame( 'stonewright/gutenberg-editor-snapshot', $ability->name() );
		$result = $ability->execute( [] );
		self::assertIsArray( $result );
		self::assertTrue( $result['ok'] );
	}

	public function test_parse_serialize_round_trip_fixtures(): void {
		$fixture_dir = dirname( __DIR__, 2 ) . '/fixtures/gutenberg';
		self::assertDirectoryExists( $fixture_dir );

		$files = glob( $fixture_dir . '/*.json' ) ?: [];
		self::assertNotEmpty( $files );

		$checked = 0;
		foreach ( $files as $file ) {
			// Skip token-only helper fixtures that are not full blocks.
			$base = basename( $file );
			if ( str_contains( $base, '-tokens' ) ) {
				continue;
			}
			$raw = json_decode( (string) file_get_contents( $file ), true );
			if ( ! is_array( $raw ) || ! isset( $raw['blockName'] ) ) {
				continue;
			}
			$html = BlockSerializer::serialize( [ $raw ] );
			self::assertNotSame( '', $html, $base . ' serialized empty' );
			// Stable serialize: second pass must match first.
			$again = BlockSerializer::serialize( [ BlockSerializer::normalize( $raw ) ] );
			self::assertSame( $html, $again, $base . ' serialize not stable' );

			// Markup must mention the block name for non-void fixtures.
			if ( ! str_ends_with( $html, ' /-->' ) && ! str_contains( $html, ' /-->' ) ) {
				self::assertStringContainsString( (string) $raw['blockName'], $html, $base );
			}

			if ( function_exists( 'parse_blocks' ) ) {
				$parsed = parse_blocks( $html );
				self::assertIsArray( $parsed, $base );
			}
			++$checked;
		}
		self::assertGreaterThanOrEqual( 5, $checked, 'Expected multiple gutenberg fixtures to round-trip' );
	}

	public function test_fse_transaction_queue_snapshots_before_write(): void {
		$queue = ( new FseTransactionQueue() )
			->enqueue(
				[
					'type'    => 'template',
					'post_id' => 701,
					'label'   => 'index',
				]
			)
			->enqueue(
				[
					'type'    => 'template_part',
					'post_id' => 702,
					'label'   => 'header',
				]
			);

		$validated = $queue->validate();
		self::assertIsArray( $validated );
		self::assertTrue( $validated['ok'] );
		self::assertSame( 2, $validated['target_count'] );

		$result = $queue->snapshot_all();
		self::assertIsArray(
			$result,
			'Expected array, got WP_Error: ' . ( $result instanceof \WP_Error ? $result->get_error_message() : '' )
		);
		self::assertTrue( $result['ok'] );
		self::assertCount( 2, $result['snapshots'] );
		self::assertSame( 'snapshotted', $result['phase'] );
		foreach ( $result['snapshots'] as $row ) {
			self::assertNotEmpty( $row['snapshot_id'] );
		}
	}

	public function test_fse_transaction_queue_rejects_empty(): void {
		$result = ( new FseTransactionQueue() )->validate();
		self::assertInstanceOf( \WP_Error::class, $result );
	}
}
