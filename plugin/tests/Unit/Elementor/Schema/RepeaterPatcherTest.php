<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Elementor\Schema;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Elementor\Schema\RepeaterPatcher;

/** @covers \Stonewright\WpMcp\Elementor\Schema\RepeaterPatcher */
final class RepeaterPatcherTest extends TestCase {

	public function test_patches_one_row_and_preserves_order_unknown_fields_and_actions(): void {
		$settings = $this->settings();
		$result = RepeaterPatcher::patch(
			$settings,
			'form_fields',
			[ 'custom_id' => 'email' ],
			[ 'field_label' => 'Business email' ]
		);

		self::assertIsArray( $result );
		self::assertSame( 'Business email', $result['settings']['form_fields'][0]['field_label'] );
		self::assertSame( 'keep-newsman-mapping', $result['settings']['form_fields'][0]['newsman_mapping'] );
		self::assertSame( 'name', $result['settings']['form_fields'][1]['custom_id'] );
		self::assertSame( [ 'email', 'newsman' ], $result['settings']['actions_after_submit'] );
		self::assertSame( $result['unknown_fields_hash_before'], $result['unknown_fields_hash_after'] );
		self::assertSame( $result['actions_after_submit_hash_before'], $result['actions_after_submit_hash_after'] );
		self::assertSame( [ 'form_fields[custom_id=email].field_label' ], $result['changed_paths'] );
	}

	public function test_zero_and_multiple_matches_are_ambiguous(): void {
		$zero = RepeaterPatcher::patch( $this->settings(), 'form_fields', [ 'custom_id' => 'missing' ], [ 'field_label' => 'X' ] );
		$duplicate = $this->settings();
		$duplicate['form_fields'][1]['custom_id'] = 'email';
		$many = RepeaterPatcher::patch( $duplicate, 'form_fields', [ 'custom_id' => 'email' ], [ 'field_label' => 'X' ] );

		self::assertInstanceOf( \WP_Error::class, $zero );
		self::assertInstanceOf( \WP_Error::class, $many );
		self::assertSame( 'stonewright_ambiguous_repeater_row', $zero->get_error_code() );
		self::assertSame( 0, $zero->get_error_data()['matches'] );
		self::assertSame( 2, $many->get_error_data()['matches'] );
	}

	public function test_identity_changes_and_stale_row_hash_are_blocked(): void {
		$identity = RepeaterPatcher::patch( $this->settings(), 'form_fields', [ 'custom_id' => 'email' ], [ 'custom_id' => 'renamed' ] );
		$stale = RepeaterPatcher::patch( $this->settings(), 'form_fields', [ 'custom_id' => 'email' ], [ 'field_label' => 'X' ], str_repeat( '0', 64 ) );

		self::assertInstanceOf( \WP_Error::class, $identity );
		self::assertSame( 'stonewright_elementor_repeater_identity_protected', $identity->get_error_code() );
		self::assertInstanceOf( \WP_Error::class, $stale );
		self::assertSame( 'stonewright_elementor_repeater_conflict', $stale->get_error_code() );
	}

	/** @return array<string,mixed> */
	private function settings(): array {
		return [
			'form_fields' => [
				[ 'custom_id' => 'email', '_id' => 'row-a', 'field_label' => 'Email', 'newsman_mapping' => 'keep-newsman-mapping' ],
				[ 'custom_id' => 'name', '_id' => 'row-b', 'field_label' => 'Name', 'conditional_logic' => [ 'enabled' => true ] ],
			],
			'actions_after_submit' => [ 'email', 'newsman' ],
			'email_to' => 'team@example.test',
			'newsman_list' => 'list-1',
		];
	}
}
