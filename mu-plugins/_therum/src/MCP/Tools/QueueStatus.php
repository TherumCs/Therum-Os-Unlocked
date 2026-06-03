<?php
declare( strict_types=1 );

namespace Therum\MCP\Tools;

use Therum\MCP\McpError;
use Therum\MCP\Tool;
use Therum\Queue;

/**
 * therum.queue.status — poll the status of one queued job, or get queue-level
 * aggregate counts. Lets MCP clients drive async tools (source.rebuild,
 * design.derive) without server-sent events: enqueue, poll until done.
 *
 * Scope: mcp.read
 */
final class QueueStatus extends Tool {

	public function name(): string {
		return 'therum.queue.status';
	}

	public function description(): string {
		return 'Poll the status of a queued job by ID, or get aggregate counts (pending/running/completed/failed/dead) for a queue. Use this to track async tools like therum.source.rebuild and therum.design.derive.';
	}

	public function input_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'job_id' => [
					'type'        => 'integer',
					'description' => 'Job ID returned by an earlier async tool call. Mutually exclusive with queue.',
				],
				'queue' => [
					'type'        => 'string',
					'description' => 'Queue name for aggregate counts (e.g. "mcp", "default"). Mutually exclusive with job_id.',
				],
			],
			'required'   => [],
		];
	}

	public function required_scope(): string {
		return 'mcp.read';
	}

	public function call( array $arguments ): array {
		$job_id = isset( $arguments['job_id'] ) ? (int) $arguments['job_id'] : 0;
		$queue  = isset( $arguments['queue'] )  ? (string) $arguments['queue'] : '';

		if ( $job_id > 0 ) {
			return $this->job_status( $job_id );
		}

		if ( $queue !== '' ) {
			return $this->queue_aggregates( $queue );
		}

		// Neither specified — return aggregates across all queues.
		return $this->queue_aggregates( null );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function job_status( int $job_id ): array {
		$job = Queue::find( $job_id );

		if ( $job === null ) {
			throw new McpError(
				-32602,
				"Job #{$job_id} not found.",
				[ 'job_id' => $job_id ]
			);
		}

		$text = sprintf(
			"Job #%d\nQueue: %s\nHandler: %s\nStatus: %s\nAttempts: %d / %d\nCreated: %s\nLast error: %s",
			$job->id,
			$job->queue,
			$job->handler,
			$job->status,
			$job->attempts,
			$job->max_attempts,
			$job->created_at,
			$job->last_error ?? '—'
		);

		return [
			'content' => [
				[ 'type' => 'text', 'text' => $text ],
			],
			'structuredContent' => [
				'job_id'       => $job->id,
				'queue'        => $job->queue,
				'handler'      => $job->handler,
				'status'       => $job->status,
				'is_terminal'  => $job->is_terminal(),
				'attempts'     => $job->attempts,
				'max_attempts' => $job->max_attempts,
				'created_at'   => $job->created_at,
				'completed_at' => $job->completed_at,
				'failed_at'    => $job->failed_at,
				'last_error'   => $job->last_error,
			],
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function queue_aggregates( ?string $queue ): array {
		$counts = Queue::stats( $queue );

		$label = $queue !== null ? "queue=$queue" : 'all queues';
		$text  = sprintf(
			"Queue counts (%s):\n  pending:   %d\n  running:   %d\n  completed: %d\n  failed:    %d\n  dead:      %d",
			$label,
			$counts['pending']   ?? 0,
			$counts['running']   ?? 0,
			$counts['completed'] ?? 0,
			$counts['failed']    ?? 0,
			$counts['dead']      ?? 0
		);

		return [
			'content' => [
				[ 'type' => 'text', 'text' => $text ],
			],
			'structuredContent' => [
				'queue'  => $queue,
				'counts' => $counts,
			],
		];
	}
}
