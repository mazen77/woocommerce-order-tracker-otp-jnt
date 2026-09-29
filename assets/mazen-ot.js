
(function($){
  function showMsg(text){ $('#mazen_ot_msg').text(text).show(); }
  function hideMsg(){ $('#mazen_ot_msg').hide().text(''); }
  let lastInput = '';

  async function post(action, data){
    return $.post(MAZEN_OT.ajax, Object.assign({action: action, nonce: MAZEN_OT.nonce}, data));
  }

  $('#mazen_ot_btn').on('click', async function(){
    hideMsg();
    const input = ($('#mazen_ot_input').val() || '').trim();
    if(!input){ showMsg('فضلاً أدخل رقم الطلب أو البريد أو الجوال.'); return; }
    lastInput = input;
    $(this).prop('disabled', true).text('جارٍ الإرسال...');
    try{
      const res = await post('mazen_ot_start', {input});
      if(res && res.ok){
        showMsg(res.message || 'تم إرسال الرمز.');
        $('#mazen_ot_otp_block').show();
        $('#mazen_ot_results').hide().html('');
      }else{
        showMsg((res && res.message) ? res.message : 'تعذّر الإرسال.');
      }
    }catch(e){
      showMsg('حدث خطأ أثناء الإرسال.');
    }finally{
      $(this).prop('disabled', false).text('إرسال رمز التحقق');
    }
  });

  $('#mazen_ot_resend').on('click', async function(){
    hideMsg();
    const input = (lastInput || ($('#mazen_ot_input').val() || '')).trim();
    if(!input){ showMsg('فضلاً أدخل المُعرف أولاً.'); return; }
    $(this).prop('disabled', true).text('جارٍ الإرسال...');
    try{
      const res = await post('mazen_ot_start', {input});
      showMsg(res && res.message ? res.message : 'تم.');
    }catch(e){
      showMsg('حدث خطأ أثناء الإرسال.');
    }finally{
      $(this).prop('disabled', false).text('إعادة إرسال');
    }
  });

  $('#mazen_ot_verify').on('click', async function(){
    hideMsg();
    const input = (lastInput || ($('#mazen_ot_input').val() || '')).trim();
    const otp = ($('#mazen_ot_otp').val() || '').trim();
    if(!input || !otp){ showMsg('أدخل المُعرف ورمز التحقق.'); return; }
    $(this).prop('disabled', true).text('جارٍ التحقق...');
    try{
      const res = await post('mazen_ot_verify', {input, otp});
      if(res && res.ok && res.token){
        showMsg(res.message || 'تم التحقق.');
        const fetched = await post('mazen_ot_fetch', {token: res.token});
        if(fetched && fetched.ok){
          $('#mazen_ot_results').html(fetched.html || '').show();
        }else{
          showMsg((fetched && fetched.message) ? fetched.message : 'تعذّر جلب الطلبات.');
        }
      }else{
        showMsg((res && res.message) ? res.message : 'رمز غير صحيح.');
      }
    }catch(e){
      showMsg('حدث خطأ أثناء التحقق.');
    }finally{
      $(this).prop('disabled', false).text('تحقق');
    }
  });
})(jQuery);
