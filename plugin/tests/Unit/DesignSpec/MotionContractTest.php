<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\DesignSpec;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\DesignSpec\Migrator;
use Stonewright\WpMcp\DesignSpec\Validator;

/**
 * DesignSpec motion contract: schema strictness, semantic checks, and
 * backward compatibility.
 *
 * @covers \Stonewright\WpMcp\DesignSpec\Validator
 * @covers \Stonewright\WpMcp\DesignSpec\Migrator
 */
final class MotionContractTest extends TestCase {

	public function test_spec_without_motion_remains_valid(): void {
		$result = Validator::validate( self::base_spec() );

		self::assertNotInstanceOf( \WP_Error::class, $result );
	}

	public function test_valid_section_motion_with_policy_passes(): void {
		$spec                 = self::base_spec();
		$spec['motion_policy'] = self::policy();
		$spec['sections'][0]['motion'] = [
			[
				'id'            => 'hero-copy-enter',
				'purpose'       => 'orient',
				'target_id'     => 'hero-copy',
				'trigger'       => 'viewport-enter',
				'effect'        => 'fade-up',
				'duration'      => 'standard',
				'delay_ms'      => 0,
				'playback'      => 'once',
				'engine'        => 'auto',
				'reduced_motion' => 'replace-with-fade',
			],
		];

		$result = Validator::validate( $spec );

		self::assertNotInstanceOf( \WP_Error::class, $result, self::error_summary( $result ) );
	}

	public function test_unknown_top_level_property_is_rejected(): void {
		$spec = self::base_spec();
		$spec['motion_polic'] = self::policy();

		$result = Validator::validate( $spec );

		self::assertInstanceOf( \WP_Error::class, $result );
	}

	public function test_motion_item_missing_required_fields_is_rejected(): void {
		$spec = self::base_spec();
		$spec['sections'][0]['motion'] = [
			[
				'id'        => 'incomplete',
				'target_id' => 'hero-copy',
			],
		];

		$result = Validator::validate( $spec );

		self::assertInstanceOf( \WP_Error::class, $result );
		$keywords = array_column( $result->get_error_data()['errors'], 'keyword' );
		self::assertContains( 'required', $keywords );
	}

	public function test_every_trigger_and_purpose_value_is_accepted(): void {
		$triggers = [ 'load', 'viewport-enter', 'viewport-progress', 'hover', 'focus-visible', 'press', 'state-change' ];
		$purposes = [ 'orient', 'feedback', 'continuity', 'emphasis', 'reveal', 'decorative' ];

		foreach ( $triggers as $i => $trigger ) {
			$spec = self::base_spec();
			$item = self::item( 't-' . $i, $trigger, 'reveal', 'hero-copy' );
			if ( 'hover' === $trigger ) {
				// Hover needs focus-visible parity to pass.
				$spec['sections'][0]['blocks'][] = self::block( 'hero-cta', 'paragraph' );
				$item['target_id']               = 'hero-cta';
				$spec['sections'][0]['motion']   = [ $item, self::item( 'f-' . $i, 'focus-visible', 'reveal', 'hero-cta' ) ];
			} else {
				$spec['sections'][0]['motion'] = [ $item ];
			}
			$item_check = Validator::validate( $spec );
			self::assertNotInstanceOf( \WP_Error::class, $item_check, "trigger {$trigger}: " . self::error_summary( $item_check ) );

			foreach ( $purposes as $purpose ) {
				$spec2                   = self::base_spec();
				$spec2['sections'][0]['motion'] = [ self::item( 'p-test', 'load', $purpose, 'hero-copy' ) ];
				$purpose_check           = Validator::validate( $spec2 );
				self::assertNotInstanceOf( \WP_Error::class, $purpose_check, "purpose {$purpose}: " . self::error_summary( $purpose_check ) );
			}
		}
	}

	public function test_invalid_trigger_enum_is_rejected_by_schema(): void {
		$spec = self::base_spec();
		$spec['sections'][0]['motion'] = [ self::item( 'bad-trigger', 'scroll-by', 'reveal', 'hero-copy' ) ];

		$result = Validator::validate( $spec );

		self::assertInstanceOf( \WP_Error::class, $result );
	}

