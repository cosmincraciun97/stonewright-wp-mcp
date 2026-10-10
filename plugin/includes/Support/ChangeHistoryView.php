<?php
/**
 * What the change history abilities and the WP-CLI command show of a ledger row, a diff and an undo plan.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Support;

use Stonewright\WpMcp\Admin\ChangeLabels;
use Stonewright\WpMcp\Admin\ChangesPage;
use Stonewright\WpMcp\Security\ChangeLedger;

/**
 * Turns the answers of the ledger, the diff engines and the rollback engine into short plain arrays, so that an agent or a
 * terminal never receives a stored image, a blob name or a whole hash. Input is checked here: a value that is not understood
 * is refused, never dropped, so a typo cannot widen a list.
 */
final class ChangeHistoryView {

	/** Error code of an input that is not valid. */
	public const INVALID = 'stonewright_change_history_invalid';

	/** Characters of a summary that a row keeps. */
	public const MAX_SUMMARY = 300;

	/** Characters of a resource name that a row keeps. */
	public const MAX_LABEL = 120;

	/** Characters of a hash that a plan shows: the page shows as many, and the engine accepts them as a preview. */
	public const HASH_PREFIX = 32;

	public const DEFAULT_PER_PAGE = ChangeLedger::DEFAULT_PER_PAGE;

	public const MAX_PER_PAGE = 100;

	/** Fewest and most lines of a diff that change-diff-get may be asked for, and the default. */
	public const MIN_DIFF_LINES = 20;

	public const MAX_DIFF_LINES = 2000;

	public const DEFAULT_DIFF_LINES = 400;

	/** Names of the filters and paging inputs of the list, in the order the schema shows them. */
	public const LIST_INPUTS = [ 'family', 'resource', 'ability', 'actor', 'status', 'from', 'to', 'restorable', 'kind', 'page', 'per_page' ];

	// -----------------------------------------------------------------------------------------------
	// Input
	// -----------------------------------------------------------------------------------------------

