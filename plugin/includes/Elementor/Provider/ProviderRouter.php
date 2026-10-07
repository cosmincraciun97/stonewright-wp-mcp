<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Elementor\Provider;

use Stonewright\WpMcp\Elementor\ArchitectureRouter;
use Stonewright\WpMcp\Elementor\Schema\WidgetSchemaRepository;
use Stonewright\WpMcp\Elementor\V4\AtomicReadbackVerifier;
use Stonewright\WpMcp\Elementor\V4\AtomicSchemaRepository;

/** Selects read-only provider evidence after document architecture is known. */
final class ProviderRouter {
	private const MAX_ISSUES                  = 20;
	private const MAX_ISSUE_SAMPLES           = 5;
	private const MAX_SAMPLE_BYTES            = 64;
	private const MAX_PROVIDERS               = 50;
	private const MAX_CAPABILITIES            = 200;
	private const MAX_RUNTIME_CLASSES         = 50;
	private const MAX_REPAIR_TOOLS            = 20;
	private const MAX_SCHEMA_DEPTH            = 8;
	private const MAX_SCHEMA_KEYS             = 256;
	private const MAX_SCHEMA_BYTES            = 32768;
	private const MAX_DYNAMIC_STRING_BYTES    = 1000;

	private \Closure $architecture;
	private \Closure $v3;
	private \Closure $atomic;
	private \Closure $abilities;
	private \Closure $environment;

	public function __construct( ?callable $architecture = null, ?callable $v3 = null, ?callable $atomic = null, ?callable $abilities = null, ?callable $environment = null ) {
		$this->architecture = \Closure::fromCallable( $architecture ?? static fn( int $post_id, string $requested ): array => ArchitectureRouter::describe( $post_id, $requested ) );
		$this->v3          = \Closure::fromCallable( $v3 ?? [ self::class, 'live_v3' ] );
		$this->atomic      = \Closure::fromCallable( $atomic ?? [ AtomicSchemaRepository::class, 'runtime_discovery' ] );
		$this->abilities   = \Closure::fromCallable( $abilities ?? [ UpstreamAbilityDiscovery::class, 'all' ] );
		$this->environment = \Closure::fromCallable( $environment ?? [ UpstreamAbilityDiscovery::class, 'native_environment' ] );
	}

