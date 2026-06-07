<?php
/**
 * Plugin Name: Therum OS — WooCommerce
 * Description: WooCommerce bridge (Therum UI integration) and WC performance module.
 *              Merged from therum-woo-bridge.php and therum-woo-perf.php.
 * Version: 1.9.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( 'WooCommerce' ) ) return;


// ════════════════════════════════════════════════════════════════════════
// WOO BRIDGE (cart page, checkout skin, order emails) — from therum-woo-bridge.php
// ════════════════════════════════════════════════════════════════════════

if ( ! defined( 'ABSPATH' ) ) exit;

// Bridge only runs when WooCommerce is loaded — but we check inside the hooks
// because mu-plugins load before regular plugins.

class Therum_Woo_Bridge {

	public static function woo_active(): bool {
		return class_exists('WooCommerce');
	}

	// ── Page renderers ────────────────────────────────────────────────

	public static function render_products(): void {
		if (!self::woo_active()) return;

		$products = function_exists('wc_get_products') ? wc_get_products([
			'limit'  => 100,
			'status' => ['publish', 'draft', 'private'],
		]) : [];

		$by_status = ['publish'=>0,'draft'=>0,'private'=>0,'low'=>0,'out'=>0];
		foreach ($products as $p) {
			$by_status[$p->get_status()] = ($by_status[$p->get_status()] ?? 0) + 1;
			$stock = $p->get_stock_status();
			if ($stock === 'outofstock') $by_status['out']++;
			elseif ($p->get_manage_stock() && (int)$p->get_stock_quantity() <= 5) $by_status['low']++;
		}

		Therum_List_Page::render([
			'title'    => 'Products',
			'subtitle' => 'Stock, variations and pricing.',
			'page_id'  => 'products',
			'meta_pills' => [
				count($products) . ' product' . (count($products)===1?'':'s'),
				$by_status['out'] . ' out of stock',
			],
			'action_buttons' => [
				['label'=>'Import CSV', 'icon'=>'import', 'href'=>admin_url('edit.php?post_type=product&page=product_importer')],
				['label'=>'New Product', 'icon'=>'plus', 'primary'=>true, 'href'=>admin_url('post-new.php?post_type=product')],
			],
			'filter_pills' => [
				['key'=>'all',     'label'=>'All',          'count'=>count($products)],
				['key'=>'publish', 'label'=>'Published',    'count'=>$by_status['publish']],
				['key'=>'draft',   'label'=>'Drafts',       'count'=>$by_status['draft']],
				['key'=>'low',     'label'=>'Low stock',    'count'=>$by_status['low'],  'flag'=>'low'],
				['key'=>'out',     'label'=>'Out of stock', 'count'=>$by_status['out'],  'flag'=>'out'],
			],
			'sort_options' => [
				['key'=>'date-desc',   'label'=>'Recently added'],
				['key'=>'date-asc',    'label'=>'Oldest first'],
				['key'=>'title-asc',   'label'=>'Name A→Z'],
				['key'=>'title-desc',  'label'=>'Name Z→A'],
				['key'=>'price-desc',  'label'=>'Highest price'],
				['key'=>'price-asc',   'label'=>'Lowest price'],
				['key'=>'stock-asc',   'label'=>'Lowest stock first'],
			],
			'search_placeholder' => 'Search products…',
			'items'              => $products,
			'card_renderer'      => [self::class, 'render_product_card'],
			'row_renderer'       => [self::class, 'render_product_row'],
			'table_columns'      => ['Product', 'Status', 'Stock', 'Price', 'SKU'],
			'empty_state'        => ['title'=>'No products yet', 'sub'=>'Add your first product or import a CSV.'],
		]);
	}

	public static function render_product_card($p): void {
		$thumb_id = $p->get_image_id();
		$thumb    = $thumb_id ? wp_get_attachment_image_url($thumb_id, 'medium') : '';
		$status   = $p->get_status();
		$stock    = $p->get_stock_status();
		$is_low   = $p->get_manage_stock() && (int)$p->get_stock_quantity() <= 5 && (int)$p->get_stock_quantity() > 0;
		$is_out   = $stock === 'outofstock';
		$thumb_style = $thumb
			? "background-image:url('".esc_url($thumb)."');background-size:cover;background-position:center;"
			: 'background:linear-gradient(135deg,#0f766e,#0e7490);';
		?>
		<a class="th-lp-card" href="<?php echo esc_url(get_edit_post_link($p->get_id())); ?>"
		   data-status="<?php echo esc_attr($status); ?>"
		   data-low="<?php echo $is_low ? '1' : '0'; ?>"
		   data-out="<?php echo $is_out ? '1' : '0'; ?>"
		   data-search="<?php echo esc_attr(strtolower($p->get_name() . ' ' . $p->get_sku())); ?>"
		   data-sort-date="<?php echo (int) $p->get_date_created()?->getTimestamp(); ?>"
		   data-sort-title="<?php echo esc_attr(strtolower($p->get_name())); ?>"
		   data-sort-price="<?php echo (float) $p->get_price(); ?>"
		   data-sort-stock="<?php echo (int) $p->get_stock_quantity(); ?>">
		  <div class="th-lp-card-thumb th-lp-card-thumb-square" style="<?php echo $thumb_style; ?>"></div>
		  <div class="th-lp-card-meta">
			<div class="th-lp-card-title-row">
			  <div class="th-lp-card-title"><?php echo esc_html($p->get_name()); ?></div>
			  <span class="th-lp-status th-lp-status-<?php echo esc_attr($status); ?>"><?php echo esc_html(ucfirst($status)); ?></span>
			</div>
			<div class="th-lp-card-sub"><?php echo wp_kses_post(wc_price((float)$p->get_price())); ?> · <?php echo $is_out ? 'Out of stock' : ($is_low ? 'Low stock' : 'In stock'); ?></div>
			<?php if ($p->get_sku()): ?><div class="th-lp-card-sub" style="font-family:ui-monospace,monospace;font-size:11px;">SKU <?php echo esc_html($p->get_sku()); ?></div><?php endif; ?>
		  </div>
		</a>
		<?php
	}

	public static function render_product_row($p): void {
		$status = $p->get_status();
		$stock  = $p->get_stock_status();
		$is_low = $p->get_manage_stock() && (int)$p->get_stock_quantity() <= 5 && (int)$p->get_stock_quantity() > 0;
		$is_out = $stock === 'outofstock';
		?>
		<tr class="th-lp-row"
			data-status="<?php echo esc_attr($status); ?>"
			data-low="<?php echo $is_low ? '1' : '0'; ?>"
			data-out="<?php echo $is_out ? '1' : '0'; ?>"
			data-search="<?php echo esc_attr(strtolower($p->get_name())); ?>"
			data-sort-title="<?php echo esc_attr(strtolower($p->get_name())); ?>"
			data-sort-price="<?php echo (float) $p->get_price(); ?>"
			data-sort-stock="<?php echo (int) $p->get_stock_quantity(); ?>">
		  <td><a href="<?php echo esc_url(get_edit_post_link($p->get_id())); ?>"><?php echo esc_html($p->get_name()); ?></a></td>
		  <td><span class="th-lp-status th-lp-status-<?php echo esc_attr($status); ?>"><?php echo esc_html(ucfirst($status)); ?></span></td>
		  <td><?php echo $is_out ? 'Out' : ($p->get_manage_stock() ? (int)$p->get_stock_quantity() : 'In'); ?></td>
		  <td><?php echo wp_kses_post(wc_price((float)$p->get_price())); ?></td>
		  <td style="font-family:ui-monospace,monospace;font-size:12px;"><?php echo esc_html($p->get_sku()); ?></td>
		</tr>
		<?php
	}

	public static function render_orders(): void {
		if (!self::woo_active()) return;

		$orders = function_exists('wc_get_orders') ? wc_get_orders([
			'limit'   => 50,
			'orderby' => 'date',
			'order'   => 'DESC',
		]) : [];

		$counts = ['all' => count($orders), 'pending'=>0, 'processing'=>0, 'completed'=>0, 'cancelled'=>0, 'refunded'=>0];
		foreach ($orders as $o) {
			$s = $o->get_status();
			if (isset($counts[$s])) $counts[$s]++;
		}

		Therum_List_Page::render([
			'title'    => 'Orders',
			'subtitle' => 'Customer orders, refunds, and shipping.',
			'page_id'  => 'orders',
			'meta_pills' => [
				count($orders) . ' order' . (count($orders)===1?'':'s'),
				$counts['processing'] . ' processing',
			],
			'action_buttons' => [
				['label'=>'Add Order', 'icon'=>'plus', 'primary'=>true, 'href'=>admin_url('post-new.php?post_type=shop_order')],
			],
			'filter_pills' => [
				['key'=>'all',        'label'=>'All',        'count'=>$counts['all']],
				['key'=>'pending',    'label'=>'Pending',    'count'=>$counts['pending']],
				['key'=>'processing', 'label'=>'Processing', 'count'=>$counts['processing']],
				['key'=>'completed',  'label'=>'Completed',  'count'=>$counts['completed']],
				['key'=>'cancelled',  'label'=>'Cancelled',  'count'=>$counts['cancelled']],
				['key'=>'refunded',   'label'=>'Refunded',   'count'=>$counts['refunded']],
			],
			'sort_options' => [
				['key'=>'date-desc',  'label'=>'Most recent'],
				['key'=>'date-asc',   'label'=>'Oldest first'],
				['key'=>'total-desc', 'label'=>'Highest total'],
				['key'=>'total-asc',  'label'=>'Lowest total'],
			],
			'search_placeholder' => 'Search orders…',
			'view_default'       => 'table',
			'items'              => $orders,
			'card_renderer'      => [self::class, 'render_order_card'],
			'row_renderer'       => [self::class, 'render_order_row'],
			'table_columns'      => ['Order', 'Customer', 'Status', 'Total', 'Date'],
			'empty_state'        => ['title'=>'No orders yet', 'sub'=>'They\'ll appear here when customers check out.'],
		]);
	}

	public static function render_order_card($o): void {
		$status   = $o->get_status();
		$customer = trim($o->get_billing_first_name() . ' ' . $o->get_billing_last_name()) ?: $o->get_billing_email() ?: 'Guest';
		$total    = $o->get_total();
		$date     = $o->get_date_created() ? $o->get_date_created()->format('M j, Y') : '';
		$num      = $o->get_order_number();
		?>
		<a class="th-lp-card" href="<?php echo esc_url($o->get_edit_order_url()); ?>"
		   data-status="<?php echo esc_attr($status); ?>"
		   data-search="<?php echo esc_attr(strtolower("$num $customer")); ?>"
		   data-sort-date="<?php echo $o->get_date_created() ? $o->get_date_created()->getTimestamp() : 0; ?>"
		   data-sort-total="<?php echo (float)$total; ?>"
		   data-sort-title="<?php echo esc_attr($num); ?>">
		  <div class="th-lp-card-meta">
			<div class="th-lp-card-title-row">
			  <div class="th-lp-card-title">#<?php echo esc_html($num); ?></div>
			  <span class="th-lp-status th-lp-status-<?php echo esc_attr($status); ?>"><?php echo esc_html(ucfirst($status)); ?></span>
			</div>
			<div class="th-lp-card-excerpt"><?php echo esc_html($customer); ?></div>
			<div class="th-lp-card-sub"><?php echo wp_kses_post(wc_price((float)$total)); ?> · <?php echo esc_html($date); ?></div>
		  </div>
		</a>
		<?php
	}

	public static function render_order_row($o): void {
		$status   = $o->get_status();
		$customer = trim($o->get_billing_first_name() . ' ' . $o->get_billing_last_name()) ?: $o->get_billing_email() ?: 'Guest';
		$total    = $o->get_total();
		$date     = $o->get_date_created() ? $o->get_date_created()->format('M j, Y') : '';
		$num      = $o->get_order_number();
		?>
		<tr class="th-lp-row"
			data-status="<?php echo esc_attr($status); ?>"
			data-search="<?php echo esc_attr(strtolower("$num $customer")); ?>"
			data-sort-date="<?php echo $o->get_date_created() ? $o->get_date_created()->getTimestamp() : 0; ?>"
			data-sort-total="<?php echo (float)$total; ?>">
		  <td><a href="<?php echo esc_url($o->get_edit_order_url()); ?>">#<?php echo esc_html($num); ?></a></td>
		  <td><?php echo esc_html($customer); ?></td>
		  <td><span class="th-lp-status th-lp-status-<?php echo esc_attr($status); ?>"><?php echo esc_html(ucfirst($status)); ?></span></td>
		  <td><?php echo wp_kses_post(wc_price((float)$total)); ?></td>
		  <td><?php echo esc_html($date); ?></td>
		</tr>
		<?php
	}

	public static function render_customers(): void {
		if (!self::woo_active()) return;

		$customers = get_users(['role' => 'customer', 'number' => 200]);

		Therum_List_Page::render([
			'title'    => 'Customers',
			'subtitle' => 'People who\'ve bought something.',
			'page_id'  => 'customers',
			'meta_pills' => [count($customers) . ' customer' . (count($customers)===1?'':'s')],
			'action_buttons' => [
				['label'=>'Export CSV', 'icon'=>'import', 'href'=>admin_url('admin.php?page=wc-admin&path=/customers')],
			],
			'filter_pills' => [
				['key'=>'all', 'label'=>'All', 'count'=>count($customers)],
			],
			'sort_options' => [
				['key'=>'date-desc',  'label'=>'Most recent'],
				['key'=>'title-asc',  'label'=>'Name A→Z'],
				['key'=>'title-desc', 'label'=>'Name Z→A'],
			],
			'search_placeholder' => 'Search customers…',
			'items'              => $customers,
			'card_renderer'      => [self::class, 'render_customer_card'],
			'row_renderer'       => [self::class, 'render_customer_row'],
			'table_columns'      => ['Customer', 'Email', 'Joined', 'Orders'],
			'empty_state'        => ['title'=>'No customers yet', 'sub'=>'They\'ll appear here after their first purchase.'],
		]);
	}

	public static function render_customer_card(\WP_User $u): void {
		$avatar = get_avatar_url($u->ID, ['size'=>96]);
		$orders = function_exists('wc_get_customer_order_count') ? wc_get_customer_order_count($u->ID) : 0;
		$joined = wp_date('M j, Y', strtotime((string)$u->user_registered));
		?>
		<a class="th-lp-card th-lp-card-user" href="<?php echo esc_url(admin_url('user-edit.php?user_id=' . $u->ID)); ?>"
		   data-status="all"
		   data-search="<?php echo esc_attr(strtolower($u->display_name . ' ' . $u->user_email)); ?>"
		   data-sort-date="<?php echo (int)strtotime((string)$u->user_registered); ?>"
		   data-sort-title="<?php echo esc_attr(strtolower($u->display_name)); ?>">
		  <div class="th-lp-user-avatar"><img src="<?php echo esc_url($avatar); ?>" alt="" /></div>
		  <div class="th-lp-card-meta">
			<div class="th-lp-card-title"><?php echo esc_html($u->display_name); ?></div>
			<div class="th-lp-card-sub"><?php echo esc_html($u->user_email); ?></div>
			<div class="th-lp-card-sub"><?php echo (int)$orders; ?> order<?php echo $orders==1?'':'s'; ?> · joined <?php echo esc_html($joined); ?></div>
		  </div>
		</a>
		<?php
	}

	public static function render_customer_row(\WP_User $u): void {
		$orders = function_exists('wc_get_customer_order_count') ? wc_get_customer_order_count($u->ID) : 0;
		$joined = wp_date('M j, Y', strtotime((string)$u->user_registered));
		?>
		<tr class="th-lp-row"
			data-status="all"
			data-search="<?php echo esc_attr(strtolower($u->display_name . ' ' . $u->user_email)); ?>"
			data-sort-date="<?php echo (int)strtotime((string)$u->user_registered); ?>"
			data-sort-title="<?php echo esc_attr(strtolower($u->display_name)); ?>">
		  <td><a href="<?php echo esc_url(admin_url('user-edit.php?user_id=' . $u->ID)); ?>"><?php echo esc_html($u->display_name); ?></a></td>
		  <td><?php echo esc_html($u->user_email); ?></td>
		  <td><?php echo esc_html($joined); ?></td>
		  <td><?php echo (int)$orders; ?></td>
		</tr>
		<?php
	}
}

// Register hidden admin pages.
add_action('admin_menu', function() {
	if (!Therum_Woo_Bridge::woo_active()) return;
	add_submenu_page('', 'Products',  'Products',  'edit_products', 'therum-products',  ['Therum_Woo_Bridge', 'render_products']);
	add_submenu_page('', 'Orders',    'Orders',    'edit_shop_orders', 'therum-orders',  ['Therum_Woo_Bridge', 'render_orders']);
	add_submenu_page('', 'Customers', 'Customers', 'list_users',    'therum-customers', ['Therum_Woo_Bridge', 'render_customers']);
}, 30);

// Repoint the existing Store nav items at our Therum versions.
add_filter('therum_admin_nav_items', function(array $items): array {
	if (!Therum_Woo_Bridge::woo_active()) return $items;
	foreach ($items as &$section) {
		if (($section['id'] ?? '') !== 'store' || !isset($section['items'])) continue;
		foreach ($section['items'] as &$it) {
			$swap = [
				'Products'  => 'admin.php?page=therum-products',
				'Orders'    => 'admin.php?page=therum-orders',
				'Customers' => 'admin.php?page=therum-customers',
			];
			if (isset($swap[$it['label']])) {
				$it['url']   = $swap[$it['label']];
				$it['match'] = 'page=therum-' . strtolower($it['label']);
			}
		}
		unset($it);
	}
	unset($section);
	return $items;
}, 30);

// ════════════════════════════════════════════════════════════════════════════
//  STORE PERFORMANCE — HPOS (High-Performance Order Storage) + Legacy REST API.
//  Surfaces WooCommerce's biggest performance/footprint levers in Therum's own
//  Settings, guarded by DB engine (HPOS needs MySQL/MariaDB — it can't run on
//  the SQLite drop-in) and by WooCommerce being active.
// ════════════════════════════════════════════════════════════════════════════

/** Is HPOS currently authoritative (orders stored in custom tables)? */
function thwp_hpos_active(): bool {
	if ( class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) ) {
		return (bool) \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
	}
	return get_option( 'woocommerce_custom_orders_table_enabled' ) === 'yes';
}

