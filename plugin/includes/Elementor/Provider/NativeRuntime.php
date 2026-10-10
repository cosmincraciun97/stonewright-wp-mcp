<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Elementor\Provider;

/**
 * The WordPress and Elementor runtime a native write runs against.
 *
 * Every method that reads state is independent of the native ability's own output, so the
 * closure compares what the ability reported with what is actually stored.
 */
interface NativeRuntime {

	/** Runs one registered ability in-process; its own permission check applies. */
	public function execute( string $ability, array $input ): mixed;

	/** The post status of a document, or an empty string when the post does not exist. */
	public function post_status( int $post_id ): string;

	/** The current user's pending autosave tree of a document, or null when there is none. */
	public function autosave_tree( int $post_id ): ?array;

	/** Puts an autosave back as it was; a null tree removes the autosave. */
	public function restore_autosave( int $post_id, ?array $tree ): bool;

	public function active_kit_id(): int;

	/**
	 * Every stored default style by HTML tag.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function default_styles( int $kit_id ): array|\WP_Error;

	/**
	 * Puts the default styles back as captured: changed tags rewritten, new tags removed.
	 *
	 * @param array<string, mixed> $before
	 */
	public function restore_default_styles( int $kit_id, array $before ): bool;

	/** @return list<string> Atomic element and widget types registered on this site. */
	public function atomic_types(): array;

	/** Post-scoped CSS regeneration through the `stonewright/elementor-css-regenerate` ability. */
	public function regenerate_css( int $post_id ): array|\WP_Error;
}
