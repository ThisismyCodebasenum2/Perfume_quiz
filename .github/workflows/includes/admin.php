<?php
namespace PQ;

if (!defined('ABSPATH')) exit;

class Admin {

    public static function init(){
        add_action('admin_menu', [__CLASS__,'menu']);
        add_action('admin_enqueue_scripts', [__CLASS__,'assets']);

        // AJAX actions (admin)
        add_action('wp_ajax_pq_admin_create_test', [__CLASS__,'ajax_create_test']);
        add_action('wp_ajax_pq_admin_delete_test', [__CLASS__,'ajax_delete_test']);
        add_action('wp_ajax_pq_admin_clone_test',  [__CLASS__,'ajax_clone_test']);

        add_action('wp_ajax_pq_admin_save_question', [__CLASS__,'ajax_save_question']);
        add_action('wp_ajax_pq_admin_delete_question', [__CLASS__,'ajax_delete_question']);
        add_action('wp_ajax_pq_admin_clone_question',  [__CLASS__,'ajax_clone_question']);

        add_action('admin_post_pq_results_bulk',   [__CLASS__,'post_results_bulk']);
        add_action('admin_post_pq_results_clone',  [__CLASS__,'post_results_clone']);
        add_action('admin_post_pq_results_delete', [__CLASS__,'post_results_delete']);

        add_action('admin_post_pq_import', [__CLASS__,'post_import']);
        add_action('admin_post_pq_export_range', [__CLASS__,'post_export_range']);
        add_action('admin_post_pq_download_sample', [__CLASS__,'post_download_sample']);

        add_action('admin_post_pq_save_settings', [__CLASS__,'post_save_settings']);
    }

    /* ----------------------- Assets ----------------------- */

