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
// ─── DRAG-TO-SORT v2 ─────────────────────────────────────────────────────────
// Persists per-user order for any Therum_List_Page with data-th-sortable="1".
// Works in grid + masonry/metro + table views. ID stamped via PHP wrap on each
// card / row (data-th-item-id). AJAX endpoint: therum_save_list_order.
//
// v2: moveBefore() with insertBefore fallback, pulsing drop indicator,
//     spring lift-in animation on dropped element.
(function thListSort() {
  var ajaxUrl = (window.ajaxurl) || '/wp-admin/admin-ajax.php';

  // moveBefore preserves element state (focus, animations, iframes).
  // Falls back to insertBefore on browsers that don't support it yet.
  function walkAndMoveTo(el, parent, ref) {
    if (typeof parent.moveBefore === 'function') {
      try { parent.moveBefore(el, ref); return; } catch (_) {}
    }
    parent.insertBefore(el, ref);
  }

  // Spring lift-in: plays the CSS animation, then cleans up the class.
  function springIn(el) {
    el.classList.add('th-lp-just-dropped');
    el.addEventListener('animationend', function handler() {
      el.classList.remove('th-lp-just-dropped');
      el.removeEventListener('animationend', handler);
    });
  }

  function escId(id) {
    return window.CSS && CSS.escape ? CSS.escape(id) : id;
  }

  document.querySelectorAll('.th-lp[data-th-sortable]').forEach(function (lp) {
    var pageId = lp.getAttribute('data-page-id') || '';
    var nonce  = lp.getAttribute('data-th-sort-nonce') || '';
    if (!pageId || !nonce) return;

    var dragEl = null;

    lp.addEventListener('dragstart', function (e) {
      var src = e.target.closest('[data-th-item-id]');
      if (!src || !lp.contains(src)) return;
      dragEl = src;
      try { e.dataTransfer.effectAllowed = 'move'; e.dataTransfer.setData('text/plain', src.getAttribute('data-th-item-id') || ''); } catch (_) {}
      requestAnimationFrame(function () { if (dragEl) dragEl.classList.add('th-lp-dragging'); });
    });

    lp.addEventListener('dragend', function () {
      if (dragEl) dragEl.classList.remove('th-lp-dragging');
      clearIndicators();
      dragEl = null;
    });

    function clearIndicators() {
      lp.querySelectorAll('.th-lp-drop-before, .th-lp-drop-after').forEach(function (el) {
        el.classList.remove('th-lp-drop-before', 'th-lp-drop-after');
      });
    }

    lp.addEventListener('dragover', function (e) {
      if (!dragEl) return;
      var over = e.target.closest('[data-th-item-id]');
      if (!over || over === dragEl) return;
      if (over.closest('[data-view-pane]') !== dragEl.closest('[data-view-pane]')) return;
      e.preventDefault();
      var rect = over.getBoundingClientRect();
      var horizontal = (over.tagName === 'DIV');
      var mid = horizontal ? rect.left + rect.width / 2 : rect.top + rect.height / 2;
      var pos = horizontal ? e.clientX : e.clientY;
      var before = pos < mid;
      clearIndicators();
      over.classList.add(before ? 'th-lp-drop-before' : 'th-lp-drop-after');
    });

    // Also clear indicators if drag leaves the container entirely.
    lp.addEventListener('dragleave', function (e) {
      if (!dragEl) return;
      // Only clear if the relatedTarget is outside the lp or null (left window).
      if (!e.relatedTarget || !lp.contains(e.relatedTarget)) {
        clearIndicators();
      }
    });

    lp.addEventListener('drop', function (e) {
      if (!dragEl) return;
      var over = e.target.closest('[data-th-item-id]');
      if (!over || over === dragEl) return;
      if (over.closest('[data-view-pane]') !== dragEl.closest('[data-view-pane]')) return;
      e.preventDefault();
      e.stopPropagation();
      clearIndicators();

      var pane = over.parentNode;
      var rect = over.getBoundingClientRect();
      var horizontal = (over.tagName === 'DIV');
      var mid = horizontal ? rect.left + rect.width / 2 : rect.top + rect.height / 2;
      var pos = horizontal ? e.clientX : e.clientY;
      var before = pos < mid;

      // Use moveBefore (preserves state) with insertBefore fallback.
      walkAndMoveTo(dragEl, pane, before ? over : over.nextSibling);

      // Spring lift-in animation on the dropped element.
      springIn(dragEl);

      // Mirror the move into every other view-pane so all views stay in sync.
      var id = dragEl.getAttribute('data-th-item-id');
      var anchorId = over.getAttribute('data-th-item-id');
      lp.querySelectorAll('[data-view-pane]').forEach(function (otherPane) {
        if (otherPane === pane) return;
        var moving = otherPane.querySelector('[data-th-item-id="' + escId(id) + '"]');
        var anchor = otherPane.querySelector('[data-th-item-id="' + escId(anchorId) + '"]');
        if (moving && anchor) {
          walkAndMoveTo(moving, anchor.parentNode, before ? anchor : anchor.nextSibling);
        }
      });

      saveOrder();
    });

    function saveOrder() {
      var pane = lp.querySelector('[data-view-pane="grid"]')
              || lp.querySelector('[data-view-pane]:not([data-view-pane="table"])')
              || lp.querySelector('[data-view-pane]');
      if (!pane) return;
      var ids = Array.prototype.map.call(
        pane.querySelectorAll('[data-th-item-id]'),
        function (el) { return el.getAttribute('data-th-item-id'); }
      );
      var fd = new FormData();
      fd.append('action', 'therum_save_list_order');
      fd.append('page_id', pageId);
      fd.append('nonce', nonce);
      fd.append('order', JSON.stringify(ids));
      fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd, keepalive: true })
        .catch(function () { /* best-effort; next save will retry */ });
    }
  });
})();

