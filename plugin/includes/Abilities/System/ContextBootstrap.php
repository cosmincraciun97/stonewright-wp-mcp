<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\System;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Context\ContextBuilder;
use Stonewright\WpMcp\Security\Permissions;

/**
 * Mandatory task bootstrap: returns instructions, matched skills, memory, and
 * a short-lived context token required by write abilities.
 *
 * @stonewright-status stable
 */
final class ContextBootstrap extends AbilityKernel {

	public function name(): string {
		return 'stonewright/context-bootstrap';
	}

	public function label(): string {
		return __( 'Bootstrap agent context', 'stonewright' );
	}

	public function description(): string {
		return __( 'MUST be called at the start of every Stonewright task. Returns current instructions, matched skill playbooks, relevant persistent memory, required follow-up actions, and a context token for write abilities.', 'stonewright' );
	}

	public function category(): string {
		return 'system';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => [ 'task' ],
			'properties'           => [
				'task'    => [
					'type'        => 'string',
					'minLength'   => 1,
					'description' => 'The user request or task summary.',
				],
				'surface' => [
					'type'        => 'string',
					'default'     => 'unknown',
					'description' => 'Primary work surface, e.g. elementor, gutenberg, wordpress, acf, cpt-ui.',
				],
				'intent'  => [
					'type'        => 'string',
					'default'     => 'unknown',
					'description' => 'Task intent, e.g. read, write, delete, debug.',
				],
			],
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'required'   => [ 'ok', 'context_token', 'instructions', 'mcp_tool_naming', 'matched_skill_playbooks', 'memory_entries', 'recommended_external_mcps', 'visual_quality_contract', 'required_followups' ],
			'properties' => [
				'ok'                      => [ 'type' => 'boolean' ],
				'context_token'           => [ 'type' => 'string' ],
				'expires_at'              => [ 'type' => 'string' ],
				'instructions'            => [ 'type' => 'string' ],
				'mcp_tool_naming'         => [ 'type' => 'object' ],
				'matched_skills'          => [ 'type' => 'array' ],
				'matched_skill_playbooks' => [ 'type' => 'array' ],
				'memory_entries'          => [ 'type' => 'array' ],
				'recommended_external_mcps' => [ 'type' => 'array' ],
				'visual_quality_contract' => [ 'type' => 'object' ],
				'required_followups'      => [ 'type' => 'array' ],
			],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::read();
	}

	public function execute( array $args ): array|\WP_Error {
		$task = isset( $args['task'] ) && is_string( $args['task'] ) ? trim( $args['task'] ) : '';
		if ( '' === $task ) {
			return $this->error( 'missing_task', __( 'A non-empty task is required.', 'stonewright' ), [ 'status' => 400 ] );
		}

		$surface = isset( $args['surface'] ) && is_string( $args['surface'] ) ? strtolower( trim( $args['surface'] ) ) : 'unknown';
		$intent  = isset( $args['intent'] ) && is_string( $args['intent'] ) ? strtolower( trim( $args['intent'] ) ) : 'unknown';

		return ContextBuilder::build(
			$task,
			'' !== $surface ? $surface : 'unknown',
			'' !== $intent ? $intent : 'unknown'
		);
	}
}
