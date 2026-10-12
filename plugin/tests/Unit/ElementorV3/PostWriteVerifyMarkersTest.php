<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\ElementorV3;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\ElementorV3\PostWriteVerify;

require_once __DIR__ . '/Fixtures/post-password-required-stub.php';

/**
 * Frontend verification matches the markers each element kind really renders and says why an element is missing.
 *
 * @covers \Stonewright\WpMcp\Abilities\ElementorV3\PostWriteVerify
 */
final class PostWriteVerifyMarkersTest extends TestCase {

	private object $elementor_instance;

	protected function setUp(): void {
		$this->elementor_instance = \Elementor\Plugin::$instance;
		$GLOBALS['stonewright_test_user_caps']      = [ 'edit_post' => true ];
		$GLOBALS['stonewright_test_user_logged_in'] = true;
		$GLOBALS['stonewright_test_options']        = [ 'stonewright_mode' => 'development' ];
	}

	protected function tearDown(): void {
		\Elementor\Plugin::$instance = $this->elementor_instance;
		unset( $GLOBALS['stonewright_test_posts'][ 302 ] );
		$GLOBALS['stonewright_test_user_caps']      = [];
		$GLOBALS['stonewright_test_user_logged_in'] = false;
		$GLOBALS['stonewright_test_options']        = [];
	}

	/**
	 * @param list<array<string,mixed>> $tree
	 * @param array<string,mixed>       $extra_meta
	 */
	private function post( array $tree, string $status = 'publish', array $extra_meta = [], string $password = '', bool $built = true ): void {
		$meta = $extra_meta;
		if ( [] !== $tree ) {
			$meta['_elementor_data'] = wp_json_encode( $tree );
		}
		if ( $built ) {
			$meta['_elementor_edit_mode'] = 'builder';
		}
		$GLOBALS['stonewright_test_posts'][ 302 ] = (object) [
			'ID'            => 302,
			'post_type'     => 'page',
			'post_status'   => $status,
			'post_password' => $password,
			'post_title'    => 'Verify markers',
			'post_content'  => '',
			'post_excerpt'  => '',
			'meta'          => $meta,
		];
	}

	private function render( string $html, ?bool $atomic = null ): void {
		$instance = [
			'frontend' => new class( $html ) {
				public function __construct( private string $html ) {
				}

				public function get_builder_content_for_display( int $post_id, bool $with_css ): string {
					return $this->html;
				}
			},
		];
		if ( null !== $atomic ) {
			$instance['experiments'] = new class( $atomic ) {
				public function __construct( private bool $active ) {
				}

				public function is_feature_active( string $name ): bool {
					return 'e_atomic_elements' === $name && $this->active;
				}
			};
		}
		\Elementor\Plugin::$instance = (object) $instance;
	}

