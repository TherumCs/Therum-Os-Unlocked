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

require_once __DIR__ . '/_therum/design/therum-design-pages.php';

Therum_Design_Pages::instance();

// ════════════════════════════════════════════════════════════════════════
// NATIVE DESIGN (menus + widgets replacement) — from therum-native-design.php
// ════════════════════════════════════════════════════════════════════════


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

require_once __DIR__ . '/_therum/design/therum-themes.php';

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
	if (!empty($state['content']) && $state['content'] !== 'regular') {
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



// THEME CSS — new architecture only. The legacy theme libraries
// (therum-theme-palettes/signatures/foundations/desktop) are REMOVED: they
// forced the dark palette with !important (body.therum-themed.glass-tint-dark)
// and fought every theme. Each morph theme now ships ONE self-contained
// stylesheet scoped to body.theme-<palette> that owns its full palette — no
// legacy layer, no override hacks.
add_action( 'admin_enqueue_scripts', function() {
	// Quick-Controls behavior (density/radius/shadow/glass/blur/fonts/etc.) —
	// loaded first so theme palettes layer on top.
	$ctl = __DIR__ . '/assets/therum-controls.css';
	if ( file_exists( $ctl ) ) {
		wp_enqueue_style( 'therum-controls', plugins_url( 'assets/therum-controls.css', __FILE__ ), [], filemtime( $ctl ) );
	}
	foreach ( [ 'theme-m00', 'theme-m01', 'theme-m02' ] as $morph ) {
		$mp = __DIR__ . '/assets/' . $morph . '.css';
		if ( file_exists( $mp ) ) {
			wp_enqueue_style(
				'therum-' . $morph,
				plugins_url( 'assets/' . $morph . '.css', __FILE__ ),
				[],
				filemtime( $mp )
			);
		}
	}

	// theme-m02 header injector — adds the N2 chrome (+ button, avatar/name/role
	// block, date pill + tasks CTA + calendar, mic). JS guards on body.theme-m02
	// at runtime, so the script is harmless if other themes are active.
	$m02js = __DIR__ . '/assets/theme-m02-header.js';
	if ( file_exists( $m02js ) ) {
		wp_enqueue_script(
			'therum-theme-m02-header',
			plugins_url( 'assets/theme-m02-header.js', __FILE__ ),
			[],
			filemtime( $m02js ),
			true
		);
		$cu = wp_get_current_user();
		$role_slug = is_array( $cu->roles ?? null ) && ! empty( $cu->roles ) ? $cu->roles[0] : '';
		// Resolve a sensible page title for the topbar stack — current admin
		// page title where available, falling back to the menu label.
		$screen   = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$pgtitle  = '';
		if ( $screen && ! empty( $screen->base ) ) {
			if ( $screen->base === 'dashboard' )       $pgtitle = 'Dashboard';
			elseif ( $screen->base === 'post' )        $pgtitle = ucfirst( $screen->post_type ?: 'Post' );
			elseif ( $screen->base === 'edit' )        $pgtitle = ucfirst( ( $screen->post_type ?: 'post' ) . 's' );
			elseif ( $screen->base === 'upload' )      $pgtitle = 'Media';
			elseif ( $screen->base === 'users' )       $pgtitle = 'Users';
			elseif ( $screen->base === 'plugins' )     $pgtitle = 'Plugins';
			elseif ( $screen->base === 'themes' )      $pgtitle = 'Themes';
			elseif ( $screen->base === 'options-general' ) $pgtitle = 'Settings';
		}
		if ( ! $pgtitle && function_exists( 'get_admin_page_title' ) ) {
			$pgtitle = wp_strip_all_tags( get_admin_page_title() );
		}
		wp_localize_script( 'therum-theme-m02-header', 'THERUM_M02_HEADER', [
			'name'        => $cu->display_name ?? '',
			'role'        => $role_slug ? ucwords( str_replace( '_', ' ', $role_slug ) ) : '',
			'avatar'      => $cu->ID ? get_avatar_url( $cu->ID, [ 'size' => 128 ] ) : '',
			'initial'     => strtoupper( mb_substr( $cu->display_name ?? 'U', 0, 1 ) ),
			'siteName'    => get_bloginfo( 'name' ),
			'pageTitle'   => $pgtitle ?: 'Dashboard',
			'siteIcon'    => function_exists( 'get_site_icon_url' ) ? ( get_site_icon_url( 128 ) ?: '' ) : '',
			'newPostUrl'  => admin_url( 'post-new.php' ),
			'tasksUrl'    => admin_url( 'admin.php?page=therum' ),
			'calendarUrl' => admin_url( 'index.php' ),
		] );
	}
} );


// ════════════════════════════════════════════════════════════════════════════
//  CUSTOMIZATION ADMIN ROUTE — from therum-customization.php
// ════════════════════════════════════════════════════════════════════════════



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
	<div class="th-cx-themes-split">
		<div class="th-cx-themes-content">

			<div class="th-cx-page-head">
				<div>
					<div class="th-cx-page-eyebrow">Customization · Themes</div>
					<h2 class="th-cx-page-title">Themes &amp; customize</h2>
					<p class="th-cx-page-sub">Browse and install themes from the store, manage your saved customs, and fine-tune every design token via the customize panel on the right. Everything theme-related, one surface.</p>
				</div>
				<button type="button" class="th-cx-btn is-primary" data-th-cx-save>💾 Save current as theme</button>
			</div>

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
	// Real per-user store — no demo data. Empty until the user saves a theme.
	$uid   = get_current_user_id();
	$saved = $uid ? get_user_meta( $uid, 'therum_saved_themes', true ) : [];
	return is_array( $saved ) ? $saved : [];
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
		// Theme-specific controls — Canvas gradient appears only for Theme 01.
		if ( ( $state['palette'] ?? '' ) === 'm01' && function_exists( 'therum_cx_render_m01_gradient_control' ) ) {
			therum_cx_render_m01_gradient_control();
		}
		?>

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
			[ 'seg', 'Content',     $s( 'content', 'regular' ),      [ 'narrow' => 'Narrow', 'regular' => 'Regular', 'full' => 'Full' ], 'content' ],
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
	if ( function_exists( 'therum_render_branding' ) ) {
		therum_render_branding();
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
	if ( function_exists( 'therum_render_site_identity' ) ) {
		therum_render_site_identity();
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
	if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ?? '' ) ), 'therum_theme' ) ) wp_send_json_error( 'Invalid or expired nonce.', 403 );
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
//
// Loop until stable. Single-pass str_ireplace is bypassable by nesting —
// "<scr<scriptipt>" becomes "<script>" after one pass. Iterate until either a
// fixed point or a hard cap (defensive bound, can't actually run more than a
// handful of passes on real input).
function therum_sanitize_admin_css( string $css ): string {
	$banned = [ '</style', '<script', '</script', 'javascript:', 'expression(' ];
	for ( $i = 0; $i < 12; $i++ ) {
		$next = str_ireplace( $banned, '', $css );
		if ( $next === $css ) return $next;
		$css = $next;
	}
	return $css;
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
	if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ?? '' ) ), 'therum_theme' ) ) wp_send_json_error( 'Invalid or expired nonce.', 403 );
	$css = therum_sanitize_admin_css( (string) wp_unslash( $_POST['css'] ?? '' ) );
	update_user_meta( get_current_user_id(), 'therum_admin_custom_css', $css );
	wp_send_json_success( [ 'len' => strlen( $css ) ] );
} );

