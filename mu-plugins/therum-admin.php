<?php
/**
 * Plugin Name: Therum OS — Admin
 * Description: Admin shell (chrome + dock + settings + install wizard) merged
 *              into one mu-plugin. Contains the sidebar/topbar/dashboard, the
 *              list-page engine, the bottom-dock toolbar, the settings registry
 *              + UI, and the first-run install wizard.
 *              Merged from therum-admin-ui, therum-admin-dock, therum-settings,
 *              therum-install-wizard (Phase 3 Merge #4, 2026-05-29).
 * Version: 1.9.0
 *
 * Section map:
 *   1. ADMIN UI — chrome (sidebar + topbar + dashboard) + list-page engine
 *   2. ADMIN DOCK — bottom toolbar replacement for wp-admin bar
 *   3. SETTINGS — registry + 14 sections + UI
 *   4. INSTALL WIZARD — one-shot first-run setup (self-disabling)
 *
 * Kill switches preserved per sub-module — search for the existing constants
 * in each section (THERUM_*_DISABLE patterns).
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Safe-require a child page class file. Logs + soft-fails if missing instead
 * of fataling the whole admin — protects against partial extracts during an
 * in-place update, a rsync glitch, or an .htaccess-blocked path.
 */
if ( ! function_exists( 'therum_admin_require_page' ) ) {
	function therum_admin_require_page( string $relative ): void {
		$abs = __DIR__ . '/_therum/admin/pages/' . ltrim( $relative, '/' );
		if ( is_file( $abs ) ) {
			require_once $abs;
			return;
		}
		// Don't fatal — log and let the rest of admin still load.
		error_log( '[therum-admin] missing page class: ' . $abs );
	}
}

/**
 * Keep Therum admin chrome clean from PHP deprecation / notice spam.
 *
 * PHP 8.1+ emits a flood of "Passing null to parameter ..." deprecation
 * notices triggered by core helpers like wp_normalize_path() and
 * wp_is_stream() that older plugins call with nulls. When WP_DEBUG_DISPLAY
 * is on, those notices print at the top of the page and break the admin
 * shell layout (they appear above the topbar, shoving content down).
 *
 * We turn off display_errors for admin requests only — the log path
 * (debug.log via WP_DEBUG_LOG) is preserved so nothing goes silent for
 * developers, but the visible chrome stays clean. Real fatals still
 * surface because they bypass display_errors when output starts.
 *
 * Kill switch: define( 'THERUM_KEEP_ADMIN_NOTICES', true ) in wp-config.php
 * to restore vanilla WP behavior (notices print on screen).
 */
if ( is_admin() && ! ( defined( 'THERUM_KEEP_ADMIN_NOTICES' ) && THERUM_KEEP_ADMIN_NOTICES ) ) {
	@ini_set( 'display_errors', '0' );
}

/**
 * True database engine in effect right now. SQLite is only active when the
 * sqlite-database-integration drop-in (wp-content/db.php) is in place AND its
 * driver class is loaded. Anything else is the MySQL/MariaDB that WordPress
 * (or the host, e.g. Local/most VPS panels) provisions by default.
 *
 * Returns one of: 'SQLite', 'MySQL'. Use therum_is_sqlite() for a boolean.
 */
if ( ! function_exists( 'therum_is_sqlite' ) ) {
	function therum_is_sqlite(): bool {
		// The drop-in defines this constant and loads the SQLite PDO driver.
		if ( defined( 'DATABASE_ENGINE' ) && DATABASE_ENGINE === 'sqlite' ) return true;
		if ( class_exists( 'WP_SQLite_DB' ) || class_exists( 'WP_SQLite_Translator' ) ) return true;
		// Drop-in file present is necessary but not sufficient; confirm the
		// active $wpdb is actually a SQLite implementation, not MySQL.
		global $wpdb;
		if ( $wpdb && stripos( get_class( $wpdb ), 'sqlite' ) !== false ) return true;
		return false;
	}
}
if ( ! function_exists( 'therum_db_engine' ) ) {
	function therum_db_engine(): string {
		return therum_is_sqlite() ? 'SQLite' : 'MySQL';
	}
}


// ════════════════════════════════════════════════════════════════════════════
//  1. ADMIN UI — from therum-admin-ui.php
// ════════════════════════════════════════════════════════════════════════════

// ─────────────────────────────────────────────────────────────────────────────
//  CONSTANTS
// ─────────────────────────────────────────────────────────────────────────────
if ( ! defined( 'THERUM_UI_VERSION' ) ) define( 'THERUM_UI_VERSION', '1.9.1' );

// ─────────────────────────────────────────────────────────────────────────────
//  ICONS — therum_i('name') returns inline SVG. Used by everything.
// ─────────────────────────────────────────────────────────────────────────────
function therum_i( string $n ): string {
	static $c = null;
	if ( ! $c ) $c = [
		'home'      => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>',
		'store'     => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 0 1-8 0"/></svg>',
		'content'   => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>',
		'manage'    => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/></svg>',
		'utils'     => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.07 4.93l-1.41 1.41M4.93 4.93l1.41 1.41M4.93 19.07l1.41-1.41M19.07 19.07l-1.41-1.41M12 2v2M12 20v2M2 12h2M20 12h2"/></svg>',
		'admin'     => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>',
		'pages'     => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>',
		'posts'     => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>',
		'media'     => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>',
		'cats'      => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></svg>',
		'tags'      => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/></svg>',
		'comments'  => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>',
		'design'    => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>',
		'themes'    => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M2 3h20v14H2z"/><path d="M8 21h8"/><path d="M12 17v4"/></svg>',
		'customizer'=> '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M12 1v6m0 6v6"/><path d="M4.22 4.22l4.24 4.24m7.08 7.08l4.24 4.24"/><path d="M1 12h6m6 0h6"/><path d="M4.22 19.78l4.24-4.24m7.08-7.08l4.24-4.24"/></svg>',
		'menus'     => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="15" y2="12"/><line x1="3" y1="18" x2="18" y2="18"/></svg>',
		'widgets'   => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/></svg>',
		'templates' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="6" rx="1"/><rect x="3" y="13" width="11" height="8" rx="1"/><rect x="17" y="13" width="4" height="8" rx="1"/></svg>',
		'import'    => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>',
		'health'    => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>',
		'db'        => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M21 12c0 1.66-4 3-9 3s-9-1.34-9-3"/><path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"/></svg>',
		'plugins'   => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M9 2v6"/><path d="M15 2v6"/><path d="M12 17v5"/><path d="M5 8h14"/><path d="M6 11V8h12v3a6 6 0 0 1-12 0Z"/></svg>',
		'users'     => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>',
		'settings'  => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.07 4.93l-1.41 1.41M4.93 4.93l1.41 1.41M4.93 19.07l1.41-1.41M19.07 19.07l-1.41-1.41M12 2v2M12 20v2M2 12h2M20 12h2"/></svg>',
		'therum'    => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>',
		'products'  => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/></svg>',
		'orders'    => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="2"/></svg>',
		'customers' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg>',
		'coupons'   => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/></svg>',
		'analytics' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>',
		'shipping'  => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="3" width="15" height="13"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>',
		'payments'  => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>',
		'back'      => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>',
		'chevron'   => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>',
		'search'    => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>',
		'sun'       => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/></svg>',
		'moon'      => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>',
		'external'  => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>',
		'profile'   => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>',
		'logout'    => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>',
		'plus'      => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>',
		'eye'       => '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>',
		'edit2'     => '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>',
		'dots'      => '<svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><circle cx="5" cy="12" r="2"/><circle cx="12" cy="12" r="2"/><circle cx="19" cy="12" r="2"/></svg>',
		'globe'     => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M2 12h20"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>',
		'palette'   => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><circle cx="13.5" cy="6.5" r=".5" fill="currentColor"/><circle cx="17.5" cy="10.5" r=".5" fill="currentColor"/><circle cx="8.5" cy="7.5" r=".5" fill="currentColor"/><circle cx="6.5" cy="12.5" r=".5" fill="currentColor"/><path d="M12 2C6.5 2 2 6.5 2 12s4.5 10 10 10c.926 0 1.648-.746 1.648-1.688 0-.437-.18-.835-.437-1.125-.29-.289-.438-.652-.438-1.125a1.64 1.64 0 0 1 1.668-1.668h1.996c3.051 0 5.555-2.503 5.555-5.554C21.965 6.012 17.461 2 12 2z"/></svg>',
		'feather'   => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M20.24 12.24a6 6 0 0 0-8.49-8.49L5 10.5V19h8.5z"/><line x1="16" y1="8" x2="2" y2="22"/><line x1="17.5" y1="15" x2="9" y2="15"/></svg>',
		'webhook'   => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M18 16.98h-5.99c-1.1 0-1.95.94-2.48 1.9A4 4 0 0 1 2 17c.01-.7.2-1.4.57-2"/><path d="m6 17 3.13-5.78c.53-.97.1-2.18-.5-3.1a4 4 0 1 1 6.89-4.06"/><path d="m12 6 3.13 5.73C15.66 12.7 16.9 13 18 13a4 4 0 0 1 0 8"/></svg>',
		'gauge'     => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="m12 14 4-4"/><path d="M3.34 19a10 10 0 1 1 17.32 0"/></svg>',
		'bell'      => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/></svg>',
		'info'      => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg>',
		'shield'    => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/><path d="m9 12 2 2 4-4"/></svg>',
		'lock'      => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="11" width="16" height="11" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg>',
		'grip'      => '<svg width="12" height="12" viewBox="0 0 24 24" fill="currentColor"><circle cx="9" cy="6" r="1.5"/><circle cx="15" cy="6" r="1.5"/><circle cx="9" cy="12" r="1.5"/><circle cx="15" cy="12" r="1.5"/><circle cx="9" cy="18" r="1.5"/><circle cx="15" cy="18" r="1.5"/></svg>',
		'x'         => '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>',
		'check'     => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>',
		'dock'      => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="15" width="20" height="6" rx="2"/><line x1="7" y1="15" x2="7" y2="10"/><line x1="12" y1="15" x2="12" y2="5"/><line x1="17" y1="15" x2="17" y2="10"/></svg>',
	];
	return $c[ $n ] ?? '';
}

// ─────────────────────────────────────────────────────────────────────────────
//  NAV — therum_nav() returns sidebar sections. Filterable via therum_admin_nav_items.
// ─────────────────────────────────────────────────────────────────────────────
function therum_nav(): array {
	$woo = class_exists( 'WooCommerce' );
	$s   = [];

	$s[] = [ 'id' => 'content', 'label' => 'Content', 'icon' => 'content', 'desc' => 'Pages, posts and media.', 'items' => [
		[ 'label' => 'Pages', 'icon' => 'pages', 'url' => 'admin.php?page=therum-pages', 'match' => 'page=therum-pages' ],
		[ 'label' => 'Posts', 'icon' => 'posts', 'url' => 'admin.php?page=therum-posts', 'match' => 'page=therum-posts' ],
		[ 'label' => 'Media', 'icon' => 'media', 'url' => 'admin.php?page=therum-media', 'match' => 'page=therum-media' ],
	] ];

	$design_items = [
		[ 'label' => 'Design System', 'icon' => 'palette', 'url' => 'admin.php?page=therum-design-system', 'match' => 'page=therum-design-system' ],
		[ 'label' => 'Themes',        'icon' => 'themes',  'url' => 'admin.php?page=therum-themes',        'match' => 'page=therum-themes' ],
		[ 'label' => 'Menus',         'icon' => 'menus',   'url' => 'admin.php?page=therum-menus',         'match' => 'page=therum-menus' ],
		[ 'label' => 'Widgets',       'icon' => 'widgets', 'url' => 'admin.php?page=therum-widgets',       'match' => 'page=therum-widgets' ],
	];

	// Show Templates item if Bricks is installed/active (post type registered)
	if ( post_type_exists( 'bricks_template' ) || is_dir( WP_CONTENT_DIR . '/themes/bricks' ) ) {
		$design_items[] = [
			'label' => 'Templates',
			'icon'  => 'templates',
			'url'   => 'admin.php?page=therum-templates',
			'match' => 'page=therum-templates',
		];
		// Only surface "Bricks Settings" if Bricks has actually registered its
		// settings menu — protects against dead links when Bricks changes its
		// slug or the menu is hidden by a capability filter.
		$bricks_url = function_exists( 'menu_page_url' ) ? menu_page_url( 'bricks-settings', false ) : '';
		if ( $bricks_url ) {
			$design_items[] = [
				'label' => 'Bricks Settings',
				'icon'  => 'settings',
				'url'   => 'admin.php?page=bricks-settings',
				'match' => 'page=bricks-settings',
			];
		}
	}

	// SITE — everything that affects what visitors see on the front-end.
	// Renamed from "Design" so the section title clearly signals "this is
	// the public-facing surface" vs. WORKSPACE (admin-UX) and SYSTEM (plumbing).
	$s[] = [ 'id' => 'site', 'label' => 'Site', 'icon' => 'design', 'desc' => 'Front-end design system, themes, navigation, templates.', 'items' => $design_items ];

	// WORKSPACE — admin UX customization. Pulled "Admin Theme" out of the old
	// SYSTEM/Admin section so admin-appearance settings aren't mixed with
	// infrastructure. Single-item for now; density / shortcuts / dock prefs
	// land here as they ship.
	$s[] = [ 'id' => 'workspace', 'label' => 'Workspace', 'icon' => 'manage', 'desc' => 'How your wp-admin looks and behaves.', 'items' => [
		[ 'label' => 'Appearance', 'icon' => 'palette', 'url' => 'admin.php?page=therum-customization', 'match' => 'page=therum-customization' ],
	] ];

	$s[] = [ 'id' => 'studio', 'label' => 'Studio', 'icon' => 'therum', 'desc' => 'Custom modules from Therum Creative Studios.', 'items' => [
		[ 'label' => 'From the Studio', 'icon' => 'therum', 'url' => 'admin.php?page=therum-studio', 'match' => 'page=therum-studio' ],
	] ];

	// SYSTEM — admin infrastructure / plumbing. Renamed from "Admin" so it
	// no longer overloads two meanings (admin-UX vs. admin-extensions).
	// "Admin Theme" item moved out to WORKSPACE above.
	$s[] = [ 'id' => 'system', 'label' => 'System', 'icon' => 'admin', 'desc' => 'Plugins, users, connections, updates, settings.', 'items' => [
		[ 'label' => 'Plugins',       'icon' => 'plugins',  'url' => 'admin.php?page=therum-plugins',       'match' => 'page=therum-plugins' ],
		[ 'label' => 'Users',         'icon' => 'users',    'url' => 'admin.php?page=therum-users',         'match' => 'page=therum-users' ],
		[ 'label' => 'Connections',   'icon' => 'webhook',  'url' => 'admin.php?page=therum-connections',   'match' => 'page=therum-connections' ],
		[ 'label' => 'Updates',       'icon' => 'import',   'url' => 'admin.php?page=therum-updates',       'match' => 'page=therum-updates',
		  'badge' => ( class_exists( 'Therum_Updates' ) && Therum_Updates::has_update() ) ? 'new' : '' ],
		[ 'label' => 'Settings',      'icon' => 'settings', 'url' => 'admin.php?page=therum-settings',      'match' => 'page=therum-settings' ],
	] ];

	// Curated sections — gated by capability checks (Woo installed, Portfolio
	// CPT registered, etc.). Each is fed by classifying the auto-detected
	// plugin pages; anything unmatched lands in "More" for the user to sort.
	$plugin_pages = therum_detect_plugin_pages();
	$curated      = therum_curated_sections();
	$buckets      = [];
	$other_items  = [];

	foreach ( $plugin_pages as $it ) {
		$placed = false;
		foreach ( $curated as $sec ) {
			if ( call_user_func( $sec['is_member'], $it ) ) {
				$buckets[ $sec['id'] ][] = $it;
				$placed = true;
				break;
			}
		}
		if ( ! $placed ) $other_items[] = $it;
	}

	// Two-phase placement:
	//   1. Sections marked 'merge' fold their items into the matching built-in
	//      section ($s) by id — Content/Site/System absorb plugin pages into
	//      the existing top-level section rather than duplicating headers.
	//   2. Sections without 'merge' get prepended as new top-level sections
	//      (Store, Portfolio).
	$prepend = [];
	foreach ( $curated as $sec ) {
		$items = $buckets[ $sec['id'] ] ?? [];
		if ( empty( $items ) ) continue;

		// Optional: flatten a parent item so its children become top-level.
		// Used by Store to promote WooCommerce's Home/Orders/Customers/etc.
		if ( ! empty( $sec['flatten'] ) ) {
			$flat = [];
			foreach ( $items as $it ) {
				if ( ( $it['match'] ?? '' ) === $sec['flatten'] && ! empty( $it['children'] ) ) {
					foreach ( $it['children'] as $ch ) $flat[] = $ch;
				} else {
					$flat[] = $it;
				}
			}
			$items = $flat;
		}

		if ( ! empty( $sec['merge'] ) ) {
			// Find matching built-in section by id and append items there.
			$merged = false;
			foreach ( $s as &$bs ) {
				if ( ( $bs['id'] ?? '' ) === $sec['id'] ) {
					$bs['items'] = array_merge( $bs['items'] ?? [], $items );
					$merged = true;
					break;
				}
			}
			unset( $bs );
			// No matching built-in — fall through to prepend so the items still appear.
			if ( $merged ) continue;
		}

		$prepend[] = [
			'id'    => $sec['id'],
			'label' => $sec['label'],
			'icon'  => $sec['icon'],
			'desc'  => $sec['desc'] ?? '',
			'items' => $items,
		];
	}
	if ( $prepend ) $s = array_merge( $prepend, $s );

	if ( ! empty( $other_items ) ) {
		$s[] = [
			'id'    => 'unsorted',
			'label' => 'Unsorted',
			'icon'  => 'plugins',
			'desc'  => "Plugin pages we couldn't auto-categorize. Drag-reorder into the right section.",
			'items' => $other_items,
		];
	}

	return $s;
}

// ─────────────────────────────────────────────────────────────────────────────
//  CURATED SECTIONS — gated promotions of plugin nav into named top-level
//  sections (Store ← Woo, Portfolio ← portfolio CPT, etc.). Each section
//  declares its gate (only show when …), classifier (which auto-detected
//  items belong), and an optional flatten target (lift a parent's children
//  to top-level inside the section, so the section *is* the plugin's nav).
// ─────────────────────────────────────────────────────────────────────────────
function therum_curated_sections(): array {
	$list = [];

	// Store section: Counter is the e-commerce engine going forward, but
	// keep a WooCommerce gate too so legacy Woo sites still see the
	// section. Both gates use the same id 'store' so either engine's
	// pages land under one section.
	if ( defined( 'COUNTER_VERSION' ) || class_exists( 'WooCommerce' ) ) {
		$list[] = [
			'id'        => 'store',
			'label'     => 'Store',
			'icon'      => 'store',
			'desc'      => defined( 'COUNTER_VERSION' ) ? 'Counter — products, orders, customers.' : 'WooCommerce shop pages.',
			'is_member' => 'therum_is_store_item',
			'flatten'   => defined( 'COUNTER_VERSION' ) ? 'page=counter' : 'page=woocommerce',
		];
	}

	// Portfolio: only when a dedicated portfolio plugin is active (registers
	// a `portfolio`-flavored CPT). Without one, Bricks templates handle
	// portfolio content from the Design section.
	if ( therum_has_portfolio_cpt() ) {
		$list[] = [
			'id'        => 'portfolio',
			'label'     => 'Portfolio',
			'icon'      => 'feather',
			'desc'      => 'Portfolio pages and entries.',
			'is_member' => 'therum_is_portfolio_item',
			'flatten'   => null,
		];
	}

	// Content / Site / System are classifiers without their own header — the
	// bucketing layer merges items into the matching built-in section. Plugin
	// pages get pulled into the natural top-level section by topic instead of
	// dumped into "Unsorted". Order matters: most-specific predicates first
	// so e.g. a "Yoast SEO" page hits site (SEO) before system (Settings).
	$list[] = [
		'id'        => 'content',
		'label'     => 'Content',
		'icon'      => 'content',
		'desc'      => 'Pages, posts and media.',
		'is_member' => 'therum_is_content_item',
		'flatten'   => null,
		'merge'     => true, // merge into the built-in 'content' section, don't create a new one
	];
	$list[] = [
		'id'        => 'site',
		'label'     => 'Site',
		'icon'      => 'design',
		'desc'      => 'Front-end design, SEO, navigation.',
		'is_member' => 'therum_is_site_item',
		'flatten'   => null,
		'merge'     => true,
	];
	$list[] = [
		'id'        => 'system',
		'label'     => 'System',
		'icon'      => 'admin',
		'desc'      => 'Security, backup, performance, integrations.',
		'is_member' => 'therum_is_system_item',
		'flatten'   => null,
		'merge'     => true,
	];

	return $list;
}

function therum_has_portfolio_cpt(): bool {
	// `case_study` is the Therum-native portfolio CPT (registered in
	// therum-case-study-cpt.php). The other slugs are common third-party
	// portfolio plugins kept here so the Portfolio section auto-shows when
	// any of them are installed.
	foreach ( [ 'case_study', 'portfolio', 'jetpack-portfolio', 'avada_portfolio', 'project', 'projects' ] as $pt ) {
		if ( post_type_exists( $pt ) ) return true;
	}
	return false;
}

/**
 * Shared helpers for the classifier predicates below. Each curated section's
 * is_member callback gets an item shape like:
 *   [ 'label' => 'Yoast SEO', 'match' => 'page=wpseo_dashboard', 'parent' => '', 'children' => [...] ]
 * and returns true if the item belongs in that section.
 */
function therum_nav_item_slug( array $it ): string {
	$match = (string) ( $it['match'] ?? '' );
	$slug  = ( strpos( $match, 'page=' ) === 0 ) ? substr( $match, 5 ) : $match;
	return strtok( $slug, '&' ) ?: $slug;
}

/** Lowercase the label + the parent slug into one searchable haystack. */
function therum_nav_item_haystack( array $it ): string {
	$label  = strtolower( (string) ( $it['label']  ?? '' ) );
	$parent = strtolower( (string) ( $it['parent'] ?? '' ) );
	$slug   = strtolower( therum_nav_item_slug( $it ) );
	return $label . '|' . $parent . '|' . $slug;
}

// Content classifier — anything that edits content types or media. CPT
// archives (edit.php?post_type=X), forms, comments-management plugins,
// download/file/document plugins.
function therum_is_content_item( array $it ): bool {
	$slug = therum_nav_item_slug( $it );
	$hay  = therum_nav_item_haystack( $it );

	// edit.php?post_type=X (any CPT archive)
	if ( strpos( $slug, 'edit.php?post_type=' ) === 0 ) {
		// Exclude woo product (Store), case_study (Portfolio) — those have their own sections.
		if ( strpos( $slug, 'post_type=product' )    !== false ) return false;
		if ( strpos( $slug, 'post_type=case_study' ) !== false ) return false;
		return true;
	}
	// Forms / form-builders typically belong in Content
	foreach ( [ 'wpforms', 'gravityforms', 'ninja-forms', 'formidable', 'cf7', 'contact-form-7', 'fluentform', 'forminator', 'mailpoet' ] as $needle ) {
		if ( strpos( $hay, $needle ) !== false ) return true;
	}
	// Generic content-editing categories
	foreach ( [ 'comments', 'reviews', 'testimonials', 'faq', 'glossary', 'document', 'download', 'gallery', 'newsletter' ] as $needle ) {
		if ( strpos( $hay, $needle ) !== false ) return true;
	}
	return false;
}

// Site classifier — design system, themes, navigation, SEO, schema, redirects,
// sitemap, page builders that aren't Bricks (Bricks is in Design natively).
function therum_is_site_item( array $it ): bool {
	$hay = therum_nav_item_haystack( $it );

	foreach ( [
		// SEO / schema / sitemap
		'seo', 'yoast', 'rank-math', 'rankmath', 'aioseo', 'all-in-one-seo', 'sitemap', 'schema',
		// Redirects / 404 / link tools
		'redirect', '404', 'link-checker', 'broken-link',
		// Design adjuncts
		'elementor', 'beaver-builder', 'oxygen', 'divi', 'breakdance', 'cwicly',
		// Theme tools (NOT Bricks — handled natively)
		'theme-builder', 'kadence', 'astra', 'generatepress', 'blocksy',
		// Navigation / menu enhancements
		'menu-icons', 'max-mega-menu', 'ubermenu',
	] as $needle ) {
		if ( strpos( $hay, $needle ) !== false ) return true;
	}
	return false;
}

// System classifier — security, backup, performance, dev/integration tooling,
// analytics, anything operational.
function therum_is_system_item( array $it ): bool {
	$hay = therum_nav_item_haystack( $it );

	foreach ( [
		// Security
		'security', 'wordfence', 'sucuri', 'ithemes-security', 'solid-security', 'firewall', 'login-lockdown', 'limit-login',
		// Backup / migration
		'backup', 'updraft', 'duplicator', 'migration', 'all-in-one-wp-migration', 'wpvivid', 'blogvault',
		// Performance / cache
		'cache', 'litespeed', 'wp-rocket', 'w3-total', 'wp-super-cache', 'autoptimize', 'flying', 'perfmatters',
		// Image / file optimization
		'imagify', 'smush', 'shortpixel', 'webp', 'ewww',
		// Analytics / tag managers
		'analytics', 'google-site-kit', 'gtag', 'tag-manager', 'matomo', 'gtm',
		// Dev / debug
		'query-monitor', 'debug-bar', 'wp-cli', 'wp-mail-log', 'mail-smtp', 'fluent-smtp',
		// Updates / integrations
		'update', 'health-check', 'webhook', 'zapier', 'ifttt', 'make.com', 'integrately',
		// Admin / users / roles
		'role', 'capability', 'user-role',
	] as $needle ) {
		if ( strpos( $hay, $needle ) !== false ) return true;
	}
	return false;
}

// Store section membership — recognizes Counter pages (counter-* slugs) AND
// any WooCommerce admin page. Either engine's pages land under the unified
// "Store" sidebar section.
function therum_is_store_item( array $it ): bool {
	$match  = $it['match']  ?? '';
	$parent = $it['parent'] ?? '';
	$slug   = ( strpos( $match, 'page=' ) === 0 ) ? substr( $match, 5 ) : $match;
	$slug   = strtok( $slug, '&' ) ?: $slug;
	if ( $slug === 'counter' || strpos( $slug, 'counter-' ) === 0 ) return true;
	if ( $parent === 'counter' || strpos( $parent, 'counter-' ) === 0 ) return true;
	return therum_is_woo_item( $it );
}

// Heuristic: is this auto-detected item part of WooCommerce / wc-admin?
function therum_is_woo_item( array $it ): bool {
	$match  = $it['match']  ?? '';
	$parent = $it['parent'] ?? '';
	$label  = strtolower( $it['label'] ?? '' );
	// Slug derived from match: "page=foo" → "foo", or filename for .php
	$slug = ( strpos( $match, 'page=' ) === 0 ) ? substr( $match, 5 ) : $match;
	$slug = strtok( $slug, '&' ) ?: $slug;

	if ( $slug === 'woocommerce' || $parent === 'woocommerce' ) return true;
	if ( strpos( $slug, 'wc-' ) === 0 || strpos( $parent, 'wc-' ) === 0 ) return true;
	if ( strpos( $slug, 'woocommerce' ) !== false ) return true;
	if ( $label === 'woocommerce' ) return true;
	return false;
}

// Heuristic: is this auto-detected item part of a portfolio plugin?
function therum_is_portfolio_item( array $it ): bool {
	$match  = $it['match']  ?? '';
	$parent = $it['parent'] ?? '';
	$label  = strtolower( $it['label'] ?? '' );
	if ( stripos( $match, 'portfolio' ) !== false )  return true;
	if ( stripos( $parent, 'portfolio' ) !== false ) return true;
	if ( $label === 'portfolio' || $label === 'projects' ) return true;
	if ( $label === 'case studies' || $label === 'case study' ) return true;
	// edit.php?post_type=<slug> (and similar) lands as match=edit.php?post_type=<slug>
	if ( stripos( $match, 'post_type=portfolio' ) !== false )         return true;
	if ( stripos( $match, 'post_type=jetpack-portfolio' ) !== false ) return true;
	if ( stripos( $match, 'post_type=project' ) !== false )           return true;
	if ( stripos( $match, 'post_type=case_study' ) !== false )        return true;
	// Taxonomy admin pages for case_study (categories/tags/disciplines)
	if ( stripos( $match, 'taxonomy=case_study_' ) !== false )        return true;
	return false;
}

// ─────────────────────────────────────────────────────────────────────────────
//  AUTO-DETECT PLUGIN ADMIN PAGES
//
//  Walks BOTH $menu (top-level) and $submenu (children) so we surface every
//  plugin page WordPress knows about — including pages plugins register under
//  core menus like Settings → X (options-general.php) or Tools → Y (tools.php),
//  which the previous top-level-only walk silently dropped.
//
//  Returns an array of items where each item may carry a 'children' array of
//  the same shape — so the sidebar can render the WP submenu hierarchy nested
//  under each plugin, styled in Therum UI.
// ─────────────────────────────────────────────────────────────────────────────
function therum_detect_plugin_pages(): array {
	global $menu, $submenu;

	if ( ! is_array( $menu ) ) return [];

	// Therum-owned slugs — rendered under our own sections, never duplicated here
	$therum_slugs = [
		'therum', 'therum-pages', 'therum-posts', 'therum-media', 'therum-templates',
		'therum-themes', 'therum-menus', 'therum-customizer', 'therum-widgets',
		'therum-plugins', 'therum-users', 'therum-settings', 'therum-products',
		'therum-orders', 'therum-customers',
	];
	// Bricks — handled in Design section
	$bricks_slugs = [ 'bricks', 'bricks-settings' ];

	// Top-level WP core menu slugs — we skip the parent itself but still walk
	// its $submenu to surface plugin-added children (Settings → Yoast, etc.)
	$core_top = [
		'index.php', 'edit.php', 'upload.php', 'link-manager.php',
		'edit-comments.php', 'themes.php', 'plugins.php', 'users.php',
		'tools.php', 'options-general.php', 'profile.php',
	];
	// WP core *submenu* slugs — these are built-in children of the cores above,
	// so we don't surface them as plugin pages
	$core_sub = [
		'index.php', 'update-core.php', 'about.php', 'credits.php', 'freedoms.php', 'privacy.php',
		'edit.php', 'post-new.php', 'edit-tags.php', 'edit-tags.php?taxonomy=category', 'edit-tags.php?taxonomy=post_tag',
		'edit.php?post_type=page', 'post-new.php?post_type=page',
		'upload.php', 'media-new.php',
		'edit-comments.php',
		'themes.php', 'nav-menus.php', 'widgets.php', 'customize.php', 'theme-editor.php', 'site-editor.php',
		'plugins.php', 'plugin-install.php', 'plugin-editor.php',
		'users.php', 'user-new.php', 'profile.php',
		'tools.php', 'import.php', 'export.php', 'site-health.php',
		'export-personal-data.php', 'erase-personal-data.php',
		'options-general.php', 'options-writing.php', 'options-reading.php',
		'options-discussion.php', 'options-media.php', 'options-permalink.php', 'options-privacy.php',
	];

	$skip_top = array_flip( array_merge( $therum_slugs, $bricks_slugs ) );
	$skip_sub = array_flip( array_merge( $therum_slugs, $bricks_slugs, $core_sub ) );
	$core_top_set = array_flip( $core_top );

	// Labels already covered by curated sections — skip auto-detected entries
	// matching these so we don't get "Customize" alongside Design's "Customizer",
	// "Pages" alongside Content's "Pages", "Products" alongside Store's, etc.
	// NB: don't add "Products" here — Woo registers Products as a top-level
	// CPT menu (slug edit.php?post_type=product) and skipping by label kills it.
	// Orders/Customers stay so the wc-orders/wc-customers HPOS top-levels
	// don't double up with WooCommerce's children of the same name (which
	// flatten into Store via the WooCommerce parent).
	$curated_labels = array_flip( array_map( 'strtolower', [
		'Dashboard', 'Comments', 'Profile', 'Tools', 'Appearance', 'Updates', 'Privacy',
		'Pages', 'Posts', 'Media',
		'Themes', 'Menus', 'Customizer', 'Customize', 'Widgets', 'Templates', 'Bricks Settings', 'Bricks',
		'Plugins', 'Users', 'Settings',
		'Orders', 'Customers',
	] ) );

	// Normalize a slug for skip-check: strip query string so "customize.php?return=..."
	// matches "customize.php" in core_sub.
	$slug_base = function( string $s ): string { return strtok( $s, '?' ) ?: $s; };

	$items        = [];
	$seen_slug    = [];
	$seen_label   = []; // label-dedup within auto-detected (LiteSpeed×2, etc.)

	// Pass 1 — plugin top-level menus + their submenus as children
	foreach ( $menu as $row ) {
		if ( ! is_array( $row ) || empty( $row[2] ) ) continue;
		$slug  = $row[2];
		$label = therum_clean_label( $row[0] );
		if ( $label === '' || strpos( $slug, 'separator' ) !== false ) continue;
		// Exact-match for core_top: `edit.php` (Posts) is core and skipped, but
		// CPTs registered as `edit.php?post_type=product` (Woo Products) are
		// distinct top-levels and must pass through.
		if ( isset( $skip_top[ $slug ] ) || isset( $core_top_set[ $slug ] ) ) continue;
		if ( isset( $curated_labels[ strtolower( $label ) ] ) ) continue;
		if ( isset( $seen_slug[ $slug ] ) ) continue;
		$llower = strtolower( $label );
		if ( isset( $seen_label[ $llower ] ) ) continue;
		$seen_slug[ $slug ]   = true;
		$seen_label[ $llower ] = true;

		$children = [];
		if ( isset( $submenu[ $slug ] ) && is_array( $submenu[ $slug ] ) ) {
			foreach ( $submenu[ $slug ] as $sub ) {
				if ( ! is_array( $sub ) || empty( $sub[2] ) ) continue;
				$sslug  = $sub[2];
				$slabel = therum_clean_label( $sub[0] );
				if ( $slabel === '' ) continue;
				if ( isset( $skip_sub[ $slug_base( $sslug ) ] ) ) continue;
				// WP duplicates the parent as the first submenu entry; skip the dupe
				if ( $sslug === $slug && count( $children ) === 0 ) continue;
				if ( isset( $seen_slug[ $sslug ] ) ) continue;
				$seen_slug[ $sslug ] = true;
				$children[] = therum_make_plugin_item( $sslug, $slabel, $slug );
			}
		}

		$items[] = therum_make_plugin_item( $slug, $label, null, $children );
	}

	// Pass 2 — orphan plugin pages registered under core menus
	// (Settings → Plugin X, Tools → Plugin Y, Appearance → Plugin Z, etc.)
	foreach ( $core_top as $core_parent ) {
		if ( ! isset( $submenu[ $core_parent ] ) || ! is_array( $submenu[ $core_parent ] ) ) continue;
		foreach ( $submenu[ $core_parent ] as $sub ) {
			if ( ! is_array( $sub ) || empty( $sub[2] ) ) continue;
			$sslug  = $sub[2];
			$slabel = therum_clean_label( $sub[0] );
			if ( $slabel === '' ) continue;
			if ( isset( $skip_sub[ $slug_base( $sslug ) ] ) ) continue;
			if ( isset( $curated_labels[ strtolower( $slabel ) ] ) ) continue;
			if ( isset( $seen_slug[ $sslug ] ) ) continue;
			$llower = strtolower( $slabel );
			if ( isset( $seen_label[ $llower ] ) ) continue;
			$seen_slug[ $sslug ]   = true;
			$seen_label[ $llower ] = true;
			$items[] = therum_make_plugin_item( $sslug, $slabel, $core_parent );
		}
	}

	usort( $items, function( $a, $b ) {
		return strcasecmp( $a['label'], $b['label'] );
	} );

	return $items;
}

function therum_make_plugin_item( string $slug, string $label, ?string $parent = null, array $children = [] ): array {
	// .php slugs (e.g., admin.php, edit.php?post_type=foo) are direct file refs;
	// everything else is a registered page → admin.php?page=<slug>
	if ( strpos( $slug, '.php' ) !== false ) {
		$url   = $slug;
		$match = $slug; // path-based match for .php files
	} else {
		$url   = 'admin.php?page=' . $slug;
		$match = 'page=' . $slug;
	}
	$item = [
		'label'  => $label,
		'icon'   => therum_icon_for_label( $label ),
		'url'    => $url,
		'match'  => $match,
		'parent' => $parent ?? '',
	];
	if ( $children ) $item['children'] = $children;
	return $item;
}

/**
 * Strip the count-badge spans WP injects into menu titles
 * (Comments, plugin updates, Woo pending orders, etc.) and any trailing
 * standalone digits that survive an HTML strip — so "Home <span>5</span>"
 * doesn't become "Home 5" in the sidebar.
 */
function therum_clean_label( $raw ): string {
	if ( ! is_string( $raw ) ) $raw = (string) $raw;
	$raw = preg_replace(
		'#<span[^>]*\bclass="[^"]*\b(?:awaiting-mod|count-\d+|update-plugins|menu-counter|wp-ui-notification|woocommerce-menu-badge)\b[^"]*"[^>]*>.*?</span>#is',
		'',
		$raw
	);
	$clean = wp_strip_all_tags( (string) $raw );
	$clean = preg_replace( '/\s+\d+\s*$/', '', $clean );
	return trim( (string) $clean );
}

/**
 * Map a cleaned admin label to the best matching icon from therum_i().
 * Used for auto-detected plugin items so Store/Portfolio/etc. don't all
 * render with the generic "settings" sun.
 */
function therum_icon_for_label( string $label ): string {
	static $map = null;
	if ( $map === null ) $map = [
		'home'         => 'home',
		'dashboard'    => 'home',
		'orders'       => 'orders',
		'products'     => 'products',
		'customers'    => 'customers',
		'coupons'      => 'coupons',
		'reports'      => 'analytics',
		'analytics'    => 'analytics',
		'marketing'    => 'tags',
		'payments'     => 'payments',
		'shipping'     => 'shipping',
		'extensions'   => 'plugins',
		'settings'     => 'settings',
		'status'       => 'health',
		'health'       => 'health',
		'tools'        => 'utils',
		'plugins'      => 'plugins',
		'users'        => 'users',
		'media'        => 'media',
		'pages'        => 'pages',
		'posts'        => 'posts',
		'comments'     => 'comments',
		'themes'       => 'themes',
		'menus'        => 'menus',
		'widgets'      => 'widgets',
		'templates'    => 'templates',
		'customize'    => 'customizer',
		'customizer'   => 'customizer',
		'portfolio'    => 'feather',
		'projects'     => 'feather',
		'import'       => 'import',
		'export'       => 'import',
	];
	$key = strtolower( trim( $label ) );
	return $map[ $key ] ?? 'settings';
}

// ─────────────────────────────────────────────────────────────────────────────
//  AJAX — bento layout persistence per-user
// ─────────────────────────────────────────────────────────────────────────────
add_action( 'wp_ajax_therum_save_layout', function() {
	// Cap floor: the layout is per-user meta but only logged-in admin users
	// have any business reaching this endpoint. Subscribers shouldn't be
	// here at all — gate to read at minimum (anyone with a dashboard).
	if ( ! current_user_can( 'read' ) ) wp_send_json_error( 'forbidden', 403 );
	check_ajax_referer( 'therum_layout', 'nonce' );
	$layout = $_POST['layout'] ?? '';
	if ( ! is_string( $layout ) ) wp_send_json_error();
	$decoded = json_decode( wp_unslash( $layout ), true );
	if ( ! is_array( $decoded ) ) wp_send_json_error();
	update_user_meta( get_current_user_id(), 'therum_bento_layout', wp_json_encode( $decoded ) );
	wp_send_json_success();
} );

