<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Skills;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\Skills\SkillsGet;
use Stonewright\WpMcp\Abilities\Skills\SkillsList;
use Stonewright\WpMcp\Context\ContextBuilder;
use Stonewright\WpMcp\Elementor\Schema\RuntimeFingerprint;
use Stonewright\WpMcp\Skills\Skills;

/**
 * @covers \Stonewright\WpMcp\Skills\Skills
 * @covers \Stonewright\WpMcp\Elementor\Schema\RuntimeFingerprint
 */
final class SkillPresenceGateTest extends TestCase {

	private mixed $original_wpdb;

	protected function setUp(): void {
		$this->original_wpdb = $GLOBALS['wpdb'] ?? null;
		if ( ! defined( 'ELEMENTOR_VERSION' ) ) {
			define( 'ELEMENTOR_VERSION', '3.24.0' );
		}
	}

	protected function tearDown(): void {
		if ( null !== $this->original_wpdb ) {
			$GLOBALS['wpdb'] = $this->original_wpdb;
		} else {
			unset( $GLOBALS['wpdb'] );
		}
	}

	public function test_required_constraint_fails_when_component_absent(): void {
		self::assertFalse(
			RuntimeFingerprint::matches_constraints( [ 'woocommerce' => 'required' ] )
		);
	}

	public function test_elementor_alias_matches_elementor_core_version(): void {
		self::assertTrue(
			RuntimeFingerprint::matches_constraints( [ 'elementor' => '>=3.16' ] )
		);
	}

	public function test_unconstrained_skill_stays_visible(): void {
		$GLOBALS['wpdb'] = $this->wpdb_with_rows(
			[
				$this->row( 'generic-skill', [] ),
				$this->row( 'woo-skill', [ 'woocommerce' => 'required' ] ),
			]
		);

		$agentic = Skills::list_agentic();
		$slugs   = array_column( $agentic, 'slug' );

		self::assertContains( 'generic-skill', $slugs );
		self::assertNotContains( 'woo-skill', $slugs );
	}

