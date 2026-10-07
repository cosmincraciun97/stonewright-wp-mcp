<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Core\AbilityAnnotations;

/**
 * Every ability carries explicit MCP tool annotations. They come from the facts the ability
 * truth matrix records (does it change state, does it reach hosts outside the site) and from
 * the verb in the ability name, and an ability whose nature differs overrides them in its meta.
 *
 * @covers \Stonewright\WpMcp\Core\AbilityAnnotations
 */
final class AbilityAnnotationsTest extends TestCase {

	private const READ  = [ 'write' => false, 'external' => false ];
	private const WRITE = [ 'write' => true, 'external' => false ];

	protected function tearDown(): void {
		AbilityAnnotations::use_traits( null );
	}

	/**
	 * @return array<string, array{string, array{write: bool, external: bool}, array<string, bool>}>
	 */
	public static function derived_hints(): array {
		$hints = static fn ( bool $read_only, bool $destructive, bool $idempotent, bool $open_world = false ): array => [
			'readonly'      => $read_only,
			'destructive'   => $destructive,
			'idempotent'    => $idempotent,
			'openWorldHint' => $open_world,
		];

		return [
			'a read'                                   => [ 'stonewright/content-get-page', self::READ, $hints( true, false, true ) ],
			'a read that reaches the web'              => [ 'stonewright/oembed-resolve', [ 'write' => false, 'external' => true ], $hints( true, false, true, true ) ],
			'a create is additive'                     => [ 'stonewright/content-create-page', self::WRITE, $hints( false, false, false ) ],
			'an add is additive'                       => [ 'stonewright/elementor-v3-add-widget', self::WRITE, $hints( false, false, false ) ],
			'an insert is additive'                    => [ 'stonewright/blocks-insert', self::WRITE, $hints( false, false, false ) ],
			'a record may update an earlier one'       => [ 'stonewright/learning-record', self::WRITE, $hints( false, true, false ) ],
			'a registration may replace one'           => [ 'stonewright/cpt-register', self::WRITE, $hints( false, true, false ) ],
			'a definition may replace an earlier one'  => [ 'stonewright/elementor-widget-define', self::WRITE, $hints( false, true, false ) ],
			'a bulk create is additive'                => [ 'stonewright/content-bulk-create', self::WRITE, $hints( false, false, false ) ],
			'a backup is additive'                     => [ 'stonewright/site-backup-page', self::WRITE, $hints( false, false, false ) ],
			'an activation replaces the active one'    => [ 'stonewright/theme-activate', self::WRITE, $hints( false, true, false ) ],
			'an update is destructive'                 => [ 'stonewright/content-update-page', self::WRITE, $hints( false, true, false ) ],
			'a save may overwrite'                     => [ 'stonewright/memory-save', self::WRITE, $hints( false, true, false ) ],
			'an apply is destructive'                  => [ 'stonewright/blueprint-apply', self::WRITE, $hints( false, true, false ) ],
			'a delete is destructive and idempotent'   => [ 'stonewright/comment-delete', self::WRITE, $hints( false, true, true ) ],
			'a removal is destructive and idempotent'  => [ 'stonewright/elementor-v3-remove-element', self::WRITE, $hints( false, true, true ) ],
			'a deactivation is idempotent'             => [ 'stonewright/plugin-deactivate', self::WRITE, $hints( false, true, true ) ],
			'the last verb of the name decides'        => [ 'stonewright/theme-backup-restore', self::WRITE, $hints( false, true, false ) ],
			'an unknown verb is destructive'           => [ 'stonewright/wp-cli-run', self::WRITE, $hints( false, true, false ) ],
			'a write that reaches the web'             => [ 'stonewright/media-upload', [ 'write' => true, 'external' => true ], $hints( false, false, false, true ) ],
		];
	}

	/**
	 * @dataProvider derived_hints
	 * @param array{write: bool, external: bool} $traits
	 * @param array<string, bool>                $expected
	 */
	public function test_hints_are_derived_from_the_facts_and_the_verb( string $name, array $traits, array $expected ): void {
		self::assertSame( $expected, AbilityAnnotations::derive( $name, $traits ) );
	}

	public function test_an_ability_without_recorded_facts_gets_explicit_conservative_hints(): void {
		AbilityAnnotations::use_traits( [] );

		self::assertSame(
			[
				'readonly'      => false,
				'destructive'   => true,
				'idempotent'    => false,
				'openWorldHint' => true,
			],
			AbilityAnnotations::for_ability( self::ability( 'stonewright/not-in-the-traits' ) )
		);
	}

