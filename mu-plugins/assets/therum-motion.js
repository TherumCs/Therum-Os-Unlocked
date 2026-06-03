(function(){
'use strict';
var rm=window.matchMedia&&window.matchMedia('(prefers-reduced-motion:reduce)').matches;
if (rm) return;
function initLenis(){
if (typeof Lenis==='undefined') return;
var lenis=new Lenis({
duration:1.0,
easing:function(t){return Math.min(1,1.001-Math.pow(2,-10*t));},
direction:'vertical',
smoothWheel:true,
smoothTouch:false,
touchMultiplier:2,
wheelMultiplier:1,
});
function raf(time){
lenis.raf(time);
requestAnimationFrame(raf);
}
requestAnimationFrame(raf);
document.addEventListener('click',function(e){
if (e.target.closest('select,.wp-editor-area,.CodeMirror,[data-lenis-prevent]')){
lenis.stop();
setTimeout(function(){lenis.start();},100);
}
});
window.therumLenis=lenis;
}
if (document.readyState==='loading'){
document.addEventListener('DOMContentLoaded',initLenis);
}else{
initLenis();
}
function supportsViewTransitions(){
return typeof document.startViewTransition==='function';
}
if (supportsViewTransitions()){
document.addEventListener('click',function(e){
var link=e.target.closest('a');
if (!link) return;
if (e.metaKey||e.ctrlKey||e.shiftKey||e.altKey) return;
if (link.target==='_blank') return;
var href=link.getAttribute('href');
if (!href||href.startsWith('#')||href.startsWith('javascript:')) return;
var isInternal=(
href.indexOf(window.location.origin)===0||
href.startsWith('/')||
href.indexOf(':
);
if (!isInternal) return;
if (link.hasAttribute('download')) return;
if (link.closest('form')) return;
e.preventDefault();
document.startViewTransition(function(){
window.location.href=href;
});
});
}
var magneticSelectors=[
'.btn-primary',
'.th-button-primary',
'.th-pdp-btn-primary',
'.th-lp-empty-cta',
'.editor-strip-btn',
];
function applyMagnetic(el){
var bound=14;
var rect,x,y,cx,cy,dx,dy;
el.addEventListener('mousemove',function(e){
rect=el.getBoundingClientRect();
cx=rect.left+rect.width/2;
cy=rect.top+rect.height/2;
dx=(e.clientX-cx)/(rect.width/2);
dy=(e.clientY-cy)/(rect.height/2);
x=Math.max(-1,Math.min(1,dx))*bound;
y=Math.max(-1,Math.min(1,dy))*bound;
el.style.transform='translate('+x+'px,'+y+'px)';
});
el.addEventListener('mouseleave',function(){
el.style.transform='';
});
}
function bindMagnetic(){
magneticSelectors.forEach(function(sel){
document.querySelectorAll(sel).forEach(function(el){
if (el.dataset.thMag) return;
el.dataset.thMag='1';
applyMagnetic(el);
});
});
}
if (document.readyState==='loading'){
document.addEventListener('DOMContentLoaded',bindMagnetic);
}else{
bindMagnetic();
}
if (typeof MutationObserver!=='undefined'){
new MutationObserver(function(){
bindMagnetic();
}).observe(document.body,{childList:true,subtree:true});
}
if ('IntersectionObserver' in window){
var io=new IntersectionObserver(function(entries){
entries.forEach(function(entry){
if (entry.isIntersecting){
entry.target.classList.add('th-revealed');
io.unobserve(entry.target);
}
});
},{threshold:0.05,rootMargin:'0px 0px-40px 0px'});
document.querySelectorAll('.th-reveal').forEach(function(el){
io.observe(el);
});
}
})();