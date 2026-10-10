<?php
/**
 * While section reuse is off: no skill offer, and a refusal that is neither a recurring error nor retryable.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\SectionReuse;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\ElementorV3\BatchMutate;
use Stonewright\WpMcp\Abilities\ElementorV4\UpdateNode;
use Stonewright\WpMcp\Abilities\Gutenberg\BlocksBatchMutate;
use Stonewright\WpMcp\Abilities\SectionReuse\SectionReuseExtract;
use Stonewright\WpMcp\Abilities\System\TaskStart;
use Stonewright\WpMcp\Context\ContextBuilder;
use Stonewright\WpMcp\Elementor\Schema\WidgetSchemaRepository;
use Stonewright\WpMcp\Elementor\V4\AtomicSchemaRepository;
use Stonewright\WpMcp\Elementor\V4\V4FeatureGate;
use Stonewright\WpMcp\SectionReuse\ReferenceCatalog;
use Stonewright\WpMcp\SectionReuse\SectionReuseSetting;
use Stonewright\WpMcp\SkillLibrary\Site\BundledPack;
use Stonewright\WpMcp\Security\ErrorPatterns;
use Stonewright\WpMcp\Security\IncidentStore;
use Stonewright\WpMcp\Security\RemediationHints;
use Stonewright\WpMcp\Tests\Unit\SkillLibrary\Site\SkillTablesDouble;

require_once __DIR__ . '/SectionFixtures.php';

/**
 * @covers \Stonewright\WpMcp\SectionReuse\SectionReuseSetting
 * @covers \Stonewright\WpMcp\Security\ErrorPatterns
 * @covers \Stonewright\WpMcp\Security\RemediationHints
 * @covers \Stonewright\WpMcp\Context\ContextBuilder
 * @covers \Stonewright\WpMcp\SkillLibrary\Site\BundledPack
 */
final class SectionReuseOffTest extends TestCase {

	private const CODE   = 'stonewright_section_reuse_off';
	private const SKILL  = 'stonewright-section-reuse';
	private const SOURCE = 30;
	private const TARGET = 701;

	private mixed $original_wpdb;

	protected function setUp(): void {
		IncidentStore::reset_for_tests();
		WidgetSchemaRepository::reset_request_cache();
		AtomicSchemaRepository::invalidate();
		V4FeatureGate::set_atomic_module_present_for_tests( true );
		ReferenceCatalog::set_provider( static fn( string $type, string $id ): ?bool => true );
		$this->original_wpdb                           = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['stonewright_test_options']           = [ 'stonewright_mode' => 'development', 'stonewright_elementor_v4_atomic' => true, 'stonewright_memory_enabled' => false ];
		$GLOBALS['stonewright_test_post_meta_calls']   = [];
		$GLOBALS['stonewright_test_wpdb_inserts']      = [];
		$GLOBALS['stonewright_test_transients']        = [];
		$GLOBALS['stonewright_test_user_caps']         = [ 'edit_post' => true, 'read_post' => true, 'edit_posts' => true, 'read' => true, 'manage_options' => true ];
		$GLOBALS['stonewright_test_user_can_callback'] = null;
		$GLOBALS['stonewright_test_user_logged_in']    = true;
		$GLOBALS['stonewright_test_current_user_id']   = 1;
		$block_content                                 = "<!-- wp:group {\"anchor\":\"features\"} -->\n<div id=\"features\" class=\"wp-block-group\"></div>\n<!-- /wp:group -->";
		$GLOBALS['stonewright_test_posts']             = [
			self::SOURCE => SectionFixtures::post( self::SOURCE, 'page', 'publish', 'Block source', $block_content ),
			self::TARGET => SectionFixtures::post( self::TARGET, 'page', 'draft', 'Block target', '' ),
			10           => SectionFixtures::post( 10, 'page', 'publish', 'V3 source', '', SectionFixtures::elementor_meta( [ SectionFixtures::v3_features( 'f' ) ] ) ),
			501          => SectionFixtures::post( 501, 'page', 'draft', 'V3 target', '', SectionFixtures::elementor_meta( [ [ 'id' => 'root', 'elType' => 'container', 'isInner' => false, 'settings' => [ 'container_type' => 'flex' ], 'elements' => [] ] ], defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : '3.0.0' ) ),
			20           => SectionFixtures::post( 20, 'page', 'publish', 'V4 source', '', SectionFixtures::elementor_meta( [ SectionFixtures::v4_features( 'v' ) ] ) ),
			601          => SectionFixtures::post( 601, 'page', 'draft', 'V4 target', '', SectionFixtures::elementor_meta( [ [ 'id' => 'a000001', 'version' => '0.0', 'elType' => 'e-div-block', 'isInner' => false, 'settings' => [], 'editor_settings' => [], 'interactions' => [], 'styles' => [], 'elements' => [] ] ] ) ),
		];
		ErrorPatterns::clear();
	}

