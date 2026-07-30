<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Design\Workflow;

use Stonewright\WpMcp\Design\Direction\DesignDirectionService;
use Stonewright\WpMcp\Elementor\Write\TreeHasher;

/**
 * First-section checkpoint for builds that establish a new visual direction.
 *
 * A build that invents a look is different from a build that maintains one. The
 * first kind is worth stopping once: render one section, show the user what the
 * direction actually looks like on their page, and only continue after they say
 * yes. The second kind must never be interrupted, because a repair or a copy fix
 * is not a design decision.
 *
 * That split is the whole class. {@see self::required()} decides which kind of
 * work this is, and the rest of the class turns a human approval into a token
 * that later section writes can be checked against.
 *
 * The token binds the page, the approved section, the design direction revision
 * that was in force, and the render that was actually approved. Anything that
 * moves afterwards — a different page, a different section, an edited direction,
 * an edited approved section — stops the token from verifying. The approval
 * covers what the user saw, not whatever the page became.
 *
 * Unlike a confirmation token, a checkpoint is not single use. One approval
 * authorises the remaining sections of one page for the length of its window,
 * because that is what the user agreed to: continue this build. It stops being
 * usable when the window closes or when the state it names changes.
 *
 * The declared scope is a caller claim, not a measurement. Preflight tells the
 * agent which scope its task is, and this gate holds the agent to that claim.
 * Refusing every build that does not declare a scope would put a new blocker in
 * front of routine edits, which is exactly the failure this checkpoint is meant
 * to avoid.
 */
final class DesignCheckpoint {

	/**
	 * Raised by a builder when remaining sections need an approval that is missing.
	 */
	public const ERROR_REQUIRED = 'stonewright_design_checkpoint_required';

	/**
	 * Malformed token, wrong signature, or wrong purpose.
	 */
	public const ERROR_INVALID = 'stonewright_design_checkpoint_invalid';

	/**
	 * Approval window closed.
	 */
	public const ERROR_EXPIRED = 'stonewright_design_checkpoint_expired';

	/**
	 * Token is authentic but names state that has since changed.
	 */
	public const ERROR_MISMATCH = 'stonewright_design_checkpoint_mismatch';

	/**
	 * Token prefix. Distinct from the confirmation token prefix so the two kinds
	 * of approval can never be substituted for one another.
	 */
	public const TOKEN_PREFIX = 'swk_';

	/**
	 * Signed purpose marker, checked on verify.
	 */
	public const PURPOSE = 'design_checkpoint';

	/**
	 * Default approval window in seconds: long enough to finish one page.
	 */
	public const TTL = 7200;

	/**
	 * Hard ceiling on the approval window.
	 */
	public const MAX_TTL = 86400;

	/**
	 * Filter for the approval window. A non-positive value expires every
	 * checkpoint immediately, which turns the gate into a hard stop.
	 */
	public const TTL_FILTER = 'stonewright_design_checkpoint_ttl';

	/**
	 * Top-level sections a gated build may write before an approval is needed.
	 */
	public const FIRST_SECTION_LIMIT = 1;

	/**
	 * Ability that turns a user approval into a checkpoint token.
	 */
	public const CONTINUATION_ABILITY = 'stonewright/design-checkpoint-record';

	/**
	 * Scope assumed when a caller declares nothing.
	 */
	public const DEFAULT_SCOPE = 'preserve';

	/**
	 * Scopes that establish a new visual direction and therefore need approval.
	 */
	public const GATED_SCOPES = [ 'new_identity', 'replacement', 'rebrand' ];

	/**
	 * Scopes that maintain an existing design and must never be interrupted.
	 */
	public const OPEN_SCOPES = [ 'preserve', 'repair', 'content_only', 'responsive_fix' ];

	/**
	 * Per-install signing secret for checkpoint tokens.
	 */
	private const SECRET_OPTION = 'stonewright_design_checkpoint_secret';

	/**
	 * Signed fields a builder re-derives from live state and compares.
	 */
	private const BOUND_KEYS = [ 'post_id', 'section_id', 'direction_hash', 'render_hash' ];

	/**
	 * Every scope this gate understands.
	 *
	 * @return list<string>
	 */
	public static function scopes(): array {
		return array_merge( self::GATED_SCOPES, self::OPEN_SCOPES );
	}

