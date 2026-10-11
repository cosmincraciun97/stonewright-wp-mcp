<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Elementor;

/**
 * The code gates of a whole element tree: the HTML widget policy and the custom code gate, applied to
 * every element the way add-widget and batch-mutate apply them to each operation.
 *
 * The writers that replace a whole document (elementor-build-tree and elementor-v3-transaction-run
 * with replace_tree) run it on the incoming tree before anything is snapshotted or written. A tree
 * that holds an HTML widget needs the site option and an explicit allow_html_widget; custom CSS, a
 * style tag in an HTML widget and unapproved CSS classes need what ElementorCustomCssGate asks for.
 */
final class ElementorTreeCodeGate {

	/**
	 * @param array<int|string, mixed> $tree    Incoming elements.
	 * @param array<string, mixed>     $args    Ability arguments (allow_html_widget, custom_code_grant).
	 * @param bool                     $dry_run A dry run reports what the apply would be refused for, but leaves a grant unused.
	 * @return true|\WP_Error
	 */
	public static function assert_tree( array $tree, array $args = [], bool $dry_run = false ): bool|\WP_Error {
		if ( self::contains_html_widget( $tree ) ) {
			$policy = HtmlWidgetPolicy::allowed( $args );
			if ( $policy instanceof \WP_Error ) {
				return $policy;
			}
		}

		$grant = trim( (string) ( $args['custom_code_grant'] ?? '' ) );
		if ( $dry_run && '' !== $grant ) {
			// A grant is single-use and checked when the tree is written; a dry run must not spend it.
			unset( $args['custom_code_grant'] );
			$gate = ElementorCustomCssGate::assert_incoming( [ 'tree' => $tree ], $args );
			if ( $gate instanceof \WP_Error && ElementorCustomCssGate::ERROR_CODE === $gate->get_error_code() ) {
				return true;
			}

			return $gate;
		}

		return ElementorCustomCssGate::assert_incoming( [ 'tree' => $tree ], $args );
	}

	/**
	 * The tree of a replace_tree envelope ({ operations: [ { action: replace_tree, tree } ] }), or null for
	 * any other envelope.
	 *
	 * @param array<string, mixed> $envelope
	 * @return array<int|string, mixed>|null
	 */
	public static function replace_tree_of( array $envelope ): ?array {
		$ops = $envelope['operations'] ?? null;
		if ( ! is_array( $ops ) || 1 !== count( $ops ) || ! is_array( $ops[0] ?? null ) || 'replace_tree' !== ( $ops[0]['action'] ?? '' ) ) {
			return null;
		}

		return isset( $ops[0]['tree'] ) && is_array( $ops[0]['tree'] ) ? $ops[0]['tree'] : [];
	}

	/**
	 * @param array<int|string, mixed> $elements
	 */
	private static function contains_html_widget( array $elements ): bool {
		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}
			if ( HtmlWidgetPolicy::is_html_type( (string) ( $element['widgetType'] ?? '' ) ) ) {
				return true;
			}
			if ( is_array( $element['elements'] ?? null ) && self::contains_html_widget( $element['elements'] ) ) {
				return true;
			}
		}

		return false;
	}
}
