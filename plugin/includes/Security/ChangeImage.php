<?php
/**
 * What the change ledger may keep of a before or after image.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Security;

/**
 * Turns the image of a resource (an array of fields, or the text of a file) into the bytes the ledger
 * stores, and decides first whether anything of it may be stored.
 *
 * Some things are never stored, whatever the caller says: a wp-config file and other files that hold
 * credentials, an option whose name is on the secret list, and resources that are credentials, such as
 * an application password, a user password or an OAuth token. For those the image is refused, and not
 * even a hash is kept.
 *
 * Every other image is masked before it is stored. In an array, the value under a key that names a
 * credential is replaced by a mask; in text, a private key block and every line that the sensitive
 * content check recognises, or that defines a key, a salt or a database password, is replaced by a
 * marker that carries the line number. An image that was masked cannot be restored from, so the ledger
 * marks it not restorable.
 *
 * The stored bytes are canonical: a JSON envelope with sorted keys, so equal images have equal bytes and
 * equal hashes. hash_of() gives the hash a live image would have, for a later comparison.
 */
final class ChangeImage {

	/** What replaces a masked value. */
	public const MASK = '[redacted]';

	private const ENVELOPE_VERSION = 1;

	/** Longest resource path, in characters, that the file check reads. */
	private const MAX_RESOURCE_PATH = 255;

	/**
	 * File names, without their folder, that hold credentials or are credentials.
	 */
	private const SECRET_FILE_PATTERN = '/^(?:wp-config[^\/]*|\.env(?:\..*)?|\.htpasswd|id_(?:rsa|dsa|ecdsa|ed25519)(?:\.pub)?|.*\.(?:pem|key|p12|pfx|keystore)|.*\.env)$/i';

	/**
	 * Resource types that are credentials themselves.
	 */
	private const SECRET_RESOURCE_TYPES = [
		'application_password',
		'user_password',
		'password',
		'oauth_token',
		'oauth_key',
		'oauth_client_secret',
		'salt',
		'auth_key',
		'session_token',
		'api_key',
		'secret',
	];

	/**
	 * Words that name a credential when they are a whole part of an option name.
	 */
	private const SECRET_NAME_PARTS = [
		'key',
		'keys',
		'secret',
		'secrets',
		'token',
		'tokens',
		'password',
		'passwords',
		'passwd',
		'pass',
		'pwd',
		'salt',
		'salts',
		'auth',
		'license',
		'licence',
		'credential',
		'credentials',
		'oauth',
		'nonce',
		'cookie',
		'cookies',
		'private',
		'signature',
	];

	/**
	 * Fragments that name a credential wherever they sit in an option name.
	 */
	private const SECRET_NAME_FRAGMENTS = [ 'secret', 'password', 'passwd', 'token', 'credential', 'oauth', 'apikey', 'privatekey', 'licensekey', 'licencekey', 'salt' ];

	/**
	 * Keys of an array image whose value is never kept.
	 */
	private const SECRET_FIELD_PATTERN = '/(?:^|[_\-.\s])(?:pass|passwd|password|passwords|pwd|passphrase|secret|secrets|token|tokens|apikey|salt|nonce|credential|credentials|cookie|cookies|authorization|auth|sessions?)(?:$|[_\-.\s])|password|secret|token|credential|apikey|api_key|private_key|secret_key|access_key|license_key|licence_key|encryption_key|signing_key|auth_key|user_activation_key/i';

	/**
	 * Constants that wp-config.php and similar files define with a secret.
	 */
	private const SECRET_DEFINE_PATTERN = '/\bdefine\s*\(\s*([\'"])(?:AUTH_KEY|SECURE_AUTH_KEY|LOGGED_IN_KEY|NONCE_KEY|AUTH_SALT|SECURE_AUTH_SALT|LOGGED_IN_SALT|NONCE_SALT|DB_PASSWORD|[A-Z0-9_]*(?:_SECRET|_PASSWORD|_TOKEN|_API_KEY|_SALT|_PRIVATE_KEY))\1\s*,/i';

	/**
	 * Stonewright credential prefixes, as the journal file also treats them.
	 */
	private const TOKEN_PATTERN = '/\b(?:swc|swotl|sw_cc)_[A-Za-z0-9_.-]{12,}/';

