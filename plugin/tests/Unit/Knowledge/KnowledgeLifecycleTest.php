<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Knowledge;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\Knowledge\KnowledgeCandidateRecord;
use Stonewright\WpMcp\Knowledge\Lifecycle\CandidateRepository;
use Stonewright\WpMcp\Knowledge\Lifecycle\CandidateTable;
use Stonewright\WpMcp\Elementor\Schema\RuntimeFingerprint;
use Stonewright\WpMcp\Knowledge\Lifecycle\SchemaRepairLearning;
use Stonewright\WpMcp\Security\PromotionGrant;
use Stonewright\WpMcp\SkillLibrary\Site\SkillLibraryService;
use Stonewright\WpMcp\SkillLibrary\Site\SkillTables;

/**
 * @covers \Stonewright\WpMcp\Knowledge\Lifecycle\CandidateRepository
 * @covers \Stonewright\WpMcp\Knowledge\Lifecycle\CandidateTable
 * @covers \Stonewright\WpMcp\SkillLibrary\Site\SkillLibraryService
 */
final class KnowledgeLifecycleTest extends TestCase {

	private mixed $original_wpdb;

	protected function setUp(): void {
		$this->original_wpdb = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['stonewright_test_current_user_id'] = 7;
		$GLOBALS['stonewright_test_user_caps'] = [ 'manage_options' => true ];
		$GLOBALS['stonewright_test_options'] = [];
		$GLOBALS['wpdb'] = self::wpdb();
	}

	protected function tearDown(): void {
		if ( null !== $this->original_wpdb ) {
			$GLOBALS['wpdb'] = $this->original_wpdb;
		} else {
			unset( $GLOBALS['wpdb'] );
		}
		$GLOBALS['stonewright_test_options'] = [];
		unset( $GLOBALS['stonewright_test_user_caps'] );
	}

	public function test_schema_contains_provenance_ttl_verification_and_status_indexes(): void {
		$sql = CandidateTable::schema_sql();

		self::assertStringContainsString( 'source_hash char(64)', $sql );
		self::assertStringContainsString( 'version_constraints_json text', $sql );
		self::assertStringContainsString( 'verification_count int', $sql );
		self::assertStringContainsString( 'expires_at datetime', $sql );
		self::assertStringContainsString( 'topic_status (topic, status)', $sql );
		self::assertStringContainsString( 'status varchar(20)', SkillTables::skills_definition() );
		self::assertStringContainsString( 'revision int', SkillTables::skills_definition() );
	}

	public function test_research_creates_disabled_draft_and_two_successes_promote_it(): void {
		$created = CandidateRepository::create( self::candidate( 'Button URL control', 'The URL control uses a structured link value.' ) );

		self::assertIsArray( $created );
		self::assertSame( 'candidate', $created['status'] );
		self::assertStringStartsWith( 'draft-button-url-control-', $created['skill_slug'] );
		$draft = SkillLibraryService::open()->find( (string) $created['skill_slug'] );
		self::assertNotNull( $draft );
		self::assertSame( 'draft', $draft['status'] );
		self::assertSame( '0', (string) $draft['enabled'] );

		$fingerprint = str_repeat( 'a', 64 );
		$first       = CandidateRepository::verify( (int) $created['id'], 'task-one', $fingerprint, true );
		$second      = CandidateRepository::verify( (int) $created['id'], 'task-two', $fingerprint, true );
		self::assertIsArray( $first );
		self::assertIsArray( $second );
		self::assertSame( 2, $second['verification_count'] );

		$promoted = self::promote_with_grant( (int) $created['id'], '' );
		self::assertIsArray( $promoted );
		self::assertSame( 'administrator_approval', $promoted['promotion_gate'] );
		self::assertSame( 2, $promoted['candidate']['verification_count'] );
		self::assertSame( 'approved', $promoted['candidate']['status'] );
		$active = SkillLibraryService::open()->find( (string) $promoted['skill_slug'] );
		self::assertNotNull( $active );
		self::assertSame( 'active', $active['status'] );
		self::assertSame( '1', (string) $active['enabled'] );
	}

