<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Elementor\V4;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Elementor\V4\DefaultStylesState;

/**
 * The default styles are captured and restored through Elementor's repository, which on 4.3 keeps each style in an
 * e_default_style post and the kit meta only holds the tag to post id map.
 *
 * @covers \Stonewright\WpMcp\Elementor\V4\DefaultStylesState
 */
final class DefaultStylesStateTest extends TestCase {

	protected function setUp(): void {
		DefaultStylesState::use_factory_for_tests( null );
	}

	protected function tearDown(): void {
		DefaultStylesState::use_factory_for_tests( null );
	}

	public function test_nothing_is_captured_when_elementor_has_no_repository(): void {
		self::assertNull( DefaultStylesState::capture() );
		self::assertFalse( DefaultStylesState::restore( [ 'v' => 1, 'items' => [] ] ), 'a state cannot be restored without the repository' );
	}

	public function test_capture_holds_the_type_and_variants_of_every_tag_and_drops_what_elementor_derives(): void {
		$repository = new DefaultStylesRepositoryFixture( [ 'h1' => self::style( 'h1', '#111' ), 'p' => self::style( 'p', '#222' ) ] );
		DefaultStylesState::use_factory_for_tests( static fn(): object => $repository );

		$state = DefaultStylesState::capture();

		self::assertIsArray( $state );
		self::assertSame( [ 'h1', 'p' ], array_keys( $state['items'] ) );
		self::assertSame( [ 'type', 'variants' ], array_keys( $state['items']['h1'] ) );
		self::assertSame( 'class', $state['items']['h1']['type'] );
	}

	public function test_restore_brings_back_a_changed_added_and_deleted_tag(): void {
		$repository = new DefaultStylesRepositoryFixture( [ 'h1' => self::style( 'h1', '#111' ), 'p' => self::style( 'p', '#222' ) ] );
		DefaultStylesState::use_factory_for_tests( static fn(): object => $repository );
		$before = (array) DefaultStylesState::capture();

		$repository->put( 'h1', [ 'type' => 'class', 'variants' => self::style( 'h1', '#999' )['variants'] ] );
		$repository->delete( 'p' );
		$repository->put( 'h2', [ 'type' => 'class', 'variants' => [] ] );
		self::assertFalse( DefaultStylesState::same( $before, (array) DefaultStylesState::capture() ) );

		self::assertTrue( DefaultStylesState::restore( $before ) );

		self::assertTrue( DefaultStylesState::same( $before, (array) DefaultStylesState::capture() ) );
		self::assertSame( [ 'h1', 'p' ], array_keys( $repository->all( true ) ), 'the tag the snapshot did not have is gone, the deleted one is back' );
	}

	public function test_a_cached_answer_never_hides_what_a_put_wrote(): void {
		$repository = new DefaultStylesRepositoryFixture( [ 'h1' => self::style( 'h1', '#111' ) ] );
		DefaultStylesState::use_factory_for_tests( static fn(): object => $repository );
		$before = (array) DefaultStylesState::capture();
		$repository->all();
		$repository->put( 'h1', [ 'type' => 'class', 'variants' => [] ] );

		$after = (array) DefaultStylesState::capture();

		self::assertSame( [], $after['items']['h1']['variants'], 'the read is forced' );
		self::assertTrue( DefaultStylesState::restore( $before ) );
	}

	public function test_a_repository_that_does_not_keep_what_was_put_is_reported_as_not_restored(): void {
		$before = [ 'v' => 1, 'items' => [ 'h1' => [ 'type' => 'class', 'variants' => [ 'x' ] ] ] ];
		DefaultStylesState::use_factory_for_tests( static fn(): object => new DefaultStylesRepositoryFixture( [], false ) );

		self::assertFalse( DefaultStylesState::restore( $before ), 'the readback differs from the state, so the restore is not confirmed' );
	}

	public function test_a_repository_that_refuses_a_tag_is_reported_as_not_restored(): void {
		DefaultStylesState::use_factory_for_tests( static fn(): object => new DefaultStylesRepositoryFixture( [], true, [ 'h1' ] ) );

		self::assertFalse( DefaultStylesState::restore( [ 'v' => 1, 'items' => [ 'h1' => [ 'type' => 'class', 'variants' => [] ] ] ] ) );
	}

	public function test_a_state_without_items_is_not_restorable(): void {
		DefaultStylesState::use_factory_for_tests( static fn(): object => new DefaultStylesRepositoryFixture( [] ) );

		self::assertFalse( DefaultStylesState::restore( [ 'v' => 1 ] ) );
	}

	/** @return array<string,mixed> */
	private static function style( string $tag, string $color ): array {
		return [
			'id'       => $tag,
			'label'    => $tag,
			'cssName'  => 'e-default-' . $tag,
			'type'     => 'class',
			'variants' => [ [ 'meta' => [ 'breakpoint' => 'desktop', 'state' => null ], 'props' => [ 'color' => [ '$$type' => 'color', 'value' => $color ] ] ] ],
		];
	}
}

/** The surface of Elementor's Default_Styles_Repository that the state uses, over an in-memory map. */
final class DefaultStylesRepositoryFixture {

	/** @var array<string,array<string,mixed>>|null */
	private ?array $cache = null;

	/**
	 * @param array<string,array<string,mixed>> $items
	 * @param list<string>                      $refused Tags that put() refuses, as Elementor does for a tag it does not allow.
	 */
	public function __construct( private array $items, private bool $keeps = true, private array $refused = [] ) {}

	/** @return array<string,array<string,mixed>> */
	public function all( bool $force = false ): array {
		if ( $force || null === $this->cache ) {
			$this->cache = $this->items;
		}
		return $this->cache;
	}

	/** @param array<string,mixed> $data */
	public function put( string $tag, array $data ): bool {
		if ( in_array( $tag, $this->refused, true ) ) {
			return false;
		}
		if ( $this->keeps ) {
			$this->items[ $tag ] = array_merge( [ 'id' => $tag, 'label' => $tag, 'cssName' => 'e-default-' . $tag ], $data );
		}
		return true;
	}

	public function delete( string $tag ): void {
		unset( $this->items[ $tag ] );
	}
}
