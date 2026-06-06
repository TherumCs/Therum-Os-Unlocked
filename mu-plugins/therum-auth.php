<?php
/**
 * Plugin Name: Therum OS — Auth
 * Description: Custom login skin + TOTP 2FA + custom roles + WooCommerce role pricing.
 *              Merged from therum-login.php, therum-2fa.php, and therum-roles-engine.php (1.8.6).
 * Version: 1.9.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;


// ════════════════════════════════════════════════════════════════════════
// LOGIN SKIN — from therum-login.php
// ════════════════════════════════════════════════════════════════════════

define( 'THERUM_LOGIN_VERSION', '1.8.8' );


// ═════════════════════════════════════════════════════════════════════════════
//  1. RESET wp-login defaults — strip the WP login.min.css so we own the page
// ═════════════════════════════════════════════════════════════════════════════
add_action( 'login_enqueue_scripts', function() {
	// Dequeue WP defaults — we replace them entirely
	wp_dequeue_style( 'login' );
	wp_dequeue_style( 'wp-admin' );
	wp_dequeue_style( 'colors-fresh' );

	// Keep dashicons (used by some core elements like the password toggle)
	wp_enqueue_style( 'dashicons' );
}, 100 );


// ═════════════════════════════════════════════════════════════════════════════
//  2. LOGIN HEADER URL + TEXT — pull from Therum branding options
// ═════════════════════════════════════════════════════════════════════════════
add_filter( 'login_headerurl',  fn() => home_url( '/' ), 20 );
add_filter( 'login_headertext', function() {
	return get_option( 'th_wordmark', 'Therum OS' );
}, 20 );


// ═════════════════════════════════════════════════════════════════════════════
//  3. BODY CLASSES — mirror the admin theme classes onto the login page
// ═════════════════════════════════════════════════════════════════════════════
add_filter( 'login_body_class', function( $classes ) {
	// CRITICAL: the entire login skin is CSS-scoped to `body.login.therum-login`.
	// Earlier versions returned $classes unchanged when Therum_Themes wasn't
	// available — that produced a fully-unstyled "raw WP" login. Always tag
	// the body so the inline style block at login_head applies even with no
	// theme state loaded.
	$add = [ 'therum-login', 'therum-themed' ];

	if ( class_exists( 'Therum_Themes' ) ) {
		$state = Therum_Themes::get_state();
		$add[] = 'theme-' . sanitize_html_class( $state['palette'] ?? 'studio' );
		if ( ($state['mode'] ?? 'dark') === 'light' ) $add[] = 'light';
		if ( ! empty( $state['glass'] ) )              $add[] = 'glass';
		if ( ! empty( $state['radius'] ) )             $add[] = 'radius-' . sanitize_html_class( $state['radius'] );
		if ( ! empty( $state['shadow'] ) )             $add[] = 'shadow-' . sanitize_html_class( $state['shadow'] );
		if ( ! empty( $state['font'] ) )               $add[] = 'font-' . sanitize_html_class( $state['font'] );
	} else {
		// Defaults so the cascade still matches the .theme-studio rules.
		$add[] = 'theme-studio';
	}

	$bg_type = get_option( 'th_login_bg_type', 'theme' );
	$add[] = 'login-bg-' . sanitize_html_class( $bg_type );

	return array_merge( $classes, $add );
});


// ═════════════════════════════════════════════════════════════════════════════
//  4. STYLES — emit a self-contained CSS sheet for the login page
//
//  We do not reuse therum-themes.php's admin_print_styles because that hook
//  only fires in /wp-admin/. Instead, we pull the Therum_Themes presets and
//  build only the variables we need for the active theme, keeping the page
//  light. The result is theme-aware login chrome with no admin coupling.
// ═════════════════════════════════════════════════════════════════════════════
add_action( 'login_head', function() {

	$state = class_exists( 'Therum_Themes' ) ? Therum_Themes::get_state() : [
		'palette' => 'studio', 'mode' => 'dark', 'glass' => false,
		'accent' => '#e83b3b', 'font' => 'system', 'radius' => 'medium',
	];

	$accent  = $state['accent']  ?? '#e83b3b';
	$radius  = $state['radius']  ?? 'medium';
	$is_glass = ! empty( $state['glass'] );
	$mode     = $state['mode'] ?? 'dark';

	// Background config
	$bg_type    = get_option( 'th_login_bg_type', 'theme' );
	$bg_color   = get_option( 'th_login_bg_color', '#0a0a0a' );
	$bg_image   = get_option( 'th_login_bg_image', '' );
	$bg_video   = get_option( 'th_login_bg_video', '' );
	$bg_overlay = (bool) get_option( 'th_login_bg_overlay', true );

	// Theme preset bg fallback (matches admin)
	$preset_bg = 'theme';
	if ( class_exists( 'Therum_Themes' ) ) {
		$presets = Therum_Themes::presets();
		$preset = $presets[ $state['palette'] ] ?? null;
		if ( $preset && ! empty( $preset['bgImage'] ) && $preset['bgImage'] !== 'none' ) {
			$preset_bg = $preset['bgImage'];
		}
	}

	$radius_map = [ 'sharp' => '0px', 'medium' => '12px', 'round' => '16px' ];
	$card_radius = $radius_map[ $radius ] ?? '12px';
	$input_radius = $radius === 'sharp' ? '0px' : '8px';

	?>
<style id="therum-login-styles">
/* ═══ RESET ═══════════════════════════════════════════════════════════════ */
body.login {
	margin: 0;
	min-height: 100vh;
	font-family: -apple-system, BlinkMacSystemFont, 'SF Pro Display', 'Segoe UI', system-ui, sans-serif;
	background: <?php echo esc_attr( $bg_color ); ?>;
	color: #f5f5f4;
	-webkit-font-smoothing: antialiased;
	overflow: hidden;
}
body.login.light { color: #0a0a0a; }
body.login #login {
	width: 100%;
	max-width: 380px;
	padding: 0;
	margin: 0;
	position: relative;
	z-index: 10;
}
body.login.therum-login {
	display: grid;
	place-items: center;
}
body.login #backtoblog,
body.login #nav { display: none; }
body.login h1 a {
	display: none; /* WP default logo block — we draw our own brand mark */
}

/* ═══ BACKGROUND LAYERS ════════════════════════════════════════════════════ */
.th-login-bg {
	position: fixed;
	inset: 0;
	z-index: 0;
	background-size: cover;
	background-position: center;
	background-repeat: no-repeat;
}
.th-login-bg-video {
	position: fixed;
	inset: 0;
	width: 100%;
	height: 100%;
	object-fit: cover;
	z-index: 0;
}
.th-login-bg-overlay {
	position: fixed;
	inset: 0;
	z-index: 1;
	background: radial-gradient(ellipse at center, transparent 0%, rgba(0,0,0,0.45) 100%);
	pointer-events: none;
}
body.login.light .th-login-bg-overlay {
	background: radial-gradient(ellipse at center, transparent 0%, rgba(255,255,255,0.25) 100%);
}

/* ═══ CARD ════════════════════════════════════════════════════════════════ */
.th-login-card {
	width: 360px;
	max-width: calc(100vw - 48px);
	padding: 36px 32px 28px;
	border-radius: <?php echo esc_attr( $card_radius ); ?>;
	background: rgba(20, 22, 28, 0.55);
	border: 0.5px solid rgba(255,255,255,0.1);
	color: #f5f5f4;
	position: relative;
	z-index: 2;
	<?php if ( $is_glass ): ?>
	backdrop-filter: blur(28px) saturate(140%);
	-webkit-backdrop-filter: blur(28px) saturate(140%);
	<?php endif; ?>
}
body.login.light .th-login-card {
	background: rgba(255,255,255,0.92);
	border-color: rgba(0,0,0,0.08);
	color: #0a0a0a;
}
body.login.theme-brutal .th-login-card {
	background: #fff;
	color: #000;
	border: 2px solid #000;
}
body.login.theme-brutal.light .th-login-card { background: #fff; }
body.login.theme-tron .th-login-card {
	background: rgba(0,0,0,0.65);
	border: 1px solid rgba(0,212,255,0.4);
	color: #e0f7ff;
	font-family: 'JetBrains Mono', ui-monospace, Menlo, monospace;
}

/* ═══ BRAND HEADER ════════════════════════════════════════════════════════ */
.th-login-brand {
	display: flex;
	align-items: center;
	gap: 10px;
	margin-bottom: 28px;
}
.th-login-mark {
	width: 30px; height: 30px;
	border-radius: 7px;
	display: grid; place-items: center;
	font-weight: 600; font-size: 13px;
	background: <?php echo esc_attr( $accent ); ?>;
	color: #fff;
	overflow: hidden;
	flex-shrink: 0;
}
.th-login-mark img {
	width: 100%; height: 100%;
	object-fit: cover;
	display: block;
}
.th-login-name {
	font-size: 14px;
	font-weight: 500;
	letter-spacing: -0.01em;
	color: inherit;
}
.th-login-h {
	font-size: 22px;
	font-weight: 500;
	margin: 0 0 6px;
	letter-spacing: -0.015em;
	color: inherit;
}
.th-login-sub {
	font-size: 13px;
	opacity: 0.65;
	margin: 0 0 22px;
	color: inherit;
}

/* ═══ FORM ════════════════════════════════════════════════════════════════ */
form#loginform,
form#registerform,
form#lostpasswordform {
	background: transparent;
	border: 0;
	box-shadow: none;
	padding: 0;
	margin: 0;
	overflow: visible;
}
.login form .input,
.login form input[type=text],
.login form input[type=password],
.login form input[type=email] {
	width: 100%;
	height: 38px;
	padding: 0 12px;
	margin: 0;
	box-sizing: border-box;
	font-size: 13px;
	font-family: inherit;
	background: rgba(255,255,255,0.06);
	border: 0.5px solid rgba(255,255,255,0.12);
	color: inherit;
	border-radius: <?php echo esc_attr( $input_radius ); ?>;
	box-shadow: none;
	outline: none;
	transition: border-color 0.15s, box-shadow 0.15s;
}
body.login.light .login form .input,
body.login.light .login form input[type=text],
body.login.light .login form input[type=password],
body.login.light .login form input[type=email] {
	background: rgba(0,0,0,0.04);
	border-color: rgba(0,0,0,0.1);
}
body.login.theme-brutal .login form .input,
body.login.theme-brutal .login form input[type=text],
body.login.theme-brutal .login form input[type=password] {
	background: #fff; border: 2px solid #000; color: #000; border-radius: 0;
}
.login form .input:focus,
.login form input:focus {
	border-color: <?php echo esc_attr( $accent ); ?>;
	box-shadow: 0 0 0 2px <?php echo esc_attr( $accent ); ?>33;
}
.login label {
	display: block;
	font-size: 11px;
	letter-spacing: 0.02em;
	opacity: 0.7;
	margin-bottom: 6px;
	color: inherit;
	text-transform: none;
}
.login form p { margin-bottom: 14px; }
.login form .forgetmenot { margin: 14px 0 18px; opacity: 0.85; }
.login form .forgetmenot label { display: inline-flex; align-items: center; gap: 6px; font-size: 12px; }
.login form .forgetmenot input { width: 14px; height: 14px; margin: 0; }
.login form .submit { margin: 0; }

