<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Design;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Companion\CompanionContract;
use Stonewright\WpMcp\Companion\Contracts\DesignSpec as DesignSpecContract;
use Stonewright\WpMcp\DesignSpec\Validator;
use Stonewright\WpMcp\Security\Permissions;
use Stonewright\WpMcp\Support\CompanionClient;

/**
 * Ability: stonewright/design.ingest_figma
 *
 * Calls the companion's /figma-ingest endpoint, validates the returned
 * DesignSpec through CompanionContract + DesignSpec\Validator, and returns
 * the spec with a stable spec_sha8 fingerprint.
 *
 * Security envelope:
 *   - Permission: Permissions::can_manage_design() (manage_options + edit_pages).
 *   - No post write occurs in this ability.
 *   - Figma token is redacted from audit log (handled by AbilityKernel).
 *
 * Companion invariant: companion NEVER writes to WordPress.
 *
 * @stonewright-status stable
 */
final class IngestFigma extends AbilityKernel {

	public function name(): string {
		return 'stonewright/design-ingest-figma';
	}

	public function label(): string {
		return __( 'Ingest Figma design', 'stonewright' );
	}

	public function description(): string {
		return __( 'Fetches a Figma node via the companion bridge, converts it to a Stonewright Design Spec, and validates the spec before returning it. The companion never writes to WordPress.', 'stonewright' );
	}

	public function category(): string {
		return 'design';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'figma_url'      => [ 'type' => 'string', 'description' => 'Full Figma share URL (mutually exclusive with file_key/node_id).' ],
				'file_key'       => [ 'type' => 'string', 'description' => 'Figma file key.' ],
				'node_id'        => [ 'type' => 'string', 'description' => 'Figma node ID (colon form).' ],
				'token_override' => [ 'type' => 'string', 'description' => 'Optional Figma personal access token override.' ],
			],
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'spec'        => [ 'type' => 'object', 'description' => 'Validated Stonewright Design Spec.' ],
				'spec_sha8'   => [ 'type' => 'string', 'description' => 'First 8 chars of sha1 of canonical spec JSON.' ],
				'asset_count' => [ 'type' => 'integer', 'description' => 'Number of image assets discovered.' ],
				'warnings'    => [ 'type' => 'array', 'items' => [ 'type' => 'string' ], 'description' => 'Non-fatal ingestion warnings.' ],
			],
			'required' => [ 'spec', 'spec_sha8', 'asset_count', 'warnings' ],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::can_manage_design();
	}

	public function execute( array $args ): array|\WP_Error {
		return $this->audit(
			$args,
			function ( array $args ): array|\WP_Error {
				// ── 1. Input validation ────────────────────────────────────────────
				$has_url      = ! empty( $args['figma_url'] );
				$has_key_pair = ! empty( $args['file_key'] ) && ! empty( $args['node_id'] );

				if ( ! $has_url && ! $has_key_pair ) {
					return $this->error(
						'missing_figma_target',
						__( 'Provide either figma_url or both file_key and node_id.', 'stonewright' )
					);
				}

				// ── 2. Call companion ──────────────────────────────────────────────
				$companion_args = array_filter( [
					'figma_url'      => $args['figma_url'] ?? null,
					'file_key'       => $args['file_key'] ?? null,
					'node_id'        => $args['node_id'] ?? null,
					'token_override' => $args['token_override'] ?? null,
				] );

				$contract_request = CompanionContract::validate( 'figma-ingest', 'request', $companion_args );
				if ( is_wp_error( $contract_request ) ) {
					return $contract_request;
				}

				$payload = CompanionClient::companion_figma_fetch( $companion_args );
				if ( is_wp_error( $payload ) ) {
					return $payload;
				}

				// ── 3. Validate response envelope (CompanionContract) ──────────────
				$contract_check = CompanionContract::validate( 'figma-ingest', 'response', $payload );
				if ( is_wp_error( $contract_check ) ) {
					return $contract_check;
				}

				// ── 4. Validate DesignSpec shape ───────────────────────────────────
				$spec = $payload['spec'] ?? [];
				if ( ! is_array( $spec ) ) {
					return $this->error(
						'invalid_spec_type',
						__( 'Companion returned a spec that is not an object.', 'stonewright' )
					);
				}

				// Structural pre-check via DesignSpecContract constants.
				$shape_errors = DesignSpecContract::check_shape( $spec );
				if ( [] !== $shape_errors ) {
					return new \WP_Error(
						'stonewright_spec_invalid',
						__( 'Companion spec failed structural shape check.', 'stonewright' ),
						[ 'errors' => $shape_errors ]
					);
				}

				// Full schema validation via Validator (AGENTS.md rule 4).
				$validated = Validator::validate( $spec );
				if ( is_wp_error( $validated ) ) {
					return $validated;
				}

				// ── 5. Compute spec fingerprint ────────────────────────────────────
				$spec_json = (string) wp_json_encode( $validated, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
				$spec_sha8 = substr( sha1( $spec_json ), 0, 8 );

				return [
					'spec'        => $validated,
					'spec_sha8'   => $spec_sha8,
					'asset_count' => (int) ( $payload['asset_count'] ?? 0 ),
					'warnings'    => (array) ( $payload['warnings'] ?? [] ),
				];
			}
		);
	}

	/**
	 * Redact token_override and figma_url from audit log.
	 *
	 * @return array<int, string>
	 */
	protected function audit_redacted_keys(): array {
		return array_merge( parent::audit_redacted_keys(), [ 'token_override', 'figma_url' ] );
	}
}
