<?php
/**
 * Plugin Name: Therum OS — Tools
 * Description: Power-user tools: ⌘K command palette, activity log, undo toast,
 *              bulk actions, redirect manager, global find & replace, favorites,
 *              keyboard shortcuts, content calendar, broken link checker, DB optimizer.
 * Version: 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;


// ════════════════════════════════════════════════════════════════════════════
//  1. ACTIVITY LOG — lightweight audit trail
// ════════════════════════════════════════════════════════════════════════════

class Therum_Activity_Log {

	const TABLE_SUFFIX = 'therum_activity_log';
	const MAX_ROWS     = 5000; // auto-prune beyond this

	public static function init(): void {
		self::ensure_table();
		// Hook into common actions
		add_action( 'save_post',              [ __CLASS__, 'on_save_post' ], 999, 3 );
		add_action( 'delete_post',            [ __CLASS__, 'on_delete_post' ] );
		add_action( 'wp_trash_post',          [ __CLASS__, 'on_trash_post' ] );
		add_action( 'activated_plugin',       [ __CLASS__, 'on_plugin_activate' ] );
		add_action( 'deactivated_plugin',     [ __CLASS__, 'on_plugin_deactivate' ] );
		add_action( 'wp_login',               [ __CLASS__, 'on_login' ], 10, 2 );
		add_action( 'switch_theme',           [ __CLASS__, 'on_switch_theme' ] );
		add_action( 'updated_option',         [ __CLASS__, 'on_option_update' ], 10, 3 );
		add_action( 'therum_duplicate_after', [ __CLASS__, 'on_duplicate' ], 10, 3 );
		// Register Settings section
		add_action( 'init', function() {
			if ( class_exists( 'Therum_Settings' ) ) {
				Therum_Settings::register( 'activity', [
					'label'    => 'Activity',
					'icon'     => 'clock',
					'desc'     => 'Audit trail of changes.',
					'priority' => 115,
					'render'   => [ __CLASS__, 'render_settings' ],
				] );
			}
		}, 20 );
	}

	private static function ensure_table(): void {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE_SUFFIX;
		if ( get_option( '_therum_activity_log_v' ) === '1' ) return;
		$charset = $wpdb->get_charset_collate();
		$wpdb->query( "CREATE TABLE IF NOT EXISTS `$table` (
			id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
			ts DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			user_name VARCHAR(100) NOT NULL DEFAULT '',
			action VARCHAR(60) NOT NULL DEFAULT '',
			object_type VARCHAR(40) NOT NULL DEFAULT '',
			object_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			object_title VARCHAR(255) NOT NULL DEFAULT '',
			detail TEXT NOT NULL DEFAULT ''
		) $charset" );
		update_option( '_therum_activity_log_v', '1', true );
	}

	public static function log( string $action, string $obj_type = '', int $obj_id = 0, string $obj_title = '', string $detail = '' ): void {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE_SUFFIX;
		$user  = wp_get_current_user();
		$wpdb->insert( $table, [
			'user_id'      => $user->ID ?? 0,
			'user_name'    => $user->display_name ?? 'system',
			'action'       => substr( $action, 0, 60 ),
			'object_type'  => substr( $obj_type, 0, 40 ),
			'object_id'    => $obj_id,
			'object_title' => substr( $obj_title, 0, 255 ),
			'detail'       => substr( $detail, 0, 2000 ),
		] );
		// Auto-prune
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `$table`" );
		if ( $count > self::MAX_ROWS ) {
			$keep_id = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM `$table` ORDER BY id DESC LIMIT 1 OFFSET %d", self::MAX_ROWS
			) );
			if ( $keep_id ) $wpdb->query( $wpdb->prepare( "DELETE FROM `$table` WHERE id < %d", $keep_id ) );
		}
	}

	// ── Hooks ────────────────────────────────────────────────────────────
	public static function on_save_post( $post_id, $post, $update ): void {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) return;
		if ( in_array( $post->post_type, [ 'nav_menu_item', 'revision', 'customize_changeset' ], true ) ) return;
		$action = $update ? 'updated' : 'created';
		self::log( $action, $post->post_type, $post_id, $post->post_title, 'Status: ' . $post->post_status );
	}
	public static function on_delete_post( $post_id ): void {
		$p = get_post( $post_id );
		if ( $p ) self::log( 'deleted', $p->post_type, $post_id, $p->post_title );
	}
	public static function on_trash_post( $post_id ): void {
		$p = get_post( $post_id );
		if ( $p ) self::log( 'trashed', $p->post_type, $post_id, $p->post_title );
	}
	public static function on_plugin_activate( $plugin ): void {
		self::log( 'activated', 'plugin', 0, $plugin );
	}
	public static function on_plugin_deactivate( $plugin ): void {
		self::log( 'deactivated', 'plugin', 0, $plugin );
	}
	public static function on_login( $user_login, $user ): void {
		self::log( 'login', 'user', $user->ID, $user->display_name, 'IP: ' . ( $_SERVER['REMOTE_ADDR'] ?? 'unknown' ) );
	}
	public static function on_switch_theme( $new_name ): void {
		self::log( 'switched_theme', 'theme', 0, $new_name );
	}
	public static function on_option_update( $option, $old, $new ): void {
		$track = [ 'blogname', 'blogdescription', 'admin_email', 'default_role', 'permalink_structure' ];
		if ( in_array( $option, $track, true ) ) {
			self::log( 'changed_option', 'option', 0, $option, 'Old: ' . mb_substr( (string) $old, 0, 200 ) );
		}
	}
	public static function on_duplicate( $new_id, $source_id, $source ): void {
		self::log( 'duplicated', $source->post_type, $new_id, $source->post_title . ' → copy', 'Source: #' . $source_id );
	}

	// ── AJAX: fetch log entries ──────────────────────────────────────────
	public static function ajax_fetch(): void {
		if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'forbidden' );
		// Nonce required — this returns the full audit trail; without it a forged
		// request could exfiltrate it via a logged-in admin's browser (CSRF). A
		// caller must send _wpnonce for the 'therum_options' action.
		check_ajax_referer( 'therum_options', '_wpnonce' );
		global $wpdb;
		$table  = $wpdb->prefix . self::TABLE_SUFFIX;
		$offset = max( 0, (int) ( $_GET['offset'] ?? 0 ) );
		$limit  = min( 100, max( 10, (int) ( $_GET['limit'] ?? 50 ) ) );
		$rows   = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM `$table` ORDER BY id DESC LIMIT %d OFFSET %d", $limit, $offset
		) );
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `$table`" );
		wp_send_json_success( [ 'rows' => $rows, 'total' => $total ] );
	}

	// ── Settings page renderer ───────────────────────────────────────────
	public static function render_settings(): void {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE_SUFFIX;
		$rows  = $wpdb->get_results( "SELECT * FROM `$table` ORDER BY id DESC LIMIT 50" );
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `$table`" );

		if ( function_exists( 'therum_settings_group' ) ) {
			therum_settings_group( 'Recent activity', $total . ' events recorded. Oldest auto-pruned at ' . self::MAX_ROWS . '.', function() use ( $rows ) {
				if ( empty( $rows ) ) {
					echo '<p style="color:var(--tx3);font-size:13px;">No activity recorded yet.</p>';
					return;
				}
				echo '<div style="max-height:500px;overflow-y:auto;">';
				echo '<table style="width:100%;font-size:12px;border-collapse:collapse;">';
				echo '<thead><tr style="text-align:left;border-bottom:1px solid var(--bd);">
					<th style="padding:8px;">When</th>
					<th style="padding:8px;">User</th>
					<th style="padding:8px;">Action</th>
					<th style="padding:8px;">Object</th>
					<th style="padding:8px;">Detail</th>
				</tr></thead><tbody>';
				foreach ( $rows as $r ) {
					$ago = human_time_diff( strtotime( $r->ts ) );
					echo '<tr style="border-bottom:1px solid var(--bd);">';
					echo '<td style="padding:6px 8px;white-space:nowrap;color:var(--tx3);">' . esc_html( $ago ) . ' ago</td>';
					echo '<td style="padding:6px 8px;font-weight:500;">' . esc_html( $r->user_name ) . '</td>';
					echo '<td style="padding:6px 8px;"><span style="padding:2px 8px;border-radius:4px;background:var(--sf2);font-size:11px;font-weight:500;">' . esc_html( $r->action ) . '</span></td>';
					echo '<td style="padding:6px 8px;">' . esc_html( $r->object_type ) . ( $r->object_title ? ': ' . esc_html( $r->object_title ) : '' ) . '</td>';
					echo '<td style="padding:6px 8px;color:var(--tx3);max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">' . esc_html( $r->detail ) . '</td>';
					echo '</tr>';
				}
				echo '</tbody></table></div>';
			} );
		}
	}
}

add_action( 'init', [ 'Therum_Activity_Log', 'init' ] );
add_action( 'wp_ajax_therum_activity_log', [ 'Therum_Activity_Log', 'ajax_fetch' ] );


// ════════════════════════════════════════════════════════════════════════════
//  2. REDIRECT MANAGER + 404 MONITOR — native replacement for the Redirection
//     plugin. 301/302/307/308/410 rules (exact + regex), auto-capture on slug
//     change, aggregated 404 logging with one-click "create redirect", hit +
//     last-seen tracking, enable/disable, and import/export.
// ════════════════════════════════════════════════════════════════════════════

class Therum_Redirects {

	const OPTION_KEY  = 'therum_redirects';      // redirect rules, keyed by source
	const OPTION_404  = 'therum_404_log';        // aggregated 404 hits, keyed by path
	const OPTION_CFG  = 'therum_redirects_cfg';  // { log_404: bool }
	const MAX_404     = 500;                      // cap distinct logged 404 paths
	const VALID_CODES = [ 301, 302, 307, 308, 410 ];

	public static function init(): void {
		// Auto-capture slug changes as 301s.
		add_action( 'post_updated', [ __CLASS__, 'on_slug_change' ], 10, 3 );
		// Match redirects early (parity with the Redirection plugin), then log
		// any unmatched 404 at the tail of template_redirect.
		// Priority 1 keeps the redirect ahead of any other plugin that
		// rewrites the request; priority 999 on log_404 ensures every other
		// 404 handler has already run.
		add_action( 'template_redirect', [ __CLASS__, 'maybe_redirect' ], 1 );
		add_action( 'template_redirect', [ __CLASS__, 'log_404' ], 999 );
		// Drain batched hit counts: on shutdown of admin requests (cheap)
		// and via a daily cron so write contention from high-traffic 301s
		// never lands inline on the redirect path.
		add_action( 'shutdown', function() {
			if ( ! is_admin() ) return;
			Therum_Redirects::flush_hits();
			Therum_Redirects::flush_404();
		} );
		if ( ! wp_next_scheduled( 'therum_redirects_flush' ) ) {
			wp_schedule_event( time() + 300, 'hourly', 'therum_redirects_flush' );
		}
		add_action( 'therum_redirects_flush', [ __CLASS__, 'flush_hits' ] );
		add_action( 'therum_redirects_flush', [ __CLASS__, 'flush_404' ] );
		// AJAX
		add_action( 'wp_ajax_therum_redirects_save',   [ __CLASS__, 'ajax_save' ] );
		add_action( 'wp_ajax_therum_redirects_delete', [ __CLASS__, 'ajax_delete' ] );
		add_action( 'wp_ajax_therum_redirects_toggle', [ __CLASS__, 'ajax_toggle' ] );
		add_action( 'wp_ajax_therum_redirects_import', [ __CLASS__, 'ajax_import' ] );
		add_action( 'wp_ajax_therum_redirects_export', [ __CLASS__, 'ajax_export' ] );
		add_action( 'wp_ajax_therum_404_clear',        [ __CLASS__, 'ajax_404_clear' ] );
		add_action( 'wp_ajax_therum_404_toggle',       [ __CLASS__, 'ajax_404_toggle' ] );
		// Settings section
		add_action( 'init', function() {
			if ( class_exists( 'Therum_Settings' ) ) {
				Therum_Settings::register( 'redirects', [
					'label'    => 'Redirects',
					'icon'     => 'external',
					'desc'     => '301/302 redirects + 404 monitor.',
					'priority' => 92,
					'render'   => [ __CLASS__, 'render_settings' ],
				] );
			}
		}, 20 );
	}

	// ── Config ──────────────────────────────────────────────────────────────
	private static function cfg(): array {
		return wp_parse_args( (array) get_option( self::OPTION_CFG, [] ), [ 'log_404' => true ] );
	}

	// ── Rules CRUD ──────────────────────────────────────────────────────────
	public static function get_all(): array {
		return (array) get_option( self::OPTION_KEY, [] );
	}

	/** Normalize a URL or path down to a leading-slash path. */
	private static function norm_path( string $p ): string {
		$path = wp_parse_url( $p, PHP_URL_PATH );
		if ( ! is_string( $path ) || $path === '' ) $path = $p;
		return '/' . ltrim( $path, '/' );
	}

	/** Delimit a user regex safely for preg_*. */
	private static function wrap_regex( string $pattern ): string {
		return '~' . str_replace( '~', '\~', $pattern ) . '~';
	}

	/**
	 * Upsert a redirect rule. Returns false if a regex pattern won't compile.
	 */
	public static function add( string $from, string $to, string $source = 'manual', int $code = 301, bool $regex = false ): bool {
		if ( ! in_array( $code, self::VALID_CODES, true ) ) $code = 301;
		$key = $regex ? trim( $from ) : self::norm_path( $from );
		if ( $key === '' || ( $to === '' && 410 !== $code ) ) return false;
		if ( $regex && @preg_match( self::wrap_regex( $key ), '' ) === false ) return false;

		$rules = self::get_all();
		$rules[ $key ] = [
			'to'      => $to,
			'code'    => $code,
			'regex'   => $regex,
			'source'  => $source,
			'created' => $rules[ $key ]['created'] ?? current_time( 'mysql' ),
			'hits'    => (int) ( $rules[ $key ]['hits'] ?? 0 ),
			'last'    => $rules[ $key ]['last'] ?? null,
			'enabled' => true,
		];
		update_option( self::OPTION_KEY, $rules, false );
		return true;
	}

	public static function on_slug_change( $post_id, $post_after, $post_before ): void {
		if ( $post_before->post_name === $post_after->post_name ) return;
		if ( $post_after->post_status !== 'publish' ) return;
		$old_url = get_permalink( $post_before );
		$new_url = get_permalink( $post_after );
		if ( $old_url && $new_url && $old_url !== $new_url ) {
			self::add( $old_url, $new_url, 'auto-slug-change', 301, false );
			if ( class_exists( 'Therum_Activity_Log' ) ) {
				Therum_Activity_Log::log( 'redirect_created', $post_after->post_type, $post_id, $post_after->post_title, $old_url . ' → ' . $new_url );
			}
		}
	}

	// ── Front-end matching ────────────────────────────────────────────────────
	public static function maybe_redirect(): void {
		if ( is_admin() ) return;
		$path  = self::norm_path( (string) ( $_SERVER['REQUEST_URI'] ?? '' ) );
		$rules = self::get_all();

		$match = null; $target = null; $code = 301;

		// 1. Exact path match.
		if ( isset( $rules[ $path ] ) && ! empty( $rules[ $path ]['enabled'] ) && empty( $rules[ $path ]['regex'] ) ) {
			$match  = $path;
			$target = (string) $rules[ $path ]['to'];
			$code   = (int) ( $rules[ $path ]['code'] ?? 301 );
		} else {
			// 2. Regex rules, in definition order.
			foreach ( $rules as $key => $r ) {
				if ( empty( $r['regex'] ) || empty( $r['enabled'] ) ) continue;
				$rx = self::wrap_regex( (string) $key );
				if ( @preg_match( $rx, $path ) === 1 ) {
					$match  = $key;
					$target = (string) @preg_replace( $rx, (string) $r['to'], $path );
					$code   = (int) ( $r['code'] ?? 301 );
					break;
				}
			}
		}

		if ( $match === null ) return;
		// 410 Gone has no target; everything else needs one and must not loop.
		if ( 410 !== $code ) {
			if ( $target === '' || self::norm_path( $target ) === $path ) return;
		}

		// Batch hit counts into a transient so a high-traffic 301 doesn't
		// turn into a write-on-every-hit on the options table. The counts
		// flush back to the rule store on a 5-minute heartbeat (see the
		// 'shutdown' handler below) or whenever the rules page is loaded.
		$bucket = (array) get_transient( self::OPTION_KEY . '_hits' );
		$bucket[ $match ] = [
			'count' => (int) ( $bucket[ $match ]['count'] ?? 0 ) + 1,
			'last'  => current_time( 'mysql' ),
		];
		set_transient( self::OPTION_KEY . '_hits', $bucket, 5 * MINUTE_IN_SECONDS );

		if ( 410 === $code ) {
			status_header( 410 );
			nocache_headers();
			exit;
		}
		wp_redirect( $target, in_array( $code, self::VALID_CODES, true ) ? $code : 301 );
		exit;
	}

	/**
	 * Drain the batched hit counts into the rules option. Called from the
	 * rules-page render and on a scheduled heartbeat — never inline on the
	 * redirect path (which is the hot path).
	 */
	public static function flush_hits(): void {
		$bucket = (array) get_transient( self::OPTION_KEY . '_hits' );
		if ( ! $bucket ) return;
		$rules = self::rules();
		$changed = false;
		foreach ( $bucket as $key => $rec ) {
			if ( ! isset( $rules[ $key ] ) ) continue;
			$rules[ $key ]['hits'] = (int) ( $rules[ $key ]['hits'] ?? 0 ) + (int) ( $rec['count'] ?? 0 );
			$rules[ $key ]['last'] = (string) ( $rec['last'] ?? current_time( 'mysql' ) );
			$changed = true;
		}
		if ( $changed ) update_option( self::OPTION_KEY, $rules, false );
		delete_transient( self::OPTION_KEY . '_hits' );
	}

	/**
	 * Aggregate unmatched 404s (path → hits/last/referrer/UA), capped.
	 *
	 * The hot path writes to a short-lived transient, NOT update_option, so a
	 * bot scan or broken-link sweep can't generate one autoload-touching DB
	 * write per request. flush_404() drains the transient into the real option.
	 */
	public static function log_404(): void {
		if ( is_admin() || ! is_404() ) return;
		if ( empty( self::cfg()['log_404'] ) ) return;
		$path = self::norm_path( (string) ( $_SERVER['REQUEST_URI'] ?? '' ) );
		if ( $path === '/' ) return;
		foreach ( [ '/favicon.ico', '/robots.txt', '/apple-touch', '/.well-known', '/wp-sitemap', '/sitemap' ] as $ignore ) {
			if ( str_starts_with( $path, $ignore ) ) return;
		}

		$bucket = (array) get_transient( self::OPTION_404 . '_buf' );
		if ( isset( $bucket[ $path ] ) ) {
			$bucket[ $path ]['hits'] = (int) ( $bucket[ $path ]['hits'] ?? 0 ) + 1;
			$bucket[ $path ]['last'] = current_time( 'mysql' );
		} else {
			// Bound the buffer too — pathological 404 floods otherwise grow it
			// without bound between flushes. Hard cap at MAX_404.
			if ( count( $bucket ) >= self::MAX_404 ) {
				uasort( $bucket, static fn( $a, $b ) => strcmp( (string) ( $a['last'] ?? '' ), (string) ( $b['last'] ?? '' ) ) );
				array_shift( $bucket );
			}
			$bucket[ $path ] = [
				'hits'  => 1,
				'first' => current_time( 'mysql' ),
				'last'  => current_time( 'mysql' ),
				'ref'   => esc_url_raw( (string) ( $_SERVER['HTTP_REFERER'] ?? '' ) ),
				'ua'    => substr( sanitize_text_field( (string) ( $_SERVER['HTTP_USER_AGENT'] ?? '' ) ), 0, 180 ),
			];
		}
		set_transient( self::OPTION_404 . '_buf', $bucket, 5 * MINUTE_IN_SECONDS );
	}

	/**
	 * Merge the buffered 404 bucket into the persistent log. Runs on admin
	 * shutdown and from the hourly redirect-flush cron — same drain points as
	 * redirect hit counters.
	 */
	public static function flush_404(): void {
		$bucket = (array) get_transient( self::OPTION_404 . '_buf' );
		if ( ! $bucket ) return;
		$log = (array) get_option( self::OPTION_404, [] );
		foreach ( $bucket as $path => $rec ) {
			if ( isset( $log[ $path ] ) ) {
				$log[ $path ]['hits'] = (int) ( $log[ $path ]['hits'] ?? 0 ) + (int) ( $rec['hits'] ?? 1 );
				$log[ $path ]['last'] = (string) ( $rec['last'] ?? current_time( 'mysql' ) );
			} else {
				if ( count( $log ) >= self::MAX_404 ) {
					uasort( $log, static fn( $a, $b ) => strcmp( (string) ( $a['last'] ?? '' ), (string) ( $b['last'] ?? '' ) ) );
					array_shift( $log );
				}
				$log[ $path ] = $rec;
			}
		}
		update_option( self::OPTION_404, $log, false );
		delete_transient( self::OPTION_404 . '_buf' );
	}

	// ── AJAX: rules ───────────────────────────────────────────────────────────
	public static function ajax_save(): void {
		if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'forbidden' );
		check_ajax_referer( 'therum_options', 'nonce' );
		$from  = sanitize_text_field( wp_unslash( $_POST['from'] ?? '' ) );
		$to    = sanitize_text_field( wp_unslash( $_POST['to'] ?? '' ) );
		$code  = (int) ( $_POST['code'] ?? 301 );
		$regex = ! empty( $_POST['regex'] ) && $_POST['regex'] !== '0';
		if ( $from === '' ) wp_send_json_error( 'A source path is required.' );
		if ( ! self::add( $from, $to, 'manual', $code, $regex ) ) {
			wp_send_json_error( $regex ? 'Invalid regex pattern.' : 'A target is required.' );
		}
		// Adding a redirect for a logged 404 clears it from the monitor.
		$log = (array) get_option( self::OPTION_404, [] );
		$key = self::norm_path( $from );
		if ( isset( $log[ $key ] ) ) { unset( $log[ $key ] ); update_option( self::OPTION_404, $log, false ); }
		wp_send_json_success( [ 'count' => count( self::get_all() ) ] );
	}

	public static function ajax_delete(): void {
		if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'forbidden' );
		check_ajax_referer( 'therum_options', 'nonce' );
		$from  = sanitize_text_field( wp_unslash( $_POST['from'] ?? '' ) );
		$rules = self::get_all();
		unset( $rules[ $from ] );
		update_option( self::OPTION_KEY, $rules, false );
		wp_send_json_success();
	}

	public static function ajax_toggle(): void {
		if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'forbidden' );
		check_ajax_referer( 'therum_options', 'nonce' );
		$from  = sanitize_text_field( wp_unslash( $_POST['from'] ?? '' ) );
		$rules = self::get_all();
		if ( ! isset( $rules[ $from ] ) ) wp_send_json_error( 'unknown rule' );
		$rules[ $from ]['enabled'] = empty( $rules[ $from ]['enabled'] );
		update_option( self::OPTION_KEY, $rules, false );
		wp_send_json_success( [ 'enabled' => $rules[ $from ]['enabled'] ] );
	}

	public static function ajax_import(): void {
		if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'forbidden' );
		check_ajax_referer( 'therum_options', 'nonce' );
		$raw = trim( (string) wp_unslash( $_POST['data'] ?? '' ) );
		if ( $raw === '' ) wp_send_json_error( 'Nothing to import.' );

		$added = 0;
		$first = ltrim( $raw )[0] ?? '';
		if ( $first === '[' || $first === '{' ) {
			// JSON: array of {from,to,code,regex} (Therum export shape).
			$rows = json_decode( $raw, true );
			if ( ! is_array( $rows ) ) wp_send_json_error( 'Invalid JSON.' );
			foreach ( $rows as $row ) {
				if ( ! is_array( $row ) ) continue;
				$from = sanitize_text_field( (string) ( $row['from'] ?? '' ) );
				$to   = sanitize_text_field( (string) ( $row['to'] ?? '' ) );
				$code = (int) ( $row['code'] ?? 301 );
				$rx   = ! empty( $row['regex'] );
				if ( $from !== '' && self::add( $from, $to, 'import', $code, $rx ) ) $added++;
			}
		} else {
			// CSV: "source,target[,code]" per line (matches Redirection CSV export).
			foreach ( preg_split( '/\r\n|\r|\n/', $raw ) as $line ) {
				$line = trim( $line );
				if ( $line === '' || str_starts_with( $line, '#' ) ) continue;
				$cols = str_getcsv( $line );
				$from = sanitize_text_field( trim( (string) ( $cols[0] ?? '' ) ) );
				$to   = sanitize_text_field( trim( (string) ( $cols[1] ?? '' ) ) );
				$code = (int) ( $cols[2] ?? 301 );
				if ( strcasecmp( $from, 'source' ) === 0 ) continue; // header row
				if ( $from !== '' && $to !== '' && self::add( $from, $to, 'import', $code, false ) ) $added++;
			}
		}
		wp_send_json_success( [ 'added' => $added, 'count' => count( self::get_all() ) ] );
	}

	public static function ajax_export(): void {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( 'forbidden', 403 );
		check_admin_referer( 'therum_options', '_wpnonce' );
		$out = [];
		foreach ( self::get_all() as $from => $r ) {
			$out[] = [
				'from'  => (string) $from,
				'to'    => (string) ( $r['to'] ?? '' ),
				'code'  => (int) ( $r['code'] ?? 301 ),
				'regex' => ! empty( $r['regex'] ),
			];
		}
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="therum-redirects-' . gmdate( 'Y-m-d' ) . '.json"' );
		echo wp_json_encode( $out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		exit;
	}

	// ── AJAX: 404 monitor ─────────────────────────────────────────────────────
	public static function ajax_404_clear(): void {
		if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'forbidden' );
		check_ajax_referer( 'therum_options', 'nonce' );
		$path = sanitize_text_field( wp_unslash( $_POST['path'] ?? '' ) );
		if ( $path === '*' ) {
			update_option( self::OPTION_404, [], false );
		} else {
			$log = (array) get_option( self::OPTION_404, [] );
			unset( $log[ $path ] );
			update_option( self::OPTION_404, $log, false );
		}
		wp_send_json_success();
	}

	public static function ajax_404_toggle(): void {
		if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'forbidden' );
		check_ajax_referer( 'therum_options', 'nonce' );
		$cfg = self::cfg();
		$cfg['log_404'] = empty( $cfg['log_404'] );
		update_option( self::OPTION_CFG, $cfg, false );
		wp_send_json_success( [ 'log_404' => $cfg['log_404'] ] );
	}

	// ── Settings UI ─────────────────────────────────────────────────────────
	public static function render_settings(): void {
		$rules  = self::get_all();
		$log    = (array) get_option( self::OPTION_404, [] );
		$log404 = ! empty( self::cfg()['log_404'] );
		$nonce  = wp_create_nonce( 'therum_options' );
		$export = wp_nonce_url( admin_url( 'admin-ajax.php?action=therum_redirects_export' ), 'therum_options' );
		if ( ! function_exists( 'therum_settings_group' ) ) return;

		// Most-hit 404s first.
		uasort( $log, static fn( $a, $b ) => (int) ( $b['hits'] ?? 0 ) <=> (int) ( $a['hits'] ?? 0 ) );

		$code_opts = static function ( int $sel ): string {
			$labels = [ 301 => '301 Permanent', 302 => '302 Temporary', 307 => '307 Temporary', 308 => '308 Permanent', 410 => '410 Gone' ];
			$h = '';
			foreach ( $labels as $c => $l ) $h .= '<option value="' . $c . '"' . selected( $sel, $c, false ) . '>' . esc_html( $l ) . '</option>';
			return $h;
		};

		therum_settings_group( 'Redirect rules', count( $rules ) . ' rule' . ( count( $rules ) === 1 ? '' : 's' ) . '. Auto-created on slug change, or add your own (exact path or regex).', function () use ( $rules, $nonce, $export, $code_opts ) {
			?>
			<div data-th-redirects data-nonce="<?php echo esc_attr( $nonce ); ?>">
			<div style="display:flex;gap:8px;margin-bottom:6px;flex-wrap:wrap;">
				<input type="text" class="th-input" placeholder="/old-path  (or regex)" style="flex:2;min-width:160px;" data-th-redir-from />
				<span style="color:var(--tx3);align-self:center;">→</span>
				<input type="text" class="th-input" placeholder="/new-path or https://…" style="flex:2;min-width:160px;" data-th-redir-to />
				<select class="th-input" data-th-redir-code style="flex:0 0 auto;"><?php echo $code_opts( 301 ); // phpcs:ignore ?></select>
				<label style="display:flex;align-items:center;gap:5px;font-size:12px;color:var(--tx2);"><input type="checkbox" data-th-redir-regex /> regex</label>
				<button type="button" class="th-btn th-btn-primary" data-th-redir-add>Add</button>
			</div>
			<div style="display:flex;gap:10px;margin-bottom:14px;font-size:12px;">
				<a class="th-btn" href="<?php echo esc_url( $export ); ?>">Export JSON</a>
				<button type="button" class="th-btn" data-th-redir-import-toggle>Import…</button>
			</div>
			<div data-th-redir-import style="display:none;margin-bottom:14px;">
				<textarea class="th-input" data-th-redir-import-data rows="4" style="width:100%;font-family:ui-monospace,monospace;font-size:11px;" placeholder="Paste CSV (source,target,code) or a Therum JSON export"></textarea>
				<button type="button" class="th-btn th-btn-primary" style="margin-top:6px;" data-th-redir-import-run>Import</button>
			</div>
			<?php if ( empty( $rules ) ): ?>
				<p style="color:var(--tx3);font-size:13px;">No redirects yet. Created automatically when you change a published page's slug, or add one above.</p>
			<?php else: ?>
				<table style="width:100%;font-size:12px;border-collapse:collapse;">
				<thead><tr style="text-align:left;border-bottom:1px solid var(--bd);">
					<th style="padding:8px;">From</th><th style="padding:8px;">To</th><th style="padding:8px;">Code</th><th style="padding:8px;">Source</th><th style="padding:8px;">Hits</th><th style="padding:8px;width:90px;"></th>
				</tr></thead><tbody>
				<?php foreach ( $rules as $from => $r ):
					$enabled = ! empty( $r['enabled'] ); ?>
					<tr style="border-bottom:1px solid var(--bd);<?php echo $enabled ? '' : 'opacity:.5;'; ?>" data-th-redir-row="<?php echo esc_attr( $from ); ?>">
						<td style="padding:6px 8px;font-family:ui-monospace,monospace;font-size:11px;"><?php echo esc_html( $from ); ?><?php echo ! empty( $r['regex'] ) ? ' <span style="color:var(--ac);font-size:9px;">RE</span>' : ''; ?></td>
						<td style="padding:6px 8px;font-size:11px;color:var(--tx2);"><?php echo esc_html( (string) ( $r['to'] ?? '' ) ); ?></td>
						<td style="padding:6px 8px;color:var(--tx3);"><?php echo (int) ( $r['code'] ?? 301 ); ?></td>
						<td style="padding:6px 8px;"><span style="padding:2px 8px;border-radius:4px;background:var(--sf2);font-size:11px;"><?php echo esc_html( $r['source'] ?? 'manual' ); ?></span></td>
						<td style="padding:6px 8px;color:var(--tx3);"><?php echo (int) ( $r['hits'] ?? 0 ); ?></td>
						<td style="padding:6px 8px;text-align:right;white-space:nowrap;">
							<button type="button" class="th-btn" style="padding:4px 7px;font-size:11px;" data-th-redir-toggle="<?php echo esc_attr( $from ); ?>"><?php echo $enabled ? 'On' : 'Off'; ?></button>
							<button type="button" class="th-btn" style="padding:4px 7px;font-size:11px;color:var(--err);" data-th-redir-del="<?php echo esc_attr( $from ); ?>">Delete</button>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody></table>
			<?php endif; ?>
			</div>
			<script>
			(function(){
				var wrap = document.querySelector('[data-th-redirects]');
				if (!wrap) return;
				var nonce = wrap.dataset.nonce;
				var ajax = window.ajaxurl || '/wp-admin/admin-ajax.php';
				function post(data, cb){ var fd=new FormData(); fd.append('nonce',nonce); Object.keys(data).forEach(function(k){fd.append(k,data[k]);}); fetch(ajax,{method:'POST',credentials:'same-origin',body:fd}).then(function(r){return r.json();}).then(cb).catch(function(){ if(window.therumToast)window.therumToast('Network error'); }); }
				wrap.querySelector('[data-th-redir-add]').addEventListener('click', function(){
					var f=wrap.querySelector('[data-th-redir-from]').value.trim();
					var t=wrap.querySelector('[data-th-redir-to]').value.trim();
					var c=wrap.querySelector('[data-th-redir-code]').value;
					var rx=wrap.querySelector('[data-th-redir-regex]').checked?'1':'0';
					if(!f) return;
					post({action:'therum_redirects_save',from:f,to:t,code:c,regex:rx}, function(res){ if(res&&res.success){location.reload();} else if(window.therumToast){window.therumToast((res&&res.data)||'Could not save');} });
				});
				wrap.querySelectorAll('[data-th-redir-del]').forEach(function(btn){ btn.addEventListener('click', function(){ post({action:'therum_redirects_delete',from:btn.dataset.thRedirDel}, function(){ var row=btn.closest('[data-th-redir-row]'); if(row)row.remove(); }); }); });
				wrap.querySelectorAll('[data-th-redir-toggle]').forEach(function(btn){ btn.addEventListener('click', function(){ post({action:'therum_redirects_toggle',from:btn.dataset.thRedirToggle}, function(res){ if(res&&res.success){ btn.textContent=res.data.enabled?'On':'Off'; btn.closest('[data-th-redir-row]').style.opacity=res.data.enabled?'':'.5'; } }); }); });
				var impT=wrap.querySelector('[data-th-redir-import-toggle]'), impBox=wrap.querySelector('[data-th-redir-import]');
				if(impT){ impT.addEventListener('click', function(){ impBox.style.display=impBox.style.display==='none'?'':'none'; }); }
				var impRun=wrap.querySelector('[data-th-redir-import-run]');
				if(impRun){ impRun.addEventListener('click', function(){ var d=wrap.querySelector('[data-th-redir-import-data]').value; if(!d.trim())return; post({action:'therum_redirects_import',data:d}, function(res){ if(res&&res.success){ if(window.therumToast)window.therumToast('Imported '+res.data.added); location.reload(); } else if(window.therumToast){window.therumToast((res&&res.data)||'Import failed');} }); }); }
				// "Create redirect" prefill coming from the 404 table.
				document.addEventListener('th-redir-prefill', function(e){ wrap.querySelector('[data-th-redir-from]').value=e.detail; wrap.querySelector('[data-th-redir-to]').focus(); wrap.scrollIntoView({behavior:'smooth',block:'center'}); });
			})();
			</script>
			<?php
		} );

		therum_settings_group( '404 monitor', count( $log ) . ' tracked path' . ( count( $log ) === 1 ? '' : 's' ) . '. Logs requests that hit a 404 so you can redirect the ones that matter.', function () use ( $log, $log404, $nonce ) {
			?>
			<div data-th-404 data-nonce="<?php echo esc_attr( $nonce ); ?>">
			<div style="display:flex;gap:12px;align-items:center;margin-bottom:14px;">
				<label style="display:flex;align-items:center;gap:6px;font-size:13px;"><input type="checkbox" data-th-404-log <?php checked( $log404 ); ?> /> Log 404 errors</label>
				<?php if ( ! empty( $log ) ): ?><button type="button" class="th-btn" style="font-size:12px;color:var(--err);" data-th-404-clear-all>Clear all</button><?php endif; ?>
			</div>
			<?php if ( empty( $log ) ): ?>
				<p style="color:var(--tx3);font-size:13px;">No 404s logged<?php echo $log404 ? ' yet.' : ' — logging is off.'; ?></p>
			<?php else: ?>
				<table style="width:100%;font-size:12px;border-collapse:collapse;">
				<thead><tr style="text-align:left;border-bottom:1px solid var(--bd);">
					<th style="padding:8px;">Path</th><th style="padding:8px;">Hits</th><th style="padding:8px;">Last seen</th><th style="padding:8px;">Referrer</th><th style="padding:8px;width:120px;"></th>
				</tr></thead><tbody>
				<?php foreach ( $log as $path => $h ): ?>
					<tr style="border-bottom:1px solid var(--bd);" data-th-404-row="<?php echo esc_attr( $path ); ?>">
						<td style="padding:6px 8px;font-family:ui-monospace,monospace;font-size:11px;"><?php echo esc_html( $path ); ?></td>
						<td style="padding:6px 8px;color:var(--tx3);"><?php echo (int) ( $h['hits'] ?? 0 ); ?></td>
						<td style="padding:6px 8px;color:var(--tx3);font-size:11px;"><?php echo esc_html( (string) ( $h['last'] ?? '' ) ); ?></td>
						<td style="padding:6px 8px;color:var(--tx3);font-size:11px;max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?php echo esc_html( (string) ( $h['ref'] ?? '' ) ?: '—' ); ?></td>
						<td style="padding:6px 8px;text-align:right;white-space:nowrap;">
							<button type="button" class="th-btn th-btn-primary" style="padding:4px 7px;font-size:11px;" data-th-404-redir="<?php echo esc_attr( $path ); ?>">→ Redirect</button>
							<button type="button" class="th-btn" style="padding:4px 7px;font-size:11px;color:var(--err);" data-th-404-del="<?php echo esc_attr( $path ); ?>">×</button>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody></table>
			<?php endif; ?>
			</div>
			<script>
			(function(){
				var wrap=document.querySelector('[data-th-404]');
				if(!wrap) return;
				var nonce=wrap.dataset.nonce;
				var ajax=window.ajaxurl||'/wp-admin/admin-ajax.php';
				function post(data, cb){ var fd=new FormData(); fd.append('nonce',nonce); Object.keys(data).forEach(function(k){fd.append(k,data[k]);}); fetch(ajax,{method:'POST',credentials:'same-origin',body:fd}).then(function(r){return r.json();}).then(cb).catch(function(){}); }
				var t=wrap.querySelector('[data-th-404-log]');
				if(t){ t.addEventListener('change', function(){ post({action:'therum_404_toggle'}, function(){}); }); }
				var ca=wrap.querySelector('[data-th-404-clear-all]');
				if(ca){ ca.addEventListener('click', function(){ if(!confirm('Clear the entire 404 log?'))return; post({action:'therum_404_clear',path:'*'}, function(){location.reload();}); }); }
				wrap.querySelectorAll('[data-th-404-del]').forEach(function(b){ b.addEventListener('click', function(){ post({action:'therum_404_clear',path:b.dataset.th404Del}, function(){ var row=b.closest('[data-th-404-row]'); if(row)row.remove(); }); }); });
				wrap.querySelectorAll('[data-th-404-redir]').forEach(function(b){ b.addEventListener('click', function(){ document.dispatchEvent(new CustomEvent('th-redir-prefill',{detail:b.dataset.th404Redir})); }); });
			})();
			</script>
			<?php
		} );
	}
}

