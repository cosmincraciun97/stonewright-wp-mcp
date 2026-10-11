<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Gutenberg;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Abilities\Design\SpecToGutenberg;
use Stonewright\WpMcp\Abilities\FSE\Navigation;
use Stonewright\WpMcp\Abilities\FSE\UpdateTemplate;
use Stonewright\WpMcp\Abilities\FSE\WriteGlobalStyles;
use Stonewright\WpMcp\Abilities\FSE\WriteTemplate;
use Stonewright\WpMcp\Abilities\Gutenberg\ApplyToPost;
use Stonewright\WpMcp\Abilities\Gutenberg\InsertBlock;
use Stonewright\WpMcp\Abilities\Gutenberg\RemoveBlock;
use Stonewright\WpMcp\Abilities\Gutenberg\UpdateBlock;
use Stonewright\WpMcp\Abilities\Patterns\CreatePattern;
use Stonewright\WpMcp\Abilities\Patterns\DeletePattern;
use Stonewright\WpMcp\Abilities\Patterns\PatternCategories;
use Stonewright\WpMcp\Abilities\Patterns\UpdatePattern;

/**
 * A Gutenberg, pattern or FSE write whose snapshot could not be stored is refused.
 *
 * Each case makes the snapshot fail the way a real site does: the history meta cannot be stored and
 * read back, so Backup::snapshot_post() returns an empty id. The write must not run, and the same
 * call must work once the snapshot can be stored (so the refusal is about the snapshot and not about
 * the arguments).
 *
 * @covers \Stonewright\WpMcp\Abilities\Gutenberg\InsertBlock
 * @covers \Stonewright\WpMcp\Abilities\Gutenberg\UpdateBlock
 * @covers \Stonewright\WpMcp\Abilities\Gutenberg\RemoveBlock
 * @covers \Stonewright\WpMcp\Abilities\Gutenberg\ApplyToPost
 * @covers \Stonewright\WpMcp\Abilities\Design\SpecToGutenberg
 * @covers \Stonewright\WpMcp\Abilities\Patterns\CreatePattern
 * @covers \Stonewright\WpMcp\Abilities\Patterns\UpdatePattern
 * @covers \Stonewright\WpMcp\Abilities\Patterns\DeletePattern
 * @covers \Stonewright\WpMcp\Abilities\Patterns\PatternCategories
 * @covers \Stonewright\WpMcp\Abilities\FSE\AbstractTemplateWriter
 * @covers \Stonewright\WpMcp\Abilities\FSE\UpdateTemplate
 * @covers \Stonewright\WpMcp\Abilities\FSE\Navigation
 * @covers \Stonewright\WpMcp\FSE\GlobalStylesWriter
 */
final class SnapshotFailureStopsGutenbergFseWriteTest extends TestCase {

	private const POST     = 12;
	private const PATTERN  = 30;
	private const TEMPLATE = 40;
	private const NAV      = 50;
	private const CONTENT  = '<!-- wp:paragraph --><p>Original</p><!-- /wp:paragraph -->';

