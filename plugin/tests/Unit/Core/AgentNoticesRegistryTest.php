<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\Ability;
use Stonewright\WpMcp\Abilities\Site\Info;
use Stonewright\WpMcp\Abilities\System\TaskStart;
use Stonewright\WpMcp\Core\AbilityRegistry;
use Stonewright\WpMcp\Security\ChangeJournal;
use Stonewright\WpMcp\Security\RescueHooks;
use Stonewright\WpMcp\Support\AgentNotices;

/**
 * Every ability response, and task-start, carries the compact pending_incident banner while a
 * rescue incident is open, and no notice can break an ability's declared output.
 *
 * @covers \Stonewright\WpMcp\Core\AbilityRegistry
 * @covers \Stonewright\WpMcp\Support\AgentNotices
 */
final class AgentNoticesRegistryTest extends TestCase {

	private string $uploads;

	protected function setUp(): void {
		$this->uploads = sys_get_temp_dir() . '/sw-notices-' . bin2hex( random_bytes( 5 ) );
		mkdir( $this->uploads, 0700, true );
		$GLOBALS['stonewright_test_upload_dir']      = [ 'basedir' => $this->uploads, 'baseurl' => 'https://example.test/uploads', 'error' => false ];
		$GLOBALS['stonewright_test_options']         = [
			'stonewright_mode'                      => 'development',
			'stonewright_enabled'                   => true,
			'stonewright_disabled_abilities'        => [],
			'stonewright_essential_extra_abilities' => [],
			'stonewright_mcp_surface'               => 'essential',
			'stonewright_essential_tools_mode'      => true,
		];
		$GLOBALS['stonewright_test_transients']      = [];
		$GLOBALS['stonewright_test_wpdb_inserts']    = [];
		$GLOBALS['stonewright_test_current_user_id'] = 7;
		$GLOBALS['stonewright_test_user_caps']       = [ 'manage_options' => true, 'read' => true, 'edit_posts' => true ];
		$GLOBALS['stonewright_test_user_logged_in']  = true;
		ChangeJournal::reset_for_tests();
		AgentNotices::reset_for_tests();
		RescueHooks::register_notices();
	}

	protected function tearDown(): void {
		ChangeJournal::reset_for_tests();
		AgentNotices::reset_for_tests();
		unset( $GLOBALS['stonewright_test_upload_dir'], $_SERVER['HTTP_MCP_SESSION_ID'] );
		$GLOBALS['stonewright_test_options']        = [];
		$GLOBALS['stonewright_test_user_caps']      = [];
		$GLOBALS['stonewright_test_user_logged_in'] = false;
		self::remove_tree( $this->uploads );
	}

	private function open_incident(): string {
		$entry = ChangeJournal::arm( [ 'ability' => 'stonewright/theme-file-patch', 'resource_type' => 'theme_file', 'resource_key' => 'functions.php', 'recipe' => [ 'type' => 'theme_backup', 'ref' => 'sw-theme-backup-1' ] ] );
		ChangeJournal::settle( $entry['id'], 'rollback_failed' );
		return $entry['id'];
	}

	public function test_a_response_is_untouched_while_nothing_is_open(): void {
		$result = AbilityRegistry::execute_with_context_guard( new Info(), [] );

		self::assertIsArray( $result );
		self::assertArrayNotHasKey( 'pending_incident', $result );
		self::assertArrayNotHasKey( 'notices', $result );
	}

	public function test_an_ability_response_carries_the_compact_banner_while_an_incident_is_open(): void {
		$id = $this->open_incident();

		$result = AbilityRegistry::execute_with_context_guard( new Info(), [] );

		self::assertSame( [ 'id', 'ability', 'since', 'rollback' ], array_keys( $result['pending_incident'] ) );
		self::assertSame( $id, $result['pending_incident']['id'] );
		self::assertSame( 'stonewright/theme-file-patch', $result['pending_incident']['ability'] );
		self::assertSame( 'stonewright-rescue-rollback', $result['pending_incident']['rollback'] );
		self::assertLessThan( 220, strlen( (string) wp_json_encode( $result['pending_incident'] ) ), 'The banner stays small.' );
	}

	public function test_task_start_carries_the_banner_too(): void {
		$id = $this->open_incident();

		$result = AbilityRegistry::execute_with_context_guard( new TaskStart(), [ 'task' => 'Update the footer copy', 'intent' => 'read' ] );

		self::assertIsArray( $result );
		self::assertSame( $id, $result['pending_incident']['id'] );
	}

