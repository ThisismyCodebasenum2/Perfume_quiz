<?php
/**
 * Plugin Name: Perfume Quiz
 * Description: آزمون شخصیت عطر با مدیریت تست/سوال/پاسخ، تفسیر نتایج، ایمپورت/اکسپورت، اتصال ووکامرس و پروفایل کاربر.
 * Version: 0.9.1
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Author: You
 * Text Domain: perfume-quiz
 */

if (!defined('ABSPATH')) exit;

/** -----------------------------------------------------------------------
 *  Constants
 *  -------------------------------------------------------------------- */
define('PQ_VER', '0.9.1');       // نسخه افزونه
define('PQ_DB_VER', '0.9.1');    // نسخه اسکیما دیتابیس (برای ارتقای dbDelta)
define('PQ_PATH', plugin_dir_path(__FILE__));
define('PQ_URL',  plugin_dir_url(__FILE__));

/** -----------------------------------------------------------------------
 *  Requirements
 *  -------------------------------------------------------------------- */
if (version_compare(PHP_VERSION, '7.4', '<')) {
    add_action('admin_notices', function () {
        echo '<div class="notice notice-error"><p><b>Perfume Quiz:</b> نیازمند PHP 7.4+ است.</p></div>';
    });
    return;
}

/** -----------------------------------------------------------------------
 *  Activation / Deactivation
 *  -------------------------------------------------------------------- */
register_activation_hook(__FILE__, function(){
    // نصب تنبل: فقط فلگ می‌گذاریم تا در admin_init اجرا شود
    update_option('pq_pending_install', 1, false);

    // زمان‌بندی پاکسازی روزانه (خروجی‌های منقضی و فایل‌ها)
    if (!wp_next_scheduled('pq_daily_cleanup')) {
        wp_schedule_event(time() + 3600, 'daily', 'pq_daily_cleanup');
    }
});

register_deactivation_hook(__FILE__, function(){
    // لغو کران پاکسازی
    $ts = wp_next_scheduled('pq_daily_cleanup');
    if ($ts) wp_unschedule_event($ts, 'pq_daily_cleanup');
});

/** -----------------------------------------------------------------------
 *  Installer / Upgrader (dbDelta)
 *  -------------------------------------------------------------------- */
add_action('admin_init', function () {
    $need_install = get_option('pq_pending_install') || get_option('pq_db_ver') !== PQ_DB_VER;
    if (!$need_install) return;

    require_once ABSPATH.'wp-admin/includes/upgrade.php';
    global $wpdb; $p=$wpdb->prefix;
    $charset = $wpdb->get_charset_collate();

    // توجه: dbDelta نیاز دارد هر جدول با CREATE TABLE کامل بیاید
    $sql = "
    CREATE TABLE {$p}pq_tests (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        title VARCHAR(255) NOT NULL,
        description LONGTEXT NULL,
        shortcode VARCHAR(64) NOT NULL,
        media_id BIGINT UNSIGNED NULL,
        status TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        KEY status (status)
    ) {$charset};

    CREATE TABLE {$p}pq_questions (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        test_id BIGINT UNSIGNED NOT NULL,
        title TEXT NOT NULL,
        hint TEXT NULL,
        special_hint TEXT NULL,
        media_id BIGINT UNSIGNED NULL,
        sort_order INT NOT NULL DEFAULT 0,
        is_deleted TINYINT(1) NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        KEY test_idx (test_id, sort_order),
        KEY is_deleted (is_deleted)
    ) {$charset};

    CREATE TABLE {$p}pq_answers (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        question_id BIGINT UNSIGNED NOT NULL,
        label VARCHAR(255) NOT NULL,
        note TEXT NULL,
        scores_json LONGTEXT NOT NULL,
        sort_order INT NOT NULL DEFAULT 0,
        PRIMARY KEY (id),
        KEY q_idx (question_id, sort_order)
    ) {$charset};

    CREATE TABLE {$p}pq_attempts (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id BIGINT UNSIGNED NULL,
        test_id BIGINT UNSIGNED NOT NULL,
        status ENUM('in_progress','done') NOT NULL DEFAULT 'in_progress',
        ctx_json LONGTEXT NULL,
        started_at DATETIME NOT NULL,
        finished_at DATETIME NULL,
        is_soft_deleted TINYINT(1) NOT NULL DEFAULT 0,
        cache_token VARCHAR(64) NULL,
        PRIMARY KEY (id),
        KEY u_idx (user_id, test_id, status),
        KEY t_idx (test_id, status)
    ) {$charset};

    CREATE TABLE {$p}pq_attempt_answers (
        attempt_id BIGINT UNSIGNED NOT NULL,
        question_id BIGINT UNSIGNED NOT NULL,
        answer_id BIGINT UNSIGNED NOT NULL,
        answered_at DATETIME NOT NULL,
        PRIMARY KEY (attempt_id, question_id),
        KEY a_idx (answer_id)
    ) {$charset};

    /* جدول نتایج با ذخیره‌ی عددی تمام المان‌ها (FE/TE/SE/NE | FI/TI/SI/NI | A..I) */
    CREATE TABLE {$p}pq_results (
        attempt_id BIGINT UNSIGNED NOT NULL,

        top1 VARCHAR(4) NOT NULL,    -- FE/TE/SE/NE/FI/TI/SI/NI
        top2 VARCHAR(4) NOT NULL,
        top3 CHAR(1) NOT NULL,       -- A..I
        final_code VARCHAR(16) NOT NULL,   -- مثلاً ENFP-E

        /* جمع امتیازها (دو رقم اعشار) */
        e_fe DECIMAL(6,2) NOT NULL DEFAULT 0,
        e_te DECIMAL(6,2) NOT NULL DEFAULT 0,
        e_se DECIMAL(6,2) NOT NULL DEFAULT 0,
        e_ne DECIMAL(6,2) NOT NULL DEFAULT 0,

        i_fi DECIMAL(6,2) NOT NULL DEFAULT 0,
        i_ti DECIMAL(6,2) NOT NULL DEFAULT 0,
        i_si DECIMAL(6,2) NOT NULL DEFAULT 0,
        i_ni DECIMAL(6,2) NOT NULL DEFAULT 0,

        c_a  DECIMAL(6,2) NOT NULL DEFAULT 0,
        c_b  DECIMAL(6,2) NOT NULL DEFAULT 0,
        c_c  DECIMAL(6,2) NOT NULL DEFAULT 0,
        c_d  DECIMAL(6,2) NOT NULL DEFAULT 0,
        c_e  DECIMAL(6,2) NOT NULL DEFAULT 0,
        c_f  DECIMAL(6,2) NOT NULL DEFAULT 0,
        c_g  DECIMAL(6,2) NOT NULL DEFAULT 0,
        c_h  DECIMAL(6,2) NOT NULL DEFAULT 0,
        c_i  DECIMAL(6,2) NOT NULL DEFAULT 0,

        created_at DATETIME NOT NULL,

        PRIMARY KEY (attempt_id),
        KEY code_idx (final_code)
    ) {$charset};

    CREATE TABLE {$p}pq_result_defs (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        final_code VARCHAR(16) NOT NULL UNIQUE,
        content_json LONGTEXT NOT NULL,  -- {h1,intro,sections[],products{p1,p2,p3}}
        updated_at DATETIME NOT NULL,
        PRIMARY KEY (id)
    ) {$charset};

    CREATE TABLE {$p}pq_exports (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        test_id BIGINT UNSIGNED NOT NULL,
        range_key VARCHAR(16) NOT NULL,  -- 24h/3d/7d/30d/3m/...
        file_path TEXT NOT NULL,
        created_at DATETIME NOT NULL,
        expire_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        KEY t_range (test_id, range_key),
        KEY expire_idx (expire_at)
    ) {$charset};
    ";

    dbDelta($sql);

    // گزینه‌های پیش‌فرض
    if (!get_option('pq_settings')) {
        add_option('pq_settings', array(
            'pages'=>array('test'=>'','interpret'=>'','profile'=>'','shop'=>''),
            'drop_on_uninstall'=>false
        ));
    }

    // پاک‌کردن فلگ و ثبت نسخه اسکیما
    delete_option('pq_pending_install');
    update_option('pq_db_ver', PQ_DB_VER, false);
});

