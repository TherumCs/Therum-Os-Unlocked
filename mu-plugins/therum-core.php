<?php
/**
 * Plugin Name: Therum OS Core
 * Description: Core functionality for Therum OS. Do not remove.
 * Version: 1.9.1
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'THERUM_OS_VERSION', '1.9.35' );
define( 'THERUM_OS_FORK',    'WordPress 6.7' );

// ── Therum lib autoloader (Phase 5 — Composer-first packaging) ───────────────
// Namespaced Therum\* classes live under _therum/src/ with PSR-4 mapping.
// Composer's autoloader takes over if you run `composer install` inside
// _therum/; otherwise the built-in SPL loader below covers Therum's own
// classes without external tooling. New Therum kernel work (queue, scoped
// tokens, settings registry, event bus) lands under Therum\* here; existing
// global-function mu-plugins keep working unchanged.
define( 'THERUM_LIB_DIR', __DIR__ . '/_therum' );

// 1) Composer autoload — only loaded when vendor/ exists (after composer install).
$_therum_vendor_autoload = THERUM_LIB_DIR . '/vendor/autoload.php';
if ( file_exists( $_therum_vendor_autoload ) ) {
	require_once $_therum_vendor_autoload;
}
unset( $_therum_vendor_autoload );

// 2) PSR-4 fallback for Therum\* — always registered. Harmless when Composer
//    also registers the same mapping (require_once dedupes the include).
spl_autoload_register( function( string $class ): void {
	if ( strncmp( $class, 'Therum\\', 7 ) !== 0 ) return;
	$relative = substr( $class, 7 );
	$path     = THERUM_LIB_DIR . '/src/' . str_replace( '\\', '/', $relative ) . '.php';
	if ( is_file( $path ) ) require_once $path;
} );

// ── Identity ──────────────────────────────────────────────────────────────────
// $a is the already-composed admin title ("Network Admin: Foo" or "Foo")
// and $t is the title part. Build off $a so the Network Admin prefix and any
// other plugins' contributions survive instead of being silently dropped.
add_filter( 'admin_title',    fn( $a, $t ) => ( $a !== '' ? $a : $t ) . ' — Therum OS', 10, 2 );
add_filter( 'login_headertext', fn() => 'Therum OS' );
add_filter( 'login_headerurl',  fn() => home_url() );
add_filter( 'admin_footer_text', fn() => 'Running <strong>Therum OS ' . THERUM_OS_VERSION . '</strong>' );
add_filter( 'update_footer',     fn() => 'Forked from ' . THERUM_OS_FORK, 11 );
add_filter( 'the_generator',     '__return_empty_string' );

// ── Sans WordPress — strip remaining engine fingerprints ─────────────────────
// Therum positions as "Therum + Bricks on a hardened substrate" — the substrate
// is a Therum OS implementation detail, not a brand surface. These filters
// scrub the few remaining places the underlying engine still leaks through.

// 1. /wp-admin/about.php → redirect to Therum dashboard. Same for credits.php
//    and freedoms.php (the other WP-branded core admin pages).
add_action( 'admin_init', function() {
	$leaky = [ 'about.php', 'credits.php', 'freedoms.php', 'privacy.php' ];
	$self  = isset( $_SERVER['PHP_SELF'] ) ? basename( (string) $_SERVER['PHP_SELF'] ) : '';
	if ( in_array( $self, $leaky, true ) && function_exists( 'wp_safe_redirect' ) ) {
		wp_safe_redirect( admin_url( 'admin.php?page=therum' ) );
		exit;
	}
} );

// 2. Strip X-Powered-By when PHP sets it (php.ini expose_php = On hosts)
add_action( 'send_headers', function() {
	if ( ! headers_sent() ) @header_remove( 'X-Powered-By' );
} );

// 3. REST root response — override `name` so /wp-json/ doesn't disclose the
//    engine. The default WP root returns the site name + description; we
//    rewrite it to use the Therum identity.
add_filter( 'rest_index', function( $response ) {
	if ( ! ( $response instanceof WP_REST_Response ) ) return $response;
	$data = $response->get_data();
	if ( is_array( $data ) ) {
		$data['name']        = get_bloginfo( 'name' );
		$data['description'] = get_bloginfo( 'description' );
		$data['engine']      = 'Therum OS ' . ( defined( 'THERUM_OS_VERSION' ) ? THERUM_OS_VERSION : '' );
		// Remove gmt_offset / timezone_string + namespaces aren't fingerprints
		// but the default doesn't disclose the WP version explicitly either.
		$response->set_data( $data );
	}
	return $response;
}, 20 );

// 4. RSS feeds — strip the <generator> tag from feed XML.
remove_action( 'rss2_head',   'the_generator' );
remove_action( 'atom_head',   'the_generator' );
remove_action( 'commentsrss2_head', 'the_generator' );
remove_action( 'rdf_header',  'the_generator' );
remove_action( 'opml_head',   'the_generator' );

// ── Security ──────────────────────────────────────────────────────────────────
add_filter( 'xmlrpc_enabled', '__return_false' );
remove_action( 'wp_head', 'rsd_link' );
remove_action( 'wp_head', 'wlwmanifest_link' );
remove_action( 'wp_head', 'wp_generator' );

add_action( 'send_headers', function() {
	if ( headers_sent() ) return;
	header( 'X-Content-Type-Options: nosniff' );
	header( 'X-Frame-Options: SAMEORIGIN' );
	header( 'X-XSS-Protection: 1; mode=block' );
	header( 'Referrer-Policy: strict-origin-when-cross-origin' );
});

// Block user enumeration
add_action( 'init', function() {
	if ( ! is_admin() && isset( $_GET['author'] ) && ! is_user_logged_in() ) {
		wp_die( 'Not allowed.', 'Therum OS', [ 'response' => 403 ] );
	}
});

/**
 * Best-effort client IP for rate-limiting keys.
 *
 * Returns REMOTE_ADDR (the real connecting socket address) by default. Only when
 * the request actually arrives from a trusted reverse proxy — REMOTE_ADDR is in
 * the THERUM_TRUSTED_PROXY allow-list (comma-separated IPs) — do we honor the
 * left-most X-Forwarded-For entry. Trusting that header unconditionally would
 * let an attacker spoof a fresh IP per request and sail past the lockout, so we
 * don't. Falls back to REMOTE_ADDR if the forwarded value isn't a valid IP.
 */
