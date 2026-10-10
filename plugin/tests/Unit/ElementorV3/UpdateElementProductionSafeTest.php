<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\ElementorV3;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\ElementorV3\UpdateElement;
use Stonewright\WpMcp\Security\ConfirmationToken;

/**
 * In production-safe mode a dry run of elementor-v3-update-element needs no
 * confirmation token because it writes nothing; a write still needs a token
 * bound to its arguments.
 *
 * @covers \Stonewright\WpMcp\Abilities\ElementorV3\UpdateElement
 */
final class UpdateElementProductionSafeTest extends TestCase {

	private const ABILITY = 'stonewright/elementor-v3-update-element';

	protected function setUp(): void {
		$GLOBALS['stonewright_test_posts']          = [
			611 => (object) [
				'ID'           => 611,
				'post_type'    => 'page',
				'post_status'  => 'draft',
				'post_title'   => 'Token target',
				'post_content' => '',
				'post_excerpt' => '',
				'meta'         => [
					'_elementor_data'      => '[{"id":"root","elType":"container","settings":{"container_type":"flex"},"elements":[{"id":"head","elType":"widget","widgetType":"heading","settings":{"title":"Before"},"elements":[]}]}]',
					'_elementor_edit_mode' => 'builder',
					'_elementor_version'   => defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : '3.0.0',
				],
			],
		];
		$GLOBALS['stonewright_test_post_meta_calls'] = [];
		$GLOBALS['stonewright_test_options']         = [ 'stonewright_mode' => 'production-safe' ];
		$GLOBALS['stonewright_test_user_caps']       = [ 'edit_post' => true, 'edit_posts' => true ];
		$GLOBALS['stonewright_test_user_logged_in']  = true;
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_posts']           = [];
		$GLOBALS['stonewright_test_post_meta_calls'] = [];
		$GLOBALS['stonewright_test_options']         = [];
		$GLOBALS['stonewright_test_user_caps']       = [];
		$GLOBALS['stonewright_test_user_logged_in']  = false;
	}

	/** @return array<string, mixed> */
	private static function args( bool $dry_run ): array {
		return [
			'post_id'    => 611,
			'element_id' => 'head',
			'settings'   => [ 'title' => 'After' ],
			'dry_run'    => $dry_run,
		];
	}

	private static function stored_title(): string {
		$tree = json_decode( stripslashes( (string) $GLOBALS['stonewright_test_posts'][611]->meta['_elementor_data'] ), true );
		return (string) $tree[0]['elements'][0]['settings']['title'];
	}

	/** @return list<string> */
	private static function lock_rows(): array {
		return array_values(
			array_filter(
				array_keys( $GLOBALS['stonewright_test_options'] ),
				static fn ( $key ): bool => str_starts_with( (string) $key, 'stonewright_elementor_lock_' )
			)
		);
	}

	public function test_dry_run_needs_no_token_and_writes_nothing(): void {
		$result = ( new UpdateElement() )->execute( self::args( true ) );

		self::assertIsArray( $result );
		self::assertTrue( $result['dry_run'] );
		self::assertSame( '', $result['snapshot_id'] );
		self::assertSame( 'Before', self::stored_title() );
		self::assertSame( [], $GLOBALS['stonewright_test_post_meta_calls'], 'A dry run must not write post meta.' );
		self::assertSame( [], self::lock_rows(), 'A dry run must not take the write lock.' );
		self::assertSame( [], (array) ( $GLOBALS['stonewright_test_posts'][611]->meta['_stonewright_backups'] ?? [] ), 'A dry run must not take a snapshot.' );
	}

	public function test_write_without_a_token_is_refused(): void {
		$result = ( new UpdateElement() )->execute( self::args( false ) );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_confirmation_required', $result->get_error_code() );
		self::assertSame( 'Before', self::stored_title() );
		self::assertSame( [], $GLOBALS['stonewright_test_post_meta_calls'] );
	}

	public function test_write_without_dry_run_flag_is_refused_without_a_token(): void {
		$args = self::args( false );
		unset( $args['dry_run'] );

		$result = ( new UpdateElement() )->execute( $args );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_confirmation_required', $result->get_error_code() );
		self::assertSame( 'Before', self::stored_title() );
	}

	public function test_write_with_a_bound_token_succeeds_and_snapshots(): void {
		$args  = self::args( false );
		$token = ConfirmationToken::issue( self::ABILITY, $args );

		$result = ( new UpdateElement() )->execute( $args + [ 'confirmation_token' => $token ] );

		self::assertIsArray( $result );
		self::assertFalse( $result['dry_run'] );
		self::assertNotSame( '', $result['snapshot_id'] );
		self::assertSame( 'After', self::stored_title() );
	}

	public function test_write_with_a_token_for_other_arguments_is_refused(): void {
		$other = self::args( false );
		$other['settings']['title'] = 'Another';
		$token = ConfirmationToken::issue( self::ABILITY, $other );

		$result = ( new UpdateElement() )->execute( self::args( false ) + [ 'confirmation_token' => $token ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_confirmation_args_mismatch', $result->get_error_code() );
		self::assertSame( 'Before', self::stored_title() );
	}

	public function test_a_dry_run_token_cannot_authorise_the_write(): void {
		$token = ConfirmationToken::issue( self::ABILITY, self::args( true ) );

		$result = ( new UpdateElement() )->execute( self::args( false ) + [ 'confirmation_token' => $token ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'Before', self::stored_title() );
	}
}