	/**
	 * The ledger filters for the inputs of the list, and the page.
	 *
	 * @param array<string, mixed> $input
	 * @return array{filters: array<string, mixed>, page: int, per_page: int}|\WP_Error
	 */
	public static function list_input( array $input ): array|\WP_Error {
		$filters = [];

		if ( isset( $input['family'] ) ) {
			if ( ! is_string( $input['family'] ) || ! in_array( $input['family'], ChangeLedger::FAMILIES, true ) ) {
				return self::invalid( 'family', __( 'Use one of the families the change history lists.', 'stonewright' ) );
			}
			$filters['family'] = $input['family'];
		}
		if ( isset( $input['resource'] ) ) {
			if ( ! is_string( $input['resource'] ) || '' === trim( $input['resource'] ) || strlen( $input['resource'] ) > 200 ) {
				return self::invalid( 'resource', __( 'Give the id or name of the resource, at most 200 characters.', 'stonewright' ) );
			}
			$filters['resource_id'] = trim( $input['resource'] );
		}
		if ( isset( $input['ability'] ) ) {
			$ability = is_string( $input['ability'] ) ? strtolower( trim( $input['ability'] ) ) : '';
			$ability = '' === $ability || str_contains( $ability, '/' ) ? $ability : 'stonewright/' . $ability;
			if ( 1 !== preg_match( '/^[a-z0-9][a-z0-9_-]{0,47}\/[a-z0-9][a-z0-9_-]{0,63}$/D', $ability ) ) {
				return self::invalid( 'ability', __( 'Give an ability name such as stonewright/content-update-page.', 'stonewright' ) );
			}
			$filters['ability'] = $ability;
		}
		if ( isset( $input['actor'] ) ) {
			$actor = self::actor( $input['actor'] );
			if ( null === $actor ) {
				return self::invalid( 'actor', __( 'Give a user id or a login name.', 'stonewright' ) );
			}
			$filters['actor'] = $actor;
		}
		if ( isset( $input['status'] ) ) {
			if ( ! is_string( $input['status'] ) || ! isset( ChangesPage::STATUS_GROUPS[ $input['status'] ] ) ) {
				return self::invalid( 'status', sprintf( /* translators: %s: the allowed statuses */ __( 'Use one of: %s.', 'stonewright' ), implode( ', ', array_keys( ChangesPage::STATUS_GROUPS ) ) ) );
			}
			$filters['status'] = ChangesPage::STATUS_GROUPS[ $input['status'] ];
		}
		foreach ( [ 'from' => 'since', 'to' => 'until' ] as $name => $key ) {
			if ( ! isset( $input[ $name ] ) ) {
				continue;
			}
			if ( ! is_string( $input[ $name ] ) || 1 !== preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/D', $input[ $name ], $parts ) || ! checkdate( (int) $parts[2], (int) $parts[3], (int) $parts[1] ) ) {
				return self::invalid( $name, __( 'Give a date as YYYY-MM-DD, in UTC.', 'stonewright' ) );
			}
			$filters[ $key ] = $input[ $name ];
		}
		if ( isset( $input['restorable'] ) ) {
			if ( ! is_bool( $input['restorable'] ) ) {
				return self::invalid( 'restorable', __( 'Use true or false.', 'stonewright' ) );
			}
			$filters['restorable'] = $input['restorable'];
		}
		if ( isset( $input['kind'] ) ) {
			if ( ! is_string( $input['kind'] ) || ! in_array( $input['kind'], ChangeLedger::KINDS, true ) ) {
				return self::invalid( 'kind', sprintf( /* translators: %s: the allowed kinds */ __( 'Use one of: %s.', 'stonewright' ), implode( ', ', ChangeLedger::KINDS ) ) );
			}
			$filters['kind'] = $input['kind'];
		}

		$page = self::integer( $input['page'] ?? 1, 1, 1000000 );
		if ( null === $page ) {
			return self::invalid( 'page', __( 'Use a page number from 1.', 'stonewright' ) );
		}
		$per_page = self::integer( $input['per_page'] ?? self::DEFAULT_PER_PAGE, 1, self::MAX_PER_PAGE );
		if ( null === $per_page ) {
			return self::invalid( 'per_page', sprintf( /* translators: %d: the largest page size */ __( 'Use a page size from 1 to %d.', 'stonewright' ), self::MAX_PER_PAGE ) );
		}

		return [ 'filters' => $filters, 'page' => $page, 'per_page' => $per_page ];
	}

	/**
	 * The cap on the lines of a diff.
	 *
	 * @param array<string, mixed> $input
	 */
	public static function diff_lines( array $input ): int|\WP_Error {
		$lines = self::integer( $input['max_lines'] ?? self::DEFAULT_DIFF_LINES, self::MIN_DIFF_LINES, self::MAX_DIFF_LINES );
		return null === $lines
			? self::invalid( 'max_lines', sprintf( /* translators: 1: fewest lines, 2: most lines */ __( 'Use a number of lines from %1$d to %2$d.', 'stonewright' ), self::MIN_DIFF_LINES, self::MAX_DIFF_LINES ) )
			: $lines;
	}

	/** The options of ChangeDiff::for_row() that keep a diff to $max_lines lines of text. */
	public static function diff_options( int $max_lines ): array {
		return [
			'text'      => [ 'max_output_lines' => $max_lines, 'max_hunks' => 25, 'max_line_chars' => 300 ],
			'blocks'    => [ 'max_items' => 50, 'max_attrs' => 15, 'max_value_chars' => 300 ],
			'elementor' => [ 'max_elements' => 50, 'max_fields' => 15, 'max_value_chars' => 300 ],
			'fields'    => [ 'max_fields' => min( 200, $max_lines ), 'max_value_chars' => 300 ],
		];
	}

