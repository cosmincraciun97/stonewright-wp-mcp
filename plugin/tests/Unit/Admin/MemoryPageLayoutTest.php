<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Admin\MemoryInstructionsPage;
use Stonewright\WpMcp\Security\IncidentStore;

/**
 * The Memory page is built from the admin UI layer: the entries come first, a table stacks on narrow screens, every
 * control has a name, and no id repeats.
 *
 * @covers \Stonewright\WpMcp\Admin\MemoryInstructionsPage
 */
final class MemoryPageLayoutTest extends TestCase {

	private mixed $original_wpdb;

	private MemoryTableDouble $table;

	protected function setUp(): void {
		$this->original_wpdb                         = $GLOBALS['wpdb'] ?? null;
		$this->table                                 = new MemoryTableDouble();
		$GLOBALS['wpdb']                             = $this->table;
		$GLOBALS['stonewright_test_user_caps']       = [ 'manage_options' => true ];
		$GLOBALS['stonewright_test_current_user_id'] = 7;
		$GLOBALS['stonewright_test_options']         = [ 'stonewright_custom_instructions' => 'Prefer native widgets.' ];
		$_GET                                        = [];
		$_POST                                       = [];
		IncidentStore::reset_for_tests();
		$this->table->seed( [ 'id' => 9, 'type' => 'feedback', 'scope' => 'site-a', 'memory_key' => 'no-html-widgets', 'name' => 'No HTML widgets', 'value_json' => '"Use native widgets first."' ] );
		$this->table->seed( [ 'id' => 12, 'type' => 'reference', 'scope' => 'audit', 'memory_key' => 'draft-lesson-abc', 'name' => 'Draft lesson', 'status' => 'draft', 'value_json' => '{"proposed_remediation":"Validate."}' ] );
	}

	protected function tearDown(): void {
		if ( null !== $this->original_wpdb ) {
			$GLOBALS['wpdb'] = $this->original_wpdb;
		} else {
			unset( $GLOBALS['wpdb'] );
		}
		$GLOBALS['stonewright_test_user_caps']       = [];
		$GLOBALS['stonewright_test_current_user_id'] = 0;
		$GLOBALS['stonewright_test_options']         = [];
		$_GET                                        = [];
		$_POST                                       = [];
		IncidentStore::reset_for_tests();
	}

	public function test_the_page_is_built_from_the_layer_and_keeps_one_heading_one(): void {
		$html = $this->render();

		self::assertStringContainsString( 'sw-ui sw-ui-page sw-memory', $html );
		self::assertStringNotContainsString( 'class="sw-card', $html );
		self::assertStringNotContainsString( 'stonewright-panel', $html );
		self::assertStringNotContainsString( 'stonewright-type-badge', $html );
		self::assertStringNotContainsString( 'notice notice-', $html );
		self::assertStringNotContainsString( ' style=', $html );
		self::assertStringNotContainsString( 'subsubsub', $html );
		self::assertSame( 1, substr_count( $html, '<h1' ) );
	}

	public function test_the_entries_come_before_the_settings_forms(): void {
		$html = $this->render();

		$entries      = strpos( $html, 'Memory entries' );
		$instructions = strpos( $html, 'Custom instructions' );
		$bundle       = strpos( $html, 'Import and export' );
		self::assertNotFalse( $entries );
		self::assertNotFalse( $instructions );
		self::assertNotFalse( $bundle );
		self::assertLessThan( $instructions, $entries );
		self::assertLessThan( $bundle, $instructions );
	}

	public function test_the_table_stacks_names_every_cell_and_shows_types_as_tags(): void {
		$html = $this->render();

		self::assertStringContainsString( 'sw-ui-table sw-ui-table--stack', $html );
		self::assertStringContainsString( '<caption class="sw-ui-visually-hidden">Memory entries</caption>', $html );
		self::assertStringContainsString( 'data-label="Scope"', $html );
		self::assertStringContainsString( 'data-label="Type"', $html );
		self::assertMatchesRegularExpression( '/<span class="sw-ui-tag">feedback<\/span>/', $html );
		self::assertStringContainsString( '<code>no-html-widgets</code>', $html );
	}

