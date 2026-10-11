<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Elementor\Provider;

/**
 * Builds the bounded `native_elementor` report from environment facts, the registered
 * `elementor/*` abilities and their per-contract certification results.
 *
 * The report is evidence only. It never routes or executes an ability.
 */
final class NativeElementorReport {

	public const SCHEMA = 1;

	private const MAX_ABILITIES = 100;
	private const MAX_ISSUES    = 10;
	private const MAX_PROVIDERS = 10;

	/**
	 * @param array<string,mixed>                 $environment    Result of UpstreamAbilityDiscovery::summarize_environment().
	 * @param array<string,array<string,mixed>>   $upstream       Normalized registered abilities keyed by name.
	 * @param array<string,array<string,mixed>>   $certifications Certification result per contract name.
	 * @param list<string>                        $failed         Discovery sources that threw.
	 * @return array<string,mixed>
	 */
	public static function build( array $environment, array $upstream, array $certifications, array $failed = [] ): array {
		ksort( $upstream );
		$names        = array_keys( $upstream );
		$shown        = array_slice( $names, 0, self::MAX_ABILITIES );
		$fingerprints = [];
		foreach ( $shown as $name ) {
			$fingerprints[ $name ] = (string) ( $upstream[ $name ]['schema_fingerprint'] ?? '' );
		}
		ksort( $certifications );
		$certification = [];
		$certified     = [];
		$unsupported   = [];
		$routable_writes = [];
		$version_codes   = [];
		$version_abilities = [];
		foreach ( $certifications as $name => $result ) {
			$state = (string) ( $result['state'] ?? 'unsupported' );
			$contract = NativeContracts::for_ability( (string) $name );
			$certification[ $name ] = [
				'state'     => $state,
				'selection' => self::selection( $state, (string) ( $contract['access'] ?? 'write' ), (string) ( $contract['routing']['native_write'] ?? '' ) ),
				'native_write' => (string) ( $contract['routing']['native_write'] ?? 'unsupported' ),
				'reason'    => (string) ( $result['reason'] ?? '' ),
				'issues'    => array_slice( array_values( array_map( 'strval', (array) ( $result['issues'] ?? [] ) ) ), 0, self::MAX_ISSUES ),
			];
			if ( 'certified' === $state ) {
				$certified[] = (string) $name;
				if ( self::routable_write( $state, $contract ) ) {
					$routable_writes[] = (string) $name;
				}
			} else {
				$unsupported[ $name ] = (string) ( $result['reason'] ?? '' );
			}
			foreach ( (array) ( $result['issues'] ?? [] ) as $issue ) {
				if ( in_array( $issue, [ 'elementor_version_out_of_range', 'elementor_version_unverified' ], true ) ) {
					$version_codes[ (string) $issue ] = true;
					$version_abilities[]              = (string) $name;
				}
			}
		}
		$state_token = self::state( $environment, count( $names ), $certified );
		$observed    = (string) ( $environment['elementor']['version'] ?? '' );
		$extra       = [];
		if ( [] !== $version_codes ) {
			$extra['version_issue'] = [
				'observed'        => $observed,
				'certified_range' => self::certified_range(),
				'issues'          => array_keys( $version_codes ),
				'abilities'       => array_slice( array_values( array_unique( $version_abilities ) ), 0, self::MAX_ABILITIES ),
			];
		}

		return $extra + [
			'schema'                        => self::SCHEMA,
			'state'                         => $state_token,
			'guidance'                      => self::guidance( $state_token, $extra['version_issue'] ?? null ),
			'elementor'                     => (array) ( $environment['elementor'] ?? [ 'installed' => false, 'version' => '' ] ),
			'mcp_module'                    => (array) ( $environment['mcp_module'] ?? [] ),
			'requires'                      => [ 'wordpress_abilities_api', 'wordpress_mcp_adapter', 'elementor_mcp_composer', 'elementor_mcp_site_exposure', 'atomic_editor' ],
			'abilities'                     => $shown,
			'abilities_count'               => count( $names ),
			'abilities_truncated'           => count( $names ) > count( $shown ),
			'ownership'                     => self::ownership( $upstream ),
			'schema_fingerprints'           => $fingerprints,
			'schema_fingerprints_truncated' => count( $names ) > count( $shown ),
			'certification'                 => $certification,
			'certified'                     => $certified,
			'unsupported'                   => $unsupported,
			// True when at least one certified ability may be written natively. A write still goes through
			// stonewright-elementor-native-execute and its gates; Elementor's own tools are never the route.
			'writes_enabled'                => [] !== $routable_writes,
			'routable_write'                => [] !== $routable_writes,
			'routable_writes'               => $routable_writes,
			'contract_errors'               => array_slice( NativeContracts::errors(), 0, self::MAX_ISSUES ),
			'discovery_failed'              => array_values( array_unique( array_map( 'strval', $failed ) ) ),
		];
	}

	/**
	 * The one value task start carries: the state token. Details stay in the full report.
	 *
	 * @param array<string,mixed> $report
	 */
	public static function compact( array $report ): string {
		$state = (string) ( $report['state'] ?? 'not_installed' );
		return 1 === preg_match( '/^[a-z_]{1,32}$/', $state ) ? $state : 'not_installed';
	}

	/**
	 * The short line task start carries for an agent: how to use native Elementor, or why it is not usable.
	 * Empty when there is nothing to act on.
	 *
	 * @param array<string,mixed> $report
	 */
	public static function hint( array $report ): string {
		$state = (string) ( $report['state'] ?? '' );
		if ( 'available' === $state ) {
			return 'Native Elementor is available: for V4 documents prefer stonewright-elementor-native-execute for certified abilities, else the Stonewright V4 writers; V3 stays on the V3 writers; never call elementor/* on Elementor\'s own MCP server.';
		}
		return 'available_uncertified' === $state ? (string) ( $report['guidance'] ?? '' ) : '';
	}

