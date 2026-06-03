(function() {
var lp = document.querySelector('.th-lp');
if (!lp) return;
var state = {
filter: 'all',
search: '',
sort: ((lp.querySelector('.th-lp-sort-item.selected') || {}).dataset || {}).sort || '',
view: ((lp.querySelector('.th-lp-view-btn.active') || {}).dataset || {}).view || 'grid'
};
function applyAll() {
var nodes = lp.querySelectorAll('[data-search]');
var visible = 0;
var query = state.search.toLowerCase();
nodes.forEach(function(n) {
var status = n.dataset.status || '';
var search = (n.dataset.search || '').toLowerCase();
var pass = true;
if (state.filter !== 'all') {
var pill = lp.querySelector('.th-lp-pill[data-filter="' + state.filter + '"]');
if (pill && pill.dataset.filterFlag) {
pass = (n.dataset[pill.dataset.filterFlag] === '1');
} else {
pass = (status === state.filter);
}
}
if (pass && query) pass = search.indexOf(query) !== -1;
n.style.display = pass ? '' : 'none';
if (pass) visible++;
});
var noresults = lp.querySelector('.th-lp-noresults');
if (noresults && nodes.length > 0) noresults.style.display = visible === 0 ? '' : 'none';
applySort();
}
function applySort() {
if (!state.sort) return;
var match = /^(.+?)-(asc|desc)$/.exec(state.sort);
if (!match) return;
var key = match[1], dir = match[2] === 'asc' ? 1 : -1;
var dataKey = 'sort' + key.charAt(0).toUpperCase() + key.slice(1);
['grid','table'].forEach(function(view) {
var container = lp.querySelector('[data-view-pane="' + view + '"]');
if (!container) return;
var rows = Array.prototype.slice.call(container.querySelectorAll(view === 'table' ? 'tbody [data-search]' : '[data-search]'));
if (rows.length === 0) return;
rows.sort(function(a, b) {
var av = a.dataset[dataKey] || '';
var bv = b.dataset[dataKey] || '';
var an = parseFloat(av), bn = parseFloat(bv);
if (!isNaN(an) && !isNaN(bn)) return (an - bn) * dir;
return av.localeCompare(bv) * dir;
});
var parent = view === 'table' ? container.querySelector('tbody') : container;
if (!parent) return;
rows.forEach(function(r) { parent.appendChild(r); });
});
}
lp.addEventListener('click', function(e) {
var pill = e.target.closest('.th-lp-pill');
if (pill) {
lp.querySelectorAll('.th-lp-pill').forEach(function(p){ p.classList.remove('active'); });
pill.classList.add('active');
state.filter = pill.dataset.filter;
applyAll();
return;
}
var sortBox = e.target.closest('.th-lp-sort');
if (sortBox && !e.target.closest('.th-lp-sort-item')) {
sortBox.classList.toggle('open');
return;
}
var sortItem = e.target.closest('.th-lp-sort-item');
if (sortItem) {
lp.querySelectorAll('.th-lp-sort-item').forEach(function(i){ i.classList.remove('selected'); });
sortItem.classList.add('selected');
state.sort = sortItem.dataset.sort;
var label = lp.querySelector('.th-lp-sort-label');
if (label) label.textContent = sortItem.textContent.trim();
lp.querySelector('.th-lp-sort').classList.remove('open');
applyAll();
return;
}
var viewBtn = e.target.closest('.th-lp-view-btn');
if (viewBtn) {
lp.querySelectorAll('.th-lp-view-btn').forEach(function(v){ v.classList.remove('active'); });
viewBtn.classList.add('active');
state.view = viewBtn.dataset.view;
lp.querySelectorAll('.th-lp-view').forEach(function(v){
v.classList.toggle('active', v.dataset.viewPane === state.view);
});
}
});
document.addEventListener('click', function(e) {
if (!e.target.closest('.th-lp-sort')) {
var s = lp.querySelector('.th-lp-sort');
if (s) s.classList.remove('open');
}
});
var input = lp.querySelector('.th-lp-search-input');
if (input) {
input.addEventListener('input', function() {
state.search = this.value.trim();
applyAll();
});
}
applySort();
var openKebab = null;
function closeOpenKebab() {
if (openKebab) {
var menu = openKebab.querySelector('.th-lp-kebab-menu');
if (menu) {
menu.style.top = '';
menu.style.bottom = '';
menu.style.left = '';
menu.style.right = '';
}
openKebab.removeAttribute('data-open');
openKebab = null;
}
}
function flipMenuIfNeeded(kebab) {
var btn = kebab.querySelector('[data-th-kebab-btn]');
var menu = kebab.querySelector('.th-lp-kebab-menu');
if (!btn || !menu) return;
var br = btn.getBoundingClientRect();
var mh = menu.offsetHeight;
var mw = menu.offsetWidth;
if (br.bottom + mh + 12 > window.innerHeight) {
menu.style.top = 'auto';
menu.style.bottom = 'calc(100% + 4px)';
}
var menuLeft = br.right - mw;
if (menuLeft < 8) {
menu.style.right = 'auto';
menu.style.left = '0';
}
}
lp.addEventListener('click', function(e) {
var btn = e.target.closest('[data-th-kebab-btn]');
if (btn) {
e.preventDefault();
e.stopPropagation();
var kebab = btn.closest('[data-th-kebab]');
if (kebab === openKebab) {
closeOpenKebab();
} else {
closeOpenKebab();
kebab.setAttribute('data-open', '');
openKebab = kebab;
requestAnimationFrame(function() { flipMenuIfNeeded(kebab); });
}
return;
}
var copyBtn = e.target.closest('[data-th-copy]');
if (copyBtn) {
e.preventDefault();
var text = copyBtn.getAttribute('data-th-copy') || '';
var label = copyBtn.querySelector('span');
var orig = label ? label.textContent : '';
var done = function(ok) {
if (label) { label.textContent = ok ? 'Copied!' : 'Copy failed'; setTimeout(function(){ label.textContent = orig; }, 1200); }
closeOpenKebab();
};
if (navigator.clipboard && navigator.clipboard.writeText) {
navigator.clipboard.writeText(text).then(function(){ done(true); }, function(){ done(false); });
} else {
try {
var ta = document.createElement('textarea');
ta.value = text; ta.style.position = 'fixed'; ta.style.opacity = '0';
document.body.appendChild(ta); ta.select();
done(document.execCommand('copy'));
document.body.removeChild(ta);
} catch (err) { done(false); }
}
return;
}
if (e.target.closest('.th-lp-kebab-item')) {
closeOpenKebab();
return;
}
if (!e.target.closest('[data-th-kebab]')) {
closeOpenKebab();
}
});
document.addEventListener('click', function(e) {
if (!e.target.closest('[data-th-kebab]')) closeOpenKebab();
});
document.addEventListener('keydown', function(e) {
if (e.key === 'Escape') closeOpenKebab();
});
window.addEventListener('resize', closeOpenKebab);
})();