	/** @return array<string,mixed> */
	public function inspect( int $post_id = 0, string $requested = 'auto' ): array {
		$architecture = self::bounded_architecture( (array) ( $this->architecture )( $post_id, $requested ) );
		$issues       = [ 'blocker' => [], 'warning' => [] ];
		$issue_counts = [ 'blocker' => 0, 'warning' => 0 ];
		$v3           = self::discover_provider( 'v3', $this->v3, [], $issues, $issue_counts );
		$atomic       = self::discover_provider( 'atomic', $this->atomic, [ 'items' => [], 'issues' => [] ], $issues, $issue_counts );
		$failed       = [];
		$abilities    = self::discover_provider( 'abilities', $this->abilities, [], $issues, $issue_counts, $failed );
		$environment  = self::discover_provider( 'environment', $this->environment, [], $issues, $issue_counts, $failed );

		foreach ( is_array( $atomic['issues'] ?? null ) ? $atomic['issues'] : [] as $issue ) {
			if ( ! is_array( $issue ) ) {
				continue;
			}
			if ( ! isset( $issue['provider'] ) || ! is_string( $issue['provider'] ) || '' === $issue['provider'] ) {
				$issue['provider'] = 'atomic';
			}
			self::record_issue( $issues, $issue_counts, $issue );
		}
		$providers = [];
		foreach ( $v3 as $schema ) {
			if ( is_array( $schema ) ) {
				self::add_capability( $providers, $issues, $issue_counts, 'v3-widget', 'v3', (string) ( $schema['widget_type'] ?? '' ), $schema, (string) ( $schema['schema_hash'] ?? '' ) );
			}
		}
		foreach ( (array) ( $atomic['items'] ?? [] ) as $schema ) {
			if ( is_array( $schema ) ) {
				self::add_capability( $providers, $issues, $issue_counts, 'atomic-node', 'v4', (string) ( $schema['atomic_type'] ?? '' ), $schema, (string) ( $schema['schema_fingerprint'] ?? '' ) );
			}
		}

		[ $upstream, $certifications ] = self::upstream_index( $abilities );
		foreach ( $upstream as $name => $ability ) {
			$meta = (array) ( $ability['meta'] ?? [] );
			$declared_architectures = array_values(
				array_intersect( [ 'v3', 'v4' ], array_map( 'strval', (array) ( $meta['architectures'] ?? [] ) ) )
			);
			$ability_architectures = [] !== $declared_architectures ? $declared_architectures : [ 'global' ];
			$annotations       = (array) ( $meta['annotations'] ?? [] );
			$is_declared_write = false === ( $annotations['readonly'] ?? null ) || true === ( $annotations['destructive'] ?? false );
			self::add_capability( $providers, $issues, $issue_counts, 'upstream-ability', $ability_architectures, (string) $name, $ability, (string) $ability['schema_fingerprint'], $is_declared_write, $certifications[ $name ] ?? null );
		}

		ksort( $providers );
		$provider_rows = array_values( $providers );
		foreach ( $provider_rows as &$provider ) {
			sort( $provider['architectures'] );
			usort( $provider['capabilities'], static fn( array $left, array $right ): int => strcmp( (string) $left['name'], (string) $right['name'] ) );
		}
		unset( $provider );

		$providers_count    = count( $provider_rows );
		$capabilities_count = array_sum( array_map( static fn( array $provider ): int => count( (array) ( $provider['capabilities'] ?? [] ) ), $provider_rows ) );

		$document = (string) ( $architecture['document_architecture'] ?? 'unknown' );
		$target   = in_array( $document, [ 'v3', 'v4', 'mixed' ], true ) ? $document : (string) ( $architecture['write_target'] ?? 'unknown' );
		$supported = 'mixed' !== $target && in_array( $target, [ 'v3', 'v4' ], true ) && self::has_architecture( $provider_rows, $target );
		$reason    = 'mixed' === $target ? 'mixed_architecture' : ( $supported ? 'provider_evidence_available' : 'provider_evidence_unavailable' );

		$certifications   = self::complete_certifications( $certifications );
		$native_preferred = self::native_preferred( $upstream, $certifications );
		$native_elementor = NativeElementorReport::build( self::normalized_environment( $environment ), $upstream, $certifications, $failed );

		$provider_rows = self::bounded_provider_rows( $provider_rows );
		$output_capabilities = array_sum( array_map( static fn( array $provider ): int => count( (array) ( $provider['capabilities'] ?? [] ) ), $provider_rows ) );
		$issue_summary = self::issue_summary( $issues, $issue_counts );

		return [
			'architecture'     => $architecture,
			'selection'        => [ 'status' => $supported ? 'supported' : 'unsupported', 'architecture' => $target, 'reason' => $reason ],
			'providers'        => $provider_rows,
			'providers_count'  => $providers_count,
			'providers_truncated' => $providers_count > count( $provider_rows ),
			'capabilities_count' => $capabilities_count,
			'capabilities_truncated' => $capabilities_count > $output_capabilities,
			'issues'           => $issue_summary['issues'],
			'issues_count'     => $issue_summary['issues_count'],
			'issues_truncated' => $issue_summary['issues_truncated'],
			'severity_counts'  => $issue_counts,
			'blocker_count'    => $issue_counts['blocker'],
			'warning_count'    => $issue_counts['warning'],
			'truncated_by_severity' => $issue_summary['truncated_by_severity'],
			'native_preferred' => $native_preferred,
			'native_elementor' => $native_elementor,
			'schema_limits'    => [ 'max_depth' => self::MAX_SCHEMA_DEPTH, 'max_keys' => self::MAX_SCHEMA_KEYS, 'max_bytes' => self::MAX_SCHEMA_BYTES ],
			'writes_enabled'   => false,
			'safety_closure'   => [ 'permission', 'mode', 'confirmation_token', 'backup', 'validation', 'write_lock', 'readback', 'frontend_verification', 'rollback', 'audit' ],
		];
	}

