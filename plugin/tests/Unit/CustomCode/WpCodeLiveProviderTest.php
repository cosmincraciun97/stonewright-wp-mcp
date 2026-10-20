<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\CustomCode;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\CustomCode\Providers\WpCodeProvider;
use Stonewright\WpMcp\Security\CustomCodeGrant;

/**
 * WpCodeProvider on WPCode's own functions, without a test backend. WPCode keeps the code type of a
 * snippet as a `wpcode_type` term and has no snippet list API, so the provider lists snippets with a
 * WP_Query and has to ask WPCode for each type. Before an apply, the provider parses the active PHP
 * snippets together; an active CSS, JavaScript or HTML snippet is not PHP and stays out of that check.
 *
 * Every test runs in its own process: the WPCode functions and post_type_exists()
 * (Fixtures/wpcode-functions-stub.php) cannot be unloaded, and the other tests run without WPCode.
 *
 * @covers \Stonewright\WpMcp\CustomCode\Providers\WpCodeProvider
 * @covers \Stonewright\WpMcp\CustomCode\WpCodeRuntimeValidator
 */
final class WpCodeLiveProviderTest extends TestCase {

	private const TARGET = 101;

	private string $log_file = '';
	private string|false $previous_log = false;

	protected function setUp(): void {
		// A separate process reports anything written to stderr as an error, and the change ledger,
		// which has no table here, logs a warning on every apply.
		$this->log_file     = (string) tempnam( sys_get_temp_dir(), 'sw-wpcode-live-' );
		$this->previous_log = ini_get( 'error_log' );
		ini_set( 'error_log', $this->log_file );

		$GLOBALS['stonewright_test_user_caps']         = [
			'read'           => true,
			'manage_options' => true,
		];
		$GLOBALS['stonewright_test_user_logged_in']    = true;
		$GLOBALS['stonewright_test_current_user_id']   = 9;
		$GLOBALS['stonewright_test_transients']        = [];
		$GLOBALS['stonewright_test_options']           = [ 'stonewright_mode' => 'development' ];
		$GLOBALS['stonewright_test_post_types']        = [
			'wpcode' => (object) [
				'cap' => (object) [
					'create_posts'  => 'edit_posts',
					'publish_posts' => 'publish_posts',
				],
			],
		];
		$GLOBALS['stonewright_test_posts']             = [];
		$GLOBALS['stonewright_test_object_terms']      = [];
		$GLOBALS['stonewright_test_search_posts']      = [];
	}

