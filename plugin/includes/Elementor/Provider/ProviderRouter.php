<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Elementor\Provider;

use Stonewright\WpMcp\Elementor\ArchitectureRouter;
use Stonewright\WpMcp\Elementor\Schema\WidgetSchemaRepository;
use Stonewright\WpMcp\Elementor\V4\AtomicSchemaRepository;

/** Selects read-only provider evidence after document architecture is known. */
final class ProviderRouter {

	private \Closure $architecture;
	private \Closure $v3;
	private \Closure $atomic;
	private \Closure $abilities;

	public function __construct( ?callable $architecture = null, ?callable $v3 = null, ?callable $atomic = null, ?callable $abilities = null ) {
		$this->architecture = \Closure::fromCallable( $architecture ?? static fn( int $post_id, string $requested ): array => ArchitectureRouter::describe( $post_id, $requested ) );
		$this->v3          = \Closure::fromCallable( $v3 ?? [ self::class, 'live_v3' ] );
		$this->atomic      = \Closure::fromCallable( $atomic ?? [ AtomicSchemaRepository::class, 'runtime_discovery' ] );
		$this->abilities   = \Closure::fromCallable( $abilities ?? [ UpstreamAbilityDiscovery::class, 'all' ] );
	}

	/** @return array<string,mixed> */
	public function inspect( int $post_id = 0, string $requested = 'auto' ): array {
		$architecture = ( $this->architecture )( $post_id, $requested );
		$v3           = ( $this->v3 )();
		$atomic       = ( $this->atomic )();
		$abilities    = ( $this->abilities )();
		$v3           = is_array( $v3 ) ? $v3 : [];
		$atomic       = is_array( $atomic ) ? $atomic : [];
		$abilities    = is_array( $abilities ) ? $abilities : [];

		$issues    = is_array( $atomic['issues'] ?? null ) ? array_values( $atomic['issues'] ) : [];
		$providers = [];
		foreach ( $v3 as $schema ) {
			if ( is_array( $schema ) ) {
				self::add_capability( $providers, $issues, 'v3-widget', 'v3', (string) ( $schema['widget_type'] ?? '' ), $schema, (string) ( $schema['schema_hash'] ?? '' ) );
			}
		}
		foreach ( (array) ( $atomic['items'] ?? [] ) as $schema ) {
			if ( is_array( $schema ) ) {
				self::add_capability( $providers, $issues, 'atomic-node', 'v4', (string) ( $schema['atomic_type'] ?? '' ), $schema, (string) ( $schema['schema_fingerprint'] ?? '' ) );
			}
		}

		$upstream = [];
		foreach ( $abilities as $ability ) {
			if ( ! is_array( $ability ) || ! str_starts_with( (string) ( $ability['name'] ?? '' ), 'elementor/' ) ) {
				continue;
			}
			$schema_fingerprint = self::fingerprint( [ $ability['input_schema'] ?? [], $ability['output_schema'] ?? [] ] );
			$ability['schema_fingerprint'] = $schema_fingerprint;
			$upstream[ (string) $ability['name'] ] = $ability;
			$meta = (array) ( $ability['meta'] ?? [] );
			$declared_architectures = array_values(
				array_intersect( [ 'v3', 'v4' ], array_map( 'strval', (array) ( $meta['architectures'] ?? [] ) ) )
			);
			$ability_architectures = [] !== $declared_architectures ? $declared_architectures : [ 'global' ];
			$annotations       = (array) ( $meta['annotations'] ?? [] );
			$is_declared_write = false === ( $annotations['readonly'] ?? null ) || true === ( $annotations['destructive'] ?? false );
			self::add_capability( $providers, $issues, 'upstream-ability', $ability_architectures, (string) $ability['name'], $ability, $schema_fingerprint, $is_declared_write );
		}

		ksort( $providers );
		$provider_rows = array_values( $providers );
		foreach ( $provider_rows as &$provider ) {
			sort( $provider['architectures'] );
			usort( $provider['capabilities'], static fn( array $left, array $right ): int => strcmp( (string) $left['name'], (string) $right['name'] ) );
		}
		unset( $provider );

		$document = (string) ( $architecture['document_architecture'] ?? 'unknown' );
		$target   = in_array( $document, [ 'v3', 'v4', 'mixed' ], true ) ? $document : (string) ( $architecture['write_target'] ?? 'unknown' );
		$supported = 'mixed' !== $target && in_array( $target, [ 'v3', 'v4' ], true ) && self::has_architecture( $provider_rows, $target );
		$reason    = 'mixed' === $target ? 'mixed_architecture' : ( $supported ? 'provider_evidence_available' : 'provider_evidence_unavailable' );

		$manage = $upstream['elementor/manage-default-styles'] ?? null;
		$certification = is_array( $manage ) ? self::certify_manage_default_styles( $manage ) : [ 'state' => 'unsupported', 'reason' => 'upstream_ability_not_registered', 'contract' => [] ];
		$native_preferred = [
			'elementor/manage-default-styles' => is_array( $manage )
				? [
					'available'               => true,
					'selection'               => 'certified' === $certification['state'] ? 'native-preferred' : 'unsupported',
					'certification'           => $certification['state'],
					'reason'                  => $certification['reason'],
					'contract'                => $certification['contract'],
					'provider_id'             => RuntimeOwnership::provider_id( (string) ( $manage['source_plugin'] ?? ( $manage['meta']['source_plugin'] ?? '' ) ) ),
					'description'             => (string) ( $manage['description'] ?? '' ),
					'input_schema'            => (array) ( $manage['input_schema'] ?? [] ),
					'output_schema'           => (array) ( $manage['output_schema'] ?? [] ),
					'schema_fingerprint'      => (string) $manage['schema_fingerprint'],
					'routable_write'          => false,
					'safety_closure_required' => true,
					'provenance'              => (array) ( $manage['provenance'] ?? [ 'schema' => 'upstream_registered_ability' ] ),
				]
				: [
					'available'  => false,
					'selection'  => 'unsupported',
					'certification' => 'unsupported',
					'reason'     => 'upstream_ability_not_registered',
				],
		];

		return [
			'architecture'     => $architecture,
			'selection'        => [ 'status' => $supported ? 'supported' : 'unsupported', 'architecture' => $target, 'reason' => $reason ],
			'providers'        => $provider_rows,
			'issues'           => array_values( $issues ),
			'native_preferred' => $native_preferred,
			'writes_enabled'   => false,
			'safety_closure'   => [ 'permission', 'mode', 'confirmation_token', 'backup', 'validation', 'write_lock', 'readback', 'frontend_verification', 'rollback', 'audit' ],
		];
	}

