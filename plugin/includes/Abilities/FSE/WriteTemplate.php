<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\FSE;

/**
 * Ability: stonewright/fse.write_template
 *
 * Inserts or updates a wp_template post. Backups existing before overwrite.
 * Requires confirmation token in production-safe mode.
 *
 * Permission: can_manage_fse() — manage_options + edit_theme_options.
 *
 * @stonewright-status stable
 */
final class WriteTemplate extends AbstractTemplateWriter {

	public function name(): string {
		return 'stonewright/fse-write-template';
	}

	public function label(): string {
		return __( 'Write FSE template', 'stonewright' );
	}

	public function description(): string {
		return __( 'Inserts or updates a Full Site Editing wp_template post. Backup is taken before any overwrite. Requires a confirmation token in production-safe mode.', 'stonewright' );
	}

	protected function post_type(): string {
		return 'wp_template';
	}

	protected function ability_slug(): string {
		return 'stonewright/fse-write-template';
	}

	public function execute( array $args ): array|\WP_Error {
		return $this->audit(
			$args,
			function ( array $args ): array|\WP_Error {
				return $this->write( $args );
			}
		);
	}
}
