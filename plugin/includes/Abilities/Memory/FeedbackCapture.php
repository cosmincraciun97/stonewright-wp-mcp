<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Memory;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Security\Permissions;

/**
 * Thin write ability agents call when the user corrects them mid-task.
 *
 * @stonewright-status stable
 */
final class FeedbackCapture extends AbilityKernel {

	public function name(): string {
		return 'stonewright/feedback-capture';
	}

	public function label(): string {
		return __( 'Capture user feedback', 'stonewright' );
	}

	public function description(): string {
		return __( 'Records a user correction as a persistent learning rule (source=user-correction) for future task-start context.', 'stonewright' );
	}

	public function category(): string {
		return 'memory';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => [ 'correction' ],
			'properties'           => [
				'correction' => [
					'type'        => 'string',
					'minLength'   => 1,
					'description' => 'What the user corrected.',
				],
				'topic'      => [
					'type'        => 'string',
					'default'     => '',
					'description' => 'Short topic label. Defaults from the first words of the correction.',
				],
				'scope'      => [
					'type'    => 'string',
					'default' => 'project',
				],
				'trigger'    => [
					'type'        => 'string',
					'default'     => '',
					'description' => 'When this correction applies.',
				],
				'severity'  => [
					'type'    => 'string',
					'default' => 'high',
					'enum'    => [ 'low', 'medium', 'high', 'critical' ],
				],
				'lesson'     => [
					'type'    => 'string',
					'default' => '',
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
				'message'    => [ 'type' => 'string' ],
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
				$correction = sanitize_textarea_field( (string) ( $a['correction'] ?? '' ) );
				if ( '' === $correction ) {
					return $this->error( 'feedback_empty', __( 'correction is required.', 'stonewright' ), [ 'status' => 400 ] );
				}

				$topic = sanitize_text_field( (string) ( $a['topic'] ?? '' ) );
				if ( '' === $topic ) {
					$topic = mb_substr( $correction, 0, 80 );
				}

				$result = ( new LearningRecord() )->execute(
					[
						'topic'      => $topic,
						'correction' => $correction,
						'scope'      => (string) ( $a['scope'] ?? 'project' ),
						'trigger'    => (string) ( $a['trigger'] ?? '' ),
						'severity'  => (string) ( $a['severity'] ?? 'high' ),
						'source'     => 'user-correction',
						'lesson'     => (string) ( $a['lesson'] ?? $correction ),
					]
				);

				if ( $result instanceof \WP_Error ) {
					return $result;
				}

				return [
					'ok'         => true,
					'memory_id'  => (int) ( $result['memory_id'] ?? 0 ),
					'memory_key' => (string) ( $result['memory_key'] ?? '' ),
					'message'    => __( 'Feedback captured as a learned rule.', 'stonewright' ),
				];
			}
		);
	}
}
