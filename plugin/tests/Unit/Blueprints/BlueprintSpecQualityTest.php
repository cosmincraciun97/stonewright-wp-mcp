<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Blueprints;

use PHPUnit\Framework\TestCase;

/**
 * Guardrail: bundled blueprints must use real layouts and unique copy.
 */
final class BlueprintSpecQualityTest extends TestCase {

	/**
	 * @return array<string, array{0: string}>
	 */
	public function blueprint_files(): array {
		$dir   = dirname( __DIR__, 3 ) . '/blueprints';
		$files = glob( $dir . '/*.json' ) ?: [];
		$out   = [];
		foreach ( $files as $file ) {
			$out[ basename( $file ) ] = [ $file ];
		}
		return $out;
	}

	/**
	 * @dataProvider blueprint_files
	 */
	public function test_spec_uses_layout_and_media( string $file ): void {
		$bp   = json_decode( (string) file_get_contents( $file ), true );
		$this->assertIsArray( $bp );
		$spec = $bp['spec'] ?? null;
		$this->assertIsArray( $spec );
		$counts = [
			'row'      => 0,
			'image'    => 0,
			'distinct' => [],
		];
		$walk = function ( array $blocks ) use ( &$walk, &$counts ): void {
			foreach ( $blocks as $b ) {
				if ( ! is_array( $b ) ) {
					continue;
				}
				$t = (string) ( $b['type'] ?? '' );
				$counts['distinct'][ $t ] = true;
				if ( 'row' === $t ) {
					++$counts['row'];
				}
				if ( 'image' === $t ) {
					++$counts['image'];
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

		$this->assertGreaterThanOrEqual( 2, $counts['row'], basename( $file ) . ': needs >=2 row layouts' );
		$this->assertGreaterThanOrEqual( 2, $counts['image'], basename( $file ) . ': needs >=2 images' );
		$extra = array_diff( array_keys( $counts['distinct'] ), [ 'heading', 'paragraph', 'button', 'row', 'column' ] );
		$this->assertGreaterThanOrEqual( 2, count( $extra ), basename( $file ) . ': needs >=2 block types beyond heading/paragraph/button' );
	}

	public function test_no_copy_duplicated_across_blueprints(): void {
		$dir   = dirname( __DIR__, 3 ) . '/blueprints';
		$files = glob( $dir . '/*.json' ) ?: [];
		$map   = []; // text => list of files

		$collect = function ( array $blocks, string $file ) use ( &$collect, &$map ): void {
			foreach ( $blocks as $b ) {
				if ( ! is_array( $b ) ) {
					continue;
				}
				$text = trim( (string) ( $b['text'] ?? '' ) );
				if ( strlen( $text ) > 40 ) {
					$map[ $text ][] = basename( $file );
				}
				if ( ! empty( $b['blocks'] ) && is_array( $b['blocks'] ) ) {
					$collect( $b['blocks'], $file );
				}
			}
		};

		foreach ( $files as $file ) {
			$bp = json_decode( (string) file_get_contents( $file ), true );
			foreach ( (array) ( $bp['spec']['sections'] ?? [] ) as $s ) {
				if ( is_array( $s ) ) {
					$collect( (array) ( $s['blocks'] ?? [] ), $file );
				}
			}
		}

		$dupes = [];
		foreach ( $map as $text => $owners ) {
			$owners = array_values( array_unique( $owners ) );
			if ( count( $owners ) > 1 ) {
				$dupes[] = mb_substr( $text, 0, 60 ) . ' in ' . implode( ',', $owners );
			}
		}
		$this->assertSame( [], $dupes, "duplicated copy across blueprints:\n" . implode( "\n", $dupes ) );
	}
}
