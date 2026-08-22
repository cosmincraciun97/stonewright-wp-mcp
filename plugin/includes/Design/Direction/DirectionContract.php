<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Design\Direction;

/**
 * Schema definition for the Stonewright Design Direction contract.
 *
 * This class owns the locked shape: the schema version, the allowlisted keys,
 * and the hard size limits. It holds no behaviour beyond describing the
 * contract so the validator and sanitizer share a single source of truth.
 *
 * @phpstan-type DirectionContractShape array{
 *   schema_version:'1.0',
 *   identity:array{name:string,summary:string},
 *   tokens:array{
 *     colors:array<string,string>,
 *     typography:array<string,array<string,int|float|string>>,
 *     spacing:array<string,string>,
 *     radii:array<string,string>,
 *     elevation:array<string,string>,
 *     motion:array<string,int|string>
 *   },
 *   components:array<string,array<string,mixed>>,
 *   dials:array{variance:int,density:int,motion:int},
 *   guidance:array{do:list<string>,avoid:list<string>},
 *   provenance:array<string,array{source:string,reference:string}>,
 *   waivers:list<array{rule_id:string,reason:string}>,
 *   readiness:array{ready:bool,sync_ready:bool,issues:list<string>}
 * }
 */
final class DirectionContract {

	/** @var string The only contract schema version this release accepts. */
	public const SCHEMA_VERSION = '1.0';

	/** @var string Structured error code for every rejected direction payload. */
	public const ERROR_CODE = 'stonewright_direction_invalid';

	/** @var int Maximum items in any list or map field. */
	public const MAX_LIST_ITEMS = 100;

	/** @var int Maximum length of any single string value. */
	public const MAX_STRING_LENGTH = 2000;

	/** @var int Maximum accepted imported source size (1 MiB). */
	public const MAX_SOURCE_BYTES = 1048576;

	/** @var int Maximum encoded size of the structured contract (256 KiB). */
	public const MAX_CONTRACT_BYTES = 262144;

	/**
	 * Allowlisted top-level contract keys, in canonical order.
	 *
	 * Canonical order makes the encoded contract - and therefore its hash -
	 * independent of the order keys arrived in.
	 *
	 * @var list<string>
	 */
	public const TOP_LEVEL_KEYS = [
		'schema_version',
		'identity',
		'tokens',
		'components',
		'dials',
		'guidance',
		'provenance',
		'waivers',
		'readiness',
	];

	/**
	 * Allowlisted token groups, in canonical order.
	 *
	 * @var list<string>
	 */
	public const TOKEN_GROUPS = [
		'colors',
		'typography',
		'spacing',
		'radii',
		'elevation',
		'motion',
	];

	/**
	 * Allowlisted dial names. Each is an integer from 0 to 100.
	 *
	 * @var list<string>
	 */
	public const DIALS = [ 'variance', 'density', 'motion' ];

	/** @var int Lowest accepted dial value. */
	public const DIAL_MIN = 0;

	/** @var int Highest accepted dial value. */
	public const DIAL_MAX = 100;

	/**
	 * Allowlisted import source types. Mirrors the bounded `source_type` column.
	 *
	 * @var list<string>
	 */
	public const SOURCE_TYPES = [ 'manual', 'import', 'elementor', 'capture' ];

	/**
	 * The canonical empty contract, used to fill absent optional sections.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults(): array {
		return [
			'schema_version' => self::SCHEMA_VERSION,
			'identity'       => [
				'name'    => '',
				'summary' => '',
			],
			'tokens'         => [
				'colors'     => [],
				'typography' => [],
				'spacing'    => [],
				'radii'      => [],
				'elevation'  => [],
				'motion'     => [],
			],
			'components'     => [],
			'dials'          => [
				'variance' => 0,
				'density'  => 0,
				'motion'   => 0,
			],
			'guidance'       => [
				'do'    => [],
				'avoid' => [],
			],
			'provenance'     => [],
			'waivers'        => [],
			'readiness'      => [
				'ready'      => false,
				'sync_ready' => false,
				'issues'     => [],
			],
		];
	}

	/**
	 * Minimal valid contract for unknown-field error payloads.
	 *
	 * @return array<string,mixed>
	 */
	public static function minimal_example(): array {
		$example                       = self::defaults();
		$example['identity']['name']    = 'Example direction';
		$example['identity']['summary'] = 'Minimal valid contract.';
		$example['dials']               = [
			'variance' => 0,
			'density'  => 0,
			'motion'   => 0,
		];

		return $example;
	}

	/**
	 * Encode empty maps as JSON objects (`{}`) so capture output is save-ready.
	 *
	 * @param array<string,mixed> $contract Canonical contract.
	 * @return array<string,mixed>
	 */
	public static function for_transport( array $contract ): array {
		if ( isset( $contract['tokens'] ) && is_array( $contract['tokens'] ) ) {
			foreach ( self::TOKEN_GROUPS as $group ) {
				if ( isset( $contract['tokens'][ $group ] ) && is_array( $contract['tokens'][ $group ] ) && [] === $contract['tokens'][ $group ] ) {
					$contract['tokens'][ $group ] = new \stdClass();
				}
			}
		}
		foreach ( [ 'components', 'provenance' ] as $key ) {
			if ( isset( $contract[ $key ] ) && is_array( $contract[ $key ] ) && [] === $contract[ $key ] ) {
				$contract[ $key ] = new \stdClass();
			}
		}

		return $contract;
	}
}
