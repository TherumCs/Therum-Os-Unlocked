<?php
/**
 * Plugin Name: Therum OS — Design
 * Description: Design system pages (themes, Bricks-aware routes, customizer skin),
 *              native menus/widgets replacement, and motion/animation engine.
 *              Merged from therum-design-pages, therum-native-design, therum-motion.
 * Version: 1.9.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;


// ════════════════════════════════════════════════════════════════════════
// DESIGN PAGES (themes grid, PDP, customizer) — from therum-design-pages.php
// ════════════════════════════════════════════════════════════════════════

if (!defined('ABSPATH')) exit;

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

Therum_Design_Pages::instance();

// ════════════════════════════════════════════════════════════════════════
// NATIVE DESIGN (menus + widgets replacement) — from therum-native-design.php
// ════════════════════════════════════════════════════════════════════════

if ( ! defined( 'ABSPATH' ) ) exit;

// ─────────────────────────────────────────────────────────────────────
// HIJACK WP URLS — redirect nav-menus.php and widgets.php to Therum
// ─────────────────────────────────────────────────────────────────────
add_action( 'admin_init', function() {
	global $pagenow;
	// Skip if user is intentionally hitting WP UI (debug param)
	if ( isset( $_GET['use_wp_native'] ) ) return;

	if ( $pagenow === 'nav-menus.php' && current_user_can( 'edit_theme_options' ) ) {
		wp_safe_redirect( admin_url( 'admin.php?page=therum-menus' ) );
		exit;
	}
	if ( $pagenow === 'widgets.php' && current_user_can( 'edit_theme_options' ) ) {
		wp_safe_redirect( admin_url( 'admin.php?page=therum-widgets' ) );
		exit;
	}
} );

// Enqueue CSS + JS for native design pages (menus + widgets)
add_action( 'admin_enqueue_scripts', function() {
	$page = $_GET['page'] ?? '';
	if ( ! in_array( $page, [ 'therum-menus', 'therum-widgets' ], true ) ) return;
	$css_path = __DIR__ . '/assets/therum-native-design.css';
	$css_ver  = file_exists( $css_path ) ? filemtime( $css_path ) : null;
	wp_enqueue_style( 'therum-native-design', plugins_url( 'assets/therum-native-design.css', __FILE__ ), [], $css_ver );
	if ( $page === 'therum-menus' ) {
		foreach ( [ 'therum-native-menus-overview', 'therum-native-menus-detail' ] as $handle ) {
			$p = __DIR__ . "/assets/{$handle}.js";
			wp_enqueue_script( $handle, plugins_url( "assets/{$handle}.js", __FILE__ ), [], file_exists( $p ) ? filemtime( $p ) : null, true );
		}
	} else {
		$p = __DIR__ . '/assets/therum-native-widgets.js';
		wp_enqueue_script( 'therum-native-widgets', plugins_url( 'assets/therum-native-widgets.js', __FILE__ ), [], file_exists( $p ) ? filemtime( $p ) : null, true );
	}
} );


// ─────────────────────────────────────────────────────────────────────
// SHARED DESIGN TOKENS (printed once on Therum native pages)
// ─────────────────────────────────────────────────────────────────────
function therum_native_design_styles() { /* CSS enqueued via therum-native-design.css */ }

// ═════════════════════════════════════════════════════════════════════════════
//  MENUS
// ═════════════════════════════════════════════════════════════════════════════

function therum_render_native_menus() {
	$menus = wp_get_nav_menus();
	$locations = get_registered_nav_menus();
	$menu_locations = get_nav_menu_locations();

	$pages      = get_pages( [ 'number' => 100, 'sort_column' => 'post_title' ] );
	$posts      = get_posts( [ 'numberposts' => 30, 'post_status' => 'publish' ] );
	$categories = get_categories( [ 'hide_empty' => false, 'number' => 30 ] );

	therum_render_native_menus_overview( $menus, $locations, $menu_locations, $pages, $posts, $categories );
}

/**
 * Card-per-menu overview — one card per menu, items shown inline as draggable
 * rows. Matches the Therum demo's clean visual while preserving every AJAX
 * endpoint used by the original 3-col editor (create, add_items, save_order,
 * delete_item, set_location).
 */
function therum_render_native_menus_overview( $menus, $locations, $menu_locations, $pages, $posts, $categories ) {
	$nonce = wp_create_nonce( 'therum_menus' );
	// Fetch each menu's items exactly once and reuse the cache below — the render
	// loop previously called wp_get_nav_menu_items() a second time per menu (N+1).
	$items_by_menu = [];
	$total_items   = 0;
	foreach ( $menus as $m ) {
		$items_by_menu[ $m->term_id ] = wp_get_nav_menu_items( $m->term_id ) ?: [];
		$total_items += count( $items_by_menu[ $m->term_id ] );
	}
	?>

	<div class="thm-wrap thn" data-nonce="<?php echo esc_attr( $nonce ); ?>">
		<header class="thm-header">
			<div class="thm-header-left">
				<div class="thm-meta"><span class="thm-meta-dot"></span>DESIGN</div>
				<h1 class="thm-title">Menus</h1>
				<p class="thm-sub">Navigation menu builder.</p>
			</div>
			<div class="thm-actions">
				<button class="thm-btn thm-btn-primary" id="thm-new-menu">
					<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
					New Menu
				</button>
			</div>
		</header>

		<?php if ( ! $menus ) : ?>
			<div class="thm-card">
				<div class="thm-empty">
					<svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round" style="opacity:.4;margin-bottom:12px;"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="15" y2="12"/><line x1="3" y1="18" x2="18" y2="18"/></svg>
					<h3 style="margin:0 0 4px;font-size:15px;color:var(--tx,#0f172a);">No menus yet</h3>
					<p style="margin:0;font-size:13px;">Create your first menu to start adding pages, posts, and links.</p>
					<button class="thm-btn thm-btn-primary" onclick="document.getElementById('thm-new-menu').click()">Create menu</button>
				</div>
			</div>
		<?php else : foreach ( $menus as $menu ) :
			$menu_items = $items_by_menu[ $menu->term_id ] ?? ( wp_get_nav_menu_items( $menu->term_id ) ?: [] );
			$loc_for_menu = array_search( $menu->term_id, $menu_locations, true );
			$loc_label = $loc_for_menu && isset( $locations[ $loc_for_menu ] ) ? $locations[ $loc_for_menu ] : 'No location';
			$item_count = count( $menu_items );
			?>
			<section class="thm-card" data-menu-id="<?php echo esc_attr( $menu->term_id ); ?>">
				<div class="thm-card-head">
					<div style="min-width:0;flex:1;">
						<h2 class="thm-card-name"><?php echo esc_html( $menu->name ); ?></h2>
						<p class="thm-card-sub"><?php echo esc_html( $loc_label ); ?> · <?php echo (int) $item_count; ?> item<?php echo $item_count === 1 ? '' : 's'; ?></p>
					</div>
					<div class="thm-card-tools">
						<?php if ( $locations ) : ?>
						<select class="thm-loc-select thn-loc-select" data-location-for="<?php echo esc_attr( $menu->term_id ); ?>" title="Display location">
							<option value="">— No location —</option>
							<?php foreach ( $locations as $loc_slug => $loc_lbl ) : ?>
								<option value="<?php echo esc_attr( $loc_slug ); ?>" <?php selected( $loc_for_menu, $loc_slug ); ?>><?php echo esc_html( $loc_lbl ); ?></option>
							<?php endforeach; ?>
						</select>
						<?php endif; ?>
						<button class="thm-icon-btn thm-add-toggle" data-menu="<?php echo esc_attr( $menu->term_id ); ?>" title="Add items">
							<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
						</button>
						<button class="thm-icon-btn thm-save" data-menu="<?php echo esc_attr( $menu->term_id ); ?>" title="Save changes">
							<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/></svg>
						</button>
					</div>
				</div>

				<div class="thm-items">
					<?php if ( ! $menu_items ) : ?>
						<div class="thm-empty">
							<p style="margin:0;font-size:13px;">No items yet — click + to add pages, posts, or custom links.</p>
						</div>
					<?php else : ?>
						<ul class="thn-tree-list" id="thn-tree-<?php echo (int) $menu->term_id; ?>">
							<?php
							// Tag tree items with object type so the ::before content can render the right label
							ob_start();
							therum_render_menu_tree( $menu_items );
							$tree_html = ob_get_clean();
							// Inject data-type attribute by parsing item objects (simple regex enhancement)
							echo $tree_html;
							?>
						</ul>
					<?php endif; ?>
				</div>

				<!-- Add-items panel (collapsed by default) -->
				<div class="thm-add-panel" data-add-for="<?php echo esc_attr( $menu->term_id ); ?>">
					<div class="thm-tabs">
						<button class="thm-tab active" data-tab="pages-<?php echo (int) $menu->term_id; ?>">Pages</button>
						<button class="thm-tab" data-tab="posts-<?php echo (int) $menu->term_id; ?>">Posts</button>
						<button class="thm-tab" data-tab="cats-<?php echo (int) $menu->term_id; ?>">Categories</button>
						<button class="thm-tab" data-tab="custom-<?php echo (int) $menu->term_id; ?>">Custom</button>
					</div>

					<div data-panel="pages-<?php echo (int) $menu->term_id; ?>">
						<div class="thm-add-search">
							<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
							<input type="text" placeholder="Search pages…" data-search-list="thm-list-pages-<?php echo (int) $menu->term_id; ?>">
						</div>
						<div class="thm-add-list" data-list="thm-list-pages-<?php echo (int) $menu->term_id; ?>">
							<?php foreach ( $pages as $p ) : ?>
								<label class="thm-add-row" data-search-text="<?php echo esc_attr( strtolower( $p->post_title ) ); ?>">
									<input type="checkbox" data-add-type="post_type" data-add-object="page" data-add-id="<?php echo (int) $p->ID; ?>" data-add-title="<?php echo esc_attr( $p->post_title ); ?>" data-add-url="<?php echo esc_attr( get_permalink( $p->ID ) ); ?>">
									<span><?php echo esc_html( $p->post_title ); ?></span>
								</label>
							<?php endforeach; ?>
							<?php if ( ! $pages ) : ?><div class="thm-add-empty">No pages yet</div><?php endif; ?>
						</div>
					</div>

					<div data-panel="posts-<?php echo (int) $menu->term_id; ?>" style="display:none;">
						<div class="thm-add-search">
							<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
							<input type="text" placeholder="Search posts…" data-search-list="thm-list-posts-<?php echo (int) $menu->term_id; ?>">
						</div>
						<div class="thm-add-list" data-list="thm-list-posts-<?php echo (int) $menu->term_id; ?>">
							<?php foreach ( $posts as $p ) : ?>
								<label class="thm-add-row" data-search-text="<?php echo esc_attr( strtolower( $p->post_title ) ); ?>">
									<input type="checkbox" data-add-type="post_type" data-add-object="post" data-add-id="<?php echo (int) $p->ID; ?>" data-add-title="<?php echo esc_attr( $p->post_title ); ?>" data-add-url="<?php echo esc_attr( get_permalink( $p->ID ) ); ?>">
									<span><?php echo esc_html( $p->post_title ); ?></span>
								</label>
							<?php endforeach; ?>
							<?php if ( ! $posts ) : ?><div class="thm-add-empty">No posts yet</div><?php endif; ?>
						</div>
					</div>

					<div data-panel="cats-<?php echo (int) $menu->term_id; ?>" style="display:none;">
						<div class="thm-add-search">
							<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
							<input type="text" placeholder="Search categories…" data-search-list="thm-list-cats-<?php echo (int) $menu->term_id; ?>">
						</div>
						<div class="thm-add-list" data-list="thm-list-cats-<?php echo (int) $menu->term_id; ?>">
							<?php foreach ( $categories as $c ) : ?>
								<label class="thm-add-row" data-search-text="<?php echo esc_attr( strtolower( $c->name ) ); ?>">
									<input type="checkbox" data-add-type="taxonomy" data-add-object="category" data-add-id="<?php echo (int) $c->term_id; ?>" data-add-title="<?php echo esc_attr( $c->name ); ?>" data-add-url="<?php echo esc_attr( get_term_link( $c ) ); ?>">
									<span><?php echo esc_html( $c->name ); ?></span>
								</label>
							<?php endforeach; ?>
							<?php if ( ! $categories ) : ?><div class="thm-add-empty">No categories</div><?php endif; ?>
						</div>
					</div>

					<div data-panel="custom-<?php echo (int) $menu->term_id; ?>" style="display:none;">
						<div class="thm-custom-form">
							<input type="url" placeholder="https://example.com" class="thm-custom-url" data-menu="<?php echo (int) $menu->term_id; ?>">
							<input type="text" placeholder="Link label" class="thm-custom-label" data-menu="<?php echo (int) $menu->term_id; ?>">
						</div>
					</div>

					<div class="thm-add-foot">
						<button class="thm-btn thm-cancel-add">Cancel</button>
						<button class="thm-btn thm-btn-primary thm-add-confirm" data-menu="<?php echo (int) $menu->term_id; ?>">Add to menu</button>
					</div>
				</div>
			</section>
		<?php endforeach; endif; ?>
	</div>

	<div class="thm-toast" id="thm-toast"></div>

	<?php
}

// Legacy stub kept so anything still calling the old function path works
function therum_render_native_menus_legacy() {
	$menus = wp_get_nav_menus();
	$selected_menu_id = isset( $_GET['menu'] ) ? (int) $_GET['menu'] : ( $menus ? $menus[0]->term_id : 0 );
	$menu_items = $selected_menu_id ? wp_get_nav_menu_items( $selected_menu_id ) : [];
	$selected_menu = $selected_menu_id ? wp_get_nav_menu_object( $selected_menu_id ) : null;
	$locations = get_registered_nav_menus();
	$menu_locations = get_nav_menu_locations();

	$pages = get_pages( [ 'number' => 100, 'sort_column' => 'post_title' ] );
	$posts = get_posts( [ 'numberposts' => 30, 'post_status' => 'publish' ] );
	$categories = get_categories( [ 'hide_empty' => false, 'number' => 30 ] );

	?>

	<div class="thn" data-nonce="<?php echo esc_attr( wp_create_nonce( 'therum_menus' ) ); ?>" data-menu-id="<?php echo esc_attr( $selected_menu_id ); ?>">
		<div class="thn-page">
			<div class="thn-header">
				<div>
					<div class="thn-meta"><span class="thn-meta-dot"></span><?php echo count( $menus ); ?> MENU<?php echo count($menus) === 1 ? '' : 'S'; ?> · <?php echo count( $menu_items ); ?> ITEMS</div>
					<h1 class="thn-title">Menus</h1>
					<p class="thn-sub">Drag to reorder, drag right to nest, click to add items.</p>
				</div>
				<div class="thn-actions">
					<button class="thn-btn" id="thn-new-menu">
						<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
						New menu
					</button>
					<?php if ( $selected_menu_id ) : ?>
					<button class="thn-btn thn-btn-primary" id="thn-save-menu">
						<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/></svg>
						Save changes
					</button>
					<?php endif; ?>
				</div>
			</div>

			<?php if ( ! $menus ) : ?>
				<div class="thn-card" style="max-width: 560px; margin: 0 auto;">
					<div class="thn-create-bar">
						<div>
							<label class="thn-label">Menu name</label>
							<input type="text" class="thn-input" id="thn-create-name" placeholder="Main navigation" autofocus>
						</div>
						<button class="thn-btn thn-btn-primary" id="thn-create-btn" style="height: 36px; align-self: end;">Create</button>
					</div>
					<div class="thn-empty">
						<div class="thn-empty-icon"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="15" y2="12"/><line x1="3" y1="18" x2="18" y2="18"/></svg></div>
						<h3 class="thn-empty-title">No menus yet</h3>
						<p class="thn-empty-sub">Name your first menu and create it. You can add pages, posts, categories, and custom links once it exists.</p>
					</div>
				</div>
			<?php else : ?>

			<div class="thn-menus-grid">
				<!-- LEFT COLUMN -->
				<div style="display:flex; flex-direction:column; gap:16px;">
					<div class="thn-card">
						<div class="thn-card-head"><h3 class="thn-card-title">Your menus</h3></div>
						<div class="thn-picker">
							<?php foreach ( $menus as $menu ) :
								$loc_for_menu = array_search( $menu->term_id, $menu_locations, true );
								?>
								<a class="thn-picker-item <?php echo $menu->term_id === $selected_menu_id ? 'active' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=therum-menus&menu=' . $menu->term_id ) ); ?>">
									<span class="thn-picker-item-name"><?php echo esc_html( $menu->name ); ?></span>
									<?php if ( $loc_for_menu ) : ?><span class="thn-picker-item-loc"><?php echo esc_html( $loc_for_menu ); ?></span><?php endif; ?>
								</a>
							<?php endforeach; ?>
						</div>
					</div>

					<div class="thn-card">
						<div class="thn-card-head-stack">
							<h3 class="thn-card-title">Add items</h3>
							<p class="thn-card-sub">Pick what to add and tap below.</p>
						</div>
						<div class="thn-card-body" style="padding: 12px;">
							<div class="thn-tabs">
								<button class="thn-tab active" data-tab="pages">Pages</button>
								<button class="thn-tab" data-tab="posts">Posts</button>
								<button class="thn-tab" data-tab="cats">Cats</button>
								<button class="thn-tab" data-tab="custom">Custom</button>
							</div>

							<div data-panel="pages">
								<div class="thn-search-mini">
									<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
									<input type="text" placeholder="Search pages…" data-filter-list="pages-list">
								</div>
								<div class="thn-add-list" data-list="pages-list">
									<?php foreach ( $pages as $p ) : ?>
										<label class="thn-add-row" data-search-text="<?php echo esc_attr( strtolower( $p->post_title ) ); ?>">
											<input type="checkbox" data-add-type="post_type" data-add-object="page" data-add-id="<?php echo esc_attr( $p->ID ); ?>" data-add-title="<?php echo esc_attr( $p->post_title ); ?>" data-add-url="<?php echo esc_attr( get_permalink( $p->ID ) ); ?>">
											<span><?php echo esc_html( $p->post_title ); ?></span>
										</label>
									<?php endforeach; ?>
									<?php if ( ! $pages ) : ?><div class="thn-add-empty">No pages yet</div><?php endif; ?>
								</div>
							</div>

							<div data-panel="posts" style="display:none;">
								<div class="thn-search-mini">
									<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
									<input type="text" placeholder="Search posts…" data-filter-list="posts-list">
								</div>
								<div class="thn-add-list" data-list="posts-list">
									<?php foreach ( $posts as $p ) : ?>
										<label class="thn-add-row" data-search-text="<?php echo esc_attr( strtolower( $p->post_title ) ); ?>">
											<input type="checkbox" data-add-type="post_type" data-add-object="post" data-add-id="<?php echo esc_attr( $p->ID ); ?>" data-add-title="<?php echo esc_attr( $p->post_title ); ?>" data-add-url="<?php echo esc_attr( get_permalink( $p->ID ) ); ?>">
											<span><?php echo esc_html( $p->post_title ); ?></span>
										</label>
									<?php endforeach; ?>
									<?php if ( ! $posts ) : ?><div class="thn-add-empty">No posts yet</div><?php endif; ?>
								</div>
							</div>

							<div data-panel="cats" style="display:none;">
								<div class="thn-search-mini">
									<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
									<input type="text" placeholder="Search categories…" data-filter-list="cats-list">
								</div>
								<div class="thn-add-list" data-list="cats-list">
									<?php foreach ( $categories as $c ) : ?>
										<label class="thn-add-row" data-search-text="<?php echo esc_attr( strtolower( $c->name ) ); ?>">
											<input type="checkbox" data-add-type="taxonomy" data-add-object="category" data-add-id="<?php echo esc_attr( $c->term_id ); ?>" data-add-title="<?php echo esc_attr( $c->name ); ?>" data-add-url="<?php echo esc_attr( get_term_link( $c ) ); ?>">
											<span><?php echo esc_html( $c->name ); ?></span>
										</label>
									<?php endforeach; ?>
									<?php if ( ! $categories ) : ?><div class="thn-add-empty">No categories</div><?php endif; ?>
								</div>
							</div>

							<div data-panel="custom" style="display:none;">
								<div class="thn-custom-link-form">
									<input type="url" id="thn-custom-url" placeholder="https://example.com">
									<input type="text" id="thn-custom-label" placeholder="Link label">
								</div>
							</div>

							<button class="thn-btn thn-btn-primary thn-add-cta" id="thn-add-selected">Add to menu</button>
						</div>
					</div>

					<?php if ( $locations ) : ?>
					<div class="thn-card">
						<div class="thn-card-head-stack">
							<h3 class="thn-card-title">Display locations</h3>
							<p class="thn-card-sub">Where each menu shows in your theme.</p>
						</div>
						<div class="thn-loc-list">
							<?php foreach ( $locations as $loc_slug => $loc_label ) : ?>
								<div class="thn-loc-item">
									<span class="thn-loc-name"><?php echo esc_html( $loc_label ); ?></span>
									<select class="thn-loc-select" data-location="<?php echo esc_attr( $loc_slug ); ?>">
										<option value="0">— None —</option>
										<?php foreach ( $menus as $m ) : ?>
											<option value="<?php echo esc_attr( $m->term_id ); ?>" <?php selected( ( $menu_locations[ $loc_slug ] ?? 0 ), $m->term_id ); ?>><?php echo esc_html( $m->name ); ?></option>
										<?php endforeach; ?>
									</select>
								</div>
							<?php endforeach; ?>
						</div>
					</div>
					<?php endif; ?>
				</div>

				<!-- RIGHT COLUMN: tree -->
				<div class="thn-card">
					<div class="thn-card-head">
						<div>
							<h3 class="thn-card-title"><?php echo esc_html( $selected_menu->name ?? 'Menu' ); ?></h3>
							<p class="thn-card-sub" style="margin-top: 2px;">Drag the handle to reorder. Drag right to indent (nested submenu).</p>
						</div>
						<span class="thn-status" id="thn-status"></span>
					</div>
					<div class="thn-tree">
						<?php if ( ! $menu_items ) : ?>
							<div class="thn-empty">
								<div class="thn-empty-icon"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="15" y2="12"/><line x1="3" y1="18" x2="18" y2="18"/></svg></div>
								<h3 class="thn-empty-title">Empty menu</h3>
								<p class="thn-empty-sub">Pick items from the panel on the left and tap "Add to menu" to start building.</p>
							</div>
						<?php else : ?>
							<ul class="thn-tree-list" id="thn-tree-root">
								<?php therum_render_menu_tree( $menu_items ); ?>
							</ul>
						<?php endif; ?>
					</div>
				</div>
			</div>

			<?php endif; ?>
		</div>
	</div>

	<div class="thn-toast" id="thn-toast"></div>

	<?php
}

function therum_render_menu_tree( $items, $parent = 0 ) {
	$kids = array_filter( $items, fn( $i ) => (int) $i->menu_item_parent === $parent );
	usort( $kids, fn( $a, $b ) => $a->menu_order - $b->menu_order );
	if ( ! $kids ) return;
	foreach ( $kids as $item ) {
		$type_label = $item->object;
		?>
		<li class="thn-tree-item" data-item-id="<?php echo esc_attr( $item->ID ); ?>" draggable="true">
			<div class="thn-tree-item-bar">
				<svg class="thn-tree-item-handle" width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><circle cx="9" cy="6" r="1.3"/><circle cx="15" cy="6" r="1.3"/><circle cx="9" cy="12" r="1.3"/><circle cx="15" cy="12" r="1.3"/><circle cx="9" cy="18" r="1.3"/><circle cx="15" cy="18" r="1.3"/></svg>
				<div class="thn-tree-item-icon">
					<?php
					$icon_path = '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">';
					if ( $item->object === 'page' ) $icon_path .= '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/>';
					elseif ( $item->object === 'category' ) $icon_path .= '<path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/>';
					elseif ( $item->object === 'custom' ) $icon_path .= '<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>';
					else $icon_path .= '<path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4 12.5-12.5z"/>';
					$icon_path .= '</svg>';
					echo $icon_path;
					?>
				</div>
				<div class="thn-tree-item-info">
					<div class="thn-tree-item-title"><?php echo esc_html( $item->title ); ?></div>
					<div class="thn-tree-item-type"><?php echo esc_html( $type_label ); ?> · <?php echo esc_html( wp_parse_url( $item->url, PHP_URL_PATH ) ?: $item->url ); ?></div>
				</div>
				<div class="thn-tree-item-actions">
					<button class="thn-tree-item-action is-danger" data-action="delete-item" title="Remove">
						<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6M14 11v6"/></svg>
					</button>
				</div>
			</div>
			<?php
			$has_kids = !empty( array_filter( $items, fn( $i ) => (int) $i->menu_item_parent === (int) $item->ID ) );
			if ( $has_kids ) :
			?>
			<ul class="thn-tree-children">
				<?php therum_render_menu_tree( $items, (int) $item->ID ); ?>
			</ul>
			<?php endif; ?>
		</li>
		<?php
	}
}

// MENU AJAX HANDLERS
add_action( 'wp_ajax_therum_menu_create', function() {
	check_ajax_referer( 'therum_menus' );
	if ( ! current_user_can( 'edit_theme_options' ) ) wp_send_json_error( 'Forbidden' );
	$name = sanitize_text_field( $_POST['name'] ?? '' );
	if ( ! $name ) wp_send_json_error( 'Name required' );
	$id = wp_create_nav_menu( $name );
	if ( is_wp_error( $id ) ) wp_send_json_error( $id->get_error_message() );
	wp_send_json_success( [ 'menu_id' => $id ] );
} );

