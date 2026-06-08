<?php
/**
 * Plugin Name: Therum OS — Elementor integration
 * Description: Mirror of the Bricks integration for sites running Elementor.
 *              Auto-redirects edit screens into Elementor for posts built with
 *              it, clears Elementor CSS cache alongside Therum's cache purge,
 *              and injects Elementor Templates / Settings into the Therum
 *              sidebar nav. Every Elementor API call is class_exists-guarded;
 *              the file is harmless on sites without Elementor.
 * Version: 1.9.25
 *
 * Kill switch: define( 'THERUM_ELEMENTOR_DISABLE', true ) in wp-config.php.
 */

if ( ! defined( 'ABSPATH' ) ) exit;
if ( defined( 'THERUM_ELEMENTOR_DISABLE' ) && THERUM_ELEMENTOR_DISABLE ) return;

// ─────────────────────────────────────────────────────────────────────────────
//  ACTIVE CHECK
// ─────────────────────────────────────────────────────────────────────────────

/** True when Elementor is installed and bootstrapped on this request. */
function therum_elementor_active(): bool {
	return defined( 'ELEMENTOR_VERSION' ) || class_exists( '\\Elementor\\Plugin' );
}

/** True when a given post was authored in Elementor (vs Classic / Gutenberg). */
function therum_post_uses_elementor( int $post_id ): bool {
	if ( ! $post_id || ! therum_elementor_active() ) return false;
	// `_elementor_edit_mode = builder` is set the first time the post is opened
	// in Elementor. This is the same check Elementor itself uses for the
	// "Edit with Elementor" admin-bar link.
	return get_post_meta( $post_id, '_elementor_edit_mode', true ) === 'builder';
}

// ─────────────────────────────────────────────────────────────────────────────
//  BUILDER URL — mirrors therum_bricks_builder_url() for Elementor
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Build a usable Elementor editor URL for any post. Uses the Plugin
 * singleton's editor->get_edit_url() when available; falls back to the
 * documented action=elementor query format if the API method changes.
 *
 * Returns '' when Elementor isn't active or the post isn't Elementor-edited.
 */
function therum_elementor_builder_url( $post ): string {
	if ( ! $post || ! therum_elementor_active() ) return '';
	$post_id = is_object( $post ) ? (int) ( $post->ID ?? 0 ) : (int) $post;
	if ( $post_id <= 0 ) return '';
	if ( ! therum_post_uses_elementor( $post_id ) ) return '';

	// Preferred: Elementor's own URL builder (handles preview args, post-type
	// support, multi-site, etc.).
	if ( class_exists( '\\Elementor\\Plugin' ) ) {
		$plugin = \Elementor\Plugin::$instance ?? null;
		if ( $plugin && isset( $plugin->documents ) && method_exists( $plugin->documents, 'get' ) ) {
			try {
				$doc = $plugin->documents->get( $post_id );
				if ( $doc && method_exists( $doc, 'get_edit_url' ) ) {
					$url = (string) $doc->get_edit_url();
					if ( $url ) return $url;
				}
			} catch ( \Throwable $e ) {
				// fall through to the manual builder URL
			}
		}
	}

	// Fallback — Elementor reads `?post=<id>&action=elementor` and resolves
	// the rest itself. Matches the URL the admin-bar link produces.
	return add_query_arg(
		[ 'post' => $post_id, 'action' => 'elementor' ],
		admin_url( 'post.php' )
	);
}

// ─────────────────────────────────────────────────────────────────────────────
//  POST EDIT AUTO-REDIRECT (parity with Bricks: pages open in the builder)
//
//  Triggered only for posts already built with Elementor — so a brand-new
//  page still opens in Gutenberg/Classic. Honors `?editor_mode=wordpress` as
//  an escape hatch (same convention the Bricks redirect uses).
// ─────────────────────────────────────────────────────────────────────────────
add_action( 'load-post.php', function() {
	if ( ! therum_elementor_active() ) return;
	if ( ! empty( $_GET['editor_mode'] ) && sanitize_key( wp_unslash( $_GET['editor_mode'] ) ) === 'wordpress' ) return;

	$screen = get_current_screen();
	if ( ! $screen || $screen->base !== 'post' ) return;

	$post_id = isset( $_GET['post'] ) ? (int) $_GET['post'] : 0;
	if ( $post_id <= 0 ) return;

	if ( ! therum_post_uses_elementor( $post_id ) ) return;
	if ( ! current_user_can( 'edit_post', $post_id ) ) return;

	$url = therum_elementor_builder_url( $post_id );
	if ( $url ) {
		wp_safe_redirect( $url );
		exit;
	}
}, 1 );