	public function test_times_are_time_elements_in_site_time_with_the_utc_instant_in_the_title(): void {
		$html = $this->render();

		self::assertMatchesRegularExpression( '/<time datetime="2026-10-07T06:00:00Z" title="2026-10-07 06:00:00 UTC">/', $html );
		self::assertStringNotContainsString( 'Updated (UTC)', $html );
	}

	public function test_the_lifecycle_filters_are_links_with_sentence_case_names_counts_and_a_current_marker(): void {
		$html = $this->render();

		self::assertMatchesRegularExpression( '/<a [^>]*aria-current="location"[^>]*>All\s*<span class="sw-ui-count sw-ui-num">2<\/span>/', $html );
		self::assertStringContainsString( 'Verified repairs', $html );
		self::assertStringContainsString( 'Unresolved incidents', $html );
		self::assertStringContainsString( 'Incident lifecycle', $html );
		self::assertStringContainsString( 'Audit feedback', $html );
		self::assertStringNotContainsString( 'Verified Repairs', $html );
	}

	public function test_every_row_action_is_named_after_its_entry(): void {
		$html = $this->render();

		self::assertMatchesRegularExpression( '/<a [^>]*href="[^"]*edit=9[^"]*"[^>]*>Edit<span class="sw-ui-visually-hidden"> No HTML widgets<\/span><\/a>/', $html );
		self::assertMatchesRegularExpression( '/<button [^>]*>Approve<span class="sw-ui-visually-hidden"> Draft lesson<\/span><\/button>/', $html );
		self::assertMatchesRegularExpression( '/<button [^>]*>Discard<span class="sw-ui-visually-hidden"> Draft lesson<\/span><\/button>/', $html );
		self::assertStringContainsString( 'value="stonewright_memory_approve_draft"', $html );
		self::assertStringContainsString( 'name="_stonewright_nonce"', $html );
		self::assertStringContainsString( 'name="id" value="12"', $html );
	}

	public function test_the_edit_view_shows_the_form_the_facts_and_a_delete_that_needs_a_second_step(): void {
		$_GET['edit'] = '9';

		$html = $this->render();

		self::assertStringContainsString( 'value="stonewright_memory_update"', $html );
		self::assertStringContainsString( 'Use native widgets first.', $html );
		self::assertStringContainsString( 'plugin-site', $html );
		self::assertStringContainsString( 'Last retrieved', $html );
		self::assertStringContainsString( 'Activation', $html );
		self::assertStringContainsString( 'Lifecycle', $html );
		self::assertMatchesRegularExpression( '/<details class="sw-ui-disclosure"[^>]*>\s*<summary>.*?Delete this entry.*?<\/summary>.*value="stonewright_memory_delete".*sw-ui-btn--danger[^>]*>Delete entry/s', $html );
		self::assertStringNotContainsString( 'data-confirm', $html );
		self::assertStringNotContainsString( 'window.confirm', $html );
		self::assertDoesNotMatchRegularExpression( '/sw-ui-btn--primary[^>]*>Delete/', $html, 'A destructive action is never primary.' );
	}

	public function test_the_edit_view_for_an_entry_that_does_not_exist_says_so_and_still_lists_the_entries(): void {
		$_GET['edit'] = '4040';

		$html = $this->render();

		self::assertStringContainsString( 'That memory entry no longer exists.', $html );
		self::assertStringContainsString( 'No HTML widgets', $html );
	}

	public function test_the_add_form_is_a_native_disclosure_that_opens_when_asked_for(): void {
		$closed = $this->render();
		self::assertMatchesRegularExpression( '/<details class="sw-ui-disclosure" id="sw-memory-add">/', $closed );
		self::assertStringContainsString( 'value="stonewright_memory_create"', $closed );
		self::assertMatchesRegularExpression( '/<a [^>]*class="sw-ui-btn sw-ui-btn--primary"[^>]*href="[^"]*add=1[^"]*#sw-memory-add"[^>]*>(?:<svg.*?<\/svg>)?Add entry<\/a>/', $closed, 'Adding is the one primary action of the page header.' );

		$_GET['add'] = '1';
		self::assertMatchesRegularExpression( '/<details class="sw-ui-disclosure" id="sw-memory-add" open>/', $this->render() );
	}

	public function test_the_add_form_labels_every_field_and_states_that_a_key_is_unique(): void {
		$html = $this->render();

		foreach ( [ 'Name', 'Scope', 'Key', 'Type', 'Value' ] as $label ) {
			self::assertMatchesRegularExpression( '/<label class="sw-ui-field__label" for="[^"]+">' . $label . '(?: \(JSON or text\))?<\/label>/', $html, $label );
		}
		self::assertStringContainsString( 'A scope and key pair is unique', $html );
	}

