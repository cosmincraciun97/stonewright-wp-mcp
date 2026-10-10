<?php
/**
 * What the change ledger may keep of an image, and what it must never keep.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Security\ChangeImage;

/**
 * Every value here is synthetic. The strings that stand for secrets are built from a marker so that
 * each test can search the stored bytes for it.
 *
 * @covers \Stonewright\WpMcp\Security\ChangeImage
 */
final class ChangeImageTest extends TestCase {

	private const MARKER = 'SYNTHETIC-SECRET-7f3a91';

	public function test_a_plain_text_image_is_kept_as_it_is_and_decodes_back(): void {
		$text   = "<?php\n// Footer for site-a\nfunction site_a_footer() { return 'ok'; }\n";
		$result = ChangeImage::prepare( $text, 'theme_file', 'twentytwentyfive/functions.php' );

		self::assertSame( '', $result['refused'] );
		self::assertFalse( $result['masked'] );
		self::assertIsString( $result['bytes'] );
		self::assertSame( hash( 'sha256', $result['bytes'] ), $result['sha256'] );
		self::assertSame( strlen( $result['bytes'] ), $result['size'] );
		self::assertSame( $text, ChangeImage::decode( $result['bytes'] ) );
	}

	public function test_a_field_name_that_names_a_credential_is_known(): void {
		foreach ( [ 'user_pass', 'user_activation_key', 'session_tokens', 'api_token', 'client_secret', 'application_password' ] as $name ) {
			self::assertTrue( ChangeImage::is_secret_field( $name ), $name );
		}
		foreach ( [ 'display_name', 'user_email', 'first_name', 'description', 'locale', 'post_title' ] as $name ) {
			self::assertFalse( ChangeImage::is_secret_field( $name ), $name );
		}
	}

	public function test_an_array_image_decodes_back_and_its_hash_does_not_depend_on_key_order(): void {
		$one = ChangeImage::prepare( [ 'post_title' => 'Home', 'meta' => [ 'b' => 2, 'a' => 1 ], 'tags' => [ 'x', 'y' ] ], 'post', '42' );
		$two = ChangeImage::prepare( [ 'tags' => [ 'x', 'y' ], 'meta' => [ 'a' => 1, 'b' => 2 ], 'post_title' => 'Home' ], 'post', '42' );

		self::assertSame( $one['sha256'], $two['sha256'] );
		self::assertSame( [ 'meta' => [ 'a' => 1, 'b' => 2 ], 'post_title' => 'Home', 'tags' => [ 'x', 'y' ] ], ChangeImage::decode( (string) $one['bytes'] ) );
	}

	public function test_a_list_keeps_its_order(): void {
		$one = ChangeImage::prepare( [ 'items' => [ 'a', 'b' ] ], 'menu', '7' );
		$two = ChangeImage::prepare( [ 'items' => [ 'b', 'a' ] ], 'menu', '7' );

		self::assertNotSame( $one['sha256'], $two['sha256'] );
	}

	public function test_no_image_is_no_bytes_and_no_hash(): void {
		$result = ChangeImage::prepare( null, 'post', '42' );

		self::assertSame( '', $result['refused'] );
		self::assertNull( $result['bytes'] );
		self::assertSame( '', $result['sha256'] );
		self::assertSame( 0, $result['size'] );
	}

	public function test_content_that_is_not_text_is_not_kept(): void {
		$result = ChangeImage::prepare( "\xff\xfe binary \x00", 'media', '9' );

		self::assertSame( 'not_encodable', $result['refused'] );
		self::assertNull( $result['bytes'] );
	}

	public function test_a_json_looking_page_without_secrets_is_not_touched(): void {
		$json   = '{"elType":"widget","widgetType":"heading","settings":{"title":"Welcome to site-a","tokens_label":"Pricing tokens"}}';
		$result = ChangeImage::prepare( [ '_elementor_data' => $json ], 'post', '42' );

		self::assertFalse( $result['masked'] );
		self::assertSame( [ '_elementor_data' => $json ], ChangeImage::decode( (string) $result['bytes'] ) );
	}

	// ---- Never stored: whole resources ---------------------------------------------------------

	/** @return array<string, array{0: string}> */
	public static function secretFiles(): array {
		return [
			'wp-config'                 => [ 'wp-config.php' ],
			'wp-config in a folder'     => [ 'sites/site-a/wp-config.php' ],
			'wp-config upper case'      => [ 'WP-CONFIG.PHP' ],
			'wp-config backup'          => [ 'wp-config.php.bak' ],
			'wp-config sample copy'     => [ 'wp-config-local.php' ],
			'wp-config with backslash'  => [ 'sites\\site-a\\wp-config.php' ],
			'dot env'                   => [ '.env' ],
			'dot env production'        => [ 'wp-content/themes/site-a/.env.production' ],
			'private key file'          => [ 'wp-content/uploads/server.key' ],
			'pem file'                  => [ 'certs/site-a.pem' ],
			'ssh key'                   => [ 'id_rsa' ],
			'password file'             => [ '.htpasswd' ],
		];
	}

