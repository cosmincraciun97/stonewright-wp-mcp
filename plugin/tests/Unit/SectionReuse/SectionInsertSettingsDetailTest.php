<?php
/**
 * Reusing a V3 section whose settings the live schema refuses: the detail the refusal carries to the caller and
 * the audit row, and the explicit `drop_settings` approval that copies the section without exactly those settings.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\SectionReuse;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\ElementorV3\BatchMutate;
use Stonewright\WpMcp\Abilities\SectionReuse\SectionReuseExtract;
use Stonewright\WpMcp\Elementor\ElementorCustomCssGate;
use Stonewright\WpMcp\Elementor\Schema\WidgetSchemaRepository;
use Stonewright\WpMcp\SectionReuse\Builder;
use Stonewright\WpMcp\SectionReuse\ElementorSectionInserter;
use Stonewright\WpMcp\SectionReuse\PortableSection;
use Stonewright\WpMcp\SectionReuse\ReferenceCatalog;
use Stonewright\WpMcp\Security\ConfirmationToken;
use Stonewright\WpMcp\Security\ErrorPatterns;
use Stonewright\WpMcp\Security\IncidentStore;
use Stonewright\WpMcp\Security\RemediationHints;
use Stonewright\WpMcp\Support\ElementorData;
use Stonewright\WpMcp\Support\ErrorEnvelope;

require_once __DIR__ . '/SectionFixtures.php';
require_once __DIR__ . '/ReuseWidgetRegistry.php';

/**
 * @covers \Stonewright\WpMcp\Abilities\ElementorV3\BatchMutate
 * @covers \Stonewright\WpMcp\SectionReuse\ElementorSectionInserter
 * @covers \Stonewright\WpMcp\Support\ErrorEnvelope
 * @covers \Stonewright\WpMcp\Security\RemediationHints
 */
final class SectionInsertSettingsDetailTest extends TestCase {

	private const TARGET  = 501;
	private const SOURCE  = 10;
	private const REFUSED = 'stonewright_section_settings_not_reusable';
	private const MISMATCH = 'stonewright_section_drop_settings_mismatch';

	protected function setUp(): void {
		WidgetSchemaRepository::reset_request_cache();
		IncidentStore::reset_for_tests();
		ElementorCustomCssGate::reset();
		ReferenceCatalog::set_provider( static fn( string $type, string $id ): ?bool => true );
		$GLOBALS['stonewright_test_options']           = [ 'stonewright_mode' => 'development' ];
		$GLOBALS['stonewright_test_post_meta_calls']   = [];
		$GLOBALS['stonewright_test_wpdb_inserts']      = [];
		$GLOBALS['stonewright_test_transients']        = [];
		$GLOBALS['stonewright_test_user_caps']         = [ 'edit_post' => true, 'read_post' => true, 'edit_posts' => true, 'read' => true ];
		$GLOBALS['stonewright_test_user_can_callback'] = null;
		$GLOBALS['stonewright_test_user_logged_in']    = true;
		$GLOBALS['stonewright_test_current_user_id']   = 1;
		self::seed( self::TARGET, [ self::root() ] );
		self::seed( self::SOURCE, [ SectionFixtures::v3_hero() ] );
	}

	protected function tearDown(): void {
		ReuseWidgetRegistry::restore();
		WidgetSchemaRepository::reset_request_cache();
		ElementorCustomCssGate::reset();
		ReferenceCatalog::set_provider( null );
		IncidentStore::reset_for_tests();
		$GLOBALS['stonewright_test_posts']             = [];
		$GLOBALS['stonewright_test_post_meta_calls']   = [];
		$GLOBALS['stonewright_test_wpdb_inserts']      = [];
		$GLOBALS['stonewright_test_options']           = [];
		$GLOBALS['stonewright_test_user_caps']         = [];
		$GLOBALS['stonewright_test_user_can_callback'] = null;
		$GLOBALS['stonewright_test_user_logged_in']    = false;
		$GLOBALS['stonewright_test_current_user_id']   = 0;
		$GLOBALS['stonewright_test_transients']        = [];
	}

	/** @return array<string, mixed> */
	private static function root(): array {
		return [ 'id' => 'root', 'elType' => 'container', 'isInner' => false, 'settings' => [ 'container_type' => 'flex' ], 'elements' => [] ];
	}

	/** @param list<array<string, mixed>> $tree */
	private static function seed( int $id, array $tree ): void {
		$GLOBALS['stonewright_test_posts'][ $id ] = SectionFixtures::post( $id, 'page', self::SOURCE === $id ? 'publish' : 'draft', 'Page ' . $id, '', SectionFixtures::elementor_meta( $tree, defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : '3.0.0' ) );
	}

