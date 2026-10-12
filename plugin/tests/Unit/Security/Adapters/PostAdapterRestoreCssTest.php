<?php
/**
 * The restore of a post image brings the generated Elementor CSS of that post in line with the restored document.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Security\Adapters;

use Elementor\Core\Files\CSS\Post;
use Stonewright\WpMcp\Elementor\RestoredCss;
use Stonewright\WpMcp\Security\Adapters\PostAdapter;

/**
 * @covers \Stonewright\WpMcp\Security\Adapters\PostAdapter::restore
 */
final class PostAdapterRestoreCssTest extends PostLedgerTestCase {

	private const POST = 52;

	private const DATA_A = '[{"id":"c0000001","elType":"container","settings":[],"elements":[{"id":"h0000001","elType":"widget","widgetType":"heading","settings":{"title":"Hi","title_color":"#aa0011"},"elements":[]}]}]';

	private const DATA_B = '[{"id":"c0000001","elType":"container","settings":[],"elements":[{"id":"h0000001","elType":"widget","widgetType":"heading","settings":{"title":"Hi","title_color":"#00bb22"},"elements":[]}]}]';

	/** @var list<int> */
	private array $generated = [];

	protected function setUp(): void {
		parent::setUp();
		$this->generated = [];
		RestoredCss::reset();
		wp_mkdir_p( $this->uploads . '/elementor/css' );
		$this->make_post( self::POST );
		$this->set_meta( self::POST, '_elementor_edit_mode', 'builder' );
		$this->set_meta( self::POST, '_elementor_data', self::DATA_A );
	}

	protected function tearDown(): void {
		Post::$factory = null;
		RestoredCss::reset();
		parent::tearDown();
	}

	public function test_an_undo_that_changes_the_document_regenerates_the_css_of_that_post(): void {
		$image = PostAdapter::image( self::POST );
		$this->set_meta( self::POST, '_elementor_data', self::DATA_B );
		file_put_contents( $this->uploads . '/elementor/css/post-52.css', 'color:#00bb22' );
		$this->generator();

		$result = PostAdapter::restore( self::POST, $image );

		self::assertIsArray( $result );
		self::assertTrue( $result['ok'] );
		self::assertSame( [ self::POST ], array_values( array_unique( $this->generated ) ) );
		self::assertSame( 'color:#aa0011', (string) file_get_contents( $this->uploads . '/elementor/css/post-52.css' ) );
		self::assertSame( 'regenerated', $result['css_regeneration']['status'] ?? null );
	}

	public function test_an_undo_whose_regeneration_fails_keeps_the_data_and_says_so(): void {
		$image = PostAdapter::image( self::POST );
		$this->set_meta( self::POST, '_elementor_data', self::DATA_B );
		file_put_contents( $this->uploads . '/elementor/css/post-52.css', 'color:#00bb22' );
		$this->generator( true );

		$result = PostAdapter::restore( self::POST, $image );

		self::assertIsArray( $result );
		self::assertTrue( $result['ok'], 'the data restore stands' );
		self::assertSame( self::DATA_A, $this->meta( self::POST, '_elementor_data' ) );
		self::assertSame( 'not_regenerated', $result['css_regeneration']['status'] ?? null );
		self::assertSame( 'stonewright/elementor-css-regenerate', $result['css_regeneration']['next_step'] ?? null );
		self::assertSame( 'color:#00bb22', (string) file_get_contents( $this->uploads . '/elementor/css/post-52.css' ), 'the previous file was put back' );
	}

	public function test_an_undo_that_leaves_the_document_as_it_was_does_not_regenerate(): void {
		$image = PostAdapter::image( self::POST );
		$GLOBALS['stonewright_test_posts'][ self::POST ]->post_title = 'Changed title';
		$this->generator();

		$result = PostAdapter::restore( self::POST, $image );

		self::assertIsArray( $result );
		self::assertTrue( $result['ok'] );
		self::assertSame( [], $this->generated );
		self::assertArrayNotHasKey( 'css_regeneration', $result );
	}

	public function test_a_restore_that_did_not_put_the_post_back_does_not_regenerate(): void {
		$image = PostAdapter::image( self::POST );
		$this->set_meta( self::POST, '_elementor_data', self::DATA_B );
		// The featured image of the image no longer exists, so the post cannot equal the image.
		$image['featured_image'] = 99;
		$this->generator();

		$result = PostAdapter::restore( self::POST, $image );

		self::assertIsArray( $result );
		self::assertFalse( $result['ok'] );
		self::assertSame( [], $this->generated );
		self::assertArrayNotHasKey( 'css_regeneration', $result );
	}

	private function generator( bool $fail = false ): void {
		Post::$factory = function ( int $id ) use ( $fail ): object {
			$this->generated[] = $id;
			return new class( $id, $this->uploads, $fail ) {
				public function __construct( private int $post_id, private string $uploads, private bool $fail ) {
				}

				public function update_file(): void {
					if ( $this->fail ) {
						throw new \RuntimeException( 'generator failed' );
					}
					$data = (string) get_post_meta( $this->post_id, '_elementor_data', true );
					preg_match( '/"title_color":"([^"]+)"/', $data, $m );
					file_put_contents( $this->get_path(), 'color:' . ( $m[1] ?? '' ) );
				}

				public function update(): void {
					$this->update_file();
					$meta           = (array) get_post_meta( $this->post_id, '_elementor_css', true );
					$meta['time']   = 1700000000;
					$meta['status'] = 'file';
					update_post_meta( $this->post_id, '_elementor_css', $meta );
				}

				/** @return mixed */
				public function get_meta( ?string $property = null ) {
					$meta = (array) get_post_meta( $this->post_id, '_elementor_css', true );
					return null === $property ? $meta : ( $meta[ $property ] ?? null );
				}

				public function get_path(): string {
					return $this->uploads . '/elementor/css/post-' . $this->post_id . '.css';
				}

				public function get_url(): string {
					return 'https://example.test/wp-content/uploads/elementor/css/post-' . $this->post_id . '.css';
				}
			};
		};
	}
}