	/** @return list<array<string,mixed>> */
	public static function live_v3(): array {
		$page = 1;
		$out  = [];
		do {
			$batch = WidgetSchemaRepository::list( '', $page, 100 );
			$items = (array) ( $batch['items'] ?? [] );
			$out   = array_merge( $out, $items );
			$count = count( $out );
			++$page;
		} while ( [] !== $items && $count < (int) ( $batch['total'] ?? 0 ) );
		return $out;
	}

	/** @param array<string,array<string,mixed>> $providers @param list<array<string,mixed>> $issues @param string|list<string> $architecture @param array<string,mixed> $evidence */
	private static function add_capability( array &$providers, array &$issues, string $kind, string|array $architecture, string $name, array $evidence, string $fingerprint, bool $write_primitive = false ): void {
		$plugin = (string) ( $evidence['source_plugin'] ?? ( $evidence['meta']['source_plugin'] ?? '' ) );
		$class  = (string) ( $evidence['runtime_class'] ?? '' );
		$id     = RuntimeOwnership::provider_id( $plugin );
		if ( '' === $name || '' === $fingerprint || '' === $class || 'unknown' === $id ) {
			$issues[] = [ 'code' => 'incomplete_provider_evidence', 'capability' => $name, 'kind' => $kind ];
			return;
		}
		if ( ! isset( $providers[ $id ] ) ) {
			$official = in_array( $id, [ 'elementor-core', 'elementor-pro' ], true );
			$ownership_provenance = (string) ( $evidence['provenance']['ownership'] ?? '' );
			$verified_official = $official && ( 'upstream-ability' !== $kind || self::verified_callback_ownership( $ownership_provenance ) );
			$providers[ $id ] = [
				'id'                => $id,
				'ownership'         => $official ? 'official' : 'third-party',
				'trust'             => $verified_official ? 'trusted' : ( $official ? 'unverified' : 'untrusted' ),
				'certification'     => 'discovered',
				'source_plugin'     => $plugin,
				'source_version'    => (string) ( $evidence['source_version'] ?? ( $evidence['meta']['source_version'] ?? '' ) ),
				'architectures'     => [],
				'runtime_classes'   => [],
				'capabilities'      => [],
				'write_primitives'  => [],
				'read_only'         => true,
				'provenance'        => [ 'ownership' => 'live_runtime_evidence' ],
			];
		}
		foreach ( is_array( $architecture ) ? $architecture : [ $architecture ] as $item ) {
			if ( ! in_array( $item, $providers[ $id ]['architectures'], true ) ) {
				$providers[ $id ]['architectures'][] = $item;
			}
		}
		if ( ! in_array( $class, $providers[ $id ]['runtime_classes'], true ) ) {
			$providers[ $id ]['runtime_classes'][] = $class;
		}
		$providers[ $id ]['capabilities'][] = [
			'kind'               => $kind,
			'name'               => $name,
			'schema_fingerprint' => $fingerprint,
			'provenance'         => (array) ( $evidence['provenance'] ?? [] ),
			'routable'           => false,
		];
		if ( $write_primitive ) {
			$providers[ $id ]['read_only'] = false;
			$certification = 'elementor/manage-default-styles' === $name ? self::certify_manage_default_styles( $evidence ) : [ 'state' => 'discovered' ];
			$providers[ $id ]['certification'] = (string) ( $certification['state'] ?? 'discovered' );
			$providers[ $id ]['write_primitives'][] = [
				'name'                    => $name,
				'source'                  => 'upstream_registered_ability',
				'routable'                => false,
				'safety_closure_required' => true,
			];
		}
	}

