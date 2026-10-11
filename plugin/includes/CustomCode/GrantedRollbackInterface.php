<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\CustomCode;

/**
 * A provider whose rollback rewrites code, so the pipeline asks for the human grant that an apply needs.
 *
 * A rollback through the custom-code-provider ability puts a kept snapshot back into the snippet. That is a
 * write of code, so it needs the same single-use grant as an apply, bound to the code it restores. The
 * pipeline asks the provider what the restore would be, stages it for approval or checks the grant, and only
 * then calls rollback(). A provider's own restore after a failed apply calls rollback() directly: the grant of
 * that apply was already spent.
 */
interface GrantedRollbackInterface {

	/**
	 * The restore a rollback of these arguments would make, without making it.
	 *
	 * @param array<string, mixed> $args The rollback arguments (snapshot_id and optional target_id).
	 * @return array{path: string, language: string, before: string, after: string}|\WP_Error The snippet path, its language, the code it holds now and the code the snapshot would put back.
	 */
	public function rollback_candidate( array $args );
}
