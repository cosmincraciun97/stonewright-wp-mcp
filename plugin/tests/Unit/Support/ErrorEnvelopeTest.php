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
				'token'     => 'secret-token',
				'password'  => 'secret-password',
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
}
