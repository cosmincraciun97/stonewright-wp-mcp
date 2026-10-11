<?php
/**
 * The wp stonewright changes command.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Cli;

use Stonewright\WpMcp\Security\ChangeLedger;
use Stonewright\WpMcp\Security\ChangeRollback;
use Stonewright\WpMcp\Security\ConfirmationToken;
use Stonewright\WpMcp\Security\Permissions;
use Stonewright\WpMcp\Support\ChangeHistoryView;
use Stonewright\WpMcp\Support\Diff\ChangeDiff;
use Stonewright\WpMcp\Support\Diff\DiffText;

/**
 * Lists the change history, shows the diff of a change and undoes or redoes one from the command line.
 *
 * The three subcommands:
 *
 *     wp stonewright changes list --user=<admin>
 *     wp stonewright changes diff <change-id> --user=<admin>
 *     wp stonewright changes rollback <change-id> --user=<admin> --dry-run
 *
 * All three need an administrator (--user). The rollback is the one the stonewright/change-rollback ability and the Changes page
 * run, held to the same rules: manage_options, drift (refused unless --force-drift), in production-safe mode a confirmation
 * token issued for exactly this change and these options (the engine verifies it, once), the health probe afterwards and the
 * audit row, which says the way was wp-cli.
 *
 * The command line is not a person pressing Undo. A change to code (theme files, custom code, sandbox files, the Customizer
 * CSS) is not undone from here: the command prints the answer of the engine and the address of the Changes page, where an
 * administrator at wp-admin gives the approval.
 *
 * The output goes through the protected methods line(), success(), warning(), fail(), items() and confirm(), so a test can
 * replace WP-CLI.
 */
class ChangesCommand {

	/** The ability a confirmation token for a rollback is issued for. */
	public const ABILITY = ChangeRollback::ABILITY;

	/** Exit code of a rollback that needs a confirmation token. */
	public const EXIT_APPROVAL = 2;

	/** Environment variable the companion passes the token in, so it never appears in a process list. */
	public const TOKEN_ENV = 'STONEWRIGHT_CONFIRMATION_TOKEN';

	/** Seconds a confirmation token issued here is valid. */
	private const TOKEN_TTL = 300;

	private const FORMATS = [ 'table', 'json', 'csv', 'yaml', 'count' ];

	private const FIELDS = [ 'change_id', 'time', 'kind', 'family', 'resource', 'ability', 'status', 'restorable', 'summary' ];

	/** Filters that are given as text and passed on as they are. */
	private const TEXT_FILTERS = [ 'family', 'resource', 'ability', 'actor', 'status', 'from', 'to', 'kind' ];

	/**
	 * Runs the rollback: callable( string $change_id, array $options ): array|WP_Error. Replaced in tests; by default
	 * ChangeRollback::run().
	 *
	 * @var (callable(string, array<string, mixed>): mixed)|null
	 */
	public static $rollback_runner = null;

