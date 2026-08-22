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

		self::assertStringContainsString( 'sw-design-page', $html );
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

	public function test_active_form_wires_checkbox_to_stable_form_id(): void {
		$repository = new DesignPageTestRepository();
		$service    = new DesignDirectionService( $repository );
		DesignPage::set_service_for_tests( $service );

		$saved = $service->save( $this->ready_input(), 7 );
		self::assertIsArray( $saved );
		$service->activate( (int) $saved['id'], 7 );

		ob_start();
		DesignPage::render();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'id="stonewright-design-active-form"', $html );
		self::assertStringContainsString( 'data-stonewright-submit-form="stonewright-design-active-form"', $html );
		self::assertStringContainsString( 'checked="checked"', $html );

		DesignPage::set_active( false, (int) $saved['id'], 7 );

		ob_start();
		DesignPage::render();
		$cleared = (string) ob_get_clean();

		self::assertStringNotContainsString( 'id="stonewright-design-active-form"', $cleared );
		self::assertStringNotContainsString( 'checked="checked"', $cleared );
		self::assertStringContainsString( 'No active design direction', $cleared );
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

		self::assertStringContainsString( 'notice-error', $html );
		self::assertStringContainsString( 'The active design direction could not be cleared.', $html );
		self::assertStringNotContainsString( 'Design direction updated.', $html );
	}

	public function test_render_shows_error_notice_when_activate_fails(): void {
		$_GET['stonewright_design_notice'] = 'activate-error';

		ob_start();
		DesignPage::render();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'notice-error', $html );
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
			public string $prefix = 'wp_';

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

	private function document( string $extra_prose = '' ): string {
		$contract = (string) wp_json_encode(
			[
				'schema_version' => '1.0',
				'identity'       => [
					'name'    => 'Quarry',
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
					'ready'      => true,
					'sync_ready' => false,
					'issues'     => [],
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