/* ═══ SUBMIT BUTTON ═══════════════════════════════════════════════════════ */
.login form .button-primary,
.login .submit input[type=submit] {
	width: 100%;
	height: 40px;
	padding: 0;
	border: none;
	border-radius: <?php echo esc_attr( $input_radius ); ?>;
	background: <?php echo esc_attr( $accent ); ?>;
	color: #fff;
	font-size: 13px;
	font-weight: 500;
	font-family: inherit;
	cursor: pointer;
	box-shadow: none;
	text-shadow: none;
	transition: opacity 0.15s, transform 0.05s;
	float: none;
}
body.login.theme-brutal .login form .button-primary {
	background: #000; color: #fff; border-radius: 0; border: 2px solid #000;
}
.login form .button-primary:hover { opacity: 0.92; }
.login form .button-primary:active { transform: scale(0.99); }

/* ═══ LINKS (forgot pw, register) ═════════════════════════════════════════ */
.th-login-row {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin: 14px 0 18px;
	font-size: 12px;
}
.th-login-row a {
	color: inherit;
	opacity: 0.85;
	text-decoration: none;
}
.th-login-row a:hover { opacity: 1; }

/* ═══ FOOTER ══════════════════════════════════════════════════════════════ */
.th-login-foot {
	margin-top: 18px;
	padding-top: 16px;
	text-align: center;
	font-size: 11px;
	opacity: 0.55;
	color: inherit;
	border-top: 0.5px solid currentColor;
}
.th-login-foot span { opacity: 1; }
body.login.theme-tron .th-login-foot { font-family: inherit; letter-spacing: 0.06em; }

/* ═══ MESSAGES (errors, info) ═════════════════════════════════════════════ */
#login_error, .message, .notice {
	background: rgba(220, 38, 38, 0.12);
	color: #fca5a5;
	border: 0.5px solid rgba(220, 38, 38, 0.35);
	border-left: 3px solid rgba(220, 38, 38, 0.8);
	padding: 10px 14px;
	margin: 0 0 14px;
	font-size: 12px;
	border-radius: <?php echo esc_attr( $input_radius ); ?>;
	box-shadow: none;
	line-height: 1.5;
}
.message {
	background: rgba(56, 232, 226, 0.1);
	color: #38e8e2;
	border-color: rgba(56, 232, 226, 0.35);
	border-left-color: rgba(56, 232, 226, 0.8);
}
body.login.light #login_error { background: #fee2e2; color: #991b1b; }
body.login.light .message { background: #dbeafe; color: #1e40af; }

/* ═══ HIDE PASSWORD STRENGTH METER + EXTRA WP CRUFT ═══════════════════════ */
.privacy-policy-page-link, .language-switcher { display: none; }
.dashicons-visibility { color: inherit !important; }
.wp-pwd button.button { background: transparent !important; border: 0 !important; }

/* ═══ FONTS — inherit from theme ══════════════════════════════════════════ */
body.login.font-jetbrains, body.login.font-mono { font-family: 'JetBrains Mono', ui-monospace, Menlo, monospace; }
body.login.font-inter { font-family: 'Inter', -apple-system, sans-serif; }
body.login.font-crimson { font-family: 'Crimson Pro', Georgia, serif; }

</style>
<?php
});


// ═════════════════════════════════════════════════════════════════════════════
//  5. CUSTOM BRAND MARKUP — injected before the form via login_form action
// ═════════════════════════════════════════════════════════════════════════════
add_action( 'login_form', function() {
	$wordmark = get_option( 'th_wordmark', 'Therum OS' );
	$logo     = get_option( 'th_logo_url', '' );
	$accent   = get_option( 'th_brand_color', '#e83b3b' );
	$heading  = get_option( 'th_login_heading', 'Welcome back' );
	$subhead  = get_option( 'th_login_subhead', 'Sign in to your workspace' );

	$action = $_REQUEST['action'] ?? 'login';
	if ( $action === 'lostpassword' || $action === 'retrievepassword' ) {
		$heading = 'Reset password';
		$subhead = 'We\'ll email you a reset link';
	} elseif ( $action === 'register' ) {
		$heading = 'Create account';
		$subhead = 'Get started with Therum OS';
	} elseif ( $action === 'rp' || $action === 'resetpass' ) {
		$heading = 'Choose a new password';
		$subhead = 'Make it a good one';
	}

	$mark_letter = strtoupper( substr( $wordmark, 0, 1 ) );

	?>
	<div class="th-login-brand">
		<div class="th-login-mark" style="background: <?php echo esc_attr( $accent ); ?>;">
			<?php if ( $logo ): ?>
				<img src="<?php echo esc_url( $logo ); ?>" alt="" />
			<?php else: ?>
				<?php echo esc_html( $mark_letter ); ?>
			<?php endif; ?>
		</div>
		<div class="th-login-name"><?php echo esc_html( $wordmark ); ?></div>
	</div>
	<h1 class="th-login-h"><?php echo esc_html( $heading ); ?></h1>
	<p class="th-login-sub"><?php echo esc_html( $subhead ); ?></p>
	<?php
}, 5 );


// ═════════════════════════════════════════════════════════════════════════════
//  6. FOOTER — bg layer (image/video) + version stamp + custom row of links
// ═════════════════════════════════════════════════════════════════════════════
add_action( 'login_footer', function() {
	$bg_type    = get_option( 'th_login_bg_type', 'theme' );
	$bg_color   = get_option( 'th_login_bg_color', '#0a0a0a' );
	$bg_image   = get_option( 'th_login_bg_image', '' );
	$bg_video   = get_option( 'th_login_bg_video', '' );
	$bg_overlay = (bool) get_option( 'th_login_bg_overlay', true );

	$show_version = (bool) get_option( 'th_login_show_version', true );

	// Resolve theme preset bg gradient (used when bg_type == 'theme')
	$theme_bg_css = '#0a0a0a';
	if ( class_exists( 'Therum_Themes' ) ) {
		$state   = Therum_Themes::get_state();
		$presets = Therum_Themes::presets();
		$palette = $state['palette'] ?? 'studio';
		$preset  = $presets[ $palette ] ?? null;
		if ( $preset ) {
			if ( ! empty( $preset['bgImage'] ) && $preset['bgImage'] !== 'none' ) {
				$theme_bg_css = $preset['bgImage'];
			} else {
				$theme_bg_css = $preset['previewMain'] ?? '#0a0a0a';
			}
		}
	}

	echo '<div class="th-login-bg"';
	if ( $bg_type === 'theme' ) {
		echo ' style="background:' . esc_attr( $theme_bg_css ) . ';"';
	} elseif ( $bg_type === 'solid' ) {
		echo ' style="background:' . esc_attr( $bg_color ) . ';"';
	} elseif ( $bg_type === 'image' && $bg_image ) {
		echo ' style="background-image:url(' . esc_url( $bg_image ) . ');"';
	}
	echo '></div>';

	if ( $bg_type === 'video' && $bg_video ) {
		echo '<video class="th-login-bg-video" autoplay muted loop playsinline>';
		echo '<source src="' . esc_url( $bg_video ) . '" type="video/mp4">';
		echo '</video>';
	}

	if ( $bg_overlay && $bg_type !== 'solid' ) {
		echo '<div class="th-login-bg-overlay"></div>';
	}

	if ( $show_version ) {
		echo '<div class="th-login-foot-fixed" style="position:fixed;bottom:18px;left:0;right:0;text-align:center;font-size:11px;opacity:0.4;z-index:10;color:inherit;">';
		echo 'Therum OS · v' . esc_html( THERUM_LOGIN_VERSION );
		echo '</div>';
	}
});


// ═════════════════════════════════════════════════════════════════════════════
//  7. WHITELIST OPTIONS — register login keys with Therum settings AJAX saver
// ═════════════════════════════════════════════════════════════════════════════
add_filter( 'therum_settings_keys', function( $keys ) {
	return array_merge( $keys, [
		'th_login_bg_type',
		'th_login_bg_color',
		'th_login_bg_image',
		'th_login_bg_video',
		'th_login_bg_overlay',
		'th_login_show_version',
		'th_login_heading',
		'th_login_subhead',
	]);
});


// ═════════════════════════════════════════════════════════════════════════════
//  8. SETTINGS SECTION — register a Login section in the Therum settings page
// ═════════════════════════════════════════════════════════════════════════════
add_action( 'init', function() {
	if ( ! class_exists( 'Therum_Settings' ) ) return;
	Therum_Settings::register( 'login', [
		'label'    => 'Login',
		'icon'     => 'lock',
		'desc'     => 'Login screen background and branding.',
		'priority' => 25,
		'render'   => 'th_render_login_settings',
	]);
}, 20 );


