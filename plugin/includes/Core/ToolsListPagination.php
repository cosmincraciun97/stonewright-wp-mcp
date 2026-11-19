<?php
/**
 * Byte-budget pagination of the MCP tools/list answer.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Core;

/**
 * The MCP adapter answers tools/list with every tool in one page and has no cursor support. On a
 * site with a large tool surface that page passes 1 MiB, the size the companion reads at most
 * from a response body. This class pages the answer at the REST boundary (rest_post_dispatch),
 * without forking the adapter, following the MCP pagination utility:
 *
 * - each tools/list answer holds tools up to a byte budget (STONEWRIGHT_MCP_TOOLS_LIST_PAGE_BYTES,
 *   filter stonewright_mcp_tools_list_page_bytes, default 768 KiB; 0 turns paging off) and at
 *   least one tool, so a tool larger than the budget still gets its own page;
 * - when more tools remain, result.nextCursor is an opaque, URL-safe string the client sends back
 *   as params.cursor;
 * - a cursor carries an offset and a digest of the tool names, so a cursor from another surface
 *   (a profile change, a plugin activated in between) is answered with JSON-RPC -32602
 *   "Invalid cursor" and the client starts again from the first page;
 * - single messages and JSON-RPC batches are both handled, matching answers to requests by id;
 * - an answer may hold arrays (mcp-adapter 0.6) or objects: from 0.7 the adapter hands the REST
 *   layer a stdClass with schema records inside, and any active plugin can bring that version.
 *   Both are paged; objects stay objects, so an empty schema still encodes as {} and not [].
 *
 * A client that never sends a cursor gets the first page only. The essential surface and the
 * smaller profiles fit one page at the default budget.
 */
final class ToolsListPagination {

	/** 768 KiB: well under the 1 MiB the companion reads from a body. */
	public const DEFAULT_PAGE_BYTES = 786432;

	public const PAGE_BYTES_CONSTANT = 'STONEWRIGHT_MCP_TOOLS_LIST_PAGE_BYTES';

	public const PAGE_BYTES_FILTER = 'stonewright_mcp_tools_list_page_bytes';

	private const ERROR_CODE = -32602;

	private const ERROR_MESSAGE = 'Invalid cursor';

	public static function register(): void {
		add_filter( 'rest_post_dispatch', [ self::class, 'paginate_response' ], 10, 3 );
	}

	/**
	 * The byte budget of one tools/list page, or 0 when paging is off.
	 */
	public static function page_bytes(): int {
		if ( defined( self::PAGE_BYTES_CONSTANT ) ) {
			$value = constant( self::PAGE_BYTES_CONSTANT );
		} else {
			$value = apply_filters( self::PAGE_BYTES_FILTER, self::DEFAULT_PAGE_BYTES );
		}
		if ( ! is_int( $value ) && ! ( is_string( $value ) && is_numeric( $value ) ) && ! is_float( $value ) ) {
			return self::DEFAULT_PAGE_BYTES;
		}
		return max( 0, (int) $value );
	}

	/**
	 * Pages the tools/list answers of an MCP route (rest_post_dispatch).
	 *
	 * @param mixed $result  The REST response.
	 * @param mixed $server  The REST server.
	 * @param mixed $request The request.
	 * @return mixed
	 */
	public static function paginate_response( mixed $result, mixed $server = null, mixed $request = null ): mixed {
		unset( $server );
		if ( ! $result instanceof \WP_REST_Response
			|| ! $request instanceof \WP_REST_Request
			|| 'POST' !== strtoupper( (string) $request->get_method() )
			|| ! McpOriginGuard::is_mcp_route( (string) $request->get_route() )
		) {
			return $result;
		}

		$budget = self::page_bytes();
		if ( $budget <= 0 ) {
			return $result;
		}

		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			return $result;
		}
		$messages = array_is_list( $body ) ? $body : [ $body ];

