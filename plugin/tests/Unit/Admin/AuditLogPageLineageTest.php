<?php
/**
 * The Audit Log shows the repair chain: A, its failed verification, repair B, and B verified.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Admin\AuditLineageDrawer;
use Stonewright\WpMcp\Admin\AuditLogPage;
use Stonewright\WpMcp\Security\IncidentStore;

/**
 * @covers \Stonewright\WpMcp\Admin\AuditLineageDrawer
 * @covers \Stonewright\WpMcp\Admin\AuditLogPage
 * @covers \Stonewright\WpMcp\Security\AuditLog::lineage_rows
 */
final class AuditLogPageLineageTest extends TestCase {

	private mixed $original_wpdb;

	/** @var array<string, mixed> */
	private array $original_actions = [];

	protected function setUp(): void {
		$this->original_wpdb    = $GLOBALS['wpdb'] ?? null;
		$this->original_actions = $GLOBALS['stonewright_test_actions'] ?? [];
		$GLOBALS['stonewright_test_user_caps']       = [ 'manage_options' => true ];
		$GLOBALS['stonewright_test_options']         = [ 'stonewright_mode' => 'development' ];
		$GLOBALS['stonewright_test_current_user_id'] = 7;
		$GLOBALS['stonewright_test_enqueued_styles']  = [];
		$GLOBALS['stonewright_test_enqueued_scripts'] = [];
		$_GET  = [];
		$_POST = [];
		IncidentStore::reset_for_tests();
		self::mount_drawer();
	}

	protected function tearDown(): void {
		if ( null !== $this->original_wpdb ) {
			$GLOBALS['wpdb'] = $this->original_wpdb;
		} else {
			unset( $GLOBALS['wpdb'] );
		}
		$GLOBALS['stonewright_test_user_caps']       = [];
		$GLOBALS['stonewright_test_options']         = [];
		$GLOBALS['stonewright_test_current_user_id'] = 0;
		$GLOBALS['stonewright_test_actions'] = $this->original_actions;
		$_GET = [];
		IncidentStore::reset_for_tests();
	}

	/** Registers the lineage drawer on the page hooks, once, as the plugin does at boot. */
	private static function mount_drawer(): void {
		foreach ( [ 'stonewright_audit_log_toolbar', 'stonewright_audit_log_change_set_cell' ] as $hook ) {
			remove_all_actions( $hook );
		}
		AuditLineageDrawer::register();
	}

	/**
	 * @param array<string, mixed> $overrides
	 * @return array<string, mixed>
	 */
	private static function row( int $id, string $change_set, array $overrides = [] ): array {
		return array_merge(
			[
				'id'                  => (string) $id,
				'ability_name'        => 'stonewright/elementor-v3-batch-mutate',
				'user_id'             => '1',
				'result_status'       => 'ok',
				'change_set_id'       => $change_set,
				'repair_of'           => '',
				'outcome'             => 'SUCCESS',
				'category'            => 'WRITE',
				'verification_status' => 'verified',
				'incident_id'         => '',
				'sanitized_args'      => '{}',
				'redacted_details'    => '{}',
				'duration_ms'         => '0',
				'mode'                => 'development',
				'created_at'          => '2026-10-01 10:00:' . sprintf( '%02d', $id % 60 ),
			],
			$overrides
		);
	}

	/** @return list<array<string, mixed>> Newest first, as the log lists them. */
	private static function repaired_rows(): array {
		return [
			self::row( 12, 'cs-B-repair', [ 'repair_of' => 'cs-A-failed', 'duration_ms' => '2400' ] ),
			self::row( 11, 'cs-A-failed', [ 'ability_name' => 'stonewright/elementor-post-write-verify', 'result_status' => 'error', 'outcome' => 'FAILED', 'category' => 'VERIFY', 'verification_status' => 'failed', 'incident_id' => str_repeat( 'ab', 32 ) ] ),
			self::row( 10, 'cs-A-failed' ),
		];
	}

	/**
	 * A failed change and $count repairs of it, newest first.
	 *
	 * @return list<array<string, mixed>>
	 */
	private static function fan_out_rows( int $count ): array {
		$rows = [];
		for ( $i = $count; $i >= 1; --$i ) {
			$rows[] = self::row( 100 + $i, 'cs-r' . $i, [ 'repair_of' => 'cs-A', 'outcome' => 'FAILED', 'verification_status' => 'failed', 'result_status' => 'error' ] );
		}
		$rows[] = self::row( 100, 'cs-A', [ 'outcome' => 'FAILED', 'verification_status' => 'failed', 'result_status' => 'error' ] );
		return $rows;
	}

