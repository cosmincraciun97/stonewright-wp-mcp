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
}
