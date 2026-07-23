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
	public const CUSTOM_CODE         = 'custom_code';

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
			'theme_code'             => [ self::TYPED_API, self::ADMIN_FORM ],
		];
	}

	/**
	 * Enforce the proof boundary before a custom-code proposal can be staged.
	 *
	 * @param mixed $native_gap
	 * @return array{reason:string,methods_tried:list<string>,evidence_ref:string}|\WP_Error
	 */
	public static function validate_native_gap( mixed $native_gap ) {
		if ( ! is_array( $native_gap ) ) {
			return self::native_gap_error();
		}
		$reason  = sanitize_textarea_field( (string) ( $native_gap['reason'] ?? '' ) );
		$methods = is_array( $native_gap['methods_tried'] ?? null )
			? array_values( array_filter( array_map( 'sanitize_key', $native_gap['methods_tried'] ) ) )
			: [];
		$allowed = [ self::TYPED_API, self::EDITOR_COMMAND_BUS, self::ADMIN_FORM, self::BROWSER_UI ];
		foreach ( $methods as $method ) {
			if ( ! in_array( $method, $allowed, true ) ) {
				return self::native_gap_error();
			}
		}
		if ( '' === trim( $reason ) || [] === $methods ) {
			return self::native_gap_error();
		}
		return [
			'reason'        => mb_substr( $reason, 0, 500 ),
			'methods_tried' => array_slice( array_values( array_unique( $methods ) ), 0, 4 ),
			'evidence_ref'  => mb_substr( sanitize_text_field( (string) ( $native_gap['evidence_ref'] ?? '' ) ), 0, 200 ),
		];
	}

	private static function native_gap_error(): \WP_Error {
		return new \WP_Error(
			'stonewright_native_gap_required',
			__( 'Custom code is blocked until native_gap includes a concrete reason and the native methods already tried.', 'stonewright' ),
			[
				'status'            => 400,
				'retryable'         => false,
				'required_methods'  => [ self::TYPED_API, self::EDITOR_COMMAND_BUS, self::ADMIN_FORM, self::BROWSER_UI ],
				'operation_class'   => self::CUSTOM_CODE,
				'execution_status'  => 'blocked',
			]
		);
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
