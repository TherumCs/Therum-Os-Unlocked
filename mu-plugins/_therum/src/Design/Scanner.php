<?php
declare( strict_types=1 );

namespace Therum\Design;

/**
 * Walks Bricks element trees across pages, feeding each (key, value) pair into
 * the registered Extractors. The output is a stream of token candidates per
 * extractor, ready for clustering / scaling decisions.
 *
 * Bricks stores page content as a FLAT array of elements in postmeta
 * `_bricks_page_content_2`, each with `settings` (nested key-value pairs).
 * We use `array_walk_recursive` to surface every leaf in settings, which is
 * the right granularity for color / font / spacing extraction.
 *
 * @see ColorExtractor
 * @see TypographyExtractor
 * @see SpacingExtractor
 */
final class Scanner {

	/** @var list<Extractor> */
	private array $extractors = [];

	public int $pages_scanned   = 0;
	public int $elements_walked = 0;

	public function add_extractor( Extractor $ext ): void {
		$this->extractors[] = $ext;
	}

	/**
	 * Scan all published pages + posts + bricks templates.
	 *
	 * @param  array<int> $explicit_ids  if non-empty, scan ONLY these post IDs
	 * @return void
	 */
	public function scan( array $explicit_ids = [] ): void {
		$ids = empty( $explicit_ids )
			? $this->collect_target_ids()
			: array_map( 'intval', $explicit_ids );

		foreach ( $ids as $post_id ) {
			$this->scan_post( (int) $post_id );
			$this->pages_scanned++;
		}
	}

	/**
	 * @return list<int>
	 */
	private function collect_target_ids(): array {
		global $wpdb;

		$post_types = (array) apply_filters( 'therum_design_scan_post_types', [
			'page', 'post', 'bricks_template', 'case_study',
		] );

		$placeholders = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );
		$sql = $wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts}
			   WHERE post_status = 'publish'
			     AND post_type IN ({$placeholders})",
			...$post_types
		);

		$ids = (array) $wpdb->get_col( $sql );
		return array_map( 'intval', $ids );
	}

	private function scan_post( int $post_id ): void {
		$bricks_content = get_post_meta( $post_id, '_bricks_page_content_2', true );
		if ( ! is_array( $bricks_content ) ) return;

		foreach ( $bricks_content as $element ) {
			if ( ! is_array( $element ) ) continue;
			$settings = $element['settings'] ?? null;
			if ( ! is_array( $settings ) ) continue;

			$this->walk_settings( $settings, '', $element );
			$this->elements_walked++;
		}

		// Also scan header + footer templates' meta if present.
		foreach ( [ '_bricks_page_header_2', '_bricks_page_footer_2' ] as $key ) {
			$chrome = get_post_meta( $post_id, $key, true );
			if ( ! is_array( $chrome ) ) continue;
			foreach ( $chrome as $element ) {
				if ( ! is_array( $element ) ) continue;
				$settings = $element['settings'] ?? null;
				if ( ! is_array( $settings ) ) continue;
				$this->walk_settings( $settings, '', $element );
				$this->elements_walked++;
			}
		}
	}

	/**
	 * Recursive walker that prefixes nested keys with dotted paths so
	 * extractors can match on hierarchical context (e.g. `_typography.color`).
	 *
	 * @param array<string,mixed> $settings
	 * @param array<string,mixed> $element_context  full element row (id/name/etc.)
	 */
	private function walk_settings( array $settings, string $prefix, array $element_context ): void {
		foreach ( $settings as $key => $value ) {
			$path = $prefix === '' ? (string) $key : ( $prefix . '.' . $key );

			if ( is_array( $value ) ) {
				$this->walk_settings( $value, $path, $element_context );
				continue;
			}

			if ( is_string( $value ) || is_int( $value ) || is_float( $value ) ) {
				foreach ( $this->extractors as $ext ) {
					$ext->accept( $path, (string) $value, $element_context );
				}
			}
		}
	}
}
