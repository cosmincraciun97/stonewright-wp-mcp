<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Companion\Contracts;

/**
 * Shape constants for the /layout endpoint.
 */
final class Layout {

	/** @var array<int, string> */
	public const REQUEST_REQUIRED = [ 'request_id', 'url' ];

	/** @var array<string, array<string, mixed>> */
	public const REQUEST_PROPERTIES = [
		'request_id' => [ 'type' => 'string' ],
		'url'        => [ 'type' => 'string' ],
		'viewport'   => [ 'type' => 'object' ],
	];

	/** @var array<int, string> */
	public const RESPONSE_REQUIRED = [ 'request_id', 'sections', 'alignment_diffs', 'has_horizontal_overflow', 'has_element_overlap' ];

	/** @var array<string, array<string, mixed>> */
	public const RESPONSE_PROPERTIES = [
		'request_id'             => [ 'type' => 'string' ],
		'sections'               => [ 'type' => 'array' ],
		'alignment_diffs'        => [ 'type' => 'array' ],
		'has_horizontal_overflow' => [ 'type' => 'boolean' ],
		'has_element_overlap'    => [ 'type' => 'boolean' ],
	];
}
