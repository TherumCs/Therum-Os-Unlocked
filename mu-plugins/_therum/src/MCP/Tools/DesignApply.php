<?php
declare( strict_types=1 );

namespace Therum\MCP\Tools;

use Therum\Design\Apply;
use Therum\Design\CandidateRepository;
use Therum\MCP\McpError;
use Therum\MCP\Tool;

/**
 * therum.design.apply — write a (refined) candidate to Bricks globals.
 *
 * The third step in the derive→review→apply pipeline. Writes to:
 *   - bricks_color_palette
 *   - bricks_global_variables
 *   - (optionally) bricks_theme_styles
 * via the safe update_option() path. Regenerates Bricks CSS files if available.
 *
 * Scope: mcp.write
 */
final class DesignApply extends Tool {

	public function name(): string {
		return 'therum.design.apply';
	}

	public function description(): string {
		return 'Apply a refined candidate to Bricks globals (color palette, CSS variables, optionally theme styles). Use after therum.design.review to write the user-approved subset. Regenerates Bricks CSS files when available.';
	}

	public function input_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'candidate_id'    => [
					'type'        => 'string',
					'description' => 'Candidate ID to apply.',
				],
				'colors_to_write' => [
					'type'        => 'array',
					'description' => 'Optional explicit list of colors to add. If omitted, the top N hex colors from the candidate are used (N configurable via the therum_design_apply_color_count filter).',
					'items'       => [
						'type'       => 'object',
						'properties' => [
							'value' => [ 'type' => 'string' ],
							'label' => [ 'type' => 'string' ],
						],
					],
				],
				'write_typography' => [
					'type'        => 'boolean',
					'description' => 'Whether to write a theme-style entry for typography (default: false).',
				],
			],
			'required'   => [ 'candidate_id' ],
		];
	}

	public function required_scope(): string {
		return 'mcp.write';
	}

	public function call( array $arguments ): array {
		$id = (string) ( $arguments['candidate_id'] ?? '' );
		if ( $id === '' ) {
			throw new McpError( -32602, 'Missing required "candidate_id".' );
		}

		$candidate = CandidateRepository::find( $id );
		if ( $candidate === null ) {
			throw new McpError( -32602, "Candidate {$id} not found." );
		}

		$opts = [];
		if ( isset( $arguments['colors_to_write'] ) && is_array( $arguments['colors_to_write'] ) ) {
			$opts['colors_to_write'] = $arguments['colors_to_write'];
		}
		if ( isset( $arguments['write_typography'] ) ) {
			$opts['write_typography'] = (bool) $arguments['write_typography'];
		}

		$result = Apply::apply( $candidate, $opts );

		$text = sprintf(
			"Applied %s.\n  Colors added: %d\n  Variables added: %d\n  Typography written: %s\n%s",
			$candidate->id,
			$result['colors_added'],
			$result['variables'],
			$result['typography'] ? 'yes' : 'no',
			empty( $result['warnings'] ) ? '' : "\nWarnings:\n  - " . implode( "\n  - ", $result['warnings'] )
		);

		return [
			'content' => [
				[ 'type' => 'text', 'text' => $text ],
			],
			'structuredContent' => $result,
		];
	}
}