    public static function assets($hook){
        if (strpos($hook, 'perfume-quiz')===false) return;

        // Media for wp.media
        wp_enqueue_media();

        // Admin CSS (سبک، موبایل‌اول)
        $css = '
        @import url("https://fonts.googleapis.com/css2?family=Vazirmatn:wght@400;700&display=swap");
        .pq-admin, .pq-admin *{font-family:Vazirmatn, sans-serif}
        .pq-container{max-width:1100px}
        .pq-card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:16px;margin:12px 0}
        .pq-row{display:flex;gap:10px;flex-wrap:wrap;align-items:center}
        .pq-gap{height:10px}
        .pq-btn{background:#2563eb;color:#fff;border:none;border-radius:8px;padding:8px 12px;cursor:pointer}
        .pq-btn.secondary{background:#111827}
        .pq-btn.warn{background:#ef4444}
        .pq-btn.gray{background:#6b7280}
        .pq-field{display:flex;flex-direction:column;gap:6px;min-width:240px}
        .pq-field input,.pq-field textarea,.pq-field select{border:1px solid #e5e7eb;border-radius:8px;padding:9px}
        .pq-table{width:100%;border-collapse:collapse}
        .pq-table th,.pq-table td{border:1px solid #e5e7eb;padding:8px;text-align:center}
        .pq-badge{background:#111827;color:#fff;border-radius:999px;padding:2px 10px;display:inline-block}
        .pq-right{text-align:right}
        @media(max-width:640px){.pq-field{min-width:100%}}
        ';
        wp_register_style('pq-admin-inline', false);
        wp_enqueue_style('pq-admin-inline');
        wp_add_inline_style('pq-admin-inline', $css);

        // Admin JS (تأییدها، مدیامُدال، تغییر انتخاب تست در Questions)
        $js = '
        (function($){
          "use strict";
          window.PQAdm = window.PQAdm || {};
          PQAdm.pickMedia = function(inputId, previewId){
            var frame = wp.media({title:"انتخاب رسانه", button:{text:"انتخاب"}, multiple:false});
            frame.on("select", function(){
              var att = frame.state().get("selection").first().toJSON();
              $("#"+inputId).val(att.id);
              if (previewId){ $("#"+previewId).text("ID: "+att.id); }
            });
            frame.open();
          };
          PQAdm.confirm = function(msg){
            return window.confirm(msg||"مطمئن هستید؟");
          };
          $(document).on("change","#pq-q-test-select", function(){
            var tid = $(this).val()||"";
            var url = new URL(window.location.href);
            url.searchParams.set("test_id", tid);
            window.location.href = url.toString();
          });
          // جلوگیری از امتیاز برای (هیچ)
          $(document).on("change",".pq-dim", function(){
            var $row = $(this).closest("tr");
            var dim = $(this).val();
            var $score = $row.find(".pq-score-"+$(this).data("grp"));
            if (dim === "-" || dim===""){
              $score.val("").prop("disabled", true);
            } else {
              $score.prop("disabled", false);
            }
          });
          // دکمه پاک‌سازی فرم سؤال
          $(document).on("click","#pq-q-clear", function(e){
            e.preventDefault();
            if(!PQAdm.confirm("مطمئن هستید؟")) return;
            $("#pq-q-form")[0].reset();
            $(".pq-dim").trigger("change");
            $("#pq-q-media-preview").text("");
          });
          // پاپ‌آپ تأیید حذف/کلون تست
          $(document).on("click",".pq-act-del-test, .pq-act-clone-test", function(e){
            if(!PQAdm.confirm("مطمئن هستید؟")){ e.preventDefault(); }
          });
        })(jQuery);
        ';
        wp_register_script('pq-admin-inline', false);
        wp_enqueue_script('pq-admin-inline');
        wp_add_inline_script('pq-admin-inline', $js);
    }

    /* ----------------------- Menu ----------------------- */

    public static function menu(){
        add_menu_page(
            'Perfume Quiz', 'Perfume Quiz', 'manage_options', 'perfume-quiz',
            [__CLASS__,'page_dashboard'], 'dashicons-filter', 56
        );
        add_submenu_page('perfume-quiz','Dashboard','Dashboard','manage_options','perfume-quiz',[__CLASS__,'page_dashboard']);
        add_submenu_page('perfume-quiz','Tests','Tests','manage_options','perfume-quiz-tests',[__CLASS__,'page_tests']);
        add_submenu_page('perfume-quiz','Questions','Questions','manage_options','perfume-quiz-questions',[__CLASS__,'page_questions']);
        add_submenu_page('perfume-quiz','Results','Results','manage_options','perfume-quiz-results',[__CLASS__,'page_results']);
        add_submenu_page('perfume-quiz','Import','Import','manage_options','perfume-quiz-import',[__CLASS__,'page_import']);
        add_submenu_page('perfume-quiz','Export','Export','manage_options','perfume-quiz-export',[__CLASS__,'page_export']);
        add_submenu_page('perfume-quiz','Settings','Settings','manage_options','perfume-quiz-settings',[__CLASS__,'page_settings']);
    }

    /* ----------------------- Pages ----------------------- */

    public static function page_dashboard(){
        global $wpdb; $p=$wpdb->prefix;
        $tests = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}pq_tests WHERE status=1");
        $qs    = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}pq_questions WHERE is_deleted=0");
        $atts  = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}pq_attempts");
        $res   = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}pq_results");
        ?>
        <div class="wrap pq-admin"><div class="pq-container">
            <h1>Perfume Quiz – Dashboard</h1>
            <div class="pq-row">
                <div class="pq-card"><b>تست‌های فعال:</b> <?php echo esc_html($tests); ?></div>
                <div class="pq-card"><b>کل سؤالات:</b> <?php echo esc_html($qs); ?></div>
                <div class="pq-card"><b>تلاش‌ها:</b> <?php echo esc_html($atts); ?></div>
                <div class="pq-card"><b>نتایج:</b> <?php echo esc_html($res); ?></div>
            </div>
            <div class="pq-card">
                <h2>راهنما</h2>
                <p>از تب <b>Tests</b> آزمون بسازید؛ سپس در <b>Questions</b> برای هر آزمون، سؤال/پاسخ/امتیازدهی را وارد کنید. نتایج در <b>Results</b> قابل مشاهده و خروجی گرفتن هستند. تفسیرها را از <b>Import</b> (Interpretations) وارد کنید.</p>
            </div>
        </div></div>
        <?php
    }

    public static function page_tests(){
        global $wpdb; $p=$wpdb->prefix;

        // لیست آزمون‌ها (قدیمی → جدید)
        $rows = $wpdb->get_results("SELECT * FROM {$p}pq_tests ORDER BY created_at ASC, id ASC");

        // شمارنده‌ها
        $q_counts = $wpdb->get_results("SELECT test_id, COUNT(*) c FROM {$p}pq_questions WHERE is_deleted=0 GROUP BY test_id", OBJECT_K);
        $r_counts = $wpdb->get_results("SELECT a.test_id, COUNT(*) c FROM {$p}pq_results r JOIN {$p}pq_attempts a ON a.id=r.attempt_id GROUP BY a.test_id", OBJECT_K);

        $nonce = wp_create_nonce('pq-admin');
        $questions_url = admin_url('admin.php?page=perfume-quiz-questions');
        $results_url   = admin_url('admin.php?page=perfume-quiz-results');
        $export_url    = admin_url('admin.php?page=perfume-quiz-export');
        ?>
        <div class="wrap pq-admin"><div class="pq-container">
            <h1>Tests</h1>
            <div class="pq-card">
                <form id="pq-test-create" method="post" action="<?php echo admin_url('admin-ajax.php'); ?>">
                    <input type="hidden" name="action" value="pq_admin_create_test">
                    <input type="hidden" name="_ajax_nonce" value="<?php echo esc_attr($nonce); ?>">
                    <div class="pq-row">
                        <div class="pq-field" style="flex:1">
                            <label>عنوان آزمون</label>
                            <input type="text" name="title" required>
                        </div>
                        <div class="pq-field">
                            <label>Shortcode</label>
                            <input type="text" name="shortcode" placeholder="[pq_test id=&quot;X&quot;]" value="[pq_test id=&quot;?&quot;]" required>
                        </div>
                        <div class="pq-field">
                            <label>مدیا آزمون (اختیاری)</label>
                            <div class="pq-row">
                                <input type="hidden" id="pq-test-media" name="media_id" value="">
                                <button type="button" class="pq-btn gray" onclick="PQAdm.pickMedia('pq-test-media','pq-test-media-prev')">انتخاب از رسانه</button>
                                <span id="pq-test-media-prev"></span>
                            </div>
                        </div>
                        <div class="pq-field" style="flex:1 0 100%">
                            <label>توضیحات</label>
                            <textarea name="description" rows="2"></textarea>
                        </div>
                    </div>
                    <div class="pq-row">
                        <button class="pq-btn" type="submit">ایجاد آزمون</button>
                    </div>
                </form>
            </div>

            <div class="pq-card">
                <table class="pq-table">
                    <thead>
                        <tr>
                            <th>ID</th><th>عنوان</th><th>مدیا</th><th>سؤالات</th><th>نتایج</th><th>Shortcode</th><th>عملیات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($rows as $r): ?>
                        <tr>
                            <td><?php echo (int)$r->id; ?></td>
                            <td class="pq-right"><?php echo esc_html($r->title); ?></td>
                            <td><?php echo $r->media_id ? '✅' : '—'; ?></td>
                            <td><?php echo isset($q_counts[$r->id]) ? (int)$q_counts[$r->id]->c : 0; ?></td>
                            <td>
                                <?php $rc = isset($r_counts[$r->id]) ? (int)$r_counts[$r->id]->c : 0; ?>
                                <a href="<?php echo esc_url($results_url.'&test_id='.$r->id); ?>" class="pq-badge"><?php echo $rc; ?></a>
                            </td>
                            <td><code>[pq_test id="<?php echo (int)$r->id; ?>"]</code></td>
                            <td>
                                <a class="pq-btn" href="<?php echo esc_url($questions_url.'&test_id='.$r->id); ?>">ویرایش</a>
                                <a class="pq-btn gray pq-act-clone-test" href="<?php echo wp_nonce_url(admin_url('admin-ajax.php?action=pq_admin_clone_test&id='.$r->id),'pq-admin'); ?>">کلون</a>
                                <a class="pq-btn warn pq-act-del-test"  href="<?php echo wp_nonce_url(admin_url('admin-ajax.php?action=pq_admin_delete_test&id='.$r->id),'pq-admin'); ?>">حذف</a>
                                <a class="pq-btn secondary" href="<?php echo esc_url($export_url.'&test_id='.$r->id); ?>">خروجی</a>
                            </td>
                        </tr>
                        <?php endforeach; if(empty($rows)): ?>
                        <tr><td colspan="7">آزمونی ثبت نشده است.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div></div>
        <?php
    }

    public static function page_questions(){
        global $wpdb; $p=$wpdb->prefix;

        $test_id = intval($_GET['test_id'] ?? 0);
        $tests = $wpdb->get_results("SELECT id,title FROM {$p}pq_tests ORDER BY created_at ASC, id ASC");
        $nonce = wp_create_nonce('pq-admin');

        // لیست سؤالات آزمون انتخاب‌شده
        $qs = [];
        if ($test_id){
            $qs = $wpdb->get_results($wpdb->prepare(
                "SELECT id,title,hint,special_hint,media_id,sort_order FROM {$p}pq_questions WHERE test_id=%d AND is_deleted=0 ORDER BY sort_order, id", $test_id
            ));
        }

        ?>
        <div class="wrap pq-admin"><div class="pq-container">
            <h1>Questions</h1>
            <div class="pq-card">
                <div class="pq-row">
                    <div class="pq-field">
                        <label>انتخاب آزمون</label>
                        <select id="pq-q-test-select">
                            <option value="">— انتخاب کنید —</option>
                            <?php foreach($tests as $t): ?>
                                <option value="<?php echo (int)$t->id; ?>" <?php selected($test_id,$t->id); ?>>
                                    #<?php echo (int)$t->id; ?> — <?php echo esc_html($t->title); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>

            <?php if($test_id): ?>
            <div class="pq-card">
                <h2>افزودن سؤال جدید برای آزمون #<?php echo (int)$test_id; ?></h2>
                <form id="pq-q-form" method="post" action="<?php echo admin_url('admin-ajax.php'); ?>">
                    <input type="hidden" name="action" value="pq_admin_save_question">
                    <input type="hidden" name="_ajax_nonce" value="<?php echo esc_attr($nonce); ?>">
                    <input type="hidden" name="test_id" value="<?php echo (int)$test_id; ?>">

                    <div class="pq-row">
                        <div class="pq-field" style="flex:1 1 100%">
                            <label>صورت سؤال</label>
                            <textarea name="title" rows="2" required></textarea>
                        </div>
                        <div class="pq-field" style="flex:1 1 100%">
                            <label>توضیح سؤال</label>
                            <textarea name="hint" rows="2"></textarea>
                        </div>
                        <div class="pq-field" style="flex:1 1 100%">
                            <label>توضیح ویژه (اختیاری)</label>
                            <textarea name="special_hint" rows="2"></textarea>
                        </div>
                        <div class="pq-field">
                            <label>مدیای سؤال (اختیاری)</label>
                            <div class="pq-row">
                                <input type="hidden" id="pq-q-media" name="media_id" value="">
                                <button type="button" class="pq-btn gray" onclick="PQAdm.pickMedia('pq-q-media','pq-q-media-preview')">انتخاب از رسانه</button>
                                <span id="pq-q-media-preview"></span>
                            </div>
                        </div>
                        <div class="pq-field">
                            <label>ترتیب نمایش</label>
                            <input type="number" name="sort_order" value="0">
                        </div>
                    </div>

                    <div class="pq-gap"></div>
                    <h3>پاسخ‌ها و امتیازدهی</h3>
                    <table class="pq-table">
                        <thead>
                            <tr>
                                <th>پاسخ</th>
                                <th>I (FI/TI/NI/SI/-)</th><th>امتیاز</th>
                                <th>E (FE/TE/NE/SE/-)</th><th>امتیاز</th>
                                <th>C (A..I/-)</th><th>امتیاز</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php for($i=1;$i<=5;$i++): ?>
                            <tr>
                                <td><input type="text" name="a<?php echo $i; ?>_label" required placeholder="متن پاسخ"></td>

                                <td>
                                    <select class="pq-dim" data-grp="i" name="a<?php echo $i; ?>_i_dim">
                                        <option value="-">(هیچ)</option>
                                        <option value="fi">FI</option><option value="ti">TI</option>
                                        <option value="ni">NI</option><option value="si">SI</option>
                                    </select>
                                </td>
                                <td><input class="pq-score-i" type="number" step="0.1" min="-5" max="5" name="a<?php echo $i; ?>_i_score" disabled></td>

                                <td>
                                    <select class="pq-dim" data-grp="e" name="a<?php echo $i; ?>_e_dim">
                                        <option value="-">(هیچ)</option>
                                        <option value="fe">FE</option><option value="te">TE</option>
                                        <option value="se">SE</option><option value="ne">NE</option>
                                    </select>
                                </td>
                                <td><input class="pq-score-e" type="number" step="0.1" min="-5" max="5" name="a<?php echo $i; ?>_e_score" disabled></td>

                                <td>
                                    <select class="pq-dim" data-grp="c" name="a<?php echo $i; ?>_c_dim">
                                        <option value="-">(هیچ)</option>
                                        <option>A</option><option>B</option><option>C</option><option>D</option><option>E</option>
                                        <option>F</option><option>G</option><option>H</option><option>I</option>
                                    </select>
                                </td>
                                <td><input class="pq-score-c" type="number" step="0.1" min="-5" max="5" name="a<?php echo $i; ?>_c_score" disabled></td>
                            </tr>
                            <?php endfor; ?>
                        </tbody>
                    </table>

                    <div class="pq-row" style="margin-top:12px">
                        <button class="pq-btn" type="submit">ذخیره سؤال</button>
                        <button class="pq-btn warn" id="pq-q-clear">سؤال جدید (پاک‌سازی)</button>
                    </div>
                </form>
            </div>

            <div class="pq-card">
                <h3>سؤال‌های ثبت‌شده</h3>
                <table class="pq-table">
                    <thead>
                        <tr>
                            <th>ID</th><th>ترتیب</th><th>عنوان</th><th>مدیا</th><th>عملیات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($qs as $q): ?>
                        <tr>
                            <td><?php echo (int)$q->id; ?></td>
                            <td><?php echo (int)$q->sort_order; ?></td>
                            <td class="pq-right"><?php echo esc_html(wp_trim_words($q->title, 18)); ?></td>
                            <td><?php echo $q->media_id ? '✅' : '—'; ?></td>
                            <td>
                                <a class="pq-btn gray" href="<?php echo wp_nonce_url(admin_url('admin-ajax.php?action=pq_admin_clone_question&id='.$q->id),'pq-admin'); ?>">کلون</a>
                                <a class="pq-btn warn" href="<?php echo wp_nonce_url(admin_url('admin-ajax.php?action=pq_admin_delete_question&id='.$q->id),'pq-admin'); ?>" onclick="return PQAdm.confirm('مطمئن هستید؟')">حذف</a>
                            </td>
                        </tr>
                        <?php endforeach; if(empty($qs)): ?>
                        <tr><td colspan="5">سؤالی ثبت نشده است.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
                <div class="pq-card">لطفاً از بالا آزمون را انتخاب کنید.</div>
            <?php endif; ?>
        </div></div>
        <?php
    }

    public static function page_results(){
        global $wpdb; $p=$wpdb->prefix;

        $tests = $wpdb->get_results("SELECT id,title FROM {$p}pq_tests ORDER BY created_at ASC, id ASC");

        $test_id = intval($_GET['test_id'] ?? 0);
        $range   = sanitize_text_field($_GET['range'] ?? '24h');
        $per     = intval($_GET['per'] ?? 10); if(!in_array($per,[10,100,1000],true)) $per=10;
        $page    = max(1, intval($_GET['paged'] ?? 1));
        $offset  = ($page-1)*$per;

        list($from_sql, $label) = self::range_to_sql($range);

        $where = "WHERE 1=1";
        $args = [];
        if ($test_id){ $where .= " AND a.test_id=%d"; $args[]=$test_id; }
        if ($from_sql){ $where .= " AND r.created_at >= %s"; $args[]=$from_sql; }

        $total = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}pq_results r JOIN {$p}pq_attempts a ON a.id=r.attempt_id $where",$args));
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT r.*, a.ctx_json, a.test_id FROM {$p}pq_results r
             JOIN {$p}pq_attempts a ON a.id=r.attempt_id
             $where ORDER BY r.created_at DESC LIMIT %d OFFSET %d",
             array_merge($args, [$per,$offset])
        ));

