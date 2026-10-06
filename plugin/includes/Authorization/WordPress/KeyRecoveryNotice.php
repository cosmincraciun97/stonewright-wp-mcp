<?php
/**
 * Admin notice and retry for missing OAuth keys.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Authorization\WordPress;

use Stonewright\WpMcp\Security\Permissions;

/**
 * Tells administrators when the OAuth keys are missing or unusable and offers a
 * nonce-protected retry (manage_options). Application Passwords keep working
 * meanwhile, which the notice says. It also says, before the button, that new keys
 * sign every connected client out: a site that already issued credentials gets its
 * keys only through this retry, never automatically.
 */
final class KeyRecoveryNotice {

	public const ACTION = 'stonewright_oauth_retry_keys';
	public const RESULT_ARG = 'stonewright_oauth_keys';

	public function __construct( private CredentialKeys $keys ) {}

	public function register(): void {
		add_action( 'admin_notices', [ $this, 'render' ] );
		add_action( 'admin_post_' . self::ACTION, [ $this, 'handle' ] );
	}

	public function render(): void {
		if ( ! Permissions::manage_options() ) {
			return;
		}
		$result = isset( $_GET[ self::RESULT_ARG ] ) ? sanitize_key( wp_unslash( (string) $_GET[ self::RESULT_ARG ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only flag set by the retry redirect.
		if ( $this->keys->ready() ) {
			if ( 'ready' === $result ) {
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Stonewright OAuth keys are ready.', 'stonewright' ) . '</p></div>';
			}
			return;
		}
		$error = $this->keys->error();
		echo '<div class="notice notice-error"><p><strong>' . esc_html__( 'Stonewright OAuth is unavailable:', 'stonewright' ) . '</strong> ';
		echo esc_html__( 'the signing or encryption key could not be created. Application Passwords keep working; OAuth connections need the keys.', 'stonewright' ) . '</p>';
		if ( null !== $error ) {
			echo '<p><code>' . esc_html( $error ) . '</code></p>';
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '" />';
		wp_nonce_field( self::ACTION );
		echo '<p>' . esc_html__( 'Creating new keys signs every connected client out, and each client must sign in again.', 'stonewright' ) . '</p>';
		echo '<p><button type="submit" class="button button-primary">' . esc_html__( 'Retry key creation', 'stonewright' ) . '</button></p>';
		echo '</form></div>';
	}

	/** Verify the request, retry key creation and return where to send the administrator. */
	public function retry(): string {
		if ( ! Permissions::manage_options() ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'stonewright' ), '', [ 'response' => 403 ] );
		}
		$nonce = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['_wpnonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, self::ACTION ) ) {
			wp_die( esc_html__( 'The retry link expired. Reload the page and try again.', 'stonewright' ), '', [ 'response' => 403 ] );
		}
		$ready = $this->keys->ensure();
		return add_query_arg( self::RESULT_ARG, $ready ? 'ready' : 'failed', admin_url( 'admin.php?page=stonewright' ) );
	}

	public function handle(): void {
		wp_safe_redirect( $this->retry() );
		exit;
	}
}