	protected function tearDown(): void {
		V4FeatureGate::set_atomic_module_present_for_tests( null );
		AtomicSchemaRepository::invalidate();
		WidgetSchemaRepository::reset_request_cache();
		ReferenceCatalog::set_provider( null );
		IncidentStore::reset_for_tests();
		ErrorPatterns::clear();
		if ( null !== $this->original_wpdb ) {
			$GLOBALS['wpdb'] = $this->original_wpdb;
		} else {
			unset( $GLOBALS['wpdb'] );
		}
		$GLOBALS['stonewright_test_posts']             = [];
		$GLOBALS['stonewright_test_post_meta_calls']   = [];
		$GLOBALS['stonewright_test_wpdb_inserts']      = [];
		$GLOBALS['stonewright_test_options']           = [];
		$GLOBALS['stonewright_test_transients']        = [];
		$GLOBALS['stonewright_test_user_caps']         = [];
		$GLOBALS['stonewright_test_user_can_callback'] = null;
		$GLOBALS['stonewright_test_user_logged_in']    = false;
		$GLOBALS['stonewright_test_current_user_id']   = 0;
	}

	private static function turn_off(): void {
		$GLOBALS['stonewright_test_options'][ SectionReuseSetting::OPTION ] = 'off';
	}

	// ------------------------------------------------------------ the skill offer

	/** A wpdb that answers the skill tables from a double and every other table as empty. */
	private function wpdb_with_skills( SkillTablesDouble $tables ): object {
		return new class( $tables ) {
			public string $prefix = 'wp_';
			public string $last_error = '';
			public int $insert_id = 0;

			public function __construct( private SkillTablesDouble $tables ) {}

			private function mine( string $query ): bool {
				return str_contains( $query, 'stonewright_skill' );
			}

			public function prepare( string $query, mixed ...$args ): string {
				return $this->tables->prepare( $query, ...$args );
			}

			public function get_var( string $query ): ?string {
				return $this->mine( $query ) ? $this->tables->get_var( $query ) : 'table_exists';
			}

			public function get_results( string $query, string $output = 'OBJECT' ): array {
				return $this->mine( $query ) ? $this->tables->get_results( $query, $output ) : [];
			}

			public function get_row( string $query, string $output = 'OBJECT' ): ?array {
				return $this->mine( $query ) ? $this->tables->get_row( $query, $output ) : null;
			}

			public function esc_like( string $text ): string {
				return $this->tables->esc_like( $text );
			}

			public function get_charset_collate(): string {
				return $this->tables->get_charset_collate();
			}
		};
	}

	/** @return list<string> Slugs the task-start context offers for a page build. */
	private function matched_slugs(): array {
		$tables = new SkillTablesDouble();
		$tables->seed_skill( [ 'slug' => self::SKILL, 'title' => 'stonewright-section-reuse', 'description' => 'Use when building a landing page section: hero, features, testimonials. Reuse sections.', 'source' => 'builtin' ] );
		$tables->seed_skill( [ 'slug' => 'stonewright-elementor-v3-builder', 'title' => 'elementor-v3-builder', 'description' => 'Use when building a landing page with Elementor.', 'source' => 'builtin' ] );
		$GLOBALS['wpdb'] = $this->wpdb_with_skills( $tables );

		$built = ContextBuilder::build( 'Build a landing page with a hero section', 'wordpress', 'write' );

		return array_column( $built['matched_skills'], 'slug' );
	}

	public function test_task_start_offers_the_reuse_skill_while_the_setting_is_ask(): void {
		self::assertContains( self::SKILL, $this->matched_slugs() );
	}