/**
 * Drag-to-sort persistence for any Therum_List_Page (Pages/Posts/Media/Users/
 * Plugins/etc.). Saves an ordered array of item IDs to user meta keyed by
 * `therum_list_order_{page_id}`. apply_saved_order() reads it at render time.
 */
add_action( 'wp_ajax_therum_save_list_order', function() {
	$page_id = isset( $_POST['page_id'] ) ? sanitize_key( $_POST['page_id'] ) : '';
	if ( ! $page_id ) wp_send_json_error( [ 'message' => 'missing page_id' ] );
	check_ajax_referer( 'therum_list_order_' . $page_id, 'nonce' );
	$raw = $_POST['order'] ?? '';
	if ( ! is_string( $raw ) ) wp_send_json_error();
	$decoded = json_decode( wp_unslash( $raw ), true );
	if ( ! is_array( $decoded ) ) wp_send_json_error();
	// Cap at a sensible upper bound + coerce every entry to a short string.
	$ids = array_slice( $decoded, 0, 5000 );
	$ids = array_values( array_filter( array_map( function( $v ) {
		$s = is_scalar( $v ) ? (string) $v : '';
		return strlen( $s ) > 0 && strlen( $s ) <= 256 ? $s : null;
	}, $ids ) ) );
	update_user_meta( get_current_user_id(), 'therum_list_order_' . $page_id, $ids );
	wp_send_json_success( [ 'count' => count( $ids ) ] );
} );

function therum_get_layout(): array {
	$raw = get_user_meta( get_current_user_id(), 'therum_bento_layout', true );
	if ( ! $raw ) return [];
	$decoded = json_decode( $raw, true );
	return is_array( $decoded ) ? $decoded : [];
}

// ─────────────────────────────────────────────────────────────────────────────
//  SIDEBAR LAYOUT — user-customisable section order, custom sections, and
//  per-item section assignment. Persisted as user meta therum_sidebar_layout.
//
//  Shape:
//    {
//      "v": 1,
//      "sections": [{ "id": "...", "label": "...", "icon": "..." }, ...],
//      "items": { "<section-id>": ["<item-id>", ...] }
//    }
//
//  Item IDs are the item's `match` string (e.g. "page=woocommerce") — stable
//  across page loads. Items not assigned anywhere fall through to the "more"
//  section so newly-installed plugins remain reachable.
// ─────────────────────────────────────────────────────────────────────────────
add_action( 'wp_ajax_therum_save_sidebar', function() {
	if ( ! current_user_can( 'read' ) ) wp_send_json_error( 'forbidden', 403 );
	check_ajax_referer( 'therum_sidebar', 'nonce' );
	$raw = $_POST['layout'] ?? '';
	if ( ! is_string( $raw ) ) wp_send_json_error();
	$decoded = json_decode( wp_unslash( $raw ), true );
	if ( ! is_array( $decoded ) ) wp_send_json_error();

	$clean = [ 'v' => 1, 'sections' => [], 'items' => [] ];
	foreach ( (array) ( $decoded['sections'] ?? [] ) as $sec ) {
		if ( empty( $sec['id'] ) ) continue;
		$id = sanitize_key( $sec['id'] );
		if ( $id === '' ) continue;
		$clean['sections'][] = [
			'id'    => $id,
			'label' => substr( sanitize_text_field( $sec['label'] ?? $id ), 0, 60 ),
			'icon'  => sanitize_key( $sec['icon'] ?? 'settings' ),
		];
	}
	foreach ( (array) ( $decoded['items'] ?? [] ) as $sid => $iids ) {
		if ( ! is_array( $iids ) ) continue;
		$sid_clean = sanitize_key( $sid );
		if ( $sid_clean === '' ) continue;
		$list = [];
		foreach ( $iids as $iid ) {
			if ( ! is_string( $iid ) ) continue;
			// Item IDs may contain = and & (query params); allow but trim length
			$iid = substr( wp_strip_all_tags( $iid ), 0, 200 );
			if ( $iid !== '' ) $list[] = $iid;
		}
		$clean['items'][ $sid_clean ] = $list;
	}

	update_user_meta( get_current_user_id(), 'therum_sidebar_layout', wp_json_encode( $clean ) );
	wp_send_json_success();
} );

add_action( 'wp_ajax_therum_reset_sidebar', function() {
	if ( ! current_user_can( 'read' ) ) wp_send_json_error( 'forbidden', 403 );
	check_ajax_referer( 'therum_sidebar', 'nonce' );
	delete_user_meta( get_current_user_id(), 'therum_sidebar_layout' );
	wp_send_json_success();
} );

function therum_get_sidebar_layout(): array {
	$raw = get_user_meta( get_current_user_id(), 'therum_sidebar_layout', true );
	if ( ! $raw ) return [];
	$decoded = json_decode( $raw, true );
	return is_array( $decoded ) ? $decoded : [];
}

// IDs of "always present when applicable" curated sections. Each id is only
// returned when its gate is satisfied — so a section that's been disabled
// (e.g. Case Studies module off → no portfolio CPT) doesn't get force-
// restored when the user's saved layout no longer references it.
function therum_curated_section_ids(): array {
	$ids = [];
	if ( defined( 'COUNTER_VERSION' ) || class_exists( 'WooCommerce' ) ) $ids[] = 'store';
	if ( function_exists( 'therum_has_portfolio_cpt' ) && therum_has_portfolio_cpt() ) $ids[] = 'portfolio';
	return $ids;
}

// Therum-curated structural items (Pages/Posts/Media, Themes/Menus/etc, Plugins/
// Users/Settings) are anchored to their default section. Users can reorder them
// inside that section but can't drag them to a custom section — they're the
// scaffolding of the OS. Auto-detected plugin pages (everything that doesn't
// match this prefix) remain freely movable.
function therum_is_locked_item( string $item_id ): bool {
	return strpos( $item_id, 'page=therum-' ) === 0;
}

/**
 * Apply the user's saved layout to the default nav.
 *
 * Rules:
 *  - User reorders inside any section persist (saved order is honored first).
 *  - Items the user has explicitly placed somewhere (= referenced anywhere in
 *    the saved layout) stay where the user put them.
 *  - Items the user has NEVER referenced auto-appear in their default-home
 *    section, so newly-detected pages (a freshly installed plugin, Woo's
 *    Products that wasn't surfaced before) always show up without needing a
 *    Reset.
 *  - Curated sections (Store, Portfolio) are guaranteed to be rendered when
 *    their gate is active, even if the user deleted them from the layout.
 */
function therum_apply_sidebar_layout( array $default_nav ): array {
	$layout = therum_get_sidebar_layout();
	if ( empty( $layout['sections'] ) ) return $default_nav;

	$curated_ids = therum_curated_section_ids();

	// Index defaults by id, and build a pool of all known items keyed by match.
	$default_sections = []; // sid => section
	$default_items_by_section = []; // sid => [items]
	$pool = []; // id => item
	$home = []; // id => default_section_id (for fallback placement)
	foreach ( $default_nav as $sec ) {
		$default_sections[ $sec['id'] ] = $sec;
		$default_items_by_section[ $sec['id'] ] = $sec['items'] ?? [];
		foreach ( $sec['items'] ?? [] as $it ) {
			$id = $it['match'] ?? '';
			if ( $id === '' ) continue;
			$pool[ $id ] = $it;
			$home[ $id ] = $sec['id'];
		}
	}

	// Set of every item-id the saved layout references in any section. If
	// an item is in this set, the user has touched it and we won't second-
	// guess their placement.
	$referenced = [];
	foreach ( (array) ( $layout['items'] ?? [] ) as $iids ) {
		foreach ( (array) $iids as $iid ) {
			if ( is_string( $iid ) && $iid !== '' ) $referenced[ $iid ] = true;
		}
	}

	$result   = [];
	$assigned = [];

	foreach ( $layout['sections'] as $sec ) {
		$sid = $sec['id'];

		// 1. Saved order first — pull items in the order the user saved them.
		$items = [];
		foreach ( (array) ( $layout['items'][ $sid ] ?? [] ) as $iid ) {
			if ( ! isset( $pool[ $iid ] ) || isset( $assigned[ $iid ] ) ) continue;
			$items[]          = $pool[ $iid ];
			$assigned[ $iid ] = true;
		}

		// 2. If this section maps to a default section, auto-append default
		// items only when they're TRULY orphaned (nowhere in saved layout).
		// This rescues stale-save items (e.g., Settings missing from Admin)
		// without overriding deliberate user placement.
		if ( isset( $default_items_by_section[ $sid ] ) ) {
			foreach ( $default_items_by_section[ $sid ] as $it ) {
				$iid = $it['match'] ?? '';
				if ( $iid === '' || isset( $assigned[ $iid ] ) || isset( $referenced[ $iid ] ) ) continue;
				$items[]          = $it;
				$assigned[ $iid ] = true;
			}
		}

		// Skip empty sections — a saved "portfolio" section with no remaining
		// items (e.g. Case Studies module disabled, Portfolio CPT gone) should
		// not render a header. The user can re-enable via Studio; the section
		// will re-appear automatically once items exist for it again.
		if ( empty( $items ) ) continue;

		$result[] = [
			'id'    => $sid,
			'label' => $sec['label'] ?? ( $default_sections[ $sid ]['label'] ?? ucfirst( $sid ) ),
			'icon'  => $sec['icon']  ?? ( $default_sections[ $sid ]['icon']  ?? 'settings' ),
			'items' => $items,
		];
	}

	// 3. Make sure every gated curated section is in the result even if the
	// user deleted it. Pull any not-yet-referenced curated items into it.
	$result_ids = array_column( $result, 'id' );
	foreach ( $curated_ids as $cid ) {
		if ( in_array( $cid, $result_ids, true ) ) continue;
		$default_items = $default_items_by_section[ $cid ] ?? [];
		if ( empty( $default_items ) ) continue;
		$bring_back = [];
		foreach ( $default_items as $it ) {
			$iid = $it['match'] ?? '';
			if ( $iid === '' || isset( $assigned[ $iid ] ) || isset( $referenced[ $iid ] ) ) continue;
			$bring_back[]     = $it;
			$assigned[ $iid ] = true;
		}
		if ( ! $bring_back ) continue; // user explicitly moved everything out — respect that
		$def = $default_sections[ $cid ] ?? [];
		array_unshift( $result, [
			'id'    => $cid,
			'label' => $def['label'] ?? ucfirst( $cid ),
			'icon'  => $def['icon']  ?? 'settings',
			'items' => $bring_back,
		] );
	}

	// 4. Anything still unassigned (no default home in saved sections, not
	// referenced anywhere) → drop into "Unsorted" so it's never lost.
	$orphans = [];
	foreach ( $pool as $id => $it ) {
		if ( isset( $assigned[ $id ] ) || isset( $referenced[ $id ] ) ) continue;
		$orphans[] = $it;
	}
	if ( $orphans ) {
		$unsorted_idx = null;
		foreach ( $result as $i => $r ) {
			// Accept either the new id ('unsorted') or the legacy id ('more')
			// for back-compat with saved layouts that still reference 'more'.
			if ( in_array( $r['id'] ?? '', [ 'unsorted', 'more' ], true ) ) { $unsorted_idx = $i; break; }
		}
		if ( $unsorted_idx === null ) {
			$result[] = [ 'id' => 'unsorted', 'label' => 'Unsorted', 'icon' => 'plugins', 'items' => $orphans ];
		} else {
			// Promote to the new id/label so legacy "More" saved layouts heal.
			$result[ $unsorted_idx ]['id']    = 'unsorted';
			$result[ $unsorted_idx ]['label'] = 'Unsorted';
			$result[ $unsorted_idx ]['items'] = array_merge( $result[ $unsorted_idx ]['items'], $orphans );
		}
	}

	return $result;
}

// ─────────────────────────────────────────────────────────────────────────────
//  DASHBOARD ROUTE — admin.php?page=therum
// ─────────────────────────────────────────────────────────────────────────────
add_action( 'admin_menu', function() {
	add_menu_page(
		'Therum OS', 'Dashboard', 'read', 'therum',
		'therum_render_dashboard', 'data:image/svg+xml;base64,' . base64_encode(
			'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="white"><path d="M3 3h7v9H3zM14 3h7v5h-7zM14 12h7v9h-7zM3 16h7v5H3z"/></svg>'
		),
		2
	);
}, 10 );

// Redirect default WP dashboard (index.php) to ours
add_action( 'admin_init', function() {
	global $pagenow;
	if ( $pagenow === 'index.php' && empty( $_GET['page'] ) && ! isset( $_GET['th_frame'] ) ) {
		wp_safe_redirect( admin_url( 'admin.php?page=therum' ) );
		exit;
	}
} );

// ─────────────────────────────────────────────────────────────────────────────
//  IFRAME GUARD — when ?th_frame=1, we skip the shell so we can iframe
//  core admin pages (themes.php, plugins.php, etc.) inside our shell's main col.
//  Also skip shell on plugin settings pages that have their own admin UI.
// ─────────────────────────────────────────────────────────────────────────────
function therum_is_frame(): bool {
	// `?th_frame=1` lets the shell iframe itself (preview, sub-render) without
	// recursing into another full chrome. This stays.
	if ( isset( $_GET['th_frame'] ) ) return true;

	// Per product directive: every admin page wraps in the Therum shell.
	// No more page-slug skip list. If a third-party plugin's full-page React
	// app misbehaves inside the shell, the user can request bypass per-page
	// via the `therum_admin_shell_bypass` filter below.
	$page = isset( $_GET['page'] ) ? (string) $_GET['page'] : '';
	return (bool) apply_filters( 'therum_admin_shell_bypass', false, $page );
}

// Allow self-framing
add_action( 'send_headers', function() {
	if ( is_admin() ) header_remove( 'X-Frame-Options' );
} );

// ─────────────────────────────────────────────────────────────────────────────
//  HIDE WP CHROME — the WP toolbar, default admin menu, etc. We replace them.
// ─────────────────────────────────────────────────────────────────────────────
add_filter( 'show_admin_bar', '__return_false' );

add_action( 'admin_enqueue_scripts', function() {
	if ( therum_is_frame() ) return;
	$path = __DIR__ . '/assets/therum-shell.css';
	wp_enqueue_style( 'therum-shell', plugins_url( 'assets/therum-shell.css', __FILE__ ), [], file_exists( $path ) ? filemtime( $path ) : null );
} );

// In iframe mode, just hide WP chrome but keep the page layout intact
add_action( 'admin_head', function() {
	if ( ! therum_is_frame() ) return;
	?>
<style id="therum-iframe-css">
#wpadminbar, #adminmenumain, #adminmenuback, #adminmenuwrap, #wpfooter { display: none !important; }
html.wp-toolbar { padding-top: 0 !important; }
#wpcontent { margin-left: 0 !important; padding-left: 20px !important; }
body { background: transparent !important; }
</style>
<?php
} );

// ─────────────────────────────────────────────────────────────────────────────
//  SHELL CSS — sidebar, topbar, layout. Theme palettes plug in via --ac/--sf etc.
// ─────────────────────────────────────────────────────────────────────────────

// ─────────────────────────────────────────────────────────────────────────────
//  SHELL HTML — sidebar + topbar wrap around #wpbody-content
//  We use admin_notices (early hook before content) for sidebar, and DOM
//  reordering via JS to wrap things correctly.
// ─────────────────────────────────────────────────────────────────────────────
add_action( 'in_admin_header', function() {
	if ( therum_is_frame() ) return;
	static $rendered = false;
	if ( $rendered ) return;
	$rendered = true;

	$user = wp_get_current_user();
	$site = get_bloginfo( 'name' ) ?: 'Therum OS';
	$home_host = wp_parse_url( home_url(), PHP_URL_HOST ) ?: '';
	$av_letter = strtoupper( substr( $user->display_name ?: 'U', 0, 1 ) );

	$nav = apply_filters( 'therum_admin_nav_items', therum_nav() );
	$nav = therum_apply_sidebar_layout( $nav );

	$uri = wp_unslash( $_SERVER['REQUEST_URI'] ?? '' );
	$is_dash = strpos( $uri, 'page=therum' ) !== false && strpos( $uri, 'therum-' ) === false;

	// Determine current section/item for active state — include children so a
	// page that lives under a plugin submenu still highlights correctly.
	$cur_match = '';
	foreach ( $nav as $sec ) {
		foreach ( $sec['items'] ?? [] as $it ) {
			if ( ! empty( $it['match'] ) && strpos( $uri, $it['match'] ) !== false ) {
				$cur_match = $it['match'];
				break 2;
			}
			foreach ( $it['children'] ?? [] as $ch ) {
				if ( ! empty( $ch['match'] ) && strpos( $uri, $ch['match'] ) !== false ) {
					$cur_match = $ch['match'];
					break 3;
				}
			}
		}
	}

	// Page title for topbar
	$page_title = 'Dashboard';
	foreach ( $nav as $sec ) {
		foreach ( $sec['items'] ?? [] as $it ) {
			if ( ( $it['match'] ?? '' ) === $cur_match ) {
				$page_title = $it['label'];
				break 2;
			}
			foreach ( $it['children'] ?? [] as $ch ) {
				if ( ( $ch['match'] ?? '' ) === $cur_match ) {
					$page_title = $ch['label'];
					break 3;
				}
			}
		}
	}
	if ( $is_dash ) $page_title = 'Dashboard';
	?>

<div id="th-shell">

  <aside id="th-sb">
    <div class="th-sb-header">
      <div class="th-logo">T</div>
      <div class="th-site-info">
        <div class="th-site-name"><?php echo esc_html( $site ); ?></div>
        <div class="th-site-host"><?php echo esc_html( $home_host ); ?></div>
      </div>
    </div>

    <div class="th-sb-search">
      <div class="th-sb-search-box">
        <?php echo therum_i('search'); ?>
        <input type="text" id="th-sb-search-input" placeholder="Search…" aria-label="Search admin" />
        <span class="th-sb-search-kbd">⌘K</span>
      </div>
    </div>

    <nav class="th-sb-nav">

      <a class="th-sb-item<?php echo $is_dash ? ' active' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=therum' ) ); ?>">
        <?php echo therum_i('home'); ?>
        <span>Dashboard</span>
      </a>

      <?php foreach ( $nav as $sec ): ?>
      <div class="th-sb-section" data-section-id="<?php echo esc_attr( $sec['id'] ?? '' ); ?>">
        <div class="th-sb-section-label">
          <span class="th-sb-grip" data-sb-grip="section" title="Drag to reorder"><?php echo therum_i('grip'); ?></span>
          <span class="th-sb-section-toggle" data-toggle-section>
            <span class="th-sb-section-name"><?php echo esc_html( strtoupper( $sec['label'] ?? '' ) ); ?></span>
            <span class="chev"><?php echo therum_i('chevron'); ?></span>
          </span>
          <button type="button" class="th-sb-section-rename" title="Rename section" aria-label="Rename section" data-sb-rename><?php echo therum_i('edit2'); ?></button>
          <button type="button" class="th-sb-section-x" title="Delete section" aria-label="Delete section" data-sb-delete><?php echo therum_i('x'); ?></button>
        </div>
        <div class="th-sb-section-items">
          <?php foreach ( $sec['items'] ?? [] as $it ):
            $is_active   = $cur_match && ( $it['match'] ?? '' ) === $cur_match;
            $url         = $it['url'] ?? '#';
            $href        = strpos( $url, 'http' ) === 0 ? $url : admin_url( $url );
            $is_external = ! empty( $it['external'] );
            $children    = $it['children'] ?? [];
            $has_kids    = ! empty( $children );
            $kid_active  = false;
            if ( $has_kids ) {
              foreach ( $children as $ch ) {
                if ( ! empty( $ch['match'] ) && $ch['match'] === $cur_match ) { $kid_active = true; break; }
              }
            }
            $wrap_classes = 'th-sb-itemwrap';
            if ( $has_kids )   $wrap_classes .= ' has-children';
            if ( $kid_active ) $wrap_classes .= ' child-active';
          ?>
          <div class="<?php echo esc_attr( $wrap_classes ); ?>" data-item-id="<?php echo esc_attr( $it['match'] ?? '' ); ?>">
            <a class="th-sb-item<?php echo $is_active ? ' active' : ''; ?>" href="<?php echo esc_url( $href ); ?>"<?php if ( $is_external ): ?> target="_blank" rel="noopener"<?php endif; ?>>
              <span class="th-sb-grip" data-sb-grip="item" title="Drag to reorder"><?php echo therum_i('grip'); ?></span>
              <?php
              // Every sidebar entry needs a visible icon. If the named icon
              // isn't in therum_i()'s registry the helper returns '' — we
              // fall back to a generic dot so the row layout doesn't shift.
              $icon_svg = therum_i( $it['icon'] ?? '' );
              if ( $icon_svg === '' ) $icon_svg = therum_i( 'chevron' );
              echo $icon_svg; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG from internal registry
              ?>
              <span><?php echo esc_html( $it['label'] ?? '' ); ?></span>
              <?php if ( $is_external && ! $has_kids ): ?><span class="th-sb-item-ext"><?php echo therum_i('external'); ?></span><?php endif; ?>
              <?php if ( $has_kids ): ?><span class="th-sb-item-chev" data-toggle-children role="button" aria-label="Toggle subpages"><?php echo therum_i('chevron'); ?></span><?php endif; ?>
            </a>
            <?php if ( $has_kids ): ?>
            <div class="th-sb-children">
              <?php foreach ( $children as $ch ):
                $ch_active = ! empty( $ch['match'] ) && $ch['match'] === $cur_match;
                $ch_url    = $ch['url'] ?? '#';
                $ch_href   = strpos( $ch_url, 'http' ) === 0 ? $ch_url : admin_url( $ch_url );
              ?>
              <a class="th-sb-child<?php echo $ch_active ? ' active' : ''; ?>" href="<?php echo esc_url( $ch_href ); ?>" title="<?php echo esc_attr( $ch['label'] ?? '' ); ?>">
                <?php echo esc_html( $ch['label'] ?? '' ); ?>
              </a>
              <?php endforeach; ?>
            </div>
            <?php endif; ?>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endforeach; ?>

      <button type="button" class="th-add-section" id="th-add-section">
        <?php echo therum_i('plus'); ?>
        <span>Add section</span>
      </button>

    </nav>

    <div class="th-sb-edit-toggle">
      <button type="button" id="th-edit-sb-btn" class="th-sb-edit-btn" title="Edit sidebar">
        <?php echo therum_i('edit2'); ?>
        <span>Edit sidebar</span>
      </button>
      <a href="<?php echo esc_url( home_url('/') ); ?>" target="_blank" rel="noopener" class="th-sb-edit-btn th-sb-view-site" title="View frontend">
        <?php echo therum_i('external'); ?>
        <span>View frontend</span>
      </a>
    </div>
    <div class="th-sb-edit-bar">
      <button type="button" class="th-sb-reset" id="th-sb-reset">Reset</button>
      <button type="button" class="th-sb-done" id="th-sb-done"><?php echo therum_i('check'); ?> Save</button>
    </div>

    <div class="th-sb-footer">
      <span class="ok-dot"></span>
      <span>v<?php echo esc_html( defined('THERUM_OS_VERSION') ? THERUM_OS_VERSION : '1.9.0' ); ?></span>
      <span class="spacer"></span>
      <span><?php echo esc_html( therum_db_engine() ); ?></span>
    </div>
  </aside>

  <div id="th-main">
    <a class="th-skip-link" href="#th-content">Skip to content</a>

    <header id="th-top">
      <div class="th-top-title"><?php echo esc_html( $page_title ); ?></div>

      <div class="th-top-actions">
        <button class="th-top-btn" id="th-theme-toggle" title="Toggle light/dark" aria-label="Toggle light or dark mode">
          <?php echo therum_i('sun'); ?>
        </button>
        <?php
        $dm_installed = function_exists( 'is_plugin_active' ) && is_plugin_active( 'desktop-mode/desktop-mode.php' );
        // Reflect the Desktop Mode plugin's REAL per-user flag, not Therum's
        // legacy mirror — so the button state matches what's actually rendering.
        $dm_user_on   = function_exists( 'therum_desktop_mode_active_for_user' )
            ? therum_desktop_mode_active_for_user()
            : ( get_user_meta( get_current_user_id(), 'desktop_mode_mode', true ) === '1' );
        ?>
        <button class="th-top-btn<?php echo $dm_user_on ? ' is-active' : ''; ?>" id="th-desktop-toggle" title="Desktop Mode" aria-label="Toggle Desktop Mode" data-dm-installed="<?php echo $dm_installed ? '1' : '0'; ?>">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>
        </button>
        <a class="th-top-btn" href="<?php echo esc_url( home_url() ); ?>" target="_blank" title="View site" aria-label="View site (opens in a new tab)">
          <?php echo therum_i('external'); ?>
        </a>
        <div class="th-top-avatar" role="img" aria-label="<?php echo esc_attr( ( $user->display_name ?? 'User' ) ); ?>"><?php echo esc_html( $av_letter ); ?></div>
      </div>
    </header>

    <div id="th-content">

	<?php
} );

// Close the wrappers after WP renders its content
add_action( 'in_admin_footer', function() {
	if ( therum_is_frame() ) return;
	static $rendered = false;
	if ( $rendered ) return;
	$rendered = true;
	?>
    </div><!-- /#th-content -->
  </div><!-- /#th-main -->
</div><!-- /#th-shell -->
	<?php
}, 1 );

// ─────────────────────────────────────────────────────────────────────────────
//  DASHBOARD RENDER
// ─────────────────────────────────────────────────────────────────────────────
/**
 * Compute real site-health status for the dashboard card.
 *
 * Checks (cheapest first):
 *   - WP core / plugin / theme updates pending
 *   - PHP version vs the wp.org-recommended minimum
 *   - HTTPS active
 *   - Debug display NOT enabled in production
 *
 * Returns a uniform shape: status (good|warning|critical), label, sub.
 * No external HTTP calls — uses options + functions that are already
 * populated by WP's own update transients.
 */
function therum_dashboard_health(): array {
	$problems = [];
	$severity = 'good';

	// 1. Core / plugin / theme updates pending
	if ( function_exists( 'get_core_updates' ) ) {
		foreach ( (array) get_core_updates() as $u ) {
			if ( isset( $u->response ) && $u->response === 'upgrade' ) {
				$problems[] = 'WordPress ' . ( $u->version ?? '' ) . ' available';
				$severity   = 'warning';
				break;
			}
		}
	}
	if ( function_exists( 'get_plugin_updates' ) ) {
		$pu = (array) get_plugin_updates();
		if ( $pu ) {
			$n = count( $pu );
			$problems[] = $n . ' plugin update' . ( $n === 1 ? '' : 's' );
			if ( $severity === 'good' ) $severity = 'warning';
		}
	}
	if ( function_exists( 'get_theme_updates' ) ) {
		$tu = (array) get_theme_updates();
		if ( $tu ) {
			$n = count( $tu );
			$problems[] = $n . ' theme update' . ( $n === 1 ? '' : 's' );
			if ( $severity === 'good' ) $severity = 'warning';
		}
	}

	// 2. PHP version below WP's current recommendation (7.4+)
	if ( version_compare( PHP_VERSION, '7.4', '<' ) ) {
		$problems[] = 'PHP ' . PHP_VERSION . ' — below recommended 7.4';
		$severity   = 'critical';
	}

	// 3. HTTPS check — only flag when the home URL says https but the
	//    current request didn't arrive over TLS (misconfiguration).
	if ( strpos( home_url(), 'https://' ) === 0
	     && empty( $_SERVER['HTTPS'] )
	     && ( $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '' ) !== 'https' ) {
		$problems[] = 'HTTPS misconfigured';
		$severity   = 'critical';
	}

	// 4. Debug display in production — never safe to leak
	if ( defined( 'WP_DEBUG' ) && WP_DEBUG
	     && defined( 'WP_DEBUG_DISPLAY' ) && WP_DEBUG_DISPLAY
	     && ! ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) ) {
		$problems[] = 'WP_DEBUG_DISPLAY is on';
		if ( $severity !== 'critical' ) $severity = 'warning';
	}

	if ( empty( $problems ) ) {
		return [
			'status' => 'good',
			'label'  => 'Healthy',
			'sub'    => 'No issues detected',
			'detail' => [],
		];
	}

	return [
		'status' => $severity,
		'label'  => $severity === 'critical' ? 'Critical' : 'Action needed',
		'sub'    => count( $problems ) . ' issue' . ( count( $problems ) === 1 ? '' : 's' ),
		'detail' => $problems,
	];
}

/**
 * Real publish-count-per-week series for a post type. Used for the
 * dashboard sparklines so the chart actually reflects activity instead
 * of decorative fixture data. Returns oldest-first.
 */
function therum_dashboard_sparkline_data( string $post_type, int $weeks = 12 ): array {
	global $wpdb;
	$cutoff = gmdate( 'Y-m-d 00:00:00', strtotime( '-' . ( $weeks * 7 ) . ' days' ) );
	$sql    = $wpdb->prepare(
		"SELECT YEARWEEK(post_date, 1) AS yw, COUNT(*) AS n
		 FROM {$wpdb->posts}
		 WHERE post_type = %s AND post_status = 'publish' AND post_date >= %s
		 GROUP BY yw",
		$post_type, $cutoff
	);
	$rows = $wpdb->get_results( $sql, ARRAY_A );
	$by_yw = [];
	foreach ( (array) $rows as $r ) {
		$by_yw[ (string) $r['yw'] ] = (int) $r['n'];
	}
	// Walk the last $weeks weeks and emit the count (or 0)
	$out = [];
	for ( $i = $weeks - 1; $i >= 0; $i-- ) {
		$ts = strtotime( "-{$i} weeks" );
		$yw = gmdate( 'oW', $ts );        // ISO year-week, matches MySQL YEARWEEK(.., 1)
		$out[] = $by_yw[ $yw ] ?? 0;
	}
	return $out;
}

/**
 * Render an SVG sparkline from a numeric series. Uses CSS-var colors so
 * it inherits the active Therum theme. Returns '' for empty input or an
 * all-zero series (so cards don't render a flat line that looks broken).
 */
function therum_dashboard_sparkline_svg( array $values, string $stroke = 'var(--ac)' ): string {
	if ( ! $values || max( $values ) === 0 ) return '';
	$w   = 120; $h = 36;
	$max = max( $values );
	$n   = count( $values );
	$step = $w / max( $n - 1, 1 );
	$pts  = [];
	foreach ( $values as $i => $v ) {
		$x = $i * $step;
		$y = $h - 4 - ( $v / $max ) * ( $h - 8 );
		$pts[] = sprintf( '%.1f,%.1f', $x, $y );
	}
	$path = 'M' . implode( ' L', $pts );
	$area = $path . " L{$w},{$h} L0,{$h} Z";
	return '<svg class="th-sparkline" viewBox="0 0 ' . $w . ' ' . $h . '" preserveAspectRatio="none">'
	     . '<path d="' . $path . '" fill="none" stroke="' . esc_attr( $stroke ) . '" stroke-width="1.5"/>'
	     . '<path d="' . $area . '" fill="' . esc_attr( $stroke ) . '" opacity="0.08"/>'
	     . '</svg>';
}

function therum_render_dashboard() {
	$user = wp_get_current_user();
	$first_name = $user->first_name ?: $user->display_name;
	$site = get_bloginfo( 'name' );

	$pages_count = (int) wp_count_posts( 'page' )->publish;
	$posts_count = (int) wp_count_posts( 'post' )->publish;
	$users_count = (int) count_users()['total_users'];
	$products_count = class_exists( 'WooCommerce' ) ? (int) wp_count_posts( 'product' )->publish : null;

	$health = therum_dashboard_health();

	$now = current_time( 'l, F j · g:i A' );

	$layout = therum_get_layout();
	$layout_map = [];
	foreach ( $layout as $l ) {
		if ( ! empty( $l['id'] ) ) $layout_map[ $l['id'] ] = $l['size'] ?? null;
	}

	$nonce = wp_create_nonce( 'therum_layout' );

	// Helper to get default size or stored size
	$size = function( $id, $default ) use ( $layout_map ) {
		return esc_attr( $layout_map[ $id ] ?? $default );
	};
	?>
<div class="th-dash">

  <div class="th-dash-greet"><?php echo esc_html( $now ); ?></div>
  <h1 class="th-dash-title">Welcome back, <?php echo esc_html( $first_name ); ?></h1>
  <p class="th-dash-sub">Here's what's happening with <?php echo esc_html( $site ); ?> today.</p>

  <div class="th-dash-actions">
    <a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=page&th_frame=1' ) ); ?>" class="th-btn th-btn-primary"><?php echo therum_i('plus'); ?> New Page</a>
    <a href="<?php echo esc_url( admin_url( 'post-new.php?th_frame=1' ) ); ?>" class="th-btn"><?php echo therum_i('plus'); ?> New Post</a>
    <a href="<?php echo esc_url( admin_url( 'media-new.php?th_frame=1' ) ); ?>" class="th-btn"><?php echo therum_i('import'); ?> Upload Media</a>
    <div class="th-dash-actions-spacer"></div>
    <button class="th-btn" id="th-edit-layout-btn"><?php echo therum_i('widgets'); ?> Edit layout</button>
  </div>

  <div class="th-edit-bar">
    <?php echo therum_i('widgets'); ?>
    <div class="th-edit-bar-text"><strong>Edit layout</strong> · drag cards to rearrange, drag the corner to resize.</div>
    <button class="th-btn" id="th-edit-reset">Reset</button>
    <button class="th-btn th-btn-primary" id="th-edit-done">Done</button>
  </div>

  <div class="th-bento" id="th-bento">

    <!-- Pages -->
    <div class="th-card" data-bento-id="stat-pages" data-size="<?php echo $size('stat-pages', 'xs'); ?>">
      <span class="th-size-picker"><button class="th-size-picker-btn" data-size-picker><?php echo therum_i('widgets'); ?></button><div class="th-size-picker-menu"></div></span>
      <span class="th-resize-handle" data-resize></span>
      <div class="th-card-head">
        <span class="th-card-label">Pages</span>
        <a class="th-card-link" href="<?php echo esc_url( admin_url( 'admin.php?page=therum-pages' ) ); ?>">View all →</a>
      </div>
      <div class="th-stat-val"><?php echo number_format_i18n( $pages_count ); ?></div>
      <div class="th-stat-sub">Total pages</div>

      <div class="th-adaptive show-sm">
        <?php echo therum_dashboard_sparkline_svg( therum_dashboard_sparkline_data( 'page' ) ); ?>
      </div>

      <div class="th-adaptive show-md th-breakdown">
        <div class="th-breakdown-row"><span>Published</span><span><?php echo (int)$pages_count; ?></span></div>
        <div class="th-breakdown-row"><span>Drafts</span><span><?php echo (int)( wp_count_posts('page')->draft ?? 0 ); ?></span></div>
        <div class="th-breakdown-row"><span>Trash</span><span><?php echo (int)( wp_count_posts('page')->trash ?? 0 ); ?></span></div>
      </div>

      <div class="th-adaptive show-lg th-mini-list">
        <div class="th-mini-list-label">Recent pages</div>
        <?php
          $recent_pages = get_posts(['post_type'=>'page','posts_per_page'=>4,'orderby'=>'modified','order'=>'DESC']);
          foreach($recent_pages as $rp):
        ?>
        <div class="th-mini-list-row">
          <span class="th-mini-list-name"><?php echo esc_html(wp_trim_words($rp->post_title,6)); ?></span>
          <span class="th-mini-list-meta"><?php echo esc_html(human_time_diff(strtotime($rp->post_modified))); ?></span>
        </div>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- Posts -->
    <div class="th-card" data-bento-id="stat-posts" data-size="<?php echo $size('stat-posts', 'xs'); ?>">
      <span class="th-size-picker"><button class="th-size-picker-btn" data-size-picker><?php echo therum_i('widgets'); ?></button><div class="th-size-picker-menu"></div></span>
      <span class="th-resize-handle" data-resize></span>
      <div class="th-card-head">
        <span class="th-card-label">Posts</span>
        <a class="th-card-link" href="<?php echo esc_url( admin_url( 'admin.php?page=therum-posts' ) ); ?>">View all →</a>
      </div>
      <div class="th-stat-val"><?php echo number_format_i18n( $posts_count ); ?></div>
      <div class="th-stat-sub">Published posts</div>

      <div class="th-adaptive show-sm">
        <?php echo therum_dashboard_sparkline_svg( therum_dashboard_sparkline_data( 'post' ) ); ?>
      </div>

      <div class="th-adaptive show-md th-breakdown">
        <div class="th-breakdown-row"><span>Published</span><span><?php echo (int)$posts_count; ?></span></div>
        <div class="th-breakdown-row"><span>Drafts</span><span><?php echo (int)( wp_count_posts()->draft ?? 0 ); ?></span></div>
        <div class="th-breakdown-row"><span>Pending</span><span><?php echo (int)( wp_count_posts()->pending ?? 0 ); ?></span></div>
      </div>

      <div class="th-adaptive show-lg th-mini-list">
        <div class="th-mini-list-label">Recent posts</div>
        <?php
          $recent_posts = get_posts(['posts_per_page'=>4,'orderby'=>'modified','order'=>'DESC']);
          foreach($recent_posts as $rp):
        ?>
        <div class="th-mini-list-row">
          <span class="th-mini-list-name"><?php echo esc_html(wp_trim_words($rp->post_title,6)); ?></span>
          <span class="th-mini-list-meta"><?php echo esc_html(human_time_diff(strtotime($rp->post_modified))); ?></span>
        </div>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- Products (Woo) -->
    <?php if ( $products_count !== null ): ?>
    <div class="th-card" data-bento-id="stat-products" data-size="<?php echo $size('stat-products', 'xs'); ?>">
      <span class="th-size-picker"><button class="th-size-picker-btn" data-size-picker><?php echo therum_i('widgets'); ?></button><div class="th-size-picker-menu"></div></span>
      <span class="th-resize-handle" data-resize></span>
      <div class="th-card-head">
        <span class="th-card-label">Products</span>
        <a class="th-card-link" href="<?php echo esc_url( admin_url( 'admin.php?page=therum-products' ) ); ?>">View all →</a>
      </div>
      <div class="th-stat-val"><?php echo number_format_i18n( $products_count ); ?></div>
      <div class="th-stat-sub">WooCommerce products</div>
    </div>
    <?php endif; ?>

    <!-- Users -->
    <div class="th-card" data-bento-id="stat-users" data-size="<?php echo $size('stat-users', 'xs'); ?>">
      <span class="th-size-picker"><button class="th-size-picker-btn" data-size-picker><?php echo therum_i('widgets'); ?></button><div class="th-size-picker-menu"></div></span>
      <span class="th-resize-handle" data-resize></span>
      <div class="th-card-head">
        <span class="th-card-label">Users</span>
        <a class="th-card-link" href="<?php echo esc_url( admin_url( 'admin.php?page=therum-users' ) ); ?>">View all →</a>
      </div>
      <div class="th-stat-val"><?php echo number_format_i18n( $users_count ); ?></div>
      <div class="th-stat-sub">Registered users</div>
    </div>

    <!-- Activity / Recent -->
    <div class="th-card" data-bento-id="activity" data-size="<?php echo $size('activity', 'md'); ?>">
      <span class="th-size-picker"><button class="th-size-picker-btn" data-size-picker><?php echo therum_i('widgets'); ?></button><div class="th-size-picker-menu"></div></span>
      <span class="th-resize-handle" data-resize></span>
      <div class="th-card-head">
        <span class="th-card-label">Recent activity</span>
      </div>
      <?php
      $recent = wp_get_recent_posts([ 'numberposts' => 5, 'post_status' => 'any', 'post_type' => ['post', 'page'] ]);
      if ( $recent ):
      ?>
      <div class="th-recent" style="border-top:none;padding-top:0;margin-top:0;">
        <?php foreach ( $recent as $r ):
          $author = get_userdata( $r['post_author'] );
          // current_time('timestamp') was deprecated in WP 5.3 — use the
          // GMT-aware integer the same way the docs recommend.
          $when = human_time_diff( strtotime( $r['post_modified'] ), (int) current_time( 'U' ) ) . ' ago';
        ?>
        <div class="th-recent-row">
          <span><?php echo esc_html( $r['post_title'] ?: '(untitled)' ); ?></span>
          <span><?php echo esc_html( ( $author->display_name ?? '?' ) . ' · ' . $when ); ?></span>
        </div>
        <?php endforeach; ?>
      </div>
      <?php else: ?>
      <p style="color:var(--tx3);font-size:13px;margin:8px 0 0;">No recent activity yet.</p>
      <?php endif; ?>
    </div>

    <!-- Site health (real, computed by therum_dashboard_health) -->
    <?php
      $health_color_map = [ 'good' => 'var(--ok)', 'warning' => 'var(--wrn)', 'critical' => 'var(--err)' ];
      $health_color = $health_color_map[ $health['status'] ] ?? 'var(--tx2)';
    ?>
    <div class="th-card" data-bento-id="health" data-size="<?php echo $size('health', 'xs'); ?>">
      <span class="th-size-picker"><button class="th-size-picker-btn" data-size-picker><?php echo therum_i('widgets'); ?></button><div class="th-size-picker-menu"></div></span>
      <span class="th-resize-handle" data-resize></span>
      <div class="th-card-head">
        <span class="th-card-label">Site health</span>
        <a class="th-card-link" href="<?php echo esc_url( admin_url( 'site-health.php' ) ); ?>">Details →</a>
      </div>
      <div class="th-stat-val" style="color:<?php echo esc_attr( $health_color ); ?>;"><?php echo esc_html( $health['label'] ); ?></div>
      <div class="th-stat-sub"><?php echo esc_html( $health['sub'] ); ?></div>

      <?php if ( ! empty( $health['detail'] ) ): ?>
      <div class="th-adaptive show-md th-breakdown">
        <?php foreach ( $health['detail'] as $line ): ?>
        <div class="th-breakdown-row"><span><?php echo esc_html( $line ); ?></span><span style="color:<?php echo esc_attr( $health_color ); ?>;">!</span></div>
        <?php endforeach; ?>
      </div>
      <div class="th-adaptive show-lg th-mini-list">
        <div class="th-mini-list-label">Issues</div>
        <?php foreach ( $health['detail'] as $line ): ?>
        <div class="th-mini-list-row">
          <span class="th-mini-list-name"><?php echo esc_html( $line ); ?></span>
          <span class="th-mini-list-meta" style="color:<?php echo esc_attr( $health_color ); ?>;">!</span>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>

  </div>
</div>
	<?php
}

