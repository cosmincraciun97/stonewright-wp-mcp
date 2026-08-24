<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Elementor;

use Stonewright\WpMcp\Elementor\Write\CssDirectoryLease;

/**
 * Guards one Elementor post CSS update against collateral global asset loss.
 */
final class CssAssetTransaction {
	private const MAX_FILES = 2000;
	private const MAX_BYTES = 67108864;
	private const LEASE_TTL = 120;

	/**
	 * @param callable():(array<string,mixed>|\WP_Error) $operation
	 * @return array{ok:true,operation_result:array<string,mixed>,css_evidence:array<string,mixed>}|\WP_Error
	 */
	public static function run( int $post_id, callable $operation ): array|\WP_Error {
		if ( $post_id <= 0 ) {
			return self::error( 'stonewright_elementor_css_invalid_post', 'A valid Elementor post id is required.', [ 'status' => 400 ] );
		}

		$location = self::css_location();
		if ( $location instanceof \WP_Error ) {
			return $location;
		}
		$lease = CssDirectoryLease::acquire(
			$location['canonical_scope'],
			'css-' . substr( hash( 'sha256', wp_generate_uuid4() . '|' . $post_id ), 0, 32 ),
			self::LEASE_TTL
		);
		if ( $lease instanceof \WP_Error ) {
			return $lease;
		}

		$metadata_before = self::capture_css_metadata( $post_id );
		try {
			$lease_check = self::renew_lease( $lease );
			if ( $lease_check instanceof \WP_Error ) {
				return $lease_check;
			}
			$lease = $lease_check;

			$before = self::capture( $location );
			if ( $before instanceof \WP_Error ) {
				return $before;
			}
			$probes_before = self::probe_protected_assets( $post_id, $before, $location, $lease );
			if ( $probes_before instanceof \WP_Error ) {
				return $probes_before;
			}

			$lease_check = self::renew_lease( $lease );
			if ( $lease_check instanceof \WP_Error ) {
				return self::rollback_error( $location, $post_id, $before, $metadata_before, 'stonewright_elementor_css_lease_lost', 'The Elementor CSS transaction lease was lost before the write.', $lease );
			}
			$lease = $lease_check;

			$valid = self::revalidate_location( $location );
			if ( $valid instanceof \WP_Error ) {
				return $valid;
			}

			try {
				$operation_result = $operation();
			} catch ( \Throwable $error ) {
				return self::rollback_error(
					$location,
					$post_id,
					$before,
					$metadata_before,
					'stonewright_elementor_css_operation_failed',
					'Elementor post CSS regeneration failed.',
					$lease,
					[ 'root_error_code' => 'operation_throwable' ]
				);
			}

			if ( $operation_result instanceof \WP_Error || ! is_array( $operation_result ) || false === ( $operation_result['ok'] ?? true ) ) {
				$root_code = $operation_result instanceof \WP_Error
					? (string) $operation_result->get_error_code()
					: (string) ( is_array( $operation_result ) ? ( $operation_result['error_code'] ?? 'operation_failed' ) : 'invalid_operation_result' );
				$extra = [ 'root_error_code' => sanitize_key( $root_code ) ];
				if ( is_array( $operation_result ) ) {
					$evidence = self::safe_operation_evidence( $operation_result );
					if ( [] !== $evidence ) {
						$extra['operation_evidence'] = $evidence;
					}
				}
				return self::rollback_error(
					$location,
					$post_id,
					$before,
					$metadata_before,
					'stonewright_elementor_css_operation_failed',
					'Elementor post CSS regeneration did not complete.',
					$lease,
					$extra
				);
			}

			$lease_check = self::renew_lease( $lease );
			if ( $lease_check instanceof \WP_Error ) {
				return self::rollback_error( $location, $post_id, $before, $metadata_before, 'stonewright_elementor_css_lease_lost', 'The Elementor CSS transaction lease was lost during verification.', $lease );
			}
			$lease = $lease_check;
			$after = self::capture( $location );
			if ( $after instanceof \WP_Error ) {
				return self::rollback_error(
					$location,
					$post_id,
					$before,
					$metadata_before,
					'stonewright_elementor_css_manifest_failed',
					'Elementor CSS could not be inspected after regeneration.',
					$lease
				);
			}

			$target     = 'post-' . $post_id . '.css';
			$collateral = self::collateral_changes( $before, $after, $target );
			if ( [] !== $collateral || ! isset( $after['files'][ $target ] ) ) {
				return self::rollback_error(
					$location,
					$post_id,
					$before,
					$metadata_before,
					'stonewright_elementor_css_collateral_change',
					'Elementor changed CSS assets outside the target post; the asset snapshot restore was attempted.',
					$lease,
					[
						'collateral_count' => count( $collateral ),
						'target_present'   => isset( $after['files'][ $target ] ),
					]
				);
			}

			$lease_check = self::renew_lease( $lease );
			if ( $lease_check instanceof \WP_Error ) {
				return self::rollback_error( $location, $post_id, $before, $metadata_before, 'stonewright_elementor_css_lease_lost', 'The Elementor CSS transaction lease was lost during the protected asset check.', $lease );
			}
			$lease = $lease_check;
			$probes_after = self::probe_protected_assets( $post_id, $after, $location, $lease );
			if ( $probes_after instanceof \WP_Error ) {
				return self::rollback_error(
					$location,
					$post_id,
					$before,
					$metadata_before,
					'stonewright_elementor_css_probe_failed',
					'One or more protected Elementor CSS assets failed the post-write HTTP check.',
					$lease,
					[ 'root_error_code' => sanitize_key( (string) $probes_after->get_error_code() ) ]
				);
			}

			$metadata_after = self::capture_css_metadata( $post_id );
			return [
				'ok'               => true,
				'operation_result' => $operation_result,
				'css_evidence'     => [
					'target'                  => $target,
					'before_file_count'       => $before['file_count'],
					'after_file_count'        => $after['file_count'],
					'before_manifest_sha256'  => self::manifest_hash( $before ),
					'after_manifest_sha256'   => self::manifest_hash( $after ),
					'protected_probes_before' => $probes_before,
					'protected_probes_after'  => $probes_after,
					'collateral_change_count' => 0,
					'css_metadata'            => [
						'before_present' => $metadata_before['exists'],
						'after_present'  => $metadata_after['exists'],
					],
					'rollback_status'         => 'not_needed',
				],
			];
		} finally {
			CssDirectoryLease::release( $lease );
		}
	}

