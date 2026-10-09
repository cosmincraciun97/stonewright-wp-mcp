<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Design;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\Design\NormalizeAssets;
use Stonewright\WpMcp\Security\ConfirmationToken;

/**
 * In production-safe mode, sideloading media needs a confirmation token bound to the call; a
 * call that sideloads nothing does not.
 *
 * @covers \Stonewright\WpMcp\Abilities\Design\NormalizeAssets
 */
final class NormalizeAssetsTokenTest extends TestCase {

	private const URL = 'https://cdn.example.com/hero.png';

	protected function setUp(): void {
		$GLOBALS['stonewright_test_asset_responses'] = [
			self::URL => [
				'response' => [ 'code' => 200 ],
				'headers'  => [ 'content-type' => 'image/png' ],
				'body'     => str_repeat( "\x89PNG\r\n", 30 ),
			],
		];
		$GLOBALS['stonewright_test_options']         = [ 'stonewright_mode' => 'production-safe' ];
		$GLOBALS['stonewright_test_transients']      = [];
		$GLOBALS['stonewright_test_next_post_id']    = 5001;
		$GLOBALS['stonewright_test_posts']           = [];
		$GLOBALS['stonewright_test_post_meta_calls'] = [];
		$GLOBALS['stonewright_test_wpdb_inserts']    = [];
		$GLOBALS['stonewright_test_current_user_id'] = 42;
		wp_mkdir_p( WP_CONTENT_DIR . '/uploads' );
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_asset_responses'] = [];
		$GLOBALS['stonewright_test_options']         = [];
		$GLOBALS['stonewright_test_transients']      = [];
		$GLOBALS['stonewright_test_posts']           = [];
		$GLOBALS['stonewright_test_current_user_id'] = 0;
	}

	/** @return array<string, mixed> */
	private static function args( array $extra = [] ): array {
		return array_merge(
			[ 'spec' => [ 'assets' => [ [ 'id' => 'hero', 'url' => self::URL ] ] ] ],
			$extra
		);
	}

	private static function sideloads_recorded(): int {
		return count(
			array_filter(
				$GLOBALS['stonewright_test_wpdb_inserts'],
				static fn ( $row ) => 'stonewright/design.asset_sideload' === ( $row['data']['ability_name'] ?? '' )
			)
		);
	}

	public function test_a_sideloading_call_without_a_token_is_refused_before_anything_is_fetched(): void {
		$result = ( new NormalizeAssets() )->execute( self::args() );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_confirmation_required', $result->get_error_code() );
		self::assertSame( 0, self::sideloads_recorded() );
		self::assertSame( [], $GLOBALS['stonewright_test_posts'], 'No attachment is created.' );
	}

	public function test_an_explicit_sideload_true_needs_the_token_too(): void {
		$result = ( new NormalizeAssets() )->execute( self::args( [ 'sideload' => true ] ) );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_confirmation_required', $result->get_error_code() );
	}

	public function test_a_token_issued_for_other_arguments_is_refused(): void {
		$other = [ 'spec' => [ 'assets' => [ [ 'id' => 'hero', 'url' => 'https://cdn.example.com/other.png' ] ] ] ];
		$token = ConfirmationToken::issue( 'stonewright/design-normalize-assets', $other );

		$result = ( new NormalizeAssets() )->execute( self::args( [ 'confirmation_token' => $token ] ) );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 0, self::sideloads_recorded() );
	}

	public function test_a_token_bound_to_the_call_lets_it_sideload_once(): void {
		$args  = self::args();
		$token = ConfirmationToken::issue( 'stonewright/design-normalize-assets', $args );

		$result = ( new NormalizeAssets() )->execute( $args + [ 'confirmation_token' => $token ] );

		self::assertIsArray( $result );
		self::assertSame( 1, $result['replaced'] );
		self::assertSame( 1, self::sideloads_recorded() );

		$replay = ( new NormalizeAssets() )->execute( $args + [ 'confirmation_token' => $token ] );
		self::assertInstanceOf( \WP_Error::class, $replay );
		self::assertSame( 1, self::sideloads_recorded(), 'A used token fetches nothing more.' );
	}

	public function test_a_call_that_does_not_sideload_needs_no_token(): void {
		$result = ( new NormalizeAssets() )->execute( self::args( [ 'sideload' => false ] ) );

		self::assertIsArray( $result );
		self::assertSame( 0, $result['replaced'] );
		self::assertSame( 0, self::sideloads_recorded() );
	}

	public function test_outside_production_safe_no_token_is_needed(): void {
		$GLOBALS['stonewright_test_options']['stonewright_mode'] = 'development';

		$result = ( new NormalizeAssets() )->execute( self::args() );

		self::assertIsArray( $result );
		self::assertSame( 1, $result['replaced'] );
	}

	public function test_the_input_schema_declares_the_token(): void {
		self::assertArrayHasKey( 'confirmation_token', ( new NormalizeAssets() )->input_schema()['properties'] );
	}
}
