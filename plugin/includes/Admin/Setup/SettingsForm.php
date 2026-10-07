<?php
/**
 * The settings form: the one form that posts to options.php.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Admin\Setup;

use Stonewright\WpMcp\Admin\ConfigurationPage;
use Stonewright\WpMcp\Admin\Ui\Button;
use Stonewright\WpMcp\Admin\Ui\Card;
use Stonewright\WpMcp\Admin\Ui\CodeBlock;
use Stonewright\WpMcp\Admin\Ui\Html;
use Stonewright\WpMcp\Admin\Ui\Icon;
use Stonewright\WpMcp\Admin\Ui\Notice;
use Stonewright\WpMcp\Security\PluginEffectiveState;

/**
 * Enabling, the mode, the MCP surface, the Elementor V4 switch, the section reuse row, optional API keys and the
 * local bridge, in the one form the Settings API saves. Every control keeps its name and id, so a save posts the
 * same fields as before, and a stored secret is never printed: its field is empty with a placeholder and a
 * checkbox that removes it.
 */
final class SettingsForm {

	public static function html( SetupContext $context ): string {
		$general = '<table class="sw-ui-form-table" role="presentation"><tbody>'
			. self::enable_row( $context )
			. self::mode_row( $context )
			. self::surface_row( $context )
			. self::switch_row(
				'stonewright_elementor_v4_atomic',
				__( 'Elementor V4 atomic abilities', 'stonewright' ),
				$context->atomic_enabled,
				__( 'Exposes the experimental Elementor V4 atomic abilities. Requires an Elementor version with the Atomic Widgets module. Writes are always blocked in production-safe.', 'stonewright' )
			)
			. SectionReuseRow::html()
			. '</tbody></table>';

		$keys = '<table class="sw-ui-form-table" role="presentation"><tbody>'
			. self::secret_row( 'stonewright_unsplash_access_key', __( 'Unsplash access key', 'stonewright' ), $context->has_unsplash_key, __( 'Leave empty to keep Unsplash off. Openverse stock search works without any key.', 'stonewright' ) )
			. self::secret_row( 'stonewright_pexels_api_key', __( 'Pexels API key', 'stonewright' ), $context->has_pexels_key, __( 'Leave empty to keep Pexels off. Only used by stock-image abilities when set.', 'stonewright' ) )
			. '</tbody></table>';

		$actions = Button::group( [ Button::render( __( 'Save settings', 'stonewright' ), [ 'type' => 'submit', 'variant' => 'primary' ] ) ] );

		return Html::element(
			'form',
			[ 'method' => 'post', 'action' => 'options.php', 'class' => 'stonewright-settings-form sw-ui-stack' ],
			self::settings_fields()
			. self::saved_notice()
			. Card::render( __( 'General', 'stonewright' ), $general, [ 'id' => 'stonewright-abilities-settings' ] )
			. Card::render(
				__( 'Stock images', 'stonewright' ),
				$keys,
				[ 'desc' => __( 'Optional keys for the stock-image abilities. A stored key is never shown again.', 'stonewright' ) ]
			)
			. self::bridge( $context )
			. $actions
		);
	}

	/** The hidden fields the Settings API needs: option group, action, nonce and referer. */
	private static function settings_fields(): string {
		ob_start();
		settings_fields( ConfigurationPage::OPTION_GROUP );

		return (string) ob_get_clean();
	}

	private static function saved_notice(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only notice selector after options.php.
		$updated = isset( $_GET['settings-updated'] ) ? sanitize_key( (string) wp_unslash( $_GET['settings-updated'] ) ) : '';
		if ( ! in_array( $updated, [ 'true', '1' ], true ) ) {
			return '';
		}

		return Notice::render( 'ok', __( 'Settings saved', 'stonewright' ), ConfigurationPage::surface_saved_notice() );
	}

