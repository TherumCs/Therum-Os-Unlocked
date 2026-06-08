<?php
/**
 * Plugin Name: Therum OS — Inline Rich-Text Editor
 * Description: Reusable contenteditable + toolbar component. Drop `[data-th-ed]`
 *              into any admin surface (or call Therum_Editor::field() from PHP)
 *              and the editor upgrades on DOMContentLoaded. Self-contained:
 *              one CSS block, one JS block, no jQuery, no external libs.
 * Version: 1.9.27
 *
 * Public API:
 *   Therum_Editor::field( $name, $value, $args = [] )    // echoes the field
 *   Therum_Editor::assets()                              // echoes CSS + JS once
 *
 * Each editor renders a hidden <input> named $name whose value mirrors the
 * sanitized inner HTML on every keystroke — so a parent <form> submission
 * picks up the content without any extra wiring.
 *
 * Kill switch: define( 'THERUM_EDITOR_DISABLE', true ).
 */

if ( ! defined( 'ABSPATH' ) ) exit;
if ( defined( 'THERUM_EDITOR_DISABLE' ) && THERUM_EDITOR_DISABLE ) return;

if ( ! class_exists( 'Therum_Editor' ) ) :

final class Therum_Editor {

	private static bool $assets_printed = false;

	/**
	 * Render a single editor field.
	 *
	 * @param string $name       Hidden input name (also the data-th-ed-name attr).
	 * @param string $value      Initial HTML body. Sanitized through wp_kses_post().
	 * @param array  $args       Options:
	 *                             - placeholder string
	 *                             - min_height int (px)
	 *                             - id        string (DOM id; auto-generated otherwise)
	 *                             - class     string (extra class on the wrapper)
	 *                             - features  array<string> subset of:
	 *                                  ['font','size','b','i','u','color','align','list','link','image']
	 *                                  Defaults to all of the above.
	 */
	public static function field( string $name, string $value = '', array $args = [] ): void {
		self::assets();

		$args = wp_parse_args( $args, [
			'placeholder' => 'Start writing…',
			'min_height'  => 160,
			'id'          => 'thed-' . wp_generate_password( 8, false, false ),
			'class'       => '',
			'features'    => [ 'font','size','b','i','u','color','align','list','link','image' ],
		] );

		$body = wp_kses_post( $value );
		$id   = sanitize_html_class( (string) $args['id'] );
		$cls  = trim( 'thed ' . sanitize_html_class( (string) $args['class'] ) );

		$has = function( string $f ) use ( $args ) {
			return in_array( $f, (array) $args['features'], true );
		};

		?>
		<div class="<?php echo esc_attr( $cls ); ?>" id="<?php echo esc_attr( $id ); ?>" data-th-ed data-th-ed-name="<?php echo esc_attr( $name ); ?>">
			<div class="thed-toolbar" role="toolbar" aria-label="Formatting">
				<?php if ( $has( 'font' ) ): ?>
					<div class="thed-select" data-th-ed-font>
						<button type="button" class="thed-select-btn" aria-haspopup="listbox" aria-expanded="false">
							<span data-th-ed-font-label>System</span>
							<svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 12 15 18 9"/></svg>
						</button>
						<div class="thed-menu" role="listbox" hidden>
							<button type="button" role="option" data-val="system-ui, -apple-system, sans-serif">System</button>
							<button type="button" role="option" data-val="Georgia, 'Times New Roman', serif">Serif</button>
							<button type="button" role="option" data-val="'Inter', -apple-system, sans-serif">Inter</button>
							<button type="button" role="option" data-val="'Poppins', -apple-system, sans-serif">Poppins</button>
							<button type="button" role="option" data-val="ui-monospace, 'JetBrains Mono', Menlo, monospace">Mono</button>
						</div>
					</div>
				<?php endif; ?>

				<?php if ( $has( 'size' ) ): ?>
					<div class="thed-select thed-select-size" data-th-ed-size>
						<button type="button" class="thed-select-btn" aria-haspopup="listbox" aria-expanded="false">
							<span data-th-ed-size-label>16px</span>
							<svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 12 15 18 9"/></svg>
						</button>
						<div class="thed-menu" role="listbox" hidden>
							<?php foreach ( [ 12, 14, 16, 18, 20, 24, 28, 32, 40, 56 ] as $px ): ?>
								<button type="button" role="option" data-val="<?php echo $px; ?>"><?php echo $px; ?>px</button>
							<?php endforeach; ?>
						</div>
					</div>
				<?php endif; ?>

				<?php if ( $has( 'b' ) || $has( 'i' ) || $has( 'u' ) ): ?>
					<span class="thed-divider" aria-hidden="true"></span>
				<?php endif; ?>

				<?php if ( $has( 'b' ) ): ?>
					<button type="button" class="thed-btn" data-th-ed-cmd="bold" aria-label="Bold" title="Bold (⌘B)"><strong>B</strong></button>
				<?php endif; ?>
				<?php if ( $has( 'i' ) ): ?>
					<button type="button" class="thed-btn" data-th-ed-cmd="italic" aria-label="Italic" title="Italic (⌘I)"><em>I</em></button>
				<?php endif; ?>
				<?php if ( $has( 'u' ) ): ?>
					<button type="button" class="thed-btn" data-th-ed-cmd="underline" aria-label="Underline" title="Underline (⌘U)"><u>U</u></button>
				<?php endif; ?>

				<?php if ( $has( 'color' ) ): ?>
					<span class="thed-divider" aria-hidden="true"></span>
					<div class="thed-color" data-th-ed-color>
						<button type="button" class="thed-btn thed-color-btn" aria-label="Text color" aria-haspopup="dialog" aria-expanded="false">
							<span class="thed-color-swatch" data-th-ed-color-swatch style="background:#0F1115"></span>
						</button>
						<div class="thed-color-popover" role="dialog" hidden>
							<div class="thed-color-row">
								<?php foreach ( [ '#0F1115','#1F232A','#3A414A','#5A6068','#7B8390','#9CA3AF','#C2C8D0','#E2E5E9','#F2F4F7','#FFFFFF' ] as $c ): ?>
									<button type="button" class="thed-color-dot" data-val="<?php echo esc_attr( $c ); ?>" style="background:<?php echo esc_attr( $c ); ?>" aria-label="<?php echo esc_attr( $c ); ?>"></button>
								<?php endforeach; ?>
							</div>
							<div class="thed-color-row">
								<?php foreach ( [ '#16A34A','#0EA5E9','#2563EB','#7C3AED','#A855F7','#DB2777','#EF4444','#F0563E','#F59E0B','#84CC16' ] as $c ): ?>
									<button type="button" class="thed-color-dot" data-val="<?php echo esc_attr( $c ); ?>" style="background:<?php echo esc_attr( $c ); ?>" aria-label="<?php echo esc_attr( $c ); ?>"></button>
								<?php endforeach; ?>
							</div>
							<div class="thed-color-custom">
								<span>Custom</span>
								<input type="color" data-th-ed-color-picker value="#2C69F6" aria-label="Custom color">
								<input type="text" data-th-ed-color-hex value="#2C69F6" maxlength="9" aria-label="Hex color">
							</div>
						</div>
					</div>
				<?php endif; ?>

				<?php if ( $has( 'align' ) || $has( 'list' ) ): ?>
					<span class="thed-divider" aria-hidden="true"></span>
				<?php endif; ?>

				<?php if ( $has( 'align' ) ): ?>
					<button type="button" class="thed-btn" data-th-ed-cmd="justifyLeft" aria-label="Align left" title="Align left">
						<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="15" y2="12"/><line x1="3" y1="18" x2="18" y2="18"/></svg>
					</button>
					<button type="button" class="thed-btn" data-th-ed-cmd="justifyCenter" aria-label="Align center" title="Align center">
						<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="6" x2="21" y2="6"/><line x1="6" y1="12" x2="18" y2="12"/><line x1="4" y1="18" x2="20" y2="18"/></svg>
					</button>
					<button type="button" class="thed-btn" data-th-ed-cmd="justifyRight" aria-label="Align right" title="Align right">
						<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="6" x2="21" y2="6"/><line x1="9" y1="12" x2="21" y2="12"/><line x1="6" y1="18" x2="21" y2="18"/></svg>
					</button>
				<?php endif; ?>

				<?php if ( $has( 'list' ) ): ?>
					<button type="button" class="thed-btn" data-th-ed-cmd="insertUnorderedList" aria-label="Bulleted list" title="Bulleted list">
						<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="4" cy="6" r="1.2"/><circle cx="4" cy="12" r="1.2"/><circle cx="4" cy="18" r="1.2"/><line x1="9" y1="6" x2="21" y2="6"/><line x1="9" y1="12" x2="21" y2="12"/><line x1="9" y1="18" x2="21" y2="18"/></svg>
					</button>
					<button type="button" class="thed-btn" data-th-ed-cmd="insertOrderedList" aria-label="Numbered list" title="Numbered list">
						<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" font-size="6"><text x="2" y="8" font-size="6" fill="currentColor" stroke="none">1</text><text x="2" y="14" font-size="6" fill="currentColor" stroke="none">2</text><text x="2" y="20" font-size="6" fill="currentColor" stroke="none">3</text><line x1="9" y1="6" x2="21" y2="6"/><line x1="9" y1="12" x2="21" y2="12"/><line x1="9" y1="18" x2="21" y2="18"/></svg>
					</button>
				<?php endif; ?>

				<?php if ( $has( 'link' ) || $has( 'image' ) ): ?>
					<span class="thed-divider" aria-hidden="true"></span>
				<?php endif; ?>

				<?php if ( $has( 'link' ) ): ?>
					<button type="button" class="thed-btn" data-th-ed-link aria-label="Insert link" title="Insert link (⌘K)">
						<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>
					</button>
				<?php endif; ?>

				<?php if ( $has( 'image' ) ): ?>
					<button type="button" class="thed-btn" data-th-ed-image aria-label="Insert image" title="Insert image">
						<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
					</button>
				<?php endif; ?>
			</div>

			<div class="thed-surface" contenteditable="true" role="textbox" aria-multiline="true"
				 spellcheck="true"
				 data-th-ed-surface
				 data-th-ed-placeholder="<?php echo esc_attr( $args['placeholder'] ); ?>"
				 style="min-height:<?php echo (int) $args['min_height']; ?>px"
			><?php echo $body; ?></div>

			<input type="hidden" name="<?php echo esc_attr( $name ); ?>" data-th-ed-value value="<?php echo esc_attr( $body ); ?>" />
		</div>
		<?php
	}

