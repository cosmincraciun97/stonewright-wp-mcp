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
 * The same goes for the list of custom widgets the widget builder registered with Elementor
 * (the stonewright_registered_widgets option): it says which generated widget files get loaded,
 * so it is dropped on every uninstall, and a reinstall registers a widget again before it loads.
 * The scheduled events and the runtime state (locks, back-offs, cached verdicts, the remembered
 * state of the rescue helper, Elementor editor baselines) go on every uninstall too: nothing
 * reads them once the plugin is gone, and the plugin rebuilds them when it is installed again.
 *
 * To remove everything the plugin owns, define STONEWRIGHT_REMOVE_ALL_DATA as true (for
 * example in wp-config.php) before deleting the plugin. Stonewright\WpMcp\Core\Uninstaller
 * then drops every plugin table and deletes every plugin option (the OAuth signing and
 * encryption keys included), plugin transient and scheduled event, and the change journal
 * files, change history blobs and copies of executed code in uploads/stonewright-state/, on every site of a multisite network. The list of what
 * goes is kept in that class.
 *
 * @package Stonewright\WpMcp
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// A deleted plugin is inactive, so a loaded Stonewright is another copy that runs: it uses the same rescue helper,
// widget list and data, so nothing goes. Its classes are loaded already; loading this copy's files would be fatal.
if ( defined( 'STONEWRIGHT_FILE' ) ) {
	return;
}

require_once __DIR__ . '/includes/Core/RescueInstaller.php';
require_once __DIR__ . '/includes/Core/RuntimeState.php';
require_once __DIR__ . '/includes/Core/Uninstaller.php';

// Another, inactive copy of Stonewright stays installed: it uses the same rescue helper, widget list and data.
if ( \Stonewright\WpMcp\Core\Uninstaller::other_copy_remains( (string) WP_UNINSTALL_PLUGIN ) ) {
	return;
}

// Code and rebuildable state, not data: the rescue helper, the list of registered custom widgets, the scheduled events and
// the runtime state go before the data setting is looked at.
\Stonewright\WpMcp\Core\Uninstaller::remove_code();
\Stonewright\WpMcp\Core\Uninstaller::forget_registered_widgets();
\Stonewright\WpMcp\Core\Uninstaller::unschedule_events();
\Stonewright\WpMcp\Core\Uninstaller::remove_runtime_state();

// Checked here as well, so the default path runs no data removal code.
if ( ! defined( 'STONEWRIGHT_REMOVE_ALL_DATA' ) || true !== STONEWRIGHT_REMOVE_ALL_DATA ) {
	return;
}

// The change journal files and the change history blobs live outside the database, so the data removal
// needs the journal classes and the blob store.
require_once __DIR__ . '/includes/Security/ChangeJournalFile.php';
require_once __DIR__ . '/includes/Security/ChangeJournal.php';
require_once __DIR__ . '/includes/Security/BlobStore.php';

\Stonewright\WpMcp\Core\Uninstaller::run();