	public function test_the_recorded_facts_decide_the_hints_of_an_ability(): void {
		AbilityAnnotations::use_traits(
			[
				'stonewright/test-read'  => self::READ,
				'stonewright/test-write' => self::WRITE,
			]
		);

		self::assertSame(
			[ 'readonly' => true, 'destructive' => false, 'idempotent' => true, 'openWorldHint' => false ],
			AbilityAnnotations::for_ability( self::ability( 'stonewright/test-read' ) )
		);
		self::assertSame(
			[ 'readonly' => false, 'destructive' => true, 'idempotent' => false, 'openWorldHint' => false ],
			AbilityAnnotations::for_ability( self::ability( 'stonewright/test-write' ) )
		);
	}

	public function test_an_ability_can_override_what_is_derived(): void {
		AbilityAnnotations::use_traits( [ 'stonewright/test-read' => self::READ ] );
		$hints = AbilityAnnotations::for_ability(
			self::ability( 'stonewright/test-read', [ 'annotations' => [ 'readonly' => false, 'destructive' => false, 'idempotent' => false, 'openWorldHint' => true ] ] )
		);

		self::assertSame( [ 'readonly' => false, 'destructive' => false, 'idempotent' => false, 'openWorldHint' => true ], $hints );
	}

	public function test_a_partial_override_keeps_the_other_derived_hints(): void {
		AbilityAnnotations::use_traits( [ 'stonewright/test-write' => self::WRITE ] );
		$hints = AbilityAnnotations::for_ability( self::ability( 'stonewright/test-write', [ 'annotations' => [ 'openWorldHint' => true ] ] ) );

		self::assertSame( [ 'readonly' => false, 'destructive' => true, 'idempotent' => false, 'openWorldHint' => true ], $hints );
	}

	public function test_a_read_only_override_cannot_stay_destructive(): void {
		AbilityAnnotations::use_traits( [ 'stonewright/test-write' => self::WRITE ] );
		$hints = AbilityAnnotations::for_ability( self::ability( 'stonewright/test-write', [ 'annotations' => [ 'readonly' => true ] ] ) );

		self::assertTrue( $hints['readonly'] );
		self::assertFalse( $hints['destructive'], 'A read-only tool does not modify anything, so it cannot be destructive.' );
	}

	public function test_mcp_spellings_in_a_meta_override_are_understood(): void {
		AbilityAnnotations::use_traits( [ 'stonewright/test-write' => self::WRITE ] );
		$hints = AbilityAnnotations::for_ability(
			self::ability( 'stonewright/test-write', [ 'annotations' => [ 'destructiveHint' => false, 'idempotentHint' => true ] ] )
		);

		self::assertFalse( $hints['destructive'] );
		self::assertTrue( $hints['idempotent'] );
	}

	public function test_a_title_override_passes_through_and_an_empty_one_is_ignored(): void {
		AbilityAnnotations::use_traits( [ 'stonewright/test-read' => self::READ ] );

		$titled = AbilityAnnotations::for_ability( self::ability( 'stonewright/test-read', [ 'annotations' => [ 'title' => '  Read the thing  ' ] ] ) );
		self::assertSame( 'Read the thing', $titled['title'] );

		$empty = AbilityAnnotations::for_ability( self::ability( 'stonewright/test-read', [ 'annotations' => [ 'title' => '   ' ] ] ) );
		self::assertArrayNotHasKey( 'title', $empty );
	}

	public function test_overrides_of_the_wrong_type_or_with_unknown_keys_are_ignored(): void {
		AbilityAnnotations::use_traits( [ 'stonewright/test-write' => self::WRITE ] );
		$hints = AbilityAnnotations::for_ability(
			self::ability(
				'stonewright/test-write',
				[
					'annotations' => [
						'readonly'    => 'yes',
						'destructive' => 0,
						'idempotent'  => null,
						'colour'      => 'blue',
					],
				]
			)
		);

		self::assertSame( [ 'readonly' => false, 'destructive' => true, 'idempotent' => false, 'openWorldHint' => false ], $hints );
	}

	public function test_a_malformed_annotations_entry_is_ignored(): void {
		AbilityAnnotations::use_traits( [ 'stonewright/test-read' => self::READ ] );
		$hints = AbilityAnnotations::for_ability( self::ability( 'stonewright/test-read', [ 'annotations' => 'read-only' ] ) );

		self::assertSame( [ 'readonly' => true, 'destructive' => false, 'idempotent' => true, 'openWorldHint' => false ], $hints );
	}

	/**
	 * @param array<string, mixed> $meta Meta the ability returns.
	 */
	private static function ability( string $name, array $meta = [] ): AbilityKernel {
		return new class( $name, $meta ) extends AbilityKernel {

			/** @param array<string, mixed> $meta */
			public function __construct( private string $ability_name, private array $ability_meta ) {}

			public function name(): string {
				return $this->ability_name;
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
