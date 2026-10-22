const dialog=document.querySelector('#lightbox');
let zoom=1;
function applyZoom(){document.querySelector('#lightbox-img').style.width=`${zoom*100}%`}
function showImage(src,caption){zoom=1;applyZoom();const image=document.querySelector('#lightbox-img');image.src=src;image.alt=caption;document.querySelector('#lightbox-caption').textContent=caption;dialog.showModal()}
document.querySelectorAll('[data-photo]').forEach(b=>b.addEventListener('click',()=>showImage(b.dataset.photo,b.dataset.caption)));
document.querySelector('.plan-open')?.addEventListener('click',()=>showImage('assets/plan.webp','Фрагмент проекта бассейна. Иллюстрация, не для производства работ.'));
document.querySelector('#zoom-in').addEventListener('click',()=>{zoom=Math.min(zoom+.5,4);applyZoom()});
document.querySelector('#zoom-out').addEventListener('click',()=>{zoom=Math.max(zoom-.5,1);applyZoom()});
document.querySelector('#close-lightbox').addEventListener('click',()=>dialog.close());
dialog.addEventListener('click',e=>{if(e.target===dialog)dialog.close()});
const phone=document.querySelector('input[name="phone"]'),phoneError=document.querySelector('#phone-error');
let phoneTouched=false;
function checkPhone(showError=false){const message=FormUtils.phoneError(phone.value);phone.setCustomValidity(message);phoneError.textContent=message;phoneError.hidden=!showError||!message;phone.setAttribute('aria-invalid',String(Boolean(showError&&message)));return !message}
phone.addEventListener('input',e=>{if(!e.inputType?.startsWith('delete')&&phone.selectionStart===phone.value.length){phone.value=FormUtils.formatPhone(phone.value)}checkPhone(phoneTouched)});
phone.addEventListener('blur',()=>{phoneTouched=true;phone.value=FormUtils.formatPhone(phone.value);checkPhone(true)});
phone.addEventListener('invalid',()=>{phoneTouched=true;checkPhone(true)});
const file=document.querySelector('#project-file'),fileList=document.querySelector('#file-list'),fileError=document.querySelector('#file-error'),clearFiles=document.querySelector('#clear-files');
function updateFiles(){const files=[...file.files];fileList.replaceChildren();files.forEach(item=>{const li=document.createElement('li');li.textContent=`${item.name} · ${(item.size/1024/1024).toFixed(1)} МБ`;fileList.append(li)});const message=FormUtils.filesError(files);file.setCustomValidity(message);fileError.textContent=message;fileError.hidden=!message;file.setAttribute('aria-invalid',String(Boolean(message)));fileList.hidden=!files.length;clearFiles.hidden=!files.length;document.querySelector('#file-label').textContent=files.length?`Выбрано файлов: ${files.length}`:'Прикрепить проекты'}
file.addEventListener('change',updateFiles);clearFiles.addEventListener('click',()=>{file.value='';updateFiles()});
const projectUrl=document.querySelector('#project-url');projectUrl.addEventListener('input',()=>{projectUrl.setCustomValidity(projectUrl.value&&!/^https?:\/\//i.test(projectUrl.value)?'Укажите полную ссылку, начиная с https:// или http://':'')});
document.querySelector('#lead-form').addEventListener('submit',e=>{e.preventDefault();document.querySelector('#form-status').textContent='Пример заполнен. Данные не отправлены. Для расчета позвоните, напишите в MAX или отправьте проект на zayavka@dayagroup.ru.'});
const mobileBar=document.querySelector('.mobile-bar');let formVisible=false,dialogOpen=false;
const updateBar=()=>mobileBar.classList.toggle('is-hidden',formVisible||dialogOpen);
new IntersectionObserver(entries=>{formVisible=entries[0].isIntersecting;updateBar()},{threshold:0}).observe(document.querySelector('#lead-form'));
new MutationObserver(()=>{dialogOpen=dialog.open;updateBar()}).observe(dialog,{attributes:true,attributeFilter:['open']});
// Manual project carousel: native touch scrolling, keyboard controls and pagination.
const track=document.querySelector('#case-track'),cards=[...track.querySelectorAll('.case')];
const prev=document.querySelector('#case-prev'),next=document.querySelector('#case-next'),dots=document.querySelector('#case-dots'),counter=document.querySelector('#case-counter');
let visible=0,dotButtons=[];
const pad=n=>String(n).padStart(2,'0');
function carouselIndex(){const step=cards[0].getBoundingClientRect().width+parseFloat(getComputedStyle(track).gap);return Math.max(0,Math.min(cards.length-visible,Math.round(track.scrollLeft/step)))}
function goTo(index){index=Math.max(0,Math.min(cards.length-visible,index));const step=cards[0].getBoundingClientRect().width+parseFloat(getComputedStyle(track).gap);track.scrollTo({left:index*step,behavior:matchMedia('(prefers-reduced-motion: reduce)').matches?'instant':'smooth'})}
function syncCarousel(){const i=carouselIndex();counter.textContent=visible===1?`${pad(i+1)} / ${pad(cards.length)}`:`${pad(i+1)}–${pad(Math.min(i+visible,cards.length))} / ${pad(cards.length)}`;prev.disabled=i===0;next.disabled=i>=cards.length-visible;dotButtons.forEach((b,n)=>b.setAttribute('aria-current',String(n===Math.round(i/visible))))}
function sizeCarousel(){const v=parseInt(getComputedStyle(document.querySelector('.case-carousel')).getPropertyValue('--visible'));if(v!==visible){visible=v;dots.replaceChildren();dotButtons=[];for(let i=0;i<cards.length;i+=visible){const b=document.createElement('button');b.type='button';b.setAttribute('aria-label',visible===1?`Показать проект ${i+1}`:`Показать проекты ${i+1}–${Math.min(i+visible,cards.length)}`);b.addEventListener('click',()=>goTo(i));dots.append(b);dotButtons.push(b)}}syncCarousel()}
prev.addEventListener('click',()=>goTo(carouselIndex()-visible));next.addEventListener('click',()=>goTo(carouselIndex()+visible));track.addEventListener('scroll',syncCarousel,{passive:true});track.addEventListener('keydown',e=>{if(e.key==='ArrowRight'||e.key==='ArrowLeft'){e.preventDefault();goTo(carouselIndex()+(e.key==='ArrowRight'?visible:-visible))}});new ResizeObserver(sizeCarousel).observe(track);sizeCarousel();

const modeButtons=[...document.querySelectorAll('[data-mode]')];
modeButtons.forEach(button=>button.addEventListener('click',()=>{
 const withProject=button.dataset.mode==='project';
 modeButtons.forEach(b=>b.setAttribute('aria-pressed',String(b===button)));
 document.querySelector('#parameters-fields').hidden=withProject;
 document.querySelector('#project-fields').hidden=!withProject;
 file.disabled=!withProject;projectUrl.disabled=!withProject;
 document.querySelector('#mode-hint').textContent=withProject?'Прикрепите чертежи. Уточним детали и сообщим срок расчета.':'Укажите известные параметры. Поможем уточнить данные для расчета.';
 document.querySelector('#form-status').textContent='';
}));

document.querySelectorAll('.review-toggle').forEach(button=>button.addEventListener('click',()=>{
 const expanded=button.getAttribute('aria-expanded')!=='true';
 button.setAttribute('aria-expanded',String(expanded));
 const card=button.closest('.review-card');card.classList.toggle('is-expanded',expanded);card.querySelector('.review-full').hidden=!expanded;card.querySelector('.review-preview').hidden=expanded;
 button.innerHTML=expanded?'Свернуть отзыв <span aria-hidden="true">−</span>':'Читать полностью <span aria-hidden="true">＋</span>';
}));
const photoTrack=document.querySelector('#photo-track');
const photoCards=[...photoTrack.querySelectorAll('.photo-card')];
const photoPrev=document.querySelector('#photo-prev'),photoNext=document.querySelector('#photo-next');
function photoVisible(){return matchMedia('(max-width:700px)').matches?1:2}
function photoStep(){return photoCards[0].getBoundingClientRect().width+parseFloat(getComputedStyle(photoTrack).gap)}
function photoIndex(){return Math.max(0,Math.min(photoCards.length-photoVisible(),Math.round(photoTrack.scrollLeft/photoStep())))}
function syncPhotos(){const i=photoIndex(),v=photoVisible();document.querySelector('#photo-counter').textContent=v===1?`${pad(i+1)} / ${pad(photoCards.length)}`:`${pad(i+1)}–${pad(Math.min(i+v,photoCards.length))} / ${pad(photoCards.length)}`;photoPrev.disabled=i===0;photoNext.disabled=i>=photoCards.length-v}
function goPhoto(i){i=Math.max(0,Math.min(photoCards.length-photoVisible(),i));photoTrack.scrollTo({left:i*photoStep(),behavior:matchMedia('(prefers-reduced-motion:reduce)').matches?'instant':'smooth'})}
photoPrev.addEventListener('click',()=>goPhoto(photoIndex()-photoVisible()));photoNext.addEventListener('click',()=>goPhoto(photoIndex()+photoVisible()));photoTrack.addEventListener('scroll',syncPhotos,{passive:true});photoTrack.addEventListener('keydown',e=>{if(e.key==='ArrowLeft'||e.key==='ArrowRight'){e.preventDefault();goPhoto(photoIndex()+(e.key==='ArrowRight'?photoVisible():-photoVisible()))}});new ResizeObserver(syncPhotos).observe(photoTrack);syncPhotos();

const systemDetails=[...document.querySelectorAll('[data-component-detail]')];
const systemPoints=[...document.querySelectorAll('[data-component]')];
function syncSystem(){systemPoints.forEach(point=>{const item=document.querySelector(`#component-${point.dataset.component}`);point.setAttribute('aria-expanded',String(item.open))})}
systemPoints.forEach(point=>point.addEventListener('click',()=>{systemDetails.forEach(item=>{item.open=item.dataset.componentDetail===point.dataset.component});syncSystem()}));
systemDetails.forEach(item=>item.addEventListener('toggle',()=>{if(item.open)systemDetails.forEach(other=>{if(other!==item)other.open=false});syncSystem()}));
syncSystem();
