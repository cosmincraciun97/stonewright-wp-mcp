<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Admin\ClientCatalog;
use Stonewright\WpMcp\Admin\ConfigurationPage;
use Stonewright\WpMcp\Admin\Connect\ConnectedClients;
use Stonewright\WpMcp\Admin\SetupState;
use Stonewright\WpMcp\Admin\Ui\Html;
use Stonewright\WpMcp\Admin\Ui\Icon;
use Stonewright\WpMcp\Authorization\WordPress\AuthorizationLifecycle;
use Stonewright\WpMcp\Authorization\WordPress\HttpSurface;
use Stonewright\WpMcp\Security\DomainLock;
use Stonewright\WpMcp\Tests\Unit\Authorization\WordPress\Fixtures\HttpRig;
use Stonewright\WpMcp\Tests\Unit\Authorization\WordPress\Fixtures\StorageRig;

/**
 * Pins what the Setup screen prints: every form, field, name, value, nonce, hook and link, in the states a site
 * can be in. The inventory (forms and controls, data hooks, links, headings, ids) is the contract of the screen.
 *
 * It is compared with the files under tests/fixtures/admin-setup. A change that is meant to alter the screen
 * regenerates them (STONEWRIGHT_UPDATE_SNAPSHOTS=1) and the diff of those files shows exactly what changed.
 *
 * @covers \Stonewright\WpMcp\Admin\ConfigurationPage
 */
final class ConfigurationPageCharacterizationTest extends TestCase {

	private const FIXTURES = __DIR__ . '/../../fixtures/admin-setup';

	private HttpRig $http;

	protected function setUp(): void {
		StorageRig::reset_globals();
		ClientCatalog::reset_for_tests();
		Html::reset_ids();
		Icon::reset_for_tests();
		$this->http = new HttpRig();
		HttpSurface::use_site( HttpRig::site( true, 'production', 'pretty', 'https://example.com' ) );
		AuthorizationLifecycle::use_storage( $this->http->storage );
		$GLOBALS['stonewright_test_user_caps']        = [ 'manage_options' => true ];
		$GLOBALS['stonewright_test_current_user_id']  = 1;
		$GLOBALS['stonewright_test_user_meta']        = [];
		$GLOBALS['stonewright_test_app_passwords']    = [];
		$GLOBALS['stonewright_test_environment_type'] = 'local';
		$GLOBALS['stonewright_test_home_url']         = 'https://example.test/';
		$_GET                                         = [];
		$_POST                                        = [];
		delete_option( 'stonewright_locked_domain' );
		delete_option( 'stonewright_domain_mismatch' );
		delete_option( 'stonewright_domain_lock_prior' );
	}

	protected function tearDown(): void {
		HttpSurface::use_site( null );
		AuthorizationLifecycle::use_storage( null );
		StorageRig::reset_globals();
		ClientCatalog::reset_for_tests();
		unset( $GLOBALS['stonewright_test_environment_type'], $GLOBALS['stonewright_test_home_url'] );
		$GLOBALS['stonewright_test_current_user_id'] = 0;
		$GLOBALS['stonewright_test_user_meta']       = [];
		$GLOBALS['stonewright_test_app_passwords']   = [];
		$_GET                                        = [];
		$_POST                                       = [];
	}

	// -------------------------------------------------------------------------
	// States
	// -------------------------------------------------------------------------

	/** A fresh OAuth-capable site: on, development, OAuth chosen, one connected client, the domain locked. */
	public function test_an_oauth_site_that_is_switched_on(): void {
		$GLOBALS['stonewright_test_options']['stonewright_enabled'] = '1';
		$this->http->rig->connect();
		DomainLock::lock();

		$this->assert_screen( 'oauth-on', self::render() );
	}