	/**
	 * Return the exact path and same-origin URL Elementor must report for one post.
	 * This is an internal typed boundary; callers must not expose the path.
	 *
	 * @return array{path:string,url:string}|\WP_Error
	 */
	public static function expected_post_css_location( int $post_id ): array|\WP_Error {
		if ( $post_id <= 0 ) {
			return self::error( 'stonewright_elementor_css_invalid_post', 'A valid Elementor post id is required.', [ 'status' => 400 ] );
		}
		$location = self::css_location();
		if ( $location instanceof \WP_Error ) {
			return $location;
		}
		return [
			'path' => $location['dir'] . '/post-' . $post_id . '.css',
			'url'  => $location['url'] . '/post-' . $post_id . '.css',
		];
	}

	/** @return array{dir:string,url:string,baseurl:string,canonical_scope:string,base_real:string,dir_real:string}|\WP_Error */
	private static function css_location(): array|\WP_Error {
		$uploads = wp_upload_dir();
		$basedir = is_array( $uploads ) ? (string) ( $uploads['basedir'] ?? '' ) : '';
		$baseurl = is_array( $uploads ) ? (string) ( $uploads['baseurl'] ?? '' ) : '';
		if ( '' === $basedir || '' === $baseurl || ! empty( $uploads['error'] ) ) {
			return self::error( 'stonewright_elementor_css_uploads_unavailable', 'The WordPress uploads location is unavailable.' );
		}

		$basedir   = rtrim( wp_normalize_path( $basedir ), '/' );
		$base_real = realpath( $basedir );
		if ( false === $base_real ) {
			return self::error( 'stonewright_elementor_css_path_unsafe', 'The Elementor CSS directory boundary is unsafe.' );
		}
		$base_real = rtrim( wp_normalize_path( $base_real ), '/' );
		$dir       = $basedir . '/elementor/css';
		$elementor = $basedir . '/elementor';
		if ( is_link( $elementor ) || is_link( $dir ) ) {
			return self::error( 'stonewright_elementor_css_path_unsafe', 'The Elementor CSS directory boundary is unsafe.' );
		}

		$dir_real = '';
		if ( file_exists( $dir ) && ! is_dir( $dir ) ) {
			return self::error( 'stonewright_elementor_css_path_unsafe', 'The Elementor CSS directory boundary is unsafe.' );
		}
		if ( is_dir( $dir ) ) {
			$resolved = realpath( $dir );
			$expected = $base_real . '/elementor/css';
			if ( false === $resolved || wp_normalize_path( $resolved ) !== $expected ) {
				return self::error( 'stonewright_elementor_css_path_unsafe', 'The Elementor CSS directory boundary is unsafe.' );
			}
			$dir_real = $expected;
		}

		return [
			'dir'             => $dir,
			'url'             => rtrim( $baseurl, '/' ) . '/elementor/css',
			'baseurl'         => rtrim( $baseurl, '/' ),
			'canonical_scope' => $base_real . '/elementor/css',
			'base_real'       => $base_real,
			'dir_real'        => $dir_real,
		];
	}

