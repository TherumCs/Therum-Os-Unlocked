<?php
declare( strict_types=1 );

namespace Therum\DesignSystem;

/**
 * Canonical token shape for the Therum Design System.
 *
 * Everything in the system flows through this schema. Adapters (Bricks, CSS)
 * read from it; the UI binds editors to it; persistence stores exactly this.
 *
 * Designed to be CMS-agnostic — no Bricks-specific keys here. Bricks integration
 * happens in BricksAdapter (one-way sync down to bricks_color_palette etc.).
 *
 * Shape:
 *   [
 *     'version'  => '1',
 *     'meta'     => [ 'name' => string, 'updated_at' => iso8601 ],
 *     'colors'   => [ ['id','name','value','role'?], ... ],
 *     'fonts'    => [ ['id','name','stack','source'?], ... ],
 *     'sizes'    => [ ['id','name','value'], ... ],
 *     'spacing'  => [ ['id','name','value'], ... ],
 *     'radii'    => [ ['id','name','value'], ... ],
 *     'shadows'  => [ ['id','name','value'], ... ],
 *   ]
 *
 * Each token has:
 *   - id: stable slug (used in CSS var names: --th-color-{id})
 *   - name: human label
 *   - value: the raw CSS value
 *   - role (colors only, optional): semantic role — primary, accent, surface, text, etc.
 *   - stack (fonts only): full CSS font-family declaration
 *   - source (fonts only, optional): 'system' | 'google' | 'local'
 */
final class Schema {

	public const VERSION = '1';

	/** CSS variable prefix — all emitted vars use this. */
	public const VAR_PREFIX = 'th';

	/** Color semantic roles surfaced as separate `--th-{role}` vars. */
	public const COLOR_ROLES = [ 'primary', 'accent', 'surface', 'surface-2', 'text', 'text-2', 'text-3', 'border', 'success', 'warning', 'error' ];

	/**
	 * Empty-but-valid system. Use as fallback when nothing is stored yet.
	 *
	 * @return array<string, mixed>
	 */
	public static function empty(): array {
		return [
			'version' => self::VERSION,
			'meta'    => [
				'name'       => 'Untitled system',
				'updated_at' => '',
			],
			'colors'  => [],
			'fonts'   => [],
			'sizes'   => [],
			'spacing' => [],
			'radii'   => [],
			'shadows' => [],
		];
	}