	/**
	 * Whether a build in this scope needs an approved first section.
	 *
	 * An unrecognised scope fails closed. A caller that declares something this
	 * gate cannot read has not established that the work is a routine edit.
	 */
	public static function required( string $scope ): bool {
		$scope = strtolower( trim( $scope ) );
		if ( '' === $scope ) {
			$scope = self::DEFAULT_SCOPE;
		}

		return ! in_array( $scope, self::OPEN_SCOPES, true );
	}

	/**
	 * Best-effort scope for a task description, used to tell the agent up front
	 * which kind of build it is about to start.
	 */
	public static function scope_for_task( string $task, string $intent = '' ): string {
		$text = strtolower( trim( $task . ' ' . $intent ) );
		$text = (string) preg_replace( '/\s+/', ' ', $text );

		// Direction-establishing work first: these outrank the maintenance terms
		// that a rebrand request also tends to contain.
		if ( self::has_any( $text, [ 'rebrand', 'brand refresh', 'new brand', 'brand identity' ] ) ) {
			return 'rebrand';
		}

		if ( self::has_any( $text, [ 'redesign', 'from scratch', 'rebuild', 'replace the design', 'replace design', 'start over' ] ) ) {
			return 'replacement';
		}

		if ( self::has_any( $text, [ 'new design', 'new identity', 'new look', 'new visual direction', 'new visual identity', 'design direction' ] ) ) {
			return 'new_identity';
		}

		if ( self::has_any( $text, [ 'responsive', 'breakpoint', 'mobile layout', 'tablet layout', 'overflow' ] ) ) {
			return 'responsive_fix';
		}

		if ( self::has_any( $text, [ 'typo', 'copy change', 'copy edit', 'change the text', 'update the text', 'wording' ] ) ) {
			return 'content_only';
		}

		if ( self::has_any( $text, [ 'broken', 'repair', 'fix ', 'regression', 'not rendering' ] ) ) {
			return 'repair';
		}

		return self::DEFAULT_SCOPE;
	}

	/**
	 * Human-readable reason for the gate decision.
	 */
	public static function reason( string $scope ): string {
		$scope = strtolower( trim( $scope ) );
		if ( '' === $scope ) {
			$scope = self::DEFAULT_SCOPE;
		}

		if ( ! self::required( $scope ) ) {
			return sprintf(
				/* translators: %s: declared design scope */
				__( 'Scope %s maintains the existing design, so no visual checkpoint is required.', 'stonewright' ),
				$scope
			);
		}

		if ( in_array( $scope, self::GATED_SCOPES, true ) ) {
			return sprintf(
				/* translators: %s: declared design scope */
				__( 'Scope %s establishes a new visual direction, so the first rendered section needs user approval before the rest of the page is written.', 'stonewright' ),
				$scope
			);
		}

		return sprintf(
			/* translators: %s: declared design scope */
			__( 'Scope %s is not a recognised design scope, so the checkpoint stays required.', 'stonewright' ),
			$scope
		);
	}

	/**
	 * The exact call that turns a user approval into a continuation token.
	 *
	 * @return array<string, mixed>
	 */
	public static function continuation_action(): array {
		return [
			'ability'       => self::CONTINUATION_ABILITY,
			'mcp_tool'      => str_replace( [ 'stonewright/', '/' ], [ 'stonewright-', '-' ], self::CONTINUATION_ABILITY ),
			'required_args' => [ 'post_id', 'section_id', 'approved' ],
			'why'           => __( 'Record the user approval of the first rendered section and pass the returned checkpoint_token to every later section write.', 'stonewright' ),
		];
	}

	/**
	 * The loop an agent follows for a direction-establishing build.
	 *
	 * @return list<string>
	 */
	public static function loop(): array {
		return [
			'Build only the first section.',
			'Render it and capture desktop, tablet, and mobile breakpoints.',
			'Run stonewright/design-quality-check on the measured evidence.',
			'Show the user the evidence: coverage, findings, and screenshots.',
			'Obtain explicit user approval of that section.',
			'Call ' . self::CONTINUATION_ABILITY . ' with approved=true to record it.',
			'Continue the remaining sections with the returned checkpoint_token.',
		];
	}

