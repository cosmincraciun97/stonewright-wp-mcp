<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Design;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\Design\SpecToElementorV3;
use Stonewright\WpMcp\Elementor\ElementorTransactionRunner;
use Stonewright\WpMcp\Security\ConfirmationToken;
use Stonewright\WpMcp\Support\ElementorData;

/**
 * design-spec-to-elementor-v3: remote assets, the transaction the write goes through, and the page shell.
 *
 * @covers \Stonewright\WpMcp\Abilities\Design\SpecToElementorV3
 * @covers \Stonewright\WpMcp\Elementor\ElementorTransactionRunner
 */
final class SpecToElementorV3WriteSafetyTest extends TestCase {

	private const PAGE = 71;
	private const ASSET_URL = 'https://cdn.example.com/bg/hero.png';

	private int $fetches = 0;

	protected function setUp(): void {
		$this->fetches = 0;
		$this->store_page( [] );
		$GLOBALS['stonewright_test_post_meta_calls'] = [];
		$GLOBALS['stonewright_test_options']         = [ 'stonewright_mode' => 'development' ];
		$GLOBALS['stonewright_test_user_caps']       = [ 'edit_post' => true, 'edit_posts' => true, 'upload_files' => true ];
		$GLOBALS['stonewright_test_user_logged_in']  = true;
		$GLOBALS['stonewright_test_current_user_id'] = 5;
		$GLOBALS['stonewright_test_transients']      = [];
		$GLOBALS['stonewright_test_asset_responses'] = [
			self::ASSET_URL => function (): array {
				++$this->fetches;
				return [
					'response' => [ 'code' => 200 ],
					'headers'  => [ 'content-type' => 'image/png' ],
					'body'     => str_repeat( 'G', 200 ),
				];
			},
		];
		unset( $GLOBALS['stonewright_test_update_post_meta_returns'] );
	}

	protected function tearDown(): void {
		ElementorTransactionRunner::set_read_override( null );
		unset( $GLOBALS['stonewright_test_update_post_meta_returns'] );
		$GLOBALS['stonewright_test_asset_responses'] = [];
		$GLOBALS['stonewright_test_posts']           = [];
		$GLOBALS['stonewright_test_options']         = [];
		$GLOBALS['stonewright_test_user_caps']       = [];
		$GLOBALS['stonewright_test_user_logged_in']  = false;
	}

	/** @param list<array<string, mixed>> $tree */
	private function store_page( array $tree ): void {
		$GLOBALS['stonewright_test_posts'] = [
			self::PAGE => (object) [
				'ID'           => self::PAGE,
				'post_type'    => 'page',
				'post_status'  => 'draft',
				'post_title'   => 'Target',
				'post_content' => '',
				'post_excerpt' => '',
				'post_parent'  => 0,
				'post_name'    => 'target',
				'meta'         => [ '_elementor_data' => (string) wp_json_encode( $tree ) ],
			],
		];
	}

	/** @return array<string, mixed> */
	private function spec( bool $with_asset = true ): array {
		$section = [ 'id' => 'hero', 'blocks' => [ [ 'type' => 'heading', 'text' => 'Hello', 'level' => 1 ] ] ];
		if ( $with_asset ) {
			$section['background'] = [ 'imageRef' => 'asset_hero', 'position' => 'center center', 'size' => 'cover' ];
		}
		return [
			'page'     => [ 'title' => 'Spec page' ],
			'assets'   => $with_asset ? [ [ 'id' => 'asset_hero', 'url' => self::ASSET_URL ] ] : [],
			'sections' => [ $section ],
		];
	}

	/** @return array<string, mixed> */
	private function meta(): array {
		return (array) $GLOBALS['stonewright_test_posts'][ self::PAGE ]->meta;
	}

	/** @return list<array<string, mixed>> */
	private function existing_tree(): array {
		return [ [ 'id' => 'keep0001', 'elType' => 'container', 'settings' => [], 'elements' => [ [ 'id' => 'keep0002', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => [ 'title' => 'Kept' ], 'elements' => [] ] ] ] ];
	}

	// -- Remote assets -------------------------------------------------------------------------