function therum_client_ip(): string {
	$remote = $_SERVER['REMOTE_ADDR'] ?? '';
	if ( defined( 'THERUM_TRUSTED_PROXY' ) && THERUM_TRUSTED_PROXY ) {
		$trusted = array_map( 'trim', explode( ',', (string) THERUM_TRUSTED_PROXY ) );
		if ( in_array( $remote, $trusted, true ) && ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			$fwd = trim( explode( ',', $_SERVER['HTTP_X_FORWARDED_FOR'] )[0] );
			if ( filter_var( $fwd, FILTER_VALIDATE_IP ) ) {
				return $fwd;
			}
		}
	}
	return $remote;
}

// Login rate limiting
add_action( 'wp_login_failed', function( $username ) {
	$key      = 'therum_fail_' . md5( therum_client_ip() );
	$attempts = (int) get_transient( $key );
	set_transient( $key, $attempts + 1, 15 * MINUTE_IN_SECONDS );
});

// Therum OS admin login is invite-only by design.
// Operators add new users via /wp-admin/users.php — never via a public form.
// This forces users_can_register off regardless of what the WP General setting says.
// Frontend account systems (WooCommerce customers, subscribers, etc.) are unaffected —
// they use their own registration flows on /my-account or custom endpoints.
add_filter( 'option_users_can_register',     '__return_zero', 999 );
add_filter( 'pre_option_users_can_register', '__return_zero', 999 );

add_filter( 'authenticate', function( $user, $username, $password ) {
	$key      = 'therum_fail_' . md5( therum_client_ip() );
	$attempts = (int) get_transient( $key );
	if ( $attempts >= 5 ) {
		return new WP_Error( 'too_many_retries', 'Too many failed attempts. Try again in 15 minutes.' );
	}
	return $user;
}, 30, 3 );

