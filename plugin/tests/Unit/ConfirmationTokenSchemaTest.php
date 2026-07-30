<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\Security\IssueConfirmationToken;

/**
 * @covers \Stonewright\WpMcp\Abilities\Security\IssueConfirmationToken
 */
final class ConfirmationTokenSchemaTest extends TestCase {

	private IssueConfirmationToken $ability;

	protected function setUp(): void {
		$this->ability = new IssueConfirmationToken();
	}

	public function test_name_uses_valid_namespace_pattern(): void {
		$name = $this->ability->name();
		$this->assertSame( 'stonewright/security-issue-confirmation-token', $name );
		$this->assertMatchesRegularExpression( '/^[a-z0-9-]+\/[a-z0-9-]+$/', $name );
	}

	public function test_input_schema_requires_ability(): void {
		$schema = $this->ability->input_schema();
		$this->assertArrayHasKey( 'required', $schema );
		$this->assertContains( 'ability', $schema['required'] );
	}

	public function test_input_schema_does_not_require_intent(): void {
		$schema = $this->ability->input_schema();
		$this->assertNotContains( 'intent', $schema['required'] ?? [] );
	}

	public function test_input_schema_has_ability_string_property(): void {
		$schema = $this->ability->input_schema();
		$this->assertArrayHasKey( 'properties', $schema );
		$this->assertArrayHasKey( 'ability', $schema['properties'] );
		$this->assertSame( 'string', $schema['properties']['ability']['type'] );
	}

	public function test_input_schema_has_args_object_property(): void {
		$schema = $this->ability->input_schema();
		$this->assertArrayHasKey( 'properties', $schema );
		$this->assertArrayHasKey( 'args', $schema['properties'] );
		$this->assertSame( 'object', $schema['properties']['args']['type'] );
	}

	public function test_input_schema_has_optional_ttl_seconds(): void {
		$schema = $this->ability->input_schema();
		$this->assertArrayHasKey( 'properties', $schema );
		$this->assertArrayHasKey( 'ttl_seconds', $schema['properties'] );
		$this->assertSame( 'integer', $schema['properties']['ttl_seconds']['type'] );
		// ttl_seconds should NOT be required.
		$required = $schema['required'] ?? [];
		$this->assertNotContains( 'ttl_seconds', $required );
	}

	public function test_input_schema_has_no_intent_field(): void {
		$schema = $this->ability->input_schema();
		$this->assertArrayNotHasKey( 'intent', $schema['properties'] ?? [] );
	}

	public function test_output_schema_returns_token_and_expires_at(): void {
		$schema = $this->ability->output_schema();
		$this->assertArrayHasKey( 'properties', $schema );
		$this->assertArrayHasKey( 'token', $schema['properties'] );
		$this->assertArrayHasKey( 'expires_at', $schema['properties'] );
	}
}
