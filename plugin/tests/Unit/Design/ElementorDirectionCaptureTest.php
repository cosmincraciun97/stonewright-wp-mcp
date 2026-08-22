<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Design;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Design\Direction\DirectionContract;
use Stonewright\WpMcp\Design\Direction\DirectionContractValidator;
use Stonewright\WpMcp\Design\Direction\ElementorDirectionCapture;

/**
 * Mapping tests for turning read-only Elementor evidence into a draft contract.
 *
 * Capture is the one path where a contract is written by machine rather than
 * authored, so these tests pin the properties that make the result trustworthy:
 * every mapped token carries provenance, a value that was not in the evidence
 * stays absent instead of being invented, contradictory evidence becomes a
 * readiness issue rather than a silent winner, the output is byte-identical for
 * identical evidence, and nothing about capture claims the direction is ready.
 *
 * @covers \Stonewright\WpMcp\Design\Direction\ElementorDirectionCapture
 */
final class ElementorDirectionCaptureTest extends TestCase {

	/**
	 * Evidence in the shape the ability schema documents.
	 *
	 * @param array<string,mixed> $overrides Replaced top-level evidence keys.
	 * @return array<string,mixed>
	 */
	private function evidence( array $overrides = [] ): array {
		$evidence = [
			'kit_id'      => 12,
			'kit_title'   => 'Stone Kit',
			'colors'      => [
				[
					'id'    => 'primary',
					'title' => 'Primary',
					'color' => '#1B1B1B',
				],
				[
					'id'    => 'accent',
					'title' => 'Accent',
					'color' => 'rgb(210, 120, 40)',
				],
			],
			'typography'  => [
				[
					'id'          => 'heading',
					'title'       => 'Heading',
					'font_family' => 'Fraunces',
					'font_weight' => '600',
					'font_size'   => '48px',
					'line_height' => '1.1',
				],
			],
			'layout'      => [
				'container_width' => '1200px',
				'widget_spacing'  => '24px',
			],
			'breakpoints' => [
				'mobile' => 767,
				'tablet' => 1024,
			],
			'buttons'     => [
				'border_radius'    => '4px',
				'background_color' => '#1B1B1B',
				'text_color'       => '#FFFFFF',
			],
		];

		return array_merge( $evidence, $overrides );
	}

	// -------------------------------------------------------------------------
	// Mapping.
	// -------------------------------------------------------------------------

	public function test_kit_colors_become_contract_color_tokens(): void {
		$result = ElementorDirectionCapture::from_evidence( $this->evidence() );

		self::assertIsArray( $result );
		self::assertSame(
			[
				'accent'  => 'rgb(210, 120, 40)',
				'primary' => '#1B1B1B',
			],
			$result['contract']['tokens']['colors']
		);
	}

	public function test_kit_typography_becomes_contract_typography_tokens(): void {
		$result = ElementorDirectionCapture::from_evidence( $this->evidence() );

		self::assertIsArray( $result );
		self::assertSame(
			[
				'font-family' => 'Fraunces',
				'font-size'   => '48px',
				'font-weight' => '600',
				'line-height' => '1.1',
			],
			$result['contract']['tokens']['typography']['heading']
		);
	}

	public function test_layout_evidence_becomes_spacing_tokens(): void {
		$result = ElementorDirectionCapture::from_evidence( $this->evidence() );

		self::assertIsArray( $result );
		self::assertSame(
			[
				'container-width' => '1200px',
				'widget-spacing'  => '24px',
			],
			$result['contract']['tokens']['spacing']
		);
	}

	public function test_breakpoints_and_button_styles_become_component_intent(): void {
		$result = ElementorDirectionCapture::from_evidence( $this->evidence() );

		self::assertIsArray( $result );
		self::assertSame(
			[
				'mobile' => 767,
				'tablet' => 1024,
			],
			$result['contract']['components']['breakpoints']
		);
		self::assertSame(
			[
				'background-color' => '#1B1B1B',
				'border-radius'    => '4px',
				'text-color'       => '#FFFFFF',
			],
			$result['contract']['components']['button']
		);
	}

