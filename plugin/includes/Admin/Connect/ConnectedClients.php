<?php
/**
 * Connected OAuth clients for the admin connect panel.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Admin\Connect;

use Stonewright\WpMcp\Admin\ConfigurationPage;
use Stonewright\WpMcp\Authorization\WordPress\AuthorizationLifecycle;
use Stonewright\WpMcp\Authorization\WordPress\ClientStore;
use Stonewright\WpMcp\Authorization\WordPress\FamilyStore;
use Stonewright\WpMcp\Authorization\WordPress\StorageTables;
use Stonewright\WpMcp\Security\AuditLog;
use Stonewright\WpMcp\Support\Logger;

/**
 * Lists the OAuth clients that can still use this site, one row per client with the
 * people who approved it and when it was last used, and disconnects a client on an
 * administrator's request.
 *
 * Disconnecting closes every live grant of the client through the grant store's
 * revocation, which also revokes each access credential of those grants, so the client
 * loses access at once and must sign in again. It first deletes the client's pending
 * consent requests and makes its unused authorization codes unusable, so nothing
 * approved before the disconnect can still create a grant afterwards. The request needs
 * manage_options and a nonce bound to the client, and every attempt is written to the
 * audit log.
 *
 * @phpstan-type Connection array{client_key: string, name: string, identity: string, people: list<string>, grants: int, connected_since: ?int, last_used: ?int}
 */
final class ConnectedClients {

	public const ACTION       = 'stonewright_oauth_disconnect';
	public const NONCE_PREFIX = 'stonewright_oauth_disconnect_';
	public const NOTICE_ARG   = 'stonewright_oauth';
	public const OUTCOMES     = [ 'disconnected', 'none', 'failed' ];

	/** Stand-alone address of the connected clients list; it leads to the list on the Setup screen. */
	public const PAGE = 'stonewright-connected-apps';

	private const CAPABILITY        = 'manage_options';
	private const NAME_LENGTH       = 80;
	private const PEOPLE_SHOWN      = 5;
	private const DISCONNECT_PASSES = 20;

	/**
	 * Connected clients, most recently used first. A store whose schema is not installed
	 * yet, or that cannot be read, reads as empty.
	 *
	 * @return list<Connection>
	 */
	public static function current(): array {
		if ( (int) get_option( StorageTables::VERSION_OPTION, 0 ) < StorageTables::SCHEMA_VERSION ) {
			return [];
		}
		try {
			$storage = AuthorizationLifecycle::storage();
			$grants  = $storage->families()->live_grants( $storage->clock()->now() );
			$clients = $storage->clients();
		} catch ( \Throwable $failure ) {
			Logger::warning( 'oauth_connections_unreadable', [ 'error_class' => get_class( $failure ) ] );
			return [];
		}

		$grouped = [];
		foreach ( $grants as $grant ) {
			$grouped[ $grant['client_key'] ][] = $grant;
		}
		$rows = [];
		foreach ( $grouped as $client_key => $client_grants ) {
			$client_key = (string) $client_key;
			$client     = $clients->find( $client_key );
			$granted    = array_values( array_filter( array_column( $client_grants, 'granted_at' ), 'is_int' ) );
			$rows[]     = [
				'client_key'      => $client_key,
				'name'            => self::name( $client ),
				'identity'        => self::identity( $client ),
				'people'          => self::people( array_column( $client_grants, 'subject_key' ) ),
				'grants'          => count( $client_grants ),
				'connected_since' => [] === $granted ? null : min( $granted ),
				'last_used'       => is_array( $client ) && is_int( $client['last_used_at'] ?? null ) ? $client['last_used_at'] : null,
			];
		}
		usort(
			$rows,
			static fn ( array $a, array $b ): int => [ $b['last_used'] ?? 0, $a['name'] ] <=> [ $a['last_used'] ?? 0, $b['name'] ]
		);
		return $rows;
	}

	/**
	 * Registers the stand-alone address as a page only administrators can open. It is
	 * reachable by URL and never listed in a menu; loading it redirects to the list.
	 */
	public static function register_page(): void {
		$hook = add_submenu_page(
			'options.php',
			__( 'Connected OAuth clients', 'stonewright' ),
			__( 'Connected OAuth clients', 'stonewright' ),
			self::CAPABILITY,
			self::PAGE,
			[ self::class, 'render_page' ]
		);
		if ( is_string( $hook ) && '' !== $hook ) {
			add_action( 'load-' . $hook, [ self::class, 'redirect_page' ] );
		}
	}