add_action( 'wp_ajax_therum_menu_add_items', function() {
	check_ajax_referer( 'therum_menus' );
	if ( ! current_user_can( 'edit_theme_options' ) ) wp_send_json_error( 'Forbidden' );
	$menu_id = (int) ( $_POST['menu_id'] ?? 0 );
	$items = json_decode( wp_unslash( $_POST['items'] ?? '[]' ), true );
	if ( ! $menu_id || ! is_array( $items ) ) wp_send_json_error( 'Bad input' );
	foreach ( $items as $item ) {
		$args = [
			'menu-item-title'  => sanitize_text_field( $item['title'] ?? '' ),
			'menu-item-status' => 'publish',
		];
		if ( ( $item['type'] ?? '' ) === 'custom' ) {
			$args['menu-item-type'] = 'custom';
			$args['menu-item-url']  = esc_url_raw( $item['url'] ?? '' );
		} else {
			$args['menu-item-type']      = $item['type'];
			$args['menu-item-object']    = $item['object'];
			$args['menu-item-object-id'] = (int) $item['object_id'];
		}
		wp_update_nav_menu_item( $menu_id, 0, $args );
	}
	wp_send_json_success( [ 'added' => count( $items ) ] );
} );

add_action( 'wp_ajax_therum_menu_save_order', function() {
	check_ajax_referer( 'therum_menus' );
	if ( ! current_user_can( 'edit_theme_options' ) ) wp_send_json_error( 'Forbidden' );
	$menu_id = (int) ( $_POST['menu_id'] ?? 0 );
	$order = json_decode( wp_unslash( $_POST['order'] ?? '[]' ), true );
	if ( ! $menu_id || ! is_array( $order ) ) wp_send_json_error( 'Bad input' );
	foreach ( $order as $entry ) {
		$id = (int) ( $entry['id'] ?? 0 );
		if ( ! $id ) continue;
		$existing = wp_setup_nav_menu_item( get_post( $id ) );
		wp_update_nav_menu_item( $menu_id, $id, [
			'menu-item-db-id'     => $id,
			'menu-item-object-id' => $existing->object_id,
			'menu-item-object'    => $existing->object,
			'menu-item-type'      => $existing->type,
			'menu-item-title'     => $existing->title,
			'menu-item-url'       => $existing->url,
			'menu-item-status'    => 'publish',
			'menu-item-parent-id' => (int) ( $entry['parent'] ?? 0 ),
			'menu-item-position'  => (int) ( $entry['position'] ?? 0 ) + 1,
		] );
	}
	wp_send_json_success();
} );

add_action( 'wp_ajax_therum_menu_delete_item', function() {
	check_ajax_referer( 'therum_menus' );
	if ( ! current_user_can( 'edit_theme_options' ) ) wp_send_json_error( 'Forbidden' );
	$id = (int) ( $_POST['item_id'] ?? 0 );
	if ( ! $id ) wp_send_json_error( 'Bad input' );
	wp_delete_post( $id, true );
	wp_send_json_success();
} );

add_action( 'wp_ajax_therum_menu_set_location', function() {
	check_ajax_referer( 'therum_menus' );
	if ( ! current_user_can( 'edit_theme_options' ) ) wp_send_json_error( 'Forbidden' );
	$loc = sanitize_text_field( $_POST['location'] ?? '' );
	$menu_id = (int) ( $_POST['menu_id'] ?? 0 );
	if ( ! $loc ) wp_send_json_error( 'Bad input' );
	$locations = get_nav_menu_locations();
	if ( $menu_id ) $locations[ $loc ] = $menu_id;
	else unset( $locations[ $loc ] );
	set_theme_mod( 'nav_menu_locations', $locations );
	wp_send_json_success();
} );


// ═════════════════════════════════════════════════════════════════════════════
//  WIDGETS
// ═════════════════════════════════════════════════════════════════════════════

function therum_render_native_widgets() {
	global $wp_registered_sidebars, $wp_registered_widgets;
	$sidebars_widgets = wp_get_sidebars_widgets();
	$active_sidebars = array_filter( $wp_registered_sidebars ?: [], fn( $sb ) => $sb['id'] !== 'wp_inactive_widgets' );

	$available = [];
	foreach ( $wp_registered_widgets as $id => $widget ) {
		$available[] = [
			'id'   => $id,
			'name' => $widget['name'] ?? $id,
			'desc' => isset( $widget['description'] ) ? wp_strip_all_tags( $widget['description'] ) : '',
		];
	}
	usort( $available, fn( $a, $b ) => strcmp( $a['name'], $b['name'] ) );

	?>

	<div class="thn" data-nonce="<?php echo esc_attr( wp_create_nonce( 'therum_widgets' ) ); ?>">
		<div class="thn-page">
			<div class="thn-header">
				<div>
					<div class="thn-meta"><span class="thn-meta-dot"></span><?php echo count( $active_sidebars ); ?> REGIONS · <?php echo count( $available ); ?> AVAILABLE WIDGETS</div>
					<h1 class="thn-title">Widgets</h1>
					<p class="thn-sub">Click a region in the page wireframe, then pick a widget to drop in.</p>
				</div>
			</div>

			<?php if ( ! $active_sidebars ) : ?>
				<div class="thn-card">
					<div class="thn-empty">
						<div class="thn-empty-icon"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/></svg></div>
						<h3 class="thn-empty-title">No widget regions registered</h3>
						<p class="thn-empty-sub">Your active theme doesn't expose any widget regions. Most modern themes (Bricks included) build their layouts visually instead.</p>
					</div>
				</div>
			<?php else : ?>

			<div class="thw-grid">
				<!-- LEFT: wireframe stage -->
				<div class="thw-stage">
					<div class="thw-stage-label">PAGE WIREFRAME · LIVE</div>
					<div class="thw-shell">
						<div class="thw-fake">Site Header</div>

						<?php
						// Group sidebars: footer-* go in footer row, rest go in content row
						$footer_regions = [];
						$other_regions = [];
						foreach ( $active_sidebars as $sb_id => $sb ) {
							if ( strpos( strtolower( $sb_id ), 'footer' ) !== false ) $footer_regions[ $sb_id ] = $sb;
							else $other_regions[ $sb_id ] = $sb;
						}

						$other_count = count( $other_regions );
						$row_class = $other_count === 0 ? '' : 'thw-row-content-side';
						?>

						<?php if ( $other_count > 0 ) : ?>
							<div class="thw-row <?php echo esc_attr( $row_class ); ?>">
								<div class="thw-fake thw-fake-content">Page content</div>
								<div style="display: flex; flex-direction: column; gap: 14px;">
									<?php foreach ( $other_regions as $sb_id => $sb ) {
										therum_render_widget_region( $sb_id, $sb, $sidebars_widgets[ $sb_id ] ?? [], $wp_registered_widgets );
									} ?>
								</div>
							</div>
						<?php else : ?>
							<div class="thw-fake thw-fake-content">Page content</div>
						<?php endif; ?>

						<?php if ( $footer_regions ) :
							$fcount = count( $footer_regions );
							$frow_class = $fcount >= 4 ? 'thw-row-4' : ( $fcount === 3 ? 'thw-row-3' : ( $fcount === 2 ? 'thw-row-2' : '' ) );
							?>
							<div class="thw-row <?php echo esc_attr( $frow_class ); ?>">
								<?php foreach ( $footer_regions as $sb_id => $sb ) {
									therum_render_widget_region( $sb_id, $sb, $sidebars_widgets[ $sb_id ] ?? [], $wp_registered_widgets );
								} ?>
							</div>
						<?php endif; ?>

						<div class="thw-fake">Site Footer</div>
					</div>
				</div>

				<!-- RIGHT: widget palette -->
				<div class="thw-side">
					<div class="thw-side-head">
						<h3 class="thw-side-title">Available widgets</h3>
						<p class="thw-side-sub" id="thw-side-sub">Pick a region in the wireframe first.</p>
					</div>
					<div class="thw-side-search">
						<div class="thw-side-search-wrap">
							<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
							<input type="text" id="thw-search" placeholder="Search widgets…">
						</div>
					</div>
					<div class="thw-side-list">
						<?php foreach ( $available as $w ) : ?>
							<div class="thw-avail-item disabled" data-widget-id="<?php echo esc_attr( $w['id'] ); ?>" data-widget-search="<?php echo esc_attr( strtolower( $w['name'] . ' ' . $w['desc'] ) ); ?>">
								<div class="thw-avail-name"><?php echo esc_html( $w['name'] ); ?></div>
								<?php if ( $w['desc'] ) : ?><div class="thw-avail-desc"><?php echo esc_html( $w['desc'] ); ?></div><?php endif; ?>
							</div>
						<?php endforeach; ?>
					</div>
				</div>
			</div>

			<?php endif; ?>
		</div>
	</div>

	<div class="thn-toast" id="thn-toast"></div>

	<?php
}

function therum_render_widget_region( $sb_id, $sb, $widgets, $registered ) {
	?>
	<div class="thw-region <?php echo $widgets ? 'has-widgets' : ''; ?>" data-sidebar="<?php echo esc_attr( $sb_id ); ?>" data-sidebar-name="<?php echo esc_attr( $sb['name'] ); ?>">
		<div class="thw-region-head">
			<span class="thw-region-name"><?php echo esc_html( $sb['name'] ); ?></span>
			<span class="thw-region-count"><?php echo count( $widgets ); ?> widget<?php echo count($widgets) === 1 ? '' : 's'; ?></span>
		</div>
		<?php if ( ! $widgets ) : ?>
			<div class="thw-region-empty">
				<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
				<span>Click to add widget</span>
			</div>
		<?php else : ?>
			<div class="thw-widgets-list">
				<?php foreach ( $widgets as $widget_id ) :
					$w = $registered[ $widget_id ] ?? null;
					if ( ! $w ) continue;
					?>
					<div class="thw-widget-item" data-widget-id="<?php echo esc_attr( $widget_id ); ?>">
						<svg class="thw-widget-handle" width="11" height="11" viewBox="0 0 24 24" fill="currentColor"><circle cx="9" cy="6" r="1.3"/><circle cx="15" cy="6" r="1.3"/><circle cx="9" cy="12" r="1.3"/><circle cx="15" cy="12" r="1.3"/><circle cx="9" cy="18" r="1.3"/><circle cx="15" cy="18" r="1.3"/></svg>
						<span class="thw-widget-name"><?php echo esc_html( $w['name'] ); ?></span>
						<button class="thw-widget-remove" data-action="remove-widget" data-sidebar="<?php echo esc_attr( $sb_id ); ?>">
							<svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
						</button>
					</div>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>
	</div>
	<?php
}

// WIDGET AJAX HANDLERS
add_action( 'wp_ajax_therum_widget_add', function() {
	check_ajax_referer( 'therum_widgets' );
	if ( ! current_user_can( 'edit_theme_options' ) ) wp_send_json_error( 'Forbidden' );
	$sidebar = sanitize_text_field( $_POST['sidebar'] ?? '' );
	$widget_id = sanitize_text_field( $_POST['widget_id'] ?? '' );
	if ( ! $sidebar || ! $widget_id ) wp_send_json_error( 'Bad input' );
	$sidebars_widgets = wp_get_sidebars_widgets();
	if ( ! isset( $sidebars_widgets[ $sidebar ] ) ) $sidebars_widgets[ $sidebar ] = [];
	$sidebars_widgets[ $sidebar ][] = $widget_id;
	wp_set_sidebars_widgets( $sidebars_widgets );
	wp_send_json_success();
} );

add_action( 'wp_ajax_therum_widget_remove', function() {
	check_ajax_referer( 'therum_widgets' );
	if ( ! current_user_can( 'edit_theme_options' ) ) wp_send_json_error( 'Forbidden' );
	$sidebar = sanitize_text_field( $_POST['sidebar'] ?? '' );
	$widget_id = sanitize_text_field( $_POST['widget_id'] ?? '' );
	if ( ! $sidebar || ! $widget_id ) wp_send_json_error( 'Bad input' );
	$sidebars_widgets = wp_get_sidebars_widgets();
	if ( isset( $sidebars_widgets[ $sidebar ] ) ) {
		$sidebars_widgets[ $sidebar ] = array_values( array_filter( $sidebars_widgets[ $sidebar ], fn( $id ) => $id !== $widget_id ) );
		wp_set_sidebars_widgets( $sidebars_widgets );
	}
	wp_send_json_success();
} );

// ════════════════════════════════════════════════════════════════════════
// MOTION ENGINE (Lenis, view transitions, stagger) — from therum-motion.php
// ════════════════════════════════════════════════════════════════════════

if ( ! defined( 'ABSPATH' ) ) exit;


// ─── Disable check ───────────────────────────────────────────────────────────
function therum_motion_enabled(): bool {
	if ( ! get_option( 'th_motion_enabled', true ) ) return false;
	return true;
}


// ─── Inject motion into all Therum admin pages ───────────────────────────────
add_action( 'admin_enqueue_scripts', function() {
	if ( ! therum_motion_enabled() ) return;
	if ( ! is_admin() ) return;

	$page = $_GET['page'] ?? '';
	$is_therum_page = strpos( $page, 'therum-' ) === 0 || in_array( $page, ['therum'], true );

	// Also load on post.php / post-new.php / user-new.php — anywhere we skin
	$skinned_screens = [ 'post.php', 'post-new.php', 'user-new.php', 'user-edit.php', 'profile.php', 'edit.php', 'options-general.php', 'edit-tags.php', 'term.php', 'edit-comments.php', 'comment.php' ];
	global $pagenow;
	$is_skinned = in_array( $pagenow, $skinned_screens, true );

	if ( ! $is_therum_page && ! $is_skinned ) return;

	// Lenis from CDN — battle-tested smooth scroll, ~6KB gzipped
	wp_enqueue_script(
		'therum-lenis',
		'https://cdn.jsdelivr.net/npm/lenis@1.1.20/dist/lenis.min.js',
		[],
		'1.1.20',
		false  // load in head so it runs before paint
	);
});


// ─── Add motion CSS + bootstrap JS to every Therum admin page ────────────────
add_action( 'admin_enqueue_scripts', function() {
	if ( ! therum_motion_enabled() ) return;
	$css_path = __DIR__ . '/assets/therum-motion.css';
	wp_enqueue_style( 'therum-motion', plugins_url( 'assets/therum-motion.css', __FILE__ ), [], file_exists( $css_path ) ? filemtime( $css_path ) : null );
} );



// ─── Bootstrap JS — runs Lenis, sets up view transitions + magnetic hover ────
add_action( 'admin_enqueue_scripts', function() {
	if ( ! therum_motion_enabled() ) return;
	$js_path = __DIR__ . '/assets/therum-motion.js';
	wp_enqueue_script( 'therum-motion', plugins_url( 'assets/therum-motion.js', __FILE__ ), [], file_exists( $js_path ) ? filemtime( $js_path ) : null, true );
} );



// ─── Add Motion toggle to Settings → Appearance ──────────────────────────────
// (does NOT modify therum-settings.php; instead injects via filter)
add_action( 'admin_footer-toplevel_page_therum-settings', function() {
	if ( ($_GET['section'] ?? '') !== 'appearance' ) return;
	$enabled = (bool) get_option( 'th_motion_enabled', true );
	$nonce = wp_create_nonce( 'therum_motion_toggle' );
	?>
<script>
(function() {
	// Inject motion toggle into appearance section if it isn't there
	if (document.querySelector('[data-th-motion-toggle]')) return;

	// Find the last settings-group in appearance and append our group
	var groups = document.querySelectorAll('.th-settings-content .th-settings-group');
	if (!groups.length) return;
	var last = groups[groups.length - 1];

	var html = '' +
		'<div class="th-settings-group">' +
		'  <div class="th-settings-group-header">' +
		'    <div class="th-settings-group-title">Motion</div>' +
		'    <div class="th-settings-group-sub">Page transitions, reveals, and magnetic hover. Respects reduced-motion preference automatically.</div>' +
		'  </div>' +
		'  <div class="th-settings-group-body">' +
		'    <div class="th-setting-row">' +
		'      <div class="th-setting-label">' +
		'        <div class="th-setting-name">Enable motion</div>' +
		'        <div class="th-setting-help">Smooth scroll, soft page loads, staggered card reveals, magnetic CTAs.</div>' +
		'      </div>' +
		'      <div class="th-setting-control">' +
		'        <button type="button" class="th-toggle <?php echo $enabled ? 'on' : ''; ?>" data-th-motion-toggle aria-pressed="<?php echo $enabled ? 'true' : 'false'; ?>"><span class="th-toggle-knob"></span></button>' +
		'      </div>' +
		'    </div>' +
		'  </div>' +
		'</div>';

	last.insertAdjacentHTML('afterend', html);

	// Wire the toggle
	var btn = document.querySelector('[data-th-motion-toggle]');
	btn.addEventListener('click', function() {
		var on = !btn.classList.contains('on');
		btn.classList.toggle('on', on);
		btn.setAttribute('aria-pressed', on ? 'true' : 'false');
		var fd = new FormData();
		fd.append('action', 'therum_motion_save');
		fd.append('nonce', '<?php echo esc_js( $nonce ); ?>');
		fd.append('enabled', on ? '1' : '0');
		fetch(window.ajaxurl || '/wp-admin/admin-ajax.php', { method:'POST', credentials:'same-origin', body: fd })
			.then(function() {
				setTimeout(function() { location.reload(); }, 200);
			});
	});
})();
</script>
<?php
});


// ─── AJAX endpoint to save motion toggle ─────────────────────────────────────
add_action( 'wp_ajax_therum_motion_save', function() {
	if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'forbidden', 403 );
	check_ajax_referer( 'therum_motion_toggle', 'nonce' );

	$enabled = ! empty( $_POST['enabled'] ) && $_POST['enabled'] === '1';
	update_option( 'th_motion_enabled', $enabled );
	wp_send_json_success([ 'enabled' => $enabled ]);
});


// ════════════════════════════════════════════════════════════════════════════
//  THEMES — 24 presets + palette CSS — from therum-themes.php
// ════════════════════════════════════════════════════════════════════════════

class Therum_Themes {

	const SITE_OPTION_KEY  = 'therum_theme_state';
	const USER_META_KEY    = 'therum_theme_state';

	public static function presets(): array {
		return array_merge( self::foundations(), self::desktop() );
	}

	/**
	 * THE 5 DESKTOP THEMES (D1–D5). Each reskins the admin chrome after a real
	 * OS surface — the admin experience changes drastically per theme. Signature
	 * CSS lives in assets/therum-theme-desktop.css keyed by `body.theme-dk-*`.
	 */
	public static function desktop(): array {
		return [
			'dk-sonoma'   => [ 'name' => 'D1 · Sonoma',   'desc' => 'macOS — translucent vibrancy, traffic-light dots, rounded glass.',   'group' => 'desktop', 'mode' => 'light', 'accent' => '#007AFF', 'density' => 'comfortable', 'sidebar' => 'full', 'sidebarStyle' => 'default', 'font' => 'system', 'radius' => 'large',  'shadow' => 'soft', 'glass' => true,  'bgImage' => 'none', 'palette' => 'dk-sonoma',   'previewMain' => '#E9E7F0', 'previewRail' => '#007AFF' ],
			'dk-cupertino'=> [ 'name' => 'D2 · Cupertino','desc' => 'iOS — bright cards, system blue, large pill controls, springy.',     'group' => 'desktop', 'mode' => 'light', 'accent' => '#0A84FF', 'density' => 'breathing',   'sidebar' => 'full', 'sidebarStyle' => 'minimal', 'font' => 'system', 'radius' => 'pilly',  'shadow' => 'soft', 'glass' => false, 'bgImage' => 'none', 'palette' => 'dk-cupertino','previewMain' => '#F2F2F7', 'previewRail' => '#0A84FF' ],
			'dk-fluent'   => [ 'name' => 'D3 · Fluent',   'desc' => 'Windows 11 — mica acrylic, restrained accent, square-ish cards.',    'group' => 'desktop', 'mode' => 'light', 'accent' => '#0067C0', 'density' => 'comfortable', 'sidebar' => 'full', 'sidebarStyle' => 'default', 'font' => 'system', 'radius' => 'medium', 'shadow' => 'soft', 'glass' => true,  'bgImage' => 'none', 'palette' => 'dk-fluent',   'previewMain' => '#EDEBF0', 'previewRail' => '#0067C0' ],
			'dk-visionos' => [ 'name' => 'D4 · visionOS', 'desc' => 'Spatial — deep ultra-frosted glass floating on dark, white text.',   'group' => 'desktop', 'mode' => 'dark',  'accent' => '#E5E5EA', 'density' => 'breathing',   'sidebar' => 'full', 'sidebarStyle' => 'default', 'font' => 'system', 'radius' => 'pilly',  'shadow' => 'soft', 'glass' => true,  'bgImage' => 'none', 'palette' => 'dk-visionos', 'previewMain' => '#15171C', 'previewRail' => '#C9CBD6' ],
			'dk-hud'      => [ 'name' => 'D5 · Sentinel HUD', 'desc' => 'Dark HUD — near-black, neon cyan glow, mono labels, sharp edges.', 'group' => 'desktop', 'mode' => 'dark',  'accent' => '#00E5FF', 'density' => 'comfortable', 'sidebar' => 'full', 'sidebarStyle' => 'solid',   'font' => 'mono',   'radius' => 'sharp',  'shadow' => 'flat', 'glass' => false, 'bgImage' => 'none', 'palette' => 'dk-hud',      'previewMain' => '#070A0E', 'previewRail' => '#00E5FF' ],
		];
	}

	/**
	 * THE 15 FOUNDATION THEMES. Each recreates one of the reference dashboards —
	 * not a recolor of one layout, but its own palette + signature chrome
	 * (canvas, card surface, depth, accent behaviour, type). The signature CSS
	 * lives in assets/therum-theme-foundations.css keyed by `body.theme-fd-*`.
	 */
	public static function foundations(): array {
		return [
			'fd-warm'    => [ 'name' => '01 · Warm',    'desc' => 'Cream canvas, gold accent, soft rounded cards. HR / Crextio.', 'group' => 'foundations', 'mode' => 'light', 'accent' => '#E6A817', 'density' => 'comfortable', 'sidebar' => 'full', 'sidebarStyle' => 'default', 'font' => 'inter', 'radius' => 'large', 'shadow' => 'soft', 'glass' => false, 'bgImage' => 'none', 'palette' => 'fd-warm',    'previewMain' => '#FBF8F1', 'previewRail' => '#E6A817' ],
			'fd-coral'   => [ 'name' => '02 · Coral',   'desc' => 'Cool neutral, coral pop, data-rich bento. Financial / N2.',  'group' => 'foundations', 'mode' => 'light', 'accent' => '#EE5340', 'density' => 'comfortable', 'sidebar' => 'full', 'sidebarStyle' => 'default', 'font' => 'inter', 'radius' => 'medium', 'shadow' => 'soft', 'glass' => false, 'bgImage' => 'none', 'palette' => 'fd-coral',   'previewMain' => '#F2F2F3', 'previewRail' => '#EE5340' ],
			'fd-float'   => [ 'name' => '03 · Float',   'desc' => 'Tinted canvas, floating white cards. Twisty.',               'group' => 'foundations', 'mode' => 'light', 'accent' => '#4F6BED', 'density' => 'breathing',   'sidebar' => 'full', 'sidebarStyle' => 'default', 'font' => 'inter', 'radius' => 'large', 'shadow' => 'soft', 'glass' => false, 'bgImage' => 'none', 'palette' => 'fd-float',   'previewMain' => '#E4E7F0', 'previewRail' => '#4F6BED' ],
			'fd-sage'    => [ 'name' => '04 · Sage',    'desc' => 'Sage canvas, forest accent, multitone donuts. Property.',     'group' => 'foundations', 'mode' => 'light', 'accent' => '#2F855A', 'density' => 'comfortable', 'sidebar' => 'full', 'sidebarStyle' => 'default', 'font' => 'inter', 'radius' => 'large', 'shadow' => 'soft', 'glass' => false, 'bgImage' => 'none', 'palette' => 'fd-sage',    'previewMain' => '#E8F0E9', 'previewRail' => '#2F855A' ],
			'fd-lilac'   => [ 'name' => '05 · Lilac',   'desc' => 'Lavender wash, soft purple gradient surfaces. AI marketing.', 'group' => 'foundations', 'mode' => 'light', 'accent' => '#7C5CFF', 'density' => 'comfortable', 'sidebar' => 'full', 'sidebarStyle' => 'default', 'font' => 'inter', 'radius' => 'large', 'shadow' => 'soft', 'glass' => false, 'bgImage' => 'none', 'palette' => 'fd-lilac',   'previewMain' => '#EEE9FB', 'previewRail' => '#7C5CFF' ],
			'fd-blocks'  => [ 'name' => '06 · Blocks',  'desc' => 'Solid colour-block cards, bold. Crypto forecast.',            'group' => 'foundations', 'mode' => 'light', 'accent' => '#111111', 'density' => 'comfortable', 'sidebar' => 'full', 'sidebarStyle' => 'solid',   'font' => 'inter', 'radius' => 'large', 'shadow' => 'flat', 'glass' => false, 'bgImage' => 'none', 'palette' => 'fd-blocks',  'previewMain' => '#EFF0EC', 'previewRail' => '#9BE26A' ],
			'fd-mono'    => [ 'name' => '07 · Mono',    'desc' => 'Black, white, one accent, huge whitespace. Limitus.',         'group' => 'foundations', 'mode' => 'light', 'accent' => '#111111', 'density' => 'breathing',   'sidebar' => 'full', 'sidebarStyle' => 'minimal', 'font' => 'inter', 'radius' => 'medium', 'shadow' => 'flat', 'glass' => false, 'bgImage' => 'none', 'palette' => 'fd-mono',    'previewMain' => '#FAFAFA', 'previewRail' => '#111111' ],
			'fd-tactile' => [ 'name' => '08 · Tactile', 'desc' => 'Frosted white, coral-pink gradient surfaces. Milkinside.',    'group' => 'foundations', 'mode' => 'light', 'accent' => '#FF6F91', 'density' => 'comfortable', 'sidebar' => 'full', 'sidebarStyle' => 'default', 'font' => 'inter', 'radius' => 'large', 'shadow' => 'soft', 'glass' => false, 'bgImage' => 'none', 'palette' => 'fd-tactile', 'previewMain' => '#F4F1F4', 'previewRail' => '#FF6F91' ],
			'fd-violet'  => [ 'name' => '09 · Violet',  'desc' => 'Near-black, violet gradient, glowing charts. Stakent.',        'group' => 'foundations', 'mode' => 'dark',  'accent' => '#7C5CFF', 'density' => 'comfortable', 'sidebar' => 'full', 'sidebarStyle' => 'default', 'font' => 'inter', 'radius' => 'medium', 'shadow' => 'soft', 'glass' => false, 'bgImage' => 'none', 'palette' => 'fd-violet',  'previewMain' => '#0C0C10', 'previewRail' => '#7C5CFF' ],
			'fd-glassblue' => [ 'name' => '10 · Glass Blue', 'desc' => 'Navy, glass cards, blue gradient hero. LunoX.',           'group' => 'foundations', 'mode' => 'dark',  'accent' => '#3B82F6', 'density' => 'comfortable', 'sidebar' => 'full', 'sidebarStyle' => 'default', 'font' => 'inter', 'radius' => 'large', 'shadow' => 'soft', 'glass' => true,  'bgImage' => 'none', 'palette' => 'fd-glassblue', 'previewMain' => '#0E1424', 'previewRail' => '#3B82F6' ],
			'fd-spatial' => [ 'name' => '11 · Spatial', 'desc' => 'Dark teal, frosted glass panels. Smart home.',               'group' => 'foundations', 'mode' => 'dark',  'accent' => '#34D399', 'density' => 'breathing',   'sidebar' => 'full', 'sidebarStyle' => 'default', 'font' => 'inter', 'radius' => 'large', 'shadow' => 'soft', 'glass' => true,  'bgImage' => 'none', 'palette' => 'fd-spatial', 'previewMain' => '#1C2422', 'previewRail' => '#34D399' ],
			'fd-onyx'    => [ 'name' => '12 · Onyx',    'desc' => 'Navy shell, light floating cards. Restaurant ops.',           'group' => 'foundations', 'mode' => 'dark',  'accent' => '#6366F1', 'density' => 'comfortable', 'sidebar' => 'full', 'sidebarStyle' => 'default', 'font' => 'inter', 'radius' => 'medium', 'shadow' => 'soft', 'glass' => false, 'bgImage' => 'none', 'palette' => 'fd-onyx',    'previewMain' => '#0E1020', 'previewRail' => '#6366F1' ],
			'fd-sentinel' => [ 'name' => '13 · Sentinel', 'desc' => 'Dark, purple/green glow gauges, dense. Security.',          'group' => 'foundations', 'mode' => 'dark',  'accent' => '#8B5CF6', 'density' => 'comfortable', 'sidebar' => 'full', 'sidebarStyle' => 'default', 'font' => 'inter', 'radius' => 'medium', 'shadow' => 'soft', 'glass' => false, 'bgImage' => 'none', 'palette' => 'fd-sentinel', 'previewMain' => '#0B0E14', 'previewRail' => '#8B5CF6' ],
			'fd-aurora'  => [ 'name' => '14 · Aurora',  'desc' => 'Warm corner gradient wash, icon rail. Panze.',                'group' => 'foundations', 'mode' => 'light', 'accent' => '#7C3AED', 'density' => 'comfortable', 'sidebar' => 'icons', 'sidebarStyle' => 'default', 'font' => 'inter', 'radius' => 'large', 'shadow' => 'soft', 'glass' => false, 'bgImage' => 'none', 'palette' => 'fd-aurora',  'previewMain' => '#F3EEFB', 'previewRail' => '#7C3AED' ],
			'fd-canvas'  => [ 'name' => '15 · Canvas',  'desc' => 'Photographic backdrop, frosted glass cards. SolarEn.',        'group' => 'foundations', 'mode' => 'light', 'accent' => '#14B8A6', 'density' => 'comfortable', 'sidebar' => 'full', 'sidebarStyle' => 'default', 'font' => 'inter', 'radius' => 'large', 'shadow' => 'soft', 'glass' => true,  'bgImage' => 'none', 'palette' => 'fd-canvas',  'previewMain' => '#DDE7E5', 'previewRail' => '#14B8A6' ],
		];
	}

