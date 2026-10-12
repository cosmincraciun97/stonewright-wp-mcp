<?php
/**
 * The wp stonewright rescue command.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Cli;

use Stonewright\WpMcp\Security\ChangeJournal;
use Stonewright\WpMcp\Security\ConfirmationToken;
use Stonewright\WpMcp\Security\Permissions;
use Stonewright\WpMcp\Security\RescueRollback;

/**
 * Lists open rescue incidents and rolls one back from the command line.
 *
 * It needs no theme and no plugin other than Stonewright, so it works on a site that is broken by
 * another plugin or by the theme:
 *
 *     wp stonewright rescue status --user=<admin> --skip-plugins=<all but stonewright> --skip-themes
 *
 * Both subcommands need an administrator (--user). The rollback is the same one the
 * stonewright/rescue-rollback ability and the Rescue page run, and it is held to the same rules: it needs
 * manage_options, in production-safe mode it needs a confirmation token issued for exactly this incident,
 * it probes the site afterwards, and the outcome is recorded on the change set and in the audit log.
 *
 * The output goes through the protected methods line(), success(), warning(), fail() and items(), so a
 * test can replace WP-CLI.
 */
class RescueCommand {

	/** The ability a confirmation token for this rollback is issued for. */
	public const ABILITY_ROLLBACK = 'stonewright/rescue-rollback';

	/** Exit code of a rollback that needs a confirmation token. */
	public const EXIT_APPROVAL = 2;

	/** Environment variable the companion passes the token in, so it never appears in a process list. */
	public const TOKEN_ENV = 'STONEWRIGHT_CONFIRMATION_TOKEN';

	/** Seconds a confirmation token issued here is valid. */
	private const TOKEN_TTL = 300;

	private const FORMATS = [ 'table', 'json', 'csv', 'yaml', 'count' ];

	private const FIELDS = [ 'id', 'ability', 'resource', 'state', 'recorded_at', 'file' ];

	/**
	 * Runs the rollback: callable( string $incident_id, array $options ): array|WP_Error. Replaced in
	 * tests; by default RescueRollback::run().
	 *
	 * @var (callable(string, array<string, mixed>): mixed)|null
	 */
	public static $rollback_runner = null;

	/**
	 * Asks what a rollback would refuse before it saves, writes or claims anything: callable( string $incident_id, array $options ): ?WP_Error.
	 * Replaced in tests; by default RescueRollback::preflight_refusal().
	 *
	 * @var (callable(string, array<string, mixed>): mixed)|null
	 */
	public static $preflight_runner = null;

	/**
	 * Records the outcome in the audit log: callable( string $id, mixed $result, string $by, string $action ).
	 * Replaced in tests; by default RescueRollback::audit_outcome().
	 *
	 * @var (callable(string, mixed, string, string): void)|null
	 */
	public static $audit_runner = null;

	/**
	 * Returns the open incidents as journal entries. Replaced in tests.
	 *
	 * @var (callable(): list<array<string, mixed>>)|null
	 */
	public static $incident_source = null;

	/**
	 * Show the open rescue incidents.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Render the incidents in a format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 *   - yaml
	 *   - count
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp stonewright rescue status --user=admin
	 *     wp stonewright rescue status --user=admin --skip-plugins=other-plugin --skip-themes --format=json
	 *
	 * @param list<string>         $args       Positional arguments (none).
	 * @param array<string, mixed> $assoc_args Named arguments.
	 */
	public function status( array $args, array $assoc_args ): void {
		unset( $args );
		$this->require_administrator();
		$format = $this->format( $assoc_args );
		try {
			$rows = array_map( [ self::class, 'row' ], self::incidents() );
		} catch ( \Throwable $failure ) {
			unset( $failure );
			$this->fail( 'The rescue incidents could not be read.' );
		}
		if ( [] === $rows && 'table' === $format ) {
			$this->line( 'No open rescue incidents.' );
			return;
		}
		$this->items( $rows, self::FIELDS, $format );
	}