// ─────────────────────────────────────────────────────────────────────────────
//  SHELL JS — bento drag/resize/reorder, theme toggle, sidebar collapse, iframe nav
// ─────────────────────────────────────────────────────────────────────────────
add_action( 'admin_enqueue_scripts', function() {
	if ( therum_is_frame() ) return;
	$path = __DIR__ . '/assets/therum-shell.js';
	$ver  = file_exists( $path ) ? filemtime( $path ) : null;
	wp_enqueue_script( 'therum-shell', plugins_url( 'assets/therum-shell.js', __FILE__ ), [], $ver, true );
	wp_localize_script( 'therum-shell', 'therumShellData', [
		'nonce'      => wp_create_nonce( 'therum_layout'  ),
		'themeNonce' => wp_create_nonce( 'therum_theme'   ),
		'sbNonce'    => wp_create_nonce( 'therum_sidebar' ),
		'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
	] );
} );


// ═════════════════════════════════════════════════════════════════════════════
//  CONSOLIDATED ADDITIONS — merged from prior patch files (2026-04-27)
//  Adds: shell dedupe (kills duplicate sidebars), width fixes (full-bleed content)
// ═════════════════════════════════════════════════════════════════════════════

// ─── Dedupe: kill duplicate #th-shell elements + move #wpbody-content inside ───
add_action( 'admin_footer', function() {
	if ( isset( $_GET['th_frame'] ) ) return;
	?>
<script id="therum-shell-dedupe">
(function() {
	'use strict';
	function dedupe() {
		var shells = document.querySelectorAll('#th-shell');
		if (shells.length > 1) {
			console.warn('[Therum] Found ' + shells.length + ' shells — keeping first');
			for (var i = 1; i < shells.length; i++) shells[i].parentNode.removeChild(shells[i]);
		}
		var content = document.getElementById('th-content');
		var wpbody  = document.getElementById('wpbody-content');
		if (content && wpbody && !content.contains(wpbody)) content.appendChild(wpbody);
	}
	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', dedupe);
	else dedupe();
	setTimeout(dedupe, 100);
})();
</script>
	<?php
}, 999 );

// ─── CSS fallback: hide any duplicate shells/sidebars ───

// ─── Width fix: settings + list pages span the full shell main column ───


// ════════════════════════════════════════════════════════════════════════
// GLOBAL ADMIN SKIN — absorbed from therum-global-admin-skin.php
// ════════════════════════════════════════════════════════════════════════

if ( ! defined( 'ABSPATH' ) ) exit;


// Global admin skin CSS — extracted to /assets/therum-global-admin-skin.css
// so the browser caches it once instead of inlining ~51KB into every admin page.
add_action( 'admin_enqueue_scripts', function() {
	$path = __DIR__ . '/assets/therum-global-admin-skin.css';
	$ver  = file_exists( $path ) ? filemtime( $path ) : '1.9.0';
	wp_enqueue_style(
		'therum-global-admin-skin',
		plugins_url( 'assets/therum-global-admin-skin.css', __FILE__ ),
		[],
		$ver
	);
}, 99 );


// Also enqueue the same skin on the login page (optional, but consistent)
add_action( 'login_head', function() {
	?>
<style>
:root {
	--th-ac: #e83b3b;
	--th-tx: #0a0a0a;
	--th-ff-d: 'Inter Tight', 'Inter', -apple-system, sans-serif;
	--th-ff-b: 'Inter', -apple-system, sans-serif;
}
body.login {
	background: #fafafa !important;
	font-family: var(--th-ff-b) !important;
}
body.login .button-primary {
	background: var(--th-tx) !important;
	border-color: var(--th-tx) !important;
	border-radius: 8px !important;
	font-family: var(--th-ff-d) !important;
	font-weight: 500 !important;
	letter-spacing: -0.005em !important;
	box-shadow: none !important;
	text-shadow: none !important;
}
</style>
	<?php
} );

// ════════════════════════════════════════════════════════════════════════
// NEXTBRICKS SKIN — absorbed from therum-nextbricks-skin.php
// ════════════════════════════════════════════════════════════════════════

add_action('admin_head', function() {
	$screen = get_current_screen();
	if ( ! $screen ) return;
	// Match NextBricks screens only — `next` alone collides with NextGEN Gallery,
	// nextpress, etc. Both `nextbricks` and `next-bricks` slugs have been seen.
	$id = (string) $screen->id;
	if ( strpos( $id, 'nextbricks' ) === false && strpos( $id, 'next-bricks' ) === false ) return;
	?>
	<style>
	/* NextBricks → Therum UI Override */
	:root {
		--nb-bg: #0a0a0a;
		--nb-bg2: #141414;
		--nb-border: rgba(255,255,255,0.08);
		--nb-text: #ffffff;
		--nb-text2: #999999;
		--nb-accent: #e83b3b;
		--nb-radius: 10px;
	}

	/* Main container */
	.next-ui-wrapper {
		background: var(--nb-bg) !important;
		color: var(--nb-text) !important;
		font-family: 'Inter', -apple-system, sans-serif !important;
	}

	/* Sidebar */
	.next-ui-sidebar {
		background: var(--nb-bg2) !important;
		border-right: 1px solid var(--nb-border) !important;
	}

	.next-ui-sidebar-item {
		color: var(--nb-text2) !important;
		border-radius: var(--nb-radius) !important;
		transition: all 0.2s ease !important;
	}

	.next-ui-sidebar-item:hover,
	.next-ui-sidebar-item.active {
		background: rgba(232,59,59,0.1) !important;
		color: var(--nb-accent) !important;
	}

	/* Category headers */
	.next-ui-category {
		color: var(--nb-text2) !important;
		font-size: 11px !important;
		font-weight: 600 !important;
		text-transform: uppercase !important;
		letter-spacing: 0.05em !important;
		margin-top: 24px !important;
	}

	/* Content area */
	.next-ui-content {
		background: var(--nb-bg) !important;
	}

	/* Search input */
	.next-ui-search input {
		background: var(--nb-bg2) !important;
		border: 1px solid var(--nb-border) !important;
		border-radius: var(--nb-radius) !important;
		color: var(--nb-text) !important;
		padding: 10px 16px !important;
		font-family: 'Inter', sans-serif !important;
	}

	.next-ui-search input:focus {
		border-color: var(--nb-accent) !important;
		outline: none !important;
		box-shadow: 0 0 0 3px rgba(232,59,59,0.1) !important;
	}

	/* Toggle switches */
	.next-ui-toggle {
		background: rgba(255,255,255,0.1) !important;
		border-radius: 999px !important;
	}

	.next-ui-toggle.active {
		background: var(--nb-accent) !important;
	}

	.next-ui-toggle-handle {
		background: #ffffff !important;
	}

	/* Element cards */
	.next-ui-element {
		background: var(--nb-bg2) !important;
		border: 1px solid var(--nb-border) !important;
		border-radius: var(--nb-radius) !important;
		color: var(--nb-text) !important;
		transition: all 0.2s ease !important;
	}

	.next-ui-element:hover {
		border-color: rgba(232,59,59,0.3) !important;
		background: rgba(232,59,59,0.05) !important;
	}

	/* Save button */
	.next-ui-save,
	button[type="submit"] {
		background: var(--nb-accent) !important;
		color: #ffffff !important;
		border: none !important;
		border-radius: var(--nb-radius) !important;
		padding: 10px 20px !important;
		font-weight: 600 !important;
		font-family: 'Inter', sans-serif !important;
		cursor: pointer !important;
		transition: all 0.2s ease !important;
	}

	.next-ui-save:hover,
	button[type="submit"]:hover {
		background: #c92e2e !important;
		box-shadow: 0 4px 12px rgba(232,59,59,0.3) !important;
	}

	/* License badge */
	.next-ui-license {
		color: var(--nb-accent) !important;
		font-weight: 600 !important;
	}

	/* Scrollbar */
	.next-ui-wrapper ::-webkit-scrollbar {
		width: 8px;
		height: 8px;
	}

	.next-ui-wrapper ::-webkit-scrollbar-track {
		background: var(--nb-bg2);
	}

	.next-ui-wrapper ::-webkit-scrollbar-thumb {
		background: rgba(255,255,255,0.2);
		border-radius: 4px;
	}

	.next-ui-wrapper ::-webkit-scrollbar-thumb:hover {
		background: rgba(255,255,255,0.3);
	}
	</style>
	<?php
});


// ═══════════════════════════════════════════════════════════════════════
// LIST PAGE ENGINE — merged from therum-list-page.php
// ═══════════════════════════════════════════════════════════════════════

// ─── GENERIC LIST PAGE ────────────────────────────────────────────────────────
//
// Therum_List_Page::render($config) emits the complete page header + toolbar +
// view container. Per-page renderers handle individual cards/rows. Filtering,
// search, sort, and view toggle are JS-only on top of pre-rendered DOM —
// each row/card carries `data-status`, `data-search` etc. attributes.

/**
 * Therum_Card_Style — central helper for thumbnail rendering across all list pages.
 * Used by Pages, Posts, and any other card-based list to render the thumbnail
 * background based on the user's preferred image source: gradient, featured,
 * stock (Picsum), or wireframe.
 */
therum_admin_require_page( 'therum-card-style.php' );

therum_admin_require_page( 'therum-list-page.php' );

// ─── PER-PAGE BUILDERS ────────────────────────────────────────────────────────
//
// Each builder:
//   1. Pulls real WP data
//   2. Builds the config (filter pill counts, action buttons, sort options)
//   3. Provides card + row renderers
//   4. Calls Therum_List_Page::render()

// Upper bound on rows a Therum list view will pull in one request. These views
// render every item into the DOM, so an unbounded query (posts_per_page => -1)
// would exhaust PHP memory on a large site. 2000 covers any realistic hand-
// curated content set while capping the worst case; override via the constant
// in wp-config.php if a site genuinely needs more.
if ( ! defined( 'THERUM_ADMIN_LIST_CAP' ) ) {
	define( 'THERUM_ADMIN_LIST_CAP', 2000 );
}

therum_admin_require_page( 'therum-pages-page.php' );

// ─── Case Studies ────────────────────────────────────────────────────────────
// Mirrors Therum_Pages_Page exactly but for the `case_study` CPT registered in
// therum-case-study-cpt.php. Reuses Therum_Pages_Page::render_card / render_row
// so the card markup matches Pages 1:1 — same chrome, same kebab, same picker.
therum_admin_require_page( 'therum-case-studies-page.php' );

therum_admin_require_page( 'therum-posts-page.php' );

therum_admin_require_page( 'therum-media-page.php' );

therum_admin_require_page( 'therum-users-page.php' );

/* ─────────────────────────────────────────────────────────────────────
 * BRICKS TEMPLATES — list page for bricks_template post type
 * Renders inline (not iframed) using the standard Therum_List_Page
 * ─────────────────────────────────────────────────────────────────── */
therum_admin_require_page( 'therum-templates-page.php' );

// Duplicate template handler
add_action('admin_post_therum_duplicate_template', function() {
	$post_id = isset($_GET['post']) ? (int) $_GET['post'] : 0;
	if (!$post_id || !current_user_can('edit_post', $post_id)) wp_die('Forbidden', 403);
	$nonce = sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ?? '' ) );
	if (!wp_verify_nonce($nonce, 'therum_dup_' . $post_id)) wp_die('Bad nonce', 400);
	$src = get_post($post_id);
	if (!$src || $src->post_type !== 'bricks_template') wp_die('Not found', 404);
	$new_id = wp_insert_post([
		'post_type'    => 'bricks_template',
		'post_status'  => 'draft',
		'post_title'   => $src->post_title . ' (copy)',
		'post_content' => $src->post_content,
		'post_author'  => get_current_user_id(),
	], true);
	if (is_wp_error($new_id)) wp_die($new_id->get_error_message());
	// Copy bricks-specific meta keys
	$keep = ['_bricks_template_type', '_bricks_page_content_2', '_bricks_page_header_2', '_bricks_page_footer_2', '_bricks_template_settings'];
	foreach ($keep as $key) {
		$v = get_post_meta($post_id, $key, true);
		if ($v !== '') update_post_meta($new_id, $key, $v);
	}
	wp_safe_redirect(admin_url('admin.php?page=therum-templates'));
	exit;
});

// Duplicate page/post handler
add_action('admin_post_therum_duplicate_post', function() {
	$post_id = isset($_GET['post']) ? (int) $_GET['post'] : 0;
	if (!$post_id || !current_user_can('edit_post', $post_id)) wp_die('Forbidden', 403);
	$nonce = sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ?? '' ) );
	if (!wp_verify_nonce($nonce, 'therum_dup_' . $post_id)) wp_die('Bad nonce', 400);
	$src = get_post($post_id);
	if (!$src) wp_die('Not found', 404);

	$new_id = wp_insert_post([
		'post_type'      => $src->post_type,
		'post_status'    => 'draft',
		'post_title'     => $src->post_title . ' (copy)',
		'post_content'   => $src->post_content,
		'post_excerpt'   => $src->post_excerpt,
		'post_author'    => get_current_user_id(),
		'post_parent'    => $src->post_parent,
		'menu_order'     => $src->menu_order,
		'comment_status' => $src->comment_status,
		'ping_status'    => $src->ping_status,
	], true);
	if (is_wp_error($new_id)) wp_die($new_id->get_error_message());

	// Copy all meta (Bricks elements, ACF fields, SEO, featured image, etc.)
	$skip = ['_edit_lock', '_edit_last', '_wp_old_slug', '_wp_old_date'];
	$meta = get_post_meta($post_id);
	foreach ($meta as $key => $vals) {
		if (in_array($key, $skip, true)) continue;
		// Clear Bricks template conditions on the duplicate to avoid conflicts
		if ($key === '_bricks_template_conditions') continue;
		foreach ($vals as $v) {
			add_post_meta($new_id, $key, maybe_unserialize($v));
		}
	}

	// Copy taxonomy assignments (categories, tags, custom taxonomies)
	$taxonomies = get_object_taxonomies($src->post_type);
	foreach ($taxonomies as $tax) {
		$terms = wp_get_object_terms($post_id, $tax, ['fields' => 'ids']);
		if (!is_wp_error($terms) && !empty($terms)) {
			wp_set_object_terms($new_id, $terms, $tax);
		}
	}

	// Redirect back to the correct Therum list page
	$type_map = [
		'page'         => 'therum-pages',
		'post'         => 'therum-posts',
		'case_study'   => 'therum-case-studies',
	];
	$dest = $type_map[$src->post_type] ?? 'therum-pages';
	wp_safe_redirect(admin_url('admin.php?page=' . $dest));
	exit;
});

therum_admin_require_page( 'therum-plugins-page.php' );

therum_admin_require_page( 'therum-plugin-detail-page.php' );

// ═════════════════════════════════════════════════════════════════════════════
//  UPDATES PAGE
// ═════════════════════════════════════════════════════════════════════════════

therum_admin_require_page( 'therum-updates-page.php' );
add_action( 'wp_ajax_therum_check_updates', [ 'Therum_Updates_Page', 'ajax_check' ] );


// ═════════════════════════════════════════════════════════════════════════════
//  CONNECTIONS PAGE
// ═════════════════════════════════════════════════════════════════════════════

therum_admin_require_page( 'therum-connections-page.php' );

add_action( 'wp_ajax_therum_connection_connect',       [ 'Therum_Connections_Page', 'ajax_connect' ] );
add_action( 'wp_ajax_therum_connection_test',          [ 'Therum_Connections_Page', 'ajax_test' ] );
add_action( 'wp_ajax_therum_connection_disconnect',    [ 'Therum_Connections_Page', 'ajax_disconnect' ] );
add_action( 'wp_ajax_therum_connection_add_custom',    [ 'Therum_Connections_Page', 'ajax_add_custom' ] );
add_action( 'wp_ajax_therum_connection_delete_custom', [ 'Therum_Connections_Page', 'ajax_delete_custom' ] );
add_action( 'wp_ajax_therum_oauth_app_save',        [ 'Therum_OAuth', 'ajax_save_app' ] );
add_action( 'admin_post_therum_oauth_start',        [ 'Therum_OAuth', 'start' ] );
add_action( 'rest_api_init', function() {
	register_rest_route( 'therum/v1', '/oauth/callback', [
		'methods'             => 'GET',
		'callback'            => [ 'Therum_OAuth', 'callback' ],
		'permission_callback' => '__return_true', // state token is the auth here
	] );
} );

// ════════════════════════════════════════════════════════════════════════════
//  Therum_OAuth — generic OAuth 2.0 authorization-code flow.
//
//  Works for ANY provider that has the following keys in its registry entry:
//    oauth_authorize_url  e.g. https://www.dropbox.com/oauth2/authorize
//    oauth_token_url      e.g. https://api.dropbox.com/oauth2/token
//    oauth_scope          space-separated scopes (provider-specific)
//
//  Per-install app credentials (client_id + client_secret) are entered once
//  by the admin via the Sign-in path's "Set up OAuth app" form — Therum
//  cannot ship hosted app credentials because (a) self-hosted installs need
//  their own redirect URIs registered with each provider and (b) sharing
//  client_secrets across customers would be a security mess. Therum can
//  add hosted credentials later as an opt-in for the SaaS edition.
//
//  Storage:
//    wp_options.therum_oauth_apps      [ provider_id => [client_id, client_secret(enc)] ]
//    wp_options.therum_connections     [ provider_id => existing schema, key holds access_token(enc) ]
//  CSRF: state nonce written to a 5-minute transient keyed by random uuid.
//
//  Redirect URI shape:
//    {site}/wp-json/therum/v1/oauth/callback?provider={id}
//  Admin must register this exact URL in the provider's app dashboard.
// ════════════════════════════════════════════════════════════════════════════
therum_admin_require_page( 'therum-oauth.php' );

/**
 * Public helper — call Anthropic Claude via the stored connector credential.
 * Returns the assistant text on success, or a WP_Error on failure.
 *
 * @param string $prompt   User message.
 * @param array  $args     Optional. Overrides for model, max_tokens, system.
 * @return string|\WP_Error
 *
 * @example
 *   $reply = therum_ask_claude( 'Summarize this page in one sentence.' );
 *   if ( is_wp_error( $reply ) ) { error_log( $reply->get_error_message() ); }
 *   else { echo $reply; }
 */
function therum_ask_claude( string $prompt, array $args = [] ) {
	$cred = Therum_Connections_Page::get_credential( 'anthropic' );
	if ( $cred['key'] === '' ) return new WP_Error( 'no_credential', 'No Anthropic credential — connect it under Connections → AI Tools first.' );

	$body = wp_parse_args( $args, [
		'model'      => 'claude-haiku-4-5-20251001',
		'max_tokens' => 1024,
		'messages'   => [ [ 'role' => 'user', 'content' => $prompt ] ],
	] );

	$res = wp_remote_post( 'https://api.anthropic.com/v1/messages', [
		'timeout'             => 30,
		// Cap inbound response size — a compromised credential routed to a
		// hostile mock endpoint could otherwise stream a huge body into PHP
		// memory. 5 MiB is well above any plausible legitimate completion.
		'limit_response_size' => 5 * 1024 * 1024,
		'sslverify'           => true,
		'headers' => [
			'x-api-key'         => $cred['key'],
			'anthropic-version' => '2023-06-01',
			'content-type'      => 'application/json',
		],
		'body' => wp_json_encode( $body ),
	] );
	if ( is_wp_error( $res ) ) return $res;
	$code = wp_remote_retrieve_response_code( $res );
	$data = json_decode( wp_remote_retrieve_body( $res ), true );
	if ( $code !== 200 ) {
		return new WP_Error( 'anthropic_' . $code, $data['error']['message'] ?? 'Anthropic ' . $code );
	}
	return (string) ( $data['content'][0]['text'] ?? '' );
}

/**
 * Public helper — call OpenAI ChatGPT via the stored connector credential.
 * Same contract as therum_ask_claude().
 */
function therum_ask_gpt( string $prompt, array $args = [] ) {
	$cred = Therum_Connections_Page::get_credential( 'openai' );
	if ( $cred['key'] === '' ) return new WP_Error( 'no_credential', 'No OpenAI credential — connect it under Connections → AI Tools first.' );

	$body = wp_parse_args( $args, [
		'model'      => 'gpt-4o-mini',
		'max_tokens' => 1024,
		'messages'   => [ [ 'role' => 'user', 'content' => $prompt ] ],
	] );

	$res = wp_remote_post( 'https://api.openai.com/v1/chat/completions', [
		'timeout'             => 30,
		'limit_response_size' => 5 * 1024 * 1024,
		'sslverify'           => true,
		'headers' => [
			'authorization' => 'Bearer ' . $cred['key'],
			'content-type'  => 'application/json',
		],
		'body' => wp_json_encode( $body ),
	] );
	if ( is_wp_error( $res ) ) return $res;
	$code = wp_remote_retrieve_response_code( $res );
	$data = json_decode( wp_remote_retrieve_body( $res ), true );
	if ( $code !== 200 ) {
		return new WP_Error( 'openai_' . $code, $data['error']['message'] ?? 'OpenAI ' . $code );
	}
	return (string) ( $data['choices'][0]['message']['content'] ?? '' );
}

// Connection connect/disconnect auditing now lives inside the authorized
// Therum_Connections_Page::ajax_connect / ajax_disconnect handlers (see
// audit_log()). The old priority-20 wp_ajax closures were removed: they ran
// without their own nonce/capability checks AND were unreachable anyway, since
// the priority-10 handlers terminate the request via wp_send_json_*.


// ─── REGISTRATION ─────────────────────────────────────────────────────────────

add_action('admin_menu', function() {
	// Hidden submenu pages (parent = null) — they get a URL but no menu placement.
	add_submenu_page(null, 'Pages',         'Pages',         'edit_pages',     'therum-pages',         ['Therum_Pages_Page',         'render']);
	add_submenu_page(null, 'Posts',         'Posts',         'edit_posts',     'therum-posts',         ['Therum_Posts_Page',         'render']);
	add_submenu_page(null, 'Case Studies',  'Case Studies',  'edit_posts',     'therum-case-studies',  ['Therum_Case_Studies_Page',  'render']);
	add_submenu_page(null, 'Media',         'Media',         'upload_files',   'therum-media',         ['Therum_Media_Page',         'render']);
	add_submenu_page(null, 'Users',         'Users',         'list_users',     'therum-users',         ['Therum_Users_Page',         'render']);
	add_submenu_page(null, 'Plugins',       'Plugins',       'activate_plugins','therum-plugins',      ['Therum_Plugins_Page',       'render']);
	add_submenu_page(null, 'Plugin',        'Plugin',        'activate_plugins','therum-plugin-detail',['Therum_Plugin_Detail_Page', 'render']);
	add_submenu_page(null, 'Updates',       'Updates',       'manage_options',  'therum-updates',      ['Therum_Updates_Page',       'render']);
	add_submenu_page(null, 'Connections',   'Connections',   'manage_options',  'therum-connections',  ['Therum_Connections_Page',   'render']);
	add_submenu_page(null, 'Studio',        'Studio',        'manage_options',  'therum-studio',       ['Therum_Studio_Page',         'render']);
});

/* ─────────────────────────────────────────────────────────────────────────────
 * THERUM STUDIO — discovery surface for Therum-built modules / spin-off plugins
 *
 * Curated showcase for plugins built by Therum Creative Studios. Each "app" is
 * either:
 *   - BUILT-IN: ships inside Therum OS itself (e.g. Nexus, the Connections
 *     layer). Renders with an "Included" badge and an Open CTA.
 *   - INSTALLABLE: a standalone plugin available from a TherumCs/* GitHub repo.
 *     Renders with status (Not installed / Installed / Active / Update
 *     available) and a contextual CTA (Install / Activate / Update / Open).
 *
 * Registry is hardcoded for now (small, audited list, edits in source). When
 * the list outgrows that, this lifts to a JSON in TherumCs/registry that the
 * page fetches + caches.
 * ───────────────────────────────────────────────────────────────────────── */
therum_admin_require_page( 'therum-studio-page.php' );

/**
 * Studio module installer — downloads the latest GitHub release ZIP for the
 * requested module and runs WP's Plugin_Upgrader on it. Real install, no
 * placeholder. Buttons in Therum_Studio_Page::render() invoke this via AJAX.
 */
add_action( 'wp_ajax_therum_studio_install', function() {
	if ( ! current_user_can( 'install_plugins' ) ) {
		wp_send_json_error( [ 'message' => 'Insufficient permissions.' ], 403 );
	}
	if ( ! check_ajax_referer( 'therum_studio_install', '_nonce', false ) ) {
		wp_send_json_error( [ 'message' => 'Invalid request (nonce).' ], 403 );
	}

	$slug = sanitize_key( $_POST['slug'] ?? '' );
	if ( ! $slug ) wp_send_json_error( [ 'message' => 'Missing slug.' ] );

	$app = null;
	foreach ( Therum_Studio_Page::apps() as $a ) {
		if ( ( $a['slug'] ?? '' ) === $slug ) { $app = $a; break; }
	}
	if ( ! $app ) wp_send_json_error( [ 'message' => 'Unknown module.' ] );
	if ( ! empty( $app['built_in'] ) ) wp_send_json_error( [ 'message' => 'Built-in modules cannot be reinstalled.' ] );
	if ( empty( $app['repo'] ) )      wp_send_json_error( [ 'message' => 'No source repository configured.' ] );

	$api  = 'https://api.github.com/repos/' . $app['repo'] . '/releases/latest';
	$resp = wp_remote_get( $api, [
		'timeout' => 25,
		'headers' => [ 'Accept' => 'application/vnd.github+json', 'User-Agent' => 'Therum-OS' ],
	] );
	if ( is_wp_error( $resp ) ) {
		wp_send_json_error( [ 'message' => 'GitHub fetch failed: ' . $resp->get_error_message() ] );
	}
	$code = (int) wp_remote_retrieve_response_code( $resp );
	if ( $code !== 200 ) {
		wp_send_json_error( [ 'message' => 'GitHub returned HTTP ' . $code . '. The repo may be private or have no releases yet.' ] );
	}
	$rel = json_decode( wp_remote_retrieve_body( $resp ), true );
	if ( ! is_array( $rel ) ) wp_send_json_error( [ 'message' => 'Bad release payload from GitHub.' ] );

	// Prefer a real .zip asset; fall back to GitHub's auto-generated zipball.
	$zip = '';
	foreach ( ( $rel['assets'] ?? [] ) as $asset ) {
		$name = strtolower( (string) ( $asset['name'] ?? '' ) );
		$url  = (string) ( $asset['browser_download_url'] ?? '' );
		if ( $url && substr( $name, -4 ) === '.zip' ) { $zip = $url; break; }
	}
	if ( ! $zip ) $zip = (string) ( $rel['zipball_url'] ?? '' );
	if ( ! $zip ) wp_send_json_error( [ 'message' => 'No installable asset found in the latest release.' ] );

	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/misc.php';
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
	require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
	require_once ABSPATH . 'wp-admin/includes/class-plugin-upgrader.php';

	if ( ! class_exists( 'WP_Ajax_Upgrader_Skin' ) ) {
		require_once ABSPATH . 'wp-admin/includes/class-wp-ajax-upgrader-skin.php';
	}

	$skin     = new WP_Ajax_Upgrader_Skin();
	$upgrader = new Plugin_Upgrader( $skin );
	$result   = $upgrader->install( $zip );

	if ( is_wp_error( $result ) ) wp_send_json_error( [ 'message' => $result->get_error_message() ] );
	if ( is_wp_error( $skin->result ) ) wp_send_json_error( [ 'message' => $skin->result->get_error_message() ] );
	if ( $skin->get_errors()->has_errors() ) wp_send_json_error( [ 'message' => $skin->get_error_messages() ] );
	if ( ! $result ) wp_send_json_error( [ 'message' => 'Install failed for an unknown reason.' ] );

	wp_send_json_success( [ 'message' => ( $app['name'] ?? 'Module' ) . ' installed.' ] );
} );

/**
 * Studio module toggle — flips the option key declared on the module's
 * registry entry. Separate from the install handler because modules ship
 * inside Therum OS; they're feature flags, not downloads.
 *
 * Body params: slug (module slug), toggle ('enable'|'disable').
 */
add_action( 'wp_ajax_therum_studio_module_toggle', function() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( [ 'message' => 'Insufficient permissions.' ], 403 );
	}
	if ( ! check_ajax_referer( 'therum_studio_module_toggle', '_nonce', false ) ) {
		wp_send_json_error( [ 'message' => 'Invalid request (nonce).' ], 403 );
	}

	$slug   = sanitize_key( wp_unslash( $_POST['slug'] ?? '' ) );
	$toggle = sanitize_key( wp_unslash( $_POST['toggle'] ?? '' ) );
	if ( ! $slug )                                    wp_send_json_error( [ 'message' => 'Missing slug.' ] );
	if ( ! in_array( $toggle, [ 'enable', 'disable' ], true ) ) wp_send_json_error( [ 'message' => 'Invalid toggle.' ] );

	if ( ! class_exists( 'Therum_Studio_Page' ) ) wp_send_json_error( [ 'message' => 'Studio registry unavailable.' ] );
	$apps = Therum_Studio_Page::apps();
	$app  = null;
	foreach ( $apps as $a ) {
		if ( ( $a['slug'] ?? '' ) === $slug ) { $app = $a; break; }
	}
	if ( ! $app || empty( $app['module'] ) || empty( $app['option'] ) ) {
		wp_send_json_error( [ 'message' => 'Not a toggleable module.' ] );
	}

	$enabled = ( $toggle === 'enable' ) ? '1' : '';
	update_option( $app['option'], $enabled, false );

	// Module-specific side effects: refresh rewrite rules for modules that
	// register a CPT (the new post-type-archive permalinks need to land in
	// WP's compiled rules cache).
	if ( $slug === 'case-studies' ) {
		flush_rewrite_rules( false );
	}

	wp_send_json_success( [ 'message' => $app['name'] . ' ' . $toggle . 'd.', 'state' => $toggle === 'enable' ? 'enabled' : 'disabled' ] );
} );

// Repoint sidebar nav at the new Therum list pages.
// Keys are CASE-INSENSITIVE on the label. The sidebar URL slugs are passed to
// `match` so the sidebar's active-state highlighter still resolves correctly.
add_filter('therum_admin_nav_items', function(array $items): array {
	$swap = [
		'pages'         => ['url' => 'admin.php?page=therum-pages',         'match' => 'page=therum-pages'],
		'posts'         => ['url' => 'admin.php?page=therum-posts',         'match' => 'page=therum-posts'],
		'case studies'  => ['url' => 'admin.php?page=therum-case-studies',  'match' => 'page=therum-case-studies'],
		'media'         => ['url' => 'admin.php?page=therum-media',         'match' => 'page=therum-media'],
		'users'         => ['url' => 'admin.php?page=therum-users',         'match' => 'page=therum-users'],
		'plugins'       => ['url' => 'admin.php?page=therum-plugins',       'match' => 'page=therum-plugins'],
	];
	foreach ($items as &$section) {
		if (!isset($section['items'])) continue;
		foreach ($section['items'] as &$it) {
			$key = strtolower((string) ($it['label'] ?? ''));
			if (isset($swap[$key])) {
				$it['url']   = $swap[$key]['url'];
				$it['match'] = $swap[$key]['match'];
				unset($it['drill'], $it['drill_type']);
			}
		}
		unset($it);
	}
	unset($section);
	return $items;
});

// ─── CSS (inline, emitted on every admin page) ────────────────────────────────

add_action( 'admin_enqueue_scripts', function() {
	$path = __DIR__ . '/assets/therum-list-page.css';
	$ver  = file_exists( $path ) ? filemtime( $path ) : null;
	wp_enqueue_style( 'therum-list-page', plugins_url( 'assets/therum-list-page.css', __FILE__ ), [], $ver );
} );

// ─── JS (inline) ──────────────────────────────────────────────────────────────

add_action( 'admin_enqueue_scripts', function() {
	$path = __DIR__ . '/assets/therum-list-page.js';
	$ver  = file_exists( $path ) ? filemtime( $path ) : null;
	wp_enqueue_script( 'therum-list-page', plugins_url( 'assets/therum-list-page.js', __FILE__ ), [], $ver, true );
} );



// ═════════════════════════════════════════════════════════════════════════════
//  CONSOLIDATED ADDITIONS — merged from prior patch files (2026-04-27)
//  Adds: density slider (3-7) + Grid/Masonry/List view toggle for Media page,
//        plus per-user view preference persistence.
// ═════════════════════════════════════════════════════════════════════════════

//  AJAX — persist per-user view preferences (theme view mode, media density+view)
// ─────────────────────────────────────────────────────────────────────────────
add_action( 'wp_ajax_therum_save_pref', function() {
	if ( ! current_user_can( 'read' ) ) wp_send_json_error( 'forbidden', 403 );
	check_ajax_referer( 'therum_pref', 'nonce' );

	$key = sanitize_key( $_POST['key'] ?? '' );
	$val = sanitize_text_field( wp_unslash( $_POST['value'] ?? '' ) );

	$allowed = [
		'theme_view_mode',     // 'cards' | 'tiles'
		'media_view_mode',     // 'grid' | 'masonry' | 'list'
		'media_density',       // 3-7
	];
	if ( ! in_array( $key, $allowed, true ) ) wp_send_json_error( 'unknown key' );

	update_user_meta( get_current_user_id(), 'therum_pref_' . $key, $val );
	wp_send_json_success();
} );

function therum_pref( $key, $default = '' ) {
	$v = get_user_meta( get_current_user_id(), 'therum_pref_' . $key, true );
	return $v !== '' ? $v : $default;
}

// ═════════════════════════════════════════════════════════════════════════════
//  MEDIA LIBRARY · download-all-as-ZIP endpoint
//
//  Hits at admin-ajax.php?action=therum_media_download_zip (nonced). Streams
//  every attachment file in wp-content/uploads into a single ZIP. Built with
//  ZipArchive::OVERWRITE on a temp file, then sent via readfile in chunks
//  so a 5GB library doesn't OOM the request. Manifest.txt at the root maps
//  each file to its WP attachment ID for re-import bookkeeping.
// ═════════════════════════════════════════════════════════════════════════════
add_action( 'wp_ajax_therum_media_download_zip', function() {
	if ( ! current_user_can( 'upload_files' ) ) wp_die( 'Forbidden', 403 );
	check_admin_referer( 'therum_media_zip' );
	if ( ! class_exists( 'ZipArchive' ) ) wp_die( 'PHP ZipArchive extension is required to download the media library.' );

	// Guard rails so a large library can't build a runaway temp file or hang the
	// request: cap both the number of files and the total uncompressed bytes.
	// Override either via wp-config.php. Defaults: 5000 files / 2 GB.
	$max_files = defined( 'THERUM_MEDIA_ZIP_MAX_FILES' ) ? (int) THERUM_MEDIA_ZIP_MAX_FILES : 5000;
	$max_bytes = defined( 'THERUM_MEDIA_ZIP_MAX_BYTES' ) ? (int) THERUM_MEDIA_ZIP_MAX_BYTES : 2 * 1024 * 1024 * 1024;

	$ids = get_posts( [
		'post_type'      => 'attachment',
		'post_status'    => 'inherit',
		'posts_per_page' => $max_files,
		'fields'         => 'ids',
		'orderby'        => 'date',
		'order'          => 'DESC',
	] );
	if ( empty( $ids ) ) wp_die( 'Media library is empty.' );

	$upload  = wp_upload_dir();
	$tmp_zip = trailingslashit( get_temp_dir() ) . 'therum-media-' . wp_generate_uuid4() . '.zip';
	$zip     = new ZipArchive();
	if ( $zip->open( $tmp_zip, ZipArchive::CREATE | ZipArchive::OVERWRITE ) !== true ) wp_die( 'Could not open temp zip.' );

	$manifest  = [ '# Therum media library export — ' . gmdate( 'c' ) ];
	$added     = 0;
	$bytes     = 0;
	$truncated = false;
	$basedir   = trailingslashit( (string) ( $upload['basedir'] ?? '' ) );

	foreach ( $ids as $id ) {
		$file = get_attached_file( (int) $id );
		if ( ! $file || ! file_exists( $file ) ) continue;
		$size = (int) filesize( $file );
		// Stop before we blow the byte budget, but always allow at least one file
		// so a single oversized asset still produces a usable (if partial) export.
		if ( $added > 0 && ( $bytes + $size ) > $max_bytes ) {
			$truncated = true;
			break;
		}
		$rel = $basedir && str_starts_with( $file, $basedir ) ? substr( $file, strlen( $basedir ) ) : basename( $file );
		if ( $zip->addFile( $file, $rel ) ) {
			$manifest[] = $id . "\t" . $rel . "\t" . $size;
			$added++;
			$bytes += $size;
		}
	}
	if ( $truncated || count( $ids ) >= $max_files ) {
		$manifest[] = '# NOTE: export truncated at ' . $added . ' files / '
			. size_format( $bytes ) . ' (limit ' . $max_files . ' files / '
			. size_format( $max_bytes ) . '). Raise THERUM_MEDIA_ZIP_MAX_FILES / '
			. 'THERUM_MEDIA_ZIP_MAX_BYTES in wp-config.php to include more.';
	}
	$zip->addFromString( 'manifest.txt', implode( "\n", $manifest ) . "\n" );
	$zip->close();

	if ( $added === 0 ) {
		@unlink( $tmp_zip );
		wp_die( 'No files were available to add to the archive.' );
	}

	$filename = 'therum-media-' . gmdate( 'Y-m-d' ) . '.zip';
	nocache_headers();
	header( 'Content-Type: application/zip' );
	header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
	header( 'Content-Length: ' . filesize( $tmp_zip ) );
	header( 'X-Therum-Files: ' . $added );
	if ( $truncated ) header( 'X-Therum-Truncated: 1' );
	while ( ob_get_level() ) ob_end_clean();
	readfile( $tmp_zip );
	@unlink( $tmp_zip );
	exit;
});