add_action( 'init', [ 'Therum_Redirects', 'init' ] );


// ════════════════════════════════════════════════════════════════════════════
//  3. GLOBAL FIND & REPLACE
// ════════════════════════════════════════════════════════════════════════════

add_action( 'wp_ajax_therum_find_replace', function() {
	if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'forbidden' );
	check_ajax_referer( 'therum_options', 'nonce' );

	$find    = wp_unslash( $_POST['find'] ?? '' );
	$replace = wp_unslash( $_POST['replace'] ?? '' );
	$dry_run = ! empty( $_POST['dry_run'] );

	if ( $find === '' ) wp_send_json_error( 'find is required' );

	global $wpdb;
	$affected = [];

	// Search post_content
	$rows = $wpdb->get_results( $wpdb->prepare(
		"SELECT ID, post_title, post_type FROM {$wpdb->posts} WHERE post_content LIKE %s AND post_status IN ('publish','draft','private','future','pending') LIMIT 500",
		'%' . $wpdb->esc_like( $find ) . '%'
	) );
	foreach ( $rows as $r ) {
		$affected[] = [ 'id' => $r->ID, 'title' => $r->post_title, 'type' => $r->post_type, 'field' => 'content' ];
	}

	// Search post_title
	$rows2 = $wpdb->get_results( $wpdb->prepare(
		"SELECT ID, post_title, post_type FROM {$wpdb->posts} WHERE post_title LIKE %s AND post_status IN ('publish','draft','private','future','pending') LIMIT 500",
		'%' . $wpdb->esc_like( $find ) . '%'
	) );
	foreach ( $rows2 as $r ) {
		$affected[] = [ 'id' => $r->ID, 'title' => $r->post_title, 'type' => $r->post_type, 'field' => 'title' ];
	}

	// Search post_excerpt
	$rows3 = $wpdb->get_results( $wpdb->prepare(
		"SELECT ID, post_title, post_type FROM {$wpdb->posts} WHERE post_excerpt LIKE %s AND post_status IN ('publish','draft','private','future','pending') LIMIT 500",
		'%' . $wpdb->esc_like( $find ) . '%'
	) );
	foreach ( $rows3 as $r ) {
		$affected[] = [ 'id' => $r->ID, 'title' => $r->post_title, 'type' => $r->post_type, 'field' => 'excerpt' ];
	}

	if ( ! $dry_run && $replace !== null ) {
		$wpdb->query( $wpdb->prepare(
			"UPDATE {$wpdb->posts} SET post_content = REPLACE(post_content, %s, %s) WHERE post_content LIKE %s",
			$find, $replace, '%' . $wpdb->esc_like( $find ) . '%'
		) );
		$wpdb->query( $wpdb->prepare(
			"UPDATE {$wpdb->posts} SET post_title = REPLACE(post_title, %s, %s) WHERE post_title LIKE %s",
			$find, $replace, '%' . $wpdb->esc_like( $find ) . '%'
		) );
		$wpdb->query( $wpdb->prepare(
			"UPDATE {$wpdb->posts} SET post_excerpt = REPLACE(post_excerpt, %s, %s) WHERE post_excerpt LIKE %s",
			$find, $replace, '%' . $wpdb->esc_like( $find ) . '%'
		) );
		// Also search/replace in postmeta values (for Bricks content, ACF, etc.)
		$wpdb->query( $wpdb->prepare(
			"UPDATE {$wpdb->postmeta} SET meta_value = REPLACE(meta_value, %s, %s) WHERE meta_value LIKE %s AND meta_key NOT LIKE '\\_transient%%'",
			$find, $replace, '%' . $wpdb->esc_like( $find ) . '%'
		) );
		if ( class_exists( 'Therum_Activity_Log' ) ) {
			Therum_Activity_Log::log( 'find_replace', 'content', 0, '', 'Find: "' . mb_substr( $find, 0, 80 ) . '" → Replace: "' . mb_substr( $replace, 0, 80 ) . '" — ' . count( $affected ) . ' posts affected' );
		}
	}

	wp_send_json_success( [
		'dry_run'  => $dry_run,
		'find'     => $find,
		'replace'  => $replace,
		'affected' => $affected,
		'count'    => count( $affected ),
	] );
} );


