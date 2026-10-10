<?php
/**
 * Consent decision from authenticated adapter facts.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Authorization\Decisions;

use Stonewright\WpMcp\Authorization\Model\OAuthFault;
use Stonewright\WpMcp\Authorization\Protocol\CodeProof;
use Stonewright\WpMcp\Authorization\Protocol\RedirectRules;
use Stonewright\WpMcp\Authorization\Protocol\ResourceRules;
use Stonewright\WpMcp\Authorization\Protocol\ScopeRules;

/** Pending facts must be server-bound; integrity and permission flags must never come from request parameters. */
final class ConsentDecision {

	public function __construct( private int $code_lifetime = 300 ) {
		if ( $code_lifetime < 1 || $code_lifetime > 600 ) {
			throw new \InvalidArgumentException( 'Invalid authorization code lifetime.' );
		}
	}

	public function decide( array $pending, string $subject_key, bool $form_integrity, bool $subject_allowed, bool $approved, int $now, string $code_key ): array {
		if ( '' === $subject_key || ! $form_integrity || ! $subject_allowed ) {
			throw new OAuthFault( 'access_denied', 403 );
		}
		foreach ( [ 'pending_key', 'client_key', 'redirect_uri', 'code_challenge', 'code_challenge_method' ] as $name ) {
			if ( ! isset( $pending[ $name ] ) || ! is_string( $pending[ $name ] ) || '' === $pending[ $name ] ) {
				throw new OAuthFault( 'invalid_request' );
			}
		}
		if ( ! isset( $pending['expires_at'], $pending['used'], $pending['native_client'], $pending['registered_redirects'], $pending['scopes'], $pending['resources'] ) || ! is_int( $pending['expires_at'] ) || ! is_bool( $pending['used'] ) || ! is_bool( $pending['native_client'] ) || ! is_array( $pending['registered_redirects'] ) || ! is_array( $pending['scopes'] ) || ! is_array( $pending['resources'] ) || $pending['used'] || $now < 0 || $now >= $pending['expires_at'] || $now > PHP_INT_MAX - $this->code_lifetime || ( isset( $pending['state'] ) && ! is_string( $pending['state'] ) ) ) {
			throw new OAuthFault( 'invalid_request' );
		}
		$redirect = ( new RedirectRules() )->approve( $pending['redirect_uri'], $pending['registered_redirects'], $pending['native_client'] );
		( new CodeProof() )->require_s256( $pending['code_challenge_method'], $pending['code_challenge'] );
		$scopes = ( new ScopeRules() )->normalize( $pending['scopes'] );
		$resources = ( new ResourceRules() )->require_authorized( $pending['resources'], $pending['resources'] );
		if ( $approved && '' === $code_key ) {
			throw new OAuthFault( 'server_error', 500 );
		}
		$next = array_replace( $pending, [ 'used' => true ] );
		$parameters = $approved ? [ 'code_key' => $code_key ] : [ 'error' => 'access_denied' ];
		if ( isset( $pending['state'] ) ) {
			$parameters['state'] = $pending['state'];
		}
		$code = $approved ? [ 'code_key' => $code_key, 'client_key' => $pending['client_key'], 'subject_key' => $subject_key, 'redirect_uri' => $redirect, 'code_challenge' => $pending['code_challenge'], 'scopes' => $scopes, 'resources' => $resources, 'expires_at' => min( $pending['expires_at'], $now + $this->code_lifetime ), 'used' => false, 'issued_family_key' => null ] : null;
		return [ 'pending' => $next, 'code' => $code, 'redirect_uri' => $redirect, 'redirect_parameters' => $parameters ];
	}
}