	/**
	 * @param list<string> $element_ids
	 * @param list<string> $html_contains
	 * @return array<string,mixed>
	 */
	private function verify( array $element_ids, array $html_contains = [] ): array {
		$result = ( new PostWriteVerify() )->execute( [ 'post_id' => 302, 'element_ids' => $element_ids, 'html_contains' => $html_contains ] );
		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() : '' );
		return $result;
	}

	/** @return list<array<string,mixed>> */
	private static function widget_tree( string $id, string $widget_type ): array {
		return [ [ 'id' => $id, 'elType' => 'widget', 'widgetType' => $widget_type, 'settings' => [], 'elements' => [] ] ];
	}

	/** @return list<array<string,mixed>> */
	private static function element_tree( string $id, string $el_type ): array {
		return [ [ 'id' => $id, 'elType' => $el_type, 'settings' => [], 'elements' => [] ] ];
	}

	public function test_a_v4_container_is_found_by_its_data_id_and_class(): void {
		$this->post( self::element_tree( 'divblk1', 'e-div-block' ) );
		$this->render( '<div class="elementor-element elementor-element-divblk1 e-con e-atomic-element" data-id="divblk1" data-e-type="e-div-block"></div>', true );

		$result = $this->verify( [ 'divblk1' ] );

		self::assertTrue( $result['ok'] );
		self::assertTrue( $result['element_checks'][0]['present'] );
		self::assertNotSame( '', (string) ( $result['element_checks'][0]['matched_marker'] ?? '' ) );
		self::assertContains( 'data-id="divblk1"', $result['element_checks'][0]['markers_checked'] );
	}

	public function test_a_v4_heading_is_found_by_its_interaction_id_only(): void {
		$this->post( self::widget_tree( 'head001', 'e-heading' ) );
		$this->render( '<h2 data-interaction-id="head001" class="e-heading-base">Title</h2>', true );

		$result = $this->verify( [ 'head001' ] );

		self::assertTrue( $result['ok'], 'A V4 leaf widget renders no data-id and no elementor-element class.' );
		self::assertSame( 'data-interaction-id="head001"', $result['element_checks'][0]['matched_marker'] );
	}

	public function test_a_v4_tabs_element_is_found_by_data_id_alone(): void {
		$this->post( self::element_tree( 'tabs001', 'e-tabs' ) );
		$this->render( '<div class="e-con e-atomic-element" data-id="tabs001" data-e-type="e-tabs"></div>', true );

		self::assertTrue( $this->verify( [ 'tabs001' ] )['ok'] );
	}

	public function test_a_leaf_widget_is_not_proven_by_a_class_it_never_renders(): void {
		$this->post( self::widget_tree( 'head002', 'e-heading' ) );
		$this->render( '<div class="elementor-element-head002">Other</div>', true );

		$result = $this->verify( [ 'head002' ] );

		self::assertFalse( $result['ok'] );
		self::assertSame( 'not_rendered', $result['missing_elements'][0]['reason'] );
	}

	public function test_an_id_that_is_a_prefix_of_another_rendered_id_is_not_a_match(): void {
		$this->post( self::widget_tree( 'abc', 'heading' ) );
		$this->render( '<div class="elementor-element elementor-element-abcd" data-id="abcd"></div>' );

		$result = $this->verify( [ 'abc' ] );

		self::assertFalse( $result['ok'] );
		self::assertFalse( $result['element_checks'][0]['present'] );
		self::assertSame( 'not_rendered', $result['missing_elements'][0]['reason'] );
		self::assertSame( 'heading', $result['missing_elements'][0]['widget_type'] );
	}

	public function test_a_v3_element_is_found_by_data_id_or_class_token(): void {
		$this->post( [ [ 'id' => 'v3a', 'elType' => 'container', 'settings' => [], 'elements' => [ [ 'id' => 'v3b', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => [], 'elements' => [] ] ] ] ] );
		$this->render( '<div class="elementor-element elementor-element-v3a" data-id="v3a"><div data-id="v3b" class="elementor-widget"></div></div>' );

		self::assertTrue( $this->verify( [ 'v3a', 'v3b' ] )['ok'] );
	}

	public function test_an_id_missing_from_the_stored_document_is_reported_as_such(): void {
		$this->post( self::widget_tree( 'real01', 'heading' ) );
		$this->render( '<div data-id="real01"></div>' );

		$result = $this->verify( [ 'real01', 'ghost01' ] );

		self::assertFalse( $result['ok'] );
		self::assertSame( [ [ 'element_id' => 'ghost01', 'widget_type' => '', 'reason' => 'not_in_document' ] ], $result['missing_elements'] );
		self::assertStringContainsString( 'ghost01', $result['message'] );
		self::assertStringContainsString( 'not_in_document', $result['message'] );
	}

	public function test_a_password_protected_post_explains_the_empty_render(): void {
		$this->post( self::widget_tree( 'head003', 'heading' ), 'publish', [], 'fixture-pass' );
		$this->render( '' );

		$result = $this->verify( [ 'head003' ] );

		self::assertFalse( $result['ok'] );
		self::assertSame( 'password_protected', $result['missing_elements'][0]['reason'] );
		self::assertTrue( $result['render_context']['password_protected'] );
		self::assertStringContainsString( 'password', strtolower( $result['message'] ) );
	}

	public function test_a_post_not_built_with_elementor_says_so(): void {
		$this->post( self::widget_tree( 'head004', 'heading' ), 'publish', [], '', false );
		$this->render( '' );

		$result = $this->verify( [ 'head004' ] );

		self::assertSame( 'not_built_with_elementor', $result['missing_elements'][0]['reason'] );
		self::assertFalse( $result['render_context']['built_with_elementor'] );
	}

	public function test_a_post_without_elementor_data_says_so(): void {
		$this->post( [] );
		$this->render( '' );

		$result = $this->verify( [ 'head005' ] );

		self::assertSame( 'no_elementor_data', $result['missing_elements'][0]['reason'] );
	}

	public function test_an_empty_render_of_a_populated_document_is_render_empty(): void {
		$this->post( self::widget_tree( 'head006', 'heading' ) );
		$this->render( '' );

		$result = $this->verify( [ 'head006' ] );

		self::assertSame( 'render_empty', $result['missing_elements'][0]['reason'] );
		self::assertSame( 0, $result['render_context']['rendered_bytes'] );
	}

	public function test_a_live_element_cache_is_named_when_the_stored_element_is_not_rendered(): void {
		$this->post( self::widget_tree( 'head007', 'heading' ), 'publish', [ '_elementor_element_cache' => [ 'timeout' => time() + 3600, 'value' => [ 'content' => '<p>old</p>' ] ] ] );
		$this->render( '<div data-id="other"></div>' );

		$result = $this->verify( [ 'head007' ] );

		self::assertSame( 'cached_html_suspected', $result['missing_elements'][0]['reason'] );
		self::assertTrue( $result['render_context']['element_cache_active'] );
		self::assertStringContainsString( 'Regenerate CSS & Data', $result['message'] );
		self::assertIsArray( get_post_meta( 302, '_elementor_element_cache', true ), 'The cache is never touched.' );
	}

	public function test_an_expired_element_cache_is_not_blamed(): void {
		$this->post( self::widget_tree( 'head008', 'heading' ), 'publish', [ '_elementor_element_cache' => [ 'timeout' => time() - 10, 'value' => [] ] ] );
		$this->render( '<div data-id="other"></div>' );

		$result = $this->verify( [ 'head008' ] );

		self::assertSame( 'not_rendered', $result['missing_elements'][0]['reason'] );
		self::assertFalse( $result['render_context']['element_cache_active'] );
	}

	public function test_a_draft_still_verifies_and_carries_a_visibility_note(): void {
		$this->post( self::widget_tree( 'head009', 'heading' ), 'draft' );
		$this->render( '<div data-id="head009"></div>' );

		$result = $this->verify( [ 'head009' ] );

		self::assertTrue( $result['ok'], 'Elementor renders a draft, so a draft alone is not a failure.' );
		self::assertSame( 'draft', $result['render_context']['post_status'] );
		self::assertNotEmpty( $result['notes'] );
		self::assertStringContainsString( 'draft', (string) $result['notes'][0] );
	}

	public function test_a_missing_v4_widget_while_the_experiment_is_off_says_so(): void {
		$this->post( self::widget_tree( 'head010', 'e-heading' ) );
		$this->render( '<div data-id="other"></div>', false );

		$result = $this->verify( [ 'head010' ] );

		self::assertSame( 'not_rendered', $result['missing_elements'][0]['reason'] );
		self::assertFalse( $result['render_context']['atomic_elements_active'] );
		self::assertStringContainsString( 'experiment', (string) ( $result['missing_elements'][0]['note'] ?? '' ) );
	}

	public function test_the_message_is_bounded_and_names_at_most_five_ids(): void {
		$tree = [];
		$ids  = [];
		for ( $i = 1; $i <= 8; ++$i ) {
			$id     = 'wid' . $i . 'aaaaaaaaaaaaaaaa';
			$ids[]  = $id;
			$tree[] = [ 'id' => $id, 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => [], 'elements' => [] ];
		}
		$this->post( $tree );
		$this->render( '<div data-id="other"></div>' );

		$result = $this->verify( $ids );

		self::assertLessThanOrEqual( 400, strlen( $result['message'] ) );
		self::assertStringContainsString( 'and 3 more', $result['message'] );
		self::assertCount( 8, $result['missing_elements'] );
	}

	public function test_a_failed_content_marker_has_a_reason_and_is_never_echoed(): void {
		$this->post( self::widget_tree( 'head011', 'heading' ) );
		$this->render( '<div data-id="head011">Hello</div>' );

		$result = $this->verify( [ 'head011' ], [ 'Secret marker text' ] );

		self::assertFalse( $result['ok'] );
		self::assertSame( 'marker_not_in_render', $result['content_checks'][0]['reason'] );
		self::assertStringNotContainsString( 'Secret marker text', (string) wp_json_encode( $result ) );
		self::assertStringContainsString( 'content marker', $result['message'] );
	}

	public function test_a_renderer_exception_names_only_the_class(): void {
		$this->post( self::widget_tree( 'head012', 'heading' ) );
		\Elementor\Plugin::$instance = (object) [
			'frontend' => new class() {
				public function get_builder_content_for_display( int $post_id, bool $with_css ): string {
					throw new \RuntimeException( 'secret detail from the renderer' );
				}
			},
		];

		$result = $this->verify( [ 'head012' ] );

		self::assertFalse( $result['ok'] );
		self::assertSame( 'renderer_failed', $result['missing_elements'][0]['reason'] );
		self::assertNotSame( '', $result['message'] );
		self::assertStringContainsString( 'RuntimeException', $result['message'] );
		self::assertStringNotContainsString( 'secret detail', (string) wp_json_encode( $result ) );
	}

	public function test_the_audit_row_of_a_failed_verification_carries_the_message_and_the_missing_elements(): void {
		$GLOBALS['stonewright_test_wpdb_inserts']    = [];
		$GLOBALS['stonewright_test_current_user_id'] = 1;
		$this->post( self::widget_tree( 'head001', 'e-heading' ) );
		$this->render( '<div class="elementor elementor-302">nothing of the element</div>', true );

		$result = $this->verify( [ 'head001' ] );

		self::assertFalse( $result['ok'] );
		$details = json_decode( (string) ( $GLOBALS['stonewright_test_wpdb_inserts'][0]['data']['redacted_details'] ?? '' ), true );
		self::assertIsArray( $details );
		self::assertSame( 'stonewright_elementor_frontend_verification_failed', $details['root_error_code'] );
		self::assertStringStartsWith( 'Frontend verification failed.', $details['error_message'] );
		self::assertStringContainsString( 'head001 (not_rendered)', $details['error_message'] );
		self::assertStringNotContainsString( 'The call failed', $details['error_message'] );
		$GLOBALS['stonewright_test_wpdb_inserts']    = [];
		$GLOBALS['stonewright_test_current_user_id'] = 0;
	}
}
