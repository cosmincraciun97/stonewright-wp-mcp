<?php
/**
 * Customizer CSS in the change ledger, including the first save when no custom CSS post exists yet.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\Themes\ThemeCustomCss;
use Stonewright\WpMcp\Security\Adapters\CodeAdapter;
use Stonewright\WpMcp\Security\ChangeLedger;
use Stonewright\WpMcp\Security\CustomCodeGrant;
use Stonewright\WpMcp\Tests\Unit\Security\Fixtures\CodeAdapterHarness;

/**
 * @covers \Stonewright\WpMcp\Security\Adapters\CodeAdapter
 * @covers \Stonewright\WpMcp\Abilities\Themes\ThemeCustomCss
 */
final class CodeAdapterCustomizerTest extends TestCase {
	use CodeAdapterHarness;

	private const GAP = [
		'reason'        => 'No native control owns this fixture CSS.',
		'methods_tried' => [ 'typed_api' ],
	];

	protected function setUp(): void {
		$this->set_up_harness();
	}

	protected function tearDown(): void {
		$this->tear_down_harness();
	}

	/** @return array<string, mixed>|\WP_Error */
	private function apply_css( string $css ): array|\WP_Error {
		$ability = new ThemeCustomCss();
		$dry     = $ability->execute( [ 'action' => 'update', 'css' => $css, 'dry_run' => true, 'native_gap' => self::GAP ] );
		self::assertIsArray( $dry, $dry instanceof \WP_Error ? $dry->get_error_message() : '' );
		$grant = CustomCodeGrant::approve_proposal( (string) $dry['proposal_id'] );
		self::assertIsArray( $grant );
		return $ability->execute( [ 'action' => 'update', 'css' => $css, 'native_gap' => self::GAP, 'custom_code_grant' => $grant['token'] ] );
	}

	public function test_the_first_save_records_an_empty_before_image_and_can_be_undone(): void {
		$GLOBALS['stonewright_test_custom_css'] = '';

		$result = $this->apply_css( "body{color:#111;}\n" );

		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() : '' );
		$row = $this->only_row();
		self::assertSame( 'custom_code', $row['family'], 'Customizer CSS is custom code: its undo needs a person.' );
		self::assertSame( 'customizer_css', $row['resource_type'] );
		self::assertSame( 'stonewright-theme', $row['resource_id'] );
		self::assertSame( 'stonewright/theme-custom-css', $row['ability'] );
		self::assertSame( 'verified', $row['status'] );
		self::assertTrue( $row['restorable'] );
		self::assertSame( '', ChangeLedger::read_image( $row['change_id'], 'before' ) );
		self::assertSame( "body{color:#111;}\n", ChangeLedger::read_image( $row['change_id'], 'after' ) );

		$restored = CodeAdapter::restore_customizer_css( $row['change_id'] );

		self::assertSame( 'succeeded', $restored['status'], $restored['detail'] );
		self::assertSame( '', $GLOBALS['stonewright_test_custom_css'] );
	}

	public function test_a_later_save_records_both_bodies_and_restores_the_earlier_one(): void {
		$GLOBALS['stonewright_test_custom_css'] = ".a{color:red;}\n";

		$this->apply_css( ".a{color:blue;}\n" );

		$row = $this->only_row();
		self::assertSame( ".a{color:red;}\n", ChangeLedger::read_image( $row['change_id'], 'before' ) );
		self::assertSame( ".a{color:blue;}\n", ChangeLedger::read_image( $row['change_id'], 'after' ) );

		$restored = CodeAdapter::restore( $row['change_id'] );

		self::assertSame( 'succeeded', $restored['status'], $restored['detail'] );
		self::assertSame( ".a{color:red;}\n", $GLOBALS['stonewright_test_custom_css'] );
		$rollback = ChangeLedger::get( (string) $restored['rollback_change_id'] );
		self::assertSame( 'rollback', $rollback['kind'] ?? '' );
		self::assertSame( $row['change_id'], $rollback['parent_id'] ?? '' );
	}

	public function test_restore_refuses_when_the_css_changed_since_the_expected_hash(): void {
		$GLOBALS['stonewright_test_custom_css'] = ".a{color:red;}\n";
		$this->apply_css( ".a{color:blue;}\n" );
		$row = $this->only_row();
		$GLOBALS['stonewright_test_custom_css'] = ".a{color:green;}\n";

		$restored = CodeAdapter::restore_customizer_css( $row['change_id'], [ 'expected_current_sha256' => $row['after_sha256'] ] );

		self::assertSame( 'failed', $restored['status'] );
		self::assertSame( 'current_changed', $restored['detail'] );
		self::assertSame( ".a{color:green;}\n", $GLOBALS['stonewright_test_custom_css'] );
	}

	public function test_the_live_hash_of_customizer_css_follows_the_css(): void {
		$GLOBALS['stonewright_test_custom_css'] = '';
		$this->apply_css( ".a{color:blue;}\n" );
		$row = $this->only_row();

		self::assertSame( $row['after_sha256'], CodeAdapter::live_sha256( $row['change_id'] ) );
	}

	public function test_a_ledger_failure_does_not_fail_the_css_write(): void {
		$GLOBALS['stonewright_test_custom_css'] = '';
		$this->break_ledger();

		$result = $this->apply_css( ".a{color:blue;}\n" );

		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() : '' );
		self::assertSame( ".a{color:blue;}\n", $GLOBALS['stonewright_test_custom_css'] );
	}
}