	/**
	 * Sensible default system — used when a brand-new site has nothing at all.
	 * Neutral palette + system font stack so the page renders cleanly out of the box.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return [
			'version' => self::VERSION,
			'meta'    => [
				'name'       => 'Default system',
				'updated_at' => '',
			],
			'colors'  => [
				[ 'id' => 'primary',    'name' => 'Primary',    'value' => '#0a0a0a', 'role' => 'primary' ],
				[ 'id' => 'accent',     'name' => 'Accent',     'value' => '#e83b3b', 'role' => 'accent' ],
				[ 'id' => 'surface',    'name' => 'Surface',    'value' => '#ffffff', 'role' => 'surface' ],
				[ 'id' => 'surface-2',  'name' => 'Surface 2',  'value' => '#fafafa', 'role' => 'surface-2' ],
				[ 'id' => 'text',       'name' => 'Text',       'value' => '#0a0a0a', 'role' => 'text' ],
				[ 'id' => 'text-2',     'name' => 'Text 2',     'value' => '#666666', 'role' => 'text-2' ],
				[ 'id' => 'text-3',     'name' => 'Text 3',     'value' => '#999999', 'role' => 'text-3' ],
				[ 'id' => 'border',     'name' => 'Border',     'value' => 'rgba(0,0,0,.08)', 'role' => 'border' ],
			],
			'fonts'   => [
				[ 'id' => 'display', 'name' => 'Display', 'stack' => '"Inter Tight", -apple-system, BlinkMacSystemFont, sans-serif', 'source' => 'google' ],
				[ 'id' => 'body',    'name' => 'Body',    'stack' => '-apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif',     'source' => 'system' ],
				[ 'id' => 'mono',    'name' => 'Mono',    'stack' => '"JetBrains Mono", ui-monospace, SFMono-Regular, monospace',     'source' => 'google' ],
			],
			'sizes'   => [
				[ 'id' => 'xs', 'name' => 'XS', 'value' => '12px' ],
				[ 'id' => 'sm', 'name' => 'SM', 'value' => '14px' ],
				[ 'id' => 'md', 'name' => 'MD', 'value' => '16px' ],
				[ 'id' => 'lg', 'name' => 'LG', 'value' => '20px' ],
				[ 'id' => 'xl', 'name' => 'XL', 'value' => '28px' ],
				[ 'id' => 'xxl','name' => '2XL','value' => '40px' ],
			],
			'spacing' => [
				[ 'id' => '1', 'name' => '1', 'value' => '4px' ],
				[ 'id' => '2', 'name' => '2', 'value' => '8px' ],
				[ 'id' => '3', 'name' => '3', 'value' => '12px' ],
				[ 'id' => '4', 'name' => '4', 'value' => '16px' ],
				[ 'id' => '5', 'name' => '5', 'value' => '24px' ],
				[ 'id' => '6', 'name' => '6', 'value' => '32px' ],
				[ 'id' => '7', 'name' => '7', 'value' => '48px' ],
				[ 'id' => '8', 'name' => '8', 'value' => '64px' ],
			],
			'radii'   => [
				[ 'id' => 'sm', 'name' => 'SM', 'value' => '4px' ],
				[ 'id' => 'md', 'name' => 'MD', 'value' => '8px' ],
				[ 'id' => 'lg', 'name' => 'LG', 'value' => '14px' ],
				[ 'id' => 'pill','name'=> 'Pill','value'=> '999px' ],
			],
			'shadows' => [
				[ 'id' => 'sm', 'name' => 'SM', 'value' => '0 1px 2px rgba(0,0,0,.04)' ],
				[ 'id' => 'md', 'name' => 'MD', 'value' => '0 4px 12px rgba(0,0,0,.06)' ],
				[ 'id' => 'lg', 'name' => 'LG', 'value' => '0 16px 40px rgba(0,0,0,.10)' ],
			],
		];
	}

	/**
	 * Sanitise an inbound system blob — drop unexpected keys, coerce types,
	 * normalise IDs. Never trust input directly; always run through this.
	 *
	 * @param  array<string, mixed> $in
	 * @return array<string, mixed>
	 */
	public static function sanitise( array $in ): array {
		$out = self::empty();

		// meta
		$meta = (array) ( $in['meta'] ?? [] );
		$out['meta']['name'] = self::clean_string( $meta['name'] ?? 'Untitled system', 80 );

		// token buckets
		foreach ( [ 'colors', 'fonts', 'sizes', 'spacing', 'radii', 'shadows' ] as $bucket ) {
			$rows = (array) ( $in[ $bucket ] ?? [] );
			$out[ $bucket ] = self::sanitise_bucket( $bucket, $rows );
		}

		return $out;
	}

	/**
	 * @param  array<int|string, mixed> $rows
	 * @return list<array<string, string>>
	 */
	private static function sanitise_bucket( string $bucket, array $rows ): array {
		$out = [];
		$seen_ids = [];
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) continue;
			$id    = self::clean_id( (string) ( $row['id']    ?? '' ) );
			$name  = self::clean_string( (string) ( $row['name']  ?? '' ), 60 );
			$value = self::clean_value( (string) ( $row['value'] ?? ( $row['stack'] ?? '' ) ), 500 );
			if ( $id === '' || $value === '' ) continue;

			// de-dup IDs by suffixing
			$base = $id; $n = 2;
			while ( isset( $seen_ids[ $id ] ) ) {
				$id = $base . '-' . $n++;
			}
			$seen_ids[ $id ] = true;

			$entry = [ 'id' => $id, 'name' => ( $name !== '' ? $name : $id ) ];

			if ( $bucket === 'fonts' ) {
				$entry['stack']  = $value;
				$source          = (string) ( $row['source'] ?? '' );
				$entry['source'] = in_array( $source, [ 'system', 'google', 'local' ], true ) ? $source : 'system';
			} else {
				$entry['value'] = $value;
			}

			if ( $bucket === 'colors' ) {
				$role = (string) ( $row['role'] ?? '' );
				if ( in_array( $role, self::COLOR_ROLES, true ) ) $entry['role'] = $role;
			}

			$out[] = $entry;
		}
		return $out;
	}

	private static function clean_id( string $s ): string {
		$s = strtolower( $s );
		$s = preg_replace( '/[^a-z0-9_-]+/', '-', $s ) ?? '';
		$s = trim( $s, '-' );
		return substr( $s, 0, 40 );
	}

	private static function clean_string( string $s, int $max ): string {
		$s = wp_strip_all_tags( $s );
		return mb_substr( trim( $s ), 0, $max );
	}

	private static function clean_value( string $s, int $max ): string {
		// CSS values: trim, strip newlines, cap length. No HTML.
		$s = wp_strip_all_tags( $s );
		$s = str_replace( [ "\n", "\r" ], ' ', $s );
		return mb_substr( trim( $s ), 0, $max );
	}
}
