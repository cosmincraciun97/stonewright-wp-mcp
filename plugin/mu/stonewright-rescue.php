<?php
/**
 * Plugin Name: Stonewright Rescue
 * Description: Records a fatal error that follows a Stonewright change and opens a short-lived safe mode (only Stonewright active, default theme) for the administrator a one-time rescue link was issued to. Installed and kept current by the Stonewright plugin; it does nothing while Stonewright is inactive.
 * Version: 1
 * Author: Stonewright
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright\WpMcp
 * @stonewright-rescue-mu
 */

defined( 'ABSPATH' ) || exit;

// The class is declared only when it does not exist yet, so a second copy of this file is harmless.
if ( ! class_exists( 'Stonewright_Rescue', false ) ) :

// phpcs:disable WordPress.Security.NonceVerification.Recommended,WordPress.Security.NonceVerification.Missing -- The request is read before WordPress has loaded: the rescue key is the credential, and no nonce exists yet.

/**
 * Stonewright rescue runtime.
 *
 * This file has no dependencies: it uses WordPress core functions only, no Composer
 * autoloader and no Stonewright class, and its syntax is valid on PHP 7.4. WordPress loads
 * it before any regular plugin and before the theme. It does nothing while Stonewright is
 * not an active plugin.
 *
 * Fatal error record. A shutdown handler looks at the last error. For a fatal error type it
 * matches the error's file (relative to ABSPATH) against the paths of the change journal
 * entries that are armed, or that settled in the last 15 minutes, and writes an incident
 * into the matching entry of the journal file in uploads/stonewright-state/. The handler
 * never rolls anything back and reads no database: a journal that cannot be read or
 * written is left alone, and the handler never raises an error of its own. Writes follow the
 * journal protocol: an exclusive flock on "<journal>.lock", a temporary file in the same
 * directory, a rename over the journal.
 *
 * Rescue keys. The plugin asks issue_key() for a key bound to one administrator. The key
 * is 16 + 32 lowercase hex characters (an id and a secret). Only the SHA-256 of the secret
 * is stored, in a non-autoloaded option, with the user id and an expiry 15 minutes ahead.
 * A key is single use: redeeming deletes its record first and only the request whose delete
 * removed the row can go on, so two requests can never both redeem it. Any attempt burns
 * the record, and an expired key, a wrong secret or a user who is no longer an
 * administrator all fail the same way. A key never reaches a log or the audit log.
 *
 * Safe boot session. A valid key sent to wp-login.php with a GET request is redeemed and
 * replaced by a session: a random token whose SHA-256 is stored with the same user id and a
 * 30 minute expiry. The browser receives the token in a cookie that is HttpOnly, Secure when
 * the site uses HTTPS, SameSite=Lax and expires with the session. The request is redirected
 * to the same login URL without the key. The redemption signs nobody in and the login page is
 * never loaded in safe boot: signing in always runs with the site's own plugins, login
 * protections included. A later request that presents a valid session cookie is loaded in
 * safe boot when it goes to wp-admin (admin-ajax.php included), or, with a WordPress logged-in
 * cookie, to the front controller (index.php), which serves pages and REST requests:
 *
 * - the stored list of active plugins (and the network list) is filtered down to Stonewright;
 * - the template and stylesheet are the installed default theme (or a theme that does not
 *   exist, which loads no theme code, when no default theme is installed);
 * - writes to the options that hold the plugin and theme selection are ignored, so the
 *   filtered values are never saved. Code that must change them on purpose, such as a
 *   rollback, runs inside with_stored_selection(), where reads and writes both use the
 *   stored selection.
 *
 * Safe boot is the browser's session, not a site mode. It never applies to a request that has
 * no valid session cookie (the optional MCP route mode below is the one exception, and it has
 * its own conditions), so anonymous front-end traffic is never served in safe boot. It never
 * applies to the login page, XML-RPC, cron or any other entry script either. Safe boot pages
 * are sent with no-cache headers and with DONOTCACHEPAGE defined, so that page caches that
 * honour them do not store a page. The session belongs to the administrator the key was
 * issued to, and the browser's requests are loaded in safe boot once that administrator has
 * signed in the normal way. If any other user signs in the session ends, and if any other
 * user becomes the current user in a safe boot request the session ends and the request is
 * stopped. Logging out ends it too. The session is checked against the logged-in user through
 * those rules: the session record holds the administrator.
 *
 * Optional MCP route mode. When the stonewright_rescue_mcp_safe_boot option is on and the
 * journal holds an open incident, a request to the Stonewright MCP routes that carries an
 * Authorization header of the scheme that route accepts is loaded in the same safe boot,
 * without a session and without a cookie. The request has to be one that WordPress serves as
 * a REST request through the front controller, index.php: the route is the one WordPress
 * reads (the rest_route form field, then the rest_route query parameter, then the part of the
 * path behind the REST base at the start of the path after the home path). A request to
 * wp-login.php, to anything under wp-admin, to xmlrpc.php or to any other entry script is
 * never loaded in this mode. Authentication and permission checks are not touched:
 * Stonewright still authenticates the request and every ability still checks its permissions.
 * The option is off by default.
 *
 * Limits. Safe boot cannot help when WordPress core, wp-config.php, a drop-in or another
 * must-use plugin fails before this file runs, or when the database is down.
 */
final class Stonewright_Rescue {

	/** Version of this file. The plugin refuses to use a loaded copy that is older than it needs. */
	const VERSION = 1;

	const OPTION_PLUGIN  = 'stonewright_rescue_plugin';
	const OPTION_JOURNAL = 'stonewright_rescue_journal_file';
	const OPTION_STATE_DIR = 'stonewright_rescue_state_dir';
	const OPTION_MCP     = 'stonewright_rescue_mcp_safe_boot';
	const KEY_PREFIX     = 'stonewright_rescue_key_';
	const SESSION_PREFIX = 'stonewright_rescue_sess_';
	const SESSION_COOKIE = 'stonewright_rescue_session';
	const QUERY_ARG      = 'stonewright_rescue';
	const EXIT_ARG       = 'stonewright_rescue_exit';
	const EXIT_NONCE     = 'stonewright_rescue_exit';
	const NO_THEME       = 'stonewright-rescue-no-theme';

	/** Seconds a rescue key can be redeemed. */
	const KEY_TTL = 900;

	/** Seconds a safe boot session lasts. */
	const SESSION_TTL = 1800;

	/** Most keys that can wait to be redeemed at once. */
	const MAX_KEYS = 5;

	/** Seconds after settling in which a verified or rolled back entry still matches a fatal error. */
	const SETTLED_SECONDS = 900;

