<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\ElementorWidgets;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\ElementorWidget\CreateCustomWidget;
use Stonewright\WpMcp\Abilities\ElementorWidget\WidgetDefine;
use Stonewright\WpMcp\Abilities\ElementorWidget\WidgetRegister;
use Stonewright\WpMcp\Core\RestRoutes;
use Stonewright\WpMcp\Sandbox\SandboxFiles;
use Stonewright\WpMcp\Security\AuditLog;
use Stonewright\WpMcp\Security\IncidentStore;

/**
 * One call of a widget ability writes one audit row, named after that ability.
 *
 * The ability kernel records every call, whatever the outcome. The widget
 * abilities must not write a second row of their own for the same call, on
 * success or when the source guard rejects the compiled or staged source. The
 * single row still has to tell an administrator which widget was involved and,
 * for a rejection, that the source guard refused it.
 *
 * @covers \Stonewright\WpMcp\Abilities\ElementorWidget\WidgetDefine
 * @covers \Stonewright\WpMcp\Abilities\ElementorWidget\WidgetRegister
 * @covers \Stonewright\WpMcp\Abilities\ElementorWidget\CreateCustomWidget
 */
final class WidgetAuditRowTest extends TestCase {

	private const DEFINE   = 'stonewright/elementor-widget-define';
	private const REGISTER = 'stonewright/elementor-widget-register';
	private const CREATE   = 'stonewright/elementor-create-custom-widget';

	protected function setUp(): void {
		$GLOBALS['stonewright_test_options']         = [ 'stonewright_mode' => 'development' ];
		$GLOBALS['stonewright_test_user_caps']       = [
			'edit_plugins'   => true,
			'manage_options' => true,
		];
		$GLOBALS['stonewright_test_current_user_id'] = 42;
		$GLOBALS['stonewright_test_wpdb_inserts']    = [];
		AuditLog::reset_request_state();
		IncidentStore::reset_for_tests();
		$this->remove_staged_widgets();
	}

	protected function tearDown(): void {
		$this->remove_staged_widgets();
		$GLOBALS['stonewright_test_options']         = [];
		$GLOBALS['stonewright_test_user_caps']       = [];
		$GLOBALS['stonewright_test_current_user_id'] = 0;
		$GLOBALS['stonewright_test_wpdb_inserts']    = [];
		AuditLog::reset_request_state();
		IncidentStore::reset_for_tests();
	}

	// -------------------------------------------------------------------------
	// widget-define
	// -------------------------------------------------------------------------

	public function test_define_writes_exactly_one_row_for_a_successful_call(): void {
		$result = ( new WidgetDefine() )->execute( $this->define_args( 'audit-define' ) );

		self::assertIsArray( $result );
		self::assertTrue( $result['ok'] );
		self::assertFileExists( SandboxFiles::draft_dir() . '/widget-audit-define.pending.php' );

		$rows = $this->rows_for( self::DEFINE );
		self::assertCount( 1, $rows );
		self::assertSame( 'ok', $rows[0]['result_status'] );
		self::assertSame( 'audit-define', $this->recorded_args( $rows[0] )['widget_slug'] ?? null );
	}

	public function test_define_writes_exactly_one_row_when_the_source_guard_rejects(): void {
		$args          = $this->define_args( 'audit-define-guard' );
		$args['label'] = $this->guard_trigger_label();

		$result = ( new WidgetDefine() )->execute( $args );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_static_guard_rejected', $result->get_error_code() );
		self::assertFileDoesNotExist( SandboxFiles::draft_dir() . '/widget-audit-define-guard.pending.php' );

		$rows = $this->rows_for( self::DEFINE );
		self::assertCount( 1, $rows );
		self::assertSame( 'error', $rows[0]['result_status'] );
		self::assertSame( 'stonewright_static_guard_rejected', $rows[0]['error_code'] );
		self::assertSame( 'audit-define-guard', $this->recorded_args( $rows[0] )['widget_slug'] ?? null );
	}

	// -------------------------------------------------------------------------
	// widget-register
	// -------------------------------------------------------------------------

	public function test_register_writes_exactly_one_row_for_a_successful_call(): void {
		$this->stage_widget( 'audit-register' );

		$result = ( new WidgetRegister() )->execute( [ 'widget_slug' => 'audit-register' ] );

		self::assertIsArray( $result );
		self::assertTrue( $result['ok'] );
		self::assertFileExists( SandboxFiles::draft_dir() . '/widget-audit-register.php' );

		$rows = $this->rows_for( self::REGISTER );
		self::assertCount( 1, $rows );
		self::assertSame( 'ok', $rows[0]['result_status'] );
		self::assertSame( 'audit-register', $this->recorded_args( $rows[0] )['widget_slug'] ?? null );
	}

	public function test_one_rest_register_call_writes_exactly_one_row(): void {
		$this->stage_widget( 'audit-register-rest' );

		AuditLog::begin_request();
		$result = ( new WidgetRegister() )->execute( [ 'widget_slug' => 'audit-register-rest' ] );
		RestRoutes::audit_post_dispatch(
			rest_ensure_response( [ 'name' => self::REGISTER, 'result' => $result ] ),
			null,
			new \WP_REST_Request( 'POST', '/stonewright/v1/abilities/run', [ 'name' => self::REGISTER ] )
		);

		$audit_rows = array_values(
			array_filter(
				$GLOBALS['stonewright_test_wpdb_inserts'],
				static fn ( array $insert ): bool => str_contains( (string) $insert['table'], 'stonewright_audit_log' )
			)
		);
		self::assertCount( 1, $audit_rows );
		self::assertSame( self::REGISTER, $audit_rows[0]['data']['ability_name'] );
	}

