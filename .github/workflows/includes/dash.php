<?php
namespace PQ;
if (!defined('ABSPATH')) exit;

class Dash {
    public static function init(){
        add_action('admin_menu', array(__CLASS__,'menu_user'));
    }

    public static function menu_user(){
        if (!is_user_logged_in()) return;
        if (current_user_can('manage_options')) return; // ادمین‌ها نبینند

        add_menu_page(
            'نتایج آزمون‌های من',
            'نتایج من',
            'read',
            'pq-my-results',
            array(__CLASS__,'render'),
            'dashicons-chart-line',
            3
        );
    }

    public static function render(){
        if (!is_user_logged_in()) wp_die('لطفاً وارد شوید.');
        $uid = get_current_user_id();
        $opt = Core::get_settings();
        $interpret = !empty($opt['pages']['interpret'])? $opt['pages']['interpret'] : '';

        echo '<div class="wrap"><h1>نتایج آزمون‌های من</h1>';

        $last = self::get_last($uid);
        if ($last){
            echo '<h2>آخرین نتیجه</h2>';
            echo '<p>کد نهایی: <strong>'.esc_html($last->final_code).'</strong> – '.esc_html($last->finished_at).'</p>';
            if ($interpret){
                $url = add_query_arg('attempt',$last->attempt_id,$interpret);
                echo '<p><a class="button button-primary" href="'.esc_url($url).'" target="_blank">نمایش تفسیر</a></p>';
            }
        } else {
            echo '<p>هیچ نتیجه‌ای موجود نیست.</p>';
        }

        echo '<h2>تاریخچه</h2>';
        $rows = self::get_history($uid, 50);
        if ($rows){
            echo '<table class="widefat striped"><thead><tr><th>#</th><th>آزمون</th><th>کد</th><th>تاریخ</th><th>تفسیر</th></tr></thead><tbody>';
            foreach($rows as $r){
                $link = $interpret? '<a href="'.esc_url(add_query_arg('attempt',$r->attempt_id,$interpret)).'" target="_blank">مشاهده</a>' : '-';
                echo '<tr>'.
                     '<td>'.$r->attempt_id.'</td>'.
                     '<td>#'.$r->test_id.'</td>'.
                     '<td><strong>'.$r->final_code.'</strong></td>'.
                     '<td>'.$r->finished_at.'</td>'.
                     '<td>'.$link.'</td>'.
                     '</tr>';
            }
            echo '</tbody></table>';
        } else {
            echo '<p>—</p>';
        }

        echo '</div>';
    }

    private static function get_last($uid){
        global $wpdb; $p=$wpdb->prefix;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT r.final_code,a.test_id,a.id attempt_id,a.finished_at
             FROM {$p}pq_attempts a JOIN {$p}pq_results r ON r.attempt_id=a.id
             WHERE a.user_id=%d AND a.status='done'
             ORDER BY a.finished_at DESC LIMIT 1", $uid
        ));
    }
    private static function get_history($uid,$limit){
        global $wpdb; $p=$wpdb->prefix;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT r.final_code,a.test_id,a.id attempt_id,a.finished_at
             FROM {$p}pq_attempts a JOIN {$p}pq_results r ON r.attempt_id=a.id
             WHERE a.user_id=%d AND a.status='done'
             ORDER BY a.finished_at DESC LIMIT %d", $uid,$limit
        ));
    }
}