	public function test_skills_get_rejects_body_when_required_component_is_absent(): void {
		$GLOBALS['wpdb'] = $this->wpdb_with_rows(
			[
				$this->row( 'woo-skill', [ 'woocommerce' => 'required' ] ),
			]
		);

		$result = ( new SkillsGet() )->execute( [ 'slug' => 'woo-skill' ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_skill_unavailable', $result->get_error_code() );
		self::assertSame( 'woocommerce', $result->get_error_data()['missing_component'] ?? null );
		self::assertStringNotContainsString( 'SECRET BODY', $result->get_error_message() );
	}

	public function test_skills_get_returns_unconstrained_user_skill_body(): void {
		$row              = $this->row( 'user-skill', [] );
		$row['source']    = 'user';
		$GLOBALS['wpdb'] = $this->wpdb_with_rows( [ $row ] );

		$result = ( new SkillsGet() )->execute( [ 'slug' => 'user-skill' ] );

		self::assertIsArray( $result );
		self::assertTrue( $result['found'] );
		self::assertSame( 'SECRET BODY', $result['skill']['content'] );
	}

	public function test_discover_mode_returns_only_slug_and_description(): void {
		$GLOBALS['wpdb'] = $this->wpdb_with_rows(
			[
				$this->row( 'generic-skill', [] ),
			]
		);

		$result = ( new SkillsList() )->execute( [ 'mode' => 'discover' ] );

		self::assertIsArray( $result );
		self::assertSame( 'discover', $result['mode'] );
		self::assertSame( [ 'slug', 'description' ], array_keys( $result['skills'][0] ) );
		self::assertArrayNotHasKey( 'content', $result['skills'][0] );
		self::assertArrayNotHasKey( 'title', $result['skills'][0] );
	}

	public function test_matched_playbooks_omit_bodies_and_point_at_skills_get(): void {
		$GLOBALS['stonewright_test_options']['stonewright_memory_enabled'] = false;
		$GLOBALS['wpdb'] = $this->wpdb_with_rows(
			[
				$this->row( 'generic-skill', [] ),
			]
		);

		$built = ContextBuilder::build( 'generic skill playbook for a page', 'gutenberg', 'write' );

		self::assertNotEmpty( $built['matched_skill_playbooks'] );
		self::assertSame( 'generic-skill', $built['matched_skill_playbooks'][0]['slug'] );
		self::assertArrayNotHasKey( 'content', $built['matched_skill_playbooks'][0] );
		self::assertSame( 'stonewright/skills-get', $built['matched_skill_playbooks'][0]['body_tool'] );
		self::assertArrayHasKey( 'description', $built['matched_skills'][0] );
		self::assertArrayNotHasKey( 'content', $built['matched_skills'][0] );
	}

	/**
	 * @return list<array{0: string, 1: string}>
	 */
	public static function build_page_skill_provider(): array {
		return [
			[ 'blocksy-build-page', 'blocksy' ],
			[ 'kadence-build-page', 'kadence-blocks' ],
			[ 'generateblocks-build-page', 'generateblocks' ],
			[ 'spectra-build-page', 'spectra' ],
		];
	}

	/**
	 * @dataProvider build_page_skill_provider
	 */
	public function test_build_page_skill_declares_required_constraint_and_finalizer_path( string $directory, string $component ): void {
		$path = dirname( __DIR__, 3 ) . '/../skills/' . $directory . '/SKILL.md';
		self::assertFileExists( $path );
		$body = (string) file_get_contents( $path );

		self::assertStringStartsWith( '---', ltrim( $body ) );
		self::assertMatchesRegularExpression( '/^version_constraints:\s*(\{.+\})$/m', $body );
		preg_match( '/^version_constraints:\s*(\{.+\})$/m', $body, $match );
		$decoded = json_decode( (string) ( $match[1] ?? '' ), true );
		self::assertIsArray( $decoded );
		self::assertSame( 'required', $decoded[ $component ] ?? null );
		self::assertStringContainsString( 'stonewright-blocks-library-check-setup', $body );
		self::assertStringContainsString( 'stonewright-blocks-library-list-blocks', $body );
		self::assertStringContainsString( 'stonewright-blocks-library-get-schema', $body );
		self::assertStringContainsString( 'stonewright-blocks-queue-change', $body );
		self::assertStringContainsString( 'stonewright-blocks-finalize-batch', $body );
		self::assertStringContainsString( 'name, attributes, innerBlocks', $body );
		self::assertStringNotContainsString( 'post_content', $body );
	}

	public function test_gutenberg_fse_builder_covers_spectra_one_without_a_duplicate_pack(): void {
		self::assertFileDoesNotExist( dirname( __DIR__, 3 ) . '/../skills/spectra-one/SKILL.md' );
		$body = (string) file_get_contents( dirname( __DIR__, 3 ) . '/../skills/gutenberg-fse-builder/SKILL.md' );
		self::assertStringContainsString( 'Spectra One', $body );
	}

	/**
	 * @param array<string, string> $constraints
	 * @return array<string, string>
	 */
	private function row( string $slug, array $constraints ): array {
		return [
			'id'                         => $slug,
			'slug'                       => $slug,
			'title'                      => $slug,
			'description'                => 'A one-line description.',
			'content'                    => 'SECRET BODY',
			'enabled'                    => '1',
			'enable_agentic'             => '1',
			'enable_prompt'              => '1',
			'source'                     => 'builtin',
			'status'                     => 'active',
			'version_constraints_json'   => (string) wp_json_encode( $constraints ),
		];
	}

	/**
	 * @param list<array<string, string>> $rows
	 */
	private function wpdb_with_rows( array $rows ): object {
		return new class( $rows ) {
			public string $prefix = 'wp_';

			/** @var list<array<string, string>> */
			private array $rows;

			/** @param list<array<string, string>> $rows */
			public function __construct( array $rows ) {
				$this->rows = $rows;
			}

			public function get_var( string $query ): string {
				return 'wp_stonewright_skills';
			}

			public function prepare( string $query, mixed ...$args ): string {
				return $query;
			}

			/** @return list<array<string, string>> */
			public function get_results( string $query, string $output = 'OBJECT' ): array {
				return $this->rows;
			}

			/** @return array<string, string>|null */
			public function get_row( string $query, string $output = 'OBJECT' ): ?array {
				return $this->rows[0] ?? null;
			}
		};
	}
}
