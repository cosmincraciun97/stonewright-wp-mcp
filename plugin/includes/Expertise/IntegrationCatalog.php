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
			self::definition( 'elementor', 'Elementor', 'P0', 'typed', [ 'ELEMENTOR_VERSION', 'plugin:elementor/elementor.php' ] ),
			self::definition( 'elementor-pro', 'Elementor Pro', 'P1', 'typed', [ 'ELEMENTOR_PRO_VERSION' ] ),
			self::definition( 'woocommerce', 'WooCommerce', 'P1', 'typed', [ 'WC_VERSION' ] ),
			self::definition( 'acf', 'Advanced Custom Fields', 'P1', 'typed', [ 'ACF_VERSION' ] ),
			self::definition( 'bricks', 'Bricks', 'P2', 'discovery', [ 'BRICKS_VERSION' ] ),
			self::definition( 'beaver-builder', 'Beaver Builder', 'P2', 'discovery', [ 'FL_BUILDER_VERSION', 'class:FLBuilderModel' ] ),
			self::definition( 'divi', 'Divi 5', 'P2', 'discovery', [ 'ET_BUILDER_VERSION', 'ET_CORE_VERSION' ] ),
			self::definition( 'breakdance', 'Breakdance', 'P2', 'discovery', [ 'BREAKDANCE_VERSION', 'function:Breakdance\\Data\\get_global_option', 'plugin:breakdance/plugin.php' ] ),
			self::definition( 'wpbakery', 'WPBakery Page Builder', 'P2', 'discovery', [ 'WPB_VC_VERSION', 'plugin:js_composer/js_composer.php' ] ),
			self::definition( 'etch', 'Etch', 'P2', 'discovery', [ 'ETCH_VERSION', 'class:Etch\\Plugin', 'plugin:etch/etch.php' ] ),
			self::definition( 'mosaic', 'Mosaic', 'P2', 'discovery', [ 'MOSAIC_VERSION', 'class:Mosaic\\Database\\MosaicDB', 'plugin:mosaic/mosaic.php' ] ),
			self::definition( 'generatepress', 'GeneratePress', 'P2', 'typed', [ 'function:generate_get_option', 'theme:generatepress' ] ),
			self::definition( 'astra', 'Astra', 'P2', 'discovery', [ 'theme:astra' ] ),
			self::definition( 'kadence', 'Kadence', 'P2', 'typed', [ 'class:Kadence\\Theme', 'theme:kadence' ] ),
			self::definition( 'blocksy', 'Blocksy', 'P2', 'typed', [ 'theme:blocksy', 'BLOCKSY_VERSION' ] ),
			self::definition( 'avada', 'Avada', 'P2', 'discovery', [ 'theme:Avada' ] ),
			self::definition( 'oceanwp', 'OceanWP', 'P2', 'discovery', [ 'theme:oceanwp' ] ),
			self::definition( 'spectra-one', 'Spectra One', 'P2', 'discovery', [ 'theme:spectra-one' ] ),
			self::definition( 'generateblocks', 'GenerateBlocks', 'P2', 'discovery', [ 'GENERATEBLOCKS_VERSION', 'plugin:generateblocks/plugin.php' ] ),
			self::definition( 'kadence-blocks', 'Kadence Blocks', 'P2', 'discovery', [ 'KADENCE_BLOCKS_VERSION', 'plugin:kadence-blocks/kadence-blocks.php' ] ),
			self::definition( 'spectra', 'Spectra', 'P2', 'discovery', [ 'UAGB_VER', 'plugin:ultimate-addons-for-gutenberg/ultimate-addons-for-gutenberg.php' ] ),
			self::definition( 'wpforms', 'WPForms', 'P1', 'discovery', [ 'WPFORMS_VERSION', 'plugin:wpforms-lite/wpforms.php', 'plugin:wpforms/wpforms.php' ] ),
			self::definition( 'contact-form-7', 'Contact Form 7', 'P1', 'discovery', [ 'WPCF7_VERSION', 'plugin:contact-form-7/wp-contact-form-7.php' ] ),
			self::definition( 'gravity-forms', 'Gravity Forms', 'P1', 'discovery', [ 'GFForms::version', 'class:GFForms', 'plugin:gravityforms/gravityforms.php' ] ),
			self::definition( 'fluent-forms', 'Fluent Forms', 'P1', 'discovery', [ 'FLUENTFORM_VERSION', 'FLUENTFORM', 'plugin:fluentform/fluentform.php' ] ),
			self::definition( 'ninja-forms', 'Ninja Forms', 'P1', 'discovery', [ 'NINJA_FORMS_VERSION', 'class:Ninja_Forms', 'plugin:ninja-forms/ninja-forms.php' ] ),
			self::definition( 'formidable-forms', 'Formidable Forms', 'P1', 'discovery', [ 'FRM_VERSION', 'class:FrmAppHelper', 'plugin:formidable/formidable.php' ] ),
			self::definition( 'jetengine', 'JetEngine', 'P1', 'discovery', [ 'JET_ENGINE_VERSION', 'function:jet_engine', 'plugin:jet-engine/jet-engine.php' ] ),
			self::definition( 'meta-box', 'Meta Box', 'P1', 'discovery', [ 'RWMB_VER', 'plugin:meta-box/meta-box.php' ] ),
			self::definition( 'acpt', 'ACPT', 'P1', 'discovery', [ 'ACPT_PLUGIN_VERSION', 'ACPT_VERSION', 'plugin:acpt/acpt.php' ] ),
			self::definition( 'pods', 'Pods', 'P1', 'discovery', [ 'PODS_VERSION', 'plugin:pods/init.php' ] ),
			self::definition( 'ase', 'Admin and Site Enhancements', 'P1', 'discovery', [ 'ASENHA_VERSION', 'plugin:admin-site-enhancements/admin-site-enhancements.php' ] ),
			self::definition( 'bricksforge', 'Bricksforge', 'P2', 'discovery', [ 'BRICKSFORGE_VERSION', 'plugin:bricksforge/bricksforge.php' ] ),
			self::definition( 'dynamic-shortcodes', 'Dynamic Shortcodes', 'P2', 'discovery', [ 'DYNAMIC_SHORTCODES_VERSION', 'plugin:dynamic-shortcodes/dynamic-shortcodes.php' ] ),
			self::definition( 'code-snippets', 'Code Snippets', 'P2', 'discovery', [ 'CODE_SNIPPETS_VERSION', 'plugin:code-snippets/code-snippets.php' ] ),
			self::definition( 'yoast-seo', 'Yoast SEO', 'P2', 'discovery', [ 'WPSEO_VERSION', 'plugin:wordpress-seo/wp-seo.php' ] ),
			self::definition( 'rank-math', 'Rank Math', 'P2', 'discovery', [ 'RANK_MATH_VERSION', 'plugin:seo-by-rank-math/rank-math.php' ] ),
			self::definition( 'all-in-one-seo', 'All in One SEO', 'P2', 'discovery', [ 'AIOSEO_VERSION', 'plugin:all-in-one-seo-pack/all_in_one_seo_pack.php' ] ),
			self::definition( 'seopress', 'SEOPress', 'P2', 'discovery', [ 'SEOPRESS_VERSION', 'plugin:wp-seopress/wp-seopress.php' ] ),
		];
	}

	/** @param list<string> $version_sources @return array<string, mixed> */
	private static function definition( string $id, string $label, string $tier, string $adapter, array $version_sources ): array {
		return compact( 'id', 'label', 'tier', 'adapter', 'version_sources' );
	}

	/** @param list<string> $sources */
	private static function detected_version( array $sources ): string {
		foreach ( $sources as $source ) {
			if ( str_starts_with( $source, 'class:' ) ) {
				if ( class_exists( substr( $source, 6 ) ) ) {
					return 'active';
				}
				continue;
			}
			if ( str_starts_with( $source, 'function:' ) ) {
				if ( function_exists( substr( $source, 9 ) ) ) {
					return 'active';
				}
				continue;
			}
			if ( str_starts_with( $source, 'theme:' ) ) {
				$stylesheet = substr( $source, 6 );
				if ( function_exists( 'wp_get_theme' ) ) {
					$theme = wp_get_theme();
					if (
						is_object( $theme )
						&& method_exists( $theme, 'get_stylesheet' )
						&& method_exists( $theme, 'get_template' )
						&& method_exists( $theme, 'get' )
						&& in_array( strtolower( $stylesheet ), [ strtolower( (string) $theme->get_stylesheet() ), strtolower( (string) $theme->get_template() ) ], true )
					) {
						return sanitize_text_field( (string) $theme->get( 'Version' ) );
					}
				}
				continue;
			}
			if ( str_starts_with( $source, 'plugin:' ) ) {
				$plugin = substr( $source, 7 );
				$active = (array) get_option( 'active_plugins', [] );
				if ( ! in_array( $plugin, $active, true ) || ! defined( 'WP_PLUGIN_DIR' ) ) {
					continue;
				}
				$file = (string) constant( 'WP_PLUGIN_DIR' ) . '/' . $plugin;
				if ( is_readable( $file ) && function_exists( 'get_file_data' ) ) {
					$data    = get_file_data( $file, [ 'version' => 'Version' ] );
					$version = sanitize_text_field( (string) ( $data['version'] ?? '' ) );
					if ( '' !== $version ) {
						return $version;
					}
				}
				return 'active';
			}
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
