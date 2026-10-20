<?php
/**
 * Removes all plugin data. Only runs when the plugin is deleted from wp-admin.
 */

declare(strict_types=1);

defined('WP_UNINSTALL_PLUGIN') || exit;

global $wpdb;

// phpcs:disable WordPress.DB.DirectDatabaseQuery
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}raek_deliveries");
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}raek_webhooks");
// phpcs:enable

delete_option('raek_db_version');
delete_option('raek_jwt_secret');
delete_metadata('user', 0, 'raek_refresh_jtis', '', true);
delete_metadata('user', 0, 'raek_token_ver', '', true);

$items = get_posts(['post_type' => 'raek_item', 'numberposts' => -1, 'fields' => 'ids', 'post_status' => 'any']);
foreach ($items as $id) {
    wp_delete_post((int) $id, true);
}
