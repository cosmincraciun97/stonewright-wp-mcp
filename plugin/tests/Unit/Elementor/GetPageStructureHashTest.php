<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Elementor;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\ElementorV3\GetPageStructure;
use Stonewright\WpMcp\Support\ResponseProjection;

/**
 * An agent re-reads a page structure constantly — before a mutation, after it, and
 * again on the next edit in the same session. Most of those reads return a tree
 * that has not moved since the last one, and pay full outline cost to say so.
 *
 * `knownHash` lets the caller assert what it already has. When the assertion holds
 * the ability answers "unchanged" and never builds the outline; when it does not,
 * the read proceeds exactly as before. The hash has to be stable across reads and
 * has to move on any content change, or the short-circuit would serve stale data.
 *
 * @covers \Stonewright\WpMcp\Abilities\ElementorV3\GetPageStructure
 */
final class GetPageStructureHashTest extends TestCase {

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private static function tree(): array {
		return [
			[
				'id'       => 'root',
				'elType'   => 'container',
				'settings' => [ '_title' => 'Hero section' ],
				'elements' => [
					[
						'id'         => 'headline',
						'elType'     => 'widget',
						'widgetType' => 'heading',
						'settings'   => [ 'title' => 'Fast native Elementor' ],
						'elements'   => [],
					],
				],
			],
		];
	}

	/**
	 * @param array<int, array<string, mixed>> $tree
	 */
	private static function seed( array $tree ): void {
		$GLOBALS['stonewright_test_posts'] = [
			811 => (object) [
				'ID'           => 811,
				'post_type'    => 'page',
				'post_status'  => 'draft',
				'post_title'   => 'Hash target',
				'post_content' => '',
				'post_excerpt' => '',
				'meta'         => [
					'_elementor_data'      => wp_json_encode( $tree ),
					'_elementor_edit_mode' => 'builder',
					'_elementor_version'   => defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : '3.0.0',
				],
			],
		];
	}

	protected function setUp(): void {
		self::seed( self::tree() );
		$GLOBALS['stonewright_test_user_caps']      = [
			'edit_post'  => true,
			'edit_posts' => true,
		];
		$GLOBALS['stonewright_test_user_logged_in'] = true;
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_posts']          = [];
		$GLOBALS['stonewright_test_user_caps']      = [];
		$GLOBALS['stonewright_test_user_logged_in'] = false;
	}

	public function test_schema_accepts_a_known_hash(): void {
		$schema = ( new GetPageStructure() )->input_schema();

		self::assertArrayHasKey( 'knownHash', $schema['properties'] );
		self::assertSame( 'string', $schema['properties']['knownHash']['type'] );
		self::assertNotContains( 'knownHash', $schema['required'] );
	}

	public function test_both_response_modes_report_the_same_hash(): void {
		$ability = new GetPageStructure();

		$summary = $ability->execute( [ 'post_id' => 811 ] );
		$full    = $ability->execute(
			[
				'post_id'      => 811,
				'responseMode' => 'full',
			]
		);

		self::assertIsArray( $summary );
		self::assertIsArray( $full );
		self::assertNotSame( '', (string) $summary['hash'] );
		// The hash describes the document, not the shape of the answer, so a
		// caller can compare across modes without re-reading in the same mode.
		self::assertSame( $summary['hash'], $full['hash'] );
	}

	public function test_the_hash_is_stable_across_repeated_reads(): void {
		$ability = new GetPageStructure();

		$first  = $ability->execute( [ 'post_id' => 811 ] );
		$second = $ability->execute( [ 'post_id' => 811 ] );

		self::assertIsArray( $first );
		self::assertIsArray( $second );
		self::assertSame( $first['hash'], $second['hash'] );
	}

	public function test_the_hash_moves_when_the_document_changes(): void {
		$ability = new GetPageStructure();
		$before  = $ability->execute( [ 'post_id' => 811 ] );

		$changed = self::tree();
		$changed[0]['elements'][0]['settings']['title'] = 'Different headline';
		self::seed( $changed );

		$after = $ability->execute( [ 'post_id' => 811 ] );

		self::assertIsArray( $before );
		self::assertIsArray( $after );
		self::assertNotSame( $before['hash'], $after['hash'] );
	}

	public function test_a_matching_known_hash_short_circuits_without_building_an_outline(): void {
		$ability = new GetPageStructure();
		$hash    = (string) ( (array) $ability->execute( [ 'post_id' => 811 ] ) )['hash'];

		$result = $ability->execute(
			[
				'post_id'   => 811,
				'knownHash' => $hash,
			]
		);

		self::assertIsArray( $result );
		self::assertTrue( $result['unchanged'] );
		self::assertSame( $hash, $result['hash'] );
		self::assertSame( 811, $result['post_id'] );

		// The outline is the only product of the summary build. Its absence, and
		// the absence of the counts derived from flattening the tree, is what
		// proves the expensive path was skipped rather than merely hidden.
		self::assertArrayNotHasKey( 'outline', $result );
		self::assertArrayNotHasKey( 'count', $result );
		self::assertArrayNotHasKey( 'returned_count', $result );
		self::assertArrayNotHasKey( 'tree', $result );
	}

	public function test_a_stale_known_hash_returns_the_full_read(): void {
		$result = ( new GetPageStructure() )->execute(
			[
				'post_id'   => 811,
				'knownHash' => 'sha256-of-something-else',
			]
		);

		self::assertIsArray( $result );
		self::assertFalse( $result['unchanged'] );
		self::assertSame( 'summary', $result['response_mode'] );
		self::assertArrayHasKey( 'outline', $result );
		self::assertSame( 2, $result['count'] );
	}

	public function test_a_matching_known_hash_short_circuits_full_mode_too(): void {
		$ability = new GetPageStructure();
		$hash    = (string) ( (array) $ability->execute( [ 'post_id' => 811 ] ) )['hash'];

		$result = $ability->execute(
			[
				'post_id'      => 811,
				'responseMode' => 'full',
				'knownHash'    => $hash,
			]
		);

		self::assertIsArray( $result );
		self::assertTrue( $result['unchanged'] );
		self::assertArrayNotHasKey( 'tree', $result );
	}

	public function test_an_unchanged_response_survives_field_projection(): void {
		$ability = new GetPageStructure();
		$hash    = (string) ( (array) $ability->execute( [ 'post_id' => 811 ] ) )['hash'];

		$result = (array) $ability->execute(
			[
				'post_id'   => 811,
				'knownHash' => $hash,
			]
		);

		// The cheapest possible re-read: assert the hash, then project the answer
		// down to the one field that decides whether to do any more work.
		self::assertSame(
			[ 'unchanged' => true ],
			ResponseProjection::apply( $result, [ 'unchanged' ] )
		);
	}

	public function test_a_missing_post_is_still_an_error_with_a_known_hash(): void {
		$result = ( new GetPageStructure() )->execute(
			[
				'post_id'   => 99999,
				'knownHash' => 'anything',
			]
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_not_found', $result->get_error_code() );
	}
}
