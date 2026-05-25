<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\Design\ApplyToPost;
use Stonewright\WpMcp\Security\ConfirmationToken;

/**
 * Integration tests for stonewright/design.apply_to_post.
 *
 * Asserts:
 *   - Backup snapshot called BEFORE _elementor_data write.
 *   - _elementor_data is written on success.
 *   - Sideloaded asset URLs replace original URLs in the spec fed to writer.
 *   - Confirmation token enforced in production-safe mode.
 *   - Permission denial works correctly.
 *
 * @covers \Stonewright\WpMcp\Abilities\Design\ApplyToPost
 * @covers \Stonewright\WpMcp\DesignSpec\AssetSideloader
 * @covers \Stonewright\WpMcp\Elementor\ElementorWriter
 * @covers \Stonewright\WpMcp\Security\Backup
 */
final class DesignApplyTest extends TestCase {

	private static ApplyToPost $ability;
	private const POST_ID = 8001;

	/** @var array<string, mixed> */
	private static array $valid_spec = [
		'version'  => '1.0.0',
		'page'     => [ 'title' => 'Apply Test Page' ],
		'sections' => [
			[
				'id'     => 'hero',
				'blocks' => [
					[ 'type' => 'heading', 'text' => 'Hello World', 'level' => 1 ],
				],
			],
		],
	];

	public static function setUpBeforeClass(): void {
		self::$ability = new ApplyToPost();
	}

	protected function setUp(): void {
		$GLOBALS['stonewright_test_user_caps'] = [
			'manage_options' => true,
			'edit_pages'     => true,
		];
		$GLOBALS['stonewright_test_user_can_callback'] = null;
		$GLOBALS['stonewright_test_user_logged_in']    = true;
		$GLOBALS['stonewright_test_current_user_id']   = 1;
		$GLOBALS['stonewright_test_options']            = [
			'stonewright_mode' => 'development',
		];
		$GLOBALS['stonewright_test_transients']      = [];
		$GLOBALS['stonewright_test_wpdb_inserts']    = [];
		$GLOBALS['stonewright_test_post_meta_calls'] = [];
		$GLOBALS['stonewright_test_posts']           = [];
		$GLOBALS['stonewright_test_asset_responses'] = [];

		$this->register_post( self::POST_ID );
	}

	// ── Happy path ────────────────────────────────────────────────────────────

	public function test_writes_elementor_data(): void {
		$result = self::$ability->execute( [
			'spec'    => self::$valid_spec,
			'post_id' => self::POST_ID,
		] );

		$this->assertIsArray( $result );
		$this->assertSame( self::POST_ID, $result['post_id'] );
		$this->assertArrayHasKey( 'spec_sha8', $result );
		$this->assertSame( 8, strlen( (string) $result['spec_sha8'] ) );

		$meta_calls = $GLOBALS['stonewright_test_post_meta_calls'];
		$keys       = array_column( $meta_calls, 'meta_key' );
		$this->assertContains( '_elementor_data', $keys, '_elementor_data must be written' );
	}

	public function test_backup_called_before_elementor_data(): void {
		self::$ability->execute( [
			'spec'    => self::$valid_spec,
			'post_id' => self::POST_ID,
		] );

		$meta_calls   = $GLOBALS['stonewright_test_post_meta_calls'];
		$snapshot_pos = null;
		$data_pos     = null;

		foreach ( $meta_calls as $i => $call ) {
			if ( '_stonewright_backups' === $call['meta_key'] && null === $snapshot_pos ) {
				$snapshot_pos = $i;
			}
			if ( '_elementor_data' === $call['meta_key'] && null === $data_pos ) {
				$data_pos = $i;
			}
		}

		$this->assertNotNull( $snapshot_pos, 'Backup snapshot must be written' );
		$this->assertNotNull( $data_pos, '_elementor_data must be written' );
		$this->assertLessThan( $data_pos, $snapshot_pos, 'Backup must happen BEFORE _elementor_data write' );
	}

	public function test_sideloaded_assets_returned(): void {
		$source_url = 'https://cdn.example.com/img/hero.png';

		$GLOBALS['stonewright_test_asset_responses'][ $source_url ] = [
			'response' => [ 'code' => 200 ],
			'headers'  => [ 'content-type' => 'image/png' ],
			'body'     => str_repeat( 'P', 200 ),
		];

		$spec_with_assets = array_merge( self::$valid_spec, [
			'assets' => [
				[ 'id' => 'asset_hero', 'url' => $source_url ],
			],
		] );

		$result = self::$ability->execute( [
			'spec'    => $spec_with_assets,
			'post_id' => self::POST_ID,
		] );

		$this->assertIsArray( $result );
		$this->assertIsArray( $result['sideloaded_assets'] );
		$this->assertCount( 1, $result['sideloaded_assets'], 'One asset should be sideloaded' );
		$this->assertIsInt( $result['sideloaded_assets'][0] );
	}

