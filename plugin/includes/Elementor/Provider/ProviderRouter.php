<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Elementor\Provider;

use Stonewright\WpMcp\Elementor\ArchitectureRouter;
use Stonewright\WpMcp\Elementor\Schema\WidgetSchemaRepository;
use Stonewright\WpMcp\Elementor\V4\AtomicSchemaRepository;

/** Selects read-only provider evidence after document architecture is known. */
final class ProviderRouter {
	private const MAX_ISSUES = 20;

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
		$issues       = [];
		$issue_count  = 0;
		$v3           = self::discover_provider( 'v3', $this->v3, [], $issues, $issue_count );
		$atomic       = self::discover_provider( 'atomic', $this->atomic, [ 'items' => [], 'issues' => [] ], $issues, $issue_count );
		$abilities    = self::discover_provider( 'abilities', $this->abilities, [], $issues, $issue_count );

		foreach ( is_array( $atomic['issues'] ?? null ) ? $atomic['issues'] : [] as $issue ) {
			if ( is_array( $issue ) ) {
				self::record_issue( $issues, $issue_count, $issue );
			}
		}
		$providers = [];
		foreach ( $v3 as $schema ) {
			if ( is_array( $schema ) ) {
				self::add_capability( $providers, $issues, $issue_count, 'v3-widget', 'v3', (string) ( $schema['widget_type'] ?? '' ), $schema, (string) ( $schema['schema_hash'] ?? '' ) );
			}
		}
		foreach ( (array) ( $atomic['items'] ?? [] ) as $schema ) {
			if ( is_array( $schema ) ) {
				self::add_capability( $providers, $issues, $issue_count, 'atomic-node', 'v4', (string) ( $schema['atomic_type'] ?? '' ), $schema, (string) ( $schema['schema_fingerprint'] ?? '' ) );
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
			self::add_capability( $providers, $issues, $issue_count, 'upstream-ability', $ability_architectures, (string) $ability['name'], $ability, $schema_fingerprint, $is_declared_write );
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
			'issues_count'     => $issue_count,
			'issues_truncated' => $issue_count > count( $issues ),
			'native_preferred' => $native_preferred,
			'writes_enabled'   => false,
			'safety_closure'   => [ 'permission', 'mode', 'confirmation_token', 'backup', 'validation', 'write_lock', 'readback', 'frontend_verification', 'rollback', 'audit' ],
		];
	}

	/** @param array<string,mixed>|list<mixed> $fallback @param list<array<string,mixed>> $issues @return array<string,mixed>|list<mixed> */
	private static function discover_provider( string $provider, \Closure $callback, array $fallback, array &$issues, int &$issue_count ): array {
		try {
			$result = $callback();
			return is_array( $result ) ? $result : $fallback;
		} catch ( \Throwable $error ) {
			self::record_issue( $issues, $issue_count, [
				'code'        => 'provider_discovery_failed',
				'provider'    => $provider,
				'error_class' => get_class( $error ),
			] );
			return $fallback;
		}
	}

