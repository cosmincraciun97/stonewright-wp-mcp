<?php
/**
 * WPCode and Code Snippets writes in the change ledger, and the restore from the ledger image.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\CustomCode;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\CustomCode\ProviderRegistry;
use Stonewright\WpMcp\CustomCode\Providers\CodeSnippetsProvider;
use Stonewright\WpMcp\CustomCode\Providers\WpCodeProvider;
use Stonewright\WpMcp\Security\Adapters\CodeAdapter;
use Stonewright\WpMcp\Security\ChangeJournal;
use Stonewright\WpMcp\Security\ChangeLedger;
use Stonewright\WpMcp\Security\CustomCodeGrant;
use Stonewright\WpMcp\Security\RescueGuard;
use Stonewright\WpMcp\Security\RollbackRecipes;
use Stonewright\WpMcp\Tests\Unit\Security\Fixtures\CodeAdapterHarness;

/**
 * @covers \Stonewright\WpMcp\Security\Adapters\CodeAdapter
 * @covers \Stonewright\WpMcp\CustomCode\ProviderSupport
 * @covers \Stonewright\WpMcp\CustomCode\Providers\WpCodeProvider
 * @covers \Stonewright\WpMcp\CustomCode\Providers\CodeSnippetsProvider
 * @covers \Stonewright\WpMcp\Security\RollbackRecipes
 */
final class SnippetLedgerTest extends TestCase {
	use CodeAdapterHarness;

	private const BEFORE = "<?php\necho 'before';\n";

	private const AFTER = "<?php\necho 'after';\n";

	/** @var array<string, array<string, mixed>> */
	private array $wpcode = [];

	/** @var array<string, array<string, mixed>> */
	private array $snippets = [];

	/** @var array<string, string> */
	private array $cache = [];

	private bool $cache_is_stale = false;

	private bool $save_fails = false;

	protected function setUp(): void {
		$this->set_up_harness();
		$this->wpcode         = [ '12' => [ 'code' => self::BEFORE, 'title' => 'Demo snippet', 'language' => 'php', 'active' => true ] ];
		$this->snippets       = [ '7' => [ 'code' => self::BEFORE, 'title' => 'Other snippet', 'language' => 'php', 'active' => true, 'scope' => 'global' ] ];
		$this->cache          = [];
		$this->cache_is_stale = false;
		$this->save_fails     = false;
		ProviderRegistry::set_for_tests(
			[
				'wpcode'        => new WpCodeProvider( $this->wpcode_backend() ),
				'code-snippets' => new CodeSnippetsProvider( $this->snippets_backend() ),
			]
		);
		RollbackRecipes::set_provider_resolver( null );
	}

	protected function tearDown(): void {
		ProviderRegistry::reset_for_tests();
		RollbackRecipes::set_provider_resolver( null );
		$this->tear_down_harness();
	}

	/** @return array<string, mixed>|\WP_Error */
	private function apply( string $provider_id, string $target, string $code, string $path ): array|\WP_Error {
		$provider = ProviderRegistry::get( $provider_id );
		self::assertNotNull( $provider );
		$dry = $provider->dry_run( [ 'target_id' => $target, 'code' => $code, 'language' => 'php' ] );
		self::assertIsArray( $dry, $dry instanceof \WP_Error ? $dry->get_error_message() : '' );
		$grant = CustomCodeGrant::issue( [ 'path' => $path, 'after_sha256' => $dry['after_sha256'], 'language' => 'php' ] );
		self::assertIsArray( $grant );
		return $provider->apply(
			[
				'target_id'              => $target,
				'code'                   => $code,
				'language'               => 'php',
				'custom_code_grant'      => $grant['token'],
				'expected_before_sha256' => $dry['before_sha256'],
			]
		);
	}

	// ---- record and settle -------------------------------------------------------------------------------