	/**
	 * Seeds the source with the hero section after the callback changed it. In the hero, placeholder ph-1 is the
	 * outer container, ph-2 the inner container, ph-3 the heading, ph-4 the text editor, ph-5 the button, ph-6 the image.
	 *
	 * @param callable(array<string, mixed>):array<string, mixed> $change
	 */
	private static function seed_hero( callable $change ): void {
		self::seed( self::SOURCE, [ $change( SectionFixtures::v3_hero() ) ] );
	}

	/** @param array<string, mixed> $hero @param array<string, mixed> $settings @return array<string, mixed> */
	private static function with_settings( array $hero, string $at, array $settings ): array {
		$map = [
			'ph-1' => [],
			'ph-2' => [ 'elements', 0 ],
			'ph-3' => [ 'elements', 0, 'elements', 0 ],
			'ph-4' => [ 'elements', 0, 'elements', 1 ],
			'ph-5' => [ 'elements', 0, 'elements', 2 ],
			'ph-6' => [ 'elements', 1 ],
		];
		$ref = &$hero;
		foreach ( $map[ $at ] as $step ) {
			$ref = &$ref[ $step ];
		}
		$ref['settings'] = array_merge( $ref['settings'], $settings );
		unset( $ref );

		return $hero;
	}

	/** @return array<string, mixed> The portable section the extract ability returns for the source. */
	private static function section(): array {
		$tree = ElementorData::read( self::SOURCE );
		$GLOBALS['stonewright_test_user_can_callback'] = static fn( string $cap, mixed ...$args ): bool => in_array( $cap, [ 'edit_posts', 'read_post', 'edit_post' ], true );
		$result = ( new SectionReuseExtract() )->execute( [ 'post_id' => self::SOURCE, 'locator' => [ 'kind' => 'element', 'id' => (string) $tree[0]['id'] ] ] );
		$GLOBALS['stonewright_test_user_can_callback'] = null;
		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_code() . ': ' . $result->get_error_message() : '' );

