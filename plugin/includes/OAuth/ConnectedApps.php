<?php
/**
 * SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Derived from includes/oauth/connected-apps.php
 * Source SHA-256: b3ae40648da8ed69bab2cf2125de080d70e999b42abc803e6640c2d4fafb30fa
 *
 * @package Stonewright\WpMcp
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\OAuth;

use Stonewright\WpMcp\OAuth\Repositories\ClientRepository;
use Stonewright\WpMcp\Security\Permissions;

defined( 'ABSPATH' ) || exit;

/**
 * Administrator view and revocation controls for OAuth clients.
 */
final class ConnectedApps {

	public const PAGE_SLUG = 'stonewright-connected-apps';

	public static function register(): void {
		$hook = add_submenu_page(
			'',
			'Connected Apps',
			'',
			'manage_options',
			self::PAGE_SLUG,
			[ self::class, 'render' ]
		);
		if ( is_string( $hook ) && '' !== $hook ) {
			add_action( 'load-' . $hook, [ self::class, 'handle_load' ] );
		}
	}

	public static function can_manage(): bool {
		return is_user_logged_in() && Permissions::manage_options();
	}

	public static function handle_load(): void {
		if ( 'POST' === (string) ( $_SERVER['REQUEST_METHOD'] ?? '' ) && self::can_manage() ) {
			self::handle_post( get_current_user_id() );
		}
	}

	public static function render(): void {
		if ( ! is_user_logged_in() ) {
			wp_die( 'You must be logged in.', '', [ 'response' => 403 ] );
		}
		if ( ! Permissions::manage_options() ) {
			wp_die( 'You are not allowed to manage Stonewright connected apps.', '', [ 'response' => 403 ] );
		}
		self::render_page( get_current_user_id() );
	}

	public static function handle_post( int $user_id ): void {
		$action = $_POST['stonewright_action'] ?? '';
		if ( 'delete_admin_client' === $action ) {
			check_admin_referer( 'stonewright_connected_apps_delete' );
			$raw       = $_POST['client_id'] ?? null;
			$client_id = is_string( $raw ) ? sanitize_key( $raw ) : '';
			if ( '' !== $client_id ) {
				self::revoke_client_access( $client_id, $user_id );
				( new ClientRepository() )->revoke( $client_id );
			}
			wp_safe_redirect( add_query_arg( [ 'deleted' => '1' ], admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ) );
			exit;
		}

		check_admin_referer( 'stonewright_connected_apps_revoke' );
		$raw       = $_POST['client_id'] ?? null;
		$client_id = is_string( $raw ) ? sanitize_key( $raw ) : '';
		if ( '' !== $client_id ) {
			self::revoke_client_access( $client_id, $user_id );
		}
		wp_safe_redirect( add_query_arg( [ 'revoked' => '1' ], admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ) );
		exit;
	}

