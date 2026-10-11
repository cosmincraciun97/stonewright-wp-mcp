<?php
/**
 * The theme-file and Customizer CSS providers apply in production-safe mode: the confirmation token is
 * verified once, for custom-code-provider and the exact request, and the human grant stays single-use.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\CustomCode;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\CustomCode\ProviderOps;
use Stonewright\WpMcp\CustomCode\ProviderRegistry;
use Stonewright\WpMcp\Security\ConfirmationToken;
use Stonewright\WpMcp\Security\CustomCodeGrant;
use Stonewright\WpMcp\Security\IncidentStore;

/**
 * @covers \Stonewright\WpMcp\Abilities\CustomCode\ProviderOps
 * @covers \Stonewright\WpMcp\CustomCode\Providers\ThemeFileProvider
 * @covers \Stonewright\WpMcp\CustomCode\Providers\CustomizerCssProvider
 * @covers \Stonewright\WpMcp\Abilities\Themes\ThemeFilePatch
 * @covers \Stonewright\WpMcp\Abilities\Themes\ThemeCustomCss
 */
final class ProviderProductionSafeApplyTest extends TestCase {

	private const GAP = [
		'reason'        => 'No typed WordPress API owns this stylesheet rule.',
		'methods_tried' => [ 'typed_api', 'admin_form' ],
	];

	private string $theme_dir;

	protected function setUp(): void {
		$GLOBALS['stonewright_test_user_caps']       = [ 'read' => true, 'edit_theme_options' => true, 'edit_css' => true, 'manage_options' => true ];
		$GLOBALS['stonewright_test_user_logged_in']  = true;
		$GLOBALS['stonewright_test_current_user_id'] = 1;
		$GLOBALS['stonewright_test_options']         = [ 'stonewright_mode' => 'production-safe', 'stonewright_disabled_abilities' => [] ];
		$GLOBALS['stonewright_test_wpdb_inserts']    = [];
		$GLOBALS['stonewright_test_transients']      = [];
		$GLOBALS['stonewright_test_custom_css']      = '';
		IncidentStore::reset_for_tests();

		$this->theme_dir = sys_get_temp_dir() . '/sw-theme-ps-' . bin2hex( random_bytes( 4 ) );
		mkdir( $this->theme_dir );
		file_put_contents( $this->theme_dir . '/style.css', "/* theme */\nbody{color:#111;}\n" );
		$GLOBALS['stonewright_test_stylesheet_directory'] = $this->theme_dir;
		$GLOBALS['stonewright_test_stylesheet']           = 'sw-test-theme';
		ProviderRegistry::reset_for_tests();
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_user_caps']       = [];
		$GLOBALS['stonewright_test_options']         = [];
		$GLOBALS['stonewright_test_wpdb_inserts']    = [];
		$GLOBALS['stonewright_test_transients']      = [];
		$GLOBALS['stonewright_test_current_user_id'] = 0;
		unset( $GLOBALS['stonewright_test_stylesheet_directory'], $GLOBALS['stonewright_test_stylesheet'], $GLOBALS['stonewright_test_custom_css'] );
		ProviderRegistry::reset_for_tests();
		IncidentStore::reset_for_tests();
		foreach ( (array) glob( $this->theme_dir . '/*' ) as $file ) {
			if ( is_string( $file ) && is_file( $file ) ) {
				@unlink( $file );
			}
		}
		@rmdir( $this->theme_dir );
	}

	/**
	 * @return array<string, array{0: string, 1: array<string, mixed>, 2: string, 3: string}>
	 */
	public static function providers(): array {
		return [
			'theme-file'     => [
				'theme-file',
				[ 'target_id' => 'style.css', 'mode' => 'append', 'code' => "p{margin:0;}\n", 'language' => 'css' ],
				'style.css',
				'theme',
			],
			'customizer-css' => [
				'customizer-css',
				[ 'target_id' => 'active', 'code' => "body{background:#fff;}\n", 'language' => 'css' ],
				'customizer/custom-css/sw-test-theme.css',
				'customizer',
			],
		];
	}

