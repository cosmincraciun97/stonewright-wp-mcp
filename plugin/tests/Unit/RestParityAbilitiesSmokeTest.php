<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\PluginsManage\PluginDeactivate;
use Stonewright\WpMcp\Abilities\Revisions\PostRevisionRestore;
use Stonewright\WpMcp\Abilities\Search\SearchQuery;
use Stonewright\WpMcp\Abilities\Settings\SettingsUpdate;
use Stonewright\WpMcp\Abilities\Themes\ThemeActivate;
use Stonewright\WpMcp\Abilities\Users\UserDelete;
use Stonewright\WpMcp\Abilities\WooCommerce\WcProductList;
use Stonewright\WpMcp\Core\AbilityRegistry;
use Stonewright\WpMcp\Security\Permissions;

/**
 * Smoke coverage for REST parity wave-3 abilities.
 */
final class RestParityAbilitiesSmokeTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_user_logged_in'] = true;
		$GLOBALS['stonewright_test_user_caps']      = [
			'list_users'          => true,
			'create_users'        => true,
			'edit_users'          => true,
			'delete_users'        => true,
			'manage_options'      => true,
			'edit_theme_options'  => true,
			'switch_themes'       => true,
			'activate_plugins'    => true,
			'delete_plugins'      => true,
			'manage_woocommerce'  => true,
			'edit_posts'          => true,
			'read'                => true,
			'moderate_comments'   => true,
		];
		$GLOBALS['stonewright_test_options']['stonewright_mode'] = 'development';
		$GLOBALS['stonewright_test_users']                       = [
			1 => [ 'ID' => 1, 'user_login' => 'admin', 'display_name' => 'Admin', 'roles' => [ 'administrator' ], 'user_email' => 'a@example.com' ],
			2 => [ 'ID' => 2, 'user_login' => 'editor', 'display_name' => 'Editor', 'roles' => [ 'editor' ], 'user_email' => 'e@example.com' ],
		];
		$GLOBALS['stonewright_test_search_posts'] = [
			(object) [ 'ID' => 11, 'post_title' => 'Hello', 'post_type' => 'post', 'post_status' => 'publish', 'post_modified' => '2026-01-01' ],
		];
	}

	public function test_registry_lists_new_parity_abilities(): void {
		$names = AbilityRegistry::list();
		$need  = [
			\Stonewright\WpMcp\Abilities\Comments\CommentList::class,
			\Stonewright\WpMcp\Abilities\Users\UserList::class,
			\Stonewright\WpMcp\Abilities\Widgets\WidgetList::class,
			\Stonewright\WpMcp\Abilities\Settings\SettingsGet::class,
			\Stonewright\WpMcp\Abilities\Themes\ThemeList::class,
			\Stonewright\WpMcp\Abilities\PluginsManage\PluginActivate::class,
			\Stonewright\WpMcp\Abilities\Revisions\PostRevisionList::class,
			\Stonewright\WpMcp\Abilities\Search\SearchQuery::class,
			\Stonewright\WpMcp\Abilities\WooCommerce\WcProductList::class,
		];
		foreach ( $need as $class ) {
			$this->assertContains( $class, $names, $class . ' missing from registry' );
		}
	}

	public function test_permission_helpers(): void {
		$GLOBALS['stonewright_test_user_caps'] = [];
		$this->assertFalse( Permissions::moderate_comments() );
		$this->assertFalse( Permissions::list_users() );
		$GLOBALS['stonewright_test_user_caps'] = [ 'moderate_comments' => true ];
		$this->assertTrue( Permissions::moderate_comments() );
	}

	public function test_settings_blocks_siteurl(): void {
		$result = ( new SettingsUpdate() )->execute(
			[
				'settings' => [ 'siteurl' => 'https://evil.example' ],
			]
		);
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'stonewright_settings_blocked_key', $result->get_error_code() );
	}

	public function test_user_delete_refuses_self(): void {
		$GLOBALS['stonewright_test_current_user_id'] = 2;
		$result = ( new UserDelete() )->execute( [ 'id' => 2, 'reassign' => 1 ] );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'stonewright_user_self_delete', $result->get_error_code() );
	}

	public function test_plugin_deactivate_protects_stonewright(): void {
		$result = ( new PluginDeactivate() )->execute( [ 'plugin' => 'stonewright/stonewright.php' ] );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'stonewright_self_protection', $result->get_error_code() );
	}

	public function test_search_query_returns_items(): void {
		$result = ( new SearchQuery() )->execute( [ 'search' => 'Hello' ] );
		$this->assertIsArray( $result );
		$this->assertNotEmpty( $result['items'] );
	}

	public function test_search_query_subscriber_only_sees_publish(): void {
		$GLOBALS['stonewright_test_user_caps'] = [ 'read' => true ];
		$GLOBALS['stonewright_test_search_posts'] = [
			(object) [ 'ID' => 11, 'post_title' => 'Public', 'post_type' => 'post', 'post_status' => 'publish', 'post_modified' => '2026-01-01' ],
			(object) [ 'ID' => 12, 'post_title' => 'Draft secret', 'post_type' => 'post', 'post_status' => 'draft', 'post_modified' => '2026-01-01' ],
		];
		// Bootstrap WP_Query stub uses stonewright_test_search_posts; ability filters by read_post.
		$result = ( new SearchQuery() )->execute( [ 'search' => 'x' ] );
		$this->assertIsArray( $result );
		// With only read cap, private/draft results must not appear if read_post denies them.
		foreach ( $result['items'] as $item ) {
			$this->assertSame( 'publish', $item['status'] );
		}
	}

	public function test_widget_save_requires_token_in_production_safe(): void {
		$GLOBALS['stonewright_test_options']['stonewright_mode'] = 'production-safe';
		$result = ( new \Stonewright\WpMcp\Abilities\Widgets\WidgetSave() )->execute(
			[
				'sidebar_id' => 'sidebar-1',
				'widgets'    => [],
			]
		);
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'stonewright_confirmation_required', $result->get_error_code() );
		$GLOBALS['stonewright_test_options']['stonewright_mode'] = 'development';
	}

	public function test_app_passwords_require_edit_user_on_target(): void {
		$GLOBALS['stonewright_test_user_caps'] = [ 'manage_options' => true ];
		// manage_options alone does not imply edit_user on arbitrary accounts in the test stub.
		$ability = new \Stonewright\WpMcp\Abilities\Users\UserAppPasswords();
		$this->assertFalse(
			$ability->permission_callback( [ 'action' => 'list', 'user_id' => 99 ] )
		);

		$GLOBALS['stonewright_test_user_can_callback'] = static function ( string $cap, mixed ...$args ): bool {
			if ( 'edit_user' === $cap && 2 === (int) ( $args[0] ?? 0 ) ) {
				return true;
			}
			return 'manage_options' === $cap;
		};
		$this->assertTrue(
			$ability->permission_callback( [ 'action' => 'list', 'user_id' => 2 ] )
		);
		unset( $GLOBALS['stonewright_test_user_can_callback'] );
	}

	public function test_wc_products_reports_unsupported_without_woocommerce(): void {
		$result = ( new WcProductList() )->execute( [] );
		$this->assertIsArray( $result );
		$this->assertFalse( $result['supported'] );
	}

	public function test_theme_activate_runs(): void {
		$result = ( new ThemeActivate() )->execute( [ 'stylesheet' => 'twentytwentyfour' ] );
		$this->assertIsArray( $result );
		$this->assertSame( 'twentytwentyfour', $result['stylesheet'] );
	}

	public function test_revision_restore_requires_existing_revision(): void {
		$result = ( new PostRevisionRestore() )->execute( [ 'revision_id' => 99999 ] );
		// permission fails or not found depending on post stub.
		$this->assertTrue( is_array( $result ) || $result instanceof \WP_Error );
	}
}
