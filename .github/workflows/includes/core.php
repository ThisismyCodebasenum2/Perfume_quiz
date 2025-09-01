<?php
namespace PQ;

if (!defined('ABSPATH')) exit;

class Core {

    public static function init(){
        add_shortcode('pq_test',        [__CLASS__, 'sc_test']);
        add_shortcode('pq_test_results',[__CLASS__, 'sc_results']);
        add_shortcode('pq_profile',     [__CLASS__, 'sc_profile']);

        // AJAX endpoints
        add_action('wp_ajax_pq_check_resume',      [__CLASS__, 'ajax_check_resume']);
        add_action('wp_ajax_nopriv_pq_check_resume',[__CLASS__, 'ajax_check_resume']);

        add_action('wp_ajax_pq_check_identity',      [__CLASS__, 'ajax_check_identity']);
        add_action('wp_ajax_nopriv_pq_check_identity',[__CLASS__, 'ajax_check_identity']);

        add_action('wp_ajax_pq_prefetch',      [__CLASS__, 'ajax_prefetch']);
        add_action('wp_ajax_nopriv_pq_prefetch',[__CLASS__, 'ajax_prefetch']);

        add_action('wp_ajax_pq_save_answer',      [__CLASS__, 'ajax_save_answer']);
        add_action('wp_ajax_nopriv_pq_save_answer',[__CLASS__, 'ajax_save_answer']);

        add_action('wp_ajax_pq_finish',      [__CLASS__, 'ajax_finish']);
        add_action('wp_ajax_nopriv_pq_finish',[__CLASS__, 'ajax_finish']);

        // Front assets minimal (فونت و چند استایل)
        add_action('wp_enqueue_scripts', [__CLASS__, 'front_assets']);
    }

    /* ----------------------- Utilities ----------------------- */

    private static function digits_to_en($str){
        // تبدیل ارقام فارسی/عربی به انگلیسی
        $fa = ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'];
        $ar = ['٠','١','٢','٣','٤','٥','٦','٧','٨','٩'];
        $en = ['0','1','2','3','4','5','6','7','8','9'];
        $str = str_replace($fa, $en, $str);
        $str = str_replace($ar, $en, $str);
        return $str;
    }

    private static function sanitize_mobile($mobile){
        $mobile = trim((string)$mobile);
        $mobile = self::digits_to_en($mobile);
        $mobile = preg_replace('/\s+|[^0-9]/u', '', $mobile);
        // حذف پیش‌شماره‌های رایج بین‌المللی اگر اشتباهاً چسبیده باشد (اختیاری)
        if (preg_match('/^(\\+?98)0?(\d{9})$/', $mobile, $m)) {
            $mobile = '0'.$m[2];
        }
        if (!preg_match('/^09\d{9}$/', $mobile)) return false;
        return $mobile;
    }

    private static function get_setting_url($key, $test_id=null){
        $opt = get_option('pq_settings', ['pages'=>[]]);
        $val = $opt['pages'][$key] ?? '';
        if (!$val){
            if ($key==='interpret'){
                $val = '/app/results_testid={id}';
            } elseif ($key==='test') {
                $val = '/app/test';
            } elseif ($key==='profile') {
                $val = '/account';
            } else {
                $val = '/';
            }
        }
        if ($test_id!==null){
            $val = str_replace('{id}', intval($test_id), $val);
        }
        return site_url(ltrim($val,'/'));
    }

    private static function get_cookie_name($test_id){
        return 'pq_cache_'.intval($test_id);
    }

    private static function now_mysql(){
        return current_time('mysql');
    }