	public function test_register_writes_exactly_one_row_when_the_source_guard_rejects(): void {
		file_put_contents(
			SandboxFiles::draft_dir() . '/widget-audit-register-guard.pending.php',
			"<?php\n" . 'sys' . 'tem( $command );' . "\n"
		);

		$result = ( new WidgetRegister() )->execute( [ 'widget_slug' => 'audit-register-guard' ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_static_guard_rejected', $result->get_error_code() );
		self::assertFileDoesNotExist( SandboxFiles::draft_dir() . '/widget-audit-register-guard.php' );

		$rows = $this->rows_for( self::REGISTER );
		self::assertCount( 1, $rows );
		self::assertSame( 'error', $rows[0]['result_status'] );
		self::assertSame( 'stonewright_static_guard_rejected', $rows[0]['error_code'] );
		self::assertSame( 'audit-register-guard', $this->recorded_args( $rows[0] )['widget_slug'] ?? null );
	}

	// -------------------------------------------------------------------------
	// elementor-create-custom-widget
	// -------------------------------------------------------------------------

	/**
	 * @dataProvider activation_provider
	 */
	public function test_create_custom_widget_writes_exactly_one_row_for_a_successful_call( bool $activate, string $file ): void {
		$args             = $this->create_args( 'audit-create' );
		$args['activate'] = $activate;

		$result = ( new CreateCustomWidget() )->execute( $args );

		self::assertIsArray( $result );
		self::assertTrue( $result['ok'] );
		self::assertSame( $activate, $result['registered'] );
		self::assertFileExists( SandboxFiles::draft_dir() . '/' . $file );

		$rows = $this->rows_for( self::CREATE );
		self::assertCount( 1, $rows );
		self::assertSame( 'ok', $rows[0]['result_status'] );
		self::assertSame( 'audit-create', $this->recorded_args( $rows[0] )['slug'] ?? null );
	}

	/** @return array<string, array{bool, string}> */
	public static function activation_provider(): array {
		return [
			'staged for review' => [ false, 'widget-audit-create.pending.php' ],
			'activated'         => [ true, 'widget-audit-create.php' ],
		];
	}

	public function test_create_custom_widget_writes_exactly_one_row_when_the_source_guard_rejects(): void {
		$args          = $this->create_args( 'audit-create-guard' );
		$args['title'] = $this->guard_trigger_label();

		$result = ( new CreateCustomWidget() )->execute( $args );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_static_guard_rejected', $result->get_error_code() );
		self::assertFileDoesNotExist( SandboxFiles::draft_dir() . '/widget-audit-create-guard.pending.php' );

		$rows = $this->rows_for( self::CREATE );
		self::assertCount( 1, $rows );
		self::assertSame( 'error', $rows[0]['result_status'] );
		self::assertSame( 'stonewright_static_guard_rejected', $rows[0]['error_code'] );
		self::assertSame( 'audit-create-guard', $this->recorded_args( $rows[0] )['slug'] ?? null );
	}

	// -------------------------------------------------------------------------
	// Fixtures
	// -------------------------------------------------------------------------

	/**
	 * A widget label the compiler passes through untouched but the source guard
	 * refuses: a short-echo tag inside the generated source.
	 */
	private function guard_trigger_label(): string {
		return 'Guard ' . '<?' . '= trigger';
	}

	/** @return array<string, mixed> */
	private function define_args( string $slug ): array {
		return [
			'widget_slug'     => $slug,
			'label'           => 'Audit Widget',
			'category'        => 'stonewright',
			'controls'        => [
				[
					'id'      => 'title',
					'label'   => 'Title',
					'type'    => 'text',
					'default' => '',
				],
			],
			'template'        => '{{ title }}',
			'render_strategy' => 'twig',
		];
	}

	/** @return array<string, mixed> */
	private function create_args( string $slug ): array {
		return [
			'slug'     => $slug,
			'title'    => 'Audit Widget',
			'props'    => [
				[
					'name'    => 'title',
					'type'    => 'text',
					'label'   => 'Title',
					'default' => '',
				],
			],
			'template' => '{{ title }}',
		];
	}

	/**
	 * Stage a pending widget file, then forget the rows that staging wrote so a
	 * test only sees the rows of the call under test.
	 */
	private function stage_widget( string $slug ): void {
		$staged = ( new WidgetDefine() )->execute( $this->define_args( $slug ) );
		self::assertIsArray( $staged );
		self::assertFileExists( SandboxFiles::draft_dir() . '/widget-' . $slug . '.pending.php' );

		$GLOBALS['stonewright_test_wpdb_inserts'] = [];
		AuditLog::reset_request_state();
	}

	private function remove_staged_widgets(): void {
		foreach ( glob( SandboxFiles::draft_dir() . '/widget-audit-*.php' ) ?: [] as $file ) {
			@unlink( $file );
		}
	}

	/**
	 * Audit-log rows the wpdb double captured for one ability.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function rows_for( string $ability ): array {
		$rows = [];
		foreach ( $GLOBALS['stonewright_test_wpdb_inserts'] as $insert ) {
			if ( str_contains( (string) $insert['table'], 'stonewright_audit_log' ) && $ability === ( $insert['data']['ability_name'] ?? '' ) ) {
				$rows[] = $insert['data'];
			}
		}
		return $rows;
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	private function recorded_args( array $row ): array {
		$decoded = json_decode( (string) ( $row['sanitized_args'] ?? '' ), true );
		return is_array( $decoded ) ? $decoded : [];
	}
}
