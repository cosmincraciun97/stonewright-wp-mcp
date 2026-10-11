<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\WooCommerce;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\WooCommerce\WcTermSave;
use Stonewright\WpMcp\WooCommerce\WooRuntime;

require_once dirname( __DIR__, 2 ) . '/Support/other-families-wp-stubs.php';

/**
 * The readback after a catalog term save compares what core stores, not what was sent: special characters
 * are stored as entities and a taken slug gets a suffix. Neither is a failed save.
 *
 * @covers \Stonewright\WpMcp\Abilities\WooCommerce\WcTermSave
 */
final class WcTermSaveReadbackTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_user_logged_in'] = true;
		$GLOBALS['stonewright_test_user_caps']      = [ 'manage_options' => true ];
		$GLOBALS['stonewright_test_options']        = [ 'stonewright_mode' => 'development' ];
		$GLOBALS['stonewright_test_taxonomies']     = [ 'product_cat' => true ];
		$GLOBALS['stonewright_test_terms']          = [];
		WooRuntime::set_test_overrides( [ 'available' => static fn (): bool => true ] );
	}

	protected function tearDown(): void {
		WooRuntime::reset_test_overrides();
		unset( $GLOBALS['stonewright_test_insert_term_transform'] );
		$GLOBALS['stonewright_test_taxonomies'] = [];
		$GLOBALS['stonewright_test_terms']      = [];
		$GLOBALS['stonewright_test_options']    = [];
	}

	private function save( array $args ): array|\WP_Error {
		return ( new WcTermSave() )->execute( $args + [ 'taxonomy' => 'product_cat', 'dry_run' => false ] );
	}

	public function test_a_name_with_an_ampersand_that_core_stores_as_an_entity_is_a_successful_save(): void {
		$GLOBALS['stonewright_test_insert_term_transform'] = static fn ( string $name, string $slug ): array => [ htmlspecialchars( $name, ENT_QUOTES ), $slug ];

		$result = $this->save( [ 'name' => 'Tom & Jerry\'s Mugs' ] );

		self::assertIsArray( $result );
		self::assertSame( 'applied', $result['execution_status'] );
		self::assertTrue( $result['effect_verified'] );
	}

	public function test_a_slug_that_core_made_unique_is_a_successful_save_and_is_reported(): void {
		$GLOBALS['stonewright_test_insert_term_transform'] = static fn ( string $name, string $slug ): array => [ $name, $slug . '-2' ];

		$result = $this->save( [ 'name' => 'Stone', 'slug' => 'stone' ] );

		self::assertIsArray( $result );
		self::assertTrue( $result['effect_verified'] );
		self::assertSame( 'stone-2', $result['slug_adjusted'] );
		self::assertSame( 'stone-2', $result['term']['slug'] );
	}

	public function test_a_slug_that_is_only_a_prefix_of_the_stored_one_is_not_mistaken_for_a_suffix(): void {
		$GLOBALS['stonewright_test_insert_term_transform'] = static fn ( string $name, string $slug ): array => [ $name, 'unrelated' ];

		$result = $this->save( [ 'name' => 'Stone', 'slug' => 'stone' ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_wc_term_readback_mismatch', $result->get_error_code() );
		self::assertSame( [ 'slug' ], $result->get_error_data()['mismatch_fields'] );
	}

	public function test_a_different_stored_name_is_still_a_mismatch(): void {
		$GLOBALS['stonewright_test_insert_term_transform'] = static fn ( string $name, string $slug ): array => [ 'Something else', $slug ];

		$result = $this->save( [ 'name' => 'Stone' ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_wc_term_readback_mismatch', $result->get_error_code() );
		self::assertSame( [ 'name' ], $result->get_error_data()['mismatch_fields'] );
	}
}
