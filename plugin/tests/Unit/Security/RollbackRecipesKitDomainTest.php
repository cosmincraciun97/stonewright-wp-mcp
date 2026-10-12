<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Security;

use Elementor\Modules\GlobalClasses\Global_Classes_Repository;
use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Elementor\V4\DefaultStylesState;
use Stonewright\WpMcp\Elementor\V4\GlobalClassesState;
use Stonewright\WpMcp\Security\Backup;
use Stonewright\WpMcp\Security\ChangeJournal;
use Stonewright\WpMcp\Security\HealthProbe;
use Stonewright\WpMcp\Security\RescueRollback;
use Stonewright\WpMcp\Security\RollbackRecipes;

/**
 * The state saved before an undo covers everything the undo can overwrite, so that putting it back restores the
 * same surfaces: the kit variables, the legacy class list, the classes and default styles of Elementor's
 * repositories, and the keys that did not exist when the snapshot was taken.
 *
 * @covers \Stonewright\WpMcp\Security\RollbackRecipes
 * @covers \Stonewright\WpMcp\Security\Backup
 * @covers \Stonewright\WpMcp\Security\RescueRollback
 */
final class RollbackRecipesKitDomainTest extends TestCase {

	private const KIT = 4;

	private const VARIABLES = '_elementor_global_variables';

	private const LEGACY_CLASSES = '_elementor_global_classes';

	/** The default styles repository the tests install; its items are the styles. */
	private ?object $repo = null;

	private bool $site_broken = false;

	protected function setUp(): void {
		Global_Classes_Repository::reset();
		GlobalClassesState::use_factory_for_tests( null );
		DefaultStylesState::use_factory_for_tests( null );
		$this->repo                                       = null;
		$this->site_broken                                = false;
		$GLOBALS['stonewright_test_options']              = [ 'stonewright_mode' => 'development' ];
		$GLOBALS['stonewright_test_transients']           = [];
		$GLOBALS['stonewright_test_filters']              = [];
		$GLOBALS['stonewright_test_wpdb_inserts']         = [];
		$GLOBALS['stonewright_test_user_caps']            = [ 'manage_options' => true ];
		$GLOBALS['stonewright_test_current_user_id']      = 7;
		$GLOBALS['stonewright_test_user_caps_by_id']      = [ 7 => [ 'manage_options' => true, 'read' => true, 'edit_post' => true ] ];
		$GLOBALS['stonewright_test_upload_dir']           = [ 'basedir' => sys_get_temp_dir() . '/sw-kit-domain', 'baseurl' => 'https://example.test/uploads', 'error' => false ];
		$GLOBALS['stonewright_test_posts']                = [
			self::KIT => (object) [
				'ID'           => self::KIT,
				'post_type'    => 'elementor_library',
				'post_status'  => 'publish',
				'post_title'   => 'Default Kit',
				'post_content' => '',
				'post_excerpt' => '',
				'post_parent'  => 0,
				'post_name'    => 'default-kit',
				'meta'         => [ '_elementor_page_settings' => [ 'custom_colors' => [] ] ],
			],
		];
		ChangeJournal::reset_for_tests();
		HealthProbe::set_transport(
			function ( string $url ) {
				if ( $this->site_broken ) {
					return [ 'response' => [ 'code' => 500 ], 'body' => '<body id="error-page"></body>', 'headers' => [] ];
				}
				return [ 'response' => [ 'code' => 200 ], 'body' => str_contains( $url, 'wp-json' ) ? '{"namespaces":[]}' : ( str_contains( $url, 'stonewright-rescue' ) ? 'data-sw-rescue-probe="ok"' : 'ok' ), 'headers' => [] ];
			}
		);
	}