	public function test_identity_name_comes_from_the_kit_title(): void {
		$result = ElementorDirectionCapture::from_evidence( $this->evidence() );

		self::assertIsArray( $result );
		self::assertSame( 'Stone Kit', $result['contract']['identity']['name'] );
	}

	public function test_captured_contract_passes_the_contract_validator(): void {
		$result = ElementorDirectionCapture::from_evidence( $this->evidence() );

		self::assertIsArray( $result );
		self::assertNotInstanceOf( \WP_Error::class, DirectionContractValidator::validate( $result['contract'] ) );
	}

	public function test_capture_is_deterministic_for_identical_evidence(): void {
		$first  = ElementorDirectionCapture::from_evidence( $this->evidence() );
		$second = ElementorDirectionCapture::from_evidence( $this->evidence() );

		self::assertIsArray( $first );
		self::assertIsArray( $second );
		self::assertSame( wp_json_encode( $first ), wp_json_encode( $second ) );
	}

	public function test_evidence_key_order_does_not_change_the_result(): void {
		$forward  = $this->evidence();
		$reversed = array_reverse( $forward, true );

		$first  = ElementorDirectionCapture::from_evidence( $forward );
		$second = ElementorDirectionCapture::from_evidence( $reversed );

		self::assertIsArray( $first );
		self::assertIsArray( $second );
		self::assertSame( wp_json_encode( $first['contract'] ), wp_json_encode( $second['contract'] ) );
	}

	// -------------------------------------------------------------------------
	// Provenance.
	// -------------------------------------------------------------------------

	public function test_every_mapped_token_carries_provenance(): void {
		$result = ElementorDirectionCapture::from_evidence( $this->evidence() );

		self::assertIsArray( $result );

		$expected = [
			'tokens.colors.primary',
			'tokens.colors.accent',
			'tokens.typography.heading',
			'tokens.spacing.container-width',
			'tokens.spacing.widget-spacing',
			'components.breakpoints',
			'components.button',
		];

		foreach ( $expected as $path ) {
			self::assertArrayHasKey( $path, $result['contract']['provenance'], $path );
			self::assertSame( 'elementor-kit', $result['contract']['provenance'][ $path ]['source'], $path );
			self::assertStringStartsWith( 'kit:12:', $result['contract']['provenance'][ $path ]['reference'], $path );
		}

		self::assertCount( count( $expected ), $result['contract']['provenance'] );
	}

	// -------------------------------------------------------------------------
	// Absence beats guessing.
	// -------------------------------------------------------------------------

	public function test_absent_evidence_sections_stay_absent_rather_than_guessed(): void {
		$result = ElementorDirectionCapture::from_evidence(
			[
				'kit_id'    => 12,
				'kit_title' => 'Stone Kit',
				'colors'    => [
					[
						'title' => 'Primary',
						'color' => '#1B1B1B',
					],
				],
			]
		);

		self::assertIsArray( $result );
		self::assertSame( [], (array) $result['contract']['tokens']['typography'] );
		self::assertSame( [], (array) $result['contract']['tokens']['spacing'] );
		self::assertSame( [], (array) $result['contract']['tokens']['radii'] );
		self::assertSame( [], (array) $result['contract']['tokens']['elevation'] );
		self::assertSame( [], (array) $result['contract']['tokens']['motion'] );
		self::assertSame( [], (array) $result['contract']['components'] );
		self::assertSame( [ 'do' => [], 'avoid' => [] ], $result['contract']['guidance'] );

		$transport = DirectionContract::for_transport( $result['contract'] );
		$encoded   = json_decode( (string) wp_json_encode( $transport ) );
		self::assertIsObject( $encoded->tokens->typography );
		self::assertIsObject( $encoded->tokens->spacing );
		self::assertSame( [], (array) $encoded->tokens->typography );

		$round_trip = json_decode( (string) wp_json_encode( $transport ), true );
		$validated  = DirectionContractValidator::validate( is_array( $round_trip ) ? $round_trip : [] );
		self::assertIsArray( $validated );
	}

