<?php
declare( strict_types=1 );

/**
 * Stand-in for wp_prepare_json_schema_for_client(), which WordPress provides from 7.1.
 *
 * It marks each schema it receives and counts the calls, so a test can tell which schemas
 * went through it. Load it in a separate process: a function cannot be declared twice.
 *
 * @param array<string|int, mixed> $schema         Schema.
 * @param string                   $schema_profile Keyword profile.
 * @return array<string|int, mixed>
 */
function wp_prepare_json_schema_for_client( array $schema, string $schema_profile = 'draft-04' ): array {
	$GLOBALS['stonewright_test_prepared_schemas'] = ( $GLOBALS['stonewright_test_prepared_schemas'] ?? 0 ) + 1;
	$schema['x-prepared']                         = $schema_profile;
	return $schema;
}
