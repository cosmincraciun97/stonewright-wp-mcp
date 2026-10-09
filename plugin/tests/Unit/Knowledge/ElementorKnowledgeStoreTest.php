<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Knowledge;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\Knowledge\DescribeWidget;
use Stonewright\WpMcp\Abilities\Knowledge\ExplainEditor;
use Stonewright\WpMcp\Abilities\Knowledge\KnowledgeRefresh;
use Stonewright\WpMcp\Abilities\Knowledge\KnowledgeSearch;
use Stonewright\WpMcp\Knowledge\ElementorKnowledgeBase;
use Stonewright\WpMcp\Knowledge\ElementorKnowledgeStore;

/**
 * The Elementor knowledge store lives in a private, guarded folder under uploads.
 * The refresh ability fills it and the readers use only that folder.
 *
 * @covers \Stonewright\WpMcp\Knowledge\ElementorKnowledgeStore
 * @covers \Stonewright\WpMcp\Knowledge\ElementorKnowledgeBase
 * @covers \Stonewright\WpMcp\Abilities\Knowledge\KnowledgeRefresh
 * @covers \Stonewright\WpMcp\Abilities\Knowledge\KnowledgeSearch
 * @covers \Stonewright\WpMcp\Abilities\Knowledge\ExplainEditor
 * @covers \Stonewright\WpMcp\Abilities\Knowledge\DescribeWidget
 * @covers \Stonewright\WpMcp\Support\DirectoryGuard
 */
final class ElementorKnowledgeStoreTest extends TestCase {

	private const STORE = 'stonewright-private/knowledge/elementor';

	private string $uploads;