// ════════════════════════════════════════════════════════════════════════════
//  4. DATABASE OPTIMIZER
// ════════════════════════════════════════════════════════════════════════════

add_action( 'wp_ajax_therum_db_optimize', function() {
	if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'forbidden' );
	check_ajax_referer( 'therum_options', 'nonce' );

	global $wpdb;
	$results = [];

	// 1. Clean excess revisions (keep latest 5 per post)
	$rev_limit = max( 1, (int) get_option( 'th_perf_revisions_limit', 5 ) );
	$excess = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'revision'" );
	// Delete revisions beyond limit for each parent
	$parents = $wpdb->get_col( "SELECT DISTINCT post_parent FROM {$wpdb->posts} WHERE post_type = 'revision' AND post_parent > 0" );
	$deleted_revs = 0;
	foreach ( $parents as $parent_id ) {
		$keep_ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'revision' AND post_parent = %d ORDER BY post_date DESC LIMIT %d",
			$parent_id, $rev_limit
		) );
		if ( empty( $keep_ids ) ) continue;
		// Build the IN() list from %d placeholders rather than interpolating —
		// keeps everything inside prepare()'s parameterization (no raw values in
		// the SQL string, and clean against static analysis).
		$keep_ids     = array_map( 'intval', $keep_ids );
		$placeholders = implode( ',', array_fill( 0, count( $keep_ids ), '%d' ) );
		$del = $wpdb->query( $wpdb->prepare(
			"DELETE FROM {$wpdb->posts} WHERE post_type = 'revision' AND post_parent = %d AND ID NOT IN ($placeholders)",
			$parent_id,
			...$keep_ids
		) );
		$deleted_revs += (int) $del;
	}
	$results[] = "Revisions: removed $deleted_revs excess (kept $rev_limit per post)";

	// 2. Clean orphaned postmeta
	$orphan_meta = $wpdb->query( "DELETE pm FROM {$wpdb->postmeta} pm LEFT JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE p.ID IS NULL" );
	$results[] = "Orphaned postmeta: removed $orphan_meta rows";

	// 3. Clean expired transients
	$trans = $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_%' AND option_value < UNIX_TIMESTAMP()" );
	$trans2 = $wpdb->query( "DELETE o FROM {$wpdb->options} o LEFT JOIN {$wpdb->options} t ON t.option_name = CONCAT('_transient_timeout_', SUBSTRING(o.option_name, 12)) WHERE o.option_name LIKE '_transient_%' AND o.option_name NOT LIKE '_transient_timeout_%' AND t.option_name IS NULL" );
	$results[] = "Transients: cleaned " . ( $trans + $trans2 ) . " expired";

	// 4. Clean spam + trash comments
	$spam = $wpdb->query( "DELETE FROM {$wpdb->comments} WHERE comment_approved IN ('spam', 'trash')" );
	$results[] = "Comments: removed $spam spam/trash";

	// 5. Clean auto-drafts older than 7 days
	$drafts = $wpdb->query( "DELETE FROM {$wpdb->posts} WHERE post_status = 'auto-draft' AND post_modified < DATE_SUB(NOW(), INTERVAL 7 DAY)" );
	$results[] = "Auto-drafts: removed $drafts old drafts";

	if ( class_exists( 'Therum_Activity_Log' ) ) {
		Therum_Activity_Log::log( 'db_optimize', 'system', 0, '', implode( '; ', $results ) );
	}

	wp_send_json_success( [ 'results' => $results ] );
} );