	/**
	 * Evidence-only report of Elementor's own MCP abilities, without the widget and Atomic schema discovery.
	 *
	 * @return array<string,mixed>
	 */
	public function native_elementor(): array {
		$issues       = [ 'blocker' => [], 'warning' => [] ];
		$issue_counts = [ 'blocker' => 0, 'warning' => 0 ];
		$failed       = [];
		$abilities    = self::discover_provider( 'abilities', $this->abilities, [], $issues, $issue_counts, $failed );
		$environment  = self::discover_provider( 'environment', $this->environment, [], $issues, $issue_counts, $failed );
		[ $upstream, $certifications ] = self::upstream_index( $abilities );

		return NativeElementorReport::build( self::normalized_environment( $environment ), $upstream, self::complete_certifications( $certifications ), $failed );
	}

	/**
	 * Registered elementor/* abilities with bounded schemas and fingerprints, plus certification where a contract ships.
	 *
	 * @param array<mixed> $abilities
	 * @return array{0:array<string,array<string,mixed>>,1:array<string,array<string,mixed>>}
	 */
	private static function upstream_index( array $abilities ): array {
		$upstream       = [];
		$certifications = [];
		foreach ( $abilities as $ability ) {
			if ( ! is_array( $ability ) || ! str_starts_with( (string) ( $ability['name'] ?? '' ), 'elementor/' ) ) {
				continue;
			}
			$name     = (string) $ability['name'];
			$contract = NativeContracts::for_ability( $name );
			if ( is_array( $contract ) && 'certifiable' === $contract['status'] ) {
				$ability['contract_summary'] = NativeCertifier::summarize( $contract, $ability );
			}
			$input_summary  = self::schema_summary( (array) ( $ability['input_schema'] ?? [] ) );
			$output_summary = self::schema_summary( (array) ( $ability['output_schema'] ?? [] ) );
			$ability['input_schema'] = $input_summary['truncated'] ? [] : (array) ( $ability['input_schema'] ?? [] );
			$ability['output_schema'] = $output_summary['truncated'] ? [] : (array) ( $ability['output_schema'] ?? [] );
			$ability['input_schema_summary']  = $input_summary;
			$ability['output_schema_summary'] = $output_summary;
			$ability['schema_fingerprint']    = self::fingerprint( [ $ability['input_schema'], $ability['output_schema'] ] );
			$upstream[ $name ] = $ability;
			if ( is_array( $contract ) ) {
				$certifications[ $name ] = NativeCertifier::certify( $ability, $contract );
			}
		}
		return [ $upstream, $certifications ];
	}

	/**
	 * Adds the explicit unsupported result for every shipped contract whose ability is not registered.
	 *
	 * @param array<string,array<string,mixed>> $certifications
	 * @return array<string,array<string,mixed>>
	 */
	private static function complete_certifications( array $certifications ): array {
		foreach ( NativeContracts::names() as $name ) {
			$certifications[ $name ] ??= [ 'state' => 'unsupported', 'reason' => 'upstream_ability_not_registered', 'issues' => [], 'contract' => [] ];
		}
		ksort( $certifications );
		return $certifications;
	}

	/**
	 * @param array<string,array<string,mixed>> $upstream
	 * @param array<string,array<string,mixed>> $certifications
	 * @return array<string,array<string,mixed>>
	 */
	private static function native_preferred( array $upstream, array $certifications ): array {
		$out = [];
		foreach ( $certifications as $name => $certification ) {
			$ability = $upstream[ $name ] ?? null;
			if ( ! is_array( $ability ) ) {
				$out[ $name ] = [
					'available'     => false,
					'selection'     => 'unsupported',
					'certification' => 'unsupported',
					'reason'        => 'upstream_ability_not_registered',
				];
				continue;
			}
			$state    = (string) $certification['state'];
			$contract = NativeContracts::for_ability( $name );
			$entry    = [
				'available'               => true,
				'selection'               => NativeElementorReport::selection( $state, (string) ( $contract['access'] ?? 'write' ), (string) ( $contract['routing']['native_write'] ?? 'allowed' ) ),
				'native_write'            => (string) ( $contract['routing']['native_write'] ?? 'unsupported' ),
				'certification'           => $state,
				'reason'                  => (string) $certification['reason'],
				'issues'                  => array_values( (array) $certification['issues'] ),
				'contract'                => (array) $certification['contract'],
				'provider_id'             => RuntimeOwnership::provider_id( (string) ( $ability['source_plugin'] ?? ( $ability['meta']['source_plugin'] ?? '' ) ) ),
				'description'             => self::bounded_string( (string) ( $ability['description'] ?? '' ) ),
				'input_schema_summary'    => (array) $ability['input_schema_summary'],
				'output_schema_summary'   => (array) $ability['output_schema_summary'],
				'schema_output'           => 'certified' === $state ? 'full_bounded' : 'summary_only_untrusted_or_rejected',
				'schema_fingerprint'      => (string) $ability['schema_fingerprint'],
				'routable_write'          => false,
				'safety_closure_required' => true,
				'provenance'              => self::bounded_provenance( (array) ( $ability['provenance'] ?? [ 'schema' => 'upstream_registered_ability' ] ) ),
			];
			if ( isset( $certification['reasons'] ) ) {
				$entry['reasons'] = array_values( (array) $certification['reasons'] );
			}
			if ( 'refused' === $entry['native_write'] ) {
				$entry['native_write_reason'] = (string) ( $contract['routing']['refusal_reason'] ?? 'native_write_refused' );
			}
			if ( 'certified' === $state && 'allowed' === $entry['native_write'] ) {
				$entry['execute_with'] = 'stonewright/elementor-native-execute';
			}
			if ( 'certified' === $state ) {
				$entry['input_schema']  = (array) $ability['input_schema'];
				$entry['output_schema'] = (array) $ability['output_schema'];
			}
			$out[ $name ] = $entry;
		}
		return $out;
	}

