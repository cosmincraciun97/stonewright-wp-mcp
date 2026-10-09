<?php
/**
 * Loopback health probe after a risky write.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Security;

/**
 * Asks the site, over HTTP from the site, whether it still loads.
 *
 * A probe is a few short requests, called legs: the home page, a wp-admin screen (reached with
 * the internal ProbeToken, never the user's cookie or an Application Password), the REST index
 * and, after a post write, the post itself (for a kit, the public front page, which shows the kit's
 * styles). Each leg is judged on what it returns. A fatal is
 * a failure: HTTP 500, the WordPress critical-error page, or PHP's own fatal text in the body.
 * A host that blocks loopback requests, a login wall, a gateway error or a timeout is not a
 * failure and not a success: the leg is "unavailable", and a probe with no passing leg is
 * "unavailable" as a whole. Nothing here ever says the site is healthy without a passing leg.
 *
 * Evidence is kept small: leg names, statuses, HTTP codes and short reasons. It never holds a
 * URL, a header or a response body.
 */
final class HealthProbe {

	public const TIMEOUT     = 10;
	public const BODY_LIMIT  = 262144;
	public const BUDGET_SECS = 30;

	/** Seconds a request waits while loopback requests are known to fail, and how long that is remembered. */
	public const QUICK_TIMEOUT = 3;
	public const COOLDOWN_KEY  = 'stonewright_probe_loopback_failing';
	public const COOLDOWN_SECS = 600;

	private const LEGS = [ 'home', 'admin', 'rest', 'post', 'custom' ];

	/** @var callable(string,array<string,mixed>):mixed|null */
	private static $transport = null;

	/**
	 * Replace the HTTP transport. It receives a URL and wp_remote_get() style arguments and
	 * returns a wp_remote_get() style response or a WP_Error. For tests and unusual hosts.
	 *
	 * @param callable(string,array<string,mixed>):mixed|null $transport
	 */
	public static function set_transport( ?callable $transport ): void {
		self::$transport = $transport;
	}

	/**
	 * The legs that fit a kind of change.
	 *
	 * @return list<string>
	 */
	public static function legs_for( string $scope ): array {
		return match ( $scope ) {
			'light' => [ 'home' ],
			'post'  => [ 'post' ],
			default => [ 'home', 'admin', 'rest' ],
		};
	}

	/**
	 * Run a probe.
	 *
	 * @param array{legs?:list<string>,user_id?:int,post_id?:int,timeout?:int} $context
	 * @return array{status:string,fatal:bool,coverage:string,legs:list<array<string,mixed>>,checked_at:int,unavailable_reason?:string}
	 */
	public static function run( array $context = [] ): array {
		$requested = isset( $context['legs'] ) && is_array( $context['legs'] ) ? $context['legs'] : self::legs_for( 'site' );
		$legs      = array_values( array_intersect( self::LEGS, array_map( 'strval', $requested ) ) );

		if ( true !== apply_filters( 'stonewright_rescue_probe_enabled', true, $context ) ) {
			return self::summarize( [], 'disabled' );
		}

		// A host whose loopback requests fail would make every write wait for every leg to time out.
		// While that holds, a probe is one short request until the site answers again.
		$quick = false !== get_transient( self::COOLDOWN_KEY );
		if ( $quick ) {
			$legs               = array_slice( $legs, 0, 1 );
			$context['timeout'] = self::QUICK_TIMEOUT;
		}

		$started = microtime( true );
		$results = [];
		foreach ( $legs as $leg ) {
			if ( microtime( true ) - $started > self::BUDGET_SECS ) {
				$results[] = [ 'leg' => $leg, 'status' => 'skipped', 'http' => 0, 'reason' => 'budget', 'ms' => 0 ];
				continue;
			}
			$results[] = self::probe_leg( $leg, $context );
		}
		$summary = self::summarize( $results );
		self::track_loopback( $summary );
		return $summary;
	}

