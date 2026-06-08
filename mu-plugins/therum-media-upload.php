<?php
/**
 * Plugin Name: Therum OS — Media Uploader
 * Description: Two-pane upload flow that replaces /wp-admin/media-new.php.
 *              Step 1: intro left, drag/drop + Select files right.
 *              Step 2: per-item meta editor left, upload queue right.
 *              Done:  return to media library.
 * Version: 1.9.26
 *
 * Routes:
 *   admin.php?page=therum-media-upload  — the uploader page
 *
 * AJAX:
 *   wp_ajax_therum_mu_upload     — handle a single file POST
 *   wp_ajax_therum_mu_save_meta  — persist alt/title/caption/description
 *
 * Kill switch: define( 'THERUM_MEDIA_UPLOAD_DISABLE', true ).
 */

if ( ! defined( 'ABSPATH' ) ) exit;
if ( defined( 'THERUM_MEDIA_UPLOAD_DISABLE' ) && THERUM_MEDIA_UPLOAD_DISABLE ) return;

// ─────────────────────────────────────────────────────────────────────────────
//  ROUTE REGISTRATION
// ─────────────────────────────────────────────────────────────────────────────

add_action( 'admin_menu', function() {
	add_submenu_page(
		null,                                  // hidden — accessed via the Upload button
		'Upload Media',                        // page title
		'Upload Media',                        // menu title
		'upload_files',                        // capability
		'therum-media-upload',                 // slug
		[ 'Therum_Media_Upload', 'render' ]
	);
}, 30 );

// The Therum Media Library's "Upload" action button is rewritten via the
// concrete therum-media-page.php edit — the leftover therum_admin_nav_items
// pass-through filter that lived here was a no-op and has been removed.
add_filter( 'media_upload_form_url', function( $url ) {
	return admin_url( 'admin.php?page=therum-media-upload' );
}, 10, 1 );


// ─────────────────────────────────────────────────────────────────────────────
//  PAGE CLASS — render the two-pane flow
// ─────────────────────────────────────────────────────────────────────────────

if ( ! class_exists( 'Therum_Media_Upload' ) ) :

final class Therum_Media_Upload {

	const NONCE_ACTION = 'therum_mu';

