(function(){
const root=document.querySelector('.thn');
if (!root) return;
const nonce=root.dataset.nonce;
const menuId=parseInt(root.dataset.menuId,10)||0;
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
Object.entries(data).forEach(([k,v])=>{
if (Array.isArray(v)||(typeof v==='object'&&v!==null)) fd.append(k,JSON.stringify(v));
else fd.append(k,v);
});
return fetch(ajaxurl,{method:'POST',body:fd,credentials:'same-origin'}).then(r=>r.json());
}
document.querySelectorAll('.thn-tab').forEach(tab=>{
tab.addEventListener('click',()=>{
document.querySelectorAll('.thn-tab').forEach(t=>t.classList.remove('active'));
tab.classList.add('active');
document.querySelectorAll('[data-panel]').forEach(p=>p.style.display='none');
document.querySelector('[data-panel="'+tab.dataset.tab+'"]').style.display='';
});
});
document.querySelectorAll('[data-filter-list]').forEach(input=>{
input.addEventListener('input',e=>{
const q=e.target.value.toLowerCase();
const list=document.querySelector('[data-list="'+input.dataset.filterList+'"]');
list.querySelectorAll('.thn-add-row').forEach(row=>{
const text=row.dataset.searchText||'';
row.style.display=text.includes(q)?'':'none';
});
});
});
document.getElementById('thn-create-btn')?.addEventListener('click',()=>{
const name=document.getElementById('thn-create-name').value.trim();
if (!name) return toast('Enter a menu name');
ajax('therum_menu_create',{name}).then(r=>{
if (r.success) location.href='?page=therum-menus&menu='+r.data.menu_id;
else toast(r.data||'Failed to create');
});
});
document.getElementById('thn-new-menu')?.addEventListener('click',()=>{
const name=prompt('Menu name?');
if (!name) return;
ajax('therum_menu_create',{name:name.trim()}).then(r=>{
if (r.success) location.href='?page=therum-menus&menu='+r.data.menu_id;
else toast(r.data||'Failed to create');
});
});
if (!menuId) return;
document.getElementById('thn-add-selected')?.addEventListener('click',()=>{
const activeTab=document.querySelector('.thn-tab.active').dataset.tab;
let items=[];
if (activeTab==='custom'){
const url=document.getElementById('thn-custom-url').value.trim();
const label=document.getElementById('thn-custom-label').value.trim();
if (!url||!label) return toast('Need both URL and label');
items.push({type:'custom',url,title:label});
}else{
const panel=document.querySelector('[data-panel="'+activeTab+'"]');
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
const tree=document.getElementById('thn-tree-root');
if (tree) wireDragDrop(tree);
document.getElementById('thn-save-menu')?.addEventListener('click',()=>{
const order=serializeTree(tree);
ajax('therum_menu_save_order',{menu_id:menuId,order}).then(r=>{
if (r.success) toast('Saved');
else toast(r.data||'Save failed');
});
});
document.querySelectorAll('[data-action="delete-item"]').forEach(btn=>{
btn.addEventListener('click',e=>{
e.stopPropagation();
const item=btn.closest('.thn-tree-item');
if (!confirm('Remove this item?')) return;
ajax('therum_menu_delete_item',{item_id:item.dataset.itemId}).then(r=>{
if (r.success) item.remove();else toast('Delete failed');
});
});
});
document.querySelectorAll('.thn-loc-select').forEach(sel=>{
sel.addEventListener('change',()=>{
ajax('therum_menu_set_location',{
location:sel.dataset.location,
menu_id:sel.value
}).then(r=>toast(r.success?'Location updated':'Failed'));
});
});
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
function wireDragDrop(root){
let dragged=null;
root.querySelectorAll('.thn-tree-item').forEach(item=>{item.draggable=true;});
root.addEventListener('dragstart',e=>{
const item=e.target.closest('.thn-tree-item');
if (!item) return;
dragged=item;
item.classList.add('dragging');
e.dataTransfer.effectAllowed='move';
try{e.dataTransfer.setData('text/plain',item.dataset.itemId);}catch(_){}
});
root.addEventListener('dragend',()=>{
if (dragged) dragged.classList.remove('dragging');
dragged=null;
root.querySelectorAll('.drag-over-into,.drag-over-before').forEach(el=>el.classList.remove('drag-over-into','drag-over-before'));
});
root.addEventListener('dragover',e=>{
e.preventDefault();
e.dataTransfer.dropEffect='move';
const target=e.target.closest('.thn-tree-item');
root.querySelectorAll('.drag-over-into,.drag-over-before').forEach(el=>el.classList.remove('drag-over-into','drag-over-before'));
if (!target||target===dragged||dragged?.contains(target)) return;
const rect=target.getBoundingClientRect();
const offsetX=e.clientX-rect.left;
if (offsetX>rect.width*0.5) target.classList.add('drag-over-into');
else target.classList.add('drag-over-before');
});
root.addEventListener('drop',e=>{
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
root.querySelectorAll('.drag-over-into,.drag-over-before').forEach(el=>el.classList.remove('drag-over-into','drag-over-before'));
});
}
})();