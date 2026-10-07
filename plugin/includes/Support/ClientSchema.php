<?php
/**
 * JSON Schema preparation for clients of Stonewright routes.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Support;

/**
 * A schema that leaves WordPress for a client, such as the body of a REST route, goes through
 * wp_prepare_json_schema_for_client() where WordPress provides it (7.1 and later), so the client
 * receives the same draft-04 form that WordPress's own abilities route publishes. Older cores
 * have no such helper and the schema is returned as it is.
 */
final class ClientSchema {

	/**
	 * @param array<string|int, mixed> $schema JSON Schema.
	 * @return array<string|int, mixed>
	 */
	public static function prepare( array $schema ): array {
		if ( ! function_exists( 'wp_prepare_json_schema_for_client' ) ) {
			return $schema;
		}

		return wp_prepare_json_schema_for_client( $schema );
	}
}
