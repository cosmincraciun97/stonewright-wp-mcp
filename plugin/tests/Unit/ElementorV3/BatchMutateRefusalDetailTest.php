<?php
// SPDX-License-Identifier: GPL-2.0-or-later
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\ElementorV3;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\ElementorV3\BatchMutate;
use Stonewright\WpMcp\Elementor\Schema\ContainerSchemaRepository;
use Stonewright\WpMcp\Elementor\Schema\WidgetSchemaRepository;
use Stonewright\WpMcp\Security\IncidentStore;
use Stonewright\WpMcp\Tests\Unit\Elementor\Schema\Fixtures\ElementorControlFixtures;

/**
 * A refused batch says, for every rejected setting: which operation, which element and widget type, which setting,
 * what was received, what the live control accepts and an example, in the error data and in the audit row.
 *
 * @covers \Stonewright\WpMcp\Abilities\ElementorV3\BatchMutate
 */
final class BatchMutateRefusalDetailTest extends TestCase {

	private object $original_elementor;

	protected function setUp(): void {
		$this->original_elementor = ElementorControlFixtures::install();
		WidgetSchemaRepository::reset_request_cache();
		$GLOBALS['stonewright_test_posts'] = [
			601 => (object) [
				'ID'           => 601,
				'post_type'    => 'page',
				'post_status'  => 'draft',
				'post_title'   => 'Refusal detail target',
				'post_content' => '',
				'post_excerpt' => '',
				'meta'         => [
					'_elementor_data'      => (string) wp_json_encode(
						[
							[
								'id'       => 'root001',
								'elType'   => 'container',
								'settings' => [ 'content_width' => 'boxed' ],
								'elements' => [
									[
										'id'         => 'head001',
										'elType'     => 'widget',
										'widgetType' => 'heading',
										'settings'   => [ 'title' => 'Hello', 'typography_typography' => '' ],
										'elements'   => [],
									],
								],
							],
						]
					),
					'_elementor_edit_mode' => 'builder',
				],
			],
		];
		$GLOBALS['stonewright_test_options']         = [ 'stonewright_mode' => 'development' ];
		$GLOBALS['stonewright_test_user_caps']       = [ 'edit_post' => true, 'edit_posts' => true ];
		$GLOBALS['stonewright_test_user_logged_in']  = true;
		$GLOBALS['stonewright_test_transients']      = [];
		$GLOBALS['stonewright_test_wpdb_inserts']    = [];
		$GLOBALS['stonewright_test_current_user_id'] = 1;
		IncidentStore::reset_for_tests();
	}

	protected function tearDown(): void {
		\Elementor\Plugin::$instance = $this->original_elementor;
		WidgetSchemaRepository::reset_request_cache();
		$GLOBALS['stonewright_test_posts']           = [];
		$GLOBALS['stonewright_test_options']         = [];
		$GLOBALS['stonewright_test_user_caps']       = [];
		$GLOBALS['stonewright_test_user_logged_in']  = false;
		$GLOBALS['stonewright_test_transients']      = [];
		$GLOBALS['stonewright_test_wpdb_inserts']    = [];
		$GLOBALS['stonewright_test_current_user_id'] = 0;
		IncidentStore::reset_for_tests();
	}

	/** @param list<array<string, mixed>> $operations */
	private function refuse( array $operations, bool $dry_run = true ): \WP_Error {
		$result = ( new BatchMutate() )->execute( [ 'post_id' => 601, 'dry_run' => $dry_run, 'operations' => $operations ] );
		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_batch_operation_failed', $result->get_error_code() );
		return $result;
	}

	/** @return array<string, mixed> */
	private function audit_details(): array {
		$rows = $GLOBALS['stonewright_test_wpdb_inserts'];
		self::assertNotEmpty( $rows );
		$details = json_decode( (string) ( $rows[0]['data']['redacted_details'] ?? '' ), true );
		self::assertIsArray( $details );
		return $details;
	}

