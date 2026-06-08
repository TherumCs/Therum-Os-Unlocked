<?php
declare( strict_types=1 );

namespace Therum\Design;

/**
 * Apply a refined Candidate kit to Bricks globals.
 *
 * Writes to:
 *   - bricks_color_palette        (array of swatches)
 *   - bricks_theme_styles         (typography defaults)
 *   - bricks_global_variables     (CSS custom properties — design tokens)
 *
 * Bricks safety rules (per the bricks-mcp data-model deep dive):
 *   - Always use update_option() (auto-serialises) — never raw SQL.
 *   - If Bricks "CSS file mode" is enabled, the global CSS file must be
 *     regenerated after global changes. We hook into the Bricks regeneration
 *     pathway if it's available; otherwise the user re-renders one page to
 *     trigger Bricks' own invalidation.
 *
 * Returns a result array summarising what was written.
 */
final class Apply {

	/**
	 * @param  array{
	 *   colors_to_write?: list<array{value: string, label?: string}>,
	 *   write_typography?: bool,
	 *   write_spacing?: bool
	 * } $opts
	 * @return array<string, mixed>
	 */
	public static function apply( Candidate $candidate, array $opts = [] ): array {
		$result = [
			'candidate_id'  => $candidate->id,
			'colors_added'  => 0,
			'typography'    => false,
			'variables'     => 0,
			'warnings'      => [],
		];

		// ── 1. Color palette ──────────────────────────────────────────────
		$colors = is_array( $opts['colors_to_write'] ?? null )
			? $opts['colors_to_write']
			: self::derive_default_colors( $candidate );

		if ( ! empty( $colors ) ) {
			$result['colors_added'] = self::write_color_palette( $colors, $candidate );
		}

		// ── 2. CSS custom properties (global variables) ───────────────────
		$result['variables'] = self::write_global_variables( $colors, $candidate );

		// ── 3. Typography defaults ────────────────────────────────────────
		if ( ! empty( $opts['write_typography'] ?? false ) ) {
			$result['typography'] = self::write_theme_styles( $candidate );
		}

		// ── 4. Regenerate builder CSS if available ────────────────────────
		$builder_regen = false;
		if ( class_exists( '\\Bricks\\Assets_Files' )
			&& method_exists( '\\Bricks\\Assets_Files', 'regenerate' ) ) {
			try {
				\Bricks\Assets_Files::regenerate();
				$builder_regen = true;
			} catch ( \Throwable $e ) {
				$result['warnings'][] = 'CSS regeneration failed: ' . $e->getMessage();
			}
		}
		// Notify sibling builder integrations (Elementor mu-plugin, etc.) so
		// each clears its own CSS cache. The hook is a no-op when no other
		// builder is loaded.
		if ( function_exists( 'do_action' ) ) {
			\do_action( 'therum_cache_purged', 'design_tokens' );
			if ( function_exists( 'therum_elementor_active' ) && therum_elementor_active() ) {
				$builder_regen = true;
			}
		}
		if ( ! $builder_regen ) {
			$result['warnings'][] = 'No builder CSS regeneration invoked — re-render one page to trigger it.';
		}

		CandidateRepository::mark_applied( $candidate );

		return $result;
	}

	/**
	 * Take the top-N hex colours from the candidate as the default palette.
	 *
	 * @return list<array{value: string, label?: string}>
	 */
	private static function derive_default_colors( Candidate $candidate ): array {
		$tokens = $candidate->data['colors']['tokens'] ?? [];
		if ( ! is_array( $tokens ) ) return [];

		$n = (int) apply_filters( 'therum_design_apply_color_count', 8 );
		$out = [];
		foreach ( $tokens as $row ) {
			if ( ! is_array( $row ) ) continue;
			$type  = (string) ( $row['type'] ?? '' );
			$value = (string) ( $row['value'] ?? '' );
			if ( $type !== 'hex' || $value === '' ) continue;
			$out[] = [ 'value' => $value ];
			if ( count( $out ) >= $n ) break;
		}
		return $out;
	}

