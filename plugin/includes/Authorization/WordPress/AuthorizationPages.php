<?php
/**
 * Authorization endpoint and consent screen (hidden admin pages).
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Authorization\WordPress;

use Stonewright\WpMcp\Authorization\Decisions\ConsentDecision;
use Stonewright\WpMcp\Authorization\Exchange\ConsentCoordinator;
use Stonewright\WpMcp\Authorization\Model\OAuthFault;
use Stonewright\WpMcp\Authorization\Protocol\CodeProof;
use Stonewright\WpMcp\Authorization\Protocol\ParameterBag;
use Stonewright\WpMcp\Authorization\Protocol\RedirectRules;
use Stonewright\WpMcp\Authorization\Protocol\RequestDecoder;
use Stonewright\WpMcp\Authorization\Protocol\ResourceRules;
use Stonewright\WpMcp\Support\Logger;

/**
 * The authorization endpoint is admin.php?page=stonewright-oauth-authorize, so
 * WordPress sends signed-out users through its login screen and back. For a signed-in
 * user (capability read) the request is checked in this order:
 *
 * 1. client: a registered client_id, or a metadata document URL (ClientDocuments); the
 *    audit facts name the application, by the identifier presented, only after this
 *    check passes, so made-up identifiers never reach the audit log;
 * 2. redirect_uri: one of the client's callbacks (exact, or another port for a
 *    loopback callback); it may be omitted when exactly one is registered.
 * A failure in 1 or 2 shows an error page and never redirects. Later failures return to
 * the callback with error, error_description, state and iss: response_type must be
 * "code", PKCE must be S256, scope may only name advertised scopes (the grant carries
 * "mcp"), and resource must be this site's MCP resource. A valid request becomes a
 * pending consent (ten minutes, for this user only) and the browser moves to
 * admin.php?page=stonewright-oauth-consent&token=<key>.
 *
 * The consent screen names the client (escaped), how it is identified, and the scheme
 * and host its callback returns to. Approve and Deny post back to the same URL with a
 * nonce bound to the pending key. Approval creates a 60-second code and redirects with
 * code, state and iss; denial redirects with error=access_denied, state and iss. Either
 * answer consumes the pending consent, so a second submission gets an error page.
 * Both pages send no-store caching, X-Frame-Options SAMEORIGIN, frame-ancestors 'self'
 * and Referrer-Policy strict-origin-when-cross-origin.
 */
final class AuthorizationPages {

	public const NONCE_ACTION = 'stonewright_oauth_consent';
	public const CODE_LIFETIME = 60;
	public const MAXIMUM_QUERY_BYTES = 16384;
	public const MAXIMUM_STATE_BYTES = 4096;

	public const SECURITY_HEADERS = [
		'X-Frame-Options'         => 'SAMEORIGIN',
		'Content-Security-Policy' => "frame-ancestors 'self';",
		'Referrer-Policy'         => 'strict-origin-when-cross-origin',
	];

	private const DESCRIPTIONS = [
		'invalid_request'           => 'The authorization request is missing a parameter, repeats one, or uses an unsupported PKCE method.',
		'unsupported_response_type' => 'Only the authorization code flow is supported.',
		'invalid_scope'             => 'The requested scope is not available.',
		'invalid_target'            => 'The requested resource is not served by this authorization server.',
		'temporarily_unavailable'   => 'The authorization server cannot answer right now.',
		'server_error'              => 'The authorization server could not complete the request.',
	];

	/** @var array<string, string> Consent view prepared before the admin page renders. */
	private static array $view = [];

	public function __construct( private SiteProfile $site, private AuthorizationStorage $storage, private ClientDocuments $documents, private RequestLimiter $limiter ) {}

	public static function nonce_action( string $pending_key ): string {
		return self::NONCE_ACTION . ':' . $pending_key;
	}

