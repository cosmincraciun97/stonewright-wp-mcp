<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Elementor;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Elementor\Provider\NativeContracts;
use Stonewright\WpMcp\Elementor\Provider\ProviderRouter;
use Stonewright\WpMcp\Security\Permissions;

/** Reports live Elementor provider ownership and upstream certification. It changes nothing; a certified write runs through stonewright-elementor-native-execute. */
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
		return __( 'Reports live Elementor provider ownership, schema provenance, trust, native-preferred certification, and the native Elementor MCP status without changing site data. Names the certified Elementor abilities that stonewright-elementor-native-execute can run and the one version range they are certified for.', 'stonewright' );
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
				'architecture'           => [ 'type' => 'object' ],
				'selection'              => [ 'type' => 'object' ],
				'providers'              => [ 'type' => 'array' ],
				'providers_count'        => [ 'type' => 'integer' ],
				'providers_truncated'    => [ 'type' => 'boolean' ],
				'capabilities_count'     => [ 'type' => 'integer' ],
				'capabilities_truncated' => [ 'type' => 'boolean' ],
				'issues'                 => [ 'type' => 'array' ],
				'issues_count'           => [ 'type' => 'integer' ],
				'issues_truncated'       => [ 'type' => 'boolean' ],
				'severity_counts'        => [ 'type' => 'object' ],
				'blocker_count'          => [ 'type' => 'integer' ],
				'warning_count'          => [ 'type' => 'integer' ],
				'truncated_by_severity'  => [ 'type' => 'object' ],
				'native_preferred'       => [ 'type' => 'object' ],
				'native_elementor'       => [ 'type' => 'object' ],
				'schema_limits'          => [ 'type' => 'object' ],
				'writes_enabled'         => [ 'type' => 'boolean', 'description' => 'True when at least one certified native ability may be written through stonewright-elementor-native-execute.' ],
				'safety_closure'         => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
			],
		];
	}

	public function meta(): array {
		return [
			'annotations' => [ 'readonly' => true, 'destructive' => false, 'idempotent' => true ],
			'provider_policy' => self::provider_policy(),
		];
	}

	/**
	 * One policy label per shipped contract, read from the contract itself so a new or changed
	 * contract never leaves a stale label here.
	 *
	 * @return array<string,string>
	 */
	private static function provider_policy(): array {
		$policy = [];
		foreach ( NativeContracts::names() as $name ) {
			$contract = NativeContracts::for_ability( $name );
			if ( ! is_array( $contract ) ) {
				continue;
			}
			$policy[ $name ] = 'unsupported' === ( $contract['status'] ?? '' )
				? 'unsupported'
				: match ( (string) ( $contract['routing']['native_write'] ?? '' ) ) {
					'read_only' => 'native-readback-when-certified',
					'refused'   => 'certified-native-write-refused',
					default     => 'native-preferred-when-certified',
				};
		}
		return $policy;
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		$post_id = max( 0, (int) ( $args['post_id'] ?? 0 ) );
		return Permissions::read() && ( $post_id < 1 || Permissions::edit_post( $post_id ) );
	}

	public function execute( array $args ): array|\WP_Error {
		$post_id      = max( 0, (int) ( $args['post_id'] ?? 0 ) );
		if ( $post_id > 0 && ! Permissions::edit_post( $post_id ) ) {
			return $this->error( 'cannot_edit_post', __( 'You cannot inspect providers for this post.', 'stonewright' ), [ 'status' => 403 ] );
		}
		$architecture = sanitize_key( (string) ( $args['architecture'] ?? 'auto' ) );
		if ( ! in_array( $architecture, [ 'auto', 'v3', 'v4' ], true ) ) {
			$architecture = 'auto';
		}
		return $this->router->inspect( $post_id, $architecture );
	}
}
