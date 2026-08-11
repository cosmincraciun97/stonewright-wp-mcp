<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\CustomCode;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\CustomCode\ProviderOps;
use Stonewright\WpMcp\CustomCode\ProviderRegistry;
use Stonewright\WpMcp\CustomCode\Providers\CodeSnippetsProvider;
use Stonewright\WpMcp\CustomCode\Providers\WpCodeProvider;
use Stonewright\WpMcp\Security\CustomCodeGrant;

/**
 * @covers \Stonewright\WpMcp\CustomCode\Providers\WpCodeProvider
 * @covers \Stonewright\WpMcp\CustomCode\Providers\CodeSnippetsProvider
 * @covers \Stonewright\WpMcp\Abilities\CustomCode\ProviderOps
 */
final class ProviderPipelineTest extends TestCase {

	/** @var array<string, array{code:string,title:string,language:string,active:bool}> */
	private array $wpcode_store = [];

	/** @var array<string, array{code:string,title:string,language:string,active:bool,scope:string}> */
	private array $snippets_store = [];

	protected function setUp(): void {
		$GLOBALS['stonewright_test_user_caps'] = [
			'read'           => true,
			'manage_options' => true,
		];
		$GLOBALS['stonewright_test_user_logged_in']  = true;
		$GLOBALS['stonewright_test_current_user_id'] = 9;
		$GLOBALS['stonewright_test_transients']      = [];
		$GLOBALS['stonewright_test_options']         = [
			'stonewright_mode' => 'development',
		];
		$this->wpcode_store = [
			'12' => [
				'code'     => "<?php\necho 'before';\n",
				'title'    => 'Demo snippet',
				'language' => 'php',
				'active'   => true,
			],
		];
		$this->snippets_store = [
			'7' => [
				'code'     => "<?php\n// old\n",
				'title'    => 'CS demo',
				'language' => 'php',
				'active'   => true,
				'scope'    => 'global',
			],
		];

		$wpcode = new WpCodeProvider( $this->wpcode_backend() );
		$cs     = new CodeSnippetsProvider( $this->snippets_backend() );
		ProviderRegistry::set_for_tests(
			[
				'wpcode'         => $wpcode,
				'code-snippets'  => $cs,
			]
		);
	}

	protected function tearDown(): void {
		ProviderRegistry::reset_for_tests();
		$GLOBALS['stonewright_test_transients'] = [];
		$GLOBALS['stonewright_test_options']    = [];
		$GLOBALS['stonewright_test_user_caps']  = [];
	}

	public function test_discover_lists_first_party_providers(): void {
		// Reset to real providers for discover surface check.
		ProviderRegistry::reset_for_tests();
		$all = ProviderRegistry::discover_all();
		$ids = array_column( $all, 'id' );
		self::assertContains( 'wpcode', $ids );
		self::assertContains( 'code-snippets', $ids );
		self::assertContains( 'customizer-css', $ids );
		self::assertContains( 'theme-file', $ids );
	}

	public function test_wpcode_list_read_dry_run_stops_for_approval(): void {
		$provider = ProviderRegistry::get( 'wpcode' );
		self::assertNotNull( $provider );

		$list = $provider->list( [ 'limit' => 10 ] );
		self::assertIsArray( $list );
		self::assertSame( 1, $list['count'] );
		self::assertSame( '12', $list['items'][0]['id'] );

		$read = $provider->read( '12' );
		self::assertIsArray( $read );
		self::assertSame( "<?php\necho 'before';\n", $read['code'] );
		self::assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $read['content_sha256'] );

