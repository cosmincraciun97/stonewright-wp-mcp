<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Design\Semantics;

/** Enforces behavior for interactive design nodes before planning or render. */
final class ActionValidator {

	private const ACTION_ROLES = [ 'button', 'cta', 'link' ];

	/**
	 * @param list<array<string, mixed>> $nodes
	 * @return list<array<string, mixed>>
	 */
	public static function validate_evidence_nodes( array $nodes ): array {
		$diagnostics = [];
		foreach ( $nodes as $index => $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}
			$path = 'nodes[' . $index . ']';
			self::validate_evidence_node( $node, $path, $diagnostics );
		}
		return $diagnostics;
	}

	/**
	 * @param array<string, mixed> $spec
	 * @return list<array<string, mixed>>
	 */
	public static function validate_design_spec( array $spec ): array {
		$diagnostics = [];
		foreach ( (array) ( $spec['sections'] ?? [] ) as $section_index => $section ) {
			if ( ! is_array( $section ) ) {
				continue;
			}
			self::validate_spec_blocks(
				(array) ( $section['blocks'] ?? [] ),
				'sections[' . $section_index . '].blocks',
				$diagnostics
			);
		}
		return $diagnostics;
	}

	/**
	 * @param array<string, mixed>       $node
	 * @param list<array<string, mixed>> $diagnostics
	 */
	private static function validate_evidence_node( array $node, string $path, array &$diagnostics ): void {
		$role    = strtolower( (string) ( $node['role'] ?? '' ) );
		$content = isset( $node['content'] ) && is_array( $node['content'] ) ? $node['content'] : [];
		$action  = isset( $node['action'] ) && is_array( $node['action'] ) ? $node['action'] : [];

		if ( in_array( $role, self::ACTION_ROLES, true ) ) {
			$label = trim( (string) ( $content['label'] ?? $content['text'] ?? '' ) );
			if ( '' === $label ) {
				$diagnostics[] = self::diagnostic( $path . '.content.label', 'missing_action_label', 'Add the visible button/link label.' );
			}
			if ( ! self::resolved_action( $action ) ) {
				$diagnostics[] = self::diagnostic( $path . '.action', 'unresolved_action', 'Add a real URL, page_id, anchor, email, phone, or form action before write.' );
			}
		}

		if ( 'navigation' === $role ) {
			$links = isset( $node['links'] ) && is_array( $node['links'] ) ? $node['links'] : [];
			if ( empty( $node['menu_id'] ) && [] === $links ) {
				$diagnostics[] = self::diagnostic( $path, 'navigation_source_missing', 'Provide a WordPress menu_id or an explicit list of labeled destinations.' );
			}
			foreach ( $links as $link_index => $link ) {
				$link = is_array( $link ) ? $link : [];
				if ( '' === trim( (string) ( $link['label'] ?? '' ) ) || ! self::valid_destination( (string) ( $link['url'] ?? '' ) ) ) {
					$diagnostics[] = self::diagnostic( $path . '.links[' . $link_index . ']', 'invalid_navigation_link', 'Provide both label and a non-placeholder destination.' );
				}
			}
		}

		if ( 'form' === $role ) {
			$fields = isset( $node['fields'] ) && is_array( $node['fields'] ) ? $node['fields'] : [];
			if ( [] === $fields ) {
				$diagnostics[] = self::diagnostic( $path . '.fields', 'form_fields_missing', 'Describe the real form fields and validation requirements.' );
			}
			if ( ! self::resolved_action( $action ) ) {
				$diagnostics[] = self::diagnostic( $path . '.action', 'form_action_missing', 'Provide the submit action and success behavior.' );
			}
			if ( '' === trim( (string) ( $action['success'] ?? $action['success_behavior'] ?? '' ) ) ) {
				$diagnostics[] = self::diagnostic( $path . '.action.success', 'form_success_missing', 'Describe the confirmation, redirect, or other success behavior.' );
			}
		}

		if ( 'image' === $role ) {
			$asset = isset( $node['asset'] ) && is_array( $node['asset'] ) ? $node['asset'] : [];
			if ( empty( $asset['attachment_id'] ) && '' === trim( (string) ( $asset['source'] ?? $asset['url'] ?? '' ) ) ) {
				$diagnostics[] = self::diagnostic( $path . '.asset', 'image_source_missing', 'Provide a WordPress attachment_id or a source asset reference.' );
			}
			if ( ! array_key_exists( 'alt', $asset ) && ! array_key_exists( 'alt_policy', $asset ) ) {
				$diagnostics[] = self::diagnostic( $path . '.asset', 'image_alt_policy_missing', 'Provide alt text or an explicit decorative/derive policy.' );
			}
		}

		if ( 'repeated-cards' === $role ) {
			$model = isset( $node['content_model'] ) && is_array( $node['content_model'] ) ? $node['content_model'] : [];
			$mode  = (string) ( $model['mode'] ?? '' );
			if ( ! in_array( $mode, [ 'static', 'dynamic' ], true ) ) {
				$diagnostics[] = self::diagnostic( $path . '.content_model.mode', 'content_model_decision_missing', 'Choose static or dynamic explicitly based on editability and volume.' );
			} elseif ( 'dynamic' === $mode && '' === trim( (string) ( $model['post_type'] ?? $model['source'] ?? '' ) ) ) {
				$diagnostics[] = self::diagnostic( $path . '.content_model', 'dynamic_source_missing', 'Provide the post type or verified dynamic source for the repeated content.' );
			}
		}

		if ( in_array( $role, [ 'header', 'footer' ], true ) && [] === (array) ( $node['conditions'] ?? [] ) ) {
			$diagnostics[] = self::diagnostic( $path . '.conditions', 'theme_builder_conditions_missing', 'Provide explicit Theme Builder display conditions.' );
		}

		foreach ( (array) ( $node['children'] ?? [] ) as $child_index => $child ) {
			if ( is_array( $child ) ) {
				self::validate_evidence_node( $child, $path . '.children[' . $child_index . ']', $diagnostics );
			}
		}
	}

	/**
	 * @param list<mixed>                $blocks
	 * @param list<array<string, mixed>> $diagnostics
	 */
	private static function validate_spec_blocks( array $blocks, string $path, array &$diagnostics ): void {
		foreach ( $blocks as $index => $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}
			$type = strtolower( (string) ( $block['type'] ?? '' ) );
			if ( 'button' === $type ) {
				$url = (string) ( $block['url'] ?? ( is_array( $block['link'] ?? null ) ? ( $block['link']['url'] ?? '' ) : '' ) );
				if ( ! self::valid_destination( $url ) ) {
					$diagnostics[] = self::diagnostic( $path . '[' . $index . '].url', 'unresolved_action', 'Set a real destination; # and empty placeholders are forbidden.' );
				}
			}
			self::validate_spec_blocks( (array) ( $block['blocks'] ?? $block['children'] ?? [] ), $path . '[' . $index . '].children', $diagnostics );
		}
	}

	/** @param array<string, mixed> $action */
	private static function resolved_action( array $action ): bool {
		if ( isset( $action['url'] ) && self::valid_destination( (string) $action['url'] ) ) {
			return true;
		}
		if ( isset( $action['anchor'] ) && preg_match( '/^#?[A-Za-z][A-Za-z0-9_-]+$/', trim( (string) $action['anchor'] ) ) ) {
			return true;
		}
		if ( isset( $action['email'] ) && false !== filter_var( trim( (string) $action['email'] ), FILTER_VALIDATE_EMAIL ) ) {
			return true;
		}
		if ( isset( $action['phone'] ) && preg_match( '/^\+?[0-9][0-9 ()-]{5,}$/', trim( (string) $action['phone'] ) ) ) {
			return true;
		}
		if ( isset( $action['form_action'] ) && self::valid_identifier( (string) $action['form_action'] ) ) {
			return true;
		}
		return isset( $action['page_id'] ) && (int) $action['page_id'] > 0;
	}

	private static function valid_destination( string $value ): bool {
		$value = trim( $value );
		if ( '' === $value || '#' === $value || str_starts_with( strtolower( $value ), 'javascript:' ) ) {
			return false;
		}
		if ( str_starts_with( $value, '/' ) || str_starts_with( $value, '#/' ) ) {
			return true;
		}
		return false !== filter_var( $value, FILTER_VALIDATE_URL )
			&& in_array( strtolower( (string) parse_url( $value, PHP_URL_SCHEME ) ), [ 'http', 'https', 'mailto', 'tel' ], true );
	}

	private static function valid_identifier( string $value ): bool {
		$value = trim( $value );
		return '' !== $value && '#' !== $value && ! str_starts_with( strtolower( $value ), 'javascript:' );
	}

	/** @return array<string, mixed> */
	private static function diagnostic( string $path, string $code, string $repair ): array {
		return [ 'path' => $path, 'code' => $code, 'blocking' => true, 'repair' => $repair ];
	}
}
