<?php
/**
 * Plugin Name: Therum OS · Theme Engine (beta)
 * Description: New theme studio engine. Layer-3 themes = palette + visual-style
 *              picks on the Layer-0/1 token base. Applies by overriding the admin
 *              chrome CSS variables (ac, bg, sf, tx, bd) live, so the whole UI
 *              repaints. Per-user active theme persists in user_meta.
 *              BETA: runs alongside the legacy theme system; only acts when a new
 *              theme is explicitly applied. Legacy presets are removed at cutover.
 * Version: 0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

final class Therum_Theme_Engine {

	const META_ACTIVE = 'therum_active_theme';   // per-user applied theme id

	/**
	 * The foundation themes. Each maps its palette onto the chrome variable
	 * contract + the new --th-* color contract, and declares its visual-style
	 * picks (radius / density / surface / depth). Faithful to the references.
	 *
	 * Colors → chrome vars:
	 *   bg=--bg  sf=--sf  sf2=--sf2  sf3=--sf3  tx=--tx  tx2=--tx2  tx3=--tx3
	 *   bd=--bd  bd2=--bd2  ac=--ac  acH=--ac-h  acS=--ac-s
	 */
	public static function themes(): array {
		return [
			// ── LIGHT ─────────────────────────────────────────────────────────
			'warm' => [
				'name' => '01 · Warm', 'mode' => 'light', 'ref' => 'HR / Crextio',
				'radius' => 'rounded', 'density' => 'comfortable', 'surface' => 'soft',
				'pal' => [ 'bg'=>'#FBF8F1','sf'=>'#FFFFFF','sf2'=>'#F4EFE6','sf3'=>'#ECE6DA',
					'tx'=>'#1A1714','tx2'=>'#6B6455','tx3'=>'#A89F8C',
					'bd'=>'rgba(40,30,10,.10)','bd2'=>'rgba(40,30,10,.18)',
					'ac'=>'#E6A817','acH'=>'#C8900F','acS'=>'rgba(230,168,23,.12)' ],
			],
			'coral' => [
				'name' => '02 · Coral', 'mode' => 'light', 'ref' => 'Financial / N2',
				'radius' => 'soft', 'density' => 'comfortable', 'surface' => 'soft',
				'pal' => [ 'bg'=>'#F2F2F3','sf'=>'#FFFFFF','sf2'=>'#ECECEE','sf3'=>'#E4E4E7',
					'tx'=>'#16181D','tx2'=>'#5B606B','tx3'=>'#9AA0AC',
					'bd'=>'rgba(0,0,0,.08)','bd2'=>'rgba(0,0,0,.16)',
					'ac'=>'#EE5340','acH'=>'#D63E2C','acS'=>'rgba(238,83,64,.10)' ],
			],
			'float' => [
				'name' => '03 · Float', 'mode' => 'light', 'ref' => 'Twisty',
				'radius' => 'pilly', 'density' => 'breathing', 'surface' => 'floating',
				'pal' => [ 'bg'=>'#E4E7F0','sf'=>'#FFFFFF','sf2'=>'#EFF1F7','sf3'=>'#E7EAF2',
					'tx'=>'#1F2430','tx2'=>'#5A6072','tx3'=>'#97A0B5',
					'bd'=>'rgba(40,50,80,.10)','bd2'=>'rgba(40,50,80,.18)',
					'ac'=>'#4F6BED','acH'=>'#3A55D6','acS'=>'rgba(79,107,237,.10)' ],
			],
			'mono' => [
				'name' => '07 · Mono', 'mode' => 'light', 'ref' => 'Limitus',
				'radius' => 'soft', 'density' => 'comfortable', 'surface' => 'flat',
				'pal' => [ 'bg'=>'#FAFAFA','sf'=>'#FFFFFF','sf2'=>'#F2F2F2','sf3'=>'#EAEAEA',
					'tx'=>'#0A0A0A','tx2'=>'#555555','tx3'=>'#999999',
					'bd'=>'rgba(0,0,0,.10)','bd2'=>'rgba(0,0,0,.20)',
					'ac'=>'#111111','acH'=>'#000000','acS'=>'rgba(0,0,0,.06)' ],
			],
			// ── DARK ──────────────────────────────────────────────────────────
			'violet' => [
				'name' => '09 · Violet', 'mode' => 'dark', 'ref' => 'Stakent',
				'radius' => 'rounded', 'density' => 'comfortable', 'surface' => 'elevated',
				'pal' => [ 'bg'=>'#0C0C10','sf'=>'#16161C','sf2'=>'#1E1E26','sf3'=>'#26262E',
					'tx'=>'#F2F2F5','tx2'=>'#A6A6B2','tx3'=>'#6E6E7A',
					'bd'=>'rgba(255,255,255,.08)','bd2'=>'rgba(255,255,255,.16)',
					'ac'=>'#7C5CFF','acH'=>'#6A48F0','acS'=>'rgba(124,92,255,.16)' ],
			],
			'spatial' => [
				'name' => '11 · Spatial', 'mode' => 'dark', 'ref' => 'Smart Home',
				'radius' => 'pilly', 'density' => 'breathing', 'surface' => 'glass',
				'pal' => [ 'bg'=>'#1C2422','sf'=>'#243029','sf2'=>'#2C3A33','sf3'=>'#35453C',
					'tx'=>'#EAF0EC','tx2'=>'#A7B5AD','tx3'=>'#6E7B74',
					'bd'=>'rgba(255,255,255,.10)','bd2'=>'rgba(255,255,255,.18)',
					'ac'=>'#34D399','acH'=>'#22B883','acS'=>'rgba(52,211,153,.16)' ],
			],
		];
	}

	/** The active theme id for the current user, or '' (legacy/off). */
	public static function active(): string {
		$id = (string) get_user_meta( get_current_user_id(), self::META_ACTIVE, true );
		return isset( self::themes()[ $id ] ) ? $id : '';
	}

	public static function init(): void {
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue' ] );
		add_action( 'admin_head',            [ __CLASS__, 'print_vars' ], 99 );
		add_filter( 'admin_body_class',      [ __CLASS__, 'body_class' ] );
		add_action( 'admin_footer',          [ __CLASS__, 'switcher' ] );
		add_action( 'wp_ajax_therum_apply_theme', [ __CLASS__, 'ajax_apply' ] );
	}

	/** Load the Layer-0 base + Layer-1 styles whenever a new theme is active. */
	public static function enqueue(): void {
		if ( self::active() === '' ) return;
		foreach ( [ 'therum-theme-base', 'therum-theme-styles' ] as $h ) {
			$path = __DIR__ . "/assets/{$h}.css";
			wp_enqueue_style( $h, plugins_url( "assets/{$h}.css", __FILE__ ), [], file_exists( $path ) ? filemtime( $path ) : null );
		}
	}

	/** Print the active theme's variable overrides — wins via html body specificity. */
	public static function print_vars(): void {
		$id = self::active();
		if ( $id === '' ) return;
		$t = self::themes()[ $id ];
		$p = $t['pal'];
		$radius_map = [ 'sharp'=>'0','soft'=>'10px','rounded'=>'14px','pilly'=>'20px' ];
		$r = $radius_map[ $t['radius'] ] ?? '14px';
		echo "\n<style id=\"therum-active-theme\">\nhtml body.th-active-theme{";
		printf( '--bg:%s;--sf:%s;--sf2:%s;--sf3:%s;--tx:%s;--tx2:%s;--tx3:%s;--bd:%s;--bd2:%s;--ac:%s;--ac-h:%s;--ac-s:%s;',
			esc_attr($p['bg']), esc_attr($p['sf']), esc_attr($p['sf2']), esc_attr($p['sf3']),
			esc_attr($p['tx']), esc_attr($p['tx2']), esc_attr($p['tx3']),
			esc_attr($p['bd']), esc_attr($p['bd2']),
			esc_attr($p['ac']), esc_attr($p['acH']), esc_attr($p['acS']) );
		// New --th-* contract (for studio components) + radius/level.
		printf( '--th-canvas:%s;--th-surface:%s;--th-text:%s;--th-text-2:%s;--th-border:%s;--th-accent:%s;--th-accent-ink:%s;--radius-lg:%s;--th-radius:%s;',
			esc_attr($p['bg']), esc_attr($p['sf']), esc_attr($p['tx']), esc_attr($p['tx2']),
			esc_attr($p['bd']), esc_attr($p['ac']),
			$t['mode']==='dark' ? '#0b0b0e' : '#ffffff',
			esc_attr($r), esc_attr($r) );
		echo "}\n</style>\n";
	}

	public static function body_class( string $classes ): string {
		$id = self::active();
		if ( $id === '' ) return $classes;
		$t = self::themes()[ $id ];
		$extra = [ 'th-os', 'th-active-theme', 'th-theme-' . $id, 'th-mode-' . $t['mode'],
			'th-surf-' . $t['surface'], 'th-radius-' . $t['radius'], 'th-density-' . $t['density'],
			'density-' . $t['density'] ]; // legacy density alias for chrome paddings
		if ( $t['mode'] === 'light' ) $extra[] = 'light';
		return trim( $classes . ' ' . implode( ' ', $extra ) );
	}

	/** Floating switcher (admin bar is hidden by the shell, so we float one). */
	public static function switcher(): void {
		if ( ! current_user_can( 'read' ) ) return;
		$active = self::active();
		$nonce  = wp_create_nonce( 'therum_apply_theme' );
		$themes = self::themes();
		?>
		<div id="th-theme-switch" data-nonce="<?php echo esc_attr( $nonce ); ?>"
			style="position:fixed;right:18px;bottom:18px;z-index:99999;font-family:-apple-system,BlinkMacSystemFont,'Inter',sans-serif;">
			<button type="button" id="th-ts-toggle" title="Switch theme (beta)"
				style="width:44px;height:44px;border-radius:50%;border:1px solid rgba(0,0,0,.12);background:#fff;box-shadow:0 6px 20px rgba(0,0,0,.18);cursor:pointer;font-size:20px;line-height:1;">🎨</button>
			<div id="th-ts-panel" style="display:none;position:absolute;right:0;bottom:54px;width:240px;background:#fff;color:#111;border:1px solid rgba(0,0,0,.1);border-radius:14px;box-shadow:0 16px 48px rgba(0,0,0,.24);padding:10px;">
				<div style="font:600 11px/1 sans-serif;text-transform:uppercase;letter-spacing:.08em;color:#999;padding:6px 8px 10px;">Themes · beta</div>
				<button type="button" class="th-ts-opt" data-theme=""
					style="display:flex;width:100%;align-items:center;gap:8px;padding:8px;border:none;background:<?php echo $active===''?'#f2f2f2':'transparent'; ?>;border-radius:8px;cursor:pointer;font:500 13px/1.2 sans-serif;color:#111;text-align:left;">Default (legacy)</button>
				<?php foreach ( $themes as $id => $t ):
					$p = $t['pal']; ?>
					<button type="button" class="th-ts-opt" data-theme="<?php echo esc_attr($id); ?>"
						style="display:flex;width:100%;align-items:center;gap:10px;padding:8px;border:none;background:<?php echo $active===$id?'#eef':'transparent'; ?>;border-radius:8px;cursor:pointer;font:500 13px/1.2 sans-serif;color:#111;text-align:left;">
						<span style="display:inline-flex;flex-shrink:0;border-radius:6px;overflow:hidden;border:1px solid rgba(0,0,0,.1);">
							<span style="width:14px;height:22px;background:<?php echo esc_attr($p['bg']); ?>;"></span>
							<span style="width:14px;height:22px;background:<?php echo esc_attr($p['sf2']); ?>;"></span>
							<span style="width:10px;height:22px;background:<?php echo esc_attr($p['ac']); ?>;"></span>
						</span>
						<span><?php echo esc_html($t['name']); ?><br><span style="font:400 10px/1 sans-serif;color:#999;"><?php echo esc_html($t['ref'].' · '.$t['mode']); ?></span></span>
					</button>
				<?php endforeach; ?>
			</div>
		</div>
		<script>
		(function(){
			var w=document.getElementById('th-theme-switch'); if(!w) return;
			var t=document.getElementById('th-ts-toggle'), p=document.getElementById('th-ts-panel');
			t.addEventListener('click',function(){ p.style.display = p.style.display==='none'?'block':'none'; });
			w.querySelectorAll('.th-ts-opt').forEach(function(b){
				b.addEventListener('click',function(){
					var fd=new FormData(); fd.append('action','therum_apply_theme');
					fd.append('theme',b.dataset.theme); fd.append('nonce',w.dataset.nonce);
					b.style.opacity=.5;
					fetch((window.ajaxurl||'/wp-admin/admin-ajax.php'),{method:'POST',credentials:'same-origin',body:fd})
						.then(function(r){return r.json();}).then(function(res){ location.reload(); })
						.catch(function(){ b.style.opacity=1; });
				});
			});
		})();
		</script>
		<?php
	}

	public static function ajax_apply(): void {
		if ( ! current_user_can( 'read' ) ) wp_send_json_error( 'forbidden', 403 );
		check_ajax_referer( 'therum_apply_theme', 'nonce' );
		$id  = sanitize_key( $_POST['theme'] ?? '' );
		$uid = get_current_user_id();
		if ( $id === '' || ! isset( self::themes()[ $id ] ) ) {
			delete_user_meta( $uid, self::META_ACTIVE );   // back to legacy
			wp_send_json_success( [ 'active' => '' ] );
		}
		update_user_meta( $uid, self::META_ACTIVE, $id );
		wp_send_json_success( [ 'active' => $id ] );
	}
}

add_action( 'init', [ 'Therum_Theme_Engine', 'init' ] );
