<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\System;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Abilities\ElementorV3\CapabilitiesSummary;
use Stonewright\WpMcp\Core\AbilityRegistry;
use Stonewright\WpMcp\Context\ContextBuilder;
use Stonewright\WpMcp\Security\Permissions;

/**
 * One-call task preflight for faster, lower-token Stonewright workflows.
 *
 * @stonewright-status stable
 */
final class WorkflowPreflight extends AbilityKernel {

	public function name(): string {
		return 'stonewright/workflow-preflight';
	}

	public function label(): string {
		return __( 'Stonewright workflow preflight', 'stonewright' );
	}

	public function description(): string {
		return __( 'Returns compact task context, auth guidance, mode, and first-pass tool choices so MCP agents can start with fewer discovery calls.', 'stonewright' );
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
					'description' => 'Primary work surface, e.g. elementor, gutenberg, wordpress, wp-cli.',
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
			'properties' => [
				'ok'            => [ 'type' => 'boolean' ],
				'context_token' => [ 'type' => 'string' ],
				'expires_at'    => [ 'type' => 'string' ],
				'mode'          => [ 'type' => 'string' ],
				'auth_guidance' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
				'fast_path'     => [ 'type' => 'object' ],
				'elementor'     => [ 'type' => 'object' ],
				'site'          => [ 'type' => 'object' ],
				'context'       => [ 'type' => 'object' ],
			],
			'required'   => [ 'ok', 'context_token', 'mode', 'auth_guidance', 'fast_path' ],
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
		$context = ContextBuilder::build(
			$task,
			'' !== $surface ? $surface : 'unknown',
			'' !== $intent ? $intent : 'unknown'
		);

		$elementor = ( new CapabilitiesSummary() )->execute( [] );
		if ( is_wp_error( $elementor ) ) {
			return $elementor;
		}

		return [
			'ok'            => true,
			'context_token' => (string) ( $context['context_token'] ?? '' ),
			'expires_at'    => (string) ( $context['expires_at'] ?? '' ),
			'mode'          => (string) get_option( 'stonewright_mode', 'development' ),
			'auth_guidance' => [
				'Use a WordPress Application Password for HTTP MCP authentication.',
				'Keep the Mcp-Session-Id response header on later JSON-RPC calls.',
				'Use MCP tool names with hyphens, for example stonewright-context-bootstrap.',
			],
			'fast_path'     => [
				'recommended_tools' => [
					'stonewright/workflow-preflight',
					'stonewright/media-upload-batch',
					'stonewright/elementor-v3-capabilities-summary',
					'stonewright/elementor-v3-build-page-from-spec',
					'stonewright/elementor-v3-apply-bundle',
				],
				'visual_setup'       => [
					'Install external Playwright MCP before visual work and restart the AI client so the browser tools appear.',
					'Verify the Playwright/browser tool can open the target URL before the first Stonewright write.',
				],
				'batching_rules'     => [
					'Build the first page pass with stonewright/elementor-v3-build-page-from-spec or stonewright/elementor-v3-apply-bundle; avoid dozens of single-widget calls for repeated cards.',
					'Use stonewright/media-upload-batch for multiple assets instead of one upload call per image.',
					'Use individual add/update/move calls only for surgical fixes after screenshot comparison.',
				],
				'quality_gates'      => [
					'Stop before writing if a visual task has no connected external Playwright/browser MCP.',
					'Validate the design spec before render.',
					'Snapshot before each Elementor or global-style write.',
					'Inspect renderer diagnostics before browser iteration.',
					'Verify desktop, tablet, and mobile breakpoints with an external browser MCP.',
				],
				'external_mcps'      => [
					'Use external Figma MCP for design extraction.',
					'Use external Playwright/browser MCP for screenshots and visual QA.',
				],
			],
			'elementor'     => $elementor,
			'site'          => [
				'ability_count' => count( AbilityRegistry::list() ),
				'mcp_server_id' => 'stonewright',
				'ability_prefix'=> 'stonewright/',
			],
			'context'       => [
				'matched_skills'          => $context['matched_skills'] ?? [],
				'matched_skill_playbooks' => self::compact_playbooks( $context['matched_skill_playbooks'] ?? [] ),
				'memory_entries'          => $context['memory_entries'] ?? [],
				'required_followups'      => $context['required_followups'] ?? [],
			],
		];
	}

	/**
	 * @param mixed $playbooks
	 * @return list<array<string, string|int>>
	 */
	private static function compact_playbooks( mixed $playbooks ): array {
		$out = [];
		foreach ( is_array( $playbooks ) ? $playbooks : [] as $playbook ) {
			if ( ! is_array( $playbook ) ) {
				continue;
			}
			$content = (string) ( $playbook['content'] ?? '' );
			$out[] = [
				'slug'           => (string) ( $playbook['slug'] ?? '' ),
				'title'          => (string) ( $playbook['title'] ?? '' ),
				'content_length' => strlen( $content ),
			];
		}
		return $out;
	}
}
