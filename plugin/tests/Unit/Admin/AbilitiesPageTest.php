<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Admin\AbilitiesPage;
use Stonewright\WpMcp\Admin\AbilityHubCatalog;

/**
 * @covers \Stonewright\WpMcp\Admin\AbilitiesPage
 */
final class AbilitiesPageTest extends TestCase {

	/**
	 * POST field names that must stay stable (toggle + bulk handlers).
	 *
	 * @var list<string>
	 */
	private const FORM_FIELD_NAMES = [
		'action',
		'ability_name',
		'ability_enabled',
		'stonewright_bulk_action',
		'stonewright_bulk_category',
		'stonewright_abilities[]',
	];

	protected function setUp(): void {
		$GLOBALS['stonewright_test_user_caps'] = [ 'manage_options' => true ];
		$GLOBALS['stonewright_test_options']   = [
			'stonewright_enabled'            => true,
			'stonewright_disabled_abilities' => [ 'stonewright/ping' ],
		];
		$_GET  = [];
		$_POST = [];
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_user_caps']     = [];
		$GLOBALS['stonewright_test_options']       = [];
		$GLOBALS['stonewright_test_filters']       = [];
		$GLOBALS['stonewright_test_last_redirect'] = null;
		$_GET  = [];
		$_POST = [];
	}

	private static function html(): string {
		ob_start();
		AbilitiesPage::render();

		return (string) ob_get_clean();
	}

	/** @return array{0: \DOMDocument, 1: \DOMXPath} */
	private static function rendered_page(): array {
		$previous = libxml_use_internal_errors( true );
		$dom      = new \DOMDocument();
		$dom->loadHTML( '<?xml encoding="utf-8" ?>' . self::html() );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		return [ $dom, new \DOMXPath( $dom ) ];
	}

	/** The text a space separated list of ids points at, the way an accessible name is computed. */
	private static function name_from_ids( \DOMDocument $dom, string $ids ): string {
		$parts = [];
		foreach ( preg_split( '/\s+/', trim( $ids ) ) ?: [] as $id ) {
			$node = $dom->getElementById( $id );
			self::assertNotNull( $node, 'aria-labelledby points at a missing id: ' . $id );
			$parts[] = trim( (string) preg_replace( '/\s+/', ' ', $node->textContent ) );
		}

		return implode( ' ', $parts );
	}

	// ---------------------------------------------------------------------------------------------
	// Structure
	// ---------------------------------------------------------------------------------------------

	public function test_the_page_is_built_from_the_layer_and_groups_abilities_by_provider_and_category(): void {
		$html = self::html();

		self::assertStringContainsString( 'sw-ui-page', $html );
		self::assertStringContainsString( 'class="sw-ui-toolbar sw-ui-toolbar--sticky', $html );
		self::assertStringContainsString( 'id="stonewright-ability-search"', $html );
		self::assertStringContainsString( 'data-sw-ui-search', $html, 'The slash key reaches the search field.' );
		self::assertStringContainsString( 'name="stonewright_bulk_action"', $html );
		self::assertStringContainsString( 'sw-ui-disclosure', $html );
		self::assertStringContainsString( 'sw-ui-table--stack', $html );
		self::assertStringContainsString( 'sw-ui-switch', $html );
		self::assertStringContainsString( 'stonewright/ping', $html );
		self::assertStringContainsString( 'data-provider="stonewright"', $html );
		self::assertStringContainsString( 'data-provider="elementor"', $html );
		self::assertStringContainsString( 'Not registered', $html );
		self::assertStringNotContainsString( 'onclick=', $html );
	}

	public function test_no_older_component_or_inline_style_is_left_on_the_page(): void {
		$html = self::html();

		foreach ( [ 'stonewright-ability-row', 'stonewright-ability-table', 'stonewright-kind-badge', 'sw-switch"', 'sw-abilities-filters', 'class="sw-btn', 'class="notice', 'stonewright-schema-table', 'widefat' ] as $legacy ) {
			self::assertStringNotContainsString( $legacy, $html, $legacy );
		}
		self::assertDoesNotMatchRegularExpression( '/\sstyle=/', $html, 'No inline style attribute.' );
	}