	/** Check an authorization request (the raw query string) for a signed-in user. */
	public function authorize( string $query, int $user_id ): PageOutcome {
		$audit = [ 'client_id' => '' ];
		if ( $user_id < 1 ) {
			return PageOutcome::error( 401, __( 'Sign in to authorize an application.', 'stonewright' ), $audit );
		}
		$admission = $this->limiter->admit( 'authorization', 'user:' . $user_id );
		if ( ! $admission['allowed'] ) {
			/* translators: %d: seconds to wait */
			return PageOutcome::error( 429, sprintf( __( 'Too many authorization requests. Try again in %d seconds.', 'stonewright' ), $admission['retry_after'] ), $audit );
		}
		try {
			$parameters = ( new RequestDecoder() )->form( $query, self::MAXIMUM_QUERY_BYTES );
			$client_id = $parameters->one( 'client_id' );
			$requested_redirect = $parameters->one( 'redirect_uri' );
		} catch ( OAuthFault $malformed ) {
			return PageOutcome::error( 400, __( 'The authorization request is malformed.', 'stonewright' ), $audit );
		}
		if ( null === $client_id ) {
			return PageOutcome::error( 400, __( 'The authorization request does not name an application.', 'stonewright' ), $audit );
		}
		$client = $this->client( $client_id );
		if ( null === $client ) {
			return PageOutcome::error( 400, __( 'This application is not registered with this site, or its client information could not be verified.', 'stonewright' ), $audit );
		}
		// Named in the audit only now that the site knows the client; the identifier is the one presented.
		$audit['client_id'] = $client_id;
		$registered = array_values( array_filter( (array) $client['redirect_uris'], 'is_string' ) );
		$redirect = self::approved_redirect( $requested_redirect ?? ( 1 === count( $registered ) ? $registered[0] : null ), $registered );
		if ( null === $redirect ) {
			return PageOutcome::error( 400, __( 'The redirect URI is not registered for this application.', 'stonewright' ), $audit );
		}
		$state = self::echoed_state( $parameters );
		try {
			$pending_key = $this->storage->consents()->open( $this->pending_request( $parameters, (string) $client['client_id'], $redirect, $registered, $user_id ) );
		} catch ( OAuthFault $fault ) {
			return $this->return_error( $redirect, $fault->error(), $state, $audit );
		} catch ( StorageFailure $failure ) {
			return $this->return_error( $redirect, 'server_error', $state, $audit );
		}
		return PageOutcome::redirect( $this->site->consent_url( $pending_key ), false, $audit );
	}

	/**
	 * The pending consent request after checking the protocol parameters.
	 *
	 * @param list<string> $registered
	 * @return array<string, mixed>
	 * @throws OAuthFault When a parameter is refused; the error goes back to the client.
	 */
	private function pending_request( ParameterBag $parameters, string $client_key, string $redirect, array $registered, int $user_id ): array {
		$state = $parameters->one( 'state' );
		if ( null !== $state && strlen( $state ) > self::MAXIMUM_STATE_BYTES ) {
			throw new OAuthFault( 'invalid_request' );
		}
		$response_type = $parameters->one( 'response_type' );
		if ( 'code' !== $response_type ) {
			throw new OAuthFault( null === $response_type ? 'invalid_request' : 'unsupported_response_type' );
		}
		$challenge = (string) $parameters->one( 'code_challenge' );
		( new CodeProof() )->require_s256( (string) ( $parameters->one( 'code_challenge_method' ) ?? 'plain' ), $challenge );
		self::require_scopes( $parameters->one( 'scope' ) );
		$request = [
			'subject_key'           => (string) $user_id,
			'client_key'            => $client_key,
			'redirect_uri'          => $redirect,
			'code_challenge'        => $challenge,
			'code_challenge_method' => 'S256',
			'scopes'                => SiteProfile::GRANTED_SCOPES,
			'resources'             => $this->resources( $parameters ),
			'native_client'         => true,
			'registered_redirects'  => $registered,
		];
		if ( null !== $state ) {
			$request['state'] = $state;
		}
		return $request;
	}

	/**
	 * The callback to use when it is one of the client's, else null.
	 *
	 * @param list<string> $registered
	 */
	private static function approved_redirect( ?string $requested, array $registered ): ?string {
		if ( null === $requested ) {
			return null;
		}
		try {
			return ( new RedirectRules() )->approve( $requested, $registered, true );
		} catch ( OAuthFault $untrusted ) {
			return null;
		}
	}

	/** The state to echo in an error response: absent when missing, repeated or too long. */
	private static function echoed_state( ParameterBag $parameters ): ?string {
		try {
			$state = $parameters->one( 'state' );
		} catch ( OAuthFault $repeated ) {
			return null;
		}
		return null !== $state && strlen( $state ) <= self::MAXIMUM_STATE_BYTES ? $state : null;
	}

