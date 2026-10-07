<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\ElementorV4;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\ElementorV4\AtomicWidgetDefine;
use Stonewright\WpMcp\Sandbox\SandboxFiles;
use Stonewright\WpMcp\Security\AuditLog;
use Stonewright\WpMcp\Security\IncidentStore;

/**
 * One call of the atomic widget define ability writes one audit row, named
 * after that ability.
 *
 * The ability kernel records every call, whatever the outcome, so the ability
 * must not write a second row of its own when the source guard rejects the
 * compiled source. The single row still has to say which widget was involved
 * and, for a rejection, that the source guard refused it. Staging the file has
 * its own differently named sandbox row, which is not part of this count.
 *
 * @covers \Stonewright\WpMcp\Abilities\ElementorV4\AtomicWidgetDefine
 */
final class AtomicWidgetDefineAuditRowTest extends TestCase {

	private const ABILITY = 'stonewright/elementor-v4-atomic-widget-define';

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

	public function test_a_successful_call_writes_exactly_one_row_for_the_ability(): void {
		$result = ( new AtomicWidgetDefine() )->execute( $this->args( 'audit-atomic' ) );

		self::assertIsArray( $result );
		self::assertTrue( $result['ok'] );
		self::assertFileExists( SandboxFiles::stored_path( 'atomic-audit-atomic.php' ) );

		$rows = $this->rows_for( self::ABILITY );
		self::assertCount( 1, $rows );
		self::assertSame( 'ok', $rows[0]['result_status'] );
		self::assertSame( 'audit-atomic', $this->recorded_args( $rows[0] )['slug'] ?? null );
	}

	public function test_a_source_guard_rejection_writes_exactly_one_row_for_the_ability(): void {
		$args          = $this->args( 'audit-atomic-guard' );
		$args['title'] = 'Guard ' . '<?' . '= trigger';

		$result = ( new AtomicWidgetDefine() )->execute( $args );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_static_guard_rejected', $result->get_error_code() );
		self::assertFileDoesNotExist( SandboxFiles::stored_path( 'atomic-audit-atomic-guard.php' ) );

		$rows = $this->rows_for( self::ABILITY );
		self::assertCount( 1, $rows );
		self::assertSame( 'error', $rows[0]['result_status'] );
		self::assertSame( 'stonewright_static_guard_rejected', $rows[0]['error_code'] );
		self::assertSame( 'audit-atomic-guard', $this->recorded_args( $rows[0] )['slug'] ?? null );
	}

	// -------------------------------------------------------------------------
	// Fixtures
	// -------------------------------------------------------------------------

	/** @return array<string, mixed> */
	private function args( string $slug ): array {
		return [
			'slug'     => $slug,
			'title'    => 'Audit Atomic Widget',
			'template' => '<div>{{ heading }}</div>',
			'props'    => [
				[ 'name' => 'heading', 'type' => 'string', 'default' => '' ],
			],
		];
	}

	private function remove_staged_widgets(): void {
		foreach ( glob( SandboxFiles::draft_dir() . '/atomic-audit-*' ) ?: [] as $file ) {
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
