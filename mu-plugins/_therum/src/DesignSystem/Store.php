<?php
declare( strict_types=1 );

namespace Therum\DesignSystem;

/**
 * Canonical persistence for the Therum Design System.
 *
 * Single autoload-OFF option `therum_design_system` is the source of truth.
 * Everything else (Bricks globals, emitted CSS, MCP tool reads) is a
 * derivative of what lives here.
 *
 * On first read (no stored value) we hydrate from one of:
 *   1. Bricks data, if Bricks is present and has anything (one-time migration)
 *   2. Schema::defaults() as a baseline so the page renders
 *
 * Hydration is idempotent — once hydrated, the option is locked in.
 */
final class Store {

	private const OPTION_KEY = 'therum_design_system';
	private const OPTION_HYDRATED = 'therum_design_system_hydrated';

	/**
	 * Get the current system, hydrating on first access.
	 *
	 * @return array<string, mixed>
	 */
	public static function get(): array {
		$stored = get_option( self::OPTION_KEY, null );

		if ( is_array( $stored ) && ! empty( $stored ) ) {
			return self::ensure_shape( $stored );
		}

		// First-touch hydration
		$hydrated = self::hydrate_initial();
		self::save( $hydrated, /*sync*/ false );
		update_option( self::OPTION_HYDRATED, 1, false );

		return $hydrated;
	}

	/**
	 * Replace the entire system. Runs through Schema::sanitise() first.
	 * Pass $sync=true to also push to adapters (Bricks etc.).
	 *
	 * @param  array<string, mixed> $system
	 * @return array<string, mixed>  the sanitised, stored shape
	 */
	public static function save( array $system, bool $sync = true ): array {
		$clean = Schema::sanitise( $system );
		$clean['meta']['updated_at'] = gmdate( 'Y-m-d\TH:i:s\Z' );

		update_option( self::OPTION_KEY, $clean, false );

		if ( $sync ) {
			/**
			 * Adapters hook here to push the canonical system to their target
			 * (Bricks globals, emitted CSS files, etc.). Listeners should NOT
			 * mutate the system — read-only.
			 */
			do_action( 'therum_design_system_saved', $clean );
		}

		return $clean;
	}

	/**
	 * Update one bucket only (colors, fonts, etc.). Cheap shortcut for
	 * UI editors that touch a single token.
	 *
	 * @param  string $bucket
	 * @param  list<array<string, string>> $rows
	 * @return array<string, mixed>
	 */
	public static function update_bucket( string $bucket, array $rows ): array {
		$system = self::get();
		$system[ $bucket ] = $rows;
		return self::save( $system );
	}

	/**
	 * Reset to defaults. Useful for testing and "start over" flows.
	 */
	public static function reset(): array {
		return self::save( Schema::defaults() );
	}

	/**
	 * Has the store been initialised (vs. lazy default)?
	 */
	public static function is_hydrated(): bool {
		return (bool) get_option( self::OPTION_HYDRATED, 0 );
	}

	// ── Hydration ────────────────────────────────────────────────────────────

	/**
	 * Pick the best initial system: Bricks data wins if present, else defaults.
	 *
	 * @return array<string, mixed>
	 */
	private static function hydrate_initial(): array {
		$from_bricks = self::try_hydrate_from_bricks();
		if ( $from_bricks !== null ) return $from_bricks;
		return Schema::defaults();
	}

