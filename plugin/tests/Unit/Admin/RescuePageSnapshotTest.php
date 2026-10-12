<?php
/**
 * The Rescue page printed once with changes in every state it shows, as static pages for the browser spec.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Admin;

use DOMDocument;
use DOMXPath;
use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Admin\MenuRegistry;
use Stonewright\WpMcp\Admin\RescuePage;
use Stonewright\WpMcp\Admin\Ui\Html;
use Stonewright\WpMcp\Admin\Ui\Icon;
use Stonewright\WpMcp\Security\Backup;
use Stonewright\WpMcp\Security\ChangeJournal;
use Stonewright\WpMcp\Security\ChangeLedger;
use Stonewright\WpMcp\Tests\Unit\Admin\Fixtures\LedgerFixture;
use Stonewright\WpMcp\Tests\Unit\Assets\CssSource;

/**
 * plugin/tests/fixtures/admin-ui/rescue-page.html is the Rescue page with a change that needs attention in each state
 * (a recorded fatal, a failed rollback, a change whose health check could not start, one with no result, one nothing
 * can roll back), a safe-mode panel and recent changes the journal alone keeps. rescue-review.html is the review of one
 * rollback in production-safe mode. Both are rendered by the page class from journal entries with synthetic content,
 * next to the stylesheets and scripts of the shell and the page. e2e/tests/action-groups.spec.ts serves them from the
 * repository and measures every action row in a real browser at every viewport, whatever the site's journal holds.
 * Regenerate them with STONEWRIGHT_UPDATE_FIXTURES=1 after changing the page.
 *
 * @covers \Stonewright\WpMcp\Admin\RescuePage
 */
final class RescuePageSnapshotTest extends TestCase {

	use LedgerFixture;

	private const LIST   = '/fixtures/admin-ui/rescue-page.html';
	private const REVIEW = '/fixtures/admin-ui/rescue-review.html';

	private const FATAL    = 'cs-1111111111111111111111aa';
	private const FAILED   = 'cs-2222222222222222222222bb';
	private const UNSTARTED = 'cs-3333333333333333333333cc';
	private const NO_RESULT = 'cs-4444444444444444444444dd';
	private const NO_RECIPE = 'cs-5555555555555555555555ee';
	private const VERIFIED  = 'cs-6666666666666666666666ff';

	protected function setUp(): void {
		$this->ledger_up();
		$GLOBALS['stonewright_test_options']       = [ 'stonewright_mode' => 'development' ];
		$GLOBALS['stonewright_test_user_caps']     = [ 'manage_options' => true ];
		$GLOBALS['stonewright_test_users']         = [ (object) [ 'ID' => 7, 'user_login' => 'editor-one', 'display_name' => 'Editor One' ] ];
		$GLOBALS['stonewright_test_posts']         = [];
		$GLOBALS['stonewright_test_wpdb_inserts']  = [];
		$GLOBALS['stonewright_test_transients']    = [];
		$_GET  = [];
		$_POST = [];
		ChangeJournal::reset_for_tests();
		RescuePage::reset_for_tests();
		RescuePage::set_safe_mode_resolver( static fn ( int $user_id ): string => 'https://example.test/wp-admin/?sw_rescue=fixture' );
		MenuRegistry::reset_for_tests();
		Html::reset_ids();
		Icon::reset_for_tests();
	}

	protected function tearDown(): void {
		RescuePage::set_safe_mode_resolver( null );
		RescuePage::reset_for_tests();
		ChangeJournal::reset_for_tests();
		MenuRegistry::reset_for_tests();
		$this->ledger_down();
		$GLOBALS['stonewright_test_options']   = [];
		$GLOBALS['stonewright_test_user_caps'] = [];
		$GLOBALS['stonewright_test_users']     = [];
		$GLOBALS['stonewright_test_posts']     = [];
		$_GET = [];
	}

	private static function path( string $fixture ): string {
		return dirname( __DIR__, 2 ) . $fixture;
	}

