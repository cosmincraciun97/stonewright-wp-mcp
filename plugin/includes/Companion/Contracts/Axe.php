<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Companion\Contracts;

/**
 * Shape constants for the /axe endpoint.
 */
final class Axe {

	/** @var array<int, string> */
	public const REQUEST_REQUIRED = [ 'request_id', 'url' ];

	/** @var array<string, array<string, mixed>> */
	public const REQUEST_PROPERTIES = [
		'request_id' => [ 'type' => 'string' ],
		'url'        => [ 'type' => 'string' ],
		'ruleset'    => [ 'type' => 'string' ],
	];

	/** @var array<int, string> */
	public const RESPONSE_REQUIRED = [ 'request_id', 'violations', 'passes_count' ];

	/** @var array<string, array<string, mixed>> */
	public const RESPONSE_PROPERTIES = [
		'request_id'       => [ 'type' => 'string' ],
		'violations'       => [ 'type' => 'array' ],
		'passes_count'     => [ 'type' => 'integer' ],
		'incomplete_count' => [ 'type' => 'integer' ],
	];
}