        $nonce = wp_create_nonce('pq-admin');
        $base_url = admin_url('admin.php?page=perfume-quiz-results&test_id='.$test_id.'&range='.$range.'&per='.$per);

        ?>
        <div class="wrap pq-admin"><div class="pq-container">
            <h1>Results</h1>

            <div class="pq-card">
                <form method="get">
                    <input type="hidden" name="page" value="perfume-quiz-results">
                    <div class="pq-row">
                        <div class="pq-field">
                            <label>آزمون</label>
                            <select name="test_id">
                                <option value="0">— همه —</option>
                                <?php foreach($tests as $t): ?>
                                <option value="<?php echo (int)$t->id; ?>" <?php selected($test_id,$t->id); ?>>
                                    #<?php echo (int)$t->id; ?> — <?php echo esc_html($t->title); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="pq-field">
                            <label>بازه زمانی</label>
                            <select name="range">
                                <?php foreach(['24h'=>'24 ساعت','3d'=>'3 روز','7d'=>'7 روز','14d'=>'14 روز','30d'=>'30 روز','3m'=>'3 ماه','6m'=>'6 ماه','9m'=>'9 ماه','12m'=>'12 ماه','24m'=>'24 ماه'] as $k=>$v): ?>
                                    <option value="<?php echo esc_attr($k); ?>" <?php selected($range,$k); ?>><?php echo esc_html($v); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="pq-field">
                            <label>نمایش در هر صفحه</label>
                            <select name="per">
                                <option value="10" <?php selected($per,10); ?>>10</option>
                                <option value="100" <?php selected($per,100); ?>>100</option>
                                <option value="1000" <?php selected($per,1000); ?>>1000</option>
                            </select>
                        </div>
                        <div class="pq-field">
                            <label>&nbsp;</label>
                            <button class="pq-btn" type="submit">اعمال</button>
                        </div>
                    </div>
                </form>
            </div>

            <div class="pq-card">
                <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                    <input type="hidden" name="action" value="pq_results_bulk">
                    <input type="hidden" name="_wpnonce" value="<?php echo esc_attr($nonce); ?>">
                    <input type="hidden" name="redirect" value="<?php echo esc_url($base_url.'&paged='.$page); ?>">

                    <div class="pq-row" style="justify-content:space-between">
                        <div>نمایش: <b><?php echo esc_html($label); ?></b> — کل: <?php echo (int)$total; ?></div>
                        <div class="pq-row">
                            <select name="bulk">
                                <option value="">— عملیات گروهی —</option>
                                <option value="delete">حذف</option>
                                <option value="export">خروجی CSV</option>
                            </select>
                            <button class="pq-btn gray" type="submit">اعمال</button>
                        </div>
                    </div>
                    <div class="pq-gap"></div>

