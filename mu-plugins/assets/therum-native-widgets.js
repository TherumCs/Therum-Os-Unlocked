(function(){
const root=document.querySelector('.thn');
if (!root) return;
const nonce=root.dataset.nonce;
let activeSidebar=null;
function toast(msg){
const t=document.getElementById('thn-toast');
t.textContent=msg;
t.classList.add('show');
clearTimeout(t._timer);
t._timer=setTimeout(()=>t.classList.remove('show'),1800);
}
function ajax(action,data){
const fd=new FormData();
fd.append('action',action);
fd.append('_wpnonce',nonce);
Object.entries(data).forEach(([k,v])=>fd.append(k,v));
return fetch(ajaxurl,{method:'POST',body:fd,credentials:'same-origin'}).then(r=>r.json());
}
document.querySelectorAll('.thw-region').forEach(region=>{
region.addEventListener('click',e=>{
if (e.target.closest('[data-action="remove-widget"]')) return;
document.querySelectorAll('.thw-region').forEach(r=>r.classList.remove('is-active'));
region.classList.add('is-active');
activeSidebar=region.dataset.sidebar;
const sub=document.getElementById('thw-side-sub');
sub.textContent='→ Adding to:'+region.dataset.sidebarName;
sub.classList.add('is-active');
document.querySelectorAll('.thw-avail-item').forEach(i=>{
i.classList.remove('disabled');
i.classList.add('is-armed');
});
});
});
document.querySelectorAll('.thw-avail-item').forEach(item=>{
item.addEventListener('click',()=>{
if (item.classList.contains('disabled')) return toast('Pick a region first');
if (!activeSidebar) return;
ajax('therum_widget_add',{sidebar:activeSidebar,widget_id:item.dataset.widgetId}).then(r=>{
if (r.success) location.reload();
else toast(r.data||'Failed');
});
});
});
document.querySelectorAll('[data-action="remove-widget"]').forEach(btn=>{
btn.addEventListener('click',e=>{
e.stopPropagation();
const widget=btn.closest('.thw-widget-item');
ajax('therum_widget_remove',{sidebar:btn.dataset.sidebar,widget_id:widget.dataset.widgetId}).then(r=>{
if (r.success) location.reload();
else toast('Failed');
});
});
});
document.getElementById('thw-search')?.addEventListener('input',e=>{
const q=e.target.value.toLowerCase();
document.querySelectorAll('.thw-avail-item').forEach(item=>{
item.style.display=item.dataset.widgetSearch.includes(q)?'':'none';
});
});
})();