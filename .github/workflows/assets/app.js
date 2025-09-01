/* Perfume Quiz – Admin JS */
(function($){
  'use strict';

  function modalConfirm(message, onYes){
    let $m = $('#pq-confirm-modal');
    if (!$m.length){
      $m = $('<div class="pq-modal" id="pq-confirm-modal" hidden><div class="pq-modal-content"><div class="pq-h2">تأیید عملیات</div><p id="pq-confirm-text"></p><div class="pq-modal-actions"><button class="button" id="pq-confirm-no">انصراف</button><button class="button button-danger" id="pq-confirm-yes">تأیید</button></div></div></div>');
      $('body').append($m);
      $m.on('click', function(e){ if(e.target===this) $m.prop('hidden', true); });
      $m.find('#pq-confirm-no').on('click', function(){ $m.prop('hidden', true); });
    }
    $m.find('#pq-confirm-text').text(message||'آیا مطمئن هستید؟');
    $m.prop('hidden', false);
    $m.find('#pq-confirm-yes').off('click').on('click', function(){
      $m.prop('hidden', true);
      onYes && onYes();
    });
  }

  function isAdminPage(){
    const p = new URLSearchParams(window.location.search);
    return p.get('page')==='pq_admin';
  }

  // Copy code snippets
  function enableCopyable(){
    $(document).on('click','code.pq-copyable',function(){
      const t=$(this).text().trim();
      if (navigator.clipboard) navigator.clipboard.writeText(t);
      else { const ta=$('<textarea>').val(t).appendTo('body').select(); document.execCommand('copy'); ta.remove(); }
      const tip=$('<span class="pq-hint" style="margin-inline-start:8px;color:#059669">کپی شد!</span>');
      $(this).after(tip); setTimeout(()=>tip.fadeOut(200,()=>tip.remove()),1200);
    });
  }

  function bindTestsTab(){
    if (!isAdminPage() || !location.search.includes('tab=tests')) return;

    // Media picker for test
    $('#pq-test-media-btn').on('click', function(e){
      e.preventDefault();
      const frame = wp.media({ title:'انتخاب مدیا آزمون', multiple:false, library:{type:'image'} });
      frame.on('select', function(){
        const att = frame.state().get('selection').first().toJSON();
        $('#pq-test-media').val(att.id);
      });
      frame.open();
    });

    // Confirm delete/clone
    $(document).on('click','.pq-btn-del-test', function(e){
      e.preventDefault();
      const href = $(this).data('href');
      modalConfirm(PQADMIN.i18n.confirm_delete_test, ()=> location.href = href);
    });
    $(document).on('click','.pq-btn-clone-test', function(e){
      e.preventDefault();
      const href = $(this).data('href');
      modalConfirm(PQADMIN.i18n.confirm_clone_test, ()=> location.href = href);
    });
  }

  function bindQuestionsTab(){
    if (!isAdminPage() || !location.search.includes('tab=questions')) return;

    // Refresh on test change
    $('#pq-qs-test-select').on('change', function(){
      const id = $(this).val();
      const url = new URL(location.href);
      url.searchParams.set('test_id', id);
      location.href = url.toString();
    });

    // Media picker for question
    $('#pq-q-media-btn').on('click', function(e){
      e.preventDefault();
      const frame = wp.media({ title:'انتخاب مدیا سوال', multiple:false, library:{type:'image'} });
      frame.on('select', function(){
        const att = frame.state().get('selection').first().toJSON();
        $('#pq-q-media').val(att.id);
      });
      frame.open();
    });

    // Lock score when "(هیچ)" selected
    function toggleScore($sel, $num){
      if ($sel.val()==='-'){ $num.val('').prop('disabled', true).css('opacity', .6); }
      else { $num.prop('disabled', false).css('opacity', 1); }
    }
    $('.pq-dim-i').each(function(){ toggleScore($(this), $(this).closest('td').find('.pq-num-i')); });
    $('.pq-dim-e').each(function(){ toggleScore($(this), $(this).closest('td').find('.pq-num-e')); });
    $('.pq-dim-c').each(function(){ toggleScore($(this), $(this).closest('td').find('.pq-num-c')); });

    $(document).on('change','.pq-dim-i', function(){ toggleScore($(this), $(this).closest('td').find('.pq-num-i')); });
    $(document).on('change','.pq-dim-e', function(){ toggleScore($(this), $(this).closest('td').find('.pq-num-e')); });
    $(document).on('change','.pq-dim-c', function(){ toggleScore($(this), $(this).closest('td').find('.pq-num-c')); });

    // New question / clear
    $('#pq-q-new-btn').on('click', function(){
      modalConfirm('مطمئن هستید؟ فرم خالی می‌شود.', function(){
        const $form = $('form[action*="pq_question_save"]');
        $form[0].reset();
        // reset dims to '-'
        $form.find('.pq-dim').val('-').trigger('change');
        $('#pq-q-media').val('');
      });
    });

    // Confirm delete/clone on list
    $(document).on('click','.pq-btn-del-q', function(e){
      e.preventDefault(); const href = $(this).data('href');
      modalConfirm(PQADMIN.i18n.confirm_delete_q, ()=> location.href=href);
    });
    $(document).on('click','.pq-btn-clone-q', function(e){
      e.preventDefault(); const href = $(this).data('href');
      modalConfirm(PQADMIN.i18n.confirm_clone_q, ()=> location.href=href);
    });
  }

  function bindResultsTab(){
    if (!isAdminPage() || !location.search.includes('tab=results')) return;

    // bulk check
    $('#pq-bulk-all').on('change', function(){
      $('tbody input[type="checkbox"][name="ids[]"]').prop('checked', this.checked);
    });

    // Confirm delete single
    $(document).on('click','.pq-btn-del-res', function(e){
      e.preventDefault(); const href=$(this).data('href');
      modalConfirm(PQADMIN.i18n.confirm_delete_res, ()=> location.href = href);
    });

    // Confirm clone
    $(document).on('click','.pq-btn-clone-res', function(e){
      e.preventDefault(); const href=$(this).data('href');
      modalConfirm('کلون نتیجه ایجاد شود؟', ()=> location.href = href);
    });

    // Confirm bulk
    $('form[action*="pq_results_bulk"]').on('submit', function(e){
      const act = $(this).find('select[name="bulk_action"]').val();
      if (!act) { e.preventDefault(); alert('یک عملیات گروهی انتخاب کنید.'); return; }
      const checked = $(this).find('input[name="ids[]"]:checked').length;
      if (!checked) { e.preventDefault(); alert('هیچ آیتمی انتخاب نشده است.'); return; }
      e.preventDefault();
      modalConfirm(PQADMIN.i18n.bulk_confirm, ()=> this.submit());
    });
  }

  $(function(){
    if (!isAdminPage()) return;
    enableCopyable();
    bindTestsTab();
    bindQuestionsTab();
    bindResultsTab();
  });

})(jQuery);
