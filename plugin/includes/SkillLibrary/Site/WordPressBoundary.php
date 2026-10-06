<?php
/**
 * Authority and audit adapter for skill mutations on this site.
 *
 * @package Stonewright
 * @license AGPL-3.0-or-later
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\SkillLibrary\Site;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Security\AuditLog;
use Stonewright\WpMcp\Security\ConfirmationToken;
use Stonewright\WpMcp\Security\Permissions;
use Stonewright\WpMcp\SkillLibrary\MutationBoundary;

/**
 * Each instance speaks for one channel a mutation arrives through.
 *
 * - The admin screen, the public skill routes, and the skill studio routes
 *   require `manage_options` at the moment of the write; studio writes also
 *   need a current REST nonce.
 * - Abilities have already passed their own permission callback, which the
 *   ability registry enforces before execution. When the ability kernel is
 *   auditing a call under the same name as the event, the event's details go
 *   on the kernel's one row instead of a second row with that name.
 * - The system channel is the plugin acting for itself: refreshing the bundled
 *   pack and recording verified knowledge evidence. Only it may do either.
 *
 * In production-safe mode, permanent deletion needs a confirmation token issued
 * for `stonewright/skills-destroy` with the skill id. Imports need the receipt
 * issued by this site's review. Audit events carry bounded metadata only.
 */
final class WordPressBoundary implements MutationBoundary {

	public const ADMIN = 'admin';

	public const REST = 'rest';

	public const STUDIO = 'studio';

	public const ABILITY = 'ability';

	public const SYSTEM = 'system';

	/** Ability name a confirmation token for permanent deletion is issued for. */
	public const DESTROY_ABILITY = 'stonewright/skills-destroy';

	private const CHANNELS = [ self::ADMIN, self::REST, self::STUDIO, self::ABILITY, self::SYSTEM ];

	/** Channels where a person acts through wp-admin or REST and must hold the capability. */
	private const CAPABILITY_CHANNELS = [ self::ADMIN, self::REST, self::STUDIO ];

	private const SYSTEM_ACTIONS = [ 'seed', 'evidence' ];

	/** Audit fields copied from a mutation summary; nothing else leaves the boundary. */
	private const AUDITED = [ 'skill_id', 'slug', 'revision', 'source', 'status', 'verification_count', 'content_hash', 'review_hash' ];

	private string $channel;

	private string $rest_nonce;

	public function __construct( string $channel, string $rest_nonce = '' ) {
		$this->channel    = $channel;
		$this->rest_nonce = $rest_nonce;
	}

	/** Reads the REST nonce a request carries in its header or `_wpnonce` parameter. */
	public static function for_request( string $channel, \WP_REST_Request $request ): self {
		$nonce = $request->get_header( 'X-WP-Nonce' );
		if ( ! is_string( $nonce ) || '' === $nonce ) {
			$param = $request->get_param( '_wpnonce' );
			$nonce = is_string( $param ) ? $param : '';
		}
		return new self( $channel, $nonce );
	}

	public function channel(): string {
		return $this->channel;
	}

	/** @param array<string, mixed> $summary */
	public function authorize( string $action, array $summary, string $token ): bool|\WP_Error {
		if ( ! in_array( $this->channel, self::CHANNELS, true )
			|| in_array( $action, self::SYSTEM_ACTIONS, true ) !== ( self::SYSTEM === $this->channel ) ) {
			return self::refusal( 'stonewright_skill_channel_refused', 'This change cannot be made from here.' );
		}
		if ( in_array( $this->channel, self::CAPABILITY_CHANNELS, true ) && ! Permissions::manage_options() ) {
			return self::refusal( 'stonewright_skill_permission_denied', 'You do not have permission to manage skills.' );
		}
		if ( self::STUDIO === $this->channel && ( '' === $this->rest_nonce || false === wp_verify_nonce( $this->rest_nonce, 'wp_rest' ) ) ) {
			return self::refusal( 'stonewright_skills_invalid_nonce', 'This request needs a current REST nonce. Reload the skills page and try again.' );
		}
		if ( 'destroy' === $action && Permissions::is_production_safe() ) {
			$verified = self::confirmed_destroy( (int) ( $summary['skill_id'] ?? 0 ), $token );
			if ( true !== $verified ) {
				return $verified;
			}
		}
		if ( 'import' === $action ) {
			return ImportReceipt::verify( $token, is_string( $summary['review_hash'] ?? null ) ? $summary['review_hash'] : '', get_current_user_id() );
		}
		return true;
	}

	/** @param array<string, mixed> $summary */
	public function record( string $action, array $summary, string $outcome ): void {
		// Refreshing the bundled pack is product maintenance; fresh installs start without audit events.
		if ( 'seed' === $action ) {
			return;
		}
		$event = array_intersect_key( $summary, array_flip( self::AUDITED ) );
		$slug  = is_string( $summary['slug'] ?? null ) ? $summary['slug'] : '';
		$name  = 'stonewright/skills-' . sanitize_key( $action );
		$meta  = [
			'operation_class' => 'skill_library',
			'resource_type'   => 'skill',
			'resource_ref'    => $slug,
			'channel'         => $this->channel,
		];

		if ( self::ABILITY === $this->channel && AbilityKernel::add_audit_details( $name, self::call_details( $action, $meta, $event ) ) ) {
			// The ability's call row carries the details; a second row with its name would double-count the call.
			return;
		}
		$event['_meta'] = $meta;
		AuditLog::record( $name, $event, $outcome );
	}

	/**
	 * The event's details as they appear on an ability call's row, where they sit next
	 * to the call's own input and so carry a `skill_` prefix.
	 *
	 * @param array<string, mixed> $meta
	 * @param array<string, mixed> $event
	 * @return array<string, mixed>
	 */
	private static function call_details( string $action, array $meta, array $event ): array {
		$details = $meta + [ 'skill_action' => sanitize_key( $action ) ];
		foreach ( $event as $key => $value ) {
			$details[ str_starts_with( $key, 'skill_' ) ? $key : 'skill_' . $key ] = $value;
		}
		return $details;
	}

	private static function confirmed_destroy( int $skill_id, string $token ): bool|\WP_Error {
		$args = [ 'id' => $skill_id ];
		if ( '' === $token ) {
			return new \WP_Error(
				'stonewright_confirmation_required',
				'Production-safe mode requires a confirmation_token issued by stonewright/security-issue-confirmation-token for ability stonewright/skills-destroy with this skill id.',
				[
					'status'  => 403,
					'ability' => self::DESTROY_ABILITY,
					'args'    => $args,
				]
			);
		}
		return ConfirmationToken::verify_or_error( $token, self::DESTROY_ABILITY, $args );
	}

	private static function refusal( string $code, string $message ): \WP_Error {
		return new \WP_Error( $code, $message, [ 'status' => 403 ] );
	}
}