	public function test_the_banner_disappears_with_the_incident(): void {
		$id = $this->open_incident();
		ChangeJournal::settle( $id, 'rolled_back' );

		$result = AbilityRegistry::execute_with_context_guard( new Info(), [] );

		self::assertArrayNotHasKey( 'pending_incident', $result );
	}

	public function test_a_field_projection_cannot_hide_the_banner(): void {
		$this->open_incident();

		$result = AbilityRegistry::execute_with_context_guard( new Info(), [ 'stonewright_fields' => [ 'name' ] ] );

		self::assertArrayHasKey( 'pending_incident', $result );
	}

	public function test_errors_are_not_decorated(): void {
		$this->open_incident();
		$error = AbilityRegistry::execute_with_context_guard( new class() extends \Stonewright\WpMcp\Abilities\AbilityKernel {
			public function name(): string {
				return 'stonewright/test-read-failure';
			}

			public function label(): string {
				return 'x';
			}

			public function description(): string {
				return 'x';
			}

			public function category(): string {
				return 'site';
			}

			public function execute( array $args ): array|\WP_Error {
				return new \WP_Error( 'stonewright_test_failure', 'It failed.' );
			}
		}, [] );

		self::assertInstanceOf( \WP_Error::class, $error );
	}

	public function test_a_pushed_line_rides_on_every_response_for_its_lifetime(): void {
		AgentNotices::push( 'section_reuse', 'section_reuse: off - do not offer section reuse', 900 );

		$result = AbilityRegistry::execute_with_context_guard( new Info(), [] );

		self::assertSame( [ 'section_reuse: off - do not offer section reuse' ], $result['notices'] );
	}

	public function test_an_ability_that_already_returns_a_notices_field_keeps_it(): void {
		AgentNotices::push( 'a_line', 'a short line', 900 );
		$ability = new class() extends \Stonewright\WpMcp\Abilities\AbilityKernel {
			public function name(): string {
				return 'stonewright/test-own-notices';
			}

			public function label(): string {
				return 'x';
			}

			public function description(): string {
				return 'x';
			}

			public function category(): string {
				return 'site';
			}

			public function execute( array $args ): array|\WP_Error {
				return [ 'ok' => true, 'notices' => [ 'its own' ] ];
			}
		};

		$result = AbilityRegistry::execute_with_context_guard( $ability, [] );

		self::assertSame( [ 'its own' ], $result['notices'] );
	}

	// -- Output schemas ----------------------------------------------------------

	/**
	 * Every ability's advertised output schema accepts the notice fields: either it already
	 * allows extra properties, or it declares both. This is what keeps a strict client-side
	 * validator, which checks structured content against the schema it was given, from rejecting
	 * a response that carries a notice.
	 */
	public function test_no_advertised_output_schema_rejects_the_notice_fields(): void {
		$offenders = [];
		$strict    = 0;
		foreach ( AbilityRegistry::list() as $class ) {
			/** @var Ability $ability */
			$ability = new $class();
			$schema  = AbilityRegistry::output_schema_for_ability( $ability );
			$type    = $schema['type'] ?? null;
			if ( 'object' !== $type ) {
				$offenders[] = $ability->name() . ' (output is not an object)';
				continue;
			}
			foreach ( [ 'oneOf', 'anyOf', 'allOf', 'not', 'if' ] as $composite ) {
				if ( isset( $schema[ $composite ] ) ) {
					$offenders[] = $ability->name() . ' (uses ' . $composite . ')';
				}
			}
			if ( false === ( $schema['additionalProperties'] ?? null ) ) {
				++$strict;
				if ( ! isset( $schema['properties']['pending_incident'], $schema['properties']['notices'] ) ) {
					$offenders[] = $ability->name() . ' (strict, notice fields undeclared)';
				}
			}
		}

		self::assertSame( [], $offenders );
		self::assertGreaterThan( 0, $strict, 'The suite must see at least one strict schema, or this test proves nothing.' );
	}

	public function test_a_strict_schema_validates_a_response_with_notices_only_because_they_are_declared(): void {
		$strict = [];
		foreach ( AbilityRegistry::list() as $class ) {
			$ability = new $class();
			$raw     = $ability->output_schema();
			if ( false === ( $raw['additionalProperties'] ?? null ) ) {
				$strict[] = $ability;
			}
		}
		self::assertNotEmpty( $strict );

		foreach ( $strict as $ability ) {
			$raw        = $ability->output_schema();
			$advertised = AbilityRegistry::output_schema_for_ability( $ability );
			$instance   = self::minimal_instance( $raw );
			$with       = $instance + [
				'pending_incident' => [ 'id' => 'cs-1', 'ability' => 'stonewright/x', 'since' => '2026-10-07T00:00:00Z', 'rollback' => 'stonewright-rescue-rollback' ],
				'notices'          => [ 'a short line' ],
			];

			self::assertTrue( self::valid( $instance, $raw ), $ability->name() . ': the control instance is valid against the raw schema.' );
			self::assertFalse( self::valid( $with, $raw ), $ability->name() . ': the raw strict schema rejects the notice fields.' );
			self::assertTrue( self::valid( $with, $advertised ), $ability->name() . ': the advertised schema accepts them.' );
		}
	}

