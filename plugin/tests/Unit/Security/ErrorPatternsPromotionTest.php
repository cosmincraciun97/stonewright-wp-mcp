<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Memory\Memory;
use Stonewright\WpMcp\Security\ErrorPatterns;

/**
 * End-to-end unit path: recurring audit errors promote into Memory learnings.
 *
 * @covers \Stonewright\WpMcp\Security\ErrorPatterns
 * @covers \Stonewright\WpMcp\Memory\Memory
 */
final class ErrorPatternsPromotionTest extends TestCase {

	private mixed $original_wpdb;

	protected function setUp(): void {
		$this->original_wpdb = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['stonewright_test_options'] = [];
		$GLOBALS['stonewright_test_current_user_id'] = 1;
		$GLOBALS['wpdb'] = $this->make_memory_wpdb();
	}

	protected function tearDown(): void {
		if ( null !== $this->original_wpdb ) {
			$GLOBALS['wpdb'] = $this->original_wpdb;
		} else {
			unset( $GLOBALS['wpdb'] );
		}
		$GLOBALS['stonewright_test_options'] = [];
	}

	public function test_two_identical_errors_propose_remediation_without_learning_row(): void {
		Memory::maybe_install_table();
		delete_option( 'stonewright_error_patterns' );

		$args = [
			'error_code' => 'stonewright_demo_failure',
			'message'    => 'Demo failed',
		];
		ErrorPatterns::observe( 'stonewright/demo-ability', 'error', $args );
		ErrorPatterns::observe( 'stonewright/demo-ability', 'error', $args );

		$recurring = ErrorPatterns::recurring();
		self::assertNotEmpty( $recurring );
		self::assertSame( 'repair_proposed', $recurring[0]['state'] );
		self::assertNotEmpty( $recurring[0]['repair'] );

		// Incidents ≠ lessons: unresolved failures stay on the pattern path only.
		$rows = Memory::list_by_type( 'feedback', 50, 0 );
		$hit  = array_filter(
			$rows,
			static fn( array $r ): bool => str_starts_with( (string) ( $r['memory_key'] ?? '' ), 'learning-audit-error-' )
		);
		self::assertSame( [], array_values( $hit ) );
	}

	public function test_expected_safety_blocks_do_not_create_learning(): void {
		Memory::maybe_install_table();
		delete_option( 'stonewright_error_patterns' );

		$args = [
			'error_code' => 'stonewright_php_code_file_write_blocked',
			'message'    => 'blocked',
		];
		ErrorPatterns::observe( 'stonewright/php-execute', 'blocked', $args );
		ErrorPatterns::observe( 'stonewright/php-execute', 'blocked', $args );

		$rows = Memory::list_by_type( 'feedback', 50, 0 );
		$hit  = array_filter(
			$rows,
			static fn( $r ) => str_contains( (string) ( $r['memory_key'] ?? '' ), 'learning-audit-error-' )
		);
		self::assertSame( [], array_values( $hit ) );
	}

	public function test_same_ability_success_without_receipt_neither_resolves_nor_teaches(): void {
		Memory::maybe_install_table();
		$args = [ 'error_code' => 'stonewright_demo_failure', 'message' => 'Demo failed' ];
		ErrorPatterns::observe( 'stonewright/demo-ability', 'error', $args );
		ErrorPatterns::observe( 'stonewright/demo-ability', 'error', $args );

		ErrorPatterns::observe_verified_repair(
			'stonewright/demo-ability',
			[
				'effect_verified'     => true,
				'verification_status' => 'verified',
				'operation_class'     => 'content_update',
			]
		);

		// ErrorPatterns is recurrence-only. Generic success is not lifecycle proof.
		$rows = Memory::list_by_type( 'feedback', 50, 0 );
		self::assertSame( [], $rows );
		$store = get_option( ErrorPatterns::OPTION_KEY, [] );
		$sig   = ErrorPatterns::signature( 'stonewright/demo-ability', $args );
		self::assertSame( 'repair_proposed', $store[ $sig ]['state'] ?? null );
	}

