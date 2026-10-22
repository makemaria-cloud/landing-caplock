(function(root){
 function phoneError(value){
  const raw=value.trim(),digits=raw.replace(/\D/g,'');
  if(!raw)return 'Укажите телефон для связи.';
  if(!/^\+?[\d\s().-]+$/.test(raw))return 'В номере допустимы цифры, пробелы, скобки и дефисы.';
  if(raw.startsWith('+')&&!digits.startsWith('7'))return /^[1-9]\d{7,14}$/.test(digits)?'':'Проверьте код страны и номер: от 8 до 15 цифр.';
  const local=!raw.startsWith('+')&&digits.length===10?digits:(digits.length===11&&/^[78]/.test(digits)?digits.slice(1):'');
  return /^[1-9]\d{9}$/.test(local)?'':'Введите полный номер: +7 (999) 123-45-67 или номер с кодом другой страны.';
 }
 function formatPhone(value){
  const raw=value.trim();if(!/^\+?[\d\s().-]*$/.test(raw))return value;
  let d=raw.replace(/\D/g,'');if(!d)return value;
  if(raw.startsWith('+')&&!d.startsWith('7'))return value;
  if(d.length===10&&!raw.startsWith('+'))d='7'+d;
  else if(d.startsWith('8'))d='7'+d.slice(1);
  if(!d.startsWith('7')||d.length>11)return value;
  const n=d.slice(1);let result='+7';
  if(n.length)result+=' ('+n.slice(0,3);
  if(n.length>=3)result+=')';
  if(n.length>3)result+=' '+n.slice(3,6);
  if(n.length>6)result+='-'+n.slice(6,8);
  if(n.length>8)result+='-'+n.slice(8,10);
  return result;
 }
 function filesError(files){const oversized=files.filter(f=>f.size>20*1024*1024);return oversized.length?'Размер каждого файла — до 20 МБ. Уменьшите крупные файлы или укажите ссылку на проект.':''}
 const api={phoneError,formatPhone,filesError};if(typeof module==='object'&&module.exports)module.exports=api;else root.FormUtils=api;
})(typeof globalThis!=='undefined'?globalThis:this);
