<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Security;

use Elementor\Core\Files\CSS\Post;
use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Elementor\RestoredCss;
use Stonewright\WpMcp\Security\Backup;
use Stonewright\WpMcp\Security\ChangeJournal;
use Stonewright\WpMcp\Security\HealthProbe;
use Stonewright\WpMcp\Security\RescueRollback;

/**
 * A rescue rollback of an Elementor page leaves the generated CSS of that page in line with the data it put back, and
 * says so when it could not.
 *
 * @covers \Stonewright\WpMcp\Security\RescueRollback
 * @covers \Stonewright\WpMcp\Security\RollbackRecipes
 */
final class RescueRollbackCssTest extends TestCase {

	private const POST = 31;

	private const DATA_A = '[{"id":"c0000001","elType":"container","settings":[],"elements":[{"id":"h0000001","elType":"widget","widgetType":"heading","settings":{"title":"Hi","title_color":"#aa0011"},"elements":[]}]}]';

	private const DATA_B = '[{"id":"c0000001","elType":"container","settings":[],"elements":[{"id":"h0000001","elType":"widget","widgetType":"heading","settings":{"title":"Hi","title_color":"#00bb22"},"elements":[]}]}]';

	private string $base;

	/** @var list<int> */
	private array $generated = [];

	protected function setUp(): void {
		$this->base = sys_get_temp_dir() . '/sw-rescue-css-' . bin2hex( random_bytes( 5 ) );
		mkdir( $this->base . '/uploads/elementor/css', 0700, true );
		$GLOBALS['stonewright_test_upload_dir']           = [ 'basedir' => $this->base . '/uploads', 'baseurl' => 'https://example.test/uploads', 'error' => false ];
		$GLOBALS['stonewright_test_options']              = [ 'stonewright_mode' => 'development' ];
		$GLOBALS['stonewright_test_posts']                = [];
		$GLOBALS['stonewright_test_transients']           = [];
		$GLOBALS['stonewright_test_filters']              = [];
		$GLOBALS['stonewright_test_active_plugins']       = [];
		$GLOBALS['stonewright_test_wpdb_inserts']         = [];
		$GLOBALS['stonewright_test_user_caps']            = [ 'manage_options' => true ];
		$GLOBALS['stonewright_test_current_user_id']      = 7;
		$GLOBALS['stonewright_test_user_caps_by_id']      = [ 7 => [ 'manage_options' => true, 'read' => true, 'edit_post' => true ] ];
		ChangeJournal::reset_for_tests();
		RestoredCss::reset();
		$this->generated = [];
		HealthProbe::set_transport(
			static fn ( string $url, array $args ) => [
				'response' => [ 'code' => 200 ],
				'body'     => str_contains( $url, 'wp-json' ) ? '{"namespaces":[]}' : ( str_contains( $url, 'stonewright-rescue' ) ? 'data-sw-rescue-probe="ok"' : 'ok' ),
				'headers'  => [],
			]
		);
	}

	protected function tearDown(): void {
		Post::$factory = null;
		HealthProbe::set_transport( null );
		RestoredCss::reset();
		ChangeJournal::reset_for_tests();
		unset( $GLOBALS['stonewright_test_upload_dir'] );
		$GLOBALS['stonewright_test_options']    = [];
		$GLOBALS['stonewright_test_user_caps']  = [];
		$GLOBALS['stonewright_test_transients'] = [];
		$GLOBALS['stonewright_test_posts']      = [];
		$this->remove_tree( $this->base );
	}