// ════════════════════════════════════════════════════════════════════════════
//  5. BROKEN LINK CHECKER — background scan
// ════════════════════════════════════════════════════════════════════════════

add_action( 'wp_ajax_therum_check_links', function() {
	if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'forbidden' );
	// Nonce required: this endpoint fires up to 50 outbound HEAD requests to
	// URLs found in post content, so without CSRF protection a forged request
	// could coerce a logged-in admin's server into scanning arbitrary hosts.
	check_ajax_referer( 'therum_options', '_wpnonce' );

	global $wpdb;
	$broken = [];
	$checked = 0;

	// Extract URLs from post_content of published posts
	$posts = $wpdb->get_results(
		"SELECT ID, post_title, post_content FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_content LIKE '%href=%' LIMIT 100"
	);

	foreach ( $posts as $p ) {
		preg_match_all( '/href=["\']([^"\']+)["\']/i', $p->post_content, $matches );
		if ( empty( $matches[1] ) ) continue;

		foreach ( array_unique( $matches[1] ) as $url ) {
			if ( str_starts_with( $url, '#' ) || str_starts_with( $url, 'mailto:' ) || str_starts_with( $url, 'tel:' ) ) continue;
			if ( $checked >= 50 ) break 2; // Cap per request

			// Verify TLS — a checker that pretends every broken cert is fine
			// lies about the link's reachability and can hide MITM rewrites.
			$response = wp_remote_head( $url, [ 'timeout' => 5, 'redirection' => 3 ] );
			$checked++;
			$code = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
			if ( $code === 0 || $code >= 400 ) {
				$broken[] = [
					'post_id'    => $p->ID,
					'post_title' => $p->post_title,
					'url'        => $url,
					'status'     => $code ?: ( is_wp_error( $response ) ? $response->get_error_message() : 'unknown' ),
				];
			}
		}
	}

	wp_send_json_success( [ 'broken' => $broken, 'checked' => $checked ] );
} );


