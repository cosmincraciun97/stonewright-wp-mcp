<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\CustomCode;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\Content\BulkCreate;
use Stonewright\WpMcp\Abilities\Content\BulkUpsertPosts;
use Stonewright\WpMcp\Abilities\Content\CreatePost;
use Stonewright\WpMcp\Abilities\Content\DuplicatePage;
use Stonewright\WpMcp\Abilities\Content\UpdatePost;
use Stonewright\WpMcp\CustomCode\ContentSurfacePolicy;
use Stonewright\WpMcp\CustomCode\OwnsPostTypesInterface;
use Stonewright\WpMcp\CustomCode\ProviderInterface;
use Stonewright\WpMcp\CustomCode\ProviderRegistry;

/**
 * @covers \Stonewright\WpMcp\CustomCode\ContentSurfacePolicy
 * @covers \Stonewright\WpMcp\CustomCode\ProviderRegistry
 * @covers \Stonewright\WpMcp\Abilities\Content\BulkCreate
 * @covers \Stonewright\WpMcp\Abilities\Content\BulkUpsertPosts
 * @covers \Stonewright\WpMcp\Abilities\Content\CreatePost
 * @covers \Stonewright\WpMcp\Abilities\Content\UpdatePost
 * @covers \Stonewright\WpMcp\Abilities\Content\DuplicatePage
 */
final class ContentSurfacePolicyTest extends TestCase {

	private const PHP_PAYLOAD = '<?php $service->run();';
	private const SNIPPET_TITLE = 'Secret Snippet Title';

	protected function setUp(): void {
		$GLOBALS['stonewright_test_user_caps']             = [];
		$GLOBALS['stonewright_test_user_logged_in']        = true;
		$GLOBALS['stonewright_test_user_can_callback']     = null;
		$GLOBALS['stonewright_test_options']               = [ 'stonewright_mode' => 'development' ];
		$GLOBALS['stonewright_test_post_types']            = [];
		$GLOBALS['stonewright_test_posts']                 = [];
		$GLOBALS['stonewright_test_post_meta_calls']       = [];
		$GLOBALS['stonewright_test_next_post_id']          = 1001;
		$GLOBALS['stonewright_test_inserted_posts']        = [];
		$GLOBALS['stonewright_test_wp_insert_post_return'] = null;
		$GLOBALS['stonewright_test_wp_update_post_return'] = null;
		$GLOBALS['stonewright_test_wp_insert_post_calls']  = [];
		$GLOBALS['stonewright_test_wp_update_post_calls']  = [];
		$GLOBALS['stonewright_test_wp_kses_post_calls']    = [];
		$GLOBALS['stonewright_test_transients']            = [];

		$this->register_post_type( 'post', 'edit_posts', 'publish_posts' );
		$this->register_post_type( 'page', 'edit_pages', 'publish_pages' );
		$this->register_post_type( 'homepage_section', 'edit_posts', 'publish_posts' );
		$this->register_post_type( 'synthetic-code-type', 'edit_posts', 'publish_posts' );

		$this->login_as( [ 'edit_posts', 'publish_posts', 'edit_pages', 'publish_pages' ] );
		$GLOBALS['stonewright_test_user_can_callback'] = static function ( string $cap, mixed ...$args ): bool {
			if ( 'edit_post' === $cap ) {
				return (int) ( $args[0] ?? 0 ) > 0;
			}
			return in_array( $cap, [ 'edit_posts', 'publish_posts', 'edit_pages', 'publish_pages', 'edit_post_meta' ], true );
		};

		ProviderRegistry::set_for_tests(
			[
				'synthetic-provider' => $this->owned_provider( 'synthetic-provider', [ 'synthetic-code-type' ] ),
			]
		);
	}

