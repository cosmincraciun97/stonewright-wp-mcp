<?php
/**
 * Shared fixture for the rollback engine tests: fake posts, options, menus and sidebars, a ledger,
 * a theme folder, a site that answers its own health checks, and the audit rows the engine writes.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Security\Rollback;

use Stonewright\WpMcp\Security\Adapters\PostAdapter;
use Stonewright\WpMcp\Security\ChangeJournal;
use Stonewright\WpMcp\Security\ChangeLedger;
use Stonewright\WpMcp\Security\HealthProbe;
use Stonewright\WpMcp\Security\Rollback\RollbackFamilies;
use Stonewright\WpMcp\Tests\Unit\Security\Adapters\FamilyLedgerTestCase;

/**
 * Builds on the option, menu and widget ledger fixture. The ledger rows of these tests are written the way
 * the adapters write them (record before, change the resource, settle with the after image), without a
 * journal entry, so the engine takes the adapter path unless a test arms the journal on purpose.
 */
abstract class RollbackTestCase extends FamilyLedgerTestCase {

	protected const POST = 31;

	/** @var callable():string */
	protected $site_state;

	protected string $theme = '';

	/** @var array<string, mixed> The test globals as they were before the test, put back afterwards. */
	private array $globals_before = [];

	protected function setUp(): void {
		foreach ( $GLOBALS as $name => $value ) {
			if ( str_starts_with( (string) $name, 'stonewright_test_' ) ) {
				$this->globals_before[ (string) $name ] = $value;
			}
		}
		parent::setUp();
		// The claim is a row of the options table; the real table has a unique key on the name.
		$this->db->unique[ $this->db->prefix . 'options' ] = [ 'option_name' ];
		$this->theme                                       = $this->uploads . '/themes/site-a';
		mkdir( $this->theme, 0777, true );
		$GLOBALS['stonewright_test_stylesheet_directory'] = $this->theme;
		$GLOBALS['stonewright_test_custom_css']           = '';
		$GLOBALS['stonewright_test_user_can_callback']    = static fn ( string $cap ): bool => true;
		RollbackFamilies::reset_for_tests();
		$this->site( static fn (): string => 'healthy' );
	}

	protected function tearDown(): void {
		RollbackFamilies::reset_for_tests();
		unset( $GLOBALS['stonewright_test_stylesheet_directory'], $GLOBALS['stonewright_test_custom_css'], $GLOBALS['stonewright_test_nonce_invalid'] );
		parent::tearDown();
		// The shared fixtures reset many test globals and restore few; other suites expect what the bootstrap set.
		foreach ( array_keys( $GLOBALS ) as $name ) {
			if ( str_starts_with( (string) $name, 'stonewright_test_' ) && ! array_key_exists( (string) $name, $this->globals_before ) ) {
				unset( $GLOBALS[ $name ] );
			}
		}
		foreach ( $this->globals_before as $name => $value ) {
			$GLOBALS[ $name ] = $value;
		}
		$this->globals_before = [];
	}

	/** @param callable():string $state healthy, broken or unavailable, asked on every request. */
	protected function site( callable $state ): void {
		$this->site_state = $state;
		HealthProbe::set_transport(
			function ( string $url ) {
				return match ( ( $this->site_state )() ) {
					'broken'      => [ 'response' => [ 'code' => 500 ], 'body' => '<body id="error-page"><p>There has been a critical error on this website.</p></body>', 'headers' => [] ],
					'unavailable' => new \WP_Error( 'http_request_failed', 'cURL error 7' ),
					default       => [
						'response' => [ 'code' => 200 ],
						'body'     => str_contains( $url, 'wp-json' ) ? '{"namespaces":["wp/v2"]}' : ( str_contains( $url, 'stonewright-rescue' ) ? 'data-sw-rescue-probe="ok"' : 'ok' ),
						'headers'  => [],
					],
				};
			}
		);
	}

	protected function as_user_without_manage_options(): void {
		$GLOBALS['stonewright_test_user_can_callback'] = static fn ( string $cap ): bool => 'manage_options' !== $cap;
	}

	protected function production_safe(): void {
		$GLOBALS['stonewright_test_options']['stonewright_mode'] = 'production-safe';
	}

	/** Drop every journal entry, so that the change is known to the ledger only. */
	protected function forget_journal(): void {
		unset( $GLOBALS['stonewright_test_options'][ ChangeJournal::DB_OPTION ], $GLOBALS['stonewright_test_options'][ ChangeJournal::OPEN_OPTION ] );
		ChangeJournal::reset_for_tests();
	}

	/**
	 * A change to a post, recorded the way the post adapter records it, with no journal entry.
	 *
	 * @param callable():void $write What the change does to the fake post.
	 * @return string The change id.
	 */
	protected function post_change( callable $write, int $post_id = self::POST, string $ability = 'stonewright/content-update-page' ): string {
		$id = PostAdapter::record_before( $ability, $post_id );
		self::assertNotSame( '', $id, 'The change was recorded.' );
		$write();
		self::assertTrue( PostAdapter::settle( $id, PostAdapter::capture_after( $post_id ), 'verified' ) );
		return $id;
	}

	protected function edit_post( string $field, string $value, int $post_id = self::POST ): void {
		$GLOBALS['stonewright_test_posts'][ $post_id ]->{$field} = $value;
	}

	protected function post_field( string $field, int $post_id = self::POST ): string {
		return (string) ( $GLOBALS['stonewright_test_posts'][ $post_id ]->{$field} ?? '' );
	}

	/** A page that a change turned from "Original body" into "Changed body". */
	protected function changed_page(): string {
		$this->make_post( self::POST, [ 'post_title' => 'Original title', 'post_content' => 'Original body' ] );
		return $this->post_change( fn () => $this->edit_post( 'post_content', 'Changed body' ) );
	}

	/** @return list<array<string, mixed>> The audit rows that the engine wrote. */
	protected function audit_rows(): array {
		$out = [];
		foreach ( $this->db->tables[ $this->db->prefix . 'stonewright_audit_log' ] ?? [] as $row ) {
			if ( 'stonewright/change-rollback' === ( $row['ability_name'] ?? '' ) ) {
				$out[] = $row;
			}
		}
		return $out;
	}

	/** @return array<string, mixed> */
	protected function audit_args( array $row ): array {
		$args = json_decode( (string) ( $row['sanitized_args'] ?? '' ), true );
		return is_array( $args ) ? $args : [];
	}

	/** @return list<string> */
	protected function blobs(): array {
		return array_map( 'basename', glob( $this->uploads . '/stonewright-state/blobs/*.gz' ) ?: [] );
	}

	/** @return array<string, mixed> */
	protected function row( string $change_id ): array {
		$row = ChangeLedger::get( $change_id );
		self::assertIsArray( $row );
		return $row;
	}

	/**
	 * A result that must be a WP_Error with this code.
	 *
	 * @param array<string, mixed>|\WP_Error $result
	 */
	protected function assert_refused( array|\WP_Error $result, string $code ): \WP_Error {
		self::assertInstanceOf( \WP_Error::class, $result, 'The run was refused.' );
		self::assertSame( $code, $result->get_error_code(), $result->get_error_message() );
		return $result;
	}
}