	/** The password route on a production site: production-safe, two Application Passwords, stored secrets. */
	public function test_the_password_route_on_a_production_site_with_stored_secrets(): void {
		$GLOBALS['stonewright_test_environment_type']                       = 'production';
		$GLOBALS['stonewright_test_options']['stonewright_enabled']         = '1';
		$GLOBALS['stonewright_test_options']['stonewright_mode']            = 'production-safe';
		$GLOBALS['stonewright_test_options']['stonewright_mcp_surface']     = 'full';
		$GLOBALS['stonewright_test_options']['stonewright_elementor_v4_atomic'] = '1';
		$GLOBALS['stonewright_test_options']['stonewright_site_alias']      = 'site-a';
		$GLOBALS['stonewright_test_options']['stonewright_unsplash_access_key'] = 'synthetic-unsplash-key-0001';
		$GLOBALS['stonewright_test_options']['stonewright_pexels_api_key']  = 'synthetic-pexels-key-0002';
		$GLOBALS['stonewright_test_options']['stonewright_companion_token'] = 'synthetic-bridge-token-0003';
		$GLOBALS['stonewright_test_options']['stonewright_companion_url']   = 'http://127.0.0.1:8765';
		$GLOBALS['stonewright_test_options']['stonewright_section_reuse']   = 'off';
		update_user_meta( 1, SetupState::META_AUTH_METHOD, 'application-password' );
		$GLOBALS['stonewright_test_app_passwords'][1] = [
			[ 'uuid' => 'uuid-one', 'name' => 'Example laptop', 'created' => 1710000000 ],
			[ 'uuid' => 'uuid-two', 'name' => 'Example desktop', 'created' => 1710003600 ],
		];
		$_GET['stonewright_app_password'] = 'app_password_revoked';
		DomainLock::lock();

		$this->assert_screen( 'password-production', self::render() );
	}

	/** A site that cannot offer OAuth, is switched off, runs production in development mode and moved domains. */
	public function test_a_site_that_is_off_on_plain_http_and_has_a_domain_mismatch(): void {
		HttpSurface::use_site( HttpRig::site( true, 'production', 'pretty', 'http://example.com' ) );
		$GLOBALS['stonewright_test_environment_type']               = 'production';
		$GLOBALS['stonewright_test_options']['stonewright_enabled'] = '0';
		$GLOBALS['stonewright_test_options']['stonewright_mode']    = 'development';
		update_option( 'stonewright_locked_domain', 'https://old.example.test/' );
		update_option(
			'stonewright_domain_lock_prior',
			[ 'origin' => 'https://older.example.test/', 'snapshotted_at' => time(), 'expires_at' => time() + 3600 ]
		);
		$_GET['settings-updated']         = 'true';
		$_GET['stonewright_app_password'] = 'app_password_error';

		$this->assert_screen( 'off-http-mismatch', self::render() );
	}

	/** Enabled, on a production site in production-safe mode, with no client connected and a locked, matching domain. */
	public function test_a_production_safe_site_with_nothing_connected_yet(): void {
		$GLOBALS['stonewright_test_environment_type']               = 'production';
		$GLOBALS['stonewright_test_options']['stonewright_enabled'] = '1';
		$GLOBALS['stonewright_test_options']['stonewright_mode']    = 'production-safe';
		$GLOBALS['stonewright_test_options']['stonewright_mcp_surface'] = 'bootstrap';
		DomainLock::lock();

		$this->assert_screen( 'production-safe-empty', self::render() );
	}

	public function test_nothing_is_printed_without_manage_options(): void {
		$GLOBALS['stonewright_test_user_caps'] = [];

		self::assertSame( '', self::render() );
	}

	// -------------------------------------------------------------------------
	// Wiring: the hooks the screen listens on and the options it saves
	// -------------------------------------------------------------------------

	public function test_the_screen_listens_on_these_hooks(): void {
		$GLOBALS['stonewright_test_actions'] = [];

		ConfigurationPage::register();

		$wired = [];
		foreach ( $GLOBALS['stonewright_test_actions'] as $hook => $callbacks ) {
			foreach ( $callbacks as $registered ) {
				$wired[] = $hook . ' => ' . ( is_array( $registered['callback'] ) ? (string) $registered['callback'][0] . '::' . (string) $registered['callback'][1] : 'closure' );
			}
		}
		sort( $wired );
		$GLOBALS['stonewright_test_actions'] = [];

		$base = 'Stonewright\\WpMcp\\Admin\\';
		self::assertSame(
			[
				'admin_init => ' . $base . 'ConfigurationPage::register_settings',
				'admin_menu => ' . $base . 'ConfigurationPage::add_menu',
				'admin_post_stonewright_generate_application_password => ' . $base . 'ConfigurationPage::handle_generate_application_password',
				'admin_post_stonewright_oauth_disconnect => ' . $base . 'Connect\\ConnectedClients::handle',
				'admin_post_stonewright_rebind_domain_lock => ' . $base . 'ConfigurationPage::handle_rebind_domain_lock',
				'admin_post_stonewright_reset_domain_lock => ' . $base . 'ConfigurationPage::handle_reset_domain_lock',
				'admin_post_stonewright_revoke_application_password => ' . $base . 'ConfigurationPage::handle_revoke_application_password',
				'admin_post_stonewright_rollback_domain_lock => ' . $base . 'ConfigurationPage::handle_rollback_domain_lock',
				'admin_post_stonewright_run_diagnostics => ' . $base . 'ConfigurationPage::handle_run_diagnostics',
				'wp_ajax_stonewright_apply_mcp_surface => ' . $base . 'ConfigurationPage::handle_apply_mcp_surface',
				'wp_ajax_stonewright_run_diagnostics => ' . $base . 'ConfigurationPage::handle_ajax_run_diagnostics',
				'wp_ajax_stonewright_set_setup_client => ' . $base . 'ConfigurationPage::handle_set_setup_client',
			],
			$wired
		);
	}