	private static function row( string $label_html, string $cell_html ): string {
		return '<tr>' . Html::element( 'th', [ 'scope' => 'row' ], $label_html ) . Html::element( 'td', [], $cell_html ) . '</tr>';
	}

	private static function label( string $for, string $text ): string {
		return Html::element( 'label', [ 'for' => $for ], Html::text( $text ) );
	}

	private static function option( string $value, string $text, bool $selected ): string {
		return '<option value="' . esc_attr( $value ) . '"' . ( $selected ? ' selected="selected"' : '' ) . '>' . esc_html( $text ) . '</option>';
	}

	private static function help( string $id, string $text ): string {
		return Html::element( 'p', [ 'class' => 'sw-ui-field__help', 'id' => $id ], Html::text( $text ) );
	}

	/** A switch: the unchecked value is posted by the hidden field, the checked one by the checkbox. */
	private static function switch_row( string $option, string $label, bool $checked, string $help, string $extra_html = '' ): string {
		$help_id = $option . '_help';

		return self::row(
			self::label( $option, $label ),
			Html::void( 'input', [ 'type' => 'hidden', 'name' => $option, 'value' => '0' ] )
			. Html::element(
				'span',
				[ 'class' => 'sw-ui-switch' ],
				Html::void(
					'input',
					[
						'type'             => 'checkbox',
						'role'             => 'switch',
						'name'             => $option,
						'id'               => $option,
						'value'            => '1',
						'checked'          => $checked ? true : null,
						'aria-describedby' => $help_id,
					]
				) . Html::element( 'span', [ 'class' => 'sw-ui-switch__track', 'aria-hidden' => 'true' ], '' )
			)
			. self::help( $help_id, $help )
			. $extra_html
		);
	}

	private static function enable_row( SetupContext $context ): string {
		$extra = '';
		if ( $context->enabled && PluginEffectiveState::STATE_ENABLED !== $context->effective_state ) {
			$extra = Notice::callout(
				'warn',
				'',
				sprintf(
					/* translators: %s: effective runtime state key */
					__( 'Requested: on. Effective state: %s (abilities stay blocked until resolved).', 'stonewright' ),
					$context->effective_state
				)
			);
		}

		return self::switch_row(
			'stonewright_enabled',
			__( 'AI abilities', 'stonewright' ),
			$context->enabled,
			__( 'Turn on Stonewright abilities for this site.', 'stonewright' ),
			$extra
		);
	}

	private static function mode_row( SetupContext $context ): string {
		$options = '';
		foreach ( [ 'development' => __( 'Development', 'stonewright' ), 'staging' => __( 'Staging', 'stonewright' ), 'production-safe' => __( 'Production-safe', 'stonewright' ) ] as $value => $text ) {
			$options .= self::option( $value, $text, $context->mode === $value );
		}
		$select = Html::element(
			'div',
			[ 'class' => 'sw-ui-field sw-ui-field--md' ],
			Html::element( 'select', [ 'class' => 'sw-ui-select', 'name' => 'stonewright_mode', 'id' => 'stonewright_mode', 'aria-describedby' => 'stonewright_mode_help' ], $options )
		);
		$help = Html::element(
			'div',
			[ 'class' => 'sw-ui-field__help', 'id' => 'stonewright_mode_help' ],
			Html::element( 'p', [], Html::text( __( 'Development — no confirmation tokens required; use only on disposable sites.', 'stonewright' ) ) )
			. Html::element( 'p', [], Html::text( __( 'Staging — same gates as development; identifies the site as staging.', 'stonewright' ) ) )
			. Html::element( 'p', [], Html::text( __( 'Production-safe — destructive and bulk writes require a fresh confirmation token per operation; Elementor V4 writes are blocked.', 'stonewright' ) ) )
		);
		$risk = 'production-safe' === $context->mode
			? Notice::callout( 'ok', '', __( 'Production-safe is on: destructive operations require confirmation tokens.', 'stonewright' ) )
			: Notice::callout( 'warn', '', __( 'This mode does not ask for confirmation tokens. Use production-safe on a live site.', 'stonewright' ) );

		return self::row( self::label( 'stonewright_mode', __( 'Mode', 'stonewright' ) ), $select . $help . $risk );
	}