	/** A change to one post that the rescue recipe can put back. */
	private function arm( string $id, int $post_id, string $title, int $ago ): void {
		$GLOBALS['stonewright_test_posts'][ $post_id ] = (object) [
			'ID' => $post_id, 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => $title, 'post_content' => 'body',
			'post_excerpt' => '', 'post_parent' => 0, 'post_name' => 'page-' . $post_id, 'meta' => [],
		];
		$this->now = 1789300800 - $ago;
		$snapshot  = Backup::snapshot_post( $post_id );
		ChangeJournal::arm(
			[
				'change_set_id' => $id,
				'ability'       => 'stonewright/elementor-v3-batch-mutate',
				'resource_type' => 'post',
				'resource_key'  => (string) $post_id,
				'recipe'        => [ 'type' => 'post_snapshot', 'ref' => $snapshot ],
				'recipe_detail' => [ 'post_id' => $post_id, 'snapshot_id' => $snapshot ],
				'scope'         => 'post',
			]
		);
	}

	/** A row of the change ledger for a journal entry, so that the row links to its diff. */
	private function ledger_row( string $id, int $post_id, string $summary ): void {
		$row = ChangeLedger::record( [ 'change_id' => $id, 'ability' => 'stonewright/elementor-v3-batch-mutate', 'family' => 'elementor', 'resource_type' => 'post', 'resource_id' => (string) $post_id, 'summary' => $summary ] );
		self::assertIsArray( $row, is_wp_error( $row ) ? $row->get_error_message() : '' );
	}

	private function populate(): void {
		$legs = static fn ( string $status, int $http, string $reason ): array => [ 'status' => 'unavailable' === $status ? 'unavailable' : $status, 'coverage' => 'none', 'legs' => [ [ 'leg' => 'home', 'status' => $status, 'http' => $http, 'reason' => $reason, 'ms' => 40 ] ], 'checked_at' => 1789300000 ];

		$this->arm( self::FATAL, 31, 'Pricing page', 7200 );
		$this->flip_to_incident( self::FATAL );
		$this->arm( self::FAILED, 32, 'Contact page', 5400 );
		ChangeJournal::settle( self::FAILED, 'rollback_failed', [ 'probe' => $legs( 'failed', 500, 'critical_error_page' ), 'rollback' => [ 'status' => 'failed', 'at' => 1789299000, 'by' => 'auto', 'recipe' => 'post_snapshot', 'detail' => 'snapshot_missing', 'site' => 'still_failing' ] ] );
		$this->arm( self::UNSTARTED, 33, 'About us', 3600 );
		ChangeJournal::record_probe( self::UNSTARTED, $legs( 'unavailable', 0, 'token_unavailable' ) );
		$this->arm( self::NO_RESULT, 34, 'Services', 1800 );
		$this->now = 1789300800 - 1700;
		$none = ChangeJournal::arm( [ 'change_set_id' => self::NO_RECIPE, 'ability' => 'stonewright/custom-code-provider', 'resource_type' => 'custom_code', 'resource_key' => 'wpcode:9', 'recipe' => [ 'type' => 'none', 'ref' => '' ] ] );
		self::assertIsArray( $none );
		ChangeJournal::settle( self::NO_RECIPE, 'rollback_failed' );
		$this->arm( self::VERIFIED, 35, 'Blog index', 600 );
		ChangeJournal::settle( self::VERIFIED, 'verified', [ 'probe' => [ 'status' => 'passed', 'coverage' => 'full', 'legs' => [ [ 'leg' => 'post', 'status' => 'passed', 'http' => 200, 'reason' => 'ok', 'ms' => 90 ] ], 'checked_at' => 1789300200 ] ] );

		// Two of them are in the history, with the summary the writer gave.
		$this->ledger_row( self::FAILED, 32, 'Changed the hero heading of the contact page.' );
		$this->ledger_row( self::UNSTARTED, 33, 'Changed the team section of the page.' );

		$this->now = 1789300800;
	}

