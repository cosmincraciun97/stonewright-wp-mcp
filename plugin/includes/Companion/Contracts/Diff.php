<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Companion\Contracts;

/**
 * Shape constants for the /diff endpoint.
 */
final class Diff {

	/** @var array<int, string> */
	public const REQUEST_REQUIRED = [ 'request_id', 'reference_artifact_id', 'actual_artifact_id', 'artifact_path' ];

	/** @var array<string, array<string, mixed>> */
	public const REQUEST_PROPERTIES = [
		'request_id'             => [ 'type' => 'string' ],
		'reference_artifact_id'  => [ 'type' => 'string' ],
		'actual_artifact_id'     => [ 'type' => 'string' ],
		'artifact_path'          => [ 'type' => 'string' ],
		'threshold'              => [ 'type' => 'number' ],
		'ignore_regions'         => [ 'type' => 'array' ],
	];

	/** @var array<int, string> */
	public const RESPONSE_REQUIRED = [ 'request_id', 'needs_reference' ];

	/** @var array<string, array<string, mixed>> */
	public const RESPONSE_PROPERTIES = [
		'request_id'      => [ 'type' => 'string' ],
		'needs_reference' => [ 'type' => 'boolean' ],
		'diff_ratio'      => [ 'type' => 'number' ],
		'passed'          => [ 'type' => 'boolean' ],
		'threshold'       => [ 'type' => 'number' ],
		'diff_url'        => [ 'type' => 'string' ],
		'mismatch_regions' => [ 'type' => 'array' ],
	];
}