function th_render_login_settings() {
	$bg_type    = get_option( 'th_login_bg_type', 'theme' );
	$bg_color   = get_option( 'th_login_bg_color', '#0a0a0a' );
	$bg_image   = get_option( 'th_login_bg_image', '' );
	$bg_video   = get_option( 'th_login_bg_video', '' );
	$bg_overlay = (bool) get_option( 'th_login_bg_overlay', true );
	$show_ver   = (bool) get_option( 'th_login_show_version', true );
	$heading    = get_option( 'th_login_heading', 'Welcome back' );
	$subhead    = get_option( 'th_login_subhead', 'Sign in to your workspace' );

	th_settings_group(
		'Background',
		'What sits behind the login card. Match the active theme, pick a solid color, or upload your own image or video.',
		function() use ( $bg_type, $bg_color, $bg_image, $bg_video, $bg_overlay ) {
			th_setting_row( 'Source', 'Where the login background comes from.',
				th_select( 'th_login_bg_type', $bg_type, [
					'theme' => 'Match active theme',
					'solid' => 'Solid color',
					'image' => 'Custom image',
					'video' => 'Custom video',
				])
			);
			th_setting_row( 'Solid color', 'Used when source is "Solid color".',
				'<div class="th-color-row"><input type="color" class="th-color" data-th-text="th_login_bg_color" value="' . esc_attr( $bg_color ) . '" /><span class="th-color-hex">' . esc_html( $bg_color ) . '</span></div>'
			);
			th_setting_row( 'Image URL', 'JPG, PNG, or WebP. Recommended 2560×1440 or larger.',
				th_text_input( 'th_login_bg_image', $bg_image, 'https://…/login-bg.jpg', 'url' )
			);
			th_setting_row( 'Video URL', 'MP4 or WebM. Plays muted on loop. Keep it under 5 MB.',
				th_text_input( 'th_login_bg_video', $bg_video, 'https://…/login-bg.mp4', 'url' )
			);
			th_setting_row( 'Darken overlay', 'Adds a soft vignette behind the card so text stays readable on busy backgrounds.',
				th_toggle( 'th_login_bg_overlay', $bg_overlay )
			);
		}
	);

	th_settings_group(
		'Copy',
		'Override the welcome heading shown on the login form.',
		function() use ( $heading, $subhead ) {
			th_setting_row( 'Heading', 'Defaults to "Welcome back".',
				th_text_input( 'th_login_heading', $heading, 'Welcome back' )
			);
			th_setting_row( 'Subhead', 'Defaults to "Sign in to your workspace".',
				th_text_input( 'th_login_subhead', $subhead, 'Sign in to your workspace' )
			);
		}
	);

	th_settings_group(
		'Footer',
		'What appears at the bottom of the login screen.',
		function() use ( $show_ver ) {
			th_setting_row( 'Show version stamp', 'Displays "Therum OS · v1.7.x" at the bottom.',
				th_toggle( 'th_login_show_version', $show_ver )
			);
		}
	);

	th_settings_group(
		'Preview',
		'What the active theme looks like when applied to the login screen.',
		function() {
			$state   = class_exists( 'Therum_Themes' ) ? Therum_Themes::get_state() : [ 'palette' => 'studio' ];
			$palette = $state['palette'] ?? 'studio';
			$presets = class_exists( 'Therum_Themes' ) ? Therum_Themes::presets() : [];
			$preset  = $presets[ $palette ] ?? [ 'name' => ucfirst( $palette ), 'previewMain' => '#1a1a1a', 'previewRail' => '#2563eb' ];
			?>
			<div class="th-login-preview" style="display:flex;gap:14px;align-items:center;padding:14px;background:var(--sf2,#0f1218);border-radius:10px;border:0.5px solid var(--bd,rgba(255,255,255,0.1));">
				<div style="width:120px;height:80px;border-radius:8px;background:<?php echo esc_attr( $preset['previewMain'] ); ?>;position:relative;overflow:hidden;flex-shrink:0;">
					<div style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);width:54px;height:36px;background:rgba(255,255,255,0.08);border:0.5px solid rgba(255,255,255,0.15);border-radius:4px;"></div>
				</div>
				<div>
					<div style="font-size:13px;font-weight:500;margin-bottom:4px;"><?php echo esc_html( $preset['name'] ); ?></div>
					<div style="font-size:11px;opacity:0.6;">
						Active theme determines login chrome.<br>
						Open <a href="<?php echo esc_url( wp_login_url() ); ?>" target="_blank" style="color:<?php echo esc_attr( $preset['previewRail'] ); ?>;">login screen ↗</a> to view.
					</div>
				</div>
			</div>
			<?php
		}
	);
}

// ════════════════════════════════════════════════════════════════════════
// TWO-FACTOR AUTH (TOTP) — from therum-2fa.php
// ════════════════════════════════════════════════════════════════════════

define( 'THERUM_2FA_VERSION', '1.8.8' );


// ═════════════════════════════════════════════════════════════════════════════
//  RFC 6238 / RFC 4226 — TOTP / HOTP implementation
//  No external library. ~80 lines of pure PHP.
// ═════════════════════════════════════════════════════════════════════════════

class Therum_2FA {

	const PERIOD     = 30;     // seconds per code
	const DIGITS     = 6;
	const ALGORITHM  = 'sha1'; // default — universal authenticator support
	const SECRET_LEN = 20;     // bytes (160 bits, base32-encoded → 32 chars)

	/**
	 * Generate a fresh base32-encoded TOTP secret.
	 */
	public static function generate_secret(): string {
		$bytes = random_bytes( self::SECRET_LEN );
		return self::base32_encode( $bytes );
	}

	/**
	 * Build the otpauth:// provisioning URI.
	 *  e.g. otpauth://totp/Therum%20OS:user@site.com?secret=ABC&issuer=Therum%20OS
	 */
	public static function provisioning_uri( string $secret, string $account, string $issuer = 'Therum OS' ): string {
		$label = rawurlencode( $issuer ) . ':' . rawurlencode( $account );
		$query = http_build_query([
			'secret'    => $secret,
			'issuer'    => $issuer,
			'algorithm' => strtoupper( self::ALGORITHM ),
			'digits'    => self::DIGITS,
			'period'    => self::PERIOD,
		]);
		return "otpauth://totp/{$label}?{$query}";
	}

	/**
	 * Verify a 6-digit code against the secret. ±1 window tolerance for
	 * clock drift. Returns the matching slot index, or false.
	 */
	public static function verify( string $secret, string $code, int $tolerance = 1 ) {
		$code  = preg_replace( '/\D/', '', $code );
		if ( strlen( $code ) !== self::DIGITS ) return false;

		$now_slot = (int) floor( time() / self::PERIOD );
		for ( $i = -$tolerance; $i <= $tolerance; $i++ ) {
			$slot = $now_slot + $i;
			if ( hash_equals( self::compute_code( $secret, $slot ), $code ) ) {
				return $slot;
			}
		}
		return false;
	}

	/**
	 * Compute the 6-digit TOTP code for a given slot.
	 */
	public static function compute_code( string $secret, int $slot ): string {
		$key = self::base32_decode( $secret );
		// 8-byte big-endian packed counter
		$counter = pack( 'N*', 0, $slot );
		$hash = hash_hmac( self::ALGORITHM, $counter, $key, true );
		// Dynamic truncation per RFC 4226
		$offset = ord( $hash[ strlen( $hash ) - 1 ] ) & 0x0F;
		$bin = (
			(( ord( $hash[ $offset ] )     & 0x7F ) << 24 ) |
			(( ord( $hash[ $offset + 1 ] ) & 0xFF ) << 16 ) |
			(( ord( $hash[ $offset + 2 ] ) & 0xFF ) << 8  ) |
			 ( ord( $hash[ $offset + 3 ] ) & 0xFF )
		);
		$code = $bin % ( 10 ** self::DIGITS );
		return str_pad( (string) $code, self::DIGITS, '0', STR_PAD_LEFT );
	}

	/**
	 * Base32 (RFC 4648) — used by all TOTP authenticators.
	 */
	public static function base32_encode( string $data ): string {
		$alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
		$out = '';
		$bits = '';
		foreach ( str_split( $data ) as $byte ) {
			$bits .= str_pad( decbin( ord( $byte ) ), 8, '0', STR_PAD_LEFT );
		}
		foreach ( str_split( $bits, 5 ) as $chunk ) {
			if ( strlen( $chunk ) < 5 ) $chunk = str_pad( $chunk, 5, '0' );
			$out .= $alphabet[ bindec( $chunk ) ];
		}
		return $out;
	}

	public static function base32_decode( string $b32 ): string {
		$alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
		$b32  = strtoupper( preg_replace( '/[^A-Z2-7]/', '', $b32 ) );
		$bits = '';
		foreach ( str_split( $b32 ) as $c ) {
			$bits .= str_pad( decbin( strpos( $alphabet, $c ) ), 5, '0', STR_PAD_LEFT );
		}
		$out = '';
		foreach ( str_split( $bits, 8 ) as $chunk ) {
			if ( strlen( $chunk ) < 8 ) break;
			$out .= chr( bindec( $chunk ) );
		}
		return $out;
	}

	/**
	 * Generate 8 backup codes. Format: XXXX-XXXX (alphanumeric, easy to read).
	 * Returns plaintext codes for display; store hashed.
	 */
	public static function generate_backup_codes( int $count = 8 ): array {
		$codes = [];
		$alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // no 0/O/1/I confusion
		for ( $i = 0; $i < $count; $i++ ) {
			$code = '';
			for ( $j = 0; $j < 8; $j++ ) {
				$code .= $alphabet[ random_int( 0, 31 ) ];
				if ( $j === 3 ) $code .= '-';
			}
			$codes[] = $code;
		}
		return $codes;
	}

	public static function hash_backup_codes( array $codes ): array {
		return array_map( fn( $c ) => wp_hash_password( $c ), $codes );
	}

	/**
	 * Verify + invalidate a backup code. Returns true on match.
	 */
	public static function verify_backup_code( int $user_id, string $code ): bool {
		$code = strtoupper( preg_replace( '/[^A-Z0-9-]/', '', $code ) );
		$stored = get_user_meta( $user_id, 'th_2fa_backup_codes', true );
		if ( ! is_array( $stored ) || empty( $stored ) ) return false;
		foreach ( $stored as $idx => $hash ) {
			if ( wp_check_password( $code, $hash ) ) {
				unset( $stored[ $idx ] );
				update_user_meta( $user_id, 'th_2fa_backup_codes', array_values( $stored ) );
				return true;
			}
		}
		return false;
	}