add_action( 'wp_ajax_therum_import_theme', function () {
	if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'unauthorized', 403 );
	if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ?? '' ) ), 'therum_theme' ) ) wp_send_json_error( 'Invalid or expired nonce.', 403 );
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


// ════════════════════════════════════════════════════════════════════════════
//  THEME 01 · CANVAS GRADIENT — customizable, per-user, live + persisted
// ════════════════════════════════════════════════════════════════════════════

function therum_m01_gradient(): array {
	$d   = [ 'g1' => '#F5EFE0', 'g2' => '#F4ECD4', 'g3' => '#F7E7AE', 'angle' => 165, 'flat' => false ];
	$uid = get_current_user_id();
	$v   = $uid ? get_user_meta( $uid, 'therum_m01_gradient', true ) : [];
	return is_array( $v ) ? array_merge( $d, $v ) : $d;
}

function therum_cx_render_m01_gradient_control(): void {
	$g = therum_m01_gradient();
	$presets = [
		'Butter' => [ '#F5EFE0', '#F4ECD4', '#F7E7AE' ],
		'Blush'  => [ '#F6ECEC', '#F3DEDE', '#F7CFD8' ],
		'Mint'   => [ '#EAF1EA', '#DFEEDF', '#C9E7CF' ],
		'Sky'    => [ '#EAEEF6', '#DCE6F4', '#C7D8F2' ],
		'Linen'  => [ '#F4F2EE', '#EDE9E1', '#E6E0D6' ],
		'Slate'  => [ '#23262E', '#2E323C', '#3A3F4B' ],
	];
	?>
	<div class="th-cx-grad" data-m01-grad>
		<div class="th-cx-grad-head">Canvas gradient <span>Theme 01</span></div>
		<div class="th-cx-grad-presets">
			<?php foreach ( $presets as $name => $c ):
				$on = ( strtolower( $c[0] ) === strtolower( $g['g1'] ) && strtolower( $c[2] ) === strtolower( $g['g3'] ) );
				?>
				<button type="button" class="th-cx-grad-preset<?php echo $on ? ' is-on' : ''; ?>" title="<?php echo esc_attr( $name ); ?>"
					data-g1="<?php echo esc_attr( $c[0] ); ?>" data-g2="<?php echo esc_attr( $c[1] ); ?>" data-g3="<?php echo esc_attr( $c[2] ); ?>"
					style="background:linear-gradient(165deg,<?php echo esc_attr( $c[0] ); ?>,<?php echo esc_attr( $c[2] ); ?>)"></button>
			<?php endforeach; ?>
		</div>
		<div class="th-cx-grad-stops">
			<label>From<input type="color" data-grad="g1" aria-label="Gradient start color" value="<?php echo esc_attr( $g['g1'] ); ?>"></label>
			<label>Mid<input type="color" data-grad="g2" aria-label="Gradient middle color" value="<?php echo esc_attr( $g['g2'] ); ?>"></label>
			<label>To<input type="color" data-grad="g3" aria-label="Gradient end color" value="<?php echo esc_attr( $g['g3'] ); ?>"></label>
		</div>
		<div class="th-cx-grad-angle">
			<span>Angle</span>
			<input type="range" min="0" max="360" value="<?php echo (int) $g['angle']; ?>" data-grad="angle" aria-label="Gradient angle in degrees">
			<b data-grad-angleval><?php echo (int) $g['angle']; ?>&deg;</b>
		</div>
		<label class="th-cx-grad-flat"><span>Flat (no gradient)</span><input type="checkbox" data-grad="flat" <?php checked( ! empty( $g['flat'] ) ); ?>></label>
	</div>
	<?php
}

