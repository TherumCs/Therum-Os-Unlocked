(function(){
const bar=document.querySelector('[data-th-editor-bar]');
if (!bar) return;
const postId=bar.dataset.postId;
const nonce=bar.dataset.nonce;
function setMode(mode){
bar.querySelectorAll('.th-em[data-mode]').forEach(b=>{
b.classList.toggle('is-active',b.dataset.mode===mode);
});
if (mode==='visual'){
const t=document.getElementById('content-tmce');if (t) t.click();
}else if (mode==='code'){
const t=document.getElementById('content-html');if (t) t.click();
}
}
bar.addEventListener('click',e=>{
const btn=e.target.closest('.th-em[data-mode]');
if (btn){e.preventDefault();setMode(btn.dataset.mode);}
});
const saveBtn=bar.querySelector('[data-th-save]');
const saveLabel=saveBtn&&saveBtn.querySelector('span');
if (saveBtn) saveBtn.addEventListener('click',async ()=>{
if (saveBtn.classList.contains('is-saving')) return;
if (window.tinyMCE&&window.tinyMCE.get('content')){
try{window.tinyMCE.get('content').save();}catch (_){}
}
const titleEl=document.getElementById('title');
const contentEl=document.getElementById('content');
const fd=new FormData();
fd.append('action','therum_save_post');
fd.append('post_id',postId);
fd.append('nonce',nonce);
if (titleEl) fd.append('title',titleEl.value);
if (contentEl) fd.append('content',contentEl.value);
saveBtn.classList.remove('is-saved','is-error');
saveBtn.classList.add('is-saving');
if (saveLabel) saveLabel.textContent='Saving…';
try{
const r=await fetch(ajaxurl,{method:'POST',body:fd,credentials:'same-origin'});
const j=await r.json();
saveBtn.classList.remove('is-saving');
if (j&&j.success){
saveBtn.classList.add('is-saved');
if (saveLabel) saveLabel.textContent='Saved';
setTimeout(()=>{
saveBtn.classList.remove('is-saved');
if (saveLabel) saveLabel.textContent='Save';
},1500);
}else{
saveBtn.classList.add('is-error');
if (saveLabel) saveLabel.textContent=(j&&j.data&&j.data.message)||'Error';
setTimeout(()=>{
saveBtn.classList.remove('is-error');
if (saveLabel) saveLabel.textContent='Save';
},2500);
}
}catch (err){
saveBtn.classList.remove('is-saving');
saveBtn.classList.add('is-error');
if (saveLabel) saveLabel.textContent='Error';
setTimeout(()=>{
saveBtn.classList.remove('is-error');
if (saveLabel) saveLabel.textContent='Save';
},2500);
}
});
document.addEventListener('keydown',e=>{
if ((e.metaKey||e.ctrlKey)&&e.key==='s'){
e.preventDefault();
if (saveBtn) saveBtn.click();
}
});
})();