	/**
	 * Fake wpdb: the page query returns $page_rows; a lineage query returns $lineage_rows.
	 *
	 * @param list<array<string, mixed>> $page_rows
	 * @param list<array<string, mixed>> $lineage_rows
	 */
	private function use_wpdb( array $page_rows, array $lineage_rows ): object {
		$GLOBALS['wpdb'] = new class( $page_rows, $lineage_rows ) {
			public $prefix = 'wp_';
			/** @var list<string> */
			public array $queries = [];

			/**
			 * @param list<array<string, mixed>> $page_rows
			 * @param list<array<string, mixed>> $lineage_rows
			 */
			public function __construct( private array $page_rows, private array $lineage_rows ) {
			}

			public function prepare( string $query, mixed ...$args ): string {
				return $query . ' /* ' . implode( ',', array_map( 'strval', $args ) ) . ' */';
			}

			public function get_var( string $query = '' ): string|int|null {
				return count( $this->page_rows );
			}

			/** @return array<int, array<string, mixed>> */
			public function get_results( string $query, string $output = 'OBJECT' ): array {
				$this->queries[] = $query;
				if ( str_contains( $query, 'stonewright_oauth_clients' ) ) {
					return [];
				}
				return str_contains( $query, 'repair_of IN' ) ? $this->lineage_rows : $this->page_rows;
			}
		};
		return $GLOBALS['wpdb'];
	}

	private static function render_page(): string {
		ob_start();
		AuditLogPage::render();
		return (string) ob_get_clean();
	}

	private static function xpath( string $html ): \DOMXPath {
		$document = new \DOMDocument();
		libxml_use_internal_errors( true );
		$document->loadHTML( '<?xml encoding="utf-8" ?>' . $html );
		libxml_clear_errors();
		return new \DOMXPath( $document );
	}

	/** An XPath predicate that matches one class token. */
	private static function has_class( string $class ): string {
		return 'contains(concat(" ", normalize-space(@class), " "), " ' . $class . ' ")';
	}

	/**
	 * @return list<\DOMElement>
	 */
	private static function find( \DOMXPath $xpath, string $query, ?\DOMNode $context = null ): array {
		$nodes = null === $context ? $xpath->query( $query ) : $xpath->query( $query, $context );
		$found = [];
		foreach ( false === $nodes ? [] : $nodes as $node ) {
			if ( $node instanceof \DOMElement ) {
				$found[] = $node;
			}
		}
		return $found;
	}

	private static function text( \DOMNode $node ): string {
		return trim( (string) preg_replace( '/\s+/', ' ', $node->textContent ) );
	}

	// -- Page hooks --------------------------------------------------------------

	public function test_the_page_mounts_the_drawer_through_two_hooks(): void {
		$seen = [];
		add_action(
			'stonewright_audit_log_toolbar',
			static function ( array $filters, array $rows, array $states ) use ( &$seen ): void {
				$seen['toolbar'] = [ $filters, count( $rows ), $states ];
			},
			20,
			3
		);
		add_action(
			'stonewright_audit_log_change_set_cell',
			static function ( array $row ) use ( &$seen ): void {
				$seen['cells'][] = (string) $row['change_set_id'];
			}
		);
		$rows = self::repaired_rows();
		$this->use_wpdb( $rows, array_reverse( $rows ) );

		self::render_page();

		self::assertSame( [], $seen['toolbar'][0], 'The toolbar hook receives the active filters.' );
		self::assertSame( 3, $seen['toolbar'][1], 'It receives the rows the page lists.' );
		self::assertSame( [ 'cs-B-repair', 'cs-A-failed', 'cs-A-failed' ], $seen['cells'], 'The cell hook runs once per row that names a change set.' );
		self::assertNotFalse( has_action( 'stonewright_audit_log_toolbar' ) );
		self::assertNotFalse( has_action( 'stonewright_audit_log_change_set_cell' ) );
	}

	public function test_the_page_renders_without_the_drawer_when_nothing_is_mounted(): void {
		remove_all_actions( 'stonewright_audit_log_toolbar' );
		remove_all_actions( 'stonewright_audit_log_change_set_cell' );
		$rows = self::repaired_rows();
		$this->use_wpdb( $rows, array_reverse( $rows ) );

		$html = self::render_page();

		self::assertStringContainsString( 'sw-audit-row', $html );
		self::assertStringNotContainsString( '<dialog', $html );
		self::assertStringNotContainsString( 'data-sw-lineage', $html );
	}

