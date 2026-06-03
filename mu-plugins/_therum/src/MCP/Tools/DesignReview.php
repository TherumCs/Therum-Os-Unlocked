<?php
declare( strict_types=1 );

namespace Therum\MCP\Tools;

use Therum\Design\CandidateRepository;
use Therum\MCP\McpError;
use Therum\MCP\Tool;

/**
 * therum.design.review — fetch a derived candidate kit for inspection.
 *
 * Returns the full token shape (frequency-sorted colours, typography
 * histograms, spacing axes) so a human (via the MCP client) can decide what
 * to apply. The Apply tool takes a refined subset.
 *
 * Scope: mcp.read
 */
final class DesignReview extends Tool {

	public function name(): string {
		return 'therum.design.review';
	}

	public function description(): string {
		return 'Fetch a derived design candidate by ID. Returns the full token shape: distinct colors (frequency-sorted), typography histogram, spacing axes with 4px-conformance ratio. Use this to decide which subset to apply via therum.design.apply.';
	}

	public function input_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'candidate_id' => [
					'type'        => 'string',
					'description' => 'Candidate ID returned by therum.design.derive.',
				],
				'list' => [
					'type'        => 'boolean',
					'description' => 'When true, ignore candidate_id and return the list of available candidates with summary stats.',
				],
			],
			'required'   => [],
		];
	}

	public function required_scope(): string {
		return 'mcp.read';
	}

	public function call( array $arguments ): array {
		$list_mode = ! empty( $arguments['list'] );

		if ( $list_mode ) {
			$candidates = CandidateRepository::list();
			$text = empty( $candidates )
				? 'No candidates yet — run therum.design.derive first.'
				: "Available candidates:\n\n" . implode( "\n", array_map(
					fn( $c ) => sprintf( '  %s — %s (%s)', $c['id'], $c['label'], $c['derived_at'] ),
					$candidates
				) );
			return [
				'content' => [
					[ 'type' => 'text', 'text' => $text ],
				],
				'structuredContent' => [
					'candidates' => array_values( $candidates ),
				],
			];
		}

		$id = (string) ( $arguments['candidate_id'] ?? '' );
		if ( $id === '' ) {
			throw new McpError( -32602, 'Provide "candidate_id", or set "list": true.' );
		}

		$candidate = CandidateRepository::find( $id );
		if ( $candidate === null ) {
			throw new McpError( -32602, "Candidate {$id} not found." );
		}

		$colors_top = array_slice( $candidate->data['colors']['tokens'] ?? [], 0, 10 );
		$summary = sprintf(
			"Candidate %s — %s\n  Derived: %s\n  Pages scanned: %d\n  Elements walked: %d\n  Distinct colors: %d (top 10 shown in structuredContent)\n  Top color: %s",
			$candidate->id,
			$candidate->label,
			$candidate->derived_at,
			$candidate->stats['pages_scanned']   ?? 0,
			$candidate->stats['elements_walked'] ?? 0,
			count( $candidate->data['colors']['tokens'] ?? [] ),
			$colors_top[0]['value'] ?? '—'
		);

		return [
			'content' => [
				[ 'type' => 'text', 'text' => $summary ],
			],
			'structuredContent' => $candidate->to_array(),
		];
	}
}