	public function test_duplicate_motion_ids_are_rejected(): void {
		$spec                          = self::base_spec();
		$spec['sections'][0]['motion'] = [
			self::item( 'dup-id', 'load', 'reveal', 'hero-copy' ),
			self::item( 'dup-id', 'viewport-enter', 'reveal', 'hero-copy' ),
		];

		$result = Validator::validate( $spec );

		self::assertInstanceOf( \WP_Error::class, $result );
		$keywords = array_column( $result->get_error_data()['errors'], 'keyword' );
		self::assertContains( 'motion_duplicate_id', $keywords );
	}

	public function test_missing_target_is_rejected(): void {
		$spec                          = self::base_spec();
		$spec['sections'][0]['motion'] = [ self::item( 'ghost-target', 'load', 'reveal', 'does-not-exist' ) ];

		$result = Validator::validate( $spec );

		self::assertInstanceOf( \WP_Error::class, $result );
		$keywords = array_column( $result->get_error_data()['errors'], 'keyword' );
		self::assertContains( 'motion_target_missing', $keywords );
	}

	public function test_block_motion_cannot_target_a_different_block(): void {
		$spec = self::base_spec();
		$spec['sections'][0]['blocks'][1]['motion'] = [
			self::item( 'cross-target', 'load', 'reveal', 'hero-copy' ),
		];

		$result = Validator::validate( $spec );

		self::assertInstanceOf( \WP_Error::class, $result );
		$keywords = array_column( $result->get_error_data()['errors'], 'keyword' );
		self::assertContains( 'motion_target_missing', $keywords );
	}

	public function test_hover_without_focus_visible_parity_is_rejected(): void {
		$spec = self::base_spec();
		$spec['sections'][0]['blocks'][1]['id']    = 'hero-cta';
		$spec['sections'][0]['blocks'][1]['motion'] = [
			self::item( 'cta-hover', 'hover', 'emphasis', 'hero-cta' ),
		];

		$result = Validator::validate( $spec );

		self::assertInstanceOf( \WP_Error::class, $result );
		$keywords = array_column( $result->get_error_data()['errors'], 'keyword' );
		self::assertContains( 'motion_hover_focus_parity', $keywords );
	}

	public function test_hover_with_focus_visible_parity_is_accepted(): void {
		$spec = self::base_spec();
		$spec['sections'][0]['blocks'][1]['id']     = 'hero-cta';
		$spec['sections'][0]['blocks'][1]['motion'] = [
			self::item( 'cta-hover', 'hover', 'emphasis', 'hero-cta' ),
			self::item( 'cta-focus', 'focus-visible', 'emphasis', 'hero-cta' ),
		];

		$result = Validator::validate( $spec );

		self::assertNotInstanceOf( \WP_Error::class, $result, self::error_summary( $result ) );
	}

	public function test_loop_without_control_is_rejected(): void {
		$spec                          = self::base_spec();
		$item                          = self::item( 'looping', 'load', 'reveal', 'hero-copy' );
		$item['playback']              = 'loop';
		$spec['sections'][0]['motion'] = [ $item ];

		$result = Validator::validate( $spec );

		self::assertInstanceOf( \WP_Error::class, $result );
		$keywords = array_column( $result->get_error_data()['errors'], 'keyword' );
		self::assertContains( 'motion_loop_requires_control', $keywords );
	}

	public function test_loop_for_decorative_purpose_is_rejected_even_with_control(): void {
		$spec                          = self::base_spec();
		$item                          = self::item( 'looping-deco', 'load', 'decorative', 'hero-copy' );
		$item['playback']              = 'loop';
		$item['control_target_id']     = 'hero-copy';
		$item['control_label']         = 'Pause animation';
		$spec['sections'][0]['motion'] = [ $item ];

		$result = Validator::validate( $spec );

		self::assertInstanceOf( \WP_Error::class, $result );
		$keywords = array_column( $result->get_error_data()['errors'], 'keyword' );
		self::assertContains( 'motion_loop_decoration', $keywords );
	}

	public function test_loop_with_control_and_justified_purpose_is_accepted(): void {
		$spec                          = self::base_spec();
		$item                          = self::item( 'looping-ok', 'load', 'emphasis', 'hero-copy' );
		$item['playback']              = 'loop';
		$item['control_target_id']     = 'hero-copy';
		$item['control_label']         = 'Pause hero animation';
		$spec['sections'][0]['motion'] = [ $item ];

		$result = Validator::validate( $spec );

		self::assertNotInstanceOf( \WP_Error::class, $result, self::error_summary( $result ) );
	}