	/** @param list<array<string,mixed>> $issues @param array<string,mixed> $issue */
	private static function record_issue( array &$issues, int &$issue_count, array $issue ): void {
		++$issue_count;
		if ( count( $issues ) < self::MAX_ISSUES ) {
			$issues[] = $issue;
		}
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
	private static function add_capability( array &$providers, array &$issues, int &$issue_count, string $kind, string|array $architecture, string $name, array $evidence, string $fingerprint, bool $write_primitive = false ): void {
		$plugin = (string) ( $evidence['source_plugin'] ?? ( $evidence['meta']['source_plugin'] ?? '' ) );
		$class  = (string) ( $evidence['runtime_class'] ?? '' );
		$id     = RuntimeOwnership::provider_id( $plugin );
		if ( '' === $name || '' === $fingerprint || '' === $class || 'unknown' === $id ) {
			self::record_issue( $issues, $issue_count, [ 'code' => 'incomplete_provider_evidence', 'capability' => $name, 'kind' => $kind ] );
			return;
		}
		$policy_evidence = $evidence;
		$policy_evidence['provider_id'] = $id;
		$atomic_policy = 'atomic-node' === $kind ? AtomicSchemaRepository::provider_policy( $policy_evidence ) : null;
		if ( ! isset( $providers[ $id ] ) ) {
			$official = in_array( $id, [ 'elementor-core', 'elementor-pro' ], true );
			$ownership_provenance = (string) ( $evidence['provenance']['ownership'] ?? '' );
			$verified_official = $official && ( 'upstream-ability' !== $kind || self::verified_callback_ownership( $ownership_provenance ) );
			$providers[ $id ] = [
				'id'                => $id,
				'ownership'         => $official ? 'official' : 'third-party',
				'trust'             => is_array( $atomic_policy ) ? $atomic_policy['trust'] : ( $verified_official ? 'trusted' : ( $official ? 'unverified' : 'untrusted' ) ),
				'certification'     => is_array( $atomic_policy ) ? $atomic_policy['certification'] : 'discovered',
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
		$write_eligible = is_array( $atomic_policy ) && true === $atomic_policy['write_eligible'];
		$providers[ $id ]['capabilities'][] = [
			'kind'               => $kind,
			'name'               => $name,
			'schema_fingerprint' => $fingerprint,
			'provenance'         => (array) ( $evidence['provenance'] ?? [] ),
			'routable'           => false,
			'write_eligible'     => $write_eligible,
		];
		if ( $write_primitive ) {
			$certification = 'elementor/manage-default-styles' === $name ? self::certify_manage_default_styles( $evidence ) : [ 'state' => 'discovered' ];
			$providers[ $id ]['certification'] = (string) ( $certification['state'] ?? 'discovered' );
			if ( 'certified' === ( $certification['state'] ?? '' ) ) {
				$providers[ $id ]['read_only'] = false;
				$providers[ $id ]['capabilities'][ array_key_last( $providers[ $id ]['capabilities'] ) ]['write_eligible'] = true;
				$providers[ $id ]['write_primitives'][] = [
					'name'                    => $name,
					'source'                  => 'upstream_registered_ability',
					'routable'                => false,
					'safety_closure_required' => true,
				];
			}
		}
	}

	/** @param array<string,mixed> $ability @return array{state:string,reason:string,contract:array<string,mixed>} */
	private static function certify_manage_default_styles( array $ability ): array {
		$meta        = (array) ( $ability['meta'] ?? [] );
		$annotations = (array) ( $meta['annotations'] ?? [] );
		$input       = (array) ( $ability['input_schema'] ?? [] );
		$output      = (array) ( $ability['output_schema'] ?? [] );
		$operations  = (array) ( $input['properties']['operations'] ?? [] );
		$item        = (array) ( $operations['items'] ?? [] );
		$properties  = (array) ( $item['properties'] ?? [] );
		$actions     = array_values( array_map( 'strval', (array) ( $properties['action']['enum'] ?? [] ) ) );
		$runtime     = (array) ( $ability['runtime_contract'] ?? ( $meta['contract'] ?? [] ) );
		$ownership_verified = self::verified_callback_ownership( (string) ( $ability['provenance']['ownership'] ?? '' ) );
		$limit       = (int) ( $runtime['runtime_operation_limit'] ?? 0 );
		$issues = [];
		if ( 'elementor/manage-default-styles' !== ( $ability['name'] ?? null ) || 'Elementor\\Modules\\Mcp\\Abilities\\Manage_Default_Styles_Ability' !== ( $ability['runtime_class'] ?? null ) ) {
			$issues[] = 'runtime_identity_mismatch';
		}
		if ( 'elementor-core' !== RuntimeOwnership::provider_id( (string) ( $ability['source_plugin'] ?? ( $meta['source_plugin'] ?? '' ) ) ) ) {
			$issues[] = 'official_owner_mismatch';
		}
		if ( self::fingerprint( $annotations ) !== self::fingerprint( [ 'readonly' => false, 'destructive' => true, 'idempotent' => false ] ) ) {
			$issues[] = 'annotations_mismatch';
		}
		if ( self::fingerprint( $output ) !== self::fingerprint( self::manage_default_styles_output_schema() ) ) {
			$issues[] = 'output_schema_mismatch';
		}
		if ( self::fingerprint( $input ) !== self::fingerprint( self::manage_default_styles_input_schema() ) ) {
			$issues[] = 'input_schema_mismatch';
		}
		if ( 'array' !== ( $operations['type'] ?? null ) || 'object' !== ( $item['type'] ?? null ) || ! self::same_set( (array) ( $item['required'] ?? [] ), [ 'action', 'tag' ] ) || ! self::same_set( array_keys( $properties ), [ 'action', 'tag', 'css', 'mode' ] ) ) {
			$issues[] = 'operations_schema_mismatch';
		}
		if ( 'string' !== ( $properties['action']['type'] ?? null ) || ! self::same_set( $actions, [ 'update', 'delete' ] ) ) {
			$issues[] = 'action_contract_mismatch';
		}
		if ( 'string' !== ( $properties['tag']['type'] ?? null ) || ! self::contains_all( (string) ( $properties['tag']['description'] ?? '' ), [ 'html wrapper tag', 'allowed wrapper tags' ] ) ) {
			$issues[] = 'tag_contract_mismatch';
		}
		if ( 'string' !== ( $properties['css']['type'] ?? null ) || ! self::contains_all( (string) ( $properties['css']['description'] ?? '' ), [ 'plain css string', '&:hover', '&:focus', '&:active', '@media(--breakpoint)', 'prop: null', 'all: null', 'wipes the variant' ] ) ) {
			$issues[] = 'css_contract_mismatch';
		}
		$mode = (array) ( $properties['mode'] ?? [] );
		if ( 'string' !== ( $mode['type'] ?? null ) || ! self::same_set( (array) ( $mode['enum'] ?? [] ), [ 'patch', 'replace' ] ) || 'patch' !== ( $mode['default'] ?? null ) || ! self::contains_all( (string) ( $mode['description'] ?? '' ), [ 'upsert variants', 'preserving untouched', 'discard all variants', 'affected breakpoints', 'null values have no effect' ] ) ) {
			$issues[] = 'mode_contract_mismatch';
		}
		if ( ! self::contains_all( (string) ( $operations['description'] ?? '' ), [ '1–20', 'action and tag', 'raw css string', 'site-wide', 'patch = upsert variants', 'replace = overwrite variants', 'delete removes' ] ) ) {
			$issues[] = 'operation_semantics_mismatch';
		}
		if ( self::manage_default_styles_description() !== ( $ability['description'] ?? null ) ) {
			$issues[] = 'ability_semantics_mismatch';
		}
		if ( 20 !== $limit || self::fingerprint( $runtime ) !== self::fingerprint( [ 'runtime_operation_limit' => 20, 'class_type' => 'class' ] ) ) {
			$issues[] = 'runtime_constants_mismatch';
		}
		if ( ! $ownership_verified ) {
			$issues[] = 'ownership_unverified';
		}
		$contract    = [
			'actions'                 => $actions,
			'responsive_css'          => self::contains_all( (string) ( $properties['css']['description'] ?? '' ), [ '@media(--breakpoint)' ] ),
			'pseudo_states'           => self::contains_all( (string) ( $properties['css']['description'] ?? '' ), [ '&:hover', '&:focus', '&:active' ] ),
			'runtime_operation_limit' => $limit,
			'class_type'              => (string) ( $runtime['class_type'] ?? '' ),
			'issues'                  => array_values( array_unique( $issues ) ),
		];
		$certified = [] === $issues;
		return [
			'state'    => $certified ? 'certified' : 'rejected',
			'reason'   => $certified ? 'official_contract_certified' : 'upstream_contract_not_certified',
			'contract' => $contract,
		];
	}

	private static function manage_default_styles_description(): string {
		return 'Bulk manage the active kit\'s site-wide default styles, keyed by HTML wrapper tag (h1..h6, p, a, section, div, ...). These styles apply to every V4 atomic element that renders that tag on the whole site, sitting on top of each widget\'s built-in base_styles and beneath any inline or global class overrides. Use action=update to upsert (patch or replace) a tag\'s variants via a raw CSS string (supports @media(--breakpoint) + &:hover/&:focus/&:active), and action=delete to remove a tag\'s default style entirely.';
	}

	/** @return array<string,mixed> */
	private static function manage_default_styles_input_schema(): array {
		return [
			'type' => 'object',
			'required' => [ 'operations' ],
			'properties' => [
				'operations' => [
					'type' => 'array',
					'description' => 'Bulk operations (1–20). Each item requires action and tag. update needs css (raw CSS string, same format as manage-classes) and applies site-wide to that HTML tag. Use mode to control merge behaviour on update (patch = upsert variants, replace = overwrite variants for the affected breakpoints). delete removes the tag\'s default style entirely.',
					'items' => [
						'type' => 'object',
						'required' => [ 'action', 'tag' ],
						'properties' => [
							'action' => [ 'type' => 'string', 'enum' => [ 'update', 'delete' ] ],
							'tag' => [
								'type' => 'string',
								'description' => 'HTML wrapper tag to target (e.g. h1, h2, p, a). Must be one of Elementor\'s allowed wrapper tags.',
							],
							'css' => [
								'type' => 'string',
								'description' => 'Plain CSS string. Supports &:hover/&:focus/&:active nesting and @media(--breakpoint) blocks. In patch mode: "prop: null" removes that prop; "all: null" wipes the variant.',
							],
							'mode' => [
								'type' => 'string',
								'enum' => [ 'patch', 'replace' ],
								'default' => 'patch',
								'description' => 'patch (default): upsert variants, preserving untouched ones; null/all:null deletions apply. replace: discard all variants for the affected breakpoints, then store new ones; null values have no effect.',
							],
						],
					],
				],
			],
		];
	}

	/** @return array<string,mixed> */
	private static function manage_default_styles_output_schema(): array {
		return [
			'type' => 'object',
			'required' => [ 'status', 'results' ],
			'properties' => [
				'status' => [ 'type' => 'string' ],
				'results' => [ 'type' => 'array' ],
			],
		];
	}

	private static function verified_callback_ownership( string $provenance ): bool {
		return in_array(
			$provenance,
			[ 'active_plugin_header', 'active_plugin_boundary' ],
			true
		);
	}

	/** @param array<string,mixed> $schema @param list<string> $required @param array<string,string> $properties */
	private static function exact_object_schema( array $schema, array $required, array $properties ): bool {
		$actual = (array) ( $schema['properties'] ?? [] );
		if ( 'object' !== ( $schema['type'] ?? null ) || ! self::same_set( (array) ( $schema['required'] ?? [] ), $required ) || ! self::same_set( array_keys( $actual ), array_keys( $properties ) ) ) {
			return false;
		}
		foreach ( $properties as $name => $type ) {
			if ( $type !== ( $actual[ $name ]['type'] ?? null ) ) {
				return false;
			}
		}
		return true;
	}

	/** @param list<mixed> $actual @param list<string> $expected */
	private static function same_set( array $actual, array $expected ): bool {
		$actual = array_values( array_map( 'strval', $actual ) );
		sort( $actual );
		sort( $expected );
		return $actual === $expected;
	}

	/** @param list<string> $needles */
	private static function contains_all( string $text, array $needles ): bool {
		$text = strtolower( $text );
		foreach ( $needles as $needle ) {
			if ( ! str_contains( $text, strtolower( $needle ) ) ) {
				return false;
			}
		}
		return true;
	}

	/** @param list<array<string,mixed>> $providers */
	private static function has_architecture( array $providers, string $architecture ): bool {
		foreach ( $providers as $provider ) {
			if ( ! in_array( $architecture, (array) ( $provider['architectures'] ?? [] ), true ) ) {
				continue;
			}
			if ( 'v4' !== $architecture ) {
				return true;
			}
			foreach ( (array) ( $provider['capabilities'] ?? [] ) as $capability ) {
				if ( 'atomic-node' === ( $capability['kind'] ?? null ) && true === ( $capability['write_eligible'] ?? false ) ) {
					return true;
				}
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