	/**
	 * QR code for the provisioning URI. We don't have a QR library bundled,
	 * but we can hand off to a public-domain QR endpoint built into the
	 * authenticator setup flow. Defaults to chart.googleapis (deprecated but
	 * still serving) with a fallback to qrserver.com. Set
	 * `THERUM_QR_ENDPOINT` constant in wp-config.php to override.
	 *
	 * For air-gapped installs, set TH_2FA_QR_ENDPOINT to '' and the setup
	 * screen will show the secret + URI for manual entry only.
	 */
	public static function qr_url( string $provisioning_uri, int $size = 200 ): string {
		$endpoint = defined( 'THERUM_QR_ENDPOINT' )
			? THERUM_QR_ENDPOINT
			: 'https://api.qrserver.com/v1/create-qr-code/';
		if ( ! $endpoint ) return '';
		return $endpoint . '?size=' . $size . 'x' . $size . '&data=' . urlencode( $provisioning_uri );
	}

	/**
	 * Is this user enrolled in 2FA?
	 */
	public static function user_enabled( int $user_id ): bool {
		return (bool) get_user_meta( $user_id, 'th_2fa_enabled', true );
	}
}


// ═════════════════════════════════════════════════════════════════════════════
//  LOGIN INTERCEPT — challenge user with 6-digit code after password auth
// ═════════════════════════════════════════════════════════════════════════════

/**
 * Hook the wp_authenticate filter chain. After WP validates user+pass, we get
 * a WP_User. If the user has 2FA, we strip the auth and force a challenge.
 */
/**
 * Second leg of login. The challenge form posts a signed, short-lived token
 * (itself proof that the password was verified during the first leg) plus the
 * 6-digit code. We verify both here at `authenticate` priority 10 — BEFORE
 * wp_authenticate_username_password (priority 20) — so a correct code logs the
 * user in WITHOUT the password ever being round-tripped through the browser.
 *
 * Returning a WP_User short-circuits the priority-20 password check (it returns
 * early when it already has a WP_User), so no empty-password error is raised.
 * Only fires when a challenge token is present, so application-password and
 * other programmatic auth paths are untouched.
 */
add_filter( 'authenticate', function( $user, $username, $password ) {
	$token = $_POST['th_2fa_token'] ?? '';
	$code  = trim( $_POST['th_2fa_code'] ?? '' );
	if ( ! $token || ! $code ) return $user;

	$payload = th_2fa_decode_token( $token );
	if ( ! $payload || $payload['exp'] < time() ) {
		return new WP_Error( '2fa_expired', 'Challenge expired. Sign in again.' );
	}
	$cu = get_user_by( 'id', $payload['uid'] );
	if ( ! $cu || ! Therum_2FA::user_enabled( $cu->ID ) ) {
		return new WP_Error( '2fa_invalid', 'Challenge invalid. Sign in again.' );
	}

	// Try TOTP, with single-use (anti-replay) protection.
	$slot = Therum_2FA::verify( get_user_meta( $cu->ID, 'th_2fa_secret', true ), $code );
	if ( $slot !== false ) {
		$last = (int) get_user_meta( $cu->ID, 'th_2fa_last_used_slot', true );
		if ( $slot === $last ) {
			return new WP_Error( '2fa_replay', 'That code has already been used. Wait for the next one.' );
		}
		update_user_meta( $cu->ID, 'th_2fa_last_used_slot', $slot );
		return $cu;
	}
	// Backup code fallback.
	if ( Therum_2FA::verify_backup_code( $cu->ID, $code ) ) {
		return $cu;
	}
	return new WP_Error( '2fa_invalid', 'Invalid code. Try again.' );
}, 10, 3 );

/**
 * First leg of login. wp_authenticate_user fires only after WP has validated a
 * real username + password (interactive login — not application passwords). If
 * the user has 2FA enabled, strip the auth and redirect to the code challenge,
 * handing along a short-lived signed token.
 */
add_filter( 'wp_authenticate_user', function( $user, $password ) {
	if ( ! ( $user instanceof WP_User ) ) return $user;
	if ( ! Therum_2FA::user_enabled( $user->ID ) ) return $user;

	$token = th_2fa_issue_token( $user->ID );
	$redirect = add_query_arg([
		'action' => 'th_2fa_challenge',
		'token'  => $token,
	], wp_login_url() );
	wp_redirect( $redirect );
	exit;
}, 30, 2 );


/**
 * Render the 2FA challenge screen at wp-login.php?action=th_2fa_challenge
 */
add_action( 'login_form_th_2fa_challenge', function() {
	$token = $_GET['token'] ?? '';
	$payload = th_2fa_decode_token( $token );
	if ( ! $payload || $payload['exp'] < time() ) {
		wp_redirect( wp_login_url() );
		exit;
	}
	$user = get_user_by( 'id', $payload['uid'] );
	if ( ! $user ) {
		wp_redirect( wp_login_url() );
		exit;
	}

	login_header( 'Two-factor authentication', '', new WP_Error() );
	?>
	<form method="post" action="<?php echo esc_url( wp_login_url() ); ?>" id="loginform" autocomplete="off">
		<input type="hidden" name="log"         value="<?php echo esc_attr( $user->user_login ); ?>" />
		<input type="hidden" name="th_2fa_token" value="<?php echo esc_attr( $token ); ?>" />

		<p style="margin: 0 0 6px; font-size: 13px; opacity: 0.85;">
			Enter the 6-digit code from your authenticator app.
		</p>
		<p style="margin: 0 0 14px; font-size: 12px; opacity: 0.55;">
			Or use a backup code (XXXX-XXXX).
		</p>

		<p>
			<label for="th_2fa_code">Code</label>
			<input type="text"
			       name="th_2fa_code"
			       id="th_2fa_code"
			       class="input"
			       autocomplete="one-time-code"
			       inputmode="numeric"
			       autofocus
			       style="letter-spacing: 0.4em; text-align: center; font-size: 18px; font-family: ui-monospace, Menlo, monospace;" />
		</p>
		<p>
			<input type="submit" class="button button-primary" value="Verify and sign in" />
		</p>
		<p class="th-login-row" style="margin-top: 18px;">
			<a href="<?php echo esc_url( wp_login_url() ); ?>">← Back to login</a>
		</p>
	</form>
	<?php
	login_footer();
	exit;
});


/**
 * Token issuance + decoding — short-lived signed tokens to bridge the password
 * step and the code step. 5-minute expiry. Signed with wp_salt().
 */
function th_2fa_issue_token( int $user_id ): string {
	$payload = [ 'uid' => $user_id, 'exp' => time() + 300 ];
	$json    = wp_json_encode( $payload );
	$sig     = hash_hmac( 'sha256', $json, wp_salt( 'auth' ) );
	return base64_encode( $json ) . '.' . $sig;
}

function th_2fa_decode_token( string $token ): ?array {
	if ( ! str_contains( $token, '.' ) ) return null;
	[ $b64, $sig ] = explode( '.', $token, 2 );
	$json = base64_decode( $b64, true );
	if ( ! $json ) return null;
	$expect = hash_hmac( 'sha256', $json, wp_salt( 'auth' ) );
	if ( ! hash_equals( $expect, $sig ) ) return null;
	$payload = json_decode( $json, true );
	if ( ! is_array( $payload ) || empty( $payload['uid'] ) ) return null;
	return $payload;
}


// ═════════════════════════════════════════════════════════════════════════════
//  USER PROFILE — enrollment screen at /wp-admin/profile.php#2fa
// ═════════════════════════════════════════════════════════════════════════════

add_action( 'show_user_profile', 'th_2fa_render_profile_section' );
add_action( 'edit_user_profile', 'th_2fa_render_profile_section' );

function th_2fa_render_profile_section( $user ) {
	$enabled = Therum_2FA::user_enabled( $user->ID );
	$secret  = get_user_meta( $user->ID, 'th_2fa_secret', true );

	if ( $enabled ) {
		$backup_remaining = is_array( get_user_meta( $user->ID, 'th_2fa_backup_codes', true ) )
			? count( get_user_meta( $user->ID, 'th_2fa_backup_codes', true ) ) : 0;
		?>
		<h2 id="2fa">Two-factor authentication</h2>
		<table class="form-table">
			<tr>
				<th>Status</th>
				<td>
					<span style="display:inline-flex;align-items:center;gap:6px;color:#10b981;">
						<span style="width:8px;height:8px;border-radius:50%;background:#10b981;"></span> Enabled
					</span>
				</td>
			</tr>
			<tr>
				<th>Backup codes</th>
				<td><?php echo esc_html( $backup_remaining ); ?> remaining
					<?php if ( $backup_remaining < 3 ): ?>
						<span style="color:#f59e0b;margin-left:8px;">— consider generating new ones</span>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th>Disable 2FA</th>
				<td>
					<button type="button" class="button" name="th_2fa_disable" value="1" onclick="document.getElementById('th_2fa_disable').value='1';this.form.submit();">
						Turn off two-factor
					</button>
					<input type="hidden" name="th_2fa_disable" id="th_2fa_disable" value="0" />
				</td>
			</tr>
		</table>
		<?php
		return;
	}

	// Not enrolled yet — show setup
	if ( ! $secret ) {
		$secret = Therum_2FA::generate_secret();
		update_user_meta( $user->ID, 'th_2fa_secret', $secret );
	}
	$account = $user->user_email ?: $user->user_login;
	$issuer  = get_option( 'th_wordmark', 'Therum OS' );
	$uri     = Therum_2FA::provisioning_uri( $secret, $account, $issuer );
	$qr      = Therum_2FA::qr_url( $uri, 200 );

	?>
	<h2 id="2fa">Two-factor authentication</h2>
	<p>Scan this QR with Google Authenticator, 1Password, Authy, or any TOTP app. Then enter a code to confirm.</p>
	<table class="form-table">
		<tr>
			<th>1. Scan</th>
			<td>
				<?php if ( $qr ): ?>
					<img src="<?php echo esc_url( $qr ); ?>" alt="2FA QR code"
					     style="width:180px;height:180px;border-radius:8px;background:#fff;padding:8px;" />
				<?php endif; ?>
				<div style="margin-top:10px;font-family:ui-monospace,Menlo,monospace;font-size:12px;opacity:0.7;">
					Or enter this secret manually:<br>
					<code style="display:inline-block;margin-top:4px;padding:6px 10px;background:rgba(0,0,0,0.06);border-radius:4px;letter-spacing:0.1em;"><?php echo esc_html( implode( ' ', str_split( $secret, 4 ) ) ); ?></code>
				</div>
			</td>
		</tr>
		<tr>
			<th>2. Verify</th>
			<td>
				<input type="text"
				       name="th_2fa_setup_code"
				       maxlength="6"
				       inputmode="numeric"
				       placeholder="000000"
				       style="width:140px;letter-spacing:0.3em;text-align:center;font-family:ui-monospace,Menlo,monospace;font-size:16px;" />
				<p class="description">Enter the 6-digit code from your authenticator to enroll. Backup codes will be shown after enrollment.</p>
			</td>
		</tr>
	</table>
	<?php
}

