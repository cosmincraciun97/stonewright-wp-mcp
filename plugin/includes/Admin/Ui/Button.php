<?php
/**
 * Buttons and button-looking links of the admin UI layer.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Admin\Ui;

/**
 * One call renders a native <button> (or an <a> when there is a destination) with the classes of the layer.
 *
 * The label is plain text and is escaped here. A button with no visible text (icon_only) is named by its
 * label through aria-label, so there is no icon-only control without a name. Name a button with the verb and
 * the object it acts on: "Disconnect Example client", not "Disconnect". "context" supplies the object for
 * assistive technology only, so a table of repeated actions keeps a short visible label ("Disconnect") and
 * every action still has its own name.
 */
final class Button {

	public const VARIANTS = [ 'secondary', 'primary', 'tertiary', 'danger', 'danger-solid' ];
	public const SIZES    = [ 'md', 'sm', 'xs' ];
	public const TYPES    = [ 'button', 'submit', 'reset' ];

	/** Attributes the helper sets itself; a caller cannot override them through "attrs". */
	private const OWNED = [ 'class', 'type', 'href', 'disabled', 'aria-busy', 'aria-disabled', 'aria-label', 'tabindex' ];

	/**
	 * @phpstan-param array{
	 *     variant?: string,
	 *     size?: string,
	 *     href?: string,
	 *     type?: string,
	 *     icon?: string,
	 *     icon_only?: bool,
	 *     context?: string,
	 *     disabled?: bool,
	 *     busy?: bool,
	 *     new_tab?: bool,
	 *     id?: string,
	 *     name?: string,
	 *     value?: string,
	 *     form?: string,
	 *     class?: string|list<string>,
	 *     attrs?: array<string, scalar|list<string>|null>
	 * } $args
	 */
	public static function render( string $label, array $args = [] ): string {
		$variant   = in_array( $args['variant'] ?? 'secondary', self::VARIANTS, true ) ? (string) ( $args['variant'] ?? 'secondary' ) : 'secondary';
		$size      = in_array( $args['size'] ?? 'md', self::SIZES, true ) ? (string) ( $args['size'] ?? 'md' ) : 'md';
		$icon_only = ! empty( $args['icon_only'] ) && '' !== (string) ( $args['icon'] ?? '' );
		$href      = (string) ( $args['href'] ?? '' );
		$disabled  = ! empty( $args['disabled'] );

		$classes = Html::classes(
			'sw-ui-btn',
			'secondary' === $variant ? '' : 'sw-ui-btn--' . $variant,
			'md' === $size ? '' : 'sw-ui-btn--' . $size,
			$icon_only ? 'sw-ui-btn--icon' : '',
			$args['class'] ?? ''
		);

		$attributes = [];
		if ( '' !== $href ) {
			$tag                     = 'a';
			$attributes['class']      = $classes;
			$attributes['href']       = $disabled ? null : $href;
			// A link cannot be disabled: it is marked, taken out of the tab order and its destination dropped.
			$attributes['aria-disabled'] = $disabled ? 'true' : null;
			$attributes['tabindex']   = $disabled ? '-1' : null;
			if ( ! empty( $args['new_tab'] ) ) {
				$attributes['target'] = '_blank';
				$attributes['rel']    = 'noopener noreferrer';
			}
		} else {
			$tag                     = 'button';
			$requested               = (string) ( $args['type'] ?? 'button' );
			$attributes['type']      = in_array( $requested, self::TYPES, true ) ? $requested : 'button';
			$attributes['class']     = $classes;
			$attributes['name']      = $args['name'] ?? null;
			$attributes['value']     = $args['value'] ?? null;
			$attributes['form']      = $args['form'] ?? null;
			$attributes['disabled']  = $disabled ? true : null;
		}
		$attributes['id']         = $args['id'] ?? null;
		$attributes['aria-busy']  = ! empty( $args['busy'] ) ? 'true' : null;
		$context                  = trim( (string) ( $args['context'] ?? '' ) );
		$attributes['aria-label'] = $icon_only ? trim( $label . ' ' . $context ) : null;

		$extra = Html::without( $args['attrs'] ?? [], self::OWNED );
		$text  = Html::text( $label ) . ( '' !== $context ? Html::element( 'span', [ 'class' => 'sw-ui-visually-hidden' ], ' ' . Html::text( $context ) ) : '' );
		$inner = ( '' !== (string) ( $args['icon'] ?? '' ) ? Icon::render( (string) $args['icon'] ) : '' ) . ( $icon_only ? '' : $text );

		return Html::element( $tag, array_merge( $attributes, $extra ), $inner );
	}

	/** The row that lays buttons out with the layer's gap. @param list<string> $buttons Rendered buttons. */
	public static function group( array $buttons, bool $end = false ): string {
		return Html::element( 'div', [ 'class' => Html::classes( 'sw-ui-actions', $end ? 'sw-ui-actions--end' : '' ) ], implode( '', $buttons ) );
	}
}
