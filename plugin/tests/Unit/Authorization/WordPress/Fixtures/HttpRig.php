<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Authorization\WordPress\Fixtures;

use Stonewright\WpMcp\Authorization\Protocol\CodeProof;
use Stonewright\WpMcp\Authorization\WordPress\AuthorizationStorage;
use Stonewright\WpMcp\Authorization\WordPress\ClientDocuments;
use Stonewright\WpMcp\Authorization\WordPress\OAuthRequest;
use Stonewright\WpMcp\Authorization\WordPress\RequestLimiter;
use Stonewright\WpMcp\Authorization\WordPress\SiteProfile;

/**
 * The storage rig plus the HTTP-facing composition: an AuthorizationStorage over the
 * same fake tables, keys, clock and identifiers, a site profile with synthetic URLs,
 * a client document resolver whose network is scripted, and a request limiter.
 */
final class HttpRig {

	public const CHALLENGE_VERIFIER = StorageRig::VERIFIER;

	public StorageRig $rig;
	public AuthorizationStorage $storage;
	public SiteProfile $site;
	public ClientDocuments $documents;
	public RequestLimiter $limiter;

	/** @var array<string, list<string>> Host => addresses the scripted resolver returns. */
	public array $addresses = [];

	/** @var array<string, array{status: int, body: string, cache_control: string}|null> URL => scripted response. */
	public array $responses = [];

	/** @var list<array{0: string, 1: string}> URL and pinned address of every fetch. */
	public array $fetches = [];

	/** @param array<string, array{0: int, 1: int}> $limits */
	public function __construct( string $mode = 'pretty', array $limits = [] ) {
		$this->rig = new StorageRig();
		$this->storage = new AuthorizationStorage(
			$this->rig->db,
			$this->rig->clock,
			$this->rig->ids,
			StorageRig::ISSUER,
			[ StorageRig::RESOURCE, StorageRig::PLAIN_RESOURCE ],
			60,
			null,
			fn (): string => $this->rig->binding_secret,
			$this->rig->keys
		);
		$this->site = self::site( true, 'production', $mode );
		$this->documents = new ClientDocuments(
			$this->storage->clients(),
			$this->rig->clock,
			fn ( string $host ): array => $this->addresses[ $host ] ?? [],
			function ( string $url, string $address ): ?array {
				$this->fetches[] = [ $url, $address ];
				return $this->responses[ $url ] ?? null;
			}
		);
		$this->limiter = new RequestLimiter( $this->rig->db, $this->rig->clock, [] === $limits ? RequestLimiter::LIMITS : $limits );
		$_SERVER['REMOTE_ADDR'] = '192.0.2.10';
	}

	/** A site profile with the synthetic URLs of one permalink mode. */
	public static function site( bool $enabled = true, string $environment = 'production', string $mode = 'pretty', string $home = 'https://example.test' ): SiteProfile {
		$rest = static fn ( string $route ): string => 'plain' === $mode ? $home . '/index.php?rest_route=/' . $route : $home . '/wp-json/' . $route;
		return new SiteProfile(
			$enabled,
			$environment,
			[
				'issuer'                 => $home,
				'resource'               => $rest( 'mcp/stonewright-oauth' ),
				'authorization_endpoint' => $home . '/wp-admin/admin.php?page=stonewright-oauth-authorize',
				'consent_endpoint'       => $home . '/wp-admin/admin.php?page=stonewright-oauth-consent',
				'token_endpoint'         => $rest( 'stonewright/v1/oauth/token' ),
				'registration_endpoint'  => $rest( 'stonewright/v1/oauth/register' ),
				'revocation_endpoint'    => $rest( 'stonewright/v1/oauth/revoke' ),
				'introspection_endpoint' => $rest( 'stonewright/v1/oauth/introspect' ),
			],
			array_values( array_unique( [ $rest( 'mcp/stonewright-oauth' ), $home . '/wp-json/mcp/stonewright-oauth', $home . '/index.php?rest_route=/mcp/stonewright-oauth' ] ) )
		);
	}

	/** A form-encoded POST from the default client address. @param array<string, string|list<string>> $fields */
	public function form( array $fields, array $headers = [] ): OAuthRequest {
		$pairs = [];
		foreach ( $fields as $name => $value ) {
			foreach ( (array) $value as $item ) {
				$pairs[] = rawurlencode( (string) $name ) . '=' . rawurlencode( (string) $item );
			}
		}
		return new OAuthRequest( 'POST', array_change_key_case( $headers + [ 'content-type' => 'application/x-www-form-urlencoded' ] ), implode( '&', $pairs ), '192.0.2.10' );
	}

	/** A JSON POST from the default client address. @param array<string, mixed> $document */
	public function json( array $document, array $headers = [] ): OAuthRequest {
		return new OAuthRequest( 'POST', array_change_key_case( $headers + [ 'content-type' => 'application/json' ] ), (string) json_encode( $document, JSON_UNESCAPED_SLASHES ), '192.0.2.10' );
	}

	/** Script a client metadata document served from a public address. @param array<string, mixed> $document */
	public function publish_document( string $url, array $document, string $cache_control = '' ): void {
		$this->addresses[ (string) parse_url( $url, PHP_URL_HOST ) ] = [ '93.184.216.34' ];
		$this->responses[ $url ] = [
			'status'        => 200,
			'body'          => (string) json_encode( $document, JSON_UNESCAPED_SLASHES ),
			'cache_control' => $cache_control,
		];
	}

	public static function challenge(): string {
		return ( new CodeProof() )->challenge( self::CHALLENGE_VERIFIER );
	}

	/** Run $work with the PHP error log redirected; returns what was logged. */
	public static function quietly( callable $work ): string {
		$log = (string) tempnam( sys_get_temp_dir(), 'sw-oauth-http-' );
		$previous = ini_get( 'error_log' );
		ini_set( 'error_log', $log );
		try {
			$work();
		} finally {
			ini_set( 'error_log', (string) $previous );
		}
		$logged = (string) file_get_contents( $log );
		@unlink( $log );
		return $logged;
	}
}
