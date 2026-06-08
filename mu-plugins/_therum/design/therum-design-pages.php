<?php
/**
 * Therum OS — Therum_Design_Pages
 *
 * Extracted from therum-design.php (1.9.x split). Same class, same
 * behavior; required back in from therum-design.php at the original
 * load position to preserve declaration order.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class Therum_Design_Pages {

	const VERSION = '1.7.6-beta';

	private static $instance = null;
	public static function instance() {
		if (self::$instance === null) self::$instance = new self();
		return self::$instance;
	}

	private function __construct() {
		add_action('admin_menu', [$this, 'register_routes'], 999);
		// Inject Therum-styled CSS into the WP Customizer panels when iframed in Therum.
		add_action('customize_controls_print_styles', [$this, 'inject_customizer_styles'], 999);
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		// Bust header template cache when Bricks templates change.
		add_action('save_post_bricks_template', function() { delete_transient('therum_bricks_header_id'); });
		add_action('deleted_post', function($post_id) {
			if (get_post_type($post_id) === 'bricks_template') delete_transient('therum_bricks_header_id');
		});
	}

	/**
	 * Inject CSS into the WordPress Customizer to restyle native panels to match
	 * the 2023 admin redesign aesthetic (clean type, modern spacing, refined controls).
	 * Only active when the customizer is loaded via Therum (?th_frame=1).
	 */
	public function inject_customizer_styles() {
		if ( ! isset( $_GET['th_frame'] ) ) return;
		$path = __DIR__ . '/assets/therum-customizer.css';
		wp_enqueue_style( 'therum-customizer', plugins_url( 'assets/therum-customizer.css', __FILE__ ), [], file_exists( $path ) ? filemtime( $path ) : null );
	}

	public function enqueue_assets() {
		$page = $_GET['page'] ?? '';
		if ( strpos( $page, 'therum-' ) === false ) return;
		$path = __DIR__ . '/assets/therum-design-pages.css';
		wp_enqueue_style( 'therum-design-pages', plugins_url( 'assets/therum-design-pages.css', __FILE__ ), [], file_exists( $path ) ? filemtime( $path ) : null );
	}


	public function register_routes() {
		// All design routes are hidden from the WP sidebar (rendered through Therum chrome only)
		add_submenu_page('', 'Themes',     'Themes',     'switch_themes',     'therum-themes',         [$this, 'render_themes']);
		add_submenu_page('', 'Theme',      'Theme',      'switch_themes',     'therum-theme-detail',   [$this, 'render_theme_detail']);
		add_submenu_page('', 'Menus',      'Menus',      'edit_theme_options','therum-menus',          [$this, 'render_menus']);
		add_submenu_page('', 'Customizer', 'Customizer', 'edit_theme_options','therum-customizer',     [$this, 'render_customizer']);
		add_submenu_page('', 'Widgets',    'Widgets',    'edit_theme_options','therum-widgets',        [$this, 'render_widgets']);
		add_submenu_page('', 'Templates',  'Templates',  'edit_posts',        'therum-templates',      [$this, 'render_templates']);
	}

	/* ─────────────────────────────────────────────────────────────────────
	 * THEMES — grid view of installed themes
	 * ───────────────────────────────────────────────────────────────────── */

	public function render_themes() {
		if (!current_user_can('switch_themes')) wp_die(__('Insufficient permissions.', 'therum'));

		$themes = wp_get_themes();
		$current = wp_get_theme();
		$current_stylesheet = $current->get_stylesheet();
		$total = count($themes);

		// Sort: active theme first, then alphabetical
		uasort($themes, function ($a, $b) use ($current_stylesheet) {
			if ($a->get_stylesheet() === $current_stylesheet) return -1;
			if ($b->get_stylesheet() === $current_stylesheet) return 1;
			return strcmp($a->get('Name'), $b->get('Name'));
		});

		$counts = [
			'all' => $total,
			'active' => 1,
			'inactive' => max(0, $total - 1),
		];

		?>
		<div class="th-lp therum-themes-page" data-page-id="themes">
		  <div class="th-lp-header">
			<div class="th-lp-header-left">
			  <div class="th-lp-meta">
				<span class="th-lp-meta-dot"></span>
				<?php echo esc_html($counts['all']); ?> INSTALLED · <?php echo esc_html($counts['active']); ?> ACTIVE
			  </div>
			  <h1 class="th-lp-title">Themes</h1>
			  <p class="th-lp-sub">Visual identity for the front-end of your site.</p>
			</div>
		  </div>

		  <div class="th-lp-toolbar">
			<div class="th-lp-pills">
			  <?php foreach ([
			    ['key'=>'all', 'label'=>'All', 'count'=>$counts['all']],
			    ['key'=>'active', 'label'=>'Active', 'count'=>$counts['active']],
			    ['key'=>'inactive', 'label'=>'Inactive', 'count'=>$counts['inactive']],
			  ] as $i => $t): ?>
				<button type="button" class="th-lp-pill <?php echo $i === 0 ? 'active' : ''; ?>" data-tab="<?php echo esc_attr($t['key']); ?>">
				  <?php echo esc_html($t['label']); ?>
				  <span class="th-lp-pill-count"><?php echo esc_html($t['count']); ?></span>
				</button>
			  <?php endforeach; ?>
			</div>
		  </div>

		  <div class="therum-themes-grid">
			<?php foreach ($themes as $theme):
			  $is_active = $theme->get_stylesheet() === $current_stylesheet;
			  $has_screenshot = (string) $theme->get_screenshot();
			  $detail_url = admin_url('admin.php?page=therum-theme-detail&theme=' . urlencode($theme->get_stylesheet()));
			?>
			  <article class="therum-theme-card <?php echo $is_active ? 'is-active' : ''; ?>" data-status="<?php echo $is_active ? 'active' : 'inactive'; ?>">
				<a href="<?php echo esc_url($detail_url); ?>" class="therum-theme-card-link">
				  <div class="therum-theme-thumb">
					<?php if ($has_screenshot): ?>
					  <img src="<?php echo esc_url($has_screenshot); ?>" alt="<?php echo esc_attr($theme->get('Name')); ?>" loading="lazy">
					<?php else: ?>
					  <div class="therum-theme-thumb-placeholder"><?php echo esc_html(strtoupper(substr($theme->get('Name'), 0, 1))); ?></div>
					<?php endif; ?>
					<?php if ($is_active): ?>
					  <span class="therum-theme-active-badge">Active</span>
					<?php endif; ?>
				  </div>
				  <div class="therum-theme-body">
					<h3 class="therum-theme-name"><?php echo esc_html($theme->get('Name')); ?></h3>
					<p class="therum-theme-desc"><?php echo esc_html(wp_trim_words(strip_tags($theme->get('Description')), 14)); ?></p>
					<p class="therum-theme-meta">v<?php echo esc_html($theme->get('Version')); ?> · <?php echo esc_html($theme->get('Author')); ?></p>
				  </div>
				</a>
			  </article>
			<?php endforeach; ?>
		  </div>
		</div>


		<script>
		(function () {
			var tabs = document.querySelectorAll('.therum-themes-page .th-lp-pill');
			var cards = document.querySelectorAll('.therum-themes-page .therum-theme-card');
			tabs.forEach(function (tab) {
				tab.addEventListener('click', function () {
					tabs.forEach(function (t) { t.classList.remove('active'); });
					tab.classList.add('active');
					var filter = tab.dataset.tab;
					cards.forEach(function (card) {
						if (filter === 'all') card.style.display = '';
						else card.style.display = (card.dataset.status === filter ? '' : 'none');
					});
				});
			});
		})();
		</script>
		<?php
	}

	/* ─────────────────────────────────────────────────────────────────────
	 * THEME DETAIL — single theme PDP with Customize + Settings actions
	 * ───────────────────────────────────────────────────────────────────── */

	public function render_theme_detail() {
		if (!current_user_can('switch_themes')) wp_die(__('Insufficient permissions.', 'therum'));

		$stylesheet = isset($_GET['theme']) ? sanitize_text_field($_GET['theme']) : '';
		$theme = wp_get_theme($stylesheet);

		if (!$theme->exists()) {
			?>
			<div class="th-lp"><div class="th-settings-empty">
			  <h3 class="th-settings-empty-title">Theme not found</h3>
			  <p class="th-settings-empty-body">No installed theme matches the slug "<?php echo esc_html($stylesheet); ?>".</p>
			  <a class="th-pdp-btn" href="<?php echo esc_url(admin_url('admin.php?page=therum-themes')); ?>">Back to Themes</a>
			</div></div>
			<?php
			return;
		}

		$current = wp_get_theme();
		$is_active = $theme->get_stylesheet() === $current->get_stylesheet();
		$screenshot = (string) $theme->get_screenshot();
		$theme_settings_url = $this->detect_theme_settings_url($theme);
		$customizer_url = admin_url('customize.php' . ($is_active ? '' : '?theme=' . urlencode($theme->get_stylesheet())));
		$activate_url = wp_nonce_url(
			admin_url('themes.php?action=activate&stylesheet=' . urlencode($theme->get_stylesheet())),
			'switch-theme_' . $theme->get_stylesheet()
		);

		?>
		<div class="th-lp therum-theme-detail" data-page-id="theme-detail">
		  <div class="th-lp-header">
			<div class="th-lp-header-left">
			  <a href="<?php echo esc_url(admin_url('admin.php?page=therum-themes')); ?>" class="th-pdp-back-link">
				<span class="th-pdp-back-arrow">←</span>
				<span>Back to Themes</span>
			  </a>
			</div>
		  </div>

		  <header class="th-pdp-header therum-theme-pdp-header">
			<div class="therum-theme-pdp-thumb">
			  <?php if ($screenshot): ?>
				<img src="<?php echo esc_url($screenshot); ?>" alt="<?php echo esc_attr($theme->get('Name')); ?>">
			  <?php else: ?>
				<div class="therum-theme-thumb-placeholder"><?php echo esc_html(strtoupper(substr($theme->get('Name'), 0, 1))); ?></div>
			  <?php endif; ?>
			</div>

			<div class="th-pdp-header-text">
			  <?php if ($is_active): ?>
				<span class="therum-theme-active-badge therum-theme-active-badge--inline">Active</span>
			  <?php endif; ?>
			  <h1 class="th-pdp-title"><?php echo esc_html($theme->get('Name')); ?></h1>
			  <div class="th-pdp-byline">
				<span>v<?php echo esc_html($theme->get('Version')); ?></span>
				<span>·</span>
				<span><?php echo esc_html($theme->get('Author')); ?></span>
				<?php if ($theme->get('ThemeURI')): ?>
				  <span>·</span>
				  <a href="<?php echo esc_url($theme->get('ThemeURI')); ?>" target="_blank" rel="noopener">Theme homepage ↗</a>
				<?php endif; ?>
			  </div>

			  <div class="th-pdp-actions">
				<?php if ($is_active && $theme_settings_url): ?>
				  <a class="th-pdp-btn" href="<?php echo esc_url($theme_settings_url); ?>">Settings</a>
				<?php endif; ?>
				<?php if ($is_active): ?>
				  <a class="th-pdp-btn" href="<?php echo esc_url(admin_url('admin.php?page=therum-customizer')); ?>">Customize</a>
				<?php else: ?>
				  <a class="th-pdp-btn th-pdp-btn-primary" href="<?php echo esc_url($activate_url); ?>">Activate</a>
				  <a class="th-pdp-btn" href="<?php echo esc_url($customizer_url); ?>">Live Preview</a>
				<?php endif; ?>
			  </div>
			</div>
		  </header>

		  <div class="th-pdp-grid">
			<div class="th-pdp-card">
			  <h3 class="th-pdp-card-title">About this theme</h3>
			  <p class="th-pdp-card-body"><?php echo wp_kses_post($theme->get('Description')); ?></p>
			</div>

			<aside class="th-pdp-side">
			  <div class="th-pdp-card">
				<h3 class="th-pdp-card-title">Details</h3>
				<dl class="th-pdp-meta">
				  <dt>Version</dt><dd>v<?php echo esc_html($theme->get('Version')); ?></dd>
				  <dt>Slug</dt><dd><?php echo esc_html($theme->get_stylesheet()); ?></dd>
				  <?php if ($theme->get('Tags')): ?>
					<dt>Tags</dt><dd><?php echo esc_html(implode(', ', (array) $theme->get('Tags'))); ?></dd>
				  <?php endif; ?>
				  <?php if ($theme->parent()): ?>
					<dt>Parent</dt><dd><?php echo esc_html($theme->parent()->get('Name')); ?></dd>
				  <?php endif; ?>
				</dl>
			  </div>
			</aside>
		  </div>
		</div>

		<?php
	}

	/**
	 * Detect a theme's admin settings page URL by looking at registered admin
	 * pages whose menu was added during the active theme's setup.
	 *
	 * Returns null if no settings page is detected (e.g. theme uses customizer only).
	 */
	private function detect_theme_settings_url($theme) {
		// For active theme only — non-active themes haven't registered their menus
		$current = wp_get_theme();
		if ($theme->get_stylesheet() !== $current->get_stylesheet()) return null;

		global $menu, $submenu;
		$theme_textdomain = strtolower($theme->get('TextDomain'));
		$theme_slug = strtolower($theme->get_stylesheet());

		// Check for registered admin pages that look like theme settings
		// Heuristic: page slug contains theme stylesheet name or text domain
		$candidates = [];
		if (is_array($menu)) {
			foreach ($menu as $item) {
				if (!isset($item[2])) continue;
				$slug = strtolower($item[2]);
				if ($this->slug_matches_theme($slug, $theme_slug, $theme_textdomain)) {
					$candidates[] = $item[2];
				}
			}
		}

		// Hardcoded mappings for known themes that don't follow the pattern
		$known_themes = [
			'bricks'        => 'bricks-settings',
			'bricks-child'  => 'bricks-settings',
			'astra'         => 'astra',
			'kadence'       => 'kadence',
			'generatepress' => 'generate-options',
			'oceanwp'       => 'oceanwp-panel',
			'blocksy'       => 'ct-dashboard',
		];

		if (isset($known_themes[$theme_slug])) {
			array_unshift($candidates, $known_themes[$theme_slug]);
		}

		// Allow filter override
		$candidates = apply_filters('therum_theme_settings_candidates', $candidates, $theme);

		if (empty($candidates)) return null;

		// Use the first candidate. We used to route through a Therum plugin-settings
		// embedder (page=therum-plugin-{slug}) — that system was scrubbed during a
		// merge and never restored, leaving theme Settings buttons broken. Until the
		// embedder is rebuilt, route to wherever the theme actually registered its
		// settings page in wp-admin: themes.php (Appearance > {Theme Name}) is the
		// near-universal convention; admin.php?page={slug} catches the few themes
		// that register at the top level. We try themes.php first since most match.
		$settings_slug = $candidates[0];
		return admin_url('themes.php?page=' . $settings_slug);
	}

	private function slug_matches_theme($slug, $theme_slug, $theme_textdomain) {
		if ($theme_slug && (strpos($slug, $theme_slug) === 0 || strpos($slug, $theme_slug . '-') !== false)) return true;
		if ($theme_textdomain && $theme_textdomain !== $theme_slug && strpos($slug, $theme_textdomain) === 0) return true;
		return false;
	}

	/* ─────────────────────────────────────────────────────────────────────
	 * MENUS / CUSTOMIZER / WIDGETS — Bricks-aware native page wrappers
	 * ───────────────────────────────────────────────────────────────────── */

	/**
	 * Detect if Bricks is active in any meaningful sense — as theme, plugin,
	 * or just present. We check multiple signals because WordPress's stylesheet
	 * option can be stale (point to a missing theme) even when Bricks is the
	 * actual editing context.
	 */
	private function is_bricks_active() {
		// Signal 1: stylesheet/template name matches bricks
		$current = wp_get_theme();
		$slug = strtolower((string) $current->get_stylesheet());
		$template = strtolower((string) $current->get_template());
		if ($slug === 'bricks' || $template === 'bricks') return true;
		if (strpos($slug, 'bricks') !== false || strpos($template, 'bricks') !== false) return true;

		// Signal 2: Bricks constants defined (plugin or theme has loaded)
		if (defined('BRICKS_VERSION')) return true;
		if (defined('BRICKS_DB_PAGE_HEADER')) return true;
		if (defined('BRICKS_DB_TEMPLATE_SLUG')) return true;

		// Signal 3: Bricks classes loaded
		if (class_exists('Bricks\\Database')) return true;
		if (class_exists('Bricks\\Capabilities')) return true;
		if (class_exists('Bricks\\Theme')) return true;

		// Signal 4: Bricks templates post type registered
		if (post_type_exists('bricks_template')) return true;

		// Signal 5: Bricks theme directory exists on disk (even if not "active" in WP sense)
		if (is_dir(WP_CONTENT_DIR . '/themes/bricks')) return true;

		return false;
	}

	/**
	 * Get the URL to the Bricks templates list (for "edit your menu in Bricks" CTAs).
	 */
	/**
	 * Discover a Bricks admin page URL by scanning the registered admin menu.
	 * Bricks registers all its pages under the top-level "bricks" parent menu.
	 * If we can find a submenu entry whose slug matches what we're looking for,
	 * we use the actual registered URL — no guessing.
	 *
	 * Returns null if the page isn't registered (e.g. user lacks permissions or
	 * Bricks's admin menu hasn't loaded). Caller falls back to the static URL.
	 */
	private function bricks_admin_url($slug_match, $fallback) {
		global $submenu;
		if (!is_array($submenu)) return $fallback;

		foreach ($submenu as $parent => $items) {
			// Bricks's parent slug is "bricks" — bail early on other parents
			if ($parent !== 'bricks') continue;
			foreach ($items as $item) {
				$item_slug = isset($item[2]) ? (string) $item[2] : '';
				if ($item_slug === $slug_match || strpos($item_slug, $slug_match) !== false) {
					// Resolve relative slug to admin URL
					if (strpos($item_slug, '.php') !== false) {
						return admin_url($item_slug);
					}
					return admin_url('admin.php?page=' . $item_slug);
				}
			}
		}
		return $fallback;
	}

	private function bricks_templates_url() {
		// Templates list lives at edit.php?post_type=bricks_template — this URL
		// is part of WP core's post-type registration, not Bricks's submenu.
		return admin_url('edit.php?post_type=bricks_template');
	}

	/**
	 * URL to open the Bricks builder for a specific template (or templates list).
	 * If $template_id is null, returns templates list URL.
	 */
	private function bricks_builder_url($template_id = null) {
		if ($template_id) {
			return home_url('/?page_id=' . (int) $template_id . '&bricks=run');
		}
		return $this->bricks_templates_url();
	}

	/**
	 * Get the URL to the Bricks plugin/theme settings page.
	 * Discovers the actual registered URL from the admin menu when possible.
	 */
	private function bricks_settings_url() {
		return $this->bricks_admin_url('bricks-settings', admin_url('admin.php?page=bricks-settings'));
	}

	/**
	 * Get the URL to the Bricks license/account page.
	 */
	private function bricks_license_url() {
		return $this->bricks_admin_url('bricks-license', admin_url('admin.php?page=bricks-license'));
	}

	/**
	 * Find the Bricks header template ID if one exists. Used to deep-link
	 * the "Edit your nav menu" action straight into the right template.
	 * Returns null if no header template found.
	 */
	private function bricks_header_template_id() {
		$cache_key = 'therum_bricks_header_id';
		$cached = get_transient($cache_key);
		if ($cached !== false) return $cached === 'none' ? null : (int) $cached;

		$query = new WP_Query([
			'post_type'      => 'bricks_template',
			'posts_per_page' => 1,
			'meta_query'     => [
				[
					'key'   => '_bricks_template_type',
					'value' => 'header',
				],
			],
			'fields'         => 'ids',
			'no_found_rows'  => true,
		]);
		$id = !empty($query->posts) ? (int) $query->posts[0] : null;
		set_transient($cache_key, $id ?: 'none', HOUR_IN_SECONDS);
		return $id;
	}

	public function render_menus() {
		if ($this->is_bricks_active()) {
			$header_id = $this->bricks_header_template_id();
			$primary_action = $header_id
				? ['label' => 'Edit header in Bricks',  'url' => $this->bricks_builder_url($header_id), 'primary' => true,  'target' => '_blank']
				: ['label' => 'Open Bricks templates', 'url' => $this->bricks_templates_url(),         'primary' => true,  'target' => '_blank'];

			$this->render_bricks_aware_page([
				'title' => 'Menus',
				'description' => 'Navigation menus and structure.',
				'bricks_message' => 'Bricks manages navigation menus inside the builder, not via WordPress menus. Open your Header template in Bricks to find the Nav Menu element and edit your site navigation.',
				'bricks_actions' => [
					$primary_action,
					['label' => 'All Bricks templates', 'url' => $this->bricks_templates_url(), 'target' => '_blank'],
					['label' => 'WordPress menus',      'url' => admin_url('nav-menus.php')],
				],
			]);
			return;
		}
		// Native Therum menus UI (replaces iframed WP nav-menus.php)
		if ( function_exists( 'therum_render_native_menus' ) ) {
			therum_render_native_menus();
			return;
		}
		$this->render_iframed_native('Menus', 'Navigation menus and structure.', admin_url('nav-menus.php'));
	}

	public function render_customizer() {
		if ($this->is_bricks_active()) {
			$this->render_bricks_aware_page([
				'title' => 'Customizer',
				'description' => 'Live theme customization.',
				'bricks_message' => 'Bricks doesn\'t use the WordPress Customizer for visual styling. Theme styling, colors, typography, and global settings live inside the Bricks builder and Bricks settings panel.',
				'bricks_actions' => [
					['label' => 'Open Bricks settings',     'url' => $this->bricks_settings_url(),    'primary' => true, 'target' => '_blank'],
					['label' => 'Open Bricks templates',    'url' => $this->bricks_templates_url(),   'target' => '_blank'],
					['label' => 'WordPress Customizer',     'url' => admin_url('customize.php')],
				],
			]);
			return;
		}
		$this->render_iframed_native('Customizer', 'Live theme customization.', admin_url('customize.php?return=' . urlencode(admin_url('admin.php?page=therum-themes'))), true);
	}

	public function render_widgets() {
		if ($this->is_bricks_active()) {
			$this->render_bricks_aware_page([
				'title' => 'Widgets',
				'description' => 'Sidebar and footer widgets.',
				'bricks_message' => 'Bricks doesn\'t use WordPress widgets. Sidebars and widget areas are managed via Bricks Sidebars, and content is built visually in the Bricks builder.',
				'bricks_actions' => [
					['label' => 'Open Bricks Sidebars',  'url' => $this->bricks_admin_url('bricks-sidebars', admin_url('admin.php?page=bricks-sidebars')), 'primary' => true, 'target' => '_blank'],
					['label' => 'Open Bricks templates', 'url' => $this->bricks_templates_url(), 'target' => '_blank'],
					['label' => 'WordPress widgets',     'url' => admin_url('widgets.php')],
				],
			]);
			return;
		}
		// Native Therum widgets UI (replaces iframed WP widgets.php)
		if ( function_exists( 'therum_render_native_widgets' ) ) {
			therum_render_native_widgets();
			return;
		}
		$this->render_iframed_native('Widgets', 'Sidebar and footer widgets.', admin_url('widgets.php'));
	}

	public function render_templates() {
		// Bricks templates list — only meaningful when Bricks is installed.
		if ( ! post_type_exists( 'bricks_template' ) && ! is_dir( WP_CONTENT_DIR . '/themes/bricks' ) ) {
			$this->render_bricks_aware_page([
				'title' => 'Templates',
				'description' => 'Site-wide template library.',
				'bricks_message' => 'Templates are a Bricks feature. Install or activate the Bricks theme to manage header, footer, archive, and section templates here.',
				'bricks_actions' => [],
			]);
			return;
		}
		Therum_Templates_Page::render();
	}

	/**
	 * Render a "this lives in Bricks" page with helpful actions.
	 * Used when the active theme is Bricks and the WP-native equivalent is unhelpful.
	 *
	 * @param array $config {
	 *     @type string $title           Page title (matches sidebar label)
	 *     @type string $description     Subtitle below page title
	 *     @type string $bricks_message  Explanation of why Bricks handles this differently
	 *     @type array  $bricks_actions  Array of ['label', 'url', 'primary' => bool] action buttons
	 *     @type string $native_url      Fallback link to native WordPress equivalent
	 * }
	 */
	private function render_bricks_aware_page($config) {
		// Page-specific hero illustrations. Larger and more confident than before.
		// Each one uses a unified visual language: outlined geometric shapes on a
		// dot-grid background that suggests "this is a system surface."
		$hero_svgs = [
			'Menus' => '<svg width="120" height="120" viewBox="0 0 120 120" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
				<rect x="20" y="22" width="80" height="14" rx="3" stroke-opacity="0.4"/>
				<rect x="28" y="48" width="64" height="14" rx="3"/>
				<rect x="36" y="74" width="48" height="14" rx="3" stroke-opacity="0.6"/>
				<circle cx="14" cy="29" r="2" fill="currentColor" stroke="none"/>
				<circle cx="14" cy="55" r="2" fill="currentColor" stroke="none"/>
				<circle cx="14" cy="81" r="2" fill="currentColor" stroke="none"/>
			</svg>',
			'Customizer' => '<svg width="120" height="120" viewBox="0 0 120 120" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
				<line x1="18" y1="32" x2="102" y2="32" stroke-opacity="0.4"/>
				<circle cx="76" cy="32" r="6" fill="currentColor" stroke="none"/>
				<line x1="18" y1="60" x2="102" y2="60"/>
				<circle cx="38" cy="60" r="6" fill="currentColor" stroke="none"/>
				<line x1="18" y1="88" x2="102" y2="88" stroke-opacity="0.6"/>
				<circle cx="86" cy="88" r="6" fill="currentColor" stroke="none"/>
			</svg>',
			'Widgets' => '<svg width="120" height="120" viewBox="0 0 120 120" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
				<rect x="18" y="18" width="38" height="38" rx="4" stroke-opacity="0.4"/>
				<rect x="64" y="18" width="38" height="38" rx="4"/>
				<rect x="18" y="64" width="38" height="38" rx="4"/>
				<rect x="64" y="64" width="38" height="38" rx="4" stroke-opacity="0.6"/>
				<circle cx="37" cy="37" r="3" fill="currentColor" stroke="none" opacity="0.4"/>
				<circle cx="83" cy="37" r="3" fill="currentColor" stroke="none"/>
				<circle cx="37" cy="83" r="3" fill="currentColor" stroke="none"/>
				<circle cx="83" cy="83" r="3" fill="currentColor" stroke="none" opacity="0.6"/>
			</svg>',
		];
		$hero_svg = $hero_svgs[$config['title']] ?? $hero_svgs['Widgets'];
		?>
		<div class="th-lp therum-bricks-aware" data-page-id="design">
		  <div class="th-lp-header">
			<div class="th-lp-header-left">
			  <div class="th-lp-meta">
				<span class="th-lp-meta-dot"></span>BRICKS THEME · MANAGED IN BUILDER
			  </div>
			  <h1 class="th-lp-title"><?php echo esc_html($config['title']); ?></h1>
			  <p class="th-lp-sub"><?php echo esc_html($config['description']); ?></p>
			</div>
		  </div>

		  <div class="therum-bricks-hero">
			<div class="therum-bricks-hero-visual" aria-hidden="true">
			  <div class="therum-bricks-hero-grid"></div>
			  <div class="therum-bricks-hero-illo"><?php echo $hero_svg; ?></div>
			</div>
			<div class="therum-bricks-hero-body">
			  <span class="therum-bricks-hero-eyebrow">Where it lives</span>
			  <h2 class="therum-bricks-hero-title">Inside the Bricks builder</h2>
			  <p class="therum-bricks-hero-text"><?php echo esc_html($config['bricks_message']); ?></p>
			  <div class="therum-bricks-hero-actions">
				<?php foreach ($config['bricks_actions'] as $i => $action):
				  $is_primary = !empty($action['primary']);
				  $target = !empty($action['target']) ? $action['target'] : '';
				?>
				  <a class="therum-bricks-action <?php echo $is_primary ? 'is-primary' : 'is-ghost'; ?>"
				     href="<?php echo esc_url($action['url']); ?>"
				     <?php if ($target): ?>target="<?php echo esc_attr($target); ?>" rel="noopener"<?php endif; ?>>
					<span class="therum-bricks-action-label"><?php echo esc_html($action['label']); ?></span>
					<svg class="therum-bricks-action-arrow" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><?php if ($target === '_blank'): ?><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/><?php else: ?><path d="M5 12h14M12 5l7 7-7 7"/><?php endif; ?></svg>
				  </a>
				<?php endforeach; ?>
			  </div>
			</div>
		  </div>
		</div>

		<?php
	}

	/**
	 * Render a native WordPress admin page wrapped in Therum chrome via iframe.
	 * Used for pages we don't try to hook+render inline (Customizer is full-screen,
	 * Menus has complex JS, etc.).
	 */
	private function render_iframed_native($title, $description, $url, $full_height = false) {
		$frame_id = 'th-iframe-' . sanitize_html_class(strtolower($title));
		?>
		<div class="th-lp therum-iframed-native" data-page-id="design">
		  <div class="th-lp-header">
			<div class="th-lp-header-left">
			  <div class="th-lp-meta">
				<span class="th-lp-meta-dot"></span>NATIVE WORDPRESS · WRAPPED IN THERUM
			  </div>
			  <h1 class="th-lp-title"><?php echo esc_html($title); ?></h1>
			  <p class="th-lp-sub"><?php echo esc_html($description); ?></p>
			</div>
		  </div>

		  <div class="therum-iframe-shell <?php echo $full_height ? 'is-full-height' : ''; ?>">
			<div class="therum-iframe-toolbar">
			  <div class="therum-iframe-toolbar-left">
				<button type="button" class="therum-iframe-tool-btn" data-action="reload" title="Reload" aria-label="Reload frame">
				  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>
				</button>
			  </div>
			  <div class="therum-iframe-toolbar-right">
				<a href="<?php echo esc_url($url); ?>" target="_blank" rel="noopener" class="therum-iframe-tool-btn therum-iframe-tool-btn--text">
				  Open native
				  <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" style="margin-left:5px"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
				</a>
			  </div>
			</div>
			<div class="therum-iframe-frame">
			  <iframe id="<?php echo esc_attr($frame_id); ?>" src="<?php echo esc_url(add_query_arg('th_frame', '1', $url)); ?>" frameborder="0"></iframe>
			</div>
		  </div>
		</div>


		<script>
		(function () {
			var frame = document.getElementById('<?php echo esc_js($frame_id); ?>');
			if (!frame) return;
			var reloadBtn = frame.closest('.therum-iframe-shell').querySelector('[data-action="reload"]');
			if (reloadBtn) {
				reloadBtn.addEventListener('click', function () {
					frame.src = frame.src;
				});
			}
		})();
		</script>
		<?php
	}
}