		// Cursor (or null) of every tools/list request, by JSON-RPC id.
		$wanted = [];
		foreach ( $messages as $message ) {
			if ( ! is_array( $message ) || 'tools/list' !== ( $message['method'] ?? null ) || ! isset( $message['id'] ) ) {
				continue;
			}
			$params = $message['params'] ?? null;
			$cursor = is_array( $params ) && array_key_exists( 'cursor', $params ) ? $params['cursor'] : null;
			// A cursor that is not a string cannot be one of ours: it is refused like a stale one.
			$wanted[ self::id_key( $message['id'] ) ] = null === $cursor || is_string( $cursor ) ? $cursor : '';
		}
		if ( [] === $wanted ) {
			return $result;
		}

		$data = $result->get_data();
		if ( is_array( $data ) && array_is_list( $data ) ) {
			foreach ( $data as $index => $envelope ) {
				$key = self::envelope_id_key( $envelope );
				if ( null !== $key && array_key_exists( $key, $wanted ) ) {
					$data[ $index ] = self::rewrite_envelope( $envelope, $wanted[ $key ], $budget );
				}
			}
		} else {
			$key = self::envelope_id_key( $data );
			if ( null === $key || ! array_key_exists( $key, $wanted ) ) {
				return $result;
			}
			$data = self::rewrite_envelope( $data, $wanted[ $key ], $budget );
		}

