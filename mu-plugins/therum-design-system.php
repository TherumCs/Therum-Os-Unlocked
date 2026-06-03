<?php
/**
 * Plugin Name: Therum OS — Design System
 * Description: Core design-system surface for Therum OS. Canonical, CMS-agnostic
 *              token store + style-tile editor + tech view. Adapters keep Bricks
 *              globals in sync when Bricks is present; CSS adapter emits tokens
 *              to wp_head so the site is stylable even without Bricks.
 *              Backend lives at Therum\DesignSystem\* under _therum/src/.
 * Version: 1.9.0
 *
 * Section map:
 *   1. CONSTANTS + BOOT (adapter init)
 *   2. AJAX — save / reset endpoints
 *   3. PAGE — route + render (typography / color / components / scale + tech)
 *   4. DATA — legacy Bricks/Therum reads (kept for the Bricks readouts section)
 *   5. STYLES + SCRIPT — inline for now
 *
 * Kill switch: define( 'THERUM_DESIGN_SYSTEM_DISABLE', true ) in wp-config.php.
 */

if ( ! defined( 'ABSPATH' ) ) exit;
if ( defined( 'THERUM_DESIGN_SYSTEM_DISABLE' ) && THERUM_DESIGN_SYSTEM_DISABLE ) return;


// ════════════════════════════════════════════════════════════════════════════
//  1. CONSTANTS + BOOT
// ════════════════════════════════════════════════════════════════════════════

if ( ! defined( 'THERUM_DS_VERSION' ) )   define( 'THERUM_DS_VERSION',   '1.9.1' );
if ( ! defined( 'THERUM_DS_PAGE_SLUG' ) ) define( 'THERUM_DS_PAGE_SLUG', 'therum-design-system' );

add_action( 'plugins_loaded', function() {
	if ( class_exists( '\\Therum\\DesignSystem\\Adapter\\BricksAdapter' ) ) {
		\Therum\DesignSystem\Adapter\BricksAdapter::init();
	}
	if ( class_exists( '\\Therum\\DesignSystem\\Adapter\\CssAdapter' ) ) {
		\Therum\DesignSystem\Adapter\CssAdapter::init();
	}
}, 5 );


// ════════════════════════════════════════════════════════════════════════════
//  2. AJAX — save / reset
// ════════════════════════════════════════════════════════════════════════════

add_action( 'wp_ajax_therum_ds_save', function() {
	if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( [ 'msg' => 'forbidden' ], 403 );
	check_ajax_referer( 'therum_ds', 'nonce' );

	$raw = $_POST['system'] ?? '';
	if ( is_string( $raw ) ) {
		$decoded = json_decode( wp_unslash( $raw ), true );
	} elseif ( is_array( $raw ) ) {
		$decoded = $raw;
	} else {
		$decoded = null;
	}

	if ( ! is_array( $decoded ) ) {
		wp_send_json_error( [ 'msg' => 'invalid payload' ], 400 );
	}

	if ( ! class_exists( '\\Therum\\DesignSystem\\Store' ) ) {
		wp_send_json_error( [ 'msg' => 'store unavailable' ], 500 );
	}

	$saved = \Therum\DesignSystem\Store::save( $decoded );

	$css = class_exists( '\\Therum\\DesignSystem\\Adapter\\CssAdapter' )
		? \Therum\DesignSystem\Adapter\CssAdapter::build_pretty()
		: '';

	wp_send_json_success( [
		'system' => $saved,
		'css'    => $css,
		'synced_to_bricks' => class_exists( '\\Therum\\DesignSystem\\Adapter\\BricksAdapter' )
			&& \Therum\DesignSystem\Adapter\BricksAdapter::is_available(),
	] );
} );

add_action( 'wp_ajax_therum_ds_reset', function() {
	if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( [ 'msg' => 'forbidden' ], 403 );
	check_ajax_referer( 'therum_ds', 'nonce' );

	if ( ! class_exists( '\\Therum\\DesignSystem\\Store' ) ) {
		wp_send_json_error( [ 'msg' => 'store unavailable' ], 500 );
	}

	$system = \Therum\DesignSystem\Store::reset();
	wp_send_json_success( [ 'system' => $system ] );
} );


// ════════════════════════════════════════════════════════════════════════════
//  3. PAGE — route registration + render
// ════════════════════════════════════════════════════════════════════════════

class Therum_Design_System_Page {

	public static function init(): void {
		add_action( 'admin_menu',    [ __CLASS__, 'register_route' ], 999 );
		add_action( 'admin_head',    [ __CLASS__, 'inject_styles' ] );
		add_action( 'admin_footer',  [ __CLASS__, 'inject_script' ] );
	}

	public static function register_route(): void {
		add_submenu_page(
			'',
			'Design System',
			'Design System',
			'manage_options',
			THERUM_DS_PAGE_SLUG,
			[ __CLASS__, 'render' ]
		);
	}

	public static function inject_styles(): void {
		$page = $_GET['page'] ?? '';
		if ( $page !== THERUM_DS_PAGE_SLUG ) return;
		echo "<style id='therum-ds-inline'>" . self::css() . "</style>";
	}

	public static function inject_script(): void {
		$page = $_GET['page'] ?? '';
		if ( $page !== THERUM_DS_PAGE_SLUG ) return;
		$nonce = wp_create_nonce( 'therum_ds' );
		$ajax  = admin_url( 'admin-ajax.php' );
		?>
		<script id="therum-ds-inline-js">
		window.therumDS = { nonce: <?php echo wp_json_encode( $nonce ); ?>, ajax: <?php echo wp_json_encode( $ajax ); ?> };
		</script>
		<script id="therum-ds-inline-js-body">
		<?php echo self::js(); ?>
		</script>
		<?php
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( __( 'Insufficient permissions.', 'therum' ) );
		}

		$system           = self::current_system();
		$bricks_available = class_exists( '\\Therum\\DesignSystem\\Adapter\\BricksAdapter' )
			&& \Therum\DesignSystem\Adapter\BricksAdapter::is_available();
		$tech_css = class_exists( '\\Therum\\DesignSystem\\Adapter\\CssAdapter' )
			? \Therum\DesignSystem\Adapter\CssAdapter::build_pretty()
			: '';

		$name    = (string) ( $system['meta']['name']       ?? 'Untitled system' );
		$updated = (string) ( $system['meta']['updated_at'] ?? '' );

		// Pull role colors for the brand stripe + header preview-font scope.
		$colors  = $system['colors'] ?? [];
		$fonts   = $system['fonts']  ?? [];
		$by_role = self::index_by_role( $colors );
		$stripe  = self::stripe_colors( $colors, $by_role );

		$display_font = self::find_font( $fonts, 'display' );
		$header_ff    = $display_font['stack'] ?? 'inherit';

		// Aggregate counts for the header sidebar.
		$counts = [
			'colors'  => count( $colors ),
			'fonts'   => count( $fonts ),
			'spacing' => count( $system['spacing'] ?? [] ),
			'radii'   => count( $system['radii']   ?? [] ),
		];
		?>
		<div class="th-lp therum-ds" data-page-id="design-system">

			<!-- ── Signature brand stripe ────────────────────────────────── -->
			<div class="ds-stripe" aria-hidden="true">
				<?php foreach ( $stripe as $sc ): ?>
					<span class="ds-stripe-cell" style="background: <?php echo esc_attr( $sc['value'] ); ?>;" title="<?php echo esc_attr( $sc['label'] . ' — ' . $sc['value'] ); ?>"></span>
				<?php endforeach; ?>
			</div>

			<!-- ── Editorial page header ─────────────────────────────────── -->
			<header class="ds-cover">
				<div class="ds-cover-main">
					<div class="ds-cover-eyebrow">Therum&nbsp;·&nbsp;Design&nbsp;System</div>
					<h1 class="ds-cover-title" style="font-family: <?php echo esc_attr( $header_ff ); ?>;" contenteditable="true" spellcheck="false" data-ds-name title="Click to rename"><?php echo esc_html( $name ); ?></h1>
					<p class="ds-cover-tag"><em>Tokens, type, components &mdash; one canonical surface.</em></p>
				</div>
				<aside class="ds-cover-spec">
					<div class="ds-spec-row"><span>Status</span><b><?php echo $bricks_available ? 'Synced to Bricks' : 'Standalone'; ?></b></div>
					<?php if ( $updated !== '' ): ?>
						<div class="ds-spec-row"><span>Last saved</span><b><?php echo esc_html( substr( str_replace( 'T', ' ', $updated ), 0, 16 ) ); ?> UTC</b></div>
					<?php else: ?>
						<div class="ds-spec-row"><span>Last saved</span><b style="color:var(--p-tx3);">never</b></div>
					<?php endif; ?>
					<div class="ds-spec-row"><span>Colors</span><b><?php echo (int) $counts['colors']; ?></b></div>
					<div class="ds-spec-row"><span>Fonts</span><b><?php echo (int) $counts['fonts']; ?></b></div>
					<div class="ds-spec-row"><span>Spacing</span><b><?php echo (int) $counts['spacing']; ?></b></div>
					<div class="ds-spec-row"><span>Radii</span><b><?php echo (int) $counts['radii']; ?></b></div>
					<div class="ds-spec-row"><span>Plugin</span><b>v<?php echo esc_html( THERUM_DS_VERSION ); ?></b></div>
				</aside>
			</header>

			<!-- ── View tabs ─────────────────────────────────────────────── -->
			<nav class="ds-viewbar">
				<div class="ds-tab-group" role="tablist">
					<button type="button" class="ds-tab is-active" data-ds-view="visual" role="tab" aria-selected="true">
						<span class="ds-tab-num">01–04</span><span class="ds-tab-name">Visual</span>
					</button>
					<button type="button" class="ds-tab" data-ds-view="tech" role="tab" aria-selected="false">
						<span class="ds-tab-num">{ }</span><span class="ds-tab-name">Tech</span>
					</button>
				</div>
				<button type="button" class="ds-action ds-action--ghost" data-ds-reset>Reset</button>
			</nav>

			<!-- ── Visual view ───────────────────────────────────────────── -->
			<div class="ds-view" data-ds-view-pane="visual" data-ds-system='<?php echo esc_attr( wp_json_encode( $system ) ); ?>'>
				<?php self::render_visual( $system ); ?>
			</div>

			<!-- ── Tech view ─────────────────────────────────────────────── -->
			<div class="ds-view" data-ds-view-pane="tech" hidden>
				<?php self::render_tech( $system, $tech_css ); ?>
			</div>

			<!-- ── Bricks readouts (collapsed) ───────────────────────────── -->
			<?php if ( $bricks_available ): ?>
			<details class="ds-disclosure">
				<summary>
					<span>What Bricks sees</span>
					<span class="ds-disclosure-meta">Downstream sync · read-only</span>
				</summary>
				<div class="ds-disclosure-body">
					<?php self::render_bricks_readouts(); ?>
				</div>
			</details>
			<?php endif; ?>

			<!-- ── Floating save dock ────────────────────────────────────── -->
			<div class="ds-dock" data-ds-dock hidden>
				<div class="ds-dock-msg">Unsaved changes</div>
				<button type="button" class="ds-action ds-action--ghost ds-action--sm" data-ds-discard>Discard</button>
				<button type="button" class="ds-action ds-action--primary" data-ds-save>Save changes</button>
			</div>

			<!-- ── Toast ─────────────────────────────────────────────────── -->
			<div class="ds-toast" data-ds-toast hidden></div>

