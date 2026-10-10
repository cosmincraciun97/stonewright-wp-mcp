<?php
/**
 * Shared fixture for the user, comment, media, catalog, site and memory ledger tests.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Security\Adapters;

use Stonewright\WpMcp\Security\Adapters\FamilyLedger;
use Stonewright\WpMcp\Security\Adapters\OtherFamilies;
use Stonewright\WpMcp\Security\RescueGuard;

require_once dirname( __DIR__, 3 ) . '/Support/other-families-wp-stubs.php';

/**
 * The ledger is the real ChangeLedger over the in-memory table of PostLedgerTestCase. Users, comments,
 * attachments and the rest are the fakes of the shared bootstrap, plus the few stubs of
 * other-families-wp-stubs.php. The current user (7) may do everything unless a test says otherwise.
 */
abstract class OtherFamilyLedgerTestCase extends PostLedgerTestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['stonewright_test_users']              = [];
		$GLOBALS['stonewright_test_missing_user_ids']   = [];
		$GLOBALS['stonewright_test_user_meta']          = [];
		$GLOBALS['stonewright_test_user_post_counts']   = [];
		$GLOBALS['stonewright_test_next_user_id']       = 50;
		$GLOBALS['stonewright_test_comments']           = [];
		$GLOBALS['stonewright_test_next_comment_id']    = 5001;
		$GLOBALS['stonewright_test_deleted_attachments'] = [];
		$GLOBALS['stonewright_test_wc_attributes']      = [];
		$GLOBALS['stonewright_test_stylesheet']         = 'site-a-theme';
		$GLOBALS['stonewright_test_template']           = 'site-a-theme';
		FamilyLedger::reset_for_tests();
		OtherFamilies::reset_for_tests();
	}

	protected function tearDown(): void {
		FamilyLedger::reset_for_tests();
		OtherFamilies::reset_for_tests();
		$GLOBALS['stonewright_test_users']            = [];
		$GLOBALS['stonewright_test_missing_user_ids'] = [];
		$GLOBALS['stonewright_test_user_meta']        = [];
		$GLOBALS['stonewright_test_comments']         = [];
		$GLOBALS['stonewright_test_wc_attributes']    = [];
		unset( $GLOBALS['stonewright_test_stylesheet'], $GLOBALS['stonewright_test_template'] );
		parent::tearDown();
	}

	/**
	 * What an ability does between the start and the end of its call.
	 *
	 * @param array<string, mixed> $args
	 * @param callable():void      $write
	 * @return mixed What RescueGuard::leave returns.
	 */
	protected function call( string $ability, array $args, callable $write, mixed $result = [ 'ok' => true ] ): mixed {
		RescueGuard::enter( $ability, $args );
		$write();
		return RescueGuard::leave( $result );
	}

	/**
	 * @param array<string, mixed> $overrides
	 */
	protected function make_user( int $id, array $overrides = [] ): void {
		$GLOBALS['stonewright_test_users'][ $id ] = array_merge(
			[
				'ID'                  => $id,
				'user_login'          => 'alice' . $id,
				'user_email'          => 'alice' . $id . '@example.test',
				'display_name'        => 'Alice ' . $id,
				'user_nicename'       => 'alice' . $id,
				'user_url'            => '',
				'user_registered'     => '2026-01-02 03:04:05',
				'roles'               => [ 'editor' ],
				'user_pass'           => 'sentinel-password-hash-' . $id,
				'user_activation_key' => 'activationsecret' . $id,
			],
			$overrides
		);
	}

	/** @return array<string, mixed> */
	protected function user( int $id ): array {
		return (array) ( $GLOBALS['stonewright_test_users'][ $id ] ?? [] );
	}

	protected function user_exists( int $id ): bool {
		return isset( $GLOBALS['stonewright_test_users'][ $id ] );
	}

	protected function remove_user( int $id ): void {
		unset( $GLOBALS['stonewright_test_users'][ $id ] );
	}

	/**
	 * Every byte the ledger keeps: the rows, the audit rows next to them, and every blob, decompressed.
	 */
	protected function stored_text(): string {
		$text = (string) json_encode( $this->db->tables );
		foreach ( $this->blob_files() as $file ) {
			$bytes = (string) file_get_contents( $file );
			$plain = @gzdecode( $bytes );
			$text .= "\n" . ( false === $plain ? $bytes : $plain );
		}
		return $text;
	}

	/**
	 * What the change ledger itself keeps: its rows and its blobs, without the audit rows of the abilities.
	 */
	protected function ledger_text(): string {
		$text = (string) json_encode( $this->db->tables[ $this->db->prefix . 'stonewright_changes' ] ?? [] );
		foreach ( $this->blob_files() as $file ) {
			$bytes = (string) file_get_contents( $file );
			$plain = @gzdecode( $bytes );
			$text .= "\n" . ( false === $plain ? $bytes : $plain );
		}
		return $text;
	}

	/** @return list<string> */
	protected function blob_files(): array {
		$files    = [];
		$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $this->uploads, \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $iterator as $file ) {
			if ( $file->isFile() ) {
				$files[] = $file->getPathname();
			}
		}
		return $files;
	}

	/**
	 * @return array<string, mixed>
	 */
	protected function row_of( string $family ): array {
		$found = array_values( array_filter( $this->ledger_rows(), static fn ( array $row ): bool => $row['family'] === $family && 'change' === $row['kind'] ) );
		self::assertCount( 1, $found, 'One ledger row of the ' . $family . ' family was expected.' );
		return $found[0];
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	protected function rows_of_kind( string $kind ): array {
		return array_values( array_filter( $this->ledger_rows(), static fn ( array $row ): bool => $kind === $row['kind'] ) );
	}
}
