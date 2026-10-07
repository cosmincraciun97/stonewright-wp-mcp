<?php
/**
 * Elementor post-write verification returns the change set of the write it verifies,
 * and a failed verification followed by a verified repair forms the audit chain.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\ElementorV3;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\ElementorV3\BatchMutate;
use Stonewright\WpMcp\Abilities\ElementorV3\PostWriteVerify;
use Stonewright\WpMcp\Elementor\Schema\WidgetSchemaRepository;
use Stonewright\WpMcp\Security\ChangeSet;
use Stonewright\WpMcp\Security\ChangeSetLineage;
use Stonewright\WpMcp\Security\IncidentStore;
use Stonewright\WpMcp\Tests\Unit\Security\ChangeSetAssertions;

require_once dirname( __DIR__ ) . '/Security/ChangeSetAssertions.php';

/**
 * @covers \Stonewright\WpMcp\Abilities\ElementorV3\PostWriteVerify
 */
final class PostWriteVerifyChangeSetTest extends TestCase {
	use ChangeSetAssertions;

	private object $elementor_instance;

	/** @var list<string> Element ids the stubbed frontend renders. */
	private array $rendered = [];

	protected function setUp(): void {
		$this->elementor_instance = \Elementor\Plugin::$instance;
		WidgetSchemaRepository::reset_request_cache();
		$GLOBALS['stonewright_test_posts'] = [
			501 => (object) [
				'ID'           => 501,
				'post_type'    => 'page',
				'post_status'  => 'draft',
				'post_title'   => 'Verified page',
				'post_content' => '',
				'post_excerpt' => '',
				'meta'         => [
					'_elementor_data'      => '[{"id":"root","elType":"container","settings":{"container_type":"flex"},"elements":[]}]',
					'_elementor_edit_mode' => 'builder',
					'_elementor_version'   => defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : '3.0.0',
				],
			],
		];
		$GLOBALS['stonewright_test_post_meta_calls']  = [];
		$GLOBALS['stonewright_test_wpdb_inserts']     = [];
		$GLOBALS['stonewright_test_options']          = [ 'stonewright_mode' => 'development' ];
		$GLOBALS['stonewright_test_user_caps']        = [ 'edit_post' => true, 'edit_posts' => true ];
		$GLOBALS['stonewright_test_user_logged_in']   = true;
		$GLOBALS['stonewright_test_current_user_id']  = 1;
		$GLOBALS['stonewright_test_transients']       = [];
		IncidentStore::reset_for_tests();
		$this->render( [] );
	}

	protected function tearDown(): void {
		\Elementor\Plugin::$instance = $this->elementor_instance;
		WidgetSchemaRepository::reset_request_cache();
		$GLOBALS['stonewright_test_posts']           = [];
		$GLOBALS['stonewright_test_post_meta_calls'] = [];
		$GLOBALS['stonewright_test_wpdb_inserts']    = [];
		$GLOBALS['stonewright_test_options']         = [];
		$GLOBALS['stonewright_test_user_caps']       = [];
		$GLOBALS['stonewright_test_user_logged_in']  = false;
		$GLOBALS['stonewright_test_current_user_id'] = 0;
		$GLOBALS['stonewright_test_transients']      = [];
		IncidentStore::reset_for_tests();
	}

	/** Make the stubbed Elementor frontend render exactly these element ids. */
	private function render( array $element_ids ): void {
		$this->rendered = $element_ids;
		$ids            = &$this->rendered;
		\Elementor\Plugin::$instance = (object) array_merge(
			(array) $this->elementor_instance,
			[
				'frontend' => new class( $ids ) {
					/** @param list<string> $ids */
					public function __construct( private array &$ids ) {
					}

					public function get_builder_content_for_display( int $post_id, bool $with_css ): string {
						return implode( '', array_map( static fn ( string $id ): string => '<div class="elementor-element-' . $id . '">Content</div>', $this->ids ) );
					}
				},
			]
		);
	}

