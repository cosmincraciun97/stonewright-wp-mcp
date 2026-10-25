<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Cli;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Cli\CleanupCommand;
use Stonewright\WpMcp\Core\Uninstaller;
use Stonewright\WpMcp\Tests\Unit\Authorization\WordPress\Fixtures\StorageRig;
use Stonewright\WpMcp\Tests\Unit\Core\Fixtures\UninstallSite;
use Stonewright\WpMcp\Tests\Unit\Core\Fixtures\UninstallWpdb;

/**
 * wp stonewright cleanup: runtime state by default, all data only when asked twice.
 *
 * @covers \Stonewright\WpMcp\Cli\CleanupCommand
 */
final class CleanupCommandTest extends TestCase {

	private UninstallWpdb $wpdb;

	private mixed $previous_wpdb = null;

	protected function setUp(): void {
		$this->previous_wpdb = $GLOBALS['wpdb'] ?? null;
		StorageRig::reset_globals();
		$this->wpdb      = UninstallSite::reset();
		$GLOBALS['wpdb'] = $this->wpdb;
	}

	protected function tearDown(): void {
		$GLOBALS['wpdb'] = $this->previous_wpdb;
		unset( $GLOBALS['stonewright_test_is_multisite'] );
		StorageRig::reset_globals();
	}

	/** @param array<string, mixed> $assoc */
	private function run_command( array $assoc ): object {
		$command = new class() extends CleanupCommand {

			/** @var list<string> */
			public array $printed = [];

			/** @var list<bool> One entry per deactivation, holding whether it was for the network. */
			public array $deactivations = [];

			protected function line( string $text ): void {
				$this->printed[] = $text;
			}

			protected function success( string $text ): void {
				$this->printed[] = $text;
			}

			protected function deactivate( bool $network ): void {
				$this->deactivations[] = $network;
			}

			protected function fail( string $text, int $code = 1 ): never {
				throw new \RuntimeException( $text, $code );
			}
		};
		$command( [], $assoc );
		return $command;
	}

	public function test_cleanup_command_without_all_data_never_drops_a_table(): void {
		$tables  = $this->wpdb->tables;
		$options = $GLOBALS['stonewright_test_options'];

		$command = $this->run_command( [] );

		self::assertSame( $tables, $this->wpdb->tables );
		foreach ( $this->wpdb->statements as $statement ) {
			self::assertStringNotContainsString( 'DROP', $statement );
		}
		$removed = array_diff( array_keys( $options ), array_keys( $GLOBALS['stonewright_test_options'] ) );
		self::assertContains( 'stonewright_elementor_editor_baseline_42', $removed, 'the runtime state went' );
		self::assertContains( '_transient_stonewright_oauth_schema_backoff', $removed );
		self::assertNotContains( 'stonewright_oauth_private_key', $removed, 'the OAuth key stayed' );
		self::assertNotContains( 'stonewright_settings', $removed );
		self::assertSame( UninstallSite::PLUGIN_HOOKS, array_intersect( UninstallSite::PLUGIN_HOOKS, array_keys( $GLOBALS['stonewright_test_scheduled_hooks'] ) ), 'the scheduled events stay while the plugin is active' );
		self::assertSame( [], $command->deactivations, 'the plugin stays active' );
		self::assertStringContainsString( sprintf( 'Removed %d runtime rows', count( $removed ) ), implode( "\n", $command->printed ) );
	}

	public function test_all_data_without_yes_changes_nothing_and_says_how_to_confirm(): void {
		$tables  = $this->wpdb->tables;
		$options = $GLOBALS['stonewright_test_options'];

		try {
			$this->run_command( [ 'all-data' => true ] );
			self::fail( 'The command must refuse without --yes.' );
		} catch ( \RuntimeException $refusal ) {
			self::assertStringContainsString( '--all-data --yes', $refusal->getMessage() );
		}

		self::assertSame( $tables, $this->wpdb->tables );
		self::assertSame( $options, $GLOBALS['stonewright_test_options'] );
		self::assertSame( [], $this->wpdb->statements, 'not even a read' );
	}