/**
 * Save 2FA setup on profile update.
 */
add_action( 'personal_options_update', 'th_2fa_save_profile' );
add_action( 'edit_user_profile_update', 'th_2fa_save_profile' );

function th_2fa_save_profile( $user_id ) {
	// Defense-in-depth: only an actor allowed to edit THIS user, and only with a
	// valid profile-update nonce, may toggle 2FA state. Core checks the nonce in
	// edit_user() upstream, but the disable button is JS-driven so we re-verify
	// here to close any CSRF gap on the disable path.
	if ( ! current_user_can( 'edit_user', $user_id ) ) {
		return;
	}
	if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( $_POST['_wpnonce'], 'update-user_' . $user_id ) ) {
		return;
	}

	// Disable
	if ( ! empty( $_POST['th_2fa_disable'] ) && $_POST['th_2fa_disable'] === '1' ) {
		delete_user_meta( $user_id, 'th_2fa_secret' );
		delete_user_meta( $user_id, 'th_2fa_enabled' );
		delete_user_meta( $user_id, 'th_2fa_backup_codes' );
		delete_user_meta( $user_id, 'th_2fa_last_used_slot' );
		set_transient( 'th_2fa_msg_' . $user_id, [ 'type' => 'info', 'msg' => 'Two-factor turned off.' ], 60 );
		return;
	}

	// Enroll: verify the setup code, then issue backup codes
	$code = trim( $_POST['th_2fa_setup_code'] ?? '' );
	if ( $code && ! Therum_2FA::user_enabled( $user_id ) ) {
		$secret = get_user_meta( $user_id, 'th_2fa_secret', true );
		if ( $secret && Therum_2FA::verify( $secret, $code ) !== false ) {
			update_user_meta( $user_id, 'th_2fa_enabled', '1' );
			$plain = Therum_2FA::generate_backup_codes( 8 );
			update_user_meta( $user_id, 'th_2fa_backup_codes', Therum_2FA::hash_backup_codes( $plain ) );
			set_transient( 'th_2fa_backup_show_' . $user_id, $plain, 300 );
			set_transient( 'th_2fa_msg_' . $user_id, [ 'type' => 'success', 'msg' => '2FA enabled. Save your backup codes below.' ], 60 );
		} else {
			delete_user_meta( $user_id, 'th_2fa_secret' );
			set_transient( 'th_2fa_msg_' . $user_id, [ 'type' => 'error', 'msg' => 'Invalid code. Setup cancelled — scan again to retry.' ], 60 );
		}
	}
}

/**
 * Show backup codes after enrollment + status messages.
 */
add_action( 'admin_notices', function() {
	$user_id = get_current_user_id();
	if ( ! $user_id ) return;

	$msg = get_transient( 'th_2fa_msg_' . $user_id );
	if ( $msg ) {
		$cls = 'notice-' . ( $msg['type'] ?? 'info' );
		echo '<div class="notice ' . esc_attr( $cls ) . ' is-dismissible"><p>' . esc_html( $msg['msg'] ) . '</p></div>';
		delete_transient( 'th_2fa_msg_' . $user_id );
	}

	$codes = get_transient( 'th_2fa_backup_show_' . $user_id );
	if ( is_array( $codes ) && ! empty( $codes ) ) {
		?>
		<div class="notice notice-warning" style="border-left-color:#f59e0b;">
			<p><strong>Save these backup codes.</strong> Each works once. Store them somewhere safe — they let you in if you lose your authenticator.</p>
			<div style="display:grid;grid-template-columns:repeat(2,max-content);gap:6px 16px;font-family:ui-monospace,Menlo,monospace;font-size:14px;padding:12px 0;">
				<?php foreach ( $codes as $c ): ?>
					<div style="padding:4px 10px;background:rgba(0,0,0,0.04);border-radius:4px;letter-spacing:0.05em;"><?php echo esc_html( $c ); ?></div>
				<?php endforeach; ?>
			</div>
			<p style="margin-bottom:8px;"><em>This is the only time these will be shown.</em></p>
		</div>
		<?php
		delete_transient( 'th_2fa_backup_show_' . $user_id );
	}
});


// ═════════════════════════════════════════════════════════════════════════════
//  SETTINGS — register a 2FA group inside the Security section
// ═════════════════════════════════════════════════════════════════════════════
add_filter( 'therum_settings_keys', function( $keys ) {
	return array_merge( $keys, [
		'th_2fa_required_for_admins',
	]);
});

/**
 * Add a "Manage 2FA" entry to the user dropdown / shortcuts. Available to all
 * authenticated users via their profile.
 */
add_filter( 'admin_bar_menu', function( $bar ) {
	if ( ! is_user_logged_in() ) return;
	$bar->add_node([
		'id'     => 'th-2fa',
		'parent' => 'user-actions',
		'title'  => 'Two-factor auth',
		'href'   => admin_url( 'profile.php#2fa' ),
	]);
}, 100 );


// ════════════════════════════════════════════════════════════════════════════
//  CUSTOM ROLES + WC ROLE PRICING — from therum-roles-engine.php
// ════════════════════════════════════════════════════════════════════════════


// ═════════════════════════════════════════════════════════════════════════════
//  CAPABILITY BUNDLES
// ═════════════════════════════════════════════════════════════════════════════
//
// Bundle = a named set of caps you can apply to a role with one click.
// Roles can mix bundles + individual caps freely.

function therum_capability_bundles() {
	return [
		'read' => [
			'label' => 'Read',
			'desc'  => 'View site, see private posts they\'re assigned to.',
			'caps'  => [ 'read', 'level_0' ],
		],
		'write' => [
			'label' => 'Write',
			'desc'  => 'Draft posts. Edit own drafts. Cannot publish.',
			'caps'  => [
				'read', 'edit_posts', 'delete_posts', 'level_0', 'level_1',
			],
		],
		'publish' => [
			'label' => 'Publish',
			'desc'  => 'Write + publish their own posts. Upload media.',
			'caps'  => [
				'read', 'edit_posts', 'delete_posts', 'publish_posts',
				'edit_published_posts', 'delete_published_posts',
				'upload_files', 'level_0', 'level_1', 'level_2',
			],
		],
		'edit_any' => [
			'label' => 'Edit any',
			'desc'  => 'Editor — edit/publish/delete anyone\'s content.',
			'caps'  => [
				'read', 'edit_posts', 'edit_others_posts', 'edit_published_posts',
				'edit_private_posts', 'publish_posts', 'delete_posts',
				'delete_others_posts', 'delete_published_posts', 'delete_private_posts',
				'read_private_posts', 'edit_pages', 'edit_others_pages',
				'edit_published_pages', 'edit_private_pages', 'publish_pages',
				'delete_pages', 'delete_others_pages', 'delete_published_pages',
				'delete_private_pages', 'read_private_pages', 'manage_categories',
				'moderate_comments', 'upload_files', 'unfiltered_html',
				'level_0', 'level_1', 'level_2', 'level_3', 'level_4', 'level_5',
				'level_6', 'level_7',
			],
		],
		'settings' => [
			'label' => 'Settings',
			'desc'  => 'Manage site options, plugins, themes, users.',
			'caps'  => [
				'manage_options', 'manage_categories', 'list_users', 'create_users',
				'edit_users', 'delete_users', 'promote_users', 'remove_users',
				'install_plugins', 'activate_plugins', 'edit_plugins', 'delete_plugins',
				'update_plugins', 'install_themes', 'switch_themes', 'edit_themes',
				'delete_themes', 'update_themes', 'update_core', 'export', 'import',
			],
		],
		'shop_customer' => [
			'label' => 'Shop customer',
			'desc'  => 'WooCommerce — buy products, view own orders.',
			'caps'  => [ 'read' ],
		],
		'shop_manager' => [
			'label' => 'Shop manager',
			'desc'  => 'WooCommerce — manage products, orders, coupons.',
			'caps'  => [
				'read', 'view_admin_dashboard', 'read_private_pages', 'read_private_posts',
				'edit_users', 'edit_posts', 'edit_pages', 'edit_published_posts',
				'edit_published_pages', 'publish_posts', 'publish_pages',
				'delete_posts', 'delete_pages', 'delete_published_posts',
				'delete_published_pages', 'delete_others_posts', 'delete_others_pages',
				'edit_others_posts', 'edit_others_pages', 'manage_categories',
				'manage_links', 'moderate_comments', 'unfiltered_html', 'upload_files',
				'export', 'import', 'list_users', 'manage_woocommerce',
				'view_woocommerce_reports', 'edit_product', 'read_product',
				'delete_product', 'edit_products', 'edit_others_products',
				'publish_products', 'read_private_products', 'delete_products',
				'delete_private_products', 'delete_published_products',
				'delete_others_products', 'edit_private_products',
				'edit_published_products', 'manage_product_terms', 'edit_product_terms',
				'delete_product_terms', 'assign_product_terms',
			],
		],
	];
}


// ═════════════════════════════════════════════════════════════════════════════
//  CUSTOM ROLES STORE — meta about roles we created (display name, bundles
//  applied, woo discount %)
// ═════════════════════════════════════════════════════════════════════════════

function therum_get_custom_roles() {
	return (array) get_option( 'th_custom_roles', [] );
}

function therum_save_custom_role( $key, $data ) {
	$roles = therum_get_custom_roles();
	$roles[ $key ] = $data;
	update_option( 'th_custom_roles', $roles );
}

function therum_delete_custom_role_meta( $key ) {
	$roles = therum_get_custom_roles();
	unset( $roles[ $key ] );
	update_option( 'th_custom_roles', $roles );
}