	/** @param array{dir:string,url:string,baseurl:string,canonical_scope:string,base_real:string,dir_real:string} $location */
	private static function revalidate_location( array $location ): bool|\WP_Error {
		$uploads = wp_upload_dir();
		$basedir = is_array( $uploads ) ? rtrim( wp_normalize_path( (string) ( $uploads['basedir'] ?? '' ) ), '/' ) : '';
		$baseurl = is_array( $uploads ) ? rtrim( (string) ( $uploads['baseurl'] ?? '' ), '/' ) : '';
		if ( '' === $basedir || '' === $baseurl || ! empty( $uploads['error'] ) || $basedir !== dirname( $location['dir'], 2 ) || $baseurl !== $location['baseurl'] ) {
			return self::error( 'stonewright_elementor_css_path_changed', 'The Elementor CSS directory boundary changed during the transaction.' );
		}
		if ( is_link( $basedir . '/elementor' ) || is_link( $location['dir'] ) ) {
			return self::error( 'stonewright_elementor_css_path_unsafe', 'The Elementor CSS directory boundary is unsafe.' );
		}
		$base_real = realpath( $basedir );
		if ( false === $base_real || wp_normalize_path( $base_real ) !== $location['base_real'] ) {
			return self::error( 'stonewright_elementor_css_path_changed', 'The Elementor CSS directory boundary changed during the transaction.' );
		}
		if ( file_exists( $location['dir'] ) && ! is_dir( $location['dir'] ) ) {
			return self::error( 'stonewright_elementor_css_path_unsafe', 'The Elementor CSS directory boundary is unsafe.' );
		}
		if ( ! is_dir( $location['dir'] ) ) {
			if ( '' !== $location['dir_real'] ) {
				return self::error( 'stonewright_elementor_css_path_changed', 'The Elementor CSS directory boundary changed during the transaction.' );
			}
			return true;
		}
		$dir_real = realpath( $location['dir'] );
		$expected = $location['base_real'] . '/elementor/css';
		if ( false === $dir_real || wp_normalize_path( $dir_real ) !== $expected || ( '' !== $location['dir_real'] && wp_normalize_path( $dir_real ) !== $location['dir_real'] ) ) {
			return self::error( 'stonewright_elementor_css_path_changed', 'The Elementor CSS directory boundary changed during the transaction.' );
		}
		return true;
	}