	/** Page load hook of the stand-alone address: redirect before any output. */
	public static function redirect_page(): void {
		if ( self::send_to_list() ) {
			exit;
		}
	}

	/** Redirects an administrator to the list on the Setup screen; false when the redirect could not be sent. */
	public static function send_to_list(): bool {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to manage OAuth clients.', 'stonewright' ), '', [ 'response' => 403 ] );
		}
		return wp_safe_redirect( self::list_url() );
	}

	/** Shown only when the redirect could not be sent: a link to the list. */
	public static function render_page(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to manage OAuth clients.', 'stonewright' ), '', [ 'response' => 403 ] );
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Connected OAuth clients', 'stonewright' ); ?></h1>
			<p><a href="<?php echo esc_url( self::list_url() ); ?>"><?php esc_html_e( 'Open the connected OAuth clients on the Setup screen.', 'stonewright' ); ?></a></p>
		</div>
		<?php
	}

	/** URL of the connected clients list on the Setup screen. */
	public static function list_url(): string {
		return admin_url( 'admin.php?page=' . ConfigurationPage::SLUG ) . '#' . SignInPanel::CONNECTIONS_ID;
	}

	/** Handles the admin-post request: disconnect, then return to the connections list. */
	public static function handle(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- disconnect() verifies the nonce bound to the client.
		$result = self::disconnect( $_POST );
		wp_safe_redirect(
			add_query_arg(
				[
					'page'           => ConfigurationPage::SLUG,
					self::NOTICE_ARG => $result['status'],
				],
				admin_url( 'admin.php' )
			) . '#' . SignInPanel::CONNECTIONS_ID
		);
		exit;
	}

	/**
	 * Close every live grant of one client after checking the capability and the nonce.
	 * Its pending consents and unused authorization codes are closed first, so neither
	 * can create a grant afterwards. The status is "disconnected", "none" when the client
	 * held no live grant, or "failed". Grants are read and revoked in batches until none
	 * of the client's are left.
	 *
	 * @param array<string, mixed> $request Form fields: client and _wpnonce.
	 * @param int                  $batch   Grants read per pass.
	 * @return array{status: string, revoked: int}
	 */
	public static function disconnect( array $request, int $batch = FamilyStore::LIVE_GRANT_LIMIT ): array {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to disconnect OAuth clients.', 'stonewright' ), '', [ 'response' => 403 ] );
		}
		$client = is_string( $request['client'] ?? null ) ? sanitize_text_field( wp_unslash( $request['client'] ) ) : '';
		$nonce  = is_string( $request['_wpnonce'] ?? null ) ? sanitize_text_field( wp_unslash( $request['_wpnonce'] ) ) : '';
		if ( 1 !== preg_match( '/^[A-Za-z0-9._-]{1,64}$/D', $client ) || false === wp_verify_nonce( $nonce, self::NONCE_PREFIX . $client ) ) {
			wp_die( esc_html__( 'This disconnect request is no longer valid. Reload the page and try again.', 'stonewright' ), '', [ 'response' => 403 ] );
		}

		$storage = AuthorizationLifecycle::storage();
		$closed  = [];
		$name    = $client;
		try {
			$name = self::name( $storage->clients()->find( $client ) );
			// Closed before the grants: a pending consent or an unused code would otherwise
			// still create a grant after the sweep below has finished.
			$storage->consents()->discard_for_client( $client );
			$storage->codes()->revoke_unused_for_client( $client );
			$families = $storage->families();
			for ( $pass = 0; $pass < self::DISCONNECT_PASSES; ++$pass ) {
				$progress = false;
				foreach ( $families->live_grants( $storage->clock()->now(), $batch, $client ) as $grant ) {
					if ( isset( $closed[ $grant['family_key'] ] ) ) {
						continue;
					}
					$families->revoke( $grant['family_key'] );
					$closed[ $grant['family_key'] ] = true;
					$progress = true;
				}
				if ( ! $progress ) {
					break;
				}
			}
			$revoked = count( $closed );
		} catch ( \Throwable $failure ) {
			$revoked = count( $closed );
			Logger::warning( 'oauth_disconnect_failed', [ 'error_class' => get_class( $failure ) ] );
			self::audit( $client, $name, $revoked, 'error' );
			return [
				'status'  => 'failed',
				'revoked' => $revoked,
			];
		}

		self::audit( $client, $name, $revoked, 'ok' );
		return [
			'status'  => 0 === $revoked ? 'none' : 'disconnected',
			'revoked' => $revoked,
		];
	}

	/** Outcome of the last disconnect named in the page URL, or an empty string. */
	public static function notice(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only notice selector.
		$value = isset( $_GET[ self::NOTICE_ARG ] ) && is_string( $_GET[ self::NOTICE_ARG ] ) ? sanitize_key( wp_unslash( $_GET[ self::NOTICE_ARG ] ) ) : '';
		return in_array( $value, self::OUTCOMES, true ) ? $value : '';
	}

	private static function audit( string $client, string $name, int $revoked, string $status ): void {
		try {
			AuditLog::record(
				'oauth/disconnect',
				[
					'client_id'      => $client,
					'client_name'    => $name,
					'revoked_grants' => $revoked,
					'actor'          => get_current_user_id(),
					'_meta'          => [
						'operation_class' => 'oauth_disconnect',
						'resource_type'   => 'oauth_client',
						'resource_ref'    => $client,
					],
				],
				$status
			);
		} catch ( \Throwable $failure ) {
			Logger::warning( 'oauth_disconnect_audit_failed', [ 'error_class' => get_class( $failure ) ] );
		}
	}

	/** @param array<string, mixed>|null $client */
	private static function name( ?array $client ): string {
		$name = is_array( $client ) ? trim( (string) ( $client['client_name'] ?? '' ) ) : '';
		if ( '' === $name ) {
			return is_array( $client ) ? __( 'Unnamed client', 'stonewright' ) : __( 'Unknown client', 'stonewright' );
		}
		if ( mb_strlen( $name, 'UTF-8' ) > self::NAME_LENGTH ) {
			return mb_substr( $name, 0, self::NAME_LENGTH - 1, 'UTF-8' ) . '…';
		}
		return $name;
	}

	/** @param array<string, mixed>|null $client */
	private static function identity( ?array $client ): string {
		if ( ! is_array( $client ) ) {
			return '';
		}
		$document = $client['client_id_metadata_document'] ?? null;
		if ( ClientStore::DOCUMENT_PURPOSE === ( $client['registration_purpose'] ?? null ) && is_string( $document ) ) {
			$host = (string) parse_url( $document, PHP_URL_HOST );
			if ( '' !== $host ) {
				/* translators: %s: host name that publishes the client's identity. */
				return sprintf( __( 'Published identity from %s', 'stonewright' ), $host );
			}
		}
		return __( 'Registered automatically', 'stonewright' );
	}

	/**
	 * Display names of the people who approved the grants.
	 *
	 * @param list<string> $subjects
	 * @return list<string>
	 */
	private static function people( array $subjects ): array {
		$names = [];
		foreach ( array_unique( $subjects ) as $subject ) {
			$user_id = (int) $subject;
			$user    = $user_id > 0 ? get_user_by( 'id', $user_id ) : false;
			if ( is_object( $user ) ) {
				$display = trim( (string) ( $user->display_name ?? '' ) );
				$names[] = '' !== $display ? $display : (string) ( $user->user_login ?? '' );
			} else {
				/* translators: %d: WordPress user ID. */
				$names[] = sprintf( __( 'Deleted user #%d', 'stonewright' ), $user_id );
			}
		}
		$names = array_values( array_unique( $names ) );
		sort( $names, SORT_NATURAL | SORT_FLAG_CASE );
		if ( count( $names ) > self::PEOPLE_SHOWN ) {
			$more  = count( $names ) - self::PEOPLE_SHOWN;
			$names = array_slice( $names, 0, self::PEOPLE_SHOWN );
			/* translators: %d: number of further people. */
			$names[] = sprintf( _n( 'and %d more', 'and %d more', $more, 'stonewright' ), $more );
		}
		return $names;
	}
}