/** Detect the Legacy REST API: the standalone plugin OR WC's built-in v1–v3 API. */
function thwp_legacy_rest_status(): array {
	if ( ! function_exists( 'get_plugins' ) ) require_once ABSPATH . 'wp-admin/includes/plugin.php';
	$all     = function_exists( 'get_plugins' ) ? get_plugins() : [];
	$files   = [];
	foreach ( $all as $file => $data ) {
		if ( stripos( (string) ( $data['Name'] ?? '' ), 'Legacy REST API' ) !== false ) $files[] = $file;
	}
	$active = false;
	foreach ( $files as $f ) {
		if ( is_plugin_active( $f ) ) { $active = true; break; }
	}
	$builtin = get_option( 'woocommerce_api_enabled' ) === 'yes';
	return [
		'plugin_files' => $files,
		'duplicate'    => count( $files ) > 1,
		'active'       => $active || $builtin,
		'builtin'      => $builtin,
	];
}

add_action( 'init', function() {
	if ( ! class_exists( 'Therum_Settings' ) || ! Therum_Woo_Bridge::woo_active() ) return;
	Therum_Settings::register( 'store-perf', [
		'label'    => 'Store performance',
		'icon'     => 'gauge',
		'desc'     => 'Order storage (HPOS) + integration API — independent layers.',
		'priority' => 64,
		'render'   => 'thwp_render_store_perf',
	] );
}, 21 );

