<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\Runtime\PhpExecute;
use Stonewright\WpMcp\Abilities\Sandbox\SandboxWrite;
use Stonewright\WpMcp\Abilities\Security\IssueConfirmationToken;
use Stonewright\WpMcp\Abilities\Themes\ThemeCustomCss;
use Stonewright\WpMcp\Abilities\Themes\ThemeFilePatch;
use Stonewright\WpMcp\Core\AbilityRegistry;
use Stonewright\WpMcp\Sandbox\SandboxFiles;
use Stonewright\WpMcp\Security\ConfirmationToken;

/**
 * Behavioral coverage for canonical payload bytes at every code-write boundary.
 *
 * @covers \Stonewright\WpMcp\Abilities\Runtime\PhpExecute
 * @covers \Stonewright\WpMcp\Abilities\Sandbox\SandboxWrite
 * @covers \Stonewright\WpMcp\Abilities\Security\IssueConfirmationToken
 * @covers \Stonewright\WpMcp\Abilities\Themes\ThemeCustomCss
 * @covers \Stonewright\WpMcp\Abilities\Themes\ThemeFilePatch
 */
final class CodeFormatterWiringTest extends TestCase {

	private string $theme_dir;

	protected function setUp(): void {
		$GLOBALS['stonewright_test_user_caps'] = [
			'read'               => true,
			'manage_options'     => true,
			'edit_plugins'       => true,
			'edit_theme_options' => true,
			'edit_css'           => true,
		];
		$GLOBALS['stonewright_test_user_logged_in']      = true;
		$GLOBALS['stonewright_test_current_user_id']     = 91;
		$GLOBALS['stonewright_test_options']             = [
			'stonewright_mode'               => 'development',
			'stonewright_disabled_abilities' => [],
		];
		$GLOBALS['stonewright_test_transients']          = [];
		$GLOBALS['stonewright_test_wpdb_inserts']        = [];
		$GLOBALS['stonewright_test_custom_css']          = '';

		$this->theme_dir = sys_get_temp_dir() . '/sw-code-wiring-' . bin2hex( random_bytes( 4 ) );
		mkdir( $this->theme_dir );
		file_put_contents( $this->theme_dir . '/style.css', "body { color: #111; }\n" );
		file_put_contents( $this->theme_dir . '/scripts.js', "const before = true;\n" );
		file_put_contents( $this->theme_dir . '/functions.php', "<?php\nfunction sw_before() { return true; }\n" );
		$GLOBALS['stonewright_test_stylesheet_directory'] = $this->theme_dir;
		$GLOBALS['stonewright_test_stylesheet']           = 'sw-code-wiring';
	}

	protected function tearDown(): void {
		$this->remove_sandbox_fixture( 'task5-canonical.php' );
		$this->remove_sandbox_fixture( 'task5-flag-mismatch.php' );
		$this->rm_tree( $this->theme_dir );

		$GLOBALS['stonewright_test_user_caps']          = [];
		$GLOBALS['stonewright_test_user_logged_in']     = false;
		$GLOBALS['stonewright_test_current_user_id']    = 0;
		$GLOBALS['stonewright_test_options']            = [];
		$GLOBALS['stonewright_test_transients']         = [];
		$GLOBALS['stonewright_test_wpdb_inserts']       = [];
		$GLOBALS['stonewright_test_custom_css']         = '';
		unset( $GLOBALS['stonewright_test_stylesheet_directory'], $GLOBALS['stonewright_test_stylesheet'] );
	}

	public function test_only_code_payload_targets_expose_optional_decode_flag(): void {
		$exposed = [];
		foreach ( AbilityRegistry::list() as $class ) {
			$ability = new $class();
			$schema  = $ability->input_schema();
			$properties = $schema['properties'] ?? [];
			$properties = is_object( $properties ) ? get_object_vars( $properties ) : $properties;
			if ( is_array( $properties ) && isset( $properties['decode_escaped_layout'] ) ) {
				$exposed[] = $ability->name();
				self::assertSame( 'boolean', $properties['decode_escaped_layout']['type'] );
				self::assertFalse( $properties['decode_escaped_layout']['default'] );
				self::assertNotContains( 'decode_escaped_layout', $schema['required'] ?? [] );
			}
		}

		sort( $exposed );
		self::assertSame(
			[
				'stonewright/php-execute',
				'stonewright/sandbox-write',
				'stonewright/theme-custom-css',
				'stonewright/theme-file-patch',
			],
			$exposed
		);
	}

	/**
	 * @dataProvider canonical_confirmation_args
	 *
	 * @param array<string, mixed> $raw_args
	 * @param array<string, mixed> $canonical_args
	 */
	public function test_confirmation_issuer_binds_token_to_canonical_target_args(
		string $ability,
		array $raw_args,
		array $canonical_args
	): void {
		$issued = ( new IssueConfirmationToken() )->execute(
			[
				'ability' => $ability,
				'args'    => $raw_args,
			]
		);

		self::assertIsArray( $issued );
		self::assertTrue(
			ConfirmationToken::verify(
				(string) $issued['token'],
				$ability,
				$canonical_args
			)
		);
	}