	/** @dataProvider secretFiles */
	public function test_a_file_that_holds_credentials_is_never_stored( string $path ): void {
		$result = ChangeImage::prepare( "define( 'DB_PASSWORD', '" . self::MARKER . "' );", 'theme_file', $path );

		self::assertSame( 'secret_file', $result['refused'], $path );
		self::assertNull( $result['bytes'] );
		self::assertSame( '', $result['sha256'], 'not even a hash of a credential file is kept' );
		self::assertSame( 0, $result['size'] );
	}

	/** @dataProvider secretFiles */
	public function test_the_file_rule_does_not_depend_on_the_family( string $path ): void {
		foreach ( [ 'sandbox', 'custom_code', 'plugin', 'option' ] as $type ) {
			self::assertSame( 'secret_file', ChangeImage::prepare( 'x', $type, $path )['refused'], $type . ' ' . $path );
		}
	}

	/** @return array<string, array{0: string}> */
	public static function secretOptions(): array {
		return [
			'auth key'                 => [ 'auth_key' ],
			'secure auth key'          => [ 'secure_auth_key' ],
			'logged in key'            => [ 'logged_in_key' ],
			'nonce key'                => [ 'nonce_key' ],
			'auth salt'                => [ 'auth_salt' ],
			'secure auth salt'         => [ 'secure_auth_salt' ],
			'logged in salt'           => [ 'logged_in_salt' ],
			'nonce salt'               => [ 'nonce_salt' ],
			'confirmation secret'      => [ 'stonewright_confirmation_secret' ],
			'oauth signing key'        => [ 'stonewright_oauth_private_key' ],
			'oauth encryption key'     => [ 'stonewright_oauth_encryption_key' ],
			'design checkpoint secret' => [ 'stonewright_design_checkpoint_secret' ],
			'api key'                  => [ 'mailer_api_key' ],
			'api key compact'          => [ 'mailerapikey' ],
			'license key'              => [ 'site_a_plugin_license_key' ],
			'access token'             => [ 'social_access_token' ],
			'refresh token'            => [ 'social_refresh_token' ],
			'smtp password'            => [ 'smtp_password' ],
			'client secret'            => [ 'oauth_client_secret' ],
			'credentials'              => [ 'cloud_credentials' ],
			'upper case'               => [ 'AUTH_KEY' ],
		];
	}

	/** @dataProvider secretOptions */
	public function test_an_option_on_the_secret_list_is_never_stored( string $name ): void {
		foreach ( [ 'option', 'theme_mod' ] as $type ) {
			$result = ChangeImage::prepare( [ 'value' => self::MARKER ], $type, $name );

			self::assertSame( 'secret_option', $result['refused'], $type . ' ' . $name );
			self::assertNull( $result['bytes'] );
			self::assertSame( '', $result['sha256'] );
		}
	}

	/** @return array<string, array{0: string}> */
	public static function ordinaryOptions(): array {
		return [
			'blogname'       => [ 'blogname' ],
			'front page'     => [ 'show_on_front' ],
			'posts per page' => [ 'posts_per_page' ],
			'author base'    => [ 'author_base' ],
			'monkey'         => [ 'monkey_business' ],
			'theme mods'     => [ 'theme_mods_twentytwentyfive' ],
			'mode'           => [ 'stonewright_mode' ],
		];
	}

	/** @dataProvider ordinaryOptions */
	public function test_an_ordinary_option_is_stored( string $name ): void {
		$result = ChangeImage::prepare( [ 'value' => 'Site A' ], 'option', $name );

		self::assertSame( '', $result['refused'], $name );
		self::assertIsString( $result['bytes'] );
	}

	/** @return array<string, array{0: string}> */
	public static function secretResourceTypes(): array {
		return [
			'application password' => [ 'application_password' ],
			'user password'        => [ 'user_password' ],
			'oauth token'          => [ 'oauth_token' ],
			'oauth key'            => [ 'oauth_key' ],
			'oauth client secret'  => [ 'oauth_client_secret' ],
			'salt'                 => [ 'salt' ],
			'auth key'             => [ 'auth_key' ],
			'session token'        => [ 'session_token' ],
		];
	}

