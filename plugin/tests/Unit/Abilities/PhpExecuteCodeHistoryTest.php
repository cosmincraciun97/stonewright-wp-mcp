<?php
/**
 * The redacted, size-capped copy of the code php-execute ran.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Abilities;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\Runtime\PhpExecute;
use Stonewright\WpMcp\Security\AuditLog;
use Stonewright\WpMcp\Security\BlobStore;
use Stonewright\WpMcp\Security\ChangeLedger;
use Stonewright\WpMcp\Security\RuntimeCodeHistory;

/**
 * @covers \Stonewright\WpMcp\Security\RuntimeCodeHistory
 * @covers \Stonewright\WpMcp\Abilities\Runtime\PhpExecute
 */
final class PhpExecuteCodeHistoryTest extends TestCase {

	private string $uploads = '';

	private mixed $wpdb_before = null;

	protected function setUp(): void {
		$this->wpdb_before = $GLOBALS['wpdb'] ?? null;
		$this->uploads = str_replace( '\\', '/', sys_get_temp_dir() ) . '/sw-code-history-' . bin2hex( random_bytes( 5 ) );
		mkdir( $this->uploads, 0777, true );
		$GLOBALS['stonewright_test_upload_dir']      = [
			'basedir' => $this->uploads,
			'baseurl' => 'https://example.test/wp-content/uploads',
			'error'   => false,
		];
		$GLOBALS['stonewright_test_user_caps']       = [
			'read'           => true,
			'manage_options' => true,
			'edit_plugins'   => true,
		];
		$GLOBALS['stonewright_test_user_logged_in']  = true;
		$GLOBALS['stonewright_test_current_user_id'] = 17;
		$GLOBALS['stonewright_test_wpdb_inserts']    = [];
		$GLOBALS['stonewright_test_options']         = [
			'stonewright_mode'                 => 'development',
			'stonewright_essential_tools_mode' => true,
			'stonewright_disabled_abilities'   => [],
		];
		$GLOBALS['stonewright_test_transients']      = [];
		RuntimeCodeHistory::use_blob_store_for_tests( null );
	}

	protected function tearDown(): void {
		RuntimeCodeHistory::use_blob_store_for_tests( null );
		unset( $GLOBALS['stonewright_test_upload_dir'] );
		$GLOBALS['wpdb'] = $this->wpdb_before;
		$GLOBALS['stonewright_test_user_caps']      = [];
		$GLOBALS['stonewright_test_user_logged_in'] = false;
		$GLOBALS['stonewright_test_wpdb_inserts']   = [];
		$GLOBALS['stonewright_test_options']        = [];
		$GLOBALS['stonewright_test_transients']     = [];
		self::remove_tree( $this->uploads );
	}

	private static function remove_tree( string $path ): void {
		if ( is_link( $path ) || is_file( $path ) ) {
			@unlink( $path );
			return;
		}
		if ( ! is_dir( $path ) ) {
			return;
		}
		foreach ( scandir( $path ) ?: [] as $item ) {
			if ( '.' !== $item && '..' !== $item ) {
				self::remove_tree( $path . '/' . $item );
			}
		}
		@rmdir( $path );
	}

	private function runtime_dir(): string {
		return $this->uploads . '/stonewright-state/runtime';
	}

	/** @return list<string> */
	private function runtime_blobs(): array {
		return array_map( 'basename', glob( $this->runtime_dir() . '/*.gz' ) ?: [] );
	}

	/**
	 * The `_meta` of the audit row the last php-execute call wrote.
	 *
	 * @return array<string, mixed>
	 */
	private function audit_meta(): array {
		$inserts = array_values(
			array_filter(
				$GLOBALS['stonewright_test_wpdb_inserts'],
				static fn( array $insert ): bool => 'stonewright/php-execute' === ( $insert['data']['ability_name'] ?? null )
			)
		);
		self::assertNotEmpty( $inserts, 'php-execute wrote an audit row' );
		$args = json_decode( (string) end( $inserts )['data']['sanitized_args'], true );
		self::assertIsArray( $args );
		return is_array( $args['_meta'] ?? null ) ? $args['_meta'] : [];
	}

