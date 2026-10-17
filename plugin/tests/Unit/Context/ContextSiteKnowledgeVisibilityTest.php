<?php
/**
 * Site memory, custom instructions, user context and recurring errors reach only administrators.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Context;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\System\ContextBootstrap;
use Stonewright\WpMcp\Abilities\System\TaskStart;
use Stonewright\WpMcp\Context\ContextBuilder;
use Stonewright\WpMcp\Security\ErrorPatterns;
use Stonewright\WpMcp\Security\IncidentStore;

/**
 * memory-list, memory-get and instructions-get need manage_options; task-start and context-bootstrap must not hand the
 * same site knowledge to a contributor or author, who may use the MCP servers but cannot read it in wp-admin.
 *
 * @covers \Stonewright\WpMcp\Context\ContextBuilder
 * @covers \Stonewright\WpMcp\Core\AgentInstructions
 * @covers \Stonewright\WpMcp\Abilities\System\TaskStart
 * @covers \Stonewright\WpMcp\Abilities\System\ContextBootstrap
 */
final class ContextSiteKnowledgeVisibilityTest extends TestCase {

	private const CUSTOM   = 'Private rule: quote the staging password policy.';
	private const CONTEXT  = 'Private context: the owner is on holiday.';
	private const ERROR    = 'Private failure text from a recurring error.';
	private const MEMORY   = 'private-memory-key';

	private mixed $original_wpdb;

	/** @var object */
	private object $db;

	protected function setUp(): void {
		IncidentStore::reset_for_tests();
		$this->original_wpdb                         = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['stonewright_test_current_user_id'] = 7;
		$GLOBALS['stonewright_test_user_caps']       = [];
		$GLOBALS['stonewright_test_options']         = [
			'stonewright_memory_enabled'              => true,
			'stonewright_custom_instructions_enabled' => true,
			'stonewright_custom_instructions'         => self::CUSTOM,
			'stonewright_user_context_enabled'        => true,
			'stonewright_user_context'                => self::CONTEXT,
		];
		$GLOBALS['stonewright_test_transients']      = [];
		$this->db                                    = $this->make_wpdb();
		$GLOBALS['wpdb']                             = $this->db;
		for ( $i = 0; $i < 3; $i++ ) {
			ErrorPatterns::observe( 'stonewright/design-validate-spec', 'error', [ 'error_code' => 'stonewright_spec_invalid', 'message' => self::ERROR ] );
		}
	}

	protected function tearDown(): void {
		IncidentStore::reset_for_tests();
		if ( null !== $this->original_wpdb ) {
			$GLOBALS['wpdb'] = $this->original_wpdb;
		} else {
			unset( $GLOBALS['wpdb'] );
		}
		$GLOBALS['stonewright_test_options']    = [];
		$GLOBALS['stonewright_test_transients'] = [];
		$GLOBALS['stonewright_test_user_caps']  = [];
	}

	/** @param list<string> $caps */
	private function user_with( array $caps ): void {
		$GLOBALS['stonewright_test_user_caps'] = array_fill_keys( $caps, true );
	}

	private function start( string $mode ): array {
		$start = ( new TaskStart() )->execute(
			[
				'task'         => 'Update the validate spec page copy.',
				'surface'      => 'wordpress',
				'intent'       => 'write',
				'responseMode' => $mode,
			]
		);
		self::assertIsArray( $start );

		return $start;
	}

	public function test_an_editor_level_caller_gets_the_context_without_site_knowledge(): void {
		$this->user_with( [ 'read', 'edit_posts' ] );

		$built = ContextBuilder::build( 'Update the validate spec page copy.', 'wordpress', 'write' );

		self::assertTrue( $built['ok'] );
		self::assertStringStartsWith( 'swctx_', (string) $built['context_token'] );
		self::assertSame( [], $built['memory_entries'] );
		self::assertSame( [ 'enabled' => false, 'text' => '' ], $built['custom_instructions'] );
		self::assertSame( [ 'enabled' => false, 'text' => '' ], $built['user_context'] );
		self::assertSame( [], $built['recurring_errors'] );
		self::assertSame( [], $built['incident_actions'] );
		self::assertStringNotContainsString( self::CUSTOM, (string) $built['instructions'] );
		self::assertStringNotContainsString( 'Site-specific instructions', (string) $built['instructions'] );
		self::assertSame( 0, $this->db->memory_queries, 'Site memory is not even read for a caller who may not see it.' );
		// The rest of the packet is unchanged.
		self::assertStringContainsString( 'Stonewright build discipline', (string) $built['instructions'] );
		self::assertNotEmpty( $built['required_followups'] );
		self::assertArrayHasKey( 'tool_profile_hint', $built );
	}

	public function test_an_administrator_still_gets_the_site_knowledge(): void {
		$this->user_with( [ 'read', 'edit_posts', 'manage_options' ] );

		$built = ContextBuilder::build( 'Update the validate spec page copy.', 'wordpress', 'write' );

		self::assertContains( self::MEMORY, array_column( $built['memory_entries'], 'memory_key' ) );
		self::assertTrue( $built['custom_instructions']['enabled'] );
		self::assertStringContainsString( self::CUSTOM, $built['custom_instructions']['text'] );
		self::assertStringContainsString( self::CONTEXT, $built['custom_instructions']['text'] );
		self::assertSame( self::CONTEXT, $built['user_context']['text'] );
		self::assertNotEmpty( $built['recurring_errors'] );
		self::assertStringContainsString( self::CUSTOM, (string) $built['instructions'] );
	}