	/**
	 * Roll back an open rescue incident.
	 *
	 * Runs the recorded rollback for the incident, probes the site and records the outcome. A rollback over an
	 * edit made after the change is refused (stonewright_change_drift) until it is run again with --force-drift and
	 * --expected-sha256 set to the current_sha256 of the dry run (`wp stonewright changes rollback <change> --dry-run`
	 * prints it). In production-safe mode it needs a confirmation token: run the command with --issue-token (and the same
	 * --force-drift and --expected-sha256), then run it again with --confirmation-token. Exit code 2 means a token is
	 * required. The command line never approves a change to code.
	 *
	 * ## OPTIONS
	 *
	 * <incident>
	 * : The incident id shown by `wp stonewright rescue status`.
	 *
	 * [--confirmation-token=<token>]
	 * : The token to confirm the rollback in production-safe mode. When the option is not given, the
	 * STONEWRIGHT_CONFIRMATION_TOKEN environment variable is used.
	 *
	 * [--force-drift]
	 * : Roll back even though the item was edited after the change, overwriting those edits. Needs --expected-sha256.
	 *
	 * [--expected-sha256=<hash>]
	 * : The current_sha256 of the dry run, 32 to 64 hexadecimal characters. The rollback stops when the item is not in that state any more.
	 *
	 * [--issue-token]
	 * : Print a confirmation token for this rollback (and these options) and stop. Nothing is rolled back.
	 *
	 * [--format=<format>]
	 * : Print the result as text or as JSON.
	 * ---
	 * default: text
	 * options:
	 *   - text
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp stonewright rescue rollback cs-0123456789abcdef01234567 --user=admin
	 *     wp stonewright rescue rollback cs-0123456789abcdef01234567 --user=admin --issue-token
	 *     wp stonewright rescue rollback cs-0123456789abcdef01234567 --user=admin --force-drift --expected-sha256=<hash>
	 *     wp stonewright rescue rollback cs-0123456789abcdef01234567 --user=admin --confirmation-token=<token>
	 *
	 * @param list<string>         $args       The incident id.
	 * @param array<string, mixed> $assoc_args Named arguments.
	 */
	public function rollback( array $args, array $assoc_args ): void {
		$this->require_administrator();
		$id = isset( $args[0] ) && is_string( $args[0] ) ? $args[0] : '';
		if ( 1 !== preg_match( '/^[A-Za-z0-9][A-Za-z0-9._:-]{0,95}$/D', $id ) ) {
			$this->fail( 'Give the id of an open incident. List them with: wp stonewright rescue status' );
		}
		$json = 'json' === ( $assoc_args['format'] ?? 'text' );
		$hash = isset( $assoc_args['expected-sha256'] ) && is_string( $assoc_args['expected-sha256'] ) ? $assoc_args['expected-sha256'] : '';
		if ( '' !== $hash && 1 !== preg_match( '/^[a-fA-F0-9]{32,64}$/D', $hash ) ) {
			$this->fail( '--expected-sha256 must be the current_sha256 of the dry run: 32 to 64 hexadecimal characters.' );
		}
		$force   = ! empty( $assoc_args['force-drift'] );
		$hash    = strtolower( $hash );
		$signed  = [ 'incident_id' => $id ];
		$options = [ 'by' => 'wp-cli', 'user_id' => (int) get_current_user_id(), 'force_drift' => $force, 'expected_current_sha256' => $hash ];
		if ( $force ) {
			$signed['force_drift'] = true;
		}
		if ( '' !== $hash ) {
			$signed['expected_current_sha256'] = $hash;
		}

		if ( ! empty( $assoc_args['issue-token'] ) ) {
			$token = ConfirmationToken::issue( self::ABILITY_ROLLBACK, $signed, self::TOKEN_TTL );
			$this->line( $json ? (string) wp_json_encode( [ 'confirmation_token' => $token, 'expires_in' => self::TOKEN_TTL ] ) : $token );
			return;
		}
		// A refusal that needs no token is answered before one is looked at, so it does not use one up.
		$refusal = self::preflight( $id, $options );
		if ( is_wp_error( $refusal ) ) {
			$this->fail( self::refusal_text( $refusal ) );
		}
		$this->require_confirmation( $id, $signed, $assoc_args );

		try {
			$result = self::run_rollback( $id, $options );
		} catch ( \Throwable $failure ) {
			unset( $failure );
			$this->fail( 'The rollback stopped on an error. Check the Stonewright audit log.' );
		}
		self::audit( $id, $result );

		if ( is_wp_error( $result ) ) {
			$this->fail( self::refusal_text( $result ) );
		}
		if ( ! is_array( $result ) ) {
			$this->fail( 'The rollback returned an answer that is not understood.' );
		}
		if ( false === ( $result['ok'] ?? null ) ) {
			$this->fail( sprintf( 'The rollback did not work: %s', self::reason( $result ) ) );
		}
		$site = (string) ( $result['site_status'] ?? 'unknown' );
		if ( 'still_failing' === $site ) {
			$this->fail( sprintf( 'Rolled back %s, but the site still fails to load. The fault may not come from this change.', $id ) );
		}
		if ( $json ) {
			$this->line( (string) wp_json_encode( $result ) );
			return;
		}
		if ( 'healthy' === $site ) {
			$this->success( sprintf( 'Rolled back %s. The site loads again.', $id ) );
			return;
		}
		$this->warning( 'The health check afterwards was unavailable, so recovery is not confirmed.' );
		$this->success( sprintf( 'Rolled back %s.', $id ) );
	}