                    <table class="pq-table">
                        <thead>
                            <tr>
                                <th><input type="checkbox" onclick="jQuery('.pq-chk').prop('checked', this.checked)"></th>
                                <th>تاریخ</th><th>ساعت</th><th>نام</th><th>فامیل</th><th>موبایل</th>
                                <th>FE</th><th>TE</th><th>SE</th><th>NE</th>
                                <th>FI</th><th>TI</th><th>SI</th><th>NI</th>
                                <th>A</th><th>B</th><th>C</th><th>D</th><th>E</th><th>F</th><th>G</th><th>H</th><th>I</th>
                                <th>کد</th><th>عملیات</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($rows as $r):
                                $ctx = json_decode($r->ctx_json, true) ?: [];
                                $dt  = mysql2date('Y-m-d', $r->created_at);
                                $tm  = mysql2date('H:i',    $r->created_at);
                                ?>
                                <tr>
                                    <td><input type="checkbox" class="pq-chk" name="ids[]" value="<?php echo (int)$r->attempt_id; ?>"></td>
                                    <td><?php echo esc_html($dt); ?></td>
                                    <td><?php echo esc_html($tm); ?></td>
                                    <td><?php echo esc_html($ctx['first'] ?? ''); ?></td>
                                    <td><?php echo esc_html($ctx['last'] ?? ''); ?></td>
                                    <td><?php echo esc_html($ctx['mobile'] ?? ''); ?></td>

                                    <td><?php echo esc_html($r->e_fe); ?></td>
                                    <td><?php echo esc_html($r->e_te); ?></td>
                                    <td><?php echo esc_html($r->e_se); ?></td>
                                    <td><?php echo esc_html($r->e_ne); ?></td>

                                    <td><?php echo esc_html($r->i_fi); ?></td>
                                    <td><?php echo esc_html($r->i_ti); ?></td>
                                    <td><?php echo esc_html($r->i_si); ?></td>
                                    <td><?php echo esc_html($r->i_ni); ?></td>

                                    <td><?php echo esc_html($r->c_a); ?></td>
                                    <td><?php echo esc_html($r->c_b); ?></td>
                                    <td><?php echo esc_html($r->c_c); ?></td>
                                    <td><?php echo esc_html($r->c_d); ?></td>
                                    <td><?php echo esc_html($r->c_e); ?></td>
                                    <td><?php echo esc_html($r->c_f); ?></td>
                                    <td><?php echo esc_html($r->c_g); ?></td>
                                    <td><?php echo esc_html($r->c_h); ?></td>
                                    <td><?php echo esc_html($r->c_i); ?></td>

                                    <td><span class="pq-badge"><?php echo esc_html($r->final_code); ?></span></td>
                                    <td>
                                        <a class="pq-btn gray" href="<?php echo wp_nonce_url(admin_url('admin-post.php?action=pq_results_clone&id='.$r->attempt_id),'pq-admin'); ?>">کلون</a>
                                        <a class="pq-btn warn" href="<?php echo wp_nonce_url(admin_url('admin-post.php?action=pq_results_delete&id='.$r->attempt_id),'pq-admin'); ?>" onclick="return PQAdm.confirm('مطمئن هستید؟')">حذف</a>
                                    </td>
                                </tr>
                            <?php endforeach; if(empty($rows)): ?>
                            <tr><td colspan="25">نتیجه‌ای یافت نشد.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>