add_action( 'wp_login', function() {
	delete_transient( 'therum_fail_' . md5( therum_client_ip() ) );
});

// Disable file editing if not already set
if ( ! defined( 'DISALLOW_FILE_EDIT' ) ) {
	define( 'DISALLOW_FILE_EDIT', true );
}

// ─── DESKTOP MODE INTEGRATION ────────────────────────────────────────────────
// Optional companion plugin: https://wordpress.org/plugins/desktop-mode/ by
// Automattic. Renders /wp-admin as a windowed desktop OS. When the user has
// it active for themselves, Therum's shell yields so the two UIs don't fight
// — DM's per-user toggle is the single source of truth.
//
// Helper: detect "is DM running for this user right now?"
if ( ! function_exists( 'therum_desktop_mode_active_for_user' ) ) {
	function therum_desktop_mode_active_for_user(): bool {
		// Prefer the plugin's own canonical helper — it checks the per-user flag
		// AND applies the `desktop_mode_mode_enabled` filter, matching exactly
		// what DM's own render gates use. (If this function exists, the plugin
		// is loaded.)
		if ( function_exists( 'desktop_mode_is_enabled' ) ) {
			return (bool) desktop_mode_is_enabled();
		}
		// Fallback for early-hook contexts where the helper isn't defined yet:
		// read DM's real opt-in meta directly. NOTE: the flag is `desktop_mode_mode`
		// (a previous guess at `desktop_mode_enabled` et al. never matched, which
		// is why Therum's shell failed to yield and double-rendered over DM).
		$uid = get_current_user_id();
		if ( ! $uid ) return false;
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		if ( ! is_plugin_active( 'desktop-mode/desktop-mode.php' ) ) return false;
		return '1' === (string) get_user_meta( $uid, 'desktop_mode_mode', true );
	}
}

// Yield Therum's shell on every admin page when DM is active for the user.
// Hooks into the existing escape hatch in therum-admin.php's therum_is_frame().
add_filter( 'therum_admin_shell_bypass', function( $bypass ) {
	if ( $bypass ) return $bypass;
	return therum_desktop_mode_active_for_user();
}, 10, 1 );

