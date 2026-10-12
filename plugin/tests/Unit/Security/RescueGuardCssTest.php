<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Security;

use Elementor\Core\Files\CSS\Post;
use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Elementor\RestoredCss;
use Stonewright\WpMcp\Security\Backup;
use Stonewright\WpMcp\Security\ChangeJournal;
use Stonewright\WpMcp\Security\HealthProbe;
use Stonewright\WpMcp\Security\RescueGuard;
use Stonewright\WpMcp\Support\AgentNotices;

/**
 * The automatic rollback of an Elementor write that broke the site regenerates the CSS of the page it put back, and the
 * error says so when that did not complete.
 *
 * @covers \Stonewright\WpMcp\Security\RescueGuard
 */
final class RescueGuardCssTest extends TestCase {

	private const POST = 31;

	private const DATA_A = '[{"id":"c0000001","elType":"container","settings":[],"elements":[{"id":"h0000001","elType":"widget","widgetType":"heading","settings":{"title":"Hi","title_color":"#aa0011"},"elements":[]}]}]';

	private const DATA_B = '[{"id":"c0000001","elType":"container","settings":[],"elements":[{"id":"h0000001","elType":"widget","widgetType":"heading","settings":{"title":"Hi","title_color":"#00bb22"},"elements":[]}]}]';

	private string $base;

	protected function setUp(): void {
		$this->base = sys_get_temp_dir() . '/sw-guard-css-' . bin2hex( random_bytes( 5 ) );
		mkdir( $this->base . '/uploads/elementor/css', 0700, true );
		$GLOBALS['stonewright_test_upload_dir']      = [ 'basedir' => $this->base . '/uploads', 'baseurl' => 'https://example.test/uploads', 'error' => false ];
		$GLOBALS['stonewright_test_options']         = [ 'stonewright_mode' => 'development' ];
		$GLOBALS['stonewright_test_posts']           = [];
		$GLOBALS['stonewright_test_transients']      = [];
		$GLOBALS['stonewright_test_filters']         = [];
		$GLOBALS['stonewright_test_active_plugins']  = [];
		$GLOBALS['stonewright_test_wpdb_inserts']    = [];
		$GLOBALS['stonewright_test_current_user_id'] = 7;
		$GLOBALS['stonewright_test_user_caps_by_id'] = [ 7 => [ 'manage_options' => true, 'read' => true, 'edit_post' => true ] ];
		ChangeJournal::reset_for_tests();
		RescueGuard::reset_for_tests();
		AgentNotices::reset_for_tests();
		RestoredCss::reset();
		HealthProbe::set_transport(
			static fn ( string $url ) => 'B' === self::live_color() && str_contains( $url, '?p=31' )
				? [ 'response' => [ 'code' => 500 ], 'body' => '<body id="error-page"><p>There has been a critical error on this website.</p></body>', 'headers' => [] ]
				: [ 'response' => [ 'code' => 200 ], 'body' => str_contains( $url, 'wp-json' ) ? '{"namespaces":["wp/v2"]}' : ( str_contains( $url, 'stonewright-rescue' ) ? 'data-sw-rescue-probe="ok"' : 'ok' ), 'headers' => [] ]
		);
	}

	protected function tearDown(): void {
		Post::$factory = null;
		HealthProbe::set_transport( null );
		RescueGuard::reset_for_tests();
		ChangeJournal::reset_for_tests();
		AgentNotices::reset_for_tests();
		RestoredCss::reset();
		unset( $GLOBALS['stonewright_test_upload_dir'] );
		$GLOBALS['stonewright_test_options']    = [];
		$GLOBALS['stonewright_test_filters']    = [];
		$GLOBALS['stonewright_test_transients'] = [];
		$GLOBALS['stonewright_test_posts']      = [];
		$this->remove_tree( $this->base );
	}

	private static function live_color(): string {
		return str_contains( (string) ( $GLOBALS['stonewright_test_posts'][ self::POST ]->meta['_elementor_data'] ?? '' ), '#00bb22' ) ? 'B' : 'A';
	}

	public function test_the_css_follows_the_automatic_rollback(): void {
		$this->armed_elementor_change();
		file_put_contents( $this->css_file(), 'color:#00bb22' );
		$this->generator();

		$result = RescueGuard::leave( [ 'ok' => true ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_rescue_write_rolled_back', $result->get_error_code() );
		self::assertSame( self::DATA_A, get_post_meta( self::POST, '_elementor_data', true ) );
		self::assertSame( 'color:#aa0011', (string) file_get_contents( $this->css_file() ) );
		self::assertArrayNotHasKey( 'css_regeneration', $result->get_error_data() );
	}

	public function test_a_regeneration_that_did_not_complete_is_named_in_the_error(): void {
		$this->armed_elementor_change();
		file_put_contents( $this->css_file(), 'color:#00bb22' );
		$this->generator( true );

		$result = RescueGuard::leave( [ 'ok' => true ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_rescue_write_rolled_back', $result->get_error_code() );
		$data = $result->get_error_data();
		self::assertSame( 'succeeded', $data['rollback_status'], 'The data was put back.' );
		self::assertSame( 'not_regenerated', $data['css_regeneration']['status'] ?? null );
		self::assertSame( 'stonewright/elementor-css-regenerate', $data['css_regeneration']['next_step'] ?? null );
		self::assertStringContainsString( 'was not regenerated', $result->get_error_message() );
		self::assertStringContainsString( 'stonewright/elementor-css-regenerate', $result->get_error_message() );
	}

	private function armed_elementor_change(): void {
		RescueGuard::enter( 'stonewright/elementor-v3-batch-mutate' );
		$GLOBALS['stonewright_test_posts'][ self::POST ] = (object) [
			'ID' => self::POST, 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Fixture', 'post_content' => 'body',
			'post_excerpt' => '', 'post_parent' => 0, 'post_name' => 'fixture', 'meta' => [ '_elementor_data' => self::DATA_A, '_elementor_edit_mode' => 'builder' ],
		];
		$snapshot = Backup::snapshot_post( self::POST );
		RescueGuard::arm_post_write( self::POST, $snapshot );
		$GLOBALS['stonewright_test_posts'][ self::POST ]->meta['_elementor_data'] = self::DATA_B;
	}

	private function css_file(): string {
		return $this->base . '/uploads/elementor/css/post-' . self::POST . '.css';
	}

	private function generator( bool $fail = false ): void {
		Post::$factory = function ( int $id ) use ( $fail ): object {
			return new class( $id, $this->base . '/uploads/elementor/css', $fail ) {
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
					return 'https://example.test/uploads/elementor/css/post-' . $this->post_id . '.css';
				}
			};
		};
	}

	private function remove_tree( string $path ): void {
		if ( ! is_dir( $path ) ) {
			return;
		}
		foreach ( (array) scandir( $path ) as $name ) {
			if ( '.' === $name || '..' === $name ) {
				continue;
			}
			$child = $path . '/' . $name;
			is_dir( $child ) && ! is_link( $child ) ? $this->remove_tree( $child ) : unlink( $child );
		}
		rmdir( $path );
	}
}
