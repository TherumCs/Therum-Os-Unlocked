(function(){
// Scope: original .th-settings surface OR the Admin Theme (Customization)
// surface — Appearance/Branding/Site Identity were moved to .th-cx and need
// the same click/save/AJAX bindings.
var settings=document.querySelector('.th-settings, [data-th-cx]');
if (!settings) return;
var ajaxUrl=window.ajaxurl||'/wp-admin/admin-ajax.php';
var nonce=(settings.querySelector('[data-nonce]')||{dataset:{}}).dataset.nonce||'';
// Shared failure handler for persistence fetches — without it a dropped request
// fails silently and the user assumes the setting saved.
function thSettingsError(e){
	console.error('[Therum] Settings save failed:', e);
	if (window.therumToast) window.therumToast('Couldn’t save — check your connection and try again');
}
var searchInput=document.getElementById('th-settings-search-input');
if (searchInput){
settings.querySelectorAll('.th-settings-nav-item span').forEach(function(s){
s.dataset.original=s.textContent;
});
searchInput.addEventListener('input',function(){
var q=this.value.toLowerCase().trim();
settings.querySelectorAll('.th-settings-nav-item').forEach(function(item){
var key=item.dataset.searchKey||'';
var matches=!q||key.indexOf(q)!==-1;
item.style.display=matches?'':'none';
var span=item.querySelector('span');
if (!span) return;
var label=span.dataset.original||span.textContent;
if (q&&matches){
var idx=label.toLowerCase().indexOf(q);
if (idx!==-1){
span.innerHTML=label.slice(0,idx)+
'<mark>'+label.slice(idx,idx+q.length)+'</mark>'+
label.slice(idx+q.length);
return;
}
}
span.textContent=label;
});
});
}
var themeSearch=document.getElementById('th-theme-search-input');
if (themeSearch){
themeSearch.addEventListener('input',function(){
var q=this.value.toLowerCase().trim();
var totalVisible=0;
settings.querySelectorAll('.th-theme-group').forEach(function(group){
var visibleInGroup=0;
group.querySelectorAll('.th-theme-card').forEach(function(card){
var match=!q||(card.dataset.search||'').indexOf(q)!==-1;
card.classList.toggle('hidden',!match);
if (match) visibleInGroup++;
});
group.classList.toggle('empty',visibleInGroup===0);
totalVisible+=visibleInGroup;
});
var none=settings.querySelector('.th-theme-no-results');
if (none) none.style.display=(totalVisible===0&&q)?'':'none';
});
}
settings.querySelectorAll('[data-th-carousel]').forEach(function(track){
var group=track.closest('.th-theme-group');
var head=track.parentElement.querySelector('.th-theme-carousel-controls')||(group&&group.querySelector('.th-theme-carousel-controls'));
var prev=head?head.querySelector('[data-th-carousel-prev]'):null;
var next=head?head.querySelector('[data-th-carousel-next]'):null;
var dotsContainer=group?group.querySelector('[data-th-carousel-dots]'):null;
function buildDots(){
if (!dotsContainer) return;
dotsContainer.innerHTML='';
var cards=track.children.length;
if (cards<=1) return;
var firstCard=track.children[0];
var cardWidth=firstCard?firstCard.offsetWidth+14:234;
var perPage=Math.max(1,Math.floor(track.clientWidth/cardWidth));
var pages=Math.max(1,Math.ceil(cards/perPage));
if (pages<=1) return;
for (var i=0;i<pages;i++){
var dot=document.createElement('button');
dot.type='button';
dot.className='th-theme-carousel-dot'+(i===0?' active':'');
dot.setAttribute('aria-label','Page '+(i+1));
dot.dataset.page=i;
dot.addEventListener('click',(function(p){
return function(){track.scrollTo({left:p*track.clientWidth,behavior:'smooth'});};
})(i));
dotsContainer.appendChild(dot);
}
}
function update(){
if (prev) prev.disabled=track.scrollLeft<=4;
if (next) next.disabled=track.scrollLeft+track.clientWidth>=track.scrollWidth-4;
if (dotsContainer){
var dots=dotsContainer.children;
if (dots.length>0){
var pageW=track.clientWidth||1;
var currentPage=Math.round(track.scrollLeft/pageW);
for (var i=0;i<dots.length;i++){
dots[i].classList.toggle('active',i===currentPage);
}
}
}
}
buildDots();
update();
track.addEventListener('scroll',update);
window.addEventListener('resize',function(){buildDots();update();});
setTimeout(function(){buildDots();update();},50);
if (prev) prev.addEventListener('click',function(){track.scrollBy({left:-track.clientWidth,behavior:'smooth'});});
if (next) next.addEventListener('click',function(){track.scrollBy({left:track.clientWidth,behavior:'smooth'});});
});
var tintColorInput=document.getElementById('th-glass-tint-color');
if (tintColorInput){
tintColorInput.addEventListener('input',function(){
var hex=this.value;
var body=document.body;
var h=hex.replace('#','');
if (h.length===3) h=h[0]+h[0]+h[1]+h[1]+h[2]+h[2];
if (h.length===6){
var r=parseInt(h.substr(0,2),16);
var g=parseInt(h.substr(2,2),16);
var b=parseInt(h.substr(4,2),16);
body.style.setProperty('--glass-tint-rgb',r+','+g+','+b);
}
Array.from(body.classList).forEach(function(cls){if (cls.indexOf('glass-tint-')===0) body.classList.remove(cls);});
body.classList.add('glass-tint-color');
clearTimeout(tintColorInput._t);
tintColorInput._t=setTimeout(function(){
var fd=new FormData();
fd.append('action','therum_save_state_field');
fd.append('field','glassTint');
fd.append('value',hex);
fd.append('nonce',nonce);
fetch(ajaxUrl,{method:'POST',credentials:'same-origin',body:fd}).catch(thSettingsError);
},250);
});
}
function applyThemeStateToBody(state){
if (!state) return;
var body=document.body;
var prefixes={
palette:'theme-',
density:'density-',
sidebarStyle:'th-sb-',
sidebarLayout:'th-sl-',
glassTint:'glass-tint-',
shadow:'shadow-',
radius:'radius-',
blur:'blur-',
font:'font-',
cardStyle:'card-',
bgImage:'bg-',
surfaceEffect:'surface-'
};
Object.keys(prefixes).forEach(function(field){
if (state[field]===undefined||state[field]===null||state[field]==='') return;
var p=prefixes[field];
Array.from(body.classList).forEach(function(cls){if (cls.indexOf(p)===0) body.classList.remove(cls);});
var v=state[field];
if (field==='cardStyle'&&v==='default') return;
if (field==='bgImage'&&v==='none') return;
if (field==='surfaceEffect'&&v==='none') return;
body.classList.add(p+v);
});
body.classList.toggle('light',state.mode==='light');
var seGlass=state.surfaceEffect&&state.surfaceEffect.indexOf('glass-')===0;
body.classList.toggle('glass',!!state.glass||seGlass);
Array.from(body.classList).forEach(function(cls){
if (cls.indexOf('glass-tint-')===0||cls.indexOf('bg-')===0) body.classList.remove(cls);
});
var seMap={
'glass-light':'glass-tint-light',
'glass-dark':'glass-tint-dark',
'glass-colored':'glass-tint-color',
'gradient':'bg-gradient',
'blurred':'bg-blurred'
};
if (state.surfaceEffect&&seMap[state.surfaceEffect]){
body.classList.add(seMap[state.surfaceEffect]);
}
body.classList.add('therum-themed');
if (state.accent&&/^#[0-9a-f]{3,8}$/i.test(state.accent)){
var accentStyle=document.getElementById('therum-theme-accent');
if (!accentStyle){
accentStyle=document.createElement('style');
accentStyle.id='therum-theme-accent';
document.head.appendChild(accentStyle);
}
accentStyle.textContent='body.therum-themed{--accent:'+state.accent+';--ac:'+state.accent+';}';
}
var glassTintGroup=document.querySelector('[data-show-when-glass="1"]');
if (glassTintGroup){
var glassActive=!!state.glass||state.palette==='glass';
glassTintGroup.style.display=glassActive?'':'none';
}
Array.from(body.classList).forEach(function(cls){if (cls.indexOf('glass-tint-')===0) body.classList.remove(cls);});
if (state.glassTint&&state.glassTint!=='auto'){
if (state.glassTint==='dark'||state.glassTint==='light'){
body.classList.add('glass-tint-'+state.glassTint);
}else if (/^#[0-9a-f]{3,8}$/i.test(state.glassTint)){
body.classList.add('glass-tint-color');
var h=state.glassTint.replace('#','');
if (h.length===3) h=h[0]+h[0]+h[1]+h[1]+h[2]+h[2];
if (h.length===6){
var r=parseInt(h.substr(0,2),16);
var g=parseInt(h.substr(2,2),16);
var b=parseInt(h.substr(4,2),16);
body.style.setProperty('--glass-tint-rgb',r+','+g+','+b);
}
}
}
}
settings.addEventListener('click',function(e){
var card=e.target.closest('.th-theme-card[data-preset]');
if (card){
var preset=card.dataset.preset;
settings.querySelectorAll('.th-theme-card.active').forEach(function(c){c.classList.remove('active');});
card.classList.add('active');
settings.querySelectorAll('.th-theme-card-active').forEach(function(p){p.remove();});
var nameEl=card.querySelector('.th-theme-card-name');
if (nameEl){
var pill=document.createElement('span');
pill.className='th-theme-card-active';
pill.textContent='Active';
nameEl.appendChild(pill);
}
var fd=new FormData();
fd.append('action','therum_apply_preset');
fd.append('preset',preset);
fd.append('nonce',nonce);
fetch(ajaxUrl,{method:'POST',credentials:'same-origin',body:fd})
.then(function(r){return r.json();})
.then(function(res){
if (res&&res.success&&res.data){
applyThemeStateToBody(res.data);
var fieldMap={'density':'density','sidebar style':'sidebarStyle','sidebar layout':'sidebarLayout','mode':'mode','glass':'glass','glass tint':'glassTint','surface effect':'surfaceEffect','shadow':'shadow','corners':'radius','blur intensity':'blur'};
settings.querySelectorAll('.th-settings-group').forEach(function(grp){
var t=(grp.querySelector('.th-settings-group-title')||{}).textContent||'';
var f=fieldMap[t.trim().toLowerCase()];
if (!f) return;
var v=res.data[f];
if (f==='glass') v=res.data.glass?'true':'false';
if (v===undefined||v===null) return;
grp.querySelectorAll('label.th-radio').forEach(function(label){
var input=label.querySelector('input');
label.classList.toggle('active',input&&String(input.value)===String(v));
});
});
// Reload to fully apply palette CSS + !important overrides that can't be swapped inline.
// Same pattern as the theme reset action.
setTimeout(function(){location.reload();},400);
}
})
.catch(thSettingsError);
return;
}
var dsRow0=e.target.closest('[data-state-field]');
var dsLabel0=e.target.closest('label.th-radio,label.th-card-style-card');
if (dsRow0&&dsLabel0){
e.preventDefault();e.stopPropagation();
var dsField0=dsRow0.getAttribute('data-state-field');
var dsInput0=dsLabel0.querySelector('input');
var dsVal0=dsInput0?dsInput0.value:'';
if (dsField0&&dsVal0){
if (dsInput0) dsInput0.checked=true;
dsRow0.querySelectorAll('label.th-radio,label.th-card-style-card').forEach(function(r){r.classList.remove('active');});
dsLabel0.classList.add('active');
var dsFd0=new FormData();
dsFd0.append('action','therum_save_state_field');
dsFd0.append('field',dsField0);
dsFd0.append('value',dsVal0);
dsFd0.append('nonce',nonce);
fetch(ajaxUrl,{method:'POST',credentials:'same-origin',body:dsFd0})
.then(function(r){return r.json();})
.then(function(res){if(res&&res.success&&res.data) applyThemeStateToBody(res.data);})
.catch(thSettingsError);
}
return;
}
var radioLabel=e.target.closest('label.th-radio');
if (radioLabel){
var groupTitle=(radioLabel.closest('.th-settings-group').querySelector('.th-settings-group-title')||{}).textContent||'';
var map={'density':'density','sidebar style':'sidebarStyle','sidebar layout':'sidebarLayout','mode':'mode','glass':'glass','glass tint':'glassTint','surface effect':'surfaceEffect','shadow':'shadow','corners':'radius','blur intensity':'blur','card layout':'cardLayout','card image':'cardImage'};
var prefix={'density':'density-','sidebarStyle':'th-sb-','sidebarLayout':'th-sl-','glassTint':'glass-tint-','shadow':'shadow-','radius':'radius-','blur':'blur-'};
var field=map[groupTitle.trim().toLowerCase()];
if (field){
e.preventDefault();e.stopPropagation();
var input=radioLabel.querySelector('input');
var val=input?input.value:radioLabel.textContent.trim().toLowerCase();
var group=radioLabel.closest('.th-settings-group');
group.querySelectorAll('label.th-radio').forEach(function(r){r.classList.remove('active');});
radioLabel.classList.add('active');
var body=document.body;
if (field==='mode'){
body.classList.toggle('light',val==='light');
}else if (field==='glass'){
body.classList.toggle('glass',val==='true'||val==='1'||val===true);
}else if (field==='glassTint'){
Array.from(body.classList).forEach(function(cls){if (cls.indexOf('glass-tint-')===0) body.classList.remove(cls);});
if (val!=='auto') body.classList.add('glass-tint-'+val);
var colorRow=document.querySelector('.th-glass-tint-color-row');
if (colorRow) colorRow.style.display=(val==='color')?'':'none';
}else if (field==='surfaceEffect'){
body.classList.remove('glass');
Array.from(body.classList).forEach(function(cls){
if (cls.indexOf('glass-tint-')===0||cls.indexOf('bg-')===0||cls.indexOf('surface-')===0){
body.classList.remove(cls);
}
});
var seMap={
'glass-light':['glass','glass-tint-light','surface-glass-light'],
'glass-dark':['glass','glass-tint-dark','surface-glass-dark'],
'glass-colored':['glass','glass-tint-color','surface-glass-colored'],
'gradient':['bg-gradient','surface-gradient'],
'blurred':['bg-blurred','surface-blurred']
};
if (seMap[val]) seMap[val].forEach(function(c){body.classList.add(c);});
}else if (prefix[field]){
var p=prefix[field];
Array.from(body.classList).forEach(function(cls){if (cls.indexOf(p)===0) body.classList.remove(cls);});
body.classList.add(p+val);
}
var fd=new FormData();
fd.append('action','therum_save_state_field');
fd.append('field',field);
fd.append('value',val);
fd.append('nonce',nonce);
fetch(ajaxUrl,{method:'POST',credentials:'same-origin',body:fd})
.then(function(r){return r.json();})
.then(function(res){if(res&&res.success&&res.data) applyThemeStateToBody(res.data);})
.catch(thSettingsError);
}
}
if (e.target.closest('#th-theme-reset')){
var fd2=new FormData();
fd2.append('action','therum_reset_theme');
fd2.append('nonce',nonce);
fetch(ajaxUrl,{method:'POST',credentials:'same-origin',body:fd2})
.then(function(r){return r.json();})
.then(function(res){
if (res&&res.success&&res.data) applyThemeStateToBody(res.data);
setTimeout(function(){location.reload();},350);
})
.catch(thSettingsError);
}
var viewBtn=e.target.closest('.th-theme-view-btn');
if (viewBtn){
e.preventDefault();e.stopPropagation();
var mode=viewBtn.dataset.view;
var group=viewBtn.closest('.th-settings-group');
if (group){
group.dataset.themeView=mode;
group.querySelectorAll('.th-theme-view-btn').forEach(function(b){b.classList.remove('active');});
viewBtn.classList.add('active');
}
var fd3=new FormData();
fd3.append('action','therum_save_state_field');
fd3.append('field','_pref_theme_view_mode');
fd3.append('value',mode);
fd3.append('nonce',nonce);
var fd4=new FormData();
fd4.append('action','therum_save_view_pref');
fd4.append('mode',mode);
fd4.append('nonce',nonce);
fetch(ajaxUrl,{method:'POST',credentials:'same-origin',body:fd4}).catch(thSettingsError);
}
},true);
document.querySelectorAll('.th-code-copy').forEach(function(btn){
btn.addEventListener('click',function(){
var id=btn.dataset.copyTarget;
var el=document.getElementById(id);
if (!el) return;
navigator.clipboard.writeText(el.textContent.trim()).then(function(){
btn.classList.add('copied');
var orig=btn.textContent;
btn.textContent='Copied!';
setTimeout(function(){btn.classList.remove('copied');btn.textContent=orig;},1500);
});
});
});
var userIniBtn=document.getElementById('th-write-userini');
if (userIniBtn){
userIniBtn.addEventListener('click',function(){
var input=document.querySelector('[data-th-text="th_upload_target_mb"]');
var mb=input?parseInt(input.value,10):parseInt(userIniBtn.dataset.target,10);
if (!mb||mb<1){alert('Enter a target size in MB.');return;}
var msg=document.getElementById('th-userini-msg');
msg.textContent='Writing...';
msg.className='th-userini-msg';
var fd=new FormData();
fd.append('action','therum_write_userini');
fd.append('mb',mb);
var optNonce=document.querySelector('input[name="th_options_nonce"]');
fd.append('nonce',optNonce?optNonce.value:nonce);
fetch(ajaxUrl,{method:'POST',credentials:'same-origin',body:fd})
.then(function(r){return r.json();})
.then(function(res){
if (res&&res.success){
msg.className='th-userini-msg ok';
msg.textContent=res.data.message||'Wrote .user.ini.';
}else{
msg.className='th-userini-msg err';
msg.textContent=(res&&res.data&&res.data.message)||'Write failed.';
}
})
.catch(function(err){
msg.className='th-userini-msg err';
msg.textContent='Network error:'+err.message;
});
});
}
})();