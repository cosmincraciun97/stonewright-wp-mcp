<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Site;

use Elementor\Core\Files\CSS\Post;
use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\Site\ChangeRestore;
use Stonewright\WpMcp\Elementor\RestoredCss;
use Stonewright\WpMcp\Security\Backup;

/**
 * Restoring a snapshot of an Elementor page leaves the generated CSS of that page in line with the restored data, and
 * the result says when it could not.
 *
 * @covers \Stonewright\WpMcp\Abilities\Site\ChangeRestore
 * @covers \Stonewright\WpMcp\Security\Backup::restore
 */
final class ChangeRestoreCssTest extends TestCase {

	private const POST_ID = 4301;

	private const DATA_A = '[{"id":"c0000001","elType":"container","settings":[],"elements":[{"id":"h0000001","elType":"widget","widgetType":"heading","settings":{"title":"Hi","title_color":"#aa0011"},"elements":[]}]}]';

	private const DATA_B = '[{"id":"c0000001","elType":"container","settings":[],"elements":[{"id":"h0000001","elType":"widget","widgetType":"heading","settings":{"title":"Hi","title_color":"#00bb22"},"elements":[]}]}]';

	private string $css_dir;

	protected function setUp(): void {
		$GLOBALS['stonewright_test_options']         = [ 'stonewright_mode' => 'development' ];
		$GLOBALS['stonewright_test_filters']         = [];
		$GLOBALS['stonewright_test_post_meta_calls'] = [];
		$GLOBALS['stonewright_test_wpdb_inserts']    = [];
		$GLOBALS['stonewright_test_user_logged_in']  = true;
		$GLOBALS['stonewright_test_user_caps']       = [ 'manage_options' => true, 'edit_posts' => true, 'edit_pages' => true, 'edit_post' => true, 'publish_posts' => true ];
		$GLOBALS['stonewright_test_current_user_id'] = 7;
		$GLOBALS['stonewright_test_posts']           = [
			self::POST_ID => (object) [
				'ID'           => self::POST_ID,
				'post_type'    => 'page',
				'post_content' => '',
				'post_status'  => 'publish',
				'post_title'   => 'Restore fixture',
				'post_excerpt' => '',
				'meta'         => [ '_elementor_data' => self::DATA_A, '_elementor_edit_mode' => 'builder' ],
			],
		];
		$this->css_dir = rtrim( (string) wp_upload_dir()['basedir'], '/\\' ) . '/elementor/css';
		wp_mkdir_p( $this->css_dir );
		RestoredCss::reset();
	}

	protected function tearDown(): void {
		Post::$factory = null;
		RestoredCss::reset();
		foreach ( [ 'post-' . self::POST_ID . '.css' ] as $name ) {
			if ( is_file( $this->css_dir . '/' . $name ) ) {
				unlink( $this->css_dir . '/' . $name );
			}
		}
		$GLOBALS['stonewright_test_options']         = [];
		$GLOBALS['stonewright_test_posts']           = [];
		$GLOBALS['stonewright_test_post_meta_calls'] = [];
		$GLOBALS['stonewright_test_wpdb_inserts']    = [];
		$GLOBALS['stonewright_test_user_caps']       = [];
		$GLOBALS['stonewright_test_user_logged_in']  = false;
	}

	public function test_the_result_says_the_css_was_regenerated_from_the_restored_data(): void {
		$snapshot = Backup::snapshot_post( self::POST_ID );
		$GLOBALS['stonewright_test_posts'][ self::POST_ID ]->meta['_elementor_data'] = self::DATA_B;
		file_put_contents( $this->css_dir . '/post-' . self::POST_ID . '.css', 'color:#00bb22' );
		$this->generator();

		$result = ( new ChangeRestore() )->execute( [ 'post_id' => self::POST_ID, 'snapshot_id' => $snapshot ] );

		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() : '' );
		self::assertTrue( $result['restored'] );
		self::assertSame( 'color:#aa0011', (string) file_get_contents( $this->css_dir . '/post-' . self::POST_ID . '.css' ) );
		self::assertSame( 'regenerated', $result['css_regeneration']['status'] ?? null );
		self::assertArrayNotHasKey( 'warnings', $result );
	}

	public function test_the_result_says_the_data_was_restored_and_the_css_was_not_regenerated(): void {
		$snapshot = Backup::snapshot_post( self::POST_ID );
		$GLOBALS['stonewright_test_posts'][ self::POST_ID ]->meta['_elementor_data'] = self::DATA_B;
		file_put_contents( $this->css_dir . '/post-' . self::POST_ID . '.css', 'color:#00bb22' );
		$this->generator( true );

		$result = ( new ChangeRestore() )->execute( [ 'post_id' => self::POST_ID, 'snapshot_id' => $snapshot ] );

		self::assertIsArray( $result, 'The restore stands.' );
		self::assertTrue( $result['restored'] );
		self::assertSame( self::DATA_A, get_post_meta( self::POST_ID, '_elementor_data', true ) );
		self::assertSame( 'not_regenerated', $result['css_regeneration']['status'] ?? null );
		self::assertSame( 'stonewright/elementor-css-regenerate', $result['css_regeneration']['next_step'] ?? null );
		self::assertStringContainsString( 'was not regenerated', (string) ( $result['warnings'][0] ?? '' ) );
		self::assertStringContainsString( 'stonewright/elementor-css-regenerate', (string) ( $result['warnings'][0] ?? '' ) );
		self::assertNotSame( '', $result['pre_restore_snapshot_id'], 'The restore can still be undone.' );
	}

	public function test_a_restore_that_changes_no_elementor_data_has_no_css_result(): void {
		$snapshot = Backup::snapshot_post( self::POST_ID );
		$GLOBALS['stonewright_test_posts'][ self::POST_ID ]->post_content = 'changed';
		$this->generator();

		$result = ( new ChangeRestore() )->execute( [ 'post_id' => self::POST_ID, 'snapshot_id' => $snapshot ] );

		self::assertIsArray( $result );
		self::assertArrayNotHasKey( 'css_regeneration', $result );
		self::assertArrayNotHasKey( 'warnings', $result );
	}

	public function test_the_output_schema_declares_the_css_report(): void {
		$properties = ( new ChangeRestore() )->output_schema()['properties'];

		self::assertArrayHasKey( 'css_regeneration', $properties );
		self::assertSame( [ 'regenerated', 'not_regenerated' ], $properties['css_regeneration']['properties']['status']['enum'] );
		self::assertArrayHasKey( 'warnings', $properties );
	}

	private function generator( bool $fail = false ): void {
		Post::$factory = function ( int $id ) use ( $fail ): object {
			return new class( $id, $this->css_dir, $fail ) {
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
					return rtrim( (string) wp_upload_dir()['baseurl'], '/' ) . '/elementor/css/post-' . $this->post_id . '.css';
				}
			};
		};
	}
}