	/** @param array<string,mixed> $ability @return array{state:string,reason:string,contract:array<string,mixed>} */
	private static function certify_manage_default_styles( array $ability ): array {
		$meta        = (array) ( $ability['meta'] ?? [] );
		$annotations = (array) ( $meta['annotations'] ?? [] );
		$schema      = (array) ( $ability['input_schema'] ?? [] );
		$operations  = (array) ( $schema['properties']['operations'] ?? [] );
		$item        = (array) ( $operations['items'] ?? [] );
		$properties  = (array) ( $item['properties'] ?? [] );
		$actions     = array_values( array_map( 'strval', (array) ( $properties['action']['enum'] ?? [] ) ) );
		$description = strtolower( (string) ( $ability['description'] ?? '' ) . ' ' . (string) ( $operations['description'] ?? '' ) . ' ' . (string) ( $properties['css']['description'] ?? '' ) );
		$runtime     = (array) ( $ability['runtime_contract'] ?? ( $meta['contract'] ?? [] ) );
		$ownership_verified = self::verified_callback_ownership( (string) ( $ability['provenance']['ownership'] ?? '' ) );
		$limit       = (int) ( $runtime['runtime_operation_limit'] ?? 0 );
		$contract    = [
			'actions'                 => $actions,
			'responsive_css'          => str_contains( $description, '@media(--breakpoint)' ),
			'pseudo_states'           => str_contains( $description, '&:hover' ) && str_contains( $description, '&:focus' ) && str_contains( $description, '&:active' ),
			'runtime_operation_limit' => $limit,
		];
		$compatible = false === ( $annotations['readonly'] ?? null )
			&& true === ( $annotations['destructive'] ?? false )
			&& array_diff( [ 'update', 'delete' ], $actions ) === [];
		$certified = $compatible && $ownership_verified && $contract['responsive_css'] && $contract['pseudo_states'] && 20 === $limit;
		return [
			'state'    => $certified ? 'certified' : ( $compatible ? 'compatible' : 'discovered' ),
			'reason'   => $certified ? 'official_contract_certified' : 'upstream_contract_not_certified',
			'contract' => $contract,
		];
	}

	private static function verified_callback_ownership( string $provenance ): bool {
		return in_array(
			$provenance,
			[ 'registration_callback_and_wordpress_plugin_metadata', 'registration_callback_and_plugin_boundary' ],
			true
		);
	}

	/** @param list<array<string,mixed>> $providers */
	private static function has_architecture( array $providers, string $architecture ): bool {
		foreach ( $providers as $provider ) {
			if ( in_array( $architecture, (array) ( $provider['architectures'] ?? [] ), true ) ) {
				return true;
			}
		}
		return false;
	}

	private static function fingerprint( mixed $value ): string {
		$canonicalize = static function ( mixed $item ) use ( &$canonicalize ): mixed {
			if ( ! is_array( $item ) ) {
				return $item;
			}
			if ( ! array_is_list( $item ) ) {
				ksort( $item );
			}
			foreach ( $item as $key => $child ) {
				$item[ $key ] = $canonicalize( $child );
			}
			return $item;
		};
		return hash( 'sha256', (string) wp_json_encode( $canonicalize( $value ) ) );
	}
}
