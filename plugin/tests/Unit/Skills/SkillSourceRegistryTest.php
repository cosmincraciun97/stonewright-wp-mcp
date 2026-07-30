<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Skills;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Skills\SkillSource;
use Stonewright\WpMcp\Skills\SkillSourceRegistry;

/**
 * A site can read skills from more than one place. The registry decides which
 * copy wins when two places offer the same slug, and it must never let an
 * outside source quietly take over a built-in or locally authored skill.
 *
 * @covers \Stonewright\WpMcp\Skills\SkillSource
 * @covers \Stonewright\WpMcp\Skills\SkillSourceRegistry
 */
final class SkillSourceRegistryTest extends TestCase {

	/** @var mixed Saved $wpdb reference restored in tearDown. */
	private mixed $original_wpdb;

	protected function setUp(): void {
		$this->original_wpdb                 = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['stonewright_test_filters'] = [];
		SkillSourceRegistry::reset();
	}

	protected function tearDown(): void {
		SkillSourceRegistry::reset();
		$GLOBALS['stonewright_test_filters'] = [];
		if ( null !== $this->original_wpdb ) {
			$GLOBALS['wpdb'] = $this->original_wpdb;
		} else {
			unset( $GLOBALS['wpdb'] );
		}
	}

	public function test_builtin_and_local_skills_are_separate_sources(): void {
		$GLOBALS['wpdb'] = $this->wpdb_with_rows(
			[
				$this->row( 'stonewright-elementor-v3-builder', 'builtin' ),
				$this->row( 'playbook-launch-checklist', 'playbook' ),
				$this->row( 'our-house-style', 'user' ),
			]
		);

		$sources = SkillSourceRegistry::sources();
		$ids     = array_map( static fn( SkillSource $source ): string => $source->id(), $sources );

		$this->assertSame( [ 'builtin', 'database' ], $ids );
		$this->assertCount( 2, $sources[0]->skills(), 'Built-in and playbook skills belong to the built-in source.' );
		$this->assertCount( 1, $sources[1]->skills(), 'Locally authored skills belong to the database source.' );
		$this->assertFalse( $sources[0]->is_external() );
		$this->assertFalse( $sources[1]->is_external() );
	}

	public function test_registered_source_resolves_after_builtin_and_database(): void {
		$GLOBALS['wpdb'] = $this->wpdb_with_rows( [ $this->row( 'our-house-style', 'user' ) ] );

		SkillSourceRegistry::register(
			new SkillSource( 'team', 'Team library', SkillSource::KIND_EXTERNAL, [ $this->row( 'team/spacing-rules', 'external' ) ] )
		);

		$resolved = SkillSourceRegistry::resolve();
		$slugs    = array_column( $resolved['skills'], 'slug' );

		$this->assertSame( [ 'our-house-style', 'team/spacing-rules' ], $slugs );
		$this->assertSame( [], $resolved['conflicts'] );
		$this->assertSame( 'team', $resolved['skills'][1]['source_id'] );
		$this->assertSame( SkillSource::KIND_EXTERNAL, $resolved['skills'][1]['source_kind'] );
	}

	public function test_external_slug_must_be_source_qualified(): void {
		$GLOBALS['wpdb'] = $this->wpdb_with_rows( [] );

		SkillSourceRegistry::register(
			new SkillSource( 'team', 'Team library', SkillSource::KIND_EXTERNAL, [ $this->row( 'spacing-rules', 'external' ) ] )
		);

		$resolved = SkillSourceRegistry::resolve();

		$this->assertSame( [], $resolved['skills'] );
		$this->assertCount( 1, $resolved['conflicts'] );
		$this->assertSame( 'external_slug_not_source_qualified', $resolved['conflicts'][0]['reason'] );
		$this->assertSame( 'spacing-rules', $resolved['conflicts'][0]['slug'] );
		$this->assertSame( 'team', $resolved['conflicts'][0]['dropped'] );
	}

	public function test_external_source_cannot_replace_a_builtin_skill(): void {
		$GLOBALS['wpdb'] = $this->wpdb_with_rows( [ $this->row( 'stonewright-elementor-v3-builder', 'builtin' ) ] );

		SkillSourceRegistry::register(
			new SkillSource(
				'team',
				'Team library',
				SkillSource::KIND_EXTERNAL,
				[ $this->row( 'stonewright-elementor-v3-builder', 'external' ) ]
			)
		);

		$resolved = SkillSourceRegistry::resolve();
		$slugs    = array_column( $resolved['skills'], 'slug' );

		$this->assertSame( [ 'stonewright-elementor-v3-builder' ], $slugs );
		$this->assertSame( 'builtin', $resolved['skills'][0]['source_id'] );
		$this->assertNotSame( [], $resolved['conflicts'] );
		$this->assertSame( 'builtin', $resolved['conflicts'][0]['kept'] );
		$this->assertSame( 'team', $resolved['conflicts'][0]['dropped'] );
	}

