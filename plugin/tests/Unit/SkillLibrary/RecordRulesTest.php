<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\SkillLibrary;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\SkillLibrary\RecordRules;

/** @covers \Stonewright\WpMcp\SkillLibrary\RecordRules */
final class RecordRulesTest extends TestCase {

	public function test_updates_preserve_omitted_site_metadata_and_flags(): void {
		$previous = [ 'id' => 42, 'slug' => 'example', 'title' => 'Example', 'description' => 'Use when writing examples.', 'content' => '# Example', 'enabled' => false, 'enable_agentic' => false, 'enable_prompt' => true, 'status' => 'draft', 'topic' => 'Example topic', 'version_constraints' => [ 'elementor' => 'required' ], 'private_extension' => [ 'synthetic' => true ] ];
		$record = RecordRules::normalize( [ 'slug' => 'example', 'title' => 'Updated example', 'content' => '# Updated' ], $previous );
		$this->assertIsArray( $record );
		$this->assertFalse( $record['enabled'] );
		$this->assertFalse( $record['enable_agentic'] );
		$this->assertTrue( $record['enable_prompt'] );
		$this->assertSame( 'draft', $record['status'] );
		$this->assertSame( 'Example topic', $record['topic'] );
		$this->assertSame( [ 'elementor' => 'required' ], $record['version_constraints'] );
		$this->assertSame( [ 'synthetic' => true ], $record['private_extension'] );
	}

	public function test_master_and_exposure_flags_remain_independent(): void {
		$record = RecordRules::normalize( [ 'slug' => 'example', 'title' => 'Example', 'description' => 'Use when writing examples.', 'content' => '# Example', 'enabled' => true, 'enable_agentic' => false ] );
		$this->assertIsArray( $record );
		$this->assertTrue( $record['enabled'] );
		$this->assertFalse( $record['enable_agentic'] );
		$this->assertTrue( $record['enable_prompt'] );
		$this->assertSame( 'active', $record['status'] );
	}

	public function test_rejects_sensitive_material_before_a_record_can_be_stored(): void {
		$record = RecordRules::normalize( [ 'slug' => 'example', 'title' => 'Example', 'content' => '-----BEGIN ' . 'PRIVATE KEY-----' ] );
		$this->assertInstanceOf( \WP_Error::class, $record );
		$this->assertSame( 'stonewright_skill_sensitive_content', $record->get_error_code() );
	}

	/** @dataProvider credential_leaves */
	public function test_credentials_are_found_in_every_raw_text_key_and_leaf( array $metadata ): void {
		$record = RecordRules::normalize( $metadata + [ 'slug' => 'example', 'title' => 'Example', 'content' => '# Example' ] );
		$this->assertInstanceOf( \WP_Error::class, $record );
		$this->assertSame( 'stonewright_skill_sensitive_content', $record->get_error_code() );
	}

	public static function credential_leaves(): array {
		$address = 'https://admin:' . 'pw12345@example.com';
		return [
			'address in body' => [ [ 'content' => 'Open ' . $address . ' first.' ] ],
			'address in description' => [ [ 'description' => 'Use ' . $address . ' for examples.' ] ],
			'address in nested metadata' => [ [ 'private_extension' => [ 'endpoint' => [ $address ] ] ] ],
			'address as a metadata key' => [ [ 'private_extension' => [ $address => true ] ] ],
			'bearer value with slashes' => [ [ 'content' => 'Send Bearer ab/' . 'cdefghijklmnop with the request.' ] ],
			'labelled value in metadata' => [ [ 'private_extension' => [ 'api_key' => 'sk-example-' . '0123456789' ] ] ],
			'labelled list in metadata' => [ [ 'private_extension' => [ 'password' => [ 'hunter2-' . 'example' ] ] ] ],
		];
	}

	public function test_a_label_ending_one_line_is_not_joined_to_the_following_lines(): void {
		$record = RecordRules::normalize( [ 'slug' => 'example', 'title' => 'Example', 'content' => "Ask for the confirmation token:\n\n```\n\"Confirm: replace the example section\"\n```\n" ] );
		$this->assertIsArray( $record );
	}

	public function test_lint_reports_unclear_triggers_conflicts_and_missing_tools(): void {
		$review = RecordRules::review( [ 'description' => '', 'content' => 'Use `stonewright/example-missing`.', 'conflicts' => [ 'synthetic conflict' ], 'status' => 'stale' ], [ 'stonewright/ping' ] );
		$this->assertContains( 'missing_trigger', $review['errors'] );
		$this->assertContains( 'unresolved_conflicts', $review['errors'] );
		$this->assertContains( 'stale_record', $review['errors'] );
		$this->assertContains( 'unavailable_tool:stonewright/example-missing', $review['errors'] );
	}

	public function test_elementor_guidance_requires_runtime_constraints(): void {
		$record = [ 'description' => 'Use when editing Elementor layouts.', 'content' => '# Example' ];
		$this->assertContains( 'missing_version_constraints', RecordRules::review( $record )['errors'] );
		$record['version_constraints'] = [ 'elementor' => 'required' ];
		$this->assertSame( [], RecordRules::review( $record )['errors'] );
	}

