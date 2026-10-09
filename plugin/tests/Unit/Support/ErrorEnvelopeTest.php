<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Support\ErrorEnvelope;

/**
 * @covers \Stonewright\WpMcp\Support\ErrorEnvelope
 */
final class ErrorEnvelopeTest extends TestCase {

	public function test_error_envelope_preserves_repairable_widget_validation_data(): void {
		$error = new \WP_Error(
			'stonewright_invalid_settings',
			'Widget settings failed validation.',
			[
				'status'    => 400,
				'widget'    => 'heading',
				'violations' => [
					[
						'path'     => 'settings.title',
						'code'     => 'required_missing',
						'expected' => 'non-empty value',
						'got'      => null,
					],
				],
				'token'     => 'test-secret-token',
				'password'  => 'test-secret-password',
				'spec'      => [ 'should' => 'stay-private' ],
			]
		);

		$envelope = ErrorEnvelope::from_wp_error( $error );
		$data     = $envelope['error']['data'] ?? [];

		self::assertSame( 400, $data['status'] );
		self::assertSame( 'heading', $data['widget'] );
		self::assertSame( 'settings.title', $data['violations'][0]['path'] );
		self::assertSame( 'required_missing', $data['violations'][0]['code'] );
		self::assertArrayNotHasKey( 'token', $data );
		self::assertArrayNotHasKey( 'password', $data );
		self::assertArrayNotHasKey( 'spec', $data );
	}

	public function test_schema_requests_survive_envelope_and_mcp_message(): void {
		$error = new \WP_Error(
			'stonewright_batch_operation_failed',
			'Elementor setting evidence is incomplete or stale.',
			[
				'status'          => 400,
				'cause_code'      => 'stonewright_elementor_evidence_invalid',
				'setting'         => 'title',
				'widget_type'     => 'heading',
				'schema_requests' => [
					[
						'ability' => 'stonewright/elementor-schema',
						'input'   => [
							'mode'        => 'summary',
							'widget_type' => 'heading',
							'query'       => 'title',
						],
					],
				],
				'token'           => 'must-not-leak',
			]
		);

		$envelope = ErrorEnvelope::from_wp_error( $error );
		$data     = $envelope['error']['data'] ?? [];
		self::assertSame( 'stonewright/elementor-schema', $data['schema_requests'][0]['ability'] );
		self::assertSame( 'heading', $data['widget_type'] );
		self::assertArrayNotHasKey( 'token', $data );

		$visible = ErrorEnvelope::with_agent_visible_payload( $error );
		self::assertStringContainsString( '"schema_requests"', $visible->get_error_message() );
		self::assertStringContainsString( 'elementor-schema', $visible->get_error_message() );
		self::assertSame( 'must-not-leak', $visible->get_error_data()['token'] );
	}

	public function test_nested_item_schema_request_is_copied_into_mcp_message(): void {
		$error = new \WP_Error(
			'stonewright_batch_operation_failed',
			'Elementor batch operation 0 (update_element) failed.',
			[
				'items' => [
					[
						'ok'    => false,
						'error' => [
							'data' => [
								'schema_request' => [
									'ability' => 'stonewright/elementor-v3-container-schema',
									'input'   => [ 'query' => 'padding' ],
								],
							],
						],
					],
				],
			]
		);

		$visible = ErrorEnvelope::with_agent_visible_payload( $error );
		self::assertStringContainsString( 'elementor-v3-container-schema', $visible->get_error_message() );
	}

	public function test_a_failed_writes_change_set_id_reaches_the_agent_message_and_the_rest_envelope(): void {
		$error = new \WP_Error(
			'stonewright_readback_mismatch',
			'Elementor write readback did not match the compiled tree.',
			[
				'status'        => 500,
				'write_receipt' => [ 'change_set_id' => 'cs-failed-write', 'snapshot_id' => 'snap_x' ],
				'change_set'    => [
					'schema'        => 'ChangeSetV1',
					'change_set_id' => 'cs-failed-write',
					'planned'       => [ [ 'kind' => 'element', 'ref' => 'hero', 'action' => 'update_element' ] ],
				],
				'token'         => 'must-not-leak',
			]
		);

		$visible = ErrorEnvelope::with_agent_visible_payload( $error );
		self::assertStringContainsString( '"change_set_id":"cs-failed-write"', $visible->get_error_message() );
		self::assertStringNotContainsString( 'hero', $visible->get_error_message(), 'Only the identifier is copied into the message.' );
		self::assertStringNotContainsString( 'must-not-leak', $visible->get_error_message() );
		self::assertSame( 'cs-failed-write', $visible->get_error_data()['change_set']['change_set_id'], 'The PHP error data keeps the whole change set.' );

		$data = ErrorEnvelope::from_wp_error( $error )['error']['data'];
		self::assertSame( 'cs-failed-write', $data['change_set_id'] );
		self::assertArrayNotHasKey( 'change_set', $data );
		self::assertArrayNotHasKey( 'write_receipt', $data );
		self::assertArrayNotHasKey( 'token', $data );
	}

	public function test_a_change_set_id_is_bounded_and_only_a_string_is_copied(): void {
		$long = new \WP_Error( 'x', 'Failed.', [ 'change_set' => [ 'change_set_id' => str_repeat( 'a', 200 ) ] ] );
		self::assertSame( str_repeat( 'a', 96 ), ErrorEnvelope::from_wp_error( $long )['error']['data']['change_set_id'] );
		self::assertStringContainsString( '"change_set_id":"' . str_repeat( 'a', 96 ) . '"', ErrorEnvelope::with_agent_visible_payload( $long )->get_error_message() );

		foreach ( [ [ 'change_set' => [ 'change_set_id' => 42 ] ], [ 'change_set' => 'not an array' ], [ 'change_set' => [ 'change_set_id' => '' ] ] ] as $data ) {
			$error = new \WP_Error( 'x', 'Failed.', $data );
			self::assertArrayNotHasKey( 'data', ErrorEnvelope::from_wp_error( $error )['error'] );
			self::assertSame( 'Failed.', ErrorEnvelope::with_agent_visible_payload( $error )->get_error_message() );
		}
	}