	const MAX_ENTRIES = 50;

	/** Largest journal file the handler will read. */
	const MAX_BYTES = 1048576;

	/** Milliseconds the handler waits for the journal lock. */
	const LOCK_WAIT_MS = 2000;

	/** Bytes held back during the request and released first thing in the shutdown handler. */
	const RESERVE_BYTES = 131072;

	private static $booted         = false;
	private static $plugin         = '';
	private static $journal_name   = '';
	private static $reserve;
	private static $safe           = false;
	private static $safe_user      = 0;
	private static $session_user   = 0;
	private static $session_ref    = '';
	private static $stored_selection = false;
	private static $theme;
	private static $state_dir      = '';

	// ------------------------------------------------------------------
	// Boot.
	// ------------------------------------------------------------------

	/**
	 * Registers the shutdown handler and, for a request that carries a key, a session or an
	 * MCP route credential, safe boot. Does nothing while Stonewright is not active.
	 */
	public static function boot() {
		if ( self::$booted || ! function_exists( 'add_filter' ) || ! function_exists( 'get_option' ) ) {
			return;
		}
		self::$booted = true;
		self::$plugin = self::active_plugin();
		if ( '' === self::$plugin ) {
			return;
		}
		$name               = get_option( self::OPTION_JOURNAL, '' );
		self::$journal_name = is_string( $name ) && preg_match( '/^journal-[a-f0-9]{32}\.json$/D', $name ) ? $name : '';
		$dir                = get_option( self::OPTION_STATE_DIR, '' );
		self::$state_dir    = is_string( $dir ) && strlen( $dir ) < 500 && false === strpos( $dir, "\0" ) ? rtrim( str_replace( '\\', '/', $dir ), '/' ) : '';
		self::$reserve      = str_repeat( 'x', self::RESERVE_BYTES );
		register_shutdown_function( [ __CLASS__, 'on_shutdown' ] );
		if ( 'cli' !== PHP_SAPI && ! defined( 'WP_CLI' ) ) {
			self::start_safe_boot();
		}
	}

	/**
	 * Forgets the state of the current request. Used when a test simulates more than one request.
	 *
	 * @internal
	 */
	public static function reset() {
		self::$booted         = false;
		self::$plugin         = '';
		self::$journal_name   = '';
		self::$reserve        = null;
		self::$safe           = false;
		self::$safe_user      = 0;
		self::$session_user   = 0;
		self::$session_ref    = '';
		self::$stored_selection = false;
		self::$theme          = null;
		self::$state_dir      = '';
	}

	/** The basename of the Stonewright plugin when it is active, or an empty string. */
	private static function active_plugin() {
		$basename = get_option( self::OPTION_PLUGIN, '' );
		if ( ! is_string( $basename ) || ! preg_match( '#^[A-Za-z0-9._/-]+\.php$#D', $basename ) || false !== strpos( $basename, '..' ) ) {
			return '';
		}
		$active = get_option( 'active_plugins', [] );
		if ( is_array( $active ) && in_array( $basename, $active, true ) ) {
			return $basename;
		}
		if ( defined( 'MULTISITE' ) && MULTISITE && function_exists( 'get_site_option' ) ) {
			$network = get_site_option( 'active_sitewide_plugins', [] );
			if ( is_array( $network ) && isset( $network[ $basename ] ) ) {
				return $basename;
			}
		}
		return '';
	}

	// ------------------------------------------------------------------
	// Fatal error record.
	// ------------------------------------------------------------------

	/** Shutdown handler. Never raises an error of its own. */
	public static function on_shutdown() {
		self::$reserve = null;
		try {
			$error = error_get_last();
			if ( is_array( $error ) ) {
				self::record_fatal( $error );
			}
		} catch ( Throwable $failure ) {
			return;
		}
	}

	/**
	 * Writes an incident for a fatal error into the journal entry that its file points at.
	 *
	 * @param array    $error An error_get_last() array.
	 * @param int|null $now   Current time; defaults to time().
	 * @return string|null The id of the entry that holds the incident, or null.
	 */
	public static function record_fatal( $error, $now = null ) {
		if ( ! is_array( $error ) || ! isset( $error['type'], $error['file'] ) || ! in_array( (int) $error['type'], self::fatal_types(), true ) ) {
			return null;
		}
		$relative = self::relative_path( (string) $error['file'] );
		$journal  = self::journal_file();
		if ( null === $relative || null === $journal ) {
			return null;
		}
		$now      = null === $now ? time() : (int) $now;
		$incident = (object) [
			'recorded_at'    => $now,
			'file'           => $relative,
			'line'           => isset( $error['line'] ) ? (int) $error['line'] : 0,
			'type'           => (int) $error['type'],
			'message_sha256' => hash( 'sha256', isset( $error['message'] ) ? (string) $error['message'] : '' ),
			'source'         => 'shutdown',
		];

		$size = @filesize( $journal );
		if ( false === $size || ! self::has_memory_for( (int) $size ) ) {
			return null;
		}
		$data = self::read_journal( $journal );
		if ( null === $data ) {
			return null;
		}
		$index = self::correlate( $data, $relative, $now );
		if ( null === $index ) {
			return null;
		}
		if ( self::holds_incident( $data->entries[ $index ] ) ) {
			return self::entry_id( $data->entries[ $index ] );
		}

		$recorded = null;
		self::update_journal(
			$journal,
			function ( $current ) use ( $relative, $now, $incident, &$recorded ) {
				$hit = self::correlate( $current, $relative, $now );
				if ( null === $hit ) {
					return false;
				}
				$entry    = $current->entries[ $hit ];
				$recorded = self::entry_id( $entry );
				if ( self::holds_incident( $entry ) ) {
					return false;
				}
				$entry->incident     = $incident;
				$entry->state        = 'incident';
				$current->updated_at = $now;
				$current->entries    = self::trim_entries( $current->entries );
				return true;
			}
		);
		return $recorded;
	}

	/** Whether decoding and rewriting a journal of $bytes bytes fits in the memory that is left. */
	private static function has_memory_for( $bytes ) {
		$limit = ini_get( 'memory_limit' );
		if ( ! is_string( $limit ) || '' === $limit || '-1' === $limit ) {
			return true;
		}
		$number = (float) $limit;
		switch ( strtolower( substr( $limit, -1 ) ) ) {
			case 'g':
				$number *= 1024;
				// Fall through.
			case 'm':
				$number *= 1024;
				// Fall through.
			case 'k':
				$number *= 1024;
		}
		return $number <= 0 || $number - memory_get_usage() >= $bytes * 6 + 65536;
	}

