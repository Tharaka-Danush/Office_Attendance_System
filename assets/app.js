'use strict';
const body=document.body;
const clock=document.getElementById('clock');
const serverStart=Date.parse(body.dataset.serverTime);
const elapsedStart=performance.now();
function tick(){const now=new Intl.DateTimeFormat('en-GB',{timeZone:body.dataset.timezone,hour:'2-digit',minute:'2-digit',second:'2-digit',hour12:false}).format(new Date(serverStart+performance.now()-elapsedStart));if(clock)clock.textContent=now;const popup=document.getElementById('checkout-confirm');if(popup&&popup.open)document.getElementById('checkout-confirm-time').textContent=now;}
tick();setInterval(tick,1000);
document.querySelectorAll('.print-trigger').forEach(b=>b.addEventListener('click',()=>window.print()));
const checkoutDialog=document.getElementById('checkout-confirm');
const confirmedForms=new WeakSet();
let pendingCheckout=null;
if(checkoutDialog){
 document.getElementById('checkout-confirm-cancel').addEventListener('click',()=>checkoutDialog.close());
 checkoutDialog.addEventListener('close',()=>{pendingCheckout=null;});
 checkoutDialog.addEventListener('click',event=>{if(event.target===checkoutDialog){const rect=checkoutDialog.getBoundingClientRect();if(event.clientX<rect.left||event.clientX>rect.right||event.clientY<rect.top||event.clientY>rect.bottom)checkoutDialog.close();}});
 document.getElementById('checkout-confirm-save').addEventListener('click',()=>{
  if(!pendingCheckout)return;
  const pending=pendingCheckout;pendingCheckout=null;checkoutDialog.close();
  confirmedForms.add(pending.form);pending.form.requestSubmit(pending.submitter||undefined);
 });
}
document.querySelectorAll('form[method="post"]').forEach(form=>form.addEventListener('submit',event=>{
 const confirmed=confirmedForms.has(form);confirmedForms.delete(form);
 if(form.dataset.confirm&&!confirmed){
  if(form.dataset.confirmKind==='checkout'&&checkoutDialog){
   event.preventDefault();if(checkoutDialog.open)return;
   pendingCheckout={form,submitter:event.submitter};
   document.getElementById('checkout-confirm-time').textContent=clock?clock.textContent:'Current server time';
   checkoutDialog.showModal();return;
  }
  if(!window.confirm(form.dataset.confirm)){event.preventDefault();return;}
  if(form.dataset.confirmKind==='salary')form.querySelector('[name="confirm_replace"]').value='1';
 }
 // Keep the clicked submit button's name/value in the actual request.
 setTimeout(()=>form.querySelectorAll('button').forEach(b=>b.disabled=true),0);
}));
if(body.dataset.live==='true')setTimeout(()=>window.location.reload(),60000);

const personalLive=document.getElementById('personal-live');
if(personalLive){
 const data=JSON.parse(personalLive.dataset.live),started=performance.now();
 const duration=seconds=>{seconds=Math.max(0,Math.floor(seconds));return String(Math.floor(seconds/3600)).padStart(2,'0')+':'+String(Math.floor(seconds%3600/60)).padStart(2,'0')+':'+String(seconds%60).padStart(2,'0');};
 const update=()=>{const now=data.now+(performance.now()-started)/1000,worked=data.completed+(data.active===null?0:Math.max(0,now-data.active));
 const values={worked,weekday:data.weekend?0:data.weekday+(data.active===null?0:Math.max(0,now-Math.max(data.active,data.cutoff))),single:data.weekend?Math.min(28800,worked):0,double:data.weekend?Math.max(0,worked-28800):0};
 personalLive.querySelectorAll('[data-time]').forEach(el=>el.textContent=duration(values[el.dataset.time]));};
 update();setInterval(update,1000);
}

function clearAttendancePrint(){document.body.classList.remove('attendance-print-month','attendance-print-daily');}
document.querySelectorAll('[data-attendance-print]').forEach(button=>button.addEventListener('click',()=>{clearAttendancePrint();document.body.classList.add('attendance-print-'+button.dataset.attendancePrint);try{window.print();}catch(error){clearAttendancePrint();throw error;}}));
window.addEventListener('afterprint',clearAttendancePrint);


