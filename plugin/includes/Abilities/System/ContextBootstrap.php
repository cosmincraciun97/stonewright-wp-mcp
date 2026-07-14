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
				'responseMode' => [
					'type'        => 'string',
					'enum'        => [ 'full', 'compact' ],
					'default'     => 'full',
					'description' => 'Use compact to return hashes and small refs for long context sections.',
				],
				'knownHashes'  => [
					'type'        => 'object',
					'description' => 'Optional client-known payload hashes keyed by response field, used to return changed/unchanged key lists.',
				],
			],
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'required'   => [
				'ok',
				'context_token',
				'instructions',
				'mcp_tool_naming',
				'tool_profile_hint',
				'matched_skill_playbooks',
				'memory_entries',
				'specializations',
				'recommended_external_mcps',
				'visual_quality_contract',
				'visual_build_gate',
				'design_implementation_contract',
				'required_followups',
			],
			'properties' => [
				'ok'                             => [ 'type' => 'boolean' ],
				'context_token'                  => [ 'type' => 'string' ],
				'expires_at'                     => [ 'type' => 'string' ],
				'instructions'                   => [ 'type' => 'string' ],
				'mcp_tool_naming'                => [ 'type' => 'object' ],
				'tool_profile_hint'              => [ 'type' => 'object' ],
				'matched_skills'                 => [ 'type' => 'array' ],
				'matched_skill_playbooks'        => [ 'type' => 'array' ],
				'memory_entries'                 => [ 'type' => 'array' ],
				'specializations'                => [ 'type' => 'array' ],
				'recommended_external_mcps'      => [ 'type' => 'array' ],
				'visual_quality_contract'        => [ 'type' => 'object' ],
				'visual_build_gate'              => [ 'type' => 'object' ],
				'design_implementation_contract' => [ 'type' => 'object' ],
				'required_followups'             => [ 'type' => 'array' ],
				'response_mode'                  => [ 'type' => 'string' ],
				'payload_hashes'                 => [ 'type' => 'object' ],
				'changed_keys'                   => [ 'type' => 'array' ],
				'unchanged_keys'                 => [ 'type' => 'array' ],
				'deltas'                         => [ 'type' => 'object' ],
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

		$response = ContextBuilder::build(
			$task,
			'' !== $surface ? $surface : 'unknown',
			'' !== $intent ? $intent : 'unknown'
		);
		if ( 'compact' === (string) ( $args['responseMode'] ?? 'full' ) ) {
			return self::compact_response( $response, is_array( $args['knownHashes'] ?? null ) ? $args['knownHashes'] : [] );
		}

		$response['response_mode'] = 'full';
		return $response;
	}

	/**
	 * @param array<string, mixed> $response
	 * @param array<string, mixed> $known_hashes
	 * @return array<string, mixed>
	 */
	private static function compact_response( array $response, array $known_hashes ): array {
		$hash_keys = [
			'instructions',
			'matched_skill_playbooks',
			'memory_entries',
			'visual_quality_contract',
			'visual_build_gate',
			'design_implementation_contract',
			'required_followups',
		];
		$is_visual = 'exempt' !== (string) ( $response['visual_quality_contract']['status'] ?? 'exempt' );
		$profile   = (string) ( $response['tool_profile_hint']['profile'] ?? 'essential' );
		$has_specializations = [] !== (array) ( $response['specializations'] ?? [] );

		$response['response_mode']                  = 'compact';
		if ( [] !== $known_hashes ) {
			[ $payload_hashes, $changed, $unchanged, $deltas ] = self::hash_delta( $response, $known_hashes, $hash_keys );
			$response['payload_hashes'] = $payload_hashes;
			$response['changed_keys']   = $changed;
			$response['unchanged_keys'] = $unchanged;
			$response['deltas']         = $deltas;
		} else {
			unset( $response['payload_hashes'], $response['changed_keys'], $response['unchanged_keys'], $response['deltas'] );
		}
		$response['instructions']                   = self::compact_text_ref( 'instructions', (string) ( $response['instructions'] ?? '' ) );
		$response['mcp_tool_naming']                = [
			'format'  => 'stonewright-hyphen-name',
			'example' => 'stonewright-context-bootstrap',
		];
		$response['matched_skill_playbooks']        = self::compact_playbooks( $response['matched_skill_playbooks'] ?? [] );
		$response['memory_entries']                 = self::compact_memory_entries( $response['memory_entries'] ?? [] );
		$response['specializations']                = self::compact_specializations( $response['specializations'] ?? [] );
		$response['recommended_external_mcps']      = self::compact_external_mcps( $response['recommended_external_mcps'] ?? [] );
		$response['visual_quality_contract']        = self::compact_object_ref( 'visual_quality_contract', $response['visual_quality_contract'] ?? [] );
		$response['visual_build_gate']              = self::compact_object_ref( 'visual_build_gate', $response['visual_build_gate'] ?? [] );
		$response['design_implementation_contract'] = self::compact_object_ref( 'design_implementation_contract', $response['design_implementation_contract'] ?? [] );
		$response['required_followups']             = self::compact_followups( $is_visual, $profile, $has_specializations );

		return $response;
	}

	/**
	 * @param array<string, mixed> $response
	 * @param array<string, mixed> $known_hashes
	 * @param list<string>        $keys
	 * @return array{0:array<string,string>,1:list<string>,2:list<string>,3:array<string,array<string,mixed>>}
	 */
	private static function hash_delta( array $response, array $known_hashes, array $keys ): array {
		$payload_hashes = [];
		$changed        = [];
		$unchanged      = [];
		$deltas         = [];

		foreach ( $keys as $key ) {
			$value                  = $response[ $key ] ?? null;
			$hash                   = self::hash_value( $value );
			$payload_hashes[ $key ] = $hash;
			if ( isset( $known_hashes[ $key ] ) && (string) $known_hashes[ $key ] === $hash ) {
				$unchanged[] = $key;
				continue;
			}
			$changed[]       = $key;
			$deltas[ $key ] = [
				'hash'   => $hash,
				'length' => is_string( $value ) ? strlen( $value ) : strlen( wp_json_encode( $value ) ?: '' ),
			];
		}

		return [ $payload_hashes, $changed, $unchanged, $deltas ];
	}

	private static function hash_value( mixed $value ): string {
		return hash( 'sha256', wp_json_encode( $value ) ?: serialize( $value ) );
	}

	private static function compact_text_ref( string $key, string $text ): string {
		if ( '' === $text ) {
			return '';
		}
		return sprintf( 'compact:%s:%s:%d', $key, self::hash_value( $text ), strlen( $text ) );
	}

	/**
	 * @param mixed $value
	 * @return array<string, mixed>
	 */
	private static function compact_object_ref( string $key, mixed $value ): array {
		return [
			'compact' => true,
			'key'     => $key,
			'hash'    => self::hash_value( $value ),
			'length'  => strlen( wp_json_encode( $value ) ?: '' ),
		];
	}

	/**
	 * @param mixed $playbooks
	 * @return list<array<string, mixed>>
	 */
	private static function compact_playbooks( mixed $playbooks ): array {
		$out = [];
		foreach ( is_array( $playbooks ) ? $playbooks : [] as $playbook ) {
			if ( ! is_array( $playbook ) ) {
				continue;
			}
			$content = (string) ( $playbook['content'] ?? '' );
			$out[]   = [
				'slug'           => (string) ( $playbook['slug'] ?? '' ),
				'title'          => (string) ( $playbook['title'] ?? '' ),
				'description'    => (string) ( $playbook['description'] ?? '' ),
				'content_length' => strlen( $content ),
				'content_hash'   => self::hash_value( $content ),
			];
		}
		return $out;
	}

	/**
	 * @param mixed $entries
	 * @return list<array<string, mixed>>
	 */
	private static function compact_memory_entries( mixed $entries ): array {
		$out = [];
		foreach ( is_array( $entries ) ? $entries : [] as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$value = $entry['value'] ?? $entry['value_json'] ?? null;
			$out[] = [
				'id'          => (string) ( $entry['id'] ?? '' ),
				'type'        => (string) ( $entry['type'] ?? '' ),
				'scope'       => (string) ( $entry['scope'] ?? '' ),
				'memory_key'  => (string) ( $entry['memory_key'] ?? '' ),
				'name'        => (string) ( $entry['name'] ?? '' ),
				'confidence'  => (float) ( $entry['confidence'] ?? 0 ),
				'updated_at'  => (string) ( $entry['updated_at'] ?? '' ),
				'value_hash'  => self::hash_value( $value ),
			];
		}
		return $out;
	}

	/** @return list<array<string, mixed>> */
	private static function compact_specializations( mixed $specializations ): array {
		$out = [];
		foreach ( is_array( $specializations ) ? $specializations : [] as $specialization ) {
			if ( ! is_array( $specialization ) ) {
				continue;
			}
			$out[] = [
				'id'    => (string) ( $specialization['id'] ?? '' ),
				'title' => (string) ( $specialization['title'] ?? '' ),
			];
		}
		return $out;
	}

	/** @return list<array<string, mixed>> */
	private static function compact_external_mcps( mixed $external_mcps ): array {
		$out = [];
		foreach ( is_array( $external_mcps ) ? $external_mcps : [] as $mcp ) {
			if ( ! is_array( $mcp ) ) {
				continue;
			}
			$out[] = [
				'id'      => (string) ( $mcp['id'] ?? '' ),
				'command' => (string) ( $mcp['command'] ?? '' ),
				'args'    => is_array( $mcp['args'] ?? null ) ? $mcp['args'] : [],
			];
		}
		return $out;
	}

	/** @return list<string> */
	private static function compact_followups( bool $is_visual, string $profile, bool $has_specializations ): array {
		$followups = [
			'Read matched playbooks and memory before acting.',
			'Record repeatable corrections with stonewright/learning-record.',
			'Use workflow-preflight fast_path.tool_profile; call tool-profile only to switch or verify.',
			'Pass stonewright_context_token to every write or destructive ability.',
		];
		if ( $is_visual ) {
			$followups[] = 'Visual work requires the external Playwright MCP before the first write.';
			$followups[] = 'Use native assets; never use a full-page screenshot as a section background.';
			$followups[] = 'Write and verify one section at a time on desktop, tablet, and mobile.';
			$followups[] = 'Gate completion on overflow, screenshot deltas, and token/asset/section evidence.';
			$followups[] = 'Custom-code or SVG-upload workarounds require explicit approval.';
		}
		if ( 'wp-cli' === $profile || 'content-model' === $profile ) {
			$followups[] = 'Use Stonewright WP-CLI status/discovery, then tokenized run or batch tools.';
		}
		if ( 'elementor-design' === $profile ) {
			$followups[] = 'Resolve Elementor widget intent and read each widget schema before writes.';
		}
		if ( $has_specializations ) {
			$followups[] = 'Apply the matched specialization guidance before writes.';
		}
		return $followups;
	}
}
