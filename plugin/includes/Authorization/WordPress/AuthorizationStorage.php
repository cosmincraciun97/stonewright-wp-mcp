<?php
/**
 * Composition of the WordPress authorization adapters.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Authorization\WordPress;

use Stonewright\WpMcp\Authorization\Ports\Clock;
use Stonewright\WpMcp\Authorization\Ports\IdentifierSource;
use Stonewright\WpMcp\Authorization\Refresh\RefreshPolicy;

/**
 * Builds every port adapter once over one database connection, so a transaction
 * started by one adapter covers the writes of the others. The site factory derives the
 * issuer, the resource identifiers (pretty and plain permalink forms) and the
 * duplicate window filter from the running site.
 */
final class AuthorizationStorage {

	public const RESOURCE_PATH = 'mcp/stonewright-oauth';
	public const DUPLICATE_WINDOW_FILTER = 'stonewright_oauth_refresh_reuse_window';

	private ?StorageTables $tables = null;
	private ?ClientStore $clients = null;
	private ?AccessTokenStore $access = null;
	private ?FamilyStore $families = null;
	private ?CodeStore $codes = null;
	private ?PendingConsentStore $consents = null;
	private ?TokenCodec $codec = null;
	private ?PermissionSubjectAuthority $subjects = null;
	private RefreshPolicy $policy;
	private CredentialKeys $keys;

	/**
	 * @param list<string>                        $resources      Resource identifiers; the first is canonical.
	 * @param (\Closure(list<string>): mixed)|null $schema_delta   Applies CREATE TABLE statements (dbDelta by default).
	 * @param (\Closure(): string)|null           $binding_secret Secret outside the database for refresh credentials.
	 * @throws \InvalidArgumentException When no resource identifier is given.
	 */
	public function __construct(
		private Database $db,
		private Clock $clock,
		private IdentifierSource $identifiers,
		private string $issuer,
		private array $resources,
		int $duplicate_window = RefreshPolicy::DEFAULT_DUPLICATE_WINDOW,
		private ?\Closure $schema_delta = null,
		private ?\Closure $binding_secret = null,
		?CredentialKeys $keys = null
	) {
		if ( [] === $resources ) {
			throw new \InvalidArgumentException( 'At least one resource identifier is required.' );
		}
		$this->policy = new RefreshPolicy( $duplicate_window );
		$this->keys = $keys ?? new CredentialKeys( $db );
	}

	public static function wordpress(): self {
		$home = self::site_issuer();
		$window = apply_filters( self::DUPLICATE_WINDOW_FILTER, RefreshPolicy::DEFAULT_DUPLICATE_WINDOW );
		return new self( Database::wordpress(), new SystemClock(), new RandomIdentifiers(), $home, self::site_resources( $home ), is_numeric( $window ) ? (int) $window : RefreshPolicy::DEFAULT_DUPLICATE_WINDOW );
	}

	/** The issuer of the running site: its home URL without a trailing slash. */
	public static function site_issuer(): string {
		return rtrim( (string) home_url( '/' ), '/' );
	}

	/**
	 * Resource identifiers the running site answers for: the REST URL of the current
	 * permalink mode first, then the pretty and plain forms, so credentials issued under
	 * either mode stay valid after a permalink change.
	 *
	 * @return list<string>
	 */
	public static function site_resources( string $home ): array {
		return array_values(
			array_unique(
				[
					rtrim( (string) rest_url( self::RESOURCE_PATH ), '/' ),
					$home . '/' . trim( (string) rest_get_url_prefix(), '/' ) . '/' . self::RESOURCE_PATH,
					$home . '/index.php?rest_route=/' . self::RESOURCE_PATH,
				]
			)
		);
	}

	public function database(): Database {
		return $this->db;
	}

	public function clock(): Clock {
		return $this->clock;
	}

	public function identifiers(): IdentifierSource {
		return $this->identifiers;
	}

	public function issuer(): string {
		return $this->issuer;
	}

	/** @return list<string> */
	public function resources(): array {
		return $this->resources;
	}

	public function policy(): RefreshPolicy {
		return $this->policy;
	}

	public function keys(): CredentialKeys {
		return $this->keys;
	}

	public function tables(): StorageTables {
		return $this->tables ??= new StorageTables( $this->db, $this->schema_delta );
	}

	public function clients(): ClientStore {
		return $this->clients ??= new ClientStore( $this->db, $this->clock );
	}

	public function access_tokens(): AccessTokenStore {
		return $this->access ??= new AccessTokenStore( $this->db );
	}

	public function families(): FamilyStore {
		return $this->families ??= new FamilyStore( $this->db, $this->access_tokens(), $this->clients(), $this->clock, $this->resources );
	}

	public function codes(): CodeStore {
		return $this->codes ??= new CodeStore( $this->db, $this->families() );
	}

	public function consents(): PendingConsentStore {
		return $this->consents ??= new PendingConsentStore( $this->db, $this->codes(), $this->clock );
	}

	public function codec(): TokenCodec {
		return $this->codec ??= new TokenCodec( $this->keys, $this->families(), $this->access_tokens(), $this->clock, $this->issuer, $this->resources, $this->binding_secret );
	}

	public function subjects(): PermissionSubjectAuthority {
		return $this->subjects ??= new PermissionSubjectAuthority();
	}

	public function validator(): AccessTokenValidator {
		return new AccessTokenValidator( $this->codec(), $this->access_tokens(), $this->subjects(), $this->clock );
	}

	public function revocation(): CredentialRevocation {
		return new CredentialRevocation( $this->codec(), $this->families(), $this->access_tokens() );
	}

	public function housekeeping(): Housekeeping {
		return new Housekeeping( $this->db, $this->families(), $this->clients(), $this->clock, $this->policy->duplicate_window() );
	}

	public function key_notice(): KeyRecoveryNotice {
		return new KeyRecoveryNotice( $this->keys );
	}
}