	/**
	 * Build a canonical system from existing Bricks data.
	 * Returns null if Bricks has nothing useful.
	 *
	 * @return array<string, mixed>|null
	 */
	private static function try_hydrate_from_bricks(): ?array {
		$palette = (array) get_option( 'bricks_color_palette', [] );
		$vars    = (array) get_option( 'bricks_global_variables', [] );
		$styles  = (array) get_option( 'bricks_theme_styles', [] );

		if ( empty( $palette ) && empty( $vars ) && empty( $styles ) ) return null;

		$system = Schema::empty();
		$system['meta']['name'] = 'Imported from Bricks';

		// Colors from the first palette (most-used pattern).
		if ( ! empty( $palette[0]['colors'] ) ) {
			foreach ( (array) $palette[0]['colors'] as $c ) {
				if ( ! is_array( $c ) ) continue;
				$value = (string) ( $c['raw'] ?? ( $c['hex'] ?? '' ) );
				if ( $value === '' ) continue;
				$system['colors'][] = [
					'id'    => self::id_from_name( (string) ( $c['name'] ?? '' ), $value ),
					'name'  => (string) ( $c['name'] ?? $value ),
					'value' => $value,
				];
			}
		}

		// Variables — try to detect colors, sizes, fonts.
		foreach ( $vars as $v ) {
			if ( ! is_array( $v ) ) continue;
			$name  = (string) ( $v['name']  ?? '' );
			$value = (string) ( $v['value'] ?? '' );
			if ( $name === '' || $value === '' ) continue;
			$id = self::id_from_var_name( $name );

			if ( self::looks_like_color( $value ) ) {
				$system['colors'][] = [ 'id' => $id, 'name' => self::label_from_id( $id ), 'value' => $value ];
			} elseif ( self::looks_like_font( $value ) ) {
				$system['fonts'][] = [ 'id' => $id, 'name' => self::label_from_id( $id ), 'stack' => $value, 'source' => 'system' ];
			} elseif ( self::looks_like_size( $value ) ) {
				$system['sizes'][] = [ 'id' => $id, 'name' => self::label_from_id( $id ), 'value' => $value ];
			}
		}

		// If we ended up with no colors at all, fall back to defaults so the
		// style tile renders meaningfully.
		if ( empty( $system['colors'] ) ) $system['colors'] = Schema::defaults()['colors'];
		if ( empty( $system['fonts'] )  ) $system['fonts']  = Schema::defaults()['fonts'];
		if ( empty( $system['sizes'] )  ) $system['sizes']  = Schema::defaults()['sizes'];

		// Always fill these — Bricks rarely exposes them as vars.
		$d = Schema::defaults();
		if ( empty( $system['spacing'] ) ) $system['spacing'] = $d['spacing'];
		if ( empty( $system['radii'] )   ) $system['radii']   = $d['radii'];
		if ( empty( $system['shadows'] ) ) $system['shadows'] = $d['shadows'];

		return $system;
	}

	private static function id_from_name( string $name, string $fallback ): string {
		$src = $name !== '' ? $name : $fallback;
		$id  = strtolower( $src );
		$id  = preg_replace( '/[^a-z0-9_-]+/', '-', $id ) ?? '';
		$id  = trim( $id, '-' );
		return $id !== '' ? substr( $id, 0, 40 ) : 'unnamed';
	}

	private static function id_from_var_name( string $name ): string {
		// strip leading --, common prefixes
		$id = ltrim( $name, '-' );
		$id = preg_replace( '/^(th|bricks|brand)[-_]/i', '', $id ) ?? $id;
		return self::id_from_name( $id, $name );
	}

	private static function label_from_id( string $id ): string {
		return ucwords( str_replace( [ '-', '_' ], ' ', $id ) );
	}

	private static function looks_like_color( string $v ): bool {
		$v = trim( $v );
		if ( preg_match( '/^#([0-9a-f]{3}|[0-9a-f]{6}|[0-9a-f]{8})$/i', $v ) ) return true;
		if ( preg_match( '/^(rgb|rgba|hsl|hsla)\(/i', $v ) ) return true;
		return false;
	}

	private static function looks_like_size( string $v ): bool {
		return (bool) preg_match( '/^-?\d*\.?\d+(px|rem|em|%|vh|vw|vmin|vmax)$/i', trim( $v ) );
	}

	private static function looks_like_font( string $v ): bool {
		return (bool) preg_match( '/sans|serif|mono|cursive|fantasy|"|\'/i', $v );
	}

	/**
	 * Defensive — make sure a freshly-read system still has the expected keys
	 * (handles schema evolution: old store missing radii, etc.).
	 *
	 * @param  array<string, mixed> $sys
	 * @return array<string, mixed>
	 */
	private static function ensure_shape( array $sys ): array {
		$base = Schema::empty();
		foreach ( $base as $k => $default ) {
			if ( ! isset( $sys[ $k ] ) ) $sys[ $k ] = $default;
		}
		return $sys;
	}
}