	public function test_all_data_with_yes_deactivates_the_plugin_and_removes_every_table_option_and_event_of_the_plugin_and_nothing_else(): void {
		$command = $this->run_command( [ 'all-data' => true, 'yes' => true ] );

		self::assertSame( [ false ], $command->deactivations, 'an active plugin would create its tables and keys again on the next request' );
		self::assertSame( [ 'options', 'postmeta', 'posts', 'stonewright_not_in_the_list', 'users' ], UninstallSite::remaining_tables( 1 ) );
		self::assertSame( UninstallSite::FOREIGN_OPTIONS, array_keys( $GLOBALS['stonewright_test_options'] ) );
		self::assertSame( UninstallSite::FOREIGN_HOOKS, array_keys( $GLOBALS['stonewright_test_scheduled_hooks'] ) );
		self::assertStringContainsString( sprintf( 'Dropped the %d Stonewright tables', count( Uninstaller::TABLES ) ), implode( "\n", $command->printed ) );
		self::assertStringContainsString( 'its files and the rescue helper were not touched', implode( "\n", $command->printed ) );
	}

	public function test_yes_alone_removes_the_runtime_state_only(): void {
		$tables = $this->wpdb->tables;

		$command = $this->run_command( [ 'yes' => true ] );

		self::assertSame( $tables, $this->wpdb->tables );
		self::assertArrayHasKey( 'stonewright_oauth_private_key', $GLOBALS['stonewright_test_options'] );
		self::assertSame( [], $command->deactivations );
	}

	public function test_on_a_network_all_data_acts_on_the_current_site_only_unless_network_is_given(): void {
		$GLOBALS['wpdb']                          = UninstallSite::reset( 1, 2, 3 );
		$GLOBALS['stonewright_test_is_multisite'] = true;

		$command = $this->run_command( [ 'all-data' => true, 'yes' => true ] );

		self::assertSame( [ false ], $command->deactivations );
		self::assertSame( UninstallSite::FOREIGN_OPTIONS, array_keys( UninstallSite::options( 1 ) ), 'the selected site is cleaned' );
		foreach ( [ 2, 3 ] as $site ) {
			self::assertSame( array_merge( UninstallSite::PLUGIN_TABLES, UninstallSite::FOREIGN_TABLES ), array_values( array_intersect( array_merge( UninstallSite::PLUGIN_TABLES, UninstallSite::FOREIGN_TABLES ), UninstallSite::remaining_tables( $site ) ) ), 'tables of site ' . $site );
			self::assertContains( 'stonewright_oauth_private_key', array_keys( UninstallSite::options( $site ) ), 'the keys of site ' . $site . ' stay' );
		}
		self::assertSame( [], array_filter( UninstallSite::$log, static fn ( string $entry ): bool => str_starts_with( $entry, 'switch:' ) ), 'no site was switched to' );
	}

	public function test_network_makes_all_data_act_on_every_site(): void {
		$GLOBALS['wpdb']                          = UninstallSite::reset( 1, 2, 3 );
		$GLOBALS['stonewright_test_is_multisite'] = true;

		$command = $this->run_command( [ 'all-data' => true, 'yes' => true, 'network' => true ] );

		self::assertSame( [ true ], $command->deactivations );
		foreach ( [ 1, 2, 3 ] as $site ) {
			self::assertSame( [ 'options', 'postmeta', 'posts', 'stonewright_not_in_the_list', 'users' ], UninstallSite::remaining_tables( $site ), 'tables of site ' . $site );
			self::assertSame( UninstallSite::FOREIGN_OPTIONS, array_keys( UninstallSite::options( $site ) ), 'options of site ' . $site );
		}
	}

	public function test_network_without_a_multisite_changes_nothing_about_the_scope(): void {
		$command = $this->run_command( [ 'all-data' => true, 'yes' => true, 'network' => true ] );

		self::assertSame( [ false ], $command->deactivations, 'there is no network to deactivate for' );
		self::assertSame( [], array_filter( UninstallSite::$log, static fn ( string $entry ): bool => str_starts_with( $entry, 'switch:' ) ) );
	}
}