	/** @deprecated Old 60-preset library — no longer surfaced (replaced by foundations()). */
	public static function _legacy_presets(): array {
		return [
			// ── Studio · New — the 2025 dashboard-study foundation themes ──────
			'warm' => [
				'name' => '01 · Warm', 'desc' => 'Cream canvas, gold accent. Human, airy HR/SaaS.', 'group' => 'studio-new',
				'mode' => 'light', 'accent' => '#E6A817', 'density' => 'comfortable',
				'sidebar' => 'full', 'sidebarStyle' => 'default', 'font' => 'inter', 'radius' => 'large', 'shadow' => 'soft',
				'glass' => false, 'bgImage' => 'none', 'palette' => 'warm',
				'previewMain' => '#FBF8F1', 'previewRail' => '#E6A817',
			],
			'coral' => [
				'name' => '02 · Coral', 'desc' => 'Cool neutral, coral pop. Data-rich fintech.', 'group' => 'studio-new',
				'mode' => 'light', 'accent' => '#EE5340', 'density' => 'comfortable',
				'sidebar' => 'full', 'sidebarStyle' => 'default', 'font' => 'inter', 'radius' => 'medium', 'shadow' => 'soft',
				'glass' => false, 'bgImage' => 'none', 'palette' => 'coral',
				'previewMain' => '#F2F2F3', 'previewRail' => '#EE5340',
			],
			'float' => [
				'name' => '03 · Float', 'desc' => 'Tinted canvas, floating white cards. Calm premium.', 'group' => 'studio-new',
				'mode' => 'light', 'accent' => '#4F6BED', 'density' => 'breathing',
				'sidebar' => 'full', 'sidebarStyle' => 'default', 'font' => 'inter', 'radius' => 'large', 'shadow' => 'soft',
				'glass' => false, 'bgImage' => 'none', 'palette' => 'float',
				'previewMain' => '#E4E7F0', 'previewRail' => '#4F6BED',
			],
			'monochrome' => [
				'name' => '07 · Mono', 'desc' => 'Black, white, one accent. Editorial minimal.', 'group' => 'studio-new',
				'mode' => 'light', 'accent' => '#111111', 'density' => 'comfortable',
				'sidebar' => 'full', 'sidebarStyle' => 'minimal', 'font' => 'inter', 'radius' => 'medium', 'shadow' => 'flat',
				'glass' => false, 'bgImage' => 'none', 'palette' => 'monochrome', 'cardStyle' => 'bare',
				'previewMain' => '#FAFAFA', 'previewRail' => '#111111',
			],
			'violet' => [
				'name' => '09 · Violet', 'desc' => 'Near-black, violet gradient. Premium dark crypto.', 'group' => 'studio-new',
				'mode' => 'dark', 'accent' => '#7C5CFF', 'density' => 'comfortable',
				'sidebar' => 'full', 'sidebarStyle' => 'default', 'font' => 'inter', 'radius' => 'medium', 'shadow' => 'soft',
				'glass' => false, 'bgImage' => 'none', 'palette' => 'violet',
				'previewMain' => '#0C0C10', 'previewRail' => '#7C5CFF',
			],
			'spatial' => [
				'name' => '11 · Spatial', 'desc' => 'Dark teal, frosted glass. Calm spatial IoT.', 'group' => 'studio-new',
				'mode' => 'dark', 'accent' => '#34D399', 'density' => 'breathing',
				'sidebar' => 'full', 'sidebarStyle' => 'default', 'font' => 'inter', 'radius' => 'large', 'shadow' => 'soft',
				'glass' => true, 'bgImage' => 'none', 'palette' => 'spatial',
				'previewMain' => '#1C2422', 'previewRail' => '#34D399',
			],

			'studio' => [
				'name' => 'Studio', 'desc' => 'Bamleon front-end. Pitch black. Hot-pink pop.', 'group' => 'foundations',
				'mode' => 'dark', 'accent' => '#f5389a', 'density' => 'standard',
				'sidebar' => 'full', 'sidebarStyle' => 'default', 'font' => 'inter', 'radius' => 'medium', 'shadow' => 'soft',
				'glass' => false, 'bgImage' => 'none', 'palette' => 'studio',
				'previewMain' => '#0a0a0a', 'previewRail' => '#f5389a',
			],
			'editorial' => [
				'name' => 'Editorial', 'desc' => 'Long-form. Roomy. Reads like Substack.', 'group' => 'foundations',
				'mode' => 'light', 'accent' => '#1f2937', 'density' => 'comfortable',
				'sidebar' => 'compact', 'sidebarStyle' => 'minimal', 'font' => 'crimson', 'radius' => 'sharp', 'shadow' => 'flat',
				'glass' => false, 'bgImage' => 'none', 'palette' => 'editorial', 'cardStyle' => 'bare',
				'previewMain' => '#fdfcf7', 'previewRail' => '#1f2937',
			],
			'brutal' => [
				'name' => 'Brutal', 'desc' => 'Sharp edges. High contrast. No BS.', 'group' => 'foundations',
				'mode' => 'light', 'accent' => '#000000', 'density' => 'compact',
				'sidebar' => 'full', 'sidebarStyle' => 'solid', 'font' => 'archivo', 'radius' => 'sharp', 'shadow' => 'flat',
				'glass' => false, 'bgImage' => 'none', 'palette' => 'brutal', 'cardStyle' => 'flat',
				'previewMain' => '#ffffff', 'previewRail' => '#000000',
			],
			'calm' => [
				'name' => 'Calm', 'desc' => 'Soft. Slow. Built for focus.', 'group' => 'foundations',
				'mode' => 'light', 'accent' => '#10b981', 'density' => 'comfortable',
				'sidebar' => 'full', 'sidebarStyle' => 'pills', 'font' => 'dm-sans', 'radius' => 'round', 'shadow' => 'soft',
				'glass' => false, 'bgImage' => 'none', 'palette' => 'calm',
				'previewMain' => '#f7f9f6', 'previewRail' => '#10b981',
			],
			'neon' => [
				'name' => 'Neon', 'desc' => 'Loud. Saturated. Going out tonight.', 'group' => 'foundations',
				'mode' => 'dark', 'accent' => '#ec4899', 'density' => 'standard',
				'sidebar' => 'icon', 'sidebarStyle' => 'default', 'font' => 'bebas', 'radius' => 'round', 'shadow' => 'bold',
				'glass' => false, 'bgImage' => 'none', 'palette' => 'neon',
				'previewMain' => '#0d0d0d', 'previewRail' => '#ec4899',
			],
			'mono' => [
				'name' => 'Mono', 'desc' => 'Black, white, and one accent.', 'group' => 'foundations',
				'mode' => 'dark', 'accent' => '#ffffff', 'density' => 'compact',
				'sidebar' => 'compact', 'sidebarStyle' => 'dividers', 'font' => 'jetbrains', 'radius' => 'sharp', 'shadow' => 'flat',
				'glass' => false, 'bgImage' => 'none', 'palette' => 'mono', 'cardStyle' => 'bare',
				'previewMain' => '#000000', 'previewRail' => '#ffffff',
			],
			'slate' => [
				'name' => 'Slate', 'desc' => 'Cool neutral. Apple-grade workhorse.', 'group' => 'foundations',
				'mode' => 'dark', 'accent' => '#3b82f6', 'density' => 'standard',
				'sidebar' => 'full', 'sidebarStyle' => 'default', 'font' => 'inter', 'radius' => 'medium', 'shadow' => 'soft',
				'glass' => false, 'bgImage' => 'none', 'palette' => 'slate',
				'previewMain' => '#1e293b', 'previewRail' => '#3b82f6',
			],
			'paper' => [
				'name' => 'Paper', 'desc' => 'Warm minimalist. Manuscript white.', 'group' => 'foundations',
				'mode' => 'light', 'accent' => '#1c1917', 'density' => 'comfortable',
				'sidebar' => 'full', 'sidebarStyle' => 'default', 'font' => 'crimson', 'radius' => 'medium', 'shadow' => 'flat',
				'glass' => false, 'bgImage' => 'none', 'palette' => 'paper', 'cardStyle' => 'paper',
				'previewMain' => '#fafaf7', 'previewRail' => '#1c1917',
			],
			'studio-pro' => [
				'name' => 'Studio Pro', 'desc' => 'Dense pro tool. Compact by default.', 'group' => 'foundations',
				'mode' => 'dark', 'accent' => '#06b6d4', 'density' => 'compact',
				'sidebar' => 'full', 'sidebarStyle' => 'default', 'font' => 'jetbrains', 'radius' => 'sharp', 'shadow' => 'flat',
				'glass' => false, 'bgImage' => 'none', 'palette' => 'studio-pro',
				'previewMain' => '#161821', 'previewRail' => '#06b6d4',
			],
			'carbon' => [
				'name' => 'Carbon', 'desc' => 'Deeper than dark. IBM-grade contrast.', 'group' => 'foundations',
				'mode' => 'dark', 'accent' => '#0f62fe', 'density' => 'standard',
				'sidebar' => 'full', 'sidebarStyle' => 'default', 'font' => 'ibm-plex', 'radius' => 'sharp', 'shadow' => 'flat',
				'glass' => false, 'bgImage' => 'none', 'palette' => 'carbon',
				'previewMain' => '#0a0a0c', 'previewRail' => '#0f62fe',
			],
			'glass' => [
				'name' => 'Glass', 'desc' => 'Frosted surfaces. Dreamy.', 'group' => 'glass-spatial',
				'mode' => 'dark', 'accent' => '#06b6d4', 'density' => 'standard',
				'sidebar' => 'full', 'sidebarStyle' => 'floating', 'font' => 'system', 'radius' => 'round', 'shadow' => 'soft',
				'glass' => true, 'bgImage' => 'radial-gradient(ellipse at top left, #1e3a8a 0%, #0a0a0a 50%), radial-gradient(ellipse at bottom right, #6b21a8 0%, transparent 60%)',
				'palette' => 'glass', 'previewMain' => 'linear-gradient(135deg,#1e3a8a,#6b21a8)', 'previewRail' => 'rgba(255,255,255,0.4)',
			],
			'aurora' => [
				'name' => 'Aurora', 'desc' => 'iOS-style. Vibrant gradients. Apple energy.', 'group' => 'glass-spatial',
				'mode' => 'light', 'accent' => '#0a84ff', 'density' => 'standard',
				'sidebar' => 'full', 'sidebarStyle' => 'pills', 'font' => 'system', 'radius' => 'round', 'shadow' => 'bold',
				'glass' => true, 'bgImage' => 'linear-gradient(135deg, #ffd1dc 0%, #c5e8ff 35%, #d4f4dd 70%, #fff4c5 100%)',
				'palette' => 'aurora', 'previewMain' => 'linear-gradient(135deg,#ffd1dc,#c5e8ff,#d4f4dd)', 'previewRail' => '#0a84ff',
			],
			'graphite' => [
				'name' => 'Graphite', 'desc' => 'Apple Pro. Cool greys. Spatial.', 'group' => 'glass-spatial',
				'mode' => 'dark', 'accent' => '#a78bfa', 'density' => 'standard',
				'sidebar' => 'full', 'sidebarStyle' => 'default', 'font' => 'system', 'radius' => 'round', 'shadow' => 'soft',
				'glass' => true, 'bgImage' => 'linear-gradient(180deg, #1c1c1e 0%, #0a0a0a 100%)',
				'palette' => 'graphite', 'previewMain' => 'linear-gradient(180deg,#2c2c2e,#1c1c1e)', 'previewRail' => '#a78bfa',
			],
			'midnight' => [
				'name' => 'Midnight', 'desc' => 'Deep ocean blues. Glass. After-hours mode.', 'group' => 'glass-spatial',
				'mode' => 'dark', 'accent' => '#5eead4', 'density' => 'standard',
				'sidebar' => 'full', 'sidebarStyle' => 'default', 'font' => 'space-grotesk', 'radius' => 'medium', 'shadow' => 'bold',
				'glass' => true, 'bgImage' => 'radial-gradient(ellipse at top, #0c1e3d 0%, #050a17 60%), radial-gradient(ellipse at bottom, #14b8a6 0%, transparent 50%)',
				'palette' => 'midnight', 'previewMain' => 'linear-gradient(135deg,#0c1e3d,#050a17)', 'previewRail' => '#5eead4',
			],
			'tron' => [
				'name' => 'Tron', 'desc' => 'Grid lines. Neon glow. End of line.', 'group' => 'experimental',
				'mode' => 'dark', 'accent' => '#00d4ff', 'density' => 'standard',
				'sidebar' => 'full', 'sidebarStyle' => 'default', 'font' => 'orbitron', 'radius' => 'sharp', 'shadow' => 'bold',
				'glass' => false, 'bgImage' => 'none', 'palette' => 'tron',
				'previewMain' => '#001830', 'previewRail' => '#00d4ff',
			],
			/* ── THERUM — the studio brand. Light + dark. ── */
			'therum-studio' => [
				'name' => 'Therum Studio', 'desc' => 'Daylight workhorse. Reads like a case study.', 'group' => 'therum',
				'mode' => 'light', 'accent' => '#1a1a1a', 'density' => 'comfortable',
				'sidebar' => 'full', 'sidebarStyle' => 'default', 'font' => 'crimson', 'radius' => 'medium', 'shadow' => 'soft',
				'glass' => false, 'bgImage' => 'none', 'palette' => 'therum-studio',
				'previewMain' => '#f7f5f0', 'previewRail' => '#1a1a1a',
			],
			'therum-os' => [
				'name' => 'Therum OS', 'desc' => 'After hours. Premium tech feel.', 'group' => 'therum',
				'mode' => 'dark', 'accent' => '#3672ff', 'density' => 'comfortable',
				'sidebar' => 'full', 'sidebarStyle' => 'default', 'font' => 'space-grotesk', 'radius' => 'medium', 'shadow' => 'soft',
				'glass' => false, 'bgImage' => 'none', 'palette' => 'therum-os',
				'previewMain' => '#0a0a0c', 'previewRail' => '#3672ff',
			],
			// Each Gundam now varies on density / sidebarStyle / radius / shadow
			// in addition to font + palette, so the lineup reads as ten distinct
			// products instead of ten color recolors. Pairings are intentional —
			// Federation prototype gets parade-flat treatment; Newtype designs
			// get pillowy radius; Char-line frames go ornate (serifs + bold
			// shadow); Celestial Being goes minimal/cool.
			'gundam-rx78' => [
				'name' => 'RX-78-2', 'desc' => 'The White Devil. Federation prototype.', 'group' => 'mecha',
				'mode' => 'light', 'accent' => '#fb2f38', 'density' => 'comfortable',
				'sidebar' => 'full', 'sidebarStyle' => 'solid', 'font' => 'audiowide', 'radius' => 'sharp', 'shadow' => 'flat',
				'glass' => false, 'bgImage' => 'none', 'palette' => 'gundam-rx78', 'cardStyle' => 'tile',
				'previewMain' => '#ffffff', 'previewRail' => '#fb2f38',
			],
			'gundam-nu' => [
				'name' => 'Nu Gundam', 'desc' => "RX-93. Amuro's white & navy.", 'group' => 'mecha',
				'mode' => 'light', 'accent' => '#0f172a', 'density' => 'standard',
				'sidebar' => 'full', 'sidebarStyle' => 'minimal', 'font' => 'space-grotesk', 'radius' => 'medium', 'shadow' => 'soft',
				'glass' => false, 'bgImage' => 'none', 'palette' => 'gundam-nu',
				'previewMain' => '#ffffff', 'previewRail' => '#0f172a',
			],
			'gundam-hinu' => [
				'name' => 'Hi-Nu', 'desc' => "RX-93-ν2. White & purple. Amuro custom.", 'group' => 'mecha',
				'mode' => 'light', 'accent' => '#7c3aed', 'density' => 'comfortable',
				'sidebar' => 'full', 'sidebarStyle' => 'pills', 'font' => 'space-grotesk', 'radius' => 'round', 'shadow' => 'soft',
				'glass' => false, 'bgImage' => 'none', 'palette' => 'gundam-hinu',
				'previewMain' => '#ffffff', 'previewRail' => '#7c3aed',
			],
			'gundam-aerial' => [
				'name' => 'Aerial', 'desc' => 'XVX-016. Permet score 8.', 'group' => 'mecha',
				'mode' => 'light', 'accent' => '#06b6d4', 'density' => 'comfortable',
				'sidebar' => 'full', 'sidebarStyle' => 'pills', 'font' => 'dm-sans', 'radius' => 'round', 'shadow' => 'soft',
				'glass' => false, 'bgImage' => 'none', 'palette' => 'gundam-aerial', 'cardStyle' => 'glass',
				'previewMain' => '#ffffff', 'previewRail' => '#06b6d4',
			],
			'gundam-calibarn' => [
				'name' => 'Calibarn', 'desc' => "Suletta's transcended form.", 'group' => 'mecha',
				'mode' => 'light', 'accent' => '#d4af37', 'density' => 'standard',
				'sidebar' => 'full', 'sidebarStyle' => 'dividers', 'font' => 'crimson', 'radius' => 'sharp', 'shadow' => 'flat',
				'glass' => false, 'bgImage' => 'none', 'palette' => 'gundam-calibarn',
				'previewMain' => '#fdfbf3', 'previewRail' => '#d4af37',
			],
			'gundam-unicorn' => [
				'name' => 'Unicorn', 'desc' => 'RX-0. NT-D destroy mode.', 'group' => 'mecha',
				'mode' => 'dark', 'accent' => '#ff2d55', 'density' => 'standard',
				'sidebar' => 'full', 'sidebarStyle' => 'default', 'font' => 'audiowide', 'radius' => 'sharp', 'shadow' => 'glow',
				'glass' => false, 'bgImage' => 'none', 'palette' => 'gundam-unicorn',
				'previewMain' => 'linear-gradient(135deg,#0d0408,#1a0810)', 'previewRail' => '#ff2d55',
			],
			'gundam-epyon' => [
				'name' => 'Epyon', 'desc' => 'OZ-13MS. Drawn beam at night.', 'group' => 'mecha',
				'mode' => 'dark', 'accent' => '#b91c1c', 'density' => 'compact',
				'sidebar' => 'full', 'sidebarStyle' => 'solid', 'font' => 'orbitron', 'radius' => 'sharp', 'shadow' => 'bold',
				'glass' => false, 'bgImage' => 'none', 'palette' => 'gundam-epyon',
				'previewMain' => 'linear-gradient(135deg,#0d0303,#1a0606)', 'previewRail' => '#b91c1c',
			],
			'gundam-sazabi' => [
				'name' => 'Sazabi', 'desc' => "Char's parade red, ceremonial gold.", 'group' => 'mecha',
				'mode' => 'dark', 'accent' => '#d4af37', 'density' => 'comfortable',
				'sidebar' => 'compact', 'sidebarStyle' => 'dividers', 'font' => 'playfair', 'radius' => 'sharp', 'shadow' => 'bold',
				'glass' => false, 'bgImage' => 'none', 'palette' => 'gundam-sazabi',
				'previewMain' => 'linear-gradient(135deg,#1a0a0c,#2a1014)', 'previewRail' => '#d4af37',
			],
			'gundam-sinanju' => [
				'name' => 'Sinanju', 'desc' => 'MSN-06S. Crimson with ornate gold.', 'group' => 'mecha',
				'mode' => 'dark', 'accent' => '#f4c542', 'density' => 'comfortable',
				'sidebar' => 'compact', 'sidebarStyle' => 'dividers', 'font' => 'playfair', 'radius' => 'medium', 'shadow' => 'glow',
				'glass' => false, 'bgImage' => 'none', 'palette' => 'gundam-sinanju',
				'previewMain' => 'linear-gradient(135deg,#1a0608,#2a0a0c)', 'previewRail' => '#f4c542',
			],
			'gundam-exia' => [
				'name' => 'Exia', 'desc' => 'GN-001. Celestial Being electric cyan.', 'group' => 'mecha',
				'mode' => 'dark', 'accent' => '#00e5ff', 'density' => 'compact',
				'sidebar' => 'icon', 'sidebarStyle' => 'minimal', 'font' => 'jetbrains', 'radius' => 'medium', 'shadow' => 'glow',
				'glass' => false, 'bgImage' => 'none', 'palette' => 'gundam-exia',
				'previewMain' => 'linear-gradient(135deg,#061222,#0c1a30)', 'previewRail' => '#00e5ff',
			],
			'gundam-kshatriya' => [
				'name' => 'Kshatriya', 'desc' => "NZ-666. Marida. Quad-wing menace.", 'group' => 'mecha',
				'mode' => 'dark', 'accent' => '#84a35e', 'density' => 'standard',
				'sidebar' => 'full', 'sidebarStyle' => 'default', 'font' => 'orbitron', 'radius' => 'sharp', 'shadow' => 'soft',
				'glass' => false, 'bgImage' => 'none', 'palette' => 'gundam-kshatriya',
				'previewMain' => '#1c2418', 'previewRail' => '#84a35e',
			],
			'gundam-vidar' => [
				'name' => 'Vidar', 'desc' => "ASW-G-XX. Gaelio. Norse god of revenge.", 'group' => 'mecha',
				'mode' => 'dark', 'accent' => '#3b3b8c', 'density' => 'standard',
				'sidebar' => 'full', 'sidebarStyle' => 'default', 'font' => 'space-grotesk', 'radius' => 'sharp', 'shadow' => 'soft',
				'glass' => false, 'bgImage' => 'none', 'palette' => 'gundam-vidar',
				'previewMain' => '#0f172a', 'previewRail' => '#7c3aed',
			],
			'gundam-tallgeese' => [
				'name' => 'Tallgeese', 'desc' => "OZ-00MS. Zechs. Lightning fast.", 'group' => 'mecha',
				'mode' => 'light', 'accent' => '#dc2626', 'density' => 'standard',
				'sidebar' => 'full', 'sidebarStyle' => 'default', 'font' => 'archivo', 'radius' => 'sharp', 'shadow' => 'soft',
				'glass' => false, 'bgImage' => 'none', 'palette' => 'gundam-tallgeese',
				'previewMain' => '#fafafa', 'previewRail' => '#dc2626',
			],
			'gundam-reborns' => [
				'name' => 'Reborns', 'desc' => "CB-0000G/C. Ribbons. Red & gold.", 'group' => 'mecha',
				'mode' => 'dark', 'accent' => '#eab308', 'density' => 'standard',
				'sidebar' => 'full', 'sidebarStyle' => 'default', 'font' => 'orbitron', 'radius' => 'sharp', 'shadow' => 'soft',
				'glass' => false, 'bgImage' => 'none', 'palette' => 'gundam-reborns',
				'previewMain' => '#7f1d1d', 'previewRail' => '#eab308',
			],
			'gundam-penelope' => [
				'name' => 'Penelope', 'desc' => "RX-104FF. Lane Aim. Federation flight unit.", 'group' => 'mecha',
				'mode' => 'light', 'accent' => '#65a30d', 'density' => 'standard',
				'sidebar' => 'full', 'sidebarStyle' => 'default', 'font' => 'dm-sans', 'radius' => 'sharp', 'shadow' => 'soft',
				'glass' => false, 'bgImage' => 'none', 'palette' => 'gundam-penelope',
				'previewMain' => '#f5f5f4', 'previewRail' => '#65a30d',
			],
			'gundam-xi' => [
				'name' => 'Xi Gundam', 'desc' => "RX-105. Hathaway. 5th gen Mafty.", 'group' => 'mecha',
				'mode' => 'light', 'accent' => '#15803d', 'density' => 'standard',
				'sidebar' => 'full', 'sidebarStyle' => 'default', 'font' => 'dm-sans', 'radius' => 'sharp', 'shadow' => 'soft',
				'glass' => false, 'bgImage' => 'none', 'palette' => 'gundam-xi',
				'previewMain' => '#fafafa', 'previewRail' => '#15803d',
			],
			'mecha-big-o' => [
				'name' => 'Big O', 'desc' => "Roger Smith. Big O. Showtime.", 'group' => 'mecha',
				'mode' => 'dark', 'accent' => '#fbbf24', 'density' => 'standard',
				'sidebar' => 'full', 'sidebarStyle' => 'default', 'font' => 'playfair', 'radius' => 'sharp', 'shadow' => 'flat',
				'glass' => false, 'bgImage' => 'none', 'palette' => 'mecha-big-o',
				'previewMain' => '#0a0a0a', 'previewRail' => '#fbbf24',
			],
			'mecha-big-o-final' => [
				'name' => 'Big O — Final Stage', 'desc' => "Limiter off. Crimson rage.", 'group' => 'mecha',
				'mode' => 'dark', 'accent' => '#dc2626', 'density' => 'standard',
				'sidebar' => 'full', 'sidebarStyle' => 'default', 'font' => 'playfair', 'radius' => 'sharp', 'shadow' => 'flat',
				'glass' => false, 'bgImage' => 'none', 'palette' => 'mecha-big-o-final',
				'previewMain' => '#0a0a0a', 'previewRail' => '#dc2626',
			],
			'cyberpunk' => [
				'name' => 'Cyberpunk', 'desc' => 'Magenta and yellow. Glitchy.', 'group' => 'experimental',
				'mode' => 'dark', 'accent' => '#ff00aa', 'density' => 'standard',
				'sidebar' => 'full', 'sidebarStyle' => 'default', 'font' => 'jetbrains', 'radius' => 'sharp', 'shadow' => 'bold',
				'glass' => false, 'bgImage' => 'none', 'palette' => 'cyberpunk',
				'previewMain' => 'linear-gradient(135deg,#150a24,#1f1230)', 'previewRail' => '#ff00aa',
			],
			'wireframe' => [
				'name' => 'Wireframe', 'desc' => 'Schematic. Monospace. Pure outlines.', 'group' => 'experimental',
				'mode' => 'dark', 'accent' => '#00ff41', 'density' => 'compact',
				'sidebar' => 'full', 'sidebarStyle' => 'default', 'font' => 'jetbrains', 'radius' => 'sharp', 'shadow' => 'flat',
				'glass' => false, 'bgImage' => 'none', 'palette' => 'wireframe', 'cardStyle' => 'flat',
				'previewMain' => '#000000', 'previewRail' => '#00ff41',
			],
			'vaporwave' => [
				'name' => 'Vaporwave', 'desc' => 'Pink/purple gradients. A E S T H E T I C.', 'group' => 'experimental',
				'mode' => 'dark', 'accent' => '#ff71ce', 'density' => 'standard',
				'sidebar' => 'full', 'sidebarStyle' => 'default', 'font' => 'vt323', 'radius' => 'round', 'shadow' => 'bold',
				'glass' => false, 'bgImage' => 'none', 'palette' => 'vaporwave',
				'previewMain' => 'linear-gradient(135deg,#1a0033,#4a0e6e)', 'previewRail' => '#ff71ce',
			],
			'facenote' => [
				'name' => 'Facenote', 'desc' => 'Frosted glass over a blue gradient.', 'group' => 'experimental',
				'mode' => 'dark', 'accent' => '#38e8e2', 'density' => 'standard',
				'sidebar' => 'full', 'sidebarStyle' => 'default', 'font' => 'space-grotesk', 'radius' => 'round', 'shadow' => 'soft',
				'glass' => false, 'bgImage' => 'none', 'palette' => 'facenote',
				'previewMain' => 'linear-gradient(135deg,#1e7fb8,#1a4a8a,#0f1f4a)', 'previewRail' => '#38e8e2',
			],
			// ── New surface-effect presets — light glass, dark glass, colored
			// glass, animated gradient, heavy frost. Each pairs with a font /
			// radius / shadow combo so they're not just the same theme with a
			// different toggle.
			'glass-light' => [
				'name' => 'Glass · Light', 'desc' => 'Airy. Frosted. Window-bright.', 'group' => 'surfaces',
				'mode' => 'light', 'accent' => '#0a84ff', 'density' => 'comfortable',
				'sidebar' => 'full', 'sidebarStyle' => 'floating', 'font' => 'inter', 'radius' => 'round', 'shadow' => 'soft',
				'glass' => true, 'bgImage' => 'none', 'glassTint' => 'light', 'palette' => 'studio', 'cardStyle' => 'glass',
				'previewMain' => 'linear-gradient(135deg,#e0eafc,#cfdef3)', 'previewRail' => '#0a84ff',
			],
			'glass-dark' => [
				'name' => 'Glass · Dark', 'desc' => 'Smoky. Heavy. Pro-tool feel.', 'group' => 'surfaces',
				'mode' => 'dark', 'accent' => '#a3e635', 'density' => 'standard',
				'sidebar' => 'full', 'sidebarStyle' => 'floating', 'font' => 'inter', 'radius' => 'medium', 'shadow' => 'soft',
				'glass' => true, 'bgImage' => 'none', 'glassTint' => 'dark', 'palette' => 'graphite', 'cardStyle' => 'glass',
				'previewMain' => 'linear-gradient(135deg,#1f2937,#0f172a)', 'previewRail' => '#a3e635',
			],
			'glass-colored' => [
				'name' => 'Glass · Colored', 'desc' => 'Wash the glass with the accent.', 'group' => 'surfaces',
				'mode' => 'dark', 'accent' => '#f5389a', 'density' => 'standard',
				'sidebar' => 'full', 'sidebarStyle' => 'pills', 'font' => 'space-grotesk', 'radius' => 'round', 'shadow' => 'glow',
				'glass' => true, 'bgImage' => 'none', 'glassTint' => '#f5389a', 'palette' => 'studio', 'cardStyle' => 'glass',
				'previewMain' => 'linear-gradient(135deg,#3a0f23,#1a0612)', 'previewRail' => '#f5389a',
			],
			'gradient-aurora' => [
				'name' => 'Gradient · Aurora', 'desc' => 'Animated multi-stop wash. Slow drift.', 'group' => 'surfaces',
				'mode' => 'dark', 'accent' => '#22d3ee', 'density' => 'standard',
				'sidebar' => 'full', 'sidebarStyle' => 'minimal', 'font' => 'space-grotesk', 'radius' => 'round', 'shadow' => 'glow',
				'glass' => false, 'bgImage' => 'gradient', 'palette' => 'studio', 'cardStyle' => 'glass',
				'previewMain' => 'linear-gradient(135deg,#0a4d68,#088395,#22d3ee)', 'previewRail' => '#22d3ee',
			],
			'blurred-frost' => [
				'name' => 'Blurred · Frost', 'desc' => 'Heavy frost on every surface.', 'group' => 'surfaces',
				'mode' => 'dark', 'accent' => '#e879f9', 'density' => 'comfortable',
				'sidebar' => 'full', 'sidebarStyle' => 'floating', 'font' => 'dm-sans', 'radius' => 'round', 'shadow' => 'soft',
				'glass' => false, 'bgImage' => 'blurred', 'palette' => 'studio', 'cardStyle' => 'glass',
				'previewMain' => 'linear-gradient(135deg,#581c87,#3b0764)', 'previewRail' => '#e879f9',
			],
			'velvet' => [
				'name' => 'Velvet', 'desc' => 'Midnight purple. Frosted tiers. Premium membership.', 'group' => 'surfaces',
				'mode' => 'dark', 'accent' => '#a78bfa', 'density' => 'comfortable',
				'sidebar' => 'full', 'sidebarStyle' => 'floating', 'font' => 'inter', 'radius' => 'round', 'shadow' => 'soft',
				'glass' => true, 'bgImage' => 'none', 'palette' => 'velvet',
				'previewMain' => 'radial-gradient(ellipse at center,#2a1862,#0a0628)', 'previewRail' => '#a78bfa',
			],
			/* ── KINDA FAMILIAR — UI inspired by big-name OS / SaaS / brands ── */
			'familiar-macos' => [
				'name' => 'macOS Sonoma', 'desc' => 'Soft blue. Frosted blur. Apple energy.', 'group' => 'familiar',
				'mode' => 'light', 'accent' => '#0a84ff', 'density' => 'comfortable',
				'sidebar' => 'full', 'sidebarStyle' => 'floating', 'font' => 'system', 'radius' => 'medium', 'shadow' => 'soft',
				'glass' => true, 'bgImage' => 'none', 'palette' => 'familiar-macos',
				'previewMain' => 'linear-gradient(135deg,#dbeafe,#e0e7ff)', 'previewRail' => '#0a84ff',
			],
			'familiar-windows' => [
				'name' => 'Windows 11', 'desc' => 'Mica grey. Acrylic. Microsoft accent.', 'group' => 'familiar',
				'mode' => 'dark', 'accent' => '#0078d4', 'density' => 'comfortable',
				'sidebar' => 'full', 'sidebarStyle' => 'default', 'font' => 'inter', 'radius' => 'medium', 'shadow' => 'soft',
				'glass' => true, 'bgImage' => 'none', 'palette' => 'familiar-windows',
				'previewMain' => '#202020', 'previewRail' => '#0078d4',
			],
			'familiar-tesla' => [
				'name' => 'Tesla', 'desc' => 'Black. White. Red accent. Minimal.', 'group' => 'familiar',
				'mode' => 'dark', 'accent' => '#e82127', 'density' => 'comfortable',
				'sidebar' => 'full', 'sidebarStyle' => 'minimal', 'font' => 'inter', 'radius' => 'sharp', 'shadow' => 'flat',
				'glass' => false, 'bgImage' => 'none', 'palette' => 'familiar-tesla',
				'previewMain' => '#0a0a0a', 'previewRail' => '#e82127',
			],
			'familiar-linear' => [
				'name' => 'Linear', 'desc' => 'Purple. Dark grey. Modern SaaS.', 'group' => 'familiar',
				'mode' => 'dark', 'accent' => '#5e6ad2', 'density' => 'standard',
				'sidebar' => 'full', 'sidebarStyle' => 'default', 'font' => 'inter', 'radius' => 'medium', 'shadow' => 'soft',
				'glass' => false, 'bgImage' => 'none', 'palette' => 'familiar-linear',
				'previewMain' => '#1a1a1f', 'previewRail' => '#5e6ad2',
			],
			'familiar-vercel' => [
				'name' => 'Vercel', 'desc' => 'Pure black. Pure white. Brutal contrast.', 'group' => 'familiar',
				'mode' => 'dark', 'accent' => '#ffffff', 'density' => 'standard',
				'sidebar' => 'full', 'sidebarStyle' => 'default', 'font' => 'jetbrains', 'radius' => 'sharp', 'shadow' => 'flat',
				'glass' => false, 'bgImage' => 'none', 'palette' => 'familiar-vercel',
				'previewMain' => '#000000', 'previewRail' => '#ffffff',
			],
			'familiar-notion' => [
				'name' => 'Notion', 'desc' => 'Off-white. Warm grey. Bookish.', 'group' => 'familiar',
				'mode' => 'light', 'accent' => '#37352f', 'density' => 'comfortable',
				'sidebar' => 'full', 'sidebarStyle' => 'default', 'font' => 'crimson', 'radius' => 'medium', 'shadow' => 'flat',
				'glass' => false, 'bgImage' => 'none', 'palette' => 'familiar-notion',
				'previewMain' => '#fbfaf6', 'previewRail' => '#37352f',
			],
			'familiar-spotify' => [
				'name' => 'Spotify', 'desc' => 'Black. Forest green. Bold streamer.', 'group' => 'familiar',
				'mode' => 'dark', 'accent' => '#1db954', 'density' => 'standard',
				'sidebar' => 'full', 'sidebarStyle' => 'default', 'font' => 'inter', 'radius' => 'round', 'shadow' => 'flat',
				'glass' => false, 'bgImage' => 'none', 'palette' => 'familiar-spotify',
				'previewMain' => '#121212', 'previewRail' => '#1db954',
			],
			'familiar-apple-music' => [
				'name' => 'Apple Music', 'desc' => 'Inky black. Pink-red glow. Album-art vibes.', 'group' => 'familiar',
				'mode' => 'dark', 'accent' => '#fa233b', 'density' => 'comfortable',
				'sidebar' => 'full', 'sidebarStyle' => 'floating', 'font' => 'system', 'radius' => 'medium', 'shadow' => 'soft',
				'glass' => true, 'bgImage' => 'none', 'palette' => 'familiar-apple-music',
				'previewMain' => 'linear-gradient(135deg,#1a0d12,#2d0a1f 60%,#0a0a0a)', 'previewRail' => '#fa233b',
			],
			'familiar-lucid' => [
				'name' => 'Lucid', 'desc' => 'Lucid Air cabin. Black glass. Electric cyan. EV calm.', 'group' => 'familiar',
				'mode' => 'dark', 'accent' => '#00d4ff', 'density' => 'breathing',
				'sidebar' => 'full', 'sidebarStyle' => 'floating', 'font' => 'inter', 'radius' => 'round', 'shadow' => 'soft',
				'glass' => true, 'bgImage' => 'none', 'palette' => 'familiar-lucid',
				'previewMain' => 'linear-gradient(135deg,#020617,#0c1929 55%,#000000)', 'previewRail' => '#00d4ff',
			],
			'familiar-xfinity' => [
				'name' => 'Xfinity Member', 'desc' => 'Midnight violet. Iridescent glass. Membership tier.', 'group' => 'familiar',
				'mode' => 'dark', 'accent' => '#a855f7', 'density' => 'comfortable',
				'sidebar' => 'full', 'sidebarStyle' => 'floating', 'font' => 'inter', 'radius' => 'medium', 'shadow' => 'soft',
				'glass' => true, 'bgImage' => 'none', 'palette' => 'familiar-xfinity',
				'previewMain' => 'radial-gradient(ellipse at 30% 25%,#3b1f8a,#0a0846 60%,#050129)', 'previewRail' => '#a855f7',
			],
			/* ── EXPERIMENTAL wildcards ── */
			'exp-holographic' => [
				'name' => 'Holographic', 'desc' => "Foil. Iridescent. Catches light.", 'group' => 'experimental',
				'mode' => 'dark', 'accent' => '#a78bfa', 'density' => 'standard',
				'sidebar' => 'full', 'sidebarStyle' => 'default', 'font' => 'bebas', 'radius' => 'round', 'shadow' => 'soft',
				'glass' => true, 'bgImage' => 'none', 'palette' => 'exp-holographic',
				'previewMain' => 'linear-gradient(135deg,#ff80b5,#9089fc,#80ffea)', 'previewRail' => '#a78bfa',
			],
			'exp-ascii' => [
				'name' => 'ASCII', 'desc' => "Pure terminal. Phosphor green on black.", 'group' => 'experimental',
				'mode' => 'dark', 'accent' => '#00ff41', 'density' => 'compact',
				'sidebar' => 'full', 'sidebarStyle' => 'default', 'font' => 'jetbrains', 'radius' => 'sharp', 'shadow' => 'flat',
				'glass' => false, 'bgImage' => 'none', 'palette' => 'exp-ascii',
				'previewMain' => '#000000', 'previewRail' => '#00ff41',
			],
			'exp-marker' => [
				'name' => 'Marker Doodle', 'desc' => "Hand-drawn. Sketchbook page.", 'group' => 'experimental',
				'mode' => 'light', 'accent' => '#ec4899', 'density' => 'comfortable',
				'sidebar' => 'full', 'sidebarStyle' => 'default', 'font' => 'caveat', 'radius' => 'round', 'shadow' => 'soft',
				'glass' => false, 'bgImage' => 'none', 'palette' => 'exp-marker',
				'previewMain' => '#fffbe6', 'previewRail' => '#ec4899',
			],
			'exp-newspaper' => [
				'name' => 'Newspaper', 'desc' => "Newsprint. Serif. Above the fold.", 'group' => 'experimental',
				'mode' => 'light', 'accent' => '#1c1c1c', 'density' => 'comfortable',
				'sidebar' => 'full', 'sidebarStyle' => 'default', 'font' => 'crimson', 'radius' => 'sharp', 'shadow' => 'flat',
				'glass' => false, 'bgImage' => 'none', 'palette' => 'exp-newspaper',
				'previewMain' => '#f4f1e8', 'previewRail' => '#1c1c1c',
			],
			'exp-crayon' => [
				'name' => 'Crayon', 'desc' => "Bright. Childlike. Pure joy.", 'group' => 'experimental',
				'mode' => 'light', 'accent' => '#f97316', 'density' => 'comfortable',
				'sidebar' => 'full', 'sidebarStyle' => 'default', 'font' => 'caveat', 'radius' => 'round', 'shadow' => 'soft',
				'glass' => false, 'bgImage' => 'none', 'palette' => 'exp-crayon',
				'previewMain' => '#fef3c7', 'previewRail' => '#f97316',
			],
		];
	}