	protected function tearDown(): void {
		ini_set( 'error_log', (string) $this->previous_log );
		@unlink( $this->log_file );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_apply_to_an_active_php_snippet_leaves_out_active_css_js_and_html_snippets(): void {
		require_once __DIR__ . '/Fixtures/wpcode-functions-stub.php';
		$this->add_snippet( self::TARGET, 'php', "echo 'before';\n", true );
		$this->add_front_end_snippets();

		$candidate = "echo 'after';\n";
		$applied   = $this->apply_granted( new WpCodeProvider(), $candidate );

		self::assertIsArray( $applied, $applied instanceof \WP_Error ? $applied->get_error_message() : '' );
		self::assertTrue( $applied['effect_verified'] );
		self::assertSame( $candidate, $GLOBALS['stonewright_test_posts'][ self::TARGET ]->post_content );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_list_reports_the_code_type_wpcode_keeps_for_each_snippet(): void {
		require_once __DIR__ . '/Fixtures/wpcode-functions-stub.php';
		$this->add_snippet( self::TARGET, 'php', "echo 'before';\n", true );
		$this->add_front_end_snippets();
		$this->add_snippet( 105, 'css', ".draft-banner { display: none; }\n", false );

		$listed = ( new WpCodeProvider() )->list();

		self::assertIsArray( $listed );
		self::assertSame(
			[
				105 => 'css',
				104 => 'html',
				103 => 'js',
				102 => 'css',
				101 => 'php',
			],
			array_column( $listed['items'], 'language', 'id' )
		);
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_apply_leaves_out_active_snippets_that_wpcode_does_not_run_as_php(): void {
		require_once __DIR__ . '/Fixtures/wpcode-functions-stub.php';
		$this->add_snippet( self::TARGET, 'php', "echo 'before';\n", true );
		// A universal snippet starts as HTML, and a text snippet has no PHP at all.
		$this->add_snippet( 102, 'universal', "<p>Today: <?php echo esc_html( gmdate( 'Y-m-d' ) ); ?></p>\n", true );
		$this->add_snippet( 103, 'text', "Doors open at 9:00.\n", true );

		$applied = $this->apply_granted( new WpCodeProvider(), "echo 'after';\n" );

		self::assertIsArray( $applied, $applied instanceof \WP_Error ? $applied->get_error_message() : '' );
		self::assertTrue( $applied['effect_verified'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_an_active_php_snippet_that_does_not_parse_still_blocks_the_apply(): void {
		require_once __DIR__ . '/Fixtures/wpcode-functions-stub.php';
		$this->add_snippet( self::TARGET, 'php', "echo 'before';\n", true );
		$this->add_snippet( 102, 'php', "function broken_helper( {\n", true );

		$candidate = "echo 'after';\n";
		$grant     = CustomCodeGrant::issue(
			[
				'path'         => 'wpcode/snippet/' . self::TARGET,
				'after_sha256' => hash( 'sha256', $candidate ),
				'language'     => 'php',
			]
		);
		self::assertIsArray( $grant );
		$result = ( new WpCodeProvider() )->apply(
			[
				'target_id'         => (string) self::TARGET,
				'code'              => $candidate,
				'custom_code_grant' => $grant['token'],
			]
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_php_candidate_invalid', $result->get_error_code() );
		self::assertSame( "echo 'before';\n", $GLOBALS['stonewright_test_posts'][ self::TARGET ]->post_content );
	}

	/**
	 * An active CSS, JavaScript and HTML snippet, none of which parses as PHP.
	 */
	private function add_front_end_snippets(): void {
		$this->add_snippet( 102, 'css', "footer.site-footer {\n\tbackground: #111;\n\tcolor: #eee;\n}\n", true );
		$this->add_snippet( 103, 'js', "const ready = () => document.body.classList.add( 'ready' );\n", true );
		$this->add_snippet( 104, 'html', "<div class=\"notice\">Doors open at 9:00</div>\n", true );
	}

	/**
	 * A snippet stored as WPCode stores it: a `wpcode` post with the code as content, published while
	 * active, with the code type as a `wpcode_type` term and no code-type post meta.
	 */
	private function add_snippet( int $id, string $code_type, string $code, bool $active ): void {
		$GLOBALS['stonewright_test_posts'][ $id ] = (object) [
			'ID'           => $id,
			'post_type'    => 'wpcode',
			'post_title'   => 'Snippet ' . $id,
			'post_status'  => $active ? 'publish' : 'draft',
			'post_content' => $code,
			'meta'         => [],
		];
		$GLOBALS['stonewright_test_object_terms'][ $id ]['wpcode_type'] = [ $code_type ];

		// The WP_Query stub returns these ids; the provider asks for the newest first.
		$ids = array_keys( $GLOBALS['stonewright_test_posts'] );
		rsort( $ids );
		$GLOBALS['stonewright_test_search_posts'] = $ids;
	}

	/**
	 * Dry run, a grant for the exact candidate, then apply, as an operator would.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	private function apply_granted( WpCodeProvider $provider, string $candidate ) {
		$dry = $provider->dry_run(
			[
				'target_id' => (string) self::TARGET,
				'code'      => $candidate,
			]
		);
		if ( $dry instanceof \WP_Error ) {
			return $dry;
		}
		$grant = CustomCodeGrant::issue(
			[
				'path'         => 'wpcode/snippet/' . self::TARGET,
				'after_sha256' => $dry['after_sha256'],
				'language'     => 'php',
			]
		);
		self::assertIsArray( $grant );
		return $provider->apply(
			[
				'target_id'              => (string) self::TARGET,
				'code'                   => $candidate,
				'custom_code_grant'      => $grant['token'],
				'expected_before_sha256' => $dry['before_sha256'],
			]
		);
	}
}
