<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Memory;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Memory\Memory;
use Stonewright\WpMcp\Security\Permissions;
use Stonewright\WpMcp\Skills\Skills;

/**
 * Records user corrections and repeatable agent mistakes into persistent site
 * memory and, only when requested, a disabled draft skill.
 *
 * Canonical (mode-neutral) request accepts topic+correction and legacy Direct
 * `text`. Success always includes a verified receipt after readback.
 *
 * @stonewright-status stable
 */
final class LearningRecord extends AbilityKernel {

	public function name(): string {
		return 'stonewright/learning-record';
	}

	public function label(): string {
		return __( 'Record learned correction', 'stonewright' );
	}

	public function description(): string {
		return __( 'Writes a persistent lesson when the user corrects the agent or the agent detects a repeatable mistake. Returns a verified receipt after readback. Skill generation is opt-in and creates a disabled draft that must pass promotion gates.', 'stonewright' );
	}

	public function category(): string {
		return 'memory';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			// topic+correction OR text (Direct legacy). Validated in execute.
			'properties'           => [
				'scope'          => [
					'type'        => 'string',
					'default'     => 'project',
					'description' => 'Canonical: user|project. Legacy surface scopes (elementor, gutenberg, …) remain accepted.',
				],
				'topic'          => [
					'type'        => 'string',
					'description' => 'Short topic the lesson applies to (canonical with correction).',
				],
				'correction'     => [
					'type'        => 'string',
					'description' => 'The corrected behavior or rule (canonical with topic).',
				],
				'text'           => [
					'type'        => 'string',
					'description' => 'Legacy Direct-mode free-text correction. Mapped to topic+correction when those are absent.',
				],
				'evidence'       => [
					'type'        => 'string',
					'default'     => '',
					'description' => 'Optional concise context for the correction.',
				],
				'lesson'         => [
					'type'        => 'string',
					'default'     => '',
					'description' => 'Operational instruction for future tasks.',
				],
				'confidence'     => [
					'type'    => 'number',
					'default' => 1.0,
					'minimum' => 0,
					'maximum' => 1,
				],
				'update_skill'   => [
					'type'        => 'boolean',
					'default'     => false,
					'description' => 'Whether to create or update a disabled draft skill from the same lesson.',
				],
				'skill_slug'     => [
					'type'        => 'string',
					'description' => 'Optional explicit skill slug. Defaults to learned-{topic}.',
				],
				'skill_title'    => [
					'type'        => 'string',
					'description' => 'Optional explicit skill title.',
				],
				'skill_content'  => [
					'type'        => 'string',
					'description' => 'Optional full skill body. Defaults to a concise correction playbook.',
				],
				'trigger'        => [
					'type'        => 'string',
					'default'     => '',
					'description' => 'When this rule applies (task category, surface, or free-text condition).',
				],
				'severity'      => [
					'type'        => 'string',
					'default'     => 'medium',
					'enum'        => [ 'low', 'medium', 'high', 'critical' ],
					'description' => 'How strongly the agent should prioritise this lesson.',
				],
				'source'         => [
					'type'        => 'string',
					'default'     => 'manual',
					'description' => 'Origin: explicit-user-request, user-correction, audit-error, manual, or Direct kind tags.',
				],
				'draft_skill'    => [
					'type'        => 'object',
					'description' => 'Legacy Direct-mode optional draft skill payload.',
					'properties'  => [
						'slug'        => [ 'type' => 'string' ],
						'name'        => [ 'type' => 'string' ],
						'description' => [ 'type' => 'string' ],
						'triggers'    => [
							'type'  => 'array',
							'items' => [ 'type' => 'string' ],
						],
						'body'        => [ 'type' => 'string' ],
					],
				],
			],
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'required'   => [ 'stored', 'backend', 'scope', 'memory_id', 'storage_ref', 'verified', 'ok', 'memory_key' ],
			'properties' => [
				'stored'       => [ 'type' => 'boolean' ],
				'backend'      => [ 'type' => 'string', 'enum' => [ 'plugin', 'direct' ] ],
				'scope'        => [ 'type' => 'string' ],
				'memory_id'    => [ 'type' => [ 'integer', 'string' ] ],
				'storage_ref'  => [ 'type' => 'string' ],
				'verified'     => [ 'type' => 'boolean' ],
				'ok'           => [ 'type' => 'boolean' ],
				'memory_key'   => [ 'type' => 'string' ],
				'memory_type'  => [ 'type' => 'string' ],
				'skill_id'     => [ 'type' => [ 'integer', 'null' ] ],
				'skill_slug'   => [ 'type' => [ 'string', 'null' ] ],
				'skill_status' => [ 'type' => [ 'string', 'null' ] ],
			],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::manage_options();
	}