function thwp_render_store_perf(): void {
	if ( ! function_exists( 'th_settings_group' ) ) return;
	$nonce  = wp_create_nonce( 'therum_options' );
	$sqlite = function_exists( 'therum_is_sqlite' ) && therum_is_sqlite();

	$hpos_on = thwp_hpos_active();
	$sync_on = get_option( 'woocommerce_custom_orders_table_data_sync_enabled' ) === 'yes';
	$legacy  = thwp_legacy_rest_status();
	$wc_feat = admin_url( 'admin.php?page=wc-settings&tab=advanced&section=features' );

	// ── How the two layers fit together ──────────────────────────────────────
	// HPOS and the Legacy REST API solve different problems and don't compete:
	// one is *where orders live*, the other is *an API some integrations call*.
	// Compatibility sync is the bridge that keeps both happy at once.
	th_settings_group( 'How these fit together', 'HPOS and the Legacy REST API are independent layers — they don\'t conflict.', function () {
		?>
		<div style="display:grid;gap:10px;font-size:13px;color:var(--tx2);">
			<div><strong style="color:var(--tx);">HPOS = storage.</strong> Where order data physically lives (custom tables vs wp_posts). A speed/footprint choice.</div>
			<div><strong style="color:var(--tx);">Legacy REST API = access.</strong> An API surface some integrations still call. It reads orders through WooCommerce's data store, so it works whichever storage is active.</div>
			<div><strong style="color:var(--ac);">Compatibility sync = the bridge.</strong> With HPOS on, sync mirrors every order back to wp_posts/postmeta too — so the Legacy REST API and any plugin that reads the old tables keep working. Run all three together: fast storage, full compatibility, no crossed wires.</div>
		</div>
		<?php
	} );

	// ── HPOS (storage layer) ──────────────────────────────────────────────────
	th_settings_group( 'High-Performance Order Storage (HPOS)', 'Storage layer — keeps orders in dedicated tables for faster queries and a smaller footprint. Independent of any API.', function () use ( $sqlite, $hpos_on, $sync_on, $wc_feat, $nonce ) {
		?>
		<div data-th-hpos data-nonce="<?php echo esc_attr( $nonce ); ?>">
		<?php if ( $sqlite ): ?>
			<p style="font-size:13px;color:var(--tx2);margin:0 0 6px;"><strong style="color:var(--tx);">Status:</strong> <span style="color:var(--tx3);">Unavailable on SQLite.</span></p>
			<p style="font-size:12px;color:var(--tx3);margin:0;">HPOS requires MySQL/MariaDB — its queries don't translate through the SQLite drop-in. This control activates on your production (MySQL) deploy.</p>
		<?php elseif ( $hpos_on ): ?>
			<p style="font-size:13px;margin:0 0 12px;"><span style="color:var(--ok);font-weight:600;">✓ Active</span> — orders are stored in custom order tables.</p>
			<label style="display:flex;align-items:center;gap:8px;font-size:13px;cursor:pointer;">
				<input type="checkbox" data-th-hpos-sync <?php checked( $sync_on ); ?> />
				<span><strong>Compatibility sync</strong> — also mirror orders to wp_posts/postmeta. Keeps the Legacy REST API and any postmeta-reading plugin working alongside HPOS. <span style="color:var(--tx3);">Recommended on if you run legacy integrations.</span></span>
			</label>
			<p style="margin:12px 0 0;"><a class="th-btn" href="<?php echo esc_url( $wc_feat ); ?>">Manage in WooCommerce →</a></p>
		<?php else: ?>
			<p style="font-size:13px;margin:0 0 10px;"><strong style="color:var(--tx);">Status:</strong> <span style="color:var(--warn,#f59e0b);">Off</span> — orders are in wp_posts/postmeta.</p>
			<button type="button" class="th-btn th-btn-primary" data-th-hpos-enable>Enable HPOS</button>
			<a class="th-btn" href="<?php echo esc_url( $wc_feat ); ?>" style="margin-left:8px;">Manage in WooCommerce →</a>
			<p style="font-size:12px;color:var(--tx3);margin:10px 0 0;">Creates the order tables and turns on <em>compatibility sync</em> so nothing breaks — the Legacy REST API and postmeta-reading plugins keep working. On a store with existing orders, HPOS becomes authoritative once the background sync finishes.</p>
		<?php endif; ?>
		</div>
		<script>
		(function(){
			var w=document.querySelector('[data-th-hpos]'); if(!w) return;
			var ajax=window.ajaxurl||'/wp-admin/admin-ajax.php', nonce=w.dataset.nonce;
			function post(action, extra, cb){ var fd=new FormData(); fd.append('action',action); fd.append('nonce',nonce); Object.keys(extra||{}).forEach(function(k){fd.append(k,extra[k]);}); fetch(ajax,{method:'POST',credentials:'same-origin',body:fd}).then(function(r){return r.json();}).then(cb).catch(function(){ if(window.therumToast)window.therumToast('Network error'); }); }
			var b=w.querySelector('[data-th-hpos-enable]');
			if(b) b.addEventListener('click', function(){
				b.disabled=true; b.textContent='Enabling…';
				post('therum_enable_hpos', {}, function(res){
					if(window.therumToast) window.therumToast((res&&res.data&&res.data.message)||(res&&res.success?'HPOS enabled':'Could not enable HPOS'));
					if(res&&res.success){ setTimeout(function(){location.reload();},700); } else { b.disabled=false; b.textContent='Enable HPOS'; }
				});
			});
			var s=w.querySelector('[data-th-hpos-sync]');
			if(s) s.addEventListener('change', function(){
				post('therum_toggle_hpos_sync', {on: s.checked?'1':'0'}, function(res){
					if(window.therumToast) window.therumToast((res&&res.data&&res.data.message)||'Updated');
					if(!(res&&res.success)) s.checked=!s.checked;
				});
			});
		})();
		</script>
		<?php
	} );

	// ── Legacy REST API (access layer) — neutral on/off, not a nag ────────────
	th_settings_group( 'Legacy REST API', 'Access layer — the v1–v3 WooCommerce REST API some older integrations call. Independent of HPOS; with compatibility sync on it runs safely alongside it. Turn it on for the plugins that need it, off to shrink your attack surface when nothing does.', function () use ( $legacy, $nonce ) {
		?>
		<div data-th-legacy data-nonce="<?php echo esc_attr( $nonce ); ?>" data-active="<?php echo $legacy['active'] ? '1' : '0'; ?>">
		<?php if ( $legacy['active'] ): ?>
			<p style="font-size:13px;margin:0 0 10px;"><span style="color:var(--ok);font-weight:600;">● On</span> — available for integrations that call the v1–v3 API.<?php echo $legacy['builtin'] ? ' <span style="color:var(--tx3);">(WooCommerce built-in)</span>' : ' <span style="color:var(--tx3);">(legacy add-on plugin)</span>'; ?></p>
			<button type="button" class="th-btn" data-th-legacy-toggle data-want="0">Turn off</button>
			<span style="font-size:12px;color:var(--tx3);margin-left:8px;">Only if no active plugin or external integration uses it.</span>
		<?php else: ?>
			<p style="font-size:13px;margin:0 0 10px;"><span style="color:var(--tx3);font-weight:600;">○ Off</span> — the v1–v3 API is not responding.</p>
			<button type="button" class="th-btn th-btn-primary" data-th-legacy-toggle data-want="1">Turn on</button>
			<span style="font-size:12px;color:var(--tx3);margin-left:8px;">Enable if a plugin or integration needs the legacy API.</span>
		<?php endif; ?>
		<?php if ( $legacy['duplicate'] ): ?>
			<p style="font-size:12px;color:var(--warn,#f59e0b);margin:10px 0 0;">⚠ <?php echo (int) count( $legacy['plugin_files'] ); ?> copies of the legacy add-on are installed — keep one, delete the extra from <a href="<?php echo esc_url( admin_url( 'plugins.php' ) ); ?>">Plugins</a>.</p>
		<?php endif; ?>
		</div>
		<script>
		(function(){
			var w=document.querySelector('[data-th-legacy]'); if(!w) return;
			var b=w.querySelector('[data-th-legacy-toggle]'); if(!b) return;
			b.addEventListener('click', function(){
				var want=b.dataset.want;
				if(want==='0' && !confirm('Turn off the Legacy REST API? Any integration still calling the v1–v3 API will stop working.')) return;
				b.disabled=true; b.textContent=want==='1'?'Turning on…':'Turning off…';
				var fd=new FormData(); fd.append('action','therum_toggle_legacy_rest'); fd.append('enable',want); fd.append('nonce',w.dataset.nonce);
				fetch(window.ajaxurl||'/wp-admin/admin-ajax.php',{method:'POST',credentials:'same-origin',body:fd})
				.then(function(r){return r.json();}).then(function(res){
					if(window.therumToast) window.therumToast((res&&res.data&&res.data.message)||(res&&res.success?'Updated':'Could not update'));
					if(res&&res.success){ setTimeout(function(){location.reload();},600); } else { b.disabled=false; b.textContent=want==='1'?'Turn on':'Turn off'; }
				}).catch(function(){ b.disabled=false; b.textContent=want==='1'?'Turn on':'Turn off'; if(window.therumToast)window.therumToast('Network error'); });
			});
		})();
		</script>
		<?php
	} );
}

