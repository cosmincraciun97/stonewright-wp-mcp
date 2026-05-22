<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Companion\Contracts;

/**
 * Shape constants for the /lighthouse endpoint.
 */
final class Lighthouse {

	/** @var array<int, string> */
	public const REQUEST_REQUIRED = [ 'request_id', 'url' ];

	/** @var array<string, array<string, mixed>> */
	public const REQUEST_PROPERTIES = [
		'request_id'    => [ 'type' => 'string' ],
		'url'           => [ 'type' => 'string' ],
		'categories'    => [ 'type' => 'array' ],
		'artifact_path' => [ 'type' => 'string' ],
	];

	/** @var array<int, string> */
	public const RESPONSE_REQUIRED = [ 'request_id', 'available' ];

	/** @var array<string, array<string, mixed>> */
	public const RESPONSE_PROPERTIES = [
		'request_id'    => [ 'type' => 'string' ],
		'available'     => [ 'type' => 'boolean' ],
		'scores'        => [ 'type' => 'object' ],
		'report_url'    => [ 'type' => 'string' ],
		'audits_failed' => [ 'type' => 'array' ],
	];
}
