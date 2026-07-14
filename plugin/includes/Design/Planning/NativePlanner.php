<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Design\Planning;

use Stonewright\WpMcp\Design\Evidence\Validator;
use Stonewright\WpMcp\Elementor\Schema\ContainerSchemaRepository;
use Stonewright\WpMcp\Elementor\Schema\WidgetSchemaRepository;

/** Builds deterministic native-first plans without emitting raw builder settings. */
final class NativePlanner {

	/**
	 * @param array<string, mixed> $evidence
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function plan( array $evidence, string $target ): array|\WP_Error {
		$validated = Validator::validate( $evidence );
		if ( $validated instanceof \WP_Error ) {
			return $validated;
		}
		// phpcs:disable WordPress.WP.CapitalPDangit.MisspelledInText -- Machine-readable target ids.
		if ( ! in_array( $target, [ 'elementor-v3', 'elementor-v4', 'gutenberg', 'wordpress' ], true ) ) {
			return new \WP_Error( 'stonewright_native_target_invalid', 'Choose elementor-v3, elementor-v4, gutenberg, or wordpress.' );
		}
		// phpcs:enable WordPress.WP.CapitalPDangit.MisspelledInText
		if ( 'elementor-v4' === $target ) {
			return [
				'ok'                       => false,
				'status'                   => 'blocked',
				'target'                   => $target,
				'evidence_schema_version'  => Validator::VERSION,
				'evidence_hash'            => $validated['evidence_hash'],
				'native_coverage_percent'  => 0,
				'native_phase'              => [
					'applied'    => false,
					'sequence'   => [],
					'operations' => [],
				],
				'blockers'                  => [
					[
						'code'   => 'elementor_v4_native_planner_not_promoted',
						'repair' => 'Use the separate V4 schema/fixture engine after its runtime adapter is available; V3 fallback is forbidden.',
					],
				],
				'customization_proposal'    => [],
				'phase_2_requires_approval' => true,
				'custom_code_applied'       => false,
			];
		}

		$operations    = [];
		$blockers      = array_values( (array) ( $validated['evidence']['unresolved'] ?? [] ) );
		$customization = [];
		$mapped        = 0;
		$total         = 0;
		foreach ( (array) ( $validated['evidence']['global']['provenance'] ?? [] ) as $setting => $row ) {
			if ( is_array( $row ) && true === ( $row['requires_confirmation'] ?? false ) ) {
				$blockers[] = [
					'code'   => 'evidence_confirmation_required',
					'repair' => 'Approve or replace inferred global evidence for ' . (string) $setting . '.',
				];
			}
		}
		foreach ( self::flatten( (array) $validated['evidence']['nodes'] ) as $node ) {
			++$total;
			$primitive = self::primitive( (string) ( $node['role'] ?? '' ), $target, $node );
			if ( $primitive instanceof \WP_Error ) {
				$blockers[] = [
					'node_id' => (string) ( $node['id'] ?? '' ),
					'code'    => $primitive->get_error_code(),
					'repair'  => $primitive->get_error_message(),
				];
			} else {
				++$mapped;
				$operations[] = [
					'node_id'       => (string) ( $node['id'] ?? '' ),
					'role'          => (string) ( $node['role'] ?? '' ),
					'native_target' => $primitive,
					'content'       => (array) ( $node['content'] ?? [] ),
					'layout_intent' => (array) ( $node['layout'] ?? [] ),
					'style_intent'  => (array) ( $node['style'] ?? [] ),
					'provenance'    => (array) ( $node['provenance'] ?? [] ),
					'write_settings' => 'compile_against_live_schema_after_plan_approval',
				];
			}

			foreach ( (array) ( $node['provenance'] ?? [] ) as $setting => $row ) {
				if ( is_array( $row ) && true === ( $row['requires_confirmation'] ?? false ) ) {
					$blockers[] = [
						'node_id' => (string) ( $node['id'] ?? '' ),
						'code'    => 'evidence_confirmation_required',
						'repair'  => 'Approve or replace inferred evidence for ' . (string) $setting . '.',
					];
				}
			}

			foreach ( (array) ( $node['customization_needs'] ?? [] ) as $need ) {
				if ( is_array( $need ) ) {
					$customization[] = self::customization_proposal( $node, $need );
				}
			}

			foreach ( (array) ( $node['unresolved'] ?? [] ) as $unresolved ) {
				if ( is_array( $unresolved ) ) {
					$blockers[] = [
						'node_id' => (string) ( $node['id'] ?? '' ),
						'code'    => (string) $unresolved['code'],
						'repair'  => (string) $unresolved['repair'],
					];
				}
			}
		}

		return [
			'ok'                       => [] === $blockers,
			'status'                   => [] === $blockers ? 'ready_for_native_dry_run' : 'blocked',
			'target'                   => $target,
			'evidence_schema_version'  => Validator::VERSION,
			'evidence_hash'            => $validated['evidence_hash'],
			'native_coverage_percent'  => 0 === $total ? 0 : round( ( $mapped / $total ) * 100, 2 ),
			'native_phase'              => [
				'applied'    => false,
				'sequence'   => [ 'global_styles', 'content_model', 'native_structure', 'native_widgets', 'responsive_settings', 'dry_run', 'approval_then_write', 'readback' ],
				'operations' => $operations,
			],
			'blockers'                  => $blockers,
			'customization_proposal'    => $customization,
			'phase_2_requires_approval' => true,
			'custom_code_applied'       => false,
		];
	}

	/**
	 * @param array<string, mixed> $node
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function primitive( string $role, string $target, array $node ): array|\WP_Error {
		$role = strtolower( $role );
		if ( 'elementor-v3' === $target ) {
			$widget = self::elementor_widget( $role, $node );
			if ( 'container' === $widget ) {
				$schema = ContainerSchemaRepository::get();
				return $schema instanceof \WP_Error ? $schema : [ 'kind' => 'element', 'name' => 'container', 'schema_hash' => $schema['schema_hash'], 'source' => $schema['source'] ];
			}
			if ( str_starts_with( $widget, 'theme-builder-' ) ) {
				return [ 'kind' => 'wordpress_elementor_template', 'name' => $widget, 'prerequisite' => 'theme_builder_conditions' ];
			}
			$schema = WidgetSchemaRepository::get( $widget );
			if ( $schema instanceof \WP_Error ) {
				return new \WP_Error( 'stonewright_native_widget_unavailable', sprintf( 'Install/activate a native widget for role %s or revise the evidence.', $role ) );
			}
			return [ 'kind' => 'widget', 'name' => $widget, 'schema_hash' => $schema['schema_hash'], 'source_plugin' => $schema['source_plugin'] ];
		}

		if ( 'gutenberg' === $target ) {
			$block = self::gutenberg_block( $role );
			if ( '' === $block ) {
				return new \WP_Error( 'stonewright_native_block_unavailable', 'No verified native Gutenberg block maps this semantic role.' );
			}
			return [ 'kind' => 'block', 'name' => $block, 'source' => 'wordpress_core_block_contract' ];
		}

		// phpcs:ignore WordPress.WP.CapitalPDangit.MisspelledInText -- Machine-readable target id.
		if ( 'wordpress' === $target ) {
			$content_model = isset( $node['content_model'] ) && is_array( $node['content_model'] ) ? $node['content_model'] : [];
			return match ( $role ) {
				'navigation'     => [ 'kind' => 'wordpress_menu', 'name' => 'wp_navigation' ],
				'repeated-cards' => [ 'kind' => 'content_model', 'name' => (string) ( $content_model['mode'] ?? 'static' ) ],
				'header', 'footer' => [ 'kind' => 'template_part', 'name' => $role ],
				default          => [ 'kind' => 'wordpress_content', 'name' => $role ],
			};
		}

		return new \WP_Error( 'stonewright_native_target_unavailable', 'The requested native target is unavailable.' );
	}

	/** @param array<string, mixed> $node */
	private static function elementor_widget( string $role, array $node ): string {
		$content_model = isset( $node['content_model'] ) && is_array( $node['content_model'] ) ? $node['content_model'] : [];

		return match ( $role ) {
			'section', 'group', 'container' => 'container',
			'heading'               => 'heading',
			'paragraph', 'text'     => 'text-editor',
			'image'                 => 'image',
			'gallery'               => 'image-gallery',
			'button', 'cta', 'link' => 'button',
			'navigation'            => 'nav-menu',
			'form'                  => 'form',
			'tabs'                  => 'tabs',
			'accordion'             => 'accordion',
			'carousel'              => 'image-carousel',
			'countdown'             => 'countdown',
			'social-links'          => 'social-icons',
			'icon-list'             => 'icon-list',
			'video'                 => 'video',
			'repeated-cards'        => 'dynamic' === ( $content_model['mode'] ?? '' ) ? 'loop-grid' : 'container',
			'header'                => 'theme-builder-header',
			'footer'                => 'theme-builder-footer',
			default                 => '',
		};
	}

