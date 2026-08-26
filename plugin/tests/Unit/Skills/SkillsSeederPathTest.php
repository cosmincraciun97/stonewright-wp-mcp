<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Skills;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Skills\SkillsSeeder;

/**
 * @covers \Stonewright\WpMcp\Skills\SkillsSeeder
 */
final class SkillsSeederPathTest extends TestCase {

	public function test_candidates_prefer_the_bundled_plugin_copy(): void {
		$candidates = SkillsSeeder::candidate_skills_dirs();

		self::assertCount( 2, $candidates );
		// Bundled location: <plugin dir>/skills (what the release ZIP ships).
		self::assertStringEndsWith( '/plugin/skills', str_replace( '\\', '/', $candidates[0] ) );
		// Dev fallback: <repo root>/skills (what a git checkout has).
		self::assertStringEndsWith( '/skills', str_replace( '\\', '/', $candidates[1] ) );
		self::assertNotSame( $candidates[0], $candidates[1] );
	}

	public function test_resolve_skills_dir_picks_the_first_existing_candidate(): void {
		$missing = sys_get_temp_dir() . '/stonewright-not-a-dir-' . uniqid();
		$real    = sys_get_temp_dir() . '/stonewright-skills-' . uniqid();
		mkdir( $real );

		try {
			self::assertSame( $real, SkillsSeeder::resolve_skills_dir( [ $missing, $real ] ) );
			self::assertSame( $real, SkillsSeeder::resolve_skills_dir( [ $real, $missing ] ) );
			self::assertSame( '', SkillsSeeder::resolve_skills_dir( [ $missing ] ) );
		} finally {
			rmdir( $real );
		}
	}
}
