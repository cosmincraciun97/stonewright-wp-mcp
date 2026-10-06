<?php
/**
 * Result of an authorization page request.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Authorization\WordPress;

/**
 * One of: a redirect (to the consent screen on this site, or to a validated client
 * callback), an error page (no redirect), or the consent view to render. Audit facts
 * never hold credentials.
 */
final class PageOutcome {

	public const REDIRECT = 'redirect';
	public const ERROR = 'error';
	public const VIEW = 'view';

	/**
	 * @param array<string, string> $view
	 * @param array<string, mixed>  $audit
	 */
	private function __construct( public readonly string $kind, public readonly int $status, public readonly string $location, public readonly bool $external, public readonly string $message, public readonly array $view, public readonly array $audit ) {}

	/** @param array<string, mixed> $audit */
	public static function redirect( string $location, bool $external, array $audit = [] ): self {
		return new self( self::REDIRECT, 302, $location, $external, '', [], $audit );
	}

	/** @param array<string, mixed> $audit */
	public static function error( int $status, string $message, array $audit = [] ): self {
		return new self( self::ERROR, $status, '', false, $message, [], $audit );
	}

	/**
	 * @param array<string, string> $view
	 * @param array<string, mixed>  $audit
	 */
	public static function view( array $view, array $audit = [] ): self {
		return new self( self::VIEW, 200, '', false, '', $view, $audit );
	}
}
