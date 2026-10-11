<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Elementor\V4;

use Elementor\Modules\GlobalClasses\Global_Classes_Repository;
use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Elementor\V4\GlobalClassesState;

/**
 * The global classes are captured and restored through Elementor's repository, which on 4.3 keeps them in
 * e_global_class posts and not in the kit meta.
 *
 * @covers \Stonewright\WpMcp\Elementor\V4\GlobalClassesState
 */
final class GlobalClassesStateTest extends TestCase {

	private const NEW_ITEM = [ 'id' => 'cls_new', 'label' => 'New', 'type' => 'class', 'variants' => [] ];

	protected function setUp(): void {
		Global_Classes_Repository::reset();
		GlobalClassesState::use_factory_for_tests( null );
	}

	protected function tearDown(): void {
		Global_Classes_Repository::reset();
		GlobalClassesState::use_factory_for_tests( null );
	}

	public function test_capture_holds_items_order_and_labels_of_the_frontend_and_the_preview(): void {
		$state = GlobalClassesState::capture();

		self::assertIsArray( $state );
		foreach ( [ 'frontend', 'preview' ] as $context ) {
			self::assertSame( [ 'cls_contract' ], array_keys( $state['contexts'][ $context ]['items'] ) );
			self::assertSame( [ 'cls_contract' ], $state['contexts'][ $context ]['order'] );
			self::assertSame( [ 'cls_contract' => 'Contract' ], $state['contexts'][ $context ]['labels'] );
		}
	}

	public function test_restore_brings_back_a_changed_added_and_reordered_class_list_in_both_contexts(): void {
		$before = GlobalClassesState::capture();
		foreach ( [ 'frontend', 'preview' ] as $context ) {
			$repository = 'preview' === $context ? Global_Classes_Repository::make()->set_preview( true ) : Global_Classes_Repository::make();
			$repository->put( [ 'cls_contract' => [ 'id' => 'cls_contract', 'label' => 'Changed', 'type' => 'class', 'variants' => [] ], 'cls_new' => self::NEW_ITEM ], [ 'cls_new', 'cls_contract' ] );
		}
		self::assertFalse( GlobalClassesState::same( $before, (array) GlobalClassesState::capture() ) );

		self::assertTrue( GlobalClassesState::restore( $before ) );

		self::assertTrue( GlobalClassesState::same( $before, (array) GlobalClassesState::capture() ) );
		self::assertNull( Global_Classes_Repository::make()->get( 'cls_new' ) );
	}

	public function test_restore_removes_a_class_that_the_snapshot_did_not_have(): void {
		$before = GlobalClassesState::capture();
		Global_Classes_Repository::make()->apply_changes( [ 'cls_new' => self::NEW_ITEM ], [ 'added' => [ 'cls_new' ], 'deleted' => [], 'modified' => [], 'order' => true ], [ 'cls_contract', 'cls_new' ] );

		self::assertTrue( GlobalClassesState::restore( (array) $before ) );

		self::assertSame( [ 'cls_contract' ], Global_Classes_Repository::make()->get_order() );
	}

	public function test_the_preview_is_read_from_a_fresh_instance_with_set_preview(): void {
		Global_Classes_Repository::make()->set_preview( true )->put( [], [] );

		$state = (array) GlobalClassesState::capture();

		self::assertSame( [], $state['contexts']['preview']['items'] );
		self::assertSame( [ 'cls_contract' ], array_keys( $state['contexts']['frontend']['items'] ), 'the preview flag does not leak into the frontend read' );
	}

	public function test_a_cached_answer_never_hides_what_a_put_wrote(): void {
		// One shared instance that caches all() the way Elementor's repository does.
		$repository = Global_Classes_Repository::make();
		GlobalClassesState::use_factory_for_tests( static fn( string $context ): ?object => 'frontend' === $context ? $repository : null );
		$before = (array) GlobalClassesState::capture();
		$repository->all();
		$repository->put( [], [] );

		$after = (array) GlobalClassesState::capture();

		self::assertSame( [], $after['contexts']['frontend']['items'], 'the read is forced' );
		self::assertTrue( GlobalClassesState::restore( $before ) );
		self::assertSame( [ 'cls_contract' ], array_keys( (array) GlobalClassesState::capture()['contexts']['frontend']['items'] ) );
	}

	public function test_nothing_is_captured_when_the_repository_is_not_there(): void {
		GlobalClassesState::use_factory_for_tests( static fn(): ?object => null );

		self::assertNull( GlobalClassesState::capture() );
		self::assertFalse( GlobalClassesState::restore( [ 'v' => 1, 'contexts' => [] ] ), 'a state cannot be restored without the repository' );
	}

	public function test_a_repository_that_does_not_keep_what_was_put_is_reported_as_not_restored(): void {
		$before = (array) GlobalClassesState::capture();
		GlobalClassesState::use_factory_for_tests(
			static fn(): object => new class() {
				public function all(): object {
					return new class() {
						public function get_items(): array { return []; }
						public function get_order(): array { return []; }
					};
				}
				public function all_labels(): array { return []; }
				public function put( array $items, array $order ): void {}
			}
		);

		self::assertFalse( GlobalClassesState::restore( $before ), 'the readback differs from the state, so the restore is not confirmed' );
	}
}
