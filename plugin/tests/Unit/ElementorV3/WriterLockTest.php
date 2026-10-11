<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\ElementorV3;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Abilities\ElementorV3\AddContainer;
use Stonewright\WpMcp\Abilities\ElementorV3\AddWidget;
use Stonewright\WpMcp\Abilities\ElementorV3\BuildPageFromSpec;
use Stonewright\WpMcp\Abilities\ElementorV3\MoveElement;
use Stonewright\WpMcp\Abilities\ElementorV3\RemoveElement;
use Stonewright\WpMcp\Abilities\ElementorV3\RepairDocument;
use Stonewright\WpMcp\Design\Direction\DesignDirectionService;
use Stonewright\WpMcp\Elementor\Write\PostWriteLock;

/**
 * A single-element writer takes the post's write lease before it reads the document, so two writers never
 * read the same tree and the second one overwrites the first. While another writer holds the lease the call
 * is refused as busy, before anything is snapshotted or written, and the other writer's lease is left alone.
 *
 * @covers \Stonewright\WpMcp\Abilities\ElementorV3\AddContainer
 * @covers \Stonewright\WpMcp\Abilities\ElementorV3\AddWidget
 * @covers \Stonewright\WpMcp\Abilities\ElementorV3\MoveElement
 * @covers \Stonewright\WpMcp\Abilities\ElementorV3\RemoveElement
 * @covers \Stonewright\WpMcp\Abilities\ElementorV3\BuildPageFromSpec
 * @covers \Stonewright\WpMcp\Abilities\ElementorV3\RepairDocument
 */
final class WriterLockTest extends TestCase {

	private const PAGE = 750;

	protected function setUp(): void {
		$tree = [
			[
				'id'       => 'root',
				'elType'   => 'container',
				'settings' => [],
				'elements' => [
					[ 'id' => 'w1', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => [ 'title' => 'Existing' ], 'elements' => [] ],
				],
			],
			[ 'id' => 'other', 'elType' => 'container', 'settings' => [], 'elements' => [] ],
		];
		$GLOBALS['stonewright_test_posts']           = [
			self::PAGE => (object) [
				'ID'           => self::PAGE,
				'post_type'    => 'page',
				'post_status'  => 'draft',
				'post_title'   => 'Target',
				'post_content' => '',
				'post_excerpt' => '',
				'post_parent'  => 0,
				'post_name'    => 'target',
				'meta'         => [
					'_elementor_data'      => (string) wp_json_encode( $tree ),
					'_elementor_edit_mode' => 'builder',
					'_elementor_version'   => defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : '3.0.0',
				],
			],
		];
		$GLOBALS['stonewright_test_post_meta_calls'] = [];
		$GLOBALS['stonewright_test_options']         = [
			'stonewright_mode'                     => 'development',
			DesignDirectionService::ACTIVE_OPTION  => 9101,
			'stonewright_design_checkpoint_secret' => str_repeat( 'c', 64 ),
		];
		$GLOBALS['stonewright_test_user_caps']       = [ 'edit_post' => true, 'edit_posts' => true ];
		$GLOBALS['stonewright_test_user_logged_in']  = true;
		$GLOBALS['stonewright_test_current_user_id'] = 42;
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_posts']           = [];
		$GLOBALS['stonewright_test_post_meta_calls'] = [];
		$GLOBALS['stonewright_test_options']         = [];
		$GLOBALS['stonewright_test_user_caps']       = [];
		$GLOBALS['stonewright_test_user_logged_in']  = false;
		$GLOBALS['stonewright_test_current_user_id'] = 0;
	}

	/**
	 * @return iterable<string, array{0:callable():AbilityKernel, 1:array<string, mixed>}>
	 */
	public static function writers(): iterable {
		$spec = [
			'version'  => '1.0.0',
			'page'     => [ 'title' => 'Fast Path' ],
			'sections' => [ [ 'id' => 'hero', 'blocks' => [ [ 'type' => 'heading', 'text' => 'Hello', 'level' => 1 ] ] ] ],
		];
		yield 'add-container' => [ static fn () => new AddContainer(), [ 'post_id' => self::PAGE ] ];
		yield 'add-widget' => [ static fn () => new AddWidget(), [ 'post_id' => self::PAGE, 'parent_id' => 'other', 'widget_type' => 'heading', 'allow_raw_known_widget' => true, 'settings' => [ 'title' => 'New' ] ] ];
		yield 'move-element' => [ static fn () => new MoveElement(), [ 'post_id' => self::PAGE, 'element_id' => 'w1', 'new_parent_id' => 'other' ] ];
		yield 'remove-element' => [ static fn () => new RemoveElement(), [ 'post_id' => self::PAGE, 'element_id' => 'w1' ] ];
		yield 'build-page-from-spec append' => [ static fn () => new BuildPageFromSpec(), [ 'post_id' => self::PAGE, 'mode' => 'append', 'spec' => $spec ] ];
		yield 'build-page-from-spec replace_section' => [ static fn () => new BuildPageFromSpec(), [ 'post_id' => self::PAGE, 'mode' => 'replace_section', 'spec' => $spec ] ];
		yield 'build-page-from-spec replace' => [ static fn () => new BuildPageFromSpec(), [ 'post_id' => self::PAGE, 'mode' => 'replace', 'spec' => $spec ] ];
		yield 'repair-document' => [ static fn () => new RepairDocument(), [ 'post_id' => self::PAGE ] ];
	}

	/**
	 * @param callable():AbilityKernel $make
	 * @param array<string, mixed>     $args
	 * @dataProvider writers
	 */
	public function test_a_write_is_refused_as_busy_while_another_writer_holds_the_lease( callable $make, array $args ): void {
		$before = (array) $GLOBALS['stonewright_test_posts'][ self::PAGE ]->meta;
		PostWriteLock::acquire( self::PAGE, 'other-writer', 30 );

		$result = $make()->execute( $args );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_elementor_write_busy', $result->get_error_code() );
		self::assertSame( [], $GLOBALS['stonewright_test_post_meta_calls'], 'Nothing was snapshotted or written.' );
		self::assertSame( $before, (array) $GLOBALS['stonewright_test_posts'][ self::PAGE ]->meta );
		self::assertTrue( PostWriteLock::owned_by( self::PAGE, 'other-writer' ), 'The other writer keeps its lease.' );
	}

	/**
	 * @param callable():AbilityKernel $make
	 * @param array<string, mixed>     $args
	 * @dataProvider writers
	 */
	public function test_a_write_that_holds_the_lease_releases_it( callable $make, array $args ): void {
		$result = $make()->execute( $args );

		if ( $result instanceof \WP_Error ) {
			// replace_section needs a record this page does not have; the refusal still must not keep the lease.
			self::assertNotSame( 'stonewright_elementor_write_busy', $result->get_error_code() );
		}
		self::assertIsArray( PostWriteLock::acquire( self::PAGE, 'next-writer', 30 ), 'The lease is free afterwards.' );
	}
}