	/**
	 * List the changes of the history, newest first.
	 *
	 * ## OPTIONS
	 *
	 * [--family=<family>]
	 * : Only changes of this family, for example post, elementor, theme_file, option, user.
	 *
	 * [--resource=<id>]
	 * : Only changes of this resource: a post id, an option name, a file path.
	 *
	 * [--ability=<ability>]
	 * : Only changes made by this ability, with or without the stonewright/ prefix.
	 *
	 * [--actor=<user>]
	 * : Only changes made by this user (id or login).
	 *
	 * [--status=<status>]
	 * : One of verified, rolled_back, incident, failed, unchecked.
	 *
	 * [--from=<date>]
	 * : First day, YYYY-MM-DD in UTC.
	 *
	 * [--to=<date>]
	 * : Last day, YYYY-MM-DD in UTC.
	 *
	 * [--restorable[=<bool>]]
	 * : Only changes that can be undone (or, with =false, the ones that cannot).
	 *
	 * [--kind=<kind>]
	 * : One of change, rollback, redo, restore_point.
	 *
	 * [--page=<n>]
	 * : The page, from 1.
	 *
	 * [--per-page=<n>]
	 * : Changes per page, 1 to 100 (default 25).
	 *
	 * [--format=<format>]
	 * : Render the changes in a format.
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
	 *     wp stonewright changes list --user=admin
	 *     wp stonewright changes list --user=admin --family=theme_file --restorable --format=json
	 *
	 * @subcommand list
	 *
	 * @param list<string>         $args       Positional arguments (none).
	 * @param array<string, mixed> $assoc_args Named arguments.
	 */
	public function list_( array $args, array $assoc_args ): void {
		unset( $args );
		$this->require_administrator();
		$format = $this->format( $assoc_args, self::FORMATS, 'table' );

		$input = [];
		foreach ( self::TEXT_FILTERS as $key ) {
			if ( isset( $assoc_args[ $key ] ) && is_string( $assoc_args[ $key ] ) ) {
				$input[ $key ] = $assoc_args[ $key ];
			}
		}
		if ( array_key_exists( 'restorable', $assoc_args ) ) {
			$input['restorable'] = $this->flag_value( $assoc_args['restorable'], 'restorable' );
		}
		if ( isset( $assoc_args['page'] ) ) {
			$input['page'] = $assoc_args['page'];
		}
		if ( isset( $assoc_args['per-page'] ) ) {
			$input['per_page'] = $assoc_args['per-page'];
		}
		$parsed = ChangeHistoryView::list_input( $input );
		if ( $parsed instanceof \WP_Error ) {
			$this->fail( $parsed->get_error_message() );
		}

		try {
			$list = ChangeLedger::list( $parsed['filters'], $parsed['per_page'], $parsed['page'] );
			$rows = array_map( [ self::class, 'row' ], $list['items'] );
		} catch ( \Throwable $failure ) {
			unset( $failure );
			$this->fail( 'The change history could not be read.' );
		}
		if ( [] === $rows && 'table' === $format ) {
			$this->line( 'No changes match.' );
			return;
		}
		$this->items( $rows, self::FIELDS, $format );
		if ( 'table' === $format && $list['pages'] > 1 ) {
			$this->line( sprintf( 'Page %1$d of %2$d (%3$d changes). Use --page and --per-page for the others.', $list['page'], $list['pages'], $list['total'] ) );
		}
	}

	/**
	 * Show the diff of one change and what an undo of it would do.
	 *
	 * The lines are masked and capped, and no stored copy of the content is printed. The plan says whether the item was
	 * edited since (drift), which newer changes touch it, whether an administrator is needed (code) and whether a
	 * confirmation token is needed.
	 *
	 * ## OPTIONS
	 *
	 * <change>
	 * : The change id shown by `wp stonewright changes list`.
	 *
	 * [--max-lines=<n>]
	 * : Most lines of text diff to print, 20 to 2000 (default 400).
	 *
	 * [--format=<format>]
	 * : Print as text or as JSON.
	 * ---
	 * default: text
	 * options:
	 *   - text
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp stonewright changes diff cs-0123456789abcdef01234567 --user=admin
	 *
	 * @param list<string>         $args       The change id.
	 * @param array<string, mixed> $assoc_args Named arguments.
	 */
	public function diff( array $args, array $assoc_args ): void {
		$this->require_administrator();
		$id    = $this->change_id( $args );
		$json  = 'json' === $this->format( $assoc_args, [ 'text', 'json' ], 'text' );
		$lines = ChangeHistoryView::diff_lines( [ 'max_lines' => $assoc_args['max-lines'] ?? ChangeHistoryView::DEFAULT_DIFF_LINES ] );
		if ( $lines instanceof \WP_Error ) {
			$this->fail( $lines->get_error_message() );
		}

		try {
			$row = ChangeLedger::get( $id );
			if ( null !== $row ) {
				$view = ChangeHistoryView::row( $row );
				$diff = ChangeDiff::for_row( $row, ChangeHistoryView::diff_options( $lines ) );
				$plan = ChangeHistoryView::plan( ChangeRollback::plan( $id ), $row );
			}
		} catch ( \Throwable $failure ) {
			unset( $failure );
			$this->fail( 'The change could not be read.' );
		}
		if ( null === $row ) {
			$this->fail( 'No change in the history has that id. Retention may have removed it. List them with: wp stonewright changes list' );
		}
		if ( $json ) {
			$this->line( (string) wp_json_encode( [ 'ok' => true, 'change' => $view, 'diff' => $diff, 'plan' => $plan ] ) );
			return;
		}
		foreach ( self::describe( $view, $diff, $plan ) as $line ) {
			$this->line( $line );
		}
	}