// ═════════════════════════════════════════════════════════════════════════════
//  CONTENT EXPORT — multi-format export for pages, posts, case studies, CPTs
//
//  Formats: json (full data + meta), txt (plain text), md (Markdown),
//           pdf (formatted), bricks (Bricks-native element JSON per page).
//
//  URL: admin-ajax.php?action=therum_export_content&post_type=page&format=json
// ═════════════════════════════════════════════════════════════════════════════

/**
 * Build an export action button config with data attrs for the format picker JS.
 * The JS intercepts the click and shows a small dropdown of format options.
 */
function therum_export_button( string $post_type ): array {
	return [
		'label' => 'Export',
		'icon'  => 'export',
		'href'  => '#',
		'attrs' => [
			'data-th-export'    => $post_type,
			'data-th-export-base' => wp_nonce_url( admin_url( 'admin-ajax.php?action=therum_export_content&post_type=' . $post_type ), 'therum_export' ),
		],
	];
}

/**
 * Collect all posts of a type into a structured array. Shared by all formats.
 */
function therum_export_collect( string $post_type ): array {
	// no_found_rows skips the COUNT() pagination query — we never need it for
	// an export. suppress_filters keeps third-party plugins from injecting
	// per-row filters that would multiply the work. The export still loads
	// the full post objects, but exporting is intentionally exhaustive — the
	// upper bound here is the safety cap below.
	return get_posts( [
		'post_type'              => $post_type,
		'post_status'            => [ 'publish', 'draft', 'future', 'pending', 'private' ],
		'posts_per_page'         => (int) apply_filters( 'therum/export/max_posts', 5000, $post_type ),
		'orderby'                => 'date',
		'order'                  => 'DESC',
		'no_found_rows'          => true,
		'update_post_term_cache' => false,
		'update_post_meta_cache' => false,
	] );
}

/**
 * Build the full structured item data for one post (used by JSON + Bricks).
 */
function therum_export_item( WP_Post $p, string $post_type ): array {
	$item = [
		'ID'             => $p->ID,
		'title'          => $p->post_title,
		'slug'           => $p->post_name,
		'status'         => $p->post_status,
		'date'           => $p->post_date,
		'date_gmt'       => $p->post_date_gmt,
		'modified'       => $p->post_modified,
		'content'        => $p->post_content,
		'excerpt'        => $p->post_excerpt,
		'author'         => get_the_author_meta( 'display_name', $p->post_author ),
		'template'       => get_page_template_slug( $p->ID ) ?: '',
		'menu_order'     => $p->menu_order,
		'parent'         => $p->post_parent,
		'featured_image' => '',
		'taxonomies'     => [],
		'meta'           => [],
	];

	$thumb_id = get_post_thumbnail_id( $p->ID );
	if ( $thumb_id ) {
		$item['featured_image'] = wp_get_attachment_url( $thumb_id ) ?: '';
	}

	$taxos = get_object_taxonomies( $post_type, 'objects' );
	foreach ( $taxos as $tax_slug => $tax_obj ) {
		$terms = wp_get_object_terms( $p->ID, $tax_slug, [ 'fields' => 'names' ] );
		if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
			$item['taxonomies'][ $tax_slug ] = $terms;
		}
	}

	$all_meta = get_post_meta( $p->ID );
	foreach ( $all_meta as $key => $values ) {
		if ( str_starts_with( $key, '_edit_' ) || $key === '_wp_old_slug' ) continue;
		$item['meta'][ $key ] = count( $values ) === 1 ? $values[0] : $values;
	}

	return $item;
}

/**
 * Stream the export in the requested format.
 */
add_action( 'wp_ajax_therum_export_content', function() {
	if ( ! current_user_can( 'edit_posts' ) ) wp_die( 'Forbidden', 403 );
	check_admin_referer( 'therum_export' );

	$post_type = sanitize_key( $_GET['post_type'] ?? 'post' );
	$format    = sanitize_key( $_GET['format']    ?? 'json' );
	$pto       = get_post_type_object( $post_type );
	if ( ! $pto ) wp_die( 'Unknown post type: ' . esc_html( $post_type ) );

	$posts    = therum_export_collect( $post_type );
	$date_str = gmdate( 'Y-m-d' );
	$base     = 'therum-' . $post_type . '-export-' . $date_str;

	nocache_headers();
	while ( ob_get_level() ) ob_end_clean();

	// ── JSON (full structured data) ──────────────────────────────────────
	if ( $format === 'json' ) {
		$export = [
			'generator'  => 'Therum OS Content Export',
			'site_url'   => home_url(),
			'exported_at'=> gmdate( 'c' ),
			'post_type'  => $post_type,
			'count'      => count( $posts ),
			'items'      => array_map( fn( $p ) => therum_export_item( $p, $post_type ), $posts ),
		];
		$json = wp_json_encode( $export, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $base . '.json"' );
		header( 'Content-Length: ' . strlen( $json ) );
		echo $json;
		exit;
	}

	// ── TXT (plain text) ─────────────────────────────────────────────────
	if ( $format === 'txt' ) {
		$lines = [];
		$lines[] = strtoupper( $pto->labels->name ?? $post_type ) . ' EXPORT — ' . home_url() . ' — ' . $date_str;
		$lines[] = str_repeat( '=', 72 );
		$lines[] = '';
		foreach ( $posts as $p ) {
			$lines[] = $p->post_title;
			$lines[] = str_repeat( '-', mb_strlen( $p->post_title ) );
			$lines[] = 'Status: ' . $p->post_status . '  |  Date: ' . $p->post_date . '  |  Slug: /' . $p->post_name;
			$lines[] = '';
			$content = wp_strip_all_tags( $p->post_content );
			$content = html_entity_decode( $content, ENT_QUOTES, 'UTF-8' );
			$content = preg_replace( '/\n{3,}/', "\n\n", trim( $content ) );
			if ( $content ) {
				$lines[] = $content;
			} else {
				$lines[] = '(no content)';
			}
			$lines[] = '';
			$lines[] = str_repeat( '=', 72 );
			$lines[] = '';
		}
		$txt = implode( "\n", $lines );
		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $base . '.txt"' );
		header( 'Content-Length: ' . strlen( $txt ) );
		echo $txt;
		exit;
	}

	// ── MD (Markdown) ────────────────────────────────────────────────────
	if ( $format === 'md' ) {
		$lines = [];
		$lines[] = '# ' . ( $pto->labels->name ?? $post_type ) . ' Export';
		$lines[] = '';
		$lines[] = '> ' . home_url() . ' — exported ' . $date_str;
		$lines[] = '';
		foreach ( $posts as $p ) {
			$lines[] = '---';
			$lines[] = '';
			$lines[] = '## ' . $p->post_title;
			$lines[] = '';
			$lines[] = '| Field | Value |';
			$lines[] = '|-------|-------|';
			$lines[] = '| Status | ' . $p->post_status . ' |';
			$lines[] = '| Date | ' . $p->post_date . ' |';
			$lines[] = '| Slug | `/' . $p->post_name . '` |';
			$thumb = get_post_thumbnail_id( $p->ID ) ? wp_get_attachment_url( get_post_thumbnail_id( $p->ID ) ) : '';
			if ( $thumb ) $lines[] = '| Featured image | ' . $thumb . ' |';

			$taxos = get_object_taxonomies( $post_type );
			foreach ( $taxos as $tax ) {
				$terms = wp_get_object_terms( $p->ID, $tax, [ 'fields' => 'names' ] );
				if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
					$lines[] = '| ' . $tax . ' | ' . implode( ', ', $terms ) . ' |';
				}
			}
			$lines[] = '';

			// Convert HTML content to basic Markdown
			$html = $p->post_content;
			$md = $html;
			$md = preg_replace( '/<h([1-6])[^>]*>(.*?)<\/h[1-6]>/si', "\n" . '$0', $md );
			$md = preg_replace( '/<h1[^>]*>(.*?)<\/h1>/si', '### $1', $md );
			$md = preg_replace( '/<h2[^>]*>(.*?)<\/h2>/si', '#### $1', $md );
			$md = preg_replace( '/<h3[^>]*>(.*?)<\/h3>/si', '##### $1', $md );
			$md = preg_replace( '/<h[4-6][^>]*>(.*?)<\/h[4-6]>/si', '###### $1', $md );
			$md = preg_replace( '/<strong[^>]*>(.*?)<\/strong>/si', '**$1**', $md );
			$md = preg_replace( '/<b[^>]*>(.*?)<\/b>/si', '**$1**', $md );
			$md = preg_replace( '/<em[^>]*>(.*?)<\/em>/si', '*$1*', $md );
			$md = preg_replace( '/<i[^>]*>(.*?)<\/i>/si', '*$1*', $md );
			$md = preg_replace( '/<a[^>]+href="([^"]*)"[^>]*>(.*?)<\/a>/si', '[$2]($1)', $md );
			$md = preg_replace( '/<li[^>]*>(.*?)<\/li>/si', '- $1', $md );
			$md = preg_replace( '/<img[^>]+src="([^"]*)"[^>]*>/si', '![]($1)', $md );
			$md = wp_strip_all_tags( $md );
			$md = html_entity_decode( $md, ENT_QUOTES, 'UTF-8' );
			$md = preg_replace( '/\n{3,}/', "\n\n", trim( $md ) );

			if ( $md ) {
				$lines[] = $md;
			} else {
				$lines[] = '*(no content)*';
			}
			$lines[] = '';
		}
		$out = implode( "\n", $lines );
		header( 'Content-Type: text/markdown; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $base . '.md"' );
		header( 'Content-Length: ' . strlen( $out ) );
		echo $out;
		exit;
	}

	// ── BRICKS (native Bricks element JSON per page, bundled as ZIP) ─────
	if ( $format === 'bricks' ) {
		if ( ! class_exists( 'ZipArchive' ) ) wp_die( 'PHP ZipArchive extension is required.' );

		$tmp_zip = trailingslashit( get_temp_dir() ) . 'therum-bricks-' . wp_generate_uuid4() . '.zip';
		$zip     = new ZipArchive();
		if ( $zip->open( $tmp_zip, ZipArchive::CREATE | ZipArchive::OVERWRITE ) !== true ) wp_die( 'Could not create temp zip.' );

		$global_classes  = get_option( 'bricks_global_classes', [] );
		$global_vars     = get_option( 'bricks_global_variables', [] );
		$added = 0;

		foreach ( $posts as $p ) {
			$bricks_data = get_post_meta( $p->ID, BRICKS_DB_PAGE_CONTENT, true );
			if ( ! is_array( $bricks_data ) || empty( $bricks_data ) ) continue;

			// Build Bricks-native export shape (matches Bricks' own template export)
			$template_data = [
				'source'          => 'bricksCopiedElements',
				'sourceUrl'       => home_url(),
				'version'         => defined( 'BRICKS_VERSION' ) ? BRICKS_VERSION : '1.0',
				'content'         => $bricks_data,
				'templateName'    => $p->post_title,
				'post_type'       => $post_type,
			];

			// Collect global classes used in this page's elements
			$used_class_ids = [];
			foreach ( $bricks_data as $el ) {
				if ( ! empty( $el['settings']['_cssGlobalClasses'] ) ) {
					$used_class_ids = array_merge( $used_class_ids, $el['settings']['_cssGlobalClasses'] );
				}
			}
			$used_class_ids = array_unique( $used_class_ids );
			if ( $used_class_ids && is_array( $global_classes ) ) {
				$page_classes = array_filter( $global_classes, fn( $c ) => in_array( $c['id'] ?? '', $used_class_ids, true ) );
				if ( $page_classes ) $template_data['globalClasses'] = array_values( $page_classes );
			}

			if ( ! empty( $global_vars ) ) {
				$template_data['globalVariables'] = $global_vars;
			}

			$slug     = $p->post_name ?: sanitize_title( $p->post_title );
			$filename = $slug . '.json';
			$zip->addFromString( $filename, wp_json_encode( $template_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
			$added++;
		}

		$zip->close();

		if ( $added === 0 ) {
			@unlink( $tmp_zip );
			wp_die( 'No Bricks content found to export — pages may use the classic editor.' );
		}

		$zip_name = $base . '-bricks.zip';
		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: attachment; filename="' . $zip_name . '"' );
		header( 'Content-Length: ' . filesize( $tmp_zip ) );
		readfile( $tmp_zip );
		@unlink( $tmp_zip );
		exit;
	}

	wp_die( 'Unknown export format: ' . esc_html( $format ) );
} );


// ═════════════════════════════════════════════════════════════════════════════
//  PART 1 — MEDIA LIBRARY: density slider + grid/masonry/metro/list views
//  Patches the Therum_Media_Page toolbar with extended view controls.
//  Targets the actual rendered DOM (.th-lp-views, .th-lp-view-grid).
// ═════════════════════════════════════════════════════════════════════════════
add_action( 'admin_enqueue_scripts', function() {
	$page = $_GET['page'] ?? '';
	if ( $page !== 'therum-media' ) return;
	$path = __DIR__ . '/assets/therum-list-media-patch.css';
	$ver  = file_exists( $path ) ? filemtime( $path ) : null;
	wp_enqueue_style( 'therum-list-media-patch', plugins_url( 'assets/therum-list-media-patch.css', __FILE__ ), [ 'therum-list-page' ], $ver );
} );

add_action( 'admin_footer', function() {
	$page = $_GET['page'] ?? '';
	if ( $page !== 'therum-media' ) return;
	$nonce = wp_create_nonce( 'therum_pref' );
	$density = (int) therum_pref( 'media_density', 5 );
	$view    = therum_pref( 'media_view_mode', 'grid' );
	?>
<script id="therum-media-patch-js">
(function() {
	'use strict';
	// Toolbar (density slider + view buttons + extra panes) is now rendered
	// server-side by Therum_List_Page::render() — see the density_slider /
	// extra_views config in the media renderer. This script only wires up
	// click + input behavior so the controls actually do something.
	var nonce       = '<?php echo esc_js( $nonce ); ?>';
	var ajaxUrl     = window.ajaxurl || '/wp-admin/admin-ajax.php';
	var viewsCont   = document.querySelector('.th-lp-views');
	var density     = document.querySelector('.th-density-control');
	var gridPane    = document.querySelector('.th-lp-view-grid');
	if (!viewsCont) return; // toolbar gone → nothing to wire

	function savePref(key, value) {
		var fd = new FormData();
		fd.append('action', 'therum_save_pref');
		fd.append('key', key);
		fd.append('value', value);
		fd.append('nonce', nonce);
		fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd });
	}

	function setActiveView(v) {
		document.querySelectorAll('[data-view-pane]').forEach(function(p){ p.classList.toggle('active', p.dataset.viewPane === v); });
		viewsCont.querySelectorAll('.th-lp-view-btn').forEach(function(b){ b.classList.toggle('active', b.dataset.view === v); });
		if (density) density.dataset.view = v;
	}

	// View toggle — single delegated click handler covers grid, masonry, metro, table, and any future extras.
	viewsCont.addEventListener('click', function(e) {
		var btn = e.target.closest('.th-lp-view-btn');
		if (!btn) return;
		var v = btn.dataset.view;
		if (!v) return;
		// Intercept on capture so the base list-page handler doesn't double-toggle.
		e.stopImmediatePropagation();
		setActiveView(v);
		savePref('media_view_mode', v);
	}, true);

	// Density slider — live grid-template-columns swap + debounced save.
	var slider  = document.getElementById('th-density-slider');
	var valueEl = document.getElementById('th-density-value');
	if (slider && gridPane) {
		var saveTimer;
		slider.addEventListener('input', function() {
			var v = slider.value;
			gridPane.dataset.density = v;
			if (valueEl) valueEl.textContent = v;
			clearTimeout(saveTimer);
			saveTimer = setTimeout(function(){ savePref('media_density', v); }, 400);
		});
	}
})();
</script>
	<?php
} );

// ═════════════════════════════════════════════════════════════════════════════
//  PART 1B — UPLOAD MEDIA (media-new.php) skinning
//  Wraps the WP native uploader with Therum chrome + restyles all the inputs.
// ═════════════════════════════════════════════════════════════════════════════
add_action( 'admin_enqueue_scripts', function( $hook ) {
	if ( $hook !== 'media-new.php' ) return;
	$path = __DIR__ . '/assets/therum-uploader-skin.css';
	$ver  = file_exists( $path ) ? filemtime( $path ) : null;
	wp_enqueue_style( 'therum-uploader-skin', plugins_url( 'assets/therum-uploader-skin.css', __FILE__ ), [], $ver );
} );

// Header injector — tiny DOM patch, kept inline since it only runs on media-new.php
add_action( 'admin_head-media-new.php', function() {
	?>
<script id="therum-uploader-header-injector">
document.addEventListener('DOMContentLoaded', function() {
	var wrap = document.querySelector('.wrap');
	if (!wrap) return;
	var header = document.createElement('div');
	header.className = 'th-uploader-header';
	header.innerHTML =
		'<div class="th-uploader-meta">Media</div>' +
		'<h1 class="th-uploader-title">Upload media</h1>' +
		'<p class="th-uploader-sub">Drag files anywhere on the page or click select. Images, video, audio, and documents up to the file size limit.</p>';
	wrap.insertBefore(header, wrap.firstChild);
});
</script>
	<?php
} );

// ═════════════════════════════════════════════════════════════════════════════

add_action( 'admin_footer', function() {
	$page = $_GET['page'] ?? '';
	if ( ! in_array( $page, ['therum-pages', 'therum-posts'], true ) ) return;
	$nonce = wp_create_nonce( 'therum_card_image' );
	?>
<script id="therum-card-picker-js">
(function() {
	'use strict';
	var nonce = '<?php echo esc_js( $nonce ); ?>';
	var ajaxUrl = window.ajaxurl || '/wp-admin/admin-ajax.php';

	// Toggle menu on button click; close on outside click
	document.addEventListener('click', function(e) {
		var inPickerEl = e.target.closest('[data-card-picker]');
		if (inPickerEl) { e.preventDefault(); e.stopPropagation(); }
		var toggleBtn = e.target.closest('[data-card-picker-toggle]');
		var inMenu = e.target.closest('[data-card-picker-menu]');
		var inPicker = inPickerEl;

		if (toggleBtn) {
			e.preventDefault();
			e.stopPropagation();
			var picker = toggleBtn.closest('[data-card-picker]');
			var wasOpen = picker.hasAttribute('data-open');
			// Close all other pickers
			document.querySelectorAll('[data-card-picker][data-open]').forEach(function(p) {
				p.removeAttribute('data-open');
			});
			if (!wasOpen) picker.setAttribute('data-open', '');
			return;
		}

		// Click on a picker item — save and update UI
		var item = e.target.closest('.th-card-picker-item');
		if (item) {
			e.preventDefault();
			e.stopPropagation();
			var picker = item.closest('[data-card-picker]');
			var postId = picker.dataset.postId;
			var isLayout = item.hasAttribute('data-card-layout');
			var isImage  = item.hasAttribute('data-card-image');
			var value  = isLayout ? item.dataset.cardLayout : item.dataset.cardImage;
			var action = isLayout ? 'therum_save_card_layout' : 'therum_save_card_image';

			// Mark active only within the same group
			var selector = isLayout ? '[data-card-layout]' : '[data-card-image]';
			picker.querySelectorAll(selector).forEach(function(it) {
				it.classList.toggle('active', it === item);
			});

			// Persist
			var fd = new FormData();
			fd.append('action', action);
			fd.append('post_id', postId);
			fd.append('value', value);
			fd.append('nonce', nonce);
			fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd })
				.then(function(r) { return r.json(); })
				.then(function(res) {
					if (!res || !res.success) return;
					if (res.data && res.data.reload) { location.reload(); return; }
					if (res.data && res.data.thumbStyle) {
						var card = picker.closest('.th-lp-card');
						if (card) {
							var thisLink = card.getAttribute('href') || card.querySelector('a') && card.querySelector('a').getAttribute('href') || '';
							document.querySelectorAll('.th-lp-card').forEach(function(c) {
								var cardLink = c.getAttribute('href') || (c.querySelector('a') && c.querySelector('a').getAttribute('href')) || '';
								if (cardLink === thisLink && cardLink) {
									var thumb = c.querySelector('.th-lp-card-thumb');
									if (thumb) thumb.setAttribute('style', res.data.thumbStyle);
								}
							});
						}
					}
				});

			picker.removeAttribute('data-open');
			return;
		}

		// Outside click — close all menus
		if (!inPicker) {
			document.querySelectorAll('[data-card-picker][data-open]').forEach(function(p) {
				p.removeAttribute('data-open');
			});
		}
	});
})();
</script>

<script id="therum-list-style-toggle-js">
(function() {
	'use strict';
	var nonce = '<?php echo esc_js( wp_create_nonce('therum_theme') ); ?>';
	var ajaxUrl = window.ajaxurl || '/wp-admin/admin-ajax.php';

	document.addEventListener('click', function(e) {
		var trig = e.target.closest('[data-style-trigger]');
		if (trig) {
			e.preventDefault(); e.stopPropagation();
			var tog = trig.closest('[data-style-toggle]');
			document.querySelectorAll('[data-style-toggle][data-open]').forEach(function(o){ if (o!==tog) o.removeAttribute('data-open'); });
			if (tog.hasAttribute('data-open')) tog.removeAttribute('data-open'); else tog.setAttribute('data-open','');
			return;
		}

		var item = e.target.closest('[data-style-field]');
		if (item && item.matches('.th-lp-style-item')) {
			e.preventDefault(); e.stopPropagation();
			var field = item.getAttribute('data-style-field');
			var value = item.getAttribute('data-style-value');
			// Update active states in same section
			var section = item.parentElement;
			section.querySelectorAll('.th-lp-style-item').forEach(function(it){ it.classList.toggle('active', it===item); });
			var fd = new FormData();
			fd.append('action', 'therum_save_state_field');
			fd.append('field', field);
			fd.append('value', value);
			fd.append('nonce', nonce);
			fetch(ajaxUrl, { method:'POST', credentials:'same-origin', body: fd })
				.then(function(r){ return r.json(); })
				.then(function(){ location.reload(); });
			return;
		}

		// outside click closes
		if (!e.target.closest('[data-style-toggle]')) {
			document.querySelectorAll('[data-style-toggle][data-open]').forEach(function(o){ o.removeAttribute('data-open'); });
		}
	});
})();
</script>
	<?php
} );

// ─────────────────────────────────────────────────────────────────────────────
//  AJAX — save per-card image source preference (post meta)
// ─────────────────────────────────────────────────────────────────────────────
add_action( 'wp_ajax_therum_save_card_image', function() {
	if ( ! current_user_can( 'edit_posts' ) ) wp_send_json_error( 'forbidden', 403 );
	check_ajax_referer( 'therum_card_image', 'nonce' );

	$post_id = (int) ( $_POST['post_id'] ?? 0 );
	$value   = sanitize_text_field( wp_unslash( $_POST['value'] ?? '' ) );

	if ( $post_id <= 0 ) wp_send_json_error( 'bad post id' );
	if ( ! current_user_can( 'edit_post', $post_id ) ) wp_send_json_error( 'cannot edit', 403 );

	$post = get_post( $post_id );
	if ( ! $post ) wp_send_json_error( 'no post' );

	if ( $value === 'inherit' || $value === '' ) {
		delete_post_meta( $post_id, '_th_card_image' );
	} elseif ( in_array( $value, ['gradient','featured','stock','wireframe','pattern'], true ) ) {
		update_post_meta( $post_id, '_th_card_image', $value );
	} else {
		wp_send_json_error( 'bad value' );
	}

	wp_send_json_success([
		'thumbStyle' => Therum_Card_Style::thumb_style( $post ),
	]);
} );

add_action( 'wp_ajax_therum_save_card_layout', function() {
	if ( ! current_user_can( 'edit_posts' ) ) wp_send_json_error( 'forbidden', 403 );
	check_ajax_referer( 'therum_card_image', 'nonce' );

	$post_id = (int) ( $_POST['post_id'] ?? 0 );
	$value   = sanitize_text_field( wp_unslash( $_POST['value'] ?? '' ) );

	if ( $post_id <= 0 ) wp_send_json_error( 'bad post id' );
	if ( ! current_user_can( 'edit_post', $post_id ) ) wp_send_json_error( 'cannot edit', 403 );

	if ( $value === 'inherit' || $value === '' ) {
		delete_post_meta( $post_id, '_th_card_layout' );
	} elseif ( in_array( $value, ['hero','compact','magazine','card-v1','card-v2','compact-v1','compact-v2'], true ) ) {
		update_post_meta( $post_id, '_th_card_layout', $value );
	} else {
		wp_send_json_error( 'bad value' );
	}

	wp_send_json_success([ 'reload' => true ]);
} );


// ─────────────────────────────────────────────────────────────────────────────
//  PLUGIN MGMT — AJAX endpoints
// ─────────────────────────────────────────────────────────────────────────────
add_action('wp_ajax_therum_plugin_action', function() {
	if (!current_user_can('activate_plugins')) wp_send_json_error('forbidden', 403);
	check_ajax_referer('therum_plugin_action', 'nonce');

	$file    = isset($_POST['plugin']) ? sanitize_text_field(wp_unslash($_POST['plugin'])) : '';
	$action  = isset($_POST['plugin_action']) ? sanitize_text_field(wp_unslash($_POST['plugin_action'])) : '';
	$version = isset($_POST['version']) ? sanitize_text_field(wp_unslash($_POST['version'])) : '';

	if (!$file) wp_send_json_error('missing plugin');

	require_once ABSPATH . 'wp-admin/includes/plugin.php';
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/misc.php';

	switch ($action) {
		case 'activate':
			$res = activate_plugin($file);
			if (is_wp_error($res)) wp_send_json_error($res->get_error_message());
			wp_send_json_success(['msg' => 'Activated', 'reload' => true]);
			break;

		case 'deactivate':
			deactivate_plugins([$file]);
			wp_send_json_success(['msg' => 'Deactivated', 'reload' => true]);
			break;

		case 'upgrade':
			if (!current_user_can('update_plugins')) wp_send_json_error('forbidden', 403);
			require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
			wp_update_plugins();
			$skin = new \WP_Ajax_Upgrader_Skin();
			$upgrader = new \Plugin_Upgrader($skin);
			$result = $upgrader->upgrade($file);
			if (is_wp_error($result)) wp_send_json_error($result->get_error_message());
			if (is_wp_error($skin->result)) wp_send_json_error($skin->result->get_error_message());
			if ($skin->get_errors()->has_errors()) wp_send_json_error($skin->get_error_messages());
			if ($result === false) wp_send_json_error('Update failed (filesystem)');
			wp_send_json_success(['msg' => 'Updated', 'reload' => true]);
			break;

		case 'rollback':
			if (!current_user_can('update_plugins')) wp_send_json_error('forbidden', 403);
			if (!$version) wp_send_json_error('missing version');
			require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
			require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
			// Resolve slug from plugin file
			$slug = dirname($file);
			if ($slug === '.') $slug = basename($file, '.php');
			// Try wp.org for the zip URL of the requested version
			$api = plugins_api('plugin_information', ['slug' => $slug, 'fields' => ['versions' => true]]);
			if (is_wp_error($api)) wp_send_json_error('wp.org lookup failed: ' . $api->get_error_message());
			$versions = (array) ($api->versions ?? []);
			if (!isset($versions[$version])) wp_send_json_error('version not on wp.org');
			$zip = $versions[$version];
			$skin = new \WP_Ajax_Upgrader_Skin();
			$upgrader = new \Plugin_Upgrader($skin);
			$was_active = is_plugin_active($file);
			$result = $upgrader->install($zip, ['overwrite_package' => true]);
			if (is_wp_error($result)) wp_send_json_error($result->get_error_message());
			if (is_wp_error($skin->result)) wp_send_json_error($skin->result->get_error_message());
			if ($skin->get_errors()->has_errors()) wp_send_json_error($skin->get_error_messages());
			if ($was_active) activate_plugin($file);
			wp_send_json_success(['msg' => 'Rolled back to v' . $version, 'reload' => true]);
			break;

		case 'delete':
			if (!current_user_can('delete_plugins')) wp_send_json_error('forbidden', 403);
			if (is_plugin_active($file)) wp_send_json_error('plugin is active');
			$res = delete_plugins([$file]);
			if (is_wp_error($res)) wp_send_json_error($res->get_error_message());
			wp_send_json_success(['msg' => 'Deleted', 'redirect' => admin_url('admin.php?page=therum-plugins')]);
			break;

		default:
			wp_send_json_error('unknown action');
	}
});

// ─────────────────────────────────────────────────────────────────────────────
//  PLUGIN MGMT — JS for both list page and PDP
// ─────────────────────────────────────────────────────────────────────────────
add_action('admin_footer', function() {
	$page = $_GET['page'] ?? '';
	if (!in_array($page, ['therum-plugins', 'therum-plugin-detail'], true)) return;
	$nonce = wp_create_nonce('therum_plugin_action');
	?>
<script id="therum-plugin-mgmt-js">
(function() {
	'use strict';
	var nonce = '<?php echo esc_js($nonce); ?>';
	var ajaxUrl = window.ajaxurl || '/wp-admin/admin-ajax.php';

	function toast(msg, tone) {
		var el = document.querySelector('[data-pdp-toast]');
		if (!el) {
			el = document.createElement('div');
			el.className = 'th-pdp-toast';
			el.setAttribute('data-pdp-toast', '');
			document.body.appendChild(el);
		}
		el.textContent = msg;
		if (tone) el.setAttribute('data-tone', tone); else el.removeAttribute('data-tone');
		el.hidden = false;
		clearTimeout(el._t);
		el._t = setTimeout(function() { el.hidden = true; }, 3500);
	}

	function resolvePluginFile(btn) {
		var pdp = btn.closest('[data-plugin-file]');
		if (pdp) return pdp.getAttribute('data-plugin-file');
		return '';
	}

	// Rollback button on the active-plugin card navigates to the PDP's
	// version-history section so the user picks which version to install.
	// Rendered as a <button> (to match Deactivate's chrome) — needs JS to
	// navigate since it's not an <a>.
	document.addEventListener('click', function(e) {
		var rb = e.target.closest('[data-rollback-href]');
		if (!rb) return;
		e.preventDefault();
		e.stopPropagation();
		window.location.href = rb.getAttribute('data-rollback-href');
	});

	document.addEventListener('click', function(e) {
		var btn = e.target.closest('[data-action]');
		if (!btn) return;
		var action = btn.getAttribute('data-action');
		if (!['activate','deactivate','upgrade','rollback','delete'].includes(action)) return;

		e.preventDefault();
		e.stopPropagation();

		var pluginFile = resolvePluginFile(btn);
		if (!pluginFile) { toast('Could not resolve plugin file', 'error'); return; }

		var version = btn.getAttribute('data-version') || '';

		// Confirm destructive actions
		if (action === 'delete' && !confirm('Delete this plugin? Files will be removed.')) return;
		if (action === 'rollback' && !confirm('Roll back to v' + version + '? Site behavior may change.')) return;

		btn.setAttribute('data-loading', '');
		var original = btn.textContent;

		var fd = new FormData();
		fd.append('action', 'therum_plugin_action');
		fd.append('nonce', nonce);
		fd.append('plugin', pluginFile);
		fd.append('plugin_action', action);
		if (version) fd.append('version', version);

		fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd })
			.then(function(r) { return r.json(); })
			.then(function(res) {
				btn.removeAttribute('data-loading');
				if (!res || !res.success) {
					var msg = (res && res.data) ? (typeof res.data === 'string' ? res.data : JSON.stringify(res.data)) : 'Failed';
					toast(msg, 'error');
					return;
				}
				toast(res.data && res.data.msg ? res.data.msg : 'Done', 'success');
				if (res.data && res.data.redirect) {
					setTimeout(function() { window.location.href = res.data.redirect; }, 600);
				} else if (res.data && res.data.reload) {
					setTimeout(function() { location.reload(); }, 600);
				}
			})
			.catch(function(err) {
				btn.removeAttribute('data-loading');
				toast('Network error', 'error');
			});
	});
})();
</script>
	<?php
});


// ════════════════════════════════════════════════════════════════════════════
//  2. ADMIN DOCK — from therum-admin-dock.php
// ════════════════════════════════════════════════════════════════════════════
if ( defined( 'THERUM_ADMIN_DOCK_DISABLE' ) && THERUM_ADMIN_DOCK_DISABLE ) return;
if ( ! apply_filters( 'therum/admin_dock/enabled', true ) ) return;

// ── Kill the WP admin bar ──────────────────────────────────────────────────────
add_filter( 'show_admin_bar', '__return_false' );

add_action( 'wp_body_open', 'thd_render',  1 );
add_action( 'wp_head',      'thd_styles',  1 );
add_action( 'wp_footer',    'thd_scripts', 1 );

// ── Settings registration (section + key whitelist) ───────────────────────────
add_filter( 'therum_settings_sections', 'thd_register_settings_section' );
add_filter( 'therum_settings_keys',     'thd_settings_keys' );

// ── Body class: dock position so CSS can switch top ↔ bottom ─────────────────
add_filter( 'body_class', 'thd_body_class' );
function thd_body_class( array $classes ): array {
	if ( ! thd_active() ) return $classes;
	$pos = get_option( 'th_dock_position', 'bottom' );
	$classes[] = 'thd-pos-' . ( $pos === 'top' ? 'top' : 'bottom' );
	return $classes;
}

// ── Options helper ────────────────────────────────────────────────────────────
function thd_opts(): array {
	return [
		'position'     => get_option( 'th_dock_position',     'bottom' ), // bottom | top
		'default_mode' => get_option( 'th_dock_default_mode', 'scroll' ), // scroll | always | drawer
		'mobile'       => get_option( 'th_dock_mobile',       'fab'    ), // fab | none
	];
}


// ═══════════════════════════════════════════════════════════════════════════════
// Helpers
// ═══════════════════════════════════════════════════════════════════════════════

function thd_active(): bool {
	if ( ! is_user_logged_in() )                 return false;
	if ( ! current_user_can( 'edit_posts' ) )    return false;
	if ( function_exists( 'bricks_is_builder' ) && bricks_is_builder() ) return false;
	if ( isset( $_GET['bricks'] ) || isset( $_GET['brickspreview'] ) )   return false;
	return true;
}

function thd_breadcrumb(): string {
	if ( is_front_page() || is_home() ) return get_bloginfo( 'name' );
	if ( is_singular() )                return get_the_title();
	if ( is_category() )                return single_cat_title( '', false );
	if ( is_tag() )                     return single_tag_title( '', false );
	if ( is_author() )                  return get_the_author();
	if ( is_archive() )                 return get_the_archive_title();
	if ( is_search() )                  return 'Search: ' . esc_html( get_search_query() );
	if ( is_404() )                     return '404';
	return get_bloginfo( 'name' );
}

function thd_edit_url(): string {
	if ( ! is_singular() ) return '';
	$id = get_the_ID();
	if ( ! $id || ! current_user_can( 'edit_post', $id ) ) return '';
	if ( defined( 'BRICKS_VERSION' ) ) return add_query_arg( 'bricks', 'run', get_permalink( $id ) );
	return get_edit_post_link( $id, '' ) ?: '';
}

function thd_new_url(): string {
	if ( is_singular( 'case_study' ) || is_post_type_archive( 'case_study' ) )
		return admin_url( 'post-new.php?post_type=case_study' );
	if ( is_singular( 'page' ) )
		return admin_url( 'post-new.php?post_type=page' );
	return admin_url( 'post-new.php' );
}

// SVG helper — keeps HTML clean
function thd_icon( string $name ): string {
	$icons = [
		'logo'     => '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>',
		'edit'     => '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4z"/></svg>',
		'plus'     => '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>',
		'focus'    => '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="3"/><path d="M12 1v3M12 20v3M4.22 4.22l2.12 2.12M17.66 17.66l2.12 2.12M1 12h3M20 12h3M4.22 19.78l2.12-2.12M17.66 6.34l2.12-2.12"/></svg>',
		'chevdown' => '<svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>',
		'chevup'   => '<svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="18 15 12 9 6 15"/></svg>',
		'mode'     => '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/></svg>',
		'close'    => '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>',
		'swap'     => '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="17 1 21 5 17 9"/><path d="M3 11V9a4 4 0 0 1 4-4h14"/><polyline points="7 23 3 19 7 15"/><path d="M21 13v2a4 4 0 0 1-4 4H3"/></svg>',
		'pin'      => '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="17" x2="12" y2="22"/><path d="M5 17h14v-1.76a2 2 0 0 0-1.11-1.79l-1.78-.9A2 2 0 0 1 15 10.76V6h1a2 2 0 0 0 0-4H8a2 2 0 0 0 0 4h1v4.76a2 2 0 0 1-1.11 1.79l-1.78.9A2 2 0 0 0 5 15.24Z"/></svg>',
		'search'   => '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>',
	];
	return $icons[ $name ] ?? '';
}

// ── Admin-side transition fade-in ──────────────────────────────────────────────
add_action( 'admin_head', function() {
	if ( ! isset( $_GET['thd_transition'] ) ) return;
	if ( ! current_user_can( 'edit_posts' ) ) return;
	echo '<style>body{opacity:0;animation:thd-admin-in 0.32s cubic-bezier(0.16,1,0.3,1) 0.05s both}@keyframes thd-admin-in{from{opacity:0;transform:translateY(6px)}to{opacity:1;transform:translateY(0)}}</style>';
} );


// ═══════════════════════════════════════════════════════════════════════════════
// HTML
// ═══════════════════════════════════════════════════════════════════════════════

