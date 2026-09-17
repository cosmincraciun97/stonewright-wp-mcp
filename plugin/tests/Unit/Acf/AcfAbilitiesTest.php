<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Acf;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\Acf\AcfFieldGroupList;
use Stonewright\WpMcp\Abilities\Acf\AcfRuntime;
use Stonewright\WpMcp\Abilities\Acf\AcfValueUpdate;
use Stonewright\WpMcp\Abilities\Acf\AcfValuesGet;

final class AcfAbilitiesTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_user_logged_in'] = true;
		$GLOBALS['stonewright_test_user_caps']      = [
			'manage_options' => true,
			'edit_post'      => true,
			'edit_posts'     => true,
		];
		$GLOBALS['stonewright_test_options']['stonewright_mode'] = 'development';
		$GLOBALS['stonewright_test_acf_active']                  = false;
		$GLOBALS['stonewright_test_acf_fields']                  = [];
		$GLOBALS['stonewright_test_acf_field_map']               = [];
		$GLOBALS['stonewright_test_acf_formatted_fields']        = [];
		$GLOBALS['stonewright_test_acf_field_types']             = [];
		$GLOBALS['stonewright_test_acf_update_field_calls']      = 0;
		$GLOBALS['stonewright_test_acf_update_field_args']       = [];
		$GLOBALS['stonewright_test_acf_flush_calls']             = [];
		$GLOBALS['stonewright_test_acf_references']              = [];
		$GLOBALS['stonewright_test_backup_calls']                = [];
		unset(
			$GLOBALS['stonewright_test_acf_get_field_callback'],
			$GLOBALS['stonewright_test_acf_update_field_return'],
			$GLOBALS['stonewright_test_acf_update_field_skip_write']
		);
	}

	protected function tearDown(): void {
		unset(
			$GLOBALS['stonewright_test_acf_field_map'],
			$GLOBALS['stonewright_test_acf_formatted_fields'],
			$GLOBALS['stonewright_test_acf_field_types'],
			$GLOBALS['stonewright_test_acf_get_field_callback'],
			$GLOBALS['stonewright_test_acf_update_field_return'],
			$GLOBALS['stonewright_test_acf_update_field_skip_write']
		);
		$GLOBALS['stonewright_test_acf_update_field_calls'] = 0;
		$GLOBALS['stonewright_test_acf_update_field_args']  = [];
		$GLOBALS['stonewright_test_acf_flush_calls']        = [];
		$GLOBALS['stonewright_test_acf_references']         = [];
	}

	public function test_names(): void {
		$this->assertSame( 'stonewright/acf-field-group-list', ( new AcfFieldGroupList() )->name() );
		$this->assertSame( 'stonewright/acf-values-get', ( new AcfValuesGet() )->name() );
	}

	public function test_missing_plugin_error(): void {
		$result = ( new AcfValuesGet() )->execute( [ 'post_id' => 1 ] );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'stonewright_plugin_missing', $result->get_error_code() );
	}

	public function test_values_get_happy_path(): void {
		$GLOBALS['stonewright_test_acf_active'] = true;
		$GLOBALS['stonewright_test_acf_fields'] = [ 'color' => 'red' ];
		$result = ( new AcfValuesGet() )->execute( [ 'post_id' => 1 ] );
		$this->assertIsArray( $result );
		$this->assertSame( 'red', $result['fields']['color'] );
	}

	public function test_input_schema_requires_value_and_documents_that_omission_is_not_deletion(): void {
		$schema = ( new AcfValueUpdate() )->input_schema();
		$this->assertContains( 'value', $schema['required'] );
		$this->assertStringContainsString( 'not a deletion', (string) $schema['properties']['value']['description'] );
		$this->assertContains( 'null', (array) $schema['properties']['value']['type'] );
	}

	public function test_value_update_snapshots_on_real_change(): void {
		$this->activate_acf();
		$this->seed_post( 5 );
		$this->map_field( 'color' );
		$GLOBALS['stonewright_test_acf_fields'] = [ 'color' => 'red' ];
		$result = $this->update( 5, 'color', 'blue' );
		$this->assertIsArray( $result );
		$this->assertTrue( $result['ok'] );
		$this->assertTrue( $result['changed'] );
		$this->assertSame( 'applied', $result['execution_status'] );
		$this->assertSame( 'verified', $result['verification_status'] );
		$this->assertTrue( $result['effect_verified'] );
		$this->assertSame( 'blue', $result['value'] );
		$this->assertSame( 1, $this->update_calls() );
		$this->assertSame( 'field_color', $GLOBALS['stonewright_test_acf_update_field_args'][0]['selector'] );
		$this->assertNotEmpty( $GLOBALS['stonewright_test_acf_flush_calls'] );
		$snaps = get_post_meta( 5, '_stonewright_backups', true );
		$this->assertIsArray( $snaps );
		$this->assertCount( 1, $snaps );
	}

	public function test_missing_value_is_not_deletion_and_does_not_write(): void {
		$this->activate_acf();
		$this->seed_post( 5 );
		$this->map_field( 'color' );
		$result = ( new AcfValueUpdate() )->execute(
			[
				'post_id'  => 5,
				'selector' => 'color',
			]
		);
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'stonewright_acf_value_required', $result->get_error_code() );
		$this->assertSame( 0, $this->update_calls() );
	}

	public function test_explicit_null_value_is_written(): void {
		$this->activate_acf();
		$this->seed_post( 5 );
		$this->map_field( 'color' );
		$GLOBALS['stonewright_test_acf_fields'] = [ 'color' => 'red' ];
		$result = $this->update( 5, 'color', null );
		$this->assertIsArray( $result );
		$this->assertTrue( $result['ok'] );
		$this->assertTrue( $result['changed'] );
		$this->assertNull( $result['value'] );
		$this->assertSame( 1, $this->update_calls() );
	}

	public function test_unknown_selector_errors_without_update_field(): void {
		$this->activate_acf();
		$this->seed_post( 5 );
		$result = $this->update( 5, 'does_not_exist', 'x' );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'stonewright_acf_unknown_selector', $result->get_error_code() );
		$this->assertSame( 0, $this->update_calls() );
	}

	public function test_permissions_run_before_read_or_write(): void {
		$this->activate_acf();
		$this->seed_post( 5 );
		$this->map_field( 'color' );
		$GLOBALS['stonewright_test_user_caps']['edit_post'] = false;
		$GLOBALS['stonewright_test_acf_get_field_callback'] = static function () {
			throw new \RuntimeException( 'get_field must not run before permissions' );
		};
		$result = $this->update( 5, 'color', 'blue' );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'stonewright_permission_denied', $result->get_error_code() );
		$this->assertSame( 0, $this->update_calls() );
	}

	public function test_false_zero_and_string_zero_are_not_boolean_cast(): void {
		$this->activate_acf();
		$this->seed_post( 5 );
		$this->map_field( 'flag', 'true_false' );
		$GLOBALS['stonewright_test_acf_fields'] = [ 'flag' => true ];
		$result = $this->update( 5, 'flag', false );
		$this->assertIsArray( $result );
		$this->assertTrue( $result['ok'] );
		$this->assertFalse( $result['value'] );
		$this->assertTrue( $result['effect_verified'] );

		$this->map_field( 'count', 'text' );
		$GLOBALS['stonewright_test_acf_fields']['count'] = 0;
		$zero = $this->update( 5, 'count', 0 );
		$this->assertIsArray( $zero );
		$this->assertFalse( $zero['changed'] );
		$this->assertSame( 0, $zero['value'] );

		$GLOBALS['stonewright_test_acf_fields']['count'] = 0;
		$string_zero = $this->update( 5, 'count', '0' );
		$this->assertIsArray( $string_zero );
		$this->assertTrue( $string_zero['changed'] );
		$this->assertSame( '0', $string_zero['value'] );
	}

	public function test_ordered_lists_are_not_sorted(): void {
		$this->activate_acf();
		$this->seed_post( 5 );
		$this->map_field( 'opts', 'checkbox' );
		$GLOBALS['stonewright_test_acf_fields'] = [ 'opts' => [ 'a', 'b' ] ];
		$same = $this->update( 5, 'opts', [ 'a', 'b' ] );
		$this->assertIsArray( $same );
		$this->assertFalse( $same['changed'] );
		$this->assertSame( 0, $this->update_calls() );

		$reordered = $this->update( 5, 'opts', [ 'b', 'a' ] );
		$this->assertIsArray( $reordered );
		$this->assertTrue( $reordered['changed'] );
		$this->assertSame( [ 'b', 'a' ], $reordered['value'] );
		$this->assertSame( 1, $this->update_calls() );
	}

	public function test_image_and_post_reference_ids_canonicalize(): void {
		$this->activate_acf();
		$this->seed_post( 5 );
		$this->seed_post( 10, 'attachment' );
		$this->map_field( 'hero', 'image' );
		$GLOBALS['stonewright_test_acf_fields'] = [ 'hero' => '10' ];
		$same = $this->update( 5, 'hero', 10 );
		$this->assertIsArray( $same );
		$this->assertFalse( $same['changed'] );
		$this->assertSame( 0, $this->update_calls() );

		$from_map = $this->update( 5, 'hero', [ 'ID' => 10 ] );
		$this->assertIsArray( $from_map );
		$this->assertFalse( $from_map['changed'] );
		$this->assertSame( 0, $this->update_calls() );

		$this->map_field( 'related', 'relationship' );
		$this->seed_post( 11 );
		$GLOBALS['stonewright_test_acf_fields']['related'] = [ 10, '11' ];
		$related = $this->update( 5, 'related', [ 10, 11 ] );
		$this->assertIsArray( $related );
		$this->assertFalse( $related['changed'] );
	}

	public function test_invalid_reference_does_not_write(): void {
		$this->activate_acf();
		$this->seed_post( 5 );
		$this->map_field( 'hero', 'image' );
		$result = $this->update( 5, 'hero', 999 );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'stonewright_acf_invalid_reference', $result->get_error_code() );
		$this->assertSame( 0, $this->update_calls() );
	}

	public function test_unchanged_canonical_value_skips_backup_and_update(): void {
		$this->activate_acf();
		$this->seed_post( 5 );
		$this->map_field( 'color' );
		$GLOBALS['stonewright_test_acf_fields'] = [ 'color' => 'blue' ];
		$result = $this->update( 5, 'color', 'blue' );
		$this->assertIsArray( $result );
		$this->assertTrue( $result['ok'] );
		$this->assertFalse( $result['changed'] );
		$this->assertSame( 'unchanged', $result['execution_status'] );
		$this->assertTrue( $result['effect_verified'] );
		$this->assertSame( 0, $this->update_calls() );
		$this->assertSame( [], $GLOBALS['stonewright_test_acf_flush_calls'] );
		$snaps = get_post_meta( 5, '_stonewright_backups', true );
		$this->assertTrue( '' === $snaps || null === $snaps || [] === $snaps );
	}

	public function test_comparison_uses_raw_get_field_not_formatted(): void {
		$this->activate_acf();
		$this->seed_post( 5 );
		$this->seed_post( 10, 'attachment' );
		$this->map_field( 'hero', 'image' );
		$GLOBALS['stonewright_test_acf_fields']           = [ 'hero' => 10 ];
		$GLOBALS['stonewright_test_acf_formatted_fields'] = [
			'hero' => [
				'ID'  => 10,
				'url' => 'https://example.test/hero.jpg',
			],
		];
		$result = $this->update( 5, 'hero', 10 );
		$this->assertIsArray( $result );
		$this->assertFalse( $result['changed'] );
		$this->assertSame( 10, $result['value'] );
		$this->assertSame( 0, $this->update_calls() );
	}

	public function test_backup_failure_blocks_update_field(): void {
		$this->activate_acf();
		$this->map_field( 'color' );
		$GLOBALS['stonewright_test_acf_fields'] = [ 'color' => 'red' ];
		$result = $this->update( 404, 'color', 'blue' );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'stonewright_backup_failed', $result->get_error_code() );
		$this->assertSame( 0, $this->update_calls() );
	}

	public function test_update_false_with_matching_raw_readback_is_unchanged_success(): void {
		$this->activate_acf();
		$this->seed_post( 5 );
		$this->map_field( 'color' );
		$calls = 0;
		$GLOBALS['stonewright_test_acf_get_field_callback'] = static function ( $selector, $post_id, $format_value ) use ( &$calls ) {
			unset( $selector, $post_id );
			++$calls;
			if ( false === $format_value && 1 === $calls ) {
				return 'stale';
			}
			return 'blue';
		};
		$GLOBALS['stonewright_test_acf_update_field_return']    = false;
		$GLOBALS['stonewright_test_acf_update_field_skip_write'] = true;
		$result = $this->update( 5, 'color', 'blue' );
		$this->assertIsArray( $result );
		$this->assertTrue( $result['ok'] );
		$this->assertTrue( $result['changed'] );
		$this->assertSame( 'applied', $result['execution_status'] );
		$this->assertTrue( $result['effect_verified'] );
		$this->assertSame( 'blue', $result['value'] );
		$this->assertSame( 1, $this->update_calls() );
	}

	public function test_update_false_with_differing_readback_is_mismatch(): void {
		$this->activate_acf();
		$this->seed_post( 5 );
		$this->map_field( 'color' );
		$GLOBALS['stonewright_test_acf_fields']                 = [ 'color' => 'red' ];
		$GLOBALS['stonewright_test_acf_update_field_return']    = false;
		$GLOBALS['stonewright_test_acf_update_field_skip_write'] = true;
		$result = $this->update( 5, 'color', 'blue' );
		$this->assertIsArray( $result );
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'failed', $result['verification_status'] );
		$this->assertFalse( $result['effect_verified'] );
		$this->assertSame( 'stonewright_acf_readback_mismatch', $result['error_code'] );
		$this->assertNotSame( '', $result['error_message'] );
		$this->assertLessThanOrEqual( 200, strlen( (string) $result['error_message'] ) );
		$this->assertSame( 'red', $result['value'] );
	}

	public function test_truthy_update_return_does_not_override_readback_mismatch(): void {
		$this->activate_acf();
		$this->seed_post( 5 );
		$this->map_field( 'color' );
		$GLOBALS['stonewright_test_acf_fields']                 = [ 'color' => 'red' ];
		$GLOBALS['stonewright_test_acf_update_field_return']    = 15;
		$GLOBALS['stonewright_test_acf_update_field_skip_write'] = true;
		$result = $this->update( 5, 'color', 'blue' );
		$this->assertIsArray( $result );
		$this->assertFalse( $result['ok'] );
		$this->assertFalse( $result['changed'] );
		$this->assertSame( 'failed', $result['execution_status'] );
		$this->assertSame( 'failed', $result['verification_status'] );
		$this->assertFalse( $result['effect_verified'] );
		$this->assertSame( 'stonewright_acf_readback_mismatch', $result['error_code'] );
	}

	public function test_unknown_custom_type_reports_limitation_without_claiming_verified(): void {
		$this->activate_acf();
		$this->seed_post( 5 );
		$this->map_field( 'payload', 'my_custom_transform' );
		$GLOBALS['stonewright_test_acf_fields'] = [ 'payload' => [ 'before' => 1 ] ];
		$result = $this->update( 5, 'payload', [ 'after' => 2 ] );
		$this->assertIsArray( $result );
		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'limited', $result['verification_status'] );
		$this->assertFalse( $result['effect_verified'] );
		$this->assertArrayHasKey( 'limitation', $result );
		$this->assertStringContainsString( 'my_custom_transform', (string) $result['limitation'] );
		$this->assertSame( [ 'after' => 2 ], $result['value'] );
		$this->assertSame( 1, $this->update_calls() );
		$this->assertNotTrue( $result['effect_verified'] );
	}

	public function test_garbage_image_is_input_error_not_noop(): void {
		$this->activate_acf();
		$this->seed_post( 5 );
		$this->map_field( 'hero', 'image' );
		$GLOBALS['stonewright_test_acf_fields'] = [ 'hero' => null ];
		$result = $this->update( 5, 'hero', 'not-an-id' );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'stonewright_acf_invalid_value', $result->get_error_code() );
		$this->assertSame( 0, $this->update_calls() );
		$this->assertSame( [], $GLOBALS['stonewright_test_backup_calls'] ?? [] );
	}

	public function test_boolean_accepts_explicit_literals_only(): void {
		$this->assertTrue( AcfRuntime::validate_value( true, [ 'type' => 'true_false' ] ) );
		$this->assertTrue( AcfRuntime::validate_value( false, [ 'type' => 'true_false' ] ) );
		$this->assertTrue( AcfRuntime::validate_value( 1, [ 'type' => 'true_false' ] ) );
		$this->assertTrue( AcfRuntime::validate_value( 0, [ 'type' => 'true_false' ] ) );
		$this->assertTrue( AcfRuntime::validate_value( '1', [ 'type' => 'true_false' ] ) );
		$this->assertTrue( AcfRuntime::validate_value( '0', [ 'type' => 'true_false' ] ) );
		foreach ( [ 'false', 'true', 'yes', 'no', 2, 1.0, [], (object) [] ] as $invalid ) {
			$this->assertInstanceOf(
				\WP_Error::class,
				AcfRuntime::validate_value( $invalid, [ 'type' => 'true_false' ] )
			);
		}
	}

	public function test_reference_ids_reject_fractions_negatives_and_unexpected_keys(): void {
		$image = [ 'type' => 'image' ];
		$this->assertTrue( AcfRuntime::validate_value( null, $image ) );
		$this->assertTrue( AcfRuntime::validate_value( false, $image ) );
		$this->assertTrue( AcfRuntime::validate_value( '', $image ) );
		$this->assertTrue( AcfRuntime::validate_value( 10, $image ) );
		$this->assertTrue( AcfRuntime::validate_value( '10', $image ) );
		$this->assertTrue( AcfRuntime::validate_value( [ 'ID' => 10 ], $image ) );
		$this->assertInstanceOf( \WP_Error::class, AcfRuntime::validate_value( 0, $image ) );
		$this->assertInstanceOf( \WP_Error::class, AcfRuntime::validate_value( -1, $image ) );
		$this->assertInstanceOf( \WP_Error::class, AcfRuntime::validate_value( 1.5, $image ) );
		$this->assertInstanceOf( \WP_Error::class, AcfRuntime::validate_value( '10.0', $image ) );
		$this->assertInstanceOf( \WP_Error::class, AcfRuntime::validate_value( 'not-an-id', $image ) );
		$this->assertInstanceOf(
			\WP_Error::class,
			AcfRuntime::validate_value( [ 'ID' => 10, 'url' => 'https://example.test/x.jpg' ], $image )
		);

		$rel = [ 'type' => 'relationship' ];
		$this->seed_post( 10 );
		$this->seed_post( 11 );
		$this->assertTrue( AcfRuntime::validate_value( [], $rel ) );
		$this->assertTrue( AcfRuntime::validate_value( [ 10, 11 ], $rel ) );
		$this->assertInstanceOf( \WP_Error::class, AcfRuntime::validate_value( [ 10, 'nope' ], $rel ) );
		$this->assertTrue( AcfRuntime::references_valid( [], $rel ) );
		$this->assertTrue( AcfRuntime::references_valid( [ 10 ], $rel ) );
		$this->assertFalse( AcfRuntime::references_valid( [ 10, 'nope' ], $rel ) );
	}

	public function test_number_decimal_equality_without_float_collapse(): void {
		$number = [ 'type' => 'number' ];
		self::assertTrue( AcfRuntime::values_equal( 1.0, '1', $number ) );
		self::assertTrue( AcfRuntime::values_equal( '1.00', 1, $number ) );
		self::assertFalse( AcfRuntime::values_equal( '1.01', 1, $number ) );
		self::assertInstanceOf(
			\WP_Error::class,
			AcfRuntime::validate_value( 'not-an-id', [ 'type' => 'image' ] )
		);
		self::assertTrue( AcfRuntime::values_equal( 0, '0', $number ) );
		self::assertTrue( AcfRuntime::values_equal( -2.5, '-2.50', $number ) );
		self::assertTrue( AcfRuntime::values_equal( '1e2', 100, $number ) );
		self::assertTrue( AcfRuntime::values_equal( '9007199254740993', '9007199254740993', $number ) );
		self::assertFalse( AcfRuntime::values_equal( '9007199254740993', '9007199254740994', $number ) );
		self::assertFalse( AcfRuntime::values_equal( '', 0, $number ) );
		self::assertInstanceOf( \WP_Error::class, AcfRuntime::validate_value( NAN, $number ) );
		self::assertInstanceOf( \WP_Error::class, AcfRuntime::validate_value( INF, $number ) );
		self::assertInstanceOf( \WP_Error::class, AcfRuntime::validate_value( '1.2.3', $number ) );
		self::assertInstanceOf( \WP_Error::class, AcfRuntime::validate_value( [], $number ) );
	}

	public function test_empty_relationship_is_not_an_invalid_reference(): void {
		$this->activate_acf();
		$this->seed_post( 5 );
		$this->map_field( 'related', 'relationship' );
		$GLOBALS['stonewright_test_acf_fields'] = [ 'related' => [] ];
		$result = $this->update( 5, 'related', [] );
		$this->assertIsArray( $result );
		$this->assertTrue( $result['ok'] );
		$this->assertFalse( $result['changed'] );
		$this->assertSame( 0, $this->update_calls() );
	}

	public function test_missing_field_reference_writes_even_when_value_matches(): void {
		$this->activate_acf();
		$this->seed_post( 5 );
		$this->map_field( 'color' );
		$GLOBALS['stonewright_test_acf_fields'] = [ 'color' => 'blue' ];
		$GLOBALS['stonewright_test_acf_references'][5]['color']       = false;
		$GLOBALS['stonewright_test_acf_references'][5]['field_color'] = false;
		$result = $this->update( 5, 'color', 'blue' );
		$this->assertIsArray( $result );
		$this->assertTrue( $result['ok'] );
		$this->assertTrue( $result['changed'] );
		$this->assertSame( 'applied', $result['execution_status'] );
		$this->assertSame( 1, $this->update_calls() );
		$this->assertSame( 'field_color', $GLOBALS['stonewright_test_acf_update_field_args'][0]['selector'] );
		$this->assertSame( 'field_color', $GLOBALS['stonewright_test_acf_references'][5]['color'] );
		$snaps = get_post_meta( 5, '_stonewright_backups', true );
		$this->assertIsArray( $snaps );
		$this->assertCount( 1, $snaps );
	}

	public function test_wrong_field_reference_repairs_via_field_key(): void {
		$this->activate_acf();
		$this->seed_post( 5 );
		$this->map_field( 'color' );
		$GLOBALS['stonewright_test_acf_fields'] = [ 'color' => 'blue' ];
		$GLOBALS['stonewright_test_acf_references'][5]['color']       = 'field_other';
		$GLOBALS['stonewright_test_acf_references'][5]['field_color'] = 'field_other';
		$result = $this->update( 5, 'color', 'blue' );
		$this->assertIsArray( $result );
		$this->assertTrue( $result['ok'] );
		$this->assertTrue( $result['changed'] );
		$this->assertSame( 1, $this->update_calls() );
		$this->assertSame( 'field_color', $GLOBALS['stonewright_test_acf_references'][5]['color'] );
	}

	public function test_group_validates_sub_fields_by_name_and_key(): void {
		$group = [
			'type'       => 'group',
			'sub_fields' => [
				[
					'key'  => 'field_title',
					'name' => 'title',
					'type' => 'text',
				],
				[
					'key'  => 'field_hero',
					'name' => 'hero',
					'type' => 'image',
				],
			],
		];
		$this->assertTrue( AcfRuntime::validate_value( [ 'title' => 'Hi', 'hero' => 10 ], $group ) );
		$this->assertTrue( AcfRuntime::validate_value( [ 'field_title' => 'Hi', 'field_hero' => 10 ], $group ) );
		$this->assertInstanceOf(
			\WP_Error::class,
			AcfRuntime::validate_value( [ 'title' => 'Hi', 'mystery' => 1 ], $group )
		);
		$this->assertInstanceOf(
			\WP_Error::class,
			AcfRuntime::validate_value( [ 'hero' => 'not-an-id' ], $group )
		);
	}

	public function test_audit_redacts_acf_field_value(): void {
		$this->activate_acf();
		$this->seed_post( 5 );
		$this->map_field( 'secret' );
		$GLOBALS['stonewright_test_acf_fields'] = [ 'secret' => 'old' ];
		$GLOBALS['stonewright_test_wpdb_inserts'] = [];
		$result = $this->update( 5, 'secret', 'super-secret-acf-value' );
		$this->assertIsArray( $result );
		$row = $GLOBALS['stonewright_test_wpdb_inserts'][0]['data'] ?? [];
		$encoded = (string) ( $row['sanitized_args'] ?? '' );
		$this->assertStringNotContainsString( 'super-secret-acf-value', $encoded );
		$decoded = json_decode( $encoded, true );
		$this->assertIsArray( $decoded );
		$this->assertArrayHasKey( 'value', $decoded );
		$this->assertIsString( $decoded['value'] );
		$this->assertStringContainsString( '[redacted', $decoded['value'] );
	}

	private function activate_acf(): void {
		$GLOBALS['stonewright_test_acf_active'] = true;
	}

	private function seed_post( int $id, string $type = 'post' ): void {
		$GLOBALS['stonewright_test_posts'][ $id ] = (object) [
			'ID'           => $id,
			'post_title'   => 'P' . $id,
			'post_status'  => 'publish',
			'post_content' => '',
			'post_excerpt' => '',
			'post_type'    => $type,
			'meta'         => [],
		];
	}

	private function map_field( string $name, string $type = 'text', string $key = '' ): void {
		$key = '' !== $key ? $key : 'field_' . $name;
		$def = [
			'key'  => $key,
			'name' => $name,
			'type' => $type,
		];
		$GLOBALS['stonewright_test_acf_field_map'][ $name ] = $def;
		$GLOBALS['stonewright_test_acf_field_map'][ $key ]  = $def;
	}

	private function update( int $post_id, string $selector, mixed $value ): array|\WP_Error {
		return ( new AcfValueUpdate() )->execute(
			[
				'post_id'  => $post_id,
				'selector' => $selector,
				'value'    => $value,
			]
		);
	}

	private function update_calls(): int {
		return (int) ( $GLOBALS['stonewright_test_acf_update_field_calls'] ?? 0 );
	}
}
