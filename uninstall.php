<?php
/**
 * Runs when the user deletes this plugin from the Plugins screen.
 *
 * WordPress loads this file directly and does not load the main plugin file, so
 * nothing here may assume FCBO_DIR, FluentCart, or any of the plugin's
 * `fcbo_*` functions exist. Pull in only what the cleanup itself needs.
 *
 * A file, rather than register_uninstall_hook(): that function serializes the
 * callback into the `uninstall_plugins` option, which cannot hold a closure and
 * breaks quietly if the named class is not loadable at delete time. uninstall.php
 * has no such failure mode and is what WordPress recommends.
 *
 * The actual policy — what gets removed and what is kept — is documented in
 * \FluentCartBulkOrder\Deactivator::uninstall(). Keep it there, not here.
 */

// WP_UNINSTALL_PLUGIN is defined only by WordPress' own uninstall path. Without
// it, this file was reached some other way and must not delete anything.
defined('WP_UNINSTALL_PLUGIN') || exit;

// AccessPolicy holds the option names and the role slug; Deactivator reads them.
// Neither file touches FluentCart at include time.
require_once __DIR__ . '/includes/AccessPolicy.php';
require_once __DIR__ . '/includes/Deactivator.php';

\FluentCartBulkOrder\Deactivator::uninstall();
