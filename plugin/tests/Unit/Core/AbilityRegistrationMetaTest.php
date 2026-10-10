<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Abilities\ElementorV4\Migrate;
use Stonewright\WpMcp\Core\AbilityAnnotations;
use Stonewright\WpMcp\Core\AbilityRegistry;
use Stonewright\WpMcp\Support\AbilitySourceFacts;
use WP\MCP\Abilities\McpAbilityExposure;
use WP\MCP\Domain\Utils\McpAnnotationMapper;

/**
 * What every Stonewright ability registers in its meta: the MCP tool annotations that the
 * bundled adapter maps to ToolAnnotations, and the exposure flags for WordPress 7.1 and for the
 * older cores and adapter versions that read the per-channel flags.
 *
 * @covers \Stonewright\WpMcp\Core\AbilityRegistry
 * @covers \Stonewright\WpMcp\Core\AbilityAnnotations
 */
final class AbilityRegistrationMetaTest extends TestCase {

	/**
	 * Abilities that state their hints in meta() because their nature differs from what their
	 * facts and name derive. Any other ability whose registered hints differ from the derived
	 * ones fails the test below, so an override cannot appear by accident.
	 *
	 * Each entry holds the hints in the order readonly, destructive, idempotent, openWorldHint.
	 *
	 * @var array<string, array{bool, bool, bool, bool}>
	 */
	private const REVIEWED_OVERRIDES = [
		// They store a context token or mark the session, or mint the token that authorizes a destructive call, so a client must not treat them as read-only.
		'stonewright/context-bootstrap'                 => [ false, false, false, false ],
		'stonewright/task-start'                        => [ false, false, false, false ],
		'stonewright/workflow-preflight'                => [ false, false, false, false ],
		'stonewright/security-issue-confirmation-token' => [ false, false, false, false ],
		// It runs any other ability, so it can do whatever that ability does.
		'stonewright/execute-ability'                   => [ false, true, false, true ],
		// Arbitrary PHP and WP-CLI commands can reach any host.
		'stonewright/php-execute'                       => [ false, true, false, true ],
		'stonewright/wp-cli-run'                        => [ false, true, false, true ],
		'stonewright/wp-cli-batch-run'                  => [ false, true, false, true ],
		'stonewright/wp-cli-job-start'                  => [ false, true, false, true ],
		// Some of the site health checks contact wordpress.org.
		'stonewright/site-health-test'                  => [ true, false, true, true ],
		// They store a record or switch session state; no site content is overwritten or removed.
		'stonewright/tool-profile'                      => [ false, false, true, false ],
		'stonewright/expertise-evaluate'                => [ false, false, false, false ],
		'stonewright/design-quality-check'              => [ false, false, false, false ],
		'stonewright/stock-image-import'                => [ false, false, false, true ],
		// Activating a plugin only adds it to the active list, and activating it twice changes nothing more.
		'stonewright/plugin-activate'                   => [ false, false, true, false ],
		// It signs an approval token and stores nothing, so it overwrites nothing.
		'stonewright/design-checkpoint-record'          => [ false, false, false, false ],
		// It writes the widget file under its slug without looking for an earlier one, so a repeat replaces the earlier widget.
		'stonewright/elementor-create-custom-widget'    => [ false, true, false, false ],
		// It only adds media files and attachments, and fetches them from the web.
		'stonewright/design-normalize-assets'           => [ false, false, false, true ],
		// It writes, but the kernel wrapper it records through declares no nature, so its source shows no write.
		'stonewright/security-audit-reconcile'          => [ false, true, true, false ],
	];

	protected function setUp(): void {
		AbilityAnnotations::use_traits( null );
		$GLOBALS['stonewright_test_options'] = [
			'stonewright_enabled'            => true,
			'stonewright_disabled_abilities' => [],
		];
	}

