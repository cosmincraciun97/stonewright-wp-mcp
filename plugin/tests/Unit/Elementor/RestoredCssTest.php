<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Elementor;

use Elementor\Core\Files\CSS\Post;
use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Elementor\CssTargetResolver;
use Stonewright\WpMcp\Elementor\RestoredCss;
use Stonewright\WpMcp\Elementor\Write\PostWriteLock;
use Stonewright\WpMcp\Security\Backup;

/**
 * A restore of an Elementor document brings the generated CSS of that one post back in line with it.
 *
 * @covers \Stonewright\WpMcp\Elementor\RestoredCss
 * @covers \Stonewright\WpMcp\Security\Backup::restore
 */
final class RestoredCssTest extends TestCase {

	private const POST_ID = 321;

	private string $css_dir;

	/** @var list<int> Post ids the CSS object was created for. */
	private array $generated = [];

	protected function setUp(): void {
		$GLOBALS['stonewright_test_posts']        = [];
		$GLOBALS['stonewright_test_options']      = [ 'stonewright_mode' => 'development' ];
		$GLOBALS['stonewright_test_actions']      = [];
		$GLOBALS['stonewright_test_asset_responses'] = [];
		$GLOBALS['stonewright_test_css_regenerate_events'] = [];
		$this->generated = [];
		$uploads         = wp_upload_dir();
		$this->css_dir   = rtrim( (string) $uploads['basedir'], '/\\' ) . '/elementor/css';
		wp_mkdir_p( $this->css_dir );
		$this->remove_css_assets();
		RestoredCss::reset();
	}

	protected function tearDown(): void {
		Post::$factory = null;
		RestoredCss::reset();
		unset( $GLOBALS['stonewright_test_css_new_time'], $GLOBALS['stonewright_test_css_regenerate_events'] );
		$GLOBALS['stonewright_test_posts']           = [];
		$GLOBALS['stonewright_test_options']         = [];
		$GLOBALS['stonewright_test_asset_responses'] = [];
		$this->remove_css_assets();
	}

	public function test_a_restore_that_changes_the_document_regenerates_the_css_of_that_post_from_the_restored_data(): void {
		$snapshot_id = $this->seed_post_and_snapshot( '#aa0011' );
		$this->put_document( '#00bb22' );
		$this->write_css( 'post-' . self::POST_ID . '.css', 'color:#00bb22' );
		$this->write_css( 'post-999.css', 'sibling' );
		$this->configure_generator();

		self::assertTrue( Backup::restore( self::POST_ID, $snapshot_id ) );

		self::assertSame( [ self::POST_ID ], array_values( array_unique( $this->generated ) ) );
		self::assertSame( 'color:#aa0011', $this->read_css( 'post-' . self::POST_ID . '.css' ) );
		self::assertSame( 'sibling', $this->read_css( 'post-999.css' ) );
		$css = RestoredCss::last( self::POST_ID );
		self::assertIsArray( $css );
		self::assertSame( 'regenerated', $css['status'] );
		self::assertTrue( $css['css_version_changed'] );
		self::assertArrayNotHasKey( 'next_step', $css );
	}

	public function test_a_restore_takes_no_snapshot_and_runs_no_ability_of_its_own(): void {
		$snapshot_id = $this->seed_post_and_snapshot( '#aa0011' );
		$this->put_document( '#00bb22' );
		$this->configure_generator();
		$before = array_keys( Backup::list_snapshots( self::POST_ID ) );

		self::assertTrue( Backup::restore( self::POST_ID, $snapshot_id ) );

		self::assertSame( 'regenerated', RestoredCss::last( self::POST_ID )['status'] ?? null );
		self::assertSame( $before, array_keys( Backup::list_snapshots( self::POST_ID ) ), 'the regeneration must not write snapshots' );
		self::assertSame( [], $GLOBALS['stonewright_test_css_regenerate_events'], 'the regeneration must not go through the css-regenerate ability' );
	}

	public function test_a_restore_that_leaves_the_document_as_it_was_does_not_regenerate(): void {
		$snapshot_id = $this->seed_post_and_snapshot( '#aa0011' );
		$this->configure_generator();

		self::assertTrue( Backup::restore( self::POST_ID, $snapshot_id ) );

		self::assertSame( [], $this->generated );
		self::assertNull( RestoredCss::last( self::POST_ID ) );
	}

