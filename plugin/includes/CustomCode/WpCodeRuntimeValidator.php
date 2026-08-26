<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\CustomCode;

/**
 * Candidate plus assembled-runtime preflight for WPCode PHP writes.
 *
 * Returns hashes and counts only. Never executes snippet bodies.
 */
final class WpCodeRuntimeValidator {

	/**
	 * @param callable(string, array<string, mixed>): mixed $api
	 * @return array{runtime_sha256:string,snippet_count:int}|\WP_Error
	 */
	public static function preflight( callable $api, string $target_id, string $candidate_code ) {
		$target_id = sanitize_text_field( $target_id );
		$listed    = $api( 'list_active', [ 'target_id' => $target_id ] );
		if ( $listed instanceof \WP_Error ) {
			return $listed;
		}
		if ( ! is_array( $listed ) || ! isset( $listed['items'] ) || ! is_array( $listed['items'] ) ) {
			return ProviderSupport::wpcode_runtime_preflight_unavailable( $target_id );
		}

		$snippets = [];
		$found    = false;
		foreach ( $listed['items'] as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$id = sanitize_text_field( (string) ( $item['id'] ?? '' ) );
			if ( '' === $id ) {
				continue;
			}
			if ( $id === $target_id ) {
				$item['code'] = $candidate_code;
				$found        = true;
			}
			$snippets[] = $item;
		}
		if ( ! $found ) {
			$snippets[] = [
				'id'       => $target_id,
				'code'     => $candidate_code,
				'language' => 'php',
				'active'   => true,
			];
		}

		$assembled = $api(
			'assemble_runtime',
			[
				'snippets'  => $snippets,
				'target_id' => $target_id,
			]
		);
		if ( $assembled instanceof \WP_Error ) {
			return $assembled;
		}
		if ( ! is_array( $assembled ) || ! is_string( $assembled['payload'] ?? null ) ) {
			return ProviderSupport::wpcode_runtime_preflight_unavailable( $target_id );
		}

		$payload = $assembled['payload'];
		$sha     = isset( $assembled['sha256'] ) && is_string( $assembled['sha256'] ) && preg_match( '/^[a-f0-9]{64}$/', $assembled['sha256'] )
			? strtolower( $assembled['sha256'] )
			: ProviderSupport::content_hash( $payload );
		$count   = (int) ( $assembled['count'] ?? count( $snippets ) );

		$lint = $api(
			'lint_runtime',
			[
				'payload'        => $payload,
				'payload_sha256' => $sha,
				'count'          => $count,
			]
		);
		if ( $lint instanceof \WP_Error ) {
			return $lint;
		}

		return [
			'runtime_sha256' => $sha,
			'snippet_count'  => $count,
		];
	}
}