	private static function surface_row( SetupContext $context ): string {
		$options = '';
		foreach (
			[
				'bootstrap' => __( 'Bootstrap (≤8 tools — progressive discovery)', 'stonewright' ),
				'essential' => __( 'Essential (compact fast path)', 'stonewright' ),
				'full'      => __( 'Full (slow / high-context — all registered tools)', 'stonewright' ),
			] as $value => $text
		) {
			$options .= self::option( $value, $text, $context->mcp_surface === $value );
		}

		// The page script writes the outcome of an apply into this line; after a save it says the surface was saved.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only notice selector after options.php.
		$updated = isset( $_GET['settings-updated'] ) ? sanitize_key( (string) wp_unslash( $_GET['settings-updated'] ) ) : '';
		$status  = in_array( $updated, [ 'true', '1' ], true )
			? ConfigurationPage::surface_saved_notice()
			: __( 'Essential is the recommended default for real work. Bootstrap is only a startup diagnostic; Full loads the entire ability surface and is slow and high-context. Changes here apply immediately; clients that permanently cache tools still need one restart.', 'stonewright' );

		$controls = Html::element(
			'div',
			[ 'class' => 'sw-ui-actions' ],
			Html::element( 'select', [ 'class' => 'sw-ui-select sw-setup__select', 'name' => 'stonewright_mcp_surface', 'id' => 'stonewright_mcp_surface', 'aria-describedby' => 'stonewright-mcp-surface-status' ], $options )
			. Button::render(
				__( 'Apply now', 'stonewright' ),
				[ 'id' => 'stonewright-apply-mcp-surface', 'attrs' => [ 'data-sw-apply-mcp-surface' => true ] ]
			)
		);
		$line = Html::element(
			'p',
			[ 'class' => 'sw-ui-field__help', 'id' => 'stonewright-mcp-surface-status', 'data-sw-mcp-surface-status' => true, 'role' => 'status', 'aria-live' => 'polite' ],
			Html::text( $status )
		);

		return self::row( self::label( 'stonewright_mcp_surface', __( 'MCP tool surface', 'stonewright' ) ), '<div data-sw-mcp-surface-field>' . $controls . $line . '</div>' );
	}

	private static function secret_row( string $option, string $label, bool $stored, string $help ): string {
		$help_id = $option . '_help';

		return self::row(
			self::label( $option, $label ),
			Html::element( 'div', [ 'class' => 'sw-ui-field sw-ui-field--md' ], SecretField::input( $option, $stored, $help_id ) )
			. SecretField::clear( $option, $stored )
			. self::help( $help_id, $help )
		);
	}