	public function execute( array $args ): array|\WP_Error {
		return $this->audit(
			$args,
			function ( array $a ): array|\WP_Error {
				if ( ! get_option( 'stonewright_memory_enabled', true ) ) {
					return $this->error( 'memory_disabled', __( 'Memory is disabled on this site.', 'stonewright' ) );
				}

				$normalized = $this->normalize_input( $a );
				if ( $normalized instanceof \WP_Error ) {
					return $normalized;
				}

				$topic      = $normalized['topic'];
				$correction = $normalized['correction'];
				$scope      = $normalized['scope'];
				$memory_type = $normalized['memory_type'];
				$source     = $normalized['source'];
				$lesson     = $normalized['lesson'];
				$confidence = $normalized['confidence'];
				$trigger    = $normalized['trigger'];
				$severity  = $normalized['severity'];
				$evidence   = $normalized['evidence'];
				$key        = 'learning-' . $this->slugify( $topic );

				// Higher severity → higher precedence so task-start ranks them first.
				// User/project authored learning outranks audit feedback via type priority.
				$precedence = match ( $severity ) {
					'critical' => 900,
					'high'     => 700,
					'low'      => 300,
					default    => 500,
				};
				if ( in_array( $memory_type, [ 'user', 'project' ], true ) ) {
					$precedence = max( $precedence, 800 );
				}

				$value = [
					'correction'  => $correction,
					'lesson'      => $lesson,
					'trigger'     => $trigger,
					'severity'   => $severity,
					'source'      => $source,
					'evidence'    => $evidence,
					'recorded_at' => current_time( 'mysql', true ),
				];

				$memory_id = Memory::put_typed(
					$memory_type,
					$scope,
					$key,
					$topic,
					$value,
					$confidence,
					[
						'topic'      => $topic,
						'status'     => 'active',
						'precedence' => $precedence,
					]
				);

				if ( 0 === $memory_id ) {
					return $this->error(
						'memory_write_failed',
						__( 'Learning could not be stored — the memory table is unavailable. Report this to the site owner; the Memory admin page shows schema health.', 'stonewright' ),
						[ 'status' => 500 ]
					);
				}

				$readback = Memory::get_by_id( $memory_id );
				if ( null === $readback ) {
					return $this->error(
						'memory_readback_failed',
						__( 'Learning write succeeded but readback found no row.', 'stonewright' ),
						[ 'status' => 500 ]
					);
				}

				$stored_value = is_array( $readback['value'] ?? null ) ? $readback['value'] : [];
				$stored_corr  = (string) ( $stored_value['correction'] ?? '' );
				if ( $this->normalize_text( $stored_corr ) !== $this->normalize_text( $correction ) ) {
					return $this->error(
						'memory_readback_mismatch',
						__( 'Learning readback did not match the stored correction.', 'stonewright' ),
						[ 'status' => 500 ]
					);
				}

				$skill_id   = null;
				$skill_slug = null;
				$update_skill = (bool) ( $a['update_skill'] ?? false );
				$draft      = is_array( $a['draft_skill'] ?? null ) ? $a['draft_skill'] : null;
				if ( $update_skill || null !== $draft ) {
					$skill_slug = $this->skill_slug(
						$topic,
						(string) ( $a['skill_slug'] ?? ( $draft['slug'] ?? '' ) )
					);
					$skill_id = Skills::save(
						[
							'slug'           => $skill_slug,
							'title'          => '' !== (string) ( $a['skill_title'] ?? ( $draft['name'] ?? '' ) )
								? (string) ( $a['skill_title'] ?? $draft['name'] )
								: 'Learned: ' . $topic,
							'description'    => '' !== (string) ( $draft['description'] ?? '' )
								? (string) $draft['description']
								: 'Use when working on ' . $topic . ' or related Stonewright tasks.',
							'content'        => '' !== (string) ( $a['skill_content'] ?? ( $draft['body'] ?? '' ) )
								? (string) ( $a['skill_content'] ?? $draft['body'] )
								: $this->default_skill_content( $topic, $scope, $correction, $lesson ),
							'enabled'        => false,
							'enable_agentic' => false,
							'enable_prompt'  => false,
							'status'         => 'draft',
							'topic'          => $topic,
							'source'         => 'user',
						]
					);
				}

				return [
					'stored'       => true,
					'backend'      => 'plugin',
					'scope'        => $scope,
					'memory_id'    => $memory_id,
					'storage_ref'  => 'wp:stonewright_memory#' . $memory_id,
					'verified'     => true,
					'ok'           => true,
					'memory_key'   => $key,
					'memory_type'  => $memory_type,
					'skill_id'     => $skill_id,
					'skill_slug'   => $skill_slug,
					'skill_status' => null !== $skill_id ? 'draft' : null,
				];
			}
		);
	}

