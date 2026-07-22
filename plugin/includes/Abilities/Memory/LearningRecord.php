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
		return __( 'Writes a persistent lesson when the user corrects the agent or the agent detects a repeatable mistake. Skill generation is opt-in and creates a disabled draft that must pass promotion gates.', 'stonewright' );
	}

	public function category(): string {
		return 'memory';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => [ 'topic', 'correction' ],
			'properties'           => [
				'scope'          => [
					'type'        => 'string',
					'default'     => 'project',
					'description' => 'Memory scope such as project, elementor, gutenberg, acf, cpt-ui, wp-cli.',
				],
				'topic'          => [
					'type'        => 'string',
					'minLength'   => 1,
					'description' => 'Short topic the lesson applies to.',
				],
				'correction'     => [
					'type'        => 'string',
					'minLength'   => 1,
					'description' => 'The corrected behavior or rule the agent must remember.',
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
					'enum'        => [ 'user-correction', 'audit-error', 'manual' ],
					'description' => 'Origin of the learning record.',
				],
			],
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'required'   => [ 'ok', 'memory_id', 'memory_key' ],
			'properties' => [
				'ok'         => [ 'type' => 'boolean' ],
				'memory_id'  => [ 'type' => 'integer' ],
				'memory_key' => [ 'type' => 'string' ],
				'skill_id'   => [ 'type' => [ 'integer', 'null' ] ],
				'skill_slug' => [ 'type' => [ 'string', 'null' ] ],
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

				$topic      = $this->clean_text( (string) ( $a['topic'] ?? '' ) );
				$correction = $this->clean_textarea( (string) ( $a['correction'] ?? '' ) );
				if ( '' === $topic || '' === $correction ) {
					return $this->error( 'learning_record_invalid', __( 'topic and correction are required.', 'stonewright' ), [ 'status' => 400 ] );
				}

				$scope      = $this->clean_scope( (string) ( $a['scope'] ?? 'project' ) );
				$lesson     = $this->clean_textarea( (string) ( $a['lesson'] ?? '' ) );
				$confidence = max( 0.0, min( 1.0, (float) ( $a['confidence'] ?? 1.0 ) ) );
				$trigger    = $this->clean_text( (string) ( $a['trigger'] ?? '' ) );
				$severity  = $this->clean_severity( (string) ( $a['severity'] ?? 'medium' ) );
				$source     = $this->clean_source( (string) ( $a['source'] ?? 'manual' ) );
				$key        = 'learning-' . $this->slugify( $topic );

				// Higher severity → higher precedence so task-start ranks them first.
				$precedence = match ( $severity ) {
					'critical' => 900,
					'high'     => 700,
					'low'      => 300,
					default    => 500,
				};

				$memory_id = Memory::put_typed(
					'feedback',
					$scope,
					$key,
					$topic,
					[
						'correction'  => $correction,
						'lesson'      => $lesson,
						'trigger'     => $trigger,
						'severity'   => $severity,
						'source'      => $source,
						'recorded_at' => current_time( 'mysql', true ),
					],
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

				$skill_id   = null;
				$skill_slug = null;
				if ( (bool) ( $a['update_skill'] ?? false ) ) {
					$skill_slug = $this->skill_slug( $topic, (string) ( $a['skill_slug'] ?? '' ) );
					$skill_id   = Skills::save(
						[
							'slug'        => $skill_slug,
							'title'       => '' !== (string) ( $a['skill_title'] ?? '' )
								? (string) $a['skill_title']
								: 'Learned: ' . $topic,
							'description' => 'Use when working on ' . $topic . ' or related Stonewright tasks.',
							'content'     => '' !== (string) ( $a['skill_content'] ?? '' )
								? (string) $a['skill_content']
								: $this->default_skill_content( $topic, $scope, $correction, $lesson ),
							'enabled'     => false,
							'enable_agentic' => false,
							'enable_prompt' => false,
							'status'      => 'draft',
							'topic'       => $topic,
							'source'      => 'user',
						]
					);
				}

				return [
					'ok'         => true,
					'memory_id'  => $memory_id,
					'memory_key' => $key,
					'skill_id'   => $skill_id,
					'skill_slug' => $skill_slug,
					'skill_status' => null !== $skill_id ? 'draft' : null,
				];
			}
		);
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
		// Accept hyphenated enum values from schema.
		$source = str_replace( '_', '-', $source );
		return in_array( $source, [ 'user-correction', 'audit-error', 'manual' ], true ) ? $source : 'manual';
	}
}