	public function test_missing_kit_id_uses_an_english_required_property_message(): void {
		$result = ElementorDirectionCapture::from_evidence( [ 'kit_title' => 'Stone Kit' ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( ElementorDirectionCapture::ERROR_CODE, $result->get_error_code() );
		self::assertStringContainsString( 'kit_id is a required property', $result->get_error_message() );
		self::assertStringNotContainsString( 'proprietate', $result->get_error_message() );
	}

	public function test_partial_typography_keeps_only_the_properties_present(): void {
		$result = ElementorDirectionCapture::from_evidence(
			$this->evidence(
				[
					'typography' => [
						[
							'title'       => 'Body',
							'font_family' => 'Inter',
							'font_size'   => '',
							'line_height' => null,
						],
					],
				]
			)
		);

		self::assertIsArray( $result );
		self::assertSame( [ 'font-family' => 'Inter' ], $result['contract']['tokens']['typography']['body'] );
	}

	public function test_dials_are_not_invented_from_evidence(): void {
		$result = ElementorDirectionCapture::from_evidence( $this->evidence() );

		self::assertIsArray( $result );
		self::assertSame(
			[
				'variance' => 0,
				'density'  => 0,
				'motion'   => 0,
			],
			$result['contract']['dials']
		);
	}

	public function test_missing_kit_title_leaves_the_name_empty_and_reports_it(): void {
		$result = ElementorDirectionCapture::from_evidence( $this->evidence( [ 'kit_title' => '' ] ) );

		self::assertIsArray( $result );
		self::assertSame( '', $result['contract']['identity']['name'] );
		self::assertNotSame( [], $result['issues'] );
		self::assertStringContainsString( 'name', strtolower( implode( ' ', $result['issues'] ) ) );
	}

	// -------------------------------------------------------------------------
	// Conflicts and unusable evidence.
	// -------------------------------------------------------------------------

	public function test_conflicting_color_values_keep_the_first_and_raise_an_issue(): void {
		$result = ElementorDirectionCapture::from_evidence(
			$this->evidence(
				[
					'colors' => [
						[
							'title' => 'Primary',
							'color' => '#1B1B1B',
						],
						[
							'title' => 'primary',
							'color' => '#FF0000',
						],
					],
				]
			)
		);

		self::assertIsArray( $result );
		self::assertSame( '#1B1B1B', $result['contract']['tokens']['colors']['primary'] );
		self::assertContains( 'tokens.colors.primary', $result['conflicts'] );
		self::assertNotSame( [], $result['issues'] );
	}

	public function test_identical_repeated_evidence_is_not_a_conflict(): void {
		$result = ElementorDirectionCapture::from_evidence(
			$this->evidence(
				[
					'colors' => [
						[
							'title' => 'Primary',
							'color' => '#1B1B1B',
						],
						[
							'title' => 'Primary',
							'color' => '#1B1B1B',
						],
					],
				]
			)
		);

		self::assertIsArray( $result );
		self::assertSame( [], $result['conflicts'] );
	}

	public function test_unusable_color_values_are_reported_not_stored(): void {
		$result = ElementorDirectionCapture::from_evidence(
			$this->evidence(
				[
					'colors' => [
						[
							'title' => 'Primary',
							'color' => 'currentColor',
						],
						[
							'title' => 'Accent',
							'color' => '#D2782A',
						],
					],
				]
			)
		);

		self::assertIsArray( $result );
		self::assertArrayNotHasKey( 'primary', $result['contract']['tokens']['colors'] );
		self::assertSame( '#D2782A', $result['contract']['tokens']['colors']['accent'] );
		self::assertContains( 'colors.primary', $result['unmapped'] );
	}

	public function test_entries_without_a_usable_key_are_skipped_and_reported(): void {
		$result = ElementorDirectionCapture::from_evidence(
			$this->evidence(
				[
					'colors' => [
						[
							'title' => '   ',
							'color' => '#1B1B1B',
						],
					],
				]
			)
		);

		self::assertIsArray( $result );
		self::assertSame( [], (array) $result['contract']['tokens']['colors'] );
		self::assertContains( 'colors.0', $result['unmapped'] );
	}

	public function test_unsupported_layout_and_button_keys_are_reported_not_mapped(): void {
		$result = ElementorDirectionCapture::from_evidence(
			$this->evidence(
				[
					'layout'  => [
						'container_width' => '1200px',
						'page_title_tag'  => 'h1',
					],
					'buttons' => [
						'hover_animation' => 'grow',
					],
				]
			)
		);

		self::assertIsArray( $result );
		self::assertSame( [ 'container-width' => '1200px' ], $result['contract']['tokens']['spacing'] );
		self::assertArrayNotHasKey( 'button', $result['contract']['components'] );
		self::assertContains( 'layout.page_title_tag', $result['unmapped'] );
		self::assertContains( 'buttons.hover_animation', $result['unmapped'] );
	}

	// -------------------------------------------------------------------------
	// Readiness.
	// -------------------------------------------------------------------------

	public function test_capture_never_claims_the_direction_is_ready(): void {
		$result = ElementorDirectionCapture::from_evidence( $this->evidence() );

		self::assertIsArray( $result );
		self::assertFalse( $result['contract']['readiness']['ready'] );
		self::assertFalse( $result['contract']['readiness']['sync_ready'] );
	}

	public function test_issues_are_carried_into_contract_readiness(): void {
		$result = ElementorDirectionCapture::from_evidence( $this->evidence( [ 'kit_title' => '' ] ) );

		self::assertIsArray( $result );
		self::assertSame( $result['issues'], $result['contract']['readiness']['issues'] );
	}

	// -------------------------------------------------------------------------
	// Rejected evidence.
	// -------------------------------------------------------------------------

	public function test_unknown_evidence_keys_are_rejected_rather_than_stripped(): void {
		$result = ElementorDirectionCapture::from_evidence( $this->evidence( [ 'raw_meta' => [ 'anything' ] ] ) );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( ElementorDirectionCapture::ERROR_CODE, $result->get_error_code() );
	}

	public function test_missing_kit_id_is_rejected(): void {
		$result = ElementorDirectionCapture::from_evidence( $this->evidence( [ 'kit_id' => 0 ] ) );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( ElementorDirectionCapture::ERROR_CODE, $result->get_error_code() );
	}

	public function test_wrongly_shaped_sections_are_rejected(): void {
		foreach ( [ 'colors', 'typography', 'layout', 'breakpoints', 'buttons' ] as $section ) {
			$result = ElementorDirectionCapture::from_evidence( $this->evidence( [ $section => 'nope' ] ) );

			self::assertInstanceOf( \WP_Error::class, $result, $section );
			self::assertSame( ElementorDirectionCapture::ERROR_CODE, $result->get_error_code(), $section );
		}
	}

	public function test_evidence_past_the_item_cap_is_rejected(): void {
		$colors = [];
		for ( $index = 0; $index <= DirectionContract::MAX_LIST_ITEMS; $index++ ) {
			$colors[] = [
				'title' => 'color-' . $index,
				'color' => '#1B1B1B',
			];
		}

		$result = ElementorDirectionCapture::from_evidence( $this->evidence( [ 'colors' => $colors ] ) );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( ElementorDirectionCapture::ERROR_CODE, $result->get_error_code() );
	}

	public function test_breakpoints_must_be_positive_integers(): void {
		$result = ElementorDirectionCapture::from_evidence(
			$this->evidence( [ 'breakpoints' => [ 'mobile' => 'small' ] ] )
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( ElementorDirectionCapture::ERROR_CODE, $result->get_error_code() );
	}

	public function test_evidence_carrying_unsafe_css_is_rejected(): void {
		$result = ElementorDirectionCapture::from_evidence(
			$this->evidence( [ 'layout' => [ 'container_width' => 'url(javascript:alert(1))' ] ] )
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( ElementorDirectionCapture::ERROR_CODE, $result->get_error_code() );
	}
}
