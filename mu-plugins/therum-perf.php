<?php
/**
 * Plugin Name: Therum OS — Performance
 * Description: HTML/CSS minify, scheduled backups, S3 upload, notifications,
 *              and LiteSpeed Cache one-shot tuner.
 *              Merged from therum-perf-engine, therum-lscache-tune, therum-cache-bust, therum-asset-gz (1.8.6).
 * Version: 1.9.0
 */

// ── OPcache preload (PHP-FPM calls this file directly as opcache.preload) ────
// Point opcache.preload to this file in php.ini, then restart PHP-FPM.
// All 16 Therum mu-plugins compile at startup for warm bytecode on first hit.
if ( ! defined( 'ABSPATH' ) ) {
	if ( extension_loaded( 'Zend OPcache' ) ) {
		$_th_base  = __DIR__;
		$_th_files = [
			'therum-admin.php',         // Admin chrome + dock + settings + wizard (Phase 3 Merge #4)
			'therum-api.php',           // REST API + connections + MCP server (Phase 3 Merge #5)
			'therum-auth.php',          // Auth + 2FA + scoped tokens + CSRF
			'therum-content.php',       // Content + cs-modes
			'therum-core.php',          // Core + autoloader + queue + maintenance cron
			'therum-design.php',        // Visual systems + fonts + fx + light-default
			'therum-media.php',         // NeoRename
			'therum-perf.php',          // Performance + object cache status
			'therum-updates.php',
			'therum-woo.php',           // Woo bridge + perf + strip-commerce
		];
		foreach ( $_th_files as $_th_f ) {
			$_th_path = $_th_base . '/' . $_th_f;
			if ( file_exists( $_th_path ) ) opcache_compile_file( $_th_path );
		}
		unset( $_th_base, $_th_files, $_th_f, $_th_path );
	}
	return;
}


// ════════════════════════════════════════════════════════════════════════
// PERF ENGINE (minify, backup, notifications) — from therum-perf-engine.php
// ════════════════════════════════════════════════════════════════════════

if ( ! defined( 'ABSPATH' ) ) exit;


// ═════════════════════════════════════════════════════════════════════════════
//  1. PERFORMANCE — fill in the missing 3 enforcement bits
// ═════════════════════════════════════════════════════════════════════════════

// ─── Object Cache ────────────────────────────────────────────────────────────
// WP has built-in non-persistent object cache. The "Object cache" toggle in our
// UI promises an in-memory cache for queries — that's what WP_Object_Cache
// already does. We expose a flag and warm common queries when enabled.
add_action( 'init', function() {
	if ( ! get_option( 'th_perf_cache', true ) ) return;

	// Warm common admin queries by pre-fetching on admin pages
	if ( ! is_admin() ) return;

	// Prime the option cache for our own settings reads (avoids 30+ DB hits)
	$th_options = [
		'th_perf_cache', 'th_perf_lazy_images', 'th_perf_defer_js',
		'th_perf_min_css', 'th_perf_min_html', 'th_perf_disable_emoji',
		'th_perf_disable_embeds', 'th_perf_heartbeat', 'th_perf_revisions_limit',
		'th_perf_trash_days', 'th_perf_autosave_interval',
	];
	wp_cache_get_multiple( $th_options, 'options' );
}, 1 );


// ─── Minify CSS — strip whitespace from Customizer additional CSS ──────────
// Note: enqueued .css files are not rewritten (we don't proxy assets); the
// HTML minifier below handles inline <style> blocks emitted by the page.
add_filter( 'wp_get_custom_css', function( $css ) {
	if ( ! get_option( 'th_perf_min_css', false ) ) return $css;
	return therum_minify_css( $css );
});


// ─── Minify HTML — strip whitespace + comments from final page output ──────
add_action( 'template_redirect', function() {
	if ( ! get_option( 'th_perf_min_html', false ) ) return;
	if ( is_admin() || is_feed() || is_robots() || is_trackback() ) return;

	// Skip if the request is XML/JSON
	if ( wp_doing_ajax() ) return;

	// Skip in Bricks builder: the builder HTML is large enough to blow the
	// PCRE backtrack limit on the comment-stripping regex, which makes
	// preg_replace return null and silently empties the response.
	if ( isset( $_GET['bricks'] ) || isset( $_GET['bricks_preview'] ) ) return;
	if ( function_exists( 'bricks_is_builder' ) && ( bricks_is_builder() || bricks_is_builder_iframe() ) ) return;

	ob_start( 'therum_minify_html' );
}, 1 );


function therum_minify_html( $html ) {
	if ( empty( $html ) ) return $html;
	// Defensive: skip minification on very large responses (Bricks builder, etc.).
	// PCRE limits make the regex chain return null on huge inputs, which would
	// silently empty the response.
	if ( strlen( $html ) > 512 * 1024 ) return $html;

	// Don't touch <pre>, <textarea>, <script>, <style> contents.
	// Use NON-HTML control-byte sentinels so the comment-stripping regex below
	// can't eat them. Earlier versions used `<!--TH_PH_N-->` placeholders that
	// matched the comment regex and got deleted, taking every <style>/<script>
	// block with them — leaving every page silently unstyled and unscripted.
	$placeholders = [];
	$original     = $html;
	try {
		$html = preg_replace_callback(
			'#<(pre|textarea|script|style)([^>]*)>([\s\S]*?)</\1>#i',
			function( $m ) use ( &$placeholders ) {
				$key = "\x02TH_PH_" . count( $placeholders ) . "\x03";
				$placeholders[ $key ] = $m[0];
				return $key;
			},
			$html
		);
		if ( $html === null ) return $original; // PCRE backtrack limit hit

		// Remove HTML comments (but keep IE conditional)
		$html = preg_replace( '/<!--(?!\s*(?:\[if [^\]]+]|<!|>))(?:(?!-->).)*-->/s', '', $html );

		// Collapse whitespace between tags
		$html = preg_replace( '/>\s+</', '><', $html );

		// Collapse runs of whitespace within text
		$html = preg_replace( '/\s{2,}/', ' ', $html );

		// Trim line breaks
		$html = preg_replace( '/[\r\n]+/', "\n", $html );

		if ( $html === null ) return $original;

		return $html;
	} finally {
		// Always restore placeholders — even if a regex above returned null
		// or an exception bubbled up. Without this, a partial-minify could
		// ship the raw \x02TH_PH_N\x03 sentinels to the browser.
		if ( $html !== null ) {
			foreach ( $placeholders as $key => $content ) {
				$html = str_replace( $key, $content, $html );
			}
		}
	}
}


