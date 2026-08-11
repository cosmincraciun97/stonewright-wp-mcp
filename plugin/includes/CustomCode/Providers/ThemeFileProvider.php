<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\CustomCode\Providers;

use Stonewright\WpMcp\Abilities\Themes\ThemeFilePatch;
use Stonewright\WpMcp\Abilities\Themes\ThemeFileRead;
use Stonewright\WpMcp\CustomCode\ProviderInterface;

/**
 * Adapter that delegates theme file work to existing typed abilities.
 */
final class ThemeFileProvider implements ProviderInterface {

	public function id(): string {
		return 'theme-file';
	}

	public function label(): string {
		return 'Theme file patch';
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
			'notes'        => 'Delegates to stonewright/theme-file-read and stonewright/theme-file-patch (grant-gated).',
		];
	}

	/** @param array<string, mixed> $args */
	public function list( array $args = [] ) {
		// Theme file surface is path-addressed; list returns allowlisted common targets.
		$paths = [ 'style.css', 'functions.php', 'assets/css/custom.css', 'assets/js/custom.js' ];
		$items = [];
		foreach ( $paths as $path ) {
			$items[] = [
				'id'       => $path,
				'title'    => $path,
				'language' => str_ends_with( $path, '.css' ) ? 'css' : ( str_ends_with( $path, '.js' ) ? 'js' : 'php' ),
				'active'   => true,
				'path'     => $path,
			];
		}
		return [
			'ok'       => true,
			'provider' => $this->id(),
			'count'    => count( $items ),
			'items'    => $items,
			'note'     => 'Paths are allowlisted by ThemeFilePaths; not every listed path may exist on disk.',
		];
	}

	public function read( string $target_id ) {
		$ability = new ThemeFileRead();
		$result  = $ability->execute(
			[
				'path' => $target_id,
			]
		);
		if ( $result instanceof \WP_Error ) {
			return $result;
		}
		$content = (string) ( $result['content'] ?? $result['contents'] ?? '' );
		return [
			'ok'             => true,
			'provider'       => $this->id(),
			'id'             => $target_id,
			'title'          => $target_id,
			'path'           => $target_id,
			'bytes'          => strlen( $content ),
			'content_sha256' => hash( 'sha256', $content ),
			'code'           => $content,
			'language'       => $this->language_for( $target_id ),
		];
	}

	/** @param array<string, mixed> $args */
	public function dry_run( array $args ) {
		$path = sanitize_text_field( (string) ( $args['target_id'] ?? $args['path'] ?? '' ) );
		if ( '' === $path ) {
			return new \WP_Error( 'stonewright_theme_file_path_required', __( 'path/target_id is required.', 'stonewright' ), [ 'status' => 400 ] );
		}
		$ability = new ThemeFilePatch();
		$input   = [
			'path'              => $path,
			'mode'              => (string) ( $args['mode'] ?? 'replace_all' ),
			'content'           => (string) ( $args['code'] ?? $args['content'] ?? '' ),
			'dry_run'           => true,
			'native_gap'        => $args['native_gap'] ?? [
				'reason'        => 'Theme file provider path for allowlisted child-theme assets.',
				'methods_tried' => [ 'typed_api' ],
			],
			'max_changed_bytes' => (int) ( $args['max_changed_bytes'] ?? 65536 ),
			'marker'            => (string) ( $args['marker'] ?? '' ),
			'start_marker'      => (string) ( $args['start_marker'] ?? '' ),
			'end_marker'        => (string) ( $args['end_marker'] ?? '' ),
			'create_if_missing' => (bool) ( $args['create_if_missing'] ?? false ),
			'theme'             => (string) ( $args['theme'] ?? 'stylesheet' ),
		];
		$result = $ability->execute( $input );
		if ( $result instanceof \WP_Error ) {
			return $result;
		}
		$result['provider']  = $this->id();
		$result['target_id'] = $path;
		return $result;
	}

	/** @param array<string, mixed> $args */
	public function apply( array $args ) {
		$path = sanitize_text_field( (string) ( $args['target_id'] ?? $args['path'] ?? '' ) );
		if ( '' === $path ) {
			return new \WP_Error( 'stonewright_theme_file_path_required', __( 'path/target_id is required.', 'stonewright' ), [ 'status' => 400 ] );
		}
		$ability = new ThemeFilePatch();
		$input   = [
			'path'               => $path,
			'mode'               => (string) ( $args['mode'] ?? 'replace_all' ),
			'content'            => (string) ( $args['code'] ?? $args['content'] ?? '' ),
			'dry_run'            => false,
			'custom_code_grant'  => (string) ( $args['custom_code_grant'] ?? '' ),
			'max_changed_bytes'  => (int) ( $args['max_changed_bytes'] ?? 65536 ),
			'confirmation_token' => (string) ( $args['confirmation_token'] ?? '' ),
			'marker'             => (string) ( $args['marker'] ?? '' ),
			'start_marker'       => (string) ( $args['start_marker'] ?? '' ),
			'end_marker'         => (string) ( $args['end_marker'] ?? '' ),
			'create_if_missing'  => (bool) ( $args['create_if_missing'] ?? false ),
			'theme'              => (string) ( $args['theme'] ?? 'stylesheet' ),
			'smoke_url'          => (string) ( $args['smoke_url'] ?? '' ),
		];
		$result = $ability->execute( $input );
		if ( $result instanceof \WP_Error ) {
			return $result;
		}
		$result['provider']  = $this->id();
		$result['target_id'] = $path;
		return $result;
	}

	/** @param array<string, mixed> $args */
	public function verify( array $args ) {
		$path = sanitize_text_field( (string) ( $args['target_id'] ?? $args['path'] ?? '' ) );
		$read = $this->read( $path );
		if ( $read instanceof \WP_Error ) {
			return $read;
		}
		$expected = strtolower( (string) ( $args['expected_sha256'] ?? $args['after_sha256'] ?? '' ) );
		$hash     = (string) ( $read['content_sha256'] ?? '' );
		$ok       = '' === $expected || hash_equals( $expected, $hash );
		return [
			'ok'                  => true,
			'provider'            => $this->id(),
			'target_id'           => $path,
			'path'                => $path,
			'content_sha256'      => $hash,
			'expected_sha256'     => $expected,
			'effect_verified'     => $ok,
			'verification_status' => $ok ? 'verified' : 'failed',
		];
	}

	/** @param array<string, mixed> $args */
	public function rollback( array $args ) {
		return new \WP_Error(
			'stonewright_theme_file_rollback_via_backup',
			__( 'Theme file rollback uses stonewright/theme-backup-restore or the automatic smoke rollback on apply failure.', 'stonewright' ),
			[
				'status'           => 400,
				'provider'         => $this->id(),
				'recommended_next' => 'stonewright/theme-backup-restore',
			]
		);
	}

	private function language_for( string $path ): string {
		$path = strtolower( $path );
		if ( str_ends_with( $path, '.css' ) ) {
			return 'css';
		}
		if ( str_ends_with( $path, '.js' ) ) {
			return 'js';
		}
		if ( str_ends_with( $path, '.html' ) || str_ends_with( $path, '.htm' ) ) {
			return 'html';
		}
		return 'php';
	}
}