/** -----------------------------------------------------------------------
 *  Daily cleanup (expired exports)
 *  -------------------------------------------------------------------- */
if (!function_exists('pq_daily_cleanup_cb')) {
    function pq_daily_cleanup_cb(){
        global $wpdb; $p=$wpdb->prefix;

        // حذف فایل‌های خروجی منقضی‌شده و رکوردهایشان
        $now = current_time('mysql');
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, file_path FROM {$p}pq_exports WHERE expire_at <= %s", $now
        ));
        if ($rows){
            foreach($rows as $r){
                $fp = $r->file_path;
                if ($fp && file_exists($fp)) @unlink($fp);
                $wpdb->delete("{$p}pq_exports", ['id'=>$r->id]);
            }
        }

        // (اختیاری) پاکسازی تلاش‌های in_progress قدیمی‌تر از 48 ساعت بدون پاسخ
        // می‌توانید در صورت نیاز اضافه کنید.
    }
    add_action('pq_daily_cleanup', 'pq_daily_cleanup_cb');
}

/** -----------------------------------------------------------------------
 *  Textdomain (در صورت نیاز به ترجمه)
 *  -------------------------------------------------------------------- */
add_action('init', function(){
    load_plugin_textdomain('perfume-quiz', false, dirname(plugin_basename(__FILE__)).'/languages');
});

/** -----------------------------------------------------------------------
 *  Load Classes
 *  -------------------------------------------------------------------- */
add_action('plugins_loaded', function () {
    // لود کلاس‌های اصلی
    require_once PQ_PATH.'includes/core.php';
    require_once PQ_PATH.'includes/admin.php';

    // مقداردهی
    if (class_exists('\\PQ\\Core'))  \PQ\Core::init();
    if (is_admin() && class_exists('\\PQ\\Admin')) \PQ\Admin::init();
});

/** -----------------------------------------------------------------------
 *  Uninstall (اختیاری – در صورت داشتن uninstall.php فعال می‌ماند)
 *  -------------------------------------------------------------------- */
/*
register_uninstall_hook(__FILE__, function(){
    // اگر نمی‌خواهید چیزی پاک شود، این بخش را خالی بگذارید
    // یا uninstall.php مجزا بسازید و drop_on_uninstall را بررسی کنید.
});
*/