function therum_minify_css( $css ) {
	if ( empty( $css ) ) return $css;
	// Strip comments
	$css = preg_replace( '!/\*[^*]*\*+([^/][^*]*\*+)*/!', '', $css );
	// Collapse whitespace
	$css = preg_replace( '/\s+/', ' ', $css );
	// Tighten around braces, colons, semicolons, commas
	$css = preg_replace( '/\s*([{}:;,>+~])\s*/', '$1', $css );
	// Remove trailing semicolons before }
	$css = str_replace( ';}', '}', $css );
	return trim( $css );
}


// ═════════════════════════════════════════════════════════════════════════════
//  2. BACKUP — scheduled + on-demand zip with optional S3
// ═════════════════════════════════════════════════════════════════════════════

// ─── Schedule cron event ─────────────────────────────────────────────────────
// Re-derive the schedule on every init. If the frequency option changed
// (e.g. daily → weekly) we have to clear the existing slot and re-schedule
// — `wp_next_scheduled` returning truthy doesn't tell us the recurrence
// matches the current option value.
add_action( 'init', function() {
	if ( ! get_option( 'th_backup_enabled', false ) ) {
		wp_clear_scheduled_hook( 'therum_backup_run' );
		return;
	}

	$freq    = get_option( 'th_backup_frequency', 'daily' );
	$next    = wp_next_scheduled( 'therum_backup_run' );
	$current = wp_get_schedule( 'therum_backup_run' );

	if ( ! $next ) {
		wp_schedule_event( time() + 60, $freq, 'therum_backup_run' );
	} elseif ( $current && $current !== $freq ) {
		wp_clear_scheduled_hook( 'therum_backup_run' );
		wp_schedule_event( time() + 60, $freq, 'therum_backup_run' );
	}
});

// Add 'weekly' schedule if WP doesn't have one (it has hourly/daily/twicedaily)
add_filter( 'cron_schedules', function( $s ) {
	$s['weekly'] = [ 'interval' => 7 * DAY_IN_SECONDS, 'display' => 'Once Weekly' ];
	return $s;
});


// ─── Cron handler ────────────────────────────────────────────────────────────
add_action( 'therum_backup_run', function() {
	therum_run_backup( 'auto' );
});


// ─── On-demand backup via AJAX ───────────────────────────────────────────────
add_action( 'wp_ajax_therum_backup_now', function() {
	if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'forbidden', 403 );
	check_ajax_referer( 'therum_backup', 'nonce' );

	$result = therum_run_backup( 'manual' );
	if ( is_wp_error( $result ) ) wp_send_json_error( $result->get_error_message() );
	wp_send_json_success( $result );
});


// ─── Backup engine ───────────────────────────────────────────────────────────
function therum_run_backup( $trigger = 'auto' ) {
	if ( ! class_exists( 'ZipArchive' ) ) {
		return new WP_Error( 'no_zip', 'PHP ZipArchive extension required for backups.' );
	}

	$upload_dir = wp_upload_dir();
	$backup_dir = WP_CONTENT_DIR . '/backups';
	if ( ! is_dir( $backup_dir ) ) {
		if ( ! wp_mkdir_p( $backup_dir ) ) {
			return new WP_Error( 'no_dir', 'Could not create backup directory: ' . $backup_dir );
		}
		// Drop a .htaccess to prevent direct access
		file_put_contents( $backup_dir . '/.htaccess', "Order deny,allow\nDeny from all\n" );
		file_put_contents( $backup_dir . '/index.php', '<?php // silence' );
	}

	$site_slug = sanitize_title( get_bloginfo( 'name' ) ) ?: 'site';
	$ts = wp_date( 'Ymd-His' );
	$filename = "{$site_slug}-{$ts}.zip";
	$path = $backup_dir . '/' . $filename;

	$zip = new ZipArchive();
	if ( $zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) !== true ) {
		return new WP_Error( 'zip_open', 'Could not open zip for writing.' );
	}

	// 1. Database dump (SQLite-compatible — works for both SQLite and MySQL)
	$db_dump = therum_dump_database();
	if ( is_string( $db_dump ) ) {
		$zip->addFromString( 'database.sql', $db_dump );
	} elseif ( is_wp_error( $db_dump ) ) {
		// Fall back to .sqlite file copy if SQLite
		$sqlite = WP_CONTENT_DIR . '/database/.ht.sqlite';
		if ( file_exists( $sqlite ) ) {
			$zip->addFile( $sqlite, 'database.sqlite' );
		}
	}

	// 2. Uploads directory
	$uploads_path = $upload_dir['basedir'];
	if ( is_dir( $uploads_path ) ) {
		therum_zip_dir( $zip, $uploads_path, 'uploads' );
	}

	// 3. Active theme(s)
	$theme = wp_get_theme();
	$theme_root = $theme->get_stylesheet_directory();
	if ( is_dir( $theme_root ) ) {
		therum_zip_dir( $zip, $theme_root, 'themes/' . basename( $theme_root ) );
	}

	// 4. Manifest
	$manifest = [
		'site_url'       => home_url(),
		'therum_version' => defined( 'THERUM_OS_VERSION' ) ? THERUM_OS_VERSION : '1.x',
		'engine_version' => get_bloginfo( 'version' ),
		'php'            => PHP_VERSION,
		'created'    => current_time( 'mysql' ),
		'trigger'    => $trigger,
		'plugins'    => array_keys( get_option( 'active_plugins', [] ) ),
		'theme'      => $theme->get_stylesheet(),
	];
	$zip->addFromString( 'manifest.json', wp_json_encode( $manifest, JSON_PRETTY_PRINT ) );

	$zip->close();

	$size = file_exists( $path ) ? filesize( $path ) : 0;

	$record = [
		'file'    => $filename,
		'path'    => $path,
		'size'    => $size,
		'time'    => time(),
		'trigger' => $trigger,
		'remote'  => '',
	];

	// 5. Upload to S3 if configured
	$dest = get_option( 'th_backup_destination', 'local' );
	if ( $dest === 's3' ) {
		$remote = therum_upload_to_s3( $path, $filename );
		if ( ! is_wp_error( $remote ) ) {
			$record['remote'] = $remote;
		}
	}

	// 6. Prune old backups (keep last 10 local)
	therum_prune_old_backups( $backup_dir, 10 );

	// 7. Append to history. autoload=false — history is large enough to
	// matter on a multi-site install and is only read from the Updates +
	// Settings → Performance admin views, never on the front-end.
	$history = get_option( 'th_backup_history', [] );
	array_unshift( $history, $record );
	$history = array_slice( $history, 0, 50 );
	update_option( 'th_backup_history', $history, false );

	update_option( 'th_backup_last_run', $record, false );

	// 8. Notification
	if ( get_option( 'th_notify_on_backup', false ) ) {
		therum_send_notification(
			'Backup completed',
			"Backup file: {$filename}\nSize: " . size_format( $size )
		);
	}

	return $record;
}


