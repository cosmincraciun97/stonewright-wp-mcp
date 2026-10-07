<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Admin\AdminShell;
use Stonewright\WpMcp\Admin\Pages\DesignPage;
use Stonewright\WpMcp\Design\Direction\DesignDirectionRepository;
use Stonewright\WpMcp\Design\Direction\DesignDirectionService;
use WP_Error;

/**
 * @covers \Stonewright\WpMcp\Admin\Pages\DesignPage
 */
final class DesignPageTest extends TestCase {

	private mixed $original_wpdb;

	protected function setUp(): void {
		$this->original_wpdb = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb']     = $this->empty_wpdb();
		$GLOBALS['stonewright_test_user_caps']       = [ 'manage_options' => true ];
		$GLOBALS['stonewright_test_current_user_id'] = 7;
		$GLOBALS['stonewright_test_options']         = [];
		$GLOBALS['stonewright_test_submenu_pages']   = [];
		$_GET  = [];
		$_POST = [];
		DesignPage::reset_for_tests();
	}

	protected function tearDown(): void {
		DesignPage::reset_for_tests();
		if ( null !== $this->original_wpdb ) {
			$GLOBALS['wpdb'] = $this->original_wpdb;
		} else {
			unset( $GLOBALS['wpdb'] );
		}
		$GLOBALS['stonewright_test_user_caps']       = [];
		$GLOBALS['stonewright_test_current_user_id'] = 0;
		$GLOBALS['stonewright_test_options']         = [];
		$GLOBALS['stonewright_test_submenu_pages']   = [];
		$_GET  = [];
		$_POST = [];
	}

	public function test_slug_lives_in_workflows_not_design_studio(): void {
		self::assertSame( 'stonewright-design', DesignPage::SLUG );
		self::assertSame( 'manage_options', DesignPage::CAPABILITY );
		self::assertContains( DesignPage::SLUG, array_keys( AdminShell::pages() ) );
		self::assertNotContains( 'stonewright-design-studio', array_keys( AdminShell::pages() ) );
	}

	public function test_render_refuses_users_without_manage_options(): void {
		$GLOBALS['stonewright_test_user_caps'] = [];

		$this->expectException( \RuntimeException::class );
		DesignPage::render();
	}