	// -- The Change set cell --------------------------------------------------------

	public function test_the_change_set_cell_has_a_short_id_a_copy_button_and_a_link_to_its_rows(): void {
		$id   = 'cs-3f2a91c2b5d84e7f6a0912ab';
		$rows = [ self::row( 1, $id ) ];
		$this->use_wpdb( $rows, $rows );

		$xpath = self::xpath( self::render_page() );

		$cells = self::find( $xpath, '//div[' . self::has_class( 'sw-audit-lineage-cell' ) . ']' );
		self::assertCount( 1, $cells );
		self::assertStringStartsWith( 'Change set:', self::text( $cells[0] ) );

		$code = self::find( $xpath, './/code[@title="' . $id . '"]', $cells[0] );
		self::assertCount( 1, $code );
		self::assertSame( 'cs-3f2a91c2b…', self::text( $code[0] ), 'The cell shows a short id; the full id is the tooltip.' );

		$copy = self::find( $xpath, './/button[' . self::has_class( 'sw-copy-prompt' ) . ']', $cells[0] );
		self::assertCount( 1, $copy );
		self::assertSame( 'button', $copy[0]->getAttribute( 'type' ) );
		self::assertSame( $id, $copy[0]->getAttribute( 'data-prompt' ), 'The copy control carries the full id.' );
		self::assertSame( 'Copy change set ID ' . $id, $copy[0]->getAttribute( 'aria-label' ) );
		self::assertSame( 'Copy', self::text( $copy[0] ) );

		$rows_link = self::find( $xpath, './/a[contains(@href, "change_set_id=' . $id . '")]', $cells[0] );
		self::assertCount( 1, $rows_link );
		self::assertSame( 'Show rows', self::text( $rows_link[0] ) );
		self::assertSame( 'Show rows of change set ' . $id, $rows_link[0]->getAttribute( 'aria-label' ) );

		self::assertSame( [], self::find( $xpath, '//*[@data-sw-lineage-open]' ), 'A change set with no relatives has no lineage to open.' );
		self::assertSame( [], self::find( $xpath, '//dialog' ), 'And the page carries no drawer.' );
	}

	public function test_short_ids_are_left_alone_and_long_ones_are_cut_with_an_ellipsis(): void {
		$rows = [ self::row( 1, 'cs-short' ), self::row( 2, 'cs-' . str_repeat( 'x', 90 ) ) ];
		$this->use_wpdb( $rows, $rows );

		$xpath = self::xpath( self::render_page() );

		self::assertCount( 1, self::find( $xpath, '//div[' . self::has_class( 'sw-audit-lineage-cell' ) . ']/code[.="cs-short"]' ) );
		self::assertCount( 1, self::find( $xpath, '//div[' . self::has_class( 'sw-audit-lineage-cell' ) . ']/code[.="cs-xxxxxxxxx…"]' ) );
	}

	public function test_a_row_without_a_change_set_has_no_cell(): void {
		$rows = [ self::row( 1, '' ) ];
		$this->use_wpdb( $rows, [] );

		self::assertStringNotContainsString( 'sw-audit-lineage-cell', self::render_page() );
	}

	public function test_the_show_rows_link_is_dropped_on_a_page_already_filtered_to_that_change_set(): void {
		$_GET = [ 'change_set_id' => 'cs-solo' ];
		$rows = [ self::row( 1, 'cs-solo' ) ];
		$this->use_wpdb( $rows, $rows );

		$xpath = self::xpath( self::render_page() );

		self::assertSame( [], self::find( $xpath, '//div[' . self::has_class( 'sw-audit-lineage-cell' ) . ']//a' ), 'The cell would link to the view the reader is already on.' );
	}

	// -- The filter chip ------------------------------------------------------------

