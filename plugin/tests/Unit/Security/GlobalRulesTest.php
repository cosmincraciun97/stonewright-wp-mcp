<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Stonewright\WpMcp\Security\GlobalRules;

/**
 * The registry is the single source of truth for site-agnostic operating rules.
 *
 * These tests defend three properties that are easy to erode later:
 * 1. Rule text stays portable — no host, URL, or site-local record id.
 * 2. `hard` means a real runtime guard exists, so a violation actually fails.
 * 3. The digest is content-addressed, so clients can cache rule bodies.
 */
final class GlobalRulesTest extends TestCase {

	/**
	 * Rules that PHP cannot mechanically enforce inside the plugin or the
	 * companion. Claiming runtime enforcement for these would be a lie the
	 * audit log then repeats, so they must stay `strong`.
	 *
	 * @var list<string>
	 */
	private const INSTRUCTION_ONLY_IDS = [
		'read-before-write',
		'verify-after-write',
		'no-schema-guessing',
		'no-transport-workarounds',
		'missing-tool-means-stop',
		'record-corrections',
		'never-trade-gates-for-tokens',
		'elementor-transaction-discipline',
		'responsive-semantic-widget',
		'verified-content-model',
		'asset-source-integrity',
		'query-hook-non-reentrant',
		'temporary-code-lifecycle',
		'dynamic-architecture-preservation',
		'native-controls-rendered-proof',
		'custom-code-human-handoff',
		'elementor-native-responsive-visibility',
	];

	public function test_generalized_operating_repairs_live_in_the_digest_registry(): void {
		foreach ( array_slice( self::INSTRUCTION_ONLY_IDS, -8 ) as $id ) {
			self::assertIsArray( GlobalRules::get( $id ), $id . ' must ship in Plugin and Direct mode.' );
		}
	}

	protected function setUp(): void {
		parent::setUp();
		GlobalRules::reset_cache();
	}

	protected function tearDown(): void {
		GlobalRules::reset_cache();
		parent::tearDown();
	}

	public function test_registry_loads_records_with_the_required_shape(): void {
		$rules = GlobalRules::all();

		self::assertNotEmpty( $rules );
		self::assertTrue( array_is_list( $rules ) );

		foreach ( $rules as $rule ) {
			self::assertSame(
				[ 'id', 'severity', 'scope', 'rule', 'why', 'enforcement' ],
				array_keys( $rule ),
				'Rule records must use the canonical key order.'
			);
			self::assertNotSame( '', $rule['rule'] );
			self::assertNotSame( '', $rule['why'] );
			self::assertSame( [ 'kind', 'guard' ], array_keys( $rule['enforcement'] ) );
		}
	}

	public function test_ids_are_unique_lowercase_slugs(): void {
		$ids = GlobalRules::ids();

		self::assertSame( array_unique( $ids ), $ids, 'Rule ids must be unique.' );
		self::assertSame( $ids, array_column( GlobalRules::all(), 'id' ) );

		foreach ( $ids as $id ) {
			self::assertMatchesRegularExpression( '/^[a-z][a-z0-9]*(?:-[a-z0-9]+)*$/', $id );
		}
	}

	public function test_severities_and_scopes_come_from_the_allowed_sets(): void {
		foreach ( GlobalRules::all() as $rule ) {
			self::assertContains( $rule['severity'], GlobalRules::SEVERITIES );
			self::assertContains( $rule['scope'], GlobalRules::SCOPES );
			self::assertContains( $rule['enforcement']['kind'], GlobalRules::ENFORCEMENT_KINDS );
		}
	}

