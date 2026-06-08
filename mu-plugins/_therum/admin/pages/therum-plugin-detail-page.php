<?php
/**
 * Therum OS — Therum_Plugin_Detail_Page
 *
 * Extracted from therum-admin.php as part of the 1.9.x split. Same
 * class, same behavior; required back in from therum-admin.php at the
 * original load position to preserve declaration order.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

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
