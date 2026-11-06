<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Themes;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\Themes\ThemeFilePatch;
use Stonewright\WpMcp\Security\CustomCodeGrant;
use Stonewright\WpMcp\Security\IncidentStore;

/**
 * Where theme-file-patch puts its content. Block placement (the default) keeps the bytes it has always
 * produced, so proposals and grants bound to an after hash stay valid. Exact placement and
 * replace_string write the content byte for byte, so an edit inside one line works.
 *
 * @covers \Stonewright\WpMcp\Abilities\Themes\ThemeFilePatch
 * @covers \Stonewright\WpMcp\Abilities\Common\CodePayloadCanonicalizer
 */
final class ThemeFilePatchPlacementTest extends TestCase {

	private const GAP = [
		'reason'        => 'No typed API owns this file segment.',
		'methods_tried' => [ 'typed_api' ],
	];

	private string $theme_dir;

	protected function setUp(): void {
		$GLOBALS['stonewright_test_user_caps']       = [ 'read' => true, 'edit_theme_options' => true, 'edit_css' => true, 'manage_options' => true ];
		$GLOBALS['stonewright_test_user_logged_in']  = true;
		$GLOBALS['stonewright_test_current_user_id'] = 1;
		$GLOBALS['stonewright_test_options']         = [ 'stonewright_mode' => 'development', 'stonewright_disabled_abilities' => [] ];
		$GLOBALS['stonewright_test_wpdb_inserts']    = [];
		$GLOBALS['stonewright_test_transients']      = [];
		IncidentStore::reset_for_tests();

		$this->theme_dir = sys_get_temp_dir() . '/sw-theme-placement-' . bin2hex( random_bytes( 4 ) );
		mkdir( $this->theme_dir );
		$GLOBALS['stonewright_test_stylesheet_directory'] = $this->theme_dir;
		$GLOBALS['stonewright_test_stylesheet']           = 'sw-test-theme';
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_user_caps']       = [];
		$GLOBALS['stonewright_test_options']         = [];
		$GLOBALS['stonewright_test_wpdb_inserts']    = [];
		$GLOBALS['stonewright_test_transients']      = [];
		$GLOBALS['stonewright_test_current_user_id'] = 0;
		unset( $GLOBALS['stonewright_test_stylesheet_directory'], $GLOBALS['stonewright_test_stylesheet'] );
		IncidentStore::reset_for_tests();
		foreach ( (array) glob( $this->theme_dir . '/*' ) as $file ) {
			if ( is_string( $file ) && is_file( $file ) ) {
				@unlink( $file );
			}
		}
		@rmdir( $this->theme_dir );
	}

	// Block placement: the bytes callers and stored proposals already depend on.

	public function test_block_placement_keeps_the_markers_and_puts_the_content_on_its_own_lines(): void {
		file_put_contents( $this->theme_dir . '/style.css', "/* sw:start */\nbody{color:#111;}\n/* sw:end */\n" );

		$after = $this->after( $this->between( 'style.css', '/* sw:start */', '/* sw:end */', 'body{color:#222;}' ) );

		self::assertSame( "/* sw:start */\nbody{color:#222;}\n\n/* sw:end */\n", $after );
	}

	public function test_explicit_block_placement_produces_the_same_bytes_as_the_default(): void {
		file_put_contents( $this->theme_dir . '/style.css', "/* sw:start */\nbody{color:#111;}\n/* sw:end */\n" );

		$default = $this->between( 'style.css', '/* sw:start */', '/* sw:end */', 'body{color:#222;}' );
		$block   = $this->between( 'style.css', '/* sw:start */', '/* sw:end */', 'body{color:#222;}', [ 'placement' => 'block' ] );

		self::assertIsArray( $default );
		self::assertIsArray( $block );
		self::assertSame( $default['after_sha256'], $block['after_sha256'] );
	}

	public function test_block_placement_of_insert_after_marker_and_append_is_unchanged(): void {
		file_put_contents( $this->theme_dir . '/style.css', "/* theme */\nbody{color:#111;}" );

		$insert = $this->patch( [ 'path' => 'style.css', 'mode' => 'insert_after_marker', 'marker' => '/* theme */', 'content' => 'a{color:red;}' ] );
		self::assertSame( "/* theme */\na{color:red;}\n\nbody{color:#111;}", $this->after( $insert ) );

		$append = $this->patch( [ 'path' => 'style.css', 'mode' => 'append', 'content' => 'a{color:red;}' ] );
		self::assertSame( "/* theme */\nbody{color:#111;}\na{color:red;}\n", $this->after( $append ) );
	}

