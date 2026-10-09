<?php
/**
 * One numbered step of Get started.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Admin\Setup;

use Stonewright\WpMcp\Admin\Ui\Badge;
use Stonewright\WpMcp\Admin\Ui\Card;

/**
 * A card whose title carries the step number and whose header says where the step stands: done, next or still to
 * do. The state is a word with an icon or a dot, never colour alone.
 */
final class Step {

	/**
	 * @param string $state done, current or todo; empty for a step that has no state of its own.
	 */
	public static function html( int $number, string $title, string $state, string $body_html, string $id = '', string $desc = '' ): string {
		return Card::render(
			sprintf(
				/* translators: 1: step number, 2: step title. */
				__( '%1$d. %2$s', 'stonewright' ),
				$number,
				$title
			),
			$body_html,
			[
				'id'           => '' !== $id ? $id : null,
				'desc'         => $desc,
				'actions_html' => '' !== $state ? self::badge( $state ) : '',
				'class'        => 'sw-setup__step',
			]
		);
	}

	public static function badge( string $state ): string {
		return match ( $state ) {
			'done'    => Badge::render( __( 'Done', 'stonewright' ), [ 'variant' => 'ok', 'icon' => 'check' ] ),
			'current' => Badge::render( __( 'Next', 'stonewright' ), [ 'variant' => 'accent', 'dot' => true ] ),
			default   => Badge::render( __( 'To do', 'stonewright' ), [ 'dot' => true ] ),
		};
	}
}