add_action( 'wp_ajax_therum_enable_hpos', function() {
	if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( [ 'message' => 'Forbidden' ], 403 );
	check_ajax_referer( 'therum_options', 'nonce' );
	if ( function_exists( 'therum_is_sqlite' ) && therum_is_sqlite() ) {
		wp_send_json_error( [ 'message' => 'HPOS needs MySQL/MariaDB — not available on SQLite.' ] );
	}
	if ( ! class_exists( 'WooCommerce' ) ) wp_send_json_error( [ 'message' => 'WooCommerce is not active.' ] );

	// Feature flag on + create the HPOS tables (idempotent).
	update_option( 'woocommerce_feature_custom_order_tables_enabled', 'yes' );
	if ( class_exists( '\WC_Install' ) && method_exists( '\WC_Install', 'create_tables' ) ) {
		\WC_Install::create_tables();
	}
	// Always keep posts in sync (safe — dual-write, no data move).
	update_option( 'woocommerce_custom_orders_table_data_sync_enabled', 'yes' );

	// Only flip authoritative immediately when there are no existing orders to
	// migrate; otherwise let the background sync run and let the operator make
	// the switch from WooCommerce → Features once sync completes.
	$has_orders = false;
	if ( function_exists( 'wc_get_orders' ) ) {
		$probe = wc_get_orders( [ 'limit' => 1, 'return' => 'ids', 'status' => 'any' ] );
		$has_orders = ! empty( $probe );
	}
	if ( ! $has_orders ) {
		update_option( 'woocommerce_custom_orders_table_enabled', 'yes' );
		wp_send_json_success( [ 'message' => 'HPOS enabled — orders now use custom tables.' ] );
	}
	wp_send_json_success( [ 'message' => 'HPOS tables created and sync started. It becomes authoritative once existing orders finish syncing — finish the switch in WooCommerce → Features.' ] );
} );

// Toggle compatibility sync (the bridge that lets HPOS + legacy consumers
// coexist). Safe both ways — it only controls the dual-write mirror, never
// moves the authoritative store.
add_action( 'wp_ajax_therum_toggle_hpos_sync', function() {
	if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( [ 'message' => 'Forbidden' ], 403 );
	check_ajax_referer( 'therum_options', 'nonce' );
	$on = ( $_POST['on'] ?? '' ) === '1';
	update_option( 'woocommerce_custom_orders_table_data_sync_enabled', $on ? 'yes' : 'no' );
	wp_send_json_success( [
		'message' => $on
			? 'Compatibility sync on — legacy consumers stay in sync with HPOS.'
			: 'Compatibility sync off — only enable this if nothing reads the legacy tables.',
	] );
} );