	protected function tearDown(): void {
		AbilityAnnotations::use_traits( null );
		$GLOBALS['stonewright_test_options'] = [];
	}

	/**
	 * @return iterable<string, array{AbilityKernel}>
	 */
	public static function abilities(): iterable {
		foreach ( AbilityRegistry::list() as $class ) {
			/** @var AbilityKernel $ability */
			$ability = new $class();
			yield $ability->name() => [ $ability ];
		}
	}

	/** @var array<string, array{write: bool, external: bool}> */
	private static array $facts = [];

	/**
	 * @param class-string $class Ability class.
	 * @return array{write: bool, external: bool}
	 */
	private static function facts( string $class ): array {
		return self::$facts[ $class ] ??= AbilitySourceFacts::detect( $class );
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function meta_of( AbilityKernel $ability ): array {
		$meta = AbilityRegistry::registration_args( $ability )['meta'];
		self::assertIsArray( $meta );
		return $meta;
	}

	/**
	 * @dataProvider abilities
	 */
	public function test_every_ability_registers_all_four_hints_as_booleans( AbilityKernel $ability ): void {
		$annotations = self::meta_of( $ability )['annotations'] ?? null;

		self::assertIsArray( $annotations, $ability->name() . ' registers no annotations.' );
		foreach ( [ 'readonly', 'destructive', 'idempotent', 'openWorldHint' ] as $hint ) {
			self::assertArrayHasKey( $hint, $annotations, $ability->name() . ' registers no ' . $hint . ' hint.' );
			self::assertIsBool( $annotations[ $hint ], $ability->name() . ' registers a ' . $hint . ' hint that is not a boolean.' );
		}
	}

	/**
	 * @dataProvider abilities
	 */
	public function test_hints_follow_the_derivation_unless_the_ability_is_a_reviewed_override( AbilityKernel $ability ): void {
		$name        = $ability->name();
		$annotations = self::meta_of( $ability )['annotations'];
		$registered  = [ $annotations['readonly'], $annotations['destructive'], $annotations['idempotent'], $annotations['openWorldHint'] ];

		if ( isset( self::REVIEWED_OVERRIDES[ $name ] ) ) {
			self::assertSame( self::REVIEWED_OVERRIDES[ $name ], $registered, $name . ' registers hints other than the reviewed ones.' );
			return;
		}

		$derived = AbilityAnnotations::derive( $name, self::facts( $ability::class ) );
		self::assertSame(
			[ $derived['readonly'], $derived['destructive'], $derived['idempotent'], $derived['openWorldHint'] ],
			$registered,
			$name . ' overrides its derived hints without being a reviewed override.'
		);
	}

	/**
	 * @dataProvider abilities
	 */
	public function test_a_read_only_ability_is_never_destructive_and_a_writer_is_never_read_only( AbilityKernel $ability ): void {
		$annotations = self::meta_of( $ability )['annotations'];
		$facts       = self::facts( $ability::class );

		if ( true === $annotations['readonly'] ) {
			self::assertFalse( $annotations['destructive'], $ability->name() . ' is read-only and destructive at once.' );
			self::assertFalse( $facts['write'], $ability->name() . ' is registered read-only but its source can change state.' );
		}
		if ( $facts['write'] && ! isset( self::REVIEWED_OVERRIDES[ $ability->name() ] ) ) {
			self::assertFalse( $annotations['readonly'], $ability->name() . ' can change state and must not be read-only.' );
		}
	}

	public function test_read_abilities_are_read_only_non_destructive_and_idempotent(): void {
		$read = 0;
		foreach ( AbilityRegistry::list() as $class ) {
			$ability = new $class();
			$facts   = self::facts( $class );
			if ( $facts['write'] || isset( self::REVIEWED_OVERRIDES[ $ability->name() ] ) && ! self::REVIEWED_OVERRIDES[ $ability->name() ][0] ) {
				continue;
			}
			++$read;
			$annotations = self::meta_of( $ability )['annotations'];
			self::assertTrue( $annotations['readonly'], $ability->name() );
			self::assertFalse( $annotations['destructive'], $ability->name() );
			self::assertTrue( $annotations['idempotent'], $ability->name() );
		}

		self::assertGreaterThan( 120, $read, 'The read abilities were not found.' );
	}

	/**
	 * @return array<string, array{string, array{bool, bool, bool, bool}}>
	 */
	public static function sample(): array {
		return [
			'content-get-page reads'                       => [ 'stonewright/content-get-page', [ true, false, true, false ] ],
			'elementor-v3-get-page-structure reads'        => [ 'stonewright/elementor-v3-get-page-structure', [ true, false, true, false ] ],
			'site-info reads'                              => [ 'stonewright/site-info', [ true, false, true, false ] ],
			'blocks-finalizer-runtime reads (its signed links store nothing)' => [ 'stonewright/blocks-finalizer-runtime', [ true, false, true, false ] ],
			'blocks-finalizer-url reads (its signed link stores nothing)' => [ 'stonewright/blocks-finalizer-url', [ true, false, true, false ] ],
			'oembed-resolve reads from the web'            => [ 'stonewright/oembed-resolve', [ true, false, true, true ] ],
			'stock-image-search reads from the web'        => [ 'stonewright/stock-image-search', [ true, false, true, true ] ],
			'content-create-page only adds'                => [ 'stonewright/content-create-page', [ false, false, false, false ] ],
			'comment-create only adds'                     => [ 'stonewright/comment-create', [ false, false, false, false ] ],
			'user-create only adds'                        => [ 'stonewright/user-create', [ false, false, false, false ] ],
			'elementor-add-heading only adds'              => [ 'stonewright/elementor-add-heading', [ false, false, false, false ] ],
			'elementor-v3-add-widget only adds'            => [ 'stonewright/elementor-v3-add-widget', [ false, false, false, false ] ],
			'blocks-insert only adds'                      => [ 'stonewright/blocks-insert', [ false, false, false, false ] ],
			'learning-record may revise its own skill draft' => [ 'stonewright/learning-record', [ false, true, false, false ] ],
			'cpt-register may replace a registration'     => [ 'stonewright/cpt-register', [ false, true, false, false ] ],
			'elementor-widget-register may replace a widget' => [ 'stonewright/elementor-widget-register', [ false, true, false, false ] ],
			'elementor-create-custom-widget may replace a widget file' => [ 'stonewright/elementor-create-custom-widget', [ false, true, false, false ] ],
			'theme-activate replaces the active theme'    => [ 'stonewright/theme-activate', [ false, true, false, false ] ],
			'media-upload adds and fetches a URL'          => [ 'stonewright/media-upload', [ false, false, false, true ] ],
			'content-update-page overwrites'               => [ 'stonewright/content-update-page', [ false, true, false, false ] ],
			'elementor-v3-batch-mutate overwrites'         => [ 'stonewright/elementor-v3-batch-mutate', [ false, true, false, false ] ],
			'settings-update overwrites'                   => [ 'stonewright/settings-update', [ false, true, false, false ] ],
			'comment-delete deletes'                       => [ 'stonewright/comment-delete', [ false, true, true, false ] ],
			'user-delete deletes'                          => [ 'stonewright/user-delete', [ false, true, true, false ] ],
			'plugin-delete deletes'                        => [ 'stonewright/plugin-delete', [ false, true, true, false ] ],
			'plugin-deactivate deactivates'                => [ 'stonewright/plugin-deactivate', [ false, true, true, false ] ],
			'plugin-activate activates'                    => [ 'stonewright/plugin-activate', [ false, false, true, false ] ],
			'theme-backup-restore restores'                => [ 'stonewright/theme-backup-restore', [ false, true, false, false ] ],
			'elementor-v4-migrate migrates'                => [ 'stonewright/elementor-v4-migrate', [ false, true, false, false ] ],
			'php-execute can do anything'                  => [ 'stonewright/php-execute', [ false, true, false, true ] ],
			'execute-ability runs any ability'             => [ 'stonewright/execute-ability', [ false, true, false, true ] ],
			'task-start issues a token'                    => [ 'stonewright/task-start', [ false, false, false, false ] ],
			'design-checkpoint-record signs a token'       => [ 'stonewright/design-checkpoint-record', [ false, false, false, false ] ],
			'design-direction-capture may replace a direction' => [ 'stonewright/design-direction-capture', [ false, true, false, false ] ],
			'security-issue-confirmation-token issues one' => [ 'stonewright/security-issue-confirmation-token', [ false, false, false, false ] ],
		];
	}

	/**
	 * @dataProvider sample
	 * @param array{bool, bool, bool, bool} $expected readonly, destructive, idempotent, openWorldHint.
	 */
	public function test_a_sample_of_abilities_register_the_expected_hints( string $name, array $expected ): void {
		$ability = AbilityRegistry::ability_by_name( $name );
		self::assertInstanceOf( AbilityKernel::class, $ability );

		$annotations = self::meta_of( $ability )['annotations'];

		self::assertSame( $expected, [ $annotations['readonly'], $annotations['destructive'], $annotations['idempotent'], $annotations['openWorldHint'] ], $name );
	}

	public function test_the_bundled_adapter_maps_the_hints_to_mcp_tool_annotations(): void {
		$read  = AbilityRegistry::ability_by_name( 'stonewright/content-get-page' );
		$write = AbilityRegistry::ability_by_name( 'stonewright/comment-delete' );
		self::assertNotNull( $read );
		self::assertNotNull( $write );

		self::assertSame(
			[ 'readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false ],
			McpAnnotationMapper::map( self::meta_of( $read )['annotations'], 'tool' )
		);
		self::assertSame(
			[ 'readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => true, 'openWorldHint' => false ],
			McpAnnotationMapper::map( self::meta_of( $write )['annotations'], 'tool' )
		);
	}

	public function test_the_traits_file_matches_the_source_of_every_ability(): void {
		$recorded = include AbilityAnnotations::traits_path();
		self::assertIsArray( $recorded );

		$fresh = [];
		foreach ( AbilityRegistry::list() as $class ) {
			$fresh[ ( new $class() )->name() ] = self::facts( $class );
		}
		ksort( $fresh, SORT_STRING );

		self::assertSame( $fresh, $recorded, 'plugin/data/ability-traits.php is out of date. Run `composer docs:matrix`.' );
		self::assertSame( array_keys( $recorded ), array_keys( $fresh ) );
	}

	public function test_every_ability_has_recorded_facts(): void {
		$recorded = include AbilityAnnotations::traits_path();

		$missing = [];
		foreach ( AbilityRegistry::list() as $class ) {
			$name = ( new $class() )->name();
			if ( ! isset( $recorded[ $name ] ) ) {
				$missing[] = $name;
			}
		}

		self::assertSame( [], $missing, 'Abilities without recorded facts would register the conservative hints. Run `composer docs:matrix`.' );
	}

	public function test_migrate_states_destructive_inside_the_annotations_not_at_the_top_of_meta(): void {
		$meta = ( new Migrate() )->meta();

		self::assertArrayNotHasKey( 'destructive', $meta, 'A top-level destructive key is read by nothing.' );
		self::assertTrue( $meta['experimental'] );
		self::assertTrue( $meta['annotations']['destructive'] );
		self::assertSame(
			[ false, true, false, false ],
			array_values( array_intersect_key( self::meta_of( new Migrate() )['annotations'], array_flip( [ 'readonly', 'destructive', 'idempotent', 'openWorldHint' ] ) ) )
		);
	}

	// ---------------------------------------------------------------------
	// Exposure: meta.public for WordPress 7.1, mcp.public and show_in_rest for older cores.
	// ---------------------------------------------------------------------

	/**
	 * @dataProvider abilities
	 */
	public function test_every_ability_is_public_on_every_channel( AbilityKernel $ability ): void {
		$meta = self::meta_of( $ability );

		self::assertTrue( $meta['public'], $ability->name() . ': WordPress 7.1 reads meta.public.' );
		self::assertTrue( $meta['show_in_rest'], $ability->name() . ': cores before 7.1 read meta.show_in_rest.' );
		self::assertTrue( $meta['mcp']['public'], $ability->name() . ': adapters read meta.mcp.public.' );
		self::assertTrue( McpAbilityExposure::is_meta_public( $meta ), $ability->name() . ': the bundled adapter does not expose the ability.' );
	}

	public function test_the_adapter_still_exposes_an_ability_that_only_sets_public(): void {
		// WordPress 7.1 seeds show_in_rest from public; the adapter falls back to public when mcp.public is absent.
		self::assertTrue( McpAbilityExposure::is_meta_public( [ 'public' => true ] ) );
		self::assertFalse( McpAbilityExposure::is_meta_public( [ 'public' => false ] ) );
	}

	public function test_an_ability_can_opt_out_of_every_channel_with_public_false(): void {
		$meta = self::meta_of( self::ability_with_meta( [ 'public' => false ] ) );

		self::assertFalse( $meta['public'] );
		self::assertFalse( $meta['show_in_rest'], 'Cores before 7.1 do not read meta.public, so the REST flag follows it.' );
		self::assertFalse( $meta['mcp']['public'] );
		self::assertFalse( McpAbilityExposure::is_meta_public( $meta ) );
	}

	public function test_an_ability_can_opt_out_of_one_channel(): void {
		$no_rest = self::meta_of( self::ability_with_meta( [ 'show_in_rest' => false ] ) );
		self::assertTrue( $no_rest['public'] );
		self::assertFalse( $no_rest['show_in_rest'] );
		self::assertTrue( McpAbilityExposure::is_meta_public( $no_rest ) );

		$no_mcp = self::meta_of( self::ability_with_meta( [ 'mcp' => [ 'public' => false ] ] ) );
		self::assertTrue( $no_mcp['public'] );
		self::assertTrue( $no_mcp['show_in_rest'] );
		self::assertFalse( McpAbilityExposure::is_meta_public( $no_mcp ) );
	}

	public function test_a_non_boolean_public_value_is_registered_as_a_boolean(): void {
		$meta = self::meta_of( self::ability_with_meta( [ 'public' => 'yes' ] ) );

		self::assertIsBool( $meta['public'], 'WordPress refuses a meta.public that is not a boolean.' );
		self::assertFalse( $meta['public'] );
	}

	public function test_other_meta_keys_of_an_ability_are_kept(): void {
		$meta = self::meta_of( self::ability_with_meta( [ 'experimental' => true, 'provider_policy' => [ 'x' => 'y' ] ] ) );

		self::assertTrue( $meta['experimental'] );
		self::assertSame( [ 'x' => 'y' ], $meta['provider_policy'] );
	}

	/**
	 * @param array<string, mixed> $meta Meta the ability returns.
	 */
	private static function ability_with_meta( array $meta ): AbilityKernel {
		return new class( $meta ) extends AbilityKernel {

			/** @param array<string, mixed> $ability_meta */
			public function __construct( private array $ability_meta ) {}

			public function name(): string {
				return 'stonewright/test-meta-fixture';
			}

			public function label(): string {
				return 'Test';
			}

			public function description(): string {
				return 'Test ability.';
			}

			public function category(): string {
				return 'site';
			}

			public function meta(): array {
				return $this->ability_meta;
			}

			public function execute( array $args ): array {
				return $this->ok();
			}
		};
	}
}