	public function test_categories_start_closed_and_carry_their_counts_in_words(): void {
		[ , $xpath ] = self::rendered_page();

		$categories = $xpath->query( '//details[contains(@class,"sw-abilities__category")]' );
		self::assertNotFalse( $categories );
		self::assertGreaterThan( 10, $categories->length );
		foreach ( $categories as $details ) {
			\assert( $details instanceof \DOMElement );
			self::assertFalse( $details->hasAttribute( 'open' ), 'Categories start closed: the page is long.' );
		}

		self::assertMatchesRegularExpression( '/\d+ of \d+ on/', self::html() );
	}

	public function test_category_and_provider_names_are_words_not_title_cased_slugs(): void {
		$html = self::html();

		foreach ( [ '>ACF<', '>WP-CLI<', '>WooCommerce<', '>SEO<', '>Content model<' ] as $label ) {
			self::assertStringContainsString( $label, $html );
		}
		foreach ( [ '>Acf<', '>Wp Cli<', '>Woocommerce<', '>Seo<', '>Content Model<', 'Mcp Adapter' ] as $slug ) {
			self::assertStringNotContainsString( $slug, $html );
		}
	}

	public function test_render_groups_external_abilities_by_provider(): void {
		$GLOBALS['stonewright_test_filters']['stonewright_abilities_hub_external'] = static function ( array $abilities ): array {
			$abilities[] = [
				'name'          => 'yoast-seo/get-head',
				'label'         => 'Get head',
				'description'   => 'Read Yoast head data.',
				'category'      => 'seo',
				'mcp_tool_name' => 'yoast-seo-get-head',
				'input_schema'  => [],
				'enabled'       => true,
			];
			return $abilities;
		};

		$html = self::html();

		self::assertStringContainsString( 'data-provider="yoast-seo"', $html );
		self::assertStringContainsString( 'yoast-seo/get-head', $html );
		self::assertStringContainsString( 'Yoast SEO', $html );
	}

	public function test_the_toolbar_counts_what_is_shown_and_what_is_on_and_says_it_politely(): void {
		[ , $xpath ] = self::rendered_page();

		$meta = $xpath->query( '//*[@data-sw-abilities-meta]' );
		self::assertNotFalse( $meta );
		self::assertSame( 1, $meta->length );
		$node = $meta->item( 0 );
		\assert( $node instanceof \DOMElement );
		self::assertSame( 'status', $node->getAttribute( 'role' ) );
		self::assertSame( 'polite', $node->getAttribute( 'aria-live' ) );
		self::assertMatchesRegularExpression( '/\d+ abilities/', $node->textContent );
		self::assertMatchesRegularExpression( '/Enabled\s+\d+/', $node->textContent );
		self::assertMatchesRegularExpression( '/Write\s+\d+/', $node->textContent );
		self::assertMatchesRegularExpression( '/Read\s+\d+/', $node->textContent );
	}

	public function test_a_search_with_no_match_has_its_own_hidden_empty_state_that_offers_a_way_out(): void {
		[ , $xpath ] = self::rendered_page();

		$empty = $xpath->query( '//*[@data-sw-abilities-empty]' );
		self::assertNotFalse( $empty );
		self::assertSame( 1, $empty->length );
		$node = $empty->item( 0 );
		\assert( $node instanceof \DOMElement );
		self::assertTrue( $node->hasAttribute( 'hidden' ) );
		self::assertStringContainsString( 'No abilities match', $node->textContent );
		self::assertSame( 1, $xpath->query( './/button[@data-sw-abilities-clear]', $node )->length );
	}

	public function test_the_master_switch_being_off_is_a_callout_that_links_to_setup(): void {
		self::assertStringNotContainsString( 'AI abilities are switched off', self::html() );

		$GLOBALS['stonewright_test_options']['stonewright_enabled'] = false;
		$html                                                       = self::html();

		self::assertStringContainsString( 'AI abilities are switched off', $html );
		self::assertStringContainsString( 'sw-ui-callout--warn', $html );
		self::assertStringContainsString( 'page=stonewright"', $html );
	}

	public function test_a_result_notice_after_a_form_post_is_announced_and_never_removes_itself(): void {
		$_GET['stonewright_toggled'] = 'bulk-enabled';
		$_GET['stonewright_changed'] = '3';
		$html                        = self::html();

		self::assertStringContainsString( 'sw-ui-notice--ok', $html );
		self::assertStringContainsString( 'role="status"', $html );
		self::assertStringContainsString( '3 abilities turned on.', $html );
		self::assertStringNotContainsString( 'is-dismissible', $html );
	}

