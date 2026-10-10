<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Authorization\WordPress\Fixtures;

use Defuse\Crypto\Crypto;

/**
 * Synthetic rows and credentials in the shape the previous release left behind
 * (schema version 4): refresh rows without client or subject, a fixed 14-day family
 * deadline, rotated/replayed markers, and refresh payloads in the public payload
 * format. Identifier hashes are random opaque values; nothing here derives them.
 */
final class LegacyRows {

	public const CLIENT = '0123456789abcdef0123456789abcdef';
	public const SUBJECT = '7';
	public const FAMILY_LIFETIME = 1209600;

	/** Observed columns of the six tables, in observed order. */
	public const OBSERVED_COLUMNS = [
		'clients'        => [ 'id', 'client_id', 'client_name', 'redirect_uris', 'is_confidential', 'client_secret_hash', 'created_at', 'last_used_at', 'registered_by_ip_hash', 'admin_created', 'registration_purpose', 'registration_expires_at' ],
		'auth_codes'     => [ 'identifier_hash', 'client_id', 'user_id', 'expires_at', 'scopes', 'redirect_uri', 'revoked' ],
		'access_tokens'  => [ 'identifier_hash', 'client_id', 'user_id', 'expires_at', 'scopes', 'revoked' ],
		'refresh_tokens' => [ 'identifier_hash', 'access_token_hash', 'grant_family_hash', 'client_id', 'user_id', 'parent_identifier_hash', 'family_expires_at', 'consumed_at', 'revoked_reason', 'expires_at', 'revoked' ],
		'rate_limits'    => [ 'bucket_key', 'window_started', 'hits', 'updated_at' ],
		'rate_metrics'   => [ 'metric_bucket', 'window_started', 'fingerprint_key', 'limited_requests', 'cooldown_until', 'updated_at' ],
	];

	public function __construct( private FakeTables $space, private int $now, private string $prefix = 'wptests_' ) {}

	/** Create the six tables exactly as schema version 4 declared them. */
	public static function install_version_four( FakeTables $space, string $prefix = 'wptests_' ): void {
		$p = $prefix . 'stonewright_oauth_';
		$space->apply_ddl(
			[
				"CREATE TABLE {$p}clients (\nid bigint unsigned NOT NULL AUTO_INCREMENT,\nclient_id varchar(64) NOT NULL,\nclient_name varchar(191) NOT NULL,\nredirect_uris text NOT NULL,\nis_confidential tinyint(1) NOT NULL DEFAULT '0',\nclient_secret_hash varchar(255) DEFAULT NULL,\ncreated_at datetime NOT NULL,\nlast_used_at datetime DEFAULT NULL,\nregistered_by_ip_hash char(64) NOT NULL,\nadmin_created tinyint(1) NOT NULL DEFAULT '0',\nregistration_purpose varchar(64) DEFAULT NULL,\nregistration_expires_at datetime DEFAULT NULL,\nPRIMARY KEY  (id),\nUNIQUE KEY client_id (client_id),\nKEY registration_expires_at (registration_expires_at)\n)",
				"CREATE TABLE {$p}auth_codes (\nidentifier_hash char(64) NOT NULL,\nclient_id varchar(64) NOT NULL,\nuser_id bigint unsigned NOT NULL,\nexpires_at datetime NOT NULL,\nscopes text NOT NULL,\nredirect_uri text NOT NULL,\nrevoked tinyint(1) NOT NULL DEFAULT '0',\nPRIMARY KEY  (identifier_hash),\nKEY expires_at (expires_at)\n)",
				"CREATE TABLE {$p}access_tokens (\nidentifier_hash char(64) NOT NULL,\nclient_id varchar(64) NOT NULL,\nuser_id bigint unsigned NOT NULL,\nexpires_at datetime NOT NULL,\nscopes text NOT NULL,\nrevoked tinyint(1) NOT NULL DEFAULT '0',\nPRIMARY KEY  (identifier_hash),\nKEY expires_at (expires_at),\nKEY user_id (user_id)\n)",
				"CREATE TABLE {$p}refresh_tokens (\nidentifier_hash char(64) NOT NULL,\naccess_token_hash char(64) NOT NULL,\ngrant_family_hash char(64) NOT NULL,\nclient_id varchar(64) DEFAULT NULL,\nuser_id bigint unsigned DEFAULT NULL,\nparent_identifier_hash char(64) DEFAULT NULL,\nfamily_expires_at datetime DEFAULT NULL,\nconsumed_at datetime DEFAULT NULL,\nrevoked_reason varchar(64) DEFAULT NULL,\nexpires_at datetime NOT NULL,\nrevoked tinyint(1) NOT NULL DEFAULT '0',\nPRIMARY KEY  (identifier_hash),\nKEY expires_at (expires_at),\nKEY grant_family_hash (grant_family_hash),\nKEY family_expires_at (family_expires_at),\nKEY consumed_at (consumed_at)\n)",
				"CREATE TABLE {$p}rate_limits (\nbucket_key char(64) NOT NULL,\nwindow_started bigint(20) unsigned NOT NULL,\nhits bigint(20) unsigned NOT NULL DEFAULT '0',\nupdated_at datetime NOT NULL,\nPRIMARY KEY  (bucket_key),\nKEY updated_idx (updated_at)\n)",
				"CREATE TABLE {$p}rate_metrics (\nmetric_bucket char(64) NOT NULL,\nwindow_started bigint(20) unsigned NOT NULL,\nfingerprint_key char(64) NOT NULL,\nlimited_requests bigint(20) unsigned NOT NULL DEFAULT '0',\ncooldown_until bigint(20) unsigned NOT NULL DEFAULT '0',\nupdated_at datetime NOT NULL,\nPRIMARY KEY  (metric_bucket,window_started,fingerprint_key),\nKEY window_idx (window_started),\nKEY updated_idx (updated_at)\n)",
			]
		);
		$space->ddl = [];
	}

