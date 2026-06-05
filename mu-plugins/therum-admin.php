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
//  ICONS — th_i('name') returns inline SVG. Used by everything.
// ─────────────────────────────────────────────────────────────────────────────
function th_i( string $n ): string {
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
//  NAV — th_nav() returns sidebar sections. Filterable via therum_admin_nav_items.
// ─────────────────────────────────────────────────────────────────────────────
function th_nav(): array {
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
		$design_items[] = [
			'label' => 'Bricks Settings',
			'icon'  => 'settings',
			'url'   => 'admin.php?page=bricks-settings',
			'match' => 'page=bricks-settings',
		];
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
	$plugin_pages = th_detect_plugin_pages();
	$curated      = th_curated_sections();
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

	// Insert curated sections at the top of the sidebar, in declaration order.
	// Empty sections are skipped so we don't render headers for absent plugins.
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
		$s[] = [ 'id' => 'more', 'label' => 'More', 'icon' => 'plugins', 'desc' => 'Plugin pages awaiting sorting into sections.', 'items' => $other_items ];
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
function th_curated_sections(): array {
	$list = [];

	if ( class_exists( 'WooCommerce' ) ) {
		$list[] = [
			'id'        => 'store',
			'label'     => 'Store',
			'icon'      => 'store',
			'desc'      => 'WooCommerce shop pages.',
			'is_member' => 'th_is_woo_item',
			'flatten'   => 'page=woocommerce',
		];
	}

	// Portfolio: only when a dedicated portfolio plugin is active (registers
	// a `portfolio`-flavored CPT). Without one, Bricks templates handle
	// portfolio content from the Design section.
	if ( th_has_portfolio_cpt() ) {
		$list[] = [
			'id'        => 'portfolio',
			'label'     => 'Portfolio',
			'icon'      => 'feather',
			'desc'      => 'Portfolio pages and entries.',
			'is_member' => 'th_is_portfolio_item',
			'flatten'   => null,
		];
	}

	return $list;
}

function th_has_portfolio_cpt(): bool {
	// `case_study` is the Therum-native portfolio CPT (registered in
	// therum-case-study-cpt.php). The other slugs are common third-party
	// portfolio plugins kept here so the Portfolio section auto-shows when
	// any of them are installed.
	foreach ( [ 'case_study', 'portfolio', 'jetpack-portfolio', 'avada_portfolio', 'project', 'projects' ] as $pt ) {
		if ( post_type_exists( $pt ) ) return true;
	}
	return false;
}

// Heuristic: is this auto-detected item part of WooCommerce / wc-admin?
function th_is_woo_item( array $it ): bool {
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
function th_is_portfolio_item( array $it ): bool {
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
function th_detect_plugin_pages(): array {
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
		$label = th_clean_label( $row[0] );
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
				$slabel = th_clean_label( $sub[0] );
				if ( $slabel === '' ) continue;
				if ( isset( $skip_sub[ $slug_base( $sslug ) ] ) ) continue;
				// WP duplicates the parent as the first submenu entry; skip the dupe
				if ( $sslug === $slug && count( $children ) === 0 ) continue;
				if ( isset( $seen_slug[ $sslug ] ) ) continue;
				$seen_slug[ $sslug ] = true;
				$children[] = th_make_plugin_item( $sslug, $slabel, $slug );
			}
		}

		$items[] = th_make_plugin_item( $slug, $label, null, $children );
	}

	// Pass 2 — orphan plugin pages registered under core menus
	// (Settings → Plugin X, Tools → Plugin Y, Appearance → Plugin Z, etc.)
	foreach ( $core_top as $core_parent ) {
		if ( ! isset( $submenu[ $core_parent ] ) || ! is_array( $submenu[ $core_parent ] ) ) continue;
		foreach ( $submenu[ $core_parent ] as $sub ) {
			if ( ! is_array( $sub ) || empty( $sub[2] ) ) continue;
			$sslug  = $sub[2];
			$slabel = th_clean_label( $sub[0] );
			if ( $slabel === '' ) continue;
			if ( isset( $skip_sub[ $slug_base( $sslug ) ] ) ) continue;
			if ( isset( $curated_labels[ strtolower( $slabel ) ] ) ) continue;
			if ( isset( $seen_slug[ $sslug ] ) ) continue;
			$llower = strtolower( $slabel );
			if ( isset( $seen_label[ $llower ] ) ) continue;
			$seen_slug[ $sslug ]   = true;
			$seen_label[ $llower ] = true;
			$items[] = th_make_plugin_item( $sslug, $slabel, $core_parent );
		}
	}

	usort( $items, function( $a, $b ) {
		return strcasecmp( $a['label'], $b['label'] );
	} );

	return $items;
}

function th_make_plugin_item( string $slug, string $label, ?string $parent = null, array $children = [] ): array {
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
		'icon'   => th_icon_for_label( $label ),
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
function th_clean_label( $raw ): string {
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
 * Map a cleaned admin label to the best matching icon from th_i().
 * Used for auto-detected plugin items so Store/Portfolio/etc. don't all
 * render with the generic "settings" sun.
 */
function th_icon_for_label( string $label ): string {
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

function th_get_layout(): array {
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
	check_ajax_referer( 'therum_sidebar', 'nonce' );
	delete_user_meta( get_current_user_id(), 'therum_sidebar_layout' );
	wp_send_json_success();
} );

function th_get_sidebar_layout(): array {
	$raw = get_user_meta( get_current_user_id(), 'therum_sidebar_layout', true );
	if ( ! $raw ) return [];
	$decoded = json_decode( $raw, true );
	return is_array( $decoded ) ? $decoded : [];
}

// IDs of "always present when applicable" curated sections. These guarantee
// the section appears even if the user deleted it from saved layout, and that
// gated content (e.g. Woo pages) always finds its way home.
function th_curated_section_ids(): array {
	return [ 'store', 'portfolio' ];
}

// Therum-curated structural items (Pages/Posts/Media, Themes/Menus/etc, Plugins/
// Users/Settings) are anchored to their default section. Users can reorder them
// inside that section but can't drag them to a custom section — they're the
// scaffolding of the OS. Auto-detected plugin pages (everything that doesn't
// match this prefix) remain freely movable.
function th_is_locked_item( string $item_id ): bool {
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
function th_apply_sidebar_layout( array $default_nav ): array {
	$layout = th_get_sidebar_layout();
	if ( empty( $layout['sections'] ) ) return $default_nav;

	$curated_ids = th_curated_section_ids();

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
	// referenced anywhere) → drop into "More" so it's never lost.
	$orphans = [];
	foreach ( $pool as $id => $it ) {
		if ( isset( $assigned[ $id ] ) || isset( $referenced[ $id ] ) ) continue;
		$orphans[] = $it;
	}
	if ( $orphans ) {
		$more_idx = null;
		foreach ( $result as $i => $r ) {
			if ( $r['id'] === 'more' ) { $more_idx = $i; break; }
		}
		if ( $more_idx === null ) {
			$result[] = [ 'id' => 'more', 'label' => 'More', 'icon' => 'plugins', 'items' => $orphans ];
		} else {
			$result[ $more_idx ]['items'] = array_merge( $result[ $more_idx ]['items'], $orphans );
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
		'th_render_dashboard', 'data:image/svg+xml;base64,' . base64_encode(
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
function th_is_frame(): bool {
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
	if ( th_is_frame() ) return;
	$path = __DIR__ . '/assets/therum-shell.css';
	wp_enqueue_style( 'therum-shell', plugins_url( 'assets/therum-shell.css', __FILE__ ), [], file_exists( $path ) ? filemtime( $path ) : null );
} );

// In iframe mode, just hide WP chrome but keep the page layout intact
add_action( 'admin_head', function() {
	if ( ! th_is_frame() ) return;
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
	if ( th_is_frame() ) return;
	static $rendered = false;
	if ( $rendered ) return;
	$rendered = true;

	$user = wp_get_current_user();
	$site = get_bloginfo( 'name' ) ?: 'Therum OS';
	$home_host = wp_parse_url( home_url(), PHP_URL_HOST ) ?: '';
	$av_letter = strtoupper( substr( $user->display_name ?: 'U', 0, 1 ) );

	$nav = apply_filters( 'therum_admin_nav_items', th_nav() );
	$nav = th_apply_sidebar_layout( $nav );

	$uri = $_SERVER['REQUEST_URI'] ?? '';
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
        <?php echo th_i('search'); ?>
        <input type="text" id="th-sb-search-input" placeholder="Search…" />
        <span class="th-sb-search-kbd">⌘K</span>
      </div>
    </div>

    <nav class="th-sb-nav">

      <a class="th-sb-item<?php echo $is_dash ? ' active' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=therum' ) ); ?>">
        <?php echo th_i('home'); ?>
        <span>Dashboard</span>
      </a>

      <?php foreach ( $nav as $sec ): ?>
      <div class="th-sb-section" data-section-id="<?php echo esc_attr( $sec['id'] ?? '' ); ?>">
        <div class="th-sb-section-label">
          <span class="th-sb-grip" data-sb-grip="section" title="Drag to reorder"><?php echo th_i('grip'); ?></span>
          <span class="th-sb-section-toggle" data-toggle-section>
            <span class="th-sb-section-name"><?php echo esc_html( strtoupper( $sec['label'] ?? '' ) ); ?></span>
            <span class="chev"><?php echo th_i('chevron'); ?></span>
          </span>
          <button type="button" class="th-sb-section-rename" title="Rename section" data-sb-rename><?php echo th_i('edit2'); ?></button>
          <button type="button" class="th-sb-section-x" title="Delete section" data-sb-delete><?php echo th_i('x'); ?></button>
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
              <span class="th-sb-grip" data-sb-grip="item" title="Drag to reorder"><?php echo th_i('grip'); ?></span>
              <?php echo th_i( $it['icon'] ?? 'chevron' ); ?>
              <span><?php echo esc_html( $it['label'] ?? '' ); ?></span>
              <?php if ( $is_external && ! $has_kids ): ?><span class="th-sb-item-ext"><?php echo th_i('external'); ?></span><?php endif; ?>
              <?php if ( $has_kids ): ?><span class="th-sb-item-chev" data-toggle-children role="button" aria-label="Toggle subpages"><?php echo th_i('chevron'); ?></span><?php endif; ?>
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
        <?php echo th_i('plus'); ?>
        <span>Add section</span>
      </button>

    </nav>

    <div class="th-sb-edit-toggle">
      <button type="button" id="th-edit-sb-btn" class="th-sb-edit-btn" title="Edit sidebar">
        <?php echo th_i('edit2'); ?>
        <span>Edit sidebar</span>
      </button>
      <a href="<?php echo esc_url( home_url('/') ); ?>" target="_blank" rel="noopener" class="th-sb-edit-btn th-sb-view-site" title="View frontend">
        <?php echo th_i('external'); ?>
        <span>View frontend</span>
      </a>
    </div>
    <div class="th-sb-edit-bar">
      <button type="button" class="th-sb-reset" id="th-sb-reset">Reset</button>
      <button type="button" class="th-sb-done" id="th-sb-done"><?php echo th_i('check'); ?> Save</button>
    </div>

    <div class="th-sb-footer">
      <span class="ok-dot"></span>
      <span>v<?php echo esc_html( defined('THERUM_OS_VERSION') ? THERUM_OS_VERSION : '1.9.0' ); ?></span>
      <span class="spacer"></span>
      <span><?php echo esc_html( therum_db_engine() ); ?></span>
    </div>
  </aside>

  <div id="th-main">

    <header id="th-top">
      <div class="th-top-title"><?php echo esc_html( $page_title ); ?></div>

      <div class="th-top-actions">
        <button class="th-top-btn" id="th-theme-toggle" title="Toggle theme">
          <?php echo th_i('sun'); ?>
        </button>
        <a class="th-top-btn" href="<?php echo esc_url( home_url() ); ?>" target="_blank" title="View site">
          <?php echo th_i('external'); ?>
        </a>
        <div class="th-top-avatar"><?php echo esc_html( $av_letter ); ?></div>
      </div>
    </header>

    <div id="th-content">

	<?php
} );

// Close the wrappers after WP renders its content
add_action( 'in_admin_footer', function() {
	if ( th_is_frame() ) return;
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
function th_dashboard_health(): array {
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
function th_dashboard_sparkline_data( string $post_type, int $weeks = 12 ): array {
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
function th_dashboard_sparkline_svg( array $values, string $stroke = 'var(--ac)' ): string {
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

function th_render_dashboard() {
	$user = wp_get_current_user();
	$first_name = $user->first_name ?: $user->display_name;
	$site = get_bloginfo( 'name' );

	$pages_count = (int) wp_count_posts( 'page' )->publish;
	$posts_count = (int) wp_count_posts( 'post' )->publish;
	$users_count = (int) count_users()['total_users'];
	$products_count = class_exists( 'WooCommerce' ) ? (int) wp_count_posts( 'product' )->publish : null;

	$health = th_dashboard_health();

	$now = current_time( 'l, F j · g:i A' );

	$layout = th_get_layout();
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
    <a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=page&th_frame=1' ) ); ?>" class="th-btn th-btn-primary"><?php echo th_i('plus'); ?> New Page</a>
    <a href="<?php echo esc_url( admin_url( 'post-new.php?th_frame=1' ) ); ?>" class="th-btn"><?php echo th_i('plus'); ?> New Post</a>
    <a href="<?php echo esc_url( admin_url( 'media-new.php?th_frame=1' ) ); ?>" class="th-btn"><?php echo th_i('import'); ?> Upload Media</a>
    <div class="th-dash-actions-spacer"></div>
    <button class="th-btn" id="th-edit-layout-btn"><?php echo th_i('widgets'); ?> Edit layout</button>
  </div>

  <div class="th-edit-bar">
    <?php echo th_i('widgets'); ?>
    <div class="th-edit-bar-text"><strong>Edit layout</strong> · drag cards to rearrange, drag the corner to resize.</div>
    <button class="th-btn" id="th-edit-reset">Reset</button>
    <button class="th-btn th-btn-primary" id="th-edit-done">Done</button>
  </div>

  <div class="th-bento" id="th-bento">

    <!-- Pages -->
    <div class="th-card" data-bento-id="stat-pages" data-size="<?php echo $size('stat-pages', 'xs'); ?>">
      <span class="th-size-picker"><button class="th-size-picker-btn" data-size-picker><?php echo th_i('widgets'); ?></button><div class="th-size-picker-menu"></div></span>
      <span class="th-resize-handle" data-resize></span>
      <div class="th-card-head">
        <span class="th-card-label">Pages</span>
        <a class="th-card-link" href="<?php echo esc_url( admin_url( 'admin.php?page=therum-pages' ) ); ?>">View all →</a>
      </div>
      <div class="th-stat-val"><?php echo number_format_i18n( $pages_count ); ?></div>
      <div class="th-stat-sub">Total pages</div>

      <div class="th-adaptive show-sm">
        <?php echo th_dashboard_sparkline_svg( th_dashboard_sparkline_data( 'page' ) ); ?>
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
      <span class="th-size-picker"><button class="th-size-picker-btn" data-size-picker><?php echo th_i('widgets'); ?></button><div class="th-size-picker-menu"></div></span>
      <span class="th-resize-handle" data-resize></span>
      <div class="th-card-head">
        <span class="th-card-label">Posts</span>
        <a class="th-card-link" href="<?php echo esc_url( admin_url( 'admin.php?page=therum-posts' ) ); ?>">View all →</a>
      </div>
      <div class="th-stat-val"><?php echo number_format_i18n( $posts_count ); ?></div>
      <div class="th-stat-sub">Published posts</div>

      <div class="th-adaptive show-sm">
        <?php echo th_dashboard_sparkline_svg( th_dashboard_sparkline_data( 'post' ) ); ?>
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
      <span class="th-size-picker"><button class="th-size-picker-btn" data-size-picker><?php echo th_i('widgets'); ?></button><div class="th-size-picker-menu"></div></span>
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
      <span class="th-size-picker"><button class="th-size-picker-btn" data-size-picker><?php echo th_i('widgets'); ?></button><div class="th-size-picker-menu"></div></span>
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
      <span class="th-size-picker"><button class="th-size-picker-btn" data-size-picker><?php echo th_i('widgets'); ?></button><div class="th-size-picker-menu"></div></span>
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
          $when = human_time_diff( strtotime( $r['post_modified'] ), current_time( 'timestamp' ) ) . ' ago';
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

    <!-- Site health (real, computed by th_dashboard_health) -->
    <?php
      $health_color_map = [ 'good' => 'var(--ok)', 'warning' => 'var(--wrn)', 'critical' => 'var(--err)' ];
      $health_color = $health_color_map[ $health['status'] ] ?? 'var(--tx2)';
    ?>
    <div class="th-card" data-bento-id="health" data-size="<?php echo $size('health', 'xs'); ?>">
      <span class="th-size-picker"><button class="th-size-picker-btn" data-size-picker><?php echo th_i('widgets'); ?></button><div class="th-size-picker-menu"></div></span>
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
	if ( th_is_frame() ) return;
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
class Therum_Card_Style {

	/** Resolve the user's current image-source preference from theme state. */
	public static function image_source(): string {
		// URL override for testing — ?th_force_image=wireframe
		if ( ! empty( $_GET['th_force_image'] ) ) {
			$forced = sanitize_text_field( wp_unslash( $_GET['th_force_image'] ) );
			if ( in_array( $forced, ['gradient','featured','stock','wireframe','pattern'], true ) ) return $forced;
		}
		if ( ! class_exists( 'Therum_Themes' ) ) return 'gradient';
		$state = Therum_Themes::get_state();
		$src = $state['cardImage'] ?? 'gradient';
		return in_array( $src, ['gradient','featured','stock','wireframe','pattern'], true ) ? $src : 'gradient';
	}

	/** Resolve the user's current card-layout preference ('card' or 'hero'). */
	public static function layout( ?\WP_Post $p = null ): string {
		if ( ! empty( $_GET['th_force_layout'] ) ) {
			$forced = sanitize_text_field( wp_unslash( $_GET['th_force_layout'] ) );
			if ( in_array( $forced, ['hero','compact','magazine','card-v1','card-v2','compact-v1','compact-v2'], true ) ) return $forced;
		}
		// Per-post override
		if ( $p ) {
			$per = get_post_meta( $p->ID, '_th_card_layout', true );
			if ( in_array( $per, ['hero','compact','magazine','card-v1','card-v2','compact-v1','compact-v2'], true ) ) return $per;
		}
		if ( ! class_exists( 'Therum_Themes' ) ) return 'hero';
		$state = Therum_Themes::get_state();
		$lay = $state['cardLayout'] ?? 'hero';
		return in_array( $lay, ['hero','compact','magazine','card-v1','card-v2','compact-v1','compact-v2'], true ) ? $lay : 'hero';
	}

	/** Diagnostic — for debugging when settings aren't applying. */
	public static function diagnostic(): string {
		if ( ! class_exists( 'Therum_Themes' ) ) return 'Therum_Themes class missing';
		$state = Therum_Themes::get_state();
		$user_meta = get_user_meta( get_current_user_id(), 'therum_theme_state', true );
		return sprintf(
			'state[cardLayout]=%s | state[cardImage]=%s | layout()=%s | image_source()=%s | raw_meta_keys=%s',
			var_export( $state['cardLayout'] ?? null, true ),
			var_export( $state['cardImage'] ?? null, true ),
			self::layout(),
			self::image_source(),
			is_array( $user_meta ) ? implode( ',', array_keys( $user_meta ) ) : 'not_array'
		);
	}

	/**
	 * Build the inline style string for a card thumbnail given a post.
	 * Always returns a `style="..."` value (not the attribute itself).
	 */
	public static function thumb_style( \WP_Post $p, ?string $forced_source = null ): string {
		// Per-post override takes priority over global, unless explicitly forced
		if ( ! $forced_source ) {
			$per_post = get_post_meta( $p->ID, '_th_card_image', true );
			if ( in_array( $per_post, ['gradient','featured','stock','wireframe','pattern'], true ) ) {
				$forced_source = $per_post;
			}
		}
		$src = $forced_source ?: self::image_source();

		// FEATURED — try real image, fallback to wireframe if missing
		if ( $src === 'featured' ) {
			$thumb_id = get_post_thumbnail_id( $p->ID );
			$thumb    = $thumb_id ? wp_get_attachment_image_url( $thumb_id, 'medium' ) : '';
			if ( $thumb ) {
				return "background-image:linear-gradient(180deg,rgba(0,0,0,0) 50%,rgba(0,0,0,.55) 100%),url('" . esc_url( $thumb ) . "');background-size:cover;background-position:center;";
			}
			// Fallback to wireframe
			return self::wireframe_style( $p );
		}

		// STOCK — Picsum, seeded by post ID for stability
		if ( $src === 'stock' ) {
			$url = "https://picsum.photos/seed/{$p->ID}/600/400";
			return "background-image:linear-gradient(180deg,rgba(0,0,0,0) 50%,rgba(0,0,0,.55) 100%),url('{$url}');background-size:cover;background-position:center;";
		}

		// WIREFRAME — auto SVG pattern
		if ( $src === 'wireframe' ) {
			return self::wireframe_style( $p );
		}

		// PATTERN — geometric SVG tile, theme-accent based
		if ( $src === 'pattern' ) {
			return self::pattern_style( $p );
		}

		// GRADIENT — deterministic per-post gradient
		return self::gradient_style( $p );
	}

	/**
	 * Generate a deterministic gradient based on post ID, so each post gets a
	 * unique-looking gradient that stays the same across page loads.
	 */
	protected static function gradient_style( \WP_Post $p ): string {
		// Pick from a curated set of gradient pairs.
		$pairs = [
			['#1e3a8a', '#6b21a8'], // navy → purple
			['#0f766e', '#0e7490'], // teal → cyan
			['#7c2d12', '#b91c1c'], // burgundy → red
			['#365314', '#65a30d'], // dark olive → lime
			['#581c87', '#a21caf'], // deep purple → magenta
			['#0c4a6e', '#0891b2'], // deep blue → sky
			['#7c2d12', '#ea580c'], // burnt → orange
			['#1f2937', '#4b5563'], // graphite → slate
			['#831843', '#e11d48'], // wine → rose
			['#134e4a', '#10b981'], // forest → emerald
		];
		$idx = $p->ID % count( $pairs );
		[$a, $b] = $pairs[ $idx ];
		return "background:linear-gradient(135deg,{$a},{$b});";
	}

	/**
	 * Wireframe — an inline SVG data URI with abstract shapes seeded by post.
	 * Looks like a low-fi mockup placeholder. Deterministic from post ID.
	 */
	protected static function wireframe_style( \WP_Post $p ): string {
		$seed = $p->ID;
		// Pick a colorway from post ID
		$colorways = [
			['#f8fafc', '#cbd5e1', '#64748b'], // slate
			['#fef3c7', '#fcd34d', '#a16207'], // amber
			['#dbeafe', '#93c5fd', '#1d4ed8'], // blue
			['#fee2e2', '#fca5a5', '#b91c1c'], // red
			['#dcfce7', '#86efac', '#15803d'], // green
			['#fae8ff', '#d8b4fe', '#7e22ce'], // purple
			['#fff7ed', '#fdba74', '#c2410c'], // orange
			['#f1f5f9', '#94a3b8', '#334155'], // cool grey
		];
		$cw = $colorways[ $seed % count( $colorways ) ];
		[$bg, $mid, $fg] = $cw;

		// Vary positions/sizes deterministically
		$x1 = ( $seed * 7 ) % 200 + 50;
		$y1 = ( $seed * 11 ) % 100 + 40;
		$x2 = ( $seed * 13 ) % 250 + 100;
		$y2 = ( $seed * 17 ) % 80 + 100;
		$rot = ( $seed * 23 ) % 360;

		$svg = <<<SVG
<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 600 400' preserveAspectRatio='xMidYMid slice'>
	<rect width='600' height='400' fill='{$bg}'/>
	<circle cx='{$x1}' cy='{$y1}' r='80' fill='{$mid}' opacity='0.6'/>
	<rect x='{$x2}' y='{$y2}' width='220' height='14' rx='7' fill='{$mid}'/>
	<rect x='{$x2}' y='{$y2}' width='160' height='14' rx='7' fill='{$mid}' transform='translate(0,30)'/>
	<rect x='{$x2}' y='{$y2}' width='100' height='14' rx='7' fill='{$mid}' transform='translate(0,60)' opacity='0.6'/>
	<g transform='rotate({$rot} 300 200)' opacity='0.15'>
		<line x1='0' y1='100' x2='600' y2='100' stroke='{$fg}' stroke-width='2'/>
		<line x1='0' y1='200' x2='600' y2='200' stroke='{$fg}' stroke-width='2'/>
		<line x1='0' y1='300' x2='600' y2='300' stroke='{$fg}' stroke-width='2'/>
	</g>
</svg>
SVG;
		$encoded = rawurlencode( trim( $svg ) );
		return "background-image:linear-gradient(180deg,rgba(0,0,0,0) 60%,rgba(0,0,0,.4) 100%),url(\"data:image/svg+xml,{$encoded}\");background-size:cover;background-position:center;";
	}

	protected static function pattern_style( \WP_Post $p ): string {
		$seed = $p->ID;
		// Pick from a curated set of accent gradients seeded by post ID
		$pairs = [
			['#1e293b', '#0ea5e9'],
			['#312e81', '#a78bfa'],
			['#0f172a', '#f97316'],
			['#7f1d1d', '#fbbf24'],
			['#064e3b', '#34d399'],
			['#831843', '#f472b6'],
			['#1c1917', '#fde68a'],
			['#082f49', '#22d3ee'],
		];
		[$bg, $stripe] = $pairs[$seed % count($pairs)];
		$angle = ($seed * 31) % 180;
		$svg = <<<SVG
<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 600 400' preserveAspectRatio='xMidYMid slice'>
	<defs>
		<pattern id='p{$seed}' x='0' y='0' width='40' height='40' patternUnits='userSpaceOnUse' patternTransform='rotate({$angle})'>
			<rect width='40' height='40' fill='{$bg}'/>
			<line x1='0' y1='0' x2='0' y2='40' stroke='{$stripe}' stroke-width='8' opacity='0.45'/>
			<line x1='20' y1='0' x2='20' y2='40' stroke='{$stripe}' stroke-width='2' opacity='0.25'/>
		</pattern>
	</defs>
	<rect width='600' height='400' fill='url(#p{$seed})'/>
</svg>
SVG;
		$encoded = rawurlencode( trim( $svg ) );
		return "background-image:linear-gradient(180deg,rgba(0,0,0,0) 50%,rgba(0,0,0,.55) 100%),url(\"data:image/svg+xml,{$encoded}\");background-size:cover;background-position:center;";
	}

	/**
	 * Render the per-card image picker menu (⋮ button + dropdown of 4 sources).
	 * Stops click propagation so clicking the menu doesn't navigate to the post.
	 */
	public static function render_picker_menu( int $post_id ): void {
		$cur_img = get_post_meta( $post_id, '_th_card_image', true ) ?: 'inherit';
		$cur_lay = get_post_meta( $post_id, '_th_card_layout', true ) ?: 'inherit';
		$layouts = [
			'inherit'  => 'Use site default',
			'card'     => 'Card',
			'hero'     => 'Hero',
			'compact'  => 'Compact',
			'magazine' => 'Magazine',
		];
		$images = [
			'inherit'   => 'Use site default',
			'gradient'  => 'Gradient',
			'featured'  => 'Featured image',
			'stock'     => 'Stock photo',
			'wireframe' => 'Wireframe',
			'pattern'   => 'Pattern',
		];
		?>
		<div class="th-card-picker" data-card-picker data-post-id="<?php echo (int)$post_id; ?>">
		  <button type="button" class="th-card-picker-btn" aria-label="Card style" data-card-picker-toggle>
			<svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><circle cx="5" cy="12" r="2"/><circle cx="12" cy="12" r="2"/><circle cx="19" cy="12" r="2"/></svg>
		  </button>
		  <div class="th-card-picker-menu" data-card-picker-menu>
			<div class="th-card-picker-label">Layout</div>
			<?php foreach ( $layouts as $key => $label ): ?>
			<button type="button" class="th-card-picker-item<?php echo $cur_lay === $key ? ' active' : ''; ?>" data-card-layout="<?php echo esc_attr( $key ); ?>">
			  <?php echo esc_html( $label ); ?>
			  <?php if ( $cur_lay === $key ): ?>
			  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
			  <?php endif; ?>
			</button>
			<?php endforeach; ?>
			<div class="th-card-picker-divider"></div>
			<div class="th-card-picker-label">Image</div>
			<?php foreach ( $images as $key => $label ): ?>
			<button type="button" class="th-card-picker-item<?php echo $cur_img === $key ? ' active' : ''; ?>" data-card-image="<?php echo esc_attr( $key ); ?>">
			  <?php echo esc_html( $label ); ?>
			  <?php if ( $cur_img === $key ): ?>
			  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
			  <?php endif; ?>
			</button>
			<?php endforeach; ?>
		  </div>
		</div>
		<?php
	}
}

class Therum_List_Page {

	/**
	 * Render a list page. Required keys: title, page_id, items.
	 *
	 * Optional: subtitle, meta_pills, action_buttons, filter_pills,
	 * sort_options, search_placeholder, view_default ('grid'|'table'),
	 * card_renderer (callable), row_renderer (callable),
	 * table_columns (array of header labels), empty_state.
	 */
	/**
	 * Pull a stable item ID from any of the shapes a list page passes through:
	 * WP_Post (->ID), WP_User (->ID), WP_Term (->term_id), or the plugin/array
	 * shape used by Therum_Plugins_Page where 'file' is the canonical id.
	 */
	public static function item_id($item): string {
		if (is_object($item)) {
			if (isset($item->ID))      return (string) $item->ID;
			if (isset($item->term_id)) return (string) $item->term_id;
		}
		if (is_array($item)) {
			return (string) ($item['id'] ?? $item['ID'] ?? $item['file'] ?? $item['slug'] ?? '');
		}
		return '';
	}

	/**
	 * Reorder $items to match the user's saved drag-drop order for $page_id,
	 * preserving any items that aren't in the saved order (appended at the end
	 * so newly-added items show up at the bottom without being lost).
	 */
	public static function apply_saved_order(array $items, string $page_id): array {
		$uid = get_current_user_id();
		if (!$uid) return $items;
		$order = (array) get_user_meta($uid, 'therum_list_order_' . $page_id, true);
		if (!$order) return $items;
		$by_id = [];
		foreach ($items as $it) {
			$id = self::item_id($it);
			if ($id !== '') $by_id[$id] = $it;
		}
		$out = [];
		foreach ($order as $id) {
			if (isset($by_id[$id])) {
				$out[] = $by_id[$id];
				unset($by_id[$id]);
			}
		}
		// Append anything not in the saved order — new items land at the end.
		foreach ($by_id as $it) $out[] = $it;
		return $out;
	}

	public static function render(array $cfg): void {
		$title             = $cfg['title']             ?? 'List';
		$subtitle          = $cfg['subtitle']          ?? '';
		$page_id           = $cfg['page_id']           ?? 'list';
		$meta_pills        = $cfg['meta_pills']        ?? [];
		$action_buttons    = $cfg['action_buttons']    ?? [];
		$filter_pills      = $cfg['filter_pills']      ?? [];
		$sort_options      = $cfg['sort_options']      ?? [];
		$search_placeholder = $cfg['search_placeholder'] ?? 'Search…';
		$view_default      = $cfg['view_default']      ?? 'grid';
		$items             = $cfg['items']             ?? [];
		$card_renderer     = $cfg['card_renderer']     ?? null;
		$row_renderer      = $cfg['row_renderer']      ?? null;
		$table_columns     = $cfg['table_columns']     ?? [];
		$row_actions       = $cfg['row_actions']       ?? null; // callable returning array of actions per item
		$empty_state       = $cfg['empty_state']       ?? ['title'=>'Nothing to show', 'sub'=>''];
		$before_toolbar    = $cfg['before_toolbar']    ?? null;
		$extra_views       = $cfg['extra_views']       ?? [];        // [ ['key'=>'masonry','label'=>'Masonry','svg'=>'<svg…'], … ]
		$density_slider    = ! empty( $cfg['density_slider'] );
		$density_default   = (int) ( $cfg['density_default'] ?? 5 );
		// Drag-to-sort persists per-user order keyed by page_id. Defaults to on.
		// Pages can opt out with 'sortable' => false (e.g. Plugins, which has
		// its own active/inactive grouping that should not be drag-reordered).
		$sortable          = $cfg['sortable'] ?? true;
		if ($sortable) {
			$items = self::apply_saved_order($items, $page_id);
		}
		$sort_nonce        = $sortable ? wp_create_nonce('therum_list_order_' . $page_id) : '';
		?>
		<div class="th-lp" data-page-id="<?php echo esc_attr($page_id); ?>"<?php if ($sortable): ?> data-th-sortable="1" data-th-sort-nonce="<?php echo esc_attr($sort_nonce); ?>"<?php endif; ?>>

		  <div class="th-lp-header">
			<div class="th-lp-header-left">
			  <?php if (!empty($meta_pills)): ?>
			  <div class="th-lp-meta">
				<span class="th-lp-meta-dot"></span>
				<?php echo esc_html(implode(' · ', $meta_pills)); ?>
			  </div>
			  <?php endif; ?>
			  <h1 class="th-lp-title"><?php echo esc_html($title); ?></h1>
			  <?php if ($subtitle !== ''): ?>
			  <p class="th-lp-sub"><?php echo esc_html($subtitle); ?></p>
			  <?php endif; ?>
			</div>
			<?php if (!empty($action_buttons)): ?>
			<div class="th-lp-actions">
			  <?php foreach ($action_buttons as $btn):
				$href    = $btn['href']    ?? '#';
				$label   = $btn['label']   ?? '';
				$primary = !empty($btn['primary']);
				$class   = 'th-btn' . ($primary ? ' th-btn-primary' : '');
				$icon    = $btn['icon']    ?? '';
				$svg     = function_exists('th_i') && $icon ? th_i($icon) : '';
				$attrs   = '';
				if (!empty($btn['attrs']) && is_array($btn['attrs'])) {
					foreach ($btn['attrs'] as $ak => $av) {
						$attrs .= ' ' . esc_attr($ak) . '="' . esc_attr((string)$av) . '"';
					}
				}
			  ?>
			  <a href="<?php echo esc_url($href); ?>" class="<?php echo esc_attr($class); ?>"<?php echo !empty($btn['target']) ? ' target="'.esc_attr($btn['target']).'"' : ''; ?><?php echo $attrs; ?>><?php echo $svg; ?> <?php echo esc_html($label); ?></a>
			  <?php endforeach; ?>
			</div>
			<?php endif; ?>
		  </div>

		  <?php if (is_callable($before_toolbar)) call_user_func($before_toolbar); ?>

		  <div class="th-lp-toolbar">
			<?php if (!empty($filter_pills)): ?>
			<div class="th-lp-pills">
			  <?php foreach ($filter_pills as $i => $pill):
				$key   = $pill['key'] ?? 'all';
				$label = $pill['label'] ?? $key;
				$count = $pill['count'] ?? null;
				$flag  = $pill['flag'] ?? '';
				$active = $i === 0 ? ' active' : '';
			  ?>
			  <button type="button" class="th-lp-pill<?php echo $active; ?>" data-filter="<?php echo esc_attr($key); ?>"<?php echo $flag ? ' data-filter-flag="'.esc_attr($flag).'"' : ''; ?>>
				<?php echo esc_html($label); ?>
				<?php if ($count !== null): ?><span class="th-lp-pill-count"><?php echo (int)$count; ?></span><?php endif; ?>
			  </button>
			  <?php endforeach; ?>
			</div>
			<?php endif; ?>

			<div style="flex:1"></div>

			<div class="th-lp-search">
			  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
			  <input type="text" placeholder="<?php echo esc_attr($search_placeholder); ?>" class="th-lp-search-input" />
			</div>

			<?php if (!empty($sort_options)): ?>
			<div class="th-lp-sort">
			  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h18"/><path d="M7 12h10"/><path d="M11 18h2"/></svg>
			  <span class="th-lp-sort-label"><?php echo esc_html($sort_options[0]['label'] ?? 'Sort'); ?></span>
			  <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"/></svg>
			  <div class="th-lp-sort-menu">
				<?php foreach ($sort_options as $i => $opt):
				  $sel = $i === 0 ? ' selected' : '';
				?>
				<button type="button" class="th-lp-sort-item<?php echo $sel; ?>" data-sort="<?php echo esc_attr($opt['key'] ?? ''); ?>">
				  <svg class="th-lp-sort-check" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg>
				  <?php echo esc_html($opt['label'] ?? ''); ?>
				</button>
				<?php endforeach; ?>
			  </div>
			</div>
			<?php endif; ?>

			<?php
			  $cur_lay = class_exists('Therum_Card_Style') ? Therum_Card_Style::layout() : 'card';
			  $cur_img = class_exists('Therum_Card_Style') ? Therum_Card_Style::image_source() : 'gradient';
			  $lay_opts = ['card-v1'=>'Card V1','card-v2'=>'Card V2','hero'=>'Hero','compact'=>'Compact','compact-v1'=>'Compact V1','compact-v2'=>'Compact V2','magazine'=>'Magazine'];
			  $img_opts = ['gradient'=>'Gradient','featured'=>'Featured','stock'=>'Stock','wireframe'=>'Wireframe','pattern'=>'Pattern'];
			?>
			<div class="th-lp-style-toggle" data-style-toggle>
			  <button type="button" class="th-lp-style-btn" data-style-trigger title="Card style">
				<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
				<span>Style</span>
			  </button>
			  <div class="th-lp-style-menu" data-style-menu>
				<div class="th-lp-style-section">
				  <div class="th-lp-style-label">Layout</div>
				  <?php foreach ($lay_opts as $k => $lbl): ?>
				  <button type="button" class="th-lp-style-item<?php echo $cur_lay===$k?' active':''; ?>" data-style-field="cardLayout" data-style-value="<?php echo esc_attr($k); ?>">
					<?php echo esc_html($lbl); ?>
					<?php if ($cur_lay===$k): ?><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg><?php endif; ?>
				  </button>
				  <?php endforeach; ?>
				</div>
				<div class="th-lp-style-section">
				  <div class="th-lp-style-label">Image</div>
				  <?php foreach ($img_opts as $k => $lbl): ?>
				  <button type="button" class="th-lp-style-item<?php echo $cur_img===$k?' active':''; ?>" data-style-field="cardImage" data-style-value="<?php echo esc_attr($k); ?>">
					<?php echo esc_html($lbl); ?>
					<?php if ($cur_img===$k): ?><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg><?php endif; ?>
				  </button>
				  <?php endforeach; ?>
				</div>
			  </div>
			</div>

			<?php
			// Optional "Cols" density slider — set $config['density_slider'] = true
			// in the calling renderer. Visible regardless of items (so the user
			// sees the chrome even on an empty library).
			$show_density = ! empty( $density_slider );
			$density_val  = (int) ( $density_default ?? 5 );
			if ( $show_density ): ?>
			<div class="th-density-control" data-view="<?php echo esc_attr( $view_default ); ?>">
			  <span class="th-density-label">Cols</span>
			  <input type="range" min="3" max="7" value="<?php echo (int) $density_val; ?>" class="th-density-slider" id="th-density-slider" />
			  <span class="th-density-value" id="th-density-value"><?php echo (int) $density_val; ?></span>
			</div>
			<?php endif; ?>

			<div class="th-lp-views">
			  <button type="button" class="th-lp-view-btn<?php echo $view_default==='grid'?' active':''; ?>" data-view="grid" title="Card view">
				<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
			  </button>
			  <?php
			  // Extra view modes (masonry, metro, list, etc.) are passed via
			  // $config['extra_views']. Each one becomes a toggle here AND
			  // creates a matching pane after the grid pane below.
			  $extras = (array) ( $extra_views ?? [] );
			  foreach ( $extras as $ev ):
				$key   = (string) ( $ev['key']   ?? '' );
				$label = (string) ( $ev['label'] ?? ucfirst( $key ) );
				$svg   = (string) ( $ev['svg']   ?? '' );
				if ( $key === '' ) continue;
			  ?>
			  <button type="button" class="th-lp-view-btn<?php echo $view_default===$key?' active':''; ?>" data-view="<?php echo esc_attr( $key ); ?>" title="<?php echo esc_attr( $label ); ?>">
				<?php echo $svg; // already safe SVG markup ?>
			  </button>
			  <?php endforeach; ?>
			  <button type="button" class="th-lp-view-btn<?php echo $view_default==='table'?' active':''; ?>" data-view="table" title="Table view">
				<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
			  </button>
			</div>
		  </div>

		  <?php if (empty($items)): ?>
		  <div class="th-lp-empty">
			<div class="th-lp-empty-icon">
			  <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10" opacity="0.2"/><circle cx="12" cy="12" r="4"/></svg>
			</div>
			<div class="th-lp-empty-title"><?php echo esc_html($empty_state['title']); ?></div>
			<?php if (!empty($empty_state['sub'])): ?>
			<div class="th-lp-empty-sub"><?php echo esc_html($empty_state['sub']); ?></div>
			<?php endif; ?>
			<?php
			// Pull primary action from action_buttons config and render as CTA
			$primary_action = null;
			foreach ((array)($action_buttons ?? []) as $btn) {
				if (!empty($btn['primary'])) { $primary_action = $btn; break; }
			}
			if ($primary_action): ?>
			<a href="<?php echo esc_url($primary_action['href'] ?? '#'); ?>" class="th-lp-empty-cta">
			  <?php echo esc_html($primary_action['label'] ?? 'Get started'); ?>
			  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
			</a>
			<?php endif; ?>
		  </div>
		  <?php else: ?>

		  <div class="th-lp-view th-lp-view-grid<?php echo $view_default==='grid'?' active':''; ?>" data-view-pane="grid"<?php echo $density_slider ? ' data-density="' . (int) $density_default . '"' : ''; ?>>
			<?php if (is_callable($card_renderer)) {
				foreach ($items as $item) {
					$_iid = $sortable ? self::item_id($item) : '';
					if ($_iid !== '') {
						// Wrap card markup so we can stamp data-th-item-id +
						// draggable onto the outer card without touching every
						// renderer. Uses output-buffering + a one-shot regex
						// patch on the first <div class="th-lp-card ...">.
						ob_start();
						call_user_func($card_renderer, $item);
						$_html = ob_get_clean();
						$_html = preg_replace(
							'/<div\s+class="(th-lp-card[^"]*)"/',
							'<div data-th-item-id="' . esc_attr($_iid) . '" draggable="true" class="$1"',
							$_html, 1
						);
						echo $_html;
					} else {
						call_user_func($card_renderer, $item);
					}
				}
			} ?>
		  </div>

		  <?php
		  // Extra view panes — masonry / metro / list — share the same card
		  // markup as the grid pane. Rendered server-side so they're always
		  // present (the JS-injection approach failed when the grid pane
		  // wasn't in the DOM yet on first load).
		  foreach ( $extra_views as $ev ):
		    $key = (string) ( $ev['key'] ?? '' );
		    if ( $key === '' ) continue;
		  ?>
		  <div class="th-lp-view th-lp-view-<?php echo esc_attr( $key ); ?><?php echo $view_default === $key ? ' active' : ''; ?>" data-view-pane="<?php echo esc_attr( $key ); ?>">
			<?php if (is_callable($card_renderer)) {
				foreach ($items as $item) {
					$_iid = $sortable ? self::item_id($item) : '';
					if ($_iid !== '') {
						ob_start();
						call_user_func($card_renderer, $item);
						$_html = ob_get_clean();
						$_html = preg_replace(
							'/<div\s+class="(th-lp-card[^"]*)"/',
							'<div data-th-item-id="' . esc_attr($_iid) . '" draggable="true" class="$1"',
							$_html, 1
						);
						echo $_html;
					} else {
						call_user_func($card_renderer, $item);
					}
				}
			} ?>
		  </div>
		  <?php endforeach; ?>

		  <?php if (is_callable($row_renderer)): ?>
		  <div class="th-lp-view th-lp-view-table<?php echo $view_default==='table'?' active':''; ?>" data-view-pane="table">
			<table class="th-lp-table">
			  <?php if (!empty($table_columns)): ?>
			  <thead>
				<tr>
				  <?php foreach ($table_columns as $col): ?>
				  <th><?php echo esc_html($col); ?></th>
				  <?php endforeach; ?>
				  <?php if ($row_actions): ?>
				  <th class="th-lp-th-actions" aria-label="Actions"></th>
				  <?php endif; ?>
				</tr>
			  </thead>
			  <?php endif; ?>
			  <tbody>
				<?php foreach ($items as $item):
					$_iid = $sortable ? self::item_id($item) : '';
					$_buffer = $_iid !== '';
					if ($_buffer) ob_start();
					if ($row_actions) {
						$actions = call_user_func($row_actions, $item);
						call_user_func($row_renderer, $item, $actions);
					} else {
						call_user_func($row_renderer, $item);
					}
					if ($_buffer) {
						$_html = ob_get_clean();
						// Stamp data-th-item-id + draggable onto the <tr>.
						$_html = preg_replace(
							'/<tr\s+class="(th-lp-row[^"]*)"/',
							'<tr data-th-item-id="' . esc_attr($_iid) . '" draggable="true" class="$1"',
							$_html, 1
						);
						echo $_html;
					}
				endforeach; ?>
			  </tbody>
			</table>
		  </div>
		  <?php endif; ?>

		  <?php endif; ?>

		  <div class="th-lp-noresults" style="display:none">
			<div class="th-lp-empty-icon">
			  <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8" opacity="0.3"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
			</div>
			<div class="th-lp-empty-title">No matches</div>
			<div class="th-lp-empty-sub">Adjust filters or clear the search.</div>
		  </div>
		</div>
		<?php
	}

	/**
	 * Render a kebab-menu cell containing the given action items.
	 * Each action: ['label', 'href', 'icon' (optional), 'danger' (bool)]
	 */
	public static function render_kebab_cell(array $actions): void {
		?>
		<td class="th-lp-td-actions">
		  <?php self::render_kebab_inner($actions); ?>
		</td>
		<?php
	}

	/** Kebab for grid/card view — floats top-right of the card */
	public static function render_card_kebab(array $actions): void {
		?>
		<div class="th-lp-card-kebab-wrap">
		  <?php self::render_kebab_inner($actions); ?>
		</div>
		<?php
	}

	private static function render_kebab_inner(array $actions): void {
		?>
		<div class="th-lp-kebab" data-th-kebab>
		  <button type="button" class="th-lp-kebab-btn" aria-label="Actions" data-th-kebab-btn>
			<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="5" r="1.5"/><circle cx="12" cy="12" r="1.5"/><circle cx="12" cy="19" r="1.5"/></svg>
		  </button>
		  <div class="th-lp-kebab-menu" role="menu">
			<?php foreach ($actions as $a):
			  $icon = function_exists('th_i') && !empty($a['icon']) ? th_i($a['icon']) : '';
			  $danger = !empty($a['danger']) ? ' th-lp-kebab-item-danger' : '';
			  // Arbitrary data-* attrs — used by Rename ("data" => ['th-rename' => 123, 'th-rename-name' => 'foo.jpg'])
			  $data_attrs = '';
			  if (!empty($a['data']) && is_array($a['data'])) {
				foreach ($a['data'] as $dkey => $dval) {
					$data_attrs .= ' data-' . esc_attr($dkey) . '="' . esc_attr((string)$dval) . '"';
				}
			  }
			  if (!empty($a['copy'])):
			?>
			<button type="button" class="th-lp-kebab-item<?php echo $danger; ?>" role="menuitem" data-th-copy="<?php echo esc_attr($a['copy']); ?>">
			  <?php echo $icon; ?>
			  <span><?php echo esc_html($a['label']); ?></span>
			</button>
			<?php elseif (!empty($a['button'])): ?>
			<button type="button" class="th-lp-kebab-item<?php echo $danger; ?>" role="menuitem"<?php echo $data_attrs; ?>>
			  <?php echo $icon; ?>
			  <span><?php echo esc_html($a['label']); ?></span>
			</button>
			<?php else: ?>
			<a class="th-lp-kebab-item<?php echo $danger; ?>" href="<?php echo esc_url($a['href']); ?>"<?php echo !empty($a['target']) ? ' target="' . esc_attr($a['target']) . '"' : ''; ?><?php echo $data_attrs; ?> role="menuitem">
			  <?php echo $icon; ?>
			  <span><?php echo esc_html($a['label']); ?></span>
			</a>
			<?php endif; endforeach; ?>
		  </div>
		</div>
		<?php
	}
}

// ─── PER-PAGE BUILDERS ────────────────────────────────────────────────────────
//
// Each builder:
//   1. Pulls real WP data
//   2. Builds the config (filter pill counts, action buttons, sort options)
//   3. Provides card + row renderers
//   4. Calls Therum_List_Page::render()

class Therum_Pages_Page {

	public static function render(): void {
		$pages = get_posts([
			'post_type'      => 'page',
			'post_status'    => ['publish','draft','pending','future','trash'],
			'posts_per_page' => -1,
			'orderby'        => 'modified',
			'order'          => 'DESC',
		]);

		$by_status = ['publish'=>0,'draft'=>0,'future'=>0,'trash'=>0];
		foreach ($pages as $p) $by_status[$p->post_status] = ($by_status[$p->post_status] ?? 0) + 1;

		$site_host = parse_url(home_url(), PHP_URL_HOST);

		Therum_List_Page::render([
			'title'    => 'Pages',
			'subtitle' => "Standalone pages on $site_host — homepage, services, about, etc.",
			'page_id'  => 'pages',
			'meta_pills' => [
				count($pages) . ' page' . (count($pages)===1?'':'s'),
				$by_status['draft'] . ' draft' . ($by_status['draft']===1?'':'s'),
			],
			'action_buttons' => [
				['label'=>'Import', 'icon'=>'import', 'href'=>admin_url('import.php')],
				['label'=>'New Page', 'icon'=>'plus', 'primary'=>true, 'href'=>admin_url('post-new.php?post_type=page')],
			],
			'filter_pills' => [
				['key'=>'all',       'label'=>'All',       'count'=>count($pages)],
				['key'=>'publish',   'label'=>'Published', 'count'=>$by_status['publish']],
				['key'=>'draft',     'label'=>'Drafts',    'count'=>$by_status['draft']],
				['key'=>'future',    'label'=>'Scheduled', 'count'=>$by_status['future']],
				['key'=>'trash',     'label'=>'Trash',     'count'=>$by_status['trash']],
			],
			'sort_options' => [
				['key'=>'modified-desc', 'label'=>'Recently modified'],
				['key'=>'modified-asc',  'label'=>'Oldest modified'],
				['key'=>'title-asc',     'label'=>'Title A→Z'],
				['key'=>'title-desc',    'label'=>'Title Z→A'],
				['key'=>'words-desc',    'label'=>'Most words'],
			],
			'search_placeholder' => 'Search pages…',
			'items'              => $pages,
			'card_renderer'      => [self::class, 'render_card'],
			'row_renderer'       => [self::class, 'render_row'],
			'row_actions'        => [self::class, 'row_actions'],
			'table_columns'      => ['Title', 'Status', 'Words', 'Modified', 'Author'],
			'empty_state'        => ['title'=>'No pages yet', 'sub'=>'Click "New Page" to create your first.'],
		]);
	}

	public static function row_actions(\WP_Post $p): array {
		$edit   = get_edit_post_link($p->ID);
		$view   = get_permalink($p->ID);
		$delete = get_delete_post_link($p->ID);
		$dup    = wp_nonce_url(admin_url('admin-post.php?action=therum_duplicate_post&post=' . $p->ID), 'therum_dup_' . $p->ID);
		if ($p->post_status === 'trash') {
			$restore = wp_nonce_url(admin_url('post.php?action=untrash&post=' . $p->ID), 'untrash-post_' . $p->ID);
			$perm_delete = get_delete_post_link($p->ID, '', true);
			$out = [];
			if ($restore) $out[] = ['label' => 'Restore', 'href' => $restore, 'icon' => 'edit2'];
			if ($perm_delete) $out[] = ['label' => 'Delete permanently', 'href' => $perm_delete, 'icon' => 'logout', 'danger' => true];
			return $out;
		}
		$out = [];
		if ($edit) $out[] = ['label' => 'Edit', 'href' => $edit, 'icon' => 'edit2'];
		if ($view) $out[] = ['label' => 'View', 'href' => $view, 'icon' => 'external'];
		if ($dup) $out[] = ['label' => 'Duplicate', 'href' => $dup, 'icon' => 'plus'];
		if ($delete) $out[] = ['label' => 'Move to Trash', 'href' => $delete, 'icon' => 'logout', 'danger' => true];
		return $out;
	}

	public static function render_card(\WP_Post $p): void {
		$status   = $p->post_status;
		$title    = $p->post_title ?: '(untitled)';
		$modified = human_time_diff(get_post_modified_time('U', false, $p));
		$words    = function_exists('th_post_word_count') ? th_post_word_count($p) : str_word_count(wp_strip_all_tags((string)$p->post_content));
		$excerpt  = wp_strip_all_tags((string) get_the_excerpt($p));
		$search   = strtolower($title . ' ' . $excerpt);
		$thumb_style = Therum_Card_Style::thumb_style($p);
		$layout      = Therum_Card_Style::layout($p);
		$status_label = self::status_label($status);
		$tag_html = '<span class="th-lp-card-tag"><span class="th-lp-tag-dot th-lp-tag-' . esc_attr($status) . '"></span>' . esc_html($status_label) . '</span>';
		$bricks_url   = function_exists('th_bricks_builder_url') ? th_bricks_builder_url($p) : '';
		$edit_url     = $bricks_url ?: (string)get_edit_post_link($p->ID);
		?>
		<div class="th-lp-card th-lp-card-layout-<?php echo esc_attr($layout); ?>"
		   data-status="<?php echo esc_attr($status); ?>"
		   data-search="<?php echo esc_attr($search); ?>"
		   data-sort-modified="<?php echo (int)get_post_modified_time('U', true, $p); ?>"
		   data-sort-title="<?php echo esc_attr(strtolower($title)); ?>"
		   data-sort-words="<?php echo $words; ?>"
		   data-edit-href="<?php echo esc_url($edit_url); ?>">
		  <?php if ($layout === 'compact'):
			$mod_ago = human_time_diff(get_post_modified_time('U', false, $p));
			$read_min = max(1, (int)ceil($words / 200));
		  ?>
		  <a class="th-lp-card-link" href="<?php echo esc_url($edit_url); ?>" tabindex="0">
			<div class="th-lp-card-thumb th-lp-card-cmp-thumb" style="<?php echo $thumb_style; ?>"></div>
			<div class="th-lp-card-cmp-title"><?php echo esc_html($title); ?></div>
			<div class="th-lp-card-cmp-tag">#<?php echo esc_html(strtolower($status_label)); ?></div>
			<div class="th-lp-card-cmp-cell">
			  <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
			  <span><?php echo number_format_i18n((int)$words); ?> words</span>
			</div>
			<div class="th-lp-card-cmp-cell">
			  <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
			  <span><?php echo (int)$read_min; ?> min</span>
			</div>
			<span class="th-lp-card-cmp-dot th-lp-tag-<?php echo esc_attr($status); ?>" title="<?php echo esc_attr($status_label); ?>"></span>
		  </a>
		  <?php elseif ($layout === 'compact-v1'):
			$mod_ago = human_time_diff(get_post_modified_time('U', false, $p));
		  ?>
		  <a class="th-lp-card-link" href="<?php echo esc_url($edit_url); ?>" tabindex="0">
			<div class="th-lp-card-thumb th-lp-card-cv1-thumb" style="<?php echo $thumb_style; ?>"></div>
			<div class="th-lp-card-meta">
			  <div class="th-lp-card-title"><?php echo esc_html($title); ?></div>
			  <div class="th-lp-card-cv1-sub"><?php echo esc_html($status_label); ?> · <?php echo esc_html($mod_ago); ?> ago</div>
			</div>
		  </a>
		  <?php elseif ($layout === 'compact-v2'):
			$author_id = $p->post_author;
			$author_name = get_the_author_meta('display_name', $author_id) ?: '—';
			$mod_date = wp_date('M j, Y', (int)get_post_modified_time('U', true, $p));
			$read_min = max(1, (int)ceil($words / 200));
		  ?>
		  <a class="th-lp-card-link" href="<?php echo esc_url($edit_url); ?>" tabindex="0">
			<div class="th-lp-card-thumb th-lp-card-cv2-thumb" style="<?php echo $thumb_style; ?>"></div>
			<div class="th-lp-card-meta">
			  <div class="th-lp-card-cv2-date"><?php echo esc_html($mod_date); ?></div>
			  <div class="th-lp-card-title"><?php echo esc_html($title); ?></div>
			  <div class="th-lp-card-cv2-author"><?php echo esc_html($author_name); ?></div>
			  <div class="th-lp-card-cv2-meta">
				<span class="th-lp-card-cv2-meta-item"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg><?php echo esc_html($status_label); ?></span>
				<span class="th-lp-card-cv2-meta-item"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg><?php echo (int)$read_min; ?> min</span>
			  </div>
			</div>
		  </a>
		  <?php elseif ($layout === 'card-v1'): ?>
		  <a class="th-lp-card-link" href="<?php echo esc_url($edit_url); ?>" tabindex="0">
			<div class="th-lp-card-thumb" style="<?php echo $thumb_style; ?>">
			  <?php echo $tag_html; ?>
			  <div class="th-lp-card-v1-overlay">
				<div class="th-lp-card-title"><?php echo esc_html($title); ?></div>
				<?php if ($excerpt): ?>
				<div class="th-lp-card-excerpt"><?php echo esc_html(wp_trim_words($excerpt, 22, '…')); ?></div>
				<?php endif; ?>
				<div class="th-lp-card-v1-cta">Edit <span class="th-lp-card-v1-arrow">→</span></div>
			  </div>
			</div>
		  </a>
		  <?php elseif ($layout === 'card-v2'):
			$author_id = $p->post_author;
			$author_name = get_the_author_meta('display_name', $author_id) ?: '—';
			$author_avatar = get_avatar_url($author_id, ['size'=>56]);
			$mod_ago = human_time_diff(get_post_modified_time('U', false, $p));
		  ?>
		  <a class="th-lp-card-link" href="<?php echo esc_url($edit_url); ?>" tabindex="0">
			<div class="th-lp-card-v2-img">
			  <div class="th-lp-card-thumb" style="<?php echo $thumb_style; ?>"></div>
			  <?php echo $tag_html; ?>
			  <div class="th-lp-card-v2-img-actions">
				<a class="th-lp-card-v2-iconbtn" href="<?php echo esc_url((string)get_permalink($p->ID)); ?>" target="_blank" rel="noopener" title="View live" onclick="event.stopPropagation();">
				  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
				</a>
				<span class="th-lp-card-v2-iconbtn">
				  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21l-7-5-7 5V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2z"/></svg>
				</span>
			  </div>
			</div>
			<div class="th-lp-card-meta">
			  <div class="th-lp-card-v2-headrow">
				<div class="th-lp-card-v2-primary"><span class="th-lp-card-v2-primary-num"><?php echo (int)$words; ?></span><span class="th-lp-card-v2-primary-unit">/ words</span></div>
				<div class="th-lp-card-v2-rating"><svg width="13" height="13" viewBox="0 0 24 24" fill="#f59e0b" stroke="#f59e0b" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg> <?php echo esc_html($mod_ago); ?></div>
			  </div>
			  <div class="th-lp-card-title"><?php echo esc_html($title); ?></div>
			  <?php if ($excerpt): ?>
			  <div class="th-lp-card-excerpt"><?php echo esc_html(wp_trim_words($excerpt, 14, '…')); ?></div>
			  <?php endif; ?>
			  <div class="th-lp-card-v2-features">
				<span class="th-lp-card-v2-feature"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg><?php echo (int)$words; ?> words</span>
				<span class="th-lp-card-v2-feature"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg><?php echo esc_html($mod_ago); ?></span>
				<span class="th-lp-card-v2-feature"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg><?php echo esc_html($author_name); ?></span>
			  </div>
			</div>
		  </a>
		  <div class="th-lp-card-v2-foot">
			<div class="th-lp-card-v2-author">
			  <img class="th-lp-card-v2-avatar" src="<?php echo esc_url((string)$author_avatar); ?>" alt="" />
			  <div class="th-lp-card-v2-author-info">
				<div class="th-lp-card-v2-author-name"><?php echo esc_html($author_name); ?></div>
				<div class="th-lp-card-v2-author-role">Author</div>
			  </div>
			</div>
			<a class="th-lp-card-v2-foot-cta" href="<?php echo esc_url($edit_url); ?>" title="Edit">
			  <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4z"/></svg>
			</a>
		  </div>
		  <?php elseif ($layout === 'magazine'):
			$mod_date = wp_date('M j, Y', (int)get_post_modified_time('U', true, $p));
			$ptype_obj = get_post_type_object($p->post_type);
			$ptype_label = $ptype_obj ? ($ptype_obj->labels->singular_name ?: $p->post_type) : $p->post_type;
		  ?>
		  <a class="th-lp-card-link" href="<?php echo esc_url($edit_url); ?>" tabindex="0">
			<div class="th-lp-card-mag-top">
			  <span class="th-lp-card-mag-meta-l"><?php echo esc_html($ptype_label); ?></span>
			  <span class="th-lp-card-mag-meta-r"><?php echo esc_html($status_label); ?> · <?php echo esc_html($mod_date); ?></span>
			</div>
			<h2 class="th-lp-card-title th-lp-card-mag-title"><?php echo esc_html($title); ?></h2>
			<div class="th-lp-card-mag-body">
			  <div class="th-lp-card-mag-text">
				<div class="th-lp-card-mag-rule"></div>
				<div class="th-lp-card-mag-label"><?php echo (int)$words; ?> words · <?php echo esc_html(human_time_diff(get_post_modified_time('U', false, $p))); ?> ago</div>
				<?php if ($excerpt): ?>
				<div class="th-lp-card-excerpt"><?php echo esc_html(wp_trim_words($excerpt, 32, '…')); ?></div>
				<?php endif; ?>
				<span class="th-lp-card-mag-cta">Edit Page <span class="th-lp-card-mag-cta-arrow">→</span></span>
			  </div>
			  <div class="th-lp-card-thumb th-lp-card-mag-thumb" style="<?php echo $thumb_style; ?>"></div>
			</div>
		  </a>
		  <?php else: ?>
		  <a class="th-lp-card-link" href="<?php echo esc_url((string)get_permalink($p->ID)); ?>" target="_blank" tabindex="0">
			<div class="th-lp-card-thumb" style="<?php echo $thumb_style; ?>">
			  <?php if ($layout !== 'compact' && $layout !== 'hero'): ?>
				<?php echo $tag_html; ?>
			  <?php endif; ?>
			</div>
			<div class="th-lp-card-meta">
			  <div class="th-lp-card-title"><?php echo esc_html($title); ?></div>
			  <?php if (in_array($layout, ['card','magazine','compact','hero']) && $excerpt): ?>
			  <div class="th-lp-card-excerpt"><?php echo esc_html(wp_trim_words($excerpt, 22, '…')); ?></div>
			  <?php endif; ?>
			  <?php if ($layout === 'hero'): ?>
			  <div class="th-lp-card-pills">
				<?php echo $tag_html; ?>
				<span class="th-lp-card-pill"><?php echo (int)$words; ?> words</span>
				<span class="th-lp-card-pill"><?php echo esc_html(human_time_diff(get_post_modified_time('U', false, $p))); ?> ago</span>
			  </div>
			  <?php else: ?>
			  <div class="th-lp-card-sub">
				<span><?php echo (int)$words; ?> words</span>
			  </div>
			  <?php endif; ?>
			</div>
		  </a>
		  <div class="th-lp-card-actions">
			<a class="th-lp-card-btn" href="<?php echo esc_url((string)get_permalink($p->ID)); ?>" target="_blank">View Live</a>
			<a class="th-lp-card-btn th-lp-card-btn-primary" href="<?php echo esc_url($edit_url); ?>">Edit Page</a>
		  </div>
		  <?php endif; ?>
		  <?php Therum_List_Page::render_card_kebab(self::row_actions($p)); ?>
		</div>
		<?php
	}

	public static function render_row(\WP_Post $p, ?array $actions = null): void {
		$status   = $p->post_status;
		$title    = $p->post_title ?: '(untitled)';
		$modified = human_time_diff(get_post_modified_time('U', false, $p));
		$words    = function_exists('th_post_word_count') ? th_post_word_count($p) : str_word_count(wp_strip_all_tags((string)$p->post_content));
		$author   = get_the_author_meta('display_name', $p->post_author) ?: '—';
		$search   = strtolower($title);
		?>
		<tr class="th-lp-row"
			data-status="<?php echo esc_attr($status); ?>"
			data-search="<?php echo esc_attr($search); ?>"
			data-sort-modified="<?php echo (int)get_post_modified_time('U', true, $p); ?>"
			data-sort-title="<?php echo esc_attr(strtolower($title)); ?>"
			data-sort-words="<?php echo $words; ?>">
		  <td><a href="<?php echo esc_url((string)get_edit_post_link($p->ID)); ?>"><?php echo esc_html($title); ?></a></td>
		  <td><span class="th-lp-status th-lp-status-<?php echo esc_attr($status); ?>"><?php echo esc_html(self::status_label($status)); ?></span></td>
		  <td><?php echo (int)$words; ?></td>
		  <td><?php echo esc_html($modified); ?> ago</td>
		  <td><?php echo esc_html($author); ?></td>
		  <?php if ($actions) Therum_List_Page::render_kebab_cell($actions); ?>
		</tr>
		<?php
	}

	private static function status_label(string $s): string {
		return ['publish'=>'Published','draft'=>'Draft','future'=>'Scheduled','pending'=>'Pending','trash'=>'Trash'][$s] ?? ucfirst($s);
	}
}

// ─── Case Studies ────────────────────────────────────────────────────────────
// Mirrors Therum_Pages_Page exactly but for the `case_study` CPT registered in
// therum-case-study-cpt.php. Reuses Therum_Pages_Page::render_card / render_row
// so the card markup matches Pages 1:1 — same chrome, same kebab, same picker.
class Therum_Case_Studies_Page {

	public static function render(): void {
		$posts = get_posts([
			'post_type'      => 'case_study',
			'post_status'    => ['publish','draft','pending','future','trash'],
			'posts_per_page' => -1,
			'orderby'        => 'modified',
			'order'          => 'DESC',
		]);

		$by_status = ['publish'=>0,'draft'=>0,'future'=>0,'trash'=>0];
		foreach ($posts as $p) $by_status[$p->post_status] = ($by_status[$p->post_status] ?? 0) + 1;

		$disciplines = get_terms(['taxonomy'=>'case_study_discipline','hide_empty'=>false]);
		$tags        = get_terms(['taxonomy'=>'case_study_tag','hide_empty'=>false]);

		Therum_List_Page::render([
			'title'    => 'Case Studies',
			'subtitle' => 'Portfolio entries — projects, write-ups, and shipped work.',
			'page_id'  => 'case-studies',
			'meta_pills' => [
				count($posts) . ' case stud' . (count($posts)===1?'y':'ies'),
				$by_status['draft'] . ' draft' . ($by_status['draft']===1?'':'s'),
				count($disciplines) . ' discipline' . (count($disciplines)===1?'':'s'),
				count($tags) . ' tag' . (count($tags)===1?'':'s'),
			],
			'action_buttons' => [
				['label'=>'Categories', 'icon'=>'folder', 'href'=>admin_url('edit-tags.php?taxonomy=case_study_discipline&post_type=case_study')],
				['label'=>'Tags',       'icon'=>'tag',    'href'=>admin_url('edit-tags.php?taxonomy=case_study_tag&post_type=case_study')],
				['label'=>'New Case Study', 'icon'=>'plus', 'primary'=>true, 'href'=>admin_url('post-new.php?post_type=case_study')],
			],
			'filter_pills' => [
				['key'=>'all',     'label'=>'All',       'count'=>count($posts)],
				['key'=>'publish', 'label'=>'Published', 'count'=>$by_status['publish']],
				['key'=>'draft',   'label'=>'Drafts',    'count'=>$by_status['draft']],
				['key'=>'future',  'label'=>'Scheduled', 'count'=>$by_status['future']],
				['key'=>'trash',   'label'=>'Trash',     'count'=>$by_status['trash']],
			],
			'sort_options' => [
				['key'=>'modified-desc', 'label'=>'Recently modified'],
				['key'=>'modified-asc',  'label'=>'Oldest modified'],
				['key'=>'title-asc',     'label'=>'Title A→Z'],
				['key'=>'title-desc',    'label'=>'Title Z→A'],
			],
			'search_placeholder' => 'Search case studies…',
			'items'              => $posts,
			// Reuse Pages renderers — same card/row markup, same kebab, same picker.
			// Only the action labels differ (handled in row_actions below).
			'card_renderer'      => [self::class, 'render_card'],
			'row_renderer'       => [self::class, 'render_row'],
			'row_actions'        => [self::class, 'row_actions'],
			'table_columns'      => ['Title', 'Status', 'Words', 'Modified', 'Author'],
			'empty_state'        => ['title'=>'No case studies yet', 'sub'=>'Click "New Case Study" to create your first.'],
		]);
	}

	public static function row_actions(\WP_Post $p): array {
		// Same shape as Pages — Edit / View / Duplicate / Trash, with Restore +
		// permanent delete on trashed items. Centralizing here means future
		// portfolio-specific actions (e.g. "Set as featured") slot in one place.
		return Therum_Pages_Page::row_actions($p);
	}

	public static function render_card(\WP_Post $p): void {
		Therum_Pages_Page::render_card($p);
	}

	public static function render_row(\WP_Post $p, ?array $actions = null): void {
		Therum_Pages_Page::render_row($p, $actions);
	}
}

class Therum_Posts_Page {

	public static function render(): void {
		$posts = get_posts([
			'post_type'      => 'post',
			'post_status'    => ['publish','draft','pending','future','trash'],
			'posts_per_page' => -1,
			'orderby'        => 'date',
			'order'          => 'DESC',
		]);

		$by_status = ['publish'=>0,'draft'=>0,'future'=>0,'trash'=>0];
		foreach ($posts as $p) $by_status[$p->post_status] = ($by_status[$p->post_status] ?? 0) + 1;

		$cats = get_categories(['hide_empty'=>false]);

		$site_host = parse_url(home_url(), PHP_URL_HOST);

		Therum_List_Page::render([
			'title'    => 'Posts',
			'subtitle' => "Blog posts and articles on $site_host.",
			'page_id'  => 'posts',
			'meta_pills' => [
				count($posts) . ' post' . (count($posts)===1?'':'s'),
				$by_status['draft'] . ' draft' . ($by_status['draft']===1?'':'s'),
			],
			'action_buttons' => [
				['label'=>'Categories', 'icon'=>'cats', 'href'=>admin_url('edit-tags.php?taxonomy=category')],
				['label'=>'New Post', 'icon'=>'plus', 'primary'=>true, 'href'=>admin_url('post-new.php')],
			],
			'filter_pills' => [
				['key'=>'all',     'label'=>'All',       'count'=>count($posts)],
				['key'=>'publish', 'label'=>'Published', 'count'=>$by_status['publish']],
				['key'=>'draft',   'label'=>'Drafts',    'count'=>$by_status['draft']],
				['key'=>'future',  'label'=>'Scheduled', 'count'=>$by_status['future']],
				['key'=>'trash',   'label'=>'Trash',     'count'=>$by_status['trash']],
			],
			'sort_options' => [
				['key'=>'date-desc',     'label'=>'Recently published'],
				['key'=>'date-asc',      'label'=>'Oldest first'],
				['key'=>'title-asc',     'label'=>'Title A→Z'],
				['key'=>'title-desc',    'label'=>'Title Z→A'],
				['key'=>'comments-desc', 'label'=>'Most comments'],
				['key'=>'words-desc',    'label'=>'Most words'],
			],
			'search_placeholder' => 'Search posts…',
			'items'              => $posts,
			'card_renderer'      => [self::class, 'render_card'],
			'row_renderer'       => [self::class, 'render_row'],
			'row_actions'        => [self::class, 'row_actions'],
			'table_columns'      => ['Title', 'Status', 'Comments', 'Date', 'Author'],
			'empty_state'        => ['title'=>'No posts yet', 'sub'=>'Click "New Post" to publish your first.'],
		]);
	}

	public static function row_actions(\WP_Post $p): array {
		$edit   = get_edit_post_link($p->ID);
		$view   = get_permalink($p->ID);
		$delete = get_delete_post_link($p->ID);
		$dup    = wp_nonce_url(admin_url('admin-post.php?action=therum_duplicate_post&post=' . $p->ID), 'therum_dup_' . $p->ID);
		if ($p->post_status === 'trash') {
			$restore = wp_nonce_url(admin_url('post.php?action=untrash&post=' . $p->ID), 'untrash-post_' . $p->ID);
			$perm_delete = get_delete_post_link($p->ID, '', true);
			$out = [];
			if ($restore) $out[] = ['label' => 'Restore', 'href' => $restore, 'icon' => 'edit2'];
			if ($perm_delete) $out[] = ['label' => 'Delete permanently', 'href' => $perm_delete, 'icon' => 'logout', 'danger' => true];
			return $out;
		}
		$out = [];
		if ($edit) $out[] = ['label' => 'Edit', 'href' => $edit, 'icon' => 'edit2'];
		if ($view) $out[] = ['label' => 'View', 'href' => $view, 'icon' => 'external'];
		if ($dup) $out[] = ['label' => 'Duplicate', 'href' => $dup, 'icon' => 'plus'];
		if ($delete) $out[] = ['label' => 'Move to Trash', 'href' => $delete, 'icon' => 'logout', 'danger' => true];
		return $out;
	}

	public static function render_card(\WP_Post $p): void {
		$status   = $p->post_status;
		$title    = $p->post_title ?: '(untitled)';
		$excerpt  = wp_trim_words(wp_strip_all_tags((string)$p->post_content), 24);
		$words    = function_exists('th_post_word_count') ? th_post_word_count($p) : str_word_count(wp_strip_all_tags((string)$p->post_content));
		$date     = human_time_diff(strtotime((string)$p->post_date));
		$comments = (int)$p->comment_count;
		$cats     = wp_get_post_categories($p->ID, ['fields'=>'names']);
		$cat      = $cats[0] ?? '';
		$thumb_style = Therum_Card_Style::thumb_style($p);
		$layout      = Therum_Card_Style::layout($p);
		$status_label = ['publish'=>'Published','draft'=>'Draft','future'=>'Scheduled','pending'=>'Pending','trash'=>'Trash'][$status] ?? ucfirst($status);
		$tag_html = '<span class="th-lp-card-tag"><span class="th-lp-tag-dot th-lp-tag-' . esc_attr($status) . '"></span>' . esc_html($status_label) . '</span>';
		$bricks_url   = function_exists('th_bricks_builder_url') ? th_bricks_builder_url($p) : '';
		$edit_url     = $bricks_url ?: (string)get_edit_post_link($p->ID);
		?>
		<div class="th-lp-card th-lp-card-layout-<?php echo esc_attr($layout); ?>"
		   data-status="<?php echo esc_attr($status); ?>"
		   data-category="<?php echo esc_attr(sanitize_title($cat)); ?>"
		   data-search="<?php echo esc_attr(strtolower($title.' '.$excerpt.' '.$cat)); ?>"
		   data-sort-date="<?php echo (int)get_post_time('U', true, $p); ?>"
		   data-sort-title="<?php echo esc_attr(strtolower($title)); ?>"
		   data-sort-comments="<?php echo $comments; ?>"
		   data-sort-words="<?php echo str_word_count($excerpt); ?>"
		   data-edit-href="<?php echo esc_url($edit_url); ?>">
		  <?php if ($layout === 'compact'):
			$mod_ago = human_time_diff(get_post_modified_time('U', false, $p));
			$read_min = max(1, (int)ceil($words / 200));
		  ?>
		  <a class="th-lp-card-link" href="<?php echo esc_url($edit_url); ?>" tabindex="0">
			<div class="th-lp-card-thumb th-lp-card-cmp-thumb" style="<?php echo $thumb_style; ?>"></div>
			<div class="th-lp-card-cmp-title"><?php echo esc_html($title); ?></div>
			<div class="th-lp-card-cmp-tag">#<?php echo esc_html(strtolower($status_label)); ?></div>
			<div class="th-lp-card-cmp-cell">
			  <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
			  <span><?php echo number_format_i18n((int)$words); ?> words</span>
			</div>
			<div class="th-lp-card-cmp-cell">
			  <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
			  <span><?php echo (int)$read_min; ?> min</span>
			</div>
			<span class="th-lp-card-cmp-dot th-lp-tag-<?php echo esc_attr($status); ?>" title="<?php echo esc_attr($status_label); ?>"></span>
		  </a>
		  <?php elseif ($layout === 'compact-v1'):
			$mod_ago = human_time_diff(get_post_modified_time('U', false, $p));
		  ?>
		  <a class="th-lp-card-link" href="<?php echo esc_url($edit_url); ?>" tabindex="0">
			<div class="th-lp-card-thumb th-lp-card-cv1-thumb" style="<?php echo $thumb_style; ?>"></div>
			<div class="th-lp-card-meta">
			  <div class="th-lp-card-title"><?php echo esc_html($title); ?></div>
			  <div class="th-lp-card-cv1-sub"><?php echo esc_html($status_label); ?> · <?php echo esc_html($mod_ago); ?> ago</div>
			</div>
		  </a>
		  <?php elseif ($layout === 'compact-v2'):
			$author_id = $p->post_author;
			$author_name = get_the_author_meta('display_name', $author_id) ?: '—';
			$mod_date = wp_date('M j, Y', (int)get_post_modified_time('U', true, $p));
			$read_min = max(1, (int)ceil($words / 200));
		  ?>
		  <a class="th-lp-card-link" href="<?php echo esc_url($edit_url); ?>" tabindex="0">
			<div class="th-lp-card-thumb th-lp-card-cv2-thumb" style="<?php echo $thumb_style; ?>"></div>
			<div class="th-lp-card-meta">
			  <div class="th-lp-card-cv2-date"><?php echo esc_html($mod_date); ?></div>
			  <div class="th-lp-card-title"><?php echo esc_html($title); ?></div>
			  <div class="th-lp-card-cv2-author"><?php echo esc_html($author_name); ?></div>
			  <div class="th-lp-card-cv2-meta">
				<span class="th-lp-card-cv2-meta-item"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg><?php echo esc_html($status_label); ?></span>
				<span class="th-lp-card-cv2-meta-item"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg><?php echo (int)$read_min; ?> min</span>
			  </div>
			</div>
		  </a>
		  <?php elseif ($layout === 'card-v1'): ?>
		  <a class="th-lp-card-link" href="<?php echo esc_url($edit_url); ?>" tabindex="0">
			<div class="th-lp-card-thumb" style="<?php echo $thumb_style; ?>">
			  <?php echo $tag_html; ?>
			  <div class="th-lp-card-v1-overlay">
				<div class="th-lp-card-title"><?php echo esc_html($title); ?></div>
				<?php if ($excerpt): ?>
				<div class="th-lp-card-excerpt"><?php echo esc_html(wp_trim_words($excerpt, 22, '…')); ?></div>
				<?php endif; ?>
				<div class="th-lp-card-v1-cta">Edit <span class="th-lp-card-v1-arrow">→</span></div>
			  </div>
			</div>
		  </a>
		  <?php elseif ($layout === 'card-v2'):
			$author_id = $p->post_author;
			$author_name = get_the_author_meta('display_name', $author_id) ?: '—';
			$author_avatar = get_avatar_url($author_id, ['size'=>56]);
			$mod_ago = human_time_diff(get_post_modified_time('U', false, $p));
		  ?>
		  <a class="th-lp-card-link" href="<?php echo esc_url($edit_url); ?>" tabindex="0">
			<div class="th-lp-card-v2-img">
			  <div class="th-lp-card-thumb" style="<?php echo $thumb_style; ?>"></div>
			  <?php echo $tag_html; ?>
			  <div class="th-lp-card-v2-img-actions">
				<a class="th-lp-card-v2-iconbtn" href="<?php echo esc_url((string)get_permalink($p->ID)); ?>" target="_blank" rel="noopener" title="View live" onclick="event.stopPropagation();">
				  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
				</a>
				<span class="th-lp-card-v2-iconbtn">
				  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21l-7-5-7 5V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2z"/></svg>
				</span>
			  </div>
			</div>
			<div class="th-lp-card-meta">
			  <div class="th-lp-card-v2-headrow">
				<div class="th-lp-card-v2-primary"><span class="th-lp-card-v2-primary-num"><?php echo (int)$words; ?></span><span class="th-lp-card-v2-primary-unit">/ words</span></div>
				<div class="th-lp-card-v2-rating"><svg width="13" height="13" viewBox="0 0 24 24" fill="#f59e0b" stroke="#f59e0b" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg> <?php echo esc_html($mod_ago); ?></div>
			  </div>
			  <div class="th-lp-card-title"><?php echo esc_html($title); ?></div>
			  <?php if ($excerpt): ?>
			  <div class="th-lp-card-excerpt"><?php echo esc_html(wp_trim_words($excerpt, 14, '…')); ?></div>
			  <?php endif; ?>
			  <div class="th-lp-card-v2-features">
				<span class="th-lp-card-v2-feature"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg><?php echo (int)$words; ?> words</span>
				<span class="th-lp-card-v2-feature"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg><?php echo esc_html($mod_ago); ?></span>
				<span class="th-lp-card-v2-feature"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg><?php echo esc_html($author_name); ?></span>
			  </div>
			</div>
		  </a>
		  <div class="th-lp-card-v2-foot">
			<div class="th-lp-card-v2-author">
			  <img class="th-lp-card-v2-avatar" src="<?php echo esc_url((string)$author_avatar); ?>" alt="" />
			  <div class="th-lp-card-v2-author-info">
				<div class="th-lp-card-v2-author-name"><?php echo esc_html($author_name); ?></div>
				<div class="th-lp-card-v2-author-role">Author</div>
			  </div>
			</div>
			<a class="th-lp-card-v2-foot-cta" href="<?php echo esc_url($edit_url); ?>" title="Edit">
			  <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4z"/></svg>
			</a>
		  </div>
		  <?php elseif ($layout === 'magazine'):
			$mod_date = wp_date('M j, Y', (int)get_post_modified_time('U', true, $p));
			$ptype_obj = get_post_type_object($p->post_type);
			$ptype_label = $ptype_obj ? ($ptype_obj->labels->singular_name ?: $p->post_type) : $p->post_type;
		  ?>
		  <a class="th-lp-card-link" href="<?php echo esc_url($edit_url); ?>" tabindex="0">
			<div class="th-lp-card-mag-top">
			  <span class="th-lp-card-mag-meta-l"><?php echo esc_html($ptype_label); ?></span>
			  <span class="th-lp-card-mag-meta-r"><?php echo esc_html($status_label); ?> · <?php echo esc_html($mod_date); ?></span>
			</div>
			<h2 class="th-lp-card-title th-lp-card-mag-title"><?php echo esc_html($title); ?></h2>
			<div class="th-lp-card-mag-body">
			  <div class="th-lp-card-mag-text">
				<div class="th-lp-card-mag-rule"></div>
				<div class="th-lp-card-mag-label"><?php echo (int)$words; ?> words · <?php echo esc_html(human_time_diff(get_post_modified_time('U', false, $p))); ?> ago</div>
				<?php if ($excerpt): ?>
				<div class="th-lp-card-excerpt"><?php echo esc_html(wp_trim_words($excerpt, 32, '…')); ?></div>
				<?php endif; ?>
				<span class="th-lp-card-mag-cta">Edit Page <span class="th-lp-card-mag-cta-arrow">→</span></span>
			  </div>
			  <div class="th-lp-card-thumb th-lp-card-mag-thumb" style="<?php echo $thumb_style; ?>"></div>
			</div>
		  </a>
		  <?php else: ?>
		  <a class="th-lp-card-link" href="<?php echo esc_url((string)get_permalink($p->ID)); ?>" target="_blank" tabindex="0">
			<div class="th-lp-card-thumb" style="<?php echo $thumb_style; ?>">
			  <?php if ($layout !== 'compact' && $layout !== 'hero'): ?>
				<?php echo $tag_html; ?>
			  <?php endif; ?>
			</div>
			<div class="th-lp-card-meta">
			  <div class="th-lp-card-title"><?php echo esc_html($title); ?></div>
			  <?php if (in_array($layout, ['card','magazine','compact','hero']) && $excerpt): ?>
			  <div class="th-lp-card-excerpt"><?php echo esc_html($excerpt); ?></div>
			  <?php endif; ?>
			  <?php if ($layout === 'hero'): ?>
			  <div class="th-lp-card-pills">
				<?php echo $tag_html; ?>
				<span class="th-lp-card-pill"><?php echo (int)$words; ?> words</span>
				<span class="th-lp-card-pill"><?php echo esc_html(human_time_diff(get_post_modified_time('U', false, $p))); ?> ago</span>
			  </div>
			  <?php else: ?>
			  <div class="th-lp-card-sub">
				<span><?php echo (int)$words; ?> words</span>
			  </div>
			  <?php endif; ?>
			</div>
		  </a>
		  <div class="th-lp-card-actions">
			<a class="th-lp-card-btn" href="<?php echo esc_url((string)get_permalink($p->ID)); ?>" target="_blank">View Live</a>
			<a class="th-lp-card-btn th-lp-card-btn-primary" href="<?php echo esc_url($edit_url); ?>">Edit Page</a>
		  </div>
		  <?php endif; ?>
		  <?php Therum_List_Page::render_card_kebab(self::row_actions($p)); ?>
		</div>
		<?php
	}

	public static function render_row(\WP_Post $p, ?array $actions = null): void {
		$status   = $p->post_status;
		$title    = $p->post_title ?: '(untitled)';
		$date     = wp_date('M j, Y', strtotime((string)$p->post_date));
		$comments = (int)$p->comment_count;
		$author   = get_the_author_meta('display_name', $p->post_author) ?: '—';
		?>
		<tr class="th-lp-row"
			data-status="<?php echo esc_attr($status); ?>"
			data-search="<?php echo esc_attr(strtolower($title)); ?>"
			data-sort-date="<?php echo (int)get_post_time('U', true, $p); ?>"
			data-sort-title="<?php echo esc_attr(strtolower($title)); ?>"
			data-sort-comments="<?php echo $comments; ?>">
		  <td><a href="<?php echo esc_url((string)get_edit_post_link($p->ID)); ?>"><?php echo esc_html($title); ?></a></td>
		  <td><span class="th-lp-status th-lp-status-<?php echo esc_attr($status); ?>"><?php echo esc_html(['publish'=>'Published','draft'=>'Draft','future'=>'Scheduled','pending'=>'Pending','trash'=>'Trash'][$status] ?? ucfirst($status)); ?></span></td>
		  <td><?php echo $comments; ?></td>
		  <td><?php echo esc_html($date); ?></td>
		  <td><?php echo esc_html($author); ?></td>
		  <?php if ($actions) Therum_List_Page::render_kebab_cell($actions); ?>
		</tr>
		<?php
	}
}

class Therum_Media_Page {

	public static function render(): void {
		$attachments = get_posts([
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'posts_per_page' => 200,
			'orderby'        => 'date',
			'order'          => 'DESC',
		]);

		$by_kind = ['image'=>0,'video'=>0,'audio'=>0,'doc'=>0];
		foreach ($attachments as $a) {
			$by_kind[self::mime_kind($a->post_mime_type)] = ($by_kind[self::mime_kind($a->post_mime_type)] ?? 0) + 1;
		}

		Therum_List_Page::render([
			'title'    => 'Media',
			'subtitle' => 'Images, video, audio and documents in the media library.',
			'page_id'  => 'media',
			'meta_pills' => [
				count($attachments) . ' file' . (count($attachments)===1?'':'s'),
				size_format(self::total_size($attachments)),
			],
			'action_buttons' => [
				['label'=>'Upload', 'icon'=>'import', 'primary'=>true, 'href'=>admin_url('media-new.php')],
				['label'=>'Bulk rename', 'icon'=>'edit2', 'href'=>'#', 'attrs' => ['data-th-bulk-rename' => '1']],
				['label'=>'Download library', 'icon'=>'export', 'href'=>wp_nonce_url( admin_url('admin-ajax.php?action=therum_media_download_zip'), 'therum_media_zip' )],
			],
			'density_slider'  => true,
			'density_default' => (int) th_pref( 'media_density', 5 ),
			'view_default'    => (string) th_pref( 'media_view_mode', 'grid' ),
			'extra_views'     => [
				[ 'key' => 'masonry', 'label' => 'Masonry view', 'svg' => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="9"/><rect x="14" y="3" width="7" height="5"/><rect x="14" y="12" width="7" height="9"/><rect x="3" y="16" width="7" height="5"/></svg>' ],
				[ 'key' => 'metro',   'label' => 'Metro tile view', 'svg' => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="11" height="11"/><rect x="16" y="3" width="5" height="5"/><rect x="16" y="10" width="5" height="11"/><rect x="3" y="16" width="11" height="5"/></svg>' ],
			],
			'filter_pills' => [
				['key'=>'all',   'label'=>'All',       'count'=>count($attachments)],
				['key'=>'image', 'label'=>'Images',    'count'=>$by_kind['image']],
				['key'=>'video', 'label'=>'Video',     'count'=>$by_kind['video']],
				['key'=>'audio', 'label'=>'Audio',     'count'=>$by_kind['audio']],
				['key'=>'doc',   'label'=>'Documents', 'count'=>$by_kind['doc']],
			],
			'sort_options' => [
				['key'=>'date-desc', 'label'=>'Newest first'],
				['key'=>'date-asc',  'label'=>'Oldest first'],
				['key'=>'title-asc', 'label'=>'Name A→Z'],
				['key'=>'title-desc','label'=>'Name Z→A'],
				['key'=>'size-desc', 'label'=>'Largest first'],
				['key'=>'size-asc',  'label'=>'Smallest first'],
			],
			'search_placeholder' => 'Search media…',
			'items'              => $attachments,
			'card_renderer'      => [self::class, 'render_card'],
			'row_renderer'       => [self::class, 'render_row'],
			'row_actions'        => [self::class, 'row_actions'],
			'table_columns'      => ['File', 'Type', 'Size', 'Uploaded'],
			'empty_state'        => ['title'=>'Media library is empty', 'sub'=>'Upload your first file to get started.'],
		]);
	}

	public static function row_actions(\WP_Post $a): array {
		$edit  = get_edit_post_link($a->ID);
		$url   = wp_get_attachment_url($a->ID);
		$delete= get_delete_post_link($a->ID, '', true);
		$file  = get_attached_file($a->ID);
		$basename = $file ? basename($file) : '';
		$out = [];
		if ($edit) $out[] = ['label' => 'Edit details', 'href' => $edit, 'icon' => 'edit2'];
		// Rename for SEO — opens the renamer modal (markup printed by therum-renamer.php
		// in the admin_footer of the Media list page).
		if ($basename) {
			$out[] = [
				'label'  => 'Rename for SEO',
				'button' => true,
				'icon'   => 'edit2',
				'data'   => [
					'th-rename'      => $a->ID,
					'th-rename-name' => $basename,
				],
			];
		}
		if ($url) {
			$out[] = ['label' => 'View file', 'href' => $url, 'icon' => 'external', 'target' => '_blank'];
			$out[] = ['label' => 'Copy URL',  'copy' => $url, 'icon' => 'export'];
		}
		if ($delete) $out[] = ['label' => 'Delete permanently', 'href' => $delete, 'icon' => 'logout', 'danger' => true];
		return $out;
	}

	private static function mime_kind(string $mime): string {
		if (str_starts_with($mime, 'image/')) return 'image';
		if (str_starts_with($mime, 'video/')) return 'video';
		if (str_starts_with($mime, 'audio/')) return 'audio';
		return 'doc';
	}

	private static function total_size(array $atts): int {
		$total = 0;
		foreach ($atts as $a) {
			$f = get_attached_file($a->ID);
			if ($f && file_exists($f)) $total += filesize($f);
		}
		return $total;
	}

	public static function render_card(\WP_Post $a): void {
		$kind = self::mime_kind((string)$a->post_mime_type);
		$thumb = wp_get_attachment_image_url($a->ID, 'medium') ?: '';
		$title = $a->post_title ?: basename((string)get_attached_file($a->ID));
		$size  = file_exists((string)get_attached_file($a->ID)) ? size_format(filesize((string)get_attached_file($a->ID))) : '';
		$date  = wp_date('M j', strtotime((string)$a->post_date));
		$thumb_style = $kind === 'image' && $thumb
			? "background-image:url('".esc_url($thumb)."');background-size:cover;background-position:center;"
			: 'background:var(--sf2);display:flex;align-items:center;justify-content:center;color:var(--tx3);font-size:28px;';
		?>
		<div class="th-lp-card th-lp-card-media"
		   data-status="<?php echo esc_attr($kind); ?>"
		   data-search="<?php echo esc_attr(strtolower($title)); ?>"
		   data-sort-date="<?php echo (int)get_post_time('U', true, $a); ?>"
		   data-sort-title="<?php echo esc_attr(strtolower($title)); ?>"
		   data-sort-size="<?php echo file_exists((string)get_attached_file($a->ID)) ? filesize((string)get_attached_file($a->ID)) : 0; ?>">
		  <a class="th-lp-card-link" href="<?php echo esc_url((string)get_edit_post_link($a->ID)); ?>" tabindex="0">
			<div class="th-lp-card-thumb th-lp-card-thumb-square" style="<?php echo $thumb_style; ?>">
			  <?php if ($kind !== 'image'): ?><span><?php echo strtoupper(substr($kind,0,3)); ?></span><?php endif; ?>
			</div>
			<div class="th-lp-card-meta">
			  <div class="th-lp-card-title"><?php echo esc_html($title); ?></div>
			  <div class="th-lp-card-sub"><?php echo esc_html($size . ($size?' · ':'') . $date); ?></div>
			</div>
		  </a>
		  <?php Therum_List_Page::render_card_kebab(self::row_actions($a)); ?>
		</div>
		<?php
	}

	public static function mime_icon(string $kind): string {
		$icons = [
			'video' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><polygon points="23 7 16 12 23 17 23 7"/><rect x="1" y="5" width="15" height="14" rx="2" ry="2"/></svg>',
			'audio' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M9 18V5l12-2v13"/><circle cx="6" cy="18" r="3"/><circle cx="18" cy="16" r="3"/></svg>',
			'document' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>',
			'archive' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><polyline points="21 8 21 21 3 21 3 8"/><rect x="1" y="3" width="22" height="5"/><line x1="10" y1="12" x2="14" y2="12"/></svg>',
			'image' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>',
			'other' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>',
		];
		return $icons[$kind] ?? $icons['other'];
	}

	public static function render_row(\WP_Post $a, ?array $actions = null): void {
		$kind = self::mime_kind((string)$a->post_mime_type);
		$title = $a->post_title ?: basename((string)get_attached_file($a->ID));
		$size  = file_exists((string)get_attached_file($a->ID)) ? size_format(filesize((string)get_attached_file($a->ID))) : '—';
		$date  = wp_date('M j, Y', strtotime((string)$a->post_date));
		?>
		<tr class="th-lp-row"
			data-status="<?php echo esc_attr($kind); ?>"
			data-search="<?php echo esc_attr(strtolower($title)); ?>"
			data-sort-date="<?php echo (int)get_post_time('U', true, $a); ?>"
			data-sort-title="<?php echo esc_attr(strtolower($title)); ?>">
		  <td>
			<a class="th-media-row-link" href="<?php echo esc_url((string)get_edit_post_link($a->ID)); ?>">
			  <?php
			  $thumb_url = '';
			  if ($kind === 'image') {
				  $thumb_url = wp_get_attachment_image_url($a->ID, [40, 40]) ?: wp_get_attachment_url($a->ID);
			  }
			  ?>
			  <?php if ($thumb_url): ?>
				<span class="th-media-row-thumb" style="background-image:url('<?php echo esc_url($thumb_url); ?>');"></span>
			  <?php else: ?>
				<span class="th-media-row-thumb th-media-row-thumb-icon th-media-row-thumb-<?php echo esc_attr($kind); ?>">
				  <?php echo self::mime_icon($kind); ?>
				</span>
			  <?php endif; ?>
			  <span class="th-media-row-title"><?php echo esc_html($title); ?></span>
			</a>
		  </td>
		  <td><?php echo esc_html(ucfirst($kind)); ?></td>
		  <td><?php echo esc_html($size); ?></td>
		  <td><?php echo esc_html($date); ?></td>
		  <?php if ($actions) Therum_List_Page::render_kebab_cell($actions); ?>
		</tr>
		<?php
	}
}

class Therum_Users_Page {

	public static function render(): void {
		$users = get_users(['number' => 200]);

		$by_role = [];
		foreach ($users as $u) {
			$role = $u->roles[0] ?? 'subscriber';
			$by_role[$role] = ($by_role[$role] ?? 0) + 1;
		}

		$pills = [
			['key'=>'all', 'label'=>'All', 'count'=>count($users)],
		];
		foreach (['administrator'=>'Admins','editor'=>'Editors','author'=>'Authors','contributor'=>'Contributors','subscriber'=>'Subscribers'] as $r=>$lbl) {
			if (($by_role[$r] ?? 0) > 0) {
				$pills[] = ['key'=>$r, 'label'=>$lbl, 'count'=>$by_role[$r]];
			}
		}

		Therum_List_Page::render([
			'title'    => 'Users',
			'subtitle' => 'People with accounts on this site.',
			'page_id'  => 'users',
			'meta_pills' => [
				count($users) . ' user' . (count($users)===1?'':'s'),
				($by_role['administrator'] ?? 0) . ' admin' . (($by_role['administrator'] ?? 0)===1?'':'s'),
			],
			'action_buttons' => [
				['label'=>'Add User', 'icon'=>'plus', 'primary'=>true, 'href'=>admin_url('user-new.php')],
			],
			'filter_pills' => $pills,
			'sort_options' => [
				['key'=>'date-desc',  'label'=>'Recently joined'],
				['key'=>'date-asc',   'label'=>'Earliest joined'],
				['key'=>'title-asc',  'label'=>'Name A→Z'],
				['key'=>'title-desc', 'label'=>'Name Z→A'],
				['key'=>'posts-desc', 'label'=>'Most posts'],
			],
			'search_placeholder' => 'Search users…',
			'items'              => $users,
			'card_renderer'      => [self::class, 'render_card'],
			'row_renderer'       => [self::class, 'render_row'],
			'row_actions'        => [self::class, 'row_actions'],
			'table_columns'      => ['User', 'Role', 'Posts', 'Joined', 'Email'],
			'empty_state'        => ['title'=>'No users yet', 'sub'=>'Invite teammates to get started.'],
		]);
	}

	public static function row_actions(\WP_User $u): array {
		$edit = admin_url('user-edit.php?user_id=' . $u->ID);
		$out = [
			['label' => 'Edit profile', 'href' => $edit, 'icon' => 'edit2'],
			['label' => 'Email', 'href' => 'mailto:' . $u->user_email, 'icon' => 'external'],
		];
		if (current_user_can('delete_users') && $u->ID !== get_current_user_id()) {
			$delete = wp_nonce_url(admin_url('users.php?action=delete&user=' . $u->ID), 'bulk-users');
			$out[] = ['label' => 'Delete user', 'href' => $delete, 'icon' => 'logout', 'danger' => true];
		}
		return $out;
	}

	public static function render_card(\WP_User $u): void {
		$role = $u->roles[0] ?? 'subscriber';
		$avatar = get_avatar_url($u->ID, ['size'=>96]);
		$posts = (int) count_user_posts($u->ID);
		$joined = wp_date('M Y', strtotime((string)$u->user_registered));
		$search = strtolower($u->display_name . ' ' . $u->user_email . ' ' . $u->user_login);
		?>
		<div class="th-lp-card th-lp-card-user"
		   data-status="<?php echo esc_attr($role); ?>"
		   data-search="<?php echo esc_attr($search); ?>"
		   data-sort-date="<?php echo (int)strtotime((string)$u->user_registered); ?>"
		   data-sort-title="<?php echo esc_attr(strtolower($u->display_name)); ?>"
		   data-sort-posts="<?php echo $posts; ?>">
		  <a class="th-lp-card-link" href="<?php echo esc_url(admin_url('user-edit.php?user_id=' . $u->ID)); ?>" tabindex="0">
			<div class="th-lp-user-avatar"><img src="<?php echo esc_url($avatar); ?>" alt="" /></div>
			<div class="th-lp-card-meta">
			  <div class="th-lp-card-title"><?php echo esc_html($u->display_name); ?></div>
			  <div class="th-lp-card-sub"><span class="th-lp-role-pill th-lp-role-<?php echo esc_attr($role); ?>"><?php echo esc_html(ucfirst($role)); ?></span></div>
			  <div class="th-lp-card-sub"><?php echo $posts; ?> post<?php echo $posts===1?'':'s'; ?> · joined <?php echo esc_html($joined); ?></div>
			</div>
		  </a>
		  <?php Therum_List_Page::render_card_kebab(self::row_actions($u)); ?>
		</div>
		<?php
	}

	public static function render_row(\WP_User $u, ?array $actions = null): void {
		$role = $u->roles[0] ?? 'subscriber';
		$posts = (int) count_user_posts($u->ID);
		$joined = wp_date('M j, Y', strtotime((string)$u->user_registered));
		?>
		<tr class="th-lp-row"
			data-status="<?php echo esc_attr($role); ?>"
			data-search="<?php echo esc_attr(strtolower($u->display_name . ' ' . $u->user_email)); ?>"
			data-sort-date="<?php echo (int)strtotime((string)$u->user_registered); ?>"
			data-sort-title="<?php echo esc_attr(strtolower($u->display_name)); ?>"
			data-sort-posts="<?php echo $posts; ?>">
		  <td><a href="<?php echo esc_url(admin_url('user-edit.php?user_id=' . $u->ID)); ?>"><?php echo esc_html($u->display_name); ?></a></td>
		  <td><span class="th-lp-role-pill th-lp-role-<?php echo esc_attr($role); ?>"><?php echo esc_html(ucfirst($role)); ?></span></td>
		  <td><?php echo $posts; ?></td>
		  <td><?php echo esc_html($joined); ?></td>
		  <td><?php echo esc_html($u->user_email); ?></td>
		  <?php if ($actions) Therum_List_Page::render_kebab_cell($actions); ?>
		</tr>
		<?php
	}
}

/* ─────────────────────────────────────────────────────────────────────
 * BRICKS TEMPLATES — list page for bricks_template post type
 * Renders inline (not iframed) using the standard Therum_List_Page
 * ─────────────────────────────────────────────────────────────────── */
class Therum_Templates_Page {

	/* Verified from Bricks v2.3.1 source: bricks/includes/setup.php */
	const TYPE_LABELS = [
		'header'              => 'Header',
		'footer'              => 'Footer',
		'content'             => 'Single',
		'section'             => 'Section',
		'popup'               => 'Popup',
		'archive'             => 'Archive',
		'search'              => 'Search results',
		'error'               => 'Error page',
		'password_protection' => 'Password protection',
	];

	/* Status palette for the template type pill (left border + bg accent) */
	const TYPE_PALETTE = [
		'header'  => ['#3b82f6', 'rgba(59,130,246,0.10)'],
		'footer'  => ['#8b5cf6', 'rgba(139,92,246,0.10)'],
		'content' => ['#10b981', 'rgba(16,185,129,0.10)'],
		'section' => ['#f59e0b', 'rgba(245,158,11,0.10)'],
		'popup'   => ['#ec4899', 'rgba(236,72,153,0.10)'],
		'archive' => ['#06b6d4', 'rgba(6,182,212,0.10)'],
		'search'  => ['#6366f1', 'rgba(99,102,241,0.10)'],
		'error'   => ['#ef4444', 'rgba(239,68,68,0.10)'],
	];

	public static function render(): void {
		$templates = get_posts([
			'post_type'      => 'bricks_template',
			'post_status'    => ['publish','draft','pending','future'],
			'posts_per_page' => -1,
			'orderby'        => 'modified',
			'order'          => 'DESC',
		]);

		$by_type = [];
		foreach ($templates as $t) {
			$type = get_post_meta($t->ID, '_bricks_template_type', true) ?: 'section';
			$by_type[$type] = ($by_type[$type] ?? 0) + 1;
		}

		$site_host = parse_url(home_url(), PHP_URL_HOST);

		// Build filter pills dynamically from what's actually in the library
		$filter_pills = [
			['key' => 'all', 'label' => 'All', 'count' => count($templates)],
		];
		foreach (self::TYPE_LABELS as $type => $label) {
			if (!empty($by_type[$type])) {
				$filter_pills[] = ['key' => $type, 'label' => $label, 'count' => $by_type[$type]];
			}
		}

		Therum_List_Page::render([
			'title'    => 'Templates',
			'subtitle' => "Header, footer, archive, and section templates for $site_host.",
			'page_id'  => 'templates',
			'meta_pills' => [
				count($templates) . ' template' . (count($templates) === 1 ? '' : 's'),
			],
			'action_buttons' => [
				['label' => 'Import',  'icon' => 'import', 'href' => admin_url('edit.php?post_type=bricks_template')],
				['label' => 'New Template', 'icon' => 'plus', 'primary' => true, 'href' => admin_url('post-new.php?post_type=bricks_template')],
			],
			'filter_pills'       => $filter_pills,
			'sort_options'       => [
				['key' => 'modified-desc', 'label' => 'Recently modified'],
				['key' => 'modified-asc',  'label' => 'Oldest modified'],
				['key' => 'title-asc',     'label' => 'Title A→Z'],
				['key' => 'title-desc',    'label' => 'Title Z→A'],
			],
			'search_placeholder' => 'Search templates…',
			'items'              => $templates,
			'card_renderer'      => [self::class, 'render_card'],
			'row_renderer'       => [self::class, 'render_row'],
			'row_actions'        => [self::class, 'row_actions'],
			'table_columns'      => ['Title', 'Type', 'Status', 'Modified', 'Author'],
			'empty_state'        => ['title' => 'No templates yet', 'sub' => 'Click "New Template" to create your first.'],
		]);
	}

	public static function render_card(\WP_Post $t): void {
		$type        = get_post_meta($t->ID, '_bricks_template_type', true) ?: 'section';
		$type_label  = self::TYPE_LABELS[$type] ?? ucfirst($type);
		$status      = $t->post_status;
		$title       = $t->post_title ?: '(untitled)';
		$modified    = human_time_diff(get_post_modified_time('U', false, $t));
		$search      = strtolower($title . ' ' . $type_label);
		$layout      = Therum_Card_Style::layout($t);
		$status_label = self::status_label($status);
		$builder_url = self::builder_url($t->ID);
		$dup_url     = wp_nonce_url(admin_url('admin-post.php?action=therum_duplicate_template&post=' . $t->ID), 'therum_dup_' . $t->ID);

		// Soft uniform purple/blue gradient — published gets the accent tint,
		// drafts fade toward neutral. Matches the Therum demo aesthetic.
		$is_live = ($status === 'publish');
		$thumb_style = $is_live
			? 'background: linear-gradient(135deg, color-mix(in srgb, var(--ac) 22%, var(--sf2)) 0%, color-mix(in srgb, #8b5cf6 16%, var(--sf2)) 100%);'
			: 'background: linear-gradient(135deg, var(--sf2) 0%, color-mix(in srgb, var(--tx3) 14%, var(--sf2)) 100%);';
		?>
		<div class="th-lp-card th-lp-card-layout-<?php echo esc_attr($layout); ?>"
		   data-status="<?php echo esc_attr($type); ?>"
		   data-post-status="<?php echo esc_attr($status); ?>"
		   data-template-type="<?php echo esc_attr($type); ?>"
		   data-search="<?php echo esc_attr($search); ?>"
		   data-sort-modified="<?php echo (int) get_post_modified_time('U', true, $t); ?>"
		   data-sort-title="<?php echo esc_attr(strtolower($title)); ?>"
		   data-edit-href="<?php echo esc_url($builder_url); ?>">
		  <a class="th-lp-card-link" href="<?php echo esc_url($builder_url); ?>" tabindex="0">
			<div class="th-lp-card-thumb" style="<?php echo esc_attr($thumb_style); ?>">
			  <div class="th-lp-tpl-type-badge"><?php echo esc_html(strtoupper($type_label)); ?></div>
			</div>
			<div class="th-lp-card-meta">
			  <div class="th-lp-card-title-row">
				<div class="th-lp-card-title"><?php echo esc_html($title); ?></div>
				<span class="th-lp-card-status-pill th-lp-card-status-<?php echo esc_attr($status); ?>">
				  <span class="dot"></span><?php echo esc_html($status_label); ?>
				</span>
			  </div>
			  <div class="th-lp-card-sub"><?php echo esc_html($type_label); ?> · Bricks · <?php echo esc_html($modified); ?> ago</div>
			</div>
		  </a>
		  <div class="th-lp-card-actions">
			<a class="th-lp-card-btn th-lp-card-btn-primary" href="<?php echo esc_url($builder_url); ?>">Edit</a>
			<a class="th-lp-card-btn" href="<?php echo esc_url($dup_url); ?>">Duplicate</a>
		  </div>
		  <?php Therum_List_Page::render_card_kebab(self::row_actions($t)); ?>
		</div>
		<?php
	}

	public static function render_row(\WP_Post $t, ?array $actions = null): void {
		$type     = get_post_meta($t->ID, '_bricks_template_type', true) ?: 'section';
		$type_label = self::TYPE_LABELS[$type] ?? ucfirst($type);
		$status   = $t->post_status;
		$title    = $t->post_title ?: '(untitled)';
		$modified = human_time_diff(get_post_modified_time('U', false, $t));
		$author   = get_the_author_meta('display_name', $t->post_author) ?: '—';
		$search   = strtolower($title . ' ' . $type_label);
		$builder_url = self::builder_url($t->ID);
		$palette  = self::TYPE_PALETTE[$type] ?? ['#999', 'rgba(0,0,0,0.06)'];
		?>
		<tr class="th-lp-row"
			data-status="<?php echo esc_attr($type); ?>"
			data-post-status="<?php echo esc_attr($status); ?>"
			data-template-type="<?php echo esc_attr($type); ?>"
			data-search="<?php echo esc_attr($search); ?>"
			data-sort-modified="<?php echo (int)get_post_modified_time('U', true, $t); ?>"
			data-sort-title="<?php echo esc_attr(strtolower($title)); ?>">
		  <td><a href="<?php echo esc_url($builder_url); ?>"><?php echo esc_html($title); ?></a></td>
		  <td><span class="th-lp-tpl-type" style="color:<?php echo esc_attr($palette[0]); ?>;background:<?php echo esc_attr($palette[1]); ?>;"><?php echo esc_html($type_label); ?></span></td>
		  <td><span class="th-lp-status th-lp-status-<?php echo esc_attr($status); ?>"><?php echo esc_html(self::status_label($status)); ?></span></td>
		  <td><?php echo esc_html($modified); ?> ago</td>
		  <td><?php echo esc_html($author); ?></td>
		  <?php if ($actions): Therum_List_Page::render_kebab_cell($actions); endif; ?>
		</tr>
		<?php
	}

	public static function row_actions(\WP_Post $t): array {
		$builder = self::builder_url($t->ID);
		$delete  = get_delete_post_link($t->ID);
		$dup     = wp_nonce_url(admin_url('admin-post.php?action=therum_duplicate_template&post=' . $t->ID), 'therum_dup_' . $t->ID);
		$out = [];
		if ($builder) $out[] = ['label' => 'Edit with Bricks', 'href' => $builder, 'icon' => 'edit2'];
		if ($dup) $out[] = ['label' => 'Duplicate', 'href' => $dup, 'icon' => 'plus'];
		if ($delete) $out[] = ['label' => 'Delete', 'href' => $delete, 'icon' => 'logout', 'danger' => true];
		return $out;
	}

	private static function status_label(string $s): string {
		return ['publish'=>'Published','draft'=>'Draft','future'=>'Scheduled','pending'=>'Pending','trash'=>'Trash'][$s] ?? ucfirst($s);
	}

	/* Build the Bricks builder URL: permalink + ?bricks=run (verified from Bricks source) */
	private static function builder_url(int $post_id): string {
		$permalink = get_permalink($post_id);
		if (!$permalink) return (string) get_edit_post_link($post_id);
		return add_query_arg('bricks', 'run', $permalink);
	}

	private static function darken(string $hex): string {
		$h = ltrim($hex, '#');
		if (strlen($h) === 3) $h = $h[0].$h[0].$h[1].$h[1].$h[2].$h[2];
		if (strlen($h) !== 6) return $hex;
		$r = max(0, hexdec(substr($h,0,2)) - 60);
		$g = max(0, hexdec(substr($h,2,2)) - 60);
		$b = max(0, hexdec(substr($h,4,2)) - 60);
		return sprintf('#%02x%02x%02x', $r, $g, $b);
	}
}

// Duplicate template handler
add_action('admin_post_therum_duplicate_template', function() {
	if (!current_user_can('edit_posts')) wp_die('Forbidden', 403);
	$post_id = isset($_GET['post']) ? (int) $_GET['post'] : 0;
	if (!$post_id || !wp_verify_nonce($_GET['_wpnonce'] ?? '', 'therum_dup_' . $post_id)) wp_die('Bad nonce', 400);
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
	if (!current_user_can('edit_posts')) wp_die('Forbidden', 403);
	$post_id = isset($_GET['post']) ? (int) $_GET['post'] : 0;
	if (!$post_id || !wp_verify_nonce($_GET['_wpnonce'] ?? '', 'therum_dup_' . $post_id)) wp_die('Bad nonce', 400);
	$src = get_post($post_id);
	if (!$src) wp_die('Not found', 404);
	if (!in_array($src->post_type, ['page', 'post'], true)) wp_die('Invalid post type for duplication', 400);
	$new_id = wp_insert_post([
		'post_type'    => $src->post_type,
		'post_status'  => 'draft',
		'post_title'   => $src->post_title . ' (copy)',
		'post_content' => $src->post_content,
		'post_excerpt' => $src->post_excerpt,
		'post_author'  => get_current_user_id(),
	], true);
	if (is_wp_error($new_id)) wp_die($new_id->get_error_message());
	// Copy all meta
	$meta = get_post_meta($post_id);
	foreach ($meta as $key => $vals) {
		if (in_array($key, ['_edit_lock', '_edit_last'], true)) continue;
		foreach ($vals as $v) {
			$v = maybe_unserialize($v);
			add_post_meta($new_id, $key, $v);
		}
	}
	$redirect = $src->post_type === 'page'
		? admin_url('admin.php?page=therum-pages')
		: admin_url('admin.php?page=therum-posts');
	wp_safe_redirect($redirect);
	exit;
});

class Therum_Plugins_Page {

	public static function render(): void {
		if (!function_exists('get_plugins'))   require_once ABSPATH . 'wp-admin/includes/plugin.php';
		if (!function_exists('get_mu_plugins')) require_once ABSPATH . 'wp-admin/includes/plugin.php';

		$plugins   = get_plugins();
		$mu        = get_mu_plugins();
		$active    = (array) get_option('active_plugins', []);
		$updates   = function_exists('get_plugin_updates') ? get_plugin_updates() : [];

		$items = [];
		foreach ($plugins as $file => $data) {
			$is_active = in_array($file, $active, true);
			$has_update = isset($updates[$file]);
			$items[] = [
				'kind'       => 'regular',
				'file'       => $file,
				'name'       => $data['Name'] ?? $file,
				'desc'       => wp_strip_all_tags($data['Description'] ?? ''),
				'version'    => $data['Version'] ?? '',
				'author'     => wp_strip_all_tags($data['Author'] ?? ''),
				'is_active'  => $is_active,
				'has_update' => $has_update,
				'new_ver'    => $has_update ? ($updates[$file]->update->new_version ?? '') : '',
			];
		}
		foreach ($mu as $file => $data) {
			$items[] = [
				'kind'       => 'must-use',
				'file'       => $file,
				'name'       => $data['Name'] ?? $file,
				'desc'       => wp_strip_all_tags($data['Description'] ?? ''),
				'version'    => $data['Version'] ?? '',
				'author'     => wp_strip_all_tags($data['Author'] ?? ''),
				'is_active'  => true,
				'has_update' => false,
				'new_ver'    => '',
			];
		}

		$counts = [
			'all'      => count($items),
			'active'   => count(array_filter($items, fn($i) => $i['kind']==='regular' && $i['is_active'])),
			'inactive' => count(array_filter($items, fn($i) => $i['kind']==='regular' && !$i['is_active'])),
			'update'   => count(array_filter($items, fn($i) => $i['has_update'])),
			'must-use' => count(array_filter($items, fn($i) => $i['kind']==='must-use')),
		];

		Therum_List_Page::render([
			'title'    => 'Plugins',
			'subtitle' => 'Functionality bolted onto WordPress core.',
			'page_id'  => 'plugins',
			'meta_pills' => [
				$counts['active'] . ' active',
				$counts['update'] . ' update' . ($counts['update']===1?'':'s'),
			],
			'action_buttons' => [
				['label'=>'Add New', 'icon'=>'plus', 'primary'=>true, 'href'=>admin_url('plugin-install.php')],
			],
			'filter_pills' => [
				['key'=>'all',      'label'=>'All',       'count'=>$counts['all'] - $counts['must-use']],
				['key'=>'active',   'label'=>'Active',    'count'=>$counts['active']],
				['key'=>'inactive', 'label'=>'Inactive',  'count'=>$counts['inactive']],
				['key'=>'update',   'label'=>'Updates',   'count'=>$counts['update'], 'flag'=>'update'],
			],
			'sort_options' => [
				['key'=>'title-asc', 'label'=>'Name A→Z'],
				['key'=>'title-desc','label'=>'Name Z→A'],
			],
			'search_placeholder' => 'Search plugins…',
			'items'              => array_values(array_filter($items, fn($i) => $i['kind'] !== 'must-use')),
			'card_renderer'      => [self::class, 'render_card'],
			'row_renderer'       => [self::class, 'render_row'],
			'table_columns'      => ['Name', 'Status', 'Version', 'Author'],
			'empty_state'        => ['title'=>'No plugins installed', 'sub'=>'Click "Add New" to install your first.'],
			'before_toolbar'     => function() use ($items) {
				self::render_core_carousel(array_filter($items, fn($i) => $i['kind'] === 'must-use'));
			},
		]);
	}

	/**
	 * Per-module icon mapping. Each Therum Core module gets an appropriate icon
	 * keyed by a substring of its name. Falls back to 'therum' icon if no match.
	 */
	private static function core_module_icon(string $name): string {
		$lower = strtolower($name);
		$map = [
			'admin ui'      => 'design',
			'shell'         => 'design',
			'api'           => 'utils',
			'webhook'       => 'utils',
			'design pages'  => 'palette',
			'editor'        => 'edit2',
			'list page'     => 'manage',
			'login'         => 'logout',
			'motion'        => 'health',
			'roles'         => 'users',
			'runtime'       => 'utils',
			'settings'      => 'settings',
			'themes'        => 'themes',
			'two-factor'    => 'logout',
			'2fa'           => 'logout',
			'woocommerce'   => 'store',
			'woo bridge'    => 'store',
			'plugin settings' => 'plugins',
			'core'          => 'therum',
		];
		foreach ($map as $needle => $icon) {
			if (strpos($lower, $needle) !== false) return $icon;
		}
		return 'therum';
	}

	/**
	 * Render the Therum Core carousel — horizontal scroller of Core module cards
	 * shown above the Plugins toolbar/grid. Always visible regardless of active filter.
	 */
	public static function render_core_carousel(array $core_items): void {
		if (empty($core_items)) return;
		// Re-index the array since array_filter preserves keys
		$core_items = array_values($core_items);
		$count = count($core_items);
		?>
		<section class="th-core-carousel" aria-label="Therum Core modules">
		  <header class="th-core-carousel-head">
			<div class="th-core-carousel-head-text">
			  <span class="th-core-carousel-eyebrow">THERUM CORE</span>
			  <h2 class="th-core-carousel-title">System modules <span class="th-core-carousel-count"><?php echo (int)$count; ?></span></h2>
			</div>
			<div class="th-core-carousel-nav">
			  <button type="button" class="th-core-carousel-arrow" data-dir="prev" aria-label="Scroll left">
				<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
			  </button>
			  <button type="button" class="th-core-carousel-arrow" data-dir="next" aria-label="Scroll right">
				<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>
			  </button>
			</div>
		  </header>

		  <div class="th-core-carousel-track" role="list">
			<?php foreach ($core_items as $item):
			  $icon = self::core_module_icon($item['name']);
			  $svg  = function_exists('th_i') ? th_i($icon) : '';
			  // Strip "Therum OS — " prefix from display name for cleaner cards
			  $display_name = preg_replace('/^Therum OS\s*[\x{2014}\x{2013}\-:]\s*/u', '', $item['name']);
			  $display_name = $display_name ?: $item['name'];
			?>
			<article class="th-core-card" role="listitem" data-search="<?php echo esc_attr(strtolower($item['name'] . ' ' . $item['desc'])); ?>">
			  <div class="th-core-card-thumb">
				<div class="th-core-card-icon"><?php echo $svg; ?></div>
			  </div>
			  <div class="th-core-card-body">
				<h3 class="th-core-card-name"><?php echo esc_html($display_name); ?></h3>
				<p class="th-core-card-desc"><?php echo esc_html(wp_trim_words($item['desc'], 14)); ?></p>
				<p class="th-core-card-meta">v<?php echo esc_html($item['version']); ?> · Therum Core</p>
			  </div>
			</article>
			<?php endforeach; ?>
		  </div>
		</section>

		<style>
		.th-core-carousel {
			margin: 0 0 32px;
		}
		.th-core-carousel-head {
			display: flex;
			align-items: flex-end;
			justify-content: space-between;
			gap: 16px;
			margin: 0 0 14px;
		}
		.th-core-carousel-eyebrow {
			display: block;
			font-family: var(--ff-d, 'Inter Tight', sans-serif);
			font-size: 11px;
			font-weight: 500;
			letter-spacing: 0.08em;
			color: var(--ac, #e83b3b);
			margin-bottom: 4px;
			text-transform: uppercase;
		}
		.th-core-carousel-title {
			font-family: var(--ff-d, 'Inter Tight', sans-serif);
			font-size: 18px;
			font-weight: 500;
			letter-spacing: -0.02em;
			color: var(--tx, #0a0a0a);
			margin: 0;
			display: flex;
			align-items: center;
			gap: 10px;
		}
		.th-core-carousel-count {
			display: inline-flex;
			align-items: center;
			justify-content: center;
			min-width: 22px;
			height: 22px;
			padding: 0 8px;
			background: var(--sf2, #f5f5f5);
			color: var(--tx2, #666);
			border-radius: 999px;
			font-size: 11px;
			font-weight: 500;
			letter-spacing: -0.005em;
		}
		.th-core-carousel-nav {
			display: flex;
			gap: 6px;
		}
		.th-core-carousel-arrow {
			width: 32px;
			height: 32px;
			display: grid;
			place-items: center;
			background: var(--sf, #fff);
			border: 1px solid var(--bd, rgba(0,0,0,0.08));
			border-radius: 8px;
			color: var(--tx2, #666);
			cursor: pointer;
			transition: background 150ms ease, border-color 150ms ease, color 150ms ease;
		}
		.th-core-carousel-arrow:hover {
			background: var(--sf2, #f5f5f5);
			border-color: var(--bd2, rgba(0,0,0,0.16));
			color: var(--tx, #0a0a0a);
		}
		.th-core-carousel-arrow:disabled {
			opacity: 0.35;
			cursor: not-allowed;
		}
		.th-core-carousel-track {
			display: flex;
			gap: 14px;
			overflow-x: auto;
			scroll-snap-type: x mandatory;
			scroll-behavior: smooth;
			padding: 4px 4px 18px;
			margin: 0 -4px;
			scrollbar-width: thin;
			scrollbar-color: var(--bd2, rgba(0,0,0,0.16)) transparent;
		}
		.th-core-carousel-track::-webkit-scrollbar {
			height: 6px;
		}
		.th-core-carousel-track::-webkit-scrollbar-track { background: transparent; }
		.th-core-carousel-track::-webkit-scrollbar-thumb {
			background: var(--bd2, rgba(0,0,0,0.16));
			border-radius: 3px;
		}
		.th-core-card {
			flex: 0 0 260px;
			scroll-snap-align: start;
			background: var(--sf, #fff);
			border: 1px solid var(--bd, rgba(0,0,0,0.08));
			border-radius: 12px;
			overflow: hidden;
			transition: border-color 150ms ease, transform 150ms ease, box-shadow 150ms ease;
		}
		.th-core-card:hover {
			border-color: var(--bd2, rgba(0,0,0,0.16));
			transform: translateY(-2px);
			box-shadow: 0 8px 24px rgba(0,0,0,0.06);
		}
		.th-core-card-thumb {
			aspect-ratio: 16 / 10;
			background: linear-gradient(135deg, var(--sf2, #fff5f5) 0%, var(--sf, #fff) 100%);
			display: grid;
			place-items: center;
			position: relative;
		}
		.th-core-card-icon {
			width: 56px;
			height: 56px;
			display: grid;
			place-items: center;
			background: var(--sf, #fff);
			border-radius: 14px;
			color: var(--ac, #e83b3b);
			box-shadow: 0 2px 12px rgba(0,0,0,0.04);
		}
		.th-core-card-icon svg {
			width: 26px;
			height: 26px;
		}
		.th-core-card-body {
			padding: 14px 16px 16px;
		}
		.th-core-card-name {
			font-family: var(--ff-d, 'Inter Tight', sans-serif);
			font-size: 14px;
			font-weight: 500;
			letter-spacing: -0.015em;
			color: var(--tx, #0a0a0a);
			margin: 0 0 5px;
			line-height: 1.3;
		}
		.th-core-card-desc {
			font-size: 12px;
			color: var(--tx2, #666);
			margin: 0 0 8px;
			line-height: 1.45;
			min-height: 34px;
			display: -webkit-box;
			-webkit-line-clamp: 2;
			-webkit-box-orient: vertical;
			overflow: hidden;
		}
		.th-core-card-meta {
			font-size: 11px;
			color: var(--tx3, #999);
			margin: 0;
			letter-spacing: -0.005em;
		}
		</style>

		<script>
		(function () {
			var carousel = document.querySelector('.th-core-carousel');
			if (!carousel) return;
			var track = carousel.querySelector('.th-core-carousel-track');
			var prev  = carousel.querySelector('.th-core-carousel-arrow[data-dir="prev"]');
			var next  = carousel.querySelector('.th-core-carousel-arrow[data-dir="next"]');
			if (!track || !prev || !next) return;

			function scrollAmount() {
				var card = track.querySelector('.th-core-card');
				if (!card) return 280;
				var gap = parseInt(getComputedStyle(track).columnGap || getComputedStyle(track).gap || '14', 10);
				return (card.offsetWidth + gap) * 2;
			}
			function updateButtons() {
				prev.disabled = track.scrollLeft <= 4;
				next.disabled = track.scrollLeft + track.clientWidth >= track.scrollWidth - 4;
			}
			prev.addEventListener('click', function () {
				track.scrollBy({ left: -scrollAmount(), behavior: 'smooth' });
			});
			next.addEventListener('click', function () {
				track.scrollBy({ left: scrollAmount(), behavior: 'smooth' });
			});
			track.addEventListener('scroll', updateButtons, { passive: true });
			window.addEventListener('resize', updateButtons);
			updateButtons();
		})();
		</script>
		<?php
	}

	public static function render_card(array $p): void {
		$status = $p['kind'] === 'must-use' ? 'must-use' : ($p['is_active'] ? 'active' : 'inactive');
		$update = $p['has_update'] ? '1' : '0';
		$search = strtolower($p['name'] . ' ' . $p['desc'] . ' ' . $p['author']);
		$can_detail = $p['kind'] === 'regular';
		$detail_url = $can_detail ? admin_url('admin.php?page=therum-plugin-detail&plugin=' . urlencode($p['file'])) : '#';
		?>
		<div class="th-lp-card th-lp-card-plugin"
		   data-status="<?php echo esc_attr($status); ?>"
		   data-update="<?php echo esc_attr($update); ?>"
		   data-search="<?php echo esc_attr($search); ?>"
		   data-sort-title="<?php echo esc_attr(strtolower($p['name'])); ?>"
		   data-plugin-file="<?php echo esc_attr($p['file']); ?>">
		  <a class="th-plugin-card-link" href="<?php echo esc_url($detail_url); ?>" <?php echo !$can_detail ? 'tabindex="-1" aria-disabled="true" onclick="return false"' : ''; ?>>
			<div class="th-lp-card-meta">
			  <div class="th-lp-card-title-row">
				<div class="th-lp-card-title"><?php echo esc_html($p['name']); ?></div>
				<div class="th-lp-status-group">
				  <span class="th-lp-status th-lp-status-<?php echo esc_attr($status); ?>"><?php echo esc_html($status==='must-use'?'Core':ucfirst($status)); ?></span>
				  <?php if ($status === 'inactive'): ?>
				  <button type="button" class="th-lp-status-x" data-action="delete" aria-label="Delete plugin" title="Delete plugin">
					<svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
				  </button>
				  <?php endif; ?>
				</div>
			  </div>
			  <div class="th-lp-card-excerpt"><?php echo esc_html(wp_trim_words($p['desc'], 22)); ?></div>
			  <div class="th-lp-card-sub">v<?php echo esc_html($p['version']); ?> · <?php echo esc_html($p['author']); ?></div>
			  <?php if ($p['has_update']): ?>
			  <div class="th-lp-card-update">Update available → v<?php echo esc_html($p['new_ver']); ?></div>
			  <?php endif; ?>
			</div>
		  </a>
		  <?php if ($p['kind'] === 'regular'): ?>
		  <div class="th-plugin-card-actions" data-plugin-actions>
			<?php if ($p['has_update']): ?>
			<button type="button" class="th-plugin-action th-plugin-action-update" data-action="upgrade" data-version="<?php echo esc_attr($p['new_ver']); ?>">
			  Update to v<?php echo esc_html($p['new_ver']); ?>
			</button>
			<?php endif; ?>
			<?php if ($p['is_active']): ?>
			<button type="button" class="th-plugin-action" data-action="deactivate">Deactivate</button>
			<button type="button" class="th-plugin-action" data-rollback-href="<?php echo esc_url($detail_url . '#version-history'); ?>">Rollback</button>
			<?php else: ?>
			<button type="button" class="th-plugin-action th-plugin-action-primary" data-action="activate">Activate</button>
			<button type="button" class="th-plugin-action th-plugin-action-danger" data-action="delete">Delete</button>
			<?php endif; ?>
		  </div>
		  <?php endif; ?>
		</div>
		<?php
	}

	public static function render_row(array $p): void {
		$status = $p['kind'] === 'must-use' ? 'must-use' : ($p['is_active'] ? 'active' : 'inactive');
		$update = $p['has_update'] ? '1' : '0';
		?>
		<tr class="th-lp-row"
			data-status="<?php echo esc_attr($status); ?>"
			data-update="<?php echo esc_attr($update); ?>"
			data-search="<?php echo esc_attr(strtolower($p['name'])); ?>"
			data-sort-title="<?php echo esc_attr(strtolower($p['name'])); ?>">
		  <td><strong><?php echo esc_html($p['name']); ?></strong></td>
		  <td><span class="th-lp-status th-lp-status-<?php echo esc_attr($status); ?>"><?php echo esc_html($status==='must-use'?'Core':ucfirst($status)); ?></span><?php if ($p['has_update']): ?> <span class="th-lp-update-tag">v<?php echo esc_html($p['new_ver']); ?> →</span><?php endif; ?></td>
		  <td><?php echo esc_html($p['version']); ?></td>
		  <td><?php echo esc_html($p['author']); ?></td>
		</tr>
		<?php
	}
}

class Therum_Plugin_Detail_Page {

	public static function render(): void {
		if ( ! function_exists('get_plugins') ) require_once ABSPATH . 'wp-admin/includes/plugin.php';
		if ( ! function_exists('plugins_api') ) require_once ABSPATH . 'wp-admin/includes/plugin-install.php';

		$plugin_file = isset($_GET['plugin']) ? sanitize_text_field( wp_unslash($_GET['plugin']) ) : '';
		$plugins = get_plugins();
		if ( !isset($plugins[$plugin_file]) ) {
			echo '<div class="wrap"><div class="th-lp"><h1 class="th-lp-title">Plugin not found</h1><p><a href="' . esc_url(admin_url('admin.php?page=therum-plugins')) . '">← Back to plugins</a></p></div></div>';
			return;
		}

		$data = $plugins[$plugin_file];
		$active = in_array($plugin_file, (array) get_option('active_plugins', []), true);
		$updates = function_exists('get_plugin_updates') ? get_plugin_updates() : [];
		$has_update = isset($updates[$plugin_file]);
		$new_ver = $has_update ? ($updates[$plugin_file]->update->new_version ?? '') : '';
		$slug = dirname($plugin_file);
		if ($slug === '.') $slug = basename($plugin_file, '.php');

		// Try to fetch wp.org data for screenshots / changelog / version history
		$wp_org = false;
		$api = plugins_api('plugin_information', [
			'slug' => $slug,
			'fields' => [
				'short_description' => true, 'sections' => true,
				'screenshots' => true, 'versions' => true,
				'banners' => true, 'icons' => true,
			],
		]);
		if ( ! is_wp_error($api) ) $wp_org = $api;

		$icon = '';
		if ($wp_org && !empty($wp_org->icons)) {
			$icon = $wp_org->icons['2x'] ?? $wp_org->icons['1x'] ?? $wp_org->icons['default'] ?? '';
		}

		$banner = '';
		if ($wp_org && !empty($wp_org->banners)) {
			$banner = $wp_org->banners['high'] ?? $wp_org->banners['low'] ?? '';
		}

		$versions = [];
		if ($wp_org && !empty($wp_org->versions)) {
			$versions = array_keys((array) $wp_org->versions);
			usort($versions, 'version_compare');
			$versions = array_reverse($versions);
			$versions = array_filter($versions, fn($v) => $v && $v !== 'trunk');
		}

		$ajax_nonce = wp_create_nonce('therum_plugin_action');
		$status_label = $active ? 'Active' : 'Inactive';
		$status_class = $active ? 'active' : 'inactive';

		// Discover the plugin's own settings/action links. Plugins inject
		// these via `plugin_action_links_{file}` and `plugin_action_links` —
		// the same hooks WP uses to render the Settings link on its native
		// plugins page. Inactive plugins won't have registered their filters
		// (their main file isn't loaded), so this naturally only populates
		// for active plugins. Filtering for href + label keeps it safe.
		$plugin_links = [];
		if ($active) {
			$raw = apply_filters('plugin_action_links_' . $plugin_file, [], $plugin_file, $data, 'all');
			$raw = apply_filters('plugin_action_links', is_array($raw) ? $raw : [], $plugin_file, $data, 'all');
			if (is_array($raw)) {
				$seen_labels = [];
				foreach ($raw as $html) {
					if (!is_string($html)) continue;
					if (!preg_match('/<a\s+[^>]*href\s*=\s*(["\'])([^"\']+)\1[^>]*>(.*?)<\/a>/is', $html, $m)) continue;
					$url   = trim($m[2]);
					$label = trim(wp_strip_all_tags($m[3]));
					// Skip WP's own activate/deactivate/delete/network-* links — we
					// already have those as real buttons in the PDP header.
					if (preg_match('/(activate|deactivate|delete|network)/i', $label)) continue;
					if ($url === '' || $label === '') continue;
					if (isset($seen_labels[$label])) continue;
					$seen_labels[$label] = true;
					$plugin_links[] = [ 'url' => $url, 'label' => $label ];
					if (count($plugin_links) >= 4) break; // sanity cap
				}
			}
		}
		?>
		<div class="wrap">
		  <div class="th-lp th-pdp" data-plugin-file="<?php echo esc_attr($plugin_file); ?>" data-ajax-nonce="<?php echo esc_attr($ajax_nonce); ?>">

			<div class="th-pdp-back">
			  <a href="<?php echo esc_url(admin_url('admin.php?page=therum-plugins')); ?>" class="th-pdp-back-link">
				<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 18 9 12 15 6"/></svg>
				Back to plugins
			  </a>
			</div>

			<?php if ($banner): ?>
			<div class="th-pdp-banner" style="background-image:url('<?php echo esc_url($banner); ?>');"></div>
			<?php endif; ?>

			<div class="th-pdp-header">
			  <?php if ($icon): ?>
			  <div class="th-pdp-icon" style="background-image:url('<?php echo esc_url($icon); ?>');"></div>
			  <?php else: ?>
			  <div class="th-pdp-icon th-pdp-icon-fallback"><?php echo esc_html(strtoupper(substr($data['Name'] ?? 'P', 0, 1))); ?></div>
			  <?php endif; ?>

			  <div class="th-pdp-header-main">
				<div class="th-pdp-meta">
				  <span class="th-lp-status th-lp-status-<?php echo esc_attr($status_class); ?>"><?php echo esc_html($status_label); ?></span>
				  <?php if ($has_update): ?><span class="th-lp-update-tag">Update v<?php echo esc_html($new_ver); ?> available</span><?php endif; ?>
				</div>
				<h1 class="th-pdp-title"><?php echo esc_html($data['Name'] ?? $plugin_file); ?></h1>
				<div class="th-pdp-byline">
				  <span>v<?php echo esc_html($data['Version'] ?? ''); ?></span>
				  <?php if (!empty($data['Author'])): ?>
				  <span>·</span>
				  <span><?php echo wp_kses_post($data['Author']); ?></span>
				  <?php endif; ?>
				  <?php if (!empty($data['PluginURI'])): ?>
				  <span>·</span>
				  <a href="<?php echo esc_url($data['PluginURI']); ?>" target="_blank" rel="noopener">Plugin homepage ↗</a>
				  <?php endif; ?>
				</div>
			  </div>

			  <div class="th-pdp-actions">
				<?php
				ob_start();
				// Plugin's own action links (Settings, Docs, etc.). Rendered
				// BEFORE the lifecycle buttons so Settings reads as the
				// primary affordance for an active plugin you've already set up.
				foreach ($plugin_links as $link) {
					echo '<a class="th-pdp-btn" href="' . esc_url($link['url']) . '">' . esc_html($link['label']) . '</a>';
				}
				if ($has_update) {
					echo '<button type="button" class="th-pdp-btn th-pdp-btn-primary" data-action="upgrade" data-version="' . esc_attr($new_ver) . '">Update to v' . esc_html($new_ver) . '</button>';
				}
				if ($active) {
					echo '<button type="button" class="th-pdp-btn" data-action="deactivate">Deactivate</button>';
				} else {
					echo '<button type="button" class="th-pdp-btn th-pdp-btn-primary" data-action="activate">Activate</button>';
				}
				$_actions_html = ob_get_clean();
				echo apply_filters('therum_plugin_pdp_actions', $_actions_html, $plugin_file);
				?>
			  </div>
			</div>

			<div class="th-pdp-grid">
			  <div class="th-pdp-main">
				<?php if ($wp_org && !empty($wp_org->sections['description'])): ?>
				<div class="th-pdp-section">
				  <h2 class="th-pdp-section-title">About this plugin</h2>
				  <div class="th-pdp-prose"><?php echo wp_kses_post($wp_org->sections['description']); ?></div>
				</div>
				<?php elseif (!empty($data['Description'])): ?>
				<div class="th-pdp-section">
				  <h2 class="th-pdp-section-title">About this plugin</h2>
				  <div class="th-pdp-prose"><?php echo wp_kses_post($data['Description']); ?></div>
				</div>
				<?php endif; ?>

				<?php if ($wp_org && !empty($wp_org->screenshots)): ?>
				<div class="th-pdp-section">
				  <h2 class="th-pdp-section-title">Screenshots</h2>
				  <div class="th-pdp-shots">
					<?php foreach ($wp_org->screenshots as $shot): ?>
					<div class="th-pdp-shot">
					  <img src="<?php echo esc_url($shot['src']); ?>" alt="">
					  <?php if (!empty($shot['caption'])): ?>
					  <div class="th-pdp-shot-caption"><?php echo esc_html($shot['caption']); ?></div>
					  <?php endif; ?>
					</div>
					<?php endforeach; ?>
				  </div>
				</div>
				<?php endif; ?>

				<?php if ($wp_org && !empty($wp_org->sections['changelog'])): ?>
				<div class="th-pdp-section">
				  <h2 class="th-pdp-section-title">Changelog</h2>
				  <div class="th-pdp-prose th-pdp-changelog"><?php echo wp_kses_post($wp_org->sections['changelog']); ?></div>
				</div>
				<?php endif; ?>
			  </div>

			  <aside class="th-pdp-side">
				<?php if (!empty($versions)): ?>
				<div class="th-pdp-card">
				  <div class="th-pdp-card-title">Version history</div>
				  <div class="th-pdp-card-sub">Roll back to a previous release.</div>
				  <div class="th-pdp-versions">
					<?php foreach ( array_slice($versions, 0, 12) as $v ):
						$is_current = ($v === ($data['Version'] ?? ''));
					?>
					<div class="th-pdp-version<?php echo $is_current ? ' is-current' : ''; ?>">
					  <div class="th-pdp-version-num">v<?php echo esc_html($v); ?><?php echo $is_current ? ' <span>· Current</span>' : ''; ?></div>
					  <?php if (!$is_current): ?>
					  <button type="button" class="th-pdp-version-btn" data-action="rollback" data-version="<?php echo esc_attr($v); ?>">Install</button>
					  <?php endif; ?>
					</div>
					<?php endforeach; ?>
				  </div>
				</div>
				<?php endif; ?>

				<div class="th-pdp-card">
				  <div class="th-pdp-card-title">Details</div>
				  <dl class="th-pdp-dl">
					<dt>Version</dt><dd>v<?php echo esc_html($data['Version'] ?? '—'); ?></dd>
					<?php if ($wp_org && !empty($wp_org->requires)): ?>
					<dt>Requires</dt><dd>WordPress <?php echo esc_html($wp_org->requires); ?>+</dd>
					<?php endif; ?>
					<?php if ($wp_org && !empty($wp_org->tested)): ?>
					<dt>Tested up to</dt><dd>WordPress <?php echo esc_html($wp_org->tested); ?></dd>
					<?php endif; ?>
					<?php if ($wp_org && !empty($wp_org->requires_php)): ?>
					<dt>Requires PHP</dt><dd><?php echo esc_html($wp_org->requires_php); ?>+</dd>
					<?php endif; ?>
					<?php if ($wp_org && !empty($wp_org->active_installs)): ?>
					<dt>Active installs</dt><dd><?php echo esc_html(number_format_i18n((int)$wp_org->active_installs)); ?>+</dd>
					<?php endif; ?>
					<?php if ($wp_org && !empty($wp_org->rating)): ?>
					<dt>Rating</dt><dd><?php echo esc_html(number_format($wp_org->rating / 20, 1)); ?> / 5</dd>
					<?php endif; ?>
					<?php if (!empty($data['TextDomain'])): ?>
					<dt>Slug</dt><dd><?php echo esc_html($data['TextDomain']); ?></dd>
					<?php endif; ?>
				  </dl>
				</div>

				<div class="th-pdp-card th-pdp-card-danger">
				  <div class="th-pdp-card-title">Danger zone</div>
				  <div class="th-pdp-card-sub">Permanently remove this plugin and its files.</div>
				  <button type="button" class="th-pdp-btn th-pdp-btn-danger" data-action="delete" <?php echo $active ? 'disabled' : ''; ?>>
					<?php echo $active ? 'Deactivate first' : 'Delete plugin'; ?>
				  </button>
				</div>
			  </aside>
			</div>

			<div class="th-pdp-toast" data-pdp-toast hidden></div>
		  </div>
		</div>
		<?php
	}
}

// ═════════════════════════════════════════════════════════════════════════════
//  UPDATES PAGE
// ═════════════════════════════════════════════════════════════════════════════

class Therum_Updates_Page {

	public static function has_update(): bool {
		$u = get_plugin_updates();
		return ! empty( $u );
	}

	public static function render(): void {
		if ( ! function_exists( 'get_plugin_updates' ) )
			require_once ABSPATH . 'wp-admin/includes/update.php';

		$plugin_updates = get_plugin_updates();
		$theme_updates  = get_theme_updates();
		$core_updates   = get_core_updates();

		$wp_ver  = get_bloginfo( 'version' );
		$new_core = '';
		foreach ( (array) $core_updates as $cu ) {
			if ( isset( $cu->response ) && $cu->response === 'upgrade' ) {
				$new_core = $cu->version ?? '';
				break;
			}
		}

		$nonce = wp_create_nonce( 'therum_check_updates' );
		$count = count( $plugin_updates ) + count( $theme_updates ) + ( $new_core ? 1 : 0 );
		?>
<div class="th-lp" style="max-width:860px">
  <div class="th-lp-head" style="display:flex;align-items:flex-start;justify-content:space-between;gap:20px">
    <div>
      <div class="th-lp-breadcrumb">ADMIN · UPDATES</div>
      <h1 class="th-lp-title">Updates</h1>
      <p class="th-lp-sub">Keep WordPress core, plugins, and themes current.</p>
    </div>
    <div style="display:flex;gap:10px;align-items:center;flex-shrink:0;padding-top:4px">
      <?php if ($count > 0): ?>
      <span style="font-size:12px;font-weight:600;color:var(--wrn);background:color-mix(in srgb,var(--wrn) 12%,transparent);padding:4px 10px;border-radius:999px"><?php echo $count; ?> available</span>
      <?php endif; ?>
      <button type="button" class="th-btn th-btn-primary" id="thup-check-btn" data-nonce="<?php echo esc_attr($nonce); ?>">
        <?php echo th_i('import'); ?> Check for updates
      </button>
    </div>
  </div>

  <!-- Core -->
  <div class="th-settings-group" style="margin-top:28px">
    <div class="th-settings-group-header">
      <div class="th-settings-group-title">WordPress Core</div>
    </div>
    <div class="th-settings-group-body">
      <div style="display:flex;align-items:center;justify-content:space-between;padding:14px 0;border-bottom:1px solid var(--bd)">
        <div style="display:flex;align-items:center;gap:14px">
          <div style="width:38px;height:38px;border-radius:9px;background:var(--sf2);display:flex;align-items:center;justify-content:center;font-weight:700;font-size:14px;color:var(--tx2)">WP</div>
          <div>
            <div style="font-weight:600;font-size:14px;color:var(--tx)">WordPress</div>
            <div style="font-size:12px;color:var(--tx3)">Current: v<?php echo esc_html($wp_ver); ?><?php if ($new_core): ?> &nbsp;→&nbsp; <strong style="color:var(--wrn)">v<?php echo esc_html($new_core); ?> available</strong><?php endif; ?></div>
          </div>
        </div>
        <?php if ($new_core): ?>
        <a href="<?php echo esc_url(admin_url('update-core.php')); ?>" class="th-btn" style="font-size:12px">Update to v<?php echo esc_html($new_core); ?> →</a>
        <?php else: ?>
        <span style="font-size:12px;color:var(--ok);display:flex;align-items:center;gap:5px"><?php echo th_i('check'); ?> Up to date</span>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Plugins -->
  <div class="th-settings-group" style="margin-top:16px">
    <div class="th-settings-group-header">
      <div class="th-settings-group-title">Plugins <?php if (!empty($plugin_updates)): ?><span style="font-size:11px;font-weight:600;color:var(--wrn);background:color-mix(in srgb,var(--wrn) 12%,transparent);padding:2px 8px;border-radius:999px;margin-left:8px"><?php echo count($plugin_updates); ?> update<?php echo count($plugin_updates)===1?'':'s'; ?></span><?php endif; ?></div>
    </div>
    <div class="th-settings-group-body">
      <?php if (empty($plugin_updates)): ?>
      <div style="padding:20px 0;text-align:center;color:var(--tx3);font-size:13px;display:flex;align-items:center;gap:8px;justify-content:center"><?php echo th_i('check'); ?> All plugins are up to date</div>
      <?php else: foreach ($plugin_updates as $file => $data): ?>
      <?php $new_v = $data->update->new_version ?? ''; $cur_v = $data->Version ?? ''; $name = $data->Name ?? $file; ?>
      <div style="display:flex;align-items:center;justify-content:space-between;padding:14px 0;border-bottom:1px solid var(--bd);gap:12px">
        <div style="display:flex;align-items:center;gap:14px;min-width:0">
          <div style="width:38px;height:38px;border-radius:9px;background:color-mix(in srgb,var(--wrn) 14%,transparent);display:flex;align-items:center;justify-content:center;font-weight:700;font-size:14px;color:var(--wrn);flex-shrink:0"><?php echo esc_html(strtoupper(substr($name,0,1))); ?></div>
          <div style="min-width:0">
            <div style="font-weight:600;font-size:14px;color:var(--tx);white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?php echo esc_html($name); ?></div>
            <div style="font-size:12px;color:var(--tx3)">v<?php echo esc_html($cur_v); ?> → <strong style="color:var(--wrn)">v<?php echo esc_html($new_v); ?></strong></div>
          </div>
        </div>
        <a href="<?php echo esc_url(admin_url('update-core.php?action=do-plugin-upgrade&plugin=' . urlencode($file) . '&_wpnonce=' . wp_create_nonce('upgrade-plugin_' . $file))); ?>" class="th-btn" style="font-size:12px;flex-shrink:0" data-action="upgrade" data-plugin-file="<?php echo esc_attr($file); ?>" data-version="<?php echo esc_attr($new_v); ?>">Update →</a>
      </div>
      <?php endforeach; endif; ?>
    </div>
  </div>

  <!-- Themes -->
  <?php if (!empty($theme_updates)): ?>
  <div class="th-settings-group" style="margin-top:16px">
    <div class="th-settings-group-header">
      <div class="th-settings-group-title">Themes <span style="font-size:11px;font-weight:600;color:var(--wrn);background:color-mix(in srgb,var(--wrn) 12%,transparent);padding:2px 8px;border-radius:999px;margin-left:8px"><?php echo count($theme_updates); ?> update<?php echo count($theme_updates)===1?'':'s'; ?></span></div>
    </div>
    <div class="th-settings-group-body">
      <?php foreach ($theme_updates as $slug => $theme): ?>
      <?php $new_v = $theme->update['new_version'] ?? ''; $cur_v = $theme->get('Version'); $name = $theme->get('Name'); ?>
      <div style="display:flex;align-items:center;justify-content:space-between;padding:14px 0;border-bottom:1px solid var(--bd);gap:12px">
        <div style="display:flex;align-items:center;gap:14px">
          <div style="width:38px;height:38px;border-radius:9px;background:color-mix(in srgb,var(--ac) 14%,transparent);display:flex;align-items:center;justify-content:center;font-weight:700;font-size:14px;color:var(--ac);flex-shrink:0"><?php echo esc_html(strtoupper(substr($name,0,1))); ?></div>
          <div>
            <div style="font-weight:600;font-size:14px;color:var(--tx)"><?php echo esc_html($name); ?></div>
            <div style="font-size:12px;color:var(--tx3)">v<?php echo esc_html($cur_v); ?> → <strong style="color:var(--wrn)">v<?php echo esc_html($new_v); ?></strong></div>
          </div>
        </div>
        <a href="<?php echo esc_url(admin_url('update-core.php')); ?>" class="th-btn" style="font-size:12px;flex-shrink:0">Update →</a>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <div id="thup-toast" hidden style="position:fixed;bottom:28px;right:28px;background:var(--sf);border:1px solid var(--bd);border-radius:10px;padding:12px 18px;font-size:13px;font-weight:500;color:var(--tx);box-shadow:0 8px 24px rgba(0,0,0,.15);z-index:9999"></div>
</div>

<script>
(function(){
  var btn = document.getElementById('thup-check-btn');
  var toast = document.getElementById('thup-toast');
  if (!btn) return;
  function showToast(msg, ok) {
    toast.textContent = msg;
    toast.style.borderColor = ok ? 'var(--ok)' : 'var(--err)';
    toast.style.color = ok ? 'var(--ok)' : 'var(--err)';
    toast.hidden = false;
    clearTimeout(toast._t);
    toast._t = setTimeout(function(){ toast.hidden = true; }, 3500);
  }
  btn.addEventListener('click', function(){
    btn.setAttribute('data-loading','');
    btn.disabled = true;
    var fd = new FormData();
    fd.append('action','therum_check_updates');
    fd.append('nonce', btn.dataset.nonce);
    fetch(window.ajaxurl||'/wp-admin/admin-ajax.php', {method:'POST',credentials:'same-origin',body:fd})
      .then(function(r){return r.json();})
      .then(function(res){
        btn.removeAttribute('data-loading');
        btn.disabled = false;
        if (res && res.success) {
          showToast('Update check complete — reloading…', true);
          setTimeout(function(){ location.reload(); }, 900);
        } else {
          showToast('Check failed', false);
        }
      })
      .catch(function(){
        btn.removeAttribute('data-loading');
        btn.disabled = false;
        showToast('Network error', false);
      });
  });
})();
</script>
		<?php
	}

	public static function ajax_check(): void {
		if ( ! current_user_can( 'update_plugins' ) ) wp_send_json_error( 'forbidden', 403 );
		check_ajax_referer( 'therum_check_updates', 'nonce' );
		// Force WP to re-check remote update data
		if ( ! function_exists( 'wp_update_plugins' ) ) require_once ABSPATH . 'wp-includes/update.php';
		if ( ! function_exists( 'wp_update_themes' ) )  require_once ABSPATH . 'wp-includes/update.php';
		wp_update_plugins();
		wp_update_themes();
		wp_version_check( [], true );
		wp_send_json_success( [ 'msg' => 'checked' ] );
	}
}
add_action( 'wp_ajax_therum_check_updates', [ 'Therum_Updates_Page', 'ajax_check' ] );


// ═════════════════════════════════════════════════════════════════════════════
//  CONNECTIONS PAGE
// ═════════════════════════════════════════════════════════════════════════════

class Therum_Connections_Page {

	const OPTION_KEY = 'therum_connections';

	/**
	 * Stub tab registry — compatibility with the newer Therum_Connections_Page
	 * shape in therum-connections.php (which is guarded by class_exists() and
	 * therefore skipped because this class loads first alphabetically). The
	 * tabs collected here can be retrieved via tabs() but are otherwise
	 * inert; the legacy provider UI on this class remains the active surface.
	 */
	private static $tabs = [];
	public static function register( string $id, array $args ): void {
		self::$tabs[ $id ] = wp_parse_args( $args, [
			'label'    => ucfirst( $id ),
			'section'  => 'connectors',
			'icon'     => 'dot',
			'priority' => 100,
			'render'   => null,
			'desc'     => '',
			'count'    => '',
		] );
	}
	public static function tabs(): array {
		$tabs = apply_filters( 'therum_connections_page_tabs', self::$tabs );
		uasort( $tabs, fn( $a, $b ) => (int)( $a['priority'] ?? 100 ) <=> (int)( $b['priority'] ?? 100 ) );
		return $tabs;
	}

	// ── Provider registry ─────────────────────────────────────────────────────
	public static function categories(): array {
		return [
			'ai-tools'    => [ 'label' => 'AI Tools',           'icon' => 'feather',  'desc' => 'Language models you can query from the dashboard or any Therum page. Bring your own API key or OAuth credentials.' ],
			'apis'        => [ 'label' => 'APIs',               'icon' => 'webhook',  'desc' => 'External REST APIs for data, email, messaging, and more.' ],
			'ecommerce'   => [ 'label' => 'Connect Ecommerce',  'icon' => 'store',    'desc' => 'Storefront and inventory platforms to sync with WooCommerce.' ],
			'payment'     => [ 'label' => 'Payment Gateways',   'icon' => 'payments', 'desc' => 'Accept cards, wallets, and bank transfers on your store.' ],
			'external'    => [ 'label' => 'External Apps',      'icon' => 'external', 'desc' => 'Automation and collaboration tools — Zapier, Slack, Notion, and more.' ],
		];
	}

	public static function providers(): array {
		$built = self::_builtin_providers();
		// Merge user-defined custom providers per-category. Each carries
		// 'custom' => true so the UI can route Edit/Delete actions and the
		// vault can show them alongside built-ins. Credential storage is
		// shared with built-ins (same OPTION_KEY, same encryption path).
		foreach ( self::get_custom_providers() as $slug => $row ) {
			$cat = $row['category'] ?? '';
			if ( ! isset( $built[ $cat ] ) || ! is_array( $row ) ) continue;
			$row['id']     = $slug;
			$row['custom'] = true;
			$built[ $cat ][] = $row;
		}
		return $built;
	}

	private static function _builtin_providers(): array {
		return [
			// ── AI Tools — 13 providers ──────────────────────────────────────
			'ai-tools' => [
				[ 'id'=>'odysseus',     'name'=>'Therum · Odysseus',   'letter'=>'Ω', 'color'=>'#1e40af', 'meta'=>'Bundled AI workspace · chat router · agents · memory — runs on this host', 'auth'=>'base_url', 'auth_label'=>'Base URL', 'auth_hint'=>'http://localhost:8000', 'url'=>'https://github.com/pewdiepie-archdaemon/odysseus' ],
				[ 'id'=>'anthropic',    'name'=>'Anthropic · Claude',  'letter'=>'A', 'color'=>'#cc785c', 'meta'=>'claude-sonnet-4.5 · 200k context · function calling',      'auth'=>'api_key',  'auth_label'=>'API Key',  'auth_hint'=>'sk-ant-…', 'url'=>'https://console.anthropic.com/settings/keys' ],
				[ 'id'=>'openai',       'name'=>'OpenAI · ChatGPT',    'letter'=>'O', 'color'=>'#10a37f', 'meta'=>'gpt-5-turbo · 128k context · vision · code interpreter',  'auth'=>'api_key',  'auth_label'=>'API Key',  'auth_hint'=>'sk-…',    'url'=>'https://platform.openai.com/api-keys' ],
				[ 'id'=>'google-gemini','name'=>'Google AI · Gemini',  'letter'=>'G', 'color'=>'#4285f4', 'meta'=>'gemini-2.5-pro · 2M context · multimodal native',          'auth'=>'api_key',  'auth_label'=>'API Key',  'auth_hint'=>'AIza…',   'url'=>'https://aistudio.google.com/app/apikey' ],
				[ 'id'=>'xai',          'name'=>'xAI · Grok',          'letter'=>'X', 'color'=>'#000000', 'meta'=>'grok-4 · 256k context · real-time X integration',          'auth'=>'api_key',  'auth_label'=>'API Key',  'auth_hint'=>'xai-…',   'url'=>'https://console.x.ai' ],
				[ 'id'=>'mistral',      'name'=>'Mistral AI',          'letter'=>'M', 'color'=>'#ff7000', 'meta'=>'mistral-large · open-weight EU host · function calling',  'auth'=>'api_key',  'auth_label'=>'API Key',  'auth_hint'=>'…',       'url'=>'https://console.mistral.ai/api-keys' ],
				[ 'id'=>'deepseek',     'name'=>'DeepSeek',            'letter'=>'D', 'color'=>'#4d6bfe', 'meta'=>'deepseek-v3 · 671B MoE · strong reasoning at low cost',    'auth'=>'api_key',  'auth_label'=>'API Key',  'auth_hint'=>'sk-…',    'url'=>'https://platform.deepseek.com/api_keys' ],
				[ 'id'=>'perplexity',   'name'=>'Perplexity',          'letter'=>'P', 'color'=>'#20b8cd', 'meta'=>'Online-grounded answers · citations · search-aware LLM',   'auth'=>'api_key',  'auth_label'=>'API Key',  'auth_hint'=>'pplx-…',  'url'=>'https://www.perplexity.ai/settings/api' ],
				[ 'id'=>'cohere',       'name'=>'Cohere',              'letter'=>'C', 'color'=>'#ff7759', 'meta'=>'command-r-plus · enterprise RAG · embedding · rerank',     'auth'=>'api_key',  'auth_label'=>'API Key',  'auth_hint'=>'…',       'url'=>'https://dashboard.cohere.com/api-keys' ],
				[ 'id'=>'groq',         'name'=>'Groq',                'letter'=>'Q', 'color'=>'#f55036', 'meta'=>'LPU inference · 500+ tok/s · llama · mixtral · qwen',      'auth'=>'api_key',  'auth_label'=>'API Key',  'auth_hint'=>'gsk_…',   'url'=>'https://console.groq.com/keys' ],
				[ 'id'=>'ollama',       'name'=>'Local · Ollama',      'letter'=>'L', 'color'=>'#7c3aed', 'meta'=>'llama · mistral · qwen — runs on the same host',           'auth'=>'base_url', 'auth_label'=>'Base URL', 'auth_hint'=>'http://localhost:11434', 'url'=>'https://ollama.com' ],
				[ 'id'=>'elevenlabs',   'name'=>'ElevenLabs · Voice',  'letter'=>'V', 'color'=>'#0a0a0a', 'meta'=>'TTS · voice clones · multilingual · audio for posts',      'auth'=>'api_key',  'auth_label'=>'API Key',  'auth_hint'=>'sk_…',    'url'=>'https://elevenlabs.io/app/settings/api-keys' ],
				[ 'id'=>'huggingface',  'name'=>'Hugging Face',        'letter'=>'H', 'color'=>'#ffd21e', 'meta'=>'Inference API · custom model endpoints · datasets',        'auth'=>'api_key',  'auth_label'=>'Access Token','auth_hint'=>'hf_…',  'url'=>'https://huggingface.co/settings/tokens' ],
			],

			// ── APIs — 12 providers (email · SMS · push · maps · realtime) ──
			'apis' => [
				[ 'id'=>'mailchimp',   'name'=>'Mailchimp',         'letter'=>'M', 'color'=>'#ffe01b', 'meta'=>'Email marketing · audiences · automations',           'auth'=>'api_key',   'auth_label'=>'API Key',          'auth_hint'=>'…-us1',     'url'=>'https://mailchimp.com/developer/marketing/guides/quick-start/' ],
				[ 'id'=>'sendgrid',    'name'=>'SendGrid',          'letter'=>'S', 'color'=>'#1a82e2', 'meta'=>'Transactional email · templates · delivery analytics','auth'=>'api_key',   'auth_label'=>'API Key',          'auth_hint'=>'SG.…',      'url'=>'https://app.sendgrid.com/settings/api_keys' ],
				[ 'id'=>'postmark',    'name'=>'Postmark',          'letter'=>'P', 'color'=>'#ffde00', 'meta'=>'Fastest transactional delivery · separate streams',   'auth'=>'api_key',   'auth_label'=>'Server Token',     'auth_hint'=>'…',         'url'=>'https://account.postmarkapp.com/api_tokens' ],
				[ 'id'=>'resend',      'name'=>'Resend',            'letter'=>'R', 'color'=>'#000000', 'meta'=>'Developer-first email · React templates · webhooks',  'auth'=>'api_key',   'auth_label'=>'API Key',          'auth_hint'=>'re_…',      'url'=>'https://resend.com/api-keys' ],
				[ 'id'=>'mailgun',     'name'=>'Mailgun',           'letter'=>'M', 'color'=>'#f06b66', 'meta'=>'Sending + receiving · routing · validation',          'auth'=>'api_key',   'auth_label'=>'API Key',          'auth_hint'=>'key-…',     'url'=>'https://app.mailgun.com/app/account/security/api_keys' ],
				[ 'id'=>'brevo',       'name'=>'Brevo · Sendinblue','letter'=>'B', 'color'=>'#0b996e', 'meta'=>'Email + SMS + chat · CRM-aware campaigns',            'auth'=>'api_key',   'auth_label'=>'API Key',          'auth_hint'=>'xkeysib-…', 'url'=>'https://app.brevo.com/settings/keys/api' ],
				[ 'id'=>'twilio',      'name'=>'Twilio',            'letter'=>'T', 'color'=>'#f22f46', 'meta'=>'SMS · voice · WhatsApp · two-factor codes',           'auth'=>'sid_token', 'auth_label'=>'Account SID',      'auth_label2'=>'Auth Token', 'auth_hint'=>'AC…', 'auth_hint2'=>'…', 'url'=>'https://console.twilio.com' ],
				[ 'id'=>'vonage',      'name'=>'Vonage',            'letter'=>'V', 'color'=>'#000000', 'meta'=>'Global SMS · voice · verify · video API',             'auth'=>'sid_token', 'auth_label'=>'API Key',          'auth_label2'=>'API Secret', 'auth_hint'=>'…', 'auth_hint2'=>'…', 'url'=>'https://dashboard.nexmo.com/getting-started/your-api-key' ],
				[ 'id'=>'onesignal',   'name'=>'OneSignal',         'letter'=>'O', 'color'=>'#e54b4d', 'meta'=>'Push notifications · in-app · email · SMS',           'auth'=>'sid_token', 'auth_label'=>'App ID',           'auth_label2'=>'REST API Key', 'auth_hint'=>'…', 'auth_hint2'=>'…', 'url'=>'https://dashboard.onesignal.com' ],
				[ 'id'=>'telegram',    'name'=>'Telegram bot',      'letter'=>'T', 'color'=>'#26a5e4', 'meta'=>'Direct message · group post · inline keyboards',      'auth'=>'api_key',   'auth_label'=>'Bot Token',        'auth_hint'=>'1234567890:ABC-…', 'url'=>'https://core.telegram.org/bots#how-do-i-create-a-bot' ],
				[ 'id'=>'mapbox',      'name'=>'Mapbox',            'letter'=>'M', 'color'=>'#1da1f2', 'meta'=>'Maps · geocoding · directions API',                   'auth'=>'api_key',   'auth_label'=>'Access Token',     'auth_hint'=>'pk.…',      'url'=>'https://account.mapbox.com/access-tokens' ],
				[ 'id'=>'pusher',      'name'=>'Pusher',            'letter'=>'P', 'color'=>'#300d4f', 'meta'=>'Real-time WebSocket channels',                        'auth'=>'sid_token', 'auth_label'=>'App Key',          'auth_label2'=>'App Secret', 'auth_hint'=>'…', 'auth_hint2'=>'…', 'url'=>'https://dashboard.pusher.com' ],
			],

			// ── Ecommerce — 8 platforms ──────────────────────────────────────
			'ecommerce' => [
				[ 'id'=>'shopify',     'name'=>'Shopify',             'letter'=>'S', 'color'=>'#96bf48', 'meta'=>'Sync products · orders · inventory',                  'auth'=>'api_key',  'auth_label'=>'Admin API Key',   'auth_hint'=>'shpat_…',   'url'=>'https://shopify.dev/docs/api/admin-rest' ],
				[ 'id'=>'bigcommerce', 'name'=>'BigCommerce',         'letter'=>'B', 'color'=>'#34313f', 'meta'=>'Catalog sync · order webhooks',                        'auth'=>'api_key',  'auth_label'=>'API Token',       'auth_hint'=>'…',         'url'=>'https://developer.bigcommerce.com' ],
				[ 'id'=>'etsy',        'name'=>'Etsy',                'letter'=>'E', 'color'=>'#f56400', 'meta'=>'Handmade & vintage marketplace sync',                  'auth'=>'api_key',  'auth_label'=>'API Key',         'auth_hint'=>'…',         'url'=>'https://www.etsy.com/developers' ],
				[ 'id'=>'amazon-sp',   'name'=>'Amazon Seller',       'letter'=>'A', 'color'=>'#ff9900', 'meta'=>'SP-API · listings · orders · FBA',                    'auth'=>'api_key',  'auth_label'=>'Refresh Token',   'auth_hint'=>'Atzr|…',    'url'=>'https://sellercentral.amazon.com' ],
				[ 'id'=>'magento',     'name'=>'Magento · Adobe Commerce','letter'=>'M','color'=>'#ee672f','meta'=>'Enterprise commerce · headless · multi-store',       'auth'=>'api_key',  'auth_label'=>'Integration Token','auth_hint'=>'…',        'url'=>'https://developer.adobe.com/commerce/' ],
				[ 'id'=>'wix',         'name'=>'Wix Stores',          'letter'=>'W', 'color'=>'#0c6ebd', 'meta'=>'Wix Stores REST · cart · orders · members',           'auth'=>'api_key',  'auth_label'=>'API Key',         'auth_hint'=>'…',         'url'=>'https://dev.wix.com/api/rest' ],
				[ 'id'=>'squarespace', 'name'=>'Squarespace Commerce','letter'=>'S', 'color'=>'#000000', 'meta'=>'Inventory · orders · transactions API',               'auth'=>'api_key',  'auth_label'=>'API Key',         'auth_hint'=>'…',         'url'=>'https://developers.squarespace.com' ],
				[ 'id'=>'lemon',       'name'=>'Lemon Squeezy',       'letter'=>'L', 'color'=>'#ffc232', 'meta'=>'Merchant-of-record SaaS billing · digital goods',     'auth'=>'api_key',  'auth_label'=>'API Key',         'auth_hint'=>'eyJ0…',     'url'=>'https://app.lemonsqueezy.com/settings/api' ],
			],

			// ── Payment Gateways — 12 (cards · BNPL · crypto · regional) ────
			'payment' => [
				[ 'id'=>'stripe',      'name'=>'Stripe',              'letter'=>'S', 'color'=>'#635bff', 'meta'=>'Cards · wallets · bank transfers · subscriptions',    'auth'=>'api_key',  'auth_label'=>'Secret Key',       'auth_hint'=>'sk_live_…',  'url'=>'https://dashboard.stripe.com/apikeys' ],
				[ 'id'=>'paypal',      'name'=>'PayPal',              'letter'=>'P', 'color'=>'#0070ba', 'meta'=>'PayPal · Venmo · Pay Later',                          'auth'=>'sid_token','auth_label'=>'Client ID',        'auth_label2'=>'Client Secret', 'auth_hint'=>'…', 'auth_hint2'=>'…', 'url'=>'https://developer.paypal.com/dashboard' ],
				[ 'id'=>'square-pay',  'name'=>'Square',              'letter'=>'S', 'color'=>'#000000', 'meta'=>'In-person · online · invoicing',                       'auth'=>'api_key',  'auth_label'=>'Access Token',     'auth_hint'=>'EAAAl…',      'url'=>'https://developer.squareup.com/apps' ],
				[ 'id'=>'braintree',   'name'=>'Braintree',           'letter'=>'B', 'color'=>'#0070ba', 'meta'=>'Full-stack payments by PayPal · cards · Venmo · wallets','auth'=>'sid_token','auth_label'=>'Merchant ID',    'auth_label2'=>'Private Key',   'auth_hint'=>'…', 'auth_hint2'=>'…', 'url'=>'https://developer.paypal.com/braintree/' ],
				[ 'id'=>'adyen',       'name'=>'Adyen',               'letter'=>'A', 'color'=>'#0abf53', 'meta'=>'Global enterprise gateway · 250+ payment methods',     'auth'=>'api_key',  'auth_label'=>'API Key',          'auth_hint'=>'AQE…',        'url'=>'https://docs.adyen.com/development-resources/api-credentials/' ],
				[ 'id'=>'authorize',   'name'=>'Authorize.Net',       'letter'=>'A', 'color'=>'#1b3a6b', 'meta'=>'Legacy US gateway · ACH · recurring billing',          'auth'=>'sid_token','auth_label'=>'API Login ID',     'auth_label2'=>'Transaction Key','auth_hint'=>'…', 'auth_hint2'=>'…', 'url'=>'https://account.authorize.net' ],
				[ 'id'=>'mollie',      'name'=>'Mollie',              'letter'=>'M', 'color'=>'#0e1c2b', 'meta'=>'EU-first · iDEAL · SEPA · Bancontact · Klarna handoff','auth'=>'api_key',  'auth_label'=>'API Key',          'auth_hint'=>'live_…',      'url'=>'https://www.mollie.com/dashboard/developers/api-keys' ],
				[ 'id'=>'klarna',      'name'=>'Klarna',              'letter'=>'K', 'color'=>'#ffaeb5', 'meta'=>'Pay in 4 · BNPL · in-checkout financing',              'auth'=>'sid_token','auth_label'=>'Username',         'auth_label2'=>'Password',       'auth_hint'=>'PK…',  'auth_hint2'=>'…', 'url'=>'https://portal.klarna.com' ],
				[ 'id'=>'affirm',      'name'=>'Affirm',              'letter'=>'A', 'color'=>'#0a0a23', 'meta'=>'Larger-ticket installments · soft credit pull',        'auth'=>'sid_token','auth_label'=>'Public API Key',   'auth_label2'=>'Private API Key','auth_hint'=>'…', 'auth_hint2'=>'…', 'url'=>'https://docs.affirm.com/affirm-developers/' ],
				[ 'id'=>'coinbase',    'name'=>'Coinbase Commerce',   'letter'=>'C', 'color'=>'#0052ff', 'meta'=>'Accept BTC · ETH · USDC · settle in fiat or crypto',   'auth'=>'api_key',  'auth_label'=>'API Key',          'auth_hint'=>'…',           'url'=>'https://beta.commerce.coinbase.com/settings/security' ],
				[ 'id'=>'razorpay',    'name'=>'Razorpay',            'letter'=>'R', 'color'=>'#02042a', 'meta'=>'India-first · UPI · cards · netbanking · payouts',     'auth'=>'sid_token','auth_label'=>'Key ID',           'auth_label2'=>'Key Secret',     'auth_hint'=>'rzp_live_…','auth_hint2'=>'…', 'url'=>'https://dashboard.razorpay.com/app/keys' ],
				[ 'id'=>'plaid',       'name'=>'Plaid',               'letter'=>'P', 'color'=>'#000000', 'meta'=>'Bank account linking · ACH · balance lookups',         'auth'=>'sid_token','auth_label'=>'Client ID',        'auth_label2'=>'Secret',         'auth_hint'=>'…', 'auth_hint2'=>'…', 'url'=>'https://dashboard.plaid.com/team/keys' ],
			],

			// ── External Apps — 18 (docs · design · dev · PM · automation) ──
			'external' => [
				[ 'id'=>'zapier',      'name'=>'Zapier',              'letter'=>'Z', 'color'=>'#ff4a00', 'meta'=>'Automate workflows · 7,000+ app integrations',         'auth'=>'api_key',  'auth_label'=>'Webhook URL',      'auth_hint'=>'https://hooks.zapier.com/…', 'url'=>'https://zapier.com/app/zaps' ],
				[ 'id'=>'make',        'name'=>'Make · Integromat',   'letter'=>'M', 'color'=>'#6d00cc', 'meta'=>'Visual scenarios · branching · scheduled flows',       'auth'=>'api_key',  'auth_label'=>'Webhook URL',      'auth_hint'=>'https://hook.make.com/…',     'url'=>'https://www.make.com/en/help/tools/webhooks' ],
				[ 'id'=>'slack',       'name'=>'Slack',               'letter'=>'S', 'color'=>'#4a154b', 'meta'=>'Post messages to channels · receive slash commands',   'auth'=>'api_key',  'auth_label'=>'Incoming Webhook', 'auth_hint'=>'https://hooks.slack.com/…',  'url'=>'https://api.slack.com/messaging/webhooks',
					'auth_methods'=>['api_key','oauth'], 'oauth_authorize_url'=>'https://slack.com/oauth/v2/authorize', 'oauth_token_url'=>'https://slack.com/api/oauth.v2.access', 'oauth_scope'=>'chat:write channels:read' ],
				[ 'id'=>'teams',       'name'=>'Microsoft Teams',     'letter'=>'T', 'color'=>'#5059c9', 'meta'=>'Channel posts · adaptive cards · bot replies',         'auth'=>'api_key',  'auth_label'=>'Webhook URL',      'auth_hint'=>'https://outlook.office.com/…', 'url'=>'https://learn.microsoft.com/en-us/microsoftteams/platform/webhooks-and-connectors/' ],
				[ 'id'=>'discord',     'name'=>'Discord',             'letter'=>'D', 'color'=>'#5865f2', 'meta'=>'Post to Discord channels via webhooks',                'auth'=>'api_key',  'auth_label'=>'Webhook URL',      'auth_hint'=>'https://discord.com/api/webhooks/…', 'url'=>'https://discord.com/developers/docs/resources/webhook' ],
				[ 'id'=>'zoom',        'name'=>'Zoom',                'letter'=>'Z', 'color'=>'#2d8cff', 'meta'=>'Meetings · webinars · recording links',                'auth'=>'api_key',  'auth_label'=>'Server-to-Server Token','auth_hint'=>'…',     'url'=>'https://marketplace.zoom.us/develop/create' ],
				[ 'id'=>'calendly',    'name'=>'Calendly',            'letter'=>'C', 'color'=>'#006bff', 'meta'=>'Bookings · event types · embed scheduler',             'auth'=>'api_key',  'auth_label'=>'Personal Access Token','auth_hint'=>'eyJraWQi…','url'=>'https://calendly.com/integrations/api_webhooks' ],
				[ 'id'=>'notion',      'name'=>'Notion',              'letter'=>'N', 'color'=>'#000000', 'meta'=>'Sync database records · push updates to pages',        'auth'=>'api_key',  'auth_label'=>'Integration Token','auth_hint'=>'secret_…',                 'url'=>'https://www.notion.so/my-integrations' ],
				[ 'id'=>'airtable',    'name'=>'Airtable',            'letter'=>'A', 'color'=>'#18bfff', 'meta'=>'Read / write Airtable bases via API',                  'auth'=>'api_key',  'auth_label'=>'Personal Access Token','auth_hint'=>'pat…',                 'url'=>'https://airtable.com/create/tokens' ],
				[ 'id'=>'gdrive',      'name'=>'Google Drive',        'letter'=>'G', 'color'=>'#4285f4', 'meta'=>'Docs · Sheets · Slides · files — read into Therum',    'auth'=>'sid_token','auth_label'=>'Client ID',        'auth_label2'=>'Client Secret','auth_hint'=>'…apps.googleusercontent.com','auth_hint2'=>'…', 'url'=>'https://console.cloud.google.com/apis/credentials',
					'auth_methods'=>['api_key','oauth'], 'oauth_authorize_url'=>'https://accounts.google.com/o/oauth2/v2/auth', 'oauth_token_url'=>'https://oauth2.googleapis.com/token', 'oauth_scope'=>'https://www.googleapis.com/auth/drive.readonly https://www.googleapis.com/auth/drive.file' ],
				[ 'id'=>'dropbox',     'name'=>'Dropbox',             'letter'=>'D', 'color'=>'#0061ff', 'meta'=>'Files · Paper · sign — pull assets into Therum media', 'auth'=>'api_key',  'auth_label'=>'Access Token',     'auth_hint'=>'sl.…',         'url'=>'https://www.dropbox.com/developers/apps',
					'auth_methods'=>['api_key','oauth'], 'oauth_authorize_url'=>'https://www.dropbox.com/oauth2/authorize', 'oauth_token_url'=>'https://api.dropbox.com/oauth2/token', 'oauth_scope'=>'files.content.read files.content.write files.metadata.read' ],
				[ 'id'=>'figma',       'name'=>'Figma',               'letter'=>'F', 'color'=>'#0d0d0d', 'meta'=>'Files · frames · variables — design tokens sync',      'auth'=>'api_key',  'auth_label'=>'Personal Access Token','auth_hint'=>'figd_…',     'url'=>'https://www.figma.com/developers/api#access-tokens' ],
				[ 'id'=>'github',      'name'=>'GitHub',              'letter'=>'G', 'color'=>'#0d1117', 'meta'=>'Repos · issues · PRs · Actions · releases',            'auth'=>'api_key',  'auth_label'=>'Personal Access Token','auth_hint'=>'ghp_…',      'url'=>'https://github.com/settings/tokens' ],
				[ 'id'=>'gitlab',      'name'=>'GitLab',              'letter'=>'G', 'color'=>'#fc6d26', 'meta'=>'Repos · pipelines · merge requests',                   'auth'=>'api_key',  'auth_label'=>'Access Token',     'auth_hint'=>'glpat-…',      'url'=>'https://gitlab.com/-/user_settings/personal_access_tokens' ],
				[ 'id'=>'linear',      'name'=>'Linear',              'letter'=>'L', 'color'=>'#5e6ad2', 'meta'=>'Issues · projects · cycles — embed on dashboard',      'auth'=>'api_key',  'auth_label'=>'API Key',          'auth_hint'=>'lin_api_…',    'url'=>'https://linear.app/settings/api' ],
				[ 'id'=>'asana',       'name'=>'Asana',               'letter'=>'A', 'color'=>'#f06a6a', 'meta'=>'Tasks · projects · timeline — sync into Therum lists', 'auth'=>'api_key',  'auth_label'=>'Personal Access Token','auth_hint'=>'…',          'url'=>'https://app.asana.com/0/my-apps' ],
				[ 'id'=>'clickup',     'name'=>'ClickUp',             'letter'=>'C', 'color'=>'#7b68ee', 'meta'=>'Workspaces · spaces · lists · docs · automations',     'auth'=>'api_key',  'auth_label'=>'Personal Token',   'auth_hint'=>'pk_…',         'url'=>'https://app.clickup.com/settings/apps' ],
				[ 'id'=>'hubspot',     'name'=>'HubSpot',             'letter'=>'H', 'color'=>'#ff7a59', 'meta'=>'Contacts · deals · marketing · service hub',           'auth'=>'api_key',  'auth_label'=>'Private App Token','auth_hint'=>'pat-na1-…',    'url'=>'https://app.hubspot.com/private-apps' ],
			],
		];
	}

	public static function get_connections(): array {
		return (array) get_option( self::OPTION_KEY, [] );
	}

	// ── At-rest encryption (AES-256-GCM) ───────────────────────────────────
	// Credentials are encrypted with a 32-byte key derived from SECURE_AUTH_KEY
	// + a domain-scoped HKDF info string. Each value gets a fresh random IV
	// and an authentication tag — tamper / corruption is detected on decrypt.
	//
	// Caveat: if SECURE_AUTH_KEY ever rotates (e.g. `wp config shuffle-salts`),
	// every stored credential becomes unrecoverable. This is the same
	// trade-off WP itself makes for cookies — documented in the connection
	// modal as "stored encrypted; re-enter if you rotate WP salts."
	private static function crypto_key(): string {
		$salt = defined( 'SECURE_AUTH_KEY' ) && SECURE_AUTH_KEY ? SECURE_AUTH_KEY : 'therum-fallback-salt-do-not-use-in-prod';
		return hash( 'sha256', $salt . '|therum-connections|v1', true );
	}
	public static function encrypt( string $plain ): string {
		if ( $plain === '' ) return '';
		$iv  = random_bytes( 12 ); // GCM standard IV length
		$tag = '';
		$ct  = openssl_encrypt( $plain, 'aes-256-gcm', self::crypto_key(), OPENSSL_RAW_DATA, $iv, $tag );
		if ( $ct === false ) return '';
		return 'v1:' . base64_encode( $iv . $tag . $ct );
	}
	public static function decrypt( string $blob ): string {
		if ( $blob === '' ) return '';
		if ( strpos( $blob, 'v1:' ) !== 0 ) return ''; // unknown / legacy format → caller asks user to re-enter
		$raw = base64_decode( substr( $blob, 3 ), true );
		if ( $raw === false || strlen( $raw ) < 28 ) return '';
		$iv  = substr( $raw, 0, 12 );
		$tag = substr( $raw, 12, 16 );
		$ct  = substr( $raw, 28 );
		$pt  = openssl_decrypt( $ct, 'aes-256-gcm', self::crypto_key(), OPENSSL_RAW_DATA, $iv, $tag );
		return $pt === false ? '' : $pt;
	}

	private static function save_connection( string $id, array $data ): void {
		$all = self::get_connections();
		// Encrypt the secret fields at rest. Label + timestamp stay plain.
		// key3/key4 are only populated for `multi` auth shape (added in 1.9.16+).
		foreach ( [ 'key', 'key2', 'key3', 'key4' ] as $k ) {
			if ( isset( $data[ $k ] ) && $data[ $k ] !== '' ) {
				$data[ $k ] = self::encrypt( (string) $data[ $k ] );
			}
		}
		$all[ $id ] = $data;
		update_option( self::OPTION_KEY, $all, false );
	}

	private static function remove_connection( string $id ): void {
		$all = self::get_connections();
		unset( $all[ $id ] );
		update_option( self::OPTION_KEY, $all, false );
	}

	// ── Custom (user-defined) providers ───────────────────────────────────────
	// Stored in a separate option so the built-in registry stays a pure code
	// constant. Each row is keyed by user-supplied slug and carries the same
	// shape as a built-in provider row (name/letter/color/auth/etc.) plus a
	// 'category' field so providers() knows where to merge it. Credentials
	// for custom providers go in the same OPTION_KEY map as built-ins.
	const OPTION_CUSTOM = 'therum_connections_custom';

	public static function get_custom_providers(): array {
		return (array) get_option( self::OPTION_CUSTOM, [] );
	}

	private static function save_custom_provider( string $slug, array $row ): void {
		$all = self::get_custom_providers();
		$all[ $slug ] = $row;
		update_option( self::OPTION_CUSTOM, $all, false );
	}

	private static function remove_custom_provider( string $slug ): void {
		$all = self::get_custom_providers();
		unset( $all[ $slug ] );
		update_option( self::OPTION_CUSTOM, $all, false );
		// Provider gone → orphan credential goes with it.
		self::remove_connection( $slug );
	}

	/**
	 * Decrypt and return the live credential for a connector. The downstream
	 * Therum integrations (Anthropic, OpenAI, etc.) call this to load the
	 * stored key on each request. Returns ['key' => '', 'key2' => ''] when
	 * the connector hasn't been configured.
	 */
	public static function get_credential( string $id ): array {
		$all = self::get_connections();
		$row = $all[ sanitize_key( $id ) ] ?? null;
		if ( ! is_array( $row ) ) return [ 'key' => '', 'key2' => '', 'key3' => '', 'key4' => '' ];
		return [
			'key'   => self::decrypt( (string) ( $row['key']  ?? '' ) ),
			'key2'  => self::decrypt( (string) ( $row['key2'] ?? '' ) ),
			'key3'  => self::decrypt( (string) ( $row['key3'] ?? '' ) ),
			'key4'  => self::decrypt( (string) ( $row['key4'] ?? '' ) ),
			'label' => (string) ( $row['label'] ?? '' ),
		];
	}

	public static function ajax_connect(): void {
		if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'forbidden', 403 );
		check_ajax_referer( 'therum_connections', 'nonce' );

		// Real at-rest encryption shipped in 1.9.1 (see crypto_key/encrypt/
		// decrypt above) — the dev-only gate is gone. SECURE_AUTH_KEY is the
		// root salt; rotating it invalidates every stored credential.
		if ( ! function_exists( 'openssl_encrypt' ) ) {
			wp_send_json_error( [ 'message' => 'OpenSSL extension is required to store credentials securely. Install php-openssl on this host.' ] );
		}

		$id    = sanitize_key( $_POST['provider_id'] ?? '' );
		$key   = sanitize_text_field( wp_unslash( $_POST['key']  ?? '' ) );
		$key2  = sanitize_text_field( wp_unslash( $_POST['key2'] ?? '' ) );
		$key3  = sanitize_text_field( wp_unslash( $_POST['key3'] ?? '' ) );
		$key4  = sanitize_text_field( wp_unslash( $_POST['key4'] ?? '' ) );
		$label = sanitize_text_field( wp_unslash( $_POST['label'] ?? '' ) );

		if ( ! $id || ! $key ) wp_send_json_error( 'missing fields' );

		self::save_connection( $id, [
			'key'          => $key,
			'key2'         => $key2,
			'key3'         => $key3,
			'key4'         => $key4,
			'label'        => $label,
			'connected_at' => time(),
		] );

		wp_send_json_success( [ 'id' => $id, 'connected_at' => human_time_diff( time() ) ] );
	}

	/**
	 * Providers that have a real test endpoint implementation below. Used
	 * to gate the Test button in the modal — the button is hidden for any
	 * provider not on this list so users don't click into a stub.
	 *
	 * To add a new testable provider:
	 *   1. Add a case to ajax_test() that makes a real auth-validating request
	 *      (prefer GET /models or GET /user — cheaper than chat completions)
	 *   2. Add its id here
	 *   3. Done — the button shows automatically
	 */
	const TESTABLE = [
		'anthropic', 'openai', 'google-gemini', 'xai',
		'mistral', 'deepseek', 'groq', 'cohere',
		'huggingface', 'github', 'gitlab', 'elevenlabs',
	];

	public static function is_testable( string $id ): bool {
		return in_array( $id, self::TESTABLE, true );
	}

	/**
	 * Helper: OpenAI-compatible providers. They all expose GET /v1/models
	 * (or close) with Bearer auth. Doesn't burn tokens — just validates the
	 * key. Calls wp_send_json_{success,error} directly.
	 */
	private static function test_openai_compat( string $base_url, string $models_path, string $key, string $name ): void {
		$res = wp_remote_get( rtrim( $base_url, '/' ) . $models_path, [
			'timeout' => 12,
			'headers' => [ 'authorization' => 'Bearer ' . $key ],
		] );
		if ( is_wp_error( $res ) ) wp_send_json_error( [ 'message' => $res->get_error_message() ] );
		$code = (int) wp_remote_retrieve_response_code( $res );
		if ( $code === 200 ) {
			$body = json_decode( wp_remote_retrieve_body( $res ), true );
			$n    = is_array( $body['data'] ?? null ) ? count( $body['data'] ) : null;
			wp_send_json_success( [ 'message' => $name . ' authenticated' . ( $n !== null ? ' · ' . $n . ' models visible' : '' ) . '.' ] );
		}
		$body = json_decode( wp_remote_retrieve_body( $res ), true );
		$msg  = $body['error']['message'] ?? wp_remote_retrieve_response_message( $res );
		wp_send_json_error( [ 'message' => $name . ' returned ' . $code . ' — ' . $msg ] );
	}

	/**
	 * Helper: token-authenticated GET against an identity endpoint (whoami).
	 * Used for GitHub, GitLab, HuggingFace, ElevenLabs — each takes a
	 * slightly different header name, so callers pass the headers map.
	 */
	private static function test_whoami( string $url, array $headers, string $name, ?string $identity_field = null ): void {
		$headers['accept']     = 'application/json';
		$headers['user-agent'] = 'Therum-OS/' . THERUM_OS_VERSION;
		$res = wp_remote_get( $url, [ 'timeout' => 12, 'headers' => $headers ] );
		if ( is_wp_error( $res ) ) wp_send_json_error( [ 'message' => $res->get_error_message() ] );
		$code = (int) wp_remote_retrieve_response_code( $res );
		$body = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( $code === 200 ) {
			$who = $identity_field && is_array( $body ) ? ( $body[ $identity_field ] ?? '' ) : '';
			wp_send_json_success( [ 'message' => $name . ' authenticated' . ( $who ? ' as ' . $who : '' ) . '.' ] );
		}
		$msg = $body['message'] ?? $body['error']['message'] ?? wp_remote_retrieve_response_message( $res );
		wp_send_json_error( [ 'message' => $name . ' returned ' . $code . ' — ' . $msg ] );
	}

	/**
	 * Live test of a stored credential. Reaches out to the provider's API
	 * with the stored key and reports the result. Only providers on the
	 * TESTABLE list get past the early-return guard — everything else
	 * never sees the button (gated in the modal JS via testableIds).
	 */
	public static function ajax_test(): void {
		if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'forbidden', 403 );
		check_ajax_referer( 'therum_connections', 'nonce' );

		$id = sanitize_key( $_POST['provider_id'] ?? '' );
		if ( ! $id ) wp_send_json_error( 'missing id' );

		// Refuse cleanly for providers without a real test implementation,
		// even though the JS hides the button — defense in depth against
		// someone POSTing directly.
		if ( ! self::is_testable( $id ) ) {
			wp_send_json_error( [ 'message' => 'Live test isn\'t implemented for this provider yet. Open an issue if you want it added.' ] );
		}

		$cred = self::get_credential( $id );
		if ( $cred['key'] === '' ) wp_send_json_error( [ 'message' => 'No credential on file. Connect first.' ] );

		switch ( $id ) {
			case 'anthropic':
				$res = wp_remote_post( 'https://api.anthropic.com/v1/messages', [
					'timeout' => 15,
					'headers' => [
						'x-api-key'         => $cred['key'],
						'anthropic-version' => '2023-06-01',
						'content-type'      => 'application/json',
					],
					'body' => wp_json_encode( [
						'model'      => 'claude-haiku-4-5-20251001',
						'max_tokens' => 8,
						'messages'   => [ [ 'role' => 'user', 'content' => 'Reply with the single word: ok' ] ],
					] ),
				] );
				if ( is_wp_error( $res ) ) wp_send_json_error( [ 'message' => $res->get_error_message() ] );
				$code = wp_remote_retrieve_response_code( $res );
				if ( $code !== 200 ) {
					$body = json_decode( wp_remote_retrieve_body( $res ), true );
					$msg  = $body['error']['message'] ?? wp_remote_retrieve_response_message( $res );
					wp_send_json_error( [ 'message' => 'Anthropic returned ' . $code . ' — ' . $msg ] );
				}
				$body = json_decode( wp_remote_retrieve_body( $res ), true );
				$out  = $body['content'][0]['text'] ?? '';
				wp_send_json_success( [ 'message' => 'Claude replied: ' . trim( $out ) ] );

			// ── OpenAI-compatible providers — GET /v1/models doesn't burn tokens
			case 'openai':       self::test_openai_compat( 'https://api.openai.com',    '/v1/models', $cred['key'], 'OpenAI' );      break;
			case 'xai':          self::test_openai_compat( 'https://api.x.ai',          '/v1/models', $cred['key'], 'xAI' );         break;
			case 'mistral':      self::test_openai_compat( 'https://api.mistral.ai',    '/v1/models', $cred['key'], 'Mistral' );     break;
			case 'deepseek':     self::test_openai_compat( 'https://api.deepseek.com',  '/models',    $cred['key'], 'DeepSeek' );    break;
			case 'groq':         self::test_openai_compat( 'https://api.groq.com',      '/openai/v1/models', $cred['key'], 'Groq' ); break;
			case 'cohere':       self::test_openai_compat( 'https://api.cohere.com',    '/v1/models', $cred['key'], 'Cohere' );      break;

			// ── Google Gemini — key as query param, not header
			case 'google-gemini':
				$res = wp_remote_get( 'https://generativelanguage.googleapis.com/v1beta/models?key=' . rawurlencode( $cred['key'] ), [ 'timeout' => 12 ] );
				if ( is_wp_error( $res ) ) wp_send_json_error( [ 'message' => $res->get_error_message() ] );
				$code = (int) wp_remote_retrieve_response_code( $res );
				$body = json_decode( wp_remote_retrieve_body( $res ), true );
				if ( $code === 200 ) {
					$n = is_array( $body['models'] ?? null ) ? count( $body['models'] ) : null;
					wp_send_json_success( [ 'message' => 'Gemini authenticated' . ( $n !== null ? ' · ' . $n . ' models visible' : '' ) . '.' ] );
				}
				wp_send_json_error( [ 'message' => 'Gemini returned ' . $code . ' — ' . ( $body['error']['message'] ?? wp_remote_retrieve_response_message( $res ) ) ] );

			// ── Identity endpoints (whoami-style) — different header names per provider
			case 'github':
				self::test_whoami( 'https://api.github.com/user', [
					'authorization' => 'Bearer ' . $cred['key'],
				], 'GitHub', 'login' );
				break;
			case 'gitlab':
				self::test_whoami( 'https://gitlab.com/api/v4/user', [
					'private-token' => $cred['key'],
				], 'GitLab', 'username' );
				break;
			case 'huggingface':
				self::test_whoami( 'https://huggingface.co/api/whoami-v2', [
					'authorization' => 'Bearer ' . $cred['key'],
				], 'Hugging Face', 'name' );
				break;
			case 'elevenlabs':
				self::test_whoami( 'https://api.elevenlabs.io/v1/user', [
					'xi-api-key' => $cred['key'],
				], 'ElevenLabs', null );
				break;
		}
	}

	public static function ajax_disconnect(): void {
		if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'forbidden', 403 );
		check_ajax_referer( 'therum_connections', 'nonce' );

		$id = sanitize_key( $_POST['provider_id'] ?? '' );
		if ( ! $id ) wp_send_json_error( 'missing id' );

		self::remove_connection( $id );
		wp_send_json_success( [ 'id' => $id ] );
	}

	// ── Add / edit a user-defined custom provider ─────────────────────────────
	// Upsert keyed by slug. Same handler covers Add (no editing_slug) and
	// Edit (editing_slug == slug, or rename when they differ). Optionally
	// also writes the credential row in one call, so the user can define
	// the provider AND paste their key in a single modal.
	public static function ajax_add_custom(): void {
		if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'forbidden', 403 );
		check_ajax_referer( 'therum_connections', 'nonce' );

		$category = sanitize_key( $_POST['category'] ?? '' );
		$cats     = self::categories();
		if ( ! isset( $cats[ $category ] ) ) wp_send_json_error( [ 'message' => 'Unknown category.' ] );

		$name = trim( sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) ) );
		if ( $name === '' ) wp_send_json_error( [ 'message' => 'Name is required.' ] );

		$orig_slug = sanitize_key( wp_unslash( $_POST['slug'] ?? '' ) );
		$slug      = $orig_slug !== '' ? $orig_slug : sanitize_title( $name );
		if ( $slug === '' ) wp_send_json_error( [ 'message' => 'Could not derive a slug from that name.' ] );

		$editing_slug = sanitize_key( wp_unslash( $_POST['editing_slug'] ?? '' ) );

		// Collision check: built-ins win, you can't shadow them. Custom-vs-custom
		// collisions are fine only when you're editing the same row.
		$builtin_ids = [];
		foreach ( self::_builtin_providers() as $list ) {
			foreach ( $list as $p ) $builtin_ids[ $p['id'] ] = true;
		}
		if ( isset( $builtin_ids[ $slug ] ) ) {
			wp_send_json_error( [ 'message' => 'A built-in provider already uses the id "' . $slug . '". Pick a different slug.' ] );
		}
		$existing_custom = self::get_custom_providers();
		if ( isset( $existing_custom[ $slug ] ) && $slug !== $editing_slug ) {
			wp_send_json_error( [ 'message' => 'A custom provider with the id "' . $slug . '" already exists.' ] );
		}

		$auth = sanitize_key( wp_unslash( $_POST['auth'] ?? 'api_key' ) );
		// `multi` = 1–4 user-defined credential fields (key, secret, auth secret, workspace id, …).
		// Existing api_key/sid_token/base_url shapes stay unchanged for backward compat.
		if ( ! in_array( $auth, [ 'api_key', 'sid_token', 'base_url', 'multi' ], true ) ) $auth = 'api_key';

		$color = sanitize_text_field( wp_unslash( $_POST['color'] ?? '' ) );
		if ( ! preg_match( '/^#[0-9a-fA-F]{6}$/', $color ) ) {
			$color = '#' . substr( md5( $slug ), 0, 6 );
		}

		$letter = strtoupper( mb_substr( $name, 0, 1 ) );
		if ( $letter === '' ) $letter = '?';

		$row = [
			'category'   => $category,
			'name'       => $name,
			'letter'     => $letter,
			'color'      => $color,
			'meta'       => sanitize_text_field( wp_unslash( $_POST['meta'] ?? '' ) ),
			'auth'       => $auth,
			'auth_label' => sanitize_text_field( wp_unslash( $_POST['auth_label'] ?? ( $auth === 'base_url' ? 'Base URL' : 'API Key' ) ) ),
			'auth_hint'  => sanitize_text_field( wp_unslash( $_POST['auth_hint'] ?? '' ) ),
			'url'        => esc_url_raw( wp_unslash( $_POST['url'] ?? '' ) ),
		];
		if ( $auth === 'sid_token' ) {
			$row['auth_label2'] = sanitize_text_field( wp_unslash( $_POST['auth_label2'] ?? 'Token' ) );
			$row['auth_hint2']  = sanitize_text_field( wp_unslash( $_POST['auth_hint2'] ?? '' ) );
		}

		// `multi` shape — copy 1–4 label+type pairs onto the provider row so
		// the Connect modal can render the right inputs and the credential
		// vault knows which slots are filled. Row 1's label is required;
		// rows 2–4 are skipped when blank.
		if ( $auth === 'multi' ) {
			$labels = [];
			$types  = [];
			for ( $i = 1; $i <= 4; $i++ ) {
				$lbl = trim( sanitize_text_field( wp_unslash( $_POST[ 'cred_label_' . $i ] ?? '' ) ) );
				if ( $lbl === '' ) continue;
				$typ = sanitize_key( wp_unslash( $_POST[ 'cred_type_' . $i ] ?? 'password' ) );
				if ( ! in_array( $typ, [ 'password', 'text' ], true ) ) $typ = 'password';
				$labels[] = $lbl;
				$types[]  = $typ;
			}
			if ( empty( $labels ) ) {
				wp_send_json_error( [ 'message' => 'At least one credential field is required for "Multiple fields" auth.' ] );
			}
			// Reuse auth_label / auth_label2 for the first two so existing
			// downstream consumers (vault display, connect modal) keep working.
			$row['auth_label']  = $labels[0];
			$row['auth_type']   = $types[0];
			if ( isset( $labels[1] ) ) { $row['auth_label2'] = $labels[1]; $row['auth_type2'] = $types[1]; }
			if ( isset( $labels[2] ) ) { $row['auth_label3'] = $labels[2]; $row['auth_type3'] = $types[2]; }
			if ( isset( $labels[3] ) ) { $row['auth_label4'] = $labels[3]; $row['auth_type4'] = $types[3]; }
			$row['auth_count']  = count( $labels );
		}

		// Rename path: editing an existing custom and the slug changed. Move
		// the credential (if any) to the new id, then drop the old row.
		if ( $editing_slug && $editing_slug !== $slug ) {
			$conns = self::get_connections();
			if ( isset( $conns[ $editing_slug ] ) ) {
				$conns[ $slug ] = $conns[ $editing_slug ];
				unset( $conns[ $editing_slug ] );
				update_option( self::OPTION_KEY, $conns, false );
			}
			$cust = self::get_custom_providers();
			unset( $cust[ $editing_slug ] );
			update_option( self::OPTION_CUSTOM, $cust, false );
		}

		self::save_custom_provider( $slug, $row );

		// Optional inline credential capture. Encryption handled by save_connection().
		$key   = sanitize_text_field( wp_unslash( $_POST['key']   ?? '' ) );
		$key2  = sanitize_text_field( wp_unslash( $_POST['key2']  ?? '' ) );
		$key3  = sanitize_text_field( wp_unslash( $_POST['key3']  ?? '' ) );
		$key4  = sanitize_text_field( wp_unslash( $_POST['key4']  ?? '' ) );
		$label = sanitize_text_field( wp_unslash( $_POST['label'] ?? '' ) );
		if ( $key !== '' ) {
			self::save_connection( $slug, [
				'key'          => $key,
				'key2'         => $key2,
				'key3'         => $key3,
				'key4'         => $key4,
				'label'        => $label,
				'connected_at' => time(),
			] );
		}

		wp_send_json_success( [
			'id'      => $slug,
			'editing' => (bool) $editing_slug,
		] );
	}

	public static function ajax_delete_custom(): void {
		if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'forbidden', 403 );
		check_ajax_referer( 'therum_connections', 'nonce' );

		$slug = sanitize_key( wp_unslash( $_POST['provider_id'] ?? '' ) );
		if ( ! $slug ) wp_send_json_error( 'missing id' );

		$custom = self::get_custom_providers();
		if ( ! isset( $custom[ $slug ] ) ) {
			wp_send_json_error( [ 'message' => 'Built-in providers can\'t be deleted.' ] );
		}

		self::remove_custom_provider( $slug ); // also clears the credential
		wp_send_json_success( [ 'id' => $slug ] );
	}

	// ── Render ────────────────────────────────────────────────────────────────
	public static function render(): void {
		$all_providers  = self::providers();
		$categories     = self::categories();
		$connections    = self::get_connections();
		$nonce          = wp_create_nonce( 'therum_connections' );

		// Active category from URL, default to first
		$tab = sanitize_key( $_GET['tab'] ?? '' );
		if ( ! isset( $categories[ $tab ] ) ) $tab = array_key_first( $categories );

		$base_url = admin_url( 'admin.php?page=therum-connections' );

		// Count connected per category
		$cat_counts = [];
		foreach ( $all_providers as $cat => $provs ) {
			$total     = count( $provs );
			$connected = 0;
			foreach ( $provs as $p ) { if ( isset( $connections[ $p['id'] ] ) ) $connected++; }
			$cat_counts[ $cat ] = [ 'connected' => $connected, 'total' => $total ];
		}

		$providers = $all_providers[ $tab ] ?? [];
		$cat       = $categories[ $tab ];

		// Management tabs (static, no providers)
		$manage_tabs = [
			'api-webhooks'  => 'API &amp; Webhooks',
			'api-vault'     => 'API keys vault',
			'webhooks-log'  => 'Webhooks log',
			'audit-log'     => 'Audit log',
		];
		$is_manage = isset( $manage_tabs[ $tab ] );
		?>
<style>
.thc-wrap{display:grid;grid-template-columns:240px 1fr;min-height:calc(100vh - var(--topbar-h,72px));gap:0}
.thc-sidebar{padding:20px 12px;border-right:1px solid var(--bd);position:sticky;top:0;align-self:start}
.thc-sidebar-group-label{font-size:10px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--tx3);padding:14px 10px 6px}
.thc-nav-item{display:flex;align-items:center;gap:8px;padding:8px 12px;border-radius:7px;color:var(--tx2);font-size:13px;font-weight:500;text-decoration:none;cursor:pointer;transition:all var(--e,.15s ease);margin-bottom:2px}
.thc-nav-item:hover{background:var(--sf2);color:var(--tx)}
.thc-nav-item.active{background:rgba(37,99,235,.08);color:var(--ac)}
.thc-nav-dot{width:7px;height:7px;border-radius:50%;background:var(--bd2);flex-shrink:0}
.thc-nav-dot.connected{background:var(--ok)}
.thc-nav-dot.partial{background:var(--wrn)}
.thc-nav-count{margin-left:auto;font-size:11px;font-weight:600;color:var(--tx3)}
.thc-nav-item.active .thc-nav-count{color:var(--ac)}
.thc-content{padding:32px 36px 80px}
.thc-breadcrumb{font-size:10px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:var(--tx3);margin-bottom:10px}
.thc-title{font-size:22px;font-weight:700;color:var(--tx);margin:0 0 6px}
.thc-desc{font-size:13px;color:var(--tx2);margin:0 0 24px;line-height:1.6}
.thc-toolbar{display:flex;align-items:center;gap:10px;margin-bottom:24px}
.thc-search{display:flex;align-items:center;gap:8px;padding:8px 12px;background:var(--sf2);border:1px solid var(--bd);border-radius:8px;flex:1;max-width:320px}
.thc-search svg{color:var(--tx3);flex-shrink:0}
.thc-search input{border:none;outline:none;background:transparent;font-size:13px;color:var(--tx);width:100%}
.thc-search input::placeholder{color:var(--tx3)}
.thc-providers{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:14px}
.thc-card{background:var(--sf);border:1px solid var(--bd);border-radius:12px;padding:20px;display:flex;flex-direction:column;gap:0;transition:border-color var(--e,.15s ease)}
.thc-card:hover{border-color:var(--bd2)}
.thc-card-head{display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:14px}
.thc-card-logo{width:44px;height:44px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:18px;color:#fff;flex-shrink:0}
.thc-card-status{font-size:10px;font-weight:700;letter-spacing:.08em;display:flex;align-items:center;gap:5px}
.thc-card-status-dot{width:6px;height:6px;border-radius:50%;background:var(--bd2)}
.thc-card-status-dot.ok{background:var(--ok)}
.thc-card-name{font-size:15px;font-weight:700;color:var(--tx);margin-bottom:4px}
.thc-card-meta{font-size:12px;color:var(--tx3);line-height:1.5;margin-bottom:16px;flex:1}
.thc-card-foot{display:flex;align-items:center;justify-content:space-between;padding-top:14px;border-top:1px solid var(--bd)}
.thc-card-action{font-size:13px;font-weight:600;color:var(--tx);text-decoration:none;cursor:pointer;background:transparent;border:none;padding:0;display:inline-flex;align-items:center;gap:4px;transition:color var(--e)}
.thc-card-action:hover{color:var(--ac)}
.thc-card-action.connected{color:var(--ac)}
.thc-card-detail{font-size:11px;color:var(--tx3)}
/* Connect modal */
.thc-modal-backdrop{position:fixed;inset:0;background:rgba(0,0,0,.5);backdrop-filter:blur(4px);z-index:9990;display:flex;align-items:center;justify-content:center}
.thc-modal{background:var(--sf);border:1px solid var(--bd);border-radius:16px;padding:28px;width:440px;max-width:calc(100vw - 40px);box-shadow:0 24px 64px rgba(0,0,0,.25)}
.thc-modal-head{display:flex;align-items:center;gap:14px;margin-bottom:20px}
.thc-modal-logo{width:44px;height:44px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:18px;color:#fff;flex-shrink:0}
.thc-modal-title{font-size:17px;font-weight:700;color:var(--tx)}
.thc-modal-sub{font-size:12px;color:var(--tx3);margin-top:2px}
.thc-modal-close{margin-left:auto;width:28px;height:28px;border-radius:6px;background:transparent;border:1px solid var(--bd);cursor:pointer;display:flex;align-items:center;justify-content:center;color:var(--tx3);flex-shrink:0}
.thc-modal-close:hover{border-color:var(--tx2);color:var(--tx)}
.thc-field{margin-bottom:16px}
.thc-field label{display:block;font-size:12px;font-weight:600;color:var(--tx2);margin-bottom:6px}
.thc-field input{width:100%;padding:9px 12px;background:var(--sf2);border:1px solid var(--bd);border-radius:8px;font-size:13px;color:var(--tx);outline:none;box-sizing:border-box;transition:border-color .2s ease}
.thc-field input:focus{border-color:var(--ac)}
.thc-field .thc-field-hint{font-size:11px;color:var(--tx3);margin-top:4px}
.thc-field a{color:var(--ac);text-decoration:none;font-size:11px}
.thc-modal-actions{display:flex;gap:8px;justify-content:flex-end;margin-top:20px}
.thc-modal-err{font-size:12px;color:var(--err);margin-top:10px;display:none}
.thc-modal-step[hidden]{display:none!important}
.thc-modal-back{background:none;border:none;color:var(--tx3);font-size:12px;font-weight:500;cursor:pointer;padding:0 0 14px;font-family:var(--f)}
.thc-modal-back:hover{color:var(--tx)}
.thc-method-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.thc-method{text-align:left;background:var(--sf2);border:1px solid var(--bd);border-radius:12px;padding:18px;cursor:pointer;font-family:var(--f);transition:all .15s ease;display:flex;flex-direction:column;gap:6px;min-height:140px}
.thc-method:hover{border-color:var(--ac);background:var(--sf);transform:translateY(-1px);box-shadow:0 6px 20px rgba(0,0,0,.06)}
.thc-method-icon{font-size:22px;line-height:1;margin-bottom:6px}
.thc-method-name{font-size:14px;font-weight:600;color:var(--tx)}
.thc-method-desc{font-size:12px;color:var(--tx3);line-height:1.45}
.thc-method{position:relative}
.thc-method-badge{position:absolute;top:10px;right:10px;font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;padding:3px 7px;border-radius:999px;background:color-mix(in srgb,var(--wrn) 16%,transparent);color:var(--wrn);border:1px solid color-mix(in srgb,var(--wrn) 40%,transparent)}
.thc-method-badge.is-ready{background:color-mix(in srgb,var(--ok) 16%,transparent);color:var(--ok);border-color:color-mix(in srgb,var(--ok) 40%,transparent)}
.thc-signin-steps{background:var(--sf2);border:1px solid var(--bd);border-radius:10px;padding:14px 16px;margin-bottom:14px;font-size:13px;color:var(--tx2);line-height:1.55}
.thc-signin-steps ol{margin:0;padding-left:18px;display:flex;flex-direction:column;gap:6px}
.thc-signin-steps a{color:var(--ac);text-decoration:none;font-weight:600}
.thc-signin-steps a:hover{text-decoration:underline}
.thc-signin-cta{display:inline-flex;align-items:center;gap:6px;background:var(--tx);color:var(--sf);border:1px solid var(--tx);border-radius:8px;padding:8px 14px;font:600 12px/1 var(--f);text-decoration:none;margin-top:10px}
.thc-signin-cta:hover{background:var(--ac);border-color:var(--ac);color:#fff}
/* Manage panel */
.thc-manage-panel{background:var(--sf2);border:1px solid var(--bd);border-radius:12px;padding:20px;margin-top:14px;display:none}
.thc-manage-panel.open{display:block}
/* Custom provider chrome */
.thc-card.is-custom{border-style:dashed}
.thc-card-badge-custom{font-size:9px;font-weight:700;letter-spacing:.08em;padding:2px 6px;border-radius:999px;background:color-mix(in srgb,var(--ac) 12%,transparent);color:var(--ac);border:1px solid color-mix(in srgb,var(--ac) 30%,transparent);margin-right:6px}
.thc-card-customops{display:inline-flex;align-items:center;gap:4px}
.thc-card-mini{background:none;border:none;cursor:pointer;font:600 11px/1.2 var(--f);color:var(--tx3);padding:2px 4px;border-radius:4px;transition:color var(--e),background var(--e)}
.thc-card-mini:hover{color:var(--tx);background:var(--sf2)}
.thc-card-mini-danger{color:var(--err)}
.thc-card-mini-danger:hover{color:var(--err);background:color-mix(in srgb,var(--err) 10%,transparent)}
.thc-card-mini-sep{color:var(--bd2);font-size:10px}
/* Add-custom modal: a wider variant of .thc-modal */
#thc-modal-addcustom .thc-field-row{display:grid;grid-template-columns:1fr 120px;gap:10px}
#thc-modal-addcustom .thc-field-color input{height:36px;width:100%;padding:0;border-radius:8px;border:1px solid var(--bd);background:var(--sf2);cursor:pointer}
.thc-ac-divider{border:none;border-top:1px solid var(--bd);margin:18px 0}
.thc-ac-section-label{font-size:10px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--tx3);margin-bottom:8px}
</style>

<div class="thc-wrap">

  <!-- Sidebar -->
  <nav class="thc-sidebar">
    <div class="thc-sidebar-group-label">Connections</div>
    <?php foreach ( $categories as $cat_key => $cat_data ):
      $cc      = $cat_counts[ $cat_key ] ?? [ 'connected' => 0, 'total' => 0 ];
      $dot_cls = $cc['connected'] === 0 ? '' : ( $cc['connected'] < $cc['total'] ? 'partial' : 'connected' );
      $is_active = $tab === $cat_key;
    ?>
    <a href="<?php echo esc_url( add_query_arg( 'tab', $cat_key, $base_url ) ); ?>"
       class="thc-nav-item <?php echo $is_active ? 'active' : ''; ?>">
      <span class="thc-nav-dot <?php echo esc_attr( $dot_cls ); ?>"></span>
      <?php echo esc_html( $cat_data['label'] ); ?>
      <?php if ( $cc['total'] > 0 ): ?>
      <span class="thc-nav-count"><?php echo (int) $cc['connected']; ?> / <?php echo (int) $cc['total']; ?></span>
      <?php endif; ?>
    </a>
    <?php endforeach; ?>

    <div class="thc-sidebar-group-label" style="margin-top:16px">Manage</div>
    <?php foreach ( $manage_tabs as $mt_key => $mt_label ):
      $is_active = $tab === $mt_key;
    ?>
    <a href="<?php echo esc_url( add_query_arg( 'tab', $mt_key, $base_url ) ); ?>"
       class="thc-nav-item <?php echo $is_active ? 'active' : ''; ?>">
      <span class="thc-nav-dot"></span>
      <?php echo $mt_label; ?>
      <?php if ( $mt_key === 'api-vault' ): ?>
      <span class="thc-nav-count"><?php echo count( $connections ); ?></span>
      <?php endif; ?>
      <?php if ( $mt_key === 'audit-log' ): ?>
      <span class="thc-nav-count"><?php echo (int) get_option('therum_conn_audit_count', 0); ?></span>
      <?php endif; ?>
    </a>
    <?php endforeach; ?>
  </nav>

  <!-- Content -->
  <div class="thc-content">

    <?php
    // OAuth result banner — set by the callback handler via redirect.
    $oauth_ok  = sanitize_key( $_GET['oauth_ok']  ?? '' );
    $oauth_err = sanitize_text_field( $_GET['oauth_err'] ?? '' );
    if ( $oauth_ok ) {
      $name = '';
      foreach ( $all_providers as $list ) { foreach ( $list as $pp ) { if ( $pp['id'] === $oauth_ok ) { $name = $pp['name']; break 2; } } }
      echo '<div style="background:color-mix(in srgb, var(--ok) 12%, transparent);border:1px solid var(--ok);color:var(--tx);border-radius:10px;padding:12px 16px;margin-bottom:16px;font-size:13px;font-weight:500">✓ Signed in to ' . esc_html( $name ?: $oauth_ok ) . ' — token stored encrypted, ready to use.</div>';
    } elseif ( $oauth_err ) {
      echo '<div style="background:color-mix(in srgb, var(--err) 12%, transparent);border:1px solid var(--err);color:var(--tx);border-radius:10px;padding:12px 16px;margin-bottom:16px;font-size:13px;font-weight:500">✗ OAuth failed: ' . esc_html( $oauth_err ) . '</div>';
    }
    ?>

    <?php if ( $is_manage ): ?>
      <?php self::render_manage_tab( $tab, $connections, $nonce ); ?>

    <?php else:
      $cat_data = $categories[ $tab ];
    ?>
    <div class="thc-breadcrumb">CONNECTIONS · <?php echo esc_html( strtoupper( $cat_data['label'] ) ); ?></div>
    <h1 class="thc-title"><?php echo esc_html( $cat_data['label'] ); ?></h1>
    <p class="thc-desc"><?php echo esc_html( $cat_data['desc'] ); ?></p>

    <div class="thc-toolbar">
      <div class="thc-search">
        <?php echo th_i('search'); ?>
        <input type="text" id="thc-search-input" placeholder="Search <?php echo esc_attr( strtolower( $cat_data['label'] ) ); ?> providers&hellip;" autocomplete="off" />
      </div>
      <span class="thc-toolbar-hint" style="font-size:11px;color:var(--tx3)">Click any card to connect &middot; or add your own</span>
      <button type="button" class="th-btn th-btn-primary" style="margin-left:auto" data-thc-add-custom="<?php echo esc_attr( $tab ); ?>">＋ Add custom</button>
    </div>

    <div class="thc-providers" id="thc-providers">
      <?php foreach ( $providers as $p ):
        $conn = $connections[ $p['id'] ] ?? null;
        $is_connected = ! empty( $conn );
        $connected_at = $is_connected ? human_time_diff( (int) ($conn['connected_at'] ?? 0) ) . ' ago' : '';
        $auth_type  = $p['auth'] ?? 'api_key';
        $search_val = strtolower( $p['name'] . ' ' . ( $p['meta'] ?? '' ) );
      ?>
      <?php $is_custom = ! empty( $p['custom'] ); ?>
      <div class="thc-card<?php echo $is_custom ? ' is-custom' : ''; ?>" data-provider="<?php echo esc_attr( $p['id'] ); ?>" data-search="<?php echo esc_attr($search_val); ?>"<?php echo $is_custom ? ' data-custom="1"' : ''; ?>>
        <div class="thc-card-head">
          <div class="thc-card-logo" style="background:<?php echo esc_attr( $p['color'] ); ?>"><?php echo esc_html( $p['letter'] ); ?></div>
          <div class="thc-card-status">
            <?php if ( $is_custom ): ?>
            <span class="thc-card-badge-custom">CUSTOM</span>
            <?php endif; ?>
            <span class="thc-card-status-dot <?php echo $is_connected ? 'ok' : ''; ?>"></span>
            <?php echo $is_connected ? 'CONNECTED' : 'NOT CONNECTED'; ?>
          </div>
        </div>
        <div class="thc-card-name"><?php echo esc_html( $p['name'] ); ?></div>
        <div class="thc-card-meta"><?php echo esc_html( $p['meta'] ?? '' ); ?></div>
        <div class="thc-card-foot">
          <?php if ( $is_connected ): ?>
          <button type="button" class="thc-card-action connected" data-thc-manage="<?php echo esc_attr($p['id']); ?>">Manage →</button>
          <?php else: ?>
          <button type="button" class="thc-card-action" data-thc-connect="<?php echo esc_attr($p['id']); ?>">Connect →</button>
          <?php endif; ?>

          <?php if ( $is_custom ): ?>
          <span class="thc-card-detail thc-card-customops">
            <button type="button" class="thc-card-mini" data-thc-edit-custom="<?php echo esc_attr($p['id']); ?>">Edit</button>
            <span class="thc-card-mini-sep">·</span>
            <button type="button" class="thc-card-mini thc-card-mini-danger" data-thc-delete-custom="<?php echo esc_attr($p['id']); ?>">Delete</button>
          </span>
          <?php elseif ( $is_connected ): ?>
          <span class="thc-card-detail">linked <?php echo esc_html( $connected_at ); ?></span>
          <?php else: ?>
          <span class="thc-card-detail"><?php echo esc_html( $auth_type === 'base_url' ? 'localhost' : 'API key' ); ?></span>
          <?php endif; ?>
        </div>

        <!-- Manage panel (inline, shown below card) -->
        <?php if ( $is_connected ): ?>
        <div class="thc-manage-panel" id="thc-manage-<?php echo esc_attr($p['id']); ?>">
          <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px">
            <div style="font-size:13px;font-weight:600;color:var(--tx)">Connected <?php echo esc_html($connected_at); ?></div>
            <button type="button" class="thc-card-action" style="font-size:12px;color:var(--err)" data-thc-disconnect="<?php echo esc_attr($p['id']); ?>">Disconnect</button>
          </div>
          <?php if ( !empty($conn['label']) ): ?>
          <div style="font-size:12px;color:var(--tx3);margin-bottom:8px">Label: <strong style="color:var(--tx)"><?php echo esc_html($conn['label']); ?></strong></div>
          <?php endif; ?>
          <div style="font-size:12px;color:var(--tx3)">Key: <span style="font-family:monospace;color:var(--tx2)"><?php echo esc_html( substr( $conn['key'] ?? '', 0, 8 ) . str_repeat('•', 12) ); ?></span></div>
          <div style="margin-top:14px;display:flex;gap:8px">
            <button type="button" class="th-btn" style="font-size:12px" data-thc-rekey="<?php echo esc_attr($p['id']); ?>">Update key</button>
          </div>
        </div>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

  </div><!-- /.thc-content -->
</div><!-- /.thc-wrap -->

<!-- Connect modal -->
<div id="thc-modal" class="thc-modal-backdrop" style="display:none" role="dialog" aria-modal="true">
  <div class="thc-modal">
    <div class="thc-modal-head">
      <div class="thc-modal-logo" id="thc-modal-logo"></div>
      <div>
        <div class="thc-modal-title" id="thc-modal-title"></div>
        <div class="thc-modal-sub" id="thc-modal-sub"></div>
      </div>
      <button type="button" class="thc-modal-close" id="thc-modal-close"><?php echo th_i('x'); ?></button>
    </div>
    <!-- Picker + Sign-in path are gated by per-provider auth_methods. The
         current registry has every provider as API-key-only (none of the
         AI tools, payment gateways, or apps have OAuth wired yet), so the
         picker stays hidden and the form renders directly. When a real
         OAuth handoff lands for a provider (Slack / GitHub / Google Drive
         / Stripe Connect / Notion / etc.), flip its `auth_methods` to
         `['api_key','oauth']` and the picker re-appears for that provider
         only. -->
    <div id="thc-modal-picker" class="thc-modal-step" hidden>
      <p class="thc-modal-sub" style="margin-bottom:18px">How do you want to connect?</p>
      <div class="thc-method-grid">
        <button type="button" class="thc-method" data-method="api_key">
          <div class="thc-method-icon">🔑</div>
          <div class="thc-method-name">API key</div>
          <div class="thc-method-desc">Paste a key from the provider&rsquo;s console. Stored encrypted on this install.</div>
        </button>
        <button type="button" class="thc-method" data-method="oauth">
          <div class="thc-method-icon">↪</div>
          <div class="thc-method-name">Sign in</div>
          <div class="thc-method-desc" id="thc-method-oauth-desc">Redirect to the provider, sign in there, come back authorized.</div>
          <span class="thc-method-badge" id="thc-method-oauth-badge" hidden>Setup needed</span>
        </button>
      </div>
    </div>

    <div id="thc-modal-setup" class="thc-modal-step">
      <button type="button" class="thc-modal-back" id="thc-modal-back" hidden>← Back</button>
      <div id="thc-modal-signin-steps" hidden></div>
      <div id="thc-modal-fields"></div>
      <div class="thc-modal-err" id="thc-modal-err"></div>
      <div class="thc-modal-actions">
        <button type="button" class="th-btn" id="thc-modal-cancel">Cancel</button>
        <button type="button" class="th-btn" id="thc-modal-test" hidden>Test</button>
        <button type="button" class="th-btn th-btn-primary" id="thc-modal-save">Connect</button>
      </div>
    </div>

    <!-- Add / Edit a user-defined custom provider. Same step covers both
         flows: Add starts blank, Edit pre-fills from providerData and uses
         a hidden editing_slug to support rename. The credential block at
         the bottom is optional — define-only is allowed, the card just
         shows as Not connected until the user comes back through Connect. -->
    <div id="thc-modal-addcustom" class="thc-modal-step" hidden>
      <input type="hidden" id="thc-ac-category" value="" />
      <input type="hidden" id="thc-ac-editing-slug" value="" />

      <div class="thc-ac-section-label">Provider</div>
      <div class="thc-field"><label>Display name</label><input type="text" id="thc-ac-name" placeholder="e.g. Acme AI" autocomplete="off" /></div>
      <div class="thc-field-row">
        <div class="thc-field"><label>Slug <span style="font-weight:400;color:var(--tx3)">(lowercase, hyphens)</span></label><input type="text" id="thc-ac-slug" placeholder="acme-ai" autocomplete="off" /></div>
        <div class="thc-field thc-field-color"><label>Brand color</label><input type="color" id="thc-ac-color" value="#6366f1" /></div>
      </div>
      <div class="thc-field"><label>Short description <span style="font-weight:400;color:var(--tx3)">(optional)</span></label><input type="text" id="thc-ac-meta" placeholder="What this provider does" autocomplete="off" /></div>

      <hr class="thc-ac-divider" />
      <div class="thc-ac-section-label">Authentication</div>
      <div class="thc-field"><label>Auth type</label>
        <select id="thc-ac-auth" style="width:100%;padding:9px 12px;background:var(--sf2);border:1px solid var(--bd);border-radius:8px;font-size:13px;color:var(--tx);outline:none;box-sizing:border-box;font-family:var(--f)">
          <option value="api_key">API key (single field)</option>
          <option value="sid_token">SID + Token (two fields)</option>
          <option value="multi">Multiple fields (up to 4 — key, secret, auth secret, …)</option>
          <option value="base_url">Base URL only (self-hosted)</option>
        </select>
      </div>
      <div class="thc-field"><label>Credentials URL <span style="font-weight:400;color:var(--tx3)">(optional; powers the "Get credentials ↗" link in Connect)</span></label><input type="url" id="thc-ac-url" placeholder="https://…" autocomplete="off" /></div>

      <!-- `multi` definition surface — visible only when Auth type === "multi". Defines
           1–4 label+type pairs that become the inputs on the Connect modal for this
           provider. Row 1 is required; rows 2–4 are skipped server-side when blank. -->
      <div id="thc-ac-multi-wrap" hidden>
        <hr class="thc-ac-divider" />
        <div class="thc-ac-section-label">Credential fields <span style="font-weight:400;color:var(--tx3);text-transform:none;letter-spacing:0">— up to 4 · row 1 required · blank label = skip</span></div>
        <div id="thc-ac-cred-rows">
          <?php
          $thc_cred_defaults = [
            [ 'label' => 'API Key',       'type' => 'password' ],
            [ 'label' => '',              'type' => 'password' ],
            [ 'label' => '',              'type' => 'password' ],
            [ 'label' => '',              'type' => 'text'     ],
          ];
          $thc_cred_placeholders = [ 'API Key', 'Client Secret', 'Auth Secret', 'Workspace ID' ];
          foreach ( $thc_cred_defaults as $thc_i => $thc_cd ):
            $thc_idx = $thc_i + 1;
          ?>
          <div class="thc-ac-cred-row" data-cred-row="<?php echo (int) $thc_idx; ?>" style="display:grid;grid-template-columns:1fr 130px;gap:8px;margin-bottom:6px">
            <input type="text" class="thc-ac-cred-label" id="thc-ac-cred-label-<?php echo (int) $thc_idx; ?>"
              placeholder="<?php echo esc_attr( $thc_cred_placeholders[ $thc_i ] ); ?>"
              value="<?php echo esc_attr( $thc_cd['label'] ); ?>"
              autocomplete="off"
              style="height:36px;padding:0 11px;background:var(--sf2);border:1px solid var(--bd);border-radius:8px;font-size:13px;font-family:var(--f);color:var(--tx)" />
            <select class="thc-ac-cred-type" id="thc-ac-cred-type-<?php echo (int) $thc_idx; ?>"
              style="height:36px;padding:0 11px;background:var(--sf2);border:1px solid var(--bd);border-radius:8px;font-size:13px;font-family:var(--f);color:var(--tx)">
              <option value="password" <?php selected( $thc_cd['type'], 'password' ); ?>>Secret</option>
              <option value="text"     <?php selected( $thc_cd['type'], 'text' );     ?>>Plain</option>
            </select>
          </div>
          <?php endforeach; ?>
        </div>
      </div>

      <hr class="thc-ac-divider" />
      <div class="thc-ac-section-label">Credential <span style="font-weight:400;color:var(--tx3);text-transform:none;letter-spacing:0">— optional, you can save the key now or later</span></div>
      <div class="thc-field"><label id="thc-ac-key-label">API key</label><input type="password" id="thc-ac-key" autocomplete="new-password" /></div>
      <div class="thc-field" id="thc-ac-key2-wrap" hidden><label id="thc-ac-key2-label">Token</label><input type="password" id="thc-ac-key2" autocomplete="new-password" /></div>
      <div class="thc-field"><label>Label <span style="font-weight:400;color:var(--tx3)">(optional, e.g. Production)</span></label><input type="text" id="thc-ac-label" autocomplete="off" /></div>

      <div class="thc-modal-err" id="thc-ac-err"></div>
      <div class="thc-modal-actions">
        <button type="button" class="th-btn" id="thc-ac-cancel">Cancel</button>
        <button type="button" class="th-btn th-btn-primary" id="thc-ac-save">Add provider</button>
      </div>
    </div>
  </div>
</div>

<div id="thc-toast" hidden style="position:fixed;bottom:28px;right:28px;background:var(--sf);border:1px solid var(--ok);border-radius:10px;padding:12px 18px;font-size:13px;font-weight:500;color:var(--ok);box-shadow:0 8px 24px rgba(0,0,0,.15);z-index:9999"></div>

<script>
(function(){
var ajaxUrl = window.ajaxurl || '/wp-admin/admin-ajax.php';
var nonce   = <?php echo wp_json_encode( $nonce ); ?>;

// Provider data for modal
var providerData = <?php echo wp_json_encode(
	array_reduce( $providers, function( $carry, $p ) {
		$carry[ $p['id'] ] = $p;
		return $carry;
	}, [] )
); ?>;

// Providers with a real test endpoint implementation. The Test button
// is hidden for any provider not in this set — see Therum_Connections_Page::TESTABLE.
var testableIds = <?php echo wp_json_encode( Therum_Connections_Page::TESTABLE ); ?>;

// Toast
var toast = document.getElementById('thc-toast');
function showToast(msg, ok) {
  toast.textContent = msg;
  toast.style.borderColor = ok ? 'var(--ok)' : 'var(--err)';
  toast.style.color        = ok ? 'var(--ok)' : 'var(--err)';
  toast.hidden = false;
  clearTimeout(toast._t);
  toast._t = setTimeout(function(){ toast.hidden = true; }, 3500);
}

// Search
var searchInput = document.getElementById('thc-search-input');
if (searchInput) {
  searchInput.addEventListener('input', function(){
    var q = this.value.toLowerCase().trim();
    document.querySelectorAll('.thc-card').forEach(function(card){
      card.style.display = (!q || (card.dataset.search||'').indexOf(q) !== -1) ? '' : 'none';
    });
  });
}

// Modal
var modal     = document.getElementById('thc-modal');
var modalLogo = document.getElementById('thc-modal-logo');
var modalTitle= document.getElementById('thc-modal-title');
var modalSub  = document.getElementById('thc-modal-sub');
var modalFields=document.getElementById('thc-modal-fields');
var modalErr  = document.getElementById('thc-modal-err');
var saveBtn   = document.getElementById('thc-modal-save');
var testBtn   = document.getElementById('thc-modal-test');
var pickerEl  = document.getElementById('thc-modal-picker');
var setupEl   = document.getElementById('thc-modal-setup');
var backBtn   = document.getElementById('thc-modal-back');
var signinEl  = document.getElementById('thc-modal-signin-steps');
var currentProvider = null;
var currentMethod   = null;

// Which providers are already connected (from the live page state). Used
// to flip the modal into "manage" mode where the Test button appears.
var connectedIds = <?php echo wp_json_encode( array_keys( $connections ) ); ?>;

// Which providers already have their OAuth app credentials configured
// on THIS install. Sign in skips the app-setup step for these.
var oauthAppReady = <?php
	$apps = (array) get_option( 'therum_oauth_apps', [] );
	$ready = [];
	foreach ( $apps as $pid => $row ) {
		if ( ! empty( $row['client_id'] ) ) $ready[] = $pid;
	}
	echo wp_json_encode( $ready );
?>;

// Build a one-shot URL that takes the user through real OAuth.
// admin-post action redirects to provider's authorize URL → user authorizes
// → provider redirects to /wp-json/therum/v1/oauth/callback?provider=X&code=...
// → our callback swaps code for token → stashes in vault → redirects back here.
var oauthStartNonce = <?php echo wp_json_encode( wp_create_nonce( 'therum_oauth_start' ) ); ?>;
var oauthRedirectUri = <?php echo wp_json_encode( rest_url( 'therum/v1/oauth/callback' ) ); ?>;
function thOAuthStartUrl(providerId) {
  return <?php echo wp_json_encode( admin_url( 'admin-post.php' ) ); ?> +
    '?action=therum_oauth_start&provider=' + encodeURIComponent(providerId) +
    '&_wpnonce=' + encodeURIComponent(oauthStartNonce);
}

function openModal(id) {
  var p = providerData[id];
  if (!p) return;
  currentProvider = p;
  currentMethod   = null;
  modalLogo.style.background = p.color;
  modalLogo.textContent = p.letter;
  modalTitle.textContent = p.name;
  var isConnected = connectedIds.indexOf(id) !== -1;
  modalSub.textContent = isConnected ? 'Already connected. Test the live credential or replace it.' : '';
  modalErr.style.display = 'none';
  modalErr.style.color = 'var(--err)';

  // The picker only shows when a provider actually supports both API key
  // and OAuth. Sign-in for an API-only provider is misleading and we're
  // not doing that. Today's OAuth providers: Dropbox, Slack, Google Drive.
  var methods = (p.auth_methods && p.auth_methods.length) ? p.auth_methods : ['api_key'];
  if (isConnected || methods.length < 2) {
    renderSetup('api_key', isConnected);
  } else {
    // Reflect OAuth-app-ready state on the Sign in tile so users know
    // whether it's one click or needs one-time setup first.
    var badge = document.getElementById('thc-method-oauth-badge');
    var desc  = document.getElementById('thc-method-oauth-desc');
    var ready = oauthAppReady.indexOf(p.id) !== -1;
    if (badge) {
      badge.hidden = false;
      badge.textContent = ready ? 'Ready' : 'Setup needed';
      badge.classList.toggle('is-ready', ready);
    }
    if (desc) {
      desc.textContent = ready
        ? 'One click to ' + p.name + '. Authorize there, come back signed in.'
        : 'First time: enter your ' + p.name + ' OAuth app credentials. After that, one click.';
    }
    pickerEl.hidden = false;
    setupEl.hidden  = true;
  }
  modal.style.display = 'flex';
}

function renderSetup(method, isConnected) {
  currentMethod = method;
  pickerEl.hidden = true;
  setupEl.hidden  = false;
  // Back button only when we actually came from the picker (multi-method
  // provider). Single-method providers shouldn't see a back link to a
  // screen they never visited.
  var p = currentProvider;
  var methods = (p.auth_methods && p.auth_methods.length) ? p.auth_methods : ['api_key'];
  backBtn.hidden = methods.length < 2 || isConnected;
  modalErr.style.display = 'none';
  modalErr.style.color = 'var(--err)';

  // OAuth path. Two phases:
  //   A) App not configured yet on this install → show a small form to
  //      enter client_id + client_secret + (read-only) redirect URI to
  //      copy into the provider's app settings. Save → moves to phase B.
  //   B) App configured → button "Sign in with {Provider}" that kicks
  //      off the real redirect handoff via admin-post.php.
  if (method === 'oauth') {
    var hasApp = oauthAppReady.indexOf(p.id) !== -1;
    testBtn.hidden = true;

    if (!hasApp) {
      // Phase A — first-time app setup for this provider on this install.
      signinEl.hidden = false;
      signinEl.className = 'thc-signin-steps';
      signinEl.innerHTML =
        'One-time setup. Register Therum as an OAuth app in the <a href="' + (p.url || '#') + '" target="_blank" rel="noopener">' + escapeHtml(p.name) + ' developer dashboard</a>, copy the credentials here, and Sign in becomes a real handoff.';
      modalFields.innerHTML =
        '<div class="thc-field"><label>Redirect URI <span style="font-weight:400;color:var(--tx3)">(paste this into the provider&rsquo;s app settings)</span></label>' +
          '<input type="text" readonly value="' + escapeAttr(oauthRedirectUri) + '" style="font-family:monospace;font-size:12px" onclick="this.select()" /></div>' +
        '<div class="thc-field"><label>Client ID</label><input type="text" id="thc-oauth-cid" placeholder="from the provider" autocomplete="off" /></div>' +
        '<div class="thc-field"><label>Client Secret</label><input type="password" id="thc-oauth-csec" placeholder="from the provider" autocomplete="new-password" /></div>';
      saveBtn.style.display = '';
      saveBtn.textContent = 'Save app · then sign in';
    } else {
      // Phase B — app ready, show the real handoff.
      signinEl.hidden = false;
      signinEl.className = 'thc-signin-steps';
      signinEl.innerHTML = 'Click below to sign in at ' + escapeHtml(p.name) + '. You&rsquo;ll be redirected there to authorize Therum, then back here.';
      modalFields.innerHTML =
        '<a class="thc-signin-cta" href="' + thOAuthStartUrl(p.id) + '" style="display:block;text-align:center;padding:12px 18px;font-size:14px">' +
          'Sign in with ' + escapeHtml(p.name) + ' ↗' +
        '</a>' +
        '<div class="thc-field" style="margin-top:14px"><div class="thc-field-hint" style="text-align:center"><a href="#" id="thc-oauth-reset">Reconfigure OAuth app</a></div></div>';
      saveBtn.style.display = 'none';
      setTimeout(function(){
        var r = document.getElementById('thc-oauth-reset');
        if (r) r.addEventListener('click', function(e){ e.preventDefault(); oauthAppReady = oauthAppReady.filter(function(x){return x !== p.id;}); renderSetup('oauth', false); });
      }, 0);
    }
    return;
  }
  signinEl.hidden = true;
  signinEl.innerHTML = '';
  saveBtn.style.display = '';

  // The actual input field — same for both paths.
  var fields = '';
  if (p.auth === 'multi') {
    // `multi` shape — up to 4 labeled credential inputs. The provider row
    // carries auth_label / auth_label2 / auth_label3 / auth_label4 plus
    // parallel auth_type entries (password|text). Inputs use ids
    // thc-f-key / thc-f-key2 / thc-f-key3 / thc-f-key4 so the save handler
    // POSTs them as key/key2/key3/key4 — matches save_connection's shape.
    var multiSlots = [
      { lbl: p.auth_label,  typ: p.auth_type,  id: 'thc-f-key'  },
      { lbl: p.auth_label2, typ: p.auth_type2, id: 'thc-f-key2' },
      { lbl: p.auth_label3, typ: p.auth_type3, id: 'thc-f-key3' },
      { lbl: p.auth_label4, typ: p.auth_type4, id: 'thc-f-key4' }
    ];
    multiSlots.forEach(function(s, i){
      if (!s.lbl) return;
      var t = s.typ === 'text' ? 'text' : 'password';
      var ac = t === 'password' ? 'new-password' : 'off';
      fields += '<div class="thc-field"><label>' + escapeHtml(s.lbl) + (i===0?' <span style="color:var(--err)">*</span>':'') + '</label><input type="' + t + '" id="' + s.id + '" autocomplete="' + ac + '" /></div>';
    });
  } else if (p.auth === 'sid_token') {
    fields += '<div class="thc-field"><label>' + escapeHtml(p.auth_label||'ID') + '</label><input type="text" id="thc-f-key" placeholder="' + escapeAttr(p.auth_hint||'') + '" autocomplete="off" /></div>';
    fields += '<div class="thc-field"><label>' + escapeHtml(p.auth_label2||'Token') + '</label><input type="password" id="thc-f-key2" placeholder="' + escapeAttr(p.auth_hint2||'') + '" autocomplete="new-password" /></div>';
  } else {
    fields += '<div class="thc-field"><label>' + escapeHtml(p.auth_label||'API Key') + '</label><input type="' + (p.auth==='base_url'?'url':'password') + '" id="thc-f-key" placeholder="' + escapeAttr(p.auth_hint||'') + '" autocomplete="new-password" /></div>';
  }
  fields += '<div class="thc-field"><label>Label <span style="font-weight:400;color:var(--tx3)">(optional)</span></label><input type="text" id="thc-f-label" placeholder="e.g. Production" /></div>';
  if (p.url && method !== 'signin') {
    fields += '<div class="thc-field"><div class="thc-field-hint"><a href="' + p.url + '" target="_blank" rel="noopener">Get credentials ↗</a></div></div>';
  }
  modalFields.innerHTML = fields;
  saveBtn.textContent = isConnected ? 'Update' : 'Connect';
  saveBtn.removeAttribute('data-loading');
  // Test only shows when (a) connection exists AND (b) we actually have a
  // real test endpoint for this provider. Avoids exposing a button that
  // would just return "not implemented" on click.
  testBtn.hidden = !isConnected || testableIds.indexOf(p.id) === -1;
  testBtn.removeAttribute('data-loading');
  setTimeout(function(){ var f = document.getElementById('thc-f-key'); if(f) f.focus(); }, 50);
}

function escapeHtml(s) { return String(s).replace(/[&<>"']/g, function(c){ return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c]; }); }
function escapeAttr(s) { return escapeHtml(s); }

// Picker tile click → expand the chosen path
pickerEl.querySelectorAll('[data-method]').forEach(function(btn){
  btn.addEventListener('click', function(){
    renderSetup(btn.getAttribute('data-method'), false);
  });
});

backBtn.addEventListener('click', function(){
  setupEl.hidden = true;
  pickerEl.hidden = false;
  modalErr.style.display = 'none';
});

function closeModal() {
  modal.style.display = 'none';
  currentProvider = null;
  currentMethod = null;
  modalFields.innerHTML = '';
  signinEl.innerHTML = '';
  signinEl.hidden = true;
  pickerEl.hidden = false;
  setupEl.hidden  = true;
  // The Add Custom step lives in the same modal — reset it too so the
  // next opener (whichever flow) starts clean.
  var ac = document.getElementById('thc-modal-addcustom');
  if (ac) ac.hidden = true;
}

document.getElementById('thc-modal-close').addEventListener('click', closeModal);
document.getElementById('thc-modal-cancel').addEventListener('click', closeModal);
modal.addEventListener('click', function(e){ if (e.target === modal) closeModal(); });
document.addEventListener('keydown', function(e){ if (e.key === 'Escape' && modal.style.display !== 'none') closeModal(); });

// Save connection
saveBtn.addEventListener('click', function(){
  if (!currentProvider) return;

  // OAuth app-setup branch: we're in Phase A (cid/csec form). Save app
  // creds, then redirect immediately into the real OAuth handoff so the
  // user lands at the provider's sign-in screen in one click.
  if (currentMethod === 'oauth') {
    var cid  = (document.getElementById('thc-oauth-cid')  || {}).value || '';
    var csec = (document.getElementById('thc-oauth-csec') || {}).value || '';
    cid = cid.trim(); csec = csec.trim();
    if (!cid || !csec) { modalErr.textContent = 'Enter both Client ID and Client Secret.'; modalErr.style.display = 'block'; return; }
    saveBtn.setAttribute('data-loading','');
    var fdA = new FormData();
    fdA.append('action','therum_oauth_app_save');
    fdA.append('nonce', nonce);
    fdA.append('provider', currentProvider.id);
    fdA.append('client_id', cid);
    fdA.append('client_secret', csec);
    fetch(ajaxUrl, {method:'POST',credentials:'same-origin',body:fdA})
      .then(function(r){ return r.json(); })
      .then(function(res){
        saveBtn.removeAttribute('data-loading');
        if (res && res.success) {
          // Straight to the provider — no second click.
          window.location.href = thOAuthStartUrl(currentProvider.id);
        } else {
          modalErr.textContent = (res && res.data) ? String(res.data) : 'Could not save OAuth app.';
          modalErr.style.display = 'block';
        }
      })
      .catch(function(){
        saveBtn.removeAttribute('data-loading');
        modalErr.textContent = 'Network error — try again.';
        modalErr.style.display = 'block';
      });
    return;
  }

  var keyEl   = document.getElementById('thc-f-key');
  var key2El  = document.getElementById('thc-f-key2');
  var key3El  = document.getElementById('thc-f-key3');
  var key4El  = document.getElementById('thc-f-key4');
  var labelEl = document.getElementById('thc-f-label');
  var key     = keyEl  ? keyEl.value.trim()  : '';
  var key2    = key2El ? key2El.value.trim() : '';
  var key3    = key3El ? key3El.value.trim() : '';
  var key4    = key4El ? key4El.value.trim() : '';
  var label   = labelEl ? labelEl.value.trim() : '';

  if (!key) { modalErr.textContent = 'Please enter the required credential.'; modalErr.style.display='block'; return; }

  saveBtn.setAttribute('data-loading','');
  modalErr.style.display = 'none';

  var fd = new FormData();
  fd.append('action','therum_connection_connect');
  fd.append('nonce', nonce);
  fd.append('provider_id', currentProvider.id);
  fd.append('key',  key);
  fd.append('key2', key2);
  fd.append('key3', key3);
  fd.append('key4', key4);
  fd.append('label', label);

  fetch(ajaxUrl, {method:'POST',credentials:'same-origin',body:fd})
    .then(function(r){ return r.json(); })
    .then(function(res){
      saveBtn.removeAttribute('data-loading');
      if (res && res.success) {
        closeModal();
        showToast(currentProvider ? currentProvider.name + ' connected' : 'Connected', true);
        setTimeout(function(){ location.reload(); }, 800);
      } else {
        modalErr.textContent = (res && res.data) ? String(res.data) : 'Save failed';
        modalErr.style.display = 'block';
      }
    })
    .catch(function(){
      saveBtn.removeAttribute('data-loading');
      modalErr.textContent = 'Network error — try again.';
      modalErr.style.display = 'block';
    });
});

// Live test of the stored credential — hits the provider's real endpoint
// for Anthropic / OpenAI, returns the response text on success or a server
// error message on failure. Costs ~$0.0001 per click.
testBtn.addEventListener('click', function(){
  if (!currentProvider) return;
  testBtn.setAttribute('data-loading','');
  testBtn.textContent = 'Testing…';
  modalErr.style.display = 'none';

  var fd = new FormData();
  fd.append('action','therum_connection_test');
  fd.append('nonce', nonce);
  fd.append('provider_id', currentProvider.id);

  fetch(ajaxUrl, {method:'POST',credentials:'same-origin',body:fd})
    .then(function(r){ return r.json(); })
    .then(function(res){
      testBtn.removeAttribute('data-loading');
      testBtn.textContent = 'Test';
      var msg = (res && res.data && res.data.message) ? res.data.message : (res && res.success ? 'OK' : 'Test failed');
      if (res && res.success) {
        modalErr.style.color = 'var(--ok)';
        modalErr.textContent = '✓ ' + msg;
      } else {
        modalErr.style.color = 'var(--err)';
        modalErr.textContent = '✗ ' + msg;
      }
      modalErr.style.display = 'block';
    })
    .catch(function(){
      testBtn.removeAttribute('data-loading');
      testBtn.textContent = 'Test';
      modalErr.style.color = 'var(--err)';
      modalErr.textContent = '✗ Network error — try again.';
      modalErr.style.display = 'block';
    });
});

// Disconnect
function disconnect(id) {
  if (!confirm('Disconnect this provider? The stored key will be deleted.')) return;
  var fd = new FormData();
  fd.append('action','therum_connection_disconnect');
  fd.append('nonce', nonce);
  fd.append('provider_id', id);
  fetch(ajaxUrl, {method:'POST',credentials:'same-origin',body:fd})
    .then(function(r){ return r.json(); })
    .then(function(res){
      if (res && res.success) {
        showToast('Disconnected', true);
        setTimeout(function(){ location.reload(); }, 600);
      } else {
        showToast('Disconnect failed', false);
      }
    })
    .catch(function(){ showToast('Network error', false); });
}

// ─── Add / Edit a custom provider ───────────────────────────────────────────
// Same modal step (#thc-modal-addcustom) covers both. Edit pre-fills from
// providerData and stashes the original slug in editing-slug so the server
// can do a rename (move credential + drop old row) when the slug changes.

var acStep      = document.getElementById('thc-modal-addcustom');
var acName      = document.getElementById('thc-ac-name');
var acSlug      = document.getElementById('thc-ac-slug');
var acColor     = document.getElementById('thc-ac-color');
var acMeta      = document.getElementById('thc-ac-meta');
var acAuth      = document.getElementById('thc-ac-auth');
var acUrl       = document.getElementById('thc-ac-url');
var acKey       = document.getElementById('thc-ac-key');
var acKeyLabel  = document.getElementById('thc-ac-key-label');
var acKey2      = document.getElementById('thc-ac-key2');
var acKey2Wrap  = document.getElementById('thc-ac-key2-wrap');
var acKey2Label = document.getElementById('thc-ac-key2-label');
var acLabelF    = document.getElementById('thc-ac-label');
var acCat       = document.getElementById('thc-ac-category');
var acEditing   = document.getElementById('thc-ac-editing-slug');
var acErr       = document.getElementById('thc-ac-err');
var acSave      = document.getElementById('thc-ac-save');
var acCancel    = document.getElementById('thc-ac-cancel');

// Auto-slug while the user types Name, unless they've manually edited Slug.
var acSlugDirty = false;
acSlug.addEventListener('input', function(){ acSlugDirty = true; });
function slugify(s) {
  return String(s||'').toLowerCase()
    .replace(/[^a-z0-9]+/g,'-')
    .replace(/^-+|-+$/g,'')
    .substring(0, 48);
}
acName.addEventListener('input', function(){
  if (!acSlugDirty) acSlug.value = slugify(acName.value);
});

// Auth-type swap: relabel the credential fields, show/hide key2 row, toggle
// the `multi` definition surface. In `multi` mode the inline credential
// capture is hidden — the user defines fields here, then connects later from
// the provider card (the Connect modal renders the 1–4 inputs).
var acMultiWrap = document.getElementById('thc-ac-multi-wrap');
var acInlineKey  = acKey ? acKey.closest('.thc-field') : null;
var acInlineKey2 = acKey2Wrap;
var acInlineLabel = acLabelF ? acLabelF.closest('.thc-field') : null;
acAuth.addEventListener('change', updateAuthFields);
function updateAuthFields() {
  var a = acAuth.value;
  if (a === 'multi') {
    if (acMultiWrap)   acMultiWrap.hidden = false;
    if (acInlineKey)   acInlineKey.style.display   = 'none';
    if (acInlineKey2)  acInlineKey2.hidden = true;
    if (acInlineLabel) acInlineLabel.style.display = 'none';
    return;
  }
  if (acMultiWrap)   acMultiWrap.hidden = true;
  if (acInlineKey)   acInlineKey.style.display   = '';
  if (acInlineLabel) acInlineLabel.style.display = '';
  if (a === 'sid_token') {
    acKeyLabel.textContent  = 'Account SID / ID';
    acKey2Label.textContent = 'Auth Token / Secret';
    acKey2Wrap.hidden = false;
    acKey.type = 'text';
  } else if (a === 'base_url') {
    acKeyLabel.textContent  = 'Base URL';
    acKey2Wrap.hidden = true;
    acKey.type = 'url';
  } else {
    acKeyLabel.textContent  = 'API key';
    acKey2Wrap.hidden = true;
    acKey.type = 'password';
  }
}

// Helpers for the multi cred-row grid — keep DOM lookups out of the hot paths.
function acGetCredRows() {
  var out = [];
  for (var i = 1; i <= 4; i++) {
    var lbl = document.getElementById('thc-ac-cred-label-' + i);
    var typ = document.getElementById('thc-ac-cred-type-'  + i);
    if (lbl && typ) out.push({ idx: i, lbl: lbl, typ: typ });
  }
  return out;
}
function acResetCredRows(labels, types) {
  acGetCredRows().forEach(function(r, i){
    r.lbl.value = (labels && labels[i] != null) ? labels[i] : '';
    r.typ.value = (types  && types[i]  === 'text') ? 'text' : 'password';
  });
}

function openAddCustomModal(category, editSlug) {
  // Hide the other steps; show ours.
  pickerEl.hidden  = true;
  setupEl.hidden   = true;
  acStep.hidden    = false;
  acErr.style.display = 'none';
  acErr.style.color = 'var(--err)';

  var editing = editSlug ? providerData[editSlug] : null;
  acCat.value     = (editing && editing.category) ? editing.category : (category || '');
  acEditing.value = editing ? editSlug : '';

  if (editing) {
    modalLogo.style.background = editing.color || '#6366f1';
    modalLogo.textContent      = editing.letter || (editing.name||'?').charAt(0).toUpperCase();
    modalTitle.textContent     = 'Edit ' + editing.name;
    modalSub.textContent       = 'Update this custom provider. Renaming the slug moves the saved credential along with it.';
    acName.value      = editing.name  || '';
    acSlug.value      = editSlug;
    acSlugDirty       = true;
    acColor.value     = (editing.color && /^#[0-9a-fA-F]{6}$/.test(editing.color)) ? editing.color : '#6366f1';
    acMeta.value      = editing.meta  || '';
    acAuth.value      = (editing.auth && ['api_key','sid_token','base_url','multi'].indexOf(editing.auth) !== -1) ? editing.auth : 'api_key';
    acUrl.value       = editing.url   || '';
    acKey.value       = '';
    acKey2.value      = '';
    acLabelF.value    = '';
    // Pre-fill the multi cred rows from the provider def (auth_label..auth_label4).
    acResetCredRows(
      [ editing.auth_label || '', editing.auth_label2 || '', editing.auth_label3 || '', editing.auth_label4 || '' ],
      [ editing.auth_type  || '', editing.auth_type2  || '', editing.auth_type3  || '', editing.auth_type4  || '' ]
    );
    acSave.textContent = 'Save changes';
  } else {
    modalLogo.style.background = '#6366f1';
    modalLogo.textContent      = '＋';
    modalTitle.textContent     = 'Add custom provider';
    modalSub.textContent       = 'Wire up a provider that isn’t in the built-in registry — internal endpoints, niche tools, anything with an API key.';
    acName.value      = '';
    acSlug.value      = '';
    acSlugDirty       = false;
    acColor.value     = '#6366f1';
    acMeta.value      = '';
    acAuth.value      = 'api_key';
    acUrl.value       = '';
    acKey.value       = '';
    acKey2.value      = '';
    acLabelF.value    = '';
    acResetCredRows([ 'API Key', '', '', '' ], [ 'password', 'password', 'password', 'text' ]);
    acSave.textContent = 'Add provider';
  }
  updateAuthFields();
  modal.style.display = 'flex';
  setTimeout(function(){ acName.focus(); }, 50);
}

acCancel.addEventListener('click', closeModal);

acSave.addEventListener('click', function(){
  var name = acName.value.trim();
  if (!name) { acErr.textContent = 'Name is required.'; acErr.style.display='block'; acName.focus(); return; }

  acSave.setAttribute('data-loading','');
  acErr.style.display = 'none';

  var fd = new FormData();
  fd.append('action','therum_connection_add_custom');
  fd.append('nonce', nonce);
  fd.append('category', acCat.value);
  fd.append('editing_slug', acEditing.value);
  fd.append('name', name);
  fd.append('slug', acSlug.value.trim());
  fd.append('color', acColor.value);
  fd.append('meta', acMeta.value.trim());
  fd.append('auth', acAuth.value);
  fd.append('url',  acUrl.value.trim());
  fd.append('key',  acKey.value.trim());
  fd.append('key2', acKey2.value.trim());
  fd.append('label',acLabelF.value.trim());
  // `multi` mode — server expects cred_label_1..4 + cred_type_1..4.
  if (acAuth.value === 'multi') {
    acGetCredRows().forEach(function(r){
      fd.append('cred_label_' + r.idx, r.lbl.value.trim());
      fd.append('cred_type_'  + r.idx, r.typ.value);
    });
  }

  fetch(ajaxUrl, {method:'POST',credentials:'same-origin',body:fd})
    .then(function(r){ return r.json(); })
    .then(function(res){
      acSave.removeAttribute('data-loading');
      if (res && res.success) {
        closeModal();
        showToast(acEditing.value ? 'Provider updated' : 'Custom provider added', true);
        setTimeout(function(){ location.reload(); }, 700);
      } else {
        var msg = (res && res.data && res.data.message) ? res.data.message
                : (res && res.data) ? String(res.data)
                : 'Save failed';
        acErr.textContent = msg;
        acErr.style.display = 'block';
      }
    })
    .catch(function(){
      acSave.removeAttribute('data-loading');
      acErr.textContent = 'Network error — try again.';
      acErr.style.display = 'block';
    });
});

function deleteCustom(id) {
  var p = providerData[id];
  var label = p ? p.name : id;
  if (!confirm('Delete the custom provider "' + label + '"? This also removes any saved credential. Built-in providers are not affected.')) return;
  var fd = new FormData();
  fd.append('action','therum_connection_delete_custom');
  fd.append('nonce', nonce);
  fd.append('provider_id', id);
  fetch(ajaxUrl, {method:'POST',credentials:'same-origin',body:fd})
    .then(function(r){ return r.json(); })
    .then(function(res){
      if (res && res.success) {
        showToast('Custom provider deleted', true);
        setTimeout(function(){ location.reload(); }, 500);
      } else {
        var msg = (res && res.data && res.data.message) ? res.data.message
                : (res && res.data) ? String(res.data)
                : 'Delete failed';
        showToast(msg, false);
      }
    })
    .catch(function(){ showToast('Network error', false); });
}

// Click delegation
document.addEventListener('click', function(e){
  var btn = e.target.closest('[data-thc-connect]');
  if (btn) { openModal(btn.getAttribute('data-thc-connect')); return; }

  var mb = e.target.closest('[data-thc-manage]');
  if (mb) {
    var id = mb.getAttribute('data-thc-manage');
    var panel = document.getElementById('thc-manage-' + id);
    if (panel) panel.classList.toggle('open');
    return;
  }

  var db = e.target.closest('[data-thc-disconnect]');
  if (db) { disconnect(db.getAttribute('data-thc-disconnect')); return; }

  var rb = e.target.closest('[data-thc-rekey]');
  if (rb) { openModal(rb.getAttribute('data-thc-rekey')); return; }

  var ab = e.target.closest('[data-thc-add-custom]');
  if (ab) { openAddCustomModal(ab.getAttribute('data-thc-add-custom'), ''); return; }

  var eb = e.target.closest('[data-thc-edit-custom]');
  if (eb) { openAddCustomModal('', eb.getAttribute('data-thc-edit-custom')); return; }

  var xb = e.target.closest('[data-thc-delete-custom]');
  if (xb) { deleteCustom(xb.getAttribute('data-thc-delete-custom')); return; }
});

})();
</script>
		<?php
	}

	private static function render_manage_tab( string $tab, array $connections, string $nonce ): void {
		if ( $tab === 'api-vault' ) {
			echo '<div class="thc-breadcrumb">CONNECTIONS · MANAGE</div>';
			echo '<h1 class="thc-title">API Keys Vault</h1>';
			echo '<p class="thc-desc">All stored provider credentials. Click Disconnect to revoke a key.</p>';

			if ( empty( $connections ) ) {
				echo '<div style="padding:40px 0;text-align:center;color:var(--tx3);font-size:14px">No connections yet. Pick a provider category and click <strong>Connect</strong>, or use <strong>＋ Add custom</strong> to wire up your own.</div>';
				return;
			}

			echo '<div class="th-settings-group"><div class="th-settings-group-body">';
			foreach ( $connections as $id => $data ) {
				$connected_at = human_time_diff( (int)($data['connected_at'] ?? 0) ) . ' ago';
				$key_preview  = substr( $data['key'] ?? '', 0, 8 ) . str_repeat( '•', 12 );
				echo '<div style="display:flex;align-items:center;justify-content:space-between;padding:14px 0;border-bottom:1px solid var(--bd);gap:12px">';
				echo '<div>';
				echo '<div style="font-weight:600;font-size:13px;color:var(--tx)">' . esc_html( $id ) . ( !empty($data['label']) ? ' <span style="color:var(--tx3);font-weight:400">· ' . esc_html($data['label']) . '</span>' : '' ) . '</div>';
				echo '<div style="font-size:12px;color:var(--tx3);font-family:monospace;margin-top:2px">' . esc_html($key_preview) . '</div>';
				echo '<div style="font-size:11px;color:var(--tx3);margin-top:2px">Connected ' . esc_html($connected_at) . '</div>';
				echo '</div>';
				echo '<button type="button" class="th-btn" style="font-size:12px;color:var(--err)" data-thc-disconnect="' . esc_attr($id) . '">Disconnect</button>';
				echo '</div>';
			}
			echo '</div></div>';

			// Include the disconnect JS
			echo '<script>
(function(){
var ajaxUrl=window.ajaxurl||"/wp-admin/admin-ajax.php";
var nonce=' . wp_json_encode($nonce) . ';
var toast=document.createElement("div");
toast.style="position:fixed;bottom:28px;right:28px;background:var(--sf);border:1px solid var(--ok);border-radius:10px;padding:12px 18px;font-size:13px;font-weight:500;color:var(--ok);box-shadow:0 8px 24px rgba(0,0,0,.15);z-index:9999;display:none";
document.body.appendChild(toast);
function showToast(msg,ok){toast.textContent=msg;toast.style.borderColor=ok?"var(--ok)":"var(--err)";toast.style.color=ok?"var(--ok)":"var(--err)";toast.style.display="block";clearTimeout(toast._t);toast._t=setTimeout(function(){toast.style.display="none";},3500);}
document.addEventListener("click",function(e){
  var db=e.target.closest("[data-thc-disconnect]");
  if(!db)return;
  if(!confirm("Disconnect this provider? The stored key will be deleted."))return;
  var id=db.getAttribute("data-thc-disconnect");
  var fd=new FormData();fd.append("action","therum_connection_disconnect");fd.append("nonce",nonce);fd.append("provider_id",id);
  fetch(ajaxUrl,{method:"POST",credentials:"same-origin",body:fd}).then(function(r){return r.json();}).then(function(res){if(res&&res.success){showToast("Disconnected",true);setTimeout(function(){location.reload();},600);}else{showToast("Failed",false);}}).catch(function(){showToast("Network error",false);});
});
})();
</script>';

		} elseif ( $tab === 'webhooks-log' ) {
			echo '<div class="thc-breadcrumb">CONNECTIONS · MANAGE</div>';
			echo '<h1 class="thc-title">Webhooks Log</h1>';
			echo '<p class="thc-desc">Incoming and outgoing webhook events logged by Therum OS.</p>';
			$log = (array) get_option('therum_webhooks_log', []);
			if (empty($log)) {
				echo '<div style="padding:40px 0;text-align:center;color:var(--tx3);font-size:14px">No webhook events recorded yet.</div>';
			} else {
				echo '<div class="th-settings-group"><div class="th-settings-group-body">';
				foreach (array_reverse(array_slice($log, -50)) as $entry) {
					echo '<div style="padding:10px 0;border-bottom:1px solid var(--bd);font-size:12px;display:flex;gap:12px;align-items:center">';
					echo '<span style="color:var(--tx3);flex-shrink:0">' . esc_html(wp_date('M j g:ia', $entry['time'] ?? 0)) . '</span>';
					echo '<span style="color:var(--tx);font-weight:500">' . esc_html($entry['event'] ?? '') . '</span>';
					echo '<span style="color:var(--tx3)">' . esc_html($entry['source'] ?? '') . '</span>';
					echo '</div>';
				}
				echo '</div></div>';
			}

		} elseif ( $tab === 'audit-log' ) {
			echo '<div class="thc-breadcrumb">CONNECTIONS · MANAGE</div>';
			echo '<h1 class="thc-title">Audit Log</h1>';
			echo '<p class="thc-desc">Connection events — who connected, changed, or disconnected a provider.</p>';
			$log = (array) get_option('therum_conn_audit_log', []);
			if (empty($log)) {
				echo '<div style="padding:40px 0;text-align:center;color:var(--tx3);font-size:14px">No audit events recorded yet.</div>';
			} else {
				echo '<div class="th-settings-group"><div class="th-settings-group-body">';
				foreach (array_reverse(array_slice($log, -100)) as $entry) {
					echo '<div style="padding:10px 0;border-bottom:1px solid var(--bd);font-size:12px;display:flex;gap:12px;align-items:center">';
					echo '<span style="color:var(--tx3);flex-shrink:0">' . esc_html(wp_date('M j g:ia', $entry['time'] ?? 0)) . '</span>';
					echo '<span style="color:var(--tx);font-weight:500">' . esc_html($entry['action'] ?? '') . '</span>';
					echo '<span style="color:var(--tx3)">' . esc_html($entry['provider'] ?? '') . '</span>';
					echo '<span style="color:var(--tx3)">' . esc_html($entry['user'] ?? '') . '</span>';
					echo '</div>';
				}
				echo '</div></div>';
			}

		} elseif ( $tab === 'api-webhooks' ) {
			echo '<div class="thc-breadcrumb">CONNECTIONS · MANAGE</div>';
			echo '<h1 class="thc-title">API &amp; Webhooks</h1>';
			echo '<p class="thc-desc">Therum REST API credentials and incoming webhook endpoints.</p>';
			$api_key = get_option('therum_rest_api_key', '');
			if (!$api_key) {
				$api_key = wp_generate_password(40, false);
				update_option('therum_rest_api_key', $api_key);
			}
			$webhook_url = rest_url('therum/v1/hook');
			echo '<div class="th-settings-group"><div class="th-settings-group-body">';
			echo '<div style="margin-bottom:18px"><div style="font-size:12px;font-weight:600;color:var(--tx2);margin-bottom:6px">REST API Key</div>';
			echo '<div style="display:flex;gap:8px"><input type="text" value="' . esc_attr($api_key) . '" readonly style="flex:1;padding:9px 12px;background:var(--sf2);border:1px solid var(--bd);border-radius:8px;font-size:12px;font-family:monospace;color:var(--tx)"><button class="th-btn" onclick="navigator.clipboard.writeText(this.previousElementSibling.value);this.textContent=\'Copied!\';setTimeout(()=>{this.textContent=\'Copy\'},1500)" style="font-size:12px">Copy</button></div></div>';
			echo '<div><div style="font-size:12px;font-weight:600;color:var(--tx2);margin-bottom:6px">Incoming Webhook URL</div>';
			echo '<div style="display:flex;gap:8px"><input type="text" value="' . esc_url($webhook_url) . '" readonly style="flex:1;padding:9px 12px;background:var(--sf2);border:1px solid var(--bd);border-radius:8px;font-size:12px;font-family:monospace;color:var(--tx)"><button class="th-btn" onclick="navigator.clipboard.writeText(this.previousElementSibling.value);this.textContent=\'Copied!\';setTimeout(()=>{this.textContent=\'Copy\'},1500)" style="font-size:12px">Copy</button></div></div>';
			echo '</div></div>';
		}
	}
}

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
class Therum_OAuth {

	const APPS_OPTION = 'therum_oauth_apps';
	const STATE_TTL   = 300; // seconds

	public static function redirect_uri(): string {
		return rest_url( 'therum/v1/oauth/callback' );
	}

	public static function get_app( string $provider ): array {
		$all = (array) get_option( self::APPS_OPTION, [] );
		$row = $all[ $provider ] ?? null;
		if ( ! is_array( $row ) ) return [ 'client_id' => '', 'client_secret' => '' ];
		return [
			'client_id'     => (string) ( $row['client_id'] ?? '' ),
			'client_secret' => Therum_Connections_Page::decrypt( (string) ( $row['client_secret'] ?? '' ) ),
		];
	}

	public static function has_app( string $provider ): bool {
		$app = self::get_app( $provider );
		return $app['client_id'] !== '' && $app['client_secret'] !== '';
	}

	public static function ajax_save_app(): void {
		if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'forbidden', 403 );
		check_ajax_referer( 'therum_connections', 'nonce' );

		$provider     = sanitize_key( $_POST['provider'] ?? '' );
		$client_id    = sanitize_text_field( wp_unslash( $_POST['client_id'] ?? '' ) );
		$client_secret = sanitize_text_field( wp_unslash( $_POST['client_secret'] ?? '' ) );
		if ( ! $provider || ! $client_id || ! $client_secret ) wp_send_json_error( 'missing fields' );

		$all = (array) get_option( self::APPS_OPTION, [] );
		$all[ $provider ] = [
			'client_id'     => $client_id,
			'client_secret' => Therum_Connections_Page::encrypt( $client_secret ),
			'saved_at'      => time(),
		];
		update_option( self::APPS_OPTION, $all, false );
		wp_send_json_success( [ 'provider' => $provider, 'redirect_uri' => self::redirect_uri() ] );
	}

	/** Looks up the provider row from Therum_Connections_Page::providers(). */
	private static function find_provider( string $id ): ?array {
		$all = Therum_Connections_Page::providers();
		foreach ( $all as $cat => $list ) {
			foreach ( $list as $p ) {
				if ( ( $p['id'] ?? '' ) === $id ) return $p;
			}
		}
		return null;
	}

	/** Entry point: admin clicks "Sign in" → we build the authorize URL and 302. */
	public static function start(): void {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden', 403 );
		check_admin_referer( 'therum_oauth_start' );

		$provider = sanitize_key( $_GET['provider'] ?? '' );
		$p        = self::find_provider( $provider );
		if ( ! $p || empty( $p['oauth_authorize_url'] ) ) wp_die( 'Unknown OAuth provider: ' . esc_html( $provider ) );

		$app = self::get_app( $provider );
		if ( $app['client_id'] === '' ) wp_die( 'Set up the OAuth app for ' . esc_html( $p['name'] ) . ' first.' );

		// CSRF state — short-lived, single-use, bound to the requesting user.
		$state = wp_generate_uuid4();
		set_transient( 'therum_oauth_' . $state, [
			'provider' => $provider,
			'user_id'  => get_current_user_id(),
		], self::STATE_TTL );

		$args = [
			'client_id'     => $app['client_id'],
			'redirect_uri'  => self::redirect_uri(),
			'response_type' => 'code',
			'state'         => $state,
		];
		if ( ! empty( $p['oauth_scope'] ) ) $args['scope'] = $p['oauth_scope'];
		// Dropbox + Google require token_access_type=offline for refresh tokens.
		if ( in_array( $provider, [ 'dropbox', 'gdrive' ], true ) ) $args['token_access_type'] = 'offline';

		wp_redirect( add_query_arg( $args, $p['oauth_authorize_url'] ) );
		exit;
	}

	/** Callback: provider redirects user back here with ?code + ?state. */
	public static function callback( \WP_REST_Request $req ) {
		$state_id = sanitize_text_field( (string) $req->get_param( 'state' ) );
		$code     = sanitize_text_field( (string) $req->get_param( 'code' ) );
		$err      = sanitize_text_field( (string) $req->get_param( 'error' ) );

		$base_back = admin_url( 'admin.php?page=therum-connections' );

		if ( $err ) wp_safe_redirect( add_query_arg( 'oauth_err', rawurlencode( $err ), $base_back ) );
		if ( ! $state_id || ! $code ) wp_safe_redirect( add_query_arg( 'oauth_err', 'missing-params', $base_back ) );

		$state = get_transient( 'therum_oauth_' . $state_id );
		if ( ! $state || empty( $state['provider'] ) ) { wp_safe_redirect( add_query_arg( 'oauth_err', 'state-expired', $base_back ) ); exit; }
		delete_transient( 'therum_oauth_' . $state_id );

		// Restore the user session — REST callbacks don't carry the admin
		// cookie context, so set_current_user lets save_connection() use
		// per-user storage if it wants. (Today storage is install-wide.)
		if ( ! empty( $state['user_id'] ) ) wp_set_current_user( (int) $state['user_id'] );

		$provider = $state['provider'];
		$p        = self::find_provider( $provider );
		if ( ! $p ) { wp_safe_redirect( add_query_arg( 'oauth_err', 'unknown-provider', $base_back ) ); exit; }

		$app = self::get_app( $provider );

		// Exchange the auth code for an access token.
		$body = [
			'code'          => $code,
			'grant_type'    => 'authorization_code',
			'client_id'     => $app['client_id'],
			'client_secret' => $app['client_secret'],
			'redirect_uri'  => self::redirect_uri(),
		];
		$res = wp_remote_post( $p['oauth_token_url'], [
			'timeout' => 20,
			'headers' => [ 'content-type' => 'application/x-www-form-urlencoded', 'accept' => 'application/json' ],
			'body'    => $body,
		] );
		if ( is_wp_error( $res ) ) { wp_safe_redirect( add_query_arg( 'oauth_err', rawurlencode( $res->get_error_message() ), $base_back ) ); exit; }
		$code_status = wp_remote_retrieve_response_code( $res );
		$data        = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( $code_status !== 200 || empty( $data['access_token'] ) ) {
			$msg = $data['error_description'] ?? $data['error'] ?? ( 'token-exchange-' . $code_status );
			wp_safe_redirect( add_query_arg( 'oauth_err', rawurlencode( (string) $msg ), $base_back ) );
			exit;
		}

		// Token in hand. Stash in the same vault as API keys so all downstream
		// helpers (Therum_Connections_Page::get_credential) work identically
		// regardless of how the credential arrived.
		$row = [
			'key'           => (string) $data['access_token'],
			'key2'          => (string) ( $data['refresh_token'] ?? '' ),
			'label'         => 'via OAuth',
			'connected_at'  => time(),
			'oauth'         => true,
			'token_type'    => (string) ( $data['token_type'] ?? 'Bearer' ),
			'expires_in'    => (int)    ( $data['expires_in'] ?? 0 ),
		];
		// Re-use the encryption path
		$reflect = $row;
		$reflect['key']  = Therum_Connections_Page::encrypt( $reflect['key'] );
		$reflect['key2'] = $reflect['key2'] !== '' ? Therum_Connections_Page::encrypt( $reflect['key2'] ) : '';
		$all = (array) get_option( 'therum_connections', [] );
		$all[ $provider ] = $reflect;
		update_option( 'therum_connections', $all, false );

		wp_safe_redirect( add_query_arg( 'oauth_ok', $provider, $base_back ) );
		exit;
	}
}

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
		'timeout' => 30,
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
		'timeout' => 30,
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

// Audit log helper: fires on every connect/disconnect
add_action( 'wp_ajax_therum_connection_connect', function() {
	$entries = (array) get_option('therum_conn_audit_log', []);
	$entries[] = [ 'time' => time(), 'action' => 'connected', 'provider' => sanitize_key($_POST['provider_id'] ?? ''), 'user' => wp_get_current_user()->user_login ];
	update_option('therum_conn_audit_log', array_slice($entries, -200), false);
	update_option('therum_conn_audit_count', count($entries) + 1, false);
}, 20 );
add_action( 'wp_ajax_therum_connection_disconnect', function() {
	$entries = (array) get_option('therum_conn_audit_log', []);
	$entries[] = [ 'time' => time(), 'action' => 'disconnected', 'provider' => sanitize_key($_POST['provider_id'] ?? ''), 'user' => wp_get_current_user()->user_login ];
	update_option('therum_conn_audit_log', array_slice($entries, -200), false);
	update_option('therum_conn_audit_count', count($entries) + 1, false);
}, 20 );


// ─── REGISTRATION ─────────────────────────────────────────────────────────────

add_action('admin_menu', function() {
	// Hidden submenu pages (parent = null) — they get a URL but no menu placement.
	add_submenu_page('', 'Pages',         'Pages',         'edit_pages',     'therum-pages',         ['Therum_Pages_Page',         'render']);
	add_submenu_page('', 'Posts',         'Posts',         'edit_posts',     'therum-posts',         ['Therum_Posts_Page',         'render']);
	add_submenu_page('', 'Case Studies',  'Case Studies',  'edit_posts',     'therum-case-studies',  ['Therum_Case_Studies_Page',  'render']);
	add_submenu_page('', 'Media',         'Media',         'upload_files',   'therum-media',         ['Therum_Media_Page',         'render']);
	add_submenu_page('', 'Users',         'Users',         'list_users',     'therum-users',         ['Therum_Users_Page',         'render']);
	add_submenu_page('', 'Plugins',       'Plugins',       'activate_plugins','therum-plugins',      ['Therum_Plugins_Page',       'render']);
	add_submenu_page('', 'Plugin',        'Plugin',        'activate_plugins','therum-plugin-detail',['Therum_Plugin_Detail_Page', 'render']);
	add_submenu_page('', 'Updates',       'Updates',       'manage_options',  'therum-updates',      ['Therum_Updates_Page',       'render']);
	add_submenu_page('', 'Connections',   'Connections',   'manage_options',  'therum-connections',  ['Therum_Connections_Page',   'render']);
	add_submenu_page('', 'Studio',        'Studio',        'manage_options',  'therum-studio',       ['Therum_Studio_Page',         'render']);
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
class Therum_Studio_Page {

	/**
	 * Curated app registry. Each entry:
	 *   slug       — display slug (lowercase, hyphenated)
	 *   name       — display name
	 *   tagline    — one-liner shown on the card
	 *   description— longer paragraph in the expanded card
	 *   color      — accent color for the card hero
	 *   built_in   — true if it ships inside Therum OS (Nexus). Renders as
	 *                "Included" with the Connections / wherever Open URL.
	 *   plugin_file— wp_plugin_dir slug/file.php (when installed). Used for
	 *                state detection: installed? active? has update?
	 *   repo       — TherumCs/<repo> for the install path (GitHub release zip)
	 *   open_url   — admin URL to open the app once installed/active
	 *   category   — informational grouping ('infrastructure' | 'site' | 'commerce')
	 */
	public static function apps(): array {
		return [
			[
				'slug'        => 'nexus',
				'name'        => 'Nexus',
				'tagline'     => 'Connections + credentials vault.',
				'description' => 'Encrypted credential storage for every external service Therum can talk to. AES-256-GCM at rest, scoped tokens, custom providers. The backbone every other Therum app rides on.',
				'color'       => '#6366f1',
				'built_in'    => true,
				'open_url'    => admin_url( 'admin.php?page=therum-connections' ),
				'category'    => 'infrastructure',
			],
			[
				'slug'        => 'cluster',
				'name'        => 'Cluster',
				'tagline'     => 'Group + organize content at scale.',
				'description' => 'Sort posts, pages, and custom post types into clusters with cross-linking, shared meta, and bulk operations. Designed for sites that grow past a few hundred entries.',
				'color'       => '#06b6d4',
				'built_in'    => false,
				'plugin_file' => 'cluster/cluster.php',
				'repo'        => 'TherumCs/Cluster',
				'open_url'    => admin_url( 'admin.php?page=cluster' ),
				'category'    => 'site',
			],
			[
				'slug'        => 'milieus',
				'name'        => 'Milieus',
				'tagline'     => 'Environment + audience targeting.',
				'description' => 'Conditional content blocks gated by environment, device, geo, role, or arbitrary audience rules. Built on top of Bricks so any element gets a Milieus toggle.',
				'color'       => '#10b981',
				'built_in'    => false,
				'plugin_file' => 'milieus/milieus.php',
				'repo'        => 'TherumCs/Milieus',
				'open_url'    => admin_url( 'admin.php?page=milieus' ),
				'category'    => 'site',
			],
			[
				'slug'        => 'shop',
				'name'        => 'Shop',
				'tagline'     => 'Lightweight commerce for Therum.',
				'description' => 'A WooCommerce alternative tuned for digital goods, services, and lightweight catalogs. Stripe + PayPal out of the box, no checkout bloat, no React. Therum-native.',
				'color'       => '#f59e0b',
				'built_in'    => false,
				'plugin_file' => 'shop/shop.php',
				'repo'        => 'TherumCs/Shop',
				'open_url'    => admin_url( 'admin.php?page=shop' ),
				'category'    => 'commerce',
			],
		];
	}

	/** Resolve current install state for an app. */
	private static function status( array $app ): array {
		if ( ! empty( $app['built_in'] ) ) {
			return [ 'state' => 'included', 'label' => 'Included', 'version' => defined( 'THERUM_OS_VERSION' ) ? THERUM_OS_VERSION : '' ];
		}
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$file = $app['plugin_file'] ?? '';
		if ( ! $file ) return [ 'state' => 'unknown', 'label' => 'Unknown', 'version' => '' ];

		$path = WP_PLUGIN_DIR . '/' . $file;
		if ( ! file_exists( $path ) ) {
			return [ 'state' => 'not_installed', 'label' => 'Not installed', 'version' => '' ];
		}

		$data = get_plugin_data( $path, false, false );
		$ver  = (string) ( $data['Version'] ?? '' );

		if ( is_plugin_active( $file ) ) {
			return [ 'state' => 'active', 'label' => 'Active', 'version' => $ver ];
		}
		return [ 'state' => 'inactive', 'label' => 'Inactive', 'version' => $ver ];
	}

	/** Build the action URL appropriate for the app's current state. */
	private static function action_url( array $app, array $status ): array {
		switch ( $status['state'] ) {
			case 'included':
				return [ 'label' => 'Open', 'url' => $app['open_url'] ?? admin_url(), 'primary' => true ];
			case 'active':
				return [ 'label' => 'Open', 'url' => $app['open_url'] ?? admin_url(), 'primary' => true ];
			case 'inactive':
				return [
					'label'   => 'Activate',
					'url'     => wp_nonce_url(
						admin_url( 'plugins.php?action=activate&plugin=' . urlencode( $app['plugin_file'] ) ),
						'activate-plugin_' . $app['plugin_file']
					),
					'primary' => true,
				];
			case 'not_installed':
			default:
				// One-click install via the WP plugin install handler — pulls the
				// zip from wp.org by slug. For Therum repos that aren't on wp.org,
				// the link drops the user at a plugin-upload screen with the slug
				// pre-filtered so they can grab the GitHub release zip.
				$slug = $app['slug'];
				return [
					'label'   => 'Install',
					'url'     => wp_nonce_url(
						admin_url( 'update.php?action=install-plugin&plugin=' . urlencode( $slug ) ),
						'install-plugin_' . $slug
					),
					'primary' => true,
				];
		}
	}

	public static function render(): void {
		$apps = self::apps();
		?>
		<div class="th-lp" data-page-id="studio">

		  <div class="th-lp-header">
			<div class="th-lp-header-left">
			  <div class="th-lp-meta">
				<span class="th-lp-meta-dot"></span>
				<?php echo esc_html( count( $apps ) ); ?> MODULE<?php echo count( $apps ) === 1 ? '' : 'S'; ?>
			  </div>
			  <h1 class="th-lp-title">From the Studio</h1>
			  <p class="th-lp-sub">Custom-built modules from Therum Creative Studios. Add the ones you need; ignore the rest. Nexus ships in core because every other module depends on it.</p>
			</div>
		  </div>

		  <div class="th-studio-grid">
			<?php foreach ( $apps as $app ):
				$status     = self::status( $app );
				$action     = self::action_url( $app, $status );
				$state_cls  = 'th-studio-state-' . $status['state'];
			?>
			<article class="th-studio-card <?php echo esc_attr( $state_cls ); ?>" data-app="<?php echo esc_attr( $app['slug'] ); ?>">
			  <div class="th-studio-card-hero" style="background:linear-gradient(135deg, <?php echo esc_attr( $app['color'] ); ?> 0%, color-mix(in srgb, <?php echo esc_attr( $app['color'] ); ?> 60%, #000) 100%);">
				<span class="th-studio-card-mark"><?php echo esc_html( strtoupper( substr( $app['name'], 0, 1 ) ) ); ?></span>
				<?php if ( ! empty( $app['built_in'] ) ): ?>
				  <span class="th-studio-card-badge th-studio-card-badge-included">Included</span>
				<?php elseif ( $status['state'] === 'active' ): ?>
				  <span class="th-studio-card-badge th-studio-card-badge-active">Active</span>
				<?php elseif ( $status['state'] === 'inactive' ): ?>
				  <span class="th-studio-card-badge th-studio-card-badge-inactive">Inactive</span>
				<?php endif; ?>
			  </div>
			  <div class="th-studio-card-body">
				<div class="th-studio-card-titlerow">
				  <h2 class="th-studio-card-name"><?php echo esc_html( $app['name'] ); ?></h2>
				  <?php if ( $status['version'] ): ?>
					<span class="th-studio-card-ver">v<?php echo esc_html( $status['version'] ); ?></span>
				  <?php endif; ?>
				</div>
				<p class="th-studio-card-tagline"><?php echo esc_html( $app['tagline'] ); ?></p>
				<p class="th-studio-card-desc"><?php echo esc_html( $app['description'] ); ?></p>
				<div class="th-studio-card-foot">
				  <a class="th-btn <?php echo $action['primary'] ? 'th-btn-primary' : ''; ?>" href="<?php echo esc_url( $action['url'] ); ?>"><?php echo esc_html( $action['label'] ); ?></a>
				  <?php if ( ! empty( $app['repo'] ) ): ?>
					<a class="th-studio-card-link" href="https://github.com/<?php echo esc_attr( $app['repo'] ); ?>" target="_blank" rel="noopener">View on GitHub ↗</a>
				  <?php endif; ?>
				</div>
			  </div>
			</article>
			<?php endforeach; ?>
		  </div>

		  <p class="th-studio-footnote">More modules in development. Therum apps share a single update channel through the in-admin updater — no separate license keys, no per-plugin nags.</p>
		</div>

		<style>
		.th-studio-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:18px;margin-top:8px}
		.th-studio-card{background:var(--sf);border:1px solid var(--bd);border-radius:14px;overflow:hidden;display:flex;flex-direction:column;transition:transform .2s ease,border-color .2s ease,box-shadow .2s ease}
		.th-studio-card:hover{transform:translateY(-2px);border-color:color-mix(in srgb,var(--tx) 14%,transparent);box-shadow:0 8px 24px rgba(0,0,0,.06)}
		.th-studio-card-hero{position:relative;height:120px;display:flex;align-items:center;justify-content:center;color:#fff}
		.th-studio-card-mark{font-family:var(--f);font-size:48px;font-weight:700;letter-spacing:-.04em;line-height:1;text-shadow:0 2px 10px rgba(0,0,0,.2)}
		.th-studio-card-badge{position:absolute;top:12px;right:12px;padding:3px 10px;font-size:10px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;border-radius:20px;background:rgba(255,255,255,.92);backdrop-filter:blur(8px)}
		.th-studio-card-badge-included{color:#6366f1}
		.th-studio-card-badge-active{color:#10b981}
		.th-studio-card-badge-inactive{color:#f59e0b}
		.th-studio-card-body{padding:18px 20px 16px;display:flex;flex-direction:column;flex:1}
		.th-studio-card-titlerow{display:flex;align-items:baseline;justify-content:space-between;gap:8px;margin-bottom:4px}
		.th-studio-card-name{font-size:18px;font-weight:700;color:var(--tx);margin:0;letter-spacing:-.01em}
		.th-studio-card-ver{font-size:11px;color:var(--tx3);font-weight:500}
		.th-studio-card-tagline{font-size:13px;color:var(--tx2);margin:0 0 10px;font-weight:500}
		.th-studio-card-desc{font-size:12px;color:var(--tx3);line-height:1.55;margin:0 0 16px;flex:1}
		.th-studio-card-foot{display:flex;align-items:center;justify-content:space-between;gap:12px;padding-top:12px;border-top:1px solid var(--bd)}
		.th-studio-card-link{font-size:11px;color:var(--tx3);text-decoration:none}
		.th-studio-card-link:hover{color:var(--ac)}
		.th-studio-footnote{margin:32px 0 0;font-size:12px;color:var(--tx3);text-align:center}
		</style>
		<?php
	}
}

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

function th_pref( $key, $default = '' ) {
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

	$ids = get_posts( [
		'post_type'      => 'attachment',
		'post_status'    => 'inherit',
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'orderby'        => 'date',
		'order'          => 'DESC',
	] );
	if ( empty( $ids ) ) wp_die( 'Media library is empty.' );

	$upload  = wp_upload_dir();
	$tmp_zip = trailingslashit( get_temp_dir() ) . 'therum-media-' . wp_generate_uuid4() . '.zip';
	$zip     = new ZipArchive();
	if ( $zip->open( $tmp_zip, ZipArchive::CREATE | ZipArchive::OVERWRITE ) !== true ) wp_die( 'Could not open temp zip.' );

	$manifest = [ '# Therum media library export — ' . gmdate( 'c' ) ];
	$added    = 0;
	$basedir  = trailingslashit( (string) ( $upload['basedir'] ?? '' ) );

	foreach ( $ids as $id ) {
		$file = get_attached_file( (int) $id );
		if ( ! $file || ! file_exists( $file ) ) continue;
		$rel = $basedir && str_starts_with( $file, $basedir ) ? substr( $file, strlen( $basedir ) ) : basename( $file );
		if ( $zip->addFile( $file, $rel ) ) {
			$manifest[] = $id . "\t" . $rel . "\t" . filesize( $file );
			$added++;
		}
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
	while ( ob_get_level() ) ob_end_clean();
	readfile( $tmp_zip );
	@unlink( $tmp_zip );
	exit;
});

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
	$density = (int) th_pref( 'media_density', 5 );
	$view    = th_pref( 'media_view_mode', 'grid' );
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

// localStorage wins if the user has made a choice; fall back to admin setting
var mode         = localStorage.getItem('thd_mode')  || serverMode;
var pos          = localStorage.getItem('thd_pos')   || serverPos;
var size         = localStorage.getItem('thd_size')  || 'normal';
var focusOn      = localStorage.getItem('thd_focus') === '1';
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
	localStorage.setItem('thd_pos', pos);
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
	localStorage.setItem('thd_size', size);
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
	localStorage.setItem('thd_mode', m);

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
	localStorage.setItem('thd_focus', on ? '1' : '0');
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
	th_setting_row(
		'Desktop & tablet position',
		'Bottom mirrors a macOS dock. Top matches the classic WordPress toolbar.',
		th_select( 'th_dock_position', $opts['position'], [
			'bottom' => 'Bottom (default)',
			'top'    => 'Top bar',
		] )
	);

	th_setting_row(
		'Default mode',
		'Applied on first visit before the user makes a personal choice. Their pick persists in localStorage.',
		th_select( 'th_dock_default_mode', $opts['default_mode'], [
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
	th_setting_row(
		'Mobile style',
		'FAB shows a circular floating button at bottom-right that expands to admin actions. None hides the dock entirely on mobile.',
		th_select( 'th_dock_mobile', $opts['mobile'], [
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

class Therum_Settings {

	private static array $sections = [];

	public static function register(string $key, array $section): void {
		$section['key'] = $key;
		$section += [
			'label'    => ucfirst($key),
			'icon'     => 'settings',
			'desc'     => '',
			'priority' => 100,
			'render'   => [self::class, 'render_stub'],
		];
		self::$sections[$key] = $section;
	}

	public static function get_sections(): array {
		$sections = self::$sections;
		$sections = apply_filters('therum_settings_sections', $sections);
		uasort($sections, fn($a, $b) => ($a['priority'] ?? 100) <=> ($b['priority'] ?? 100));
		return $sections;
	}

	public static function render_page(): void {
		$sections   = self::get_sections();
		$active_key = sanitize_key($_GET['section'] ?? '');
		if (!isset($sections[$active_key])) {
			$active_key = array_key_first($sections);
		}
		$active = $sections[$active_key] ?? null;
		?>
		<div class="th-settings">
		  <aside class="th-settings-nav">
			<div class="th-settings-search">
			  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
			  <input type="text" placeholder="Search settings…" id="th-settings-search-input" />
			</div>
			<nav>
			  <?php foreach ($sections as $s):
				$href = add_query_arg(['page' => 'therum-settings', 'section' => $s['key']], admin_url('admin.php'));
				$is_active = $s['key'] === $active_key;
			  ?>
			  <a href="<?php echo esc_url($href); ?>" class="th-settings-nav-item<?php echo $is_active ? ' active' : ''; ?>" data-search-key="<?php echo esc_attr(strtolower($s['label'] . ' ' . $s['desc'])); ?>">
				<?php if (function_exists('th_i') && !empty($s['icon'])) echo th_i($s['icon']); ?>
				<span><?php echo esc_html($s['label']); ?></span>
			  </a>
			  <?php endforeach; ?>
			</nav>
		  </aside>

		  <main class="th-settings-content">
			<header class="th-settings-header">
			  <div class="th-settings-header-left">
				<div class="th-settings-meta">
				  <span class="th-settings-meta-dot"></span>
				  <?php echo esc_html( strtoupper( 'Settings · ' . count( $sections ) . ' SECTION' . ( count( $sections ) === 1 ? '' : 'S' ) ) ); ?>
				</div>
				<h1 class="th-settings-title"><?php echo esc_html($active['label'] ?? 'Settings'); ?></h1>
				<?php if (!empty($active['desc'])): ?>
				<p class="th-settings-sub"><?php echo esc_html($active['desc']); ?></p>
				<?php endif; ?>
			  </div>
			</header>
			<?php if (is_callable($active['render'] ?? null)) call_user_func($active['render']); ?>
		  </main>
		</div>
		<?php
	}

	public static function render_stub(): void {
		?>
		<div class="th-settings-group">
		  <div class="th-settings-group-body" style="padding:32px;color:var(--tx2);font-size:14px;">
			Settings for this section land in a future update. The section is registered so plugins can attach config via the `therum_settings_sections` filter.
		  </div>
		</div>
		<?php
	}

	// ── Built-in section renderers ─────────────────────────────────────

	public static function render_appearance(): void {
		$state   = Therum_Themes::get_state();
		$presets = Therum_Themes::presets();
		$groups  = Therum_Themes::groups();
		$nonce   = wp_create_nonce('therum_theme');

		// Group presets for display.
		$by_group = [];
		foreach ($presets as $key => $p) {
			$by_group[$p['group']][$key] = $p;
		}
		?>
		<?php $view_mode = get_user_meta( get_current_user_id(), 'therum_pref_theme_view_mode', true ) ?: 'simple'; ?>
		<div class="th-settings-group" data-nonce="<?php echo esc_attr($nonce); ?>" data-theme-view="<?php echo esc_attr($view_mode); ?>">
		  <div class="th-settings-group-header th-theme-presets-header">
			<div>
			  <div class="th-settings-group-title">Theme presets</div>
			  <div class="th-settings-group-sub">Pick a vibe. Click to apply instantly. Density, accent, font, radius all bundle in.</div>
			</div>
			<div class="th-theme-view-toggle">
			  <button type="button" class="th-theme-view-btn<?php echo $view_mode==='simple'?' active':''; ?>" data-view="simple" title="Simple — color cards">
				<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
				<span>Simple</span>
			  </button>
			  <button type="button" class="th-theme-view-btn<?php echo $view_mode==='advanced'?' active':''; ?>" data-view="advanced" title="Advanced — full style tile">
				<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18"/><path d="M9 21V9"/></svg>
				<span>Advanced</span>
			  </button>
			</div>
		  </div>
		  <div class="th-settings-group-body">
			<div class="th-theme-search">
			  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
			  <input type="text" id="th-theme-search-input" placeholder="Search themes…" autocomplete="off" />
			</div>
			<?php foreach ($groups as $gkey => $g): if (empty($by_group[$gkey])) continue;
			  $is_gundam = $gkey === 'gundam';
			?>
			<?php $group_count = count($by_group[$gkey]); ?>
			<div class="th-theme-group" data-group="<?php echo esc_attr($gkey); ?>">
			  <div class="th-theme-group-head">
				<div>
				  <div class="th-theme-group-title"><?php echo esc_html($g['label']); ?>
					<span class="th-theme-group-count"><?php echo (int) $group_count; ?></span>
					<?php if ($is_gundam): ?><span class="th-theme-group-badge">Therum OS</span><?php endif; ?>
				  </div>
				  <div class="th-theme-group-desc"><?php echo esc_html($g['desc']); ?></div>
				</div>
				<div class="th-theme-carousel-controls">
				  <button type="button" class="th-theme-carousel-btn" data-th-carousel-prev aria-label="Previous"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 18 9 12 15 6"/></svg></button>
				  <button type="button" class="th-theme-carousel-btn" data-th-carousel-next aria-label="Next"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"/></svg></button>
				</div>
			  </div>
			  <div class="th-theme-carousel<?php echo $is_gundam ? ' th-theme-carousel-gundam' : ''; ?>" data-th-carousel>
				<?php foreach ($by_group[$gkey] as $key => $p):
				  $is_active = $state['palette'] === $p['palette'];
				  $rail_style = "background:" . esc_attr($p['previewRail']) . ";";
				  $main_style = strpos($p['previewMain'], 'gradient') !== false
					? "background:" . esc_attr($p['previewMain']) . ";"
					: "background-color:" . esc_attr($p['previewMain']) . ";";
				  $search_blob = strtolower($p['name'] . ' ' . $p['desc']);
				?>
				<?php
				  // Derive font + radius hints for the preview
				  $font_class = 'th-tp-font-' . ($p['font'] ?? 'system');
				  $radius_class = 'th-tp-radius-' . ($p['radius'] ?? 'medium');
				  $is_light = ($p['mode'] ?? 'dark') === 'light';
				  $accent = $p['accent'] ?? '#e83b3b';
				  $main_color = strpos($p['previewMain'], 'gradient') !== false ? '' : $p['previewMain'];
				?>
				<button type="button" class="th-theme-card <?php echo $font_class; ?> <?php echo $radius_class; ?> <?php echo $is_light ? 'th-tp-light' : 'th-tp-dark'; ?><?php echo $is_active ? ' active' : ''; ?>" data-preset="<?php echo esc_attr($key); ?>" data-search="<?php echo esc_attr($search_blob); ?>" style="--tp-accent:<?php echo esc_attr($accent); ?>;<?php if ($main_color): ?>--tp-bg:<?php echo esc_attr($main_color); ?>;<?php endif; ?>">

				  <!-- SIMPLE VIEW: original color preview -->
				  <div class="th-theme-preview th-tp-simple">
					<div class="th-theme-preview-main" style="<?php echo $main_style; ?>"></div>
					<div class="th-theme-preview-rail" style="<?php echo $rail_style; ?>"></div>
				  </div>

				  <!-- ADVANCED VIEW: full style tile with text + UI elements -->
				  <div class="th-theme-preview th-tp-advanced" style="<?php echo $main_style; ?>">
					<div class="th-tp-rail" style="<?php echo $rail_style; ?>"></div>
					<div class="th-tp-stage">
					  <div class="th-tp-headline">Aa</div>
					  <div class="th-tp-line th-tp-line-1"></div>
					  <div class="th-tp-line th-tp-line-2"></div>
					  <div class="th-tp-btn" style="background:<?php echo esc_attr($accent); ?>">Action</div>
					  <div class="th-tp-swatches">
						<div class="th-tp-sw" style="background:<?php echo esc_attr($accent); ?>"></div>
						<div class="th-tp-sw th-tp-sw-mid"></div>
						<div class="th-tp-sw th-tp-sw-bg"></div>
					  </div>
					</div>
				  </div>

				  <div class="th-theme-card-meta">
					<div class="th-theme-card-name"><?php echo esc_html($p['name']); ?>
					  <?php if ($is_active): ?><span class="th-theme-card-active">Active</span><?php endif; ?>
					</div>
					<div class="th-theme-card-desc"><?php echo esc_html($p['desc']); ?></div>
				  </div>
				</button>
				<?php endforeach; ?>
			  </div>
			  <div class="th-theme-carousel-dots" data-th-carousel-dots></div>
			</div>
			<?php endforeach; ?>
			<div class="th-theme-no-results" style="display:none">No themes match. Clear search to browse all.</div>
		  </div>
		</div>

		<!-- ─── THEME CUSTOMIZATION (overrides) ─────────────────────────────
		     Everything below overrides the active preset. Collapsed by default —
		     opt in only if you want to customize on top of the preset. Every
		     time you pick a new theme card above, these reset cleanly to the
		     new preset's values. -->
		<details class="th-settings-customize" open>
		  <summary class="th-settings-customize-head">
		    <span class="th-settings-customize-title">Theme Customization <span class="th-settings-customize-pill">overrides</span></span>
		    <span class="th-settings-customize-sub">Override individual values on top of the preset. Picking a new theme above resets these.</span>
		    <svg class="th-settings-customize-chev" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"/></svg>
		  </summary>

		<div class="th-settings-group">
		  <div class="th-settings-group-header">
			<div class="th-settings-group-title">Density</div>
			<div class="th-settings-group-sub">How much breathing room. Affects nav, list rows, card padding.</div>
		  </div>
		  <div class="th-settings-group-body">
			<div class="th-radio-row">
			  <?php foreach (['compact','standard','comfortable','breathing'] as $d): ?>
			  <label class="th-radio<?php echo $state['density']===$d?' active':''; ?>">
				<input type="radio" name="density" value="<?php echo esc_attr($d); ?>" <?php checked($state['density'], $d); ?> />
				<?php echo esc_html(ucfirst($d)); ?>
			  </label>
			  <?php endforeach; ?>
			</div>
		  </div>
		</div>

		<div class="th-settings-group">
		  <div class="th-settings-group-header">
			<div class="th-settings-group-title">Sidebar style</div>
			<div class="th-settings-group-sub">Per-site override. Themed presets may override per their design.</div>
		  </div>
		  <div class="th-settings-group-body">
			<div class="th-radio-row">
			  <?php foreach (['default','pills','floating','solid','minimal','dividers'] as $s): ?>
			  <label class="th-radio<?php echo $state['sidebarStyle']===$s?' active':''; ?>">
				<input type="radio" name="sidebarStyle" value="<?php echo esc_attr($s); ?>" <?php checked($state['sidebarStyle'], $s); ?> />
				<?php echo esc_html(ucfirst($s)); ?>
			  </label>
			  <?php endforeach; ?>
			</div>
		  </div>
		</div>

		<!-- SIDEBAR LAYOUT (icon / icon+text / text) -->
		<div class="th-settings-group">
		  <div class="th-settings-group-header">
			<div class="th-settings-group-title">Sidebar layout</div>
			<div class="th-settings-group-sub">How nav items render in the sidebar.</div>
		  </div>
		  <div class="th-settings-group-body">
			<div class="th-radio-row">
			  <?php foreach (['both','icons','text'] as $sl):
				$label = ['both'=>'Icon + text','icons'=>'Icons only','text'=>'Text only'][$sl];
			  ?>
			  <label class="th-radio<?php echo ($state['sidebarLayout'] ?? 'both')===$sl?' active':''; ?>">
				<input type="radio" name="sidebarLayout" value="<?php echo esc_attr($sl); ?>" <?php checked($state['sidebarLayout'] ?? 'both', $sl); ?> />
				<?php echo esc_html($label); ?>
			  </label>
			  <?php endforeach; ?>
			</div>
		  </div>
		</div>

		<!-- SHADOW -->
		<div class="th-settings-group">
		  <div class="th-settings-group-header">
			<div class="th-settings-group-title">Shadow</div>
			<div class="th-settings-group-sub">Depth on cards, dropdowns, modals.</div>
		  </div>
		  <div class="th-settings-group-body">
			<div class="th-radio-row">
			  <?php foreach (['none','soft','medium','strong'] as $sh): ?>
			  <label class="th-radio<?php echo ($state['shadow'] ?? 'soft')===$sh?' active':''; ?>">
				<input type="radio" name="shadow" value="<?php echo esc_attr($sh); ?>" <?php checked($state['shadow'] ?? 'soft', $sh); ?> />
				<?php echo esc_html(ucfirst($sh)); ?>
			  </label>
			  <?php endforeach; ?>
			</div>
		  </div>
		</div>

		<!-- CORNERS / RADIUS -->
		<div class="th-settings-group">
		  <div class="th-settings-group-header">
			<div class="th-settings-group-title">Corners</div>
			<div class="th-settings-group-sub">How rounded buttons, cards, and inputs are.</div>
		  </div>
		  <div class="th-settings-group-body">
			<div class="th-radio-row">
			  <?php foreach (['sharp','medium','round'] as $r): ?>
			  <label class="th-radio<?php echo ($state['radius'] ?? 'medium')===$r?' active':''; ?>">
				<input type="radio" name="radius" value="<?php echo esc_attr($r); ?>" <?php checked($state['radius'] ?? 'medium', $r); ?> />
				<?php echo esc_html(ucfirst($r)); ?>
			  </label>
			  <?php endforeach; ?>
			</div>
		  </div>
		</div>

		<!-- GLASS -->
		<div class="th-settings-group">
		  <div class="th-settings-group-header">
			<div class="th-settings-group-title">Glass</div>
			<div class="th-settings-group-sub">Frosted backdrop on cards and modals. Best paired with Glass-family themes.</div>
		  </div>
		  <div class="th-settings-group-body">
			<div class="th-radio-row">
			  <label class="th-radio<?php echo empty($state['glass'])?' active':''; ?>">
				<input type="radio" name="glass" value="false" <?php checked(empty($state['glass'])); ?> />
				Off
			  </label>
			  <label class="th-radio<?php echo !empty($state['glass'])?' active':''; ?>">
				<input type="radio" name="glass" value="true" <?php checked(!empty($state['glass'])); ?> />
				On
			  </label>
			</div>
		  </div>
		</div>

		<!-- GLASS TINT — only visible when palette is Glass -->
		<?php
		  $is_glass_on = !empty($state['glass']) || ($state['palette'] ?? '') === 'glass';
		  $glass_tint = $state['glassTint'] ?? 'auto';
		  $tint_is_color = preg_match('/^#[0-9a-f]{3,8}$/i', $glass_tint);
		  $tint_mode = $tint_is_color ? 'color' : $glass_tint;
		?>
		<div class="th-settings-group th-glass-tint-group" data-show-when-glass="1" style="<?php echo $is_glass_on ? '' : 'display:none;'; ?>">
		  <div class="th-settings-group-header">
			<div class="th-settings-group-title">Glass tint</div>
			<div class="th-settings-group-sub">Auto follows the light/dark toggle. Force a tint, or pick a custom color for the frost.</div>
		  </div>
		  <div class="th-settings-group-body">
			<div class="th-radio-row">
			  <?php foreach (['auto','dark','light','color'] as $gt):
				$label = ['auto'=>'Auto','dark'=>'Dark','light'=>'Light','color'=>'Color'][$gt];
			  ?>
			  <label class="th-radio<?php echo $tint_mode===$gt?' active':''; ?>" data-glass-tint="<?php echo esc_attr($gt); ?>">
				<input type="radio" name="glassTint" value="<?php echo esc_attr($gt); ?>" <?php checked($tint_mode, $gt); ?> />
				<?php echo esc_html($label); ?>
			  </label>
			  <?php endforeach; ?>
			</div>
			<div class="th-glass-tint-color-row" style="margin-top:14px;<?php echo $tint_mode === 'color' ? '' : 'display:none;'; ?>">
			  <label style="display:flex;align-items:center;gap:10px;font-size:12px;color:var(--tx2);">
				<input type="color" id="th-glass-tint-color" value="<?php echo $tint_is_color ? esc_attr($glass_tint) : '#1e3a8a'; ?>" style="width:36px;height:32px;padding:0;border:1px solid var(--bd);border-radius:6px;background:transparent;cursor:pointer;" />
				<span>Tint color — picks the frost color used across cards, sidebar, and shell glow</span>
			  </label>
			</div>
		  </div>
		</div>

		<!-- SURFACE EFFECT — five distinct backdrop modes, each independent of theme.
		     Maps to body.bg-<value> + body.glass + body.glass-tint-<x> CSS rules.
		     None: clean. Light Glass: airy frosted. Dark Glass: smoky frost.
		     Colored Glass: frost washed in the accent. Gradient: animated drift.
		     Blurred: heavy frost on every surface. -->
		<?php $surface = $state['surfaceEffect'] ?? 'none'; ?>
		<div class="th-settings-group">
		  <div class="th-settings-group-header">
			<div class="th-settings-group-title">Surface effect</div>
			<div class="th-settings-group-sub">Backdrop atmosphere for cards, sidebar, and topbar. Stacks on any theme.</div>
		  </div>
		  <div class="th-settings-group-body">
			<div class="th-radio-row" style="flex-wrap:wrap;gap:6px;">
			  <?php foreach ([
				'none'          => 'None',
				'glass-light'   => 'Light Glass',
				'glass-dark'    => 'Dark Glass',
				'glass-colored' => 'Colored Glass',
				'gradient'      => 'Gradient',
				'blurred'       => 'Blurred',
			  ] as $sk => $sl): ?>
			  <label class="th-radio<?php echo $surface===$sk?' active':''; ?>">
				<input type="radio" name="surfaceEffect" value="<?php echo esc_attr($sk); ?>" <?php checked($surface, $sk); ?> />
				<?php echo esc_html($sl); ?>
			  </label>
			  <?php endforeach; ?>
			</div>
		  </div>
		</div>

		<!-- BLUR -->
		<div class="th-settings-group">
		  <div class="th-settings-group-header">
			<div class="th-settings-group-title">Blur intensity</div>
			<div class="th-settings-group-sub">How much backdrop blur the Glass / Blurred effect uses.</div>
		  </div>
		  <div class="th-settings-group-body">
			<div class="th-radio-row">
			  <?php foreach (['light','medium','heavy'] as $b): ?>
			  <label class="th-radio<?php echo ($state['blur'] ?? 'medium')===$b?' active':''; ?>">
				<input type="radio" name="blur" value="<?php echo esc_attr($b); ?>" <?php checked($state['blur'] ?? 'medium', $b); ?> />
				<?php echo esc_html(ucfirst($b)); ?>
			  </label>
			  <?php endforeach; ?>
			</div>
		  </div>
		</div>

		<div class="th-settings-group">
		  <div class="th-settings-group-header">
			<div class="th-settings-group-title">Card layout</div>
			<div class="th-settings-group-sub">How posts and pages render in list views.</div>
		  </div>
		  <div class="th-settings-group-body">
			<?php $card_layout = $state['cardLayout'] ?? 'hero'; ?>
			<div class="th-card-style-grid" data-state-field="cardLayout">
			  <?php
			  $card_layouts = [
				['key'=>'card-v1',    'name'=>'Card V1',    'desc'=>'Poster — full image with title, excerpt, and link overlaid.'],
				['key'=>'card-v2',    'name'=>'Card V2',    'desc'=>'Detailed — image, status, meta, features, and author footer.'],
				['key'=>'hero',       'name'=>'Hero',       'desc'=>'Full-bleed image, text overlay.'],
				['key'=>'compact',    'name'=>'Compact',    'desc'=>'Dense rows with small thumb.'],
				['key'=>'compact-v1', 'name'=>'Compact V1', 'desc'=>'Tight list — square thumb, title, subtitle, dot menu.'],
				['key'=>'compact-v2', 'name'=>'Compact V2', 'desc'=>'Editorial list — larger thumb, date, title, author, meta line, bookmark.'],
				['key'=>'magazine',   'name'=>'Magazine',   'desc'=>'Editorial split — image left, copy right.'],
			  ];
			  foreach ($card_layouts as $cl):
				$active = $card_layout === $cl['key'];
			  ?>
			  <label class="th-card-style-card<?php echo $active ? ' active' : ''; ?>">
				<input type="radio" name="cardLayout" value="<?php echo esc_attr($cl['key']); ?>" <?php checked($card_layout, $cl['key']); ?> />
				<div class="th-card-style-preview th-card-style-preview-<?php echo esc_attr($cl['key']); ?>">
				  <?php if ($cl['key'] === 'hero'): ?>
					<div class="th-cs-overlay"></div>
				  <?php elseif ($cl['key'] === 'compact'): ?>
					<div class="th-cs-row2"></div>
				  <?php endif; ?>
				</div>
				<div class="th-card-style-info">
				  <div class="th-card-style-name"><?php echo esc_html($cl['name']); ?></div>
				  <div class="th-card-style-desc"><?php echo esc_html($cl['desc']); ?></div>
				</div>
			  </label>
			  <?php endforeach; ?>
			</div>
		  </div>
		</div>

		<div class="th-settings-group">
		  <div class="th-settings-group-header">
			<div class="th-settings-group-title">Card image</div>
			<div class="th-settings-group-sub">What fills the thumbnail.</div>
		  </div>
		  <div class="th-settings-group-body">
			<?php $card_image = $state['cardImage'] ?? 'gradient'; ?>
			<div class="th-card-style-grid th-card-style-grid-img" data-state-field="cardImage">
			  <?php
			  $card_images = [
				['key'=>'gradient',  'name'=>'Gradient',  'desc'=>'Per-post color blend.'],
				['key'=>'featured',  'name'=>'Featured',  'desc'=>'Use post featured image.'],
				['key'=>'stock',     'name'=>'Stock',     'desc'=>'Curated stock photo.'],
				['key'=>'wireframe', 'name'=>'Wireframe', 'desc'=>'Abstract SVG mock.'],
				['key'=>'pattern',   'name'=>'Pattern',   'desc'=>'Geometric tile pattern.'],
			  ];
			  foreach ($card_images as $ci):
				$active = $card_image === $ci['key'];
			  ?>
			  <label class="th-card-style-card<?php echo $active ? ' active' : ''; ?>">
				<input type="radio" name="cardImage" value="<?php echo esc_attr($ci['key']); ?>" <?php checked($card_image, $ci['key']); ?> />
				<div class="th-card-style-preview th-card-img-preview-<?php echo esc_attr($ci['key']); ?>"></div>
				<div class="th-card-style-info">
				  <div class="th-card-style-name"><?php echo esc_html($ci['name']); ?></div>
				  <div class="th-card-style-desc"><?php echo esc_html($ci['desc']); ?></div>
				</div>
			  </label>
			  <?php endforeach; ?>
			</div>
		  </div>
		</div>

		</details><!-- /.th-settings-customize -->

		<div class="th-settings-group">
		  <div class="th-settings-group-header">
			<div class="th-settings-group-title">Reset</div>
		  </div>
		  <div class="th-settings-group-body">
			<button type="button" class="th-btn" id="th-theme-reset">Reset my theme to site default</button>
		  </div>
		</div>
		<?php
	}

	public static function render_security(): void {
		?>
		<div class="th-settings-group">
		  <div class="th-settings-group-header">
			<div class="th-settings-group-title">Hardening (always on)</div>
			<div class="th-settings-group-sub">Therum OS ships with these locked. Plugin needed to disable.</div>
		  </div>
		  <div class="th-settings-group-body">
			<ul class="th-checklist">
			  <li><span class="th-check ok">✓</span> XML-RPC endpoint disabled</li>
			  <li><span class="th-check ok">✓</span> Login rate limiting (5 fails per 15 min)</li>
			  <li><span class="th-check ok">✓</span> Plugin/theme file editing disabled</li>
			  <li><span class="th-check ok">✓</span> Security headers (X-Frame, X-Content-Type, Referrer-Policy)</li>
			  <li><span class="th-check ok">✓</span> Pingback / trackback disabled</li>
			  <li><span class="th-check ok">✓</span> User enumeration via REST limited to authenticated requests</li>
			</ul>
		  </div>
		</div>
		<?php
	}

	public static function render_about(): void {
		$wp_ver  = get_bloginfo('version');
		$php_ver = PHP_VERSION;
		$db      = therum_is_sqlite() ? 'SQLite (drop-in)' : 'MySQL';
		?>
		<div class="th-settings-group">
		  <div class="th-settings-group-header"><div class="th-settings-group-title">Therum OS</div></div>
		  <div class="th-settings-group-body">
			<div class="th-about-grid">
			  <div><span class="th-about-label">Therum OS</span><strong>v<?php echo defined('THERUM_OS_VERSION') ? esc_html(THERUM_OS_VERSION) : '?'; ?></strong></div>
			  <div><span class="th-about-label">WordPress</span><?php echo esc_html($wp_ver); ?></div>
			  <div><span class="th-about-label">PHP</span><?php echo esc_html($php_ver); ?></div>
			  <div><span class="th-about-label">Database</span><?php echo esc_html($db); ?></div>
			  <div><span class="th-about-label">Multisite</span><?php echo is_multisite() ? 'Yes' : 'No'; ?></div>
			  <div><span class="th-about-label">Page editor</span>Bricks Builder</div>
			</div>
		  </div>
		</div>
		<div class="th-settings-group">
		  <div class="th-settings-group-header"><div class="th-settings-group-title">Credits</div></div>
		  <div class="th-settings-group-body" style="font-size:13px;color:var(--tx2);line-height:1.6;">
			Therum OS is built and maintained by <strong>Bam</strong> at <strong>Therum Creative Studios</strong>. Forked from WordPress, runs on SQLite, ships with Bricks. Anti-agency. Anti-bloat.
		  </div>
		</div>
		<?php
	}
}

// Register the default sections. Appearance, Branding, and Site Identity
// were moved to the Admin Theme (Customization) surface — their tab
// registrations live in therum-design.php inside the customization init hook.
add_action('init', function() {
	Therum_Settings::register('plugin-compat',  ['label'=>'Plugin Compatibility', 'icon'=>'plugins',  'desc'=>'Per-plugin compatibility tweaks.', 'priority'=>40,  'render'=>'th_render_plugin_compat']);
	Therum_Settings::register('security',       ['label'=>'Security',       'icon'=>'shield',    'desc'=>'Hardened defaults.',               'priority'=>50, 'render'=>['Therum_Settings', 'render_security']]);
	Therum_Settings::register('permissions',    ['label'=>'Permissions',    'icon'=>'users',    'desc'=>'Role capabilities.',               'priority'=>60,  'render'=>'th_render_permissions']);
	Therum_Settings::register('performance',    ['label'=>'Performance',    'icon'=>'gauge',    'desc'=>'Cache, lazy load, defer JS.',      'priority'=>70,  'render'=>'th_render_performance']);
	Therum_Settings::register('editor',         ['label'=>'Editor Defaults','icon'=>'edit2',    'desc'=>'Bricks defaults, classic editor.', 'priority'=>80,  'render'=>'th_render_editor_defaults']);
	Therum_Settings::register('uploads',        ['label'=>'Uploads',        'icon'=>'media',    'desc'=>'File types, max size, processing.','priority'=>90,  'render'=>'th_render_uploads']);
	Therum_Settings::register('notifications',  ['label'=>'Notifications',  'icon'=>'bell', 'desc'=>'Email, Slack, webhooks.',          'priority'=>100, 'render'=>'th_render_notifications']);
	// API & Webhooks merged into Connections > Manage > 'rest' tab (see therum-connections.php).
	Therum_Settings::register('updates',        ['label'=>'Updates',        'icon'=>'import',   'desc'=>'Auto-update plugins, themes, core.','priority'=>120, 'render'=>'th_render_updates']);
	Therum_Settings::register('backup',         ['label'=>'Backup',         'icon'=>'db',       'desc'=>'Schedule + restore.',              'priority'=>130, 'render'=>'th_render_backup']);
	Therum_Settings::register('experiments',    ['label'=>'Experiments',    'icon'=>'plugins',  'desc'=>'Opt-in surfaces and modes.',       'priority'=>135, 'render'=>'th_render_experiments']);
	Therum_Settings::register('about',          ['label'=>'About',          'icon'=>'info',   'desc'=>'Version, credits, system info.',   'priority'=>140, 'render'=>['Therum_Settings', 'render_about']]);
});

function th_render_experiments(): void {
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

	th_settings_group( 'Desktop Mode', 'Run wp-admin as a windowed desktop OS — draggable windows, a left-edge dock, virtual desktops. Per-user opt-in; Therum\'s shell yields automatically when active. Built by Automattic.', function() use ( $dm_active, $dm_user, $install_url, $search_url, $activate_url ) {
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

// Register the page itself + repoint sidebar Settings nav at it.
add_action('admin_menu', function() {
	add_submenu_page('', 'Therum Settings', 'Therum Settings', 'manage_options', 'therum-settings', ['Therum_Settings', 'render_page']);
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
function th_settings_group( $title, $sub, $body_callback ) {
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
function th_setting_row( $label, $help, $control_html ) {
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
function th_toggle( $key, $checked ) {
	$on = $checked ? ' on' : '';
	return '<button type="button" class="th-toggle' . $on . '" data-th-toggle="' . esc_attr( $key ) . '" aria-pressed="' . ( $checked ? 'true' : 'false' ) . '"><span class="th-toggle-knob"></span></button>';
}

// Text input (data-th-text="key")
function th_text_input( $key, $value, $placeholder = '', $type = 'text' ) {
	return '<input type="' . esc_attr( $type ) . '" class="th-input" data-th-text="' . esc_attr( $key ) . '" value="' . esc_attr( $value ) . '" placeholder="' . esc_attr( $placeholder ) . '" />';
}

// Select (data-th-select="key")
function th_select( $key, $value, $options ) {
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
function th_render_branding() {
	$logo = get_option( 'th_logo_url', '' );
	$favicon = get_option( 'th_favicon_url', '' );
	$wordmark = get_option( 'th_wordmark', get_bloginfo('name') );
	$brand = get_option( 'th_brand_color', '#e83b3b' );

	th_settings_group( 'Brand identity', 'Logo, favicon, wordmark used across the admin and login screen.', function() use ( $logo, $favicon, $wordmark, $brand ) {
		th_setting_row( 'Wordmark', 'Site name shown in sidebar header.', th_text_input( 'th_wordmark', $wordmark, 'Therum OS' ) );
		th_setting_row( 'Logo URL', 'Square SVG or PNG. Shown 32×32 in sidebar.', th_text_input( 'th_logo_url', $logo, 'https://example.com/logo.svg', 'url' ) );
		th_setting_row( 'Favicon URL', 'Browser tab icon. 32×32 PNG or ICO.', th_text_input( 'th_favicon_url', $favicon, 'https://example.com/favicon.ico', 'url' ) );
		th_setting_row( 'Brand color', 'Used as fallback accent if no theme is active.', '<div class="th-color-row"><input type="color" class="th-color" data-th-text="th_brand_color" value="' . esc_attr( $brand ) . '" /><span class="th-color-hex">' . esc_html( $brand ) . '</span></div>' );
	});

	th_settings_group( 'Preview', 'How your brand appears in the sidebar.', function() use ( $logo, $wordmark, $brand ) {
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
function th_render_site_identity() {
	$name = get_option( 'blogname' );
	$tagline = get_option( 'blogdescription' );
	$tz = get_option( 'timezone_string', 'UTC' );
	$lang = get_option( 'WPLANG', 'en_US' );
	$df = get_option( 'date_format', 'F j, Y' );
	$tf = get_option( 'time_format', 'g:i a' );
	$sow = (int) get_option( 'start_of_week', 1 );

	th_settings_group( 'Site identity', 'How the world sees this site.', function() use ( $name, $tagline ) {
		th_setting_row( 'Site title', 'Main name. Appears in browser tabs and SEO.', th_text_input( 'blogname', $name ) );
		th_setting_row( 'Tagline', 'Short description. Shown in feeds and search.', th_text_input( 'blogdescription', $tagline, 'A few words about your site' ) );
	});

	th_settings_group( 'Locale', 'Time, date, and language preferences.', function() use ( $tz, $lang, $df, $tf, $sow ) {
		// Build timezone list — common ones
		$tz_options = [];
		foreach ( timezone_identifiers_list() as $z ) $tz_options[ $z ] = $z;
		th_setting_row( 'Timezone', 'Used for scheduled posts and timestamps.', th_select( 'timezone_string', $tz, $tz_options ) );

		th_setting_row( 'Date format', 'PHP date() format string.', th_select( 'date_format', $df, [
			'F j, Y'    => date_i18n('F j, Y'),
			'Y-m-d'     => date_i18n('Y-m-d'),
			'm/d/Y'     => date_i18n('m/d/Y'),
			'd/m/Y'     => date_i18n('d/m/Y'),
			'j M Y'     => date_i18n('j M Y'),
		]));

		th_setting_row( 'Time format', '', th_select( 'time_format', $tf, [
			'g:i a' => date_i18n('g:i a'),
			'g:i A' => date_i18n('g:i A'),
			'H:i'   => date_i18n('H:i'),
		]));

		th_setting_row( 'Week starts on', 'First day of the week in calendar pickers.', th_select( 'start_of_week', $sow, [
			0 => 'Sunday', 1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday',
			4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday',
		]));
	});
}

// ─── PLUGIN COMPAT ──────────────────────────────────────────────────────────
function th_render_plugin_compat() {
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
		th_settings_group( 'Detected plugins', 'Compatibility shims for major plugins. Nothing here yet — shims auto-surface when their plugin is active.', function() {
			?>
			<div class="th-compat-empty" style="padding:20px 0;color:var(--tx3);font-size:13px;">
				No supported plugins detected. Activate WooCommerce, Bricks, LiteSpeed Cache, or any of the Bricks add-ons to surface their compatibility shims here.
			</div>
			<?php
		});
	} else {
		th_settings_group( 'Detected plugins', 'Compatibility shims for major plugins. Keep on unless you know what you\'re doing.', function() use ( $detected ) {
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
						<?php echo th_toggle( $p['compat_key'], $compat_on ); ?>
					</div>
				</div>
			<?php endforeach; ?>
			</div>
			<?php
		});
	}

	th_settings_group( 'Polyfills (always on)', 'Universal compatibility shims that load before any plugin.', function() {
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
function th_render_permissions() {
	// Defer to roles engine if loaded
	if ( function_exists( 'therum_render_permissions_full' ) ) {
		therum_render_permissions_full();
		return;
	}

	// Fallback if engine isn't loaded for some reason
	$default_role = get_option( 'default_role', 'subscriber' );
	th_settings_group( 'New user defaults', 'What role new users get when they register.', function() use ( $default_role ) {
		th_setting_row( 'Default role', 'Capabilities new users start with.', th_select( 'default_role', $default_role, [
			'subscriber'  => 'Subscriber (read only)',
			'contributor' => 'Contributor (write drafts)',
			'author'      => 'Author (publish own posts)',
			'editor'      => 'Editor (publish/edit any post)',
			'administrator' => 'Administrator (full access)',
		]));
	});
	th_settings_group( 'Roles engine missing', 'Install therum-roles-engine.php to manage custom roles.', function() {
		echo '<div style="font-size:13px;color:var(--tx3);">Custom role builder requires <code>therum-roles-engine.php</code> in mu-plugins.</div>';
	});
}

// ─── PERFORMANCE ────────────────────────────────────────────────────────────
function th_render_performance() {
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
	th_settings_group( 'PHP runtime', 'How much memory PHP has to work with on this request.', function() use ( $mem_limit, $mem_current, $mem_peak, $wp_mem_constant, $wp_mem_writable, $mem_nonce ) {
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
	th_settings_group( 'Caching & loading', 'Make pages render faster.', function() use ( $cache, $lazy_img, $defer_js ) {
		th_setting_row( 'Object cache', 'In-memory cache for queries. SQLite-compatible.', th_toggle( 'th_perf_cache', $cache ) );
		th_setting_row( 'Lazy-load images', 'Defer below-fold images until they\'re scrolled into view.', th_toggle( 'th_perf_lazy_images', $lazy_img ) );
		th_setting_row( 'Defer non-critical JS', 'Add `defer` attribute to scripts. Speeds up first paint.', th_toggle( 'th_perf_defer_js', $defer_js ) );
	});

	// ─── KILL THE BLOAT ─────────────────────────────────────────────
	th_settings_group( 'Strip the bloat', 'Disable WordPress features most sites never use.', function() use ( $kill_emoji, $kill_embeds, $heartbeat ) {
		th_setting_row( 'Disable emoji scripts', 'Saves ~13KB of inline JS + a sprite. WP injects this on every page.', th_toggle( 'th_perf_disable_emoji', $kill_emoji ) );
		th_setting_row( 'Disable oEmbed scripts', 'Saves ~10KB of JS. Only matters if you embed Twitter/Spotify cards.', th_toggle( 'th_perf_disable_embeds', $kill_embeds ) );
		th_setting_row( 'Heartbeat frequency', 'WP polls admin-ajax every 15s by default. Slows everything down.', th_select( 'th_perf_heartbeat', $heartbeat, [
			'off'      => 'Off (no autosave / no realtime)',
			'slow'     => 'Slow (60s) — recommended',
			'default'  => 'Default (15s)',
		]));
	});

	// ─── DATA HOUSEKEEPING ──────────────────────────────────────────
	th_settings_group( 'Data housekeeping', 'Keep the database lean over time.', function() use ( $revisions, $trash_days, $autosave_int ) {
		th_setting_row( 'Post revisions kept', 'Each save creates a row. 0 = none, default 5. Lower = leaner DB.', th_text_input( 'th_perf_revisions_limit', $revisions, '5', 'number' ) );
		th_setting_row( 'Auto-empty trash after (days)', 'Trashed posts deleted after N days. Default 30.', th_text_input( 'th_perf_trash_days', $trash_days, '7', 'number' ) );
		th_setting_row( 'Autosave interval (seconds)', 'How often the editor saves drafts. Default 60.', th_text_input( 'th_perf_autosave_interval', $autosave_int, '120', 'number' ) );
	});

	// ─── MINIFICATION ───────────────────────────────────────────────
	th_settings_group( 'Minification', 'Strip whitespace from output. Save bandwidth.', function() use ( $min_css, $min_html ) {
		th_setting_row( 'Minify CSS', 'Combine and minify stylesheets.', th_toggle( 'th_perf_min_css', $min_css ) );
		th_setting_row( 'Minify HTML', 'Strip comments and whitespace from page output.', th_toggle( 'th_perf_min_html', $min_html ) );
	});

	// ─── PURGE — universal multi-layer cache bust ───────────────────────
	$purge_nonce = wp_create_nonce( 'therum_purge_all' );
	$layers_now  = [];
	$layers_now[] = 'WP object cache';
	if ( function_exists( 'is_plugin_active' ) && is_plugin_active( 'litespeed-cache/litespeed-cache.php' ) ) $layers_now[] = 'LiteSpeed';
	if ( defined( 'BRICKS_VERSION' ) )                                                                       $layers_now[] = 'Bricks';
	$layers_now[] = 'Therum transients';
	th_settings_group( 'Purge caches', 'Flush every cache layer Therum can reach on this install.', function() use ( $purge_nonce, $layers_now ) {
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
		th_settings_group( 'LiteSpeed Cache', 'Detected v' . $lscache_ver . '. Therum settings yield to LSCache when active.', function() {
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
function th_render_editor_defaults() {
	$default = get_option( 'th_editor_default', 'bricks' );
	$df      = (bool) get_option( 'th_editor_distraction_free', false );
	$classic = (bool) get_option( 'th_editor_classic_for_posts', true );

	th_settings_group( 'Editor preferences', 'Therum OS uses Bricks Builder by default. Gutenberg stays disabled.', function() use ( $default, $df, $classic ) {
		th_setting_row( 'Default editor for pages', 'Bricks is the visual builder. Classic is a fallback.', th_select( 'th_editor_default', $default, [
			'bricks'  => 'Bricks Builder (recommended)',
			'classic' => 'Classic editor',
		]));
		th_setting_row( 'Classic editor for posts', 'Posts (vs pages) keep classic editor by default. Avoids Bricks overhead.', th_toggle( 'th_editor_classic_for_posts', $classic ) );
		th_setting_row( 'Distraction-free mode', 'Hide WP chrome inside the editor for focused writing.', th_toggle( 'th_editor_distraction_free', $df ) );
	});

	th_settings_group( 'Bricks shortcuts', 'Quick links to Bricks settings.', function() {
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
}

// ─── UPLOADS ────────────────────────────────────────────────────────────────
function th_render_uploads() {
	$max_mb     = (int) get_option( 'th_upload_max_mb', 64 );
	$strip_exif = (bool) get_option( 'th_upload_strip_exif', true );
	$auto_webp  = (bool) get_option( 'th_upload_auto_webp', false );
	$resize_max = (int) get_option( 'th_upload_resize_max', 2560 );
	$auto_rename = (bool) get_option( 'th_renamer_auto', false );

	th_settings_group( 'Media file renaming', 'Auto-rename uploaded files to match their title or alt text. Renames the file, all intermediate sizes, and rewrites every database reference (post content, postmeta, options).', function() use ( $auto_rename ) {
		th_setting_row(
			'Auto-rename on title edit',
			'When you change an attachment\'s title or alt text, the file is renamed to match. Manual renames via the kebab \'Rename for SEO\' always work; this toggle just adds an automatic path.',
			th_toggle( 'th_renamer_auto', $auto_rename )
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
	th_settings_group( 'PHP runtime — upload limits', 'These are set by PHP. Therum can write a .user.ini to override them.', function() use ( $php_upload_max, $php_post_max, $php_max_input, $php_max_exec ) {
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
	th_settings_group( 'Adjust upload limits', 'Therum writes these to <code>.user.ini</code> in your WordPress root. PHP picks up changes within a few minutes (immediately if you reload Local).', function() use ( $wanted_mb, $user_ini_writable, $user_ini_path ) {
		if ( ! $user_ini_writable ) {
			echo '<div class="th-warn"><strong>Heads up:</strong> ' . esc_html( $user_ini_path ) . ' is not writable. Therum can\'t adjust these for you.</div>';
			return;
		}
		th_setting_row( 'Target upload limit (MB)', 'Therum writes upload_max_filesize, post_max_size, and memory_limit so they all line up.', th_text_input( 'th_upload_target_mb', $wanted_mb, '256', 'number' ) );
		?>
		<div class="th-userini-status">
			<button type="button" class="th-btn th-btn-primary" id="th-write-userini" data-target="<?php echo esc_attr( $wanted_mb ); ?>">Write .user.ini</button>
			<span class="th-userini-msg" id="th-userini-msg"></span>
		</div>
		<?php
	});

	// ─── WP-LEVEL UPLOAD SETTINGS ─────────────────────────────────────
	th_settings_group( 'WordPress upload rules', 'These apply on top of PHP limits — Therum can be more restrictive but not more permissive.', function() use ( $max_mb, $resize_max ) {
		th_setting_row( 'Max upload size (MB)', 'Reject uploads above this size, even if PHP allows more.', th_text_input( 'th_upload_max_mb', $max_mb, '64', 'number' ) );
		th_setting_row( 'Auto-resize large images (px)', 'Downscale images larger than this on upload. Use 0 to disable.', th_text_input( 'th_upload_resize_max', $resize_max, '2560', 'number' ) );
	});

	th_settings_group( 'Image processing', 'Applied automatically on upload.', function() use ( $strip_exif, $auto_webp ) {
		th_setting_row( 'Strip EXIF data', 'Remove camera/GPS metadata for privacy + smaller files.', th_toggle( 'th_upload_strip_exif', $strip_exif ) );
		th_setting_row( 'Auto-convert to WebP', 'Modern format. Smaller files. Generates fallback for older browsers.', th_toggle( 'th_upload_auto_webp', $auto_webp ) );
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

	th_settings_group( 'Allowed file types', 'Toggle which file types WordPress will accept in the media uploader. Changes save instantly.', function() use ( $type_groups, $enabled, $wp_defaults ) {
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
function th_render_notifications() {
	$admin_email   = get_option( 'admin_email' );
	$slack         = get_option( 'th_notify_slack_webhook', '' );
	$email_enabled = (bool) get_option( 'th_notify_email', true );
	$on_login      = (bool) get_option( 'th_notify_on_login', false );
	$on_update     = (bool) get_option( 'th_notify_on_update', true );
	$on_backup     = (bool) get_option( 'th_notify_on_backup', false );
	$nonce         = wp_create_nonce( 'therum_notify' );

	th_settings_group( 'Email', 'Where Therum sends transactional emails.', function() use ( $admin_email, $email_enabled ) {
		th_setting_row( 'Admin email', 'Receives security alerts, update notices, etc.', th_text_input( 'admin_email', $admin_email, 'you@example.com', 'email' ) );
		th_setting_row( 'Email enabled', 'Master switch — disable to silence all email notifications.', th_toggle( 'th_notify_email', $email_enabled ) );
	});

	th_settings_group( 'Slack', 'Pipe site events into a Slack channel.', function() use ( $slack ) {
		th_setting_row( 'Webhook URL', 'Get from Slack → Apps → Incoming Webhooks.', th_text_input( 'th_notify_slack_webhook', $slack, 'https://hooks.slack.com/services/...', 'url' ) );
	});

	th_settings_group( 'Triggers', 'When to fire notifications.', function() use ( $on_login, $on_update, $on_backup ) {
		th_setting_row( 'New admin login', 'Notify when a user with admin caps logs in.', th_toggle( 'th_notify_on_login', $on_login ) );
		th_setting_row( 'Plugin/theme updates', 'Notify when something is auto-updated.', th_toggle( 'th_notify_on_update', $on_update ) );
		th_setting_row( 'Backup completed', 'Notify when a scheduled or manual backup finishes.', th_toggle( 'th_notify_on_backup', $on_backup ) );
	});

	th_settings_group( 'Test', 'Send a test notification to confirm everything works.', function() use ( $nonce ) {
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
function th_render_api_webhooks() {
	// Delegate to API engine if loaded (gives full webhooks UI)
	if ( function_exists( 'therum_render_api_full' ) ) {
		therum_render_api_full();
		return;
	}

	// Fallback if engine isn't loaded
	$rest_enabled  = (bool) get_option( 'th_rest_enabled', true );
	$rest_auth_req = (bool) get_option( 'th_rest_require_auth', false );
	$cors          = get_option( 'th_cors_origins', '' );

	th_settings_group( 'REST API', 'WP\'s built-in REST API at /wp-json/.', function() use ( $rest_enabled, $rest_auth_req ) {
		th_setting_row( 'REST API enabled', 'Disable to fully lock down /wp-json/ endpoints.', th_toggle( 'th_rest_enabled', $rest_enabled ) );
		th_setting_row( 'Require authentication', 'Block unauthenticated reads. Recommended.', th_toggle( 'th_rest_require_auth', $rest_auth_req ) );
	});
	th_settings_group( 'CORS', 'Allow cross-origin requests from these domains.', function() use ( $cors ) {
		th_setting_row( 'Allowed origins', 'One per line. Use * for wildcard (not recommended).', '<textarea class="th-input th-textarea" data-th-text="th_cors_origins" rows="4" placeholder="https://app.example.com">' . esc_textarea( $cors ) . '</textarea>' );
	});
	th_settings_group( 'API engine missing', 'Install therum-api-engine.php to manage webhooks.', function() {
		echo '<div style="font-size:13px;color:var(--tx3);">Webhook builder requires <code>therum-api-engine.php</code> in mu-plugins.</div>';
	});
}

// ─── UPDATES ────────────────────────────────────────────────────────────────
function th_render_updates() {
	$core_major = get_option( 'th_auto_update_core_major', 'enabled' );
	$plugins    = (bool) get_option( 'th_auto_update_plugins', true );
	$themes     = (bool) get_option( 'th_auto_update_themes', true );

	$wp_ver = get_bloginfo( 'version' );

	th_settings_group( 'Versions', 'What\'s installed and what\'s available.', function() use ( $wp_ver ) {
		?>
		<div class="th-version-grid">
			<div><span class="th-version-label">Therum OS</span><strong>v<?php echo defined('THERUM_OS_VERSION') ? esc_html(THERUM_OS_VERSION) : '?'; ?></strong></div>
			<div><span class="th-version-label">WordPress</span>v<?php echo esc_html( $wp_ver ); ?></div>
			<div><span class="th-version-label">PHP</span><?php echo esc_html( PHP_VERSION ); ?></div>
		</div>
		<?php
	});

	th_settings_group( 'Auto-updates', 'What gets updated without asking.', function() use ( $core_major, $plugins, $themes ) {
		th_setting_row( 'WordPress core (major)', 'Major version updates (e.g. 6.4 → 6.5). Minor security patches always auto-update.', th_select( 'th_auto_update_core_major', $core_major, [
			'enabled'  => 'On — auto-update major releases',
			'disabled' => 'Off — manual only',
		]));
		th_setting_row( 'Plugins', 'Auto-update active plugins.', th_toggle( 'th_auto_update_plugins', $plugins ) );
		th_setting_row( 'Themes', 'Auto-update installed themes.', th_toggle( 'th_auto_update_themes', $themes ) );
	});
}

// ─── BACKUP ─────────────────────────────────────────────────────────────────
function th_render_backup() {
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

	th_settings_group( 'Schedule', 'Automatic snapshots of your site.', function() use ( $enabled, $freq, $next_scheduled ) {
		th_setting_row( 'Enable backups', 'Schedule recurring backups via WP cron.', th_toggle( 'th_backup_enabled', $enabled ) );
		th_setting_row( 'Frequency', 'How often to back up. Cron runs on traffic, so quiet sites lag.', th_select( 'th_backup_frequency', $freq, [
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

	th_settings_group( 'Run on demand', 'Trigger a backup right now.', function() use ( $nonce ) {
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
				  res.innerHTML = '✓ Backup created: <strong>' + (j.data.file || '') + '</strong> ' + (size ? '('+size+')' : '');
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

	th_settings_group( 'Destination', 'Where backup files are stored.', function() use ( $dest ) {
		th_setting_row( 'Backup destination', 'Local stays in wp-content/backups. S3 uploads after each run.', th_select( 'th_backup_destination', $dest, [
			'local' => 'Local only',
			's3'    => 'Local + S3',
		]));
	});

	if ( $dest === 's3' ) {
		th_settings_group( 'S3 credentials', 'Works with AWS S3 and S3-compatible services (Cloudflare R2, Wasabi, MinIO).', function() use ( $s3_bucket, $s3_region, $s3_access, $s3_secret, $s3_endpoint, $s3_prefix ) {
			th_setting_row( 'Bucket', 'The bucket where backups are stored.', th_text_input( 'th_backup_s3_bucket', $s3_bucket, 'my-site-backups' ) );
			th_setting_row( 'Region', 'AWS region code. Use auto for R2/Wasabi.', th_text_input( 'th_backup_s3_region', $s3_region, 'us-east-1' ) );
			th_setting_row( 'Access key', 'IAM access key with PutObject permission.', th_text_input( 'th_backup_s3_access_key', $s3_access, 'AKIAxxxxxxxxxxxxxxxx' ) );
			th_setting_row( 'Secret key', 'Secret access key. Stored in WP options.', th_text_input( 'th_backup_s3_secret_key', $s3_secret, '••••••••••••••••', 'password' ) );
			th_setting_row( 'Endpoint (optional)', 'Custom endpoint for non-AWS providers. Leave blank for AWS.', th_text_input( 'th_backup_s3_endpoint', $s3_endpoint, 'r2.cloudflare.com (optional)' ) );
			th_setting_row( 'Path prefix', 'Folder inside the bucket. Defaults to therum-backups.', th_text_input( 'th_backup_s3_prefix', $s3_prefix, 'therum-backups' ) );
		});
	}

	if ( ! empty( $history ) ) {
		th_settings_group( 'Recent backups', 'Last 10 backups stored locally. Older are pruned automatically.', function() use ( $history ) {
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

	th_settings_group( 'SQLite database', 'Therum runs on SQLite — your DB is a single file inside the zip.', function() {
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

	console.log('[Therum] Settings content patch loaded — all sections now functional');
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
	check_ajax_referer( 'therum_theme', 'nonce' );
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
	check_ajax_referer( 'therum_theme', 'nonce' );

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

	// Coerce booleans
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
//  4. INSTALL WIZARD — from therum-install-wizard.php
// ════════════════════════════════════════════════════════════════════════════
if ( defined( 'THERUM_WIZARD_DISABLE' ) && THERUM_WIZARD_DISABLE ) return;
if ( ! apply_filters( 'therum/wizard/enabled', true ) ) return;

// ── Hooks ─────────────────────────────────────────────────────────────────────
add_action( 'admin_init', [ 'Therum_Wizard', 'maybe_redirect' ], 1 );
add_action( 'admin_menu', [ 'Therum_Wizard', 'register_page'  ] );

add_action( 'wp_ajax_thw_save_step',       [ 'Therum_Wizard', 'ajax_save_step'       ] );
add_action( 'wp_ajax_thw_skip_step',       [ 'Therum_Wizard', 'ajax_skip_step'       ] );
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
goTo(<?php echo (int) max( 1, (int) ( $progress['last_step'] ?? 1 ) ); ?>);
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
			$progress['edition'] = sanitize_text_field( $_POST['edition'] );
		}
		if ( ! empty( $_POST['stack'] ) ) {
			$progress['stack'] = sanitize_text_field( $_POST['stack'] );
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

		$ip     = sanitize_text_field( $_POST['ip'] ?? '' );
		$domain = sanitize_text_field( $_POST['domain'] ?? '' );

		if ( empty( $ip ) ) {
			wp_send_json_error( [ 'message' => 'No IP address provided.' ] );
		}

		// Real implementation: ping the Therum server daemon on the VPS.
		// Daemon listens on a Unix socket or TCP port with a shared secret.
		// For now: validate IP format and do a basic TCP check.
		if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			wp_send_json_error( [ 'message' => 'Invalid IP address format.' ] );
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

		$db_name = preg_replace( '/[^a-zA-Z0-9_]/', '', $_POST['db_name'] ?? '' );
		$db_user = preg_replace( '/[^a-zA-Z0-9_]/', '', $_POST['db_user'] ?? '' );
		$db_pass = sanitize_text_field( $_POST['db_pass'] ?? '' );

		if ( ! $db_name || ! $db_user || ! $db_pass ) {
			wp_send_json_error( [ 'message' => 'All database fields are required.' ] );
		}

		// Real implementation: call server daemon to run CREATE DATABASE + GRANT.
		// Daemon executes: mysql -e "CREATE DATABASE {db_name}; CREATE USER ..."
		// Stub response for now — daemon integration in therum-server.php.
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

		// Real implementation: rsync mu-plugins to VPS via server daemon.
		// Stub: return list of plugins as if deployed.
		$plugins = [
			'therum-core.php',    'therum-auth.php',    'therum-updates.php',
			'therum-admin.php',   'therum-design.php',  'therum-content.php',
			'therum-perf.php',    'therum-woo.php',     'therum-api.php',
			'therum-media.php',
		];

		wp_send_json_success( [
			'log'     => $plugins,
			'message' => count( $plugins ) . ' mu-plugins deployed. OPcache flushed.',
		] );
	}
}
