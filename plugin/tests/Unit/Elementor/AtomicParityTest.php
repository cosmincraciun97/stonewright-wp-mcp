<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Elementor;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\ElementorV3\UpdateElement;
use Stonewright\WpMcp\Abilities\ElementorV4\UpdateNode;
use Stonewright\WpMcp\Elementor\V4\AtomicTreeInspector;

/**
 * Elementor Atomic semantic parity regression suite.
 *
 * Guards the mutation-correctness contract: surgical writes keep element ids
 * and widget types stable, leave sibling settings and V3 subtrees untouched,
 * store markup-bearing settings as the exact single-parse source string (no
 * re-escaping or double encoding), and produce documents that survive an
 * encode/decode/reopen roundtrip unchanged.
 *
 * @covers \Stonewright\WpMcp\Abilities\ElementorV4\UpdateNode
 * @covers \Stonewright\WpMcp\Elementor\V4\AtomicTreeInspector
 */
final class AtomicParityTest extends TestCase {

	private const POST_ID  = 701;
	private const FIXTURE  = 'mixed-v3-v4-document.json';
	private const PARA_ID  = 'w-v4-para';
	private const SIBLING  = 'w-v4-para-two';
	private const V3_TEXT  = 'w-v3-text';

	/**
	 * Decoded fixture document (native-save shape, synthetic content).
	 *
	 * @return array<int,mixed>
	 */
	private function fixture_tree(): array {
		$path = dirname( __DIR__, 2 ) . '/fixtures/elementor/atomic-parity/' . self::FIXTURE;
		self::assertFileExists( $path );
		$tree = json_decode( (string) file_get_contents( $path ), true );
		self::assertIsArray( $tree );
		return $tree;
	}

	protected function setUp(): void {
		$GLOBALS['stonewright_test_posts'] = [
			self::POST_ID => (object) [
				'ID'           => self::POST_ID,
				'post_type'    => 'page',
				'post_status'  => 'draft',
				'post_title'   => 'Atomic parity target',
				'post_content' => '',
				'post_excerpt' => '',
				'meta'         => [
					'_elementor_data'      => wp_json_encode( $this->fixture_tree() ),
					'_elementor_edit_mode' => 'builder',
					'_elementor_version'   => defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : '3.0.0',
				],
			],
		];
		$GLOBALS['stonewright_test_post_meta_calls'] = [];
		$GLOBALS['stonewright_test_options']         = [
			'stonewright_mode'                => 'development',
			'stonewright_elementor_v4_atomic' => true,
		];
		$GLOBALS['stonewright_test_user_caps']       = [ 'edit_post' => true, 'edit_posts' => true ];
		$GLOBALS['stonewright_test_user_logged_in']  = true;
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_posts']           = [];
		$GLOBALS['stonewright_test_post_meta_calls'] = [];
		$GLOBALS['stonewright_test_options']         = [];
		$GLOBALS['stonewright_test_user_caps']       = [];
		$GLOBALS['stonewright_test_user_logged_in']  = false;
	}

	/**
	 * Read back the stored document tree after a write.
	 *
	 * @return array<int,mixed>
	 */
	private function stored_tree(): array {
		$raw   = (string) $GLOBALS['stonewright_test_posts'][ self::POST_ID ]->meta['_elementor_data'];
		// The test meta stub already unslashes written values, so the raw
		// payload is valid JSON as-is; keep a legacy slashed-storage fallback.
		$tree  = json_decode( $raw, true );
		if ( ! is_array( $tree ) ) {
			$tree = json_decode( stripslashes( $raw ), true );
		}
		self::assertIsArray( $tree );
		return $tree;
	}

