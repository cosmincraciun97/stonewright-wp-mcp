<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Abilities\Skills;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\Skills\SkillsList;

/**
 * @covers \Stonewright\WpMcp\Abilities\Skills\SkillsList
 */
final class SkillsListTest extends TestCase {

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

	public function test_list_omits_content_by_default_to_reduce_token_usage(): void {
		$GLOBALS['wpdb'] = $this->wpdb_with_rows();

		$result = ( new SkillsList() )->execute( [] );

		self::assertIsArray( $result );
		self::assertArrayNotHasKey( 'content', $result['skills'][0] );
		self::assertSame( 'token-skill', $result['skills'][0]['slug'] );
	}

	public function test_list_can_include_content_when_explicitly_requested(): void {
		$GLOBALS['wpdb'] = $this->wpdb_with_rows();

		$result = ( new SkillsList() )->execute( [ 'include_content' => true ] );

		self::assertIsArray( $result );
		self::assertSame( 'Long playbook body', $result['skills'][0]['content'] );
	}

	public function test_list_filters_agentic_mode(): void {
		$GLOBALS['wpdb'] = $this->wpdb_with_rows();

		$result = ( new SkillsList() )->execute( [ 'mode' => 'agentic' ] );

		self::assertIsArray( $result );
		self::assertSame( 'agentic', $result['mode'] );
		self::assertCount( 1, $result['skills'] );
		self::assertSame( 'token-skill', $result['skills'][0]['slug'] );
		self::assertArrayNotHasKey( 'content', $result['skills'][0] );
	}

	public function test_list_filters_prompt_mode(): void {
		$GLOBALS['wpdb'] = $this->wpdb_with_rows();

		$result = ( new SkillsList() )->execute( [ 'mode' => 'prompt' ] );

		self::assertIsArray( $result );
		self::assertSame( 'prompt', $result['mode'] );
		self::assertCount( 1, $result['skills'] );
		self::assertSame( 'prompt-only-skill', $result['skills'][0]['slug'] );
	}

	private function wpdb_with_rows(): object {
		return new class() {
			public string $prefix = 'wp_';

			public function get_var( string $query ): string {
				return 'wp_stonewright_skills';
			}

			public function prepare( string $query, mixed ...$args ): string {
				return $query;
			}

			/** @return array<int, array<string, string>> */
			public function get_results( string $query, string $output = 'OBJECT' ): array {
				return [
					[
						'id'             => '1',
						'slug'           => 'token-skill',
						'title'          => 'Token Skill',
						'description'    => 'Use this for token tests.',
						'content'        => 'Long playbook body',
						'enabled'        => '1',
						'enable_agentic' => '1',
						'enable_prompt'  => '0',
						'source'         => 'user',
					],
					[
						'id'             => '2',
						'slug'           => 'prompt-only-skill',
						'title'          => 'Prompt Only Skill',
						'description'    => 'Manual prompt playbook.',
						'content'        => 'Prompt body',
						'enabled'        => '1',
						'enable_agentic' => '0',
						'enable_prompt'  => '1',
						'source'         => 'user',
					],
				];
			}
		};
	}
}
