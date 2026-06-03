(function() {
'use strict';
const SIZES = {
'xs': { cols: 1, rows: 1, label: '1×1' },
'sm': { cols: 2, rows: 1, label: '2×1' },
'md': { cols: 3, rows: 1, label: '3×1' },
'lg': { cols: 4, rows: 1, label: 'Full' },
'sm-square': { cols: 2, rows: 2, label: '2×2' },
'md-tall': { cols: 2, rows: 3, label: '2×3' },
'lg-tall': { cols: 3, rows: 2, label: '3×2' },
'xl-hero': { cols: 4, rows: 2, label: '4×2' },
};
const SIZE_KEYS = Object.keys(SIZES);
const NONCE = therumShellData.nonce;
const THEME_NONCE = therumShellData.themeNonce;
const SB_NONCE = therumShellData.sbNonce;
const AJAX = therumShellData.ajaxUrl;
(function() {
const nav = document.querySelector('.th-sb-nav');
if (!nav) return;
const active = nav.querySelector('.th-sb-item.active, .th-sb-child.active');
if (!active) return;
const navRect = nav.getBoundingClientRect();
const itemRect = active.getBoundingClientRect();
if (itemRect.top < navRect.top || itemRect.bottom > navRect.bottom) {
active.scrollIntoView({ block: 'center' });
}
})();
const TH_SB_COLLAPSED_KEY = 'therum_sb_collapsed_v1';
function thSbReadCollapsed() {
try { return JSON.parse(localStorage.getItem(TH_SB_COLLAPSED_KEY) || '[]'); }
catch (e) { return []; }
}
function thSbWriteCollapsed(list) {
try { localStorage.setItem(TH_SB_COLLAPSED_KEY, JSON.stringify(list)); } catch (e) {}
}
function thSbSectionKey(sec) {
return sec.dataset.id
|| (sec.querySelector('.th-sb-section-label')?.textContent || '').trim().toLowerCase()
|| '';
}
(function() {
const collapsed = new Set(thSbReadCollapsed());
document.querySelectorAll('.th-sb-section').forEach(sec => {
if (collapsed.has(thSbSectionKey(sec))) sec.classList.add('collapsed');
});
})();
document.querySelectorAll('[data-toggle-section]').forEach(toggle => {
toggle.addEventListener('click', () => {
const sec = toggle.closest('.th-sb-section');
if (!sec) return;
sec.classList.toggle('collapsed');
const collapsed = thSbReadCollapsed();
const key = thSbSectionKey(sec);
const idx = collapsed.indexOf(key);
if (sec.classList.contains('collapsed')) {
if (idx === -1) collapsed.push(key);
} else if (idx !== -1) {
collapsed.splice(idx, 1);
}
thSbWriteCollapsed(collapsed);
});
});
document.querySelectorAll('[data-toggle-children]').forEach(chev => {
chev.addEventListener('click', e => {
e.preventDefault();
e.stopPropagation();
const wrap = chev.closest('.th-sb-itemwrap');
if (wrap) wrap.classList.toggle('expanded');
});
});
const sbSearch = document.getElementById('th-sb-search-input');
if (sbSearch) {
sbSearch.addEventListener('input', e => {
const q = e.target.value.trim().toLowerCase();
document.querySelectorAll('.th-sb-itemwrap').forEach(wrap => {
const parentTxt = (wrap.querySelector('.th-sb-item')?.textContent || '').toLowerCase();
const kids = wrap.querySelectorAll('.th-sb-child');
let kidHit = false;
kids.forEach(k => {
const t = k.textContent.trim().toLowerCase();
const hit = !q || t.includes(q);
k.style.display = hit ? '' : 'none';
if (q && hit) kidHit = true;
});
const parentHit = !q || parentTxt.includes(q);
wrap.style.display = (parentHit || kidHit) ? '' : 'none';
if (q && kidHit) wrap.classList.add('expanded');
});
document.querySelectorAll('.th-sb-nav > .th-sb-item').forEach(it => {
const txt = it.textContent.trim().toLowerCase();
it.style.display = (!q || txt.includes(q)) ? '' : 'none';
});
});
}
(function initSidebarEdit() {
const nav = document.querySelector('.th-sb-nav');
const editBtn = document.getElementById('th-edit-sb-btn');
const doneBtn = document.getElementById('th-sb-done');
const resetBtn = document.getElementById('th-sb-reset');
const addBtn = document.getElementById('th-add-section');
if (!nav || !editBtn) return;
const setEdit = (on) => {
document.body.classList.toggle('th-edit-sb', on);
editBtn.classList.toggle('is-active', on);
nav.querySelectorAll('.th-sb-section, .th-sb-itemwrap').forEach(el => {
if (on) el.setAttribute('draggable', 'true');
else el.removeAttribute('draggable');
});
};
editBtn.addEventListener('click', () => setEdit(!document.body.classList.contains('th-edit-sb')));
nav.addEventListener('click', e => {
if (!document.body.classList.contains('th-edit-sb')) return;
if (e.target.closest('[data-sb-grip]')) { e.preventDefault(); e.stopPropagation(); return; }
const link = e.target.closest('a.th-sb-item, a.th-sb-child');
if (link && !e.target.closest('[data-sb-rename], [data-sb-delete], [data-toggle-children]')) {
e.preventDefault();
}
}, true);
nav.addEventListener('click', e => {
const renameBtn = e.target.closest('[data-sb-rename]');
const delBtn = e.target.closest('[data-sb-delete]');
if (renameBtn) {
e.preventDefault(); e.stopPropagation();
const sec = renameBtn.closest('.th-sb-section');
const nameEl = sec.querySelector('.th-sb-section-name');
const current = nameEl.textContent.trim();
const next = prompt('Section name:', current);
if (next !== null && next.trim()) nameEl.textContent = next.trim().toUpperCase();
return;
}
if (delBtn) {
e.preventDefault(); e.stopPropagation();
const sec = delBtn.closest('.th-sb-section');
if (!confirm('Delete this section? Its items move to "More".')) return;
let more = nav.querySelector('.th-sb-section[data-section-id="more"]');
if (!more) {
more = document.createElement('div');
more.className = 'th-sb-section';
more.setAttribute('data-section-id', 'more');
more.setAttribute('draggable', 'true');
more.innerHTML = sec.querySelector('.th-sb-section-label').outerHTML.replace(/>([A-Z][^<]*)</, '>MORE<')
+ '<div class="th-sb-section-items"></div>';
nav.insertBefore(more, addBtn);
}
const moreItems = more.querySelector('.th-sb-section-items');
sec.querySelectorAll('.th-sb-itemwrap').forEach(it => moreItems.appendChild(it));
sec.remove();
}
});
if (addBtn) addBtn.addEventListener('click', () => {
const label = (prompt('New section name:', 'New Section') || '').trim();
if (!label) return;
const id = 'custom-' + Date.now().toString(36);
const sec = document.createElement('div');
sec.className = 'th-sb-section';
sec.setAttribute('data-section-id', id);
sec.setAttribute('draggable', 'true');
sec.innerHTML = `
<div class="th-sb-section-label">
<span class="th-sb-grip" data-sb-grip="section" title="Drag to reorder"><svg width="12" height="12" viewBox="0 0 24 24" fill="currentColor"><circle cx="9" cy="6" r="1.5"/><circle cx="15" cy="6" r="1.5"/><circle cx="9" cy="12" r="1.5"/><circle cx="15" cy="12" r="1.5"/><circle cx="9" cy="18" r="1.5"/><circle cx="15" cy="18" r="1.5"/></svg></span>
<span class="th-sb-section-toggle" data-toggle-section>
<span class="th-sb-section-name">${escapeHtml(label.toUpperCase())}</span>
<span class="chev"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg></span>
</span>
<button type="button" class="th-sb-section-rename" data-sb-rename title="Rename"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg></button>
<button type="button" class="th-sb-section-x" data-sb-delete title="Delete"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
</div>
<div class="th-sb-section-items"></div>
`;
nav.insertBefore(sec, addBtn);
sec.querySelector('[data-toggle-section]').addEventListener('click', () => sec.classList.toggle('collapsed'));
sec.scrollIntoView({ behavior: 'smooth', block: 'center' });
});
let dragKind = null;
let dragEl = null;
nav.addEventListener('dragstart', e => {
if (!document.body.classList.contains('th-edit-sb')) { e.preventDefault(); return; }
const item = e.target.closest('.th-sb-itemwrap');
const sec = e.target.closest('.th-sb-section');
if (item && nav.contains(item)) {
dragKind = 'item';
dragEl = item;
} else if (sec && nav.contains(sec)) {
dragKind = 'section';
dragEl = sec;
} else {
return;
}
e.dataTransfer.effectAllowed = 'move';
try { e.dataTransfer.setData('text/plain', dragKind); } catch (_) {}
setTimeout(() => dragEl && dragEl.classList.add('dragging'), 0);
});
nav.addEventListener('dragend', () => {
clearDropMarkers();
stopAutoScroll();
if (dragEl) dragEl.classList.remove('dragging');
dragKind = null; dragEl = null;
});
function clearDropMarkers() {
nav.querySelectorAll('.drop-before, .drop-after, .drop-into').forEach(el => {
el.classList.remove('drop-before', 'drop-after', 'drop-into');
});
}
const SCROLL_EDGE = 48;
const SCROLL_SPEED = 12;
let scrollRAF = null;
function autoScroll(clientY) {
const r = nav.getBoundingClientRect();
let dy = 0;
if (clientY < r.top + SCROLL_EDGE) dy = -SCROLL_SPEED;
else if (clientY > r.bottom - SCROLL_EDGE) dy = SCROLL_SPEED;
if (!dy) {
if (scrollRAF) { cancelAnimationFrame(scrollRAF); scrollRAF = null; }
return;
}
const tick = () => {
nav.scrollTop += dy;
scrollRAF = requestAnimationFrame(tick);
};
if (!scrollRAF) scrollRAF = requestAnimationFrame(tick);
}
function stopAutoScroll() {
if (scrollRAF) { cancelAnimationFrame(scrollRAF); scrollRAF = null; }
}
nav.addEventListener('dragover', e => {
if (!dragEl) return;
e.preventDefault();
e.dataTransfer.dropEffect = 'move';
autoScroll(e.clientY);
clearDropMarkers();
if (dragKind === 'section') {
const sec = e.target.closest('.th-sb-section');
if (!sec || sec === dragEl) return;
const r = sec.getBoundingClientRect();
const mid = r.top + r.height / 2;
sec.classList.add(e.clientY < mid ? 'drop-before' : 'drop-after');
} else if (dragKind === 'item') {
const overItem = e.target.closest('.th-sb-itemwrap');
const overSec = e.target.closest('.th-sb-section');
if (overItem && overItem !== dragEl) {
const r = overItem.getBoundingClientRect();
const mid = r.top + r.height / 2;
overItem.classList.add(e.clientY < mid ? 'drop-before' : 'drop-after');
} else if (overSec) {
overSec.classList.add('drop-into');
}
}
});
nav.addEventListener('drop', e => {
if (!dragEl) return;
e.preventDefault();
if (dragKind === 'section') {
const before = nav.querySelector('.th-sb-section.drop-before');
const after = nav.querySelector('.th-sb-section.drop-after');
if (before) before.parentNode.insertBefore(dragEl, before);
else if (after) after.parentNode.insertBefore(dragEl, after.nextSibling);
} else if (dragKind === 'item') {
const before = nav.querySelector('.th-sb-itemwrap.drop-before');
const after = nav.querySelector('.th-sb-itemwrap.drop-after');
const into = nav.querySelector('.th-sb-section.drop-into');
if (before) before.parentNode.insertBefore(dragEl, before);
else if (after) after.parentNode.insertBefore(dragEl, after.nextSibling);
else if (into) into.querySelector('.th-sb-section-items').appendChild(dragEl);
}
clearDropMarkers();
});
function snapshotLayout() {
const sections = [];
const items = {};
nav.querySelectorAll('.th-sb-section').forEach(sec => {
const id = sec.dataset.sectionId;
if (!id) return;
const label = (sec.querySelector('.th-sb-section-name')?.textContent || id).trim();
sections.push({ id, label, icon: 'settings' });
items[id] = Array.from(sec.querySelectorAll('.th-sb-itemwrap'))
.map(el => el.dataset.itemId).filter(Boolean);
});
return { v: 1, sections, items };
}
function saveSidebar() {
const fd = new FormData();
fd.append('action', 'therum_save_sidebar');
fd.append('nonce', SB_NONCE);
fd.append('layout', JSON.stringify(snapshotLayout()));
return fetch(AJAX, { method: 'POST', credentials: 'same-origin', body: fd });
}
if (doneBtn) doneBtn.addEventListener('click', async () => {
await saveSidebar();
setEdit(false);
});
if (resetBtn) resetBtn.addEventListener('click', async () => {
if (!confirm('Reset sidebar to defaults? Your custom sections will be lost.')) return;
const fd = new FormData();
fd.append('action', 'therum_reset_sidebar');
fd.append('nonce', SB_NONCE);
await fetch(AJAX, { method: 'POST', credentials: 'same-origin', body: fd });
location.reload();
});
function escapeHtml(s) {
return s.replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}
})();
const themeBtn = document.getElementById('th-theme-toggle');
if (themeBtn) {
themeBtn.addEventListener('click', () => {
const isLight = !document.body.classList.contains('light');
document.body.classList.toggle('light', isLight);
document.documentElement.classList.toggle('light', isLight);
const fd = new FormData();
fd.append('action', 'therum_save_state_field');
fd.append('field', 'mode');
fd.append('value', isLight ? 'light' : 'dark');
fd.append('nonce', THEME_NONCE);
fetch(AJAX, { method: 'POST', credentials: 'same-origin', body: fd });
});
}
const editBtn = document.getElementById('th-edit-layout-btn');
const editDone = document.getElementById('th-edit-done');
const editReset = document.getElementById('th-edit-reset');
const bento = document.getElementById('th-bento');
if (editBtn && bento) {
editBtn.addEventListener('click', () => document.body.classList.add('th-edit-layout'));
if (editDone) editDone.addEventListener('click', () => {
document.body.classList.remove('th-edit-layout');
document.querySelectorAll('.th-size-picker.open').forEach(p => p.classList.remove('open'));
saveLayout();
});
if (editReset) editReset.addEventListener('click', () => {
if (!confirm('Reset dashboard to default layout?')) return;
bento.querySelectorAll('.th-card').forEach(c => {
const id = c.dataset.bentoId;
const def = (id && id.startsWith('stat-')) ? 'xs' : (id === 'activity' ? 'md' : 'xs');
c.dataset.size = def;
});
saveLayout();
});
}
function saveLayout() {
const layout = Array.from(bento.querySelectorAll('.th-card')).map(c => ({
id: c.dataset.bentoId, size: c.dataset.size || 'xs'
}));
const fd = new FormData();
fd.append('action', 'therum_save_layout');
fd.append('nonce', NONCE);
fd.append('layout', JSON.stringify(layout));
fetch(AJAX, { method: 'POST', body: fd, credentials: 'same-origin' });
}
function flipResize(card, newSize) {
const cards = Array.from(bento.querySelectorAll('.th-card'));
const first = new Map();
cards.forEach(c => first.set(c, c.getBoundingClientRect()));
card.dataset.size = newSize;
requestAnimationFrame(() => {
cards.forEach(c => {
const f = first.get(c);
const l = c.getBoundingClientRect();
const dx = f.left - l.left, dy = f.top - l.top;
const dw = f.width / l.width, dh = f.height / l.height;
if (Math.abs(dx) < 1 && Math.abs(dy) < 1 && Math.abs(dw - 1) < .01 && Math.abs(dh - 1) < .01) return;
c.style.transformOrigin = 'top left';
c.style.transition = 'none';
c.style.transform = 'translate(' + dx + 'px,' + dy + 'px) scale(' + dw + ',' + dh + ')';
requestAnimationFrame(() => {
c.style.transition = 'transform .4s cubic-bezier(.32,.72,0,1)';
c.style.transform = '';
setTimeout(() => { c.style.transition = ''; c.style.transformOrigin = ''; }, 400);
});
});
});
}
document.querySelectorAll('[data-size-picker]').forEach(btn => {
btn.addEventListener('click', e => {
e.stopPropagation();
const wrap = btn.closest('.th-size-picker');
const card = btn.closest('.th-card');
document.querySelectorAll('.th-size-picker.open').forEach(p => { if (p !== wrap) p.classList.remove('open'); });
wrap.classList.toggle('open');
if (wrap.classList.contains('open')) {
const menu = wrap.querySelector('.th-size-picker-menu');
const cur = card.dataset.size || 'xs';
menu.innerHTML = '<div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--tx3);margin-bottom:8px;">Size</div>' +
'<div class="th-size-grid">' +
SIZE_KEYS.map(k => '<button class="th-size-opt' + (k === cur ? ' active' : '') + '" data-pick="' + k + '">' + SIZES[k].label + '</button>').join('') +
'</div>';
menu.querySelectorAll('[data-pick]').forEach(opt => {
opt.addEventListener('click', ev => {
ev.stopPropagation();
flipResize(card, opt.dataset.pick);
wrap.classList.remove('open');
saveLayout();
});
});
}
});
});
document.addEventListener('click', e => {
if (!e.target.closest('.th-size-picker')) {
document.querySelectorAll('.th-size-picker.open').forEach(p => p.classList.remove('open'));
}
});
(function setupBentoDragReorder() {
if (!bento) return;
let dragCard = null;
let placeholder = null;
bento.addEventListener('mousedown', function(e) {
if (!document.body.classList.contains('th-edit-layout')) return;
if (e.target.closest('[data-resize]')) return;
if (e.target.closest('.th-size-picker')) return;
const card = e.target.closest('.th-card[data-bento-id]');
if (!card) return;
e.preventDefault();
dragCard = card;
card.classList.add('dragging');
placeholder = document.createElement('div');
placeholder.className = 'th-bento-placeholder';
placeholder.dataset.size = card.dataset.size || 'xs';
card.parentNode.insertBefore(placeholder, card);
const rect = card.getBoundingClientRect();
card.style.position = 'fixed';
card.style.left = rect.left + 'px';
card.style.top = rect.top + 'px';
card.style.width = rect.width + 'px';
card.style.height = rect.height + 'px';
card.style.zIndex = '9999';
card.style.pointerEvents = 'none';
card.style.transition = 'none';
card.dataset.dragOffsetX = (e.clientX - rect.left);
card.dataset.dragOffsetY = (e.clientY - rect.top);
document.addEventListener('mousemove', onDragMove);
document.addEventListener('mouseup', onDragEnd);
});
function onDragMove(e) {
if (!dragCard) return;
const ox = parseFloat(dragCard.dataset.dragOffsetX) || 0;
const oy = parseFloat(dragCard.dataset.dragOffsetY) || 0;
dragCard.style.left = (e.clientX - ox) + 'px';
dragCard.style.top = (e.clientY - oy) + 'px';
dragCard.style.display = 'none';
const under = document.elementFromPoint(e.clientX, e.clientY);
dragCard.style.display = '';
if (!under) return;
const overCard = under.closest('.th-card[data-bento-id]');
if (!overCard || overCard === placeholder) return;
const rect = overCard.getBoundingClientRect();
const before = (e.clientY - rect.top) < rect.height / 2;
const parent = overCard.parentNode;
if (before) {
parent.insertBefore(placeholder, overCard);
} else {
parent.insertBefore(placeholder, overCard.nextSibling);
}
}
function onDragEnd() {
document.removeEventListener('mousemove', onDragMove);
document.removeEventListener('mouseup', onDragEnd);
if (!dragCard) return;
dragCard.style.position = '';
dragCard.style.left = '';
dragCard.style.top = '';
dragCard.style.width = '';
dragCard.style.height = '';
dragCard.style.zIndex = '';
dragCard.style.pointerEvents = '';
dragCard.style.transition = '';
if (placeholder && placeholder.parentNode) {
placeholder.parentNode.insertBefore(dragCard, placeholder);
placeholder.parentNode.removeChild(placeholder);
}
dragCard.classList.remove('dragging');
dragCard = null;
placeholder = null;
saveLayout();
}
})();
const HANDLE_POSITIONS = ['nw','n','ne','w','e','sw','s','se'];
function injectResizeHandles(card) {
if (card.querySelector('.th-rh')) return;
HANDLE_POSITIONS.forEach(pos => {
const h = document.createElement('span');
h.className = 'th-rh th-rh-' + pos;
h.dataset.handle = pos;
card.appendChild(h);
});
}
function removeResizeHandles(card) {
card.querySelectorAll('.th-rh').forEach(h => h.remove());
}
function refreshAllHandles() {
const on = document.body.classList.contains('th-edit-layout');
document.querySelectorAll('.th-bento .th-card').forEach(card => {
if (on) injectResizeHandles(card);
else removeResizeHandles(card);
});
}
if (editBtn) editBtn.addEventListener('click', () => setTimeout(refreshAllHandles, 10));
if (editDone) editDone.addEventListener('click', () => setTimeout(refreshAllHandles, 10));
let resizeState = null;
document.addEventListener('mousedown', e => {
if (!document.body.classList.contains('th-edit-layout')) return;
const handle = e.target.closest('.th-rh');
if (!handle) return;
e.preventDefault();
e.stopPropagation();
const card = handle.closest('.th-card');
if (!card) return;
const rect = card.getBoundingClientRect();
resizeState = {
card, handle: handle.dataset.handle,
startX: e.clientX, startY: e.clientY,
startW: rect.width, startH: rect.height,
initialSize: card.dataset.size || 'xs',
};
document.body.classList.add('th-resizing');
});
document.addEventListener('mousemove', e => {
if (!resizeState) return;
const dx = e.clientX - resizeState.startX;
const dy = e.clientY - resizeState.startY;
const h = resizeState.handle;
let wDelta = 0, hDelta = 0;
if (h.includes('e')) wDelta = dx;
if (h.includes('w')) wDelta = -dx;
if (h.includes('s')) hDelta = dy;
if (h.includes('n')) hDelta = -dy;
const card = resizeState.card;
const gridGap = 12;
const bentoEl = document.getElementById('th-bento');
if (!bentoEl) return;
const SIZE_GRID_COLS = 4;
const colWidth = (bentoEl.clientWidth - gridGap * (SIZE_GRID_COLS - 1)) / SIZE_GRID_COLS;
const targetW = resizeState.startW + wDelta;
const targetCols = Math.max(1, Math.min(SIZE_GRID_COLS, Math.round(targetW / colWidth)));
const targetH = resizeState.startH + hDelta;
const isTall = targetH > 220;
let newSize;
if (targetCols === 1) newSize = 'xs';
else if (targetCols === 2 && !isTall) newSize = 'sm';
else if (targetCols === 2 && isTall) newSize = 'md-tall';
else if (targetCols === 3 && !isTall) newSize = 'md';
else if (targetCols === 3 && isTall) newSize = 'lg-tall';
else if (targetCols === 4 && !isTall) newSize = 'lg';
else if (targetCols === 4 && isTall) newSize = 'xl-hero';
else newSize = card.dataset.size || 'xs';
if (newSize !== card.dataset.size) flipResize(card, newSize);
});
document.addEventListener('mouseup', () => {
if (!resizeState) return;
resizeState = null;
document.body.classList.remove('th-resizing');
saveLayout();
});
refreshAllHandles();
document.querySelectorAll('.notice').forEach(n => {
if (!n.classList.contains('therum-keep')) n.style.display = 'none';
});
})();