	public function test_a_settings_form_posts_every_option_of_its_group_so_saving_one_never_clears_another(): void {
		$html = $this->render();

		self::assertSame( 1, preg_match_all( '/<form[^>]*action="options\.php"[^>]*>(.*?)<\/form>/s', $html, $forms ), 'One settings form.' );
		foreach ( [ 'stonewright_custom_instructions', 'stonewright_custom_instructions_enabled', 'stonewright_memory_enabled' ] as $option ) {
			self::assertStringContainsString( 'name="' . $option . '"', $forms[1][0], $option );
		}
	}

	public function test_the_instructions_field_is_labelled_limited_and_described(): void {
		$html = $this->render();

		self::assertMatchesRegularExpression( '/<label class="sw-ui-field__label" for="stonewright_custom_instructions">Custom instructions<\/label>/', $html );
		self::assertMatchesRegularExpression( '/<textarea[^>]*id="stonewright_custom_instructions"[^>]*maxlength="4000"[^>]*aria-describedby="stonewright_custom_instructions-help"/', $html );
		self::assertStringContainsString( 'Up to 4000 characters.', $html );
	}

	public function test_no_id_is_used_twice_and_nonce_fields_carry_none(): void {
		$_GET['edit'] = '9';
		$html         = $this->render();

		preg_match_all( '/\bid="([^"]+)"/', $html, $found );
		$repeated = array_keys( array_filter( array_count_values( $found[1] ), static fn ( int $count ): bool => $count > 1 ) );
		self::assertSame( [], $repeated );
		self::assertStringNotContainsString( 'id="_wpnonce"', $html );
		self::assertStringNotContainsString( 'id="submit"', $html );
	}

	public function test_an_empty_store_teaches_what_memory_is_and_how_to_add_to_it(): void {
		$this->table->rows = [];

		$html = $this->render();

		self::assertStringContainsString( 'No memory entries yet', $html );
		self::assertStringContainsString( 'Add entry', $html );
		self::assertStringNotContainsString( '<table class="sw-ui-table sw-ui-table--stack"><caption class="sw-ui-visually-hidden">Memory entries', $html );
	}

	public function test_a_filter_with_nothing_in_it_says_so_and_offers_the_way_back(): void {
		$_GET['type'] = 'project';

		$html = $this->render();

		self::assertStringContainsString( 'No entries in this view', $html );
		self::assertStringContainsString( 'Show all entries', $html );
	}

	public function test_the_receipt_lookup_and_the_migration_are_labelled_and_keep_their_checks(): void {
		$html = $this->render();

		self::assertMatchesRegularExpression( '/<label class="sw-ui-field__label" for="[^"]+">Plugin memory ID<\/label>/', $html );
		self::assertStringContainsString( 'value="stonewright_memory_migrate_feedback"', $html );
		self::assertMatchesRegularExpression( '/<input[^>]*name="export_confirmed"[^>]*required/', $html );
		self::assertStringContainsString( 'I exported the current JSON bundle.', $html );
	}

	public function test_a_looked_up_receipt_is_announced(): void {
		$_GET['receipt_id'] = '9';
		self::assertMatchesRegularExpression( '/role="status"[^>]*>.*Receipt verified.*wp:stonewright_memory#9/s', $this->render() );

		$_GET['receipt_id'] = '9999';
		self::assertMatchesRegularExpression( '/role="alert"[^>]*>.*No plugin-site memory receipt exists/s', $this->render() );
	}

	public function test_a_long_key_and_name_are_escaped_and_carry_no_inline_style(): void {
		$this->table->seed( [ 'id' => 30, 'memory_key' => str_repeat( 'k', 150 ), 'name' => '<b>' . str_repeat( 'n', 150 ) . '</b>' ] );

		$html = $this->render();

		self::assertStringContainsString( str_repeat( 'k', 150 ), $html );
		self::assertStringNotContainsString( '<b>nnn', $html );
		self::assertStringNotContainsString( ' style=', $html );
	}

	private function render(): string {
		ob_start();
		MemoryInstructionsPage::render();

		return (string) ob_get_clean();
	}
}
