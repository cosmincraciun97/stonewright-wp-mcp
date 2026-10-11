<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Admin\AbilitiesPage;
use Stonewright\WpMcp\Admin\AdminBarIndicator;
use Stonewright\WpMcp\Admin\AuditLogPage;
use Stonewright\WpMcp\Admin\ConfigurationPage;
use Stonewright\WpMcp\Admin\McpbBundle;
use Stonewright\WpMcp\Admin\MemoryInstructionsPage;
use Stonewright\WpMcp\Admin\Pages\ContextPage;
use Stonewright\WpMcp\Admin\RescuePage;
use Stonewright\WpMcp\Admin\SandboxPage;
use Stonewright\WpMcp\Security\AccessDenied;

/**
 * An admin page or handler that refuses a user answers 403, not the default 500 of wp_die(), and keeps its message.
 *
 * @covers \Stonewright\WpMcp\Security\AccessDenied
 */
final class AccessDeniedTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_user_logged_in']       = true;
		$GLOBALS['stonewright_test_user_caps']            = [ 'read' => true ];
		$GLOBALS['stonewright_test_current_user_id']      = 5;
		$GLOBALS['stonewright_test_last_wp_die_response'] = null;
		$GLOBALS['stonewright_test_last_wp_die_message']   = null;
		$_POST                                            = [];
		$_GET                                             = [];
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_user_caps']       = [];
		$GLOBALS['stonewright_test_current_user_id'] = 0;
	}

	public function test_the_helper_dies_with_403_and_the_message_unchanged(): void {
		try {
			AccessDenied::deny( 'You do not have permission to do this.' );
			self::fail( 'Expected wp_die.' );
		} catch ( \RuntimeException $denied ) {
			self::assertSame( 'wp_die: You do not have permission to do this.', $denied->getMessage() );
		}

		self::assertSame( 403, $GLOBALS['stonewright_test_last_wp_die_response'] );
	}

	/**
	 * @return array<string, array{callable, string}>
	 */
	public static function handlers(): array {
		return [
			'abilities toggle'      => [ [ AbilitiesPage::class, 'handle_toggle' ], 'Insufficient permissions.' ],
			'application password'  => [ [ ConfigurationPage::class, 'handle_generate_application_password' ], 'Insufficient permissions.' ],
			'memory create'         => [ [ MemoryInstructionsPage::class, 'handle_create' ], 'You do not have permission to perform this action.' ],
			'sandbox save'          => [ [ SandboxPage::class, 'handle_save' ], 'Insufficient permissions.' ],
			'audit log page'        => [ [ AuditLogPage::class, 'render' ], 'You do not have permission to view the Stonewright audit log.' ],
			'rescue page'           => [ [ RescuePage::class, 'render' ], 'You do not have permission to view Stonewright Rescue.' ],
			'context save'          => [ [ ContextPage::class, 'handle_save' ], 'You do not have permission to do this.' ],
			'bundle download'       => [ [ McpbBundle::class, 'handle_download' ], 'You are not allowed to download this bundle.' ],
			'admin bar toggle'      => [ static fn () => AdminBarIndicator::apply_toggle( 'all' ), 'You are not allowed to toggle Stonewright abilities.' ],
		];
	}

	/**
	 * @dataProvider handlers
	 */
	public function test_a_handler_refuses_a_user_without_the_capability_with_403( callable $handler, string $message ): void {
		try {
			$handler();
			self::fail( 'Expected wp_die.' );
		} catch ( \RuntimeException $denied ) {
			self::assertSame( 'wp_die: ' . $message, $denied->getMessage() );
		}

		self::assertSame( 403, $GLOBALS['stonewright_test_last_wp_die_response'] );
	}

	public function test_no_not_allowed_message_in_the_plugin_is_left_to_the_default_500(): void {
		$root     = dirname( __DIR__, 3 ) . '/includes';
		$offences = [];
		foreach ( new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS ) ) as $file ) {
			if ( 'php' !== $file->getExtension() ) {
				continue;
			}
			$text = (string) file_get_contents( $file->getPathname() );
			// A wp_die( with a message about permission or access, and no response code in the call.
			if ( preg_match_all( '/wp_die\(\s*esc_html__\(\s*\'([^\']*(?:permission|not allowed|Security check|Queue access)[^\']*)\',[^;]*?;/s', $text, $matches, PREG_SET_ORDER ) ) {
				foreach ( $matches as $match ) {
					if ( ! str_contains( $match[0], "'response'" ) ) {
						$offences[] = substr( $file->getPathname(), strlen( $root ) + 1 ) . ': ' . $match[1];
					}
				}
			}
		}

		self::assertSame( [], $offences, 'These answer 500 by default; use AccessDenied::deny().' );
	}
}
