<?php
// SPDX-License-Identifier: GPL-2.0-or-later
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\ElementorV3;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\ElementorV3\BuildPageFromSpec;
use Stonewright\WpMcp\Elementor\Renderer\Section;
use Stonewright\WpMcp\Support\ElementorData;

/**
 * `mode: "replace_section"` replaces the container built from the spec section with the same section id.
 *
 * @covers \Stonewright\WpMcp\Abilities\ElementorV3\BuildPageFromSpec
 */
final class BuildPageFromSpecReplaceSectionTest extends TestCase {

	private const POST = 779;

	protected function setUp(): void {
		$GLOBALS['stonewright_test_posts'] = [
			self::POST => (object) [
				'ID'           => self::POST,
				'post_type'    => 'page',
				'post_status'  => 'draft',
				'post_title'   => 'Replace section target',
				'post_content' => '',
				'post_excerpt' => '',
				'meta'         => [
					'_elementor_data'      => '[]',
					'_elementor_edit_mode' => 'builder',
					'_elementor_version'   => defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : '3.0.0',
				],
			],
		];
		$GLOBALS['stonewright_test_post_meta_calls'] = [];
		$GLOBALS['stonewright_test_options']         = [ 'stonewright_mode' => 'development' ];
		$GLOBALS['stonewright_test_current_user_id'] = 42;
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_posts']           = [];
		$GLOBALS['stonewright_test_post_meta_calls'] = [];
		$GLOBALS['stonewright_test_options']         = [];
		$GLOBALS['stonewright_test_current_user_id'] = 0;
	}

	/** @return array<int, array<string, mixed>> */
	private function stored_tree(): array {
		$decoded = json_decode( stripslashes( (string) $GLOBALS['stonewright_test_posts'][ self::POST ]->meta['_elementor_data'] ), true );
		return is_array( $decoded ) ? $decoded : [];
	}

	/**
	 * @param array<string, string> $sections Section id => heading text, in page order.
	 * @return array<string, mixed>
	 */
	private static function spec( array $sections ): array {
		$out = [];
		foreach ( $sections as $id => $title ) {
			$out[] = [
				'id'     => (string) $id,
				'blocks' => [ [ 'type' => 'heading', 'text' => $title, 'level' => 2 ] ],
			];
		}
		return [ 'version' => '1.0.0', 'page' => [ 'title' => 'Page' ], 'sections' => $out ];
	}

	/** @param array<string, mixed> $args @return array<string, mixed>|\WP_Error */
	private static function build( array $args ): array|\WP_Error {
		return ( new BuildPageFromSpec() )->execute( [ 'post_id' => self::POST ] + $args );
	}

	/** @return list<string> */
	private function titles(): array {
		return array_map( static fn( array $container ): string => (string) ( $container['elements'][0]['settings']['title'] ?? '' ), $this->stored_tree() );
	}

	/** @return list<string> */
	private static function all_ids( array $tree ): array {
		$ids = [];
		ElementorData::walk(
			$tree,
			static function ( array $element ) use ( &$ids ): void {
				$ids[] = (string) $element['id'];
			}
		);
		return $ids;
	}

	private function assert_unchanged_since( string $stored_before ): void {
		self::assertSame( $stored_before, $GLOBALS['stonewright_test_posts'][ self::POST ]->meta['_elementor_data'], 'The document must not change.' );
	}

	public function test_replace_section_replaces_the_named_section_and_keeps_the_others(): void {
		self::assertIsArray( self::build( [ 'spec' => self::spec( [ 'alpha' => 'Alpha One' ] ) ] ) );
		self::assertIsArray( self::build( [ 'mode' => 'append', 'spec' => self::spec( [ 'beta' => 'Beta Two' ] ) ] ) );
		$before = $this->stored_tree();

		$result = self::build( [ 'mode' => 'replace_section', 'spec' => self::spec( [ 'beta' => 'Beta Replaced' ] ) ] );

		self::assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$tree = $this->stored_tree();
		self::assertCount( 2, $tree );
		self::assertSame( $before[0], $tree[0], 'The alpha container must not change at all.' );
		self::assertSame( $before[1]['id'], $tree[1]['id'], 'The replaced container keeps its id.' );
		self::assertSame( [ 'Alpha One', 'Beta Replaced' ], $this->titles() );
		$ids = self::all_ids( $tree );
		self::assertSame( $ids, array_values( array_unique( $ids ) ), 'Every id on the page stays unique.' );
	}