	/**
	 * Remember that loopback requests fail (every leg failed to connect), or forget it once any
	 * leg gets an answer. An HTTP status of any kind is an answer.
	 *
	 * @param array<string, mixed> $summary
	 */
	private static function track_loopback( array $summary ): void {
		$attempted = 0;
		$no_answer = 0;
		$answered  = false;
		foreach ( is_array( $summary['legs'] ?? null ) ? $summary['legs'] : [] as $leg ) {
			if ( 'skipped' === $leg['status'] || 'token_unavailable' === $leg['reason'] ) {
				continue;
			}
			++$attempted;
			if ( 0 === (int) $leg['http'] ) {
				++$no_answer;
			} else {
				$answered = true;
			}
		}
		if ( $answered ) {
			delete_transient( self::COOLDOWN_KEY );
		} elseif ( $attempted > 0 && $attempted === $no_answer ) {
			set_transient( self::COOLDOWN_KEY, 1, self::COOLDOWN_SECS );
		}
	}

	/**
	 * Judge one response.
	 *
	 * @return array{status:string,reason:string}
	 */
	public static function classify( int $http, string $body, string $leg = 'home', string $marker = '' ): array {
		$body = substr( $body, 0, self::BODY_LIMIT );

		if ( $http >= 500 && ( str_contains( $body, 'id="error-page"' ) || false !== stripos( $body, 'critical error' ) ) ) {
			return [ 'status' => 'failed', 'reason' => 'critical_error_page' ];
		}
		if ( 1 === preg_match( '/(?:Fatal error|Parse error|Uncaught (?:Error|Exception|TypeError|ParseError|Throwable)|Allowed memory size of|Maximum execution time of)\b.{0,500}?(?:\sin\s+\S+(?:\s+on line\s+\d+|:\d+)|thrown in\s)/s', $body ) ) {
			return [ 'status' => 'failed', 'reason' => 'fatal_marker' ];
		}
		if ( 500 === $http ) {
			return [ 'status' => 'failed', 'reason' => 'http_500' ];
		}
		if ( $http < 100 ) {
			return [ 'status' => 'unavailable', 'reason' => 'no_response' ];
		}
		if ( $http >= 300 && $http < 400 ) {
			// A redirect that was not followed, or too many of them: nothing was seen of the page.
			return [ 'status' => 'unavailable', 'reason' => 'redirect' ];
		}
		if ( $http >= 400 && ! ( 404 === $http && 'home' === $leg ) ) {
			return [ 'status' => 'unavailable', 'reason' => 'http_' . $http ];
		}

		if ( 'admin' === $leg ) {
			if ( str_contains( $body, 'id="loginform"' ) ) {
				return [ 'status' => 'unavailable', 'reason' => 'login_required' ];
			}
			if ( '' !== $marker && ! str_contains( $body, $marker ) ) {
				return [ 'status' => 'unavailable', 'reason' => 'unexpected_body' ];
			}
		}
		if ( 'rest' === $leg && ! str_contains( $body, '"namespaces"' ) ) {
			return [ 'status' => 'unavailable', 'reason' => 'unexpected_body' ];
		}
		return [ 'status' => 'passed', 'reason' => 'ok' ];
	}

	/**
	 * Judge a probe taken after a change against the one taken before it.
	 *
	 * On its own, a leg that cannot be reached (a refused connection, a timeout, an empty answer, any
	 * 5xx) proves nothing. When the same leg passed before the change, that silence is the change's
	 * doing: a change that hangs or crashes the server must not be kept for want of an answer. Such a
	 * leg counts as failed. A leg that did not pass before, and any other reason for no answer (a login
	 * wall, a page that is not the page, a refusal), is left as it is.
	 *
	 * @param array<string, mixed> $probe         A probe taken after the change.
	 * @param list<string>         $passed_before Names of the legs that passed before it.
	 * @return array<string, mixed>
	 */
	public static function compare( array $probe, array $passed_before ): array {
		if ( [] === $passed_before || ! is_array( $probe['legs'] ?? null ) ) {
			return $probe;
		}
		$legs     = [];
		$degraded = false;
		foreach ( $probe['legs'] as $leg ) {
			if ( is_array( $leg )
				&& 'unavailable' === ( $leg['status'] ?? '' )
				&& in_array( (string) ( $leg['leg'] ?? '' ), $passed_before, true )
				&& self::is_degradation( $leg )
			) {
				$leg['reason'] = substr( 'degraded_' . (string) ( $leg['reason'] ?? '' ), 0, 48 );
				$leg['status'] = 'failed';
				$degraded      = true;
			}
			$legs[] = $leg;
		}
		if ( ! $degraded ) {
			return $probe;
		}
		$judged               = self::summarize( $legs );
		$judged['checked_at'] = (int) ( $probe['checked_at'] ?? time() );
		return $judged;
	}