// ─── SELF-HEAL: rotate placeholder auth salts ────────────────────────────────
// Older Therum installs (and any install that skipped the wizard) shipped
// wp-config.php with placeholder salts like 'th-k1-put-something-long-...'.
// These are weak HMAC keys for auth/nonce cookies — known to cause both
// security exposure AND sporadic auth-cookie validation failures (the user-
// visible symptom is random logouts on refresh).
//
// On any admin page load by an admin user, if ALL 8 salts still match the
// placeholder pattern AND wp-config.php is writable, we rotate them once,
// snapshot the old file to wp-config.php.therum-salts.bak, set a sentinel
// option so we never re-rotate, then redirect the user to login (their
// cookie was signed with the old salt and is now invalid — one-time
// re-login, then stable forever).
add_action( 'admin_init', function() {
	if ( wp_doing_ajax() ) return;
	if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) return;
	if ( defined( 'DOING_CRON' ) && DOING_CRON ) return;
	if ( defined( 'WP_CLI' ) && WP_CLI ) return;
	if ( ! current_user_can( 'manage_options' ) ) return;
	if ( get_option( '_therum_salts_rotated' ) ) return;

	$keys = [ 'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY',
	          'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT' ];

	// Detection: ALL 8 must look like a placeholder for us to act. We refuse
	// to touch wp-config.php if even one salt has been customised — that
	// signals the operator chose their own keys and we should respect them.
	$all_placeholder = true;
	foreach ( $keys as $k ) {
		if ( ! defined( $k ) ) { $all_placeholder = false; break; }
		$v = constant( $k );
		// Match the exact "put-something-long-and-random-here" pattern we shipped.
		$is_placeholder = is_string( $v )
			&& strlen( $v ) < 60
			&& strpos( $v, 'put-something' ) !== false;
		if ( ! $is_placeholder ) { $all_placeholder = false; break; }
	}

	if ( ! $all_placeholder ) {
		// Real salts in place — mark done so we stop checking.
		update_option( '_therum_salts_rotated', 1, true );
		return;
	}

	$path = ABSPATH . 'wp-config.php';
	if ( ! is_readable( $path ) || ! is_writable( $path ) ) return; // retry next admin load

	$src = (string) @file_get_contents( $path );
	if ( strlen( $src ) < 200 ) return;

	// Try the WordPress.org salt service first; fall back to local random_bytes.
	// Use wp_remote_get rather than file_get_contents so TLS verification and
	// the HTTP API's timeouts/proxy settings apply consistently (a raw stream
	// can silently skip cert validation on hosts with an incomplete CA bundle).
	$new_block = '';
	$resp = wp_remote_get( 'https://api.wordpress.org/secret-key/1.1/salt/', [
		'timeout'   => 5,
		'sslverify' => true,
	] );
	$body = is_wp_error( $resp ) ? '' : (string) wp_remote_retrieve_body( $resp );
	if ( $body !== '' && strlen( $body ) > 400 && strpos( $body, 'AUTH_KEY' ) !== false ) {
		$new_block = rtrim( $body );
	} else {
		$lines = [];
		foreach ( $keys as $k ) {
			$lines[] = "define( '" . $k . "', '" . bin2hex( random_bytes( 32 ) ) . "' );";
		}
		$new_block = implode( "\n", $lines );
	}

	// Replace the existing AUTH_KEY → NONCE_SALT define block in one regex.
	$out = preg_replace(
		"/define\(\s*'AUTH_KEY'[^)]+\);.*?define\(\s*'NONCE_SALT'[^)]+\);/s",
		$new_block,
		$src,
		1
	);
	if ( ! is_string( $out ) || strlen( $out ) < strlen( $src ) - 1000 || strpos( $out, "define( 'NONCE_SALT'" ) === false ) {
		// Patch didn't land cleanly — bail without writing. Operator can rotate manually.
		return;
	}

	// Snapshot the current file before writing.
	@copy( $path, $path . '.therum-salts.bak' );

	// Atomic write: temp file, then rename.
	$tmp = $path . '.therum-salts.tmp';
	if ( @file_put_contents( $tmp, $out ) === false ) return;
	if ( ! @rename( $tmp, $path ) ) { @unlink( $tmp ); return; }

	// Mark rotated permanently. Even if a future install accidentally
	// re-introduces placeholders, this sentinel prevents double-rotation
	// on a healthy install.
	update_option( '_therum_salts_rotated', 1, true );
	update_option( '_therum_salts_rotated_at', time(), true );

	// The current user's auth cookie was signed with the OLD salts and will
	// not validate on the next request. Log them out cleanly here, then
	// redirect to the login screen with a notice so the re-auth isn't a
	// silent surprise.
	wp_logout();
	$login = add_query_arg( 'therum_salts_rotated', '1', wp_login_url( admin_url( 'admin.php?page=therum' ) ) );
	wp_safe_redirect( $login );
	exit;
}, 3 );

// One-time notice on the login screen explaining why the user got bounced.
add_action( 'login_message', function( $message ) {
	if ( empty( $_GET['therum_salts_rotated'] ) ) return $message;
	$notice = '<p class="message" style="border-left-color:#10b981;">Therum security update: auth keys were rotated. Sign in once to continue — you won\'t see this again.</p>';
	return $notice . $message;
} );

// ── Gutenberg off ─────────────────────────────────────────────────────────────
add_filter( 'use_block_editor_for_post',      '__return_false', 100 );
add_filter( 'use_block_editor_for_post_type', '__return_false', 100 );
add_filter( 'use_widgets_block_editor',        '__return_false' );

// Remove block styles from front end
add_action( 'wp_enqueue_scripts', function() {
	wp_dequeue_style( 'wp-block-library' );
	wp_dequeue_style( 'wp-block-library-theme' );
	wp_dequeue_style( 'global-styles' );
}, 100 );