	public function test_two_sources_offering_the_same_slug_report_a_conflict(): void {
		$GLOBALS['wpdb'] = $this->wpdb_with_rows( [] );

		SkillSourceRegistry::register(
			new SkillSource( 'alpha', 'Alpha', SkillSource::KIND_EXTERNAL, [ $this->row( 'alpha/rules', 'external' ) ] )
		);
		SkillSourceRegistry::register(
			new SkillSource( 'beta', 'Beta', SkillSource::KIND_EXTERNAL, [ $this->row( 'alpha/rules', 'external' ) ] )
		);

		$resolved = SkillSourceRegistry::resolve();

		$this->assertCount( 1, $resolved['skills'] );
		$this->assertSame( 'alpha', $resolved['skills'][0]['source_id'] );
		$this->assertCount( 1, $resolved['conflicts'] );
		$this->assertSame( 'beta', $resolved['conflicts'][0]['dropped'] );
	}

	public function test_reserved_source_ids_are_refused(): void {
		$GLOBALS['wpdb'] = $this->wpdb_with_rows( [] );

		foreach ( [ 'builtin', 'database', 'stonewright', '' ] as $id ) {
			SkillSourceRegistry::register(
				new SkillSource( $id, 'Impostor', SkillSource::KIND_EXTERNAL, [ $this->row( $id . '/rules', 'external' ) ] )
			);
		}

		$ids = array_map( static fn( SkillSource $source ): string => $source->id(), SkillSourceRegistry::sources() );

		$this->assertSame( [ 'builtin', 'database' ], $ids );
	}

	public function test_a_source_declared_as_builtin_by_a_caller_is_treated_as_external(): void {
		$GLOBALS['wpdb'] = $this->wpdb_with_rows( [] );

		SkillSourceRegistry::register(
			new SkillSource( 'team', 'Team library', SkillSource::KIND_BUILTIN, [ $this->row( 'team/rules', 'external' ) ] )
		);

		$sources = SkillSourceRegistry::sources();

		$this->assertCount( 3, $sources );
		$this->assertSame( 'team', $sources[2]->id() );
		$this->assertTrue( $sources[2]->is_external(), 'Only Stonewright itself can declare a built-in source.' );
	}

	public function test_filter_can_add_a_source_and_junk_entries_are_ignored(): void {
		$GLOBALS['wpdb'] = $this->wpdb_with_rows( [] );

		add_filter(
			SkillSourceRegistry::FILTER,
			static function ( array $sources ): array {
				$sources[] = new SkillSource( 'partner', 'Partner', SkillSource::KIND_EXTERNAL, [] );
				$sources[] = 'not-a-source';
				$sources[] = [ 'id' => 'also-not-a-source' ];
				return $sources;
			}
		);

		$ids = array_map( static fn( SkillSource $source ): string => $source->id(), SkillSourceRegistry::sources() );

		$this->assertSame( [ 'builtin', 'database', 'partner' ], $ids );
	}

	public function test_registry_never_executes_or_fetches_anything(): void {
		$source = new SkillSource( 'team', 'Team library', SkillSource::KIND_EXTERNAL, [ $this->row( 'team/rules', 'external' ) ] );

		$this->assertSame( 'team/', $source->slug_prefix() );
		$this->assertSame(
			[
				'id'    => 'team',
				'label' => 'Team library',
				'kind'  => SkillSource::KIND_EXTERNAL,
				'count' => 1,
			],
			$source->to_array()
		);

		$file = (string) ( new \ReflectionClass( SkillSourceRegistry::class ) )->getFileName();
		$code = (string) file_get_contents( $file );

		$forbidden = [ 'wp_remote_get', 'wp_safe_remote_get', 'file_get_contents', 'include', 'require', 'eval' . '(', 'call_user_func' ];
		foreach ( $forbidden as $needle ) {
			$this->assertStringNotContainsString(
				$needle,
				$code,
				'Source enumeration stays read-only: ' . $needle . ' must not appear in the registry.'
			);
		}
	}

	/** @return array<string, mixed> */
	private function row( string $slug, string $source ): array {
		return [
			'id'          => abs( crc32( $slug ) ),
			'slug'        => $slug,
			'title'       => ucfirst( str_replace( [ '-', '/' ], ' ', $slug ) ),
			'description' => 'Use when working on ' . $slug . '.',
			'content'     => '# ' . $slug,
			'enabled'     => 1,
			'source'      => $source,
			'status'      => 'active',
			'revision'    => 1,
		];
	}

	/** @param list<array<string, mixed>> $rows */
	private function wpdb_with_rows( array $rows ): object {
		return new class( $rows ) {
			public string $prefix = 'wp_';

			/** @param list<array<string, mixed>> $rows */
			public function __construct( private array $rows ) {}

			public function get_var( string $q ): string {
				return 'wp_stonewright_skills';
			}

			/** @return list<array<string, mixed>> */
			public function get_results( string $q, string $output = 'OBJECT' ): array {
				return $this->rows;
			}

			public function prepare( string $q, mixed ...$args ): string {
				return $q;
			}
		};
	}
}