	public function test_retry_guidance_reaches_the_message_and_the_rest_envelope_without_lock_internals(): void {
		$error = new \WP_Error(
			'stonewright_elementor_write_busy',
			'Another Elementor transaction is writing this post.',
			[
				'status'              => 409,
				'retryable'           => true,
				'retry_after'         => 12,
				'retry_after_seconds' => 12,
				'lock_fingerprint'    => 'abc',
				'lock_expires_at'     => 1700000000,
			]
		);

		$visible = ErrorEnvelope::with_agent_visible_payload( $error );
		self::assertSame( 'Another Elementor transaction is writing this post. {"retryable":true,"retry_after":12}', $visible->get_error_message() );

		$data = ErrorEnvelope::from_wp_error( $error )['error']['data'];
		self::assertTrue( $data['retryable'] );
		self::assertSame( 12, $data['retry_after'] );
		self::assertArrayNotHasKey( 'lock_fingerprint', $data );
		self::assertArrayNotHasKey( 'lock_expires_at', $data );
	}

	public function test_retry_after_seconds_alone_is_reported_as_retry_after_and_is_bounded(): void {
		$error = new \WP_Error( 'x', 'Busy.', [ 'retryable' => true, 'retry_after_seconds' => 99999 ] );
		self::assertSame( 'Busy. {"retryable":true,"retry_after":3600}', ErrorEnvelope::with_agent_visible_payload( $error )->get_error_message() );

		$not_numeric = new \WP_Error( 'x', 'Busy.', [ 'retryable' => 'yes', 'retry_after' => 'soon' ] );
		self::assertSame( 'Busy. {"retryable":true}', ErrorEnvelope::with_agent_visible_payload( $not_numeric )->get_error_message() );

		$negative = new \WP_Error( 'x', 'Busy.', [ 'retry_after' => -5 ] );
		self::assertSame( 'Busy.', ErrorEnvelope::with_agent_visible_payload( $negative )->get_error_message() );
	}

	/** @return array<string, array{0:string}> */
	public static function rescue_error_codes(): array {
		return [
			'rolled back'     => [ 'stonewright_rescue_write_rolled_back' ],
			'rollback failed' => [ 'stonewright_rescue_rollback_failed' ],
		];
	}

	/**
	 * @dataProvider rescue_error_codes
	 */
	public function test_a_rescue_error_carries_its_code_and_safe_fields_in_the_agent_message( string $code ): void {
		$error = new \WP_Error(
			$code,
			'The site stopped loading after this change.',
			[
				'status'              => 500,
				'retryable'           => false,
				'rollback_status'     => 'succeeded',
				'incident_id'         => 'cs-abc123',
				'change_set_id'       => 'cs-abc123',
				'site_status'         => 'healthy',
				'original_error_code' => 'stonewright_plugin_activation_failed',
				'probe'               => [ 'status' => 'failed', 'legs' => [ [ 'leg' => 'home', 'url' => 'https://example.test/?sw_probe=secret' ] ] ],
				'resource_type'       => 'plugin',
				'root_error_code'     => 'stonewright_rescue_probe_failed',
				'token'               => 'must-not-leak',
			]
		);

		$visible = ErrorEnvelope::with_agent_visible_payload( $error );

		$message = $visible->get_error_message();
		self::assertSame( $code, $visible->get_error_code() );
		self::assertStringContainsString( '"code":"' . $code . '"', $message );
		self::assertStringContainsString( '"change_set_id":"cs-abc123"', $message );
		self::assertStringContainsString( '"incident_id":"cs-abc123"', $message );
		self::assertStringContainsString( '"rollback_status":"succeeded"', $message );
		self::assertStringContainsString( '"site_status":"healthy"', $message );
		self::assertStringContainsString( '"original_error_code":"stonewright_plugin_activation_failed"', $message );
		foreach ( [ 'must-not-leak', 'sw_probe', 'example.test', 'resource_type', 'root_error_code', '"probe"', '"status"' ] as $internal ) {
			self::assertStringNotContainsString( $internal, $message, $internal );
		}
		self::assertSame( 'must-not-leak', $visible->get_error_data()['token'], 'The PHP error data is untouched.' );
	}

	public function test_only_a_rescue_error_gets_a_code_in_its_message(): void {
		$error = new \WP_Error( 'stonewright_elementor_write_busy', 'Busy.', [ 'retryable' => true, 'site_status' => 'healthy', 'rollback_status' => 'succeeded' ] );

		$message = ErrorEnvelope::with_agent_visible_payload( $error )->get_error_message();

		self::assertSame( 'Busy. {"retryable":true}', $message );
	}

	public function test_rescue_fields_are_bounded_strings(): void {
		$error = new \WP_Error(
			'stonewright_rescue_write_rolled_back',
			'Rolled back.',
			[
				'change_set_id'   => str_repeat( 'a', 200 ),
				'site_status'     => [ 'not' => 'a string' ],
				'rollback_status' => str_repeat( 'b', 200 ),
			]
		);

		$message = ErrorEnvelope::with_agent_visible_payload( $error )->get_error_message();

		self::assertStringContainsString( '"change_set_id":"' . str_repeat( 'a', 96 ) . '"', $message );
		self::assertStringNotContainsString( 'not', $message );
		self::assertStringNotContainsString( str_repeat( 'b', 100 ), $message );
	}
}
