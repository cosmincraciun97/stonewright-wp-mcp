<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Core;

/**
 * Deterministic method-selection contract for Stonewright work.
 *
 * Order: typed_api → editor_command_bus → admin_form → browser_ui.
 */
final class MethodRouter {

	public const TYPED_API           = 'typed_api';
	public const EDITOR_COMMAND_BUS  = 'editor_command_bus';
	public const ADMIN_FORM          = 'admin_form';
	public const BROWSER_UI          = 'browser_ui';

	/**
	 * Capability matrix: operation class → preferred methods (ordered).
	 *
	 * @return array<string, list<string>>
	 */
	public static function capability_matrix(): array {
		return [
			'elementor_document_data' => [ self::TYPED_API, self::EDITOR_COMMAND_BUS ],
			'elementor_editor_only'   => [ self::EDITOR_COMMAND_BUS, self::BROWSER_UI ],
			'wordpress_content'      => [ self::TYPED_API ],
			'wordpress_options'      => [ self::TYPED_API, self::ADMIN_FORM ],
			'admin_only_setting'     => [ self::ADMIN_FORM, self::BROWSER_UI ],
			'visual_verification'    => [ self::BROWSER_UI ],
			'device_preview_switch'  => [ self::EDITOR_COMMAND_BUS, self::BROWSER_UI ],
		];
	}

	/**
	 * @param array<string, mixed> $context Optional hints (has_typed_ability, editor_loaded, …).
	 * @return array{method: string, reason: string, candidates: list<string>}
	 */
	public static function select( string $operation_class, array $context = [] ): array {
		$matrix     = self::capability_matrix();
		$candidates = $matrix[ $operation_class ] ?? [ self::TYPED_API, self::EDITOR_COMMAND_BUS, self::ADMIN_FORM, self::BROWSER_UI ];

		foreach ( $candidates as $method ) {
			if ( self::is_available( $method, $context ) ) {
				return [
					'method'     => $method,
					'reason'     => self::reason( $method, $operation_class, $context ),
					'candidates' => $candidates,
				];
			}
		}

		return [
			'method'     => self::BROWSER_UI,
			'reason'     => 'fallback_no_programmatic_path',
			'candidates' => $candidates,
		];
	}

	/**
	 * @param array<string, mixed> $context
	 */
	private static function is_available( string $method, array $context ): bool {
		return match ( $method ) {
			self::TYPED_API          => (bool) ( $context['has_typed_ability'] ?? true ),
			self::EDITOR_COMMAND_BUS => (bool) ( $context['editor_loaded'] ?? false ) || (bool) ( $context['allow_command_bus'] ?? false ),
			self::ADMIN_FORM         => (bool) ( $context['has_admin_form'] ?? false ),
			self::BROWSER_UI         => (bool) ( $context['browser_available'] ?? true ),
			default                  => false,
		};
	}

	/**
	 * @param array<string, mixed> $context
	 */
	private static function reason( string $method, string $operation_class, array $context ): string {
		return match ( $method ) {
			self::TYPED_API          => 'typed_stonewright_or_native_api_available_for_' . $operation_class,
			self::EDITOR_COMMAND_BUS => 'editor_only_or_runtime_state_requires_command_bus',
			self::ADMIN_FORM         => 'supported_admin_setting_without_rest_api',
			self::BROWSER_UI         => 'ui_only_workflow_or_visual_verification',
			default                  => 'unknown',
		};
	}
}
