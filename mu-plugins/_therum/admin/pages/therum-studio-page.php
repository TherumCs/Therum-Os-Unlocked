<?php
/**
 * Therum OS — Therum_Studio_Page
 *
 * Extracted from therum-admin.php as part of the 1.9.x split. Same
 * class, same behavior; required back in from therum-admin.php at the
 * original load position to preserve declaration order.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class Therum_Studio_Page {

	/**
	 * Curated app registry. Each entry:
	 *   slug       — display slug (lowercase, hyphenated)
	 *   name       — display name
	 *   tagline    — one-liner shown on the card
	 *   description— longer paragraph in the expanded card
	 *   color      — accent color for the card hero
	 *   built_in   — true if it ships inside Therum OS (Nexus). Renders as
	 *                "Included" with the Connections / wherever Open URL.
	 *   plugin_file— wp_plugin_dir slug/file.php (when installed). Used for
	 *                state detection: installed? active? has update?
	 *   repo       — TherumCs/<repo> for the install path (GitHub release zip)
	 *   open_url   — admin URL to open the app once installed/active
	 *   category   — informational grouping ('infrastructure' | 'site' | 'commerce')
	 */
	public static function apps(): array {
		return [
			[
				'slug'        => 'nexus',
				'name'        => 'Nexus',
				'tagline'     => 'Connections + credentials vault.',
				'description' => 'Encrypted credential storage for every external service Therum can talk to. AES-256-GCM at rest, scoped tokens, custom providers. The backbone every other Therum app rides on.',
				'color'       => '#6366f1',
				'built_in'    => true,
				'open_url'    => admin_url( 'admin.php?page=therum-connections' ),
				'category'    => 'infrastructure',
			],
			[
				'slug'        => 'cluster',
				'name'        => 'Cluster',
				'tagline'     => 'Group + organize content at scale.',
				'description' => 'Sort posts, pages, and custom post types into clusters with cross-linking, shared meta, and bulk operations. Designed for sites that grow past a few hundred entries.',
				'color'       => '#06b6d4',
				'built_in'    => false,
				'plugin_file' => 'cluster/cluster.php',
				'repo'        => 'TherumCs/Cluster',
				'open_url'    => admin_url( 'admin.php?page=cluster' ),
				'category'    => 'site',
			],
			[
				'slug'        => 'milieus',
				'name'        => 'Milieus',
				'tagline'     => 'Environment + audience targeting.',
				'description' => 'Conditional content blocks gated by environment, device, geo, role, or arbitrary audience rules. Built on top of Bricks so any element gets a Milieus toggle.',
				'color'       => '#10b981',
				'built_in'    => false,
				'plugin_file' => 'milieus/milieus.php',
				'repo'        => 'TherumCs/Milieus',
				'open_url'    => admin_url( 'admin.php?page=milieus' ),
				'category'    => 'site',
			],
			[
				'slug'        => 'shop',
				'name'        => 'Shop',
				'tagline'     => 'Lightweight commerce for Therum.',
				'description' => 'A WooCommerce alternative tuned for digital goods, services, and lightweight catalogs. Stripe + PayPal out of the box, no checkout bloat, no React. Therum-native.',
				'color'       => '#f59e0b',
				'built_in'    => false,
				'plugin_file' => 'shop/shop.php',
				'repo'        => 'TherumCs/Shop',
				'open_url'    => admin_url( 'admin.php?page=shop' ),
				'category'    => 'commerce',
			],
			[
				// Modules are built-in Therum features that are off by default —
				// the merchant opts in from Studio. Differs from `built_in` apps
				// (always on, can only "Open") and `plugin_file` apps (install
				// from GitHub). State driven by an option key.
				'slug'        => 'case-studies',
				'name'        => 'Case Studies',
				'tagline'     => 'Portfolio CPT + sidebar section.',
				'description' => 'Registers the `case_study` custom post type and surfaces a Portfolio entry in the Therum sidebar. Enable only on sites that publish a portfolio.',
				'color'       => '#e83b3b',
				'module'      => true,
				'option'      => 'therum_case_studies_enabled',
				'open_url'    => admin_url( 'edit.php?post_type=case_study' ),
				'category'    => 'site',
			],
		];
	}

	/** Resolve current install state for an app. */
	private static function status( array $app ): array {
		if ( ! empty( $app['built_in'] ) ) {
			return [ 'state' => 'included', 'label' => 'Included', 'version' => defined( 'THERUM_OS_VERSION' ) ? THERUM_OS_VERSION : '' ];
		}
		// Modules — built-in features gated on an option. State is enabled/disabled.
		if ( ! empty( $app['module'] ) ) {
			$enabled = ! empty( $app['option'] ) ? (bool) get_option( $app['option'], false ) : false;
			return $enabled
				? [ 'state' => 'enabled',  'label' => 'Enabled',  'version' => defined( 'THERUM_OS_VERSION' ) ? THERUM_OS_VERSION : '' ]
				: [ 'state' => 'disabled', 'label' => 'Disabled', 'version' => '' ];
		}
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$file = $app['plugin_file'] ?? '';
		if ( ! $file ) return [ 'state' => 'unknown', 'label' => 'Unknown', 'version' => '' ];

		$path = WP_PLUGIN_DIR . '/' . $file;
		if ( ! file_exists( $path ) ) {
			return [ 'state' => 'not_installed', 'label' => 'Not installed', 'version' => '' ];
		}

		$data = get_plugin_data( $path, false, false );
		$ver  = (string) ( $data['Version'] ?? '' );

		if ( is_plugin_active( $file ) ) {
			return [ 'state' => 'active', 'label' => 'Active', 'version' => $ver ];
		}
		return [ 'state' => 'inactive', 'label' => 'Inactive', 'version' => $ver ];
	}

	/** Build the action URL appropriate for the app's current state. */
	private static function action_url( array $app, array $status ): array {
		switch ( $status['state'] ) {
			case 'included':
				return [ 'label' => 'Open', 'url' => $app['open_url'] ?? admin_url(), 'primary' => true ];
			case 'enabled':
				// Module enabled — primary action is "Open", with a secondary
				// "Disable" rendered alongside (the JS handler wires the
				// data-module-toggle attribute below).
				return [
					'label'         => 'Open',
					'url'           => $app['open_url'] ?? admin_url(),
					'primary'       => true,
					'module_toggle' => 'disable',
				];
			case 'disabled':
				return [
					'label'         => 'Enable',
					'url'           => '#',
					'primary'       => true,
					'module_toggle' => 'enable',
				];
			case 'active':
				return [ 'label' => 'Open', 'url' => $app['open_url'] ?? admin_url(), 'primary' => true ];
			case 'inactive':
				return [
					'label'   => 'Activate',
					'url'     => wp_nonce_url(
						admin_url( 'plugins.php?action=activate&plugin=' . urlencode( $app['plugin_file'] ) ),
						'activate-plugin_' . $app['plugin_file']
					),
					'primary' => true,
				];
			case 'not_installed':
			default:
				// Install via the Therum Studio AJAX handler (downloads the GitHub
				// release zip and runs Plugin_Upgrader). The href is a real GitHub
				// fallback so the link is functional even with JS disabled.
				return [
					'label'   => 'Install',
					'url'     => ! empty( $app['repo'] )
						? 'https://github.com/' . $app['repo'] . '/releases/latest'
						: admin_url( 'plugin-install.php' ),
					'primary' => true,
					'install' => true,
				];
		}
	}

	public static function render(): void {
		$apps = self::apps();
		?>
		<div class="th-lp" data-page-id="studio">

		  <div class="th-lp-header">
			<div class="th-lp-header-left">
			  <div class="th-lp-meta">
				<span class="th-lp-meta-dot"></span>
				<?php echo esc_html( count( $apps ) ); ?> MODULE<?php echo count( $apps ) === 1 ? '' : 'S'; ?>
			  </div>
			  <h1 class="th-lp-title">From the Studio</h1>
			  <p class="th-lp-sub">Custom-built modules from Therum Creative Studios. Add the ones you need; ignore the rest. Nexus ships in core because every other module depends on it.</p>
			</div>
		  </div>

		  <div class="th-studio-grid">
			<?php foreach ( $apps as $app ):
				$status     = self::status( $app );
				$action     = self::action_url( $app, $status );
				$state_cls  = 'th-studio-state-' . $status['state'];
			?>
			<article class="th-studio-card <?php echo esc_attr( $state_cls ); ?>" data-app="<?php echo esc_attr( $app['slug'] ); ?>">
			  <div class="th-studio-card-hero" style="background:linear-gradient(135deg, <?php echo esc_attr( $app['color'] ); ?> 0%, color-mix(in srgb, <?php echo esc_attr( $app['color'] ); ?> 60%, #000) 100%);">
				<span class="th-studio-card-mark"><?php echo esc_html( strtoupper( substr( $app['name'], 0, 1 ) ) ); ?></span>
				<?php if ( ! empty( $app['built_in'] ) ): ?>
				  <span class="th-studio-card-badge th-studio-card-badge-included">Included</span>
				<?php elseif ( $status['state'] === 'active' ): ?>
				  <span class="th-studio-card-badge th-studio-card-badge-active">Active</span>
				<?php elseif ( $status['state'] === 'inactive' ): ?>
				  <span class="th-studio-card-badge th-studio-card-badge-inactive">Inactive</span>
				<?php elseif ( $status['state'] === 'enabled' ): ?>
				  <span class="th-studio-card-badge th-studio-card-badge-active">Enabled</span>
				<?php elseif ( $status['state'] === 'disabled' ): ?>
				  <span class="th-studio-card-badge th-studio-card-badge-inactive">Disabled</span>
				<?php endif; ?>
			  </div>
			  <div class="th-studio-card-body">
				<div class="th-studio-card-titlerow">
				  <h2 class="th-studio-card-name"><?php echo esc_html( $app['name'] ); ?></h2>
				  <?php if ( $status['version'] ): ?>
					<span class="th-studio-card-ver">v<?php echo esc_html( $status['version'] ); ?></span>
				  <?php endif; ?>
				</div>
				<p class="th-studio-card-tagline"><?php echo esc_html( $app['tagline'] ); ?></p>
				<p class="th-studio-card-desc"><?php echo esc_html( $app['description'] ); ?></p>
				<div class="th-studio-card-foot">
				  <?php if ( ! empty( $action['install'] ) ): ?>
					<a class="th-btn th-btn-primary th-studio-install"
					   href="<?php echo esc_url( $action['url'] ); ?>"
					   data-slug="<?php echo esc_attr( $app['slug'] ); ?>"
					   target="_blank" rel="noopener"><?php echo esc_html( $action['label'] ); ?></a>
				  <?php elseif ( ! empty( $action['module_toggle'] ) ): ?>
					<?php // Modules render primary action + a secondary toggle. ?>
					<?php if ( $action['module_toggle'] === 'enable' ): ?>
					  <button type="button" class="th-btn th-btn-primary th-studio-module-toggle"
							  data-slug="<?php echo esc_attr( $app['slug'] ); ?>"
							  data-action="enable"><?php echo esc_html( $action['label'] ); ?></button>
					<?php else: /* disable */ ?>
					  <a class="th-btn th-btn-primary" href="<?php echo esc_url( $action['url'] ); ?>"><?php echo esc_html( $action['label'] ); ?></a>
					  <button type="button" class="th-btn th-studio-module-toggle"
							  data-slug="<?php echo esc_attr( $app['slug'] ); ?>"
							  data-action="disable">Disable</button>
					<?php endif; ?>
				  <?php else: ?>
					<a class="th-btn <?php echo $action['primary'] ? 'th-btn-primary' : ''; ?>" href="<?php echo esc_url( $action['url'] ); ?>"><?php echo esc_html( $action['label'] ); ?></a>
				  <?php endif; ?>
				  <?php if ( ! empty( $app['repo'] ) ): ?>
					<a class="th-studio-card-link" href="https://github.com/<?php echo esc_attr( $app['repo'] ); ?>" target="_blank" rel="noopener">View on GitHub ↗</a>
				  <?php endif; ?>
				</div>
			  </div>
			</article>
			<?php endforeach; ?>
		  </div>

		  <p class="th-studio-footnote">More modules in development. Therum apps share a single update channel through the in-admin updater — no separate license keys, no per-plugin nags.</p>
		</div>

		<style>
		.th-studio-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:18px;margin-top:8px}
		.th-studio-card{background:var(--sf);border:1px solid var(--bd);border-radius:14px;overflow:hidden;display:flex;flex-direction:column;transition:transform .2s ease,border-color .2s ease,box-shadow .2s ease}
		.th-studio-card:hover{transform:translateY(-2px);border-color:color-mix(in srgb,var(--tx) 14%,transparent);box-shadow:0 8px 24px rgba(0,0,0,.06)}
		.th-studio-card-hero{position:relative;height:120px;display:flex;align-items:center;justify-content:center;color:#fff}
		.th-studio-card-mark{font-family:var(--f);font-size:48px;font-weight:700;letter-spacing:-.04em;line-height:1;text-shadow:0 2px 10px rgba(0,0,0,.2)}
		.th-studio-card-badge{position:absolute;top:12px;right:12px;padding:3px 10px;font-size:10px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;border-radius:20px;background:rgba(255,255,255,.92);backdrop-filter:blur(8px)}
		.th-studio-card-badge-included{color:#6366f1}
		.th-studio-card-badge-active{color:#10b981}
		.th-studio-card-badge-inactive{color:#f59e0b}
		.th-studio-card-body{padding:18px 20px 16px;display:flex;flex-direction:column;flex:1}
		.th-studio-card-titlerow{display:flex;align-items:baseline;justify-content:space-between;gap:8px;margin-bottom:4px}
		.th-studio-card-name{font-size:18px;font-weight:700;color:var(--tx);margin:0;letter-spacing:-.01em}
		.th-studio-card-ver{font-size:11px;color:var(--tx3);font-weight:500}
		.th-studio-card-tagline{font-size:13px;color:var(--tx2);margin:0 0 10px;font-weight:500}
		.th-studio-card-desc{font-size:12px;color:var(--tx3);line-height:1.55;margin:0 0 16px;flex:1}
		.th-studio-card-foot{display:flex;align-items:center;justify-content:space-between;gap:12px;padding-top:12px;border-top:1px solid var(--bd)}
		.th-studio-card-link{font-size:11px;color:var(--tx3);text-decoration:none}
		.th-studio-card-link:hover{color:var(--ac)}
		.th-studio-footnote{margin:32px 0 0;font-size:12px;color:var(--tx3);text-align:center}
		.th-studio-install.is-busy{opacity:.6;pointer-events:none}
		.th-studio-install.is-busy::after{content:" …";letter-spacing:.1em}
		.th-studio-card.is-error{border-color:var(--err,#ef4444)}
		.th-studio-card-error{margin:8px 0 0;font-size:11px;color:var(--err,#ef4444)}
		</style>
		<script>
		(function(){
			var nonce = <?php echo wp_json_encode( wp_create_nonce( 'therum_studio_install' ) ); ?>;
			var moduleNonce = <?php echo wp_json_encode( wp_create_nonce( 'therum_studio_module_toggle' ) ); ?>;
			var ajaxUrl = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;

			// Module enable/disable toggle. POSTs to a separate handler that
			// flips the option key declared on the module's registry entry,
			// then reloads so the sidebar reflects the new state.
			document.querySelectorAll('.th-studio-module-toggle').forEach(function(btn){
				btn.addEventListener('click', function(){
					if (btn.classList.contains('is-busy')) return;
					var slug = btn.dataset.slug;
					var action = btn.dataset.action;
					if (!slug || !action) return;
					var oldLabel = btn.textContent;
					btn.classList.add('is-busy');
					btn.textContent = action === 'enable' ? 'Enabling' : 'Disabling';
					var body = new URLSearchParams();
					body.append('action', 'therum_studio_module_toggle');
					body.append('_nonce', moduleNonce);
					body.append('slug', slug);
					body.append('toggle', action);
					fetch(ajaxUrl, { method:'POST', credentials:'same-origin', body: body })
						.then(function(r){ return r.json().catch(function(){ return {success:false}; }); })
						.then(function(json){
							if (json && json.success) { window.location.reload(); return; }
							throw new Error((json && json.data && json.data.message) || 'Toggle failed.');
						})
						.catch(function(err){
							btn.classList.remove('is-busy');
							btn.textContent = oldLabel;
							alert(err.message || String(err));
						});
				});
			});
			document.querySelectorAll('.th-studio-install').forEach(function(btn){
				btn.addEventListener('click', function(e){
					// Allow modifier/middle clicks to open GitHub fallback in a tab.
					if (e.metaKey || e.ctrlKey || e.shiftKey || e.button === 1) return;
					e.preventDefault();
					var slug = btn.dataset.slug;
					if (!slug || btn.classList.contains('is-busy')) return;
					var card = btn.closest('.th-studio-card');
					var oldLabel = btn.textContent;
					btn.classList.add('is-busy');
					btn.textContent = 'Installing';
					if (card){
						card.classList.remove('is-error');
						var prev = card.querySelector('.th-studio-card-error');
						if (prev) prev.remove();
					}
					var body = new URLSearchParams();
					body.append('action', 'therum_studio_install');
					body.append('_nonce', nonce);
					body.append('slug', slug);
					fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body })
						.then(function(r){ return r.json().catch(function(){ return { success:false, data:{ message:'Bad server response.' } }; }); })
						.then(function(json){
							if (json && json.success){
								btn.textContent = 'Installed — reloading';
								window.location.reload();
								return;
							}
							throw new Error((json && json.data && json.data.message) || 'Install failed.');
						})
						.catch(function(err){
							btn.classList.remove('is-busy');
							btn.textContent = oldLabel;
							if (card){
								card.classList.add('is-error');
								var msg = document.createElement('div');
								msg.className = 'th-studio-card-error';
								msg.textContent = err.message || String(err);
								var foot = card.querySelector('.th-studio-card-foot');
								if (foot) foot.parentNode.insertBefore(msg, foot);
							}
						});
				});
			});
		})();
		</script>
		<?php
	}
}