	public function test_the_filter_chip_names_the_change_set_and_removes_only_that_filter(): void {
		$_GET = [ 'change_set_id' => 'cs-3f2a91c2b5d84e7f6a0912ab', 'status' => 'error' ];
		$rows = [ self::row( 1, 'cs-3f2a91c2b5d84e7f6a0912ab' ) ];
		$this->use_wpdb( $rows, $rows );

		$xpath = self::xpath( self::render_page() );

		$chips = self::find( $xpath, '//*[' . self::has_class( 'sw-audit-lineage-chip' ) . ']' );
		self::assertCount( 1, $chips );
		self::assertStringStartsWith( 'Change set cs-3f2a91c2b…', self::text( $chips[0] ) );
		self::assertCount( 1, self::find( $xpath, './/code[@title="cs-3f2a91c2b5d84e7f6a0912ab"]', $chips[0] ) );

		$remove = self::find( $xpath, './/a[@aria-label="Remove change set filter"]', $chips[0] );
		self::assertCount( 1, $remove, 'The remove control is named for what it does.' );
		$href = html_entity_decode( $remove[0]->getAttribute( 'href' ) );
		self::assertStringContainsString( 'page=stonewright-audit-log', $href );
		self::assertStringContainsString( 'status=error', $href, 'The other filters stay.' );
		self::assertStringNotContainsString( 'change_set_id', $href );
	}

	public function test_no_chip_is_shown_without_a_change_set_filter(): void {
		$_GET = [ 'status' => 'error' ];
		$rows = self::repaired_rows();
		$this->use_wpdb( $rows, array_reverse( $rows ) );

		self::assertStringNotContainsString( 'sw-audit-lineage-chip', self::render_page() );
	}

	public function test_the_chip_stays_when_the_filter_matches_no_rows(): void {
		$_GET = [ 'change_set_id' => 'cs-gone' ];
		$this->use_wpdb( [], [] );

		$html = self::render_page();

		self::assertStringContainsString( 'No audit entries', $html );
		self::assertStringContainsString( 'Remove change set filter', $html, 'An empty result still lets the reader take the filter off.' );
	}

	// -- The drawer -----------------------------------------------------------------

	public function test_a_repaired_change_gets_one_drawer_panel_and_a_lineage_button_on_each_of_its_rows(): void {
		$rows = self::repaired_rows();
		$this->use_wpdb( $rows, array_reverse( $rows ) );

		$xpath = self::xpath( self::render_page() );

		$drawers = self::find( $xpath, '//dialog[@id="sw-audit-lineage-drawer"]' );
		self::assertCount( 1, $drawers );
		self::assertSame( 'sw-audit-lineage-title', $drawers[0]->getAttribute( 'aria-labelledby' ) );
		self::assertTrue( $drawers[0]->hasAttribute( 'data-sw-lineage-drawer' ) );
		self::assertSame( 'Change set lineage', self::text( self::find( $xpath, './/h2[@id="sw-audit-lineage-title"]', $drawers[0] )[0] ) );
		self::assertCount( 1, self::find( $xpath, './/button[@data-sw-lineage-close]', $drawers[0] ) );

		$panels = self::find( $xpath, './/section[@data-sw-lineage-panel]', $drawers[0] );
		self::assertCount( 1, $panels, 'One panel per chain, however many rows the chain has on the page.' );
		self::assertTrue( $panels[0]->hasAttribute( 'hidden' ) );

		$buttons = self::find( $xpath, '//button[@data-sw-lineage-open]' );
		self::assertCount( 3, $buttons, 'Every row of the chain can open it.' );
		foreach ( $buttons as $button ) {
			self::assertSame( $panels[0]->getAttribute( 'id' ), $button->getAttribute( 'data-sw-lineage-open' ) );
			self::assertSame( 'dialog', $button->getAttribute( 'aria-haspopup' ) );
			self::assertSame( 'sw-audit-lineage-drawer', $button->getAttribute( 'aria-controls' ) );
			self::assertTrue( $button->hasAttribute( 'hidden' ), 'The button appears when the script that opens the drawer has loaded.' );
			self::assertSame( 'button', $button->getAttribute( 'type' ) );
			self::assertSame( 'Lineage', self::text( $button ) );
			self::assertStringStartsWith( 'Lineage of change set cs-', $button->getAttribute( 'aria-label' ) );
			self::assertMatchesRegularExpression( '/^Change set cs-[A-Za-z-]+$/', $button->getAttribute( 'data-sw-lineage-title' ) );
		}
		self::assertSame( [ 'cs-B-repair', 'cs-A-failed', 'cs-A-failed' ], array_map( static fn ( \DOMElement $b ): string => $b->getAttribute( 'data-sw-lineage-for' ), $buttons ) );
	}

