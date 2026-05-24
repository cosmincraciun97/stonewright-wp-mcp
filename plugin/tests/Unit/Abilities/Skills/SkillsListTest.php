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
						'id'          => '1',
						'slug'        => 'token-skill',
						'title'       => 'Token Skill',
						'description' => 'Use this for token tests.',
						'content'     => 'Long playbook body',
						'enabled'     => '1',
						'source'      => 'user',
					],
				];
			}
		};
	}
}