// Block function polyfills for 6.3+ plugin compatibility
if ( ! function_exists( 'register_block_type' ) ) {
	function register_block_type( $name, $args = [] ) { return false; }
}
if ( ! function_exists( 'has_blocks' ) ) {
	function has_blocks( $post = null ) { return false; }
}
if ( ! function_exists( 'has_block' ) ) {
	function has_block( $block_name, $post = null ) { return false; }
}
if ( ! function_exists( 'parse_blocks' ) ) {
	function parse_blocks( $content ) { return []; }
}
if ( ! function_exists( 'render_block' ) ) {
	function render_block( $block ) { return ''; }
}
if ( ! function_exists( 'get_block_wrapper_attributes' ) ) {
	function get_block_wrapper_attributes( $extra = [] ) { return ''; }
}

// ── Performance ───────────────────────────────────────────────────────────────
add_action( 'init', function() {
	remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
	remove_action( 'admin_print_scripts', 'print_emoji_detection_script' );
	remove_action( 'wp_print_styles', 'print_emoji_styles' );
	remove_action( 'admin_print_styles', 'print_emoji_styles' );
	remove_action( 'wp_head', 'wp_oembed_add_discovery_links' );
	remove_action( 'wp_head', 'wp_oembed_add_host_js' );
	remove_action( 'wp_head', 'wp_shortlink_wp_head' );
	remove_action( 'wp_head', 'rest_output_link_wp_head' );
	remove_action( 'wp_head', 'adjacent_posts_rel_link_wp_head' );
});

// ── Dashboard widget ──────────────────────────────────────────────────────────
add_action( 'wp_dashboard_setup', function() {
	remove_meta_box( 'dashboard_primary', 'dashboard', 'side' );
	wp_add_dashboard_widget( 'therum_status', 'Therum OS', function() {
		echo '<p><strong>Therum OS ' . THERUM_OS_VERSION . '</strong> — Forked from ' . THERUM_OS_FORK . '</p>';
		echo '<ul style="margin:8px 0 0;padding-left:16px;font-size:13px;">';
		echo ( function_exists('therum_is_sqlite') && therum_is_sqlite() )
			? '<li>✓ SQLite (no MySQL)</li>'
			: '<li>✓ MySQL <span style="opacity:.6">(SQLite drop-in not active)</span></li>';
		echo '<li>✓ Classic editor</li>';
		echo '<li>✓ Login rate limiting</li>';
		echo '<li>✓ XML-RPC disabled</li>';
		echo '<li>✓ Security headers</li>';
		echo '</ul>';
	});
});

// ── Page Builder Compatibility ────────────────────────────────────────────────
// Polyfill missing script handles ONLY when the Bricks theme isn't active.
//
// Why this gate exists: this hook used to run at priority 1 (before Bricks
// theme registers its scripts at priority 10) and would `wp_register_script`
// the `bricks-scripts` handle with a `false` source. Since `wp_enqueue_script`
// won't overwrite an already-registered handle's source, Bricks' subsequent
// enqueue would leave the handle in the queue but with NO src — breaking
// `bricks-scripts.min.js` from ever rendering on the frontend, which in turn
// breaks `bricksIsFrontend` (and every NextBricks element that gates on it).
//
// The polyfill is only useful when Bricks ISN'T active (e.g. someone's
// running plugins that depend on these handles without the parent theme).
// When Bricks IS active, it registers everything itself.
$therum_bricks_active = function_exists( 'wp_get_theme' )
	&& ( get_template() === 'bricks' || wp_get_theme()->get_template() === 'bricks' );

