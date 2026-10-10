<?php
/**
 * The central hook of the rescue guard for the user, comment, media, catalog and site families: where a
 * change is recorded and settled, and what a ledger failure may not touch.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Security\Adapters;

use Stonewright\WpMcp\Abilities\Users\UserUpdate;
use Stonewright\WpMcp\Security\Adapters\OtherFamilies;
use Stonewright\WpMcp\Security\ChangeLedger;
use Stonewright\WpMcp\Security\RescueGuard;
use Stonewright\WpMcp\Tests\Unit\Security\Fixtures\LedgerWpdb;

/**
 * @covers \Stonewright\WpMcp\Security\RescueGuard
 * @covers \Stonewright\WpMcp\Security\Adapters\OtherFamilies
 * @covers \Stonewright\WpMcp\Security\Adapters\FamilyLedger
 */
final class OtherFamiliesHookTest extends OtherFamilyLedgerTestCase {

	public function test_an_ability_of_no_listed_family_is_not_watched(): void {
		self::assertNull( OtherFamilies::begin( 'stonewright/content-update-page', [ 'id' => 31 ] ) );
		self::assertNull( OtherFamilies::begin( 'stonewright/user-list', [] ) );
		OtherFamilies::finish( null, [ 'ok' => true ] );

		self::assertSame( [], $this->ledger_rows() );
	}

	/**
	 * @return array<string, array{0:string,1:array<string,mixed>}>
	 */
	public static function watched(): array {
		return [
			'user update'        => [ 'stonewright/user-update', [ 'id' => 21 ] ],
			'user delete'        => [ 'stonewright/user-delete', [ 'id' => 21, 'reassign' => 7 ] ],
			'comment update'     => [ 'stonewright/comment-update', [ 'id' => 5 ] ],
			'comment delete'     => [ 'stonewright/comment-delete', [ 'id' => 5 ] ],
			'media alt'          => [ 'stonewright/media-set-alt', [ 'id' => 70, 'alt' => 'x' ] ],
			'media optimize'     => [ 'stonewright/media-optimize', [ 'id' => 70 ] ],
			'product save'       => [ 'stonewright/wc-product-save', [ 'id' => 12 ] ],
			'product delete'     => [ 'stonewright/wc-product-delete', [ 'id' => 12 ] ],
			'variation save'     => [ 'stonewright/wc-variation-save', [ 'id' => 21, 'parent_id' => 20 ] ],
			'term save'          => [ 'stonewright/wc-term-save', [ 'id' => 31, 'taxonomy' => 'product_cat' ] ],
			'attribute delete'   => [ 'stonewright/wc-attribute-delete', [ 'id' => 40 ] ],
			'theme activate'     => [ 'stonewright/theme-activate', [ 'stylesheet' => 'site-b-theme' ] ],
			'plugin delete'      => [ 'stonewright/plugin-delete', [ 'plugin' => 'hello-dolly/hello.php' ] ],
			'php execute'        => [ 'stonewright/php-execute', [ 'code' => 'return 1;' ] ],
			'app passwords'      => [ 'stonewright/user-app-passwords', [ 'action' => 'create', 'user_id' => 21 ] ],
			'user create'        => [ 'stonewright/user-create', [ 'user_login' => 'a', 'user_pass' => 'sentinel-pass-phrase-1' ] ],
			'media upload'       => [ 'stonewright/media-upload', [ 'url' => 'https://example.test/a.jpg' ] ],
		];
	}

	/**
	 * @dataProvider watched
	 * @param array<string, mixed> $args
	 */
	public function test_every_listed_ability_is_watched_and_the_frame_keeps_no_argument_value( string $ability, array $args ): void {
		$args['secret_value'] = 'sentinel-value-in-args-4421';

		RescueGuard::enter( $ability, $args );

		$frames = new \ReflectionProperty( RescueGuard::class, 'frames' );
		$frames->setAccessible( true );
		$json = (string) json_encode( $frames->getValue(), JSON_UNESCAPED_SLASHES );
		self::assertStringContainsString( $ability, $json );
		self::assertStringNotContainsString( 'sentinel-value-in-args-4421', $json );
		self::assertStringNotContainsString( 'sentinel-pass-phrase-1', $json );
		RescueGuard::leave( [ 'ok' => true ] );
	}

	public function test_a_change_is_recorded_when_the_call_ends_and_the_result_is_untouched(): void {
		$this->make_user( 21 );

		$result = $this->call( 'stonewright/user-update', [ 'id' => 21, 'display_name' => 'Alice B' ], function (): void {
			$GLOBALS['stonewright_test_users'][21]['display_name'] = 'Alice B';
		}, [ 'id' => 21 ] );

		self::assertSame( [ 'id' => 21 ], $result );
		$row = $this->row_of( 'user' );
		self::assertSame( [ 'verified', 7 ], [ $row['status'], $row['actor'] ] );
		self::assertNotNull( $row['settled_at'] );
		self::assertSame( 'claude-code/1.4.2', $row['client'] );
	}

	public function test_a_call_that_failed_after_a_change_is_recorded_as_failed(): void {
		$this->make_user( 21 );

		$this->call( 'stonewright/user-update', [ 'id' => 21 ], function (): void {
			$GLOBALS['stonewright_test_users'][21]['display_name'] = 'Half written';
		}, new \WP_Error( 'stonewright_user_update_failed', 'No.' ) );

		$row = $this->row_of( 'user' );
		self::assertSame( 'failed', $row['status'] );
		self::assertTrue( $row['restorable'], 'What changed can still be put back.' );
	}