	/**
	 * Issues a checkpoint token for an approved first section.
	 *
	 * Callers validate the page, section and hashes before issuing; this method
	 * signs what it is given.
	 *
	 * @return array<string, mixed> Token plus the approval it records.
	 */
	public static function issue( int $post_id, string $section_id, string $direction_hash, string $render_hash ): array {
		$ttl         = (int) apply_filters( self::TTL_FILTER, self::TTL );
		$ttl         = min( self::MAX_TTL, $ttl );
		$approved_at = time();
		$expires_at  = $approved_at + $ttl;
		$approved_by = get_current_user_id();

		$payload = [
			'purpose'        => self::PURPOSE,
			'post_id'        => $post_id,
			'section_id'     => $section_id,
			'direction_hash' => $direction_hash,
			'render_hash'    => $render_hash,
			'user_id'        => $approved_by,
			'approved_at'    => $approved_at,
			'expires_at'     => $expires_at,
			'nonce'          => bin2hex( random_bytes( 8 ) ),
		];

		$payload_json = self::canonical_json( $payload );

		return [
			'token'          => self::TOKEN_PREFIX . self::b64url_encode( $payload_json ) . '.' . self::b64url_encode( self::sign( $payload_json ) ),
			'post_id'        => $post_id,
			'section_id'     => $section_id,
			'direction_hash' => $direction_hash,
			'render_hash'    => $render_hash,
			'approved_by'    => $approved_by,
			'approved_at'    => gmdate( 'Y-m-d H:i:s', $approved_at ),
			'expires_at'     => gmdate( 'Y-m-d H:i:s', $expires_at ),
			'expires_in'     => $ttl,
		];
	}

	/**
	 * Verifies a checkpoint against the state a builder derived from live data.
	 *
	 * @param string               $token      Token returned by {@see self::issue()}.
	 * @param array<string, mixed> $bound_args Current post_id, section_id, direction_hash, render_hash.
	 * @return true|\WP_Error
	 */
	public static function verify( string $token, array $bound_args ): bool|\WP_Error {
		$parsed = self::parse( $token );
		if ( null === $parsed ) {
			return new \WP_Error(
				self::ERROR_INVALID,
				__( 'The checkpoint token is malformed.', 'stonewright' ),
				[ 'status' => 403 ]
			);
		}

		[ $payload_json, $signature, $payload ] = $parsed;

		if ( ! hash_equals( self::sign( $payload_json ), $signature ) ) {
			return new \WP_Error(
				self::ERROR_INVALID,
				__( 'The checkpoint token signature is invalid.', 'stonewright' ),
				[ 'status' => 403 ]
			);
		}

		if ( self::PURPOSE !== ( $payload['purpose'] ?? '' ) ) {
			return new \WP_Error(
				self::ERROR_INVALID,
				__( 'That token was not issued as a design checkpoint.', 'stonewright' ),
				[ 'status' => 403 ]
			);
		}

		if ( time() > (int) ( $payload['expires_at'] ?? 0 ) ) {
			return new \WP_Error(
				self::ERROR_EXPIRED,
				__( 'The checkpoint approval window has closed. Show the current first section again and record a new approval.', 'stonewright' ),
				[ 'status' => 403 ]
			);
		}

		if ( (int) ( $payload['user_id'] ?? -1 ) !== get_current_user_id() ) {
			return self::mismatch( 'approved_by', (string) ( $payload['user_id'] ?? '' ), (string) get_current_user_id() );
		}

		foreach ( self::BOUND_KEYS as $key ) {
			$approved = 'post_id' === $key
				? (string) (int) ( $payload[ $key ] ?? 0 )
				: (string) ( $payload[ $key ] ?? '' );
			$current  = 'post_id' === $key
				? (string) (int) ( $bound_args[ $key ] ?? 0 )
				: (string) ( $bound_args[ $key ] ?? '' );

			if ( ! hash_equals( $approved, $current ) ) {
				return self::mismatch( $key, $approved, $current );
			}
		}

		return true;
	}

	/**
	 * Reads the section a token claims to approve.
	 *
	 * This is an unauthenticated read of the payload, used only to choose which
	 * section a builder should re-hash. {@see self::verify()} then authenticates
	 * the whole payload, so a forged section id buys nothing.
	 */
	public static function bound_section_id( string $token ): string {
		$parsed = self::parse( $token );

		return null === $parsed ? '' : (string) ( $parsed[2]['section_id'] ?? '' );
	}