	private static function entry_id( $entry ) {
		return isset( $entry->id ) && is_scalar( $entry->id ) ? (string) $entry->id : '';
	}

	private static function entry_state( $entry ) {
		return is_object( $entry ) && isset( $entry->state ) && is_string( $entry->state ) ? $entry->state : '';
	}

	/**
	 * The index of the journal entry that a fatal error in $relative belongs to, or null.
	 *
	 * An entry matches when it is armed, unverified or failed to roll back, or when it verified
	 * or rolled back in the last 15 minutes, and one of its paths is the file or a directory
	 * that holds it. The latest matching entry wins.
	 *
	 * @param object $data     The decoded journal.
	 * @param string $relative Path of the failing file relative to ABSPATH.
	 * @param int    $now      Current time.
	 * @return int|null
	 */
	public static function correlate( $data, $relative, $now ) {
		if ( ! is_object( $data ) || ! isset( $data->entries ) || ! is_array( $data->entries ) ) {
			return null;
		}
		$best    = null;
		$best_at = -1;
		foreach ( $data->entries as $index => $entry ) {
			if ( ! is_object( $entry ) || ! isset( $entry->state ) || ! is_string( $entry->state ) ) {
				continue;
			}
			$armed_at = isset( $entry->armed_at ) && is_numeric( $entry->armed_at ) ? (int) $entry->armed_at : 0;
			if ( $now + 5 < $armed_at || ! self::is_open( $entry, $armed_at, (int) $now ) ) {
				continue;
			}
			if ( ! isset( $entry->paths ) || ! is_array( $entry->paths ) || ! self::paths_match( $entry->paths, $relative ) ) {
				continue;
			}
			if ( $armed_at >= $best_at ) {
				$best    = $index;
				$best_at = $armed_at;
			}
		}
		return $best;
	}

	/** Whether an entry can still be the cause of a fatal error at $now. */
	private static function is_open( $entry, $armed_at, $now ) {
		switch ( $entry->state ) {
			case 'armed':
			case 'probe_unavailable':
			case 'rollback_failed':
			case 'incident':
				return true;
			case 'verified':
			case 'rolled_back':
				if ( isset( $entry->settled_at ) && is_numeric( $entry->settled_at ) ) {
					$settled = (int) $entry->settled_at;
				} else {
					$deadline = isset( $entry->probe_deadline ) && is_numeric( $entry->probe_deadline ) ? (int) $entry->probe_deadline : 0;
					$settled  = max( $armed_at, $deadline );
				}
				return $now - $settled <= self::SETTLED_SECONDS;
		}
		return false;
	}

	private static function paths_match( $paths, $relative ) {
		$windows = '\\' === DIRECTORY_SEPARATOR;
		$target  = $windows ? strtolower( $relative ) : $relative;
		foreach ( $paths as $path ) {
			if ( ! is_string( $path ) ) {
				continue;
			}
			$path = trim( str_replace( '\\', '/', $path ) );
			$path = ltrim( preg_replace( '#^(?:\./)+#', '', $path ), '/' );
			$path = rtrim( $path, '/' );
			if ( '' === $path ) {
				continue;
			}
			$path = $windows ? strtolower( $path ) : $path;
			if ( $target === $path || 0 === strpos( $target, $path . '/' ) ) {
				return true;
			}
		}
		return false;
	}

	/** Whether an entry already holds an incident. The first one stays: an entry carries one incident. */
	private static function holds_incident( $entry ) {
		return 'incident' === self::entry_state( $entry ) && isset( $entry->incident ) && is_object( $entry->incident );
	}

	/** Keeps at most MAX_ENTRIES entries, dropping the oldest settled ones first. */
	private static function trim_entries( $entries ) {
		$excess = count( $entries ) - self::MAX_ENTRIES;
		if ( $excess <= 0 ) {
			return $entries;
		}
		$order = array_keys( $entries );
		usort(
			$order,
			function ( $a, $b ) use ( $entries ) {
				$sa = in_array( self::entry_state( $entries[ $a ] ), [ 'verified', 'rolled_back' ], true ) ? 0 : 1;
				$sb = in_array( self::entry_state( $entries[ $b ] ), [ 'verified', 'rolled_back' ], true ) ? 0 : 1;
				if ( $sa !== $sb ) {
					return $sa - $sb;
				}
				$aa = is_object( $entries[ $a ] ) && isset( $entries[ $a ]->armed_at ) ? (int) $entries[ $a ]->armed_at : 0;
				$ab = is_object( $entries[ $b ] ) && isset( $entries[ $b ]->armed_at ) ? (int) $entries[ $b ]->armed_at : 0;
				return $aa !== $ab ? ( $aa < $ab ? -1 : 1 ) : $a - $b;
			}
		);
		$drop = array_flip( array_slice( $order, 0, $excess ) );
		$kept = [];
		foreach ( $entries as $index => $entry ) {
			if ( ! isset( $drop[ $index ] ) ) {
				$kept[] = $entry;
			}
		}
		return $kept;
	}

	private static function fatal_types() {
		return [ E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR ];
	}

	/** A path relative to ABSPATH, or null when the file is outside it. */
	private static function relative_path( $file ) {
		$file  = str_replace( '\\', '/', $file );
		$roots = [ rtrim( str_replace( '\\', '/', ABSPATH ), '/' ) . '/' ];
		$real  = realpath( ABSPATH );
		if ( is_string( $real ) ) {
			$roots[] = rtrim( str_replace( '\\', '/', $real ), '/' ) . '/';
		}
		$windows = '\\' === DIRECTORY_SEPARATOR;
		foreach ( $roots as $root ) {
			$head = substr( $file, 0, strlen( $root ) );
			if ( strlen( $file ) > strlen( $root ) && ( $windows ? strtolower( $head ) === strtolower( $root ) : $head === $root ) ) {
				return substr( $file, strlen( $root ) );
			}
		}
		return null;
	}

	/** The directories that can hold uploads/stonewright-state/, most specific first. No database access. */
	private static function state_dirs() {
		$base = defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR : ABSPATH . 'wp-content';
		$dirs = [];
		if ( defined( 'MULTISITE' ) && MULTISITE && isset( $GLOBALS['blog_id'] ) && (int) $GLOBALS['blog_id'] > 1 ) {
			$dirs[] = $base . '/uploads/sites/' . (int) $GLOBALS['blog_id'];
		}
		if ( defined( 'UPLOADS' ) && is_string( UPLOADS ) && '' !== UPLOADS ) {
			$dirs[] = ABSPATH . trim( UPLOADS, '/\\' );
		}
		$dirs[] = $base . '/uploads';
		$out    = '' === self::$state_dir ? [] : [ self::$state_dir ];
		foreach ( $dirs as $dir ) {
			$out[] = rtrim( str_replace( '\\', '/', $dir ), '/' ) . '/stonewright-state';
		}
		return $out;
	}

