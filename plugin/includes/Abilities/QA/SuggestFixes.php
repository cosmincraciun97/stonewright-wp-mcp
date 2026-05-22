<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\QA;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Security\Permissions;

/**
 * Pure-PHP heuristic mapper that converts diff/accessibility reports into suggested patches.
 * Contract decision: keep output_schema aligned to the handler response shape.
 *
 * @stonewright-status stable
 */
final class SuggestFixes extends AbilityKernel {

	public function name(): string {
		return 'stonewright/qa-suggest-fixes';
	}

	public function label(): string {
		return __( 'QA: Suggest Fixes', 'stonewright' );
	}

	public function description(): string {
		return __( 'Converts diff/accessibility report items into a list of suggested patches.', 'stonewright' );
	}

	public function category(): string {
		return 'qa';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'diff_report' => [ 'type' => 'object' ],
				'spec'        => [ 'type' => 'object' ],
			],
			'required'             => [ 'diff_report' ],
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'ok'          => [ 'type' => 'boolean' ],
				'suggestions' => [
					'type'  => 'array',
					'items' => [
						'type'       => 'object',
						'properties' => [
							'kind'       => [ 'type' => 'string', 'enum' => [ 'block_update', 'style_token_update', 'theme_json_update' ] ],
							'target'     => [ 'type' => 'string' ],
							'change'     => [ 'type' => 'object' ],
							'confidence' => [ 'type' => 'number', 'minimum' => 0, 'maximum' => 1 ],
						],
						'required'   => [ 'kind', 'target', 'change', 'confidence' ],
					],
				],
			],
			'required'   => [ 'ok', 'suggestions' ],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::edit_posts();
	}

	public function execute( array $args ): array|\WP_Error {
		return $this->audit(
			$args,
			function ( array $args ) {
				$report      = (array) $args['diff_report'];
				$suggestions = [];

				// Missing sections → suggest block_update to add the missing section.
				foreach ( $report['missing_sections'] ?? [] as $section_name ) {
					$suggestions[] = [
						'kind'       => 'block_update',
						'target'     => 'section:' . $section_name,
						'change'     => [ 'action' => 'insert', 'section' => $section_name ],
						'confidence' => 0.7,
					];
				}

				// Pixel diff failures → suggest style_token_update.
				if ( isset( $report['ratio'] ) && isset( $report['pass'] ) && ! $report['pass'] ) {
					$suggestions[] = [
						'kind'       => 'style_token_update',
						'target'     => 'global',
						'change'     => [ 'action' => 'review_visual_tokens', 'ratio' => $report['ratio'] ],
						'confidence' => 0.5,
					];
				}

				// Alignment diffs → suggest theme_json_update.
				foreach ( $report['alignment_diffs'] ?? [] as $diff ) {
					$target = is_array( $diff ) ? ( $diff['selector'] ?? 'unknown' ) : (string) $diff;
					$suggestions[] = [
						'kind'       => 'theme_json_update',
						'target'     => $target,
						'change'     => is_array( $diff ) ? $diff : [ 'raw' => $diff ],
						'confidence' => 0.6,
					];
				}

				// Accessibility violations → suggest block_update per rule.
				foreach ( $report['violations'] ?? [] as $violation ) {
					$rule = $violation['rule'] ?? 'unknown';
					$suggestions[] = [
						'kind'       => 'block_update',
						'target'     => 'a11y:' . $rule,
						'change'     => [
							'action' => 'fix_accessibility',
							'rule'   => $rule,
							'impact' => $violation['impact'] ?? 'unknown',
							'help'   => $violation['help'] ?? '',
							'nodes'  => $violation['nodes'] ?? [],
						],
						'confidence' => 0.8,
					];
				}

				return $this->ok( [ 'suggestions' => $suggestions ] );
			}
		);
	}
}
