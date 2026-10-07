<?php
/**
 * Labelled form controls of the admin UI layer.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Admin\Ui;

/**
 * A field is a label, a native control, help text and error text, in that order. Every control is named by a
 * label that points at it, help and error text are linked with aria-describedby, and an invalid control says the
 * cause with an icon and words, never with colour alone.
 *
 * A switch is a native checkbox with role="switch" over a drawn track, so it posts like any checkbox and works
 * without script. `hidden_zero` prints a hidden 0 before it, so a form that saves an unchecked box still posts a
 * value.
 */
final class FormField {

	/** Attributes a control accepts from the caller, in the order they are printed. */
	private const CONTROL_ATTRIBUTES = [ 'maxlength', 'placeholder', 'autocomplete', 'pattern', 'min', 'readonly', 'spellcheck' ];

	/**
	 * A one-line text, search, number or password input.
	 *
	 * @phpstan-param array{
	 *     id?: string,
	 *     type?: string,
	 *     value?: string,
	 *     help?: string,
	 *     error?: string,
	 *     required?: bool,
	 *     size?: string,
	 *     maxlength?: int,
	 *     placeholder?: string,
	 *     autocomplete?: string,
	 *     pattern?: string,
	 *     min?: int,
	 *     readonly?: bool,
	 *     spellcheck?: string,
	 *     class?: string|list<string>,
	 *     attrs?: array<string, scalar|list<string>|null>
	 * } $args "size" is sm or md and caps the width.
	 */
	public static function input( string $label, string $name, array $args = [] ): string {
		$id   = self::id( $name, $args );
		$type = in_array( $args['type'] ?? 'text', [ 'text', 'search', 'number', 'password', 'email', 'url' ], true ) ? (string) ( $args['type'] ?? 'text' ) : 'text';
		$attr = [
			'class' => Html::classes( 'sw-ui-input', $args['class'] ?? '' ),
			'type'  => $type,
			'id'    => $id,
			'name'  => $name,
			'value' => array_key_exists( 'value', $args ) ? (string) $args['value'] : null,
		];

		return self::wrap( $label, $id, Html::void( 'input', self::finish( $attr, $args, $id ) ), $args );
	}

	/**
	 * A multi-line input. "code" sets the text in the monospace face.
	 *
	 * @phpstan-param array{
	 *     id?: string,
	 *     value?: string,
	 *     help?: string,
	 *     error?: string,
	 *     required?: bool,
	 *     rows?: int,
	 *     code?: bool,
	 *     maxlength?: int,
	 *     placeholder?: string,
	 *     spellcheck?: string,
	 *     class?: string|list<string>,
	 *     attrs?: array<string, scalar|list<string>|null>
	 * } $args
	 */
	public static function textarea( string $label, string $name, array $args = [] ): string {
		$id   = self::id( $name, $args );
		$attr = [
			'class' => Html::classes( 'sw-ui-textarea', ! empty( $args['code'] ) ? 'sw-ui-textarea--code' : '', $args['class'] ?? '' ),
			'id'    => $id,
			'name'  => $name,
			'rows'  => isset( $args['rows'] ) ? (string) (int) $args['rows'] : null,
		];

		return self::wrap( $label, $id, Html::element( 'textarea', self::finish( $attr, $args, $id ), Html::text( (string) ( $args['value'] ?? '' ) ) ), $args );
	}

	/**
	 * A native select.
	 *
	 * @param array<string, string> $options Value to label.
	 * @phpstan-param array{
	 *     id?: string,
	 *     value?: string,
	 *     help?: string,
	 *     error?: string,
	 *     required?: bool,
	 *     size?: string,
	 *     class?: string|list<string>,
	 *     attrs?: array<string, scalar|list<string>|null>
	 * } $args "value" is the chosen option.
	 */
	public static function select( string $label, string $name, array $options, array $args = [] ): string {
		$id      = self::id( $name, $args );
		$chosen  = (string) ( $args['value'] ?? '' );
		$choices = '';
		foreach ( $options as $value => $text ) {
			$choices .= Html::element( 'option', [ 'value' => (string) $value, 'selected' => (string) $value === $chosen ], Html::text( $text ) );
		}
		$attr = [
			'class' => Html::classes( 'sw-ui-select', $args['class'] ?? '' ),
			'id'    => $id,
			'name'  => $name,
		];

		return self::wrap( $label, $id, Html::element( 'select', self::finish( $attr, $args, $id ), $choices ), $args );
	}

