<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\ElementorWidgets;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\ElementorWidget\CreateCustomWidget;
use Stonewright\WpMcp\Abilities\ElementorWidget\WidgetDefine;
use Stonewright\WpMcp\Abilities\ElementorWidget\WidgetList;
use Stonewright\WpMcp\Abilities\ElementorWidget\WidgetRegister;
use Stonewright\WpMcp\Sandbox\SandboxFiles;
use Stonewright\WpMcp\Security\ChangeLedger;
use Stonewright\WpMcp\Tests\Unit\Security\Fixtures\CodeAdapterHarness;

/**
 * The custom widget builders write through SandboxFiles: every write keeps a backup of the file it
 * replaces, leaves a change-ledger row and stops at the sandbox size cap.
 *
 * @covers \Stonewright\WpMcp\Abilities\ElementorWidget\WidgetDefine
 * @covers \Stonewright\WpMcp\Abilities\ElementorWidget\WidgetRegister
 * @covers \Stonewright\WpMcp\Abilities\ElementorWidget\CreateCustomWidget
 * @covers \Stonewright\WpMcp\Abilities\ElementorWidget\WidgetList
 * @covers \Stonewright\WpMcp\Elementor\WidgetBuilder\Compiler
 * @covers \Stonewright\WpMcp\Sandbox\SandboxFiles
 */
final class WidgetSandboxWritesTest extends TestCase {
	use CodeAdapterHarness;

	private const SLUG = 'sbx-card';

	protected function setUp(): void {
		$this->set_up_harness();
		$GLOBALS['stonewright_test_user_caps'] = [ 'edit_plugins' => true, 'manage_options' => true, 'read' => true ];
		$GLOBALS['stonewright_test_option_autoload'] = [];
		$this->clean_sandbox();
	}

	protected function tearDown(): void {
		$this->clean_sandbox();
		unset( $GLOBALS['stonewright_test_option_autoload'] );
		$this->tear_down_harness();
	}