	/** The path of the journal file, or null when there is none. */
	private static function journal_file() {
		foreach ( self::state_dirs() as $dir ) {
			if ( '' !== self::$journal_name ) {
				if ( is_file( $dir . '/' . self::$journal_name ) ) {
					return $dir . '/' . self::$journal_name;
				}
				continue;
			}
			$found  = glob( $dir . '/journal-*.json' );
			$newest = null;
			$time   = -1;
			foreach ( is_array( $found ) ? $found : [] as $candidate ) {
				if ( preg_match( '/journal-[a-f0-9]{32}\.json$/D', $candidate ) && (int) @filemtime( $candidate ) > $time ) {
					$newest = $candidate;
					$time   = (int) @filemtime( $candidate );
				}
			}
			if ( null !== $newest ) {
				return $newest;
			}
		}
		return null;
	}

	/** The decoded journal, or null when the file is missing, too large, unreadable or of another version. */
	private static function read_journal( $file ) {
		$size = @filesize( $file );
		if ( false === $size || $size < 2 || $size > self::MAX_BYTES ) {
			return null;
		}
		$raw = @file_get_contents( $file );
		if ( ! is_string( $raw ) ) {
			return null;
		}
		$data = json_decode( $raw );
		if ( ! is_object( $data ) || ! isset( $data->version, $data->entries ) || 1 !== (int) $data->version || ! is_array( $data->entries ) ) {
			return null;
		}
		return $data;
	}

	/**
	 * Applies $mutator to the journal while holding the lock. The mutator returns true when it
	 * changed the document; the change is then written to a temporary file and renamed over the
	 * journal. Returns whether a change was written.
	 */
	private static function update_journal( $file, $mutator ) {
		$lock = @fopen( $file . '.lock', 'c' );
		if ( false === $lock ) {
			return false;
		}
		$locked   = false;
		$deadline = microtime( true ) + self::LOCK_WAIT_MS / 1000;
		do {
			$locked = flock( $lock, LOCK_EX | LOCK_NB );
			if ( $locked ) {
				break;
			}
			usleep( 4000 );
		} while ( microtime( true ) < $deadline );
		if ( ! $locked ) {
			fclose( $lock );
			return false;
		}
		$written = false;
		try {
			$data = self::read_journal( $file );
			if ( null !== $data && true === call_user_func( $mutator, $data ) ) {
				$written = self::replace_file( $file, $data );
			}
		} finally {
			flock( $lock, LOCK_UN );
			fclose( $lock );
		}
		return $written;
	}

	private static function replace_file( $file, $data ) {
		$json = json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( ! is_string( $json ) ) {
			return false;
		}
		$temp = $file . '.tmp-' . substr( md5( uniqid( '', true ) ), 0, 8 );
		if ( false === @file_put_contents( $temp, $json ) ) {
			@unlink( $temp );
			return false;
		}
		$mode = @fileperms( $file );
		if ( false !== $mode ) {
			@chmod( $temp, $mode & 0777 );
		}
		for ( $attempt = 0; $attempt < 25; $attempt++ ) {
			if ( @rename( $temp, $file ) ) {
				return true;
			}
			usleep( 8000 );
		}
		@unlink( $temp );
		return false;
	}

	/** Whether the journal holds an entry that is an open incident: one whose state is "incident" or "rollback_failed". */
	public static function has_open_incident() {
		$file = self::journal_file();
		$data = null === $file ? null : self::read_journal( $file );
		if ( null === $data ) {
			return false;
		}
		foreach ( $data->entries as $entry ) {
			if ( in_array( self::entry_state( $entry ), [ 'incident', 'rollback_failed' ], true ) ) {
				return true;
			}
		}
		return false;
	}

	// ------------------------------------------------------------------
	// Rescue keys and sessions.
	// ------------------------------------------------------------------

	/**
	 * Issues a rescue key bound to an administrator.
	 *
	 * @param int $user_id The administrator the key is for.
	 * @return array|null array( 'key' => string, 'expires_at' => int ), or null when the user is not
	 *                    an administrator or the key could not be stored.
	 */
	public static function issue_key( $user_id ) {
		$user_id = (int) $user_id;
		if ( $user_id < 1 || ! self::is_administrator( $user_id ) ) {
			return null;
		}
		try {
			$id     = bin2hex( random_bytes( 8 ) );
			$secret = bin2hex( random_bytes( 16 ) );
		} catch ( Exception $failure ) {
			return null;
		}
		self::purge_expired();
		$waiting = self::records( self::KEY_PREFIX );
		if ( count( $waiting ) >= self::MAX_KEYS ) {
			usort(
				$waiting,
				function ( $a, $b ) {
					return $a['expires'] - $b['expires'];
				}
			);
			foreach ( array_slice( $waiting, 0, count( $waiting ) - self::MAX_KEYS + 1 ) as $oldest ) {
				delete_option( $oldest['name'] );
			}
		}
		$now    = time();
		$record = [
			'h' => hash( 'sha256', $secret ),
			'u' => $user_id,
			'c' => $now,
			'e' => $now + self::KEY_TTL,
		];
		if ( ! add_option( self::KEY_PREFIX . $id, self::encode( $record ), '', 'no' ) ) {
			return null;
		}
		return [
			'key'        => $id . '.' . $secret,
			'expires_at' => $now + self::KEY_TTL,
		];
	}

	/**
	 * Redeems a key: the user id it is bound to, or 0 when it cannot be used.
	 *
	 * The record is deleted before anything else is checked, and only the request whose delete
	 * removed the row goes on.
	 */
	private static function redeem( $raw ) {
		if ( ! is_string( $raw ) || ! preg_match( '/^([a-f0-9]{16})\.([a-f0-9]{32})$/D', $raw, $parts ) ) {
			return 0;
		}
		$name   = self::KEY_PREFIX . $parts[1];
		$stored = self::stored_value( $name );
		if ( null === $stored || ! delete_option( $name ) ) {
			return 0;
		}
		$record = json_decode( $stored, true );
		if ( ! is_array( $record ) || ! isset( $record['h'], $record['u'], $record['e'] ) ) {
			return 0;
		}
		if ( ! hash_equals( (string) $record['h'], hash( 'sha256', $parts[2] ) ) || (int) $record['e'] < time() ) {
			return 0;
		}
		$user_id = (int) $record['u'];
		return $user_id > 0 && self::is_administrator( $user_id ) ? $user_id : 0;
	}

