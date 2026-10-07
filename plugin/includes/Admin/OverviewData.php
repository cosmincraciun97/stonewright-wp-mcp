<?php
/**
 * What the Overview page says: the facts about the connection, what needs attention and the setup steps.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Admin;

use Stonewright\WpMcp\Admin\Connect\ConnectedClients;
use Stonewright\WpMcp\Admin\Connect\SignInStatus;
use Stonewright\WpMcp\Gutenberg\Finalizer\BlockQueue;
use Stonewright\WpMcp\Security\AuditLog;
use Stonewright\WpMcp\Security\ChangeJournal;
use Stonewright\WpMcp\Security\IncidentStore;
use Stonewright\WpMcp\Security\PluginEffectiveState;

/**
 * Plain data, no markup. facts() reads the site once; attention() and setup_steps() turn those facts into what
 * the page shows, so the rules (what counts as needing attention, which setup step is next) are tested without a
 * page around them.
 *
 * Everything is read-only. A source that cannot be read (a table that is not installed yet, a store that fails)
 * reads as nothing to report, never as an error on the page.
 *
 * @phpstan-type Facts array{enabled: bool, sign_in_ready: bool, client_count: int, password_count: int, connection_seen: bool, last_client_use: int|null, audit_incidents: int, rescue_open: int, rescue_unconfirmed: int, queue_queued: int, queue_failed: int}
 * @phpstan-type Item array{id: string, title: string, detail: string, badge: array{label: string, variant: string, icon: string}, action: array{label: string, url: string, context: string}}
 * @phpstan-type Step array{id: string, label: string, cta: string, state: string, url: string}
 */
final class OverviewData {

	/**
	 * Read the site.
	 *
	 * @return Facts
	 */
	public static function facts(): array {
		$clients  = self::guard( static fn (): array => ConnectedClients::current(), [] );
		$passwords = self::guard( static fn (): array => self::application_passwords(), [] );

		$last_used_client   = array_filter( array_column( $clients, 'last_used' ), 'is_int' );
		$last_used_password = array_filter( array_column( $passwords, 'last_used' ), static fn ( $value ): bool => null !== $value && '' !== $value && 0 !== $value );
		$audit_rows         = self::guard( static fn (): int => count( AuditLog::recent( 1, 1 ) ), 0 );

		return [
			'enabled'            => PluginEffectiveState::enabled_requested(),
			'sign_in_ready'      => self::guard( static fn (): bool => SignInStatus::current()['transport_allowed'], false ) || [] !== $passwords,
			'client_count'       => count( $clients ),
			'password_count'     => count( $passwords ),
			'connection_seen'    => [] !== $last_used_client || [] !== $last_used_password || $audit_rows > 0,
			'last_client_use'    => [] !== $last_used_client ? (int) max( $last_used_client ) : null,
			'audit_incidents'    => self::guard( static fn (): int => (int) ( IncidentStore::counts()['open'] ?? 0 ), 0 ),
			'rescue_open'        => self::guard( static fn (): int => count( ChangeJournal::open_incidents() ), 0 ),
			'rescue_unconfirmed' => self::guard( static fn (): int => count( ChangeJournal::unconfirmed() ), 0 ),
			'queue_queued'       => self::guard( static fn (): int => BlockQueue::pending_count(), 0 ),
			'queue_failed'       => self::guard( static fn (): int => BlockQueue::failed_count(), 0 ),
		];
	}

