<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\ElementorV3;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\ElementorV3\ApplyBundle;
use Stonewright\WpMcp\Security\ConfirmationToken;

/**
 * Confirmation-token gate of the Elementor V3 apply-bundle ability.
 *
 * @covers \Stonewright\WpMcp\Abilities\ElementorV3\ApplyBundle
 */
final class ApplyBundleGateTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_posts'] = [
			801 => self::post( 801, 'Bundle target one' ),
			802 => self::post( 802, 'Bundle target two' ),
		];
		$GLOBALS['stonewright_test_post_meta_calls'] = [];
		$GLOBALS['stonewright_test_options']         = [ 'stonewright_mode' => 'development' ];
		$GLOBALS['stonewright_test_user_caps']       = [ 'edit_post' => true, 'edit_posts' => true ];
		$GLOBALS['stonewright_test_user_logged_in']  = true;
		$GLOBALS['stonewright_test_transients']      = [];
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_posts']           = [];
		$GLOBALS['stonewright_test_post_meta_calls'] = [];
		$GLOBALS['stonewright_test_options']         = [];
		$GLOBALS['stonewright_test_user_caps']       = [];
		$GLOBALS['stonewright_test_user_logged_in']  = false;
		$GLOBALS['stonewright_test_transients']      = [];
	}

	public function test_schema_takes_one_top_level_confirmation_token_and_none_per_write(): void {
		$schema = ( new ApplyBundle() )->input_schema();

		self::assertArrayHasKey( 'confirmation_token', $schema['properties'] );
		self::assertSame( 'string', $schema['properties']['confirmation_token']['type'] );
		self::assertArrayNotHasKey( 'confirmation_token', $schema['properties']['writes']['items']['properties'] );
	}

	public function test_production_safe_write_is_refused_without_a_token(): void {
		$GLOBALS['stonewright_test_options']['stonewright_mode'] = 'production-safe';

		$result = ( new ApplyBundle() )->execute( self::args() );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_confirmation_required', $result->get_error_code() );
		self::assertSame( [], $GLOBALS['stonewright_test_post_meta_calls'] );
	}

	public function test_production_safe_write_succeeds_with_a_top_level_token_for_the_whole_call(): void {
		$GLOBALS['stonewright_test_options']['stonewright_mode'] = 'production-safe';
		$ability = new ApplyBundle();
		$args    = self::args();
		$token   = ConfirmationToken::issue( $ability->name(), $args );

		$result = $ability->execute( $args + [ 'confirmation_token' => $token ] );

		self::assertIsArray(
			$result,
			'Expected array, got WP_Error: ' . ( $result instanceof \WP_Error ? $result->get_error_code() . ' ' . $result->get_error_message() : '' )
		);
		self::assertTrue( $result['ok'] );
		self::assertSame( 2, $result['applied'] );
		self::assertSame( 0, $result['failed'] );
		self::assertNotSame( [], $GLOBALS['stonewright_test_post_meta_calls'] );
	}

	public function test_production_safe_token_is_bound_to_the_arguments_of_the_call(): void {
		$GLOBALS['stonewright_test_options']['stonewright_mode'] = 'production-safe';
		$ability = new ApplyBundle();
		$token   = ConfirmationToken::issue( $ability->name(), self::args() );
		$changed = self::args();
		$changed['writes'][1]['spec']['page']['title'] = 'Another title';

		$result = $ability->execute( $changed + [ 'confirmation_token' => $token ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_confirmation_args_mismatch', $result->get_error_code() );
		self::assertSame( [], $GLOBALS['stonewright_test_post_meta_calls'] );
	}

	public function test_production_safe_token_cannot_be_used_twice(): void {
		$GLOBALS['stonewright_test_options']['stonewright_mode'] = 'production-safe';
		$ability = new ApplyBundle();
		$args    = self::args() + [ 'confirmation_token' => ConfirmationToken::issue( $ability->name(), self::args() ) ];

		self::assertIsArray( $ability->execute( $args ) );
		$replayed = $ability->execute( $args );

		self::assertInstanceOf( \WP_Error::class, $replayed );
		self::assertSame( 'stonewright_confirmation_replayed', $replayed->get_error_code() );
	}

	public function test_development_write_needs_no_token(): void {
		$result = ( new ApplyBundle() )->execute( self::args() );

		self::assertIsArray( $result );
		self::assertSame( 2, $result['applied'] );
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function args(): array {
		return [
			'writes' => [
				[ 'post_id' => 801, 'spec' => self::spec( 'One' ) ],
				[ 'post_id' => 802, 'spec' => self::spec( 'Two' ) ],
			],
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function spec( string $title ): array {
		return [
			'version'  => '1.0.0',
			'page'     => [ 'title' => $title ],
			'sections' => [
				[
					'id'     => 'hero',
					'blocks' => [
						[ 'type' => 'heading', 'text' => $title, 'level' => 1 ],
					],
				],
			],
		];
	}

	private static function post( int $id, string $title ): object {
		return (object) [
			'ID'           => $id,
			'post_type'    => 'page',
			'post_status'  => 'draft',
			'post_title'   => $title,
			'post_content' => '',
			'post_excerpt' => '',
			'meta'         => [
				'_elementor_data'      => '[{"id":"keep","elType":"container","settings":[],"elements":[]}]',
				'_elementor_edit_mode' => 'builder',
				'_elementor_version'   => defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : '3.0.0',
			],
		];
	}
}