	public static function groups(): array {
		return [
			'studio-new'    => ['label' => 'Studio · New',  'desc' => 'The new theme engine — foundation themes from the 2025 dashboard study.'],
			'foundations'   => ['label' => 'Foundations',   'desc' => 'Core working themes — pick once, forget.'],
			'surfaces'      => ['label' => 'Surfaces',      'desc' => 'Glass, gradient, frost — pure atmosphere plays.'],
			'glass-spatial' => ['label' => 'Glass & Spatial','desc' => 'Frosted, layered, atmospheric.'],
			'therum'        => ['label' => 'Therum',        'desc' => 'The studio brand. Light + dark.'],
			'mecha'         => ['label' => 'Mecha Pack',    'desc' => 'Suit-inspired colorways. Light + dark per variant.'],
			'familiar'      => ['label' => 'Kinda Familiar','desc' => 'You\'ve seen these somewhere before.'],
			'experimental'  => ['label' => 'Experimental',  'desc' => 'Loud. Specific. Don\'t use for client work.'],
		];
	}

	public static function default_state(): array {
		return [
			'palette' => 'studio',
			// Default to 'auto' so the chrome respects the user's OS-level
			// light/dark preference out of the box. JS handler in
			// therum-customization.js + the early pre-paint script in
			// admin_head flip body.light based on prefers-color-scheme.
			'mode'    => 'auto',
			'glass'   => false,
			'accent'  => '#e83b3b',
			'intensity' => 'standard',
			'font'    => 'system',
			'displayFont' => 'inter-tight',
			'monoFont' => 'jetbrains',
			'baseSize' => 14,
			'letterSpacing' => 'normal',
			'lineHeight' => 'standard',
			'radius'  => 'medium',
			'borderWeight' => 'standard',
			'shadow'  => 'soft',
			'blur'    => 40,
			'glassTint' => 'dark',
			'density' => 'comfortable',
			'sidebar' => 'full',
			'sidebarStyle' => 'default',
			'sidebarLayout' => 'both',
			'topbar'  => 'sticky',
			'content' => 'full',
			'bentoGap' => 16,
			'bgImage' => 'none',
			'cardLayout' => 'hero',
			'cardImage'  => 'gradient',
			'cardStyle' => 'default',
			'motion'  => 'full',
			'transitionSpeed' => 'standard',
			'pageTransitions' => true,
			'cardHoverLift' => true,
			'listView' => 'grid',
			'itemsPerPage' => 24,
			'thumbSource' => 'gradient',
			'contrast' => 'standard',
			'reduceTransparency' => false,
			'underlineLinks' => false,
			'focusRings' => true,
			'largeTargets' => false,
			'showGrips' => false,
			'showShortcuts' => true,
			'autoSave' => true,
			'debugOverlays' => false,
			'codeEditorTheme' => 'therum',
			'desktopMode' => false,
			'surfaceEffect' => 'none',
		];
	}

	public static function get_state(): array {
		$user_id = get_current_user_id();
		if ($user_id) {
			$user = get_user_meta($user_id, self::USER_META_KEY, true);
			if (is_array($user) && !empty($user)) {
				$state = array_merge(self::default_state(), $user);
				return self::migrate_state($state);
			}
		}
		$site = get_option(self::SITE_OPTION_KEY, []);
		if (is_array($site) && !empty($site)) {
			$state = array_merge(self::default_state(), $site);
			return self::migrate_state($state);
		}
		return self::default_state();
	}

	/**
	 * Migrate legacy state values that changed type or valid options.
	 * Runs on read so old data auto-heals without a manual migration step.
	 */
	private static function migrate_state(array $state): array {
		// blur: was 'medium' (string), now int 0-60. Convert legacy string values.
		if (isset($state['blur']) && !is_numeric($state['blur'])) {
			$blur_map = ['low' => 20, 'medium' => 40, 'high' => 60];
			$state['blur'] = $blur_map[$state['blur']] ?? 40;
		}
		// density: was 'standard', now 'comfortable'. Map the old value.
		if (isset($state['density']) && $state['density'] === 'standard') {
			$state['density'] = 'comfortable';
		}
		// glassTint: was 'auto', now 'dark'. Map the old value.
		if (isset($state['glassTint']) && $state['glassTint'] === 'auto') {
			$state['glassTint'] = 'dark';
		}
		return $state;
	}

	public static function save_user_state(array $state): void {
		$state = array_intersect_key($state, self::default_state());
		update_user_meta(get_current_user_id(), self::USER_META_KEY, $state);
	}

	public static function save_site_state(array $state): void {
		$state = array_intersect_key($state, self::default_state());
		update_option(self::SITE_OPTION_KEY, $state);
	}

	public static function reset_user_state(): void {
		delete_user_meta(get_current_user_id(), self::USER_META_KEY);
	}

	public static function apply_preset(string $preset_id): array {
		$presets = self::presets();
		if (!isset($presets[$preset_id])) return self::get_state();
		$p = $presets[$preset_id];
		// CLEAN RESET — start from defaults, overlay the preset cleanly. No
		// leftover overrides leak in from prior state. Picking a theme card
		// gives you EXACTLY that preset, nothing else. Per-property
		// customization happens in the "Theme Customization" override panel.
		$state = array_merge(self::default_state(), [
			'palette'      => $p['palette'],
			// Every preset starts in 'auto' mode by user request — the
			// preset's own mode hint is treated as advisory styling info
			// (which palette colors to use), not as a forced light/dark
			// lock. Users can override per-install via Quick Controls.
			'mode'         => 'auto',
			'glass'        => $p['glass'] ?? false,
			'glassTint'    => $p['glassTint'] ?? 'auto',
			'accent'       => $p['accent'],
			'font'         => $p['font'],
			'radius'       => $p['radius'],
			'shadow'       => $p['shadow'],
			'density'      => $p['density'],
			'sidebar'      => $p['sidebar'],
			'sidebarStyle' => $p['sidebarStyle'],
			'bgImage'      => $p['bgImage'] ?? 'none',
			'cardStyle'    => $p['cardStyle'] ?? 'default',
		]);
		if ( isset( $p['sidebarLayout'] ) ) $state['sidebarLayout'] = $p['sidebarLayout'];
		if ( isset( $p['blur'] ) )          $state['blur']          = $p['blur'];
		self::save_user_state($state);
		return $state;
	}

	/**
	 * Verify nonce for theme AJAX calls. These endpoints are called from
	 * two different surfaces that emit different nonce actions:
	 *   - Customization page → 'therum_theme'
	 *   - Settings → Appearance → 'therum_options'
	 * Accept either so saves work from both pages.
	 */
	private static function verify_theme_nonce(): void {
		$nonce = $_POST['nonce'] ?? $_REQUEST['_wpnonce'] ?? '';
		if ( ! wp_verify_nonce( $nonce, 'therum_theme' ) && ! wp_verify_nonce( $nonce, 'therum_options' ) ) {
			wp_send_json_error( 'Invalid or expired nonce.', 403 );
		}
	}

	public static function ajax_apply_preset(): void {
		if (!current_user_can('read')) wp_send_json_error('unauthorized', 403);
		self::verify_theme_nonce();
		$id = sanitize_key($_POST['preset'] ?? '');
		$state = self::apply_preset($id);
		wp_send_json_success($state);
	}

	public static function ajax_reset(): void {
		if (!current_user_can('read')) wp_send_json_error('unauthorized', 403);
		self::verify_theme_nonce();
		self::reset_user_state();
		wp_send_json_success(self::get_state());
	}

