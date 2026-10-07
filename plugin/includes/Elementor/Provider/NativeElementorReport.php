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
		foreach ( $certifications as $name => $result ) {
			$state = (string) ( $result['state'] ?? 'unsupported' );
			$contract = NativeContracts::for_ability( (string) $name );
			$certification[ $name ] = [
				'state'     => $state,
				'selection' => self::selection( $state, (string) ( $contract['access'] ?? 'write' ) ),
				'reason'    => (string) ( $result['reason'] ?? '' ),
				'issues'    => array_slice( array_values( array_map( 'strval', (array) ( $result['issues'] ?? [] ) ) ), 0, self::MAX_ISSUES ),
			];
			if ( 'certified' === $state ) {
				$certified[] = (string) $name;
			} else {
				$unsupported[ $name ] = (string) ( $result['reason'] ?? '' );
			}
		}

		return [
			'schema'                        => self::SCHEMA,
			'state'                         => self::state( $environment, count( $names ), $certified ),
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
			'writes_enabled'                => false,
			'routable_write'                => false,
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

	public static function selection( string $state, string $access ): string {
		if ( 'certified' !== $state ) {
			return 'unsupported';
		}
		return 'read' === $access ? 'native-readback' : 'native-preferred';
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