	/**
	 * A switch: a setting that is on or off.
	 *
	 * @phpstan-param array{
	 *     id?: string,
	 *     checked?: bool,
	 *     help?: string,
	 *     hidden_zero?: bool,
	 *     disabled?: bool,
	 *     value?: string,
	 *     class?: string|list<string>,
	 *     attrs?: array<string, scalar|list<string>|null>
	 * } $args
	 */
	public static function switch( string $label, string $name, array $args = [] ): string {
		$id   = self::id( $name, $args );
		$help = (string) ( $args['help'] ?? '' );
		$hint = '' !== $help ? $id . '-help' : null;
		$zero = ! empty( $args['hidden_zero'] ) ? Html::void( 'input', [ 'type' => 'hidden', 'name' => $name, 'value' => '0' ] ) : '';

		$control = Html::void(
			'input',
			array_merge(
				[
					'type'             => 'checkbox',
					'role'             => 'switch',
					'id'               => $id,
					'name'             => $name,
					'value'            => (string) ( $args['value'] ?? '1' ),
					'checked'          => ! empty( $args['checked'] ),
					'disabled'         => ! empty( $args['disabled'] ),
					'aria-describedby' => $hint,
				],
				Html::without( $args['attrs'] ?? [], [ 'type', 'role', 'id', 'name', 'value', 'checked', 'disabled', 'aria-describedby' ] )
			)
		);
		$switch  = Html::element(
			'label',
			[ 'class' => Html::classes( 'sw-ui-switch', $args['class'] ?? '' ), 'for' => $id ],
			$control . Html::element( 'span', [ 'class' => 'sw-ui-switch__track', 'aria-hidden' => 'true' ], '' ) . Html::element( 'span', [], Html::text( $label ) )
		);
		$text    = '' !== $help ? Html::element( 'span', [ 'class' => 'sw-ui-field__help', 'id' => $hint ], Html::text( $help ) ) : '';

		return Html::element( 'div', [ 'class' => 'sw-ui-field' ], $zero . $switch . $text );
	}

	/**
	 * A checkbox inside its label, which gives it a 24px target.
	 *
	 * @phpstan-param array{
	 *     id?: string,
	 *     checked?: bool,
	 *     required?: bool,
	 *     disabled?: bool,
	 *     value?: string,
	 *     class?: string|list<string>,
	 *     attrs?: array<string, scalar|list<string>|null>
	 * } $args
	 */
	public static function checkbox( string $label, string $name, array $args = [] ): string {
		$id      = self::id( $name, $args );
		$control = Html::void(
			'input',
			array_merge(
				[
					'type'     => 'checkbox',
					'id'       => $id,
					'name'     => $name,
					'value'    => (string) ( $args['value'] ?? '1' ),
					'checked'  => ! empty( $args['checked'] ),
					'required' => ! empty( $args['required'] ),
					'disabled' => ! empty( $args['disabled'] ),
				],
				Html::without( $args['attrs'] ?? [], [ 'type', 'id', 'name', 'value', 'checked', 'required', 'disabled' ] )
			)
		);

		return Html::element( 'label', [ 'class' => Html::classes( 'sw-ui-checkbox', $args['class'] ?? '' ), 'for' => $id ], $control . Html::element( 'span', [], Html::text( $label ) ) );
	}

	/**
	 * A hidden input.
	 */
	public static function hidden( string $name, string $value ): string {
		return Html::void( 'input', [ 'type' => 'hidden', 'name' => $name, 'value' => $value ] );
	}