	public function test_hard_rules_declare_a_concrete_runtime_guard(): void {
		$hard = GlobalRules::ids_for_severity( 'hard' );
		self::assertNotEmpty( $hard, 'At least one rule must be runtime-enforced.' );

		foreach ( $hard as $id ) {
			$rule = GlobalRules::get( $id );
			self::assertIsArray( $rule );
			self::assertSame( 'runtime', $rule['enforcement']['kind'], $id . ' is hard but not runtime-enforced.' );
			self::assertMatchesRegularExpression(
				'/^[a-z][a-z0-9_]*$/',
				$rule['enforcement']['guard'],
				$id . ' is hard but names no concrete guard.'
			);
		}
	}

	public function test_non_runtime_rules_declare_no_guard(): void {
		foreach ( GlobalRules::all() as $rule ) {
			if ( 'runtime' === $rule['enforcement']['kind'] ) {
				continue;
			}

			self::assertSame( '', $rule['enforcement']['guard'], $rule['id'] . ' claims a guard it does not have.' );
			self::assertNotSame( 'hard', $rule['severity'], $rule['id'] . ' cannot be hard without a runtime guard.' );
		}
	}

	public function test_agent_instructions_are_not_advertised_as_runtime_enforced(): void {
		foreach ( self::INSTRUCTION_ONLY_IDS as $id ) {
			$rule = GlobalRules::get( $id );
			self::assertIsArray( $rule, $id . ' is missing from the registry.' );
			self::assertSame( 'instruction', $rule['enforcement']['kind'], $id . ' cannot claim runtime enforcement.' );
			self::assertSame( 'strong', $rule['severity'], $id . ' must remain strong.' );
		}
	}

	public function test_rule_text_never_names_a_host_url_or_site_local_id(): void {
		foreach ( GlobalRules::all() as $rule ) {
			$text = $rule['rule'] . ' ' . $rule['why'];

			self::assertDoesNotMatchRegularExpression( '#[a-z]+://#i', $text, $rule['id'] . ' contains a URL.' );
			self::assertDoesNotMatchRegularExpression( '#\bwww\.#i', $text, $rule['id'] . ' contains a host.' );
			self::assertDoesNotMatchRegularExpression(
				'#\b[a-z0-9-]+\.(?:ro|com|net|org|io|dev|co|eu|uk|local|test|site)\b#i',
				$text,
				$rule['id'] . ' contains a host name.'
			);
			self::assertDoesNotMatchRegularExpression( '#\b\d{2,}\b#', $text, $rule['id'] . ' contains a site-local record id.' );
		}
	}

	public function test_ids_for_severity_filters_and_preserves_order(): void {
		$expected = [];
		foreach ( GlobalRules::all() as $rule ) {
			if ( 'strong' === $rule['severity'] ) {
				$expected[] = $rule['id'];
			}
		}

		self::assertSame( $expected, GlobalRules::ids_for_severity( 'strong' ) );
		self::assertSame( [], GlobalRules::ids_for_severity( 'not-a-severity' ) );
	}

	public function test_get_returns_a_record_or_null(): void {
		self::assertNull( GlobalRules::get( 'no-such-rule' ) );

		$first = GlobalRules::ids()[0];
		$rule  = GlobalRules::get( $first );
		self::assertIsArray( $rule );
		self::assertSame( $first, $rule['id'] );
	}

	public function test_digest_is_content_addressed(): void {
		$one = [ $this->fixture_rule( 'alpha-rule' ) ];
		$two = [ $this->fixture_rule( 'beta-rule' ) ];

		self::assertSame( GlobalRules::digest_of( $one ), GlobalRules::digest_of( $one ) );
		self::assertNotSame( GlobalRules::digest_of( $one ), GlobalRules::digest_of( $two ) );
		self::assertMatchesRegularExpression( '/^[0-9a-f]{40}$/', GlobalRules::digest_of( $one ) );
	}

	public function test_digest_ignores_insignificant_json_whitespace(): void {
		$rules = [ $this->fixture_rule( 'alpha-rule' ) ];
		$dense = $this->write_fixture( 'dense.json', (string) json_encode( $rules ) );
		$pretty = $this->write_fixture( 'pretty.json', (string) json_encode( $rules, JSON_PRETTY_PRINT ) );

		self::assertSame(
			GlobalRules::digest_of( GlobalRules::load_from( $dense ) ),
			GlobalRules::digest_of( GlobalRules::load_from( $pretty ) )
		);
	}

