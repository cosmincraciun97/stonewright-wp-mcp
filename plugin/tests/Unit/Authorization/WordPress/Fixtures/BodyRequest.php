<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Authorization\WordPress\Fixtures;

/** A REST request with a raw body, as the REST server builds it from the HTTP request. */
final class BodyRequest extends \WP_REST_Request {

	/** @param array<string, string> $headers */
	public function __construct( string $route, private string $raw_body, array $headers = [], string $method = 'POST' ) {
		parent::__construct( $method, $route );
		foreach ( $headers as $name => $value ) {
			$this->set_header( $name, $value );
		}
	}

	public function get_body(): string {
		return $this->raw_body;
	}
}
