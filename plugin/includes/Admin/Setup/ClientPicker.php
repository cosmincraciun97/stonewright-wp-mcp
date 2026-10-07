<?php
/**
 * The client picker of the Application Password route.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Admin\Setup;

use Stonewright\WpMcp\Admin\ClientCatalog;
use Stonewright\WpMcp\Admin\ConnectClientConfig;
use Stonewright\WpMcp\Admin\Ui\CodeBlock;
use Stonewright\WpMcp\Admin\Ui\Html;
use Stonewright\WpMcp\Admin\Ui\Notice;

/**
 * One choice per catalog client, the connection method (a local companion over stdio, or remote HTTP) and a panel
 * with the chosen client's snippets for both methods. The page script switches the shown panel and snippet without
 * a request; the server renders the saved client and method as the chosen ones.
 */
final class ClientPicker {

	public static function html( string $username, string $app_password, string $selected_slug, string $selected_method ): string {
		$clients = ConnectClientConfig::chooser_clients();
		$slugs   = array_map(
			static fn( array $client ): string => (string) $client['slug'],
			$clients
		);
		$selected_slug = ClientCatalog::resolve_slug( $selected_slug );
		if ( ! in_array( $selected_slug, $slugs, true ) ) {
			$selected_slug = SetupContext::selected_client( 0 );
		}
		if ( ! in_array( $selected_method, [ 'stdio', 'http' ], true ) ) {
			$selected_method = 'stdio';
		}

		$cards = '';
		foreach ( $clients as $client ) {
			$slug   = (string) $client['slug'];
			$active = $slug === $selected_slug;
			$app_ok = (bool) ( $client['app_password_support'] ?? true );
			$cards .= Html::element(
				'button',
				[
					'type'                       => 'button',
					'role'                       => 'tab',
					'class'                      => Html::classes( 'sw-ui-choice', $active ? 'is-active' : '' ),
					'data-stonewright-client-card' => $slug,
					'aria-selected'              => $active ? 'true' : 'false',
					'aria-disabled'              => $app_ok ? 'false' : 'true',
					'aria-controls'              => 'sw-client-panel-' . $slug,
				],
				Html::element( 'span', [ 'class' => 'sw-ui-choice__title' ], Html::text( (string) $client['label'] ) )
				. Html::element( 'span', [ 'class' => 'sw-ui-choice__text' ], Html::text( self::client_card_blurb( $client ) ) )
			);
		}

		$picker = Html::element(
			'div',
			[ 'class' => 'sw-client-picker', 'data-stonewright-client-picker' => true ],
			Html::element( 'div', [ 'class' => 'sw-ui-choices sw-ui-choices--compact', 'role' => 'tablist', 'aria-label' => __( 'MCP clients', 'stonewright' ) ], $cards )
		);

		$panels = '';
		foreach ( $clients as $client ) {
			$panels .= self::panel( $client, $username, $app_password, $selected_slug, $selected_method );
		}

		return Html::element( 'div', [ 'class' => 'sw-ui-stack' ], $picker . self::method_picker( $selected_method ) . Html::element( 'div', [ 'class' => 'sw-snippet-panels', 'data-stonewright-snippet-panels' => true ], $panels ) );
	}

	private static function method_picker( string $selected_method ): string {
		$option = static function ( string $method, string $label, string $blurb, string $tooltip ) use ( $selected_method ): string {
			$active = $method === $selected_method;

			return Html::element(
				'button',
				[
					'type'                    => 'button',
					'role'                    => 'radio',
					'class'                   => Html::classes( 'sw-ui-choice', $active ? 'is-active' : '' ),
					'data-stonewright-method' => $method,
					'aria-checked'            => $active ? 'true' : 'false',
					'data-sw-tooltip'         => $tooltip,
				],
				Html::element( 'span', [ 'class' => 'sw-ui-choice__title' ], Html::text( $label ) )
				. Html::element( 'span', [ 'class' => 'sw-ui-choice__text' ], Html::text( $blurb ) )
			);
		};

		return Html::element(
			'div',
			[ 'class' => 'sw-method-picker sw-ui-stack', 'data-stonewright-method-picker' => true, 'role' => 'radiogroup', 'aria-label' => __( 'Connection method', 'stonewright' ) ],
			Html::element( 'p', [ 'class' => 'sw-ui-field__help' ], Html::text( __( 'Local stdio means your AI client starts the Stonewright companion on this computer and talks to that local process through standard input/output. It is required for Direct mode and local WP-CLI. Remote HTTP connects straight to the WordPress plugin over HTTPS and does not run a local companion.', 'stonewright' ) ) )
			. Html::element(
				'div',
				[ 'class' => 'sw-ui-choices' ],
				$option(
					'stdio',
					__( 'Local companion (stdio)', 'stonewright' ),
					__( 'AI client starts the companion locally. Required for Direct mode.', 'stonewright' ),
					__( 'Your AI client starts the Stonewright companion on this computer and communicates with it through standard input/output. Required for Direct mode and local WP-CLI.', 'stonewright' )
				)
				. $option(
					'http',
					__( 'Remote Streamable HTTP', 'stonewright' ),
					__( 'No Node or companion required.', 'stonewright' ),
					__( 'Your AI client connects directly to this WordPress site over HTTPS. Best for remote/production sites: nothing to install locally. Requires the application password from this page.', 'stonewright' )
				)
			)
		);
	}

