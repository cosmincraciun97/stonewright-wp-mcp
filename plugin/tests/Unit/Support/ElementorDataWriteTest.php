<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Support\ElementorData;

/**
 * Regression coverage for the read-back end-state verification in
 * ElementorData::write().
 *
 * Background — update_post_meta() returns `false` BOTH on true failure AND
 * on a successful no-op (the new value equals the existing one). The pre-fix
 * write() required `false !== $mode_result && false !== $version_result`,
 * which exploded on every BuildPageFromSpec into a freshly-created Theme
 * Builder template — TemplateStore::create() sets `_elementor_edit_mode =
 * 'builder'` at creation time, so the write()'s identical re-set returned
 * false → write() returned false → BuildPageFromSpec returned WP_Error
 * `stonewright_write_failed` even though `_elementor_data` was persisted
 * correctly.
 *
 * @covers \Stonewright\WpMcp\Support\ElementorData
 */
final class ElementorDataWriteTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_post_meta_calls']  = [];
		$GLOBALS['stonewright_test_posts'][ 8800 ]    = (object) [
			'ID'           => 8800,
			'post_type'    => 'elementor_library',
			'post_status'  => 'publish',
			'post_title'   => 'fixture template',
			'post_content' => '',
			'post_excerpt' => '',
			'post_parent'  => 0,
			'post_name'    => 'fixture',
			'meta'         => [
				// Simulating the post that TemplateStore::create() just set up:
				// edit_mode already 'builder', empty data, no version yet.
				'_elementor_data'      => '[]',
				'_elementor_edit_mode' => 'builder',
			],
		];
	}

	public function test_write_succeeds_when_edit_mode_is_already_builder(): void {
		$tree = [
			[
				'id'         => 'abc1234',
				'elType'     => 'section',
				'settings'   => [],
				'elements'   => [],
			],
		];

		$result = ElementorData::write( 8800, $tree );

		$this->assertTrue(
			$result,
			"write() must succeed even when _elementor_edit_mode is unchanged. "
			. "update_post_meta() returning false for a no-op no longer poisons the overall result."
		);

		// And the data must actually be persisted to the fake post.
		$post = $GLOBALS['stonewright_test_posts'][ 8800 ];
		$this->assertArrayHasKey( '_elementor_data', $post->meta );
	}

	public function test_write_persists_data_into_post_meta(): void {
		$tree = [
			[
				'id'       => 'def5678',
				'elType'   => 'section',
				'settings' => [],
				'elements' => [],
			],
		];

		$this->assertTrue( ElementorData::write( 8800, $tree ) );

		// Confirm the slashed JSON landed in the post's meta. The test stub
		// of update_post_meta persists addslashed strings as-is (real WP
		// auto-unslashes on get_post_meta read; the stub does not — so a
		// round-trip ElementorData::read() comparison would be testing the
		// stub more than the production code; covered by the smoke-test on
		// mcp-test.local instead).
		$post = $GLOBALS['stonewright_test_posts'][ 8800 ];
		$this->assertArrayHasKey( '_elementor_data', $post->meta );
		$this->assertStringContainsString( 'def5678', stripslashes( (string) $post->meta['_elementor_data'] ) );
	}

	public function test_write_returns_false_when_json_encoding_fails(): void {
		// Resource handles are unencodable by json_encode → wp_json_encode returns false.
		$tree = [ [ 'id' => 'x', 'res' => fopen( 'php://memory', 'r' ) ] ];
		$result = ElementorData::write( 8800, $tree );
		// PHP 8.4 + JSON_INVALID_UTF8_IGNORE may swap behaviour; either way,
		// when wp_json_encode returns false write() must return false.
		// (If json_encode happens to succeed on the resource — it won't, but
		// hedging — read-back equality will still gate the success path.)
		$this->assertFalse( $result );
	}

	public function test_write_persists_elementor_version_to_meta(): void {
		ElementorData::write( 8800, [] );
		$post = $GLOBALS['stonewright_test_posts'][ 8800 ];
		$this->assertArrayHasKey( '_elementor_version', $post->meta );
		$this->assertNotSame( '', (string) $post->meta['_elementor_version'] );
	}
}