	public static function render(): void {
		if ( ! current_user_can( 'upload_files' ) ) {
			wp_die( esc_html__( 'You do not have permission to upload files.', 'therum' ) );
		}

		$nonce       = wp_create_nonce( self::NONCE_ACTION );
		$ajax_url    = admin_url( 'admin-ajax.php' );
		$library_url = admin_url( 'admin.php?page=therum-media' );
		$max_upload  = wp_max_upload_size();
		$max_human   = size_format( $max_upload );
		$accept_attr = implode( ',', [
			'image/*', 'video/*', 'audio/*',
			'.pdf', '.doc', '.docx', '.xls', '.xlsx', '.ppt', '.pptx', '.txt', '.csv',
			'.zip',
		] );

		?>
		<div class="wrap" style="margin:0;padding:0;max-width:none">
		<div class="tmu" data-tmu data-tmu-nonce="<?php echo esc_attr( $nonce ); ?>" data-tmu-ajax="<?php echo esc_url( $ajax_url ); ?>" data-tmu-library="<?php echo esc_url( $library_url ); ?>" data-tmu-max="<?php echo (int) $max_upload; ?>">

			<!-- ════════ STEP 1 — INTRO + DROP ZONE ════════ -->
			<section class="tmu-step is-active" data-tmu-step="1">
				<div class="tmu-pane tmu-pane-left">
					<div class="tmu-brand">
						<span class="tmu-brand-mark">T</span>
						<span class="tmu-brand-name">Therum Media</span>
					</div>
					<div class="tmu-intro">
						<h1>Upload your media</h1>
						<p>Drag and drop files, or click <strong>Select files</strong> to pick them from disk. We'll guide you through naming + alt text right after.</p>
						<ul class="tmu-checklist">
							<li><span class="tmu-check">✓</span> Images, video, audio, documents</li>
							<li><span class="tmu-check">✓</span> Up to <?php echo esc_html( $max_human ); ?> per file</li>
							<li><span class="tmu-check">✓</span> Auto-renames + EXIF strip honored from settings</li>
						</ul>
					</div>
					<div class="tmu-foot">
						<a href="<?php echo esc_url( $library_url ); ?>" class="tmu-link">← Back to media library</a>
					</div>
				</div>

				<div class="tmu-pane tmu-pane-right">
					<div class="tmu-drop" data-tmu-drop tabindex="0" role="button" aria-label="Drop files here or press Enter to select">
						<div class="tmu-drop-icon" aria-hidden="true">
							<svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
								<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
								<polyline points="17 8 12 3 7 8"/>
								<line x1="12" y1="3" x2="12" y2="15"/>
							</svg>
						</div>
						<div class="tmu-drop-title">Drag and drop files to upload</div>
						<div class="tmu-drop-sub">Files are private until you finish the flow.</div>
						<button type="button" class="tmu-btn tmu-btn-outline" data-tmu-pick>Select files</button>
						<input type="file" multiple class="tmu-file-input" data-tmu-file accept="<?php echo esc_attr( $accept_attr ); ?>" />
					</div>
				</div>
			</section>

			<!-- ════════ STEP 2 — META EDITOR + QUEUE ════════ -->
			<section class="tmu-step" data-tmu-step="2">
				<div class="tmu-pane tmu-pane-left">
					<div class="tmu-step-eyebrow">Step 2 of 2 · Add details</div>
					<h2 class="tmu-step-title">Name your files</h2>
					<p class="tmu-step-sub">Pick a file on the right to edit its metadata. Good alt text helps screen readers and search.</p>

					<form class="tmu-meta-form" data-tmu-meta autocomplete="off" onsubmit="return false">
						<div class="tmu-meta-empty" data-tmu-meta-empty>
							<div class="tmu-meta-empty-icon" aria-hidden="true">◇</div>
							<div>Select a file on the right to edit its details.</div>
						</div>
						<div class="tmu-meta-fields" data-tmu-meta-fields hidden>
							<label class="tmu-field">
								<span>Title</span>
								<input type="text" name="title" maxlength="200" />
							</label>
							<label class="tmu-field">
								<span>Alt text <em>recommended</em></span>
								<input type="text" name="alt" maxlength="200" placeholder="Describe what's in the image for screen readers" />
							</label>
							<label class="tmu-field">
								<span>Caption</span>
								<input type="text" name="caption" maxlength="500" />
							</label>
							<div class="tmu-field tmu-field-rt">
								<span>Description</span>
								<?php if ( class_exists( 'Therum_Editor' ) ): ?>
									<?php Therum_Editor::field( 'description', '', [
										'placeholder' => 'Optional — describe this file in your own words.',
										'min_height'  => 160,
										'features'    => [ 'b', 'i', 'u', 'color', 'list', 'link' ],
									] ); ?>
								<?php else: ?>
									<textarea name="description" rows="3" maxlength="1000"></textarea>
								<?php endif; ?>
							</div>
							<div class="tmu-meta-status" data-tmu-meta-status></div>
						</div>
					</form>

					<div class="tmu-foot">
						<button type="button" class="tmu-link" data-tmu-back>← Back to upload</button>
						<button type="button" class="tmu-btn tmu-btn-primary" data-tmu-finish>Save &amp; finish</button>
					</div>
				</div>

				<div class="tmu-pane tmu-pane-right tmu-pane-queue">
					<div class="tmu-queue-head">
						<div class="tmu-queue-title">Uploaded <span data-tmu-count>0</span></div>
						<button type="button" class="tmu-btn tmu-btn-outline tmu-btn-sm" data-tmu-pick-more>Add more</button>
					</div>
					<div class="tmu-queue" data-tmu-queue></div>
				</div>
			</section>

			<!-- ════════ DONE ════════ -->
			<section class="tmu-step" data-tmu-step="done">
				<div class="tmu-pane tmu-pane-left tmu-pane-center">
					<div class="tmu-done">
						<div class="tmu-done-mark" aria-hidden="true">
							<svg width="56" height="56" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"/></svg>
						</div>
						<h2>All saved.</h2>
						<p data-tmu-done-summary>0 files uploaded.</p>
						<div class="tmu-done-actions">
							<a class="tmu-btn tmu-btn-primary" href="<?php echo esc_url( $library_url ); ?>">Open media library</a>
							<button type="button" class="tmu-btn tmu-btn-outline" data-tmu-restart>Upload more</button>
						</div>
					</div>
				</div>
				<div class="tmu-pane tmu-pane-right tmu-pane-queue tmu-pane-readonly">
					<div class="tmu-queue-head">
						<div class="tmu-queue-title">Saved files</div>
					</div>
					<div class="tmu-queue tmu-queue-readonly" data-tmu-queue-done></div>
				</div>
			</section>

		</div></div>

		<?php self::print_styles(); ?>
		<?php self::print_script(); ?>
		<?php
	}

