<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Skills;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Stonewright\WpMcp\Skills\Skills;
use Stonewright\WpMcp\Skills\SkillsSeeder;

/**
 * Retired packaged landing-page playbooks must leave the tree and the table.
 *
 * @covers \Stonewright\WpMcp\Skills\Skills
 * @covers \Stonewright\WpMcp\Skills\SkillsSeeder
 */
final class LandingPlaybookRetirementTest extends TestCase {

	/** @var list<string> */
	private const RETIRED_FILES = [
		'landing-page-agency.md',
		'landing-page-saas.md',
		'landing-page-law-firm.md',
		'landing-page-healthcare.md',
		'landing-page-nonprofit.md',
		'landing-page-real-estate.md',
		'landing-page-restaurant.md',
	];

	/** @var list<string> */
	private const RETIRED_SLUGS = [
		'playbook-landing-page-agency',
		'playbook-landing-page-saas',
		'playbook-landing-page-law-firm',
		'playbook-landing-page-healthcare',
		'playbook-landing-page-nonprofit',
		'playbook-landing-page-real-estate',
		'playbook-landing-page-restaurant',
	];

	/** @var mixed */
	private mixed $original_wpdb;

	protected function setUp(): void {
		$this->original_wpdb = $GLOBALS['wpdb'] ?? null;
	}

	protected function tearDown(): void {
		if ( null !== $this->original_wpdb ) {
			$GLOBALS['wpdb'] = $this->original_wpdb;
		} else {
			unset( $GLOBALS['wpdb'] );
		}
	}

	public function test_retired_landing_page_playbook_files_do_not_exist(): void {
		$dir = dirname( __DIR__, 4 ) . '/skills/playbooks';

		foreach ( self::RETIRED_FILES as $file ) {
			$this->assertFileDoesNotExist( $dir . '/' . $file );
		}

		$this->assertFileExists( $dir . '/homepage-hero.md' );
		$this->assertFileExists( $dir . '/about-page.md' );
	}

	/**
	 * @dataProvider retired_slugs
	 */
	public function test_retire_packaged_slug_deletes_playbook_rows( string $slug ): void {
		$GLOBALS['wpdb'] = $this->make_wpdb_with_skill(
			[
				'id'     => '11',
				'slug'   => $slug,
				'source' => 'playbook',
			]
		);

		$this->assertTrue( Skills::retire_packaged_slug( $slug ) );
		$this->assertSame( 1, $GLOBALS['wpdb']->delete_calls );
		$this->assertSame( $slug, $GLOBALS['wpdb']->deleted['slug'] ?? null );
		$this->assertSame( 'playbook', $GLOBALS['wpdb']->deleted['source'] ?? null );
	}

	public function test_retire_packaged_slug_keeps_user_rows_for_the_same_slug(): void {
		$slug = 'playbook-landing-page-agency';
		$GLOBALS['wpdb'] = $this->make_wpdb_with_skill(
			[
				'id'     => '12',
				'slug'   => $slug,
				'source' => 'user',
			]
		);

		$this->assertFalse( Skills::retire_packaged_slug( $slug ) );
		$this->assertSame( 0, $GLOBALS['wpdb']->delete_calls );
	}

	/**
	 * @dataProvider preserved_sources
	 */
	public function test_retire_packaged_slug_never_touches_site_owned_rows( string $source ): void {
		$GLOBALS['wpdb'] = $this->make_wpdb_with_skill(
			[
				'id'     => '13',
				'slug'   => 'playbook-landing-page-saas',
				'source' => $source,
			]
		);

		$this->assertFalse( Skills::retire_packaged_slug( 'playbook-landing-page-saas' ) );
		$this->assertSame( 0, $GLOBALS['wpdb']->delete_calls );
	}

	public function test_seeder_retires_the_packaged_landing_page_slugs(): void {
		$source = $this->method_source( SkillsSeeder::class, 'seed' );

		$this->assertStringContainsString( 'retire_packaged_slug', $source );
		$this->assertDoesNotMatchRegularExpression( '/\b(?:DROP|TRUNCATE|DELETE\s+FROM)\b/i', $source );

		foreach ( self::RETIRED_SLUGS as $slug ) {
			$this->assertTrue(
				str_contains( $source, $slug ) || str_contains( $source, 'retire_packaged_slug' ),
				'seed() must retire ' . $slug
			);
		}
	}

	/** @return array<string, array{string}> */
	public static function retired_slugs(): array {
		$cases = [];
		foreach ( self::RETIRED_SLUGS as $slug ) {
			$cases[ $slug ] = [ $slug ];
		}

		return $cases;
	}

	/** @return array<string, array{string}> */
	public static function preserved_sources(): array {
		return [
			'user'      => [ 'user' ],
			'uploaded'  => [ 'uploaded' ],
			'candidate' => [ 'candidate' ],
		];
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private function make_wpdb_with_skill( array $row ): object {
		return new class( $row ) {
			public string $prefix = 'wp_';

			public int $delete_calls = 0;

			/** @var array<string, mixed>|null */
			public ?array $deleted = null;

			/** @var list<mixed> */
			private array $last_args = [];

			/** @param array<string, mixed> $row */
			public function __construct( private array $row ) {}

			public function get_var( string $q = '' ): string {
				return 'wp_stonewright_skills';
			}

			public function prepare( string $q, mixed ...$args ): string {
				$this->last_args = $args;
				return $q;
			}

			/** @return array<string, mixed>|null */
			public function get_row( string $q, string $output = 'OBJECT' ): ?array {
				$needle = (string) ( $this->last_args[0] ?? '' );
				if ( (string) ( $this->row['slug'] ?? '' ) === $needle || (string) ( $this->row['id'] ?? '' ) === $needle ) {
					return $this->row;
				}

				return null;
			}

			/**
			 * @param array<string, mixed> $where
			 * @param array<string, mixed> $where_format
			 */
			public function delete( string $table, array $where, array $where_format = [] ): int {
				++$this->delete_calls;
				$this->deleted = $where;
				return 1;
			}
		};
	}

	private function method_source( string $class, string $method ): string {
		$reflection = new ReflectionMethod( $class, $method );
		$file       = (string) $reflection->getFileName();
		$lines      = file( $file );
		$this->assertIsArray( $lines );

		return implode(
			'',
			array_slice(
				$lines,
				$reflection->getStartLine() - 1,
				$reflection->getEndLine() - $reflection->getStartLine() + 1
			)
		);
	}
}
