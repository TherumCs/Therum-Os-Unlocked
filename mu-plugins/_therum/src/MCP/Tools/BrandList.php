<?php
declare( strict_types=1 );

namespace Therum\MCP\Tools;

use Therum\MCP\Tool;

/**
 * therum.brand.list — list the design kits known to Therum.
 *
 * Reads:
 *   - Stored kits in wp_options['therum_design_kits'] (populated by
 *     Phase 2.5 `design.derive` pipeline → `design.apply`).
 *   - Hard-coded brand registries via the `therum_brand_registry` filter,
 *     so existing therum-design.php brand metadata surfaces here without
 *     needing to be persisted.
 *
 * Scope: mcp.read
 */
final class BrandList extends Tool {

	public function name(): string {
		return 'therum.brand.list';
	}

	public function description(): string {
		return 'List all design kits / brands known to Therum. Includes stored kits derived via design.derive and any kits registered programmatically via the therum_brand_registry filter (e.g. sidemoney, bam, theme defaults).';
	}

	public function input_schema(): array {
		return [
			'type'       => 'object',
			'properties' => new \stdClass(),
			'required'   => [],
		];
	}

	public function required_scope(): string {
		return 'mcp.read';
	}

	public function call( array $arguments ): array {
		$kits = self::collect();

		$lines = [];
		foreach ( $kits as $id => $kit ) {
			$lines[] = sprintf(
				'%s — %s (%s)',
				$id,
				$kit['label'] ?? $id,
				$kit['source'] ?? 'unknown'
			);
		}

		$text = empty( $kits )
			? 'No design kits registered yet. Run therum.design.derive to create one from existing pages, or register one via the therum_brand_registry filter.'
			: implode( "\n", $lines );

		return [
			'content' => [
				[ 'type' => 'text', 'text' => $text ],
			],
			'structuredContent' => [
				'count' => count( $kits ),
				'kits'  => $kits,
			],
		];
	}

	/**
	 * @return array<string, array{label: string, source: string, derived_at?: string}>
	 */
	private static function collect(): array {
		// 1. Stored kits (Phase 2.5 output)
		$stored = (array) get_option( 'therum_design_kits', [] );

		// 2. Registered via filter (existing brand registries in therum-design)
		$registered = (array) apply_filters( 'therum_brand_registry', [] );

		$out = [];

		foreach ( $stored as $id => $kit ) {
			$out[ (string) $id ] = [
				'label'      => (string) ( $kit['label'] ?? $id ),
				'source'     => 'derived',
				'derived_at' => (string) ( $kit['derived_at'] ?? '' ),
			];
		}

		foreach ( $registered as $id => $kit ) {
			// Filter-registered kits override stored ones of the same ID
			$out[ (string) $id ] = [
				'label'  => (string) ( $kit['label'] ?? $id ),
				'source' => 'registered',
			];
		}

		ksort( $out );
		return $out;
	}
}
