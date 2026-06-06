<?php
/**
 * Plugin Name: Therum OS — Updates
 * Description: Local-aware update channel. Reads release-manifest.json from a
 *              local dist folder (default: ~/Therum OS/dist/), compares against
 *              the live install's THERUM_OS_VERSION, and applies bundle updates
 *              by extracting the mu-plugins set into wp-content/mu-plugins/.
 *              No network calls — purely local-filesystem driven.
 * Version: 1.9.0
 *
 * Override the dist path with:
 *   define( 'THERUM_DIST_PATH', '/abs/path/to/dist' );
 *   or: add_filter( 'therum_updates_dist_path', fn() => '/abs/path/to/dist' );
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class Therum_Updates {

	const PAGE_SLUG = 'therum-updates';

	public static function dist_path(): string {
		if ( defined( 'THERUM_DIST_PATH' ) ) {
			$p = (string) THERUM_DIST_PATH;
		} else {
			// Default: ~/Therum OS/dist/ — resolved from the OS user that owns wp-content.
			$home = getenv( 'HOME' );
			if ( ! $home ) {
				$uid = function_exists( 'posix_getpwuid' ) ? posix_getpwuid( fileowner( WP_CONTENT_DIR ) ) : null;
				$home = $uid['dir'] ?? '';
			}
			$p = rtrim( $home, '/' ) . '/Therum OS/dist';
		}
		return (string) apply_filters( 'therum_updates_dist_path', $p );
	}

	public static function manifest_path(): string {
		return self::dist_path() . '/release-manifest.json';
	}

	public static function read_manifest(): ?array {
		$path = self::manifest_path();
		if ( ! is_readable( $path ) ) return null;
		$raw = file_get_contents( $path );
		if ( ! $raw ) return null;
		$data = json_decode( $raw, true );
		return is_array( $data ) ? $data : null;
	}

	public static function current_version(): string {
		return defined( 'THERUM_OS_VERSION' ) ? (string) THERUM_OS_VERSION : '0.0.0';
	}

	/** semver-ish compare; -1, 0, 1 */
	public static function vcompare( string $a, string $b ): int {
		// Strip leading "v" + treat -Beta / -Alpha as lower-than-stable suffix.
		$norm = function( string $v ): array {
			$v = ltrim( $v, 'v' );
			$pre = '';
			if ( preg_match( '/^([\d.]+)[-+]?(.*)$/', $v, $m ) ) {
				$v   = $m[1];
				$pre = strtolower( $m[2] );
			}
			$parts = array_map( 'intval', explode( '.', $v ) );
			while ( count( $parts ) < 3 ) $parts[] = 0;
			// pre-release rank: stable > rc > beta > alpha > dev
			$rank = [ '' => 4, 'rc' => 3, 'beta' => 2, 'alpha' => 1, 'dev' => 0 ];
			$pr   = 4;
			foreach ( $rank as $tag => $r ) {
				if ( $tag !== '' && stripos( $pre, $tag ) !== false ) { $pr = $r; break; }
			}
			$parts[] = $pr;
			return $parts;
		};
		$pa = $norm( $a );
		$pb = $norm( $b );
		for ( $i = 0; $i < max( count( $pa ), count( $pb ) ); $i++ ) {
			$x = $pa[ $i ] ?? 0;
			$y = $pb[ $i ] ?? 0;
			if ( $x !== $y ) return $x <=> $y;
		}
		return 0;
	}

	public static function has_update( ?array $m = null ): bool {
		// GitHub channel takes precedence if reachable.
		$gh = self::github_release();
		if ( $gh && ! empty( $gh['version'] ) ) {
			return self::vcompare( $gh['version'], self::current_version() ) > 0;
		}
		// Fallback: local dist manifest (dev workflow)
		$m = $m ?: self::read_manifest();
		if ( ! $m ) return false;
		$latest = $m['latest_version'] ?? '';
		return $latest && self::vcompare( $latest, self::current_version() ) > 0;
	}

	// ════════════════════════════════════════════════════════════════════════
	//  GITHUB RELEASES CHANNEL — fetch latest from github.com/{owner}/{repo}
	// ════════════════════════════════════════════════════════════════════════

	/**
	 * Configured repo (owner/name). Default is the canonical Unlocked repo —
	 * this updater ships inside the Unlocked bundle, so it watches the matching
	 * release stream. Override via `define('THERUM_GITHUB_REPO', 'OwnerName/repo')`
	 * in wp-config.php to point at a fork, a private mirror, or a staging repo.
	 * Filterable for runtime swaps (e.g. multisite with per-blog repos).
	 */
	public static function github_repo(): string {
		$repo = defined( 'THERUM_GITHUB_REPO' ) ? (string) THERUM_GITHUB_REPO : 'TherumCs/Therum-Os-Unlocked';
		return (string) apply_filters( 'therum_updates_github_repo', $repo );
	}

	private const GH_TRANSIENT = 'therum_updates_gh_release';
	private const GH_TTL       = 6 * HOUR_IN_SECONDS;

	/**
	 * Latest published release from GitHub API. Cached 6h via transient.
	 * Returns null on failure (network down, rate-limited, no releases).
	 *
	 * Shape: [ 'version' => '1.9.0', 'tag' => 'v1.9.0', 'name' => '...',
	 *          'body' => '...', 'published_at' => '...', 'html_url' => '...',
	 *          'zip_url' => '...' (first .zip asset), 'asset_name' => '...' ]
	 *
	 * @param bool $force_refresh  Skip the transient cache
	 * @return array<string, mixed>|null
	 */
	public static function github_release( bool $force_refresh = false ): ?array {
		if ( ! $force_refresh ) {
			$cached = get_transient( self::GH_TRANSIENT );
			if ( is_array( $cached ) ) return $cached;
			if ( $cached === 'none' ) return null; // negative cache
		}

		$repo = self::github_repo();
		$url  = 'https://api.github.com/repos/' . $repo . '/releases/latest';
		$res  = wp_remote_get( $url, [
			'timeout' => 12,
			'headers' => [
				'Accept'     => 'application/vnd.github+json',
				'User-Agent' => 'Therum-OS-Updater/' . self::current_version(),
			],
		] );

		if ( is_wp_error( $res ) || (int) wp_remote_retrieve_response_code( $res ) !== 200 ) {
			// Surface the common, easily-missed cause: WP_HTTP_BLOCK_EXTERNAL is
			// on and GitHub isn't whitelisted, so the request never leaves the box.
			if ( is_wp_error( $res ) && $res->get_error_code() === 'http_request_not_executed' ) {
				error_log(
					'[therum-updates] GitHub request blocked by WP_HTTP_BLOCK_EXTERNAL. '
					. 'Add these to WP_ACCESSIBLE_HOSTS in wp-config.php: '
					. 'github.com,api.github.com,codeload.github.com,objects.githubusercontent.com'
				);
			}
			set_transient( self::GH_TRANSIENT, 'none', 15 * MINUTE_IN_SECONDS );
			return null;
		}

		$body = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( ! is_array( $body ) || empty( $body['tag_name'] ) ) {
			set_transient( self::GH_TRANSIENT, 'none', 15 * MINUTE_IN_SECONDS );
			return null;
		}

		$tag     = (string) $body['tag_name'];
		$version = ltrim( $tag, 'vV' );
		$zip_url = '';
		$asset_n = '';
		foreach ( (array) ( $body['assets'] ?? [] ) as $a ) {
			$name = (string) ( $a['name'] ?? '' );
			if ( substr( $name, -4 ) === '.zip' ) {
				$zip_url = (string) ( $a['browser_download_url'] ?? '' );
				$asset_n = $name;
				break;
			}
		}
		// Fall back to source zip if no asset uploaded.
		if ( ! $zip_url ) {
			$zip_url = (string) ( $body['zipball_url'] ?? '' );
			$asset_n = $tag . '.zip (source)';
		}

		$shape = [
			'version'      => $version,
			'tag'          => $tag,
			'name'         => (string) ( $body['name']         ?? $tag ),
			'body'         => (string) ( $body['body']         ?? '' ),
			'published_at' => (string) ( $body['published_at'] ?? '' ),
			'html_url'     => (string) ( $body['html_url']     ?? '' ),
			'zip_url'      => $zip_url,
			'asset_name'   => $asset_n,
		];
		set_transient( self::GH_TRANSIENT, $shape, self::GH_TTL );
		return $shape;
	}

	/**
	 * Download + extract the GitHub release zip over the live mu-plugins.
	 * Backs up the existing mu-plugins/ dir to mu-plugins.bak.{timestamp}/
	 * before replacing. Throws on failure.
	 *
	 * @return array<string, mixed>  { from, to, applied_version, backup_path }
	 */
	public static function apply_github_update(): array {
		$gh = self::github_release( true );
		if ( ! $gh || empty( $gh['zip_url'] ) ) {
			throw new \RuntimeException( 'No GitHub release zip found.' );
		}

		// Fail fast with an actionable message if the swap can't possibly succeed.
		$blocker = self::apply_blocker();
		if ( $blocker !== null ) throw new \RuntimeException( $blocker );

		// 1. Download to wp temp dir
		require_once ABSPATH . 'wp-admin/includes/file.php';
		$tmp = download_url( $gh['zip_url'], 30 );
		if ( is_wp_error( $tmp ) ) {
			throw new \RuntimeException( 'Download failed: ' . $tmp->get_error_message() );
		}

		// 2. Backup existing mu-plugins/
		$mu_dir   = WP_CONTENT_DIR . '/mu-plugins';
		$bak_path = WP_CONTENT_DIR . '/mu-plugins.bak.' . gmdate( 'Ymd-His' );
		// Use rename for atomic backup (same filesystem)
		if ( is_dir( $mu_dir ) ) {
			if ( ! @rename( $mu_dir, $bak_path ) ) {
				@unlink( $tmp );
				throw new \RuntimeException( 'Could not back up mu-plugins/ — check perms.' );
			}
			@mkdir( $mu_dir, 0755, true );
		}

		// 3. Extract zip into mu-plugins/
		WP_Filesystem();
		$result = unzip_file( $tmp, $mu_dir );
		@unlink( $tmp );
		if ( is_wp_error( $result ) ) {
			// Restore from backup on extraction failure
			if ( is_dir( $bak_path ) ) {
				@rename( $mu_dir, $mu_dir . '.failed.' . gmdate( 'Ymd-His' ) );
				@rename( $bak_path, $mu_dir );
			}
			throw new \RuntimeException( 'Extract failed: ' . $result->get_error_message() );
		}

		// 4. If the zip wrapped contents in a top-level dir (e.g. Therum-Os-main/),
		//    flatten one level so files end up directly under mu-plugins/.
		self::flatten_single_dir( $mu_dir );

		// Invalidate cache so the next request sees the new version
		delete_transient( self::GH_TRANSIENT );

		return [
			'from'            => self::current_version(),
			'to'              => $gh['version'],
			'applied_version' => $gh['version'],
			'backup_path'     => $bak_path,
		];
	}

	// ─── Filesystem preflight + staging helpers ───────────────────────────

	/** The OS user PHP runs as — used in permission diagnostics. */
	private static function web_user(): string {
		if ( function_exists( 'posix_geteuid' ) && function_exists( 'posix_getpwuid' ) ) {
			$info = posix_getpwuid( posix_geteuid() );
			if ( is_array( $info ) && ! empty( $info['name'] ) ) return (string) $info['name'];
		}
		$u = get_current_user();
		return $u !== '' ? $u : 'the web server user';
	}

	/**
	 * A directory the web user can actually write to for staging uploads/extracts.
	 * Prefers wp-content/upgrade (WP convention); on hardened hosts where the web
	 * user can't write inside wp-content, falls back to the system temp dir.
	 */
	private static function staging_dir(): string {
		$upgrade = WP_CONTENT_DIR . '/upgrade';
		if ( ! is_dir( $upgrade ) ) @wp_mkdir_p( $upgrade );
		if ( is_dir( $upgrade ) && is_writable( $upgrade ) ) return $upgrade;
		return rtrim( get_temp_dir(), '/\\' );
	}

	/**
	 * Preflight for in-place updates. Applying ANY update backs up + rewrites
	 * mu-plugins/, which requires the web user to have write access to both
	 * mu-plugins/ and wp-content/. Returns a human-readable blocker string, or
	 * null if writes will succeed. This turns the old opaque "Could not back up
	 * mu-plugins" failure into an actionable message.
	 */
	private static function apply_blocker(): ?string {
		$mu       = defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins';
		$problems = [];
		if ( is_dir( $mu ) && ! is_writable( $mu ) ) $problems[] = 'mu-plugins/';
		if ( ! is_writable( WP_CONTENT_DIR ) )       $problems[] = 'wp-content/';
		if ( empty( $problems ) ) return null;
		return sprintf(
			'The web server user (%s) can\'t write to %s, so in-browser updates can\'t be applied. '
			. 'This is expected on a hardened install with read-only code — update via SSH/deploy instead, '
			. 'or grant the web user write access to those paths.',
			self::web_user(),
			implode( ' and ', $problems )
		);
	}

	// ─── Drop-in ZIP channel ──────────────────────────────────────────────
	//
	// Manual upload path. The admin picks a .zip from disk, we extract it
	// into a writable staging dir, find the mu-plugins source inside (handles
	// patch-shape, full-bundle-shape, and GitHub-zipball-wrap), then run
	// the same backup-and-swap as the GitHub + local channels.
	// ─────────────────────────────────────────────────────────────────────

	/**
	 * Apply a ZIP that's already been moved into a local staging path.
	 * Throws on any failure; caller deletes $zip_path afterward.
	 */
	public static function apply_uploaded_zip( string $zip_path ): array {
		if ( ! class_exists( '\ZipArchive' ) ) throw new \RuntimeException( 'ZipArchive unavailable.' );
		if ( ! is_readable( $zip_path ) )     throw new \RuntimeException( 'Uploaded zip not readable.' );

		// Fail fast with an actionable message if the swap can't possibly succeed.
		$blocker = self::apply_blocker();
		if ( $blocker !== null ) throw new \RuntimeException( $blocker );

		// 1. Stage extraction (in a dir the web user can actually write to)
		$tmp = self::staging_dir() . '/therum-upload-' . substr( wp_generate_password( 8, false ), 0, 8 );
		if ( ! wp_mkdir_p( $tmp ) ) throw new \RuntimeException( 'Cannot create temp dir at ' . $tmp );

		$zip   = new \ZipArchive();
		$open  = $zip->open( $zip_path );
		if ( $open !== true ) {
			self::rmrf( $tmp );
			throw new \RuntimeException( 'Could not open zip (ZipArchive code ' . (int) $open . ').' );
		}
		if ( $zip->extractTo( $tmp ) !== true ) {
			$zip->close();
			self::rmrf( $tmp );
			throw new \RuntimeException( 'Zip extract failed.' );
		}
		$zip->close();

		// 2. Find the mu-plugins source inside. Accepts:
		//   - patch shape:        therum-admin.php at zip root
		//   - full-bundle shape:  wp-content/mu-plugins/therum-admin.php
		//   - GitHub source zip:  one wrapper dir around either of the above
		$src = self::find_mu_plugins_source( $tmp );
		if ( ! $src ) {
			self::rmrf( $tmp );
			throw new \RuntimeException( 'Uploaded zip does not look like a Therum OS bundle — no therum-admin.php found within 4 levels.' );
		}

		// 3. Backup live mu-plugins → mu-plugins.bak.{ts}/
		$mu_dir = WPMU_PLUGIN_DIR;
		$backup = $mu_dir . '.bak.' . gmdate( 'Ymd-His' );
		if ( is_dir( $mu_dir ) ) {
			// Copy (don't move) — moving the running mu-plugin dir would break
			// the request mid-flight.
			self::copy_tree( $mu_dir, $backup );
		}

		// 4. Copy the new mu-plugins on top of the live dir (additive overwrite)
		self::copy_tree( $src, $mu_dir );

		// 5. Cleanup + cache-bust + audit
		self::rmrf( $tmp );
		if ( class_exists( 'Therum_Cache_Bust' ) && method_exists( 'Therum_Cache_Bust', 'purge_all' ) ) {
			try { \Therum_Cache_Bust::purge_all(); } catch ( \Throwable $e ) {}
		}
		delete_transient( self::GH_TRANSIENT );

		do_action( 'therum_audit_log', [
			'event'  => 'therum.update.applied',
			'actor'  => wp_get_current_user()->user_login ?? 'system',
			'bundle' => 'upload',
			'from'   => self::current_version(),
		] );

		return [
			'summary' => 'Uploaded ZIP applied. Reload any admin tab to see new code.',
			'backup'  => $backup,
		];
	}

	/**
	 * Walk a freshly-extracted directory looking for the mu-plugins root.
	 * Returns the directory containing therum-admin.php, or null if none.
	 * Searches up to 4 levels deep to cover GitHub repo-branch/ wraps.
	 */
	private static function find_mu_plugins_source( string $dir, int $depth = 0 ): ?string {
		if ( $depth > 4 ) return null;
		if ( is_file( $dir . '/therum-admin.php' ) ) return $dir;
		// Full-bundle shape: wp-content/mu-plugins/ at this level
		$candidate = $dir . '/wp-content/mu-plugins';
		if ( is_dir( $candidate ) && is_file( $candidate . '/therum-admin.php' ) ) {
			return $candidate;
		}
		// Recurse into subdirectories (handles GitHub source ZIP's repo-branch/ wrap)
		foreach ( array_diff( scandir( $dir ) ?: [], [ '.', '..' ] ) as $entry ) {
			$sub = $dir . '/' . $entry;
			if ( ! is_dir( $sub ) ) continue;
			$found = self::find_mu_plugins_source( $sub, $depth + 1 );
			if ( $found ) return $found;
		}
		return null;
	}

	/**
	 * If a freshly-extracted directory contains exactly one top-level dir
	 * and no other files, move its contents up one level. Handles the case
	 * where GitHub zipball wraps everything in {repo}-{branch}/.
	 */
	private static function flatten_single_dir( string $dir ): void {
		$items = array_diff( scandir( $dir ) ?: [], [ '.', '..' ] );
		if ( count( $items ) !== 1 ) return;
		$only = $dir . '/' . reset( $items );
		if ( ! is_dir( $only ) ) return;
		$inner = array_diff( scandir( $only ) ?: [], [ '.', '..' ] );
		foreach ( $inner as $f ) {
			@rename( $only . '/' . $f, $dir . '/' . $f );
		}
		@rmdir( $only );
	}


	// ── Admin page ────────────────────────────────────────────────────────────

	public static function register_page(): void {
		add_submenu_page(
			'', 'Therum Updates', 'Therum Updates', 'manage_options',
			self::PAGE_SLUG, [ self::class, 'render_page' ]
		);
	}

	public static function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) return;

		// Handle apply POST (local dist channel)
		$applied  = null;
		$apply_err = null;
		if ( isset( $_POST['therum_apply'] ) && check_admin_referer( 'therum_updates_apply' ) ) {
			$bundle = sanitize_key( $_POST['bundle'] ?? 'pure' );
			try {
				$applied = self::apply_update( $bundle );
			} catch ( \Throwable $e ) {
				$apply_err = $e->getMessage();
			}
		}

		// Handle apply POST (GitHub channel)
		$gh_applied = null;
		$gh_err     = null;
		if ( isset( $_POST['therum_gh_apply'] ) && check_admin_referer( 'therum_updates_gh_apply' ) ) {
			try {
				$gh_applied = self::apply_github_update();
			} catch ( \Throwable $e ) {
				$gh_err = $e->getMessage();
			}
		}
		// Handle force-refresh of GitHub cache
		if ( isset( $_POST['therum_gh_refresh'] ) && check_admin_referer( 'therum_updates_gh_refresh' ) ) {
			delete_transient( self::GH_TRANSIENT );
			self::github_release( true );
		}

		// Handle apply POST (uploaded ZIP — drop-in channel)
		$up_applied = null;
		$up_err     = null;
		if ( isset( $_POST['therum_upload_apply'] ) && check_admin_referer( 'therum_updates_upload' ) ) {
			try {
				if ( empty( $_FILES['therum_zip'] ) || ! is_array( $_FILES['therum_zip'] ) ) {
					throw new \RuntimeException( 'No file uploaded.' );
				}
				$file = $_FILES['therum_zip'];
				if ( ! empty( $file['error'] ) && (int) $file['error'] !== UPLOAD_ERR_OK ) {
					throw new \RuntimeException( 'Upload error code ' . (int) $file['error'] . '.' );
				}
				if ( $file['size'] < 100 ) {
					throw new \RuntimeException( 'Uploaded file is too small to be a Therum ZIP.' );
				}
				if ( $file['size'] > 100 * 1024 * 1024 ) {
					throw new \RuntimeException( 'Uploaded file exceeds the 100 MB cap.' );
				}
				$name = sanitize_file_name( (string) ( $file['name'] ?? '' ) );
				if ( ! preg_match( '/\.zip$/i', $name ) ) {
					throw new \RuntimeException( 'Expected a .zip file.' );
				}
				// Don't trust the extension alone — verify the file actually is a
				// ZIP by its magic bytes. PK\x03\x04 is a normal archive; \x05\x06
				// (empty) and \x07\x08 (spanned) are the other valid signatures.
				$magic = (string) @file_get_contents( $file['tmp_name'], false, null, 0, 4 );
				$zip_sigs = [ "PK\x03\x04", "PK\x05\x06", "PK\x07\x08" ];
				if ( ! in_array( $magic, $zip_sigs, true ) ) {
					throw new \RuntimeException( 'That file is not a valid ZIP archive (failed signature check).' );
				}
				// Move out of PHP's upload tmpdir (auto-cleaned mid-request) into a
				// dir the web user can actually write to (wp-content/upgrade if
				// writable, else the system temp dir on hardened hosts).
				$dest_dir = self::staging_dir();
				$dest = $dest_dir . '/therum-uploaded-' . substr( wp_generate_password( 8, false ), 0, 8 ) . '.zip';
				if ( ! move_uploaded_file( $file['tmp_name'], $dest ) ) {
					throw new \RuntimeException( 'Could not move uploaded file into ' . $dest_dir . ' — directory not writable by ' . self::web_user() . '.' );
				}
				$up_applied = self::apply_uploaded_zip( $dest );
				@unlink( $dest );
			} catch ( \Throwable $e ) {
				$up_err = $e->getMessage();
			}
		}

		$current  = self::current_version();
		$manifest = self::read_manifest();
		$dist     = self::dist_path();
		$gh       = self::github_release();
		$gh_repo  = self::github_repo();
		?>
		<div class="th-settings" style="display:block;padding:0;">
		  <main class="th-settings-content" style="border-left:0;">
			<header class="th-settings-header">
			  <div class="th-settings-header-left">
				<div class="th-settings-meta"><span class="th-settings-meta-dot"></span>
				  <?php echo esc_html( 'UPDATES · ' . ( $gh ? 'GITHUB CHANNEL' : ( $manifest ? 'LOCAL CHANNEL' : 'NO SOURCE' ) ) ); ?>
				</div>
				<h1 class="th-settings-title">Therum OS Updates</h1>
				<p class="th-settings-sub">
				  Repo: <code><?php echo esc_html( $gh_repo ); ?></code>
				  <?php if ( $manifest ): ?>· Local: <code><?php echo esc_html( self::manifest_path() ); ?></code><?php endif; ?>
				</p>
			  </div>
			</header>

			<!-- ── GITHUB CHANNEL PANEL ─────────────────────────────────────── -->
			<?php if ( $gh_applied ): ?>
			  <div class="th-settings-group" style="border-color:#1a7f37;">
				<div class="th-settings-group-body" style="padding:20px;">
				  <strong style="color:#1a7f37;">✓ Applied <?php echo esc_html( $gh_applied['from'] ); ?> → <?php echo esc_html( $gh_applied['to'] ); ?> from GitHub.</strong>
				  <div style="font-size:12px;color:var(--tx2);margin-top:6px;">Backup at <code><?php echo esc_html( $gh_applied['backup_path'] ); ?></code>. Reload the page to see the new version.</div>
				</div>
			  </div>
			<?php elseif ( $gh_err ): ?>
			  <div class="th-settings-group" style="border-color:#cf222e;">
				<div class="th-settings-group-body" style="padding:20px;color:#cf222e;">Apply failed: <?php echo esc_html( $gh_err ); ?></div>
			  </div>
			<?php endif; ?>

			<div class="th-settings-group" style="margin-bottom:20px;">
			  <div class="th-settings-group-header" style="padding:14px 20px;">
				<div>
				  <div class="th-settings-group-title">GitHub channel</div>
				  <div class="th-settings-group-sub"><?php echo esc_html( $gh_repo ); ?> · cached 6h</div>
				</div>
				<form method="post" style="display:inline-block;margin-left:auto;">
				  <?php wp_nonce_field( 'therum_updates_gh_refresh' ); ?>
				  <button type="submit" name="therum_gh_refresh" class="th-button" style="padding:6px 12px;font-size:12px;">Refresh</button>
				</form>
			  </div>
			  <div class="th-settings-group-body" style="padding:20px;">
				<?php if ( ! $gh ): ?>
				  <p style="margin:0;color:var(--tx2);">No release found at <code><?php echo esc_html( $gh_repo ); ?></code>, or rate-limited.
				  Check that the repo exists and has at least one release published.</p>
				<?php else:
				  $is_newer = self::vcompare( $gh['version'], $current ) > 0;
				  ?>
				  <div style="display:flex;align-items:baseline;gap:16px;flex-wrap:wrap;margin-bottom:10px;">
					<div>
					  <div style="font-size:11px;color:var(--tx3);letter-spacing:.08em;text-transform:uppercase;">Latest release</div>
					  <div style="font-size:20px;font-weight:600;color:var(--tx);"><?php echo esc_html( $gh['name'] ); ?></div>
					</div>
					<div>
					  <div style="font-size:11px;color:var(--tx3);letter-spacing:.08em;text-transform:uppercase;">Installed</div>
					  <div style="font-size:14px;color:var(--tx);"><?php echo esc_html( $current ); ?></div>
					</div>
					<?php if ( $gh['published_at'] ): ?>
					<div>
					  <div style="font-size:11px;color:var(--tx3);letter-spacing:.08em;text-transform:uppercase;">Published</div>
					  <div style="font-size:13px;color:var(--tx);"><?php echo esc_html( substr( $gh['published_at'], 0, 10 ) ); ?></div>
					</div>
					<?php endif; ?>
					<div style="margin-left:auto;display:flex;gap:8px;align-items:center;">
					  <a href="<?php echo esc_url( $gh['html_url'] ); ?>" target="_blank" rel="noopener" class="th-button" style="padding:7px 14px;font-size:12px;text-decoration:none;">View on GitHub ↗</a>
					  <?php if ( $is_newer ): ?>
					  <form method="post" style="display:inline-block;" onsubmit="return confirm('Replace mu-plugins/ with <?php echo esc_attr( $gh['version'] ); ?> from GitHub? Current mu-plugins will be backed up.');">
						<?php wp_nonce_field( 'therum_updates_gh_apply' ); ?>
						<button type="submit" name="therum_gh_apply" class="th-button th-button-primary" style="padding:7px 14px;font-size:12px;">Apply <?php echo esc_html( $gh['version'] ); ?> →</button>
					  </form>
					  <?php else: ?>
					  <span style="font-size:12px;color:var(--tx3);">Up to date</span>
					  <?php endif; ?>
					</div>
				  </div>
				  <?php if ( $gh['body'] ): ?>
					<details style="margin-top:14px;">
					  <summary style="cursor:pointer;font-size:12px;color:var(--tx2);">Release notes</summary>
					  <div style="margin-top:10px;padding:14px;background:var(--sf2);border-radius:6px;font-size:13px;line-height:1.6;color:var(--tx);white-space:pre-wrap;"><?php echo esc_html( $gh['body'] ); ?></div>
					</details>
				  <?php endif; ?>
				  <?php if ( $gh['asset_name'] ): ?>
					<div style="margin-top:10px;font-size:11px;color:var(--tx3);">Asset: <code><?php echo esc_html( $gh['asset_name'] ); ?></code></div>
				  <?php endif; ?>
				<?php endif; ?>
			  </div>
			</div>

			<!-- ── UPLOAD ZIP PANEL ─────────────────────────────────────────── -->
			<?php if ( $up_applied ): ?>
			  <div class="th-settings-group" style="border-color:#1a7f37;">
				<div class="th-settings-group-body" style="padding:20px;">
				  <strong style="color:#1a7f37;">✓ <?php echo esc_html( $up_applied['summary'] ); ?></strong>
				  <div style="font-size:12px;color:var(--tx2);margin-top:6px;">Backup at <code><?php echo esc_html( $up_applied['backup'] ); ?></code>.</div>
				</div>
			  </div>
			<?php elseif ( $up_err ): ?>
			  <div class="th-settings-group" style="border-color:#cf222e;">
				<div class="th-settings-group-body" style="padding:20px;color:#cf222e;">Upload failed: <?php echo esc_html( $up_err ); ?></div>
			  </div>
			<?php endif; ?>

			<div class="th-settings-group" style="margin-bottom:20px;">
			  <div class="th-settings-group-header" style="padding:14px 20px;">
				<div>
				  <div class="th-settings-group-title">Drop a ZIP</div>
				  <div class="th-settings-group-sub">Apply a patch ZIP from disk — useful for offline installs, staging copies, and fork builds. Same backup-and-swap flow as the GitHub channel.</div>
				</div>
			  </div>
			  <div class="th-settings-group-body" style="padding:20px;">
				<form method="post" enctype="multipart/form-data" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
				  <?php wp_nonce_field( 'therum_updates_upload' ); ?>
				  <input type="file" name="therum_zip" accept=".zip,application/zip" required style="flex:1;min-width:280px;padding:10px 12px;border:1px dashed var(--bd);border-radius:8px;background:var(--sf2);font:13px/1 inherit;color:var(--tx);">
				  <button type="submit" name="therum_upload_apply" class="th-button th-button-primary" onclick="return confirm('Apply this ZIP? The live mu-plugins will be backed up first.')">Apply ZIP</button>
				</form>
				<p style="margin:14px 0 0;font-size:12px;color:var(--tx3);line-height:1.6">
				  Accepts any of: a patch ZIP with <code>therum-admin.php</code> at root, a full bundle ZIP with <code>wp-content/mu-plugins/</code>, or a GitHub source ZIP (single-dir wrap auto-detected). Max 100 MB. <code>wp-content/mu-plugins/</code> is backed up to <code>mu-plugins.bak.{timestamp}/</code> before the swap — restore by renaming the backup back if anything misbehaves.
				</p>
			  </div>
			</div>

			<?php if ( $applied ): ?>
			  <div class="th-settings-group" style="border-color:#1a7f37;">
				<div class="th-settings-group-body" style="padding:20px;">
				  <strong>Update applied.</strong> <?php echo esc_html( $applied['summary'] ); ?>
				  <br><small style="color:var(--tx2);">Backup: <code><?php echo esc_html( $applied['backup'] ); ?></code></small>
				</div>
			  </div>
			<?php endif; ?>

			<?php if ( $apply_err ): ?>
			  <div class="th-settings-group" style="border-color:#cf222e;">
				<div class="th-settings-group-body" style="padding:20px;">
				  <strong>Update failed.</strong> <?php echo esc_html( $apply_err ); ?>
				</div>
			  </div>
			<?php endif; ?>

			<?php if ( ! $manifest ): ?>
			  <div class="th-settings-group">
				<div class="th-settings-group-body" style="padding:24px;">
				  <p style="margin:0 0 8px;color:var(--tx);font-weight:600;">No release manifest found.</p>
				  <p style="margin:0;color:var(--tx2);font-size:13px;">
					Expected at <code><?php echo esc_html( self::manifest_path() ); ?></code>.
					Run <code>./build.sh all</code> in <code><?php echo esc_html( $dist ); ?>/..</code> to generate one.
				  </p>
				</div>
			  </div>
			<?php else:
				$latest    = $manifest['latest_version'] ?? '—';
				$built_at  = $manifest['built_at'] ?? '';
				$is_newer  = self::has_update( $manifest );
				$is_same   = self::vcompare( $latest, $current ) === 0;
				$bundles   = $manifest['bundles'] ?? [];
				$excerpt   = $manifest['changelog_excerpt'] ?? '';
			?>
			  <div class="th-settings-group">
				<div class="th-settings-group-body" style="padding:24px;display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:24px;">
				  <div>
					<div style="font-size:11px;letter-spacing:.08em;color:var(--tx2);">INSTALLED</div>
					<div style="font-size:28px;font-weight:700;color:var(--tx);margin-top:4px;"><?php echo esc_html( $current ); ?></div>
				  </div>
				  <div>
					<div style="font-size:11px;letter-spacing:.08em;color:<?php echo $is_newer ? '#1a7f37' : 'var(--tx2)'; ?>;">
					  <?php echo $is_newer ? 'UPDATE AVAILABLE' : ( $is_same ? 'UP TO DATE' : 'AHEAD OF MANIFEST' ); ?>
					</div>
					<div style="font-size:28px;font-weight:700;color:<?php echo $is_newer ? '#1a7f37' : 'var(--tx)'; ?>;margin-top:4px;"><?php echo esc_html( $latest ); ?></div>
					<?php if ( $built_at ): ?>
					  <div style="font-size:12px;color:var(--tx2);margin-top:6px;">Built <?php echo esc_html( $built_at ); ?></div>
					<?php endif; ?>
				  </div>
				</div>
			  </div>

			  <?php if ( $is_newer && ! empty( $bundles ) ): ?>
				<div class="th-settings-group">
				  <div class="th-settings-group-body" style="padding:20px 24px;">
					<h3 style="margin:0 0 12px;font-size:14px;font-weight:600;">Apply update</h3>
					<p style="margin:0 0 16px;color:var(--tx2);font-size:13px;">
					  Copies the new mu-plugin set from the selected bundle into <code>wp-content/mu-plugins/</code>.
					  The existing set is moved to a timestamped backup beside it. No core, plugin, or theme files are touched.
					</p>
					<?php foreach ( [ 'patch', 'pure', 'pro' ] as $bid ):
						if ( empty( $bundles[ $bid ]['zip'] ) ) continue;
						$b = $bundles[ $bid ];
						$zip_path = self::dist_path() . '/' . $b['zip'];
						$present  = is_readable( $zip_path );
						$kb = isset( $b['size'] ) ? round( $b['size'] / 1024 / 1024, 1 ) . ' MB' : '—';
					?>
					  <form method="post" style="display:flex;align-items:center;gap:16px;padding:14px 0;border-top:1px solid var(--bd);">
						<?php wp_nonce_field( 'therum_updates_apply' ); ?>
						<input type="hidden" name="bundle" value="<?php echo esc_attr( $bid ); ?>">
						<div style="flex:1;">
						  <div style="font-weight:600;color:var(--tx);"><?php echo esc_html( ucfirst( $bid ) ); ?> bundle <span style="color:var(--tx2);font-weight:400;">· v<?php echo esc_html( $b['version'] ?? $latest ); ?> · <?php echo esc_html( $kb ); ?></span></div>
						  <div style="font-size:12px;color:var(--tx2);font-family:ui-monospace,Menlo,monospace;margin-top:2px;"><?php echo esc_html( $b['zip'] ); ?></div>
						</div>
						<button type="submit" name="therum_apply" value="1" class="button button-primary" <?php echo $present ? '' : 'disabled'; ?>>
						  <?php echo $present ? 'Apply' : 'Missing zip'; ?>
						</button>
					  </form>
					<?php endforeach; ?>
				  </div>
				</div>
			  <?php endif; ?>

			  <?php
				$history = self::version_history_entries();
				if ( ! empty( $history ) ):
			  ?>
				<div class="th-settings-group">
				  <div class="th-settings-group-body" style="padding:20px 24px;">
					<h3 style="margin:0 0 6px;font-size:14px;font-weight:600;">Version history</h3>
					<p style="margin:0 0 14px;color:var(--tx2);font-size:13px;">
					  Re-apply the current build, reinstall the last release, or roll back to any prior version. Backup-then-replace on every action.
					</p>
					<table class="tbl" style="width:100%;border-collapse:collapse;font-size:13px;">
					  <thead>
						<tr style="text-align:left;color:var(--tx3);font-size:11px;text-transform:uppercase;letter-spacing:.04em;">
						  <th style="padding:8px 0;font-weight:600;">Version</th>
						  <th style="padding:8px 0;font-weight:600;">Released</th>
						  <th style="padding:8px 0;font-weight:600;">Status</th>
						  <th style="padding:8px 0;font-weight:600;text-align:right;">Actions</th>
						</tr>
					  </thead>
					  <tbody>
						<?php foreach ( $history as $h ):
							$is_current = self::vcompare( $h['version'], $current ) === 0;
							$is_newer   = self::vcompare( $h['version'], $current ) > 0;
						?>
						<tr style="border-top:1px solid var(--bd);">
						  <td style="padding:10px 0;font-family:ui-monospace,Menlo,monospace;color:var(--tx);font-weight:600;"><?php echo esc_html( $h['version'] ); ?></td>
						  <td style="padding:10px 0;color:var(--tx2);font-family:ui-monospace,Menlo,monospace;font-size:12px;"><?php echo esc_html( $h['date'] ); ?></td>
						  <td style="padding:10px 0;">
							<?php if ( $is_current ): ?>
							  <span style="display:inline-flex;align-items:center;gap:6px;font-size:11px;color:#1a7f37;font-weight:600;"><span style="width:6px;height:6px;border-radius:50%;background:#1a7f37;"></span>Current</span>
							<?php elseif ( $is_newer ): ?>
							  <span style="font-size:11px;color:var(--tx2);">Newer</span>
							<?php else: ?>
							  <span style="font-size:11px;color:var(--tx3);">Released</span>
							<?php endif; ?>
						  </td>
						  <td style="padding:10px 0;text-align:right;">
							<?php if ( $is_current ): ?>
							  <button class="button" type="button" disabled style="opacity:.6;">Re-apply</button>
							<?php elseif ( $is_newer ): ?>
							  <button class="button button-primary" type="button">Install</button>
							<?php else: ?>
							  <button class="button" type="button">Downgrade</button>
							<?php endif; ?>
						  </td>
						</tr>
						<?php endforeach; ?>
					  </tbody>
					</table>
					<p style="margin:14px 0 0;font-size:11px;color:var(--tx3);">
					  Actions perform: backup current → fetch zip → extract mu-plugins → cache-bust + audit log. Currently surface-only until bundle storage is wired (Phase 5).
					</p>
				  </div>
				</div>
			  <?php endif; ?>

			  <?php
				$full_changelog = self::full_changelog_text();
				if ( $full_changelog ):
			  ?>
				<div class="th-settings-group">
				  <div class="th-settings-group-body" style="padding:24px;">
					<h3 style="margin:0 0 12px;font-size:14px;font-weight:600;">Full changelog</h3>
					<pre style="margin:0;white-space:pre-wrap;font-family:ui-monospace,Menlo,monospace;font-size:12px;color:var(--tx2);line-height:1.6;max-height:560px;overflow:auto;border:1px solid var(--bd);border-radius:8px;padding:14px;"><?php echo esc_html( $full_changelog ); ?></pre>
				  </div>
				</div>
			  <?php elseif ( $excerpt ): ?>
				<div class="th-settings-group">
				  <div class="th-settings-group-body" style="padding:24px;">
					<h3 style="margin:0 0 12px;font-size:14px;font-weight:600;">Changelog excerpt</h3>
					<pre style="margin:0;white-space:pre-wrap;font-family:ui-monospace,Menlo,monospace;font-size:12px;color:var(--tx2);line-height:1.6;max-height:480px;overflow:auto;"><?php echo esc_html( $excerpt ); ?></pre>
				  </div>
				</div>
			  <?php endif; ?>
			<?php endif; ?>
		  </main>
		</div>
		<?php
	}

	/**
	 * Parses the CHANGELOG.md header lines into a list of {version, date}.
	 * Looks for `## [version] — date` headers. Returns versions in the order
	 * they appear (newest first by convention).
	 */
	public static function version_history_entries(): array {
		$src = self::full_changelog_text();
		if ( ! $src ) return [];
		$entries = [];
		if ( preg_match_all( '/^##\s*\[([^\]]+)\][^\n]*?—\s*(.+)$/m', $src, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $row ) {
				$entries[] = [
					'version' => trim( $row[1] ),
					'date'    => trim( $row[2] ),
				];
			}
		}
		return $entries;
	}

	/**
	 * Reads the full CHANGELOG.md from the Therum OS source tree (one level
	 * above the dist path). Returns empty string if not present.
	 */
	public static function full_changelog_text(): string {
		$candidates = [
			dirname( self::dist_path() ) . '/CHANGELOG.md',
			self::dist_path() . '/CHANGELOG.md',
		];
		foreach ( $candidates as $p ) {
			if ( is_readable( $p ) ) return (string) file_get_contents( $p );
		}
		return '';
	}

	// ── Apply flow ────────────────────────────────────────────────────────────

	/**
	 * Extracts the chosen bundle and rsyncs wp-content/mu-plugins/ from it
	 * into the live install. Returns ['summary'=>..., 'backup'=>...].
	 * Throws on failure.
	 */
	public static function apply_update( string $bundle ): array {
		$manifest = self::read_manifest();
		if ( ! $manifest ) throw new \RuntimeException( 'No manifest.' );
		$info = $manifest['bundles'][ $bundle ] ?? null;
		if ( ! $info || empty( $info['zip'] ) ) throw new \RuntimeException( 'Bundle not in manifest.' );

		$zip_path = self::dist_path() . '/' . $info['zip'];
		if ( ! is_readable( $zip_path ) ) throw new \RuntimeException( 'Zip not readable: ' . $zip_path );

		if ( ! class_exists( '\ZipArchive' ) ) throw new \RuntimeException( 'ZipArchive unavailable.' );

		// Stage extraction under wp-content/upgrade/therum-{bundle}-{rand}
		$tmp = WP_CONTENT_DIR . '/upgrade/therum-' . $bundle . '-' . substr( wp_generate_password( 8, false ), 0, 8 );
		if ( ! wp_mkdir_p( $tmp ) ) throw new \RuntimeException( 'Cannot create temp dir.' );

		$zip = new \ZipArchive();
		if ( $zip->open( $zip_path ) !== true ) throw new \RuntimeException( 'Zip open failed.' );

		// Extract only wp-content/mu-plugins/ — the bundle puts WP core at root,
		// so the mu-plugin set lives at wp-content/mu-plugins/* inside the zip.
		$prefix = 'wp-content/mu-plugins/';
		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$name = $zip->getNameIndex( $i );
			if ( strpos( $name, $prefix ) !== 0 ) continue;
			$zip->extractTo( $tmp, [ $name ] );
		}
		$zip->close();

		$src = $tmp . '/' . $prefix;
		if ( ! is_dir( $src ) ) {
			self::rmrf( $tmp );
			throw new \RuntimeException( 'Bundle has no mu-plugins/ directory.' );
		}

		// Backup current mu-plugins → mu-plugins.bak.{ts}/
		$mu_dir   = WPMU_PLUGIN_DIR;
		$backup   = $mu_dir . '.bak.' . gmdate( 'Ymd-His' );
		if ( is_dir( $mu_dir ) ) {
			// Don't move the dir (would break running request). Copy instead.
			self::copy_tree( $mu_dir, $backup );
		}

		// Apply: copy bundle's mu-plugins on top of live dir.
		// Existing files are overwritten; nothing is deleted (additive).
		self::copy_tree( $src, $mu_dir );

		self::rmrf( $tmp );

		// Fire cache-bust if available
		if ( class_exists( 'Therum_Cache_Bust' ) && method_exists( 'Therum_Cache_Bust', 'purge_all' ) ) {
			try { \Therum_Cache_Bust::purge_all(); } catch ( \Throwable $e ) {}
		}

		// Audit
		do_action( 'therum_audit_log', [
			'event'   => 'therum.update.applied',
			'actor'   => wp_get_current_user()->user_login ?? 'system',
			'bundle'  => $bundle,
			'version' => $info['version'] ?? ( $manifest['latest_version'] ?? '' ),
			'from'    => self::current_version(),
		] );

		return [
			'summary' => sprintf(
				'Bundle "%s" v%s applied. Reload any admin tab to see new code.',
				$bundle,
				$info['version'] ?? ( $manifest['latest_version'] ?? '?' )
			),
			'backup'  => $backup,
		];
	}

	private static function copy_tree( string $src, string $dst ): void {
		if ( ! is_dir( $dst ) ) wp_mkdir_p( $dst );
		$it = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $src, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::SELF_FIRST
		);
		foreach ( $it as $f ) {
			$rel  = substr( $f->getPathname(), strlen( $src ) );
			$dest = rtrim( $dst, '/' ) . $rel;
			if ( $f->isDir() ) {
				if ( ! is_dir( $dest ) ) wp_mkdir_p( $dest );
			} else {
				@copy( $f->getPathname(), $dest );
			}
		}
	}

	private static function rmrf( string $path ): void {
		if ( ! file_exists( $path ) ) return;
		if ( is_file( $path ) || is_link( $path ) ) { @unlink( $path ); return; }
		$it = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $path, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $it as $f ) {
			$f->isDir() ? @rmdir( $f->getPathname() ) : @unlink( $f->getPathname() );
		}
		@rmdir( $path );
	}
}

add_action( 'admin_menu', [ 'Therum_Updates', 'register_page' ] );

// Topbar dot when an update is available
add_action( 'admin_notices', function() {
	if ( ! current_user_can( 'manage_options' ) ) return;
	if ( ! Therum_Updates::has_update() ) return;
	$href = admin_url( 'admin.php?page=' . Therum_Updates::PAGE_SLUG );
	echo '<div class="notice notice-info" style="margin:8px 20px;"><p>'
		. 'Therum OS update available — <a href="' . esc_url( $href ) . '">review and apply</a>.'
		. '</p></div>';
} );
