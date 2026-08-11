<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\CustomCode\Providers;

use Stonewright\WpMcp\Abilities\Themes\ThemeCustomCss;
use Stonewright\WpMcp\CustomCode\ProviderInterface;

/**
 * Adapter that delegates Customizer CSS work to the existing typed ability.
 */
final class CustomizerCssProvider implements ProviderInterface {

	public function id(): string {
		return 'customizer-css';
	}

	public function label(): string {
		return 'Customizer CSS';
	}

	public function discover(): array {
		return [
			'id'           => $this->id(),
			'label'        => $this->label(),
			'available'    => true,
			'active'       => true,
			'version'      => (string) ( get_bloginfo( 'version' ) ?: 'core' ),
			'supported'    => true,
			'plugin_file'  => 'wordpress-core',
			'capabilities' => [ 'discover', 'list', 'read', 'dry-run', 'apply', 'verify', 'rollback' ],
			'notes'        => 'Delegates to stonewright/theme-custom-css (grant-gated).',
		];
	}

	/** @param array<string, mixed> $args */
	public function list( array $args = [] ) {
		$stylesheet = (string) get_stylesheet();
		$path       = 'customizer/custom-css/' . ( '' !== $stylesheet ? sanitize_key( $stylesheet ) : 'active-theme' ) . '.css';
		$css        = (string) wp_get_custom_css();
		return [
			'ok'       => true,
			'provider' => $this->id(),
			'count'    => 1,
			'items'    => [
				[
					'id'       => 'active',
					'title'    => 'Active theme Customizer CSS',
					'language' => 'css',
					'active'   => true,
					'path'     => $path,
					'bytes'    => strlen( $css ),
				],
			],
		];
	}

	public function read( string $target_id ) {
		$ability = new ThemeCustomCss();
		$result  = $ability->execute( [ 'action' => 'get' ] );
		if ( $result instanceof \WP_Error ) {
			return $result;
		}
		$css        = (string) ( $result['css'] ?? '' );
		$stylesheet = (string) ( $result['stylesheet'] ?? get_stylesheet() );
		$path       = 'customizer/custom-css/' . ( '' !== $stylesheet ? sanitize_key( $stylesheet ) : 'active-theme' ) . '.css';
		return [
			'ok'             => true,
			'provider'       => $this->id(),
			'id'             => 'active',
			'title'          => 'Active theme Customizer CSS',
			'language'       => 'css',
			'path'           => $path,
			'bytes'          => strlen( $css ),
			'content_sha256' => hash( 'sha256', $css ),
			'code'           => $css,
			'stylesheet'     => $stylesheet,
		];
	}

	/** @param array<string, mixed> $args */
	public function dry_run( array $args ) {
		$ability = new ThemeCustomCss();
		$input   = [
			'action'            => 'update',
			'css'               => (string) ( $args['code'] ?? $args['css'] ?? $args['content'] ?? '' ),
			'dry_run'           => true,
			'native_gap'        => $args['native_gap'] ?? [
				'reason'        => 'Customizer CSS provider path for theme-level custom CSS.',
				'methods_tried' => [ 'typed_api' ],
			],
			'max_changed_bytes' => (int) ( $args['max_changed_bytes'] ?? 65536 ),
		];
		$result = $ability->execute( $input );
		if ( $result instanceof \WP_Error ) {
			return $result;
		}
		$result['provider'] = $this->id();
		$result['target_id'] = 'active';
		return $result;
	}

	/** @param array<string, mixed> $args */
	public function apply( array $args ) {
		$ability = new ThemeCustomCss();
		$input   = [
			'action'            => 'update',
			'css'               => (string) ( $args['code'] ?? $args['css'] ?? $args['content'] ?? '' ),
			'dry_run'           => false,
			'custom_code_grant' => (string) ( $args['custom_code_grant'] ?? '' ),
			'max_changed_bytes' => (int) ( $args['max_changed_bytes'] ?? 65536 ),
			'confirmation_token'=> (string) ( $args['confirmation_token'] ?? '' ),
		];
		$result = $ability->execute( $input );
		if ( $result instanceof \WP_Error ) {
			return $result;
		}
		$result['provider']  = $this->id();
		$result['target_id'] = 'active';
		return $result;
	}

	/** @param array<string, mixed> $args */
	public function verify( array $args ) {
		$read = $this->read( 'active' );
		if ( $read instanceof \WP_Error ) {
			return $read;
		}
		$expected = strtolower( (string) ( $args['expected_sha256'] ?? $args['after_sha256'] ?? '' ) );
		$hash     = (string) ( $read['content_sha256'] ?? '' );
		$ok       = '' === $expected || hash_equals( $expected, $hash );
		return [
			'ok'                  => true,
			'provider'            => $this->id(),
			'target_id'           => 'active',
			'path'                => (string) ( $read['path'] ?? '' ),
			'content_sha256'      => $hash,
			'expected_sha256'     => $expected,
			'effect_verified'     => $ok,
			'verification_status' => $ok ? 'verified' : 'failed',
		];
	}

	/** @param array<string, mixed> $args */
	public function rollback( array $args ) {
		return new \WP_Error(
			'stonewright_customizer_css_rollback_via_backup',
			__( 'Customizer CSS rollback uses stonewright/theme-backup-restore or the post snapshot from apply.', 'stonewright' ),
			[
				'status'           => 400,
				'provider'         => $this->id(),
				'recommended_next' => 'stonewright/theme-backup-restore or restore the Customizer CSS post snapshot',
			]
		);
	}
}