	/**
	 * @return array<string, array{string, array<string, mixed>, array<string, mixed>}>
	 */
	public static function canonical_confirmation_args(): array {
		return [
			'php execute strips tag and decodes body' => [
				'stonewright/php-execute',
				[
					'code'                  => '<?php\nreturn 42;',
					'decode_escaped_layout' => true,
				],
				[
					'code'                  => 'return 42;',
					'decode_escaped_layout' => true,
				],
			],
			'theme patch derives css from normalized path' => [
				'stonewright/theme-file-patch',
				[
					'path'                  => '/style.css',
					'mode'                  => 'replace_all',
					'content'               => '.x { color: red; }\n.y { color: blue; }',
					'decode_escaped_layout' => true,
				],
				[
					'path'                  => 'style.css',
					'mode'                  => 'replace_all',
					'content'               => ".x { color: red; }\n.y { color: blue; }\n",
					'decode_escaped_layout' => true,
				],
			],
			'sandbox write keeps complete php file' => [
				'stonewright/sandbox-write',
				[
					'name'                  => 'task5-canonical.php',
					'contents'              => '<?php\nreturn 7;',
					'decode_escaped_layout' => true,
				],
				[
					'name'                  => 'task5-canonical.php',
					'contents'              => "<?php\nreturn 7;\n",
					'decode_escaped_layout' => true,
				],
			],
			'theme custom css decodes css' => [
				'stonewright/theme-custom-css',
				[
					'action'                => 'update',
					'css'                   => '.x { color: red; }\n.y { color: blue; }',
					'decode_escaped_layout' => true,
				],
				[
					'action'                => 'update',
					'css'                   => ".x { color: red; }\n.y { color: blue; }\n",
					'decode_escaped_layout' => true,
				],
			],
		];
	}

	public function test_absent_and_explicit_false_decode_flag_share_one_token_binding(): void {
		$GLOBALS['stonewright_test_options']['stonewright_mode'] = 'production-safe';
		$raw = [
			'name'     => 'task5-canonical.php',
			'contents' => "<?php\r\nreturn 7;",
		];
		$issued = ( new IssueConfirmationToken() )->execute(
			[
				'ability' => 'stonewright/sandbox-write',
				'args'    => $raw,
			]
		);
		self::assertIsArray( $issued );

		$result = ( new SandboxWrite() )->execute(
			array_merge(
				$raw,
				[
					'decode_escaped_layout' => false,
					'confirmation_token'    => $issued['token'],
				]
			)
		);

		self::assertIsArray( $result );
		self::assertSame( "<?php\nreturn 7;\n", SandboxFiles::read( 'task5-canonical.php' ) );

		$internal_audit = $this->audit_args( 'sandbox.write' );
		self::assertSame(
			substr( hash( 'sha256', "<?php\nreturn 7;\n" ), 0, 8 ),
			$internal_audit['content_sha8'] ?? null
		);

		$ability_audit = $this->audit_args( 'stonewright/sandbox-write' );
		self::assertStringContainsString( '[redacted', (string) ( $ability_audit['contents'] ?? '' ) );
		self::assertStringContainsString( '[redacted', (string) ( $ability_audit['confirmation_token'] ?? '' ) );
		self::assertStringNotContainsString(
			(string) $issued['token'],
			(string) wp_json_encode( $ability_audit )
		);
	}

