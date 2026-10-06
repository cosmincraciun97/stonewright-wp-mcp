<?php
/**
 * Stonewright uninstall handler.
 *
 * Deleting the plugin keeps all plugin data by default: OAuth clients, grants and keys,
 * memory, skills, audit history and settings stay in the database, so a reinstall or a
 * rollback finds everything as it was.
 *
 * To remove everything the plugin owns, define STONEWRIGHT_REMOVE_ALL_DATA as true (for
 * example in wp-config.php) before deleting the plugin. Stonewright\WpMcp\Core\Uninstaller
 * then drops every plugin table and deletes every plugin option (the OAuth signing and
 * encryption keys included), plugin transient and scheduled event, on every site of a
 * multisite network. The list of what goes is kept in that class.
 *
 * @package Stonewright\WpMcp
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// Checked here as well, so the default path loads no plugin code.
if ( ! defined( 'STONEWRIGHT_REMOVE_ALL_DATA' ) || true !== STONEWRIGHT_REMOVE_ALL_DATA ) {
	return;
}

require_once __DIR__ . '/includes/Core/Uninstaller.php';

\Stonewright\WpMcp\Core\Uninstaller::run();