	/**
	 * Dry-run the request and issue the operator grant a human would issue from wp-admin.
	 *
	 * @param array<string, mixed> $request
	 * @return array{apply: array<string, mixed>, grant: string, dry: array<string, mixed>}
	 */
	private function staged( string $provider, array $request, string $grant_path ): array {
		$ops = new ProviderOps();
		$dry = $ops->execute( [ 'action' => 'dry-run', 'provider' => $provider, 'native_gap' => self::GAP ] + $request );
		self::assertIsArray( $dry, $dry instanceof \WP_Error ? $dry->get_error_message() : '' );
		$grant = CustomCodeGrant::issue(
			[
				'path'          => $grant_path,
				'after_sha256'  => $dry['after_sha256'],
				'language'      => 'css',
				'changed_bytes' => $dry['changed_bytes'],
			]
		);
		self::assertIsArray( $grant, $grant instanceof \WP_Error ? $grant->get_error_message() : '' );

		return [
			'dry'   => $dry,
			'grant' => (string) $grant['token'],
			'apply' => [ 'action' => 'apply', 'provider' => $provider ] + $request,
		];
	}

	private function live_code( string $provider ): string {
		return 'theme-file' === $provider
			? (string) file_get_contents( $this->theme_dir . '/style.css' )
			: (string) $GLOBALS['stonewright_test_custom_css'];
	}