function thd_render(): void {
	if ( ! thd_active() ) return;

	$dash    = admin_url( 'admin.php?page=therum' );
	$edit    = thd_edit_url();
	$new     = thd_new_url();
	$logout  = wp_logout_url( get_permalink() ?: home_url() );
	$profile = admin_url( 'profile.php' );
	$crumb   = thd_breadcrumb();
	$user    = wp_get_current_user();
	$avatar  = get_avatar( $user->ID, 24, '', '', [ 'class' => 'thd-avatar-img' ] );
	$opts    = thd_opts();
	?>
<!-- Therum OS Admin Dock -->
<div id="thd-bar" role="navigation" aria-label="Therum OS admin dock"
	data-pos="<?php echo esc_attr( $opts['position'] ); ?>"
	data-default-mode="<?php echo esc_attr( $opts['default_mode'] ); ?>"
	data-mobile="<?php echo esc_attr( $opts['mobile'] ); ?>"
	data-nonce="<?php echo esc_attr( wp_create_nonce( 'therum_options' ) ); ?>">

	<a href="<?php echo esc_url( $dash ); ?>" class="thd-logo" title="Dashboard">
		<?php echo thd_icon( 'logo' ); ?>
		<span>Therum OS</span>
	</a>

	<span class="thd-sep" aria-hidden="true">/</span>
	<span class="thd-crumb"><?php echo esc_html( $crumb ); ?></span>

	<div class="thd-spacer"></div>

	<!-- ── 5 pinnable shortcut slots ── -->
	<div class="thd-shortcuts" id="thd-shortcuts" aria-label="Quick shortcuts">
		<?php for ( $i = 0; $i < 5; $i++ ) : ?>
		<div class="thd-slot" data-slot="<?php echo $i; ?>" role="button" tabindex="0" aria-label="Shortcut slot <?php echo $i + 1; ?>">
			<div class="thd-slot-inner">
				<span class="thd-slot-add"><?php echo thd_icon( 'plus' ); ?></span>
				<span class="thd-slot-badge" aria-hidden="true"></span>
			</div>
			<span class="thd-slot-tooltip"></span>
		</div>
		<?php endfor; ?>
	</div>

	<div class="thd-spacer"></div>

	<div class="thd-actions">

		<?php if ( $edit ) : ?>
		<a href="<?php echo esc_url( $edit ); ?>" class="thd-btn" title="Edit this page">
			<?php echo thd_icon( 'edit' ); ?><span>Edit</span>
		</a>
		<?php endif; ?>

		<a href="<?php echo esc_url( $new ); ?>" class="thd-btn" title="New">
			<?php echo thd_icon( 'plus' ); ?><span>New</span>
		</a>

		<!-- Mode switcher -->
		<div class="thd-mode-wrap" id="thd-mode-wrap">
			<button class="thd-btn" id="thd-mode-btn" title="Display mode">
				<?php echo thd_icon( 'mode' ); ?>
				<span id="thd-mode-label">Scroll</span>
				<?php echo thd_icon( 'chevup' ); ?>
			</button>
			<div class="thd-mode-panel" id="thd-mode-panel" role="menu" aria-hidden="true">
				<div class="thd-mode-header">Display mode</div>
				<button class="thd-mode-opt" data-mode="always"  role="menuitem">
					<span class="thd-mode-dot"></span>
					<div>
						<div class="thd-mode-name">Always on</div>
						<div class="thd-mode-desc">Dock stays visible at all times</div>
					</div>
				</button>
				<button class="thd-mode-opt" data-mode="scroll" role="menuitem">
					<span class="thd-mode-dot"></span>
					<div>
						<div class="thd-mode-name">Auto-hide</div>
						<div class="thd-mode-desc">Hides on scroll down, returns on scroll up</div>
					</div>
				</button>
				<button class="thd-mode-opt" data-mode="drawer" role="menuitem">
					<span class="thd-mode-dot"></span>
					<div>
						<div class="thd-mode-name">Drawer</div>
						<div class="thd-mode-desc">Hidden — pull-tab opens it on demand</div>
					</div>
				</button>
				<div class="thd-mode-rule"></div>
				<div class="thd-mode-header">Position</div>
				<button class="thd-pos-opt" data-position="bottom" role="menuitem">
					<span class="thd-mode-dot"></span>
					<div>
						<div class="thd-mode-name">Bottom dock</div>
						<div class="thd-mode-desc">macOS-style, below the content</div>
					</div>
				</button>
				<button class="thd-pos-opt" data-position="top" role="menuitem">
					<span class="thd-mode-dot"></span>
					<div>
						<div class="thd-mode-name">Top bar</div>
						<div class="thd-mode-desc">Classic toolbar position</div>
					</div>
				</button>
				<div class="thd-mode-rule"></div>
				<div class="thd-mode-header">Size</div>
				<button class="thd-size-opt" data-size="slim" role="menuitem">
					<span class="thd-mode-dot"></span>
					<div>
						<div class="thd-mode-name">Slim</div>
						<div class="thd-mode-desc">56px — minimal footprint</div>
					</div>
				</button>
				<button class="thd-size-opt" data-size="normal" role="menuitem">
					<span class="thd-mode-dot"></span>
					<div>
						<div class="thd-mode-name">Normal</div>
						<div class="thd-mode-desc">72px — default</div>
					</div>
				</button>
				<button class="thd-size-opt" data-size="large" role="menuitem">
					<span class="thd-mode-dot"></span>
					<div>
						<div class="thd-mode-name">Large</div>
						<div class="thd-mode-desc">88px — macOS-dock size</div>
					</div>
				</button>
			</div>
		</div>

		<!-- Focus mode -->
		<button class="thd-btn thd-focus-btn" id="thd-focus-btn" title="Focus mode — hides dock for demos" aria-pressed="false">
			<?php echo thd_icon( 'focus' ); ?><span>Focus</span>
		</button>

		<!-- Collapse to drawer (shortcut) -->
		<button class="thd-btn thd-collapse-btn" id="thd-collapse-btn" title="Collapse to drawer">
			<?php echo thd_icon( 'chevdown' ); ?>
		</button>

		<!-- Avatar -->
		<div class="thd-avatar-wrap">
			<button class="thd-avatar-btn" id="thd-avatar-btn"
				aria-haspopup="true" aria-expanded="false"
				title="<?php echo esc_attr( $user->display_name ); ?>">
				<?php echo $avatar; ?>
			</button>
			<div class="thd-dropdown" id="thd-dropdown" role="menu" aria-hidden="true">
				<div class="thd-dd-header"><?php echo esc_html( $user->display_name ); ?></div>
				<a href="<?php echo esc_url( $profile ); ?>" class="thd-dd-item" role="menuitem">Profile</a>
				<a href="<?php echo esc_url( $dash );    ?>" class="thd-dd-item" role="menuitem">Dashboard</a>
				<div class="thd-dd-rule"></div>
				<a href="<?php echo esc_url( $logout );  ?>" class="thd-dd-item thd-dd-danger" role="menuitem">Log out</a>
			</div>
		</div>

	</div>
</div>

<!-- Drawer pull-tab — prominent handle when dock is collapsed -->
<button id="thd-tab" aria-hidden="true" title="Open Therum OS dock">
	<?php echo thd_icon( 'logo' ); ?>
	<span>Open</span>
</button>

<!-- Focus mode edge trigger (4px strip at bottom) -->
<div id="thd-edge" role="button" tabindex="-1" aria-hidden="true"></div>

<!-- Shortcut picker panel -->
<?php
// Quick-shortcuts picker — only surface destinations that actually resolve on
// this install. Adding e.g. "WooCommerce" when Woo isn't installed dumps the
// user at a WP "Sorry, you are not allowed to access this page." screen.
$thd_available = [
	[ 'label' => 'Dashboard',    'url' => admin_url(),                                  'color' => '#6366f1' ],
	[ 'label' => 'Posts',        'url' => admin_url( 'edit.php' ),                      'color' => '#0891b2' ],
	[ 'label' => 'Pages',        'url' => admin_url( 'edit.php?post_type=page' ),       'color' => '#0e7490' ],
	[ 'label' => 'Media',        'url' => admin_url( 'upload.php' ),                    'color' => '#7c3aed' ],
	[ 'label' => 'New Post',     'url' => admin_url( 'post-new.php' ),                  'color' => '#2563eb' ],
	[ 'label' => 'Comments',     'url' => admin_url( 'edit-comments.php' ),             'color' => '#d97706' ],
	[ 'label' => 'Appearance',   'url' => admin_url( 'themes.php' ),                    'color' => '#db2777' ],
	[ 'label' => 'Plugins',      'url' => admin_url( 'plugins.php' ),                   'color' => '#dc2626' ],
	[ 'label' => 'Users',        'url' => admin_url( 'users.php' ),                     'color' => '#059669' ],
	[ 'label' => 'Settings',     'url' => admin_url( 'options-general.php' ),           'color' => '#64748b' ],
	[ 'label' => 'Therum OS',    'url' => admin_url( 'admin.php?page=therum' ),         'color' => '#0891b2' ],
	[ 'label' => 'Dock settings','url' => admin_url( 'admin.php?page=therum-settings&section=admin-dock' ), 'color' => '#475569' ],
	[ 'label' => 'Menus',        'url' => admin_url( 'nav-menus.php' ),                 'color' => '#b45309' ],
];
if ( post_type_exists( 'case_study' ) ) {
	$thd_available[] = [ 'label' => 'Case Studies', 'url' => admin_url( 'edit.php?post_type=case_study' ), 'color' => '#9333ea' ];
}
if ( class_exists( 'WooCommerce' ) ) {
	$thd_available[] = [ 'label' => 'WooCommerce', 'url' => admin_url( 'admin.php?page=wc-admin' ), 'color' => '#7c3aed' ];
}
if ( defined( 'BRICKS_VERSION' ) ) {
	$thd_available[] = [ 'label' => 'Bricks', 'url' => admin_url( 'admin.php?page=bricks' ), 'color' => '#dc5a12' ];
}
if ( function_exists( 'is_plugin_active' ) && is_plugin_active( 'litespeed-cache/litespeed-cache.php' ) ) {
	$thd_available[] = [ 'label' => 'LiteSpeed Cache', 'url' => admin_url( 'admin.php?page=litespeed' ), 'color' => '#0ea5e9' ];
}
?>
<div id="thd-picker" role="dialog" aria-modal="true" aria-label="Add shortcut" aria-hidden="true">
	<div class="thd-picker-head">
		<span><?php echo thd_icon( 'pin' ); ?>Quick shortcuts</span>
		<button class="thd-picker-close" id="thd-picker-close" aria-label="Close"><?php echo thd_icon( 'close' ); ?></button>
	</div>
	<div class="thd-picker-search-wrap">
		<?php echo thd_icon( 'search' ); ?>
		<input type="text" id="thd-picker-search" placeholder="Search destinations…" autocomplete="off" />
	</div>
	<div class="thd-picker-grid" id="thd-picker-grid">
		<?php foreach ( $thd_available as $sc ) : ?>
		<button class="thd-picker-item"
			data-label="<?php echo esc_attr( $sc['label'] ); ?>"
			data-url="<?php echo esc_attr( $sc['url'] ); ?>"
			data-color="<?php echo esc_attr( $sc['color'] ); ?>">
			<span class="thd-picker-badge" style="background:<?php echo esc_attr( $sc['color'] ); ?>">
				<?php echo esc_html( mb_substr( $sc['label'], 0, 2 ) ); ?>
			</span>
			<span class="thd-picker-item-label"><?php echo esc_html( $sc['label'] ); ?></span>
		</button>
		<?php endforeach; ?>
	</div>
	<div class="thd-picker-footer">Right-click any shortcut to remove it</div>
</div>

<!-- Page transition overlay -->
<div id="thd-transition-overlay" aria-hidden="true"></div>

<!-- Mobile FAB (< 768px only) — suppressed when mobile = none -->
<?php if ( $opts['mobile'] !== 'none' ) : ?>
<button id="thd-fab" aria-label="Therum OS admin" aria-expanded="false">
	<?php echo thd_icon( 'logo' ); ?>
</button>
<div id="thd-fab-panel" role="menu" aria-hidden="true">
	<div class="thd-fab-header">
		<span><?php echo esc_html( $crumb ); ?></span>
		<button class="thd-fab-close" id="thd-fab-close" aria-label="Close"><?php echo thd_icon( 'close' ); ?></button>
	</div>
	<a href="<?php echo esc_url( $dash ); ?>" class="thd-fab-item" role="menuitem">
		<?php echo thd_icon( 'logo' ); ?><span>Dashboard</span>
	</a>
	<?php if ( $edit ) : ?>
	<a href="<?php echo esc_url( $edit ); ?>" class="thd-fab-item" role="menuitem">
		<?php echo thd_icon( 'edit' ); ?><span>Edit this page</span>
	</a>
	<?php endif; ?>
	<a href="<?php echo esc_url( $new ); ?>" class="thd-fab-item" role="menuitem">
		<?php echo thd_icon( 'plus' ); ?><span>New</span>
	</a>
	<div class="thd-fab-rule"></div>
	<a href="<?php echo esc_url( $profile ); ?>" class="thd-fab-item" role="menuitem">
		<?php echo $avatar; ?><span>Profile</span>
	</a>
	<a href="<?php echo esc_url( $logout ); ?>" class="thd-fab-item thd-fab-item--danger" role="menuitem">
		<?php echo thd_icon( 'focus' ); ?><span>Log out</span>
	</a>
</div>
<?php endif; // mobile FAB ?>
	<?php
}


// ═══════════════════════════════════════════════════════════════════════════════
// Styles
// ═══════════════════════════════════════════════════════════════════════════════

function thd_styles(): void {
	if ( ! thd_active() ) return;
	?>
<style id="therum-admin-dock">
/* ── Design tokens — dark (default) ── */
:root {
	--thd-h:         48px;
	--thd-bg:        rgba(10,10,10,0.72);
	--thd-bg-panel:  rgba(14,14,14,0.97);
	--thd-bd:        rgba(255,255,255,0.08);
	--thd-text:      rgba(255,255,255,0.85);
	--thd-text-dim:  rgba(255,255,255,0.40);
	--thd-text-faint:rgba(255,255,255,0.22);
	--thd-hover:     rgba(255,255,255,0.07);
	--thd-active-bg: rgba(255,255,255,0.10);
	--thd-ease:      cubic-bezier(0.32,0.72,0,1);
}

/* ── Light mode — prefers-color-scheme ── */
@media (prefers-color-scheme: light) {
	:root {
		--thd-bg:        rgba(245,245,245,0.78);
		--thd-bg-panel:  rgba(252,252,252,0.98);
		--thd-bd:        rgba(0,0,0,0.09);
		--thd-text:      rgba(0,0,0,0.82);
		--thd-text-dim:  rgba(0,0,0,0.40);
		--thd-text-faint:rgba(0,0,0,0.22);
		--thd-hover:     rgba(0,0,0,0.05);
		--thd-active-bg: rgba(0,0,0,0.07);
	}
}

/* ── Light mode — body class (Therum OS / Bricks site theme) ── */
body.light,
body.th-light,
body.light-mode,
body[data-theme*="light"] {
	--thd-bg:        rgba(245,245,245,0.78);
	--thd-bg-panel:  rgba(252,252,252,0.98);
	--thd-bd:        rgba(0,0,0,0.09);
	--thd-text:      rgba(0,0,0,0.82);
	--thd-text-dim:  rgba(0,0,0,0.40);
	--thd-text-faint:rgba(0,0,0,0.22);
	--thd-hover:     rgba(0,0,0,0.05);
	--thd-active-bg: rgba(0,0,0,0.07);
}

/* ── Size variants ── */
/* Bottom position (macOS-dock-style) */
body.thd-size-slim   { --thd-h: 56px; }
body.thd-size-normal { --thd-h: 72px; }
body.thd-size-large  { --thd-h: 88px; }

/* Top position is 3/4 of bottom at each tier — denser classic-toolbar feel */
body.thd-pos-top.thd-size-slim   { --thd-h: 42px; }
body.thd-pos-top.thd-size-normal { --thd-h: 54px; }
body.thd-pos-top.thd-size-large  { --thd-h: 66px; }

/* ── Dock (bottom bar) ── */
#thd-bar {
	position: fixed;
	bottom: 0; left: 0; right: 0;
	height: var(--thd-h);
	display: flex;
	align-items: center;
	gap: 6px;
	padding: 0 16px;
	background: var(--thd-bg);
	backdrop-filter: blur(40px) saturate(200%) brightness(1.04);
	-webkit-backdrop-filter: blur(40px) saturate(200%) brightness(1.04);
	border-top: 1px solid var(--thd-bd);
	z-index: 99999;
	transform: translateY(0);
	transition: transform 0.22s var(--thd-ease);
	font-family: -apple-system, BlinkMacSystemFont, 'Inter', sans-serif;
	font-size: 12px;
	color: var(--thd-text-dim);
	-webkit-font-smoothing: antialiased;
	box-sizing: border-box;
}

/* ── Logo ── */
.thd-logo {
	display: flex;
	align-items: center;
	gap: 7px;
	color: var(--thd-text);
	text-decoration: none;
	font-weight: 600;
	font-size: 11px;
	letter-spacing: 0.07em;
	text-transform: uppercase;
	flex-shrink: 0;
	transition: opacity 0.14s;
}
.thd-logo:hover { opacity: 1; }
.thd-logo svg   { opacity: 0.6; flex-shrink: 0; }

/* ── Breadcrumb ── */
.thd-sep   { color: var(--thd-text-faint); font-size: 15px; flex-shrink: 0; }
.thd-crumb {
	color: var(--thd-text-dim);
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
	max-width: 240px;
	font-size: 12px;
}
.thd-spacer { flex: 1; min-width: 0; }

/* ── Action buttons ── */
.thd-actions { display: flex; align-items: center; gap: 2px; flex-shrink: 0; }

.thd-btn {
	display: inline-flex;
	align-items: center;
	gap: 6px;
	padding: 6px 11px;
	background: none;
	border: none;
	color: var(--thd-text-dim);
	font-size: 12px;
	font-weight: 500;
	letter-spacing: 0.02em;
	cursor: pointer;
	border-radius: 8px;
	text-decoration: none;
	transition: color 0.14s, background 0.14s;
	font-family: inherit;
	line-height: 1;
}
.thd-btn:hover           { color: var(--thd-text); background: var(--thd-hover); }
.thd-btn.is-active       { color: var(--thd-text); background: var(--thd-active-bg); }
.thd-collapse-btn        { padding: 6px 8px; }

/* ── Mode switcher (panel opens upward) ── */
.thd-mode-wrap { position: relative; }

.thd-mode-panel {
	position: absolute;
	bottom: calc(100% + 10px);
	top: auto;
	right: 0;
	width: 240px;
	background: var(--thd-bg-panel);
	backdrop-filter: blur(32px) saturate(200%);
	-webkit-backdrop-filter: blur(32px) saturate(200%);
	border: 1px solid var(--thd-bd);
	border-radius: 14px;
	box-shadow: 0 16px 48px rgba(0,0,0,0.35), 0 2px 8px rgba(0,0,0,0.15);
	padding: 6px;
	opacity: 0;
	transform: translateY(6px) scale(0.97);
	transform-origin: bottom right;
	transition: opacity 0.15s, transform 0.15s;
	pointer-events: none;
	z-index: 100001;
}
.thd-mode-panel.is-open {
	opacity: 1;
	transform: translateY(0) scale(1);
	pointer-events: auto;
}
.thd-mode-header {
	font-size: 9px;
	font-weight: 700;
	letter-spacing: 0.12em;
	text-transform: uppercase;
	color: var(--thd-text-faint);
	padding: 8px 12px 6px;
}
.thd-mode-rule {
	height: 1px;
	background: var(--thd-bd);
	margin: 4px 6px;
}
.thd-mode-opt,
.thd-pos-opt,
.thd-size-opt {
	display: flex;
	align-items: center;
	gap: 12px;
	width: 100%;
	padding: 11px 12px;
	background: none;
	border: none;
	border-radius: 9px;
	cursor: pointer;
	transition: background 0.12s;
	text-align: left;
	font-family: inherit;
}
.thd-mode-opt:hover,
.thd-pos-opt:hover,
.thd-size-opt:hover  { background: var(--thd-hover); }
.thd-mode-opt.is-active,
.thd-pos-opt.is-active,
.thd-size-opt.is-active { background: var(--thd-active-bg); }
.thd-mode-dot {
	width: 8px;
	height: 8px;
	border-radius: 50%;
	border: 1.5px solid var(--thd-text-faint);
	flex-shrink: 0;
	transition: border-color 0.14s, background 0.14s;
}
.thd-mode-opt.is-active .thd-mode-dot,
.thd-pos-opt.is-active  .thd-mode-dot,
.thd-size-opt.is-active .thd-mode-dot {
	background: var(--thd-text);
	border-color: var(--thd-text);
}
.thd-mode-name { font-size: 13px; font-weight: 500; color: var(--thd-text); }
.thd-mode-desc { font-size: 11px; color: var(--thd-text-dim); margin-top: 2px; }

/* ── Avatar + dropdown (opens upward) ── */
.thd-avatar-wrap  { position: relative; margin-left: 4px; }
.thd-avatar-btn   {
	width: 30px; height: 30px;
	border-radius: 50%;
	overflow: hidden;
	background: var(--thd-hover);
	border: 1.5px solid var(--thd-bd);
	padding: 0; cursor: pointer;
	transition: border-color 0.14s;
	display: flex; align-items: center; justify-content: center;
}
.thd-avatar-btn:hover { border-color: var(--thd-text-dim); }
.thd-avatar-img   { width: 100% !important; height: 100% !important; border-radius: 50% !important; display: block !important; }

.thd-dropdown {
	position: absolute;
	bottom: calc(100% + 10px);
	top: auto;
	right: 0;
	min-width: 170px;
	background: var(--thd-bg-panel);
	backdrop-filter: blur(32px) saturate(200%);
	-webkit-backdrop-filter: blur(32px) saturate(200%);
	border: 1px solid var(--thd-bd);
	border-radius: 14px;
	box-shadow: 0 16px 48px rgba(0,0,0,0.35), 0 2px 8px rgba(0,0,0,0.15);
	padding: 6px;
	opacity: 0;
	transform: translateY(6px) scale(0.97);
	transform-origin: bottom right;
	transition: opacity 0.15s, transform 0.15s;
	pointer-events: none;
	z-index: 100001;
}
.thd-dropdown.is-open    { opacity: 1; transform: translateY(0) scale(1); pointer-events: auto; }
.thd-dd-header           { font-size: 11px; color: var(--thd-text-dim); font-weight: 500; padding: 8px 12px; border-bottom: 1px solid var(--thd-bd); margin-bottom: 4px; }
.thd-dd-item             { display: block; padding: 9px 12px; font-size: 13px; color: var(--thd-text-dim); text-decoration: none; border-radius: 8px; transition: background 0.12s, color 0.12s; }
.thd-dd-item:hover       { background: var(--thd-hover); color: var(--thd-text); }
.thd-dd-danger           { color: rgba(252,165,165,0.65); }
.thd-dd-danger:hover     { background: rgba(252,165,165,0.06); color: #fca5a5; }
.thd-dd-rule             { height: 1px; background: var(--thd-bd); margin: 4px 0; }

/* ══════════════════════════════════════════════════
   Drawer pull-tab (bottom edge, opens upward)
══════════════════════════════════════════════════ */
#thd-tab {
	position: fixed;
	bottom: 0;
	left: 50%;
	transform: translateX(-50%) translateY(100%);
	width: 48px;
	height: 24px;
	background: var(--thd-bg);
	backdrop-filter: blur(40px) saturate(200%);
	-webkit-backdrop-filter: blur(40px) saturate(200%);
	border: 1px solid var(--thd-bd);
	border-bottom: none;
	border-radius: 12px 12px 0 0;
	display: flex;
	align-items: center;
	justify-content: center;
	cursor: pointer;
	z-index: 99999;
	color: var(--thd-text-dim);
	padding: 0;
	font-family: inherit;
	transition: transform 0.22s var(--thd-ease), color 0.14s;
}
#thd-tab:hover { color: var(--thd-text); }

/* ══════════════════════════════════════════════════
   Focus-mode edge trigger (4px strip at bottom)
══════════════════════════════════════════════════ */
#thd-edge {
	position: fixed;
	bottom: 0; left: 0; right: 0;
	height: 4px;
	background: linear-gradient(90deg,
		var(--thd-bd) 0%,
		rgba(0,0,0,0) 50%,
		var(--thd-bd) 100%);
	z-index: 99999;
	cursor: pointer;
	opacity: 0;
	pointer-events: none;
	transition: opacity 0.2s;
}

/* ══════════════════════════════════════════════════
   Body offset (prevents content hiding behind dock)
══════════════════════════════════════════════════ */
body.thd-offset { padding-bottom: var(--thd-h) !important; }

/* ══════════════════════════════════════════════════
   Mode: scroll — dock hides when scrolled away
══════════════════════════════════════════════════ */
body.thd-scroll #thd-bar.is-hidden { transform: translateY(100%); }

/* ══════════════════════════════════════════════════
   Mode: drawer — dock hidden, tab is the trigger
══════════════════════════════════════════════════ */
body.thd-drawer              { padding-bottom: 0 !important; }
body.thd-drawer #thd-bar     { transform: translateY(100%); }
body.thd-drawer #thd-tab     { transform: translateX(-50%) translateY(0); }

/* Drawer open state */
body.thd-drawer.thd-drawer-open              { padding-bottom: var(--thd-h) !important; }
body.thd-drawer.thd-drawer-open #thd-bar    { transform: translateY(0); }
body.thd-drawer.thd-drawer-open #thd-tab    { transform: translateX(-50%) translateY(100%); }

/* ══════════════════════════════════════════════════
   Focus mode — overrides all three modes
   Everything hidden, only 4px edge strip remains
══════════════════════════════════════════════════ */
body.thd-focus                               { padding-bottom: 0 !important; }
body.thd-focus #thd-bar                     { transform: translateY(100%) !important; }
body.thd-focus #thd-tab                     { transform: translateX(-50%) translateY(100%) !important; }
body.thd-focus #thd-edge                    { opacity: 1; pointer-events: auto; }

/* Peek — dock temporarily visible in focus mode on edge hover */
body.thd-focus.thd-peek                     { padding-bottom: var(--thd-h) !important; }
body.thd-focus.thd-peek #thd-bar           { transform: translateY(0) !important; }

/* ══════════════════════════════════════════════════
   Mobile FAB (< 768px) — replaces the full dock
══════════════════════════════════════════════════ */

/* FAB hidden by default (desktop) */
#thd-fab       { display: none; }
#thd-fab-panel {
	position: fixed;
	bottom: 82px;
	right: 16px;
	width: 220px;
	background: rgba(12,12,12,0.97);
	backdrop-filter: blur(20px);
	-webkit-backdrop-filter: blur(20px);
	border: 1px solid rgba(255,255,255,0.08);
	border-radius: 14px;
	box-shadow: 0 12px 40px rgba(0,0,0,0.5);
	padding: 6px;
	opacity: 0;
	transform: translateY(10px) scale(0.95);
	transform-origin: bottom right;
	transition: opacity 0.15s, transform 0.15s;
	pointer-events: none;
	z-index: 100000;
	display: none;
}

@media (max-width: 767px) {
	/* Hide full dock on mobile */
	#thd-bar,
	#thd-tab,
	#thd-edge { display: none !important; }

	/* No body offset on mobile */
	body.thd-offset { padding-bottom: 0 !important; }

	/* FAB button */
	#thd-fab {
		position: fixed;
		bottom: 20px;
		right: 16px;
		width: 52px;
		height: 52px;
		border-radius: 50%;
		background: var(--thd-bg);
		backdrop-filter: blur(24px) saturate(180%);
		-webkit-backdrop-filter: blur(24px) saturate(180%);
		border: 1px solid var(--thd-bd);
		display: flex;
		align-items: center;
		justify-content: center;
		cursor: pointer;
		z-index: 99999;
		color: rgba(255,255,255,0.8);
		padding: 0;
		font-family: inherit;
		box-shadow: 0 4px 24px rgba(0,0,0,0.45);
		transition: transform 0.18s, box-shadow 0.18s, border-radius 0.18s;
	}
	#thd-fab:hover    { transform: scale(1.06); box-shadow: 0 6px 30px rgba(0,0,0,0.55); }
	#thd-fab.is-open  { border-radius: 14px; }

	/* FAB action panel */
	#thd-fab-panel { display: block; }
	#thd-fab-panel.is-open {
		opacity: 1;
		transform: translateY(0) scale(1);
		pointer-events: auto;
	}

	/* Panel header */
	.thd-fab-header {
		display: flex;
		align-items: center;
		justify-content: space-between;
		padding: 8px 10px;
		border-bottom: 1px solid rgba(255,255,255,0.06);
		margin-bottom: 4px;
		font-size: 11px;
		font-weight: 500;
		color: rgba(255,255,255,0.35);
		font-family: -apple-system, BlinkMacSystemFont, 'Inter', sans-serif;
		white-space: nowrap;
		overflow: hidden;
		text-overflow: ellipsis;
	}
	.thd-fab-close {
		display: flex;
		align-items: center;
		justify-content: center;
		flex-shrink: 0;
		background: none;
		border: none;
		color: rgba(255,255,255,0.3);
		cursor: pointer;
		padding: 2px;
		border-radius: 4px;
		font-family: inherit;
		transition: color 0.12s;
		margin-left: 8px;
	}
	.thd-fab-close:hover { color: #fff; }

	/* Panel items */
	.thd-fab-item {
		display: flex;
		align-items: center;
		gap: 10px;
		padding: 9px 10px;
		font-size: 13px;
		color: rgba(255,255,255,0.62);
		text-decoration: none;
		border-radius: 8px;
		transition: background 0.12s, color 0.12s;
		font-family: -apple-system, BlinkMacSystemFont, 'Inter', sans-serif;
	}
	.thd-fab-item:hover              { background: rgba(255,255,255,0.07); color: #fff; }
	.thd-fab-item svg                { flex-shrink: 0; opacity: 0.65; }
	.thd-fab-item .thd-avatar-img,
	.thd-fab-item img                { width: 18px !important; height: 18px !important; border-radius: 50% !important; display: inline-block !important; }
	.thd-fab-item--danger            { color: rgba(252,165,165,0.65); }
	.thd-fab-item--danger:hover      { background: rgba(252,165,165,0.06); color: #fca5a5; }
	.thd-fab-rule                    { height: 1px; background: rgba(255,255,255,0.06); margin: 4px 0; }
}

@media (min-width: 768px) {
	/* Ensure FAB stays hidden on desktop/tablet */
	#thd-fab,
	#thd-fab-panel { display: none !important; }
}

/* ══════════════════════════════════════════════════
   Drawer pull-tab — prominent handle
══════════════════════════════════════════════════ */
#thd-tab {
	gap: 6px;
	font-size: 10px;
	font-weight: 700;
	letter-spacing: 0.08em;
	text-transform: uppercase;
	color: var(--thd-text-dim);
	width: 80px;
	height: 30px;
	border-radius: 14px 14px 0 0;
	box-shadow: 0 -4px 20px rgba(0,0,0,0.18);
}
#thd-tab:hover { color: var(--thd-text); }

/* Subtle breathing glow when tab is visible (drawer mode) */
body.thd-drawer #thd-tab {
	animation: thd-tab-breathe 3s ease-in-out infinite;
}
@keyframes thd-tab-breathe {
	0%, 100% { box-shadow: 0 -4px 20px rgba(0,0,0,0.18); }
	50%       { box-shadow: 0 -4px 28px rgba(0,0,0,0.28), 0 -2px 12px rgba(255,255,255,0.04); }
}

/* ══════════════════════════════════════════════════
   Shortcut slots (center of dock)
══════════════════════════════════════════════════ */
.thd-shortcuts {
	display: flex;
	align-items: center;
	gap: 4px;
	flex-shrink: 0;
}

.thd-slot {
	position: relative;
	display: flex;
	align-items: center;
	justify-content: center;
	width: 36px;
	height: 36px;
	border-radius: 10px;
	cursor: pointer;
	transition: background 0.14s, transform 0.14s;
	outline: none;
	border: 1.5px dashed var(--thd-bd);
	color: var(--thd-text-faint);
}
.thd-slot:hover {
	background: var(--thd-hover);
	border-color: var(--thd-text-faint);
	color: var(--thd-text-dim);
	transform: translateY(-2px);
}
.thd-slot:focus-visible { outline: 2px solid var(--thd-text-dim); outline-offset: 2px; }

/* Empty state — dashed border + plus */
.thd-slot-inner { display: flex; align-items: center; justify-content: center; }
.thd-slot-add   { display: flex; opacity: 0.5; transition: opacity 0.14s; }
.thd-slot:hover .thd-slot-add { opacity: 1; }

/* Filled state */
.thd-slot.is-filled {
	border-style: solid;
	border-color: transparent;
	background: var(--thd-hover);
}
.thd-slot.is-filled:hover { transform: translateY(-3px); background: var(--thd-active-bg); }
.thd-slot.is-filled .thd-slot-add { display: none; }

.thd-slot-badge {
	display: none;
	width: 28px;
	height: 28px;
	border-radius: 8px;
	align-items: center;
	justify-content: center;
	font-size: 10px;
	font-weight: 700;
	letter-spacing: 0.02em;
	color: #fff;
	text-transform: uppercase;
	font-family: -apple-system, BlinkMacSystemFont, 'Inter', sans-serif;
}
.thd-slot.is-filled .thd-slot-badge { display: flex; }

/* Tooltip */
.thd-slot-tooltip {
	position: absolute;
	bottom: calc(100% + 8px);
	left: 50%;
	transform: translateX(-50%) translateY(4px);
	background: var(--thd-bg-panel);
	border: 1px solid var(--thd-bd);
	border-radius: 7px;
	padding: 5px 9px;
	font-size: 11px;
	font-weight: 500;
	color: var(--thd-text);
	white-space: nowrap;
	pointer-events: none;
	opacity: 0;
	transition: opacity 0.12s, transform 0.12s;
	z-index: 100002;
	backdrop-filter: blur(20px);
	-webkit-backdrop-filter: blur(20px);
}
.thd-slot:hover .thd-slot-tooltip {
	opacity: 1;
	transform: translateX(-50%) translateY(0);
}

/* Tooltip flips for top-position */
body.thd-pos-top .thd-slot-tooltip {
	bottom: auto;
	top: calc(100% + 8px);
	transform: translateX(-50%) translateY(-4px);
}
body.thd-pos-top .thd-slot:hover .thd-slot-tooltip { transform: translateX(-50%) translateY(0); }

/* ══════════════════════════════════════════════════
   Shortcut picker panel
══════════════════════════════════════════════════ */
#thd-picker {
	position: fixed;
	bottom: calc(var(--thd-h) + 12px);
	left: 50%;
	transform: translateX(-50%) translateY(10px) scale(0.97);
	transform-origin: bottom center;
	width: 340px;
	max-height: 420px;
	display: flex;
	flex-direction: column;
	background: var(--thd-bg-panel);
	backdrop-filter: blur(40px) saturate(200%);
	-webkit-backdrop-filter: blur(40px) saturate(200%);
	border: 1px solid var(--thd-bd);
	border-radius: 18px;
	box-shadow: 0 24px 60px rgba(0,0,0,0.4), 0 4px 12px rgba(0,0,0,0.2);
	opacity: 0;
	pointer-events: none;
	transition: opacity 0.18s, transform 0.18s;
	z-index: 100002;
	overflow: hidden;
}
#thd-picker.is-open {
	opacity: 1;
	transform: translateX(-50%) translateY(0) scale(1);
	pointer-events: auto;
}
body.thd-pos-top #thd-picker {
	bottom: auto;
	top: calc(var(--thd-h) + 12px);
	transform-origin: top center;
	transform: translateX(-50%) translateY(-10px) scale(0.97);
}
body.thd-pos-top #thd-picker.is-open { transform: translateX(-50%) translateY(0) scale(1); }

.thd-picker-head {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 16px 16px 12px;
	border-bottom: 1px solid var(--thd-bd);
	flex-shrink: 0;
}
.thd-picker-head > span {
	display: flex;
	align-items: center;
	gap: 7px;
	font-size: 13px;
	font-weight: 600;
	color: var(--thd-text);
}
.thd-picker-close {
	display: flex;
	align-items: center;
	justify-content: center;
	background: none;
	border: none;
	color: var(--thd-text-dim);
	cursor: pointer;
	padding: 4px;
	border-radius: 6px;
	font-family: inherit;
	transition: color 0.12s, background 0.12s;
}
.thd-picker-close:hover { color: var(--thd-text); background: var(--thd-hover); }

.thd-picker-search-wrap {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 10px 14px;
	border-bottom: 1px solid var(--thd-bd);
	flex-shrink: 0;
	color: var(--thd-text-dim);
}
.thd-picker-search-wrap input {
	flex: 1;
	background: none;
	border: none;
	font-size: 13px;
	color: var(--thd-text);
	outline: none;
	font-family: inherit;
}
.thd-picker-search-wrap input::placeholder { color: var(--thd-text-faint); }

.thd-picker-grid {
	display: grid;
	grid-template-columns: 1fr 1fr;
	gap: 4px;
	padding: 8px;
	overflow-y: auto;
	flex: 1;
	scrollbar-width: thin;
	scrollbar-color: var(--thd-bd) transparent;
}
.thd-picker-item {
	display: flex;
	align-items: center;
	gap: 10px;
	padding: 9px 11px;
	background: none;
	border: none;
	border-radius: 10px;
	cursor: pointer;
	font-family: inherit;
	text-align: left;
	transition: background 0.12s;
}
.thd-picker-item:hover { background: var(--thd-hover); }
.thd-picker-badge {
	width: 30px;
	height: 30px;
	border-radius: 8px;
	display: flex;
	align-items: center;
	justify-content: center;
	font-size: 10px;
	font-weight: 700;
	color: #fff;
	text-transform: uppercase;
	flex-shrink: 0;
	letter-spacing: 0.02em;
}
.thd-picker-item-label { font-size: 12px; font-weight: 500; color: var(--thd-text); }
.thd-picker-footer {
	padding: 10px 16px;
	border-top: 1px solid var(--thd-bd);
	font-size: 11px;
	color: var(--thd-text-faint);
	text-align: center;
	flex-shrink: 0;
}

/* ══════════════════════════════════════════════════
   Page transition overlay
══════════════════════════════════════════════════ */
#thd-transition-overlay {
	position: fixed;
	inset: 0;
	background: var(--thd-bg);
	backdrop-filter: blur(40px) saturate(200%);
	-webkit-backdrop-filter: blur(40px) saturate(200%);
	z-index: 999999;
	opacity: 0;
	pointer-events: none;
	transition: opacity 0.22s cubic-bezier(0.4,0,1,1);
}
#thd-transition-overlay.is-active {
	opacity: 1;
	pointer-events: auto;
}

/* ══════════════════════════════════════════════════
   Position: top — full override when admin sets top
   All bottom→top, translateY(100%)→translateY(-100%),
   border-top→border-bottom, padding-bottom→padding-top,
   panels open downward, tab hangs from top edge.
══════════════════════════════════════════════════ */
body.thd-pos-top #thd-bar {
	bottom: auto; top: 0;
	border-top: none;
	border-bottom: 1px solid var(--thd-bd);
}

/* Panels open downward in top position */
body.thd-pos-top .thd-mode-panel,
body.thd-pos-top .thd-dropdown {
	bottom: auto;
	top: calc(100% + 10px);
	transform: translateY(-6px) scale(0.97);
	transform-origin: top right;
}
body.thd-pos-top .thd-mode-panel.is-open,
body.thd-pos-top .thd-dropdown.is-open {
	transform: translateY(0) scale(1);
}

/* Body offset → padding-top */
body.thd-pos-top.thd-offset    { padding-bottom: 0 !important; padding-top: var(--thd-h) !important; }

/* Scroll mode: slide up to hide */
body.thd-pos-top.thd-scroll #thd-bar.is-hidden { transform: translateY(-100%); }

/* Pull-tab: hangs from top edge */
body.thd-pos-top #thd-tab {
	bottom: auto; top: 0;
	border-bottom: none; border-top: none;
	border-radius: 0 0 10px 10px;
	transform: translateX(-50%) translateY(-100%);
}

/* Edge trigger: top strip */
body.thd-pos-top #thd-edge { bottom: auto; top: 0; }