	public function test_the_panel_is_a_nested_list_with_the_state_of_every_change_set_as_text(): void {
		$rows = self::repaired_rows();
		$this->use_wpdb( $rows, array_reverse( $rows ) );

		$xpath = self::xpath( self::render_page() );

		$tree = self::find( $xpath, '//section[@data-sw-lineage-panel]/ol[@aria-label="Change set lineage"]' );
		self::assertCount( 1, $tree, 'The list is named.' );

		$roots = self::find( $xpath, './li', $tree[0] );
		self::assertCount( 1, $roots );
		self::assertSame( 'cs-A-failed', $roots[0]->getAttribute( 'data-sw-lineage-node' ) );
		$children = self::find( $xpath, './ol/li', $roots[0] );
		self::assertCount( 1, $children, 'The repair nests under the change it repairs.' );
		self::assertSame( 'cs-B-repair', $children[0]->getAttribute( 'data-sw-lineage-node' ) );

		$failed = self::text( self::find( $xpath, './div[' . self::has_class( 'sw-audit-lineage-node__card' ) . ']', $roots[0] )[0] );
		self::assertStringContainsString( 'Verification failed, Elementor v3 batch mutate, 10:00:11', $failed, 'A node reads as a sentence: state, operation, time.' );
		self::assertStringContainsString( 'stonewright/elementor-v3-batch-mutate', $failed, 'The ability code is the one that made the change.' );

		$repair = self::text( self::find( $xpath, './div[' . self::has_class( 'sw-audit-lineage-node__card' ) . ']', $children[0] )[0] );
		self::assertStringContainsString( 'Verified, Elementor v3 batch mutate, 10:00:12', $repair );
		self::assertStringContainsString( '2.4 s', $repair );
		self::assertStringContainsString( 'Repair of cs-A-failed', $repair );

		$times = self::find( $xpath, '//section[@data-sw-lineage-panel]//time' );
		self::assertSame( '2026-10-01T10:00:11Z', $times[0]->getAttribute( 'datetime' ) );
		self::assertSame( '2026-10-01T10:00:12Z', $times[1]->getAttribute( 'datetime' ) );

		self::assertSame( [], self::find( $xpath, '//section[@data-sw-lineage-panel]//*[@aria-current]' ), 'The reader\'s own node is marked by the script, per opener.' );
	}

	public function test_every_node_can_show_the_rows_of_its_change_set(): void {
		$rows = self::repaired_rows();
		$this->use_wpdb( $rows, array_reverse( $rows ) );

		$xpath = self::xpath( self::render_page() );

		foreach ( [ 'cs-A-failed', 'cs-B-repair' ] as $id ) {
			$links = self::find( $xpath, '//li[@data-sw-lineage-node="' . $id . '"]/div//a[contains(@href, "change_set_id=' . $id . '")]' );
			self::assertCount( 1, $links, 'Tab walks from node to node through these links.' );
			self::assertSame( 'Show rows of change set ' . $id, $links[0]->getAttribute( 'aria-label' ) );
		}
	}

	public function test_the_summary_counts_the_states_and_gives_the_time_span_and_mode(): void {
		$rows = self::repaired_rows();
		$this->use_wpdb( $rows, array_reverse( $rows ) );

		$xpath   = self::xpath( self::render_page() );
		$summary = self::find( $xpath, '//section[@data-sw-lineage-panel]/p[' . self::has_class( 'sw-audit-lineage-summary' ) . ']' );

		self::assertCount( 1, $summary );
		$text = self::text( $summary[0] );
		self::assertStringContainsString( '1 failed', $text );
		self::assertStringContainsString( '1 verified', $text );
		self::assertStringContainsString( '2 change sets', $text );
		self::assertStringContainsString( '2026-10-01 10:00:10 to 10:00:12 UTC', $text );
		self::assertStringContainsString( 'development', $text );
		self::assertCount( 1, self::find( $xpath, './/span[' . self::has_class( 'sw-badge--error' ) . ']', $summary[0] ) );
		self::assertCount( 1, self::find( $xpath, './/span[' . self::has_class( 'sw-badge--ok' ) . ']', $summary[0] ) );
	}