if ( ! $therum_bricks_active ) {
	add_action( 'wp_enqueue_scripts', function() {
		$handles = [
			'bricks-scripts', 'bricks-frontend', 'bricks-child',
			'bricks-isotope', 'bricks-splide', 'bricks-photoswipe',
			'bricks-mmenu', 'bricks-easings', 'bricks-imagesloaded', 'bricks-slick',
			'bricksforge-panel', 'bricksforge-scripts',
			'bricks-extras', 'bricks-extras-scripts',
			'maxbricks-scripts',
			'next-bricks', 'brixies-scripts',
		];
		foreach ( $handles as $handle ) {
			if ( ! wp_script_is( $handle, 'registered' ) ) {
				wp_register_script( $handle, false, [], false, true );
			}
		}
	}, 100 ); // 100, not 1 — well after any theme has had a chance to register

	add_action( 'admin_enqueue_scripts', function() {
		$handles = [
			'bricks-scripts', 'bricks-frontend',
			'bricksforge-panel', 'bricksforge-scripts',
			'bricks-extras', 'maxbricks-scripts',
			'next-bricks', 'brixies-scripts',
		];
		foreach ( $handles as $handle ) {
			if ( ! wp_script_is( $handle, 'registered' ) ) {
				wp_register_script( $handle, false, [], false, true );
			}
		}
	}, 100 );
}

// WooCommerce compatibility — ensure block-based Woo features
// don't fatal when Gutenberg is stripped
add_action( 'init', function() {
	// Prevent Woo from forcing block checkout/cart if blocks unavailable
	if ( ! function_exists( 'register_block_type' ) ) {
		add_filter( 'woocommerce_blocks_loaded', '__return_false' );
	}

	// Suppress WooCommerce admin notice about missing blocks
	add_filter( 'woocommerce_show_admin_notice', function( $show, $notice ) {
		$block_notices = [ 'woocommerce_blocks_phase_2', 'update_notice' ];
		if ( in_array( $notice, $block_notices, true ) ) return false;
		return $show;
	}, 10, 2 );
} );

// Prevent "called incorrectly" notices from page builders in debug mode
add_filter( 'doing_it_wrong_trigger_error', function( $trigger, $function_name ) {
	$builder_functions = [
		'register_block_type',
		'unregister_block_type',
		'wp_enqueue_script',
	];
	foreach ( $builder_functions as $fn ) {
		if ( strpos( $function_name, 'bricks' ) !== false ) return false;
		if ( strpos( $function_name, 'woocommerce_blocks' ) !== false ) return false;
	}
	return $trigger;
}, 10, 2 );

// Hide admin bar when page loaded in Therum OS preview iframe
add_action( 'init', function() {
	if ( isset( $_GET['th_preview'] ) ) {
		add_filter( 'show_admin_bar', '__return_false' );
	}
});

// Allow Therum OS admin pages to load inside our iframe
// Remove X-Frame-Options aggressively so all admin pages work in iframe
add_action( 'admin_init', function() {
	if ( isset( $_GET['th_frame'] ) ) {
		// Remove WP's built-in frame options header action
		remove_action( 'admin_init', 'send_frame_options_header' );
		remove_action( 'login_init', 'send_frame_options_header' );
	}
}, 0 );

add_action( 'send_headers', function() {
	if ( isset( $_GET['th_frame'] ) ) {
		header_remove( 'X-Frame-Options' );
		header( 'X-Frame-Options: SAMEORIGIN' );
	}
}, 99 );

// Also catch it at template_redirect level
add_action( 'admin_head', function() {
	if ( isset( $_GET['th_frame'] ) ) {
		header_remove( 'X-Frame-Options' );
	}
}, 0 );

// ── Featured Media (Dual Ratio) ───────────────────────────────────────────────
// Dual featured media slots for case studies: wide (16:9/1920×1080) for hero/list/reveal,
// tight (4:5) for grid view. Each slot accepts image or video. Single helper,
// two dynamic data tags, falls back gracefully if grid slot is empty.

// Meta box on case_study post type
add_action( 'add_meta_boxes', function() {
	add_meta_box(
		'therum_featured_grid',
		'Featured Media (Grid)',
		'therum_featured_grid_meta_box_callback',
		'case_study',
		'normal',
		'high'
	);
} );