	public function test_tenth_repeat_writes_draft_reference_lesson_not_active(): void {
		Memory::maybe_install_table();
		delete_option( 'stonewright_error_patterns' );

		$args = [
			'error_code' => 'stonewright_spec_invalid',
			'message'    => 'Spec rejected: missing sections array.',
		];
		for ( $i = 0; $i < 9; $i++ ) {
			ErrorPatterns::observe( 'stonewright/design-validate-spec', 'error', $args );
		}

		self::assertSame( [], Memory::list_by_type( 'reference', 50, 0 ) );

		ErrorPatterns::observe( 'stonewright/design-validate-spec', 'error', $args );

		$sig  = ErrorPatterns::signature( 'stonewright/design-validate-spec', $args );
		$rows = Memory::list_by_type( 'reference', 50, 0 );
		self::assertCount( 1, $rows );
		$row = $rows[0];
		self::assertSame( 'draft', $row['status'] );
		self::assertSame( 'audit', $row['scope'] );
		self::assertSame( 'draft-lesson-' . $sig, $row['memory_key'] );
		self::assertNotSame( 'active', $row['status'] );
		$value = is_array( $row['value'] ?? null ) ? $row['value'] : [];
		self::assertSame( $sig, (string) ( $value['signature'] ?? '' ) );
		self::assertNotEmpty( (string) ( $value['proposed_remediation'] ?? '' ) );
	}

	public function test_expected_safety_block_at_ten_does_not_write_draft_lesson(): void {
		Memory::maybe_install_table();
		delete_option( 'stonewright_error_patterns' );

		$args = [
			'error_code' => 'stonewright_php_code_file_write_blocked',
			'message'    => 'blocked',
		];
		for ( $i = 0; $i < 10; $i++ ) {
			ErrorPatterns::observe( 'stonewright/php-execute', 'blocked', $args );
		}

		self::assertSame( [], Memory::list_by_type( 'reference', 50, 0 ) );
		self::assertSame( [], Memory::list_by_type( 'feedback', 50, 0 ) );
	}

	public function test_agent_supplied_recipe_without_canonical_receipt_does_not_promote(): void {
		Memory::maybe_install_table();
		$args = [ 'error_code' => 'stonewright_demo_failure', 'message' => 'Demo failed' ];
		ErrorPatterns::observe( 'stonewright/demo-ability', 'error', $args );
		ErrorPatterns::observe( 'stonewright/demo-ability', 'error', $args );

		ErrorPatterns::observe_verified_repair(
			'stonewright/demo-ability',
			[
				'effect_verified'     => true,
				'verification_status' => 'verified',
				'repair_recipe'       => 'Read the schema first, then update only the supported field.',
			]
		);

		$rows = Memory::list_by_type( 'feedback', 50, 0 );
		$active = array_values(
			array_filter(
				$rows,
				static fn( array $row ): bool => 'active' === (string) ( $row['status'] ?? '' )
			)
		);
		self::assertSame( [], $active );
		$store = get_option( ErrorPatterns::OPTION_KEY, [] );
		$sig   = ErrorPatterns::signature( 'stonewright/demo-ability', $args );
		self::assertSame( 'repair_proposed', $store[ $sig ]['state'] ?? null );
	}

