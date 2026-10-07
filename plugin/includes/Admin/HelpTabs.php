<?php
/**
 * Contextual help for the Stonewright admin pages, in the Help tab WordPress already provides.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Admin;

/**
 * Every Stonewright page gets two native help tabs: "What is this page?" (the same sentence the page header
 * shows, where the page sits and the pages next to it) and "Glossary" (the words the product uses). The Help
 * button is keyboard accessible and needs no script of ours.
 */
final class HelpTabs {

	public static function register(): void {
		add_action( 'current_screen', [ self::class, 'add' ] );
	}

	/**
	 * @param object|null $screen The current screen (a WP_Screen). Anything without the two help methods is ignored.
	 */
	public static function add( ?object $screen ): void {
		if ( null === $screen || ! method_exists( $screen, 'add_help_tab' ) || ! method_exists( $screen, 'set_help_sidebar' ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only page routing.
		$page  = isset( $_GET['page'] ) && is_string( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		$entry = '' === $page ? null : MenuRegistry::entry( $page, MenuRegistry::requested_tab() );
		if ( null === $entry || ! current_user_can( $entry['capability'] ) ) {
			return;
		}

		$screen->add_help_tab(
			[
				'id'      => 'stonewright-help-page',
				'title'   => __( 'What is this page?', 'stonewright' ),
				'content' => self::page_help( $entry ),
			]
		);
		$screen->add_help_tab(
			[
				'id'      => 'stonewright-help-glossary',
				'title'   => __( 'Glossary', 'stonewright' ),
				'content' => self::glossary(),
			]
		);
		$screen->set_help_sidebar(
			'<p><strong>' . esc_html__( 'Stonewright', 'stonewright' ) . '</strong></p>'
			. '<p><a href="' . esc_url( admin_url( 'admin.php?page=stonewright-status' ) ) . '">' . esc_html__( 'Overview', 'stonewright' ) . '</a></p>'
			. '<p><a href="' . esc_url( admin_url( 'admin.php?page=stonewright' ) ) . '">' . esc_html__( 'Setup', 'stonewright' ) . '</a></p>'
		);
	}

	/**
	 * @param array<string, mixed> $entry A registry entry.
	 */
	private static function page_help( array $entry ): string {
		$html = '<p><strong>' . esc_html( (string) $entry['title'] ) . '</strong></p>';
		if ( '' !== (string) $entry['lede'] ) {
			$html .= '<p>' . esc_html( (string) $entry['lede'] ) . '</p>';
		}

		$hub     = (string) $entry['hub'];
		$related = '';
		foreach ( MenuRegistry::hub_entries( $hub ) as $sibling ) {
			if ( $sibling['slug'] === $entry['slug'] || ! current_user_can( $sibling['capability'] ) || str_contains( $related, 'page=' . $sibling['slug'] . '"' ) ) {
				continue;
			}
			$related .= '<li><a href="' . esc_url( add_query_arg( [ 'page' => $sibling['slug'] ], admin_url( 'admin.php' ) ) ) . '">' . esc_html( $sibling['label'] ) . '</a></li>';
		}

		$html .= '<p>' . esc_html(
			sprintf(
				/* translators: %s: name of a group of pages, for example Knowledge */
				__( 'This page is part of %s.', 'stonewright' ),
				MenuRegistry::hub_label( $hub )
			)
		) . '</p>';
		if ( '' !== $related ) {
			$html .= '<p>' . esc_html__( 'Related pages', 'stonewright' ) . '</p><ul>' . $related . '</ul>';
		}

		return $html;
	}

	private static function glossary(): string {
		$terms = [
			__( 'Abilities', 'stonewright' )        => __( 'The tools an AI client can call on this site. Each one can be turned on or off on the AI Abilities page.', 'stonewright' ),
			__( 'Skills', 'stonewright' )           => __( 'Markdown playbooks for repeatable work. Agents read the short description first and load the full text only when a task matches.', 'stonewright' ),
			__( 'Memory', 'stonewright' )           => __( 'What agents may remember about this site. It stays empty until you or an agent saves an entry.', 'stonewright' ),
			__( 'Context', 'stonewright' )          => __( 'Site-specific instructions that are added to what an agent is told when a task starts.', 'stonewright' ),
			__( 'Design direction', 'stonewright' ) => __( 'Your colours, type and spacing, imported from a DESIGN.md file, so generated pages follow them.', 'stonewright' ),
			__( 'Sandbox', 'stonewright' )          => __( 'PHP drafts that agents write and you review. Nothing runs until you activate a file.', 'stonewright' ),
		];

		$html = '<dl>';
		foreach ( $terms as $term => $definition ) {
			$html .= '<dt>' . esc_html( $term ) . '</dt><dd>' . esc_html( $definition ) . '</dd>';
		}

		return $html . '</dl>';
	}
}
