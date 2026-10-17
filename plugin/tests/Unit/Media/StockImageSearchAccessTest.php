<?php
/**
 * stock-image-search spends the site's provider quota: upload_files, as stock-image-import; values are encoded.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Media;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\Media\StockImageImport;
use Stonewright\WpMcp\Abilities\Media\StockImageSearch;
use Stonewright\WpMcp\Media\StockImageClient;

/**
 * @covers \Stonewright\WpMcp\Abilities\Media\StockImageSearch
 * @covers \Stonewright\WpMcp\Media\StockImageClient
 */
final class StockImageSearchAccessTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_user_logged_in']      = true;
		$GLOBALS['stonewright_test_user_can_callback']   = null;
		$GLOBALS['stonewright_test_options']             = [];
		$GLOBALS['stonewright_test_wp_remote_get_calls'] = [];
		$GLOBALS['stonewright_test_wp_remote_get']       = static fn (): array => [
			'response' => [ 'code' => 200 ],
			'body'     => (string) wp_json_encode( [ 'results' => [], 'photos' => [] ] ),
		];
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_user_caps']           = [];
		$GLOBALS['stonewright_test_options']             = [];
		$GLOBALS['stonewright_test_wp_remote_get_calls'] = [];
		unset( $GLOBALS['stonewright_test_wp_remote_get'] );
	}

	/** @param list<string> $caps */
	private function user_with( array $caps ): void {
		$GLOBALS['stonewright_test_user_caps'] = array_fill_keys( $caps, true );
	}

	public function test_a_user_who_can_only_read_or_edit_posts_is_refused(): void {
		$ability = new StockImageSearch();

		$this->user_with( [ 'read' ] );
		self::assertNotTrue( $ability->permission_callback( [ 'query' => 'timber' ] ) );

		$this->user_with( [ 'read', 'edit_posts' ] );
		self::assertNotTrue( $ability->permission_callback( [ 'query' => 'timber' ] ) );
	}

	public function test_a_user_who_can_upload_files_may_search_as_for_the_import(): void {
		$this->user_with( [ 'read', 'edit_posts', 'upload_files' ] );

		self::assertTrue( ( new StockImageSearch() )->permission_callback( [ 'query' => 'timber' ] ) );
		self::assertTrue( ( new StockImageImport() )->permission_callback( [ 'url' => 'https://images.example.test/a.png' ] ) );
	}

	/** @return array<string, array{0: string}> */
	public static function providers(): array {
		return [
			'openverse' => [ StockImageClient::PROVIDER_OPENVERSE ],
			'unsplash'  => [ StockImageClient::PROVIDER_UNSPLASH ],
			'pexels'    => [ StockImageClient::PROVIDER_PEXELS ],
		];
	}

	/** @dataProvider providers */
	public function test_every_query_value_is_encoded_so_it_cannot_add_parameters( string $provider ): void {
		$GLOBALS['stonewright_test_options'][ StockImageClient::OPTION_UNSPLASH ] = 'unsplash-key-example';
		$GLOBALS['stonewright_test_options'][ StockImageClient::OPTION_PEXELS ]   = 'pexels-key-example';

		$result = StockImageClient::search( 'cats&x=y #1 caf' . "\u{e9}", $provider, 2, 5 );

		self::assertIsArray( $result );
		$calls = $GLOBALS['stonewright_test_wp_remote_get_calls'];
		self::assertCount( 1, $calls );
		$url = (string) $calls[0]['url'];
		parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $parsed );
		self::assertStringNotContainsString( '&x=y', $url );
		self::assertStringContainsString( 'cats%26x%3Dy%20%231%20caf%C3%A9', $url );
		self::assertArrayNotHasKey( 'x', $parsed, 'The value cannot add a parameter.' );
		self::assertSame( 'cats&x=y #1 caf' . "\u{e9}", $parsed['q'] ?? $parsed['query'] );
		self::assertSame( '2', $parsed['page'] );
	}
}
