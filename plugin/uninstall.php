<?php
/**
 * Stonewright uninstall handler.
 *
 * Deleting the plugin keeps all plugin data by default: OAuth clients, grants and keys,
 * memory, skills, audit history and settings stay in the database, so a reinstall or a
 * rollback finds everything as it was.
 *
 * The rescue helper is not data. It is a must-use plugin file in wp-content/mu-plugins that the
 * plugin installed, and deleting the plugin always removes it, with or without the setting below.
 *
 * To remove everything the plugin owns, define STONEWRIGHT_REMOVE_ALL_DATA as true (for
 * example in wp-config.php) before deleting the plugin. Stonewright\WpMcp\Core\Uninstaller
 * then drops every plugin table and deletes every plugin option (the OAuth signing and
 * encryption keys included), plugin transient and scheduled event, and the change journal
 * files in uploads/stonewright-state/, on every site of a multisite network. The list of what
 * goes is kept in that class.
 *
 * @package Stonewright\WpMcp
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/includes/Core/RescueInstaller.php';
require_once __DIR__ . '/includes/Core/Uninstaller.php';

// Code, not data: removed before the data setting is looked at.
\Stonewright\WpMcp\Core\Uninstaller::remove_code();

// Checked here as well, so the default path runs no data removal code.
if ( ! defined( 'STONEWRIGHT_REMOVE_ALL_DATA' ) || true !== STONEWRIGHT_REMOVE_ALL_DATA ) {
	return;
}

// The change journal files live outside the database, so the data removal needs the journal classes.
require_once __DIR__ . '/includes/Security/ChangeJournalFile.php';
require_once __DIR__ . '/includes/Security/ChangeJournal.php';

\Stonewright\WpMcp\Core\Uninstaller::run();