	/**
	 * A probe made of legs that were taken one at a time.
	 *
	 * @param list<array<string, mixed>> $legs
	 * @return array{status:string,fatal:bool,coverage:string,legs:list<array<string,mixed>>,checked_at:int,unavailable_reason?:string}
	 */
	public static function summary_of( array $legs ): array {
		return self::summarize( $legs );
	}

	/**
	 * Whether an unavailable leg is the site failing to answer, rather than the probe failing to ask:
	 * a transport error or an empty answer (HTTP 0), or a server error. A token that could not be
	 * stored is the probe's own trouble.
	 *
	 * @param array<string, mixed> $leg
	 */
	private static function is_degradation( array $leg ): bool {
		$http = (int) ( $leg['http'] ?? 0 );
		if ( $http >= 500 ) {
			return true;
		}
		return 0 === $http && 'token_unavailable' !== (string) ( $leg['reason'] ?? '' );
	}

	/**
	 * A summary shaped like the one the theme write used to report from its fresh-bootstrap smoke.
	 *
	 * @param array<string, mixed> $probe
	 * @return array<string, mixed>
	 */
	public static function smoke_summary( array $probe ): array {
		$status = (string) ( $probe['status'] ?? 'unavailable' );
		$legs   = is_array( $probe['legs'] ?? null ) ? $probe['legs'] : [];
		$focus  = null;
		foreach ( $legs as $leg ) {
			if ( is_array( $leg ) && ( 'failed' === ( $leg['status'] ?? '' ) ) ) {
				$focus = $leg;
				break;
			}
		}
		if ( null === $focus ) {
			foreach ( $legs as $leg ) {
				if ( is_array( $leg ) && 'passed' === ( $leg['status'] ?? '' ) ) {
					$focus = $leg;
					break;
				}
			}
		}
		return [
			'status' => 'unavailable' === $status ? 'skipped' : $status,
			'reason' => is_array( $focus ) ? (string) ( $focus['reason'] ?? '' ) : (string) ( $probe['unavailable_reason'] ?? 'probe_unavailable' ),
			'http'   => is_array( $focus ) ? (int) ( $focus['http'] ?? 0 ) : 0,
			'legs'   => $legs,
		];
	}