	public function test_the_failed_node_shows_what_became_of_its_incident(): void {
		$rows     = self::repaired_rows();
		$incident = str_repeat( 'ab', 32 );
		update_option(
			IncidentStore::OPTION_KEY,
			[ $incident => [ 'incident_id' => $incident, 'state' => 'resolved', 'last_seen' => '2026-10-01 10:00:11' ] ],
			false
		);
		$this->use_wpdb( $rows, array_reverse( $rows ) );

		$xpath = self::xpath( self::render_page() );
		$card  = self::find( $xpath, '//li[@data-sw-lineage-node="cs-A-failed"]/div[' . self::has_class( 'sw-audit-lineage-node__card' ) . ']' );

		self::assertStringContainsString( 'Incident resolved', self::text( $card[0] ) );
	}

	public function test_the_node_state_never_relies_on_colour_alone(): void {
		$rows = self::repaired_rows();
		$this->use_wpdb( $rows, array_reverse( $rows ) );

		$xpath = self::xpath( self::render_page() );

		foreach ( self::find( $xpath, '//li[@data-sw-lineage-node]' ) as $node ) {
			$badge = self::find( $xpath, './div/p/span[' . self::has_class( 'sw-badge' ) . ']', $node );
			self::assertCount( 1, $badge );
			self::assertContains( self::text( $badge[0] ), [ 'Verification failed', 'Failed', 'Verified', 'Not verified' ] );
		}
	}

	public function test_a_repair_whose_parent_is_not_in_the_log_says_so(): void {
		$rows = [ self::row( 30, 'cs-B-repair', [ 'repair_of' => 'cs-A-pruned' ] ) ];
		$this->use_wpdb( $rows, $rows );

		$html = self::render_page();

		self::assertSame( 1, substr_count( $html, 'data-sw-lineage-panel' ) );
		self::assertStringContainsString( 'Repair of cs-A-pruned (not in this log)', $html );
		self::assertStringContainsString( 'data-sw-lineage-open', $html );
	}

	public function test_a_superseding_change_set_names_the_one_it_supersedes(): void {
		$rows = [ self::row( 31, 'cs-new', [ 'redacted_details' => '{"supersedes":"cs-old"}' ] ) ];
		$this->use_wpdb( $rows, $rows );

		$html = self::render_page();

		self::assertStringContainsString( 'Supersedes cs-old', $html );
	}

	public function test_a_branch_with_more_than_five_children_folds_into_a_details_element(): void {
		$rows = self::fan_out_rows( 6 );
		$this->use_wpdb( $rows, $rows );

		$xpath    = self::xpath( self::render_page() );
		$branches = self::find( $xpath, '//li[@data-sw-lineage-node="cs-A"]/details[' . self::has_class( 'sw-audit-lineage-branch' ) . ']' );

		self::assertCount( 1, $branches );
		self::assertSame( '6 repairs of cs-A', self::text( self::find( $xpath, './summary', $branches[0] )[0] ) );
		self::assertCount( 6, self::find( $xpath, './ol/li[@data-sw-lineage-node]', $branches[0] ) );
		self::assertFalse( $branches[0]->hasAttribute( 'open' ), 'A long branch starts folded.' );
	}

	public function test_a_branch_with_five_children_or_fewer_stays_open(): void {
		$rows = self::fan_out_rows( 5 );
		$this->use_wpdb( $rows, $rows );

		$xpath = self::xpath( self::render_page() );

		self::assertSame( [], self::find( $xpath, '//details[' . self::has_class( 'sw-audit-lineage-branch' ) . ']' ) );
		self::assertCount( 5, self::find( $xpath, '//li[@data-sw-lineage-node="cs-A"]/ol/li[@data-sw-lineage-node]' ) );
	}

	public function test_at_most_fifty_nodes_render_and_the_rest_wait_behind_show_more(): void {
		$rows = self::fan_out_rows( 59 );
		$this->use_wpdb( $rows, $rows );

		$xpath = self::xpath( self::render_page() );

		$shown = self::find( $xpath, '//section[@data-sw-lineage-panel]/ol[@aria-label="Change set lineage"]//li[@data-sw-lineage-node]' );
		self::assertCount( 50, $shown, 'A panel draws at most fifty nodes.' );

		$more = self::find( $xpath, '//section[@data-sw-lineage-panel]//button[@data-sw-lineage-more]' );
		self::assertCount( 1, $more );
		self::assertSame( 'Show 10 more', self::text( $more[0] ) );
		self::assertSame( 'false', $more[0]->getAttribute( 'aria-expanded' ) );

		$list = self::find( $xpath, '//ol[@id="' . $more[0]->getAttribute( 'data-sw-lineage-more' ) . '"]' );
		self::assertCount( 1, $list );
		self::assertTrue( $list[0]->hasAttribute( 'hidden' ) );
		self::assertSame( $list[0]->getAttribute( 'id' ), $more[0]->getAttribute( 'aria-controls' ) );
		$hidden = self::find( $xpath, './li[@data-sw-lineage-node]', $list[0] );
		self::assertCount( 10, $hidden );
		self::assertStringContainsString( 'Repair of cs-A', self::text( $hidden[0] ), 'A node that waits still reads on its own.' );
		self::assertSame( [], self::find( $xpath, './/ol', $hidden[0] ), 'The extra nodes are a flat list.' );
	}

