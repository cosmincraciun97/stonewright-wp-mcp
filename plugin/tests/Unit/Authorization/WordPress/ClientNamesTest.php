<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Authorization\WordPress;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Authorization\WordPress\ClientDocuments;
use Stonewright\WpMcp\Authorization\WordPress\ClientNames;

/**
 * @covers \Stonewright\WpMcp\Authorization\WordPress\ClientNames
 */
final class ClientNamesTest extends TestCase {

	private mixed $original_wpdb;

	protected function setUp(): void {
		$this->original_wpdb = $GLOBALS['wpdb'] ?? null;
	}

	protected function tearDown(): void {
		$GLOBALS['wpdb'] = $this->original_wpdb;
	}

	/** @param list<array<string, string|null>> $rows */
	private function connection( array $rows ): object {
		return $GLOBALS['wpdb'] = new class( $rows ) {
			public string $prefix = 'wp_';
			/** @var list<string> */
			public array $queries = [];
			/** @var list<mixed> */
			public array $arguments = [];

			/** @param list<array<string, string|null>> $rows */
			public function __construct( private array $rows ) {}

			public function prepare( string $query, mixed ...$args ): string {
				$this->arguments = $args;
				return $query;
			}

			/** @return list<array<string, string|null>> */
			public function get_results( string $query, string $output = 'OBJECT' ): array {
				$this->queries[] = $query;
				return $this->rows;
			}
		};
	}

	public function test_registered_clients_are_named_by_their_identifier(): void {
		$wpdb = $this->connection( [ [ 'client_id' => 'client-abc', 'client_name' => 'Desktop client' ], [ 'client_id' => 'other', 'client_name' => '' ] ] );

		self::assertSame( [ 'client-abc' => 'Desktop client' ], ClientNames::lookup( [ 'client-abc', 'client-abc', 'other' ] ) );
		self::assertCount( 1, $wpdb->queries );
		self::assertStringContainsString( 'wp_stonewright_oauth_clients', $wpdb->queries[0] );
		self::assertSame( [ 'client-abc', 'other' ], $wpdb->arguments );
	}

	public function test_document_clients_are_found_through_their_url(): void {
		$url = 'https://client.example.test/oauth/client.json';
		$key = ClientDocuments::client_key( $url );
		$wpdb = $this->connection( [ [ 'client_id' => $key, 'client_name' => 'Document client' ] ] );

		self::assertSame( [ $url => 'Document client', $key => 'Document client' ], ClientNames::lookup( [ $url, $key ] ) );
		self::assertSame( [ $key ], $wpdb->arguments );
	}

	public function test_no_identifiers_need_no_query(): void {
		$wpdb = $this->connection( [] );

		self::assertSame( [], ClientNames::lookup( [ '', str_repeat( 'x', 300 ) ] ) );
		self::assertSame( [], $wpdb->queries );
	}
}
