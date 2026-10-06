<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\SkillLibrary\Site;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\SkillLibrary\PackInventory;
use Stonewright\WpMcp\SkillLibrary\Site\BundledPack;
use Stonewright\WpMcp\SkillLibrary\Site\SkillLibraryService;
use Stonewright\WpMcp\SkillLibrary\Site\WordPressBoundary;

/**
 * @covers \Stonewright\WpMcp\SkillLibrary\Site\BundledPack
 * @covers \Stonewright\WpMcp\SkillLibrary\Site\SystemWrites
 * @covers \Stonewright\WpMcp\SkillLibrary\Site\SkillLibraryService
 */
final class BundledPackTest extends TestCase {

	private const SHIPPED_SKILLS = [
		'stonewright-acf-build-fields',
		'stonewright-agent-operating-rules',
		'stonewright-blocksy-build-page',
		'stonewright-content-model-integrations',
		'stonewright-design-to-wordpress',
		'stonewright-elementor-site-clone',
		'stonewright-elementor-v3-builder',
		'stonewright-elementor-v4-atomic',
		'stonewright-forms-inventory',
		'stonewright-generateblocks-build-page',
		'stonewright-gutenberg-fse-builder',
		'stonewright-how-to-write-skills',
		'stonewright-kadence-build-page',
		'stonewright-seo-optimize',
		'stonewright-spectra-build-page',
		'stonewright-stonewright',
		'stonewright-stonewright-review',
		'stonewright-visual-direction',
		'stonewright-woocommerce-catalog',
		'stonewright-wp-plugin-dev',
	];

	private const SHIPPED_PLAYBOOKS = [
		'playbook-ab-copy-variants',
		'playbook-about-page',
		'playbook-blog-series',
		'playbook-contact-page',
		'playbook-faq-accordion',
		'playbook-homepage-hero',
		'playbook-mega-menu',
		'playbook-multilingual-prep',
		'playbook-page-migration',
		'playbook-popup-lead-capture',
		'playbook-portfolio-gallery',
		'playbook-pricing-table',
		'playbook-section-refactor',
		'playbook-seo-on-page-audit',
		'playbook-services-grid',
		'playbook-stock-image-fill',
		'playbook-testimonials-section',
		'playbook-woocommerce-catalog-setup',
	];

	private mixed $original_wpdb;

	private SkillTablesDouble $tables;

	private string $pack = '';

	protected function setUp(): void {
		$this->original_wpdb                         = $GLOBALS['wpdb'] ?? null;
		$this->tables                                = new SkillTablesDouble();
		$GLOBALS['wpdb']                             = $this->tables;
		$GLOBALS['stonewright_test_options']         = [];
		$GLOBALS['stonewright_test_user_caps']       = [];
		$GLOBALS['stonewright_test_current_user_id'] = 0;
	}

	protected function tearDown(): void {
		$GLOBALS['wpdb']                     = $this->original_wpdb;
		$GLOBALS['stonewright_test_options'] = [];
		if ( '' !== $this->pack ) {
			self::remove_tree( $this->pack );
		}
	}

	public function test_identities_follow_the_bundled_layout(): void {
		$inventory = PackInventory::scan( BundledPack::root() );
		self::assertIsArray( $inventory );
		self::assertSame( [], $inventory['diagnostics'] );

		$identities = BundledPack::identities( $inventory );
		$expected   = array_merge( self::SHIPPED_SKILLS, self::SHIPPED_PLAYBOOKS );
		sort( $expected );
		$actual = array_values( $identities );
		sort( $actual );

		self::assertSame( $expected, $actual );
		self::assertSame( 'stonewright-acf-build-fields', $identities['acf-build-fields'] );
		self::assertSame( 'playbook-mega-menu', $identities['playbooks/mega-menu'] );
		self::assertSame( 'stonewright-how-to-write-skills', $identities['how-to-write-skills'] );
		$listed = BundledPack::slugs();
		sort( $listed );
		self::assertSame( $expected, $listed );
	}