	public function test_executed_code_is_kept_redacted_under_the_size_cap_and_referenced_from_the_audit_row(): void {
		$code   = "// synthetic runtime note\nreturn 2 + 3;";
		$result = ( new PhpExecute() )->execute( [ 'code' => $code ] );

		self::assertIsArray( $result );
		self::assertTrue( $result['ok'] );
		$meta = $this->audit_meta();
		self::assertSame( hash( 'sha256', $code ), $meta['code_sha256'] );
		self::assertSame( strlen( $code ), $meta['code_bytes'] );
		self::assertFalse( $meta['code_truncated'] );
		self::assertTrue( BlobStore::is_valid_hash( (string) $meta['code_ref'] ) );
		self::assertSame( $code, RuntimeCodeHistory::read( (string) $meta['code_ref'] ) );
		self::assertSame( [ $meta['code_ref'] . '.gz' ], $this->runtime_blobs(), 'one blob, under uploads/stonewright-state/runtime/' );

		$padded = "return 1;\n// " . str_repeat( 'x', RuntimeCodeHistory::MAX_BYTES + 5000 );
		$result = ( new PhpExecute() )->execute( [ 'code' => $padded ] );

		self::assertIsArray( $result );
		$meta = $this->audit_meta();
		self::assertSame( hash( 'sha256', $padded ), $meta['code_sha256'], 'the hash is of the whole code that ran' );
		self::assertSame( strlen( $padded ), $meta['code_bytes'], 'the byte count is of the whole code that ran' );
		self::assertTrue( $meta['code_truncated'] );
		$kept = RuntimeCodeHistory::read( (string) $meta['code_ref'] );
		self::assertIsString( $kept );
		self::assertSame( RuntimeCodeHistory::MAX_BYTES, strlen( $kept ) );
		self::assertSame( substr( $padded, 0, RuntimeCodeHistory::MAX_BYTES ), $kept );
	}

	public function test_secrets_are_masked_in_the_kept_copy_but_the_original_hash_is_recorded(): void {
		$code = "\$password = 'fixture-horse-battery';\n\$headers = [ 'Authorization' => 'Bearer fixture-ABCDEF0123' ];\nreturn strlen( \$password );";

		$result = ( new PhpExecute() )->execute( [ 'code' => $code ] );

		self::assertIsArray( $result );
		self::assertSame( 21, $result['result'], 'the original code ran' );
		$meta = $this->audit_meta();
		self::assertSame( hash( 'sha256', $code ), $meta['code_sha256'] );
		$kept = RuntimeCodeHistory::read( (string) $meta['code_ref'] );
		self::assertSame( "\$password = 'fi…ry';\n\$headers = [ 'Authorization' => 'Be…23' ];\nreturn strlen( \$password );", $kept );
		self::assertNotSame( $meta['code_sha256'], $meta['code_ref'], 'the reference names the redacted copy, not the original code' );
		foreach ( $this->runtime_blobs() as $file ) {
			self::assertStringNotContainsString( 'fixture-horse', (string) gzdecode( (string) file_get_contents( $this->runtime_dir() . '/' . $file ) ) );
		}
		$stored_row = (string) end( $GLOBALS['stonewright_test_wpdb_inserts'] )['data']['sanitized_args'];
		self::assertStringNotContainsString( 'fixture-horse', $stored_row );
	}

	public function test_a_secret_that_straddles_the_size_cap_is_not_kept_in_part(): void {
		$code = "return 1;
// " . str_repeat( 'y', RuntimeCodeHistory::MAX_BYTES - 60 )
			. "
\$password = 'fixture-straddling-secret-value-0123456789-tail';
" . str_repeat( 'z', 200 );

		( new PhpExecute() )->execute( [ 'code' => $code ] );

		$meta = $this->audit_meta();
		self::assertTrue( $meta['code_truncated'] );
		$kept = (string) RuntimeCodeHistory::read( (string) $meta['code_ref'] );
		self::assertSame( RuntimeCodeHistory::MAX_BYTES, strlen( $kept ) );
		self::assertStringContainsString( "\$password = 'fi…il';", $kept );
		self::assertStringNotContainsString( 'straddling', $kept );
		self::assertStringNotContainsString( 'secret-value', $kept );
	}

	public function test_the_setting_off_keeps_only_the_hash(): void {
		$GLOBALS['stonewright_test_options'][ RuntimeCodeHistory::OPTION ] = false;
		$code = 'return 7;';

		$result = ( new PhpExecute() )->execute( [ 'code' => $code ] );

		self::assertIsArray( $result );
		$meta = $this->audit_meta();
		self::assertSame( hash( 'sha256', $code ), $meta['code_sha256'] );
		self::assertArrayNotHasKey( 'code_ref', $meta );
		self::assertArrayNotHasKey( 'code_bytes', $meta );
		self::assertArrayNotHasKey( 'code_truncated', $meta );
		self::assertSame( [], $this->runtime_blobs() );
		self::assertDirectoryDoesNotExist( $this->runtime_dir() );
	}