	/**
	 * Patch a single field in the user's theme state. Used by the Quick
	 * Controls panel so individual toggles, swatches, sliders, and segments
	 * persist across page loads. Whitelist-keyed so only known fields land
	 * in user_meta — arbitrary client input is rejected.
	 */
	/**
	 * Coerce a single Quick Controls value to match the type of its default,
	 * applying CSS-safe validation to string fields. Shared by ajax_save_field
	 * and ajax_save_batch so the rules can't drift between the two entry points.
	 *
	 * @param mixed $value    Raw submitted value.
	 * @param mixed $default  The field's default (its type drives coercion).
	 * @param string $field   Field key (selects color vs. generic CSS handling).
	 * @return mixed Bool, int, or sanitized string ready to persist.
	 */
	private static function coerce_field_value( $value, $default, string $field ) {
		if ( is_bool( $default ) ) {
			return filter_var( $value, FILTER_VALIDATE_BOOLEAN );
		}
		if ( is_int( $default ) ) {
			return (int) $value;
		}
		return self::sanitize_css_value( $field, is_string( $value ) ? $value : '' );
	}

	/**
	 * Sanitize a string state value for safe emission into an inline <style>
	 * block. These tokens are later interpolated into CSS (custom properties,
	 * background-image, etc.), where sanitize_text_field alone would still let a
	 * crafted value break out of the rule — e.g. `red} body{background:url(...)`
	 * or `</style>`. Colors are format-validated; every other string is stripped
	 * of CSS-control characters and dangerous url()/expression() constructs.
	 */
	private static function sanitize_css_value( string $field, string $value ): string {
		$value = sanitize_text_field( $value );

		// Color fields: only #hex (3/6/8) or rgb()/rgba()/hsl()/hsla() forms.
		if ( $field === 'accent' ) {
			if ( preg_match( '/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/', $value ) ) return $value;
			if ( preg_match( '/^(?:rgb|rgba|hsl|hsla)\(\s*[0-9.,%\s\/]+\)$/i', $value ) ) return $value;
			return ''; // reject; consumer falls back to its default
		}

		if ( $value === '' || $value === 'none' ) return $value;

		// Generic CSS value (e.g. bgImage gradients): forbid rule/tag break-out
		// characters and script-bearing url()/expression() constructs. Reject to
		// a safe sentinel rather than echoing an attacker-controlled fragment.
		if ( preg_match( '/[{}<>;]|@import|expression\s*\(|url\s*\(\s*["\']?\s*(?:javascript|data|vbscript):/i', $value ) ) {
			return 'none';
		}
		return $value;
	}

	public static function ajax_save_field(): void {
		if (!current_user_can('read')) wp_send_json_error('unauthorized', 403);
		self::verify_theme_nonce();
		// NB: sanitize_key() lowercases — Therum state uses camelCase keys
		// (glassTint, sidebarStyle, cardStyle, bgImage, surfaceEffect). Use a
		// case-preserving filter and validate against the whitelist below.
		$raw   = (string) ( $_POST['field'] ?? '' );
		$field = preg_replace( '/[^a-zA-Z0-9_-]/', '', $raw );
		$value = $_POST['value'] ?? '';
		$defaults = self::default_state();
		if (!array_key_exists($field, $defaults)) {
			wp_send_json_error(['message' => 'Unknown field: ' . $field]);
		}
		$current = self::get_state();
		$current[$field] = self::coerce_field_value( $value, $defaults[$field], $field );
		self::save_user_state($current);
		wp_send_json_success($current);
	}

	/**
	 * Batch-save multiple Quick Controls fields in a single request.
	 * Used by the 💾 Save button in the Quick Controls panel footer.
	 * Expects POST['fields'] as a JSON-encoded object of {field: value} pairs.
	 */
	public static function ajax_save_batch(): void {
		if ( ! current_user_can( 'read' ) ) wp_send_json_error( 'unauthorized', 403 );
		self::verify_theme_nonce();

		$raw = $_POST['fields'] ?? '';
		if ( is_string( $raw ) ) {
			$fields = json_decode( wp_unslash( $raw ), true );
		} else {
			$fields = $raw;
		}
		if ( ! is_array( $fields ) || empty( $fields ) ) {
			wp_send_json_error( [ 'message' => 'No fields provided' ] );
		}

		$defaults = self::default_state();
		$current  = self::get_state();
		$saved    = [];

		foreach ( $fields as $field => $value ) {
			// Sanitize field name (case-preserving)
			$field = preg_replace( '/[^a-zA-Z0-9_-]/', '', (string) $field );
			if ( ! array_key_exists( $field, $defaults ) ) continue;

			$current[ $field ] = self::coerce_field_value( $value, $defaults[ $field ], $field );
			$saved[] = $field;
		}

		if ( ! empty( $saved ) ) {
			self::save_user_state( $current );
		}

		wp_send_json_success( [
			'state'  => $current,
			'saved'  => $saved,
		] );
	}
}

add_action('wp_ajax_therum_apply_preset',     ['Therum_Themes', 'ajax_apply_preset']);
add_action('wp_ajax_therum_reset_theme',      ['Therum_Themes', 'ajax_reset']);
add_action('wp_ajax_therum_save_theme_field', ['Therum_Themes', 'ajax_save_field']);
add_action('wp_ajax_therum_save_theme_batch', ['Therum_Themes', 'ajax_save_batch']);

// Apply theme classes to admin body.
add_filter('admin_body_class', function(string $classes): string {
	$state = Therum_Themes::get_state();
	$add = ['therum-themed', 'theme-' . $state['palette']];
	if ($state['mode'] === 'light') $add[] = 'light';
	if (!empty($state['glass']))    $add[] = 'glass';
	// FIX: `font-<font>` was missing from body classes — the rich `body.font-*`
	// CSS rules above never matched because nothing emitted the class. Without
	// this every theme stayed on Inter regardless of preset.
	if (!empty($state['font']))        $add[] = 'font-' . $state['font'];
	if (!empty($state['density']))     $add[] = 'density-' . $state['density'];
	if (!empty($state['sidebarStyle']))  $add[] = 'th-sb-' . $state['sidebarStyle'];
	if (!empty($state['sidebarLayout'])) $add[] = 'th-sl-' . $state['sidebarLayout'];
	if (!empty($state['glassTint']))     $add[] = 'glass-tint-' . $state['glassTint'];
	if (!empty($state['shadow']))        $add[] = 'shadow-' . $state['shadow'];
	if (!empty($state['radius']))      $add[] = 'radius-' . $state['radius'];
	if (!empty($state['blur']))        $add[] = 'blur-' . $state['blur'];
	// Background mode (none / gradient / blurred / stock / wireframe etc.) —
	// previously stored in preset state but never made it to the body class.
	// The bg-* CSS rules below light up animated gradients, heavy frost, etc.
	if (!empty($state['bgImage']) && $state['bgImage'] !== 'none') {
		$add[] = 'bg-' . $state['bgImage'];
	}
	// Structural card-chrome — the new "skin" knob. Each variant transforms
	// the dashboard's card grid into a fundamentally different visual register
	// (flat schematic / colorblock material / frosted glass / bare list).
	if (!empty($state['cardStyle']) && $state['cardStyle'] !== 'default') {
		$add[] = 'card-' . $state['cardStyle'];
	}
	// Surface effect (independent of theme). When set to a glass-* variant we
	// flip body.glass on AND emit the matching glass-tint-* class the existing
	// CSS expects (`glass-tint-light` / `-dark` / `-color`). Earlier I emitted
	// `glass-light` etc. directly, which didn't match any rule.
	if (!empty($state['surfaceEffect']) && $state['surfaceEffect'] !== 'none') {
		$se = $state['surfaceEffect'];
		if ($se === 'glass-light') {
			$add[] = 'glass';
			$add[] = 'glass-tint-light';
			$add[] = 'surface-glass-light';
		} elseif ($se === 'glass-dark') {
			$add[] = 'glass';
			$add[] = 'glass-tint-dark';
			$add[] = 'surface-glass-dark';
		} elseif ($se === 'glass-colored') {
			$add[] = 'glass';
			$add[] = 'glass-tint-color';
			$add[] = 'surface-glass-colored';
		} elseif ($se === 'gradient' || $se === 'blurred') {
			$add[] = 'bg-' . $se;
			$add[] = 'surface-' . $se;
		}
	}
	// Custom CSS variable for the preset's `accent` color (used by --accent
	// fallback chain in styling). We can't put a value into a class, so this
	// rides as an inline-style hook on body via a separate filter below.
	if (!empty($state['glassTint']) && $state['glassTint'] !== 'auto') {
		// Custom hex color → just emit class to enable rules; the actual color comes via inline style
		if ($state['glassTint'] === 'dark' || $state['glassTint'] === 'light') {
			$add[] = 'glass-tint-' . $state['glassTint'];
		} elseif (preg_match('/^#[0-9a-f]{3,8}$/i', $state['glassTint'])) {
			$add[] = 'glass-tint-color';
		}
	}
	// Content width — drives #th-content > * max-width + topbar alignment
	if (!empty($state['content']) && $state['content'] !== 'full') {
		$add[] = 'content-' . $state['content'];
	}
	// Topbar mode
	if (!empty($state['topbar']) && $state['topbar'] !== 'sticky') {
		$add[] = 'topbar-' . $state['topbar'];
	}
	return $classes . ' ' . implode(' ', $add);
});

// Pre-paint mode resolver — when state.mode is 'auto', resolve the OS-level
// light/dark preference BEFORE the page paints and toggle body.light
// accordingly. Without this, an auto+system-light user sees dark chrome for
// a frame until therum-customization.js runs (a small FOUM). Runs in <1ms
// because it's inline at the top of <head>. Also subscribes to live OS
// changes so flipping the system theme repaints the admin instantly.
add_action( 'admin_head', function() {
	if ( ! class_exists( 'Therum_Themes' ) ) return;
	$state = Therum_Themes::get_state();
	if ( ( $state['mode'] ?? '' ) !== 'auto' ) return;
	?>
	<script id="therum-mode-auto">
	(function(){
		var mql = window.matchMedia && window.matchMedia('(prefers-color-scheme: light)');
		function apply(){ document.body && document.body.classList.toggle('light', !!(mql && mql.matches)); }
		if (document.body) apply();
		else document.addEventListener('DOMContentLoaded', apply, { once: true });
		if (mql && mql.addEventListener) mql.addEventListener('change', apply);
	})();
	</script>
	<?php
}, 1 );

// Glass tint: when a custom color is set, inject CSS variable
add_action('admin_head', function() {
	if (!class_exists('Therum_Themes')) return;
	$state = Therum_Themes::get_state();
	if (empty($state['glassTint']) || !preg_match('/^#[0-9a-f]{3,8}$/i', $state['glassTint'])) return;
	$hex = sanitize_text_field($state['glassTint']);
	// Convert hex to RGB for use in rgba()
	$h = ltrim($hex, '#');
	if (strlen($h) === 3) $h = $h[0].$h[0].$h[1].$h[1].$h[2].$h[2];
	if (strlen($h) !== 6) return;
	$r = hexdec(substr($h, 0, 2));
	$g = hexdec(substr($h, 2, 2));
	$b = hexdec(substr($h, 4, 2));
	echo '<style id="therum-glass-tint">body.glass-tint-color { --glass-tint-rgb: ' . $r . ', ' . $g . ', ' . $b . '; }</style>';
}, 999);

// Per-preset accent color → CSS variable on body. Each theme preset declares
// its own `accent` hex. We can't smuggle a hex into a class, so emit it as an
// inline style on `body.therum-themed` via head injection. Loaded after the
// palette stylesheet so it overrides palette defaults.
add_action('admin_head', function() {
	if (!class_exists('Therum_Themes')) return;
	$state = Therum_Themes::get_state();
	if (empty($state['accent']) || !preg_match('/^#[0-9a-f]{3,8}$/i', $state['accent'])) return;
	$accent = sanitize_text_field($state['accent']);
	echo '<style id="therum-theme-accent">body.therum-themed { --accent: ' . $accent . '; --ac: ' . $accent . '; }</style>';
}, 1000);



// Theme palette CSS — extracted to /assets/therum-theme-palettes.css so the
// browser caches it once instead of inlining ~134KB into every admin page.
// Dynamic accent + glass-tint variables stay inline above (lines ~620–646).
add_action( 'admin_enqueue_scripts', function() {
	$handle = 'therum-theme-palettes';
	$src    = plugins_url( 'assets/therum-theme-palettes.css', __FILE__ );
	$path   = __DIR__ . '/assets/therum-theme-palettes.css';
	$ver    = file_exists( $path ) ? filemtime( $path ) : THERUM_OS_VERSION;
	wp_enqueue_style( $handle, $src, [], $ver );

	// Signature theme treatments — background textures, locked typography,
	// card chrome per theme. Layers on top of the variable-only palettes.
	$sig_path = __DIR__ . '/assets/therum-theme-signatures.css';
	if ( file_exists( $sig_path ) ) {
		wp_enqueue_style(
			'therum-theme-signatures',
			plugins_url( 'assets/therum-theme-signatures.css', __FILE__ ),
			[ 'therum-theme-palettes' ],
			filemtime( $sig_path )
		);
	}

	// New foundation themes — palette + signature chrome that recreates each
	// reference dashboard. Loaded LAST so it wins over the legacy palettes.
	$fd_path = __DIR__ . '/assets/therum-theme-foundations.css';
	if ( file_exists( $fd_path ) ) {
		wp_enqueue_style(
			'therum-theme-foundations',
			plugins_url( 'assets/therum-theme-foundations.css', __FILE__ ),
			[ 'therum-theme-palettes', 'therum-theme-signatures' ],
			filemtime( $fd_path )
		);
	}

	// 5 desktop themes (D1–D5) — palette + signature chrome per OS surface.
	$dk_path = __DIR__ . '/assets/therum-theme-desktop.css';
	if ( file_exists( $dk_path ) ) {
		wp_enqueue_style(
			'therum-theme-desktop',
			plugins_url( 'assets/therum-theme-desktop.css', __FILE__ ),
			[ 'therum-theme-foundations' ],
			filemtime( $dk_path )
		);
	}
} );


// ════════════════════════════════════════════════════════════════════════════
//  CUSTOMIZATION ADMIN ROUTE — from therum-customization.php
// ════════════════════════════════════════════════════════════════════════════


if ( ! defined( 'ABSPATH' ) ) exit;

// ═════════════════════════════════════════════════════════════════════════════
//  REGISTRY
// ═════════════════════════════════════════════════════════════════════════════

if ( ! class_exists( 'Therum_Customization' ) ) :

final class Therum_Customization {

	/** @var array<string,array> $tabs */
	private static $tabs = [];

	/**
	 * Register a Customization sub-page tab.
	 *
	 * @param string $id     unique id (e.g. 'branding')
	 * @param array  $args   ['label','section','icon','priority','render','desc']
	 *                       section = 'main' | 'brand' | 'defaults'
	 *                       render  = callable that echoes the tab body
	 */
	public static function register( string $id, array $args ): void {
		self::$tabs[ $id ] = wp_parse_args( $args, [
			'label'    => ucfirst( $id ),
			'section'  => 'main',
			'icon'     => 'dot',
			'priority' => 100,
			'render'   => null,
			'desc'     => '',
		] );
	}

	public static function tabs(): array {
		$tabs = apply_filters( 'therum_customization_tabs', self::$tabs );
		uasort( $tabs, fn( $a, $b ) => (int)($a['priority'] ?? 100) <=> (int)($b['priority'] ?? 100) );
		return $tabs;
	}

	public static function current_tab_id(): string {
		$tabs = self::tabs();
		$want = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( (string) $_GET['tab'] ) ) : '';
		if ( $want && isset( $tabs[ $want ] ) ) return $want;
		return array_key_first( $tabs ) ?: 'themes';
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'therum' ) );
		}
		$tabs = self::tabs();
		$cur  = self::current_tab_id();
		?>
		<div class="wrap"><div class="th-cx" data-th-cx data-nonce="<?php echo esc_attr( wp_create_nonce( 'therum_theme' ) ); ?>">
			<div class="th-cx-head">
				<div>
					<div class="th-cx-eyebrow">Admin · Studio</div>
					<h1 class="th-cx-title">Studio</h1>
					<p class="th-cx-sub">Pick what to edit on the left, see it live in the center, tune it on the right. Themes, brand kit, and behavior — one surface.</p>
				</div>
			</div>

			<div class="th-cx-grid">
				<?php self::render_nav( $tabs, $cur ); ?>

				<main class="th-cx-main">
					<?php
					$tab = $tabs[ $cur ] ?? null;
					if ( $tab && is_callable( $tab['render'] ) ) {
						call_user_func( $tab['render'], $cur, $tab );
					} else {
						self::render_stub( $cur, $tab );
					}
					?>
				</main>
			</div>
		</div></div>
		<?php
	}

	private static function render_nav( array $tabs, string $cur ): void {
		// Studio left rail — collapsible accordion groups ("what to edit").
		// The old "Customization" group is retired: the Themes studio is the
		// center canvas, not a left-nav destination. Groups render as native
		// <details> accordions (no JS); the group holding the current tab opens.
		$sections = [
			'studio'   => [ 'label' => 'Studio',    'desc' => 'Theme store and live customize panel.' ],
			'brand'    => [ 'label' => 'Brand Kit',  'desc' => 'Identity, logo, colors and fonts.' ],
			'behavior' => [ 'label' => 'Behavior',   'desc' => 'Dashboard layout, navigation, editor defaults.' ],
			'advanced' => [ 'label' => 'Advanced',   'desc' => 'Custom CSS, import and export.' ],
		];
		$grouped = [];
		foreach ( $tabs as $id => $t ) {
			$sect = $t['section'] ?? 'studio';
			$grouped[ $sect ][ $id ] = $t;
		}
		// Which group holds the current tab? That one opens; otherwise Studio.
		$cur_section = '';
		foreach ( $grouped as $sid => $items ) {
			if ( isset( $items[ $cur ] ) ) { $cur_section = $sid; break; }
		}
		?>
		<aside class="th-cx-nav">
			<?php foreach ( $sections as $sect_id => $sect ):
				$items = $grouped[ $sect_id ] ?? [];
				$open  = ( $sect_id === $cur_section ) || ( $cur_section === '' && $sect_id === 'studio' );
				?>
				<details class="th-cx-nav-group"<?php echo $open ? ' open' : ''; ?>>
					<summary class="th-cx-nav-section">
						<span><?php echo esc_html( $sect['label'] ); ?></span>
						<span class="th-cx-nav-caret" aria-hidden="true"></span>
					</summary>
					<?php if ( $items ): foreach ( $items as $id => $t ):
						$href = add_query_arg( [ 'page' => 'therum-customization', 'tab' => $id ], admin_url( 'admin.php' ) );
						$cls  = 'th-cx-nav-item' . ( $cur === $id ? ' is-active' : '' );
						?>
						<a class="<?php echo esc_attr( $cls ); ?>" href="<?php echo esc_url( $href ); ?>">
							<span class="th-cx-nav-item-dot"></span>
							<?php echo esc_html( $t['label'] ); ?>
							<?php if ( ! empty( $t['count'] ) ): ?>
							<span class="th-cx-nav-item-count"><?php echo esc_html( $t['count'] ); ?></span>
							<?php endif; ?>
						</a>
					<?php endforeach; else: ?>
						<div class="th-cx-nav-soon"><?php echo esc_html( $sect['desc'] ); ?> <em>Coming soon.</em></div>
					<?php endif; ?>
				</details>
			<?php endforeach; ?>
		</aside>
		<?php
	}

	private static function render_stub( string $id, ?array $t ): void {
		$label = $t['label'] ?? ucfirst( $id );
		?>
		<div class="th-cx-page-head">
			<div>
				<div class="th-cx-page-eyebrow">Customization · <?php echo esc_html( $label ); ?></div>
				<h2 class="th-cx-page-title"><?php echo esc_html( $label ); ?></h2>
				<p class="th-cx-page-sub"><?php echo esc_html( $t['desc'] ?? 'Coming next.' ); ?></p>
			</div>
		</div>
		<div class="th-cx-stub">
			<div class="th-cx-stub-mark">Phase 1 · scaffold</div>
			<h4 class="th-cx-stub-title"><?php echo esc_html( $label ); ?> page.</h4>
			<p class="th-cx-stub-sub">Surface registered. Renderer lands in Phase 2 — port from <code>previews/connections-and-dashboard.html</code> §<?php echo esc_html( $id ); ?>.</p>
		</div>
		<?php
	}
}

endif; // class_exists

// ═════════════════════════════════════════════════════════════════════════════
//  ADMIN ROUTE
// ═════════════════════════════════════════════════════════════════════════════

add_action( 'admin_menu', function() {
	add_submenu_page(
		'therum',                                    // parent (Therum top-level)
		__( 'Customization', 'therum' ),             // page title
		__( 'Customization', 'therum' ),             // menu label (hidden by Therum chrome)
		'manage_options',                            // cap
		'therum-customization',                      // slug
		[ 'Therum_Customization', 'render' ]         // callback
	);
}, 60 );

// Enqueue customization CSS + JS on the route
add_action( 'admin_enqueue_scripts', function( $hook ) {
	$is_cx = isset( $_GET['page'] ) && $_GET['page'] === 'therum-customization';
	if ( ! $is_cx ) return;
	$css = __DIR__ . '/assets/therum-customization.css';
	if ( file_exists( $css ) ) {
		wp_enqueue_style( 'therum-customization', plugins_url( 'assets/therum-customization.css', __FILE__ ), [ 'therum-shell' ], filemtime( $css ) );
	}
	$js = __DIR__ . '/assets/therum-customization.js';
	if ( file_exists( $js ) ) {
		wp_enqueue_script( 'therum-customization', plugins_url( 'assets/therum-customization.js', __FILE__ ), [], filemtime( $js ), true );
	}
} );

// ═════════════════════════════════════════════════════════════════════════════
//  DEFAULT TABS (registered late so other plugins can register first)
// ═════════════════════════════════════════════════════════════════════════════

add_action( 'init', function() {
	if ( ! class_exists( 'Therum_Customization' ) ) return;

	// 'active' is no longer a left-nav destination — the active-theme summary
	// card renders at the top of the Themes studio canvas itself. The renderer
	// (therum_cx_render_active) stays defined for reference / direct linking.

	$presets_total = class_exists( 'Therum_Themes' ) ? count( Therum_Themes::presets() ) : 0;
	Therum_Customization::register( 'themes', [
		'label'    => 'Themes',
		'section'  => 'studio',
		'priority' => 10,
		'desc'     => 'Theme store · saved themes · customize panel — one surface.',
		'count'    => (string) $presets_total,
		'render'   => 'therum_cx_render_themes',
	] );

	// Branding / Site Identity moved here from Settings. They share the
	// working save logic that used to live under therum-settings; the
	// renderers from therum-admin.php are wrapped in a customization
	// page-head so they read as native Admin Theme tabs.
	//
	// "Appearance" was previously its own tab — merged into "Themes" since
	// both surfaced the same picker. Themes is now the canonical visual
	// configuration tab; the Quick Controls panel on its right column covers
	// what Appearance used to expose (density, sidebar style, etc.).
	Therum_Customization::register( 'branding', [
		'label'    => 'Branding',
		'section'  => 'brand',
		'priority' => 30,
		'desc'     => 'Logo, favicon, wordmark, brand color.',
		'render'   => 'therum_cx_render_branding',
	] );

	Therum_Customization::register( 'site-identity', [
		'label'    => 'Site Identity',
		'section'  => 'brand',
		'priority' => 35,
		'desc'     => 'Title, tagline, timezone, language.',
		'render'   => 'therum_cx_render_site_identity',
	] );

	Therum_Customization::register( 'behavior', [
		'label'    => 'Behavior',
		'section'  => 'behavior',
		'priority' => 50,
		'desc'     => 'Admin menu, landing page, list density.',
		'render'   => 'therum_cx_render_behavior',
	] );

	Therum_Customization::register( 'advanced', [
		'label'    => 'Advanced',
		'section'  => 'advanced',
		'priority' => 60,
		'desc'     => 'Custom admin CSS, import & export.',
		'render'   => 'therum_cx_render_advanced',
	] );

	// ─── Tabs intentionally NOT registered ────────────────────────────────
	// The four tabs below — Login screen, List page, Dashboard, Editor —
	// were scaffolded with no backend save/load. Their controls were pure
	// fixture data: the headline inputs read from `get_bloginfo('name')`,
	// the layout/thumb pickers had hardcoded defaults, the per-role
	// override table was a fake list, no Save button did anything. Hiding
	// them until a real backend lands. The renderer functions stay in
	// this file for reference and re-activation.
	//
	// To revive a tab: implement save/load → re-add the corresponding
	// Therum_Customization::register( ... ) call below.
}, 20 );


// ═════════════════════════════════════════════════════════════════════════════
//  THEMES PAGE RENDERER
//
//  Combined: active theme summary · theme store grid · saved themes · the
//  38-option Quick Controls panel. Two-column split, panel sticky on the
//  right inside this page only (not a global rail).
// ═════════════════════════════════════════════════════════════════════════════

