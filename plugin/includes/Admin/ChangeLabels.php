<?php
/**
 * The words the Changes page uses for the fields of a ledger row.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Admin;

use Stonewright\WpMcp\Admin\Ui\Badge;
use Stonewright\WpMcp\Admin\Ui\Html;

/**
 * Turns family, status, kind and restorable reason codes, users and resources into text a person reads. Every
 * method returns plain text (the caller escapes it) except the two that return markup built with the Ui helpers.
 */
final class ChangeLabels {

	/** Characters of a resource name shown before it is cut. */
	private const MAX_RESOURCE_CHARS = 80;

	/** @return array<string, string> Family code to its label, in the order of the filter. */
	public static function labels_by_family(): array {
		return [
			'post'             => __( 'Post', 'stonewright' ),
			'elementor'        => __( 'Elementor', 'stonewright' ),
			'gutenberg'        => __( 'Blocks', 'stonewright' ),
			'fse'              => __( 'Site editor', 'stonewright' ),
			'global_styles'    => __( 'Global styles', 'stonewright' ),
			'theme_file'       => __( 'Theme file', 'stonewright' ),
			'custom_code'      => __( 'Custom code', 'stonewright' ),
			'sandbox'          => __( 'Sandbox', 'stonewright' ),
			'option'           => __( 'Option', 'stonewright' ),
			'menu'             => __( 'Menu', 'stonewright' ),
			'widget'           => __( 'Widget', 'stonewright' ),
			'user'             => __( 'User', 'stonewright' ),
			'media'            => __( 'Media', 'stonewright' ),
			'plugin'           => __( 'Plugin', 'stonewright' ),
			'skill'            => __( 'Skill', 'stonewright' ),
			'design_direction' => __( 'Design direction', 'stonewright' ),
			'woocommerce'      => __( 'WooCommerce', 'stonewright' ),
			'comment'          => __( 'Comment', 'stonewright' ),
			'memory'           => __( 'Memory', 'stonewright' ),
			'other'            => __( 'Other', 'stonewright' ),
		];
	}

	public static function label_for_family( string $family ): string {
		return self::labels_by_family()[ $family ] ?? self::humanize( $family );
	}

	public static function kind( string $kind ): string {
		return match ( $kind ) {
			'rollback'      => __( 'Rollback', 'stonewright' ),
			'redo'          => __( 'Redo', 'stonewright' ),
			'restore_point' => __( 'Restore point', 'stonewright' ),
			default         => __( 'Change', 'stonewright' ),
		};
	}

	/** @return array{label: string, variant: string, icon: string} */
	public static function status( string $status ): array {
		return match ( $status ) {
			'verified'                      => [ 'label' => __( 'Verified', 'stonewright' ), 'variant' => 'ok', 'icon' => 'check' ],
			'rolled_back', 'rolled_back_by' => [ 'label' => __( 'Rolled back', 'stonewright' ), 'variant' => 'info', 'icon' => 'refresh' ],
			'incident'                      => [ 'label' => __( 'Incident', 'stonewright' ), 'variant' => 'danger', 'icon' => 'alert' ],
			'rollback_failed'               => [ 'label' => __( 'Rollback failed', 'stonewright' ), 'variant' => 'danger', 'icon' => 'x' ],
			'failed'                        => [ 'label' => __( 'Failed', 'stonewright' ), 'variant' => 'danger', 'icon' => 'x' ],
			'armed', 'probe_unavailable'    => [ 'label' => __( 'Not verified', 'stonewright' ), 'variant' => 'warn', 'icon' => 'clock' ],
			default                         => [ 'label' => self::humanize( $status ), 'variant' => 'neutral', 'icon' => '' ],
		};
	}

	public static function status_badge( string $status ): string {
		$view = self::status( $status );

		return Badge::render( $view['label'], [ 'variant' => $view['variant'], 'icon' => $view['icon'] ] );
	}