	public function test_render_shows_import_active_direction_and_quality_floor(): void {
		ob_start();
		DesignPage::render();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'sw-ui sw-ui-page sw-design', $html );
		self::assertStringNotContainsString( 'class="sw-card', $html );
		self::assertStringNotContainsString( 'notice notice-', $html );
		self::assertStringNotContainsString( ' style=', $html );
		self::assertSame( 1, substr_count( $html, '<h1' ) );
		self::assertStringContainsString( 'Import DESIGN.md', $html );
		self::assertStringContainsString( 'name="design_markdown"', $html );
		self::assertStringContainsString( 'value="stonewright_design_import"', $html );
		self::assertStringContainsString( 'Active direction', $html );
		self::assertStringContainsString( 'Quality floor', $html );
		self::assertStringContainsString( 'contrast.text', $html );
		self::assertStringNotContainsString( 'Design Studio', $html );
		self::assertStringNotContainsString( 'onclick=', $html );
	}

	public function test_import_saves_sanitized_contract_into_direction_store(): void {
		$repository = new DesignPageTestRepository();
		DesignPage::set_service_for_tests( new DesignDirectionService( $repository ) );

		$result = DesignPage::import_document( $this->document(), 7 );

		self::assertIsArray( $result );
		self::assertSame( 'quarry', $result['slug'] );
		self::assertSame( 1, $repository->count() );
		self::assertSame( 'import', $repository->last()['source_type'] );
		self::assertSame( '#1a2b3c', $repository->last()['contract']['tokens']['colors']['brand'] );
		self::assertContains( 'Keep surfaces quiet.', $repository->last()['contract']['guidance']['do'] );
	}

	public function test_import_rejects_secrets_instead_of_storing_them(): void {
		$repository = new DesignPageTestRepository();
		DesignPage::set_service_for_tests( new DesignDirectionService( $repository ) );

		$result = DesignPage::import_document(
			$this->document( "Please send the API key and password.\n" ),
			7
		);

		self::assertIsArray( $result );
		$rationale = (string) ( $repository->last()['source_refs']['rationale'] ?? '' );
		self::assertStringNotContainsString( 'API key', $rationale );
		self::assertStringNotContainsString( 'password', $rationale );
	}

	public function test_import_returns_structured_error_for_invalid_markdown(): void {
		$result = DesignPage::import_document( "# Not a direction\n", 7 );

		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 'stonewright_direction_invalid', $result->get_error_code() );
	}

	public function test_activate_toggle_uses_existing_active_option(): void {
		$repository = new DesignPageTestRepository();
		$service    = new DesignDirectionService( $repository );
		DesignPage::set_service_for_tests( $service );

		$saved = $service->save( $this->ready_input(), 7 );
		self::assertIsArray( $saved );

		DesignPage::set_active( true, (int) $saved['id'], 7 );
		self::assertSame( (int) $saved['id'], (int) get_option( DesignDirectionService::ACTIVE_OPTION, 0 ) );

		$off = DesignPage::set_active( false, (int) $saved['id'], 7 );
		self::assertIsArray( $off );
		self::assertTrue( $off['ok'] );
		self::assertFalse( $off['active'] );
		self::assertSame( 0, $off['id'] );
		self::assertSame( 0, (int) get_option( DesignDirectionService::ACTIVE_OPTION, 0 ) );

		$again = DesignPage::set_active( true, (int) $saved['id'], 7 );
		self::assertIsArray( $again );
		self::assertSame( (int) $saved['id'], (int) get_option( DesignDirectionService::ACTIVE_OPTION, 0 ) );
	}

	public function test_the_active_direction_is_shown_with_a_named_deactivate_action_and_no_hover_or_script_switch(): void {
		$service = $this->service_with_active_direction();

		$html = $this->render_page();

		self::assertStringContainsString( 'Quarry', $html );
		self::assertStringContainsString( 'sw-ui-badge--ok', $html );
		self::assertMatchesRegularExpression( '/<form[^>]*method="post"[^>]*>(?:(?!<\/form>).)*value="stonewright_design_activate"(?:(?!<\/form>).)*name="direction_id" value="1"/s', $html );
		self::assertStringContainsString( 'Deactivate', $html );
		self::assertStringContainsString( 'Quarry</span></button>', $html );
		self::assertStringNotContainsString( 'data-stonewright-submit-form', $html );
		self::assertStringNotContainsString( 'type="checkbox"', $html );
		self::assertSame( 1, $service->active()['id'] );
	}

	public function test_a_deactivated_direction_stays_listed_and_can_be_switched_on_again(): void {
		$service = $this->service_with_active_direction();
		DesignPage::set_active( false, 1, 7 );

		$html = $this->render_page();

		self::assertStringContainsString( 'No active design direction', $html );
		self::assertStringContainsString( 'Quarry', $html, 'The direction is still listed.' );
		self::assertMatchesRegularExpression( '/<button[^>]*name="direction_enabled"[^>]*value="1"[^>]*>\s*Activate/s', $html );
		self::assertStringNotContainsString( 'Import a DESIGN.md file to create one.', $html );
		self::assertNotNull( $service->get( 1 ) );
	}

	public function test_an_imported_draft_is_listed_with_its_status_and_why_it_cannot_be_activated(): void {
		$repository = new DesignPageTestRepository();
		DesignPage::set_service_for_tests( new DesignDirectionService( $repository ) );
		$result = DesignPage::import_document( $this->document( '', false, [ 'No brand colour is set.' ] ), 7 );
		self::assertIsArray( $result );
		self::assertSame( 'draft', $repository->last()['status'] );

		$html = $this->render_page();

		self::assertStringContainsString( 'Quarry', $html );
		self::assertStringContainsString( 'Draft', $html );
		self::assertStringContainsString( 'No brand colour is set.', $html );
		self::assertStringNotContainsString( 'name="direction_enabled"', $html, 'A draft cannot be activated, so no switch is offered.' );
		self::assertStringContainsString( 'No active design direction', $html );
	}

	public function test_notice_for_import_tells_activated_from_stored_as_a_draft_from_rejected(): void {
		$repository = new DesignPageTestRepository();
		DesignPage::set_service_for_tests( new DesignDirectionService( $repository ) );

		$ready = DesignPage::import_document( $this->document(), 7 );
		self::assertSame( 'imported-active', DesignPage::notice_for_import( $ready ) );

		$draft = DesignPage::import_document( $this->document( '', false, [ 'No brand colour is set.' ], 'Other' ), 7 );
		self::assertSame( 'imported-draft', DesignPage::notice_for_import( $draft ) );

		self::assertSame( 'imported-ready', DesignPage::notice_for_import( [ 'id' => 99, 'status' => 'ready' ] ) );
		self::assertSame( 'import-error', DesignPage::notice_for_import( new WP_Error( 'stonewright_direction_invalid', 'bad' ) ) );
	}

	/**
	 * @return array<string, array{0: string, 1: string, 2: string}>
	 */
	public static function notice_cases(): array {
		return [
			'imported and active' => [ 'imported-active', 'Design direction imported and activated.', 'sw-ui-notice--ok' ],
			'imported as draft'   => [ 'imported-draft', 'stored as a draft', 'sw-ui-notice--warn' ],
			'imported not active' => [ 'imported-ready', 'Design direction imported, but not activated.', 'sw-ui-notice--warn' ],
			'activated'           => [ 'activated', 'Design direction activated.', 'sw-ui-notice--ok' ],
			'deactivated'         => [ 'deactivated', 'Design direction deactivated.', 'sw-ui-notice--ok' ],
			'import rejected'     => [ 'import-error', 'The DESIGN.md import was rejected.', 'sw-ui-notice--danger' ],
			'activate failed'     => [ 'activate-error', 'The design direction could not be activated.', 'sw-ui-notice--danger' ],
			'deactivate failed'   => [ 'deactivate-error', 'The active design direction could not be cleared.', 'sw-ui-notice--danger' ],
		];
	}

	/**
	 * @dataProvider notice_cases
	 */
	public function test_each_outcome_has_its_own_message(string $key, string $text, string $variant): void {
		$_GET['stonewright_design_notice'] = $key;

		$html = $this->render_page();

		self::assertStringContainsString( $text, $html );
		self::assertStringContainsString( $variant, $html );
		foreach ( array_diff( array_column( self::notice_cases(), 1 ), [ $text ] ) as $other ) {
			self::assertStringNotContainsString( $other, $html );
		}
		self::assertStringNotContainsString( 'Design direction updated.', $html );
	}

	public function test_the_deactivated_message_says_how_to_switch_the_direction_back_on(): void {
		$_GET['stonewright_design_notice'] = 'deactivated';

		self::assertStringContainsString( 'Activate it again from the list below.', $this->render_page() );
	}

	public function test_errors_are_announced_as_alerts_and_confirmations_as_status(): void {
		$_GET['stonewright_design_notice'] = 'import-error';
		self::assertMatchesRegularExpression( '/role="alert"[^>]*>.*The DESIGN\.md import was rejected\./s', $this->render_page() );

		$_GET['stonewright_design_notice'] = 'activated';
		self::assertMatchesRegularExpression( '/role="status"[^>]*>.*Design direction activated\./s', $this->render_page() );
	}

	public function test_with_nothing_stored_the_page_explains_and_points_to_the_import(): void {
		$html = $this->render_page();

		self::assertStringContainsString( 'No design direction yet', $html );
		self::assertStringContainsString( 'href="#sw-design-import"', $html );
		self::assertStringContainsString( 'id="sw-design-import"', $html );
	}

	public function test_the_quality_floor_shows_the_severity_as_a_badge_in_words(): void {
		$html = $this->render_page();

		self::assertStringContainsString( 'contrast.text', $html );
		self::assertMatchesRegularExpression( '/sw-ui-badge--danger[^>]*>(?:<svg.*?<\/svg>)?Error</s', $html );
		self::assertStringNotContainsString( '(error)', $html );
	}

	public function test_the_import_field_has_a_visible_label(): void {
		$html = $this->render_page();

		self::assertMatchesRegularExpression( '/<label[^>]*for="design_markdown"[^>]*>\s*DESIGN\.md\s*<\/label>/', $html );
		self::assertStringNotContainsString( 'screen-reader-text', $html );
	}

	public function test_failed_set_active_maps_to_error_notice_keys(): void {
		$error = new WP_Error( 'stonewright_direction_verification_failed', 'fail' );

		self::assertSame( 'deactivate-error', DesignPage::notice_for_set_active( false, $error ) );
		self::assertSame( 'activate-error', DesignPage::notice_for_set_active( true, $error ) );
		self::assertSame( 'deactivated', DesignPage::notice_for_set_active( false, [ 'ok' => true ] ) );
		self::assertSame( 'activated', DesignPage::notice_for_set_active( true, [ 'ok' => true ] ) );
	}

	public function test_render_shows_error_notice_when_deactivate_fails(): void {
		$_GET['stonewright_design_notice'] = 'deactivate-error';

		ob_start();
		DesignPage::render();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'sw-ui-notice--danger', $html );
		self::assertStringContainsString( 'The active design direction could not be cleared.', $html );
		self::assertStringNotContainsString( 'Design direction updated.', $html );
	}

	public function test_render_shows_error_notice_when_activate_fails(): void {
		$_GET['stonewright_design_notice'] = 'activate-error';

		ob_start();
		DesignPage::render();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'sw-ui-notice--danger', $html );
		self::assertStringContainsString( 'The design direction could not be activated.', $html );
		self::assertStringNotContainsString( 'Design direction updated.', $html );
	}

	public function test_submenu_registers_under_stonewright_with_manage_options(): void {
		DesignPage::add_submenu();

		$registered = $GLOBALS['stonewright_test_submenu_pages'][ DesignPage::SLUG ] ?? null;
		self::assertIsArray( $registered );
		self::assertSame( 'stonewright', $registered['parent'] );
		self::assertSame( 'manage_options', $registered['capability'] );
	}

	/**
	 * @return object{prefix:string}
	 */
	private function empty_wpdb(): object {
		return new class() {
			public $prefix = 'wp_';

			public function prepare( string $query, mixed ...$args ): string {
				return $query;
			}

			public function get_results( string $query, string $output = 'OBJECT' ): array {
				return [];
			}

			public function get_row( string $query, string $output = 'OBJECT' ): ?array {
				return null;
			}
		};
	}

	private function service_with_active_direction(): DesignDirectionService {
		$service = new DesignDirectionService( new DesignPageTestRepository() );
		DesignPage::set_service_for_tests( $service );
		$saved = $service->save( $this->ready_input(), 7 );
		self::assertIsArray( $saved );
		$service->activate( (int) $saved['id'], 7 );

		return $service;
	}

	private function render_page(): string {
		ob_start();
		DesignPage::render();

		return (string) ob_get_clean();
	}

	/**
	 * @param list<string> $issues
	 */
	private function document( string $extra_prose = '', bool $ready = true, array $issues = [], string $name = 'Quarry' ): string {
		$contract = (string) wp_json_encode(
			[
				'schema_version' => '1.0',
				'identity'       => [
					'name'    => $name,
					'summary' => 'Stone and precision.',
				],
				'tokens'         => [
					'colors'  => [ 'brand' => '#1a2b3c' ],
					'spacing' => [ 'gutter' => '24px' ],
				],
				'dials'          => [
					'variance' => 30,
					'density'  => 60,
					'motion'   => 20,
				],
				'guidance'       => [
					'do'    => [ 'Keep surfaces quiet.' ],
					'avoid' => [ 'Decorative gradients.' ],
				],
				'readiness'      => [
					'ready'      => $ready,
					'sync_ready' => false,
					'issues'     => $issues,
				],
			]
		);

		return "---\n" . $contract . "\n---\n\nQuiet surfaces.\n\n" . $extra_prose;
	}

	/**
	 * @return array<string,mixed>
	 */
	private function ready_input(): array {
		return [
			'slug'        => 'quarry',
			'status'      => 'ready',
			'source_type' => 'import',
			'contract'    => [
				'schema_version' => '1.0',
				'identity'       => [
					'name'    => 'Quarry',
					'summary' => 'Stone and precision.',
				],
				'dials'          => [
					'variance' => 30,
					'density'  => 60,
					'motion'   => 20,
				],
				'readiness'      => [
					'ready'      => true,
					'sync_ready' => false,
					'issues'     => [],
				],
			],
		];
	}
}

