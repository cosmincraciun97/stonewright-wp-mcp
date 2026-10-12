<?php
/**
 * The undo and the redo of an Elementor page from the change history leave the generated CSS in line with the data.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Security\Rollback;

use Elementor\Core\Files\CSS\Post;
use Stonewright\WpMcp\Elementor\RestoredCss;
use Stonewright\WpMcp\Security\ChangeRollback;

/**
 * @covers \Stonewright\WpMcp\Security\ChangeRollback
 * @covers \Stonewright\WpMcp\Security\Rollback\PostRollbackFamily
 */
final class ChangeRollbackCssTest extends RollbackTestCase {

	private const DATA_A = '[{"id":"c0000001","elType":"container","settings":[],"elements":[{"id":"h0000001","elType":"widget","widgetType":"heading","settings":{"title":"Hi","title_color":"#aa0011"},"elements":[]}]}]';

	private const DATA_B = '[{"id":"c0000001","elType":"container","settings":[],"elements":[{"id":"h0000001","elType":"widget","widgetType":"heading","settings":{"title":"Hi","title_color":"#00bb22"},"elements":[]}]}]';

	/** @var list<int> */
	private array $generated = [];

	protected function setUp(): void {
		parent::setUp();
		$this->generated = [];
		RestoredCss::reset();
		wp_mkdir_p( $this->uploads . '/elementor/css' );
	}

	protected function tearDown(): void {
		Post::$factory = null;
		RestoredCss::reset();
		parent::tearDown();
	}

	public function test_an_undo_regenerates_the_css_and_a_redo_regenerates_it_again(): void {
		$id = $this->changed_elementor_page();
		file_put_contents( $this->css_file(), 'color:#00bb22' );
		$this->generator();

		$undo = ChangeRollback::run( $id, [ 'by' => 'ability' ] );

		self::assertIsArray( $undo, $undo instanceof \WP_Error ? $undo->get_error_message() : '' );
		self::assertSame( 'color:#aa0011', (string) file_get_contents( $this->css_file() ) );
		self::assertSame( 'regenerated', $undo['css_regeneration']['status'] ?? null );
		self::assertSame( [], $undo['warnings'] );

		// The fake database of this fixture does not delete rows of the options table, so the leases of the first regeneration are cleared by hand.
		foreach ( array_keys( $GLOBALS['stonewright_test_options'] ) as $option ) {
			if ( str_starts_with( (string) $option, 'stonewright_elementor_' ) ) {
				unset( $GLOBALS['stonewright_test_options'][ $option ] );
			}
		}

		$redo = ChangeRollback::run( $undo['rollback_change_id'], [ 'by' => 'ability' ] );

		self::assertIsArray( $redo, $redo instanceof \WP_Error ? $redo->get_error_message() : '' );
		self::assertSame( 'redo', $redo['kind'] );
		self::assertSame( self::DATA_B, get_post_meta( self::POST, '_elementor_data', true ) );
		self::assertSame( 'color:#00bb22', (string) file_get_contents( $this->css_file() ) );
		self::assertSame( 'regenerated', $redo['css_regeneration']['status'] ?? null );
	}

	public function test_an_undo_whose_regeneration_failed_keeps_the_restore_and_says_so(): void {
		$id = $this->changed_elementor_page();
		file_put_contents( $this->css_file(), 'color:#00bb22' );
		$this->generator( true );

		$undo = ChangeRollback::run( $id, [ 'by' => 'ability' ] );

		self::assertIsArray( $undo, 'The data was put back, so the undo is not refused or taken back because of the CSS.' );
		self::assertSame( self::DATA_A, get_post_meta( self::POST, '_elementor_data', true ) );
		self::assertSame( 'succeeded', $undo['rollback_status'] );
		self::assertNotSame( '', $undo['rollback_change_id'], 'The undo is in the history.' );
		self::assertSame( 'not_regenerated', $undo['css_regeneration']['status'] ?? null );
		self::assertSame( 'stonewright/elementor-css-regenerate', $undo['css_regeneration']['next_step'] ?? null );
		$warnings = implode( ' ', array_map( 'strval', $undo['warnings'] ) );
		self::assertStringContainsString( 'was not regenerated', $warnings );
		self::assertStringContainsString( 'stonewright/elementor-css-regenerate', $warnings );
		self::assertSame( 'color:#00bb22', (string) file_get_contents( $this->css_file() ), 'The previous file is still there.' );
	}

	public function test_an_undo_of_a_page_without_elementor_data_has_no_css_result(): void {
		$id = $this->changed_page();
		$this->generator();

		$undo = ChangeRollback::run( $id, [ 'by' => 'ability' ] );

		self::assertIsArray( $undo );
		self::assertArrayNotHasKey( 'css_regeneration', $undo );
		self::assertSame( [], $this->generated );
	}

	private function changed_elementor_page(): string {
		$this->make_post( self::POST, [ 'post_title' => 'Original title', 'post_content' => 'Original body' ] );
		$this->set_meta( self::POST, '_elementor_edit_mode', 'builder' );
		$this->set_meta( self::POST, '_elementor_data', self::DATA_A );
		return $this->post_change( fn () => $this->set_meta( self::POST, '_elementor_data', self::DATA_B ) );
	}

	private function css_file(): string {
		return $this->uploads . '/elementor/css/post-' . self::POST . '.css';
	}

	private function generator( bool $fail = false ): void {
		Post::$factory = function ( int $id ) use ( $fail ): object {
			$this->generated[] = $id;
			return new class( $id, $this->uploads . '/elementor/css', $fail ) {
				public function __construct( private int $post_id, private string $dir, private bool $fail ) {
				}

				public function update_file(): void {
					if ( $this->fail ) {
						throw new \RuntimeException( 'generator failed' );
					}
					preg_match( '/"title_color":"([^"]+)"/', (string) get_post_meta( $this->post_id, '_elementor_data', true ), $m );
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
					return $this->dir . '/post-' . $this->post_id . '.css';
				}

				public function get_url(): string {
					return 'https://example.test/wp-content/uploads/elementor/css/post-' . $this->post_id . '.css';
				}
			};
		};
	}
}
