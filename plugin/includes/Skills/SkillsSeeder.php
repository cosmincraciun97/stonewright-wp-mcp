<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Skills;

/**
 * Seeds built-in skills from the /skills directory and adds the meta-skill.
 * All seeds are idempotent (upsert by slug).
 *
 * @stonewright-status stable
 */
final class SkillsSeeder {

	/** @var string Absolute path to the external skills directory */
	private static string $skills_dir = '';

	public static function seed(): void {
		self::$skills_dir = rtrim( dirname( __DIR__, 3 ), '/\\' ) . '/skills';

		// Seed each subdirectory that contains a SKILL.md.
		if ( is_dir( self::$skills_dir ) ) {
			$dirs = glob( self::$skills_dir . '/*', GLOB_ONLYDIR );
			if ( is_array( $dirs ) ) {
				foreach ( $dirs as $dir ) {
					$skill_file = $dir . '/SKILL.md';
					if ( is_file( $skill_file ) ) {
						self::seed_from_file( basename( $dir ), $skill_file );
					}
				}
			}
		}

		// Seed the meta-skill that teaches the LLM how to write new skills.
		self::seed_meta_skill();
	}

	private static function seed_from_file( string $dir_name, string $file_path ): void {
		$content = file_get_contents( $file_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( false === $content ) {
			return;
		}

		// Parse YAML front matter for name/description if present.
		$title       = self::slug_to_title( $dir_name );
		$description = '';

		if ( str_starts_with( ltrim( $content ), '---' ) ) {
			$end = strpos( $content, '---', 3 );
			if ( false !== $end ) {
				$front_matter = substr( $content, 3, $end - 3 );
				$content      = ltrim( substr( $content, $end + 3 ) );

				if ( preg_match( '/^name:\s*(.+)$/m', $front_matter, $m ) ) {
					$title = trim( $m[1] );
				}
				if ( preg_match( '/^description:\s*>?\s*(.+)/s', $front_matter, $m ) ) {
					$description = trim( preg_replace( '/\s+/', ' ', $m[1] ) ?? '' );
				}
			}
		}

		Skills::save( [
			'slug'        => 'stonewright-' . $dir_name,
			'title'       => $title,
			'description' => $description,
			'content'     => trim( $content ),
			'enabled'     => 1,
			'source'      => 'builtin',
		] );
	}

	private static function seed_meta_skill(): void {
		Skills::save( [
			'slug'        => 'stonewright-how-to-write-skills',
			'title'       => 'How to write Stonewright skills',
			'description' => 'Teaches the AI how to create new site-specific skills and playbooks.',
			'source'      => 'builtin',
			'enabled'     => 1,
			'content'     => <<<'MD'
# How to Write Stonewright Skills

A skill is a Markdown playbook stored in the Stonewright admin panel that the AI follows automatically when the current task matches its description.

## Structure

A skill should have:
- A clear, one-line **description** (shown in the admin card and used to match tasks)
- A **title** (short, human-readable)
- **Content**: step-by-step instructions, rules, and examples

## Creating a New Skill via MCP

Use the ability `stonewright/skills-save`:
```json
{
  "slug": "my-skill-slug",
  "title": "My Skill Title",
  "description": "One-line description of when to use this skill",
  "content": "# My Skill\n\nStep 1: ...\nStep 2: ..."
}
```

Or visit **WordPress Admin → Stonewright → Skills** to create one manually.

## Tips

- Keep skills focused on one workflow or domain
- Include examples of correct ability calls (e.g. `stonewright/design-build-spec`)
- Reference exact ability names the LLM should call
- Use headings and code blocks for clarity
- Disable skills that don't apply to the current project to keep instructions lean
MD,
		] );
	}

	private static function slug_to_title( string $slug ): string {
		return ucwords( str_replace( [ '-', '_' ], ' ', $slug ) );
	}
}