// Resolve all caps from selected bundles + individual caps
function therum_resolve_caps( $bundles, $individual_caps ) {
	$cap_set = [];
	$bundles_def = therum_capability_bundles();
	foreach ( (array) $bundles as $b ) {
		if ( isset( $bundles_def[ $b ] ) ) {
			foreach ( $bundles_def[ $b ]['caps'] as $c ) $cap_set[ $c ] = true;
		}
	}
	foreach ( (array) $individual_caps as $c ) {
		if ( $c ) $cap_set[ $c ] = true;
	}
	return $cap_set;
}


// ═════════════════════════════════════════════════════════════════════════════
//  AJAX — create/update/delete custom roles
// ═════════════════════════════════════════════════════════════════════════════

add_action( 'wp_ajax_therum_role_save', function() {
	if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'forbidden', 403 );
	check_ajax_referer( 'therum_role', 'nonce' );

	$key       = sanitize_key( $_POST['key'] ?? '' );
	$name      = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );
	$bundles   = isset( $_POST['bundles'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['bundles'] ) ) : [];
	$caps      = isset( $_POST['caps'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['caps'] ) ) : [];
	$discount  = isset( $_POST['discount'] ) ? max( 0, min( 100, (float) $_POST['discount'] ) ) : 0;
	$is_new    = isset( $_POST['is_new'] ) && $_POST['is_new'] === '1';

	if ( ! $name ) wp_send_json_error( 'name is required' );

	// Auto-generate key on new from name
	if ( $is_new || ! $key ) {
		$key = sanitize_key( $name );
		if ( ! $key ) wp_send_json_error( 'invalid name' );
		// Prevent overwriting built-ins
		$reserved = [ 'administrator','editor','author','contributor','subscriber','customer','shop_manager' ];
		if ( in_array( $key, $reserved, true ) ) {
			wp_send_json_error( 'cannot overwrite built-in role: ' . $key );
		}
		// Disambiguate if exists
		global $wp_roles;
		if ( ! $wp_roles ) $wp_roles = wp_roles();
		$base = $key;
		$i = 2;
		while ( isset( $wp_roles->roles[ $key ] ) ) {
			$key = $base . '_' . $i;
			$i++;
		}
	}

	$cap_set = therum_resolve_caps( $bundles, $caps );

	// Remove existing role first if updating (to clean up stale caps)
	global $wp_roles;
	if ( ! $wp_roles ) $wp_roles = wp_roles();
	if ( isset( $wp_roles->roles[ $key ] ) ) {
		remove_role( $key );
	}

	add_role( $key, $name, $cap_set );

	therum_save_custom_role( $key, [
		'key'      => $key,
		'name'     => $name,
		'bundles'  => $bundles,
		'caps'     => array_keys( $cap_set ),
		'discount' => $discount,
		'updated'  => time(),
	]);

	wp_send_json_success([
		'key'  => $key,
		'name' => $name,
		'msg'  => $is_new ? 'Role created' : 'Role updated',
	]);
});


add_action( 'wp_ajax_therum_role_delete', function() {
	if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'forbidden', 403 );
	check_ajax_referer( 'therum_role', 'nonce' );

	$key = sanitize_key( $_POST['key'] ?? '' );
	if ( ! $key ) wp_send_json_error( 'no key' );

	// Prevent built-in deletion
	$reserved = [ 'administrator','editor','author','contributor','subscriber','customer','shop_manager' ];
	if ( in_array( $key, $reserved, true ) ) {
		wp_send_json_error( 'cannot delete built-in role' );
	}

	// Reassign affected users to default role
	$default = get_option( 'default_role', 'subscriber' );
	$users = get_users( [ 'role' => $key, 'fields' => 'ID' ] );
	foreach ( $users as $uid ) {
		$user = get_userdata( $uid );
		if ( $user ) {
			$user->remove_role( $key );
			$user->add_role( $default );
		}
	}

	remove_role( $key );
	therum_delete_custom_role_meta( $key );

	wp_send_json_success([ 'msg' => 'Role deleted. ' . count( $users ) . ' user(s) reassigned to ' . $default . '.' ]);
});


// ═════════════════════════════════════════════════════════════════════════════
//  WOOCOMMERCE — role-based percentage discount
// ═════════════════════════════════════════════════════════════════════════════
//
// For each cart, find the user's roles, look up their largest discount, and
// apply it as a fee (negative). Hooks woocommerce_cart_calculate_fees.

add_action( 'woocommerce_cart_calculate_fees', function( $cart ) {
	if ( is_admin() && ! defined( 'DOING_AJAX' ) ) return;
	if ( ! is_user_logged_in() ) return;

	$user = wp_get_current_user();
	if ( empty( $user->roles ) ) return;

	$custom = therum_get_custom_roles();
	$max_discount = 0.0;
	$discount_role = '';

	foreach ( $user->roles as $role_key ) {
		if ( ! isset( $custom[ $role_key ] ) ) continue;
		$pct = (float) ( $custom[ $role_key ]['discount'] ?? 0 );
		if ( $pct > $max_discount ) {
			$max_discount = $pct;
			$discount_role = $custom[ $role_key ]['name'] ?? $role_key;
		}
	}

	if ( $max_discount <= 0 ) return;

	$subtotal = (float) $cart->get_subtotal();
	if ( $subtotal <= 0 ) return;

	$discount = round( $subtotal * ( $max_discount / 100 ), 2 );
	$label = sprintf( '%s discount (%s%%)', $discount_role, rtrim( rtrim( number_format( $max_discount, 2 ), '0' ), '.' ) );

	$cart->add_fee( $label, -$discount, false );
}, 20, 1 );


// Show the role on the user's account page (purely informational)
add_action( 'woocommerce_account_dashboard', function() {
	$user = wp_get_current_user();
	if ( empty( $user->roles ) ) return;

	$custom = therum_get_custom_roles();
	$discount = 0.0;
	$role_name = '';
	foreach ( $user->roles as $r ) {
		if ( isset( $custom[ $r ] ) && (float) ( $custom[ $r ]['discount'] ?? 0 ) > $discount ) {
			$discount = (float) $custom[ $r ]['discount'];
			$role_name = $custom[ $r ]['name'];
		}
	}
	if ( $discount > 0 ) {
		echo '<div style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:10px;padding:14px 18px;margin:14px 0;">';
		echo '<strong>' . esc_html( $role_name ) . ':</strong> ';
		echo 'You receive ' . esc_html( rtrim( rtrim( number_format( $discount, 2 ), '0' ), '.' ) ) . '% off all orders automatically at checkout.';
		echo '</div>';
	}
});


// ═════════════════════════════════════════════════════════════════════════════
//  CAPABILITY SELF-HEAL — keeps administrator role fully intact
// ═════════════════════════════════════════════════════════════════════════════
//
// Some plugins (security plugins, role editors, third-party customization) can
// silently strip capabilities from the administrator role. Bricks's admin pages
// require manage_options — if it's missing, every Bricks admin link wp_die()s
// with "Sorry, you are not allowed to access this page."
//
// This module ensures the administrator role always has the core admin caps
// it should have. Runs on every admin page load (cheap — just an array read).
// Only acts if a cap is actually missing.

function therum_admin_required_caps() {
	// The full default WordPress administrator capability set.
	// Source: wp-admin/includes/schema.php :: populate_roles_270() and friends.
	return [
		'switch_themes', 'edit_themes', 'activate_plugins', 'edit_plugins',
		'edit_users', 'edit_files', 'manage_options', 'moderate_comments',
		'manage_categories', 'manage_links', 'upload_files', 'import',
		'unfiltered_html', 'edit_posts', 'edit_others_posts', 'edit_published_posts',
		'publish_posts', 'edit_pages', 'read', 'level_10', 'level_9', 'level_8',
		'level_7', 'level_6', 'level_5', 'level_4', 'level_3', 'level_2',
		'level_1', 'level_0', 'edit_others_pages', 'edit_published_pages',
		'publish_pages', 'delete_pages', 'delete_others_pages', 'delete_published_pages',
		'delete_posts', 'delete_others_posts', 'delete_published_posts',
		'delete_private_posts', 'edit_private_posts', 'read_private_posts',
		'delete_private_pages', 'edit_private_pages', 'read_private_pages',
		'delete_users', 'create_users', 'unfiltered_upload', 'edit_dashboard',
		'update_plugins', 'delete_plugins', 'install_plugins', 'update_themes',
		'install_themes', 'update_core', 'list_users', 'remove_users',
		'promote_users', 'edit_theme_options', 'delete_themes', 'export',
	];
}

function therum_heal_admin_caps() {
	$role = get_role( 'administrator' );
	if ( ! $role ) {
		// Administrator role itself is missing — recreate from scratch.
		add_role( 'administrator', 'Administrator', array_fill_keys( therum_admin_required_caps(), true ) );
		$role = get_role( 'administrator' );
		if ( ! $role ) return; // give up
	}

	$missing = [];
	foreach ( therum_admin_required_caps() as $cap ) {
		if ( ! $role->has_cap( $cap ) ) {
			$role->add_cap( $cap );
			$missing[] = $cap;
		}
	}

	// If we restored manage_options specifically, log it for diagnostic value.
	if ( in_array( 'manage_options', $missing, true ) && function_exists( 'error_log' ) ) {
		error_log( '[Therum] Restored missing administrator capabilities: ' . implode( ', ', $missing ) );
	}
}

// Run on admin page loads — cheap, only acts when something's actually missing.
// Priority 5 so this fires BEFORE Bricks's admin_menu hook (which runs at default 10).
add_action( 'admin_init', 'therum_heal_admin_caps', 5 );

// Also run on user login so the user picks up restored caps immediately
// (caps are evaluated against the role at request time, but this ensures
// we don't let a freshly-logged-in admin hit a broken Bricks page first).
add_action( 'wp_login', 'therum_heal_admin_caps' );


// ═════════════════════════════════════════════════════════════════════════════
//  RENDER — replaces th_render_permissions in therum-settings.php
// ═════════════════════════════════════════════════════════════════════════════

