<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Content;

/**
 * The optional `date` of a content write, and the rule for a scheduled status.
 *
 * `date` is an ISO 8601 date or date-time. A value without an offset is read in the site timezone, the way
 * the editor reads it; a value with an offset or Z is converted to the site timezone. A `future` status needs
 * a date that is still ahead, because core publishes a post at once when its date is not.
 */
final class PostDate {

	private const PATTERN = '/^\d{4}-\d{2}-\d{2}(?:[T ]\d{2}:\d{2}(?::\d{2}(?:\.\d+)?)?)?(?:Z|[+-]\d{2}:?\d{2})?$/';

	/**
	 * The schema property every create and update ability shares.
	 *
	 * @return array<string, mixed>
	 */
	public static function schema(): array {
		return [
			'type'        => 'string',
			'maxLength'   => 40,
			'description' => 'Optional ISO 8601 date or date-time, for example 2030-06-01T09:30:00. Read in the site timezone unless it carries an offset. Required, and must be in the future, when status is future.',
		];
	}

	/**
	 * The post fields that carry the date, or an error when the date or the scheduled status is not valid.
	 *
	 * @param mixed       $date     The `date` argument, or null when it was not sent.
	 * @param string      $status   The requested status, or an empty string when none was sent.
	 * @param object|null $existing The post being updated, or null for a create.
	 * @return array<string, mixed>|\WP_Error Fields to merge into the post array; empty when no date applies.
	 */
	public static function resolve( mixed $date, string $status, ?object $existing = null ): array|\WP_Error {
		$fields = [];
		$moment = null;

		if ( null !== $date && '' !== $date ) {
			$moment = is_string( $date ) ? self::parse( $date ) : null;
			if ( null === $moment ) {
				return new \WP_Error(
					'stonewright_invalid_date',
					__( 'date must be an ISO 8601 date or date-time, for example 2030-06-01T09:30:00.', 'stonewright' ),
					[ 'status' => 400 ]
				);
			}
			$fields = [
				'post_date'     => $moment->setTimezone( wp_timezone() )->format( 'Y-m-d H:i:s' ),
				'post_date_gmt' => $moment->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ),
			];
			if ( null !== $existing ) {
				// Core keeps the date of a draft unless the caller says it is being edited.
				$fields['edit_date'] = true;
			}
		}

		if ( 'future' === $status ) {
			$timestamp = null !== $moment ? $moment->getTimestamp() : self::stored_timestamp( $existing );
			if ( null === $timestamp || $timestamp <= time() ) {
				return new \WP_Error(
					'stonewright_future_date_required',
					__( 'A future status needs a date that is still ahead. Send date, for example 2030-06-01T09:30:00.', 'stonewright' ),
					[ 'status' => 400 ]
				);
			}
		}

		return $fields;
	}

	private static function parse( string $date ): ?\DateTimeImmutable {
		$date = trim( $date );
		if ( 1 !== preg_match( self::PATTERN, $date ) ) {
			return null;
		}
		try {
			$moment = new \DateTimeImmutable( $date, wp_timezone() );
		} catch ( \Exception $_exception ) {
			return null;
		}
		$errors = \DateTimeImmutable::getLastErrors();
		if ( is_array( $errors ) && ( $errors['warning_count'] > 0 || $errors['error_count'] > 0 ) ) {
			return null;
		}
		return $moment;
	}

	private static function stored_timestamp( ?object $existing ): ?int {
		if ( null === $existing ) {
			return null;
		}
		$gmt = (string) ( $existing->post_date_gmt ?? '' );
		if ( '' === $gmt || str_starts_with( $gmt, '0000' ) ) {
			return null;
		}
		$timestamp = strtotime( $gmt . ' UTC' );
		return false === $timestamp ? null : $timestamp;
	}
}
