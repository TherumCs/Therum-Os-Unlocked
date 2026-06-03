<?php
declare( strict_types=1 );

namespace Therum\Design\Extractors;

use Therum\Design\Extractor;

/**
 * Extracts spacing-axis tokens: padding, margin, gap.
 *
 * Tracks frequency per axis. Bricks stores spacing as objects with `top`,
 * `right`, `bottom`, `left` leaves, plus a `unit` sibling — we get each
 * individual leaf and the unit. Phase 2.5 v1 reports raw values; review-step
 * UX surfaces a "4px grid conformance" check.
 */
final class SpacingExtractor implements Extractor {

	private const SPACING_HINTS = [ 'padding', 'margin', 'gap' ];
	private const NUMERIC_KEYS  = [ 'top', 'right', 'bottom', 'left' ];

	/** @var array<string,int> normalised "$axis:$value" → count  (axis = padding/margin/gap) */
	private array $counts = [];

	public function accept( string $path, string $value, array $element_context ): void {
		$path_low = strtolower( $path );

		$axis = null;
		foreach ( self::SPACING_HINTS as $hint ) {
			if ( str_contains( $path_low, $hint ) ) { $axis = $hint; break; }
		}
		if ( $axis === null ) return;

		// We only care about numeric leaves; ignore `unit`, etc.
		$last = strtolower( (string) substr( $path, (int) strrpos( $path, '.' ) + 1 ) );
		if ( ! in_array( $last, self::NUMERIC_KEYS, true ) ) {
			// Single-shorthand value (gap: 16) — keep
			if ( ! is_numeric( $value ) ) return;
		}

		$norm = trim( $value );
		if ( $norm === '' || $norm === '0' ) return;

		$key = $axis . ':' . $norm;
		$this->counts[ $key ] = ( $this->counts[ $key ] ?? 0 ) + 1;
	}

	public function tokens(): array {
		arsort( $this->counts );

		$by_axis = [ 'padding' => [], 'margin' => [], 'gap' => [] ];
		foreach ( $this->counts as $key => $n ) {
			[ $axis, $value ] = explode( ':', $key, 2 );
			$by_axis[ $axis ][] = [ 'value' => $value, 'count' => $n ];
		}

		// 4px-conformance check: how many distinct values are 4-multiples?
		$all_values = [];
		foreach ( $by_axis as $list ) foreach ( $list as $row ) $all_values[] = $row['value'];
		$conformance = self::four_px_conformance( $all_values );

		return [
			'spacing' => [
				'by_axis'             => $by_axis,
				'four_px_conformance' => $conformance,
			],
		];
	}

	/**
	 * @param list<string> $values
	 * @return array{total: int, conforming: int, ratio: float}
	 */
	private static function four_px_conformance( array $values ): array {
		$total = $conforming = 0;
		foreach ( $values as $v ) {
			$num = (float) preg_replace( '/[^0-9.]/', '', $v );
			if ( $num <= 0 ) continue;
			$total++;
			if ( fmod( $num, 4 ) === 0.0 ) $conforming++;
		}
		return [
			'total'      => $total,
			'conforming' => $conforming,
			'ratio'      => $total > 0 ? round( $conforming / $total, 3 ) : 0.0,
		];
	}
}
