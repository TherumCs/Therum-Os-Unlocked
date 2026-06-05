/* ─────────────────────────────────────────────────────────────────────────
 *  THERUM OS — CUSTOMIZATION admin page · interactions
 *
 *  Wires up the Themes page Quick Controls panel (collapsible groups,
 *  segmented buttons, swatches, sliders, toggles), the theme store card
 *  actions (star/hide/apply), the saved-theme delete confirm flow, and
 *  the search filter. AJAX persistence to Therum_Themes::set_state() lands
 *  in Phase 5/6; this file ships the UX layer.
 *  ─────────────────────────────────────────────────────────────────────── */

document.addEventListener('DOMContentLoaded', function () {

	// Collapsible groups in the Quick Controls panel
	document.querySelectorAll('.th-cx-group-head').forEach(function (head) {
		head.addEventListener('click', function () {
			head.closest('.th-cx-group').classList.toggle('is-collapsed');
		});
	});

	// Panel foot buttons — Reset / Random / Save
	document.querySelectorAll('.th-cx-foot-btn').forEach(function (b) {
		b.addEventListener('click', function () {
			var act = b.dataset.act;
			var root = document.documentElement;
			var body = document.body;
			if (act === 'reset') {
				var qcPrefixes = ['intensity-','density-','sb-','sb-variant-','tb-','content-','glass-tint-','bg-','shadow-','tracking-','lh-','radius-','bw-','card-','motion-','contrast-','list-view-','card-layout-','thumb-','editor-'];
				var qcFlags = ['glass-surfaces','no-page-trans','no-hover-lift','reduce-transparency','underline-links','focus-rings','large-targets','show-grips','show-shortcuts','auto-save','debug','light'];
				Array.prototype.slice.call(body.classList).forEach(function (c) {
					for (var i = 0; i < qcPrefixes.length; i++) { if (c.indexOf(qcPrefixes[i]) === 0) { body.classList.remove(c); return; } }
					if (qcFlags.indexOf(c) !== -1) body.classList.remove(c);
				});
				['--ac','--ac-h','--ac-s','--bg','--sf','--sf2','--sf3','--sf4','--tx','--tx2','--tx3','--tx4','--bd','--bd2','--bd3','--accent','--bento-gap','--blur-strength','--t-base','--e','--e-speed','--items-per-page','--f','--fd','--fm'].forEach(function (p) {
					root.style.removeProperty(p);
					body.style.removeProperty(p);
				});
				// Also persist the reset to user_meta
				var cxNonce2 = (document.querySelector('[data-th-cx]') || {}).dataset.nonce || '';
				if (cxNonce2) {
					var rfd = new FormData();
					rfd.append('action', 'therum_reset_theme');
					rfd.append('nonce', cxNonce2);
					fetch(window.ajaxurl || '/wp-admin/admin-ajax.php', {
						method: 'POST', credentials: 'same-origin', body: rfd
					}).then(function () {
						setTimeout(function () { location.reload(); }, 350);
					});
				}
				if (window.therumToast) window.therumToast('Reset to defaults');
			} else if (act === 'random') {
				var cards = document.querySelectorAll('.th-cx-theme[data-theme]');
				if (cards.length) {
					var pick = cards[Math.floor(Math.random() * cards.length)];
					pick.click();
					if (window.therumToast) window.therumToast('Random theme: ' + (pick.dataset.theme || 'applied'));
				}
			} else if (act === 'save') {
				// Collect all Quick Control values from data-th-state-field rows
				// and batch-save them to user_meta via AJAX.
				var fields = {};
				var controlsPanel = document.querySelector('[data-th-cx-controls]');
				if (controlsPanel) {
					// Segmented buttons
					controlsPanel.querySelectorAll('[data-th-state-field]').forEach(function (row) {
						var field = row.getAttribute('data-th-state-field');
						if (!field) return;
						// Segment: active button's data-value
						var activeSeg = row.querySelector('.th-cx-seg button.is-active');
						if (activeSeg) { fields[field] = activeSeg.dataset.value; return; }
						// Toggle: is-on state
						var toggle = row.querySelector('.th-cx-toggle-sw');
						if (toggle) { fields[field] = toggle.classList.contains('is-on') ? '1' : '0'; return; }
						// Slider: input value
						var slider = row.querySelector('.th-cx-slider');
						if (slider) { fields[field] = slider.value; return; }
						// Select: selected value
						var select = row.querySelector('.th-cx-select');
						if (select) { fields[field] = select.value; return; }
						// Swatch: active swatch's data-color
						var activeSwatch = row.querySelector('.th-cx-swatch.is-active');
						if (activeSwatch && activeSwatch.dataset.color) { fields[field] = activeSwatch.dataset.color; return; }
					});
				}
				var fieldCount = Object.keys(fields).length;
				if (fieldCount === 0) {
					if (window.therumToast) window.therumToast('Nothing to save');
					return;
				}
				b.disabled = true;
				b.textContent = 'Saving...';
				var cxNonce = (document.querySelector('[data-th-cx]') || {}).dataset.nonce || '';
				var fd = new FormData();
				fd.append('action', 'therum_save_theme_batch');
				fd.append('fields', JSON.stringify(fields));
				fd.append('nonce', cxNonce);
				fetch(window.ajaxurl || '/wp-admin/admin-ajax.php', {
					method: 'POST',
					credentials: 'same-origin',
					body: fd
				})
					.then(function (r) { return r.json(); })
					.then(function (res) {
						b.disabled = false;
						b.textContent = '💾 Save';
						if (res && res.success) {
							var count = (res.data && res.data.saved) ? res.data.saved.length : fieldCount;
							if (window.therumToast) window.therumToast(count + ' setting' + (count !== 1 ? 's' : '') + ' saved');
						} else {
							if (window.therumToast) window.therumToast('Save failed: ' + ((res.data && res.data.message) || 'unknown error'));
						}
					})
					.catch(function () {
						b.disabled = false;
						b.textContent = '💾 Save';
						if (window.therumToast) window.therumToast('Network error — could not save');
					});
			}
		});
	});

	// Theme store / Saved tab switch
	document.querySelectorAll('.th-cx-themes-tabs').forEach(function (tabs) {
		var panes = tabs.parentElement.querySelector('.th-cx-themes-panes');
		if (!panes) return;
		tabs.querySelectorAll('.th-cx-themes-tab').forEach(function (btn) {
			btn.addEventListener('click', function () {
				var target = btn.dataset.themesTab;
				tabs.querySelectorAll('.th-cx-themes-tab').forEach(function (b) {
					b.classList.toggle('is-active', b === btn);
					b.setAttribute('aria-selected', b === btn ? 'true' : 'false');
				});
				panes.setAttribute('data-themes-active', target);
				panes.querySelectorAll('.th-cx-themes-pane').forEach(function (p) {
					p.classList.toggle('is-active', p.dataset.themesPane === target);
				});
			});
		});
	});

	// Helper: swap a prefixed class on body (still used for layout controls
	// where a structural class is the right tool — sidebar, variant, motion,
	// list view). Visual / perceptual controls below use CSS-var writes
	// instead because they're guaranteed to take effect: every component
	// already consumes vars like --bg / --tx / --sf / --bd, so setting them
	// on documentElement bypasses any specificity or CSS-coverage issues.
	function thSetPrefixed(prefix, value) {
		var body = document.body;
		Array.prototype.slice.call(body.classList).forEach(function (c) {
			if (c.indexOf(prefix) === 0) body.classList.remove(c);
		});
		if (value) body.classList.add(prefix + value);
	}
	function thSetVar(name, value) {
		if (value == null || value === '') document.documentElement.style.removeProperty(name);
		else document.documentElement.style.setProperty(name, value);
	}
	function thSetVars(map) { Object.keys(map).forEach(function (k) { thSetVar(k, map[k]); }); }

	// Token sets for the modes we ship by default. Light/dark flip the
	// surface + text + border vars directly on :root so the change is
	// immediate, regardless of which palette class is on body.
	var TH_MODES = {
		light: { '--bg':'#fafafa','--sf':'#ffffff','--sf2':'#f5f5f5','--tx':'#0a0a0a','--tx2':'#4a4a4a','--tx3':'#8a8a8a','--bd':'rgba(0,0,0,.08)','--bd2':'rgba(0,0,0,.18)' },
		dark:  { '--bg':'#0a0a0a','--sf':'#141414','--sf2':'#1a1a1a','--tx':'#fafafa','--tx2':'#a0a0a0','--tx3':'#6a6a6a','--bd':'rgba(255,255,255,.10)','--bd2':'rgba(255,255,255,.20)' }
	};
	function thApplyMode(mode) {
		var key = mode;
		if (mode === 'auto') {
			var prefersLight = window.matchMedia && window.matchMedia('(prefers-color-scheme: light)').matches;
			key = prefersLight ? 'light' : 'dark';
		}
		var set = TH_MODES[key];
		if (!set) return;
		thSetVars(set);
		document.body.classList.toggle('light', key === 'light');
	}

	// Label → segment handler map.
	var TH_SEG_RULES = {
		'mode': function (v) { thApplyMode(v); },
		'intensity': function (v) {
			var maps = { subtle: '6%', standard: '12%', vivid: '22%' };
			thSetVar('--ac-s', 'color-mix(in srgb, var(--ac) ' + (maps[v] || '12%') + ', transparent)');
			thSetPrefixed('intensity-', v);
		},
		'density': function (v) {
			// Persist class for components that key off it; also nudge gutter vars.
			var maps = { compact: { '--row-pad':'10px','--nav-pad-y':'6px','--nav-pad-x':'16px' },
			             comfortable: { '--row-pad':'16px','--nav-pad-y':'10px','--nav-pad-x':'20px' },
			             breathing: { '--row-pad':'28px','--nav-pad-y':'18px','--nav-pad-x':'26px' } };
			thSetPrefixed('density-', v);
			if (maps[v]) thSetVars(maps[v]);
		},
		'sidebar':       function (v) { thSetPrefixed('th-sl-', v); },
		'variant':       function (v) { thSetPrefixed('th-sb-', v); },
		'topbar':        function (v) { thSetPrefixed('tb-', v); },
		'content':       function (v) { thSetPrefixed('content-', v); },
		'glass tint':    function (v) { thSetPrefixed('glass-tint-', v); },
		'background':    function (v) { thSetPrefixed('bg-', v === 'none' ? '' : v); },
		'shadow': function (v) {
			var maps = {
				flat:  'none',
				soft:  '0 1px 0 rgba(0,0,0,.02), 0 4px 14px rgba(0,0,0,.05)',
				heavy: '0 1px 0 rgba(0,0,0,.04), 0 14px 38px rgba(0,0,0,.16)'
			};
			thSetVar('--th-shadow-card', maps[v] || maps.soft);
			thSetPrefixed('shadow-', v);
		},
		'letter spacing': function (v) {
			var maps = { tight: '-0.01em', normal: '0em', loose: '0.02em' };
			thSetVar('--th-tracking', maps[v] || '0em');
			thSetPrefixed('tracking-', v === 'normal' ? '' : v);
		},
		'line height': function (v) {
			var maps = { tight: '1.3', standard: '1.5', relaxed: '1.65' };
			thSetVar('--th-lh', maps[v] || '1.5');
			thSetPrefixed('lh-', v === 'standard' ? '' : v);
		},
		'radius': function (v) {
			var maps = {
				sharp:  { '--radius-sm':'2px',  '--radius-md':'4px',  '--radius-lg':'6px'  },
				medium: { '--radius-sm':'6px',  '--radius-md':'10px', '--radius-lg':'14px' },
				round:  { '--radius-sm':'10px', '--radius-md':'16px', '--radius-lg':'22px' }
			};
			if (maps[v]) thSetVars(maps[v]);
			thSetPrefixed('radius-', v);
		},
		'border weight': function (v) {
			var maps = { hairline: '0.5px', standard: '1px', bold: '2px' };
			thSetVar('--bd-width', maps[v] || '1px');
			thSetPrefixed('bw-', v === 'standard' ? '' : v);
		},
		'card style':    function (v) { thSetPrefixed('card-', v); },
		'motion':        function (v) { thSetPrefixed('motion-', v === 'full' ? '' : v); },
		'transition speed': function (v) {
			var map = { instant: '.05s', snappy: '.1s', standard: '.18s', slow: '.32s' };
			thSetVar('--e-speed', map[v] || '.18s');
			thSetVar('--e', (map[v] || '.18s') + ' ease');
		},
		'contrast':      function (v) { thSetPrefixed('contrast-', v === 'standard' ? '' : v); },
		'list view':     function (v) { thSetPrefixed('list-view-', v); }
	};

	function thRowLabel(el) {
		var row = el.closest('.th-cx-row');
		if (!row) return '';
		var lbl = row.querySelector('.th-cx-label');
		var raw = lbl ? (lbl.childNodes[0] && lbl.childNodes[0].textContent) || lbl.textContent : '';
		return raw.replace(/\s+/g, ' ').trim().toLowerCase();
	}

	// Segmented controls — exclusive toggle within each .th-cx-seg + mapped effect
	document.querySelectorAll('.th-cx-seg').forEach(function (seg) {
		seg.querySelectorAll('button').forEach(function (btn) {
			btn.addEventListener('click', function () {
				seg.querySelectorAll('button.is-active').forEach(function (o) { o.classList.remove('is-active'); });
				btn.classList.add('is-active');
				var row = seg.closest('.th-cx-row');
				var val = row && row.querySelector('.th-cx-label-val');
				if (val) val.textContent = btn.dataset.value || btn.textContent.toLowerCase();
				var handler = TH_SEG_RULES[thRowLabel(btn)];
				if (handler) handler(btn.dataset.value);
			});
		});
	});

	// Toggle pills — flip is-on + apply mapped body class
	var TH_TOGGLE_RULES = {
		'glass surfaces':             { cls: 'glass-surfaces' },
		'page transitions':           { cls: 'no-page-trans', invert: true },
		'card hover lift':            { cls: 'no-hover-lift', invert: true },
		'reduce transparency':        { cls: 'reduce-transparency' },
		'underline links':            { cls: 'underline-links' },
		'focus rings always visible': { cls: 'focus-rings' },
		'larger click targets':       { cls: 'large-targets' },
		'show sidebar grip handles':  { cls: 'show-grips' },
		'show keyboard shortcuts':    { cls: 'show-shortcuts' },
		'auto-save layout changes':   { cls: 'auto-save' },
		'debug overlays (dev only)':  { cls: 'debug' }
	};
	function thToggleLabel(el) {
		var wrap = el.closest('.th-cx-toggle');
		if (!wrap) return '';
		var lbl = wrap.querySelector('.th-cx-toggle-label');
		return lbl ? lbl.textContent.trim().toLowerCase() : '';
	}
	document.querySelectorAll('.th-cx-toggle-sw').forEach(function (sw) {
		// Sync initial state to body class
		var cfg0 = TH_TOGGLE_RULES[thToggleLabel(sw)];
		if (cfg0 && cfg0.cls) {
			var on0 = sw.classList.contains('is-on');
			var apply0 = cfg0.invert ? !on0 : on0;
			document.body.classList.toggle(cfg0.cls, apply0);
		}
		sw.addEventListener('click', function () {
			sw.classList.toggle('is-on');
			var cfg = TH_TOGGLE_RULES[thToggleLabel(sw)];
			if (cfg && cfg.cls) {
				var on = sw.classList.contains('is-on');
				var apply = cfg.invert ? !on : on;
				document.body.classList.toggle(cfg.cls, apply);
			}
		});
	});

	// Color swatches — exclusive within each group + apply --ac/--ac-h/--ac-s/--accent to body
	function thHexToRgb(c) {
		if (!c || c[0] !== '#') return null;
		var h = c.slice(1);
		if (h.length === 3) h = h[0]+h[0]+h[1]+h[1]+h[2]+h[2];
		if (h.length !== 6) return null;
		return { r: parseInt(h.slice(0,2),16), g: parseInt(h.slice(2,4),16), b: parseInt(h.slice(4,6),16) };
	}
	function thLighten(hex, t) {
		var rgb = thHexToRgb(hex);
		if (!rgb) return hex;
		return 'rgb(' + Math.round(rgb.r + (255-rgb.r)*t) + ',' + Math.round(rgb.g + (255-rgb.g)*t) + ',' + Math.round(rgb.b + (255-rgb.b)*t) + ')';
	}
	function thAlpha(hex, a) {
		var rgb = thHexToRgb(hex);
		if (!rgb) return hex;
		return 'rgba(' + rgb.r + ',' + rgb.g + ',' + rgb.b + ',' + a + ')';
	}
	document.querySelectorAll('.th-cx-swatches').forEach(function (group) {
		group.querySelectorAll('.th-cx-swatch').forEach(function (sw) {
			sw.addEventListener('click', function () {
				group.querySelectorAll('.th-cx-swatch.is-active').forEach(function (o) { o.classList.remove('is-active'); });
				sw.classList.add('is-active');
				var row = group.closest('.th-cx-row');
				var val = row && row.querySelector('.th-cx-label-val');
				var hex = sw.dataset.color;
				if (val && hex) val.textContent = hex;
				if (hex) {
					document.body.style.setProperty('--ac', hex);
					document.body.style.setProperty('--ac-h', thLighten(hex, 0.18));
					document.body.style.setProperty('--ac-s', thAlpha(hex, 0.12));
					document.body.style.setProperty('--accent', hex);
				}
			});
		});
	});

	// Sliders — live readout + write to mapped CSS var
	var TH_SLIDER_RULES = {
		'bento gap':     { name: '--bento-gap',     unit: 'px' },
		'blur strength': { name: '--blur-strength', unit: 'px' },
		'base size':     { name: '--t-base',        unit: 'px' },
		'items / page':  { name: '--items-per-page', unit: '' }
	};
	document.querySelectorAll('.th-cx-slider').forEach(function (slider) {
		var num = slider.parentElement.querySelector('.th-cx-slider-num');
		var label = slider.closest('.th-cx-row');
		var val = label && label.querySelector('.th-cx-label-val');
		var rowLbl = thRowLabel(slider);
		slider.addEventListener('input', function () {
			if (num) num.textContent = slider.value;
			if (val) val.textContent = slider.value;
			var cfg = TH_SLIDER_RULES[rowLbl];
			if (cfg && cfg.name) document.body.style.setProperty(cfg.name, slider.value + (cfg.unit || ''));
		});
	});

	// Selects — write to mapped CSS var (fonts)
	var TH_SELECT_RULES = {
		'body font':           { name: '--f' },
		'display font':        { name: '--fd' },
		'mono font':           { name: '--fm' },
		'card layout':         { classPrefix: 'card-layout-' },
		'thumbnail source':    { classPrefix: 'thumb-' },
		'code editor theme':   { classPrefix: 'editor-' }
	};
	var TH_FONT_MAP = {
		'inter': "'Inter',sans-serif",
		'inter-tight': "'Inter Tight','Inter',sans-serif",
		'space-grotesk': "'Space Grotesk','Inter',sans-serif",
		'dm-sans': "'DM Sans','Inter',sans-serif",
		'ibm-plex': "'IBM Plex Sans','Inter',sans-serif",
		'crimson': "'Crimson Pro',Georgia,serif",
		'playfair': "'Playfair Display',Georgia,serif",
		'halyard': "'Halyard Display','Inter',sans-serif",
		'archivo-black': "'Archivo Black','Inter Tight',sans-serif",
		'bebas': "'Bebas Neue','Inter Tight',sans-serif",
		'audiowide': "'Audiowide','Inter Tight',sans-serif",
		'orbitron': "'Orbitron','Inter Tight',sans-serif",
		'caveat': "'Caveat',cursive",
		'system': "-apple-system,BlinkMacSystemFont,sans-serif",
		'jetbrains': "'JetBrains Mono',ui-monospace,monospace",
		'ibm-plex-mono': "'IBM Plex Mono',ui-monospace,monospace",
		'sf-mono': "'SF Mono',ui-monospace,monospace",
		'vt323': "'VT323',ui-monospace,monospace"
	};
	document.querySelectorAll('.th-cx-select').forEach(function (sel) {
		sel.addEventListener('change', function () {
			var row = sel.closest('.th-cx-row');
			var val = row && row.querySelector('.th-cx-label-val');
			if (val) val.textContent = sel.options[sel.selectedIndex] ? sel.options[sel.selectedIndex].text : sel.value;
			var cfg = TH_SELECT_RULES[thRowLabel(sel)];
			if (!cfg) return;
			if (cfg.name) {
				document.body.style.setProperty(cfg.name, TH_FONT_MAP[sel.value] || sel.value);
			} else if (cfg.classPrefix) {
				thSetPrefixed(cfg.classPrefix, sel.value);
			}
		});
	});

	// Theme card actions + card-body click. Apply persists to user_meta via
	// Therum_Themes::ajax_apply_preset, then reloads so palette CSS +
	// !important overrides take effect (same pattern as therum-settings.js).
	var thCxRoot   = document.querySelector('[data-th-cx]');
	var thCxNonce  = thCxRoot ? (thCxRoot.dataset.nonce || '') : '';
	var thCxAjax   = window.ajaxurl || '/wp-admin/admin-ajax.php';

	function thCxApplyPreset(preset, originBtn) {
		if (!preset) return;
		document.querySelectorAll('.th-cx-theme.is-active').forEach(function (o) { o.classList.remove('is-active'); });
		var card = document.querySelector('.th-cx-theme[data-theme="' + preset + '"]');
		if (card) card.classList.add('is-active');
		if (originBtn) originBtn.disabled = true;
		var fd = new FormData();
		fd.append('action', 'therum_apply_preset');
		fd.append('preset', preset);
		fd.append('nonce', thCxNonce);
		return fetch(thCxAjax, { method: 'POST', credentials: 'same-origin', body: fd })
			.then(function (r) { return r.json(); })
			.then(function (res) {
				if (res && res.success) {
					if (window.therumToast) window.therumToast('Theme applied');
					setTimeout(function () { location.reload(); }, 300);
				} else {
					if (originBtn) originBtn.disabled = false;
					if (window.therumToast) window.therumToast('Could not apply theme');
				}
			})
			.catch(function () { if (originBtn) originBtn.disabled = false; });
	}

	document.querySelectorAll('.th-cx-theme-action').forEach(function (btn) {
		btn.addEventListener('click', function (e) {
			e.stopPropagation();
			var card = btn.closest('.th-cx-theme');
			var act  = btn.dataset.act;
			if (act === 'star') {
				btn.classList.toggle('is-on');
				card.classList.toggle('is-saved', btn.classList.contains('is-on'));
			} else if (act === 'hide') {
				card.classList.toggle('is-hidden');
			} else if (act === 'apply') {
				thCxApplyPreset(card && card.dataset.theme, btn);
			}
		});
	});

	// Clicking the card body (not the inner action buttons) also applies
	// the theme. Most users go for the whole tile, not the small ✓.
	document.querySelectorAll('.th-cx-theme[data-theme]').forEach(function (card) {
		card.addEventListener('click', function (e) {
			if (e.target.closest('.th-cx-theme-action')) return; // action button handled above
			if (card.classList.contains('is-active')) return;     // already active
			thCxApplyPreset(card.dataset.theme, null);
		});
		card.style.cursor = 'pointer';
	});

	// Quick Controls persistence — every segment / swatch / toggle / slider /
	// select gets a debounced save via Therum_Themes::ajax_save_field. The
	// row's data-th-state-field attribute (set by the panel renderer) is the
	// key in default_state(); we only persist whitelisted fields.
	// Small status indicator so the user has visible feedback that Quick
	// Controls actually persist (live preview is instant; AJAX save is the
	// part that was previously invisible — and so users assumed broken).
	function thCxFlash(text, isErr) {
		var el = document.querySelector('[data-th-cx-flash]');
		if (!el) {
			el = document.createElement('div');
			el.setAttribute('data-th-cx-flash', '');
			el.style.cssText = 'position:fixed;right:18px;bottom:18px;z-index:99999;background:var(--sf,#fff);color:var(--tx,#0a0a0a);border:1px solid var(--bd,rgba(0,0,0,.08));border-left:3px solid var(--ok,#10b981);border-radius:8px;padding:10px 14px;font:500 12px/1.3 var(--f,-apple-system,sans-serif);box-shadow:0 6px 22px rgba(0,0,0,.12);opacity:0;transform:translateY(6px);transition:opacity .18s ease,transform .18s ease';
			document.body.appendChild(el);
		}
		el.style.borderLeftColor = isErr ? (getComputedStyle(document.documentElement).getPropertyValue('--err') || '#ef4444') : (getComputedStyle(document.documentElement).getPropertyValue('--ok') || '#10b981');
		el.textContent = text;
		requestAnimationFrame(function () { el.style.opacity = '1'; el.style.transform = 'translateY(0)'; });
		clearTimeout(thCxFlash._t);
		thCxFlash._t = setTimeout(function () { el.style.opacity = '0'; el.style.transform = 'translateY(6px)'; }, 1400);
	}

	// Save path. Click-driven controls (segs / toggles / swatches / selects)
	// post immediately — the previous 220ms debounce was eating saves when
	// the user clicked a Quick Control and immediately navigated via the
	// sidebar. Only slider input gets debounced, since drag streams emit
	// dozens of `input` events per second.
	//
	// Network safety:
	//   • `keepalive: true` lets the request survive page unload, so a
	//     click + immediate sidebar navigation still completes the POST.
	//   • A `pagehide` flush via sendBeacon catches any pending slider-
	//     debounce save that didn't fire in time.
	var thCxSliderTimer = {};
	var thCxPending     = {}; // field → value awaiting flush

	function thCxFire(field, value) {
		delete thCxPending[field];
		var fd = new FormData();
		fd.append('action', 'therum_save_theme_field');
		fd.append('field', field);
		fd.append('value', value);
		fd.append('nonce', thCxNonce);
		fetch(thCxAjax, {
			method: 'POST',
			credentials: 'same-origin',
			body: fd,
			keepalive: true, // survives page unload — critical to the sidebar-nav bug
		})
			.then(function (r) { return r.json(); })
			.then(function (res) {
				if (res && res.success) {
					thCxFlash(field + ' · saved');
				} else {
					thCxFlash((res && res.data && res.data.message) || ('Save failed: ' + field), true);
				}
			})
			.catch(function () { thCxFlash('Network error · ' + field, true); });
	}

	function thCxSaveField(field, value, opts) {
		if (!field) return;
		opts = opts || {};
		thCxPending[field] = value;
		if (opts.debounce) {
			clearTimeout(thCxSliderTimer[field]);
			thCxSliderTimer[field] = setTimeout(function () { thCxFire(field, value); }, 180);
		} else {
			thCxFire(field, value);
		}
	}

	// Flush on pagehide — any pending slider-debounce save gets a beacon
	// POST so it lands on the server even if the user mid-click navigates
	// or closes the tab. Uses sendBeacon (no response read, fire-and-forget).
	window.addEventListener('pagehide', function () {
		var keys = Object.keys(thCxPending);
		if (!keys.length || !navigator.sendBeacon) return;
		keys.forEach(function (field) {
			var fd = new FormData();
			fd.append('action', 'therum_save_theme_field');
			fd.append('field', field);
			fd.append('value', thCxPending[field]);
			fd.append('nonce', thCxNonce);
			navigator.sendBeacon(thCxAjax, fd);
		});
	});
	// Bubble-listen on the controls container so dynamically-added rows also save.
	var thCxControls = document.querySelector('[data-th-cx-controls]');
	if (thCxControls) {
		thCxControls.addEventListener('click', function (e) {
			var seg = e.target.closest('.th-cx-seg button[data-value]');
			if (seg) {
				var row = seg.closest('[data-th-state-field]');
				if (row) thCxSaveField(row.getAttribute('data-th-state-field'), seg.dataset.value);
				return;
			}
			var sw = e.target.closest('.th-cx-swatch[data-color]');
			if (sw) {
				var swRow = sw.closest('[data-th-state-field]');
				if (swRow) thCxSaveField(swRow.getAttribute('data-th-state-field'), sw.dataset.color);
				return;
			}
			var toggle = e.target.closest('.th-cx-toggle-sw');
			if (toggle) {
				var tRow = toggle.closest('[data-th-state-field]');
				if (tRow) {
					var tField = tRow.getAttribute('data-th-state-field');
					// Desktop Mode toggle uses its own AJAX endpoint + reloads
					if (tField === 'desktopMode') {
						var fd = new FormData();
						fd.append('action', 'therum_toggle_desktop_mode');
						fd.append('nonce', thCxNonce);
						fetch(thCxAjax, { method: 'POST', credentials: 'same-origin', body: fd })
							.then(function (r) { return r.json(); })
							.then(function (res) {
								if (res && res.success) {
									thCxFlash('Desktop Mode ' + (res.data.active ? 'on' : 'off'));
									setTimeout(function () { location.reload(); }, 400);
								} else {
									thCxFlash('Could not toggle Desktop Mode', true);
									toggle.classList.toggle('is-on'); // revert visual
								}
							});
					} else {
						thCxSaveField(tField, toggle.classList.contains('is-on') ? '1' : '0');
					}
				}
			}
		});
		thCxControls.addEventListener('input', function (e) {
			var slider = e.target.closest('.th-cx-slider');
			if (slider) {
				var sRow = slider.closest('[data-th-state-field]');
				// Sliders fire dozens of input events per drag → debounce.
				if (sRow) thCxSaveField(sRow.getAttribute('data-th-state-field'), slider.value, { debounce: true });
				return;
			}
			var select = e.target.closest('.th-cx-select');
			if (select) {
				var selRow = select.closest('[data-th-state-field]');
				// Select change is a single event — no debounce needed.
				if (selRow) thCxSaveField(selRow.getAttribute('data-th-state-field'), select.value);
			}
		});
	}

	// Hide strips whose cards are all filtered out
	function applyStripVisibility() {
		document.querySelectorAll('[data-cx-store] .th-cx-strip').forEach(function (strip) {
			var visible = Array.prototype.some.call(strip.querySelectorAll('.th-cx-theme'), function (c) {
				return c.style.display !== 'none';
			});
			strip.classList.toggle('is-empty', !visible);
		});
	}

	// Theme store filters
	document.querySelectorAll('.th-cx-store-filter').forEach(function (f) {
		f.addEventListener('click', function () {
			var group = f.parentElement;
			group.querySelectorAll('.th-cx-store-filter.is-active').forEach(function (o) { o.classList.remove('is-active'); });
			f.classList.add('is-active');
			var which = f.dataset.filter || 'all';
			document.querySelectorAll('.th-cx-theme').forEach(function (card) {
				var matches =
					which === 'all' ||
					(which === 'starred' && card.classList.contains('is-saved')) ||
					(which === 'hidden'  && card.classList.contains('is-hidden')) ||
					card.dataset.group === which;
				card.style.display = matches ? '' : 'none';
			});
			applyStripVisibility();
		});
	});

	// Theme search
	var search = document.querySelector('[data-th-cx-theme-search]');
	if (search) {
		search.addEventListener('input', function () {
			var q = search.value.trim().toLowerCase();
			document.querySelectorAll('.th-cx-theme').forEach(function (card) {
				var name = card.dataset.name || '';
				card.style.display = !q || name.indexOf(q) !== -1 ? '' : 'none';
			});
			applyStripVisibility();
		});
	}

	// Density toggle (simple / detailed)
	document.querySelectorAll('[data-cx-store] .th-cx-density-btn').forEach(function (btn) {
		btn.addEventListener('click', function () {
			var store = btn.closest('[data-cx-store]');
			if (!store) return;
			store.querySelectorAll('.th-cx-density-btn').forEach(function (b) { b.classList.remove('is-active'); });
			btn.classList.add('is-active');
			store.setAttribute('data-card-mode', btn.dataset.cardMode || 'simple');
		});
	});

	// Connections page · per-bucket search filter
	document.querySelectorAll('[data-th-cn-search]').forEach(function (input) {
		input.addEventListener('input', function () {
			var q = input.value.trim().toLowerCase();
			document.querySelectorAll('[data-th-cn-card]').forEach(function (card) {
				var name = card.dataset.name || '';
				card.style.display = !q || name.indexOf(q) !== -1 ? '' : 'none';
			});
		});
	});

	// Saved theme delete with two-click confirm
	document.querySelectorAll('.th-cx-saved-del').forEach(function (btn) {
		var armed = false;
		btn.addEventListener('click', function (e) {
			e.stopPropagation();
			if (!armed) {
				btn.style.borderColor = '#ef4444';
				btn.style.background  = 'rgba(239,68,68,.12)';
				btn.style.color       = '#ef4444';
				armed = true;
				setTimeout(function () {
					if (armed) { armed = false; btn.style.cssText = ''; }
				}, 2400);
				return;
			}
			var card = btn.closest('.th-cx-saved');
			card.style.transition = 'all .25s ease';
			card.style.opacity = '0';
			card.style.transform = 'scale(.96)';
			setTimeout(function () { card.remove(); }, 250);
		});
	});
});