// ─── EXPORT FORMAT PICKER ────────────────────────────────────────────────────
// Intercepts [data-th-export] buttons and shows a small dropdown with format
// options (JSON, TXT, Markdown, Bricks). Clicking a format navigates to the
// export URL with &format=xxx appended.
(function thExportPicker() {
  document.querySelectorAll('[data-th-export]').forEach(function (btn) {
    var baseUrl = btn.getAttribute('data-th-export-base') || '';
    if (!baseUrl) return;

    btn.addEventListener('click', function (e) {
      e.preventDefault();
      e.stopPropagation();

      // If menu already open, close it
      var existing = btn.parentElement.querySelector('.th-export-menu');
      if (existing) { existing.remove(); return; }

      // Close any other open export menus
      document.querySelectorAll('.th-export-menu').forEach(function (m) { m.remove(); });

      var formats = [
        { key: 'json',   label: 'JSON',     desc: 'Full data + meta + Bricks elements' },
        { key: 'bricks', label: 'Bricks',   desc: 'Native Bricks format (ZIP of per-page JSON)' },
        { key: 'md',     label: 'Markdown',  desc: 'Content as .md with front-matter table' },
        { key: 'txt',    label: 'Plain text', desc: 'Stripped HTML, readable text' },
      ];

      var menu = document.createElement('div');
      menu.className = 'th-export-menu';
      menu.innerHTML = '<div class="th-export-menu-title">Export format</div>' +
        formats.map(function (f) {
          return '<a class="th-export-menu-item" href="' + baseUrl + '&format=' + f.key + '">' +
            '<span class="th-export-menu-label">' + f.label + '</span>' +
            '<span class="th-export-menu-desc">' + f.desc + '</span>' +
          '</a>';
        }).join('');

      btn.style.position = 'relative';
      btn.parentElement.style.position = 'relative';
      btn.parentElement.appendChild(menu);

      // Close on outside click
      setTimeout(function () {
        document.addEventListener('click', function closer(ev) {
          if (!menu.contains(ev.target) && ev.target !== btn) {
            menu.remove();
            document.removeEventListener('click', closer);
          }
        });
      }, 10);
    });
  });
})();