	public function test_apply_with_nothing_chosen_is_not_silent(): void {
		foreach ( [
			'bulk-no-action'    => 'Choose a bulk action, then press Apply.',
			'bulk-no-selection' => 'Select at least one ability, then press Apply.',
			'bulk-no-category'  => 'Choose a category for that action, then press Apply.',
		] as $code => $sentence ) {
			$_GET['stonewright_toggled'] = $code;
			$html                        = self::html();

			self::assertStringContainsString( $sentence, $html, $code );
			self::assertStringContainsString( 'sw-ui-notice--warn', $html, $code );
			self::assertStringContainsString( 'role="alert"', $html, $code );
		}
	}

	public function test_an_unknown_result_code_prints_no_notice(): void {
		$_GET['stonewright_toggled'] = 'something-else';

		self::assertStringNotContainsString( 'sw-ui-notice--', self::html() );
	}

	public function test_the_page_hands_its_script_the_rest_base_and_the_nonces_it_needs(): void {
		[ , $xpath ] = self::rendered_page();

		$root = $xpath->query( '//*[@data-sw-abilities]' )->item( 0 );
		self::assertInstanceOf( \DOMElement::class, $root );
		self::assertStringContainsString( '/stonewright/v1/admin/abilities', $root->getAttribute( 'data-rest-url' ) );
		self::assertSame( wp_create_nonce( 'wp_rest' ), $root->getAttribute( 'data-rest-nonce' ) );
		self::assertSame( wp_create_nonce( 'stonewright_toggle_ability' ), $root->getAttribute( 'data-toggle-nonce' ) );
		self::assertSame( wp_create_nonce( 'stonewright_bulk_abilities' ), $root->getAttribute( 'data-bulk-nonce' ) );
		$strings = json_decode( $root->getAttribute( 'data-strings' ), true );
		self::assertIsArray( $strings );
		self::assertArrayHasKey( 'undo', $strings );
	}

	public function test_the_parameters_are_not_in_the_page_they_load_when_a_row_asks_for_them(): void {
		[ , $xpath ] = self::rendered_page();

		self::assertSame( 0, $xpath->query( '//table[contains(@class,"widefat")]' )->length );
		$details = $xpath->query( '//details[@data-sw-ability-params]' );
		self::assertNotFalse( $details );
		self::assertGreaterThan( 100, $details->length, 'Every ability has a parameter list.' );
		self::assertSame( 0, $xpath->query( '//details[@data-sw-ability-params]//table' )->length );
	}

	// ---------------------------------------------------------------------------------------------
	// Names, ids and forms
	// ---------------------------------------------------------------------------------------------

	public function test_each_ability_switch_is_named_after_its_ability(): void {
		[ $dom, $xpath ] = self::rendered_page();

		$switches = $xpath->query( '//input[@role="switch"]' );
		self::assertNotFalse( $switches );
		self::assertGreaterThan( 10, $switches->length, 'The hub lists many abilities.' );

		$names = [];
		foreach ( $switches as $switch ) {
			self::assertInstanceOf( \DOMElement::class, $switch );
			$main = $xpath->query( 'ancestor::tr[1]//*[@data-sw-ability]', $switch )->item( 0 );
			self::assertInstanceOf( \DOMElement::class, $main );

			$name = self::name_from_ids( $dom, $switch->getAttribute( 'aria-labelledby' ) );
			self::assertStringStartsWith( $main->getAttribute( 'data-ability-label' ), $name, 'The switch is named by the ability label of its row.' );
			self::assertSame( $main->getAttribute( 'data-sw-ability' ), $switch->getAttribute( 'data-sw-ability-switch' ), 'The switch knows which ability it changes.' );
			$names[ $main->getAttribute( 'data-sw-ability' ) ] = $name;
		}

		self::assertSame( count( $names ), count( array_unique( $names ) ), 'No two switches may share a name, even when two abilities share a label.' );
	}