	public function test_a_snippet_cannot_keep_its_own_code_out_of_the_copy(): void {
		$code = 'update_option( "' . RuntimeCodeHistory::OPTION . '", false ); return "turned off from inside";';

		$result = ( new PhpExecute() )->execute( [ 'code' => $code ] );

		self::assertIsArray( $result );
		self::assertSame( 'turned off from inside', $result['result'] );
		self::assertFalse( (bool) get_option( RuntimeCodeHistory::OPTION, true ), 'the snippet did turn the setting off' );
		$meta = $this->audit_meta();
		self::assertSame( $code, RuntimeCodeHistory::read( (string) $meta['code_ref'] ), 'the copy was decided before it ran' );
	}

	public function test_the_copy_is_stored_before_the_snippet_runs(): void {
		$code    = '$count = count( glob( ' . var_export( $this->runtime_dir() . '/*.gz', true ) . ' ) ?: [] ); return $count;';
		$result  = ( new PhpExecute() )->execute( [ 'code' => $code ] );

		self::assertIsArray( $result );
		self::assertSame( 1, $result['result'], 'the snippet found its own copy already on disk' );
	}

	public function test_a_copy_that_was_due_and_could_not_be_stored_says_so_in_the_row(): void {
		RuntimeCodeHistory::use_blob_store_for_tests( new BlobStore( $this->runtime_dir(), 10 ) );

		( new PhpExecute() )->execute( [ 'code' => 'return 5;' ] );

		$meta = $this->audit_meta();
		self::assertFalse( $meta['code_kept'] );
		self::assertArrayNotHasKey( 'code_ref', $meta );
		self::assertSame( hash( 'sha256', 'return 5;' ), $meta['code_sha256'] );
	}

	public function test_the_setting_off_leaves_no_code_kept_marker(): void {
		$GLOBALS['stonewright_test_options'][ RuntimeCodeHistory::OPTION ] = false;

		( new PhpExecute() )->execute( [ 'code' => 'return 5;' ] );

		self::assertArrayNotHasKey( 'code_kept', $this->audit_meta() );
	}

	public function test_a_full_folder_deletes_the_oldest_copies_to_make_room_for_the_newest(): void {
		$first = RuntimeCodeHistory::keep( 'return "first";' );
		self::assertIsArray( $first );
		$one_blob = (int) filesize( $this->runtime_dir() . '/' . $first['ref'] . '.gz' );
		RuntimeCodeHistory::use_blob_store_for_tests( new BlobStore( $this->runtime_dir(), ( $one_blob * 2 ) + 8 ) );
		$second = RuntimeCodeHistory::keep( 'return "second";' );
		self::assertIsArray( $second );
		touch( $this->runtime_dir() . '/' . $first['ref'] . '.gz', time() - 3000 );
		touch( $this->runtime_dir() . '/' . $second['ref'] . '.gz', time() - 2000 );

		$third = RuntimeCodeHistory::keep( 'return "third";' );

		self::assertIsArray( $third );
		self::assertNull( RuntimeCodeHistory::read( $first['ref'] ), 'the oldest copy made room' );
		self::assertSame( 'return "second";', RuntimeCodeHistory::read( $second['ref'] ) );
		self::assertSame( 'return "third";', RuntimeCodeHistory::read( $third['ref'] ) );
	}