	public function test_sideloaded_url_replaces_original_in_elementor_data(): void {
		$source_url = 'https://cdn.example.com/img/banner.png';

		$GLOBALS['stonewright_test_asset_responses'][ $source_url ] = [
			'response' => [ 'code' => 200 ],
			'headers'  => [ 'content-type' => 'image/png' ],
			'body'     => str_repeat( 'B', 200 ),
		];

		// Use a heading block (passes Validator) with the source URL in the assets list.
		// URL replacement happens in the spec array before it goes to ElementorWriter.
		// After replacement, $source_url is replaced by a WP attachment URL in the spec.
		$spec_with_asset = [
			'version'  => '1.0.0',
			'page'     => [ 'title' => 'Asset Replacement Page' ],
			'sections' => [
				[
					'id'     => 'sec1',
					'blocks' => [
						[ 'type' => 'heading', 'text' => 'Banner Section', 'level' => 1 ],
					],
				],
			],
			'assets' => [
				[ 'id' => 'asset_banner', 'url' => $source_url ],
			],
		];

		$result = self::$ability->execute( [
			'spec'    => $spec_with_asset,
			'post_id' => self::POST_ID,
		] );

		// The ability must succeed: sideloading + writing both work.
		$this->assertIsArray( $result, 'execute() must return array on success' );
		$this->assertIsArray( $result['sideloaded_assets'] );
		$this->assertCount( 1, $result['sideloaded_assets'], 'Asset from spec.assets must be sideloaded' );
		$this->assertGreaterThan( 0, $result['sideloaded_assets'][0] );

		// _elementor_data must be written (proves write pipeline completed).
		$meta_calls = $GLOBALS['stonewright_test_post_meta_calls'];
		$keys       = array_column( $meta_calls, 'meta_key' );
		$this->assertContains( '_elementor_data', $keys, '_elementor_data must be written after asset sideload' );

		// The original CDN URL must NOT appear in the written _elementor_data value.
		// This confirms URL replacement actually occurred before the spec was handed off.
		$elementor_data_call = null;
		foreach ( $meta_calls as $call ) {
			if ( '_elementor_data' === $call['meta_key'] ) {
				$elementor_data_call = $call;
				break;
			}
		}
		$this->assertNotNull( $elementor_data_call, '_elementor_data meta call not found' );
		$written_data = is_string( $elementor_data_call['value'] )
			? $elementor_data_call['value']
			: (string) wp_json_encode( $elementor_data_call['value'] );
		$this->assertStringNotContainsString(
			$source_url,
			$written_data,
			'Original CDN URL must not appear in written _elementor_data — URL replacement failed'
		);
	}

	// ── Confirmation token enforcement ────────────────────────────────────────

	public function test_background_image_ref_is_sideloaded_and_written_as_elementor_background(): void {
		$source_url = 'https://cdn.example.com/img/hero-glow.png';

		$GLOBALS['stonewright_test_asset_responses'][ $source_url ] = [
			'response' => [ 'code' => 200 ],
			'headers'  => [ 'content-type' => 'image/png' ],
			'body'     => str_repeat( 'G', 200 ),
		];

		$result = self::$ability->execute( [
			'spec'    => [
				'version'  => '1.0.0',
				'page'     => [ 'title' => 'Background Asset Page' ],
				'assets'   => [
					[ 'id' => 'asset_glow_bg', 'url' => $source_url ],
				],
				'sections' => [
					[
						'id'         => 'hero',
						'background' => [
							'color'    => '#030712',
							'imageRef' => 'asset_glow_bg',
							'position' => 'center center',
							'size'     => 'cover',
						],
						'blocks'     => [
							[ 'type' => 'heading', 'text' => 'Hero', 'level' => 1 ],
						],
					],
				],
			],
			'post_id' => self::POST_ID,
		] );

		$this->assertIsArray( $result );
		$this->assertCount( 1, $result['sideloaded_assets'] );

		$elementor_data_call = $this->elementor_data_call();
		$tree                = json_decode( stripslashes( (string) $elementor_data_call['value'] ), true );

		$this->assertIsArray( $tree );
		$this->assertSame( 'classic', $tree[0]['settings']['background_background'] );
		$attachment_id = (int) $result['sideloaded_assets'][0];
		$this->assertSame( 'https://example.test/wp-content/uploads/attachment-' . $attachment_id . '.txt', $tree[0]['settings']['background_image']['url'] );
		$this->assertSame( $attachment_id, $tree[0]['settings']['background_image']['id'] );
		$this->assertSame( 'center center', $tree[0]['settings']['background_position'] );
		$this->assertSame( 'cover', $tree[0]['settings']['background_size'] );
		$this->assertStringNotContainsString( $source_url, (string) $elementor_data_call['value'] );
	}