// ════════════════════════════════════════════════════════════════════════════
//  6. FAVORITES / PINNED — star pages/posts for quick access
// ════════════════════════════════════════════════════════════════════════════

add_action( 'wp_ajax_therum_toggle_favorite', function() {
	// Auth gate. The favourites store is per-user but the post id is
	// reachable from the client — without these checks a subscriber could
	// favourite an arbitrary post including private/draft entries they
	// otherwise can't see.
	if ( ! is_user_logged_in() ) wp_send_json_error( 'forbidden', 403 );

	$nonce = $_POST['nonce'] ?? '';
	if ( ! wp_verify_nonce( $nonce, 'therum_theme' ) && ! wp_verify_nonce( $nonce, 'therum_options' ) ) {
		wp_send_json_error( 'bad nonce' );
	}
	$post_id = (int) ( $_POST['post_id'] ?? 0 );
	if ( ! $post_id || ! get_post( $post_id ) ) wp_send_json_error( 'bad post' );
	if ( ! current_user_can( 'read_post', $post_id ) ) wp_send_json_error( 'forbidden', 403 );

	$user_id = get_current_user_id();
	$favs    = (array) get_user_meta( $user_id, 'therum_favorites', true );
	$favs    = array_filter( $favs );
	$key     = array_search( $post_id, $favs, true );

	if ( $key !== false ) {
		unset( $favs[ $key ] );
		$is_fav = false;
	} else {
		array_unshift( $favs, $post_id );
		$is_fav = true;
	}

	update_user_meta( $user_id, 'therum_favorites', array_values( $favs ) );
	wp_send_json_success( [ 'is_favorite' => $is_fav, 'count' => count( $favs ) ] );
} );

// Expose favorites to the shell JS for sidebar rendering
add_action( 'admin_head', function() {
	if ( ! is_admin() ) return;
	$favs = (array) get_user_meta( get_current_user_id(), 'therum_favorites', true );
	$favs = array_filter( $favs );
	if ( empty( $favs ) ) return;

	$items = [];
	foreach ( array_slice( $favs, 0, 10 ) as $id ) {
		$p = get_post( $id );
		if ( ! $p || $p->post_status === 'trash' ) continue;
		$items[] = [
			'id'    => $p->ID,
			'title' => $p->post_title ?: '(untitled)',
			'type'  => $p->post_type,
			'edit'  => get_edit_post_link( $p->ID, 'raw' ),
		];
	}
	if ( empty( $items ) ) return;
	echo '<script>window.therumFavorites = ' . wp_json_encode( $items ) . ';</script>' . "\n";
} );


// ════════════════════════════════════════════════════════════════════════════
//  7. CONTENT CALENDAR — AJAX endpoint for scheduled/recent posts
// ════════════════════════════════════════════════════════════════════════════

add_action( 'wp_ajax_therum_calendar_events', function() {
	if ( ! current_user_can( 'edit_posts' ) ) wp_send_json_error( 'forbidden' );
	check_ajax_referer( 'therum_options', '_wpnonce' );

	$start = sanitize_text_field( $_GET['start'] ?? gmdate( 'Y-m-01' ) );
	$end   = sanitize_text_field( $_GET['end']   ?? gmdate( 'Y-m-t' ) );

	$posts = get_posts( [
		'post_type'      => [ 'post', 'page', 'case_study' ],
		'post_status'    => [ 'publish', 'draft', 'future', 'pending' ],
		'posts_per_page' => 200,
		'date_query'     => [
			[ 'after' => $start, 'before' => $end, 'inclusive' => true ],
		],
		'orderby' => 'date',
		'order'   => 'ASC',
	] );

	$events = [];
	foreach ( $posts as $p ) {
		$events[] = [
			'id'     => $p->ID,
			'title'  => $p->post_title ?: '(untitled)',
			'date'   => $p->post_date,
			'status' => $p->post_status,
			'type'   => $p->post_type,
			'edit'   => get_edit_post_link( $p->ID, 'raw' ),
		];
	}

	wp_send_json_success( [ 'events' => $events ] );
} );


// ════════════════════════════════════════════════════════════════════════════
//  8. SCHEDULED ACTIONS DASHBOARD — upcoming cron + scheduled posts
// ════════════════════════════════════════════════════════════════════════════

add_action( 'wp_ajax_therum_scheduled_overview', function() {
	if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'forbidden' );
	check_ajax_referer( 'therum_options', '_wpnonce' );

	$out = [ 'scheduled_posts' => [], 'cron_jobs' => [] ];

	// Scheduled posts
	$future = get_posts( [
		'post_type'      => [ 'post', 'page', 'case_study' ],
		'post_status'    => 'future',
		'posts_per_page' => 20,
		'orderby'        => 'date',
		'order'          => 'ASC',
	] );
	foreach ( $future as $p ) {
		$out['scheduled_posts'][] = [
			'id'    => $p->ID,
			'title' => $p->post_title,
			'type'  => $p->post_type,
			'date'  => $p->post_date,
			'edit'  => get_edit_post_link( $p->ID, 'raw' ),
		];
	}

	// Next cron events
	$crons = _get_cron_array();
	if ( is_array( $crons ) ) {
		$now = time();
		foreach ( $crons as $ts => $hooks ) {
			if ( $ts < $now ) continue;
			foreach ( $hooks as $hook => $events ) {
				// Skip WP internal noise
				if ( str_starts_with( $hook, 'wp_' ) && ! in_array( $hook, [ 'wp_scheduled_auto_draft_delete' ], true ) ) continue;
				$out['cron_jobs'][] = [
					'hook'      => $hook,
					'next_run'  => gmdate( 'Y-m-d H:i:s', $ts ),
					'in'        => human_time_diff( $now, $ts ),
				];
			}
			if ( count( $out['cron_jobs'] ) >= 20 ) break;
		}
	}

	wp_send_json_success( $out );
} );


// ════════════════════════════════════════════════════════════════════════════
//  9. TOOLS SETTINGS — Find & Replace + DB Optimizer + Link Checker UI
// ════════════════════════════════════════════════════════════════════════════

add_action( 'init', function() {
	if ( ! class_exists( 'Therum_Settings' ) ) return;

	Therum_Settings::register( 'tools', [
		'label'    => 'Tools',
		'icon'     => 'settings',
		'desc'     => 'Find & replace, DB cleanup, link checker.',
		'priority' => 125,
		'render'   => 'therum_render_tools_settings',
	] );
}, 20 );

