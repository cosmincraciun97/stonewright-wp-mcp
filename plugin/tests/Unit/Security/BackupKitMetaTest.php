<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use Elementor\Modules\GlobalClasses\Global_Classes_Repository;
use Stonewright\WpMcp\Elementor\V4\GlobalClassesState;
use Stonewright\WpMcp\Security\Backup;

/**
 * A snapshot of the active kit can cover the Elementor V4 global variables (kit meta) and the global classes
 * (through Elementor's repository, with the legacy kit meta key as the fallback for older Elementor).
 *
 * @covers \Stonewright\WpMcp\Security\Backup
 */
final class BackupKitMetaTest extends TestCase {

	private const KIT = 4;

	protected function setUp(): void {
		Global_Classes_Repository::reset();
		GlobalClassesState::use_factory_for_tests( null );
		$GLOBALS['stonewright_test_post_meta_calls'] = [];
		unset( $GLOBALS['stonewright_test_update_post_meta_returns'] );
		$GLOBALS['stonewright_test_posts'] = [
			self::KIT => (object) [
				'ID'           => self::KIT,
				'post_type'    => 'elementor_library',
				'post_status'  => 'publish',
				'post_title'   => 'Default Kit',
				'post_content' => '',
				'post_excerpt' => '',
				'post_parent'  => 0,
				'post_name'    => 'default-kit',
				'meta'         => [
					'_elementor_page_settings'  => [ 'custom_colors' => [] ],
					'_elementor_global_classes' => [ 'items' => [ 'cls_a' => [ 'id' => 'cls_a', 'label' => 'A' ] ], 'order' => [ 'cls_a' ] ],
				],
			],
		];
	}

	protected function tearDown(): void {
		GlobalClassesState::use_factory_for_tests( null );
		Global_Classes_Repository::reset();
		$GLOBALS['stonewright_test_posts']           = [];
		$GLOBALS['stonewright_test_post_meta_calls'] = [];
	}

	private function set_meta( string $key, mixed $value ): void {
		$post       = $GLOBALS['stonewright_test_posts'][ self::KIT ];
		$meta       = (array) $post->meta;
		$meta[ $key ] = $value;
		$post->meta = $meta;
	}

	public function test_the_kit_key_list_names_the_variable_store_and_only_the_legacy_class_key(): void {
		self::assertContains( '_elementor_global_variables', Backup::KIT_V4_META_KEYS );
		self::assertContains( '_elementor_global_classes', Backup::KIT_V4_META_KEYS, 'the legacy key stays for Elementor versions without the repository' );
		self::assertNotContains( '_elementor_global_classes_post_ids', Backup::KIT_V4_META_KEYS, 'the post-based storage of 4.3 is covered through the repository, not by listing its keys' );
		self::assertStringContainsString( 'repository', (string) ( new \ReflectionClassConstant( Backup::class, 'KIT_V4_META_KEYS' ) )->getDocComment() );
	}

	public function test_a_snapshot_with_the_class_state_restores_a_class_change_the_kit_meta_never_shows(): void {
		$before = GlobalClassesState::capture();
		$id     = Backup::snapshot_post( self::KIT, [], true, [], true );
		self::assertNotSame( '', $id );
		self::assertArrayHasKey( 'global_classes', Backup::get_snapshot( self::KIT, $id ) );

		Global_Classes_Repository::make()->put( [ 'cls_other' => [ 'id' => 'cls_other', 'label' => 'Other', 'type' => 'class', 'variants' => [] ] ], [ 'cls_other' ] );
		self::assertTrue( Backup::differs_from_snapshot( self::KIT, $id ), 'a change in the repository is a change of the snapshot' );

		self::assertTrue( Backup::restore( self::KIT, $id ) );
		self::assertTrue( GlobalClassesState::same( (array) $before, (array) GlobalClassesState::capture() ) );
		self::assertFalse( Backup::differs_from_snapshot( self::KIT, $id ) );
	}

	public function test_a_restore_that_the_repository_does_not_confirm_fails(): void {
		$id = Backup::snapshot_post( self::KIT, [], true, [], true );
		self::assertNotSame( '', $id );
		GlobalClassesState::use_factory_for_tests( static fn(): ?object => null );

		self::assertFalse( Backup::restore( self::KIT, $id ), 'a snapshot that holds class state is not restored without the repository' );
	}

