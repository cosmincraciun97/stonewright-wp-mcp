<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Gutenberg;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\Gutenberg\BlocksBatchMutate;
use Stonewright\WpMcp\Abilities\Gutenberg\InsertBlock;
use Stonewright\WpMcp\Abilities\Gutenberg\ParseBlocks;
use Stonewright\WpMcp\Abilities\Gutenberg\RemoveBlock;
use Stonewright\WpMcp\Abilities\Gutenberg\UpdateBlock;
use Stonewright\WpMcp\Support\BlockTree;

/**
 * One addressing scheme for every block read and write: the path returned by
 * blocks-parse names the same block in blocks-update, blocks-remove,
 * blocks-insert and blocks-batch-mutate, also when the stored content has
 * blank separators between blocks (what the block editor writes).
 *
 * @covers \Stonewright\WpMcp\Abilities\Gutenberg\BlocksBatchMutate
 * @covers \Stonewright\WpMcp\Abilities\Gutenberg\InsertBlock
 * @covers \Stonewright\WpMcp\Abilities\Gutenberg\ParseBlocks
 * @covers \Stonewright\WpMcp\Abilities\Gutenberg\RemoveBlock
 * @covers \Stonewright\WpMcp\Abilities\Gutenberg\UpdateBlock
 * @covers \Stonewright\WpMcp\Support\BlockTree
 */
final class BlockAddressingTest extends TestCase {

	private const POST = 910;

	protected function setUp(): void {
		$GLOBALS['stonewright_test_options']               = [ 'stonewright_mode' => 'development' ];
		$GLOBALS['stonewright_test_user_caps']             = [ 'edit_post' => true, 'edit_posts' => true ];
		$GLOBALS['stonewright_test_user_logged_in']        = true;
		$GLOBALS['stonewright_test_current_user_id']       = 42;
		$GLOBALS['stonewright_test_post_meta_calls']       = [];
		$GLOBALS['stonewright_test_wp_update_post_return'] = null;
		$GLOBALS['stonewright_test_registered_blocks']     = [
			'core/paragraph' => (object) [
				'attributes'      => [ 'className' => [ 'type' => 'string' ] ],
				'render_callback' => static fn(): string => '',
				'is_dynamic'      => true,
			],
			'core/group'     => (object) [
				'attributes'      => [ 'className' => [ 'type' => 'string' ] ],
				'render_callback' => static fn(): string => '',
				'is_dynamic'      => true,
			],
		];
		$this->store( self::abc() );
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_posts']            = [];
		$GLOBALS['stonewright_test_options']          = [];
		$GLOBALS['stonewright_test_user_caps']        = [];
		$GLOBALS['stonewright_test_user_logged_in']   = false;
		$GLOBALS['stonewright_test_post_meta_calls']  = [];
		unset( $GLOBALS['stonewright_test_registered_blocks'] );
	}

	/** Three root paragraphs separated by the blank lines the block editor writes. */
	private static function abc(): string {
		return self::para( 'A' ) . "\n\n" . self::para( 'B' ) . "\n\n" . self::para( 'C' );
	}

	/** A paragraph, a group holding two paragraphs, a closing paragraph. */
	private static function nested(): string {
		return self::para( 'Intro' ) . "\n\n"
			. "<!-- wp:group -->\n<div class=\"wp-block-group\">\n\n"
			. self::para( 'G1' ) . "\n\n" . self::para( 'G2' ) . "\n\n"
			. "</div>\n<!-- /wp:group -->\n\n"
			. self::para( 'Outro' );
	}

	private static function para( string $text ): string {
		return "<!-- wp:paragraph -->\n<p>" . $text . "</p>\n<!-- /wp:paragraph -->";
	}

	private function store( string $content ): void {
		$GLOBALS['stonewright_test_posts'] = [
			self::POST => (object) [
				'ID'           => self::POST,
				'post_type'    => 'page',
				'post_status'  => 'draft',
				'post_title'   => 'Addressing target',
				'post_content' => $content,
				'post_excerpt' => '',
				'meta'         => [],
			],
		];
	}

	private function content(): string {
		return (string) $GLOBALS['stonewright_test_posts'][ self::POST ]->post_content;
	}

	/**
	 * Text outline of a tree as blocks-parse reports it: one entry per
	 * addressable block, children nested.
	 *
	 * @param array<int, array<string, mixed>> $blocks
	 * @return array<int, mixed>
	 */
	private function outline( array $blocks ): array {
		$out = [];
		foreach ( $blocks as $block ) {
			$text  = trim( wp_strip_all_tags( (string) $block['innerHTML'] ) );
			$inner = (array) ( $block['innerBlocks'] ?? [] );
			$out[] = [] === $inner ? $text : [ (string) ( $block['name'] ?? '' ) => $this->outline( $inner ) ];
		}
		return $out;
	}