	/** What the safe-boot helper does after a fatal: flip the entry in the shared file, then let the page import it. */
	private function flip_to_incident( string $id ): void {
		$uploads = (string) ( $GLOBALS['stonewright_test_upload_dir']['basedir'] ?? '' );
		$file    = new \Stonewright\WpMcp\Security\ChangeJournalFile( $uploads . '/stonewright-state/' . (string) get_option( ChangeJournal::FILE_OPTION, '' ) );
		$file->transaction(
			static function ( array $document ) use ( $id ): array {
				foreach ( $document['entries'] as $index => $entry ) {
					if ( $entry['id'] === $id ) {
						$document['entries'][ $index ]['state']    = 'incident';
						$document['entries'][ $index ]['incident'] = [ 'recorded_at' => 1789300700, 'file' => 'wp-content/themes/site-a/functions.php', 'line' => 9, 'type' => 1, 'message_sha256' => str_repeat( 'c', 64 ), 'source' => 'shutdown' ];
					}
				}
				return $document;
			}
		);
		clearstatcache();
		ChangeJournal::sync_from_file();
	}

	private function page( string $title, array $get ): string {
		$this->populate();
		$_GET = $get;
		ob_start();
		try {
			RescuePage::render();
			// The band's mark is printed only when the plugin address is defined, which depends on the test that ran before.
			$page = (string) preg_replace( '#<img class="sw-ui-band__logo"[^>]*>#', '', (string) ob_get_contents() );
		} finally {
			ob_end_clean();
		}
		// The tokens are random; the fixture holds one that looks like them.
		$page = (string) preg_replace( '/value="swc_[^"]+"/', 'value="swc_fixture"', $page );

		return "<!doctype html>\n"
			. '<html lang="en">' . "\n"
			. '<head>' . "\n"
			. '<meta charset="utf-8">' . "\n"
			. '<meta name="viewport" content="width=device-width, initial-scale=1">' . "\n"
			. '<title>' . $title . '</title>' . "\n"
			. '<link rel="stylesheet" href="sw-ui.css">' . "\n"
			. '<link rel="stylesheet" href="shell.css">' . "\n"
			. '<link rel="stylesheet" href="admin.css">' . "\n"
			. '<link rel="stylesheet" href="stonewright-admin.css">' . "\n"
			. '<link rel="stylesheet" href="rescue.css">' . "\n"
			. '<style>body{margin:0;background:#f0f0f1;color:#1d2327;font:13px/1.4 sans-serif}#wpcontent{padding:0 20px}.wrap h1{font-size:23px;font-weight:400;margin:0;padding:9px 0 4px;line-height:1.3}code{background:rgba(0,0,0,.07);padding:3px 5px 2px 1px;margin:0 1px;font-size:13px}</style>' . "\n"
			. '</head>' . "\n"
			. '<body class="wp-admin wp-core-ui admin-color-modern">' . "\n"
			. Icon::sprite() . "\n"
			. '<div id="wpcontent"><main id="wpbody-content">' . "\n"
			. $page . "\n"
			. '</main></div>' . "\n"
			. '<script src="sw-ui.js"></script>' . "\n"
			. '<script src="rescue.js"></script>' . "\n"
			. '</body>' . "\n"
			. '</html>' . "\n";
	}

	private function list_document(): string {
		return $this->page( 'Rescue', [ 'rescue' => 'rechecked_unreachable', 'incident' => self::UNSTARTED ] );
	}

	private function review_document(): string {
		$GLOBALS['stonewright_test_options']['stonewright_mode'] = 'production-safe';

		return $this->page( 'Rescue: roll back a change', [ 'review' => self::NO_RESULT ] );
	}