	public function test_the_settings_form_saves_these_options_in_one_group(): void {
		$GLOBALS['stonewright_test_registered_settings'] = [];

		ConfigurationPage::register_settings();

		$saved = [];
		foreach ( $GLOBALS['stonewright_test_registered_settings'] as $registered ) {
			$saved[] = $registered['group'] . ' ' . $registered['option'] . ' ' . (string) ( $registered['args']['type'] ?? '' ) . ' default=' . var_export( $registered['args']['default'] ?? null, true );
		}
		$GLOBALS['stonewright_test_registered_settings'] = [];

		self::assertSame(
			[
				'stonewright_settings stonewright_enabled boolean default=false',
				'stonewright_settings stonewright_install_mode string default=\'auto\'',
				'stonewright_settings stonewright_site_alias string default=\'\'',
				'stonewright_settings stonewright_site_environment string default=\'\'',
				'stonewright_settings stonewright_custom_instructions_enabled boolean default=true',
				'stonewright_settings stonewright_essential_tools_mode boolean default=true',
				'stonewright_settings stonewright_mcp_surface string default=\'essential\'',
				'stonewright_settings stonewright_mode string default=\'development\'',
				'stonewright_settings stonewright_companion_url string default=\'http://127.0.0.1:8765\'',
				'stonewright_settings stonewright_companion_token string default=\'\'',
				'stonewright_settings stonewright_elementor_v4_atomic boolean default=false',
				'stonewright_settings stonewright_unsplash_access_key string default=\'\'',
				'stonewright_settings stonewright_pexels_api_key string default=\'\'',
			],
			$saved
		);
	}

	// -------------------------------------------------------------------------
	// Fixtures
	// -------------------------------------------------------------------------

	private function assert_screen( string $name, string $html ): void {
		$html = $this->without_random_values( $html );
		$this->assert_matches_file( $name . '.inventory.txt', self::inventory( $html ) );
	}

	/** The connected client's key and its disconnect nonce are generated per run. */
	private function without_random_values( string $html ): string {
		foreach ( ConnectedClients::current() as $connection ) {
			$key  = $connection['client_key'];
			$html = str_replace( 'test-nonce-' . md5( ConnectedClients::NONCE_PREFIX . $key ), '{disconnect-nonce}', $html );
			$html = str_replace( $key, '{client-key}', $html );
		}

		return $html;
	}

	private function assert_matches_file( string $file, string $actual ): void {
		$path = self::FIXTURES . '/' . $file;
		if ( '1' === getenv( 'STONEWRIGHT_UPDATE_SNAPSHOTS' ) ) {
			if ( ! is_dir( self::FIXTURES ) ) {
				mkdir( self::FIXTURES, 0777, true );
			}
			file_put_contents( $path, $actual );
		}
		self::assertFileExists( $path, 'Missing fixture ' . $file . '. Run with STONEWRIGHT_UPDATE_SNAPSHOTS=1 to create it.' );
		self::assertSame( (string) file_get_contents( $path ), $actual, $file . ' differs from the Setup screen.' );
	}

	private static function render(): string {
		ob_start();
		try {
			ConfigurationPage::render();
		} finally {
			$html = (string) ob_get_clean();
		}
		return $html;
	}

