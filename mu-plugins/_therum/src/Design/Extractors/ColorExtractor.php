<?php
declare( strict_types=1 );

namespace Therum\Design\Extractors;

use Therum\Design\Extractor;

/**
 * Extracts colour tokens from Bricks element settings.
 *
 * Looks for settings keys commonly associated with colour values, parses any
 * hex / rgb / rgba / CSS-var inputs into a normalised hex (or var keyword),
 * and tracks frequency. The output is a frequency-sorted list ready for
 * downstream clustering in the review step.
 *
 * Clustering threshold (ΔE2000) is intentionally NOT applied here — that's a
 * review-time decision the user makes interactively. Phase 2.5 v1 emits raw
 * frequency-sorted distinct values; the review tool exposes a threshold knob.
 */
final class ColorExtractor implements Extractor {

	/** Settings sub-key fragments that indicate a colour value. */
	private const COLOR_KEY_HINTS = [
		'color',
		'background',
		'backgroundcolor',
		'bordercolor',
		'fill',
		'stroke',
		'shadow',
		'palette',
		'gradient',
	];

	/** @var array<string, int> normalised value → count */
	private array $counts = [];

	public function accept( string $path, string $value, array $element_context ): void {
		$path_low = strtolower( $path );

		$looks_like_color_key = false;
		foreach ( self::COLOR_KEY_HINTS as $hint ) {
			if ( str_contains( $path_low, $hint ) ) {
				$looks_like_color_key = true;
				break;
			}
		}

		// Bricks colours often arrive as nested `{ rgb: 'rgba(...)', hex: '#...' }`
		// arrays. We get string leaves from those; `hex` and `rgb` keys are useful
		// even if the parent key isn't obviously colour-named.
		if ( ! $looks_like_color_key ) {
			$last_segment = strtolower( (string) substr( $path, (int) strrpos( $path, '.' ) + 1 ) );
			if ( in_array( $last_segment, [ 'hex', 'rgb', 'rgba', 'hsl', 'hsla' ], true ) ) {
				$looks_like_color_key = true;
			}
		}

		if ( ! $looks_like_color_key ) return;

		$normalised = self::normalise( $value );
		if ( $normalised === '' ) return;

		$this->counts[ $normalised ] = ( $this->counts[ $normalised ] ?? 0 ) + 1;
	}

	public function tokens(): array {
		arsort( $this->counts );

		$tokens = [];
		foreach ( $this->counts as $value => $count ) {
			$tokens[] = [
				'value' => $value,
				'count' => $count,
				'type'  => self::value_type( $value ),
			];
		}

		return [
			'colors' => [
				'distinct_count' => count( $tokens ),
				'tokens'         => $tokens,
			],
		];
	}

	/**
	 * Normalise a raw colour string. Returns:
	 *   - lowercase #rrggbb for hex inputs (also expanding #rgb → #rrggbb)
	 *   - the original string for rgb()/rgba()/hsl()/hsla()/var() (lowercased + space-trimmed)
	 *   - '' if the value doesn't look like a colour
	 */
	public static function normalise( string $raw ): string {
		$v = trim( $raw );
		if ( $v === '' ) return '';

		// Hex
		if ( preg_match( '/^#([0-9a-f]{3}|[0-9a-f]{6}|[0-9a-f]{8})$/i', $v ) ) {
			$v = strtolower( $v );
			if ( strlen( $v ) === 4 ) {
				// #rgb → #rrggbb
				return '#' . $v[1] . $v[1] . $v[2] . $v[2] . $v[3] . $v[3];
			}
			return $v;
		}

		$lower = strtolower( $v );

		// rgb / rgba / hsl / hsla / var()
		if ( preg_match( '/^(rgba?|hsla?|var)\s*\(/', $lower ) ) {
			// Collapse whitespace inside the parentheses for stable de-dup keys.
			return (string) preg_replace( '/\s+/', '', $lower );
		}

		return '';
	}

	private static function value_type( string $normalised ): string {
		if ( str_starts_with( $normalised, '#' ) ) return 'hex';
		if ( str_starts_with( $normalised, 'rgb' ) ) return 'rgb';
		if ( str_starts_with( $normalised, 'hsl' ) ) return 'hsl';
		if ( str_starts_with( $normalised, 'var' ) ) return 'var';
		return 'other';
	}
}