	protected function setUp(): void {
		$GLOBALS['stonewright_test_options']         = [ 'stonewright_mode' => 'development' ];
		$GLOBALS['stonewright_test_user_caps']       = [
			'manage_options'     => true,
			'edit_theme_options' => true,
			'edit_posts'         => true,
			'edit_post'          => true,
			'edit_pages'         => true,
		];
		$GLOBALS['stonewright_test_user_logged_in']  = true;
		$GLOBALS['stonewright_test_current_user_id'] = 1;
		$GLOBALS['stonewright_test_transients']      = [];
		$GLOBALS['stonewright_test_post_meta_calls'] = [];
		$GLOBALS['stonewright_test_next_post_id']    = 7000;
		$GLOBALS['stonewright_test_inserted_posts']  = [];
		$GLOBALS['stonewright_test_deleted_posts']   = [];
		$GLOBALS['stonewright_test_object_terms']    = [];
		$GLOBALS['stonewright_test_registered_blocks'] = [
			'core/paragraph' => (object) [
				'attributes'      => [ 'content' => [ 'type' => 'string' ] ],
				'render_callback' => static fn(): string => '',
				'is_dynamic'      => true,
			],
		];
		$GLOBALS['stonewright_test_posts'] = [];
		foreach (
			[
				self::POST     => 'page',
				self::PATTERN  => 'wp_block',
				self::TEMPLATE => 'wp_template',
				self::NAV      => 'wp_navigation',
			] as $id => $type
		) {
			$GLOBALS['stonewright_test_posts'][ $id ] = (object) [
				'ID'           => $id,
				'post_type'    => $type,
				'post_status'  => 'publish',
				'post_name'    => 'wp_template' === $type ? 'index' : 'item-' . $id,
				'post_title'   => 'Item ' . $id,
				'post_content' => self::CONTENT,
				'post_excerpt' => '',
				'meta'         => [],
			];
		}
		$GLOBALS['stonewright_test_object_terms'][ self::TEMPLATE ]['wp_theme'] = [ 'stonewright-theme' ];
		$GLOBALS['stonewright_test_block_template_lookup'] = static function ( string $id, string $type ): ?object {
			if ( 'wp_template' !== $type || 'stonewright-theme//index' !== $id ) {
				return null;
			}
			return (object) [
				'id'      => $id,
				'wp_id'   => self::TEMPLATE,
				'type'    => $type,
				'slug'    => 'index',
				'theme'   => 'stonewright-theme',
				'content' => self::CONTENT,
			];
		};
	}

	protected function tearDown(): void {
		unset(
			$GLOBALS['stonewright_test_update_post_meta_returns'],
			$GLOBALS['stonewright_test_block_template_lookup'],
			$GLOBALS['stonewright_test_registered_blocks']
		);
		$GLOBALS['stonewright_test_posts']           = [];
		$GLOBALS['stonewright_test_options']         = [];
		$GLOBALS['stonewright_test_user_caps']       = [];
		$GLOBALS['stonewright_test_user_logged_in']  = false;
		$GLOBALS['stonewright_test_current_user_id'] = 0;
		$GLOBALS['stonewright_test_object_terms']    = [];
	}

	/** @return iterable<string, array{0: callable():AbilityKernel, 1: array<string, mixed>, 2: int}> */
	public static function writers(): iterable {
		$spec = [
			'version'  => '1.0.0',
			'page'     => [ 'title' => 'Apply Test Page' ],
			'sections' => [
				[
					'id'     => 'hero',
					'blocks' => [ [ 'type' => 'heading', 'text' => 'Hello World', 'level' => 1 ] ],
				],
			],
		];
		$para = '<!-- wp:paragraph --><p>Changed</p><!-- /wp:paragraph -->';

		yield 'blocks-insert' => [ static fn () => new InsertBlock(), [ 'post_id' => self::POST, 'block' => [ 'name' => 'core/paragraph', 'innerHTML' => '<p>New</p>' ] ], self::POST ];
		yield 'blocks-update' => [ static fn () => new UpdateBlock(), [ 'post_id' => self::POST, 'path' => [ 0 ], 'innerHTML' => '<p>Changed</p>' ], self::POST ];
		yield 'blocks-remove' => [ static fn () => new RemoveBlock(), [ 'post_id' => self::POST, 'path' => [ 0 ] ], self::POST ];
		yield 'gutenberg-apply-to-post' => [ static fn () => new ApplyToPost(), [ 'post_id' => self::POST, 'spec' => $spec ], self::POST ];
		yield 'design-spec-to-gutenberg' => [ static fn () => new SpecToGutenberg(), [ 'post_id' => self::POST, 'spec' => $spec, 'append' => true ], self::POST ];
		yield 'patterns-update' => [ static fn () => new UpdatePattern(), [ 'id' => self::PATTERN, 'content' => $para ], self::PATTERN ];
		yield 'patterns-delete' => [ static fn () => new DeletePattern(), [ 'id' => self::PATTERN ], self::PATTERN ];
		yield 'patterns-categories' => [ static fn () => new PatternCategories(), [ 'action' => 'assign', 'id' => self::PATTERN, 'categories' => [ 'featured' ] ], self::PATTERN ];
		yield 'fse-write-template (existing)' => [ static fn () => new WriteTemplate(), [ 'template_slug' => 'index', 'theme' => 'stonewright-theme', 'content' => $para ], self::TEMPLATE ];
		yield 'fse-update-template' => [ static fn () => new UpdateTemplate(), [ 'id' => 'stonewright-theme//index', 'type' => 'wp_template', 'content' => $para ], self::TEMPLATE ];
		yield 'fse-navigation (update)' => [ static fn () => new Navigation(), [ 'action' => 'update', 'id' => self::NAV, 'content' => $para ], self::NAV ];
		yield 'fse-write-global-styles' => [ static fn () => new WriteGlobalStyles(), [ 'theme_json' => [ 'version' => 3, 'settings' => [ 'color' => [ 'palette' => [ [ 'slug' => 'primary', 'color' => '#112233', 'name' => 'Primary' ] ] ] ] ] ], 2 ];
	}

