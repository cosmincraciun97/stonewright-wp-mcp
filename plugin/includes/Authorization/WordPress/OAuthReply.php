<?php
/**
 * An OAuth endpoint response, independent of the REST server.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Authorization\WordPress;

/**
 * Status, JSON body (null for an empty body), headers and the audit facts of one
 * response. Audit facts never hold credentials: client_id (the identifier the client
 * presented), sensitive_values (request values the audit recorder must mask) and an
 * optional audit body that replaces the response body for the recorder.
 */
final class OAuthReply {

	/**
	 * @param array<string, mixed>|null $body
	 * @param array<string, string>     $headers
	 * @param array<string, mixed>      $audit
	 */
	public function __construct( public readonly int $status, public readonly ?array $body, public readonly array $headers = [], public readonly array $audit = [] ) {}

	/** @param array<string, string> $headers Added or replaced headers. */
	public function with_headers( array $headers ): self {
		return new self( $this->status, $this->body, array_replace( $this->headers, $headers ), $this->audit );
	}

	/** @param array<string, mixed> $audit Added or replaced audit facts. */
	public function with_audit( array $audit ): self {
		return new self( $this->status, $this->body, $this->headers, array_replace( $this->audit, $audit ) );
	}

	public function to_rest(): \WP_REST_Response {
		$response = new \WP_REST_Response( $this->body, $this->status );
		foreach ( $this->headers as $name => $value ) {
			$response->header( $name, $value );
		}
		return $response;
	}

	/** A response object holding what the audit recorder may see. */
	public function audit_response(): \WP_REST_Response {
		$body = is_array( $this->audit['body'] ?? null ) ? $this->audit['body'] : ( $this->body ?? [] );
		$response = new \WP_REST_Response( $body, $this->status );
		if ( isset( $this->headers['Retry-After'] ) ) {
			$response->header( 'Retry-After', $this->headers['Retry-After'] );
		}
		return $response;
	}

	/** Send status, headers and (unless suppressed) the JSON body outside the REST server. */
	public function send( bool $with_body = true ): void {
		status_header( $this->status );
		foreach ( $this->headers as $name => $value ) {
			header( $name . ': ' . $value );
		}
		if ( $with_body && null !== $this->body ) {
			echo (string) wp_json_encode( $this->body, JSON_UNESCAPED_SLASHES ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON document served with an application/json content type.
		}
	}
}
