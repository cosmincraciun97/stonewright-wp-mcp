<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Design;

use Stonewright\WpMcp\Abilities\AbilityKernel;
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
				$steps[] = [ 'step' => 'ingest', 'status' => 'ok', 'sections' => count( $raw_spec['sections'] ?? [] ) ];

				// ── Step 2: Validate spec ────────────────────────────────────────
				$validate = ( new ValidateSpec() )->execute( [ 'spec' => $raw_spec ] );
				if ( is_wp_error( $validate ) ) {
					$steps[] = [ 'step' => 'validate', 'status' => 'error', 'error' => $validate->get_error_message() ];
					return $this->error_with_steps( $validate, $steps );
				}
				$steps[] = [ 'step' => 'validate', 'status' => 'ok' ];

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
					];
				}

				// ── Step 4: Apply spec to post ───────────────────────────────────
				$apply = ( new ApplyToPost() )->execute( [
					'spec'    => $raw_spec,
					'post_id' => $post_id,
				] );
				if ( is_wp_error( $apply ) ) {
					$steps[] = [ 'step' => 'apply', 'status' => 'error', 'error' => $apply->get_error_message() ];
					return $this->error_with_steps( $apply, $steps );
				}
				$spec_sha8 = (string) ( $apply['spec_sha8'] ?? '' );
				$steps[]   = [
					'step'              => 'apply',
					'status'            => 'ok',
					'spec_sha8'         => $spec_sha8,
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
					'success'   => true,
					'dry_run'   => false,
					'renderer'  => $chosen_renderer,
					'spec_sha8' => $spec_sha8,
					'steps'     => $steps,
				];
			}
		);
	}

	/**
	 * Merge step log into a WP_Error's extra data for richer error reporting.
	 *
	 * @param \WP_Error            $error
	 * @param array<int, mixed[]>  $steps
	 * @return \WP_Error
	 */
	private function error_with_steps( \WP_Error $error, array $steps ): \WP_Error {
		$data           = (array) $error->get_error_data();
		$data['steps']  = $steps;
		$error->add_data( $data, $error->get_error_code() );
		return $error;
	}
}
