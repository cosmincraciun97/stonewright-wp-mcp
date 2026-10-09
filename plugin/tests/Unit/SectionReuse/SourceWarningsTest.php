<?php
/**
 * A password-protected or draft source gets a warning at find and at extract, because its text could land on a public page.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\SectionReuse;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\SectionReuse\SectionReuseExtract;
use Stonewright\WpMcp\Abilities\SectionReuse\SectionReuseFind;
use Stonewright\WpMcp\SectionReuse\ReferenceCatalog;
use Stonewright\WpMcp\SectionReuse\SignatureCache;
use Stonewright\WpMcp\Security\IncidentStore;

require_once __DIR__ . '/SectionFixtures.php';

/**
 * @covers \Stonewright\WpMcp\SectionReuse\SourceWarnings
 * @covers \Stonewright\WpMcp\Abilities\SectionReuse\SectionReuseFind
 * @covers \Stonewright\WpMcp\Abilities\SectionReuse\SectionReuseExtract
 */
final class SourceWarningsTest extends TestCase {

	protected function setUp(): void {
		IncidentStore::reset_for_tests();
		SignatureCache::reset_for_tests();
		ReferenceCatalog::set_provider( static fn( string $type, string $id ): ?bool => true );
		$GLOBALS['stonewright_test_options']           = [ 'stonewright_mode' => 'development' ];
		$GLOBALS['stonewright_test_posts']             = [];
		$GLOBALS['stonewright_test_post_meta_calls']   = [];
		$GLOBALS['stonewright_test_wpdb_inserts']      = [];
		$GLOBALS['stonewright_test_post_types']        = [
			'post'              => (object) [ 'name' => 'post', 'public' => true ],
			'page'              => (object) [ 'name' => 'page', 'public' => true ],
			'elementor_library' => (object) [ 'name' => 'elementor_library', 'public' => false ],
			'wp_block'          => (object) [ 'name' => 'wp_block', 'public' => false ],
		];
		$GLOBALS['stonewright_test_user_caps']         = [ 'edit_posts' => true, 'read' => true ];
		$GLOBALS['stonewright_test_user_can_callback'] = static fn( string $cap, mixed ...$args ): bool => in_array( $cap, [ 'edit_posts', 'read_post', 'edit_post' ], true );
		$GLOBALS['stonewright_test_user_logged_in']    = true;
		$GLOBALS['stonewright_test_current_user_id']   = 7;
	}

	protected function tearDown(): void {
		ReferenceCatalog::set_provider( null );
		SignatureCache::reset_for_tests();
		IncidentStore::reset_for_tests();
		$GLOBALS['stonewright_test_options']           = [];
		$GLOBALS['stonewright_test_posts']             = [];
		$GLOBALS['stonewright_test_post_types']        = [];
		$GLOBALS['stonewright_test_user_caps']         = [];
		$GLOBALS['stonewright_test_user_can_callback'] = null;
		$GLOBALS['stonewright_test_user_logged_in']    = false;
	}

	private static function page( int $id, string $status = 'publish', string $password = '', string $type = 'page' ): void {
		$post                                     = SectionFixtures::post( $id, $type, $status, 'Page ' . $id, '', SectionFixtures::elementor_meta( [ SectionFixtures::v3_features( 'f' ) ] ) );
		$post->post_password                      = $password;
		$GLOBALS['stonewright_test_posts'][ $id ] = $post;
	}

	/** @return list<string> The warning codes of the first candidate of the first role. */
	private static function find_codes(): array {
		$result = ( new SectionReuseFind() )->execute( [ 'builder' => 'elementor-v3', 'roles' => [ 'features' ] ] );
		self::assertIsArray( $result );

		return array_column( $result['roles'][0]['candidates'][0]['warnings'], 'code' );
	}

	/** @return list<string> */
	private static function extract_codes( int $id ): array {
		$result = ( new SectionReuseExtract() )->execute( [ 'post_id' => $id, 'locator' => [ 'kind' => 'element', 'id' => 'f000001' ] ] );
		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() : '' );

		return array_column( $result['warnings'], 'code' );
	}

	public function test_a_password_protected_source_is_flagged_at_find_and_at_extract(): void {
		self::page( 10, 'publish', 'secret-word' );

		self::assertSame( [ 'password_protected_source' ], self::find_codes() );
		self::assertSame( [ 'password_protected_source' ], self::extract_codes( 10 ) );
	}

	public function test_a_draft_source_is_flagged_at_find_and_at_extract(): void {
		self::page( 11, 'draft' );

		self::assertSame( [ 'draft_source' ], self::find_codes() );
		self::assertSame( [ 'draft_source' ], self::extract_codes( 11 ) );
	}

	public function test_a_draft_that_is_also_password_protected_gets_both(): void {
		self::page( 12, 'draft', 'secret-word' );

		self::assertSame( [ 'draft_source', 'password_protected_source' ], self::find_codes() );
		self::assertSame( [ 'draft_source', 'password_protected_source' ], self::extract_codes( 12 ) );
	}

	public function test_a_published_source_without_a_password_has_no_source_warning(): void {
		self::page( 13 );

		self::assertSame( [], self::find_codes() );
		self::assertSame( [], self::extract_codes( 13 ) );
	}

	public function test_the_warning_names_the_source_and_never_the_password(): void {
		self::page( 14, 'draft', 'secret-word' );

		$result = ( new SectionReuseExtract() )->execute( [ 'post_id' => 14, 'locator' => [ 'kind' => 'element', 'id' => 'f000001' ] ] );

		self::assertIsArray( $result );
		self::assertSame( [ [ 'code' => 'draft_source', 'count' => 1, 'items' => [ '14' ] ], [ 'code' => 'password_protected_source', 'count' => 1, 'items' => [ '14' ] ] ], $result['warnings'] );
		self::assertStringNotContainsString( 'secret-word', (string) wp_json_encode( $result ) );
	}

	public function test_a_draft_pattern_is_flagged_too(): void {
		$post                                      = SectionFixtures::post( 15, 'wp_block', 'draft', 'Banner', SectionFixtures::gutenberg_features_content() );
		$GLOBALS['stonewright_test_posts'][15] = $post;

		$result = ( new SectionReuseFind() )->execute( [ 'builder' => 'gutenberg', 'roles' => [ 'features' ] ] );

		self::assertIsArray( $result );
		self::assertSame( [ 'draft_source' ], array_column( $result['roles'][0]['candidates'][0]['warnings'], 'code' ) );
	}
}