	protected function tearDown(): void {
		ProviderRegistry::reset_for_tests();
		$GLOBALS['stonewright_test_user_caps']             = [];
		$GLOBALS['stonewright_test_user_logged_in']        = false;
		$GLOBALS['stonewright_test_user_can_callback']     = null;
		$GLOBALS['stonewright_test_options']               = [];
		$GLOBALS['stonewright_test_post_types']            = [];
		$GLOBALS['stonewright_test_posts']                 = [];
		$GLOBALS['stonewright_test_wp_insert_post_calls']  = [];
		$GLOBALS['stonewright_test_wp_update_post_calls']  = [];
		$GLOBALS['stonewright_test_wp_kses_post_calls']    = [];
	}

	public function test_policy_rejects_owned_post_type_without_content_or_title(): void {
		$result = ContentSurfacePolicy::assert_generic_write_allowed( 'synthetic-code-type' );
		self::assertInstanceOf( \WP_Error::class, $result );
		$this->assert_provider_required_error( $result );
	}

	public function test_policy_allows_ordinary_post_page_and_non_code_cpt(): void {
		self::assertTrue( ContentSurfacePolicy::assert_generic_write_allowed( 'post' ) );
		self::assertTrue( ContentSurfacePolicy::assert_generic_write_allowed( 'page' ) );
		self::assertTrue( ContentSurfacePolicy::assert_generic_write_allowed( 'homepage_section' ) );
	}

	public function test_duplicate_ownership_blocks_generic_writes(): void {
		ProviderRegistry::set_for_tests(
			[
				'synthetic-provider' => $this->owned_provider( 'synthetic-provider', [ 'synthetic-code-type' ] ),
				'other-provider'     => $this->owned_provider( 'other-provider', [ 'synthetic-code-type' ] ),
			]
		);

		$result = ContentSurfacePolicy::assert_generic_write_allowed( 'synthetic-code-type' );
		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_custom_code_post_type_ownership_conflict', $result->get_error_code() );
		$data = $result->get_error_data();
		self::assertIsArray( $data );
		self::assertSame( 409, $data['status'] );
		self::assertSame( 'synthetic-code-type', $data['post_type'] );
		self::assertFalse( $data['retryable'] );
		self::assertSame( 'stonewright-custom-code-provider-ops', $data['next_ability'] );
		self::assertContains( 'synthetic-provider', $data['providers'] );
		self::assertContains( 'other-provider', $data['providers'] );
		self::assertArrayNotHasKey( 'title', $data );
		self::assertArrayNotHasKey( 'code', $data );
		self::assertArrayNotHasKey( 'content', $data );
	}

