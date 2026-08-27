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

// AccessPolicy holds the three gate option names and the role slug; StoreDefaults
// holds the name of the option every other setting lives in. Deactivator reads
// both. None of the three touches FluentCart at include time, and none of them
// pulls in the Wholesale classes — the two application meta keys are named as
// literals in Deactivator for exactly that reason.
//
// StoreDefaults FIRST: AccessPolicy's Gate 1 reads it, so it must be defined
// before that file is parsed.
require_once __DIR__ . '/includes/StoreDefaults.php';
require_once __DIR__ . '/includes/AccessPolicy.php';
require_once __DIR__ . '/includes/Deactivator.php';

\FluentCartBulkOrder\Deactivator::uninstall();
