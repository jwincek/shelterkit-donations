<?php
/**
 * Uninstall handler for ShelterKit Donations.
 *
 * Runs when the plugin is deleted from wp-admin. Data is preserved unless the
 * admin enabled "Delete all data on uninstall" — see includes/uninstall-cleanup.php.
 *
 * @package Starter_Shelter
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/includes/uninstall-cleanup.php';

starter_shelter_run_uninstall();
