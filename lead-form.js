(function () {
 'use strict';
 const form=document.querySelector('#lead-form'),status=document.querySelector('#form-status');
 const submit=form.querySelector('[type=submit]'),note=form.querySelector('.demo-note');
 const endpoint='/api/lead.php';
 form.method='post';form.action=endpoint;form.enctype='multipart/form-data';
 const limits={city:120,start:120,details:3000,project_url:2048,area:12,height:12};
 Object.entries(limits).forEach(([name,max])=>{form.elements[name].maxLength=max});
 const file=form.querySelector('[type=file]');file.name='files[]';file.accept='.pdf,.dwg,.dxf,.xlsx,.xls,.docx,.zip';
 document.querySelector('#file-help').textContent='До 5 файлов: PDF, DWG, DXF, XLS, XLSX, DOCX, ZIP · до 20 МБ каждый, до 25 МБ суммарно';
 const trap=document.createElement('input');trap.name='website';trap.type='text';trap.tabIndex=-1;trap.autocomplete='off';trap.setAttribute('aria-hidden','true');trap.className='form-trap';form.append(trap);
 const consentVersion='2026-11-17-v1',consentPath='/consent-2026-11-17.html';
 const consent=document.createElement('label');consent.className='consent-control';
 const checkbox=document.createElement('input');checkbox.type='checkbox';checkbox.name='consent';checkbox.value='1';checkbox.required=true;
 const consentText=document.createElement('span'),consentLink=document.createElement('a');
 consentLink.href='consent-2026-11-17.html';consentLink.target='_blank';consentLink.rel='noopener';consentLink.textContent='согласие на обработку персональных данных';
 consentText.append('Я даю ',consentLink,'.');consent.append(checkbox,consentText);submit.before(consent);
 let csrf='',consentHash='',pending=false,key='',received=false;
 function newKey(){const bytes=new Uint8Array(16);crypto.getRandomValues(bytes);return [...bytes].map(v=>v.toString(16).padStart(2,'0')).join('')}
 function state(name,message){form.dataset.state=name;status.textContent=message;status.setAttribute('role',name==='error'?'alert':'status');form.setAttribute('aria-busy',String(name==='pending'))}
 async function request(options){const controller=new AbortController();const timer=setTimeout(()=>controller.abort(),45000);try{const response=await fetch(endpoint,{...options,credentials:'same-origin',signal:controller.signal});let body;try{body=await response.json()}catch{throw new Error('Не удалось подтвердить прием. Повторите отправку позже.')}if(!response.ok){const error=new Error(body.error||'Прием временно недоступен.');error.status=response.status;throw error}return {response,body}}finally{clearTimeout(timer)}}
 async function setup(){const {body}=await request({method:'GET'});if(!body.csrf||body.consent_version!==consentVersion||body.consent_url!==location.origin+consentPath||!/^[a-f0-9]{64}$/.test(body.consent_sha256||''))throw new Error('Условия формы обновились. Обновите страницу перед отправкой.');csrf=body.csrf;consentHash=body.consent_sha256;note.hidden=true}
 setup().catch(()=>{note.textContent='Прием через форму пока недоступен. Используйте телефон, почту или MAX рядом.'});
 // После неизвестного результата сохраняем ID и содержимое для безопасного повтора, не очищаем поля.
 form.addEventListener('submit',async event=>{
  event.preventDefault();if(pending||received)return;
  if(!csrf){try{await setup()}catch(error){state('error',error.message);return}}
  if(!form.reportValidity())return;
  if(!key)key=newKey();
  const data=new FormData(form);
  data.set('csrf',csrf);data.set('idempotency_key',key);
  data.set('consent_version',consentVersion);data.set('consent_sha256',consentHash);
  data.set('mode',form.querySelector('[data-mode][aria-pressed=true]')?.dataset.mode||'project');
  data.set('page',location.origin+location.pathname);
  const params=new URLSearchParams(location.search);
  ['utm_source','utm_medium','utm_campaign','utm_content','utm_term'].forEach(name=>data.set(name,(params.get(name)||'').slice(0,200)));
  pending=true;submit.disabled=true;state('pending','Отправляем заявку. Дождитесь подтверждения приема.');
  try{
   const {response,body}=await request({method:'POST',body:data});
   if(response.status!==202||body.status!=='received'||body.id!==key)throw new Error('Сервер не подтвердил прием заявки.');
   received=true;state('received',`Заявка получена и ожидает обработки. Номер обращения: ${body.id}.`);
  }catch(error){if(error.status===403){csrf='';try{await setup()}catch{}}state('error',error.name==='AbortError'?'Ответ не получен. Заявка могла быть принята. Данные не очищены; повторите отправку без изменений.':error.message)}
  finally{pending=false;submit.disabled=received}
 });
})();
