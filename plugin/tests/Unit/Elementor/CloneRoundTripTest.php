<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Elementor;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\DesignSpec\QaReport;

/**
 * Lightweight clone-path proof: rich DesignSpec QA + structural expectations.
 * Full PageDigest→write→digest needs Elementor stubs; this gates quality of clone specs.
 */
final class CloneRoundTripTest extends TestCase {

	public function test_construction_blueprint_passes_qa_and_has_clone_friendly_types(): void {
		$path = dirname( __DIR__, 3 ) . '/blueprints/construction.json';
		$bp   = json_decode( (string) file_get_contents( $path ), true );
		self::assertIsArray( $bp );
		$spec = $bp['spec'];
		self::assertIsArray( $spec );

		$types = [];
		$walk  = function ( array $blocks ) use ( &$walk, &$types ): void {
			foreach ( $blocks as $b ) {
				if ( ! is_array( $b ) ) {
					continue;
				}
				$t = (string) ( $b['type'] ?? '' );
				if ( '' !== $t ) {
					$types[ $t ] = true;
				}
				if ( ! empty( $b['blocks'] ) && is_array( $b['blocks'] ) ) {
					$walk( $b['blocks'] );
				}
			}
		};
		foreach ( (array) ( $spec['sections'] ?? [] ) as $s ) {
			if ( is_array( $s ) ) {
				$walk( (array) ( $s['blocks'] ?? [] ) );
			}
		}

		self::assertArrayHasKey( 'row', $types );
		self::assertArrayHasKey( 'image', $types );
		self::assertArrayHasKey( 'heading', $types );
		self::assertArrayNotHasKey( 'html', $types );

		$qa = QaReport::for_spec( $spec );
		self::assertGreaterThanOrEqual( 80, $qa['score'], wp_json_encode( $qa['issues'] ) );
	}
}
