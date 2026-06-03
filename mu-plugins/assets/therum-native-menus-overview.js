(function(){
const root=document.querySelector('.thm-wrap');
if (!root) return;
const nonce=root.dataset.nonce;
function toast(msg){
const t=document.getElementById('thm-toast');
t.textContent=msg;
t.classList.add('show');
clearTimeout(t._timer);
t._timer=setTimeout(()=>t.classList.remove('show'),1800);
}
function ajax(action,data){
const fd=new FormData();
fd.append('action',action);
fd.append('_wpnonce',nonce);
Object.entries(data).forEach(([k,v])=>{
if (Array.isArray(v)||(typeof v==='object'&&v!==null)) fd.append(k,JSON.stringify(v));
else fd.append(k,v);
});
return fetch(ajaxurl,{method:'POST',body:fd,credentials:'same-origin'}).then(r=>r.json());
}
document.getElementById('thm-new-menu')?.addEventListener('click',()=>{
const name=prompt('Menu name?');
if (!name) return;
ajax('therum_menu_create',{name:name.trim()}).then(r=>{
if (r.success) location.reload();
else toast(r.data||'Failed to create');
});
});
root.addEventListener('click',e=>{
const tab=e.target.closest('.thm-tab');
if (tab){
const card=tab.closest('.thm-card');
card.querySelectorAll('.thm-tab').forEach(t=>t.classList.remove('active'));
tab.classList.add('active');
card.querySelectorAll('[data-panel]').forEach(p=>p.style.display='none');
const panel=card.querySelector('[data-panel="'+tab.dataset.tab+'"]');
if (panel) panel.style.display='';
}
});
root.addEventListener('input',e=>{
const inp=e.target.closest('[data-search-list]');
if (!inp) return;
const q=inp.value.toLowerCase();
const list=root.querySelector('[data-list="'+inp.dataset.searchList+'"]');
if (!list) return;
list.querySelectorAll('.thm-add-row').forEach(row=>{
row.style.display=(row.dataset.searchText||'').includes(q)?'':'none';
});
});
root.querySelectorAll('.thm-add-toggle').forEach(btn=>{
btn.addEventListener('click',()=>{
btn.closest('.thm-card').classList.toggle('is-adding');
});
});
root.addEventListener('click',e=>{
if (e.target.closest('.thm-cancel-add')){
e.target.closest('.thm-card').classList.remove('is-adding');
}
});
root.querySelectorAll('.thm-add-confirm').forEach(btn=>{
btn.addEventListener('click',()=>{
const card=btn.closest('.thm-card');
const menuId=parseInt(card.dataset.menuId,10);
const activeTab=card.querySelector('.thm-tab.active').dataset.tab.split('-')[0];
let items=[];
if (activeTab==='custom'){
const url=card.querySelector('.thm-custom-url').value.trim();
const label=card.querySelector('.thm-custom-label').value.trim();
if (!url||!label) return toast('Need both URL and label');
items.push({type:'custom',url,title:label});
}else{
const panel=card.querySelector('[data-panel^="'+activeTab+'-"]');
panel.querySelectorAll('input:checked').forEach(cb=>{
items.push({
type:cb.dataset.addType,
object:cb.dataset.addObject,
object_id:cb.dataset.addId,
title:cb.dataset.addTitle,
url:cb.dataset.addUrl
});
});
}
if (!items.length) return toast('Select items first');
ajax('therum_menu_add_items',{menu_id:menuId,items}).then(r=>{
if (r.success) location.reload();
else toast(r.data||'Failed to add');
});
});
});
root.querySelectorAll('.thm-save').forEach(btn=>{
btn.addEventListener('click',()=>{
const menuId=parseInt(btn.dataset.menu,10);
const tree=document.getElementById('thn-tree-'+menuId);
if (!tree) return;
const order=serializeTree(tree);
ajax('therum_menu_save_order',{menu_id:menuId,order}).then(r=>{
toast(r.success?'Saved':(r.data||'Save failed'));
});
});
});
root.addEventListener('click',e=>{
const del=e.target.closest('[data-action="delete-item"]');
if (!del) return;
e.stopPropagation();
const item=del.closest('.thn-tree-item');
if (!confirm('Remove this item?')) return;
ajax('therum_menu_delete_item',{item_id:item.dataset.itemId}).then(r=>{
if (r.success) item.remove();else toast('Delete failed');
});
});
root.querySelectorAll('.thm-loc-select').forEach(sel=>{
sel.addEventListener('change',()=>{
const menuId=parseInt(sel.dataset.locationFor,10);
ajax('therum_menu_set_location',{location:sel.value,menu_id:menuId}).then(r=>{
toast(r.success?'Location updated':'Failed');
});
});
});
root.querySelectorAll('.thn-tree-list').forEach(tree=>wireDragDrop(tree));
function serializeTree(ul,parent=0){
const out=[];
ul.querySelectorAll(':scope>.thn-tree-item').forEach((li,i)=>{
const id=parseInt(li.dataset.itemId,10);
out.push({id,parent,position:i});
const childUl=li.querySelector(':scope>.thn-tree-children');
if (childUl) out.push(...serializeTree(childUl,id));
});
return out;
}
function wireDragDrop(tree){
let dragged=null;
tree.querySelectorAll('.thn-tree-item').forEach(item=>{item.draggable=true;});
tree.addEventListener('dragstart',e=>{
const item=e.target.closest('.thn-tree-item');
if (!item) return;
dragged=item;
item.classList.add('dragging');
e.dataTransfer.effectAllowed='move';
try{e.dataTransfer.setData('text/plain',item.dataset.itemId);}catch (_){}
});
tree.addEventListener('dragend',()=>{
if (dragged) dragged.classList.remove('dragging');
dragged=null;
tree.querySelectorAll('.drag-over-into,.drag-over-before').forEach(el=>el.classList.remove('drag-over-into','drag-over-before'));
});
tree.addEventListener('dragover',e=>{
e.preventDefault();
e.dataTransfer.dropEffect='move';
const target=e.target.closest('.thn-tree-item');
tree.querySelectorAll('.drag-over-into,.drag-over-before').forEach(el=>el.classList.remove('drag-over-into','drag-over-before'));
if (!target||target===dragged||dragged?.contains(target)) return;
const rect=target.getBoundingClientRect();
if (e.clientX-rect.left>rect.width*0.5) target.classList.add('drag-over-into');
else target.classList.add('drag-over-before');
});
tree.addEventListener('drop',e=>{
e.preventDefault();
const target=e.target.closest('.thn-tree-item');
if (!target||!dragged||target===dragged||dragged.contains(target)) return;
if (target.classList.contains('drag-over-into')){
let childUl=target.querySelector(':scope>.thn-tree-children');
if (!childUl){
childUl=document.createElement('ul');
childUl.className='thn-tree-children';
target.appendChild(childUl);
}
childUl.appendChild(dragged);
}else{
target.parentNode.insertBefore(dragged,target);
}
tree.querySelectorAll('.drag-over-into,.drag-over-before').forEach(el=>el.classList.remove('drag-over-into','drag-over-before'));
});
}
})();