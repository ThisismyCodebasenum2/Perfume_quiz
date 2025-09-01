<?php
// Perfume Quiz – Uninstall
if (!defined('WP_UNINSTALL_PLUGIN')) exit;

$opt = get_option('pq_settings', array());
$drop = !empty($opt['drop_on_uninstall']);

if ($drop) {
    global $wpdb; $p = $wpdb->prefix;

    // حذف فایل‌های خروجی باقی‌مانده
    $dir = WP_CONTENT_DIR.'/uploads/pq_exports';
    if (is_dir($dir)) {
        foreach (glob($dir.'/*.csv') as $file) {
            @unlink($file);
        }
        // اگر خالی شد، پوشه را هم حذف کن
        @rmdir($dir);
    }

    // حذف جداول
    $tables = array(
        "{$p}pq_exports",
        "{$p}pq_result_defs",
        "{$p}pq_results",
        "{$p}pq_attempt_answers",
        "{$p}pq_attempts",
        "{$p}pq_answers",
        "{$p}pq_questions",
        "{$p}pq_tests"
    );
    foreach ($tables as $t) {
        $wpdb->query("DROP TABLE IF EXISTS {$t}");
    }

    // حذف optionها
    delete_option('pq_settings');
    delete_option('pq_pending_install');
}