		$dry = $provider->dry_run(
			[
				'target_id' => '12',
				'code'      => "<?php\necho 'after';\n",
				'language'  => 'php',
			]
		);
		self::assertIsArray( $dry );
		self::assertTrue( $dry['dry_run'] );
		self::assertTrue( $dry['approval_required'] );
		self::assertTrue( $dry['agent_must_stop'] );
		self::assertNotEmpty( $dry['approval_url'] );
		self::assertSame( 'wpcode/snippet/12', $dry['path'] );
		self::assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $dry['before_sha256'] );
		self::assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $dry['after_sha256'] );
		self::assertGreaterThan( 0, $dry['changed_bytes'] );
	}

	public function test_wpcode_apply_requires_grant_then_verifies_and_can_rollback(): void {
		$provider = ProviderRegistry::get( 'wpcode' );
		self::assertNotNull( $provider );

		$candidate = "<?php\necho 'after';\n";
		$dry       = $provider->dry_run(
			[
				'target_id' => '12',
				'code'      => $candidate,
				'language'  => 'php',
			]
		);
		self::assertIsArray( $dry );

		// Apply without grant must fail closed.
		$blocked = $provider->apply(
			[
				'target_id'              => '12',
				'code'                   => $candidate,
				'language'               => 'php',
				'expected_before_sha256' => $dry['before_sha256'],
			]
		);
		self::assertInstanceOf( \WP_Error::class, $blocked );
		self::assertSame( 'stonewright_custom_code_grant_required', $blocked->get_error_code() );

		$grant = CustomCodeGrant::issue(
			[
				'path'         => 'wpcode/snippet/12',
				'after_sha256' => $dry['after_sha256'],
				'language'     => 'php',
			]
		);
		self::assertIsArray( $grant );

		$applied = $provider->apply(
			[
				'target_id'              => '12',
				'code'                   => $candidate,
				'language'               => 'php',
				'custom_code_grant'      => $grant['token'],
				'expected_before_sha256' => $dry['before_sha256'],
			]
		);
		self::assertIsArray( $applied );
		self::assertTrue( $applied['effect_verified'] );
		self::assertSame( 'verified', $applied['verification_status'] );
		self::assertSame( $candidate, $this->wpcode_store['12']['code'] );

		$rollback = $provider->rollback(
			[
				'snapshot_id' => $applied['snapshot_id'],
				'target_id'   => '12',
			]
		);
		self::assertIsArray( $rollback );
		self::assertTrue( $rollback['effect_verified'] );
		self::assertSame( "<?php\necho 'before';\n", $this->wpcode_store['12']['code'] );
	}

	public function test_wpcode_optimistic_concurrency_conflict(): void {
		$provider = ProviderRegistry::get( 'wpcode' );
		self::assertNotNull( $provider );
		$candidate = "<?php\necho 'x';\n";
		$dry       = $provider->dry_run(
			[
				'target_id' => '12',
				'code'      => $candidate,
				'language'  => 'php',
			]
		);
		self::assertIsArray( $dry );

		// External change before apply.
		$this->wpcode_store['12']['code'] = "<?php\necho 'raced';\n";

		$grant = CustomCodeGrant::issue(
			[
				'path'         => 'wpcode/snippet/12',
				'after_sha256' => $dry['after_sha256'],
				'language'     => 'php',
			]
		);
		self::assertIsArray( $grant );

		$conflict = $provider->apply(
			[
				'target_id'              => '12',
				'code'                   => $candidate,
				'language'               => 'php',
				'custom_code_grant'      => $grant['token'],
				'expected_before_sha256' => $dry['before_sha256'],
			]
		);
		self::assertInstanceOf( \WP_Error::class, $conflict );
		self::assertSame( 'stonewright_custom_code_concurrency_conflict', $conflict->get_error_code() );
	}

	public function test_code_snippets_pipeline(): void {
		$provider = ProviderRegistry::get( 'code-snippets' );
		self::assertNotNull( $provider );
		$candidate = "<?php\n// new\n";
		$dry       = $provider->dry_run(
			[
				'target_id' => '7',
				'code'      => $candidate,
				'language'  => 'php',
			]
		);
		self::assertIsArray( $dry );
		self::assertTrue( $dry['agent_must_stop'] );
		self::assertStringContainsString( 'code-snippets/snippet/7', $dry['path'] );

		$grant = CustomCodeGrant::issue(
			[
				'path'         => $dry['path'],
				'after_sha256' => $dry['after_sha256'],
				'language'     => 'php',
			]
		);
		self::assertIsArray( $grant );
		$applied = $provider->apply(
			[
				'target_id'              => '7',
				'code'                   => $candidate,
				'language'               => 'php',
				'custom_code_grant'      => $grant['token'],
				'expected_before_sha256' => $dry['before_sha256'],
			]
		);
		self::assertIsArray( $applied );
		self::assertTrue( $applied['effect_verified'] );
		self::assertSame( $candidate, $this->snippets_store['7']['code'] );
	}

	public function test_ability_discover_and_dry_run_via_ops(): void {
		// Re-inject test backends after discover reset in other tests.
		ProviderRegistry::set_for_tests(
			[
				'wpcode'        => new WpCodeProvider( $this->wpcode_backend() ),
				'code-snippets' => new CodeSnippetsProvider( $this->snippets_backend() ),
			]
		);
		$ability = new ProviderOps();
		self::assertSame( 'stonewright/custom-code-provider', $ability->name() );

		$discover = $ability->execute( [ 'action' => 'discover' ] );
		self::assertIsArray( $discover );
		self::assertTrue( $discover['ok'] );
		self::assertNotEmpty( $discover['providers'] );

		$dry = $ability->execute(
			[
				'action'    => 'dry-run',
				'provider'  => 'wpcode',
				'target_id' => '12',
				'code'      => "<?php\nreturn 1;\n",
				'language'  => 'php',
			]
		);
		self::assertIsArray( $dry );
		self::assertTrue( $dry['agent_must_stop'] );
		self::assertArrayNotHasKey( 'custom_code_grant', $dry );
	}

	public function test_invalid_php_rejected_on_dry_run(): void {
		$provider = ProviderRegistry::get( 'wpcode' );
		self::assertNotNull( $provider );
		$result = $provider->dry_run(
			[
				'target_id' => '12',
				'code'      => '<?php this is not valid php {{{',
				'language'  => 'php',
			]
		);
		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_php_candidate_invalid', $result->get_error_code() );
	}

	public function test_rollback_rejects_target_id_mismatch(): void {
		$provider = ProviderRegistry::get( 'wpcode' );
		self::assertNotNull( $provider );

		$candidate = "<?php\necho 'after-mismatch';\n";
		$dry       = $provider->dry_run(
			[
				'target_id' => '12',
				'code'      => $candidate,
				'language'  => 'php',
			]
		);
		self::assertIsArray( $dry );
		$grant = CustomCodeGrant::issue(
			[
				'path'         => 'wpcode/snippet/12',
				'after_sha256' => $dry['after_sha256'],
				'language'     => 'php',
			]
		);
		self::assertIsArray( $grant );
		$applied = $provider->apply(
			[
				'target_id'              => '12',
				'code'                   => $candidate,
				'language'               => 'php',
				'custom_code_grant'      => $grant['token'],
				'expected_before_sha256' => $dry['before_sha256'],
			]
		);
		self::assertIsArray( $applied );

		$mismatch = $provider->rollback(
			[
				'snapshot_id' => $applied['snapshot_id'],
				'target_id'   => '999',
			]
		);
		self::assertInstanceOf( \WP_Error::class, $mismatch );
		self::assertSame( 'stonewright_wpcode_snapshot_target_mismatch', $mismatch->get_error_code() );
		// Original target still holds the applied body — mismatch must not mutate.
		self::assertSame( $candidate, $this->wpcode_store['12']['code'] );
	}

	public function test_code_snippets_rollback_rejects_target_id_mismatch(): void {
		$provider = ProviderRegistry::get( 'code-snippets' );
		self::assertNotNull( $provider );

		$candidate = "<?php\n// changed before mismatch\n";
		$dry       = $provider->dry_run(
			[
				'target_id' => '7',
				'code'      => $candidate,
				'language'  => 'php',
			]
		);
		self::assertIsArray( $dry );
		$grant = CustomCodeGrant::issue(
			[
				'path'         => (string) $dry['path'],
				'after_sha256' => (string) $dry['after_sha256'],
				'language'     => 'php',
			]
		);
		self::assertIsArray( $grant );
		$applied = $provider->apply(
			[
				'target_id'              => '7',
				'code'                   => $candidate,
				'language'               => 'php',
				'custom_code_grant'      => $grant['token'],
				'expected_before_sha256' => $dry['before_sha256'],
			]
		);
		self::assertIsArray( $applied );

		$mismatch = $provider->rollback(
			[
				'snapshot_id' => $applied['snapshot_id'],
				'target_id'   => '99',
			]
		);
		self::assertInstanceOf( \WP_Error::class, $mismatch );
		self::assertSame( 'stonewright_code_snippets_snapshot_target_mismatch', $mismatch->get_error_code() );
		self::assertSame( $candidate, $this->snippets_store['7']['code'] );
	}

	public function test_wpcode_post_type_guard_rejects_arbitrary_posts(): void {
		$provider = new WpCodeProvider();
		$guard    = new \ReflectionMethod( $provider, 'is_wpcode_post_type' );

		self::assertFalse( $guard->invoke( $provider, 'post' ) );
		self::assertFalse( $guard->invoke( $provider, 'page' ) );
	}

	public function test_list_read_require_code_edit_caps(): void {
		$ability = new ProviderOps();
		$GLOBALS['stonewright_test_user_caps'] = [
			'read'               => true,
			'manage_options'     => false,
			'edit_theme_options' => false,
		];

		$discover = $ability->permission_callback( [ 'action' => 'discover' ] );
		self::assertTrue( $discover );

		$list = $ability->permission_callback( [ 'action' => 'list', 'provider' => 'wpcode' ] );
		self::assertInstanceOf( \WP_Error::class, $list );

		$read = $ability->permission_callback( [ 'action' => 'read', 'provider' => 'wpcode', 'target_id' => '12' ] );
		self::assertInstanceOf( \WP_Error::class, $read );

		$GLOBALS['stonewright_test_user_caps'] = [
			'read'               => true,
			'manage_options'     => false,
			'edit_theme_options' => true,
		];
		$wpcode_list = $ability->permission_callback( [ 'action' => 'list', 'provider' => 'wpcode' ] );
		self::assertInstanceOf( \WP_Error::class, $wpcode_list );
		$wpcode_read = $ability->permission_callback( [ 'action' => 'read', 'provider' => 'wpcode', 'target_id' => '12' ] );
		self::assertInstanceOf( \WP_Error::class, $wpcode_read );
		self::assertTrue( $ability->permission_callback( [ 'action' => 'list', 'provider' => 'theme-file' ] ) );
		self::assertTrue( $ability->permission_callback( [ 'action' => 'read', 'provider' => 'customizer-css' ] ) );

		// Writes still require manage_options even when edit_theme_options is present.
		$apply = $ability->permission_callback( [ 'action' => 'apply', 'provider' => 'wpcode' ] );
		self::assertInstanceOf( \WP_Error::class, $apply );

		$GLOBALS['stonewright_test_user_caps'] = [
			'read'           => true,
			'manage_options' => true,
		];
		self::assertTrue( $ability->permission_callback( [ 'action' => 'read', 'provider' => 'wpcode', 'target_id' => '12' ] ) );
		self::assertTrue( $ability->permission_callback( [ 'action' => 'apply', 'provider' => 'wpcode' ] ) );
	}

	public function test_production_safe_apply_and_rollback_require_confirmation_token(): void {
		ProviderRegistry::set_for_tests(
			[
				'wpcode' => new WpCodeProvider( $this->wpcode_backend() ),
			]
		);
		$GLOBALS['stonewright_test_options']['stonewright_mode'] = 'production-safe';
		$ability = new ProviderOps();
		foreach ( [ 'apply', 'rollback' ] as $action ) {
			$result = $ability->execute(
				[
					'action'            => $action,
					'provider'          => 'wpcode',
					'target_id'         => '12',
					'code'              => "<?php\necho 1;\n",
					'snapshot_id'       => 'synthetic-snapshot',
					'custom_code_grant' => 'not-a-real-grant',
				]
			);
			self::assertInstanceOf( \WP_Error::class, $result, $action );
			self::assertSame( 'stonewright_confirmation_required', $result->get_error_code(), $action );
		}
	}

	/** @return callable */
	private function wpcode_backend(): callable {
		return function ( string $op, array $args ) {
			if ( 'discover' === $op ) {
				return [ 'active' => true, 'version' => '2.2.0' ];
			}
			if ( 'list' === $op ) {
				$items = [];
				foreach ( $this->wpcode_store as $id => $row ) {
					$items[] = [
						'id'       => (string) $id,
						'title'    => $row['title'],
						'language' => $row['language'],
						'active'   => $row['active'],
						'path'     => 'wpcode/snippet/' . $id,
					];
				}
				return [ 'ok' => true, 'provider' => 'wpcode', 'count' => count( $items ), 'items' => $items ];
			}
			if ( 'read' === $op ) {
				$id = (string) ( $args['id'] ?? '' );
				if ( ! isset( $this->wpcode_store[ $id ] ) ) {
					return new \WP_Error( 'stonewright_wpcode_not_found', 'missing', [ 'status' => 404 ] );
				}
				$row = $this->wpcode_store[ $id ];
				return [
					'id'       => $id,
					'title'    => $row['title'],
					'code'     => $row['code'],
					'language' => $row['language'],
					'active'   => $row['active'],
				];
			}
			if ( 'save' === $op ) {
				$id = (string) ( $args['id'] ?? '' );
				$this->wpcode_store[ $id ]['code'] = (string) ( $args['code'] ?? '' );
				return true;
			}
			return null;
		};
	}

	/** @return callable */
	private function snippets_backend(): callable {
		return function ( string $op, array $args ) {
			if ( 'discover' === $op ) {
				return [ 'active' => true, 'version' => '3.6.5' ];
			}
			if ( 'list' === $op ) {
				$items = [];
				foreach ( $this->snippets_store as $id => $row ) {
					$items[] = [
						'id'       => (string) $id,
						'title'    => $row['title'],
						'language' => $row['language'],
						'active'   => $row['active'],
						'scope'    => $row['scope'],
						'path'     => 'code-snippets/snippet/' . $id,
					];
				}
				return [ 'ok' => true, 'provider' => 'code-snippets', 'count' => count( $items ), 'items' => $items ];
			}
			if ( 'read' === $op ) {
				$id = (string) ( $args['id'] ?? '' );
				if ( ! isset( $this->snippets_store[ $id ] ) ) {
					return new \WP_Error( 'stonewright_code_snippets_not_found', 'missing', [ 'status' => 404 ] );
				}
				$row = $this->snippets_store[ $id ];
				return [
					'id'       => $id,
					'title'    => $row['title'],
					'code'     => $row['code'],
					'language' => $row['language'],
					'active'   => $row['active'],
					'scope'    => $row['scope'],
				];
			}
			if ( 'save' === $op ) {
				$id = (string) ( $args['id'] ?? '' );
				$this->snippets_store[ $id ]['code'] = (string) ( $args['code'] ?? '' );
				return true;
			}
			return null;
		};
	}
}