	public function test_registry_digest_matches_the_shipped_records(): void {
		self::assertSame( GlobalRules::digest_of( GlobalRules::all() ), GlobalRules::digest() );
	}

	public function test_all_is_cached_within_one_request(): void {
		$first = GlobalRules::all();
		self::assertSame( $first, GlobalRules::all() );
	}

	public function test_shipped_registry_file_is_readable_at_the_packaged_path(): void {
		$path = GlobalRules::path();

		self::assertFileExists( $path, 'The registry must ship inside the plugin directory.' );
		self::assertStringContainsString(
			'plugin/data/global-rules.json',
			str_replace( '\\', '/', $path ),
			'The registry must live under plugin/data so release packaging includes it.'
		);
	}

	public function test_missing_file_fails_loudly(): void {
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'global rule registry' );

		GlobalRules::load_from( $this->fixture_dir() . '/does-not-exist.json' );
	}

	public function test_malformed_json_fails_loudly(): void {
		$path = $this->write_fixture( 'malformed.json', '{ "id": ' );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'not valid JSON' );

		GlobalRules::load_from( $path );
	}

	public function test_non_list_payload_fails_loudly(): void {
		$path = $this->write_fixture( 'not-a-list.json', '{"rules":[]}' );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'list of rule records' );

		GlobalRules::load_from( $path );
	}

	public function test_unknown_severity_fails_loudly(): void {
		$rule             = $this->fixture_rule( 'alpha-rule' );
		$rule['severity'] = 'urgent';
		$path             = $this->write_fixture( 'bad-severity.json', (string) json_encode( [ $rule ] ) );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'severity' );

		GlobalRules::load_from( $path );
	}

	public function test_hard_rule_without_a_guard_fails_loudly(): void {
		$rule                = $this->fixture_rule( 'alpha-rule' );
		$rule['severity']    = 'hard';
		$rule['enforcement'] = [
			'kind'  => 'instruction',
			'guard' => '',
		];
		$path                = $this->write_fixture( 'hard-without-guard.json', (string) json_encode( [ $rule ] ) );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'runtime guard' );

		GlobalRules::load_from( $path );
	}

	public function test_duplicate_ids_fail_loudly(): void {
		$rule = $this->fixture_rule( 'alpha-rule' );
		$path = $this->write_fixture( 'duplicate-ids.json', (string) json_encode( [ $rule, $rule ] ) );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'duplicate' );

		GlobalRules::load_from( $path );
	}

	public function test_missing_key_fails_loudly(): void {
		$rule = $this->fixture_rule( 'alpha-rule' );
		unset( $rule['why'] );
		$path = $this->write_fixture( 'missing-key.json', (string) json_encode( [ $rule ] ) );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'why' );

		GlobalRules::load_from( $path );
	}

	/**
	 * @return array{id: string, severity: string, scope: string, rule: string, why: string, enforcement: array{kind: string, guard: string}}
	 */
	private function fixture_rule( string $id ): array {
		return [
			'id'          => $id,
			'severity'    => 'strong',
			'scope'       => 'all',
			'rule'        => 'Do the safe thing.',
			'why'         => 'Because the unsafe thing costs a rollback.',
			'enforcement' => [
				'kind'  => 'instruction',
				'guard' => '',
			],
		];
	}

	private function fixture_dir(): string {
		$dir = sys_get_temp_dir() . '/stonewright-global-rules-test';
		if ( ! is_dir( $dir ) ) {
			mkdir( $dir, 0o777, true );
		}

		return $dir;
	}

	private function write_fixture( string $name, string $contents ): string {
		$path = $this->fixture_dir() . '/' . $name;
		file_put_contents( $path, $contents );

		return $path;
	}
}
