<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Companion\Contracts;

/**
 * Shape constants for the /screenshot endpoint.
 *
 * Each constant is a minimal JSON Schema array compatible with the
 * CompanionContract::validate() walker. These mirror the JSON Schema files
 * under companion/src/contracts/ — schemas are the single source of truth;
 * this file is the PHP mirror.
 */
final class Screenshot {

	/**
	 * Required fields in the request payload.
	 *
	 * @var array<int, string>
	 */
	public const REQUEST_REQUIRED = [ 'request_id', 'url', 'artifact_path' ];

	/**
	 * @var array<string, array<string, mixed>>
	 */
	public const REQUEST_PROPERTIES = [
		'request_id'    => [ 'type' => 'string' ],
		'url'           => [ 'type' => 'string' ],
		'artifact_path' => [ 'type' => 'string' ],
		'viewport'      => [ 'type' => 'object' ],
		'full_page'     => [ 'type' => 'boolean' ],
		'wait_for'      => [ 'type' => 'string' ],
		'wait_ms'       => [ 'type' => 'integer' ],
		'selector'      => [ 'type' => 'string' ],
	];

	/**
	 * @var array<int, string>
	 */
	public const RESPONSE_REQUIRED = [ 'request_id', 'artifact_id', 'path', 'url', 'width', 'height', 'viewport', 'created_at' ];

	/**
	 * @var array<string, array<string, mixed>>
	 */
	public const RESPONSE_PROPERTIES = [
		'request_id'  => [ 'type' => 'string' ],
		'artifact_id' => [ 'type' => 'string' ],
		'path'        => [ 'type' => 'string' ],
		'url'         => [ 'type' => 'string' ],
		'width'       => [ 'type' => 'integer' ],
		'height'      => [ 'type' => 'integer' ],
		'viewport'    => [ 'type' => 'object' ],
		'created_at'  => [ 'type' => 'string' ],
	];
}