	/**
	 * Undo a change, or redo a rollback.
	 *
	 * Puts the item back as it was before the change (for a rollback row, as it was before the rollback), probes the
	 * site and records a rollback row. Drift, an item edited after the change, is refused unless --force-drift. Use
	 * --dry-run first: it prints the plan and changes nothing.
	 *
	 * A change to code (theme file, custom code, sandbox file, Customizer CSS) is not undone from the command line: the
	 * command prints where an administrator approves it at wp-admin.
	 *
	 * In production-safe mode a run needs a confirmation token: run the command with --issue-token (add the same
	 * --force-drift, --permanent and --expected-sha256 as the run), then run it again with --confirmation-token. Exit
	 * code 2 means a token is needed.
	 *
	 * ## OPTIONS
	 *
	 * <change>
	 * : The change id shown by `wp stonewright changes list`, or the id of a rollback row to redo it.
	 *
	 * [--dry-run]
	 * : Print the plan and change nothing.
	 *
	 * [--force-drift]
	 * : Undo even though the item was edited after the change, overwriting those edits.
	 *
	 * [--expected-sha256=<hash>]
	 * : The current_sha256 of the plan. The run stops when the item is not in that state any more.
	 *
	 * [--permanent]
	 * : For families that can remove for good, for example an upload that the change created.
	 *
	 * [--yes]
	 * : Do not ask before the run.
	 *
	 * [--confirmation-token=<token>]
	 * : The token to confirm the run in production-safe mode. When the option is not given, the
	 * STONEWRIGHT_CONFIRMATION_TOKEN environment variable is used.
	 *
	 * [--issue-token]
	 * : Print a confirmation token for this change and these options and stop. Nothing is rolled back.
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
	 *     wp stonewright changes rollback cs-0123456789abcdef01234567 --user=admin --dry-run
	 *     wp stonewright changes rollback cs-0123456789abcdef01234567 --user=admin --yes
	 *     wp stonewright changes rollback cs-0123456789abcdef01234567 --user=admin --issue-token
	 *
	 * @param list<string>         $args       The change id.
	 * @param array<string, mixed> $assoc_args Named arguments.
	 */
	public function rollback( array $args, array $assoc_args ): void {
		$this->require_administrator();
		$id   = $this->change_id( $args );
		$json = 'json' === $this->format( $assoc_args, [ 'text', 'json' ], 'text' );

		$hash = isset( $assoc_args['expected-sha256'] ) && is_string( $assoc_args['expected-sha256'] ) ? $assoc_args['expected-sha256'] : '';
		if ( '' !== $hash && 1 !== preg_match( '/^[a-fA-F0-9]{32,64}$/D', $hash ) ) {
			$this->fail( '--expected-sha256 must be the current_sha256 of the plan: 32 to 64 hexadecimal characters.' );
		}
		$token   = isset( $assoc_args['confirmation-token'] ) && is_string( $assoc_args['confirmation-token'] ) ? $assoc_args['confirmation-token'] : (string) getenv( self::TOKEN_ENV );
		$options = ChangeHistoryView::rollback_input(
			[
				'dry_run'                 => ! empty( $assoc_args['dry-run'] ),
				'force_drift'             => ! empty( $assoc_args['force-drift'] ),
				'permanent'               => ! empty( $assoc_args['permanent'] ),
				'expected_current_sha256' => $hash,
				'confirmation_token'      => $token,
			]
		);
		if ( $options instanceof \WP_Error ) {
			$this->fail( $options->get_error_message() );
		}

		if ( ! empty( $assoc_args['issue-token'] ) ) {
			$issued = ConfirmationToken::issue( self::ABILITY, ChangeRollback::confirmation_args( $id, $options ), self::TOKEN_TTL );
			$this->line( $json ? (string) wp_json_encode( [ 'confirmation_token' => $issued, 'expires_in' => self::TOKEN_TTL ] ) : $issued );
			return;
		}
		if ( ! $options['dry_run'] ) {
			$this->confirm( sprintf( 'Roll back change %s?', $id ), $assoc_args );
		}

		try {
			$result = self::run_rollback( $id, array_merge( $options, [ 'by' => 'wp-cli', 'actor' => (int) get_current_user_id() ] ) );
		} catch ( \Throwable $failure ) {
			unset( $failure );
			$this->fail( 'The rollback stopped on an error. Check the Stonewright audit log.' );
		}

		if ( is_wp_error( $result ) ) {
			$this->fail_with( $result, $id, $options );
		}
		if ( ! is_array( $result ) ) {
			$this->fail( 'The rollback returned an answer that is not understood.' );
		}
		if ( $json ) {
			$this->line( (string) wp_json_encode( $result ) );
			if ( 'still_failing' === (string) ( $result['site_status'] ?? '' ) ) {
				$this->fail( 'The site still fails to load.' );
			}
			return;
		}
		if ( ! empty( $result['dry_run'] ) ) {
			foreach ( self::describe_plan( $result ) as $line ) {
				$this->line( $line );
			}
			return;
		}
		$this->report( $id, $result );
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

	/**
	 * Ask a person to confirm. WP-CLI skips the question when --yes was given and ends the command on no.
	 *
	 * @param array<string, mixed> $assoc_args
	 */
	protected function confirm( string $question, array $assoc_args ): void {
		self::cli( 'confirm', $question, $assoc_args );
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
	 * @param list<string>         $allowed
	 * @param array<string, mixed> $assoc_args
	 */
	private function format( array $assoc_args, array $allowed, string $default ): string {
		$format = isset( $assoc_args['format'] ) && is_string( $assoc_args['format'] ) ? $assoc_args['format'] : $default;
		if ( ! in_array( $format, $allowed, true ) ) {
			$this->fail( 'Unknown format. Use one of: ' . implode( ', ', $allowed ) . '.' );
		}
		return $format;
	}

	/** @param list<string> $args */
	private function change_id( array $args ): string {
		$id = isset( $args[0] ) && is_string( $args[0] ) ? $args[0] : '';
		if ( ! ChangeLedger::is_valid_id( $id ) ) {
			$this->fail( 'Give a change id such as cs-0123456789abcdef01234567. List them with: wp stonewright changes list' );
		}
		return $id;
	}

	/** A flag given bare (true) or with a value that says yes or no. */
	private function flag_value( mixed $value, string $name ): bool {
		if ( true === $value || in_array( $value, [ '1', 'true', 'yes' ], true ) ) {
			return true;
		}
		if ( false === $value || in_array( $value, [ '0', 'false', 'no' ], true ) ) {
			return false;
		}
		$this->fail( sprintf( '--%s takes true or false.', $name ) );
	}

	/**
	 * @param array<string, mixed> $row A row of ChangeHistoryView::row().
	 * @return array<string, mixed>
	 */
	private static function row( array $row ): array {
		$view = ChangeHistoryView::row( $row );
		return [
			'change_id'  => $view['change_id'],
			'time'       => $view['time'],
			'kind'       => $view['kind'],
			'family'     => $view['family'],
			'resource'   => $view['resource_label'],
			'ability'    => $view['ability'],
			'status'     => $view['status'],
			'restorable' => $view['restorable'] ? 'yes' : 'no',
			'summary'    => $view['summary'],
		];
	}

	/**
	 * What the diff command prints.
	 *
	 * @param array<string, mixed> $view
	 * @param array<string, mixed> $diff
	 * @param array<string, mixed> $plan
	 * @return list<string>
	 */
	private static function describe( array $view, array $diff, array $plan ): array {
		$out   = [];
		$out[] = sprintf( 'Change %1$s  %2$s  %3$s  %4$s', $view['change_id'], $view['status'], $view['family'], $view['resource_label'] );
		$out[] = sprintf( 'Recorded %1$s by %2$s with %3$s', $view['time'], $view['actor_name'], $view['ability'] );
		if ( '' !== $view['summary'] ) {
			$out[] = 'Summary: ' . $view['summary'];
		}
		if ( ! $view['restorable'] ) {
			$out[] = 'Not restorable: ' . ( '' !== $view['restorable_reason'] ? $view['restorable_reason'] : 'not_restorable' );
		}
		$out[] = '';
		array_push( $out, ...DiffText::lines( $diff ) );
		$out[] = '';
		if ( empty( $plan['available'] ) ) {
			$out[] = sprintf( 'Undo: not available (%1$s) %2$s', (string) ( $plan['error_code'] ?? '' ), (string) ( $plan['message'] ?? '' ) );
			if ( isset( $plan['redo_change_id'] ) ) {
				$out[] = 'Redo it with the rollback row: ' . $plan['redo_change_id'];
			}
			return $out;
		}
		return array_merge( $out, self::plan_lines( $plan, 'redo' === $plan['kind'] ? 'Redo' : 'Undo' ) );
	}

	/**
	 * The plan of a dry run of the rollback.
	 *
	 * @param array<string, mixed> $result
	 * @return list<string>
	 */
	private static function describe_plan( array $result ): array {
		$out   = [ 'Dry run: nothing was changed.' ];
		$out[] = 'Would do: ' . (string) ( $result['would_apply'] ?? '' );
		if ( is_array( $result['diff'] ?? null ) ) {
			array_push( $out, ...DiffText::lines( $result['diff'] ) );
		}
		return array_merge( $out, self::plan_lines( $result, 'redo' === ( $result['kind'] ?? '' ) ? 'Redo' : 'Undo', true ) );
	}

	/**
	 * @param array<string, mixed> $plan
	 * @return list<string>
	 */
	private static function plan_lines( array $plan, string $verb, bool $hint = false ): array {
		$drift = empty( $plan['drift_known'] ) && array_key_exists( 'drift_known', $plan ) ? 'unknown' : ( ! empty( $plan['drift'] ) ? 'yes' : 'no' );
		$newer = array_map( static fn ( array $other ): string => (string) $other['change_id'], array_values( array_filter( (array) ( $plan['newer_changes'] ?? [] ), 'is_array' ) ) );
		$out   = [
			$verb . ': available',
			'Drift: ' . $drift,
			'Newer changes: ' . ( [] === $newer ? 'none' : implode( ', ', $newer ) ),
			'Needs an administrator: ' . ( ! empty( $plan['approval_required'] ) ? 'yes' : 'no' ),
		];
		if ( ! empty( $plan['approval_required'] ) && isset( $plan['approval_url'] ) ) {
			$out[] = 'Approval page: ' . (string) $plan['approval_url'];
		}
		$out[] = 'Needs a confirmation token: ' . ( ! empty( $plan['confirmation_required'] ) ? 'yes' : 'no' );
		if ( $hint && ! empty( $plan['confirmation_required'] ) ) {
			$out[] = 'Get one with: wp stonewright changes rollback ' . (string) ( $plan['change_id'] ?? '<change>' ) . ' --issue-token';
		}
		foreach ( (array) ( $plan['warnings'] ?? [] ) as $warning ) {
			$out[] = 'Warning: ' . (string) $warning;
		}
		return $out;
	}

	/**
	 * End the command with the error of the engine, in words that suit a terminal.
	 *
	 * @param array<string, mixed> $options
	 */
	private function fail_with( \WP_Error $error, string $id, array $options ): never {
		$code = (string) $error->get_error_code();
		$data = is_array( $error->get_error_data() ) ? $error->get_error_data() : [];
		if ( 'stonewright_rescue_approval_required' === $code ) {
			$url = isset( $data['approval_url'] ) && is_string( $data['approval_url'] ) ? $data['approval_url'] : ( isset( $data['url'] ) && is_string( $data['url'] ) ? $data['url'] : '' );
			$this->fail(
				sprintf(
					'Undoing or redoing a change to code needs an administrator at wp-admin, and the command line is not that approval. Open %1$s, find change %2$s and press Undo, then stop (%3$s).',
					'' !== $url ? $url : 'Stonewright > Activity > Changes',
					$id,
					$code
				)
			);
		}
		if ( str_starts_with( $code, 'stonewright_confirmation_' ) ) {
			$flags = ( ! empty( $options['force_drift'] ) ? ' --force-drift' : '' ) . ( ! empty( $options['permanent'] ) ? ' --permanent' : '' ) . ( '' !== $options['expected_current_sha256'] ? ' --expected-sha256=' . $options['expected_current_sha256'] : '' );
			$this->fail(
				sprintf(
					'Production-safe mode needs a valid confirmation token for exactly this change and these options (%1$s). Run: wp stonewright changes rollback %2$s%3$s --issue-token (it prints a token valid for %4$d minutes), then run the rollback again with --confirmation-token=<token>.',
					$code,
					$id,
					$flags,
					intdiv( self::TOKEN_TTL, 60 )
				),
				self::EXIT_APPROVAL
			);
		}
		$this->fail( sprintf( '%1$s (%2$s)', $error->get_error_message(), $code ) );
	}

	/**
	 * The result of a rollback that ran.
	 *
	 * @param array<string, mixed> $result
	 */
	private function report( string $id, array $result ): void {
		foreach ( array_merge( (array) ( $result['warnings'] ?? [] ), (array) ( $result['limits'] ?? [] ) ) as $note ) {
			$this->warning( (string) $note );
		}
		if ( false === ( $result['ok'] ?? null ) ) {
			$this->fail( 'The rollback did not work.' );
		}
		if ( 'noop' === (string) ( $result['rollback_status'] ?? '' ) ) {
			$this->success( sprintf( 'Nothing to do: the item already is as it was before %s.', $id ) );
			return;
		}
		$verb = 'redo' === (string) ( $result['kind'] ?? '' ) ? 'Redone' : 'Rolled back';
		$site = (string) ( $result['site_status'] ?? 'unknown' );
		if ( 'still_failing' === $site ) {
			$this->fail( sprintf( '%1$s %2$s, but the site still fails to load. The fault may not come from this change.', $verb, $id ) );
		}
		if ( 'healthy' !== $site ) {
			$this->warning( 'The health check afterwards was unavailable, so the result is not confirmed.' );
		}
		$new = (string) ( $result['rollback_change_id'] ?? '' );
		$this->success( sprintf( '%1$s %2$s.%3$s', $verb, $id, '' !== $new ? ' New row: ' . $new . '.' : '' ) . ( 'healthy' === $site ? ' The site loads.' : '' ) );
	}

	/**
	 * @param array<string, mixed> $options
	 */
	private static function run_rollback( string $id, array $options ): mixed {
		if ( null !== self::$rollback_runner ) {
			return ( self::$rollback_runner )( $id, $options );
		}
		return ChangeRollback::run( $id, $options );
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