	public function test_a_marker_that_occurs_twice_is_ambiguous(): void {
		file_put_contents( $this->theme_dir . '/style.css', "/* sw:start */\nbody{color:#111;}\n/* sw:end */\n/* sw:end */\n" );

		$result = $this->between( 'style.css', '/* sw:start */', '/* sw:end */', 'body{color:#222;}', [ 'placement' => 'exact' ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_theme_file_marker_ambiguous', $result->get_error_code() );
		self::assertSame( 2, $result->get_error_data()['matches'] );
	}

	public function test_an_end_marker_before_or_overlapping_the_start_marker_is_missing(): void {
		file_put_contents( $this->theme_dir . '/style.css', "/* sw:end */\nbody{color:#111;}\n/* sw:start */\n" );
		$result = $this->between( 'style.css', '/* sw:start */', '/* sw:end */', 'body{color:#222;}', [ 'placement' => 'exact' ] );
		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'end_marker_missing', $result->get_error_data()['error_code'] );

		file_put_contents( $this->theme_dir . '/style.css', "/* sw:start-end */\nbody{color:#111;}\n" );
		$result = $this->between( 'style.css', '/* sw:start', 'start-end */', 'body{color:#222;}', [ 'placement' => 'exact' ] );
		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'end_marker_missing', $result->get_error_data()['error_code'] );
	}

	// Exact placement.

	public function test_exact_placement_edits_inside_one_line(): void {
		file_put_contents( $this->theme_dir . '/functions.php', "<?php\n\$data = [\n\t'bio'  => ( \$bio ),\n];\n" );

		$result = $this->between( 'functions.php', "'bio'  =>", '( $bio ),', ' wp_kses_post', [ 'placement' => 'exact' ] );

		self::assertSame( "<?php\n\$data = [\n\t'bio'  => wp_kses_post( \$bio ),\n];\n", $this->after( $result ) );
		self::assertSame( 1, $result['diff_preview']['changed_lines'] );
	}

	public function test_exact_placement_keeps_the_layout_around_the_markers(): void {
		file_put_contents( $this->theme_dir . '/style.css', "/* theme */\n/* sw:start */\nbody{color:#111;}\n/* sw:end */\na{color:red;}\n" );

		$after = $this->after( $this->between( 'style.css', '/* sw:start */', '/* sw:end */', "\nbody{color:#222;}\n", [ 'placement' => 'exact' ] ) );

		self::assertSame( "/* theme */\n/* sw:start */\nbody{color:#222;}\n/* sw:end */\na{color:red;}\n", $after );
	}

	public function test_sending_the_current_block_back_with_exact_placement_changes_nothing(): void {
		file_put_contents( $this->theme_dir . '/style.css', "/* sw:start */\nbody{color:#111;}  \n/* sw:end */\n" );

		$result = $this->between( 'style.css', '/* sw:start */', '/* sw:end */', "\nbody{color:#111;}  \n", [ 'placement' => 'exact' ] );

		self::assertIsArray( $result );
		self::assertFalse( $result['changed'] );
		self::assertFalse( $result['approval_required'] );
		self::assertSame( '', $result['approval_url'] );
	}

	public function test_exact_placement_keeps_crlf_line_endings_the_caller_sends(): void {
		file_put_contents( $this->theme_dir . '/style.css', "/* theme */\r\n/* sw:start */\r\nbody{color:#111;}\r\n/* sw:end */\r\n" );

		$after = $this->after( $this->between( 'style.css', '/* sw:start */', '/* sw:end */', "\r\nbody{color:#222;}\r\n", [ 'placement' => 'exact' ] ) );

		self::assertSame( "/* theme */\r\n/* sw:start */\r\nbody{color:#222;}\r\n/* sw:end */\r\n", $after );
	}

