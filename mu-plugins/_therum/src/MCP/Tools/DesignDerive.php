<?php
declare( strict_types=1 );

namespace Therum\MCP\Tools;

use Therum\Design\Candidate;
use Therum\Design\CandidateRepository;
use Therum\Design\Derive;
use Therum\MCP\McpError;
use Therum\MCP\Tool;
use Therum\Queue;

/**
 * therum.design.derive — scan the site and produce a candidate design kit.
 *
 * The differentiator. Reads every published page's Bricks element tree,
 * aggregates color / typography / spacing frequency, and writes a candidate
 * kit to wp_options['therum_design_candidates']. The candidate is the input
 * to therum.design.review (read) and therum.design.apply (write).
 *
 * Behaviour:
 *   - When source = "page-list" with ≤ 5 ids → runs SYNC, returns candidate ID
 *   - Otherwise → enqueues via Therum\Queue (queue=mcp), returns job ID
 *
 * Sync small-list path keeps the local dev workflow snappy (most case-study
 * derives target one page). The queue path handles big-site derives without
 * MCP timeouts.
 *
 * Scope: mcp.design.derive
 */
final class DesignDerive extends Tool {

	public const HANDLER_ID = 'therum.design.derive';
	public const QUEUE_NAME = 'mcp';

	private const SYNC_THRESHOLD = 5;

	public function name(): string {
		return 'therum.design.derive';
	}

	public function description(): string {
		return 'Derive a design system (colors, typography, spacing tokens) from existing Bricks pages. Small page-list runs return synchronously; site-wide runs queue and return a job ID — poll therum.queue.status until done, then call therum.design.review with the candidate ID.';
	}

	public function input_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'source'   => [
					'type'        => 'string',
					'enum'        => [ 'site', 'page-list' ],
					'description' => 'Scope of the scan. "site" walks every published page; "page-list" walks only the post IDs in `post_ids`.',
				],
				'post_ids' => [
					'type'        => 'array',
					'items'       => [ 'type' => 'integer' ],
					'description' => 'Post IDs to scan when source="page-list".',
				],
				'label'    => [
					'type'        => 'string',
					'description' => 'Human label for the resulting kit (default: timestamped).',
				],
			],
			'required'   => [],
		];
	}

	public function required_scope(): string {
		return 'mcp.design.derive';
	}

	public function call( array $arguments ): array {
		$source   = (string) ( $arguments['source']   ?? 'site' );
		$post_ids = is_array( $arguments['post_ids'] ?? null )
			? array_map( 'intval', $arguments['post_ids'] )
			: [];
		$label    = (string) ( $arguments['label']    ?? '' );

		// Sync path: page-list with ≤ SYNC_THRESHOLD pages.
		if ( $source === 'page-list' && count( $post_ids ) > 0 && count( $post_ids ) <= self::SYNC_THRESHOLD ) {
			$candidate = Derive::run( [
				'post_ids'    => $post_ids,
				'label'       => $label,
				'source_type' => 'page-list',
			] );
			return self::sync_response( $candidate );
		}

		// Async path: enqueue.
		$job_id = Queue::push(
			self::HANDLER_ID,
			[
				'source'      => $source,
				'post_ids'    => $post_ids,
				'label'       => $label,
				'queued_by'   => get_current_user_id(),
			],
			[ 'queue' => self::QUEUE_NAME, 'max_attempts' => 1 ]
		);

		if ( $job_id === 0 ) {
			throw new McpError( -32000, 'Failed to enqueue derive job.' );
		}

		return [
			'content' => [
				[
					'type' => 'text',
					'text' => sprintf(
						"Derive queued (source=%s).\nJob ID: %d\n\nPoll therum.queue.status { job_id: %d } until status=completed, then read the candidate with therum.design.review { candidate_id: <see job's last_error or recent candidates> }.",
						$source,
						$job_id,
						$job_id
					),
				],
			],
			'structuredContent' => [
				'mode'   => 'async',
				'job_id' => $job_id,
				'queue'  => self::QUEUE_NAME,
			],
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function sync_response( Candidate $c ): array {
		$colors_n  = count( $c->data['colors']['tokens'] ?? [] );
		$fam_n     = count( $c->data['typography']['families'] ?? [] );
		return [
			'content' => [
				[
					'type' => 'text',
					'text' => sprintf(
						"Derived %s (%d pages, %d elements walked).\nDistinct colors: %d · families: %d\n\nReview with: therum.design.review { candidate_id: \"%s\" }\nApply with:  therum.design.apply  { candidate_id: \"%s\" }",
						$c->id,
						$c->stats['pages_scanned']   ?? 0,
						$c->stats['elements_walked'] ?? 0,
						$colors_n,
						$fam_n,
						$c->id,
						$c->id
					),
				],
			],
			'structuredContent' => [
				'mode'         => 'sync',
				'candidate_id' => $c->id,
				'label'        => $c->label,
				'stats'        => $c->stats,
			],
		];
	}

	/**
	 * Queue handler — runs the actual derive in a worker process.
	 *
	 * @param array<string, mixed> $payload
	 */
	public static function handler( array $payload ): void {
		$source   = (string) ( $payload['source']   ?? 'site' );
		$post_ids = is_array( $payload['post_ids'] ?? null )
			? array_map( 'intval', $payload['post_ids'] )
			: [];
		$label    = (string) ( $payload['label']    ?? '' );

		$candidate = Derive::run( [
			'post_ids'    => $source === 'page-list' ? $post_ids : [],
			'label'       => $label,
			'source_type' => $source,
		] );

		// Log the candidate ID so MCP clients polling via queue.status can
		// surface it in last_error field (used here as completion data).
		error_log( '[therum.design.derive] candidate_id=' . $candidate->id );
	}
}