	/** Print the editor's CSS + JS exactly once per request. */
	public static function assets(): void {
		if ( self::$assets_printed ) return;
		self::$assets_printed = true;
		self::print_styles();
		self::print_script();
	}

	private static function print_styles(): void {
		?>
		<style>
		.thed { --thed-bd:rgba(20,24,30,.10); --thed-bd2:rgba(20,24,30,.18); --thed-tx:#0F1115; --thed-tx2:#5A6068; --thed-tx3:#94A0AD;
			--thed-ac:#0F1115; --thed-bg:#FFFFFF; --thed-bg2:#F4F5F7;
			--thed-radius:14px; --thed-font:-apple-system,BlinkMacSystemFont,'Inter','Segoe UI',Roboto,sans-serif;
			background:var(--thed-bg); border:1px solid var(--thed-bd); border-radius:var(--thed-radius);
			font-family:var(--thed-font); color:var(--thed-tx); box-shadow:0 1px 2px rgba(20,24,40,.03); overflow:visible }
		.thed *,.thed *::before,.thed *::after { box-sizing:border-box }

		.thed-toolbar { display:flex; flex-wrap:wrap; align-items:center; gap:6px;
			padding:8px 10px; border-bottom:1px solid var(--thed-bd); background:linear-gradient(180deg,#FCFCFD,#FFFFFF) }
		.thed-divider { width:1px; height:18px; background:var(--thed-bd); margin:0 4px; align-self:center }

		.thed-btn { display:inline-flex; align-items:center; justify-content:center; min-width:32px; height:32px; padding:0 8px;
			background:transparent; border:1px solid transparent; border-radius:8px; color:var(--thed-tx); cursor:pointer;
			font:600 13px/1 var(--thed-font); transition:background .12s ease, border-color .12s ease }
		.thed-btn:hover { background:var(--thed-bg2) }
		.thed-btn:focus-visible { outline:none; border-color:var(--thed-ac); box-shadow:0 0 0 3px rgba(15,17,21,.08) }
		.thed-btn.is-active { background:var(--thed-bg2); border-color:var(--thed-bd2) }
		.thed-btn em { font-style:italic; font-weight:600 }
		.thed-btn u  { text-underline-offset:2px }
		.thed-btn svg { display:block }

		.thed-select { position:relative }
		.thed-select-btn { display:inline-flex; align-items:center; gap:8px; height:32px; padding:0 10px;
			background:#fff; border:1px solid var(--thed-bd); border-radius:10px;
			font:500 13px/1 var(--thed-font); color:var(--thed-tx); cursor:pointer; transition:border-color .12s ease, background .12s ease }
		.thed-select-btn:hover { border-color:var(--thed-bd2) }
		.thed-select.is-open .thed-select-btn { border-color:var(--thed-ac); box-shadow:0 0 0 3px rgba(15,17,21,.06) }
		.thed-select-size .thed-select-btn { min-width:72px; justify-content:space-between }
		.thed-menu { position:absolute; top:calc(100% + 6px); left:0; min-width:160px;
			background:#fff; border:1px solid var(--thed-bd); border-radius:12px;
			box-shadow:0 18px 40px rgba(15,17,21,.14); padding:6px; z-index:30; max-height:280px; overflow:auto }
		.thed-menu button { display:flex; width:100%; align-items:center; gap:8px; padding:8px 10px;
			background:transparent; border:none; border-radius:8px; font:500 13px/1.2 var(--thed-font); color:var(--thed-tx); cursor:pointer; text-align:left }
		.thed-menu button:hover { background:var(--thed-bg2) }
		.thed-menu button[aria-selected="true"]::after { content:"✓"; margin-left:auto; color:var(--thed-tx3) }

		.thed-color { position:relative }
		.thed-color-swatch { display:inline-block; width:18px; height:18px; border-radius:999px; border:1.5px solid var(--thed-bd2);
			background:#0F1115; box-shadow:inset 0 0 0 2px #fff }
		.thed-color-popover { position:absolute; top:calc(100% + 6px); left:0; min-width:280px;
			background:#fff; border:1px solid var(--thed-bd); border-radius:14px; box-shadow:0 18px 40px rgba(15,17,21,.14);
			padding:14px; z-index:30; display:flex; flex-direction:column; gap:8px }
		.thed-color-row { display:flex; gap:8px; flex-wrap:wrap }
		.thed-color-dot { width:22px; height:22px; border-radius:999px; border:1.5px solid rgba(15,17,21,.10);
			cursor:pointer; padding:0; transition:transform .12s ease, box-shadow .12s ease }
		.thed-color-dot:hover { transform:scale(1.08) }
		.thed-color-dot.is-active { box-shadow:0 0 0 2px #fff, 0 0 0 4px var(--thed-ac) }
		.thed-color-custom { display:flex; align-items:center; gap:8px; padding-top:8px; border-top:1px solid var(--thed-bd); font-size:13px; font-weight:600; color:var(--thed-tx) }
		.thed-color-custom input[type="color"] { width:24px; height:24px; padding:0; border:1.5px solid var(--thed-bd2); border-radius:999px; background:transparent; cursor:pointer }
		.thed-color-custom input[type="color"]::-webkit-color-swatch-wrapper { padding:0 }
		.thed-color-custom input[type="color"]::-webkit-color-swatch { border:none; border-radius:999px }
		.thed-color-custom input[type="text"] { flex:1; padding:6px 10px; border:1px solid var(--thed-bd); border-radius:8px;
			font:500 12px/1 ui-monospace,'JetBrains Mono',Menlo,monospace; color:var(--thed-tx); text-transform:uppercase; min-width:0 }
		.thed-color-custom input[type="text"]:focus { outline:none; border-color:var(--thed-ac); box-shadow:0 0 0 3px rgba(15,17,21,.06) }

		.thed-surface { padding:14px 18px; outline:none; min-height:160px;
			font:400 15px/1.55 var(--thed-font); color:var(--thed-tx) }
		.thed-surface:focus { outline:none }
		.thed-surface[data-empty="1"]::before { content:attr(data-th-ed-placeholder); color:var(--thed-tx3); pointer-events:none; display:block }
		.thed-surface p { margin:0 0 12px }
		.thed-surface p:last-child { margin-bottom:0 }
		.thed-surface ul,.thed-surface ol { margin:0 0 12px; padding-left:22px }
		.thed-surface a { color:#2C69F6; text-decoration:underline }
		.thed-surface img { max-width:100%; max-height:60vh; height:auto; border-radius:8px; display:block; margin:8px 0 }

		/* Modal variant — wrapper + close button */
		.thed-modal-overlay { position:fixed; inset:0; background:rgba(15,17,21,.36); backdrop-filter:blur(2px);
			display:flex; align-items:center; justify-content:center; padding:24px; z-index:100000 }
		.thed-modal { width:min(820px, 100%); background:#fff; border-radius:20px; padding:36px 40px 28px;
			box-shadow:0 30px 80px rgba(15,17,21,.25); position:relative; max-height:calc(100vh - 48px); overflow:auto }
		.thed-modal-icon { width:42px; height:42px; border-radius:11px; border:1px solid var(--thed-bd); background:#fff;
			display:inline-flex; align-items:center; justify-content:center; box-shadow:0 1px 2px rgba(15,17,21,.05); color:var(--thed-tx) }
		.thed-modal-close { position:absolute; top:18px; right:18px; width:32px; height:32px; border-radius:8px;
			background:transparent; border:none; color:var(--thed-tx2); cursor:pointer; font-size:20px; line-height:1 }
		.thed-modal-close:hover { background:var(--thed-bg2); color:var(--thed-tx) }
		.thed-modal h2 { margin:18px 0 6px; font:700 22px/1.2 var(--thed-font); letter-spacing:-.01em }
		.thed-modal-sub { margin:0 0 22px; color:var(--thed-tx2); font-size:14px }
		.thed-modal-actions { display:flex; justify-content:flex-end; gap:10px; margin-top:18px }
		.thed-modal-actions .thed-cta { padding:10px 18px; border-radius:10px; font:600 14px/1 var(--thed-font); cursor:pointer; border:1.5px solid transparent }
		.thed-modal-actions .thed-cta-primary { background:var(--thed-ac); color:#fff; border-color:var(--thed-ac) }
		.thed-modal-actions .thed-cta-primary:hover { background:#000 }
		.thed-modal-actions .thed-cta-ghost { background:#fff; border-color:var(--thed-bd2); color:var(--thed-tx) }
		.thed-modal-actions .thed-cta-ghost:hover { background:var(--thed-bg2) }
		</style>
		<?php
	}

	private static function print_script(): void {
		?>
		<script>
		(function(){
			if (window.__therum_editor_init) return; window.__therum_editor_init = true;

			function ready(fn){ if (document.readyState !== 'loading') fn(); else document.addEventListener('DOMContentLoaded', fn); }
			function clamp(s){ return String(s||'').slice(0, 9); }
			function isValidHex(s){ return /^#([0-9a-f]{3}|[0-9a-f]{6}|[0-9a-f]{8})$/i.test(s); }

			// URL allow-list for the link/image inserts. Refuses javascript:,
			// data:, vbscript:, and protocol-relative (//evil/) URLs — only
			// http(s), mailto, tel, and single-leading-slash relatives pass.
			function isSafeUrl(u){
				u = String(u||'').trim();
				if (!u) return false;
				if (u.indexOf('//') === 0) return false;                  // protocol-relative
				if (/^(javascript|data|vbscript|file):/i.test(u)) return false;
				if (/^(https?|mailto|tel):/i.test(u)) return true;
				return u[0] === '/' || u[0] === '#';                      // relative / fragment
			}
			function isSafeImageUrl(u){
				u = String(u||'').trim();
				if (!u) return false;
				if (u.indexOf('//') === 0) return false;
				if (/^(javascript|data|vbscript|file):/i.test(u)) return false;
				if (/^https?:/i.test(u)) return true;
				return u[0] === '/';                                       // relative only
			}
			function normalizeUrl(u){
				u = String(u||'').trim();
				if (!u) return '';
				if (/^(https?|mailto|tel):/i.test(u)) return u;
				if (u[0] === '/' || u[0] === '#') return u;
				return 'https://' + u;
			}

			function sanitizePastedHtml(html){
				// Strip script/style and event handlers from pasted content.
				var d = document.createElement('div'); d.innerHTML = html;
				d.querySelectorAll('script,style,iframe,object,embed,link,meta').forEach(function(el){ el.remove(); });
				d.querySelectorAll('*').forEach(function(el){
					// drop on* event attrs
					for (var i = el.attributes.length - 1; i >= 0; i--) {
						var a = el.attributes[i];
						if (/^on/i.test(a.name)) el.removeAttribute(a.name);
						if ((a.name === 'href' || a.name === 'src') && !isSafeUrl(a.value)) el.removeAttribute(a.name);
						// Drop layout-hostile styles. Inline style values that
						// position-fix or full-viewport-cover let pasted content
						// hijack the admin chrome — strip the whole attr rather
						// than try to whitelist properties.
						if (a.name === 'style' && /position\s*:\s*(fixed|sticky|absolute)|z-index\s*:\s*\d{4,}|inset\s*:|top\s*:\s*0|left\s*:\s*0/i.test(a.value)) {
							el.removeAttribute(a.name);
						}
					}
				});
				return d.innerHTML;
			}

			function init(root){
				if (root.__inited) return;
				root.__inited = true;
				var surface  = root.querySelector('[data-th-ed-surface]');
				var hidden   = root.querySelector('[data-th-ed-value]');
				if (!surface || !hidden) return;

				// Empty-state placeholder
				function refreshEmpty(){
					var empty = !surface.innerText.trim() && !surface.querySelector('img');
					surface.toggleAttribute('data-empty', empty);
					if (empty) surface.setAttribute('data-empty', '1');
					else surface.removeAttribute('data-empty');
				}
				refreshEmpty();

				// Sync surface → hidden input on every change (for parent forms)
				function sync(){
					hidden.value = surface.innerHTML;
					hidden.dispatchEvent(new Event('input',  { bubbles:true }));
					hidden.dispatchEvent(new Event('change', { bubbles:true }));
					refreshEmpty();
				}
				surface.addEventListener('input',  sync);
				surface.addEventListener('blur',   sync);

				// Sanitize pasted HTML so users can't drop scripts in.
				surface.addEventListener('paste', function(e){
					if (!e.clipboardData) return;
					var html = e.clipboardData.getData('text/html');
					var text = e.clipboardData.getData('text/plain');
					e.preventDefault();
					var insert = html ? sanitizePastedHtml(html) : (text ? text.replace(/\n/g, '<br>') : '');
					document.execCommand('insertHTML', false, insert);
				});

				// Toolbar buttons — execCommand
				root.querySelectorAll('[data-th-ed-cmd]').forEach(function(btn){
					btn.addEventListener('mousedown', function(e){ e.preventDefault(); });
					btn.addEventListener('click', function(){
						surface.focus();
						document.execCommand(btn.dataset.thEdCmd, false, null);
						syncToolbarState();
						sync();
					});
				});

				// Keyboard shortcuts (B/I/U)
				surface.addEventListener('keydown', function(e){
					if (!(e.metaKey || e.ctrlKey)) return;
					var k = e.key.toLowerCase();
					if (k === 'b') { e.preventDefault(); document.execCommand('bold');  syncToolbarState(); sync(); }
					else if (k === 'i') { e.preventDefault(); document.execCommand('italic'); syncToolbarState(); sync(); }
					else if (k === 'u') { e.preventDefault(); document.execCommand('underline'); syncToolbarState(); sync(); }
					else if (k === 'k') { e.preventDefault(); openLinkPrompt(); }
				});

				// Reflect active formatting in toolbar buttons (best-effort)
				function syncToolbarState(){
					[['bold','bold'],['italic','italic'],['underline','underline']].forEach(function(pair){
						var btn = root.querySelector('[data-th-ed-cmd="'+pair[0]+'"]');
						if (!btn) return;
						try { btn.classList.toggle('is-active', document.queryCommandState(pair[1])); }
						catch (e) { /* execCommand deprecation noise — ignore */ }
					});
				}
				surface.addEventListener('keyup', syncToolbarState);
				surface.addEventListener('mouseup', syncToolbarState);

				// Generic dropdown opener (font / size / color)
				function wireMenu(holderSel, onPick, labelSel){
					var holder = root.querySelector(holderSel);
					if (!holder) return null;
					var btn  = holder.querySelector('.thed-select-btn');
					var menu = holder.querySelector('.thed-menu');
					if (!btn || !menu) return null;
					btn.addEventListener('click', function(e){
						e.preventDefault();
						var open = !menu.hidden;
						closeAllMenus();
						if (open) return;
						menu.hidden = false;
						holder.classList.add('is-open');
						btn.setAttribute('aria-expanded', 'true');
					});
					menu.querySelectorAll('button[role="option"]').forEach(function(opt){
						opt.addEventListener('mousedown', function(e){ e.preventDefault(); });
						opt.addEventListener('click', function(){
							onPick(opt.dataset.val, opt.textContent);
							if (labelSel) holder.querySelector(labelSel).textContent = opt.textContent;
							menu.hidden = true; holder.classList.remove('is-open');
							btn.setAttribute('aria-expanded', 'false');
						});
					});
					return { holder: holder, menu: menu };
				}

				function closeAllMenus(){
					root.querySelectorAll('.thed-menu').forEach(function(m){ m.hidden = true; });
					root.querySelectorAll('.thed-select').forEach(function(s){
						s.classList.remove('is-open');
						var b = s.querySelector('.thed-select-btn'); if (b) b.setAttribute('aria-expanded', 'false');
					});
					var pop = root.querySelector('.thed-color-popover'); if (pop) pop.hidden = true;
					var cb  = root.querySelector('.thed-color-btn');     if (cb)  cb.setAttribute('aria-expanded', 'false');
				}

				wireMenu('[data-th-ed-font]', function(stack){
					surface.focus();
					// fontName takes a font face name; using execCommand with the first family.
					var first = stack.split(',')[0].replace(/['"]/g, '').trim();
					document.execCommand('fontName', false, first);
					sync();
				}, '[data-th-ed-font-label]');

				wireMenu('[data-th-ed-size]', function(px){
					surface.focus();
					// execCommand fontSize takes 1..7. Wrap selection in a span with explicit px so
					// we keep arbitrary sizes — first fontSize call gives us a hook to find.
					document.execCommand('fontSize', false, 7);
					var fonts = surface.querySelectorAll('font[size="7"]');
					fonts.forEach(function(f){
						var span = document.createElement('span');
						span.style.fontSize = px + 'px';
						while (f.firstChild) span.appendChild(f.firstChild);
						f.parentNode.replaceChild(span, f);
					});
					sync();
				}, '[data-th-ed-size-label]');

				// Color popover
				var colorWrap = root.querySelector('[data-th-ed-color]');
				if (colorWrap) {
					var colorBtn = colorWrap.querySelector('.thed-color-btn');
					var pop      = colorWrap.querySelector('.thed-color-popover');
					var swatch   = colorWrap.querySelector('[data-th-ed-color-swatch]');
					var picker   = colorWrap.querySelector('[data-th-ed-color-picker]');
					var hex      = colorWrap.querySelector('[data-th-ed-color-hex]');

					colorBtn.addEventListener('mousedown', function(e){ e.preventDefault(); });
					colorBtn.addEventListener('click', function(e){
						e.preventDefault();
						var open = !pop.hidden;
						closeAllMenus();
						if (open) return;
						pop.hidden = false; colorBtn.setAttribute('aria-expanded', 'true');
					});
					colorWrap.querySelectorAll('.thed-color-dot').forEach(function(dot){
						dot.addEventListener('mousedown', function(e){ e.preventDefault(); });
						dot.addEventListener('click', function(){
							applyColor(dot.dataset.val);
						});
					});
					picker.addEventListener('input', function(){
						hex.value = picker.value.toUpperCase();
					});
					picker.addEventListener('change', function(){ applyColor(picker.value); });
					hex.addEventListener('change', function(){
						var v = clamp(hex.value.trim());
						if (!v) return;
						if (v[0] !== '#') v = '#' + v;
						if (isValidHex(v)) applyColor(v);
					});

					function applyColor(c){
						if (!c) return;
						surface.focus();
						document.execCommand('foreColor', false, c);
						swatch.style.background = c;
						if (hex)    hex.value = c.toUpperCase();
						if (picker && /^#[0-9a-f]{6}$/i.test(c)) picker.value = c;
						sync();
					}
				}

				// Link
				var linkBtn = root.querySelector('[data-th-ed-link]');
				function openLinkPrompt(){
					surface.focus();
					var current = '';
					try {
						var sel = window.getSelection();
						if (sel && sel.anchorNode) {
							var a = sel.anchorNode.parentElement && sel.anchorNode.parentElement.closest('a');
							if (a) current = a.getAttribute('href') || '';
						}
					} catch(e){}
					var url = window.prompt('Link URL:', current || 'https://');
					if (url === null) return;
					url = url.trim();
					if (!url) { document.execCommand('unlink', false, null); }
					else if (!isSafeUrl(url)) { window.alert('Refused: only http(s), mailto, and relative URLs are allowed.'); }
					else {
						url = normalizeUrl(url);
						document.execCommand('createLink', false, url);
						// Reinforce target=_blank on the newly created link.
						surface.querySelectorAll('a[href]').forEach(function(a){
							if (a.getAttribute('href') === url) { a.setAttribute('target', '_blank'); a.setAttribute('rel','noopener'); }
						});
					}
					sync();
				}
				if (linkBtn) {
					linkBtn.addEventListener('mousedown', function(e){ e.preventDefault(); });
					linkBtn.addEventListener('click', openLinkPrompt);
				}

				// Image (by URL — keeps the editor dependency-free; media-picker
				// integration can attach later by replacing this handler).
				var imgBtn = root.querySelector('[data-th-ed-image]');
				if (imgBtn) {
					imgBtn.addEventListener('mousedown', function(e){ e.preventDefault(); });
					imgBtn.addEventListener('click', function(){
						surface.focus();
						var url = window.prompt('Image URL:');
						if (!url) return;
						url = url.trim();
						if (!url) return;
						if (!isSafeImageUrl(url)) { window.alert('Refused: image URL must be http(s) or a relative path.'); return; }
						url = normalizeUrl(url);
						document.execCommand('insertImage', false, url);
						sync();
					});
				}

				// Close menus on outside click
				document.addEventListener('mousedown', function(e){
					if (!root.contains(e.target)) closeAllMenus();
				});
			}

			function scan(){ document.querySelectorAll('[data-th-ed]').forEach(init); }

			ready(scan);
			// Re-scan when DOM is updated by AJAX-ish callers (uploader step swap).
			window.therum_editor_scan = scan;
		})();
		</script>
		<?php
	}
}

endif; // class_exists guard