	public function test_a_wpcode_apply_records_the_snippet_before_and_after_with_its_metadata(): void {
		$result = $this->apply( 'wpcode', '12', self::AFTER, 'wpcode/snippet/12' );

		self::assertIsArray( $result );
		$row = $this->only_row();
		self::assertSame( 'custom_code', $row['family'] );
		self::assertSame( 'custom_code_wpcode', $row['resource_type'] );
		self::assertSame( '12', $row['resource_id'] );
		self::assertSame( 'verified', $row['status'] );
		self::assertTrue( $row['restorable'] );
		$before = ChangeLedger::read_image( $row['change_id'], 'before' );
		$after  = ChangeLedger::read_image( $row['change_id'], 'after' );
		self::assertIsArray( $before );
		self::assertIsArray( $after );
		self::assertSame( self::BEFORE, $before['body'] );
		self::assertSame( self::AFTER, $after['body'] );
		self::assertSame( 'Demo snippet', $before['title'] );
		self::assertSame( 'php', $before['language'] );
		self::assertTrue( $before['active'] );
		self::assertSame( 'wpcode/snippet/12', $before['path'] );
		self::assertSame( 'wpcode', $before['provider'] );
	}

	public function test_a_code_snippets_apply_records_the_snippet_with_its_scope(): void {
		$result = $this->apply( 'code-snippets', '7', self::AFTER, 'code-snippets/snippet/7' );

		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() : '' );
		$row = $this->only_row();
		self::assertSame( 'custom_code_code_snippets', $row['resource_type'] );
		self::assertSame( '7', $row['resource_id'] );
		self::assertSame( 'global', ChangeLedger::read_image( $row['change_id'], 'before' )['scope'] );
		self::assertSame( self::AFTER, ChangeLedger::read_image( $row['change_id'], 'after' )['body'] );
	}

	public function test_a_failed_save_is_a_failed_row_and_the_error_is_unchanged(): void {
		$this->save_fails = true;

		$result = $this->apply( 'wpcode', '12', self::AFTER, 'wpcode/snippet/12' );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'failed', $this->only_row()['status'] );
		self::assertSame( '', $this->only_row()['after_ref'] );
	}

	public function test_a_write_that_fails_its_own_check_is_restored_and_the_row_says_so(): void {
		$this->cache_is_stale = true;

		$result = $this->apply( 'wpcode', '12', self::AFTER, 'wpcode/snippet/12' );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_wpcode_verify_failed_restored', $result->get_error_code() );
		self::assertSame( 'rolled_back', $this->only_row()['status'] );
		self::assertSame( self::BEFORE, $this->wpcode['12']['code'] );
	}

	public function test_a_ledger_failure_does_not_fail_the_snippet_write(): void {
		$this->break_ledger();

		$result = $this->apply( 'wpcode', '12', self::AFTER, 'wpcode/snippet/12' );

		self::assertIsArray( $result );
		self::assertTrue( $result['effect_verified'] );
		self::assertSame( self::AFTER, $this->wpcode['12']['code'] );
	}

	public function test_inside_a_rescue_call_the_row_takes_the_journal_id_and_waits_for_the_journal(): void {
		RescueGuard::enter( 'stonewright/custom-code-provider' );
		$armed = (string) RescueGuard::arm_custom_code_write( 'wpcode', '12' );

		$result = $this->apply( 'wpcode', '12', self::AFTER, 'wpcode/snippet/12' );

		self::assertIsArray( $result );
		$row = $this->only_row();
		self::assertSame( $armed, $row['change_id'] );
		self::assertSame( 'armed', $row['status'], 'The site check after the call decides the outcome.' );
		self::assertSame( self::AFTER, ChangeLedger::read_image( $armed, 'after' )['body'] );

		ChangeJournal::settle( $armed, 'verified' );
		self::assertSame( 'verified', ChangeLedger::get( $armed )['status'] );
	}

	// ---- the expired snapshot -------------------------------------------------------------------------------

	/** @return array<string, mixed> */
	private function entry_of( string $snapshot_id ): array {
		return [
			'id'            => 'cs-' . str_repeat( 'a', 24 ),
			'ability'       => 'stonewright/custom-code-provider',
			'resource_type' => 'custom_code',
			'resource_key'  => 'wpcode:12',
			'recipe'        => [ 'type' => 'none', 'ref' => $snapshot_id ],
			'recipe_detail' => [ 'provider' => 'wpcode', 'target_id' => '12', 'snapshot_id' => $snapshot_id ],
			'state'         => 'verified',
		];
	}

	public function test_a_live_snapshot_is_available_and_an_expired_one_is_not(): void {
		$result   = $this->apply( 'wpcode', '12', self::AFTER, 'wpcode/snippet/12' );
		$snapshot = (string) $result['snapshot_id'];

		self::assertTrue( RollbackRecipes::available( $this->entry_of( $snapshot ) ) );
		self::assertStringContainsString( 'provider snapshot', RollbackRecipes::describe( $this->entry_of( $snapshot ) ) );

		unset( $GLOBALS['stonewright_test_transients'][ 'sw_cc_snap_' . $snapshot ] );

		self::assertFalse( RollbackRecipes::available( $this->entry_of( $snapshot ) ), 'The snapshot is gone: the entry must not read as available.' );
		self::assertStringContainsString( 'expired', RollbackRecipes::describe( $this->entry_of( $snapshot ) ) );
		$run = RollbackRecipes::run( $this->entry_of( $snapshot ) );
		self::assertSame( 'failed', $run['status'], 'Running the recipe still fails closed.' );
		self::assertStringContainsString( 'snapshot_missing', $run['detail'] );
		self::assertSame( self::AFTER, $this->wpcode['12']['code'], 'Nothing was written.' );
	}

	public function test_the_ledger_image_outlives_the_snapshot_and_restores_the_snippet(): void {
		$this->apply( 'wpcode', '12', self::AFTER, 'wpcode/snippet/12' );
		$row = $this->only_row();
		$GLOBALS['stonewright_test_transients'] = [];

		$restored = CodeAdapter::restore_snippet( $row['change_id'] );

		self::assertSame( 'succeeded', $restored['status'], $restored['detail'] );
		self::assertSame( self::BEFORE, $this->wpcode['12']['code'] );
		$rollback = ChangeLedger::get( (string) $restored['rollback_change_id'] );
		self::assertSame( 'rollback', $rollback['kind'] );
		self::assertSame( $row['change_id'], $rollback['parent_id'] );
		self::assertSame( self::AFTER, ChangeLedger::read_image( $rollback['change_id'], 'before' )['body'] );
		self::assertSame( self::BEFORE, ChangeLedger::read_image( $rollback['change_id'], 'after' )['body'] );
	}

	public function test_restore_a_code_snippets_snippet_through_its_provider(): void {
		$this->apply( 'code-snippets', '7', self::AFTER, 'code-snippets/snippet/7' );

		$restored = CodeAdapter::restore( $this->only_row()['change_id'] );

		self::assertSame( 'succeeded', $restored['status'], $restored['detail'] );
		self::assertSame( self::BEFORE, $this->snippets['7']['code'] );
	}

	public function test_restore_refuses_when_the_snippet_changed_since_the_expected_hash(): void {
		$this->apply( 'wpcode', '12', self::AFTER, 'wpcode/snippet/12' );
		$row = $this->only_row();
		$this->wpcode['12']['code'] = "<?php\necho 'edited by hand';\n";

		$restored = CodeAdapter::restore_snippet( $row['change_id'], [ 'expected_current_sha256' => $row['after_sha256'] ] );

		self::assertSame( 'failed', $restored['status'] );
		self::assertSame( 'current_changed', $restored['detail'] );
		self::assertSame( "<?php\necho 'edited by hand';\n", $this->wpcode['12']['code'] );
	}

	public function test_restore_fails_cleanly_when_the_provider_is_gone(): void {
		$this->apply( 'wpcode', '12', self::AFTER, 'wpcode/snippet/12' );
		$row = $this->only_row();
		ProviderRegistry::set_for_tests( [] );

		$restored = CodeAdapter::restore_snippet( $row['change_id'] );

		self::assertSame( 'failed', $restored['status'] );
		self::assertSame( 'provider_missing', $restored['detail'] );
	}

	public function test_the_live_hash_of_a_snippet_follows_its_body(): void {
		$this->apply( 'wpcode', '12', self::AFTER, 'wpcode/snippet/12' );
		$row = $this->only_row();

		self::assertSame( $row['after_sha256'], CodeAdapter::live_sha256( $row['change_id'] ) );

		$this->wpcode['12']['code'] = 'changed';
		self::assertNotSame( $row['after_sha256'], CodeAdapter::live_sha256( $row['change_id'] ) );
	}

	// ---- backends -------------------------------------------------------------------------------------------

	/** @return callable */
	private function wpcode_backend(): callable {
		return function ( string $op, array $args ) {
			switch ( $op ) {
				case 'discover':
					return [ 'active' => true, 'version' => '2.2.0' ];
				case 'read':
					$id = (string) ( $args['id'] ?? '' );
					return isset( $this->wpcode[ $id ] ) ? array_merge( [ 'id' => $id ], $this->wpcode[ $id ] ) : new \WP_Error( 'stonewright_wpcode_not_found', 'missing', [ 'status' => 404 ] );
				case 'list_active':
					$items = [];
					foreach ( $this->wpcode as $id => $row ) {
						if ( ! empty( $row['active'] ) && 'php' === $row['language'] ) {
							$items[] = [ 'id' => (string) $id, 'title' => $row['title'], 'language' => 'php', 'active' => true, 'code' => $row['code'] ];
						}
					}
					return [ 'ok' => true, 'count' => count( $items ), 'items' => $items ];
				case 'assemble_runtime':
					$parts = [];
					foreach ( (array) ( $args['snippets'] ?? [] ) as $snippet ) {
						$parts[] = (string) ( $snippet['code'] ?? '' );
					}
					$payload = implode( "\n", $parts );
					return [ 'ok' => true, 'count' => count( $parts ), 'sha256' => hash( 'sha256', $payload ), 'payload' => $payload ];
				case 'lint_runtime':
					return [ 'ok' => true, 'sha256' => hash( 'sha256', (string) ( $args['payload'] ?? '' ) ) ];
				case 'native_save':
					if ( $this->save_fails ) {
						return new \WP_Error( 'stonewright_wpcode_native_save_failed', 'save failed', [ 'status' => 500 ] );
					}
					$this->wpcode[ (string) $args['id'] ]['code'] = (string) $args['code'];
					return true;
				case 'rebuild_cache':
					$this->cache = [];
					if ( $this->cache_is_stale ) {
						// The first rebuild leaves the cache empty; the next one (the rollback's) works.
						$this->cache_is_stale = false;
						return true;
					}
					foreach ( $this->wpcode as $id => $row ) {
						if ( ! empty( $row['active'] ) ) {
							$this->cache[ (string) $id ] = hash( 'sha256', (string) $row['code'] );
						}
					}
					return true;
				case 'inspect_cache':
					$id = (string) ( $args['id'] ?? '' );
					return [ 'ok' => true, 'member' => isset( $this->cache[ $id ] ), 'count' => count( $this->cache ) ];
			}
			return null;
		};
	}

	/** @return callable */
	private function snippets_backend(): callable {
		return function ( string $op, array $args ) {
			if ( 'discover' === $op ) {
				return [ 'active' => true, 'version' => '3.6.5' ];
			}
			if ( 'read' === $op ) {
				$id = (string) ( $args['id'] ?? '' );
				return isset( $this->snippets[ $id ] ) ? array_merge( [ 'id' => $id ], $this->snippets[ $id ] ) : new \WP_Error( 'stonewright_code_snippets_not_found', 'missing', [ 'status' => 404 ] );
			}
			if ( 'save' === $op ) {
				$this->snippets[ (string) $args['id'] ]['code'] = (string) $args['code'];
				return true;
			}
			return null;
		};
	}
}
