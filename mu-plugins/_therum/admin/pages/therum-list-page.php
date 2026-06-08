<?php
/**
 * Therum OS — Therum_List_Page
 *
 * Extracted from therum-admin.php as part of the 1.9.x split. Same
 * class, same behavior; required back in from therum-admin.php at the
 * original load position to preserve declaration order.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

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
				$svg     = function_exists('therum_i') && $icon ? therum_i($icon) : '';
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
			  $icon = function_exists('therum_i') && !empty($a['icon']) ? therum_i($a['icon']) : '';
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
			<a class="th-lp-kebab-item<?php echo $danger; ?>" href="<?php echo esc_url($a['href']); ?>"<?php echo !empty($a['target']) ? ' target="' . esc_attr($a['target']) . '"' : ''; ?><?php echo !empty($a['download']) ? ' download="' . esc_attr($a['download']) . '"' : ''; ?><?php echo $data_attrs; ?> role="menuitem">
			  <?php echo $icon; ?>
			  <span><?php echo esc_html($a['label']); ?></span>
			</a>
			<?php endif; endforeach; ?>
		  </div>
		</div>
		<?php
	}
}