	// -----------------------------------------------------------------------
	// Output. WP-CLI in production; a recorder in tests.
	// -----------------------------------------------------------------------

	protected function line( string $text ): void {
		self::cli( 'line', $text );
	}

	protected function success( string $text ): void {
		self::cli( 'success', $text );
	}

	protected function warning( string $text ): void {
		self::cli( 'warning', $text );
	}

	protected function fail( string $text, int $code = 1 ): never {
		self::cli( 'error', $text, $code );
		throw new \RuntimeException( $text, $code );
	}

	/**
	 * @param list<array<string, mixed>> $rows
	 * @param list<string>               $fields
	 */
	protected function items( array $rows, array $fields, string $format ): void {
		$format_items = 'WP_CLI\Utils\format_items';
		$format_items( $format, $rows, $fields ); // @phpstan-ignore-line -- Exists only while WP-CLI runs.
	}

	// -----------------------------------------------------------------------
	// Internals.
	// -----------------------------------------------------------------------

	private function require_administrator(): void {
		if ( get_current_user_id() < 1 ) {
			$this->fail( 'Run this command as an administrator: pass --user=<login or ID>.' );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			$this->fail( 'This user cannot manage options. Pass --user=<administrator login or ID>.' );
		}
	}

	/**
	 * In production-safe mode the rollback needs a token issued for this incident and this user.
	 *
	 * @param array<string, mixed> $signed     What the token is signed over.
	 * @param array<string, mixed> $assoc_args Named arguments.
	 */
	private function require_confirmation( string $id, array $signed, array $assoc_args ): void {
		if ( ! Permissions::is_production_safe() ) {
			return;
		}
		$token = isset( $assoc_args['confirmation-token'] ) && is_string( $assoc_args['confirmation-token'] ) ? $assoc_args['confirmation-token'] : (string) getenv( self::TOKEN_ENV );
		$ok    = '' !== $token && true === ConfirmationToken::verify_or_error( $token, self::ABILITY_ROLLBACK, $signed );
		if ( $ok ) {
			return;
		}
		$this->fail(
			sprintf( 'Production-safe mode needs a valid confirmation token. Run: wp stonewright rescue rollback %1$s --issue-token (it prints a token valid for %2$d minutes), then run the rollback again with --confirmation-token=<token>.', $id, intdiv( self::TOKEN_TTL, 60 ) ),
			self::EXIT_APPROVAL
		);
	}