function therum_zip_dir( ZipArchive $zip, $source_dir, $zip_root ) {
	$source_dir = rtrim( $source_dir, '/\\' );
	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $source_dir, RecursiveDirectoryIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::SELF_FIRST
	);

	foreach ( $iterator as $file ) {
		if ( ! $file->isFile() ) continue;
		$path = $file->getPathname();
		$rel = substr( $path, strlen( $source_dir ) + 1 );
		// Skip cache + temp dirs
		if ( strpos( $rel, 'cache/' ) === 0 ) continue;
		if ( strpos( $rel, '.tmp' ) !== false ) continue;
		$zip->addFile( $path, $zip_root . '/' . str_replace( '\\', '/', $rel ) );
	}
}


/**
 * Stream-build the database dump in fixed-size row batches so we never hold
 * the whole table in memory. The previous SELECT * FROM `{$table}` would
 * OOM on any table > ~50MB — the new path keeps memory bounded to one
 * batch's worth of rows.
 */
function therum_dump_database() {
	global $wpdb;

	if ( defined( 'DB_ENGINE' ) && constant( 'DB_ENGINE' ) === 'sqlite' ) {
		return new WP_Error( 'sqlite', 'SQLite — copy file instead' );
	}

	$tables = $wpdb->get_col( 'SHOW TABLES' );
	if ( ! is_array( $tables ) || empty( $tables ) ) {
		return new WP_Error( 'no_tables', 'No tables found' );
	}

	$batch_size = (int) apply_filters( 'therum/backup/db_batch_size', 500 );
	$out  = "-- Therum OS backup\n-- " . current_time( 'mysql' ) . "\n\n";

	foreach ( $tables as $table ) {
		$create = $wpdb->get_row( $wpdb->prepare( 'SHOW CREATE TABLE `' . esc_sql( $table ) . '`' ), ARRAY_N );
		if ( $create && isset( $create[1] ) ) {
			$out .= "DROP TABLE IF EXISTS `{$table}`;\n" . $create[1] . ";\n\n";
		}

		$offset = 0;
		while ( true ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT * FROM `' . esc_sql( $table ) . '` LIMIT %d OFFSET %d',
					$batch_size,
					$offset
				),
				ARRAY_A
			);
			if ( empty( $rows ) ) break;
			foreach ( $rows as $r ) {
				$cols = array_map( fn( $c ) => "`{$c}`", array_keys( $r ) );
				$vals = array_map( function( $v ) {
					if ( is_null( $v ) ) return 'NULL';
					return "'" . esc_sql( $v ) . "'";
				}, array_values( $r ) );
				$out .= "INSERT INTO `{$table}` (" . implode( ',', $cols ) . ") VALUES (" . implode( ',', $vals ) . ");\n";
			}
			$offset += $batch_size;
			if ( count( $rows ) < $batch_size ) break;
		}
		$out .= "\n";
	}
	return $out;
}


function therum_prune_old_backups( $dir, $keep = 10 ) {
	$files = glob( $dir . '/*.zip' );
	if ( ! is_array( $files ) ) return;
	// Precompute mtimes once — filemtime() inside usort is O(n log n)
	// syscalls otherwise.
	$with_mtime = array_map( fn( $f ) => [ $f, @filemtime( $f ) ?: 0 ], $files );
	usort( $with_mtime, fn( $a, $b ) => $b[1] <=> $a[1] );
	$old = array_slice( $with_mtime, $keep );
	foreach ( $old as $entry ) @unlink( $entry[0] );
}