	/**
	 * @param array<mixed> $environment
	 * @return array<string,mixed>
	 */
	private static function normalized_environment( array $environment ): array {
		if ( is_array( $environment['elementor'] ?? null ) && is_array( $environment['mcp_module'] ?? null ) ) {
			return $environment;
		}
		return UpstreamAbilityDiscovery::summarize_environment( [] );
	}

	/** @param array<string,mixed>|list<mixed> $fallback @param array{blocker:array<string,array<string,mixed>>,warning:array<string,array<string,mixed>>} $issues @param array{blocker:int,warning:int} $issue_counts @param list<string>|null $failed Collects the names of providers that threw. @return array<string,mixed>|list<mixed> */
	private static function discover_provider( string $provider, \Closure $callback, array $fallback, array &$issues, array &$issue_counts, ?array &$failed = null ): array {
		try {
			$result = $callback();
			return is_array( $result ) ? $result : $fallback;
		} catch ( \Throwable $error ) {
			if ( null !== $failed ) {
				$failed[] = $provider;
			}
			self::record_issue( $issues, $issue_counts, [
				'code'        => 'provider_discovery_failed',
				'provider'    => $provider,
				'error_class' => get_class( $error ),
			] );
			return $fallback;
		}
	}

	/** @param array{blocker:array<string,array<string,mixed>>,warning:array<string,array<string,mixed>>} $issues @param array{blocker:int,warning:int} $issue_counts @param array<string,mixed> $issue */
	private static function record_issue( array &$issues, array &$issue_counts, array $issue ): void {
		$severity = self::issue_severity( $issue );
		++$issue_counts[ $severity ];

		$code              = self::bounded_string( (string) ( $issue['code'] ?? 'provider_issue' ), 100 );
		$provider          = self::bounded_string( (string) ( $issue['provider'] ?? '' ), 100 );
		$descriptor_format = self::bounded_string( (string) ( $issue['descriptor_format'] ?? '' ), 100 );
		$error_class       = self::bounded_string( (string) ( $issue['error_class'] ?? '' ), 100 );
		$key               = implode( '|', [ $severity, $code, $provider, $descriptor_format, $error_class ] );

		if ( ! isset( $issues[ $severity ][ $key ] ) ) {
			if ( count( $issues[ $severity ] ) >= self::MAX_ISSUES ) {
				return;
			}
			$issues[ $severity ][ $key ] = [
				'severity'          => $severity,
				'code'              => $code,
				'provider'          => $provider,
				'descriptor_format' => $descriptor_format,
				'error_class'       => $error_class,
				'count'             => 0,
				'samples'           => [],
				'samples_truncated' => false,
			];
		}

		++$issues[ $severity ][ $key ]['count'];

		$sample = [
			'atomic_type' => self::sample_token( (string) ( $issue['atomic_type'] ?? '' ) ),
			'prop'        => self::sample_token( (string) ( $issue['prop'] ?? '' ) ),
		];
		if ( '' === $sample['atomic_type'] && '' === $sample['prop'] ) {
			return;
		}
		if ( count( $issues[ $severity ][ $key ]['samples'] ) >= self::MAX_ISSUE_SAMPLES ) {
			$issues[ $severity ][ $key ]['samples_truncated'] = true;
			return;
		}
		$issues[ $severity ][ $key ]['samples'][] = $sample;
	}

