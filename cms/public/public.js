(() => {
  'use strict';
  let config={};try{config=JSON.parse(document.getElementById('ursar-public-config')?.textContent||'{}')}catch{}
  const supported=form=>form instanceof HTMLFormElement&&(form.id==='stickyelements-form'||form.id.startsWith('srfm-form-'));
  const initForm=form=>{
    if(form.dataset.ursarReady)return;form.dataset.ursarReady='1';
    const consent=document.createElement('label');consent.className='ursar-consent';const box=document.createElement('input');box.type='checkbox';box.name='ursar_consent';box.required=true;const text=document.createElement('span');text.textContent=config.consentText||'Я согласен с политикой конфиденциальности и обработкой персональных данных.';const link=document.createElement('a');link.href='/privacy-policy-2/';link.target='_blank';link.rel='noopener';link.textContent='Политика конфиденциальности';text.append(' ',link);consent.append(box,text);
    const hp=document.createElement('input');hp.type='text';hp.name='ursar_website';hp.tabIndex=-1;hp.autocomplete='off';hp.className='ursar-honeypot';hp.setAttribute('aria-hidden','true');hp.setAttribute('aria-label','Оставьте пустым');
    const submit=form.querySelector('button[type="submit"],input[type="submit"],.srfm-submit-button');(submit?.parentElement||form).insertBefore(consent,submit||null);form.append(hp);
  };
  const send=async form=>{
    initForm(form);if(form.dataset.ursarSending)return;
    let status=form.querySelector('[data-preserved-form-status]');if(!status){status=document.createElement('p');status.dataset.preservedFormStatus='';status.setAttribute('role','status');form.append(status);}status.className='ursar-form-status';
    if(config.preview){status.textContent='Это предпросмотр. Для отправки обращения откройте опубликованную страницу.';return;}
    const val=selector=>form.querySelector(selector)?.value?.trim()||'';
    const textInputs=[...form.querySelectorAll('input[type="text"]')].filter(x=>!x.name.startsWith('ursar_'));
    const name=val('[name="contact-form-name"]')||textInputs.slice(0,form.id==='stickyelements-form'?1:2).map(x=>x.value.trim()).filter(Boolean).join(' ');
    const data={id:form.dataset.ursarRequestId||crypto.randomUUID(),name,email:val('input[type="email"],[name="contact-form-email"]'),phone:val('input[type="tel"],[name="contact-form-phone"]'),message:val('textarea'),page:location.pathname,formName:form.id==='stickyelements-form'?'Боковая форма':'Форма обратной связи',consent:!!form.querySelector('[name="ursar_consent"]')?.checked,website:val('[name="ursar_website"]')};
    if(!data.consent){status.textContent='Подтвердите согласие на обработку персональных данных.';form.querySelector('[name="ursar_consent"]').focus();return;}
    if(!data.name||(!data.email&&!data.phone)){status.textContent='Укажите имя и телефон или email.';return;}
    form.dataset.ursarRequestId=data.id;form.dataset.ursarSending='1';const buttons=[...form.querySelectorAll('[type="submit"]')];buttons.forEach(b=>b.disabled=true);status.textContent='Отправляем сообщение…';
    try{const response=await fetch('/api/enquiries',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(data)});const result=await response.json();if(!response.ok)throw new Error(result.error||'Не удалось отправить сообщение.');status.textContent=result.message;status.classList.add('success');form.reset();delete form.dataset.ursarRequestId;}
    catch(error){status.textContent=error.message||'Нет соединения. Попробуйте ещё раз.';status.classList.add('error');}
    finally{delete form.dataset.ursarSending;buttons.forEach(b=>b.disabled=false);}
  };
  window.ursarSubmitEnquiry=(form,event)=>{if(!supported(form))return false;event.preventDefault();event.stopImmediatePropagation();send(form);return true;};
  const init=()=>document.querySelectorAll('form').forEach(f=>{if(supported(f))initForm(f);});
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',init);else init();
  window.addEventListener('submit',event=>window.ursarSubmitEnquiry(event.target,event),true);
  window.addEventListener('click',event=>{const button=event.target.closest('button,input[type="submit"],input[type="image"]');if(button?.form&&['submit','image'].includes(button.type))window.ursarSubmitEnquiry(button.form,event);},true);
  document.addEventListener('click',event=>{const b=event.target.closest('[data-gallery-image]');if(!b)return;const gallery=b.closest('.ursar-gallery');gallery.querySelector('.ursar-gallery-main').href=b.dataset.galleryImage;gallery.querySelector('.ursar-gallery-main img').src=b.dataset.galleryImage;});
  if(config.preview){
    document.documentElement.classList.add('ursar-preview');
    document.addEventListener('click',event=>{const target=event.target.closest('[data-cms-fields]');if(target){event.preventDefault();event.stopImmediatePropagation();parent.postMessage({type:'ursar-field',id:target.dataset.cmsFields.split(' ')[0]},location.origin);}},true);
    window.addEventListener('message',event=>{if(event.origin!==location.origin||event.source!==parent||event.data?.type!=='ursar-preview-fields')return;const values=event.data.fields||{};const walker=document.createTreeWalker(document.body,NodeFilter.SHOW_COMMENT);let comment;while(comment=walker.nextNode()){const match=comment.data.match(/^URSAR:T:(\w+)$/);if(!match||typeof values[match[1]]!=='string')continue;let node=comment.nextSibling;while(node&&!(node.nodeType===8&&node.data==='URSAR:/T')){const next=node.nextSibling;node.remove();node=next;}comment.after(document.createTextNode(values[match[1]]));}});
  }
})();