	/**
	 * What a user and the scripts can act on: forms and their controls in order, hooks, links, headings and ids.
	 */
	private static function inventory( string $html ): string {
		$html = str_replace( STONEWRIGHT_VERSION, '{VERSION}', $html );
		$doc  = new \DOMDocument();
		libxml_use_internal_errors( true );
		$doc->loadHTML( '<?xml encoding="utf-8" ?><body>' . $html . '</body>' );
		libxml_clear_errors();
		$xpath = new \DOMXPath( $doc );

		$out = [];

		$out[] = '# headings';
		foreach ( $xpath->query( '//h1|//h2|//h3|//h4' ) ?: [] as $node ) {
			$out[] = strtolower( $node->nodeName ) . ' ' . self::text( $node );
		}

		$out[] = '# forms and controls';
		$out[] = '(outside any form)';
		foreach ( self::control_lines( $xpath, null ) as $line ) {
			$out[] = '  ' . $line;
		}
		foreach ( $xpath->query( '//form' ) ?: [] as $form ) {
			/** @var \DOMElement $form */
			$out[] = 'form action=' . $form->getAttribute( 'action' ) . ' method=' . $form->getAttribute( 'method' ) . ' class="' . $form->getAttribute( 'class' ) . '"';
			foreach ( self::control_lines( $xpath, $form ) as $line ) {
				$out[] = '  ' . $line;
			}
		}

		$out[] = '# links';
		foreach ( $xpath->query( '//a[@href]' ) ?: [] as $link ) {
			/** @var \DOMElement $link */
			$out[] = $link->getAttribute( 'href' ) . ' => ' . self::text( $link );
		}

		$out[] = '# hooks (data-stonewright-*, data-sw-*, role, aria-controls)';
		$hooks = [];
		foreach ( $xpath->query( '//*' ) ?: [] as $el ) {
			/** @var \DOMElement $el */
			foreach ( $el->attributes ?? [] as $attr ) {
				$name = $attr->nodeName;
				if ( str_starts_with( $name, 'data-stonewright-' ) || str_starts_with( $name, 'data-sw-' ) || 'aria-controls' === $name ) {
					$key           = $el->nodeName . ' ' . $name . '=' . ( in_array( $name, [ 'data-stonewright-text-full', 'data-sw-ui-copy-text' ], true ) ? '(prompt)' : $attr->nodeValue );
					$hooks[ $key ] = ( $hooks[ $key ] ?? 0 ) + 1;
				}
			}
		}
		ksort( $hooks );
		foreach ( $hooks as $key => $count ) {
			$out[] = $key . ' x' . $count;
		}

		$out[] = '# hidden';
		foreach ( $xpath->query( '//*[@hidden]' ) ?: [] as $el ) {
			/** @var \DOMElement $el */
			$out[] = $el->nodeName . ' id=' . $el->getAttribute( 'id' ) . ' class="' . $el->getAttribute( 'class' ) . '"';
		}

		$out[] = '# ids';
		$ids   = [];
		foreach ( $xpath->query( '//*[@id]' ) ?: [] as $el ) {
			/** @var \DOMElement $el */
			$ids[ $el->getAttribute( 'id' ) ] = ( $ids[ $el->getAttribute( 'id' ) ] ?? 0 ) + 1;
		}
		ksort( $ids );
		foreach ( $ids as $id => $count ) {
			$out[] = $id . ( $count > 1 ? ' x' . $count : '' );
		}

		return implode( "\n", $out ) . "\n";
	}

	/**
	 * @return list<string>
	 */
	private static function control_lines( \DOMXPath $xpath, ?\DOMElement $form ): array {
		$lines = [];
		$query = null === $form ? '//*[self::input or self::select or self::textarea or self::button][not(ancestor::form)]' : './/*[self::input or self::select or self::textarea or self::button]';
		foreach ( $xpath->query( $query, $form ) ?: [] as $control ) {
			/** @var \DOMElement $control */
			$parts = [ $control->nodeName ];
			foreach ( [ 'type', 'name', 'id', 'value', 'placeholder', 'autocomplete', 'class' ] as $attr ) {
				if ( $control->hasAttribute( $attr ) ) {
					$parts[] = $attr . '=' . $control->getAttribute( $attr );
				}
			}
			foreach ( [ 'checked', 'disabled', 'required', 'readonly', 'hidden' ] as $flag ) {
				if ( $control->hasAttribute( $flag ) ) {
					$parts[] = $flag;
				}
			}
			foreach ( $control->attributes ?? [] as $attr ) {
				if ( str_starts_with( $attr->nodeName, 'data-' ) && 'data-confirm' !== $attr->nodeName ) {
					$parts[] = $attr->nodeName . '=' . ( 'data-sw-ui-copy-text' === $attr->nodeName ? '(prompt)' : $attr->nodeValue );
				}
			}
			if ( 'button' === $control->nodeName ) {
				$parts[] = 'text="' . self::text( $control ) . '"';
			}
			if ( 'select' === $control->nodeName ) {
				$selected = [];
				foreach ( $xpath->query( './/option', $control ) ?: [] as $option ) {
					/** @var \DOMElement $option */
					$selected[] = $option->getAttribute( 'value' ) . ( $option->hasAttribute( 'selected' ) ? '*' : '' );
				}
				$parts[] = 'options=' . implode( ',', $selected );
			}
			$lines[] = implode( ' ', $parts );
		}

		return $lines;
	}

	private static function text( \DOMNode $node ): string {
		return trim( (string) preg_replace( '/\s+/', ' ', $node->textContent ) );
	}
}