function therum_cx_render_themes( string $tab_id, array $tab ): void {

	// Resolve current theme + saved themes (hardcoded demo data for Phase 2;
	// real persistence to user_meta + global option lands in Phase 5/6).
	$state         = class_exists( 'Therum_Themes' ) ? Therum_Themes::get_state() : [];
	// State stores the active preset under `palette` (the preset's palette
	// id, which doubles as its preset key — 'studio', 'editorial', etc.).
	// Reading `$state['theme']` always returned null and forced the Active
	// pill onto the Studio card regardless of what was actually applied.
	$current_theme = $state['palette'] ?? 'studio';
	// State stores the light/dark preference under `mode` ('light' | 'dark' |
	// 'auto'), not a boolean `light` key — the old expression read a key that
	// never existed and, thanks to ?? binding tighter than ?:, always resolved
	// to 'dark'. Treat 'auto' as 'dark' for this indicator (matching the prior
	// effective default) while letting an explicit 'light' actually show.
	$current_mode  = ( ( $state['mode'] ?? 'auto' ) === 'light' ) ? 'light' : 'dark';
	$presets       = class_exists( 'Therum_Themes' ) ? Therum_Themes::presets() : [];
	$saved         = therum_cx_saved_themes();
	$starred       = [ 'studio', 'familiar-macos', 'familiar-lucid', 'familiar-xfinity' ];
	$hidden        = [ 'familiar-vercel' ];

	?>
	<div class="th-cx-page-head">
		<div>
			<div class="th-cx-page-eyebrow">Customization · Themes</div>
			<h2 class="th-cx-page-title">Themes &amp; customize</h2>
			<p class="th-cx-page-sub">Browse and install themes from the store, manage your saved customs, and fine-tune every design token via the customize panel on the right. Everything theme-related, one surface.</p>
		</div>
		<button type="button" class="th-cx-btn is-primary" data-th-cx-save>💾 Save current as theme</button>
	</div>

	<div class="th-cx-themes-split">
		<div class="th-cx-themes-content">

			<?php therum_cx_render_active_card( $current_theme, $presets ); ?>

			<div class="th-cx-themes-tabs" role="tablist" aria-label="Theme source">
				<button type="button" class="th-cx-themes-tab is-active" role="tab" data-themes-tab="store" aria-selected="true">
					<span class="th-cx-themes-tab-label">Theme store</span>
					<span class="th-cx-themes-tab-count"><?php echo (int) count( $presets ); ?></span>
				</button>
				<button type="button" class="th-cx-themes-tab" role="tab" data-themes-tab="saved" aria-selected="false">
					<span class="th-cx-themes-tab-label">Saved</span>
					<span class="th-cx-themes-tab-count"><?php echo (int) count( $saved ); ?></span>
				</button>
			</div>

			<div class="th-cx-themes-panes" data-themes-active="store">
				<div class="th-cx-themes-pane is-active" data-themes-pane="store">
					<?php therum_cx_render_theme_store( $presets, $current_theme, $starred, $hidden ); ?>
				</div>
				<div class="th-cx-themes-pane" data-themes-pane="saved">
					<?php therum_cx_render_saved_themes( $saved ); ?>
				</div>
			</div>

		</div>

		<aside class="th-cx-quick-controls" data-th-cx-controls>
			<?php therum_cx_render_quick_controls( $current_mode ); ?>
		</aside>
	</div>
	<?php
}

/**
 * Active theme summary card — preview tile + name + tokens + actions.
 */
function therum_cx_render_active_card( string $theme_id, array $presets ): void {
	$t = $presets[ $theme_id ] ?? null;
	if ( ! $t ) return;
	$name = $t['name']  ?? ucfirst( $theme_id );
	$desc = $t['desc']  ?? '';
	$group= $t['group'] ?? '';
	$bg   = $t['previewMain'] ?? '#0a0a0a';
	$rail = $t['previewRail'] ?? '#f5389a';
	$accent = $t['accent'] ?? '#f5389a';
	?>
	<div class="th-cx-active">
		<div class="th-cx-active-preview" style="background:<?php echo esc_attr( $bg ); ?>">
			<span class="th-cx-active-tile-dot" style="background:<?php echo esc_attr( $accent ); ?>"></span>
			<div class="th-cx-active-tile-cards">
				<div class="th-cx-active-tile-card"></div>
				<div class="th-cx-active-tile-card"></div>
				<div class="th-cx-active-tile-card"></div>
				<div class="th-cx-active-tile-card"></div>
			</div>
		</div>
		<div class="th-cx-active-info">
			<div class="th-cx-active-tag">Active · <?php echo esc_html( ucfirst( $group ) ); ?></div>
			<h2><?php echo esc_html( $name ); ?></h2>
			<p><?php echo esc_html( $desc ); ?></p>
			<div class="th-cx-active-tokens">
				<span class="th-cx-active-token"><span class="th-cx-active-token-sw" style="background:#0a0a0a;border:1px solid var(--bd)"></span>bg</span>
				<span class="th-cx-active-token"><span class="th-cx-active-token-sw" style="background:#141414"></span>surface</span>
				<span class="th-cx-active-token"><span class="th-cx-active-token-sw" style="background:<?php echo esc_attr( $accent ); ?>"></span>accent</span>
				<span class="th-cx-active-token"><span class="th-cx-active-token-sw" style="background:#ffffff"></span>text</span>
			</div>
			<div class="th-cx-active-actions">
				<button type="button" class="th-cx-btn">✎ Edit tokens</button>
				<button type="button" class="th-cx-btn">💾 Save as custom</button>
				<button type="button" class="th-cx-btn">⤓ Export</button>
			</div>
		</div>
	</div>
	<?php
}

/**
 * Theme store — every preset rendered as an installable card, stacked into
 * per-group horizontal scroll strips. Supports a simple/detailed density
 * toggle via [data-card-mode] on the section.
 */
function therum_cx_render_theme_store( array $presets, string $current_theme, array $starred, array $hidden ): void {
	$groups = [];
	foreach ( $presets as $id => $t ) {
		$g = $t['group'] ?? 'other';
		$groups[ $g ][ $id ] = $t;
	}
	$group_order  = [ 'studio-new', 'foundations', 'desktop', 'surfaces', 'familiar', 'therum', 'mecha', 'experimental', 'glass-spatial', 'other' ];
	$group_labels = [
		'studio-new'    => 'Studio · New',
		'foundations'   => 'Foundations',
		'desktop'       => 'Desktop',
		'surfaces'      => 'Surfaces',
		'familiar'      => 'Familiar',
		'therum'        => 'Therum',
		'mecha'         => 'Mecha',
		'experimental'  => 'Experimental',
		'glass-spatial' => 'Glass · Spatial',
		'other'         => 'Other',
	];
	$total = count( $presets );

	?>
	<section id="cx-store" data-cx-store data-card-mode="simple">
		<div class="th-cx-store-head">
			<div class="th-cx-store-titlebar">
				<span class="th-cx-store-title">Theme store</span>
				<span class="th-cx-section-count"><?php echo (int) $total; ?> installed</span>
			</div>
			<div class="th-cx-store-controls">
				<div class="th-cx-store-filters">
					<button class="th-cx-store-filter is-active" data-filter="all">All</button>
					<button class="th-cx-store-filter" data-filter="starred">Starred</button>
					<button class="th-cx-store-filter" data-filter="hidden">Hidden</button>
					<?php foreach ( $group_order as $g ): if ( empty( $groups[ $g ] ) ) continue; ?>
					<button class="th-cx-store-filter" data-filter="<?php echo esc_attr( $g ); ?>"><?php echo esc_html( $group_labels[ $g ] ?? ucfirst( $g ) ); ?></button>
					<?php endforeach; ?>
				</div>
				<div class="th-cx-store-tools">
					<label class="th-cx-search">
						<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
						<input type="search" placeholder="Search themes…" data-th-cx-theme-search />
					</label>
					<div class="th-cx-density-toggle" role="tablist" aria-label="Card view">
						<button type="button" class="th-cx-density-btn is-active" data-card-mode="simple" title="Simple view">
							<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
						</button>
						<button type="button" class="th-cx-density-btn" data-card-mode="detailed" title="Detailed view">
							<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="7"/><rect x="3" y="14" width="18" height="7"/></svg>
						</button>
					</div>
				</div>
			</div>
		</div>

		<div class="th-cx-theme-grid">
			<?php foreach ( $group_order as $g ):
				if ( empty( $groups[ $g ] ) ) continue;
				$strip_count = count( $groups[ $g ] );
			?>
			<div class="th-cx-strip" data-strip-group="<?php echo esc_attr( $g ); ?>">
				<div class="th-cx-strip-head">
					<span class="th-cx-strip-name"><?php echo esc_html( $group_labels[ $g ] ?? ucfirst( $g ) ); ?></span>
					<span class="th-cx-strip-count"><?php echo (int) $strip_count; ?> themes</span>
				</div>
				<div class="th-cx-strip-scroller">
					<?php foreach ( $groups[ $g ] as $id => $t ):
						$name   = $t['name'] ?? ucfirst( $id );
						$desc   = $t['desc'] ?? '';
						$bg     = $t['previewMain'] ?? '#1a1a1a';
						$rail   = $t['previewRail'] ?? '#f5389a';
						$accent = $t['accent'] ?? '#f5389a';
						$is_active = $id === $current_theme;
						$is_star   = in_array( $id, $starred, true );
						$is_hide   = in_array( $id, $hidden, true );
						$cls = 'th-cx-theme';
						if ( $is_active ) $cls .= ' is-active';
						if ( $is_star )   $cls .= ' is-saved';
						if ( $is_hide )   $cls .= ' is-hidden';
					?>
					<div class="<?php echo esc_attr( $cls ); ?>" data-theme="<?php echo esc_attr( $id ); ?>" data-group="<?php echo esc_attr( $g ); ?>" data-name="<?php echo esc_attr( strtolower( $name ) ); ?>">
						<?php if ( $is_active ): ?><span class="th-cx-theme-active-pill">Active</span><?php endif; ?>
						<div class="th-cx-theme-preview" style="background:<?php echo esc_attr( $bg ); ?>">
							<div class="th-cx-theme-rail" style="background:<?php echo esc_attr( $rail ); ?>"></div>
							<div class="th-cx-theme-cards">
								<div class="th-cx-theme-card" style="background:<?php echo esc_attr( $accent ); ?>;opacity:.2"></div>
								<div class="th-cx-theme-card" style="background:<?php echo esc_attr( $accent ); ?>;opacity:.2"></div>
								<div class="th-cx-theme-card" style="background:<?php echo esc_attr( $accent ); ?>;opacity:.2"></div>
								<div class="th-cx-theme-card" style="background:<?php echo esc_attr( $accent ); ?>;opacity:.2"></div>
							</div>
						</div>
						<div class="th-cx-theme-info" data-desc="<?php echo esc_attr( $desc ); ?>">
							<div class="th-cx-theme-name"><?php echo esc_html( $name ); ?></div>
							<div class="th-cx-theme-group"><?php echo esc_html( $group_labels[ $g ] ?? ucfirst( $g ) ); ?></div>
						</div>
						<div class="th-cx-theme-actions">
							<button type="button" class="th-cx-theme-action <?php echo $is_star ? 'is-on' : ''; ?>" data-act="star" title="Star · pin to favorites">★</button>
							<button type="button" class="th-cx-theme-action" data-act="hide" title="Hide from picker">⊘</button>
							<?php if ( ! $is_active ): ?>
							<button type="button" class="th-cx-theme-action" data-act="apply" title="Apply this theme">✓</button>
							<?php endif; ?>
						</div>
					</div>
					<?php endforeach; ?>
				</div>
			</div>
			<?php endforeach; ?>
		</div>
	</section>
	<?php
}

/**
 * Saved themes grid — user's custom theme combos. Hardcoded demo data for
 * Phase 2; persistence to user_meta lands in Phase 5/6.
 */
function therum_cx_render_saved_themes( array $saved ): void {
	?>
	<section id="cx-saved">
		<div class="th-cx-store-head">
			<span class="th-cx-store-title">Your saved themes</span>
			<span class="th-cx-section-count"><?php echo count( $saved ); ?> saved</span>
			<div class="th-cx-store-tools">
				<button type="button" class="th-cx-add">⤓ Import .json</button>
				<button type="button" class="th-cx-add">⤴ Export all</button>
				<button type="button" class="th-cx-add is-primary">＋ Save current</button>
			</div>
		</div>

		<?php if ( empty( $saved ) ): ?>
		<div class="th-cx-empty">
			<strong>No saved themes yet.</strong>
			Customize the design tokens in the panel on the right, then click <em>Save current as theme</em> at the top to lock the combo for reuse.
		</div>
		<?php else: ?>
		<div class="th-cx-saved-grid">
			<?php foreach ( $saved as $key => $s ):
				$tokens = $s['tokens'] ?? [];
				$sw     = $s['swatch'] ?? '#f5389a';
			?>
			<div class="th-cx-saved" data-saved="<?php echo esc_attr( $key ); ?>">
				<div class="th-cx-saved-head">
					<span class="th-cx-saved-sw" style="background:<?php echo esc_attr( $sw ); ?>"></span>
					<div style="flex:1">
						<div class="th-cx-saved-name"><?php echo esc_html( $s['name'] ); ?></div>
						<div class="th-cx-saved-meta"><?php echo esc_html( $s['meta'] ); ?></div>
					</div>
				</div>
				<div class="th-cx-saved-tokens">
					<?php foreach ( $tokens as $tok ): ?>
					<span class="th-cx-saved-token"><?php echo esc_html( $tok ); ?></span>
					<?php endforeach; ?>
				</div>
				<div class="th-cx-saved-foot">
					<button type="button" class="th-cx-saved-btn">Edit</button>
					<button type="button" class="th-cx-saved-btn">Export</button>
					<button type="button" class="th-cx-saved-btn <?php echo ! empty( $s['active'] ) ? 'is-primary' : ''; ?>" data-act="apply">Apply</button>
					<button type="button" class="th-cx-saved-del" data-act="delete" title="Delete saved theme">🗑</button>
				</div>
			</div>
			<?php endforeach; ?>
		</div>
		<?php endif; ?>
	</section>
	<?php
}

/**
 * Demo saved themes data. Phase 2 ships hardcoded; Phase 5/6 reads from
 * user_meta('therum_saved_themes') with a CRUD UI.
 */
function therum_cx_saved_themes(): array {
	return [
		'studio-pink' => [
			'name'   => 'Studio · Pink',
			'meta'   => 'saved Apr 28 · 2 days ago',
			'swatch' => '#f5389a',
			'tokens' => [ 'DARK', 'INTER', 'ROUND', 'GLASS' ],
			'active' => true,
		],
		'lucid-office' => [
			'name'   => 'Lucid · Office',
			'meta'   => 'saved May 2 · 11 days ago',
			'swatch' => '#00d4ff',
			'tokens' => [ 'DARK', 'INTER', 'MED', 'BREATH' ],
		],
		'editorial-reading' => [
			'name'   => 'Editorial · Reading',
			'meta'   => 'saved May 8 · 5 days ago',
			'swatch' => '#fafaf7',
			'tokens' => [ 'LIGHT', 'CRIMSON', 'MED', 'BREATH' ],
		],
	];
}

/**
 * Quick Controls panel — 38 controls grouped into 10 collapsible sections.
 * Phase 2 ships the UI; saves wire up to Therum_Themes::set_state() in Phase 5/6.
 */
function therum_cx_render_quick_controls( string $current_mode ): void {
	// Read the actual saved state so each control reflects what's persisted,
	// not the hardcoded markup defaults. Previously every render emitted the
	// same literal values (e.g. Density="breathing") regardless of what the
	// user had saved — making it look like settings weren't sticking. They
	// were, but the panel UI lied about the current value on every reload.
	$state = class_exists( 'Therum_Themes' ) ? Therum_Themes::get_state() : [];
	$s = function( string $key, $default ) use ( $state ) {
		return array_key_exists( $key, $state ) && $state[ $key ] !== '' && $state[ $key ] !== null
			? $state[ $key ]
			: $default;
	};
	?>
	<div class="th-cx-panel-head">
		<h3 class="th-cx-panel-title">Quick controls</h3>
		<span class="th-cx-panel-meta">38 options</span>
	</div>

	<div class="th-cx-panel-body">

		<?php
		$dm_on = function_exists( 'therum_desktop_mode_active_for_user' )
			? therum_desktop_mode_active_for_user()
			: ( get_user_meta( get_current_user_id(), 'desktop_mode_mode', true ) === '1' );
		therum_cx_panel_group( 'Appearance', [
			[ 'seg', 'Mode', $s( 'mode', 'auto' ), [ 'light' => 'Light', 'dark' => 'Dark', 'auto' => 'Auto' ], 'mode' ],
			[ 'toggle', 'Desktop Mode', $dm_on, 'desktopMode' ],
			[ 'swatch', 'Accent', $s( 'accent', '#e83b3b' ), [ '#f5389a', '#3b82f6', '#10b981', '#f59e0b', '#ef4444', '#a855f7', '#06b6d4', 'custom' ], 'accent' ],
			[ 'seg', 'Intensity', $s( 'intensity', 'standard' ), [ 'subtle' => 'Subtle', 'standard' => 'Standard', 'vivid' => 'Vivid' ], 'intensity' ],
		] ); ?>

		<?php therum_cx_panel_group( 'Layout', [
			[ 'seg', 'Density',     $s( 'density', 'standard' ),     [ 'compact' => 'Compact', 'comfortable' => 'Comfortable', 'breathing' => 'Breathing' ], 'density' ],
			[ 'seg', 'Sidebar',     $s( 'sidebar', 'full' ),         [ 'full' => 'Full', 'icons' => 'Icons', 'text' => 'Text' ], 'sidebar' ],
			[ 'seg', 'Variant',     $s( 'sidebarStyle', 'default' ), [ 'default' => 'Default', 'floating' => 'Floating', 'minimal' => 'Minimal' ], 'sidebarStyle' ],
			[ 'seg', 'Topbar',      $s( 'topbar', 'sticky' ),        [ 'static' => 'Static', 'sticky' => 'Sticky', 'hide' => 'Hide' ], 'topbar' ],
			[ 'seg', 'Content',     $s( 'content', 'full' ),         [ 'narrow' => 'Narrow', 'wide' => 'Wide', 'full' => 'Full' ], 'content' ],
			[ 'slider', 'Bento gap', (int) $s( 'bentoGap', 16 ), 8, 32, 'bentoGap' ],
		] ); ?>

		<?php therum_cx_panel_group( 'Surfaces', [
			[ 'toggle', 'Glass surfaces', (bool) $s( 'glass', false ), 'glass' ],
			[ 'seg', 'Glass tint', $s( 'glassTint', 'dark' ), [ 'light' => 'Light', 'dark' => 'Dark', 'colored' => 'Colored' ], 'glassTint' ],
			[ 'slider', 'Blur strength', (int) $s( 'blur', 40 ), 0, 60, 'blur' ],
			[ 'seg', 'Background', $s( 'bgImage', 'none' ), [ 'none' => 'None', 'gradient' => 'Gradient', 'pattern' => 'Pattern', 'image' => 'Image' ], 'bgImage' ],
			[ 'seg', 'Shadow', $s( 'shadow', 'soft' ), [ 'flat' => 'Flat', 'soft' => 'Soft', 'heavy' => 'Heavy' ], 'shadow' ],
		] ); ?>

		<?php therum_cx_panel_group( 'Typography', [
			[ 'select', 'Body font',    $s( 'font', 'system' ), [ 'inter' => 'Inter', 'inter-tight' => 'Inter Tight', 'space-grotesk' => 'Space Grotesk', 'dm-sans' => 'DM Sans', 'ibm-plex' => 'IBM Plex Sans', 'crimson' => 'Crimson Pro', 'playfair' => 'Playfair Display', 'halyard' => 'Halyard Display', 'system' => 'System UI' ], 'font' ],
			[ 'select', 'Display font', $s( 'displayFont', 'inter-tight' ), [ 'inter-tight' => 'Inter Tight', 'archivo-black' => 'Archivo Black', 'bebas' => 'Bebas Neue', 'halyard' => 'Halyard Display', 'audiowide' => 'Audiowide', 'orbitron' => 'Orbitron', 'caveat' => 'Caveat' ], 'displayFont' ],
			[ 'select', 'Mono font',    $s( 'monoFont', 'jetbrains' ),  [ 'jetbrains' => 'JetBrains Mono', 'ibm-plex-mono' => 'IBM Plex Mono', 'sf-mono' => 'SF Mono', 'vt323' => 'VT323' ], 'monoFont' ],
			[ 'slider', 'Base size',    (int) $s( 'baseSize', 14 ), 12, 18, 'baseSize' ],
			[ 'seg', 'Letter spacing',  $s( 'letterSpacing', 'normal' ), [ 'tight' => 'Tight', 'normal' => 'Normal', 'loose' => 'Loose' ], 'letterSpacing' ],
			[ 'seg', 'Line height',     $s( 'lineHeight', 'standard' ), [ 'tight' => 'Tight', 'standard' => 'Standard', 'relaxed' => 'Relaxed' ], 'lineHeight' ],
		] ); ?>

		<?php therum_cx_panel_group( 'Shapes', [
			[ 'seg', 'Radius',        $s( 'radius', 'medium' ),        [ 'sharp' => 'Sharp', 'medium' => 'Medium', 'round' => 'Round' ], 'radius' ],
			[ 'seg', 'Border weight',  $s( 'borderWeight', 'standard' ),[ 'hairline' => 'Hairline', 'standard' => 'Standard', 'bold' => 'Bold' ], 'borderWeight' ],
			[ 'seg', 'Card style',     $s( 'cardStyle', 'default' ),    [ 'flat' => 'Flat', 'outline' => 'Outline', 'elevated' => 'Elevated', 'default' => 'Default' ], 'cardStyle' ],
		] ); ?>

		<?php therum_cx_panel_group( 'Motion', [
			[ 'seg', 'Motion',           $s( 'motion', 'full' ),           [ 'full' => 'Full', 'reduced' => 'Reduced', 'off' => 'Off' ], 'motion' ],
			[ 'seg', 'Transition speed', $s( 'transitionSpeed', 'standard' ), [ 'instant' => 'Instant', 'snappy' => 'Snappy', 'standard' => 'Standard', 'slow' => 'Slow' ], 'transitionSpeed' ],
			[ 'toggle', 'Page transitions', (bool) $s( 'pageTransitions', true ), 'pageTransitions' ],
			[ 'toggle', 'Card hover lift',  (bool) $s( 'cardHoverLift', true ),   'cardHoverLift' ],
		] ); ?>

		<?php therum_cx_panel_group( 'Content defaults', [
			[ 'select', 'Card layout',      $s( 'cardLayout', 'hero' ), [ 'hero' => 'Hero', 'card-v1' => 'Card V1 · poster', 'card-v2' => 'Card V2 · detailed', 'card-v3' => 'Card V3 · minimal', 'compact-v1' => 'Compact V1 · tight', 'compact-v2' => 'Compact V2 · editorial', 'compact-v3' => 'Compact V3 · stacked', 'magazine' => 'Magazine' ], 'cardLayout' ],
			[ 'select', 'Thumbnail source', $s( 'thumbSource', 'gradient' ), [ 'gradient' => 'Gradient', 'featured' => 'Featured image', 'stock' => 'Stock (Picsum)', 'wireframe' => 'Wireframe schematic', 'pattern' => 'Geometric pattern' ], 'thumbSource' ],
			[ 'seg', 'List view',           $s( 'listView', 'grid' ),   [ 'grid' => 'Grid', 'table' => 'Table' ], 'listView' ],
			[ 'slider', 'Items / page',     (int) $s( 'itemsPerPage', 24 ), 10, 60, 'itemsPerPage' ],
		] ); ?>

		<?php therum_cx_panel_group( 'Accessibility', [
			[ 'seg', 'Contrast',                      $s( 'contrast', 'standard' ), [ 'standard' => 'Standard', 'high' => 'High' ], 'contrast' ],
			[ 'toggle', 'Reduce transparency',        (bool) $s( 'reduceTransparency', false ), 'reduceTransparency' ],
			[ 'toggle', 'Underline links',            (bool) $s( 'underlineLinks', false ),      'underlineLinks' ],
			[ 'toggle', 'Focus rings always visible', (bool) $s( 'focusRings', true ),           'focusRings' ],
			[ 'toggle', 'Larger click targets',       (bool) $s( 'largeTargets', false ),        'largeTargets' ],
		] ); ?>

		<?php therum_cx_panel_group( 'Advanced', [
			[ 'toggle', 'Show sidebar grip handles', (bool) $s( 'showGrips', false ),      'showGrips' ],
			[ 'toggle', 'Show keyboard shortcuts',   (bool) $s( 'showShortcuts', true ),    'showShortcuts' ],
			[ 'toggle', 'Auto-save layout changes',  (bool) $s( 'autoSave', true ),         'autoSave' ],
			[ 'toggle', 'Debug overlays (dev only)',  (bool) $s( 'debugOverlays', false ),   'debugOverlays' ],
			[ 'select', 'Code editor theme',          $s( 'codeEditorTheme', 'therum' ), [ 'therum' => 'Therum Studio', 'one-dark' => 'One Dark', 'solarized' => 'Solarized Dark', 'monokai' => 'Monokai', 'github' => 'GitHub' ], 'codeEditorTheme' ],
		], true /* collapsed */ ); ?>

	</div>

	<div class="th-cx-panel-foot">
		<button type="button" class="th-cx-foot-btn" data-act="reset">⟲ Reset</button>
		<button type="button" class="th-cx-foot-btn" data-act="random">🎲 Random</button>
		<button type="button" class="th-cx-foot-btn is-primary" data-act="save">💾 Save</button>
	</div>
	<?php
}

/**
 * Helper · render a panel group with its controls.
 *
 * @param string $name      Group title (Appearance, Layout, etc.)
 * @param array  $controls  Array of control specs: [ type, label, ...args ]
 * @param bool   $collapsed Start collapsed
 */
function therum_cx_panel_group( string $name, array $controls, bool $collapsed = false ): void {
	$cls = 'th-cx-group' . ( $collapsed ? ' is-collapsed' : '' );
	?>
	<div class="<?php echo esc_attr( $cls ); ?>">
		<div class="th-cx-group-head">
			<span class="th-cx-group-name"><?php echo esc_html( $name ); ?></span>
			<span class="th-cx-group-chev">▾</span>
		</div>
		<div class="th-cx-group-body">
			<?php foreach ( $controls as $c ): therum_cx_render_control( $c ); endforeach; ?>
		</div>
	</div>
	<?php
}

/**
 * Helper · render a single control row.
 * Each control spec is positional: [ type, label, value, options-or-min, max ].
 * An optional trailing string element is treated as a Therum_Themes::default_state()
 * field key — emitted as `data-th-state-field` on the row so the JS persists
 * the value via the therum_save_theme_field AJAX endpoint.
 */