	/** @param array{dir:string,url:string,baseurl:string,canonical_scope:string,base_real:string,dir_real:string} $location @return array{files:array<string,array{bytes:string,size:int,sha256:string,mode:int}>,file_count:int,total_bytes:int}|\WP_Error */
	private static function capture( array $location ): array|\WP_Error {
		$valid = self::revalidate_location( $location );
		if ( $valid instanceof \WP_Error ) {
			return $valid;
		}
		if ( ! is_dir( $location['dir'] ) ) {
			return [ 'files' => [], 'file_count' => 0, 'total_bytes' => 0 ];
		}

		$files         = [];
		$total         = 0;
		$canonical_dir = realpath( $location['dir'] );
		if ( false === $canonical_dir ) {
			return self::error( 'stonewright_elementor_css_manifest_unsafe', 'The Elementor CSS directory could not be canonically identified.' );
		}
		$canonical_dir = wp_normalize_path( $canonical_dir );
		try {
			$iterator = new \FilesystemIterator( $location['dir'], \FilesystemIterator::SKIP_DOTS );
			foreach ( $iterator as $item ) {
				$name = $item->getBasename();
				if ( ! self::safe_filename( $name ) || $item->isLink() || is_link( $item->getPathname() ) || ! $item->isFile() ) {
					return self::error( 'stonewright_elementor_css_manifest_unsafe', 'The Elementor CSS directory contains an unsupported entry.' );
				}
				$resolved = realpath( $item->getPathname() );
				if ( false === $resolved || wp_normalize_path( $resolved ) !== $canonical_dir . '/' . $name ) {
					return self::error( 'stonewright_elementor_css_manifest_unsafe', 'The Elementor CSS directory contains an unsafe entry.' );
				}
				if ( count( $files ) >= self::MAX_FILES ) {
					return self::error( 'stonewright_elementor_css_manifest_too_large', 'The Elementor CSS manifest exceeds the file limit.' );
				}
				$bytes = file_get_contents( $item->getPathname() );
				if ( false === $bytes || strlen( $bytes ) > self::MAX_BYTES || $total + strlen( $bytes ) > self::MAX_BYTES ) {
					return self::error( 'stonewright_elementor_css_manifest_read_failed', 'An Elementor CSS asset could not be read.' );
				}
				$mode = fileperms( $item->getPathname() );
				$files[ $name ] = [
					'bytes'  => $bytes,
					'size'   => strlen( $bytes ),
					'sha256' => hash( 'sha256', $bytes ),
					'mode'   => false === $mode ? 0644 : $mode & 0777,
				];
				$total += strlen( $bytes );
			}
		} catch ( \Throwable $error ) {
			return self::error( 'stonewright_elementor_css_manifest_read_failed', 'The Elementor CSS directory could not be inspected.' );
		}
		$valid = self::revalidate_location( $location );
		if ( $valid instanceof \WP_Error ) {
			return $valid;
		}
		ksort( $files );
		return [ 'files' => $files, 'file_count' => count( $files ), 'total_bytes' => $total ];
	}

	/** @param array{files:array<string,array{bytes:string,size:int,sha256:string,mode:int}>,file_count:int,total_bytes:int} $manifest @param array{dir:string,url:string,baseurl:string,canonical_scope:string,base_real:string,dir_real:string} $location @param array{key:string,scope:string,owner:string,acquired_at:int,expires_at:int,ttl:int} $lease @return list<array{asset:string,status:int,url_sha256:string}>|\WP_Error */
	private static function probe_protected_assets( int $post_id, array $manifest, array $location, array &$lease ): array|\WP_Error {
		$valid = self::revalidate_location( $location );
		if ( $valid instanceof \WP_Error ) {
			return $valid;
		}
		$protected = [ 'post-' . $post_id . '.css', 'custom-frontend.min.css', 'custom-pro-widget-nav-menu.min.css' ];
		$probes = [];
		foreach ( $protected as $asset ) {
			if ( ! isset( $manifest['files'][ $asset ] ) ) {
				continue;
			}
			$lease_check = self::renew_lease( $lease );
			if ( $lease_check instanceof \WP_Error ) {
				return $lease_check;
			}
			$lease = $lease_check;
			$url = $location['url'] . '/' . rawurlencode( $asset );
			if ( ! self::same_origin( home_url( '/' ), $url ) ) {
				return self::error( 'stonewright_elementor_css_probe_unsafe_origin', 'A protected Elementor CSS URL is not same-origin.' );
			}
			$response = wp_safe_remote_get(
				$url,
				[
					'timeout'             => 10,
					'redirection'         => 0,
					'limit_response_size' => 1,
					'sslverify'           => true,
				]
			);
			if ( $response instanceof \WP_Error ) {
				return self::error( 'stonewright_elementor_css_probe_failed', 'A protected Elementor CSS URL could not be fetched.' );
			}
			$status   = wp_remote_retrieve_response_code( $response );
			$redirect = (string) wp_remote_retrieve_header( $response, 'location' );
			if ( 200 !== $status || '' !== $redirect ) {
				return self::error( 'stonewright_elementor_css_probe_failed', 'A protected Elementor CSS URL did not return HTTP 200 without redirect.' );
			}
			$probes[] = [ 'asset' => $asset, 'status' => $status, 'url_sha256' => hash( 'sha256', $url ) ];
		}
		return $probes;
	}