function therum_featured_grid_meta_box_callback( $post ) {
	$att_id = get_post_meta( $post->ID, '_therum_featured_grid', true );
	wp_nonce_field( 'therum_featured_grid_nonce', 'therum_featured_grid_nonce' );
	echo '<input type="hidden" name="therum_featured_grid_id" value="' . esc_attr( $att_id ) . '" id="therum_featured_grid_id">';
	echo '<button class="button" id="therum_featured_grid_button">Select Media</button>';
	if ( $att_id ) {
		echo '<div style="margin-top: 10px;">';
		echo wp_get_attachment_image( $att_id, array( 192, 240 ) );
		echo '</div>';
	}
	echo '<script>
	document.getElementById("therum_featured_grid_button").addEventListener("click", function(e) {
		e.preventDefault();
		var frame = wp.media({
			title: "Select Media (Grid)",
			button: { text: "Use this media" },
			multiple: false,
			library: { type: [ "image", "video" ] }
		});
		frame.on("select", function() {
			var att = frame.state().get("selection").first().toJSON();
			document.getElementById("therum_featured_grid_id").value = att.id;
			location.reload();
		});
		frame.open();
	});
	</script>';
}

// Save featured grid media meta
add_action( 'save_post', function( $post_id ) {
	if ( ! isset( $_POST['therum_featured_grid_nonce'] ) || ! wp_verify_nonce( $_POST['therum_featured_grid_nonce'], 'therum_featured_grid_nonce' ) ) {
		return;
	}
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}
	
	$att_id = isset( $_POST['therum_featured_grid_id'] ) ? intval( $_POST['therum_featured_grid_id'] ) : 0;
	if ( $att_id ) {
		update_post_meta( $post_id, '_therum_featured_grid', $att_id );
	} else {
		delete_post_meta( $post_id, '_therum_featured_grid' );
	}
} );

// Helper function: therum_featured_media( $post_id, $view = 'grid' )
// Returns HTML: <img> or <video> depending on attachment type
// Falls back to featured image if grid slot empty
function therum_featured_media( $post_id, $view = 'grid' ) {
	$att_id = ( $view === 'grid' )
		? get_post_meta( $post_id, '_therum_featured_grid', true )
		: 0;
	
	if ( ! $att_id ) {
		$att_id = get_post_thumbnail_id( $post_id );
	}
	
	if ( ! $att_id ) {
		return '';
	}
	
	$mime = get_post_mime_type( $att_id );
	$url  = wp_get_attachment_url( $att_id );
	
	// Video: return autoplay muted loop element
	if ( strpos( $mime, 'video' ) === 0 ) {
		return sprintf(
			'<video class="therum-home__tile-video" src="%s" autoplay muted loop playsinline preload="metadata"></video>',
			esc_url( $url )
		);
	}
	
	// Image: return <img> with lazy loading, alt text
	$alt = get_post_meta( $att_id, '_wp_attachment_image_alt', true );
	return wp_get_attachment_image( $att_id, 'large', false, [
		'class'   => 'therum-home__tile-img',
		'alt'     => $alt ?: get_the_title( $post_id ),
		'loading' => 'lazy',
	] );
}

// Register Bricks dynamic data tags
add_filter( 'bricks/dynamic_data/register_tags', function( $tags ) {
	$tags[] = [
		'name'  => '{therum_featured_media:grid}',
		'label' => 'Therum Featured (Grid)',
		'group' => 'Therum OS',
	];
	$tags[] = [
		'name'  => '{therum_featured_media:wide}',
		'label' => 'Therum Featured (Wide)',
		'group' => 'Therum OS',
	];
	return $tags;
} );

// Resolve Bricks dynamic data tags
add_filter( 'bricks/dynamic_data/render_tag', function( $value, $tag, $post, $context ) {
	if ( strpos( $tag, '{therum_featured_media:' ) !== 0 ) {
		return $value;
	}
	$view = ( strpos( $tag, ':grid}' ) !== false ) ? 'grid' : 'wide';
	$post_id = $post->ID ?? get_the_ID();
	return therum_featured_media( $post_id, $view );
}, 10, 4 );


// ═══════════════════════════════════════════════════════════════════════
// DEV NO-CACHE — merged from therum-dev-nocache.php (auto-gates on .local hosts)
// ═══════════════════════════════════════════════════════════════════════

// Activation gate: only `.local` development hosts.
$host = $_SERVER['HTTP_HOST'] ?? '';
if ( ! str_ends_with( $host, '.local' ) ) return;

