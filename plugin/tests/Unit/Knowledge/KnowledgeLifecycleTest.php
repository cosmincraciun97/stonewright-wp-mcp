<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Knowledge;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Knowledge\Lifecycle\CandidateRepository;
use Stonewright\WpMcp\Knowledge\Lifecycle\CandidateTable;
use Stonewright\WpMcp\Skills\Skills;
use Stonewright\WpMcp\Skills\SkillsTable;

/**
 * @covers \Stonewright\WpMcp\Knowledge\Lifecycle\CandidateRepository
 * @covers \Stonewright\WpMcp\Knowledge\Lifecycle\CandidateTable
 * @covers \Stonewright\WpMcp\Skills\Skills
 */
final class KnowledgeLifecycleTest extends TestCase {

	private mixed $original_wpdb;

	protected function setUp(): void {
		$this->original_wpdb = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['stonewright_test_current_user_id'] = 7;
		$GLOBALS['wpdb'] = self::wpdb();
	}

	protected function tearDown(): void {
		if ( null !== $this->original_wpdb ) {
			$GLOBALS['wpdb'] = $this->original_wpdb;
		} else {
			unset( $GLOBALS['wpdb'] );
		}
	}

	public function test_schema_contains_provenance_ttl_verification_and_status_indexes(): void {
		$sql = CandidateTable::schema_sql();

		self::assertStringContainsString( 'source_hash char(64)', $sql );
		self::assertStringContainsString( 'version_constraints_json text', $sql );
		self::assertStringContainsString( 'verification_count int', $sql );
		self::assertStringContainsString( 'expires_at datetime', $sql );
		self::assertStringContainsString( 'topic_status (topic, status)', $sql );
		self::assertStringContainsString( 'status varchar(20)', SkillsTable::schema_sql() );
		self::assertStringContainsString( 'revision int', SkillsTable::schema_sql() );
	}

	public function test_research_creates_disabled_draft_and_two_successes_promote_it(): void {
		$created = CandidateRepository::create( self::candidate( 'Button URL control', 'The URL control uses a structured link value.' ) );

		self::assertIsArray( $created );
		self::assertSame( 'candidate', $created['status'] );
		self::assertStringStartsWith( 'draft-button-url-control-', $created['skill_slug'] );
		$draft = Skills::get( (string) $created['skill_slug'] );
		self::assertNotNull( $draft );
		self::assertSame( 'draft', $draft['status'] );
		self::assertSame( '0', (string) $draft['enabled'] );

		$fingerprint = str_repeat( 'a', 64 );
		$first       = CandidateRepository::verify( (int) $created['id'], 'task-one', $fingerprint, true );
		$second      = CandidateRepository::verify( (int) $created['id'], 'task-two', $fingerprint, true );
		self::assertIsArray( $first );
		self::assertIsArray( $second );
		self::assertSame( 2, $second['verification_count'] );

		$promoted = CandidateRepository::promote( (int) $created['id'], false, '' );
		self::assertIsArray( $promoted );
		self::assertSame( 'verified_successes', $promoted['promotion_gate'] );
		self::assertSame( 'approved', $promoted['candidate']['status'] );
		$active = Skills::get( (string) $promoted['skill_slug'] );
		self::assertNotNull( $active );
		self::assertSame( 'active', $active['status'] );
		self::assertSame( '1', (string) $active['enabled'] );
	}

	public function test_early_promotion_requires_user_approval_note(): void {
		$created = CandidateRepository::create( self::candidate( 'Form actions', 'Forms need an explicit success behavior.' ) );
		self::assertIsArray( $created );

		$blocked = CandidateRepository::promote( (int) $created['id'], true, '' );
		self::assertInstanceOf( \WP_Error::class, $blocked );
		self::assertSame( 'stonewright_knowledge_promotion_gate', $blocked->get_error_code() );

		$approved = CandidateRepository::promote( (int) $created['id'], true, 'User approved this exact candidate.' );
		self::assertIsArray( $approved );
		self::assertSame( 'user_approval', $approved['promotion_gate'] );
	}

	public function test_conflicting_active_topic_is_not_silently_replaced(): void {
		$first  = CandidateRepository::create( self::candidate( 'Responsive button recipe', 'Use the native responsive controls.' ) );
		$second = CandidateRepository::create( self::candidate( 'Responsive button recipe', 'Use a different unverified control.' ) );
		self::assertIsArray( $first );
		self::assertIsArray( $second );

		CandidateRepository::promote( (int) $first['id'], true, 'Approved first candidate.' );
		$conflict = CandidateRepository::promote( (int) $second['id'], true, 'Approved second candidate.' );

		self::assertInstanceOf( \WP_Error::class, $conflict );
		self::assertSame( 'stonewright_knowledge_conflict', $conflict->get_error_code() );
	}