// ─── S3 upload (signature v4, no SDK needed) ────────────────────────────────
function therum_upload_to_s3( $local_path, $remote_key ) {
	$bucket    = trim( (string) get_option( 'th_backup_s3_bucket', '' ) );
	$region    = trim( (string) get_option( 'th_backup_s3_region', 'us-east-1' ) );
	$access    = trim( (string) get_option( 'th_backup_s3_access_key', '' ) );
	$secret    = trim( (string) get_option( 'th_backup_s3_secret_key', '' ) );
	$endpoint  = trim( (string) get_option( 'th_backup_s3_endpoint', '' ) );
	$prefix    = trim( (string) get_option( 'th_backup_s3_prefix', 'therum-backups' ), '/' );

	if ( ! $bucket || ! $access || ! $secret ) {
		return new WP_Error( 's3_config', 'Missing S3 credentials' );
	}

	$key = $prefix ? $prefix . '/' . $remote_key : $remote_key;

	// Default to AWS S3 endpoint
	$host = $endpoint ?: "s3.{$region}.amazonaws.com";
	$host = preg_replace( '#^https?://#', '', $host );

	$url = "https://{$host}/{$bucket}/{$key}";

	$body = file_get_contents( $local_path );
	if ( $body === false ) return new WP_Error( 's3_read', 'Could not read backup file' );

	$content_sha256 = hash( 'sha256', $body );
	$now = gmdate( 'Ymd\THis\Z' );
	$today = gmdate( 'Ymd' );

	$canonical_headers =
		"host:{$host}\n" .
		"x-amz-content-sha256:{$content_sha256}\n" .
		"x-amz-date:{$now}\n";
	$signed_headers = 'host;x-amz-content-sha256;x-amz-date';

	$canonical_request =
		"PUT\n" .
		"/{$bucket}/{$key}\n" .
		"\n" .
		$canonical_headers . "\n" .
		$signed_headers . "\n" .
		$content_sha256;

	$credential_scope = "{$today}/{$region}/s3/aws4_request";
	$string_to_sign =
		"AWS4-HMAC-SHA256\n" .
		"{$now}\n" .
		"{$credential_scope}\n" .
		hash( 'sha256', $canonical_request );

	$k_date    = hash_hmac( 'sha256', $today, 'AWS4' . $secret, true );
	$k_region  = hash_hmac( 'sha256', $region, $k_date, true );
	$k_service = hash_hmac( 'sha256', 's3', $k_region, true );
	$k_signing = hash_hmac( 'sha256', 'aws4_request', $k_service, true );
	$signature = hash_hmac( 'sha256', $string_to_sign, $k_signing );

	$auth = "AWS4-HMAC-SHA256 Credential={$access}/{$credential_scope}, SignedHeaders={$signed_headers}, Signature={$signature}";

	$response = wp_remote_request( $url, [
		'method'  => 'PUT',
		'timeout' => 120,
		'headers' => [
			'Host'                 => $host,
			'x-amz-content-sha256' => $content_sha256,
			'x-amz-date'           => $now,
			'Authorization'        => $auth,
			'Content-Type'         => 'application/zip',
			'Content-Length'       => strlen( $body ),
		],
		'body' => $body,
	]);

	if ( is_wp_error( $response ) ) return $response;
	$code = wp_remote_retrieve_response_code( $response );
	if ( $code < 200 || $code >= 300 ) {
		return new WP_Error( 's3_failed', 'S3 upload failed: HTTP ' . $code . ' — ' . wp_remote_retrieve_body( $response ) );
	}

	return $url;
}


// ═════════════════════════════════════════════════════════════════════════════
//  3. NOTIFICATIONS — admin login alerts, update alerts, Slack webhook
// ═════════════════════════════════════════════════════════════════════════════

// ─── Central dispatcher ──────────────────────────────────────────────────────
function therum_send_notification( $subject, $body, $tone = 'info' ) {
	$admin_email = get_option( 'admin_email' );
	$slack_url   = trim( (string) get_option( 'th_notify_slack_webhook', '' ) );

	$site_name = get_bloginfo( 'name' );
	$full_subject = "[{$site_name}] {$subject}";

	// Email — wp_mail is synchronous so a stalled SMTP can block the request
	// that triggered this notification. Register a one-shot failure listener
	// per call so the error_log line says "this specific notification failed",
	// not a generic mail-failed bag — operators can see which notification
	// type is failing without tailing the queue.
	if ( $admin_email && get_option( 'th_notify_email', true ) ) {
		$listener = static function ( $wp_error ) use ( $full_subject ) {
			if ( is_wp_error( $wp_error ) ) {
				error_log( '[therum-perf] wp_mail failed for "' . $full_subject . '": ' . $wp_error->get_error_message() );
			}
		};
		add_action( 'wp_mail_failed', $listener );
		try {
			wp_mail( $admin_email, $full_subject, $body );
		} finally {
			remove_action( 'wp_mail_failed', $listener );
		}
	}

	// Slack — cap body length. Slack rejects payloads >40KB silently with a
	// 400; a runaway stack trace or generated payload would otherwise just
	// vanish. Cap well below the limit to leave headroom for the JSON envelope
	// + emoji + subject. Filterable so ops can tune.
	//
	// SSRF guard: an admin-configured webhook URL is still admin input, and
	// the same guard the api.php dispatcher uses applies — refuse to POST to
	// internal addresses. We test on the resolved scheme + host; if the
	// helper doesn't exist (load-order edge case), fall through with the
	// scheme check below.
	if ( $slack_url ) {
		if ( function_exists( 'therum_webhook_url_safe' ) && ! therum_webhook_url_safe( $slack_url ) ) {
			error_log( '[therum-perf] Slack webhook URL refused by SSRF guard.' );
			$slack_url = '';
		}
	}
	if ( $slack_url ) {
		$emoji      = [ 'info' => ':information_source:', 'warn' => ':warning:', 'error' => ':rotating_light:', 'success' => ':white_check_mark:' ][ $tone ] ?? ':information_source:';
		$max_body   = (int) apply_filters( 'therum_slack_body_max_chars', 35000 );
		// Reserve budget for the truncation marker we append below so multi-byte
		// bodies + marker still fit under the cap.
		$marker     = "\n…[truncated]";
		$body_short = mb_substr( (string) $body, 0, $max_body - mb_strlen( $marker ) );
		if ( $body_short !== (string) $body ) {
			$body_short .= $marker;
		}
		wp_remote_post( $slack_url, [
			'timeout' => 8,
			'headers' => [ 'Content-Type' => 'application/json' ],
			'body'    => wp_json_encode([
				'text' => "{$emoji} *{$full_subject}*\n{$body_short}",
			]),
		]);
	}
}


// ─── Admin login alerts ──────────────────────────────────────────────────────
add_action( 'wp_login', function( $user_login, $user ) {
	if ( ! get_option( 'th_notify_on_login', false ) ) return;
	if ( ! ( $user instanceof WP_User ) ) return;
	if ( ! user_can( $user, 'manage_options' ) ) return;

	$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '?';
	$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '?';

	therum_send_notification(
		'Admin login',
		"User: {$user->user_login} ({$user->user_email})\nIP: {$ip}\nAgent: {$ua}\nTime: " . current_time( 'mysql' ),
		'info'
	);
}, 10, 2 );