function therum_render_tools_settings(): void {
	$nonce = wp_create_nonce( 'therum_options' );

	if ( function_exists( 'therum_settings_group' ) ) {
		// ── Find & Replace ───────────────────────────────────────────────
		therum_settings_group( 'Find & replace', 'Search across all post content, titles, excerpts, and meta. Preview matches before replacing.', function() use ( $nonce ) {
			?>
			<div data-th-fnr data-nonce="<?php echo esc_attr( $nonce ); ?>">
				<div style="display:flex;gap:8px;margin-bottom:10px;flex-wrap:wrap;">
					<input type="text" class="th-input" placeholder="Find this text…" style="flex:1;min-width:200px;" data-th-fnr-find />
					<input type="text" class="th-input" placeholder="Replace with…" style="flex:1;min-width:200px;" data-th-fnr-replace />
				</div>
				<div style="display:flex;gap:8px;">
					<button type="button" class="th-btn" data-th-fnr-preview>Preview matches</button>
					<button type="button" class="th-btn th-btn-primary" data-th-fnr-run style="display:none;">Replace all</button>
				</div>
				<div data-th-fnr-results style="margin-top:12px;font-size:12px;color:var(--tx2);"></div>
			</div>
			<script>
			(function(){
				var wrap = document.querySelector('[data-th-fnr]');
				if (!wrap) return;
				var nonce = wrap.dataset.nonce, ajax = window.ajaxurl || '/wp-admin/admin-ajax.php';
				var findEl = wrap.querySelector('[data-th-fnr-find]');
				var repEl = wrap.querySelector('[data-th-fnr-replace]');
				var results = wrap.querySelector('[data-th-fnr-results]');
				var runBtn = wrap.querySelector('[data-th-fnr-run]');
				// Escape every value that originates from the server before it
				// hits innerHTML. Post titles + field names are user-authorable
				// content and would otherwise become a stored-XSS sink in the
				// admin browser when an admin runs the checker.
				function esc(s){ return String(s == null ? '' : s).replace(/[&<>"']/g, function(c){
					return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c];
				}); }
				wrap.querySelector('[data-th-fnr-preview]').addEventListener('click', function(){
					var fd = new FormData();
					fd.append('action','therum_find_replace');fd.append('find',findEl.value);fd.append('replace',repEl.value);fd.append('dry_run','1');fd.append('nonce',nonce);
					fetch(ajax,{method:'POST',credentials:'same-origin',body:fd}).then(function(r){return r.json();}).then(function(res){
						if (res.success) {
							var d = res.data;
							var count = parseInt(d.count, 10) || 0;
							var lines = '';
							if (count > 0 && Array.isArray(d.affected)) {
								lines = '<br>' + d.affected.slice(0,10).map(function(a){
									return esc(a.type) + ' #' + (parseInt(a.id,10)||0) + ' "' + esc(a.title) + '" (' + esc(a.field) + ')';
								}).join('<br>');
							}
							results.innerHTML = '<strong>' + count + ' match' + (count===1?'':'es') + '</strong> found across posts.' + lines;
							runBtn.style.display = count > 0 ? '' : 'none';
						}
					});
				});
				runBtn.addEventListener('click', function(){
					if (!confirm('Replace "' + findEl.value + '" with "' + repEl.value + '" across all content? This cannot be undone.')) return;
					var fd = new FormData();
					fd.append('action','therum_find_replace');fd.append('find',findEl.value);fd.append('replace',repEl.value);fd.append('dry_run','');fd.append('nonce',nonce);
					fetch(ajax,{method:'POST',credentials:'same-origin',body:fd}).then(function(r){return r.json();}).then(function(res){
						if (res.success) {
							results.innerHTML = '<strong style="color:var(--ok);">Done.</strong> Replaced in ' + res.data.count + ' location' + (res.data.count===1?'':'s') + '.';
							runBtn.style.display = 'none';
						}
					});
				});
			})();
			</script>
			<?php
		} );

		// ── DB Optimizer ─────────────────────────────────────────────────
		therum_settings_group( 'Database optimizer', 'Clean excess revisions, orphaned meta, expired transients, spam comments, old auto-drafts.', function() use ( $nonce ) {
			?>
			<div data-th-dbopt data-nonce="<?php echo esc_attr( $nonce ); ?>">
				<button type="button" class="th-btn th-btn-primary" data-th-dbopt-run>Run cleanup</button>
				<div data-th-dbopt-results style="margin-top:12px;font-size:12px;color:var(--tx2);"></div>
			</div>
			<script>
			(function(){
				var wrap = document.querySelector('[data-th-dbopt]');
				if (!wrap) return;
				wrap.querySelector('[data-th-dbopt-run]').addEventListener('click', function(){
					this.disabled = true; this.textContent = 'Cleaning…';
					var fd = new FormData();
					fd.append('action','therum_db_optimize');fd.append('nonce',wrap.dataset.nonce);
					fetch(window.ajaxurl||'/wp-admin/admin-ajax.php',{method:'POST',credentials:'same-origin',body:fd})
					.then(function(r){return r.json();}).then(function(res){
						var btn = wrap.querySelector('[data-th-dbopt-run]');
						btn.disabled = false; btn.textContent = 'Run cleanup';
						if (res.success) {
							wrap.querySelector('[data-th-dbopt-results]').innerHTML = '<strong style="color:var(--ok);">Done.</strong><br>' + res.data.results.join('<br>');
						}
					});
				});
			})();
			</script>
			<?php
		} );

		// ── Broken Link Checker ──────────────────────────────────────────
		therum_settings_group( 'Broken link checker', 'Scans published posts for links returning 404 or errors. Checks up to 50 URLs per run.', function() {
			?>
			<div data-th-linkcheck>
				<button type="button" class="th-btn th-btn-primary" data-th-linkcheck-run>Scan for broken links</button>
				<div data-th-linkcheck-results style="margin-top:12px;font-size:12px;color:var(--tx2);"></div>
			</div>
			<script>
			(function(){
				var wrap = document.querySelector('[data-th-linkcheck]');
				if (!wrap) return;
				wrap.querySelector('[data-th-linkcheck-run]').addEventListener('click', function(){
					this.disabled = true; this.textContent = 'Scanning…';
					fetch((window.ajaxurl||'/wp-admin/admin-ajax.php') + '?action=therum_check_links&_wpnonce=<?php echo esc_js( wp_create_nonce( 'therum_options' ) ); ?>',{credentials:'same-origin'})
					.then(function(r){return r.json();}).then(function(res){
						var btn = wrap.querySelector('[data-th-linkcheck-run]');
						btn.disabled = false; btn.textContent = 'Scan for broken links';
						var el = wrap.querySelector('[data-th-linkcheck-results]');
						if (res.success) {
							var d = res.data;
							if (!d.broken.length) { el.innerHTML = '<strong style="color:var(--ok);">No broken links found.</strong> Checked ' + d.checked + ' URLs.'; return; }
							// Build the broken-link list with createElement + textContent so
							// neither the URL nor the post title (both ultimately user-
							// controllable post_content / post_title) can run script in
							// the admin's browser. Also refuse non-http(s) URLs.
							function esc(s){ return String(s == null ? '' : s).replace(/[&<>"']/g, function(c){
								return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c];
							}); }
							var header = '<strong style="color:var(--err);">' + d.broken.length + ' broken link' + (d.broken.length===1?'':'s') + '</strong> found (checked ' + (parseInt(d.checked,10)||0) + ' URLs):';
							el.innerHTML = header;
							d.broken.forEach(function(b){
								var line = document.createElement('div');
								var safeUrl = /^https?:\/\//i.test(b.url || '') ? b.url : '#';
								line.innerHTML = '• <a target="_blank" rel="noopener"></a> → ' + esc(b.status) + ' (in "' + esc(b.post_title) + '")';
								var a = line.querySelector('a');
								a.href = safeUrl;
								a.textContent = String(b.url || '');
								el.appendChild(line);
							});
						}
					});
				});
			})();
			</script>
			<?php
		} );
	}
}


// ════════════════════════════════════════════════════════════════════════════
//  10. MULTISITE AWARENESS — add network context to sidebar if multisite
// ════════════════════════════════════════════════════════════════════════════

if ( is_multisite() ) {
	add_action( 'admin_bar_menu', function( $bar ) {
		if ( ! is_user_logged_in() ) return;
		$sites = get_sites( [ 'number' => 10 ] );
		if ( count( $sites ) <= 1 ) return;
		$bar->add_node( [
			'id'     => 'th-network-sites',
			'parent' => 'top-secondary',
			'title'  => 'Network (' . count( $sites ) . ' sites)',
		] );
		foreach ( $sites as $s ) {
			$bar->add_node( [
				'id'     => 'th-site-' . $s->blog_id,
				'parent' => 'th-network-sites',
				'title'  => $s->blogname ?: $s->domain,
				'href'   => get_admin_url( $s->blog_id ),
			] );
		}
	}, 200 );
}


// ════════════════════════════════════════════════════════════════════════════
//  11. ⌘K COMMAND PALETTE + 12. UNDO TOAST + 13. BULK ACTIONS +
//  14. KEYBOARD SHORTCUTS + 15. REVISION LINK + IMPORT TOOL
//
//  All injected as one inline script block in admin_footer.
// ════════════════════════════════════════════════════════════════════════════