	public function test_replace_section_targets_the_right_section_among_three(): void {
		self::assertIsArray( self::build( [ 'spec' => self::spec( [ 'alpha' => 'Alpha', 'beta' => 'Beta', 'gamma' => 'Gamma' ] ) ] ) );
		$before = $this->stored_tree();

		$expected = [ 'Alpha', 'Beta', 'Gamma' ];
		foreach ( [ 'alpha', 'beta', 'gamma' ] as $position => $section ) {
			self::assertIsArray( self::build( [ 'mode' => 'replace_section', 'spec' => self::spec( [ $section => 'New ' . $section ] ) ] ) );
			$expected[ $position ] = 'New ' . $section;
			self::assertSame( $expected, $this->titles(), 'Only section ' . $section . ' changes.' );
		}

		self::assertSame( [ 'New alpha', 'New beta', 'New gamma' ], $this->titles() );
		self::assertSame( array_column( $before, 'id' ), array_column( $this->stored_tree(), 'id' ), 'Top-level ids never change.' );
		$ids = self::all_ids( $this->stored_tree() );
		self::assertSame( $ids, array_values( array_unique( $ids ) ) );
	}

	public function test_one_call_may_replace_several_sections_and_leaves_the_rest(): void {
		self::assertIsArray( self::build( [ 'spec' => self::spec( [ 'alpha' => 'Alpha', 'beta' => 'Beta', 'gamma' => 'Gamma' ] ) ] ) );

		// The spec lists them in another order than the page; each is matched by its id.
		$result = self::build( [ 'mode' => 'replace_section', 'spec' => self::spec( [ 'gamma' => 'Gamma 2', 'alpha' => 'Alpha 2' ] ) ] );

		self::assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		self::assertSame( [ 'Alpha 2', 'Beta', 'Gamma 2' ], $this->titles() );
		$ids = self::all_ids( $this->stored_tree() );
		self::assertSame( $ids, array_values( array_unique( $ids ) ) );
	}