		</div>
		<?php
	}

	/** Pick up to 4 swatches for the top brand stripe — role-preferred. */
	private static function stripe_colors( array $colors, array $by_role ): array {
		$preferred = [ 'primary', 'accent', 'text', 'surface' ];
		$out = [];
		foreach ( $preferred as $role ) {
			if ( ! isset( $by_role[ $role ] ) ) continue;
			$out[] = [ 'label' => $role, 'value' => $by_role[ $role ] ];
		}
		// Pad with first colors if roles missing
		foreach ( $colors as $c ) {
			if ( count( $out ) >= 4 ) break;
			$val = (string) ( $c['value'] ?? '' );
			if ( $val === '' ) continue;
			$out[] = [ 'label' => (string) ( $c['name'] ?? $c['id'] ?? 'Color' ), 'value' => $val ];
		}
		return array_slice( $out, 0, 4 );
	}

	// ── Visual view ──────────────────────────────────────────────────────────

	private static function render_visual( array $system ): void {
		$colors  = $system['colors']  ?? [];
		$fonts   = $system['fonts']   ?? [];
		$spacing = $system['spacing'] ?? [];
		$radii   = $system['radii']   ?? [];
		$shadows = $system['shadows'] ?? [];

		$by_role  = self::index_by_role( $colors );
		$primary  = $by_role['primary']  ?? ( $colors[0]['value']  ?? '#0a0a0a' );
		$accent   = $by_role['accent']   ?? ( $colors[1]['value']  ?? '#e83b3b' );
		$surface  = $by_role['surface']  ?? '#ffffff';
		$surface2 = $by_role['surface-2']?? '#fafafa';
		$text     = $by_role['text']     ?? '#0a0a0a';
		$text2    = $by_role['text-2']   ?? '#666666';
		$text3    = $by_role['text-3']   ?? '#999999';
		$border   = $by_role['border']   ?? 'rgba(0,0,0,.08)';

		$display = self::find_font( $fonts, 'display' );
		$body    = self::find_font( $fonts, 'body' );
		$mono    = self::find_font( $fonts, 'mono' );

		$radius_md = self::find_value( $radii, 'md', '8px' );

		// Canvas vars — JS updates these to live-preview.
		$tile_vars = sprintf(
			'--bg: %s; --bg2: %s; --tx: %s; --tx2: %s; --tx3: %s; --bd: %s; --primary: %s; --accent: %s; --r-md: %s; --ff-display: %s; --ff-body: %s; --ff-mono: %s;',
			esc_attr( $surface ),  esc_attr( $surface2 ),
			esc_attr( $text ),     esc_attr( $text2 ),     esc_attr( $text3 ),
			esc_attr( $border ),
			esc_attr( $primary ),  esc_attr( $accent ),
			esc_attr( $radius_md ),
			esc_attr( $display['stack'] ?? 'inherit' ),
			esc_attr( $body['stack']    ?? 'inherit' ),
			esc_attr( $mono['stack']    ?? 'monospace' )
		);

		// Short name of a font (first family in the stack) for use in labels.
		$first_family = function( ?string $stack ): string {
			$s = trim( (string) $stack );
			if ( $s === '' ) return '—';
			if ( preg_match( '/^"([^"]+)"/', $s, $m ) ) return $m[1];
			if ( preg_match( '/^([A-Za-z][A-Za-z0-9 \-]+)(?:,|$)/', $s, $m ) ) return trim( $m[1] );
			return '—';
		};
		$display_short = $first_family( $display['stack'] ?? '' );
		$body_short    = $first_family( $body['stack']    ?? '' );

		$specimen = [
			[ 'label' => 'Display',    'size' => 56, 'weight' => 500, 'family' => 'display', 'lh' => 1.0,  'text' => 'Design that ships.' ],
			[ 'label' => 'Heading 1',  'size' => 36, 'weight' => 500, 'family' => 'display', 'lh' => 1.1,  'text' => 'Tokens drive the surface.' ],
			[ 'label' => 'Heading 2',  'size' => 24, 'weight' => 500, 'family' => 'display', 'lh' => 1.2,  'text' => 'A system you can see and edit.' ],
			[ 'label' => 'Body large', 'size' => 18, 'weight' => 400, 'family' => 'body',    'lh' => 1.55, 'text' => 'Body copy renders in your Body font — the same stack the front-end uses.' ],
			[ 'label' => 'Body',       'size' => 15, 'weight' => 400, 'family' => 'body',    'lh' => 1.6,  'text' => 'The quick brown fox jumps over the lazy dog. Body sets the pace for most reading.' ],
			[ 'label' => 'Small',      'size' => 13, 'weight' => 400, 'family' => 'body',    'lh' => 1.5,  'text' => 'Caption · meta · UI labels.' ],
			[ 'label' => 'Mono',       'size' => 13, 'weight' => 400, 'family' => 'mono',    'lh' => 1.5,  'text' => '--th-font-mono: monospace;' ],
		];
		?>
		<div class="ds-canvas" style="<?php echo $tile_vars; // phpcs:ignore — values escaped above ?>">

			<!-- 01 · Typography ─────────────────────────────────────────── -->
			<section class="ds-band">
				<header class="ds-chapter">
					<div class="ds-chapter-id">
						<span class="ds-chapter-num">01</span>
						<h2 class="ds-chapter-title">Typography</h2>
					</div>
					<div class="ds-chapter-ctl">
						<label class="ds-picker">
							<span>Display</span>
							<select data-ds-font-stack="display"><?php self::render_font_options( $display['stack'] ?? '' ); ?></select>
						</label>
						<label class="ds-picker">
							<span>Body</span>
							<select data-ds-font-stack="body"><?php self::render_font_options( $body['stack'] ?? '' ); ?></select>
						</label>
					</div>
				</header>

				<div class="ds-specimen">
					<?php foreach ( $specimen as $row ): ?>
						<div class="ds-specimen-row">
							<div class="ds-specimen-meta">
								<div class="ds-specimen-label"><?php echo esc_html( $row['label'] ); ?></div>
								<div class="ds-specimen-spec"><?php echo (int) $row['size']; ?>px / <?php echo (int) $row['weight']; ?></div>
							</div>
							<div class="ds-specimen-sample" style="font-family: var(--ff-<?php echo esc_attr( $row['family'] ); ?>); font-size: <?php echo (int) $row['size']; ?>px; font-weight: <?php echo (int) $row['weight']; ?>; line-height: <?php echo esc_attr( (string) $row['lh'] ); ?>;">
								<?php echo esc_html( $row['text'] ); ?>
							</div>
						</div>
					<?php endforeach; ?>
				</div>

				<!-- Type scale — sizes from system.sizes (separate from specimen size set) -->
				<?php $sizes = $system['sizes'] ?? []; ?>
				<div class="ds-typescale">
					<div class="ds-typescale-head">
						<div class="ds-typescale-label">Type scale</div>
						<div class="ds-typescale-meta"><?php echo count( $sizes ); ?> step<?php echo count( $sizes ) === 1 ? '' : 's'; ?> &middot; click to copy</div>
					</div>
					<div class="ds-typescale-row" data-ds-bucket="sizes">
						<?php foreach ( $sizes as $i => $s ):
							$val = (string) ( $s['value'] ?? '' );
							?>
							<div class="ds-typescale-item" data-ds-token-idx="<?php echo $i; ?>" data-ds-bucket="sizes">
								<button type="button" class="ds-typescale-sample" data-ds-copy-val="<?php echo esc_attr( $val ); ?>" style="font-size: <?php echo esc_attr( $val ); ?>; font-family: var(--ff-display); line-height: 1;" title="Copy <?php echo esc_attr( $val ); ?>">Aa</button>
								<div class="ds-typescale-meta">
									<span class="ds-typescale-name"><?php echo esc_html( $s['name'] ); ?></span>
									<span class="ds-typescale-val"><?php echo esc_html( $val ); ?></span>
								</div>
								<button type="button" class="ds-token-rm" data-ds-token-remove aria-label="Remove">&times;</button>
							</div>
						<?php endforeach; ?>
						<button type="button" class="ds-token-add" data-ds-token-add="sizes" aria-label="Add size">
							<span class="ds-token-add-icon">+</span>
							<span class="ds-token-add-label">Add size</span>
						</button>
					</div>
				</div>
			</section>

			<!-- 02 · Color ──────────────────────────────────────────────── -->
			<section class="ds-band">
				<header class="ds-chapter">
					<div class="ds-chapter-id">
						<span class="ds-chapter-num">02</span>
						<h2 class="ds-chapter-title">Color</h2>
					</div>
					<div class="ds-chapter-ctl">
						<span class="ds-chapter-meta"><?php echo count( $colors ); ?> tokens · click any swatch to edit</span>
					</div>
				</header>

				<div class="ds-palette" data-ds-palette>
					<?php foreach ( $colors as $i => $c ):
						$val      = (string) ( $c['value'] ?? '#000000' );
						$role     = (string) ( $c['role'] ?? '' );
						$contrast = self::contrast_for( $val );
						?>
						<article class="ds-color" data-ds-color-idx="<?php echo $i; ?>" data-ds-copy-hex="<?php echo esc_attr( $val ); ?>" data-ds-bucket="colors">
							<button type="button" class="ds-token-rm" data-ds-token-remove aria-label="Remove">&times;</button>
							<label class="ds-color-fill" style="background: <?php echo esc_attr( $val ); ?>; color: <?php echo esc_attr( $contrast ); ?>;">
								<input type="color" class="ds-color-input" value="<?php echo esc_attr( self::to_hex_input( $val ) ); ?>" data-ds-color-input="<?php echo $i; ?>" aria-label="Edit <?php echo esc_attr( $c['name'] ?? '' ); ?>">
								<?php if ( $role ): ?>
								<span class="ds-color-role"><?php echo esc_html( $role ); ?></span>
								<?php endif; ?>
								<span class="ds-color-edit-cue" aria-hidden="true">click&nbsp;to&nbsp;edit</span>
							</label>
							<div class="ds-color-meta">
								<div class="ds-color-name"><?php echo esc_html( $c['name'] ?? $c['id'] ?? 'Color' ); ?></div>
								<div class="ds-color-bottom">
									<span class="ds-color-hex"><?php echo esc_html( $val ); ?></span>
									<button type="button" class="ds-color-copy" data-ds-copy-hex="<?php echo esc_attr( $val ); ?>" aria-label="Copy hex">
										<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="11" height="11" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
									</button>
								</div>
							</div>
						</article>
					<?php endforeach; ?>
					<button type="button" class="ds-token-add ds-color-add" data-ds-token-add="colors" aria-label="Add color">
						<span class="ds-token-add-icon">+</span>
						<span class="ds-token-add-label">Add color</span>
					</button>
				</div>
			</section>

			<!-- 03 · Components ─────────────────────────────────────────── -->
			<section class="ds-band">
				<header class="ds-chapter">
					<div class="ds-chapter-id">
						<span class="ds-chapter-num">03</span>
						<h2 class="ds-chapter-title">Components</h2>
					</div>
					<div class="ds-chapter-ctl">
						<span class="ds-chapter-meta">composed from your case-study primitives · tokens applied live</span>
					</div>
				</header>

				<div class="ds-comp-grid">

					<!-- ── Device frame (browser chrome wrapper used across case studies) ── -->
					<div class="ds-comp">
						<div class="ds-comp-label">
							<span>Device frame</span>
							<code class="ds-comp-class">.therum-cs__browser-tile</code>
						</div>
						<div class="ds-comp-stage ds-comp-stage--media">
							<figure class="ds-cs-browser">
								<div class="ds-cs-browser-bar" aria-hidden="true">
									<span class="ds-cs-dot" style="background:#ff5f56"></span>
									<span class="ds-cs-dot" style="background:#ffbd2e"></span>
									<span class="ds-cs-dot" style="background:#27c93f"></span>
									<span class="ds-cs-browser-url">sidemoney.co</span>
								</div>
								<div class="ds-cs-browser-canvas" style="font-family: var(--ff-display);">
									<div class="ds-cs-browser-display">Sidemoney</div>
									<div class="ds-cs-browser-sub" style="font-family: var(--ff-body);">Stories told in the world's true language.</div>
									<div class="ds-cs-browser-cta"><button type="button" class="ds-btn ds-btn--accent ds-btn--sm">Field notes &rarr;</button></div>
								</div>
							</figure>
						</div>
					</div>

					<!-- ── Scroll plate (the scrollable preview tile) ── -->
					<div class="ds-comp">
						<div class="ds-comp-label">
							<span>Scroll plate</span>
							<code class="ds-comp-class">.plate--scroll</code>
						</div>
						<div class="ds-comp-stage ds-comp-stage--media">
							<figure class="ds-cs-plate">
								<div class="ds-cs-plate-window">
									<div class="ds-cs-plate-strip">
										<div class="ds-cs-plate-row" style="font-family: var(--ff-display); color: var(--tx);">Sidemoney 2.0</div>
										<div class="ds-cs-plate-row ds-cs-plate-row--alt" style="font-family: var(--ff-body); color: var(--tx2);">Relaunched April 9, 2025 &mdash; brand, site, and operating system rebuilt from first principles.</div>
										<div class="ds-cs-plate-row" style="font-family: var(--ff-display); color: var(--tx);">Comcast NBCUniversal</div>
										<div class="ds-cs-plate-row ds-cs-plate-row--alt" style="font-family: var(--ff-body); color: var(--tx2);">XfinityMobile.com refresh &mdash; cards, devices, and a measurable lift in conversion.</div>
										<div class="ds-cs-plate-row" style="font-family: var(--ff-display); color: var(--tx);">JPMorgan Chase</div>
										<div class="ds-cs-plate-row ds-cs-plate-row--alt" style="font-family: var(--ff-body); color: var(--tx2);">Aston Martin partnership &mdash; postcard, ATM moment, multi-touch DM program.</div>
										<div class="ds-cs-plate-row" style="font-family: var(--ff-display); color: var(--tx);">Kingsland</div>
									</div>
								</div>
								<div class="ds-cs-plate-caption">Hover-scroll surface &mdash; reserved for actually scrollable plates per the rule.</div>
							</figure>
						</div>
					</div>

					<!-- ── Brand block (mini style tile per case study) ── -->
					<div class="ds-comp">
						<div class="ds-comp-label">
							<span>Brand block</span>
							<code class="ds-comp-class">.therum-cs__brand-block</code>
						</div>
						<div class="ds-comp-stage ds-comp-stage--media">
							<figure class="ds-cs-brand">
								<header class="ds-cs-brand-head">
									<span class="ds-cs-brand-kicker">Brand &middot; 2.0 relaunch</span>
									<h5 class="ds-cs-brand-name" style="font-family: var(--ff-display);">Sidemoney</h5>
									<div class="ds-cs-brand-chips">
										<span class="ds-cs-brand-chip">PHL-NYC-WLRD</span>
										<span class="ds-cs-brand-chip">Founded 2019</span>
										<span class="ds-cs-brand-chip">Documentation</span>
									</div>
								</header>
								<div class="ds-cs-brand-tile">
									<div class="ds-cs-brand-stripe">
										<span style="background:var(--primary)" title="Primary"></span>
										<span style="background:var(--accent)" title="Accent"></span>
										<span style="background:var(--tx2)" title="Text 2"></span>
										<span style="background:var(--bg2)" title="Surface 2"></span>
									</div>
									<div class="ds-cs-brand-spec" style="font-family: var(--ff-body);">
										<div class="ds-cs-brand-type" style="font-family: var(--ff-display);">Aa</div>
										<div class="ds-cs-brand-meta">
											<div>Display &middot; <em data-ds-font-label="display"><?php echo esc_html( $display_short ); ?></em></div>
											<div>Body &middot; <em data-ds-font-label="body"><?php echo esc_html( $body_short ); ?></em></div>
										</div>
									</div>
								</div>
							</figure>
						</div>
					</div>

					<!-- ── Architecture stack (numbered layers, Therum OS-style diagram) ── -->
					<div class="ds-comp">
						<div class="ds-comp-label">
							<span>Arch stack</span>
							<code class="ds-comp-class">.therum-cs__archstack</code>
						</div>
						<div class="ds-comp-stage ds-comp-stage--media">
							<div class="ds-cs-archstack">
								<?php
								$layers = [
									[ 'n' => '01', 'name' => 'Surface',   'tech' => 'Therum admin chrome &middot; Bricks builder' ],
									[ 'n' => '02', 'name' => 'Tokens',    'tech' => 'therum_design_system &middot; canonical store' ],
									[ 'n' => '03', 'name' => 'Kernel',    'tech' => 'Therum\\* lib &middot; MCP &middot; Queue &middot; Events' ],
									[ 'n' => '04', 'name' => 'Substrate', 'tech' => 'WordPress &middot; MySQL &rarr; SQLite (planned)' ],
								];
								foreach ( $layers as $layer ): ?>
									<div class="ds-cs-archstack-layer">
										<div class="ds-cs-archstack-num" style="font-family: var(--ff-display);"><?php echo esc_html( $layer['n'] ); ?></div>
										<div class="ds-cs-archstack-body">
											<div class="ds-cs-archstack-name" style="font-family: var(--ff-display);"><?php echo esc_html( $layer['name'] ); ?></div>
											<div class="ds-cs-archstack-tech"><?php echo wp_kses( $layer['tech'], [ 'em' => [] ] ); ?></div>
										</div>
									</div>
								<?php endforeach; ?>
							</div>
						</div>
					</div>

					<!-- ── Device cycler (the morphing slider — phone → tablet → laptop) ── -->
					<div class="ds-comp">
						<div class="ds-comp-label">
							<span>Device cycler</span>
							<code class="ds-comp-class">.therum-cs__device-cycle</code>
						</div>
						<div class="ds-comp-stage ds-comp-stage--media">
							<figure class="ds-cs-cycle">
								<div class="ds-cs-cycle-stage">
									<span class="ds-cs-cycle-frame is-phone"   aria-label="Phone"></span>
									<span class="ds-cs-cycle-frame is-tablet"  aria-label="Tablet"></span>
									<span class="ds-cs-cycle-frame is-laptop"  aria-label="Laptop"></span>
								</div>
								<div class="ds-cs-cycle-dots" aria-hidden="true">
									<span class="ds-cs-cycle-dot is-on"></span>
									<span class="ds-cs-cycle-dot"></span>
									<span class="ds-cs-cycle-dot"></span>
								</div>
							</figure>
						</div>
					</div>

					<!-- ── Across devices (phone + laptop side-by-side, sync scroll) ── -->
					<div class="ds-comp">
						<div class="ds-comp-label">
							<span>Across devices</span>
							<code class="ds-comp-class">.therum-cs__across-devices</code>
						</div>
						<div class="ds-comp-stage ds-comp-stage--media">
							<figure class="ds-cs-across">
								<div class="ds-cs-across-laptop">
									<div class="ds-cs-across-laptop-screen">
										<span class="ds-cs-across-line is-strong"></span>
										<span class="ds-cs-across-line is-img"></span>
										<span class="ds-cs-across-line"></span>
										<span class="ds-cs-across-line is-short"></span>
									</div>
									<div class="ds-cs-across-laptop-base"></div>
								</div>
								<div class="ds-cs-across-phone">
									<div class="ds-cs-across-phone-screen">
										<span class="ds-cs-across-line is-strong"></span>
										<span class="ds-cs-across-line is-img"></span>
										<span class="ds-cs-across-line"></span>
										<span class="ds-cs-across-line is-short"></span>
									</div>
								</div>
							</figure>
						</div>
					</div>

					<!-- ── Split section (50/50 image | text editorial pattern) ── -->
					<div class="ds-comp">
						<div class="ds-comp-label">
							<span>Split section</span>
							<code class="ds-comp-class">.therum-cs__split</code>
						</div>
						<div class="ds-comp-stage ds-comp-stage--media">
							<figure class="ds-cs-split">
								<div class="ds-cs-split-media"></div>
								<div class="ds-cs-split-body" style="font-family: var(--ff-body);">
									<div class="ds-cs-split-kicker" style="font-family: var(--ff-display);">Section title</div>
									<span class="ds-cs-split-line is-strong"></span>
									<span class="ds-cs-split-line"></span>
									<span class="ds-cs-split-line is-short"></span>
								</div>
							</figure>
						</div>
					</div>

					<!-- ── Spec strip (numbered milestones / facts row) ── -->
					<div class="ds-comp">
						<div class="ds-comp-label">
							<span>Spec strip</span>
							<code class="ds-comp-class">.therum-cs__spec-strip</code>
						</div>
						<div class="ds-comp-stage ds-comp-stage--media">
							<figure class="ds-cs-spec-strip">
								<?php $strip = [ ['n'=>'01','l'=>'Discovery'], ['n'=>'02','l'=>'System'], ['n'=>'03','l'=>'Surface'], ['n'=>'04','l'=>'Ship'] ];
								foreach ( $strip as $cell ): ?>
									<div class="ds-cs-spec-cell">
										<div class="ds-cs-spec-num" style="font-family: var(--ff-display);"><?php echo esc_html( $cell['n'] ); ?></div>
										<div class="ds-cs-spec-l" style="font-family: var(--ff-body);"><?php echo esc_html( $cell['l'] ); ?></div>
									</div>
								<?php endforeach; ?>
							</figure>
						</div>
					</div>

				</div>

				<!-- Primitives strip — buttons / form / badges in one compact row, since they
				     underpin every component above. Kept for completeness, not the show. -->
				<div class="ds-prim-strip">
					<div class="ds-prim-strip-label">Primitives</div>
					<div class="ds-prim-strip-row" style="font-family: var(--ff-body);">
						<button type="button" class="ds-btn ds-btn--primary">Primary</button>
						<button type="button" class="ds-btn ds-btn--accent">Accent</button>
						<button type="button" class="ds-btn ds-btn--ghost">Ghost</button>
						<span class="ds-prim-sep"></span>
						<input type="email" class="ds-input ds-input--inline" placeholder="email@therum.dev">
						<span class="ds-prim-sep"></span>
						<span class="ds-badge ds-badge--primary">Live</span>
						<span class="ds-badge ds-badge--accent">New</span>
						<span class="ds-chip">v<?php echo esc_html( THERUM_DS_VERSION ); ?></span>
					</div>
				</div>
			</section>

			<!-- 04 · Scale ──────────────────────────────────────────────── -->
			<section class="ds-band">
				<header class="ds-chapter">
					<div class="ds-chapter-id">
						<span class="ds-chapter-num">04</span>
						<h2 class="ds-chapter-title">Scale</h2>
					</div>
					<div class="ds-chapter-ctl">
						<span class="ds-chapter-meta"><?php echo count( $spacing ); ?> spacing &middot; <?php echo count( $radii ); ?> radii &middot; <?php echo count( $shadows ); ?> shadows &middot; click any value to copy</span>
					</div>
				</header>

				<div class="ds-scale">

					<!-- Spacing — stacked bars + an in-context gap demo -->
					<div class="ds-scale-group">
						<div class="ds-scale-group-head">
							<div class="ds-scale-group-label">Spacing</div>
							<div class="ds-scale-group-meta">px scale &middot; click to copy</div>
						</div>
						<div class="ds-spacing-row" data-ds-bucket="spacing">
							<?php foreach ( $spacing as $i => $s ): ?>
								<div class="ds-spacing-item" data-ds-token-idx="<?php echo $i; ?>" data-ds-bucket="spacing">
									<button type="button" class="ds-token-rm" data-ds-token-remove aria-label="Remove">&times;</button>
									<button type="button" class="ds-spacing-bar-btn" data-ds-copy-val="<?php echo esc_attr( $s['value'] ); ?>" title="Copy <?php echo esc_attr( $s['value'] ); ?>">
										<div class="ds-spacing-bar" style="height: <?php echo esc_attr( $s['value'] ); ?>;"></div>
										<div class="ds-spacing-meta">
											<span class="ds-spacing-name"><?php echo esc_html( $s['name'] ); ?></span>
											<span class="ds-spacing-val"><?php echo esc_html( $s['value'] ); ?></span>
										</div>
									</button>
								</div>
							<?php endforeach; ?>
							<button type="button" class="ds-token-add" data-ds-token-add="spacing" aria-label="Add spacing">
								<span class="ds-token-add-icon">+</span>
								<span class="ds-token-add-label">Add</span>
							</button>
						</div>
						<!-- In-context demo: a row whose gap toggles through the scale -->
						<div class="ds-spacing-demo" data-ds-spacing-demo>
							<div class="ds-spacing-demo-stage">
								<?php for ( $i = 0; $i < 4; $i++ ): ?>
									<span class="ds-spacing-demo-box"></span>
								<?php endfor; ?>
							</div>
							<div class="ds-spacing-demo-ctl">
								<span class="ds-spacing-demo-label">Hover any bar above &mdash; this row's <code>gap</code> updates</span>
								<span class="ds-spacing-demo-current" data-ds-spacing-current><?php echo esc_html( $spacing[3]['value'] ?? '16px' ); ?></span>
							</div>
						</div>
					</div>

					<!-- Radii — applied to real mini-cards -->
					<div class="ds-scale-group">
						<div class="ds-scale-group-head">
							<div class="ds-scale-group-label">Radii</div>
							<div class="ds-scale-group-meta">applied to a primary card &middot; click to copy</div>
						</div>
						<div class="ds-radii-row" data-ds-bucket="radii">
							<?php foreach ( $radii as $i => $r ): ?>
								<div class="ds-radius-item" data-ds-token-idx="<?php echo $i; ?>" data-ds-bucket="radii">
									<button type="button" class="ds-token-rm" data-ds-token-remove aria-label="Remove">&times;</button>
									<button type="button" class="ds-radius-btn" data-ds-copy-val="<?php echo esc_attr( $r['value'] ); ?>" title="Copy <?php echo esc_attr( $r['value'] ); ?>">
										<div class="ds-radius-card" style="border-radius: <?php echo esc_attr( $r['value'] ); ?>; background: var(--primary); color: var(--bg);">
											<span class="ds-radius-card-mark" style="font-family: var(--ff-display);">&phi;</span>
										</div>
										<div class="ds-radius-meta">
											<span class="ds-radius-name"><?php echo esc_html( $r['name'] ); ?></span>
											<span class="ds-radius-val"><?php echo esc_html( $r['value'] ); ?></span>
										</div>
									</button>
								</div>
							<?php endforeach; ?>
							<button type="button" class="ds-token-add" data-ds-token-add="radii" aria-label="Add radius">
								<span class="ds-token-add-icon">+</span>
								<span class="ds-token-add-label">Add</span>
							</button>
						</div>
					</div>

					<!-- Shadows — applied to surface tiles -->
					<?php if ( ! empty( $shadows ) ): ?>
					<div class="ds-scale-group">
						<div class="ds-scale-group-head">
							<div class="ds-scale-group-label">Shadows</div>
							<div class="ds-scale-group-meta">applied to a surface tile &middot; click to copy</div>
						</div>
						<div class="ds-shadows-row" data-ds-bucket="shadows">
							<?php foreach ( $shadows as $i => $sh ): ?>
								<div class="ds-shadow-item" data-ds-token-idx="<?php echo $i; ?>" data-ds-bucket="shadows">
									<button type="button" class="ds-token-rm" data-ds-token-remove aria-label="Remove">&times;</button>
									<button type="button" class="ds-shadow-btn" data-ds-copy-val="<?php echo esc_attr( $sh['value'] ); ?>" title="Copy shadow">
										<div class="ds-shadow-card" style="box-shadow: <?php echo esc_attr( $sh['value'] ); ?>; background: var(--bg);"></div>
										<div class="ds-shadow-meta">
											<span class="ds-shadow-name"><?php echo esc_html( $sh['name'] ); ?></span>
											<span class="ds-shadow-val" title="<?php echo esc_attr( $sh['value'] ); ?>"><?php echo esc_html( self::short( $sh['value'], 28 ) ); ?></span>
										</div>
									</button>
								</div>
							<?php endforeach; ?>
							<button type="button" class="ds-token-add" data-ds-token-add="shadows" aria-label="Add shadow">
								<span class="ds-token-add-icon">+</span>
								<span class="ds-token-add-label">Add</span>
							</button>
						</div>
					</div>
					<?php endif; ?>

				</div>
			</section>

		</div>
		<?php
	}

	// ── Tech view ────────────────────────────────────────────────────────────

	private static function render_tech( array $system, string $tech_css ): void {
		?>
		<div class="ds-tech">
			<div class="ds-tech-col ds-tech-col--main">
				<header class="ds-tech-head">
					<h3 class="ds-tech-title">Export</h3>
					<div class="ds-tech-format" role="tablist">
						<button type="button" class="ds-tech-format-btn is-active" data-ds-format="css">CSS</button>
						<button type="button" class="ds-tech-format-btn"           data-ds-format="json">JSON</button>
						<button type="button" class="ds-tech-format-btn"           data-ds-format="tailwind">Tailwind</button>
						<button type="button" class="ds-tech-format-btn"           data-ds-format="scss">SCSS</button>
						<button type="button" class="ds-tech-format-btn"           data-ds-format="figma">Figma</button>
					</div>
					<button type="button" class="ds-tech-copy" data-ds-copy="export">Copy</button>
					<button type="button" class="ds-tech-copy" data-ds-download="export" title="Download current format as a file">Download</button>
					<button type="button" class="ds-tech-copy" data-ds-pdf title="Print the whole design system as a PDF">PDF</button>
				</header>
				<pre class="ds-tech-pre" data-ds-export-pre><code><?php echo esc_html( $tech_css ); ?></code></pre>
				<p class="ds-tech-note">CSS: emitted via <code>wp_head</code> + <code>admin_head</code>. JSON: stored at <code>wp_options.therum_design_system</code>. Tailwind &amp; SCSS: generated client-side for handoff.</p>
			</div>
			<div class="ds-tech-col">
				<header class="ds-tech-head">
					<h3 class="ds-tech-title">Canonical JSON</h3>
					<button type="button" class="ds-tech-copy" data-ds-copy="json">Copy</button>
				</header>
				<pre class="ds-tech-pre" data-ds-json-pre><code><?php echo esc_html( wp_json_encode( $system, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ); ?></code></pre>
				<p class="ds-tech-note">Source of truth. Adapters subscribe to <code>therum_design_system_saved</code> and re-emit downstream.</p>
				<!-- keep the CSS pre around for legacy ref / live-CSS updates from save -->
				<pre class="ds-tech-pre" data-ds-css-pre hidden><code><?php echo esc_html( $tech_css ); ?></code></pre>
			</div>
		</div>
		<?php
	}

	// ── Bricks readouts ──────────────────────────────────────────────────────

	private static function render_bricks_readouts(): void {
		$palettes = Therum_Design_System_Data::palettes();
		$vars     = Therum_Design_System_Data::variables_by_category();
		$classes  = Therum_Design_System_Data::global_classes();
		?>
		<div class="ds-bricks">

			<div class="ds-bricks-col">
				<div class="ds-bricks-label">Palettes <span><?php echo count( $palettes ); ?></span></div>
				<?php if ( empty( $palettes ) ): ?>
					<div class="ds-bricks-empty">No palettes in Bricks. Save above to push.</div>
				<?php else: foreach ( $palettes as $p ): ?>
					<div class="ds-bricks-palette">
						<div class="ds-bricks-palette-name"><?php echo esc_html( $p['name'] ); ?> <span>(<?php echo count( $p['colors'] ); ?>)</span></div>
						<div class="ds-bricks-swatches">
							<?php foreach ( $p['colors'] as $c ): ?>
								<span class="ds-bricks-swatch" style="background: <?php echo esc_attr( $c['raw'] ); ?>;" title="<?php echo esc_attr( ( $c['name'] ?? '' ) . ' · ' . $c['raw'] ); ?>"></span>
							<?php endforeach; ?>
						</div>
					</div>
				<?php endforeach; endif; ?>
			</div>

			<div class="ds-bricks-col">
				<div class="ds-bricks-label">Variables <span><?php echo array_sum( array_map( 'count', $vars ) ); ?></span></div>
				<?php if ( empty( $vars ) ): ?>
					<div class="ds-bricks-empty">No global variables yet.</div>
				<?php else: foreach ( $vars as $cat => $items ): ?>
					<div class="ds-bricks-var-cat">
						<div class="ds-bricks-var-cat-name"><?php echo esc_html( $cat ); ?> <span>(<?php echo count( $items ); ?>)</span></div>
						<div class="ds-bricks-var-chips">
							<?php foreach ( array_slice( $items, 0, 18 ) as $v ): ?>
								<span class="ds-bricks-var-chip" title="<?php echo esc_attr( $v['value'] ); ?>"><?php echo esc_html( $v['name'] ); ?></span>
							<?php endforeach; ?>
							<?php if ( count( $items ) > 18 ): ?><span class="ds-bricks-var-chip is-more">+<?php echo count( $items ) - 18; ?></span><?php endif; ?>
						</div>
					</div>
				<?php endforeach; endif; ?>
			</div>

			<div class="ds-bricks-col">
				<div class="ds-bricks-label">Global classes <span><?php echo count( $classes ); ?></span></div>
				<?php if ( empty( $classes ) ): ?>
					<div class="ds-bricks-empty">No global classes yet.</div>
				<?php else: ?>
					<div class="ds-bricks-classes">
						<?php foreach ( array_slice( $classes, 0, 30 ) as $c ):
							$prop_count = count( $c['settings'] );
							?>
							<div class="ds-bricks-class">
								<code>.<?php echo esc_html( $c['name'] ?: $c['id'] ); ?></code>
								<span><?php echo $prop_count; ?></span>
							</div>
						<?php endforeach; ?>
						<?php if ( count( $classes ) > 30 ): ?>
							<div class="ds-bricks-class is-more">+<?php echo count( $classes ) - 30; ?> more</div>
						<?php endif; ?>
					</div>
				<?php endif; ?>
			</div>

		</div>
		<?php
	}

	// ── helpers ──────────────────────────────────────────────────────────────

	private static function current_system(): array {
		if ( class_exists( '\\Therum\\DesignSystem\\Store' ) ) {
			return \Therum\DesignSystem\Store::get();
		}
		return [
			'version' => '1',
			'meta'    => [ 'name' => 'Unavailable', 'updated_at' => '' ],
			'colors'  => [], 'fonts' => [], 'sizes' => [],
			'spacing' => [], 'radii' => [], 'shadows' => [],
		];
	}

	private static function index_by_role( array $colors ): array {
		$out = [];
		foreach ( $colors as $c ) {
			$role = (string) ( $c['role'] ?? '' );
			if ( $role !== '' && ! isset( $out[ $role ] ) ) $out[ $role ] = (string) ( $c['value'] ?? '' );
		}
		return $out;
	}

	private static function find_font( array $fonts, string $id ): ?array {
		foreach ( $fonts as $f ) if ( ( $f['id'] ?? '' ) === $id ) return $f;
		return $fonts[0] ?? null;
	}

	private static function find_value( array $rows, string $id, string $fallback ): string {
		foreach ( $rows as $r ) if ( ( $r['id'] ?? '' ) === $id ) return (string) ( $r['value'] ?? $fallback );
		return $fallback;
	}

	private static function font_choices(): array {
		return [
			'system-sans'      => [ 'label' => 'System sans',    'stack' => '-apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif' ],
			'system-serif'     => [ 'label' => 'System serif',   'stack' => 'ui-serif, Georgia, Cambria, "Times New Roman", serif' ],
			'system-mono'      => [ 'label' => 'System mono',    'stack' => 'ui-monospace, SFMono-Regular, Menlo, monospace' ],
			'inter-tight'      => [ 'label' => 'Inter Tight',    'stack' => '"Inter Tight", -apple-system, BlinkMacSystemFont, sans-serif' ],
			'inter'            => [ 'label' => 'Inter',          'stack' => 'Inter, -apple-system, BlinkMacSystemFont, sans-serif' ],
			'instrument'       => [ 'label' => 'Instrument Sans','stack' => '"Instrument Sans", -apple-system, sans-serif' ],
			'space-grotesk'    => [ 'label' => 'Space Grotesk',  'stack' => '"Space Grotesk", -apple-system, sans-serif' ],
			'manrope'          => [ 'label' => 'Manrope',        'stack' => 'Manrope, -apple-system, sans-serif' ],
			'plex-sans'        => [ 'label' => 'IBM Plex Sans',  'stack' => '"IBM Plex Sans", -apple-system, sans-serif' ],
			'fraunces'         => [ 'label' => 'Fraunces',       'stack' => 'Fraunces, Georgia, serif' ],
			'instrument-serif' => [ 'label' => 'Instrument Serif','stack'=> '"Instrument Serif", Georgia, serif' ],
			'jetbrains-mono'   => [ 'label' => 'JetBrains Mono', 'stack' => '"JetBrains Mono", ui-monospace, monospace' ],
		];
	}

	private static function render_font_options( string $current ): void {
		$choices = self::font_choices();
		$matched = false;
		foreach ( $choices as $key => $c ) {
			$is_current = ( trim( $c['stack'] ) === trim( $current ) );
			if ( $is_current ) $matched = true;
			echo '<option value="' . esc_attr( $c['stack'] ) . '"' . ( $is_current ? ' selected' : '' ) . '>' . esc_html( $c['label'] ) . '</option>';
		}
		if ( ! $matched && $current !== '' ) {
			echo '<option value="' . esc_attr( $current ) . '" selected>Custom · ' . esc_html( self::short( $current, 28 ) ) . '</option>';
		}
	}

	public static function contrast_for( string $hex ): string {
		$hex = ltrim( trim( $hex ), '#' );
		if ( strlen( $hex ) === 3 ) $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
		if ( strlen( $hex ) !== 6 || ! ctype_xdigit( $hex ) ) return '#0a0a0a';
		$r = hexdec( substr( $hex, 0, 2 ) ); $g = hexdec( substr( $hex, 2, 2 ) ); $b = hexdec( substr( $hex, 4, 2 ) );
		return ( ( 0.299*$r + 0.587*$g + 0.114*$b ) / 255 ) > 0.55 ? '#0a0a0a' : '#ffffff';
	}

	private static function to_hex_input( string $value ): string {
		$v = trim( $value );
		if ( preg_match( '/^#[0-9a-f]{6}$/i', $v ) ) return $v;
		if ( preg_match( '/^#([0-9a-f])([0-9a-f])([0-9a-f])$/i', $v, $m ) ) return '#' . $m[1].$m[1] . $m[2].$m[2] . $m[3].$m[3];
		return '#000000';
	}

	private static function short( string $s, int $n ): string {
		return mb_strlen( $s ) > $n ? mb_substr( $s, 0, $n - 1 ) . '…' : $s;
	}


	// ════════════════════════════════════════════════════════════════════════
	//  5. STYLES — Pixflow-aligned: flat, restrained, document-like
	// ════════════════════════════════════════════════════════════════════════

	private static function css(): string {
		return <<<'CSS'
/* Therum Design System — v0.4. Editorial cover, stepped palette, big chapter numbers,
 * floating save dock, copy-on-click swatches. Eat-your-own-dogfood: the page chrome
 * uses the user's display font for the cover title + chapter numbers.
 */
.therum-ds, .therum-ds * { box-sizing: border-box; }
.therum-ds { font-feature-settings: "ss01", "cv11"; }
.therum-ds [class*="-mono"], .therum-ds .ds-swatch-hex,
.therum-ds .ds-spec-row b, .therum-ds .ds-specimen-spec,
.therum-ds .ds-spacing-val, .therum-ds .ds-spacing-name,
.therum-ds .ds-radius-val, .therum-ds .ds-radius-name,
.therum-ds .ds-tech-pre, .therum-ds .ds-bricks-var-chip,
.therum-ds .ds-chip { font-feature-settings: "tnum", "lnum"; }

.therum-ds {
	--p-sf:   var(--sf,  #ffffff);
	--p-sf2:  var(--sf2, #f7f7f6);
	--p-bg:   #fbfaf8;            /* page background — slightly warm off-white */
	--p-bd:   var(--bd,  rgba(0,0,0,.09));
	--p-bd2:  var(--bd2, rgba(0,0,0,.18));
	--p-tx:   var(--tx,  #0a0a0a);
	--p-tx2:  var(--tx2, #4a4a4a);
	--p-tx3:  var(--tx3, #8a8a8a);
	--p-ac:   var(--ac,  #e83b3b);
	--p-ff:   var(--ff-d, 'Inter Tight', -apple-system, BlinkMacSystemFont, sans-serif);
	--p-mono: var(--ff-mono, 'JetBrains Mono', ui-monospace, monospace);
	background: var(--p-bg);
	padding: 0 40px 140px;
	max-width: 1320px;
	margin: 0 auto;
	font-family: var(--p-ff);
	color: var(--p-tx);
	-webkit-font-smoothing: antialiased;
	-moz-osx-font-smoothing: grayscale;
}
/* Brand stripe sits flush to edges, ignores page padding. */
.therum-ds .ds-stripe { margin: 0 -40px; }

/* ── Signature brand stripe (top-of-page logo bar) ─────────────────────── */
.ds-stripe { display: grid; grid-template-columns: 3fr 1fr 1fr 1fr; height: 6px; margin: 0 0 0; }
.ds-stripe-cell { display: block; transition: flex 200ms ease; }

/* ── Editorial cover ───────────────────────────────────────────────────── */
.ds-cover { display: grid; grid-template-columns: 1fr 280px; gap: 64px; padding: 64px 0 56px; border-bottom: 1px solid var(--p-bd); align-items: flex-end; }
.ds-cover-main { min-width: 0; }
.ds-cover-eyebrow { font-size: 11px; font-weight: 500; letter-spacing: .18em; text-transform: uppercase; color: var(--p-tx3); margin-bottom: 28px; }
.ds-cover-title { font-size: clamp(56px, 8vw, 112px); font-weight: 500; letter-spacing: -.04em; line-height: .92; margin: 0 0 20px; color: var(--p-tx); outline: none; transition: box-shadow 200ms ease; }
.ds-cover-title[contenteditable="true"]:hover { cursor: text; }
.ds-cover-title[contenteditable="true"]:hover::after { content: ''; }
.ds-cover-title[contenteditable="true"]:focus { box-shadow: inset 0 -3px 0 var(--accent, var(--p-ac)); }
.ds-cover-tag { font-size: 17px; line-height: 1.45; color: var(--p-tx2); margin: 0; max-width: 44ch; font-family: ui-serif, Georgia, 'Times New Roman', serif; }
.ds-cover-tag em { font-style: italic; }

.ds-cover-spec { display: flex; flex-direction: column; gap: 0; border-left: 1px solid var(--p-bd); padding: 4px 0 4px 28px; }
.ds-spec-row { display: flex; justify-content: space-between; align-items: baseline; gap: 16px; padding: 7px 0; border-bottom: 1px solid var(--p-bd); }
.ds-spec-row:last-child { border-bottom: none; }
.ds-spec-row > span { font-size: 11px; color: var(--p-tx3); letter-spacing: .04em; text-transform: uppercase; font-weight: 500; }
.ds-spec-row > b { font-size: 13px; font-weight: 500; color: var(--p-tx); font-family: var(--p-mono); }

/* ── View tabs + reset ─────────────────────────────────────────────────── */
.ds-viewbar { display: flex; justify-content: space-between; align-items: center; gap: 16px; padding: 18px 0; border-bottom: 1px solid var(--p-bd); }
.ds-tab-group { display: inline-flex; gap: 0; }
.ds-tab { display: inline-flex; align-items: baseline; gap: 8px; padding: 8px 16px 8px 0; margin-right: 24px; border: 0; background: transparent; color: var(--p-tx3); font-family: inherit; font-size: 13px; font-weight: 500; cursor: pointer; transition: color 150ms ease; position: relative; }
.ds-tab:hover { color: var(--p-tx2); }
.ds-tab.is-active { color: var(--p-tx); }
.ds-tab.is-active::after { content: ''; position: absolute; left: 0; right: 24px; bottom: -18px; height: 1px; background: var(--p-tx); }
.ds-tab-num { font-family: var(--p-mono); font-size: 11px; opacity: .7; }
.ds-tab-name { font-size: 13px; }

.ds-action { display: inline-flex; align-items: center; padding: 9px 18px; border: 1px solid var(--p-tx); background: var(--p-sf); color: var(--p-tx); font-family: inherit; font-size: 12px; font-weight: 500; cursor: pointer; border-radius: 0; transition: background 150ms ease, color 150ms ease, opacity 150ms ease; line-height: 1.3; }
.ds-action:hover { background: var(--p-tx); color: var(--p-sf); }
.ds-action--primary { background: var(--p-tx); color: var(--p-sf); }
.ds-action--primary:hover { background: #1a1a1a; border-color: #1a1a1a; }
.ds-action--primary:disabled { opacity: .35; cursor: not-allowed; background: var(--p-tx); color: var(--p-sf); }
.ds-action--ghost { background: transparent; border-color: transparent; color: var(--p-tx3); }
.ds-action--ghost:hover { background: transparent; color: var(--p-tx); border-color: var(--p-bd); }
.ds-action--sm { padding: 6px 12px; font-size: 11px; }

/* ── Canvas + bands ────────────────────────────────────────────────────── */
.ds-canvas { background: transparent; }
.ds-view[hidden] { display: none; }
.ds-band { padding: 80px 0; border-bottom: 1px solid var(--p-bd); }
.ds-band:last-child { border-bottom: none; }

/* ── Big chapter heads ─────────────────────────────────────────────────── */
.ds-chapter { display: grid; grid-template-columns: auto 1fr auto; gap: 32px; align-items: end; margin-bottom: 56px; }
.ds-chapter-id { display: flex; align-items: baseline; gap: 24px; min-width: 0; }
.ds-chapter-num { font-family: var(--ff-display, var(--p-ff)); font-size: clamp(72px, 9vw, 112px); font-weight: 500; line-height: .85; letter-spacing: -.04em; color: var(--p-tx); font-variant-numeric: tabular-nums; }
.ds-chapter-title { font-family: var(--p-ff); font-size: 11px; font-weight: 500; letter-spacing: .18em; text-transform: uppercase; color: var(--p-tx3); margin: 0 0 10px; }
.ds-chapter-ctl { display: inline-flex; align-items: center; gap: 16px; flex-wrap: wrap; justify-self: end; }
.ds-chapter-meta { font-size: 11px; color: var(--p-tx3); letter-spacing: .02em; }

/* ── Picker ────────────────────────────────────────────────────────────── */
.ds-picker { display: inline-flex; align-items: center; gap: 8px; }
.ds-picker > span { font-size: 10px; font-weight: 500; letter-spacing: .12em; text-transform: uppercase; color: var(--p-tx3); }
.ds-picker > select { padding: 7px 28px 7px 12px; border: 1px solid var(--p-bd); background: var(--p-sf); color: var(--p-tx); font-family: inherit; font-size: 12px; cursor: pointer; border-radius: 0; appearance: none; background-image: linear-gradient(45deg, transparent 50%, currentColor 50%), linear-gradient(135deg, currentColor 50%, transparent 50%); background-position: calc(100% - 14px) 50%, calc(100% - 10px) 50%; background-size: 4px 4px; background-repeat: no-repeat; transition: border-color 150ms ease; }
.ds-picker > select:hover { border-color: var(--p-tx); }

/* ── 01 Typography specimen ────────────────────────────────────────────── */
.ds-specimen { border-top: 1px solid var(--p-tx); }
.ds-specimen-row { display: grid; grid-template-columns: 220px 1fr; gap: 48px; padding: 36px 0; border-bottom: 1px solid var(--p-bd); align-items: baseline; transition: background 200ms ease; }
.ds-specimen-row:last-child { border-bottom: 1px solid var(--p-tx); }
.ds-specimen-row:hover { background: rgba(0,0,0,.012); }
.ds-specimen-meta { display: flex; flex-direction: column; gap: 6px; }
.ds-specimen-label { font-size: 13px; font-weight: 500; color: var(--p-tx); }
.ds-specimen-spec { font-family: var(--p-mono); font-size: 11px; color: var(--p-tx3); letter-spacing: .02em; }
.ds-specimen-sample { color: var(--p-tx); min-width: 0; overflow-wrap: anywhere; }

/* Type scale — sizes from system.sizes */
.ds-typescale { margin-top: 40px; padding-top: 28px; border-top: 1px solid var(--p-bd); }
.ds-typescale-head { display: flex; justify-content: space-between; align-items: baseline; gap: 16px; margin-bottom: 24px; }
.ds-typescale-label { font-size: 11px; font-weight: 500; letter-spacing: .18em; text-transform: uppercase; color: var(--p-tx); }
.ds-typescale-meta { font-size: 11px; color: var(--p-tx3); font-family: ui-serif, Georgia, serif; font-style: italic; }
.ds-typescale-row { display: flex; align-items: flex-end; gap: 8px; flex-wrap: wrap; }
.ds-typescale-item { position: relative; display: flex; flex-direction: column; align-items: center; gap: 10px; padding: 14px 16px 12px; border: 1px solid transparent; transition: border-color 150ms ease, background 150ms ease; min-width: 72px; }
.ds-typescale-item:hover { border-color: var(--p-bd); background: var(--p-sf); }
.ds-typescale-sample { background: transparent; border: 0; padding: 0; cursor: pointer; color: var(--p-tx); font-weight: 500; letter-spacing: -.02em; transition: color 150ms ease; }
.ds-typescale-sample:hover { color: var(--accent, var(--p-ac)); }
.ds-typescale-meta { display: flex; flex-direction: column; align-items: center; gap: 2px; }
.ds-typescale-name { font-family: var(--p-mono); font-size: 11px; color: var(--p-tx); }
.ds-typescale-val { font-family: var(--p-mono); font-size: 10px; color: var(--p-tx3); font-variant-numeric: tabular-nums; }

/* Token add/remove controls (used by colors, sizes, spacing, radii, shadows) */
.ds-token-rm { position: absolute; top: 4px; right: 4px; width: 18px; height: 18px; padding: 0; display: grid; place-items: center; border: 0; background: var(--p-tx); color: var(--p-sf); border-radius: 50%; font-size: 12px; line-height: 1; cursor: pointer; opacity: 0; transform: scale(.85); transition: opacity 150ms ease, transform 150ms ease; z-index: 4; }
.ds-color:hover .ds-token-rm, .ds-typescale-item:hover .ds-token-rm, .ds-spacing-item:hover .ds-token-rm, .ds-radius-item:hover .ds-token-rm, .ds-shadow-item:hover .ds-token-rm { opacity: 1; transform: scale(1); }
.ds-token-rm:hover { background: #c53030; }

.ds-token-add { display: inline-flex; flex-direction: column; align-items: center; justify-content: center; gap: 8px; padding: 24px; min-width: 80px; border: 1px dashed var(--p-bd); background: transparent; color: var(--p-tx3); font-family: inherit; cursor: pointer; transition: border-color 150ms ease, color 150ms ease, background 150ms ease; }
.ds-token-add:hover { border-color: var(--p-tx); color: var(--p-tx); background: var(--p-sf); }
.ds-token-add-icon { font-size: 20px; line-height: 1; font-weight: 300; }
.ds-token-add-label { font-size: 11px; font-weight: 500; letter-spacing: .04em; }
/* In the color grid the add tile takes one full card slot */
.ds-color-add { aspect-ratio: 4 / 3; padding: 0; min-width: 0; min-height: 220px; border-radius: 10px; }
.ds-color-add .ds-token-add-icon { font-size: 28px; }

/* Inline editor for add-new-token (sizes/spacing/radii/shadows) */
.ds-token-form { display: flex; flex-direction: column; gap: 6px; padding: 14px; min-width: 200px; border: 1px solid var(--p-tx); background: var(--p-sf); }
.ds-token-form input { width: 100%; padding: 6px 10px; border: 1px solid var(--p-bd); background: var(--p-sf); color: var(--p-tx); font-family: var(--p-mono); font-size: 12px; outline: none; }
.ds-token-form input:focus { border-color: var(--p-tx); }
.ds-token-form-row { display: flex; gap: 6px; }
.ds-token-form-row button { flex: 1; padding: 6px 10px; border: 1px solid var(--p-tx); background: var(--p-tx); color: var(--p-sf); font-family: inherit; font-size: 11px; font-weight: 500; cursor: pointer; }
.ds-token-form-row button.cancel { background: transparent; color: var(--p-tx); }

/* ── 02 Palette — color cards ──────────────────────────────────────────── */
.ds-palette { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 18px; }
.ds-color { position: relative; background: var(--p-sf); border: 1px solid var(--p-bd); border-radius: 10px; overflow: hidden; transition: transform 200ms cubic-bezier(.2,.7,.2,1), box-shadow 200ms ease, border-color 200ms ease; display: flex; flex-direction: column; }
.ds-color:hover { transform: translateY(-2px); border-color: var(--p-bd2); box-shadow: 0 14px 32px rgba(0,0,0,.07), 0 2px 6px rgba(0,0,0,.04); }
.ds-color-fill { position: relative; display: block; aspect-ratio: 4 / 3; cursor: pointer; overflow: hidden; }
.ds-color-input { position: absolute; inset: 0; opacity: 0; cursor: pointer; border: 0; padding: 0; width: 100%; height: 100%; z-index: 2; }
.ds-color-role { position: absolute; top: 14px; left: 14px; font-family: var(--p-ff); font-size: 9px; font-weight: 500; letter-spacing: .14em; text-transform: uppercase; color: inherit; opacity: .78; z-index: 1; }
.ds-color-edit-cue { position: absolute; bottom: 14px; left: 14px; font-family: var(--p-ff); font-size: 10px; font-weight: 500; letter-spacing: .04em; color: inherit; opacity: 0; transition: opacity 200ms ease; z-index: 1; pointer-events: none; }
.ds-color:hover .ds-color-edit-cue { opacity: .65; }
.ds-color-meta { padding: 14px 16px 14px; border-top: 1px solid var(--p-bd); display: flex; flex-direction: column; gap: 6px; }
.ds-color-name { font-size: 13px; font-weight: 500; color: var(--p-tx); line-height: 1.2; }
.ds-color-bottom { display: flex; justify-content: space-between; align-items: center; gap: 8px; }
.ds-color-hex { font-family: var(--p-mono); font-size: 12px; color: var(--p-tx2); font-variant-numeric: tabular-nums; text-transform: lowercase; }
.ds-color-copy { display: inline-flex; align-items: center; justify-content: center; width: 26px; height: 26px; padding: 0; border: 1px solid var(--p-bd); background: var(--p-sf); color: var(--p-tx3); cursor: pointer; border-radius: 6px; transition: background 150ms ease, color 150ms ease, border-color 150ms ease; }
.ds-color-copy:hover { background: var(--p-tx); color: var(--p-sf); border-color: var(--p-tx); }
.ds-color-copy:active { transform: scale(.94); }

/* ── 03 Components ─────────────────────────────────────────────────────── */
.ds-comp-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 1px; background: var(--p-bd); border: 1px solid var(--p-tx); }
.ds-comp { background: var(--p-sf); padding: 32px 32px 36px; min-height: 240px; display: flex; flex-direction: column; }
.ds-comp-label { font-size: 10px; font-weight: 500; letter-spacing: .18em; text-transform: uppercase; color: var(--p-tx3); margin-bottom: 28px; padding-bottom: 14px; border-bottom: 1px solid var(--p-bd); }
.ds-comp-stage { display: flex; flex-direction: column; gap: 18px; font-family: var(--ff-body); color: var(--tx); flex: 1; }
.ds-comp-row { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; }

/* component primitives — use the canvas tokens so they retint live */
.ds-btn { padding: 11px 20px; border-radius: var(--r-md, 8px); border: 1px solid transparent; font-size: 13px; font-weight: 500; cursor: pointer; font-family: inherit; transition: filter 150ms ease, background 150ms ease, transform 100ms ease; line-height: 1.2; }
.ds-btn:hover { filter: brightness(1.06); }
.ds-btn:active { transform: translateY(1px); }
.ds-btn--sm { padding: 7px 13px; font-size: 12px; }
.ds-btn--primary { background: var(--primary); color: var(--bg); }
.ds-btn--accent  { background: var(--accent);  color: #fff; }
.ds-btn--ghost   { background: transparent; color: var(--tx); border-color: var(--bd); }
.ds-btn--ghost:hover { background: var(--bg2); filter: none; }

.ds-field { display: flex; flex-direction: column; gap: 5px; }
.ds-field > span { font-size: 11px; color: var(--tx2); font-weight: 500; }
.ds-input { padding: 10px 14px; border: 1px solid var(--bd); border-radius: var(--r-md, 8px); background: var(--bg); color: var(--tx); font-family: inherit; font-size: 13px; outline: none; transition: border-color 150ms ease; width: 100%; }
.ds-input:focus { border-color: var(--accent); }
.ds-check { display: inline-flex; gap: 8px; align-items: center; font-size: 13px; color: var(--tx); }
.ds-check input { accent-color: var(--accent); }

.ds-card { background: var(--bg2); border: 1px solid var(--bd); border-radius: var(--r-md, 8px); overflow: hidden; max-width: 360px; transition: transform 200ms cubic-bezier(.2,.7,.2,1), box-shadow 200ms ease; }
.ds-card:hover { transform: translateY(-2px); box-shadow: 0 12px 32px rgba(0,0,0,.08); }
.ds-card-thumb { aspect-ratio: 16/9; background: linear-gradient(135deg, var(--accent), color-mix(in srgb, var(--accent) 40%, var(--primary))); position: relative; }
.ds-card-thumb::after { content: ''; position: absolute; inset: 0; background: radial-gradient(circle at 28% 72%, rgba(255,255,255,.18), transparent 65%); }
.ds-card-body { padding: 18px 20px 20px; color: var(--tx); }
.ds-card-eyebrow { font-size: 10px; font-weight: 500; letter-spacing: .12em; text-transform: uppercase; color: var(--accent); margin-bottom: 8px; }
.ds-card-title { font-family: var(--ff-display); font-size: 18px; font-weight: 500; letter-spacing: -.015em; margin: 0 0 8px; color: var(--tx); }
.ds-card-desc { font-size: 13px; line-height: 1.55; color: var(--tx2); margin: 0; }

.ds-badge { display: inline-flex; align-items: center; padding: 5px 11px; border-radius: 999px; font-size: 11px; font-weight: 500; letter-spacing: .01em; line-height: 1; }
.ds-badge--primary { background: var(--primary); color: var(--bg); }
.ds-badge--accent  { background: color-mix(in srgb, var(--accent) 14%, transparent); color: var(--accent); }
.ds-badge--ghost   { background: var(--bg2); color: var(--tx2); border: 1px solid var(--bd); }
.ds-chip { display: inline-flex; padding: 5px 11px; border: 1px solid var(--bd); border-radius: var(--r-md, 8px); font-family: var(--p-mono); font-size: 11px; color: var(--tx2); }

/* component label gets a mono class name on the right */
.ds-comp-label { display: flex; justify-content: space-between; align-items: baseline; gap: 12px; }
.ds-comp-class { font-family: var(--p-mono); font-size: 10px; font-weight: 400; color: var(--p-tx3); letter-spacing: 0; text-transform: lowercase; }
.ds-comp-stage--media { padding: 0; }

/* ── Case study component: Device frame (browser chrome) ──────────────── */
.ds-cs-browser { margin: 0; border: 1px solid var(--bd); border-radius: 10px; overflow: hidden; background: var(--bg); }
.ds-cs-browser-bar { display: flex; align-items: center; gap: 6px; height: 28px; background: #232323; padding: 0 12px; border-bottom: 1px solid #0e0e0e; }
.ds-cs-dot { display: inline-block; width: 9px; height: 9px; border-radius: 50%; }
.ds-cs-browser-url { margin-left: 14px; font-family: var(--p-mono); font-size: 10px; color: rgba(255,255,255,.55); letter-spacing: 0; }
.ds-cs-browser-canvas { padding: 32px 28px; min-height: 160px; display: flex; flex-direction: column; justify-content: center; gap: 8px; background: var(--bg); color: var(--tx); }
.ds-cs-browser-display { font-size: 28px; font-weight: 500; letter-spacing: -.025em; line-height: 1; }
.ds-cs-browser-sub { font-size: 13px; color: var(--tx2); line-height: 1.45; }
.ds-cs-browser-cta { margin-top: 8px; }

/* ── Case study component: Scroll plate ──────────────────────────────── */
.ds-cs-plate { margin: 0; }
.ds-cs-plate-window { position: relative; border: 1px solid var(--bd); border-radius: 10px; background: var(--bg2); height: 180px; overflow: hidden; }
.ds-cs-plate-strip { position: absolute; inset: 0; overflow-y: auto; scroll-behavior: smooth; padding: 0; scrollbar-width: thin; scrollbar-color: var(--accent) transparent; }
.ds-cs-plate-strip::-webkit-scrollbar { width: 4px; }
.ds-cs-plate-strip::-webkit-scrollbar-thumb { background: var(--accent); border-radius: 2px; }
.ds-cs-plate-row { padding: 14px 18px; font-size: 16px; border-bottom: 1px solid var(--bd); }
.ds-cs-plate-row:last-child { border-bottom: none; }
.ds-cs-plate-row--alt { background: var(--bg); font-size: 13px; line-height: 1.5; }
.ds-cs-plate-caption { margin-top: 10px; font-size: 11px; color: var(--tx3); font-family: ui-serif, Georgia, serif; font-style: italic; }
.ds-cs-plate-window:hover .ds-cs-plate-strip { /* scroll cue via the affordance — actual scroll-on-hover would need JS */ }

/* ── Case study component: Brand block ───────────────────────────────── */
.ds-cs-brand { margin: 0; }
.ds-cs-brand-head { display: flex; flex-direction: column; gap: 6px; margin-bottom: 14px; }
.ds-cs-brand-kicker { font-size: 10px; font-weight: 500; letter-spacing: .12em; text-transform: uppercase; color: var(--tx3); }
.ds-cs-brand-name { font-size: 22px; font-weight: 500; letter-spacing: -.025em; margin: 0; color: var(--tx); }
.ds-cs-brand-chips { display: flex; gap: 6px; flex-wrap: wrap; margin-top: 4px; }
.ds-cs-brand-chip { display: inline-flex; padding: 3px 9px; border: 1px solid var(--bd); border-radius: 999px; font-size: 10px; color: var(--tx2); }
.ds-cs-brand-tile { border: 1px solid var(--bd); border-radius: 8px; overflow: hidden; background: var(--bg); }
.ds-cs-brand-stripe { display: grid; grid-template-columns: 1.6fr 1fr 1fr 1fr; height: 56px; }
.ds-cs-brand-stripe > span { display: block; box-shadow: inset 0 0 0 1px rgba(0,0,0,.04); }
.ds-cs-brand-spec { display: flex; gap: 18px; align-items: center; padding: 16px 18px; border-top: 1px solid var(--bd); }
.ds-cs-brand-type { font-size: 40px; font-weight: 500; letter-spacing: -.04em; color: var(--tx); line-height: 1; }
.ds-cs-brand-meta { font-size: 11px; color: var(--tx2); line-height: 1.55; }
.ds-cs-brand-meta em { font-style: italic; color: var(--tx); }

/* ── Case study component: Architecture stack ───────────────────────── */
.ds-cs-archstack { display: grid; grid-template-columns: 1fr; gap: 1px; background: var(--bd); border: 1px solid var(--bd); border-radius: 6px; overflow: hidden; }
.ds-cs-archstack-layer { display: grid; grid-template-columns: 56px 1fr; background: var(--bg); padding: 14px 18px; gap: 16px; align-items: center; }
.ds-cs-archstack-num { font-size: 22px; font-weight: 500; letter-spacing: -.02em; color: var(--accent); line-height: 1; }
.ds-cs-archstack-body { display: flex; flex-direction: column; gap: 2px; }
.ds-cs-archstack-name { font-size: 14px; font-weight: 500; letter-spacing: -.01em; color: var(--tx); }
.ds-cs-archstack-tech { font-family: var(--p-mono); font-size: 11px; color: var(--tx3); }

/* ── Primitives strip (compact recap of buttons/form/badges) ─────────── */
.ds-prim-strip { margin-top: 36px; padding-top: 28px; border-top: 1px solid var(--p-bd); display: flex; flex-direction: column; gap: 14px; }
.ds-prim-strip-label { font-size: 10px; font-weight: 500; letter-spacing: .18em; text-transform: uppercase; color: var(--p-tx3); }
.ds-prim-strip-row { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; }
.ds-prim-sep { width: 1px; height: 22px; background: var(--p-bd); margin: 0 4px; }
.ds-input--inline { padding: 8px 12px; min-width: 220px; max-width: 280px; }

/* ── 04 Scale (dynamic — click-to-copy, applied previews, hover-to-update) ─ */
.ds-scale { display: flex; flex-direction: column; gap: 56px; padding-top: 8px; }
.ds-scale-group { display: flex; flex-direction: column; gap: 24px; }
.ds-scale-group-head { display: flex; justify-content: space-between; align-items: baseline; gap: 16px; padding-bottom: 14px; border-bottom: 1px solid var(--p-bd); flex-wrap: wrap; }
.ds-scale-group-label { font-size: 11px; font-weight: 500; letter-spacing: .18em; text-transform: uppercase; color: var(--p-tx); margin: 0; }
.ds-scale-group-meta { font-size: 11px; color: var(--p-tx3); font-family: ui-serif, Georgia, serif; font-style: italic; }

/* Spacing — clickable bars + in-context demo */
.ds-spacing-row { display: flex; align-items: flex-end; gap: 12px; flex-wrap: wrap; }
.ds-spacing-item { position: relative; display: flex; flex-direction: column; align-items: center; min-width: 56px; border: 1px solid transparent; background: transparent; transition: border-color 150ms ease, background 150ms ease; }
.ds-spacing-item:hover, .ds-spacing-item.is-active { border-color: var(--p-bd); background: var(--p-sf); }
.ds-spacing-bar-btn { display: flex; flex-direction: column; align-items: center; gap: 12px; padding: 14px 8px 12px; border: 0; background: transparent; cursor: pointer; font-family: inherit; color: inherit; width: 100%; }
.ds-spacing-bar { width: 28px; background: var(--p-tx); transition: filter 150ms ease, background 150ms ease; min-height: 4px; }
.ds-spacing-item:hover .ds-spacing-bar, .ds-spacing-item.is-active .ds-spacing-bar { background: var(--accent, var(--p-ac)); }
.ds-spacing-meta { display: flex; flex-direction: column; align-items: center; gap: 2px; }
.ds-spacing-name { font-family: var(--p-mono); font-size: 11px; color: var(--p-tx); font-variant-numeric: tabular-nums; }
.ds-spacing-val { font-family: var(--p-mono); font-size: 10px; color: var(--p-tx3); font-variant-numeric: tabular-nums; }

.ds-spacing-demo { display: grid; grid-template-columns: 1fr auto; align-items: center; gap: 24px; padding: 20px 24px; background: var(--p-sf); border: 1px solid var(--p-bd); }
.ds-spacing-demo-stage { display: flex; align-items: center; gap: 16px; transition: gap 220ms cubic-bezier(.2,.7,.2,1); }
.ds-spacing-demo-box { display: inline-block; width: 36px; height: 36px; background: var(--p-tx); }
.ds-spacing-demo-box:nth-child(2) { background: var(--accent, var(--p-ac)); }
.ds-spacing-demo-box:nth-child(3) { background: var(--p-tx2); }
.ds-spacing-demo-box:nth-child(4) { background: var(--p-tx3); }
.ds-spacing-demo-ctl { display: flex; flex-direction: column; gap: 4px; align-items: flex-end; }
.ds-spacing-demo-label { font-size: 11px; color: var(--p-tx3); }
.ds-spacing-demo-label code { font-family: var(--p-mono); background: var(--p-sf2); padding: 1px 5px; }
.ds-spacing-demo-current { font-family: var(--p-mono); font-size: 16px; font-weight: 500; color: var(--p-tx); font-variant-numeric: tabular-nums; }

/* Radii — applied to mini-cards */
.ds-radii-row { display: flex; gap: 16px; flex-wrap: wrap; align-items: flex-end; }
.ds-radius-item { position: relative; display: flex; flex-direction: column; align-items: center; border: 1px solid transparent; background: transparent; transition: border-color 150ms ease, background 150ms ease; }
.ds-radius-item:hover { border-color: var(--p-bd); background: var(--p-sf); }
.ds-radius-btn { display: flex; flex-direction: column; align-items: center; gap: 12px; padding: 8px; border: 0; background: transparent; cursor: pointer; font-family: inherit; color: inherit; }
.ds-radius-card { width: 64px; height: 64px; display: grid; place-items: center; transition: transform 180ms cubic-bezier(.2,.7,.2,1); }
.ds-radius-item:hover .ds-radius-card { transform: scale(1.04); }
.ds-radius-card-mark { font-size: 22px; font-weight: 500; opacity: .7; }
.ds-radius-meta { display: flex; flex-direction: column; align-items: center; gap: 2px; }
.ds-radius-name { font-family: var(--p-mono); font-size: 11px; color: var(--p-tx); }
.ds-radius-val { font-family: var(--p-mono); font-size: 10px; color: var(--p-tx3); font-variant-numeric: tabular-nums; }

/* Shadows — applied to surface tiles */
.ds-shadows-row { display: flex; gap: 20px; flex-wrap: wrap; padding: 16px 0; align-items: flex-end; }
.ds-shadow-item { position: relative; display: flex; flex-direction: column; align-items: center; border: 1px solid transparent; background: transparent; transition: border-color 150ms ease; }
.ds-shadow-item:hover { border-color: var(--p-bd); }
.ds-shadow-btn { display: flex; flex-direction: column; align-items: center; gap: 14px; padding: 16px 10px; border: 0; background: transparent; cursor: pointer; font-family: inherit; color: inherit; }
.ds-shadow-card { width: 96px; height: 56px; background: var(--p-sf); border: 1px solid var(--p-bd); transition: transform 180ms cubic-bezier(.2,.7,.2,1); }
.ds-shadow-item:hover .ds-shadow-card { transform: translateY(-2px); }
.ds-shadow-meta { display: flex; flex-direction: column; align-items: center; gap: 2px; max-width: 140px; }
.ds-shadow-name { font-family: var(--p-mono); font-size: 11px; color: var(--p-tx); }
.ds-shadow-val { font-family: var(--p-mono); font-size: 10px; color: var(--p-tx3); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 140px; }

/* ── Tech view ─────────────────────────────────────────────────────────── */
.ds-tech { display: grid; grid-template-columns: 1fr 1fr; gap: 32px; padding: 56px 0; }
.ds-tech-col { display: flex; flex-direction: column; gap: 14px; min-width: 0; }
.ds-tech-head { display: flex; justify-content: space-between; align-items: center; gap: 8px; padding-bottom: 8px; border-bottom: 1px solid var(--p-bd); }
.ds-tech-title { font-size: 11px; font-weight: 500; color: var(--p-tx); margin: 0; text-transform: uppercase; letter-spacing: .18em; }
.ds-tech-copy { padding: 6px 13px; border: 1px solid var(--p-bd); background: var(--p-sf); color: var(--p-tx2); font-family: inherit; font-size: 11px; font-weight: 500; cursor: pointer; transition: background 150ms ease, color 150ms ease, border-color 150ms ease; }
.ds-tech-copy:hover { background: var(--p-tx); color: var(--p-sf); border-color: var(--p-tx); }
.ds-tech-format { display: inline-flex; gap: 2px; padding: 2px; background: var(--p-sf2); border: 1px solid var(--p-bd); margin-right: auto; margin-left: 16px; }
.ds-tech-format-btn { padding: 5px 12px; border: 0; background: transparent; color: var(--p-tx2); font-family: var(--p-mono); font-size: 11px; cursor: pointer; transition: background 150ms ease, color 150ms ease; }
.ds-tech-format-btn:hover { color: var(--p-tx); }
.ds-tech-format-btn.is-active { background: var(--p-tx); color: var(--p-sf); }
.ds-tech-pre { background: #0d1117; border: 0; padding: 24px 26px; margin: 0; max-height: 520px; overflow: auto; font-family: var(--p-mono); font-size: 12px; line-height: 1.75; color: #d2dae6; font-variant-numeric: tabular-nums; }
.ds-tech-pre code { font-family: inherit; color: inherit; }
.ds-tech-note { font-size: 12px; color: var(--p-tx3); line-height: 1.55; margin: 0; font-family: ui-serif, Georgia, serif; font-style: italic; }
.ds-tech-note code { font-family: var(--p-mono); font-size: 11px; background: var(--p-sf2); padding: 1px 6px; font-style: normal; }

/* ── Disclosure (Bricks readouts) ──────────────────────────────────────── */
.ds-disclosure { margin-top: 56px; border-top: 1px solid var(--p-bd); padding-top: 36px; }
.ds-disclosure > summary { cursor: pointer; list-style: none; display: flex; justify-content: space-between; align-items: baseline; gap: 16px; padding: 10px 0; }
.ds-disclosure > summary::-webkit-details-marker { display: none; }
.ds-disclosure > summary > span:first-child { font-size: 14px; font-weight: 500; color: var(--p-tx); }
.ds-disclosure > summary > span:first-child::before { content: '+'; color: var(--p-tx3); margin-right: 10px; font-family: var(--p-mono); }
.ds-disclosure[open] > summary > span:first-child::before { content: '−'; }
.ds-disclosure-meta { font-size: 11px; color: var(--p-tx3); }
.ds-disclosure-body { padding: 28px 0 8px; }

/* ── Bricks readouts ──────────────────────────────────────────────────── */
.ds-bricks { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 32px; }
.ds-bricks-col { min-width: 0; }
.ds-bricks-label { font-size: 10px; font-weight: 500; letter-spacing: .18em; text-transform: uppercase; color: var(--p-tx3); margin-bottom: 16px; padding-bottom: 10px; border-bottom: 1px solid var(--p-tx); }
.ds-bricks-label span { color: var(--p-tx); margin-left: 8px; font-family: var(--p-mono); }
.ds-bricks-empty { font-size: 12px; color: var(--p-tx3); padding: 16px 0; font-style: italic; }
.ds-bricks-palette { margin-bottom: 16px; }
.ds-bricks-palette-name { font-size: 12px; color: var(--p-tx); margin-bottom: 8px; }
.ds-bricks-palette-name span { color: var(--p-tx3); }
.ds-bricks-swatches { display: flex; gap: 4px; flex-wrap: wrap; }
.ds-bricks-swatch { width: 22px; height: 22px; border: 1px solid var(--p-bd); }
.ds-bricks-var-cat { margin-bottom: 16px; }
.ds-bricks-var-cat-name { font-size: 11px; color: var(--p-tx2); margin-bottom: 8px; text-transform: capitalize; }
.ds-bricks-var-cat-name span { color: var(--p-tx3); }
.ds-bricks-var-chips { display: flex; gap: 4px; flex-wrap: wrap; }
.ds-bricks-var-chip { display: inline-block; padding: 3px 8px; background: var(--p-sf2); border: 1px solid var(--p-bd); font-family: var(--p-mono); font-size: 10px; color: var(--p-tx); }
.ds-bricks-var-chip.is-more { color: var(--p-tx3); }
.ds-bricks-classes { display: flex; flex-direction: column; gap: 0; }
.ds-bricks-class { display: flex; justify-content: space-between; padding: 7px 0; border-bottom: 1px solid var(--p-bd); font-size: 11px; }
.ds-bricks-class code { font-family: var(--p-mono); color: var(--p-tx); }
.ds-bricks-class span { color: var(--p-tx3); font-family: var(--p-mono); }
.ds-bricks-class.is-more { color: var(--p-tx3); justify-content: center; }

/* ── Floating save dock (appears when dirty) ──────────────────────────── */
.ds-dock { position: fixed; bottom: 28px; right: 28px; left: 28px; max-width: 520px; margin-left: auto; display: flex; align-items: center; gap: 14px; padding: 14px 18px; background: var(--p-tx); color: var(--p-sf); z-index: 9999; box-shadow: 0 24px 48px rgba(0,0,0,.18), 0 4px 12px rgba(0,0,0,.10); animation: dsDockIn 220ms cubic-bezier(.2,.7,.2,1); }
.ds-dock[hidden] { display: none; }
.ds-dock-msg { flex: 1; font-size: 13px; font-weight: 500; }
.ds-dock .ds-action { border-color: rgba(255,255,255,.16); background: transparent; color: var(--p-sf); }
.ds-dock .ds-action:hover { background: rgba(255,255,255,.10); color: var(--p-sf); border-color: rgba(255,255,255,.32); }
.ds-dock .ds-action--primary { background: var(--p-sf); color: var(--p-tx); border-color: var(--p-sf); }
.ds-dock .ds-action--primary:hover { background: #f0f0f0; color: var(--p-tx); border-color: #f0f0f0; }
.ds-dock .ds-action--ghost { color: rgba(255,255,255,.7); border-color: transparent; }
.ds-dock .ds-action--ghost:hover { background: rgba(255,255,255,.08); color: var(--p-sf); border-color: transparent; }

@keyframes dsDockIn { from { transform: translateY(20px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }

/* ── Toast (copy feedback) ─────────────────────────────────────────────── */
.ds-toast { position: fixed; bottom: 28px; left: 50%; transform: translateX(-50%); padding: 10px 16px; background: var(--p-tx); color: var(--p-sf); font-size: 12px; font-weight: 500; z-index: 10000; animation: dsToastIn 180ms ease-out; }
.ds-toast[hidden] { display: none; }
@keyframes dsToastIn { from { transform: translate(-50%, 12px); opacity: 0; } to { transform: translate(-50%, 0); opacity: 1; } }

/* ── Responsive ────────────────────────────────────────────────────────── */
@media (max-width: 1100px) {
	.ds-cover { grid-template-columns: 1fr; gap: 40px; }
	.ds-cover-spec { border-left: none; border-top: 1px solid var(--p-bd); padding: 28px 0 0; flex-direction: row; flex-wrap: wrap; gap: 16px 28px; }
	.ds-spec-row { border-bottom: none; padding: 0; flex-direction: column; align-items: flex-start; gap: 2px; }
}
@media (max-width: 900px) {
	.ds-chapter { grid-template-columns: 1fr; gap: 18px; }
	.ds-chapter-ctl { justify-self: start; }
	.ds-comp-grid { grid-template-columns: 1fr; }
	.ds-scale { grid-template-columns: 1fr; gap: 48px; }
	.ds-tech { grid-template-columns: 1fr; }
	.ds-bricks { grid-template-columns: 1fr; }
}
@media (max-width: 600px) {
	.ds-palette { grid-template-columns: repeat(2, 1fr); gap: 12px; }
	.ds-specimen-row { grid-template-columns: 1fr; gap: 8px; padding: 24px 0; }
	.ds-band { padding: 56px 0; }
	.ds-cover-title { font-size: 48px; }
	.ds-dock { left: 16px; right: 16px; bottom: 16px; }
}

/* ── New front-end component previews ───────────────────────────────────
   Compact, token-driven illustrations of components that live on the
   bamleon front-end. They read in the same tile as the case-study
   primitives so the Components section shows the whole vocabulary. */

/* Device cycler — three frames stacked, the "active" one shows above */
.therum-ds .ds-cs-cycle { display: flex; flex-direction: column; align-items: center; gap: 14px; padding: 22px 0 6px; }
.therum-ds .ds-cs-cycle-stage { position: relative; width: 200px; height: 130px; }
.therum-ds .ds-cs-cycle-frame { position: absolute; background: var(--bg, var(--p-sf2)); border: 1.5px solid var(--p-tx); border-radius: 8px; transition: transform .6s cubic-bezier(.32,.72,0,1), opacity .4s ease; }
.therum-ds .ds-cs-cycle-frame.is-phone  { width: 48px; height: 92px; left: 22px; top: 19px; transform: rotate(-6deg); border-radius: 8px; z-index: 1; }
.therum-ds .ds-cs-cycle-frame.is-tablet { width: 88px; height: 116px; left: 56px; top: 7px; transform: rotate(-2deg); border-radius: 10px; z-index: 2; }
.therum-ds .ds-cs-cycle-frame.is-laptop { width: 140px; height: 90px; right: 0; top: 18px; border-radius: 8px 8px 4px 4px; z-index: 3; box-shadow: 0 6px 18px rgba(0,0,0,.08); }
.therum-ds .ds-cs-cycle-frame.is-laptop::after { content: ''; position: absolute; left: -10px; right: -10px; bottom: -6px; height: 5px; background: var(--p-tx); border-radius: 0 0 6px 6px; }
.therum-ds .ds-cs-cycle:hover .ds-cs-cycle-frame.is-phone  { transform: rotate(-10deg) translateY(-4px); }
.therum-ds .ds-cs-cycle:hover .ds-cs-cycle-frame.is-tablet { transform: rotate(-4deg) translateY(-2px); }
.therum-ds .ds-cs-cycle:hover .ds-cs-cycle-frame.is-laptop { transform: translateY(-2px); }
.therum-ds .ds-cs-cycle-dots { display: flex; gap: 6px; }
.therum-ds .ds-cs-cycle-dot { width: 6px; height: 6px; border-radius: 50%; background: var(--p-bd2); }
.therum-ds .ds-cs-cycle-dot.is-on { background: var(--p-ac); }

/* Across devices — laptop + phone, same content, scroll-synced */
.therum-ds .ds-cs-across { display: flex; align-items: flex-end; gap: 14px; padding: 18px 12px; justify-content: center; }
.therum-ds .ds-cs-across-laptop { position: relative; width: 180px; }
.therum-ds .ds-cs-across-laptop-screen { background: var(--bg, var(--p-sf2)); border: 1.5px solid var(--p-tx); border-radius: 6px 6px 2px 2px; padding: 10px; display: flex; flex-direction: column; gap: 6px; min-height: 96px; }
.therum-ds .ds-cs-across-laptop-base { width: calc(100% + 18px); margin-left: -9px; height: 5px; background: var(--p-tx); border-radius: 0 0 6px 6px; }
.therum-ds .ds-cs-across-phone { width: 56px; height: 108px; background: var(--bg, var(--p-sf2)); border: 1.5px solid var(--p-tx); border-radius: 9px; padding: 8px 6px; display: flex; flex-direction: column; gap: 4px; }
.therum-ds .ds-cs-across-line { display: block; height: 4px; background: var(--p-tx3); border-radius: 2px; opacity: .55; }
.therum-ds .ds-cs-across-line.is-strong { opacity: .9; height: 5px; width: 70%; background: var(--p-tx); }
.therum-ds .ds-cs-across-line.is-short  { width: 40%; opacity: .35; }
.therum-ds .ds-cs-across-line.is-img    { height: 22px; opacity: 1; background: linear-gradient(135deg, var(--primary, var(--p-tx)), var(--accent, var(--p-ac))); border-radius: 3px; }
.therum-ds .ds-cs-across-phone .ds-cs-across-line.is-img { height: 18px; }

/* Split section — 50/50 image | text */
.therum-ds .ds-cs-split { display: grid; grid-template-columns: 1fr 1fr; min-height: 130px; border: 1px solid var(--p-bd); border-radius: 10px; overflow: hidden; }
.therum-ds .ds-cs-split-media { background: linear-gradient(135deg, var(--primary, var(--p-tx)), var(--accent, var(--p-ac))); }
.therum-ds .ds-cs-split-body { padding: 18px 16px; display: flex; flex-direction: column; gap: 8px; background: var(--bg, var(--p-sf)); }
.therum-ds .ds-cs-split-kicker { font-size: 12px; font-weight: 600; letter-spacing: -.005em; color: var(--p-tx); margin-bottom: 4px; }
.therum-ds .ds-cs-split-line { display: block; height: 4px; background: var(--p-tx3); border-radius: 2px; opacity: .55; }
.therum-ds .ds-cs-split-line.is-strong { opacity: .9; width: 80%; background: var(--p-tx); }
.therum-ds .ds-cs-split-line.is-short  { width: 50%; opacity: .35; }

/* Spec strip — numbered milestones row */
.therum-ds .ds-cs-spec-strip { display: grid; grid-template-columns: repeat(4, 1fr); gap: 0; border: 1px solid var(--p-bd); border-radius: 10px; overflow: hidden; background: var(--bg, var(--p-sf)); }
.therum-ds .ds-cs-spec-cell { padding: 16px 14px; border-right: 1px solid var(--p-bd); display: flex; flex-direction: column; gap: 4px; }
.therum-ds .ds-cs-spec-cell:last-child { border-right: 0; }
.therum-ds .ds-cs-spec-num { font-size: 26px; font-weight: 500; letter-spacing: -.02em; color: var(--p-tx); line-height: 1; }
.therum-ds .ds-cs-spec-l { font-size: 11px; font-weight: 500; color: var(--p-tx3); letter-spacing: .02em; text-transform: uppercase; }

/* ── Bento polish — subtle, not invasive ─────────────────────────────────
   Wraps each major DS band in a softly-rounded card surface so the page
   reads as a stack of bento tiles instead of one long scroll. Section
   spacing and content remain untouched. */
.therum-ds .ds-band{
	background: var(--p-sf);
	border: 1px solid var(--p-bd);
	border-radius: 18px;
	margin: 18px 0;
	padding: 48px 40px;
	box-shadow: 0 1px 0 rgba(0,0,0,.02), 0 8px 24px rgba(0,0,0,.025);
}
.therum-ds .ds-band + .ds-band{ margin-top: 18px }
.therum-ds .ds-cover{ background: var(--p-sf); border: 1px solid var(--p-bd); border-radius: 18px; padding: 56px 40px 48px; margin-top: 18px }
.therum-ds .ds-tech-col{ background: var(--p-sf); border: 1px solid var(--p-bd); border-radius: 14px; padding: 22px 22px 18px }
.therum-ds .ds-tech-pre{ border-radius: 10px }

/* Download / PDF buttons sit next to Copy in the export header */
.therum-ds .ds-tech-head{ gap: 8px; flex-wrap: wrap }
.therum-ds .ds-tech-copy[data-ds-pdf]{ border-color: var(--p-ac); color: var(--p-ac) }
.therum-ds .ds-tech-copy[data-ds-pdf]:hover{ background: var(--p-ac); color: #fff }

/* ── Print stylesheet — Save as PDF from any browser ──────────────────── */
@media print{
	body.ds-printing #wpadminbar,
	body.ds-printing #th-sb,
	body.ds-printing #th-top,
	body.ds-printing .ds-dock,
	body.ds-printing .ds-tech,
	body.ds-printing .ds-bricks,
	body.ds-printing .notice,
	body.ds-printing .updated,
	body.ds-printing .error{ display: none !important }
	body.ds-printing #wpbody-content{ padding: 0 !important }
	body.ds-printing .therum-ds{ background: #fff !important; padding: 0 24px 24px !important; max-width: none !important }
	body.ds-printing .therum-ds .ds-band,
	body.ds-printing .therum-ds .ds-cover{
		box-shadow: none !important;
		border-radius: 0 !important;
		border: 0 !important;
		page-break-inside: avoid;
		break-inside: avoid;
		margin: 0 0 24px !important;
		padding: 24px 0 !important;
	}
	body.ds-printing .ds-cover-title{ font-size: 72px !important }
	@page{ margin: 18mm }
}
CSS;
	}

	private static function js(): string {
		return <<<'JS'
(function() {
	const canvasHost = document.querySelector('[data-ds-system]');
	if (!canvasHost) return;
	const canvas = document.querySelector('.ds-canvas');

	// Snapshot the originally-loaded system so Discard can restore it.
	let system   = JSON.parse(canvasHost.getAttribute('data-ds-system') || '{}');
	let original = JSON.parse(canvasHost.getAttribute('data-ds-system') || '{}');
	let dirty = false;

	const dock        = document.querySelector('[data-ds-dock]');
	const toast       = document.querySelector('[data-ds-toast]');
	const stripeCells = document.querySelectorAll('.ds-stripe-cell');

	function showToast(msg) {
		if (!toast) return;
		toast.textContent = msg;
		toast.hidden = false;
		clearTimeout(toast._t);
		toast._t = setTimeout(() => { toast.hidden = true; }, 1400);
	}

	function setDirty(yes) {
		dirty = yes;
		if (dock) dock.hidden = !yes;
	}

	// Editable system name (cover title — contenteditable)
	const nameEl = document.querySelector('[data-ds-name]');
	if (nameEl) {
		nameEl.addEventListener('input', () => {
			const clean = (nameEl.textContent || '').replace(/\s+/g, ' ').trim().slice(0, 80);
			if (!system.meta) system.meta = {};
			system.meta.name = clean;
			setDirty(true);
		});
		nameEl.addEventListener('keydown', (e) => {
			if (e.key === 'Enter') { e.preventDefault(); nameEl.blur(); }
			if (e.key === 'Escape') { nameEl.blur(); }
		});
		nameEl.addEventListener('paste', (e) => {
			e.preventDefault();
			const text = ((e.clipboardData || window.clipboardData).getData('text') || '').trim().slice(0, 80);
			document.execCommand('insertText', false, text);
		});
	}

	// Tabs (Visual / Tech)
	document.querySelectorAll('[data-ds-view]').forEach(btn => {
		btn.addEventListener('click', () => {
			const target = btn.getAttribute('data-ds-view');
			document.querySelectorAll('[data-ds-view]').forEach(b => {
				const on = b.getAttribute('data-ds-view') === target;
				b.classList.toggle('is-active', on);
				b.setAttribute('aria-selected', on ? 'true' : 'false');
			});
			document.querySelectorAll('[data-ds-view-pane]').forEach(p => {
				p.hidden = p.getAttribute('data-ds-view-pane') !== target;
			});
		});
	});

	// Color edits — DELEGATED so new color cards work too.
	document.addEventListener('input', (e) => {
		const inp = e.target.closest('[data-ds-color-input]');
		if (!inp) return;
		const idx = parseInt(inp.getAttribute('data-ds-color-input'), 10);
		const val = inp.value;
		if (!Array.isArray(system.colors) || !system.colors[idx]) return;
		system.colors[idx].value = val;

		const card = inp.closest('.ds-color');
		if (card) {
			const fill = card.querySelector('.ds-color-fill');
			if (fill) { fill.style.background = val; fill.style.color = contrastFor(val); }
			const hex = card.querySelector('.ds-color-hex');
			if (hex) hex.textContent = val;
			card.setAttribute('data-ds-copy-hex', val);
			const copyBtn = card.querySelector('.ds-color-copy');
			if (copyBtn) copyBtn.setAttribute('data-ds-copy-hex', val);
		}
		const role = system.colors[idx].role || '';
		if (canvas) {
			const map = { 'primary':'--primary', 'accent':'--accent', 'surface':'--bg', 'surface-2':'--bg2', 'text':'--tx', 'text-2':'--tx2', 'text-3':'--tx3', 'border':'--bd' };
			if (map[role]) canvas.style.setProperty(map[role], val);
		}
		const stripeMap = { 'primary': 0, 'accent': 1, 'text': 2, 'surface': 3 };
		if (stripeCells.length && stripeMap[role] !== undefined && stripeCells[stripeMap[role]]) {
			stripeCells[stripeMap[role]].style.background = val;
		}
		setDirty(true);
	});

	// Font edits — live preview via canvas CSS vars
	document.querySelectorAll('[data-ds-font-stack]').forEach(sel => {
		sel.addEventListener('change', () => {
			const id = sel.getAttribute('data-ds-font-stack');
			const stack = sel.value;
			if (!Array.isArray(system.fonts)) system.fonts = [];
			let f = system.fonts.find(x => x.id === id);
			if (!f) { f = { id: id, name: id, stack: stack, source: 'system' }; system.fonts.push(f); }
			else { f.stack = stack; }
			if (canvas) {
				if (id === 'display') canvas.style.setProperty('--ff-display', stack);
				if (id === 'body')    canvas.style.setProperty('--ff-body',    stack);
				if (id === 'mono')    canvas.style.setProperty('--ff-mono',    stack);
			}
			// Eat-your-own-dogfood: cover title + chapter numbers also reflect the display change.
			if (id === 'display') {
				const cover = document.querySelector('.ds-cover-title');
				if (cover) cover.style.fontFamily = stack;
				document.querySelectorAll('.ds-chapter-num').forEach(el => { el.style.fontFamily = stack; });
			}
			// Brand block — font-name labels live there. Show the first family.
			const label = document.querySelector('[data-ds-font-label="' + id + '"]');
			if (label) label.textContent = firstFamily(stack);
			setDirty(true);
		});
	});

	// Extract the primary family name from a CSS stack ("Inter Tight", -apple-system, ...).
	function firstFamily(stack) {
		const s = (stack || '').trim();
		if (!s) return '—';
		const q = s.match(/^"([^"]+)"/);
		if (q) return q[1];
		const w = s.match(/^([A-Za-z][A-Za-z0-9 \-]+)(?:,|$)/);
		return w ? w[1].trim() : '—';
	}

	// Copy hex — explicit button on every color card.
	document.addEventListener('click', async (e) => {
		const btn = e.target.closest('.ds-color-copy');
		if (!btn) return;
		e.preventDefault();
		e.stopPropagation();
		const hex = btn.getAttribute('data-ds-copy-hex') || '';
		try { await navigator.clipboard.writeText(hex); showToast('Copied ' + hex); } catch (_) {}
	});
	// Right-click anywhere on a color card → copy (power-user shortcut).
	document.querySelectorAll('.ds-color').forEach(card => {
		card.addEventListener('contextmenu', async (e) => {
			e.preventDefault();
			const hex = card.getAttribute('data-ds-copy-hex') || '';
			try { await navigator.clipboard.writeText(hex); showToast('Copied ' + hex); } catch (_) {}
		});
	});

	// Helper: contrast text color for any hex (used when retinting cards live).
	function contrastFor(hex) {
		hex = (hex || '').replace('#','');
		if (hex.length === 3) hex = hex[0]+hex[0]+hex[1]+hex[1]+hex[2]+hex[2];
		if (hex.length !== 6) return '#0a0a0a';
		const r = parseInt(hex.slice(0,2),16), g = parseInt(hex.slice(2,4),16), b = parseInt(hex.slice(4,6),16);
		return ((0.299*r + 0.587*g + 0.114*b) / 255) > 0.55 ? '#0a0a0a' : '#ffffff';
	}

	// Save
	async function doSave(btn) {
		if (!dirty) return;
		const orig = btn ? btn.textContent : null;
		if (btn) { btn.disabled = true; btn.textContent = 'Saving…'; }
		try {
			const fd = new FormData();
			fd.append('action', 'therum_ds_save');
			fd.append('nonce', window.therumDS.nonce);
			fd.append('system', JSON.stringify(system));
			const res = await fetch(window.therumDS.ajax, { method: 'POST', body: fd, credentials: 'same-origin' });
			const data = await res.json();
			if (!data.success) throw new Error(data.data && data.data.msg || 'save failed');
			system = data.data.system;
			original = JSON.parse(JSON.stringify(system));
			setDirty(false);
			showToast(data.data.synced_to_bricks ? 'Saved · synced to Bricks' : 'Saved');
			const cssPre  = document.querySelector('[data-ds-css-pre] code');
			if (cssPre && data.data.css) cssPre.textContent = data.data.css;
			const jsonPre = document.querySelector('[data-ds-json-pre] code');
			if (jsonPre) jsonPre.textContent = JSON.stringify(system, null, 2);
			applyExport(); // refresh whatever export format is currently shown
			if (btn && orig !== null) { btn.textContent = orig; btn.disabled = false; }
		} catch (err) {
			console.error('[therum-ds] save failed', err);
			if (btn) { btn.textContent = 'Save failed'; btn.disabled = false; }
			showToast('Save failed');
		}
	}
	document.querySelectorAll('[data-ds-save]').forEach(btn => btn.addEventListener('click', () => doSave(btn)));

	// Discard (reload values from original snapshot, restore canvas vars)
	function doDiscard() {
		system = JSON.parse(JSON.stringify(original));
		setDirty(false);
		// Simplest: reload the page to fully restore visual state — avoids re-render bugs.
		location.reload();
	}
	document.querySelectorAll('[data-ds-discard]').forEach(btn => btn.addEventListener('click', doDiscard));

	// Reset (server-side wipe to defaults)
	document.querySelectorAll('[data-ds-reset]').forEach(btn => {
		btn.addEventListener('click', async () => {
			if (!confirm('Reset to defaults? This overwrites your tokens.')) return;
			try {
				const fd = new FormData();
				fd.append('action', 'therum_ds_reset');
				fd.append('nonce', window.therumDS.nonce);
				const res = await fetch(window.therumDS.ajax, { method: 'POST', body: fd, credentials: 'same-origin' });
				const data = await res.json();
				if (!data.success) throw new Error('reset failed');
				location.reload();
			} catch (err) {
				console.error('[therum-ds] reset failed', err);
				showToast('Reset failed');
			}
		});
	});

	// Scale section — click any value to copy
	document.querySelectorAll('[data-ds-copy-val]').forEach(btn => {
		btn.addEventListener('click', async (e) => {
			e.preventDefault();
			const v = btn.getAttribute('data-ds-copy-val') || '';
			try { await navigator.clipboard.writeText(v); showToast('Copied ' + v); } catch (_) {}
		});
	});

	// Spacing — hover any bar to drive the in-context demo (delegated; works on new bars too)
	const spacingDemo  = document.querySelector('[data-ds-spacing-demo] .ds-spacing-demo-stage');
	const spacingLabel = document.querySelector('[data-ds-spacing-current]');
	document.addEventListener('mouseover', (e) => {
		const item = e.target.closest('.ds-spacing-item');
		if (!item || !spacingDemo) return;
		const btn = item.querySelector('[data-ds-copy-val]');
		const v = btn ? btn.getAttribute('data-ds-copy-val') : '';
		if (!v) return;
		spacingDemo.style.gap = v;
		if (spacingLabel) spacingLabel.textContent = v;
		document.querySelectorAll('.ds-spacing-item').forEach(i => i.classList.toggle('is-active', i === item));
	});

	// ── Add / remove tokens ─────────────────────────────────────────────
	document.addEventListener('click', (e) => {
		// Remove
		const rm = e.target.closest('[data-ds-token-remove]');
		if (rm) {
			e.preventDefault(); e.stopPropagation();
			const item = rm.closest('[data-ds-bucket]');
			if (!item) return;
			const bucket = item.getAttribute('data-ds-bucket');
			const idx    = parseInt(item.getAttribute('data-ds-color-idx') ?? item.getAttribute('data-ds-token-idx') ?? '-1', 10);
			if (bucket && idx >= 0 && Array.isArray(system[bucket])) {
				const name = system[bucket][idx] && (system[bucket][idx].name || system[bucket][idx].id) || 'token';
				if (!confirm('Remove "' + name + '"?')) return;
				system[bucket].splice(idx, 1);
				item.remove();
				reindexBucket(bucket);
				setDirty(true);
				showToast('Removed');
			}
			return;
		}

		// Add
		const add = e.target.closest('[data-ds-token-add]');
		if (add) {
			e.preventDefault();
			const bucket = add.getAttribute('data-ds-token-add');
			if (bucket === 'colors') {
				addColorToken(add);
			} else {
				openTokenForm(add, bucket);
			}
			return;
		}
	});

	// Re-index data-*-idx attrs on remaining bucket items after a removal
	function reindexBucket(bucket) {
		const items = document.querySelectorAll('[data-ds-bucket="' + bucket + '"]:not([data-ds-token-add])');
		items.forEach((el, i) => {
			if (bucket === 'colors') {
				el.setAttribute('data-ds-color-idx', i);
				const inp = el.querySelector('[data-ds-color-input]');
				if (inp) inp.setAttribute('data-ds-color-input', i);
			} else {
				el.setAttribute('data-ds-token-idx', i);
			}
		});
	}

	function addColorToken(addBtn) {
		const val  = '#888888';
		const idx  = (system.colors || []).length;
		const name = 'Color ' + (idx + 1);
		const id   = 'color-' + (idx + 1);
		system.colors = system.colors || [];
		system.colors.push({ id: id, name: name, value: val });
		const html = ''
			+ '<article class="ds-color" data-ds-color-idx="' + idx + '" data-ds-copy-hex="' + val + '" data-ds-bucket="colors">'
			+   '<button type="button" class="ds-token-rm" data-ds-token-remove aria-label="Remove">&times;</button>'
			+   '<label class="ds-color-fill" style="background:' + val + '; color:' + contrastFor(val) + ';">'
			+     '<input type="color" class="ds-color-input" value="' + val + '" data-ds-color-input="' + idx + '">'
			+     '<span class="ds-color-edit-cue">click to edit</span>'
			+   '</label>'
			+   '<div class="ds-color-meta">'
			+     '<div class="ds-color-name" contenteditable="true" data-ds-token-rename>' + escapeHtml(name) + '</div>'
			+     '<div class="ds-color-bottom">'
			+       '<span class="ds-color-hex">' + val + '</span>'
			+       '<button type="button" class="ds-color-copy" data-ds-copy-hex="' + val + '" aria-label="Copy">⎘</button>'
			+     '</div>'
			+   '</div>'
			+ '</article>';
		addBtn.insertAdjacentHTML('beforebegin', html);
		setDirty(true);
		// Auto-open picker on the new card
		const newCard = addBtn.previousElementSibling;
		const newInp  = newCard && newCard.querySelector('input[type=color]');
		if (newInp) setTimeout(() => newInp.click(), 60);
	}

	function openTokenForm(addBtn, bucket) {
		// Don't show a second form if one is already open
		if (addBtn.parentElement.querySelector('.ds-token-form')) return;
		const placeholder = bucket === 'shadows' ? '0 4px 12px rgba(0,0,0,.06)' : '16px';
		const form = document.createElement('div');
		form.className = 'ds-token-form';
		form.innerHTML = ''
			+ '<input class="ds-token-form-name" type="text" placeholder="Name (e.g. md)" maxlength="40">'
			+ '<input class="ds-token-form-val"  type="text" placeholder="Value (' + placeholder + ')" maxlength="120">'
			+ '<div class="ds-token-form-row">'
			+   '<button class="cancel" type="button" data-ds-form-cancel>Cancel</button>'
			+   '<button type="button" data-ds-form-add>Add</button>'
			+ '</div>';
		addBtn.parentElement.insertBefore(form, addBtn);
		form.querySelector('.ds-token-form-name').focus();

		form.addEventListener('click', (e) => {
			if (e.target.matches('[data-ds-form-cancel]')) { form.remove(); return; }
			if (e.target.matches('[data-ds-form-add]')) {
				const name = form.querySelector('.ds-token-form-name').value.trim();
				const val  = form.querySelector('.ds-token-form-val').value.trim();
				if (!name || !val) { form.querySelector('.ds-token-form-name').focus(); return; }
				const id = name.toLowerCase().replace(/[^a-z0-9_-]+/g, '-').replace(/^-+|-+$/g, '') || ('t' + Date.now());
				system[bucket] = system[bucket] || [];
				system[bucket].push({ id: id, name: name, value: val });
				appendScaleItemDOM(addBtn, bucket, system[bucket].length - 1, id, name, val);
				form.remove();
				setDirty(true);
				showToast('Added ' + name);
			}
		});
		form.addEventListener('keydown', (e) => {
			if (e.key === 'Enter')  { e.preventDefault(); form.querySelector('[data-ds-form-add]').click(); }
			if (e.key === 'Escape') { form.remove(); }
		});
	}

	function appendScaleItemDOM(addBtn, bucket, idx, id, name, val) {
		let html = '';
		if (bucket === 'sizes') {
			html = ''
				+ '<div class="ds-typescale-item" data-ds-token-idx="' + idx + '" data-ds-bucket="sizes">'
				+   '<button type="button" class="ds-typescale-sample" data-ds-copy-val="' + escapeAttr(val) + '" style="font-size:' + escapeAttr(val) + '; font-family: var(--ff-display); line-height: 1;">Aa</button>'
				+   '<div class="ds-typescale-meta"><span class="ds-typescale-name">' + escapeHtml(name) + '</span><span class="ds-typescale-val">' + escapeHtml(val) + '</span></div>'
				+   '<button type="button" class="ds-token-rm" data-ds-token-remove aria-label="Remove">&times;</button>'
				+ '</div>';
		} else if (bucket === 'spacing') {
			html = ''
				+ '<div class="ds-spacing-item" data-ds-token-idx="' + idx + '" data-ds-bucket="spacing">'
				+   '<button type="button" class="ds-token-rm" data-ds-token-remove aria-label="Remove">&times;</button>'
				+   '<button type="button" class="ds-spacing-bar-btn" data-ds-copy-val="' + escapeAttr(val) + '">'
				+     '<div class="ds-spacing-bar" style="height:' + escapeAttr(val) + ';"></div>'
				+     '<div class="ds-spacing-meta"><span class="ds-spacing-name">' + escapeHtml(name) + '</span><span class="ds-spacing-val">' + escapeHtml(val) + '</span></div>'
				+   '</button>'
				+ '</div>';
		} else if (bucket === 'radii') {
			html = ''
				+ '<div class="ds-radius-item" data-ds-token-idx="' + idx + '" data-ds-bucket="radii">'
				+   '<button type="button" class="ds-token-rm" data-ds-token-remove aria-label="Remove">&times;</button>'
				+   '<button type="button" class="ds-radius-btn" data-ds-copy-val="' + escapeAttr(val) + '">'
				+     '<div class="ds-radius-card" style="border-radius:' + escapeAttr(val) + '; background: var(--primary); color: var(--bg);"><span class="ds-radius-card-mark" style="font-family: var(--ff-display);">φ</span></div>'
				+     '<div class="ds-radius-meta"><span class="ds-radius-name">' + escapeHtml(name) + '</span><span class="ds-radius-val">' + escapeHtml(val) + '</span></div>'
				+   '</button>'
				+ '</div>';
		} else if (bucket === 'shadows') {
			const short = val.length > 28 ? val.slice(0, 27) + '…' : val;
			html = ''
				+ '<div class="ds-shadow-item" data-ds-token-idx="' + idx + '" data-ds-bucket="shadows">'
				+   '<button type="button" class="ds-token-rm" data-ds-token-remove aria-label="Remove">&times;</button>'
				+   '<button type="button" class="ds-shadow-btn" data-ds-copy-val="' + escapeAttr(val) + '">'
				+     '<div class="ds-shadow-card" style="box-shadow:' + escapeAttr(val) + '; background: var(--bg);"></div>'
				+     '<div class="ds-shadow-meta"><span class="ds-shadow-name">' + escapeHtml(name) + '</span><span class="ds-shadow-val" title="' + escapeAttr(val) + '">' + escapeHtml(short) + '</span></div>'
				+   '</button>'
				+ '</div>';
		}
		if (html) addBtn.insertAdjacentHTML('beforebegin', html);
	}

	function escapeHtml(s) { return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }
	function escapeAttr(s) { return escapeHtml(s); }

	// Tech view: format switcher + copy
	let exportFormat = 'css';
	const exportPre = document.querySelector('[data-ds-export-pre] code');

	function generateExport(format) {
		const s = system || {};
		if (format === 'json') {
			return JSON.stringify(s, null, 2);
		}
		if (format === 'css') {
			const lines = [];
			(s.colors || []).forEach(c => {
				if (!c.id || !c.value) return;
				lines.push('  --th-color-' + c.id + ': ' + c.value + ';');
				if (c.role) lines.push('  --th-' + c.role + ': ' + c.value + ';');
			});
			(s.fonts || []).forEach(f => { if (f.id && f.stack) lines.push('  --th-font-' + f.id + ': ' + f.stack + ';'); });
			(s.sizes || []).forEach(x => { if (x.id && x.value) lines.push('  --th-size-' + x.id + ': ' + x.value + ';'); });
			(s.spacing || []).forEach(x => { if (x.id && x.value) lines.push('  --th-space-' + x.id + ': ' + x.value + ';'); });
			(s.radii || []).forEach(x => { if (x.id && x.value) lines.push('  --th-radius-' + x.id + ': ' + x.value + ';'); });
			(s.shadows || []).forEach(x => { if (x.id && x.value) lines.push('  --th-shadow-' + x.id + ': ' + x.value + ';'); });
			return ':root {\n' + lines.join('\n') + '\n}\n';
		}
		if (format === 'scss') {
			const lines = [ '// Therum Design System — generated' ];
			(s.colors || []).forEach(c => { if (c.id && c.value) lines.push('$color-' + c.id + ': ' + c.value + ';'); });
			(s.fonts || []).forEach(f => { if (f.id && f.stack) lines.push('$font-' + f.id + ': ' + f.stack + ';'); });
			(s.sizes || []).forEach(x => { if (x.id && x.value) lines.push('$size-' + x.id + ': ' + x.value + ';'); });
			(s.spacing || []).forEach(x => { if (x.id && x.value) lines.push('$space-' + x.id + ': ' + x.value + ';'); });
			(s.radii || []).forEach(x => { if (x.id && x.value) lines.push('$radius-' + x.id + ': ' + x.value + ';'); });
			(s.shadows || []).forEach(x => { if (x.id && x.value) lines.push('$shadow-' + x.id + ': ' + x.value + ';'); });
			return lines.join('\n') + '\n';
		}
		if (format === 'tailwind') {
			const colors = {};
			(s.colors || []).forEach(c => { if (c.id && c.value) colors[c.id] = c.value; });
			const fontFamily = {};
			(s.fonts || []).forEach(f => {
				if (!f.id || !f.stack) return;
				// Split stack into array of families
				const families = f.stack.split(',').map(x => x.trim().replace(/^"(.+)"$/, '$1'));
				fontFamily[f.id] = families;
			});
			const fontSize = {};
			(s.sizes || []).forEach(x => { if (x.id && x.value) fontSize[x.id] = x.value; });
			const spacing = {};
			(s.spacing || []).forEach(x => { if (x.id && x.value) spacing[x.id] = x.value; });
			const borderRadius = {};
			(s.radii || []).forEach(x => { if (x.id && x.value) borderRadius[x.id] = x.value; });
			const boxShadow = {};
			(s.shadows || []).forEach(x => { if (x.id && x.value) boxShadow[x.id] = x.value; });
			const cfg = {
				theme: { extend: { colors, fontFamily, fontSize, spacing, borderRadius, boxShadow } }
			};
			return '/** Therum Design System — tailwind.config.js */\nmodule.exports = ' + JSON.stringify(cfg, null, 2) + ';\n';
		}
		if (format === 'figma') {
			// W3C Design Tokens Community Group format — what the official
			// "Tokens Studio for Figma" / "Design Tokens" plugins ingest. Drop
			// the file into Figma → Tokens → Import to materialize as styles.
			const out = {
				$themes: [],
				$metadata: { name: (s.meta && s.meta.name) || 'Therum Design System', version: s.version || '1', tokenSetOrder: ['global'] },
				global: { color: {}, font: {}, size: {}, spacing: {}, radius: {}, shadow: {} }
			};
			(s.colors  || []).forEach(c => { if (c.id && c.value) out.global.color[c.id]   = { $type: 'color',       $value: c.value }; });
			(s.fonts   || []).forEach(f => { if (f.id && f.stack) out.global.font[f.id]    = { $type: 'fontFamily',  $value: f.stack }; });
			(s.sizes   || []).forEach(x => { if (x.id && x.value) out.global.size[x.id]    = { $type: 'dimension',   $value: x.value }; });
			(s.spacing || []).forEach(x => { if (x.id && x.value) out.global.spacing[x.id] = { $type: 'dimension',   $value: x.value }; });
			(s.radii   || []).forEach(x => { if (x.id && x.value) out.global.radius[x.id]  = { $type: 'dimension',   $value: x.value }; });
			(s.shadows || []).forEach(x => { if (x.id && x.value) out.global.shadow[x.id]  = { $type: 'shadow',      $value: x.value }; });
			return JSON.stringify(out, null, 2);
		}
		return '';
	}

	// Download current export as a file. Filename + MIME chosen from format.
	const FORMAT_FILE = {
		css:      { name: 'therum-design-system.css',           mime: 'text/css' },
		json:     { name: 'therum-design-system.json',          mime: 'application/json' },
		scss:     { name: 'therum-design-system.scss',          mime: 'text/x-scss' },
		tailwind: { name: 'tailwind.config.js',                 mime: 'text/javascript' },
		figma:    { name: 'therum-design-system.tokens.json',   mime: 'application/json' }
	};
	document.querySelectorAll('[data-ds-download]').forEach(btn => {
		btn.addEventListener('click', () => {
			const which = btn.getAttribute('data-ds-download');
			const text  = (document.querySelector('[data-ds-' + which + '-pre] code') || {}).textContent || '';
			if (!text) return;
			const meta = FORMAT_FILE[exportFormat] || FORMAT_FILE.json;
			const blob = new Blob([text], { type: meta.mime });
			const url  = URL.createObjectURL(blob);
			const a = document.createElement('a');
			a.href = url; a.download = meta.name;
			document.body.appendChild(a); a.click();
			setTimeout(() => { URL.revokeObjectURL(url); a.remove(); }, 0);
		});
	});

	// Print-to-PDF: trigger window.print on a body class that activates the
	// print stylesheet inside .therum-ds. Browser handles "Save as PDF".
	document.querySelectorAll('[data-ds-pdf]').forEach(btn => {
		btn.addEventListener('click', () => {
			document.body.classList.add('ds-printing');
			setTimeout(() => {
				window.print();
				setTimeout(() => document.body.classList.remove('ds-printing'), 500);
			}, 50);
		});
	});

	function applyExport() {
		if (!exportPre) return;
		exportPre.textContent = generateExport(exportFormat);
	}

	document.querySelectorAll('[data-ds-format]').forEach(btn => {
		btn.addEventListener('click', () => {
			exportFormat = btn.getAttribute('data-ds-format');
			document.querySelectorAll('[data-ds-format]').forEach(b => b.classList.toggle('is-active', b === btn));
			applyExport();
		});
	});

	// Copy buttons — read from whichever pre matches the data-ds-copy target
	document.querySelectorAll('[data-ds-copy]').forEach(btn => {
		btn.addEventListener('click', async () => {
			const which = btn.getAttribute('data-ds-copy');
			let pre = null;
			if (which === 'export') pre = document.querySelector('[data-ds-export-pre] code');
			else if (which === 'json') pre = document.querySelector('[data-ds-json-pre] code');
			else                      pre = document.querySelector('[data-ds-css-pre] code');
			if (!pre) return;
			try {
				await navigator.clipboard.writeText(pre.textContent || '');
				const original = btn.textContent;
				btn.textContent = 'Copied';
				setTimeout(() => { btn.textContent = original; }, 1200);
			} catch (e) {}
		});
	});

	// Cmd/Ctrl-S → save
	document.addEventListener('keydown', (e) => {
		if ((e.metaKey || e.ctrlKey) && e.key === 's') {
			if (dirty) { e.preventDefault(); doSave(document.querySelector('[data-ds-dock] [data-ds-save]')); }
		}
	});
})();
JS;
	}
}


// ════════════════════════════════════════════════════════════════════════════
//  4. DATA — legacy Bricks/Therum reads (powers the Bricks readouts disclosure)
// ════════════════════════════════════════════════════════════════════════════

class Therum_Design_System_Data {

	public static function palettes(): array {
		$raw = get_option( 'bricks_color_palette', [] );
		if ( ! is_array( $raw ) ) return [];
		$out = [];
		foreach ( $raw as $p ) {
			if ( ! is_array( $p ) ) continue;
			$colors = [];
			foreach ( (array) ( $p['colors'] ?? [] ) as $c ) {
				if ( ! is_array( $c ) ) continue;
				$colors[] = [
					'id'   => (string) ( $c['id']   ?? '' ),
					'name' => (string) ( $c['name'] ?? '' ),
					'raw'  => (string) ( $c['raw']  ?? ( $c['hex'] ?? '' ) ),
				];
			}
			$out[] = [
				'id'     => (string) ( $p['id']   ?? '' ),
				'name'   => (string) ( $p['name'] ?? 'Untitled palette' ),
				'colors' => $colors,
			];
		}
		return $out;
	}

	public static function variables_by_category(): array {
		$raw = get_option( 'bricks_global_variables', [] );
		if ( ! is_array( $raw ) ) return [];
		$out = [];
		foreach ( $raw as $v ) {
			if ( ! is_array( $v ) ) continue;
			$cat = (string) ( $v['category'] ?? 'uncategorised' );
			$out[ $cat ][] = [
				'id'       => (string) ( $v['id']    ?? '' ),
				'name'     => (string) ( $v['name']  ?? '' ),
				'value'    => (string) ( $v['value'] ?? '' ),
				'category' => $cat,
			];
		}
		ksort( $out );
		return $out;
	}

	public static function global_classes(): array {
		$raw = get_option( 'bricks_global_classes', [] );
		if ( ! is_array( $raw ) ) return [];
		$out = [];
		foreach ( $raw as $c ) {
			if ( ! is_array( $c ) ) continue;
			$out[] = [
				'id'       => (string) ( $c['id']   ?? '' ),
				'name'     => (string) ( $c['name'] ?? '' ),
				'settings' => (array)  ( $c['settings'] ?? [] ),
				'category' => (string) ( $c['category'] ?? '' ),
			];
		}
		return $out;
	}
}


Therum_Design_System_Page::init();
