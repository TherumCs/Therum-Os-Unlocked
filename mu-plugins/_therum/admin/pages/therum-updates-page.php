<?php
/**
 * Therum OS — Therum_Updates_Page
 *
 * Extracted from therum-admin.php as part of the 1.9.x split. Same
 * class, same behavior; required back in from therum-admin.php at the
 * original load position to preserve declaration order.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class Therum_Updates_Page {

	public static function has_update(): bool {
		$u = get_plugin_updates();
		return ! empty( $u );
	}

	public static function render(): void {
		if ( ! function_exists( 'get_plugin_updates' ) )
			require_once ABSPATH . 'wp-admin/includes/update.php';

		$plugin_updates = get_plugin_updates();
		$theme_updates  = get_theme_updates();
		$core_updates   = get_core_updates();

		$wp_ver  = get_bloginfo( 'version' );
		$new_core = '';
		foreach ( (array) $core_updates as $cu ) {
			if ( isset( $cu->response ) && $cu->response === 'upgrade' ) {
				$new_core = $cu->version ?? '';
				break;
			}
		}

		$nonce = wp_create_nonce( 'therum_check_updates' );
		$count = count( $plugin_updates ) + count( $theme_updates ) + ( $new_core ? 1 : 0 );
		?>
<div class="th-lp" style="max-width:860px">
  <div class="th-lp-head" style="display:flex;align-items:flex-start;justify-content:space-between;gap:20px">
    <div>
      <div class="th-lp-breadcrumb">ADMIN · UPDATES</div>
      <h1 class="th-lp-title">Updates</h1>
      <p class="th-lp-sub">Keep WordPress core, plugins, and themes current.</p>
    </div>
    <div style="display:flex;gap:10px;align-items:center;flex-shrink:0;padding-top:4px">
      <?php if ($count > 0): ?>
      <span style="font-size:12px;font-weight:600;color:var(--wrn);background:color-mix(in srgb,var(--wrn) 12%,transparent);padding:4px 10px;border-radius:999px"><?php echo $count; ?> available</span>
      <?php endif; ?>
      <button type="button" class="th-btn th-btn-primary" id="thup-check-btn" data-nonce="<?php echo esc_attr($nonce); ?>">
        <?php echo therum_i('import'); ?> Check for updates
      </button>
    </div>
  </div>

  <!-- Core -->
  <div class="th-settings-group" style="margin-top:28px">
    <div class="th-settings-group-header">
      <div class="th-settings-group-title">WordPress Core</div>
    </div>
    <div class="th-settings-group-body">
      <div style="display:flex;align-items:center;justify-content:space-between;padding:14px 0;border-bottom:1px solid var(--bd)">
        <div style="display:flex;align-items:center;gap:14px">
          <div style="width:38px;height:38px;border-radius:9px;background:var(--sf2);display:flex;align-items:center;justify-content:center;font-weight:700;font-size:14px;color:var(--tx2)">WP</div>
          <div>
            <div style="font-weight:600;font-size:14px;color:var(--tx)">WordPress</div>
            <div style="font-size:12px;color:var(--tx3)">Current: v<?php echo esc_html($wp_ver); ?><?php if ($new_core): ?> &nbsp;→&nbsp; <strong style="color:var(--wrn)">v<?php echo esc_html($new_core); ?> available</strong><?php endif; ?></div>
          </div>
        </div>
        <?php if ($new_core): ?>
        <a href="<?php echo esc_url(admin_url('update-core.php')); ?>" class="th-btn" style="font-size:12px">Update to v<?php echo esc_html($new_core); ?> →</a>
        <?php else: ?>
        <span style="font-size:12px;color:var(--ok);display:flex;align-items:center;gap:5px"><?php echo therum_i('check'); ?> Up to date</span>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Plugins -->
  <div class="th-settings-group" style="margin-top:16px">
    <div class="th-settings-group-header">
      <div class="th-settings-group-title">Plugins <?php if (!empty($plugin_updates)): ?><span style="font-size:11px;font-weight:600;color:var(--wrn);background:color-mix(in srgb,var(--wrn) 12%,transparent);padding:2px 8px;border-radius:999px;margin-left:8px"><?php echo count($plugin_updates); ?> update<?php echo count($plugin_updates)===1?'':'s'; ?></span><?php endif; ?></div>
    </div>
    <div class="th-settings-group-body">
      <?php if (empty($plugin_updates)): ?>
      <div style="padding:20px 0;text-align:center;color:var(--tx3);font-size:13px;display:flex;align-items:center;gap:8px;justify-content:center"><?php echo therum_i('check'); ?> All plugins are up to date</div>
      <?php else: foreach ($plugin_updates as $file => $data): ?>
      <?php $new_v = $data->update->new_version ?? ''; $cur_v = $data->Version ?? ''; $name = $data->Name ?? $file; ?>
      <div style="display:flex;align-items:center;justify-content:space-between;padding:14px 0;border-bottom:1px solid var(--bd);gap:12px">
        <div style="display:flex;align-items:center;gap:14px;min-width:0">
          <div style="width:38px;height:38px;border-radius:9px;background:color-mix(in srgb,var(--wrn) 14%,transparent);display:flex;align-items:center;justify-content:center;font-weight:700;font-size:14px;color:var(--wrn);flex-shrink:0"><?php echo esc_html(strtoupper(substr($name,0,1))); ?></div>
          <div style="min-width:0">
            <div style="font-weight:600;font-size:14px;color:var(--tx);white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?php echo esc_html($name); ?></div>
            <div style="font-size:12px;color:var(--tx3)">v<?php echo esc_html($cur_v); ?> → <strong style="color:var(--wrn)">v<?php echo esc_html($new_v); ?></strong></div>
          </div>
        </div>
        <a href="<?php echo esc_url(admin_url('update-core.php?action=do-plugin-upgrade&plugin=' . urlencode($file) . '&_wpnonce=' . wp_create_nonce('upgrade-plugin_' . $file))); ?>" class="th-btn" style="font-size:12px;flex-shrink:0" data-action="upgrade" data-plugin-file="<?php echo esc_attr($file); ?>" data-version="<?php echo esc_attr($new_v); ?>">Update →</a>
      </div>
      <?php endforeach; endif; ?>
    </div>
  </div>

  <!-- Themes -->
  <?php if (!empty($theme_updates)): ?>
  <div class="th-settings-group" style="margin-top:16px">
    <div class="th-settings-group-header">
      <div class="th-settings-group-title">Themes <span style="font-size:11px;font-weight:600;color:var(--wrn);background:color-mix(in srgb,var(--wrn) 12%,transparent);padding:2px 8px;border-radius:999px;margin-left:8px"><?php echo count($theme_updates); ?> update<?php echo count($theme_updates)===1?'':'s'; ?></span></div>
    </div>
    <div class="th-settings-group-body">
      <?php foreach ($theme_updates as $slug => $theme): ?>
      <?php $new_v = $theme->update['new_version'] ?? ''; $cur_v = $theme->get('Version'); $name = $theme->get('Name'); ?>
      <div style="display:flex;align-items:center;justify-content:space-between;padding:14px 0;border-bottom:1px solid var(--bd);gap:12px">
        <div style="display:flex;align-items:center;gap:14px">
          <div style="width:38px;height:38px;border-radius:9px;background:color-mix(in srgb,var(--ac) 14%,transparent);display:flex;align-items:center;justify-content:center;font-weight:700;font-size:14px;color:var(--ac);flex-shrink:0"><?php echo esc_html(strtoupper(substr($name,0,1))); ?></div>
          <div>
            <div style="font-weight:600;font-size:14px;color:var(--tx)"><?php echo esc_html($name); ?></div>
            <div style="font-size:12px;color:var(--tx3)">v<?php echo esc_html($cur_v); ?> → <strong style="color:var(--wrn)">v<?php echo esc_html($new_v); ?></strong></div>
          </div>
        </div>
        <a href="<?php echo esc_url(admin_url('update-core.php')); ?>" class="th-btn" style="font-size:12px;flex-shrink:0">Update →</a>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <div id="thup-toast" hidden style="position:fixed;bottom:28px;right:28px;background:var(--sf);border:1px solid var(--bd);border-radius:10px;padding:12px 18px;font-size:13px;font-weight:500;color:var(--tx);box-shadow:0 8px 24px rgba(0,0,0,.15);z-index:9999"></div>
</div>

<script>
(function(){
  var btn = document.getElementById('thup-check-btn');
  var toast = document.getElementById('thup-toast');
  if (!btn) return;
  function showToast(msg, ok) {
    toast.textContent = msg;
    toast.style.borderColor = ok ? 'var(--ok)' : 'var(--err)';
    toast.style.color = ok ? 'var(--ok)' : 'var(--err)';
    toast.hidden = false;
    clearTimeout(toast._t);
    toast._t = setTimeout(function(){ toast.hidden = true; }, 3500);
  }
  btn.addEventListener('click', function(){
    btn.setAttribute('data-loading','');
    btn.disabled = true;
    var fd = new FormData();
    fd.append('action','therum_check_updates');
    fd.append('nonce', btn.dataset.nonce);
    fetch(window.ajaxurl||'/wp-admin/admin-ajax.php', {method:'POST',credentials:'same-origin',body:fd})
      .then(function(r){return r.json();})
      .then(function(res){
        btn.removeAttribute('data-loading');
        btn.disabled = false;
        if (res && res.success) {
          showToast('Update check complete — reloading…', true);
          setTimeout(function(){ location.reload(); }, 900);
        } else {
          showToast('Check failed', false);
        }
      })
      .catch(function(){
        btn.removeAttribute('data-loading');
        btn.disabled = false;
        showToast('Network error', false);
      });
  });
})();
</script>
		<?php
	}

	public static function ajax_check(): void {
		if ( ! current_user_can( 'update_plugins' ) ) wp_send_json_error( 'forbidden', 403 );
		check_ajax_referer( 'therum_check_updates', 'nonce' );
		// Force WP to re-check remote update data
		if ( ! function_exists( 'wp_update_plugins' ) ) require_once ABSPATH . 'wp-includes/update.php';
		if ( ! function_exists( 'wp_update_themes' ) )  require_once ABSPATH . 'wp-includes/update.php';
		wp_update_plugins();
		wp_update_themes();
		wp_version_check( [], true );
		wp_send_json_success( [ 'msg' => 'checked' ] );
	}
}
