<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\FSE;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\FSE\Navigation;
use Stonewright\WpMcp\Security\ConfirmationToken;

/**
 * @covers \Stonewright\WpMcp\Abilities\FSE\Navigation
 */
final class NavigationAbilityTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_user_caps'] = [
			'manage_options'     => true,
			'edit_theme_options' => true,
			'edit_posts'         => true,
			'edit_post'          => true,
			'edit_pages'         => true,
		];
		$GLOBALS['stonewright_test_user_logged_in']  = true;
		$GLOBALS['stonewright_test_current_user_id'] = 1;
		$GLOBALS['stonewright_test_options']         = [ 'stonewright_mode' => 'development' ];
		$GLOBALS['stonewright_test_posts']           = [];
		$GLOBALS['stonewright_test_next_post_id']    = 4100;
		$GLOBALS['stonewright_test_inserted_posts']  = [];
		$GLOBALS['stonewright_test_post_meta_calls'] = [];
		$GLOBALS['stonewright_test_audit_rows']      = [];
	}

	public function test_ability_name_is_stable(): void {
		self::assertSame( 'stonewright/fse-navigation', ( new Navigation() )->name() );
	}

	public function test_create_read_update_round_trip(): void {
		$ability = new Navigation();
		$created = $ability->execute(
			[
				'action'  => 'create',
				'title'   => 'Primary',
				'content' => '<!-- wp:navigation-link {"label":"Home","url":"https://example.test/"} /-->',
			]
		);

		self::assertIsArray( $created );
		self::assertGreaterThan( 0, $created['id'] );
		self::assertNotEmpty( $created['snapshot_id'] );
		self::assertSame( 'wp_navigation', $GLOBALS['stonewright_test_posts'][ $created['id'] ]->post_type );

		$read = $ability->execute( [ 'action' => 'read', 'id' => $created['id'] ] );
		self::assertIsArray( $read );
		self::assertSame( $created['id'], $read['id'] );
		self::assertStringContainsString( 'Home', $read['content'] );
		self::assertSame( 'core/navigation', $read['block']['name'] );
		self::assertSame( $created['id'], $read['block']['attrs']['ref'] );

		$updated = $ability->execute(
			[
				'action'  => 'update',
				'id'      => $created['id'],
				'content' => '<!-- wp:navigation-link {"label":"About","url":"https://example.test/about/"} /-->',
			]
		);
		self::assertIsArray( $updated );
		self::assertNotEmpty( $updated['snapshot_id'] );
		self::assertStringContainsString( 'About', $GLOBALS['stonewright_test_posts'][ $created['id'] ]->post_content );
	}

	public function test_refuses_empty_content(): void {
		$result = ( new Navigation() )->execute(
			[
				'action'  => 'create',
				'title'   => 'Empty',
				'content' => '   ',
			]
		);
		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_empty_content', $result->get_error_code() );
	}

	public function test_requires_confirmation_in_production_safe(): void {
		$GLOBALS['stonewright_test_options']['stonewright_mode'] = 'production-safe';
		$result = ( new Navigation() )->execute(
			[
				'action'  => 'create',
				'title'   => 'Primary',
				'content' => '<!-- wp:navigation-link {"label":"Home","url":"https://example.test/"} /-->',
			]
		);
		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_confirmation_required', $result->get_error_code() );
	}

	public function test_accepts_valid_confirmation_token(): void {
		$GLOBALS['stonewright_test_options']['stonewright_mode'] = 'production-safe';
		$args = [
			'action'  => 'create',
			'title'   => 'Primary',
			'content' => '<!-- wp:navigation-link {"label":"Home","url":"https://example.test/"} /-->',
		];
		$token  = ConfirmationToken::issue( 'stonewright/fse-navigation', $args );
		$result = ( new Navigation() )->execute( array_merge( $args, [ 'confirmation_token' => $token ] ) );
		self::assertIsArray( $result );
		self::assertGreaterThan( 0, $result['id'] );
	}

	public function test_permission_uses_fse_caps(): void {
		$GLOBALS['stonewright_test_user_caps'] = [ 'edit_theme_options' => true ];
		self::assertFalse( ( new Navigation() )->permission_callback( [] ) );
		$GLOBALS['stonewright_test_user_caps'] = [
			'manage_options'     => true,
			'edit_theme_options' => true,
		];
		self::assertTrue( ( new Navigation() )->permission_callback( [] ) );
	}
}
