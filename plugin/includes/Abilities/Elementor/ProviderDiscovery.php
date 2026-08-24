<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Elementor;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Elementor\Provider\ProviderRouter;
use Stonewright\WpMcp\Security\Permissions;

/** Reports live Elementor provider ownership and upstream certification without enabling writes. */
final class ProviderDiscovery extends AbilityKernel {

	private ProviderRouter $router;

	public function __construct( ?ProviderRouter $router = null ) {
		$this->router = $router ?? new ProviderRouter();
	}

	public function name(): string {
		return 'stonewright/elementor-provider-discovery';
	}

	public function label(): string {
		return __( 'Discover Elementor providers', 'stonewright' );
	}

	public function description(): string {
		return __( 'Reports live Elementor provider ownership, schema provenance, trust, and native-preferred certification without changing site data.', 'stonewright' );
	}

	public function category(): string {
		return 'elementor';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'post_id'      => [ 'type' => 'integer', 'minimum' => 0 ],
				'architecture' => [ 'type' => 'string', 'enum' => [ 'auto', 'v3', 'v4' ], 'default' => 'auto' ],
			],
		];
	}

	public function output_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => true,
			'properties'           => [
				'providers'        => [ 'type' => 'array' ],
				'native_preferred' => [ 'type' => 'object' ],
				'writes_enabled'   => [ 'type' => 'boolean' ],
			],
		];
	}

	public function meta(): array {
		return [
			'annotations' => [ 'readonly' => true, 'destructive' => false, 'idempotent' => true ],
			'provider_policy' => [ 'elementor/manage-default-styles' => 'native-preferred-when-certified' ],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		unset( $args );
		return Permissions::read();
	}

	public function execute( array $args ): array|\WP_Error {
		$post_id      = max( 0, (int) ( $args['post_id'] ?? 0 ) );
		$architecture = sanitize_key( (string) ( $args['architecture'] ?? 'auto' ) );
		if ( ! in_array( $architecture, [ 'auto', 'v3', 'v4' ], true ) ) {
			$architecture = 'auto';
		}
		return $this->router->inspect( $post_id, $architecture );
	}
}
