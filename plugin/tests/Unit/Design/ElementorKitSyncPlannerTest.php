<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Design;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Design\Direction\DirectionContract;
use Stonewright\WpMcp\Design\Direction\ElementorKitSyncPlanner;

/**
 * Planning tests for direction to Elementor kit synchronization.
 *
 * The planner is the read-only half of sync: it compares a stored contract with
 * the live kit and says exactly what would change. Everything the apply step is
 * allowed to do comes from this list, so these tests pin the properties that
 * make the list safe to hand to a writer:
 *
 * - The same inputs always produce the same plan and the same base hash, because
 *   the base hash is what detects a kit that moved under us.
 * - Only kit-supported groups become operations. Contract sections Elementor has
 *   no global for are reported, never forced into a field that does not exist.
 * - Identical values produce no operation at all, so applying a synced direction
 *   is a no-op instead of a rewrite.
 * - A value the kit cannot store blocks the plan rather than being coerced.
 *
 * @covers \Stonewright\WpMcp\Design\Direction\ElementorKitSyncPlanner
 */
final class ElementorKitSyncPlannerTest extends TestCase {

	// -------------------------------------------------------------------------
	// Determinism and staleness.
	// -------------------------------------------------------------------------

	public function test_identical_inputs_produce_an_identical_plan(): void {
		$first  = ElementorKitSyncPlanner::plan( $this->contract(), $this->kit() );
		$second = ElementorKitSyncPlanner::plan( $this->contract(), $this->kit() );

		self::assertIsArray( $first );
		self::assertIsArray( $second );
		self::assertSame( $first, $second );
	}

