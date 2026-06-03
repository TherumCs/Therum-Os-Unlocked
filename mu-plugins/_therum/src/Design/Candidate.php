<?php
declare( strict_types=1 );

namespace Therum\Design;

/**
 * A candidate design kit — the output of one Derive run, ready for review.
 *
 * NOT a Bricks-shaped artifact: candidates use the intermediate Therum shape
 * (frequency-sorted token lists, per-axis histograms). The Apply step
 * translates a refined candidate into Bricks globals (`bricks_color_palette`,
 * `bricks_theme_styles`, etc.).
 */
final class Candidate {

	/**
	 * @param array<string, mixed>  $data        merged extractor output
	 * @param array<string, mixed>  $source      { type, post_ids?, count }
	 * @param array<string, mixed>  $stats       { pages_scanned, elements_walked }
	 */
	public function __construct(
		public readonly string $id,
		public readonly string $label,
		public readonly string $derived_at,
		public readonly array $data,
		public readonly array $source,
		public readonly array $stats,
	) {}

	/**
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return [
			'id'         => $this->id,
			'label'      => $this->label,
			'derived_at' => $this->derived_at,
			'source'     => $this->source,
			'stats'      => $this->stats,
			'data'       => $this->data,
		];
	}

	/**
	 * @param array<string, mixed> $row
	 */
	public static function from_array( array $row ): self {
		return new self(
			id:         (string) ( $row['id'] ?? '' ),
			label:      (string) ( $row['label'] ?? '' ),
			derived_at: (string) ( $row['derived_at'] ?? '' ),
			data:       is_array( $row['data'] ?? null ) ? $row['data'] : [],
			source:     is_array( $row['source'] ?? null ) ? $row['source'] : [],
			stats:      is_array( $row['stats'] ?? null ) ? $row['stats'] : [],
		);
	}

	public static function new_id(): string {
		return 'kit_' . substr( md5( (string) microtime( true ) . random_int( 0, PHP_INT_MAX ) ), 0, 10 );
	}
}
