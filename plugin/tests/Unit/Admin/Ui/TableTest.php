<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Admin\Ui;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Admin\Ui\Button;
use Stonewright\WpMcp\Admin\Ui\Html;
use Stonewright\WpMcp\Admin\Ui\Table;

/**
 * @covers \Stonewright\WpMcp\Admin\Ui\Table
 */
final class TableTest extends TestCase {

	/** @var list<array{key: string, label: string, primary?: bool, secondary?: bool, numeric?: bool, actions?: bool}> */
	private const COLUMNS = [
		[ 'key' => 'name', 'label' => 'Client', 'primary' => true ],
		[ 'key' => 'by', 'label' => 'Approved by', 'secondary' => true ],
		[ 'key' => 'calls', 'label' => 'Calls', 'numeric' => true ],
		[ 'key' => 'action', 'label' => 'Action', 'actions' => true ],
	];

	protected function setUp(): void {
		Html::reset_ids();
	}

	public function test_a_table_has_a_caption_column_headers_and_labelled_cells(): void {
		$html = Table::render(
			self::COLUMNS,
			[ [ 'name' => [ 'text' => 'Example client', 'meta' => 'Registered automatically' ], 'by' => 'admin', 'calls' => '12', 'action' => [ 'html' => Button::render( 'Disconnect Example client', [ 'size' => 'sm', 'variant' => 'danger' ] ) ] ] ],
			[ 'caption' => 'Connected clients' ]
		);

		self::assertSame(
			'<table class="sw-ui-table sw-ui-table--stack"><caption class="sw-ui-visually-hidden">Connected clients</caption>'
			. '<thead><tr><th scope="col">Client</th><th scope="col" class="sw-ui-col--secondary">Approved by</th><th scope="col" class="sw-ui-table__num">Calls</th><th scope="col" class="sw-ui-table__actions"><span class="sw-ui-visually-hidden">Action</span></th></tr></thead>'
			. '<tbody><tr>'
			. '<td class="sw-ui-table__primary-cell"><span class="sw-ui-table__primary">Example client</span><span class="sw-ui-table__meta">Registered automatically</span></td>'
			. '<td class="sw-ui-col--secondary" data-label="Approved by">admin</td>'
			. '<td class="sw-ui-table__num" data-label="Calls">12</td>'
			. '<td class="sw-ui-table__actions"><button type="button" class="sw-ui-btn sw-ui-btn--danger sw-ui-btn--sm">Disconnect Example client</button></td>'
			. '</tr></tbody></table>',
			$html
		);
	}

	public function test_the_primary_cell_and_the_actions_cell_have_no_data_label_because_they_head_the_stacked_card(): void {
		$html = Table::render( self::COLUMNS, [ [ 'name' => 'A', 'by' => 'b', 'calls' => '1', 'action' => 'x' ] ], [ 'caption' => 'c' ] );

		self::assertSame( 2, substr_count( $html, 'data-label=' ), 'Only the two ordinary cells carry a label.' );
	}

	public function test_stacking_can_be_turned_off_and_density_changed(): void {
		self::assertStringStartsWith( '<table class="sw-ui-table sw-ui-table--comfortable">', Table::render( self::COLUMNS, [], [ 'caption' => 'c', 'stack' => false, 'comfortable' => true ] ) );
	}

	public function test_an_empty_table_says_so_in_a_full_width_row(): void {
		$html = Table::render( self::COLUMNS, [], [ 'caption' => 'Clients', 'empty' => 'No clients connected.' ] );

		self::assertStringContainsString( '<tbody><tr><td colspan="4">No clients connected.</td></tr></tbody>', $html );
	}

	public function test_a_missing_cell_renders_empty_rather_than_breaking_the_row(): void {
		$html = Table::render( self::COLUMNS, [ [ 'name' => 'A' ] ], [ 'caption' => 'c' ] );

		self::assertSame( 4, substr_count( $html, '<td' ) );
	}

	public function test_text_and_labels_are_escaped_and_html_is_taken_as_given(): void {
		$html = Table::render(
			[ [ 'key' => 'name', 'label' => '<i>Name</i>', 'primary' => true ], [ 'key' => 'x', 'label' => 'X' ] ],
			[ [ 'name' => '<script>alert(1)</script>', 'x' => [ 'html' => '<strong>ok</strong>' ] ] ],
			[ 'caption' => '<b>cap</b>' ]
		);

		self::assertStringNotContainsString( '<script', $html );
		self::assertStringNotContainsString( '<i>', $html );
		self::assertStringNotContainsString( '<b>', $html );
		self::assertStringContainsString( '<strong>ok</strong>', $html );
	}

	public function test_an_id_and_extra_classes_can_be_given(): void {
		$html = Table::render( self::COLUMNS, [], [ 'caption' => 'c', 'id' => 'clients', 'class' => 'extra' ] );

		self::assertStringStartsWith( '<table class="sw-ui-table sw-ui-table--stack extra" id="clients">', $html );
	}
}