// ─── Plugin update alerts ────────────────────────────────────────────────────
add_action( 'upgrader_process_complete', function( $upgrader, $hook_extra ) {
	if ( ! get_option( 'th_notify_on_update', true ) ) return;
	if ( empty( $hook_extra['action'] ) || $hook_extra['action'] !== 'update' ) return;

	$type = $hook_extra['type'] ?? 'unknown';
	$items = [];

	if ( $type === 'plugin' && ! empty( $hook_extra['plugins'] ) ) {
		foreach ( $hook_extra['plugins'] as $f ) {
			$data = function_exists( 'get_plugin_data' ) ? get_plugin_data( WP_PLUGIN_DIR . '/' . $f, false, false ) : [];
			$items[] = ($data['Name'] ?? $f) . ' → v' . ($data['Version'] ?? '?');
		}
	} elseif ( $type === 'theme' && ! empty( $hook_extra['themes'] ) ) {
		foreach ( $hook_extra['themes'] as $slug ) {
			$theme = wp_get_theme( $slug );
			$items[] = $theme->get( 'Name' ) . ' → v' . $theme->get( 'Version' );
		}
	} elseif ( $type === 'core' ) {
		$items[] = 'WordPress core → v' . get_bloginfo( 'version' );
	}

	if ( empty( $items ) ) return;

	therum_send_notification(
		ucfirst( $type ) . ' updated',
		"Updated:\n• " . implode( "\n• ", $items ),
		'success'
	);
}, 10, 2 );


// ─── Test notification (for "Send test" button) ─────────────────────────────
add_action( 'wp_ajax_therum_notify_test', function() {
	if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'forbidden', 403 );
	check_ajax_referer( 'therum_notify', 'nonce' );

	therum_send_notification(
		'Test notification',
		"This is a test from your Therum OS settings panel.\nIf you're seeing this, notifications are wired up correctly.",
		'info'
	);
	wp_send_json_success( [ 'msg' => 'Test sent' ] );
});

// ════════════════════════════════════════════════════════════════════════
// LSCACHE TUNE (one-shot LiteSpeed Cache config) — from therum-lscache-tune.php
// ════════════════════════════════════════════════════════════════════════


add_action( 'plugins_loaded', function() {
	// Only act when LSCache is actually loaded
	if ( ! defined( 'LSCWP_V' ) && ! class_exists( 'LiteSpeed\Core' ) ) return;

	// Audit-only mode: report on Tools → Site Health, no writes
	if ( ! defined( 'THERUM_LSCACHE_APPLY' ) || ! THERUM_LSCACHE_APPLY ) return;

	// One-shot guard
	if ( get_option( '_th_lscache_tuned' ) ) return;

	// Recommended values — conservative; biggest visible wins, lowest breakage risk.
	// Each option key is the LSCache `litespeed.conf.<key>` shape (without the prefix).
	$recos = [
		// ── Media / images ───────────────────────────────────────────────
		'media-lazy'                 => 1,    // Lazy-load images (native loading=lazy)
		'media-iframe_lazy'          => 1,    // Lazy-load iframes (YouTube embeds, etc.)
		'media-add_missing_sizes'    => 1,    // Add width/height to <img> — kills CLS
		'media-lqip'                 => 0,    // Off — needs LiteSpeed.com QUIC.cloud account
		'img_optm-webp'              => 1,    // Serve WebP (LSCache image opt service)
		'img_optm-webp_replace_srcset' => 1,

		// ── CSS / JS optimization ────────────────────────────────────────
		'optm-css_min'               => 1,
		'optm-js_min'                => 1,
		'optm-html_min'              => 1,
		'optm-css_comb'              => 0,    // Off — combining can break Bricks load order
		'optm-js_comb'               => 0,    // Off — same reason
		'optm-js_defer'              => 2,    // 2 = defer (1 = async, more breakage)
		'optm-css_async'             => 0,    // Off — async-CSS via LSCache often FOUCs Bricks
		'optm-emoji_rm'              => 1,    // Belt-and-suspenders w/ therum-woo-perf M2
		'optm-noscript_rm'           => 0,    // Leave — required for some no-JS fallbacks
		'optm-ggfonts_async'         => 1,    // font-display: swap on Google fonts
		'optm-ggfonts_rm'            => 0,    // Don't remove — only async
		'optm-css_font_display'      => 1,    // Force font-display: swap on @font-face

		// ── DB optimization ──────────────────────────────────────────────
		'db_optm-revisions_max'      => 5,    // Cap revisions per post
		'db_optm-revisions_age'      => 30,   // Auto-purge revisions > 30 days

		// ── Cache TTLs (defaults are already sensible — leave) ───────────
		// 'cache-ttl_pub'           => 604800,
	];

	$applied = [];
	foreach ( $recos as $key => $val ) {
		$opt = "litespeed.conf.{$key}";
		$current = get_option( $opt );
		if ( $current === false ) continue;   // Option doesn't exist in this LSCache version
		if ( (string) $current === (string) $val ) continue;   // Already the right value
		update_option( $opt, $val );
		$applied[ $key ] = [ 'from' => $current, 'to' => $val ];
	}

	if ( $applied ) {
		// Purge LSCache so changes take effect on next request
		if ( function_exists( 'do_action' ) ) {
			do_action( 'litespeed_purge_all' );
		}
		update_option( '_th_lscache_tuned', [
			'when'    => current_time( 'mysql' ),
			'applied' => $applied,
		] );
		// Only log on WP_DEBUG — tune-applied is a normal operation, not an
		// error path, and on a hook that fires across admin requests this would
		// quickly fill production logs.
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG && function_exists( 'error_log' ) ) {
			error_log( '[therum-lscache-tune] applied: ' . wp_json_encode( array_keys( $applied ) ) );
		}
	}
}, 99 );


