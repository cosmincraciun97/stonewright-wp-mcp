<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\QA;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Companion\CompanionContract;
use Stonewright\WpMcp\QA\QaArtifactStore;
use Stonewright\WpMcp\Security\Permissions;
use Stonewright\WpMcp\Support\CompanionClient;

/**
 * Capture screenshots at multiple viewports and run layout sanity checks.
 * Contract decision: keep output_schema aligned to the handler response shape.
 *
 * Viewport precedence:
 *   1. design_spec.responsive.breakpoints — when a validated DesignSpec array is passed as
 *      design_spec and it contains responsive.breakpoints, those pixel widths are used.
 *      Heights are synthesised from the label defaults (mobile: 812, tablet: 1024, desktop: 900).
 *      TODO: the DesignSpec schema (stonewright.schema.json §responsive.breakpoints) currently
 *      stores only pixel widths per label, not full {w,h} pairs. If the spec gains a
 *      viewports field with heights, consume it here instead.
 *   2. Caller-supplied viewports from input.
 *   3. Hardcoded defaults: mobile/tablet/desktop.
 *
 * @stonewright-status stable
 */
final class ResponsiveCheck extends AbilityKernel {

	private const DEFAULT_VIEWPORTS = [
		[ 'w' => 375, 'h' => 812, 'label' => 'mobile' ],
		[ 'w' => 768, 'h' => 1024, 'label' => 'tablet' ],
		[ 'w' => 1440, 'h' => 900, 'label' => 'desktop' ],
	];

	/** Heights paired with each breakpoint label for design-spec expansion. */
	private const LABEL_HEIGHTS = [
		'mobile'  => 812,
		'tablet'  => 1024,
		'desktop' => 900,
	];

	public function name(): string {
		return 'stonewright/qa-responsive-check';
	}

	public function label(): string {
		return __( 'QA: Responsive Check', 'stonewright' );
	}

	public function description(): string {
		return __( 'Screenshots a page at multiple viewports and checks for overflow/overlap layout issues.', 'stonewright' );
	}

	public function category(): string {
		return 'qa';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'post_id'     => [ 'type' => 'integer', 'minimum' => 1 ],
				'url'         => [ 'type' => 'string', 'format' => 'uri' ],
				'viewports'   => [
					'type'  => 'array',
					'items' => [
						'type'       => 'object',
						'properties' => [
							'w'     => [ 'type' => 'integer', 'minimum' => 1 ],
							'h'     => [ 'type' => 'integer', 'minimum' => 1 ],
							'label' => [ 'type' => 'string' ],
						],
						'required'   => [ 'w', 'h' ],
					],
				],
				'design_spec' => [
					'type'        => 'object',
					'description' => 'Optional validated Stonewright DesignSpec. When present and responsive.breakpoints is set, those breakpoints override caller-supplied viewports.',
				],
			],
		];
	}

	public function output_schema(): array {
		return [
			'type'  => 'array',
			'items' => [
				'type'       => 'object',
				'properties' => [
					'viewport'    => [ 'type' => 'object' ],
					'artifact_id' => [ 'type' => 'string' ],
					'image_url'   => [ 'type' => 'string' ],
					'issues'      => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
					'request_id'  => [ 'type' => 'string' ],
				],
			],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::edit_posts();
	}

	public function execute( array $args ): array|\WP_Error {
		return $this->audit(
			$args,
			function ( array $args ) {
				if ( empty( $args['post_id'] ) && empty( $args['url'] ) ) {
					return $this->error( 'missing_target', __( 'Either post_id or url is required.', 'stonewright' ) );
				}

				$target_url = ! empty( $args['url'] )
					? $args['url']
					: get_permalink( (int) $args['post_id'] );

				if ( ! $target_url ) {
					return $this->error( 'invalid_post', __( 'Could not resolve URL for post_id.', 'stonewright' ) );
				}

				$viewports = $this->resolve_viewports( $args );
				$results   = [];

				foreach ( $viewports as $vp ) {
					$request_id    = wp_generate_uuid4();
					$artifact_path = QaArtifactStore::reserve( $request_id );

					$body = [
						'request_id'    => $request_id,
						'url'           => $target_url,
						'artifact_path' => rtrim( $artifact_path, '/' ),
						'viewport'      => [ 'width' => (int) $vp['w'], 'height' => (int) $vp['h'] ],
						'full_page'     => true,
						'wait_ms'       => 500,
					];

					// Validate request payload before sending.
					$req_check = CompanionContract::validate( 'screenshot', 'request', $body );
					if ( is_wp_error( $req_check ) ) {
						return $req_check;
					}

					$screenshot = CompanionClient::post( '/screenshot', $body );

					if ( is_wp_error( $screenshot ) ) {
						return $screenshot;
					}

					// Validate response payload after parsing.
					$resp_check = CompanionContract::validate( 'screenshot', 'response', $screenshot );
					if ( is_wp_error( $resp_check ) ) {
						return $resp_check;
					}

					$image_url = QaArtifactStore::url_for( (string) ( $screenshot['path'] ?? '' ) )
						?: ( $screenshot['url'] ?? '' );

					$results[] = [
						'viewport'    => $vp,
						'request_id'  => $request_id,
						'artifact_id' => $screenshot['artifact_id'] ?? '',
						'image_url'   => $image_url,
						'issues'      => $screenshot['issues'] ?? [],
					];
				}

				return $results;
			}
		);
	}

	/**
	 * Resolve the viewport list using the precedence chain:
	 *   1. design_spec.responsive.breakpoints
	 *   2. Input viewports
	 *   3. DEFAULT_VIEWPORTS
	 *
	 * @param  array<string, mixed> $args
	 * @return array<int, array{w: int, h: int, label: string}>
	 */
	private function resolve_viewports( array $args ): array {
		// --- Priority 1: design_spec.responsive.breakpoints ---
		$spec = $args['design_spec'] ?? null;
		if ( is_array( $spec ) ) {
			$breakpoints = $spec['responsive']['breakpoints'] ?? null;
			if ( is_array( $breakpoints ) && ! empty( $breakpoints ) ) {
				$vps = [];
				foreach ( $breakpoints as $label => $width ) {
					if ( ! is_int( $width ) || $width <= 0 ) {
						continue;
					}
					$height = self::LABEL_HEIGHTS[ $label ] ?? 900;
					$vps[]  = [ 'w' => $width, 'h' => $height, 'label' => (string) $label ];
				}
				if ( ! empty( $vps ) ) {
					return $vps;
				}
			}
		}

		// --- Priority 2: caller-supplied viewports ---
		if ( ! empty( $args['viewports'] ) && is_array( $args['viewports'] ) ) {
			return $args['viewports'];
		}

		// --- Priority 3: hardcoded defaults ---
		return self::DEFAULT_VIEWPORTS;
	}
}