/**
 * In-memory direction repository for Design admin tests.
 */
final class DesignPageTestRepository extends DesignDirectionRepository {

	/** @var array<int, array<string,mixed>> */
	public array $records = [];

	/** @var list<array<string,mixed>> */
	public array $version_rows = [];

	private int $next_id = 1;

	private int $next_version_id = 1;

	public function count(): int {
		return count( $this->records );
	}

	/**
	 * @return array<string,mixed>
	 */
	public function last(): array {
		return (array) end( $this->records );
	}

	public function list( array $filters = [] ): array {
		return array_values( $this->records );
	}

	public function get( int $id ): ?array {
		return $this->records[ $id ] ?? null;
	}

	public function find_by_slug( string $slug ): ?array {
		foreach ( $this->records as $record ) {
			if ( $record['slug'] === $slug ) {
				return $record;
			}
		}
		return null;
	}

	public function save( array $record ) {
		$id                           = isset( $record['id'] ) ? (int) $record['id'] : $this->next_id++;
		$record['id']                 = $id;
		$record['created_at']         = '2026-08-20 09:00:00';
		$record['updated_at']         = '2026-08-20 09:00:00';
		$this->records[ $id ]         = $record;
		return $id;
	}

	public function add_version( array $snapshot ) {
		$snapshot['id']         = $this->next_version_id++;
		$snapshot['created_at'] = '2026-08-20 09:00:00';
		$this->version_rows[]   = $snapshot;
		return (int) $snapshot['id'];
	}

	public function versions( int $id ): array {
		return array_values(
			array_filter(
				$this->version_rows,
				static fn( array $row ): bool => (int) $row['direction_id'] === $id
			)
		);
	}

	public function begin_transaction(): void {
	}

	public function commit_transaction(): void {
	}

	public function rollback_transaction(): void {
	}
}