	private static function same_origin( string $left, string $right ): bool {
		$a = wp_parse_url( $left );
		$b = wp_parse_url( $right );
		if ( ! is_array( $a ) || ! is_array( $b ) ) {
			return false;
		}
		$scheme_a = strtolower( (string) ( $a['scheme'] ?? '' ) );
		$scheme_b = strtolower( (string) ( $b['scheme'] ?? '' ) );
		$host_a   = strtolower( (string) ( $a['host'] ?? '' ) );
		$host_b   = strtolower( (string) ( $b['host'] ?? '' ) );
		$port_a   = (int) ( $a['port'] ?? ( 'https' === $scheme_a ? 443 : 80 ) );
		$port_b   = (int) ( $b['port'] ?? ( 'https' === $scheme_b ? 443 : 80 ) );
		return in_array( $scheme_a, [ 'http', 'https' ], true ) && $scheme_a === $scheme_b && '' !== $host_a && $host_a === $host_b && $port_a === $port_b;
	}

	/** @param array{files:array<string,array{bytes:string,size:int,sha256:string,mode:int}>} $before @param array{files:array<string,array{bytes:string,size:int,sha256:string,mode:int}>} $after @return list<string> */
	private static function collateral_changes( array $before, array $after, string $target ): array {
		$names = array_unique( array_merge( array_keys( $before['files'] ), array_keys( $after['files'] ) ) );
		$out   = [];
		foreach ( $names as $name ) {
			if ( $target === $name ) {
				continue;
			}
			$left  = $before['files'][ $name ] ?? null;
			$right = $after['files'][ $name ] ?? null;
			if ( ! is_array( $left ) || ! is_array( $right ) || self::file_signature( $left ) !== self::file_signature( $right ) ) {
				$out[] = hash( 'sha256', $name );
			}
		}
		return $out;
	}

	/** @param array{size:int,sha256:string,mode:int} $file */
	private static function file_signature( array $file ): string {
		return $file['size'] . ':' . $file['sha256'] . ':' . $file['mode'];
	}

	/** @param array{files:array<string,array{bytes:string,size:int,sha256:string,mode:int}>} $manifest */
	private static function manifest_hash( array $manifest ): string {
		$rows = [];
		foreach ( $manifest['files'] as $name => $file ) {
			$rows[] = hash( 'sha256', $name ) . ':' . self::file_signature( $file );
		}
		return hash( 'sha256', implode( "\n", $rows ) );
	}

	/** @param array{files:array<string,array{bytes:string,size:int,sha256:string,mode:int}>,file_count:int,total_bytes:int} $left @param array{files:array<string,array{bytes:string,size:int,sha256:string,mode:int}>,file_count:int,total_bytes:int} $right */
	private static function manifests_equal( array $left, array $right ): bool {
		if ( $left['file_count'] !== $right['file_count'] || $left['total_bytes'] !== $right['total_bytes'] || array_keys( $left['files'] ) !== array_keys( $right['files'] ) ) {
			return false;
		}
		foreach ( $left['files'] as $name => $file ) {
			if ( self::file_signature( $file ) !== self::file_signature( $right['files'][ $name ] ) ) {
				return false;
			}
		}
		return true;
	}

	/** @param array{files:array<string,array{bytes:string,size:int,sha256:string,mode:int}>,file_count:int,total_bytes:int} $before @param array{key:string,scope:string,owner:string,acquired_at:int,expires_at:int,ttl:int} $lease @param array<string,mixed> $extra */
	private static function rollback_error( array $location, int $post_id, array $before, array $metadata_before, string $code, string $message, array &$lease, array $extra = [] ): \WP_Error {
		$rollback = self::restore( $location, $post_id, $before, $metadata_before, $lease );
		$data = array_merge(
			[
				'status'                   => 500,
				'rollback_status'          => $rollback['ok'] ? 'succeeded' : 'failed',
				'manifest_rollback_status' => $rollback['manifest_status'],
				'metadata_rollback_status' => $rollback['metadata_status'],
			],
			$extra
		);
		return self::error( $code, $message, $data );
	}