add_action( 'wp_ajax_therum_save_m01_gradient', function () {
	if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'unauthorized', 403 );
	if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ?? '' ) ), 'therum_theme' ) ) wp_send_json_error( 'Invalid or expired nonce.', 403 );
	$hex = function ( $v, $fb ) {
		$v = is_string( $v ) ? trim( $v ) : '';
		return preg_match( '/^#[0-9a-fA-F]{6}$/', $v ) ? $v : $fb;
	};
	$g = [
		'g1'    => $hex( $_POST['g1'] ?? '', '#F5EFE0' ),
		'g2'    => $hex( $_POST['g2'] ?? '', '#F4ECD4' ),
		'g3'    => $hex( $_POST['g3'] ?? '', '#F7E7AE' ),
		'angle' => max( 0, min( 360, (int) ( $_POST['angle'] ?? 165 ) ) ),
		'flat'  => ! empty( $_POST['flat'] ) && $_POST['flat'] !== 'false',
	];
	update_user_meta( get_current_user_id(), 'therum_m01_gradient', $g );
	wp_send_json_success( $g );
} );

// Emit the saved gradient as CSS vars on every admin page when Theme 01 is active.
add_action( 'admin_head', function () {
	$state = class_exists( 'Therum_Themes' ) ? Therum_Themes::get_state() : [];
	if ( ( $state['palette'] ?? '' ) !== 'm01' ) return;
	$g   = therum_m01_gradient();
	$css = sprintf(
		'body.theme-m01{--m01-g1:%s;--m01-g2:%s;--m01-g3:%s;--m01-g-angle:%ddeg;}',
		$g['g1'], $g['g2'], $g['g3'], (int) $g['angle']
	);
	if ( ! empty( $g['flat'] ) ) {
		$css .= sprintf( 'body.theme-m01 #wpwrap{background:%s !important;}', $g['g1'] );
	}
	echo "\n<style id=\"therum-m01-grad\">{$css}</style>\n";
}, 1000 );
