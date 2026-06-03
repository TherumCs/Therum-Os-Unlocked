(function(){
'use strict';
function renderTiles(){
var view=document.body.dataset.themeView;
if (view!=='tiles') return;
document.querySelectorAll('.th-theme-card').forEach(function(card){
if (card.querySelector('.th-style-tile')) return;
var name=(card.querySelector('.th-theme-card-name')||{}).textContent||'';
name=name.replace('Active','').trim();
var desc=(card.querySelector('.th-theme-card-desc')||{}).textContent||'';
var preview=card.querySelector('.th-theme-preview');
var bg='';
var rail='';
if (preview){
var mainEl=preview.querySelector('.th-theme-preview-main');
var railEl=preview.querySelector('.th-theme-preview-rail');
if (mainEl) bg=mainEl.getAttribute('style')||'';
if (railEl) rail=railEl.style.background||railEl.style.backgroundColor||'#888';
}
var isActive=card.classList.contains('active');
var swatches=[rail,rail,'#ffffff'];
var tile=document.createElement('div');
tile.className='th-style-tile';
tile.innerHTML=
'<div class="th-style-tile-hero" style="'+bg+'">'+
(isActive?'<span class="th-style-tile-active-pill">Active</span>':'')+
'<div class="th-style-tile-name-row">'+
'<span class="th-style-tile-name">'+name+'</span>'+
'</div>'+
'<div class="th-style-tile-headline">The quick brown fox</div>'+
'<div class="th-style-tile-body">'+desc+'</div>'+
'</div>'+
'<div class="th-style-tile-footer" style="'+bg+'">'+
'<button class="th-style-tile-button" style="background:'+rail+';color:white;border-color:'+rail+';">Action</button>'+
'<div class="th-style-tile-swatches">'+
swatches.map(function(s){return '<div class="th-style-tile-swatch" style="background:'+s+'"></div>';}).join('')+
'</div>'+
'</div>';
card.appendChild(tile);
});
}
function clearTiles(){
document.querySelectorAll('.th-style-tile').forEach(function(t){t.remove();});
}
if (document.readyState==='loading'){
document.addEventListener('DOMContentLoaded',renderTiles);
}else{
renderTiles();
}
var observer=new MutationObserver(function(muts){
muts.forEach(function(m){
if (m.attributeName==='data-theme-view'){
if (document.body.dataset.themeView==='tiles'){
renderTiles();
}else{
clearTiles();
}
}
});
});
observer.observe(document.body,{attributes:true,attributeFilter:['data-theme-view']});
})();