	public function test_two_calls_nested_in_one_frame_record_their_own_changes(): void {
		$this->make_user( 21 );
		$this->make_user( 22 );

		RescueGuard::enter( 'stonewright/user-update', [ 'id' => 21 ] );
		$GLOBALS['stonewright_test_users'][21]['display_name'] = 'One';
		$this->call( 'stonewright/user-update', [ 'id' => 22 ], function (): void {
			$GLOBALS['stonewright_test_users'][22]['display_name'] = 'Two';
		} );
		RescueGuard::leave( [ 'ok' => true ] );

		self::assertSame( [ '22', '21' ], array_column( $this->ledger_rows(), 'resource_id' ) );
	}

	/**
	 * @return array<string, array{0:string,1:string}>
	 */
	public static function ledger_failures(): array {
		return [
			'database throws'   => [ 'throw', 'change_ledger_record_failed' ],
			'database refuses'  => [ 'refuse', 'change_ledger_record_failed' ],
			'settle throws'     => [ 'throw_update', 'change_ledger_settle_failed' ],
			'settle refuses'    => [ 'refuse_update', 'change_ledger_settle_failed' ],
		];
	}

	/**
	 * @dataProvider ledger_failures
	 */
	public function test_a_ledger_failure_is_logged_and_never_changes_the_write_or_its_result( string $failure, string $event ): void {
		$log   = $this->uploads . '/php-errors.log';
		$saved = (string) ini_get( 'error_log' );
		ini_set( 'error_log', $log );
		$db         = new class() extends PostLedgerWpdb {
			public string $mode = '';

			public function insert( $table, $data, $format = null ) {
				if ( str_contains( (string) $table, 'stonewright_changes' ) ) {
					if ( 'throw' === $this->mode ) {
						throw new \RuntimeException( 'database gone' );
					}
					if ( 'refuse' === $this->mode ) {
						return false;
					}
				}
				return parent::insert( $table, $data, $format );
			}

			public function update( $table, $data, $where, $format = null, $where_format = null ) {
				if ( 'throw_update' === $this->mode ) {
					throw new \RuntimeException( 'database gone' );
				}
				if ( 'refuse_update' === $this->mode ) {
					return false;
				}
				return parent::update( $table, $data, $where, $format, $where_format );
			}
		};
		$db->unique      = $this->db->unique;
		$db->mode        = $failure;
		$GLOBALS['wpdb'] = $db;
		$this->make_user( 21 );

		try {
			$result = ( new UserUpdate() )->execute( [ 'id' => 21, 'role' => 'author' ] );
		} finally {
			ini_set( 'error_log', $saved );
		}

		self::assertSame( [ 'id' => 21 ], $result );
		self::assertSame( [ 'author' ], $this->user( 21 )['roles'], 'The write happened.' );
		self::assertStringContainsString( 'stonewright.' . $event, (string) file_get_contents( $log ), 'The failure is logged.' );
	}

	public function test_a_ledger_that_is_not_installed_records_nothing_and_changes_nothing(): void {
		$GLOBALS['wpdb'] = new LedgerWpdb();
		$this->make_user( 21 );

		$result = ( new UserUpdate() )->execute( [ 'id' => 21, 'role' => 'author' ] );

		self::assertSame( [ 'id' => 21 ], $result );
		self::assertSame( [ 'author' ], $this->user( 21 )['roles'] );
		self::assertArrayNotHasKey( $GLOBALS['wpdb']->prefix . 'stonewright_changes', $GLOBALS['wpdb']->tables );
	}

	public function test_an_adapter_that_cannot_read_the_resource_never_stops_the_call(): void {
		$log   = $this->uploads . '/php-errors.log';
		$saved = (string) ini_get( 'error_log' );
		ini_set( 'error_log', $log );
		$GLOBALS['stonewright_test_users'][21] = new class() {
			public int $ID = 21;

			public function __isset( string $name ): bool {
				return true;
			}

			public function __get( string $name ): never {
				throw new \RuntimeException( 'unreadable ' . $name );
			}
		};

		try {
			$result = $this->call( 'stonewright/user-update', [ 'id' => 21 ], static function (): void {}, [ 'id' => 21 ] );
		} finally {
			ini_set( 'error_log', $saved );
		}

		self::assertSame( [ 'id' => 21 ], $result );
		self::assertSame( [], $this->ledger_rows() );
		self::assertStringContainsString( 'stonewright.change_ledger_', (string) file_get_contents( $log ) );
	}

	public function test_a_row_of_a_resource_no_adapter_here_owns_is_not_available_and_an_unknown_row_fails(): void {
		$row = ChangeLedger::record( [ 'ability' => 'stonewright/content-update-page', 'family' => 'post', 'resource_type' => 'post', 'resource_id' => '31', 'before' => [ 'v' => 1 ] ] );
		self::assertIsArray( $row );

		$restore = OtherFamilies::restore( (string) $row['change_id'] );

		self::assertSame( [ 'not_available', 'unsupported_family' ], [ $restore['status'], $restore['detail'] ] );
		self::assertNull( OtherFamilies::live_sha256( (string) $row['change_id'] ) );
		self::assertSame( [ 'failed', 'change_not_found' ], [ OtherFamilies::restore( 'cs-000000000000000000000000' )['status'], OtherFamilies::restore( 'cs-000000000000000000000000' )['detail'] ] );
		self::assertNull( OtherFamilies::live_sha256( 'cs-000000000000000000000000' ) );
	}

	public function test_the_journal_and_the_post_ledger_are_untouched_by_the_families(): void {
		$this->make_user( 21 );

		$this->call( 'stonewright/user-update', [ 'id' => 21 ], function (): void {
			$GLOBALS['stonewright_test_users'][21]['display_name'] = 'Alice B';
		} );

		self::assertSame( [], \Stonewright\WpMcp\Security\ChangeJournal::recent(), 'The families arm no journal entry and run no probe.' );
		self::assertSame( 1, ChangeLedger::count() );
	}
}