	/**
	 * @param array<string, mixed> $context
	 * @return array{leg:string,status:string,http:int,reason:string,ms:int}
	 */
	private static function probe_leg( string $leg, array $context ): array {
		$user_id = isset( $context['user_id'] ) ? (int) $context['user_id'] : 0;
		$nonce   = bin2hex( random_bytes( 8 ) );
		$headers = [
			'Cache-Control' => 'no-cache',
			'Pragma'        => 'no-cache',
		];
		$marker = '';

		switch ( $leg ) {
			case 'rest':
				$url = add_query_arg( ProbeToken::PARAM, $nonce, rest_url( '/' ) );
				break;
			case 'admin':
				if ( $user_id < 1 ) {
					return self::leg_result( $leg, 'skipped', 0, 'no_user', 0 );
				}
				if ( user_can( $user_id, 'manage_options' ) ) {
					$url    = add_query_arg( ProbeToken::PARAM, $nonce, admin_url( 'admin.php?page=stonewright-rescue' ) );
					$marker = 'data-sw-rescue-probe="ok"';
				} else {
					$url    = add_query_arg( ProbeToken::PARAM, $nonce, admin_url( 'index.php' ) );
					$marker = 'wp-admin';
				}
				$token = ProbeToken::issue( $user_id, (string) wp_parse_url( $url, PHP_URL_PATH ), $nonce );
				if ( null === $token ) {
					return self::leg_result( $leg, 'unavailable', 0, 'token_unavailable', 0 );
				}
				$headers[ ProbeToken::HEADER ] = $token;
				break;
			case 'post':
				$post_id = isset( $context['post_id'] ) ? (int) $context['post_id'] : 0;
				$target  = self::post_url( $post_id, $user_id );
				if ( null === $target ) {
					return self::leg_result( $leg, 'skipped', 0, 'not_viewable', 0 );
				}
				$url = add_query_arg( ProbeToken::PARAM, $nonce, $target['url'] );
				if ( $target['needs_login'] ) {
					$token = ProbeToken::issue( $user_id, (string) wp_parse_url( $url, PHP_URL_PATH ), $nonce );
					if ( null === $token ) {
						return self::leg_result( $leg, 'unavailable', 0, 'token_unavailable', 0 );
					}
					$headers[ ProbeToken::HEADER ] = $token;
				}
				break;
			case 'custom':
				$target = isset( $context['url'] ) && is_string( $context['url'] ) ? self::same_site_url( $context['url'] ) : null;
				if ( null === $target ) {
					return self::leg_result( $leg, 'skipped', 0, 'foreign_host', 0 );
				}
				$url = add_query_arg( ProbeToken::PARAM, $nonce, $target );
				break;
			default:
				$url = add_query_arg( ProbeToken::PARAM, $nonce, home_url( '/' ) );
		}

		// A request that carries the probe token never follows a redirect, so the token cannot be sent to
		// wherever a redirect points. A caller-chosen URL is not followed either.
		$redirects = isset( $headers[ ProbeToken::HEADER ] ) || 'custom' === $leg ? 0 : 2;
		$args      = [
			'timeout'             => isset( $context['timeout'] ) ? max( 1, min( 15, (int) $context['timeout'] ) ) : self::TIMEOUT,
			'redirection'         => $redirects,
			'sslverify'           => (bool) apply_filters( 'https_local_ssl_verify', false ),
			'limit_response_size' => self::BODY_LIMIT,
			'headers'             => $headers,
		];
		$args = apply_filters( 'stonewright_rescue_probe_args', $args, $leg );

		$begun    = hrtime( true );
		$response = ( self::$transport ?? 'wp_remote_get' )( $url, $args );
		$ms       = (int) ( ( hrtime( true ) - $begun ) / 1_000_000 );

		if ( is_wp_error( $response ) ) {
			return self::leg_result( $leg, 'unavailable', 0, self::error_reason( $response ), $ms );
		}
		$http  = (int) wp_remote_retrieve_response_code( $response );
		$judge = self::classify( $http, (string) wp_remote_retrieve_body( $response ), $leg, $marker );
		return self::leg_result( $leg, $judge['status'], $http, $judge['reason'], $ms );
	}

	/**
	 * Where to look at a post, and whether the request must be logged in.
	 *
	 * @return array{url:string,needs_login:bool}|null Null when the post has no front-end page.
	 */
	private static function post_url( int $post_id, int $user_id ): ?array {
		if ( $post_id < 1 ) {
			return null;
		}
		$status = get_post_status( $post_id );
		if ( false === $status || 'trash' === $status ) {
			return null;
		}
		if ( self::is_kit( $post_id ) ) {
			// A kit has no front-end page of its own; its styles show on the public front page.
			return [ 'url' => home_url( '/' ), 'needs_login' => false ];
		}
		if ( 'publish' === $status ) {
			$link = get_permalink( $post_id );
			return is_string( $link ) && '' !== $link ? [ 'url' => $link, 'needs_login' => false ] : null;
		}
		if ( $user_id < 1 || ! user_can( $user_id, 'edit_post', $post_id ) ) {
			return null;
		}
		$preview = get_preview_post_link( $post_id );
		return is_string( $preview ) && '' !== $preview ? [ 'url' => $preview, 'needs_login' => true ] : null;
	}

