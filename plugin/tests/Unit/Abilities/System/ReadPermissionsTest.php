<?php
/**
 * Read abilities that expose site knowledge or per-post data need more than the read capability.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Abilities\System;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\Diagnostics\CapabilityPreflight;
use Stonewright\WpMcp\Abilities\Elementor\ProviderDiscovery;
use Stonewright\WpMcp\Abilities\Memory\MemoryGet;
use Stonewright\WpMcp\Abilities\Memory\MemoryList;
use Stonewright\WpMcp\Abilities\System\InstructionsGet;
use Stonewright\WpMcp\Abilities\System\TaskStart;
use Stonewright\WpMcp\Security\IncidentStore;

/**
 * @covers \Stonewright\WpMcp\Abilities\Memory\MemoryList
 * @covers \Stonewright\WpMcp\Abilities\Memory\MemoryGet
 * @covers \Stonewright\WpMcp\Abilities\System\InstructionsGet
 * @covers \Stonewright\WpMcp\Abilities\Elementor\ProviderDiscovery
 * @covers \Stonewright\WpMcp\Abilities\Diagnostics\CapabilityPreflight
 */
final class ReadPermissionsTest extends TestCase {

	private const POST = 4301;

	protected function setUp(): void {
		IncidentStore::reset_for_tests();
		$GLOBALS['stonewright_test_user_logged_in']  = true;
		$GLOBALS['stonewright_test_current_user_id'] = 7;
		$GLOBALS['stonewright_test_options']         = [
			'stonewright_essential_tools_mode' => true,
			'stonewright_disabled_abilities'   => [],
		];
		$GLOBALS['stonewright_test_transients']      = [];
		$GLOBALS['stonewright_test_posts'][ self::POST ] = (object) [
			'ID'           => self::POST,
			'post_type'    => 'post',
			'post_status'  => 'private',
			'post_title'   => 'Private post',
			'post_content' => '',
			'post_excerpt' => '',
			'meta'         => [],
		];
	}

	protected function tearDown(): void {
		IncidentStore::reset_for_tests();
		unset( $GLOBALS['stonewright_test_user_can_callback'] );
		$GLOBALS['stonewright_test_user_caps']  = [];
		$GLOBALS['stonewright_test_options']    = [];
		$GLOBALS['stonewright_test_posts']      = [];
		$GLOBALS['stonewright_test_transients'] = [];
	}

	/**
	 * @param list<string> $caps
	 * @param list<string> $denied "cap:post_id" pairs refused although the capability is held.
	 */
	private function user_can( array $caps, array $denied = [] ): void {
		$GLOBALS['stonewright_test_user_can_callback'] = static function ( string $cap, mixed ...$args ) use ( $caps, $denied ): bool {
			if ( isset( $args[0] ) && in_array( $cap . ':' . (int) $args[0], $denied, true ) ) {
				return false;
			}
			return in_array( $cap, $caps, true );
		};
	}

	/** @return array<string, array{0: class-string}> */
	public static function site_knowledge_abilities(): array {
		return [
			'memory-list'             => [ MemoryList::class ],
			'memory-get'              => [ MemoryGet::class ],
			'system-instructions-get' => [ InstructionsGet::class ],
		];
	}

	/**
	 * @param class-string $class
	 * @dataProvider site_knowledge_abilities
	 */
	public function test_memory_and_instructions_reads_need_manage_options( string $class ): void {
		$ability = new $class();

		$this->user_can( [ 'read', 'edit_posts' ] );
		self::assertNotTrue( $ability->permission_callback( [] ), 'A user who can only read or edit posts is refused.' );

		$this->user_can( [ 'read', 'manage_options' ] );
		self::assertTrue( $ability->permission_callback( [] ) );
	}

	public function test_task_start_keeps_working_for_a_user_who_can_only_read(): void {
		$this->user_can( [ 'read' ] );

		$start = ( new TaskStart() )->execute(
			[
				'task'         => 'Update an existing post title and excerpt.',
				'surface'      => 'wordpress',
				'intent'       => 'write',
				'responseMode' => 'compact',
			]
		);

		self::assertIsArray( $start, 'task-start does not call the memory or instructions abilities, so the stricter permission does not reach it.' );
		self::assertTrue( ( new TaskStart() )->permission_callback( [] ) );
	}

	public function test_provider_discovery_for_a_post_needs_edit_post(): void {
		$ability = new ProviderDiscovery();

		$this->user_can( [ 'read', 'edit_posts', 'edit_post' ], [ 'edit_post:' . self::POST ] );
		$denied = $ability->permission_callback( [ 'post_id' => self::POST ] );
		self::assertNotTrue( $denied );
		self::assertTrue( $ability->permission_callback( [ 'post_id' => self::POST + 1 ] ), 'edit_post is checked for the requested post only.' );

		$this->user_can( [ 'read', 'edit_posts', 'edit_post' ] );
		self::assertTrue( $ability->permission_callback( [ 'post_id' => self::POST ] ) );
	}

	public function test_provider_discovery_without_a_post_stays_a_read(): void {
		$this->user_can( [ 'read' ] );

		self::assertTrue( ( new ProviderDiscovery() )->permission_callback( [] ) );
	}

	public function test_provider_discovery_execute_refuses_a_post_the_user_cannot_edit(): void {
		$this->user_can( [ 'read' ] );

		$result = ( new ProviderDiscovery() )->execute( [ 'post_id' => self::POST ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_cannot_edit_post', $result->get_error_code() );
	}

	public function test_capability_preflight_needs_read_post_for_the_post(): void {
		$ability = new CapabilityPreflight();

		$this->user_can( [ 'read' ], [ 'read_post:' . self::POST ] );
		self::assertNotTrue( $ability->permission_callback( [ 'post_id' => self::POST ] ) );

		$this->user_can( [ 'read', 'read_post' ] );
		self::assertTrue( $ability->permission_callback( [ 'post_id' => self::POST ] ) );
	}

	public function test_capability_preflight_execute_refuses_a_post_the_user_cannot_read(): void {
		$this->user_can( [ 'read' ] );

		$result = ( new CapabilityPreflight() )->execute( [ 'post_id' => self::POST ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_cannot_read_post', $result->get_error_code() );
	}
}