/* Drawer mode: slides up to hide */
body.thd-pos-top.thd-drawer              { padding-top: 0 !important; }
body.thd-pos-top.thd-drawer #thd-bar    { transform: translateY(-100%); }
body.thd-pos-top.thd-drawer #thd-tab    { transform: translateX(-50%) translateY(0); }

body.thd-pos-top.thd-drawer.thd-drawer-open              { padding-bottom: 0 !important; padding-top: var(--thd-h) !important; }
body.thd-pos-top.thd-drawer.thd-drawer-open #thd-bar   { transform: translateY(0); }
body.thd-pos-top.thd-drawer.thd-drawer-open #thd-tab   { transform: translateX(-50%) translateY(-100%); }

/* Focus mode: slides up to hide */
body.thd-pos-top.thd-focus                              { padding-top: 0 !important; }
body.thd-pos-top.thd-focus #thd-bar                    { transform: translateY(-100%) !important; }
body.thd-pos-top.thd-focus.thd-peek                    { padding-bottom: 0 !important; padding-top: var(--thd-h) !important; }
body.thd-pos-top.thd-focus.thd-peek #thd-bar           { transform: translateY(0) !important; }
</style>
	<?php
}


// ═══════════════════════════════════════════════════════════════════════════════
// Scripts
// ═══════════════════════════════════════════════════════════════════════════════

function thd_scripts(): void {
	if ( ! thd_active() ) return;
	?>
<script id="therum-admin-dock-js">
(function () {
'use strict';

// ── Elements ──────────────────────────────────────────────────────────────────
var bar         = document.getElementById('thd-bar');
var tab         = document.getElementById('thd-tab');
var edge        = document.getElementById('thd-edge');
var focusBtn    = document.getElementById('thd-focus-btn');
var collapseBtn = document.getElementById('thd-collapse-btn');
var modeBtn     = document.getElementById('thd-mode-btn');
var modePanel   = document.getElementById('thd-mode-panel');
var modeLabel   = document.getElementById('thd-mode-label');
var avatarBtn   = document.getElementById('thd-avatar-btn');
var dropdown    = document.getElementById('thd-dropdown');
var fab         = document.getElementById('thd-fab');
var fabPanel    = document.getElementById('thd-fab-panel');
var fabClose    = document.getElementById('thd-fab-close');
var body        = document.body;

if (!bar) return;

// ── Server-defined defaults (set via Therum OS › Admin Dock settings) ─────────
var serverPos    = bar.dataset.pos         || 'bottom'; // bottom | top
var serverMode   = bar.dataset.defaultMode || 'scroll'; // scroll | always | drawer
var nonce        = bar.dataset.nonce       || '';

// ── State ─────────────────────────────────────────────────────────────────────
var MODE_LABELS  = { always: 'Always on', scroll: 'Auto-hide', drawer: 'Drawer' };

// Safari private browsing throws on localStorage access — guarded reads /
// writes keep the dock working when storage isn't available.
function thdLsGet(k){ try { return localStorage.getItem(k); } catch(e){ return null; } }
function thdLsSet(k,v){ try { localStorage.setItem(k, v); } catch(e){} }

// localStorage wins if the user has made a choice; fall back to admin setting
var mode         = thdLsGet('thd_mode')  || serverMode;
var pos          = thdLsGet('thd_pos')   || serverPos;
var size         = thdLsGet('thd_size')  || 'normal';
var focusOn      = thdLsGet('thd_focus') === '1';
var drawerOpen   = false;
var scrollHidden = false;
var lastY        = window.scrollY;
var ticking      = false;

// ── Apply initial state (no animation on load) ────────────────────────────────
body.style.transition = 'none';
applySize(size, false);
applyPos(pos, false);
applyMode(mode, false);
if (focusOn) applyFocus(true, false);
requestAnimationFrame(function () { body.style.transition = ''; });

// ── Position management ───────────────────────────────────────────────────────
function applyPos(p, saveToServer) {
	pos = (p === 'top') ? 'top' : 'bottom';
	thdLsSet('thd_pos', pos);
	body.classList.toggle('thd-pos-top',    pos === 'top');
	body.classList.toggle('thd-pos-bottom', pos === 'bottom');

	// Update position dots in panel
	document.querySelectorAll('.thd-pos-opt').forEach(function (opt) {
		opt.classList.toggle('is-active', opt.dataset.position === pos);
	});

	// Persist to server so PHP default tracks user choice
	if (saveToServer && nonce) {
		var fd = new FormData();
		fd.append('action', 'therum_save_option');
		fd.append('key',    'th_dock_position');
		fd.append('value',  pos);
		fd.append('nonce',  nonce);
		fetch('/wp-admin/admin-ajax.php', { method: 'POST', body: fd, credentials: 'same-origin' });
	}
}

// Position option selection (inside mode panel)
document.querySelectorAll('.thd-pos-opt').forEach(function (opt) {
	opt.addEventListener('click', function () {
		applyPos(this.dataset.position, true);
		modePanel.classList.remove('is-open');
		modePanel.setAttribute('aria-hidden', 'true');
	});
});

// ── Size management ───────────────────────────────────────────────────────────
function applySize(s) {
	var valid = { slim: 1, normal: 1, large: 1 };
	size = valid[s] ? s : 'normal';
	thdLsSet('thd_size', size);
	body.classList.remove('thd-size-slim', 'thd-size-normal', 'thd-size-large');
	body.classList.add('thd-size-' + size);
	document.querySelectorAll('.thd-size-opt').forEach(function (opt) {
		opt.classList.toggle('is-active', opt.dataset.size === size);
	});
}

// Size option selection (inside mode panel)
document.querySelectorAll('.thd-size-opt').forEach(function (opt) {
	opt.addEventListener('click', function () {
		applySize(this.dataset.size);
		modePanel.classList.remove('is-open');
		modePanel.setAttribute('aria-hidden', 'true');
	});
});

// ── Mode management ───────────────────────────────────────────────────────────
function applyMode(m, animate) {
	mode = m;
	thdLsSet('thd_mode', m);

	if (!animate) {
		bar.style.transition = 'none';
		if (tab) tab.style.transition = 'none';
		requestAnimationFrame(function () {
			bar.style.transition = '';
			if (tab) tab.style.transition = '';
		});
	}

	// Remove all mode classes
	body.classList.remove('thd-always', 'thd-scroll', 'thd-drawer', 'thd-drawer-open', 'thd-offset');
	bar.classList.remove('is-hidden');
	scrollHidden = false;
	drawerOpen   = false;

	// Apply new mode
	body.classList.add('thd-' + m);

	if (m !== 'drawer') {
		body.classList.add('thd-offset');
	}

	// Update mode panel UI
	document.querySelectorAll('.thd-mode-opt').forEach(function (opt) {
		opt.classList.toggle('is-active', opt.dataset.mode === m);
	});
	if (modeLabel) modeLabel.textContent = MODE_LABELS[m] || m;
}

// Mode panel open/close
if (modeBtn) {
	modeBtn.addEventListener('click', function (e) {
		e.stopPropagation();
		var open = modePanel.classList.toggle('is-open');
		modePanel.setAttribute('aria-hidden', open ? 'false' : 'true');
	});
}

// Mode option selection
document.querySelectorAll('.thd-mode-opt').forEach(function (opt) {
	opt.addEventListener('click', function () {
		applyMode(this.dataset.mode, true);
		modePanel.classList.remove('is-open');
		modePanel.setAttribute('aria-hidden', 'true');
	});
});

// ── Drawer ────────────────────────────────────────────────────────────────────
function openDrawer() {
	if (mode !== 'drawer') return;
	drawerOpen = true;
	body.classList.add('thd-drawer-open', 'thd-offset');
}

function closeDrawer() {
	if (mode !== 'drawer') return;
	drawerOpen = false;
	body.classList.remove('thd-drawer-open', 'thd-offset');
}

// Pull-tab: click opens the drawer
if (tab) {
	tab.addEventListener('click', function () {
		openDrawer();
		tab.setAttribute('aria-hidden', 'true');
	});
}

// Collapse button: collapse to drawer or close drawer
if (collapseBtn) {
	collapseBtn.addEventListener('click', function () {
		if (mode === 'drawer') {
			// Already in drawer mode — just collapse it
			closeDrawer();
		} else {
			// Switch to drawer mode and immediately collapse
			applyMode('drawer', true);
		}
	});
}

// ── Scroll-aware (scroll mode only) ──────────────────────────────────────────
function setScrollHidden(hide) {
	if (mode !== 'scroll' || focusOn) return;
	if (hide === scrollHidden) return;
	scrollHidden = hide;
	bar.classList.toggle('is-hidden', hide);
	body.classList.toggle('thd-offset', !hide);
}

window.addEventListener('scroll', function () {
	if (ticking || mode !== 'scroll' || focusOn) return;
	ticking = true;
	requestAnimationFrame(function () {
		var y = window.scrollY;
		if      (y < 8)          setScrollHidden(false); // at top — always show
		else if (y > lastY + 4)  setScrollHidden(true);  // scroll down — hide
		else if (y < lastY - 4)  setScrollHidden(false); // scroll up — show
		lastY   = y;
		ticking = false;
	});
}, { passive: true });

// ── Focus mode ────────────────────────────────────────────────────────────────
function applyFocus(on, animate) {
	focusOn = on;
	thdLsSet('thd_focus', on ? '1' : '0');
	if (!animate) {
		body.style.transition = 'none';
		requestAnimationFrame(function () { body.style.transition = ''; });
	}
	body.classList.toggle('thd-focus', on);
	body.classList.toggle('thd-offset', !on && mode !== 'drawer');
	if (focusBtn) {
		focusBtn.classList.toggle('is-active', on);
		focusBtn.setAttribute('aria-pressed', on ? 'true' : 'false');
	}
}

if (focusBtn) {
	focusBtn.addEventListener('click', function () { applyFocus(!focusOn, true); });
}

// Edge trigger — hover to peek dock in focus mode
var peekTimer;
var hideTimer;

function peekShow() {
	clearTimeout(hideTimer);
	body.classList.add('thd-peek', 'thd-offset');
}
function peekHide() {
	hideTimer = setTimeout(function () {
		body.classList.remove('thd-peek', 'thd-offset');
	}, 180);
}

if (edge) {
	edge.addEventListener('mouseenter', function () {
		clearTimeout(hideTimer);
		peekTimer = setTimeout(peekShow, 120);
	});
	edge.addEventListener('mouseleave', function () {
		clearTimeout(peekTimer);
		peekHide();
	});
	edge.addEventListener('click', function () {
		applyFocus(false, true);
	});
}

// Keep peek alive while mouse is over the bar itself
if (bar) {
	bar.addEventListener('mouseenter', function () {
		if (focusOn) { clearTimeout(hideTimer); peekShow(); }
	});
	bar.addEventListener('mouseleave', function () {
		if (focusOn) peekHide();
	});
}

// ── Avatar dropdown ───────────────────────────────────────────────────────────
function closeAll() {
	if (dropdown)  { dropdown.classList.remove('is-open');  dropdown.setAttribute('aria-hidden', 'true'); }
	if (modePanel) { modePanel.classList.remove('is-open'); modePanel.setAttribute('aria-hidden', 'true'); }
	if (avatarBtn) avatarBtn.setAttribute('aria-expanded', 'false');
}

if (avatarBtn && dropdown) {
	avatarBtn.addEventListener('click', function (e) {
		e.stopPropagation();
		closeAll();
		var open = dropdown.classList.toggle('is-open');
		this.setAttribute('aria-expanded', open ? 'true' : 'false');
		dropdown.setAttribute('aria-hidden', open ? 'false' : 'true');
	});
}

// Close all desktop panels on outside click or Escape
document.addEventListener('click', closeAll);
document.addEventListener('keydown', function (e) {
	if (e.key === 'Escape') { closeAll(); closeFab(); }
});

// ── Shortcut slots ────────────────────────────────────────────────────────────
var SLOT_KEY      = 'thd_shortcuts_v1';
var picker        = document.getElementById('thd-picker');
var pickerSearch  = document.getElementById('thd-picker-search');
var pickerGrid    = document.getElementById('thd-picker-grid');
var pickerClose   = document.getElementById('thd-picker-close');
var txOverlay     = document.getElementById('thd-transition-overlay');
var activeSlot    = -1;
var slots         = [];

// Load saved shortcuts (array of 5, null = empty)
try { slots = JSON.parse(localStorage.getItem(SLOT_KEY) || '[]'); } catch(e) {}
if (!Array.isArray(slots)) slots = [];
while (slots.length < 5) slots.push(null);
slots = slots.slice(0, 5);

function saveSlots() { localStorage.setItem(SLOT_KEY, JSON.stringify(slots)); }

function renderSlots() {
	document.querySelectorAll('.thd-slot').forEach(function(el, i) {
		var sc = slots[i];
		el.classList.toggle('is-filled', !!sc);
		var badge   = el.querySelector('.thd-slot-badge');
		var tooltip = el.querySelector('.thd-slot-tooltip');
		if (sc) {
			badge.textContent = sc.label.slice(0, 2).toUpperCase();
			badge.style.background = sc.color || '#555';
			tooltip.textContent = sc.label;
			el.setAttribute('aria-label', sc.label);
		} else {
			badge.textContent = '';
			badge.style.background = '';
			tooltip.textContent = '';
			el.setAttribute('aria-label', 'Add shortcut');
		}
	});
}

// Slot click — navigate if filled, open picker if empty
document.querySelectorAll('.thd-slot').forEach(function(el, i) {
	el.addEventListener('click', function(e) {
		e.stopPropagation();
		if (slots[i]) {
			navigateWithTransition(slots[i].url);
		} else {
			openPicker(i);
		}
	});
	// Right-click to remove
	el.addEventListener('contextmenu', function(e) {
		if (!slots[i]) return;
		e.preventDefault();
		slots[i] = null;
		saveSlots();
		renderSlots();
	});
	// Keyboard
	el.addEventListener('keydown', function(e) {
		if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); this.click(); }
		if (e.key === 'Delete' || e.key === 'Backspace') {
			if (slots[i]) { slots[i] = null; saveSlots(); renderSlots(); }
		}
	});
});

// Picker open/close
function openPicker(slotIndex) {
	activeSlot = slotIndex;
	if (!picker) return;
	picker.classList.add('is-open');
	picker.setAttribute('aria-hidden', 'false');
	if (pickerSearch) { pickerSearch.value = ''; filterPicker(''); pickerSearch.focus(); }
}
function closePicker() {
	activeSlot = -1;
	if (!picker) return;
	picker.classList.remove('is-open');
	picker.setAttribute('aria-hidden', 'true');
}

if (pickerSearch) {
	pickerSearch.addEventListener('input', function() { filterPicker(this.value); });
	pickerSearch.addEventListener('keydown', function(e) { if (e.key === 'Escape') closePicker(); });
}
if (pickerClose) { pickerClose.addEventListener('click', closePicker); }

function filterPicker(q) {
	if (!pickerGrid) return;
	q = q.toLowerCase().trim();
	pickerGrid.querySelectorAll('.thd-picker-item').forEach(function(item) {
		var label = (item.dataset.label || '').toLowerCase();
		item.style.display = (!q || label.indexOf(q) !== -1) ? '' : 'none';
	});
}

if (pickerGrid) {
	pickerGrid.addEventListener('click', function(e) {
		var item = e.target.closest('.thd-picker-item');
		if (!item || activeSlot < 0) return;
		slots[activeSlot] = {
			label: item.dataset.label,
			url:   item.dataset.url,
			color: item.dataset.color
		};
		saveSlots();
		renderSlots();
		closePicker();
	});
}

// Close picker on outside click
document.addEventListener('click', function(e) {
	if (!picker) return;
	if (!picker.contains(e.target) && !e.target.closest('.thd-slot')) closePicker();
});

renderSlots();

// ── Page transition ───────────────────────────────────────────────────────────
function navigateWithTransition(url) {
	// Append transition signal for wp-admin destinations
	var dest = url;
	if (url.indexOf('/wp-admin') !== -1) {
		dest = url + (url.indexOf('?') !== -1 ? '&' : '?') + 'thd_transition=1';
	}

	// Use View Transitions API if available (Chrome 111+)
	if (document.startViewTransition) {
		document.startViewTransition(function() { window.location.href = dest; });
		return;
	}

	// Fallback: frosted glass overlay fade
	if (txOverlay) {
		txOverlay.classList.add('is-active');
		setTimeout(function() { window.location.href = dest; }, 230);
	} else {
		window.location.href = dest;
	}
}

// ── Mobile FAB ────────────────────────────────────────────────────────────────
function closeFab() {
	if (!fab || !fabPanel) return;
	fabPanel.classList.remove('is-open');
	fab.classList.remove('is-open');
	fab.setAttribute('aria-expanded', 'false');
	fabPanel.setAttribute('aria-hidden', 'true');
}

if (fab && fabPanel) {
	fab.addEventListener('click', function (e) {
		e.stopPropagation();
		var open = !fabPanel.classList.contains('is-open');
		fabPanel.classList.toggle('is-open', open);
		fab.classList.toggle('is-open', open);
		fab.setAttribute('aria-expanded', open ? 'true' : 'false');
		fabPanel.setAttribute('aria-hidden', open ? 'false' : 'true');
	});

	if (fabClose) {
		fabClose.addEventListener('click', function (e) {
			e.stopPropagation();
			closeFab();
		});
	}

	// Close FAB panel on outside click
	document.addEventListener('click', closeFab);

	// Prevent panel clicks from bubbling to the document close handler
	fabPanel.addEventListener('click', function (e) {
		e.stopPropagation();
	});
}

})();
</script>
	<?php
}


// ═══════════════════════════════════════════════════════════════════════════════
// Settings — Admin Dock section in Therum OS › Settings
// ═══════════════════════════════════════════════════════════════════════════════

function thd_register_settings_section( array $sections ): array {
	$sections['admin-dock'] = [
		'key'      => 'admin-dock',
		'label'    => 'Admin Dock',
		'icon'     => 'dock',
		'desc'     => 'Position, default mode, and mobile behaviour of the frontend admin dock.',
		'priority' => 15,
		'render'   => 'thd_render_settings',
	];
	return $sections;
}

function thd_settings_keys( array $keys ): array {
	return array_merge( $keys, [
		'th_dock_position',
		'th_dock_default_mode',
		'th_dock_mobile',
	] );
}

function thd_render_settings(): void {
	$opts  = thd_opts();
	$nonce = wp_create_nonce( 'therum_options' );
	?>
<div class="th-settings-group" data-nonce="<?php echo esc_attr( $nonce ); ?>">
  <div class="th-settings-group-header">
	<div class="th-settings-group-title">Dock position</div>
	<div class="th-settings-group-sub">Where the admin dock appears for logged-in editors on the frontend.</div>
  </div>
  <div class="th-settings-group-body">

	<?php
	therum_setting_row(
		'Desktop & tablet position',
		'Bottom mirrors a macOS dock. Top matches the classic WordPress toolbar.',
		therum_select( 'th_dock_position', $opts['position'], [
			'bottom' => 'Bottom (default)',
			'top'    => 'Top bar',
		] )
	);

	therum_setting_row(
		'Default mode',
		'Applied on first visit before the user makes a personal choice. Their pick persists in localStorage.',
		therum_select( 'th_dock_default_mode', $opts['default_mode'], [
			'scroll' => 'Scroll-aware (default)',
			'always' => 'Always on',
			'drawer' => 'Drawer',
		] )
	);
	?>

  </div>
</div>

<div class="th-settings-group">
  <div class="th-settings-group-header">
	<div class="th-settings-group-title">Mobile</div>
	<div class="th-settings-group-sub">On screens narrower than 768 px the full dock is replaced.</div>
  </div>
  <div class="th-settings-group-body">

	<?php
	therum_setting_row(
		'Mobile style',
		'FAB shows a circular floating button at bottom-right that expands to admin actions. None hides the dock entirely on mobile.',
		therum_select( 'th_dock_mobile', $opts['mobile'], [
			'fab'  => 'FAB — floating action button (default)',
			'none' => 'Hidden on mobile',
		] )
	);
	?>

  </div>
</div>

<div class="th-settings-group">
  <div class="th-settings-group-header">
	<div class="th-settings-group-title">Note</div>
	<div class="th-settings-group-sub">These are site-wide defaults. Each logged-in user can override mode and focus via the dock itself — their choice is stored in localStorage per device.</div>
  </div>
</div>
	<?php
}


// ════════════════════════════════════════════════════════════════════════════
//  3. SETTINGS — from therum-settings.php
// ════════════════════════════════════════════════════════════════════════════

therum_admin_require_page( 'therum-settings.php' );

// Register the default sections. Appearance, Branding, and Site Identity
// were moved to the Admin Theme (Customization) surface — their tab
// registrations live in therum-design.php inside the customization init hook.
add_action('init', function() {
	Therum_Settings::register('plugin-compat',  ['label'=>'Plugin Compatibility', 'icon'=>'plugins',  'desc'=>'Per-plugin compatibility tweaks.', 'priority'=>40,  'render'=>'therum_render_plugin_compat']);
	Therum_Settings::register('security',       ['label'=>'Security',       'icon'=>'shield',    'desc'=>'Hardened defaults.',               'priority'=>50, 'render'=>['Therum_Settings', 'render_security']]);
	Therum_Settings::register('permissions',    ['label'=>'Permissions',    'icon'=>'users',    'desc'=>'Role capabilities.',               'priority'=>60,  'render'=>'therum_render_permissions']);
	Therum_Settings::register('performance',    ['label'=>'Performance',    'icon'=>'gauge',    'desc'=>'Cache, lazy load, defer JS.',      'priority'=>70,  'render'=>'therum_render_performance']);
	Therum_Settings::register('editor',         ['label'=>'Editor Defaults','icon'=>'edit2',    'desc'=>'Bricks defaults, classic editor.', 'priority'=>80,  'render'=>'therum_render_editor_defaults']);
	Therum_Settings::register('uploads',        ['label'=>'Uploads',        'icon'=>'media',    'desc'=>'File types, max size, processing.','priority'=>90,  'render'=>'therum_render_uploads']);
	Therum_Settings::register('notifications',  ['label'=>'Notifications',  'icon'=>'bell', 'desc'=>'Email, Slack, webhooks.',          'priority'=>100, 'render'=>'therum_render_notifications']);
	// API & Webhooks merged into Connections > Manage > 'rest' tab (see therum-connections.php).
	Therum_Settings::register('updates',        ['label'=>'Updates',        'icon'=>'import',   'desc'=>'Auto-update plugins, themes, core.','priority'=>120, 'render'=>'therum_render_updates']);
	Therum_Settings::register('backup',         ['label'=>'Backup',         'icon'=>'db',       'desc'=>'Schedule + restore.',              'priority'=>130, 'render'=>'therum_render_backup']);
	Therum_Settings::register('experiments',    ['label'=>'Experiments',    'icon'=>'plugins',  'desc'=>'Opt-in surfaces and modes.',       'priority'=>135, 'render'=>'therum_render_experiments']);
	Therum_Settings::register('about',          ['label'=>'About',          'icon'=>'info',   'desc'=>'Version, credits, system info.',   'priority'=>140, 'render'=>['Therum_Settings', 'render_about']]);
});

function therum_render_experiments(): void {
	$dm_active = function_exists( 'is_plugin_active' ) && is_plugin_active( 'desktop-mode/desktop-mode.php' );
	$dm_user   = function_exists( 'therum_desktop_mode_active_for_user' ) && therum_desktop_mode_active_for_user();
	$install_url = wp_nonce_url(
		admin_url( 'update.php?action=install-plugin&plugin=desktop-mode' ),
		'install-plugin_desktop-mode'
	);
	$search_url  = admin_url( 'plugin-install.php?s=desktop-mode&tab=search&type=term' );
	$activate_url = wp_nonce_url(
		admin_url( 'plugins.php?action=activate&plugin=desktop-mode/desktop-mode.php' ),
		'activate-plugin_desktop-mode/desktop-mode.php'
	);

	therum_settings_group( 'Desktop Mode', 'Run wp-admin as a windowed desktop OS — draggable windows, a left-edge dock, virtual desktops. Per-user opt-in; Therum\'s shell yields automatically when active. Built by Automattic.', function() use ( $dm_active, $dm_user, $install_url, $search_url, $activate_url ) {
		?>
		<div style="display:flex;gap:14px;align-items:flex-start;flex-wrap:wrap;font-size:13px;color:var(--tx2);line-height:1.55;">
			<div style="flex:1;min-width:280px;">
				<?php if ( ! $dm_active ): ?>
					<p style="margin:0 0 10px 0;"><strong style="color:var(--tx);">Not installed.</strong> Install the official <code>desktop-mode</code> plugin from the WordPress directory to enable.</p>
					<div style="display:flex;gap:8px;flex-wrap:wrap;">
						<a class="th-btn th-btn-primary" href="<?php echo esc_url( $install_url ); ?>">Install Desktop Mode</a>
						<a class="th-btn" href="<?php echo esc_url( $search_url ); ?>">View in plugin directory</a>
						<a class="th-btn" href="https://wordpress.org/plugins/desktop-mode/" target="_blank" rel="noopener">Plugin homepage ↗</a>
					</div>
				<?php elseif ( ! $dm_user ): ?>
					<p style="margin:0 0 10px 0;"><strong style="color:var(--ok);">Installed + active.</strong> Toggle Desktop Mode on for your account from the admin-bar desktop icon (top-right) — Therum's shell will yield automatically.</p>
					<a class="th-btn" href="<?php echo esc_url( admin_url() ); ?>">Open dashboard to toggle</a>
				<?php else: ?>
					<p style="margin:0;"><strong style="color:var(--ok);">Active for your account.</strong> Therum's chrome is yielding to Desktop Mode. Toggle the desktop icon in the admin bar to switch back to Therum's classic shell.</p>
				<?php endif; ?>
			</div>
			<div style="flex:0 0 200px;padding:12px;background:var(--sf2);border-radius:8px;font-size:12px;color:var(--tx3);">
				<div style="font-weight:600;color:var(--tx2);margin-bottom:6px;">How it works</div>
				Therum's <code>therum_admin_shell_bypass</code> filter checks each request — when Desktop Mode is enabled for the current user, Therum's sidebar + topbar don't render, leaving the screen to DM.
			</div>
		</div>
		<?php
	});
}

// ─────────────────────────────────────────────────────────────────────────────
//  AJAX — toggle desktop mode preference per user
// ─────────────────────────────────────────────────────────────────────────────
add_action( 'wp_ajax_therum_toggle_desktop_mode', function() {
	if ( ! current_user_can( 'read' ) ) wp_send_json_error( 'forbidden', 403 );
	$nonce = $_POST['nonce'] ?? '';
	if ( ! wp_verify_nonce( $nonce, 'therum_theme' ) && ! wp_verify_nonce( $nonce, 'therum_options' ) ) {
		wp_send_json_error( 'Invalid nonce.', 403 );
	}
	$user_id = get_current_user_id();

	// Desktop Mode is owned by the companion plugin; its per-user flag
	// (`desktop_mode_mode`) is the single source of truth that both DM's render
	// gates and Therum's shell-yield consult. Toggle THAT so Therum's button
	// genuinely enters/exits the windowed OS. (The old code flipped a private
	// `therum_desktop_mode` meta that nothing rendered from — the button lit up
	// but nothing happened.) Mirror the legacy key for any older reads.
	if ( ! function_exists( 'is_plugin_active' ) ) require_once ABSPATH . 'wp-admin/includes/plugin.php';
	if ( ! is_plugin_active( 'desktop-mode/desktop-mode.php' ) ) {
		wp_send_json_error( [ 'message' => 'The Desktop Mode plugin is not active.', 'installed' => false ], 409 );
	}
	$current = '1' === (string) get_user_meta( $user_id, 'desktop_mode_mode', true );
	$new_val = $current ? '0' : '1';
	update_user_meta( $user_id, 'desktop_mode_mode', $new_val );
	update_user_meta( $user_id, 'therum_desktop_mode', $new_val ); // legacy mirror
	wp_send_json_success( [ 'active' => $new_val === '1' ] );
} );

// Guaranteed exit. When Desktop Mode is active for the user, Therum's shell
// (and its topbar toggle) yields — so add an unmistakable "Exit Desktop Mode"
// node to the WP admin bar (which DM keeps visible) that turns it back off.
add_action( 'admin_bar_menu', function( $bar ) {
	if ( ! is_admin_bar_showing() ) return;
	if ( ! function_exists( 'therum_desktop_mode_active_for_user' ) || ! therum_desktop_mode_active_for_user() ) return;
	$bar->add_node( [
		'id'    => 'therum-exit-desktop',
		'title' => '⊗ Exit Desktop Mode',
		'href'  => wp_nonce_url( admin_url( 'admin-post.php?action=therum_exit_desktop' ), 'therum_exit_desktop' ),
		'meta'  => [ 'title' => 'Turn off Desktop Mode and return to the Therum shell' ],
	] );
}, 100 );

add_action( 'admin_post_therum_exit_desktop', function() {
	if ( ! current_user_can( 'read' ) ) wp_die( 'Forbidden', 403 );
	check_admin_referer( 'therum_exit_desktop' );
	$user_id = get_current_user_id();
	update_user_meta( $user_id, 'desktop_mode_mode', '0' );
	update_user_meta( $user_id, 'therum_desktop_mode', '0' );
	wp_safe_redirect( admin_url( 'admin.php?page=therum' ) );
	exit;
} );

// Register the page itself + repoint sidebar Settings nav at it.
add_action('admin_menu', function() {
	add_submenu_page(null, 'Therum Settings', 'Therum Settings', 'manage_options', 'therum-settings', ['Therum_Settings', 'render_page']);
}, 20);

add_filter('therum_admin_nav_items', function(array $items): array {
	foreach ($items as &$section) {
		if (!isset($section['items'])) continue;
		foreach ($section['items'] as &$it) {
			if ($it['label'] === 'Settings') {
				$it['url']   = 'admin.php?page=therum-settings';
				$it['match'] = 'page=therum-settings';
			}
		}
		unset($it);
	}
	unset($section);
	return $items;
}, 20);

// CSS
add_action( 'admin_enqueue_scripts', function() {
	$path = __DIR__ . '/assets/therum-settings.css';
	wp_enqueue_style( 'therum-settings', plugins_url( 'assets/therum-settings.css', __FILE__ ), [], file_exists( $path ) ? filemtime( $path ) : null );
} );

// JS
add_action( 'admin_enqueue_scripts', function() {
	$path = __DIR__ . '/assets/therum-settings.js';
	wp_enqueue_script( 'therum-settings', plugins_url( 'assets/therum-settings.js', __FILE__ ), [], file_exists( $path ) ? filemtime( $path ) : null, true );
} );



// ═════════════════════════════════════════════════════════════════════════════
//  CONSOLIDATED ADDITIONS — merged from prior patch files (2026-04-27)
//  Includes: 11 section renderers, AJAX field-saver, Style Tile view
// ═════════════════════════════════════════════════════════════════════════════

// Make sure is_plugin_active() is defined when this file loads
if ( ! function_exists( 'is_plugin_active' ) ) {
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
}

// ─────────────────────────────────────────────────────────────────────────────
//  Hook into the registry — swap stub renderers for real ones
// ─────────────────────────────────────────────────────────────────────────────
// ─────────────────────────────────────────────────────────────────────────────
//  AJAX — generic option saver. Handles all the simple toggles + text fields.
//  Posts: action=therum_save_option, key=foo, value=bar, nonce=...
//  Whitelisted via TH_SETTINGS_KEYS so plugins can't write arbitrary options.
// ─────────────────────────────────────────────────────────────────────────────
const TH_SETTINGS_KEYS = [
	// Branding
	'th_logo_url', 'th_favicon_url', 'th_wordmark', 'th_brand_color',
	// Site Identity
	'blogname', 'blogdescription', 'timezone_string', 'WPLANG', 'date_format', 'time_format', 'start_of_week',
	// Plugin Compat
	'th_compat_woo', 'th_compat_bricks', 'th_compat_litespeed', 'th_compat_yoast',
	'th_compat_brickextras', 'th_compat_bricksforge', 'th_compat_advthemer',
	'th_compat_maxaddons', 'th_compat_nextbricks',
	// Permissions
	'default_role',
	// Performance — caching/loading
	'th_perf_cache', 'th_perf_lazy_images', 'th_perf_defer_js', 'th_perf_min_css', 'th_perf_min_html',
	// Performance — strip the bloat
	'th_perf_disable_emoji', 'th_perf_disable_embeds', 'th_perf_heartbeat',
	// Performance — data housekeeping
	'th_perf_revisions_limit', 'th_perf_trash_days', 'th_perf_autosave_interval',
	'th_notify_email', 'th_notify_on_backup',
	'th_backup_s3_bucket', 'th_backup_s3_region', 'th_backup_s3_access_key', 'th_backup_s3_secret_key', 'th_backup_s3_endpoint', 'th_backup_s3_prefix',
	// Editor
	'th_editor_default', 'th_editor_distraction_free', 'th_editor_classic_for_posts',
	// Uploads
	'th_upload_max_mb', 'th_upload_strip_exif', 'th_upload_auto_webp', 'th_upload_resize_max', 'th_allowed_mime_types',
	// Notifications
	'admin_email', 'th_notify_slack_webhook', 'th_notify_on_login', 'th_notify_on_update',
	// API
	'th_rest_enabled', 'th_rest_require_auth', 'th_cors_origins',
	// Updates — namespaced (th_) so they don't collide with WP's own internal
	// `auto_update_plugins` / `auto_update_themes` options (which WP stores as
	// arrays of per-item opt-ins, not booleans). The filter hooks below
	// translate our booleans into the real WP filter return values.
	'th_auto_update_core_major', 'th_auto_update_plugins', 'th_auto_update_themes',
	// Backup
	'th_backup_enabled', 'th_backup_frequency', 'th_backup_destination',
];

add_action( 'wp_ajax_therum_save_option', function() {
	if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'forbidden', 403 );
	check_ajax_referer( 'therum_options', 'nonce' );

	$key   = sanitize_key( $_POST['key'] ?? '' );
	$value = wp_unslash( $_POST['value'] ?? '' );

	$allowed_keys = apply_filters( 'therum_settings_keys', TH_SETTINGS_KEYS );
	if ( ! in_array( $key, $allowed_keys, true ) ) wp_send_json_error( 'unknown key' );

	// Boolean coerce
	if ( in_array( $value, [ 'true', 'false' ], true ) ) $value = ( $value === 'true' );

	// Numeric keys → int cast
	$int_keys = [ 'start_of_week', 'th_upload_max_mb', 'th_upload_resize_max' ];
	if ( in_array( $key, $int_keys, true ) ) $value = (int) $value;

	// Sanitize different field types
	if ( is_string( $value ) ) {
		if ( strpos( $key, '_url' ) !== false || strpos( $key, 'webhook' ) !== false ) $value = esc_url_raw( $value );
		elseif ( strpos( $key, 'email' ) !== false || $key === 'admin_email' ) $value = sanitize_email( $value );
		elseif ( $key === 'th_cors_origins' ) $value = sanitize_textarea_field( $value );
		elseif ( $key === 'blogdescription' ) $value = sanitize_text_field( $value );
		else $value = sanitize_text_field( $value );
	}

	update_option( $key, $value );
	wp_send_json_success( [ 'key' => $key, 'value' => $value ] );
} );

// ─────────────────────────────────────────────────────────────────────────────
//  Auto-update filters — translate Therum's `th_auto_update_*` toggles into
//  WP's actual auto-update decisions. Priority 999 → Therum's toggle is the
//  final word; other plugins (e.g. security plugins forcing auto-updates) get
//  overridden by what the admin set in Settings → Updates.
// ─────────────────────────────────────────────────────────────────────────────

add_filter( 'auto_update_plugin', function( $update, $item ) {
	$opt = get_option( 'th_auto_update_plugins', null );
	return $opt === null ? $update : (bool) $opt;
}, 999, 2 );

add_filter( 'auto_update_theme', function( $update, $item ) {
	$opt = get_option( 'th_auto_update_themes', null );
	return $opt === null ? $update : (bool) $opt;
}, 999, 2 );

add_filter( 'auto_update_core', function( $update, $item ) {
	if ( get_option( 'th_auto_update_core_major', 'enabled' ) !== 'disabled' ) {
		return $update;
	}
	// Major blocked. Allow minor (same major branch) and security patches.
	$cur = isset( $item->current_branch ) ? (string) $item->current_branch : '';
	$new = isset( $item->new_branch ) ? (string) $item->new_branch : '';
	if ( $cur && $new && $cur !== $new ) return false; // different branch → major
	return $update;
}, 999, 2 );

// ─────────────────────────────────────────────────────────────────────────────
//  Helper — emit a settings group with header + body
// ─────────────────────────────────────────────────────────────────────────────
function therum_settings_group( $title, $sub, $body_callback ) {
	?>
	<div class="th-settings-group">
		<div class="th-settings-group-header">
			<div class="th-settings-group-title"><?php echo esc_html( $title ); ?></div>
			<?php if ( $sub ): ?><div class="th-settings-group-sub"><?php echo esc_html( $sub ); ?></div><?php endif; ?>
		</div>
		<div class="th-settings-group-body">
			<?php call_user_func( $body_callback ); ?>
		</div>
	</div>
	<?php
}

// Render a labeled setting row (label on left, control on right)
function therum_setting_row( $label, $help, $control_html ) {
	?>
	<div class="th-setting-row">
		<div class="th-setting-label">
			<div class="th-setting-name"><?php echo esc_html( $label ); ?></div>
			<?php if ( $help ): ?><div class="th-setting-help"><?php echo esc_html( $help ); ?></div><?php endif; ?>
		</div>
		<div class="th-setting-control"><?php echo $control_html; ?></div>
	</div>
	<?php
}

// Toggle (data-th-toggle="key") — wired by the JS at bottom
function therum_toggle( $key, $checked ) {
	$on = $checked ? ' on' : '';
	return '<button type="button" class="th-toggle' . $on . '" data-th-toggle="' . esc_attr( $key ) . '" aria-pressed="' . ( $checked ? 'true' : 'false' ) . '"><span class="th-toggle-knob"></span></button>';
}

// Text input (data-th-text="key")
function therum_text_input( $key, $value, $placeholder = '', $type = 'text' ) {
	return '<input type="' . esc_attr( $type ) . '" class="th-input" data-th-text="' . esc_attr( $key ) . '" value="' . esc_attr( $value ) . '" placeholder="' . esc_attr( $placeholder ) . '" />';
}

// Select (data-th-select="key")
function therum_select( $key, $value, $options ) {
	$html = '<select class="th-select" data-th-select="' . esc_attr( $key ) . '">';
	foreach ( $options as $val => $label ) {
		$sel = ( (string) $val === (string) $value ) ? ' selected' : '';
		$html .= '<option value="' . esc_attr( $val ) . '"' . $sel . '>' . esc_html( $label ) . '</option>';
	}
	$html .= '</select>';
	return $html;
}

// ═════════════════════════════════════════════════════════════════════════════
//  SECTION RENDERERS
// ═════════════════════════════════════════════════════════════════════════════

