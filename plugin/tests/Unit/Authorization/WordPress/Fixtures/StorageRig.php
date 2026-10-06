<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Authorization\WordPress\Fixtures;

use PHPUnit\Framework\Assert;
use Stonewright\WpMcp\Authorization\Exchange\CodeExchangeCoordinator;
use Stonewright\WpMcp\Authorization\Exchange\ConsentCoordinator;
use Stonewright\WpMcp\Authorization\Decisions\AuthorizationCodeDecision;
use Stonewright\WpMcp\Authorization\Decisions\ConsentDecision;
use Stonewright\WpMcp\Authorization\Model\CodeDemand;
use Stonewright\WpMcp\Authorization\Model\CodeExchangeOutcome;
use Stonewright\WpMcp\Authorization\Model\RefreshOutcome;
use Stonewright\WpMcp\Authorization\Model\RotationDemand;
use Stonewright\WpMcp\Authorization\Model\TokenPair;
use Stonewright\WpMcp\Authorization\Protocol\CodeProof;
use Stonewright\WpMcp\Authorization\Refresh\RefreshCoordinator;
use Stonewright\WpMcp\Authorization\Refresh\RefreshPolicy;
use Stonewright\WpMcp\Authorization\Refresh\RotationDecision;
use Stonewright\WpMcp\Authorization\WordPress\AccessTokenStore;
use Stonewright\WpMcp\Authorization\WordPress\ClientStore;
use Stonewright\WpMcp\Authorization\WordPress\CodeStore;
use Stonewright\WpMcp\Authorization\WordPress\CredentialKeys;
use Stonewright\WpMcp\Authorization\WordPress\Database;
use Stonewright\WpMcp\Authorization\WordPress\FamilyStore;
use Stonewright\WpMcp\Authorization\WordPress\PendingConsentStore;
use Stonewright\WpMcp\Authorization\WordPress\StorageTables;
use Stonewright\WpMcp\Authorization\WordPress\TokenCodec;
use Stonewright\WpMcp\Tests\Unit\Authorization\Fixtures\ScriptedClock;
use Stonewright\WpMcp\Tests\Unit\Authorization\Fixtures\SyntheticSubjectAuthority;

/**
 * Every WordPress adapter wired over one fake connection, with a scripted clock,
 * predictable identifiers and keys created in memory for this test process only.
 * connection() returns a second rig over the same tables: another PHP process.
 */
final class StorageRig {

	/** 2026-01-01T00:00:00Z. */
	public const T = 1767225600;

	public const RESOURCE = 'https://example.test/wp-json/mcp/stonewright-oauth';
	public const PLAIN_RESOURCE = 'https://example.test/index.php?rest_route=/mcp/stonewright-oauth';
	public const ISSUER = 'https://example.test';
	public const REDIRECT = 'http://127.0.0.1:7999/callback';
	public const VERIFIER = 'synthetic-verifier-synthetic-verifier-0123456789';

	private static ?string $private_key = null;

	public FakeWpdb $wpdb;
	public Database $db;
	public ScriptedClock $clock;
	public SequentialIdentifiers $ids;
	public CredentialKeys $keys;
	public ClientStore $clients;
	public AccessTokenStore $access;
	public FamilyStore $families;
	public CodeStore $codes;
	public PendingConsentStore $consents;
	public TokenCodec $codec;
	public string $binding_secret = 'synthetic-binding-secret';
	public string $current_subject = '7';
	public int $window = 60;

	public function __construct( public FakeTables $space = new FakeTables(), bool $install = true, string $id_prefix = 'a' ) {
		$this->wpdb = new FakeWpdb( $space );
		$this->db = new Database( $this->wpdb );
		$this->clock = new ScriptedClock( [ self::T ] );
		$this->ids = new SequentialIdentifiers( $id_prefix );
		if ( $install ) {
			( new StorageTables( $this->db, static fn ( $sql ): array => $space->apply_ddl( $sql ) ) )->install();
			self::seed_keys();
		}
		$this->wire();
	}

	/** A second process over the same tables and options. */
	public function connection( string $id_prefix = 'b' ): self {
		$other = new self( $this->space, false, $id_prefix );
		$other->clock = $this->clock;
		$other->binding_secret = $this->binding_secret;
		$other->wire();
		return $other;
	}

	public function wire(): void {
		$this->keys = new CredentialKeys( $this->db );
		$this->clients = new ClientStore( $this->db, $this->clock, static fn (): string => '192.0.2.10' );
		$this->access = new AccessTokenStore( $this->db );
		$this->families = new FamilyStore( $this->db, $this->access, $this->clients, $this->clock, [ self::RESOURCE, self::PLAIN_RESOURCE ], static function (): void {} );
		$this->codes = new CodeStore( $this->db, $this->families );
		$subject = fn (): string => $this->current_subject;
		$this->consents = new PendingConsentStore( $this->db, $this->codes, $this->clock, $subject );
		$secret = fn (): string => $this->binding_secret;
		$this->codec = new TokenCodec( $this->keys, $this->families, $this->access, $this->clock, self::ISSUER, [ self::RESOURCE, self::PLAIN_RESOURCE ], $secret );
	}

	/** A 2048-bit RSA key generated once per test process; it never leaves memory. */
	public static function private_key(): string {
		if ( null === self::$private_key ) {
			[ $pem ] = CredentialKeys::generate_rsa( CredentialKeys::default_configurations() );
			if ( null === $pem ) {
				throw new \RuntimeException( 'The test process cannot create an RSA key.' );
			}
			self::$private_key = $pem;
		}
		return self::$private_key;
	}