	public function test_remote_assets_are_not_fetched_for_a_user_who_may_not_upload_files(): void {
		$GLOBALS['stonewright_test_user_caps'] = [ 'edit_post' => true, 'edit_posts' => true ];

		$result = ( new SpecToElementorV3() )->execute( [ 'post_id' => self::PAGE, 'replace' => true, 'spec' => $this->spec() ] );

		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_code() : '' );
		self::assertSame( 0, $this->fetches, 'Nothing was fetched.' );
		self::assertSame( [], $result['sideloaded_assets'] );
		self::assertStringContainsString( 'upload', strtolower( (string) wp_json_encode( $result['diagnostics'] ) ), 'The result says why the asset was left alone.' );
	}

	public function test_an_append_in_production_safe_mode_fetches_remote_assets_only_with_a_token(): void {
		$GLOBALS['stonewright_test_options']['stonewright_mode'] = 'production-safe';
		$args = [ 'post_id' => self::PAGE, 'replace' => false, 'spec' => $this->spec() ];

		$without = ( new SpecToElementorV3() )->execute( $args );
		self::assertIsArray( $without, $without instanceof \WP_Error ? $without->get_error_code() : '' );
		self::assertSame( 0, $this->fetches, 'No token, no fetch.' );
		self::assertSame( [], $without['sideloaded_assets'] );

		$this->store_page( [] );
		$args['confirmation_token'] = ConfirmationToken::issue( 'stonewright/design-spec-to-elementor-v3', $args );
		$with = ( new SpecToElementorV3() )->execute( $args );
		self::assertIsArray( $with, $with instanceof \WP_Error ? $with->get_error_code() : '' );
		self::assertSame( 1, $this->fetches );
		self::assertCount( 1, $with['sideloaded_assets'] );
	}

	public function test_remote_assets_are_fetched_in_development_mode_for_a_user_who_may_upload(): void {
		$result = ( new SpecToElementorV3() )->execute( [ 'post_id' => self::PAGE, 'replace' => true, 'spec' => $this->spec() ] );

		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_code() : '' );
		self::assertSame( 1, $this->fetches );
		self::assertCount( 1, $result['sideloaded_assets'] );
	}

	// -- The transaction -----------------------------------------------------------------------

	public function test_a_write_that_does_not_read_back_is_rolled_back(): void {
		$this->store_page( $this->existing_tree() );
		$before = $this->meta()['_elementor_data'];
		ElementorTransactionRunner::set_read_override( static fn (): array => [] );

		$result = ( new SpecToElementorV3() )->execute( [ 'post_id' => self::PAGE, 'replace' => true, 'spec' => $this->spec( false ) ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_transaction_readback_failed', $result->get_error_code() );
		ElementorTransactionRunner::set_read_override( null );
		self::assertSame( $before, $this->meta()['_elementor_data'], 'The page is back to what it was.' );
	}

	public function test_an_append_keeps_the_existing_elements_and_adds_the_new_ones(): void {
		$this->store_page( $this->existing_tree() );

		$result = ( new SpecToElementorV3() )->execute( [ 'post_id' => self::PAGE, 'replace' => false, 'spec' => $this->spec( false ) ] );

		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_code() : '' );
		$ids = array_column( ElementorData::read( self::PAGE ), 'id' );
		self::assertSame( 'keep0001', $ids[0] );
		self::assertGreaterThan( 1, count( $ids ) );
		self::assertNotSame( '', $result['snapshot_id'] );
	}

	public function test_the_transaction_refuses_a_tree_that_changed_since_it_was_read(): void {
		$this->store_page( $this->existing_tree() );

		$result = ElementorTransactionRunner::run_full_tree( self::PAGE, $this->existing_tree(), [], true, [], str_repeat( 'a', 64 ) );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_transaction_precondition', $result->get_error_code() );
		self::assertSame( [], array_filter( $GLOBALS['stonewright_test_post_meta_calls'], static fn( array $c ): bool => '_elementor_data' === $c['meta_key'] ), 'Nothing was written.' );
	}

	// -- The page shell ------------------------------------------------------------------------

	public function test_a_page_without_elements_gets_the_page_shell_by_default_and_the_result_says_so(): void {
		$result = ( new SpecToElementorV3() )->execute( [ 'post_id' => self::PAGE, 'replace' => true, 'spec' => $this->spec( false ) ] );

		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_code() : '' );
		self::assertSame( 'elementor_header_footer', $this->meta()['_wp_page_template'] ?? null );
		self::assertSame( 'yes', $this->meta()['_elementor_page_settings']['hide_title'] ?? null );
		self::assertTrue( $result['page_shell']['applied'] );
	}

	public function test_a_page_that_already_has_elements_keeps_its_shell_by_default(): void {
		$this->store_page( $this->existing_tree() );

		$result = ( new SpecToElementorV3() )->execute( [ 'post_id' => self::PAGE, 'replace' => true, 'spec' => $this->spec( false ) ] );

		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_code() : '' );
		self::assertArrayNotHasKey( '_wp_page_template', $this->meta() );
		self::assertArrayNotHasKey( '_elementor_page_settings', $this->meta() );
		self::assertFalse( $result['page_shell']['applied'] );
	}

	public function test_page_shell_true_applies_it_to_a_page_that_has_elements_and_false_skips_it_on_an_empty_page(): void {
		$this->store_page( $this->existing_tree() );
		$forced = ( new SpecToElementorV3() )->execute( [ 'post_id' => self::PAGE, 'replace' => true, 'page_shell' => true, 'spec' => $this->spec( false ) ] );
		self::assertIsArray( $forced, $forced instanceof \WP_Error ? $forced->get_error_code() : '' );
		self::assertSame( 'elementor_header_footer', $this->meta()['_wp_page_template'] ?? null );

		$this->store_page( [] );
		$skipped = ( new SpecToElementorV3() )->execute( [ 'post_id' => self::PAGE, 'replace' => true, 'page_shell' => false, 'spec' => $this->spec( false ) ] );
		self::assertIsArray( $skipped, $skipped instanceof \WP_Error ? $skipped->get_error_code() : '' );
		self::assertArrayNotHasKey( '_wp_page_template', $this->meta() );
		self::assertFalse( $skipped['page_shell']['applied'] );
	}

	public function test_a_page_shell_that_cannot_be_stored_is_reported_and_does_not_hide_the_write(): void {
		$GLOBALS['stonewright_test_update_post_meta_returns'] = [ '_wp_page_template' => false ];

		$result = ( new SpecToElementorV3() )->execute( [ 'post_id' => self::PAGE, 'replace' => true, 'spec' => $this->spec( false ) ] );

		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_code() : '' );
		self::assertNotSame( [], ElementorData::read( self::PAGE ), 'The design itself was written.' );
		self::assertFalse( $result['page_shell']['applied'] );
		self::assertNotSame( '', (string) $result['page_shell']['warning'] );
	}

	public function test_the_input_schema_documents_page_shell(): void {
		$schema = ( new SpecToElementorV3() )->input_schema();

		self::assertSame( 'boolean', $schema['properties']['page_shell']['type'] );
		self::assertArrayNotHasKey( 'default', $schema['properties']['page_shell'] );
	}
}