	public function test_a_restore_of_a_post_without_elementor_data_does_not_regenerate(): void {
		$GLOBALS['stonewright_test_posts'][ self::POST_ID ] = $this->post( [] );
		$snapshot_id = Backup::snapshot_post( self::POST_ID );
		$GLOBALS['stonewright_test_posts'][ self::POST_ID ]->post_title = 'Changed';
		$this->configure_generator();

		self::assertTrue( Backup::restore( self::POST_ID, $snapshot_id ) );

		self::assertSame( [], $this->generated );
		self::assertNull( RestoredCss::last( self::POST_ID ) );
	}

	public function test_a_failed_regeneration_keeps_the_restored_data_and_says_the_css_was_not_regenerated(): void {
		$snapshot_id = $this->seed_post_and_snapshot( '#aa0011' );
		$this->put_document( '#00bb22' );
		$this->write_css( 'post-' . self::POST_ID . '.css', 'color:#00bb22' );
		Post::$factory = function ( int $id ): object {
			$this->generated[] = $id;
			return new class( $id, $this->css_dir ) {
				public function __construct( private int $post_id, private string $css_dir ) {
				}

				public function update_file(): void {
					throw new \RuntimeException( 'generator failed' );
				}

				public function update(): void {
					throw new \RuntimeException( 'generator failed' );
				}

				public function get_path(): string {
					return $this->css_dir . '/post-' . $this->post_id . '.css';
				}

				public function get_url(): string {
					return rtrim( (string) wp_upload_dir()['baseurl'], '/' ) . '/elementor/css/post-' . $this->post_id . '.css';
				}
			};
		};

		self::assertTrue( Backup::restore( self::POST_ID, $snapshot_id ), 'the data restore stands' );

		self::assertStringContainsString( '#aa0011', (string) get_post_meta( self::POST_ID, '_elementor_data', true ) );
		$css = RestoredCss::last( self::POST_ID );
		self::assertIsArray( $css );
		self::assertSame( 'not_regenerated', $css['status'] );
		self::assertSame( 'regeneration_failed', $css['reason'] );
		self::assertSame( 'stonewright/elementor-css-regenerate', $css['next_step'] );
		self::assertSame( 'color:#00bb22', $this->read_css( 'post-' . self::POST_ID . '.css' ), 'the transaction put the previous file back' );
	}

	public function test_a_post_another_writer_holds_is_not_regenerated_and_its_lease_is_left_alone(): void {
		$snapshot_id = $this->seed_post_and_snapshot( '#aa0011' );
		$this->put_document( '#00bb22' );
		$this->configure_generator();
		$lease = PostWriteLock::acquire( self::POST_ID, 'other-writer', 60 );
		self::assertIsArray( $lease );

		self::assertTrue( Backup::restore( self::POST_ID, $snapshot_id ) );

		self::assertSame( [], $this->generated );
		$css = RestoredCss::last( self::POST_ID );
		self::assertSame( 'not_regenerated', $css['status'] ?? null );
		self::assertSame( 'post_busy', $css['reason'] ?? null );
		self::assertTrue( PostWriteLock::owned_by( self::POST_ID, 'other-writer' ) );
		PostWriteLock::release( self::POST_ID, 'other-writer' );
	}

	public function test_the_lease_of_the_regeneration_is_released_when_it_is_done(): void {
		$snapshot_id = $this->seed_post_and_snapshot( '#aa0011' );
		$this->put_document( '#00bb22' );
		$this->configure_generator();

		Backup::restore( self::POST_ID, $snapshot_id );

		$again = PostWriteLock::acquire( self::POST_ID, 'next-writer', 30 );
		self::assertIsArray( $again, 'the post is free for the next writer' );
		PostWriteLock::release( self::POST_ID, 'next-writer' );
	}

	public function test_restored_data_with_css_control_characters_is_never_handed_to_the_generator(): void {
		$snapshot_id = $this->seed_post_and_snapshot( 'red;} body{display:none' );
		$this->put_document( '#00bb22' );
		$this->configure_generator();

		self::assertTrue( Backup::restore( self::POST_ID, $snapshot_id ) );

		self::assertSame( [], $this->generated );
		$css = RestoredCss::last( self::POST_ID );
		self::assertSame( 'not_regenerated', $css['status'] ?? null );
		self::assertSame( 'unsafe_value', $css['reason'] ?? null );
		self::assertSame( 'stonewright/elementor-css-regenerate', $css['next_step'] ?? null );
	}