	/** @dataProvider fixtures */
	public function test_the_checked_in_page_is_what_the_page_class_renders( string $name ): void {
		$html = 'list' === $name ? $this->list_document() : $this->review_document();
		$path = self::path( 'list' === $name ? self::LIST : self::REVIEW );
		if ( '1' === getenv( 'STONEWRIGHT_UPDATE_FIXTURES' ) ) {
			$dir = dirname( $path );
			if ( ! is_dir( $dir ) ) {
				mkdir( $dir, 0777, true );
			}
			file_put_contents( $path, $html );
		}

		self::assertFileExists( $path );
		self::assertSame( file_get_contents( $path ), $html, 'Regenerate with STONEWRIGHT_UPDATE_FIXTURES=1 after changing the page.' );
		self::assertStringNotContainsString( "\r", $html );
	}

	/** @return array<string, array{0: string}> */
	public static function fixtures(): array {
		return [ 'the list' => [ 'list' ], 'the review' => [ 'review' ] ];
	}

	public function test_the_list_carries_what_the_browser_spec_measures(): void {
		$dom = new DOMDocument();
		libxml_use_internal_errors( true );
		$dom->loadHTML( $this->list_document() );
		libxml_clear_errors();
		$xpath = new DOMXPath( $dom );

		self::assertSame( 5, $xpath->query( '//table[@data-sw-rescue-table="attention"]//tr[@data-sw-rescue-row]' )->length, 'Five changes need attention: a fatal, a failed rollback, one nothing can roll back and two that were never verified.' );
		self::assertSame( 1, $xpath->query( '(//tr[@data-sw-rescue-row="' . self::UNSTARTED . '"]//div[@data-sw-actions])[1]//a[contains(@class, "sw-ui-btn--danger")]' )->length, 'The not-verified row has Roll back...' );
		self::assertSame( 1, $xpath->query( '(//tr[@data-sw-rescue-row="' . self::UNSTARTED . '"]//div[@data-sw-actions])[1]//button[@type="submit"]' )->length, '...Check again...' );
		self::assertSame( 1, $xpath->query( '(//tr[@data-sw-rescue-row="' . self::UNSTARTED . '"]//div[@data-sw-actions])[1]//a[starts-with(normalize-space(.), "View diff")]' )->length, '...and View diff, in one row.' );
		self::assertSame( 1, $xpath->query( '//section[@class="sw-rescue-panel"]//div[@data-sw-actions]//button' )->length, 'The safe-mode panel.' );
		self::assertGreaterThan( 0, $xpath->query( '//*[@data-sw-rescue-fallback]//tr[@data-sw-rescue-row]' )->length, 'A recent change only the journal keeps.' );
		self::assertSame( 0, $xpath->query( '//script[not(@src)]' )->length, 'No inline script.' );

		$ids = [];
		foreach ( $xpath->query( '//*[@id]' ) ?: [] as $node ) {
			$ids[] = $node->getAttribute( 'id' );
		}
		self::assertSame( array_values( array_unique( $ids ) ), $ids, 'Ids are unique.' );
	}

	public function test_the_review_carries_the_form_and_the_two_buttons_in_one_row(): void {
		$dom = new DOMDocument();
		libxml_use_internal_errors( true );
		$dom->loadHTML( $this->review_document() );
		libxml_clear_errors();
		$xpath = new DOMXPath( $dom );

		self::assertSame( 1, $xpath->query( '//section[@data-sw-rescue-review]//form[@method="post"]' )->length );
		self::assertSame( 1, $xpath->query( '//section[@data-sw-rescue-review]//input[@data-sw-rescue-phrase="ROLL BACK"]' )->length );
		self::assertSame( 1, $xpath->query( '//section[@data-sw-rescue-review]//div[@data-sw-actions]//a[normalize-space(.)="Cancel"]' )->length );
		self::assertSame( 1, $xpath->query( '//section[@data-sw-rescue-review]//div[@data-sw-actions]//button[@data-sw-rescue-submit]' )->length );
	}

	public function test_the_stylesheets_the_fixtures_link_exist(): void {
		foreach ( [ 'admin/sw-ui.css', 'admin/shell.css', 'admin/admin.css', 'css/stonewright-admin.css', 'admin/pages/rescue.css' ] as $asset ) {
			self::assertNotSame( '', CssSource::read( $asset ) );
		}
	}
}