	/**
	 * @dataProvider providers
	 * @param array<string, mixed> $request
	 */
	public function test_apply_succeeds_with_one_token_and_one_grant( string $provider, array $request, string $grant_path ): void {
		$staged = $this->staged( $provider, $request, $grant_path );
		$token  = ConfirmationToken::issue( 'stonewright/custom-code-provider', $staged['apply'] );

		$result = ( new ProviderOps() )->execute( $staged['apply'] + [ 'custom_code_grant' => $staged['grant'], 'confirmation_token' => $token ] );

		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_code() . ': ' . $result->get_error_message() : '' );
		self::assertSame( $provider, $result['provider'] );
		self::assertStringContainsString( trim( (string) $request['code'] ), $this->live_code( $provider ) );
	}

	/**
	 * @dataProvider providers
	 * @param array<string, mixed> $request
	 */
	public function test_a_replayed_token_is_refused_and_writes_nothing( string $provider, array $request, string $grant_path ): void {
		$staged = $this->staged( $provider, $request, $grant_path );
		$token  = ConfirmationToken::issue( 'stonewright/custom-code-provider', $staged['apply'] );
		$call   = $staged['apply'] + [ 'custom_code_grant' => $staged['grant'], 'confirmation_token' => $token ];
		$ops    = new ProviderOps();

		$first = $ops->execute( $call );
		self::assertIsArray( $first, $first instanceof \WP_Error ? $first->get_error_message() : '' );
		$after_first = $this->live_code( $provider );

		$replay = $ops->execute( $call );

		self::assertInstanceOf( \WP_Error::class, $replay );
		self::assertSame( 'stonewright_confirmation_replayed', $replay->get_error_code() );
		self::assertSame( $after_first, $this->live_code( $provider ) );
	}

	/**
	 * @dataProvider providers
	 * @param array<string, mixed> $request
	 */
	public function test_a_token_for_other_arguments_is_refused( string $provider, array $request, string $grant_path ): void {
		$staged = $this->staged( $provider, $request, $grant_path );
		$other  = [ 'code' => "a{b:c}\n" ] + $staged['apply'];
		$token  = ConfirmationToken::issue( 'stonewright/custom-code-provider', $other );
		$before = $this->live_code( $provider );

		$result = ( new ProviderOps() )->execute( $staged['apply'] + [ 'custom_code_grant' => $staged['grant'], 'confirmation_token' => $token ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_confirmation_args_mismatch', $result->get_error_code() );
		self::assertSame( $before, $this->live_code( $provider ) );
	}

	/**
	 * @dataProvider providers
	 * @param array<string, mixed> $request
	 */
	public function test_a_token_for_another_ability_is_refused( string $provider, array $request, string $grant_path ): void {
		$staged = $this->staged( $provider, $request, $grant_path );
		$token  = ConfirmationToken::issue( 'stonewright/theme-file-patch', $staged['apply'] );
		$before = $this->live_code( $provider );

		$result = ( new ProviderOps() )->execute( $staged['apply'] + [ 'custom_code_grant' => $staged['grant'], 'confirmation_token' => $token ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( $before, $this->live_code( $provider ) );
	}

	/**
	 * @dataProvider providers
	 * @param array<string, mixed> $request
	 */
	public function test_a_missing_token_is_still_required( string $provider, array $request, string $grant_path ): void {
		$staged = $this->staged( $provider, $request, $grant_path );
		$before = $this->live_code( $provider );

		$result = ( new ProviderOps() )->execute( $staged['apply'] + [ 'custom_code_grant' => $staged['grant'] ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_confirmation_required', $result->get_error_code() );
		self::assertSame( $before, $this->live_code( $provider ) );
	}

	/**
	 * @dataProvider providers
	 * @param array<string, mixed> $request
	 */
	public function test_a_valid_token_does_not_replace_the_human_grant( string $provider, array $request, string $grant_path ): void {
		$staged = $this->staged( $provider, $request, $grant_path );
		$token  = ConfirmationToken::issue( 'stonewright/custom-code-provider', $staged['apply'] );
		$before = $this->live_code( $provider );

		$result = ( new ProviderOps() )->execute( $staged['apply'] + [ 'confirmation_token' => $token ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_custom_code_grant_required', $result->get_error_code() );
		self::assertSame( $before, $this->live_code( $provider ) );
	}

	/**
	 * @dataProvider providers
	 * @param array<string, mixed> $request
	 */
	public function test_the_grant_is_single_use_across_two_tokens( string $provider, array $request, string $grant_path ): void {
		$staged = $this->staged( $provider, $request, $grant_path );
		$ops    = new ProviderOps();
		$first  = $ops->execute( $staged['apply'] + [ 'custom_code_grant' => $staged['grant'], 'confirmation_token' => ConfirmationToken::issue( 'stonewright/custom-code-provider', $staged['apply'] ) ] );
		self::assertIsArray( $first, $first instanceof \WP_Error ? $first->get_error_message() : '' );

		// The live code goes back to what it was, so a second apply of the same candidate is a real change again.
		'theme-file' === $provider
			? file_put_contents( $this->theme_dir . '/style.css', "/* theme */
body{color:#111;}
" )
			: $GLOBALS['stonewright_test_custom_css'] = '';
		$after_first = $this->live_code( $provider );

		$second = $ops->execute( $staged['apply'] + [ 'custom_code_grant' => $staged['grant'], 'confirmation_token' => ConfirmationToken::issue( 'stonewright/custom-code-provider', $staged['apply'] ) ] );

		self::assertInstanceOf( \WP_Error::class, $second );
		self::assertSame( 'stonewright_custom_code_grant_reused', $second->get_error_code() );
		self::assertSame( $after_first, $this->live_code( $provider ) );
	}

	public function test_the_delegated_abilities_still_check_the_token_when_called_directly(): void {
		$result = ( new \Stonewright\WpMcp\Abilities\Themes\ThemeFilePatch() )->execute(
			[ 'path' => 'style.css', 'mode' => 'append', 'content' => "p{margin:0;}\n", 'native_gap' => self::GAP, 'dry_run' => false ]
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_confirmation_required', $result->get_error_code() );

		$css = ( new \Stonewright\WpMcp\Abilities\Themes\ThemeCustomCss() )->execute( [ 'action' => 'update', 'css' => "a{b:c}\n", 'native_gap' => self::GAP ] );
		self::assertInstanceOf( \WP_Error::class, $css );
		self::assertSame( 'stonewright_confirmation_required', $css->get_error_code() );
	}

	public function test_the_provider_apply_method_alone_is_not_a_way_around_the_token(): void {
		$staged = $this->staged( 'theme-file', self::providers()['theme-file'][1], 'style.css' );
		$before = $this->live_code( 'theme-file' );

		$provider = ProviderRegistry::get( 'theme-file' );
		self::assertNotNull( $provider );
		$result = $provider->apply( $staged['apply'] + [ 'custom_code_grant' => $staged['grant'] ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_confirmation_required', $result->get_error_code() );
		self::assertSame( $before, $this->live_code( 'theme-file' ) );
	}
}