	/**
	 * What needs the person's attention, most urgent first. Each item has a state in words, a sentence about it and
	 * one action. Nothing is listed when there is nothing to do.
	 *
	 * @param Facts $facts
	 * @return list<Item>
	 */
	public static function attention( array $facts ): array {
		$items = [];

		if ( $facts['rescue_open'] > 0 ) {
			$items[] = self::item(
				'rescue-open',
				sprintf(
					/* translators: %d: number of changes */
					_n( '%d change needs a rollback', '%d changes need a rollback', $facts['rescue_open'], 'stonewright' ),
					$facts['rescue_open']
				),
				__( 'A change stopped the site from loading. Open Rescue to roll it back.', 'stonewright' ),
				[ __( 'Open', 'stonewright' ), 'danger', 'x' ],
				[ __( 'Open Rescue', 'stonewright' ), self::page_url( 'stonewright-rescue' ), __( 'for changes that need a rollback', 'stonewright' ) ]
			);
		}
		if ( $facts['rescue_unconfirmed'] > 0 ) {
			$items[] = self::item(
				'rescue-unconfirmed',
				sprintf(
					/* translators: %d: number of changes */
					_n( '%d change was not confirmed', '%d changes were not confirmed', $facts['rescue_unconfirmed'], 'stonewright' ),
					$facts['rescue_unconfirmed']
				),
				__( 'The site did not answer its health check after the change. Open Rescue to check that it loads.', 'stonewright' ),
				[ __( 'Unconfirmed', 'stonewright' ), 'warn', 'alert' ],
				[ __( 'Open Rescue', 'stonewright' ), self::page_url( 'stonewright-rescue' ), __( 'for unconfirmed changes', 'stonewright' ) ]
			);
		}
		if ( $facts['audit_incidents'] > 0 ) {
			$items[] = self::item(
				'audit-incidents',
				sprintf(
					/* translators: %d: number of incidents */
					_n( '%d open incident', '%d open incidents', $facts['audit_incidents'], 'stonewright' ),
					$facts['audit_incidents']
				),
				__( 'The same error keeps coming back in the audit log.', 'stonewright' ),
				[ __( 'Open', 'stonewright' ), 'danger', 'x' ],
				[ __( 'Review incidents', 'stonewright' ), add_query_arg( [ 'page' => 'stonewright-audit-log', 'view' => 'incidents' ], admin_url( 'admin.php' ) ), __( 'in the audit log', 'stonewright' ) ]
			);
		}
		if ( $facts['queue_failed'] > 0 ) {
			$items[] = self::item(
				'queue-failed',
				sprintf(
					/* translators: %d: number of block changes */
					_n( '%d block change failed', '%d block changes failed', $facts['queue_failed'], 'stonewright' ),
					$facts['queue_failed']
				),
				__( 'A queued block change could not be prepared in the editor. Ask the agent to retry it.', 'stonewright' ),
				[ __( 'Failed', 'stonewright' ), 'danger', 'x' ],
				[ __( 'Open block queue', 'stonewright' ), self::page_url( 'stonewright-block-finalizer' ), __( 'for failed block changes', 'stonewright' ) ]
			);
		}
		if ( $facts['queue_queued'] > 0 ) {
			$items[] = self::item(
				'queue-queued',
				sprintf(
					/* translators: %d: number of block changes */
					_n( '%d block change waiting', '%d block changes waiting', $facts['queue_queued'], 'stonewright' ),
					$facts['queue_queued']
				),
				__( 'Open the editor link your agent sent so the editor can prepare them.', 'stonewright' ),
				[ __( 'Queued', 'stonewright' ), 'warn', 'alert' ],
				[ __( 'Open block queue', 'stonewright' ), self::page_url( 'stonewright-block-finalizer' ), __( 'for queued block changes', 'stonewright' ) ]
			);
		}
		if ( $facts['enabled'] && ! $facts['connection_seen'] ) {
			$items[] = self::item(
				'connection',
				__( 'Connection not verified yet', 'stonewright' ),
				__( 'No AI client has called this site yet. Connect one, then run Verify setup.', 'stonewright' ),
				[ __( 'Setup', 'stonewright' ), 'info', 'info' ],
				[ __( 'Open Setup', 'stonewright' ), self::page_url( 'stonewright' ), __( 'to verify the connection', 'stonewright' ) ]
			);
		}

		return $items;
	}

	/**
	 * The four steps to a working connection and which one is next.
	 *
	 * @param Facts $facts
	 * @return array{done: int, total: int, steps: list<Step>, next: Step|null}
	 */
	public static function setup_steps( array $facts ): array {
		$connected = $facts['client_count'] > 0 || $facts['password_count'] > 0;
		$setup     = self::page_url( 'stonewright' );
		$defs      = [
			[ 'enable', __( 'Enable AI abilities', 'stonewright' ), __( 'Enable abilities', 'stonewright' ), $facts['enabled'] ],
			[ 'sign-in', __( 'Choose a sign-in method', 'stonewright' ), __( 'Choose sign-in method', 'stonewright' ), $facts['enabled'] && $facts['sign_in_ready'] ],
			[ 'connect', __( 'Connect a client', 'stonewright' ), __( 'Connect a client', 'stonewright' ), $facts['enabled'] && $connected ],
			[ 'verify', __( 'Verify the connection', 'stonewright' ), __( 'Verify connection', 'stonewright' ), $facts['enabled'] && $facts['connection_seen'] ],
		];

		$steps = [];
		$next  = null;
		$done  = 0;
		foreach ( $defs as $def ) {
			if ( $def[3] ) {
				$state = 'done';
				++$done;
			} elseif ( null === $next ) {
				$state = 'next';
			} else {
				$state = 'todo';
			}
			$step = [
				'id'    => $def[0],
				'label' => $def[1],
				'cta'   => $def[2],
				'state' => $state,
				'url'   => $setup,
			];
			if ( 'next' === $state ) {
				$next = $step;
			}
			$steps[] = $step;
		}

		return [
			'done'  => $done,
			'total' => count( $defs ),
			'steps' => $steps,
			'next'  => $next,
		];
	}