// Neutral two-way toggle for the Legacy REST API — it's an integration surface,
// not something to reflexively kill. Enabling activates the add-on plugin (if
// installed) and flips WC's built-in flag; disabling reverses both.
add_action( 'wp_ajax_therum_toggle_legacy_rest', function() {
	if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( [ 'message' => 'Forbidden' ], 403 );
	check_ajax_referer( 'therum_options', 'nonce' );
	if ( ! function_exists( 'activate_plugin' ) ) require_once ABSPATH . 'wp-admin/includes/plugin.php';
	$enable = ( $_POST['enable'] ?? '' ) === '1';
	$st     = thwp_legacy_rest_status();

	if ( $enable ) {
		update_option( 'woocommerce_api_enabled', 'yes' );
		$activated = false;
		foreach ( $st['plugin_files'] as $f ) {
			if ( ! is_plugin_active( $f ) ) {
				$res = activate_plugin( $f );
				if ( ! is_wp_error( $res ) ) { $activated = true; break; }
			} else {
				$activated = true;
			}
		}
		if ( empty( $st['plugin_files'] ) && ! $st['builtin'] ) {
			wp_send_json_success( [ 'message' => 'Built-in legacy API enabled. (On WooCommerce 9+ install the “WooCommerce Legacy REST API” add-on if an integration needs the full v1–v3 surface.)' ] );
		}
		wp_send_json_success( [ 'message' => 'Legacy REST API turned on.' ] );
	}

	// Disable: clear WC's built-in flag + deactivate the add-on plugin(s).
	update_option( 'woocommerce_api_enabled', 'no' );
	foreach ( $st['plugin_files'] as $f ) {
		if ( is_plugin_active( $f ) ) deactivate_plugins( $f );
	}
	wp_send_json_success( [ 'message' => 'Legacy REST API turned off.' ] );
} );

// Inject a Revenue card into the dashboard bento.
//
// Note on SQLite + HPOS: the SQLite drop-in can't translate WC's HPOS-specific
// date_created comparison query, so we DON'T pass `date_created` to wc_get_orders.
// Instead we pull a bounded recent batch (200 most recent completed/processing)
// and filter the 30-day window in PHP. Try/catch is a belt to keep a future
// query bug from killing the entire dashboard render.
add_filter('therum_dashboard_cards', function(array $cards): array {
	if (!Therum_Woo_Bridge::woo_active()) return $cards;
	$cards[] = [
		'id'                => 'woo-revenue',
		'priority'          => 35,
		'default_size'      => 'sm',
		'allowed_sizes'     => ['xs', 'sm', 'md', 'sm-square'],
		'recommended_sizes' => ['sm', 'sm-square'],
		'render'            => function() {
			$total = 0.0;
			$count = 0;
			$cutoff = time() - 30 * DAY_IN_SECONDS;
			if (function_exists('wc_get_orders')) {
				try {
					$orders = wc_get_orders([
						'limit'   => 200,
						'status'  => ['completed', 'processing'],
						'orderby' => 'date',
						'order'   => 'DESC',
					]);
					foreach ($orders as $o) {
						$d = $o->get_date_created();
						if ($d && $d->getTimestamp() >= $cutoff) {
							$total += (float) $o->get_total();
							$count++;
						}
					}
				} catch (\Throwable $e) {
					// SQLite + HPOS or any other store-specific query bug — render
					// an empty state rather than crashing the whole dashboard.
					if (function_exists('error_log')) error_log('[therum-woo-bridge] revenue card: ' . $e->getMessage());
				}
			}
			?>
			<div class="th-card-header">
			  <div class="th-card-title">Revenue · 30d</div>
			  <a href="<?php echo esc_url(admin_url('admin.php?page=wc-admin&path=/analytics/revenue')); ?>" class="th-card-link">Analytics →</a>
			</div>
			<div class="th-stat-value"><?php echo wp_kses_post(function_exists('wc_price') ? wc_price($total) : '$' . number_format($total, 2)); ?></div>
			<div class="th-stat-label"><?php echo (int) $count; ?> order<?php echo $count===1?'':'s'; ?> in last 30 days</div>
			<?php
		},
	];
	return $cards;
}, 30);

// ════════════════════════════════════════════════════════════════════════
// WOO PERF (dequeue bloat, cache headers, image pipeline) — from therum-woo-perf.php
// ════════════════════════════════════════════════════════════════════════

if ( ! defined( 'ABSPATH' ) ) exit;
if ( defined( 'THERUM_WOO_PERF_DISABLE' ) && THERUM_WOO_PERF_DISABLE ) return;

// ─── Helpers ─────────────────────────────────────────────────────────────────

/** @return bool true if module is enabled (filter override returns false to disable). */
function thwp_on( string $name, bool $default = true ): bool {
	return (bool) apply_filters( "therum_woo_perf/{$name}", $default );
}

function thwp_woo_active(): bool {
	return class_exists( 'WooCommerce' );
}

/** True when LiteSpeed Cache is active and managing page cache — we step aside. */
function thwp_lscache_active(): bool {
	return defined( 'LSCWP_V' ) || class_exists( 'LiteSpeed\Core' ) || class_exists( 'LiteSpeed_Cache' );
}

/** Heuristic: this request belongs to a shopper with cart/session state. */
function thwp_has_wc_session_cookies(): bool {
	if ( empty( $_COOKIE ) || ! is_array( $_COOKIE ) ) return false;
	foreach ( array_keys( $_COOKIE ) as $name ) {
		if ( ! is_string( $name ) ) continue;
		if (
			$name === 'woocommerce_items_in_cart' ||
			$name === 'woocommerce_cart_hash' ||
			str_starts_with( $name, 'wp_woocommerce_session_' )
		) {
			return true;
		}
	}
	return false;
}


// ═════════════════════════════════════════════════════════════════════════════
//  M1 — Cart Fragments Killer
// ═════════════════════════════════════════════════════════════════════════════
// Removes the /?wc-ajax=get_refreshed_fragments call that fires on every page
// load. Tradeoff: mini-cart count no longer auto-refreshes between page loads;
// it updates on add-to-cart and on navigation. Swap for an SSE handler when we
// add Datastar/HyperPress.

add_action( 'wp_enqueue_scripts', function() {
	if ( ! thwp_on( 'cart_fragments' ) ) return;
	if ( ! thwp_woo_active() ) return;
	wp_dequeue_script( 'wc-cart-fragments' );
}, 11 );


// ═════════════════════════════════════════════════════════════════════════════
//  M2 — Script + style dequeue (bloat)
// ═════════════════════════════════════════════════════════════════════════════
// Default-on: WP/WC scripts that ship on every page and are rarely needed.
// Default-off: WC's three frontend stylesheets (Bricks/theme often needs them).

add_action( 'init', function() {
	if ( ! thwp_on( 'dequeue_bloat' ) ) return;

	// Note: emoji removal lives in therum-core.php (always-on). The TinyMCE
	// emoji plugin filter and emoji_svg_url filter belong here for completeness
	// since core only touches the head/print actions.
	add_filter( 'tiny_mce_plugins', fn( $p ) => is_array( $p ) ? array_diff( $p, [ 'wpemoji' ] ) : $p );
	add_filter( 'emoji_svg_url', '__return_false' );

	// Generator meta
	remove_action( 'wp_head', 'wp_generator' );
});