	protected function setUp(): void {
		$this->uploads = sys_get_temp_dir() . '/sw-knowledge-' . bin2hex( random_bytes( 6 ) );
		mkdir( $this->uploads, 0777, true );
		$GLOBALS['stonewright_test_upload_dir']      = [ 'basedir' => $this->uploads, 'baseurl' => 'https://example.test/wp-content/uploads', 'error' => false ];
		$GLOBALS['stonewright_test_options']         = [ 'stonewright_mode' => 'development' ];
		$GLOBALS['stonewright_test_user_caps']       = [ 'manage_options' => true, 'read' => true ];
		$GLOBALS['stonewright_test_user_logged_in']  = true;
		$GLOBALS['stonewright_test_current_user_id'] = 1;
		unset( $GLOBALS['stonewright_test_wp_remote_get'], $GLOBALS['stonewright_test_wp_remote_get_calls'] );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['stonewright_test_upload_dir'], $GLOBALS['stonewright_test_wp_remote_get'], $GLOBALS['stonewright_test_wp_remote_get_calls'] );
		$GLOBALS['stonewright_test_options']         = [];
		$GLOBALS['stonewright_test_user_caps']       = [];
		$GLOBALS['stonewright_test_user_logged_in']  = false;
		$GLOBALS['stonewright_test_current_user_id'] = 0;
		$this->remove_tree( $this->uploads );
	}

	// ---------------------------------------------------------------------
	// Helpers.
	// ---------------------------------------------------------------------

	private function remove_tree( string $dir ): void {
		if ( is_link( $dir ) ) {
			@unlink( $dir );
			@rmdir( $dir );
			return;
		}
		if ( ! is_dir( $dir ) ) {
			return;
		}
		foreach ( scandir( $dir ) ?: [] as $name ) {
			if ( '.' === $name || '..' === $name ) {
				continue;
			}
			$this->remove_tree( $dir . '/' . $name );
			if ( is_file( $dir . '/' . $name ) ) {
				@unlink( $dir . '/' . $name );
			}
		}
		@rmdir( $dir );
	}

	private function store_dir(): string {
		return $this->uploads . '/' . self::STORE;
	}

	/** @return array<string, mixed>|\WP_Error */
	private function refresh( array $args ): array|\WP_Error {
		return ( new KnowledgeRefresh() )->execute( $args );
	}

	/** @return array<string, mixed> */
	private function refresh_ok( string $url, ?string $hub = null, string $body = '' ): array {
		$args = [
			'url'  => $url,
			'body' => '' !== $body ? $body : "## Navigator\nThe navigator panel lists every element on the page and lets you reorder them.\n",
		];
		if ( null !== $hub ) {
			$args['hub'] = $hub;
		}
		$result = $this->refresh( $args );
		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() : '' );
		return $result;
	}

	/** @return list<string> */
	private function files_under( string $dir ): array {
		$out = [];
		if ( ! is_dir( $dir ) ) {
			return $out;
		}
		$it = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $file ) {
			$out[] = str_replace( '\\', '/', substr( (string) $file, strlen( $dir ) + 1 ) );
		}
		sort( $out );
		return $out;
	}

	private function seed( string $hub, string $name, string $body ): void {
		$dir = $this->store_dir() . '/' . $hub;
		mkdir( $dir, 0777, true );
		file_put_contents( $dir . '/' . $name . '.md', "---\ntitle: " . ucfirst( $name ) . "\nsource_url: https://elementor.com/help/" . $name . "\nfetched_at: " . gmdate( 'c' ) . "\n---\n\n" . $body . "\n" );
	}

	// ---------------------------------------------------------------------
	// Readers find refreshed entries.
	// ---------------------------------------------------------------------

	public function test_readers_find_an_entry_written_by_the_refresh_ability(): void {
		$result = $this->refresh_ok( 'https://elementor.com/help/build-with-the-editor/navigator-panel', null );

		self::assertSame( 'created', $result['status'] );
		self::assertSame( 'editor', $result['hub'] );
		self::assertSame( self::STORE . '/editor/navigator-panel.md', $result['file_path'] );
		self::assertFileExists( $this->store_dir() . '/editor/navigator-panel.md' );

		$search = ElementorKnowledgeBase::search( 'navigator reorder', 'editor', 5 );
		self::assertCount( 1, $search['results'] );
		self::assertSame( self::STORE . '/editor/navigator-panel.md', $search['results'][0]['path'] );
		self::assertSame( 'https://elementor.com/help/build-with-the-editor/navigator-panel', $search['results'][0]['source_url'] );
		self::assertArrayNotHasKey( 'hint', $search );

		$explain = ElementorKnowledgeBase::explain_editor( 'navigator', null, 5 );
		self::assertNotEmpty( $explain['results'] );

		$ability = ( new KnowledgeSearch() )->execute( [ 'query' => 'navigator' ] );
		self::assertIsArray( $ability );
		self::assertNotEmpty( $ability['results'] );
		$ability = ( new ExplainEditor() )->execute( [ 'topic' => 'navigator', 'area' => 'editor' ] );
		self::assertIsArray( $ability );
		self::assertNotEmpty( $ability['results'] );
	}

	public function test_describe_widget_returns_a_refreshed_widget_article(): void {
		$this->refresh_ok( 'https://elementor.com/widgets/countdown-widget', null, "## Countdown\nThe countdown widget shows a due date timer.\n" );

		$result = ElementorKnowledgeBase::describe_widget( 'countdown' );

		self::assertNotEmpty( $result['documents'] );
		self::assertArrayNotHasKey( 'hint', $result );
		$ability = ( new DescribeWidget() )->execute( [ 'widget' => 'countdown' ] );
		self::assertIsArray( $ability );
		self::assertNotEmpty( $ability['documents'] );
	}

	public function test_readers_skip_the_change_log_and_underscore_files(): void {
		$this->refresh_ok( 'https://elementor.com/help/build-with-the-editor/navigator-panel' );
		file_put_contents( $this->store_dir() . '/editor/_draft.md', "---\ntitle: Draft\n---\nnavigator draft\n" );

		self::assertFileExists( $this->store_dir() . '/_change_log.md' );
		$paths = array_column( ElementorKnowledgeBase::search( 'navigator', null, 20 )['results'], 'path' );
		self::assertSame( [ self::STORE . '/editor/navigator-panel.md' ], $paths );
	}

	public function test_a_second_refresh_of_the_same_content_reports_unchanged(): void {
		$first  = $this->refresh_ok( 'https://elementor.com/help/build-with-the-editor/navigator-panel' );
		$second = $this->refresh_ok( 'https://elementor.com/help/build-with-the-editor/navigator-panel' );

		self::assertTrue( $first['content_changed'] );
		self::assertFalse( $second['content_changed'] );
		self::assertSame( 'unchanged', $second['status'] );
		self::assertSame( $first['hash'], $second['previous_hash'] );
	}

	// ---------------------------------------------------------------------
	// Empty store.
	// ---------------------------------------------------------------------

	public function test_empty_store_returns_nothing_and_names_the_refresh_ability(): void {
		$search = ElementorKnowledgeBase::search( 'elementor container', null, 5 );

		self::assertSame( [], $search['results'] );
		self::assertArrayHasKey( 'hint', $search );
		self::assertStringContainsString( 'stonewright/elementor-knowledge-refresh', (string) $search['hint'] );
		self::assertSame( 'stonewright/elementor-knowledge-refresh', $search['refresh_ability'] );

		$explain = ElementorKnowledgeBase::explain_editor( 'navigator', 'editor', 5 );
		self::assertSame( [], $explain['results'] );
		self::assertStringContainsString( 'stonewright/elementor-knowledge-refresh', (string) $explain['hint'] );

		$widget = ElementorKnowledgeBase::describe_widget( 'heading' );
		self::assertSame( [], $widget['documents'] );
		self::assertStringContainsString( 'stonewright/elementor-knowledge-refresh', (string) $widget['hint'] );

		$ability = ( new KnowledgeSearch() )->execute( [ 'query' => 'container' ] );
		self::assertIsArray( $ability );
		self::assertTrue( $ability['ok'] );
		self::assertSame( [], $ability['results'] );
		self::assertArrayHasKey( 'hint', $ability );
	}

	public function test_reading_an_empty_store_creates_nothing(): void {
		ElementorKnowledgeBase::search( 'container' );
		ElementorKnowledgeBase::describe_widget( 'heading' );

		self::assertSame( [], $this->files_under( $this->uploads ) );
		self::assertDirectoryDoesNotExist( $this->uploads . '/stonewright-private' );
	}

	public function test_a_store_with_documents_but_no_match_gives_no_hint(): void {
		$this->seed( 'editor', 'navigator', 'The navigator panel lists elements.' );

		$search = ElementorKnowledgeBase::search( 'zzzunmatched' );

		self::assertSame( [], $search['results'] );
		self::assertArrayNotHasKey( 'hint', $search );
	}

	public function test_an_unavailable_uploads_directory_reads_as_empty_and_refuses_a_write(): void {
		$GLOBALS['stonewright_test_upload_dir'] = [ 'basedir' => '', 'baseurl' => '', 'error' => 'Unable to create directory.' ];

		$search = ElementorKnowledgeBase::search( 'container' );
		self::assertSame( [], $search['results'] );
		self::assertArrayHasKey( 'hint', $search );

		$result = $this->refresh( [ 'url' => 'https://elementor.com/help/a', 'body' => "## A\nbody body body\n" ] );
		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_store_unavailable', $result->get_error_code() );
	}

	public function test_readers_ignore_an_unknown_area(): void {
		$this->seed( 'editor', 'navigator', 'The navigator panel lists elements.' );

		self::assertSame( [], ElementorKnowledgeBase::search( 'navigator', '../..' )['results'] );
		self::assertSame( [], ElementorKnowledgeBase::search( 'navigator', 'nope' )['results'] );
		self::assertCount( 1, ElementorKnowledgeBase::search( 'navigator', 'editor' )['results'] );
	}

	// ---------------------------------------------------------------------
	// Guard files.
	// ---------------------------------------------------------------------

	public function test_the_store_is_created_with_deny_guards_at_every_level(): void {
		$this->refresh_ok( 'https://elementor.com/help/build-with-the-editor/navigator-panel' );

		foreach ( [ 'stonewright-private', 'stonewright-private/knowledge', self::STORE, self::STORE . '/editor' ] as $level ) {
			$dir = $this->uploads . '/' . $level;
			self::assertFileExists( $dir . '/index.php', $level );
			self::assertFileExists( $dir . '/.htaccess', $level );
			self::assertFileExists( $dir . '/web.config', $level );
			self::assertStringContainsString( 'Require all denied', (string) file_get_contents( $dir . '/.htaccess' ) );
			self::assertStringContainsString( 'Deny from all', (string) file_get_contents( $dir . '/.htaccess' ) );
			self::assertStringContainsString( '<deny users="*" />', (string) file_get_contents( $dir . '/web.config' ) );
			self::assertStringStartsWith( '<?php', (string) file_get_contents( $dir . '/index.php' ) );
		}
	}

	public function test_existing_guard_files_are_not_overwritten(): void {
		mkdir( $this->uploads . '/stonewright-private', 0777, true );
		file_put_contents( $this->uploads . '/stonewright-private/.htaccess', "# custom\n" );

		$this->refresh_ok( 'https://elementor.com/help/build-with-the-editor/navigator-panel' );

		self::assertSame( "# custom\n", file_get_contents( $this->uploads . '/stonewright-private/.htaccess' ) );
		self::assertFileExists( $this->uploads . '/stonewright-private/index.php' );
	}

	public function test_a_refresh_writes_only_inside_the_private_folder(): void {
		$this->refresh_ok( 'https://elementor.com/help/build-with-the-editor/navigator-panel' );

		self::assertSame( [ 'stonewright-private' ], array_values( array_diff( scandir( $this->uploads ) ?: [], [ '.', '..' ] ) ) );
		$guards = [ '.htaccess', 'index.php', 'web.config' ];
		self::assertSame(
			[
				self::STORE . '/_change_log.md',
				self::STORE . '/editor/navigator-panel.md',
			],
			array_values(
				array_filter(
					$this->files_under( $this->uploads ),
					static fn ( string $file ): bool => ! in_array( basename( $file ), $guards, true )
				)
			)
		);
	}

	// ---------------------------------------------------------------------
	// Hosts.
	// ---------------------------------------------------------------------

	/** @return array<string, array{string}> */
	public static function refused_hosts(): array {
		return [
			'suffix without a dot'  => [ 'https://evilelementor.com/help/x' ],
			'domain as a prefix'    => [ 'https://elementor.com.example.test/help/x' ],
			'userinfo trick'        => [ 'https://elementor.com@example.test/help/x' ],
			'unrelated host'        => [ 'https://example.test/help/x' ],
			'prefix on a subdomain' => [ 'https://developers.elementor.com.example.test/x' ],
			'trailing label'        => [ 'https://elementor.company/help/x' ],
			'dashed prefix'         => [ 'https://not-elementor.com/help/x' ],
		];
	}

	/** @dataProvider refused_hosts */
	public function test_hosts_outside_elementor_com_are_refused_before_anything_is_fetched_or_written( string $url ): void {
		$result = $this->refresh( [ 'url' => $url, 'body' => "## A\nbody body body\n" ] );
		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_invalid_host', $result->get_error_code() );

		$result = $this->refresh( [ 'url' => $url ] );
		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_invalid_host', $result->get_error_code() );

		self::assertSame( [], $GLOBALS['stonewright_test_wp_remote_get_calls'] ?? [] );
		self::assertSame( [], $this->files_under( $this->uploads ) );
	}

	/** @return array<string, array{string}> */
	public static function accepted_hosts(): array {
		return [
			'apex'                 => [ 'https://elementor.com/help/a' ],
			'www subdomain'        => [ 'https://www.elementor.com/help/a' ],
			'developers subdomain' => [ 'https://developers.elementor.com/docs/a' ],
			'upper case host'      => [ 'https://ELEMENTOR.COM/help/a' ],
			'http'                 => [ 'http://elementor.com/help/a' ],
		];
	}

	public function test_a_fetch_refuses_local_addresses_and_limits_redirects(): void {
		$GLOBALS['stonewright_test_wp_remote_get'] = [
			'response' => [ 'code' => 200 ],
			'body'     => '<html><body><main><h1>Widget</h1><p>' . str_repeat( 'Article text about the widget. ', 60 ) . '</p></main></body></html>',
		];

		$this->refresh( [ 'url' => 'https://developers.elementor.com/docs/widgets/a/' ] );

		$calls = $GLOBALS['stonewright_test_wp_remote_get_calls'] ?? [];
		self::assertCount( 1, $calls );
		self::assertTrue( $calls[0]['args']['reject_unsafe_urls'] ?? false, 'Local and private addresses are refused, for the URL and every redirect.' );
		self::assertLessThanOrEqual( 3, $calls[0]['args']['redirection'] ?? 5 );
	}

	/** @dataProvider accepted_hosts */
	public function test_elementor_com_and_its_subdomains_are_accepted( string $url ): void {
		$result = $this->refresh( [ 'url' => $url, 'body' => "## A\nbody body body\n" ] );

		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() : '' );
		self::assertSame( 'created', $result['status'] );
	}

	// ---------------------------------------------------------------------
	// Hubs.
	// ---------------------------------------------------------------------

	/** @return array<string, array{mixed}> */
	public static function refused_hubs(): array {
		return [
			'parent traversal' => [ '../../escape' ],
			'nested traversal' => [ 'widgets/../../escape' ],
			'unknown name'     => [ 'unknown' ],
			'upper case'       => [ 'Widgets' ],
			'trailing slash'   => [ 'widgets/' ],
			'empty string'     => [ '' ],
			'absolute path'    => [ '/tmp/escape' ],
			'array'            => [ [ 'widgets' ] ],
			'number'           => [ 7 ],
		];
	}

	/** @dataProvider refused_hubs */
	public function test_an_invalid_hub_is_refused_before_a_path_is_built( mixed $hub ): void {
		$result = $this->refresh( [ 'url' => 'https://elementor.com/help/a', 'body' => "## A\nbody body body\n", 'hub' => $hub ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_invalid_hub', $result->get_error_code() );
		self::assertSame( [], $this->files_under( $this->uploads ) );
	}

	/** @return array<string, array{string}> */
	public static function valid_hubs(): array {
		return [
			'widgets'       => [ 'widgets' ],
			'editor'        => [ 'editor' ],
			'theme'         => [ 'theme' ],
			'developer'     => [ 'developer' ],
			'custom-widget' => [ 'custom-widget' ],
			'help-root'     => [ 'help-root' ],
		];
	}

	/** @dataProvider valid_hubs */
	public function test_every_schema_hub_is_accepted( string $hub ): void {
		$result = $this->refresh_ok( 'https://elementor.com/help/a-page', $hub );

		self::assertSame( $hub, $result['hub'] );
		self::assertFileExists( $this->store_dir() . '/' . $hub . '/a-page.md' );
	}

	public function test_the_hub_list_matches_the_input_schema_enum(): void {
		$enum = ( new KnowledgeRefresh() )->input_schema()['properties']['hub']['enum'];

		self::assertSame( $enum, ElementorKnowledgeStore::HUBS );
	}

	// ---------------------------------------------------------------------
	// Path containment.
	// ---------------------------------------------------------------------

	/** @return array<string, array{string, string}> */
	public static function odd_urls(): array {
		return [
			'dot dot segment'   => [ 'https://elementor.com/help/..', 'untitled' ],
			'dot segment'       => [ 'https://elementor.com/help/.', 'untitled' ],
			'encoded traversal' => [ 'https://elementor.com/help/..%2f..%2fescape', '2f..-2fescape' ],
			'encoded backslash' => [ 'https://elementor.com/help/..%5c..%5cescape', '5c..-5cescape' ],
			'leading dots'      => [ 'https://elementor.com/help/..hidden', 'hidden' ],
			'underscore prefix' => [ 'https://elementor.com/help/_change_log', 'change_log' ],
			'root path'         => [ 'https://elementor.com/', 'untitled' ],
		];
	}

	/** @dataProvider odd_urls */
	public function test_the_slug_is_sanitised_and_the_file_stays_inside_the_hub_folder( string $url, string $expected_slug ): void {
		$result = $this->refresh( [ 'url' => $url, 'hub' => 'help-root', 'body' => "## A\nbody body body\n" ] );
		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() : '' );

		self::assertSame( $expected_slug, $result['slug'] );
		self::assertFileExists( $this->store_dir() . '/help-root/' . $expected_slug . '.md' );
		self::assertSame( [ 'stonewright-private' ], array_values( array_diff( scandir( $this->uploads ) ?: [], [ '.', '..' ] ) ) );
		self::assertSame( [ $expected_slug . '.md' ], array_values( array_filter( scandir( $this->store_dir() . '/help-root' ) ?: [], static fn ( string $n ): bool => str_ends_with( $n, '.md' ) ) ) );
	}

	public function test_file_path_refuses_names_that_could_leave_the_folder(): void {
		foreach ( [ '../x', '..', '.', 'a/b', 'a\\b', '', '.hidden', '_private', "x\0y" ] as $slug ) {
			self::assertNull( ElementorKnowledgeStore::file_path( 'widgets', $slug ), 'Slug: ' . json_encode( $slug ) );
		}
		self::assertNull( ElementorKnowledgeStore::file_path( '../widgets', 'ok' ) );
		self::assertNull( ElementorKnowledgeStore::file_path( 'nope', 'ok' ) );

		$path = ElementorKnowledgeStore::file_path( 'widgets', 'ok' );
		self::assertNotNull( $path );
		self::assertSame( str_replace( '\\', '/', (string) realpath( $this->store_dir() . '/widgets' ) ), str_replace( '\\', '/', (string) realpath( dirname( (string) $path ) ) ) );
		self::assertSame( 'ok.md', basename( (string) $path ) );
	}

	public function test_a_hub_folder_that_is_a_link_leaving_the_store_is_refused(): void {
		$outside = $this->uploads . '-outside';
		mkdir( $outside, 0777, true );
		mkdir( $this->store_dir(), 0777, true );
		if ( ! @symlink( $outside, $this->store_dir() . '/widgets' ) ) {
			$this->remove_tree( $outside );
			self::markTestSkipped( 'Symbolic links cannot be created on this host.' );
		}

		try {
			$result = $this->refresh( [ 'url' => 'https://elementor.com/widgets/a-widget', 'hub' => 'widgets', 'body' => "## A\nbody body body\n" ] );

			self::assertInstanceOf( \WP_Error::class, $result );
			self::assertSame( 'stonewright_store_unavailable', $result->get_error_code() );
			self::assertSame( [], array_values( array_diff( scandir( $outside ) ?: [], [ '.', '..' ] ) ) );
		} finally {
			$this->remove_tree( $outside );
		}
	}

	public function test_a_file_that_is_a_link_is_not_overwritten(): void {
		$outside = $this->uploads . '-target.md';
		file_put_contents( $outside, 'original' );
		mkdir( $this->store_dir() . '/editor', 0777, true );
		if ( ! @symlink( $outside, $this->store_dir() . '/editor/linked.md' ) ) {
			@unlink( $outside );
			self::markTestSkipped( 'Symbolic links cannot be created on this host.' );
		}

		try {
			$result = $this->refresh( [ 'url' => 'https://elementor.com/help/build-with-the-editor/linked', 'body' => "## A\nbody body body\n" ] );

			self::assertInstanceOf( \WP_Error::class, $result );
			self::assertSame( 'original', file_get_contents( $outside ) );
		} finally {
			@unlink( $outside );
		}
	}

	// ---------------------------------------------------------------------
	// Permissions, shape and descriptions.
	// ---------------------------------------------------------------------

	public function test_refresh_still_requires_manage_options(): void {
		$GLOBALS['stonewright_test_user_caps'] = [ 'read' => true ];
		self::assertNotTrue( ( new KnowledgeRefresh() )->permission_callback( [] ) );

		$GLOBALS['stonewright_test_user_caps'] = [ 'manage_options' => true ];
		self::assertTrue( ( new KnowledgeRefresh() )->permission_callback( [] ) );
	}

	public function test_refresh_response_keeps_its_shape(): void {
		$result = $this->refresh_ok( 'https://elementor.com/help/build-with-the-editor/navigator-panel' );

		self::assertSame(
			[ 'ok', 'content_changed', 'status', 'file_path', 'slug', 'hash', 'hub', 'previous_hash', 'fetched_at', 'reason' ],
			array_keys( $result )
		);
	}

	public function test_descriptions_name_the_private_store_and_not_the_old_location(): void {
		foreach ( [ new KnowledgeRefresh(), new KnowledgeSearch(), new ExplainEditor(), new DescribeWidget() ] as $ability ) {
			self::assertStringNotContainsString( 'docs/knowledge', $ability->description() );
		}
		self::assertStringContainsString( 'stonewright-private', ( new KnowledgeRefresh() )->description() );
		self::assertStringContainsString( 'empty until the first', ( new KnowledgeSearch() )->description() );
	}
}