	public function test_official_docs_and_elementor_version_constraints_are_hard_gates(): void {
		$untrusted = self::candidate( 'Elementor tabs', 'Use the nested tabs widget.' );
		$untrusted['source_url'] = 'https://example.com/elementor-tabs';
		$result = CandidateRepository::create( $untrusted );
		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_knowledge_source_untrusted', $result->get_error_code() );

		$unversioned = self::candidate( 'Elementor tabs', 'Use the nested tabs widget.' );
		$unversioned['version_constraints'] = [];
		$result = CandidateRepository::create( $unversioned );
		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_knowledge_version_constraints_missing', $result->get_error_code() );
	}

	public function test_skill_versions_can_be_linted_and_rolled_back(): void {
		$created = CandidateRepository::create( self::candidate( 'Elementor button rollback', 'Use the live link control.' ) );
		self::assertIsArray( $created );
		$promoted = CandidateRepository::promote( (int) $created['id'], true, 'Approved exact runtime recipe.' );
		self::assertIsArray( $promoted );

		$slug     = (string) $promoted['skill_slug'];
		$original = Skills::get( $slug );
		self::assertNotNull( $original );
		Skills::save( array_merge( $original, [ 'content' => (string) $original['content'] . "\nTemporary edit." ] ) );
		self::assertGreaterThanOrEqual( 2, count( Skills::history( $slug ) ) );
		self::assertTrue( Skills::rollback( $slug, 2 ) );
		self::assertSame( $original['content'], Skills::get( $slug )['content'] );

		$lint = Skills::lint(
			[
				'description'         => 'Use when testing a stale Elementor recipe.',
				'content'             => 'Call stonewright/not-a-real-tool only after validation.',
				'topic'               => 'Elementor recipe',
				'version_constraints' => [ 'elementor_core' => '3.30.*' ],
				'status'              => 'stale',
			]
		);
		self::assertContains( 'stale_reference', $lint['errors'] );
		self::assertContains( 'missing_tool_reference:stonewright/not-a-real-tool', $lint['errors'] );
	}

	public function test_runtime_drift_and_expiry_stale_only_incompatible_linked_skills(): void {
		$compatible   = CandidateRepository::create( self::candidate( 'Elementor compatible recipe', 'Compatible fact.' ) );
		$incompatible = CandidateRepository::create( self::candidate( 'Elementor incompatible recipe', 'Incompatible fact.' ) );
		self::assertIsArray( $compatible );
		self::assertIsArray( $incompatible );

		$runtime = str_repeat( 'a', 64 );
		$old     = str_repeat( 'b', 64 );
		CandidateRepository::verify( (int) $compatible['id'], 'compatible-task', $runtime, true );
		CandidateRepository::verify( (int) $incompatible['id'], 'old-task', $old, true );
		$compatible_promotion   = CandidateRepository::promote( (int) $compatible['id'], true, 'Approved compatible recipe.' );
		$incompatible_promotion = CandidateRepository::promote( (int) $incompatible['id'], true, 'Approved old recipe.' );
		self::assertIsArray( $compatible_promotion );
		self::assertIsArray( $incompatible_promotion );

		self::assertSame( 1, CandidateRepository::invalidate_fingerprint( $runtime ) );
		self::assertSame( 'approved', CandidateRepository::get( (int) $compatible['id'] )['status'] );
		self::assertSame( 'stale', CandidateRepository::get( (int) $incompatible['id'] )['status'] );
		self::assertSame( 'stale', Skills::get( (string) $incompatible_promotion['skill_slug'] )['status'] );

		$GLOBALS['wpdb']->candidates[ (int) $compatible['id'] ]['expires_at'] = '2020-01-01 00:00:00';
		self::assertSame( 'stale', CandidateRepository::get( (int) $compatible['id'] )['status'] );
		self::assertSame( 'stale', Skills::get( (string) $compatible_promotion['skill_slug'] )['status'] );
	}

	/** @return array<string, mixed> */
	private static function candidate( string $topic, string $fact ): array {
		return [
			'topic'               => $topic,
			'widget'              => 'button',
			'control'             => 'link',
			'fact'                => $fact,
			'recipe'              => 'Read the live schema, dry-run the setting, write it, then verify editor and frontend behavior.',
			'source_url'          => 'https://elementor.com/help/button-widget/',
			'source_hash'         => hash( 'sha256', $topic . $fact ),
			'fetched_at'          => '2026-07-14T12:00:00Z',
			'expires_at'          => '2027-07-14T12:00:00Z',
			'version_constraints' => [ 'elementor_core' => '>=3.30 <4.0', 'elementor_pro' => 'optional' ],
			'evidence_type'       => 'official_docs',
			'confidence'          => 0.9,
		];
	}

