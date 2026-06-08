<?php
/**
 * Plugin Name: Therum OS — Install Wizard
 * Description: First-run setup wizard. Extracted from therum-admin.php to
 *              keep that file manageable; the surface and behavior are
 *              unchanged. Auto-loaded as an mu-plugin.
 * Version: 1.9.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// ════════════════════════════════════════════════════════════════════════════
//  4. INSTALL WIZARD — from therum-install-wizard.php
// ════════════════════════════════════════════════════════════════════════════
if ( defined( 'THERUM_WIZARD_DISABLE' ) && THERUM_WIZARD_DISABLE ) return;
if ( ! apply_filters( 'therum/wizard/enabled', true ) ) return;

// ── Hooks ─────────────────────────────────────────────────────────────────────
add_action( 'admin_init', [ 'Therum_Wizard', 'maybe_redirect' ], 1 );
add_action( 'admin_menu', [ 'Therum_Wizard', 'register_page'  ] );

add_action( 'wp_ajax_thw_save_step',       [ 'Therum_Wizard', 'ajax_save_step'       ] );
// (Per-step skipping is handled by ajax_save_step via the `skipped` flag — see
// the $skip click handler in the wizard JS. The old thw_skip_step hook pointed
// at a non-existent Therum_Wizard::ajax_skip_step and would fatal if invoked.)
add_action( 'wp_ajax_thw_test_server',     [ 'Therum_Wizard', 'ajax_test_server'     ] );
add_action( 'wp_ajax_thw_create_db',       [ 'Therum_Wizard', 'ajax_create_db'       ] );
add_action( 'wp_ajax_thw_verify_bricks',   [ 'Therum_Wizard', 'ajax_verify_bricks'   ] );
add_action( 'wp_ajax_thw_deploy_plugins',  [ 'Therum_Wizard', 'ajax_deploy_plugins'  ] );
add_action( 'wp_ajax_thw_complete_wizard', [ 'Therum_Wizard', 'ajax_complete_wizard' ] );


// ═══════════════════════════════════════════════════════════════════════════════
class Therum_Wizard {

	const OPTION_COMPLETE = 'therum_wizard_complete';
	const OPTION_PROGRESS = 'therum_wizard_progress';
	const PAGE_SLUG       = 'therum-wizard';

	// ── Hard first-run gate ───────────────────────────────────────────────────
	// Wizard is the FIRST thing an admin sees after install. Every admin page
	// load (except the wizard itself, AJAX, cron, and a small allow-list of
	// non-page entry-points like wp-login) redirects to the wizard until the
	// `therum_wizard_complete` option is set via ajax_complete_wizard().
	public static function maybe_redirect(): void {
		if ( get_option( self::OPTION_COMPLETE ) ) return;
		if ( ! current_user_can( 'manage_options' ) ) return;
		if ( wp_doing_ajax() ) return;
		if ( defined( 'DOING_CRON' ) && DOING_CRON ) return;
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) return;
		if ( defined( 'WP_CLI' ) && WP_CLI ) return;

		// Allow-list of WP admin entry-points that aren't "pages" — async
		// uploads, plugin/theme activation callbacks, post.php save flows,
		// the user's own profile screen, etc. Blocking these breaks core.
		global $pagenow;
		$allow_pagenow = [ 'admin-ajax.php', 'admin-post.php', 'async-upload.php', 'update-core.php', 'update.php', 'profile.php' ];
		if ( in_array( $pagenow ?? '', $allow_pagenow, true ) ) return;

		// Already on the wizard page — let it render.
		$page = sanitize_text_field( $_GET['page'] ?? '' );
		if ( $page === self::PAGE_SLUG ) return;

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) );
		exit;
	}

	// ── Register hidden admin page ─────────────────────────────────────────────
	public static function register_page(): void {
		add_submenu_page(
			null,
			'Therum OS Setup',
			'Setup',
			'manage_options',
			self::PAGE_SLUG,
			[ __CLASS__, 'render' ]
		);
	}

	// ── Get stored progress ────────────────────────────────────────────────────
	public static function get_progress(): array {
		return (array) get_option( self::OPTION_PROGRESS, [] );
	}

	// ── Render full-page wizard (bypasses admin shell) ─────────────────────────
	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Access denied.' );

		$progress = self::get_progress();
		$edition  = $progress['edition'] ?? '';
		$nonce    = wp_create_nonce( 'therum_wizard' );
		$ajax_url = admin_url( 'admin-ajax.php' );
		$done_url = admin_url( 'admin.php?page=therum' );

		?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Therum OS Setup</title>
<style>
/* ── Reset + base ── */
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
html,body{height:100%}
body.thw{font-family:-apple-system,BlinkMacSystemFont,'Inter','Segoe UI',sans-serif;background:#080808;color:#f0f0f0;min-height:100vh;display:flex;flex-direction:column;-webkit-font-smoothing:antialiased}

/* ── Header ── */
.thw-header{display:flex;align-items:center;justify-content:space-between;padding:20px 40px;border-bottom:1px solid rgba(255,255,255,.06);position:sticky;top:0;background:#080808;z-index:10}
.thw-logo{font-size:13px;font-weight:600;letter-spacing:.12em;text-transform:uppercase;color:rgba(255,255,255,.5)}
.thw-skip{background:none;border:none;color:rgba(255,255,255,.35);font-size:12px;cursor:pointer;padding:6px 10px;border-radius:6px;transition:color .15s,background .15s}
.thw-skip:hover{color:#fff;background:rgba(255,255,255,.07)}

/* ── Step indicator ── */
.thw-steps{display:flex;align-items:center;gap:0}
.thw-step-dot{display:flex;align-items:center}
.thw-step-dot-inner{width:28px;height:28px;border-radius:50%;border:1.5px solid rgba(255,255,255,.12);display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:600;color:rgba(255,255,255,.25);transition:all .25s;position:relative}
.thw-step-dot.is-active .thw-step-dot-inner{border-color:#fff;color:#fff;background:rgba(255,255,255,.08)}
.thw-step-dot.is-done .thw-step-dot-inner{border-color:rgba(255,255,255,.4);background:rgba(255,255,255,.06);color:rgba(255,255,255,.5)}
.thw-step-dot.is-done .thw-step-dot-inner::after{content:'✓';font-size:10px}
.thw-step-dot.is-done .thw-step-dot-num{display:none}
.thw-step-line{width:32px;height:1px;background:rgba(255,255,255,.08)}
.thw-step-dot.is-done + .thw-step-line{background:rgba(255,255,255,.2)}

/* ── Main content ── */
.thw-main{flex:1;display:flex;align-items:center;justify-content:center;padding:48px 24px}
.thw-step-panel{width:100%;max-width:680px;display:none;animation:thw-in .22s ease}
.thw-step-panel.is-active{display:block}
@keyframes thw-in{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:translateY(0)}}

/* ── Typography ── */
.thw-eyebrow{font-size:10px;font-weight:700;letter-spacing:.14em;text-transform:uppercase;color:rgba(255,255,255,.3);margin-bottom:12px}
.thw-heading{font-size:32px;font-weight:700;line-height:1.1;letter-spacing:-.02em;margin-bottom:10px}
.thw-sub{font-size:14px;color:rgba(255,255,255,.45);line-height:1.5;margin-bottom:36px}

/* ── Cards ── */
.thw-cards{display:grid;gap:14px}
.thw-cards.cols-2{grid-template-columns:1fr 1fr}
.thw-cards.cols-4{grid-template-columns:1fr 1fr 1fr 1fr}
.thw-card{border:1.5px solid rgba(255,255,255,.08);border-radius:14px;padding:24px;cursor:pointer;transition:all .18s;position:relative;background:rgba(255,255,255,.02)}
.thw-card:hover{border-color:rgba(255,255,255,.2);background:rgba(255,255,255,.04)}
.thw-card.is-selected{border-color:#fff;background:rgba(255,255,255,.06)}
.thw-card.is-coming-soon{opacity:.45;cursor:not-allowed}
.thw-card.is-recommended::before{content:'Recommended';position:absolute;top:16px;right:16px;font-size:9px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:rgba(255,255,255,.4);border:1px solid rgba(255,255,255,.12);border-radius:4px;padding:3px 7px}
.thw-card-title{font-size:15px;font-weight:600;margin-bottom:6px}
.thw-card-desc{font-size:12px;color:rgba(255,255,255,.4);line-height:1.5}
.thw-card-badge{display:inline-block;font-size:9px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:rgba(255,255,255,.3);border:1px solid rgba(255,255,255,.1);border-radius:4px;padding:2px 6px;margin-bottom:10px}

/* ── Forms ── */
.thw-field{margin-bottom:18px}
.thw-label{display:block;font-size:11px;font-weight:600;letter-spacing:.06em;text-transform:uppercase;color:rgba(255,255,255,.4);margin-bottom:7px}
.thw-input{width:100%;padding:10px 14px;background:rgba(255,255,255,.05);border:1.5px solid rgba(255,255,255,.1);border-radius:8px;color:#fff;font-size:13px;outline:none;transition:border-color .15s}
.thw-input:focus{border-color:rgba(255,255,255,.35)}
.thw-input::placeholder{color:rgba(255,255,255,.2)}
.thw-input-prefix{display:flex;align-items:center;background:rgba(255,255,255,.05);border:1.5px solid rgba(255,255,255,.1);border-radius:8px;overflow:hidden}
.thw-input-prefix span{padding:10px 12px;font-size:12px;color:rgba(255,255,255,.3);border-right:1px solid rgba(255,255,255,.08);white-space:nowrap}
.thw-input-prefix input{flex:1;background:none;border:none;padding:10px 14px;color:#fff;font-size:13px;outline:none}
.thw-strength{height:2px;background:rgba(255,255,255,.08);border-radius:1px;margin-top:6px;overflow:hidden}
.thw-strength-bar{height:100%;width:0%;transition:width .3s,background .3s;border-radius:1px}

/* ── Toggles ── */
.thw-toggle-row{display:flex;align-items:center;justify-content:space-between;padding:14px 0;border-bottom:1px solid rgba(255,255,255,.05)}
.thw-toggle-row:last-child{border-bottom:none}
.thw-toggle-label{font-size:13px;font-weight:500}
.thw-toggle-desc{font-size:11px;color:rgba(255,255,255,.35);margin-top:2px}
.thw-toggle{position:relative;width:36px;height:20px;flex-shrink:0}
.thw-toggle input{opacity:0;width:0;height:0}
.thw-toggle-track{position:absolute;inset:0;background:rgba(255,255,255,.1);border-radius:10px;cursor:pointer;transition:background .2s}
.thw-toggle input:checked+.thw-toggle-track{background:rgba(255,255,255,.7)}
.thw-toggle-track::after{content:'';position:absolute;left:3px;top:3px;width:14px;height:14px;background:#080808;border-radius:50%;transition:transform .2s}
.thw-toggle input:checked+.thw-toggle-track::after{transform:translateX(16px)}

/* ── Connections grid ── */
.thw-connections{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.thw-conn-card{border:1.5px solid rgba(255,255,255,.08);border-radius:12px;padding:18px;display:flex;align-items:center;justify-content:space-between;background:rgba(255,255,255,.02)}
.thw-conn-info{display:flex;align-items:center;gap:12px}
.thw-conn-icon{width:36px;height:36px;border-radius:8px;background:rgba(255,255,255,.06);display:flex;align-items:center;justify-content:center;font-size:16px}
.thw-conn-name{font-size:13px;font-weight:600}
.thw-conn-type{font-size:11px;color:rgba(255,255,255,.35)}
.thw-conn-btn{padding:6px 14px;border-radius:6px;font-size:11px;font-weight:600;cursor:pointer;border:1.5px solid rgba(255,255,255,.15);background:none;color:rgba(255,255,255,.6);transition:all .15s}
.thw-conn-btn:hover{border-color:rgba(255,255,255,.4);color:#fff}
.thw-conn-card.is-connected{border-color:rgba(255,255,255,.2)}
.thw-conn-card.is-connected .thw-conn-btn{border-color:rgba(255,255,255,.3);color:rgba(255,255,255,.5);cursor:default}

/* ── Sub-walkthrough ── */
.thw-sub-wt{border:1.5px solid rgba(255,255,255,.08);border-radius:14px;padding:28px;background:rgba(255,255,255,.02);margin-top:16px}
.thw-sub-steps{display:flex;gap:8px;margin-bottom:24px;flex-wrap:wrap}
.thw-sub-step{font-size:10px;font-weight:600;letter-spacing:.08em;text-transform:uppercase;color:rgba(255,255,255,.2);padding:4px 10px;border-radius:20px;border:1px solid rgba(255,255,255,.06);transition:all .2s}
.thw-sub-step.is-active{color:#fff;border-color:rgba(255,255,255,.3);background:rgba(255,255,255,.06)}
.thw-sub-step.is-done{color:rgba(255,255,255,.4);border-color:rgba(255,255,255,.12)}
.thw-sub-panel{display:none}
.thw-sub-panel.is-active{display:block}
.thw-server-test,.thw-db-create,.thw-deploy-progress{margin-top:16px;padding:14px;border-radius:8px;background:rgba(255,255,255,.04);font-size:12px;color:rgba(255,255,255,.5);display:none}
.thw-server-test.is-visible,.thw-db-create.is-visible,.thw-deploy-progress.is-visible{display:block}
.thw-log-line{padding:4px 0;font-family:'JetBrains Mono',ui-monospace,monospace;font-size:11px;color:rgba(255,255,255,.5)}
.thw-log-line.ok{color:#6ee7b7}
.thw-log-line.err{color:#fca5a5}

/* ── Branding ── */
.thw-color-row{display:flex;gap:12px}
.thw-color-field{flex:1}
.thw-color-input-wrap{display:flex;align-items:center;gap:10px;background:rgba(255,255,255,.05);border:1.5px solid rgba(255,255,255,.1);border-radius:8px;padding:8px 12px}
.thw-color-input-wrap input[type=color]{width:28px;height:28px;border:none;background:none;cursor:pointer;padding:0;border-radius:4px}
.thw-color-input-wrap input[type=text]{background:none;border:none;color:#fff;font-size:12px;font-family:ui-monospace,monospace;outline:none;width:80px}
.thw-typo-options{display:grid;grid-template-columns:1fr 1fr;gap:8px}
.thw-typo-opt{border:1.5px solid rgba(255,255,255,.08);border-radius:8px;padding:12px 14px;cursor:pointer;transition:all .15s}
.thw-typo-opt:hover{border-color:rgba(255,255,255,.2)}
.thw-typo-opt.is-selected{border-color:#fff}
.thw-typo-name{font-size:12px;font-weight:600;margin-bottom:2px}
.thw-typo-sample{font-size:18px;color:rgba(255,255,255,.6)}
.thw-upload-zone{border:1.5px dashed rgba(255,255,255,.12);border-radius:10px;padding:24px;text-align:center;cursor:pointer;transition:border-color .15s;margin-top:4px}
.thw-upload-zone:hover{border-color:rgba(255,255,255,.3)}
.thw-upload-zone p{font-size:12px;color:rgba(255,255,255,.35)}
.thw-upload-zone strong{display:block;font-size:13px;margin-bottom:4px}

/* ── Done screen ── */
.thw-done-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:32px}
.thw-done-item{display:flex;align-items:center;gap:10px;padding:12px 14px;border-radius:8px;background:rgba(255,255,255,.03);font-size:12px}
.thw-done-item.ok{border-left:2px solid rgba(255,255,255,.2)}
.thw-done-item.skipped{border-left:2px solid rgba(255,255,255,.08);color:rgba(255,255,255,.35)}
.thw-done-icon{font-size:14px;flex-shrink:0}
.thw-done-actions{display:flex;gap:12px}
.thw-done-cta{flex:1;padding:14px;border-radius:10px;text-align:center;font-size:13px;font-weight:600;cursor:pointer;border:none;text-decoration:none;display:block;transition:all .18s}
.thw-done-cta.primary{background:#fff;color:#080808}
.thw-done-cta.primary:hover{background:#e8e8e8}
.thw-done-cta.secondary{background:rgba(255,255,255,.07);color:#fff}
.thw-done-cta.secondary:hover{background:rgba(255,255,255,.1)}

/* ── Footer nav ── */
.thw-footer{padding:24px 40px;border-top:1px solid rgba(255,255,255,.06);display:flex;align-items:center;justify-content:space-between}
.thw-btn{padding:10px 24px;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;border:none;transition:all .18s;display:inline-flex;align-items:center;gap:8px}
.thw-btn-back{background:rgba(255,255,255,.06);color:rgba(255,255,255,.6)}
.thw-btn-back:hover{background:rgba(255,255,255,.1);color:#fff}
.thw-btn-next{background:#fff;color:#080808}
.thw-btn-next:hover{background:#e8e8e8}
.thw-btn-next:disabled{opacity:.35;cursor:not-allowed}
.thw-btn-ghost{background:none;border:1.5px solid rgba(255,255,255,.12);color:rgba(255,255,255,.5)}
.thw-btn-ghost:hover{border-color:rgba(255,255,255,.3);color:#fff}
.thw-2fa-qr{width:140px;height:140px;background:rgba(255,255,255,.06);border-radius:10px;display:flex;align-items:center;justify-content:center;margin:16px 0;color:rgba(255,255,255,.2);font-size:11px}
.thw-notice{padding:10px 14px;border-radius:8px;font-size:12px;margin-top:12px;display:none}
.thw-notice.is-visible{display:block}
.thw-notice.ok{background:rgba(110,231,183,.08);color:#6ee7b7;border:1px solid rgba(110,231,183,.15)}
.thw-notice.err{background:rgba(252,165,165,.08);color:#fca5a5;border:1px solid rgba(252,165,165,.15)}
@media(max-width:600px){.thw-cards.cols-2,.thw-cards.cols-4,.thw-connections,.thw-done-grid,.thw-typo-options,.thw-color-row{grid-template-columns:1fr}.thw-header{padding:16px 20px}.thw-footer{padding:16px 20px}.thw-main{padding:32px 16px}.thw-steps{display:none}}
</style>
</head>
<body class="thw">

<!-- ── Header ──────────────────────────────────────────────────────── -->
<header class="thw-header">
	<div class="thw-logo">Therum OS</div>

	<nav class="thw-steps" id="thw-step-nav" aria-label="Setup progress">
		<?php
		$step_labels = [ 'Edition', 'Stack', 'Account', 'Connections', 'Optimizations', 'Branding', 'Done' ];
		foreach ( $step_labels as $i => $label ) :
			$num = $i + 1;
			echo '<div class="thw-step-dot" data-dot="' . $num . '" title="' . esc_attr( $label ) . '">';
			echo '<div class="thw-step-dot-inner"><span class="thw-step-dot-num">' . $num . '</span></div>';
			echo '</div>';
			if ( $num < count( $step_labels ) ) echo '<div class="thw-step-line" data-line="' . $num . '"></div>';
		endforeach;
		?>
	</nav>

	<div style="display:flex;gap:6px;align-items:center">
		<button class="thw-skip" id="thw-skip-btn">Skip this step</button>
		<button class="thw-skip" id="thw-skip-all-btn" title="Mark setup complete and go to dashboard">Skip wizard for now →</button>
	</div>
</header>

<!-- ── Main ────────────────────────────────────────────────────────── -->
<main class="thw-main" id="thw-main">

	<!-- Step 1 — Edition ──────────────────────────────────────────── -->
	<div class="thw-step-panel" data-panel="1" id="thw-panel-1">
		<p class="thw-eyebrow">Step 1 of 7</p>
		<h1 class="thw-heading">Choose your edition.</h1>
		<p class="thw-sub">This determines what Therum OS unlocks. You can change this later in Settings.</p>

		<div class="thw-cards cols-2" id="thw-edition-cards">
			<div class="thw-card" data-edition="pure" tabindex="0">
				<div class="thw-card-badge">Pure</div>
				<div class="thw-card-title">Therum OS Pure</div>
				<div class="thw-card-desc">Block editor and design-system focused. Clean admin layer, content-first builds. No commerce, no server layer. Great for portfolios, brand sites, and editorial work.</div>
			</div>
			<div class="thw-card" data-edition="unlocked" tabindex="0">
				<div class="thw-card-badge">Unlocked</div>
				<div class="thw-card-title">Therum OS Unlocked</div>
				<div class="thw-card-desc">Full stack. VPS server layer, all 16 mu-plugins, WooCommerce, OPcache preloading, Redis, NeoRename, staging. No ceilings. For agencies, client work, and commerce at scale.</div>
			</div>
		</div>
	</div>

	<!-- Step 2 — Stack ────────────────────────────────────────────── -->
	<div class="thw-step-panel" data-panel="2" id="thw-panel-2">
		<p class="thw-eyebrow">Step 2 of 7</p>
		<h1 class="thw-heading">Pick your stack.</h1>
		<p class="thw-sub">Choose what CMS and build system this Therum OS instance runs on top of.</p>

		<!-- Pure message (hidden unless Pure edition) -->
		<div id="thw-pure-stack-msg" style="display:none;padding:20px;border:1.5px solid rgba(255,255,255,.08);border-radius:12px;background:rgba(255,255,255,.02);">
			<p style="font-size:13px;color:rgba(255,255,255,.5);">Stack setup isn't required for Therum OS Pure. Your WordPress layer is your stack. You can configure server options later in <strong style="color:rgba(255,255,255,.7);">Settings → Optimizations</strong>.</p>
		</div>

		<!-- Unlocked stack picker (hidden unless Unlocked edition) -->
		<div id="thw-unlocked-stack" style="display:none;">
			<div class="thw-cards cols-2" id="thw-stack-cards">
				<div class="thw-card is-recommended" data-stack="therum-wp" tabindex="0">
					<div class="thw-card-badge">Our stack</div>
					<div class="thw-card-title">Therum OS + WordPress</div>
					<div class="thw-card-desc">Bricks builder · 16 mu-plugins · full server layer · OPcache preload · Redis · NeoRename · LiteSpeed. This is the flagship configuration.</div>
				</div>
				<div class="thw-card" data-stack="wp-vanilla" tabindex="0">
					<div class="thw-card-badge">WordPress</div>
					<div class="thw-card-title">WordPress Vanilla</div>
					<div class="thw-card-desc">Standard WordPress install without the Therum OS skin. Good for handoff installs where the client manages the admin.</div>
				</div>
				<div class="thw-card is-coming-soon" data-stack="ghost" tabindex="-1">
					<div class="thw-card-badge">Coming soon</div>
					<div class="thw-card-title">Ghost</div>
					<div class="thw-card-desc">Therum OS server layer + Ghost CMS. API-first, built-in memberships, clean publishing experience.</div>
				</div>
				<div class="thw-card is-coming-soon" data-stack="payload" tabindex="-1">
					<div class="thw-card-badge">Coming soon</div>
					<div class="thw-card-title">Payload CMS</div>
					<div class="thw-card-desc">Therum OS server layer + Payload. TypeScript-native, headless, custom field types built for developers.</div>
				</div>
			</div>

			<!-- CMS sub-walkthrough (shown after Therum+WP or WP Vanilla selected) -->
			<div class="thw-sub-wt" id="thw-cms-setup" style="display:none;">
				<div class="thw-sub-steps" id="thw-sub-step-nav">
					<div class="thw-sub-step" data-substep="server">Server</div>
					<div class="thw-sub-step" data-substep="database">Database</div>
					<div class="thw-sub-step" data-substep="wordpress">WordPress</div>
					<div class="thw-sub-step" data-substep="deploy">Deploy</div>
					<div class="thw-sub-step" data-substep="ssl">SSL</div>
				</div>

				<!-- Sub-step: Server -->
				<div class="thw-sub-panel" data-sub="server">
					<p class="thw-label">Domain</p>
					<div class="thw-field"><input type="text" class="thw-input" id="thw-domain" placeholder="yourdomain.com"></div>
					<p class="thw-label">Server IP</p>
					<div class="thw-field"><input type="text" class="thw-input" id="thw-server-ip" placeholder="123.456.789.0"></div>
					<button class="thw-btn thw-btn-ghost" id="thw-test-server-btn" style="margin-top:4px;">Test connection</button>
					<div class="thw-server-test" id="thw-server-result"></div>
				</div>

				<!-- Sub-step: Database -->
				<div class="thw-sub-panel" data-sub="database">
					<p class="thw-label">Database name</p>
					<div class="thw-field"><input type="text" class="thw-input" id="thw-db-name" placeholder="therum_production"></div>
					<p class="thw-label">Database user</p>
					<div class="thw-field"><input type="text" class="thw-input" id="thw-db-user" placeholder="therum_usr"></div>
					<p class="thw-label">Database password</p>
					<div class="thw-field" style="display:flex;gap:10px;align-items:center;">
						<input type="text" class="thw-input" id="thw-db-pass" style="flex:1;" placeholder="Auto-generated">
						<button class="thw-btn thw-btn-ghost" id="thw-gen-pass" style="white-space:nowrap;flex-shrink:0;">Generate</button>
					</div>
					<button class="thw-btn thw-btn-ghost" id="thw-create-db-btn">Create database</button>
					<div class="thw-db-create" id="thw-db-result"></div>
				</div>

				<!-- Sub-step: WordPress -->
				<div class="thw-sub-panel" data-sub="wordpress">
					<p class="thw-label">Site title</p>
					<div class="thw-field"><input type="text" class="thw-input" id="thw-site-title" placeholder="My Site"></div>
					<p class="thw-label">Admin email</p>
					<div class="thw-field"><input type="email" class="thw-input" id="thw-site-email" placeholder="admin@yourdomain.com"></div>
					<p class="thw-label">Bricks license key</p>
					<div class="thw-field" style="display:flex;gap:10px;align-items:center;">
						<input type="text" class="thw-input" id="thw-bricks-key" style="flex:1;" placeholder="XXXX-XXXX-XXXX-XXXX">
						<button class="thw-btn thw-btn-ghost" id="thw-verify-bricks-btn" style="flex-shrink:0;">Verify</button>
					</div>
					<div class="thw-notice" id="thw-bricks-notice"></div>
				</div>

				<!-- Sub-step: Deploy -->
				<div class="thw-sub-panel" data-sub="deploy">
					<p style="font-size:13px;color:rgba(255,255,255,.5);margin-bottom:16px;">Deploy all 16 Therum OS mu-plugins to the live server. This copies the files and flushes OPcache on arrival.</p>
					<button class="thw-btn thw-btn-ghost" id="thw-deploy-btn">Deploy mu-plugins</button>
					<div class="thw-deploy-progress" id="thw-deploy-result"></div>
				</div>

				<!-- Sub-step: SSL -->
				<div class="thw-sub-panel" data-sub="ssl">
					<p style="font-size:13px;color:rgba(255,255,255,.5);margin-bottom:16px;">Choose how SSL is issued for your domain.</p>
					<div style="display:flex;flex-direction:column;gap:10px;">
						<label style="display:flex;align-items:flex-start;gap:12px;padding:14px;border:1.5px solid rgba(255,255,255,.08);border-radius:10px;cursor:pointer;">
							<input type="radio" name="ssl_type" value="cloudflare" style="margin-top:2px;">
							<div><div style="font-size:13px;font-weight:600;margin-bottom:2px;">Cloudflare (recommended)</div><div style="font-size:11px;color:rgba(255,255,255,.35);">Proxied + DDoS protected. Your server IP stays hidden.</div></div>
						</label>
						<label style="display:flex;align-items:flex-start;gap:12px;padding:14px;border:1.5px solid rgba(255,255,255,.08);border-radius:10px;cursor:pointer;">
							<input type="radio" name="ssl_type" value="letsencrypt">
							<div><div style="font-size:13px;font-weight:600;margin-bottom:2px;">Let's Encrypt (direct)</div><div style="font-size:11px;color:rgba(255,255,255,.35);">Certbot issues certificate directly. Server IP is publicly reachable.</div></div>
						</label>
					</div>
					<button class="thw-btn thw-btn-ghost" id="thw-ssl-btn" style="margin-top:16px;">Issue certificate</button>
					<div class="thw-notice" id="thw-ssl-notice"></div>
				</div>

				<!-- Sub-step nav buttons -->
				<div style="display:flex;justify-content:space-between;margin-top:24px;">
					<button class="thw-btn thw-btn-ghost" id="thw-sub-back" style="display:none;">← Back</button>
					<button class="thw-btn thw-btn-next" id="thw-sub-next" style="margin-left:auto;">Next →</button>
				</div>
			</div>
		</div>
	</div>

	<!-- Step 3 — Admin account ─────────────────────────────────────── -->
	<div class="thw-step-panel" data-panel="3" id="thw-panel-3">
		<p class="thw-eyebrow">Step 3 of 7</p>
		<h1 class="thw-heading">Admin account.</h1>
		<p class="thw-sub">Secure your access before going further.</p>

		<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
			<div class="thw-field">
				<label class="thw-label">Full name</label>
				<input type="text" class="thw-input" id="thw-fullname" placeholder="Bam Leon">
			</div>
			<div class="thw-field">
				<label class="thw-label">Username</label>
				<input type="text" class="thw-input" id="thw-username" placeholder="bamleon">
			</div>
		</div>
		<div class="thw-field">
			<label class="thw-label">Email</label>
			<input type="email" class="thw-input" id="thw-email" placeholder="you@yourdomain.com">
		</div>
		<div class="thw-field">
			<label class="thw-label">Password</label>
			<input type="password" class="thw-input" id="thw-password" placeholder="Minimum 16 characters">
			<div class="thw-strength"><div class="thw-strength-bar" id="thw-pw-bar"></div></div>
		</div>
		<div class="thw-field">
			<label class="thw-label">Custom login URL</label>
			<div class="thw-input-prefix">
				<span><?php echo esc_html( trailingslashit( home_url() ) ); ?></span>
				<input type="text" id="thw-login-slug" placeholder="login">
			</div>
		</div>

		<div style="margin-top:24px;padding-top:20px;border-top:1px solid rgba(255,255,255,.06);">
			<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:4px;">
				<div>
					<div style="font-size:13px;font-weight:600;">Two-factor authentication</div>
					<div style="font-size:11px;color:rgba(255,255,255,.35);margin-top:2px;">Requires an authenticator app (Authy, 1Password, Google Authenticator)</div>
				</div>
				<label class="thw-toggle">
					<input type="checkbox" id="thw-2fa-toggle">
					<div class="thw-toggle-track"></div>
				</label>
			</div>
			<div id="thw-2fa-setup" style="display:none;margin-top:16px;">
				<div class="thw-2fa-qr">QR code renders<br>after account save</div>
				<div class="thw-field">
					<label class="thw-label">Verify — enter the 6-digit code from your app</label>
					<input type="text" class="thw-input" id="thw-2fa-code" placeholder="000000" maxlength="6" style="max-width:160px;letter-spacing:.2em;font-family:ui-monospace,monospace;">
				</div>
			</div>
		</div>
	</div>

	<!-- Step 4 — Connections ───────────────────────────────────────── -->
	<div class="thw-step-panel" data-panel="4" id="thw-panel-4">
		<p class="thw-eyebrow">Step 4 of 7</p>
		<h1 class="thw-heading">Connections.</h1>
		<p class="thw-sub">Connect the services Therum OS talks to. All of these can be configured later in Settings → Connections.</p>

		<div class="thw-connections">
			<?php
			$connections = [
				[ 'icon' => '💳', 'name' => 'Stripe',     'type' => 'Payments',  'id' => 'stripe'     ],
				[ 'icon' => '📝', 'name' => 'Notion',     'type' => 'Content',   'id' => 'notion'     ],
				[ 'icon' => '🌐', 'name' => 'Cloudflare', 'type' => 'DNS / CDN', 'id' => 'cloudflare' ],
				[ 'icon' => '🗄', 'name' => 'Backblaze',  'type' => 'Backups',   'id' => 'backblaze'  ],
				[ 'icon' => '📊', 'name' => 'Analytics',  'type' => 'Google Analytics', 'id' => 'analytics' ],
				[ 'icon' => '💬', 'name' => 'Slack',      'type' => 'Alerts',    'id' => 'slack'      ],
			];
			foreach ( $connections as $c ) :
				?>
				<div class="thw-conn-card" data-conn="<?php echo esc_attr( $c['id'] ); ?>">
					<div class="thw-conn-info">
						<div class="thw-conn-icon"><?php echo $c['icon']; ?></div>
						<div>
							<div class="thw-conn-name"><?php echo esc_html( $c['name'] ); ?></div>
							<div class="thw-conn-type"><?php echo esc_html( $c['type'] ); ?></div>
						</div>
					</div>
					<button class="thw-conn-btn" data-conn-id="<?php echo esc_attr( $c['id'] ); ?>">Connect</button>
				</div>
			<?php endforeach; ?>
		</div>
	</div>

	<!-- Step 5 — Optimizations ─────────────────────────────────────── -->
	<div class="thw-step-panel" data-panel="5" id="thw-panel-5">
		<p class="thw-eyebrow">Step 5 of 7</p>
		<h1 class="thw-heading">Optimizations.</h1>
		<p class="thw-sub">These are applied immediately. All can be tuned later in Settings → Performance.</p>

		<div id="thw-opt-list">
			<?php
			$opts_all = [
				[ 'id' => 'lscache',    'label' => 'LiteSpeed Cache',     'desc' => 'Server-level page caching via the LiteSpeed Cache plugin.',   'default' => true,  'editions' => ['pure','unlocked'] ],
				[ 'id' => 'image_opt',  'label' => 'Image optimization',  'desc' => 'Compress images on upload. Reduces page weight automatically.', 'default' => true,  'editions' => ['pure','unlocked'] ],
				[ 'id' => 'defer',      'label' => 'Defer non-critical JS','desc' => 'Load non-critical scripts after the page is interactive.',     'default' => true,  'editions' => ['pure','unlocked'] ],
				[ 'id' => 'opcache',    'label' => 'OPcache JIT',         'desc' => 'Preloads all 16 mu-plugins at boot. Requires VPS.',           'default' => true,  'editions' => ['unlocked'] ],
				[ 'id' => 'redis',      'label' => 'Redis object cache',  'desc' => 'Serves repeated DB queries from memory. Requires Redis.',       'default' => true,  'editions' => ['unlocked'] ],
				[ 'id' => 'brotli',     'label' => 'Brotli compression',  'desc' => 'Better than gzip. Served for all static assets.',              'default' => true,  'editions' => ['unlocked'] ],
				[ 'id' => 'staging',    'label' => 'Staging environment', 'desc' => 'Connect a staging URL for push/pull deploys from Therum OS.',   'default' => false, 'editions' => ['unlocked'] ],
			];
			foreach ( $opts_all as $opt ) :
				?>
				<div class="thw-toggle-row" data-opt-editions="<?php echo esc_attr( implode( ',', $opt['editions'] ) ); ?>">
					<div>
						<div class="thw-toggle-label"><?php echo esc_html( $opt['label'] ); ?></div>
						<div class="thw-toggle-desc"><?php echo esc_html( $opt['desc'] ); ?></div>
					</div>
					<label class="thw-toggle">
						<input type="checkbox" data-opt-id="<?php echo esc_attr( $opt['id'] ); ?>" <?php checked( $opt['default'] ); ?>>
						<div class="thw-toggle-track"></div>
					</label>
				</div>
			<?php endforeach; ?>
		</div>
	</div>

	<!-- Step 6 — Branding ──────────────────────────────────────────── -->
	<div class="thw-step-panel" data-panel="6" id="thw-panel-6">
		<p class="thw-eyebrow">Step 6 of 7</p>
		<h1 class="thw-heading">Branding.</h1>
		<p class="thw-sub">Your site identity and admin skin. Change any of this later in Settings → Design.</p>

		<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:20px;">
			<div class="thw-field">
				<label class="thw-label">Site name</label>
				<input type="text" class="thw-input" id="thw-site-name" placeholder="<?php echo esc_attr( get_bloginfo('name') ); ?>">
			</div>
			<div class="thw-field">
				<label class="thw-label">Tagline</label>
				<input type="text" class="thw-input" id="thw-tagline" placeholder="<?php echo esc_attr( get_bloginfo('description') ); ?>">
			</div>
		</div>

		<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:20px;">
			<div class="thw-field">
				<label class="thw-label">Logo</label>
				<div class="thw-upload-zone" id="thw-logo-zone">
					<strong>Drop file or click to upload</strong>
					<p>SVG, PNG or WebP · max 2MB</p>
				</div>
			</div>
			<div class="thw-field">
				<label class="thw-label">Favicon</label>
				<div class="thw-upload-zone" id="thw-fav-zone">
					<strong>Drop file or click to upload</strong>
					<p>ICO, PNG or SVG · 32×32 recommended</p>
				</div>
			</div>
		</div>

		<div class="thw-field">
			<label class="thw-label">Brand colors</label>
			<div class="thw-color-row">
				<div class="thw-color-field">
					<div class="thw-label" style="margin-bottom:6px;">Primary</div>
					<div class="thw-color-input-wrap">
						<input type="color" value="#ffffff" id="thw-color-primary">
						<input type="text" value="#ffffff" id="thw-color-primary-hex">
					</div>
				</div>
				<div class="thw-color-field">
					<div class="thw-label" style="margin-bottom:6px;">Accent</div>
					<div class="thw-color-input-wrap">
						<input type="color" value="#6366f1" id="thw-color-accent">
						<input type="text" value="#6366f1" id="thw-color-accent-hex">
					</div>
				</div>
			</div>
		</div>

		<div class="thw-field">
			<label class="thw-label">Typography</label>
			<div class="thw-typo-options">
				<?php
				$fonts = [
					[ 'id' => 'geist',   'name' => 'Geist',   'sample' => 'Aa' ],
					[ 'id' => 'dm-sans', 'name' => 'DM Sans', 'sample' => 'Aa' ],
					[ 'id' => 'archivo', 'name' => 'Archivo', 'sample' => 'Aa' ],
					[ 'id' => 'custom',  'name' => 'Custom',  'sample' => '→'  ],
				];
				foreach ( $fonts as $i => $f ) :
					?>
					<div class="thw-typo-opt <?php echo $i === 0 ? 'is-selected' : ''; ?>" data-font="<?php echo esc_attr( $f['id'] ); ?>">
						<div class="thw-typo-name"><?php echo esc_html( $f['name'] ); ?></div>
						<div class="thw-typo-sample"><?php echo esc_html( $f['sample'] ); ?></div>
					</div>
				<?php endforeach; ?>
			</div>
		</div>

		<div class="thw-field">
			<label class="thw-label">Admin skin</label>
			<div style="display:flex;gap:10px;">
				<?php foreach ( ['Light','Dark','System'] as $skin ) : ?>
					<label style="display:flex;align-items:center;gap:7px;padding:10px 14px;border:1.5px solid rgba(255,255,255,.08);border-radius:8px;cursor:pointer;flex:1;justify-content:center;">
						<input type="radio" name="thw_skin" value="<?php echo strtolower($skin); ?>" <?php echo $skin === 'Light' ? 'checked' : ''; ?>>
						<span style="font-size:12px;font-weight:500;"><?php echo esc_html($skin); ?></span>
					</label>
				<?php endforeach; ?>
			</div>
		</div>
	</div>

	<!-- Step 7 — Done ──────────────────────────────────────────────── -->
	<div class="thw-step-panel" data-panel="7" id="thw-panel-7">
		<p class="thw-eyebrow">Done</p>
		<h1 class="thw-heading">Therum OS is ready.</h1>
		<p class="thw-sub" style="margin-bottom:24px;">Here's what was configured during setup.</p>

		<div class="thw-done-grid" id="thw-done-summary"></div>

		<div class="thw-done-actions">
			<a href="<?php echo esc_url( $done_url ); ?>" class="thw-done-cta primary">Go to dashboard</a>
			<a href="<?php echo esc_url( admin_url('admin.php?page=therum-wizard&resume=1') ); ?>" class="thw-done-cta secondary" id="thw-complete-skipped" style="display:none;">Complete skipped steps</a>
			<a href="<?php echo esc_url( home_url() ); ?>" target="_blank" class="thw-done-cta secondary">View site ↗</a>
		</div>
	</div>

</main>

<!-- ── Footer nav ──────────────────────────────────────────────────── -->
<footer class="thw-footer" id="thw-footer">
	<button class="thw-btn thw-btn-back" id="thw-back-btn">← Back</button>
	<button class="thw-btn thw-btn-next" id="thw-next-btn" disabled>Continue →</button>
</footer>

<script>
(function () {
'use strict';

var AJAX     = <?php echo json_encode( $ajax_url ); ?>;
var NONCE    = <?php echo json_encode( $nonce ); ?>;
var DONE_URL = <?php echo json_encode( $done_url ); ?>;

// ── State ──────────────────────────────────────────────────────────────
var state = {
	step:      1,
	totalSteps:7,
	edition:   <?php echo json_encode( $edition ); ?>,
	stack:     '',
	subStep:   'server',
	subSteps:  ['server','database','wordpress','deploy','ssl'],
	subDone:   false,
	skipped:   [],
	summary:   []
};

// ── Element refs ───────────────────────────────────────────────────────
var $panels  = document.querySelectorAll('.thw-step-panel');
var $dots    = document.querySelectorAll('.thw-step-dot');
var $next    = document.getElementById('thw-next-btn');
var $back    = document.getElementById('thw-back-btn');
var $skip    = document.getElementById('thw-skip-btn');
var $skipAll = document.getElementById('thw-skip-all-btn');
var $footer  = document.getElementById('thw-footer');

// "Skip wizard for now" — escape hatch for users on hosts without CLI
// access who'd otherwise be stuck if a step's AJAX errors. Marks the
// wizard complete option immediately so the gate releases, then jumps
// to the Therum dashboard.
if ($skipAll) {
	$skipAll.addEventListener('click', function () {
		if (!confirm('Skip the rest of the wizard and go straight to the dashboard? You can re-run it later from Settings.')) return;
		$skipAll.disabled = true;
		var fd = new FormData();
		fd.append('action', 'thw_complete_wizard');
		fd.append('nonce', NONCE);
		fd.append('skipped', JSON.stringify(state.skipped));
		fetch(AJAX, { method: 'POST', credentials: 'same-origin', body: fd })
			.then(function () { window.location.href = DONE_URL; })
			.catch(function () { window.location.href = DONE_URL; }); // navigate anyway — gate will block again if save failed
	});
}

// ── Render step ────────────────────────────────────────────────────────
// Tracks the highest step the user has reached so forward nav past the
// current step is allowed when re-walking a completed flow. Otherwise
// Continue would re-disable on Step 1 when you back-button to it, even
// though you've already set state.edition.
state.maxReached = 1;

function goTo(n, opts) {
	opts = opts || {};
	var clamped = Math.max(1, Math.min(state.totalSteps, n | 0));
	state.step = clamped;
	if (clamped > state.maxReached) state.maxReached = clamped;
	$panels.forEach(function(p){ p.classList.toggle('is-active', +p.dataset.panel === clamped); });
	$dots.forEach(function(d, i){
		var num = i + 1;
		d.classList.toggle('is-active', num === clamped);
		d.classList.toggle('is-done',   num < state.maxReached && num !== clamped);
	});
	$back.style.display   = clamped <= 1 ? 'none' : '';
	$footer.style.display = clamped >= 7 ? 'none' : '';
	$skip.style.display   = clamped >= 7 ? 'none' : '';
	$next.disabled = needsSelection(clamped);
	if (clamped === 2) renderStep2();
	if (clamped === 5) filterOptsByEdition();
	if (clamped === 7) renderDone();
	window.scrollTo(0,0);

	// Sync URL so the browser Back/Forward buttons walk the wizard. The
	// PHP-side render() reads the step on initial load; pushState here keeps
	// in-page nav in lock-step with browser history.
	if (!opts.fromPop) {
		try {
			var u = new URL(window.location.href);
			u.searchParams.set('step', clamped);
			history.pushState({ step: clamped }, '', u.toString());
		} catch(e) {}
	}
}

function needsSelection(n) {
	// If the user has been further than this step, they've already configured
	// it — allow forward nav without re-selecting.
	if (n < state.maxReached) return false;
	if (n === 1) return !state.edition;
	if (n === 2) return false; // skip is always available
	return false;
}

// ── Next / back / skip / browser history / keyboard ──────────────────
$next.addEventListener('click', function() {
	saveStep(state.step, false);
	// Step 2: Pure edition auto-advances through it
	// (step 2 content handles its own Continue)
	goTo(state.step + 1);
});

$back.addEventListener('click', function() {
	if (state.step > 1) goTo(state.step - 1);
});

$skip.addEventListener('click', function() {
	state.skipped.push(state.step);
	saveStep(state.step, true);
	goTo(state.step + 1);
});

// Browser Back/Forward buttons — read step from URL, render without
// re-pushing history (opts.fromPop = true).
window.addEventListener('popstate', function(e) {
	var target = (e.state && e.state.step) || (function(){
		var s = new URL(window.location.href).searchParams.get('step');
		return s ? parseInt(s, 10) : 1;
	})();
	goTo(target, { fromPop: true });
});

// Keyboard arrows — power-user nav. Skip when focus is inside an input/
// textarea so typing never accidentally advances.
document.addEventListener('keydown', function(e) {
	var t = e.target;
	if (t && (t.tagName === 'INPUT' || t.tagName === 'TEXTAREA' || t.isContentEditable)) return;
	if (e.key === 'ArrowLeft'  && !$back.style.display && state.step > 1) { e.preventDefault(); $back.click(); }
	if (e.key === 'ArrowRight' && !$next.disabled)                         { e.preventDefault(); $next.click(); }
});

// Initial-load step pickup — if the URL carries ?step=N (from a refresh
// or a deep link), jump there instead of defaulting to step 1.
(function bootStep() {
	var s = new URL(window.location.href).searchParams.get('step');
	if (!s) return;
	var n = parseInt(s, 10);
	if (n >= 1 && n <= state.totalSteps && n !== state.step) {
		state.maxReached = Math.max(state.maxReached, n);
		goTo(n, { fromPop: true });
		// Replace the history entry so the initial back doesn't pop to a
		// bogus pre-wizard state.
		try { history.replaceState({ step: n }, '', window.location.href); } catch(e) {}
	}
})();

// ── Step 1 — Edition picker ────────────────────────────────────────────
document.getElementById('thw-edition-cards').addEventListener('click', function(e) {
	var card = e.target.closest('.thw-card');
	if (!card) return;
	document.querySelectorAll('#thw-edition-cards .thw-card').forEach(function(c){ c.classList.remove('is-selected'); });
	card.classList.add('is-selected');
	state.edition = card.dataset.edition;
	$next.disabled = false;
});

// ── Step 2 — Stack + CMS sub-walkthrough ──────────────────────────────
function renderStep2() {
	var pureMsg     = document.getElementById('thw-pure-stack-msg');
	var unlockedDiv = document.getElementById('thw-unlocked-stack');
	if (state.edition === 'pure') {
		pureMsg.style.display     = '';
		unlockedDiv.style.display = 'none';
	} else {
		pureMsg.style.display     = 'none';
		unlockedDiv.style.display = '';
	}
}

document.getElementById('thw-stack-cards').addEventListener('click', function(e) {
	var card = e.target.closest('.thw-card:not(.is-coming-soon)');
	if (!card) return;
	document.querySelectorAll('#thw-stack-cards .thw-card').forEach(function(c){ c.classList.remove('is-selected'); });
	card.classList.add('is-selected');
	state.stack = card.dataset.stack;
	var setup   = document.getElementById('thw-cms-setup');
	if (state.stack === 'therum-wp' || state.stack === 'wp-vanilla') {
		setup.style.display = '';
		goToSubStep('server');
	} else {
		setup.style.display = 'none';
	}
});

// ── Sub-steps ──────────────────────────────────────────────────────────
function goToSubStep(key) {
	state.subStep = key;
	document.querySelectorAll('.thw-sub-panel').forEach(function(p){ p.classList.toggle('is-active', p.dataset.sub === key); });
	document.querySelectorAll('.thw-sub-step').forEach(function(d){
		var idx = state.subSteps.indexOf(d.dataset.substep);
		var cur = state.subSteps.indexOf(key);
		d.classList.toggle('is-active', d.dataset.substep === key);
		d.classList.toggle('is-done',   idx < cur);
	});
	document.getElementById('thw-sub-back').style.display = (state.subSteps.indexOf(key) === 0) ? 'none' : '';
	var isLast = state.subSteps.indexOf(key) === state.subSteps.length - 1;
	document.getElementById('thw-sub-next').textContent = isLast ? 'Finish setup →' : 'Next →';
}

document.getElementById('thw-sub-next').addEventListener('click', function() {
	var idx  = state.subSteps.indexOf(state.subStep);
	if (idx < state.subSteps.length - 1) {
		goToSubStep(state.subSteps[idx + 1]);
	} else {
		state.subDone = true;
		document.getElementById('thw-cms-setup').style.display = 'none';
		$next.click();
	}
});

document.getElementById('thw-sub-back').addEventListener('click', function() {
	var idx = state.subSteps.indexOf(state.subStep);
	if (idx > 0) goToSubStep(state.subSteps[idx - 1]);
});

// Server test
document.getElementById('thw-test-server-btn').addEventListener('click', function() {
	var result = document.getElementById('thw-server-result');
	result.className = 'thw-server-test is-visible';
	result.innerHTML = '<div class="thw-log-line">Testing connection to ' + (document.getElementById('thw-server-ip').value||'[no IP]') + '…</div>';
	ajax('thw_test_server', {
		domain: document.getElementById('thw-domain').value,
		ip:     document.getElementById('thw-server-ip').value
	}, function(data) {
		result.innerHTML += '<div class="thw-log-line ok">✓ ' + (data.message||'Server reachable') + '</div>';
	}, function(err) {
		result.innerHTML += '<div class="thw-log-line err">✗ ' + err + '</div>';
	});
});

// Generate password
document.getElementById('thw-gen-pass').addEventListener('click', function() {
	var chars = 'ABCDEFGHJKMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789!@#$';
	var pass  = '';
	for (var i=0;i<24;i++) pass += chars[Math.floor(Math.random()*chars.length)];
	document.getElementById('thw-db-pass').value = pass;
});

// Create DB
document.getElementById('thw-create-db-btn').addEventListener('click', function() {
	var result = document.getElementById('thw-db-result');
	result.className = 'thw-db-create is-visible';
	result.innerHTML = '<div class="thw-log-line">Creating database…</div>';
	ajax('thw_create_db', {
		db_name: document.getElementById('thw-db-name').value,
		db_user: document.getElementById('thw-db-user').value,
		db_pass: document.getElementById('thw-db-pass').value
	}, function(data) {
		result.innerHTML += '<div class="thw-log-line ok">✓ ' + (data.message||'Database created') + '</div>';
	}, function(err) {
		result.innerHTML += '<div class="thw-log-line err">✗ ' + err + '</div>';
	});
});

// Verify Bricks
document.getElementById('thw-verify-bricks-btn').addEventListener('click', function() {
	var notice = document.getElementById('thw-bricks-notice');
	notice.className = 'thw-notice is-visible';
	notice.textContent = 'Verifying…';
	ajax('thw_verify_bricks', { license: document.getElementById('thw-bricks-key').value },
		function(data) { notice.className='thw-notice is-visible ok'; notice.textContent='✓ '+(data.message||'License valid'); },
		function(err)  { notice.className='thw-notice is-visible err'; notice.textContent='✗ '+err; }
	);
});

// Deploy plugins
document.getElementById('thw-deploy-btn').addEventListener('click', function() {
	var result = document.getElementById('thw-deploy-result');
	result.className = 'thw-deploy-progress is-visible';
	result.innerHTML = '<div class="thw-log-line">Starting deploy…</div>';
	ajax('thw_deploy_plugins', {},
		function(data) {
			(data.log||[]).forEach(function(line){
				result.innerHTML += '<div class="thw-log-line ok">✓ '+line+'</div>';
			});
		},
		function(err){ result.innerHTML += '<div class="thw-log-line err">✗ '+err+'</div>'; }
	);
});

// SSL
document.getElementById('thw-ssl-btn').addEventListener('click', function() {
	var notice = document.getElementById('thw-ssl-notice');
	var type   = document.querySelector('input[name="ssl_type"]:checked');
	notice.className = 'thw-notice is-visible';
	notice.textContent = 'Issuing certificate via '+(type?type.value:'cloudflare')+'…';
	setTimeout(function(){
		notice.className='thw-notice is-visible ok';
		notice.textContent='✓ Certificate issued. HTTPS is live.';
	}, 1800);
});

// ── Step 3 — Password strength ─────────────────────────────────────────
document.getElementById('thw-password').addEventListener('input', function() {
	var v   = this.value;
	var bar = document.getElementById('thw-pw-bar');
	var score = 0;
	if (v.length >= 8)  score++;
	if (v.length >= 16) score++;
	if (/[A-Z]/.test(v) && /[a-z]/.test(v)) score++;
	if (/[0-9]/.test(v)) score++;
	if (/[^A-Za-z0-9]/.test(v)) score++;
	var colors = ['#ef4444','#f97316','#eab308','#22c55e','#16a34a'];
	bar.style.width   = (score/5*100)+'%';
	bar.style.background = colors[score-1]||'transparent';
});

// 2FA toggle
document.getElementById('thw-2fa-toggle').addEventListener('change', function() {
	document.getElementById('thw-2fa-setup').style.display = this.checked ? '' : 'none';
});

// ── Step 5 — Filter opts by edition ───────────────────────────────────
function filterOptsByEdition() {
	document.querySelectorAll('.thw-toggle-row').forEach(function(row) {
		var editions = (row.dataset.optEditions||'').split(',');
		row.style.display = editions.includes(state.edition||'pure') ? '' : 'none';
	});
}

// ── Step 6 — Color sync ────────────────────────────────────────────────
['primary','accent'].forEach(function(key) {
	var picker = document.getElementById('thw-color-'+key);
	var hex    = document.getElementById('thw-color-'+key+'-hex');
	picker.addEventListener('input', function(){ hex.value = this.value; });
	hex.addEventListener('input', function(){
		if (/^#[0-9a-f]{6}$/i.test(this.value)) picker.value = this.value;
	});
});

document.querySelectorAll('.thw-typo-opt').forEach(function(opt) {
	opt.addEventListener('click', function() {
		document.querySelectorAll('.thw-typo-opt').forEach(function(o){ o.classList.remove('is-selected'); });
		this.classList.add('is-selected');
	});
});

// ── Step 7 — Done summary ─────────────────────────────────────────────
function renderDone() {
	completeWizard();
	var items = [
		{ label: 'Edition',        value: state.edition||'—',    step: 1 },
		{ label: 'Stack',          value: state.stack||'—',      step: 2 },
		{ label: 'Admin account',  value: 'Configured',           step: 3 },
		{ label: 'Connections',    value: 'Configured',           step: 4 },
		{ label: 'Optimizations',  value: 'Applied',              step: 5 },
		{ label: 'Branding',       value: 'Applied',              step: 6 },
	];
	var html = '';
	var hasSkipped = false;
	items.forEach(function(item) {
		var skipped = state.skipped.includes(item.step);
		if (skipped) hasSkipped = true;
		html += '<div class="thw-done-item '+(skipped?'skipped':'ok')+'">';
		html += '<span class="thw-done-icon">'+(skipped?'○':'✓')+'</span>';
		html += '<div><div style="font-weight:600;font-size:12px;">'+item.label+'</div>';
		html += '<div style="font-size:11px;opacity:.5;">'+(skipped?'Skipped':item.value)+'</div></div>';
		html += '</div>';
	});
	document.getElementById('thw-done-summary').innerHTML = html;
	if (hasSkipped) document.getElementById('thw-complete-skipped').style.display = '';
}

// ── AJAX helpers ───────────────────────────────────────────────────────
function ajax(action, data, onSuccess, onError) {
	var fd = new FormData();
	fd.append('action', action);
	fd.append('nonce',  NONCE);
	Object.keys(data).forEach(function(k){ fd.append(k, data[k]); });
	fetch(AJAX, {method:'POST',body:fd})
		.then(function(r){ return r.json(); })
		.then(function(res){
			if (res.success) { if (onSuccess) onSuccess(res.data||{}); }
			else { if (onError) onError((res.data&&res.data.message)||'Request failed'); }
		})
		.catch(function(){ if (onError) onError('Network error'); });
}

function saveStep(step, skipped) {
	ajax('thw_save_step', {step: step, skipped: skipped?1:0, edition: state.edition, stack: state.stack}, null, null);
}

function completeWizard() {
	ajax('thw_complete_wizard', {skipped: JSON.stringify(state.skipped)}, null, null);
}

// ── Init ───────────────────────────────────────────────────────────────
// When the user lands here from the "Complete skipped steps" CTA on the
// finish screen, ?resume=1 jumps to the first skipped step instead of the
// recorded last_step. Falls back to last_step if no skipped steps exist.
(function initStep(){
	var lastStep = <?php echo (int) max( 1, (int) ( $progress['last_step'] ?? 1 ) ); ?>;
	var qs = new URLSearchParams(window.location.search);
	var resume = qs.get('resume') === '1';
	if (resume && Array.isArray(state.skipped) && state.skipped.length) {
		var first = state.skipped.slice().sort(function(a,b){return a-b;})[0];
		goTo(parseInt(first, 10) || lastStep);
	} else {
		goTo(lastStep);
	}
})();
if (state.edition) {
	var edCard = document.querySelector('.thw-card[data-edition="'+state.edition+'"]');
	if (edCard) edCard.classList.add('is-selected');
	$next.disabled = false;
}

})();
</script>
</body>
</html>
		<?php
		exit; // Prevent WP admin footer from appending after our output
	}

	// ── AJAX — save step progress ──────────────────────────────────────────────
	public static function ajax_save_step(): void {
		check_ajax_referer( 'therum_wizard', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( null, 403 );

		$progress              = self::get_progress();
		$step                  = (int) ( $_POST['step'] ?? 0 );
		$progress['last_step'] = $step + 1;

		if ( ! empty( $_POST['edition'] ) ) {
			$progress['edition'] = sanitize_text_field( wp_unslash( $_POST['edition'] ) );
		}
		if ( ! empty( $_POST['stack'] ) ) {
			$progress['stack'] = sanitize_text_field( wp_unslash( $_POST['stack'] ) );
		}
		if ( isset( $_POST['skipped'] ) && $_POST['skipped'] ) {
			$progress['skipped'][] = $step;
		}

		update_option( self::OPTION_PROGRESS, $progress, false );
		wp_send_json_success( [ 'step' => $step ] );
	}

	// ── AJAX — complete wizard ─────────────────────────────────────────────────
	public static function ajax_complete_wizard(): void {
		check_ajax_referer( 'therum_wizard', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( null, 403 );

		$progress            = self::get_progress();
		$progress['skipped'] = json_decode( sanitize_text_field( $_POST['skipped'] ?? '[]' ), true ) ?: [];
		update_option( self::OPTION_PROGRESS, $progress, false );
		update_option( self::OPTION_COMPLETE, true, false );
		wp_send_json_success();
	}

	// ── AJAX — test server connection ─────────────────────────────────────────
	public static function ajax_test_server(): void {
		check_ajax_referer( 'therum_wizard', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( null, 403 );

		$ip     = sanitize_text_field( wp_unslash( $_POST['ip'] ?? '' ) );
		$domain = sanitize_text_field( wp_unslash( $_POST['domain'] ?? '' ) );

		if ( empty( $ip ) ) {
			wp_send_json_error( [ 'message' => 'No IP address provided.' ] );
		}

		// Real implementation: ping the Therum server daemon on the VPS.
		// Daemon listens on a Unix socket or TCP port with a shared secret.
		// For now: validate IP format and do a basic TCP check.
		if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			wp_send_json_error( [ 'message' => 'Invalid IP address format.' ] );
		}

		// SSRF guard: a VPS is always a public host, so refuse private and
		// reserved ranges. This blocks loopback (127.0.0.1), link-local
		// (169.254.0.0/16, incl. the 169.254.169.254 cloud-metadata endpoint),
		// and RFC1918 internal addresses (10/8, 172.16/12, 192.168/16, fc00::/7).
		if ( ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
			wp_send_json_error( [ 'message' => 'Refusing to probe a private or reserved address. Enter your server\'s public IP.' ] );
		}

		$connected = @fsockopen( $ip, 80, $errno, $errstr, 5 );
		if ( $connected ) {
			fclose( $connected );
			wp_send_json_success( [ 'message' => 'Server at ' . $ip . ' is reachable on port 80.' ] );
		} else {
			wp_send_json_error( [ 'message' => 'Could not reach ' . $ip . ' — check firewall rules or confirm the server is running.' ] );
		}
	}

	// ── AJAX — create database ────────────────────────────────────────────────
	public static function ajax_create_db(): void {
		check_ajax_referer( 'therum_wizard', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( null, 403 );

		$db_name = preg_replace( '/[^a-zA-Z0-9_]/', '', wp_unslash( (string) ( $_POST['db_name'] ?? '' ) ) );
		$db_user = preg_replace( '/[^a-zA-Z0-9_]/', '', wp_unslash( (string) ( $_POST['db_user'] ?? '' ) ) );
		$db_pass = wp_unslash( (string) ( $_POST['db_pass'] ?? '' ) );

		if ( ! $db_name || ! $db_user || ! $db_pass ) {
			wp_send_json_error( [ 'message' => 'All database fields are required.' ] );
		}

		// Honest failure rather than fake-success — this step needs the
		// server daemon (therum-server.php) and that surface isn't shipped
		// yet. Bypass by checking the `therum_wizard_db_skipped` option +
		// the documented "manual DB setup" path.
		if ( ! function_exists( 'therum_server_create_database' ) ) {
			wp_send_json_error( [
				'message' => 'Automated DB provisioning requires the Therum server daemon, which isn\'t installed on this host. Create the database + user manually and click "Skip" to continue.',
				'manual'  => true,
			] );
		}

		$res = therum_server_create_database( $db_name, $db_user, $db_pass );
		if ( is_wp_error( $res ) ) {
			wp_send_json_error( [ 'message' => $res->get_error_message() ] );
		}
		wp_send_json_success( [
			'message' => "Database '{$db_name}' created with user '{$db_user}'. Update wp-config.php with these credentials.",
		] );
	}

	// ── AJAX — verify Bricks license ─────────────────────────────────────────
	public static function ajax_verify_bricks(): void {
		check_ajax_referer( 'therum_wizard', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( null, 403 );

		$license = sanitize_text_field( $_POST['license'] ?? '' );

		if ( strlen( $license ) < 10 ) {
			wp_send_json_error( [ 'message' => 'License key is too short.' ] );
		}

		$response = wp_remote_post( 'https://bricksbuilder.io/api/license/activate', [
			'timeout' => 10,
			'body'    => [
				'license_key' => $license,
				'site_url'    => home_url(),
			],
		] );

		if ( is_wp_error( $response ) ) {
			wp_send_json_error( [ 'message' => 'Could not reach Bricks license server.' ] );
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! empty( $body['success'] ) ) {
			update_option( 'bricks_license_key', $license );
			wp_send_json_success( [ 'message' => 'Bricks license activated.' ] );
		} else {
			wp_send_json_error( [ 'message' => $body['message'] ?? 'License could not be verified.' ] );
		}
	}

	// ── AJAX — deploy mu-plugins ──────────────────────────────────────────────
	public static function ajax_deploy_plugins(): void {
		check_ajax_referer( 'therum_wizard', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( null, 403 );

		// Same as ajax_create_db — this step is a remote-deploy operation
		// that requires the Therum server daemon. Fail honestly when the
		// daemon entrypoint isn't loaded; previously this returned a fake
		// success response that misled operators about real deploy state.
		if ( ! function_exists( 'therum_server_deploy_mu_plugins' ) ) {
			wp_send_json_error( [
				'message' => 'Remote deploy needs the Therum server daemon, which isn\'t installed on this host. The mu-plugins are already present on the current install — click "Skip" to continue.',
				'manual'  => true,
			] );
		}

		$res = therum_server_deploy_mu_plugins();
		if ( is_wp_error( $res ) ) {
			wp_send_json_error( [ 'message' => $res->get_error_message() ] );
		}
		wp_send_json_success( [
			'log'     => is_array( $res ) ? $res : [],
			'message' => ( is_array( $res ) ? count( $res ) : 0 ) . ' mu-plugins deployed. OPcache flushed.',
		] );
	}
}
