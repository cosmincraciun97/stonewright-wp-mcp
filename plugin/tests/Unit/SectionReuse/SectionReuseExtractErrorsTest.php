<?php
/**
 * stonewright/section-reuse-extract: refusals that tell the agent what to do next.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\SectionReuse;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\SectionReuse\SectionReuseExtract;
use Stonewright\WpMcp\SectionReuse\ReferenceCatalog;
use Stonewright\WpMcp\Security\IncidentStore;

require_once __DIR__ . '/SectionFixtures.php';

/**
 * @covers \Stonewright\WpMcp\Abilities\SectionReuse\SectionReuseExtract
 * @covers \Stonewright\WpMcp\SectionReuse\SourceLocators
 */
final class SectionReuseExtractErrorsTest extends TestCase {

	protected function setUp(): void {
		IncidentStore::reset_for_tests();
		ReferenceCatalog::set_provider( static fn( string $type, string $id ): ?bool => true );
		$GLOBALS['stonewright_test_options']           = [ 'stonewright_mode' => 'development' ];
		$GLOBALS['stonewright_test_posts']             = [];
		$GLOBALS['stonewright_test_post_meta_calls']   = [];
		$GLOBALS['stonewright_test_user_caps']         = [ 'edit_posts' => true, 'read' => true ];
		$GLOBALS['stonewright_test_user_can_callback'] = static fn( string $cap, mixed ...$args ): bool => in_array( $cap, [ 'edit_posts', 'read_post', 'edit_post' ], true );
		$GLOBALS['stonewright_test_user_logged_in']    = true;
		$GLOBALS['stonewright_test_current_user_id']   = 7;
	}

	protected function tearDown(): void {
		ReferenceCatalog::set_provider( null );
		IncidentStore::reset_for_tests();
		$GLOBALS['stonewright_test_options']           = [];
		$GLOBALS['stonewright_test_posts']             = [];
		$GLOBALS['stonewright_test_user_caps']         = [];
		$GLOBALS['stonewright_test_user_can_callback'] = null;
		$GLOBALS['stonewright_test_user_logged_in']    = false;
	}

	/** @param list<array<string, mixed>> $tree */
	private static function elementor_post( int $id, array $tree, string $type = 'page', string $status = 'publish' ): void {
		$GLOBALS['stonewright_test_posts'][ $id ] = SectionFixtures::post( $id, $type, $status, 'Source ' . $id, '', SectionFixtures::elementor_meta( $tree ) );
	}

	/** A container with one widget: a section with no nested container. @return array<string, mixed> */
	private static function plain_section( string $id, string $title ): array {
		return [
			'id'       => $id,
			'elType'   => 'container',
			'isInner'  => false,
			'settings' => [],
			'elements' => [ [ 'id' => $id . 'w', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => [ 'title' => $title ], 'elements' => [] ] ],
		];
	}

	private static function extract( int $post_id, array $locator ): \WP_Error {
		$result = ( new SectionReuseExtract() )->execute( [ 'post_id' => $post_id, 'locator' => $locator ] );
		self::assertInstanceOf( \WP_Error::class, $result );

		return $result;
	}

	public function test_a_post_that_does_not_exist_gets_the_generic_answer_with_the_offered_values(): void {
		$error = self::extract( 999, [ 'kind' => 'element', 'id' => 'x' ] );
		$data  = (array) $error->get_error_data();

		self::assertSame( 'stonewright_not_found', $error->get_error_code() );
		self::assertSame( 'not_found_or_not_permitted', $data['reason'] ?? null );
		self::assertSame( [ 'publish', 'draft', 'pending', 'future', 'private' ], $data['offered_statuses'] ?? null );
		self::assertStringContainsString( 'does not exist', $error->get_error_message() );
	}

	public function test_a_private_post_the_user_may_not_edit_is_not_told_apart_from_a_missing_one(): void {
		self::elementor_post( 30, [ self::plain_section( 'a1', 'Secret title' ) ], 'page', 'private' );
		$GLOBALS['stonewright_test_user_can_callback'] = static fn( string $cap, mixed ...$args ): bool => 'edit_posts' === $cap;

		$hidden  = self::extract( 30, [ 'kind' => 'element', 'id' => 'a1' ] );
		$missing = self::extract( 31, [ 'kind' => 'element', 'id' => 'a1' ] );

		self::assertSame( $missing->get_error_code(), $hidden->get_error_code() );
		self::assertSame( $missing->get_error_message(), $hidden->get_error_message() );
		self::assertSame( $missing->get_error_data(), $hidden->get_error_data() );
		$json = (string) wp_json_encode( [ $hidden->get_error_message(), $hidden->get_error_data() ] );
		self::assertStringNotContainsString( 'Secret title', $json );
		self::assertArrayNotHasKey( 'post_status', (array) $hidden->get_error_data() );
		self::assertArrayNotHasKey( 'post_type', (array) $hidden->get_error_data() );
	}