	public function test_the_setting_is_on_by_default_and_a_stored_zero_turns_it_off(): void {
		unset( $GLOBALS['stonewright_test_options'][ RuntimeCodeHistory::OPTION ] );
		self::assertTrue( RuntimeCodeHistory::enabled() );

		$GLOBALS['stonewright_test_options'][ RuntimeCodeHistory::OPTION ] = '0';
		self::assertFalse( RuntimeCodeHistory::enabled() );
		self::assertNull( RuntimeCodeHistory::keep( 'return 1;' ) );

		$GLOBALS['stonewright_test_options'][ RuntimeCodeHistory::OPTION ] = '1';
		self::assertTrue( RuntimeCodeHistory::enabled() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_constant_set_to_false_turns_it_off_whatever_the_setting_says(): void {
		$GLOBALS['stonewright_test_options'][ RuntimeCodeHistory::OPTION ] = '1';
		define( 'STONEWRIGHT_PHP_EXECUTE_KEEP_CODE', false );

		self::assertFalse( RuntimeCodeHistory::enabled() );
		self::assertNull( RuntimeCodeHistory::keep( 'return 1;' ) );
		self::assertSame( [], $this->runtime_blobs() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_constant_set_to_true_does_not_override_the_setting(): void {
		$GLOBALS['stonewright_test_options'][ RuntimeCodeHistory::OPTION ] = '0';
		define( 'STONEWRIGHT_PHP_EXECUTE_KEEP_CODE', true );

		self::assertFalse( RuntimeCodeHistory::enabled() );
	}

	public function test_blocked_executions_are_kept_too(): void {
		$code = 'update_post_meta(8170, "_elementor_data", "[]"); return true;';

		$blocked = ( new PhpExecute() )->execute( [ 'code' => $code ] );

		self::assertInstanceOf( \WP_Error::class, $blocked );
		$meta = $this->audit_meta();
		self::assertSame( 'blocked', $meta['execution_status'] );
		self::assertSame( hash( 'sha256', $code ), $meta['code_sha256'] );
		self::assertSame( $code, RuntimeCodeHistory::read( (string) $meta['code_ref'] ) );
	}

	public function test_an_execution_refused_for_a_missing_confirmation_token_is_kept_too(): void {
		$GLOBALS['stonewright_test_options']['stonewright_mode'] = 'production-safe';
		$code = 'return get_option( "blogname" );';

		$blocked = ( new PhpExecute() )->execute( [ 'code' => $code ] );

		self::assertInstanceOf( \WP_Error::class, $blocked );
		self::assertSame( 'stonewright_confirmation_required', $blocked->get_error_code() );
		$meta = $this->audit_meta();
		self::assertSame( $code, RuntimeCodeHistory::read( (string) $meta['code_ref'] ) );
	}

	public function test_a_failing_snippet_is_kept_with_its_error(): void {
		$code = 'throw new \RuntimeException( "synthetic failure" );';

		$result = ( new PhpExecute() )->execute( [ 'code' => $code ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		$meta = $this->audit_meta();
		self::assertSame( 'error', $meta['execution_status'] );
		self::assertSame( $code, RuntimeCodeHistory::read( (string) $meta['code_ref'] ) );
	}

	public function test_the_same_code_run_twice_keeps_one_blob(): void {
		$first  = RuntimeCodeHistory::keep( 'return 1;' );
		$second = RuntimeCodeHistory::keep( 'return 1;' );

		self::assertIsArray( $first );
		self::assertSame( $first, $second );
		self::assertCount( 1, $this->runtime_blobs() );
	}

	public function test_only_an_administrator_reads_a_kept_copy(): void {
		$kept = RuntimeCodeHistory::keep( 'return 1;' );
		self::assertIsArray( $kept );

		self::assertSame( 'return 1;', RuntimeCodeHistory::read( $kept['ref'] ) );

		$GLOBALS['stonewright_test_user_caps']['manage_options'] = false;
		self::assertNull( RuntimeCodeHistory::read( $kept['ref'] ) );
	}

	public function test_a_reference_that_is_not_a_kept_blob_reads_as_nothing(): void {
		self::assertNull( RuntimeCodeHistory::read( 'not-a-hash' ) );
		self::assertNull( RuntimeCodeHistory::read( '../../../wp-config' ) );
		self::assertNull( RuntimeCodeHistory::read( str_repeat( 'a', 64 ) ), 'a well-formed name with no file behind it' );
		self::assertNull( RuntimeCodeHistory::read( '' ) );
	}

	public function test_the_store_has_its_own_folder_apart_from_the_change_ledger_blobs(): void {
		$runtime = RuntimeCodeHistory::blob_store();
		$ledger  = ChangeLedger::blob_store();

		self::assertNotNull( $runtime );
		self::assertNotNull( $ledger );
		self::assertSame( $this->uploads . '/stonewright-state/runtime', $runtime->root() );
		self::assertNotSame( $ledger->root(), $runtime->root() );

		RuntimeCodeHistory::keep( 'return 1;' );
		self::assertSame( [], $ledger->hashes(), 'the ledger retention never sees a code blob, so it cannot treat one as an orphan' );
	}

	public function test_a_full_store_keeps_nothing_and_does_not_stop_the_snippet(): void {
		RuntimeCodeHistory::use_blob_store_for_tests( new BlobStore( $this->runtime_dir(), 10 ) );

		self::assertNull( RuntimeCodeHistory::keep( 'return 1; // ' . str_repeat( 'z', 500 ) ) );

		$result = ( new PhpExecute() )->execute( [ 'code' => 'return 5;' ] );
		self::assertIsArray( $result );
		self::assertSame( 5, $result['result'] );
		self::assertArrayNotHasKey( 'code_ref', $this->audit_meta() );
	}

	public function test_an_unusable_uploads_folder_keeps_nothing(): void {
		$GLOBALS['stonewright_test_upload_dir'] = [ 'basedir' => '', 'error' => 'no uploads' ];

		self::assertNull( RuntimeCodeHistory::keep( 'return 1;' ) );
	}

	public function test_a_cut_never_splits_a_multibyte_character(): void {
		$code = str_repeat( 'a', RuntimeCodeHistory::MAX_BYTES - 1 ) . 'é' . 'tail';

		$kept = RuntimeCodeHistory::keep( $code );

		self::assertIsArray( $kept );
		self::assertTrue( $kept['truncated'] );
		self::assertSame( strlen( $code ), $kept['bytes'] );
		$text = (string) RuntimeCodeHistory::read( $kept['ref'] );
		self::assertTrue( mb_check_encoding( $text, 'UTF-8' ) );
		self::assertSame( RuntimeCodeHistory::MAX_BYTES - 1, strlen( $text ) );
	}

	public function test_retention_prunes_code_older_than_the_audit_window(): void {
		$old    = RuntimeCodeHistory::keep( 'return "old";' );
		$recent = RuntimeCodeHistory::keep( 'return "recent";' );
		self::assertIsArray( $old );
		self::assertIsArray( $recent );
		touch( $this->runtime_dir() . '/' . $old['ref'] . '.gz', time() - ( 8 * DAY_IN_SECONDS ) );
		touch( $this->runtime_dir() . '/' . $recent['ref'] . '.gz', time() - ( 6 * DAY_IN_SECONDS ) );
		$GLOBALS['stonewright_test_options'][ AuditLog::RETENTION_OPTION ] = 7;
		$GLOBALS['wpdb'] = $this->quiet_wpdb();

		AuditLog::run_scheduled_retention();

		self::assertSame( [ $recent['ref'] . '.gz' ], $this->runtime_blobs() );
		self::assertNull( RuntimeCodeHistory::read( $old['ref'] ) );
		self::assertSame( 'return "recent";', RuntimeCodeHistory::read( $recent['ref'] ) );
	}

	public function test_code_run_again_is_kept_for_a_new_window(): void {
		$kept = RuntimeCodeHistory::keep( 'return "again";' );
		self::assertIsArray( $kept );
		touch( $this->runtime_dir() . '/' . $kept['ref'] . '.gz', time() - ( 30 * DAY_IN_SECONDS ) );

		RuntimeCodeHistory::keep( 'return "again";' );
		$GLOBALS['stonewright_test_options'][ AuditLog::RETENTION_OPTION ] = 7;

		self::assertSame( 0, RuntimeCodeHistory::prune_expired() );
		self::assertSame( 'return "again";', RuntimeCodeHistory::read( $kept['ref'] ) );
	}

	public function test_without_a_retention_window_nothing_is_pruned(): void {
		$kept = RuntimeCodeHistory::keep( 'return "kept";' );
		self::assertIsArray( $kept );
		touch( $this->runtime_dir() . '/' . $kept['ref'] . '.gz', time() - ( 400 * DAY_IN_SECONDS ) );
		$GLOBALS['stonewright_test_options'][ AuditLog::RETENTION_OPTION ] = 0;

		self::assertSame( 0, RuntimeCodeHistory::prune_expired() );
		self::assertSame( 'return "kept";', RuntimeCodeHistory::read( $kept['ref'] ) );
	}

	public function test_a_failing_prune_cannot_break_the_daily_job(): void {
		RuntimeCodeHistory::use_blob_store_for_tests(
			new class( $this->runtime_dir() ) extends BlobStore {
				public function sweep( array $referenced, int $min_age ): array {
					throw new \RuntimeException( 'Synthetic sweep failure' );
				}
			}
		);
		$GLOBALS['stonewright_test_options'][ AuditLog::RETENTION_OPTION ] = 7;

		self::assertSame( 0, RuntimeCodeHistory::prune_expired() );
	}

	/** A database that has nothing to repair or delete, for the daily retention job. */
	private function quiet_wpdb(): object {
		return new class() {
			public $prefix = 'wp_';

			public function get_charset_collate(): string {
				return '';
			}

			/** @return list<string> */
			public function get_col( string $query, int $x = 0 ): array {
				return [];
			}

			public function prepare( string $query, mixed ...$args ): string {
				return $query;
			}

			/** @return list<array<string, mixed>> */
			public function get_results( string $query, mixed $output = null ): array {
				return [];
			}

			public function get_var( string $query ): mixed {
				return null;
			}

			public function query( string $query ): int {
				return 0;
			}
		};
	}
}