	public function test_task_start_does_not_offer_the_reuse_skill_while_the_setting_is_off(): void {
		self::turn_off();

		$slugs = $this->matched_slugs();

		self::assertNotContains( self::SKILL, $slugs );
		self::assertContains( 'stonewright-elementor-v3-builder', $slugs, 'Other skills are offered as before.' );
	}

	public function test_a_bundled_skill_whose_directory_carries_the_product_prefix_is_not_prefixed_twice(): void {
		self::assertSame( 'stonewright-section-reuse', BundledPack::identity( 'stonewright-section-reuse' ) );
		self::assertSame( 'stonewright-elementor-v3-builder', BundledPack::identity( 'elementor-v3-builder' ) );
		self::assertSame( 'stonewright-stonewright', BundledPack::identity( 'stonewright' ) );
		self::assertSame( 'playbook-pricing-table', BundledPack::identity( 'playbooks/pricing-table' ) );
		self::assertContains( self::SKILL, BundledPack::slugs() );
		self::assertNotContains( 'stonewright-stonewright-section-reuse', BundledPack::slugs() );
	}

	// ------------------------------------------------------------ the refusal

	public function test_the_refusal_says_blocked_and_not_retryable(): void {
		self::turn_off();

		$error = ( new SectionReuseExtract() )->execute( [ 'post_id' => self::SOURCE, 'locator' => [ 'kind' => 'block', 'path' => [ 0 ] ] ] );

		self::assertInstanceOf( \WP_Error::class, $error );
		self::assertSame( self::CODE, $error->get_error_code() );
		$data = (array) $error->get_error_data();
		self::assertFalse( $data['retryable'] );
		self::assertSame( 'blocked', $data['execution_status'] );
		self::assertFalse( $data['enabled'] );
	}

	public function test_a_batch_writer_reports_the_refusal_as_blocked_and_not_retryable(): void {
		$gutenberg = self::gutenberg_section();
		$v3        = self::elementor_section( 10, 'f000001' );
		$v4        = self::elementor_section( 20, 'v000001' );
		self::turn_off();

		$failures = [
			'gutenberg' => ( new BlocksBatchMutate() )->execute( [ 'post_id' => self::TARGET, 'dry_run' => true, 'operations' => [ [ 'action' => 'insert_section', 'op_id' => 'a', 'section' => $gutenberg ] ] ] ),
			'v3'        => ( new BatchMutate() )->execute( [ 'post_id' => 501, 'dry_run' => true, 'operations' => [ [ 'action' => 'insert_section', 'op_id' => 'a', 'parent_id' => 'root', 'section' => $v3 ] ] ] ),
			'v4'        => ( new UpdateNode() )->execute( [ 'post_id' => 601, 'dry_run' => true, 'operations' => [ [ 'action' => 'insert_section', 'op_id' => 'a', 'parent_id' => 'a000001', 'section' => $v4 ] ] ] ),
		];

		foreach ( $failures as $family => $error ) {
			self::assertInstanceOf( \WP_Error::class, $error, $family );
			$data = (array) $error->get_error_data();
			self::assertSame( self::CODE, $data['items'][0]['error']['code'], $family );
			self::assertFalse( $data['retryable'], $family . ': the refusal is not retryable.' );
			self::assertSame( 'blocked', $data['execution_status'], $family );
		}
	}

	public function test_an_ordinary_batch_failure_stays_retryable(): void {
		$error = ( new BlocksBatchMutate() )->execute( [ 'post_id' => self::TARGET, 'dry_run' => true, 'operations' => [ [ 'action' => 'insert_section', 'op_id' => 'a', 'section' => [ 'schema' => 'nope' ] ] ] ] );

		self::assertInstanceOf( \WP_Error::class, $error );
		self::assertTrue( $error->get_error_data()['retryable'] );
		self::assertArrayNotHasKey( 'execution_status', (array) $error->get_error_data() );
	}

	public function test_repeated_refusals_never_become_a_recurring_error(): void {
		self::turn_off();
		for ( $i = 0; $i < 4; $i++ ) {
			( new SectionReuseExtract() )->execute( [ 'post_id' => self::SOURCE, 'locator' => [ 'kind' => 'block', 'path' => [ 0 ] ] ] );
		}
		unset( $GLOBALS['stonewright_test_options'][ SectionReuseSetting::OPTION ] );

		self::assertSame( [], array_column( ErrorPatterns::recurring(), 'error_code' ), 'Not recurring while the setting is ask either.' );
		$built = ContextBuilder::build( 'Build a landing page', 'wordpress', 'write' );
		self::assertSame( [], $built['recurring_errors'] );
	}

