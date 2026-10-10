<?php
/**
 * The diff between two images that are not the stored ones of a row: what an undo would change.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Support\Diff;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Support\Diff\ChangeDiff;

/**
 * @covers \Stonewright\WpMcp\Support\Diff\ChangeDiff::for_images
 */
final class ChangeDiffImagesTest extends TestCase {

	public function test_two_texts_are_compared_from_the_left_to_the_right(): void {
		$diff = ChangeDiff::for_images( [ 'family' => 'theme_file', 'resource_id' => 'site-a/style.css' ], ".a { color: blue; }\n", ".a { color: red; }\n" );

		self::assertSame( 'ok', $diff['status'] );
		self::assertTrue( $diff['changed'] );
		self::assertSame( 'site-a/style.css', $diff['sections'][0]['title'] );
		$shown = (string) wp_json_encode( $diff['sections'][0]['result'] );
		self::assertStringContainsString( 'color: blue', $shown );
		self::assertStringContainsString( 'color: red', $shown );
	}

	public function test_two_arrays_are_compared_as_fields(): void {
		$diff = ChangeDiff::for_images( [ 'family' => 'option' ], [ 'value' => 'Live' ], [ 'value' => 'Before' ] );

		self::assertSame( 'ok', $diff['status'] );
		self::assertTrue( $diff['changed'] );
		self::assertSame( 'fields', $diff['sections'][0]['id'] );
	}

	public function test_a_missing_side_reads_as_empty_so_a_removal_shows_everything_as_removed(): void {
		$diff = ChangeDiff::for_images( [ 'family' => 'post' ], [ 'post_content' => 'Gone soon' ], null );

		self::assertSame( 'ok', $diff['status'] );
		self::assertTrue( $diff['changed'] );
		self::assertStringContainsString( 'Gone soon', (string) wp_json_encode( $diff ) );
	}

	public function test_two_identical_images_are_not_a_change(): void {
		$diff = ChangeDiff::for_images( [ 'family' => 'option' ], [ 'value' => 'Same' ], [ 'value' => 'Same' ] );

		self::assertFalse( $diff['changed'] );
	}

	public function test_nothing_on_either_side_says_there_is_nothing_to_compare(): void {
		$diff = ChangeDiff::for_images( [ 'family' => 'post' ], null, null );

		self::assertSame( 'no_images', $diff['status'] );
		self::assertSame( [], $diff['sections'] );
	}

	public function test_the_masked_flag_of_the_row_is_carried_over(): void {
		$diff = ChangeDiff::for_images( [ 'family' => 'post', 'restorable_reason' => 'masked_secret' ], [ 'a' => '1' ], [ 'a' => '2' ] );

		self::assertTrue( $diff['image_masked'] );
	}
}