	/** Creates the session for a redeemed key and sends its cookie. */
	private static function start_session( $user_id ) {
		self::revoke_session( false );
		try {
			$token = bin2hex( random_bytes( 16 ) );
		} catch ( Exception $failure ) {
			return false;
		}
		$hash   = hash( 'sha256', $token );
		$now    = time();
		$record = [
			'h' => $hash,
			'u' => (int) $user_id,
			'c' => $now,
			'e' => $now + self::SESSION_TTL,
		];
		$name = self::SESSION_PREFIX . substr( $hash, 0, 40 );
		if ( ! add_option( $name, self::encode( $record ), '', 'no' ) ) {
			return false;
		}
		if ( ! self::send_cookie( $token, $now + self::SESSION_TTL ) ) {
			delete_option( $name );
			return false;
		}
		return true;
	}

	/**
	 * The valid session of this request: array( 'u' => user id, 'e' => expiry, 'ref' => short id ), or null.
	 *
	 * @return array|null
	 */
	public static function read_session() {
		$token = self::cookie_token();
		if ( null === $token ) {
			return null;
		}
		$hash   = hash( 'sha256', $token );
		$name   = self::SESSION_PREFIX . substr( $hash, 0, 40 );
		$stored = self::stored_value( $name );
		if ( null === $stored ) {
			return null;
		}
		$record = json_decode( $stored, true );
		if ( ! is_array( $record ) || ! isset( $record['h'], $record['u'], $record['e'] ) || ! hash_equals( (string) $record['h'], $hash ) ) {
			return null;
		}
		if ( (int) $record['e'] < time() ) {
			delete_option( $name );
			return null;
		}
		return [
			'u'   => (int) $record['u'],
			'e'   => (int) $record['e'],
			'ref' => substr( $hash, 0, 16 ),
		];
	}

	/** Ends the session of this request, if there is one, and clears its cookie unless a new cookie follows. */
	private static function revoke_session( $clear_cookie = true ) {
		$token = self::cookie_token();
		if ( null === $token ) {
			return;
		}
		delete_option( self::SESSION_PREFIX . substr( hash( 'sha256', $token ), 0, 40 ) );
		if ( $clear_cookie ) {
			self::send_cookie( '', time() - 86400 );
		}
	}

	private static function cookie_token() {
		if ( ! isset( $_COOKIE[ self::SESSION_COOKIE ] ) || ! is_string( $_COOKIE[ self::SESSION_COOKIE ] ) || ! preg_match( '/^[a-f0-9]{32}$/D', $_COOKIE[ self::SESSION_COOKIE ] ) ) {
			return null;
		}
		return $_COOKIE[ self::SESSION_COOKIE ];
	}

	/** Deletes every expired key and session record. */
	public static function purge_expired() {
		$now = time();
		foreach ( array_merge( self::records( self::KEY_PREFIX ), self::records( self::SESSION_PREFIX ) ) as $record ) {
			if ( $record['expires'] < $now ) {
				delete_option( $record['name'] );
			}
		}
	}

	/** Deletes every key and session record. Returns how many were removed. */
	public static function purge_all() {
		$removed = 0;
		foreach ( array_merge( self::records( self::KEY_PREFIX ), self::records( self::SESSION_PREFIX ) ) as $record ) {
			if ( delete_option( $record['name'] ) ) {
				++$removed;
			}
		}
		return $removed;
	}

