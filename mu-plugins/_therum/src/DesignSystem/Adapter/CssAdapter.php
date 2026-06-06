<?php
declare( strict_types=1 );

namespace Therum\DesignSystem\Adapter;

use Therum\DesignSystem\Store;

/**
 * CSS adapter — emits Therum design tokens as CSS custom properties
 * everywhere they're needed:
 *
 *   - Front-end:  wp_head     (so the live site uses them when no Bricks runs)
 *   - Admin:      admin_head  (so the style tile preview shows the live tokens)
 *
 * Always-on. Works with or without Bricks — Bricks separately serialises the
 * same tokens to its own CSS files via BricksAdapter, but this adapter is the
 * fallback path that guarantees a stylable site regardless.
 *
 * The emitted block is cached per system-update via the meta.updated_at
 * stamp — cheap to re-emit on every request, but if hot-path matters later we
 * can move to a written .css file.
 */
final class CssAdapter {

	private const STYLE_ID = 'therum-design-system';

	public static function init(): void {
		add_action( 'wp_head',    [ self::class, 'emit_frontend' ], 1 );
		add_action( 'admin_head', [ self::class, 'emit_admin' ],    1 );
		// Buster: invalidate the runtime cache on save.
		add_action( 'therum_design_system_saved', [ self::class, 'clear_cache' ] );
	}

	public static function emit_frontend(): void {
		// Two blocks: (1) raw token variables, (2) a base FE skin that maps
		// common HTML elements to the tokens so a vanilla install reflects
		// design-system changes immediately. Sites with Bricks templates
		// override this via element-specific CSS; the skin is a fallback,
		// not a hammer — opt out via the `therum_ds_fe_skin` filter.
		$block = self::build_block();
		if ( $block === '' ) return;
		echo "\n<style id='" . esc_attr( self::STYLE_ID ) . "'>" . $block . "</style>\n";

		if ( ! apply_filters( 'therum_ds_fe_skin', true ) ) return;
		echo "\n<style id='" . esc_attr( self::STYLE_ID ) . "-fe'>" . self::build_fe_skin() . "</style>\n";
	}

	/**
	 * Base FE skin — maps semantic token roles (text, surface, accent, etc.)
	 * to body, headings, links, and form elements so DS edits are visible
	 * on the live site without requiring per-element template work.
	 *
	 * Roles consumed: --th-text, --th-bg, --th-surface, --th-accent, --th-border,
	 *                  --th-font-body, --th-font-display.
	 * Falls back to neutral values when a role isn't defined.
	 */
	private static function build_fe_skin(): string {
		return ':root{--th-text-fallback:#0a0a0a;--th-bg-fallback:#ffffff;--th-surface-fallback:#fafafa;--th-accent-fallback:#0a0a0a;--th-border-fallback:rgba(0,0,0,.08)}'
			. 'body{color:var(--th-text,var(--th-text-fallback));background:var(--th-bg,var(--th-bg-fallback));font-family:var(--th-font-body,inherit)}'
			. 'h1,h2,h3,h4,h5,h6{color:var(--th-text,var(--th-text-fallback));font-family:var(--th-font-display,var(--th-font-body,inherit))}'
			. 'a{color:var(--th-accent,var(--th-accent-fallback))}'
			. 'hr,fieldset{border-color:var(--th-border,var(--th-border-fallback))}'
			. 'button,input,textarea,select{font-family:var(--th-font-body,inherit)}';
	}

	public static function emit_admin(): void {
		// Always emit in admin — the style tile + builder UIs both rely on it.
		echo "\n<style id='" . esc_attr( self::STYLE_ID ) . "-admin'>" . self::build_block() . "</style>\n";
	}

	public static function clear_cache(): void {
		// Currently in-memory only; reserved for future filesystem cache.
	}