	public function test_a_trashed_post_the_user_may_edit_is_named_with_its_status(): void {
		self::elementor_post( 32, [ self::plain_section( 'a1', 'Title' ) ], 'page', 'trash' );

		$error = self::extract( 32, [ 'kind' => 'element', 'id' => 'a1' ] );
		$data  = (array) $error->get_error_data();

		self::assertSame( 'stonewright_not_found', $error->get_error_code() );
		self::assertSame( 'status_not_offered', $data['reason'] ?? null );
		self::assertSame( 'trash', $data['post_status'] ?? null );
		self::assertSame( 'page', $data['post_type'] ?? null );
		self::assertStringContainsString( 'trash', $error->get_error_message() );
		self::assertStringContainsString( 'publish, draft, pending, future, private', $error->get_error_message() );
		self::assertStringContainsString( 'stonewright-section-reuse-find', $error->get_error_message() );
	}

	public function test_a_post_type_that_is_never_a_source_is_named(): void {
		$GLOBALS['stonewright_test_posts'][ 33 ] = SectionFixtures::post( 33, 'attachment', 'publish', 'A file' );

		$error = self::extract( 33, [ 'kind' => 'element', 'id' => 'a1' ] );
		$data  = (array) $error->get_error_data();

		self::assertSame( 'stonewright_not_found', $error->get_error_code() );
		self::assertSame( 'post_type_not_offered', $data['reason'] ?? null );
		self::assertSame( 'attachment', $data['post_type'] ?? null );
		self::assertStringContainsString( 'attachment', $error->get_error_message() );
	}

	public function test_a_wrong_locator_returns_every_valid_locator_of_the_source(): void {
		self::elementor_post( 40, [ self::plain_section( 'a1', 'Hero words' ), self::plain_section( 'b2', 'Pricing' ), self::plain_section( 'c3', 'Contact' ) ] );

		$error = self::extract( 40, [ 'kind' => 'element', 'id' => 'zz9' ] );
		$data  = (array) $error->get_error_data();

		self::assertSame( 'stonewright_section_not_found', $error->get_error_code() );
		self::assertSame( 3, $data['locators_total'] ?? null );
		self::assertFalse( $data['locators_truncated'] ?? null );
		self::assertSame(
			[ [ 'kind' => 'element', 'id' => 'a1' ], [ 'kind' => 'element', 'id' => 'b2' ], [ 'kind' => 'element', 'id' => 'c3' ] ],
			array_column( (array) ( $data['valid_locators'] ?? [] ), 'locator' )
		);
		self::assertSame( 'Pricing', $data['valid_locators'][1]['heading'] ?? null );
		self::assertSame( 'elementor-v3', $data['valid_locators'][1]['builder'] ?? null );
		self::assertStringContainsString( '3 sections', $error->get_error_message() );
		self::assertStringContainsString( 'valid_locators', $error->get_error_message() );
		self::assertStringContainsString( 'a1', $error->get_error_message() );
	}

	public function test_the_list_of_valid_locators_is_bounded(): void {
		$tree = [];
		for ( $i = 1; $i <= 25; $i++ ) {
			$tree[] = self::plain_section( 's' . $i, 'Section ' . $i );
		}
		self::elementor_post( 41, $tree );

		$error = self::extract( 41, [ 'kind' => 'element', 'id' => 'nope' ] );
		$data  = (array) $error->get_error_data();

		self::assertCount( 20, $data['valid_locators'] ?? [] );
		self::assertSame( 25, $data['locators_total'] ?? null );
		self::assertTrue( $data['locators_truncated'] ?? null );
		self::assertStringContainsString( '25 sections', $error->get_error_message() );
	}

	public function test_nested_containers_come_after_the_top_level_sections(): void {
		self::elementor_post( 42, [ SectionFixtures::v3_features(), self::plain_section( 'p1', 'Plain' ) ] );

		$error   = self::extract( 42, [ 'kind' => 'element', 'id' => 'nope' ] );
		$locator = array_column( (array) ( $error->get_error_data()['valid_locators'] ?? [] ), 'locator' );

		self::assertSame( [ 'kind' => 'element', 'id' => 'f000001' ], $locator[0] );
		self::assertSame( [ 'kind' => 'element', 'id' => 'p1' ], $locator[1] );
		self::assertContains( [ 'kind' => 'element', 'id' => 'f000003' ], $locator );
	}

	public function test_a_near_miss_of_an_id_is_pointed_at(): void {
		self::elementor_post( 43, [ self::plain_section( 'abc123', 'One' ), self::plain_section( 'def456', 'Two' ) ] );

		$error = self::extract( 43, [ 'kind' => 'element', 'id' => ' ABC123 ' ] );
		$data  = (array) $error->get_error_data();

		self::assertSame( [ 'kind' => 'element', 'id' => 'abc123' ], $data['closest']['locator'] ?? null );
		self::assertStringContainsString( 'abc123', $error->get_error_message() );
	}

	public function test_a_gutenberg_source_lists_block_locators(): void {
		$GLOBALS['stonewright_test_posts'][ 44 ] = SectionFixtures::post( 44, 'page', 'publish', 'Blocks', SectionFixtures::gutenberg_features_content() );

		$error = self::extract( 44, [ 'kind' => 'block', 'path' => [ 9 ] ] );
		$data  = (array) $error->get_error_data();

		self::assertSame( 'stonewright_section_not_found', $error->get_error_code() );
		self::assertNotEmpty( $data['valid_locators'] ?? [] );
		self::assertSame( 'block', $data['valid_locators'][0]['locator']['kind'] ?? null );
		self::assertSame( [ 0 ], $data['valid_locators'][0]['locator']['path'] ?? null );
		self::assertSame( 'gutenberg', $data['valid_locators'][0]['builder'] ?? null );
	}
}