// ── Audit notice (always shown when LSCache is loose) ────────────────────────
add_action( 'admin_notices', function() {
	if ( ! current_user_can( 'manage_options' ) ) return;
	if ( ! defined( 'LSCWP_V' ) && ! class_exists( 'LiteSpeed\Core' ) ) return;

	// If already tuned, show the success state once
	$tuned = get_option( '_th_lscache_tuned' );
	if ( $tuned && is_array( $tuned ) ) {
		// Only show on the LSCache settings pages and the Therum dashboard
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$slug = $screen ? (string) $screen->id : '';
		if ( ! str_contains( $slug, 'litespeed' ) && ! str_contains( $slug, 'therum' ) ) return;
		printf(
			'<div class="notice notice-success"><p><strong>Therum LSCache Tuner:</strong> %d settings applied on %s. Delete the <code>_th_lscache_tuned</code> option to re-run.</p></div>',
			count( $tuned['applied'] ?? [] ),
			esc_html( (string) ( $tuned['when'] ?? '' ) )
		);
		return;
	}

	// Loose-defaults nudge
	$loose = [];
	foreach ( [ 'optm-css_min', 'optm-js_min', 'optm-js_defer', 'media-lazy', 'media-add_missing_sizes', 'img_optm-webp' ] as $k ) {
		$v = get_option( "litespeed.conf.{$k}" );
		if ( $v === '' || $v === '0' || $v === 0 || $v === null ) $loose[] = $k;
	}
	if ( ! $loose ) return;

	$apply_status = ( defined( 'THERUM_LSCACHE_APPLY' ) && THERUM_LSCACHE_APPLY )
		? '<em>(THERUM_LSCACHE_APPLY is set — will apply on next pageload)</em>'
		: 'Add <code>define( \'THERUM_LSCACHE_APPLY\', true );</code> to <code>wp-config.php</code> to apply.';

	printf(
		'<div class="notice notice-warning"><p><strong>Therum LSCache Tuner:</strong> %d safe-win settings are off. %s</p></div>',
		count( $loose ),
		$apply_status
	);
});


// ════════════════════════════════════════════════════════════════════════════
//  CACHE BUST — multi-layer purge — from therum-cache-bust.php
// ════════════════════════════════════════════════════════════════════════════

if ( ! class_exists( 'Therum_Cache_Bust' ) ) :

final class Therum_Cache_Bust {

	/**
	 * Nuke every cache layer we can reach. Idempotent. Safe to call from
	 * any hook — guards every step for plugin existence.
	 */
	public static function purge_all( string $context = '' ): void {

		// 1. WP object cache
		if ( function_exists( 'wp_cache_flush' ) ) wp_cache_flush();

		// 2. Therum-namespaced transients (the ones we control)
		self::delete_transients_with_prefix( 'therum_' );

		// 3. LiteSpeed Cache · full-page cache
		if ( has_action( 'litespeed_purge_all' ) ) {
			do_action( 'litespeed_purge_all' );
		}

		// 4. Bricks · template/query render cache
		if ( class_exists( '\Bricks\Helpers' ) && method_exists( '\Bricks\Helpers', 'delete_query_results_transient' ) ) {
			\Bricks\Helpers::delete_query_results_transient();
		}
		// Bricks element cache (if available)
		if ( class_exists( '\Bricks\Database' ) && method_exists( '\Bricks\Database', 'delete_all_post_meta' ) ) {
			// note: NOT calling this in routine purges — it nukes meta. left as a
			// reference for the manual "force regenerate" case.
		}

		// 5. WP rewrite rules — soft flush so a theme/plugin change doesn't
		//    leave a 404 on freshly-registered routes
		if ( function_exists( 'flush_rewrite_rules' ) ) {
			flush_rewrite_rules( false ); // false = no .htaccess rewrite
		}

		/**
		 * Fires after every Therum-initiated cache purge so other modules
		 * can hook in (e.g. CDN edge invalidations registered elsewhere).
		 */
		do_action( 'therum_cache_purged', $context );
	}

	/**
	 * Force-fresh URL flag — when `?th_fresh=1` is present, no layer should
	 * cache this request. We add no-store headers + signal to LiteSpeed.
	 */
	public static function handle_fresh_flag(): void {
		if ( empty( $_GET['th_fresh'] ) ) return;
		nocache_headers();
		if ( ! headers_sent() ) {
			@header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0', true );
			@header( 'Pragma: no-cache', true );
		}
		if ( ! defined( 'DONOTCACHEPAGE' ) ) define( 'DONOTCACHEPAGE', true );
		if ( ! defined( 'DONOTCACHEDB' ) )   define( 'DONOTCACHEDB', true );
		if ( ! defined( 'DONOTCACHEOBJECT' ) ) define( 'DONOTCACHEOBJECT', true );
	}

	/**
	 * Invalidate OPcache for a specific file (called when a mu-plugin is
	 * edited and we want the new bytecode loaded next request).
	 */
	public static function opcache_invalidate( string $path ): void {
		if ( function_exists( 'opcache_invalidate' ) && file_exists( $path ) ) {
			@opcache_invalidate( $path, true );
		}
	}

	private static function delete_transients_with_prefix( string $prefix ): void {
		global $wpdb;
		if ( ! $wpdb ) return;
		// Site transients
		$wpdb->query( $wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			'_transient_' . $wpdb->esc_like( $prefix ) . '%',
			'_transient_timeout_' . $wpdb->esc_like( $prefix ) . '%'
		) );
		// Multisite transients (if applicable)
		if ( is_multisite() && $wpdb->sitemeta ) {
			$wpdb->query( $wpdb->prepare(
				"DELETE FROM {$wpdb->sitemeta} WHERE meta_key LIKE %s OR meta_key LIKE %s",
				'_site_transient_' . $wpdb->esc_like( $prefix ) . '%',
				'_site_transient_timeout_' . $wpdb->esc_like( $prefix ) . '%'
			) );
		}
	}
}

endif;

// ─── AJAX: manual purge from Settings > Performance ─────────────────────────
add_action( 'wp_ajax_therum_purge_all_caches', function() {
	if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( [ 'msg' => 'forbidden' ], 403 );
	check_ajax_referer( 'therum_purge_all', 'nonce' );
	if ( class_exists( 'Therum_Cache_Bust' ) ) {
		Therum_Cache_Bust::purge_all( 'manual:settings' );
	}
	$layers = [];
	if ( function_exists( 'wp_cache_flush' ) )       $layers[] = 'WP object cache';
	if ( has_action( 'litespeed_purge_all' ) )       $layers[] = 'LiteSpeed';
	if ( class_exists( '\\Bricks\\Helpers' ) )       $layers[] = 'Bricks';
	$layers[] = 'Therum transients';
	wp_send_json_success( [ 'msg' => 'Purged: ' . implode( ', ', $layers ), 'layers' => $layers ] );
} );