                    <?php
                    // Pagination
                    $pages = max(1, ceil($total/$per));
                    if ($pages>1){
                        echo '<div class="pq-row" style="justify-content:center;margin-top:12px">';
                        for($i=1;$i<=$pages;$i++){
                            $u = $base_url.'&paged='.$i;
                            $cls = 'pq-btn gray';
                            if ($i==$page) $cls='pq-btn';
                            echo '<a class="'.$cls.'" href="'.esc_url($u).'">'.$i.'</a>';
                        }
                        echo '</div>';
                    }
                    ?>
                </form>
            </div>
        </div></div>
        <?php
    }

    public static function page_import(){
        global $wpdb; $p=$wpdb->prefix;
        $tests = $wpdb->get_results("SELECT id,title FROM {$p}pq_tests ORDER BY created_at ASC, id ASC");
        $nonce = wp_create_nonce('pq-admin');
        ?>
        <div class="wrap pq-admin"><div class="pq-container">
            <h1>Import</h1>

            <div class="pq-card">
              <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" enctype="multipart/form-data">
                <input type="hidden" name="action" value="pq_import">
                <input type="hidden" name="_wpnonce" value="<?php echo esc_attr($nonce); ?>">
                <div class="pq-row">
                    <div class="pq-field">
                        <label>آزمون</label>
                        <select name="test_id" required>
                            <option value="">— انتخاب کنید —</option>
                            <?php foreach($tests as $t): ?>
                                <option value="<?php echo (int)$t->id; ?>">#<?php echo (int)$t->id; ?> — <?php echo esc_html($t->title); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="pq-field">
                        <label>نوع ایمپورت</label>
                        <select name="type" required>
                            <option value="questions">Questions</option>
                            <option value="interpretations">Interpretations</option>
                        </select>
                    </div>
                    <div class="pq-field" style="min-width:320px">
                        <label>فایل CSV (UTF-8 + BOM)</label>
                        <input type="file" name="file" accept=".csv" required>
                    </div>
                </div>
                <div class="pq-row">
                    <button class="pq-btn" type="submit">شروع ایمپورت</button>
                    <a class="pq-btn gray" href="<?php echo wp_nonce_url(admin_url('admin-post.php?action=pq_download_sample&type=questions'),'pq-admin'); ?>">نمونه CSV سؤالات</a>
                    <a class="pq-btn gray" href="<?php echo wp_nonce_url(admin_url('admin-post.php?action=pq_download_sample&type=interpretations'),'pq-admin'); ?>">نمونه CSV تفسیر</a>
                </div>
              </form>
            </div>

            <div class="pq-card">
                <h2>فرمت CSV</h2>
                <h3>Questions</h3>
                <pre style="white-space:pre-wrap;direction:ltr">title,hint,special_hint,media_id,sort_order,
a1_label,a1_i_dim,a1_i_score,a1_e_dim,a1_e_score,a1_c_dim,a1_c_score,
a2_label,a2_i_dim,a2_i_score,a2_e_dim,a2_e_score,a2_c_dim,a2_c_score,
a3_label,a3_i_dim,a3_i_score,a3_e_dim,a3_e_score,a3_c_dim,a3_c_score,
a4_label,a4_i_dim,a4_i_score,a4_e_dim,a4_e_score,a4_c_dim,a4_c_score,
a5_label,a5_i_dim,a5_i_score,a5_e_dim,a5_e_score,a5_c_dim,a5_c_score</pre>

                <h3>Interpretations</h3>
                <pre style="white-space:pre-wrap;direction:ltr">final_code,h1,intro,sections_json,p1,p2,p3
# sections_json sample: {"sections":[{"h":"...","p":"...","img":"..."},{"type":"table","cols":["c1","c2"],"rows":[["r1c1","r1c2"],["r2c1","r2c2"]]}]}</pre>
            </div>
        </div></div>
        <?php
    }

    public static function page_export(){
        global $wpdb; $p=$wpdb->prefix;
        $tests = $wpdb->get_results("SELECT id,title FROM {$p}pq_tests ORDER BY created_at ASC, id ASC");
        $nonce = wp_create_nonce('pq-admin');

        // لیست خروجی‌های موجود
        $rows = $wpdb->get_results("SELECT * FROM {$p}pq_exports ORDER BY created_at DESC LIMIT 50");
        ?>
        <div class="wrap pq-admin"><div class="pq-container">
            <h1>Export</h1>
            <div class="pq-card">
                <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                    <input type="hidden" name="action" value="pq_export_range">
                    <input type="hidden" name="_wpnonce" value="<?php echo esc_attr($nonce); ?>">
                    <div class="pq-row">
                        <div class="pq-field">
                            <label>آزمون</label>
                            <select name="test_id" required>
                                <?php foreach($tests as $t): ?>
                                    <option value="<?php echo (int)$t->id; ?>">#<?php echo (int)$t->id; ?> — <?php echo esc_html($t->title); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="pq-field">
                            <label>بازه</label>
                            <select name="range" required>
                                <?php foreach(['24h','3d','7d','14d','30d','3m','6m','9m','12m','24m'] as $r): ?>
                                    <option><?php echo esc_html($r); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="pq-field">
                            <label>&nbsp;</label>
                            <button class="pq-btn" type="submit">ساخت CSV</button>
                        </div>
                    </div>
                </form>
            </div>

            <div class="pq-card">
                <h2>فایل‌های خروجی اخیر</h2>
                <table class="pq-table">
                    <thead><tr><th>ID</th><th>Test</th><th>Range</th><th>ایجاد</th><th>انقضاء</th><th>دانلود</th></tr></thead>
                    <tbody>
                    <?php foreach($rows as $r): ?>
                        <tr>
                            <td><?php echo (int)$r->id; ?></td>
                            <td><?php echo (int)$r->test_id; ?></td>
                            <td><?php echo esc_html($r->range_key); ?></td>
                            <td><?php echo esc_html($r->created_at); ?></td>
                            <td><?php echo esc_html($r->expire_at); ?></td>
                            <td>
                                <?php if($r->file_path && file_exists($r->file_path)): ?>
                                    <a class="pq-btn" href="<?php echo esc_url( self::file_public_url($r->file_path) ); ?>">دانلود</a>
                                <?php else: echo '—'; endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; if(empty($rows)): ?>
                        <tr><td colspan="6">خروجی‌ای ثبت نشده است.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div></div>
        <?php
    }

    public static function page_settings(){
        $opt = get_option('pq_settings', ['pages'=>[],'drop_on_uninstall'=>false]);
        $nonce = wp_create_nonce('pq-admin');
        ?>
        <div class="wrap pq-admin"><div class="pq-container">
            <h1>Settings</h1>
            <div class="pq-card">
                <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                    <input type="hidden" name="action" value="pq_save_settings">
                    <input type="hidden" name="_wpnonce" value="<?php echo esc_attr($nonce); ?>">
                    <div class="pq-row">
                        <div class="pq-field" style="min-width:320px">
                            <label>URL صفحه آزمون</label>
                            <input type="text" name="pages[test]" value="<?php echo esc_attr($opt['pages']['test'] ?? '/app/test'); ?>">
                        </div>
                        <div class="pq-field" style="min-width:320px">
                            <label>URL صفحه تفسیر (از {id} استفاده کنید)</label>
                            <input type="text" name="pages[interpret]" value="<?php echo esc_attr($opt['pages']['interpret'] ?? '/app/results_testid={id}'); ?>">
                        </div>
                        <div class="pq-field" style="min-width:320px">
                            <label>URL صفحه پروفایل</label>
                            <input type="text" name="pages[profile]" value="<?php echo esc_attr($opt['pages']['profile'] ?? '/account'); ?>">
                        </div>
                        <div class="pq-field">
                            <label><input type="checkbox" name="drop_on_uninstall" value="1" <?php checked( !empty($opt['drop_on_uninstall']) ); ?>> حذف کامل داده‌ها در Uninstall</label>
                        </div>
                    </div>
                    <div class="pq-row">
                        <button class="pq-btn" type="submit">ذخیره تنظیمات</button>
                    </div>
                </form>
            </div>
        </div></div>
        <?php
    }

    /* ----------------------- AJAX: Tests ----------------------- */

    public static function ajax_create_test(){
        check_ajax_referer('pq-admin');
        if (!current_user_can('manage_options')) wp_send_json_error();

        global $wpdb; $p=$wpdb->prefix;
        $title = sanitize_text_field($_POST['title'] ?? '');
        $short = sanitize_text_field($_POST['shortcode'] ?? '');
        $desc  = wp_kses_post($_POST['description'] ?? '');
        $mid   = intval($_POST['media_id'] ?? 0);
        if (!$title) wp_send_json_error(['msg'=>'title invalid']);

        $wpdb->insert("{$p}pq_tests", [
            'title'=>$title, 'description'=>$desc, 'shortcode'=>$short,
            'media_id'=>$mid ?: null,
            'status'=>1, 'created_at'=>current_time('mysql'), 'updated_at'=>current_time('mysql')
        ]);
        wp_send_json_success(['id'=> (int)$wpdb->insert_id]);
    }

    public static function ajax_delete_test(){
        check_admin_referer('pq-admin');
        if (!current_user_can('manage_options')) wp_die();

        global $wpdb; $p=$wpdb->prefix;
        $id = intval($_GET['id'] ?? 0);
        if ($id){
            // حذف آبشاری ساده
            $qids = $wpdb->get_col($wpdb->prepare("SELECT id FROM {$p}pq_questions WHERE test_id=%d",$id));
            if ($qids){
                $wpdb->query("DELETE FROM {$p}pq_answers WHERE question_id IN (".implode(',', array_map('intval',$qids)).")");
                $wpdb->query("DELETE FROM {$p}pq_questions WHERE id IN (".implode(',', array_map('intval',$qids)).")");
            }
            // حذف نتایج مرتبط
            $aids = $wpdb->get_col($wpdb->prepare("SELECT id FROM {$p}pq_attempts WHERE test_id=%d",$id));
            if ($aids){
                $wpdb->query("DELETE FROM {$p}pq_attempt_answers WHERE attempt_id IN (".implode(',', array_map('intval',$aids)).")");
                $wpdb->query("DELETE FROM {$p}pq_results WHERE attempt_id IN (".implode(',', array_map('intval',$aids)).")");
                $wpdb->query("DELETE FROM {$p}pq_attempts WHERE id IN (".implode(',', array_map('intval',$aids)).")");
            }
            $wpdb->delete("{$p}pq_tests", ['id'=>$id]);
        }
        wp_safe_redirect(admin_url('admin.php?page=perfume-quiz-tests'));
        exit;
    }

    public static function ajax_clone_test(){
        check_admin_referer('pq-admin');
        if (!current_user_can('manage_options')) wp_die();

        global $wpdb; $p=$wpdb->prefix;
        $id = intval($_GET['id'] ?? 0);
        if ($id){
            $t = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}pq_tests WHERE id=%d",$id));
            if ($t){
                $wpdb->insert("{$p}pq_tests", [
                    'title'=>$t->title.' (Clone)', 'description'=>$t->description, 'shortcode'=>$t->shortcode,
                    'media_id'=>$t->media_id, 'status'=>$t->status,
                    'created_at'=>current_time('mysql'), 'updated_at'=>current_time('mysql')
                ]);
                $new_id = (int)$wpdb->insert_id;

                // clone questions + answers
                $qs = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$p}pq_questions WHERE test_id=%d AND is_deleted=0 ORDER BY sort_order,id",$id));
                foreach($qs as $q){
                    $wpdb->insert("{$p}pq_questions", [
                        'test_id'=>$new_id, 'title'=>$q->title,'hint'=>$q->hint,'special_hint'=>$q->special_hint,
                        'media_id'=>$q->media_id,'sort_order'=>$q->sort_order,'is_deleted'=>0,
                        'created_at'=>current_time('mysql'),'updated_at'=>current_time('mysql')
                    ]);
                    $new_qid = (int)$wpdb->insert_id;
                    $ans = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$p}pq_answers WHERE question_id=%d ORDER BY sort_order,id",$q->id));
                    foreach($ans as $a){
                        $wpdb->insert("{$p}pq_answers", [
                            'question_id'=>$new_qid,'label'=>$a->label,'note'=>$a->note,'scores_json'=>$a->scores_json,'sort_order'=>$a->sort_order
                        ]);
                    }
                }
            }
        }
        wp_safe_redirect(admin_url('admin.php?page=perfume-quiz-tests'));
        exit;
    }

    /* ----------------------- AJAX: Questions ----------------------- */

    public static function ajax_save_question(){
        check_ajax_referer('pq-admin');
        if (!current_user_can('manage_options')) wp_send_json_error();

        global $wpdb; $p=$wpdb->prefix;
        $test_id = intval($_POST['test_id'] ?? 0);
        if (!$test_id) wp_send_json_error(['msg'=>'test invalid']);

        $title = wp_kses_post($_POST['title'] ?? '');
        if (!$title) wp_send_json_error(['msg'=>'title required']);

        $hint  = wp_kses_post($_POST['hint'] ?? '');
        $sh    = wp_kses_post($_POST['special_hint'] ?? '');
        $mid   = intval($_POST['media_id'] ?? 0);
        $ord   = intval($_POST['sort_order'] ?? 0);

        $wpdb->insert("{$p}pq_questions", [
            'test_id'=>$test_id,'title'=>$title,'hint'=>$hint,'special_hint'=>$sh,
            'media_id'=>$mid ?: null,'sort_order'=>$ord,'is_deleted'=>0,
            'created_at'=>current_time('mysql'),'updated_at'=>current_time('mysql')
        ]);
        $qid = (int)$wpdb->insert_id;

        // 5 answers
        for($i=1;$i<=5;$i++){
            $label = sanitize_text_field($_POST["a{$i}_label"] ?? '');
            if(!$label) continue;

            // sanitize dims and scores (هیچ => نادیده)
            $i_dim = self::norm_dim($_POST["a{$i}_i_dim"] ?? '-', ['fi','ti','ni','si']);
            $e_dim = self::norm_dim($_POST["a{$i}_e_dim"] ?? '-', ['fe','te','se','ne']);
            $c_dim = strtoupper(self::norm_dim($_POST["a{$i}_c_dim"] ?? '-', ['A','B','C','D','E','F','G','H','I']));

            $i_score = isset($_POST["a{$i}_i_score"]) ? floatval($_POST["a{$i}_i_score"]) : 0;
            $e_score = isset($_POST["a{$i}_e_score"]) ? floatval($_POST["a{$i}_e_score"]) : 0;
            $c_score = isset($_POST["a{$i}_c_score"]) ? floatval($_POST["a{$i}_c_score"]) : 0;

            $payload = ['i'=>[],'e'=>[],'c'=>[]];

            if ($i_dim && $i_dim!=='-'){
                $payload['i']=['dim'=>$i_dim,'score'=>max(-5,min(5,$i_score))];
            }
            if ($e_dim && $e_dim!=='-'){
                $payload['e']=['dim'=>$e_dim,'score'=>max(-5,min(5,$e_score))];
            }
            if ($c_dim && $c_dim!=='-'){
                $payload['c']=['dim'=>$c_dim,'score'=>max(-5,min(5,$c_score))];
            }

            $wpdb->insert("{$p}pq_answers", [
                'question_id'=>$qid, 'label'=>$label, 'note'=>null,
                'scores_json'=> wp_json_encode($payload, JSON_UNESCAPED_UNICODE),
                'sort_order'=>$i
            ]);
        }

        wp_send_json_success(['id'=>$qid]);
    }

    private static function norm_dim($val, $allow){
        $val = trim((string)$val);
        if ($val==='' || $val==='-') return '-';
        $val_l = strtolower($val);
        $map = array_combine(array_map('strtolower',$allow), $allow);
        if (isset($map[$val_l])) return $map[$val_l];
        return '-';
    }

    public static function ajax_delete_question(){
        check_admin_referer('pq-admin');
        if (!current_user_can('manage_options')) wp_die();

        global $wpdb; $p=$wpdb->prefix;
        $id = intval($_GET['id'] ?? 0);
        if ($id){
            $wpdb->update("{$p}pq_questions", ['is_deleted'=>1], ['id'=>$id]);
            $wpdb->query($wpdb->prepare("DELETE FROM {$p}pq_answers WHERE question_id=%d",$id));
        }
        wp_safe_redirect(wp_get_referer() ?: admin_url('admin.php?page=perfume-quiz-questions'));
        exit;
    }

    public static function ajax_clone_question(){
        check_admin_referer('pq-admin');
        if (!current_user_can('manage_options')) wp_die();

        global $wpdb; $p=$wpdb->prefix;
        $id = intval($_GET['id'] ?? 0);
        if ($id){
            $q = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}pq_questions WHERE id=%d",$id));
            if ($q){
                $wpdb->insert("{$p}pq_questions", [
                    'test_id'=>$q->test_id,'title'=>$q->title,'hint'=>$q->hint,'special_hint'=>$q->special_hint,
                    'media_id'=>$q->media_id,'sort_order'=>$q->sort_order,'is_deleted'=>0,
                    'created_at'=>current_time('mysql'),'updated_at'=>current_time('mysql')
                ]);
                $new_qid=(int)$wpdb->insert_id;
                $ans=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$p}pq_answers WHERE question_id=%d ORDER BY sort_order,id",$id));
                foreach($ans as $a){
                    $wpdb->insert("{$p}pq_answers", [
                        'question_id'=>$new_qid,'label'=>$a->label,'note'=>$a->note,'scores_json'=>$a->scores_json,'sort_order'=>$a->sort_order
                    ]);
                }
            }
        }
        wp_safe_redirect(wp_get_referer() ?: admin_url('admin.php?page=perfume-quiz-questions'));
        exit;
    }

    /* ----------------------- Results: helpers & posts ----------------------- */

    private static function range_to_sql($key){
        $now = current_time('timestamp');
        $label = '';
        switch($key){
            case '24h': $ts = $now - 24*3600; $label='24 ساعت اخیر'; break;
            case '3d':  $ts = $now - 3*86400; $label='3 روز اخیر'; break;
            case '7d':  $ts = $now - 7*86400; $label='7 روز اخیر'; break;
            case '14d': $ts = $now - 14*86400; $label='14 روز اخیر'; break;
            case '30d': $ts = $now - 30*86400; $label='30 روز اخیر'; break;
            case '3m':  $ts = strtotime('-3 months', $now); $label='3 ماه اخیر'; break;
            case '6m':  $ts = strtotime('-6 months', $now); $label='6 ماه اخیر'; break;
            case '9m':  $ts = strtotime('-9 months', $now); $label='9 ماه اخیر'; break;
            case '12m': $ts = strtotime('-12 months',$now); $label='12 ماه اخیر'; break;
            case '24m': $ts = strtotime('-24 months',$now); $label='24 ماه اخیر'; break;
            default: $ts = 0; $label='همه';
        }
        return [$ts?date('Y-m-d H:i:s',$ts):'', $label];
    }

    public static function post_results_delete(){
        check_admin_referer('pq-admin');
        if (!current_user_can('manage_options')) wp_die();

        global $wpdb; $p=$wpdb->prefix;
        $id = intval($_GET['id'] ?? 0);
        if ($id){
            $wpdb->delete("{$p}pq_results", ['attempt_id'=>$id]);
            $wpdb->delete("{$p}pq_attempt_answers", ['attempt_id'=>$id]);
            $wpdb->delete("{$p}pq_attempts", ['id'=>$id]);
        }
        wp_safe_redirect(wp_get_referer() ?: admin_url('admin.php?page=perfume-quiz-results'));
        exit;
    }

    public static function post_results_clone(){
        check_admin_referer('pq-admin');
        if (!current_user_can('manage_options')) wp_die();

        global $wpdb; $p=$wpdb->prefix;
        $id = intval($_GET['id'] ?? 0);
        if ($id){
            $r = $wpdb->get_row($wpdb->prepare(
                "SELECT r.*, a.test_id, a.ctx_json FROM {$p}pq_results r JOIN {$p}pq_attempts a ON a.id=r.attempt_id WHERE r.attempt_id=%d", $id
            ));
            if ($r){
                // ساخت attempt جدید done
                $wpdb->insert("{$p}pq_attempts", [
                    'user_id'=>null,'test_id'=>$r->test_id,'status'=>'done',
                    'ctx_json'=>$r->ctx_json,'started_at'=>current_time('mysql'),
                    'finished_at'=>current_time('mysql'),'is_soft_deleted'=>0,'cache_token'=>null
                ]);
                $new_att = (int)$wpdb->insert_id;

                // کپی r
                $wpdb->insert("{$p}pq_results", [
                    'attempt_id'=>$new_att,
                    'top1'=>$r->top1,'top2'=>$r->top2,'top3'=>$r->top3,'final_code'=>$r->final_code,
                    'e_fe'=>$r->e_fe,'e_te'=>$r->e_te,'e_se'=>$r->e_se,'e_ne'=>$r->e_ne,
                    'i_fi'=>$r->i_fi,'i_ti'=>$r->i_ti,'i_si'=>$r->i_si,'i_ni'=>$r->i_ni,
                    'c_a'=>$r->c_a,'c_b'=>$r->c_b,'c_c'=>$r->c_c,'c_d'=>$r->c_d,'c_e'=>$r->c_e,
                    'c_f'=>$r->c_f,'c_g'=>$r->c_g,'c_h'=>$r->c_h,'c_i'=>$r->c_i,
                    'created_at'=>current_time('mysql')
                ]);
            }
        }
        wp_safe_redirect(wp_get_referer() ?: admin_url('admin.php?page=perfume-quiz-results'));
        exit;
    }

    public static function post_results_bulk(){
        check_admin_referer('pq-admin');
        if (!current_user_can('manage_options')) wp_die();

        $bulk = sanitize_text_field($_POST['bulk'] ?? '');
        $ids  = array_map('intval', (array)($_POST['ids'] ?? []));
        $redir= esc_url_raw($_POST['redirect'] ?? admin_url('admin.php?page=perfume-quiz-results'));

        if ($ids){
            if ($bulk==='delete'){
                foreach($ids as $id){
                    $_GET['id'] = $id;
                    self::post_results_delete(); // exits
                }
            } elseif ($bulk==='export'){
                // خروجی سریع CSV (دانلود مستقیم)
                self::output_csv_rows($ids);
            }
        }
        wp_safe_redirect($redir); exit;
    }

    private static function output_csv_rows($attempt_ids){
        if (headers_sent()) return;
        global $wpdb; $p=$wpdb->prefix;

        $in = implode(',', array_map('intval',$attempt_ids));
        $rows = $wpdb->get_results("SELECT r.*, a.ctx_json, a.test_id FROM {$p}pq_results r JOIN {$p}pq_attempts a ON a.id=r.attempt_id WHERE r.attempt_id IN ($in) ORDER BY r.created_at DESC");

        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="pq_selected_'.date('Ymd_His').'.csv"');
        echo "\xEF\xBB\xBF"; // BOM

        $cols = ['attempt_id','test_id','date','time','first','last','email','mobile','age','gender',
                 'season','context','job','goal','relig','like','hate',
                 'e_fe','e_te','e_se','e_ne','i_fi','i_ti','i_si','i_ni','c_a','c_b','c_c','c_d','c_e','c_f','c_g','c_h','c_i','final_code'];
        $out = fopen('php://output','w');
        fputcsv($out, $cols);

        foreach($rows as $r){
            $ctx = json_decode($r->ctx_json,true) ?: [];
            $date = mysql2date('Y-m-d', $r->created_at);
            $time = mysql2date('H:i', $r->created_at);
            $line = [
                $r->attempt_id,$r->test_id,$date,$time,
                $ctx['first']??'',$ctx['last']??'',$ctx['email']??'',$ctx['mobile']??'',$ctx['age']??'',$ctx['gender']??'',
                $ctx['season']??'',$ctx['context']??'',$ctx['job']??'',$ctx['goal']??'',$ctx['relig']??'',$ctx['like']??'',$ctx['hate']??'',
                $r->e_fe,$r->e_te,$r->e_se,$r->e_ne,$r->i_fi,$r->i_ti,$r->i_si,$r->i_ni,
                $r->c_a,$r->c_b,$r->c_c,$r->c_d,$r->c_e,$r->c_f,$r->c_g,$r->c_h,$r->c_i,
                $r->final_code
            ];
            fputcsv($out, $line);
        }
        fclose($out);
        exit;
    }

    /* ----------------------- Import / Export posts ----------------------- */

    public static function post_import(){
        check_admin_referer('pq-admin');
        if (!current_user_can('manage_options')) wp_die();

        $type = sanitize_text_field($_POST['type'] ?? '');
        $test_id = intval($_POST['test_id'] ?? 0);
        if (!$type || !$test_id){ wp_die('Invalid params'); }

        if (empty($_FILES['file']['tmp_name'])) wp_die('No file');

        $tmp = $_FILES['file']['tmp_name'];
        $csv = file($tmp, FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES);
        if (!$csv) wp_die('Empty CSV');

        // Remove BOM
        if (substr($csv[0],0,3) === "\xEF\xBB\xBF") $csv[0]= substr($csv[0],3);

        if ($type==='questions'){
            self::import_questions_csv($test_id, $csv);
        } else {
            self::import_interpret_csv($csv);
        }

        wp_safe_redirect(admin_url('admin.php?page=perfume-quiz-import&done=1'));
        exit;
    }

    private static function import_questions_csv($test_id, $lines){
        global $wpdb; $p=$wpdb->prefix;

        // header
        $head = str_getcsv($lines[0]);
        for($i=1;$i<count($lines);$i++){
            $row = array_combine($head, str_getcsv($lines[$i]));
            if (!$row) continue;

            $title = wp_kses_post($row['title'] ?? '');
            if (!$title) continue;

            $hint  = wp_kses_post($row['hint'] ?? '');
            $sh    = wp_kses_post($row['special_hint'] ?? '');
            $mid   = intval($row['media_id'] ?? 0);
            $ord   = intval($row['sort_order'] ?? 0);

            $wpdb->insert("{$p}pq_questions", [
                'test_id'=>$test_id,'title'=>$title,'hint'=>$hint,'special_hint'=>$sh,
                'media_id'=>$mid?:null,'sort_order'=>$ord,'is_deleted'=>0,
                'created_at'=>current_time('mysql'),'updated_at'=>current_time('mysql')
            ]);
            $qid = (int)$wpdb->insert_id;

            for($ai=1;$ai<=5;$ai++){
                $lbl = sanitize_text_field($row["a{$ai}_label"] ?? '');
                if (!$lbl) continue;

                $i_dim = self::norm_dim($row["a{$ai}_i_dim"] ?? '-', ['fi','ti','ni','si']);
                $e_dim = self::norm_dim($row["a{$ai}_e_dim"] ?? '-', ['fe','te','se','ne']);
                $c_dim = strtoupper(self::norm_dim($row["a{$ai}_c_dim"] ?? '-', ['A','B','C','D','E','F','G','H','I']));

                $i_score = isset($row["a{$ai}_i_score"]) ? floatval($row["a{$ai}_i_score"]) : 0;
                $e_score = isset($row["a{$ai}_e_score"]) ? floatval($row["a{$ai}_e_score"]) : 0;
                $c_score = isset($row["a{$ai}_c_score"]) ? floatval($row["a{$ai}_c_score"]) : 0;

                $payload = ['i'=>[],'e'=>[],'c'=>[]];
                if ($i_dim && $i_dim!=='-'){ $payload['i']=['dim'=>$i_dim,'score'=>max(-5,min(5,$i_score))]; }
                if ($e_dim && $e_dim!=='-'){ $payload['e']=['dim'=>$e_dim,'score'=>max(-5,min(5,$e_score))]; }
                if ($c_dim && $c_dim!=='-'){ $payload['c']=['dim'=>$c_dim,'score'=>max(-5,min(5,$c_score))]; }

                $wpdb->insert("{$p}pq_answers", [
                    'question_id'=>$qid,'label'=>$lbl,'note'=>null,
                    'scores_json'=> wp_json_encode($payload, JSON_UNESCAPED_UNICODE),
                    'sort_order'=>$ai
                ]);
            }
        }
    }

    private static function import_interpret_csv($lines){
        global $wpdb; $p=$wpdb->prefix;

        $head = str_getcsv($lines[0]);
        for($i=1;$i<count($lines);$i++){
            $row = array_combine($head, str_getcsv($lines[$i]));
            if (!$row) continue;

            $code = sanitize_text_field($row['final_code'] ?? '');
            if (!$code) continue;

            $h1   = sanitize_text_field($row['h1'] ?? '');
            $intro= wp_kses_post($row['intro'] ?? '');
            $sec_json = $row['sections_json'] ?? '';
            $sections = [];
            if ($sec_json){
                $tmp = json_decode($sec_json,true);
                if (is_array($tmp)){
                    // اجازهٔ table
                    $sections = $tmp['sections'] ?? $tmp;
                }
            }
            $p1 = intval($row['p1'] ?? 0);
            $p2 = intval($row['p2'] ?? 0);
            $p3 = intval($row['p3'] ?? 0);

            $content = [
                'h1'=>$h1,'intro'=>$intro,
                'sections'=>$sections,
                'products'=>['p1'=>$p1,'p2'=>$p2,'p3'=>$p3]
            ];

            $wpdb->replace("{$p}pq_result_defs", [
                'final_code'=>$code,
                'content_json'=> wp_json_encode($content, JSON_UNESCAPED_UNICODE),
                'updated_at'=> current_time('mysql')
            ]);
        }
    }

    public static function post_export_range(){
        check_admin_referer('pq-admin');
        if (!current_user_can('manage_options')) wp_die();

        global $wpdb; $p=$wpdb->prefix;
        $test_id = intval($_POST['test_id'] ?? 0);
        $range   = sanitize_text_field($_POST['range'] ?? '24h');
        if (!$test_id) wp_die('invalid');

        list($from_sql,$label) = self::range_to_sql($range);
        $where = "WHERE a.test_id=%d".($from_sql?" AND r.created_at>=%s":'');
        $args = $from_sql ? [$test_id,$from_sql] : [$test_id];

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT r.*, a.ctx_json, a.test_id FROM {$p}pq_results r
             JOIN {$p}pq_attempts a ON a.id=r.attempt_id
             $where ORDER BY r.created_at DESC", $args
        ));

        // ساخت فایل CSV در wp-content/uploads/pq_exports
        $upload = wp_upload_dir();
        $dir = trailingslashit($upload['basedir']).'pq_exports';
        if (!file_exists($dir)) wp_mkdir_p($dir);

        $file = $dir.'/export_'.$test_id.'_'.$range.'_'.date('Ymd_His').'.csv';
        $out = fopen($file,'w');
        // BOM
        fwrite($out, "\xEF\xBB\xBF");

        $cols = ['attempt_id','test_id','date','time','first','last','email','mobile','age','gender',
                 'season','context','job','goal','relig','like','hate',
                 'e_fe','e_te','e_se','e_ne','i_fi','i_ti','i_si','i_ni','c_a','c_b','c_c','c_d','c_e','c_f','c_g','c_h','c_i','final_code'];
        fputcsv($out, $cols);

        foreach($rows as $r){
            $ctx = json_decode($r->ctx_json,true) ?: [];
            $date = mysql2date('Y-m-d', $r->created_at);
            $time = mysql2date('H:i', $r->created_at);
            $line = [
                $r->attempt_id,$r->test_id,$date,$time,
                $ctx['first']??'',$ctx['last']??'',$ctx['email']??'',$ctx['mobile']??'',$ctx['age']??'',$ctx['gender']??'',
                $ctx['season']??'',$ctx['context']??'',$ctx['job']??'',$ctx['goal']??'',$ctx['relig']??'',$ctx['like']??'',$ctx['hate']??'',
                $r->e_fe,$r->e_te,$r->e_se,$r->e_ne,$r->i_fi,$r->i_ti,$r->i_si,$r->i_ni,
                $r->c_a,$r->c_b,$r->c_c,$r->c_d,$r->c_e,$r->c_f,$r->c_g,$r->c_h,$r->c_i,
                $r->final_code
            ];
            fputcsv($out, $line);
        }
        fclose($out);

        // ثبت در pq_exports (Expire: 7 روز)
        $wpdb->insert("{$p}pq_exports", [
            'test_id'=>$test_id,'range_key'=>$range,'file_path'=>$file,
            'created_at'=> current_time('mysql'),
            'expire_at'=> date('Y-m-d H:i:s', time()+7*86400)
        ]);

        wp_safe_redirect(admin_url('admin.php?page=perfume-quiz-export&done=1'));
        exit;
    }

    public static function post_download_sample(){
        check_admin_referer('pq-admin');
        if (!current_user_can('manage_options')) wp_die();

        $type = sanitize_text_field($_GET['type'] ?? 'questions');
        if (headers_sent()) return;
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="pq_sample_'.$type.'.csv"');
        echo "\xEF\xBB\xBF";

        if ($type==='interpretations'){
            $cols = ['final_code','h1','intro','sections_json','p1','p2','p3'];
            $row  = ['ENFP-E','تیتر نمونه','خلاصه‌ی کوتاه','{"sections":[{"h":"هدر","p":"متن"},{"type":"table","cols":["c1","c2"],"rows":[["r1c1","r1c2"]]}]}',101,102,103];
        } else {
            $cols = ['title','hint','special_hint','media_id','sort_order'];
            for($i=1;$i<=5;$i++){
                array_push($cols,"a{$i}_label","a{$i}_i_dim","a{$i}_i_score","a{$i}_e_dim","a{$i}_e_score","a{$i}_c_dim","a{$i}_c_score");
            }
            $row = ['صورت سوال نمونه','توضیح','توضیح ویژه','',0,
                    'پاسخ 1','fi',1.5,'fe',0.5,'A',0.3,
                    'پاسخ 2','-','', 'se',1.0,'-', '',
                    'پاسخ 3','ti',-0.4,'-','', 'C',1.2,
                    'پاسخ 4','si',0.0,'ne',-0.2,'-', '',
                    'پاسخ 5','-','', '-',  '', 'I',-0.3];
        }
        $out = fopen('php://output','w');
        fputcsv($out,$cols);
        fputcsv($out,$row);
        fclose($out);
        exit;
    }

    public static function post_save_settings(){
        check_admin_referer('pq-admin');
        if (!current_user_can('manage_options')) wp_die();

        $pages = (array)($_POST['pages'] ?? []);
        $drop  = !empty($_POST['drop_on_uninstall']);
        $opt = get_option('pq_settings', []);
        $opt['pages'] = [
            'test'=> sanitize_text_field($pages['test'] ?? '/app/test'),
            'interpret'=> sanitize_text_field($pages['interpret'] ?? '/app/results_testid={id}'),
            'profile'=> sanitize_text_field($pages['profile'] ?? '/account'),
        ];
        $opt['drop_on_uninstall'] = $drop ? 1 : 0;
        update_option('pq_settings',$opt);

        wp_safe_redirect(admin_url('admin.php?page=perfume-quiz-settings&saved=1')); exit;
    }

    /* ----------------------- Helpers ----------------------- */

    private static function file_public_url($path){
        $upload = wp_upload_dir();
        if (strpos($path, $upload['basedir'])===0){
            return str_replace($upload['basedir'], $upload['baseurl'], $path);
        }
        return '';
    }
}