	/**
	 * Find one node by id anywhere in the tree.
	 *
	 * @param array<int,mixed> $tree Document tree.
	 * @return array<string,mixed>|null
	 */
	private function find_node( array $tree, string $id ): ?array {
		foreach ( $tree as $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}
			if ( (string) ( $node['id'] ?? '' ) === $id ) {
				return $node;
			}
			$children = $node['elements'] ?? [];
			if ( is_array( $children ) ) {
				$found = $this->find_node( $children, $id );
				if ( null !== $found ) {
					return $found;
				}
			}
		}
		return null;
	}

	/**
	 * Collect every element id in the tree.
	 *
	 * @param array<int,mixed> $tree Document tree.
	 * @return list<string>
	 */
	private function collect_ids( array $tree ): array {
		$ids = [];
		foreach ( $tree as $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}
			$ids[] = (string) ( $node['id'] ?? '' );
			$children = $node['elements'] ?? [];
			if ( is_array( $children ) ) {
				$ids = array_merge( $ids, $this->collect_ids( $children ) );
			}
		}
		return $ids;
	}

	public function test_patched_markup_setting_stores_the_exact_single_parse_source(): void {
		$replacement = '<p>Updated <strong>bold</strong> &amp; <em>italic <a href="https://example.test/x">link</a></em> text.</p>';

		$result = ( new UpdateNode() )->execute(
			[
				'post_id'    => self::POST_ID,
				'element_id' => self::PARA_ID,
				'settings'   => [ 'text' => $replacement ],
			]
		);
		self::assertIsArray( $result );

		$node = $this->find_node( $this->stored_tree(), self::PARA_ID );
		self::assertIsArray( $node );

		// Single source of truth: the stored markup equals the supplied string
		// byte for byte — no re-escaping, no entity drift, no double encoding.
		self::assertSame( $replacement, $node['settings']['text'] );

		// Encode/decode roundtrip preserves the same string.
		$roundtrip = json_decode( wp_json_encode( $node ), true );
		self::assertSame( $replacement, $roundtrip['settings']['text'] );
	}

	public function test_ids_and_widget_types_are_stable_across_surgical_writes(): void {
		$before_ids   = $this->collect_ids( $this->fixture_tree() );
		$before_types = [
			self::PARA_ID => 'e-paragraph',
			self::SIBLING => 'e-paragraph',
			self::V3_TEXT => 'text-editor',
		];

		$result = ( new UpdateNode() )->execute(
			[
				'post_id'    => self::POST_ID,
				'element_id' => self::PARA_ID,
				'settings'   => [ 'text' => '<p>Rewritten copy.</p>' ],
			]
		);
		self::assertIsArray( $result );

		$tree = $this->stored_tree();
		self::assertSame( $before_ids, $this->collect_ids( $tree ), 'Surgical writes must never regenerate element ids.' );

		foreach ( $before_types as $id => $type ) {
			$node = $this->find_node( $tree, $id );
			self::assertIsArray( $node );
			self::assertSame( $type, $node['widgetType'], "widgetType drift on {$id} is forbidden." );
		}
	}

	public function test_sibling_settings_and_v3_subtree_remain_byte_identical(): void {
		$fixture     = $this->fixture_tree();
		$sibling_before = $this->find_node( $fixture, self::SIBLING );
		$v3_before   = $this->find_node( $fixture, self::V3_TEXT );
		self::assertIsArray( $sibling_before );
		self::assertIsArray( $v3_before );

		$result = ( new UpdateNode() )->execute(
			[
				'post_id'    => self::POST_ID,
				'element_id' => self::PARA_ID,
				'settings'   => [ 'text' => '<p>Only this paragraph changes.</p>' ],
			]
		);
		self::assertIsArray( $result );

		$tree = $this->stored_tree();

		$sibling_after = $this->find_node( $tree, self::SIBLING );
		self::assertIsArray( $sibling_after );
		self::assertSame(
			wp_json_encode( $sibling_before ),
			wp_json_encode( $sibling_after ),
			'Sibling atomic elements must stay byte-identical.'
		);

		$v3_after = $this->find_node( $tree, self::V3_TEXT );
		self::assertIsArray( $v3_after );
		self::assertSame(
			wp_json_encode( $v3_before ),
			wp_json_encode( $v3_after ),
			'A V4-targeted write must not alter the V3 subtree.'
		);

		// Unknown pre-existing settings keys survive untouched.
		$container = $this->find_node( $tree, 'con-v4-atomic' );
		self::assertIsArray( $container );
		self::assertSame( 'sale', $container['settings']['pro_ribbon'] );
	}

	public function test_v3_targeted_write_leaves_atomic_nodes_byte_identical(): void {
		$fixture = $this->fixture_tree();
		$para_before    = $this->find_node( $fixture, self::PARA_ID );
		$sibling_before = $this->find_node( $fixture, self::SIBLING );
		self::assertIsArray( $para_before );
		self::assertIsArray( $sibling_before );

		$result = ( new UpdateElement() )->execute(
			[
				'post_id'    => self::POST_ID,
				'element_id' => self::V3_TEXT,
				'settings'   => [ 'editor' => '<p>Legacy copy updated with <strong>bold</strong>.</p>' ],
			]
		);
		self::assertIsArray( $result );

		$tree = $this->stored_tree();

		$para_after = $this->find_node( $tree, self::PARA_ID );
		self::assertIsArray( $para_after );
		self::assertSame( wp_json_encode( $para_before ), wp_json_encode( $para_after ) );

		$sibling_after = $this->find_node( $tree, self::SIBLING );
		self::assertIsArray( $sibling_after );
		self::assertSame( wp_json_encode( $sibling_before ), wp_json_encode( $sibling_after ) );

		$v3_after = $this->find_node( $tree, self::V3_TEXT );
		self::assertIsArray( $v3_after );
		self::assertSame( '<p>Legacy copy updated with <strong>bold</strong>.</p>', $v3_after['settings']['editor'] );
	}

	public function test_nested_formatting_survives_a_surgical_text_replacement(): void {
		$original = $this->find_node( $this->fixture_tree(), self::PARA_ID );
		self::assertIsArray( $original );
		$original_html = (string) $original['settings']['text'];

		// Fixture self-check: entities decode deterministically (no double
		// encoding) and inline tags are balanced before any write.
		$decoded_once  = html_entity_decode( $original_html, ENT_QUOTES | ENT_HTML5 );
		$decoded_twice = html_entity_decode( $decoded_once, ENT_QUOTES | ENT_HTML5 );
		self::assertSame( $decoded_once, $decoded_twice );

		// Surgical replacement keeps nested markup: new text reuses the same
		// strong/em/a/span structure instead of flattening to plain text.
		$replacement = '<p>Salut <strong>lumii</strong> dragi &amp; <em>nested <strong>accent</strong></em> cu <a href="https://example.test/y"><span class="k">legătură</span></a>.</p>';

		$result = ( new UpdateNode() )->execute(
			[
				'post_id'    => self::POST_ID,
				'element_id' => self::PARA_ID,
				'settings'   => [ 'text' => $replacement ],
			]
		);
		self::assertIsArray( $result );

		$node = $this->find_node( $this->stored_tree(), self::PARA_ID );
		self::assertIsArray( $node );
		self::assertSame( $replacement, $node['settings']['text'] );

		preg_match_all( '/<([a-z]+)[^>]*>/', (string) $node['settings']['text'], $opens );
		preg_match_all( '#</([a-z]+)>#', (string) $node['settings']['text'], $closes );
		sort( $opens[1] );
		sort( $closes[1] );
		self::assertSame( $closes[1], $opens[1], 'Nested inline markup must stay balanced after the write.' );
		self::assertStringContainsString( '<strong>accent</strong>', (string) $node['settings']['text'] );
	}

	public function test_document_roundtrip_is_reopen_stable_and_inspectable(): void {
		$result = ( new UpdateNode() )->execute(
			[
				'post_id'    => self::POST_ID,
				'element_id' => self::PARA_ID,
				'settings'   => [ 'text' => '<p>Roundtrip <code>probe</code>.</p>' ],
			]
		);
		self::assertIsArray( $result );

		$raw        = (string) $GLOBALS['stonewright_test_posts'][ self::POST_ID ]->meta['_elementor_data'];
		$first_pass = json_decode( $raw, true );
		if ( ! is_array( $first_pass ) ) {
			$first_pass = json_decode( stripslashes( $raw ), true );
		}
		self::assertIsArray( $first_pass );

		// Reopen simulation: decode → encode → decode yields identical data.
		$second_pass = json_decode( wp_json_encode( $first_pass ), true );
		self::assertSame( $first_pass, $second_pass );

		// The raw payload must not show double-encoding artifacts: a once
		// encoded quote is \" while double encoding produces \\".
		self::assertStringNotContainsString( '\\\\"', $raw );

		// The dependency-free inspector still classifies the mixed document.
		$stats = AtomicTreeInspector::inspect( $first_pass );
		self::assertIsArray( $stats );
		self::assertSame( 'mixed', $stats['architecture'] ?? '' );
		self::assertSame( 2, (int) ( $stats['atomic_count'] ?? -1 ) );
		self::assertSame( 4, (int) ( $stats['non_atomic_count'] ?? -1 ) );
	}
}