function therum_cx_render_control( array $c ): void {
	$type = $c[0] ?? 'noop';
	switch ( $type ) {
		case 'seg':    therum_cx_seg(    $c[1], $c[2], $c[3], $c[4] ?? '' ); break;
		case 'toggle': therum_cx_toggle( $c[1], $c[2], $c[3] ?? '' ); break;
		case 'slider': therum_cx_slider( $c[1], $c[2], $c[3] ?? 0, $c[4] ?? 100, $c[5] ?? '' ); break;
		case 'select': therum_cx_select( $c[1], $c[2], $c[3], $c[4] ?? '' ); break;
		case 'swatch': therum_cx_swatch( $c[1], $c[2], $c[3], $c[4] ?? '' ); break;
	}
}

function therum_cx_seg( string $label, string $value, array $options, string $field = '' ): void {
	$row_attr = $field ? ' data-th-state-field="' . esc_attr( $field ) . '"' : '';
	?>
	<div class="th-cx-row"<?php echo $row_attr; ?>>
		<div class="th-cx-label"><?php echo esc_html( $label ); ?> <span class="th-cx-label-val"><?php echo esc_html( $value ); ?></span></div>
		<div class="th-cx-seg">
			<?php foreach ( $options as $k => $lbl ): ?>
			<button type="button" class="<?php echo $k === $value ? 'is-active' : ''; ?>" data-value="<?php echo esc_attr( $k ); ?>"><?php echo esc_html( $lbl ); ?></button>
			<?php endforeach; ?>
		</div>
	</div>
<?php }

function therum_cx_toggle( string $label, bool $on, string $field = '' ): void {
	$row_attr = $field ? ' data-th-state-field="' . esc_attr( $field ) . '"' : '';
	?>
	<div class="th-cx-toggle"<?php echo $row_attr; ?>>
		<span class="th-cx-toggle-label"><?php echo esc_html( $label ); ?></span>
		<span class="th-cx-toggle-sw <?php echo $on ? 'is-on' : ''; ?>"></span>
	</div>
<?php }

function therum_cx_slider( string $label, int $value, int $min, int $max, string $field = '' ): void {
	$row_attr = $field ? ' data-th-state-field="' . esc_attr( $field ) . '"' : '';
	?>
	<div class="th-cx-row"<?php echo $row_attr; ?>>
		<div class="th-cx-label"><?php echo esc_html( $label ); ?> <span class="th-cx-label-val"><?php echo (int) $value; ?></span></div>
		<div class="th-cx-slider-wrap">
			<input class="th-cx-slider" type="range" min="<?php echo (int) $min; ?>" max="<?php echo (int) $max; ?>" value="<?php echo (int) $value; ?>" />
			<span class="th-cx-slider-num"><?php echo (int) $value; ?></span>
		</div>
	</div>
<?php }

function therum_cx_select( string $label, string $value, array $options, string $field = '' ): void {
	$row_attr = $field ? ' data-th-state-field="' . esc_attr( $field ) . '"' : '';
	?>
	<div class="th-cx-row"<?php echo $row_attr; ?>>
		<div class="th-cx-label"><?php echo esc_html( $label ); ?></div>
		<select class="th-cx-select">
			<?php foreach ( $options as $k => $lbl ): ?>
			<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $k, $value ); ?>><?php echo esc_html( $lbl ); ?></option>
			<?php endforeach; ?>
		</select>
	</div>
<?php }

function therum_cx_swatch( string $label, string $value, array $colors, string $field = '' ): void {
	$row_attr = $field ? ' data-th-state-field="' . esc_attr( $field ) . '"' : '';
	?>
	<div class="th-cx-row"<?php echo $row_attr; ?>>
		<div class="th-cx-label"><?php echo esc_html( $label ); ?> <span class="th-cx-label-val"><?php echo esc_html( $value ); ?></span></div>
		<div class="th-cx-swatches">
			<?php foreach ( $colors as $c ):
				if ( $c === 'custom' ) {
					echo '<span class="th-cx-swatch" style="background:linear-gradient(135deg,#000 50%,#fff 50%)" title="Custom"></span>';
					continue;
				}
				$cls = 'th-cx-swatch' . ( $c === $value ? ' is-active' : '' );
			?>
			<span class="<?php echo esc_attr( $cls ); ?>" style="background:<?php echo esc_attr( $c ); ?>" data-color="<?php echo esc_attr( $c ); ?>"></span>
			<?php endforeach; ?>
		</div>
	</div>
<?php }



// ═════════════════════════════════════════════════════════════════════════════
//  PHASE 5 — REMAINING CUSTOMIZATION SUB-PAGES
//  Active theme · Branding · Login · List page defaults · Dashboard · Editor
// ═════════════════════════════════════════════════════════════════════════════

function therum_cx_render_active( string $tab_id, array $tab ): void {
	$state         = class_exists( 'Therum_Themes' ) ? Therum_Themes::get_state() : [];
	// State stores the active preset under `palette` (the preset's palette
	// id, which doubles as its preset key — 'studio', 'editorial', etc.).
	// Reading `$state['theme']` always returned null and forced the Active
	// pill onto the Studio card regardless of what was actually applied.
	$current_theme = $state['palette'] ?? 'studio';
	$presets       = class_exists( 'Therum_Themes' ) ? Therum_Themes::presets() : [];
	?>
	<div class="th-cx-page-head">
		<div>
			<div class="th-cx-page-eyebrow">Customization · Active</div>
			<h2 class="th-cx-page-title">Active theme</h2>
			<p class="th-cx-page-sub">The theme currently driving the admin chrome. Quick actions here; deep editing lives under Themes.</p>
		</div>
		<a class="th-cx-btn is-primary" href="<?php echo esc_url( add_query_arg( [ 'page' => 'therum-customization', 'tab' => 'themes' ], admin_url( 'admin.php' ) ) ); ?>">Open theme store →</a>
	</div>

	<?php therum_cx_render_active_card( $current_theme, $presets ); ?>
	<?php
}

/**
 * Branding tab — moved here from Settings. Delegates to the working renderer
 * in therum-admin.php so the save handlers, color picker, and preview keep
 * working. Wrapped in a th-cx-page-head so it matches the rest of the
 * Customization surface.
 */
function therum_cx_render_branding( string $tab_id, array $tab ): void {
	?>
	<div class="th-cx-page-head">
		<div>
			<div class="th-cx-page-eyebrow">Admin theme · Branding</div>
			<h2 class="th-cx-page-title">Branding</h2>
			<p class="th-cx-page-sub">Logo, favicon, wordmark, brand color — shown across admin chrome and the login screen.</p>
		</div>
	</div>
	<?php
	if ( function_exists( 'th_render_branding' ) ) {
		th_render_branding();
	}
}

/**
 * Appearance tab — themes preset picker (moved from Settings → Appearance).
 */
function therum_cx_render_appearance( string $tab_id, array $tab ): void {
	?>
	<div class="th-cx-page-head">
		<div>
			<div class="th-cx-page-eyebrow">Admin theme · Appearance</div>
			<h2 class="th-cx-page-title">Appearance</h2>
			<p class="th-cx-page-sub">Themes, density, sidebar style. Pick a vibe and the whole admin chrome follows.</p>
		</div>
	</div>
	<?php
	if ( class_exists( 'Therum_Settings' ) && method_exists( 'Therum_Settings', 'render_appearance' ) ) {
		Therum_Settings::render_appearance();
	}
}

/**
 * Site Identity tab — title, tagline, timezone, locale (moved from Settings).
 */
function therum_cx_render_site_identity( string $tab_id, array $tab ): void {
	?>
	<div class="th-cx-page-head">
		<div>
			<div class="th-cx-page-eyebrow">Admin theme · Site Identity</div>
			<h2 class="th-cx-page-title">Site Identity</h2>
			<p class="th-cx-page-sub">Title, tagline, timezone, date/time format, locale.</p>
		</div>
	</div>
	<?php
	if ( function_exists( 'th_render_site_identity' ) ) {
		th_render_site_identity();
	}
}

function therum_cx_render_login_screen( string $tab_id, array $tab ): void {
	?>
	<div class="th-cx-page-head">
		<div>
			<div class="th-cx-page-eyebrow">Customization · Login</div>
			<h2 class="th-cx-page-title">Login screen</h2>
			<p class="th-cx-page-sub">Therum replaces WP's default login via <code>therum-auth.php</code>. Tune visual + behavior here.</p>
		</div>
		<button type="button" class="th-cx-btn is-primary">💾 Save login screen</button>
	</div>

	<div class="th-cx-block">
		<div class="th-cx-grid-2">
			<div class="th-cx-login-preview">
				<div class="th-cx-login-card">
					<span class="th-cx-login-logo">T</span>
					<div class="th-cx-login-title">Sign in to <?php echo esc_html( get_bloginfo( 'name' ) ); ?></div>
					<div class="th-cx-login-input"></div>
					<div class="th-cx-login-input"></div>
					<div class="th-cx-login-btn">Sign in →</div>
				</div>
			</div>
			<div style="display:flex;flex-direction:column;gap:14px">
				<div class="th-cx-field"><label class="th-cx-field-label">Headline</label><input class="th-cx-input" type="text" value="Sign in to <?php echo esc_attr( get_bloginfo( 'name' ) ); ?>" /></div>
				<div class="th-cx-field"><label class="th-cx-field-label">Subheadline</label><input class="th-cx-input" type="text" placeholder="Optional supporting copy" /></div>
				<div class="th-cx-field"><label class="th-cx-field-label">Background</label>
					<div class="th-cx-seg">
						<button>Solid</button><button class="is-active">Gradient</button><button>Image</button><button>Video</button>
					</div>
				</div>
				<div class="th-cx-toggle"><span class="th-cx-toggle-label">Two-factor (TOTP)</span><span class="th-cx-toggle-sw is-on"></span></div>
				<div class="th-cx-toggle"><span class="th-cx-toggle-label">Hide "Lost password" link</span><span class="th-cx-toggle-sw"></span></div>
				<div class="th-cx-toggle"><span class="th-cx-toggle-label">Therum chrome on login page</span><span class="th-cx-toggle-sw is-on"></span></div>
			</div>
		</div>
	</div>
	<?php
}

function therum_cx_render_list_page( string $tab_id, array $tab ): void {
	// Layouts: Hero + 3 Card variants + 3 Compact variants + Magazine.
	// Previews are rendered via the OS-wide .th-os-prev primitives in
	// therum-shell.css — when a layout's visual changes, fix it there.
	$layouts = [
		'hero'       => 'Hero · image + meta below',
		'card-v1'    => 'Card V1 · poster overlay',
		'card-v2'    => 'Card V2 · detailed',
		'card-v3'    => 'Card V3 · circular · minimal',
		'compact-v1' => 'Compact V1 · tight text list',
		'compact-v2' => 'Compact V2 · editorial · circle',
		'compact-v3' => 'Compact V3 · stacked meta',
		'magazine'   => 'Magazine · 50/50 image | text',
	];
	$sources = [
		'gradient'  => 'Gradient · deterministic per post',
		'featured'  => 'Featured image · fallback to wireframe',
		'stock'     => 'Stock · Picsum, seeded by ID',
		'wireframe' => 'Wireframe · page schematic',
		'pattern'   => 'Pattern · geometric tile',
	];
	$current_layout = 'hero';
	$current_source = 'gradient';
	?>
	<div class="th-cx-page-head">
		<div>
			<div class="th-cx-page-eyebrow">Customization · List page</div>
			<h2 class="th-cx-page-title">List page defaults</h2>
			<p class="th-cx-page-sub">Default card layout + thumbnail source for every Therum list view (Pages · Posts · Case Studies). Per-post overrides still work — this sets the fallback.</p>
		</div>
		<button type="button" class="th-cx-btn is-primary">💾 Save defaults</button>
	</div>

	<div class="th-cx-block">
		<h3>Card layout</h3>
		<p class="th-cx-block-sub">Which visual frame wraps every list-page card by default.</p>
		<div class="th-cx-radio-grid">
			<?php foreach ( $layouts as $k => $label ): $active = $k === $current_layout; ?>
			<label class="th-cx-radio<?php echo $active ? ' is-active' : ''; ?>">
				<div class="th-os-prev th-os-prev--<?php echo esc_attr( $k ); ?>" data-layout="<?php echo esc_attr( $k ); ?>">
					<div class="th-os-prev__img"></div>
					<div class="th-os-prev__line"></div>
					<div class="th-os-prev__line"></div>
					<div class="th-os-prev__line"></div>
					<div class="th-os-prev__line"></div>
					<div class="th-os-prev__line"></div>
				</div>
				<div class="th-cx-radio-name"><?php echo esc_html( $label ); ?></div>
			</label>
			<?php endforeach; ?>
		</div>
	</div>

	<div class="th-cx-block">
		<h3>Thumbnail source</h3>
		<p class="th-cx-block-sub">What fills the card thumb. Each post can still override via <code>_th_card_image</code> meta.</p>
		<div class="th-cx-radio-grid">
			<?php foreach ( $sources as $k => $label ): $active = $k === $current_source; ?>
			<label class="th-cx-radio<?php echo $active ? ' is-active' : ''; ?>">
				<div class="th-os-thumb th-os-thumb--<?php echo esc_attr( $k ); ?>" data-thumb="<?php echo esc_attr( $k ); ?>"></div>
				<div class="th-cx-radio-name"><?php echo esc_html( $label ); ?></div>
			</label>
			<?php endforeach; ?>
		</div>
	</div>
	<?php
}

function therum_cx_render_dashboard( string $tab_id, array $tab ): void {
	?>
	<div class="th-cx-page-head">
		<div>
			<div class="th-cx-page-eyebrow">Customization · Dashboard</div>
			<h2 class="th-cx-page-title">Dashboard layout</h2>
			<p class="th-cx-page-sub">Default bento grid, card sizes, per-role overrides, welcome message. What every new user sees on first load.</p>
		</div>
		<button type="button" class="th-cx-btn is-primary">💾 Save as default</button>
	</div>

	<div class="th-cn-stats">
		<div class="th-cn-stat"><div class="th-cn-stat-l">Total cards</div><div class="th-cn-stat-n">12</div><div class="th-cn-stat-d">8 enabled · 4 hidden</div></div>
		<div class="th-cn-stat"><div class="th-cn-stat-l">Grid columns</div><div class="th-cn-stat-n">12</div><div class="th-cn-stat-d">dense flow</div></div>
		<div class="th-cn-stat"><div class="th-cn-stat-l">Row height</div><div class="th-cn-stat-n">120px</div><div class="th-cn-stat-d">min</div></div>
		<div class="th-cn-stat"><div class="th-cn-stat-l">Card gap</div><div class="th-cn-stat-n">16px</div><div class="th-cn-stat-d">standard</div></div>
	</div>

	<div class="th-cx-block">
		<h3>Welcome message</h3>
		<div class="th-cx-grid-2">
			<div class="th-cx-field"><label class="th-cx-field-label">Headline</label><input class="th-cx-input" type="text" value="Welcome back, {first_name}." /></div>
			<div class="th-cx-field"><label class="th-cx-field-label">Subheadline</label><input class="th-cx-input" type="text" value="Here's what's moving today." /></div>
		</div>
	</div>

	<div class="th-cx-block">
		<h3>Per-role overrides</h3>
		<table class="th-cn-table">
			<thead><tr><th>Role</th><th>Layout</th><th>Hidden cards</th><th>Welcome message</th><th style="text-align:right">Actions</th></tr></thead>
			<tbody>
				<tr><td>Administrator</td><td>Default</td><td>None</td><td>Default</td><td class="th-cn-tbl-actions"><button class="th-cn-iconbtn">✎</button></td></tr>
				<tr><td>Editor</td><td>Custom · 8 cards</td><td>Site health · Storage</td><td>"Welcome back."</td><td class="th-cn-tbl-actions"><button class="th-cn-iconbtn">✎</button></td></tr>
				<tr><td>Author</td><td>Custom · 4 cards</td><td>Connections · Stripe · Mailchimp · Notion</td><td>"Write something."</td><td class="th-cn-tbl-actions"><button class="th-cn-iconbtn">✎</button></td></tr>
				<tr><td>Contributor</td><td>Minimal</td><td>9 hidden</td><td>"Drafts only."</td><td class="th-cn-tbl-actions"><button class="th-cn-iconbtn">✎</button></td></tr>
			</tbody>
		</table>
	</div>
	<?php
}

function therum_cx_render_editor( string $tab_id, array $tab ): void {
	?>
	<div class="th-cx-page-head">
		<div>
			<div class="th-cx-page-eyebrow">Customization · Editor</div>
			<h2 class="th-cx-page-title">Editor preferences</h2>
			<p class="th-cx-page-sub">Per-post-type editor defaults · autosave · code editor theme · keyboard shortcuts · word count + spellcheck.</p>
		</div>
		<button type="button" class="th-cx-btn is-primary">💾 Save preferences</button>
	</div>

	<div class="th-cx-block">
		<h3>Default editor per post type</h3>
		<div class="th-cx-grid-3">
			<div class="th-cx-field"><label class="th-cx-field-label">Pages</label><select class="th-cx-select"><option>Bricks (default)</option><option>Classic</option><option>Block editor</option><option>Headless · Therum schema</option></select></div>
			<div class="th-cx-field"><label class="th-cx-field-label">Posts</label><select class="th-cx-select"><option>Classic (default)</option><option>Bricks</option><option>Block editor</option><option>Headless · Therum schema</option></select></div>
			<div class="th-cx-field"><label class="th-cx-field-label">Case studies</label><select class="th-cx-select"><option>Bricks (default)</option><option>Classic</option><option>Block editor</option><option>Headless · Therum schema</option></select></div>
		</div>
	</div>

	<div class="th-cx-block">
		<h3>Behavior</h3>
		<div class="th-cx-grid-2">
			<div class="th-cx-row">
				<div class="th-cx-label">Autosave interval <span class="th-cx-label-val">15s</span></div>
				<div class="th-cx-slider-wrap"><input class="th-cx-slider" type="range" min="5" max="120" value="15"/><span class="th-cx-slider-num">15</span></div>
			</div>
			<div class="th-cx-field"><label class="th-cx-field-label">Code editor theme</label><select class="th-cx-select"><option>Therum Studio (default)</option><option>One Dark</option><option>Solarized Dark</option><option>Monokai</option><option>GitHub</option></select></div>
		</div>
		<div class="th-cx-toggle"><span class="th-cx-toggle-label">Show word count in title bar</span><span class="th-cx-toggle-sw is-on"></span></div>
		<div class="th-cx-toggle"><span class="th-cx-toggle-label">Spellcheck</span><span class="th-cx-toggle-sw is-on"></span></div>
		<div class="th-cx-toggle"><span class="th-cx-toggle-label">Distraction-free mode by default</span><span class="th-cx-toggle-sw"></span></div>
		<div class="th-cx-toggle"><span class="th-cx-toggle-label">Auto-format Markdown</span><span class="th-cx-toggle-sw is-on"></span></div>
	</div>

	<div class="th-cx-block">
		<h3>Keyboard shortcuts</h3>
		<div class="th-cx-shortcuts">
			<?php foreach ( [
				[ 'Save post', [ '⌘', 'S' ] ],
				[ 'Open search', [ '⌘', 'K' ] ],
				[ 'Toggle sidebar', [ '⌘', 'B' ] ],
				[ 'Open Bricks builder', [ '⌘', 'E' ] ],
				[ 'Switch theme', [ '⌘', '⇧', 'T' ] ],
				[ 'New post', [ '⌘', '⇧', 'N' ] ],
			] as $sc ): ?>
			<div class="th-cx-shortcut">
				<span class="th-cx-shortcut-name"><?php echo esc_html( $sc[0] ); ?></span>
				<span class="th-cx-shortcut-keys">
					<?php foreach ( $sc[1] as $i => $k ): if ( $i > 0 ) echo '<span class="th-cx-kbd-plus">+</span>'; ?>
					<span class="th-cx-kbd"><?php echo esc_html( $k ); ?></span>
					<?php endforeach; ?>
				</span>
				<button class="th-cn-iconbtn">✎</button>
			</div>
			<?php endforeach; ?>
		</div>
	</div>
	<?php
}


// ═══════════════════════════════════════════════════════════════════════
// FONTS — merged from therum-fonts.php
// ═══════════════════════════════════════════════════════════════════════

if ( defined( 'THERUM_FONTS_DISABLE' ) && THERUM_FONTS_DISABLE ) return;

add_action( 'wp_head', function() {
	echo '<link rel="preconnect" href="https://fonts.googleapis.com">';
	echo '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>';
	// Geist family + the catalog theme fonts that aren't already loaded by
	// Bricks. Bricks ships: Inter, Inter Tight, JetBrains Mono, Caveat,
	// Orbitron, Playfair Display, Space Grotesk, VT323, Permanent Marker.
	// We add the rest needed by the theme catalog.
	echo '<link rel="stylesheet" href="https://fonts.googleapis.com/css2?'
		. 'family=Geist:wght@100..900'
		. '&family=Geist+Mono:wght@100..900'
		. '&family=Crimson+Pro:ital,wght@0,200..900;1,200..900'
		. '&family=DM+Sans:ital,opsz,wght@0,9..40,100..1000;1,9..40,100..1000'
		. '&family=Archivo:ital,wght@0,100..900;1,100..900'
		. '&family=Audiowide&family=Bebas+Neue'
		. '&family=IBM+Plex+Sans:ital,wght@0,100..700;1,100..700'
		. '&display=swap"'
		. '>';
}, 2 );


// ════════════════════════════════════════════════════════════════════════
// EFFECTS + TRANSITIONS — from therum-fx.php
// ════════════════════════════════════════════════════════════════════════
// Smooth scroll (Lenis), tooltips (Tippy.js), PJAX (Swup v4).
//
// Kill switches (add to wp-config.php):
//   define( 'THERUM_FX_SMOOTH_SCROLL', false );
//   define( 'THERUM_FX_TOOLTIPS',      false );
//   define( 'THERUM_FX_PJAX',          false );
//
// Merged from therum-fx.php (Phase 3 collapse, 2026-05-27).

// ═══════════════════════════════════════════════════════════════════════
// ① SMOOTH SCROLL — Lenis (from therum-smooth-scroll.php)
// ═══════════════════════════════════════════════════════════════════════
/**
 * Should we enqueue Lenis on this request? Public, non-builder pages only.
 */
function therum_smooth_scroll_enabled() {
	if ( is_admin() ) return false;
	if ( function_exists( 'bricks_is_builder' ) && bricks_is_builder() ) return false;
	// Skip wp-login.php and feed/embed requests.
	if ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) return false;
	if ( is_feed() || is_embed() ) return false;
	return true;
}

/**
 * Enqueue the Lenis library from CDN in <head> so it parses before any
 * inline scripts that reference it.
 */
add_action( 'wp_enqueue_scripts', function () {
	if ( ! therum_smooth_scroll_enabled() ) return;

	wp_enqueue_script(
		'therum-lenis',
		'https://cdn.jsdelivr.net/npm/lenis@1.1.20/dist/lenis.min.js',
		[],
		'1.1.20',
		false   // in <head>, blocking — Lenis is tiny (~6KB gzip) and needs to be ready early
	);
} );

/**
 * Boot Lenis inline right after the library loads. Inline so we don't have
 * to ship a separate JS file; the config is small and pinned to one place.
 */