	/** @return array<string, mixed> */
	private function write( string $change_set_id, string $title, ?string $repair_of = null ): array {
		$result = ( new BatchMutate() )->execute(
			[
				'post_id'       => 501,
				'change_set_id' => $change_set_id,
				'operations'    => [ [ 'action' => 'add_widget', 'op_id' => 'headline', 'parent_id' => 'root', 'widget_type' => 'heading', 'settings' => [ 'title' => $title ] ] ],
			] + ( null === $repair_of ? [] : [ 'repair_of' => $repair_of ] )
		);
		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() : '' );
		return $result;
	}

	/** @return array<string, mixed> The last audit row recorded. */
	private function last_row(): array {
		$rows = $GLOBALS['stonewright_test_wpdb_inserts'];
		self::assertNotEmpty( $rows );
		return end( $rows )['data'];
	}

	public function test_a_passing_render_verifies_the_change_set_of_the_write(): void {
		$written = $this->write( 'cs-write-1', 'Hello' );
		$element = $written['refs']['headline'];
		$this->render( [ $element ] );

		$result = ( new PostWriteVerify() )->execute( [ 'post_id' => 501, 'element_ids' => [ $element ], 'change_set' => $written['change_set'] ] );

		self::assertIsArray( $result );
		self::assertTrue( $result['ok'] );
		$change_set = $result['change_set'];
		self::assertValidChangeSet( $change_set );
		self::assertSame( 'cs-write-1', $change_set['change_set_id'], 'The verification belongs to the change set of the write.' );
		self::assertSame( 'verified', $change_set['verification']['status'] );
		self::assertSame( 'frontend_render', $change_set['verification']['evidence']['method'] );
		self::assertSame( $result['render_sha256'], $change_set['verification']['evidence']['render_sha256'] );
		self::assertSame( [ [ 'kind' => 'element', 'ref' => $element, 'action' => 'render', 'index' => 0 ] ], $change_set['planned'] );
		self::assertSame( $change_set['planned'], $change_set['applied'] );
		self::assertSame( [], $change_set['missing'] );
		self::assertSame( $written['change_set']['before_hash'], $change_set['before_hash'] );
		self::assertSame( $written['change_set']['after_hash'], $change_set['after_hash'] );
		self::assertSame( $written['change_set']['rollback_recipe_ref'], $change_set['rollback_recipe_ref'] );
		self::assertTrue( $change_set['rollback_available'] );
		self::assertSame( 'cs-write-1', $this->last_row()['change_set_id'] );
		self::assertSame( 'SUCCESS', $this->last_row()['outcome'] );
		self::assertSame( '', $this->last_row()['incident_id'] );
	}

	public function test_a_render_that_lacks_the_element_fails_the_change_set_and_names_what_is_missing(): void {
		$written = $this->write( 'cs-write-2', 'Hello' );
		$element = $written['refs']['headline'];

		$result = ( new PostWriteVerify() )->execute( [ 'post_id' => 501, 'element_ids' => [ $element, 'never-rendered' ], 'change_set' => $written['change_set'] ] );

		self::assertIsArray( $result );
		self::assertFalse( $result['ok'] );
		$change_set = $result['change_set'];
		self::assertValidChangeSet( $change_set );
		self::assertSame( 'cs-write-2', $change_set['change_set_id'] );
		self::assertSame( 'failed', $change_set['verification']['status'] );
		self::assertSame( [ $element, 'never-rendered' ], array_column( $change_set['planned'], 'ref' ) );
		self::assertSame( [], $change_set['applied'] );
		self::assertSame( [ $element, 'never-rendered' ], array_column( $change_set['missing'], 'ref' ) );
		self::assertSame( 'frontend', $change_set['verification']['evidence']['failed_check'] );
		self::assertSame( 'stonewright_elementor_frontend_verification_failed', $change_set['verification']['evidence']['root_error_code'] );
		self::assertSame( 'cs-write-2', $this->last_row()['change_set_id'] );
		self::assertSame( 'FAILED', $this->last_row()['outcome'] );
		self::assertSame( 'failed', $this->last_row()['verification_status'] );
	}

	public function test_a_partial_render_splits_the_checks_into_applied_and_missing(): void {
		$written = $this->write( 'cs-write-3', 'Hello' );
		$element = $written['refs']['headline'];
		$this->render( [ $element ] );

		$result = ( new PostWriteVerify() )->execute( [ 'post_id' => 501, 'element_ids' => [ $element, 'absent-one' ], 'html_contains' => [ 'Content', 'no such text' ], 'change_set' => $written['change_set'] ] );

		self::assertIsArray( $result );
		$change_set = $result['change_set'];
		self::assertValidChangeSet( $change_set );
		self::assertSame( 'failed', $change_set['verification']['status'] );
		self::assertCount( 4, $change_set['planned'] );
		self::assertSame( [ $element ], array_column( array_filter( $change_set['applied'], static fn ( array $e ): bool => 'element' === $e['kind'] ), 'ref' ) );
		self::assertSame( [ 'absent-one' ], array_column( array_filter( $change_set['missing'], static fn ( array $e ): bool => 'element' === $e['kind'] ), 'ref' ) );
		self::assertCount( 1, array_filter( $change_set['applied'], static fn ( array $e ): bool => 'content' === $e['kind'] ), 'A marker that renders is applied.' );
		self::assertCount( 1, array_filter( $change_set['missing'], static fn ( array $e ): bool => 'content' === $e['kind'] ), 'A marker that does not render is missing.' );
		self::assertStringNotContainsString( 'no such text', (string) wp_json_encode( $change_set ), 'Markers are reported by hash, never as text.' );
	}

	public function test_the_write_receipt_alone_still_identifies_the_change_set(): void {
		$written = $this->write( 'cs-write-4', 'Hello' );
		$element = $written['refs']['headline'];
		$this->render( [ $element ] );

		$result = ( new PostWriteVerify() )->execute( [ 'post_id' => 501, 'element_ids' => [ $element ], 'write_receipt' => $written['write_receipt'] ] );

		self::assertIsArray( $result );
		$change_set = $result['change_set'];
		self::assertValidChangeSet( $change_set );
		self::assertSame( 'cs-write-4', $change_set['change_set_id'] );
		self::assertSame( 'verified', $change_set['verification']['status'] );
		self::assertSame( $written['write_receipt']['before_hash'], $change_set['before_hash'] );
		self::assertSame( $written['write_receipt']['snapshot_id'], $change_set['rollback_recipe_ref']['ref'] );
	}

	public function test_a_verification_without_a_receipt_or_change_set_reports_none(): void {
		$this->render( [ 'x' ] );

		$result = ( new PostWriteVerify() )->execute( [ 'post_id' => 501, 'element_ids' => [ 'x' ] ] );

		self::assertIsArray( $result );
		self::assertArrayNotHasKey( 'change_set', $result );
	}

	public function test_a_failed_verification_then_a_verified_repair_forms_the_audit_chain_and_resolves_the_incident(): void {
		// A: the write verifies at readback, but the page does not show it.
		$first   = $this->write( 'cs-A', 'Hello' );
		$element = $first['refs']['headline'];
		$verify  = ( new PostWriteVerify() )->execute( [ 'post_id' => 501, 'element_ids' => [ $element ], 'change_set' => $first['change_set'] ] );
		self::assertIsArray( $verify );
		self::assertFalse( $verify['ok'] );
		$incidents = IncidentStore::recent();
		self::assertCount( 1, $incidents, 'The failed verification is a recorded failure.' );
		self::assertNotSame( 'resolved', $incidents[0]['state'] );
		self::assertSame( 'cs-A', $incidents[0]['last_change_set_id'] );

		// B: the repair names A and verifies.
		$repair = $this->write( 'cs-B', 'Hello again', 'cs-A' );

		self::assertSame( 'verified', $repair['change_set']['verification']['status'] );
		self::assertSame( 'cs-A', $repair['change_set']['repair_of'] );
		$incident = IncidentStore::recent()[0];
		self::assertSame( 'resolved', $incident['state'] );
		self::assertSame( $this->last_row()['event_id'], $incident['resolution_event_id'] );
		self::assertSame( '', $this->last_row()['incident_id'], 'The successful repair row belongs to no incident.' );

		// The audit rows read as the chain A -> verification failed -> repair B -> verified.
		$rows = [];
		foreach ( $GLOBALS['stonewright_test_wpdb_inserts'] as $index => $insert ) {
			$rows[] = array_merge( [ 'id' => $index + 1 ], $insert['data'] );
		}
		$graph = ChangeSetLineage::graph( $rows );
		$tree  = ChangeSetLineage::tree( $graph, 'cs-B' );

		self::assertSame( [ 'cs-A', 'cs-B' ], $tree['order'] );
		self::assertSame( [ 'cs-B' ], $tree['nodes']['cs-A']['children'] );
		self::assertSame( [ 'failed', 'verified' ], [ $tree['nodes']['cs-A']['state'], $tree['nodes']['cs-B']['state'] ] );
		self::assertSame( 'verification_failed', $tree['nodes']['cs-A']['detail'] );
		self::assertSame( 'stonewright/elementor-post-write-verify', $rows[1]['ability_name'] );
		self::assertSame( $incident['incident_id'], $tree['nodes']['cs-A']['incident_id'] );
	}

	public function test_the_ability_declares_the_change_set_input_and_output(): void {
		$ability = new PostWriteVerify();

		self::assertSame( 'object', $ability->input_schema()['properties']['change_set']['type'] );
		self::assertSame( ChangeSet::output_property(), $ability->output_schema()['properties']['change_set'] );
	}
}
