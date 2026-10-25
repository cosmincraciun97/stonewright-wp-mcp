<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Core\AbilityRegistry;
use Stonewright\WpMcp\Core\PluginRegistration;
use Stonewright\WpMcp\Core\ToolsListPagination;

/**
 * tools/list is paginated at the REST boundary by byte budget, with MCP cursors, so a surface
 * larger than the companion's 1 MiB read cap still arrives. A client that ignores nextCursor
 * keeps the whole essential surface on one page.
 *
 * @covers \Stonewright\WpMcp\Core\ToolsListPagination
 */
final class ToolsListPaginationTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_filters'] = [];
		$GLOBALS['stonewright_test_options'] = [];
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_filters'] = [];
		$GLOBALS['stonewright_test_options'] = [];
	}

	/**
	 * Synthetic tools whose encoded size is predictable: about $payload bytes each.
	 *
	 * @return list<array<string, mixed>>
	 */
	private static function tools( int $count, int $payload = 100 ): array {
		$tools = [];
		for ( $i = 1; $i <= $count; $i++ ) {
			$tools[] = [
				'name'        => sprintf( 'tool-%03d', $i ),
				'description' => str_repeat( 'x', $payload ),
				'inputSchema' => [ 'type' => 'object' ],
			];
		}
		return $tools;
	}

	/**
	 * @param list<array<string, mixed>> $tools
	 * @param mixed                      $id
	 * @return array<string, mixed>
	 */
	private static function envelope( array $tools, mixed $id = 1 ): array {
		return [
			'jsonrpc' => '2.0',
			'id'      => $id,
			'result'  => [ 'tools' => $tools ],
		];
	}

	/**
	 * @param array<string, mixed>|list<array<string, mixed>> $body
	 */
	private static function request( array $body, string $route = '/mcp/stonewright', string $method = 'POST' ): \WP_REST_Request {
		$request = new \WP_REST_Request( $method, $route );
		$request->set_json_params( $body );
		return $request;
	}

	private static function tools_list_message( mixed $id = 1, ?string $cursor = null ): array {
		$message = [
			'jsonrpc' => '2.0',
			'id'      => $id,
			'method'  => 'tools/list',
		];
		if ( null !== $cursor ) {
			$message['params'] = [ 'cursor' => $cursor ];
		}
		return $message;
	}

	private static function dispatch( mixed $response, \WP_REST_Request $request ): mixed {
		return ToolsListPagination::paginate_response( $response, new \WP_REST_Server(), $request );
	}

	private static function encoded_size( array $tools ): int {
		$size = 0;
		foreach ( $tools as $tool ) {
			$size += strlen( (string) wp_json_encode( $tool ) ) + 1;
		}
		return $size;
	}

	public function test_tools_under_the_budget_come_back_in_one_page_without_a_cursor(): void {
		$tools = self::tools( 5 );
		$page  = ToolsListPagination::page( $tools, null, 100000 );

		self::assertIsArray( $page );
		self::assertSame( $tools, $page['tools'] );
		self::assertNull( $page['nextCursor'] );
	}

	public function test_pages_split_on_the_byte_budget_and_the_last_page_has_no_cursor(): void {
		$tools  = self::tools( 10 );
		$budget = self::encoded_size( array_slice( $tools, 0, 4 ) );

		$cursor = null;
		$names  = [];
		$pages  = 0;
		do {
			$page = ToolsListPagination::page( $tools, $cursor, $budget );
			self::assertIsArray( $page );
			self::assertLessThanOrEqual( $budget, self::encoded_size( $page['tools'] ) );
			foreach ( $page['tools'] as $tool ) {
				$names[] = $tool['name'];
			}
			++$pages;
			$cursor = $page['nextCursor'];
			self::assertLessThan( 10, $pages, 'Pagination must terminate.' );
		} while ( null !== $cursor );

		self::assertSame( 3, $pages, 'Four tools fit per page: 4 + 4 + 2.' );
		self::assertSame( array_column( $tools, 'name' ), $names, 'Every tool arrives once, in order.' );
	}

	public function test_a_tool_larger_than_the_budget_still_gets_its_own_page(): void {
		$tools   = self::tools( 3 );
		$tools[1]['description'] = str_repeat( 'y', 5000 );

		$first = ToolsListPagination::page( $tools, null, 600 );
		self::assertIsArray( $first );
		self::assertSame( [ 'tool-001' ], array_column( $first['tools'], 'name' ) );
		self::assertNotNull( $first['nextCursor'] );

		$second = ToolsListPagination::page( $tools, $first['nextCursor'], 600 );
		self::assertIsArray( $second );
		self::assertSame( [ 'tool-002' ], array_column( $second['tools'], 'name' ), 'An oversized tool is never dropped.' );
		self::assertNotNull( $second['nextCursor'] );

		$third = ToolsListPagination::page( $tools, $second['nextCursor'], 600 );
		self::assertIsArray( $third );
		self::assertSame( [ 'tool-003' ], array_column( $third['tools'], 'name' ) );
		self::assertNull( $third['nextCursor'] );
	}

	public function test_a_cursor_from_another_surface_is_refused_with_invalid_params(): void {
		$tools = self::tools( 6 );
		$first = ToolsListPagination::page( $tools, null, 500 );
		self::assertIsArray( $first );
		self::assertNotNull( $first['nextCursor'] );

		$other = $tools;
		array_pop( $other );
		$result = ToolsListPagination::page( $other, $first['nextCursor'], 500 );
		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_invalid_cursor', $result->get_error_code() );

		foreach ( [ 'not-base64!', ToolsListPagination::encode_cursor( 99, 'whatever0000' ), base64_encode( '{"o":"x"}' ) ] as $junk ) {
			$bad = ToolsListPagination::page( $tools, $junk, 500 );
			self::assertInstanceOf( \WP_Error::class, $bad, 'A malformed or stale cursor is refused.' );
		}

		// Through the REST boundary the refusal is a JSON-RPC -32602 "Invalid cursor".
		$request = self::request( self::tools_list_message( 1, $first['nextCursor'] ) );
		$out     = self::with_budget(
			500,
			static fn() => self::dispatch(
				new \WP_REST_Response( self::envelope( $other ) ),
				$request
			)
		);
		self::assertInstanceOf( \WP_REST_Response::class, $out );
		$data = $out->get_data();
		self::assertSame( -32602, $data['error']['code'] );
		self::assertSame( 'Invalid cursor', $data['error']['message'] );
		self::assertSame( 1, $data['id'] );
		self::assertArrayNotHasKey( 'result', $data );
		self::assertSame( 200, $out->get_status(), 'The adapter answers invalid params with HTTP 200 and a JSON-RPC error; so does this.' );
	}

	public function test_a_single_request_is_rewritten_to_the_page_and_a_cursor_follows(): void {
		$tools   = self::tools( 6 );
		$request = self::request( self::tools_list_message( 7 ) );

		$out  = self::with_budget( self::encoded_size( array_slice( $tools, 0, 2 ) ), static fn() => self::dispatch( new \WP_REST_Response( self::envelope( $tools, 7 ) ), $request ) );
		$data = $out->get_data();
		self::assertSame( 7, $data['id'] );
		self::assertSame( [ 'tool-001', 'tool-002' ], array_column( $data['result']['tools'], 'name' ) );
		self::assertIsString( $data['result']['nextCursor'] );
		self::assertSame( 200, $out->get_status() );

		$next_request = self::request( self::tools_list_message( 8, $data['result']['nextCursor'] ) );
		$next         = self::with_budget( self::encoded_size( array_slice( $tools, 0, 2 ) ), static fn() => self::dispatch( new \WP_REST_Response( self::envelope( $tools, 8 ) ), $next_request ) );
		self::assertSame( [ 'tool-003', 'tool-004' ], array_column( $next->get_data()['result']['tools'], 'name' ) );
	}

	public function test_batch_requests_paginate_each_tools_list_message_by_id(): void {
		$tools   = self::tools( 6 );
		$budget  = self::encoded_size( array_slice( $tools, 0, 3 ) );
		$first   = ToolsListPagination::page( $tools, null, $budget );
		self::assertIsArray( $first );

		$request = self::request(
			[
				self::tools_list_message( 'a' ),
				[
					'jsonrpc' => '2.0',
					'id'      => 'b',
					'method'  => 'ping',
				],
				self::tools_list_message( 'c', $first['nextCursor'] ),
				[
					'jsonrpc' => '2.0',
					'method'  => 'notifications/initialized',
				],
			]
		);
		$batch   = [
			self::envelope( $tools, 'c' ),
			[
				'jsonrpc' => '2.0',
				'id'      => 'b',
				'result'  => [],
			],
			self::envelope( $tools, 'a' ),
		];

		$out  = self::with_budget( $budget, static fn() => self::dispatch( new \WP_REST_Response( $batch ), $request ) );
		$data = $out->get_data();
		self::assertCount( 3, $data );
		self::assertSame( 'c', $data[0]['id'] );
		self::assertSame( [ 'tool-004', 'tool-005', 'tool-006' ], array_column( $data[0]['result']['tools'], 'name' ) );
		self::assertArrayNotHasKey( 'nextCursor', $data[0]['result'] );
		self::assertSame( [], $data[1]['result'], 'Other methods in the batch are untouched.' );
		self::assertSame( 'a', $data[2]['id'] );
		self::assertSame( [ 'tool-001', 'tool-002', 'tool-003' ], array_column( $data[2]['result']['tools'], 'name' ) );
		self::assertSame( $first['nextCursor'], $data[2]['result']['nextCursor'] );
	}

	public function test_an_invalid_cursor_in_a_batch_only_fails_its_own_message(): void {
		$tools   = self::tools( 6 );
		$request = self::request( [ self::tools_list_message( 1, 'garbage' ), self::tools_list_message( 2 ) ] );
		$batch   = [ self::envelope( $tools, 1 ), self::envelope( $tools, 2 ) ];

		$out  = self::with_budget( 500, static fn() => self::dispatch( new \WP_REST_Response( $batch ), $request ) );
		$data = $out->get_data();
		self::assertSame( -32602, $data[0]['error']['code'] );
		self::assertArrayHasKey( 'result', $data[1] );
		self::assertSame( 200, $out->get_status(), 'A batch keeps HTTP 200; the error lives in its own envelope.' );
	}

	public function test_zero_budget_disables_pagination(): void {
		$tools   = self::tools( 6 );
		$request = self::request( self::tools_list_message( 1 ) );
		$response = new \WP_REST_Response( self::envelope( $tools ) );

		$out = self::with_budget( 0, static fn() => self::dispatch( $response, $request ) );
		self::assertSame( $tools, $out->get_data()['result']['tools'] );
		self::assertArrayNotHasKey( 'nextCursor', $out->get_data()['result'] );
	}

	public function test_page_bytes_constant_filter_and_default(): void {
		self::assertSame( 786432, ToolsListPagination::DEFAULT_PAGE_BYTES );
		self::assertSame( 786432, ToolsListPagination::page_bytes() );

		$GLOBALS['stonewright_test_filters']['stonewright_mcp_tools_list_page_bytes'] = static fn( $value ) => 4096;
		self::assertSame( 4096, ToolsListPagination::page_bytes() );

		$GLOBALS['stonewright_test_filters']['stonewright_mcp_tools_list_page_bytes'] = static fn( $value ) => -5;
		self::assertSame( 0, ToolsListPagination::page_bytes(), 'A negative value disables pagination like zero.' );

		$GLOBALS['stonewright_test_filters']['stonewright_mcp_tools_list_page_bytes'] = static fn( $value ) => 'junk';
		self::assertSame( 786432, ToolsListPagination::page_bytes(), 'A filter that returns a non-number keeps the default.' );
	}

	public function test_other_routes_and_methods_are_untouched(): void {
		$tools = self::tools( 6 );

		$cases = [
			'other namespace'  => [ self::request( self::tools_list_message( 1 ), '/wp/v2/posts' ) ],
			'get on mcp route' => [ self::request( self::tools_list_message( 1 ), '/mcp/stonewright', 'GET' ) ],
			'other method'     => [
				self::request(
					[
						'jsonrpc' => '2.0',
						'id'      => 1,
						'method'  => 'tools/call',
					]
				),
			],
		];
		foreach ( $cases as $label => [ $request ] ) {
			$response = new \WP_REST_Response( self::envelope( $tools ) );
			$out      = self::with_budget( 300, static fn() => self::dispatch( $response, $request ) );
			self::assertSame( $response, $out, $label );
			self::assertSame( $tools, $out->get_data()['result']['tools'], $label );
		}

		$passthrough = new \WP_Error( 'x', 'y' );
		self::assertSame( $passthrough, self::with_budget( 300, static fn() => self::dispatch( $passthrough, self::request( self::tools_list_message( 1 ) ) ) ) );
	}

	public function test_both_mcp_routes_are_paginated(): void {
		$tools = self::tools( 6 );
		foreach ( [ '/mcp/stonewright', '/mcp/stonewright-oauth' ] as $route ) {
			$request = self::request( self::tools_list_message( 1 ), $route );
			$out     = self::with_budget( 300, static fn() => self::dispatch( new \WP_REST_Response( self::envelope( $tools ) ), $request ) );
			self::assertLessThan( 6, count( $out->get_data()['result']['tools'] ), $route );
			self::assertIsString( $out->get_data()['result']['nextCursor'], $route );
		}
	}

	public function test_register_hooks_rest_post_dispatch_and_plugin_registration_calls_it(): void {
		ToolsListPagination::register();
		self::assertNotFalse( has_filter( 'rest_post_dispatch' ) );

		$source = (string) file_get_contents( dirname( __DIR__, 3 ) . '/includes/Core/PluginRegistration.php' );
		self::assertStringContainsString( 'ToolsListPagination::register()', $source );
		self::assertTrue( class_exists( PluginRegistration::class ) );
	}

	public function test_essential_profile_fits_one_page_at_the_default_budget(): void {
		$GLOBALS['stonewright_test_user_caps']       = [
			'read'           => true,
			'manage_options' => true,
		];
		$GLOBALS['stonewright_test_user_logged_in']  = true;
		$GLOBALS['stonewright_test_current_user_id'] = 1;
		$GLOBALS['stonewright_test_options']         = [
			'stonewright_mode'                      => 'development',
			'stonewright_disabled_abilities'        => [],
			'stonewright_essential_tools_mode'      => true,
			'stonewright_essential_extra_abilities' => [],
		];

		$tools = [];
		foreach ( AbilityRegistry::enabled_abilities() as $row ) {
			$tools[] = [
				'name'        => (string) $row['mcp_tool_name'],
				'description' => (string) $row['description'],
				'inputSchema' => $row['input_schema'],
			];
		}
		self::assertNotEmpty( $tools );

		$page = ToolsListPagination::page( $tools, null, ToolsListPagination::DEFAULT_PAGE_BYTES );
		self::assertIsArray( $page );
		self::assertNull( $page['nextCursor'], 'A client that ignores nextCursor still gets the whole essential surface.' );
		self::assertCount( count( $tools ), $page['tools'] );
	}

	/**
	 * Runs $callback with the page budget forced through the filter.
	 *
	 * @param callable():mixed $callback
	 */
	private static function with_budget( int $budget, callable $callback ): mixed {
		$GLOBALS['stonewright_test_filters']['stonewright_mcp_tools_list_page_bytes'] = static fn( $value ) => $budget;
		try {
			return $callback();
		} finally {
			unset( $GLOBALS['stonewright_test_filters']['stonewright_mcp_tools_list_page_bytes'] );
		}
	}
}
