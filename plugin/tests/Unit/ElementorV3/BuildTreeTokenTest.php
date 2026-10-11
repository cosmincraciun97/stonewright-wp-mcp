<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\ElementorV3;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\ElementorV3\BuildTree;
use Stonewright\WpMcp\Security\ConfirmationToken;
use Stonewright\WpMcp\Support\ElementorData;

/**
 * elementor-build-tree: in production-safe mode a confirmation token is bound to the exact tree it was issued for,
 * a dry run needs none, and a document that holds Elementor V4 Atomic nodes is refused.
 *
 * @covers \Stonewright\WpMcp\Abilities\ElementorV3\BuildTree
 */
final class BuildTreeTokenTest extends TestCase {

	private const POST     = 770;
	private const ABILITY  = 'stonewright/elementor-build-tree';
	private const EXISTING = '[{"id":"root","elType":"container","settings":{"container_type":"flex"},"elements":[]}]';

	protected function setUp(): void {
		$GLOBALS['stonewright_test_posts']           = [
			self::POST => (object) [
				'ID'           => self::POST,
				'post_type'    => 'page',
				'post_status'  => 'draft',
				'post_title'   => 'Tree target',
				'post_content' => '',
				'post_excerpt' => '',
				'meta'         => [
					'_elementor_data'      => self::EXISTING,
					'_elementor_edit_mode' => 'builder',
					'_elementor_version'   => defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : '3.0.0',
				],
			],
		];
		$GLOBALS['stonewright_test_post_meta_calls'] = [];
		$GLOBALS['stonewright_test_options']         = [ 'stonewright_mode' => 'production-safe' ];
		$GLOBALS['stonewright_test_user_caps']       = [ 'edit_post' => true, 'edit_posts' => true, 'manage_options' => true ];
		$GLOBALS['stonewright_test_user_logged_in']  = true;
		$GLOBALS['stonewright_test_current_user_id'] = 9;
		$GLOBALS['stonewright_test_transients']      = [];
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_posts']           = [];
		$GLOBALS['stonewright_test_post_meta_calls'] = [];
		$GLOBALS['stonewright_test_options']         = [];
		$GLOBALS['stonewright_test_user_caps']       = [];
		$GLOBALS['stonewright_test_user_logged_in']  = false;
		$GLOBALS['stonewright_test_current_user_id'] = 0;
		$GLOBALS['stonewright_test_transients']      = [];
	}

	/** @return list<array<string, mixed>> */
	private static function tree( string $title ): array {
		return [
			[
				'id'       => 'hero',
				'elType'   => 'container',
				'settings' => [ 'container_type' => 'flex' ],
				'elements' => [
					[ 'id' => 'h1', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => [ 'title' => $title ], 'elements' => [] ],
				],
			],
		];
	}

	/** @return array<string, mixed> */
	private static function args( string $title ): array {
		return [ 'post_id' => self::POST, 'tree' => self::tree( $title ) ];
	}

	public function test_a_token_for_one_tree_does_not_authorise_another_tree(): void {
		$token = ConfirmationToken::issue( self::ABILITY, self::args( 'Approved headline' ) );

		$result = ( new BuildTree() )->execute( self::args( 'Another headline' ) + [ 'confirmation_token' => $token ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_confirmation_args_mismatch', $result->get_error_code() );
		self::assertSame( self::EXISTING, $GLOBALS['stonewright_test_posts'][ self::POST ]->meta['_elementor_data'], 'Nothing was written.' );
	}

	public function test_a_token_issued_for_only_the_post_and_dry_run_no_longer_works(): void {
		$token = ConfirmationToken::issue( self::ABILITY, [ 'post_id' => self::POST, 'dry_run' => false ] );

		$result = ( new BuildTree() )->execute( self::args( 'Headline' ) + [ 'confirmation_token' => $token ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_confirmation_args_mismatch', $result->get_error_code() );
	}

	public function test_a_token_for_the_exact_tree_authorises_the_write_once(): void {
		$token = ConfirmationToken::issue( self::ABILITY, self::args( 'Approved headline' ) );

		$result = ( new BuildTree() )->execute( self::args( 'Approved headline' ) + [ 'confirmation_token' => $token ] );

		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_code() : '' );
		self::assertTrue( $result['ok'] );
		self::assertSame( 'Approved headline', ElementorData::read( self::POST )[0]['elements'][0]['settings']['title'] );

		$replay = ( new BuildTree() )->execute( self::args( 'Approved headline' ) + [ 'confirmation_token' => $token ] );
		self::assertInstanceOf( \WP_Error::class, $replay );
	}

	public function test_a_write_without_a_token_is_refused_in_production_safe_mode(): void {
		$result = ( new BuildTree() )->execute( self::args( 'Headline' ) );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_confirmation_required', $result->get_error_code() );
	}

	public function test_a_dry_run_needs_no_token(): void {
		$result = ( new BuildTree() )->execute( self::args( 'Headline' ) + [ 'dry_run' => true ] );

		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_code() : '' );
		self::assertTrue( $result['dry_run'] );
		self::assertSame( [], $GLOBALS['stonewright_test_post_meta_calls'] );
	}

	/**
	 * @return iterable<string, array{0:bool}>
	 */
	public static function modes(): iterable {
		yield 'dry run' => [ true ];
		yield 'write' => [ false ];
	}

	/**
	 * @dataProvider modes
	 */
	public function test_a_document_with_v4_atomic_nodes_is_refused( bool $dry_run ): void {
		$GLOBALS['stonewright_test_options']['stonewright_mode'] = 'development';
		$atomic = '[{"id":"root","elType":"container","settings":{},"elements":[{"id":"a1","elType":"widget","widgetType":"e-heading","settings":{},"elements":[]}]}]';
		$GLOBALS['stonewright_test_posts'][ self::POST ]->meta['_elementor_data'] = $atomic;

		$result = ( new BuildTree() )->execute( self::args( 'Headline' ) + ( $dry_run ? [ 'dry_run' => true ] : [] ) );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_v3_architecture_mismatch', $result->get_error_code() );
		self::assertSame( $atomic, $GLOBALS['stonewright_test_posts'][ self::POST ]->meta['_elementor_data'] );
		self::assertSame( [], $GLOBALS['stonewright_test_post_meta_calls'], 'Nothing was snapshotted or written.' );
	}
}