	/** @dataProvider secretResourceTypes */
	public function test_a_resource_that_is_a_credential_is_never_stored( string $type ): void {
		$result = ChangeImage::prepare( [ 'password' => self::MARKER ], $type, '12' );

		self::assertSame( 'secret_resource', $result['refused'], $type );
		self::assertNull( $result['bytes'] );
		self::assertSame( '', $result['sha256'] );
	}

	// ---- Never stored: values inside an image --------------------------------------------------

	public function test_a_user_password_hash_and_the_other_credentials_of_a_user_are_dropped(): void {
		$image  = [
			'ID'                  => 12,
			'user_login'          => 'editor-a',
			'user_email'          => 'editor-a@example.test',
			'user_pass'           => '$P$B' . self::MARKER,
			'user_activation_key' => '1700000000:' . self::MARKER,
			'session_tokens'      => [ hash( 'sha256', self::MARKER ) => [ 'expiration' => 1, 'ua' => 'x' ] ],
			'roles'               => [ 'editor' ],
		];
		$result = ChangeImage::prepare( $image, 'user', '12' );

		self::assertTrue( $result['masked'] );
		self::assertIsString( $result['bytes'] );
		self::assertStringNotContainsString( self::MARKER, $result['bytes'] );
		self::assertStringNotContainsString( hash( 'sha256', self::MARKER ), $result['bytes'] );
		$decoded = ChangeImage::decode( $result['bytes'] );
		self::assertIsArray( $decoded );
		self::assertSame( 'editor-a@example.test', $decoded['user_email'], 'the harmless fields stay' );
		self::assertSame( [ 'editor' ], $decoded['roles'] );
		self::assertSame( ChangeImage::MASK, $decoded['user_pass'] );
	}

	public function test_application_passwords_of_a_user_are_never_stored(): void {
		$image  = [
			'ID'                    => 12,
			'_application_passwords' => [
				[ 'uuid' => 'c0ffee00-0000-4000-8000-000000000001', 'password' => '$P$B' . self::MARKER, 'name' => 'agent-a' ],
			],
			'application_passwords'  => [ [ 'password' => self::MARKER ] ],
		];
		$result = ChangeImage::prepare( $image, 'user', '12' );

		self::assertTrue( $result['masked'] );
		self::assertStringNotContainsString( self::MARKER, (string) $result['bytes'] );
		self::assertStringNotContainsString( 'agent-a', (string) $result['bytes'], 'the whole entry is dropped, not only the hash' );
	}

	public function test_oauth_tokens_and_keys_inside_an_image_are_never_stored(): void {
		$image  = [
			'client_id'     => 'client-a',
			'access_token'  => 'swc_' . self::MARKER . 'aaaaaaaa',
			'refresh_token' => self::MARKER . '-refresh',
			'client_secret' => self::MARKER . '-client',
			'id_token'      => self::MARKER . '-id',
			'private_key'   => self::MARKER . '-private',
			'Authorization' => 'Bearer ' . self::MARKER . 'abcdefghijkl',
			'note'          => 'kept',
		];
		$result = ChangeImage::prepare( $image, 'option', 'site_a_connector' );

		self::assertTrue( $result['masked'] );
		self::assertStringNotContainsString( self::MARKER, (string) $result['bytes'] );
		$decoded = ChangeImage::decode( (string) $result['bytes'] );
		self::assertIsArray( $decoded );
		self::assertSame( 'client-a', $decoded['client_id'] );
		self::assertSame( 'kept', $decoded['note'] );
	}

	public function test_keys_and_salts_written_as_constants_are_masked_line_by_line(): void {
		$lines = [
			'<?php',
			"define( 'AUTH_KEY',         '" . self::MARKER . "-1' );",
			"define( 'SECURE_AUTH_KEY',  \"" . self::MARKER . "-2\" );",
			"define('LOGGED_IN_KEY','" . self::MARKER . "-3');",
			"define( 'NONCE_KEY', '" . self::MARKER . "-4' );",
			"define( 'AUTH_SALT', '" . self::MARKER . "-5' );",
			"define( 'SECURE_AUTH_SALT', '" . self::MARKER . "-6' );",
			"define( 'LOGGED_IN_SALT', '" . self::MARKER . "-7' );",
			"define( 'NONCE_SALT', '" . self::MARKER . "-8' );",
			"define( 'DB_PASSWORD', '" . self::MARKER . "-9' );",
			"define( 'WP_DEBUG', false );",
		];
		$result = ChangeImage::prepare( implode( "\n", $lines ) . "\n", 'theme_file', 'site-a/config-copy.php' );

		self::assertTrue( $result['masked'] );
		self::assertStringNotContainsString( self::MARKER, (string) $result['bytes'] );
		$text = ChangeImage::decode( (string) $result['bytes'] );
		self::assertIsString( $text );
		$out = explode( "\n", $text );
		self::assertSame( '<?php', $out[0] );
		self::assertSame( '[masked line 2]', $out[1] );
		self::assertSame( '[masked line 9]', $out[8] );
		self::assertSame( '[masked line 10]', $out[9] );
		self::assertSame( "define( 'WP_DEBUG', false );", $out[10] );
	}