	private const PRIVATE_KEY_BLOCK = '/-----BEGIN (?:[A-Z]+ )*PRIVATE KEY-----.*?(?:-----END (?:[A-Z]+ )*PRIVATE KEY-----|\z)/s';

	/**
	 * Decide what may be stored of an image and produce the bytes.
	 *
	 * @param mixed  $image         An array of fields, a string, or null for no image.
	 * @param string $resource_type Type of the resource, for example post, option, theme_file.
	 * @param string $resource_id   Id of the resource: a post id, an option name, a path.
	 * @return array{refused:string,bytes:?string,masked:bool,sha256:string,size:int}
	 *         refused is '' or the reason code: secret_file, secret_option, secret_resource, not_encodable.
	 */
	public static function prepare( mixed $image, string $resource_type, string $resource_id ): array {
		$refusal = self::refusal( $resource_type, $resource_id );
		if ( '' !== $refusal ) {
			return self::result( $refusal, null, false );
		}
		if ( null === $image ) {
			return self::result( '', null, false );
		}
		$masked = false;
		if ( is_array( $image ) ) {
			$data = self::sort_keys( self::mask_value( $image, $masked ) );
			$kind = 'data';
		} elseif ( is_string( $image ) ) {
			$data = self::mask_text( $image, $masked );
			$kind = 'text';
		} else {
			return self::result( 'not_encodable', null, false );
		}
		$bytes = json_encode(
			[
				'v'    => self::ENVELOPE_VERSION,
				'kind' => $kind,
				'data' => $data,
			],
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION
		);
		if ( ! is_string( $bytes ) ) {
			return self::result( 'not_encodable', null, false );
		}
		return self::result( '', $bytes, $masked );
	}

	/**
	 * The hash that a live image has as a stored image, or '' when no image is kept for the resource.
	 * Equal to the stored sha256 when nothing changed since the image was taken.
	 */
	public static function hash_of( mixed $image, string $resource_type, string $resource_id ): string {
		return self::prepare( $image, $resource_type, $resource_id )['sha256'];
	}

	/**
	 * The image that stored bytes hold.
	 *
	 * @return string|array<mixed>|null Null when the bytes are not an image of this format.
	 */
	public static function decode( string $bytes ): string|array|null {
		$envelope = json_decode( $bytes, true );
		if ( ! is_array( $envelope ) || ( $envelope['v'] ?? null ) !== self::ENVELOPE_VERSION || ! array_key_exists( 'data', $envelope ) ) {
			return null;
		}
		return match ( $envelope['kind'] ?? '' ) {
			'text'  => is_string( $envelope['data'] ) ? $envelope['data'] : null,
			'data'  => is_array( $envelope['data'] ) ? $envelope['data'] : null,
			default => null,
		};
	}