	public function test_without_the_repository_the_snapshot_falls_back_to_the_legacy_kit_meta(): void {
		GlobalClassesState::use_factory_for_tests( static fn(): ?object => null );
		$before = get_post_meta( self::KIT, '_elementor_global_classes', true );
		$id     = Backup::snapshot_post( self::KIT, [], true, Backup::KIT_V4_META_KEYS, true );
		self::assertNotSame( '', $id );
		self::assertArrayNotHasKey( 'global_classes', Backup::get_snapshot( self::KIT, $id ) );

		$this->set_meta( '_elementor_global_classes', [ 'items' => [], 'order' => [] ] );
		self::assertTrue( Backup::restore( self::KIT, $id ) );
		self::assertSame( $before, get_post_meta( self::KIT, '_elementor_global_classes', true ) );
	}

	public function test_a_restore_brings_back_a_changed_class_list(): void {
		$before = get_post_meta( self::KIT, '_elementor_global_classes', true );
		$id     = Backup::snapshot_post( self::KIT, [], true, Backup::KIT_V4_META_KEYS );
		self::assertNotSame( '', $id );

		$this->set_meta( '_elementor_global_classes', [ 'items' => [ 'cls_a' => [ 'id' => 'cls_a', 'label' => 'Changed' ], 'cls_b' => [ 'id' => 'cls_b', 'label' => 'B' ] ], 'order' => [ 'cls_a', 'cls_b' ] ] );
		self::assertTrue( Backup::differs_from_snapshot( self::KIT, $id ), 'a class change must show up as a change of the snapshot' );

		self::assertTrue( Backup::restore( self::KIT, $id ) );
		self::assertSame( $before, get_post_meta( self::KIT, '_elementor_global_classes', true ) );
		self::assertFalse( Backup::differs_from_snapshot( self::KIT, $id ) );
	}

	public function test_a_restore_removes_a_variable_store_that_did_not_exist_before_the_write(): void {
		$id = Backup::snapshot_post( self::KIT, [], true, Backup::KIT_V4_META_KEYS );
		self::assertNotSame( '', $id );

		$this->set_meta( '_elementor_global_variables', [ 'data' => [ 'var_1' => [ 'label' => 'Brand', 'type' => 'global-color-variable', 'value' => '#fff' ] ], 'version' => 1 ] );

		self::assertTrue( Backup::restore( self::KIT, $id ) );
		self::assertArrayNotHasKey( '_elementor_global_variables', (array) $GLOBALS['stonewright_test_posts'][ self::KIT ]->meta );
	}

	public function test_a_snapshot_without_the_key_list_leaves_the_kit_class_meta_alone(): void {
		$id = Backup::snapshot_post( self::KIT );
		self::assertNotSame( '', $id );
		self::assertArrayNotHasKey( '_elementor_global_classes', Backup::get_snapshot( self::KIT, $id )['meta'] );

		$this->set_meta( '_elementor_global_classes', [ 'items' => [], 'order' => [] ] );
		self::assertTrue( Backup::restore( self::KIT, $id ) );
		self::assertSame( [ 'items' => [], 'order' => [] ], get_post_meta( self::KIT, '_elementor_global_classes', true ), 'a plain snapshot never owned this key' );
	}

	public function test_a_key_outside_the_kit_list_is_not_captured_or_restored(): void {
		$this->set_meta( '_not_ours', 'before' );
		$id = Backup::snapshot_post( self::KIT, [], true, [ '_not_ours', '_elementor_global_classes' ] );
		self::assertNotSame( '', $id );
		$snapshot = Backup::get_snapshot( self::KIT, $id );
		self::assertArrayNotHasKey( '_not_ours', $snapshot['meta'] );
		self::assertArrayHasKey( '_elementor_global_classes', $snapshot['meta'] );

		$this->set_meta( '_not_ours', 'after' );
		self::assertTrue( Backup::restore( self::KIT, $id ) );
		self::assertSame( 'after', get_post_meta( self::KIT, '_not_ours', true ) );
	}
}