	public function test_thin_evidence_needs_a_note_from_the_administrator_who_approves(): void {
		$created = CandidateRepository::create( self::candidate( 'Form actions', 'Forms need an explicit success behavior.' ) );
		self::assertIsArray( $created );

		$blocked = self::promote_with_grant( (int) $created['id'], '' );
		self::assertInstanceOf( \WP_Error::class, $blocked );
		self::assertSame( 'stonewright_promotion_note_required', $blocked->get_error_code() );

		$approved = self::promote_with_grant( (int) $created['id'], 'User approved this exact candidate.' );
		self::assertIsArray( $approved );
		self::assertSame( 'administrator_approval', $approved['promotion_gate'] );
		self::assertSame( 'User approved this exact candidate.', $approved['approval_note'] );
	}

	public function test_an_agent_cannot_promote_without_a_grant(): void {
		$created = CandidateRepository::create( self::candidate( 'Button URL control', 'The URL control uses a structured link value.' ) );
		self::assertIsArray( $created );
		$fingerprint = str_repeat( 'a', 64 );
		CandidateRepository::verify( (int) $created['id'], 'task-one', $fingerprint, true );
		CandidateRepository::verify( (int) $created['id'], 'task-two', $fingerprint, true );

		$blocked = CandidateRepository::promote( (int) $created['id'] );

		self::assertInstanceOf( \WP_Error::class, $blocked );
		self::assertSame( 'stonewright_promotion_approval_required', $blocked->get_error_code() );
		self::assertTrue( $blocked->get_error_data()['agent_must_stop'] );
		self::assertStringContainsString( 'stonewright-skills', (string) $blocked->get_error_data()['approval_url'] );
		self::assertSame( 'verified', CandidateRepository::get( (int) $created['id'] )['status'] );
		$skill = SkillLibraryService::open()->find( (string) $created['skill_slug'] );
		self::assertNotNull( $skill );
		self::assertSame( 'draft', $skill['status'] );
		self::assertCount( 1, PromotionGrant::pending() );

		// Asking again does not stack requests or promote.
		$again = CandidateRepository::promote( (int) $created['id'] );
		self::assertInstanceOf( \WP_Error::class, $again );
		self::assertCount( 1, PromotionGrant::pending() );
	}

	public function test_a_grant_is_single_use_and_bound_to_its_candidate(): void {
		$first  = CandidateRepository::create( self::candidate( 'Button URL control', 'The URL control uses a structured link value.' ) );
		$second = CandidateRepository::create( self::candidate( 'Icon box layout', 'The icon box keeps the icon above the title.' ) );
		self::assertIsArray( $first );
		self::assertIsArray( $second );

		$request = CandidateRepository::request_promotion( (int) $first['id'] );
		self::assertIsArray( $request );
		self::assertIsArray( PromotionGrant::approve( (string) $request['request_id'], 'Reviewed the first candidate.' ) );

		// The grant belongs to the first candidate; the second one is still blocked.
		$other = CandidateRepository::promote( (int) $second['id'] );
		self::assertInstanceOf( \WP_Error::class, $other );
		self::assertSame( 'stonewright_promotion_approval_required', $other->get_error_code() );

		$promoted = CandidateRepository::promote( (int) $first['id'] );
		self::assertIsArray( $promoted );
		self::assertSame( 'approved', $promoted['candidate']['status'] );

		// Once spent, the same grant promotes nothing again.
		$GLOBALS['wpdb']->candidates[ (int) $first['id'] ]['status'] = 'candidate';
		$spent = CandidateRepository::promote( (int) $first['id'] );
		self::assertInstanceOf( \WP_Error::class, $spent );
		self::assertSame( 'stonewright_promotion_approval_required', $spent->get_error_code() );
	}

