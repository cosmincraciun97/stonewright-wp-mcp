<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\ElementorV4;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\ElementorV4\Migrate;
use Stonewright\WpMcp\Elementor\V4\V4FeatureGate;
use Stonewright\WpMcp\Elementor\Write\PostWriteLock;
use Stonewright\WpMcp\Elementor\Write\TreeHasher;
use Stonewright\WpMcp\Security\ConfirmationToken;
use Stonewright\WpMcp\Tests\Support\ElementorKsesSimulation;

require_once dirname( __DIR__, 2 ) . '/Support/elementor-kses-simulation.php';

/**
 * The V3 to V4 migration: error codes, the confirmation, the write lock with the tree recheck, and the rollback answer.
 *
 * @covers \Stonewright\WpMcp\Abilities\ElementorV4\Migrate
 */
final class MigrateTest extends TestCase {
	use ElementorKsesSimulation;

	private const POST  = 7101;
	private const OTHER = 7102;
	private const NAME  = 'stonewright/elementor-v4-migrate';

	/** @var list<array<string, mixed>> */
	private array $tree;

	protected function setUp(): void {
		V4FeatureGate::set_atomic_module_present_for_tests( true );
		$this->tree = [
			[
				'id'       => 'root',
				'elType'   => 'container',
				'settings' => [],
				'elements' => [
					[ 'id' => 'w1', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => [ 'title' => 'Existing' ], 'elements' => [] ],
				],
			],
		];
		$GLOBALS['stonewright_test_options']         = [ 'stonewright_mode' => 'development', 'stonewright_elementor_v4_atomic' => true ];
		$GLOBALS['stonewright_test_user_caps']       = [ 'edit_post' => true, 'edit_posts' => true ];
		$GLOBALS['stonewright_test_user_logged_in']  = true;
		$GLOBALS['stonewright_test_current_user_id'] = 42;
		$GLOBALS['stonewright_test_post_meta_calls'] = [];
		unset( $GLOBALS['stonewright_test_update_post_meta_returns'] );
		$GLOBALS['stonewright_test_posts'] = [
			self::POST  => $this->post( self::POST, $this->tree ),
			self::OTHER => $this->post( self::OTHER, $this->tree ),
		];
	}

	protected function tearDown(): void {
		$this->stop_simulating_elementor_kses();
		V4FeatureGate::set_atomic_module_present_for_tests( null );
		unset( $GLOBALS['stonewright_test_update_post_meta_returns'], $GLOBALS['stonewright_test_after_add_option'] );
		$GLOBALS['stonewright_test_posts']           = [];
		$GLOBALS['stonewright_test_post_meta_calls'] = [];
		$GLOBALS['stonewright_test_options']         = [];
		$GLOBALS['stonewright_test_user_caps']       = [];
		$GLOBALS['stonewright_test_user_logged_in']  = false;
		$GLOBALS['stonewright_test_current_user_id'] = 0;
		$GLOBALS['stonewright_test_wp_update_post_return'] = null;
	}

	/** @param list<array<string, mixed>> $tree */
	private function post( int $id, array $tree ): object {
		return (object) [
			'ID'           => $id,
			'post_type'    => 'page',
			'post_status'  => 'draft',
			'post_title'   => 'Migrate me',
			'post_content' => '',
			'post_excerpt' => '',
			'post_parent'  => 0,
			'post_name'    => 'migrate-' . $id,
			'meta'         => [ '_elementor_data' => (string) wp_json_encode( $tree ), '_elementor_edit_mode' => 'builder' ],
		];
	}

	private function stored( int $id ): string {
		return (string) $GLOBALS['stonewright_test_posts'][ $id ]->meta['_elementor_data'];
	}

	/** @return array<string, mixed> */
	private function apply_args(): array {
		return [ 'post_id' => self::POST, 'apply' => true, 'confirm_migration' => true ];
	}

	public function test_a_plan_writes_nothing_and_needs_no_confirmation(): void {
		$result = ( new Migrate() )->execute( [ 'post_id' => self::POST ] );

		self::assertIsArray( $result );
		self::assertFalse( $result['applied'] );
		self::assertSame( [], $GLOBALS['stonewright_test_post_meta_calls'] );
	}