	/**
	 * What the page says about the optional local bridge: a state, never the stored value.
	 *
	 * The tile names a host and port only. A configured URL may carry a path, a query or credentials, and none of
	 * that belongs on a page that is read at a glance. Nothing here claims the bridge is running: the page does not
	 * probe it.
	 *
	 * @return array{state: string, host: string, detail: string}
	 */
	public static function companion( string $url ): array {
		$url = trim( $url );
		if ( '' === $url ) {
			return [
				'state'  => __( 'Not used', 'stonewright' ),
				'host'   => '',
				'detail' => __( 'No bridge URL set', 'stonewright' ),
			];
		}

		$parts = parse_url( $url );
		if ( ! is_array( $parts ) || ! isset( $parts['host'] ) || '' === $parts['host'] ) {
			return [
				'state'  => __( 'Needs attention', 'stonewright' ),
				'host'   => '',
				'detail' => __( 'The bridge URL is not valid', 'stonewright' ),
			];
		}

		return [
			'state'  => __( 'Configured', 'stonewright' ),
			'host'   => $parts['host'] . ( isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '' ),
			'detail' => '',
		];
	}

	/** A stored UTC time as words ("5 mins ago"). A value that is not a time is returned as it is. */
	public static function relative_time( string $mysql_utc ): string {
		$timestamp = strtotime( $mysql_utc . ' UTC' );
		if ( false === $timestamp ) {
			return $mysql_utc;
		}

		return self::relative_since( $timestamp );
	}

	/** A Unix time as words ("5 mins ago"). */
	public static function relative_since( int $timestamp ): string {
		$diff = time() - $timestamp;
		if ( $diff < 60 ) {
			return __( 'just now', 'stonewright' );
		}
		if ( $diff < HOUR_IN_SECONDS ) {
			$mins = (int) floor( $diff / MINUTE_IN_SECONDS );

			return sprintf(
				/* translators: %d: minutes */
				_n( '%d min ago', '%d mins ago', $mins, 'stonewright' ),
				$mins
			);
		}
		if ( $diff < DAY_IN_SECONDS ) {
			$hours = (int) floor( $diff / HOUR_IN_SECONDS );

			return sprintf(
				/* translators: %d: hours */
				_n( '%d hour ago', '%d hours ago', $hours, 'stonewright' ),
				$hours
			);
		}
		$days = (int) floor( $diff / DAY_IN_SECONDS );

		return sprintf(
			/* translators: %d: days */
			_n( '%d day ago', '%d days ago', $days, 'stonewright' ),
			$days
		);
	}

	/** The mode in words, with what it means for destructive actions. @return array{label: string, meta: string} */
	public static function mode( string $mode ): array {
		return match ( $mode ) {
			'production-safe' => [ 'label' => __( 'Production-safe', 'stonewright' ), 'meta' => __( 'Confirmation tokens on', 'stonewright' ) ],
			'staging'         => [ 'label' => __( 'Staging', 'stonewright' ), 'meta' => __( 'Confirmation tokens off', 'stonewright' ) ],
			default           => [ 'label' => __( 'Development', 'stonewright' ), 'meta' => __( 'Confirmation tokens off', 'stonewright' ) ],
		};
	}

	/** The tool surface profile in words. */
	public static function surface_label( string $surface ): string {
		return match ( $surface ) {
			'full'      => __( 'Full profile', 'stonewright' ),
			'bootstrap' => __( 'Bootstrap profile', 'stonewright' ),
			default     => __( 'Essential profile', 'stonewright' ),
		};
	}

	/**
	 * @return list<array{last_used: mixed}>
	 */
	private static function application_passwords(): array {
		$user_id = get_current_user_id();
		if ( $user_id <= 0 || ! class_exists( '\WP_Application_Passwords' ) || ! method_exists( '\WP_Application_Passwords', 'get_user_application_passwords' ) ) {
			return [];
		}
		$passwords = \WP_Application_Passwords::get_user_application_passwords( $user_id );

		return is_array( $passwords ) ? array_values( $passwords ) : [];
	}

	private static function page_url( string $slug ): string {
		return add_query_arg( [ 'page' => $slug ], admin_url( 'admin.php' ) );
	}

	/**
	 * @param array{0: string, 1: string, 2: string} $badge  Label, variant, icon.
	 * @param array{0: string, 1: string, 2: string} $action Label, URL, what it acts on.
	 * @return Item
	 */
	private static function item( string $id, string $title, string $detail, array $badge, array $action ): array {
		return [
			'id'     => $id,
			'title'  => $title,
			'detail' => $detail,
			'badge'  => [ 'label' => $badge[0], 'variant' => $badge[1], 'icon' => $badge[2] ],
			'action' => [ 'label' => $action[0], 'url' => $action[1], 'context' => $action[2] ],
		];
	}

	/**
	 * Run a read; a failure reads as the fallback.
	 *
	 * @template T
	 * @param callable(): T $read
	 * @param T             $fallback
	 * @return T
	 */
	private static function guard( callable $read, mixed $fallback ): mixed {
		try {
			return $read();
		} catch ( \Throwable $failure ) {
			return $fallback;
		}
	}
}
