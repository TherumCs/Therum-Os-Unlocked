<?php
/**
 * Therum OS — Therum_Settings
 *
 * Extracted from therum-admin.php as part of the 1.9.x split. Same
 * class, same behavior; required back in from therum-admin.php at the
 * original load position to preserve declaration order.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

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
		$active_key = sanitize_key( wp_unslash( $_GET['section'] ?? '' ) );
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
				<?php if (function_exists('therum_i') && !empty($s['icon'])) echo therum_i($s['icon']); ?>
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
