<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Admin\AbilitiesPage;

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
		$_GET = [];
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_user_caps'] = [];
		$GLOBALS['stonewright_test_options']   = [];
		$GLOBALS['stonewright_test_filters']   = [];
		$_GET = [];
	}

	public function test_render_outputs_compact_grouped_abilities_hub(): void {
		ob_start();
		AbilitiesPage::render();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'sw-abilities-page', $html );
		self::assertStringContainsString( 'sw-abilities-filters', $html );
		self::assertStringContainsString( 'id="stonewright-ability-search"', $html );
		self::assertStringContainsString( 'name="stonewright_bulk_action"', $html );
		self::assertStringContainsString( 'sw-ability-category', $html );
		self::assertStringContainsString( 'stonewright-ability-row', $html );
		self::assertStringContainsString( 'stonewright-kind-badge', $html );
		self::assertStringContainsString( '<details', $html );
		self::assertStringContainsString( 'stonewright-schema-table-wrap', $html );
		self::assertStringContainsString( 'stonewright/ping', $html );
		self::assertStringNotContainsString( 'onclick=', $html );
		self::assertStringContainsString( 'data-provider="stonewright"', $html );
		self::assertStringContainsString( 'data-provider="elementor"', $html );
		self::assertStringContainsString( 'Not registered', $html );
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

		ob_start();
		AbilitiesPage::render();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'data-provider="yoast-seo"', $html );
		self::assertStringContainsString( 'yoast-seo/get-head', $html );
		self::assertStringContainsString( 'Yoast Seo', $html );
	}

	public function test_render_includes_sticky_filters_stats_and_switches(): void {
		ob_start();
		AbilitiesPage::render();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'sw-abilities-filters', $html );
		self::assertStringContainsString( 'sw-abilities-stats', $html );
		self::assertStringContainsString( 'sw-switch', $html );
		self::assertStringContainsString( 'name="ability_enabled"', $html );
		self::assertStringContainsString( 'sw-ability-category', $html );
		self::assertStringContainsString( 'sw-abilities-empty', $html );
		self::assertMatchesRegularExpression( '/Enabled\s+\d+/', $html );
		self::assertMatchesRegularExpression( '/Write\s+\d+/', $html );
		self::assertMatchesRegularExpression( '/Read\s+\d+/', $html );
	}

	/** @return array{0: \DOMDocument, 1: \DOMXPath} */
	private static function rendered_page(): array {
		ob_start();
		AbilitiesPage::render();
		$html = (string) ob_get_clean();

		$previous = libxml_use_internal_errors( true );
		$dom      = new \DOMDocument();
		$dom->loadHTML( '<?xml encoding="utf-8" ?>' . $html );
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

	public function test_each_ability_switch_is_named_after_its_ability(): void {
		[ $dom, $xpath ] = self::rendered_page();

		$switches = $xpath->query( '//input[@name="ability_enabled"]' );
		self::assertNotFalse( $switches );
		self::assertGreaterThan( 10, $switches->length, 'The hub lists many abilities.' );

		$names = [];
		foreach ( $switches as $switch ) {
			self::assertInstanceOf( \DOMElement::class, $switch );
			$row = $switch->parentNode;
			while ( $row instanceof \DOMElement && ! str_contains( $row->getAttribute( 'class' ), 'stonewright-ability-row' ) ) {
				$row = $row->parentNode;
			}
			self::assertInstanceOf( \DOMElement::class, $row );

			$name = self::name_from_ids( $dom, $switch->getAttribute( 'aria-labelledby' ) );
			self::assertStringStartsWith( $row->getAttribute( 'data-label' ), $name, 'The switch is named by the ability label of its row.' );
			self::assertSame( 'switch', $switch->getAttribute( 'role' ), 'An on/off control that applies at once is a switch.' );
			$names[ $row->getAttribute( 'data-name' ) ] = $name;
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

	public function test_each_details_toggle_names_the_ability_it_opens(): void {
		[ $dom, $xpath ] = self::rendered_page();

		$summaries = $xpath->query( '//details[contains(@class,"stonewright-ability-details")]/summary' );
		self::assertNotFalse( $summaries );
		self::assertGreaterThan( 10, $summaries->length );

		$names = [];
		foreach ( $summaries as $summary ) {
			self::assertInstanceOf( \DOMElement::class, $summary );
			$name = self::name_from_ids( $dom, $summary->getAttribute( 'aria-labelledby' ) );
			// The visible word stays at the start of the name (WCAG 2.5.3).
			self::assertStringStartsWith( 'Details ', $name );
			$names[] = $name;
		}

		self::assertSame( count( $names ), count( array_unique( $names ) ), 'No two Details toggles may share a name.' );
	}

	public function test_the_label_ids_the_names_point_at_are_unique_per_ability(): void {
		[ , $xpath ] = self::rendered_page();

		$ids = [];
		foreach ( $xpath->query( '//*[@id]' ) ?: [] as $node ) {
			\assert( $node instanceof \DOMElement );
			$ids[] = $node->getAttribute( 'id' );
		}
		$label_ids = array_filter( $ids, static fn ( string $id ): bool => str_ends_with( $id, '-label' ) );

		self::assertNotEmpty( $label_ids );
		self::assertSame( count( $label_ids ), count( array_unique( $label_ids ) ), 'Duplicate ids would point every name at the first ability.' );
	}

	public function test_the_bulk_selects_are_labelled(): void {
		[ $dom, $xpath ] = self::rendered_page();

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

	public function test_form_field_name_snapshot_is_stable(): void {
		ob_start();
		AbilitiesPage::render();
		$html = (string) ob_get_clean();

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
}
