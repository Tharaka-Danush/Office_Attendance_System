'use strict';
const form=document.getElementById('login-form');
if(form){
 const username=form.querySelector('[name="username"]');
 const password=form.querySelector('[name="password"]');
 const fields=[username,password];
 const toggle=form.querySelector('.password-toggle');
 const resetFields=()=>{
  fields.forEach(field=>{field.value='';field.readOnly=true;field.setAttribute('autocomplete',field===password?'new-password':'off');});
  password.type='password';toggle.setAttribute('aria-pressed','false');toggle.setAttribute('aria-label','Show password');
 };
 resetFields();
 // pageshow also runs when the browser restores a page from its back/forward cache.
 window.addEventListener('pageshow',resetFields);
 fields.forEach(field=>{
  const enable=()=>{field.readOnly=false;};
  field.addEventListener('focus',enable);
  field.addEventListener('pointerdown',enable);
 });
 toggle.addEventListener('click',()=>{
  const show=password.type==='password';password.type=show?'text':'password';
  toggle.setAttribute('aria-pressed',String(show));toggle.setAttribute('aria-label',show?'Hide password':'Show password');
 });
 form.addEventListener('submit',()=>{
  // Retain values until native form serialization finishes; clear them on leaving.
  form.querySelector('[type="submit"]').textContent='Signing in…';
 });
 window.addEventListener('pagehide',()=>fields.forEach(field=>field.value=''));
}