add_action( 'wp_enqueue_scripts', function() {
	if ( ! thwp_on( 'dequeue_bloat' ) ) return;

	// wp-embed (oEmbed iframe shim) — almost never used on a brochure/store
	wp_dequeue_script( 'wp-embed' );

	// Dashicons for logged-out users only (toolbar uses them when logged in)
	if ( ! is_user_logged_in() ) {
		wp_dequeue_style( 'dashicons' );
	}

	if ( thwp_woo_active() ) {
		// WC tracking + attribution scripts that ship to every shopper
		wp_dequeue_script( 'wc-order-attribution' );
		wp_dequeue_script( 'sourcebuster-js' );

		// `wc-add-to-cart` is only needed on shop/category/product pages
		if ( ! ( is_woocommerce() || is_shop() || is_product_category() || is_product_tag() || is_product() ) ) {
			wp_dequeue_script( 'wc-add-to-cart' );
		}
	}

	// WC frontend stylesheets — opt-in: Bricks/theme usually styles WC itself,
	// but removing these wholesale can break add-to-cart buttons in unfamiliar
	// templates. Off by default.
	if ( thwp_on( 'dequeue_wc_css', false ) && thwp_woo_active() ) {
		wp_dequeue_style( 'wc-blocks-style' );
		wp_dequeue_style( 'woocommerce-layout' );
		wp_dequeue_style( 'woocommerce-smallscreen' );
		wp_dequeue_style( 'woocommerce-general' );
		wp_dequeue_style( 'woocommerce-inline' );
	}
}, 99 );

// Safety net — catch late re-enqueues from other plugins
add_action( 'wp_print_scripts', function() {
	if ( ! thwp_on( 'dequeue_bloat' ) ) return;
	if ( is_admin() ) return;
	wp_dequeue_script( 'wp-embed' );
	if ( thwp_on( 'cart_fragments' ) && thwp_woo_active() ) {
		wp_dequeue_script( 'wc-cart-fragments' );
	}
}, PHP_INT_MAX );


// ═════════════════════════════════════════════════════════════════════════════
//  M3 — Heartbeat Tamer
// ═════════════════════════════════════════════════════════════════════════════
// Disable Heartbeat on frontend; throttle admin to 60s (autosave still works).

add_action( 'init', function() {
	if ( ! thwp_on( 'heartbeat' ) ) return;

	if ( ! is_admin() ) {
		wp_deregister_script( 'heartbeat' );
		return;
	}
});

add_filter( 'heartbeat_settings', function( $settings ) {
	if ( ! thwp_on( 'heartbeat' ) ) return $settings;
	$settings['interval'] = 60;
	return $settings;
});


// ═════════════════════════════════════════════════════════════════════════════
//  M4 — Disable Blocks (frontend only)
// ═════════════════════════════════════════════════════════════════════════════
// Drops ~80KB of block-library CSS on the frontend. Admin/Gutenberg untouched.
// Bricks doesn't render Gutenberg blocks, so this is safe in our setup.

add_action( 'wp_enqueue_scripts', function() {
	if ( ! thwp_on( 'disable_blocks' ) ) return;
	wp_dequeue_style( 'wp-block-library' );
	wp_dequeue_style( 'wp-block-library-theme' );
	wp_dequeue_style( 'classic-theme-styles' );
	wp_dequeue_style( 'global-styles' );
	if ( thwp_woo_active() ) {
		wp_dequeue_style( 'wc-blocks-style' );
	}
}, 100 );


// ═════════════════════════════════════════════════════════════════════════════
//  M5 — Page Cache Headers (only when LSCache isn't doing it)
// ═════════════════════════════════════════════════════════════════════════════
// Emits Cache-Control hints so an upstream cache (CDN, eventually Souin) can
// safely cache anonymous pages. Skipped when LiteSpeed Cache is active —
// LSCache manages its own headers and we'd just create conflicts.

add_action( 'send_headers', function() {
	if ( ! thwp_on( 'cache_headers' ) ) return;
	if ( thwp_lscache_active() ) return;     // LSCache owns it
	if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) return;

	// Always private for logged-in users
	if ( is_user_logged_in() ) {
		header( 'Cache-Control: no-store, private, max-age=0' );
		return;
	}

	// REST/login/cron paths — don't cache
	$uri = $_SERVER['REQUEST_URI'] ?? '';
	if (
		str_contains( $uri, '/wp-json' ) ||
		str_contains( $uri, '/wp-login.php' ) ||
		str_contains( $uri, '/wp-cron.php' )
	) {
		header( 'Cache-Control: no-store, private, max-age=0' );
		return;
	}

	// Active shopper or WC interactive pages — never cache
	if ( thwp_woo_active() ) {
		if ( thwp_has_wc_session_cookies() ) {
			header( 'Cache-Control: no-store, private, max-age=0' );
			return;
		}
		if ( function_exists( 'is_cart' ) && ( is_cart() || is_checkout() || is_account_page() ) ) {
			header( 'Cache-Control: no-store, private, max-age=0' );
			return;
		}
	}

	// Public anonymous page — cacheable
	header( 'Cache-Control: public, max-age=3600, s-maxage=3600, stale-while-revalidate=86400' );
}, 1 );


// ═════════════════════════════════════════════════════════════════════════════
//  M6 — WC Frontend Optimizer
// ═════════════════════════════════════════════════════════════════════════════

add_action( 'init', function() {
	if ( ! thwp_on( 'frontend_opt' ) ) return;
	if ( ! thwp_woo_active() ) return;

	// Drop the per-loop rating-stars output (1 DB query/product on archives)
	remove_action( 'woocommerce_after_shop_loop_item_title', 'woocommerce_template_loop_rating', 5 );

	// WC's own structured-data emitter — replace with theme schema if needed
	remove_action( 'woocommerce_email_order_details', 'woocommerce_get_email_order_items', 10 );

	// Marketplace suggestions in admin product editor
	add_filter( 'woocommerce_allow_marketplace_suggestions', '__return_false' );

	// Persistent cart — extra writes per session, rarely worth it
	remove_action( 'wp_login', [ 'WC_Cart', 'persistent_cart_update' ] );
	remove_action( 'woocommerce_add_to_cart', [ 'WC_Cart', 'persistent_cart_update' ] );
	remove_action( 'woocommerce_cart_item_removed', [ 'WC_Cart', 'persistent_cart_update' ] );
	remove_action( 'woocommerce_cart_item_restored', [ 'WC_Cart', 'persistent_cart_update' ] );
	remove_action( 'woocommerce_cart_emptied', [ 'WC_Cart', 'persistent_cart_destroy' ] );

	// WC admin bar nodes on frontend (rendering cost for nothing)
	add_action( 'admin_bar_menu', function( $bar ) {
		$bar->remove_node( 'woocommerce-site-visibility-badge' );
	}, 100 );
});

// Prime WC option cache early so the first wp_options query batch is one round-trip
add_action( 'muplugins_loaded', function() {
	if ( ! thwp_on( 'frontend_opt' ) ) return;
	if ( ! function_exists( 'wp_prime_option_caches' ) ) return;

	wp_prime_option_caches( [
		'woocommerce_currency',
		'woocommerce_currency_pos',
		'woocommerce_price_thousand_sep',
		'woocommerce_price_decimal_sep',
		'woocommerce_price_num_decimals',
		'woocommerce_default_country',
		'woocommerce_calc_taxes',
		'woocommerce_prices_include_tax',
		'woocommerce_tax_display_shop',
		'woocommerce_tax_display_cart',
		'woocommerce_enable_coupons',
		'woocommerce_cart_redirect_after_add',
		'woocommerce_enable_ajax_add_to_cart',
		'woocommerce_shop_page_id',
	] );
}, PHP_INT_MAX );

// Batch-prime thumbnail attachments on shop archives — avoids N×2 query storms
add_action( 'woocommerce_before_shop_loop', function() {
	if ( ! thwp_on( 'frontend_opt' ) ) return;
	if ( ! thwp_woo_active() ) return;

	global $wp_query;
	if ( empty( $wp_query->posts ) || ! is_array( $wp_query->posts ) ) return;

	$thumb_ids = [];
	foreach ( $wp_query->posts as $p ) {
		$tid = (int) get_post_thumbnail_id( $p->ID );
		if ( $tid ) $thumb_ids[] = $tid;
	}
	if ( $thumb_ids ) {
		_prime_post_caches( $thumb_ids, false, true );
	}
});


