<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Abilities\Skills;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\Skills\SkillsSave;

/**
 * @covers \Stonewright\WpMcp\Abilities\Skills\SkillsSave
 */
final class SkillsSaveTest extends TestCase {

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

	public function test_save_persists_skill_exposure_flags(): void {
		$GLOBALS['wpdb'] = new class() {
			public string $prefix = 'wp_';
			public int $insert_id = 40;

			/** @var array<string, mixed> */
			public array $inserted = [];

			public function get_var( string $query ): string {
				return 'wp_stonewright_skills';
			}

			public function prepare( string $query, mixed ...$args ): string {
				return $query;
			}

			public function get_row( string $query, string $output = 'OBJECT' ): ?array {
				return null;
			}

			/** @param array<string, mixed> $data */
			public function insert( string $table, array $data, array $format = [] ): int {
				$this->inserted = $data;
				$this->insert_id++;
				return 1;
			}
		};

		$result = ( new SkillsSave() )->execute( [
			'slug'           => 'manual-playbook',
			'title'          => 'Manual Playbook',
			'description'    => 'Use only on request.',
			'content'        => '# Manual',
			'enabled'        => true,
			'enable_agentic' => false,
			'enable_prompt'  => true,
		] );

		self::assertIsArray( $result );
		self::assertSame( 'manual-playbook', $result['slug'] );
		self::assertSame( 1, $GLOBALS['wpdb']->inserted['enabled'] );
		self::assertSame( 0, $GLOBALS['wpdb']->inserted['enable_agentic'] );
		self::assertSame( 1, $GLOBALS['wpdb']->inserted['enable_prompt'] );
	}
}
