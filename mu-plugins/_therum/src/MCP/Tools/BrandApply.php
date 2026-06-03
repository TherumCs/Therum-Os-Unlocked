<?php
declare( strict_types=1 );

namespace Therum\MCP\Tools;

use Therum\Design\Apply;
use Therum\Design\CandidateRepository;
use Therum\MCP\McpError;
use Therum\MCP\Tool;

/**
 * therum.brand.apply — apply a known kit (by ID) to the current site.
 *
 * Convenience wrapper over therum.design.apply: takes a brand/kit ID from
 * the BrandList catalogue (either a derived candidate or a registered brand)
 * and applies it with default options. For more control over which colors /
 * typography rows get written, use therum.design.apply directly.
 *
 * Scope: mcp.write
 */
final class BrandApply extends Tool {

	public function name(): string {
		return 'therum.brand.apply';
	}

	public function description(): string {
		return 'Apply a known brand/kit by ID. Convenience wrapper over therum.design.apply with sensible defaults. Use therum.brand.list to see available IDs.';
	}

	public function input_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'brand_id' => [
					'type'        => 'string',
					'description' => 'Kit ID from therum.brand.list (e.g. "kit_abcdef1234").',
				],
				'scope' => [
					'type'        => 'string',
					'enum'        => [ 'site' ],
					'description' => 'Apply scope (only "site" supported in v1).',
				],
			],
			'required'   => [ 'brand_id' ],
		];
	}

	public function required_scope(): string {
		return 'mcp.write';
	}

	public function call( array $arguments ): array {
		$id = (string) ( $arguments['brand_id'] ?? '' );
		if ( $id === '' ) {
			throw new McpError( -32602, 'Missing required "brand_id".' );
		}

		$candidate = CandidateRepository::find( $id );
		if ( $candidate === null ) {
			throw new McpError( -32602, "Brand/kit {$id} not found. Use therum.brand.list to see available IDs." );
		}

		$result = Apply::apply( $candidate, [ 'write_typography' => true ] );

		$text = sprintf(
			"Applied brand %s (%s).\n  Colors: %d  ·  Variables: %d  ·  Typography: %s",
			$candidate->id,
			$candidate->label,
			$result['colors_added'],
			$result['variables'],
			$result['typography'] ? 'yes' : 'no'
		);

		return [
			'content' => [
				[ 'type' => 'text', 'text' => $text ],
			],
			'structuredContent' => $result,
		];
	}
}