function therum_render_permissions_full() {
	global $wp_roles;
	if ( ! $wp_roles ) $wp_roles = wp_roles();

	$default_role = get_option( 'default_role', 'subscriber' );
	$custom = therum_get_custom_roles();
	$counts = count_users()['avail_roles'] ?? [];
	$bundles = therum_capability_bundles();
	$nonce = wp_create_nonce( 'therum_role' );
	$woo_active = class_exists( 'WooCommerce' );

	// All known caps across all roles for the "individual capability" picker
	$all_caps = [];
	foreach ( $wp_roles->roles as $role ) {
		foreach ( array_keys( $role['capabilities'] ) as $c ) {
			$all_caps[ $c ] = true;
		}
	}
	foreach ( $bundles as $b ) {
		foreach ( $b['caps'] as $c ) $all_caps[ $c ] = true;
	}
	$all_caps = array_keys( $all_caps );
	sort( $all_caps );

	th_settings_group( 'New user defaults', 'What role new users get when they register.', function() use ( $default_role, $wp_roles ) {
		$opts = [];
		foreach ( $wp_roles->roles as $key => $role ) {
			$opts[ $key ] = $role['name'];
		}
		th_setting_row( 'Default role', 'Capabilities new users start with.', th_select( 'default_role', $default_role, $opts ) );
	});

	th_settings_group( 'Roles overview', 'Built-in WordPress roles and any custom roles you\'ve created.', function() use ( $wp_roles, $counts, $custom ) {
		?>
		<table class="th-roles-table" style="width:100%;">
		  <thead>
			<tr>
			  <th>Role</th>
			  <th style="width:80px;">Users</th>
			  <th>Key capabilities</th>
			  <th style="width:110px;">Type</th>
			  <th style="width:80px;text-align:right;"></th>
			</tr>
		  </thead>
		  <tbody>
		  <?php foreach ( $wp_roles->roles as $key => $role ):
			$cap_summary = [];
			if ( ! empty( $role['capabilities']['manage_options'] ) ) $cap_summary[] = 'Settings';
			if ( ! empty( $role['capabilities']['manage_woocommerce'] ) ) $cap_summary[] = 'Shop';
			if ( ! empty( $role['capabilities']['edit_others_posts'] ) ) $cap_summary[] = 'Edit any';
			if ( ! empty( $role['capabilities']['publish_posts'] ) ) $cap_summary[] = 'Publish';
			if ( ! empty( $role['capabilities']['edit_posts'] ) ) $cap_summary[] = 'Write';
			if ( empty( $cap_summary ) ) $cap_summary[] = 'Read';
			$is_custom = isset( $custom[ $key ] );
			$discount = $is_custom ? (float) ( $custom[ $key ]['discount'] ?? 0 ) : 0;
		  ?>
			<tr data-role-row="<?php echo esc_attr( $key ); ?>">
			  <td>
				<strong><?php echo esc_html( $role['name'] ); ?></strong>
				<?php if ( $discount > 0 ): ?>
				  <span style="display:inline-block;margin-left:6px;padding:2px 8px;background:color-mix(in srgb, var(--ac) 15%, transparent);color:var(--ac);border-radius:10px;font-size:10px;font-weight:600;">−<?php echo esc_html( rtrim( rtrim( number_format( $discount, 2 ), '0' ), '.' ) ); ?>% off</span>
				<?php endif; ?>
			  </td>
			  <td><?php echo (int) ( $counts[ $key ] ?? 0 ); ?></td>
			  <td class="th-roles-caps"><?php echo esc_html( implode( ' · ', $cap_summary ) ); ?></td>
			  <td>
				<?php if ( $is_custom ): ?>
				  <span style="font-size:11px;color:var(--ac);font-weight:600;">Custom</span>
				<?php else: ?>
				  <span style="font-size:11px;color:var(--tx3);">Built-in</span>
				<?php endif; ?>
			  </td>
			  <td style="text-align:right;">
				<?php if ( $is_custom ): ?>
				  <button type="button" class="th-button" data-role-edit="<?php echo esc_attr( $key ); ?>" style="padding:5px 10px;font-size:11px;">Edit</button>
				<?php endif; ?>
			  </td>
			</tr>
		  <?php endforeach; ?>
		  </tbody>
		</table>
		<?php
	});

	th_settings_group( 'Custom roles', 'Build your own roles. Mix preset capability bundles with individual caps.', function() use ( $bundles, $all_caps, $custom, $woo_active, $nonce ) {
		?>
		<div class="th-role-builder" data-th-role-builder data-nonce="<?php echo esc_attr( $nonce ); ?>">
		  <button type="button" class="th-button th-button-primary" data-role-new>+ New custom role</button>

		  <!-- Editor panel (hidden by default) -->
		  <div class="th-role-editor" data-role-editor hidden>
			<div class="th-role-editor-head">
			  <h3 data-role-editor-title>New custom role</h3>
			  <button type="button" class="th-role-close" data-role-cancel aria-label="Cancel">×</button>
			</div>

			<input type="hidden" data-role-key value="">
			<input type="hidden" data-role-is-new value="1">

			<div class="th-role-field">
			  <label>Role name</label>
			  <input type="text" class="th-input" data-role-name placeholder="Friends &amp; Family" maxlength="60">
			  <small>Display name shown to admins. The internal key is auto-generated from this.</small>
			</div>

			<div class="th-role-field">
			  <label>Capability bundles</label>
			  <small>Click to toggle. Bundles add their caps to the role. Mix freely.</small>
			  <div class="th-bundle-grid">
				<?php foreach ( $bundles as $bk => $b ): ?>
				<label class="th-bundle-card" data-bundle-card>
				  <input type="checkbox" data-bundle="<?php echo esc_attr( $bk ); ?>" value="<?php echo esc_attr( $bk ); ?>">
				  <div class="th-bundle-name"><?php echo esc_html( $b['label'] ); ?></div>
				  <div class="th-bundle-desc"><?php echo esc_html( $b['desc'] ); ?></div>
				</label>
				<?php endforeach; ?>
			  </div>
			</div>

			<div class="th-role-field">
			  <label>Individual capabilities</label>
			  <small>Fine-grained caps on top of (or instead of) bundles. Search to filter.</small>
			  <input type="text" class="th-input" data-cap-search placeholder="Filter capabilities…" style="margin-bottom:10px;">
			  <div class="th-cap-grid" data-cap-grid>
				<?php foreach ( $all_caps as $cap ): ?>
				<label class="th-cap-pill" data-cap-pill data-cap-name="<?php echo esc_attr( $cap ); ?>">
				  <input type="checkbox" data-cap="<?php echo esc_attr( $cap ); ?>" value="<?php echo esc_attr( $cap ); ?>">
				  <span><?php echo esc_html( $cap ); ?></span>
				</label>
				<?php endforeach; ?>
			  </div>
			</div>

			<?php if ( $woo_active ): ?>
			<div class="th-role-field">
			  <label>WooCommerce discount</label>
			  <small>Percentage off cart subtotal. Applied automatically at checkout for any user with this role. Use 0 to disable.</small>
			  <div style="display:flex;align-items:center;gap:8px;max-width:200px;">
				<input type="number" class="th-input" data-role-discount min="0" max="100" step="0.5" value="0" style="text-align:right;">
				<span style="font-size:14px;color:var(--tx2);font-weight:600;">%</span>
			  </div>
			</div>
			<?php else: ?>
			<div class="th-role-field">
			  <label>WooCommerce discount</label>
			  <small style="color:var(--tx3);">WooCommerce isn't active. Activate it to enable role-based pricing for Friends &amp; Family or VIP customers.</small>
			</div>
			<input type="hidden" data-role-discount value="0">
			<?php endif; ?>

			<div class="th-role-actions">
			  <span class="th-role-result" data-role-result></span>
			  <button type="button" class="th-button" data-role-cancel>Cancel</button>
			  <button type="button" class="th-button" data-role-delete style="color:var(--err);border-color:color-mix(in srgb, var(--err) 30%, transparent);" hidden>Delete</button>
			  <button type="button" class="th-button th-button-primary" data-role-save>Save role</button>
			</div>
		  </div>
		</div>

		<?php
		// Pass custom roles data to JS
		$roles_payload = [];
		foreach ( $custom as $k => $r ) {
			$roles_payload[ $k ] = [
				'key'      => $k,
				'name'     => $r['name'] ?? $k,
				'bundles'  => $r['bundles'] ?? [],
				'caps'     => $r['caps'] ?? [],
				'discount' => (float) ( $r['discount'] ?? 0 ),
			];
		}
		?>
		<script>
		(function() {
		  var ROLES = <?php echo wp_json_encode( $roles_payload ); ?>;
		  var builder = document.querySelector('[data-th-role-builder]');
		  if (!builder) return;
		  var nonce = builder.getAttribute('data-nonce');
		  var ajax  = window.ajaxurl || '/wp-admin/admin-ajax.php';
		  var editor = builder.querySelector('[data-role-editor]');
		  var resultEl = builder.querySelector('[data-role-result]');

		  function $(s, ctx) { return (ctx || builder).querySelector(s); }
		  function $$(s, ctx) { return Array.prototype.slice.call((ctx || builder).querySelectorAll(s)); }

		  function setVal(sel, v) { var el = $(sel); if (el) el.value = v; }
		  function getVal(sel)    { var el = $(sel); return el ? el.value : ''; }

		  function openEditor(role) {
			editor.hidden = false;
			resultEl.textContent = '';
			var isNew = !role;
			$('[data-role-editor-title]').textContent = isNew ? 'New custom role' : 'Edit role: ' + role.name;
			setVal('[data-role-key]', role ? role.key : '');
			setVal('[data-role-is-new]', isNew ? '1' : '0');
			setVal('[data-role-name]', role ? role.name : '');
			setVal('[data-role-discount]', role ? role.discount : 0);
			// Toggle bundle checkboxes
			$$('[data-bundle]').forEach(function(cb) {
				cb.checked = role && role.bundles && role.bundles.indexOf(cb.value) >= 0;
				var card = cb.closest('[data-bundle-card]');
				if (card) card.classList.toggle('active', cb.checked);
			});
			// Toggle cap checkboxes
			$$('[data-cap]').forEach(function(cb) {
				cb.checked = role && role.caps && role.caps.indexOf(cb.value) >= 0;
				var pill = cb.closest('[data-cap-pill]');
				if (pill) pill.classList.toggle('active', cb.checked);
			});
			var delBtn = $('[data-role-delete]');
			if (delBtn) delBtn.hidden = isNew;
			editor.scrollIntoView({ behavior: 'smooth', block: 'center' });
		  }

		  function closeEditor() { editor.hidden = true; resultEl.textContent = ''; }

		  // New
		  $('[data-role-new]').addEventListener('click', function() { openEditor(null); });

		  // Edit existing (delegated)
		  document.addEventListener('click', function(e) {
			var editBtn = e.target.closest('[data-role-edit]');
			if (editBtn) {
			  e.preventDefault();
			  var k = editBtn.getAttribute('data-role-edit');
			  if (ROLES[k]) openEditor(ROLES[k]);
			}
		  });

		  // Cancel
		  $$('[data-role-cancel]').forEach(function(b) { b.addEventListener('click', closeEditor); });

		  // Bundle / cap visual toggle
		  builder.addEventListener('change', function(e) {
			var t = e.target;
			if (t.matches('[data-bundle]')) {
			  var card = t.closest('[data-bundle-card]');
			  if (card) card.classList.toggle('active', t.checked);
			}
			if (t.matches('[data-cap]')) {
			  var pill = t.closest('[data-cap-pill]');
			  if (pill) pill.classList.toggle('active', t.checked);
			}
		  });

		  // Capability search
		  var capSearch = $('[data-cap-search]');
		  if (capSearch) {
			capSearch.addEventListener('input', function() {
			  var q = capSearch.value.toLowerCase().trim();
			  $$('[data-cap-pill]').forEach(function(p) {
				var name = p.getAttribute('data-cap-name') || '';
				p.style.display = (!q || name.indexOf(q) >= 0) ? '' : 'none';
			  });
			});
		  }

		  // Save
		  $('[data-role-save]').addEventListener('click', function() {
			var btn = this;
			var bundles = $$('[data-bundle]:checked').map(function(c) { return c.value; });
			var caps    = $$('[data-cap]:checked').map(function(c) { return c.value; });
			var fd = new FormData();
			fd.append('action', 'therum_role_save');
			fd.append('nonce', nonce);
			fd.append('key',      getVal('[data-role-key]'));
			fd.append('name',     getVal('[data-role-name]'));
			fd.append('is_new',   getVal('[data-role-is-new]'));
			fd.append('discount', getVal('[data-role-discount]'));
			bundles.forEach(function(b) { fd.append('bundles[]', b); });
			caps.forEach(function(c) { fd.append('caps[]', c); });

			btn.disabled = true; btn.style.opacity = '0.6';
			resultEl.textContent = 'Saving…'; resultEl.style.color = 'var(--tx2)';

			fetch(ajax, { method:'POST', credentials:'same-origin', body: fd })
			  .then(function(r) { return r.json(); })
			  .then(function(j) {
				btn.disabled = false; btn.style.opacity = '';
				if (j && j.success) {
				  resultEl.textContent = '✓ ' + (j.data.msg || 'Saved');
				  resultEl.style.color = 'var(--ok)';
				  setTimeout(function() { location.reload(); }, 700);
				} else {
				  resultEl.textContent = '✗ ' + ((j && j.data) || 'Failed');
				  resultEl.style.color = 'var(--err)';
				}
			  })
			  .catch(function() {
				btn.disabled = false; btn.style.opacity = '';
				resultEl.textContent = '✗ Network error';
				resultEl.style.color = 'var(--err)';
			  });
		  });

		  // Delete
		  $('[data-role-delete]').addEventListener('click', function() {
			var key = getVal('[data-role-key]');
			var name = getVal('[data-role-name]');
			if (!key) return;
			if (!confirm('Delete role "' + name + '"? Users will be reassigned to the default role.')) return;

			var fd = new FormData();
			fd.append('action', 'therum_role_delete');
			fd.append('nonce', nonce);
			fd.append('key', key);

			resultEl.textContent = 'Deleting…';
			fetch(ajax, { method:'POST', credentials:'same-origin', body: fd })
			  .then(function(r) { return r.json(); })
			  .then(function(j) {
				if (j && j.success) {
				  resultEl.textContent = '✓ ' + (j.data.msg || 'Deleted');
				  resultEl.style.color = 'var(--ok)';
				  setTimeout(function() { location.reload(); }, 800);
				} else {
				  resultEl.textContent = '✗ ' + ((j && j.data) || 'Failed');
				  resultEl.style.color = 'var(--err)';
				}
			  });
		  });
		})();
		</script>

		<style>
		.th-role-editor {
		  background: var(--sf);
		  border: 1px solid var(--bd);
		  border-radius: 14px;
		  padding: 24px 28px;
		  margin-top: 16px;
		  box-shadow: 0 4px 18px rgba(0,0,0,0.04);
		}
		.th-role-editor[hidden] { display: none; }
		.th-role-editor-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 18px; }
		.th-role-editor-head h3 { margin: 0; font-size: 16px; font-weight: 700; color: var(--tx); }
		.th-role-close {
		  background: transparent; border: 0; color: var(--tx3); cursor: pointer;
		  width: 28px; height: 28px; border-radius: 50%; font-size: 22px; line-height: 1;
		}
		.th-role-close:hover { background: var(--sf2); color: var(--tx); }
		.th-role-field { margin-bottom: 22px; }
		.th-role-field > label { display: block; font-size: 13px; font-weight: 600; color: var(--tx); margin-bottom: 4px; }
		.th-role-field > small { display: block; font-size: 11px; color: var(--tx3); margin-bottom: 10px; line-height: 1.4; }
		.th-role-field input[type="text"], .th-role-field input[type="number"] { max-width: 360px; width: 100%; }

		.th-bundle-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 10px; }
		.th-bundle-card {
		  position: relative; padding: 12px 14px;
		  background: var(--sf2); border: 2px solid var(--bd);
		  border-radius: 10px; cursor: pointer; transition: all 0.15s var(--e);
		}
		.th-bundle-card input { position: absolute; opacity: 0; pointer-events: none; }
		.th-bundle-card:hover { border-color: var(--tx3); }
		.th-bundle-card.active { border-color: var(--ac); background: color-mix(in srgb, var(--ac) 5%, var(--sf)); box-shadow: 0 0 0 4px color-mix(in srgb, var(--ac) 10%, transparent); }
		.th-bundle-name { font-size: 13px; font-weight: 600; color: var(--tx); margin-bottom: 2px; }
		.th-bundle-desc { font-size: 11px; color: var(--tx3); line-height: 1.3; }

		.th-cap-grid {
		  display: flex; flex-wrap: wrap; gap: 6px;
		  max-height: 280px; overflow-y: auto;
		  padding: 12px;
		  background: var(--sf2);
		  border: 1px solid var(--bd);
		  border-radius: 10px;
		}
		.th-cap-pill {
		  position: relative;
		  padding: 5px 10px;
		  background: var(--sf);
		  border: 1px solid var(--bd);
		  border-radius: 14px;
		  font-size: 11px;
		  font-family: monospace;
		  color: var(--tx2);
		  cursor: pointer;
		  transition: all 0.12s var(--e);
		}
		.th-cap-pill input { position: absolute; opacity: 0; pointer-events: none; }
		.th-cap-pill:hover { border-color: var(--ac); color: var(--ac); }
		.th-cap-pill.active { background: var(--ac); color: #fff; border-color: var(--ac); }

		.th-role-actions {
		  display: flex; gap: 10px; align-items: center; justify-content: flex-end;
		  padding-top: 18px; border-top: 1px solid var(--bd); margin-top: 18px;
		}
		.th-role-result { font-size: 12px; margin-right: auto; }
		</style>
		<?php
	});
}