		$result->set_data( $data );
		return $result;
	}

	/**
	 * One page of a tool list.
	 *
	 * @param array<int, mixed> $tools  Every tool of the surface, in order.
	 * @param string|null       $cursor The cursor of the previous page, or null for the first page.
	 * @param int               $budget Page budget in bytes (JSON size of the tools plus one byte each for the commas).
	 * @return array{tools: list<mixed>, nextCursor: string|null}|\WP_Error An error when the cursor is not one of this surface.
	 */
	public static function page( array $tools, ?string $cursor, int $budget ): array|\WP_Error {
		$tools  = array_values( $tools );
		$digest = self::digest( $tools );
		$offset = 0;

		if ( null !== $cursor ) {
			$decoded = self::decode_cursor( $cursor );
			if ( null === $decoded || $decoded['d'] !== $digest || $decoded['o'] >= count( $tools ) ) {
				return new \WP_Error( 'stonewright_invalid_cursor', self::ERROR_MESSAGE );
			}
			$offset = $decoded['o'];
		}

		$page  = [];
		$bytes = 0;
		$count = count( $tools );
		for ( $i = $offset; $i < $count; $i++ ) {
			$encoded = wp_json_encode( $tools[ $i ] );
			$size    = ( is_string( $encoded ) ? strlen( $encoded ) : 0 ) + 1;
			if ( [] !== $page && $bytes + $size > $budget ) {
				break;
			}
			$page[] = $tools[ $i ];
			$bytes += $size;
		}

		$next = $offset + count( $page );
		return [
			'tools'      => $page,
			'nextCursor' => $next < $count ? self::encode_cursor( $next, $digest ) : null,
		];
	}

	public static function encode_cursor( int $offset, string $digest ): string {
		$json = (string) wp_json_encode(
			[
				'o' => $offset,
				'd' => $digest,
			]
		);
		return rtrim( strtr( base64_encode( $json ), '+/', '-_' ), '=' );
	}

	/**
	 * @return array{o: int, d: string}|null Null when the string is not a cursor this class issued.
	 */
	public static function decode_cursor( string $cursor ): ?array {
		if ( '' === $cursor || strlen( $cursor ) > 256 || 1 !== preg_match( '/^[A-Za-z0-9_-]+$/', $cursor ) ) {
			return null;
		}
		$raw = base64_decode( strtr( $cursor, '-_', '+/' ), true );
		if ( false === $raw ) {
			return null;
		}
		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) || ! isset( $data['o'], $data['d'] ) || ! is_int( $data['o'] ) || $data['o'] < 0 || ! is_string( $data['d'] ) ) {
			return null;
		}
		return [
			'o' => $data['o'],
			'd' => $data['d'],
		];
	}

	/**
	 * First twelve hex characters of the sha1 of the tool names, in order.
	 *
	 * @param list<mixed> $tools
	 */
	private static function digest( array $tools ): string {
		$names = [];
		foreach ( $tools as $tool ) {
			$value   = self::unwrap( $tool );
			$name    = null === $value ? null : self::field( $value, 'name' );
			$names[] = is_string( $name ) ? $name : sha1( (string) wp_json_encode( $tool ) );
		}
		return substr( sha1( implode( "\n", $names ) ), 0, 12 );
	}

	/**
	 * An array or a plain object as it is; any other JSON-serializable value (a schema record of a
	 * newer MCP adapter) as what it serializes to, one level deep. Nested values are left alone, so
	 * empty schema objects keep encoding as {}.
	 *
	 * @return array<mixed>|\stdClass|null Null when the value is neither.
	 */
	private static function unwrap( mixed $value ): array|\stdClass|null {
		if ( $value instanceof \JsonSerializable ) {
			$value = $value->jsonSerialize();
		}
		return is_array( $value ) || $value instanceof \stdClass ? $value : null;
	}

	/**
	 * @param array<mixed>|\stdClass $value
	 */
	private static function field( array|\stdClass $value, string $key ): mixed {
		return is_array( $value ) ? ( $value[ $key ] ?? null ) : ( $value->{$key} ?? null );
	}

	/**
	 * The id key of one JSON-RPC response, or null when it carries no id.
	 */
	private static function envelope_id_key( mixed $envelope ): ?string {
		$value = self::unwrap( $envelope );
		if ( null === $value || ! ( is_array( $value ) ? array_key_exists( 'id', $value ) : property_exists( $value, 'id' ) ) ) {
			return null;
		}
		return self::id_key( self::field( $value, 'id' ) );
	}

	/**
	 * Stable key of a JSON-RPC id; 1 and "1" are different ids.
	 */
	private static function id_key( mixed $id ): string {
		if ( is_int( $id ) ) {
			return 'i:' . $id;
		}
		if ( is_string( $id ) ) {
			return 's:' . $id;
		}
		return 'x:' . wp_json_encode( $id );
	}

	/**
	 * @param mixed $envelope One JSON-RPC response: an array, a plain object or a schema record.
	 * @return mixed The response with its tools cut to the page; arrays stay arrays, objects become plain objects.
	 */
	private static function rewrite_envelope( mixed $envelope, ?string $cursor, int $budget ): mixed {
		$value  = self::unwrap( $envelope );
		$result = null === $value ? null : self::unwrap( self::field( $value, 'result' ) );
		$tools  = null === $result ? null : self::field( $result, 'tools' );
		if ( null === $value || ! is_array( $tools ) ) {
			return $envelope;
		}

		$page = self::page( $tools, $cursor, $budget );
		if ( $page instanceof \WP_Error ) {
			return [
				'jsonrpc' => self::field( $value, 'jsonrpc' ) ?? '2.0',
				'id'      => self::field( $value, 'id' ),
				'error'   => [
					'code'    => self::ERROR_CODE,
					'message' => self::ERROR_MESSAGE,
				],
			];
		}

		if ( is_array( $result ) ) {
			$result['tools'] = $page['tools'];
			unset( $result['nextCursor'] );
			if ( null !== $page['nextCursor'] ) {
				$result['nextCursor'] = $page['nextCursor'];
			}
		} else {
			$result        = clone $result;
			$result->tools = $page['tools'];
			unset( $result->nextCursor );
			if ( null !== $page['nextCursor'] ) {
				$result->nextCursor = $page['nextCursor'];
			}
		}

		if ( is_array( $value ) ) {
			$value['result'] = $result;
			return $value;
		}
		$value         = clone $value;
		$value->result = $result;
		return $value;
	}
}
