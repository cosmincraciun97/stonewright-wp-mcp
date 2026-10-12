<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Elementor;

use Stonewright\WpMcp\Elementor\Schema\CssValueGuard;
use Stonewright\WpMcp\Elementor\Write\PostWriteLock;
use Stonewright\WpMcp\Support\ElementorData;

/**
 * Brings the generated CSS of one post in line with an Elementor document that a restore put back.
 *
 * A restore writes the document meta directly, so Elementor never runs its own save and keeps serving the stylesheet it
 * generated for the state that was replaced. This regenerates that one post through the same guarded path as
 * stonewright/elementor-css-regenerate: the CSS value check, the target resolver, the post write lease and
 * CssAssetTransaction (directory lease, manifest of the other files, readback). It takes no snapshot and writes no
 * audit or history row: the restore that calls it has already saved the state it replaced, and the regeneration writes
 * only the generated files and the CSS metadata of this post, which the transaction puts back when it fails.
 *
 * It never undoes the data restore. A regeneration that does not complete is returned as `not_regenerated`, with the
 * reason and the next step, for the caller to report.
 */
final class RestoredCss {

	public const NEXT_STEP = 'stonewright/elementor-css-regenerate';

	private const META_DATA     = '_elementor_data';
	private const META_SETTINGS = '_elementor_page_settings';

	/** Seconds the regeneration holds the post write lease. */
	private const LEASE_TTL = 120;

	/** @var array<int,array<string,mixed>> The result of the last regeneration of each post in this request. */
	private static array $results = [];

	/** Forgets every result. For tests. */
	public static function reset(): void {
		self::$results = [];
	}

	/** Forgets the result of the previous restore of one post. A restore calls it before it writes. */
	public static function forget( int $post_id ): void {
		unset( self::$results[ $post_id ] );
	}

	/**
	 * The result of the last regeneration of this post in this request, or null when the restore that ran last did not
	 * regenerate it (the document did not change, or the post is not an Elementor document).
	 *
	 * @return array<string,mixed>|null
	 */
	public static function last( int $post_id ): ?array {
		return self::$results[ $post_id ] ?? null;
	}

	/**
	 * The regenerations of this request that did not complete, for a caller that restored more than one post.
	 *
	 * @return list<array<string,mixed>>
	 */
	public static function unfinished(): array {
		return array_values( array_filter( self::$results, static fn ( array $css ): bool => 'not_regenerated' === ( $css['status'] ?? '' ) ) );
	}

	/**
	 * What a restore is about to replace, as far as the CSS of the post depends on it. Taken before the restore writes.
	 *
	 * @return array{data:string,settings:string}
	 */
	public static function state( int $post_id ): array {
		return [
			'data'     => self::fingerprint( $post_id, self::META_DATA ),
			'settings' => self::fingerprint( $post_id, self::META_SETTINGS ),
		];
	}

	/**
	 * Regenerates the CSS of the post when the restore changed what the CSS is built from, and returns what happened.
	 * Call it after the restored document has been read back and found right.
	 *
	 * @param array{data:string,settings:string} $before   From state(), taken before the restore wrote.
	 * @param CssTargetResolver|null            $resolver  For tests.
	 * @return array<string,mixed> `status` is regenerated, not_regenerated or not_needed. Only the first two are kept for last().
	 */
	public static function refresh( int $post_id, array $before, ?CssTargetResolver $resolver = null ): array {
		$after = self::state( $post_id );
		if ( ( $before['data'] ?? '' ) === $after['data'] && ( $before['settings'] ?? '' ) === $after['settings'] ) {
			return [ 'status' => 'not_needed', 'post_id' => $post_id, 'reason' => 'document_unchanged' ];
		}
		if ( 'absent' === ( $before['data'] ?? '' ) && 'absent' === $after['data'] && 'absent' === ( $before['settings'] ?? '' ) && 'absent' === $after['settings'] ) {
			return [ 'status' => 'not_needed', 'post_id' => $post_id, 'reason' => 'not_an_elementor_document' ];
		}

		try {
			$result = self::regenerate( $post_id, $resolver ?? new CssTargetResolver() );
		} catch ( \Throwable $error ) {
			$result = self::failed( $post_id, 'regeneration_failed', [ 'error_class' => preg_replace( '/[^A-Za-z0-9_\\\\]/', '', get_class( $error ) ) ] );
		}
		self::$results[ $post_id ] = $result;
		return $result;
	}

	/**
	 * The sentence that tells a person the data was restored and the CSS was not regenerated, with the next step. Empty
	 * when the CSS was regenerated or nothing needed it.
	 *
	 * @param array<string,mixed>|null $css A result of refresh() or last().
	 */
	public static function warning( ?array $css ): string {
		if ( null === $css || 'not_regenerated' !== ( $css['status'] ?? '' ) ) {
			return '';
		}
		return sprintf(
			/* translators: 1: post id, 2: ability name */
			__( 'The content was restored, but the generated CSS of post %1$d was not regenerated, so the page may still load the stylesheet of the state that was replaced. Call %2$s for this post, then check the page in a logged-out browser.', 'stonewright' ),
			(int) ( $css['post_id'] ?? 0 ),
			self::NEXT_STEP
		);
	}