	public function test_each_row_checkbox_says_what_it_selects(): void {
		[ $dom, $xpath ] = self::rendered_page();

		$boxes = $xpath->query( '//input[@name="stonewright_abilities[]"]' );
		self::assertNotFalse( $boxes );
		self::assertGreaterThan( 10, $boxes->length );

		$names = [];
		foreach ( $boxes as $box ) {
			self::assertInstanceOf( \DOMElement::class, $box );
			$name = self::name_from_ids( $dom, $box->getAttribute( 'aria-labelledby' ) );
			self::assertStringStartsWith( 'Select ', $name );
			self::assertNotSame( 'Select', trim( $name ) );
			$names[ $box->getAttribute( 'value' ) ] = $name;
		}

		self::assertSame( count( $names ), count( array_unique( $names ) ), 'No two row checkboxes may share a name.' );
	}

	public function test_each_parameters_toggle_names_the_ability_it_opens(): void {
		[ $dom, $xpath ] = self::rendered_page();

		$summaries = $xpath->query( '//details[@data-sw-ability-params]/summary' );
		self::assertNotFalse( $summaries );
		self::assertGreaterThan( 10, $summaries->length );

		$names = [];
		foreach ( $summaries as $summary ) {
			self::assertInstanceOf( \DOMElement::class, $summary );
			$name = self::name_from_ids( $dom, $summary->getAttribute( 'aria-labelledby' ) );
			// The visible word stays at the start of the name (WCAG 2.5.3).
			self::assertStringStartsWith( 'Parameters ', $name );
			$names[] = $name;
		}

		self::assertSame( count( $names ), count( array_unique( $names ) ), 'No two parameter toggles may share a name.' );
	}

	public function test_no_id_appears_twice_on_the_page(): void {
		[ , $xpath ] = self::rendered_page();

		$ids = [];
		foreach ( $xpath->query( '//*[@id]' ) ?: [] as $node ) {
			\assert( $node instanceof \DOMElement );
			$ids[] = $node->getAttribute( 'id' );
		}

		self::assertNotEmpty( $ids );
		self::assertSame( [], array_keys( array_filter( array_count_values( $ids ), static fn ( int $count ): bool => $count > 1 ) ), 'Duplicate ids point every name at the first element.' );
	}

	public function test_there_is_one_form_for_bulk_actions_and_one_hidden_form_for_the_switch_fallback(): void {
		[ , $xpath ] = self::rendered_page();

		$forms = $xpath->query( '//form[@action and contains(@action,"admin-post.php")]' );
		self::assertNotFalse( $forms );
		self::assertSame( 2, $forms->length, 'The page used to print one form per ability.' );
		self::assertSame( 2, $xpath->query( '//input[@name="_wpnonce"]' )->length );
		self::assertSame( 0, $xpath->query( '//*[@id="_wpnonce"]' )->length );
		self::assertSame( 1, $xpath->query( '//form[@id="stonewright-bulk-form"]' )->length );
		self::assertSame( 1, $xpath->query( '//form[@data-sw-abilities-toggle-form][@hidden]' )->length );
	}

	public function test_the_bulk_selects_are_labelled(): void {
		[ , $xpath ] = self::rendered_page();

		foreach ( [ 'stonewright_bulk_action', 'stonewright_bulk_category' ] as $field ) {
			$selects = $xpath->query( '//select[@name="' . $field . '"]' );
			self::assertNotFalse( $selects );
			self::assertSame( 1, $selects->length, $field );
			$select = $selects->item( 0 );
			self::assertInstanceOf( \DOMElement::class, $select );

			$id = $select->getAttribute( 'id' );
			self::assertNotSame( '', $id, $field . ' needs an id for its label.' );
			$labels = $xpath->query( '//label[@for="' . $id . '"]' );
			self::assertNotFalse( $labels );
			self::assertSame( 1, $labels->length, $field . ' needs one label.' );
			self::assertNotSame( '', trim( (string) $labels->item( 0 )?->textContent ), $field );
		}
	}

	public function test_apply_is_a_secondary_button_and_the_page_has_no_primary_action(): void {
		$html = self::html();

		self::assertStringContainsString( '>Apply<', $html );
		self::assertStringNotContainsString( 'sw-ui-btn--primary', $html );
	}