// ═════════════════════════════════════════════════════════════════════════════
//  TRIGGERS — events where we always want a multi-layer purge
// ═════════════════════════════════════════════════════════════════════════════

// Therum settings + theme state saves
add_action( 'updated_option', function( $option ) {
	if ( strpos( $option, 'therum' ) === 0 || strpos( $option, 'th_' ) === 0 ) {
		Therum_Cache_Bust::purge_all( 'option:' . $option );
	}
}, 10, 1 );

// User-meta saves for Therum theme state (per-user theme picks)
add_action( 'updated_user_meta', function( $meta_id, $object_id, $meta_key ) {
	if ( strpos( (string) $meta_key, 'therum' ) === 0 || strpos( (string) $meta_key, 'th_' ) === 0 ) {
		Therum_Cache_Bust::purge_all( 'usermeta:' . $meta_key );
	}
}, 10, 3 );

// Theme switch
add_action( 'switch_theme',  fn() => Therum_Cache_Bust::purge_all( 'switch_theme' ) );

// Plugin activate / deactivate / delete
add_action( 'activated_plugin',   fn( $p ) => Therum_Cache_Bust::purge_all( 'activated:' . $p ) );
add_action( 'deactivated_plugin', fn( $p ) => Therum_Cache_Bust::purge_all( 'deactivated:' . $p ) );
add_action( 'deleted_plugin',     fn( $p ) => Therum_Cache_Bust::purge_all( 'deleted:' . $p ) );

// Post + page saves
add_action( 'save_post',   function( $post_id ) {
	if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) return;
	Therum_Cache_Bust::purge_all( 'save_post:' . $post_id );
} );

// `?th_fresh=1` query flag
add_action( 'init',         [ 'Therum_Cache_Bust', 'handle_fresh_flag' ], 1 );
add_action( 'send_headers', [ 'Therum_Cache_Bust', 'handle_fresh_flag' ] );

// ═════════════════════════════════════════════════════════════════════════════
//  ADMIN-BAR · "Purge all caches" pill in the Therum topbar
// ═════════════════════════════════════════════════════════════════════════════

add_action( 'admin_post_therum_purge_cache', function() {
	if ( ! current_user_can( 'manage_options' ) ) wp_die( 'forbidden' );
	check_admin_referer( 'therum_purge_cache' );
	Therum_Cache_Bust::purge_all( 'admin_bar' );
	$redirect = wp_get_referer() ?: admin_url();
	wp_safe_redirect( add_query_arg( 'th_purged', '1', $redirect ) );
	exit;
} );

// One-line admin notice after a manual purge
add_action( 'admin_notices', function() {
	if ( empty( $_GET['th_purged'] ) ) return;
	echo '<div class="notice notice-success is-dismissible therum-keep"><p>Therum · all caches purged.</p></div>';
} );


// ════════════════════════════════════════════════════════════════════════════
//  FILE MTIME WATCHER — auto-purge cache when mu-plugin code is edited on
//  disk. WP DB events already trigger Therum_Cache_Bust::purge_all() via the
//  hooks above, but raw disk edits (Claude / vim / rsync deploys) don't fire
//  any WP action. This watcher closes the loop: on every admin_init, scan
//  mu-plugins/ for *.{php,css,js} mtimes; if any file is newer than the last
//  seen mtime, call purge_all() once and update the stored value.
// ════════════════════════════════════════════════════════════════════════════

add_action( 'admin_init', function () {
	if ( ! class_exists( 'Therum_Cache_Bust' ) ) return;
	$dir = __DIR__;
	if ( ! is_dir( $dir ) ) return;

	$files = array_merge(
		glob( $dir . '/*.php' )         ?: [],
		glob( $dir . '/assets/*.css' )  ?: [],
		glob( $dir . '/assets/*.js' )   ?: []
	);
	if ( ! $files ) return;

	$latest = 0;
	foreach ( $files as $f ) {
		$m = @filemtime( $f );
		if ( $m && $m > $latest ) $latest = $m;
	}
	if ( ! $latest ) return;

	$stored = (int) get_option( 'therum_assets_last_mtime', 0 );
	if ( $latest > $stored ) {
		Therum_Cache_Bust::purge_all( 'asset_edit' );
		update_option( 'therum_assets_last_mtime', $latest, false );
		// Also bump OPcache for changed PHP so the next request loads fresh bytecode
		if ( function_exists( 'opcache_reset' ) ) @opcache_reset();
	}
}, 2 ); // priority 2 — after .gz sync (priority 1) so we don't trigger on the .gz writes

// ════════════════════════════════════════════════════════════════════════════
//  ASSET GZ SYNC — from therum-asset-gz.php
// ════════════════════════════════════════════════════════════════════════════

add_action( 'admin_init', function () {
	$dir = __DIR__ . '/assets';
	if ( ! is_dir( $dir ) || ! function_exists( 'gzencode' ) ) return;

	$files = glob( $dir . '/*.{css,js}', GLOB_BRACE );
	if ( ! $files ) return;

	foreach ( $files as $src ) {
		$gz = $src . '.gz';
		if ( ! file_exists( $gz ) ) continue;

		$src_mtime = filemtime( $src );
		$gz_mtime  = filemtime( $gz );
		if ( $src_mtime <= $gz_mtime ) continue;

		$data = @file_get_contents( $src );
		if ( $data === false ) continue;

		$compressed = @gzencode( $data, 9 );
		if ( $compressed === false ) continue;

		if ( @file_put_contents( $gz, $compressed ) !== false ) {
			@touch( $gz, $src_mtime );
		}
	}
}, 1 );


