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
					if ( 'playbooks' === basename( $dir ) ) {
						continue;
					}
					$skill_file = $dir . '/SKILL.md';
					if ( is_file( $skill_file ) ) {
						self::seed_from_file( basename( $dir ), $skill_file );
					}
				}
			}
		}

		self::seed_playbooks();
		self::seed_meta_skill();
	}

	/**
	 * Seed prompt playbooks from skills/playbooks/*.md with source=playbook.
	 */
	private static function seed_playbooks(): void {
		$playbooks_dir = self::$skills_dir . '/playbooks';
		if ( ! is_dir( $playbooks_dir ) ) {
			return;
		}

		$files = glob( $playbooks_dir . '/*.md' );
		if ( ! is_array( $files ) ) {
			return;
		}

		foreach ( $files as $file_path ) {
			self::seed_playbook_from_file( $file_path );
		}
	}

	private static function seed_playbook_from_file( string $file_path ): void {
		$content = file_get_contents( $file_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( false === $content ) {
			return;
		}

		$base           = basename( $file_path, '.md' );
		$title          = self::slug_to_title( $base );
		$description    = '';
		$enable_agentic = true;
		$enable_prompt  = true;

		if ( str_starts_with( ltrim( $content ), '---' ) ) {
			$end = strpos( $content, '---', 3 );
			if ( false !== $end ) {
				$front_matter = substr( $content, 3, $end - 3 );
				$content      = ltrim( substr( $content, $end + 3 ) );

				$title          = self::front_matter_string( $front_matter, 'name', $title );
				$description    = self::front_matter_string( $front_matter, 'description', $description );
				$enable_agentic = self::front_matter_bool( $front_matter, 'enable_agentic', $enable_agentic );
				$enable_prompt  = self::front_matter_bool( $front_matter, 'enable_prompt', $enable_prompt );
			}
		}

		Skills::save( [
			'slug'           => 'playbook-' . sanitize_title( $base ),
			'title'          => $title,
			'description'    => $description,
			'content'        => trim( $content ),
			'enabled'        => 1,
			'enable_agentic' => $enable_agentic,
			'enable_prompt'  => $enable_prompt,
			'source'         => 'playbook',
		] );
	}

	private static function seed_from_file( string $dir_name, string $file_path ): void {
		$content = file_get_contents( $file_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( false === $content ) {
			return;
		}

		$title          = self::slug_to_title( $dir_name );
		$description    = '';
		$enable_agentic = true;
		$enable_prompt  = true;

		if ( str_starts_with( ltrim( $content ), '---' ) ) {
			$end = strpos( $content, '---', 3 );
			if ( false !== $end ) {
				$front_matter = substr( $content, 3, $end - 3 );
				$content      = ltrim( substr( $content, $end + 3 ) );

				$title          = self::front_matter_string( $front_matter, 'name', $title );
				$description    = self::front_matter_string( $front_matter, 'description', $description );
				$enable_agentic = self::front_matter_bool( $front_matter, 'enable_agentic', $enable_agentic );
				$enable_prompt  = self::front_matter_bool( $front_matter, 'enable_prompt', $enable_prompt );
			}
		}

		Skills::save( [
			'slug'           => 'stonewright-' . $dir_name,
			'title'          => $title,
			'description'    => $description,
			'content'        => trim( $content ),
			'enabled'        => 1,
			'enable_agentic' => $enable_agentic,
			'enable_prompt'  => $enable_prompt,
			'source'         => 'builtin',
		] );
	}

	private static function seed_meta_skill(): void {
		Skills::save( [
			'slug'           => 'stonewright-how-to-write-skills',
			'title'          => 'How to write Stonewright skills',
			'description'    => 'Teaches agents how to create new site-specific skills and playbooks.',
			'source'         => 'builtin',
			'enabled'        => 1,
			'enable_agentic' => 1,
			'enable_prompt'  => 1,
			'content'        => <<<'MD'
# How to Write Stonewright Skills

A skill is a Markdown playbook stored in the Stonewright admin panel. It can be auto-matched from a task description, exposed as an explicit prompt/command, or both.

## Structure

A skill should have:
- A clear, one-line **description** (shown in the admin card and used for auto-match)
- A **title** (short, human-readable)
- **Content**: step-by-step instructions, rules, and examples
- Exposure flags:
  - `enable_agentic`: use for concise rules that should auto-match
  - `enable_prompt`: use for playbooks users may open explicitly

## Creating a New Skill via MCP

Use the ability `stonewright/skills-save`:
```json
{
  "slug": "my-skill-slug",
  "title": "My Skill Title",
  "description": "One-line description of when to use this skill",
  "content": "# My Skill\n\nStep 1: ...\nStep 2: ...",
  "enable_agentic": true,
  "enable_prompt": true
}
```

Or visit **WordPress Admin -> Stonewright -> Skills** to create one manually.

## Tips

- Keep skills focused on one workflow or domain
- Include examples of correct ability calls (e.g. `stonewright/design-build-spec`)
- Reference exact ability names the LLM should call
- Put large, rarely needed playbooks in prompt mode instead of auto-match mode
- Use headings and code blocks for clarity
- Disable skills that don't apply to the current project to keep instructions lean
MD,
		] );
	}

	private static function front_matter_string( string $front_matter, string $key, string $default ): string {
		$value = self::front_matter_value( $front_matter, $key );
		if ( null === $value ) {
			return $default;
		}

		return trim( $value, " \t\n\r\0\x0B\"'" );
	}

	private static function front_matter_bool( string $front_matter, string $key, bool $default ): bool {
		$value = self::front_matter_value( $front_matter, $key );
		if ( null === $value ) {
			return $default;
		}

		return in_array( strtolower( trim( $value, " \t\n\r\0\x0B\"'" ) ), [ '1', 'true', 'yes', 'on' ], true );
	}

	private static function front_matter_value( string $front_matter, string $key ): ?string {
		$lines = preg_split( '/\R/', $front_matter );
		if ( ! is_array( $lines ) ) {
			return null;
		}

		$pattern = '/^' . preg_quote( $key, '/' ) . ':\s*(.*)$/';
		foreach ( $lines as $index => $line ) {
			if ( ! preg_match( $pattern, trim( $line ), $match ) ) {
				continue;
			}

			$value = trim( (string) ( $match[1] ?? '' ) );
			if ( ! in_array( $value, [ '>', '|' ], true ) ) {
				return preg_replace( '/\s+/', ' ', $value ) ?? $value;
			}

			$folded = [];
			for ( $i = $index + 1, $total = count( $lines ); $i < $total; ++$i ) {
				$next = (string) $lines[ $i ];
				if ( '' !== trim( $next ) && preg_match( '/^[A-Za-z0-9_-]+:\s*/', $next ) ) {
					break;
				}
				$folded[] = trim( $next );
			}

			$result = trim( implode( ' ', array_filter(
				$folded,
				static fn( string $line ): bool => '' !== $line
			) ) );
			return preg_replace( '/\s+/', ' ', $result ) ?? $result;
		}

		return null;
	}

	private static function slug_to_title( string $slug ): string {
		return ucwords( str_replace( [ '-', '_' ], ' ', $slug ) );
	}
}
