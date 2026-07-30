<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Companion\Contracts;

/**
 * Shape constants for the GET /health endpoint.
 */
final class Health {

	/** @var array<int, string> */
	public const REQUEST_REQUIRED = [];

	/** @var array<string, array<string, mixed>> */
	public const REQUEST_PROPERTIES = [];

	/** @var array<int, string> */
	public const RESPONSE_REQUIRED = [ 'status', 'contract_version' ];

	/** @var array<string, array<string, mixed>> */
	public const RESPONSE_PROPERTIES = [
		'status'           => [ 'type' => 'string' ],
		'contract_version' => [ 'type' => 'string' ],
	];
}
