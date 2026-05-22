<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Companion\Contracts;

/**
 * PHP mirror of companion/src/contracts/figma-ingest.schema.json.
 *
 * Provides shape constants for CompanionContract::validate() to validate
 * requests sent TO the companion's /figma-ingest endpoint and responses
 * received FROM it.
 *
 * Companion is the canonical schema source; these constants must be kept in sync
 * whenever figma-ingest.schema.json changes.
 */
final class FigmaIngest {

	/**
	 * Required fields in the /figma-ingest request payload.
	 *
	 * NOTE: the companion requires either figma_url OR (file_key + node_id).
	 * The PHP-level required list is empty because the anyOf constraint is
	 * enforced procedurally in the ability (not structural type checking).
	 *
	 * @var array<int, string>
	 */
	public const REQUEST_REQUIRED = [];

	/**
	 * @var array<string, array<string, mixed>>
	 */
	public const REQUEST_PROPERTIES = [
		'figma_url'      => [ 'type' => 'string' ],
		'file_key'       => [ 'type' => 'string' ],
		'node_id'        => [ 'type' => 'string' ],
		'token_override' => [ 'type' => 'string' ],
	];

	/**
	 * Required fields in the /figma-ingest response payload.
	 *
	 * @var array<int, string>
	 */
	public const RESPONSE_REQUIRED = [ 'spec', 'warnings', 'asset_count' ];

	/**
	 * @var array<string, array<string, mixed>>
	 */
	public const RESPONSE_PROPERTIES = [
		'spec'        => [ 'type' => 'object' ],
		'warnings'    => [ 'type' => 'array' ],
		'asset_count' => [ 'type' => 'integer' ],
	];
}