	private static function print_styles(): void {
		?>
		<style>
		/* Therum Media Uploader — scoped to .tmu */
		/* The page is registered with parent=null so WP generates
		   `admin_page_therum-media-upload`, not toplevel_*. The selector matches
		   any class containing `page_therum-media-upload` so both legacy and
		   current WP body classes work. */
		body[class*="page_therum-media-upload"] #wpcontent { padding:0 !important; background:transparent }
		body[class*="page_therum-media-upload"] #wpbody-content > .wrap { padding:0 !important; margin:0 !important; max-width:none !important }

		.tmu { --tmu-bg:#FAFAFA; --tmu-panel:#FFFFFF; --tmu-bd:rgba(20,24,30,.08); --tmu-bd2:rgba(20,24,30,.14);
			--tmu-tx:#0F1115; --tmu-tx2:#5A6068; --tmu-tx3:#94A0AD; --tmu-ac:#0F1115; --tmu-acc:#F0563E; --tmu-ok:#16A34A;
			--tmu-radius:14px; --tmu-radius-lg:20px;
			--tmu-font:-apple-system,BlinkMacSystemFont,'Inter','Segoe UI',Roboto,sans-serif;
			background:var(--tmu-bg); min-height:calc(100vh - 32px);
			display:flex; align-items:stretch; justify-content:center; padding:24px;
			font-family:var(--tmu-font); color:var(--tmu-tx); box-sizing:border-box;
		}
		.tmu *,.tmu *::before,.tmu *::after { box-sizing:border-box }

		.tmu-step { display:none; width:100%; max-width:1280px; min-height:600px;
			background:var(--tmu-panel); border:1px solid var(--tmu-bd); border-radius:var(--tmu-radius-lg);
			overflow:hidden; box-shadow:0 1px 3px rgba(20,24,40,.04);
			grid-template-columns:1fr 1fr; gap:0 }
		.tmu-step.is-active { display:grid }

		.tmu-pane { padding:48px 56px; display:flex; flex-direction:column; min-width:0 }
		.tmu-pane-left { border-right:1px solid var(--tmu-bd) }
		.tmu-pane-right { background:#F4F5F7; justify-content:center; align-items:stretch }
		.tmu-pane-center { align-items:center; justify-content:center; text-align:center }

		/* Brand */
		.tmu-brand { display:flex; align-items:center; gap:10px; margin-bottom:auto }
		.tmu-brand-mark { width:34px; height:34px; border-radius:9px; background:var(--tmu-ac); color:#fff;
			display:inline-flex; align-items:center; justify-content:center; font-weight:800; font-size:16px; letter-spacing:-.02em }
		.tmu-brand-name { font-weight:700; font-size:14px; color:var(--tmu-tx); letter-spacing:-.01em }

		/* Intro */
		.tmu-intro { margin:32px 0 auto; max-width:36ch }
		.tmu-intro h1 { font-size:30px; font-weight:700; letter-spacing:-.025em; margin:0 0 10px; line-height:1.1 }
		.tmu-intro p { font-size:15px; color:var(--tmu-tx2); line-height:1.55; margin:0 0 20px }
		.tmu-checklist { list-style:none; padding:0; margin:0; display:flex; flex-direction:column; gap:10px }
		.tmu-checklist li { display:flex; align-items:center; gap:10px; font-size:14px; color:var(--tmu-tx2) }
		.tmu-check { width:18px; height:18px; border-radius:999px; background:rgba(15,17,21,.06); color:var(--tmu-tx);
			display:inline-flex; align-items:center; justify-content:center; font-size:11px; font-weight:700 }

		.tmu-foot { margin-top:32px; display:flex; align-items:center; gap:16px; justify-content:space-between }
		.tmu-foot:has(.tmu-link:only-child) { justify-content:flex-start }

		/* Drop zone */
		.tmu-drop { width:100%; max-width:440px; margin:auto; padding:48px 32px;
			border:1.5px dashed var(--tmu-bd2); border-radius:var(--tmu-radius); background:#fff;
			display:flex; flex-direction:column; align-items:center; gap:14px; text-align:center; cursor:pointer;
			transition:border-color .2s ease, background .2s ease, transform .2s ease }
		.tmu-drop:hover,.tmu-drop:focus-visible { border-color:var(--tmu-ac); outline:none }
		.tmu-drop.is-dragover { border-color:var(--tmu-acc); background:#FFF6F4; transform:scale(1.01) }
		.tmu-drop-icon { width:64px; height:64px; border-radius:14px; background:rgba(15,17,21,.04);
			display:inline-flex; align-items:center; justify-content:center; color:var(--tmu-tx) }
		.tmu-drop-title { font-size:16px; font-weight:700; letter-spacing:-.01em }
		.tmu-drop-sub { font-size:13px; color:var(--tmu-tx3) }
		.tmu-file-input { position:absolute; left:-9999px; opacity:0; width:1px; height:1px }

		/* Buttons */
		.tmu-btn { display:inline-flex; align-items:center; justify-content:center; gap:8px;
			padding:11px 22px; border-radius:10px; font:600 14px/1 var(--tmu-font); cursor:pointer;
			border:1.5px solid transparent; text-decoration:none; transition:background .15s ease,border-color .15s ease,color .15s ease,opacity .15s ease }
		.tmu-btn-primary { background:var(--tmu-ac); color:#fff }
		.tmu-btn-primary:hover { background:#000 }
		.tmu-btn-outline { background:#fff; border-color:var(--tmu-bd2); color:var(--tmu-tx) }
		.tmu-btn-outline:hover { background:#F4F5F7; border-color:var(--tmu-tx) }
		.tmu-btn-sm { padding:7px 14px; font-size:12px; border-radius:8px }
		.tmu-btn[disabled],.tmu-btn.is-busy { opacity:.55; cursor:not-allowed }
		.tmu-link { background:none; border:none; padding:0; color:var(--tmu-tx2); font:500 13px/1 var(--tmu-font);
			cursor:pointer; text-decoration:none }
		.tmu-link:hover { color:var(--tmu-tx) }

		/* Step 2 — meta editor */
		.tmu-step-eyebrow { font-size:11px; letter-spacing:.08em; text-transform:uppercase; font-weight:700; color:var(--tmu-tx3); margin-bottom:10px }
		.tmu-step-title { font-size:26px; font-weight:700; letter-spacing:-.02em; margin:0 0 8px }
		.tmu-step-sub { color:var(--tmu-tx2); font-size:14px; margin:0 0 28px }

		.tmu-meta-form { flex:1; min-height:0; overflow:auto }
		.tmu-meta-empty { color:var(--tmu-tx3); font-size:14px; padding:24px; border:1px dashed var(--tmu-bd); border-radius:12px;
			display:flex; flex-direction:column; align-items:center; gap:10px; text-align:center }
		.tmu-meta-empty-icon { font-size:24px; color:var(--tmu-bd2) }
		.tmu-meta-fields { display:flex; flex-direction:column; gap:16px }
		.tmu-field { display:flex; flex-direction:column; gap:6px; font-size:13px; font-weight:600; color:var(--tmu-tx) }
		.tmu-field span em { font-weight:400; font-style:normal; color:var(--tmu-tx3); margin-left:6px }
		.tmu-field input,.tmu-field textarea { width:100%; padding:10px 12px; border:1px solid var(--tmu-bd); border-radius:10px;
			background:#fff; font:400 14px/1.4 var(--tmu-font); color:var(--tmu-tx); resize:vertical;
			transition:border-color .15s ease, box-shadow .15s ease }
		.tmu-field input:focus,.tmu-field textarea:focus { outline:none; border-color:var(--tmu-ac); box-shadow:0 0 0 3px rgba(15,17,21,.06) }
		.tmu-meta-status { font-size:12px; color:var(--tmu-tx3); min-height:18px }
		.tmu-meta-status.is-saved { color:var(--tmu-ok) }
		.tmu-meta-status.is-error { color:var(--tmu-acc) }

		/* Queue (Step 2 right pane) */
		.tmu-pane-queue { padding:32px 32px; gap:18px; background:#F4F5F7 }
		.tmu-queue-head { display:flex; align-items:center; justify-content:space-between; gap:12px }
		.tmu-queue-title { font-weight:700; font-size:15px; letter-spacing:-.01em }
		.tmu-queue-title span { color:var(--tmu-tx3); font-weight:500; margin-left:4px }
		.tmu-queue { flex:1; min-height:0; overflow:auto; display:flex; flex-direction:column; gap:10px; padding-right:4px }
		.tmu-queue-readonly { pointer-events:none }

		.tmu-item { display:flex; align-items:center; gap:14px; padding:10px;
			background:#fff; border:1.5px solid var(--tmu-bd); border-radius:12px; cursor:pointer;
			transition:border-color .15s ease, background .15s ease }
		.tmu-item:hover { border-color:var(--tmu-tx3) }
		.tmu-item.is-active { border-color:var(--tmu-ac); background:#FAFAFB }
		.tmu-item.is-error { border-color:var(--tmu-acc) }
		.tmu-item-thumb { width:48px; height:48px; flex-shrink:0; border-radius:8px; background:#EEF0F3 center/cover no-repeat;
			display:flex; align-items:center; justify-content:center; color:var(--tmu-tx3); font-size:11px; font-weight:700 }
		.tmu-item-body { flex:1; min-width:0; display:flex; flex-direction:column; gap:4px }
		.tmu-item-name { font-size:13px; font-weight:600; color:var(--tmu-tx); white-space:nowrap; overflow:hidden; text-overflow:ellipsis }
		.tmu-item-meta { font-size:11px; color:var(--tmu-tx3) }
		.tmu-item-bar { position:relative; height:4px; border-radius:2px; background:rgba(15,17,21,.06); overflow:hidden; margin-top:2px }
		.tmu-item-bar-fill { position:absolute; inset:0 100% 0 0; background:var(--tmu-ac); transition:right .15s linear }
		.tmu-item.is-done .tmu-item-bar-fill { background:var(--tmu-ok); right:0 !important }
		.tmu-item-state { font-size:11px; color:var(--tmu-tx3); white-space:nowrap }
		.tmu-item.is-done .tmu-item-state { color:var(--tmu-ok); font-weight:600 }
		.tmu-item.is-error .tmu-item-state { color:var(--tmu-acc) }

		/* Done */
		.tmu-done { display:flex; flex-direction:column; align-items:center; gap:16px; max-width:380px }
		.tmu-done-mark { width:88px; height:88px; border-radius:50%; background:rgba(22,163,74,.1); color:var(--tmu-ok);
			display:inline-flex; align-items:center; justify-content:center }
		.tmu-done h2 { margin:0; font-size:28px; font-weight:700; letter-spacing:-.025em }
		.tmu-done p { margin:0; color:var(--tmu-tx2); font-size:14px }
		.tmu-done-actions { display:flex; gap:10px; margin-top:8px }

		/* Responsive */
		@media (max-width: 900px) {
			.tmu { padding:12px }
			.tmu-step.is-active { grid-template-columns:1fr; min-height:auto }
			.tmu-pane { padding:28px }
			.tmu-pane-left { border-right:none; border-bottom:1px solid var(--tmu-bd) }
		}
		</style>
		<?php
	}

	private static function print_script(): void {
		?>
		<script>
		(function(){
			var root = document.querySelector('[data-tmu]');
			if (!root) return;
			var ajax  = root.dataset.tmuAjax;
			var nonce = root.dataset.tmuNonce;
			var lib   = root.dataset.tmuLibrary;
			var maxBytes = parseInt(root.dataset.tmuMax, 10) || (64 * 1024 * 1024);

			var fileInput = root.querySelector('[data-tmu-file]');
			var drop      = root.querySelector('[data-tmu-drop]');
			var pickBtn   = root.querySelector('[data-tmu-pick]');
			var pickMore  = root.querySelector('[data-tmu-pick-more]');
			var queueEl   = root.querySelector('[data-tmu-queue]');
			var queueDone = root.querySelector('[data-tmu-queue-done]');
			var countEl   = root.querySelector('[data-tmu-count]');
			var doneSum   = root.querySelector('[data-tmu-done-summary]');
			var metaForm  = root.querySelector('[data-tmu-meta]');
			var metaFields = root.querySelector('[data-tmu-meta-fields]');
			var metaEmpty = root.querySelector('[data-tmu-meta-empty]');
			var metaStatus = root.querySelector('[data-tmu-meta-status]');
			var finishBtn = root.querySelector('[data-tmu-finish]');
			var backBtn   = root.querySelector('[data-tmu-back]');
			var restartBtn = root.querySelector('[data-tmu-restart]');

			var queue = []; // [{ id, file, name, size, status, attachmentId, thumb, meta:{title,alt,caption,description} }]
			var activeId = null;
			var seq = 0;

			function showStep(n) {
				root.querySelectorAll('[data-tmu-step]').forEach(function(s){
					s.classList.toggle('is-active', s.dataset.tmuStep === String(n));
				});
			}

			function bytesHuman(b){
				if (b < 1024) return b + ' B';
				if (b < 1024*1024) return (b/1024).toFixed(1) + ' KB';
				if (b < 1024*1024*1024) return (b/1024/1024).toFixed(1) + ' MB';
				return (b/1024/1024/1024).toFixed(2) + ' GB';
			}

			function renderQueue(){
				queueEl.innerHTML = '';
				var doneCount = 0;
				queue.forEach(function(q){
					var div = document.createElement('div');
					div.className = 'tmu-item' + (q.status === 'done' ? ' is-done' : '')
						+ (q.status === 'error' ? ' is-error' : '')
						+ (q.id === activeId ? ' is-active' : '');
					div.dataset.id = q.id;
					var thumbStyle = q.thumb ? 'background-image:url('+ q.thumb +');' : '';
					div.innerHTML =
						'<div class="tmu-item-thumb" style="'+ thumbStyle +'">'+ (q.thumb ? '' : (q.kind || 'FILE')) +'</div>' +
						'<div class="tmu-item-body">' +
							'<div class="tmu-item-name" title="'+ escapeHtml(q.name) +'">'+ escapeHtml(q.name) +'</div>' +
							'<div class="tmu-item-meta">'+ bytesHuman(q.size) +' · '+ (q.status === 'done' ? 'Uploaded' : q.status === 'error' ? (q.error || 'Failed') : Math.floor(q.progress || 0) + '%') +'</div>' +
							'<div class="tmu-item-bar"><div class="tmu-item-bar-fill" style="right:'+ (100 - (q.progress||0)) +'%"></div></div>' +
						'</div>' +
						'<div class="tmu-item-state">'+ (q.status === 'done' ? '✓' : q.status === 'error' ? '!' : (Math.floor(q.progress||0) + '%')) +'</div>';
					div.addEventListener('click', function(){ selectActive(q.id); });
					queueEl.appendChild(div);
					if (q.status === 'done') doneCount++;
				});
				if (countEl) countEl.textContent = doneCount;
			}

			function escapeHtml(s){ return String(s||'').replace(/[&<>"']/g, function(c){ return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c]; }); }

			function setFieldValue(name, val){
				val = val || '';
				// Therum rich-text editor case: write into the contenteditable
				// surface and let its own sync push to the hidden input.
				var ed = metaFields.querySelector('[data-th-ed][data-th-ed-name="'+ name +'"]');
				if (ed) {
					var surface = ed.querySelector('[data-th-ed-surface]');
					var hidden  = ed.querySelector('[data-th-ed-value]');
					if (surface) surface.innerHTML = val;
					if (hidden)  hidden.value      = val;
					if (surface) surface.dispatchEvent(new Event('input', { bubbles:true }));
					return;
				}
				var input = metaFields.querySelector('[name="'+ name +'"]');
				if (input) input.value = val;
			}

			function selectActive(id){
				var q = queue.find(function(x){ return x.id === id; });
				if (!q || q.status !== 'done') return;
				activeId = id;
				renderQueue();
				metaEmpty.hidden = true;
				metaFields.hidden = false;
				setFieldValue('title',       q.meta.title);
				setFieldValue('alt',         q.meta.alt);
				setFieldValue('caption',     q.meta.caption);
				setFieldValue('description', q.meta.description);
				metaStatus.className = 'tmu-meta-status';
				metaStatus.textContent = '';
			}

			function addFiles(files){
				if (!files || !files.length) return;
				var hadAny = queue.length > 0;
				Array.prototype.forEach.call(files, function(f){
					if (f.size > maxBytes) {
						queue.push({ id: ++seq, file: f, name: f.name, size: f.size, status: 'error', progress: 0, error: 'Exceeds upload limit', kind: kindOf(f), meta:{} });
						return;
					}
					var q = { id: ++seq, file: f, name: f.name, size: f.size, status: 'pending', progress: 0, kind: kindOf(f), meta:{} };
					queue.push(q);
					if (f.type && f.type.indexOf('image/') === 0) {
						var rdr = new FileReader();
						rdr.onload = function(e){ q.thumb = e.target.result; renderQueue(); };
						rdr.readAsDataURL(f);
					}
				});
				if (!hadAny) showStep(2);
				renderQueue();
				processQueue();
			}

			function kindOf(f){
				if (!f.type) return 'FILE';
				if (f.type.indexOf('image/') === 0) return 'IMG';
				if (f.type.indexOf('video/') === 0) return 'VID';
				if (f.type.indexOf('audio/') === 0) return 'AUD';
				if (f.type.indexOf('application/pdf') === 0) return 'PDF';
				return 'FILE';
			}

			function processQueue(){
				queue.forEach(function(q){
					if (q.status === 'pending') uploadOne(q);
				});
			}

			function uploadOne(q){
				q.status = 'uploading';
				q.progress = 0;
				renderQueue();

				var fd = new FormData();
				fd.append('action', 'therum_mu_upload');
				fd.append('_nonce', nonce);
				fd.append('file', q.file, q.name);

				var xhr = new XMLHttpRequest();
				xhr.open('POST', ajax, true);
				xhr.upload.onprogress = function(e){
					if (!e.lengthComputable) return;
					q.progress = (e.loaded / e.total) * 100;
					renderQueue();
				};
				xhr.onreadystatechange = function(){
					if (xhr.readyState !== 4) return;
					try {
						var res = JSON.parse(xhr.responseText);
						if (res && res.success && res.data && res.data.id) {
							q.attachmentId = res.data.id;
							q.thumb = res.data.thumb || q.thumb;
							q.meta = {
								title: res.data.title || q.name.replace(/\.[^.]+$/, ''),
								alt: res.data.alt || '',
								caption: res.data.caption || '',
								description: res.data.description || ''
							};
							q.status = 'done';
							q.progress = 100;
						} else {
							q.status = 'error';
							q.error = (res && res.data && res.data.message) || 'Upload failed';
						}
					} catch (e) {
						q.status = 'error';
						q.error = 'Bad server response';
					}
					renderQueue();
					if (!activeId && q.status === 'done') selectActive(q.id);
				};
				xhr.send(fd);
			}

			// Drop zone
			['dragenter','dragover'].forEach(function(ev){
				drop.addEventListener(ev, function(e){ e.preventDefault(); e.stopPropagation(); drop.classList.add('is-dragover'); });
			});
			['dragleave','drop'].forEach(function(ev){
				drop.addEventListener(ev, function(e){ e.preventDefault(); e.stopPropagation(); drop.classList.remove('is-dragover'); });
			});
			drop.addEventListener('drop', function(e){ if (e.dataTransfer && e.dataTransfer.files) addFiles(e.dataTransfer.files); });
			drop.addEventListener('click', function(){ fileInput.click(); });
			drop.addEventListener('keydown', function(e){ if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); fileInput.click(); } });
			if (pickBtn)  pickBtn.addEventListener('click', function(e){ e.stopPropagation(); fileInput.click(); });
			if (pickMore) pickMore.addEventListener('click', function(){ fileInput.click(); });
			fileInput.addEventListener('change', function(){ addFiles(fileInput.files); fileInput.value = ''; });

			// Meta save (debounced per-field)
			var saveTimer = null;
			function scheduleSave(){
				if (saveTimer) clearTimeout(saveTimer);
				metaStatus.className = 'tmu-meta-status';
				metaStatus.textContent = 'Saving…';
				saveTimer = setTimeout(saveMeta, 350);
			}
			metaFields.querySelectorAll('input, textarea').forEach(function(el){
				el.addEventListener('input', function(){
					var q = queue.find(function(x){ return x.id === activeId; });
					if (!q) return;
					q.meta[el.name] = el.value;
					scheduleSave();
				});
			});

			function saveMeta(){
				var q = queue.find(function(x){ return x.id === activeId; });
				if (!q || !q.attachmentId) return;
				var fd = new FormData();
				fd.append('action', 'therum_mu_save_meta');
				fd.append('_nonce', nonce);
				fd.append('id', q.attachmentId);
				fd.append('title', q.meta.title || '');
				fd.append('alt', q.meta.alt || '');
				fd.append('caption', q.meta.caption || '');
				fd.append('description', q.meta.description || '');
				fetch(ajax, { method:'POST', credentials:'same-origin', body:fd })
					.then(function(r){ return r.json(); })
					.then(function(res){
						if (res && res.success) {
							metaStatus.className = 'tmu-meta-status is-saved';
							metaStatus.textContent = 'Saved';
						} else {
							metaStatus.className = 'tmu-meta-status is-error';
							metaStatus.textContent = (res && res.data && res.data.message) || 'Save failed';
						}
					})
					.catch(function(){
						metaStatus.className = 'tmu-meta-status is-error';
						metaStatus.textContent = 'Network error';
					});
			}

			// Nav — every handler null-guards because the render layer may emit
			// a subset of steps depending on context (e.g. an embedded uploader).
			if (finishBtn) finishBtn.addEventListener('click', function(){
				// Flush any pending save first
				if (saveTimer) { clearTimeout(saveTimer); saveMeta(); }
				var done = queue.filter(function(q){ return q.status === 'done'; });
				var errored = queue.filter(function(q){ return q.status === 'error'; });
				if (queueDone) {
					queueDone.innerHTML = '';
					done.forEach(function(q){
						var div = document.createElement('div');
						div.className = 'tmu-item is-done';
						var thumbStyle = q.thumb ? 'background-image:url('+ q.thumb +');' : '';
						div.innerHTML =
							'<div class="tmu-item-thumb" style="'+ thumbStyle +'">'+ (q.thumb ? '' : escapeHtml(q.kind || 'FILE')) +'</div>' +
							'<div class="tmu-item-body"><div class="tmu-item-name">'+ escapeHtml(q.meta.title || q.name) +'</div>' +
							'<div class="tmu-item-meta">'+ bytesHuman(q.size) +' · Uploaded</div></div>' +
							'<div class="tmu-item-state">✓</div>';
						queueDone.appendChild(div);
					});
					errored.forEach(function(q){
						var div = document.createElement('div');
						div.className = 'tmu-item is-error';
						div.innerHTML =
							'<div class="tmu-item-thumb">'+ escapeHtml(q.kind || 'FILE') +'</div>' +
							'<div class="tmu-item-body"><div class="tmu-item-name">'+ escapeHtml(q.name) +'</div>' +
							'<div class="tmu-item-meta">'+ escapeHtml(q.error || 'Failed') +'</div></div>' +
							'<div class="tmu-item-state">!</div>';
						queueDone.appendChild(div);
					});
				}
				if (doneSum) {
					var msg = done.length + (done.length === 1 ? ' file saved.' : ' files saved.');
					if (errored.length) msg += ' ' + errored.length + ' failed.';
					doneSum.textContent = msg;
				}
				showStep('done');
			});
			if (backBtn) backBtn.addEventListener('click', function(){
				if (queue.length) { showStep(2); } else { showStep(1); }
			});
			if (restartBtn) restartBtn.addEventListener('click', function(){
				queue = []; activeId = null; seq = 0;
				if (queueEl) queueEl.innerHTML = '';
				if (queueDone) queueDone.innerHTML = '';
				metaFields.hidden = true; metaEmpty.hidden = false;
				showStep(1);
			});
		})();
		</script>
		<?php
	}

	// ─────────────────────────────────────────────────────────────────────────
	//  AJAX — accept one file
	// ─────────────────────────────────────────────────────────────────────────

	public static function ajax_upload(): void {
		if ( ! current_user_can( 'upload_files' ) ) {
			wp_send_json_error( [ 'message' => 'Insufficient permissions.' ], 403 );
		}
		if ( ! check_ajax_referer( self::NONCE_ACTION, '_nonce', false ) ) {
			wp_send_json_error( [ 'message' => 'Invalid request.' ], 403 );
		}
		if ( empty( $_FILES['file'] ) || ! is_array( $_FILES['file'] ) ) {
			wp_send_json_error( [ 'message' => 'No file received.' ] );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';

		// Stuff $_FILES into the shape media_handle_upload expects.
		$_FILES['therum_upload'] = $_FILES['file'];
		$attachment_id = media_handle_upload( 'therum_upload', 0 );
		unset( $_FILES['therum_upload'] );

		if ( is_wp_error( $attachment_id ) ) {
			wp_send_json_error( [ 'message' => $attachment_id->get_error_message() ] );
		}
		if ( ! $attachment_id ) {
			wp_send_json_error( [ 'message' => 'Upload failed (no id).' ] );
		}

		$post     = get_post( $attachment_id );
		$thumb    = wp_get_attachment_image_url( $attachment_id, 'thumbnail' );
		$src      = wp_get_attachment_url( $attachment_id );

		wp_send_json_success( [
			'id'          => (int) $attachment_id,
			'title'       => $post ? $post->post_title : '',
			'caption'     => $post ? $post->post_excerpt : '',
			'description' => $post ? $post->post_content : '',
			'alt'         => (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ),
			'thumb'       => $thumb ?: '',
			'url'         => $src ?: '',
			'mime'        => $post ? $post->post_mime_type : '',
		] );
	}

	public static function ajax_save_meta(): void {
		if ( ! current_user_can( 'upload_files' ) ) {
			wp_send_json_error( [ 'message' => 'Insufficient permissions.' ], 403 );
		}
		if ( ! check_ajax_referer( self::NONCE_ACTION, '_nonce', false ) ) {
			wp_send_json_error( [ 'message' => 'Invalid request.' ], 403 );
		}
		$id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		if ( ! $id ) wp_send_json_error( [ 'message' => 'Missing id.' ] );
		if ( ! current_user_can( 'edit_post', $id ) ) wp_send_json_error( [ 'message' => 'Cannot edit this attachment.' ], 403 );

		// Captions are short single-line copy → no markup. Description is a
		// rich-text surface (Therum_Editor) → allow a tight kses allow-list
		// rather than the wp_kses_post default (which permits `<a>` with
		// `javascript:` hrefs in older WP versions). Title + alt strip every
		// tag.
		$rt_allowed = [
			'a'      => [ 'href' => true, 'title' => true, 'target' => true, 'rel' => true ],
			'br'     => [], 'p' => [],
			'strong' => [], 'b' => [], 'em' => [], 'i' => [], 'u' => [],
			'ul'     => [], 'ol' => [], 'li' => [],
			'span'   => [ 'style' => true ],
		];
		$rt_protocols = [ 'http', 'https', 'mailto', 'tel' ];

		$title       = isset( $_POST['title'] )   ? sanitize_text_field( wp_unslash( $_POST['title'] ) )       : '';
		$alt         = isset( $_POST['alt'] )     ? sanitize_text_field( wp_unslash( $_POST['alt'] ) )         : '';
		$caption     = isset( $_POST['caption'] ) ? sanitize_textarea_field( wp_unslash( $_POST['caption'] ) ) : '';
		$description = isset( $_POST['description'] )
			? wp_kses( wp_unslash( $_POST['description'] ), $rt_allowed, $rt_protocols )
			: '';

		// WP truncates post_title silently at the schema's varchar limit (255
		// chars). Refuse oversize titles up-front so the user gets feedback
		// instead of a silent crop.
		if ( strlen( $title ) > 200 ) wp_send_json_error( [ 'message' => 'Title too long (max 200 characters).' ] );

		$update = [ 'ID' => $id ];
		if ( $title !== '' )   $update['post_title']   = $title;
		$update['post_excerpt'] = $caption;
		$update['post_content'] = $description;

		$res = wp_update_post( $update, true );
		if ( is_wp_error( $res ) ) wp_send_json_error( [ 'message' => $res->get_error_message() ] );

		update_post_meta( $id, '_wp_attachment_image_alt', $alt );

		wp_send_json_success( [ 'id' => $id ] );
	}
}

endif;

add_action( 'wp_ajax_therum_mu_upload',    [ 'Therum_Media_Upload', 'ajax_upload' ] );
add_action( 'wp_ajax_therum_mu_save_meta', [ 'Therum_Media_Upload', 'ajax_save_meta' ] );


// ─────────────────────────────────────────────────────────────────────────────
//  REPLACE THE DEFAULT "Upload" BUTTON ON THE THERUM MEDIA LIBRARY
// ─────────────────────────────────────────────────────────────────────────────

add_filter( 'therum_media_page_action_buttons', function( $buttons ) {
	foreach ( $buttons as &$b ) {
		if ( ( $b['label'] ?? '' ) === 'Upload' ) {
			$b['href'] = admin_url( 'admin.php?page=therum-media-upload' );
		}
	}
	return $buttons;
}, 10, 1 );