	public function test_true_decode_flag_cannot_be_replayed_as_false(): void {
		$GLOBALS['stonewright_test_options']['stonewright_mode'] = 'production-safe';
		$issued = ( new IssueConfirmationToken() )->execute(
			[
				'ability' => 'stonewright/php-execute',
				'args'    => [
					'code'                  => 'return 42;',
					'decode_escaped_layout' => true,
				],
			]
		);
		self::assertIsArray( $issued );

		$result = ( new PhpExecute() )->execute(
			[
				'code'                  => 'return 42;',
				'decode_escaped_layout' => false,
				'confirmation_token'    => $issued['token'],
			]
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_confirmation_args_mismatch', $result->get_error_code() );
	}

	public function test_php_execute_guards_and_audit_receive_canonical_body(): void {
		$blocked = ( new PhpExecute() )->execute(
			[
				'code'                  => 'file_put_contents\t("/tmp/task5.php", "x");',
				'decode_escaped_layout' => true,
			]
		);

		self::assertInstanceOf( \WP_Error::class, $blocked );
		self::assertSame( 'stonewright_php_code_file_write_blocked', $blocked->get_error_code() );

		$result = ( new PhpExecute() )->execute(
			[
				'code'                  => '// comment\nreturn 42;',
				'decode_escaped_layout' => true,
			]
		);
		self::assertIsArray( $result );
		self::assertSame( 42, $result['result'] );

		$audit = $this->audit_args( 'stonewright/php-execute' );
		self::assertSame(
			hash( 'sha256', "// comment\nreturn 42;" ),
			$audit['_meta']['code_sha256'] ?? null
		);
		self::assertStringNotContainsString( '// comment', (string) wp_json_encode( $audit ) );
	}

	/**
	 * @dataProvider theme_patch_languages
	 */
	public function test_theme_patch_hashes_the_candidate_built_from_canonical_content(
		string $path,
		string $content,
		string $canonical_content,
		string $language
	): void {
		$before = (string) file_get_contents( $this->theme_dir . '/' . $path );
		$result = ( new ThemeFilePatch() )->execute(
			[
				'path'                  => $path,
				'mode'                  => 'append',
				'content'               => $content,
				'decode_escaped_layout' => true,
				'dry_run'               => true,
				'native_gap'            => [
					'reason'        => 'The fixture exercises the typed custom-code transaction.',
					'methods_tried' => [ 'typed_api' ],
				],
			]
		);

		self::assertIsArray( $result );
		self::assertSame( $language, $result['language'] );
		self::assertSame( hash( 'sha256', $before . $canonical_content ), $result['after_sha256'] );

		$audit = $this->audit_args( 'stonewright/theme-file-patch' );
		self::assertSame(
			'[redacted, length=' . mb_strlen( $canonical_content ) . ', sha256=' .
				substr( hash( 'sha256', $canonical_content ), 0, 8 ) . ']',
			$audit['content'] ?? null
		);
		self::assertStringNotContainsString( $canonical_content, (string) wp_json_encode( $audit ) );
	}

	/**
	 * @return array<string, array{string, string, string, string}>
	 */
	public static function theme_patch_languages(): array {
		return [
			'php' => [
				'functions.php',
				'function sw_after() {\n\treturn 1;\n}',
				"function sw_after() {\n\treturn 1;\n}\n",
				'php',
			],
			'css' => [
				'style.css',
				'.after { color: red; }\n.after strong { color: blue; }',
				".after { color: red; }\n.after strong { color: blue; }\n",
				'css',
			],
			'js'  => [
				'scripts.js',
				'const after = 1;\nrun(after);',
				"const after = 1;\nrun(after);\n",
				'js',
			],
		];
	}

	public function test_invalid_theme_path_is_rejected_before_token_issue_or_target_audit(): void {
		$args = [
			'path'    => '../style.css',
			'mode'    => 'replace_all',
			'content' => 'body {}',
		];
		$issued = ( new IssueConfirmationToken() )->execute(
			[
				'ability' => 'stonewright/theme-file-patch',
				'args'    => $args,
			]
		);
		self::assertInstanceOf( \WP_Error::class, $issued );
		self::assertSame( 'stonewright_theme_file_path_traversal', $issued->get_error_code() );

		$GLOBALS['stonewright_test_wpdb_inserts'] = [];
		$result = ( new ThemeFilePatch() )->execute( $args );
		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_theme_file_path_traversal', $result->get_error_code() );
		self::assertSame( [], $this->audits_for( 'stonewright/theme-file-patch' ) );
	}

	public function test_theme_custom_css_persists_and_audits_canonical_css_without_source_leak(): void {
		$raw       = '.task5 { color: red; }\n.task5 strong { color: blue; }';
		$canonical = ".task5 { color: red; }\n.task5 strong { color: blue; }\n";
		$result    = ( new ThemeCustomCss() )->execute(
			[
				'action'                => 'update',
				'css'                   => $raw,
				'decode_escaped_layout' => true,
			]
		);

		self::assertIsArray( $result );
		self::assertSame( $canonical, $GLOBALS['stonewright_test_custom_css'] );
		self::assertSame( $canonical, $result['css'] );

		$audit = $this->audit_args( 'stonewright/theme-custom-css' );
		self::assertSame(
			'[redacted, length=' . mb_strlen( $canonical ) . ', sha256=' .
				substr( hash( 'sha256', $canonical ), 0, 8 ) . ']',
			$audit['css'] ?? null
		);
		self::assertStringNotContainsString( '.task5', (string) wp_json_encode( $audit ) );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function audit_args( string $ability ): array {
		$audits = $this->audits_for( $ability );
		self::assertNotEmpty( $audits, "Expected an audit row for {$ability}." );
		$decoded = json_decode( (string) ( $audits[ count( $audits ) - 1 ]['data']['sanitized_args'] ?? '{}' ), true );
		self::assertIsArray( $decoded );
		return $decoded;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function audits_for( string $ability ): array {
		return array_values(
			array_filter(
				$GLOBALS['stonewright_test_wpdb_inserts'],
				static fn( array $insert ): bool => $ability === ( $insert['data']['ability_name'] ?? null )
			)
		);
	}

	private function remove_sandbox_fixture( string $name ): void {
		$dir = SandboxFiles::draft_dir();
		foreach ( glob( $dir . '/' . $name . '*' ) ?: [] as $path ) {
			@unlink( $path );
		}
	}

	private function rm_tree( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		foreach ( scandir( $dir ) ?: [] as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}
			$path = $dir . '/' . $item;
			is_dir( $path ) ? $this->rm_tree( $path ) : @unlink( $path );
		}
		@rmdir( $dir );
	}
}