	/** Why a change cannot be restored, as the end of "Not restorable: ...". */
	public static function reason( string $code ): string {
		return match ( $code ) {
			'masked_secret'   => __( 'the content held a secret, which was masked before it was stored', 'stonewright' ),
			'secret_file'     => __( 'Stonewright keeps no copy of files that can hold credentials', 'stonewright' ),
			'secret_option'   => __( 'Stonewright keeps no copy of settings that hold credentials', 'stonewright' ),
			'secret_resource' => __( 'Stonewright keeps no copy of credentials', 'stonewright' ),
			'too_large'       => __( 'the content was too large to store', 'stonewright' ),
			'store_full'      => __( 'the content store was full', 'stonewright' ),
			'store_unavailable' => __( 'the content store could not be written', 'stonewright' ),
			'no_before_image' => __( 'there is no copy of the content from before the change', 'stonewright' ),
			'not_restorable'  => __( 'this kind of change cannot be undone', 'stonewright' ),
			'not_encodable'   => __( 'the content could not be stored', 'stonewright' ),
			default           => self::humanize( $code ),
		};
	}

	/** "plugin_deleted" reads as "plugin deleted". */
	public static function humanize( string $code ): string {
		$text = trim( (string) preg_replace( '/[_\-\s]+/', ' ', $code ) );

		return '' === $text ? __( 'unknown', 'stonewright' ) : $text;
	}

	/** The WordPress user of a change, or why there is none. */
	public static function user( int $actor ): string {
		if ( $actor < 1 ) {
			return __( 'No user', 'stonewright' );
		}
		$user = get_user_by( 'id', $actor );
		if ( is_object( $user ) && '' !== (string) ( $user->user_login ?? '' ) ) {
			return (string) $user->user_login;
		}

		/* translators: %d: WordPress user ID of an account that no longer exists */
		return sprintf( __( 'User #%d (deleted)', 'stonewright' ), $actor );
	}

	/** An ability as the operation it names: "stonewright/content-update-page" reads "content update page". */
	public static function ability_words( string $ability ): string {
		return self::humanize( (string) preg_replace( '#^stonewright/#', '', $ability ) );
	}

	/**
	 * The resource of a row: a name to show, whether it is code, an edit link when the resource still has one,
	 * and the full text for a title when the name was cut.
	 *
	 * @param array<string, mixed> $row
	 * @return array{text: string, code: bool, url: string, title: string}
	 */
	public static function resource( array $row ): array {
		$type = (string) ( $row['resource_type'] ?? '' );
		$id   = (string) ( $row['resource_id'] ?? '' );
		if ( 'post' === $type && 1 === preg_match( '/^[1-9]\d{0,18}$/', $id ) ) {
			$post_id = (int) $id;
			if ( null !== get_post( $post_id ) ) {
				$title = trim( html_entity_decode( wp_strip_all_tags( get_the_title( $post_id ) ), ENT_QUOTES ) );
				$link  = get_edit_post_link( $post_id, 'raw' );

				/* translators: %d: post ID */
				return [ 'text' => '' !== $title ? $title : sprintf( __( 'Post #%d', 'stonewright' ), $post_id ), 'code' => false, 'url' => is_string( $link ) ? $link : '', 'title' => '' ];
			}

			/* translators: %d: post ID of a post that no longer exists */
			return [ 'text' => sprintf( __( 'Post #%d', 'stonewright' ), $post_id ), 'code' => false, 'url' => '', 'title' => '' ];
		}

		$text = function_exists( 'mb_strlen' ) && mb_strlen( $id ) > self::MAX_RESOURCE_CHARS ? mb_substr( $id, 0, self::MAX_RESOURCE_CHARS ) . '…' : $id;

		return [ 'text' => $text, 'code' => true, 'url' => '', 'title' => $text !== $id ? $id : '' ];
	}

	/** The resource as markup: its name, linked to the edit screen when there is one. */
	public static function resource_html( array $row ): string {
		$resource = self::resource( $row );
		$name     = $resource['code']
			? Html::element( 'code', [ 'title' => '' !== $resource['title'] ? $resource['title'] : null ], Html::text( $resource['text'] ) )
			: Html::text( $resource['text'] );
		if ( '' !== $resource['url'] ) {
			return Html::element( 'a', [ 'class' => 'sw-ui-link', 'href' => $resource['url'] ], $name );
		}

		return $name;
	}

	/** The first characters of a change id, for a title and a line of meta. */
	public static function short_id( string $id ): string {
		return strlen( $id ) > 15 ? substr( $id, 0, 15 ) . '…' : $id;
	}
}