	public function test_an_apply_without_confirm_migration_is_refused_with_a_single_prefixed_code(): void {
		$result = ( new Migrate() )->execute( [ 'post_id' => self::POST, 'apply' => true ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_v4_migration_confirmation_required', $result->get_error_code() );
		self::assertSame( [], $GLOBALS['stonewright_test_post_meta_calls'] );
	}

	public function test_a_confirmed_apply_migrates_and_returns_the_readback_hash(): void {
		$before = $this->stored( self::POST );

		$result = ( new Migrate() )->execute( $this->apply_args() );

		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() : '' );
		self::assertTrue( $result['applied'] );
		self::assertNotSame( '', $result['snapshot_id'] );
		self::assertNotSame( $before, $this->stored( self::POST ) );
		self::assertArrayHasKey( 'rollback_status', $result );
		self::assertSame( 'not_attempted', $result['rollback_status'] );
		self::assertIsArray( PostWriteLock::acquire( self::POST, 'next-writer', 30 ), 'a finished migration leaves no lock behind' );
	}

	public function test_a_user_without_unfiltered_html_migrates_and_the_readback_matches_the_filtered_tree(): void {
		$this->simulate_elementor_kses();
		$this->tree[0]['elements'][0]['settings']['title'] = 'Existing<script>alert(1)</script>';
		$GLOBALS['stonewright_test_posts'][ self::POST ]   = $this->post( self::POST, $this->tree );

		$result = ( new Migrate() )->execute( $this->apply_args() );

		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() : '' );
		self::assertTrue( $result['applied'] );
		self::assertStringNotContainsString( '<script>', $this->stored( self::POST ) );
	}

	public function test_production_safe_mode_needs_a_token_bound_to_the_post(): void {
		$GLOBALS['stonewright_test_options']['stonewright_mode'] = 'production-safe';
		$before = $this->stored( self::POST );

		$missing = ( new Migrate() )->execute( $this->apply_args() );
		self::assertInstanceOf( \WP_Error::class, $missing );
		self::assertSame( 'stonewright_confirmation_required', $missing->get_error_code() );

		$other_post = $this->apply_args();
		$other_post['post_id'] = self::OTHER;
		$wrong      = ( new Migrate() )->execute( $this->apply_args() + [ 'confirmation_token' => ConfirmationToken::issue( self::NAME, $other_post ) ] );
		self::assertInstanceOf( \WP_Error::class, $wrong );
		self::assertSame( 'stonewright_confirmation_args_mismatch', $wrong->get_error_code() );
		self::assertSame( $before, $this->stored( self::POST ), 'a token for another post migrates nothing' );

		$right = ( new Migrate() )->execute( $this->apply_args() + [ 'confirmation_token' => ConfirmationToken::issue( self::NAME, $this->apply_args() ) ] );
		self::assertIsArray( $right, $right instanceof \WP_Error ? $right->get_error_message() : '' );
		self::assertTrue( $right['applied'] );
	}

	public function test_the_input_schema_offers_the_confirmation_token(): void {
		self::assertArrayHasKey( 'confirmation_token', ( new Migrate() )->input_schema()['properties'] );
		self::assertArrayHasKey( 'rollback_status', ( new Migrate() )->output_schema()['properties'] );
	}

	public function test_an_apply_is_refused_while_another_transaction_holds_the_page_lock(): void {
		self::assertIsArray( PostWriteLock::acquire( self::POST, 'other-writer', 30 ) );
		$before = $this->stored( self::POST );

		$result = ( new Migrate() )->execute( $this->apply_args() );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_elementor_write_busy', $result->get_error_code() );
		self::assertSame( [], $GLOBALS['stonewright_test_post_meta_calls'], 'no snapshot and no write' );
		self::assertSame( $before, $this->stored( self::POST ) );
	}

	public function test_an_apply_rechecks_the_page_after_taking_the_lock(): void {
		$changed = $this->tree;
		$changed[0]['elements'][0]['settings']['title'] = 'Changed by someone else';
		$GLOBALS['stonewright_test_after_add_option'] = function () use ( $changed ): void {
			$GLOBALS['stonewright_test_posts'][ self::POST ]->meta['_elementor_data'] = wp_json_encode( $changed );
		};

		$result = ( new Migrate() )->execute( $this->apply_args() );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_elementor_revision_conflict', $result->get_error_code() );
		self::assertSame( TreeHasher::hash( $changed ), $result->get_error_data()['current_tree_hash'] );
		self::assertSame( [], $GLOBALS['stonewright_test_post_meta_calls'], 'the stale plan takes no snapshot and writes nothing' );
	}

	public function test_a_failed_write_reports_whether_the_snapshot_was_restored(): void {
		$GLOBALS['stonewright_test_update_post_meta_returns'] = [ '_elementor_data' => false ];

		$restored = ( new Migrate() )->execute( $this->apply_args() );

		self::assertInstanceOf( \WP_Error::class, $restored );
		self::assertSame( 'stonewright_v4_migration_write_failed', $restored->get_error_code() );
		self::assertSame( 'succeeded', $restored->get_error_data()['rollback_status'] );
		self::assertNotSame( '', $restored->get_error_data()['snapshot_id'] );

		// The restore itself fails: the post update inside Backup::restore() is refused.
		$GLOBALS['stonewright_test_update_post_meta_returns'] = [ '_elementor_data' => false ];
		$GLOBALS['stonewright_test_wp_update_post_return']    = new \WP_Error( 'db_error', 'refused' );
		$failed = ( new Migrate() )->execute( $this->apply_args() );

		self::assertInstanceOf( \WP_Error::class, $failed );
		self::assertSame( 'failed', $failed->get_error_data()['rollback_status'] );
		self::assertStringNotContainsString( 'was restored', $failed->get_error_message() );
	}
}