	private function clean_sandbox(): void {
		foreach ( glob( SandboxFiles::draft_dir() . '/widget-sbx-*' ) ?: [] as $file ) {
			@unlink( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
	}

	/** @return list<array<string, mixed>> Full ledger rows of one resource id, oldest first. */
	private function rows_for( string $resource_id ): array {
		$out = [];
		foreach ( $this->ledger_rows() as $row ) {
			if ( $resource_id === $row['resource_id'] ) {
				$out[] = ChangeLedger::get( (string) $row['change_id'] ) ?? [];
			}
		}
		return $out;
	}

	/** @return array<string, mixed> */
	private function define_args( string $label = 'Sandbox Card', string $slug = self::SLUG ): array {
		return [
			'widget_slug'     => $slug,
			'label'           => $label,
			'category'        => 'stonewright',
			'controls'        => [ [ 'id' => 'title', 'label' => 'Title', 'type' => 'text', 'default' => '' ] ],
			'template'        => '{{ title }}',
			'render_strategy' => 'twig',
		];
	}

	/** @return array<string, mixed> */
	private function create_args( array $extra = [] ): array {
		return array_merge(
			[
				'slug'     => self::SLUG,
				'title'    => 'Sandbox Card',
				'props'    => [ [ 'name' => 'title', 'type' => 'text', 'label' => 'Title', 'default' => '' ] ],
				'template' => '{{ title }}',
			],
			$extra
		);
	}

	// ---- elementor-widget-define -------------------------------------------------------------------------

	public function test_define_leaves_a_ledger_row_and_backs_up_the_file_it_replaces(): void {
		$first = ( new WidgetDefine() )->execute( $this->define_args( 'First Label' ) );
		self::assertIsArray( $first, $first instanceof \WP_Error ? $first->get_error_message() : '' );
		$first_body = (string) file_get_contents( SandboxFiles::stored_path( 'widget-sbx-card.pending.php' ) );

		$second = ( new WidgetDefine() )->execute( $this->define_args( 'Second Label' ) );
		self::assertIsArray( $second );

		$rows = $this->rows_for( 'widget-sbx-card.pending.php' );
		self::assertCount( 2, $rows, 'Each define is a ledger row.' );
		self::assertSame( 'sandbox', $rows[0]['family'] );
		self::assertSame( 'stonewright/elementor-widget-define', $rows[0]['ability'] );
		self::assertSame( $first_body, ChangeLedger::read_image( $rows[1]['change_id'], 'before' ) );

		$versions = SandboxFiles::backup_versions( 'widget-sbx-card.pending.php' );
		self::assertCount( 1, $versions, 'The second define keeps the first pending file.' );
		self::assertSame( $first_body, file_get_contents( $versions[0]['path'] ) );
	}

	public function test_define_stops_at_the_sandbox_size_cap_and_writes_nothing(): void {
		$args             = $this->define_args();
		$args['controls'] = [];
		for ( $i = 0; $i < 2500; ++$i ) {
			$args['controls'][] = [ 'id' => 'field_' . $i, 'label' => str_repeat( 'x', 80 ), 'type' => 'text', 'default' => str_repeat( 'y', 40 ) ];
		}

		$result = ( new WidgetDefine() )->execute( $args );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_sandbox_too_large', $result->get_error_code() );
		self::assertFileDoesNotExist( SandboxFiles::stored_path( 'widget-sbx-card.pending.php' ) );
	}

	public function test_a_pending_widget_is_not_listed_as_a_sandbox_file_nor_activated_as_one(): void {
		( new WidgetDefine() )->execute( $this->define_args() );

		$names = array_column( SandboxFiles::list_files(), 'name' );
		self::assertNotContains( 'widget-sbx-card.pending.php', $names );

		$activated = SandboxFiles::activate( 'widget-sbx-card.pending.php' );
		self::assertInstanceOf( \WP_Error::class, $activated );
		self::assertFileDoesNotExist( SandboxFiles::mu_dir() . '/' . SandboxFiles::active_prefix() . 'widget-sbx-card.pending.php' );
	}

	// ---- elementor-widget-register -----------------------------------------------------------------------

	public function test_register_writes_the_active_file_through_the_ledger_and_removes_the_pending_file(): void {
		( new WidgetDefine() )->execute( $this->define_args() );
		$pending_body = (string) file_get_contents( SandboxFiles::stored_path( 'widget-sbx-card.pending.php' ) );

		$result = ( new WidgetRegister() )->execute( [ 'widget_slug' => self::SLUG ] );

		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() : '' );
		self::assertSame( 'none', $result['previous_status'] );
		self::assertFileDoesNotExist( SandboxFiles::stored_path( 'widget-sbx-card.pending.php' ) );
		self::assertSame( $pending_body, file_get_contents( SandboxFiles::stored_path( 'widget-sbx-card.php' ) ) );

		$rows = $this->rows_for( 'widget-sbx-card.php' );
		self::assertCount( 1, $rows );
		self::assertSame( 'stonewright/elementor-widget-register', $rows[0]['ability'] );
		self::assertTrue( $rows[0]['restorable'], 'The register call created the active file.' );
		self::assertSame( $pending_body, ChangeLedger::read_image( $rows[0]['change_id'], 'after' ) );
		self::assertSame( true, $GLOBALS['stonewright_test_option_autoload']['stonewright_registered_widgets'] );
		self::assertSame( [ self::SLUG ], get_option( 'stonewright_registered_widgets' ) );
	}

	public function test_registering_again_keeps_the_active_file_it_replaces(): void {
		( new WidgetDefine() )->execute( $this->define_args( 'One' ) );
		( new WidgetRegister() )->execute( [ 'widget_slug' => self::SLUG ] );
		$active_v1 = (string) file_get_contents( SandboxFiles::stored_path( 'widget-sbx-card.php' ) );
		( new WidgetDefine() )->execute( $this->define_args( 'Two' ) );

		$result = ( new WidgetRegister() )->execute( [ 'widget_slug' => self::SLUG ] );

		self::assertIsArray( $result );
		self::assertSame( 'active', $result['previous_status'] );
		$versions = SandboxFiles::backup_versions( 'widget-sbx-card.php' );
		self::assertCount( 1, $versions );
		self::assertSame( $active_v1, file_get_contents( $versions[0]['path'] ) );
		self::assertSame( [ self::SLUG ], get_option( 'stonewright_registered_widgets' ), 'The slug is listed once.' );
	}

	public function test_register_without_a_pending_file_is_refused(): void {
		$result = ( new WidgetRegister() )->execute( [ 'widget_slug' => self::SLUG ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_widget_pending_not_found', $result->get_error_code() );
		self::assertSame( [], $this->rows_for( 'widget-sbx-card.php' ) );
	}

	// ---- elementor-create-custom-widget ------------------------------------------------------------------

	public function test_create_stages_the_pending_file_through_the_ledger_with_the_real_class_name(): void {
		$result = ( new CreateCustomWidget() )->execute( $this->create_args( [ 'slug' => 'sbx-price_card' ] ) );

		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() : '' );
		self::assertFalse( $result['registered'] );
		$source = (string) file_get_contents( SandboxFiles::stored_path( 'widget-sbx-price_card.pending.php' ) );
		self::assertStringContainsString( 'final class Stonewright_Widget_Sbx_Price_Card ', $source );
		self::assertStringContainsString( 'namespace Stonewright\\GeneratedWidgets {', $source );
		self::assertSame( 'Stonewright\\GeneratedWidgets\\Stonewright_Widget_Sbx_Price_Card', $result['class_name'], 'The name PHP resolves, not a made-up one.' );

		$rows = $this->rows_for( 'widget-sbx-price_card.pending.php' );
		self::assertCount( 1, $rows );
		self::assertSame( 'stonewright/elementor-create-custom-widget', $rows[0]['ability'] );
		self::assertSame( [], get_option( 'stonewright_registered_widgets', [] ) );
	}

	public function test_create_with_activate_writes_the_active_file_and_stores_the_option_without_autoload(): void {
		$result = ( new CreateCustomWidget() )->execute( $this->create_args( [ 'activate' => true ] ) );

		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() : '' );
		self::assertTrue( $result['registered'] );
		self::assertFileExists( SandboxFiles::stored_path( 'widget-sbx-card.php' ) );
		self::assertCount( 1, $this->rows_for( 'widget-sbx-card.php' ) );
		self::assertSame( [ self::SLUG ], get_option( 'stonewright_registered_widgets' ) );
		self::assertSame( true, $GLOBALS['stonewright_test_option_autoload']['stonewright_registered_widgets'] );
	}

	public function test_create_keeps_the_file_it_replaces_and_stops_at_the_size_cap(): void {
		( new CreateCustomWidget() )->execute( $this->create_args( [ 'activate' => true ] ) );
		$first = (string) file_get_contents( SandboxFiles::stored_path( 'widget-sbx-card.php' ) );

		( new CreateCustomWidget() )->execute( $this->create_args( [ 'activate' => true, 'title' => 'Renamed Card' ] ) );
		$versions = SandboxFiles::backup_versions( 'widget-sbx-card.php' );
		self::assertCount( 1, $versions );
		self::assertSame( $first, file_get_contents( $versions[0]['path'] ) );

		$big = $this->create_args( [ 'activate' => true ] );
		$big['props'] = [];
		for ( $i = 0; $i < 2500; ++$i ) {
			$big['props'][] = [ 'name' => 'field_' . $i, 'type' => 'text', 'label' => str_repeat( 'x', 80 ), 'default' => str_repeat( 'y', 40 ) ];
		}
		$result = ( new CreateCustomWidget() )->execute( $big );
		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_sandbox_too_large', $result->get_error_code() );
		self::assertStringContainsString( 'Renamed Card', (string) file_get_contents( SandboxFiles::stored_path( 'widget-sbx-card.php' ) ), 'The refused write left the widget as it was.' );
	}

	public function test_create_passes_icon_and_keywords_into_the_widget(): void {
		$result = ( new CreateCustomWidget() )->execute( $this->create_args( [ 'icon' => 'eicon-price-list', 'keywords' => [ 'pricing', "owner's plan" ] ] ) );

		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() : '' );
		$source = (string) file_get_contents( SandboxFiles::stored_path( 'widget-sbx-card.pending.php' ) );
		self::assertStringContainsString( "return 'eicon-price-list';", $source );
		self::assertStringContainsString( 'public function get_keywords(): array', $source );
		self::assertStringContainsString( "'pricing'", $source );
		self::assertStringContainsString( "'owner\\'s plan'", $source );
	}