// ════════════════════════════════════════════════════════════════════════════
//  THERUM API TOKENS (Phase 5.1 — capability-scoped tokens)
// ════════════════════════════════════════════════════════════════════════════
// Replaces all-or-nothing App Passwords for Therum's own REST surface (MCP,
// internal admin AJAX). Per-token scopes (mcp.read, mcp.write, mcp.dangerous,
// therum.* …) so the "Dangerous Actions" toggle becomes per-token instead of
// a global on/off. Classes live under Therum\Auth\* (see _therum/src/Auth/).
//
// App Passwords keep working — this layers on top; bricks-mcp, WP-CLI, and
// the existing Therum AJAX surface remain compatible.
//
// Kill switch:
//   define( 'THERUM_TOKENS_DISABLE', true ) in wp-config.php
//
// Admin UI for issue/list/revoke ships in the next session (Phase 5.1 part 2)
// — this section ships the data layer + REST authentication middleware.

if ( ! ( defined( 'THERUM_TOKENS_DISABLE' ) && THERUM_TOKENS_DISABLE ) ) {

	// Idempotent table install. Safe to call on every admin load.
	add_action( 'admin_init', function() {
		if ( ! class_exists( \Therum\Auth\TokenRegistry::class ) ) return;
		\Therum\Auth\TokenRegistry::install();
	}, 5 );

	// REST authentication middleware. Runs *after* WP's built-in auth
	// (cookie + App Passwords) — priority 20 means if WP already authenticated
	// the request, we pass through; otherwise we try to find + verify a
	// `Authorization: Bearer tro_...` token.
	add_filter( 'rest_authentication_errors', function( $errors ) {
		if ( ! class_exists( \Therum\Auth\Middleware::class ) ) return $errors;
		return \Therum\Auth\Middleware::on_rest_authentication_errors( $errors );
	}, 20 );
}


// ════════════════════════════════════════════════════════════════════════════
//  THERUM API TOKENS — admin UI + REST routes (Phase 5.1 Part 2)
// ════════════════════════════════════════════════════════════════════════════
// Users → API Tokens — issue / list / revoke scoped tokens via a small admin
// page. REST routes live under /wp-json/therum/v1/tokens.
//
// Reuses the kill switch from Part 1: THERUM_TOKENS_DISABLE bypasses BOTH the
// data layer and the UI.

if ( ! ( defined( 'THERUM_TOKENS_DISABLE' ) && THERUM_TOKENS_DISABLE ) ) {

	add_action( 'admin_menu', function() {
		if ( ! class_exists( \Therum\Auth\AdminPage::class ) ) return;
		\Therum\Auth\AdminPage::register_menu();
	} );

	add_action( 'rest_api_init', function() {
		if ( ! class_exists( \Therum\Auth\RestRoutes::class ) ) return;
		\Therum\Auth\RestRoutes::register();
	} );
}
