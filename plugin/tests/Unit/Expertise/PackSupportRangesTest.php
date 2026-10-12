<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Expertise;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Expertise\BundledPacks;
use Stonewright\WpMcp\Expertise\ExpertiseResolver;
use Stonewright\WpMcp\Expertise\ExpertiseStore;

/**
 * A bundled pack is offered only on runtimes inside its supported version ranges. Elementor 4 still
 * edits V3 documents beside atomic ones, so the packs that describe V3 documents stay available on
 * every released Elementor major, and the WordPress and WooCommerce ranges follow the plugin's own
 * minimums.
 *
 * @covers \Stonewright\WpMcp\Expertise\BundledPacks
 * @covers \Stonewright\WpMcp\Expertise\ExpertiseResolver
 */
final class PackSupportRangesTest extends TestCase {

	/** Packs that describe V3 documents, which Elementor 4 still edits. */
	private const V3_DOCUMENT_PACKS = [ 'elementor-v3', 'theme-builder', 'elementor-pro-dynamic' ];

	private mixed $original_wpdb;

	protected function setUp(): void {
		$this->original_wpdb = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb']     = new class() {
			public $prefix = 'wp_';
			public $insert_id = 10;
			public function get_charset_collate(): string { return 'DEFAULT CHARACTER SET utf8mb4'; }
			public function prepare( string $query, mixed ...$args ): string { return $query; }
			public function get_var( string $query ): mixed { return null; }
			/** @return list<array<string, mixed>> */
			public function get_results( string $query, string $output = 'OBJECT' ): array { return []; }
		};
		ExpertiseStore::reset_cache();
	}

	protected function tearDown(): void {
		if ( null !== $this->original_wpdb ) {
			$GLOBALS['wpdb'] = $this->original_wpdb;
		} else {
			unset( $GLOBALS['wpdb'] );
		}
		ExpertiseStore::reset_cache();
	}

	/** @return iterable<string, array{string, string}> */
	public static function v3_document_pack_on_supported_elementor(): iterable {
		foreach ( self::V3_DOCUMENT_PACKS as $id ) {
			foreach ( [ '3.20.0', '3.30.0', '4.0.0', '4.3.2', '4.9.9' ] as $version ) {
				yield $id . ' on Elementor ' . $version => [ $id, $version ];
			}
		}
	}

	/** @dataProvider v3_document_pack_on_supported_elementor */
	public function test_v3_document_packs_are_offered_on_elementor_3_and_4( string $id, string $elementor ): void {
		$result = ExpertiseResolver::compatibility( self::pack( $id ), self::runtime( [ 'elementor_core' => $elementor, 'elementor_pro' => $elementor ] ) );

		self::assertSame( [], $result['reasons'], $id . ' must be available on Elementor ' . $elementor );
	}

	/** @return iterable<string, array{string, string}> */
	public static function v3_document_pack_outside_the_reviewed_range(): iterable {
		foreach ( self::V3_DOCUMENT_PACKS as $id ) {
			foreach ( [ '3.19.9', '5.0.0' ] as $version ) {
				yield $id . ' on Elementor ' . $version => [ $id, $version ];
			}
		}
	}

	/** @dataProvider v3_document_pack_outside_the_reviewed_range */
	public function test_v3_document_packs_stay_hidden_outside_the_reviewed_range( string $id, string $elementor ): void {
		$result = ExpertiseResolver::compatibility( self::pack( $id ), self::runtime( [ 'elementor_core' => $elementor, 'elementor_pro' => '4.0.0' ] ) );

		self::assertFalse( $result['compatible'] );
		self::assertContains( 'elementor_core:version_mismatch', $result['reasons'] );
	}

	public function test_atomic_pack_needs_elementor_4(): void {
		$pack = self::pack( 'elementor-v4-atomic' );

		self::assertTrue( ExpertiseResolver::compatibility( $pack, self::runtime( [ 'elementor_core' => '4.0.0' ] ) )['compatible'] );
		self::assertFalse( ExpertiseResolver::compatibility( $pack, self::runtime( [ 'elementor_core' => '3.30.0' ] ) )['compatible'] );
	}

	public function test_resolver_offers_the_v3_pack_on_an_elementor_4_runtime(): void {
		$runtime = self::runtime( [ 'elementor_core' => '4.3.2', 'elementor_pro' => '4.3.2' ] );

		self::assertContains( 'elementor-v3', array_column( ExpertiseResolver::resolve( 'Edit Elementor V3 widgets', 'elementor', [], $runtime ), 'id' ) );
	}

	public function test_wordpress_range_starts_at_the_plugin_minimum_except_where_a_pack_needs_more(): void {
		foreach ( BundledPacks::all() as $pack ) {
			$constraint = (string) ( $pack['supported_versions']['wordpress'] ?? '' );
			self::assertMatchesRegularExpression( '/^>=\s*(\d+\.\d+(?:\.\d+)?)/', $constraint, (string) $pack['id'] . ' must pin WordPress.' );
			preg_match( '/^>=\s*(\d+\.\d+(?:\.\d+)?)/', $constraint, $match );

			self::assertTrue(
				version_compare( $match[1], STONEWRIGHT_MIN_WP, '>=' ),
				sprintf( '%s starts at WordPress %s, below the plugin minimum %s.', $pack['id'], $match[1], STONEWRIGHT_MIN_WP )
			);
		}
		foreach ( BundledPacks::all() as $pack ) {
			if ( 'gutenberg-advanced' !== $pack['id'] ) {
				self::assertSame( '>=' . STONEWRIGHT_MIN_WP, $pack['supported_versions']['wordpress'], (string) $pack['id'] );
			}
		}
	}

	public function test_woocommerce_packs_share_one_range(): void {
		$ranges = [];
		foreach ( BundledPacks::all() as $pack ) {
			if ( isset( $pack['supported_versions']['woocommerce'] ) ) {
				$ranges[ (string) $pack['id'] ] = $pack['supported_versions']['woocommerce'];
			}
		}

		self::assertGreaterThanOrEqual( 2, count( $ranges ) );
		self::assertCount( 1, array_unique( $ranges ), 'WooCommerce packs must not diverge.' );
	}

	/** @return array<string, mixed> */
	private static function pack( string $id ): array {
		$packs = array_column( BundledPacks::all(), null, 'id' );
		self::assertArrayHasKey( $id, $packs );
		return $packs[ $id ];
	}

	/**
	 * @param array<string, string> $versions Overrides for the component versions.
	 * @return array<string, mixed>
	 */
	private static function runtime( array $versions = [] ): array {
		$capabilities = [];
		foreach ( BundledPacks::all() as $pack ) {
			$capabilities = array_merge( $capabilities, $pack['required_capabilities'] );
		}
		return [
			'versions'     => array_merge(
				[ 'wordpress' => '7.1.2', 'php' => PHP_VERSION, 'elementor_core' => '3.30.0', 'elementor_pro' => '3.30.0', 'woocommerce' => '10.9.4', 'acf' => '6.4.0' ],
				$versions
			),
			'capabilities' => array_values( array_unique( $capabilities ) ),
			'fingerprint'  => str_repeat( 'a', 64 ),
		];
	}
}