	/** The consent view for a pending request of this user, or an error page. */
	public function review( string $query, int $user_id ): PageOutcome {
		$pending_key = self::pending_key( $query );
		$pending = null === $pending_key ? null : $this->storage->consents()->peek( $pending_key );
		$client = null === $pending ? null : $this->storage->clients()->find( (string) $pending['client_key'] );
		if ( null === $pending_key || null === $pending || null === $client || (string) $user_id !== $pending['subject_key'] ) {
			return self::expired();
		}
		$document = is_string( $client['client_id_metadata_document'] ?? null ) ? $client['client_id_metadata_document'] : '';
		$user = get_user_by( 'id', $user_id );
		$name = is_string( $client['client_name'] ?? null ) ? trim( $client['client_name'] ) : '';
		return PageOutcome::view(
			[
				'token'         => $pending_key,
				'client_name'   => '' === $name ? __( 'An unnamed application', 'stonewright' ) : $name,
				'document_host' => '' === $document ? '' : (string) parse_url( $document, PHP_URL_HOST ),
				'destination'   => self::origin( (string) $pending['redirect_uri'] ),
				'user'          => false === $user ? '' : (string) ( $user->display_name ?? $user->user_login ?? '' ),
				'site'          => (string) get_bloginfo( 'name' ),
			],
			[ 'client_id' => '' !== $document ? $document : (string) $pending['client_key'] ]
		);
	}

	/**
	 * Approve or deny a pending request.
	 *
	 * @param array<string, mixed> $form Unslashed POST fields.
	 */
	public function decide( string $query, array $form, int $user_id ): PageOutcome {
		$pending_key = self::pending_key( $query );
		if ( null === $pending_key ) {
			return self::expired();
		}
		$nonce = is_string( $form['_wpnonce'] ?? null ) ? $form['_wpnonce'] : '';
		if ( '' === $nonce || false === wp_verify_nonce( $nonce, self::nonce_action( $pending_key ) ) ) {
			return PageOutcome::error( 403, __( 'This confirmation expired. Go back, reload the page and choose again.', 'stonewright' ) );
		}
		$approve = isset( $form['approve'] );
		if ( $approve === isset( $form['deny'] ) ) {
			return PageOutcome::error( 400, __( 'Choose Approve or Deny.', 'stonewright' ) );
		}
		try {
			$coordinator = new ConsentCoordinator( $this->storage->consents(), $this->storage->clock(), $this->storage->identifiers(), $this->storage->subjects(), new ConsentDecision( self::CODE_LIFETIME ) );
			$outcome = $coordinator->decide( $pending_key, (string) $user_id, true, $approve );
		} catch ( OAuthFault $fault ) {
			return 'access_denied' === $fault->error()
				? PageOutcome::error( 403, __( 'Your account cannot use the MCP tools on this site.', 'stonewright' ) )
				: self::expired();
		} catch ( StorageFailure $failure ) {
			return PageOutcome::error( 500, __( 'The answer could not be stored. Start the connection again from your application.', 'stonewright' ) );
		}
		$client_key = (string) ( $outcome['pending']['client_key'] ?? '' );
		$parameters = [];
		if ( $approve ) {
			try {
				$parameters['code'] = $this->storage->codec()->encode_code( (array) $outcome['code'] );
			} catch ( OAuthFault $failure ) {
				$parameters['error'] = 'server_error';
			}
		} else {
			$parameters['error'] = 'access_denied';
		}
		$state = $outcome['redirect_parameters']['state'] ?? null;
		if ( is_string( $state ) ) {
			$parameters['state'] = $state;
		}
		$parameters['iss'] = $this->site->issuer();
		$audit = [
			'client_id' => $client_key,
			'body'      => isset( $parameters['error'] ) ? [ 'error' => $parameters['error'] ] : [],
		];
		return PageOutcome::redirect( self::callback( (string) $outcome['redirect_uri'], $parameters ), true, $audit );
	}