	/** @param array<string,mixed> $issue */
	private static function issue_severity( array $issue ): string {
		$severity = strtolower( (string) ( $issue['severity'] ?? '' ) );
		if ( in_array( $severity, [ 'blocker', 'critical', 'error' ], true ) || 'provider_discovery_failed' === ( $issue['code'] ?? null ) ) {
			return 'blocker';
		}
		return 'warning';
	}

	/** @param array{blocker:array<string,array<string,mixed>>,warning:array<string,array<string,mixed>>} $issues @param array{blocker:int,warning:int} $counts @return array{issues:list<array<string,mixed>>,issues_count:int,issues_truncated:bool,truncated_by_severity:array{blocker:int,warning:int}} */
	private static function issue_summary( array $issues, array $counts ): array {
		$groups  = array_merge( array_values( $issues['blocker'] ), array_values( $issues['warning'] ) );
		$bounded = array_slice( $groups, 0, self::MAX_ISSUES );
		$retained = [ 'blocker' => 0, 'warning' => 0 ];
		foreach ( $bounded as $group ) {
			$severity = self::issue_severity( $group );
			$retained[ $severity ] += (int) ( $group['count'] ?? 0 );
		}
		$blocker_count = (int) $counts['blocker'];
		$warning_count = (int) $counts['warning'];
		$total         = $blocker_count + $warning_count;
		return [
			'issues'                => $bounded,
			'issues_count'          => $total,
			'issues_truncated'      => $total > count( $bounded ),
			'truncated_by_severity' => [
				'blocker' => max( 0, $blocker_count - $retained['blocker'] ),
				'warning' => max( 0, $warning_count - $retained['warning'] ),
			],
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

	/** @param array<string,array<string,mixed>> $providers @param array{blocker:array<string,array<string,mixed>>,warning:array<string,array<string,mixed>>} $issues @param array{blocker:int,warning:int} $issue_counts @param string|list<string> $architecture @param array<string,mixed> $evidence @param array<string,mixed>|null $certification Contract result for this ability, when one ships. */
	private static function add_capability( array &$providers, array &$issues, array &$issue_counts, string $kind, string|array $architecture, string $name, array $evidence, string $fingerprint, bool $write_primitive = false, ?array $certification = null ): void {
		$plugin = self::bounded_string( (string) ( $evidence['source_plugin'] ?? ( $evidence['meta']['source_plugin'] ?? '' ) ) );
		$class  = self::bounded_string( (string) ( $evidence['runtime_class'] ?? '' ) );
		$name   = self::bounded_string( $name );
		$id     = RuntimeOwnership::provider_id( $plugin );
		if ( '' === $name || '' === $fingerprint || '' === $class || 'unknown' === $id ) {
			self::record_issue( $issues, $issue_counts, [ 'code' => 'incomplete_provider_evidence', 'capability' => $name, 'kind' => $kind ] );
			return;
		}
		$fingerprint = self::normalized_schema_fingerprint( $fingerprint );
		$policy_evidence = $evidence;
		$policy_evidence['provider_id'] = $id;
		$atomic_policy = 'atomic-node' === $kind ? AtomicSchemaRepository::provider_policy( $policy_evidence ) : null;
		if ( ! isset( $providers[ $id ] ) ) {
			$official = in_array( $id, [ 'elementor-core', 'elementor-pro' ], true );
			$ownership_provenance = (string) ( $evidence['provenance']['ownership'] ?? '' );
			$verified_official = $official && ( 'upstream-ability' !== $kind || self::verified_callback_ownership( $ownership_provenance ) );
			$providers[ $id ] = [
				'id'                   => $id,
				'ownership'            => $official ? 'official' : 'third-party',
				'ownership_trust'      => is_array( $atomic_policy ) ? $atomic_policy['ownership_trust'] : ( $official ? 'official' : 'third-party' ),
				'trust'                => is_array( $atomic_policy ) ? $atomic_policy['trust'] : ( $verified_official ? 'trusted' : ( $official ? 'unverified' : 'untrusted' ) ),
				'certification'        => is_array( $atomic_policy ) ? $atomic_policy['certification'] : 'discovered',
				'schema_certification' => is_array( $atomic_policy ) ? $atomic_policy['schema_certification'] : 'discovered',
				'source_plugin'        => $plugin,
				'source_version'       => self::bounded_string( (string) ( $evidence['source_version'] ?? ( $evidence['meta']['source_version'] ?? '' ) ), 100 ),
				'architectures'        => [],
				'runtime_classes'      => [],
				'capabilities'         => [],
				'write_primitives'     => [],
				'read_only'            => true,
				'provenance'           => [ 'ownership' => 'live_runtime_evidence' ],
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
			'provenance'         => self::bounded_provenance( (array) ( $evidence['provenance'] ?? [] ) ),
			'routable'           => false,
			'write_eligible'     => $write_eligible,
		];
		if ( $write_primitive ) {
			$certification ??= [ 'state' => 'discovered' ];
			$state = 'unsupported' === ( $certification['state'] ?? '' ) ? 'discovered' : (string) ( $certification['state'] ?? 'discovered' );
			$rank  = [ 'discovered' => 0, 'rejected' => 1, 'certified' => 2 ];
			if ( ( $rank[ $state ] ?? 0 ) >= ( $rank[ $providers[ $id ]['certification'] ] ?? 0 ) ) {
				$providers[ $id ]['certification'] = $state;
			}
			if ( 'certified' === ( $certification['state'] ?? '' ) ) {
				$providers[ $id ]['schema_certification'] = 'certified';
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

	private static function verified_callback_ownership( string $provenance ): bool {
		return NativeCertifier::ownership_verified( $provenance );
	}

	/** @param array<string,mixed> $architecture @return array<string,mixed> */
	private static function bounded_architecture( array $architecture ): array {
		$out = [];
		foreach ( [ 'elementor_version', 'document_architecture', 'requested_architecture', 'write_target', 'reason' ] as $key ) {
			if ( array_key_exists( $key, $architecture ) ) {
				$out[ $key ] = self::bounded_string( (string) $architecture[ $key ], 'reason' === $key ? self::MAX_DYNAMIC_STRING_BYTES : 100 );
			}
		}
		foreach ( [ 'site_v4', 'document_inspected', 'write_blocked', 'surgical_v3_allowed', 'high_level_write_blocked', 'implicit_conversion' ] as $key ) {
			if ( array_key_exists( $key, $architecture ) ) {
				$out[ $key ] = (bool) $architecture[ $key ];
			}
		}
		if ( array_key_exists( 'post_id', $architecture ) ) {
			$out['post_id'] = max( 0, (int) $architecture['post_id'] );
		}
		if ( is_array( $architecture['repair_tools'] ?? null ) ) {
			$tools = array_values( array_map( static fn( mixed $tool ): string => self::bounded_string( (string) $tool, 100 ), $architecture['repair_tools'] ) );
			$out['repair_tools']           = array_slice( $tools, 0, self::MAX_REPAIR_TOOLS );
			$out['repair_tools_count']     = count( $tools );
			$out['repair_tools_truncated'] = count( $tools ) > count( $out['repair_tools'] );
		}
		return $out;
	}

	/** @param list<array<string,mixed>> $providers @return list<array<string,mixed>> */
	private static function bounded_provider_rows( array $providers ): array {
		$providers = array_slice( $providers, 0, self::MAX_PROVIDERS );
		$remaining_capabilities = self::MAX_CAPABILITIES;
		foreach ( $providers as &$provider ) {
			$capabilities = (array) ( $provider['capabilities'] ?? [] );
			$total_capabilities = count( $capabilities );
			$allowed = min( $remaining_capabilities, $total_capabilities );
			$provider['capabilities'] = array_slice( $capabilities, 0, $allowed );
			$provider['capabilities_count'] = $total_capabilities;
			$provider['capabilities_truncated'] = $allowed < $total_capabilities;
			$remaining_capabilities -= $allowed;

			$runtime_classes = array_values( array_map( [ self::class, 'bounded_string' ], (array) ( $provider['runtime_classes'] ?? [] ) ) );
			$provider['runtime_classes_count'] = count( $runtime_classes );
			$provider['runtime_classes'] = array_slice( $runtime_classes, 0, self::MAX_RUNTIME_CLASSES );
			$provider['runtime_classes_truncated'] = count( $runtime_classes ) > count( $provider['runtime_classes'] );
			$provider['write_primitives'] = array_slice( (array) ( $provider['write_primitives'] ?? [] ), 0, self::MAX_CAPABILITIES );
		}
		unset( $provider );
		return $providers;
	}

	/** Whether a schema fits the depth, key and byte limits the router applies to every schema it reports. */
	public static function schema_within_limits( array $schema ): bool {
		return ! self::schema_summary( $schema )['truncated'];
	}

	/**
	 * The element and depth caps every Elementor route applies to a tree it reads or compares: the same
	 * bounds the V4 readback verifier uses. Callers that walk a document stop at these.
	 *
	 * @return array{max_elements:int,max_depth:int}
	 */
	public static function element_limits(): array {
		return [
			'max_elements' => AtomicReadbackVerifier::MAX_NODES,
			'max_depth'    => AtomicReadbackVerifier::MAX_DEPTH,
		];
	}

	/** @return array{keys_count:int,max_depth:int,bytes:int,truncated:bool} */
	private static function schema_summary( array $schema ): array {
		$keys_count = 0;
		$max_depth  = 0;
		self::measure_schema( $schema, 1, $keys_count, $max_depth );
		$encoded = json_encode( $schema, JSON_PARTIAL_OUTPUT_ON_ERROR, 2048 );
		$bytes   = is_string( $encoded ) ? strlen( $encoded ) : self::MAX_SCHEMA_BYTES + 1;
		return [
			'keys_count' => $keys_count,
			'max_depth'  => $max_depth,
			'bytes'      => $bytes,
			'truncated'  => $keys_count > self::MAX_SCHEMA_KEYS || $max_depth > self::MAX_SCHEMA_DEPTH || $bytes > self::MAX_SCHEMA_BYTES,
		];
	}

	private static function measure_schema( mixed $value, int $depth, int &$keys_count, int &$max_depth ): void {
		if ( ! is_array( $value ) ) {
			return;
		}
		$max_depth = min( self::MAX_SCHEMA_DEPTH + 1, max( $max_depth, $depth ) );
		$keys_count = min( self::MAX_SCHEMA_KEYS + 1, $keys_count + count( $value ) );
		if ( $depth >= self::MAX_SCHEMA_DEPTH + 1 ) {
			return;
		}
		foreach ( $value as $child ) {
			self::measure_schema( $child, $depth + 1, $keys_count, $max_depth );
		}
	}

	/** @param array<string,mixed> $provenance @return array<string,string|int|float|bool|null> */
	private static function bounded_provenance( array $provenance ): array {
		$allowed = [ 'metadata', 'schema', 'ownership', 'controls', 'certification', 'source_repository', 'source_commit', 'source_path' ];
		$out = [];
		foreach ( $allowed as $key ) {
			$value = $provenance[ $key ] ?? null;
			if ( is_string( $value ) ) {
				$out[ $key ] = self::bounded_string( $value );
			} elseif ( is_int( $value ) || is_float( $value ) || is_bool( $value ) || ( null === $value && array_key_exists( $key, $provenance ) ) ) {
				$out[ $key ] = $value;
			}
		}
		return $out;
	}

	private static function sample_token( string $value ): string {
		if ( '' === $value ) {
			return '';
		}
		if ( strlen( $value ) > self::MAX_SAMPLE_BYTES || str_contains( $value, '\\' ) ) {
			return 'sha256:' . hash( 'sha256', $value );
		}
		return $value;
	}

	private static function bounded_string( string $value, int $max_bytes = self::MAX_DYNAMIC_STRING_BYTES ): string {
		if ( strlen( $value ) <= $max_bytes ) {
			return $value;
		}
		return substr( $value, 0, max( 0, $max_bytes - 3 ) ) . '...';
	}

	private static function normalized_schema_fingerprint( string $fingerprint ): string {
		if ( 1 === preg_match( '/^[a-f0-9]{64}$/', $fingerprint ) ) {
			return $fingerprint;
		}
		return 'invalid_sha256:' . hash( 'sha256', $fingerprint );
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
		return NativeCertifier::fingerprint( $value );
	}
}