	public function test_bulk_upsert_rejects_code_cpt_before_kses_and_write(): void {
		$result = ( new BulkUpsertPosts() )->execute(
			[
				'post_type' => 'synthetic-code-type',
				'items'     => [
					[
						'slug'    => 'secret-snippet',
						'title'   => self::SNIPPET_TITLE,
						'content' => self::PHP_PAYLOAD,
						'status'  => 'draft',
					],
				],
			]
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		$this->assert_provider_required_error( $result );
		$this->assert_no_generic_write();
	}

	public function test_bulk_create_rejects_code_cpt_before_kses_and_write(): void {
		$result = ( new BulkCreate() )->execute(
			[
				'items' => [
					[
						'title'     => self::SNIPPET_TITLE,
						'content'   => self::PHP_PAYLOAD,
						'post_type' => 'synthetic-code-type',
						'status'    => 'draft',
					],
				],
			]
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		$this->assert_provider_required_error( $result );
		$this->assert_no_generic_write();
	}

	public function test_create_post_rejects_code_cpt_before_kses_and_write(): void {
		$result = ( new CreatePost() )->execute(
			[
				'title'     => self::SNIPPET_TITLE,
				'content'   => self::PHP_PAYLOAD,
				'post_type' => 'synthetic-code-type',
				'status'    => 'draft',
			]
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		$this->assert_provider_required_error( $result );
		$this->assert_no_generic_write();
	}

	public function test_update_post_rejects_code_cpt_before_kses_and_write(): void {
		$this->set_post( 55, 'synthetic-code-type', self::SNIPPET_TITLE, 'old' );

		$result = ( new UpdatePost() )->execute(
			[
				'id'      => 55,
				'title'   => self::SNIPPET_TITLE,
				'content' => self::PHP_PAYLOAD,
			]
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		$this->assert_provider_required_error( $result );
		$this->assert_no_generic_write();
		self::assertSame( 'old', $GLOBALS['stonewright_test_posts'][55]->post_content );
	}

	public function test_duplicate_page_rejects_code_cpt_before_write(): void {
		$this->set_post( 77, 'synthetic-code-type', self::SNIPPET_TITLE, self::PHP_PAYLOAD );

		$result = ( new DuplicatePage() )->execute(
			[
				'id'           => 77,
				'title_suffix' => ' (copy)',
			]
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		$this->assert_provider_required_error( $result );
		$this->assert_no_generic_write();
	}

	public function test_permission_callbacks_reject_owned_types_before_capability_checks(): void {
		$GLOBALS['stonewright_test_user_caps']         = [];
		$GLOBALS['stonewright_test_user_can_callback'] = static fn(): bool => false;

		$create = ( new CreatePost() )->permission_callback(
			[
				'title'     => self::SNIPPET_TITLE,
				'content'   => self::PHP_PAYLOAD,
				'post_type' => 'synthetic-code-type',
			]
		);
		self::assertInstanceOf( \WP_Error::class, $create );
		$this->assert_provider_required_error( $create );

		$bulk = ( new BulkCreate() )->permission_callback(
			[
				'items' => [
					[
						'title'     => self::SNIPPET_TITLE,
						'post_type' => 'synthetic-code-type',
					],
				],
			]
		);
		self::assertInstanceOf( \WP_Error::class, $bulk );
		$this->assert_provider_required_error( $bulk );

		$upsert = ( new BulkUpsertPosts() )->permission_callback(
			[
				'post_type' => 'synthetic-code-type',
				'items'     => [
					[
						'slug'  => 'secret-snippet',
						'title' => self::SNIPPET_TITLE,
					],
				],
			]
		);
		self::assertInstanceOf( \WP_Error::class, $upsert );
		$this->assert_provider_required_error( $upsert );

		$this->set_post( 55, 'synthetic-code-type', self::SNIPPET_TITLE, 'old' );
		$update = ( new UpdatePost() )->permission_callback(
			[
				'id'      => 55,
				'content' => self::PHP_PAYLOAD,
			]
		);
		self::assertInstanceOf( \WP_Error::class, $update );
		$this->assert_provider_required_error( $update );

		$this->set_post( 77, 'synthetic-code-type', self::SNIPPET_TITLE, self::PHP_PAYLOAD );
		$duplicate = ( new DuplicatePage() )->permission_callback( [ 'id' => 77 ] );
		self::assertInstanceOf( \WP_Error::class, $duplicate );
		$this->assert_provider_required_error( $duplicate );
	}

	public function test_ordinary_post_page_and_cpt_still_sanitize_and_write(): void {
		$post = ( new CreatePost() )->execute(
			[
				'title'     => 'Ordinary post',
				'content'   => '<p>Hello</p>',
				'post_type' => 'post',
			]
		);
		self::assertIsArray( $post );
		self::assertGreaterThan( 0, (int) $post['id'] );

		$page_upsert = ( new BulkUpsertPosts() )->execute(
			[
				'post_type' => 'page',
				'items'     => [
					[
						'slug'    => 'about',
						'title'   => 'About',
						'content' => '<p>About</p>',
					],
				],
			]
		);
		self::assertIsArray( $page_upsert );
		self::assertSame( 1, $page_upsert['created'] );

		$cpt = ( new BulkCreate() )->execute(
			[
				'items' => [
					[
						'title'     => 'Section',
						'content'   => '<p>Section</p>',
						'post_type' => 'homepage_section',
					],
				],
			]
		);
		self::assertIsArray( $cpt );
		self::assertCount( 1, $cpt['created'] );

		self::assertGreaterThan( 0, count( $GLOBALS['stonewright_test_wp_kses_post_calls'] ) );
		self::assertGreaterThan( 0, count( $GLOBALS['stonewright_test_wp_insert_post_calls'] ) );

		$perm = ( new CreatePost() )->permission_callback(
			[
				'title'     => 'Ordinary post',
				'post_type' => 'post',
			]
		);
		self::assertTrue( $perm );
	}

	private function assert_provider_required_error( \WP_Error $result ): void {
		self::assertSame( 'stonewright_custom_code_provider_required', $result->get_error_code() );
		$data = $result->get_error_data();
		self::assertIsArray( $data );
		self::assertSame( 409, $data['status'] );
		self::assertSame( 'synthetic-code-type', $data['post_type'] );
		self::assertSame( 'synthetic-provider', $data['provider'] );
		self::assertSame( 'stonewright-custom-code-provider-ops', $data['next_ability'] );
		self::assertFalse( $data['retryable'] );
		self::assertArrayNotHasKey( 'title', $data );
		self::assertArrayNotHasKey( 'code', $data );
		self::assertArrayNotHasKey( 'content', $data );

		$encoded = wp_json_encode( [ $result->get_error_message(), $data ] );
		self::assertIsString( $encoded );
		self::assertStringNotContainsString( '<?php', $encoded );
		self::assertStringNotContainsString( '$service->run()', $encoded );
		self::assertStringNotContainsString( self::SNIPPET_TITLE, $encoded );
	}

	private function assert_no_generic_write(): void {
		$wp_insert_post_calls = count( $GLOBALS['stonewright_test_wp_insert_post_calls'] ?? [] );
		$wp_update_post_calls = count( $GLOBALS['stonewright_test_wp_update_post_calls'] ?? [] );
		$kses_calls           = count( $GLOBALS['stonewright_test_wp_kses_post_calls'] ?? [] );
		self::assertSame( 0, $wp_insert_post_calls );
		self::assertSame( 0, $wp_update_post_calls );
		self::assertSame( 0, $kses_calls );
	}

	private function register_post_type( string $name, string $create_cap, string $publish_cap ): void {
		$GLOBALS['stonewright_test_post_types'][ $name ] = (object) [
			'cap' => (object) [
				'create_posts'  => $create_cap,
				'publish_posts' => $publish_cap,
			],
		];
	}

	private function login_as( array $caps ): void {
		$GLOBALS['stonewright_test_user_logged_in'] = true;
		$GLOBALS['stonewright_test_user_caps']      = array_fill_keys( $caps, true );
	}

	private function set_post( int $id, string $post_type, string $title, string $content ): void {
		$GLOBALS['stonewright_test_posts'][ $id ] = (object) [
			'ID'           => $id,
			'post_type'    => $post_type,
			'post_status'  => 'draft',
			'post_title'   => $title,
			'post_content' => $content,
			'post_excerpt' => '',
			'post_parent'  => 0,
			'post_name'    => 'secret-snippet',
		];
	}

	/**
	 * @param list<string> $types
	 */
	private function owned_provider( string $id, array $types ): ProviderInterface {
		return new class( $id, $types ) implements ProviderInterface, OwnsPostTypesInterface {
			/** @param list<string> $types */
			public function __construct(
				private string $provider_id,
				private array $types
			) {}

			public function id(): string {
				return $this->provider_id;
			}

			public function label(): string {
				return $this->provider_id;
			}

			/** @return list<string> */
			public function owned_post_types(): array {
				return $this->types;
			}

			public function discover(): array {
				return [
					'id'           => $this->provider_id,
					'label'        => $this->provider_id,
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
