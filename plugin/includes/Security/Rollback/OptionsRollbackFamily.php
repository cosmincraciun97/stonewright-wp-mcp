<?php
/**
 * Rollback handler for options, entries of shared options and theme mods, and for theme switches.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Security\Rollback;

use Stonewright\WpMcp\Security\Adapters\OptionsAdapter;

/**
 * The live image is read over the scope the stored image describes. A restore goes through OptionsAdapter::restore()
 * with the ability that made the original change, so the allowlist of that ability still decides what may be written,
 * for a rollback row as much as for the change.
 *
 * The active theme is recorded in the same ledger family (resource type theme_switch). Its rows go to the handler that
 * the constructor takes, because their image is a stylesheet and a template, not a list of options.
 */
final class OptionsRollbackFamily implements RollbackFamilyHandler {

	/** Resource type of a theme switch. */
	private const THEME_SWITCH = 'theme_switch';

	public function __construct( private ?RollbackFamilyHandler $theme = null ) {}

	public function families(): array {
		return [ 'option' ];
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private function theme_for( array $row ): ?RollbackFamilyHandler {
		return null !== $this->theme && self::THEME_SWITCH === (string) ( $row['resource_type'] ?? '' ) ? $this->theme : null;
	}

	public function live_image( array $row ): string|array|\WP_Error|null {
		$theme = $this->theme_for( $row );
		if ( null !== $theme ) {
			return $theme->live_image( $row );
		}
		$stored = FamilySupport::scope_image( $row );
		return null === $stored ? FamilySupport::unreadable() : OptionsAdapter::image( OptionsAdapter::scope_of( $stored ) );
	}

	public function restore( array $row, string|array|null $image, array $options ): array {
		$theme = $this->theme_for( $row );
		if ( null !== $theme ) {
			return $theme->restore( $row, $image, $options );
		}
		if ( ! is_array( $image ) ) {
			return [ 'status' => 'failed', 'detail' => 'image_invalid' ];
		}
		return FamilySupport::answer( OptionsAdapter::restore( $image, (string) RollbackFamilies::root_of( $row )['ability'] ) );
	}

	public function describe( array $row, string|array|null $image ): string {
		$theme = $this->theme_for( $row );
		if ( null !== $theme ) {
			return $theme->describe( $row, $image );
		}
		return __( 'Writes the settings back as they were. A setting that did not exist before is removed.', 'stonewright' );
	}

	public function records_own_row( array $row ): bool {
		$theme = $this->theme_for( $row );
		return null !== $theme && $theme->records_own_row( $row );
	}

	public function requires_human( array $row ): bool {
		$theme = $this->theme_for( $row );
		return null !== $theme && $theme->requires_human( $row );
	}
}
