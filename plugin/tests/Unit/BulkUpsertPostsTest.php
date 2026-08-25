<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\Content\BulkUpsertPosts;
use Stonewright\WpMcp\CustomCode\OwnsPostTypesInterface;
use Stonewright\WpMcp\CustomCode\ProviderInterface;
use Stonewright\WpMcp\CustomCode\ProviderRegistry;
use Stonewright\WpMcp\Security\ConfirmationToken;

/**
 * @covers \Stonewright\WpMcp\Abilities\Content\BulkUpsertPosts
 * @covers \Stonewright\WpMcp\Security\Permissions
 */
final class BulkUpsertPostsTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_user_caps']              = [];
		$GLOBALS['stonewright_test_user_logged_in']         = false;
		$GLOBALS['stonewright_test_user_can_callback']      = null;
		$GLOBALS['stonewright_test_options']                = [];
		$GLOBALS['stonewright_test_post_types']             = [];
		$GLOBALS['stonewright_test_posts']                  = [];
		$GLOBALS['stonewright_test_post_meta_calls']        = [];
		$GLOBALS['stonewright_test_next_post_id']           = 1001;
		$GLOBALS['stonewright_test_inserted_posts']         = [];
		$GLOBALS['stonewright_test_wp_insert_post_return']  = null;
		$GLOBALS['stonewright_test_wp_update_post_return']  = null;
		$GLOBALS['stonewright_test_wp_insert_post_calls']   = [];
		$GLOBALS['stonewright_test_wp_update_post_calls']   = [];
		$GLOBALS['stonewright_test_wp_kses_post_calls']     = [];

		$this->registerPostType( 'homepage_section', 'edit_posts', 'publish_posts' );
		ProviderRegistry::reset_for_tests();
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_user_caps']              = [];
		$GLOBALS['stonewright_test_user_logged_in']         = false;
		$GLOBALS['stonewright_test_user_can_callback']      = null;
		$GLOBALS['stonewright_test_options']                = [];
		$GLOBALS['stonewright_test_post_types']             = [];
		$GLOBALS['stonewright_test_posts']                  = [];
		$GLOBALS['stonewright_test_post_meta_calls']        = [];
		$GLOBALS['stonewright_test_next_post_id']           = 1001;
		$GLOBALS['stonewright_test_inserted_posts']         = [];
		$GLOBALS['stonewright_test_wp_insert_post_return']  = null;
		$GLOBALS['stonewright_test_wp_update_post_return']  = null;
		$GLOBALS['stonewright_test_wp_insert_post_calls']   = [];
		$GLOBALS['stonewright_test_wp_update_post_calls']   = [];
		$GLOBALS['stonewright_test_wp_kses_post_calls']     = [];
		ProviderRegistry::reset_for_tests();
	}

	public function test_upserts_many_posts_with_meta_in_one_call(): void {
		$this->loginAs( [ 'edit_posts', 'publish_posts' ] );
		$GLOBALS['stonewright_test_user_can_callback'] = static function ( string $cap ): bool {
			return in_array( $cap, [ 'edit_posts', 'publish_posts', 'edit_post_meta' ], true );
		};

		$result = ( new BulkUpsertPosts() )->execute(
			[
				'post_type' => 'homepage_section',
				'items'     => [
					[
						'slug'       => 'hero',
						'title'      => 'Hero',
						'status'     => 'publish',
						'menu_order' => 1,
						'meta'       => [
							'section_slug' => 'hero',
							'section_order' => 1,
						],
					],
					[
						'slug'    => 'proof',
						'title'   => 'Proof',
						'status'  => 'publish',
						'excerpt' => 'Fast proof',
						'meta'    => [
							'section_slug' => 'proof',
						],
					],
				],
			]
		);

		self::assertIsArray( $result );
		self::assertSame( 2, $result['created'] );
		self::assertSame( 0, $result['updated'] );
		self::assertCount( 2, $result['items'] );
		self::assertCount( 3, $GLOBALS['stonewright_test_post_meta_calls'] );
		self::assertSame( 'section_slug', $GLOBALS['stonewright_test_post_meta_calls'][0]['meta_key'] );
	}

	public function test_updates_existing_post_matched_by_slug(): void {
		$this->loginAs( [ 'edit_posts', 'publish_posts' ] );
		$this->setPost( 42, 'homepage_section', 'draft', 'hero' );
		$GLOBALS['stonewright_test_user_can_callback'] = static function ( string $cap, mixed ...$args ): bool {
			if ( 'edit_post' === $cap ) {
				return 42 === (int) ( $args[0] ?? 0 );
			}
			return in_array( $cap, [ 'edit_posts', 'publish_posts', 'edit_post_meta' ], true );
		};

		$result = ( new BulkUpsertPosts() )->execute(
			[
				'post_type' => 'homepage_section',
				'items'     => [
					[
						'slug'   => 'hero',
						'title'  => 'Hero Updated',
						'status' => 'publish',
						'meta'   => [
							'section_slug' => 'hero',
						],
					],
				],
			]
		);

		self::assertIsArray( $result );
		self::assertSame( 0, $result['created'] );
		self::assertSame( 1, $result['updated'] );
		self::assertSame( 42, $result['items'][0]['id'] );
		self::assertSame( 'Hero Updated', $GLOBALS['stonewright_test_posts'][42]->post_title );
	}

	public function test_permission_callback_rejects_publish_without_publish_cap(): void {
		$this->loginAs( [ 'edit_posts' ] );

		$result = ( new BulkUpsertPosts() )->permission_callback(
			[
				'post_type' => 'homepage_section',
				'items'     => [
					[
						'slug'   => 'hero',
						'title'  => 'Hero',
						'status' => 'publish',
					],
				],
			]
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_forbidden', $result->get_error_code() );
	}

	public function test_accepts_post_status_alias_and_validates_required_payload(): void {
		$this->loginAs( [ 'edit_posts', 'publish_posts' ] );
		$GLOBALS['stonewright_test_user_can_callback'] = static function ( string $cap ): bool {
			return in_array( $cap, [ 'edit_posts', 'publish_posts', 'edit_post_meta' ], true );
		};

		$result = ( new BulkUpsertPosts() )->execute(
			[
				'post_type' => 'homepage_section',
				'items'     => [
					[
						'slug'        => 'hero',
						'title'       => 'Hero',
						'post_status' => 'publish',
					],
				],
			]
		);

		self::assertIsArray( $result );
		self::assertSame( 1, $result['created'] );
		self::assertSame( 'publish', $GLOBALS['stonewright_test_inserted_posts'][0]['post_status'] );

		$invalid_status = ( new BulkUpsertPosts() )->execute(
			[
				'post_type' => 'homepage_section',
				'items'     => [
					[
						'slug'   => 'bad',
						'title'  => 'Bad',
						'status' => 'public',
					],
				],
			]
		);

		self::assertInstanceOf( \WP_Error::class, $invalid_status );
		self::assertSame( 'stonewright_invalid_post_status', $invalid_status->get_error_code() );

		$missing_title = ( new BulkUpsertPosts() )->execute(
			[
				'post_type' => 'homepage_section',
				'items'     => [
					[
						'slug'  => 'untitled',
						'title' => '',
					],
				],
			]
		);

		self::assertInstanceOf( \WP_Error::class, $missing_title );
		self::assertSame( 'stonewright_invalid_content_item', $missing_title->get_error_code() );
	}

	public function test_permission_callback_uses_post_status_alias_for_publish_cap(): void {
		$this->loginAs( [ 'edit_posts' ] );

		$result = ( new BulkUpsertPosts() )->permission_callback(
			[
				'post_type' => 'homepage_section',
				'items'     => [
					[
						'slug'        => 'hero',
						'title'       => 'Hero',
						'post_status' => 'publish',
					],
				],
			]
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_forbidden', $result->get_error_code() );
	}

	private function registerPostType( string $name, string $create_cap, string $publish_cap ): void {
		$GLOBALS['stonewright_test_post_types'][ $name ] = (object) [
			'cap' => (object) [
				'create_posts'  => $create_cap,
				'publish_posts' => $publish_cap,
			],
		];
	}

	private function loginAs( array $caps ): void {
		$GLOBALS['stonewright_test_user_logged_in'] = true;
		$GLOBALS['stonewright_test_user_caps']      = array_fill_keys( $caps, true );
	}

	private function setPost( int $id, string $post_type, string $status, string $slug ): void {
		$GLOBALS['stonewright_test_posts'][ $id ] = (object) [
			'ID'           => $id,
			'post_type'    => $post_type,
			'post_status'  => $status,
			'post_title'   => 'Hero Old',
			'post_content' => '',
			'post_excerpt' => '',
			'post_parent'  => 0,
			'post_name'    => $slug,
		];
	}

	public function test_production_safe_mode_requires_confirmation_token(): void {
		$this->loginAs( [ 'edit_posts', 'publish_posts' ] );
		$GLOBALS['stonewright_test_current_user_id'] = 1;
		$GLOBALS['stonewright_test_transients']      = [];
		$GLOBALS['stonewright_test_user_can_callback'] = static function ( string $cap ): bool {
			return in_array( $cap, [ 'edit_posts', 'publish_posts', 'edit_post_meta' ], true );
		};

		$args = [
			'post_type' => 'homepage_section',
			'items'     => [
				[
					'slug'  => 'hero',
					'title' => 'Hero',
				],
			],
		];

		$GLOBALS['stonewright_test_options']['stonewright_mode'] = 'production-safe';
		$blocked = ( new BulkUpsertPosts() )->execute( $args );
		self::assertInstanceOf( \WP_Error::class, $blocked );
		self::assertSame( 'stonewright_confirmation_required', $blocked->get_error_code() );

		$args['confirmation_token'] = ConfirmationToken::issue( 'stonewright/content-bulk-upsert-posts', $args );
		$result = ( new BulkUpsertPosts() )->execute( $args );
		self::assertIsArray( $result );
		self::assertSame( 1, $result['created'] );

		$GLOBALS['stonewright_test_options']['stonewright_mode'] = 'development';
		$dev = ( new BulkUpsertPosts() )->execute(
			[
				'post_type' => 'homepage_section',
				'items'     => [
					[
						'slug'  => 'hero-dev',
						'title' => 'Hero Dev',
					],
				],
			]
		);
		self::assertIsArray( $dev );
		self::assertSame( 1, $dev['created'] );
	}

	public function test_rejects_provider_owned_code_post_type_before_kses_and_write(): void {
		$this->loginAs( [ 'edit_posts', 'publish_posts' ] );
		$this->registerPostType( 'synthetic-code-type', 'edit_posts', 'publish_posts' );
		ProviderRegistry::set_for_tests(
			[
				'synthetic-provider' => $this->syntheticOwnedProvider(),
			]
		);

		$result = ( new BulkUpsertPosts() )->execute(
			[
				'post_type' => 'synthetic-code-type',
				'items'     => [
					[
						'slug'    => 'secret-snippet',
						'title'   => 'Secret Snippet Title',
						'content' => '<?php $service->run();',
						'status'  => 'draft',
					],
				],
			]
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_custom_code_provider_required', $result->get_error_code() );
		$wp_insert_post_calls = count( $GLOBALS['stonewright_test_wp_insert_post_calls'] ?? [] );
		$wp_update_post_calls = count( $GLOBALS['stonewright_test_wp_update_post_calls'] ?? [] );
		$kses_calls           = count( $GLOBALS['stonewright_test_wp_kses_post_calls'] ?? [] );
		self::assertSame( 0, $wp_insert_post_calls );
		self::assertSame( 0, $wp_update_post_calls );
		self::assertSame( 0, $kses_calls );
	}

	private function syntheticOwnedProvider(): ProviderInterface {
		return new class() implements ProviderInterface, OwnsPostTypesInterface {
			public function id(): string {
				return 'synthetic-provider';
			}

			public function label(): string {
				return 'Synthetic';
			}

			/** @return list<string> */
			public function owned_post_types(): array {
				return [ 'synthetic-code-type' ];
			}

			public function discover(): array {
				return [
					'id'           => 'synthetic-provider',
					'label'        => 'Synthetic',
					'available'    => true,
					'active'       => true,
					'version'      => '0.0.0',
					'supported'    => true,
					'plugin_file'  => '',
					'capabilities' => [],
					'notes'        => '',
				];
			}

			public function list( array $args = [] ) {
				return [ 'ok' => true, 'items' => [] ];
			}

			public function read( string $target_id ) {
				return new \WP_Error( 'stonewright_unsupported', 'unused' );
			}

			public function dry_run( array $args ) {
				return new \WP_Error( 'stonewright_unsupported', 'unused' );
			}

			public function apply( array $args ) {
				return new \WP_Error( 'stonewright_unsupported', 'unused' );
			}

			public function verify( array $args ) {
				return new \WP_Error( 'stonewright_unsupported', 'unused' );
			}

			public function rollback( array $args ) {
				return new \WP_Error( 'stonewright_unsupported', 'unused' );
			}
		};
	}
}