	/** @return array<string,mixed> */
	private static function regenerate( int $post_id, CssTargetResolver $resolver ): array {
		// Elementor prints stored colour, typography and unit values into the stylesheet unescaped.
		$unsafe = CssValueGuard::unsafe_values_in_tree( ElementorData::read( $post_id ) );
		$page   = get_post_meta( $post_id, self::META_SETTINGS, true );
		if ( is_array( $page ) ) {
			$unsafe = array_merge( $unsafe, CssValueGuard::unsafe_values_in_settings( $page, 'page_settings' ) );
		}
		if ( [] !== $unsafe ) {
			return self::failed( $post_id, 'unsafe_value', [ 'count' => count( $unsafe ) ] );
		}

		$target = $resolver->resolve( $post_id );
		if ( $target instanceof \WP_Error ) {
			return 'stonewright_elementor_css_runtime_unavailable' === $target->get_error_code()
				? self::failed( $post_id, 'elementor_unavailable' )
				: self::failed( $post_id, 'target_unresolved', [ 'error_code' => sanitize_key( (string) $target->get_error_code() ) ] );
		}

		$owner = 'css-restore-' . substr( hash( 'sha256', wp_generate_uuid4() . '|' . $post_id ), 0, 24 );
		$lease = PostWriteLock::acquire( $post_id, $owner, self::LEASE_TTL );
		if ( $lease instanceof \WP_Error ) {
			return self::failed( $post_id, 'post_busy' );
		}

		try {
			$transaction = CssAssetTransaction::run(
				$target,
				static fn (): array => CssRegenerator::regenerate( $target )
			);
		} finally {
			PostWriteLock::release( $post_id, $owner );
		}
		if ( $transaction instanceof \WP_Error ) {
			$data = is_array( $transaction->get_error_data() ) ? $transaction->get_error_data() : [];
			return self::failed(
				$post_id,
				'regeneration_failed',
				[
					'error_code'      => sanitize_key( (string) $transaction->get_error_code() ),
					'rollback_status' => sanitize_key( (string) ( $data['rollback_status'] ?? '' ) ),
				]
			);
		}

		$operation  = is_array( $transaction['operation_result'] ?? null ) ? $transaction['operation_result'] : [];
		$evidence   = is_array( $transaction['css_evidence'] ?? null ) ? $transaction['css_evidence'] : [];
		$generation = sanitize_key( (string) ( $evidence['generation_status'] ?? '' ) );
		if ( '' === $generation || 'not_checked' === $generation ) {
			$generation = (bool) ( $operation['ok'] ?? false ) ? 'verified' : 'failed';
		}
		if ( 'verified' !== $generation ) {
			return self::failed( $post_id, 'regeneration_unverified' );
		}

		$result = [
			'status'          => 'regenerated',
			'post_id'         => $post_id,
			'asset_kind'      => $target->kind(),
			'filename'        => $target->filename(),
			'delivery_status' => sanitize_key( (string) ( $evidence['delivery_status'] ?? 'not_checked' ) ),
		];
		if ( isset( $operation['css_version'] ) && is_numeric( $operation['css_version'] ) ) {
			$result['css_version']         = max( 0, (int) $operation['css_version'] );
			$result['css_version_changed'] = (bool) ( $operation['css_version_changed'] ?? false );
		}
		// A page cache that stored the page keeps the old stylesheet link until it is purged; only this post, as the ability does.
		if ( true === ( $operation['css_version_changed'] ?? null ) && 'not_needed' === (string) ( $evidence['rollback_status'] ?? 'not_needed' ) ) {
			$purge                = PageCachePurger::purge_quietly( $post_id );
			$result['cache_purge'] = [ 'ran' => array_values( array_map( 'strval', (array) ( $purge['ran'] ?? [] ) ) ) ];
		}
		return $result;
	}

	/**
	 * @param array<string,mixed> $extra
	 * @return array<string,mixed>
	 */
	private static function failed( int $post_id, string $reason, array $extra = [] ): array {
		return array_merge(
			[
				'status'    => 'not_regenerated',
				'post_id'   => $post_id,
				'reason'    => $reason,
				'next_step' => self::NEXT_STEP,
			],
			array_filter( $extra, static fn ( $value ): bool => '' !== $value && null !== $value )
		);
	}

	/** A comparable value of one meta key: `absent` when the post has none. */
	private static function fingerprint( int $post_id, string $key ): string {
		if ( function_exists( 'metadata_exists' ) ? ! metadata_exists( 'post', $post_id, $key ) : ! array_key_exists( $key, (array) get_post_meta( $post_id ) ) ) {
			return 'absent';
		}
		return hash( 'sha256', serialize( get_post_meta( $post_id, $key, true ) ) );
	}
}