	public function test_a_panel_of_exactly_fifty_nodes_has_no_show_more(): void {
		$rows = self::fan_out_rows( 49 );
		$this->use_wpdb( $rows, $rows );

		$html = self::render_page();

		self::assertStringNotContainsString( 'data-sw-lineage-more', $html );
	}

	public function test_nodes_below_the_third_level_are_marked_with_an_ellipsis_and_their_level(): void {
		$rows = [];
		for ( $i = 5; $i >= 0; --$i ) {
			$rows[] = self::row( 200 + $i, 'cs-L' . $i, [ 'repair_of' => $i > 0 ? 'cs-L' . ( $i - 1 ) : '', 'outcome' => 'FAILED', 'verification_status' => 'failed', 'result_status' => 'error' ] );
		}
		$this->use_wpdb( $rows, $rows );

		$xpath = self::xpath( self::render_page() );

		for ( $level = 0; $level <= 5; ++$level ) {
			$marker = self::find( $xpath, '//li[@data-sw-lineage-node="cs-L' . $level . '"]/div//span[' . self::has_class( 'sw-audit-lineage-node__depth' ) . ']' );
			if ( $level < 4 ) {
				self::assertSame( [], $marker, 'Levels one to four indent and need no marker.' );
				continue;
			}
			self::assertCount( 1, $marker );
			self::assertSame( '… Level ' . ( $level + 1 ), self::text( $marker[0] ) );
		}
	}

	public function test_many_chains_on_one_page_are_bounded(): void {
		$rows = [];
		for ( $chain = 0; $chain < 27; ++$chain ) {
			$rows[] = self::row( 1000 + $chain * 2 + 1, 'cs-c' . $chain . '-b', [ 'repair_of' => 'cs-c' . $chain . '-a' ] );
			$rows[] = self::row( 1000 + $chain * 2, 'cs-c' . $chain . '-a', [ 'outcome' => 'FAILED', 'verification_status' => 'failed', 'result_status' => 'error' ] );
		}
		$this->use_wpdb( $rows, $rows );

		$xpath = self::xpath( self::render_page() );

		self::assertCount( AuditLineageDrawer::MAX_PANELS, self::find( $xpath, '//section[@data-sw-lineage-panel]' ), 'A page prints a bounded number of panels.' );
		self::assertCount( AuditLineageDrawer::MAX_PANELS * 2, self::find( $xpath, '//button[@data-sw-lineage-open]' ), 'Chains beyond the limit keep the id, the copy control and the rows link.' );
		self::assertCount( 54, self::find( $xpath, '//div[' . self::has_class( 'sw-audit-lineage-cell' ) . ']' ) );
	}

	public function test_a_chain_longer_than_the_tree_limit_says_it_was_cut(): void {
		$rows = [];
		for ( $i = 119; $i >= 0; --$i ) {
			$rows[] = self::row( 300 + $i, 'cs-chain-' . $i, [ 'repair_of' => $i > 0 ? 'cs-chain-' . ( $i - 1 ) : '', 'outcome' => 'FAILED', 'verification_status' => 'failed', 'result_status' => 'error' ] );
		}
		$this->use_wpdb( array_slice( $rows, 0, 2 ), $rows );

		$html = self::render_page();

		self::assertStringContainsString( 'This chain is longer than the view', $html );
	}

	// -- Cost and safety ------------------------------------------------------------------

	public function test_change_sets_without_relatives_render_no_drawer_and_ask_for_one_lookup(): void {
		$rows = [ self::row( 40, 'cs-solo' ), self::row( 41, '' ) ];
		$wpdb = $this->use_wpdb( $rows, $rows );

		$html = self::render_page();

		self::assertStringNotContainsString( '<dialog', $html );
		$lineage_queries = array_filter( $wpdb->queries, static fn ( string $query ): bool => str_contains( $query, 'repair_of IN' ) );
		self::assertCount( 1, $lineage_queries, 'One bounded lookup for the change sets on the page.' );
	}