	/**
	 * Consent form HTML; every value is escaped.
	 *
	 * @param array<string, string> $view
	 */
	public static function render( array $view ): string {
		$client = (string) ( $view['client_name'] ?? '' );
		$site = (string) ( $view['site'] ?? '' );
		$user = (string) ( $view['user'] ?? '' );
		$host = (string) ( $view['document_host'] ?? '' );
		$identity = '' === $host
			? __( 'Registered with this site', 'stonewright' )
			/* translators: %s: host name serving the application's client information */
			: sprintf( __( 'Client information published by %s', 'stonewright' ), $host );
		$html = '<div class="wrap sw-oauth-consent">';
		/* translators: 1: application name, 2: site name */
		$html .= '<h1>' . esc_html( sprintf( __( 'Connect %1$s to %2$s?', 'stonewright' ), $client, $site ) ) . '</h1>';
		$html .= '<div class="card">';
		/* translators: 1: application name, 2: user name */
		$html .= '<p>' . esc_html( sprintf( __( '%1$s is asking to use the Stonewright MCP tools on this site as %2$s. It can do only what your account is allowed to do through those tools.', 'stonewright' ), $client, $user ) ) . '</p>';
		$html .= '<table class="form-table" role="presentation"><tbody>';
		$html .= '<tr><th scope="row">' . esc_html__( 'Application', 'stonewright' ) . '</th><td>' . esc_html( $client ) . '</td></tr>';
		$html .= '<tr><th scope="row">' . esc_html__( 'Identified by', 'stonewright' ) . '</th><td>' . esc_html( $identity ) . '</td></tr>';
		$html .= '<tr><th scope="row">' . esc_html__( 'Returns you to', 'stonewright' ) . '</th><td><code>' . esc_html( (string) ( $view['destination'] ?? '' ) ) . '</code></td></tr>';
		$html .= '<tr><th scope="row">' . esc_html__( 'Access', 'stonewright' ) . '</th><td>' . esc_html__( 'Stonewright MCP tools (scope: mcp)', 'stonewright' ) . '</td></tr>';
		$html .= '</tbody></table>';
		$html .= '<p>' . esc_html__( 'Approve only if you started this connection yourself. You can revoke the access later.', 'stonewright' ) . '</p>';
		$html .= '<form method="post" action="">';
		$html .= wp_nonce_field( self::nonce_action( (string) ( $view['token'] ?? '' ) ), '_wpnonce', true, false );
		$html .= '<p class="submit">';
		$html .= '<button type="submit" name="approve" value="1" class="button button-primary">' . esc_html__( 'Approve', 'stonewright' ) . '</button> ';
		$html .= '<button type="submit" name="deny" value="1" class="button">' . esc_html__( 'Deny', 'stonewright' ) . '</button>';
		$html .= '</p></form></div></div>';
		return $html;
	}

	/** Register both hidden pages while OAuth is available (admin_menu). */
	public static function register_pages(): void {
		if ( ! HttpSurface::site()->available() ) {
			return;
		}
		$authorize = add_submenu_page( 'options.php', __( 'Authorize application', 'stonewright' ), __( 'Authorize application', 'stonewright' ), 'read', SiteProfile::AUTHORIZE_PAGE, [ self::class, 'render_page' ] );
		$consent = add_submenu_page( 'options.php', __( 'Approve application', 'stonewright' ), __( 'Approve application', 'stonewright' ), 'read', SiteProfile::CONSENT_PAGE, [ self::class, 'render_page' ] );
		if ( is_string( $authorize ) && '' !== $authorize ) {
			add_action( 'load-' . $authorize, [ self::class, 'load_authorize' ] );
		}
		if ( is_string( $consent ) && '' !== $consent ) {
			add_action( 'load-' . $consent, [ self::class, 'load_consent' ] );
		}
	}

	/** Answers the authorization page on its load- hook, before any admin output. */
	public static function load_authorize(): void {
		self::send_security_headers();
		try {
			$outcome = self::compose()->authorize( self::query_string(), get_current_user_id() );
		} catch ( \Throwable $failure ) {
			$outcome = self::unavailable( $failure );
		}
		HttpSurface::audit_page( 'oauth/authorize', $outcome );
		self::finish( $outcome );
	}