	/**
	 * @param array<string, mixed> $a
	 * @return array{
	 *   topic:string,correction:string,scope:string,memory_type:string,source:string,
	 *   lesson:string,confidence:float,trigger:string,severity:string,evidence:string
	 * }|\WP_Error
	 */
	private function normalize_input( array $a ): array|\WP_Error {
		$topic      = $this->clean_text( (string) ( $a['topic'] ?? '' ) );
		$correction = $this->clean_textarea( (string) ( $a['correction'] ?? '' ) );
		$text       = $this->clean_textarea( (string) ( $a['text'] ?? '' ) );

		if ( ( '' === $topic || '' === $correction ) && '' !== $text ) {
			// Direct legacy: single free-text field.
			$topic      = '' !== $topic ? $topic : $this->topic_from_text( $text );
			$correction = '' !== $correction ? $correction : $text;
		}

		if ( '' === $topic || '' === $correction ) {
			return $this->error(
				'learning_record_invalid',
				__( 'Provide topic+correction, or text (Direct legacy).', 'stonewright' ),
				[ 'status' => 400 ]
			);
		}

		$source   = $this->clean_source( (string) ( $a['source'] ?? 'manual' ) );
		$scope    = $this->clean_scope( (string) ( $a['scope'] ?? 'project' ) );
		$evidence = $this->clean_textarea( (string) ( $a['evidence'] ?? '' ) );
		$lesson   = $this->clean_textarea( (string) ( $a['lesson'] ?? '' ) );
		if ( '' === $lesson && '' !== $evidence ) {
			$lesson = $evidence;
		}

		return [
			'topic'       => $topic,
			'correction'  => $correction,
			'scope'       => $scope,
			'memory_type' => $this->resolve_memory_type( $scope, $source ),
			'source'      => $source,
			'lesson'      => $lesson,
			'confidence'  => max( 0.0, min( 1.0, (float) ( $a['confidence'] ?? 1.0 ) ) ),
			'trigger'     => $this->clean_text( (string) ( $a['trigger'] ?? '' ) ),
			'severity'   => $this->clean_severity( (string) ( $a['severity'] ?? 'medium' ) ),
			'evidence'    => $evidence,
		];
	}

	private function resolve_memory_type( string $scope, string $source ): string {
		if ( 'audit-error' === $source ) {
			return 'feedback';
		}
		// Explicit user/project learning must not be stored as audit feedback.
		if ( 'user' === $scope || in_array( $source, [ 'explicit-user-request', 'user-correction' ], true ) ) {
			return 'user' === $scope ? 'user' : ( 'project' === $scope ? 'project' : 'user' );
		}
		if ( 'project' === $scope ) {
			return 'project';
		}
		// Surface scopes (elementor, …) with manual source → project (user-authored).
		return 'project';
	}

	private function topic_from_text( string $text ): string {
		$line = trim( explode( "\n", $text )[0] ?? $text );
		$line = mb_substr( $line, 0, 80 );
		return '' !== $line ? $line : 'correction';
	}

	private function normalize_text( string $text ): string {
		return preg_replace( '/\s+/', ' ', trim( $text ) ) ?? '';
	}

	private function default_skill_content( string $topic, string $scope, string $correction, string $lesson ): string {
		$lines = [
			'# Learned Correction: ' . $topic,
			'',
			'## When To Use',
			'Use this whenever the task touches `' . $scope . '` and the topic resembles "' . $topic . '".',
			'',
			'## Required Behavior',
			'- Correction: ' . $correction,
		];

		if ( '' !== $lesson ) {
			$lines[] = '- Lesson: ' . $lesson;
		}

		$lines[] = '- Before acting, check current Stonewright memory and relevant skills for newer constraints.';

		return implode( "\n", $lines );
	}

	private function skill_slug( string $topic, string $explicit ): string {
		$slug = sanitize_title( $explicit );
		if ( '' !== $slug ) {
			return $slug;
		}
		return 'learned-' . $this->slugify( $topic );
	}

	private function slugify( string $text ): string {
		$normalised = preg_replace( '/[^A-Za-z0-9_-]+/', '-', $text ) ?? '';
		return trim( sanitize_title( $normalised ), '-' );
	}

	private function clean_scope( string $scope ): string {
		$clean = sanitize_title( $scope );
		return '' !== $clean ? $clean : 'project';
	}

	private function clean_text( string $text ): string {
		return sanitize_text_field( $text );
	}

	private function clean_textarea( string $text ): string {
		return sanitize_textarea_field( $text );
	}

	private function clean_severity( string $severity ): string {
		$severity = strtolower( sanitize_key( $severity ) );
		return in_array( $severity, [ 'low', 'medium', 'high', 'critical' ], true ) ? $severity : 'medium';
	}

	private function clean_source( string $source ): string {
		$source = strtolower( sanitize_key( $source ) );
		$source = str_replace( '_', '-', $source );
		$allowed = [
			'user-correction',
			'explicit-user-request',
			'audit-error',
			'manual',
			'correction',
			'lesson',
			'preference',
			'fact',
		];
		if ( ! in_array( $source, $allowed, true ) ) {
			return 'manual';
		}
		// Map Direct kind tags onto explicit-user-request.
		if ( in_array( $source, [ 'correction', 'lesson', 'preference', 'fact' ], true ) ) {
			return 'explicit-user-request';
		}
		return $source;
	}
}