	public function test_a_page_without_change_sets_makes_no_lineage_lookup(): void {
		$rows = [ self::row( 50, '' ), self::row( 51, '' ) ];
		$wpdb = $this->use_wpdb( $rows, [] );

		self::render_page();

		self::assertSame( [], array_filter( $wpdb->queries, static fn ( string $query ): bool => str_contains( $query, 'repair_of IN' ) ) );
	}

	public function test_the_lineage_lookup_is_bounded_and_uses_the_indexed_columns(): void {
		$rows = self::repaired_rows();
		$wpdb = $this->use_wpdb( $rows, array_reverse( $rows ) );

		self::render_page();

		$lookup = array_values( array_filter( $wpdb->queries, static fn ( string $query ): bool => str_contains( $query, 'repair_of IN' ) ) )[0];
		self::assertStringContainsString( 'change_set_id IN (', $lookup );
		self::assertStringContainsString( 'repair_of IN (', $lookup );
		self::assertMatchesRegularExpression( '/LIMIT %d/', $lookup );
		self::assertStringContainsString( 'ORDER BY id ASC', $lookup );
	}

	public function test_identifiers_are_escaped_and_the_markup_has_no_script_surface(): void {
		$rows = [
			self::row( 61, 'cs-"><script>alert(1)</script>', [ 'repair_of' => 'cs-<img src=x onerror=alert(2)>' ] ),
		];
		$this->use_wpdb( $rows, $rows );
		$_GET = [ 'change_set_id' => 'cs-"><script>alert(3)</script>' ];

		$html = self::render_page();

		self::assertStringNotContainsString( '<script>alert', $html );
		self::assertStringNotContainsString( '<img src=x', $html );
		self::assertStringNotContainsString( 'onerror=alert', $html );
		self::assertStringContainsString( 'data-sw-lineage-open', $html, 'The hostile ids still render, escaped.' );
	}

	public function test_the_lineage_markup_has_no_inline_styles_and_no_script_elements(): void {
		$rows = self::fan_out_rows( 8 );
		$this->use_wpdb( $rows, $rows );
		$_GET = [ 'change_set_id' => 'cs-A' ];

		$html  = self::render_page();
		$start = (int) strpos( $html, 'sw-audit-lineage-chips' );
		$part  = substr( $html, $start );

		self::assertStringNotContainsString( ' style=', $part );
		self::assertStringNotContainsString( '<script', $part );
		self::assertStringNotContainsString( 'onclick=', $part );
	}

	public function test_the_assets_load_on_the_audit_log_page_only(): void {
		AuditLineageDrawer::enqueue( 'stonewright_page_stonewright-audit-log' );

		self::assertContains( 'stonewright-admin-audit-lineage', $GLOBALS['stonewright_test_enqueued_styles'] );
		self::assertContains( 'stonewright-admin-audit-lineage', $GLOBALS['stonewright_test_enqueued_scripts'] );

		$GLOBALS['stonewright_test_enqueued_styles']  = [];
		$GLOBALS['stonewright_test_enqueued_scripts'] = [];
		AuditLineageDrawer::enqueue( 'toplevel_page_stonewright' );
		self::assertSame( [], $GLOBALS['stonewright_test_enqueued_styles'] );
		self::assertSame( [], $GLOBALS['stonewright_test_enqueued_scripts'] );
	}

	public function test_the_redacted_export_carries_the_repair_link(): void {
		$row = self::row( 70, 'cs-B-repair', [ 'repair_of' => 'cs-A-failed', 'event_id' => '00000000-0000-4000-8000-000000000070' ] );

		$json = AuditLogPage::build_export( [ $row ], 'json' );
		self::assertIsString( $json );
		$decoded = json_decode( $json, true );
		self::assertSame( 'cs-A-failed', $decoded[0]['repair_of'] );
		self::assertSame( 'cs-B-repair', $decoded[0]['change_set_id'] );

		$csv = AuditLogPage::build_export( [ $row ], 'csv' );
		self::assertIsString( $csv );
		self::assertStringContainsString( 'change_set_id,repair_of,transaction_id', (string) strtok( $csv, "\n" ) );
		$empty = AuditLogPage::build_export( [], 'csv' );
		self::assertIsString( $empty );
		self::assertStringContainsString( 'repair_of', $empty );
	}
}
