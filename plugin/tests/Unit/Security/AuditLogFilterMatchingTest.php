<?php
/**
 * Each Audit Log filter has one matching rule: text filters match part of the value, identifiers match exactly.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Admin\AuditLogPage;
use Stonewright\WpMcp\Security\AuditEvent;
use Stonewright\WpMcp\Security\AuditLog;

/**
 * @covers \Stonewright\WpMcp\Security\AuditLog
 * @covers \Stonewright\WpMcp\Admin\AuditLogPage
 */
final class AuditLogFilterMatchingTest extends TestCase {

	private mixed $original_wpdb;

	protected function setUp(): void {
		$this->original_wpdb                   = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['stonewright_test_user_caps'] = [ 'manage_options' => true ];
		$_GET                                  = [];
	}

	protected function tearDown(): void {
		$GLOBALS['wpdb']                       = $this->original_wpdb;
		$GLOBALS['stonewright_test_user_caps'] = [];
		$_GET                                  = [];
	}

	private function wpdb(): object {
		return new class() {
			public $prefix = 'wp_';
			/** @var list<array{sql: string, args: list<mixed>}> */
			public array $queries = [];

			public function prepare( string $query, mixed ...$args ): string {
				$this->queries[] = [ 'sql' => $query, 'args' => $args ];
				return $query;
			}

			public function get_var( string $query = '' ): int {
				return 0;
			}

			/** @return array<int, array<string, mixed>> */
			public function get_results( string $query, string $output = 'OBJECT' ): array {
				return [];
			}
		};
	}

	/** @param array<string, mixed> $filters @return array{sql: string, args: list<mixed>} */
	private function listing( array $filters ): array {
		$wpdb            = $this->wpdb();
		$GLOBALS['wpdb'] = $wpdb;
		AuditLog::recent( 20, 1, $filters );
		return $wpdb->queries[0];
	}

	/** @return array<string, array{0: string, 1: string}> */
	public static function containsFilters(): array {
		return [
			'ability'         => [ 'ability', 'ability_name' ],
			'operation class' => [ 'operation_class', 'operation_class' ],
			'root error code' => [ 'root_error_code', 'root_error_code' ],
			'path'            => [ 'normalized_path', 'normalized_path' ],
		];
	}

	public function test_the_list_of_contains_filters_is_the_list_that_builds_like_clauses(): void {
		self::assertSame( [ 'ability', 'operation_class', 'root_error_code', 'normalized_path' ], AuditLog::CONTAINS_FILTERS );
		foreach ( AuditLog::CONTAINS_FILTERS as $filter ) {
			self::assertStringContainsString( 'LIKE %s', $this->listing( [ $filter => 'x' ] )['sql'], $filter );
		}
	}

	/** @dataProvider containsFilters */
	public function test_text_filters_match_part_of_the_value_and_keep_dots_and_case( string $filter, string $column ): void {
		$query = $this->listing( [ $filter => 'Design_direction.save' ] );

		self::assertStringContainsString( $column . ' LIKE %s', $query['sql'] );
		self::assertContains( '%Design\_direction.save%', array_map( 'strval', $query['args'] ) );
		self::assertStringNotContainsString( $column . ' = %s', $query['sql'] );
	}

	public function test_a_path_filter_is_normalised_before_it_is_matched(): void {
		$query = $this->listing( [ 'normalized_path' => '\\verify//readback' ] );

		self::assertContains( '%verify/readback%', array_map( 'strval', $query['args'] ) );
	}

	/** @return array<string, array{0: string}> */
	public static function exactFilters(): array {
		return [
			'verification' => [ 'verification_status' ],
			'rollback'     => [ 'rollback_status' ],
			'error code'   => [ 'error_code' ],
			'change set'   => [ 'change_set_id' ],
		];
	}

	/** @dataProvider exactFilters */
	public function test_state_and_identifier_filters_still_match_exactly( string $filter ): void {
		$query = $this->listing( [ $filter => 'cs-7f3a91c2-0001' ] );

		self::assertStringContainsString( $filter . ' = %s', $query['sql'] );
		self::assertStringNotContainsString( $filter . ' LIKE', $query['sql'] );
	}

	public function test_the_page_hands_a_dotted_operation_class_to_the_query_unchanged(): void {
		$wpdb            = $this->wpdb();
		$GLOBALS['wpdb'] = $wpdb;
		$_GET            = [ 'operation_class' => 'design_direction.save', 'root_error_code' => 'skill_write', 'normalized_path' => 'verify/readback' ];

		ob_start();
		AuditLogPage::render();
		ob_get_clean();

		$args = [];
		foreach ( $wpdb->queries as $query ) {
			$args = array_merge( $args, array_map( 'strval', $query['args'] ) );
		}
		self::assertContains( '%design\_direction.save%', $args );
		self::assertContains( '%skill\_write%', $args );
		self::assertContains( '%verify/readback%', $args );
	}

	public function test_the_page_says_how_each_filter_matches(): void {
		$GLOBALS['wpdb'] = $this->wpdb();

		ob_start();
		AuditLogPage::render();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'Ability, operation class, root error code and path match part of what you type', $html );
		self::assertStringContainsString( 'Status, category, outcome, verification, rollback, user ID and change set ID match exactly', $html );
		self::assertMatchesRegularExpression( '/Ability<\/label>.*?Contains/s', $html );
	}

	public function test_confirmation_token_checks_are_safety_rows_not_writes(): void {
		$event = AuditEvent::normalize( 'security.confirmation_token', [ '_meta' => [ 'result' => 'valid' ] ], 'ok' );

		self::assertSame( AuditEvent::CATEGORY_SAFETY, $event['category'] );
		self::assertSame( AuditEvent::OUTCOME_SUCCESS, $event['outcome'] );
	}
}