	public function test_a_success_counts_only_when_it_is_a_verified_audit_event_the_plugin_wrote(): void {
		$created = CandidateRepository::create( self::candidate( 'Button URL control', 'The URL control uses a structured link value.' ) );
		self::assertIsArray( $created );
		$id = (int) $created['id'];

		// The caller invents an event id: nothing counts.
		$unknown = CandidateRepository::verify_from_event( $id, '11111111-1111-4111-8111-111111111111' );
		self::assertInstanceOf( \WP_Error::class, $unknown );
		self::assertSame( 'stonewright_knowledge_verification_unproven', $unknown->get_error_code() );
		self::assertSame( 0, CandidateRepository::get( $id )['verification_count'] );

		// A real event, but its effect was not verified.
		$GLOBALS['wpdb']->audit_rows = [ self::audit_event( [ 'effect_verified' => 0 ] ) ];
		self::assertInstanceOf( \WP_Error::class, CandidateRepository::verify_from_event( $id, '11111111-1111-4111-8111-111111111111' ) );

		// A failed call.
		$GLOBALS['wpdb']->audit_rows = [ self::audit_event( [ 'outcome' => 'FAILED' ] ) ];
		self::assertInstanceOf( \WP_Error::class, CandidateRepository::verify_from_event( $id, '11111111-1111-4111-8111-111111111111' ) );

		// Knowledge calls cannot verify knowledge.
		$GLOBALS['wpdb']->audit_rows = [ self::audit_event( [ 'ability_name' => 'stonewright/knowledge-candidate-record' ] ) ];
		self::assertInstanceOf( \WP_Error::class, CandidateRepository::verify_from_event( $id, '11111111-1111-4111-8111-111111111111' ) );

		// An event from long ago.
		$GLOBALS['wpdb']->audit_rows = [ self::audit_event( [ 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 90 * DAY_IN_SECONDS ) ] ) ];
		self::assertInstanceOf( \WP_Error::class, CandidateRepository::verify_from_event( $id, '11111111-1111-4111-8111-111111111111' ) );
		self::assertSame( 0, CandidateRepository::get( $id )['verification_count'] );

		$GLOBALS['wpdb']->audit_rows = [ self::audit_event( [] ) ];
		$counted = CandidateRepository::verify_from_event( $id, '11111111-1111-4111-8111-111111111111' );
		self::assertIsArray( $counted );
		self::assertSame( 1, $counted['verification_count'] );
		self::assertSame( [ '11111111-1111-4111-8111-111111111111' ], $counted['verification_task_ids'] );
		self::assertSame( [ RuntimeFingerprint::describe()['hash'] ], $counted['verified_fingerprints'] );

