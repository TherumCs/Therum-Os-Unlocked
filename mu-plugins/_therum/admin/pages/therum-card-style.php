<?php
/**
 * Therum OS — Therum_Card_Style
 *
 * Extracted from therum-admin.php as part of the 1.9.x split. Same
 * class, same behavior; required back in from therum-admin.php at the
 * original load position to preserve declaration order.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class Therum_Card_Style {

	/** Resolve the user's current image-source preference from theme state. */
	public static function image_source(): string {
		// URL override for testing — ?th_force_image=wireframe
		if ( ! empty( $_GET['th_force_image'] ) ) {
			$forced = sanitize_text_field( wp_unslash( $_GET['th_force_image'] ) );
			if ( in_array( $forced, ['gradient','featured','stock','wireframe','pattern'], true ) ) return $forced;
		}
		if ( ! class_exists( 'Therum_Themes' ) ) return 'gradient';
		$state = Therum_Themes::get_state();
		$src = $state['cardImage'] ?? 'gradient';
		return in_array( $src, ['gradient','featured','stock','wireframe','pattern'], true ) ? $src : 'gradient';
	}

	/** Resolve the user's current card-layout preference ('card' or 'hero'). */
	public static function layout( ?\WP_Post $p = null ): string {
		if ( ! empty( $_GET['th_force_layout'] ) ) {
			$forced = sanitize_text_field( wp_unslash( $_GET['th_force_layout'] ) );
			if ( in_array( $forced, ['hero','compact','magazine','card-v1','card-v2','compact-v1','compact-v2'], true ) ) return $forced;
		}
		// Per-post override
		if ( $p ) {
			$per = get_post_meta( $p->ID, '_th_card_layout', true );
			if ( in_array( $per, ['hero','compact','magazine','card-v1','card-v2','compact-v1','compact-v2'], true ) ) return $per;
		}
		if ( ! class_exists( 'Therum_Themes' ) ) return 'hero';
		$state = Therum_Themes::get_state();
		$lay = $state['cardLayout'] ?? 'hero';
		return in_array( $lay, ['hero','compact','magazine','card-v1','card-v2','compact-v1','compact-v2'], true ) ? $lay : 'hero';
	}

	/** Diagnostic — for debugging when settings aren't applying. */
	public static function diagnostic(): string {
		if ( ! class_exists( 'Therum_Themes' ) ) return 'Therum_Themes class missing';
		$state = Therum_Themes::get_state();
		$user_meta = get_user_meta( get_current_user_id(), 'therum_theme_state', true );
		return sprintf(
			'state[cardLayout]=%s | state[cardImage]=%s | layout()=%s | image_source()=%s | raw_meta_keys=%s',
			var_export( $state['cardLayout'] ?? null, true ),
			var_export( $state['cardImage'] ?? null, true ),
			self::layout(),
			self::image_source(),
			is_array( $user_meta ) ? implode( ',', array_keys( $user_meta ) ) : 'not_array'
		);
	}

	/**
	 * Build the inline style string for a card thumbnail given a post.
	 * Always returns a `style="..."` value (not the attribute itself).
	 */
	public static function thumb_style( \WP_Post $p, ?string $forced_source = null ): string {
		// Per-post override takes priority over global, unless explicitly forced
		if ( ! $forced_source ) {
			$per_post = get_post_meta( $p->ID, '_th_card_image', true );
			if ( in_array( $per_post, ['gradient','featured','stock','wireframe','pattern'], true ) ) {
				$forced_source = $per_post;
			}
		}
		$src = $forced_source ?: self::image_source();

		// FEATURED — try real image, fallback to wireframe if missing
		if ( $src === 'featured' ) {
			$thumb_id = get_post_thumbnail_id( $p->ID );
			$thumb    = $thumb_id ? wp_get_attachment_image_url( $thumb_id, 'medium' ) : '';
			if ( $thumb ) {
				return "background-image:linear-gradient(180deg,rgba(0,0,0,0) 50%,rgba(0,0,0,.55) 100%),url('" . esc_url( $thumb ) . "');background-size:cover;background-position:center;";
			}
			// Fallback to wireframe
			return self::wireframe_style( $p );
		}

		// STOCK — Picsum, seeded by post ID for stability
		if ( $src === 'stock' ) {
			$url = "https://picsum.photos/seed/{$p->ID}/600/400";
			return "background-image:linear-gradient(180deg,rgba(0,0,0,0) 50%,rgba(0,0,0,.55) 100%),url('{$url}');background-size:cover;background-position:center;";
		}

		// WIREFRAME — auto SVG pattern
		if ( $src === 'wireframe' ) {
			return self::wireframe_style( $p );
		}

		// PATTERN — geometric SVG tile, theme-accent based
		if ( $src === 'pattern' ) {
			return self::pattern_style( $p );
		}

		// GRADIENT — deterministic per-post gradient
		return self::gradient_style( $p );
	}

	/**
	 * Generate a deterministic gradient based on post ID, so each post gets a
	 * unique-looking gradient that stays the same across page loads.
	 */
	protected static function gradient_style( \WP_Post $p ): string {
		// Pick from a curated set of gradient pairs.
		$pairs = [
			['#1e3a8a', '#6b21a8'], // navy → purple
			['#0f766e', '#0e7490'], // teal → cyan
			['#7c2d12', '#b91c1c'], // burgundy → red
			['#365314', '#65a30d'], // dark olive → lime
			['#581c87', '#a21caf'], // deep purple → magenta
			['#0c4a6e', '#0891b2'], // deep blue → sky
			['#7c2d12', '#ea580c'], // burnt → orange
			['#1f2937', '#4b5563'], // graphite → slate
			['#831843', '#e11d48'], // wine → rose
			['#134e4a', '#10b981'], // forest → emerald
		];
		$idx = $p->ID % count( $pairs );
		[$a, $b] = $pairs[ $idx ];
		return "background:linear-gradient(135deg,{$a},{$b});";
	}

	/**
	 * Wireframe — an inline SVG data URI with abstract shapes seeded by post.
	 * Looks like a low-fi mockup placeholder. Deterministic from post ID.
	 */
	protected static function wireframe_style( \WP_Post $p ): string {
		$seed = $p->ID;
		// Pick a colorway from post ID
		$colorways = [
			['#f8fafc', '#cbd5e1', '#64748b'], // slate
			['#fef3c7', '#fcd34d', '#a16207'], // amber
			['#dbeafe', '#93c5fd', '#1d4ed8'], // blue
			['#fee2e2', '#fca5a5', '#b91c1c'], // red
			['#dcfce7', '#86efac', '#15803d'], // green
			['#fae8ff', '#d8b4fe', '#7e22ce'], // purple
			['#fff7ed', '#fdba74', '#c2410c'], // orange
			['#f1f5f9', '#94a3b8', '#334155'], // cool grey
		];
		$cw = $colorways[ $seed % count( $colorways ) ];
		[$bg, $mid, $fg] = $cw;

		// Vary positions/sizes deterministically
		$x1 = ( $seed * 7 ) % 200 + 50;
		$y1 = ( $seed * 11 ) % 100 + 40;
		$x2 = ( $seed * 13 ) % 250 + 100;
		$y2 = ( $seed * 17 ) % 80 + 100;
		$rot = ( $seed * 23 ) % 360;

		$svg = <<<SVG
<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 600 400' preserveAspectRatio='xMidYMid slice'>
	<rect width='600' height='400' fill='{$bg}'/>
	<circle cx='{$x1}' cy='{$y1}' r='80' fill='{$mid}' opacity='0.6'/>
	<rect x='{$x2}' y='{$y2}' width='220' height='14' rx='7' fill='{$mid}'/>
	<rect x='{$x2}' y='{$y2}' width='160' height='14' rx='7' fill='{$mid}' transform='translate(0,30)'/>
	<rect x='{$x2}' y='{$y2}' width='100' height='14' rx='7' fill='{$mid}' transform='translate(0,60)' opacity='0.6'/>
	<g transform='rotate({$rot} 300 200)' opacity='0.15'>
		<line x1='0' y1='100' x2='600' y2='100' stroke='{$fg}' stroke-width='2'/>
		<line x1='0' y1='200' x2='600' y2='200' stroke='{$fg}' stroke-width='2'/>
		<line x1='0' y1='300' x2='600' y2='300' stroke='{$fg}' stroke-width='2'/>
	</g>
</svg>
SVG;
		$encoded = rawurlencode( trim( $svg ) );
		return "background-image:linear-gradient(180deg,rgba(0,0,0,0) 60%,rgba(0,0,0,.4) 100%),url(\"data:image/svg+xml,{$encoded}\");background-size:cover;background-position:center;";
	}

	protected static function pattern_style( \WP_Post $p ): string {
		$seed = $p->ID;
		// Pick from a curated set of accent gradients seeded by post ID
		$pairs = [
			['#1e293b', '#0ea5e9'],
			['#312e81', '#a78bfa'],
			['#0f172a', '#f97316'],
			['#7f1d1d', '#fbbf24'],
			['#064e3b', '#34d399'],
			['#831843', '#f472b6'],
			['#1c1917', '#fde68a'],
			['#082f49', '#22d3ee'],
		];
		[$bg, $stripe] = $pairs[$seed % count($pairs)];
		$angle = ($seed * 31) % 180;
		$svg = <<<SVG
<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 600 400' preserveAspectRatio='xMidYMid slice'>
	<defs>
		<pattern id='p{$seed}' x='0' y='0' width='40' height='40' patternUnits='userSpaceOnUse' patternTransform='rotate({$angle})'>
			<rect width='40' height='40' fill='{$bg}'/>
			<line x1='0' y1='0' x2='0' y2='40' stroke='{$stripe}' stroke-width='8' opacity='0.45'/>
			<line x1='20' y1='0' x2='20' y2='40' stroke='{$stripe}' stroke-width='2' opacity='0.25'/>
		</pattern>
	</defs>
	<rect width='600' height='400' fill='url(#p{$seed})'/>
</svg>
SVG;
		$encoded = rawurlencode( trim( $svg ) );
		return "background-image:linear-gradient(180deg,rgba(0,0,0,0) 50%,rgba(0,0,0,.55) 100%),url(\"data:image/svg+xml,{$encoded}\");background-size:cover;background-position:center;";
	}

	/**
	 * Render the per-card image picker menu (⋮ button + dropdown of 4 sources).
	 * Stops click propagation so clicking the menu doesn't navigate to the post.
	 */
	public static function render_picker_menu( int $post_id ): void {
		$cur_img = get_post_meta( $post_id, '_th_card_image', true ) ?: 'inherit';
		$cur_lay = get_post_meta( $post_id, '_th_card_layout', true ) ?: 'inherit';
		$layouts = [
			'inherit'  => 'Use site default',
			'card'     => 'Card',
			'hero'     => 'Hero',
			'compact'  => 'Compact',
			'magazine' => 'Magazine',
		];
		$images = [
			'inherit'   => 'Use site default',
			'gradient'  => 'Gradient',
			'featured'  => 'Featured image',
			'stock'     => 'Stock photo',
			'wireframe' => 'Wireframe',
			'pattern'   => 'Pattern',
		];
		?>
		<div class="th-card-picker" data-card-picker data-post-id="<?php echo (int)$post_id; ?>">
		  <button type="button" class="th-card-picker-btn" aria-label="Card style" data-card-picker-toggle>
			<svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><circle cx="5" cy="12" r="2"/><circle cx="12" cy="12" r="2"/><circle cx="19" cy="12" r="2"/></svg>
		  </button>
		  <div class="th-card-picker-menu" data-card-picker-menu>
			<div class="th-card-picker-label">Layout</div>
			<?php foreach ( $layouts as $key => $label ): ?>
			<button type="button" class="th-card-picker-item<?php echo $cur_lay === $key ? ' active' : ''; ?>" data-card-layout="<?php echo esc_attr( $key ); ?>">
			  <?php echo esc_html( $label ); ?>
			  <?php if ( $cur_lay === $key ): ?>
			  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
			  <?php endif; ?>
			</button>
			<?php endforeach; ?>
			<div class="th-card-picker-divider"></div>
			<div class="th-card-picker-label">Image</div>
			<?php foreach ( $images as $key => $label ): ?>
			<button type="button" class="th-card-picker-item<?php echo $cur_img === $key ? ' active' : ''; ?>" data-card-image="<?php echo esc_attr( $key ); ?>">
			  <?php echo esc_html( $label ); ?>
			  <?php if ( $cur_img === $key ): ?>
			  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
			  <?php endif; ?>
			</button>
			<?php endforeach; ?>
		  </div>
		</div>
		<?php
	}
}