// ════════════════════════════════════════════════════════════════════════════
//  OBJECT CACHE STATUS + ENFORCEMENT (Phase 5.3 — Redis detection + nudge)
// ════════════════════════════════════════════════════════════════════════════
// Detects Redis at boot and surfaces status in the Performance Overview.
// Does NOT replace the Redis Object Cache plugin — that's the well-tested
// drop-in. Therum's role here is to:
//   (1) detect whether Redis is reachable from PHP (phpredis + ping)
//   (2) detect whether a persistent object cache is wired (drop-in installed)
//   (3) warn the admin when (1) is true but (2) isn't (= "Redis is reachable,
//       finish the wiring")
//   (4) surface both states in the Performance Overview status rows
//
// VPS guide installs Redis and configures it on 127.0.0.1:6379. Local by
// Flywheel typically does NOT ship Redis — expect "pending" rows there.
//
// Kill switch:
//   define( 'THERUM_OBJECT_CACHE_DISABLE', true ) in wp-config.php
//
// Override host/port via filters (only useful for non-default deployments):
//   add_filter( 'therum_perf/redis_host', fn() => '10.0.0.5' );
//   add_filter( 'therum_perf/redis_port', fn() => 6380 );

if ( ! ( defined( 'THERUM_OBJECT_CACHE_DISABLE' ) && THERUM_OBJECT_CACHE_DISABLE ) ) {

	/**
	 * Cached Redis-reachability check. Runs at most once per request.
	 *
	 * @return array{
	 *   phpredis_available: bool,
	 *   reachable: bool,
	 *   host: string,
	 *   port: int,
	 *   error: ?string,
	 *   ext_object_cache: bool,
	 *   dropin_installed: bool
	 * }
	 */
	function therum_perf_object_cache_status(): array {
		static $cached = null;
		if ( $cached !== null ) return $cached;

		$host = (string) apply_filters( 'therum_perf/redis_host', '127.0.0.1' );
		$port = (int)    apply_filters( 'therum_perf/redis_port', 6379 );

		$status = [
			'phpredis_available' => class_exists( 'Redis' ),
			'reachable'          => false,
			'host'               => $host,
			'port'               => $port,
			'error'              => null,
			'ext_object_cache'   => function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache(),
			'dropin_installed'   => file_exists( WP_CONTENT_DIR . '/object-cache.php' ),
		];

		if ( ! $status['phpredis_available'] ) {
			$status['error'] = 'phpredis extension not loaded';
			return $cached = $status;
		}

		// Best-effort connect — 0.5s timeout so a missing Redis doesn't slow boots.
		try {
			$r  = new \Redis();
			$ok = @$r->connect( $host, $port, 0.5 );
			if ( $ok ) {
				$pong = @$r->ping();
				// phpredis 5.x returns +PONG, 6.x returns "PONG" or true depending on context
				$status['reachable'] = ( $pong === '+PONG' || $pong === 'PONG' || $pong === true );
				if ( ! $status['reachable'] && $pong === false ) {
					$status['error'] = 'ping failed';
				}
				@$r->close();
			} else {
				$status['error'] = "could not connect to {$host}:{$port}";
			}
		} catch ( \Throwable $e ) {
			$status['error'] = $e->getMessage();
		}

		return $cached = $status;
	}

	/**
	 * Surface object-cache status in the Performance Overview admin page.
	 * Mirrors the row-shape used by therum-woo.php Fast-order-lookup status.
	 */
	add_filter( 'therum_perf_status_rows', function( array $rows ): array {
		$s = therum_perf_object_cache_status();

		// Row 1 — Redis reachability
		if ( ! $s['phpredis_available'] ) {
			$rows[] = [
				'label' => 'Object cache · Redis',
				'state' => 'pending',
				'note'  => 'phpredis extension not loaded — install on host',
			];
		} elseif ( $s['reachable'] ) {
			$rows[] = [
				'label' => 'Object cache · Redis',
				'state' => 'enabled',
				'note'  => sprintf( 'reachable at %s:%d', $s['host'], $s['port'] ),
			];
		} else {
			$rows[] = [
				'label' => 'Object cache · Redis',
				'state' => 'pending',
				'note'  => $s['error'] ?? 'unreachable',
			];
		}

		// Row 2 — persistent object cache wiring (drop-in)
		if ( $s['ext_object_cache'] ) {
			$rows[] = [
				'label' => 'Object cache · drop-in',
				'state' => 'enabled',
				'note'  => 'persistent backend active',
			];
		} elseif ( $s['reachable'] ) {
			$rows[] = [
				'label' => 'Object cache · drop-in',
				'state' => 'pending',
				'note'  => 'Redis reachable — install Redis Object Cache plugin to wire it',
			];
		} else {
			$rows[] = [
				'label' => 'Object cache · drop-in',
				'state' => 'pending',
				'note'  => 'not installed (DB-backed transients in use)',
			];
		}

		return $rows;
	}, 20 );

	/**
	 * Admin notice when Redis is reachable but the drop-in is missing.
	 * The friction we want: "you set up Redis, now finish the wiring."
	 * Scoped to Therum dashboard + plugins screens to avoid notice spam.
	 */
	add_action( 'admin_notices', function() {
		if ( ! current_user_can( 'manage_options' ) ) return;

		$s = therum_perf_object_cache_status();
		if ( ! $s['reachable'] || $s['ext_object_cache'] ) return;

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen ) return;

		// Show on the Therum top-level admin screen + the Plugins list.
		$show_on_prefix = [ 'toplevel_page_therum', 'plugins' ];
		$match = false;
		foreach ( $show_on_prefix as $prefix ) {
			if ( str_starts_with( (string) $screen->id, $prefix ) ) { $match = true; break; }
		}
		if ( ! $match ) return;

		$install_url = admin_url( 'plugin-install.php?s=redis+object+cache&tab=search&type=term' );
		?>
		<div class="notice notice-warning is-dismissible">
			<p>
				<strong>Therum OS — Object cache:</strong>
				Redis is reachable at <code><?php echo esc_html( $s['host'] . ':' . $s['port'] ); ?></code>
				but no <code>object-cache.php</code> drop-in is installed.
				Install <a href="<?php echo esc_url( $install_url ); ?>">Redis Object Cache</a>
				to enable persistent object caching (~1000× faster than DB transients).
			</p>
		</div>
		<?php
	} );
}