	/** @param array{files:array<string,array{bytes:string,size:int,sha256:string,mode:int}>,file_count:int,total_bytes:int} $before @param array{exists:bool,value:mixed} $metadata_before @param array{key:string,scope:string,owner:string,acquired_at:int,expires_at:int,ttl:int} $lease @return array{ok:bool,manifest_status:string,metadata_status:string} */
	private static function restore( array $location, int $post_id, array $before, array $metadata_before, array &$lease ): array {
		$reclaimed = CssDirectoryLease::reclaim( $lease, self::LEASE_TTL );
		if ( $reclaimed instanceof \WP_Error ) {
			return [
				'ok'              => false,
				'manifest_status' => 'not_attempted_lock_lost',
				'metadata_status' => 'not_attempted_lock_lost',
			];
		}
		$lease   = $reclaimed;
		$current = self::capture( $location );
		if ( ! is_array( $current ) ) {
			return [
				'ok'              => false,
				'manifest_status' => 'failed',
				'metadata_status' => 'failed',
			];
		}
		$manifest_status = self::restore_manifest( $location, $before, $current ) ? 'succeeded' : 'failed';
		$metadata_status = self::restore_css_metadata( $post_id, $metadata_before ) ? 'succeeded' : 'failed';
		return [
			'ok'              => 'succeeded' === $manifest_status && 'succeeded' === $metadata_status,
			'manifest_status' => $manifest_status,
			'metadata_status' => $metadata_status,
		];
	}

	/** @param array{dir:string,url:string,baseurl:string,canonical_scope:string,base_real:string,dir_real:string} $location @param array{files:array<string,array{bytes:string,size:int,sha256:string,mode:int}>} $before @param array{files:array<string,array{bytes:string,size:int,sha256:string,mode:int}>} $current */
	private static function restore_manifest( array $location, array $before, array $current ): bool {
		$valid = self::revalidate_location( $location );
		if ( $valid instanceof \WP_Error ) {
			return false;
		}
		if ( ! is_dir( $location['dir'] ) && ! wp_mkdir_p( $location['dir'] ) ) {
			return false;
		}
		$valid = self::revalidate_location( $location );
		if ( $valid instanceof \WP_Error ) {
			return false;
		}
		foreach ( $current['files'] as $name => $file ) {
			if ( isset( $before['files'][ $name ] ) ) {
				continue;
			}
			$path = $location['dir'] . '/' . $name;
			if ( is_link( $path ) || ! is_file( $path ) || ! unlink( $path ) ) {
				return false;
			}
		}
		$valid = self::revalidate_location( $location );
		if ( $valid instanceof \WP_Error ) {
			return false;
		}
		foreach ( $before['files'] as $name => $file ) {
			$path = $location['dir'] . '/' . $name;
			if ( is_link( $path ) || is_dir( $path ) || ! self::atomic_write( $path, $file['bytes'], $file['mode'] ) ) {
				return false;
			}
		}
		$valid = self::revalidate_location( $location );
		if ( $valid instanceof \WP_Error ) {
			return false;
		}
		$restored = self::capture( $location );
		return is_array( $restored ) && self::manifests_equal( $before, $restored );
	}

	/** @param array{exists:bool,value:mixed} $expected */
	private static function restore_css_metadata( int $post_id, array $expected ): bool {
		if ( $expected['exists'] ) {
			update_post_meta( $post_id, '_elementor_css', $expected['value'] );
		} elseif ( self::meta_exists( $post_id, '_elementor_css' ) && ! delete_post_meta( $post_id, '_elementor_css' ) ) {
			return false;
		}
		$exists = self::meta_exists( $post_id, '_elementor_css' );
		return $exists === $expected['exists'] && ( ! $exists || self::values_match( get_post_meta( $post_id, '_elementor_css', true ), $expected['value'] ) );
	}