// ═════════════════════════════════════════════════════════════════════════════
//  M7 — Image Pipeline
// ═════════════════════════════════════════════════════════════════════════════
// WP 5.5+ already adds loading="lazy" to most <img>. This module layers on:
//   • decoding="async" on every img (lets browser paint without blocking decode)
//   • fetchpriority="high" on the LCP candidate (first product card image on
//     archives, the main image on a single product) so the browser races it
//     down with critical CSS instead of treating it as just-another-img
//   • forces lazy loading off for the LCP image (lazy + fetchpriority is a
//     known anti-pattern that *delays* LCP)
// WebP/AVIF conversion is intentionally out of scope here — that's a server-
// side job (LSCache image opt, or a media filter at upload time).

add_filter( 'wp_get_attachment_image_attributes', function( $attr, $attachment, $size ) {
	if ( ! thwp_on( 'image_pipeline' ) ) return $attr;
	if ( is_admin() ) return $attr;

	// Always async-decode
	if ( empty( $attr['decoding'] ) ) {
		$attr['decoding'] = 'async';
	}

	return $attr;
}, 10, 3 );

// Mark the LCP image — first product image on shop archives, main image on
// product page — with fetchpriority="high" and force-eager. We track "have we
// boosted one yet?" via a static so this only fires once per request.
add_filter( 'wp_get_attachment_image_attributes', function( $attr, $attachment, $size ) {
	if ( ! thwp_on( 'image_pipeline' ) ) return $attr;
	if ( is_admin() ) return $attr;

	static $boosted = false;
	if ( $boosted ) return $attr;

	$is_lcp_context = false;
	if ( thwp_woo_active() ) {
		if ( function_exists( 'is_product' ) && is_product() && did_action( 'woocommerce_before_single_product' ) && ! did_action( 'woocommerce_after_single_product_summary' ) ) {
			$is_lcp_context = true;
		} elseif ( function_exists( 'is_shop' ) && ( is_shop() || is_product_category() || is_product_tag() ) && did_action( 'woocommerce_before_shop_loop' ) && ! did_action( 'woocommerce_after_shop_loop' ) ) {
			$is_lcp_context = true;
		}
	}

	if ( $is_lcp_context ) {
		$attr['fetchpriority'] = 'high';
		$attr['loading']       = 'eager';   // override WP's default lazy
		$boosted = true;
	}

	return $attr;
}, 11, 3 );

// Catch any <img> inserted directly (not via wp_get_attachment_image) and add
// decoding="async". Cheap regex on the rendered fragment.
add_filter( 'the_content', function( $content ) {
	if ( ! thwp_on( 'image_pipeline' ) ) return $content;
	if ( is_admin() || empty( $content ) ) return $content;
	return preg_replace_callback(
		'/<img\b(?![^>]*\bdecoding=)([^>]*)>/i',
		fn( $m ) => '<img decoding="async"' . $m[1] . '>',
		$content
	);
}, 99 );


// ═════════════════════════════════════════════════════════════════════════════
//  M8 — Pixel Defer (Snap / TikTok / Reddit / GLA)
// ═════════════════════════════════════════════════════════════════════════════
// Tracking pixels are *needed* on conversion pages — we don't dequeue them.
// But they don't need to block render anywhere. Force async on script tags
// from known pixel/tracking handles so LCP doesn't pay their TLS handshake.
// Self-gates: each plugin already checks is_enabled() before enqueuing, so
// this only kicks in when the merchant has tracking turned on.

add_filter( 'script_loader_tag', function( $tag, $handle, $src ) {
	if ( ! thwp_on( 'pixel_defer' ) ) return $tag;
	if ( is_admin() ) return $tag;

	// Match by handle prefix (each plugin uses its own naming) and by src host.
	$pixel_handle_prefixes = [ 'snapchat-', 'reddit-', 'tiktok-', 'tt4b-', 'gla-', 'google-listings' ];
	$pixel_src_hosts       = [
		'sc-static.net',                     // Snap
		'analytics.tiktok.com',              // TikTok
		'redditstatic.com',                  // Reddit
		'googletagmanager.com',              // GTM (Google Listings & Ads)
		'google-analytics.com',
	];

	$is_pixel = false;
	foreach ( $pixel_handle_prefixes as $prefix ) {
		if ( str_starts_with( (string) $handle, $prefix ) ) { $is_pixel = true; break; }
	}
	if ( ! $is_pixel ) {
		foreach ( $pixel_src_hosts as $host ) {
			if ( $src && str_contains( (string) $src, $host ) ) { $is_pixel = true; break; }
		}
	}
	if ( ! $is_pixel ) return $tag;

	// Already async/defer? leave it.
	if ( str_contains( $tag, ' async' ) || str_contains( $tag, ' defer' ) ) return $tag;

	// Inject async — pixels are fire-and-forget; order with other scripts
	// doesn't matter (they don't expose anything other scripts call into).
	return preg_replace( '/<script\b/', '<script async', $tag, 1 );
}, 10, 3 );


// ═════════════════════════════════════════════════════════════════════════════
//  M9 — Fast Order Lookup (admin order search)
// ═════════════════════════════════════════════════════════════════════════════
// WooCommerce's admin order search runs an unindexed LIKE '%term%' scan across
// wp_postmeta (pre-HPOS) or wp_wc_orders_meta (HPOS), looking at billing email,
// name, phone, transaction_id, etc. On stores with > 5k orders this is the
// slowest query in the admin — routinely 2-8 seconds for a single search.
//
// Fix: maintain a single, indexed lookup table populated on every order save.
// Searches hit one indexed query instead of a meta scan. Inspired by the
// fast-woo-order-lookup plugin (credit: Ollie Jones). Native Therum build so
// it composes cleanly with HPOS + LiteSpeed + our own perf gating.
//
// Kill switches:
//   define('THERUM_WOO_FAST_ORDER_LOOKUP', false)           — disables module entirely
//   apply_filters('therum_woo_perf/fast_order_lookup', false) — runtime disable
//
// On first request the table is created if missing. Existing orders backfill
// in chunks via a one-shot cron — never blocks the request.

const THERUM_WOO_ORDER_LOOKUP_TABLE = 'therum_wc_order_search';
const THERUM_WOO_ORDER_LOOKUP_DB_VERSION = 1;

function thwp_order_lookup_table(): string {
	global $wpdb;
	return $wpdb->prefix . THERUM_WOO_ORDER_LOOKUP_TABLE;
}

function thwp_order_lookup_install(): void {
	if ( ! thwp_on( 'fast_order_lookup' ) ) return;

	$installed = (int) get_option( 'therum_woo_order_lookup_db', 0 );
	if ( $installed >= THERUM_WOO_ORDER_LOOKUP_DB_VERSION ) return;

	global $wpdb;
	$table   = thwp_order_lookup_table();
	$charset = $wpdb->get_charset_collate();

	// Indexed on every column we search. order_id is the FK back to wc_orders.
	$sql = "CREATE TABLE {$table} (
		order_id BIGINT UNSIGNED NOT NULL,
		billing_email VARCHAR(190) NOT NULL DEFAULT '',
		billing_name VARCHAR(190) NOT NULL DEFAULT '',
		billing_phone VARCHAR(64)  NOT NULL DEFAULT '',
		transaction_id VARCHAR(190) NOT NULL DEFAULT '',
		updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
		PRIMARY KEY (order_id),
		KEY billing_email (billing_email),
		KEY billing_name (billing_name(64)),
		KEY billing_phone (billing_phone),
		KEY transaction_id (transaction_id)
	) {$charset};";

	if ( ! function_exists( 'dbDelta' ) ) require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( $sql );

	update_option( 'therum_woo_order_lookup_db', THERUM_WOO_ORDER_LOOKUP_DB_VERSION, false );

	// Schedule a one-shot backfill (chunked to avoid timeouts on big stores).
	if ( ! wp_next_scheduled( 'therum_woo_order_lookup_backfill' ) ) {
		wp_schedule_single_event( time() + 30, 'therum_woo_order_lookup_backfill' );
	}
}
add_action( 'admin_init', 'thwp_order_lookup_install' );