	/**
	 * Write the colour palette to bricks_color_palette in Bricks' expected
	 * shape: a list of palettes, each with an `id`, `name`, `colors[]`.
	 */
	private static function write_color_palette( array $colors, Candidate $candidate ): int {
		$existing = (array) get_option( 'bricks_color_palette', [] );

		$palette_id = 'therum_' . $candidate->id;
		$palette = [
			'id'     => $palette_id,
			'name'   => $candidate->label,
			'colors' => [],
		];
		foreach ( $colors as $i => $c ) {
			$value = (string) ( $c['value'] ?? '' );
			if ( $value === '' ) continue;
			$palette['colors'][] = [
				'id'    => $palette_id . '_c' . $i,
				'name'  => $c['label'] ?? sprintf( 'Color %d', $i + 1 ),
				'raw'   => $value,
			];
		}

		// Update-by-id, not append. Repeated apply() calls of the same kit
		// would otherwise grow bricks_color_palette unboundedly with stale
		// snapshots of the same palette.
		$replaced = false;
		foreach ( $existing as $idx => $row ) {
			if ( is_array( $row ) && ( $row['id'] ?? '' ) === $palette_id ) {
				$existing[ $idx ] = $palette;
				$replaced = true;
				break;
			}
		}
		if ( ! $replaced ) $existing[] = $palette;
		update_option( 'bricks_color_palette', array_values( $existing ), false );

		return count( $palette['colors'] );
	}

	/**
	 * Write CSS custom properties to bricks_global_variables.
	 */
	private static function write_global_variables( array $colors, Candidate $candidate ): int {
		$existing = (array) get_option( 'bricks_global_variables', [] );
		$slug     = preg_replace( '/[^a-z0-9]+/', '-', strtolower( $candidate->id ) ) ?: 'therum';

		// Build the new set keyed by id so we can update-by-id below instead
		// of appending. Repeated apply() calls of the same kit would otherwise
		// pile duplicate CSS variables into the option indefinitely.
		$new_vars = [];
		foreach ( $colors as $i => $c ) {
			$value = (string) ( $c['value'] ?? '' );
			if ( $value === '' ) continue;
			$id = $slug . '-color-' . ( $i + 1 );
			$new_vars[ $id ] = [
				'id'       => $id,
				'name'     => '--' . $slug . '-color-' . ( $i + 1 ),
				'value'    => $value,
				'category' => $slug,
			];
		}

		$families = $candidate->data['typography']['families'] ?? [];
		if ( is_array( $families ) && ! empty( $families[0]['value'] ) ) {
			$id = $slug . '-font-family';
			$new_vars[ $id ] = [
				'id'       => $id,
				'name'     => '--' . $slug . '-font-family',
				'value'    => (string) $families[0]['value'],
				'category' => $slug,
			];
		}

		// Merge: replace any existing entry whose id matches; preserve everything
		// else (other kits, hand-edited vars).
		$out = [];
		foreach ( $existing as $row ) {
			if ( is_array( $row ) && isset( $row['id'] ) && isset( $new_vars[ $row['id'] ] ) ) {
				$out[] = $new_vars[ $row['id'] ];
				unset( $new_vars[ $row['id'] ] );
			} else {
				$out[] = $row;
			}
		}
		foreach ( $new_vars as $row ) $out[] = $row;

		update_option( 'bricks_global_variables', $out, false );
		return count( $colors ) + ( $families && ! empty( $families[0]['value'] ) ? 1 : 0 );
	}

	private static function write_theme_styles( Candidate $candidate ): bool {
		$existing = (array) get_option( 'bricks_theme_styles', [] );
		$families = $candidate->data['typography']['families'] ?? [];
		$sizes    = $candidate->data['typography']['sizes']    ?? [];

		$primary_family = $families[0]['value'] ?? null;
		$base_size      = $sizes[0]['value']    ?? null;

		if ( $primary_family === null && $base_size === null ) return false;

		$style_id = 'therum_' . $candidate->id;
		$existing[ $style_id ] = [
			'id'   => $style_id,
			'name' => $candidate->label,
			'settings' => array_filter( [
				'typography_font_family' => $primary_family,
				'typography_font_size'   => $base_size,
			] ),
		];
		update_option( 'bricks_theme_styles', $existing, false );
		return true;
	}
}