	public function test_fresh_install_seeds_every_bundled_entry_enabled_active_and_unaudited(): void {
		$counts = $this->service()->refresh_bundled_pack();

		self::assertIsArray( $counts );
		self::assertSame( 38, $counts['inserted'] );
		self::assertCount( 38, $this->tables->skills );
		$sources = array_count_values( array_column( $this->tables->skills, 'source' ) );
		self::assertSame( [ 'builtin' => 20, 'playbook' => 18 ], [ 'builtin' => $sources['builtin'], 'playbook' => $sources['playbook'] ] );
		foreach ( $this->tables->skills as $row ) {
			self::assertSame( [ '1', '1', '1' ], [ $row['enabled'], $row['enable_agentic'], $row['enable_prompt'] ], (string) $row['slug'] );
			self::assertSame( [ 'active', '1', '0', '[]' ], [ $row['status'], $row['revision'], $row['verification_count'], $row['conflict_json'] ], (string) $row['slug'] );
			self::assertSame( $row['created_at'], $row['updated_at'] );
		}
		self::assertSame( 'elementor-visual-direction', $this->tables->skill_by_slug( 'stonewright-visual-direction' )['topic'] ?? null );
		self::assertSame( '{"acf":"required"}', $this->tables->skill_by_slug( 'stonewright-acf-build-fields' )['version_constraints_json'] ?? null );
		self::assertSame( '{"any_of":"acf|acpt|meta-box|ase|pods"}', $this->tables->skill_by_slug( 'stonewright-content-model-integrations' )['version_constraints_json'] ?? null );
		self::assertSame( '[]', $this->tables->skill_by_slug( 'playbook-mega-menu' )['version_constraints_json'] ?? null );
		self::assertSame( 'Mega menu', $this->tables->skill_by_slug( 'playbook-mega-menu' )['title'] ?? null );
		$guide = $this->tables->skill_by_slug( 'stonewright-how-to-write-skills' );
		self::assertIsArray( $guide );
		self::assertSame( [ 'builtin', 'how-to-write-skills', '[]', '' ], [ $guide['source'], $guide['title'], $guide['version_constraints_json'], $guide['topic'] ] );
		self::assertStringStartsWith( 'Use when', (string) $guide['description'] );
		self::assertSame( [], $this->tables->versions );
		self::assertSame( [], $this->audit_inserts() );
	}

	public function test_a_second_refresh_changes_nothing(): void {
		$this->service()->refresh_bundled_pack();
		$before = $this->tables->skills;

		$counts = $this->service()->refresh_bundled_pack();

		self::assertIsArray( $counts );
		self::assertSame( 0, $counts['inserted'] + $counts['updated'] + $counts['reclaimed'] + $counts['retired'] );
		self::assertSame( 38, $counts['unchanged'] );
		self::assertSame( $before, $this->tables->skills );
		self::assertSame( [], $this->tables->versions );
	}

	public function test_upgrade_updates_changed_text_keeps_site_flags_and_records_the_prior_text(): void {
		$this->write_pack( [ 'alpha' => self::skill( 'alpha', 'Use when testing alpha.', '# Alpha one' ) ], [ 'beta' => self::skill( 'Beta', 'Use when testing beta.', '# Beta' ) ] );
		$this->service()->refresh_bundled_pack( $this->pack );
		$alpha = $this->tables->skill_by_slug( 'stonewright-alpha' );
		self::assertIsArray( $alpha );
		$this->tables->skills[ (int) $alpha['id'] ]['enabled']       = '0';
		$this->tables->skills[ (int) $alpha['id'] ]['enable_prompt'] = '0';

		$this->write_pack( [ 'alpha' => self::skill( 'alpha', 'Use when testing alpha.', '# Alpha two', 'version_constraints: {"acf": "required"}' ) ], [ 'beta' => self::skill( 'Beta', 'Use when testing beta.', '# Beta' ) ] );
		$counts = $this->service()->refresh_bundled_pack( $this->pack );

		self::assertIsArray( $counts );
		self::assertSame( 1, $counts['updated'] );
		$row = $this->tables->skill_by_slug( 'stonewright-alpha' );
		self::assertIsArray( $row );
		self::assertSame( "# Alpha two\n", $row['content'] );
		self::assertSame( '{"acf":"required"}', $row['version_constraints_json'] );
		self::assertSame( [ '0', '1', '0', '2' ], [ $row['enabled'], $row['enable_agentic'], $row['enable_prompt'], $row['revision'] ] );
		self::assertCount( 1, $this->tables->versions );
		$snapshot = json_decode( (string) array_values( $this->tables->versions )[0]['snapshot_json'], true );
		self::assertSame( "# Alpha one\n", $snapshot['content'] );
		self::assertSame( '1', $snapshot['revision'] );
		self::assertSame( '1', $this->tables->skill_by_slug( 'playbook-beta' )['revision'] ?? null );
	}

	public function test_a_built_in_slug_held_by_another_source_is_reclaimed_after_a_snapshot(): void {
		$this->write_pack( [ 'alpha' => self::skill( 'alpha', 'Use when testing alpha.', '# Shipped alpha' ) ], [] );
		$this->tables->seed_skill(
			[
				'slug'           => 'stonewright-alpha',
				'title'          => 'My alpha',
				'description'    => 'Use when the site owner wrote it.',
				'content'        => '# Site owner text',
				'source'         => 'user',
				'enabled'        => 0,
				'enable_agentic' => 0,
				'status'         => 'draft',
				'revision'       => 3,
			]
		);

		$counts = $this->service()->refresh_bundled_pack( $this->pack );

		self::assertIsArray( $counts );
		self::assertSame( 1, $counts['reclaimed'] );
		$row = $this->tables->skill_by_slug( 'stonewright-alpha' );
		self::assertIsArray( $row );
		self::assertSame( [ 'builtin', 'active', "# Shipped alpha\n", '4' ], [ $row['source'], $row['status'], $row['content'], $row['revision'] ] );
		self::assertSame( [ '1', '1', '1' ], [ $row['enabled'], $row['enable_agentic'], $row['enable_prompt'] ] );
		$snapshot = json_decode( (string) array_values( $this->tables->versions )[0]['snapshot_json'], true );
		self::assertSame( [ 'user', '# Site owner text', '3' ], [ $snapshot['source'], $snapshot['content'], $snapshot['revision'] ] );
	}