/** Upsert lookup row when an order saves. Runs on both core hook + HPOS hook. */
function thwp_order_lookup_sync( $order_id ): void {
	if ( ! thwp_on( 'fast_order_lookup' ) ) return;
	if ( ! $order_id || ! thwp_woo_active() ) return;

	$order = wc_get_order( $order_id );
	if ( ! $order ) return;

	global $wpdb;
	$wpdb->replace(
		thwp_order_lookup_table(),
		[
			'order_id'       => (int) $order->get_id(),
			'billing_email'  => (string) $order->get_billing_email(),
			'billing_name'   => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
			'billing_phone'  => (string) $order->get_billing_phone(),
			'transaction_id' => (string) $order->get_transaction_id(),
		],
		[ '%d', '%s', '%s', '%s', '%s' ]
	);
}
add_action( 'woocommerce_new_order',     'thwp_order_lookup_sync', 20 );
add_action( 'woocommerce_update_order',  'thwp_order_lookup_sync', 20 );
add_action( 'woocommerce_delete_order',  function( $order_id ) {
	global $wpdb;
	$wpdb->delete( thwp_order_lookup_table(), [ 'order_id' => (int) $order_id ], [ '%d' ] );
} );

/** Cron — backfill existing orders in 500-row chunks. */
add_action( 'therum_woo_order_lookup_backfill', function() {
	if ( ! thwp_on( 'fast_order_lookup' ) ) return;
	if ( ! thwp_woo_active() ) return;

	$last = (int) get_option( 'therum_woo_order_lookup_backfill_cursor', 0 );

	$orders = wc_get_orders( [
		'limit'     => 500,
		'paginate'  => false,
		'orderby'   => 'ID',
		'order'     => 'ASC',
		'date_modified' => '>' . gmdate( 'Y-m-d H:i:s', 0 ),
		'meta_query' => [],
		// Cursor pagination by ID to avoid offset drift on large stores
		'id__gt' => $last,
		'return' => 'objects',
	] );

	if ( empty( $orders ) ) {
		delete_option( 'therum_woo_order_lookup_backfill_cursor' );
		update_option( 'therum_woo_order_lookup_backfill_complete', time(), false );
		return;
	}

	$max_id = $last;
	foreach ( $orders as $o ) {
		thwp_order_lookup_sync( $o->get_id() );
		$max_id = max( $max_id, (int) $o->get_id() );
	}
	update_option( 'therum_woo_order_lookup_backfill_cursor', $max_id, false );

	// Schedule next chunk
	wp_schedule_single_event( time() + 10, 'therum_woo_order_lookup_backfill' );
} );

/**
 * Override the admin order-search query.
 * Returns an array of order IDs matching the search term using our indexed
 * table. WooCommerce passes this through `woocommerce_order_table_search_query_meta_keys`
 * (HPOS) and `woocommerce_shop_order_search_results` (legacy CPT) filters.
 */
function thwp_order_lookup_search( string $term ): array {
	global $wpdb;
	$term = trim( $term );
	if ( $term === '' ) return [];

	$table = thwp_order_lookup_table();
	$like  = '%' . $wpdb->esc_like( $term ) . '%';

	$ids = $wpdb->get_col( $wpdb->prepare(
		"SELECT order_id FROM {$table}
		 WHERE billing_email LIKE %s
		    OR billing_name  LIKE %s
		    OR billing_phone LIKE %s
		    OR transaction_id LIKE %s
		 LIMIT 200",
		$like, $like, $like, $like
	) );

	return array_map( 'intval', (array) $ids );
}

// HPOS search hook — returns array of matching order IDs.
add_filter( 'woocommerce_order_list_table_prepare_items_query_args', function( $args ) {
	if ( ! thwp_on( 'fast_order_lookup' ) ) return $args;
	if ( empty( $args['s'] ) || ! is_string( $args['s'] ) ) return $args;

	$ids = thwp_order_lookup_search( $args['s'] );
	if ( empty( $ids ) ) return $args;

	// Replace LIKE search with our pre-computed ID list.
	unset( $args['s'] );
	$args['post__in'] = isset( $args['post__in'] ) && is_array( $args['post__in'] )
		? array_intersect( $args['post__in'], $ids )
		: $ids;

	return $args;
} );

// Legacy CPT-orders search hook — pre-HPOS path. Same shape.
add_filter( 'woocommerce_shop_order_search_results', function( $results, $term, $search_fields ) {
	if ( ! thwp_on( 'fast_order_lookup' ) ) return $results;
	$ids = thwp_order_lookup_search( (string) $term );
	return $ids ?: $results;
}, 10, 3 );

/** Expose status for the Performance Overview surface. */
add_filter( 'therum_perf_status_rows', function( array $rows ): array {
	if ( ! thwp_on( 'fast_order_lookup' ) ) return $rows;
	global $wpdb;

	$table        = thwp_order_lookup_table();
	$table_exists = (bool) $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table ) );
	$row_count    = $table_exists ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ) : 0;
	$backfill_done = (bool) get_option( 'therum_woo_order_lookup_backfill_complete', 0 );

	$rows[] = [
		'label' => 'Woo · Fast order lookup',
		'state' => $backfill_done ? 'enabled' : ( $table_exists ? 'syncing' : 'pending' ),
		'note'  => $backfill_done
			? sprintf( '%s rows · indexed', number_format_i18n( $row_count ) )
			: ( $table_exists ? sprintf( '%s rows · backfill in progress', number_format_i18n( $row_count ) ) : 'install pending' ),
	];
	return $rows;
} );


// ═════════════════════════════════════════════════════════════════════════════
//  M10 — Strip Commerce Scripts on Non-Commerce Pages
// ═════════════════════════════════════════════════════════════════════════════
// Last-resort filter for plugins that re-enqueue WC + pixel scripts AFTER M2's
// dequeue pass at priority 99. Runs at script_loader_tag time and drops matching
// <script> tags entirely from output on non-commerce pages.
//
// Merged from therum-woo-strip.php (Phase 3 collapse, 2026-05-27).
//
// Kill switches (any one disables the module):
//   define( 'THERUM_STRIP_COMMERCE_DISABLE', true )            — legacy constant (back-compat)
//   apply_filters( 'therum/strip_commerce', '__return_false' ) — legacy filter (back-compat)
//   apply_filters( 'therum_woo_perf/strip_commerce', false )   — module pattern
//
// Risk: if a Woo element (price, add-to-cart, mini-cart) ever lands on a
// portfolio page, those elements will silently break. Whitelist that page via
// the legacy filter if needed.

add_filter( 'script_loader_tag', function( $tag, $handle, $src ) {
	if ( defined( 'THERUM_STRIP_COMMERCE_DISABLE' ) && THERUM_STRIP_COMMERCE_DISABLE ) return $tag;
	if ( ! thwp_on( 'strip_commerce' ) ) return $tag;
	if ( ! apply_filters( 'therum/strip_commerce', true ) ) return $tag;
	if ( is_admin() ) return $tag;
	if ( ! $src ) return $tag;

	// Bail on real commerce pages (preserve full cart/product/checkout/account flow)
	if ( function_exists( 'is_woocommerce' ) ) {
		if ( is_woocommerce() || is_shop() || is_product_category() || is_product_tag() ||
		     is_product() || is_cart() || is_checkout() || is_account_page() ) {
			return $tag;
		}
	}

	$strip_patterns = [
		'/reddit-for-woocommerce/',
		'/snapchat-for-woocommerce/',
		'/woocommerce/assets/js/jquery-blockui/',
		'/woocommerce/assets/js/js-cookie/',
		'/woocommerce/assets/js/sourcebuster/',
		'/woocommerce/assets/js/frontend/order-attribution',
		'/woocommerce/assets/js/frontend/add-to-cart',
		'/woocommerce/assets/js/frontend/woocommerce.min.js',
		'/bricks/assets/js/integrations/woocommerce',
	];
	foreach ( $strip_patterns as $p ) {
		if ( strpos( (string) $src, $p ) !== false ) {
			return '';  // remove tag from output
		}
	}
	return $tag;
}, 999, 3 );