	public static function revoke_client_access( string $client_id, int $user_id ): void {
		global $wpdb;

		$access  = $wpdb->prefix . 'stonewright_oauth_access_tokens';
		$refresh = $wpdb->prefix . 'stonewright_oauth_refresh_tokens';
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- trusted table names use the WordPress prefix.
		$sql     = $wpdb->prepare(
			"UPDATE `{$refresh}` rt
			JOIN `{$access}` at ON at.identifier_hash = rt.access_token_hash
			SET rt.revoked = 1
			WHERE at.client_id = %s AND at.user_id = %d",
			$client_id,
			$user_id
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( is_string( $sql ) ) {
			$wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
		$wpdb->update(
			$access,
			[ 'revoked' => 1 ],
			[
				'client_id' => $client_id,
				'user_id'   => $user_id,
			]
		);
	}

	public static function render_page( int $user_id ): void {
		global $wpdb;

		$access  = $wpdb->prefix . 'stonewright_oauth_access_tokens';
		$refresh = $wpdb->prefix . 'stonewright_oauth_refresh_tokens';
		$clients = $wpdb->prefix . 'stonewright_oauth_clients';
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- trusted table names use the WordPress prefix.
		$sql     = $wpdb->prepare(
			"SELECT c.client_name, at.client_id, at.scopes, MAX(rt.expires_at) AS expires_at
			FROM `{$refresh}` rt
			JOIN `{$access}` at ON at.identifier_hash = rt.access_token_hash
			JOIN `{$clients}` c ON c.client_id = at.client_id
			WHERE at.user_id = %d AND rt.revoked = 0 AND rt.expires_at > %s
			GROUP BY at.client_id, c.client_name, at.scopes
			ORDER BY expires_at DESC",
			$user_id,
			gmdate( 'Y-m-d H:i:s' )
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = is_string( $sql )
			? $wpdb->get_results( $sql, ARRAY_A ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			: [];
		$apps = is_array( $rows ) ? $rows : [];

		echo '<div class="wrap"><h1>' . esc_html__( 'Connected Apps', 'stonewright' ) . '</h1>';
		echo '<p>' . esc_html__( 'These applications have been granted access to your WordPress account via Stonewright. The connection renews automatically while in use; the expiry shown is when it lapses if the app stops connecting.', 'stonewright' ) . '</p>';

		$revoked = $_GET['revoked'] ?? null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( '1' === $revoked ) {
			echo '<div class="notice notice-success is-dismissible"><p>';
			echo esc_html__( 'Access revoked successfully.', 'stonewright' ) . '</p></div>';
		}

		if ( [] === $apps ) {
			echo '<p>' . esc_html__( 'No apps are currently connected to your account.', 'stonewright' ) . '</p>';
			self::render_admin_clients_section();
			echo '</div>';
			return;
		}

		echo '<table class="wp-list-table widefat fixed striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Application', 'stonewright' ) . '</th>';
		echo '<th>' . esc_html__( 'Scope', 'stonewright' ) . '</th>';
		echo '<th>' . esc_html__( 'Connection expires', 'stonewright' ) . '</th><th></th></tr></thead><tbody>';
		foreach ( $apps as $app ) {
			$name       = (string) ( $app['client_name'] ?? '' );
			$client_id  = (string) ( $app['client_id'] ?? '' );
			$scopes_raw = (string) ( $app['scopes'] ?? '' );
			$expires    = (string) ( $app['expires_at'] ?? '' );
			$scopes     = json_decode( $scopes_raw, true );
			$scope_text = is_array( $scopes )
				? implode( ' ', array_map( static fn( mixed $scope ): string => is_string( $scope ) ? $scope : '', $scopes ) )
				: $scopes_raw;

			echo '<tr><td><strong>' . esc_html( $name ) . '</strong></td>';
			echo '<td>' . esc_html( $scope_text ) . '</td><td>' . esc_html( $expires ) . '</td><td>';
			echo '<form method="post">';
			wp_nonce_field( 'stonewright_connected_apps_revoke' );
			echo '<input type="hidden" name="client_id" value="' . esc_attr( $client_id ) . '">';
			echo '<button type="submit" class="button">' . esc_html__( 'Revoke Access', 'stonewright' ) . '</button>';
			echo '</form></td></tr>';
		}
		echo '</tbody></table>';
		self::render_admin_clients_section();
		echo '</div>';
	}

	public static function render_admin_clients_section(): void {
		$clients = ( new ClientRepository() )->list_admin_clients();
		$deleted = $_GET['deleted'] ?? null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( '1' === $deleted ) {
			echo '<div class="notice notice-success is-dismissible"><p>';
			echo esc_html__( 'Client ID deleted.', 'stonewright' ) . '</p></div>';
		}
		if ( [] === $clients ) {
			return;
		}

		echo '<h2>' . esc_html__( 'Manually created client IDs', 'stonewright' ) . '</h2>';
		echo '<table class="wp-list-table widefat fixed striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Application', 'stonewright' ) . '</th>';
		echo '<th>' . esc_html__( 'Client ID', 'stonewright' ) . '</th>';
		echo '<th>' . esc_html__( 'Created', 'stonewright' ) . '</th>';
		echo '<th>' . esc_html__( 'First used', 'stonewright' ) . '</th><th></th></tr></thead><tbody>';
		foreach ( $clients as $client ) {
			echo '<tr><td><strong>' . esc_html( $client['client_name'] ) . '</strong></td>';
			echo '<td><code>' . esc_html( $client['client_id'] ) . '</code></td>';
			echo '<td>' . esc_html( $client['created_at'] ) . '</td>';
			echo '<td>' . esc_html( $client['last_used_at'] ?? __( 'Never', 'stonewright' ) ) . '</td><td>';
			echo '<form method="post">';
			wp_nonce_field( 'stonewright_connected_apps_delete' );
			echo '<input type="hidden" name="stonewright_action" value="delete_admin_client">';
			echo '<input type="hidden" name="client_id" value="' . esc_attr( $client['client_id'] ) . '">';
			echo '<button type="submit" class="button">' . esc_html__( 'Delete', 'stonewright' ) . '</button>';
			echo '</form></td></tr>';
		}
		echo '</tbody></table>';
	}
}
