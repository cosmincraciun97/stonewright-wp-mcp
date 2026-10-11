<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\CustomCode;

/**
 * A provider that delegates its writes to a typed ability which also checks the production-safe
 * confirmation token.
 *
 * The provider pipeline (the custom-code-provider ability) verifies the token once for an apply, for itself
 * and the exact request, then calls apply_verified() so the delegated ability does not read the same
 * single-use token again. A dry run writes nothing and the pipeline asks for no token, so it calls
 * dry_run_verified(). The human grant and every other gate of the delegated ability still run.
 * apply() and dry_run() keep the delegated ability's own token check.
 */
interface VerifiedApplyInterface {

	/**
	 * @param array<string, mixed> $args
	 * @return array<string, mixed>|\WP_Error
	 */
	public function apply_verified( array $args );

	/**
	 * @param array<string, mixed> $args
	 * @return array<string, mixed>|\WP_Error
	 */
	public function dry_run_verified( array $args );
}