	public function test_a_private_key_block_is_masked_whole(): void {
		$kind   = 'PRIVATE' . ' KEY';
		$block  = '-----BEGIN ' . $kind . "-----\n" . self::MARKER . "\nQUJDREVGR0hJSktMTU5PUA==\n-----END " . $kind . '-----';
		$result = ChangeImage::prepare( "before\n" . $block . "\nafter\n", 'sandbox', 'site-a/deploy.php' );

		self::assertTrue( $result['masked'] );
		self::assertStringNotContainsString( self::MARKER, (string) $result['bytes'] );
		self::assertStringNotContainsString( 'QUJDREVGR0hJSktMTU5PUA', (string) $result['bytes'] );
		$text = ChangeImage::decode( (string) $result['bytes'] );
		self::assertIsString( $text );
		self::assertStringStartsWith( "before\n", $text );
		self::assertStringEndsWith( "\nafter\n", $text );
	}

	public function test_credential_forms_the_sensitive_content_check_knows_are_masked(): void {
		$text   = implode(
			"\n",
			[
				'Authorization: Bearer ' . self::MARKER . 'abcdefghij',
				'pass' . 'word: ' . self::MARKER . '-pw',
				'application password: ' . implode( ' ', [ 'abcd', 'efgh', 'ijkl', 'mnop', 'qrst', 'uvwx' ] ),
				'connect to https://admin:' . self::MARKER . '@example.test/path',
				'token = ' . self::MARKER . '-t',
				'harmless line',
			]
		);
		$result = ChangeImage::prepare( $text, 'custom_code', 'site-a-snippet' );

		self::assertTrue( $result['masked'] );
		self::assertStringNotContainsString( self::MARKER, (string) $result['bytes'] );
		self::assertStringNotContainsString( 'abcd efgh ijkl', (string) $result['bytes'] );
		$out = explode( "\n", (string) ChangeImage::decode( (string) $result['bytes'] ) );
		self::assertSame( 'harmless line', $out[5] );
		self::assertSame( '[masked line 1]', $out[0] );
	}

	public function test_a_stonewright_token_in_a_text_is_masked(): void {
		foreach ( [ 'swc_' . self::MARKER . '0000', 'swotl_' . self::MARKER . '0000', 'sw_cc_' . self::MARKER . '0000' ] as $token ) {
			$result = ChangeImage::prepare( "line one\nvalue is " . $token . "\n", 'custom_code', 'site-a-snippet' );

			self::assertTrue( $result['masked'], $token );
			self::assertStringNotContainsString( self::MARKER, (string) $result['bytes'] );
		}
	}

	public function test_line_endings_of_a_masked_text_are_kept(): void {
		$result = ChangeImage::prepare( "a\r\npassword: " . self::MARKER . "-pw\r\nb\r\n", 'custom_code', 'site-a-snippet' );

		self::assertSame( "a\r\n[masked line 2]\r\nb\r\n", ChangeImage::decode( (string) $result['bytes'] ) );
	}

	public function test_the_hash_of_a_live_image_is_the_hash_that_was_stored_for_it(): void {
		$image  = [ 'user_login' => 'editor-a', 'user_pass' => self::MARKER ];
		$stored = ChangeImage::prepare( $image, 'user', '12' );

		self::assertSame( $stored['sha256'], ChangeImage::hash_of( $image, 'user', '12' ) );
		self::assertNotSame( $stored['sha256'], ChangeImage::hash_of( [ 'user_login' => 'editor-b' ], 'user', '12' ) );
		self::assertSame( '', ChangeImage::hash_of( 'x', 'theme_file', 'wp-config.php' ), 'a refused resource has no hash' );
	}

	public function test_decode_gives_null_for_bytes_that_are_not_an_image(): void {
		self::assertNull( ChangeImage::decode( 'not json' ) );
		self::assertNull( ChangeImage::decode( '{"v":2,"kind":"text","data":"x"}' ) );
		self::assertNull( ChangeImage::decode( '{"v":1,"kind":"text","data":["x"]}' ) );
		self::assertNull( ChangeImage::decode( '' ) );
	}
}