	public function test_stagger_over_span_is_rejected(): void {
		$spec                          = self::base_spec();
		$item                          = self::item( 'staggered', 'viewport-enter', 'reveal', 'hero-copy' );
		$item['stagger']               = [
			'target_ids'  => [ 'hero-copy', 'hero-cta-x', 'hero-img' ],
			'interval_ms' => 250,
			'span_ms'     => 400,
		];
		$spec['sections'][0]['motion'] = [ $item ];

		$result = Validator::validate( $spec );

		self::assertInstanceOf( \WP_Error::class, $result );
		$keywords = array_column( $result->get_error_data()['errors'], 'keyword' );
		self::assertContains( 'motion_stagger_span_exceeded', $keywords );
	}

	public function test_duration_delay_bounds_are_enforced_by_schema(): void {
		// delay_ms above 2000.
		$spec                          = self::base_spec();
		$item                          = self::item( 'slow-delay', 'load', 'reveal', 'hero-copy' );
		$item['delay_ms']              = 2001;
		$spec['sections'][0]['motion'] = [ $item ];
		self::assertInstanceOf( \WP_Error::class, Validator::validate( $spec ) );

		// Raw duration above 3000.
		$spec                          = self::base_spec();
		$item                          = self::item( 'slow-run', 'load', 'reveal', 'hero-copy' );
		$item['duration']              = 3001;
		$spec['sections'][0]['motion'] = [ $item ];
		self::assertInstanceOf( \WP_Error::class, Validator::validate( $spec ) );

		// Boundary values pass.
		$spec                          = self::base_spec();
		$item                          = self::item( 'edge', 'load', 'reveal', 'hero-copy' );
		$item['delay_ms']              = 2000;
		$item['duration']              = 3000;
		$spec['sections'][0]['motion'] = [ $item ];
		$result                        = Validator::validate( $spec );
		self::assertNotInstanceOf( \WP_Error::class, $result, self::error_summary( $result ) );
	}

	public function test_provider_engine_requires_provider_id(): void {
		$spec                          = self::base_spec();
		$item                          = self::item( 'provided', 'load', 'reveal', 'hero-copy' );
		$item['engine']                = 'provider';
		$spec['sections'][0]['motion'] = [ $item ];

		$result = Validator::validate( $spec );

		self::assertInstanceOf( \WP_Error::class, $result );
		$keywords = array_column( $result->get_error_data()['errors'], 'keyword' );
		self::assertContains( 'motion_provider_id_required', $keywords );
	}

	public function test_unknown_effect_slug_is_rejected(): void {
		$spec                          = self::base_spec();
		$spec['sections'][0]['motion'] = [ self::item( 'custom-fx', 'load', 'reveal', 'hero-copy' ) ];
		$spec['sections'][0]['motion'][0]['effect'] = 'super-custom-effect';

		$result = Validator::validate( $spec );

		self::assertInstanceOf( \WP_Error::class, $result );
		$keywords = array_column( $result->get_error_data()['errors'], 'keyword' );
		self::assertContains( 'motion_effect_unknown', $keywords );
	}

	public function test_stagger_reveal_requires_stagger_configuration(): void {
		$spec                          = self::base_spec();
		$item                          = self::item( 'staggering', 'viewport-enter', 'reveal', 'hero-copy' );
		$item['effect']                = 'stagger-reveal';
		$spec['sections'][0]['motion'] = [ $item ];

		$result = Validator::validate( $spec );

		self::assertInstanceOf( \WP_Error::class, $result );
		$keywords = array_column( $result->get_error_data()['errors'], 'keyword' );
		self::assertContains( 'motion_stagger_required', $keywords );

		$item['stagger']               = [
			'target_ids'  => [ 'hero-copy', 'secondary' ],
			'interval_ms' => 80,
			'span_ms'     => 200,
		];
		$spec['sections'][0]['motion'] = [ $item ];
		$result                        = Validator::validate( $spec );
		self::assertNotInstanceOf( \WP_Error::class, $result, self::error_summary( $result ) );
	}

