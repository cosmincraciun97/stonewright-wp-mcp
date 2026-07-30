<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\Ability;
use Stonewright\WpMcp\Core\AbilityRegistry;
use Stonewright\WpMcp\DesignSpec\Validator;
use Stonewright\WpMcp\Security\Backup;
use Stonewright\WpMcp\Security\ConfirmationToken;

/**
 * Registry smoke test — boots the registry and exercises every ability without fatals.
 *
 * Also asserts all 7 AGENTS.md hard rules hold at a structural level.
 *
 * @covers \Stonewright\WpMcp\Core\AbilityRegistry
 */
final class SmokeTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_user_caps'] = array_fill_keys(
			[
				'read',
				'edit_posts',
				'edit_pages',
				'manage_options',
				'edit_plugins',
				'edit_themes',
				'upload_files',
				'edit_theme_options',
				'publish_posts',
				'publish_pages',
			],
			true
		);
		$GLOBALS['stonewright_test_user_logged_in']  = true;
		$GLOBALS['stonewright_test_current_user_id'] = 1;
		$GLOBALS['stonewright_test_options']         = [
			'stonewright_elementor_v4_atomic' => true,
			'stonewright_memory_enabled'      => true,
			'elementor_active_kit'            => 4,
		];
		$GLOBALS['stonewright_test_transients']      = [];
		$GLOBALS['stonewright_test_wpdb_inserts']    = [];
		$GLOBALS['stonewright_test_next_post_id']    = 2001;
		$GLOBALS['stonewright_test_posts']           = [
			1 => (object) [
				'ID'           => 1,
				'post_type'    => 'page',
				'post_status'  => 'draft',
				'post_title'   => 'Smoke Page',
				'post_content' => '<!-- wp:paragraph --><p>Smoke</p><!-- /wp:paragraph -->',
				'post_excerpt' => '',
				'post_parent'  => 0,
				'post_name'    => 'smoke-page',
				'meta'         => [
					'_elementor_data'      => '[{"id":"smoke1","elType":"container","settings":[],"elements":[]}]',
					'_elementor_edit_mode' => 'builder',
				],
			],
			4 => (object) [
				'ID'           => 4,
				'post_type'    => 'elementor_library',
				'post_status'  => 'publish',
				'post_title'   => 'Smoke Kit',
				'post_content' => '',
				'post_excerpt' => '',
				'post_parent'  => 0,
				'post_name'    => 'smoke-kit',
				'meta'         => [
					'_elementor_page_settings' => [
						'e_atomic_classes'   => [],
						'e_atomic_variables' => [],
					],
				],
			],
		];
		$GLOBALS['stonewright_test_post_meta_calls'] = [];

		$sandbox_dir = WP_CONTENT_DIR . '/stonewright-sandbox';
		$mu_dir      = WP_CONTENT_DIR . '/mu-plugins';
		wp_mkdir_p( $sandbox_dir );
		wp_mkdir_p( $mu_dir );
		file_put_contents( $sandbox_dir . '/smoke.php', "<?php\n// smoke fixture.\n" );
		file_put_contents( $mu_dir . '/stonewright-sandbox-smoke.php', "<?php\n// smoke active.\n" );

		$widget_pending = $sandbox_dir . '/widget-smoke-reg.pending.php';
		if ( ! file_exists( $widget_pending ) ) {
			file_put_contents( // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
				$widget_pending,
				"<?php\ndeclare( strict_types=1 );\nif ( ! defined( 'ABSPATH' ) ) { exit; }\n// Stonewright smoke test widget stub.\n"
			);
		}
	}

	// -------------------------------------------------------------------------
	// Registry enumeration.
	// -------------------------------------------------------------------------

	public function test_registry_list_returns_108_or_more_abilities(): void {
		$list = AbilityRegistry::list();
		$this->assertGreaterThanOrEqual(
			108,
			count( $list ),
			'AbilityRegistry::list() must return at least 108 abilities. Got: ' . count( $list )
		);
	}

	public function test_all_registered_classes_are_loadable(): void {
		foreach ( AbilityRegistry::list() as $class ) {
			$this->assertTrue(
				class_exists( $class ),
				"Class {$class} from AbilityRegistry::list() must be autoloadable."
			);
		}
	}

	// -------------------------------------------------------------------------
	// Per-ability interface smoke.
	// -------------------------------------------------------------------------

	/**
	 * @return array<string, array{class-string<Ability>}>
	 */
	public static function ability_class_provider(): array {
		$out = [];
		foreach ( AbilityRegistry::list() as $class ) {
			$out[ $class ] = [ $class ];
		}
		return $out;
	}

	/**
	 * @dataProvider ability_class_provider
	 * @param class-string<Ability> $class
	 */
	public function test_ability_name_is_stonewright_prefixed( string $class ): void {
		$ability = new $class();
		$this->assertStringStartsWith(
			'stonewright/',
			$ability->name(),
			"{$class}::name() must start with 'stonewright/'."
		);
	}

	/**
	 * @dataProvider ability_class_provider
	 * @param class-string<Ability> $class
	 */
	public function test_ability_description_is_non_empty_string( string $class ): void {
		$ability = new $class();
		$this->assertNotEmpty(
			$ability->description(),
			"{$class}::description() must not be empty."
		);
	}

	/**
	 * @dataProvider ability_class_provider
	 * @param class-string<Ability> $class
	 */
	public function test_ability_input_schema_is_array_with_type( string $class ): void {
		$ability = new $class();
		$schema  = $ability->input_schema();
		$this->assertIsArray( $schema, "{$class}::input_schema() must return an array." );
		$this->assertArrayHasKey( 'type', $schema, "{$class}::input_schema() must declare a 'type' key." );
	}

	/**
	 * @dataProvider ability_class_provider
	 * @param class-string<Ability> $class
	 */
	public function test_ability_output_schema_is_array_with_type( string $class ): void {
		$ability = new $class();
		$schema  = $ability->output_schema();
		$this->assertIsArray( $schema, "{$class}::output_schema() must return an array." );
		$this->assertArrayHasKey( 'type', $schema, "{$class}::output_schema() must declare a 'type' key." );
	}

	/**
	 * @dataProvider ability_class_provider
	 * @param class-string<Ability> $class
	 */
	public function test_ability_permission_callback_returns_bool_or_wp_error( string $class ): void {
		$ability = new $class();
		$result  = $ability->permission_callback( [] );
		$this->assertTrue(
			is_bool( $result ) || $result instanceof \WP_Error,
			"{$class}::permission_callback([]) must return bool or WP_Error. Got: " . gettype( $result )
		);
	}

	// -------------------------------------------------------------------------
	// AGENTS.md hard rule assertions (one per rule).
	// -------------------------------------------------------------------------

	/**
	 * Hard rule 1: PHP execution is limited to the runtime executor.
	 * No source file in plugin/includes/ may contain a bare eval() call except
	 * the dedicated php-execute ability and detection-only files.
	 * Files that reference the token as a string literal for detection are allowlisted.
	 */
	public function test_hard_rule_1_no_eval_in_source_tree(): void {
		$includes_dir = dirname( __DIR__, 2 ) . '/includes';
		// These files reference the token as a string literal in token-detection code.
		$allowlist = [ 'StaticGuard.php', 'Compiler.php', 'DesignSpec.php', 'PhpExecute.php' ];
		$found     = $this->scan_lines(
			$includes_dir,
			'/\.php$/',
			// Match the PHP eval keyword call, not a string containing it.
			static function ( string $line ): bool {
				// Exclude lines that are entirely inside a string / comment context
				// by checking that the match is a PHP keyword invocation, not quoted.
				return (bool) preg_match( '/(?<![\'"])\\beval\s*\(/i', $line );
			},
			$allowlist
		);
		$this->assertSame(
			[],
			$found,
			"Hard rule 1 violated: eval() call found outside the dedicated runtime executor or detection-only files.\n" . implode( "\n", $found )
		);
	}

	/**
	 * Hard rule 2: No __return_true for writes.
	 * __return_true must not appear in any includes/ file.
	 */
	public function test_hard_rule_2_no_return_true_permission_callback(): void {
		$includes_dir = dirname( __DIR__, 2 ) . '/includes';
		$found        = $this->scan_lines(
			$includes_dir,
			'/\.php$/',
			static function ( string $line ): bool {
				return str_contains( $line, '__return_true' );
			},
			[]
		);
		$this->assertSame(
			[],
			$found,
			"Hard rule 2 violated: __return_true appears in includes/.\n" . implode( "\n", $found )
		);
	}

	/**
	 * Hard rule 3: Backup class exists with snapshot_post() method.
	 */
	public function test_hard_rule_3_backup_snapshot_post_exists(): void {
		$this->assertTrue(
			class_exists( Backup::class ),
			'Hard rule 3: Stonewright\WpMcp\Security\Backup class must exist.'
		);
		$this->assertTrue(
			method_exists( Backup::class, 'snapshot_post' ),
			'Hard rule 3: Backup::snapshot_post() must exist.'
		);
	}

	/**
	 * Hard rule 4: Validator class exists with validate() method.
	 */
	public function test_hard_rule_4_validator_exists(): void {
		$this->assertTrue(
			class_exists( Validator::class ),
			'Hard rule 4: Stonewright\WpMcp\DesignSpec\Validator must exist.'
		);
		$this->assertTrue(
			method_exists( Validator::class, 'validate' ),
			'Hard rule 4: Validator::validate() must exist.'
		);
	}

	/**
	 * Hard rule 5: ConfirmationToken class exists with issue() and verify().
	 */
	public function test_hard_rule_5_confirmation_token_exists(): void {
		$this->assertTrue(
			class_exists( ConfirmationToken::class ),
			'Hard rule 5: Stonewright\WpMcp\Security\ConfirmationToken must exist.'
		);
		$this->assertTrue(
			method_exists( ConfirmationToken::class, 'issue' ),
			'Hard rule 5: ConfirmationToken::issue() must exist.'
		);
		$this->assertTrue(
			method_exists( ConfirmationToken::class, 'verify' ),
			'Hard rule 5: ConfirmationToken::verify() must exist.'
		);
	}

	/**
	 * Hard rule 6: Companion never writes to WordPress.
	 * companion/src must not contain direct wp-json REST write method calls.
	 */
	public function test_hard_rule_6_companion_no_wp_rest_writes(): void {
		$companion_src = dirname( __DIR__, 3 ) . '/companion/src';
		if ( ! is_dir( $companion_src ) ) {
			$this->markTestSkipped( 'companion/src not found; skipping companion write check.' );
		}

		$violators = [];
		foreach ( $this->collect_files( $companion_src, '/\.ts$/' ) as $file ) {
			$content = (string) file_get_contents( $file );
			// A file that references both wp-json and an HTTP write method is a concern.
			if (
				preg_match( '/wp-json/i', $content ) &&
				preg_match( '/method\s*:\s*["\'](?:POST|PUT|PATCH|DELETE)["\']/', $content )
			) {
				$violators[] = $file;
			}
		}

		$this->assertSame(
			[],
			$violators,
			"Hard rule 6: companion TypeScript files appear to make WordPress REST write calls.\n" . implode( "\n", $violators )
		);
	}

	/**
	 * Hard rule 7: All abilities use the stonewright/ prefix.
	 */
	public function test_hard_rule_7_ability_prefix_consistency(): void {
		$violations = [];
		foreach ( AbilityRegistry::list() as $class ) {
			$ability = new $class();
			if ( ! str_starts_with( $ability->name(), 'stonewright/' ) ) {
				$violations[] = $ability->name();
			}
		}
		$this->assertSame(
			[],
			$violations,
			'Hard rule 7: abilities without stonewright/ prefix: ' . implode( ', ', $violations )
		);
	}

	// -------------------------------------------------------------------------
	// Helpers.
	// -------------------------------------------------------------------------

	/**
	 * Scan files under $dir for lines matching a predicate, skipping allowlisted
	 * basenames.
	 *
	 * @param callable(string):bool $matcher
	 * @param array<int, string>    $skip_basenames
	 * @return array<int, string>
	 */
	private function scan_lines( string $dir, string $ext_pattern, callable $matcher, array $skip_basenames ): array {
		$found = [];
		foreach ( $this->collect_files( $dir, $ext_pattern ) as $file ) {
			if ( in_array( basename( $file ), $skip_basenames, true ) ) {
				continue;
			}
			$lines = file( $file, FILE_IGNORE_NEW_LINES );
			if ( false === $lines ) {
				continue;
			}
			foreach ( $lines as $no => $line ) {
				if ( $matcher( $line ) ) {
					$found[] = $file . ':' . ( $no + 1 ) . ': ' . trim( $line );
				}
			}
		}
		return $found;
	}

	/**
	 * Recursively collect files matching $ext_pattern under $dir.
	 *
	 * @return array<int, string>
	 */
	private function collect_files( string $dir, string $ext_pattern ): array {
		$files    = [];
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS )
		);
		foreach ( $iterator as $file ) {
			if ( $file instanceof \SplFileInfo && preg_match( $ext_pattern, $file->getFilename() ) ) {
				$files[] = $file->getPathname();
			}
		}
		return $files;
	}
}