	/** @return array<int, mixed> */
	private function parsed_outline(): array {
		$parsed = ( new ParseBlocks() )->execute( [ 'post_id' => self::POST, 'responseMode' => 'full' ] );
		self::assertIsArray( $parsed );
		return $this->outline( $parsed['blocks'] );
	}

	/** The group's wrapper markup stays around its children after a nested write. */
	private function assertGroupWrapperIntact(): void {
		self::assertMatchesRegularExpression(
			'#<!-- wp:(?:core/)?group -->\s*<div class="wp-block-group">\s*<!-- wp:(?:core/)?paragraph -->.*<!-- /wp:(?:core/)?paragraph -->\s*</div>\s*<!-- /wp:(?:core/)?group -->#s',
			$this->content()
		);
		self::assertSame( 1, substr_count( $this->content(), '</div>' ) );
	}

	// ---------------------------------------------------------------------
	// Baseline: what blocks-parse reports.
	// ---------------------------------------------------------------------

	public function test_parse_lists_the_blocks_without_the_blank_separators(): void {
		self::assertSame( [ 'A', 'B', 'C' ], $this->parsed_outline() );
	}

	public function test_block_tree_parse_is_the_list_blocks_parse_reports(): void {
		$tree = BlockTree::parse( $this->content() );
		self::assertCount( 3, $tree );
		self::assertSame( [ 'core/paragraph', 'core/paragraph', 'core/paragraph' ], array_column( $tree, 'blockName' ) );
	}

	// ---------------------------------------------------------------------
	// blocks-update
	// ---------------------------------------------------------------------