	/**
	 * Whether a post is an Elementor kit (site-wide styles): the active kit, or a library post whose
	 * template type is kit.
	 */
	private static function is_kit( int $post_id ): bool {
		if ( $post_id === (int) get_option( 'elementor_active_kit', 0 ) ) {
			return true;
		}
		return 'elementor_library' === get_post_type( $post_id )
			&& 'kit' === get_post_meta( $post_id, '_elementor_template_type', true );
	}

	/**
	 * A caller-supplied URL, only when it is an http(s) URL on this site's own host. The server
	 * is never made to request another host.
	 */
	private static function same_site_url( string $url ): ?string {
		$parts = wp_parse_url( trim( $url ) );
		$home  = wp_parse_url( home_url( '/' ) );
		if ( ! is_array( $parts ) || ! is_array( $home ) || empty( $parts['host'] ) || empty( $home['host'] ) ) {
			return null;
		}
		$scheme      = strtolower( (string) ( $parts['scheme'] ?? '' ) );
		$home_scheme = strtolower( (string) ( $home['scheme'] ?? '' ) );
		// Exactly the home URL's origin: same scheme, same host, same port, no credentials.
		if ( ! in_array( $scheme, [ 'http', 'https' ], true )
			|| $scheme !== $home_scheme
			|| 0 !== strcasecmp( (string) $parts['host'], (string) $home['host'] )
			|| self::port( $parts, $scheme ) !== self::port( $home, $home_scheme )
			|| isset( $parts['user'] )
			|| isset( $parts['pass'] )
		) {
			return null;
		}
		return trim( $url );
	}

	/**
	 * The port of a parsed URL, with the scheme's default when none is written.
	 *
	 * @param array<string, mixed> $parts
	 */
	private static function port( array $parts, string $scheme ): int {
		return isset( $parts['port'] ) ? (int) $parts['port'] : ( 'https' === $scheme ? 443 : 80 );
	}

	private static function error_reason( \WP_Error $error ): string {
		$code = sanitize_key( (string) $error->get_error_code() );
		return '' !== $code ? substr( $code, 0, 48 ) : 'request_failed';
	}

	/**
	 * @return array{leg:string,status:string,http:int,reason:string,ms:int}
	 */
	private static function leg_result( string $leg, string $status, int $http, string $reason, int $ms ): array {
		return [
			'leg'    => $leg,
			'status' => $status,
			'http'   => $http,
			'reason' => $reason,
			'ms'     => $ms,
		];
	}

	/**
	 * @param list<array<string, mixed>> $results
	 * @return array{status:string,fatal:bool,coverage:string,legs:list<array<string,mixed>>,checked_at:int,unavailable_reason?:string}
	 */
	private static function summarize( array $results, string $forced_reason = '' ): array {
		$failed = false;
		$passed = 0;
		$counted = 0;
		$reason = $forced_reason;
		foreach ( $results as $result ) {
			if ( 'skipped' === $result['status'] ) {
				continue;
			}
			++$counted;
			if ( 'failed' === $result['status'] ) {
				$failed = true;
			} elseif ( 'passed' === $result['status'] ) {
				++$passed;
			} elseif ( '' === $reason ) {
				$reason = (string) $result['reason'];
			}
		}
		$status   = $failed ? 'failed' : ( $passed > 0 ? 'passed' : 'unavailable' );
		$coverage = 0 === $passed ? 'none' : ( $passed === $counted ? 'full' : 'partial' );
		$summary  = [
			'status'     => $status,
			'fatal'      => $failed,
			'coverage'   => $coverage,
			'legs'       => $results,
			'checked_at' => time(),
		];
		if ( 'unavailable' === $status ) {
			$summary['unavailable_reason'] = '' !== $reason ? $reason : 'no_evidence';
		}
		return $summary;
	}
}