	public static function hex( int $bytes ): string {
		return bin2hex( random_bytes( $bytes ) );
	}

	public static function at( int $epoch ): string {
		return gmdate( 'Y-m-d H:i:s', $epoch );
	}

	/** Insert the client row the previous release wrote at registration. */
	public function client( string $client_id = self::CLIENT, ?int $last_used = null ): void {
		$this->insert(
			'clients',
			[
				'client_id'             => $client_id,
				'client_name'           => 'Synthetic earlier client',
				'redirect_uris'         => '["http://127.0.0.1:7999/callback"]',
				'is_confidential'       => '0',
				'client_secret_hash'    => null,
				'created_at'            => self::at( $this->now - 86400 ),
				'last_used_at'          => null === $last_used ? null : self::at( $last_used ),
				'registered_by_ip_hash' => hash( 'sha256', '192.0.2.10' ),
			]
		);
	}

	/**
	 * A family issued at $issued with $rotations rotations, one second apart.
	 * $ending is null (live head), 'replayed' (the last rotated row was replayed and
	 * the head revoked) or 'revoked' (the head was revoked through revocation).
	 *
	 * @return array{family_hash: string, rows: list<string>, jtis: list<string>, heads: list<string>, deadline: int, issued: int}
	 */
	public function family( string $tag, int $rotations, ?string $ending = null, ?int $issued = null, string $client_id = self::CLIENT ): array {
		unset( $tag );
		$issued ??= $this->now - 3600;
		$deadline = $issued + self::FAMILY_LIFETIME;
		$family_hash = self::hex( 32 );
		$rows = [];
		$jtis = [];
		$parent = null;
		for ( $index = 0; $index <= $rotations; $index++ ) {
			$created = $issued + $index;
			$jti = self::hex( 40 );
			$identifier = self::hex( 32 );
			$is_head = $index === $rotations;
			$reason = $is_head ? ( null === $ending ? null : ( 'replayed' === $ending ? 'replayed' : null ) ) : 'rotated';
			if ( ! $is_head && 'replayed' === $ending && $index === $rotations - 1 ) {
				$reason = 'replayed';
			}
			$this->insert(
				'refresh_tokens',
				[
					'identifier_hash'        => $identifier,
					'access_token_hash'      => hash( 'sha256', $jti ),
					'grant_family_hash'      => $family_hash,
					'client_id'              => null,
					'user_id'                => null,
					'parent_identifier_hash' => $parent,
					'family_expires_at'      => self::at( $deadline ),
					'consumed_at'            => $is_head ? null : self::at( $created + 1 ),
					'revoked_reason'         => $reason,
					'expires_at'             => self::at( $deadline ),
					'revoked'                => ( $is_head && null === $ending ) ? '0' : '1',
				]
			);
			$this->insert(
				'access_tokens',
				[
					'identifier_hash' => hash( 'sha256', $jti ),
					'client_id'       => $client_id,
					'user_id'         => self::SUBJECT,
					'expires_at'      => self::at( $created + 3600 ),
					'scopes'          => '["mcp"]',
					'revoked'         => $is_head && null === $ending ? '0' : '1',
				]
			);
			$rows[] = $identifier;
			$jtis[] = $jti;
			$parent = $identifier;
		}
		return [ 'family_hash' => $family_hash, 'rows' => $rows, 'jtis' => $jtis, 'heads' => [ $parent ], 'deadline' => $deadline, 'issued' => $issued ];
	}