	/**
	 * Build the CSS variable block for the current system.
	 * Returns minified, ready-to-inject CSS text.
	 */
	private static function build_block(): string {
		$system = Store::get();

		$lines = [];

		foreach ( (array) ( $system['colors'] ?? [] ) as $c ) {
			$id    = (string) ( $c['id']    ?? '' );
			$value = (string) ( $c['value'] ?? '' );
			if ( $id === '' || $value === '' ) continue;
			$lines[] = '--th-color-' . self::safe( $id ) . ':' . self::cssval( $value ) . ';';
			// Semantic role alias if present
			$role = (string) ( $c['role'] ?? '' );
			if ( $role !== '' ) {
				$lines[] = '--th-' . self::safe( $role ) . ':' . self::cssval( $value ) . ';';
			}
		}

		foreach ( (array) ( $system['fonts'] ?? [] ) as $f ) {
			$id    = (string) ( $f['id']    ?? '' );
			$stack = (string) ( $f['stack'] ?? '' );
			if ( $id === '' || $stack === '' ) continue;
			$lines[] = '--th-font-' . self::safe( $id ) . ':' . self::cssval( $stack ) . ';';
		}

		foreach ( (array) ( $system['sizes'] ?? [] ) as $s ) {
			$id    = (string) ( $s['id']    ?? '' );
			$value = (string) ( $s['value'] ?? '' );
			if ( $id === '' || $value === '' ) continue;
			$lines[] = '--th-size-' . self::safe( $id ) . ':' . self::cssval( $value ) . ';';
		}

		foreach ( (array) ( $system['spacing'] ?? [] ) as $s ) {
			$id    = (string) ( $s['id']    ?? '' );
			$value = (string) ( $s['value'] ?? '' );
			if ( $id === '' || $value === '' ) continue;
			$lines[] = '--th-space-' . self::safe( $id ) . ':' . self::cssval( $value ) . ';';
		}

		foreach ( (array) ( $system['radii'] ?? [] ) as $r ) {
			$id    = (string) ( $r['id']    ?? '' );
			$value = (string) ( $r['value'] ?? '' );
			if ( $id === '' || $value === '' ) continue;
			$lines[] = '--th-radius-' . self::safe( $id ) . ':' . self::cssval( $value ) . ';';
		}

		foreach ( (array) ( $system['shadows'] ?? [] ) as $s ) {
			$id    = (string) ( $s['id']    ?? '' );
			$value = (string) ( $s['value'] ?? '' );
			if ( $id === '' || $value === '' ) continue;
			$lines[] = '--th-shadow-' . self::safe( $id ) . ':' . self::cssval( $value ) . ';';
		}

		if ( empty( $lines ) ) return '';

		return ':root{' . implode( '', $lines ) . '}';
	}

	/**
	 * Build the same block but as a human-readable string for the Tech View
	 * UI — multi-line, indented, easy to copy.
	 */
	public static function build_pretty(): string {
		$block = self::build_block();
		if ( $block === '' ) return ':root {\n}\n';
		// Re-format
		$inner = preg_replace( '/^:root\{(.*)\}$/', '$1', $block ) ?? '';
		$decls = array_filter( array_map( 'trim', explode( ';', $inner ) ) );
		$out   = ":root {\n";
		foreach ( $decls as $d ) $out .= "  $d;\n";
		$out  .= "}\n";
		return $out;
	}

	/** Restrict identifier chars used in CSS var names. */
	private static function safe( string $s ): string {
		$s = strtolower( $s );
		return preg_replace( '/[^a-z0-9_-]+/', '-', $s ) ?? '';
	}

	/**
	 * Strip anything that would let a value break out of the declaration or smuggle
	 * script. Design-system tokens are colors / lengths / font names — never
	 * url(), expression(), or @import — so those constructs are removed wholesale
	 * rather than parsed. Comments and backslash escapes are dropped first so they
	 * can't hide or reconstruct a blocked keyword (e.g. `\65 xpression`).
	 */
	private static function cssval( string $v ): string {
		$v = preg_replace( '#/\*.*?\*/#s', '', $v ) ?? '';          // CSS comments
		$v = str_replace( [ ';', '{', '}', '<', '>', '\\' ], '', $v ); // break-out chars + escapes
		$v = preg_replace( '/(?:url|expression|image-set|@import)\s*\(/i', '', $v ) ?? $v;
		$v = preg_replace( '/(?:javascript|vbscript|data)\s*:/i', '', $v ) ?? $v;
		return trim( $v );
	}
}
