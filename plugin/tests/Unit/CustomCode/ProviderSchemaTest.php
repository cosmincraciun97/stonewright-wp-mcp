<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\CustomCode;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\CustomCode\ProviderOps;
use Stonewright\WpMcp\CustomCode\ProviderRegistry;
use Stonewright\WpMcp\Security\CustomCodeGrant;
use Stonewright\WpMcp\Security\IncidentStore;

/**
 * The input schema of the custom-code provider pipeline names every argument the providers read, so
 * a request that carries one is not refused as an unknown property, and replace_between_markers works
 * through the tool.
 *
 * @covers \Stonewright\WpMcp\Abilities\CustomCode\ProviderOps::input_schema
 * @covers \Stonewright\WpMcp\CustomCode\Providers\ThemeFileProvider
 */
final class ProviderSchemaTest extends TestCase {

	private const GAP = [
		'reason'        => 'No typed WordPress API owns this stylesheet rule.',
		'methods_tried' => [ 'typed_api', 'admin_form' ],
	];

	/** Keys the providers read from a call to their own backend, not from the tool arguments. */
	private const INTERNAL_KEYS = [ 'active', 'payload', 'snippets' ];

	private string $theme_dir;

	protected function setUp(): void {
		$GLOBALS['stonewright_test_user_caps']       = [ 'read' => true, 'edit_theme_options' => true, 'edit_css' => true, 'manage_options' => true ];
		$GLOBALS['stonewright_test_user_logged_in']  = true;
		$GLOBALS['stonewright_test_current_user_id'] = 1;
		$GLOBALS['stonewright_test_options']         = [ 'stonewright_mode' => 'development', 'stonewright_disabled_abilities' => [] ];
		$GLOBALS['stonewright_test_wpdb_inserts']    = [];
		$GLOBALS['stonewright_test_transients']      = [];
		IncidentStore::reset_for_tests();

		$this->theme_dir = sys_get_temp_dir() . '/sw-theme-schema-' . bin2hex( random_bytes( 4 ) );
		mkdir( $this->theme_dir );
		file_put_contents( $this->theme_dir . '/style.css', "/* theme */\n/* sw:start */\nbody{color:#111;}\n/* sw:end */\na{color:red;}\n" );
		$GLOBALS['stonewright_test_stylesheet_directory'] = $this->theme_dir;
		$GLOBALS['stonewright_test_stylesheet']           = 'sw-test-theme';
		ProviderRegistry::reset_for_tests();
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_user_caps']       = [];
		$GLOBALS['stonewright_test_options']         = [];
		$GLOBALS['stonewright_test_wpdb_inserts']    = [];
		$GLOBALS['stonewright_test_transients']      = [];
		$GLOBALS['stonewright_test_current_user_id'] = 0;
		unset( $GLOBALS['stonewright_test_stylesheet_directory'], $GLOBALS['stonewright_test_stylesheet'] );
		ProviderRegistry::reset_for_tests();
		IncidentStore::reset_for_tests();
		foreach ( (array) glob( $this->theme_dir . '/*' ) as $file ) {
			if ( is_string( $file ) && is_file( $file ) ) {
				@unlink( $file );
			}
		}
		@rmdir( $this->theme_dir );
	}

	public function test_the_schema_names_every_argument_the_providers_read(): void {
		$properties = array_keys( ( new ProviderOps() )->input_schema()['properties'] );
		$root       = dirname( __DIR__, 3 ) . '/includes/CustomCode/Providers';
		$missing    = [];
		foreach ( (array) glob( $root . '/*.php' ) as $file ) {
			preg_match_all( "/\\\$args\\['([a-z_0-9]+)'\\]/", (string) file_get_contents( (string) $file ), $found );
			foreach ( $found[1] as $key ) {
				if ( ! in_array( $key, $properties, true ) && ! in_array( $key, self::INTERNAL_KEYS, true ) ) {
					$missing[ $key ] = basename( (string) $file );
				}
			}
		}

		self::assertSame( [], $missing, 'Arguments read by a provider but missing from the input schema.' );
	}

	public function test_the_new_properties_are_typed_and_bounded(): void {
		$properties = ( new ProviderOps() )->input_schema()['properties'];

		foreach ( [ 'marker', 'start_marker', 'end_marker', 'id', 'smoke_url' ] as $key ) {
			self::assertSame( 'string', $properties[ $key ]['type'], $key );
			self::assertArrayHasKey( 'maxLength', $properties[ $key ], $key );
		}
		self::assertSame( 'boolean', $properties['create_if_missing']['type'] );
		self::assertSame( 'boolean', $properties['expected_active']['type'] );
		self::assertSame( [ 'stylesheet', 'template' ], $properties['theme']['enum'] );
		self::assertSame( [ 'append', 'insert_after_marker', 'replace_between_markers', 'replace_all' ], $properties['mode']['enum'] );
	}