	/** The local WP-CLI bridge: a closed disclosure with its address, token and the launch values. */
	private static function bridge( SetupContext $context ): string {
		$steps = '';
		foreach ( [ __( 'Click Generate token.', 'stonewright' ), __( 'Save settings.', 'stonewright' ), __( 'Copy the developer launch values into the local bridge process.', 'stonewright' ) ] as $step ) {
			$steps .= Html::element( 'li', [], Html::text( $step ) );
		}

		$url = Html::element(
			'div',
			[ 'class' => 'sw-ui-field sw-ui-field--md' ],
			Html::void(
				'input',
				[
					'type'         => 'url',
					'class'        => 'sw-ui-input',
					'name'         => 'stonewright_companion_url',
					'id'           => 'stonewright_companion_url',
					'value'        => $context->companion_url,
					'placeholder'  => 'http://127.0.0.1:8765',
					'autocomplete' => 'off',
				]
			)
		);

		$token_help = $context->has_companion_token
			? self::help( 'stonewright_companion_token_help', __( 'A bridge token is stored and is not shown again. Generate a new token to replace it; the launch values below keep a placeholder until you do.', 'stonewright' ) )
			: '';
		$token_id   = 'stonewright_companion_token';
		$token      = Html::element(
			'div',
			[ 'class' => 'sw-ui-actions' ],
			Html::element( 'div', [ 'class' => 'sw-ui-field sw-ui-field--md' ], SecretField::input( $token_id, $context->has_companion_token, $context->has_companion_token ? $token_id . '_help' : '' ) )
			. Html::element(
				'button',
				[
					'type'                  => 'button',
					'class'                 => 'sw-ui-btn',
					'aria-pressed'          => 'false',
					'data-sw-ui-reveal'     => '#' . $token_id,
					'data-sw-ui-show-label' => __( 'Show bridge token', 'stonewright' ),
					'data-sw-ui-hide-label' => __( 'Hide bridge token', 'stonewright' ),
				],
				Html::element( 'span', [ 'data-sw-ui-reveal-label' => true ], Html::text( __( 'Show bridge token', 'stonewright' ) ) )
			)
			. Button::render( __( 'Copy bridge token', 'stonewright' ), [ 'attrs' => [ 'data-sw-ui-copy' => '#' . $token_id, 'data-sw-ui-copy-status' => '#stonewright-bridge-token-status', 'data-sw-ui-copied-label' => __( 'Copied', 'stonewright' ) ] ] )
			. Button::render( __( 'Generate token', 'stonewright' ), [ 'attrs' => [ 'data-stonewright-generate-token' => $token_id ] ] )
			. Html::element( 'span', [ 'class' => 'sw-ui-copy__status', 'role' => 'status', 'id' => 'stonewright-bridge-token-status' ], '' )
		);

		$table = '<table class="sw-ui-form-table" role="presentation"><tbody>'
			. self::row( self::label( 'stonewright_companion_url', __( 'Bridge URL (optional)', 'stonewright' ) ), $url )
			. self::row( self::label( $token_id, __( 'Bridge token', 'stonewright' ) ), $token . $token_help . SecretField::clear( $token_id, $context->has_companion_token ) )
			. '</tbody></table>';

		$env = CodeBlock::render(
			$context->bridge_launch_env,
			[
				'id'         => 'stonewright-companion-bridge-env',
				'title'      => __( 'Developer launch values', 'stonewright' ),
				'copy_label' => __( 'bridge launch values', 'stonewright' ),
				'body_class' => 'stonewright-bridge-env',
				'body_attrs' => [
					'data-stonewright-bridge-token-source'      => $token_id,
					'data-stonewright-bridge-token-placeholder' => $context->bridge_token,
				],
			]
		);

		$body = Notice::callout(
			'info',
			__( 'Most users can skip this.', 'stonewright' ),
			__( 'Step 3 of Get started already runs Stonewright through npx. Use this only if you want WordPress-side WP-CLI abilities to call a local bridge you run yourself.', 'stonewright' )
		)
			. Html::element( 'ol', [ 'class' => 'stonewright-bridge-steps' ], $steps )
			. $table
			. Html::element( 'p', [ 'class' => 'sw-ui-field__help' ], Html::text( __( 'Start the optional bridge with these env vars. The token must match the saved bridge token above.', 'stonewright' ) ) )
			. $env;

		return Html::element(
			'details',
			[ 'class' => 'sw-ui-disclosure stonewright-advanced-bridge', 'data-sw-ui-remember' => 'setup-bridge' ],
			Html::element( 'summary', [], Icon::render( 'chev-r' ) . Html::text( __( 'Local WP-CLI bridge (advanced)', 'stonewright' ) ) )
			. Html::element( 'div', [ 'class' => 'sw-ui-disclosure__body sw-ui-stack', 'id' => 'stonewright-companion-bridge-settings' ], $body )
		);
	}
}
