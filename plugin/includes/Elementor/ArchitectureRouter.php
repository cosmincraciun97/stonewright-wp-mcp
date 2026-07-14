<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Elementor;

use Stonewright\WpMcp\Elementor\V4\AtomicTreeInspector;
use Stonewright\WpMcp\Support\ElementorData;

/** Selects an Elementor write pipeline without implicit V3/V4 conversion. */
final class ArchitectureRouter {

	/** @return array<string, mixed> */
	public static function describe( int $post_id = 0, string $requested = 'auto' ): array {
		$runtime_version = defined( 'ELEMENTOR_VERSION' ) ? (string) ELEMENTOR_VERSION : '';
		$version         = (string) apply_filters( 'stonewright_elementor_version', $runtime_version );
		$site_v4         = '' !== $version && version_compare( $version, '4.0.0', '>=' );
		$document        = 'unknown';
		if ( $post_id > 0 && get_post( $post_id ) ) {
			$document = (string) ( AtomicTreeInspector::inspect( ElementorData::read( $post_id ) )['architecture'] ?? 'unknown' );
		}

		$requested = in_array( $requested, [ 'auto', 'v3', 'v4' ], true ) ? $requested : 'auto';
		$target    = 'v3';
		$blocked   = false;
		$reason    = 'Elementor V3 runtime or legacy V3 document detected.';

		if ( 'mixed' === $document ) {
			$target  = 'none';
			$blocked = true;
			$reason  = 'Document already mixes V3 and V4 nodes; repair or restore it before any write.';
		} elseif ( 'v4' === $document ) {
			$target  = 'v4';
			$blocked = true;
			$reason  = 'Atomic document detected; the current high-level V4 renderer remains experimental, so automatic page writes are blocked.';
		} elseif ( 'v3' === $document ) {
			$target = 'v3';
			if ( 'v4' === $requested ) {
				$blocked = true;
				$reason  = 'Explicit V4 target conflicts with an existing V3 document; use reviewed migration first.';
			}
		} elseif ( 'v3' === $requested ) {
			$target = 'v3';
			$reason = 'Caller explicitly selected a legacy V3 document for an empty or unknown target.';
		} elseif ( 'v4' === $requested ) {
			$target  = 'v4';
			$blocked = true;
			$reason  = 'V4 was selected, but the high-level V4 renderer is not production-ready.';
		} elseif ( $site_v4 ) {
			$target  = 'none';
			$blocked = true;
			$reason  = 'Elementor 4 runtime with an empty or unspecified document is architecture-ambiguous. Select target_architecture=v3 explicitly or use a production-ready V4 editor adapter.';
		}

		return [
			'elementor_version'     => $version,
			'site_v4'               => $site_v4,
			'post_id'               => $post_id,
			'document_architecture' => $document,
			'requested_architecture' => $requested,
			'write_target'          => $target,
			'write_blocked'         => $blocked,
			'reason'                => $reason,
			'implicit_conversion'   => false,
		];
	}
}