	public function test_exact_placement_of_insert_after_marker_append_and_replace_all(): void {
		file_put_contents( $this->theme_dir . '/style.css', "/* theme */\nbody{color:#111;}" );

		$insert = $this->patch( [ 'path' => 'style.css', 'mode' => 'insert_after_marker', 'marker' => "/* theme */\n", 'content' => "a{color:red;}\n", 'placement' => 'exact' ] );
		self::assertSame( "/* theme */\na{color:red;}\nbody{color:#111;}", $this->after( $insert ) );

		$append = $this->patch( [ 'path' => 'style.css', 'mode' => 'append', 'content' => 'a{color:red;}', 'placement' => 'exact' ] );
		self::assertSame( "/* theme */\nbody{color:#111;}a{color:red;}", $this->after( $append ) );

		$all = $this->patch( [ 'path' => 'style.css', 'mode' => 'replace_all', 'content' => 'p{margin:0;}  ', 'placement' => 'exact' ] );
		self::assertSame( 'p{margin:0;}  ', $this->after( $all ) );
	}

	public function test_an_unknown_placement_is_refused_before_anything_is_read(): void {
		file_put_contents( $this->theme_dir . '/style.css', "/* sw:start */\nbody{color:#111;}\n/* sw:end */\n" );

		$result = $this->between( 'style.css', '/* sw:start */', '/* sw:end */', 'body{color:#222;}', [ 'placement' => 'inline' ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_code_payload_invalid', $result->get_error_code() );
		self::assertSame( 'placement', $result->get_error_data()['field'] );
	}

	// replace_string.

	public function test_replace_string_replaces_the_one_occurrence_byte_for_byte(): void {
		file_put_contents( $this->theme_dir . '/functions.php', "<?php\n\$data = [\n\t'bio'  => ( \$bio ),\n];\n" );

		$result = $this->replace( 'functions.php', "'bio'  => ( \$bio ),", "'bio'  => wp_kses_post( \$bio )," );

		self::assertSame( "<?php\n\$data = [\n\t'bio'  => wp_kses_post( \$bio ),\n];\n", $this->after( $result ) );
		self::assertSame( 'replace_string', $result['mode'] );
		self::assertTrue( $result['approval_required'] );
	}

	public function test_replace_string_with_empty_content_deletes_the_text(): void {
		file_put_contents( $this->theme_dir . '/style.css', "body{color:#111;}\n.old{display:none;}\na{color:red;}\n" );

		$after = $this->after( $this->replace( 'style.css', ".old{display:none;}\n", '' ) );

		self::assertSame( "body{color:#111;}\na{color:red;}\n", $after );
	}

	public function test_replace_string_matches_crlf_text_as_it_is_in_the_file(): void {
		file_put_contents( $this->theme_dir . '/style.css', "body{color:#111;}\r\na{color:red;}\r\n" );

		self::assertInstanceOf( \WP_Error::class, $this->replace( 'style.css', "body{color:#111;}\na", 'x' ) );

		$after = $this->after( $this->replace( 'style.css', "body{color:#111;}\r\na", "body{color:#222;}\r\na" ) );
		self::assertSame( "body{color:#222;}\r\na{color:red;}\r\n", $after );
	}

	public function test_replace_string_that_changes_nothing_needs_no_approval(): void {
		file_put_contents( $this->theme_dir . '/style.css', "body{color:#111;}\n" );

		$result = $this->replace( 'style.css', 'color:#111', 'color:#111' );

		self::assertIsArray( $result );
		self::assertFalse( $result['changed'] );
		self::assertFalse( $result['approval_required'] );
	}

	public function test_replace_string_refuses_a_missing_an_empty_and_an_ambiguous_old_string(): void {
		file_put_contents( $this->theme_dir . '/style.css', "a{color:red;}\nb{color:red;}\n" );

		$missing = $this->replace( 'style.css', 'color:blue', 'color:green' );
		self::assertInstanceOf( \WP_Error::class, $missing );
		self::assertSame( 'stonewright_theme_file_old_string_missing', $missing->get_error_code() );
		self::assertSame( 'old_string', $missing->get_error_data()['field'] );

		$empty = $this->patch( [ 'path' => 'style.css', 'mode' => 'replace_string', 'content' => 'x' ] );
		self::assertInstanceOf( \WP_Error::class, $empty );
		self::assertSame( 'stonewright_theme_file_old_string_required', $empty->get_error_code() );

		$ambiguous = $this->replace( 'style.css', 'color:red', 'color:green' );
		self::assertInstanceOf( \WP_Error::class, $ambiguous );
		self::assertSame( 'stonewright_theme_file_old_string_ambiguous', $ambiguous->get_error_code() );
		self::assertSame( 2, $ambiguous->get_error_data()['matches'] );
	}

	public function test_replace_string_counts_overlapping_occurrences(): void {
		file_put_contents( $this->theme_dir . '/style.css', "/* aaa */\n" );

		$result = $this->replace( 'style.css', 'aa', 'b' );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_theme_file_old_string_ambiguous', $result->get_error_code() );
		self::assertSame( 2, $result->get_error_data()['matches'] );
	}

	public function test_replace_string_candidate_is_validated_as_a_complete_file(): void {
		file_put_contents( $this->theme_dir . '/functions.php', "<?php\nfunction sw_x() { return 1; }\n" );

		$result = $this->replace( 'functions.php', 'return 1; }', 'return 1;' );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'failed', $result->get_error_data()['verification_status'] );
		self::assertSame( 'fail', $result->get_error_data()['validator_summary']['result'] );
	}

	public function test_replace_string_applies_with_a_grant_bound_to_its_candidate(): void {
		file_put_contents( $this->theme_dir . '/style.css', "/* theme */\nbody{color:#111;}\n" );

		$dry = $this->replace( 'style.css', 'color:#111', 'color:#222' );
		self::assertIsArray( $dry );
		$grant = CustomCodeGrant::issue(
			[
				'path'          => 'style.css',
				'after_sha256'  => $dry['after_sha256'],
				'language'      => 'css',
				'changed_bytes' => $dry['changed_bytes'],
			]
		);
		self::assertIsArray( $grant, $grant instanceof \WP_Error ? $grant->get_error_message() : '' );

		$result = ( new ThemeFilePatch() )->execute(
			[
				'path'              => 'style.css',
				'mode'              => 'replace_string',
				'old_string'        => 'color:#111',
				'content'           => 'color:#222',
				'custom_code_grant' => (string) $grant['token'],
			]
		);

		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_code() . ': ' . $result->get_error_message() : '' );
		self::assertSame( "/* theme */\nbody{color:#222;}\n", (string) file_get_contents( $this->theme_dir . '/style.css' ) );

		$audit = $this->audit_args();
		self::assertStringContainsString( '[redacted', (string) ( $audit['old_string'] ?? '' ) );
		self::assertStringNotContainsString( 'color:#111', (string) wp_json_encode( $audit ) );
	}

