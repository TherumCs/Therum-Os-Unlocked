<?php
/**
 * Therum OS — Therum_Plugins_Page
 *
 * Extracted from therum-admin.php as part of the 1.9.x split. Same
 * class, same behavior; required back in from therum-admin.php at the
 * original load position to preserve declaration order.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

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
		$core_items = array_values($core_items);
		$count = count($core_items);
		?>
		<section class="th-core-modules" aria-label="Therum Core modules">
		  <button type="button" class="th-core-modules-toggle" aria-expanded="false">
			<div class="th-core-modules-toggle-left">
			  <span class="th-core-modules-label">System modules</span>
			  <span class="th-core-modules-count"><?php echo (int)$count; ?></span>
			  <span class="th-core-modules-summary">
				<?php
				$names = array_map(function($item) {
					$n = preg_replace('/^Therum OS\s*[\x{2014}\x{2013}\-:]\s*/u', '', $item['name']);
					return $n ?: $item['name'];
				}, $core_items);
				echo esc_html(implode(' · ', $names));
				?>
			  </span>
			</div>
			<svg class="th-core-modules-chev" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
		  </button>

		  <div class="th-core-modules-body" hidden>
			<div class="th-core-modules-grid">
			  <?php foreach ($core_items as $item):
				$icon = self::core_module_icon($item['name']);
				$svg  = function_exists('therum_i') ? therum_i($icon) : '';
				$display_name = preg_replace('/^Therum OS\s*[\x{2014}\x{2013}\-:]\s*/u', '', $item['name']);
				$display_name = $display_name ?: $item['name'];
			  ?>
			  <div class="th-core-module" data-search="<?php echo esc_attr(strtolower($item['name'] . ' ' . $item['desc'])); ?>">
				<div class="th-core-module-icon"><?php echo $svg; ?></div>
				<div class="th-core-module-info">
				  <div class="th-core-module-name"><?php echo esc_html($display_name); ?></div>
				  <div class="th-core-module-desc"><?php echo esc_html(wp_trim_words($item['desc'], 12)); ?></div>
				</div>
				<div class="th-core-module-ver">v<?php echo esc_html($item['version']); ?></div>
			  </div>
			  <?php endforeach; ?>
			</div>
		  </div>
		</section>

		<style>
		.th-core-modules {
			margin: 0 0 24px;
			border: 1px solid var(--bd, rgba(0,0,0,0.08));
			border-radius: 10px;
			background: var(--sf, #fff);
			overflow: hidden;
		}
		.th-core-modules-toggle {
			display: flex;
			align-items: center;
			justify-content: space-between;
			width: 100%;
			padding: 12px 16px;
			border: none;
			background: none;
			cursor: pointer;
			gap: 12px;
			color: inherit;
			font-family: inherit;
			transition: background 120ms ease;
		}
		.th-core-modules-toggle:hover {
			background: var(--sf2, #f5f5f5);
		}
		.th-core-modules-toggle-left {
			display: flex;
			align-items: center;
			gap: 10px;
			min-width: 0;
		}
		.th-core-modules-label {
			font-size: 13px;
			font-weight: 600;
			letter-spacing: -0.01em;
			color: var(--tx, #0a0a0a);
			white-space: nowrap;
		}
		.th-core-modules-count {
			display: inline-flex;
			align-items: center;
			justify-content: center;
			min-width: 20px;
			height: 20px;
			padding: 0 6px;
			background: var(--sf2, #f5f5f5);
			color: var(--tx3, #999);
			border-radius: 999px;
			font-size: 11px;
			font-weight: 500;
			flex-shrink: 0;
		}
		.th-core-modules-summary {
			font-size: 12px;
			color: var(--tx3, #999);
			white-space: nowrap;
			overflow: hidden;
			text-overflow: ellipsis;
			min-width: 0;
		}
		.th-core-modules.is-open .th-core-modules-summary {
			display: none;
		}
		.th-core-modules-chev {
			color: var(--tx3, #999);
			flex-shrink: 0;
			transition: transform 200ms ease;
		}
		.th-core-modules.is-open .th-core-modules-chev {
			transform: rotate(180deg);
		}
		.th-core-modules-body {
			border-top: 1px solid var(--bd, rgba(0,0,0,0.06));
		}
		.th-core-modules-grid {
			display: grid;
			grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
			gap: 1px;
			background: var(--bd, rgba(0,0,0,0.06));
		}
		.th-core-module {
			display: flex;
			align-items: center;
			gap: 12px;
			padding: 12px 16px;
			background: var(--sf, #fff);
			transition: background 120ms ease;
		}
		.th-core-module:hover {
			background: var(--sf2, #f5f5f5);
		}
		.th-core-module-icon {
			width: 32px;
			height: 32px;
			display: grid;
			place-items: center;
			background: var(--sf2, #f5f5f5);
			border-radius: 8px;
			color: var(--ac, #e83b3b);
			flex-shrink: 0;
		}
		.th-core-module-icon svg {
			width: 16px;
			height: 16px;
		}
		.th-core-module-info {
			min-width: 0;
			flex: 1;
		}
		.th-core-module-name {
			font-size: 13px;
			font-weight: 500;
			color: var(--tx, #0a0a0a);
			letter-spacing: -0.01em;
			line-height: 1.3;
		}
		.th-core-module-desc {
			font-size: 11px;
			color: var(--tx3, #999);
			line-height: 1.4;
			white-space: nowrap;
			overflow: hidden;
			text-overflow: ellipsis;
		}
		.th-core-module-ver {
			font-size: 11px;
			color: var(--tx3, #999);
			white-space: nowrap;
			flex-shrink: 0;
		}
		</style>

		<script>
		(function () {
			var section = document.querySelector('.th-core-modules');
			if (!section) return;
			var toggle = section.querySelector('.th-core-modules-toggle');
			var body   = section.querySelector('.th-core-modules-body');
			if (!toggle || !body) return;

			// Restore collapsed state from localStorage
			var stored = localStorage.getItem('th_core_modules_open');
			if (stored === '1') {
				section.classList.add('is-open');
				body.hidden = false;
				toggle.setAttribute('aria-expanded', 'true');
			}

			toggle.addEventListener('click', function () {
				var open = section.classList.toggle('is-open');
				body.hidden = !open;
				toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
				localStorage.setItem('th_core_modules_open', open ? '1' : '0');
			});
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
