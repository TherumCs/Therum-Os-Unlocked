/* ============================================================================
 * THERUM OS · Theme 02 — Header injection (N2 Financial Dashboard)
 * Injects the real DOM the N2 layout needs that isn't in the base admin:
 *   Row 1 right cluster: + button → avatar(photo) → name/role → search-icon
 *   Row 2 left: date pill (NN + weekday,month | Show my Tasks CTA | calendar)
 *   Row 2 right: greeting + AI prompt input + mic
 * Runs only when body.theme-m02 is set. Idempotent. Re-runs on class change.
 * Server data arrives via window.THERUM_M02_HEADER.
 * ========================================================================== */
(function () {
  'use strict';

  function svg(path, w) {
    w = w || 22;
    return '<svg xmlns="http://www.w3.org/2000/svg" width="' + w + '" height="' + w +
      '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" ' +
      'stroke-linecap="round" stroke-linejoin="round">' + path + '</svg>';
  }

  var ICONS = {
    plus:  svg('<line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>', 26),
    arrow: svg('<line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/>', 18),
    cal:   svg('<rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>', 22),
    mic:   svg('<rect x="9" y="2" width="6" height="12" rx="3"/><path d="M5 10v2a7 7 0 0 0 14 0v-2"/><line x1="12" y1="19" x2="12" y2="22"/>', 24),
  };

  function el(tag, attrs, html) {
    var n = document.createElement(tag);
    if (attrs) for (var k in attrs) {
      if (k === 'class') n.className = attrs[k];
      else if (k === 'style') n.style.cssText = attrs[k];
      else n.setAttribute(k, attrs[k]);
    }
    if (html != null) n.innerHTML = html;
    return n;
  }

  function buildCluster(data) {
    var wrap = el('div', { class: 'th-m02-cluster' });

    var plus = el('a', {
      class: 'th-m02-plus',
      href: data.newPostUrl || '#',
      'aria-label': 'New post',
    }, ICONS.plus);
    wrap.appendChild(plus);

    var user = el('div', { class: 'th-m02-user' });
    // Encode the avatar URL and wrap in url("…") so a URL containing a literal
    // quote can't break out of the style attribute. encodeURI preserves the
    // legal URL chars including / : ? &.
    var avEl = el('div', {
      class: 'th-m02-avatar' + (data.avatar ? ' has-photo' : ''),
    }, data.avatar ? '' : (data.initial || 'U'));
    if (data.avatar) {
      avEl.style.backgroundImage = 'url("' + encodeURI(String(data.avatar)).replace(/"/g, '%22') + '")';
    }
    user.appendChild(avEl);
    if (data.name) {
      // Build via DOM nodes — no innerHTML for server-supplied values.
      var info = el('div', { class: 'th-m02-userinfo' });
      var nameEl = el('div', { class: 'th-m02-name' });
      var roleEl = el('div', { class: 'th-m02-role' });
      nameEl.textContent = data.name;
      roleEl.textContent = data.role || '';
      info.appendChild(nameEl);
      info.appendChild(roleEl);
      user.appendChild(info);
    }
    wrap.appendChild(user);

    return wrap;
  }

  function buildDatePill(data) {
    var now = new Date();
    var day = now.getDate();
    var weekday = now.toLocaleDateString(undefined, { weekday: 'short' });
    var month = now.toLocaleDateString(undefined, { month: 'long' });

    var pill = el('div', { class: 'th-m02-datepill' });
    pill.appendChild(el('div', { class: 'th-m02-date-num' }, String(day)));
    pill.appendChild(el('div', { class: 'th-m02-date-meta' },
      '<span>' + weekday + ',</span><span>' + month + '</span>'
    ));
    pill.appendChild(el('div', { class: 'th-m02-divider' }));
    pill.appendChild(el('a', {
      class: 'th-m02-tasks',
      href: data.tasksUrl || '#',
    }, '<span>Show my Tasks</span>' + ICONS.arrow));
    pill.appendChild(el('a', {
      class: 'th-m02-cal',
      href: data.calendarUrl || '#',
      'aria-label': 'Calendar',
    }, ICONS.cal));
    return pill;
  }

  function buildPrompt() {
    var wrap = el('div', { class: 'th-m02-prompt' });
    wrap.appendChild(el('div', { class: 'th-m02-prompt-greet' },
      'Hey, Need help?<span class="th-m02-prompt-wave">👋</span>'
    ));
    var form = el('form', { class: 'th-m02-prompt-form', role: 'search' });
    var input = el('input', {
      class: 'th-m02-prompt-input',
      type: 'text',
      placeholder: 'Just ask me anything!',
      'aria-label': 'Ask the assistant',
      autocomplete: 'off',
    });
    form.appendChild(input);
    wrap.appendChild(form);
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var q = input.value.trim();
      if (!q) return;
      var main = document.getElementById('th-sb-search-input');
      if (main) {
        main.value = q;
        main.focus();
        main.dispatchEvent(new Event('input', { bubbles: true }));
        main.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true }));
        return;
      }
      window.location.href = '/wp-admin/edit.php?s=' + encodeURIComponent(q);
    });
    return wrap;
  }

  function buildMic() {
    var btn = el('button', {
      class: 'th-m02-mic',
      type: 'button',
      'aria-label': 'Voice search',
    }, ICONS.mic);
    btn.addEventListener('click', function () {
      var SR = window.SpeechRecognition || window.webkitSpeechRecognition;
      if (!SR) { btn.title = 'Voice input not supported in this browser'; return; }
      var input = document.querySelector('.th-m02-prompt-input') ||
                  document.getElementById('th-sb-search-input');
      if (!input) return;
      var r = new SR();
      r.lang = (navigator.language || 'en-US');
      r.interimResults = false;
      r.maxAlternatives = 1;
      btn.classList.add('is-recording');
      r.onresult = function (e) {
        input.value = e.results[0][0].transcript;
        input.focus();
        input.dispatchEvent(new Event('input', { bubbles: true }));
      };
      r.onerror = function () { btn.classList.remove('is-recording'); };
      r.onend = function () { btn.classList.remove('is-recording'); };
      r.start();
    });
    return btn;
  }

  function applyTitleStack(data) {
    // "Site name / current page title" — replaces the default "name / host"
    // pattern with the reference's "Brand / Section" stack.
    var name = document.querySelector('.th-site-name');
    var host = document.querySelector('.th-site-host');
    if (name && data.siteName) name.textContent = data.siteName;
    if (host && data.pageTitle) host.textContent = data.pageTitle;
    // Site icon → logo background (falls back to the initial letter).
    var logo = document.querySelector('.th-logo');
    if (logo && data.siteIcon) {
      logo.style.backgroundImage = 'url(' + data.siteIcon + ')';
      logo.style.backgroundSize = 'cover';
      logo.style.backgroundPosition = 'center';
      logo.style.color = 'transparent';
    }
  }

  function inject() {
    if (!document.body.classList.contains('theme-m02')) return;
    if (document.body.dataset.m02HeaderInjected === '1') return;

    var sb = document.getElementById('th-sb');
    var top = document.getElementById('th-top');
    // Topbar is required; row 2 is optional (some admin pages may not render it).
    if (!sb) return;

    var data = window.THERUM_M02_HEADER || {};

    applyTitleStack(data);

    var search = sb.querySelector('.th-sb-search');
    var cluster = buildCluster(data);
    if (search) sb.insertBefore(cluster, search);
    else sb.appendChild(cluster);

    if (top) {
      top.insertBefore(buildDatePill(data), top.firstChild);
      top.appendChild(buildPrompt());
      top.appendChild(buildMic());
    }

    document.body.dataset.m02HeaderInjected = '1';
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', inject);
  } else {
    inject();
  }

  // Live theme switch (Therum swaps body classes without reload).
  var mo = new MutationObserver(function () { inject(); });
  mo.observe(document.body, { attributes: true, attributeFilter: ['class'] });
})();
