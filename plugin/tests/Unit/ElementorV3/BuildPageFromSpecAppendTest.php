<?php
// SPDX-License-Identifier: GPL-2.0-or-later
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\ElementorV3;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\ElementorV3\BuildPageFromSpec;
use Stonewright\WpMcp\Elementor\Write\PostWriteLock;
use Stonewright\WpMcp\Support\ElementorData;

/**
 * @covers \Stonewright\WpMcp\Abilities\ElementorV3\BuildPageFromSpec
 */
final class BuildPageFromSpecAppendTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_posts'] = [
			778 => (object) [
				'ID'           => 778,
				'post_type'    => 'page',
				'post_status'  => 'draft',
				'post_title'   => 'Append target',
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
		$decoded = json_decode( stripslashes( (string) $GLOBALS['stonewright_test_posts'][778]->meta['_elementor_data'] ), true );
		return is_array( $decoded ) ? $decoded : [];
	}

	/**
	 * @param array<int, array<string, mixed>> $tree
	 * @return list<string>
	 */
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

	public function test_append_on_a_page_that_already_has_a_build_keeps_ids_unique(): void {
		self::assertIsArray( ( new BuildPageFromSpec() )->execute( [ 'post_id' => 778, 'spec' => self::spec( 'alpha', 'First' ) ] ) );
		$first = $this->stored_tree();

		$result = ( new BuildPageFromSpec() )->execute( [ 'post_id' => 778, 'mode' => 'append', 'spec' => self::spec( 'beta', 'Second' ) ] );

		self::assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$tree = $this->stored_tree();
		self::assertCount( 2, $tree );
		self::assertSame( $first[0], $tree[0], 'Existing elements must not change.' );
		self::assertNotSame( $first[0]['id'], $tree[1]['id'] );
		self::assertMatchesRegularExpression( '/^[a-f0-9]{7}$/', $tree[1]['id'] );
		$ids = self::all_ids( $tree );
		self::assertSame( $ids, array_values( array_unique( $ids ) ), 'Every id must be unique across the page.' );
		self::assertSame( 'Second', $tree[1]['elements'][0]['settings']['title'] );
	}

	public function test_a_third_append_stays_unique_and_each_append_is_stable_between_dry_run_and_apply(): void {
		( new BuildPageFromSpec() )->execute( [ 'post_id' => 778, 'spec' => self::spec( 'alpha', 'First' ) ] );
		( new BuildPageFromSpec() )->execute( [ 'post_id' => 778, 'mode' => 'append', 'spec' => self::spec( 'beta', 'Second' ) ] );

		$args = [ 'post_id' => 778, 'mode' => 'append', 'spec' => self::spec( 'gamma', 'Third' ) ];
		$dry  = ( new BuildPageFromSpec() )->execute( $args + [ 'dry_run' => true ] );
		self::assertIsArray( $dry );
		$applied = ( new BuildPageFromSpec() )->execute( $args );
		self::assertIsArray( $applied );

		self::assertSame( $dry['after_hash'], $applied['after_hash'] );
		$ids = self::all_ids( $this->stored_tree() );
		self::assertCount( 3, $this->stored_tree() );
		self::assertSame( $ids, array_values( array_unique( $ids ) ) );
	}

	public function test_replace_section_still_matches_ids_in_place(): void {
		( new BuildPageFromSpec() )->execute( [ 'post_id' => 778, 'spec' => self::spec( 'alpha', 'First' ) ] );
		$first = $this->stored_tree();

		$result = ( new BuildPageFromSpec() )->execute( [ 'post_id' => 778, 'mode' => 'replace_section', 'spec' => self::spec( 'alpha', 'Changed' ) ] );

		self::assertIsArray( $result );
		$tree = $this->stored_tree();
		self::assertCount( 1, $tree );
		self::assertSame( $first[0]['id'], $tree[0]['id'] );
		self::assertSame( 'Changed', $tree[0]['elements'][0]['settings']['title'] );
	}

	public function test_dry_run_reports_the_same_tree_error_as_the_write(): void {
		$GLOBALS['stonewright_test_posts'][778]->meta['_elementor_data'] = (string) wp_json_encode(
			[
				[ 'id' => 'dup0001', 'elType' => 'container', 'settings' => [], 'elements' => [] ],
				[ 'id' => 'dup0001', 'elType' => 'container', 'settings' => [], 'elements' => [] ],
			]
		);
		$args = [ 'post_id' => 778, 'mode' => 'append', 'spec' => self::spec( 'beta', 'Second' ) ];

		$dry = ( new BuildPageFromSpec() )->execute( $args + [ 'dry_run' => true ] );
		self::assertInstanceOf( \WP_Error::class, $dry );
		self::assertSame( 'stonewright_elementor_tree_invalid', $dry->get_error_code() );
		self::assertSame( 'duplicate_id', $dry->get_error_data()['violations'][0]['code'] );
		self::assertSame( [], $GLOBALS['stonewright_test_post_meta_calls'] );

		$applied = ( new BuildPageFromSpec() )->execute( $args );
		self::assertInstanceOf( \WP_Error::class, $applied );
		self::assertSame( $dry->get_error_code(), $applied->get_error_code() );
		self::assertSame( 'duplicate_id', $applied->get_error_data()['violations'][0]['code'] );
		self::assertSame( $dry->get_error_data()['violations'], $applied->get_error_data()['violations'] );
	}

	public function test_busy_lock_returns_the_retryable_busy_error_without_restoring_over_the_other_writer(): void {
		self::assertIsArray( PostWriteLock::acquire( 778, 'other-writer', 30 ) );
		$before = $GLOBALS['stonewright_test_posts'][778]->meta['_elementor_data'];

		$result = ( new BuildPageFromSpec() )->execute( [ 'post_id' => 778, 'spec' => self::spec( 'alpha', 'First' ) ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_elementor_write_busy', $result->get_error_code() );
		self::assertTrue( $result->get_error_data()['retryable'] );
		self::assertSame( $before, $GLOBALS['stonewright_test_posts'][778]->meta['_elementor_data'] );
		foreach ( $GLOBALS['stonewright_test_post_meta_calls'] as $call ) {
			self::assertNotSame( '_elementor_data', $call['meta_key'], 'A refused write must not touch the document.' );
		}
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function spec( string $section_id, string $title ): array {
		return [
			'version'  => '1.0.0',
			'page'     => [ 'title' => 'Append' ],
			'sections' => [
				[
					'id'     => $section_id,
					'blocks' => [
						[ 'type' => 'heading', 'text' => $title, 'level' => 1 ],
					],
				],
			],
		];
	}
}