	public function test_token_for_one_spec_rejected_with_different_spec(): void {
		$GLOBALS['stonewright_test_options']['stonewright_mode'] = 'production-safe';

		// $spec_a — single hero section (what the token is issued for).
		$spec_a = self::$valid_spec;

		// $spec_b — structurally different: two sections and a different page title.
		$spec_b = [
			'version'  => '1.0.0',
			'page'     => [ 'title' => 'A Totally Different Page' ],
			'sections' => [
				[
					'id'     => 'sec1',
					'blocks' => [
						[ 'type' => 'heading', 'text' => 'First Section', 'level' => 1 ],
					],
				],
				[
					'id'     => 'sec2',
					'blocks' => [
						[ 'type' => 'heading', 'text' => 'Second Section', 'level' => 2 ],
					],
				],
			],
		];

		// 1. Issue token for $spec_a + post_id.
		$token_args = [
			'spec'    => $spec_a,
			'post_id' => self::POST_ID,
		];
		$token = ConfirmationToken::issue( 'stonewright/design-apply-to-post', $token_args, 300 );

		// 2. Call execute() with $spec_b + same post_id + that token.
		$result = self::$ability->execute( [
			'spec'               => $spec_b,
			'post_id'            => self::POST_ID,
			'confirmation_token' => $token,
		] );

		// 3. Assert WP_Error with code stonewright_confirmation_args_mismatch.
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'stonewright_confirmation_args_mismatch', $result->get_error_code() );
	}

	public function test_confirmation_token_required_in_production_safe_mode(): void {
		$GLOBALS['stonewright_test_options']['stonewright_mode'] = 'production-safe';

		$result = self::$ability->execute( [
			'spec'    => self::$valid_spec,
			'post_id' => self::POST_ID,
		] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'stonewright_confirmation_token_required', $result->get_error_code() );
	}

	public function test_valid_confirmation_token_passes_in_production_safe_mode(): void {
		$GLOBALS['stonewright_test_options']['stonewright_mode'] = 'production-safe';

		$args = [
			'spec'    => self::$valid_spec,
			'post_id' => self::POST_ID,
		];
		$token = ConfirmationToken::issue( 'stonewright/design-apply-to-post', $args, 300 );
		$args['confirmation_token'] = $token;

		$result = self::$ability->execute( $args );

		$this->assertIsArray( $result );
		$this->assertSame( self::POST_ID, $result['post_id'] );
	}

	public function test_invalid_confirmation_token_rejected_in_production_safe_mode(): void {
		$GLOBALS['stonewright_test_options']['stonewright_mode'] = 'production-safe';

		$result = self::$ability->execute( [
			'spec'               => self::$valid_spec,
			'post_id'            => self::POST_ID,
			'confirmation_token' => 'swc_badtokenvalue',
		] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		// Signature invalid → stonewright_confirmation_invalid
		$this->assertSame( 'stonewright_confirmation_invalid', $result->get_error_code() );
	}

	public function test_development_mode_does_not_require_token(): void {
		$GLOBALS['stonewright_test_options']['stonewright_mode'] = 'development';

		$result = self::$ability->execute( [
			'spec'    => self::$valid_spec,
			'post_id' => self::POST_ID,
		] );

		$this->assertIsArray( $result );
		$this->assertSame( self::POST_ID, $result['post_id'] );
	}

	// ── Permission checks ─────────────────────────────────────────────────────

	public function test_permission_denied_without_manage_options(): void {
		$GLOBALS['stonewright_test_user_caps'] = [ 'edit_pages' => true ];
		// post_id > 0 required for permission_callback to check edit_post.
		$perm = self::$ability->permission_callback( [ 'post_id' => self::POST_ID ] );
		$this->assertFalse( $perm );
	}

	public function test_permission_denied_without_edit_post_cap(): void {
		$GLOBALS['stonewright_test_user_caps'] = [
			'manage_options' => true,
			'edit_pages'     => true,
		];
		// edit_post cap for specific post_id = false.
		$GLOBALS['stonewright_test_user_can_callback'] = static function ( string $cap, mixed ...$args ): bool {
			if ( 'edit_post' === $cap ) return false;
			return ! empty( $GLOBALS['stonewright_test_user_caps'][ $cap ] );
		};

		$perm = self::$ability->permission_callback( [ 'post_id' => self::POST_ID ] );
		$this->assertFalse( $perm );
	}

	// ── Error paths ───────────────────────────────────────────────────────────

	public function test_missing_post_returns_backup_error(): void {
		// No post registered for id 9999.
		$result = self::$ability->execute( [
			'spec'    => self::$valid_spec,
			'post_id' => 9999,
		] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'stonewright_backup_failed', $result->get_error_code() );
	}

	public function test_invalid_spec_returns_spec_invalid_error(): void {
		$result = self::$ability->execute( [
			'spec'    => [ 'version' => '1.0.0', 'page' => [], 'sections' => [] ],
			'post_id' => self::POST_ID,
		] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'stonewright_spec_invalid', $result->get_error_code() );
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	private function register_post( int $id ): void {
		$GLOBALS['stonewright_test_posts'][ $id ] = (object) [
			'ID'           => $id,
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_title'   => 'Apply Test Post ' . $id,
			'post_content' => '',
			'post_excerpt' => '',
			'post_parent'  => 0,
			'post_name'    => 'apply-test-' . $id,
			'meta'         => [],
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function elementor_data_call(): array {
		foreach ( $GLOBALS['stonewright_test_post_meta_calls'] as $call ) {
			if ( '_elementor_data' === $call['meta_key'] ) {
				return $call;
			}
		}

		$this->fail( '_elementor_data meta call not found' );
	}
}