	public function test_v1_to_v2_migration_preserves_structure_and_passes_v2_validation(): void {
		$v1 = self::base_spec();
		unset( $v1['version'] );
		$v1['tokens'] = [ 'colors' => [ 'primary' => '#224466' ] ];

		$v2 = Migrator::v1_to_v2( $v1 );

		self::assertSame( '2.0.0', $v2['version'] );
		self::assertSame( $v1['sections'], $v2['sections'] );
		$result = Validator::validate( $v2 );
		self::assertNotInstanceOf( \WP_Error::class, $result, self::error_summary( $result ) );
	}

	public function test_migrated_spec_with_legacy_design_system_motion_keys_stays_valid(): void {
		$v1                    = self::base_spec();
		$v1['design_system']   = [
			'motion' => [ 'unknown_legacy_key' => 'must-survive' ],
		];

		$v2     = Migrator::v1_to_v2( $v1 );
		$result = Validator::validate( $v2 );

		self::assertNotInstanceOf( \WP_Error::class, $result, self::error_summary( $result ) );
		self::assertSame( 'must-survive', $v2['design_system']['motion']['unknown_legacy_key'] );
	}

	public function test_v2_spec_with_motion_survives_migration_untouched(): void {
		$v2                            = self::base_spec();
		$v2['version']                 = '2.0.0';
		$v2['motion_policy']           = self::policy();
		$v2['sections'][0]['motion']   = [ self::item( 'kept', 'load', 'orient', 'hero-copy' ) ];

		$migrated = Migrator::v1_to_v2( $v2 );

		self::assertSame( $v2['sections'][0]['motion'], $migrated['sections'][0]['motion'] );
		self::assertSame( $v2['motion_policy'], $migrated['motion_policy'] );
		self::assertNotInstanceOf( \WP_Error::class, Validator::validate( $migrated ) );
	}

	public function test_motion_absent_means_full_backward_compatibility(): void {
		// Existing v2 fixture without motion must validate identically before
		// and after the motion contract landed.
		$spec   = self::base_spec();
		$result = Validator::validate( Migrator::v1_to_v2( $spec ) );

		self::assertNotInstanceOf( \WP_Error::class, $result, self::error_summary( $result ) );
		self::assertArrayNotHasKey( 'motion', $result['sections'][0] );
		self::assertArrayNotHasKey( 'motion_policy', $result );
	}

	// ---------------------------------------------------------------------

	/**
	 * @return array<string, mixed>
	 */
	private static function base_spec(): array {
		return [
			'version'  => '2.0.0',
			'page'     => [ 'title' => 'Hero page' ],
			'sections' => [
					[
						'id'     => 'hero',
						'blocks' => [
							self::block( 'hero-copy', 'paragraph' ),
							self::block( 'secondary', 'paragraph' ),
						],
					],
			],
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function block( string $id, string $type ): array {
		return [
			'id'   => $id,
			'type' => $type,
			'text' => 'Real copy text.',
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function policy(): array {
		return [
			'level'          => 'subtle',
			'durations'      => [ 'instant' => 0, 'fast' => 160, 'standard' => 280, 'slow' => 480 ],
			'easings'        => [ 'standard' => 'standard', 'enter' => 'decelerate', 'exit' => 'accelerate' ],
			'distances'      => [ 'small' => 8, 'medium' => 16, 'large' => 32 ],
			'max_concurrent' => 3,
			'reduced_motion' => 'replace_nonessential',
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function item( string $id, string $trigger, string $purpose, string $target_id ): array {
		return [
			'id'             => $id,
			'purpose'        => $purpose,
			'target_id'      => $target_id,
			'trigger'        => $trigger,
			'effect'         => 'fade-up',
			'playback'       => 'once',
			'engine'         => 'auto',
			'reduced_motion' => 'replace-with-fade',
		];
	}

	private static function error_summary( mixed $result ): string {
		if ( ! $result instanceof \WP_Error ) {
			return '';
		}
		$errors = $result->get_error_data()['errors'] ?? [];
		return implode(
			'; ',
			array_map(
				static fn( array $e ): string => ( $e['keyword'] ?? '?' ) . '@' . ( $e['path_string'] ?? '' ) . ': ' . ( $e['message'] ?? '' ),
				(array) $errors
			)
		);
	}
}