	/** @param array<string, mixed> $assoc_args */
	private function format( array $assoc_args ): string {
		$format = isset( $assoc_args['format'] ) && is_string( $assoc_args['format'] ) ? $assoc_args['format'] : 'table';
		if ( ! in_array( $format, self::FORMATS, true ) ) {
			$this->fail( 'Unknown format. Use one of: ' . implode( ', ', self::FORMATS ) . '.' );
		}
		return $format;
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private static function incidents(): array {
		if ( null !== self::$incident_source ) {
			return ( self::$incident_source )();
		}
		ChangeJournal::sync_from_file();
		return ChangeJournal::open_incidents();
	}

	/**
	 * @param array<string, mixed> $entry A journal entry.
	 * @return array<string, mixed>
	 */
	private static function row( array $entry ): array {
		$incident = is_array( $entry['incident'] ?? null ) ? $entry['incident'] : [];
		$time     = (int) ( $incident['recorded_at'] ?? 0 );
		$time     = $time > 0 ? $time : (int) ( $entry['settled_at'] ?? 0 );
		$time     = $time > 0 ? $time : (int) ( $entry['armed_at'] ?? 0 );
		return [
			'id'          => (string) ( $entry['id'] ?? '' ),
			'ability'     => (string) ( $entry['ability'] ?? '' ),
			'resource'    => (string) ( $entry['resource_key'] ?? '' ),
			'state'       => (string) ( $entry['state'] ?? '' ),
			'recorded_at' => gmdate( 'Y-m-d\TH:i:s\Z', max( 0, $time ) ),
			'file'        => (string) ( $incident['file'] ?? '' ),
		];
	}

	/**
	 * @param array<string, mixed> $options
	 */
	private static function run_rollback( string $id, array $options ): mixed {
		if ( null !== self::$rollback_runner ) {
			return ( self::$rollback_runner )( $id, $options );
		}
		return RescueRollback::run( $id, $options );
	}

	/**
	 * The words for an error of the rollback. A refusal that names a state to confirm says how to confirm it.
	 */
	private static function refusal_text( \WP_Error $error ): string {
		$code = (string) $error->get_error_code();
		$text = sprintf( '%1$s (%2$s)', $error->get_error_message(), $code );
		$data = is_array( $error->get_error_data() ) ? $error->get_error_data() : [];
		$hash = isset( $data['current_sha256'] ) && is_string( $data['current_sha256'] ) && 1 === preg_match( '/^[a-f0-9]{64}$/D', $data['current_sha256'] ) ? $data['current_sha256'] : '';
		if ( '' !== $hash && in_array( $code, [ 'stonewright_change_drift', 'stonewright_change_drift_hash_required' ], true ) ) {
			$text .= sprintf( ' The item is now %1$s. To overwrite the later edit, run again with --force-drift --expected-sha256=%1$s.', $hash );
		}
		return $text;
	}

	/**
	 * @param array<string, mixed> $options
	 */
	private static function preflight( string $id, array $options ): mixed {
		try {
			if ( null !== self::$preflight_runner ) {
				return ( self::$preflight_runner )( $id, $options );
			}
			return RescueRollback::preflight_refusal( $id, $options );
		} catch ( \Throwable $failure ) {
			unset( $failure );
			return null;
		}
	}

	private static function audit( string $id, mixed $result ): void {
		if ( ! is_array( $result ) && ! is_wp_error( $result ) ) {
			return;
		}
		try {
			if ( null !== self::$audit_runner ) {
				( self::$audit_runner )( $id, $result, 'wp-cli', 'rollback' );
				return;
			}
			RescueRollback::audit_outcome( $id, $result, 'wp-cli', 'rollback' );
		} catch ( \Throwable $failure ) {
			unset( $failure );
		}
	}

	/**
	 * A short reason from a failed rollback result.
	 *
	 * @param array<string, mixed> $result
	 */
	private static function reason( array $result ): string {
		foreach ( [ 'detail', 'error', 'message', 'rollback_status' ] as $key ) {
			if ( isset( $result[ $key ] ) && is_scalar( $result[ $key ] ) && '' !== trim( (string) $result[ $key ] ) ) {
				return substr( trim( (string) $result[ $key ] ), 0, 200 );
			}
		}
		return 'no detail was returned';
	}

	/**
	 * Calls a static method of WP_CLI. WP_CLI is not a dependency of the plugin: it exists only
	 * when WP-CLI runs this command.
	 */
	private static function cli( string $method, mixed ...$arguments ): void {
		$class = 'WP_CLI';
		$class::$method( ...$arguments );
	}
}
