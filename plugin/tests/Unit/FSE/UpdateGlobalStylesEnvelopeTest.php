<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\FSE;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\FSE\CreateTemplatePart;
use Stonewright\WpMcp\Abilities\FSE\UpdateGlobalStyles;
use Stonewright\WpMcp\Abilities\FSE\UpdateTemplate;
use Stonewright\WpMcp\Abilities\FSE\WriteGlobalStyles;
use Stonewright\WpMcp\Security\ConfirmationToken;

/**
 * @covers \Stonewright\WpMcp\Abilities\FSE\UpdateGlobalStyles
 * @covers \Stonewright\WpMcp\Abilities\FSE\WriteGlobalStyles
 * @covers \Stonewright\WpMcp\FSE\GlobalStylesWriter
 */
final class UpdateGlobalStylesEnvelopeTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_options'] = [ 'stonewright_mode' => 'development' ];
		$GLOBALS['stonewright_test_user_caps'] = [
			'manage_options'     => true,
			'edit_theme_options' => true,
			'edit_pages'         => true,
		];
		$GLOBALS['stonewright_test_user_logged_in']  = true;
		$GLOBALS['stonewright_test_current_user_id'] = 1;
		$GLOBALS['stonewright_test_posts']           = [];
		$GLOBALS['stonewright_test_post_meta_calls'] = [];
		$GLOBALS['stonewright_test_audit_rows']      = [];
		unset( $GLOBALS['stonewright_test_update_post_meta_return'] );
	}

	public function test_update_is_compatibility_wrapper_for_write(): void {
		self::assertSame( 'stonewright/fse-update-global-styles', ( new UpdateGlobalStyles() )->name() );
		self::assertSame( 'stonewright/fse-write-global-styles', ( new WriteGlobalStyles() )->name() );
	}

	public function test_update_rejects_invalid_theme_json_like_write(): void {
		$write  = ( new WriteGlobalStyles() )->execute( [ 'theme_json' => [ 'version' => 'not-an-int' ] ] );
		$update = ( new UpdateGlobalStyles() )->execute(
			[
				'mode'     => 'replace',
				'settings' => [ 'unknownKey' => true ],
			]
		);

		self::assertInstanceOf( \WP_Error::class,$write );
		self::assertSame( 'stonewright_theme_json_invalid', $write->get_error_code() );
		self::assertInstanceOf( \WP_Error::class,$update );
		self::assertSame( 'stonewright_theme_json_invalid', $update->get_error_code() );
	}

	public function test_update_backups_and_keeps_id_for_callers(): void {
		$GLOBALS['stonewright_test_post_meta_calls'] = [];
		$result = ( new UpdateGlobalStyles() )->execute(
			[
				'mode'     => 'merge',
				'settings' => [
					'color' => [
						'palette' => [
							[ 'slug' => 'primary', 'color' => '#112233', 'name' => 'Primary' ],
						],
					],
				],
			]
		);

		self::assertIsArray( $result );
		self::assertArrayHasKey( 'id', $result );
		self::assertSame( 2, $result['id'] );
		self::assertNotEmpty( $result['snapshot_id'] );
		$backup_meta = array_filter(
			$GLOBALS['stonewright_test_post_meta_calls'],
			static fn( array $c ): bool => '_stonewright_backups' === ( $c['meta_key'] ?? '' )
		);
		self::assertNotEmpty( $backup_meta );
	}

	public function test_update_requires_token_in_production_safe(): void {
		$GLOBALS['stonewright_test_options']['stonewright_mode'] = 'production-safe';
		$blocked = ( new UpdateGlobalStyles() )->execute(
			[
				'settings' => [
					'color' => [
						'palette' => [
							[ 'slug' => 'primary', 'color' => '#112233', 'name' => 'Primary' ],
						],
					],
				],
			]
		);
		self::assertInstanceOf( \WP_Error::class,$blocked );
		self::assertSame( 'stonewright_confirmation_required', $blocked->get_error_code() );

		$args = [
			'settings' => [
				'color' => [
					'palette' => [
						[ 'slug' => 'primary', 'color' => '#112233', 'name' => 'Primary' ],
					],
				],
			],
		];
		$token  = ConfirmationToken::issue( 'stonewright/fse-update-global-styles', $args );
		$result = ( new UpdateGlobalStyles() )->execute( array_merge( $args, [ 'confirmation_token' => $token ] ) );
		self::assertIsArray( $result );
		self::assertSame( 2, $result['id'] );
	}

	public function test_an_empty_update_is_refused_and_writes_nothing(): void {
		$before  = \WP_Theme_JSON_Resolver::get_user_global_styles_post_id();
		$content = $GLOBALS['stonewright_test_posts'][ $before ]->post_content;
		$GLOBALS['stonewright_test_post_meta_calls'] = [];

		foreach ( [ [], [ 'mode' => 'replace' ], [ 'mode' => 'merge', 'settings' => [], 'styles' => [] ] ] as $args ) {
			$result = ( new UpdateGlobalStyles() )->execute( $args );

			self::assertInstanceOf( \WP_Error::class, $result, (string) wp_json_encode( $args ) );
			self::assertSame( 'stonewright_global_styles_empty', $result->get_error_code() );
		}
		self::assertSame( $content, $GLOBALS['stonewright_test_posts'][ $before ]->post_content );
		self::assertSame( [], $GLOBALS['stonewright_test_post_meta_calls'], 'No snapshot was taken.' );
	}

	public function test_a_list_in_an_update_replaces_the_stored_list_and_does_not_merge_by_index(): void {
		$id = \WP_Theme_JSON_Resolver::get_user_global_styles_post_id();
		$GLOBALS['stonewright_test_posts'][ $id ]->post_content = (string) wp_json_encode(
			[
				'version'  => 3,
				'settings' => [
					'color'      => [
						'palette' => [
							[ 'slug' => 'primary', 'color' => '#111111', 'name' => 'Primary' ],
							[ 'slug' => 'accent', 'color' => '#222222', 'name' => 'Accent' ],
							[ 'slug' => 'muted', 'color' => '#333333', 'name' => 'Muted' ],
						],
					],
					'layout'     => [ 'contentSize' => '720px' ],
				],
			]
		);

		$result = ( new UpdateGlobalStyles() )->execute(
			[
				'mode'     => 'merge',
				'settings' => [ 'color' => [ 'palette' => [ [ 'slug' => 'brand', 'color' => '#abcdef', 'name' => 'Brand' ] ] ] ],
			]
		);

		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_code() . ' ' . $result->get_error_message() : '' );
		$stored = json_decode( (string) $GLOBALS['stonewright_test_posts'][ $id ]->post_content, true );
		self::assertSame( [ [ 'slug' => 'brand', 'color' => '#abcdef', 'name' => 'Brand' ] ], $stored['settings']['color']['palette'], 'The palette is the list that was sent, not a mix of both.' );
		self::assertSame( '720px', $stored['settings']['layout']['contentSize'], 'Keys that were not sent are kept.' );
	}

	public function test_update_permission_matches_write(): void {
		$GLOBALS['stonewright_test_user_caps'] = [ 'edit_theme_options' => true ];
		self::assertFalse( ( new UpdateGlobalStyles() )->permission_callback( [] ) );
		self::assertFalse( ( new WriteGlobalStyles() )->permission_callback( [] ) );

		$GLOBALS['stonewright_test_user_caps'] = [
			'manage_options'     => true,
			'edit_theme_options' => true,
		];
		self::assertTrue( ( new UpdateGlobalStyles() )->permission_callback( [] ) );
		self::assertTrue( ( new WriteGlobalStyles() )->permission_callback( [] ) );
	}

	public function test_create_template_part_and_update_template_refuse_empty_content(): void {
		$created = ( new CreateTemplatePart() )->execute(
			[
				'slug'    => 'header',
				'title'   => 'Header',
				'content' => '   ',
				'area'    => 'header',
			]
		);
		self::assertInstanceOf( \WP_Error::class,$created );
		self::assertSame( 'stonewright_empty_content', $created->get_error_code() );

		$updated = ( new UpdateTemplate() )->execute(
			[
				'id'      => 'stonewright-theme//contract',
				'content' => '',
			]
		);
		self::assertInstanceOf( \WP_Error::class,$updated );
		self::assertSame( 'stonewright_empty_content', $updated->get_error_code() );
	}
}
