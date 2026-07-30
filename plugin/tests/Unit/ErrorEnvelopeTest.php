<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Support\ErrorEnvelope;

/**
 * @covers \Stonewright\WpMcp\Support\ErrorEnvelope
 */
final class ErrorEnvelopeTest extends TestCase {

	// -------------------------------------------------------------------------
	// Allowlist filtering — spec and unknown keys must be stripped.
	// -------------------------------------------------------------------------

	public function test_strips_spec_and_unknown_keys_keeps_status_and_errors(): void {
		$huge_spec = array_fill( 0, 500, 'sensitive-data' );
		$error     = new \WP_Error(
			'stonewright_spec_invalid',
			'Design spec failed validation.',
			[
				'status'   => 400,
				'spec'     => $huge_spec,
				'errors'   => [ [ 'keyword' => 'required', 'message' => 'page.title is required', 'path' => [] ] ],
				'evil_key' => 'leaked-value',
			]
		);

		$envelope = ErrorEnvelope::from_wp_error( $error );

		$this->assertSame( 'stonewright_spec_invalid', $envelope['error']['code'] );
		$this->assertSame( 400, $envelope['error']['data']['status'] );
		$this->assertNotEmpty( $envelope['error']['data']['errors'] );

		// Sensitive keys must not appear.
		$this->assertArrayNotHasKey( 'spec', $envelope['error']['data'] );
		$this->assertArrayNotHasKey( 'evil_key', $envelope['error']['data'] );
	}

	public function test_strips_all_sensitive_token_keys(): void {
		$error = new \WP_Error(
			'stonewright_test_error',
			'Test error.',
			[
				'status'             => 403,
				'confirmation_token' => 'swc_abc.def',
				'token'              => 'raw-token',
				'password'           => 's3cr3t',
				'api_key'            => 'my-api-key',
				'secret'             => 'shh',
				'args'               => [ 'plan' => [] ],
			]
		);

		$envelope = ErrorEnvelope::from_wp_error( $error );

		$data = $envelope['error']['data'];
		$this->assertSame( 403, $data['status'] );

		foreach ( [ 'confirmation_token', 'token', 'password', 'api_key', 'secret', 'args' ] as $key ) {
			$this->assertArrayNotHasKey( $key, $data, "Key '{$key}' must be stripped from error data." );
		}
	}

	public function test_safe_keys_are_forwarded_intact(): void {
		$errors = [ [ 'keyword' => 'required', 'message' => 'Sections required', 'path' => [ 'sections' ] ] ];
		$error  = new \WP_Error(
			'stonewright_spec_invalid',
			'Invalid spec.',
			[
				'status'       => 422,
				'failed_index' => 3,
				'post_type'    => 'page',
				'ability'      => 'stonewright/design.validate',
				'nonce_sha8'   => 'ab12cd34',
				'errors'       => $errors,
			]
		);

		$envelope = ErrorEnvelope::from_wp_error( $error );
		$data     = $envelope['error']['data'];

		$this->assertSame( 422, $data['status'] );
		$this->assertSame( 3, $data['failed_index'] );
		$this->assertSame( 'page', $data['post_type'] );
		$this->assertSame( 'stonewright/design.validate', $data['ability'] );
		$this->assertSame( 'ab12cd34', $data['nonce_sha8'] );
		$this->assertSame( $errors, $data['errors'] );
	}

	// -------------------------------------------------------------------------
	// Edge cases.
	// -------------------------------------------------------------------------

	public function test_no_data_produces_envelope_with_code_and_message_only(): void {
		$error    = new \WP_Error( 'stonewright_something', 'Something went wrong.' );
		$envelope = ErrorEnvelope::from_wp_error( $error );

		$this->assertSame( 'stonewright_something', $envelope['error']['code'] );
		$this->assertSame( 'Something went wrong.', $envelope['error']['message'] );
		$this->assertArrayNotHasKey( 'data', $envelope['error'] );
	}

	public function test_empty_array_data_produces_no_data_key(): void {
		$error    = new \WP_Error( 'stonewright_something', 'Oops.', [] );
		$envelope = ErrorEnvelope::from_wp_error( $error );

		$this->assertArrayNotHasKey( 'data', $envelope['error'] );
	}

	public function test_non_array_data_produces_no_data_key(): void {
		// WP_Error with scalar data — non-array data must be silently dropped.
		$error    = new \WP_Error( 'stonewright_something', 'Oops.', 'string-data' );
		$envelope = ErrorEnvelope::from_wp_error( $error );

		$this->assertArrayNotHasKey( 'data', $envelope['error'] );
	}

	public function test_data_with_only_unknown_keys_yields_no_data_key(): void {
		// If all keys are stripped by the allowlist, 'data' must not be present.
		$error    = new \WP_Error(
			'stonewright_something',
			'Oops.',
			[ 'spec' => [ 'big' => 'thing' ], 'args' => [ 'plan' => [] ] ]
		);
		$envelope = ErrorEnvelope::from_wp_error( $error );

		$this->assertArrayNotHasKey( 'data', $envelope['error'] );
	}
}
