<?php
/**
 * Notices and callouts of the admin UI layer.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Admin\Ui;

/**
 * A notice reports something that just happened or is true now ("Settings saved", "The endpoint answered
 * 403"). It is announced: role="status" for ok and info, role="alert" for warn and danger. It never
 * disappears by itself, and the shell never moves it.
 *
 * A callout is guidance that belongs to its place in the content (a warning above a risky form). It is static
 * and has no live role.
 *
 * Neither takes colour alone: each carries an icon and a title or text.
 */
final class Notice {

	public const VARIANTS = [ 'ok', 'warn', 'danger', 'info' ];

	private const ICONS = [
		'ok'     => 'check',
		'warn'   => 'alert',
		'danger' => 'x',
		'info'   => 'info',
	];

	/**
	 * @phpstan-param array{
	 *     text_html?: string,
	 *     actions_html?: string,
	 *     icon?: string,
	 *     id?: string,
	 *     role?: string,
	 *     class?: string|list<string>,
	 *     attrs?: array<string, scalar|list<string>|null>
	 * } $args text_html and actions_html are markup the caller has already escaped or built with these helpers.
	 */
	public static function render( string $variant, string $title, string $text = '', array $args = [] ): string {
		return self::build( 'notice', $variant, $title, $text, $args );
	}

	/** @param array<string, mixed> $args The same options as render(). */
	public static function callout( string $variant, string $title, string $text = '', array $args = [] ): string {
		return self::build( 'callout', $variant, $title, $text, $args );
	}

	/** @param array<string, mixed> $args */
	private static function build( string $kind, string $variant, string $title, string $text, array $args ): string {
		$variant = in_array( $variant, self::VARIANTS, true ) ? $variant : 'info';
		$icon    = isset( $args['icon'] ) && Icon::exists( (string) $args['icon'] ) ? (string) $args['icon'] : self::ICONS[ $variant ];

		$attributes = [
			'class' => Html::classes( 'sw-ui-' . $kind, 'sw-ui-' . $kind . '--' . $variant, $args['class'] ?? '' ),
			'id'    => $args['id'] ?? null,
			'role'  => 'notice' === $kind ? self::role( $variant, (string) ( $args['role'] ?? '' ) ) : null,
		];

		$body = '';
		if ( '' !== $title ) {
			$body .= Html::element( 'div', [ 'class' => 'sw-ui-notice__title' ], Html::text( $title ) );
		}
		if ( '' !== (string) ( $args['text_html'] ?? '' ) ) {
			$body .= Html::element( 'div', [ 'class' => 'sw-ui-notice__text' ], (string) $args['text_html'] );
		} elseif ( '' !== $text ) {
			$body .= Html::element( 'div', [ 'class' => 'sw-ui-notice__text' ], Html::text( $text ) );
		}
		if ( '' !== (string) ( $args['actions_html'] ?? '' ) ) {
			$body .= Html::element( 'div', [ 'class' => 'sw-ui-notice__actions sw-ui-actions' ], (string) $args['actions_html'] );
		}

		/** @var array<string, scalar|list<string>|null> $extra */
		$extra = Html::without( is_array( $args['attrs'] ?? null ) ? $args['attrs'] : [], [ 'class', 'role', 'id' ] );

		return Html::element(
			'div',
			array_merge( $attributes, $extra ),
			Icon::render( $icon, [ 'size' => 'lg' ] ) . Html::element( 'div', [], $body )
		);
	}

	private static function role( string $variant, string $requested ): string {
		if ( in_array( $requested, [ 'status', 'alert' ], true ) ) {
			return $requested;
		}

		return in_array( $variant, [ 'warn', 'danger' ], true ) ? 'alert' : 'status';
	}
}
