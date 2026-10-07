<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\SkillLibrary;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Elementor\Schema\RuntimeFingerprint;
use Stonewright\WpMcp\SkillLibrary\DocumentCodec;
use Stonewright\WpMcp\SkillLibrary\ProviderRequirement;
use Stonewright\WpMcp\SkillLibrary\RecordRules;
use Stonewright\WpMcp\SkillLibrary\VisibilityRules;

/**
 * `requires_provider` front matter: accepted values, compilation into the visibility
 * constraints, validation in the codec and the lint, and runtime behavior.
 *
 * @covers \Stonewright\WpMcp\SkillLibrary\ProviderRequirement
 * @covers \Stonewright\WpMcp\SkillLibrary\DocumentCodec
 * @covers \Stonewright\WpMcp\SkillLibrary\VisibilityRules
 * @covers \Stonewright\WpMcp\SkillLibrary\RecordRules
 */
final class ProviderRequirementTest extends TestCase {

	protected function tearDown(): void {
		ProviderRequirement::use_resolver( null );
	}

	public function test_the_only_accepted_provider_is_elementor_native(): void {
		self::assertSame( [ 'elementor-native' ], ProviderRequirement::IDS );
		self::assertTrue( ProviderRequirement::is_known( 'elementor-native' ) );
		foreach ( [ '', 'Elementor-Native', 'elementor', 'elementor-native ', 'unknown', 'elementor-native|acf' ] as $id ) {
			self::assertFalse( ProviderRequirement::is_known( $id ), $id );
		}
		self::assertSame( 'provider:elementor-native', ProviderRequirement::component( 'elementor-native' ) );
		self::assertSame( 'elementor-native', ProviderRequirement::id_of( 'provider:elementor-native' ) );
		self::assertNull( ProviderRequirement::id_of( 'elementor' ) );
	}

	public function test_front_matter_key_is_read_and_compiled_into_the_visibility_constraints(): void {
		$record = DocumentCodec::read( "---\nname: Example\ndescription: Use when writing examples.\nrequires_provider: elementor-native\n---\n# Body\n" );

		self::assertIsArray( $record );
		self::assertSame( 'elementor-native', $record['metadata']['requires_provider'] );
		self::assertSame( [ 'provider:elementor-native' => 'required' ], $record['metadata']['version_constraints'] );
		self::assertSame( "# Body\n", $record['content'] );
	}

	public function test_the_compiled_constraint_merges_with_existing_component_constraints(): void {
		$record = DocumentCodec::read( "---\nname: Example\ndescription: Use when writing examples.\nversion_constraints: {\"elementor\": \">=4.3\"}\nrequires_provider: \"elementor-native\"\n---\nBody\n" );

		self::assertIsArray( $record );
		self::assertSame( [ 'elementor' => '>=4.3', 'provider:elementor-native' => 'required' ], $record['metadata']['version_constraints'] );
	}

	public function test_a_document_without_the_key_is_unchanged(): void {
		$record = DocumentCodec::read( "---\nname: Example\ndescription: Use when writing examples.\n---\nBody\n" );

		self::assertIsArray( $record );
		self::assertArrayNotHasKey( 'requires_provider', $record['metadata'] );
		self::assertArrayNotHasKey( 'version_constraints', $record['metadata'] );
	}

	/** @return array<string,array{0:string}> */
	public static function invalid_documents(): array {
		$head = "---\nname: Example\ndescription: Use when writing examples.\n";
		return [
			'unknown provider'          => [ $head . "requires_provider: unknown-provider\n---\nBody" ],
			'wrong case'                => [ $head . "requires_provider: Elementor-Native\n---\nBody" ],
			'plugin slug'               => [ $head . "requires_provider: elementor\n---\nBody" ],
			'alternatives'              => [ $head . "requires_provider: elementor-native|acf\n---\nBody" ],
			'empty'                     => [ $head . "requires_provider:\n---\nBody" ],
			'flow list'                 => [ $head . "requires_provider: [elementor-native]\n---\nBody" ],
			'repeated key'              => [ $head . "requires_provider: elementor-native\nrequires_provider: elementor-native\n---\nBody" ],
			'conflicting constraint'    => [ $head . "version_constraints: {\"provider:elementor-native\": \">=1.0\"}\nrequires_provider: elementor-native\n---\nBody" ],
			'unknown provider constraint' => [ $head . "version_constraints: {\"provider:unknown-provider\": \"required\"}\n---\nBody" ],
			'provider version expression' => [ $head . "version_constraints: {\"provider:elementor-native\": \">=1.0\"}\n---\nBody" ],
		];
	}

	/** @dataProvider invalid_documents */
	public function test_invalid_provider_requirements_are_rejected_by_the_codec( string $markdown ): void {
		$result = DocumentCodec::read( $markdown );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_skill_document_invalid', $result->get_error_code() );
	}

	public function test_an_exported_provider_constraint_reads_back_without_the_key(): void {
		$record = DocumentCodec::read( "---\nname: Example\ndescription: Use when writing examples.\nversion_constraints: {\"provider:elementor-native\": \"required\"}\n---\nBody\n" );

		self::assertIsArray( $record );
		self::assertSame( [ 'provider:elementor-native' => 'required' ], $record['metadata']['version_constraints'] );
		self::assertArrayNotHasKey( 'requires_provider', $record['metadata'] );
	}

