<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Design;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Security\ConfirmationToken;
use Stonewright\WpMcp\Security\Permissions;

/**
 * Ability: stonewright/design-build-from-figma-reference
 *
 * Orchestrates the full Figma → WordPress build pipeline in a single call:
 *   1. stonewright/design-ingest-figma   — fetch Figma node → raw DesignSpec
 *   2. stonewright/design-validate-spec  — validate spec schema
 *   3. stonewright/design-choose-renderer — auto-detect best renderer
 *   4. stonewright/design-apply-to-post  — apply spec to WP post (with backup)
 *   5. stonewright/qa-screenshot-page    — capture live screenshot (skippable)
 *   6. stonewright/qa-verify-against-reference — visual diff vs Figma (skippable)
 *
 * Security envelope (AGENTS.md):
 *   - Permissions: can_manage_design() + can_edit_post($post_id)
 *   - Backup is enforced inside design-apply-to-post → ElementorWriter
 *   - Validator is enforced inside design-apply-to-post → ElementorWriter
 *   - dry_run=true skips steps 4-6 entirely (safe read-only mode)
 *
 * @stonewright-status stable
 */
final class BuildFromFigmaReference extends AbilityKernel {

	private const MAX_FIGMA_INGEST_WARNINGS = 50;

	public function name(): string {
		return 'stonewright/design-build-from-figma-reference';
	}

	public function label(): string {
		return __( 'Build from Figma Reference', 'stonewright' );
	}

	public function description(): string {
		return __( 'Orchestrates the full Figma → WordPress build pipeline: ingest Figma node, validate spec, choose renderer, apply to post, and optionally run QA screenshot + visual diff.', 'stonewright' );
	}