	public function test_the_refusal_is_never_wrapped_in_stop_advice(): void {
		self::turn_off();
		$error = SectionReuseSetting::off_error();
		$sig   = ErrorPatterns::signature( 'stonewright/section-reuse-extract', [ 'error_code' => self::CODE, 'message' => SectionReuseSetting::OFF_INSTRUCTION ] );
		$store = [ $sig => [ 'signature' => $sig, 'ability' => 'stonewright/section-reuse-extract', 'error_code' => self::CODE, 'message' => SectionReuseSetting::OFF_INSTRUCTION, 'count' => 14, 'last_seen' => gmdate( 'c' ), 'first_seen' => gmdate( 'c' ), 'dismissed' => false, 'expected' => false, 'outcome' => 'failed' ] ];
		update_option( ErrorPatterns::OPTION_KEY, $store );

		$escalated = ErrorPatterns::escalate_error( 'stonewright/section-reuse-extract', $error );

		self::assertSame( SectionReuseSetting::OFF_INSTRUCTION, $escalated->get_error_message() );
		self::assertStringNotContainsString( 'dry_run', $escalated->get_error_message() );
		self::assertStringNotContainsString( 'STOP', $escalated->get_error_message() );
		self::assertArrayNotHasKey( 'repair', (array) $escalated->get_error_data() );
		self::assertStringNotContainsString( 'dry_run', RemediationHints::for_code( self::CODE, 'stonewright/section-reuse-extract' ) );
		self::assertStringContainsString( 'Do not', RemediationHints::for_code( self::CODE ) );
	}

	public function test_entries_recorded_earlier_are_hidden_and_removed_when_the_setting_changes(): void {
		$old   = [ 'signature' => 'old', 'ability' => 'stonewright/section-reuse-extract', 'error_code' => self::CODE, 'message' => 'Section reuse is off.', 'count' => 14, 'last_seen' => gmdate( 'c' ), 'first_seen' => gmdate( 'c' ), 'dismissed' => false, 'expected' => false, 'outcome' => 'failed' ];
		$other = [ 'signature' => 'other', 'ability' => 'stonewright/design-apply', 'error_code' => 'stonewright_other', 'message' => 'Spec rejected.', 'count' => 3, 'last_seen' => gmdate( 'c' ), 'first_seen' => gmdate( 'c' ), 'dismissed' => false, 'expected' => false, 'outcome' => 'failed' ];
		update_option( ErrorPatterns::OPTION_KEY, [ 'old' => $old, 'other' => $other ] );

		self::assertSame( [ 'stonewright_other' ], array_column( ErrorPatterns::recurring(), 'error_code' ), 'An entry recorded before is hidden at once.' );

		SectionReuseSetting::on_change( 'off', 'ask' );

		$stored = (array) get_option( ErrorPatterns::OPTION_KEY, [] );
		self::assertSame( [ 'other' ], array_keys( $stored ), 'The change removes the old entry and keeps every other one.' );
	}

	// ------------------------------------------------------------ fixtures

	/** @return array<string, mixed> */
	private static function gutenberg_section(): array {
		$GLOBALS['stonewright_test_user_can_callback'] = static fn( string $cap, mixed ...$args ): bool => in_array( $cap, [ 'edit_posts', 'read_post', 'edit_post' ], true );
		$result = ( new SectionReuseExtract() )->execute( [ 'post_id' => self::SOURCE, 'locator' => [ 'kind' => 'block', 'path' => [ 0 ] ] ] );
		$GLOBALS['stonewright_test_user_can_callback'] = null;
		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() : '' );

		return $result['section'];
	}

	/** @return array<string, mixed> */
	private static function elementor_section( int $post_id, string $element_id ): array {
		$GLOBALS['stonewright_test_user_can_callback'] = static fn( string $cap, mixed ...$args ): bool => in_array( $cap, [ 'edit_posts', 'read_post', 'edit_post' ], true );
		$result = ( new SectionReuseExtract() )->execute( [ 'post_id' => $post_id, 'locator' => [ 'kind' => 'element', 'id' => $element_id ] ] );
		$GLOBALS['stonewright_test_user_can_callback'] = null;
		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() : '' );

		return $result['section'];
	}
}