		return $result['section'];
	}

	/** @param array<string, mixed> $extra @return array<string, mixed> */
	private static function insert_op( array $extra = [] ): array {
		return array_merge( [ 'action' => 'insert_section', 'op_id' => 'feat', 'parent_id' => 'root', 'section' => self::section() ], $extra );
	}

	/** @param list<array<string, mixed>> $operations @param array<string, mixed> $extra @return array<string, mixed>|\WP_Error */
	private static function batch( array $operations, bool $dry_run = false, array $extra = [] ): array|\WP_Error {
		return ( new BatchMutate() )->execute( array_merge( [ 'post_id' => self::TARGET, 'dry_run' => $dry_run, 'operations' => $operations ], $extra ) );
	}

	/** @return array<string, mixed> The data of the refusal the first failed operation returned. */
	private static function inner( \WP_Error $result ): array {
		foreach ( (array) ( $result->get_error_data()['items'] ?? [] ) as $item ) {
			if ( empty( $item['ok'] ) ) {
				return (array) $item['error'];
			}
		}
		self::fail( 'No failed operation in ' . $result->get_error_code() );
	}

	private static function target_writes(): int {
		return count( array_filter( $GLOBALS['stonewright_test_post_meta_calls'], static fn( array $call ): bool => self::TARGET === $call['post_id'] && '_elementor_data' === $call['meta_key'] ) );
	}

	/** @return array<string, mixed> */
	private static function last_audit_details(): array {
		$rows = $GLOBALS['stonewright_test_wpdb_inserts'];
		self::assertNotEmpty( $rows );
		$details = json_decode( (string) end( $rows )['data']['redacted_details'], true );
		self::assertIsArray( $details );

		return $details;
	}

	/** @return array<string, array<string, mixed>> The target document by element id. */
	private static function target_elements(): array {
		return ElementorData::flatten( ElementorData::read( self::TARGET ) );
	}

	/** @param array<string, mixed> $result @return array<string, mixed> The inserted element by the placeholder it had in the section. */
	private static function copied( array $result, string $placeholder ): array {
		return self::target_elements()[ $result['refs'][ 'feat.' . $placeholder ] ];
	}

	// ---------------------------------------------------------------- the message and the data name the settings

	public function test_the_message_names_the_rejected_keys_with_the_element_and_never_their_values(): void {
		self::seed_hero(
			static function ( array $hero ): array {
				$hero = self::with_settings( $hero, 'ph-3', [ 'acme_a' => 'secret-value-1', 'acme_b' => [ 'k' => 'secret-value-2' ] ] );
				return self::with_settings( $hero, 'ph-1', [ 'acme_root' => 'secret-value-3' ] );
			}
		);

		$result = self::batch( [ self::insert_op() ], true );

		self::assertInstanceOf( \WP_Error::class, $result );
		$message = self::inner( $result )['message'];
		foreach ( [ 'acme_a', 'acme_b', 'acme_root', 'ph-3', 'heading', 'ph-1', 'container' ] as $named ) {
			self::assertStringContainsString( $named, $message );
		}
		self::assertStringNotContainsString( 'secret-value', $message );
		self::assertStringNotContainsString( 'secret-value', $result->get_error_message() );
		self::assertStringContainsString( $message, $result->get_error_message(), 'The batch message carries the detail.' );
	}

	public function test_the_message_names_at_most_five_keys_and_says_how_many_more(): void {
		self::seed_hero(
			static fn( array $hero ): array => self::with_settings( $hero, 'ph-3', array_combine( array_map( static fn( int $n ): string => 'acme_k' . $n, range( 1, 7 ) ), range( 1, 7 ) ) )
		);

		$result  = self::batch( [ self::insert_op() ], true );
		$message = self::inner( $result )['message'];

		self::assertSame( 5, preg_match_all( '/acme_k\d/', $message ), $message );
		self::assertStringContainsString( 'and 2 more', $message );
		self::assertCount( 7, self::inner( $result )['data']['violations'], 'The data lists every one of them.' );
	}

	public function test_the_data_lists_every_violation_of_every_element_with_path_code_and_control_type(): void {
		ReuseWidgetRegistry::install( [], true );
		self::seed_hero(
			static function ( array $hero ): array {
				$hero = self::with_settings( $hero, 'ph-3', [ 'acme_a' => 1, 'align' => 'sideways' ] );
				$hero = self::with_settings( $hero, 'ph-5', [ 'acme_btn' => 1 ] );
				return self::with_settings( $hero, 'ph-1', [ 'acme_root' => 1 ] );
			}
		);

		$data = self::inner( self::batch( [ self::insert_op() ], true ) )['data'];

		$by_path = [];
		foreach ( $data['violations'] as $violation ) {
			$by_path[ $violation['element'] . ' ' . $violation['path'] ] = $violation;
		}
		self::assertSame( [ 'ph-1 settings.acme_root', 'ph-3 settings.acme_a', 'ph-3 settings.align', 'ph-5 settings.acme_btn' ], self::sorted( array_keys( $by_path ) ) );
		self::assertSame( 'container', $by_path['ph-1 settings.acme_root']['element_type'] );
		self::assertSame( 'heading', $by_path['ph-3 settings.acme_a']['element_type'] );
		self::assertSame( 'button', $by_path['ph-5 settings.acme_btn']['element_type'] );
		self::assertSame( 'unknown_setting_preserved', $by_path['ph-3 settings.acme_a']['code'] );
		self::assertArrayNotHasKey( 'control_type', $by_path['ph-3 settings.acme_a'], 'An unknown key has no control.' );
		self::assertSame( 'invalid_option', $by_path['ph-3 settings.align']['code'] );
		self::assertSame( 'choose', $by_path['ph-3 settings.align']['control_type'] );
		self::assertSame( 4, $data['violations_total'] );
		self::assertSame( 'ph-1', $data['element'], 'The first element that holds one is still named at the top.' );
		self::assertStringNotContainsString( 'sideways', (string) wp_json_encode( $data['violations'] ), 'Names only, never values.' );
	}

	/** @param list<string> $values @return list<string> */
	private static function sorted( array $values ): array {
		sort( $values );

		return $values;
	}

	public function test_the_data_lists_at_most_twenty_five_violations_and_counts_all(): void {
		self::seed_hero(
			static fn( array $hero ): array => self::with_settings( $hero, 'ph-3', array_combine( array_map( static fn( int $n ): string => 'acme_k' . $n, range( 1, 30 ) ), range( 1, 30 ) ) )
		);

		$data = self::inner( self::batch( [ self::insert_op() ], true ) )['data'];

		self::assertCount( 25, $data['violations'] );
		self::assertSame( 30, $data['violations_total'] );
		self::assertFalse( $data['drop_settings_available'], 'A list that does not fit cannot be matched exactly, so it is not offered.' );
		self::assertArrayNotHasKey( 'drop_settings_proposal', $data );
	}

	public function test_a_class_and_custom_css_the_gate_would_refuse_are_named_instead_of_an_empty_list(): void {
		self::seed_hero(
			static function ( array $hero ): array {
				$hero = self::with_settings( $hero, 'ph-1', [ 'css_classes' => 'brand-band', 'custom_css' => '.x{color:red}' ] );
				return self::with_settings( $hero, 'ph-3', [ 'acme_a' => 1 ] );
			}
		);
		$payload = PortableSection::validate( self::section(), Builder::ELEMENTOR_V3 );
		self::assertIsArray( $payload );

		$result = ElementorSectionInserter::instantiate( $payload, Builder::ELEMENTOR_V3, [], [] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( self::REFUSED, $result->get_error_code() );
		$codes = [];
		foreach ( $result->get_error_data()['violations'] as $violation ) {
			$codes[ $violation['element'] . ' ' . $violation['path'] ] = $violation['code'];
		}
		self::assertSame( 'css_classes_not_approved', $codes['ph-1 settings.css_classes'] );
		self::assertSame( 'custom_css_needs_approval', $codes['ph-1 settings.custom_css'] );
		self::assertSame( 'unknown_setting_preserved', $codes['ph-3 settings.acme_a'] );
		self::assertStringNotContainsString( 'brand-band', $result->get_error_message() );
		self::assertStringNotContainsString( 'color:red', $result->get_error_message() );
	}

	// ---------------------------------------------------------------- the batch answer and the audit row keep it

	public function test_the_batch_answer_keeps_the_rejected_settings_in_the_data_and_in_the_message_an_mcp_client_reads(): void {
		self::seed_hero( static fn( array $hero ): array => self::with_settings( $hero, 'ph-3', [ 'acme_a' => 'secret-value-1' ] ) );

		foreach ( [ true, false ] as $dry_run ) {
			$result = self::batch( [ self::insert_op() ], $dry_run );

			self::assertInstanceOf( \WP_Error::class, $result );
			$data = $result->get_error_data();
			self::assertSame( 'ph-3', $data['rejected_settings'][0]['element'] );
			self::assertSame( 'settings.acme_a', $data['rejected_settings'][0]['path'] );
			self::assertSame( [ [ 'element' => 'ph-3', 'setting' => 'acme_a' ] ], $data['drop_settings_proposal'] );

			$flattened = ErrorEnvelope::with_agent_visible_payload( $result )->get_error_message();
			self::assertStringContainsString( '"rejected_settings"', $flattened );
			self::assertStringContainsString( 'settings.acme_a', $flattened );
			self::assertStringContainsString( '"drop_settings_proposal"', $flattened );
			self::assertStringNotContainsString( 'secret-value', $flattened );
		}
		self::assertSame( 0, self::target_writes() );
	}

	public function test_the_audit_row_keeps_the_key_paths_names_only(): void {
		self::seed_hero(
			static function ( array $hero ): array {
				$hero = self::with_settings( $hero, 'ph-3', [ 'acme_a' => 'secret-value-1', 'acme_b' => 'secret-value-2' ] );
				return self::with_settings( $hero, 'ph-1', [ 'acme_root' => 'secret-value-3' ] );
			}
		);

		self::batch( [ self::insert_op() ], true );

		$details = self::last_audit_details();
		self::assertSame( 'ph-1 acme_root, ph-3 acme_a, ph-3 acme_b', $details['rejected_settings'] );
		self::assertSame( self::REFUSED, $details['root_error_code'] );
		self::assertStringNotContainsString( 'secret-value', (string) wp_json_encode( $details ) );
	}

	public function test_the_repair_text_names_three_ways_out_and_does_not_send_the_agent_to_reread_ids(): void {
		$ability = 'stonewright/elementor-v3-batch-mutate';
		$hints   = [
			RemediationHints::for_code( self::REFUSED, $ability, 'stonewright_batch_operation_failed' ),
			RemediationHints::for_code( self::REFUSED, $ability ),
			RemediationHints::for_code( self::MISMATCH, $ability, 'stonewright_batch_operation_failed' ),
		];
		foreach ( $hints as $hint ) {
			self::assertStringContainsString( 'another section', $hint );
			self::assertStringContainsString( 'activate', $hint );
			self::assertStringContainsString( 'drop_settings', $hint );
			self::assertStringContainsString( 'user', $hint );
			self::assertStringNotContainsStringIgnoringCase( 're-read', $hint );
			self::assertStringNotContainsStringIgnoringCase( 'element id', $hint );
			self::assertStringNotContainsString( 'schema_requests', $hint );
		}

		self::seed_hero( static fn( array $hero ): array => self::with_settings( $hero, 'ph-3', [ 'acme_a' => 1 ] ) );
		$result = self::batch( [ self::insert_op() ], true );
		self::assertStringContainsString( $hints[0], $result->get_error_data()['repair'] );
	}

	// ---------------------------------------------------------------- the same call again

	public function test_the_same_call_again_gets_the_same_detail_and_the_same_change_set(): void {
		self::seed_hero( static fn( array $hero ): array => self::with_settings( $hero, 'ph-3', [ 'acme_a' => 1 ] ) );

		foreach ( [ true, false ] as $dry_run ) {
			$first  = self::batch( [ self::insert_op() ], $dry_run );
			$second = self::batch( [ self::insert_op() ], $dry_run );

			self::assertInstanceOf( \WP_Error::class, $first );
			self::assertInstanceOf( \WP_Error::class, $second );
			self::assertSame( $first->get_error_message(), $second->get_error_message() );
			self::assertSame( $first->get_error_data()['rejected_settings'], $second->get_error_data()['rejected_settings'] );
			self::assertSame( $first->get_error_data()['drop_settings_proposal'], $second->get_error_data()['drop_settings_proposal'] );
			self::assertSame( $first->get_error_data()['write_receipt']['change_set_id'], $second->get_error_data()['write_receipt']['change_set_id'] );
			self::assertSame(
				ErrorEnvelope::with_agent_visible_payload( $first )->get_error_message(),
				ErrorEnvelope::with_agent_visible_payload( $second )->get_error_message()
			);
		}
	}

	public function test_a_repeat_failure_wrapper_keeps_the_detail_and_gives_the_guidance_of_the_cause(): void {
		self::seed_hero( static fn( array $hero ): array => self::with_settings( $hero, 'ph-3', [ 'acme_a' => 1 ] ) );
		$ability = 'stonewright/elementor-v3-batch-mutate';
		$failed  = ErrorEnvelope::with_agent_visible_payload( self::batch( [ self::insert_op() ] ) );
		$args    = [ 'error_code' => $failed->get_error_code(), 'message' => $failed->get_error_message() ];
		ErrorPatterns::observe( $ability, 'error', $args );
		ErrorPatterns::observe( $ability, 'error', $args );

		$repeat = ErrorPatterns::escalate_error( $ability, $failed );

		self::assertStringContainsString( 'STOP: this exact error occurred 2 times', $repeat->get_error_message() );
		self::assertStringContainsString( 'acme_a', $repeat->get_error_message(), 'The settings are still named.' );
		self::assertStringContainsString( '"rejected_settings"', $repeat->get_error_message() );
		self::assertSame( RemediationHints::for_code( self::REFUSED ), $repeat->get_error_data()['repair'] );
		self::assertSame( $failed->get_error_data()['rejected_settings'], $repeat->get_error_data()['rejected_settings'] );
	}

	// ---------------------------------------------------------------- drop_settings

	public function test_without_the_option_nothing_is_stripped_and_nothing_is_written(): void {
		self::seed_hero( static fn( array $hero ): array => self::with_settings( $hero, 'ph-3', [ 'acme_a' => 1 ] ) );

		$result = self::batch( [ self::insert_op() ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( self::REFUSED, self::inner( $result )['code'] );
		self::assertSame( 0, self::target_writes() );
	}

	public function test_the_exact_list_copies_the_section_without_exactly_those_settings(): void {
		self::seed_hero(
			static function ( array $hero ): array {
				$hero = self::with_settings( $hero, 'ph-3', [ 'acme_a' => 'secret-value-1', 'title_color' => '#123456' ] );
				return self::with_settings( $hero, 'ph-1', [ 'acme_root' => 'secret-value-3' ] );
			}
		);
		$drop = [ [ 'element' => 'ph-1', 'setting' => 'acme_root' ], [ 'element' => 'ph-3', 'setting' => 'acme_a' ] ];

		$result = self::batch( [ self::insert_op( [ 'drop_settings' => $drop ] ) ] );

		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() . wp_json_encode( $result->get_error_data() ) : '' );
		$heading = self::copied( $result, 'ph-3' );
		self::assertArrayNotHasKey( 'acme_a', $heading['settings'] );
		self::assertSame( '#123456', $heading['settings']['title_color'], 'Everything that is not listed is kept exactly.' );
		self::assertSame( 'Build something solid', $heading['settings']['title'] );
		self::assertArrayNotHasKey( 'acme_root', self::copied( $result, 'ph-1' )['settings'] );
		self::assertSame( 'full', self::copied( $result, 'ph-1' )['settings']['content_width'] );
		self::assertSame( 1, self::target_writes() );

		// The source section is never touched.
		self::assertSame( 'secret-value-1', ElementorData::flatten( ElementorData::read( self::SOURCE ) )['h000003']['settings']['acme_a'] );

		$removed = $result['items'][0]['removed_settings'];
		self::assertSame( [ 'ph-1 acme_root', 'ph-3 acme_a' ], array_map( static fn( array $row ): string => $row['element'] . ' ' . $row['setting'], $removed ) );
		self::assertSame( 'heading', $removed[1]['element_type'] );
		self::assertSame( 'unknown_setting_preserved', $removed[1]['code'] );
		self::assertSame( [ 0, 0 ], array_column( $result['removed_settings'], 'operation' ), 'The answer lists them once more at the top, with the operation.' );
		self::assertSame( [ 'ph-1 acme_root', 'ph-3 acme_a' ], array_values( $result['write_receipt']['removed_settings'] ) );
		self::assertSame( 'settings_removed', $result['items'][0]['warnings'][0]['code'] );
		self::assertStringNotContainsString( 'secret-value', (string) wp_json_encode( $result ) );
		self::assertSame( 'ph-1 acme_root, ph-3 acme_a', self::last_audit_details()['removed_settings'] );
	}

	public function test_a_dry_run_plans_the_removal_and_writes_nothing(): void {
		self::seed_hero( static fn( array $hero ): array => self::with_settings( $hero, 'ph-3', [ 'acme_a' => 1 ] ) );

		$result = self::batch( [ self::insert_op( [ 'drop_settings' => [ [ 'element' => 'ph-3', 'setting' => 'acme_a' ] ] ] ) ], true );

		self::assertIsArray( $result );
		self::assertSame( 'acme_a', $result['items'][0]['removed_settings'][0]['setting'] );
		self::assertSame( 0, self::target_writes() );
	}

	public function test_a_list_for_other_settings_than_the_rejected_ones_is_refused(): void {
		self::seed_hero( static fn( array $hero ): array => self::with_settings( $hero, 'ph-3', [ 'acme_a' => 1 ] ) );

		$result = self::batch( [ self::insert_op( [ 'drop_settings' => [ [ 'element' => 'ph-3', 'setting' => 'acme_other' ] ] ] ) ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		$error = self::inner( $result );
		self::assertSame( self::MISMATCH, $error['code'] );
		self::assertSame( [ [ 'element' => 'ph-3', 'setting' => 'acme_a' ] ], $error['data']['drop_settings_missing'] );
		self::assertSame( [ [ 'element' => 'ph-3', 'setting' => 'acme_other' ] ], $error['data']['drop_settings_unexpected'] );
		self::assertSame( [ [ 'element' => 'ph-3', 'setting' => 'acme_a' ] ], $error['data']['drop_settings_proposal'] );
		self::assertSame( 0, self::target_writes() );
	}

	public function test_a_partial_list_is_refused_and_removes_nothing(): void {
		self::seed_hero(
			static function ( array $hero ): array {
				$hero = self::with_settings( $hero, 'ph-3', [ 'acme_a' => 1 ] );
				return self::with_settings( $hero, 'ph-5', [ 'acme_btn' => 1 ] );
			}
		);

		$result = self::batch( [ self::insert_op( [ 'drop_settings' => [ [ 'element' => 'ph-3', 'setting' => 'acme_a' ] ] ] ) ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		$error = self::inner( $result );
		self::assertSame( self::MISMATCH, $error['code'] );
		self::assertSame( [ [ 'element' => 'ph-5', 'setting' => 'acme_btn' ] ], $error['data']['drop_settings_missing'] );
		self::assertSame( [], $error['data']['drop_settings_unexpected'] );
		self::assertSame( 0, self::target_writes() );
	}

	public function test_a_list_with_an_extra_entry_is_refused_and_a_valid_setting_is_never_removed(): void {
		self::seed_hero( static fn( array $hero ): array => self::with_settings( $hero, 'ph-3', [ 'acme_a' => 1 ] ) );

		$result = self::batch( [ self::insert_op( [ 'drop_settings' => [ [ 'element' => 'ph-3', 'setting' => 'acme_a' ], [ 'element' => 'ph-3', 'setting' => 'title' ] ] ] ) ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		$error = self::inner( $result );
		self::assertSame( self::MISMATCH, $error['code'] );
		self::assertSame( [], $error['data']['drop_settings_missing'] );
		self::assertSame( [ [ 'element' => 'ph-3', 'setting' => 'title' ] ], $error['data']['drop_settings_unexpected'] );
		self::assertSame( 0, self::target_writes() );
	}

	public function test_a_list_for_a_section_the_schema_accepts_is_refused_too(): void {
		$result = self::batch( [ self::insert_op( [ 'drop_settings' => [ [ 'element' => 'ph-3', 'setting' => 'title' ] ] ] ) ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( self::MISMATCH, self::inner( $result )['code'] );
		self::assertSame( 0, self::target_writes() );
	}

	public function test_an_empty_list_is_no_approval(): void {
		self::seed_hero( static fn( array $hero ): array => self::with_settings( $hero, 'ph-3', [ 'acme_a' => 1 ] ) );

		$result = self::batch( [ self::insert_op( [ 'drop_settings' => [] ] ) ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( self::REFUSED, self::inner( $result )['code'] );
	}

	public function test_a_list_that_is_not_a_list_of_element_and_setting_pairs_is_refused(): void {
		foreach ( [ 'acme_a', [ 'acme_a' ], [ [ 'element' => 'ph-3' ] ], [ [ 'setting' => 'acme_a' ] ], [ [ 'element' => 'ph-3', 'setting' => [ 'acme_a' ] ] ] ] as $bad ) {
			$result = self::batch( [ self::insert_op( [ 'drop_settings' => $bad ] ) ], true );

			self::assertInstanceOf( \WP_Error::class, $result );
			self::assertSame( 'stonewright_section_drop_settings_invalid', self::inner( $result )['code'] );
		}
	}

	public function test_a_value_the_live_control_refuses_is_dropped_by_its_key(): void {
		ReuseWidgetRegistry::install( [], true );
		self::seed_hero( static fn( array $hero ): array => self::with_settings( $hero, 'ph-3', [ 'align' => 'sideways' ] ) );

		$plan = self::inner( self::batch( [ self::insert_op() ], true ) )['data'];
		self::assertSame( [ [ 'element' => 'ph-3', 'setting' => 'align' ] ], $plan['drop_settings_proposal'] );

		$result = self::batch( [ self::insert_op( [ 'drop_settings' => $plan['drop_settings_proposal'] ] ) ] );

		self::assertIsArray( $result );
		self::assertArrayNotHasKey( 'align', self::copied( $result, 'ph-3' )['settings'] );
	}

	public function test_the_drop_list_is_complete_when_one_removal_leaves_another_setting_without_its_condition(): void {
		// `boxed_width` only applies while `content_width` is boxed; with the invalid `content_width` gone it is refused too.
		self::seed_hero( static fn( array $hero ): array => self::with_settings( $hero, 'ph-1', [ 'content_width' => 'sideways', 'boxed_width' => [ 'size' => 600, 'unit' => 'px' ] ] ) );

		$plan = self::inner( self::batch( [ self::insert_op() ], true ) )['data'];

		self::assertSame( [ 'boxed_width', 'content_width' ], self::sorted( array_column( $plan['drop_settings_proposal'], 'setting' ) ) );
		$result = self::batch( [ self::insert_op( [ 'drop_settings' => $plan['drop_settings_proposal'] ] ) ] );
		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() : '' );
		self::assertArrayNotHasKey( 'content_width', self::copied( $result, 'ph-1' )['settings'] );
		self::assertSame( 'flex', self::copied( $result, 'ph-1' )['settings']['container_type'] );
	}

	public function test_a_binding_to_a_control_the_widget_does_not_have_is_dropped_alone_and_other_bindings_stay(): void {
		self::seed_hero(
			static fn( array $hero ): array => self::with_settings( $hero, 'ph-3', [ '__globals__' => [ 'acme_a' => 'globals/colors?id=primary', 'title_color' => 'globals/colors?id=secondary' ] ] )
		);

		$plan = self::inner( self::batch( [ self::insert_op() ], true ) )['data'];
		self::assertSame( [ [ 'element' => 'ph-3', 'setting' => '__globals__.acme_a' ] ], $plan['drop_settings_proposal'] );

		$result = self::batch( [ self::insert_op( [ 'drop_settings' => $plan['drop_settings_proposal'] ] ) ] );

		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() : '' );
		self::assertSame( [ 'title_color' => 'globals/colors?id=secondary' ], self::copied( $result, 'ph-3' )['settings']['__globals__'] );
	}

	public function test_the_removal_is_listed_in_the_replay_of_the_same_request(): void {
		self::seed_hero( static fn( array $hero ): array => self::with_settings( $hero, 'ph-3', [ 'acme_a' => 1 ] ) );
		$operations = [ self::insert_op( [ 'drop_settings' => [ [ 'element' => 'ph-3', 'setting' => 'acme_a' ] ] ] ) ];

		$first  = self::batch( $operations, false, [ 'idempotency_key' => 'same-call' ] );
		$replay = self::batch( $operations, false, [ 'idempotency_key' => 'same-call' ] );

		self::assertIsArray( $first );
		self::assertIsArray( $replay );
		self::assertTrue( $replay['idempotent_replay'] );
		self::assertSame( $first['items'][0]['removed_settings'], $replay['items'][0]['removed_settings'] );
		self::assertSame( 1, self::target_writes() );
	}

	// ---------------------------------------------------------------- the confirmation token of production-safe mode

	public function test_in_production_safe_mode_the_token_covers_the_drop_list(): void {
		$GLOBALS['stonewright_test_options']['stonewright_mode'] = 'production-safe';
		self::seed_hero( static fn( array $hero ): array => self::with_settings( $hero, 'ph-3', [ 'acme_a' => 1 ] ) );
		$drop    = [ [ 'element' => 'ph-3', 'setting' => 'acme_a' ] ];
		$with    = [ 'post_id' => self::TARGET, 'operations' => [ self::insert_op( [ 'drop_settings' => $drop ] ) ] ];
		$without = [ 'post_id' => self::TARGET, 'operations' => [ self::insert_op() ] ];
		$ability = 'stonewright/elementor-v3-batch-mutate';

		// A token issued for the insert without the option does not approve the insert with it.
		$refused = ( new BatchMutate() )->execute( $with + [ 'confirmation_token' => ConfirmationToken::issue( $ability, $without ) ] );
		self::assertInstanceOf( \WP_Error::class, $refused );
		self::assertSame( 'stonewright_confirmation_args_mismatch', $refused->get_error_code() );
		self::assertSame( 0, self::target_writes() );

		// No token at all is refused as before.
		$missing = ( new BatchMutate() )->execute( $with );
		self::assertInstanceOf( \WP_Error::class, $missing );
		self::assertSame( 'stonewright_confirmation_required', $missing->get_error_code() );

		// A token issued for another list does not approve this one.
		$other   = [ 'post_id' => self::TARGET, 'operations' => [ self::insert_op( [ 'drop_settings' => [ [ 'element' => 'ph-3', 'setting' => 'acme_b' ] ] ] ) ] ];
		$refused = ( new BatchMutate() )->execute( $with + [ 'confirmation_token' => ConfirmationToken::issue( $ability, $other ) ] );
		self::assertInstanceOf( \WP_Error::class, $refused );
		self::assertSame( 0, self::target_writes() );

		// The token issued for exactly this request writes.
		$written = ( new BatchMutate() )->execute( $with + [ 'confirmation_token' => ConfirmationToken::issue( $ability, $with ) ] );
		self::assertIsArray( $written, $written instanceof \WP_Error ? $written->get_error_message() : '' );
		self::assertSame( 1, self::target_writes() );
	}

	// ---------------------------------------------------------------- the custom code gate

	public function test_a_class_the_site_has_not_approved_is_dropped_only_when_it_is_listed(): void {
		self::seed_hero( static fn( array $hero ): array => self::with_settings( $hero, 'ph-1', [ 'css_classes' => 'brand-band' ] ) );

		$plain = self::batch( [ self::insert_op() ] );
		self::assertInstanceOf( \WP_Error::class, $plain, 'Without the option the gate refuses as before.' );
		self::assertSame( 'stonewright_css_classes_not_approved', $plain->get_error_code() );

		$result = self::batch( [ self::insert_op( [ 'drop_settings' => [ [ 'element' => 'ph-1', 'setting' => 'css_classes' ] ] ] ) ] );

		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() : '' );
		self::assertArrayNotHasKey( 'css_classes', self::copied( $result, 'ph-1' )['settings'] );
		self::assertSame( 'css_classes_not_approved', $result['items'][0]['removed_settings'][0]['code'] );
	}

	public function test_a_class_the_site_approved_is_kept_and_listing_it_is_a_mismatch(): void {
		$GLOBALS['stonewright_test_options']['stonewright_approved_css_classes'] = [ 'brand-band' ];
		self::seed_hero( static fn( array $hero ): array => self::with_settings( $hero, 'ph-1', [ 'css_classes' => 'brand-band' ] ) );

		$kept = self::batch( [ self::insert_op() ] );
		self::assertIsArray( $kept, $kept instanceof \WP_Error ? $kept->get_error_message() : '' );
		self::assertSame( 'brand-band', self::copied( $kept, 'ph-1' )['settings']['css_classes'] );

		$listed = self::batch( [ self::insert_op( [ 'drop_settings' => [ [ 'element' => 'ph-1', 'setting' => 'css_classes' ] ] ] ) ], true );
		self::assertInstanceOf( \WP_Error::class, $listed );
		self::assertSame( self::MISMATCH, self::inner( $listed )['code'] );
	}

	public function test_custom_css_that_needs_the_human_grant_is_dropped_only_when_it_is_listed(): void {
		self::seed_hero( static fn( array $hero ): array => self::with_settings( $hero, 'ph-1', [ 'custom_css' => '.x{color:red}' ] ) );

		$plain = self::batch( [ self::insert_op() ], true );
		self::assertInstanceOf( \WP_Error::class, $plain );
		self::assertSame( 'stonewright_custom_code_approval_required', $plain->get_error_code() );

		$result = self::batch( [ self::insert_op( [ 'drop_settings' => [ [ 'element' => 'ph-1', 'setting' => 'custom_css' ] ] ] ) ] );

		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() : '' );
		self::assertArrayNotHasKey( 'custom_css', self::copied( $result, 'ph-1' )['settings'] );
		self::assertStringNotContainsString( 'color:red', (string) wp_json_encode( $result['items'][0]['removed_settings'] ) );
	}

	public function test_custom_css_in_another_operation_is_still_gated_when_a_section_lists_its_own(): void {
		self::seed_hero( static fn( array $hero ): array => self::with_settings( $hero, 'ph-1', [ 'custom_css' => '.x{color:red}' ] ) );
		$operations = [
			self::insert_op( [ 'drop_settings' => [ [ 'element' => 'ph-1', 'setting' => 'custom_css' ] ] ] ),
			[ 'action' => 'add_container', 'parent_id' => 'root', 'settings' => [ 'custom_css' => '.y{color:blue}' ] ],
		];

		$result = self::batch( $operations, true );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_custom_code_approval_required', $result->get_error_code() );
	}
}
