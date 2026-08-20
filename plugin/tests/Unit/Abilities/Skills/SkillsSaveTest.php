<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Abilities\Skills;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\Skills\SkillsSave;
use Stonewright\WpMcp\Security\ConfirmationToken;

/**
 * @covers \Stonewright\WpMcp\Abilities\Skills\SkillsSave
 */
final class SkillsSaveTest extends TestCase {

	private mixed $original_wpdb;

	protected function setUp(): void {
		$this->original_wpdb = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['stonewright_test_options']         = [];
		$GLOBALS['stonewright_test_current_user_id'] = 1;
		$GLOBALS['stonewright_test_transients']      = [];
	}

	protected function tearDown(): void {
		if ( null !== $this->original_wpdb ) {
			$GLOBALS['wpdb'] = $this->original_wpdb;
		} else {
			unset( $GLOBALS['wpdb'] );
		}
		$GLOBALS['stonewright_test_options']         = [];
		$GLOBALS['stonewright_test_current_user_id'] = 0;
		$GLOBALS['stonewright_test_transients']      = [];
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
				if ( str_contains( $table, 'stonewright_skills' ) ) {
					$this->inserted = $data;
				}
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

	public function test_production_safe_mode_requires_confirmation_token(): void {
		$GLOBALS['stonewright_test_options']         = [ 'stonewright_mode' => 'production-safe' ];
		$GLOBALS['stonewright_test_current_user_id'] = 1;
		$GLOBALS['stonewright_test_transients']      = [];

		$args = [
			'slug'    => 'manual-playbook',
			'title'   => 'Manual Playbook',
			'content' => '# Manual',
		];

		$blocked = ( new SkillsSave() )->execute( $args );
		self::assertInstanceOf( \WP_Error::class, $blocked );
		self::assertSame( 'stonewright_confirmation_required', $blocked->get_error_code() );

		$GLOBALS['wpdb'] = new class() {
			public string $prefix = 'wp_';
			public int $insert_id = 40;

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
				$this->insert_id++;
				return 1;
			}
		};

		$args['confirmation_token'] = ConfirmationToken::issue( 'stonewright/skills-save', $args );
		$result = ( new SkillsSave() )->execute( $args );
		self::assertIsArray( $result );
		self::assertSame( 'manual-playbook', $result['slug'] );

		$GLOBALS['stonewright_test_options']['stonewright_mode'] = 'development';
		$dev = ( new SkillsSave() )->execute(
			[
				'slug'    => 'dev-playbook',
				'title'   => 'Dev Playbook',
				'content' => '# Dev',
			]
		);
		self::assertIsArray( $dev );
	}
}
