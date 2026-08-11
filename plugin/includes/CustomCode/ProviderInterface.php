<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\CustomCode;

/**
 * Provider-neutral custom-code contract.
 *
 * Pipeline: discover → list → read → dry-run → approval stop → apply → verify → rollback.
 * Write paths never auto-open approval, mint grants, or apply without an explicit
 * human-issued single-use custom_code_grant bound to the candidate hash.
 */
interface ProviderInterface {

	/** Stable provider id, e.g. `wpcode`, `code-snippets`, `customizer-css`, `theme-file`. */
	public function id(): string;

	public function label(): string;

	/**
	 * Detect plugin presence and supported version.
	 *
	 * @return array{
	 *   id:string,
	 *   label:string,
	 *   available:bool,
	 *   active:bool,
	 *   version:string,
	 *   supported:bool,
	 *   plugin_file:string,
	 *   capabilities:list<string>,
	 *   notes:string
	 * }
	 */
	public function discover(): array;

	/**
	 * @param array<string, mixed> $args
	 * @return array<string, mixed>|\WP_Error
	 */
	public function list( array $args = [] );

	/**
	 * @return array<string, mixed>|\WP_Error
	 */
	public function read( string $target_id );

	/**
	 * Dry-run a candidate update. Must stop before opening approval or obtaining a grant.
	 *
	 * @param array<string, mixed> $args
	 * @return array<string, mixed>|\WP_Error
	 */
	public function dry_run( array $args );

	/**
	 * Apply a grant-bound candidate. Requires custom_code_grant.
	 *
	 * @param array<string, mixed> $args
	 * @return array<string, mixed>|\WP_Error
	 */
	public function apply( array $args );

	/**
	 * Verify the live record against expected hashes.
	 *
	 * @param array<string, mixed> $args
	 * @return array<string, mixed>|\WP_Error
	 */
	public function verify( array $args );

	/**
	 * Roll back to a previously snapshotted provider record when possible.
	 *
	 * @param array<string, mixed> $args
	 * @return array<string, mixed>|\WP_Error
	 */
	public function rollback( array $args );
}