// Confirm logout for employee, HR and admin accounts without changing sessions until confirmed.
const logoutDialog=document.getElementById('logout-confirm');
const logoutLink=document.querySelector('[data-logout-confirm]');
if(logoutDialog&&logoutLink){
 const cancel=document.getElementById('logout-confirm-cancel');
 const confirm=document.getElementById('logout-confirm-yes');
 logoutLink.addEventListener('click',event=>{
  event.preventDefault();
  confirm.href=logoutLink.href;
  if(typeof logoutDialog.showModal==='function'){
   if(!logoutDialog.open)logoutDialog.showModal();
  }else if(window.confirm('Are you sure you want to log out?')){
   window.location.assign(logoutLink.href);
  }
 });
 cancel.addEventListener('click',()=>logoutDialog.close());
 logoutDialog.addEventListener('click',event=>{if(event.target===logoutDialog)logoutDialog.close();});
}

// Admin correction: show the original session and prefill editable local times.
const adminRecord=document.getElementById('admin-attendance-record');
if(adminRecord){
 const correctionForm=adminRecord.closest('form');
 const before=document.getElementById('admin-attendance-before');
 const inField=correctionForm.querySelector('[name="proposed_in"]');
 const outField=correctionForm.querySelector('[name="proposed_out"]');
 const render=()=>{
  const option=adminRecord.selectedOptions[0];
  if(!option||!option.value){before.textContent='Select a session to see the original times before editing.';inField.value='';outField.value='';return;}
  const oldIn=option.dataset.in||'';
  const oldOut=option.dataset.out||'';
  before.textContent=`Original attendance · ${option.dataset.person||''} · Check in: ${oldIn.replace('T',' ')||'Unknown'} · Check out: ${oldOut.replace('T',' ')||'Open session'}`;
  inField.value=oldIn;outField.value=oldOut;
 };
 adminRecord.addEventListener('change',render);
 render();
 correctionForm.addEventListener('submit',event=>{
  // Explicit before/after review is required for sensitive attendance edits.
  // Validate explicit selection and prevent editing with no actual changes.
  const option=adminRecord.selectedOptions[0];
  if(!option||!option.value)return;
  if(inField.value===(option.dataset.in||'')&&outField.value===(option.dataset.out||'')){
   event.preventDefault();window.alert('No time changes detected. Edit the check-in or check-out time before saving.');return;
  }
  const message=`Confirm employee attendance correction\n\n${option.dataset.person||''}\nBEFORE: ${option.dataset.in||'Unknown'} → ${option.dataset.out||'Open'}\nAFTER: ${inField.value} → ${outField.value}\n\nSave this audited change?`;
  if(!window.confirm(message))event.preventDefault();
 });
}

// Preserve the desktop management sidebar position when opening another page.
// Session storage is per-browser-tab; no server-side data or credentials stored.
(()=>{
 const sidebar=document.querySelector('.app-page .layout > aside');
 if(!sidebar)return;
 const menu=sidebar.querySelector('nav[aria-label="Main navigation"]');
 if(!menu)return;
 const key='officeAttendance:sidebarScroll:v1';
 const desktop=()=>window.matchMedia('(min-width: 761px)').matches;
 const save=()=>{
  if(!desktop())return;
  try{window.sessionStorage.setItem(key,String(sidebar.scrollTop));}catch(_error){}
 };
 const restore=()=>{
  if(!desktop())return;
  let stored=null;
  try{stored=window.sessionStorage.getItem(key);}catch(_error){}
  if(stored!==null&&Number.isFinite(Number(stored))){
   sidebar.scrollTop=Math.max(0,Number(stored));
  }else{
   // First visit: show the active link without moving the main document.
   const active=menu.querySelector('a[aria-current="page"]');
   if(active){
    const a=active.getBoundingClientRect();
    const s=sidebar.getBoundingClientRect();
    if(a.bottom>s.bottom-24)sidebar.scrollTop+=a.bottom-s.bottom+24;
    else if(a.top<s.top+24)sidebar.scrollTop-=s.top+24-a.top;
   }
  }
 };
 menu.addEventListener('click',event=>{
  if(event.target.closest('a[href]'))save();
 });
 sidebar.addEventListener('scroll',()=>{
  if(desktop())save();
 },{passive:true});
 window.addEventListener('pagehide',save);
 window.addEventListener('pageshow',restore);
 restore();
})();
