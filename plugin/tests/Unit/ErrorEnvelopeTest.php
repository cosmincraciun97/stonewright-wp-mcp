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

	// -------------------------------------------------------------------------
	// A refusal that waits for a person names the page where the person decides.
	// -------------------------------------------------------------------------

	public function test_the_message_of_an_approval_refusal_carries_its_code_flags_and_link(): void {
		$error = new \WP_Error(
			'stonewright_custom_code_grant_required',
			'Show the user the approval URL and stop.',
			[
				'status'            => 400,
				'retryable'         => false,
				'execution_status'  => 'blocked',
				'approval_required' => true,
				'agent_must_stop'   => true,
				'approval_url'      => 'https://example.test/wp-admin/admin.php?page=stonewright-custom-code-approval&proposal_id=abc',
				'after_sha256'      => str_repeat( 'a', 64 ),
			]
		);

		$message = ErrorEnvelope::with_agent_visible_payload( $error )->get_error_message();

		$this->assertSame( 1, preg_match( '/(\{.*\})\s*$/', $message, $match ), $message );
		$payload = json_decode( $match[1], true );
		$this->assertSame( 'stonewright_custom_code_grant_required', $payload['code'] );
		$this->assertTrue( $payload['approval_required'] );
		$this->assertTrue( $payload['agent_must_stop'] );
		$this->assertSame( 'https://example.test/wp-admin/admin.php?page=stonewright-custom-code-approval&proposal_id=abc', $payload['approval_url'] );
		$this->assertArrayNotHasKey( 'after_sha256', $payload, 'Only the approval fields are copied.' );
	}

	public function test_a_link_that_is_not_an_http_address_is_left_out_of_the_message_and_the_envelope(): void {
		$error = new \WP_Error(
			'stonewright_promotion_approval_required',
			'Show the user the approval link and stop.',
			[
				'approval_required' => true,
				'agent_must_stop'   => true,
				'approval_url'      => 'javascript:alert(1)',
			]
		);

		$message = ErrorEnvelope::with_agent_visible_payload( $error )->get_error_message();
		$this->assertStringNotContainsString( 'javascript', $message );
		$this->assertStringContainsString( '"approval_required":true', $message );
		$this->assertArrayNotHasKey( 'approval_url', ErrorEnvelope::from_wp_error( $error )['error']['data'] );
	}

	public function test_the_envelope_keeps_the_approval_flags_and_link(): void {
		$error = new \WP_Error(
			'stonewright_promotion_approval_required',
			'Show the user the approval link and stop.',
			[
				'status'            => 403,
				'approval_required' => true,
				'agent_must_stop'   => true,
				'approval_url'      => 'https://example.test/wp-admin/admin.php?page=stonewright-skills&view=catalog&promotion=req1',
				'request_id'        => 'req1',
			]
		);

		$data = ErrorEnvelope::from_wp_error( $error )['error']['data'];

		$this->assertTrue( $data['approval_required'] );
		$this->assertTrue( $data['agent_must_stop'] );
		$this->assertSame( 'https://example.test/wp-admin/admin.php?page=stonewright-skills&view=catalog&promotion=req1', $data['approval_url'] );
		$this->assertArrayNotHasKey( 'request_id', $data );
	}

	public function test_an_error_without_approval_flags_gets_no_approval_fields(): void {
		$error = new \WP_Error( 'stonewright_something', 'Oops.', [ 'status' => 400, 'approval_url' => 'https://example.test/' ] );

		$this->assertSame( 'Oops.', ErrorEnvelope::with_agent_visible_payload( $error )->get_error_message() );
	}
}
