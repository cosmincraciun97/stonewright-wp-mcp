<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Abilities\System;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\System\RulesGet;
use Stonewright\WpMcp\Abilities\System\TaskStart;
use Stonewright\WpMcp\Abilities\System\ToolProfile;
use Stonewright\WpMcp\Core\AbilityRegistry;
use Stonewright\WpMcp\Security\GlobalRules;
use Stonewright\WpMcp\Support\TokenSurfaceBudgets;

/**
 * Rules only change behavior if an agent can actually reach them.
 *
 * Task start therefore carries a digest reference — cheap enough for the compact
 * budget — and `stonewright/rules-get` serves the bodies on demand. These tests
 * assert the runtime payloads, never the source text: a grep would pass while
 * the shipped response stayed empty.
 *
 * @covers \Stonewright\WpMcp\Abilities\System\RulesGet
 */
final class RulesGetTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		GlobalRules::reset_cache();
		$GLOBALS['stonewright_test_transients'] = [];
		$GLOBALS['stonewright_test_options']    = [];
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_transients'] = [];
		$GLOBALS['stonewright_test_options']    = [];
		parent::tearDown();
	}

	private function rules_get(): RulesGet {
		return new RulesGet();
	}

	/**
	 * @param array<string, mixed> $args
	 * @return array<string, mixed>
	 */
	private function payload( array $args = [] ): array {
		$result = $this->rules_get()->execute( $args );
		self::assertIsArray( $result );

		return $result;
	}

	public function test_ability_is_registered_under_the_canonical_name(): void {
		$ability = AbilityRegistry::ability_by_name( 'stonewright/rules-get' );

		self::assertNotNull( $ability );
		self::assertSame( 'system', $ability->category() );
	}

	public function test_returns_every_rule_with_the_registry_digest(): void {
		$result = $this->payload();

		self::assertTrue( $result['ok'] );
		self::assertSame( GlobalRules::digest(), $result['digest'] );
		self::assertFalse( $result['unchanged'] );
		self::assertCount( count( GlobalRules::all() ), $result['rules'] );
		self::assertSame( count( GlobalRules::all() ), $result['count'] );
	}

	public function test_every_returned_record_keeps_its_enforcement_claim(): void {
		$result = $this->payload();

		foreach ( $result['rules'] as $rule ) {
			self::assertArrayHasKey( 'id', $rule );
			self::assertArrayHasKey( 'severity', $rule );
			self::assertArrayHasKey( 'scope', $rule );
			self::assertArrayHasKey( 'rule', $rule );
			self::assertArrayHasKey( 'why', $rule );
			self::assertArrayHasKey( 'enforcement', $rule );
			if ( 'hard' === $rule['severity'] ) {
				self::assertSame( 'runtime', $rule['enforcement']['kind'] );
				self::assertNotSame( '', $rule['enforcement']['guard'] );
			}
		}
	}

	public function test_severity_filter_returns_only_that_severity(): void {
		$result = $this->payload( [ 'severity' => 'hard' ] );

		self::assertNotSame( [], $result['rules'] );
		self::assertSame(
			GlobalRules::ids_for_severity( 'hard' ),
			array_column( $result['rules'], 'id' )
		);
		self::assertSame( 'hard', $result['filters']['severity'] );
	}

	public function test_scope_filter_keeps_globally_scoped_rules(): void {
		$result = $this->payload( [ 'scope' => 'elementor' ] );

		$scopes = array_unique( array_column( $result['rules'], 'scope' ) );
		sort( $scopes );
		self::assertSame( [ 'all', 'elementor' ], $scopes );
		self::assertContains( 'elementor', $scopes );
	}

	public function test_scope_all_is_not_narrower_than_the_registry(): void {
		self::assertCount( count( GlobalRules::all() ), $this->payload( [ 'scope' => 'all' ] )['rules'] );
	}

	public function test_unknown_severity_is_rejected(): void {
		$result = $this->rules_get()->execute( [ 'severity' => 'medium' ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_invalid_severity', $result->get_error_code() );
	}

	public function test_unknown_scope_is_rejected(): void {
		$result = $this->rules_get()->execute( [ 'scope' => 'seo' ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_invalid_scope', $result->get_error_code() );
	}

	public function test_matching_known_digest_skips_the_bodies(): void {
		$result = $this->payload( [ 'knownDigest' => GlobalRules::digest() ] );

		self::assertTrue( $result['unchanged'] );
		self::assertSame( [], $result['rules'] );
		self::assertSame( GlobalRules::digest(), $result['digest'] );
		// The count still tells the client how many rules its cache should hold.
		self::assertSame( count( GlobalRules::all() ), $result['count'] );
	}

	public function test_stale_known_digest_returns_the_bodies_again(): void {
		$result = $this->payload( [ 'knownDigest' => sha1( 'stale' ) ] );

		self::assertFalse( $result['unchanged'] );
		self::assertNotSame( [], $result['rules'] );
	}

	public function test_known_digest_short_circuit_respects_filters(): void {
		// A client that cached only the hard rules must not be told "unchanged"
		// for the whole registry, so the digest is filter-specific.
		$hard = $this->payload( [ 'severity' => 'hard' ] );

		self::assertNotSame( GlobalRules::digest(), $hard['digest'] );

		$cached = $this->payload( [ 'severity' => 'hard', 'knownDigest' => $hard['digest'] ] );
		self::assertTrue( $cached['unchanged'] );
		self::assertSame( [], $cached['rules'] );
	}

	public function test_output_schema_matches_the_response_shape(): void {
		$schema = $this->rules_get()->output_schema();
		$result = $this->payload();

		foreach ( array_keys( $schema['properties'] ) as $key ) {
			self::assertArrayHasKey( $key, $result );
		}
		foreach ( $schema['required'] as $key ) {
			self::assertArrayHasKey( $key, $result );
		}
	}

	public function test_reading_rules_never_requires_a_context_token(): void {
		$schema = $this->rules_get()->input_schema();

		self::assertArrayNotHasKey( 'stonewright_context_token', $schema['properties'] );
	}

	public function test_bootstrap_surface_exposes_rules_get(): void {
		self::assertContains(
			'stonewright/rules-get',
			AbilityRegistry::bootstrap_ability_names_for_test()
		);
	}

	public function test_bootstrap_surface_stays_within_its_tool_budget(): void {
		self::assertLessThanOrEqual(
			TokenSurfaceBudgets::BOOTSTRAP_MAX_TOOLS,
			count( AbilityRegistry::bootstrap_ability_names_for_test() )
		);
	}

	public function test_essential_surface_exposes_rules_get(): void {
		self::assertContains(
			'stonewright/rules-get',
			AbilityRegistry::essential_ability_names_for_test()
		);
		self::assertLessThanOrEqual(
			TokenSurfaceBudgets::ESSENTIAL_MAX_TOOLS,
			count( AbilityRegistry::essential_ability_names_for_test() )
		);
	}

	/**
	 * @dataProvider profile_provider
	 */
	public function test_named_profiles_expose_rules_get( string $profile ): void {
		self::assertContains( 'stonewright/rules-get', ToolProfile::profile_tools( $profile ) );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function profile_provider(): array {
		return [
			'bootstrap'        => [ 'bootstrap' ],
			'essential'        => [ 'essential' ],
			'low-tools'        => [ 'low-tools' ],
			'elementor-design' => [ 'elementor-design' ],
			'content-model'    => [ 'content-model' ],
			'gutenberg'        => [ 'gutenberg' ],
			'wp-cli'           => [ 'wp-cli' ],
			'site-admin'       => [ 'site-admin' ],
			'full'             => [ 'full' ],
		];
	}

	public function test_task_start_suggested_profile_exposes_rules_get(): void {
		$result = ( new TaskStart() )->execute(
			[
				'task'         => 'Rebuild an Elementor hero section from a design reference.',
				'surface'      => 'elementor',
				'intent'       => 'write',
				'responseMode' => 'compact',
			]
		);

		self::assertIsArray( $result );
		$suggested = (string) $result['fast_path']['suggested_profile'];
		self::assertNotSame( '', $suggested );
		self::assertContains( 'stonewright/rules-get', ToolProfile::profile_tools( $suggested ) );
	}

	public function test_full_task_start_references_the_rules_tool_and_digest(): void {
		$result = ( new TaskStart() )->execute(
			[
				'task'         => 'Update an existing post title and excerpt.',
				'surface'      => 'wordpress',
				'intent'       => 'write',
				'responseMode' => 'full',
			]
		);

		self::assertIsArray( $result );
		self::assertSame( GlobalRules::digest(), $result['hard_rules']['digest'] );
		self::assertSame( 'stonewright-rules-get', $result['hard_rules']['tool'] );
		// Full mode is uncapped, so it can afford to name the enforced ids.
		self::assertSame( GlobalRules::ids_for_severity( 'hard' ), $result['hard_rules']['hard'] );
		self::assertSame( count( GlobalRules::all() ), $result['hard_rules']['count'] );
	}

	public function test_compact_task_start_carries_only_the_digest_reference(): void {
		$result = ( new TaskStart() )->execute(
			[
				'task'         => 'Update an existing post title and excerpt.',
				'surface'      => 'wordpress',
				'intent'       => 'write',
				'responseMode' => 'compact',
			]
		);

		self::assertIsArray( $result );
		self::assertSame(
			[
				'digest' => GlobalRules::digest(),
				'tool'   => 'stonewright-rules-get',
			],
			$result['hard_rules']
		);
	}

	public function test_compact_task_start_still_fits_the_non_visual_budget(): void {
		$GLOBALS['stonewright_test_options']['stonewright_essential_tools_mode'] = true;
		$result = ( new TaskStart() )->execute(
			[
				'task'         => 'Update an existing post title and excerpt.',
				'surface'      => 'wordpress',
				'intent'       => 'write',
				'responseMode' => 'compact',
			]
		);

		self::assertIsArray( $result );
		$tokens = (int) ceil( strlen( (string) wp_json_encode( $result ) ) / 4 );
		// Baseline before this task was 799 of 800. Adding the digest reference
		// had to be paid for by removing duplicated compact guidance, not by
		// raising the budget.
		self::assertLessThan( TokenSurfaceBudgets::TASK_START_NON_VISUAL_MAX_TOKENS, $tokens );
		self::assertLessThan( 760, $tokens );
	}

	public function test_compact_task_start_drops_duplicated_site_fields(): void {
		$result = ( new TaskStart() )->execute(
			[
				'task'         => 'Update an existing post title and excerpt.',
				'surface'      => 'wordpress',
				'intent'       => 'write',
				'responseMode' => 'compact',
			]
		);

		self::assertIsArray( $result );
		// Both values are already top-level; repeating them under `site` bought
		// nothing and cost budget that the rules digest now uses.
		self::assertArrayNotHasKey( 'write_target_url', $result['site'] );
		self::assertArrayNotHasKey( 'configured_mcp_surface', $result['site'] );
		self::assertNotSame( '', (string) $result['write_target_url'] );
		self::assertNotSame( '', (string) $result['configured_mcp_surface'] );
		self::assertArrayHasKey( 'active_write_target', $result['site'] );
	}

	public function test_compact_expertise_refs_name_their_body_tool_once(): void {
		$result = ( new TaskStart() )->execute(
			[
				'task'         => 'Update an existing post title and excerpt.',
				'surface'      => 'wordpress',
				'intent'       => 'write',
				'responseMode' => 'compact',
			]
		);

		self::assertIsArray( $result );
		self::assertNotSame( [], $result['context']['expertise_refs'] );
		self::assertSame( 'stonewright/expertise-get', $result['context']['expertise_body_tool'] );
		foreach ( $result['context']['expertise_refs'] as $ref ) {
			self::assertArrayHasKey( 'id', $ref );
			self::assertArrayHasKey( 'hash', $ref );
			self::assertArrayNotHasKey( 'body_tool', $ref );
			self::assertArrayNotHasKey( 'status', $ref );
			self::assertArrayNotHasKey( 'activation', $ref );
		}
	}

	public function test_full_task_start_keeps_the_detailed_expertise_refs(): void {
		$result = ( new TaskStart() )->execute(
			[
				'task'         => 'Update an existing post title and excerpt.',
				'surface'      => 'wordpress',
				'intent'       => 'write',
				'responseMode' => 'full',
			]
		);

		self::assertIsArray( $result );
		$packs = $result['context']['expertise_packs'];
		self::assertNotSame( [], $packs );
		self::assertArrayHasKey( 'status', $packs[0] );
		self::assertArrayHasKey( 'activation', $packs[0] );
		self::assertArrayHasKey( 'body_tool', $packs[0] );
	}

	public function test_compact_re_list_instruction_stays_short_but_actionable(): void {
		$result = ( new TaskStart() )->execute(
			[
				'task'         => 'Update an existing post title and excerpt.',
				'surface'      => 'wordpress',
				'intent'       => 'write',
				'responseMode' => 'compact',
			]
		);

		self::assertIsArray( $result );
		$re_list = (string) $result['re_list_instruction'];
		self::assertStringContainsString( 'tools/list', $re_list );
		self::assertLessThan( 160, strlen( $re_list ) );
	}
}
