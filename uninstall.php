<?php

declare(strict_types=1);

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

$cleanupHook = 'ysai_daily_cleanup';
wp_clear_scheduled_hook($cleanupHook);
foreach (array(
    'ysai_cleanup_schedule_retry_after',
    'ysai_cleanup_schedule_log_after',
    'ysai_cleanup_schedule_failure_reason',
) as $operationalOption) {
    delete_option($operationalOption);
}

$options = get_option('ysai_options', array());
$deleteFlag = is_array($options) ? ($options['delete_data_on_uninstall'] ?? 0) : 0;
$deleteData = $deleteFlag === true || $deleteFlag === 1 || $deleteFlag === '1';
if (!$deleteData) {
    return;
}

global $wpdb;
$tables = array(
    $wpdb->prefix . 'ysai_v2_messages',
    $wpdb->prefix . 'ysai_v2_turns',
    $wpdb->prefix . 'ysai_v2_conversations',
    $wpdb->prefix . 'ysai_v2_rate_limits',
);
foreach ($tables as $table) {
    $safe = preg_replace('/[^A-Za-z0-9_]/', '', $table);
    if (is_string($safe) && $safe === $table) {
        $wpdb->query("DROP TABLE IF EXISTS `{$safe}`"); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    }
}

delete_option('ysai_schema_version');
delete_option('ysai_options');
