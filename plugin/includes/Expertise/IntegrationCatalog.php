<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Expertise;

/** Runtime integration inventory with explicit support levels. */
final class IntegrationCatalog {

	/** @return list<array{id:string,label:string,tier:string,adapter:string,version:string,status:string,reason:string}> */
	public static function inspect(): array {
		$definitions = self::definitions();
		$rows        = [];
		foreach ( $definitions as $definition ) {
			$version = self::detected_version( (array) $definition['version_sources'] );
			$adapter = (string) $definition['adapter'];
			$rows[]  = [
				'id'      => (string) $definition['id'],
				'label'   => (string) $definition['label'],
				'tier'    => (string) $definition['tier'],
				'adapter' => $adapter,
				'version' => $version,
				'status'  => '' === $version ? 'unavailable' : ( 'typed' === $adapter ? 'supported' : 'discovery-only' ),
				'reason'  => '' === $version
					? 'plugin_or_theme_not_detected'
					: ( 'typed' === $adapter ? 'stable_api_adapter_available' : 'live_schema_or_official_api_required_before_write' ),
			];
		}
		return $rows;
	}

	/** @return list<array<string, mixed>> */
	public static function definitions(): array {
		return [
			self::definition( 'elementor-pro', 'Elementor Pro', 'P1', 'typed', [ 'ELEMENTOR_PRO_VERSION' ] ),
			self::definition( 'woocommerce', 'WooCommerce', 'P1', 'typed', [ 'WC_VERSION' ] ),
			self::definition( 'acf', 'Advanced Custom Fields', 'P1', 'typed', [ 'ACF_VERSION' ] ),
			self::definition( 'contact-form-7', 'Contact Form 7', 'P1', 'discovery', [ 'WPCF7_VERSION' ] ),
			self::definition( 'gravity-forms', 'Gravity Forms', 'P1', 'discovery', [ 'GFForms::version' ] ),
			self::definition( 'fluent-forms', 'Fluent Forms', 'P1', 'discovery', [ 'FLUENTFORM_VERSION', 'FLUENTFORM' ] ),
			self::definition( 'yoast-seo', 'Yoast SEO', 'P2', 'discovery', [ 'WPSEO_VERSION' ] ),
			self::definition( 'rank-math', 'Rank Math', 'P2', 'discovery', [ 'RANK_MATH_VERSION' ] ),
			self::definition( 'bricks', 'Bricks', 'P2', 'discovery', [ 'BRICKS_VERSION' ] ),
			self::definition( 'beaver-builder', 'Beaver Builder', 'P2', 'discovery', [ 'FL_BUILDER_VERSION' ] ),
			self::definition( 'divi', 'Divi', 'P2', 'discovery', [ 'ET_CORE_VERSION' ] ),
		];
	}

	/** @param list<string> $version_sources @return array<string, mixed> */
	private static function definition( string $id, string $label, string $tier, string $adapter, array $version_sources ): array {
		return compact( 'id', 'label', 'tier', 'adapter', 'version_sources' );
	}

	/** @param list<string> $sources */
	private static function detected_version( array $sources ): string {
		foreach ( $sources as $source ) {
			if ( str_contains( $source, '::' ) ) {
				[ $class, $property ] = explode( '::', $source, 2 );
				if ( class_exists( $class ) && property_exists( $class, $property ) ) {
					/** @var mixed $value */
					$value = $class::${$property};
					if ( is_scalar( $value ) && '' !== (string) $value ) {
						return sanitize_text_field( (string) $value );
					}
				}
				continue;
			}
			if ( defined( $source ) && is_scalar( constant( $source ) ) ) {
				return sanitize_text_field( (string) constant( $source ) );
			}
		}
		return '';
	}
}