// ─────────────────────────────────────────────────────────────────────────────
//  CACHE PURGE — Elementor maintains its own CSS file cache; clear it when
//  Therum purges so design-token / global-style changes take effect on the
//  next page load instead of after the next file regeneration.
// ─────────────────────────────────────────────────────────────────────────────
add_action( 'therum_cache_purged', function( $context = '' ) {
	if ( ! therum_elementor_active() ) return;
	if ( ! class_exists( '\\Elementor\\Plugin' ) ) return;

	$plugin = \Elementor\Plugin::$instance ?? null;
	if ( ! $plugin ) return;

	try {
		// 1. Elementor CSS file cache — main one. Regenerated on next request.
		if ( isset( $plugin->files_manager ) && method_exists( $plugin->files_manager, 'clear_cache' ) ) {
			$plugin->files_manager->clear_cache();
		}
		// 2. Elementor Pro CSS file cache (if Pro is installed and bootstrapped).
		if ( class_exists( '\\ElementorPro\\Plugin' ) ) {
			$pro = \ElementorPro\Plugin::instance();
			if ( $pro && isset( $pro->files_manager ) && method_exists( $pro->files_manager, 'clear_cache' ) ) {
				$pro->files_manager->clear_cache();
			}
		}
	} catch ( \Throwable $e ) {
		error_log( '[therum-elementor] cache purge failed: ' . $e->getMessage() );
	}
}, 10, 1 );

// Design-token regeneration is covered by the `therum_cache_purged` hook
// above — Apply::run() fires that action with context='design_tokens' after
// tokens are written, so Elementor's CSS files clear and rebuild on the next
// request without needing an extra action.

// ─────────────────────────────────────────────────────────────────────────────
//  SIDEBAR NAV — inject Elementor Templates + Settings into the Site section.
//  Gated on:
//    - Elementor active (class_exists)
//    - Templates CPT registered (covers Elementor / Elementor Pro)
//    - Settings menu actually registered via menu_page_url()
// ─────────────────────────────────────────────────────────────────────────────
add_filter( 'therum_admin_nav_items', function( array $items ): array {
	if ( ! therum_elementor_active() ) return $items;

	$add = [];

	if ( post_type_exists( 'elementor_library' ) ) {
		$add[] = [
			'label' => 'Elementor Templates',
			'icon'  => 'templates',
			'url'   => 'edit.php?post_type=elementor_library',
			'match' => 'post_type=elementor_library',
		];
	}

	$settings_url = function_exists( 'menu_page_url' ) ? menu_page_url( 'elementor', false ) : '';
	if ( $settings_url ) {
		$add[] = [
			'label' => 'Elementor Settings',
			'icon'  => 'settings',
			'url'   => 'admin.php?page=elementor',
			'match' => 'page=elementor',
		];
	}

	if ( ! $add ) return $items;

	// Append to the Site section if it exists (same place as Bricks links).
	foreach ( $items as &$section ) {
		if ( ( $section['id'] ?? '' ) === 'site' && isset( $section['items'] ) && is_array( $section['items'] ) ) {
			$section['items'] = array_merge( $section['items'], $add );
			return $items;
		}
	}
	unset( $section );

	// No Site section — drop the links into the first section so they're at
	// least reachable.
	if ( isset( $items[0]['items'] ) && is_array( $items[0]['items'] ) ) {
		$items[0]['items'] = array_merge( $items[0]['items'], $add );
	}
	return $items;
} );

// ─────────────────────────────────────────────────────────────────────────────
//  SETTINGS SHORTCUTS — "Elementor shortcuts" group on the Settings page,
//  mirroring "Bricks shortcuts" at therum-admin.php settings tab. Rendered
//  only when Elementor is loaded; otherwise we surface a helpful message.
// ─────────────────────────────────────────────────────────────────────────────
add_action( 'therum_settings_groups', function() {
	if ( ! function_exists( 'therum_settings_group' ) ) return;
	therum_settings_group( 'Elementor shortcuts', 'Quick links to Elementor settings.', function() {
		if ( ! therum_elementor_active() ) {
			echo '<div style="font-size:13px;color:var(--tx2);">Elementor not detected. Install + activate Elementor to use these.</div>';
			return;
		}
		?>
		<div class="th-shortcut-grid">
			<a class="th-shortcut" href="<?php echo esc_url( admin_url( 'admin.php?page=elementor' ) ); ?>">Elementor Settings</a>
			<?php if ( post_type_exists( 'elementor_library' ) ): ?>
				<a class="th-shortcut" href="<?php echo esc_url( admin_url( 'edit.php?post_type=elementor_library' ) ); ?>">Templates</a>
			<?php endif; ?>
			<?php if ( function_exists( 'menu_page_url' ) && menu_page_url( 'elementor-tools', false ) ): ?>
				<a class="th-shortcut" href="<?php echo esc_url( admin_url( 'admin.php?page=elementor-tools' ) ); ?>">Tools</a>
			<?php endif; ?>
			<?php if ( function_exists( 'menu_page_url' ) && menu_page_url( 'elementor-system-info', false ) ): ?>
				<a class="th-shortcut" href="<?php echo esc_url( admin_url( 'admin.php?page=elementor-system-info' ) ); ?>">System Info</a>
			<?php endif; ?>
		</div>
		<?php
	} );
} );