	/**
	 * A refresh credential in the public payload format for row $index of $family.
	 * $password is the stored encryption key string, or another variant to try.
	 *
	 * @param array{jtis: list<string>, deadline: int} $family
	 */
	public static function refresh_payload( array $family, int $index, string $password, string $client_id = self::CLIENT ): string {
		$payload = json_encode(
			[
				'client_id'        => $client_id,
				'refresh_token_id' => self::hex( 40 ),
				'access_token_id'  => $family['jtis'][ $index ],
				'scopes'           => [ 'mcp' ],
				'user_id'          => self::SUBJECT,
				'expire_time'      => $family['deadline'],
			]
		);
		return Crypto::encryptWithPassword( (string) $payload, $password );
	}

	/** An authorization code payload in the same public format. */
	public static function code_payload( string $password ): string {
		$payload = json_encode(
			[
				'client_id'             => self::CLIENT,
				'redirect_uri'          => 'http://127.0.0.1:7999/callback',
				'auth_code_id'          => self::hex( 40 ),
				'scopes'                => [ 'mcp' ],
				'user_id'               => self::SUBJECT,
				'expire_time'           => time() + 60,
				'code_challenge'        => str_repeat( 'A', 43 ),
				'code_challenge_method' => 'S256',
			]
		);
		return Crypto::encryptWithPassword( (string) $payload, $password );
	}

	/**
	 * An access JWT in the earlier shape: typ/alg header, string audience, fractional
	 * time claims, string subject and an array of scopes, with no issuer or client.
	 *
	 * @param array<string, mixed> $overrides
	 */
	public static function access_token( string $jti, string $private_key, int $issued, array $overrides = [], array $header = [ 'typ' => 'JWT', 'alg' => 'RS256' ] ): string {
		$claims = array_replace(
			[
				'aud'    => StorageRig::RESOURCE,
				'jti'    => $jti,
				'iat'    => $issued + 0.25,
				'nbf'    => $issued + 0.25,
				'exp'    => $issued + 3600.25,
				'sub'    => self::SUBJECT,
				'scopes' => [ 'mcp' ],
			],
			$overrides
		);
		$encode = static fn ( array $value ): string => rtrim( strtr( base64_encode( (string) json_encode( $value, JSON_UNESCAPED_SLASHES ) ), '+/', '-_' ), '=' );
		$input = $encode( $header ) . '.' . $encode( $claims );
		openssl_sign( $input, $signature, $private_key, OPENSSL_ALGO_SHA256 );
		return $input . '.' . rtrim( strtr( base64_encode( (string) $signature ), '+/', '-_' ), '=' );
	}

	/** @param array<string, ?string> $data */
	private function insert( string $table, array $data ): void {
		$result = $this->space->insert_row( $this->prefix . 'stonewright_oauth_' . $table, $data );
		if ( is_string( $result ) ) {
			throw new \LogicException( $result );
		}
	}
}