	public function test_a_replace_between_markers_request_passes_the_schema(): void {
		self::assertSame( [], $this->schema_errors( $this->request() ) );
		self::assertSame( [ 'bogus' ], $this->schema_errors( $this->request() + [ 'bogus' => 'x' ] ) );
		self::assertSame( [ 'mode' ], $this->schema_errors( [ 'mode' => 'replace_everything' ] + $this->request() ) );
	}

	public function test_replace_between_markers_dry_runs_and_applies_through_the_tool(): void {
		$ops = new ProviderOps();
		$dry = $ops->execute( [ 'action' => 'dry-run', 'native_gap' => self::GAP ] + $this->request() );
		self::assertIsArray( $dry, $dry instanceof \WP_Error ? $dry->get_error_code() . ': ' . $dry->get_error_message() : '' );
		self::assertSame( 'style.css', $dry['target_id'] );

		$grant = CustomCodeGrant::issue(
			[
				'path'          => 'style.css',
				'after_sha256'  => $dry['after_sha256'],
				'language'      => 'css',
				'changed_bytes' => $dry['changed_bytes'],
			]
		);
		self::assertIsArray( $grant, $grant instanceof \WP_Error ? $grant->get_error_message() : '' );

		$result = $ops->execute( [ 'action' => 'apply', 'custom_code_grant' => (string) $grant['token'] ] + $this->request() );
		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_code() . ': ' . $result->get_error_message() : '' );

		self::assertSame(
			"/* theme */\n/* sw:start */\nbody{color:#222;}\n\n/* sw:end */\na{color:red;}\n",
			(string) file_get_contents( $this->theme_dir . '/style.css' )
		);
	}

	public function test_create_if_missing_passes_the_schema_and_reaches_the_provider(): void {
		$request = [ 'provider' => 'theme-file', 'target_id' => 'assets/css/custom.css', 'mode' => 'replace_all', 'code' => "p{margin:0;}\n", 'create_if_missing' => true, 'language' => 'css' ];
		self::assertSame( [], $this->schema_errors( $request ) );

		$dry = ( new ProviderOps() )->execute( [ 'action' => 'dry-run', 'native_gap' => self::GAP ] + $request );
		self::assertIsArray( $dry, $dry instanceof \WP_Error ? $dry->get_error_code() . ': ' . $dry->get_error_message() : '' );
		self::assertFileDoesNotExist( $this->theme_dir . '/assets/css/custom.css' );
	}

	/** @return array<string, mixed> */
	private function request(): array {
		return [
			'provider'     => 'theme-file',
			'target_id'    => 'style.css',
			'mode'         => 'replace_between_markers',
			'start_marker' => '/* sw:start */',
			'end_marker'   => '/* sw:end */',
			'code'         => 'body{color:#222;}',
			'language'     => 'css',
			'theme'        => 'stylesheet',
		];
	}

	/**
	 * The properties a WordPress input validator would refuse: a name the schema does not list (it has
	 * additionalProperties:false), or a value outside the listed type, enum or length.
	 *
	 * @param array<string, mixed> $args
	 * @return list<string>
	 */
	private function schema_errors( array $args ): array {
		$args   = [ 'action' => 'dry-run' ] + $args;
		$schema = ( new ProviderOps() )->input_schema();
		$errors = [];
		foreach ( $args as $key => $value ) {
			$property = $schema['properties'][ $key ] ?? null;
			if ( null === $property ) {
				$errors[] = (string) $key;
				continue;
			}
			$type_ok = match ( $property['type'] ?? null ) {
				'string'  => is_string( $value ),
				'boolean' => is_bool( $value ),
				'integer' => is_int( $value ),
				'object'  => is_array( $value ),
				default   => true,
			};
			$bad = ! $type_ok
				|| ( isset( $property['enum'] ) && ! in_array( $value, $property['enum'], true ) )
				|| ( isset( $property['maxLength'] ) && is_string( $value ) && strlen( $value ) > $property['maxLength'] );
			if ( $bad ) {
				$errors[] = (string) $key;
			}
		}

		return $errors;
	}
}