	/** @return array{exists:bool,value:mixed} */
	private static function capture_css_metadata( int $post_id ): array {
		$exists = self::meta_exists( $post_id, '_elementor_css' );
		return [ 'exists' => $exists, 'value' => $exists ? get_post_meta( $post_id, '_elementor_css', true ) : null ];
	}

	private static function meta_exists( int $post_id, string $key ): bool {
		if ( function_exists( 'metadata_exists' ) ) {
			return metadata_exists( 'post', $post_id, $key );
		}
		$all_meta = get_post_meta( $post_id );
		return is_array( $all_meta ) && array_key_exists( $key, $all_meta );
	}

	private static function values_match( mixed $actual, mixed $expected ): bool {
		return $actual === $expected || ( is_string( $actual ) && is_string( $expected ) && wp_unslash( $actual ) === wp_unslash( $expected ) );
	}

	/** @param array<string,mixed> $operation */
	private static function safe_operation_evidence( array $operation ): array {
		$out = [];
		if ( isset( $operation['error_code'] ) && is_scalar( $operation['error_code'] ) ) {
			$out['error_code'] = sanitize_key( (string) $operation['error_code'] );
		}
		if ( isset( $operation['verification'] ) && is_array( $operation['verification'] ) ) {
			$verification = [];
			foreach ( [ 'rendered_bytes', 'render_sha256' ] as $key ) {
				if ( isset( $operation['verification'][ $key ] ) && is_scalar( $operation['verification'][ $key ] ) ) {
					$verification[ $key ] = 'rendered_bytes' === $key ? max( 0, (int) $operation['verification'][ $key ] ) : (string) $operation['verification'][ $key ];
				}
			}
			foreach ( [ 'element_checks', 'content_checks' ] as $key ) {
				if ( ! isset( $operation['verification'][ $key ] ) || ! is_array( $operation['verification'][ $key ] ) ) {
					continue;
				}
				$verification[ $key ] = [];
				foreach ( array_slice( $operation['verification'][ $key ], 0, 50 ) as $check ) {
					if ( ! is_array( $check ) ) {
						continue;
					}
					$row = [ 'present' => (bool) ( $check['present'] ?? false ) ];
					foreach ( [ 'element_id', 'selector', 'sha256' ] as $field ) {
						if ( isset( $check[ $field ] ) && is_scalar( $check[ $field ] ) ) {
							$row[ $field ] = mb_substr( sanitize_text_field( (string) $check[ $field ] ), 0, 255 );
						}
					}
					if ( isset( $check['length'] ) && is_scalar( $check['length'] ) ) {
						$row['length'] = max( 0, (int) $check['length'] );
					}
					$verification[ $key ][] = $row;
				}
			}
			$out['verification'] = $verification;
		}
		return $out;
	}

	/** @param array{key:string,scope:string,owner:string,acquired_at:int,expires_at:int,ttl:int} $lease @return array{key:string,scope:string,owner:string,acquired_at:int,expires_at:int,ttl:int}|\WP_Error */
	private static function renew_lease( array $lease ): array|\WP_Error {
		return CssDirectoryLease::renew( $lease, self::LEASE_TTL );
	}

	private static function safe_filename( string $name ): bool {
		return strlen( $name ) <= 255 && 1 === preg_match( '/\A[A-Za-z0-9][A-Za-z0-9._-]*\.css\z/iD', $name );
	}

	private static function atomic_write( string $path, string $bytes, int $mode ): bool {
		$tmp = tempnam( sys_get_temp_dir(), 'stonewright-css-restore-' );
		if ( false === $tmp ) {
			return false;
		}
		$written = file_put_contents( $tmp, $bytes );
		if ( strlen( $bytes ) !== $written || ! chmod( $tmp, $mode ) ) {
			if ( is_file( $tmp ) ) {
				unlink( $tmp );
			}
			return false;
		}
		if ( @rename( $tmp, $path ) ) {
			return true;
		}
		$copied = @copy( $tmp, $path );
		if ( is_file( $tmp ) ) {
			unlink( $tmp );
		}
		return $copied && chmod( $path, $mode );
	}

	/** @param array<string,mixed> $data */
	private static function error( string $code, string $message, array $data = [] ): \WP_Error {
		return new \WP_Error( $code, $message, array_merge( [ 'status' => 409 ], $data ) );
	}
}