	public function test_a_missing_elementor_runtime_is_reported_as_not_regenerated(): void {
		$snapshot_id = $this->seed_post_and_snapshot( '#aa0011' );
		$this->put_document( '#00bb22' );
		$state       = RestoredCss::state( self::POST_ID );
		Backup::restore( self::POST_ID, $snapshot_id );
		RestoredCss::reset();

		$result = RestoredCss::refresh( self::POST_ID, $state, new CssTargetResolver( null, static fn( string $class ): bool => false ) );

		self::assertSame( 'not_regenerated', $result['status'] );
		self::assertSame( 'elementor_unavailable', $result['reason'] );
	}

	public function test_the_result_of_one_restore_does_not_leak_into_the_next(): void {
		$snapshot_id = $this->seed_post_and_snapshot( '#aa0011' );
		$this->put_document( '#00bb22' );
		$this->configure_generator();
		Backup::restore( self::POST_ID, $snapshot_id );
		self::assertNotNull( RestoredCss::last( self::POST_ID ) );

		Backup::restore( self::POST_ID, $snapshot_id );

		self::assertNull( RestoredCss::last( self::POST_ID ), 'the second restore changed nothing and regenerated nothing' );
	}

	private function seed_post_and_snapshot( string $color ): string {
		$GLOBALS['stonewright_test_posts'][ self::POST_ID ] = $this->post( [
			'_elementor_data'      => $this->document( $color ),
			'_elementor_edit_mode' => 'builder',
		] );
		$snapshot_id = Backup::snapshot_post( self::POST_ID );
		self::assertNotSame( '', $snapshot_id );
		return $snapshot_id;
	}

	private function put_document( string $color ): void {
		$GLOBALS['stonewright_test_posts'][ self::POST_ID ]->meta['_elementor_data'] = $this->document( $color );
	}

	private function document( string $color ): string {
		return (string) wp_json_encode(
			[ [
				'id'       => 'c0000001',
				'elType'   => 'container',
				'settings' => [],
				'elements' => [ [ 'id' => 'h0000001', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => [ 'title' => 'Hello', 'title_color' => $color ], 'elements' => [] ] ],
			] ]
		);
	}

	/** @param array<string,mixed> $meta */
	private function post( array $meta ): object {
		return (object) [
			'ID'           => self::POST_ID,
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_title'   => 'Restore css fixture',
			'post_content' => '',
			'post_excerpt' => '',
			'post_parent'  => 0,
			'post_name'    => 'restore-css-fixture',
			'meta'         => $meta,
		];
	}

	/** An Elementor CSS object that writes the colour of the document it reads into the post file. */
	private function configure_generator(): void {
		Post::$factory = function ( int $id ): object {
			$this->generated[] = $id;
			return new class( $id, $this->css_dir ) {
				public function __construct( private int $post_id, private string $css_dir ) {
				}

				public function update_file(): void {
					$data = (string) get_post_meta( $this->post_id, '_elementor_data', true );
					preg_match( '/"title_color":"([^"]+)"/', $data, $m );
					file_put_contents( $this->get_path(), 'color:' . ( $m[1] ?? '' ) );
				}

				public function update(): void {
					$this->update_file();
					$meta           = (array) get_post_meta( $this->post_id, '_elementor_css', true );
					$meta['time']   = 1700000000 + (int) ( $GLOBALS['stonewright_test_css_new_time'] ?? 0 );
					$meta['status'] = 'file';
					update_post_meta( $this->post_id, '_elementor_css', $meta );
				}

				/** @return mixed */
				public function get_meta( ?string $property = null ) {
					$meta = (array) get_post_meta( $this->post_id, '_elementor_css', true );
					return null === $property ? $meta : ( $meta[ $property ] ?? null );
				}

				public function get_path(): string {
					return $this->css_dir . '/post-' . $this->post_id . '.css';
				}

				public function get_url(): string {
					return rtrim( (string) wp_upload_dir()['baseurl'], '/' ) . '/elementor/css/post-' . $this->post_id . '.css';
				}
			};
		};
		$GLOBALS['stonewright_test_posts'][ self::POST_ID ]->meta['_elementor_css'] = [ 'time' => 1, 'status' => 'file' ];
	}

	private function write_css( string $name, string $bytes ): void {
		file_put_contents( $this->css_dir . '/' . $name, $bytes );
	}

	private function read_css( string $name ): string {
		return (string) file_get_contents( $this->css_dir . '/' . $name );
	}

	private function remove_css_assets(): void {
		foreach ( [ 'post-' . self::POST_ID . '.css', 'post-999.css' ] as $name ) {
			$path = $this->css_dir . '/' . $name;
			if ( is_file( $path ) || is_link( $path ) ) {
				unlink( $path );
			}
		}
	}
}
