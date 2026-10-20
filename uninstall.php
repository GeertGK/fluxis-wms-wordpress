<?php

/**
 * Removes the plugin's options and transients. Product data (including
 * _fluxis_* product meta and imported images) is intentionally kept.
 */

declare(strict_types=1);

defined('WP_UNINSTALL_PLUGIN') || exit;

global $wpdb;

delete_option('fluxis_wc_connection');
delete_option('fluxis_wc_last_status');
delete_option('fluxis_wc_current_batch');

// phpcs:disable WordPress.DB.DirectDatabaseQuery
$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like('fluxis_wc_') . '%'));
$wpdb->query($wpdb->prepare(
    "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
    $wpdb->esc_like('_transient_fluxis_') . '%',
    $wpdb->esc_like('_transient_timeout_fluxis_') . '%',
    $wpdb->esc_like('_site_transient_fluxis_') . '%',
    $wpdb->esc_like('_site_transient_timeout_fluxis_') . '%',
));
// phpcs:enable

if (function_exists('as_unschedule_all_actions')) {
    as_unschedule_all_actions('', [], 'fluxis');
}