	protected function tearDown(): void {
		HealthProbe::set_transport( null );
		RescueRollback::set_selection_runner( null );
		GlobalClassesState::use_factory_for_tests( null );
		DefaultStylesState::use_factory_for_tests( null );
		Global_Classes_Repository::reset();
		ChangeJournal::reset_for_tests();
		$GLOBALS['stonewright_test_posts']        = [];
		$GLOBALS['stonewright_test_options']      = [];
		$GLOBALS['stonewright_test_user_caps']    = [];
		$GLOBALS['stonewright_test_transients']   = [];
		unset( $GLOBALS['stonewright_test_upload_dir'] );
	}

	/** A class change in both contexts of the repository. */
	private function put_class( string $id ): void {
		$item = [ $id => [ 'id' => $id, 'label' => $id, 'type' => 'class', 'variants' => [] ] ];
		Global_Classes_Repository::make()->put( $item, [ $id ] );
		Global_Classes_Repository::make()->set_preview( true )->put( $item, [ $id ] );
	}

	private function set_meta( string $key, mixed $value ): void {
		$post         = $GLOBALS['stonewright_test_posts'][ self::KIT ];
		$meta         = (array) $post->meta;
		$meta[ $key ] = $value;
		$post->meta   = $meta;
	}

	private function drop_meta( string $key ): void {
		$post       = $GLOBALS['stonewright_test_posts'][ self::KIT ];
		$meta       = (array) $post->meta;
		unset( $meta[ $key ] );
		$post->meta = $meta;
	}

	private function meta( string $key ): mixed {
		$meta = (array) $GLOBALS['stonewright_test_posts'][ self::KIT ]->meta;
		return array_key_exists( $key, $meta ) ? $meta[ $key ] : '(absent)';
	}

	/**
	 * Install a default styles repository that holds these tags.
	 *
	 * @param array<string, mixed> $items
	 */
	private function use_styles_repository( array $items = [ 'h1' => [ 'type' => 'class', 'variants' => [ 'old' ] ] ] ): void {
		$this->repo = new class( $items ) {
			/** @param array<string, mixed> $items */
			public function __construct( public array $items ) {}

			/** @return array<string, mixed> */
			public function all( bool $force = false ): array {
				return $this->items;
			}

			/** @param array<string, mixed> $data */
			public function put( string $tag, array $data ): bool {
				$this->items[ $tag ] = array_merge( [ 'id' => $tag ], $data );
				return true;
			}

			public function delete( string $tag ): void {
				unset( $this->items[ $tag ] );
			}
		};
		$repo = $this->repo;
		DefaultStylesState::use_factory_for_tests( static fn (): object => $repo );
	}

	/** @return list<string> */
	private function style_tags(): array {
		return array_map( 'strval', array_keys( (array) $this->repo->items ) );
	}

	/** The restore point a kit write takes: the way a variable, class or default style write saves its source. */
	private function source_snapshot( array $keys = [ self::VARIABLES ], bool $classes = false, bool $styles = false ): string {
		$id = Backup::snapshot_post( self::KIT, [], false, $keys, $classes, $styles );
		self::assertNotSame( '', $id );
		return $id;
	}

	/** @return array<string, mixed> A journal-shaped entry whose recipe restores a kit snapshot. */
	private function entry( string $snapshot_id ): array {
		return [
			'id'            => 'cs-' . str_repeat( 'a', 24 ),
			'ability'       => 'stonewright/elementor-v4-update-variable',
			'resource_type' => 'post',
			'resource_key'  => (string) self::KIT,
			'recipe'        => [ 'type' => 'post_snapshot', 'ref' => $snapshot_id ],
			'recipe_detail' => [ 'post_id' => self::KIT, 'snapshot_id' => $snapshot_id ],
		];
	}

	// -- The report's case: the variables ------------------------------------------------------------