	private static function wpdb(): object {
		return new class() {
			public string $prefix = 'wp_';
			public int $insert_id = 100;
			/** @var array<int, array<string, mixed>> */
			public array $candidates = [];
			/** @var array<int, array<string, mixed>> */
			public array $skills = [];
			/** @var list<array<string, mixed>> */
			public array $history = [];

			public function get_charset_collate(): string {
				return 'DEFAULT CHARACTER SET utf8mb4';
			}

			public function esc_like( string $value ): string {
				return $value;
			}

			public function prepare( string $query, mixed ...$args ): string {
				foreach ( $args as $arg ) {
					$replacement = is_int( $arg ) ? (string) $arg : "'" . addslashes( (string) $arg ) . "'";
					$query       = (string) preg_replace( '/%[ds]/', $replacement, $query, 1 );
				}
				return $query;
			}

			public function get_var( string $query ): mixed {
				return str_contains( $query, 'SELECT id FROM' ) ? null : 'table_exists';
			}

			/** @return array<string, mixed>|null */
			public function get_row( string $query, string $output = 'OBJECT' ): ?array {
				if ( str_contains( $query, 'stonewright_knowledge_candidates' ) ) {
					if ( preg_match( '/WHERE id = (\d+)/', $query, $match ) ) {
						return $this->candidates[ (int) $match[1] ] ?? null;
					}
					if ( preg_match( "/semantic_fingerprint = '([^']+)'/", $query, $match ) ) {
						foreach ( $this->candidates as $candidate ) {
							if ( (string) $candidate['semantic_fingerprint'] === stripslashes( $match[1] ) ) {
								return $candidate;
							}
						}
					}
				}
				if ( str_contains( $query, 'stonewright_skills' ) && preg_match( "/slug = '([^']+)'/", $query, $match ) ) {
					foreach ( $this->skills as $skill ) {
						if ( (string) $skill['slug'] === stripslashes( $match[1] ) ) {
							return $skill;
						}
					}
				}
				return null;
			}

			/** @return list<array<string, mixed>> */
			public function get_results( string $query, string $output = 'OBJECT' ): array {
				if ( str_contains( $query, 'stonewright_skill_versions' ) ) {
					return $this->history;
				}
				if ( str_contains( $query, 'stonewright_skills' ) ) {
					return array_values(
						array_filter(
							$this->skills,
							static fn( array $skill ): bool => ! str_contains( $query, 'enabled = 1' ) || 1 === (int) $skill['enabled']
						)
					);
				}
				if ( str_contains( $query, 'expires_at <=' ) ) {
					return array_values(
						array_filter(
							$this->candidates,
							static fn( array $candidate ): bool => strtotime( (string) $candidate['expires_at'] . ' UTC' ) <= time()
								&& in_array( (string) $candidate['status'], [ 'candidate', 'verified', 'approved' ], true )
						)
					);
				}
				if ( str_contains( $query, 'verified_fingerprints_json NOT LIKE' ) && preg_match( "/NOT LIKE '%([a-f0-9]{64})%'/", $query, $match ) ) {
					return array_values(
						array_filter(
							$this->candidates,
							static fn( array $candidate ): bool => in_array( (string) $candidate['status'], [ 'verified', 'approved' ], true )
								&& ! str_contains( (string) $candidate['verified_fingerprints_json'], $match[1] )
						)
					);
				}
				return array_values( $this->candidates );
			}

			/** @param array<string, mixed> $data */
			public function insert( string $table, array $data, array $format = [] ): int {
				++$this->insert_id;
				if ( str_ends_with( $table, 'stonewright_knowledge_candidates' ) ) {
					$data['id']         = $this->insert_id;
					$data['created_at'] = '2026-07-14 12:00:00';
					$data['updated_at'] = '2026-07-14 12:00:00';
					$this->candidates[ $this->insert_id ] = $data;
				} elseif ( str_ends_with( $table, 'stonewright_skills' ) ) {
					$data['id']         = $this->insert_id;
					$data['created_at'] = '2026-07-14 12:00:00';
					$this->skills[ $this->insert_id ] = $data;
				} elseif ( str_ends_with( $table, 'stonewright_skill_versions' ) ) {
					$this->history[] = $data + [ 'created_at' => '2026-07-14 12:00:00' ];
				}
				return 1;
			}

			/** @param array<string, mixed> $data @param array<string, mixed> $where */
			public function update( string $table, array $data, array $where, array $format = [], array $where_format = [] ): int {
				if ( str_ends_with( $table, 'stonewright_knowledge_candidates' ) ) {
					$id = (int) ( $where['id'] ?? 0 );
					$this->candidates[ $id ] = array_merge( $this->candidates[ $id ], $data );
					return 1;
				}
				foreach ( $this->skills as $id => $skill ) {
					if ( (string) $skill['slug'] === (string) ( $where['slug'] ?? '' ) ) {
						$this->skills[ $id ] = array_merge( $skill, $data );
						return 1;
					}
				}
				return 0;
			}

			public function query( string $query ): int {
				if ( ! str_contains( $query, 'verified_fingerprints_json NOT LIKE' ) || ! preg_match( "/NOT LIKE '%([a-f0-9]{64})%'/", $query, $match ) ) {
					return 0;
				}
				$count = 0;
				foreach ( $this->candidates as $id => $candidate ) {
					if ( in_array( (string) $candidate['status'], [ 'verified', 'approved' ], true )
						&& ! str_contains( (string) $candidate['verified_fingerprints_json'], $match[1] ) ) {
						$this->candidates[ $id ]['status'] = 'stale';
						++$count;
					}
				}
				return $count;
			}
		};
	}
}