	public function test_a_section_that_is_not_on_the_page_is_refused_and_nothing_is_written(): void {
		self::assertIsArray( self::build( [ 'spec' => self::spec( [ 'alpha' => 'Alpha', 'beta' => 'Beta' ] ) ] ) );
		$stored = (string) $GLOBALS['stonewright_test_posts'][ self::POST ]->meta['_elementor_data'];
		$GLOBALS['stonewright_test_post_meta_calls'] = [];

		$result = self::build( [ 'mode' => 'replace_section', 'spec' => self::spec( [ 'delta' => 'Delta' ] ) ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_replace_section_target_missing', $result->get_error_code() );
		self::assertSame( 'delta', $result->get_error_data()['section_id'] );
		self::assertSame( [], $GLOBALS['stonewright_test_post_meta_calls'], 'No snapshot and no write.' );
		$this->assert_unchanged_since( $stored );
	}

	public function test_one_missing_section_stops_the_whole_call(): void {
		self::assertIsArray( self::build( [ 'spec' => self::spec( [ 'alpha' => 'Alpha', 'beta' => 'Beta' ] ) ] ) );
		$stored = (string) $GLOBALS['stonewright_test_posts'][ self::POST ]->meta['_elementor_data'];

		$result = self::build( [ 'mode' => 'replace_section', 'spec' => self::spec( [ 'alpha' => 'Alpha 2', 'delta' => 'Delta' ] ) ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_replace_section_target_missing', $result->get_error_code() );
		$this->assert_unchanged_since( $stored );
	}

	public function test_a_container_the_editor_removed_is_missing(): void {
		self::assertIsArray( self::build( [ 'spec' => self::spec( [ 'alpha' => 'Alpha', 'beta' => 'Beta' ] ) ] ) );
		$tree = $this->stored_tree();
		unset( $tree[1] );
		$GLOBALS['stonewright_test_posts'][ self::POST ]->meta['_elementor_data'] = (string) wp_json_encode( array_values( $tree ) );

		$result = self::build( [ 'mode' => 'replace_section', 'spec' => self::spec( [ 'beta' => 'Beta 2' ] ) ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_replace_section_target_missing', $result->get_error_code() );
	}

	public function test_a_section_built_twice_is_ambiguous_and_refused(): void {
		self::assertIsArray( self::build( [ 'spec' => self::spec( [ 'alpha' => 'Alpha' ] ) ] ) );
		self::assertIsArray( self::build( [ 'mode' => 'append', 'spec' => self::spec( [ 'beta' => 'Beta one' ] ) ] ) );
		self::assertIsArray( self::build( [ 'mode' => 'append', 'spec' => self::spec( [ 'beta' => 'Beta two' ] ) ] ) );
		$stored = (string) $GLOBALS['stonewright_test_posts'][ self::POST ]->meta['_elementor_data'];

		$result = self::build( [ 'mode' => 'replace_section', 'spec' => self::spec( [ 'beta' => 'Beta new' ] ) ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_replace_section_target_ambiguous', $result->get_error_code() );
		self::assertSame( 'beta', $result->get_error_data()['section_id'] );
		self::assertCount( 2, $result->get_error_data()['element_ids'] );
		$this->assert_unchanged_since( $stored );

		// The sibling that was built once is still addressable.
		$other = self::build( [ 'mode' => 'replace_section', 'spec' => self::spec( [ 'alpha' => 'Alpha new' ] ) ] );
		self::assertIsArray( $other, is_wp_error( $other ) ? $other->get_error_message() : '' );
		self::assertSame( [ 'Alpha new', 'Beta one', 'Beta two' ], $this->titles() );
	}

	public function test_a_spec_that_repeats_a_section_id_is_refused(): void {
		self::assertIsArray( self::build( [ 'spec' => self::spec( [ 'alpha' => 'Alpha' ] ) ] ) );
		$spec                = self::spec( [ 'alpha' => 'One' ] );
		$spec['sections'][]  = $spec['sections'][0];
		$stored              = (string) $GLOBALS['stonewright_test_posts'][ self::POST ]->meta['_elementor_data'];

		$result = self::build( [ 'mode' => 'replace_section', 'spec' => $spec ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_replace_section_target_ambiguous', $result->get_error_code() );
		$this->assert_unchanged_since( $stored );
	}

	public function test_a_section_without_an_explicit_id_is_refused(): void {
		self::assertIsArray( self::build( [ 'spec' => self::spec( [ 'alpha' => 'Alpha' ] ) ] ) );
		$spec = [ 'version' => '1.0.0', 'page' => [ 'title' => 'Page' ], 'sections' => [ [ 'blocks' => [ [ 'type' => 'heading', 'text' => 'No id' ] ] ] ] ];
		$stored = (string) $GLOBALS['stonewright_test_posts'][ self::POST ]->meta['_elementor_data'];

		$result = self::build( [ 'mode' => 'replace_section', 'spec' => $spec ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_replace_section_id_required', $result->get_error_code() );
		$this->assert_unchanged_since( $stored );
	}

	public function test_a_page_built_before_sections_were_recorded_is_refused_even_when_an_id_matches_by_position(): void {
		// A container carrying the id the renderer derives for the first section of any spec, with no record behind it.
		$legacy = [
			[ 'id' => Section::stable_id( 's0' ), 'elType' => 'container', 'settings' => [], 'elements' => [ [ 'id' => 'old0001', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => [ 'title' => 'Old first' ], 'elements' => [] ] ] ],
			[ 'id' => Section::stable_id( 's1' ), 'elType' => 'container', 'settings' => [], 'elements' => [] ],
		];
		$GLOBALS['stonewright_test_posts'][ self::POST ]->meta['_elementor_data'] = (string) wp_json_encode( $legacy );
		$stored = (string) $GLOBALS['stonewright_test_posts'][ self::POST ]->meta['_elementor_data'];

		$result = self::build( [ 'mode' => 'replace_section', 'spec' => self::spec( [ 'beta' => 'Beta' ] ) ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_replace_section_unrecorded', $result->get_error_code() );
		self::assertSame( 409, $result->get_error_data()['status'] );
		self::assertStringContainsString( 'replace', $result->get_error_data()['repair'] );
		$this->assert_unchanged_since( $stored );
	}

	public function test_a_page_built_by_this_version_can_be_rebuilt_over_a_legacy_page(): void {
		$GLOBALS['stonewright_test_posts'][ self::POST ]->meta['_elementor_data'] = (string) wp_json_encode(
			[ [ 'id' => Section::stable_id( 's0' ), 'elType' => 'container', 'settings' => [], 'elements' => [] ] ]
		);

		self::assertIsArray( self::build( [ 'mode' => 'replace', 'spec' => self::spec( [ 'alpha' => 'Alpha', 'beta' => 'Beta' ] ) ] ) );
		$result = self::build( [ 'mode' => 'replace_section', 'spec' => self::spec( [ 'beta' => 'Beta 2' ] ) ] );

		self::assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		self::assertSame( [ 'Alpha', 'Beta 2' ], $this->titles() );
	}

	public function test_a_dry_run_gives_the_answer_the_apply_gives(): void {
		self::assertIsArray( self::build( [ 'spec' => self::spec( [ 'alpha' => 'Alpha', 'beta' => 'Beta' ] ) ] ) );
		$stored = (string) $GLOBALS['stonewright_test_posts'][ self::POST ]->meta['_elementor_data'];
		$args   = [ 'mode' => 'replace_section', 'spec' => self::spec( [ 'beta' => 'Beta 2' ] ) ];

		$dry = self::build( $args + [ 'dry_run' => true ] );
		self::assertIsArray( $dry, is_wp_error( $dry ) ? $dry->get_error_message() : '' );
		self::assertSame( [ 'Alpha', 'Beta 2' ], array_map( static fn( array $c ): string => (string) $c['elements'][0]['settings']['title'], $dry['preview'] ) );
		$this->assert_unchanged_since( $stored );

		$applied = self::build( $args );
		self::assertIsArray( $applied );
		self::assertSame( $dry['after_hash'], $applied['after_hash'] );
		self::assertSame( $dry['elements'], $applied['elements'] );

		foreach ( [ 'delta' => 'stonewright_replace_section_target_missing', 'x' => 'stonewright_replace_section_target_missing' ] as $section => $code ) {
			$args = [ 'mode' => 'replace_section', 'spec' => self::spec( [ $section => 'Nope' ] ) ];
			$dry  = self::build( $args + [ 'dry_run' => true ] );
			$real = self::build( $args );
			self::assertInstanceOf( \WP_Error::class, $dry );
			self::assertInstanceOf( \WP_Error::class, $real );
			self::assertSame( $code, $dry->get_error_code() );
			self::assertSame( $dry->get_error_code(), $real->get_error_code() );
			self::assertSame( $dry->get_error_message(), $real->get_error_message() );
		}

		$GLOBALS['stonewright_test_posts'][ self::POST ]->meta['_elementor_data'] = '[]';
		unset( $GLOBALS['stonewright_test_posts'][ self::POST ]->meta['_stonewright_spec_sections'] );
		$legacy_dry  = self::build( [ 'mode' => 'replace_section', 'dry_run' => true, 'spec' => self::spec( [ 'beta' => 'Beta' ] ) ] );
		$legacy_real = self::build( [ 'mode' => 'replace_section', 'spec' => self::spec( [ 'beta' => 'Beta' ] ) ] );
		self::assertSame( 'stonewright_replace_section_unrecorded', $legacy_dry->get_error_code() );
		self::assertSame( $legacy_dry->get_error_code(), $legacy_real->get_error_code() );
	}

	public function test_a_dry_run_records_nothing(): void {
		self::assertIsArray( self::build( [ 'dry_run' => true, 'spec' => self::spec( [ 'alpha' => 'Alpha' ] ) ] ) );
		self::assertSame( [], $GLOBALS['stonewright_test_post_meta_calls'] );

		$unrecorded = self::build( [ 'mode' => 'replace_section', 'spec' => self::spec( [ 'alpha' => 'Alpha' ] ) ] );
		self::assertSame( 'stonewright_replace_section_unrecorded', $unrecorded->get_error_code() );
	}

	public function test_replace_forgets_the_sections_of_the_previous_build(): void {
		self::assertIsArray( self::build( [ 'spec' => self::spec( [ 'alpha' => 'Alpha', 'beta' => 'Beta' ] ) ] ) );
		self::assertIsArray( self::build( [ 'mode' => 'replace', 'spec' => self::spec( [ 'gamma' => 'Gamma' ] ) ] ) );

		$result = self::build( [ 'mode' => 'replace_section', 'spec' => self::spec( [ 'beta' => 'Beta 2' ] ) ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_replace_section_target_missing', $result->get_error_code() );
		self::assertSame( [ 'Gamma' ], $this->titles() );
	}

	public function test_replacement_children_never_reuse_an_id_held_elsewhere_on_the_page(): void {
		// Both specs put their heading at the same position, so the renderer derives the same child id for each.
		self::assertIsArray( self::build( [ 'spec' => self::spec( [ 'alpha' => 'Alpha', 'beta' => 'Beta' ] ) ] ) );

		self::assertIsArray( self::build( [ 'mode' => 'replace_section', 'spec' => self::spec( [ 'beta' => 'Beta 2' ] ) ] ) );

		$ids = self::all_ids( $this->stored_tree() );
		self::assertSame( $ids, array_values( array_unique( $ids ) ) );
		self::assertCount( 4, $ids );
	}
}