	/**
	 * @param callable():AbilityKernel $make
	 * @param array<string, mixed>     $args
	 * @dataProvider writers
	 */
	public function test_a_failed_snapshot_stops_the_write( callable $make, array $args, int $target ): void {
		if ( ! isset( $GLOBALS['stonewright_test_posts'][ $target ] ) ) {
			// The global styles record the resolver stub points at.
			$GLOBALS['stonewright_test_posts'][ $target ] = (object) [
				'ID'           => $target,
				'post_type'    => 'wp_global_styles',
				'post_status'  => 'publish',
				'post_name'    => 'wp-global-styles-test',
				'post_title'   => 'Custom Styles',
				'post_content' => '{}',
				'post_excerpt' => '',
				'meta'         => [],
			];
		}
		$before_post = clone $GLOBALS['stonewright_test_posts'][ $target ];
		// The history meta cannot be stored, so the snapshot cannot be read back and comes out empty.
		$GLOBALS['stonewright_test_update_post_meta_returns'] = [ '_stonewright_backups' => true ];

		$result = $make()->execute( $args );

		self::assertInstanceOf( \WP_Error::class, $result, 'The write must be refused.' );
		self::assertSame( 'stonewright_backup_failed', $result->get_error_code() );
		self::assertEquals( $before_post, $GLOBALS['stonewright_test_posts'][ $target ], 'The post is untouched.' );
		self::assertSame( [], $GLOBALS['stonewright_test_inserted_posts'], 'No post was inserted.' );

		// Once the snapshot can be stored, the same call goes through: the refusal was about the snapshot.
		unset( $GLOBALS['stonewright_test_update_post_meta_returns'] );
		$retry = $make()->execute( $args );
		self::assertNotInstanceOf( \WP_Error::class, $retry, $retry instanceof \WP_Error ? $retry->get_error_code() . ': ' . $retry->get_error_message() : '' );
	}

	/**
	 * A create has nothing to snapshot beforehand, so a snapshot that fails afterwards takes the new
	 * post back out: the write is undone, not left behind unprotected.
	 *
	 * @return iterable<string, array{0: callable():AbilityKernel, 1: array<string, mixed>, 2: string}>
	 */
	public static function creators(): iterable {
		yield 'patterns-create' => [ static fn () => new CreatePattern(), [ 'title' => 'Hero', 'content' => self::CONTENT ], 'wp_block' ];
		yield 'fse-navigation (create)' => [ static fn () => new Navigation(), [ 'action' => 'create', 'title' => 'Primary', 'content' => self::CONTENT ], 'wp_navigation' ];
	}

	/**
	 * @param callable():AbilityKernel $make
	 * @param array<string, mixed>     $args
	 * @dataProvider creators
	 */
	public function test_a_failed_snapshot_removes_the_post_a_create_just_made( callable $make, array $args, string $type ): void {
		$GLOBALS['stonewright_test_update_post_meta_returns'] = [ '_stonewright_backups' => true ];

		$result = $make()->execute( $args );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_backup_failed', $result->get_error_code() );
		foreach ( $GLOBALS['stonewright_test_inserted_posts'] as $inserted ) {
			self::assertArrayNotHasKey( (int) $inserted['ID'], $GLOBALS['stonewright_test_posts'], 'The new ' . $type . ' post does not stay.' );
		}
		self::assertNotSame( [], $GLOBALS['stonewright_test_inserted_posts'], 'The create did run before the snapshot was taken.' );

		unset( $GLOBALS['stonewright_test_update_post_meta_returns'] );
		$retry = $make()->execute( $args );
		self::assertNotInstanceOf( \WP_Error::class, $retry );
	}
}