	/**
	 * Whether stonewright-elementor-native-execute can run an ability at all: it is certified and its
	 * contract does not refuse the native route.
	 *
	 * @param array<string,mixed>|null $contract
	 */
	public static function routable( string $state, ?array $contract ): bool {
		return 'certified' === $state && is_array( $contract ) && in_array( (string) ( $contract['routing']['native_write'] ?? '' ), [ 'allowed', 'read_only' ], true );
	}

	/**
	 * Whether a certified ability may be written natively through stonewright-elementor-native-execute.
	 *
	 * @param array<string,mixed>|null $contract
	 */
	public static function routable_write( string $state, ?array $contract ): bool {
		return self::routable( $state, $contract ) && 'write' === ( $contract['access'] ?? '' ) && 'allowed' === ( $contract['routing']['native_write'] ?? '' );
	}

	/** The Elementor versions the shipped contracts cover, as one range. */
	public static function certified_range(): string {
		$min = '';
		$max = '';
		foreach ( NativeContracts::names() as $name ) {
			$contract = NativeContracts::for_ability( $name );
			if ( ! is_array( $contract ) || 'certifiable' !== ( $contract['status'] ?? '' ) ) {
				continue;
			}
			foreach ( (array) $contract['schemas'] as $schema ) {
				$low  = (string) ( $schema['elementor_versions']['min'] ?? '' );
				$high = (string) ( $schema['elementor_versions']['max'] ?? '' );
				if ( '' !== $low && ( '' === $min || version_compare( $low, $min, '<' ) ) ) {
					$min = $low;
				}
				if ( '' !== $high && ( '' === $max || version_compare( str_replace( '*', '9999', $high ), str_replace( '*', '9999', $max ), '>' ) ) ) {
					$max = $high;
				}
			}
		}
		return '' === $min ? '' : $min . ' - ' . $max;
	}

	/**
	 * One short instruction for the agent, from the state alone.
	 *
	 * @param array<string,mixed>|null $version_issue
	 */
	private static function guidance( string $state, ?array $version_issue ): string {
		if ( 'available' === $state ) {
			return 'Certified Elementor abilities run through stonewright-elementor-native-execute. For a V4 (Atomic) document prefer it for the certified abilities and fall back to the Stonewright V4 writers otherwise; V3 stays on the V3 writers. Never call elementor/* tools on Elementor\'s own MCP server: that skips backups, the change history and the audit.';
		}
		if ( 'available_uncertified' !== $state ) {
			return '';
		}
		if ( is_array( $version_issue ) ) {
			$observed = '' === (string) $version_issue['observed'] ? 'unknown' : (string) $version_issue['observed'];
			return sprintf(
				'Elementor %1$s is outside the certified range %2$s (%3$s), so native writes are refused. Use the Stonewright V4 writers for V4 documents and the V3 writers for V3, and do not call elementor/* tools on Elementor\'s own MCP server.',
				$observed,
				(string) $version_issue['certified_range'],
				implode( ', ', array_map( 'strval', (array) $version_issue['issues'] ) )
			);
		}
		return 'Elementor\'s MCP abilities are registered but none passed certification, so native writes are refused. Use the Stonewright V4 writers for V4 documents and the V3 writers for V3, and do not call elementor/* tools on Elementor\'s own MCP server.';
	}

	/** A certified ability whose contract refuses native writes is reported as such, never as preferred. */
	public static function selection( string $state, string $access, string $native_write = 'allowed' ): string {
		if ( 'certified' !== $state ) {
			return 'unsupported';
		}
		if ( 'read' === $access ) {
			return 'native-readback';
		}
		return 'refused' === $native_write ? 'native-write-refused' : 'native-preferred';
	}

	/** @param array<string,mixed> $environment @param list<string> $certified */
	private static function state( array $environment, int $abilities, array $certified ): string {
		if ( true !== ( $environment['elementor']['installed'] ?? false ) ) {
			return 'not_installed';
		}
		if ( $abilities > 0 ) {
			return [] === $certified ? 'available_uncertified' : 'available';
		}
		$module = (array) ( $environment['mcp_module'] ?? [] );
		if ( true !== ( $module['present'] ?? false ) ) {
			return 'module_unavailable';
		}
		if ( [] !== ( $module['missing'] ?? [] ) ) {
			return 'requirements_missing';
		}
		return true !== ( $module['site_exposure_enabled'] ?? false ) ? 'exposure_disabled' : 'no_abilities_registered';
	}

	/** @param array<string,array<string,mixed>> $upstream @return array{status:string,provider_ids:list<string>,source_version:string} */
	private static function ownership( array $upstream ): array {
		$ids      = [];
		$official = 0;
		$version  = '';
		foreach ( $upstream as $ability ) {
			$id = RuntimeOwnership::provider_id( (string) ( $ability['source_plugin'] ?? ( $ability['meta']['source_plugin'] ?? '' ) ) );
			if ( ! in_array( $id, $ids, true ) ) {
				$ids[] = $id;
			}
			if ( in_array( $id, [ 'elementor-core', 'elementor-pro' ], true ) ) {
				++$official;
			}
			if ( '' === $version && 'elementor-core' === $id ) {
				$version = substr( (string) ( $ability['source_version'] ?? '' ), 0, 100 );
			}
		}
		sort( $ids );
		$status = 'none';
		if ( [] !== $upstream ) {
			$status = count( $upstream ) === $official ? 'official' : ( 0 === $official ? 'third-party' : 'mixed' );
		}
		return [ 'status' => $status, 'provider_ids' => array_slice( $ids, 0, self::MAX_PROVIDERS ), 'source_version' => $version ];
	}
}