	public function test_every_rejected_setting_of_every_operation_is_listed_with_its_element_and_widget(): void {
		$error = $this->refuse(
			[
				[ 'action' => 'add_widget', 'parent_id' => 'root001', 'op_id' => 'title', 'widget_type' => 'heading', 'settings' => [ 'title' => 'New', 'title_color' => 'blue-ish', 'header_size' => 'h9' ] ],
				[ 'action' => 'update_element', 'element_id' => 'head001', 'settings' => [ 'typography_font_size' => [ 'unit' => 'px', 'size' => 40 ] ] ],
				[ 'action' => 'add_container', 'parent_id' => 'root001', 'settings' => [ 'width' => [ 'unit' => '%', 'size' => 50 ] ] ],
			]
		);
		$data = $error->get_error_data();

		self::assertSame( 3, $data['failed'] );
		$rejected = $data['rejected_settings'];
		self::assertCount( 4, $rejected );

		self::assertSame( 0, $rejected[0]['operation_index'] );
		self::assertSame( 'add_widget', $rejected[0]['action'] );
		self::assertSame( 'title', $rejected[0]['op_id'] );
		self::assertSame( 'heading', $rejected[0]['widget_type'] );
		self::assertSame( 'root001', $rejected[0]['parent_id'] );
		self::assertSame( 'title_color', $rejected[0]['setting'] );
		self::assertSame( 'settings.title_color', $rejected[0]['path'] );
		self::assertSame( 'string', $rejected[0]['received_value']['type'] );
		self::assertSame( 'blue-ish', $rejected[0]['received_value']['preview'] );
		self::assertSame( 'color', $rejected[0]['control']['type'] );
		self::assertSame( [ 'title_color' => '#1a73e8' ], $rejected[0]['example'] );
		self::assertSame( 'header_size', $rejected[1]['setting'] );
		self::assertContains( 'h2', $rejected[1]['allowed_values'] );

		self::assertSame( 1, $rejected[2]['operation_index'] );
		self::assertSame( 'update_element', $rejected[2]['action'] );
		self::assertSame( 'head001', $rejected[2]['element_id'] );
		self::assertSame( 'heading', $rejected[2]['widget_type'] );
		self::assertSame( 'inactive_condition', $rejected[2]['code'] );
		self::assertSame( [ 'typography_typography' => 'custom' ], $rejected[2]['requires'] );

		self::assertSame( 2, $rejected[3]['operation_index'] );
		self::assertSame( 'add_container', $rejected[3]['action'] );
		self::assertSame( 'container', $rejected[3]['widget_type'] );
		self::assertSame( [ 'content_width' => 'full' ], $rejected[3]['requires'] );
	}

	public function test_the_message_names_the_operation_the_element_the_setting_and_what_to_send(): void {
		$error = $this->refuse(
			[
				[ 'action' => 'update_element', 'element_id' => 'head001', 'settings' => [ 'typography_font_size' => [ 'unit' => 'px', 'size' => 40 ] ] ],
			]
		);
		$message = $error->get_error_message();

		self::assertStringContainsString( 'operation 0', $message );
		self::assertStringContainsString( 'update_element', $message );
		self::assertStringContainsString( 'head001', $message );
		self::assertStringContainsString( 'heading', $message );
		self::assertStringContainsString( 'settings.typography_font_size', $message );
		self::assertStringContainsString( 'typography_typography=custom', $message );
		self::assertStringContainsString( 'No page data was written', $message );
	}

	public function test_a_stop_on_error_write_names_the_same_detail(): void {
		$error = $this->refuse(
			[
				[ 'action' => 'add_widget', 'parent_id' => 'root001', 'widget_type' => 'heading', 'settings' => [ 'title' => 'New', 'title_colour' => '#fff' ] ],
			],
			false
		);
		$data = $error->get_error_data();

		self::assertSame( 'title_colour', $data['rejected_settings'][0]['setting'] );
		self::assertSame( 'title_color', $data['rejected_settings'][0]['suggestions'][0] );
		self::assertStringContainsString( 'title_colour', $error->get_error_message() );
		self::assertStringContainsString( 'title_color', $error->get_error_message() );
	}