add_action( 'wp_print_footer_scripts', function () {
	if ( ! therum_smooth_scroll_enabled() ) return;
	?>
<script id="therum-smooth-scroll-boot">
(function () {
	'use strict';

	// Respect prefers-reduced-motion — bypass smooth scroll entirely.
	var rm = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
	if (rm) return;

	function init() {
		if (typeof Lenis === 'undefined') return;

		var lenis = new Lenis({
			duration: 0.9,                                                    // settle window — was 1.4 which read as "input lag" on wheel
			easing: function (t) { return Math.min(1, 1.001 - Math.pow(2, -10 * t)); }, // exponential ease-out — soft landing
			direction: 'vertical',
			gestureDirection: 'vertical',
			smooth: true,
			smoothWheel: true,
			smoothTouch: false,    // mobile already has native momentum; double-applying feels laggy
			touchMultiplier: 2,
			wheelMultiplier: 1.0,  // 1:1 — was 0.85 which compounded with the slower duration
			infinite: false,
			autoResize: true,
			// Defer to native scroll inside any element tagged data-lenis-prevent
			// (e.g., the reveal-layout list which has its own overflow:auto so
			// the user can scroll through the full case-study set). Without this,
			// Lenis swallows the wheel event and the page scrolls instead.
			prevent: function (node) {
				return !!(node && node.closest && node.closest('[data-lenis-prevent], .therum-reveal__list'));
			}
		});

		// RAF loop — drives the Lenis internal smoothing.
		function raf(time) {
			lenis.raf(time);
			requestAnimationFrame(raf);
		}
		requestAnimationFrame(raf);

		// Pause Lenis when interacting with elements that need native scroll
		// (selects, code editors, anything tagged data-lenis-prevent).
		document.addEventListener('click', function (e) {
			if (e.target.closest && e.target.closest('select, .wp-editor-area, .CodeMirror, [data-lenis-prevent]')) {
				lenis.stop();
				setTimeout(function () { lenis.start(); }, 100);
			}
		});

		// Expose for other scripts (island anchor jumps, etc.) to cooperate.
		window.therumLenis = lenis;
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
</script>
	<?php
}, 5 );    // priority 5 — before other footer scripts so therumLenis is ready when they bind handlers


// ═══════════════════════════════════════════════════════════════════════
// ② TOOLTIPS — Tippy.js (from therum-tooltips.php)
// ═══════════════════════════════════════════════════════════════════════
function therum_tippy_enabled() {
	if ( is_admin() ) return false;
	if ( function_exists( 'bricks_is_builder' ) && bricks_is_builder() ) return false;
	if ( is_feed() || is_embed() ) return false;
	return true;
}

/**
 * Enqueue Popper.js + Tippy.js from CDN. Tippy depends on Popper for
 * positioning. Both ship from unpkg with subresource caching.
 */
add_action( 'wp_enqueue_scripts', function () {
	if ( ! therum_tippy_enabled() ) return;

	wp_enqueue_script(
		'therum-popper',
		'https://unpkg.com/@popperjs/core@2.11.8/dist/umd/popper.min.js',
		[],
		'2.11.8',
		false
	);
	wp_enqueue_script(
		'therum-tippy',
		'https://unpkg.com/tippy.js@6.3.7/dist/tippy-bundle.umd.min.js',
		[ 'therum-popper' ],
		'6.3.7',
		false
	);
} );

/**
 * Inject the theme CSS once. Inline so a single file change updates it
 * everywhere without an extra HTTP request.
 */
add_action( 'wp_head', function () {
	if ( ! therum_tippy_enabled() ) return;
	?>
<style id="therum-tippy-theme">
/* Therum dark micro-pill theme — matches the legacy .therum-island__tooltip
   styling so the swap to Tippy is visually transparent. */
.tippy-box[data-theme~="therum"] {
	background: rgba(14, 14, 16, 0.97) !important;
	color: #ffffff !important;
	border: 0.5px solid rgba(255, 255, 255, 0.10) !important;
	border-radius: 6px !important;
	box-shadow: none !important;
	backdrop-filter: none !important;
	-webkit-backdrop-filter: none !important;
	filter: none !important;
	font-family: var(--therum-font-mono, "JetBrains Mono", ui-monospace, monospace);
	font-size: 10px;
	font-weight: 600;
	letter-spacing: 0.10em;
	text-transform: uppercase;
	line-height: 1.2;
	text-align: center;
	padding: 0;
}
.tippy-box[data-theme~="therum"] .tippy-content {
	padding: 7px 14px;
}
/* Arrow — refined clip-path triangle matching the legacy ::after nub. */
.tippy-box[data-theme~="therum"] > .tippy-arrow {
	color: rgba(20, 20, 20, 0.92);
}
.tippy-box[data-theme~="therum"] > .tippy-arrow::before {
	border-color: transparent;
}
.tippy-box[data-theme~="therum"][data-placement^="top"] > .tippy-arrow::before    { border-top-color:    rgba(20, 20, 20, 0.92); }
.tippy-box[data-theme~="therum"][data-placement^="bottom"] > .tippy-arrow::before { border-bottom-color: rgba(20, 20, 20, 0.92); }
.tippy-box[data-theme~="therum"][data-placement^="left"] > .tippy-arrow::before   { border-left-color:   rgba(20, 20, 20, 0.92); }
.tippy-box[data-theme~="therum"][data-placement^="right"] > .tippy-arrow::before  { border-right-color:  rgba(20, 20, 20, 0.92); }
/* Italic display-type em inside content for proper-noun project names */
.tippy-box[data-theme~="therum"] .tippy-content em {
	font-family: var(--therum-font-display, "Inter Tight", sans-serif);
	font-style: italic;
	font-weight: 500;
	font-size: 11px;
	letter-spacing: 0;
	text-transform: none;
	margin: 0 1px;
}
</style>
	<?php
}, 5 );

/**
 * Boot Tippy in the footer after Tippy + Popper are loaded.
 */
add_action( 'wp_print_footer_scripts', function () {
	if ( ! therum_tippy_enabled() ) return;
	?>
<script id="therum-tippy-boot">
(function () {
	'use strict';
	if (typeof tippy === 'undefined') return;

	// Tippy default config — applies to every instance unless overridden.
	tippy.setDefaultProps({
		theme: 'therum',
		animation: 'shift-toward',
		duration: [200, 150],
		delay: [120, 60],
		offset: [0, 10],
		arrow: false,
		placement: 'bottom',
		hideOnClick: true,
		touch: ['hold', 400],
		maxWidth: 320,
		allowHTML: true,
		zIndex: 110
	});

	/**
	 * Convert any [title] attributes into [data-tippy-content] so the
	 * browser-default tooltip doesn't fire alongside Tippy. Skip if the
	 * element already has data-tippy-content or is tagged [data-no-tippy].
	 */
	function migrateTitles(root) {
		root = root || document;
		var els = root.querySelectorAll('[title]:not([data-no-tippy])');
		els.forEach(function (el) {
			var t = el.getAttribute('title');
			if (!t) return;
			if (!el.hasAttribute('data-tippy-content')) {
				el.setAttribute('data-tippy-content', t);
			}
			el.removeAttribute('title');
		});
	}

	function bind() {
		migrateTitles(document);
		// Attach to every element with explicit content. aria-label alone
		// is too noisy (every accessible button would get a tooltip), so we
		// require data-tippy-content (set either directly or via title).
		tippy('[data-tippy-content]:not([data-no-tippy])');
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', bind);
	} else {
		bind();
	}

	// Re-bind on dynamic DOM changes (e.g., JS-injected case study cards,
	// overlay footer rewrite, modes pill labels). Throttled MutationObserver.
	if (typeof MutationObserver === 'function') {
		var pending = false;
		var observer = new MutationObserver(function () {
			if (pending) return;
			pending = true;
			requestAnimationFrame(function () {
				pending = false;
				migrateTitles(document);
				tippy('[data-tippy-content]:not([data-no-tippy]):not(.tippy-active)');
			});
		});
		observer.observe(document.body, { childList: true, subtree: true, attributes: true, attributeFilter: ['title', 'data-tippy-content'] });
	}

	// Expose for other scripts (island back/forward labels update at runtime).
	window.therumTippy = tippy;
})();
</script>
	<?php
}, 8 );    // priority 8 — after Tippy lib but before scripts that set data-tippy-content


// ═══════════════════════════════════════════════════════════════════════
// ③ PJAX — Swup v4 (from therum-pjax.php)
// ═══════════════════════════════════════════════════════════════════════
function therum_pjax_enabled() {
	if ( is_admin() ) return false;
	if ( function_exists( 'bricks_is_builder' ) && bricks_is_builder() ) return false;
	if ( is_feed() || is_embed() ) return false;
	return true;
}

/**
 * Enqueue swup.js from CDN in <head>. Tiny (~10 KB gzip).
 */
add_action( 'wp_enqueue_scripts', function () {
	if ( ! therum_pjax_enabled() ) return;

	wp_enqueue_script(
		'therum-swup',
		// 4.8.6 never existed on npm (versions skip from 4.8.3 to 4.9.0), so this
		// 404'd and Swup silently failed to load. Pinned to the real 4.9.0 UMD.
		'https://unpkg.com/swup@4.9.0/dist/Swup.umd.js',
		[],
		'4.9.0',
		false   // <head> — needs to be available before footer boot script
	);
} );

/**
 * Subresource Integrity for the third-party CDN libraries. The browser refuses
 * to execute a script whose bytes don't match the pinned sha384 hash, so a
 * compromised or swapped CDN file can't run. Hashes are tied to the exact
 * pinned versions above — bump the version AND the hash together (recompute via
 * `curl -s <url> | openssl dgst -sha384 -binary | openssl base64 -A`).
 */
add_filter( 'script_loader_tag', function( $tag, $handle ) {
	static $sri = [
		'therum-lenis'  => 'sha384-eD5ubmuCcvTdCADWuSchJYE7wcj2nzNr0itbrh6w/KJyBiltSZ8w3VFRBSWu5YQ8',
		'therum-popper' => 'sha384-I7E8VVD/ismYTF4hNIPjVp/Zjvgyol6VFvRkX/vR+Vc4jQkC+hVqc2pM8ODewa9r',
		'therum-tippy'  => 'sha384-AiTRpehQ7zqeua0Ypfa6Q4ki/ddhczZxrKtiQbTQUlJIhBkTeyoZP9/W/5ulFt29',
		'therum-swup'   => 'sha384-LKlKA1JCkFDiUBfg8G6/e3MqT/Nx6KIjDiQ8pCX7YApw6dUSsp2XkFe4rDT2W8eY',
	];
	if ( ! isset( $sri[ $handle ] ) ) return $tag;
	// Avoid double-injecting if another filter already added integrity.
	if ( strpos( $tag, 'integrity=' ) !== false ) return $tag;
	$attrs = sprintf( ' integrity="%s" crossorigin="anonymous"', esc_attr( $sri[ $handle ] ) );
	return str_replace( ' src=', $attrs . ' src=', $tag );
}, 10, 2 );

/**
 * Mark <body> with a class so PJAX-specific CSS can scope selectors.
 */
add_filter( 'body_class', function ( $classes ) {
	if ( therum_pjax_enabled() ) $classes[] = 'has-pjax';
	return $classes;
} );

/**
 * Page-transition CSS.
 *
 * Swup v4 adds these classes to <html> during a navigation:
 *   is-changing   — whole visit lifecycle
 *   is-animating  — while any animation is in flight
 *   is-leaving    — out phase (before content swap)
 *   is-rendering  — in phase (after content swap)
 *
 * We drive the transition purely via CSS — no JS animation library
 * required. The content wrapper fades + slides out, the new content
 * fades + slides in. 260 ms total per phase, ease cubic.
 *
 * Prefers-reduced-motion: durations collapse to 1 ms so PJAX still
 * avoids full-page reloads without playing motion.
 */
add_action( 'wp_head', function () {
	if ( ! therum_pjax_enabled() ) return;
	?>
<style id="therum-pjax-css">
/* ── Transition container ─────────────────────────────────────── */
#brx-content {
	transition: opacity 0.26s cubic-bezier(0.4, 0, 0.2, 1),
	            transform 0.26s cubic-bezier(0.4, 0, 0.2, 1);
	will-change: opacity, transform;
}

/* OUT — page leaves upward, fades */
html.is-leaving #brx-content {
	opacity: 0;
	transform: translateY(-7px);
}

/* IN — new page arrives from below, fades up */
html.is-rendering #brx-content {
	opacity: 0;
	transform: translateY(9px);
}

/* ── Progress bar ─────────────────────────────────────────────── */
#thp-bar {
	position: fixed;
	top: 0;
	left: 0;
	width: 100%;
	height: 2px;
	z-index: 100000;
	pointer-events: none;
	transform-origin: left center;
	transform: scaleX(0);
	opacity: 0;
	background: linear-gradient(
		90deg,
		var(--thp-color, #6366f1) 0%,
		color-mix(in srgb, var(--thp-color, #6366f1) 70%, transparent) 100%
	);
	/* fast expand, slow fade-out */
	transition: transform 0.18s ease, opacity 0.35s ease;
}
#thp-bar.thp-loading {
	transform: scaleX(0.55);
	opacity: 1;
	transition: transform 0.22s ease;
}
#thp-bar.thp-complete {
	transform: scaleX(1);
	opacity: 0;
	transition: transform 0.14s ease, opacity 0.4s ease 0.12s;
}

/* ── Reduced-motion override ──────────────────────────────────── */
@media (prefers-reduced-motion: reduce) {
	#brx-content,
	#thp-bar,
	#thp-bar.thp-loading,
	#thp-bar.thp-complete {
		transition-duration: 1ms !important;
	}
}
</style>
	<?php
}, 6 );    // after Tippy styles (5), before body output

/**
 * Inline boot — runs after swup loads. Configures swup to swap #brx-content,
 * wires the progress bar, and broadcasts re-init signals for other scripts.
 */
add_action( 'wp_print_footer_scripts', function () {
	if ( ! therum_pjax_enabled() ) return;
	?>
<script id="therum-pjax-boot">
(function () {
	'use strict';
	if (typeof Swup === 'undefined') return;

	// ── Progress bar ─────────────────────────────────────────────────────
	var bar = document.createElement('div');
	bar.id  = 'thp-bar';
	document.body.appendChild(bar);

	function barStart() {
		bar.classList.remove('thp-complete');
		// Force reflow so the scaleX(0) baseline is painted before we add .thp-loading.
		bar.offsetWidth; // eslint-disable-line no-unused-expressions
		bar.classList.add('thp-loading');
	}
	function barDone() {
		bar.classList.remove('thp-loading');
		bar.classList.add('thp-complete');
		// Clean up after the fade-out completes (~550 ms).
		setTimeout(function () { bar.classList.remove('thp-complete'); }, 600);
	}

	// ── Swup instance ─────────────────────────────────────────────────────
	var swup = new Swup({
		// Swap only the page-content wrapper. The admin float, dynamic island,
		// modes pill, and overlay menu all live outside and persist.
		containers: ['#brx-content'],

		// Skip links that should load natively.
		linkSelector: 'a[href]:not([data-no-pjax]):not([download]):not([target="_blank"])',
		ignoreVisit: function (url) {
			try {
				var u = new URL(url, window.location.href);
				if (u.origin !== window.location.origin) return true;
				// Let the browser handle file downloads directly.
				if (/\.(pdf|zip|dmg|exe|csv|xlsx?|docx?|pptx?|mp4|mov|webm|mp3|wav)$/i.test(u.pathname)) return true;
			} catch (e) { return true; }
			return false;
		},

		// CSS-driven timing: Swup reads transition-duration from #brx-content
		// after is-leaving / is-rendering classes are applied. No JS timer needed.
		animationSelector: '#brx-content',
		animationDuration: 300, // hard cap fallback (ms) if CSS read fails

		plugins: []
	});

	// ── Progress bar hooks ───────────────────────────────────────────────
	swup.hooks.on('visit:start',  barStart);
	swup.hooks.on('page:view',    barDone);

	// ── Re-init after content swap ────────────────────────────────────────
	// Broadcast a custom event so every script that needs to scan the new
	// DOM can re-bind without being coupled to Swup directly.
	function reinit() {
		document.dispatchEvent(new CustomEvent('therum:content-replaced', { bubbles: true }));

		// Re-scan for Tippy targets on the new content.
		if (window.therumTippy && typeof window.therumTippy === 'function') {
			window.therumTippy('[data-tippy-content]:not([data-no-tippy]):not(.tippy-active)');
		}

		// Re-init the dynamic island (panel rebuild from new H2s, progress ring).
		if (window.TherumIsland && typeof window.TherumIsland.refresh === 'function') {
			window.TherumIsland.refresh();
		}

		// Resync Lenis scroll dimensions after content swap.
		if (window.therumLenis && typeof window.therumLenis.resize === 'function') {
			window.therumLenis.resize();
		}
	}

	swup.hooks.on('content:replace', reinit);
	swup.hooks.on('page:view',       reinit);

	// ── Swap Bricks inline CSS on every page transition ──────────────────
	// Swup only swaps #brx-content, so the <head> (which holds Bricks's
	// customCss + page-specific styles) stays frozen from first page load.
	// Without this hook, any edit to customCss in the DB never shows up
	// during PJAX navigation — you have to full-reload to pick it up.
	swup.hooks.on('content:replace', function (visit) {
		try {
			var nextDoc = new DOMParser().parseFromString(visit.to.html, 'text/html');
			// IDs Bricks uses for inline style blocks that hold customCss + variables.
			var ids = [
				'bricks-frontend-inline-css',
				'bricks-frontend-inline-inline-css',
				'global-styles-inline-css',
				'therum-tippy-theme',
				'therum-pjax-css'
			];
			ids.forEach(function (id) {
				var fresh = nextDoc.getElementById(id);
				var here  = document.getElementById(id);
				if (fresh && here && fresh.textContent !== here.textContent) {
					here.textContent = fresh.textContent;
				} else if (fresh && !here) {
					document.head.appendChild(fresh.cloneNode(true));
				}
			});
		} catch (e) { /* fall through — old styles persist */ }
	});

	// ── Disable Swup's in-memory cache during dev ────────────────────────
	// Stops back/forward nav from showing a stale HTML snapshot.
	if (swup.cache && typeof swup.cache.clear === 'function') {
		swup.hooks.on('page:view', function () { swup.cache.clear(); });
	}

	// Signal to CSS that PJAX is active (suspends any non-PJAX curtain effect).
	document.documentElement.classList.add('has-pjax');

	// Expose for debugging / external hooks.
	window.therumSwup = swup;
})();
</script>
	<?php
}, 12 );    // priority 12 — after Tippy + Lenis boots so swup can call into them on reinit


// ════════════════════════════════════════════════════════════════════════
// LIGHT-DEFAULT BODY CLASS — from therum-theme-default.php
// ════════════════════════════════════════════════════════════════════════
// Server-renders class="therum-light" on <body> so first-paint is the
// brand's light theme. Prevents FOUC when the JS theme toggler is still
// parsing — returning visitors with "dark" stored have it removed by JS.
//
// Merged from therum-theme-default.php (Phase 3 collapse, 2026-05-27).

add_filter( 'body_class', function ( array $classes ) {
	// Admin/builder previews keep their own state.
	if ( is_admin() ) return $classes;
	if ( function_exists( 'bricks_is_builder' ) && bricks_is_builder() ) return $classes;

	if ( ! in_array( 'therum-light', $classes, true ) ) {
		$classes[] = 'therum-light';
	}
	return $classes;
} );


// ════════════════════════════════════════════════════════════════════════════
//  STUDIO · BEHAVIOR  — real, per-user admin behaviors (persist + take effect)
// ════════════════════════════════════════════════════════════════════════════

function therum_behavior_state(): array {
	$defaults = [ 'login_landing' => 'default', 'fold_menu' => false, 'rows_per_page' => 0 ];
	$uid = get_current_user_id();
	$s   = $uid ? get_user_meta( $uid, 'therum_behavior', true ) : [];
	return is_array( $s ) ? array_merge( $defaults, $s ) : $defaults;
}

function therum_cx_render_behavior( string $tab_id, array $tab ): void {
	$s    = therum_behavior_state();
	$opts = [ 'default' => 'WordPress default', 'dashboard' => 'Dashboard', 'therum' => 'Therum', 'posts' => 'Posts', 'pages' => 'Pages' ];
	?>
	<div class="th-cx-page-head"><div>
		<div class="th-cx-page-eyebrow">Studio · Behavior</div>
		<h2 class="th-cx-page-title">Behavior</h2>
		<p class="th-cx-page-sub">How the admin behaves for your account — where you land after login, the menu state, and list density. Saves to your user profile and takes effect immediately.</p>
	</div></div>
	<div class="th-cx-card" data-th-behavior>
		<div class="th-cx-field">
			<label class="th-cx-field-label">Landing page after login</label>
			<select class="th-cx-select" data-behavior="login_landing">
				<?php foreach ( $opts as $k => $lbl ): ?>
				<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $s['login_landing'], $k ); ?>><?php echo esc_html( $lbl ); ?></option>
				<?php endforeach; ?>
			</select>
		</div>
		<div class="th-cx-field">
			<label class="th-cx-field-label">List rows per page <span style="color:var(--tx3)">(0 = WordPress default)</span></label>
			<input class="th-cx-input" type="number" min="0" max="200" value="<?php echo (int) $s['rows_per_page']; ?>" data-behavior="rows_per_page" />
		</div>
		<div class="th-cx-toggle" data-behavior-toggle="fold_menu">
			<span class="th-cx-toggle-label">Collapse the WordPress admin menu by default</span>
			<span class="th-cx-toggle-sw <?php echo $s['fold_menu'] ? 'is-on' : ''; ?>"></span>
		</div>
		<div class="th-cx-save-row">
			<button type="button" class="th-cx-btn is-primary" data-behavior-save>Save behavior</button>
			<span class="th-cx-save-note" data-behavior-note></span>
		</div>
	</div>
	<?php
}

add_action( 'wp_ajax_therum_save_behavior', function () {
	if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'unauthorized', 403 );
	if ( ! wp_verify_nonce( $_POST['nonce'] ?? '', 'therum_theme' ) ) wp_send_json_error( 'Invalid or expired nonce.', 403 );
	$landing = sanitize_key( $_POST['login_landing'] ?? 'default' );
	if ( ! in_array( $landing, [ 'default', 'dashboard', 'therum', 'posts', 'pages' ], true ) ) $landing = 'default';
	$fold = ! empty( $_POST['fold_menu'] ) && $_POST['fold_menu'] !== 'false';
	$rows = max( 0, min( 200, (int) ( $_POST['rows_per_page'] ?? 0 ) ) );
	$state = [ 'login_landing' => $landing, 'fold_menu' => $fold, 'rows_per_page' => $rows ];
	update_user_meta( get_current_user_id(), 'therum_behavior', $state );
	set_user_setting( 'mfold', $fold ? 'f' : 'o' ); // apply menu fold immediately
	wp_send_json_success( $state );
} );

// Apply: login landing redirect.
add_filter( 'login_redirect', function ( $redirect_to, $requested, $user ) {
	if ( ! ( $user instanceof WP_User ) || ! $user->exists() ) return $redirect_to;
	$s       = get_user_meta( $user->ID, 'therum_behavior', true );
	$landing = is_array( $s ) ? ( $s['login_landing'] ?? 'default' ) : 'default';
	switch ( $landing ) {
		case 'therum':    return admin_url( 'admin.php?page=therum' );
		case 'posts':     return admin_url( 'edit.php' );
		case 'pages':     return admin_url( 'edit.php?post_type=page' );
		case 'dashboard': return admin_url( 'index.php' );
	}
	return $redirect_to;
}, 20, 3 );

// Apply: list rows per page.
add_filter( 'edit_posts_per_page', function ( $per_page, $post_type ) {
	$r = (int) ( therum_behavior_state()['rows_per_page'] ?? 0 );
	return $r > 0 ? $r : $per_page;
}, 20, 2 );


// ════════════════════════════════════════════════════════════════════════════
//  STUDIO · ADVANCED  — custom admin CSS + theme import/export (real saves)
// ════════════════════════════════════════════════════════════════════════════

function therum_get_custom_admin_css(): string {
	$uid = get_current_user_id();
	$css = $uid ? get_user_meta( $uid, 'therum_admin_custom_css', true ) : '';
	return is_string( $css ) ? $css : '';
}

// Author is a manage_options user (already trusted with theme/plugin file edit);
// this only prevents breaking out of the <style> wrapper.
function therum_sanitize_admin_css( string $css ): string {
	return str_ireplace( [ '</style', '<script', '</script', 'javascript:', 'expression(' ], '', $css );
}

function therum_cx_render_advanced( string $tab_id, array $tab ): void {
	$css    = therum_get_custom_admin_css();
	$state  = class_exists( 'Therum_Themes' ) ? Therum_Themes::get_state() : [];
	$export = wp_json_encode( $state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
	?>
	<div class="th-cx-page-head"><div>
		<div class="th-cx-page-eyebrow">Studio · Advanced</div>
		<h2 class="th-cx-page-title">Advanced</h2>
		<p class="th-cx-page-sub">Custom admin CSS and theme import / export. Custom CSS is injected on every admin page after the active theme — scope rules with <code>body.theme-…</code> selectors.</p>
	</div></div>

	<div class="th-cx-card">
		<label class="th-cx-field-label">Custom admin CSS</label>
		<textarea class="th-cx-textarea" data-custom-css spellcheck="false" style="min-height:200px" placeholder="/* e.g. */ body.theme-fd-violet #th-top { box-shadow: 0 0 24px rgba(124,92,255,.4); }"><?php echo esc_textarea( $css ); ?></textarea>
		<div class="th-cx-save-row">
			<button type="button" class="th-cx-btn is-primary" data-custom-css-save>Save CSS</button>
			<span class="th-cx-save-note" data-custom-css-note></span>
		</div>
	</div>

	<div class="th-cx-card" style="margin-top:16px">
		<label class="th-cx-field-label">Export theme</label>
		<p class="th-cx-page-sub" style="margin:4px 0 8px">Your current theme state as JSON.</p>
		<textarea class="th-cx-textarea" readonly data-export-json style="min-height:140px"><?php echo esc_textarea( $export ); ?></textarea>
		<div class="th-cx-save-row"><button type="button" class="th-cx-btn" data-export-download>⤓ Download .json</button></div>
	</div>

	<div class="th-cx-card" style="margin-top:16px">
		<label class="th-cx-field-label">Import theme</label>
		<p class="th-cx-page-sub" style="margin:4px 0 8px">Paste a theme JSON export and apply it to your account. Unknown keys are ignored.</p>
		<textarea class="th-cx-textarea" data-import-json spellcheck="false" style="min-height:140px" placeholder='{ "palette": "fd-violet", "mode": "auto", ... }'></textarea>
		<div class="th-cx-save-row">
			<button type="button" class="th-cx-btn is-primary" data-import-apply>Import &amp; apply</button>
			<span class="th-cx-save-note" data-import-note></span>
		</div>
	</div>
	<?php
}

add_action( 'wp_ajax_therum_save_custom_css', function () {
	if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'unauthorized', 403 );
	if ( ! wp_verify_nonce( $_POST['nonce'] ?? '', 'therum_theme' ) ) wp_send_json_error( 'Invalid or expired nonce.', 403 );
	$css = therum_sanitize_admin_css( (string) wp_unslash( $_POST['css'] ?? '' ) );
	update_user_meta( get_current_user_id(), 'therum_admin_custom_css', $css );
	wp_send_json_success( [ 'len' => strlen( $css ) ] );
} );

add_action( 'wp_ajax_therum_import_theme', function () {
	if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'unauthorized', 403 );
	if ( ! wp_verify_nonce( $_POST['nonce'] ?? '', 'therum_theme' ) ) wp_send_json_error( 'Invalid or expired nonce.', 403 );
	if ( ! class_exists( 'Therum_Themes' ) ) wp_send_json_error( 'Theme engine unavailable.', 500 );
	$data = json_decode( (string) wp_unslash( $_POST['json'] ?? '' ), true );
	if ( ! is_array( $data ) ) wp_send_json_error( 'Invalid JSON.', 400 );
	$clean = array_intersect_key( $data, Therum_Themes::default_state() );
	if ( empty( $clean ) ) wp_send_json_error( 'No recognizable theme keys found.', 400 );
	$state = array_merge( Therum_Themes::get_state(), $clean );
	Therum_Themes::save_user_state( $state );
	wp_send_json_success( $state );
} );

// Inject saved custom admin CSS on every admin page, after theme styles.
add_action( 'admin_head', function () {
	$css = therum_get_custom_admin_css();
	if ( $css !== '' ) {
		echo "\n<style id=\"therum-custom-admin-css\">\n" . $css . "\n</style>\n";
	}
}, 999 );
