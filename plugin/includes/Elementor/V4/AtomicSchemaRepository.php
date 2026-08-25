<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Elementor\V4;

use Stonewright\WpMcp\Elementor\Provider\RuntimeOwnership;

/**
 * Compact, versioned Elementor Atomic schema repository.
 *
 * The bundled schemas cover only structures verified against Elementor's
 * public data-structure documentation. Installed runtimes may add or replace
 * schemas through the filter; unknown types and properties are never guessed.
 */
final class AtomicSchemaRepository {

	public const ELEMENT_VERSION = '0.0';
	public const PAGE_VERSION    = '0.4';

	/** @var array<string, array<string, mixed>>|null */
	private static ?array $schemas = null;

	/**
	 * @return array<string, array<string, mixed>>
	 */
	public static function all(): array {
		if ( null !== self::$schemas ) {
			return self::$schemas;
		}

		$bundled = [
			'e-div-block' => self::layout( 'Div', [] ),
			'e-flexbox'   => self::layout( 'Container', [ 'direction' => 'string', 'gap' => 'size' ] ),
			'e-grid'      => self::layout( 'Grid', [ 'columns' => 'string', 'rows' => 'string', 'gap' => 'size' ] ),
			'e-heading'   => self::widget( 'Heading', [ 'text' => [ 'key' => 'title', 'type' => 'html-v3' ], 'level' => [ 'key' => 'tag', 'type' => 'heading-level' ], 'link' => [ 'key' => 'link', 'type' => 'link' ] ] ),
			'e-paragraph' => self::widget( 'TextEditor', [ 'text' => [ 'key' => 'paragraph', 'type' => 'html-v3' ], 'link' => [ 'key' => 'link', 'type' => 'link' ] ] ),
			'e-image'     => self::widget( 'Image', [ 'url' => [ 'key' => 'image', 'type' => 'image-url' ], 'alt' => [ 'key' => 'image', 'type' => 'image-alt' ], 'link' => [ 'key' => 'link', 'type' => 'link' ] ] ),
			'e-button'    => self::widget( 'Button', [ 'text' => [ 'key' => 'text', 'type' => 'html-v3' ], 'link' => [ 'key' => 'link', 'type' => 'link' ] ] ),
			'e-divider'   => self::widget( 'Divider', [] ),
			'e-svg'       => self::widget( 'Icon', [ 'url' => [ 'key' => 'svg', 'type' => 'svg-src' ], 'link' => [ 'key' => 'link', 'type' => 'link' ] ] ),
		];

		$runtime = self::runtime_discovery();
		$runtime_schemas = self::index_runtime_items( $runtime['items'] );
		$schemas = array_replace( $bundled, $runtime_schemas );
		$authority = $bundled;
		foreach ( $runtime_schemas as $type => $schema ) {
			if ( self::is_write_certified( $schema ) ) {
				$authority[ $type ] = $schema;
			}
		}

		/**
		 * Supplies schemas discovered from the installed Elementor runtime.
		 * Each item must use the same compact shape as the bundled schemas.
		 *
		 * @param array<string, array<string, mixed>> $schemas
		 */
		$filtered = apply_filters( 'stonewright_elementor_v4_atomic_schemas', $schemas );
		self::$schemas = self::sanitize_schemas( is_array( $filtered ) ? $filtered : $schemas, $authority );

		return self::$schemas;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public static function for_atomic_type( string $atomic_type ): ?array {
		$schemas = self::all();
		return $schemas[ $atomic_type ] ?? null;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public static function for_design_type( string $design_type ): ?array {
		foreach ( self::all() as $atomic_type => $schema ) {
			$aliases = isset( $schema['design_types'] ) && is_array( $schema['design_types'] ) ? $schema['design_types'] : [];
			if ( in_array( $design_type, $aliases, true ) ) {
				$schema['atomic_type'] = $atomic_type;
				return $schema;
			}
		}
		return null;
	}

	public static function fingerprint(): string {
		$payload = wp_json_encode( self::all(), JSON_UNESCAPED_SLASHES );
		return hash( 'sha256', false === $payload ? '' : $payload );
	}

	public static function invalidate(): void {
		self::$schemas = null;
	}

	/**
	 * Ownership trust and schema certification are independent.
	 * Official ownership makes inventory trustworthy; it does not certify a write schema.
	 *
	 * @param array<string,mixed> $evidence
	 * @return array{ownership_trust:string,schema_certification:string,write_eligible:bool,reason:string,trust:string,certification:string}
	 */
	public static function provider_policy( array $evidence ): array {
		$provider = (string) ( $evidence['provider_id'] ?? '' );
		$provenance = (array) ( $evidence['provenance'] ?? [] );
		$certification_evidence = (string) ( $provenance['certification'] ?? '' );
		$ownership_trust = self::ownership_trust( $provider, $evidence );
		$bundled = 'elementor-core' === $provider && 'stonewright_bundled_contract' === $certification_evidence;

		if ( $bundled ) {
			return self::policy_result( 'official', 'bundled', true, 'bundled_contract' );
		}

		if ( self::adapter_certified( $evidence ) ) {
			return self::policy_result( $ownership_trust, 'adapter-certified', true, 'adapter_certified' );
		}

		if ( in_array( $certification_evidence, [ 'stonewright_bundled_contract', 'stonewright_adapter_contract', 'stonewright_explicit_certification' ], true ) ) {
			return self::policy_result( $ownership_trust, 'rejected', false, 'provider_not_certified' );
		}

		return self::policy_result( $ownership_trust, 'inventory-only', false, 'schema_inventory_only' );
	}

	/** @param array<string,mixed> $schema */
	public static function is_write_certified( array $schema ): bool {
		return true === self::provider_policy( $schema )['write_eligible'];
	}

	/**
	 * Discovers every installed Atomic layout/widget and its prop JSON schemas.
	 *
	 * @return array{items:list<array<string,mixed>>,issues:list<array<string,mixed>>}
	 */
	public static function runtime_discovery(): array {
		if ( ! class_exists( '\\Elementor\\Plugin' ) ) {
			return [ 'items' => [], 'issues' => [] ];
		}
		$items  = [];
		$issues = [];
		try {
			$sources = [];
			$elements_manager = \Elementor\Plugin::$instance->elements_manager ?? null;
			if ( is_object( $elements_manager ) && method_exists( $elements_manager, 'get_element_types' ) ) {
				$sources['layout'] = $elements_manager->get_element_types();
			}
			$widgets_manager = \Elementor\Plugin::$instance->widgets_manager ?? null;
			if ( is_object( $widgets_manager ) && method_exists( $widgets_manager, 'get_widget_types' ) ) {
				$sources['widget'] = $widgets_manager->get_widget_types();
			}
			foreach ( $sources as $kind => $instances ) {
				if ( ! is_array( $instances ) ) {
					continue;
				}
				foreach ( $instances as $registered_type => $instance ) {
					$type = (string) $registered_type;
					if ( ! str_starts_with( $type, 'e-' ) || ! is_object( $instance ) || ! method_exists( $instance, 'get_props_schema' ) ) {
						continue;
					}
					$props = [];
					try {
						$runtime_props = call_user_func( [ $instance, 'get_props_schema' ] );
					} catch ( \Throwable $error ) {
						$issues[] = [ 'code' => 'schema_unavailable', 'atomic_type' => $type, 'error_class' => get_class( $error ) ];
						continue;
					}
					if ( ! is_array( $runtime_props ) ) {
						$issues[] = [ 'code' => 'schema_invalid', 'atomic_type' => $type ];
						continue;
					}
					$valid_props = true;
					$ownership   = RuntimeOwnership::describe( $instance );
					$elementor_version = self::elementor_version(
						[
							'source_version' => $ownership['source_version'],
						]
					);
					foreach ( $runtime_props as $name => $prop_schema ) {
						$prop_name = self::bounded_name( (string) $name );
						if ( ! is_object( $prop_schema ) ) {
							$issues[] = [
								'code'        => 'descriptor_unavailable',
								'atomic_type' => $type,
								'prop'        => $prop_name,
								'error_class' => '',
							];
							$valid_props = false;
							break;
						}
						$normalized = AtomicPropDescriptorNormalizer::normalize( $prop_schema );
						if ( ! $normalized['ok'] ) {
							$issue = is_array( $normalized['issue'] ) ? $normalized['issue'] : [];
							$issues[] = [
								'code'        => (string) ( $issue['code'] ?? 'descriptor_unavailable' ),
								'atomic_type' => $type,
								'prop'        => $prop_name,
								'error_class' => (string) ( $issue['error_class'] ?? '' ),
							];
							$valid_props = false;
							break;
						}
						$prop = [
							'key'                    => $prop_name,
							'runtime_descriptor'     => $normalized['runtime_descriptor'],
							'descriptor_format'      => $normalized['descriptor_format'],
							'descriptor_fingerprint' => AtomicPropContractAdapter::fingerprint( $normalized['runtime_descriptor'] ),
						];
						$certified = AtomicPropContractAdapter::certify(
							$normalized,
							[ 'elementor_version' => $elementor_version ]
						);
						if ( true === $certified['matched'] && is_array( $certified['compact_contract'] ) ) {
							$prop['key']  = $certified['compact_contract']['key'];
							$prop['type'] = $certified['compact_contract']['type'];
						}
						$props[ $prop_name ] = $prop;
					}
					if ( ! $valid_props ) {
						continue;
					}
					$schema = [
						'atomic_type'    => $type,
						'kind'           => $kind,
						'design_types'   => [ $type ],
						'version'        => self::ELEMENT_VERSION,
						'props'          => $props,
						'source'         => 'live_runtime',
						'source_plugin'  => $ownership['source_plugin'],
						'source_version' => $ownership['source_version'],
						'runtime_class'  => $ownership['runtime_class'],
						'provider_id'    => $ownership['provider_id'],
						'ownership'      => $ownership['ownership'],
						'provenance'     => [
							'schema'    => 'live_elementor_runtime',
							'ownership' => $ownership['provenance']['ownership'],
						],
					];
					$schema = self::with_policy( $schema );
					$schema['schema_fingerprint'] = hash( 'sha256', (string) wp_json_encode( self::canonicalize( $schema ) ) );
					$items[] = $schema;
				}
			}
		} catch ( \Throwable $error ) {
			$issues[] = [ 'code' => 'runtime_discovery_failed', 'error_class' => get_class( $error ) ];
		}
		return [ 'items' => $items, 'issues' => $issues ];
	}

	/** @param list<array<string,mixed>> $items @return array<string,array<string,mixed>> */
	private static function index_runtime_items( array $items ): array {
		$out = [];
		foreach ( $items as $item ) {
			$type = (string) ( $item['atomic_type'] ?? '' );
			if ( '' !== $type ) {
				$copy = $item;
				unset( $copy['atomic_type'] );
				$out[ $type ] = $copy;
			}
		}
		return $out;
	}

	private static function canonicalize( mixed $value ): mixed {
		if ( ! is_array( $value ) ) {
			return $value;
		}
		if ( ! array_is_list( $value ) ) {
			ksort( $value );
		}
		foreach ( $value as $key => $item ) {
			$value[ $key ] = self::canonicalize( $item );
		}
		return $value;
	}

	/**
	 * @param array<string, string> $props
	 * @return array<string, mixed>
	 */
	private static function layout( string $design_type, array $props ): array {
		$mapped = [];
		foreach ( $props as $name => $type ) {
			$mapped[ $name ] = [ 'key' => 'gap' === $name ? 'gap' : 'flex-' . $name, 'type' => 'style-' . $type ];
		}
		if ( 'Grid' === $design_type ) {
			$mapped = [
				'columns' => [ 'key' => 'grid-template-columns', 'type' => 'style-string' ],
				'rows'    => [ 'key' => 'grid-template-rows', 'type' => 'style-string' ],
				'gap'     => [ 'key' => 'gap', 'type' => 'style-size' ],
			];
		}
		return self::with_policy(
			[
				'kind'         => 'layout',
				'design_types' => 'Container' === $design_type ? [ 'Section', 'Column', 'Container' ] : [ $design_type ],
				'version'      => self::ELEMENT_VERSION,
				'props'        => $mapped,
				'source'       => 'elementor_official_docs',
				'provider_id'  => 'elementor-core',
				'provenance'   => [ 'certification' => 'stonewright_bundled_contract' ],
			]
		);
	}

	/**
	 * @param array<string, array<string, string>> $props
	 * @return array<string, mixed>
	 */
	private static function widget( string $design_type, array $props ): array {
		return self::with_policy(
			[
				'kind'         => 'widget',
				'design_types' => [ $design_type ],
				'version'      => self::ELEMENT_VERSION,
				'props'        => $props,
				'source'       => 'elementor_official_docs',
				'provider_id'  => 'elementor-core',
				'provenance'   => [ 'certification' => 'stonewright_bundled_contract' ],
			]
		);
	}

	/**
	 * @param array<string, mixed>                $schemas
	 * @param array<string, array<string, mixed>> $authority
	 * @return array<string, array<string, mixed>>
	 */
	private static function sanitize_schemas( array $schemas, array $authority ): array {
		$out = [];
		foreach ( $schemas as $type => $schema ) {
			if ( ! is_string( $type ) || ! str_starts_with( $type, 'e-' ) || ! is_array( $schema ) ) {
				continue;
			}
			if ( ! in_array( $schema['kind'] ?? '', [ 'layout', 'widget' ], true ) || empty( $schema['version'] ) || ! isset( $schema['props'] ) || ! is_array( $schema['props'] ) ) {
				continue;
			}
			$certified = $authority[ $type ] ?? null;
			if ( ! is_array( $certified ) || self::canonicalize( $schema ) !== self::canonicalize( $certified ) ) {
				continue;
			}
			$out[ $type ] = self::with_policy( $certified );
		}
		return $out;
	}

	/**
	 * @param array<string,mixed> $schema
	 * @return array<string,mixed>
	 */
	private static function with_policy( array $schema ): array {
		$policy = self::provider_policy( $schema );
		$schema['ownership_trust']         = $policy['ownership_trust'];
		$schema['schema_certification']    = $policy['schema_certification'];
		$schema['provider_trust']          = $policy['trust'];
		$schema['provider_certification']  = $policy['certification'];
		$schema['write_eligible']          = $policy['write_eligible'];
		return $schema;
	}

	/**
	 * @return array{ownership_trust:string,schema_certification:string,write_eligible:bool,reason:string,trust:string,certification:string}
	 */
	private static function policy_result( string $ownership_trust, string $schema_certification, bool $write_eligible, string $reason ): array {
		return [
			'ownership_trust'      => $ownership_trust,
			'schema_certification' => $schema_certification,
			'write_eligible'       => $write_eligible,
			'reason'               => $reason,
			'trust'                => 'official' === $ownership_trust ? 'trusted' : 'untrusted',
			'certification'        => in_array( $schema_certification, [ 'bundled', 'adapter-certified' ], true ) ? 'certified' : 'discovered',
		];
	}

	/**
	 * @param array<string,mixed> $evidence
	 */
	private static function ownership_trust( string $provider, array $evidence ): string {
		$explicit = $evidence['ownership'] ?? null;
		if ( in_array( $provider, [ 'elementor-core', 'elementor-pro' ], true ) ) {
			return 'official';
		}
		if ( str_starts_with( $provider, 'plugin:' ) ) {
			return 'third-party';
		}
		if ( in_array( $explicit, [ 'official', 'third-party', 'unknown' ], true ) ) {
			return (string) $explicit;
		}
		return 'unknown';
	}

	/**
	 * @param array<string,mixed> $evidence
	 */
	private static function adapter_certified( array $evidence ): bool {
		$version = self::elementor_version( $evidence );
		$props   = $evidence['props'] ?? null;
		if ( is_array( $props ) && [] !== $props ) {
			foreach ( $props as $prop ) {
				if ( ! is_array( $prop ) || ! isset( $prop['runtime_descriptor'], $prop['descriptor_format'] ) ) {
					return false;
				}
				$result = AtomicPropContractAdapter::certify(
					[
						'ok'                 => true,
						'descriptor_format'  => $prop['descriptor_format'],
						'runtime_descriptor' => $prop['runtime_descriptor'],
					],
					[ 'elementor_version' => $version ]
				);
				if ( true !== $result['matched'] ) {
					return false;
				}
			}
			return true;
		}

		if ( ! isset( $evidence['runtime_descriptor'], $evidence['descriptor_format'] ) ) {
			return false;
		}

		$result = AtomicPropContractAdapter::certify(
			[
				'ok'                 => true,
				'descriptor_format'  => $evidence['descriptor_format'],
				'runtime_descriptor' => $evidence['runtime_descriptor'],
			],
			[ 'elementor_version' => $version ]
		);
		return true === $result['matched'];
	}

	/**
	 * @param array<string,mixed> $evidence
	 */
	private static function elementor_version( array $evidence ): string {
		if ( is_string( $evidence['elementor_version'] ?? null ) && '' !== $evidence['elementor_version'] ) {
			return $evidence['elementor_version'];
		}
		if ( defined( 'ELEMENTOR_VERSION' ) ) {
			return (string) constant( 'ELEMENTOR_VERSION' );
		}
		return is_string( $evidence['source_version'] ?? null ) ? $evidence['source_version'] : '';
	}

	private static function bounded_name( string $name ): string {
		if ( strlen( $name ) <= 1000 ) {
			return $name;
		}
		return substr( $name, 0, 1000 );
	}
}