	/**
	 * A short plain text that is safe to keep in a row and in the audit log: tags removed, control
	 * characters and runs of space collapsed, cut to $max characters, and replaced by a mask when it
	 * looks like a credential.
	 */
	public static function safe_text( string $text, int $max ): string {
		$text = function_exists( 'sanitize_text_field' ) ? sanitize_text_field( $text ) : trim( strip_tags( $text ) );
		$text = trim( (string) preg_replace( '/[\x00-\x20\x7F]+/', ' ', $text ) );
		if ( '' === $text ) {
			return '';
		}
		if ( SensitiveContent::contains( $text ) || 1 === preg_match( self::TOKEN_PATTERN, $text ) ) {
			return self::MASK;
		}
		return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, $max ) : substr( $text, 0, $max );
	}

	/**
	 * Why nothing of this resource may be stored, or '' when something may.
	 */
	public static function refusal( string $resource_type, string $resource_id ): string {
		$type = strtolower( trim( $resource_type ) );
		if ( in_array( $type, self::SECRET_RESOURCE_TYPES, true ) ) {
			return 'secret_resource';
		}
		if ( self::is_secret_file( $resource_id ) ) {
			return 'secret_file';
		}
		if ( in_array( $type, [ 'option', 'theme_mod', 'network_option', 'site_option' ], true ) && self::is_secret_name( $resource_id ) ) {
			return 'secret_option';
		}
		return '';
	}

	/** Whether the name of a field or a meta key names a credential, so that its value is masked. */
	public static function is_secret_field( string $name ): bool {
		return 1 === preg_match( self::SECRET_FIELD_PATTERN, $name );
	}

	/** Whether an option name is on the secret list. */
	public static function is_secret_name( string $name ): bool {
		$name = strtolower( trim( $name ) );
		if ( '' === $name ) {
			return false;
		}
		foreach ( self::SECRET_NAME_FRAGMENTS as $fragment ) {
			if ( str_contains( $name, $fragment ) ) {
				return true;
			}
		}
		$parts = preg_split( '/[^a-z0-9]+/', $name, -1, PREG_SPLIT_NO_EMPTY );
		foreach ( false === $parts ? [] : $parts as $part ) {
			if ( in_array( $part, self::SECRET_NAME_PARTS, true ) ) {
				return true;
			}
		}
		return false;
	}

	/** Whether a path names a file that holds credentials. The folder part is ignored. */
	private static function is_secret_file( string $path ): bool {
		$path = str_replace( '\\', '/', trim( $path ) );
		if ( '' === $path || strlen( $path ) > self::MAX_RESOURCE_PATH * 4 ) {
			return false;
		}
		foreach ( explode( '/', $path ) as $segment ) {
			if ( '' !== $segment && 1 === preg_match( self::SECRET_FILE_PATTERN, $segment ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @return array{refused:string,bytes:?string,masked:bool,sha256:string,size:int}
	 */
	private static function result( string $refused, ?string $bytes, bool $masked ): array {
		return [
			'refused' => $refused,
			'bytes'   => $bytes,
			'masked'  => $masked,
			'sha256'  => null === $bytes ? '' : hash( 'sha256', $bytes ),
			'size'    => null === $bytes ? 0 : strlen( $bytes ),
		];
	}

	/**
	 * Mask the values of an array image, at any depth.
	 *
	 * @param mixed $value
	 * @return mixed
	 */
	private static function mask_value( mixed $value, bool &$masked ): mixed {
		if ( is_string( $value ) ) {
			return self::mask_text( $value, $masked );
		}
		if ( ! is_array( $value ) ) {
			return $value;
		}
		$out = [];
		foreach ( $value as $key => $item ) {
			if ( is_string( $key ) && 1 === preg_match( self::SECRET_FIELD_PATTERN, $key ) ) {
				$out[ $key ] = self::MASK;
				$masked      = true;
				continue;
			}
			$out[ $key ] = self::mask_value( $item, $masked );
		}
		return $out;
	}

	/**
	 * Replace what must not be kept in a text. Line endings stay as they were.
	 */
	private static function mask_text( string $text, bool &$masked ): string {
		$blocks = preg_replace( self::PRIVATE_KEY_BLOCK, '[masked private key]', $text );
		if ( is_string( $blocks ) && $blocks !== $text ) {
			$text   = $blocks;
			$masked = true;
		}
		$parts = preg_split( '/(\r\n|\n|\r)/', $text, -1, PREG_SPLIT_DELIM_CAPTURE );
		if ( ! is_array( $parts ) ) {
			return $text;
		}
		$line = 0;
		foreach ( $parts as $index => $part ) {
			if ( 0 !== $index % 2 ) {
				continue;
			}
			++$line;
			if ( '[masked private key]' === $part || '' === $part ) {
				continue;
			}
			if ( SensitiveContent::contains( $part ) || 1 === preg_match( self::TOKEN_PATTERN, $part ) || 1 === preg_match( self::SECRET_DEFINE_PATTERN, $part ) ) {
				$parts[ $index ] = '[masked line ' . $line . ']';
				$masked          = true;
			}
		}
		return implode( '', $parts );
	}

	/**
	 * @param mixed $value
	 * @return mixed
	 */
	private static function sort_keys( mixed $value ): mixed {
		if ( ! is_array( $value ) ) {
			return $value;
		}
		foreach ( $value as $key => $item ) {
			$value[ $key ] = self::sort_keys( $item );
		}
		if ( ! array_is_list( $value ) ) {
			ksort( $value, SORT_STRING );
		}
		return $value;
	}
}