	/**
	 * Hashes one section of an Elementor tree so an approval can be tied to the
	 * render the user actually saw.
	 *
	 * Returns an empty string when the section is not in the tree; callers treat
	 * that as "nothing to approve" rather than as a match.
	 *
	 * @param array<int, array<string, mixed>> $tree       Elementor elements.
	 * @param string                           $section_id Element id to hash.
	 */
	public static function section_render_hash( array $tree, string $section_id ): string {
		if ( '' === $section_id ) {
			return '';
		}

		$node = self::find_node( $tree, $section_id );

		return null === $node ? '' : TreeHasher::hash( [ $node ] );
	}

	/**
	 * Contract hash of the direction currently in force, or an empty string when
	 * the site has none.
	 */
	public static function active_direction_hash( ?DesignDirectionService $service = null ): string {
		$record = ( $service ?? new DesignDirectionService() )->active();

		return is_array( $record ) ? (string) ( $record['contract_hash'] ?? '' ) : '';
	}

	/**
	 * @param array<int, array<string, mixed>> $nodes
	 * @return array<string, mixed>|null
	 */
	private static function find_node( array $nodes, string $section_id ): ?array {
		foreach ( $nodes as $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}

			if ( (string) ( $node['id'] ?? '' ) === $section_id ) {
				return $node;
			}

			$children = isset( $node['elements'] ) && is_array( $node['elements'] ) ? $node['elements'] : [];
			$found    = self::find_node( $children, $section_id );
			if ( null !== $found ) {
				return $found;
			}
		}

		return null;
	}

	private static function mismatch( string $field, string $approved, string $current ): \WP_Error {
		return new \WP_Error(
			self::ERROR_MISMATCH,
			sprintf(
				/* translators: %s: name of the bound checkpoint field */
				__( 'The checkpoint no longer describes the current state: %s changed since it was approved. Show the current first section again and record a new approval.', 'stonewright' ),
				$field
			),
			[
				'status'   => 409,
				'field'    => $field,
				'approved' => $approved,
				'current'  => $current,
			]
		);
	}

	/**
	 * @param string $text  Normalised task text.
	 * @param list<string> $terms Substrings to look for.
	 */
	private static function has_any( string $text, array $terms ): bool {
		foreach ( $terms as $term ) {
			if ( str_contains( $text, $term ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param array<string, mixed> $payload
	 */
	private static function canonical_json( array $payload ): string {
		ksort( $payload );
		$encoded = wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		return is_string( $encoded ) ? $encoded : '{}';
	}

	private static function sign( string $payload_json ): string {
		return hash_hmac( 'sha256', $payload_json, self::secret(), true );
	}

	private static function secret(): string {
		$per_install = (string) get_option( self::SECRET_OPTION, '' );
		if ( '' === $per_install ) {
			$per_install = bin2hex( random_bytes( 32 ) );
			add_option( self::SECRET_OPTION, $per_install, '', false );
		}

		return wp_salt( 'auth' ) . $per_install;
	}

	/**
	 * @return array{string, string, array<string, mixed>}|null
	 */
	private static function parse( string $token ): ?array {
		if ( ! str_starts_with( $token, self::TOKEN_PREFIX ) ) {
			return null;
		}

		$body = substr( $token, strlen( self::TOKEN_PREFIX ) );
		$dot  = strpos( $body, '.' );
		if ( false === $dot ) {
			return null;
		}

		$payload_json = self::b64url_decode( substr( $body, 0, $dot ) );
		$signature    = self::b64url_decode( substr( $body, $dot + 1 ) );
		if ( false === $payload_json || false === $signature || '' === $payload_json || '' === $signature ) {
			return null;
		}

		try {
			$payload = json_decode( $payload_json, true, 32, JSON_THROW_ON_ERROR );
		} catch ( \JsonException ) {
			return null;
		}

		return is_array( $payload ) ? [ $payload_json, $signature, $payload ] : null;
	}

	private static function b64url_encode( string $bytes ): string {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		return rtrim( strtr( base64_encode( $bytes ), '+/', '-_' ), '=' );
	}

	/**
	 * @return string|false
	 */
	private static function b64url_decode( string $value ): string|false {
		$padded = $value . str_repeat( '=', ( 4 - strlen( $value ) % 4 ) % 4 );

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		return base64_decode( strtr( $padded, '-_', '+/' ), true );
	}
}