	public function test_the_saved_state_of_a_kit_undo_puts_the_variables_back(): void {
		$this->set_meta( self::VARIABLES, 'OLD' );
		$source = $this->source_snapshot();
		$this->set_meta( self::VARIABLES, 'NEW' );
		$entry = $this->entry( $source );

		$captured = RollbackRecipes::capture( $entry );
		self::assertSame( 'ok', $captured['status'], $captured['detail'] );
		self::assertSame( 'succeeded', RollbackRecipes::run( $entry )['status'] );
		self::assertSame( 'OLD', $this->meta( self::VARIABLES ) );

		$put_back = RollbackRecipes::run( $captured['restore'] );

		self::assertSame( 'succeeded', $put_back['status'] );
		self::assertSame( 'NEW', $this->meta( self::VARIABLES ), 'The put-back returns the variables the undo replaced.' );
	}

	public function test_an_undo_that_breaks_the_site_is_put_back_with_the_variables_of_before_the_undo(): void {
		$this->set_meta( self::VARIABLES, 'OLD' );
		$source = $this->source_snapshot();
		$this->set_meta( self::VARIABLES, 'NEW' );
		$entry = ChangeJournal::arm( $this->entry( $source ) + [ 'scope' => 'post' ] );
		self::assertIsArray( $entry );
		ChangeJournal::settle( $entry['id'], 'verified' );
		$this->site_broken_when( fn (): bool => 'OLD' === $this->meta( self::VARIABLES ) );

		$result = RescueRollback::run( $entry['id'], [ 'by' => 'admin-page', 'user_id' => 7, 'human_approved' => true ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_rescue_undo_reverted', $result->get_error_code() );
		self::assertSame( 'NEW', $this->meta( self::VARIABLES ), 'The variable the undo replaced is back, not left at the older value.' );
		self::assertSame( 'verified', ChangeJournal::get( $entry['id'] )['state'] );
	}

	/** @param callable():bool $broken */
	private function site_broken_when( callable $broken ): void {
		HealthProbe::set_transport(
			function ( string $url ) use ( $broken ) {
				if ( $broken() ) {
					return [ 'response' => [ 'code' => 500 ], 'body' => '<body id="error-page"></body>', 'headers' => [] ];
				}
				return [ 'response' => [ 'code' => 200 ], 'body' => str_contains( $url, 'wp-json' ) ? '{"namespaces":[]}' : ( str_contains( $url, 'stonewright-rescue' ) ? 'data-sw-rescue-probe="ok"' : 'ok' ), 'headers' => [] ];
			}
		);
	}

	// -- The rest of the domain ----------------------------------------------------------------------

	public function test_the_legacy_class_key_is_covered(): void {
		$this->set_meta( self::LEGACY_CLASSES, [ 'items' => [ 'a' => [ 'id' => 'a' ] ], 'order' => [ 'a' ] ] );
		$source = $this->source_snapshot( [ self::LEGACY_CLASSES ] );
		$this->set_meta( self::LEGACY_CLASSES, [ 'items' => [ 'b' => [ 'id' => 'b' ] ], 'order' => [ 'b' ] ] );
		$entry    = $this->entry( $source );
		$captured = RollbackRecipes::capture( $entry );
		self::assertSame( 'ok', $captured['status'], $captured['detail'] );

		self::assertSame( 'succeeded', RollbackRecipes::run( $entry )['status'] );
		self::assertSame( 'a', array_key_first( $this->meta( self::LEGACY_CLASSES )['items'] ) );
		self::assertSame( 'succeeded', RollbackRecipes::run( $captured['restore'] )['status'] );

		self::assertSame( 'b', array_key_first( $this->meta( self::LEGACY_CLASSES )['items'] ) );
	}

	public function test_the_classes_of_the_repository_are_covered(): void {
		$source = $this->source_snapshot( [], true );
		$this->put_class( 'cls_new' );
		$entry    = $this->entry( $source );
		$captured = RollbackRecipes::capture( $entry );
		self::assertSame( 'ok', $captured['status'], $captured['detail'] );

		self::assertSame( 'succeeded', RollbackRecipes::run( $entry )['status'] );
		self::assertSame( [ 'cls_contract' ], array_keys( (array) GlobalClassesState::capture()['contexts']['frontend']['items'] ) );
		self::assertSame( 'succeeded', RollbackRecipes::run( $captured['restore'] )['status'] );

		$live = GlobalClassesState::capture();
		self::assertSame( [ 'cls_new' ], array_keys( (array) $live['contexts']['frontend']['items'] ), 'The class the undo removed is back.' );
		self::assertSame( [ 'cls_new' ], array_keys( (array) $live['contexts']['preview']['items'] ), 'In the preview context too.' );
	}

	public function test_the_default_styles_are_covered(): void {
		$this->use_styles_repository();
		$source = $this->source_snapshot( [], false, true );
		$this->repo->items = [ 'h1' => [ 'type' => 'class', 'variants' => [ 'new' ] ], 'h3' => [ 'type' => 'class', 'variants' => [] ] ];
		$entry    = $this->entry( $source );
		$captured = RollbackRecipes::capture( $entry );
		self::assertSame( 'ok', $captured['status'], $captured['detail'] );

		self::assertSame( 'succeeded', RollbackRecipes::run( $entry )['status'] );
		self::assertSame( [ 'h1' ], $this->style_tags() );
		self::assertSame( 'succeeded', RollbackRecipes::run( $captured['restore'] )['status'] );

		self::assertSame( [ 'h1', 'h3' ], $this->style_tags() );
		self::assertSame( [ 'new' ], $this->repo->items['h1']['variants'] );
	}

	public function test_a_key_that_was_absent_when_the_snapshot_was_taken_is_put_back_with_its_value(): void {
		$source = $this->source_snapshot();
		self::assertContains( self::VARIABLES, Backup::get_snapshot( self::KIT, $source )['meta_absent'] );
		$this->set_meta( self::VARIABLES, 'CREATED' );
		$entry    = $this->entry( $source );
		$captured = RollbackRecipes::capture( $entry );
		self::assertSame( 'ok', $captured['status'], $captured['detail'] );

		self::assertSame( 'succeeded', RollbackRecipes::run( $entry )['status'] );
		self::assertSame( '(absent)', $this->meta( self::VARIABLES ), 'The undo removes the key.' );
		self::assertSame( 'succeeded', RollbackRecipes::run( $captured['restore'] )['status'] );

		self::assertSame( 'CREATED', $this->meta( self::VARIABLES ) );
	}

	public function test_a_key_that_exists_now_and_not_in_the_snapshot_is_absent_after_the_put_back_when_it_was_absent_before_the_undo(): void {
		$this->set_meta( self::VARIABLES, 'OLD' );
		$source = $this->source_snapshot();
		$this->drop_meta( self::VARIABLES );
		$entry    = $this->entry( $source );
		$captured = RollbackRecipes::capture( $entry );
		self::assertSame( 'ok', $captured['status'], $captured['detail'] );

		RollbackRecipes::run( $entry );
		self::assertSame( 'OLD', $this->meta( self::VARIABLES ) );
		RollbackRecipes::run( $captured['restore'] );

		self::assertSame( '(absent)', $this->meta( self::VARIABLES ), 'Absence is part of the saved state.' );
	}

	public function test_every_surface_of_the_source_is_covered_together(): void {
		$this->use_styles_repository();
		$this->set_meta( self::VARIABLES, 'OLD' );
		$this->set_meta( self::LEGACY_CLASSES, [ 'items' => [], 'order' => [] ] );
		$source = $this->source_snapshot( Backup::KIT_V4_META_KEYS, true, true );
		$this->set_meta( self::VARIABLES, 'NEW' );
		$this->drop_meta( self::LEGACY_CLASSES );
		$this->put_class( 'cls_new' );
		$this->repo->items = [ 'h2' => [ 'type' => 'class', 'variants' => [ 'new' ] ] ];
		$entry        = $this->entry( $source );
		$captured     = RollbackRecipes::capture( $entry );
		self::assertSame( 'ok', $captured['status'], $captured['detail'] );
		$now = [
			'variables' => $this->meta( self::VARIABLES ),
			'legacy'    => $this->meta( self::LEGACY_CLASSES ),
			'classes'   => array_keys( (array) GlobalClassesState::capture()['contexts']['frontend']['items'] ),
			'styles'    => $this->style_tags(),
		];

		self::assertSame( 'succeeded', RollbackRecipes::run( $entry )['status'] );
		self::assertSame( 'succeeded', RollbackRecipes::run( $captured['restore'] )['status'] );

		self::assertSame(
			$now,
			[
				'variables' => $this->meta( self::VARIABLES ),
				'legacy'    => $this->meta( self::LEGACY_CLASSES ),
				'classes'   => array_keys( (array) GlobalClassesState::capture()['contexts']['frontend']['items'] ),
				'styles'    => $this->style_tags(),
			]
		);
	}

	// -- An incomplete capture stops the undo before anything is written -----------------------------

	public function test_a_capture_that_cannot_cover_the_class_repository_is_refused_and_stores_nothing(): void {
		$source = $this->source_snapshot( [ self::VARIABLES ], true );
		$this->set_meta( self::VARIABLES, 'NEW' );
		GlobalClassesState::use_factory_for_tests( static fn (): ?object => null );
		$stored = count( Backup::list_snapshots( self::KIT ) );

		$captured = RollbackRecipes::capture( $this->entry( $source ) );

		self::assertSame( 'failed', $captured['status'] );
		self::assertSame( 'capture_incomplete', $captured['detail'] );
		self::assertArrayNotHasKey( 'restore', $captured );
		self::assertCount( $stored, Backup::list_snapshots( self::KIT ), 'No half snapshot is left behind.' );
	}

	public function test_a_capture_that_cannot_cover_the_default_styles_is_refused(): void {
		$this->use_styles_repository();
		$source = $this->source_snapshot( [], false, true );
		DefaultStylesState::use_factory_for_tests( static fn (): ?object => null );

		$captured = RollbackRecipes::capture( $this->entry( $source ) );

		self::assertSame( 'failed', $captured['status'] );
		self::assertSame( 'capture_incomplete', $captured['detail'] );
	}

	public function test_the_undo_is_refused_before_it_writes_when_the_kit_state_cannot_be_saved(): void {
		$this->set_meta( self::VARIABLES, 'OLD' );
		$source = $this->source_snapshot( [ self::VARIABLES ], true );
		$this->set_meta( self::VARIABLES, 'NEW' );
		$entry = ChangeJournal::arm( $this->entry( $source ) + [ 'scope' => 'post' ] );
		self::assertIsArray( $entry );
		ChangeJournal::settle( $entry['id'], 'verified' );
		GlobalClassesState::use_factory_for_tests( static fn (): ?object => null );

		$result = RescueRollback::run( $entry['id'], [ 'by' => 'admin-page', 'user_id' => 7, 'human_approved' => true ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_rescue_undo_capture_failed', $result->get_error_code() );
		self::assertSame( 'NEW', $this->meta( self::VARIABLES ), 'Nothing was written.' );
		self::assertSame( 'verified', ChangeJournal::get( $entry['id'] )['state'] );
	}

	// -- A put-back that does not reproduce the whole state is not a success --------------------------

	public function test_a_put_back_whose_class_repository_does_not_confirm_is_not_reported_as_succeeded(): void {
		$source = $this->source_snapshot( [ self::VARIABLES ], true );
		$this->set_meta( self::VARIABLES, 'NEW' );
		$entry    = $this->entry( $source );
		$captured = RollbackRecipes::capture( $entry );
		self::assertSame( 'ok', $captured['status'], $captured['detail'] );
		RollbackRecipes::run( $entry );
		GlobalClassesState::use_factory_for_tests( static fn (): ?object => null );

		$put_back = RollbackRecipes::run( $captured['restore'] );

		self::assertSame( 'failed', $put_back['status'], 'The variables came back but the classes could not be confirmed.' );
	}

	public function test_a_put_back_that_cannot_write_the_default_styles_is_not_reported_as_succeeded(): void {
		$this->use_styles_repository();
		$source       = $this->source_snapshot( [ self::VARIABLES ], false, true );
		$this->repo->items = [ 'h1' => [ 'type' => 'class', 'variants' => [ 'new' ] ] ];
		$this->set_meta( self::VARIABLES, 'NEW' );
		$entry    = $this->entry( $source );
		$captured = RollbackRecipes::capture( $entry );
		self::assertSame( 'ok', $captured['status'], $captured['detail'] );
		RollbackRecipes::run( $entry );
		DefaultStylesState::use_factory_for_tests( static fn (): ?object => null );

		self::assertSame( 'failed', RollbackRecipes::run( $captured['restore'] )['status'] );
	}

	// -- Snapshots stored before this change keep working --------------------------------------------

	public function test_a_snapshot_without_kit_surfaces_is_captured_as_before(): void {
		$source = Backup::snapshot_post( self::KIT, [], false );
		self::assertNotSame( '', $source );
		$captured = RollbackRecipes::capture( $this->entry( $source ) );

		self::assertSame( 'ok', $captured['status'], $captured['detail'] );
		$saved = Backup::get_snapshot( self::KIT, (string) $captured['restore']['recipe']['ref'] );
		self::assertArrayNotHasKey( 'global_classes', $saved );
		self::assertArrayNotHasKey( 'default_styles', $saved );
		self::assertNotContains( self::VARIABLES, array_keys( (array) $saved['meta'] ) );
		self::assertNotContains( self::VARIABLES, (array) ( $saved['meta_absent'] ?? [] ), 'The saved state covers what the source covers, not more.' );
	}

	public function test_a_snapshot_stored_without_the_absent_list_is_captured_without_inventing_one(): void {
		$this->set_meta( self::VARIABLES, 'OLD' );
		$source = $this->source_snapshot();
		// A snapshot from before meta_absent existed.
		$stored = get_post_meta( self::KIT, '_stonewright_backups', true );
		unset( $stored[ $source ]['meta_absent'] );
		update_post_meta( self::KIT, '_stonewright_backups', $stored );
		$this->set_meta( self::VARIABLES, 'NEW' );
		$entry = $this->entry( $source );

		$captured = RollbackRecipes::capture( $entry );

		self::assertSame( 'ok', $captured['status'], $captured['detail'] );
		RollbackRecipes::run( $entry );
		RollbackRecipes::run( $captured['restore'] );
		self::assertSame( 'NEW', $this->meta( self::VARIABLES ) );
	}

	/** A snapshot that is gone writes nothing when the recipe runs, so the capture saves the post as it always did. */
	public function test_a_snapshot_that_is_gone_is_captured_without_a_domain_and_the_recipe_then_fails_without_writing(): void {
		$this->set_meta( self::VARIABLES, 'NEW' );
		$entry    = $this->entry( 'snap_0000000000000000' );
		$captured = RollbackRecipes::capture( $entry );

		self::assertSame( 'ok', $captured['status'], $captured['detail'] );
		self::assertSame( 'failed', RollbackRecipes::run( $entry )['status'] );
		self::assertSame( 'NEW', $this->meta( self::VARIABLES ), 'Nothing was written.' );
	}

	public function test_the_snapshot_the_recipe_needs_is_kept_by_the_capture(): void {
		$this->set_meta( self::VARIABLES, 'OLD' );
		$source = $this->source_snapshot();
		for ( $i = 0; $i < 9; $i++ ) {
			Backup::snapshot_post( self::KIT, [], false );
		}
		self::assertCount( 10, Backup::list_snapshots( self::KIT ) );

		$captured = RollbackRecipes::capture( $this->entry( $source ) );

		self::assertSame( 'ok', $captured['status'], $captured['detail'] );
		self::assertNotNull( Backup::get_snapshot( self::KIT, $source ), 'The snapshot to restore outlives the one that is saved now.' );
	}
}
