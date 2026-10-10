<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Elementor\Provider;

/**
 * Decides where a write goes: Elementor's own ability, a Stonewright writer, or nowhere.
 *
 * An ability routes native only when it is certified and its contract allows a native write.
 * Documents route per subtree and are never converted between V3 and V4. Nothing here executes.
 */
final class NativeRoute {

	public const NATIVE         = 'native';
	public const FALLBACK       = 'stonewright_v4_fallback';
	public const STONEWRIGHT_V3 = 'stonewright_v3';
	public const REFUSED        = 'refused';

	/** @var array<string, list<string>> Stonewright writers that cover the same ground, by contract family or ability. */
	private const FALLBACKS = [
		'elementor/manage-classes'         => [ 'stonewright/elementor-v4-create-class', 'stonewright/elementor-v4-update-class' ],
		'elementor/manage-global-variable' => [ 'stonewright/elementor-v4-create-variable', 'stonewright/elementor-v4-update-variable' ],
		'tree_composition'                 => [ 'stonewright/elementor-v4-render-from-spec', 'stonewright/elementor-v4-update-node', 'stonewright/design-spec-to-elementor-v4' ],
	];

	/** @var list<string> */
	private const V3_WRITERS = [ 'stonewright/elementor-v3-batch-mutate', 'stonewright/elementor-v3-build-page-from-spec' ];

	/** @var array<string, string> Environment state to the feature Elementor is missing. */
	private const MISSING_FEATURE = [
		'not_installed'           => 'elementor',
		'module_unavailable'      => 'elementor_mcp_module',
		'requirements_missing'    => 'elementor_mcp_requirements',
		'exposure_disabled'       => 'elementor_mcp_site_exposure',
		'no_abilities_registered' => 'elementor_mcp_abilities',
	];

	/**
	 * @param array<string,mixed> $block The `native_elementor` report.
	 * @return array{route:string,reason:string,ability:string,family:string,read_only:bool,fallback:list<string>,issues:list<string>,missing_feature:?string}
	 */
	public static function for_ability( string $ability, array $block ): array {
		$contract      = NativeContracts::for_ability( $ability );
		$certification = is_array( $block['certification'][ $ability ] ?? null ) ? $block['certification'][ $ability ] : null;
		$family        = (string) ( $contract['routing']['family'] ?? '' );
		$fallback      = self::FALLBACKS[ $ability ] ?? self::FALLBACKS[ $family ] ?? [];
		$base          = [
			'route'           => self::FALLBACK,
			'reason'          => 'not_available_for_certification',
			'ability'         => $ability,
			'family'          => $family,
			'read_only'       => false,
			'fallback'        => $fallback,
			'issues'          => [],
			'missing_feature' => null,
		];
		if ( null === $contract || null === $certification ) {
			if ( null !== $contract ) {
				$base['reason'] = 'upstream_ability_not_registered';
				$base['missing_feature'] = self::MISSING_FEATURE[ (string) ( $block['state'] ?? '' ) ] ?? 'elementor_ability:' . $ability;
			}
			return $base;
		}
		if ( 'unsupported' === ( $contract['status'] ?? '' ) ) {
			return array_merge( $base, [ 'reason' => (string) $contract['reason'] ] );
		}
		if ( 'certified' !== ( $certification['state'] ?? '' ) ) {
			$reason = (string) ( $certification['reason'] ?? 'upstream_contract_not_certified' );
			return array_merge(
				$base,
				[
					'reason'          => $reason,
					'issues'          => array_values( array_map( 'strval', (array) ( $certification['issues'] ?? [] ) ) ),
					'missing_feature' => 'upstream_ability_not_registered' === $reason ? ( self::MISSING_FEATURE[ (string) ( $block['state'] ?? '' ) ] ?? 'elementor_ability:' . $ability ) : null,
				]
			);
		}
		$write = (string) ( $contract['routing']['native_write'] ?? 'refused' );
		if ( 'refused' === $write ) {
			return array_merge( $base, [ 'reason' => (string) ( $contract['routing']['refusal_reason'] ?? 'native_write_refused' ) ] );
		}
		return array_merge( $base, [ 'route' => self::NATIVE, 'reason' => 'read_only' === $write ? 'certified_native_read' : 'certified_native_write', 'read_only' => 'read_only' === $write ] );
	}

	/**
	 * Routes a write by the architecture of the document and the kind of element it targets.
	 *
	 * @param string $architecture empty, v3, v4, mixed, or unknown.
	 * @param string $parent       document (root), atomic, v3, or missing.
	 * @return array{route:string,reason:string,conversion:bool,fallback:list<string>}
	 */
	public static function for_document( string $architecture, string $parent ): array {
		$decision = static fn( string $route, string $reason, array $fallback = [] ): array => [ 'route' => $route, 'reason' => $reason, 'conversion' => false, 'fallback' => $fallback ];

		if ( 'missing' === $parent ) {
			return $decision( self::REFUSED, 'parent_not_found' );
		}
		return match ( $architecture ) {
			'v3'            => $decision( self::STONEWRIGHT_V3, 'v3_document', self::V3_WRITERS ),
			'v4'            => $decision( self::NATIVE, 'v4_document' ),
			'empty'         => $decision( self::NATIVE, 'empty_document' ),
			'mixed'         => match ( $parent ) {
				'atomic' => $decision( self::NATIVE, 'mixed_document_atomic_subtree' ),
				'v3'     => $decision( self::STONEWRIGHT_V3, 'mixed_document_v3_subtree', self::V3_WRITERS ),
				default  => $decision( self::REFUSED, 'mixed_document_root_insert' ),
			},
			default         => $decision( self::REFUSED, 'document_architecture_unknown' ),
		};
	}
}