	/**
	 * In-memory stonewright_memory table so put_typed + list_by_type share state.
	 */
	private function make_memory_wpdb(): object {
		return new class() {
			public string $prefix     = 'wp_';
			public int $insert_id     = 0;
			public string $last_error = '';

			/** @var array<int, array<string, mixed>> */
			public array $rows = [];

			public function get_charset_collate(): string {
				return '';
			}

			/** @return array<int, string> */
			public function get_col( string $query, int $x = 0 ): array {
				return [
					'id',
					'scope',
					'type',
					'name',
					'memory_key',
					'value_json',
					'confidence',
					'topic',
					'version_fingerprint',
					'expires_at',
					'status',
					'precedence',
					'created_by',
					'created_at',
					'updated_at',
					'last_retrieved_at',
				];
			}

			public function prepare( string $query, mixed ...$args ): string {
				// Stash args for get_results / get_var filters used below.
				$this->last_prepare_args = $args;
				return $query;
			}

			/** @var array<int, mixed> */
			public array $last_prepare_args = [];

			public function get_var( string $query ): mixed {
				if ( str_contains( $query, 'SELECT id FROM' ) && str_contains( $query, 'memory_key' ) ) {
					$scope = (string) ( $this->last_prepare_args[0] ?? '' );
					$key   = (string) ( $this->last_prepare_args[1] ?? '' );
					foreach ( $this->rows as $row ) {
						if ( (string) $row['scope'] === $scope && (string) $row['memory_key'] === $key ) {
							return (int) $row['id'];
						}
					}
					return null;
				}
				return null;
			}

			/** @param array<string, mixed> $data */
			public function insert( string $table, array $data, array $format = [] ): int {
				++$this->insert_id;
				$row               = $data;
				$row['id']         = $this->insert_id;
				$row['created_at'] = $row['created_at'] ?? gmdate( 'Y-m-d H:i:s' );
				$row['updated_at'] = $row['updated_at'] ?? gmdate( 'Y-m-d H:i:s' );
				$this->rows[]      = $row;
				return 1;
			}

			/** @param array<string, mixed> $data @param array<string, mixed> $where */
			public function update( string $table, array $data, array $where, array $format = [], array $where_format = [] ): int {
				$id = (int) ( $where['id'] ?? 0 );
				foreach ( $this->rows as $i => $row ) {
					if ( (int) $row['id'] === $id ) {
						$this->rows[ $i ] = array_merge( $row, $data, [ 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ] );
						return 1;
					}
				}
				return 0;
			}

			/** @return array<int, array<string, mixed>> */
			public function get_results( string $query, string $output = 'OBJECT' ): array {
				$type = null;
				if ( str_contains( $query, 'WHERE type' ) ) {
					$type = (string) ( $this->last_prepare_args[0] ?? '' );
				}

				$out = [];
				foreach ( array_reverse( $this->rows ) as $row ) {
					if ( null !== $type && (string) ( $row['type'] ?? '' ) !== $type ) {
						continue;
					}
					$out[] = [
						'id'                  => (int) $row['id'],
						'type'                => (string) ( $row['type'] ?? 'generic' ),
						'scope'               => (string) ( $row['scope'] ?? '' ),
						'memory_key'          => (string) ( $row['memory_key'] ?? '' ),
						'name'                => (string) ( $row['name'] ?? '' ),
						'value_json'          => (string) ( $row['value_json'] ?? '{}' ),
						'confidence'          => (float) ( $row['confidence'] ?? 1.0 ),
						'topic'               => (string) ( $row['topic'] ?? '' ),
						'version_fingerprint' => (string) ( $row['version_fingerprint'] ?? '' ),
						'expires_at'          => $row['expires_at'] ?? null,
						'status'              => (string) ( $row['status'] ?? 'active' ),
						'precedence'          => (int) ( $row['precedence'] ?? 0 ),
						'created_at'          => (string) ( $row['created_at'] ?? '' ),
						'updated_at'          => (string) ( $row['updated_at'] ?? '' ),
					];
				}
				return $out;
			}

			public function get_row( string $query, string $output = 'OBJECT' ): ?array {
				if ( str_contains( $query, 'WHERE id' ) ) {
					$id = (int) ( $this->last_prepare_args[0] ?? 0 );
					foreach ( $this->rows as $row ) {
						if ( (int) $row['id'] === $id ) {
							return $row;
						}
					}
				}
				return null;
			}
		};
	}
}
