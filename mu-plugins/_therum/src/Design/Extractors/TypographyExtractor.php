<?php
declare( strict_types=1 );

namespace Therum\Design\Extractors;

use Therum\Design\Extractor;

/**
 * Extracts typography tokens: font-family, font-size, font-weight, line-height.
 *
 * Tracks frequency per axis. Phase 2.5 v1 produces the raw histogram per axis;
 * the review tool exposes "actual scale vs suggested clean scale" toggle for
 * sizes (modular scale interpolation deferred to v2).
 */
final class TypographyExtractor implements Extractor {

	private const FAMILY_HINTS = [ 'fontfamily', 'font-family', 'family' ];
	private const SIZE_HINTS   = [ 'fontsize', 'font-size', 'size' ];
	private const WEIGHT_HINTS = [ 'fontweight', 'font-weight', 'weight' ];
	private const LH_HINTS     = [ 'lineheight', 'line-height' ];

	/** @var array<string,int> */ private array $families = [];
	/** @var array<string,int> */ private array $sizes    = [];
	/** @var array<string,int> */ private array $weights  = [];
	/** @var array<string,int> */ private array $line_heights = [];

	public function accept( string $path, string $value, array $element_context ): void {
		$path_low = strtolower( $path );
		$v = trim( $value );
		if ( $v === '' ) return;

		// Only descend into typography contexts; avoid catching every "size" in
		// the schema (e.g. icon size, gap size).
		$in_typography_block = str_contains( $path_low, 'typograph' ) || str_contains( $path_low, 'text' );

		if ( self::matches( $path_low, self::FAMILY_HINTS ) ) {
			$this->families[ $v ] = ( $this->families[ $v ] ?? 0 ) + 1;
			return;
		}
		if ( self::matches( $path_low, self::SIZE_HINTS ) && $in_typography_block ) {
			$norm = self::normalise_size( $v );
			if ( $norm !== '' ) $this->sizes[ $norm ] = ( $this->sizes[ $norm ] ?? 0 ) + 1;
			return;
		}
		if ( self::matches( $path_low, self::WEIGHT_HINTS ) ) {
			$this->weights[ $v ] = ( $this->weights[ $v ] ?? 0 ) + 1;
			return;
		}
		if ( self::matches( $path_low, self::LH_HINTS ) ) {
			$this->line_heights[ $v ] = ( $this->line_heights[ $v ] ?? 0 ) + 1;
			return;
		}
	}

	public function tokens(): array {
		return [
			'typography' => [
				'families'     => self::sorted( $this->families ),
				'sizes'        => self::sorted( $this->sizes ),
				'weights'      => self::sorted( $this->weights ),
				'line_heights' => self::sorted( $this->line_heights ),
			],
		];
	}

	private static function matches( string $path_low, array $hints ): bool {
		foreach ( $hints as $h ) if ( str_contains( $path_low, $h ) ) return true;
		return false;
	}

	private static function normalise_size( string $v ): string {
		$v = trim( strtolower( $v ) );
		// Keep px / rem / em / % values verbatim; the user can convert in review.
		if ( preg_match( '/^[\d.]+\s*(px|rem|em|%)?$/', $v ) ) {
			return preg_replace( '/\s+/', '', $v ) ?? $v;
		}
		return '';
	}

	/**
	 * @param  array<string,int> $counts
	 * @return list<array{value: string, count: int}>
	 */
	private static function sorted( array $counts ): array {
		arsort( $counts );
		$out = [];
		foreach ( $counts as $v => $n ) $out[] = [ 'value' => (string) $v, 'count' => $n ];
		return $out;
	}
}