	/**
	 * A WordPress nonce as a hidden input with no id. wp_nonce_field() gives every nonce the id of its name, so
	 * a page with several forms repeats it; the id is never used.
	 */
	public static function nonce( string $action, string $name = '_wpnonce' ): string {
		return self::hidden( $name, wp_create_nonce( $action ) );
	}

	/**
	 * A form that posts to admin-post.php with its action, its nonce and any hidden values.
	 *
	 * @param string                                  $inner_html Markup built with these helpers.
	 * @param array{hidden?: array<string, string>, class?: string|list<string>, id?: string, attrs?: array<string, scalar|list<string>|null>} $args
	 */
	public static function post_form( string $action, string $nonce_action, string $nonce_name, string $inner_html, array $args = [] ): string {
		$fields = self::hidden( 'action', $action );
		foreach ( $args['hidden'] ?? [] as $name => $value ) {
			$fields .= self::hidden( (string) $name, (string) $value );
		}

		return Html::element(
			'form',
			array_merge(
				[
					'class'  => Html::classes( $args['class'] ?? '' ),
					'method' => 'post',
					'action' => admin_url( 'admin-post.php' ),
					'id'     => $args['id'] ?? null,
				],
				Html::without( $args['attrs'] ?? [], [ 'class', 'method', 'action', 'id' ] )
			),
			$fields . self::nonce( $nonce_action, $nonce_name ) . $inner_html
		);
	}

	/**
	 * @param array<string, mixed> $args
	 */
	private static function id( string $name, array $args ): string {
		return '' !== (string) ( $args['id'] ?? '' ) ? (string) $args['id'] : Html::unique_id( $name );
	}

	/**
	 * Add the constraints, the invalid state and the description link to a control's attributes.
	 *
	 * @param array<string, scalar|list<string>|null> $attr
	 * @param array<string, mixed>                    $args
	 * @return array<string, scalar|list<string>|null>
	 */
	private static function finish( array $attr, array $args, string $id ): array {
		foreach ( self::CONTROL_ATTRIBUTES as $name ) {
			if ( isset( $args[ $name ] ) && ! ( is_bool( $args[ $name ] ) && ! $args[ $name ] ) ) {
				$attr[ $name ] = is_int( $args[ $name ] ) ? (string) $args[ $name ] : $args[ $name ];
			}
		}
		$attr['required'] = ! empty( $args['required'] );

		$described = [];
		if ( '' !== (string) ( $args['error'] ?? '' ) ) {
			$attr['aria-invalid'] = 'true';
		}
		if ( '' !== (string) ( $args['help'] ?? '' ) ) {
			$described[] = $id . '-help';
		}
		if ( '' !== (string) ( $args['error'] ?? '' ) ) {
			$described[] = $id . '-error';
		}
		$attr['aria-describedby'] = [] === $described ? null : implode( ' ', $described );

		$extra = Html::without( is_array( $args['attrs'] ?? null ) ? $args['attrs'] : [], [ 'class', 'id', 'name', 'aria-describedby', 'aria-invalid' ] );

		return array_merge( $attr, $extra );
	}

	/**
	 * @param array<string, mixed> $args
	 */
	private static function wrap( string $label, string $id, string $control, array $args ): string {
		$size = in_array( $args['size'] ?? '', [ 'sm', 'md' ], true ) ? 'sw-ui-field--' . $args['size'] : '';
		$help = '' !== (string) ( $args['help'] ?? '' )
			? Html::element( 'span', [ 'class' => 'sw-ui-field__help', 'id' => $id . '-help' ], Html::text( (string) $args['help'] ) )
			: '';
		$err  = '' !== (string) ( $args['error'] ?? '' )
			? Html::element( 'span', [ 'class' => 'sw-ui-field__error', 'id' => $id . '-error' ], Icon::render( 'alert' ) . Html::text( (string) $args['error'] ) )
			: '';

		return Html::element(
			'div',
			[ 'class' => Html::classes( 'sw-ui-field', $size ) ],
			Html::element( 'label', [ 'class' => 'sw-ui-field__label', 'for' => $id ], Html::text( $label ) ) . $control . $help . $err
		);
	}
}