	/** @param array<string, mixed> $client Catalog client row. */
	private static function panel( array $client, string $username, string $app_password, string $selected_slug, string $selected_method ): string {
		$slug   = (string) $client['slug'];
		$active = $slug === $selected_slug;
		$is_cli = 'cli' === (string) ( $client['snippet_kind'] ?? '' );
		$path   = (string) ( $client['config_path'] ?? '' );
		$notes  = (string) ( $client['notes'] ?? '' );
		$app_ok = (bool) ( $client['app_password_support'] ?? true );

		$body = '';
		if ( ! $app_ok ) {
			$body .= Notice::callout(
				'info',
				'',
				sprintf(
					/* translators: %s: client label. */
					__( '%s does not support Application Password. Use OAuth.', 'stonewright' ),
					(string) $client['label']
				)
			);
		}
		if ( '' !== $notes ) {
			$body .= Html::element( 'p', [ 'class' => 'sw-ui-field__help' ], Html::text( $notes ) );
		}

		foreach ( [ 'stdio', 'http' ] as $method ) {
			$snippet = ConnectClientConfig::snippet_for( $slug, $username, $app_password, $method );
			if ( is_wp_error( $snippet ) ) {
				continue;
			}
			$deeplink = (string) ( $snippet['deeplink'] ?? '' );
			$note     = (string) ( $snippet['note'] ?? '' );
			$inner    = '';
			if ( '' !== $deeplink ) {
				$inner .= Html::element(
					'p',
					[ 'class' => 'sw-ui-actions' ],
					'<a class="sw-ui-btn" href="' . esc_url( $deeplink, [ 'cursor' ] ) . '">' . esc_html__( 'Add to Cursor', 'stonewright' ) . '</a>'
					. Html::element( 'span', [ 'class' => 'sw-ui-field__help' ], Html::text( __( 'The link adds the entry below exactly as shown; replace the password placeholder in mcp.json if it is still there.', 'stonewright' ) ) )
				);
			}
			if ( 'http' === $method ) {
				$inner .= Html::element( 'p', [ 'class' => 'sw-ui-field__help' ], Html::text( __( 'Streamable HTTP against the WordPress MCP endpoint. Keep the companion only when you need local WP-CLI workflows.', 'stonewright' ) ) );
			}
			if ( '' !== $note ) {
				$inner .= Html::element( 'p', [ 'class' => 'sw-ui-field__help' ], Html::text( $note ) );
			}
			$inner .= CodeBlock::render(
				self::format_snippet_display( $snippet ),
				[
					'id'         => 'sw-client-snippet-' . $slug . '-' . $method,
					'title'      => $is_cli ? __( 'Run command', 'stonewright' ) : __( 'Config file', 'stonewright' ),
					'where'      => $path,
					'copy_label' => sprintf(
						/* translators: 1: client label, 2: connection method, stdio or http. */
						__( '%1$s snippet (%2$s)', 'stonewright' ),
						(string) $client['label'],
						$method
					),
				]
			);

			$body .= Html::element(
				'div',
				[ 'class' => 'sw-method-snippet sw-ui-stack', 'data-stonewright-method-snippet' => $method, 'hidden' => $method === $selected_method ? null : true ],
				$inner
			);
		}

		return Html::element(
			'div',
			[
				'id'                            => 'sw-client-panel-' . $slug,
				'class'                         => Html::classes( 'sw-client-panel sw-ui-stack', $active ? 'is-active' : '' ),
				'role'                          => 'tabpanel',
				'data-stonewright-client-panel' => $slug,
				'hidden'                        => $active ? null : true,
			],
			$body
		);
	}

	/**
	 * Short card blurb for a catalog snippet kind.
	 *
	 * @param array<string, mixed> $client Catalog client row.
	 */
	private static function client_card_blurb( array $client ): string {
		return match ( (string) ( $client['snippet_kind'] ?? 'json' ) ) {
			'cli'   => __( 'Terminal / CLI command', 'stonewright' ),
			'toml'  => __( 'TOML config block', 'stonewright' ),
			'mixed' => __( 'Client-specific config', 'stonewright' ),
			default => __( 'JSON MCP config', 'stonewright' ),
		};
	}

	/**
	 * @param array<string, mixed> $snippet Snippet payload from ConnectClientConfig.
	 */
	private static function format_snippet_display( array $snippet ): string {
		unset( $snippet['deeplink'], $snippet['note'] );
		if ( isset( $snippet['command'] ) && is_string( $snippet['command'] ) ) {
			return $snippet['command'];
		}
		if ( isset( $snippet['toml'] ) && is_string( $snippet['toml'] ) ) {
			return $snippet['toml'];
		}
		return (string) wp_json_encode( $snippet, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
	}
}
