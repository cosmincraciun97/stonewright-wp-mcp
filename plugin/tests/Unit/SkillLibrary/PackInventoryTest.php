<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\SkillLibrary;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\SkillLibrary\PackInventory;
use Stonewright\WpMcp\SkillLibrary\PackRefresh;

/** @covers \Stonewright\WpMcp\SkillLibrary\PackInventory @covers \Stonewright\WpMcp\SkillLibrary\PackRefresh */
final class PackInventoryTest extends TestCase {

	private string $root;

	protected function setUp(): void {
		$this->root = sys_get_temp_dir() . '/stonewright-pack-' . bin2hex( random_bytes( 8 ) );
		mkdir( $this->root . '/example', 0700, true );
		mkdir( $this->root . '/playbooks', 0700, true );
		file_put_contents( $this->root . '/example/SKILL.md', "---\nname: Example guide\ndescription: Use when writing examples.\n---\n# Example\n" );
		file_put_contents( $this->root . '/playbooks/hero.md', "---\nname: Hero guide\ndescription: Use when writing a hero.\n---\n# Hero\n" );
		file_put_contents( $this->root . '/example/notes.md', 'Ignored reference' );
	}

	protected function tearDown(): void {
		foreach ( [ '/example/SKILL.md', '/example/notes.md', '/playbooks/hero.md' ] as $file ) {
			unlink( $this->root . $file );
		}
		rmdir( $this->root . '/example' );
		rmdir( $this->root . '/playbooks' );
		rmdir( $this->root );
	}

	public function test_inventory_reads_only_product_entry_documents(): void {
		$inventory = PackInventory::scan( $this->root );
		$this->assertIsArray( $inventory );
		$this->assertSame( [ 'example', 'playbooks/hero' ], array_column( $inventory['entries'], 'pack_key' ) );
		$this->assertSame( 'Example guide', $inventory['entries'][0]['record']['title'] );
		$this->assertSame( [], $inventory['diagnostics'] );
	}

	public function test_missing_pack_fails_closed(): void {
		$this->assertInstanceOf( \WP_Error::class, PackInventory::scan( $this->root . '/absent' ) );
	}

	public function test_refresh_preserves_preferences_and_unspecified_metadata(): void {
		$inventory = PackInventory::scan( $this->root );
		$this->assertIsArray( $inventory );
		$existing = [ [ 'id' => 42, 'slug' => 'known-example-id', 'source' => 'builtin', 'enabled' => false, 'enable_agentic' => false, 'enable_prompt' => true, 'topic' => 'Site-recorded topic', 'version_constraints' => [ 'elementor' => 'required' ], 'content' => 'Old product body' ], [ 'id' => 43, 'slug' => 'local-guide', 'source' => 'user', 'content' => 'Private synthetic body' ] ];
		$plan = PackRefresh::plan( $inventory, $existing, [ 'example' => 'known-example-id', 'playbooks/hero' => 'known-hero-id' ] );
		$this->assertIsArray( $plan );
		$this->assertCount( 2, $plan['upserts'] );
		$this->assertFalse( $plan['upserts'][0]['enabled'] );
		$this->assertFalse( $plan['upserts'][0]['enable_agentic'] );
		$this->assertTrue( $plan['upserts'][0]['enable_prompt'] );
		$this->assertSame( 'Site-recorded topic', $plan['upserts'][0]['topic'] );
		$this->assertSame( [ 'elementor' => 'required' ], $plan['upserts'][0]['version_constraints'] );
		$this->assertNotContains( 'local-guide', array_column( $plan['upserts'], 'slug' ) );
	}

	public function test_refresh_refuses_unknown_identity_or_local_collision(): void {
		$inventory = PackInventory::scan( $this->root );
		$this->assertIsArray( $inventory );
		$this->assertInstanceOf( \WP_Error::class, PackRefresh::plan( $inventory, [], [] ) );
		$plan = PackRefresh::plan( $inventory, [ [ 'slug' => 'known-example-id', 'source' => 'user' ] ], [ 'example' => 'known-example-id', 'playbooks/hero' => 'known-hero-id' ] );
		$this->assertIsArray( $plan );
		$this->assertSame( [ 'known-example-id' ], array_column( $plan['conflicts'], 'slug' ) );
		$this->assertSame( [ 'known-hero-id' ], array_column( $plan['upserts'], 'slug' ) );
	}

	public function test_refresh_rejects_duplicate_site_or_product_identities(): void {
		$inventory = PackInventory::scan( $this->root );
		$this->assertIsArray( $inventory );
		$identities = [ 'example' => 'known-example-id', 'playbooks/hero' => 'known-hero-id' ];
		$duplicate = [ [ 'id' => 42, 'slug' => 'known-example-id', 'source' => 'builtin' ], [ 'id' => 43, 'slug' => 'known-example-id', 'source' => 'user' ] ];
		$this->assertInstanceOf( \WP_Error::class, PackRefresh::plan( $inventory, $duplicate, $identities ) );
		$this->assertInstanceOf( \WP_Error::class, PackRefresh::plan( $inventory, [], [ 'example' => 'same-id', 'playbooks/hero' => 'same-id' ] ) );
	}
}