// 1) Front-end HTML never caches.
add_filter( 'wp_headers', function ( array $headers ) : array {
    if ( is_admin() ) return $headers;
    $headers['Cache-Control'] = 'no-store, no-cache, must-revalidate, max-age=0';
    $headers['Pragma']        = 'no-cache';
    $headers['Expires']       = '0';
    return $headers;
}, 999 );

// 2) Rewrite every /wp-content/uploads/ asset URL in the rendered HTML to
//    carry a `?v={filemtime}` cache buster so the browser refetches the
//    moment the underlying file changes.
add_action( 'template_redirect', function () : void {
    if ( is_admin() ) return;
    if ( wp_doing_ajax() ) return;
    if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) return;

    // 2a) Flush Bricks CSS/element transients so the latest rebuild data shows
    //     immediately. Cost: one DELETE query per load (~0.3ms on local).
    global $wpdb;
    $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_bricks_%' OR option_name LIKE '_transient_timeout_bricks_%'" );

    // 2b) Rewrite /wp-content/uploads/ asset URLs with filemtime bust.
    ob_start( function ( string $html ) : string {
        return preg_replace_callback(
            '#(src|href)="([^"]*?/wp-content/uploads/[^"?]+\.(svg|png|jpg|jpeg|webp|gif|css|js|html|mp4|webm))(\?[^"]*)?"#i',
            function ( array $m ) : string {
                $url      = $m[2];
                $rel_path = parse_url( $url, PHP_URL_PATH ) ?: '';
                $abs_path = ABSPATH . ltrim( $rel_path, '/' );
                $v        = is_readable( $abs_path ) ? filemtime( $abs_path ) : time();
                return sprintf( '%s="%s?v=%d"', $m[1], $url, $v );
            },
            $html
        );
    } );
} );


// ════════════════════════════════════════════════════════════════════════════
//  THERUM QUEUE (Phase 5.2 — persistent job queue)
// ════════════════════════════════════════════════════════════════════════════
// Replaces WP-cron as the actual workload runner. Backed by wp_therum_jobs;
// workers run via `wp therum queue work` (under systemd timer on the VPS;
// manually in dev). WP-cron only handles periodic maintenance (stale-lock
// sweep, completed-job pruning) — never dispatches real work.
//
// Public API: Therum\Queue::push() / register_handler() / work() / stats().
// CLI: wp therum queue work | status | retry <id> | prune.
//
// Kill switch:
//   define( 'THERUM_QUEUE_DISABLE', true ) in wp-config.php

if ( ! ( defined( 'THERUM_QUEUE_DISABLE' ) && THERUM_QUEUE_DISABLE ) ) {

	// Idempotent table install on admin load.
	add_action( 'admin_init', function() {
		if ( ! class_exists( \Therum\Queue::class ) ) return;
		\Therum\Queue::install();
	}, 5 );

	// Register CLI commands when WP_CLI is loaded.
	add_action( 'cli_init', function() {
		if ( ! class_exists( \Therum\Queue\CliCommands::class ) ) return;
		\Therum\Queue\CliCommands::register();
	} );

	// Periodic maintenance — stale-lock sweep + completed-job pruning.
	// 5-minute cadence is enough for stale-lock recovery without DB hammering.
	add_filter( 'cron_schedules', function( array $schedules ): array {
		if ( ! isset( $schedules['therum_5min'] ) ) {
			$schedules['therum_5min'] = [
				'interval' => 5 * MINUTE_IN_SECONDS,
				'display'  => 'Every 5 minutes (Therum)',
			];
		}
		return $schedules;
	} );

	add_action( 'init', function() {
		if ( ! wp_next_scheduled( 'therum_queue_maintenance' ) ) {
			wp_schedule_event( time() + 60, 'therum_5min', 'therum_queue_maintenance' );
		}
	} );

	add_action( 'therum_queue_maintenance', function() {
		if ( ! class_exists( \Therum\Queue::class ) ) return;
		\Therum\Queue::maintenance();
	} );
}