		// The same event is one success, however often it is named.
		$repeat = CandidateRepository::verify_from_event( $id, '11111111-1111-4111-8111-111111111111' );
		self::assertIsArray( $repeat );
		self::assertSame( 1, $repeat['verification_count'] );
	}
	public function test_the_ability_ignores_an_approval_the_agent_supplies_itself(): void {
		$created = CandidateRepository::create( self::candidate( 'Button URL control', 'The URL control uses a structured link value.' ) );
		self::assertIsArray( $created );

		$result = ( new KnowledgeCandidateRecord() )->execute(
			[
				'action'        => 'promote',
				'id'            => (int) $created['id'],
				'user_approved' => true,
				'approval_note' => 'The user said yes.',
			]
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_promotion_approval_required', $result->get_error_code() );
		self::assertSame( 'candidate', CandidateRepository::get( (int) $created['id'] )['status'] );
		self::assertSame( 'draft', SkillLibraryService::open()->find( (string) $created['skill_slug'] )['status'] );
	}

	public function test_the_ability_promotes_once_an_administrator_approved_the_request(): void {
		$created = CandidateRepository::create( self::candidate( 'Button URL control', 'The URL control uses a structured link value.' ) );
		self::assertIsArray( $created );
		$args = [ 'action' => 'promote', 'id' => (int) $created['id'] ];

		$blocked = ( new KnowledgeCandidateRecord() )->execute( $args );
		self::assertInstanceOf( \WP_Error::class, $blocked );
		self::assertIsArray( PromotionGrant::approve( (string) $blocked->get_error_data()['request_id'], 'Reviewed on the site.' ) );

		$result = ( new KnowledgeCandidateRecord() )->execute( $args );

		self::assertIsArray( $result );
		self::assertSame( 'approved', $result['result']['candidate']['status'] );
		self::assertSame( 'Reviewed on the site.', $result['result']['approval_note'] );
		self::assertSame( 'active', SkillLibraryService::open()->find( (string) $created['skill_slug'] )['status'] );
	}

	public function test_the_verify_ability_refuses_a_task_id_the_agent_made_up(): void {
		$created = CandidateRepository::create( self::candidate( 'Button URL control', 'The URL control uses a structured link value.' ) );
		self::assertIsArray( $created );

		$result = ( new KnowledgeCandidateRecord() )->execute(
			[
				'action'              => 'verify',
				'id'                  => (int) $created['id'],
				'task_id'             => 'task-one',
				'runtime_fingerprint' => str_repeat( 'a', 64 ),
				'success'             => true,
			]
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_knowledge_verification_unproven', $result->get_error_code() );
		self::assertSame( 0, CandidateRepository::get( (int) $created['id'] )['verification_count'] );
	}

	public function test_conflicting_active_topic_is_not_silently_replaced(): void {
		$first  = CandidateRepository::create( self::candidate( 'Responsive button recipe', 'Use the native responsive controls.' ) );
		$second = CandidateRepository::create( self::candidate( 'Responsive button recipe', 'Use a different unverified control.' ) );
		self::assertIsArray( $first );
		self::assertIsArray( $second );

		self::promote_with_grant( (int) $first['id'], 'Approved first candidate.' );
		$conflict = self::promote_with_grant( (int) $second['id'], 'Approved second candidate.' );

		self::assertInstanceOf( \WP_Error::class, $conflict );
		self::assertSame( 'stonewright_knowledge_conflict', $conflict->get_error_code() );
	}

	public function test_replace_promotion_withdraws_the_conflicting_skill_once_the_candidate_passes_lint(): void {
		$first  = CandidateRepository::create( self::candidate( 'Responsive button recipe', 'Use the native responsive controls.' ) );
		$second = CandidateRepository::create( self::candidate( 'Responsive button recipe', 'Use a different, verified control.' ) );
		self::assertIsArray( $first );
		self::assertIsArray( $second );
		$current = self::promote_with_grant( (int) $first['id'], 'Approved first candidate.' );
		self::assertIsArray( $current );

		$replacement = self::promote_with_grant( (int) $second['id'], 'Approved replacement.', 'replace' );

		self::assertIsArray( $replacement );
		self::assertSame( [ $current['skill_slug'] ], $replacement['replaced'] );
		$withdrawn = SkillLibraryService::open()->find( (string) $current['skill_slug'] );
		self::assertNotNull( $withdrawn );
		self::assertSame( 'stale', $withdrawn['status'] );
		self::assertSame( '0', $withdrawn['enabled'] );
		self::assertSame( [ $second['semantic_fingerprint'] ], $withdrawn['conflicts'] );
		$active = SkillLibraryService::open()->find( (string) $replacement['skill_slug'] );
		self::assertNotNull( $active );
		self::assertSame( 'active', $active['status'] );
		self::assertSame( '1', (string) $active['enabled'] );
	}

	public function test_replace_promotion_that_fails_lint_leaves_the_conflicting_skill_as_it_was(): void {
		$flawed           = self::candidate( 'Responsive button recipe', 'Use a different control.' );
		$flawed['recipe'] = 'Call stonewright/not-a-real-tool after validation.';
		$first            = CandidateRepository::create( self::candidate( 'Responsive button recipe', 'Use the native responsive controls.' ) );
		$second           = CandidateRepository::create( $flawed );
		self::assertIsArray( $first );
		self::assertIsArray( $second );
		$current = self::promote_with_grant( (int) $first['id'], 'Approved first candidate.' );
		self::assertIsArray( $current );
		$before = SkillLibraryService::open()->find( (string) $current['skill_slug'] );
		self::assertNotNull( $before );
		self::assertSame( 'active', $before['status'] );

		$refused = self::promote_with_grant( (int) $second['id'], 'Approved replacement.', 'replace' );

		self::assertInstanceOf( \WP_Error::class, $refused );
		self::assertSame( 'stonewright_skill_lint_failed', $refused->get_error_code() );
		$after = SkillLibraryService::open()->find( (string) $current['skill_slug'] );
		self::assertNotNull( $after );
		self::assertSame( 'active', $after['status'] );
		self::assertSame( '1', $after['enabled'] );
		self::assertSame( $before['revision'], $after['revision'] );
		self::assertSame( $before, $after );
		self::assertSame( 'candidate', CandidateRepository::get( (int) $second['id'] )['status'] );
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
		$promoted = self::promote_with_grant( (int) $created['id'], 'Approved exact runtime recipe.' );
		self::assertIsArray( $promoted );

		$slug     = (string) $promoted['skill_slug'];
		$skills   = SkillLibraryService::open();
		$original = $skills->find( $slug );
		self::assertNotNull( $original );
		$skills->save_skill( array_merge( $original, [ 'content' => (string) $original['content'] . "\nTemporary edit." ] ) );
		self::assertGreaterThanOrEqual( 2, count( $skills->revisions_of( $slug ) ) );
		self::assertTrue( $skills->roll_back_skill( $slug, 2 ) );
		self::assertSame( $original['content'], $skills->find( $slug )['content'] );

		$lint = $skills->review_record(
			[
				'description'         => 'Use when testing a stale Elementor recipe.',
				'content'             => 'Call stonewright/not-a-real-tool only after validation.',
				'topic'               => 'Elementor recipe',
				'version_constraints' => [ 'elementor_core' => '3.30.*' ],
				'status'              => 'stale',
			]
		);
		self::assertContains( 'stale_record', $lint['errors'] );
		self::assertContains( 'unavailable_tool:stonewright/not-a-real-tool', $lint['errors'] );
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
		$compatible_promotion   = self::promote_with_grant( (int) $compatible['id'], 'Approved compatible recipe.' );
		$incompatible_promotion = self::promote_with_grant( (int) $incompatible['id'], 'Approved old recipe.' );
		self::assertIsArray( $compatible_promotion );
		self::assertIsArray( $incompatible_promotion );

		self::assertSame( 1, CandidateRepository::invalidate_fingerprint( $runtime ) );
		self::assertSame( 'approved', CandidateRepository::get( (int) $compatible['id'] )['status'] );
		self::assertSame( 'stale', CandidateRepository::get( (int) $incompatible['id'] )['status'] );
		self::assertSame( 'stale', SkillLibraryService::open()->find( (string) $incompatible_promotion['skill_slug'] )['status'] );

		$GLOBALS['wpdb']->candidates[ (int) $compatible['id'] ]['expires_at'] = '2020-01-01 00:00:00';
		self::assertSame( 'stale', CandidateRepository::get( (int) $compatible['id'] )['status'] );
		self::assertSame( 'stale', SkillLibraryService::open()->find( (string) $compatible_promotion['skill_slug'] )['status'] );
	}

	public function test_schema_failure_alone_never_creates_candidate(): void {
		SchemaRepairLearning::observe_failure(
			'loop-carousel',
			[ 'slides_to_show' => [ 'size' => 3 ] ],
			self::schema_error(),
			str_repeat( '1', 64 )
		);

		self::assertSame(
			[],
			CandidateRepository::list( [ 'topic' => 'Elementor schema repair: loop-carousel/slides_to_show' ] )
		);
	}

	public function test_two_distinct_verified_tasks_wait_for_approval_on_one_deduplicated_candidate(): void {
		$schema = self::repair_schema();
		SchemaRepairLearning::observe_failure(
			'loop-carousel',
			[ 'slides_to_show' => [ 'size' => 3 ] ],
			self::schema_error(),
			str_repeat( '1', 64 )
		);
		$first = SchemaRepairLearning::observe_verified(
			'loop-carousel',
			[ 'slides_to_show' => 3 ],
			$schema,
			str_repeat( '1', 64 )
		);
		$repeat = SchemaRepairLearning::observe_verified(
			'loop-carousel',
			[ 'slides_to_show' => 4 ],
			$schema,
			str_repeat( '1', 64 )
		);
		$second = SchemaRepairLearning::observe_verified(
			'loop-carousel',
			[ 'slides_to_show' => 5 ],
			$schema,
			str_repeat( '2', 64 )
		);

		self::assertSame( 1, $first[0]['candidate']['verification_count'] );
		self::assertSame( 1, $repeat[0]['candidate']['verification_count'] );
		self::assertSame( 'verified', $second[0]['candidate']['status'] );
		self::assertSame( 2, $second[0]['candidate']['verification_count'] );
		self::assertCount( 1, PromotionGrant::pending() );
		self::assertCount(
			1,
			CandidateRepository::list( [ 'topic' => 'Elementor schema repair: loop-carousel/slides_to_show' ] )
		);
		self::assertStringNotContainsString( '"size":3', (string) $second[0]['candidate']['recipe'] );
		self::assertStringNotContainsString( '5', (string) $second[0]['candidate']['fact'] );
	}

	/**
	 * @param array<string, mixed> $overrides
	 * @return array<string, mixed>
	 */
	private static function audit_event( array $overrides ): array {
		return $overrides + [
			'event_id'            => '11111111-1111-4111-8111-111111111111',
			'ability_name'        => 'stonewright/elementor-v3-update-element',
			'outcome'             => 'SUCCESS',
			'effect_verified'     => 1,
			'verification_status' => 'verified',
			'created_at'          => gmdate( 'Y-m-d H:i:s' ),
		];
	}

	/**
	 * Stands in for the administrator: stages the request, approves it as the administrator, then promotes.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function promote_with_grant( int $id, string $note, string $conflict = 'reject' ): array|\WP_Error {
		$request = CandidateRepository::request_promotion( $id, $conflict );
		if ( $request instanceof \WP_Error ) {
			return $request;
		}
		$approved = PromotionGrant::approve( (string) $request['request_id'], $note );
		if ( $approved instanceof \WP_Error ) {
			return $approved;
		}

		return CandidateRepository::promote( $id, $conflict );
	}

	private static function schema_error(): \WP_Error {
		return new \WP_Error(
			'stonewright_elementor_settings_invalid',
			'Invalid control.',
			[
				'violations' => [
					[
						'path'     => 'slides_to_show',
						'expected' => 'number',
						'received' => 'object',
					],
				],
				'schema_hash'        => str_repeat( 'a', 64 ),
				'runtime_fingerprint'=> str_repeat( 'b', 64 ),
			]
		);
	}

	/** @return array<string, mixed> */
	private static function repair_schema(): array {
		return [
			'schema_hash'         => str_repeat( 'a', 64 ),
			'runtime_fingerprint' => str_repeat( 'b', 64 ),
			'elementor_core'      => '3.30.0',
			'elementor_pro'       => '3.30.0',
			'controls'            => [
				'slides_to_show' => [ 'type' => 'number' ],
			],
		];
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
			public $prefix = 'wp_';
			public $insert_id = 100;
			/** @var array<int, array<string, mixed>> */
			public array $candidates = [];
			/** @var array<int, array<string, mixed>> */
			public array $skills = [];
			/** @var list<array<string, mixed>> */
			public array $history = [];
			/** @var list<array<string, mixed>> */
			public array $audit_rows = [];

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
				if ( str_contains( $query, 'stonewright_audit_log' ) ) {
					return $this->audit_rows;
				}
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