	public function test_unknown_tool_catalog_cannot_prove_referenced_tools_exist(): void {
		$review = RecordRules::review( [ 'description' => 'Use when writing examples.', 'content' => 'Call `stonewright/example-missing`.' ] );
		$this->assertContains( 'unavailable_tool:stonewright/example-missing', $review['errors'] );
	}

	/** @dataProvider multilingual_descriptions */
	public function test_descriptive_triggers_are_not_restricted_to_english( string $description ): void {
		$review = RecordRules::review( [ 'description' => $description, 'content' => '# Synthetic example' ] );
		$this->assertNotContains( 'missing_trigger', $review['errors'] );
	}

	public static function multilingual_descriptions(): array {
		return [
			[ 'Folosește pentru construirea paginilor cu exemple.' ],
			[ '创建和编辑示例页面时使用。' ],
			[ 'استخدمه عند إنشاء صفحات تجريبية.' ],
			[ 'Úsalo al crear páginas de ejemplo.' ],
			[ 'Build example pages and preserve the current site rules.' ],
		];
	}

	public function test_public_product_descriptions_are_accepted_independent_of_language(): void {
		$inventory = \Stonewright\WpMcp\SkillLibrary\PackInventory::scan( dirname( STONEWRIGHT_DIR ) . '/skills' );
		$this->assertIsArray( $inventory );
		$this->assertSame( [], $inventory['diagnostics'] );
		$this->assertNotEmpty( $inventory['entries'] );
		foreach ( $inventory['entries'] as $entry ) {
			$this->assertNotContains( 'missing_trigger', RecordRules::review( $entry['record'] )['errors'], $entry['pack_key'] );
		}
	}

	public function test_a_host_can_supply_a_more_specific_trigger_policy(): void {
		$review = RecordRules::review( [ 'description' => 'Vague.', 'content' => '# Synthetic example' ], [], static fn( string $description ): bool => false );
		$this->assertContains( 'missing_trigger', $review['errors'] );
		$accepted = RecordRules::review( [ 'description' => 'Vague.', 'content' => '# Synthetic example' ], [], static fn( string $description ): bool => true );
		$this->assertNotContains( 'missing_trigger', $accepted['errors'] );
	}

	/** @dataProvider malformed_metadata */
	public function test_known_metadata_types_cannot_cross_the_logical_boundary( array $metadata ): void {
		$record = RecordRules::normalize( $metadata + [ 'slug' => 'example', 'title' => 'Example', 'content' => '# Example' ] );
		$this->assertInstanceOf( \WP_Error::class, $record );
	}

	public static function malformed_metadata(): array {
		return [
			'null flag' => [ [ 'enabled' => null ] ],
			'null topic' => [ [ 'topic' => null ] ],
			'null state' => [ [ 'status' => null ] ],
			'null source' => [ [ 'source' => null ] ],
			'topic object' => [ [ 'topic' => [ 'unsafe' ] ] ],
			'constraints text' => [ [ 'version_constraints' => 'elementor' ] ],
			'constraints nested object' => [ [ 'version_constraints' => [ 'elementor' => [ '>=3.16' ] ] ] ],
			'constraint empty' => [ [ 'version_constraints' => [ 'elementor' => '' ] ] ],
			'negative verification' => [ [ 'verification_count' => -1 ] ],
			'float verification' => [ [ 'verification_count' => 1.5 ] ],
			'conflict text' => [ [ 'conflicts' => 'unresolved' ] ],
			'conflict object' => [ [ 'conflicts' => [ [ 'unresolved' ] ] ] ],
			'fingerprint invalid' => [ [ 'semantic_fingerprint' => 'invalid' ] ],
			'fingerprint with trailing newline' => [ [ 'semantic_fingerprint' => str_repeat( 'a', 64 ) . "\n" ] ],
			'constraint name with trailing newline' => [ [ 'version_constraints' => [ "elementor\n" => 'required' ] ] ],
			'any_of with an empty alternative' => [ [ 'version_constraints' => [ 'any_of' => 'acf||pods' ] ] ],
		];
	}

	/** @dataProvider stored_constraint_shapes */
	public function test_stored_constraint_shapes_are_accepted( array $constraints ): void {
		$record = RecordRules::normalize( [ 'slug' => 'example', 'title' => 'Example', 'content' => '# Example', 'version_constraints' => $constraints ] );
		$this->assertIsArray( $record );
		$this->assertSame( $constraints, $record['version_constraints'] );
	}

	public static function stored_constraint_shapes(): array {
		return [
			'no constraint' => [ [] ],
			'required plugin or version expression' => [ [ 'elementor' => 'required', 'woocommerce' => '>=8.0' ] ],
			'any one listed plugin' => [ [ 'any_of' => 'acf|acpt|pods' ] ],
			'digit-leading plugin slugs' => [ [ '3d-viewer' => 'required', 'any_of' => '2fa-guard|redirection' ] ],
		];
	}
}