// ─── BRANDING ───────────────────────────────────────────────────────────────
function therum_render_branding() {
	$logo = get_option( 'th_logo_url', '' );
	$favicon = get_option( 'th_favicon_url', '' );
	$wordmark = get_option( 'th_wordmark', get_bloginfo('name') );
	$brand = get_option( 'th_brand_color', '#e83b3b' );

	therum_settings_group( 'Brand identity', 'Logo, favicon, wordmark used across the admin and login screen.', function() use ( $logo, $favicon, $wordmark, $brand ) {
		therum_setting_row( 'Wordmark', 'Site name shown in sidebar header.', therum_text_input( 'th_wordmark', $wordmark, 'Therum OS' ) );
		therum_setting_row( 'Logo URL', 'Square SVG or PNG. Shown 32×32 in sidebar.', therum_text_input( 'th_logo_url', $logo, 'https://example.com/logo.svg', 'url' ) );
		therum_setting_row( 'Favicon URL', 'Browser tab icon. 32×32 PNG or ICO.', therum_text_input( 'th_favicon_url', $favicon, 'https://example.com/favicon.ico', 'url' ) );
		therum_setting_row( 'Brand color', 'Used as fallback accent if no theme is active.', '<div class="th-color-row"><input type="color" class="th-color" data-th-text="th_brand_color" value="' . esc_attr( $brand ) . '" /><span class="th-color-hex">' . esc_html( $brand ) . '</span></div>' );
	});

	therum_settings_group( 'Preview', 'How your brand appears in the sidebar.', function() use ( $logo, $wordmark, $brand ) {
		?>
		<div class="th-brand-preview">
			<div class="th-brand-logo" style="background:<?php echo esc_attr( $brand ); ?>">
				<?php if ( $logo ): ?><img src="<?php echo esc_url( $logo ); ?>" alt="" /><?php else: ?><?php echo esc_html( strtoupper( substr( $wordmark, 0, 1 ) ) ); ?><?php endif; ?>
			</div>
			<div><div class="th-brand-name"><?php echo esc_html( $wordmark ); ?></div><div class="th-brand-host"><?php echo esc_html( wp_parse_url( home_url(), PHP_URL_HOST ) ); ?></div></div>
		</div>
		<?php
	});
}

// ─── SITE IDENTITY ──────────────────────────────────────────────────────────
function therum_render_site_identity() {
	$name = get_option( 'blogname' );
	$tagline = get_option( 'blogdescription' );
	$tz = get_option( 'timezone_string', 'UTC' );
	$lang = get_option( 'WPLANG', 'en_US' );
	$df = get_option( 'date_format', 'F j, Y' );
	$tf = get_option( 'time_format', 'g:i a' );
	$sow = (int) get_option( 'start_of_week', 1 );

	therum_settings_group( 'Site identity', 'How the world sees this site.', function() use ( $name, $tagline ) {
		therum_setting_row( 'Site title', 'Main name. Appears in browser tabs and SEO.', therum_text_input( 'blogname', $name ) );
		therum_setting_row( 'Tagline', 'Short description. Shown in feeds and search.', therum_text_input( 'blogdescription', $tagline, 'A few words about your site' ) );
	});

	therum_settings_group( 'Locale', 'Time, date, and language preferences.', function() use ( $tz, $lang, $df, $tf, $sow ) {
		// Build timezone list — common ones
		$tz_options = [];
		foreach ( timezone_identifiers_list() as $z ) $tz_options[ $z ] = $z;
		therum_setting_row( 'Timezone', 'Used for scheduled posts and timestamps.', therum_select( 'timezone_string', $tz, $tz_options ) );

		therum_setting_row( 'Date format', 'PHP date() format string.', therum_select( 'date_format', $df, [
			'F j, Y'    => date_i18n('F j, Y'),
			'Y-m-d'     => date_i18n('Y-m-d'),
			'm/d/Y'     => date_i18n('m/d/Y'),
			'd/m/Y'     => date_i18n('d/m/Y'),
			'j M Y'     => date_i18n('j M Y'),
		]));

		therum_setting_row( 'Time format', '', therum_select( 'time_format', $tf, [
			'g:i a' => date_i18n('g:i a'),
			'g:i A' => date_i18n('g:i A'),
			'H:i'   => date_i18n('H:i'),
		]));

		therum_setting_row( 'Week starts on', 'First day of the week in calendar pickers.', therum_select( 'start_of_week', $sow, [
			0 => 'Sunday', 1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday',
			4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday',
		]));
	});
}

// ─── PLUGIN COMPAT ──────────────────────────────────────────────────────────
function therum_render_plugin_compat() {
	$plugins = [
		// Detect Bricks by checking either the theme OR any of its main plugin files (slug varies by version)
		[ 'detect' => function() { return defined('BRICKS_VERSION') || file_exists(WP_CONTENT_DIR.'/themes/bricks/style.css'); },
		  'name' => 'Bricks Builder',           'compat_key' => 'th_compat_bricks',         'note' => 'Page editor. Replaces Gutenberg.' ],
		[ 'slug' => 'woocommerce/woocommerce.php',                           'name' => 'WooCommerce',          'compat_key' => 'th_compat_woo',           'note' => 'Adds Products / Orders / Customers to sidebar via Woo Bridge.' ],
		[ 'slug' => 'litespeed-cache/litespeed-cache.php',                   'name' => 'LiteSpeed Cache',      'compat_key' => 'th_compat_litespeed',     'note' => 'Object cache + page cache. Plays nice with SQLite.' ],
		[ 'slug' => 'bricksextras/bricksextras.php',                         'name' => 'BricksExtras',         'compat_key' => 'th_compat_brickextras',   'note' => 'Bricks element library.' ],
		[ 'slug' => 'bricksforge/plugin.php',                                'name' => 'Bricksforge',          'compat_key' => 'th_compat_bricksforge',   'note' => 'Bricks dynamic forms + animations.' ],
		[ 'slug' => 'bricks-advanced-themer/bricks-advanced-themer.php',     'name' => 'Advanced Themer',      'compat_key' => 'th_compat_advthemer',     'note' => 'Bricks design system extender.' ],
		[ 'slug' => 'max-addons-pro-bricks/max-addons-pro-bricks.php',       'name' => 'Max Addons Pro',       'compat_key' => 'th_compat_maxaddons',     'note' => 'Bricks element pack.' ],
		[ 'slug' => 'nextbricks/nextbricks.php',                             'name' => 'NextBricks',           'compat_key' => 'th_compat_nextbricks',    'note' => 'Bricks layout extensions.' ],
		[ 'slug' => 'wordpress-seo/wp-seo.php',                              'name' => 'Yoast SEO',            'compat_key' => 'th_compat_yoast',         'note' => 'Polyfilled to coexist with Therum theme system.' ],
	];

	// Only surface plugins that are actually installed — "NOT INSTALLED" rows
	// are noise on a real install. Each entry is evaluated via its detect
	// callback (Bricks) or is_plugin_active() (slug), then filtered.
	$detected = array_values( array_filter( $plugins, function( $p ) {
		return isset( $p['detect'] )
			? (bool) call_user_func( $p['detect'] )
			: ( ! empty( $p['slug'] ) && is_plugin_active( $p['slug'] ) );
	} ) );

	if ( empty( $detected ) ) {
		therum_settings_group( 'Detected plugins', 'Compatibility shims for major plugins. Nothing here yet — shims auto-surface when their plugin is active.', function() {
			?>
			<div class="th-compat-empty" style="padding:20px 0;color:var(--tx3);font-size:13px;">
				No supported plugins detected. Activate WooCommerce, Bricks, LiteSpeed Cache, or any of the Bricks add-ons to surface their compatibility shims here.
			</div>
			<?php
		});
	} else {
		therum_settings_group( 'Detected plugins', 'Compatibility shims for major plugins. Keep on unless you know what you\'re doing.', function() use ( $detected ) {
			?>
			<div class="th-compat-list">
			<?php foreach ( $detected as $p ):
				$compat_on = (bool) get_option( $p['compat_key'], true );
			?>
				<div class="th-compat-row">
					<div class="th-compat-info">
						<div class="th-compat-name">
							<?php echo esc_html( $p['name'] ); ?>
							<span class="th-compat-status active">Active</span>
						</div>
						<div class="th-compat-note"><?php echo esc_html( $p['note'] ); ?></div>
					</div>
					<div class="th-compat-toggle">
						<?php echo therum_toggle( $p['compat_key'], $compat_on ); ?>
					</div>
				</div>
			<?php endforeach; ?>
			</div>
			<?php
		});
	}

	therum_settings_group( 'Polyfills (always on)', 'Universal compatibility shims that load before any plugin.', function() {
		?>
		<ul class="th-checklist">
			<li><span class="th-check ok">✓</span> Block editor API stubs (so Gutenberg-aware plugins don't fatal)</li>
			<li><span class="th-check ok">✓</span> WP_Cron compatibility for SQLite</li>
			<li><span class="th-check ok">✓</span> X-Frame-Options removal for self-iframing</li>
			<li><span class="th-check ok">✓</span> Custom Post Type registration hooks</li>
			<li><span class="th-check ok">✓</span> WooCommerce DB schema initializer</li>
		</ul>
		<?php
	});
}

// ─── PERMISSIONS ────────────────────────────────────────────────────────────
function therum_render_permissions() {
	// Defer to roles engine if loaded
	if ( function_exists( 'therum_render_permissions_full' ) ) {
		therum_render_permissions_full();
		return;
	}

	// Fallback if engine isn't loaded for some reason
	$default_role = get_option( 'default_role', 'subscriber' );
	therum_settings_group( 'New user defaults', 'What role new users get when they register.', function() use ( $default_role ) {
		therum_setting_row( 'Default role', 'Capabilities new users start with.', therum_select( 'default_role', $default_role, [
			'subscriber'  => 'Subscriber (read only)',
			'contributor' => 'Contributor (write drafts)',
			'author'      => 'Author (publish own posts)',
			'editor'      => 'Editor (publish/edit any post)',
			'administrator' => 'Administrator (full access)',
		]));
	});
	therum_settings_group( 'Roles engine missing', 'Install therum-roles-engine.php to manage custom roles.', function() {
		echo '<div style="font-size:13px;color:var(--tx3);">Custom role builder requires <code>therum-roles-engine.php</code> in mu-plugins.</div>';
	});
}

// ─── PERFORMANCE ────────────────────────────────────────────────────────────
function therum_render_performance() {
	$cache    = (bool) get_option( 'th_perf_cache', true );
	$lazy_img = (bool) get_option( 'th_perf_lazy_images', true );
	$defer_js = (bool) get_option( 'th_perf_defer_js', false );
	$min_css  = (bool) get_option( 'th_perf_min_css', false );
	$min_html = (bool) get_option( 'th_perf_min_html', false );

	// Resource toggles
	$kill_emoji   = (bool) get_option( 'th_perf_disable_emoji', true );
	$kill_embeds  = (bool) get_option( 'th_perf_disable_embeds', true );
	$heartbeat    = get_option( 'th_perf_heartbeat', 'slow' );
	$revisions    = (int) get_option( 'th_perf_revisions_limit', 5 );
	$trash_days   = (int) get_option( 'th_perf_trash_days', 7 );
	$autosave_int = (int) get_option( 'th_perf_autosave_interval', 120 );

	// Read PHP runtime values (read-only — these are set in php.ini / wp-config)
	$mem_limit       = ini_get( 'memory_limit' );
	$mem_peak        = function_exists('memory_get_peak_usage') ? size_format( memory_get_peak_usage( true ) ) : 'n/a';
	$mem_current     = function_exists('memory_get_usage') ? size_format( memory_get_usage( true ) ) : 'n/a';
	$wp_mem_constant = defined('WP_MEMORY_LIMIT') ? WP_MEMORY_LIMIT : 'not set';

	// ─── PHP RUNTIME ─────────────────────────────────────────────────
	$wp_mem_writable = is_writable( ABSPATH . 'wp-config.php' );
	$mem_nonce       = wp_create_nonce( 'therum_mem_save' );
	therum_settings_group( 'PHP runtime', 'How much memory PHP has to work with on this request.', function() use ( $mem_limit, $mem_current, $mem_peak, $wp_mem_constant, $wp_mem_writable, $mem_nonce ) {
		$presets = [ '128M', '256M', '512M', '768M', '1024M', '2048M', '4096M' ];
		?>
		<div class="th-runtime-grid" data-th-mem-nonce="<?php echo esc_attr( $mem_nonce ); ?>">
			<div class="th-runtime-stat">
				<div class="th-runtime-label">PHP memory_limit</div>
				<div class="th-runtime-value"><?php echo esc_html( $mem_limit ); ?></div>
				<div class="th-runtime-help">Read-only · set in php.ini</div>
			</div>
			<div class="th-runtime-stat is-editable">
				<div class="th-runtime-label">WP_MEMORY_LIMIT</div>
				<?php if ( $wp_mem_writable ): ?>
					<select class="th-runtime-edit" data-th-mem-edit>
						<?php foreach ( $presets as $p ):
							$selected = ( $p === $wp_mem_constant ) ? ' selected' : '';
						?>
							<option value="<?php echo esc_attr( $p ); ?>"<?php echo $selected; ?>><?php echo esc_html( $p ); ?></option>
						<?php endforeach; ?>
						<?php if ( ! in_array( $wp_mem_constant, $presets, true ) && $wp_mem_constant !== 'not set' ): ?>
							<option value="<?php echo esc_attr( $wp_mem_constant ); ?>" selected><?php echo esc_html( $wp_mem_constant ); ?> · current</option>
						<?php endif; ?>
					</select>
					<div class="th-runtime-help" data-th-mem-status>Click to change · saves to wp-config.php</div>
				<?php else: ?>
					<div class="th-runtime-value"><?php echo esc_html( $wp_mem_constant ); ?></div>
					<div class="th-runtime-help">wp-config.php not writable — chmod 644 to edit inline</div>
				<?php endif; ?>
			</div>
			<div class="th-runtime-stat">
				<div class="th-runtime-label">In use now</div>
				<div class="th-runtime-value"><?php echo esc_html( $mem_current ); ?></div>
				<div class="th-runtime-help">Current request</div>
			</div>
			<div class="th-runtime-stat">
				<div class="th-runtime-label">Peak this request</div>
				<div class="th-runtime-value"><?php echo esc_html( $mem_peak ); ?></div>
				<div class="th-runtime-help">High water mark</div>
			</div>
		</div>
		<?php if ( ! $wp_mem_writable ): ?>
		<div class="th-runtime-howto">
			<div class="th-runtime-howto-label">To increase memory, add this to <code>wp-config.php</code> above <code>/* That's all */</code>:</div>
			<div class="th-code-row">
				<code class="th-code-snippet" id="th-mem-snippet">define( 'WP_MEMORY_LIMIT', '512M' );</code>
				<button type="button" class="th-code-copy" data-copy-target="th-mem-snippet">Copy</button>
			</div>
		</div>
		<?php endif; ?>
		<style>
			.th-runtime-grid.is-editable select.th-runtime-edit{ background:var(--sf2);border:1px solid var(--bd);border-radius:7px;padding:4px 8px;font:600 28px/1.15 var(--f);color:var(--tx);min-width:120px;cursor:pointer;letter-spacing:-.02em;margin:2px 0 4px;transition:border-color var(--e),box-shadow var(--e) }
			.th-runtime-grid select.th-runtime-edit:hover{ border-color: var(--tx2) }
			.th-runtime-grid select.th-runtime-edit:focus{ outline:none; border-color: var(--ac); box-shadow: 0 0 0 3px var(--ac-s) }
			.th-runtime-grid .th-runtime-stat.is-editable .th-runtime-label::after{ content:'· editable'; margin-left:6px; font-weight:500; color:var(--ac); letter-spacing:.04em }
		</style>
		<script>
		(function(){
			var sel = document.querySelector('[data-th-mem-edit]');
			if (!sel) return;
			var status = document.querySelector('[data-th-mem-status]');
			var grid = sel.closest('[data-th-mem-nonce]');
			var nonce = grid ? grid.getAttribute('data-th-mem-nonce') : '';
			var ajaxUrl = (window.ajaxurl) || '/wp-admin/admin-ajax.php';
			sel.addEventListener('change', function(){
				var val = sel.value;
				if (status){ status.textContent = 'Saving…'; status.style.color = 'var(--tx3)'; }
				var fd = new FormData();
				fd.append('action', 'therum_save_wp_memory_limit');
				fd.append('value', val);
				fd.append('nonce', nonce);
				fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd })
					.then(function(r){ return r.json(); })
					.then(function(res){
						if (res && res.success){
							if (status){ status.textContent = 'Saved · reloading…'; status.style.color = 'var(--ok)'; }
							setTimeout(function(){ location.reload(); }, 400);
						} else {
							if (status){ status.textContent = (res && res.data && res.data.message) || 'Could not write wp-config.php'; status.style.color = 'var(--err)'; }
						}
					})
					.catch(function(){
						if (status){ status.textContent = 'Network error'; status.style.color = 'var(--err)'; }
					});
			});
		})();
		</script>
		<?php
	});

	// ─── CACHING & LOADING ──────────────────────────────────────────
	therum_settings_group( 'Caching & loading', 'Make pages render faster.', function() use ( $cache, $lazy_img, $defer_js ) {
		therum_setting_row( 'Object cache', 'In-memory cache for queries. SQLite-compatible.', therum_toggle( 'th_perf_cache', $cache ) );
		therum_setting_row( 'Lazy-load images', 'Defer below-fold images until they\'re scrolled into view.', therum_toggle( 'th_perf_lazy_images', $lazy_img ) );
		therum_setting_row( 'Defer non-critical JS', 'Add `defer` attribute to scripts. Speeds up first paint.', therum_toggle( 'th_perf_defer_js', $defer_js ) );
	});

	// ─── KILL THE BLOAT ─────────────────────────────────────────────
	therum_settings_group( 'Strip the bloat', 'Disable WordPress features most sites never use.', function() use ( $kill_emoji, $kill_embeds, $heartbeat ) {
		therum_setting_row( 'Disable emoji scripts', 'Saves ~13KB of inline JS + a sprite. WP injects this on every page.', therum_toggle( 'th_perf_disable_emoji', $kill_emoji ) );
		therum_setting_row( 'Disable oEmbed scripts', 'Saves ~10KB of JS. Only matters if you embed Twitter/Spotify cards.', therum_toggle( 'th_perf_disable_embeds', $kill_embeds ) );
		therum_setting_row( 'Heartbeat frequency', 'WP polls admin-ajax every 15s by default. Slows everything down.', therum_select( 'th_perf_heartbeat', $heartbeat, [
			'off'      => 'Off (no autosave / no realtime)',
			'slow'     => 'Slow (60s) — recommended',
			'default'  => 'Default (15s)',
		]));
	});

	// ─── DATA HOUSEKEEPING ──────────────────────────────────────────
	therum_settings_group( 'Data housekeeping', 'Keep the database lean over time.', function() use ( $revisions, $trash_days, $autosave_int ) {
		therum_setting_row( 'Post revisions kept', 'Each save creates a row. 0 = none, default 5. Lower = leaner DB.', therum_text_input( 'th_perf_revisions_limit', $revisions, '5', 'number' ) );
		therum_setting_row( 'Auto-empty trash after (days)', 'Trashed posts deleted after N days. Default 30.', therum_text_input( 'th_perf_trash_days', $trash_days, '7', 'number' ) );
		therum_setting_row( 'Autosave interval (seconds)', 'How often the editor saves drafts. Default 60.', therum_text_input( 'th_perf_autosave_interval', $autosave_int, '120', 'number' ) );
	});

	// ─── MINIFICATION ───────────────────────────────────────────────
	therum_settings_group( 'Minification', 'Strip whitespace from output. Save bandwidth.', function() use ( $min_css, $min_html ) {
		therum_setting_row( 'Minify CSS', 'Combine and minify stylesheets.', therum_toggle( 'th_perf_min_css', $min_css ) );
		therum_setting_row( 'Minify HTML', 'Strip comments and whitespace from page output.', therum_toggle( 'th_perf_min_html', $min_html ) );
	});

	// ─── PURGE — universal multi-layer cache bust ───────────────────────
	$purge_nonce = wp_create_nonce( 'therum_purge_all' );
	$layers_now  = [];
	$layers_now[] = 'WP object cache';
	if ( function_exists( 'is_plugin_active' ) && is_plugin_active( 'litespeed-cache/litespeed-cache.php' ) ) $layers_now[] = 'LiteSpeed';
	if ( defined( 'BRICKS_VERSION' ) )                                                                       $layers_now[] = 'Bricks';
	$layers_now[] = 'Therum transients';
	therum_settings_group( 'Purge caches', 'Flush every cache layer Therum can reach on this install.', function() use ( $purge_nonce, $layers_now ) {
		?>
		<div class="th-purge-wrap" data-th-purge-nonce="<?php echo esc_attr( $purge_nonce ); ?>" style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
			<button type="button" class="button button-primary" data-th-purge-btn style="background:var(--ac);border-color:var(--ac);">Purge all caches</button>
			<span style="font-size:12px;color:var(--tx3);">Will flush: <?php echo esc_html( implode( ' · ', $layers_now ) ); ?></span>
			<span data-th-purge-status style="font-size:12px;font-weight:500;"></span>
		</div>
		<script>
		(function(){
			var wrap = document.currentScript.closest('.th-purge-wrap');
			if (!wrap) return;
			var btn = wrap.querySelector('[data-th-purge-btn]');
			var status = wrap.querySelector('[data-th-purge-status]');
			var nonce = wrap.getAttribute('data-th-purge-nonce');
			var ajaxUrl = (window.ajaxurl) || '/wp-admin/admin-ajax.php';
			btn.addEventListener('click', function(){
				btn.disabled = true; status.textContent = 'Purging…'; status.style.color = 'var(--tx3)';
				var fd = new FormData();
				fd.append('action', 'therum_purge_all_caches');
				fd.append('nonce', nonce);
				fetch(ajaxUrl, { method:'POST', credentials:'same-origin', body: fd })
					.then(function(r){ return r.json(); })
					.then(function(res){
						btn.disabled = false;
						if (res && res.success) { status.textContent = (res.data && res.data.msg) || 'Purged'; status.style.color = 'var(--ok)'; }
						else                    { status.textContent = (res && res.data && res.data.msg) || 'Failed'; status.style.color = 'var(--err)'; }
						setTimeout(function(){ status.textContent = ''; }, 4000);
					})
					.catch(function(){ btn.disabled = false; status.textContent = 'Network error'; status.style.color = 'var(--err)'; });
			});
		})();
		</script>
		<?php
	});

	if ( function_exists( 'is_plugin_active' ) && is_plugin_active( 'litespeed-cache/litespeed-cache.php' ) ) {
		$lscache_ver = defined( 'LSCWP_V' ) ? LSCWP_V : ( class_exists( 'LiteSpeed\\Core' ) ? 'detected' : '—' );
		therum_settings_group( 'LiteSpeed Cache', 'Detected v' . $lscache_ver . '. Therum settings yield to LSCache when active.', function() {
			?>
			<div style="font-size:13px;color:var(--tx2);line-height:1.55;">
				LiteSpeed Cache is handling caching, image optimization, and minification.
				Use <strong>Purge caches</strong> above to flush LSCache from here, or open the
				<a href="<?php echo esc_url( admin_url('admin.php?page=litespeed') ); ?>" style="color:var(--ac);">LSCache dashboard</a>
				for granular settings.
			</div>
			<?php
		});
	}
}

// ─── EDITOR DEFAULTS ────────────────────────────────────────────────────────
function therum_render_editor_defaults() {
	$default = get_option( 'th_editor_default', 'bricks' );
	$df      = (bool) get_option( 'th_editor_distraction_free', false );
	$classic = (bool) get_option( 'th_editor_classic_for_posts', true );

	therum_settings_group( 'Editor preferences', 'Therum OS uses Bricks Builder by default. Gutenberg stays disabled.', function() use ( $default, $df, $classic ) {
		therum_setting_row( 'Default editor for pages', 'Bricks is the visual builder. Classic is a fallback.', therum_select( 'th_editor_default', $default, [
			'bricks'  => 'Bricks Builder (recommended)',
			'classic' => 'Classic editor',
		]));
		therum_setting_row( 'Classic editor for posts', 'Posts (vs pages) keep classic editor by default. Avoids Bricks overhead.', therum_toggle( 'th_editor_classic_for_posts', $classic ) );
		therum_setting_row( 'Distraction-free mode', 'Hide WP chrome inside the editor for focused writing.', therum_toggle( 'th_editor_distraction_free', $df ) );
	});

	therum_settings_group( 'Bricks shortcuts', 'Quick links to Bricks settings.', function() {
		if ( ! defined('BRICKS_VERSION') ) {
			echo '<div style="font-size:13px;color:var(--tx2);">Bricks not detected. Install + activate Bricks to use these.</div>';
			return;
		}
		?>
		<div class="th-shortcut-grid">
			<a class="th-shortcut" href="<?php echo esc_url( admin_url('admin.php?page=bricks-settings') ); ?>">Bricks Settings</a>
			<a class="th-shortcut" href="<?php echo esc_url( admin_url('admin.php?page=bricks-templates') ); ?>">Templates</a>
			<a class="th-shortcut" href="<?php echo esc_url( admin_url('admin.php?page=bricks-custom-code') ); ?>">Custom code</a>
		</div>
		<?php
	});

	/**
	 * Extension point — sibling mu-plugins (therum-elementor.php, etc.) hook
	 * this to inject their own "X shortcuts" group right after the Bricks
	 * group. Same `therum_settings_group()` API.
	 */
	do_action( 'therum_settings_groups' );
}

// ─── UPLOADS ────────────────────────────────────────────────────────────────
function therum_render_uploads() {
	$max_mb     = (int) get_option( 'th_upload_max_mb', 64 );
	$strip_exif = (bool) get_option( 'th_upload_strip_exif', true );
	$auto_webp  = (bool) get_option( 'th_upload_auto_webp', false );
	$resize_max = (int) get_option( 'th_upload_resize_max', 2560 );
	$auto_rename = (bool) get_option( 'th_renamer_auto', false );

	therum_settings_group( 'Media file renaming', 'Auto-rename uploaded files to match their title or alt text. Renames the file, all intermediate sizes, and rewrites every database reference (post content, postmeta, options).', function() use ( $auto_rename ) {
		therum_setting_row(
			'Auto-rename on title edit',
			'When you change an attachment\'s title or alt text, the file is renamed to match. Manual renames via the kebab \'Rename for SEO\' always work; this toggle just adds an automatic path.',
			therum_toggle( 'th_renamer_auto', $auto_rename )
		);
		?>
		<div style="font-size:11px;color:var(--tx3);padding:6px 14px 0;">
			Risk to know: out-of-database references (CDN caches, hardcoded URLs in custom HTML, external services) aren't touched — same boundary as any rename tool.
		</div>
		<?php
	} );

	// Read PHP runtime values for upload limits
	$php_upload_max  = ini_get( 'upload_max_filesize' );
	$php_post_max    = ini_get( 'post_max_size' );
	$php_max_input   = ini_get( 'max_input_time' );
	$php_max_exec    = ini_get( 'max_execution_time' );

	// Check if .user.ini exists and what it contains
	$user_ini_path   = ABSPATH . '.user.ini';
	$user_ini_writable = ( file_exists( $user_ini_path ) && is_writable( $user_ini_path ) ) || ( ! file_exists( $user_ini_path ) && is_writable( ABSPATH ) );
	$user_ini_content = file_exists( $user_ini_path ) ? file_get_contents( $user_ini_path ) : '';

	// Wanted target — what the user has set in our admin
	$wanted_mb = (int) get_option( 'th_upload_target_mb', 256 );

	// ─── PHP RUNTIME (read-only) ──────────────────────────────────────
	therum_settings_group( 'PHP runtime — upload limits', 'These are set by PHP. Therum can write a .user.ini to override them.', function() use ( $php_upload_max, $php_post_max, $php_max_input, $php_max_exec ) {
		?>
		<div class="th-runtime-grid">
			<div class="th-runtime-stat">
				<div class="th-runtime-label">upload_max_filesize</div>
				<div class="th-runtime-value"><?php echo esc_html( $php_upload_max ); ?></div>
				<div class="th-runtime-help">Per-file ceiling</div>
			</div>
			<div class="th-runtime-stat">
				<div class="th-runtime-label">post_max_size</div>
				<div class="th-runtime-value"><?php echo esc_html( $php_post_max ); ?></div>
				<div class="th-runtime-help">Total POST body</div>
			</div>
			<div class="th-runtime-stat">
				<div class="th-runtime-label">max_execution_time</div>
				<div class="th-runtime-value"><?php echo esc_html( $php_max_exec ); ?>s</div>
				<div class="th-runtime-help">Per-script timeout</div>
			</div>
			<div class="th-runtime-stat">
				<div class="th-runtime-label">max_input_time</div>
				<div class="th-runtime-value"><?php echo esc_html( $php_max_input ); ?>s</div>
				<div class="th-runtime-help">Upload timeout</div>
			</div>
		</div>
		<?php
	});

	// ─── ADJUST RUNTIME (writes .user.ini) ────────────────────────────
	therum_settings_group( 'Adjust upload limits', 'Therum writes these to <code>.user.ini</code> in your WordPress root. PHP picks up changes within a few minutes (immediately if you reload Local).', function() use ( $wanted_mb, $user_ini_writable, $user_ini_path ) {
		if ( ! $user_ini_writable ) {
			echo '<div class="th-warn"><strong>Heads up:</strong> ' . esc_html( $user_ini_path ) . ' is not writable. Therum can\'t adjust these for you.</div>';
			return;
		}
		therum_setting_row( 'Target upload limit (MB)', 'Therum writes upload_max_filesize, post_max_size, and memory_limit so they all line up.', therum_text_input( 'th_upload_target_mb', $wanted_mb, '256', 'number' ) );
		?>
		<div class="th-userini-status">
			<button type="button" class="th-btn th-btn-primary" id="th-write-userini" data-target="<?php echo esc_attr( $wanted_mb ); ?>">Write .user.ini</button>
			<span class="th-userini-msg" id="th-userini-msg"></span>
		</div>
		<?php
	});

	// ─── WP-LEVEL UPLOAD SETTINGS ─────────────────────────────────────
	therum_settings_group( 'WordPress upload rules', 'These apply on top of PHP limits — Therum can be more restrictive but not more permissive.', function() use ( $max_mb, $resize_max ) {
		therum_setting_row( 'Max upload size (MB)', 'Reject uploads above this size, even if PHP allows more.', therum_text_input( 'th_upload_max_mb', $max_mb, '64', 'number' ) );
		therum_setting_row( 'Auto-resize large images (px)', 'Downscale images larger than this on upload. Use 0 to disable.', therum_text_input( 'th_upload_resize_max', $resize_max, '2560', 'number' ) );
	});

	therum_settings_group( 'Image processing', 'Applied automatically on upload.', function() use ( $strip_exif, $auto_webp ) {
		therum_setting_row( 'Strip EXIF data', 'Remove camera/GPS metadata for privacy + smaller files.', therum_toggle( 'th_upload_strip_exif', $strip_exif ) );
		therum_setting_row( 'Auto-convert to WebP', 'Modern format. Smaller files. Generates fallback for older browsers.', therum_toggle( 'th_upload_auto_webp', $auto_webp ) );
	});

	// ─── ALLOWED FILE TYPES ───────────────────────────────────────────
	$saved_types = get_option( 'th_allowed_mime_types', '' );
	$enabled     = $saved_types ? (array) json_decode( $saved_types, true ) : null;

	$type_groups = [
		'Images'    => [
			'jpg|jpeg|jpe' => 'JPEG',
			'png'          => 'PNG',
			'gif'          => 'GIF',
			'webp'         => 'WebP',
			'avif'         => 'AVIF',
			'svg|svgz'     => 'SVG',
			'heic|heif'    => 'HEIC',
			'ico'          => 'ICO',
			'bmp'          => 'BMP',
		],
		'Video'     => [
			'mp4|m4v'      => 'MP4',
			'mov|qt'       => 'MOV',
			'avi'          => 'AVI',
			'webm'         => 'WebM',
			'mkv'          => 'MKV',
			'ogv|ogg'      => 'OGV',
			'wmv'          => 'WMV',
			'flv'          => 'FLV',
		],
		'Audio'     => [
			'mp3|m4a'      => 'MP3',
			'wav'          => 'WAV',
			'ogg|oga'      => 'OGG',
			'flac'         => 'FLAC',
			'aac'          => 'AAC',
		],
		'Documents' => [
			'pdf'          => 'PDF',
			'txt|asc'      => 'TXT',
			'csv'          => 'CSV',
			'doc|docx'     => 'DOCX',
			'xls|xlsx'     => 'XLSX',
			'ppt|pptx'     => 'PPTX',
			'odt'          => 'ODT',
			'rtf'          => 'RTF',
		],
		'Archives'  => [
			'zip'          => 'ZIP',
			'gz|gzip'      => 'GZ',
			'tar'          => 'TAR',
			'7z'           => '7Z',
			'rar'          => 'RAR',
		],
		'Code'      => [
			'json'         => 'JSON',
			'xml'          => 'XML',
			'sql'          => 'SQL',
			'woff|woff2'   => 'WOFF',
			'ttf|otf'      => 'TTF',
		],
	];

	// WordPress-allowed by default (never need enabling)
	$wp_defaults = [ 'jpg|jpeg|jpe', 'png', 'gif', 'webp', 'mp4|m4v', 'mov|qt', 'mp3|m4a', 'ogg|oga', 'wav', 'pdf', 'txt|asc', 'csv' ];

	// If no saved value, treat WP defaults as enabled
	if ( $enabled === null ) {
		$enabled = $wp_defaults;
	}

	therum_settings_group( 'Allowed file types', 'Toggle which file types WordPress will accept in the media uploader. Changes save instantly.', function() use ( $type_groups, $enabled, $wp_defaults ) {
		?>
		<div class="th-mime-grid" id="th-mime-grid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:16px;margin-top:4px;">
		<?php foreach ( $type_groups as $group_label => $types ): ?>
			<div class="th-mime-group" style="background:var(--sf2);border:1px solid var(--bd);border-radius:10px;padding:12px 14px;">
				<div style="font-size:11px;font-weight:600;letter-spacing:0.1em;text-transform:uppercase;color:var(--tx3);margin-bottom:10px;"><?php echo esc_html( $group_label ); ?></div>
				<?php foreach ( $types as $ext => $label ):
					$is_default = in_array( $ext, $wp_defaults, true );
					$is_enabled = in_array( $ext, $enabled, true );
					?>
				<label class="th-mime-row" style="display:flex;align-items:center;justify-content:space-between;gap:8px;padding:5px 0;cursor:pointer;<?php echo $is_default ? 'opacity:0.6;' : ''; ?>"
					<?php if ( $is_default ) echo 'title="WP default — always allowed"'; ?>>
					<span style="font-size:13px;color:var(--tx);"><?php echo esc_html( $label ); ?></span>
					<input type="checkbox" class="th-mime-checkbox" data-ext="<?php echo esc_attr( $ext ); ?>"
						<?php checked( $is_enabled ); ?>
						<?php disabled( $is_default ); ?>
						style="width:16px;height:16px;accent-color:var(--ac);cursor:<?php echo $is_default ? 'not-allowed' : 'pointer'; ?>;">
				</label>
				<?php endforeach; ?>
			</div>
		<?php endforeach; ?>
		</div>
		<div style="margin-top:14px;display:flex;align-items:center;gap:12px;">
			<button type="button" id="th-save-mime-types" class="th-btn th-btn-primary">Save file types</button>
			<span id="th-mime-msg" style="font-size:13px;color:var(--tx2);"></span>
		</div>
		<script>
		(function(){
			document.getElementById('th-save-mime-types').addEventListener('click', function() {
				var checked = [];
				document.querySelectorAll('.th-mime-checkbox:checked').forEach(function(el) {
					checked.push(el.dataset.ext);
				});
				var btn = this, msg = document.getElementById('th-mime-msg');
				btn.disabled = true;
				msg.textContent = 'Saving…';
				var fd = new FormData();
				fd.append('action', 'therum_save_mime_types');
				fd.append('nonce', '<?php echo esc_js( wp_create_nonce('therum_mime_types') ); ?>');
				fd.append('types', JSON.stringify(checked));
				fetch(window.ajaxurl || '/wp-admin/admin-ajax.php', {method:'POST', credentials:'same-origin', body:fd})
					.then(function(r){ return r.json(); })
					.then(function(j){
						btn.disabled = false;
						if (j && j.success) {
							msg.textContent = '✓ Saved';
							msg.style.color = 'var(--ok)';
						} else {
							msg.textContent = '✗ ' + (j && j.data ? j.data : 'Error');
							msg.style.color = 'var(--err)';
						}
						setTimeout(function(){ msg.textContent = ''; }, 3000);
					})
					.catch(function(){ btn.disabled = false; msg.textContent = '✗ Network error'; msg.style.color = 'var(--err)'; });
			});
		})();
		</script>
		<?php
	});
}

// ─── NOTIFICATIONS ──────────────────────────────────────────────────────────
function therum_render_notifications() {
	$admin_email   = get_option( 'admin_email' );
	$slack         = get_option( 'th_notify_slack_webhook', '' );
	$email_enabled = (bool) get_option( 'th_notify_email', true );
	$on_login      = (bool) get_option( 'th_notify_on_login', false );
	$on_update     = (bool) get_option( 'th_notify_on_update', true );
	$on_backup     = (bool) get_option( 'th_notify_on_backup', false );
	$nonce         = wp_create_nonce( 'therum_notify' );

	therum_settings_group( 'Email', 'Where Therum sends transactional emails.', function() use ( $admin_email, $email_enabled ) {
		therum_setting_row( 'Admin email', 'Receives security alerts, update notices, etc.', therum_text_input( 'admin_email', $admin_email, 'you@example.com', 'email' ) );
		therum_setting_row( 'Email enabled', 'Master switch — disable to silence all email notifications.', therum_toggle( 'th_notify_email', $email_enabled ) );
	});

	therum_settings_group( 'Slack', 'Pipe site events into a Slack channel.', function() use ( $slack ) {
		therum_setting_row( 'Webhook URL', 'Get from Slack → Apps → Incoming Webhooks.', therum_text_input( 'th_notify_slack_webhook', $slack, 'https://hooks.slack.com/services/...', 'url' ) );
	});

	therum_settings_group( 'Triggers', 'When to fire notifications.', function() use ( $on_login, $on_update, $on_backup ) {
		therum_setting_row( 'New admin login', 'Notify when a user with admin caps logs in.', therum_toggle( 'th_notify_on_login', $on_login ) );
		therum_setting_row( 'Plugin/theme updates', 'Notify when something is auto-updated.', therum_toggle( 'th_notify_on_update', $on_update ) );
		therum_setting_row( 'Backup completed', 'Notify when a scheduled or manual backup finishes.', therum_toggle( 'th_notify_on_backup', $on_backup ) );
	});

	therum_settings_group( 'Test', 'Send a test notification to confirm everything works.', function() use ( $nonce ) {
		?>
		<div class="th-setting-row">
		  <div class="th-setting-label">
			<div class="th-setting-name">Send test</div>
			<div class="th-setting-help">Fires both email and Slack with a test message right now.</div>
		  </div>
		  <div class="th-setting-control" style="display:flex;align-items:center;gap:10px;text-align:right;">
			<span class="th-notify-test-result" data-th-notify-result style="font-size:12px;"></span>
			<button type="button" class="th-button th-button-primary" data-th-notify-test data-nonce="<?php echo esc_attr( $nonce ); ?>">Send test notification</button>
		  </div>
		</div>
		<script>
		(function() {
		  var btn = document.querySelector('[data-th-notify-test]');
		  if (!btn) return;
		  btn.addEventListener('click', function() {
			var nonce = btn.getAttribute('data-nonce');
			var res = document.querySelector('[data-th-notify-result]');
			res.textContent = 'Sending…';
			res.style.color = 'var(--tx2)';
			var fd = new FormData();
			fd.append('action', 'therum_notify_test');
			fd.append('nonce', nonce);
			fetch(window.ajaxurl || '/wp-admin/admin-ajax.php', { method:'POST', credentials:'same-origin', body: fd })
			  .then(function(r){ return r.json(); })
			  .then(function(j){
				if (j && j.success) { res.textContent = '✓ Test sent. Check inbox/Slack.'; res.style.color = 'var(--ok)'; }
				else { res.textContent = '✗ ' + ((j && j.data) || 'Failed'); res.style.color = 'var(--err)'; }
			  })
			  .catch(function(e){ res.textContent = '✗ Network error'; res.style.color = 'var(--err)'; });
		  });
		})();
		</script>
		<?php
	});
}