	public function test_a_non_strict_schema_advertises_nothing_extra(): void {
		$ability    = new Info();
		$advertised = AbilityRegistry::output_schema_for_ability( $ability );

		self::assertArrayNotHasKey( 'pending_incident', $advertised['properties'] ?? [] );
		self::assertArrayNotHasKey( 'notices', $advertised['properties'] ?? [] );
	}

	// -- Tool surface --------------------------------------------------------------

	public function test_the_rescue_tools_join_the_compact_surface_only_while_an_incident_is_open(): void {
		$closed = array_column( AbilityRegistry::enabled_abilities(), 'name' );
		self::assertNotContains( 'stonewright/rescue-rollback', $closed );
		self::assertNotContains( 'stonewright/rescue-status', $closed );

		$this->open_incident();

		$open = array_column( AbilityRegistry::enabled_abilities(), 'name' );
		self::assertContains( 'stonewright/rescue-rollback', $open );
		self::assertContains( 'stonewright/rescue-status', $open );
		self::assertContains( 'stonewright/rescue-rollback', AbilityRegistry::mcp_server_ability_names() );
	}

	public function test_the_essential_budget_is_not_spent_on_rescue_tools(): void {
		self::assertCount( 30, AbilityRegistry::essential_ability_names_for_test() );
		self::assertNotContains( 'stonewright/rescue-rollback', AbilityRegistry::essential_ability_names_for_test() );
	}

	public function test_both_rescue_abilities_are_on_the_full_surface_always(): void {
		$GLOBALS['stonewright_test_options']['stonewright_mcp_surface'] = 'full';

		$names = array_column( AbilityRegistry::enabled_abilities(), 'name' );

		self::assertContains( 'stonewright/rescue-rollback', $names );
		self::assertContains( 'stonewright/rescue-status', $names );
	}

	public function test_rescue_status_is_a_read_and_rescue_rollback_needs_the_task_context_token(): void {
		$GLOBALS['stonewright_test_options']['stonewright_mcp_surface'] = 'full';
		$by_name = [];
		foreach ( AbilityRegistry::enabled_abilities() as $row ) {
			$by_name[ $row['name'] ] = $row;
		}

		self::assertArrayNotHasKey( 'stonewright_context_token', $by_name['stonewright/rescue-status']['input_schema']['properties'] );
		self::assertArrayHasKey( 'stonewright_context_token', $by_name['stonewright/rescue-rollback']['input_schema']['properties'] );
		self::assertContains( 'stonewright_context_token', $by_name['stonewright/rescue-rollback']['input_schema']['required'] );
	}

	// -- helpers -------------------------------------------------------------------

	/**
	 * A smallest valid instance of an object schema: its required properties with plausible values.
	 *
	 * @param array<string, mixed> $schema
	 * @return array<string, mixed>
	 */
	private static function minimal_instance( array $schema ): array {
		$out = [];
		foreach ( $schema['required'] ?? [] as $name ) {
			$type = $schema['properties'][ $name ]['type'] ?? 'string';
			$type = is_array( $type ) ? $type[0] : $type;
			$out[ $name ] = match ( $type ) {
				'boolean' => true,
				'integer', 'number' => 1,
				'array'   => [],
				'object'  => new \stdClass(),
				default   => 'x',
			};
		}
		return $out;
	}

	/**
	 * @param array<string, mixed> $instance
	 * @param array<string, mixed> $schema
	 */
	private static function valid( array $instance, array $schema ): bool {
		$validator = new \Opis\JsonSchema\Validator();
		$data      = json_decode( (string) wp_json_encode( (object) $instance ) );
		$document  = json_decode( (string) wp_json_encode( $schema ) );
		return $validator->validate( $data, $document )->isValid();
	}

	private static function remove_tree( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		foreach ( scandir( $dir ) ?: [] as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}
			$path = $dir . '/' . $item;
			is_dir( $path ) ? self::remove_tree( $path ) : @unlink( $path );
		}
		@rmdir( $dir );
	}
}