	public function test_a_rollback_regenerates_the_css_of_the_page_from_the_data_it_put_back(): void {
		$id = $this->changed_page();
		file_put_contents( $this->css_file(), 'color:#00bb22' );
		$this->generator();

		$result = RescueRollback::run( $id, [ 'by' => 'cli', 'user_id' => 7, 'human_approved' => true ] );

		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() : '' );
		self::assertTrue( $result['ok'] );
		self::assertSame( self::DATA_A, get_post_meta( self::POST, '_elementor_data', true ) );
		self::assertSame( 'color:#aa0011', (string) file_get_contents( $this->css_file() ) );
		self::assertSame( 'regenerated', $result['css_regeneration']['status'] ?? null );
		self::assertSame( [ self::POST ], array_values( array_unique( $this->generated ) ) );
	}

	public function test_a_rollback_whose_regeneration_failed_reports_the_restored_data_and_the_next_step(): void {
		$id = $this->changed_page();
		file_put_contents( $this->css_file(), 'color:#00bb22' );
		$this->generator( true );

		$result = RescueRollback::run( $id, [ 'by' => 'cli', 'user_id' => 7, 'human_approved' => true ] );

		self::assertIsArray( $result, 'The data was put back, so the rollback is not undone because of the CSS.' );
		self::assertSame( self::DATA_A, get_post_meta( self::POST, '_elementor_data', true ) );
		self::assertSame( 'succeeded', $result['rollback_status'] );
		self::assertSame( 'rolled_back', $result['state'] );
		self::assertSame( 'not_regenerated', $result['css_regeneration']['status'] ?? null );
		self::assertSame( 'stonewright/elementor-css-regenerate', $result['css_regeneration']['next_step'] ?? null );
		$warnings = implode( ' ', array_map( 'strval', $result['warnings'] ?? [] ) );
		self::assertStringContainsString( 'was not regenerated', $warnings );
		self::assertStringContainsString( 'stonewright/elementor-css-regenerate', $warnings );
		self::assertSame( 'color:#00bb22', (string) file_get_contents( $this->css_file() ) );
	}

	public function test_a_rollback_of_a_page_that_is_not_an_elementor_document_has_no_css_result(): void {
		$GLOBALS['stonewright_test_posts'][ self::POST ] = $this->post( [] );
		$snapshot = Backup::snapshot_post( self::POST );
		$GLOBALS['stonewright_test_posts'][ self::POST ]->post_content = 'newer body';
		$id = $this->arm( $snapshot );
		$this->generator();

		$result = RescueRollback::run( $id, [ 'by' => 'cli', 'user_id' => 7, 'human_approved' => true ] );

		self::assertIsArray( $result );
		self::assertArrayNotHasKey( 'css_regeneration', $result );
		self::assertSame( [], $this->generated );
	}

	public function test_a_rollback_of_something_else_does_not_report_the_css_of_an_earlier_restore(): void {
		$id = $this->changed_page();
		$this->generator();
		RescueRollback::run( $id, [ 'by' => 'cli', 'user_id' => 7, 'human_approved' => true ] );
		self::assertNotNull( RestoredCss::last( self::POST ) );

		update_option( 'blogname', 'Older name', false );
		$restore = Backup::snapshot_options( [ 'blogname' ] );
		update_option( 'blogname', 'Newer name', false );
		$entry = ChangeJournal::arm(
			[
				'ability'       => 'stonewright/settings-update',
				'resource_type' => 'option',
				'resource_key'  => 'blogname',
				'recipe'        => [ 'type' => 'option_restore', 'ref' => $restore ],
				'recipe_detail' => [ 'restore_id' => $restore ],
				'scope'         => 'light',
			]
		);
		ChangeJournal::settle( (string) $entry['id'], 'verified' );

		$result = RescueRollback::run( (string) $entry['id'], [ 'by' => 'cli', 'user_id' => 7, 'human_approved' => true ] );

		self::assertIsArray( $result );
		self::assertArrayNotHasKey( 'css_regeneration', $result );
	}

	private function changed_page(): string {
		$GLOBALS['stonewright_test_posts'][ self::POST ] = $this->post( [ '_elementor_data' => self::DATA_A, '_elementor_edit_mode' => 'builder' ] );
		$snapshot = Backup::snapshot_post( self::POST );
		self::assertNotSame( '', $snapshot );
		$GLOBALS['stonewright_test_posts'][ self::POST ]->meta['_elementor_data'] = self::DATA_B;
		return $this->arm( $snapshot );
	}

	private function arm( string $snapshot ): string {
		$entry = ChangeJournal::arm(
			[
				'ability'       => 'stonewright/elementor-v3-batch-mutate',
				'resource_type' => 'post',
				'resource_key'  => (string) self::POST,
				'recipe'        => [ 'type' => 'post_snapshot', 'ref' => $snapshot ],
				'recipe_detail' => [ 'post_id' => self::POST, 'snapshot_id' => $snapshot ],
				'scope'         => 'post',
			]
		);
		ChangeJournal::settle( (string) $entry['id'], 'verified' );
		return (string) $entry['id'];
	}

	/** @param array<string,mixed> $meta */
	private function post( array $meta ): object {
		return (object) [
			'ID' => self::POST, 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Fixture', 'post_content' => 'older body',
			'post_excerpt' => '', 'post_parent' => 0, 'post_name' => 'fixture', 'meta' => $meta,
		];
	}

	private function css_file(): string {
		return $this->base . '/uploads/elementor/css/post-' . self::POST . '.css';
	}

	private function generator( bool $fail = false ): void {
		Post::$factory = function ( int $id ) use ( $fail ): object {
			$this->generated[] = $id;
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