	/**
	 * The options of a rollback run, from the inputs of the ability or the command.
	 *
	 * @param array<string, mixed> $input
	 * @return array{dry_run: bool, force_drift: bool, permanent: bool, expected_current_sha256: string, confirmation_token: string}|\WP_Error
	 */
	public static function rollback_input( array $input ): array|\WP_Error {
		foreach ( [ 'dry_run', 'force_drift', 'permanent' ] as $flag ) {
			if ( isset( $input[ $flag ] ) && ! is_bool( $input[ $flag ] ) ) {
				return self::invalid( $flag, __( 'Use true or false.', 'stonewright' ) );
			}
		}
		$hash = '';
		if ( isset( $input['expected_current_sha256'] ) && '' !== $input['expected_current_sha256'] ) {
			if ( ! is_string( $input['expected_current_sha256'] ) || 1 !== preg_match( '/^[a-fA-F0-9]{32,64}$/D', $input['expected_current_sha256'] ) ) {
				return self::invalid( 'expected_current_sha256', __( 'Give the hash that the plan showed: 32 to 64 hexadecimal characters.', 'stonewright' ) );
			}
			$hash = strtolower( $input['expected_current_sha256'] );
		}
		$token = '';
		if ( isset( $input['confirmation_token'] ) ) {
			if ( ! is_string( $input['confirmation_token'] ) ) {
				return self::invalid( 'confirmation_token', __( 'Give the token as text.', 'stonewright' ) );
			}
			$token = $input['confirmation_token'];
		}
		return [
			'dry_run'                 => ! empty( $input['dry_run'] ),
			'force_drift'             => ! empty( $input['force_drift'] ),
			'permanent'               => ! empty( $input['permanent'] ),
			'expected_current_sha256' => $hash,
			'confirmation_token'      => $token,
		];
	}

	// -----------------------------------------------------------------------------------------------
	// Output
	// -----------------------------------------------------------------------------------------------

	/**
	 * The short row of a change.
	 *
	 * @param array<string, mixed> $row A row of ChangeLedger.
	 * @return array<string, mixed>
	 */
	public static function row( array $row ): array {
		$id       = (string) $row['change_id'];
		$resource = ChangeLabels::resource( $row );
		$time     = strtotime( (string) $row['created_at'] . ' UTC' );

		return [
			'change_id'         => $id,
			'time'              => gmdate( 'Y-m-d\TH:i:s\Z', false === $time ? 0 : $time ),
			'kind'              => (string) $row['kind'],
			'family'            => (string) $row['family'],
			'resource_type'     => (string) $row['resource_type'],
			'resource_label'    => self::clip( $resource['text'], self::MAX_LABEL ),
			'ability'           => (string) $row['ability'],
			'actor'             => (int) $row['actor'],
			'actor_name'        => ChangeLabels::user( (int) $row['actor'] ),
			'status'            => (string) $row['status'],
			'summary'           => self::clip( (string) $row['summary'], self::MAX_SUMMARY ),
			'restorable'        => (bool) $row['restorable'],
			'restorable_reason' => (string) $row['restorable_reason'],
			'parent_id'         => (string) $row['parent_id'],
			'children'          => ChangeLedger::count( [ 'parent_id' => $id ] ),
		];
	}

	/**
	 * What an undo or redo of a change would do, in a few fields. The diff of the undo is left out: change-diff-get shows the
	 * diff of the change, and a dry run of the rollback shows the summary of the undo (diff_brief()).
	 *
	 * @param array<string, mixed>|\WP_Error $plan The answer of ChangeRollback::plan().
	 * @param array<string, mixed>           $row  The row the plan is for.
	 * @return array<string, mixed>
	 */
	public static function plan( array|\WP_Error $plan, array $row ): array {
		if ( $plan instanceof \WP_Error ) {
			$data = is_array( $plan->get_error_data() ) ? $plan->get_error_data() : [];
			$out  = [
				'available'         => false,
				'restorable'        => (bool) $row['restorable'],
				'restorable_reason' => (string) $row['restorable_reason'],
				'error_code'        => (string) $plan->get_error_code(),
				'message'           => self::clip( $plan->get_error_message(), self::MAX_SUMMARY ),
			];
			if ( ! empty( $data['rollback_change_id'] ) && is_string( $data['rollback_change_id'] ) ) {
				$out['redo_change_id'] = $data['rollback_change_id'];
			}
			return $out;
		}

		$out = [
			'available'             => true,
			'restorable'            => true,
			'restorable_reason'     => '',
			'kind'                  => (string) $plan['kind'],
			'path'                  => (string) $plan['path'],
			'approval_required'     => (bool) $plan['approval_required'],
			'drift'                 => (bool) $plan['drift'],
			'drift_known'           => (bool) $plan['drift_known'],
			'requires_force'        => (bool) $plan['requires_force'],
			'already_restored'      => (bool) $plan['already_restored'],
			'newer_changes'         => array_values( (array) $plan['newer_changes'] ),
			'warnings'              => array_values( array_map( 'strval', (array) $plan['warnings'] ) ),
			'confirmation_required' => (bool) $plan['confirmation_required'],
			'would_apply'           => (string) $plan['would_apply'],
			'current_sha256'        => substr( (string) $plan['current_sha256'], 0, self::HASH_PREFIX ),
		];
		if ( isset( $plan['approval_url'] ) ) {
			$out['approval_url'] = (string) $plan['approval_url'];
		}
		return $out;
	}