	/** Decides a consent POST, or prepares the consent form, on the page's load- hook. */
	public static function load_consent(): void {
		self::send_security_headers();
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_key( (string) $_SERVER['REQUEST_METHOD'] ) ) : 'GET';
		try {
			$pages = self::compose();
			if ( 'POST' === $method ) {
				$form = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- decide() verifies the nonce bound to the pending request.
				$outcome = $pages->decide( self::query_string(), is_array( $form ) ? $form : [], get_current_user_id() );
			} else {
				$outcome = $pages->review( self::query_string(), get_current_user_id() );
			}
		} catch ( \Throwable $failure ) {
			$outcome = self::unavailable( $failure );
		}
		if ( PageOutcome::VIEW === $outcome->kind ) {
			self::$view = $outcome->view;
			return;
		}
		if ( 'POST' === $method ) {
			HttpSurface::audit_page( 'oauth/consent', $outcome );
		}
		self::finish( $outcome );
	}

	/** Page callback: the consent form prepared by load_consent(). */
	public static function render_page(): void {
		if ( [] === self::$view ) {
			return;
		}
		echo self::render( self::$view ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render() escapes every value it composes.
	}

	/** @return list<string> Canonical resource identifiers; this site's resource when none is named. */
	private function resources( ParameterBag $parameters ): array {
		$requested = $parameters->values( 'resource' );
		if ( [] === $requested ) {
			return [ $this->site->resource() ];
		}
		return ( new ResourceRules() )->require_authorized( $requested, $this->site->resources() );
	}

	/** @throws OAuthFault When a scope is not advertised by this server (invalid_scope). */
	private static function require_scopes( ?string $scope ): void {
		$requested = array_filter( explode( ' ', (string) $scope ), static fn ( string $item ): bool => '' !== $item );
		if ( array_diff( $requested, SiteProfile::SUPPORTED_SCOPES ) ) {
			throw new OAuthFault( 'invalid_scope' );
		}
	}

	/** @return array<string, mixed>|null */
	private function client( string $client_id ): ?array {
		if ( ClientDocuments::is_document_url( $client_id ) ) {
			try {
				return $this->documents->resolve( $client_id );
			} catch ( OAuthFault | StorageFailure $refused ) {
				return null;
			}
		}
		$client = $this->storage->clients()->find( $client_id );
		// A document client is addressed by its URL, so the cached copy is always current.
		if ( null === $client || ClientStore::DOCUMENT_PURPOSE === $client['registration_purpose'] ) {
			return null;
		}
		return $client;
	}

	/** @param array<string, mixed> $audit */
	private function return_error( string $redirect, string $error, ?string $state, array $audit ): PageOutcome {
		$parameters = [
			'error'             => $error,
			'error_description' => self::DESCRIPTIONS[ $error ] ?? self::DESCRIPTIONS['invalid_request'],
		];
		if ( null !== $state ) {
			$parameters['state'] = $state;
		}
		$parameters['iss'] = $this->site->issuer();
		$audit['body'] = [ 'error' => $error ];
		return PageOutcome::redirect( self::callback( $redirect, $parameters ), true, $audit );
	}

	private static function unavailable( \Throwable $failure ): PageOutcome {
		Logger::warning( 'oauth_authorization_page_failed', [ 'error_class' => get_class( $failure ) ] );
		return PageOutcome::error( 500, __( 'Authorization is unavailable right now. Try again later.', 'stonewright' ) );
	}

	private static function expired(): PageOutcome {
		return PageOutcome::error( 400, __( 'This authorization request has expired or was already answered. Start the connection again from your application.', 'stonewright' ) );
	}

	private static function pending_key( string $query ): ?string {
		try {
			$key = ( new RequestDecoder() )->form( $query, self::MAXIMUM_QUERY_BYTES )->one( 'token' );
		} catch ( OAuthFault $malformed ) {
			return null;
		}
		return null !== $key && preg_match( '/^[0-9a-f]{32}$/D', $key ) ? $key : null;
	}

	/** @param array<string, string> $parameters */
	private static function callback( string $redirect, array $parameters ): string {
		return $redirect . ( str_contains( $redirect, '?' ) ? '&' : '?' ) . http_build_query( $parameters, '', '&', PHP_QUERY_RFC3986 );
	}

	/**
	 * Scheme, host and port of a callback, the part a user can recognize.
	 *
	 * The port is part of an origin, so it is kept when the callback names one: on a loopback
	 * address another local process could be listening on a different port.
	 */
	private static function origin( string $uri ): string {
		$parts = parse_url( $uri );
		if ( ! is_array( $parts ) || ! isset( $parts['scheme'], $parts['host'] ) ) {
			return '';
		}
		return $parts['scheme'] . '://' . $parts['host'] . ( isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '' );
	}

	private static function compose(): self {
		return new self( HttpSurface::site(), AuthorizationLifecycle::storage(), HttpSurface::documents(), HttpSurface::limiter() );
	}

	private static function query_string(): string {
		return isset( $_SERVER['QUERY_STRING'] ) ? (string) $_SERVER['QUERY_STRING'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Decoded and validated by RequestDecoder.
	}

	private static function send_security_headers(): void {
		if ( headers_sent() ) {
			return;
		}
		nocache_headers();
		foreach ( self::SECURITY_HEADERS as $name => $value ) {
			header( $name . ': ' . $value );
		}
	}

	private static function finish( PageOutcome $outcome ): void {
		if ( PageOutcome::REDIRECT === $outcome->kind ) {
			if ( $outcome->external ) {
				// The location is a callback the client registered, matched by RedirectRules.
				wp_redirect( $outcome->location, 302, 'WordPress' ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- Registered client callback.
			} else {
				wp_safe_redirect( $outcome->location, 302, 'WordPress' );
			}
			exit;
		}
		wp_die(
			esc_html( $outcome->message ),
			esc_html__( 'Authorization error', 'stonewright' ),
			[
				'response'  => (int) $outcome->status,
				'back_link' => false,
			]
		);
	}
}
