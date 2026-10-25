<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Elementor;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\Site\SitePulse;
use Stonewright\WpMcp\Abilities\System\TaskStart;
use Stonewright\WpMcp\Elementor\CachePurgeAdvisor;
use Stonewright\WpMcp\Support\AgentNotices;

/**
 * @covers \Stonewright\WpMcp\Elementor\CachePurgeAdvisor
 */
final class CachePurgeAdvisorTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_options'] = [];
		CachePurgeAdvisor::use_litespeed( true );
	}

	protected function tearDown(): void {
		CachePurgeAdvisor::use_litespeed( null );
		$GLOBALS['stonewright_test_options'] = [];
	}

	public function test_no_advisory_without_litespeed_cache(): void {
		CachePurgeAdvisor::use_litespeed( false );
		$GLOBALS['stonewright_test_options']['litespeed.conf.purge-post_all'] = 1;

		self::assertSame( [], CachePurgeAdvisor::advisories() );
		self::assertNull( CachePurgeAdvisor::for_plugin_change() );
	}

	public function test_purge_all_pages_rule_yields_the_advisory(): void {
		$GLOBALS['stonewright_test_options']['litespeed.conf.purge-post_all'] = 1;

		$advisories = CachePurgeAdvisor::advisories();

		self::assertCount( 1, $advisories );
		self::assertSame( 'LiteSpeed Cache', $advisories[0]['plugin'] );
		self::assertSame( [ 'plugin', 'setting', 'effect', 'hint' ], array_keys( $advisories[0] ) );
		self::assertStringContainsString( 'Auto Purge Rules: All pages', $advisories[0]['setting'] );
		self::assertStringContainsString( 'purged on each post or page save', $advisories[0]['effect'] );
		self::assertStringContainsString( 'purges its whole page cache on every page save', $advisories[0]['hint'] );
		self::assertStringContainsString( 'one apply per post', $advisories[0]['hint'] );
	}

	public function test_the_pages_rule_and_the_all_rule_are_one_advisory_that_names_both(): void {
		$GLOBALS['stonewright_test_options']['litespeed.conf.purge-post_all'] = '1';
		$GLOBALS['stonewright_test_options']['litespeed.conf.purge-post_p']   = '1';

		$advisories = CachePurgeAdvisor::advisories();

		self::assertCount( 1, $advisories );
		self::assertStringContainsString( 'All pages', $advisories[0]['setting'] );
		self::assertStringContainsString( 'Pages', $advisories[0]['setting'] );
	}

	public function test_purge_on_upgrade_yields_its_own_advisory(): void {
		$GLOBALS['stonewright_test_options']['litespeed.conf.purge-upgrade'] = 1;

		$advisories = CachePurgeAdvisor::advisories();

		self::assertCount( 1, $advisories );
		self::assertStringContainsString( 'plugin or theme is updated', $advisories[0]['effect'] );
	}

	public function test_rules_off_yield_nothing(): void {
		$GLOBALS['stonewright_test_options']['litespeed.conf.purge-post_all'] = 0;
		$GLOBALS['stonewright_test_options']['litespeed.conf.purge-post_p']   = '0';
		$GLOBALS['stonewright_test_options']['litespeed.conf.purge-upgrade']  = false;

		self::assertSame( [], CachePurgeAdvisor::advisories() );
		self::assertSame( [], $this->notice_lines() );
		CachePurgeAdvisor::note_write();
		self::assertSame( [], $this->notice_lines() );
	}

	public function test_the_option_names_are_the_ones_litespeed_cache_stores(): void {
		// LiteSpeed Cache names an option litespeed.<class>.<id>; the ids are its O_PURGE_* constants.
		self::assertSame( 'litespeed.conf.', CachePurgeAdvisor::LITESPEED_PREFIX );
		self::assertSame( [ 'purge-post_all', 'purge-post_p', 'purge-upgrade' ], array_keys( CachePurgeAdvisor::LITESPEED_RULES ) );
	}

	public function test_a_plugin_change_carries_the_activation_copy_when_an_advisory_exists(): void {
		$GLOBALS['stonewright_test_options']['litespeed.conf.purge-post_all'] = 1;

		$answer = CachePurgeAdvisor::for_plugin_change();

		self::assertIsArray( $answer );
		self::assertCount( 1, $answer['advisories'] );
		self::assertStringContainsString( "clears Elementor's generated CSS", $answer['hint'] );
		self::assertStringContainsString( 'the whole page cache', $answer['hint'] );
	}

	public function test_a_write_pushes_the_post_save_advisory_once_per_hour(): void {
		$GLOBALS['stonewright_test_options']['litespeed.conf.purge-post_all'] = 1;
		$now                                                                  = 1_800_000_000;

		CachePurgeAdvisor::note_write( $now );
		self::assertCount( 1, $this->notice_lines( $now ) );
		self::assertSame( $now, $GLOBALS['stonewright_test_options'][ AgentNotices::OPTION ]['cache_purge']['at'] );

		CachePurgeAdvisor::note_write( $now + 1800 );
		self::assertSame( $now, $GLOBALS['stonewright_test_options'][ AgentNotices::OPTION ]['cache_purge']['at'], 'Not pushed again within the hour.' );

		CachePurgeAdvisor::note_write( $now + 3601 );
		self::assertSame( $now + 3601, $GLOBALS['stonewright_test_options'][ AgentNotices::OPTION ]['cache_purge']['at'], 'Pushed again after it expired.' );
	}

	public function test_an_upgrade_only_rule_does_not_leave_a_save_notice(): void {
		$GLOBALS['stonewright_test_options']['litespeed.conf.purge-upgrade'] = 1;
		CachePurgeAdvisor::note_write();

		self::assertSame( [], $this->notice_lines() );
	}

	/** @return list<string> */
	private function notice_lines( ?int $now = null ): array {
		$fields = AgentNotices::fields( $now );

		return $fields['notices'] ?? [];
	}

	public function test_task_start_fast_path_carries_site_cache_purge(): void {
		$GLOBALS['stonewright_test_options']['stonewright_essential_tools_mode'] = true;
		$GLOBALS['stonewright_test_options']['stonewright_disabled_abilities']   = [];
		$args = [ 'task' => 'Update an existing post title and excerpt.', 'surface' => 'wordpress', 'intent' => 'write' ];

		$quiet = ( new TaskStart() )->execute( $args );
		self::assertIsArray( $quiet );
		self::assertArrayNotHasKey( 'cache_purge', $quiet['site'] ?? [], 'No rule, no advisory, no extra bytes.' );

		$GLOBALS['stonewright_test_options']['litespeed.conf.purge-post_all'] = 1;
		$compact = ( new TaskStart() )->execute( $args );
		self::assertIsArray( $compact );
		self::assertSame( 'compact', $compact['response_mode'] );
		self::assertCount( 1, $compact['site']['cache_purge'] );
		self::assertStringContainsString( 'one apply per post', $compact['site']['cache_purge'][0]['hint'] );

		$full = ( new TaskStart() )->execute( $args + [ 'responseMode' => 'full' ] );
		self::assertIsArray( $full );
		self::assertCount( 1, $full['site']['cache_purge'] );
	}

	public function test_site_pulse_reports_the_advisory_only_when_there_is_one(): void {
		SitePulse::use_metrics( [ 'autoload_bytes' => 1000, 'admin_count' => 1, 'plugin_updates' => 0, 'orphan_postmeta' => 0 ] );
		try {
			self::assertArrayNotHasKey( 'cache_purge', ( new SitePulse() )->execute( [] ) );

			$GLOBALS['stonewright_test_options']['litespeed.conf.purge-post_p'] = 1;
			$report = ( new SitePulse() )->execute( [] );
			self::assertCount( 1, $report['cache_purge'] );
			self::assertSame( 'LiteSpeed Cache', $report['cache_purge'][0]['plugin'] );
			self::assertArrayHasKey( 'cache_purge', ( new SitePulse() )->output_schema()['properties'] );
		} finally {
			SitePulse::use_metrics( null );
		}
	}

	public function test_plugin_and_sandbox_activation_answers_carry_the_advisory(): void {
		$plain = [ 'plugin' => 'example/example.php', 'active' => true ];
		self::assertSame( $plain, CachePurgeAdvisor::attach_to_plugin_change( $plain ), 'No rule leaves the answer as it was.' );

		$GLOBALS['stonewright_test_options']['litespeed.conf.purge-post_all'] = 1;
		$answer = CachePurgeAdvisor::attach_to_plugin_change( $plain );
		self::assertSame( [ 'plugin', 'active', 'cache_purge' ], array_keys( $answer ) );

		$root = dirname( __DIR__, 3 ) . '/includes/Abilities/';
		foreach ( [ 'PluginsManage/PluginActivate', 'PluginsManage/PluginDeactivate', 'Sandbox/SandboxActivate', 'Sandbox/SandboxDeactivate' ] as $file ) {
			$source = (string) file_get_contents( $root . $file . '.php' );
			self::assertStringContainsString( 'CachePurgeAdvisor::attach_to_plugin_change(', $source, $file );
			self::assertStringContainsString( "'cache_purge' => [ 'type' => 'object' ]", $source, $file . ' declares the field.' );
		}
	}

	public function test_the_elementor_kit_and_template_writers_leave_the_notice(): void {
		$root = dirname( __DIR__, 3 ) . '/includes/';
		foreach ( [ 'Support/ElementorData', 'Abilities/ElementorV3/KitBatchMutate', 'Abilities/ElementorV3/UpdateKitColors', 'Abilities/ElementorV3/UpdateKitTypography', 'Abilities/ElementorV3/UpdatePageSettings' ] as $file ) {
			self::assertStringContainsString( 'CachePurgeAdvisor::note_write()', (string) file_get_contents( $root . $file . '.php' ), $file );
		}
	}
}