	public function test_base_hash_describes_the_live_kit_not_the_contract(): void {
		$plan = ElementorKitSyncPlanner::plan( $this->contract(), $this->kit() );
		self::assertIsArray( $plan );

		$other_contract = $this->contract();
		$other_contract['identity']['name'] = 'Different Direction';
		$same_kit                           = ElementorKitSyncPlanner::plan( $other_contract, $this->kit() );

		self::assertIsArray( $same_kit );
		self::assertSame( $plan['base_hash'], $same_kit['base_hash'] );
		self::assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $plan['base_hash'] );
	}

	public function test_a_changed_live_value_changes_the_base_hash(): void {
		$plan = ElementorKitSyncPlanner::plan( $this->contract(), $this->kit() );

		$moved                        = $this->kit();
		$moved['colors'][0]['color'] = '#000000';
		$after                        = ElementorKitSyncPlanner::plan( $this->contract(), $moved );

		self::assertIsArray( $plan );
		self::assertIsArray( $after );
		self::assertNotSame( $plan['base_hash'], $after['base_hash'] );
	}

	public function test_reordered_live_entries_change_the_base_hash(): void {
		$plan = ElementorKitSyncPlanner::plan( $this->contract(), $this->kit() );

		$reordered           = $this->kit();
		$reordered['colors'] = array_reverse( $reordered['colors'] );
		$after               = ElementorKitSyncPlanner::plan( $this->contract(), $reordered );

		self::assertIsArray( $plan );
		self::assertIsArray( $after );
		self::assertNotSame( $plan['base_hash'], $after['base_hash'] );
	}

	// -------------------------------------------------------------------------
	// No-op planning.
	// -------------------------------------------------------------------------

	public function test_a_kit_that_already_matches_needs_no_operations(): void {
		$plan = ElementorKitSyncPlanner::plan( $this->contract(), $this->kit() );

		self::assertIsArray( $plan );
		self::assertSame( [], $plan['operations'] );
		self::assertSame( [], $plan['blocked'] );
		self::assertTrue( $plan['ready_to_apply'] );
	}

	public function test_color_comparison_ignores_hex_case(): void {
		$contract                            = $this->contract();
		$contract['tokens']['colors']['primary'] = '#1f2933';

		$plan = ElementorKitSyncPlanner::plan( $contract, $this->kit() );

		self::assertIsArray( $plan );
		self::assertSame( [], $plan['operations'] );
	}

	public function test_unitless_line_height_matches_the_kit_em_value(): void {
		$plan = ElementorKitSyncPlanner::plan( $this->contract(), $this->kit() );

		self::assertIsArray( $plan );
		self::assertSame( [], $plan['operations'] );
	}

	// -------------------------------------------------------------------------
	// Operations.
	// -------------------------------------------------------------------------

	public function test_a_changed_color_becomes_an_update_operation(): void {
		$contract                               = $this->contract();
		$contract['tokens']['colors']['accent'] = '#7c2d12';

		$plan = ElementorKitSyncPlanner::plan( $contract, $this->kit() );

		self::assertIsArray( $plan );
		self::assertCount( 1, $plan['operations'] );
		self::assertSame(
			[
				'group'    => 'colors',
				'action'   => 'update',
				'bucket'   => 'custom',
				'target'   => 'accent',
				'property' => 'color',
				'path'     => 'tokens.colors.accent',
				'from'     => '#C2410C',
				'to'       => '#7c2d12',
			],
			$plan['operations'][0]
		);
	}

	public function test_a_token_the_kit_lacks_becomes_a_custom_create_operation(): void {
		$contract                                = $this->contract();
		$contract['tokens']['colors']['surface'] = '#f8fafc';

		$plan = ElementorKitSyncPlanner::plan( $contract, $this->kit() );

		self::assertIsArray( $plan );
		self::assertCount( 1, $plan['operations'] );
		self::assertSame( 'create', $plan['operations'][0]['action'] );
		self::assertSame( 'custom', $plan['operations'][0]['bucket'] );
		self::assertSame( 'surface', $plan['operations'][0]['target'] );
		self::assertNull( $plan['operations'][0]['from'] );
	}

	public function test_typography_plans_one_operation_per_changed_property(): void {
		$contract = $this->contract();
		$contract['tokens']['typography']['heading']['font-size']   = '56px';
		$contract['tokens']['typography']['heading']['font-family'] = 'Fraunces';

		$plan = ElementorKitSyncPlanner::plan( $contract, $this->kit() );

		self::assertIsArray( $plan );
		self::assertCount( 1, $plan['operations'] );
		self::assertSame( 'typography', $plan['operations'][0]['group'] );
		self::assertSame( 'font-size', $plan['operations'][0]['property'] );
		self::assertSame( '48px', $plan['operations'][0]['from'] );
		self::assertSame( '56px', $plan['operations'][0]['to'] );
		self::assertSame( 'tokens.typography.heading.font-size', $plan['operations'][0]['path'] );
	}

	public function test_operations_are_ordered_by_path_regardless_of_contract_order(): void {
		$contract                     = $this->contract();
		$contract['tokens']['colors'] = [
			'surface' => '#f8fafc',
			'accent'  => '#7c2d12',
			'ink'     => '#0f172a',
		];

		$plan = ElementorKitSyncPlanner::plan( $contract, $this->kit() );

		self::assertIsArray( $plan );
		self::assertSame(
			[ 'tokens.colors.accent', 'tokens.colors.ink', 'tokens.colors.surface' ],
			array_column( $plan['operations'], 'path' )
		);
	}

	// -------------------------------------------------------------------------
	// Sections the kit has no global for.
	// -------------------------------------------------------------------------

	public function test_unsupported_token_groups_are_reported_and_never_planned(): void {
		$contract                        = $this->contract();
		$contract['tokens']['spacing']   = [ 'section' => '96px' ];
		$contract['tokens']['radii']     = [ 'card' => '12px' ];
		$contract['tokens']['elevation'] = [ 'card' => '0 1px 2px rgba(0,0,0,0.1)' ];
		$contract['tokens']['motion']    = [ 'ease' => '200ms' ];

		$plan = ElementorKitSyncPlanner::plan( $contract, $this->kit() );

		self::assertIsArray( $plan );
		self::assertSame( [], $plan['operations'] );
		self::assertSame(
			[ 'tokens.elevation', 'tokens.motion', 'tokens.radii', 'tokens.spacing' ],
			array_column( $plan['warnings'], 'path' )
		);
		self::assertSame( ElementorKitSyncPlanner::REASON_UNSUPPORTED_GROUP, $plan['warnings'][0]['reason'] );
	}

	public function test_component_guidance_stays_in_the_contract(): void {
		$contract               = $this->contract();
		$contract['components'] = [ 'button' => [ 'border-radius' => '4px' ] ];

		$plan = ElementorKitSyncPlanner::plan( $contract, $this->kit() );

		self::assertIsArray( $plan );
		self::assertSame( [], $plan['operations'] );
		self::assertContains( 'components.button', array_column( $plan['warnings'], 'path' ) );
	}

	public function test_a_typography_property_elementor_has_no_global_for_is_reported(): void {
		$contract = $this->contract();
		$contract['tokens']['typography']['heading']['text-transform'] = 'uppercase';

		$plan = ElementorKitSyncPlanner::plan( $contract, $this->kit() );

		self::assertIsArray( $plan );
		self::assertSame( [], $plan['operations'] );
		self::assertSame(
			[ 'tokens.typography.heading.text-transform' ],
			array_column( $plan['warnings'], 'path' )
		);
		self::assertSame( ElementorKitSyncPlanner::REASON_UNSUPPORTED_PROPERTY, $plan['warnings'][0]['reason'] );
	}

	// -------------------------------------------------------------------------
	// Blocked values.
	// -------------------------------------------------------------------------

	public function test_a_css_variable_color_blocks_the_plan(): void {
		$contract                                = $this->contract();
		$contract['tokens']['colors']['accent']  = 'var(--brand)';

		$plan = ElementorKitSyncPlanner::plan( $contract, $this->kit() );

		self::assertIsArray( $plan );
		self::assertSame( [], $plan['operations'] );
		self::assertSame( 'tokens.colors.accent', $plan['blocked'][0]['path'] );
		self::assertSame( ElementorKitSyncPlanner::REASON_UNSUPPORTED_VALUE, $plan['blocked'][0]['reason'] );
		self::assertFalse( $plan['ready_to_apply'] );
	}

	public function test_a_font_size_without_a_unit_blocks_the_plan(): void {
		$contract = $this->contract();
		$contract['tokens']['typography']['heading']['font-size'] = '48';

		$plan = ElementorKitSyncPlanner::plan( $contract, $this->kit() );

		self::assertIsArray( $plan );
		self::assertSame( [], $plan['operations'] );
		self::assertSame( 'tokens.typography.heading.font-size', $plan['blocked'][0]['path'] );
		self::assertFalse( $plan['ready_to_apply'] );
	}

	public function test_a_calculated_dimension_blocks_the_plan(): void {
		$contract = $this->contract();
		$contract['tokens']['typography']['heading']['letter-spacing'] = 'clamp(1px, 1vw, 3px)';

		$plan = ElementorKitSyncPlanner::plan( $contract, $this->kit() );

		self::assertIsArray( $plan );
		self::assertSame( [], $plan['operations'] );
		self::assertSame( 'tokens.typography.heading.letter-spacing', $plan['blocked'][0]['path'] );
	}

	// -------------------------------------------------------------------------
	// Readiness.
	// -------------------------------------------------------------------------

	public function test_a_direction_that_is_not_sync_ready_is_not_ready_to_apply(): void {
		$contract                               = $this->contract();
		$contract['readiness']['sync_ready']    = false;
		$contract['tokens']['colors']['accent'] = '#7c2d12';

		$plan = ElementorKitSyncPlanner::plan( $contract, $this->kit() );

		self::assertIsArray( $plan );
		self::assertCount( 1, $plan['operations'] );
		self::assertFalse( $plan['ready_to_apply'] );
	}

	// -------------------------------------------------------------------------
	// Rejections.
	// -------------------------------------------------------------------------

	public function test_an_invalid_contract_is_rejected(): void {
		$contract                      = $this->contract();
		$contract['identity']['name']  = '';

		$plan = ElementorKitSyncPlanner::plan( $contract, $this->kit() );

		self::assertInstanceOf( \WP_Error::class, $plan );
		self::assertSame( ElementorKitSyncPlanner::ERROR_CODE, $plan->get_error_code() );
	}

	public function test_an_unknown_live_kit_field_is_rejected(): void {
		$kit             = $this->kit();
		$kit['settings'] = [ 'page_title_selector' => 'h1.entry-title' ];

		$plan = ElementorKitSyncPlanner::plan( $this->contract(), $kit );

		self::assertInstanceOf( \WP_Error::class, $plan );
		self::assertSame( ElementorKitSyncPlanner::ERROR_CODE, $plan->get_error_code() );
	}

	public function test_a_live_kit_without_an_id_is_rejected(): void {
		$kit           = $this->kit();
		$kit['kit_id'] = 0;

		$plan = ElementorKitSyncPlanner::plan( $this->contract(), $kit );

		self::assertInstanceOf( \WP_Error::class, $plan );
		self::assertSame( ElementorKitSyncPlanner::ERROR_CODE, $plan->get_error_code() );
	}

	public function test_a_live_kit_group_that_is_not_a_list_is_rejected(): void {
		$kit           = $this->kit();
		$kit['colors'] = [ 'primary' => '#1f2933' ];

		$plan = ElementorKitSyncPlanner::plan( $this->contract(), $kit );

		self::assertInstanceOf( \WP_Error::class, $plan );
		self::assertSame( ElementorKitSyncPlanner::ERROR_CODE, $plan->get_error_code() );
	}

	public function test_a_live_entry_without_an_id_is_rejected(): void {
		$kit                     = $this->kit();
		$kit['colors'][0]['id']  = '';

		$plan = ElementorKitSyncPlanner::plan( $this->contract(), $kit );

		self::assertInstanceOf( \WP_Error::class, $plan );
		self::assertSame( ElementorKitSyncPlanner::ERROR_CODE, $plan->get_error_code() );
	}

	public function test_an_oversized_live_kit_is_rejected_before_planning(): void {
		$kit = $this->kit();
		for ( $index = 0; $index < 200; $index++ ) {
			$kit['colors'][] = [
				'slug'   => 'filler-' . $index,
				'id'     => 'filler-' . $index,
				'title'  => 'Filler ' . $index,
				'color'  => '#101010',
				'bucket' => 'custom',
			];
		}

		$plan = ElementorKitSyncPlanner::plan( $this->contract(), $kit );

		self::assertInstanceOf( \WP_Error::class, $plan );
		self::assertSame( ElementorKitSyncPlanner::ERROR_CODE, $plan->get_error_code() );
	}

	// -------------------------------------------------------------------------
	// Helpers.
	// -------------------------------------------------------------------------

	/**
	 * A sync-ready contract that matches the kit fixture exactly.
	 *
	 * @return array<string,mixed>
	 */
	private function contract(): array {
		$contract = DirectionContract::defaults();

		$contract['identity']['name']        = 'Quarry';
		$contract['tokens']['colors']        = [
			'primary' => '#1F2933',
			'accent'  => '#c2410c',
		];
		$contract['tokens']['typography']    = [
			'heading' => [
				'font-family' => 'Fraunces',
				'font-size'   => '48px',
				'line-height' => '1.05',
			],
		];
		$contract['readiness']['sync_ready'] = true;

		return $contract;
	}

	/**
	 * A normalized live kit, as the typed kit reader returns it.
	 *
	 * @return array<string,mixed>
	 */
	private function kit(): array {
		return [
			'kit_id'     => 44,
			'colors'     => [
				[
					'slug'   => 'primary',
					'id'     => 'primary',
					'title'  => 'Primary',
					'color'  => '#1F2933',
					'bucket' => 'system',
				],
				[
					'slug'   => 'accent',
					'id'     => 'accent',
					'title'  => 'Accent',
					'color'  => '#C2410C',
					'bucket' => 'custom',
				],
			],
			'typography' => [
				[
					'slug'       => 'heading',
					'id'         => 'heading',
					'title'      => 'Heading',
					'bucket'     => 'system',
					'properties' => [
						'font-family' => 'Fraunces',
						'font-size'   => '48px',
						'line-height' => '1.05em',
					],
				],
			],
		];
	}
}