	public function test_entries_that_no_longer_ship_are_retired_and_return_when_shipped_again(): void {
		$this->write_pack( [ 'alpha' => self::skill( 'alpha', 'Use when testing alpha.', '# Alpha' ) ], [] );
		$this->tables->seed_skill( [ 'slug' => 'stonewright-gone', 'title' => 'Gone', 'content' => '# Gone', 'source' => 'builtin', 'enable_prompt' => 0 ] );
		$this->tables->seed_skill( [ 'slug' => 'playbook-landing-page-agency', 'title' => 'Landing', 'content' => '# Landing', 'source' => 'playbook' ] );
		$this->tables->seed_skill( [ 'slug' => 'my-note', 'title' => 'Mine', 'content' => '# Mine', 'source' => 'user' ] );
		$this->tables->seed_skill( [ 'slug' => 'imported-note', 'title' => 'Imported', 'content' => '# Imported', 'source' => 'uploaded', 'status' => 'draft', 'enabled' => 0 ] );
		$untouched = [ $this->tables->skill_by_slug( 'my-note' ), $this->tables->skill_by_slug( 'imported-note' ) ];

		$counts = $this->service()->refresh_bundled_pack( $this->pack );

		self::assertIsArray( $counts );
		self::assertSame( 2, $counts['retired'] );
		$gone = $this->tables->skill_by_slug( 'stonewright-gone' );
		self::assertIsArray( $gone );
		self::assertSame( [ 'retired', '1', '0', '1' ], [ $gone['status'], $gone['enabled'], $gone['enable_prompt'], $gone['revision'] ] );
		self::assertSame( 'retired', $this->tables->skill_by_slug( 'playbook-landing-page-agency' )['status'] ?? null );
		self::assertSame( $untouched, [ $this->tables->skill_by_slug( 'my-note' ), $this->tables->skill_by_slug( 'imported-note' ) ] );
		self::assertSame( [], $this->tables->versions );

		$this->write_pack( [ 'alpha' => self::skill( 'alpha', 'Use when testing alpha.', '# Alpha' ), 'gone' => self::skill( 'gone', 'Use when it ships again.', '# Gone' ) ], [] );
		$this->service()->refresh_bundled_pack( $this->pack );

		$back = $this->tables->skill_by_slug( 'stonewright-gone' );
		self::assertIsArray( $back );
		self::assertSame( [ 'active', '0' ], [ $back['status'], $back['enable_prompt'] ] );
	}

	public function test_an_unusable_pack_changes_nothing(): void {
		$this->write_pack( [ 'broken' => "# No front matter\n" ], [] );

		$result = $this->service()->refresh_bundled_pack( $this->pack );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( [], $this->tables->skills );
		self::assertInstanceOf( \WP_Error::class, $this->service()->refresh_bundled_pack( $this->pack . '/missing' ) );
	}

	private function service(): SkillLibraryService {
		return SkillLibraryService::open( WordPressBoundary::SYSTEM );
	}

	/** @return array<int, array<string, mixed>> */
	private function audit_inserts(): array {
		return array_values( array_filter( $this->tables->inserts, static fn( array $insert ): bool => str_contains( $insert['table'], 'audit_log' ) ) );
	}

	/**
	 * @param array<string, string> $skills
	 * @param array<string, string> $playbooks
	 */
	private function write_pack( array $skills, array $playbooks ): void {
		if ( '' === $this->pack ) {
			$this->pack = sys_get_temp_dir() . '/stonewright-pack-' . bin2hex( random_bytes( 6 ) );
		}
		self::remove_tree( $this->pack );
		mkdir( $this->pack . '/playbooks', 0777, true );
		foreach ( $skills as $key => $markdown ) {
			mkdir( $this->pack . '/' . $key );
			file_put_contents( $this->pack . '/' . $key . '/SKILL.md', $markdown );
		}
		foreach ( $playbooks as $key => $markdown ) {
			file_put_contents( $this->pack . '/playbooks/' . $key . '.md', $markdown );
		}
	}

	private static function skill( string $name, string $description, string $body, string $extra = '' ): string {
		return "---\nname: {$name}\ndescription: {$description}\n" . ( '' === $extra ? '' : $extra . "\n" ) . "---\n\n{$body}\n";
	}

	private static function remove_tree( string $path ): void {
		if ( ! is_dir( $path ) ) {
			return;
		}
		$items = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $path, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::CHILD_FIRST );
		foreach ( $items as $item ) {
			$item->isDir() ? rmdir( $item->getPathname() ) : unlink( $item->getPathname() );
		}
		rmdir( $path );
	}
}
