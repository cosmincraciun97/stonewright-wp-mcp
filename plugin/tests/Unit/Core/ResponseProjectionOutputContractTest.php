<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\Ability;
use Stonewright\WpMcp\Context\ContextToken;
use Stonewright\WpMcp\Core\AbilityRegistry;
use Stonewright\WpMcp\Core\RegisteredAbility;
use Stonewright\WpMcp\Security\ChangeSet;
use Stonewright\WpMcp\Support\ResponseProjection;
use Stonewright\WpMcp\Tests\Unit\Core\Fixtures\CoreRestSchemaValidator;

/**
 * A projected response is still the ability's response, so it has to satisfy the declared output
 * schema. WordPress validates the output after the execute callback returns it, which is after the
 * projection, and an MCP client checks structuredContent against the outputSchema the tool lists.
 * A write has committed by then: a projection that breaks the schema reports a committed write as
 * a failure, and a caller that retries applies it twice.
 *
 * The calls go through RegisteredAbility, built from the registry's own registration arguments,
 * with core's schema rules standing in for rest_validate_value_from_schema().
 *
 * @covers \Stonewright\WpMcp\Core\AbilityRegistry::execute_with_context_guard
 * @covers \Stonewright\WpMcp\Support\ResponseProjection
 */
final class ResponseProjectionOutputContractTest extends TestCase {

	/** A change set as a file write reports it, every field ChangeSetV1 requires present. */
	private const CHANGE_SET = [
		'schema'              => 'ChangeSetV1',
		'change_set_id'       => 'cs-3f9a1c',
		'planned'             => [ [ 'kind' => 'file', 'ref' => 'style.css', 'action' => 'update' ] ],
		'applied'             => [ [ 'kind' => 'file', 'ref' => 'style.css', 'action' => 'update' ] ],
		'missing'             => [],
		'unexpected'          => [],
		'before_hash'         => 'b5d1e0',
		'after_hash'          => '9c27f4',
		'verification'        => [ 'status' => 'verified', 'evidence' => [ 'counts' => [ 'planned' => 1, 'applied' => 1 ] ] ],
		'rollback_available'  => true,
		'rollback_recipe_ref' => 'theme-backup:7',
		'repair_of'           => null,
		'supersedes'          => null,
		'approval_reason'     => null,
	];

	protected function setUp(): void {
		if ( ! class_exists( 'WP_Ability', false ) ) {
			require_once dirname( __DIR__, 3 ) . '/vendor/wordpress/abilities-api/includes/abilities-api/class-wp-ability.php';
		}

		unset( $_SERVER['HTTP_MCP_SESSION_ID'] );
		$GLOBALS['stonewright_test_transients']     = [];
		$GLOBALS['stonewright_test_options']        = [
			'stonewright_enabled'              => true,
			'stonewright_disabled_abilities'   => [],
			'stonewright_essential_tools_mode' => true,
		];
		$GLOBALS['stonewright_test_rest_validator'] = [ CoreRestSchemaValidator::class, 'validate' ];
	}

	protected function tearDown(): void {
		unset( $_SERVER['HTTP_MCP_SESSION_ID'], $GLOBALS['stonewright_test_rest_validator'] );
		$GLOBALS['stonewright_test_transients'] = [];
		$GLOBALS['stonewright_test_options']    = [];
	}

	public function test_a_nested_projection_of_a_committed_write_is_a_valid_response(): void {
		// The output contract of the theme file patch: an open object with a change set in it.
		$write = self::write_probe(
			[
				'type'                 => 'object',
				'additionalProperties' => true,
				'properties'           => [
					'change_set' => ChangeSet::output_property(),
				],
			],
			[
				'ok'            => true,
				'path'          => 'style.css',
				'bytes_written' => 18432,
				'diff'          => "@@ -1,3 +1,4 @@\n+.site-footer a { color: inherit; }\n",
				'change_set'    => self::CHANGE_SET,
			]
		);

		$result = self::call( $write, [ 'ok', 'change_set.change_set_id', 'change_set.verification' ] );

		self::assertSame( 1, $write->writes );
		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() : '' );
		// Every field the change set schema requires comes back, and nothing the caller left out
		// of the projection does.
		self::assertSame(
			[
				'ok'         => true,
				'change_set' => self::CHANGE_SET,
			],
			$result
		);
	}

	public function test_a_projection_that_cannot_satisfy_the_schema_returns_the_full_response(): void {
		// Projecting entries.id drops the entry without an id, which leaves fewer entries than the
		// schema allows. Keeping required keys cannot repair that, so the caller gets the whole
		// response rather than a refusal of a write that has already happened.
		$output = [
			'ok'      => true,
			'entries' => [
				[
					'id'   => 'header',
					'kind' => 'css',
				],
				[ 'kind' => 'js' ],
			],
		];
		$write  = self::write_probe(
			[
				'type'       => 'object',
				'properties' => [
					'entries' => [
						'type'     => 'array',
						'minItems' => 2,
						'items'    => [ 'type' => 'object' ],
					],
				],
			],
			$output
		);

		$result = self::call( $write, [ 'entries.id' ] );

		self::assertSame( 1, $write->writes );
		self::assertSame( $output, $result );
	}

	/**
	 * Runs the ability the way the Abilities API does: input validation and the permission check,
	 * the registered execute callback, then output validation.
	 *
	 * @param list<string> $fields
	 */
	private static function call( Ability $ability, array $fields ): mixed {
		$registered = new RegisteredAbility( $ability->name(), AbilityRegistry::registration_args( $ability ) );

		return $registered->execute(
			[
				'stonewright_context_token' => ContextToken::issue( 'Patch the theme stylesheet', $ability->name() )['token'],
				ResponseProjection::PARAM   => $fields,
			]
		);
	}

	/**
	 * A write ability that counts its writes and answers with the given output.
	 *
	 * @param array<string, mixed> $output_schema
	 * @param array<string, mixed> $output
	 */
	private static function write_probe( array $output_schema, array $output ): object {
		return new class( $output_schema, $output ) implements Ability {

			public int $writes = 0;

			/**
			 * @param array<string, mixed> $schema
			 * @param array<string, mixed> $output
			 */
			public function __construct( private array $schema, private array $output ) {
			}

			public function name(): string {
				return 'stonewright/test-projection-file-write';
			}

			public function label(): string {
				return 'Projection write probe';
			}

			public function description(): string {
				return 'Writes once per call and reports the write.';
			}

			public function category(): string {
				return 'themes';
			}

			public function input_schema(): array {
				return [
					'type'                 => 'object',
					'additionalProperties' => false,
					'properties'           => [],
				];
			}

			public function output_schema(): array {
				return $this->schema;
			}

			public function meta(): array {
				return [];
			}

			public function permission_callback( array $args ): bool|\WP_Error {
				return true;
			}

			public function execute( array $args ) {
				++$this->writes;

				return $this->output;
			}
		};
	}
}