	/**
	 * @param array<string, mixed> $extra
	 * @return array<string, mixed>|\WP_Error
	 */
	private function between( string $path, string $start, string $end, string $content, array $extra = [] ): array|\WP_Error {
		return $this->patch(
			[
				'path'         => $path,
				'mode'         => 'replace_between_markers',
				'start_marker' => $start,
				'end_marker'   => $end,
				'content'      => $content,
			] + $extra
		);
	}

	/** @return array<string, mixed>|\WP_Error */
	private function replace( string $path, string $old, string $content ): array|\WP_Error {
		return $this->patch(
			[
				'path'       => $path,
				'mode'       => 'replace_string',
				'old_string' => $old,
				'content'    => $content,
			]
		);
	}

	/**
	 * A dry run of theme-file-patch with the given arguments.
	 *
	 * @param array<string, mixed> $args
	 * @return array<string, mixed>|\WP_Error
	 */
	private function patch( array $args ): array|\WP_Error {
		return ( new ThemeFilePatch() )->execute( $args + [ 'dry_run' => true, 'native_gap' => self::GAP ] );
	}

	/**
	 * The candidate the dry run hashed: the preview holds the whole file while it is short.
	 *
	 * @param array<string, mixed>|\WP_Error $result
	 */
	private function after( array|\WP_Error $result ): string {
		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_code() . ': ' . $result->get_error_message() : '' );
		$after = (string) $result['preview'];
		self::assertSame( $result['after_sha256'], hash( 'sha256', $after ), 'preview is the whole candidate' );
		return $after;
	}

	/** @return array<string, mixed> The sanitized arguments of the last theme-file-patch audit row. */
	private function audit_args(): array {
		$audits = array_values(
			array_filter(
				(array) $GLOBALS['stonewright_test_wpdb_inserts'],
				static fn( array $insert ): bool => 'stonewright/theme-file-patch' === ( $insert['data']['ability_name'] ?? null )
			)
		);
		self::assertNotEmpty( $audits, 'Expected an audit row for stonewright/theme-file-patch.' );
		$decoded = json_decode( (string) ( $audits[ count( $audits ) - 1 ]['data']['sanitized_args'] ?? '{}' ), true );
		self::assertIsArray( $decoded );
		return $decoded;
	}
}