	public function test_update_changes_the_block_blocks_parse_lists_at_that_path(): void {
		$result = ( new UpdateBlock() )->execute( [ 'post_id' => self::POST, 'path' => [ 1 ], 'innerHTML' => '<p>B-updated</p>' ] );

		self::assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_code() : '' );
		self::assertSame( [ 'A', 'B-updated', 'C' ], $this->parsed_outline() );
		self::assertStringContainsString( '<p>A</p>', $this->content() );
		self::assertStringContainsString( '<p>C</p>', $this->content() );
		self::assertStringNotContainsString( '<p>B</p>', $this->content() );
	}

	public function test_update_addresses_the_last_block_by_its_parse_index(): void {
		$result = ( new UpdateBlock() )->execute( [ 'post_id' => self::POST, 'path' => [ 2 ], 'innerHTML' => '<p>C-updated</p>' ] );

		self::assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_code() : '' );
		self::assertSame( [ 'A', 'B', 'C-updated' ], $this->parsed_outline() );
	}

	public function test_update_with_a_path_past_the_last_block_fails_and_changes_nothing(): void {
		$before = $this->content();
		$result = ( new UpdateBlock() )->execute( [ 'post_id' => self::POST, 'path' => [ 3 ], 'innerHTML' => '<p>X</p>' ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_invalid_path', $result->get_error_code() );
		self::assertSame( $before, $this->content() );
	}

	public function test_update_addresses_a_nested_block_by_its_parse_path(): void {
		$this->store( self::nested() );
		self::assertSame( [ 'Intro', [ 'core/group' => [ 'G1', 'G2' ] ], 'Outro' ], $this->parsed_outline() );

		$result = ( new UpdateBlock() )->execute( [ 'post_id' => self::POST, 'path' => [ 1, 1 ], 'innerHTML' => '<p>G2-updated</p>' ] );

		self::assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_code() : '' );
		self::assertSame( [ 'Intro', [ 'core/group' => [ 'G1', 'G2-updated' ] ], 'Outro' ], $this->parsed_outline() );
		$this->assertGroupWrapperIntact();
	}

	public function test_update_refuses_to_replace_the_html_of_a_block_that_holds_inner_blocks(): void {
		$this->store( self::nested() );
		$before = $this->content();
		$result = ( new UpdateBlock() )->execute( [ 'post_id' => self::POST, 'path' => [ 1 ], 'innerHTML' => '<div>flat</div>' ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_unsafe_nested_inner_html', $result->get_error_code() );
		self::assertSame( $before, $this->content() );
	}

	public function test_update_on_an_empty_post_fails_with_a_structured_error(): void {
		$this->store( '' );
		$result = ( new UpdateBlock() )->execute( [ 'post_id' => self::POST, 'path' => [ 0 ], 'innerHTML' => '<p>X</p>' ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_invalid_path', $result->get_error_code() );
		self::assertSame( '', $this->content() );
	}

	// ---------------------------------------------------------------------
	// blocks-remove
	// ---------------------------------------------------------------------

	public function test_remove_deletes_the_block_blocks_parse_lists_at_that_path(): void {
		$result = ( new RemoveBlock() )->execute( [ 'post_id' => self::POST, 'path' => [ 1 ] ] );

		self::assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_code() : '' );
		self::assertSame( [ 'A', 'C' ], $this->parsed_outline() );
		self::assertStringNotContainsString( '<p>B</p>', $this->content() );
	}

	public function test_remove_with_a_path_past_the_last_block_fails_and_changes_nothing(): void {
		$before = $this->content();
		$result = ( new RemoveBlock() )->execute( [ 'post_id' => self::POST, 'path' => [ 3 ] ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_invalid_path', $result->get_error_code() );
		self::assertSame( $before, $this->content() );
	}

	public function test_remove_addresses_a_nested_block_by_its_parse_path(): void {
		$this->store( self::nested() );
		$result = ( new RemoveBlock() )->execute( [ 'post_id' => self::POST, 'path' => [ 1, 0 ] ] );

		self::assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_code() : '' );
		self::assertSame( [ 'Intro', [ 'core/group' => [ 'G2' ] ], 'Outro' ], $this->parsed_outline() );
		$this->assertGroupWrapperIntact();
	}

	public function test_remove_on_an_empty_post_fails_with_a_structured_error(): void {
		$this->store( '' );
		$result = ( new RemoveBlock() )->execute( [ 'post_id' => self::POST, 'path' => [ 0 ] ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_invalid_path', $result->get_error_code() );
	}

	// ---------------------------------------------------------------------
	// blocks-insert
	// ---------------------------------------------------------------------

	private function insert( array $extra ): array|\WP_Error {
		return ( new InsertBlock() )->execute(
			array_merge(
				[
					'post_id' => self::POST,
					'block'   => [ 'name' => 'core/paragraph', 'innerHTML' => '<p>NEW</p>' ],
				],
				$extra
			)
		);
	}

	public function test_insert_position_is_an_index_into_the_blocks_parse_list(): void {
		$result = $this->insert( [ 'path' => [], 'position' => 1 ] );

		self::assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_code() : '' );
		self::assertSame( [ 'A', 'NEW', 'B', 'C' ], $this->parsed_outline() );
		self::assertSame( [ 1 ], $result['path'] );
	}

	public function test_insert_without_a_position_appends_after_the_last_listed_block(): void {
		$result = $this->insert( [] );

		self::assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_code() : '' );
		self::assertSame( [ 'A', 'B', 'C', 'NEW' ], $this->parsed_outline() );
		self::assertSame( [ 3 ], $result['path'] );
	}

	public function test_insert_into_a_group_uses_the_parse_path_of_the_group(): void {
		$this->store( self::nested() );
		$result = $this->insert( [ 'path' => [ 1 ], 'position' => 1 ] );

		self::assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_code() : '' );
		self::assertSame( [ 'Intro', [ 'core/group' => [ 'G1', 'NEW', 'G2' ] ], 'Outro' ], $this->parsed_outline() );
		$this->assertGroupWrapperIntact();
	}

	public function test_insert_with_an_unknown_parent_path_fails_and_changes_nothing(): void {
		$before = $this->content();
		$result = $this->insert( [ 'path' => [ 7 ], 'position' => 0 ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_invalid_path', $result->get_error_code() );
		self::assertSame( $before, $this->content() );
	}

	public function test_insert_into_a_wrapper_without_children_fails_instead_of_misplacing_the_block(): void {
		$this->store( "<!-- wp:group -->\n<div class=\"wp-block-group\"></div>\n<!-- /wp:group -->" );
		$before = $this->content();
		$result = $this->insert( [ 'path' => [ 0 ], 'position' => 0 ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_unsafe_nested_structure', $result->get_error_code() );
		self::assertSame( $before, $this->content() );
	}
	public function test_insert_into_an_empty_post_creates_the_first_block(): void {
		$this->store( '' );
		$result = $this->insert( [] );

		self::assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_code() : '' );
		self::assertSame( [ 'NEW' ], $this->parsed_outline() );
		self::assertSame( [ 0 ], $result['path'] );
	}

	public function test_insert_into_a_whitespace_only_post_creates_the_first_block(): void {
		$this->store( "\n\n" );
		self::assertSame( [], $this->parsed_outline() );
		$result = $this->insert( [] );

		self::assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_code() : '' );
		self::assertSame( [ 'NEW' ], $this->parsed_outline() );
	}

	// ---------------------------------------------------------------------
	// blocks-batch-mutate
	// ---------------------------------------------------------------------

	/**
	 * @param array<int, array<string, mixed>> $operations
	 * @return array<string, mixed>|\WP_Error
	 */
	private function batch( array $operations ): array|\WP_Error {
		$ability = new BlocksBatchMutate();
		$plan    = $ability->execute( [ 'post_id' => self::POST, 'dry_run' => true, 'operations' => $operations ] );
		if ( $plan instanceof \WP_Error ) {
			return $plan;
		}
		return $ability->execute(
			[
				'post_id'               => self::POST,
				'operations'            => $operations,
				'expected_content_hash' => $plan['before_hash'],
			]
		);
	}

	public function test_batch_update_and_remove_use_the_blocks_parse_paths(): void {
		$result = $this->batch(
			[
				[ 'action' => 'update', 'path' => [ 1 ], 'innerHTML' => '<p>B-updated</p>' ],
				[ 'action' => 'remove', 'path' => [ 2 ] ],
			]
		);

		self::assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_code() : '' );
		self::assertSame( [ 'A', 'B-updated' ], $this->parsed_outline() );
	}

	public function test_batch_dry_run_preview_lists_only_addressable_blocks(): void {
		$result = ( new BlocksBatchMutate() )->execute(
			[
				'post_id'    => self::POST,
				'dry_run'    => true,
				'operations' => [ [ 'action' => 'update', 'path' => [ 0 ], 'innerHTML' => '<p>A2</p>' ] ],
			]
		);

		self::assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_code() : '' );
		self::assertSame( 3, $result['preview_summary']['root_block_count'] );
		self::assertSame( [ 'core/paragraph', 'core/paragraph', 'core/paragraph' ], $result['preview_summary']['block_names'] );
	}

	public function test_batch_insert_before_and_after_anchors_use_the_blocks_parse_paths(): void {
		$block  = [ 'blockName' => 'core/paragraph', 'innerHTML' => '<p>NEW</p>' ];
		$result = $this->batch(
			[
				[ 'action' => 'insert', 'before_path' => [ 1 ], 'block' => $block ],
			]
		);
		self::assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_code() : '' );
		self::assertSame( [ 'A', 'NEW', 'B', 'C' ], $this->parsed_outline() );

		$this->store( self::abc() );
		$result = $this->batch(
			[
				[ 'action' => 'insert', 'after_path' => [ 2 ], 'block' => $block ],
			]
		);
		self::assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_code() : '' );
		self::assertSame( [ 'A', 'B', 'C', 'NEW' ], $this->parsed_outline() );
	}

	public function test_batch_with_a_path_past_the_last_block_fails_and_writes_nothing(): void {
		$before = $this->content();
		$result = ( new BlocksBatchMutate() )->execute(
			[
				'post_id'    => self::POST,
				'dry_run'    => true,
				'operations' => [ [ 'action' => 'update', 'path' => [ 3 ], 'innerHTML' => '<p>X</p>' ] ],
			]
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( $before, $this->content() );
	}

	public function test_batch_update_addresses_a_nested_block_by_its_parse_path(): void {
		$this->store( self::nested() );
		$result = $this->batch( [ [ 'action' => 'update', 'path' => [ 1, 0 ], 'innerHTML' => '<p>G1-updated</p>' ] ] );

		self::assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_code() : '' );
		self::assertSame( [ 'Intro', [ 'core/group' => [ 'G1-updated', 'G2' ] ], 'Outro' ], $this->parsed_outline() );
	}

	public function test_batch_insert_into_an_empty_post_creates_the_first_block(): void {
		$this->store( '' );
		$result = $this->batch(
			[ [ 'action' => 'insert', 'block' => [ 'blockName' => 'core/paragraph', 'innerHTML' => '<p>NEW</p>' ] ] ]
		);

		self::assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_code() : '' );
		self::assertSame( [ 'NEW' ], $this->parsed_outline() );
	}

	public function test_batch_remove_on_an_empty_post_fails_with_a_structured_error(): void {
		$this->store( '' );
		$result = ( new BlocksBatchMutate() )->execute(
			[ 'post_id' => self::POST, 'dry_run' => true, 'operations' => [ [ 'action' => 'remove', 'path' => [ 0 ] ] ] ]
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( '', $this->content() );
	}
}