	public function test_the_audit_row_keeps_the_real_message_and_the_first_rejected_setting(): void {
		$this->refuse(
			[
				[ 'action' => 'update_element', 'element_id' => 'head001', 'settings' => [ 'typography_font_size' => [ 'unit' => 'px', 'size' => 40 ] ] ],
				[ 'action' => 'add_widget', 'parent_id' => 'root001', 'widget_type' => 'heading', 'settings' => [ 'title' => 'x', 'title_color' => 'blue-ish' ] ],
			]
		);
		$details = $this->audit_details();

		self::assertSame( 'stonewright_batch_operation_failed', $details['error_code'] );
		self::assertSame( 'stonewright_elementor_settings_invalid', $details['root_error_code'] );
		self::assertStringContainsString( 'settings.typography_font_size', $details['error_message'] );
		self::assertStringContainsString( 'head001', $details['error_message'] );
		self::assertSame( 'head001', $details['element_id'] );
		self::assertSame( 'settings.typography_font_size', $details['setting_path'] );
		self::assertSame( 'array', $details['actual_type'] );
		self::assertSame( 0, $details['failed_action_index'] );
		self::assertSame( 'heading', $details['widget_type'] );
		self::assertSame( 2, $details['rejected_total'] );
		self::assertStringContainsString( 'op1 add_widget heading: title_color', $details['rejected_summary'] );
		self::assertStringContainsString( 'blue-ish', $details['rejected_summary'] );
		self::assertLessThanOrEqual( 500, mb_strlen( $details['error_message'] ) );
	}

	public function test_a_secret_in_a_rejected_value_never_reaches_the_error_data_or_the_audit_row(): void {
		$error = $this->refuse(
			[
				[ 'action' => 'add_widget', 'parent_id' => 'root001', 'widget_type' => 'heading', 'settings' => [ 'title' => 'x', 'title_color' => 'Bearer fixture-token-abcdefghijklmnop' ] ],
			]
		);
		$encoded = (string) wp_json_encode( $error->get_error_data() ) . $error->get_error_message() . (string) wp_json_encode( $this->audit_details() );

		self::assertStringNotContainsString( 'abcdefghijklmnop', $encoded );
		self::assertStringContainsString( 'title_color', $encoded );
	}

	public function test_the_repair_text_tells_the_caller_to_use_the_listed_values_instead_of_the_schema_round_trip_only(): void {
		$error = $this->refuse(
			[
				[ 'action' => 'add_widget', 'parent_id' => 'root001', 'widget_type' => 'heading', 'settings' => [ 'title' => 'x', 'title_color' => 'blue-ish' ] ],
			]
		);
		$repair = (string) $error->get_error_data()['repair'];

		self::assertStringContainsString( 'rejected_settings', $repair );
		self::assertStringContainsString( 'example', $repair );
	}

	public function test_a_batch_of_accepted_settings_still_plans(): void {
		$result = ( new BatchMutate() )->execute(
			[
				'post_id'    => 601,
				'dry_run'    => true,
				'operations' => [
					[ 'action' => 'add_widget', 'parent_id' => 'root001', 'widget_type' => 'heading', 'settings' => [ 'title' => 'New', 'size' => 'default', 'typography_typography' => 'custom', 'typography_font_weight' => 600, 'align_mobile' => 'center' ], 'responsive_scope' => [ 'desktop', 'mobile' ] ],
					[ 'action' => 'update_element', 'element_id' => 'head001', 'settings' => [ 'typography_typography' => 'custom', 'typography_font_size' => [ 'unit' => 'custom', 'size' => 'clamp(1.5rem, 4vw, 3rem)' ] ], ],
				],
			]
		);

		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() : '' );
		self::assertSame( 0, $result['failed'] );
	}

	public function test_a_refusal_that_is_not_about_settings_still_says_why_in_the_message_and_the_audit_row(): void {
		$error = $this->refuse(
			[
				[ 'action' => 'add_widget', 'parent_id' => 'root001', 'widget_type' => 'heading', 'settings' => [ 'title' => 'x', 'align_mobile' => 'center' ] ],
				[ 'action' => 'update_element', 'element_id' => 'gone999', 'settings' => [ 'title' => 'y' ] ],
			]
		);
		$message = $error->get_error_message();

		self::assertStringContainsString( 'Operation 0 failed:', $message );
		self::assertStringContainsString( 'align_mobile', $message );
		self::assertStringContainsString( 'responsive_scope', $message );
		self::assertStringContainsString( 'Operation 1 failed: Element not found.', $message );
		self::assertStringContainsString( 'responsive_scope', (string) $error->get_error_data()['items'][0]['error']['data']['repair'] );
		self::assertStringContainsString( 'Operation 0 failed:', $this->audit_details()['error_message'] );
	}
}