	public function test_form_field_name_snapshot_is_stable(): void {
		$html = self::html();

		foreach ( self::FORM_FIELD_NAMES as $name ) {
			self::assertStringContainsString(
				'name="' . $name . '"',
				$html,
				"Expected form field name=\"{$name}\" to remain present."
			);
		}
		self::assertStringContainsString( 'value="stonewright_toggle_ability"', $html );
		self::assertStringContainsString( 'value="stonewright_bulk_abilities"', $html );
	}

	// ---------------------------------------------------------------------------------------------
	// The form handlers keep their gates
	// ---------------------------------------------------------------------------------------------

	public function test_both_handlers_still_refuse_a_user_without_manage_options(): void {
		$GLOBALS['stonewright_test_user_caps'] = [];
		$names                                 = array_slice( AbilityHubCatalog::names(), 0, 1 );
		$_POST                                 = [ 'ability_name' => $names[0], 'stonewright_bulk_action' => 'disable_selected', 'stonewright_abilities' => $names ];

		foreach ( [ 'handle_toggle', 'handle_bulk' ] as $handler ) {
			try {
				AbilitiesPage::$handler();
				self::fail( $handler . ' ran for a user who cannot manage options.' );
			} catch ( \RuntimeException $refused ) {
				self::assertStringContainsString( 'Insufficient permissions', $refused->getMessage() );
			}
		}
		self::assertSame( [ 'stonewright/ping' ], $GLOBALS['stonewright_test_options']['stonewright_disabled_abilities'] );
	}

	public function test_a_toggle_post_changes_the_option_and_goes_back_to_the_page(): void {
		$name = (string) AbilityHubCatalog::collect()[0]['name'];

		$url = AbilitiesPage::apply_toggle_request( [ 'ability_name' => $name ] );

		self::assertContains( $name, $GLOBALS['stonewright_test_options']['stonewright_disabled_abilities'], 'No ability_enabled field means off, as before.' );
		self::assertStringContainsString( 'page=stonewright-abilities', $url );
		self::assertStringContainsString( 'stonewright_toggled=disabled', $url );

		$url = AbilitiesPage::apply_toggle_request( [ 'ability_name' => $name, 'ability_enabled' => '1' ] );

		self::assertNotContains( $name, $GLOBALS['stonewright_test_options']['stonewright_disabled_abilities'] );
		self::assertStringContainsString( 'stonewright_toggled=enabled', $url );
	}

	public function test_a_toggle_post_without_a_name_changes_nothing(): void {
		$url = AbilitiesPage::apply_toggle_request( [ 'ability_name' => '' ] );

		self::assertSame( [ 'stonewright/ping' ], $GLOBALS['stonewright_test_options']['stonewright_disabled_abilities'] );
		self::assertStringContainsString( 'page=stonewright-abilities', $url );
	}

	public function test_a_bulk_post_applies_the_action_and_reports_how_many_changed(): void {
		$names = array_slice( AbilityHubCatalog::names(), 1, 3 );

		$url = AbilitiesPage::apply_bulk_request( [ 'stonewright_bulk_action' => 'disable_selected', 'stonewright_abilities' => $names ] );

		self::assertStringContainsString( 'stonewright_toggled=bulk-disabled', $url );
		self::assertStringContainsString( 'stonewright_changed=3', $url );
		foreach ( $names as $name ) {
			self::assertContains( $name, $GLOBALS['stonewright_test_options']['stonewright_disabled_abilities'] );
		}
	}

	public function test_a_bulk_post_with_nothing_chosen_says_what_is_missing_instead_of_pretending(): void {
		$before = $GLOBALS['stonewright_test_options']['stonewright_disabled_abilities'];

		self::assertStringContainsString( 'stonewright_toggled=bulk-no-action', AbilitiesPage::apply_bulk_request( [] ) );
		self::assertStringContainsString( 'stonewright_toggled=bulk-no-selection', AbilitiesPage::apply_bulk_request( [ 'stonewright_bulk_action' => 'enable_selected' ] ) );
		self::assertStringContainsString( 'stonewright_toggled=bulk-no-category', AbilitiesPage::apply_bulk_request( [ 'stonewright_bulk_action' => 'disable_category' ] ) );
		self::assertSame( $before, $GLOBALS['stonewright_test_options']['stonewright_disabled_abilities'] );
	}
}
