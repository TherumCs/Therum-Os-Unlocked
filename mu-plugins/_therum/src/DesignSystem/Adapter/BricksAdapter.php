<?php
declare( strict_types=1 );

namespace Therum\DesignSystem\Adapter;

/**
 * Bricks adapter — pushes the canonical Therum design system into Bricks
 * globals when Bricks is installed.
 *
 * Direction: one-way (Therum → Bricks). Therum is the source of truth.
 * Bricks data hydrates the canonical store ONCE on first install (see Store).
 * After that, all edits flow Therum → Bricks.
 *
 * Writes to:
 *   - bricks_color_palette   (creates/replaces a single palette: id='therum')
 *   - bricks_global_variables (writes one var per color + per font-family)
 *
 * Safe to call when Bricks isn't installed — the adapter checks before writing.
 */
final class BricksAdapter {

	private const PALETTE_ID = 'therum';

	/** Boot — hook into save event. Idempotent. */
	public static function init(): void {
		add_action( 'therum_design_system_saved', [ self::class, 'on_saved' ], 10, 1 );
	}

	public static function is_available(): bool {
		return defined( 'BRICKS_VERSION' ) || class_exists( '\\Bricks\\Database' );
	}

	/**
	 * Sync handler — fired by Store::save() when sync=true.
	 *
	 * @param array<string, mixed> $system
	 */
	public static function on_saved( array $system ): void {
		if ( ! self::is_available() ) return;

		self::sync_palette( $system['colors'] ?? [] );
		self::sync_variables( $system );
		self::regenerate_css();
	}

	/**
	 * Write/replace the Therum palette inside bricks_color_palette.
	 * Preserves any non-Therum palettes the user has manually defined.
	 *
	 * @param list<array<string, string>> $colors
	 */
	private static function sync_palette( array $colors ): void {
		$existing = (array) get_option( 'bricks_color_palette', [] );

		// Drop any prior Therum palette.
		$kept = [];
		foreach ( $existing as $p ) {
			if ( is_array( $p ) && ( $p['id'] ?? '' ) !== self::PALETTE_ID ) $kept[] = $p;
		}

		$swatches = [];
		foreach ( $colors as $i => $c ) {
			$value = (string) ( $c['value'] ?? '' );
			if ( $value === '' ) continue;
			$swatches[] = [
				'id'   => self::PALETTE_ID . '_' . ( $c['id'] ?? $i ),
				'name' => (string) ( $c['name'] ?? $value ),
				'raw'  => $value,
			];
		}

		if ( ! empty( $swatches ) ) {
			$kept[] = [
				'id'     => self::PALETTE_ID,
				'name'   => 'Therum',
				'colors' => $swatches,
			];
		}

		update_option( 'bricks_color_palette', $kept, false );
	}

	/**
	 * Write CSS custom properties to bricks_global_variables.
	 * Preserves non-Therum variables. All Therum vars share category='therum'.
	 *
	 * @param array<string, mixed> $system
	 */
	private static function sync_variables( array $system ): void {
		$existing = (array) get_option( 'bricks_global_variables', [] );

		// Drop any prior Therum vars.
		$kept = [];
		foreach ( $existing as $v ) {
			if ( is_array( $v ) && ( $v['category'] ?? '' ) !== 'therum' ) $kept[] = $v;
		}

		// Colors
		foreach ( (array) ( $system['colors'] ?? [] ) as $c ) {
			$id    = (string) ( $c['id']    ?? '' );
			$value = (string) ( $c['value'] ?? '' );
			if ( $id === '' || $value === '' ) continue;
			$kept[] = [
				'id'       => 'th-color-' . $id,
				'name'     => '--th-color-' . $id,
				'value'    => $value,
				'category' => 'therum',
			];
		}

		// Fonts
		foreach ( (array) ( $system['fonts'] ?? [] ) as $f ) {
			$id    = (string) ( $f['id']    ?? '' );
			$stack = (string) ( $f['stack'] ?? '' );
			if ( $id === '' || $stack === '' ) continue;
			$kept[] = [
				'id'       => 'th-font-' . $id,
				'name'     => '--th-font-' . $id,
				'value'    => $stack,
				'category' => 'therum',
			];
		}

		// Sizes
		foreach ( (array) ( $system['sizes'] ?? [] ) as $s ) {
			$id    = (string) ( $s['id']    ?? '' );
			$value = (string) ( $s['value'] ?? '' );
			if ( $id === '' || $value === '' ) continue;
			$kept[] = [
				'id'       => 'th-size-' . $id,
				'name'     => '--th-size-' . $id,
				'value'    => $value,
				'category' => 'therum',
			];
		}

		// Spacing
		foreach ( (array) ( $system['spacing'] ?? [] ) as $s ) {
			$id    = (string) ( $s['id']    ?? '' );
			$value = (string) ( $s['value'] ?? '' );
			if ( $id === '' || $value === '' ) continue;
			$kept[] = [
				'id'       => 'th-space-' . $id,
				'name'     => '--th-space-' . $id,
				'value'    => $value,
				'category' => 'therum',
			];
		}

		// Radii
		foreach ( (array) ( $system['radii'] ?? [] ) as $r ) {
			$id    = (string) ( $r['id']    ?? '' );
			$value = (string) ( $r['value'] ?? '' );
			if ( $id === '' || $value === '' ) continue;
			$kept[] = [
				'id'       => 'th-radius-' . $id,
				'name'     => '--th-radius-' . $id,
				'value'    => $value,
				'category' => 'therum',
			];
		}

		// Shadows
		foreach ( (array) ( $system['shadows'] ?? [] ) as $s ) {
			$id    = (string) ( $s['id']    ?? '' );
			$value = (string) ( $s['value'] ?? '' );
			if ( $id === '' || $value === '' ) continue;
			$kept[] = [
				'id'       => 'th-shadow-' . $id,
				'name'     => '--th-shadow-' . $id,
				'value'    => $value,
				'category' => 'therum',
			];
		}

		update_option( 'bricks_global_variables', $kept, false );
	}

	/**
	 * Regenerate Bricks CSS files so the new vars take effect on next request
	 * without needing to re-render a page manually.
	 */
	private static function regenerate_css(): void {
		if ( ! class_exists( '\\Bricks\\Assets_Files' ) ) return;
		if ( ! method_exists( '\\Bricks\\Assets_Files', 'regenerate' ) ) return;
		try {
			\Bricks\Assets_Files::regenerate();
		} catch ( \Throwable $e ) {
			// Silent — Bricks CSS regen failure shouldn't block the save.
			error_log( '[therum-design-system] Bricks CSS regen failed: ' . $e->getMessage() );
		}
	}
}