	private static function gutenberg_block( string $role ): string {
		return match ( $role ) {
			'section', 'group', 'container' => 'core/group',
			'heading'               => 'core/heading',
			'paragraph', 'text'     => 'core/paragraph',
			'image'                 => 'core/image',
			'gallery', 'carousel'   => 'core/gallery',
			'button', 'cta', 'link' => 'core/button',
			'navigation'            => 'core/navigation',
			'icon-list'             => 'core/list',
			'video'                 => 'core/video',
			'header', 'footer'      => 'core/template-part',
			default                 => '',
		};
	}

	/** @param list<array<string, mixed>> $nodes @return list<array<string, mixed>> */
	private static function flatten( array $nodes ): array {
		$out = [];
		foreach ( $nodes as $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}
			$out[] = $node;
			$out   = array_merge( $out, self::flatten( (array) ( $node['children'] ?? [] ) ) );
		}
		return $out;
	}

	/** @param array<string, mixed> $node @param array<string, mixed> $need @return array<string, mixed> */
	private static function customization_proposal( array $node, array $need ): array {
		return [
			'node_id'              => (string) ( $node['id'] ?? '' ),
			'delta'                => (string) ( $need['delta'] ?? '' ),
			'reason_native_stops'  => (string) ( $need['reason'] ?? 'No verified native control covers the remaining delta.' ),
			'options'              => [
				[ 'type' => 'css', 'risk' => 'low', 'file_policy' => 'versioned active-theme stylesheet', 'requires_scoped_semantic_selector' => true ],
				[ 'type' => 'css_js', 'risk' => 'medium', 'file_policy' => 'versioned active-theme assets', 'only_for_missing_behavior' => true ],
				[ 'type' => 'custom_php', 'risk' => 'high', 'file_policy' => 'versioned plugin or child-theme file', 'requires_static_analysis_and_tests' => true ],
			],
			'required_detail'      => [ 'exact_files', 'semantic_selectors', 'diff', 'impact', 'risk', 'rollback', 'test_plan', 'confirmation_request' ],
			'applied'              => false,
			'requires_approval'    => true,
			'requires_diff'        => true,
			'requires_risk_review' => true,
			'requires_rollback'    => true,
			'requires_tests'       => true,
		];
	}
}