    private static function get_questions($test_id){
        global $wpdb; $p=$wpdb->prefix;
        $qs = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$p}pq_questions WHERE test_id=%d AND is_deleted=0 ORDER BY sort_order, id", $test_id
        ));
        if (!$qs) return [];

        $out = [];
        foreach($qs as $q){
            $ans = $wpdb->get_results($wpdb->prepare(
                "SELECT id,label,scores_json,sort_order FROM {$p}pq_answers WHERE question_id=%d ORDER BY sort_order, id", $q->id
            ));
            $a = [];
            foreach($ans as $r){
                $a[] = [
                    'id' => intval($r->id),
                    'label' => $r->label,
                    'scores' => json_decode($r->scores_json, true) ?: []
                ];
            }
            $out[] = [
                'id'=> intval($q->id),
                'title'=> $q->title,
                'hint'=> $q->hint,
                'special_hint'=> $q->special_hint,
                'media_id'=> $q->media_id ? intval($q->media_id) : 0,
                'answers'=> $a
            ];
        }
        return $out;
    }

    private static function make_attempt($test_id, $user_id=null, $ctx=[]){
        global $wpdb; $p=$wpdb->prefix;

        $token = wp_generate_password(20, false);
        $wpdb->insert("{$p}pq_attempts", [
            'user_id' => $user_id ?: null,
            'test_id' => $test_id,
            'status'  => 'in_progress',
            'ctx_json'=> wp_json_encode($ctx, JSON_UNESCAPED_UNICODE),
            'started_at'=> self::now_mysql(),
            'finished_at'=> null,
            'is_soft_deleted'=> 0,
            'cache_token'=> $user_id ? null : $token
        ]);
        $id = intval($wpdb->insert_id);

        // کش 24 ساعته
        if (!$user_id){
            setcookie(self::get_cookie_name($test_id), $token, time()+24*3600, COOKIEPATH ?: '/', COOKIE_DOMAIN ?: '', is_ssl(), true);
        }
        return $id;
    }

    private static function find_attempt($test_id){
        global $wpdb; $p=$wpdb->prefix;
        $uid = get_current_user_id();

        if ($uid){
            $id = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$p}pq_attempts WHERE user_id=%d AND test_id=%d AND status='in_progress' ORDER BY id DESC LIMIT 1",
                $uid, $test_id
            ));
            if ($id) return intval($id);
        }
        $token = $_COOKIE[self::get_cookie_name($test_id)] ?? '';
        if ($token){
            $id = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$p}pq_attempts WHERE user_id IS NULL AND test_id=%d AND status='in_progress' AND cache_token=%s ORDER BY id DESC LIMIT 1",
                $test_id, $token
            ));
            if ($id) return intval($id);
        }
        return 0;
    }

    private static function clear_attempt_cache($test_id){
        // حذف کوکی کش مهمان
        if (isset($_COOKIE[self::get_cookie_name($test_id)])){
            setcookie(self::get_cookie_name($test_id), '', time()-3600, COOKIEPATH ?: '/', COOKIE_DOMAIN ?: '', is_ssl(), true);
        }
    }

    private static function img_html($id, $size='medium_large'){
        if (!$id) return '';
        $src = wp_get_attachment_image_src($id, $size);
        if (!$src) return '';
        // نمایش 600x800 (max-width)
        return '<img src="'.esc_url($src[0]).'" alt="" style="max-width:600px; width:100%; aspect-ratio:3/4; object-fit:cover; border-radius:10px; display:block; margin:10px 0;">';
    }

    /* ----------------------- Shortcodes ----------------------- */

    public static function sc_test($atts){
        $a = shortcode_atts(['id'=>0], $atts, 'pq_test');
        $test_id = intval($a['id']);
        if (!$test_id) return '<div class="pq-msg">Test ID نامعتبر است.</div>';

        // Front styles (فونت Vazirmatn + فاصله‌ها)
        wp_enqueue_style('pq-front-inline', 'data:text/css,'.rawurlencode(
            '@import url("https://fonts.googleapis.com/css2?family=Vazirmatn:wght@400;700&display=swap");'.
            '.pq-wrap,*{font-family:Vazirmatn,sans-serif}.pq-wrap{max-width:720px;margin:14px auto;padding:10px}'.
            '.pq-row{margin:10px 0}'.
            '.pq-grid{display:grid;gap:10px;grid-template-columns:1fr}'.
            '.pq-btns{display:flex;gap:10px;flex-wrap:wrap;margin-top:12px}'.
            '.pq-btn{background:#2563eb;color:#fff;border:none;border-radius:8px;padding:10px 14px;cursor:pointer}'.
            '.pq-btn.secondary{background:#111827}'.
            '.pq-btn.warn{background:#ef4444}'.
            '.pq-card{border:1px solid #e5e7eb;border-radius:12px;padding:14px;margin:12px 0;background:#fff}'.
            '.pq-h1{font-weight:700;font-size:20px;margin:6px 0 10px}'.
            '.pq-h2{font-weight:700;font-size:16px;margin:0 0 6px}'.
            '.pq-help{color:#6b7280;font-size:13px}'.
            '.pq-field{display:flex;flex-direction:column;gap:6px}'.
            '.pq-field input,.pq-field select,.pq-field textarea{padding:10px;border:1px solid #e5e7eb;border-radius:8px}'.
            '.pq-err{color:#ef4444;font-size:13px;margin-top:4px}'.
            '.pq-q-media{margin-top:8px}'.
            '.pq-answers{display:grid;gap:8px}'.
            '.pq-answers label{display:flex;gap:8px;align-items:flex-start;border:1px solid #e5e7eb;border-radius:8px;padding:10px;cursor:pointer}'.
            '.pq-nav{display:flex;gap:10px;justify-content:space-between;margin-top:12px}'.
            '.pq-hide{display:none!important}'
        ), [], PQ_VER);

        // آبجکت جاوااسکریپت
        wp_enqueue_script('jquery');
        wp_add_inline_script('jquery', '(function($){window.PQF='.wp_json_encode([
            'ajax'=> admin_url('admin-ajax.php'),
            'nonce'=> wp_create_nonce('pq-front'),
            'test_id'=> $test_id,
            'resume_url'=> self::get_setting_url('test', $test_id),
            'result_url'=> self::get_setting_url('interpret', $test_id),
            'user'=> [
                'id'=> get_current_user_id(),
                'first_name'=> get_user_meta(get_current_user_id(), 'first_name', true),
                'last_name'=> get_user_meta(get_current_user_id(), 'last_name', true),
                'email'=> wp_get_current_user()->user_email ?? '',
                'mobile'=> get_user_meta(get_current_user_id(), 'mobile', true),
                'gender'=> get_user_meta(get_current_user_id(), 'gender', true),
                'age'=> intval(get_user_meta(get_current_user_id(), 'age', true))
            ]
        ], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).';})(jQuery);');

        ob_start();
        ?>
        <div class="pq-wrap" id="pq-app" data-test="<?php echo esc_attr($test_id); ?>">
            <div class="pq-card" id="pq-step-0">
                <div class="pq-h1">آغاز آزمون</div>
                <p class="pq-help">اگر آزمون نیمه‌تمامی دارید، می‌توانید ادامه دهید یا آزمون جدید شروع کنید.</p>
                <div class="pq-btns">
                    <button class="pq-btn" id="pq-btn-check-resume">بررسی ادامه آزمون قبلی</button>
                    <button class="pq-btn secondary" id="pq-btn-go-info">ورود به فرم اطلاعات</button>
                </div>
                <div id="pq-resume-box" class="pq-row pq-hide">
                    <div class="pq-h2">آزمون ذخیره‌شده یافت شد</div>
                    <div class="pq-btns">
                        <button class="pq-btn" id="pq-btn-resume">ادامه آزمون قبلی</button>
                        <button class="pq-btn warn" id="pq-btn-reset">آزمون جدید و حذف دیتای ذخیره شده</button>
                    </div>
                </div>
                <div id="pq-noresume-box" class="pq-row pq-hide">
                    <div class="pq-h2">آزمون ذخیره‌شده‌ای یافت نشد</div>
                </div>
            </div>

            <div class="pq-card pq-hide" id="pq-step-1">
                <div class="pq-h1">اطلاعات اولیه</div>
                <div class="pq-grid">

                    <div class="pq-field">
                        <label>نام</label>
                        <input type="text" id="pq-first" placeholder="فقط حروف" value="">
                        <div class="pq-err" id="err-first"></div>
                    </div>
                    <div class="pq-field">
                        <label>نام خانوادگی</label>
                        <input type="text" id="pq-last" placeholder="فقط حروف" value="">
                        <div class="pq-err" id="err-last"></div>
                    </div>

                    <div class="pq-field">
                        <label>ایمیل</label>
                        <input type="email" id="pq-email" placeholder="example@mail.com" value="">
                        <div class="pq-err" id="err-email"></div>
                    </div>

                    <div class="pq-field">
                        <label>شماره تماس</label>
                        <input type="tel" id="pq-mobile" placeholder="09xxxxxxxxx" value="">
                        <div class="pq-err" id="err-mobile"></div>
                    </div>

                    <div class="pq-field">
                        <label>سن</label>
                        <select id="pq-age">
                            <option value="">انتخاب کنید</option>
                            <?php for($i=1;$i<=130;$i++): ?>
                                <option value="<?php echo $i; ?>"><?php echo $i; ?></option>
                            <?php endfor; ?>
                        </select>
                        <div class="pq-err" id="err-age"></div>
                    </div>

                    <div class="pq-field">
                        <label>جنسیت</label>
                        <select id="pq-gender">
                            <option value="">انتخاب کنید</option>
                            <option value="male">مرد</option>
                            <option value="female">زن</option>
                        </select>
                        <div class="pq-err" id="err-gender"></div>
                    </div>

                    <!-- فیلدهای مرحله دوم (در همان ابتدای شروع دریافت می‌شوند و اجباری‌اند به‌جز like/hate) -->
                    <div class="pq-field">
                        <label>فصل مورد نظر</label>
                        <select id="pq-season">
                            <option value="">انتخاب کنید</option>
                            <option>بهار</option><option>تابستان</option><option>پاییز</option><option>زمستان</option>
                        </select>
                        <div class="pq-err" id="err-season"></div>
                    </div>

                    <div class="pq-field">
                        <label>موقعیت استفاده</label>
                        <select id="pq-context">
                            <option value="">انتخاب کنید</option>
                            <option>رسمی</option><option>کژوال</option><option>کاری</option><option>باشگاه</option>
                            <option>دیت</option><option>جلسه کاری</option><option>مجلس</option><option>دانشگاه و مدرسه</option>
                        </select>
                        <div class="pq-err" id="err-context"></div>
                    </div>

                    <div class="pq-field">
                        <label>حوزه کاری</label>
                        <input type="text" id="pq-job" placeholder="اداری / دانشجو ...">
                        <div class="pq-err" id="err-job"></div>
                    </div>

                    <div class="pq-field">
                        <label>هدف استفاده از عطر</label>
                        <input type="text" id="pq-goal" placeholder="افزایش توجه / حس خنکی / ...">
                        <div class="pq-err" id="err-goal"></div>
                    </div>

                    <div class="pq-field">
                        <label>مذهبی هستید؟</label>
                        <select id="pq-relig">
                            <option value="">انتخاب کنید</option>
                            <option value="yes">بله</option>
                            <option value="no">خیر</option>
                        </select>
                        <div class="pq-err" id="err-relig"></div>
                    </div>

                    <div class="pq-field">
                        <label>عطر مورد علاقه (اختیاری - ID محصول ووکامرس)</label>
                        <input type="text" id="pq-like" placeholder="مثلاً 123">
                    </div>

                    <div class="pq-field">
                        <label>عطر مورد انزجار (اختیاری - ID محصول ووکامرس)</label>
                        <input type="text" id="pq-hate" placeholder="مثلاً 456">
                    </div>
                </div>
                <div class="pq-btns">
                    <button class="pq-btn" id="pq-btn-start">شروع آزمون</button>
                </div>
                <div class="pq-row pq-help" id="pq-idn-msg"></div>
            </div>

            <div class="pq-card pq-hide" id="pq-step-2">
                <div id="pq-test-media"></div>
                <div id="pq-q-box"></div>
                <div class="pq-nav">
                    <button class="pq-btn secondary" id="pq-prev">قبلی</button>
                    <button class="pq-btn" id="pq-next">بعدی</button>
                    <button class="pq-btn warn pq-hide" id="pq-finish">اتمام آزمون</button>
                </div>
            </div>
        </div>

        <script>
        (function($){
          'use strict';

          // Helpers
          function digitsToEn(s){
            if(!s) return s;
            var fa='۰۱۲۳۴۵۶۷۸۹', ar='٠١٢٣٤٥٦٧٨٩';
            return String(s).replace(/[۰-۹]/g, d=> String(fa.indexOf(d))).replace(/[٠-٩]/g, d=> String(ar.indexOf(d)));
          }
          function normMobile(s){
            s = digitsToEn(String(s||'')).replace(/\s+|[^0-9]/g,'');
            var m = s.match(/^(\+?98)0?(\d{9})$/); if(m) s = '0'+m[2];
            return s;
          }
          function validMobile(s){
            s = normMobile(s);
            return /^09\d{9}$/.test(s);
          }

          // State
          let TEST_ID  = parseInt($('#pq-app').data('test'),10);
          let ATTEMPT  = 0;
          let QLIST    = [];   // {id,title,hint,special_hint,media_id,answers[{id,label,scores}]}
          let QINDEX   = 0;
          let MEDIA_ID = 0;

          // Prefill from user
          (function prefill(){
            if(!window.PQF||!PQF.user) return;
            $('#pq-first').val(PQF.user.first_name||'');
            $('#pq-last').val(PQF.user.last_name||'');
            $('#pq-email').val(PQF.user.email||'');
            const mb = digitsToEn(PQF.user.mobile||''); $('#pq-mobile').val(mb);
            if(PQF.user.age) $('#pq-age').val(String(PQF.user.age));
            if(PQF.user.gender) $('#pq-gender').val(PQF.user.gender);
          })();

          // Step 0: resume check
          $('#pq-btn-check-resume').on('click', function(){
            $.post(PQF.ajax, {action:'pq_check_resume', _ajax_nonce:PQF.nonce, test_id:TEST_ID}, function(res){
              if(!res||!res.success){ $('#pq-noresume-box').removeClass('pq-hide'); return;}
              if(res.data && res.data.has){ ATTEMPT = res.data.attempt_id; $('#pq-resume-box').removeClass('pq-hide'); }
              else { $('#pq-noresume-box').removeClass('pq-hide'); }
            });
          });
          $('#pq-btn-go-info').on('click', function(){
            $('#pq-step-0').addClass('pq-hide'); $('#pq-step-1').removeClass('pq-hide');

            // Prefetch questions while filling info
            $.post(PQF.ajax, {action:'pq_prefetch', _ajax_nonce:PQF.nonce, test_id:TEST_ID}, function(res){
              if(res&&res.success){
                QLIST = res.data.questions||[];
                MEDIA_ID = res.data.test_media_id||0;
              }
            });
          });
          $('#pq-btn-resume').on('click', function(){
            if(!ATTEMPT) return;
            // Move to questions and load
            $('#pq-step-0').addClass('pq-hide');
            startQuestions(true);
          });
          $('#pq-btn-reset').on('click', function(){
            // Reset attempt cookie server-side when new attempt starts; اینجا فقط جلو می‌رویم
            $('#pq-step-0').addClass('pq-hide'); $('#pq-step-1').removeClass('pq-hide');
          });

          // Identity & start
          $('#pq-btn-start').on('click', function(){
            // validate
            let ok=true;
            const first=$('#pq-first').val().trim(),
                  last =$('#pq-last').val().trim(),
                  email=$('#pq-email').val().trim(),
                  mobile=normMobile($('#pq-mobile').val()),
                  age  =$('#pq-age').val(),
                  gender=$('#pq-gender').val(),
                  season=$('#pq-season').val(),
                  context=$('#pq-context').val(),
                  job=$('#pq-job').val().trim(),
                  goal=$('#pq-goal').val().trim(),
                  relig=$('#pq-relig').val(),
                  like=$('#pq-like').val().trim(),
                  hate=$('#pq-hate').val().trim();

            $('.pq-err').text('');
            if(!first){ ok=false; $('#err-first').text('نام الزامی است'); }
            if(!last){ ok=false; $('#err-last').text('نام خانوادگی الزامی است'); }
            if(email && !/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(email)){ ok=false; $('#err-email').text('ایمیل نامعتبر'); }
            if(!validMobile(mobile)){ ok=false; $('#err-mobile').text('شماره موبایل معتبر نیست (11 رقم و با 09 شروع شود).'); }
            if(!age){ ok=false; $('#err-age').text('سن را انتخاب کنید'); }
            if(!gender){ ok=false; $('#err-gender').text('جنسیت را انتخاب کنید'); }
            if(!season){ ok=false; $('#err-season').text('فصل را انتخاب کنید'); }
            if(!context){ ok=false; $('#err-context').text('موقعیت را انتخاب کنید'); }
            if(!job){ ok=false; $('#err-job').text('حوزه کاری را پر کنید'); }
            if(!goal){ ok=false; $('#err-goal').text('هدف را پر کنید'); }
            if(!relig){ ok=false; $('#err-relig').text('گزینه مذهبی را انتخاب کنید'); }
            if(!ok) return;

            $('#pq-idn-msg').text('در حال بررسی هویت و شروع آزمون...');

            $.post(PQF.ajax, {
              action:'pq_check_identity', _ajax_nonce:PQF.nonce, test_id:TEST_ID,
              first:first, last:last, email:email, mobile:mobile,
              age:age, gender:gender, season:season, context:context, job:job, goal:goal, relig:relig,
              like:like, hate:hate
            }, function(res){
              if(!res || !res.success){
                $('#pq-idn-msg').html(res && res.data && res.data.msg ? res.data.msg : 'خطایی رخ داد.');
                return;
              }
              if(res.data.need_login){
                $('#pq-idn-msg').html('این ایمیل یا موبایل قبلاً ثبت شده است. لطفاً وارد شوید. <a href="'+res.data.login_url+'">رفتن به لاگین</a>');
                return;
              }
              ATTEMPT = res.data.attempt_id;
              $('#pq-step-1').addClass('pq-hide');
              startQuestions(false);
            });
          });

          function renderQuestion(){
            if(!QLIST.length){ $('#pq-q-box').html('<div class="pq-help">سوالی برای این آزمون ثبت نشده است.</div>'); return; }
            const q=QLIST[QINDEX]||{};
            let html='<div class="pq-h1">سوال '+(QINDEX+1)+' از '+QLIST.length+'</div>';
            html+='<div class="pq-h2">'+(q.title||'')+'</div>';
            if(q.hint) html+='<div class="pq-help">'+q.hint+'</div>';
            if(q.special_hint) html+='<div class="pq-help">'+q.special_hint+'</div>';
            if(q.media_id){ html+='<div class="pq-q-media"><?php echo esc_js(self::img_html('__MID__')); ?></div>'.replace('__MID__', q.media_id); }
            html+='<div class="pq-answers">';
            (q.answers||[]).forEach(function(a){
              html+='<label><input type="radio" name="pq-ans" value="'+a.id+'"><span>'+a.label+'</span></label>';
            });
            html+='</div>';
            $('#pq-q-box').html(html);

            // Nav buttons visibility
            $('#pq-prev').prop('disabled', QINDEX===0);
            if(QINDEX===QLIST.length-1){
              $('#pq-next').addClass('pq-hide');
              $('#pq-finish').removeClass('pq-hide');
            }else{
              $('#pq-next').removeClass('pq-hide');
              $('#pq-finish').addClass('pq-hide');
            }
          }

          function startQuestions(isResume){
            // media
            if(MEDIA_ID){
              $('#pq-test-media').html('<?php echo esc_js(self::img_html('__MID__')); ?>'.replace('__MID__', MEDIA_ID));
            }
            $('#pq-step-2').removeClass('pq-hide');
            if(!QLIST.length){
              // در صورت Prefetch نشدن، حالا دریافت کن
              $.post(PQF.ajax, {action:'pq_prefetch', _ajax_nonce:PQF.nonce, test_id:TEST_ID}, function(res){
                if(res&&res.success){ QLIST=res.data.questions||[]; MEDIA_ID=res.data.test_media_id||0; renderQuestion(); }
                else { $('#pq-q-box').html('<div class="pq-help">عدم توانایی در بارگیری سوالات.</div>'); }
              });
            } else {
              renderQuestion();
            }
          }

          function selectedAnswer(){
            return $('input[name="pq-ans"]:checked').val()||'';
          }

          $('#pq-next').on('click', function(){
            const ans = selectedAnswer();
            if(!ans){ alert('لطفاً یک پاسخ انتخاب کنید'); return; }
            $.post(PQF.ajax, {action:'pq_save_answer', _ajax_nonce:PQF.nonce, attempt_id:ATTEMPT, qid:QLIST[QINDEX].id, aid:ans}, function(){
              QINDEX = Math.min(QINDEX+1, QLIST.length-1);
              renderQuestion();
            });
          });

          $('#pq-prev').on('click', function(){
            QINDEX = Math.max(QINDEX-1, 0);
            renderQuestion();
          });

          $('#pq-finish').on('click', function(){
            const ans = selectedAnswer();
            if(!ans){ alert('لطفاً یک پاسخ انتخاب کنید'); return; }
            $.post(PQF.ajax, {action:'pq_save_answer', _ajax_nonce:PQF.nonce, attempt_id:ATTEMPT, qid:QLIST[QINDEX].id, aid:ans}, function(){
              $.post(PQF.ajax, {action:'pq_finish', _ajax_nonce:PQF.nonce, attempt_id:ATTEMPT}, function(res){
                 if(res&&res.success && res.data && res.data.redirect){
                    window.location.href = res.data.redirect;
                 } else {
                    alert('خطا در اتمام آزمون');
                 }
              });
            });
          });

        })(jQuery);
        </script>
        <?php
        return ob_get_clean();
    }

    public static function sc_results($atts){
        $a = shortcode_atts(['id'=>0], $atts, 'pq_test_results');
        $test_id = intval($a['id']);
        if (!$test_id) return '<div class="pq-wrap">Test ID نامعتبر است.</div>';

        // آخرین تلاش تمام‌شدهٔ کاربر در این تست
        global $wpdb; $p=$wpdb->prefix;
        $uid = get_current_user_id();

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT r.*, a.ctx_json FROM {$p}pq_results r
             JOIN {$p}pq_attempts a ON a.id=r.attempt_id
             WHERE a.test_id=%d AND a.status='done' AND a.user_id %s
             ORDER BY r.created_at DESC LIMIT 1",
             $test_id, $uid ? $wpdb->prepare('= %d', $uid) : 'IS NULL'
        ));

        // اگر کاربر مهمان است و attempt با کوکی هم باشد
        if (!$row){
            $token = $_COOKIE[self::get_cookie_name($test_id)] ?? '';
            if ($token){
                $row = $wpdb->get_row($wpdb->prepare(
                    "SELECT r.*, a.ctx_json FROM {$p}pq_results r
                     JOIN {$p}pq_attempts a ON a.id=r.attempt_id
                     WHERE a.test_id=%d AND a.status='done' AND a.cache_token=%s
                     ORDER BY r.created_at DESC LIMIT 1", $test_id, $token
                ));
            }
        }

        if (!$row){
            return '<div class="pq-wrap"><div class="pq-card">نتیجه‌ای برای نمایش یافت نشد.</div></div>';
        }

        // یافتن تفسیر
        $def = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}pq_result_defs WHERE final_code=%s", $row->final_code));
        $content = $def ? json_decode($def->content_json, true) : null;

        ob_start();
        ?>
        <style>
        @import url('https://fonts.googleapis.com/css2?family=Vazirmatn:wght@400;700&display=swap');
        .pq-wrap,*{font-family:Vazirmatn,sans-serif}
        .pq-wrap{max-width:800px;margin:14px auto;padding:10px}
        .pq-card{border:1px solid #e5e7eb;background:#fff;border-radius:12px;padding:16px;margin:10px 0}
        .pq-h1{font-weight:700;font-size:22px;margin:0 0 8px}
        .pq-h2{font-weight:700;font-size:16px;margin:12px 0 6px}
        .pq-p{margin:0 0 8px;line-height:1.9}
        .pq-gap{height:20px}
        .pq-table{width:100%;border-collapse:collapse;margin:10px 0}
        .pq-table th,.pq-table td{border:1px solid #e5e7eb;padding:8px;text-align:center}
        .pq-prod{display:grid;gap:10px;grid-template-columns:repeat(auto-fill,minmax(180px,1fr))}
        .pq-badge{background:#111827;color:#fff;border-radius:999px;padding:2px 10px;display:inline-block}
        </style>
        <div class="pq-wrap">
            <div class="pq-card">
                <div class="pq-h1"><?php echo esc_html($content['h1'] ?? 'نتیجه آزمون'); ?></div>
                <div class="pq-p"><span class="pq-badge"><?php echo esc_html($row->final_code); ?></span></div>
                <?php if (!empty($content['intro'])): ?>
                    <div class="pq-p"><?php echo wp_kses_post(nl2br($content['intro'])); ?></div>
                <?php endif; ?>
                <div class="pq-gap"></div>

                <?php
                if (!empty($content['sections']) && is_array($content['sections'])){
                    foreach($content['sections'] as $sec){
                        if (!empty($sec['h'])) echo '<div class="pq-h2">'.esc_html($sec['h']).'</div>';
                        if (!empty($sec['p'])) echo '<div class="pq-p">'.wp_kses_post(nl2br($sec['p'])).'</div>';
                        if (!empty($sec['img'])) echo '<div class="pq-p"><img src="'.esc_url($sec['img']).'" style="max-width:100%;border-radius:10px"></div>';
                        if (!empty($sec['type']) && $sec['type']==='table' && !empty($sec['cols']) && !empty($sec['rows'])){
                            echo '<table class="pq-table"><thead><tr>';
                            foreach($sec['cols'] as $c) echo '<th>'.esc_html($c).'</th>';
                            echo '</tr></thead><tbody>';
                            foreach($sec['rows'] as $r){
                                echo '<tr>'; foreach($r as $cell) echo '<td>'.wp_kses_post($cell).'</td>'; echo '</tr>';
                            }
                            echo '</tbody></table>';
                        }
                        echo '<div class="pq-gap"></div>';
                    }
                }
                ?>

                <?php
                // محصولات پیشنهادی
                if (!empty($content['products']) && function_exists('wc_get_product')){
                    $pids = array_filter([(int)($content['products']['p1']??0),(int)($content['products']['p2']??0),(int)($content['products']['p3']??0)]);
                    if ($pids){
                        echo '<div class="pq-h2">پیشنهادهای مرتبط</div><div class="pq-prod">';
                        foreach($pids as $pid){
                            $prod = wc_get_product($pid);
                            if(!$prod) continue;
                            $url = get_permalink($pid);
                            $img = get_the_post_thumbnail_url($pid, 'medium') ?: wc_placeholder_img_src();
                            echo '<a href="'.esc_url($url).'" class="pq-card" style="text-decoration:none;color:inherit">';
                            echo '<img src="'.esc_url($img).'" style="width:100%;aspect-ratio:1/1;object-fit:cover;border-radius:8px;margin-bottom:8px">';
                            echo '<div>'.esc_html($prod->get_name()).'</div>';
                            echo '</a>';
                        }
                        echo '</div>';
                    }
                }
                ?>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    public static function sc_profile($atts){
        if (!is_user_logged_in()){
            return '<div class="pq-wrap"><div class="pq-card">برای مشاهده نتایج، لطفاً وارد شوید.</div></div>';
        }
        global $wpdb; $p=$wpdb->prefix;
        $uid = get_current_user_id();

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT r.*, a.test_id, a.started_at FROM {$p}pq_results r
             JOIN {$p}pq_attempts a ON a.id=r.attempt_id
             WHERE a.user_id=%d AND a.status='done'
             ORDER BY r.created_at DESC", $uid
        ));
        if (!$rows) return '<div class="pq-wrap"><div class="pq-card">نتیجه‌ای ثبت نشده است.</div></div>';

        $out = '<style>@import url("https://fonts.googleapis.com/css2?family=Vazirmatn:wght@400;700&display=swap");.pq-wrap,*{font-family:Vazirmatn,sans-serif}.pq-wrap{max-width:900px;margin:14px auto;padding:10px}.pq-card{border:1px solid #e5e7eb;background:#fff;border-radius:12px;padding:16px;margin:10px 0}.pq-row{display:flex;gap:10px;align-items:center;justify-content:space-between;flex-wrap:wrap}.pq-badge{background:#111827;color:#fff;border-radius:999px;padding:2px 10px}</style>';
        $out .= '<div class="pq-wrap"><div class="pq-card"><div class="pq-h1">نتایج آزمون‌های من</div>';
        foreach($rows as $r){
            $res_url = self::get_setting_url('interpret', $r->test_id);
            $out .= '<div class="pq-row"><div>'.esc_html(mysql2date('Y-m-d H:i',$r->created_at)).' — <span class="pq-badge">'.esc_html($r->final_code).'</span></div>';
            $out .= '<div><a class="pq-btn" style="background:#2563eb;padding:6px 10px;color:#fff;border-radius:8px;text-decoration:none" href="'.esc_url($res_url).'">نمایش تفسیر</a></div></div>';
        }
        $out .= '</div></div>';
        return $out;
    }

    /* ----------------------- AJAX handlers ----------------------- */

    public static function ajax_check_resume(){
        check_ajax_referer('pq-front');
        $test_id = intval($_POST['test_id'] ?? 0);
        if (!$test_id) wp_send_json_error();
        $att = self::find_attempt($test_id);
        wp_send_json_success(['has'=> $att>0, 'attempt_id'=>$att]);
    }

    public static function ajax_check_identity(){
        check_ajax_referer('pq-front');
        global $wpdb; $p=$wpdb->prefix;

        $test_id = intval($_POST['test_id'] ?? 0);
        if (!$test_id) wp_send_json_error(['msg'=>'Test ID invalid']);

        $first = sanitize_text_field($_POST['first'] ?? '');
        $last  = sanitize_text_field($_POST['last'] ?? '');
        $email = sanitize_email($_POST['email'] ?? '');
        $mobile= self::sanitize_mobile($_POST['mobile'] ?? '');
        $age   = intval($_POST['age'] ?? 0);
        $gender= sanitize_text_field($_POST['gender'] ?? '');

        $season= sanitize_text_field($_POST['season'] ?? '');
        $context= sanitize_text_field($_POST['context'] ?? '');
        $job   = sanitize_text_field($_POST['job'] ?? '');
        $goal  = sanitize_text_field($_POST['goal'] ?? '');
        $relig = sanitize_text_field($_POST['relig'] ?? '');
        $like  = sanitize_text_field($_POST['like'] ?? '');
        $hate  = sanitize_text_field($_POST['hate'] ?? '');

        if (!$mobile){ wp_send_json_error(['msg'=>'شماره موبایل معتبر نیست (11 رقم و با 09 شروع شود).']); }

        // اگر کاربر لاگین نیست و ایمیل/موبایل مربوط به یوزر دیگری است => مجبور به لاگین
        if (!is_user_logged_in()){
            if ($email){
                $u = get_user_by('email', $email);
                if ($u) wp_send_json_success(['need_login'=>true, 'login_url'=> wp_login_url()]);
            }
            if ($mobile){
                $uid_by_mobile = $wpdb->get_var($wpdb->prepare(
                    "SELECT user_id FROM {$p}usermeta WHERE meta_key='mobile' AND meta_value=%s LIMIT 1", $mobile
                ));
                if ($uid_by_mobile) wp_send_json_success(['need_login'=>true, 'login_url'=> wp_login_url()]);
            }
        }

        // ساخت attempt + ذخیرهٔ ctx
        $ctx = [
            'first'=>$first,'last'=>$last,'email'=>$email,'mobile'=>$mobile,'age'=>$age,'gender'=>$gender,
            'season'=>$season,'context'=>$context,'job'=>$job,'goal'=>$goal,'relig'=>$relig,
            'like'=>$like,'hate'=>$hate
        ];
        $attempt_id = self::find_attempt($test_id);
        if (!$attempt_id){
            $attempt_id = self::make_attempt($test_id, get_current_user_id() ?: null, $ctx);
        } else {
            $wpdb->update("{$p}pq_attempts", ['ctx_json'=> wp_json_encode($ctx, JSON_UNESCAPED_UNICODE)], ['id'=>$attempt_id]);
        }

        // اگر کاربر لاگین است، پروفایل را به‌روز کنیم (ملک ذخیره پروفایل آخرین آزمون)
        if (is_user_logged_in()){
            $uid = get_current_user_id();
            update_user_meta($uid,'first_name',$first);
            update_user_meta($uid,'last_name',$last);
            if ($mobile) update_user_meta($uid,'mobile',$mobile);
            if ($gender) update_user_meta($uid,'gender',$gender);
            if ($age) update_user_meta($uid,'age',$age);
        }

        wp_send_json_success(['need_login'=>false,'attempt_id'=>$attempt_id]);
    }

    public static function ajax_prefetch(){
        check_ajax_referer('pq-front');
        global $wpdb; $p=$wpdb->prefix;

        $test_id = intval($_POST['test_id'] ?? 0);
        if (!$test_id) wp_send_json_error();

        $questions = self::get_questions($test_id);
        $media_id = intval($wpdb->get_var($wpdb->prepare("SELECT media_id FROM {$p}pq_tests WHERE id=%d", $test_id)));

        wp_send_json_success(['questions'=>$questions, 'test_media_id'=>$media_id]);
    }

    public static function ajax_save_answer(){
        check_ajax_referer('pq-front');
        global $wpdb; $p=$wpdb->prefix;

        $attempt_id = intval($_POST['attempt_id'] ?? 0);
        $qid = intval($_POST['qid'] ?? 0);
        $aid = intval($_POST['aid'] ?? 0);
        if (!$attempt_id || !$qid || !$aid) wp_send_json_error();

        // upsert
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT attempt_id FROM {$p}pq_attempt_answers WHERE attempt_id=%d AND question_id=%d", $attempt_id,$qid
        ));
        if ($exists){
            $wpdb->update("{$p}pq_attempt_answers", [
                'answer_id'=>$aid, 'answered_at'=> self::now_mysql()
            ], ['attempt_id'=>$attempt_id, 'question_id'=>$qid]);
        } else {
            $wpdb->insert("{$p}pq_attempt_answers", [
                'attempt_id'=>$attempt_id, 'question_id'=>$qid, 'answer_id'=>$aid, 'answered_at'=> self::now_mysql()
            ]);
        }
        wp_send_json_success();
    }

    public static function ajax_finish(){
        check_ajax_referer('pq-front');
        global $wpdb; $p=$wpdb->prefix;

        $attempt_id = intval($_POST['attempt_id'] ?? 0);
        if (!$attempt_id) wp_send_json_error(['msg'=>'attempt invalid']);

        $att = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}pq_attempts WHERE id=%d", $attempt_id));
        if (!$att) wp_send_json_error(['msg'=>'not found']);

        // گردآوری پاسخ‌ها
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT a.scores_json
             FROM {$p}pq_attempt_answers x
             JOIN {$p}pq_answers a ON a.id=x.answer_id
             WHERE x.attempt_id=%d", $attempt_id
        ));
        // جمع امتیازها
        $E = ['fe'=>0,'te'=>0,'se'=>0,'ne'=>0];
        $I = ['fi'=>0,'ti'=>0,'si'=>0,'ni'=>0];
        $C = ['a'=>0,'b'=>0,'c'=>0,'d'=>0,'e'=>0,'f'=>0,'g'=>0,'h'=>0,'i'=>0];

        foreach($rows as $r){
            $sc = json_decode($r->scores_json, true) ?: [];
            if (!empty($sc['e']['dim']) && isset($E[$sc['e']['dim']])) $E[$sc['e']['dim']] += floatval($sc['e']['score'] ?? 0);
            if (!empty($sc['i']['dim']) && isset($I[$sc['i']['dim']])) $I[$sc['i']['dim']] += floatval($sc['i']['score'] ?? 0);
            if (!empty($sc['c']['dim']) && isset($C[strtolower($sc['c']['dim'])])) $C[strtolower($sc['c']['dim'])] += floatval($sc['c']['score'] ?? 0);
        }

        // انتخاب پارامتر اول از بین همه (E∪I)
        $all = $E + $I; // union
        $param1 = self::max_key($all, ['fe','te','se','ne','fi','ti','si','ni']);
        // انتخاب پارامتر دوم طبق قوانین
        $pair = [];
        switch($param1){
            case 'fe': $pair = ['si','ni']; break;
            case 'te': $pair = ['si','ni']; break;
            case 'se': $pair = ['ti','fi']; break;
            case 'ne': $pair = ['ti','fi']; break;
            case 'fi': $pair = ['se','ne']; break;
            case 'ti': $pair = ['se','ne']; break;
            case 'si': $pair = ['te','fe']; break;
            case 'ni': $pair = ['te','fe']; break;
        }
        $pool2 = ['si'=>$I['si'],'ni'=>$I['ni'],'ti'=>$I['ti'],'fi'=>$I['fi'],'se'=>$E['se'],'ne'=>$E['ne'],'te'=>$E['te'],'fe'=>$E['fe']];
        $param2 = self::max_key(array_intersect_key($pool2, array_flip($pair)), $pair);

        // تعیین کد MBTI بر اساس نگاشت صریح
        $mbti = self::mbti_from($param1, $param2);

        // پارامتر سوم: بیشینه از C
        $param3 = strtoupper(self::max_key($C, ['a','b','c','d','e','f','g','h','i']));

        $final = $mbti.'-'.$param3;

        // ذخیره در pq_results (همراه با جمع‌ها)
        $wpdb->replace("{$p}pq_results", [
            'attempt_id'=>$attempt_id,
            'top1'=> strtoupper($param1),
            'top2'=> strtoupper($param2),
            'top3'=> $param3,
            'final_code'=> $final,

            'e_fe'=> round($E['fe'],2), 'e_te'=>round($E['te'],2), 'e_se'=>round($E['se'],2), 'e_ne'=>round($E['ne'],2),
            'i_fi'=> round($I['fi'],2), 'i_ti'=>round($I['ti'],2), 'i_si'=>round($I['si'],2), 'i_ni'=>round($I['ni'],2),
            'c_a'=> round($C['a'],2), 'c_b'=>round($C['b'],2), 'c_c'=>round($C['c'],2), 'c_d'=>round($C['d'],2), 'c_e'=>round($C['e'],2),
            'c_f'=> round($C['f'],2), 'c_g'=>round($C['g'],2), 'c_h'=>round($C['h'],2), 'c_i'=>round($C['i'],2),

            'created_at'=> self::now_mysql()
        ]);

        // بستن attempt
        $wpdb->update("{$p}pq_attempts", [
            'status'=>'done','finished_at'=> self::now_mysql()
        ], ['id'=>$attempt_id]);

        // حذف کش مهمان (آغاز آزمون جدید نیازمند گزینه بود)
        self::clear_attempt_cache($att->test_id);

        $redir = self::get_setting_url('interpret', $att->test_id);
        wp_send_json_success(['redirect'=>$redir]);
    }

    private static function max_key($arr, $priority_order){
        // بیشترین مقدار؛ در صورت تساوی، طبق priority_order
        $maxv = null; $key = null;
        foreach($arr as $k=>$v){
            if ($maxv===null || $v>$maxv || ($v===$maxv && array_search($k,$priority_order)!==false && array_search($k,$priority_order) < array_search($key,$priority_order))){
                $maxv = $v; $key = $k;
            }
        }
        return $key;
    }

    private static function mbti_from($p1, $p2){
        $p1 = strtolower($p1); $p2 = strtolower($p2);
        switch($p1){
            case 'fe': return ($p2==='si') ? 'ESFJ' : 'ENFJ';
            case 'te': return ($p2==='si') ? 'ESTJ' : 'ENTJ';
            case 'se': return ($p2==='ti') ? 'ESTP' : 'ESFP';
            case 'ne': return ($p2==='ti') ? 'ENTP' : 'ENFP';
            case 'fi': return ($p2==='se') ? 'ISFP' : 'INFP';
            case 'ti': return ($p2==='se') ? 'ISTP' : 'INTP';
            case 'si': return ($p2==='te') ? 'ISTJ' : 'ISFJ';
            case 'ni': return ($p2==='te') ? 'INTJ' : 'INFJ';
        }
        // fallback
        return 'XXXX';
    }

    public static function front_assets(){
        // Nothing global yet (همه CSS/JS لازم درون شورت‌کد اینلاین شده)
    }
}