	public function test_visibility_rules_accept_only_known_provider_components_with_the_required_expression(): void {
		self::assertTrue( VisibilityRules::well_formed( [ 'provider:elementor-native' => 'required' ] ) );
		self::assertTrue( VisibilityRules::well_formed( [ 'elementor' => '>=4.3', 'provider:elementor-native' => 'required' ] ) );
		self::assertFalse( VisibilityRules::well_formed( [ 'provider:unknown-provider' => 'required' ] ) );
		self::assertFalse( VisibilityRules::well_formed( [ 'provider:elementor-native' => '>=1.0' ] ) );
		self::assertFalse( VisibilityRules::well_formed( [ 'provider:' => 'required' ] ) );
		self::assertFalse( VisibilityRules::well_formed( [ 'provider:elementor-native|acf' => 'required' ] ) );
		self::assertFalse( VisibilityRules::well_formed( [ 'any_of' => 'provider:elementor-native|acf' ] ) );
		self::assertFalse( VisibilityRules::well_formed( [ 'other:elementor-native' => 'required' ] ) );
	}

	public function test_a_skill_requiring_an_absent_provider_stays_hidden_and_names_the_requirement(): void {
		$record = [ 'enabled' => true, 'enable_agentic' => true, 'enable_prompt' => true, 'status' => 'active', 'version_constraints' => [ 'provider:elementor-native' => 'required' ] ];
		$absent = static fn( array $constraints ): bool => false;
		$present = static fn( array $constraints ): bool => true;

		self::assertSame( [ 'provider:elementor-native' ], VisibilityRules::missing( $record, $absent ) );
		self::assertFalse( VisibilityRules::eligible( $record, 'agentic', $absent ) );
		self::assertFalse( VisibilityRules::eligible( $record, 'prompt', $absent ) );
		self::assertTrue( VisibilityRules::eligible( $record, 'all', $present ) );
		self::assertTrue( VisibilityRules::eligible( $record, 'agentic', $present ) );
	}

	public function test_the_record_rules_accept_the_requirement_and_never_make_it_a_blocking_finding(): void {
		$record = RecordRules::normalize( [ 'slug' => 'example', 'title' => 'Example', 'description' => 'Use when writing examples.', 'content' => '# Example', 'version_constraints' => [ 'provider:elementor-native' => 'required' ] ] );

		self::assertIsArray( $record );
		self::assertSame( [ 'provider:elementor-native' => 'required' ], $record['version_constraints'] );
		self::assertSame( [], RecordRules::review( $record )['errors'] );
	}

	public function test_the_record_rules_reject_an_unknown_provider_requirement(): void {
		$result = RecordRules::normalize( [ 'slug' => 'example', 'title' => 'Example', 'content' => '# Example', 'version_constraints' => [ 'provider:unknown-provider' => 'required' ] ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_skill_record_invalid', $result->get_error_code() );
	}

	public function test_lint_flags_a_stored_record_with_an_unknown_provider_requirement(): void {
		$record = [ 'slug' => 'example', 'title' => 'Example', 'description' => 'Use when writing examples.', 'content' => '# Example', 'version_constraints' => [ 'provider:unknown-provider' => 'required', 'provider:elementor-native' => '>=1.0' ] ];

		$errors = RecordRules::review( $record )['errors'];

		self::assertContains( 'invalid_provider_requirement:provider:unknown-provider', $errors );
		self::assertContains( 'invalid_provider_requirement:provider:elementor-native', $errors );
		self::assertSame( [], RecordRules::review( [ 'slug' => 'example', 'title' => 'Example', 'description' => 'Use when writing examples.', 'content' => '# Example', 'version_constraints' => [ 'provider:elementor-native' => 'required' ] ] )['errors'] );
	}

	public function test_runtime_matching_resolves_provider_components_through_the_resolver(): void {
		ProviderRequirement::use_resolver( static fn( string $id ): bool => 'elementor-native' === $id );

		self::assertTrue( RuntimeFingerprint::matches_constraints( [ 'provider:elementor-native' => 'required' ] ) );
		self::assertTrue( RuntimeFingerprint::matches_constraints( [ 'provider:elementor-native' => 'Required' ] ) );
		self::assertFalse( RuntimeFingerprint::matches_constraints( [ 'provider:elementor-native' => '>=1.0' ] ) );
		self::assertFalse( RuntimeFingerprint::matches_constraints( [ 'provider:unknown-provider' => 'required' ] ) );

		ProviderRequirement::use_resolver( static fn( string $id ): bool => false );
		self::assertFalse( RuntimeFingerprint::matches_constraints( [ 'provider:elementor-native' => 'required' ] ) );
		self::assertTrue( RuntimeFingerprint::matches_constraints( [] ) );
	}

	/** @return array<string,array{0:array<string,mixed>,1:bool}> */
	public static function native_states(): array {
		return [
			'available'             => [ [ 'state' => 'available' ], true ],
			'available uncertified' => [ [ 'state' => 'available_uncertified' ], true ],
			'no abilities'          => [ [ 'state' => 'no_abilities_registered' ], false ],
			'exposure disabled'     => [ [ 'state' => 'exposure_disabled' ], false ],
			'requirements missing'  => [ [ 'state' => 'requirements_missing' ], false ],
			'module unavailable'    => [ [ 'state' => 'module_unavailable' ], false ],
			'not installed'         => [ [ 'state' => 'not_installed' ], false ],
			'malformed'             => [ [], false ],
		];
	}

	/** @dataProvider native_states */
	public function test_elementor_native_is_present_exactly_when_its_abilities_are_registered( array $report, bool $expected ): void {
		self::assertSame( $expected, ProviderRequirement::provided_by( 'elementor-native', $report ) );
		self::assertFalse( ProviderRequirement::provided_by( 'unknown-provider', $report ) );
	}

	public function test_without_a_runtime_the_live_check_reports_the_provider_absent(): void {
		self::assertFalse( ProviderRequirement::present( 'unknown-provider' ) );
		self::assertIsBool( ProviderRequirement::present( 'elementor-native' ) );
	}
}