	public function test_create_without_icon_and_keywords_keeps_the_default_icon(): void {
		( new CreateCustomWidget() )->execute( $this->create_args() );

		$source = (string) file_get_contents( SandboxFiles::stored_path( 'widget-sbx-card.pending.php' ) );
		self::assertStringContainsString( "return 'eicon-code';", $source );
		self::assertStringNotContainsString( 'get_keywords', $source );
	}

	public function test_create_refuses_an_icon_that_is_not_a_css_class_name(): void {
		$result = ( new CreateCustomWidget() )->execute( $this->create_args( [ 'icon' => "x'; phpinfo(); //" ] ) );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_compiler_invalid_icon', $result->get_error_code() );
		self::assertFileDoesNotExist( SandboxFiles::stored_path( 'widget-sbx-card.pending.php' ) );
	}

	// ---- descriptions ------------------------------------------------------------------------------------

	public function test_descriptions_name_the_stored_files_and_the_register_ability_correctly(): void {
		$texts = [
			( new WidgetDefine() )->description(),
			( new WidgetRegister() )->description(),
			( new WidgetList() )->description(),
			( new CreateCustomWidget() )->description(),
			(string) ( new CreateCustomWidget() )->input_schema()['properties']['activate']['description'],
		];
		foreach ( $texts as $text ) {
			self::assertStringNotContainsString( '.pending.php', $text );
			self::assertStringNotContainsString( 'widget_register', $text );
			self::assertStringNotContainsString( 'widget_define', $text );
		}
		self::assertStringContainsString( '.pending.draft', ( new WidgetDefine() )->description() );
		self::assertStringContainsString( 'stonewright/elementor-widget-register', ( new WidgetDefine() )->description() );
		self::assertStringContainsString( '.pending.draft', ( new WidgetList() )->description() );
	}
}