	/**
	 * A diff of a plan without its lines: how much is added, removed and changed in each part.
	 *
	 * @param array<string, mixed> $diff A result of ChangeDiff.
	 * @return array<string, mixed>
	 */
	public static function diff_brief( array $diff ): array {
		$sections = [];
		foreach ( (array) ( $diff['sections'] ?? [] ) as $section ) {
			$summary    = is_array( $section['result']['summary'] ?? null ) ? $section['result']['summary'] : [];
			$sections[] = [
				'id'      => (string) $section['id'],
				'title'   => self::clip( (string) $section['title'], self::MAX_LABEL ),
				'summary' => [
					'added'   => (int) ( $summary['added'] ?? 0 ),
					'removed' => (int) ( $summary['removed'] ?? 0 ),
					'changed' => (int) ( $summary['changed'] ?? 0 ),
					'moved'   => (int) ( $summary['moved'] ?? 0 ),
				],
			];
		}
		return [
			'status'       => (string) ( $diff['status'] ?? '' ),
			'message'      => self::clip( (string) ( $diff['message'] ?? '' ), self::MAX_SUMMARY ),
			'changed'      => ! empty( $diff['changed'] ),
			'truncated'    => ! empty( $diff['truncated'] ),
			'masked'       => (int) ( $diff['masked'] ?? 0 ),
			'image_masked' => ! empty( $diff['image_masked'] ),
			'deleted'      => ! empty( $diff['deleted'] ),
			'sections'     => $sections,
		];
	}

	// -----------------------------------------------------------------------------------------------
	// Helpers
	// -----------------------------------------------------------------------------------------------

	/** A user id, or the id of the account with that login; -1 for an account that does not exist, which matches no change. */
	private static function actor( mixed $value ): int|null {
		if ( is_int( $value ) && $value > 0 ) {
			return $value;
		}
		if ( ! is_string( $value ) || '' === trim( $value ) || strlen( $value ) > 60 ) {
			return null;
		}
		$value = trim( $value );
		if ( ctype_digit( $value ) && (int) $value > 0 ) {
			return (int) $value;
		}
		$account = get_user_by( 'login', $value );
		return is_object( $account ) && (int) $account->ID > 0 ? (int) $account->ID : -1;
	}

	/** An integer in a range, from an int or a string of digits; null for anything else. */
	private static function integer( mixed $value, int $min, int $max ): ?int {
		if ( is_string( $value ) && 1 === preg_match( '/^[0-9]{1,9}$/D', $value ) ) {
			$value = (int) $value;
		}
		return is_int( $value ) && $value >= $min && $value <= $max ? $value : null;
	}

	private static function clip( string $text, int $max ): string {
		$text = trim( (string) preg_replace( '/\s+/', ' ', $text ) );
		if ( function_exists( 'mb_strlen' ) && mb_strlen( $text ) > $max ) {
			return mb_substr( $text, 0, $max - 1 ) . '…';
		}
		return strlen( $text ) > $max ? substr( $text, 0, $max - 1 ) . '…' : $text;
	}

	private static function invalid( string $field, string $message ): \WP_Error {
		return new \WP_Error( self::INVALID, sprintf( /* translators: 1: the input name, 2: what is allowed */ __( 'Input %1$s is not valid. %2$s', 'stonewright' ), $field, $message ), [ 'status' => 400, 'field' => $field ] );
	}
}