	public function category(): string {
		return 'design';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'figma_file_key' => [
					'type'        => 'string',
					'minLength'   => 1,
					'description' => 'Figma file key (from the URL after /design/ or /file/).',
				],
				'figma_node_id'  => [
					'type'        => 'string',
					'minLength'   => 1,
					'description' => 'Figma node ID (e.g. "97-8306" or "97:8306").',
				],
				'post_id'        => [
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => 'WordPress post ID to apply the spec to.',
				],
				'renderer'       => [
					'type'        => 'string',
					'enum'        => [ 'auto', 'gutenberg', 'elementor_v3', 'elementor_v4' ],
					'default'     => 'auto',
					'description' => 'Override renderer selection. Defaults to auto-detect.',
				],
				'dry_run'        => [
					'type'        => 'boolean',
					'default'     => false,
					'description' => 'If true, runs ingest + validate only — does not write to WordPress.',
				],
				'skip_qa'        => [
					'type'        => 'boolean',
					'default'     => false,
					'description' => 'If true, skips screenshot and visual diff steps.',
				],
				'confirmation_token' => [
					'type'        => 'string',
					'description' => 'Required in production-safe mode when dry_run is false.',
				],
			],
			'required' => [ 'figma_file_key', 'figma_node_id', 'post_id' ],
		];
	}

	public function output_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => true,
			'properties'           => [
				'success'     => [ 'type' => 'boolean' ],
				'dry_run'     => [ 'type' => 'boolean' ],
				'steps'       => [
					'type'  => 'array',
					'items' => [
						'type'                 => 'object',
						'additionalProperties' => true,
						'properties'           => [
							'step'   => [ 'type' => 'string' ],
							'status' => [ 'type' => 'string', 'enum' => [ 'ok', 'skipped', 'error' ] ],
						],
					],
				],
				'renderer'    => [ 'type' => 'string' ],
				'snapshot_id' => [ 'type' => 'string' ],
				'spec_sha8'   => [ 'type' => 'string' ],
				'warnings'    => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
			],
			'required' => [ 'success', 'steps' ],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		if ( ! Permissions::can_manage_design() ) {
			return false;
		}
		$post_id = (int) ( $args['post_id'] ?? 0 );
		if ( $post_id <= 0 ) {
			// Defer post_id validation to execute()
			return false;
		}
		return Permissions::can_edit_post( $post_id );
	}

	public function execute( array $args ): array|\WP_Error {
		return $this->audit(
			$args,
			function ( array $args ): array|\WP_Error {
				// ── Input validation ──────────────────────────────────────────────
				$file_key = isset( $args['figma_file_key'] ) ? sanitize_text_field( (string) $args['figma_file_key'] ) : '';
				$node_id  = isset( $args['figma_node_id'] ) ? sanitize_text_field( (string) $args['figma_node_id'] ) : '';
				$post_id  = isset( $args['post_id'] ) ? (int) $args['post_id'] : 0;
				$renderer = isset( $args['renderer'] ) ? (string) $args['renderer'] : 'auto';
				$dry_run  = (bool) ( $args['dry_run'] ?? false );
				$skip_qa  = (bool) ( $args['skip_qa'] ?? false );

				if ( '' === $file_key ) {
					return $this->error( 'missing_figma_file_key', __( 'figma_file_key is required.', 'stonewright' ) );
				}
				if ( '' === $node_id ) {
					return $this->error( 'missing_figma_node_id', __( 'figma_node_id is required.', 'stonewright' ) );
				}
				if ( $post_id <= 0 ) {
					return $this->error( 'invalid_post_id', __( 'post_id must be a positive integer.', 'stonewright' ) );
				}
				if ( ! $dry_run && Permissions::is_production_safe() ) {
					$token = isset( $args['confirmation_token'] ) ? (string) $args['confirmation_token'] : '';
					if ( '' === $token ) {
						return new \WP_Error(
							'stonewright_confirmation_required',
							__( 'Production-safe mode requires a confirmation_token.', 'stonewright' ),
							[ 'status' => 403 ]
						);
					}
					$verify_args = array_filter(
						$args,
						static fn( string $key ): bool => 'confirmation_token' !== $key,
						ARRAY_FILTER_USE_KEY
					);
					$verify = ConfirmationToken::verify_or_error( $token, $this->name(), $verify_args );
					if ( is_wp_error( $verify ) ) {
						return $verify;
					}
				}

				$steps = [];

				// ── Step 1: Ingest Figma ─────────────────────────────────────────
				$ingest = ( new IngestFigma() )->execute( [
					'file_key' => $file_key,
					'node_id'  => $node_id,
				] );
				if ( is_wp_error( $ingest ) ) {
					$steps[] = [ 'step' => 'ingest', 'status' => 'error', 'error' => $ingest->get_error_message() ];
					return $this->error_with_steps( $ingest, $steps );
				}
				$raw_spec = $ingest['spec'] ?? null;
				if ( ! is_array( $raw_spec ) ) {
					$steps[] = [ 'step' => 'ingest', 'status' => 'error', 'error' => 'No spec returned from ingest.' ];
					return $this->error( 'ingest_no_spec', __( 'Figma ingest did not return a spec.', 'stonewright' ) );
				}
				$ingest_warnings = array_values( array_map( 'strval', (array) ( $ingest['warnings'] ?? [] ) ) );
				$steps[]         = [
					'step'          => 'ingest',
					'status'        => 'ok',
					'sections'      => count( $raw_spec['sections'] ?? [] ),
					'warning_count' => count( $ingest_warnings ),
				];

				// ── Step 2: Validate spec ────────────────────────────────────────
				$validate = ( new ValidateSpec() )->execute( [ 'spec' => $raw_spec ] );
				if ( is_wp_error( $validate ) ) {
					$steps[] = [ 'step' => 'validate', 'status' => 'error', 'error' => $validate->get_error_message() ];
					return $this->error_with_steps( $validate, $steps );
				}
				$steps[] = [ 'step' => 'validate', 'status' => 'ok' ];

				$quality_issues = $this->figma_quality_issues( $raw_spec, $ingest_warnings );
				if ( [] !== $quality_issues ) {
					$steps[] = [
						'step'   => 'quality_gate',
						'status' => 'error',
						'issues' => $quality_issues,
					];
					return new \WP_Error(
						'stonewright_figma_quality_gate_failed',
						__( 'Figma ingest quality gate failed; no WordPress content was written.', 'stonewright' ),
						[
							'status'   => 422,
							'steps'    => $steps,
							'issues'   => $quality_issues,
							'warnings' => $ingest_warnings,
						]
					);
				}
				$steps[] = [
					'step'          => 'quality_gate',
					'status'        => 'ok',
					'warning_count' => count( $ingest_warnings ),
				];

				// ── Step 3: Choose renderer ──────────────────────────────────────
				$choose = ( new ChooseRenderer() )->execute( [
					'spec'    => $raw_spec,
					'post_id' => $post_id,
					'prefer'  => $renderer,
				] );
				if ( is_wp_error( $choose ) ) {
					$steps[] = [ 'step' => 'choose_renderer', 'status' => 'error', 'error' => $choose->get_error_message() ];
					return $this->error_with_steps( $choose, $steps );
				}
				$chosen_renderer = (string) ( $choose['renderer'] ?? 'gutenberg' );
				$steps[]         = [ 'step' => 'choose_renderer', 'status' => 'ok', 'renderer' => $chosen_renderer ];

				// ── Dry-run: stop here ───────────────────────────────────────────
				if ( $dry_run ) {
					$steps[] = [ 'step' => 'apply', 'status' => 'skipped', 'reason' => 'dry_run=true' ];
					if ( $skip_qa ) {
						$steps[] = [ 'step' => 'screenshot', 'status' => 'skipped', 'reason' => 'dry_run=true' ];
						$steps[] = [ 'step' => 'qa_verify', 'status' => 'skipped', 'reason' => 'dry_run=true' ];
					}
					return [
						'success'  => true,
						'dry_run'  => true,
						'renderer' => $chosen_renderer,
						'steps'    => $steps,
						'warnings' => $ingest_warnings,
					];
				}

				// ── Step 4: Apply spec to post ───────────────────────────────────
				$apply = $this->apply_with_renderer( $chosen_renderer, $raw_spec, $post_id );
				if ( is_wp_error( $apply ) ) {
					$steps[] = [ 'step' => 'apply', 'status' => 'error', 'error' => $apply->get_error_message() ];
					return $this->error_with_steps( $apply, $steps );
				}
				$spec_sha8 = (string) ( $apply['spec_sha8'] ?? '' );
				$steps[]   = [
					'step'              => 'apply',
					'status'            => 'ok',
					'ability'           => (string) ( $apply['ability'] ?? '' ),
					'spec_sha8'         => $spec_sha8,
					'snapshot_id'       => (string) ( $apply['snapshot_id'] ?? '' ),
					'sideloaded_assets' => count( (array) ( $apply['sideloaded_assets'] ?? [] ) ),
				];

				// ── Steps 5 & 6: QA (optional) ──────────────────────────────────
				if ( $skip_qa ) {
					$steps[] = [ 'step' => 'screenshot', 'status' => 'skipped', 'reason' => 'skip_qa=true' ];
					$steps[] = [ 'step' => 'qa_verify', 'status' => 'skipped', 'reason' => 'skip_qa=true' ];
				} else {
					// Step 5: Screenshot
					$screenshot = ( new \Stonewright\WpMcp\Abilities\QA\ScreenshotPage() )->execute( [
						'post_id'   => $post_id,
						'full_page' => true,
					] );
					if ( is_wp_error( $screenshot ) ) {
						// Non-fatal: companion may be unavailable
						$steps[] = [ 'step' => 'screenshot', 'status' => 'error', 'error' => $screenshot->get_error_message() ];
					} else {
						$steps[] = [
							'step'      => 'screenshot',
							'status'    => 'ok',
							'image_url' => (string) ( $screenshot['image_url'] ?? '' ),
						];

						// Step 6: Verify against reference
						$verify = ( new \Stonewright\WpMcp\Abilities\QA\VerifyAgainstReference() )->execute( [
							'post_id'         => $post_id,
							'reference_label' => 'figma-' . $file_key . '-' . str_replace( ':', '-', $node_id ),
						] );
						if ( is_wp_error( $verify ) ) {
							$steps[] = [ 'step' => 'qa_verify', 'status' => 'error', 'error' => $verify->get_error_message() ];
						} else {
							$steps[] = [
								'step'      => 'qa_verify',
								'status'    => 'ok',
								'score'     => $verify['score'] ?? null,
								'passed'    => $verify['passed'] ?? null,
							];
						}
					}
				}

				return [
					'success'     => true,
					'dry_run'     => false,
					'renderer'    => $chosen_renderer,
					'snapshot_id' => (string) ( $apply['snapshot_id'] ?? '' ),
					'spec_sha8'   => $spec_sha8,
					'steps'       => $steps,
					'warnings'    => $ingest_warnings,
				];
			}
		);
	}

	/**
	 * @param array<string, mixed> $spec
	 * @return array<string, mixed>|\WP_Error
	 */
	private function apply_with_renderer( string $renderer, array $spec, int $post_id ): array|\WP_Error {
		return match ( $renderer ) {
			'gutenberg'    => $this->apply_gutenberg( $spec, $post_id ),
			'elementor_v3' => $this->apply_elementor_v3( $spec, $post_id ),
			'elementor_v4' => $this->error( 'renderer_preview_only', __( 'Elementor V4 rendering is currently preview-only and cannot apply to a post.', 'stonewright' ) ),
			default        => $this->error( 'renderer_unsupported', __( 'The selected renderer cannot apply this spec.', 'stonewright' ) ),
		};
	}

	/**
	 * @param array<string, mixed> $spec
	 * @return array<string, mixed>|\WP_Error
	 */
	private function apply_gutenberg( array $spec, int $post_id ): array|\WP_Error {
		$args = [
			'spec'    => $spec,
			'post_id' => $post_id,
		];
		$this->attach_internal_confirmation_token( 'stonewright/design-spec-to-gutenberg', $args );

		$result = ( new SpecToGutenberg() )->execute( $args );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$result['ability'] = 'stonewright/design-spec-to-gutenberg';
		return $result;
	}

	/**
	 * @param array<string, mixed> $spec
	 * @return array<string, mixed>|\WP_Error
	 */
	private function apply_elementor_v3( array $spec, int $post_id ): array|\WP_Error {
		$args = [
			'spec'    => $spec,
			'post_id' => $post_id,
			'replace' => true,
		];
		$this->attach_internal_confirmation_token( 'stonewright/design-spec-to-elementor-v3', $args );

		$result = ( new SpecToElementorV3() )->execute( $args );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$result['ability']   = 'stonewright/design-spec-to-elementor-v3';
		$result['spec_sha8'] = $this->spec_sha8( $spec );
		return $result;
	}

	/**
	 * The orchestrator verifies the user-facing destructive intent first. In
	 * production-safe mode, sub-abilities still keep their own guards, so we
	 * issue a short-lived internal token scoped to the exact renderer call.
	 *
	 * @param array<string, mixed> $args
	 */
	private function attach_internal_confirmation_token( string $ability_name, array &$args ): void {
		if ( Permissions::is_production_safe() ) {
			$args['confirmation_token'] = ConfirmationToken::issue( $ability_name, $args, 60 );
		}
	}

	/**
	 * @param array<string, mixed> $spec
	 */
	private function spec_sha8( array $spec ): string {
		$spec_json = (string) wp_json_encode( $spec, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return substr( sha1( $spec_json ), 0, 8 );
	}

	/**
	 * Catch known high-risk Figma ingest failures before a renderer can turn
	 * them into a successful but visually empty WordPress page.
	 *
	 * @param array<string, mixed> $spec
	 * @param array<int, string>   $warnings
	 * @return array<int, string>
	 */
	private function figma_quality_issues( array $spec, array $warnings ): array {
		$issues = [];

		$warning_count = count( $warnings );
		if ( $warning_count > self::MAX_FIGMA_INGEST_WARNINGS ) {
			$issues[] = sprintf(
				'Figma ingest produced too many warnings (%d); conversion is too lossy to apply safely.',
				$warning_count
			);
		}

		foreach ( $warnings as $warning ) {
			$normalized_warning = strtolower( $warning );
			if ( str_contains( $normalized_warning, 'no mappable sections' ) ) {
				$issues[] = 'Figma ingest produced no mappable sections.';
			}
			if ( str_contains( $normalized_warning, 'generating a placeholder section' ) ) {
				$issues[] = 'Figma ingest generated a placeholder section.';
			}
			if ( str_contains( $normalized_warning, 'image export failed' ) ) {
				$issues[] = 'Figma image export failed.';
			}
			if ( str_contains( $normalized_warning, 'has no export url' ) ) {
				$issues[] = 'A Figma image block has no export URL.';
			}
		}

		$sections = (array) ( $spec['sections'] ?? [] );
		if ( [] === $sections ) {
			$issues[] = 'Design spec contains no sections.';
		}

		$meaningful_blocks = 0;
		foreach ( $sections as $section ) {
			if ( is_array( $section ) && isset( $section['id'] ) && 'section_placeholder' === (string) $section['id'] ) {
				$issues[] = 'Design spec contains a placeholder section.';
			}
			if ( is_array( $section ) ) {
				$meaningful_blocks += $this->count_meaningful_blocks( (array) ( $section['blocks'] ?? [] ) );
			}
		}

		if ( 0 === $meaningful_blocks ) {
			$issues[] = 'Design spec contains no meaningful content blocks.';
		}

		return array_values( array_unique( $issues ) );
	}

	/**
	 * @param array<int, mixed> $blocks
	 */
	private function count_meaningful_blocks( array $blocks ): int {
		$count = 0;

		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}

			$type = (string) ( $block['type'] ?? '' );
			$text = strtolower( (string) ( $block['text'] ?? '' ) );
			$id   = (string) ( $block['id'] ?? '' );
			if ( str_starts_with( $id, 'placeholder_' ) || str_contains( $text, 'empty design' ) ) {
				continue;
			}
			if ( in_array( $type, [ 'heading', 'paragraph', 'image', 'button' ], true ) ) {
				++$count;
			}

			if ( isset( $block['blocks'] ) && is_array( $block['blocks'] ) ) {
				$count += $this->count_meaningful_blocks( $block['blocks'] );
			}
		}

		return $count;
	}

	/**
	 * Merge step log into a WP_Error's extra data for richer error reporting.
	 *
	 * @param \WP_Error            $error
	 * @param array<int, mixed[]>  $steps
	 * @return \WP_Error
	 */
	private function error_with_steps( \WP_Error $error, array $steps ): \WP_Error {
		$data          = (array) $error->get_error_data();
		$data['steps'] = $steps;

		return new \WP_Error( $error->get_error_code(), $error->get_error_message(), $data );
	}

	/**
	 * @return array<int, string>
	 */
	protected function audit_redacted_keys(): array {
		return array_merge( parent::audit_redacted_keys(), [ 'confirmation_token' ] );
	}
}
