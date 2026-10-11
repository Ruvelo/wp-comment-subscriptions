<?php

/**
 * Runs when the plugin is deleted from the Plugins screen.
 *
 * Scheduled jobs are always cleared, so nothing is left running.
 * Subscriptions and settings are deleted only when the site owner ticked
 * "Also delete every subscription and setting".
 */

declare(strict_types=1);

if (! defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

wp_clear_scheduled_hook('ruvelo_comment_subscriptions_send');
wp_clear_scheduled_hook('ruvelo_comment_subscriptions_daily');
delete_option('ruvelo_comment_subscriptions_digest_due');

$ruvelo_settings = get_option('ruvelo_comment_subscriptions_settings', []);
$ruvelo_delete = is_array($ruvelo_settings) && ! empty($ruvelo_settings['delete_data']);

/**
 * Lets a site decide in code, e.g. on a staging copy.
 *
 * @param bool $delete
 */
if (! apply_filters('ruvelo_comment_subscriptions_uninstall_delete_data', $ruvelo_delete)) {
    return;
}

global $wpdb;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.SlowDBQuery -- Removing the plugin's own tables and data, once.
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}ruvelo_comment_subscriptions");
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}ruvelo_comment_subscriptions_queue");
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}ruvelo_comment_subscriptions_digest");
$wpdb->delete($wpdb->postmeta, ['meta_key' => '_ruvelo_cs_disabled']);
$wpdb->delete($wpdb->usermeta, ['meta_key' => 'ruvelo_comment_subscriptions_delivery']);
$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", $wpdb->esc_like('_transient_ruvelo_cs_').'%', $wpdb->esc_like('_transient_timeout_ruvelo_cs_').'%'));
// phpcs:enable

foreach (['ruvelo_comment_subscriptions_settings', 'ruvelo_comment_subscriptions_db_version', 'ruvelo_comment_subscriptions_secret'] as $ruvelo_option) {
    delete_option($ruvelo_option);
}

foreach (wp_roles()->role_objects as $ruvelo_role) {
    $ruvelo_role->remove_cap('ruvelo_manage_comment_subscriptions');
}
