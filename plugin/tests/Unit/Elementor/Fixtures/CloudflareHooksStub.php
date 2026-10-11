<?php
declare( strict_types=1 );

namespace Cloudflare\APO\WordPress;

/**
 * Stand-in for the hooks object of the Cloudflare plugin, with the one public method the purger calls.
 */
final class Hooks {
	/** @var array<int,mixed> */
	public array $purged = [];

	public bool $throw = false;

	public function purgeCacheByRelevantURLs( $post_ids ): void {
		if ( $this->throw ) {
			throw new \RuntimeException( 'Cloudflare API unreachable.' );
		}
		$this->purged[] = $post_ids;
	}
}