add_action( 'admin_footer', function() {
	if ( function_exists( 'therum_is_frame' ) && therum_is_frame() ) return;

	// Build command palette data
	$commands = [];

	// Navigation commands
	$nav_items = [
		[ 'Dashboard',     admin_url( 'admin.php?page=therum' ) ],
		[ 'Pages',         admin_url( 'admin.php?page=therum-pages' ) ],
		[ 'Posts',         admin_url( 'admin.php?page=therum-posts' ) ],
		[ 'Media',         admin_url( 'admin.php?page=therum-media' ) ],
		[ 'Plugins',       admin_url( 'admin.php?page=therum-plugins' ) ],
		[ 'Users',         admin_url( 'admin.php?page=therum-users' ) ],
		[ 'Settings',      admin_url( 'admin.php?page=therum-settings' ) ],
		[ 'Customization', admin_url( 'admin.php?page=therum-customization' ) ],
		[ 'Themes',        admin_url( 'admin.php?page=therum-customization&section=themes' ) ],
		[ 'Updates',       admin_url( 'admin.php?page=therum-updates' ) ],
		[ 'Connections',   admin_url( 'admin.php?page=therum-connections' ) ],
	];
	foreach ( $nav_items as $ni ) {
		$commands[] = [ 'label' => 'Go to ' . $ni[0], 'url' => $ni[1], 'group' => 'Navigation', 'keys' => strtolower( $ni[0] ) ];
	}

	// Action commands
	$commands[] = [ 'label' => 'New Page',         'url' => admin_url( 'post-new.php?post_type=page' ), 'group' => 'Create' ];
	$commands[] = [ 'label' => 'New Post',          'url' => admin_url( 'post-new.php' ), 'group' => 'Create' ];
	$commands[] = [ 'label' => 'Upload Media',      'url' => admin_url( 'media-new.php' ), 'group' => 'Create' ];
	$commands[] = [ 'label' => 'New Case Study',    'url' => admin_url( 'post-new.php?post_type=case_study' ), 'group' => 'Create' ];

	// Settings shortcuts
	$settings_sections = [ 'appearance', 'branding', 'security', 'performance', 'uploads', 'notifications', 'updates', 'backup', 'experiments', 'tools', 'activity', 'redirects', 'about' ];
	foreach ( $settings_sections as $s ) {
		$commands[] = [ 'label' => ucfirst( $s ) . ' settings', 'url' => admin_url( 'admin.php?page=therum-settings&section=' . $s ), 'group' => 'Settings', 'keys' => $s . ' settings' ];
	}

	// Toggle commands (JS-handled)
	$commands[] = [ 'label' => 'Toggle dark mode',    'action' => 'toggle-theme', 'group' => 'Actions' ];
	$commands[] = [ 'label' => 'Toggle desktop mode', 'action' => 'toggle-desktop', 'group' => 'Actions' ];
	$commands[] = [ 'label' => 'View site',           'url' => home_url(), 'group' => 'Actions', 'target' => '_blank' ];

	// Recent posts for quick jump
	$recent = get_posts( [ 'posts_per_page' => 15, 'post_type' => [ 'page', 'post', 'case_study' ], 'post_status' => [ 'publish', 'draft' ], 'orderby' => 'modified', 'order' => 'DESC' ] );
	foreach ( $recent as $rp ) {
		$commands[] = [ 'label' => 'Edit: ' . ( $rp->post_title ?: '(untitled)' ), 'url' => get_edit_post_link( $rp->ID, 'raw' ), 'group' => 'Recent', 'keys' => strtolower( $rp->post_title ) ];
	}

	$json = wp_json_encode( $commands, JSON_UNESCAPED_UNICODE );
	?>
<style id="therum-tools-css">
/* ⌘K Command Palette */
.th-cmd-overlay{position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:99999;display:none;align-items:flex-start;justify-content:center;padding-top:min(20vh,160px);backdrop-filter:blur(4px)}
.th-cmd-overlay.is-open{display:flex}
.th-cmd{width:560px;max-width:calc(100vw - 40px);max-height:min(60vh,480px);background:var(--sf,#fff);border:1px solid var(--bd);border-radius:14px;box-shadow:0 24px 80px rgba(0,0,0,.25);overflow:hidden;display:flex;flex-direction:column;animation:th-lift-in .15s ease both}
@keyframes th-lift-in{from{opacity:0;transform:translateY(-8px) scale(.98)}to{opacity:1;transform:translateY(0) scale(1)}}
.th-cmd-input-wrap{display:flex;align-items:center;gap:10px;padding:14px 18px;border-bottom:1px solid var(--bd)}
.th-cmd-input-wrap svg{color:var(--tx3);flex-shrink:0}
.th-cmd-input{flex:1;border:none;background:none;font-size:16px;font-family:var(--f);color:var(--tx);outline:none}
.th-cmd-input::placeholder{color:var(--tx3)}
.th-cmd-kbd{font-size:10px;color:var(--tx3);background:var(--sf2);padding:3px 8px;border-radius:5px;border:1px solid var(--bd)}
.th-cmd-list{overflow-y:auto;padding:6px}
.th-cmd-group{font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:.06em;color:var(--tx3);padding:8px 12px 4px}
.th-cmd-item{display:flex;align-items:center;gap:10px;padding:9px 14px;border-radius:8px;color:var(--tx);font-size:14px;text-decoration:none!important;cursor:pointer;transition:background .08s}
.th-cmd-item:hover,.th-cmd-item.is-active{background:var(--sf2);color:var(--ac)}
.th-cmd-item-sub{margin-left:auto;font-size:11px;color:var(--tx3)}
.th-cmd-empty{padding:24px;text-align:center;color:var(--tx3);font-size:13px}
.th-cmd-footer{padding:8px 14px;border-top:1px solid var(--bd);display:flex;gap:14px;font-size:11px;color:var(--tx3)}
.th-cmd-footer kbd{font-size:10px;background:var(--sf2);padding:2px 6px;border-radius:4px;border:1px solid var(--bd);font-family:var(--f)}

/* Undo toast */
.th-undo-toast{position:fixed;bottom:24px;left:50%;transform:translateX(-50%) translateY(20px);background:var(--tx,#0a0a0a);color:#fff;padding:10px 18px;border-radius:10px;font:500 13px/1.3 var(--f);box-shadow:0 12px 40px rgba(0,0,0,.25);z-index:99998;display:flex;align-items:center;gap:12px;opacity:0;transition:opacity .2s,transform .2s}
.th-undo-toast.is-visible{opacity:1;transform:translateX(-50%) translateY(0)}
.th-undo-toast button{background:rgba(255,255,255,.2);border:none;color:#fff;padding:5px 12px;border-radius:6px;font:600 12px/1 var(--f);cursor:pointer}
.th-undo-toast button:hover{background:rgba(255,255,255,.3)}

/* Bulk actions bar */
.th-bulk-bar{display:none;position:sticky;top:var(--topbar-h,72px);z-index:50;margin:0 0 16px;padding:10px 16px;background:var(--ac);color:#fff;border-radius:10px;align-items:center;gap:12px;font-size:13px;font-weight:500}
.th-bulk-bar.is-active{display:flex}
.th-bulk-bar button{background:rgba(255,255,255,.2);border:none;color:#fff;padding:6px 12px;border-radius:6px;font:500 12px/1 var(--f);cursor:pointer}
.th-bulk-bar button:hover{background:rgba(255,255,255,.3)}
.th-bulk-bar .spacer{flex:1}
.th-lp-card.is-selected{outline:2px solid var(--ac);outline-offset:2px}
.th-lp-row.is-selected td:first-child{box-shadow:inset 3px 0 0 0 var(--ac)}
</style>

<div class="th-cmd-overlay" id="th-cmd-overlay">
<div class="th-cmd">
  <div class="th-cmd-input-wrap">
    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
    <input class="th-cmd-input" id="th-cmd-input" placeholder="Search commands, pages, settings…" autocomplete="off" />
    <span class="th-cmd-kbd">ESC</span>
  </div>
  <div class="th-cmd-list" id="th-cmd-list"></div>
  <div class="th-cmd-footer">
    <span><kbd>↑↓</kbd> navigate</span>
    <span><kbd>↵</kbd> select</span>
    <span><kbd>esc</kbd> close</span>
  </div>
</div>
</div>

<div class="th-undo-toast" id="th-undo-toast">
  <span id="th-undo-msg"></span>
  <button id="th-undo-btn">Undo</button>
</div>

<script id="therum-tools-js">
(function() {
'use strict';
var COMMANDS = <?php echo $json; ?>;
var overlay = document.getElementById('th-cmd-overlay');
var input   = document.getElementById('th-cmd-input');
var list    = document.getElementById('th-cmd-list');
var activeIdx = -1;

// ─── ⌘K COMMAND PALETTE ─────────────────────────────────────────────
function openPalette() {
  overlay.classList.add('is-open');
  input.value = '';
  activeIdx = -1;
  renderResults('');
  setTimeout(function(){ input.focus(); }, 50);
}
function closePalette() {
  overlay.classList.remove('is-open');
}

function renderResults(q) {
  q = q.toLowerCase().trim();
  var groups = {};
  var count = 0;
  COMMANDS.forEach(function(c) {
    var haystack = (c.label + ' ' + (c.keys || '') + ' ' + (c.group || '')).toLowerCase();
    if (q && haystack.indexOf(q) === -1) return;
    var g = c.group || 'Other';
    if (!groups[g]) groups[g] = [];
    groups[g].push(c);
    count++;
  });
  if (count === 0) {
    list.innerHTML = '<div class="th-cmd-empty">No matches for "' + q.replace(/</g,'&lt;') + '"</div>';
    return;
  }
  var html = '';
  var idx = 0;
  Object.keys(groups).forEach(function(g) {
    html += '<div class="th-cmd-group">' + g + '</div>';
    groups[g].forEach(function(c) {
      var cls = idx === activeIdx ? ' is-active' : '';
      html += '<div class="th-cmd-item' + cls + '" data-idx="' + idx + '" data-url="' + (c.url||'') + '" data-action="' + (c.action||'') + '" data-target="' + (c.target||'') + '">';
      html += '<span>' + c.label + '</span>';
      if (c.group === 'Recent') html += '<span class="th-cmd-item-sub">' + (c.keys||'') + '</span>';
      html += '</div>';
      idx++;
    });
  });
  list.innerHTML = html;
}

function executeItem(el) {
  var url = el.dataset.url;
  var action = el.dataset.action;
  closePalette();
  if (action === 'toggle-theme') {
    var btn = document.getElementById('th-theme-toggle');
    if (btn) btn.click();
  } else if (action === 'toggle-desktop') {
    var dm = document.getElementById('th-desktop-toggle');
    if (dm) dm.click();
  } else if (url) {
    if (el.dataset.target === '_blank') window.open(url);
    else window.location.href = url;
  }
}

input.addEventListener('input', function() {
  activeIdx = -1;
  renderResults(input.value);
});

input.addEventListener('keydown', function(e) {
  var items = list.querySelectorAll('.th-cmd-item');
  if (e.key === 'ArrowDown') {
    e.preventDefault();
    activeIdx = Math.min(activeIdx + 1, items.length - 1);
    items.forEach(function(it, i) { it.classList.toggle('is-active', i === activeIdx); });
    if (items[activeIdx]) items[activeIdx].scrollIntoView({ block: 'nearest' });
  } else if (e.key === 'ArrowUp') {
    e.preventDefault();
    activeIdx = Math.max(activeIdx - 1, 0);
    items.forEach(function(it, i) { it.classList.toggle('is-active', i === activeIdx); });
    if (items[activeIdx]) items[activeIdx].scrollIntoView({ block: 'nearest' });
  } else if (e.key === 'Enter') {
    e.preventDefault();
    var active = list.querySelector('.th-cmd-item.is-active') || items[0];
    if (active) executeItem(active);
  } else if (e.key === 'Escape') {
    closePalette();
  }
});

list.addEventListener('click', function(e) {
  var item = e.target.closest('.th-cmd-item');
  if (item) executeItem(item);
});

overlay.addEventListener('click', function(e) {
  if (e.target === overlay) closePalette();
});

// ⌘K / Ctrl+K global shortcut
document.addEventListener('keydown', function(e) {
  if ((e.metaKey || e.ctrlKey) && e.key === 'k') {
    e.preventDefault();
    if (overlay.classList.contains('is-open')) closePalette();
    else openPalette();
  }
});

// Also wire the sidebar ⌘K hint to open the palette
var sbSearch = document.querySelector('.th-sb-search-box');
if (sbSearch) {
  sbSearch.addEventListener('click', function(e) {
    e.preventDefault();
    openPalette();
  });
}

// ─── UNDO TOAST ──────────────────────────────────────────────────────
var undoToast = document.getElementById('th-undo-toast');
var undoMsg   = document.getElementById('th-undo-msg');
var undoBtn   = document.getElementById('th-undo-btn');
var undoTimer = null;
var undoCallback = null;

window.therumUndo = function(message, callback, duration) {
  clearTimeout(undoTimer);
  undoMsg.textContent = message;
  undoCallback = callback;
  undoToast.classList.add('is-visible');
  undoTimer = setTimeout(function() {
    undoToast.classList.remove('is-visible');
    undoCallback = null;
  }, duration || 8000);
};

undoBtn.addEventListener('click', function() {
  clearTimeout(undoTimer);
  undoToast.classList.remove('is-visible');
  if (typeof undoCallback === 'function') undoCallback();
  undoCallback = null;
});

// ─── BULK ACTIONS ────────────────────────────────────────────────────
// Ctrl/Cmd+Click on list items to select; bulk bar appears with actions.
var lp = document.querySelector('.th-lp');
if (lp) {
  var selected = new Set();

  lp.addEventListener('click', function(e) {
    if (!(e.metaKey || e.ctrlKey)) return;
    var card = e.target.closest('.th-lp-card[data-th-item-id], .th-lp-row[data-th-item-id]');
    if (!card) return;
    e.preventDefault();
    e.stopPropagation();
    var id = card.getAttribute('data-th-item-id');
    if (selected.has(id)) {
      selected.delete(id);
      card.classList.remove('is-selected');
    } else {
      selected.add(id);
      card.classList.add('is-selected');
    }
    updateBulkBar();
  });

  // Inject bulk bar if not present
  var bulkBar = document.createElement('div');
  bulkBar.className = 'th-bulk-bar';
  bulkBar.innerHTML = '<span class="th-bulk-count">0 selected</span><span class="spacer"></span>' +
    '<button data-bulk="publish">Publish</button>' +
    '<button data-bulk="draft">Set to draft</button>' +
    '<button data-bulk="trash">Trash</button>' +
    '<button data-bulk="clear" style="background:transparent;text-decoration:underline;opacity:.8">Clear</button>';
  var toolbar = lp.querySelector('.th-lp-toolbar');
  if (toolbar) toolbar.parentNode.insertBefore(bulkBar, toolbar.nextSibling);

  function updateBulkBar() {
    var count = selected.size;
    bulkBar.classList.toggle('is-active', count > 0);
    bulkBar.querySelector('.th-bulk-count').textContent = count + ' selected';
    // Sync visual selection across views
    lp.querySelectorAll('[data-th-item-id]').forEach(function(el) {
      el.classList.toggle('is-selected', selected.has(el.getAttribute('data-th-item-id')));
    });
  }

  bulkBar.addEventListener('click', function(e) {
    var action = e.target.dataset.bulk;
    if (!action) return;
    if (action === 'clear') {
      selected.clear();
      updateBulkBar();
      return;
    }
    if (!selected.size) return;
    var ids = Array.from(selected);
    // Extract numeric post IDs from item IDs (format: "post_123" or just "123")
    var postIds = ids.map(function(id) { return parseInt(id.replace(/\D/g,''), 10); }).filter(Boolean);
    if (!postIds.length) return;
    if (action === 'trash' && !confirm('Move ' + postIds.length + ' item(s) to trash?')) return;

    var fd = new FormData();
    fd.append('action', 'therum_bulk_action');
    fd.append('bulk_action', action);
    fd.append('post_ids', JSON.stringify(postIds));
    fd.append('nonce', (window.therumShellData && therumShellData.themeNonce) || '');
    fetch((window.ajaxurl || '/wp-admin/admin-ajax.php'), { method: 'POST', credentials: 'same-origin', body: fd })
      .then(function(r) { return r.json(); })
      .then(function(res) {
        if (res.success) {
          var msg = res.data.count + ' item(s) ' + action + (action === 'trash' ? 'ed' : 'ed');
          if (action === 'trash') {
            window.therumUndo(msg, function() {
              // Undo: untrash them
              var ufd = new FormData();
              ufd.append('action', 'therum_bulk_action');
              ufd.append('bulk_action', 'untrash');
              ufd.append('post_ids', JSON.stringify(postIds));
              ufd.append('nonce', (window.therumShellData && therumShellData.themeNonce) || '');
              fetch((window.ajaxurl || '/wp-admin/admin-ajax.php'), { method: 'POST', credentials: 'same-origin', body: fd })
                .then(function() { location.reload(); });
            });
          }
          setTimeout(function() { location.reload(); }, action === 'trash' ? 8500 : 500);
        }
      });
  });
}

// ─── KEYBOARD SHORTCUTS ──────────────────────────────────────────────
// Global shortcuts available on list pages
document.addEventListener('keydown', function(e) {
  // Don't fire if user is typing in an input
  if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA' || e.target.isContentEditable) return;
  if (overlay.classList.contains('is-open')) return;

  // ? — show shortcut help
  if (e.key === '?' && !e.metaKey && !e.ctrlKey) {
    e.preventDefault();
    alert(
      'Therum OS Keyboard Shortcuts\n\n' +
      '⌘K / Ctrl+K — Command palette\n' +
      'N — New post/page (on list pages)\n' +
      '/ — Focus search\n' +
      'G then H — Go to dashboard\n' +
      'G then P — Go to pages\n' +
      'G then O — Go to posts\n' +
      'G then M — Go to media\n' +
      'G then S — Go to settings\n' +
      '⌘+Click — Select multiple items\n' +
      '? — This help'
    );
    return;
  }

  // / — focus search
  if (e.key === '/' && !e.metaKey && !e.ctrlKey) {
    var search = document.querySelector('.th-lp-search-input') || document.querySelector('.th-sb-search-box input');
    if (search) { e.preventDefault(); search.focus(); }
    return;
  }

  // N — new post/page
  if (e.key === 'n' && !e.metaKey && !e.ctrlKey) {
    var page = new URLSearchParams(window.location.search).get('page') || '';
    if (page === 'therum-pages') window.location.href = '<?php echo esc_js( admin_url( 'post-new.php?post_type=page' ) ); ?>';
    else if (page === 'therum-posts') window.location.href = '<?php echo esc_js( admin_url( 'post-new.php' ) ); ?>';
    else if (page === 'therum-case-studies') window.location.href = '<?php echo esc_js( admin_url( 'post-new.php?post_type=case_study' ) ); ?>';
    return;
  }

  // G then X — go-to shortcuts (two-key sequence)
  if (e.key === 'g' && !e.metaKey && !e.ctrlKey) {
    var gHandler = function(e2) {
      document.removeEventListener('keydown', gHandler);
      clearTimeout(gTimer);
      var map = {
        'h': '<?php echo esc_js( admin_url( 'admin.php?page=therum' ) ); ?>',
        'p': '<?php echo esc_js( admin_url( 'admin.php?page=therum-pages' ) ); ?>',
        'o': '<?php echo esc_js( admin_url( 'admin.php?page=therum-posts' ) ); ?>',
        'm': '<?php echo esc_js( admin_url( 'admin.php?page=therum-media' ) ); ?>',
        's': '<?php echo esc_js( admin_url( 'admin.php?page=therum-settings' ) ); ?>',
        'u': '<?php echo esc_js( admin_url( 'admin.php?page=therum-users' ) ); ?>',
        'c': '<?php echo esc_js( admin_url( 'admin.php?page=therum-customization' ) ); ?>',
      };
      if (map[e2.key]) { e2.preventDefault(); window.location.href = map[e2.key]; }
    };
    var gTimer = setTimeout(function() { document.removeEventListener('keydown', gHandler); }, 1000);
    document.addEventListener('keydown', gHandler);
    return;
  }
});

})();
</script>
	<?php
}, 999 );


// ════════════════════════════════════════════════════════════════════════════
//  BULK ACTIONS — AJAX handler
// ════════════════════════════════════════════════════════════════════════════

add_action( 'wp_ajax_therum_bulk_action', function() {
	if ( ! current_user_can( 'edit_posts' ) ) wp_send_json_error( 'forbidden' );
	$nonce = $_POST['nonce'] ?? '';
	if ( ! wp_verify_nonce( $nonce, 'therum_theme' ) && ! wp_verify_nonce( $nonce, 'therum_options' ) && ! wp_verify_nonce( $nonce, 'therum_layout' ) ) {
		wp_send_json_error( 'bad nonce' );
	}

	$action   = sanitize_key( $_POST['bulk_action'] ?? '' );
	$post_ids = json_decode( wp_unslash( $_POST['post_ids'] ?? '[]' ), true );
	if ( ! is_array( $post_ids ) || empty( $post_ids ) ) wp_send_json_error( 'no posts' );

	$allowed = [ 'publish', 'draft', 'trash', 'untrash' ];
	if ( ! in_array( $action, $allowed, true ) ) wp_send_json_error( 'bad action' );

	$count = 0;
	foreach ( $post_ids as $id ) {
		$id = (int) $id;
		if ( ! current_user_can( 'edit_post', $id ) ) continue;

		if ( $action === 'trash' ) {
			wp_trash_post( $id );
		} elseif ( $action === 'untrash' ) {
			wp_untrash_post( $id );
		} else {
			wp_update_post( [ 'ID' => $id, 'post_status' => $action ] );
		}
		$count++;
	}

	if ( class_exists( 'Therum_Activity_Log' ) ) {
		Therum_Activity_Log::log( 'bulk_' . $action, 'posts', 0, '', $count . ' posts' );
	}

	wp_send_json_success( [ 'action' => $action, 'count' => $count ] );
} );


// ════════════════════════════════════════════════════════════════════════════
//  REVISION HISTORY — add "Revisions" link to post row actions
// ════════════════════════════════════════════════════════════════════════════

add_filter( 'post_row_actions', 'therum_add_revision_link', 10, 2 );
add_filter( 'page_row_actions', 'therum_add_revision_link', 10, 2 );

function therum_add_revision_link( $actions, $post ) {
	if ( ! wp_revisions_enabled( $post ) ) return $actions;
	$count = count( wp_get_post_revisions( $post->ID, [ 'posts_per_page' => -1, 'fields' => 'ids' ] ) );
	if ( $count > 0 ) {
		$url = admin_url( 'revision.php?revision=' . $post->ID );
		$actions['therum_revisions'] = '<a href="' . esc_url( $url ) . '">' . $count . ' revision' . ( $count === 1 ? '' : 's' ) . '</a>';
	}
	return $actions;
}


// ════════════════════════════════════════════════════════════════════════════
//  JSON IMPORTER — handles our own therum-*-export-*.json format
// ════════════════════════════════════════════════════════════════════════════

add_action( 'wp_ajax_therum_import_content', function() {
	if ( ! current_user_can( 'edit_posts' ) ) wp_send_json_error( 'forbidden' );
	check_admin_referer( 'therum_import' );

	if ( empty( $_FILES['file'] ) ) wp_send_json_error( 'no file' );
	$file = $_FILES['file'];
	if ( $file['error'] !== UPLOAD_ERR_OK ) wp_send_json_error( 'upload error: ' . $file['error'] );

	// Cap upload size before reading — wp_max_upload_size() is the right
	// natural ceiling here; an attacker uploading a multi-GB JSON would
	// otherwise OOM the request.
	$max = (int) wp_max_upload_size();
	if ( $max > 0 && (int) ( $file['size'] ?? 0 ) > $max ) {
		wp_send_json_error( 'file too large (max ' . size_format( $max ) . ')' );
	}

	$json = file_get_contents( $file['tmp_name'] );
	if ( $json === false ) wp_send_json_error( 'could not read upload' );
	$data = json_decode( $json, true );
	if ( json_last_error() !== JSON_ERROR_NONE ) {
		wp_send_json_error( 'invalid JSON: ' . json_last_error_msg() );
	}
	if ( ! is_array( $data ) || empty( $data['items'] ) ) wp_send_json_error( 'invalid JSON or no items' );

	$imported = 0;
	$skipped  = 0;

	foreach ( $data['items'] as $item ) {
		// Check if a post with same slug + type already exists
		$existing = get_page_by_path( $item['slug'] ?? '', OBJECT, $item['meta']['_wp_post_type'] ?? $data['post_type'] ?? 'post' );
		if ( $existing ) { $skipped++; continue; }

		$new_id = wp_insert_post( [
			'post_type'    => $data['post_type'] ?? 'post',
			'post_status'  => $item['status'] ?? 'draft',
			'post_title'   => $item['title'] ?? '',
			'post_name'    => $item['slug'] ?? '',
			'post_content' => $item['content'] ?? '',
			'post_excerpt' => $item['excerpt'] ?? '',
			'post_date'    => $item['date'] ?? current_time( 'mysql' ),
			'menu_order'   => $item['menu_order'] ?? 0,
		], true );

		if ( is_wp_error( $new_id ) ) { $skipped++; continue; }

		// Import meta. Block keys that carry access-control / credential state or
		// internal bookkeeping so a crafted export file can't smuggle in
		// capability or session data alongside ordinary content meta.
		if ( ! empty( $item['meta'] ) && is_array( $item['meta'] ) ) {
			$blocked_meta = [
				'_edit_lock', '_edit_last', '_wp_old_slug',
				'_capabilities', 'wp_capabilities', '_wp_user_level',
				'session_tokens', '_wp_persisted_preferences',
			];
			foreach ( $item['meta'] as $key => $value ) {
				$key = (string) $key;
				if ( in_array( $key, $blocked_meta, true ) ) continue;
				// Disallow capability-bearing keys regardless of prefix variants.
				if ( stripos( $key, 'capabilities' ) !== false || stripos( $key, 'user_level' ) !== false ) continue;
				update_post_meta( $new_id, $key, maybe_unserialize( $value ) );
			}
		}

		// Import taxonomies
		if ( ! empty( $item['taxonomies'] ) && is_array( $item['taxonomies'] ) ) {
			foreach ( $item['taxonomies'] as $tax => $terms ) {
				wp_set_object_terms( $new_id, $terms, $tax );
			}
		}

		$imported++;
	}

	if ( class_exists( 'Therum_Activity_Log' ) ) {
		Therum_Activity_Log::log( 'imported', $data['post_type'] ?? 'content', 0, '', "$imported imported, $skipped skipped" );
	}

	wp_send_json_success( [ 'imported' => $imported, 'skipped' => $skipped, 'total' => count( $data['items'] ) ] );
} );