	public function test_compact_task_start_for_an_editor_level_caller_omits_the_private_sections(): void {
		$this->user_with( [ 'read', 'edit_posts' ] );

		$start   = $this->start( 'compact' );
		$context = (array) ( $start['context'] ?? [] );

		foreach ( [ 'memory_refs', 'custom_instructions', 'recurring_errors', 'incident_actions' ] as $key ) {
			self::assertArrayNotHasKey( $key, $context, $key . ' is for administrators.' );
		}
		self::assertArrayHasKey( 'required_actions', $context );
		self::assertNotContains( 'load_memory_refs', $context['required_actions'] );
		self::assertNotContains( 'repair_open_incidents_first', $context['required_actions'] );
		$encoded = (string) wp_json_encode( $start );
		foreach ( [ self::CUSTOM, self::CONTEXT, self::ERROR, self::MEMORY ] as $private ) {
			self::assertStringNotContainsString( $private, $encoded );
		}
	}

	public function test_compact_task_start_for_an_administrator_keeps_the_private_sections(): void {
		$this->user_with( [ 'read', 'edit_posts', 'manage_options' ] );

		$context = (array) ( $this->start( 'compact' )['context'] ?? [] );

		self::assertContains( self::MEMORY, array_column( (array) ( $context['memory_refs'] ?? [] ), 'memory_key' ) );
		self::assertTrue( $context['custom_instructions']['enabled'] ?? false );
		self::assertNotEmpty( $context['recurring_errors'] ?? [] );
		self::assertContains( 'load_memory_refs', $context['required_actions'] );
	}

	public function test_full_context_bootstrap_for_an_editor_level_caller_omits_the_private_sections(): void {
		$this->user_with( [ 'read', 'edit_posts' ] );

		$result = ( new ContextBootstrap() )->execute( [ 'task' => 'Update the validate spec page copy.', 'surface' => 'wordpress', 'intent' => 'write' ] );

		self::assertIsArray( $result );
		self::assertTrue( $result['ok'] );
		self::assertSame( [], $result['memory_entries'] );
		self::assertSame( [], $result['recurring_errors'] );
		self::assertSame( [], $result['incident_actions'] );
		self::assertFalse( $result['custom_instructions']['enabled'] );
		self::assertSame( '', $result['custom_instructions']['text'] );
		self::assertFalse( $result['user_context']['enabled'] );
		$encoded = (string) wp_json_encode( $result );
		foreach ( [ self::CUSTOM, self::CONTEXT, self::ERROR, self::MEMORY ] as $private ) {
			self::assertStringNotContainsString( $private, $encoded );
		}
	}

	public function test_the_gated_response_still_has_every_field_the_output_schema_requires(): void {
		$this->user_with( [ 'read', 'edit_posts' ] );
		$schema = ( new ContextBootstrap() )->output_schema();

		$result = ( new ContextBootstrap() )->execute( [ 'task' => 'Anything', 'surface' => 'wordpress' ] );

		self::assertIsArray( $result );
		foreach ( $schema['required'] as $field ) {
			self::assertArrayHasKey( $field, $result, $field . ' is required by the output schema.' );
		}
		foreach ( [ 'memory_entries' => 'array', 'custom_instructions' => 'object', 'user_context' => 'object', 'recurring_errors' => 'array', 'incident_actions' => 'array' ] as $field => $type ) {
			self::assertSame( $type, $schema['properties'][ $field ]['type'] );
			self::assertIsArray( $result[ $field ] );
		}
	}

	private function make_wpdb(): object {
		return new class() {
			public $prefix = 'wp_';
			public int $memory_queries = 0;

			public function get_var( string $query ): string {
				return 'table_exists';
			}

			public function prepare( string $query, mixed ...$args ): string {
				return $query;
			}

			public function update( string $table, array $data, array $where, array $format = [], array $where_format = [] ): int {
				return 1;
			}

			/** @return array<int, array<string, mixed>> */
			public function get_results( string $query, string $output = 'OBJECT' ): array {
				if ( str_contains( $query, 'stonewright_skills' ) || str_contains( $query, 'stonewright_design_direction' ) ) {
					return [];
				}
				++$this->memory_queries;

				return [
					[
						'id'                  => '9',
						'type'                => 'user',
						'scope'               => 'wordpress',
						'memory_key'          => 'private-memory-key',
						'name'                => 'Private memory entry',
						'value_json'          => wp_json_encode( 'Private value' ),
						'confidence'          => '1.0000',
						'topic'               => 'Private memory entry',
						'version_fingerprint' => '',
						'expires_at'          => '',
						'status'              => 'active',
						'precedence'          => '10',
						'created_by'          => '1',
						'created_at'          => '2026-01-01 00:00:00',
						'updated_at'          => '2026-01-01 00:00:00',
						'last_retrieved_at'   => '',
					],
				];
			}
		};
	}
}