// ─── API & WEBHOOKS ─────────────────────────────────────────────────────────
function therum_render_api_webhooks() {
	// Delegate to API engine if loaded (gives full webhooks UI)
	if ( function_exists( 'therum_render_api_full' ) ) {
		therum_render_api_full();
		return;
	}

	// Fallback if engine isn't loaded
	$rest_enabled  = (bool) get_option( 'th_rest_enabled', true );
	$rest_auth_req = (bool) get_option( 'th_rest_require_auth', false );
	$cors          = get_option( 'th_cors_origins', '' );

	therum_settings_group( 'REST API', 'WP\'s built-in REST API at /wp-json/.', function() use ( $rest_enabled, $rest_auth_req ) {
		therum_setting_row( 'REST API enabled', 'Disable to fully lock down /wp-json/ endpoints.', therum_toggle( 'th_rest_enabled', $rest_enabled ) );
		therum_setting_row( 'Require authentication', 'Block unauthenticated reads. Recommended.', therum_toggle( 'th_rest_require_auth', $rest_auth_req ) );
	});
	therum_settings_group( 'CORS', 'Allow cross-origin requests from these domains.', function() use ( $cors ) {
		therum_setting_row( 'Allowed origins', 'One per line. Use * for wildcard (not recommended).', '<textarea class="th-input th-textarea" data-th-text="th_cors_origins" rows="4" placeholder="https://app.example.com">' . esc_textarea( $cors ) . '</textarea>' );
	});
	therum_settings_group( 'API engine missing', 'Install therum-api-engine.php to manage webhooks.', function() {
		echo '<div style="font-size:13px;color:var(--tx3);">Webhook builder requires <code>therum-api-engine.php</code> in mu-plugins.</div>';
	});
}

// ─── UPDATES ────────────────────────────────────────────────────────────────
function therum_render_updates() {
	$core_major = get_option( 'th_auto_update_core_major', 'enabled' );
	$plugins    = (bool) get_option( 'th_auto_update_plugins', true );
	$themes     = (bool) get_option( 'th_auto_update_themes', true );

	$wp_ver = get_bloginfo( 'version' );

	therum_settings_group( 'Versions', 'What\'s installed and what\'s available.', function() use ( $wp_ver ) {
		?>
		<div class="th-version-grid">
			<div><span class="th-version-label">Therum OS</span><strong>v<?php echo defined('THERUM_OS_VERSION') ? esc_html(THERUM_OS_VERSION) : '?'; ?></strong></div>
			<div><span class="th-version-label">WordPress</span>v<?php echo esc_html( $wp_ver ); ?></div>
			<div><span class="th-version-label">PHP</span><?php echo esc_html( PHP_VERSION ); ?></div>
		</div>
		<?php
	});

	therum_settings_group( 'Auto-updates', 'What gets updated without asking.', function() use ( $core_major, $plugins, $themes ) {
		therum_setting_row( 'WordPress core (major)', 'Major version updates (e.g. 6.4 → 6.5). Minor security patches always auto-update.', therum_select( 'th_auto_update_core_major', $core_major, [
			'enabled'  => 'On — auto-update major releases',
			'disabled' => 'Off — manual only',
		]));
		therum_setting_row( 'Plugins', 'Auto-update active plugins.', therum_toggle( 'th_auto_update_plugins', $plugins ) );
		therum_setting_row( 'Themes', 'Auto-update installed themes.', therum_toggle( 'th_auto_update_themes', $themes ) );
	});
}

// ─── BACKUP ─────────────────────────────────────────────────────────────────
function therum_render_backup() {
	$enabled    = (bool) get_option( 'th_backup_enabled', false );
	$freq       = get_option( 'th_backup_frequency', 'daily' );
	$dest       = get_option( 'th_backup_destination', 'local' );
	$s3_bucket  = get_option( 'th_backup_s3_bucket', '' );
	$s3_region  = get_option( 'th_backup_s3_region', 'us-east-1' );
	$s3_access  = get_option( 'th_backup_s3_access_key', '' );
	$s3_secret  = get_option( 'th_backup_s3_secret_key', '' );
	$s3_endpoint= get_option( 'th_backup_s3_endpoint', '' );
	$s3_prefix  = get_option( 'th_backup_s3_prefix', 'therum-backups' );
	$last_run   = get_option( 'th_backup_last_run', null );
	$history    = get_option( 'th_backup_history', [] );
	$nonce      = wp_create_nonce( 'therum_backup' );

	$next_scheduled = wp_next_scheduled( 'therum_backup_run' );

	therum_settings_group( 'Schedule', 'Automatic snapshots of your site.', function() use ( $enabled, $freq, $next_scheduled ) {
		therum_setting_row( 'Enable backups', 'Schedule recurring backups via WP cron.', therum_toggle( 'th_backup_enabled', $enabled ) );
		therum_setting_row( 'Frequency', 'How often to back up. Cron runs on traffic, so quiet sites lag.', therum_select( 'th_backup_frequency', $freq, [
			'hourly'     => 'Every hour',
			'twicedaily' => 'Twice daily',
			'daily'      => 'Daily',
			'weekly'     => 'Weekly',
		]));
		if ( $next_scheduled ) {
			?>
			<div class="th-setting-row">
			  <div class="th-setting-label">
				<div class="th-setting-name">Next run</div>
				<div class="th-setting-help">When the next scheduled backup will fire.</div>
			  </div>
			  <div class="th-setting-control" style="color:var(--tx);font-weight:500;">
				<?php echo esc_html( wp_date( 'M j, Y · g:i a', $next_scheduled ) ); ?>
			  </div>
			</div>
			<?php
		}
	});

	therum_settings_group( 'Run on demand', 'Trigger a backup right now.', function() use ( $nonce ) {
		?>
		<div class="th-setting-row">
		  <div class="th-setting-label">
			<div class="th-setting-name">Backup now</div>
			<div class="th-setting-help">Builds a fresh zip in <code>wp-content/backups/</code>. Uploads to S3 if configured. May take 30s–2min depending on site size.</div>
		  </div>
		  <div class="th-setting-control" style="display:flex;align-items:center;gap:10px;text-align:right;flex-wrap:wrap;justify-content:flex-end;">
			<span class="th-backup-result" data-th-backup-result style="font-size:12px;"></span>
			<button type="button" class="th-button th-button-primary" data-th-backup-now data-nonce="<?php echo esc_attr( $nonce ); ?>">Run backup now</button>
		  </div>
		</div>
		<script>
		(function(){
		  var btn = document.querySelector('[data-th-backup-now]');
		  if (!btn) return;
		  btn.addEventListener('click', function() {
			var nonce = btn.getAttribute('data-nonce');
			var res = document.querySelector('[data-th-backup-result]');
			res.textContent = 'Running… do not close tab.';
			res.style.color = 'var(--tx2)';
			btn.disabled = true; btn.style.opacity = '0.6';
			var fd = new FormData();
			fd.append('action', 'therum_backup_now');
			fd.append('nonce', nonce);
			fetch(window.ajaxurl || '/wp-admin/admin-ajax.php', { method:'POST', credentials:'same-origin', body: fd })
			  .then(function(r){ return r.json(); })
			  .then(function(j){
				btn.disabled = false; btn.style.opacity = '';
				if (j && j.success) {
				  var size = j.data && j.data.size ? Math.round(j.data.size / 1024 / 1024 * 10) / 10 + ' MB' : '';
				  // Build via DOM nodes — never via innerHTML on server-supplied
				  // strings. The filename is server-controlled today but a
				  // defense-in-depth escape costs nothing.
				  res.textContent = '';
				  res.appendChild(document.createTextNode('✓ Backup created: '));
				  var strong = document.createElement('strong');
				  strong.textContent = (j.data && j.data.file) || '';
				  res.appendChild(strong);
				  if (size) res.appendChild(document.createTextNode(' (' + size + ')'));
				  res.style.color = 'var(--ok)';
				  setTimeout(function(){ location.reload(); }, 1500);
				} else {
				  res.textContent = '✗ ' + ((j && j.data) || 'Failed');
				  res.style.color = 'var(--err)';
				}
			  })
			  .catch(function(e){ btn.disabled = false; btn.style.opacity=''; res.textContent = '✗ Network error'; res.style.color = 'var(--err)'; });
		  });
		})();
		</script>
		<?php
	});

	therum_settings_group( 'Destination', 'Where backup files are stored.', function() use ( $dest ) {
		therum_setting_row( 'Backup destination', 'Local stays in wp-content/backups. S3 uploads after each run.', therum_select( 'th_backup_destination', $dest, [
			'local' => 'Local only',
			's3'    => 'Local + S3',
		]));
	});

	if ( $dest === 's3' ) {
		therum_settings_group( 'S3 credentials', 'Works with AWS S3 and S3-compatible services (Cloudflare R2, Wasabi, MinIO).', function() use ( $s3_bucket, $s3_region, $s3_access, $s3_secret, $s3_endpoint, $s3_prefix ) {
			therum_setting_row( 'Bucket', 'The bucket where backups are stored.', therum_text_input( 'th_backup_s3_bucket', $s3_bucket, 'my-site-backups' ) );
			therum_setting_row( 'Region', 'AWS region code. Use auto for R2/Wasabi.', therum_text_input( 'th_backup_s3_region', $s3_region, 'us-east-1' ) );
			therum_setting_row( 'Access key', 'IAM access key with PutObject permission.', therum_text_input( 'th_backup_s3_access_key', $s3_access, 'AKIAxxxxxxxxxxxxxxxx' ) );
			therum_setting_row( 'Secret key', 'Secret access key. Stored in WP options.', therum_text_input( 'th_backup_s3_secret_key', $s3_secret, '••••••••••••••••', 'password' ) );
			therum_setting_row( 'Endpoint (optional)', 'Custom endpoint for non-AWS providers. Leave blank for AWS.', therum_text_input( 'th_backup_s3_endpoint', $s3_endpoint, 'r2.cloudflare.com (optional)' ) );
			therum_setting_row( 'Path prefix', 'Folder inside the bucket. Defaults to therum-backups.', therum_text_input( 'th_backup_s3_prefix', $s3_prefix, 'therum-backups' ) );
		});
	}

	if ( ! empty( $history ) ) {
		therum_settings_group( 'Recent backups', 'Last 10 backups stored locally. Older are pruned automatically.', function() use ( $history ) {
			?>
			<table class="th-roles-table" style="width:100%;">
			  <thead><tr><th>File</th><th>Size</th><th>Trigger</th><th>When</th></tr></thead>
			  <tbody>
				<?php foreach ( array_slice( $history, 0, 10 ) as $h ): ?>
				<tr>
				  <td><code style="background:var(--sf2);padding:2px 6px;border-radius:4px;font-size:11px;"><?php echo esc_html( $h['file'] ); ?></code></td>
				  <td><?php echo esc_html( size_format( (int) ($h['size'] ?? 0) ) ); ?></td>
				  <td style="text-transform:capitalize;"><?php echo esc_html( $h['trigger'] ?? 'auto' ); ?></td>
				  <td><?php echo esc_html( wp_date( 'M j · g:i a', (int) ($h['time'] ?? 0) ) ); ?></td>
				</tr>
				<?php endforeach; ?>
			  </tbody>
			</table>
			<?php
		});
	}

	therum_settings_group( 'SQLite database', 'Therum runs on SQLite — your DB is a single file inside the zip.', function() {
		?>
		<div style="font-size:13px;color:var(--tx2);line-height:1.6;">
			Your database lives at <code style="background:var(--sf2);padding:2px 6px;border-radius:4px;font-size:12px;">wp-content/database/.ht.sqlite</code>.
			The backup zip captures it as <code style="background:var(--sf2);padding:2px 6px;border-radius:4px;font-size:12px;">database.sqlite</code>.
			To restore: drop the file back in place. No <code>mysqldump</code> nonsense.
		</div>
		<?php
	});
}

// ═════════════════════════════════════════════════════════════════════════════
//  CSS — for the new controls + redesigned search header
// ═════════════════════════════════════════════════════════════════════════════
add_action( 'admin_enqueue_scripts', function() {
	$page = $_GET['page'] ?? '';
	if ( $page !== 'therum-settings' ) return;
	$path = __DIR__ . '/assets/therum-settings-content.css';
	wp_enqueue_style( 'therum-settings-content', plugins_url( 'assets/therum-settings-content.css', __FILE__ ), [ 'therum-settings' ], file_exists( $path ) ? filemtime( $path ) : null );
} );


// ═════════════════════════════════════════════════════════════════════════════
//  JS — wire toggles, inputs, selects to AJAX
// ═════════════════════════════════════════════════════════════════════════════
add_action( 'admin_footer', function() {
	$page = $_GET['page'] ?? '';
	if ( $page !== 'therum-settings' ) return;
	$nonce = wp_create_nonce( 'therum_options' );
	?>
<script id="therum-settings-content-js">
(function() {
	'use strict';
	var ajaxUrl = window.ajaxurl || '/wp-admin/admin-ajax.php';
	var nonce   = '<?php echo esc_js( $nonce ); ?>';

	function save(key, value, el) {
		if (el) el.classList.add('saving');
		var fd = new FormData();
		fd.append('action', 'therum_save_option');
		fd.append('key', key);
		fd.append('value', value);
		fd.append('nonce', nonce);
		return fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd })
			.then(function(r) { return r.json(); })
			.then(function(res) {
				if (el) {
					el.classList.remove('saving');
					el.classList.add('saved');
					setTimeout(function() { el.classList.remove('saved'); }, 1500);
				}
				return res;
			});
	}

	// Toggles
	document.querySelectorAll('[data-th-toggle]').forEach(function(t) {
		t.addEventListener('click', function() {
			var newOn = !t.classList.contains('on');
			t.classList.toggle('on', newOn);
			t.setAttribute('aria-pressed', newOn ? 'true' : 'false');
			save(t.dataset.thToggle, newOn ? 'true' : 'false', t);
		});
	});

	// Text inputs (debounce on type, save on blur)
	document.querySelectorAll('[data-th-text]').forEach(function(input) {
		var timer;
		input.addEventListener('input', function() {
			clearTimeout(timer);
			timer = setTimeout(function() { save(input.dataset.thText, input.value, input); }, 600);
		});
		input.addEventListener('blur', function() {
			clearTimeout(timer);
			save(input.dataset.thText, input.value, input);
		});
	});

	// Selects (save on change)
	document.querySelectorAll('[data-th-select]').forEach(function(sel) {
		sel.addEventListener('change', function() {
			save(sel.dataset.thSelect, sel.value, sel);
		});
	});

	// Color picker → update hex display LIVE, save on change (not every drag tick)
	document.querySelectorAll('input.th-color').forEach(function(c) {
		c.addEventListener('input', function() {
			var hex = c.parentElement.querySelector('.th-color-hex');
			if (hex) hex.textContent = c.value;
		});
		// Color picker has a separate 'change' event when user releases — save then
		c.addEventListener('change', function() {
			save(c.dataset.thText, c.value, c);
		});
	});

	// Override: color picker shouldn't be in the data-th-text input handler (that's debounced typing)
	// We need to disable that handler for color inputs specifically — or just let it run, it's debounced anyway.
	// (No-op: the existing data-th-text handler debounces, which is fine for color pickers too.)

})();
</script>
	<?php
} );

//  FIX 4 — STYLE TILE VIEW (real preview with text + colors + UI elements)
//  When body[data-theme-view="tiles"] is set, replace card content with a
//  rendered "style tile" showing: heading sample + body text + color swatches
//  + button preview, all using the theme's actual colors.
// ═════════════════════════════════════════════════════════════════════════════
add_action( 'admin_enqueue_scripts', function() {
	$page    = $_GET['page']    ?? '';
	$section = $_GET['section'] ?? 'appearance';
	if ( $page !== 'therum-settings' || ( $section !== 'appearance' && $section !== '' ) ) return;
	$css_path = __DIR__ . '/assets/therum-settings-tile.css';
	$js_path  = __DIR__ . '/assets/therum-settings-tile.js';
	wp_enqueue_style(  'therum-settings-tile', plugins_url( 'assets/therum-settings-tile.css', __FILE__ ), [ 'therum-settings' ], file_exists( $css_path ) ? filemtime( $css_path ) : null );
	wp_enqueue_script( 'therum-settings-tile', plugins_url( 'assets/therum-settings-tile.js',  __FILE__ ), [], file_exists( $js_path ) ? filemtime( $js_path ) : null, true );
}, 110 );


// ═════════════════════════════════════════════════════════════════════════════


// ─────────────────────────────────────────────────────────────────────────────
//  AJAX — persist theme view mode (simple / advanced) per-user
// ─────────────────────────────────────────────────────────────────────────────
add_action( 'wp_ajax_therum_save_view_pref', function() {
	if ( ! current_user_can( 'read' ) ) wp_send_json_error( 'forbidden', 403 );
	// Called from both Settings (nonce='therum_options') and Customization (nonce='therum_theme')
	$nonce = $_POST['nonce'] ?? $_REQUEST['_wpnonce'] ?? '';
	if ( ! wp_verify_nonce( $nonce, 'therum_theme' ) && ! wp_verify_nonce( $nonce, 'therum_options' ) ) {
		wp_send_json_error( 'Invalid or expired nonce.', 403 );
	}
	$mode = sanitize_key( $_POST['mode'] ?? 'simple' );
	if ( ! in_array( $mode, [ 'simple', 'advanced' ], true ) ) $mode = 'simple';
	update_user_meta( get_current_user_id(), 'therum_pref_theme_view_mode', $mode );
	wp_send_json_success( [ 'mode' => $mode ] );
} );


// ─────────────────────────────────────────────────────────────────────────────
//  AJAX — save a single theme state field (density, sidebarStyle, mode, glass)
// ─────────────────────────────────────────────────────────────────────────────
add_action( 'wp_ajax_therum_save_state_field', function() {
	if ( ! current_user_can( 'read' ) ) wp_send_json_error( 'forbidden', 403 );

	// This endpoint is called from two surfaces:
	//   1. Settings → Appearance  (nonce action = 'therum_options')
	//   2. Customization → Quick Controls  (nonce action = 'therum_theme')
	// Accept either nonce so saves work from both pages.
	$nonce = $_POST['nonce'] ?? $_REQUEST['_wpnonce'] ?? '';
	$valid = wp_verify_nonce( $nonce, 'therum_theme' ) || wp_verify_nonce( $nonce, 'therum_options' );
	if ( ! $valid ) {
		wp_send_json_error( 'Invalid or expired nonce.', 403 );
	}

	if ( ! class_exists( 'Therum_Themes' ) ) wp_send_json_error( 'no themes class' );

	// Case-insensitive field matching: sanitize_key() lowercases, so we map
	// lowercased input back to the original camelCase key in default_state().
	$raw_field = sanitize_text_field( wp_unslash( $_POST['field'] ?? '' ) );
	$value     = sanitize_text_field( wp_unslash( $_POST['value'] ?? '' ) );

	$allowed = array_keys( Therum_Themes::default_state() );
	$lookup  = [];
	foreach ( $allowed as $a ) { $lookup[ strtolower( $a ) ] = $a; }
	$field = $lookup[ strtolower( $raw_field ) ] ?? '';
	if ( $field === '' ) wp_send_json_error( 'bad field: ' . $raw_field );

	// Coerce value type based on default_state() — same approach as ajax_save_field()
	$defaults = Therum_Themes::default_state();
	if ( is_bool( $defaults[ $field ] ?? null ) ) {
		$value = filter_var( $value, FILTER_VALIDATE_BOOLEAN );
	} elseif ( is_int( $defaults[ $field ] ?? null ) ) {
		$value = (int) $value;
	}
	// Legacy string coercion fallback
	if ( $value === 'true' )  $value = true;
	elseif ( $value === 'false' ) $value = false;

	$state = Therum_Themes::get_state();
	$state[ $field ] = $value;
	Therum_Themes::save_user_state( $state );

	wp_send_json_success( $state );
} );


// ═════════════════════════════════════════════════════════════════════════════
//  PERFORMANCE FUNCTIONALITY — make the toggles actually do something
// ═════════════════════════════════════════════════════════════════════════════

// Disable emoji scripts
add_action( 'init', function() {
	if ( ! get_option( 'th_perf_disable_emoji', true ) ) return;
	remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
	remove_action( 'admin_print_scripts', 'print_emoji_detection_script' );
	remove_action( 'wp_print_styles', 'print_emoji_styles' );
	remove_action( 'admin_print_styles', 'print_emoji_styles' );
	remove_filter( 'the_content_feed', 'wp_staticize_emoji' );
	remove_filter( 'comment_text_rss', 'wp_staticize_emoji' );
	remove_filter( 'wp_mail', 'wp_staticize_emoji_for_email' );
	add_filter( 'tiny_mce_plugins', function( $p ) { return is_array($p) ? array_diff($p, ['wpemoji']) : $p; } );
}, 11 );

// Disable embed scripts (oEmbed)
add_action( 'init', function() {
	if ( ! get_option( 'th_perf_disable_embeds', true ) ) return;
	remove_action( 'wp_head', 'wp_oembed_add_discovery_links' );
	remove_action( 'wp_head', 'wp_oembed_add_host_js' );
	remove_filter( 'oembed_dataparse', 'wp_filter_oembed_result', 10 );
	add_filter( 'embed_oembed_discover', '__return_false' );
	add_filter( 'tiny_mce_plugins', function( $p ) { return is_array($p) ? array_diff($p, ['wpembed']) : $p; } );
	add_filter( 'rewrite_rules_array', function( $rules ) {
		foreach ( $rules as $rule => $rewrite ) {
			if ( false !== strpos( (string)$rewrite, 'embed=true' ) ) unset( $rules[ $rule ] );
		}
		return $rules;
	});
}, 11 );

// Heartbeat throttle
add_filter( 'heartbeat_settings', function( $settings ) {
	$mode = get_option( 'th_perf_heartbeat', 'slow' );
	if ( $mode === 'slow' )    $settings['interval'] = 60;
	if ( $mode === 'default' ) $settings['interval'] = 15;
	return $settings;
});
add_action( 'init', function() {
	if ( get_option( 'th_perf_heartbeat', 'slow' ) === 'off' ) {
		wp_deregister_script( 'heartbeat' );
	}
}, 1 );

// Post revisions limit
add_filter( 'wp_revisions_to_keep', function( $num, $post ) {
	$limit = (int) get_option( 'th_perf_revisions_limit', 5 );
	return $limit;
}, 10, 2 );

// Empty trash interval
add_filter( 'pre_option_EMPTY_TRASH_DAYS', function( $val ) {
	$days = (int) get_option( 'th_perf_trash_days', 7 );
	return $days > 0 ? $days : $val;
});

// Autosave interval
add_action( 'admin_init', function() {
	$interval = (int) get_option( 'th_perf_autosave_interval', 120 );
	if ( $interval > 0 && function_exists( 'wp_register_script' ) ) {
		wp_deregister_script( 'autosave' );
		wp_register_script( 'autosave', '/wp-includes/js/autosave.min.js', [ 'heartbeat' ], false, 1 );
	}
	// The actual interval comes from a JS variable WP injects — override via filter:
	add_filter( 'block_editor_settings_all', function( $settings ) use ( $interval ) {
		$settings['autosaveInterval'] = $interval;
		return $settings;
	});
});

// Lazy load images (WP has it native — just make sure it stays on)
add_filter( 'wp_lazy_loading_enabled', function( $default ) {
	return (bool) get_option( 'th_perf_lazy_images', true );
});

// Defer non-critical JS
add_filter( 'script_loader_tag', function( $tag, $handle, $src ) {
	if ( ! get_option( 'th_perf_defer_js', false ) ) return $tag;
	if ( is_admin() ) return $tag;
	// Skip critical scripts
	$skip = [ 'jquery', 'jquery-core', 'jquery-migrate' ];
	if ( in_array( $handle, $skip, true ) ) return $tag;
	if ( strpos( $tag, ' defer' ) !== false || strpos( $tag, ' async' ) !== false ) return $tag;
	return str_replace( '<script ', '<script defer ', $tag );
}, 10, 3 );

// WP-level max upload size override (cap PHP's value if our setting is smaller)
add_filter( 'upload_size_limit', function( $size ) {
	$mb = (int) get_option( 'th_upload_max_mb', 64 );
	if ( $mb <= 0 ) return $size;
	$ours = $mb * 1024 * 1024;
	return min( $size, $ours );
});

// Auto-resize large images on upload
add_filter( 'wp_handle_upload_prefilter', function( $file ) {
	$max = (int) get_option( 'th_upload_resize_max', 2560 );
	if ( $max <= 0 ) return $file;
	if ( strpos( $file['type'], 'image/' ) !== 0 ) return $file;

	// Defer to wp_handle_upload — WP will resize via big_image_size_threshold
	add_filter( 'big_image_size_threshold', function() use ( $max ) { return $max; }, 99 );
	return $file;
});

// Strip EXIF on upload
add_filter( 'wp_handle_upload', function( $upload ) {
	if ( ! get_option( 'th_upload_strip_exif', true ) ) return $upload;
	if ( ! function_exists( 'imagecreatefromjpeg' ) ) return $upload; // GD required
	if ( ! isset( $upload['type'] ) || strpos( $upload['type'], 'image/jpeg' ) !== 0 ) return $upload;
	if ( ! file_exists( $upload['file'] ) ) return $upload;

	$img = @imagecreatefromjpeg( $upload['file'] );
	if ( $img ) {
		imagejpeg( $img, $upload['file'], 90 );
		imagedestroy( $img );
	}
	return $upload;
});

// ═════════════════════════════════════════════════════════════════════════════
//  AJAX — Write .user.ini for upload size adjustments
// ═════════════════════════════════════════════════════════════════════════════
add_action( 'wp_ajax_therum_write_userini', function() {
	if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'forbidden', 403 );
	check_ajax_referer( 'therum_theme', 'nonce' );

	$mb = max( 1, min( 4096, (int) ( $_POST['mb'] ?? 256 ) ) );
	$path = ABSPATH . '.user.ini';

	// Build the file content
	$lines = [];
	$lines[] = '; Therum OS — auto-generated. Edit upload limits in /wp-admin → Settings → Uploads.';
	$lines[] = '; Last updated: ' . gmdate( 'Y-m-d H:i:s' ) . ' UTC';
	$lines[] = '';
	$lines[] = "upload_max_filesize = {$mb}M";
	$lines[] = "post_max_size = {$mb}M";
	$lines[] = "memory_limit = " . max( 256, $mb * 2 ) . "M";
	$lines[] = "max_execution_time = 600";
	$lines[] = "max_input_time = 600";
	$content = implode( "\n", $lines ) . "\n";

	// Try to write
	$wrote = @file_put_contents( $path, $content );
	if ( $wrote === false ) {
		wp_send_json_error( [ 'message' => 'Could not write to ' . $path . ' — check file permissions.' ] );
	}

	// Persist the target so the input shows the right value next load
	update_option( 'th_upload_target_mb', $mb );

	wp_send_json_success( [
		'message' => 'Wrote ' . basename( $path ) . ' with ' . $mb . 'M target. PHP will pick up changes within ~5 minutes (or instantly if you reload Local).',
		'path'    => $path,
		'mb'      => $mb,
	]);
});

// ── Auto-convert image uploads to WebP ────────────────────────────────────
// Gated by the `th_upload_auto_webp` option (set on the Settings → Uploads
// surface). Runs against new uploads only — the original .jpg/.png is
// replaced by a .webp sibling and the attachment is registered as image/webp.
//
// Kill switch: define( 'THERUM_DISABLE_WEBP', true ) in wp-config.php.
// Quality filter: apply_filters( 'therum/webp/quality', 82 ) — 0-100.
add_filter( 'wp_handle_upload', function( $file ) {
	if ( defined( 'THERUM_DISABLE_WEBP' ) && THERUM_DISABLE_WEBP ) return $file;
	if ( ! get_option( 'th_upload_auto_webp', false ) ) return $file;
	if ( empty( $file['file'] ) || empty( $file['type'] ) ) return $file;
	if ( ! in_array( $file['type'], [ 'image/jpeg', 'image/png' ], true ) ) return $file;
	if ( ! file_exists( $file['file'] ) ) return $file;

	// Skip if PHP can't do WebP (no GD WebP support and no Imagick).
	$has_imagick = class_exists( 'Imagick' ) && in_array( 'WEBP', (array) Imagick::queryFormats(), true );
	$has_gd      = function_exists( 'imagewebp' ) && function_exists( 'imagecreatefromstring' );
	if ( ! $has_imagick && ! $has_gd ) return $file;

	$quality = (int) apply_filters( 'therum/webp/quality', 82 );
	$quality = max( 1, min( 100, $quality ) );

	$src_path = $file['file'];
	$dst_path = preg_replace( '/\.(jpe?g|png)$/i', '.webp', $src_path );
	if ( $dst_path === $src_path ) return $file; // no extension match
	if ( file_exists( $dst_path ) ) {
		// Don't clobber an existing webp with the same base name.
		$pi = pathinfo( $dst_path );
		$dst_path = $pi['dirname'] . '/' . $pi['filename'] . '-' . substr( md5( (string) microtime( true ) ), 0, 6 ) . '.webp';
	}

	$converted = false;
	if ( $has_imagick ) {
		try {
			$img = new Imagick( $src_path );
			$img->setImageFormat( 'webp' );
			$img->setImageCompressionQuality( $quality );
			$img->setOption( 'webp:method', '6' );
			$converted = (bool) $img->writeImage( $dst_path );
			$img->clear();
			$img->destroy();
		} catch ( \Throwable $e ) {
			$converted = false;
		}
	}
	if ( ! $converted && $has_gd ) {
		$raw = @file_get_contents( $src_path );
		if ( $raw !== false ) {
			$img = @imagecreatefromstring( $raw );
			if ( $img ) {
				// Preserve PNG transparency
				if ( $file['type'] === 'image/png' ) {
					imagepalettetotruecolor( $img );
					imagealphablending( $img, true );
					imagesavealpha( $img, true );
				}
				$converted = @imagewebp( $img, $dst_path, $quality );
				imagedestroy( $img );
			}
		}
	}

	if ( ! $converted || ! file_exists( $dst_path ) ) {
		// Conversion failed — fall through with the original file unchanged.
		return $file;
	}

	// Production guardrail: if the WebP is LARGER than the original (common
	// when re-encoding already-optimized PNGs), keep the original. WebP is
	// supposed to make files smaller; if it doesn't here, the trade isn't
	// worth the format change.
	$src_size = @filesize( $src_path );
	$dst_size = @filesize( $dst_path );
	if ( $src_size && $dst_size && $dst_size >= $src_size * 0.95 ) {
		@unlink( $dst_path );
		return $file;
	}

	// Replace the original — only delete after the webp lands on disk OK
	// and is verifiably smaller. WP's wp_generate_attachment_metadata runs
	// after wp_handle_upload and will derive intermediate sizes from the
	// new WebP source using the same GD/Imagick path that converted it.
	@unlink( $src_path );

	$file['file'] = $dst_path;
	$file['type'] = 'image/webp';
	if ( ! empty( $file['url'] ) ) {
		$file['url'] = preg_replace( '/\.(jpe?g|png)(\?|#|$)/i', '.webp$2', $file['url'] );
		// Also handle the dedupe suffix case
		if ( substr( $file['url'], -5 ) !== '.webp' ) {
			$pi = pathinfo( $dst_path );
			$file['url'] = trailingslashit( dirname( $file['url'] ) ) . $pi['basename'];
		}
	}
	return $file;
}, 20 );

// ── WP_MEMORY_LIMIT inline editor ──────────────────────────────────────────
// Patches wp-config.php to set (or replace) the WP_MEMORY_LIMIT constant.
// Accepts presets only — caller picks from a hard-coded select so we never
// inject arbitrary user input into a php file.
add_action( 'wp_ajax_therum_save_wp_memory_limit', function() {
	if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'forbidden', 403 );
	check_ajax_referer( 'therum_mem_save', 'nonce' );

	$allowed = [ '128M', '256M', '512M', '768M', '1024M', '2048M', '4096M' ];
	$value   = (string) ( $_POST['value'] ?? '' );
	if ( ! in_array( $value, $allowed, true ) ) {
		wp_send_json_error( [ 'message' => 'Invalid value.' ] );
	}

	$path = ABSPATH . 'wp-config.php';
	if ( ! is_readable( $path ) ) wp_send_json_error( [ 'message' => 'wp-config.php not readable.' ] );
	if ( ! is_writable( $path ) ) wp_send_json_error( [ 'message' => 'wp-config.php not writable. chmod 644 first.' ] );

	$src = file_get_contents( $path );
	if ( $src === false ) wp_send_json_error( [ 'message' => 'Read failed.' ] );

	$new_line = "define( 'WP_MEMORY_LIMIT', '" . $value . "' );";

	if ( preg_match( "/define\(\s*['\"]WP_MEMORY_LIMIT['\"]\s*,\s*['\"][^'\"]+['\"]\s*\)\s*;/", $src ) ) {
		// Replace existing define
		$out = preg_replace(
			"/define\(\s*['\"]WP_MEMORY_LIMIT['\"]\s*,\s*['\"][^'\"]+['\"]\s*\)\s*;/",
			$new_line,
			$src,
			1
		);
	} else {
		// Insert above the "That's all, stop editing!" marker. Fall back to
		// before the require_once that loads wp-settings.php.
		if ( strpos( $src, "/* That's all, stop editing!" ) !== false ) {
			$out = str_replace( "/* That's all, stop editing!", $new_line . "\n\n/* That's all, stop editing!", $src );
		} elseif ( strpos( $src, "require_once ABSPATH . 'wp-settings.php'" ) !== false ) {
			$out = str_replace( "require_once ABSPATH . 'wp-settings.php'", $new_line . "\n\nrequire_once ABSPATH . 'wp-settings.php'", $src );
		} else {
			$out = rtrim( $src ) . "\n\n" . $new_line . "\n";
		}
	}

	// Safety: never write zero-length or HTML
	if ( ! is_string( $out ) || strlen( $out ) < 200 || strpos( $out, '<' ) === 0 ) {
		wp_send_json_error( [ 'message' => 'Patch produced unsafe output — aborted.' ] );
	}

	// Snapshot the existing file before writing so a bad patch is rescuable.
	// One slot only — overwritten on each save. Restore manually with
	//   mv wp-config.php.therum.bak wp-config.php
	$bak = $path . '.therum.bak';
	@copy( $path, $bak );

	// Write atomically via a temp file in the same dir
	$tmp = $path . '.therum.tmp';
	if ( @file_put_contents( $tmp, $out ) === false ) wp_send_json_error( [ 'message' => 'Write failed.' ] );
	if ( ! @rename( $tmp, $path ) ) {
		@unlink( $tmp );
		wp_send_json_error( [ 'message' => 'Atomic rename failed.' ] );
	}

	wp_send_json_success( [ 'message' => 'Saved.', 'value' => $value ] );
});

// ── Allowed MIME types AJAX ───────────────────────────────────────────────────
add_action( 'wp_ajax_therum_save_mime_types', function() {
	if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'forbidden', 403 );
	check_ajax_referer( 'therum_mime_types', 'nonce' );

	$raw     = wp_unslash( $_POST['types'] ?? '[]' );
	$decoded = json_decode( $raw, true );
	if ( ! is_array( $decoded ) ) wp_send_json_error( 'invalid data' );

	$allowed_exts = [
		'jpg|jpeg|jpe', 'png', 'gif', 'webp', 'avif', 'svg|svgz', 'heic|heif', 'ico', 'bmp',
		'mp4|m4v', 'mov|qt', 'avi', 'webm', 'mkv', 'ogv|ogg', 'wmv', 'flv',
		'mp3|m4a', 'wav', 'ogg|oga', 'flac', 'aac',
		'pdf', 'txt|asc', 'csv', 'doc|docx', 'xls|xlsx', 'ppt|pptx', 'odt', 'rtf',
		'zip', 'gz|gzip', 'tar', '7z', 'rar',
		'json', 'xml', 'sql', 'woff|woff2', 'ttf|otf',
	];

	$sanitized = array_values( array_filter( $decoded, fn( $e ) => in_array( $e, $allowed_exts, true ) ) );
	update_option( 'th_allowed_mime_types', wp_json_encode( $sanitized ) );
	wp_send_json_success( [ 'count' => count( $sanitized ) ] );
});

// ── upload_mimes filter ───────────────────────────────────────────────────────
add_filter( 'upload_mimes', function( $mimes ) {
	$saved = get_option( 'th_allowed_mime_types', '' );
	if ( ! $saved ) return $mimes;

	$enabled = json_decode( $saved, true );
	if ( ! is_array( $enabled ) || empty( $enabled ) ) return $mimes;

	$ext_to_mime = [
		'jpg|jpeg|jpe' => 'image/jpeg',
		'png'          => 'image/png',
		'gif'          => 'image/gif',
		'webp'         => 'image/webp',
		'avif'         => 'image/avif',
		'svg|svgz'     => 'image/svg+xml',
		'heic|heif'    => 'image/heic',
		'ico'          => 'image/x-icon',
		'bmp'          => 'image/bmp',
		'mp4|m4v'      => 'video/mp4',
		'mov|qt'       => 'video/quicktime',
		'avi'          => 'video/avi',
		'webm'         => 'video/webm',
		'mkv'          => 'video/x-matroska',
		'ogv|ogg'      => 'video/ogg',
		'wmv'          => 'video/x-ms-wmv',
		'flv'          => 'video/x-flv',
		'mp3|m4a'      => 'audio/mpeg',
		'wav'          => 'audio/wav',
		'ogg|oga'      => 'audio/ogg',
		'flac'         => 'audio/flac',
		'aac'          => 'audio/aac',
		'pdf'          => 'application/pdf',
		'txt|asc'      => 'text/plain',
		'csv'          => 'text/csv',
		'doc|docx'     => 'application/msword',
		'xls|xlsx'     => 'application/vnd.ms-excel',
		'ppt|pptx'     => 'application/vnd.ms-powerpoint',
		'odt'          => 'application/vnd.oasis.opendocument.text',
		'rtf'          => 'text/rtf',
		'zip'          => 'application/zip',
		'gz|gzip'      => 'application/gzip',
		'tar'          => 'application/x-tar',
		'7z'           => 'application/x-7z-compressed',
		'rar'          => 'application/x-rar-compressed',
		'json'         => 'application/json',
		'xml'          => 'application/xml',
		'sql'          => 'application/sql',
		'woff|woff2'   => 'font/woff',
		'ttf|otf'      => 'font/ttf',
	];

	// Start from WP defaults, then add enabled extras
	foreach ( $ext_to_mime as $ext => $mime ) {
		if ( in_array( $ext, $enabled, true ) ) {
			$mimes[ $ext ] = $mime;
		} else {
			// Only remove non-defaults that were explicitly disabled
			// (WP defaults like jpg/png are always kept unless WP itself removes them)
		}
	}

	return $mimes;
}, 20 );




// ════════════════════════════════════════════════════════════════════════════
//  4. INSTALL WIZARD — extracted to its own mu-plugin: therum-wizard.php
//  Same surface, same hooks, separately loadable. Kept the header here so
//  the section-map at the top of this file stays accurate.
// ════════════════════════════════════════════════════════════════════════════
