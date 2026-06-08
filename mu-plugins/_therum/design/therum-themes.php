<?php
/**
 * Therum OS — Therum_Themes
 *
 * Extracted from therum-design.php (1.9.x split).
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class Therum_Themes {

	const SITE_OPTION_KEY  = 'therum_theme_state';
	const USER_META_KEY    = 'therum_theme_state';

	public static function presets(): array {
		// Starting over: themes are now full MORPHS (each restyles + relocates
		// the whole chrome to look like its reference), not palette recolors.
		// Built one at a time, verified in preview, then ported here.
		return self::morph_themes();
	}

	/**
	 * THE MORPH THEMES. Each ships a dedicated stylesheet (assets/theme-<palette>.css)
	 * scoped to body.theme-<palette> that transforms the entire admin — sidebar,
	 * topbar, canvas, cards, type — to look like its reference dashboard. The
	 * theme only restyles the live menu data, so every section/page stays
	 * reachable (sections become dropdowns; auto-added plugin pages included).
	 */
	public static function morph_themes(): array {
		return [
			'theme-00' => [
				'name' => '00 · Default', 'desc' => 'Modern WordPress-admin redesign — clean cool-neutral canvas, white sidebar with clear contrast, blue accent, flat subtle cards, generous whitespace.',
				'group' => 'foundations', 'mode' => 'light', 'accent' => '#3858E9', 'density' => 'comfortable',
				'sidebar' => 'full', 'sidebarStyle' => 'default', 'font' => 'system', 'radius' => 'medium', 'shadow' => 'soft',
				'glass' => false, 'bgImage' => 'none', 'palette' => 'm00', 'previewMain' => '#F6F7F9', 'previewRail' => '#FFFFFF',
				'designSystemDoc' => 'assets/theme-m00.design-system.md',
				'tokens' => [
					'modes' => [ 'light', 'dark' ],
					'sidebar' => [ 'always' => 'dark', 'bg' => '#16181E', 'text' => '#C7CCD6', 'muted' => 'rgba(255,255,255,.42)', 'active' => 'inset-accent-bar' ],
					'light' => [
						'accent' => '#3858E9', 'accentHover' => '#2E45C5', 'accentSoft' => 'rgba(56,88,233,.10)',
						'canvas' => '#F6F7F9', 'surface' => '#FFFFFF', 'surface2' => '#F1F3F5', 'surface3' => '#E9ECEF',
						'text' => '#1E1E1E', 'text2' => '#50575E', 'text3' => '#8C8F94', 'border' => '#E3E5E8', 'border2' => '#D2D5D9',
					],
					'dark' => [
						'accent' => '#5B7CFA', 'accentHover' => '#7C97FF', 'accentSoft' => 'rgba(91,124,250,.18)',
						'canvas' => '#15161A', 'surface' => '#1E2027', 'surface2' => '#262932', 'surface3' => '#2F333D',
						'text' => '#F2F4F7', 'text2' => '#A9AEB8', 'text3' => '#6E7480', 'border' => 'rgba(255,255,255,.09)', 'border2' => 'rgba(255,255,255,.16)',
					],
					'semantic' => [ 'success' => '#1E8E3E', 'warning' => '#E6A817', 'error' => '#D63638', 'info' => '#3858E9' ],
					'typography' => [ 'display' => 'system', 'body' => 'system', 'mono' => 'monospace',
						'scale' => [ 'h1' => 28, 'cardTitle' => 16, 'stat' => 28, 'body' => 14, 'label' => 13, 'small' => 11 ] ],
					'radius' => [ 'sm' => 6, 'md' => 8, 'lg' => 10, 'full' => 999 ],
					'shadow' => [ 'card' => '0 1px 2px rgba(16,24,40,.04),0 4px 12px rgba(16,24,40,.05)' ],
					'spacing' => [ 'xs' => 4, 'sm' => 8, 'md' => 16, 'lg' => 24, 'xl' => 40 ],
					'layout' => [ 'nav' => 'left-sidebar', 'sidebar' => 'dark-rail', 'content' => 'switchable-light-dark' ],
				],
			],
			'theme-01' => [
				'name' => '01 · Warm Studio', 'desc' => 'Crextio — top pill-nav, warm ivory canvas, golden accent, very-rounded soft bento, one dark spotlight card.',
				'group' => 'foundations', 'mode' => 'light', 'accent' => '#F2C20E', 'density' => 'comfortable',
				'sidebar' => 'full', 'sidebarStyle' => 'default', 'font' => 'inter', 'radius' => 'large', 'shadow' => 'soft',
				'glass' => false, 'bgImage' => 'none', 'palette' => 'm01', 'previewMain' => '#F4ECD4', 'previewRail' => '#1A1916',
				// Design system saved with the theme (human-readable doc + machine tokens).
				'designSystemDoc' => 'assets/theme-m01.design-system.md',
				'tokens' => [
					'colors' => [
						'accent' => '#F2C20E', 'accentHover' => '#D9A800', 'accentSoft' => 'rgba(242,194,14,.14)',
						'dark' => '#1A1916', 'canvas' => '#F4ECD4', 'surface' => '#FFFFFF', 'surface2' => '#FBF6EA',
						'surface3' => '#F0E8D6', 'text' => '#1C1A16', 'text2' => '#7A7466', 'text3' => '#A8A292',
						'border' => 'rgba(40,30,10,.12)', 'border2' => 'rgba(40,30,10,.20)',
						'success' => '#2F9E5E', 'warning' => '#E6A817', 'error' => '#E5533D', 'info' => '#4F6BED',
					],
					'gradient' => [ 'from' => '#F5EFE0', 'mid' => '#F4ECD4', 'to' => '#F7E7AE', 'angle' => 165 ],
					'typography' => [
						'display' => 'Poppins', 'body' => 'Plus Jakarta Sans', 'mono' => 'JetBrains Mono',
						'scale' => [ 'hero' => 40, 'h1' => 30, 'cardTitle' => 18, 'stat' => 30, 'body' => 14, 'label' => 14, 'small' => 11 ],
					],
					'radius' => [ 'sm' => 8, 'md' => 10, 'lg' => 14, 'xl' => 16, '2xl' => 22, 'full' => 999 ],
					'shadow' => [ 'card' => '0 10px 30px rgba(120,90,10,.08)', 'bar' => '0 4px 16px rgba(120,90,10,.07)', 'dropdown' => '0 18px 48px rgba(80,60,10,.18)' ],
					'spacing' => [ 'xs' => 4, 'sm' => 8, 'md' => 12, 'lg' => 24, 'xl' => 32 ],
					'layout' => [ 'navHeight' => 66, 'nav' => 'top-pill' ],
				],
			],
			'theme-02' => [
				'name' => '02 · Coral Finance', 'desc' => 'N2 financial dashboard — light cool-gray canvas, white very-rounded cards with soft shadows, coral accent, top pill-nav, one dark spotlight card.',
				'group' => 'foundations', 'mode' => 'light', 'accent' => '#F0563E', 'density' => 'comfortable',
				'sidebar' => 'full', 'sidebarStyle' => 'default', 'font' => 'inter', 'radius' => 'large', 'shadow' => 'soft',
				'glass' => false, 'bgImage' => 'none', 'palette' => 'm02', 'previewMain' => '#EFF0F3', 'previewRail' => '#F0563E',
				'designSystemDoc' => 'assets/theme-m02.design-system.md',
				'tokens' => [
					'colors' => [ 'accent' => '#F0563E', 'accentHover' => '#DC4528', 'accentSoft' => 'rgba(240,86,62,.12)',
						'dark' => '#16181C', 'canvas' => '#EFF0F3', 'surface' => '#FFFFFF', 'surface2' => '#F6F7F9', 'surface3' => '#EEEFF2',
						'text' => '#1A1D21', 'text2' => '#6B7178', 'text3' => '#9AA0A8', 'border' => 'rgba(20,24,30,.08)', 'border2' => 'rgba(20,24,30,.14)',
						'success' => '#16A34A', 'warning' => '#E6A817', 'error' => '#E5533D', 'info' => '#3B82F6' ],
					'typography' => [ 'display' => 'Inter', 'body' => 'Inter', 'mono' => 'JetBrains Mono',
						'scale' => [ 'hero' => 30, 'h1' => 30, 'cardTitle' => 16, 'stat' => 28, 'body' => 14, 'label' => 14, 'small' => 12 ] ],
					'radius' => [ 'sm' => 8, 'md' => 12, 'lg' => 14, 'card' => 20, 'pill' => 999 ],
					'shadow' => [ 'card' => '0 1px 2px rgba(20,24,40,.04),0 12px 30px rgba(20,24,40,.06)' ],
					'spacing' => [ 'xs' => 4, 'sm' => 8, 'md' => 16, 'lg' => 24, 'xl' => 32 ],
					'layout' => [ 'navHeight' => 68, 'nav' => 'top-pill', 'spotlight' => 'last-bento-card-dark' ],
				],
			],
		];
	}

	/** Return the saved design-system tokens for a theme (its "settings"), or []. */
	public static function design_system( string $theme_id ): array {
		$presets = self::presets();
		return $presets[ $theme_id ]['tokens'] ?? [];
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
			'content' => 'regular',
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
			// A theme lands in its intended mode (e.g. Theme 00 = light); users
			// can still override per-install via Quick Controls (Mode).
			'mode'         => $p['mode'] ?? 'auto',
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
		$raw   = $_POST['nonce'] ?? $_REQUEST['_wpnonce'] ?? '';
		$nonce = sanitize_text_field( wp_unslash( (string) $raw ) );
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
		// wp_unslash on raw POST data per WP coding standards (defensive even
		// though field/value are immediately filtered/coerced below).
		$raw   = (string) wp_unslash( $_POST['field'] ?? '' );
		$field = preg_replace( '/[^a-zA-Z0-9_-]/', '', $raw );
		$value = wp_unslash( $_POST['value'] ?? '' );
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