	/**
	 * The stored records whose option name starts with $prefix.
	 *
	 * @return array[] Each array( 'name' => option name, 'expires' => timestamp ).
	 */
	private static function records( $prefix ) {
		global $wpdb;
		$out = [];
		if ( ! is_object( $wpdb ) || empty( $wpdb->options ) ) {
			return $out;
		}
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( $prefix ) . '%' ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			$record = json_decode( (string) $row['option_value'], true );
			$out[]  = [
				'name'    => (string) $row['option_name'],
				'expires' => is_array( $record ) && isset( $record['e'] ) ? (int) $record['e'] : 0,
			];
		}
		return $out;
	}

	/** An option value read straight from the database, so a name taken from a request never fills the options cache. */
	private static function stored_value( $name ) {
		global $wpdb;
		if ( ! is_object( $wpdb ) || empty( $wpdb->options ) ) {
			return null;
		}
		$value = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return is_string( $value ) ? $value : null;
	}

	private static function is_administrator( $user_id ) {
		if ( ! class_exists( 'WP_User' ) ) {
			return false;
		}
		$user = new WP_User( (int) $user_id );
		return $user->exists() && user_can( $user, 'manage_options' );
	}

	private static function encode( $value ) {
		$json = function_exists( 'wp_json_encode' ) ? wp_json_encode( $value ) : json_encode( $value );
		return is_string( $json ) ? $json : '{}';
	}

	// ------------------------------------------------------------------
	// Safe boot.
	// ------------------------------------------------------------------

	/**
	 * Redeems a key from the request, if it carries one, and puts the request into safe boot
	 * when it carries a valid session or is an eligible MCP route request.
	 *
	 * @internal
	 */
	public static function start_safe_boot() {
		if ( '' === self::$plugin || ! file_exists( self::plugin_file() ) ) {
			return;
		}
		self::redeem_from_request();
		$session = self::read_session();
		if ( null !== $session ) {
			$context = self::request_context();
			self::watch_session( $session['u'] );
			if ( self::session_applies( $context ) ) {
				self::enter( $session['u'], $session['ref'] );
				return;
			}
			if ( 'login' === $context ) {
				add_filter( 'login_message', [ __CLASS__, 'login_notice' ] );
			}
		}
		if ( self::mcp_mode_applies() ) {
			self::enter( 0, '' );
		}
	}

	private static function plugin_file() {
		$dir = defined( 'WP_PLUGIN_DIR' ) ? WP_PLUGIN_DIR : ( defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR . '/plugins' : ABSPATH . 'wp-content/plugins' );
		return $dir . '/' . self::$plugin;
	}

	/** Redeems the key of a GET request to wp-login.php, starts the session and redirects without the key. */
	private static function redeem_from_request() {
		if ( ! isset( $_GET[ self::QUERY_ARG ] ) || ! isset( $_SERVER['REQUEST_METHOD'] ) || 'GET' !== $_SERVER['REQUEST_METHOD'] || 'wp-login.php' !== self::script_name() ) {
			return;
		}
		if ( self::headers_already_sent() ) {
			// The session cookie could not be sent, so the key is left unused.
			return;
		}
		$user_id = self::redeem( $_GET[ self::QUERY_ARG ] );
		if ( $user_id < 1 || ! self::start_session( $user_id ) ) {
			add_filter( 'login_message', [ __CLASS__, 'invalid_key_notice' ] );
			return;
		}
		$query = [];
		foreach ( $_GET as $name => $value ) {
			if ( self::QUERY_ARG !== $name ) {
				$query[ $name ] = $value;
			}
		}
		$url = site_url( 'wp-login.php', 'login' );
		if ( [] !== $query ) {
			$url .= '?' . http_build_query( $query, '', '&' );
		}
		self::send_header( 'Location: ' . str_replace( [ "\r", "\n" ], '', $url ), 302 );
		self::send_header( 'Cache-Control: no-store, max-age=0' );
		self::send_header( 'Referrer-Policy: no-referrer' );
		self::stop();
	}

	/**
	 * Whether a valid session puts a request of this context in safe boot: wp-admin, and the front
	 * controller (pages and REST requests) with a logged-in cookie. Never the login page.
	 */
	private static function session_applies( $context ) {
		if ( 'admin' === $context ) {
			return true;
		}
		return ( 'rest' === $context || 'front' === $context ) && self::has_logged_in_cookie();
	}

	/**
	 * Watches the sign-in and sign-out of a browser that holds a valid session, in safe boot or not:
	 * a sign-in by another user and signing out both end the session.
	 */
	private static function watch_session( $user_id ) {
		self::$session_user = (int) $user_id;
		add_action( 'wp_login', [ __CLASS__, 'on_login' ], 0, 2 );
		add_action( 'wp_logout', [ __CLASS__, 'on_logout' ], 0 );
	}

	/** Loads this request in safe boot. */
	private static function enter( $user_id, $session_ref ) {
		self::$safe        = true;
		self::$safe_user   = (int) $user_id;
		self::$session_ref = (string) $session_ref;
		if ( ! defined( 'STONEWRIGHT_RESCUE_SAFE_BOOT' ) ) {
			define( 'STONEWRIGHT_RESCUE_SAFE_BOOT', true );
		}
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		if ( ! self::headers_already_sent() && function_exists( 'nocache_headers' ) ) {
			nocache_headers();
		}

		$late = PHP_INT_MAX;
		add_filter( 'option_active_plugins', [ __CLASS__, 'only_plugin_list' ], $late );
		add_filter( 'option_active_sitewide_plugins', [ __CLASS__, 'only_plugin_map' ], $late );
		add_filter( 'site_option_active_sitewide_plugins', [ __CLASS__, 'only_plugin_map' ], $late );
		add_filter( 'template', [ __CLASS__, 'safe_theme' ], $late );
		add_filter( 'stylesheet', [ __CLASS__, 'safe_theme' ], $late );
		add_filter( 'validate_current_theme', '__return_false', $late );
		foreach ( [ 'active_plugins', 'active_sitewide_plugins', 'template', 'stylesheet', 'current_theme' ] as $option ) {
			add_filter( 'pre_update_option_' . $option, [ __CLASS__, 'keep_stored_value' ], $late, 2 );
		}
		add_filter( 'pre_update_site_option_active_sitewide_plugins', [ __CLASS__, 'keep_stored_value' ], $late, 2 );

		if ( $user_id > 0 ) {
			add_action( 'set_current_user', [ __CLASS__, 'on_set_current_user' ], 0 );
			add_action( 'admin_init', [ __CLASS__, 'maybe_exit' ], 0 );
			add_action( 'admin_notices', [ __CLASS__, 'render_notice' ] );
			add_action( 'network_admin_notices', [ __CLASS__, 'render_notice' ] );
		}
	}

	/** Filter for the stored list of active plugins: Stonewright only. */
	public static function only_plugin_list( $plugins ) {
		if ( self::$stored_selection ) {
			return $plugins;
		}
		return is_array( $plugins ) && in_array( self::$plugin, $plugins, true ) ? [ self::$plugin ] : [];
	}

	/** Filter for the network list of active plugins (a map from basename to time): Stonewright only. */
	public static function only_plugin_map( $plugins ) {
		if ( self::$stored_selection ) {
			return $plugins;
		}
		return is_array( $plugins ) && isset( $plugins[ self::$plugin ] ) ? [ self::$plugin => $plugins[ self::$plugin ] ] : [];
	}

	/** Filter for template and stylesheet: the default theme, or a theme that does not exist. */
	public static function safe_theme( $theme ) {
		if ( self::$stored_selection ) {
			return $theme;
		}
		if ( null === self::$theme ) {
			self::$theme = self::default_theme();
		}
		return self::$theme;
	}

	private static function default_theme() {
		$root       = ( defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR : ABSPATH . 'wp-content' ) . '/themes/';
		$candidates = [ 'twentytwentyfive', 'twentytwentyfour', 'twentytwentythree', 'twentytwentytwo', 'twentytwentyone', 'twentytwenty', 'twentynineteen' ];
		if ( defined( 'WP_DEFAULT_THEME' ) && is_string( WP_DEFAULT_THEME ) ) {
			array_unshift( $candidates, WP_DEFAULT_THEME );
		}
		foreach ( $candidates as $slug ) {
			if ( preg_match( '/^[a-z0-9_-]+$/D', $slug ) && is_file( $root . $slug . '/style.css' ) && ( is_file( $root . $slug . '/index.php' ) || is_file( $root . $slug . '/templates/index.html' ) ) ) {
				return $slug;
			}
		}
		return self::NO_THEME;
	}

	/** Filter for pre_update_option: the value stays what it was unless with_stored_selection() is running. */
	public static function keep_stored_value( $value, $old_value ) {
		return self::$stored_selection ? $value : $old_value;
	}

	/**
	 * Runs $callback with the stored plugin and theme selection in place of the safe boot one:
	 * reads see the real active plugins and theme, and writes to those options are saved. It is
	 * for code that changes the selection on purpose, such as a rollback that activates or
	 * deactivates a plugin or restores the theme options: its read, change and write then work
	 * on the real list, never on the filtered one.
	 *
	 * @param callable $callback Code to run.
	 * @return mixed The callback's result.
	 */
	public static function with_stored_selection( $callback ) {
		$before                 = self::$stored_selection;
		self::$stored_selection = true;
		try {
			return call_user_func( $callback );
		} finally {
			self::$stored_selection = $before;
		}
	}

	/** Action for set_current_user: a current user other than the session's administrator ends the session. */
	public static function on_set_current_user() {
		$current = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
		if ( $current < 1 || ( $current === self::$safe_user && self::is_administrator( $current ) ) ) {
			return;
		}
		self::revoke_session();
		wp_die( esc_html__( 'Stonewright safe mode ended because this browser is signed in as a different user. Reload the page.', 'stonewright' ), '', [ 'response' => 403 ] );
	}

	/**
	 * Action for wp_login: a sign-in by anyone but the session's administrator ends the session. The
	 * login page loads with every plugin, so this runs after the site's own login checks have passed.
	 */
	public static function on_login( $user_login = '', $user = null ) {
		if ( $user instanceof WP_User && self::$session_user > 0 && (int) $user->ID === self::$session_user ) {
			return;
		}
		self::revoke_session();
	}

	/** Action for wp_logout: signing out ends safe boot. */
	public static function on_logout() {
		self::revoke_session();
	}

	/** Action for admin_init: the exit link of the safe boot notice. */
	public static function maybe_exit() {
		if ( ! isset( $_GET[ self::EXIT_ARG ], $_GET['_wpnonce'] ) || ! is_string( $_GET['_wpnonce'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( $_GET['_wpnonce'], self::EXIT_NONCE ) ) {
			return;
		}
		self::revoke_session();
		self::send_header( 'Location: ' . str_replace( [ "\r", "\n" ], '', admin_url() ), 302 );
		self::stop();
	}

	/** Action for admin_notices: the safe boot notice. */
	public static function render_notice() {
		echo '<div class="notice notice-warning"><p><strong>' . esc_html__( 'Stonewright safe mode.', 'stonewright' ) . '</strong> '
			. esc_html__( 'Only Stonewright is active and the default theme is in use for this browser. Plugin and theme changes are paused.', 'stonewright' )
			. ' <a href="' . esc_url( self::exit_url() ) . '">' . esc_html__( 'Leave safe mode', 'stonewright' ) . '</a></p></div>';
	}

	/** Filter for login_message: tells the administrator what happens after sign-in. */
	public static function login_notice( $message ) {
		return $message . '<p class="message">' . esc_html__( 'A Stonewright rescue link was opened in this browser. Sign in as the administrator it was issued for: Stonewright safe mode starts after you sign in.', 'stonewright' ) . '</p>';
	}

	/** Filter for login_message: a rescue link that cannot be used. */
	public static function invalid_key_notice( $message ) {
		return $message . '<div id="login_error">' . esc_html__( 'This Stonewright rescue link is not valid. It may have been used already or have expired.', 'stonewright' ) . '</div>';
	}

	/** The URL that ends safe boot, or an empty string outside safe boot. */
	public static function exit_url() {
		if ( ! self::$safe || ! function_exists( 'wp_nonce_url' ) ) {
			return '';
		}
		return wp_nonce_url( add_query_arg( self::EXIT_ARG, '1', admin_url() ), self::EXIT_NONCE );
	}

	/** Whether this request is loaded in safe boot. */
	public static function is_safe_boot() {
		return self::$safe;
	}

	/** The administrator of the safe boot session, or 0 (MCP route mode). */
	public static function safe_boot_user() {
		return self::$safe_user;
	}

	/** A short identifier of the safe boot session that cannot be turned back into its cookie. */
	public static function session_ref() {
		return self::$session_ref;
	}

	/** Whether the Stonewright plugin basename this copy works with is known. */
	public static function plugin_basename() {
		return self::$plugin;
	}

	// ------------------------------------------------------------------
	// Optional MCP route mode.
	// ------------------------------------------------------------------

	/**
	 * Whether this request is loaded in safe boot by the MCP route mode: a REST request that
	 * WordPress serves through the front controller, for an MCP route, with the credential that
	 * route accepts, while the setting is on and the journal holds an open incident.
	 */
	private static function mcp_mode_applies() {
		if ( ! self::has_any_authorization() || 'rest' !== self::request_context() ) {
			return false;
		}
		$scheme = self::mcp_route_scheme();
		if ( '' === $scheme || ! self::has_authorization( $scheme ) ) {
			return false;
		}
		$setting = get_option( self::OPTION_MCP, '' );
		if ( ! in_array( $setting, [ '1', 1, true, 'yes', 'on' ], true ) ) {
			return false;
		}
		return self::has_open_incident();
	}

	/** "basic" for the Application Password route, "bearer" for the OAuth route, "" for any other request. */
	private static function mcp_route_scheme() {
		$route = self::rest_route();
		if ( null === $route || '/' !== substr( $route, 0, 1 ) ) {
			return '';
		}
		$route = '/' . trim( strtolower( $route ), '/' );
		if ( '/mcp/stonewright-oauth' === $route || 0 === strpos( $route, '/mcp/stonewright-oauth/' ) ) {
			return 'bearer';
		}
		if ( '/mcp/stonewright' === $route || 0 === strpos( $route, '/mcp/stonewright/' ) ) {
			return 'basic';
		}
		return '';
	}

	/**
	 * The REST route that WordPress will serve for this request, as the request wrote it, or null when
	 * the request is no REST request. Only the front controller, index.php, serves REST requests.
	 * WordPress reads the route from the rest_route form field, then from the rest_route query
	 * parameter, and without either from the part of the path behind the REST base.
	 */
	private static function rest_route() {
		if ( 'index.php' !== self::script_name() || false !== stripos( self::script_path(), '/wp-admin/' ) ) {
			return null;
		}
		foreach ( [ $_POST, $_GET ] as $fields ) {
			if ( isset( $fields['rest_route'] ) ) {
				return is_string( $fields['rest_route'] ) && '' !== $fields['rest_route'] ? $fields['rest_route'] : null;
			}
		}
		return self::route_from_path();
	}

	/**
	 * The route behind the REST base when the request path starts with it, after the home path of the
	 * site and an optional index.php, or null. The REST base elsewhere in the path does not count.
	 */
	private static function route_from_path() {
		if ( empty( $_SERVER['REQUEST_URI'] ) || ! is_string( $_SERVER['REQUEST_URI'] ) ) {
			return null;
		}
		$parts = explode( '?', $_SERVER['REQUEST_URI'], 2 );
		$path  = trim( (string) preg_replace( '#/+#', '/', rawurldecode( $parts[0] ) ), '/' );
		$home  = trim( (string) parse_url( self::home_address(), PHP_URL_PATH ), '/' );
		if ( '' !== $home ) {
			if ( 0 !== stripos( $path . '/', $home . '/' ) ) {
				return null;
			}
			$path = ltrim( (string) substr( $path, strlen( $home ) ), '/' );
		}
		if ( 0 === strpos( $path . '/', 'index.php/' ) ) {
			$path = ltrim( (string) substr( $path, 9 ), '/' );
		}
		$base = self::rest_base();
		if ( $path === $base ) {
			return '/';
		}
		return 0 === strpos( $path, $base . '/' ) ? '/' . substr( $path, strlen( $base ) + 1 ) : null;
	}

	/** The REST base: wp-json, unless a filter that is registered by now changes it. */
	private static function rest_base() {
		$base = function_exists( 'rest_get_url_prefix' ) ? rest_get_url_prefix() : 'wp-json';
		$base = is_string( $base ) ? trim( $base, '/' ) : '';
		return '' === $base ? 'wp-json' : $base;
	}

	private static function home_address() {
		if ( defined( 'WP_HOME' ) && is_string( WP_HOME ) && '' !== WP_HOME ) {
			return WP_HOME;
		}
		$home = get_option( 'home', '' );
		return is_string( $home ) ? $home : '';
	}

	/** Whether the request carries any Authorization credential at all. */
	private static function has_any_authorization() {
		foreach ( [ 'HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION', 'PHP_AUTH_USER' ] as $name ) {
			if ( ! empty( $_SERVER[ $name ] ) ) {
				return true;
			}
		}
		return false;
	}

	private static function has_authorization( $scheme ) {
		foreach ( [ 'HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION' ] as $name ) {
			if ( isset( $_SERVER[ $name ] ) && is_string( $_SERVER[ $name ] ) && preg_match( '/^' . $scheme . '\s+\S/iD', trim( $_SERVER[ $name ] ) ) ) {
				return true;
			}
		}
		return 'basic' === $scheme && ! empty( $_SERVER['PHP_AUTH_USER'] );
	}

	// ------------------------------------------------------------------
	// Request helpers and output seams.
	// ------------------------------------------------------------------

	/**
	 * The kind of request, from the script the web server started: "login" (wp-login.php), "admin"
	 * (anything under wp-admin, admin-ajax.php included), "rest" or "front" (index.php, the front
	 * controller, for a REST request or a page), or "other" (every other entry script: cron, XML-RPC,
	 * comments, sign-up, trackbacks, mail, and the scripts of plugins and themes).
	 */
	private static function request_context() {
		$name = self::script_name();
		if ( 'wp-login.php' === $name ) {
			return 'login';
		}
		if ( false !== stripos( self::script_path(), '/wp-admin/' ) ) {
			return 'admin';
		}
		if ( 'index.php' !== $name ) {
			return 'other';
		}
		return null === self::rest_route() ? 'front' : 'rest';
	}

	/** The path of the script the web server started, with forward slashes and a leading slash. */
	private static function script_path() {
		$file = '';
		if ( ! empty( $_SERVER['SCRIPT_FILENAME'] ) ) {
			$file = (string) $_SERVER['SCRIPT_FILENAME'];
		} elseif ( ! empty( $_SERVER['SCRIPT_NAME'] ) ) {
			$file = (string) $_SERVER['SCRIPT_NAME'];
		}
		return '/' . ltrim( str_replace( '\\', '/', $file ), '/' );
	}

	private static function script_name() {
		return basename( self::script_path() );
	}

	private static function has_logged_in_cookie() {
		foreach ( array_keys( $_COOKIE ) as $name ) {
			if ( 0 === strpos( (string) $name, 'wordpress_logged_in_' ) ) {
				return true;
			}
		}
		return false;
	}

	/** Sends a header. A test can take it over through $GLOBALS['stonewright_rescue_io']['header']. */
	private static function send_header( $line, $status = 0 ) {
		if ( isset( $GLOBALS['stonewright_rescue_io']['header'] ) ) {
			call_user_func( $GLOBALS['stonewright_rescue_io']['header'], $line, $status );
			return;
		}
		if ( $status > 0 ) {
			header( $line, true, $status );
		} else {
			header( $line );
		}
	}

	/** Sends the session cookie, or clears it with an empty value. */
	private static function send_cookie( $value, $expires ) {
		$ssl    = isset( $GLOBALS['stonewright_rescue_io']['ssl'] ) ? (bool) call_user_func( $GLOBALS['stonewright_rescue_io']['ssl'] ) : ( function_exists( 'is_ssl' ) && is_ssl() );
		$secure = $ssl || 0 === strpos( strtolower( (string) get_option( 'home', '' ) ), 'https://' );
		$path   = defined( 'COOKIEPATH' ) ? COOKIEPATH : (string) preg_replace( '|https?://[^/]+|i', '', get_option( 'home', '' ) . '/' );
		$domain = defined( 'COOKIE_DOMAIN' ) && is_string( COOKIE_DOMAIN ) ? COOKIE_DOMAIN : '';
		$path   = '' === $path ? '/' : $path;
		if ( isset( $GLOBALS['stonewright_rescue_io']['cookie'] ) ) {
			return (bool) call_user_func(
				$GLOBALS['stonewright_rescue_io']['cookie'],
				self::SESSION_COOKIE,
				$value,
				[
					'expires'  => $expires,
					'path'     => $path,
					'domain'   => $domain,
					'secure'   => $secure,
					'httponly' => true,
					'samesite' => 'Lax',
				]
			);
		}
		if ( self::headers_already_sent() ) {
			return false;
		}
		if ( PHP_VERSION_ID >= 70300 ) {
			return setcookie(
				self::SESSION_COOKIE,
				$value,
				[
					'expires'  => $expires,
					'path'     => $path,
					'domain'   => $domain,
					'secure'   => $secure,
					'httponly' => true,
					'samesite' => 'Lax',
				]
			);
		}
		return setcookie( self::SESSION_COOKIE, $value, $expires, $path . '; samesite=Lax', $domain, $secure, true );
	}

	private static function headers_already_sent() {
		if ( isset( $GLOBALS['stonewright_rescue_io']['headers_sent'] ) ) {
			return (bool) call_user_func( $GLOBALS['stonewright_rescue_io']['headers_sent'] );
		}
		return headers_sent();
	}

	private static function stop() {
		if ( isset( $GLOBALS['stonewright_rescue_io']['exit'] ) ) {
			call_user_func( $GLOBALS['stonewright_rescue_io']['exit'] );
			return;
		}
		exit;
	}
}

// phpcs:enable WordPress.Security.NonceVerification.Recommended,WordPress.Security.NonceVerification.Missing

endif;

Stonewright_Rescue::boot();

/* STONEWRIGHT_RESCUE_MU_END */