	/**
	 * Assert an unencrypted PKCS#8 PEM holding an RSA-2048 key: the full PEM framing
	 * (written with quantifiers, so the source holds no key header) and the parsed key.
	 */
	public static function assert_pkcs8_rsa_key( mixed $pem ): void {
		Assert::assertIsString( $pem );
		Assert::assertMatchesRegularExpression( '/^-{5}BEGIN PRIVATE KEY-{5}\n[A-Za-z0-9+\/=\n]+-{5}END PRIVATE KEY-{5}\n?$/D', $pem );
		$details = openssl_pkey_get_details( openssl_pkey_get_private( $pem ) );
		Assert::assertIsArray( $details );
		Assert::assertSame( OPENSSL_KEYTYPE_RSA, $details['type'] );
		Assert::assertSame( 2048, $details['bits'] );
	}

	public static function seed_keys(): void {
		$GLOBALS['stonewright_test_options'][ CredentialKeys::PRIVATE_KEY_OPTION ] = self::private_key();
		$GLOBALS['stonewright_test_options'][ CredentialKeys::ENCRYPTION_KEY_OPTION ] = base64_encode( str_repeat( "\x5a", 32 ) );
	}

	public function at( int $time ): self {
		$this->clock->set( $time );
		return $this;
	}

	/** Register a public client and return its id. */
	public function register_client( string $name = 'Synthetic client' ): string {
		return $this->clients->create(
			[
				'redirect_uris'              => [ self::REDIRECT ],
				'grant_types'                => [ 'authorization_code', 'refresh_token' ],
				'response_types'             => [ 'code' ],
				'token_endpoint_auth_method' => 'none',
				'client_name'                => $name,
			]
		)['client_id'];
	}

	/** Approve a pending consent for the current subject and return the encoded code. */
	public function authorize( string $client_id, array $overrides = [] ): string {
		$pending_key = $this->consents->open(
			array_replace(
				[
					'subject_key'           => $this->current_subject,
					'client_key'            => $client_id,
					'redirect_uri'          => self::REDIRECT,
					'code_challenge'        => ( new CodeProof() )->challenge( self::VERIFIER ),
					'code_challenge_method' => 'S256',
					'scopes'                => [ 'mcp' ],
					'resources'             => [ self::RESOURCE ],
					'state'                 => 'synthetic-state',
					'native_client'         => true,
					'registered_redirects'  => [ self::REDIRECT ],
				],
				$overrides
			)
		);
		$outcome = ( new ConsentCoordinator( $this->consents, $this->clock, $this->ids, new SyntheticSubjectAuthority(), new ConsentDecision( 60 ) ) )->decide( $pending_key, $this->current_subject, true, true );
		return $this->codec->encode_code( $outcome['code'] );
	}

	public function exchange( string $code, string $client_id, ?string $verifier = null ): CodeExchangeOutcome {
		$facts = $this->codec->inspect( $code );
		$coordinator = new CodeExchangeCoordinator( $this->codes, new AuthorizationCodeDecision( new RefreshPolicy( $this->window ) ), $this->clock, $this->ids, new SyntheticSubjectAuthority() );
		return $coordinator->exchange( new CodeDemand( $facts->credential_key, $client_id, self::REDIRECT, $verifier ?? self::VERIFIER, [ self::RESOURCE ] ) );
	}

	/** Full authorization: register, consent, exchange and encode. @return array{0: string, 1: TokenPair, 2: CodeExchangeOutcome} */
	public function connect(): array {
		$client = $this->register_client();
		$outcome = $this->exchange( $this->authorize( $client ), $client );
		return [ $client, $this->codec->encode( $outcome->issuance ), $outcome ];
	}

	public function coordinator( bool $allowed = true ): RefreshCoordinator {
		return new RefreshCoordinator( $this->families, new RotationDecision( new RefreshPolicy( $this->window ) ), $this->clock, $this->ids, new SyntheticSubjectAuthority( $allowed ) );
	}

	/** Present an encoded refresh credential the way the token endpoint would. */
	public function refresh( string $refresh_token, string $client_id, array $resources = [ self::RESOURCE ] ): RefreshOutcome {
		$facts = $this->codec->inspect( $refresh_token );
		return $this->coordinator()->rotate( new RotationDemand( (string) $facts->family_key, $facts->credential_key, $client_id, $resources, null ) );
	}

	/** @return list<array<string, ?string>> */
	public function rows( string $table ): array {
		return $this->space->rows( $this->db->table( $table ) );
	}

	/** @return array<string, ?string>|null */
	public function row( string $table, string $column, string $value ): ?array {
		foreach ( $this->rows( $table ) as $row ) {
			if ( $row[ $column ] === $value ) {
				return $row;
			}
		}
		return null;
	}

	public static function reset_globals(): void {
		$GLOBALS['stonewright_test_options'] = [];
		$GLOBALS['stonewright_test_actions'] = [];
		$GLOBALS['stonewright_test_scheduled_hooks'] = [];
		$GLOBALS['stonewright_test_user_caps'] = [];
		$GLOBALS['stonewright_test_user_caps_by_id'] = [];
		$GLOBALS['stonewright_test_missing_user_ids'] = [];
		unset( $GLOBALS['stonewright_test_nonce_invalid'], $GLOBALS['stonewright_test_last_redirect'] );
	}
}
