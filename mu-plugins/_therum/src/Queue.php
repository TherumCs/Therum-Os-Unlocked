<?php
declare( strict_types=1 );

namespace Therum;

use Therum\Queue\HandlerRegistry;
use Therum\Queue\Job;
use Therum\Queue\Repository;

/**
 * Therum Queue — public facade.
 *
 * The persistent job queue that replaces WP-cron for actual workloads. Backed
 * by wp_therum_jobs with row-level locking + exponential backoff retries.
 * Workers run via `wp therum queue:work` (under systemd timer on the VPS;
 * manually in dev). WP-cron is only used for periodic maintenance (stale-lock
 * sweep, completed-job pruning) — never to dispatch real work.
 *
 * Typical use:
 *
 *   Therum\Queue::register_handler( 'design.derive', function( array $payload ): void {
 *       Therum\Design\Derive::run_for_pages( $payload['page_ids'] );
 *   } );
 *
 *   $job_id = Therum\Queue::push( 'design.derive', [ 'page_ids' => [ 12, 34, 56 ] ] );
 *
 *   // Later, in a worker process:
 *   Therum\Queue::work( 'default', max_jobs: 100 );
 *
 * @see Job              value object
 * @see Repository       DB ops
 * @see HandlerRegistry  in-memory handler map
 */
final class Queue {

	/**
	 * Default backoff schedule (seconds): 1m, 2m, 4m, 8m, 16m.
	 * Indexed by attempt count (0-indexed → delay before retry N+1).
	 */
	private const DEFAULT_BACKOFF = [ 60, 120, 240, 480, 960 ];

	public static function install(): void {
		Repository::install();
	}

	/**
	 * Enqueue a job.
	 *
	 * @param  string              $handler_id  must match a registered handler
	 * @param  array<string,mixed> $payload     serialised to JSON
	 * @param  array{
	 *           queue?: string,
	 *           max_attempts?: int,
	 *           delay?: int        // seconds before first run
	 *         } $opts
	 * @return int  job ID (0 on insert failure)
	 */
	public static function push( string $handler_id, array $payload = [], array $opts = [] ): int {
		$queue        = (string) ( $opts['queue']        ?? 'default' );
		$max_attempts = (int)    ( $opts['max_attempts'] ?? 3 );
		$delay        = (int)    ( $opts['delay']        ?? 0 );

		$available_at = $delay > 0
			? gmdate( 'Y-m-d H:i:s', time() + $delay )
			: current_time( 'mysql', true );

		return Repository::insert( $queue, $handler_id, $payload, $max_attempts, $available_at );
	}

	public static function register_handler( string $handler_id, callable $callable ): void {
		HandlerRegistry::register( $handler_id, $callable );
	}

	/**
	 * Process up to $max_jobs from $queue.
	 *
	 * Returns the number of jobs actually processed (whether they succeeded or
	 * failed). When $max_jobs is 0, runs until the queue is empty. The caller
	 * is responsible for loop control (e.g. a worker process polling on a sleep).
	 *
	 * @param  string  $queue
	 * @param  int     $max_jobs  0 = drain the queue, then return
	 * @return int     jobs processed
	 */
	public static function work( string $queue = 'default', int $max_jobs = 0 ): int {
		$worker_id = self::worker_id();
		$processed = 0;

		// Recover any locks from previously crashed workers before grabbing new work.
		Repository::reset_stale_locks();

		while ( $max_jobs === 0 || $processed < $max_jobs ) {
			$job = Repository::reserve( $queue, $worker_id );
			if ( $job === null ) break;

			$handler = HandlerRegistry::get( $job->handler );

			if ( $handler === null ) {
				// No registered handler — treat as dead immediately. (Re-running
				// with the same handler list would just fail the same way.)
				Repository::fail(
					$job->id,
					sprintf( 'No handler registered for "%s"', $job->handler ),
					current_time( 'mysql', true ),
					is_dead: true
				);
				$processed++;
				continue;
			}

			try {
				( $handler )( $job->payload, $job );
				Repository::complete( $job->id );
			} catch ( \Throwable $e ) {
				$next_attempts = $job->attempts; // already incremented by reserve()
				if ( $next_attempts >= $job->max_attempts ) {
					// Out of retries — send to dead-letter status.
					Repository::fail(
						$job->id,
						$e->getMessage() . "\n" . $e->getTraceAsString(),
						current_time( 'mysql', true ),
						is_dead: true
					);
				} else {
					$delay_idx = max( 0, $next_attempts - 1 );
					$delay     = self::DEFAULT_BACKOFF[ $delay_idx ] ?? end( self::DEFAULT_BACKOFF );
					$available_at = gmdate( 'Y-m-d H:i:s', time() + (int) $delay );
					Repository::fail(
						$job->id,
						$e->getMessage(),
						$available_at,
						is_dead: false
					);
				}
			}

			$processed++;
		}

		return $processed;
	}

	/**
	 * @return array<string,int>
	 */
	public static function stats( ?string $queue = null ): array {
		return Repository::counts_by_status( $queue );
	}

	public static function retry( int $job_id ): bool {
		return Repository::retry( $job_id );
	}

	public static function find( int $job_id ): ?Job {
		return Repository::find( $job_id );
	}

	/**
	 * Periodic maintenance: reset stale locks, prune old completed rows.
	 * Wired into a WP-cron event by therum-core.php — runs every 5 minutes.
	 */
	public static function maintenance( int $prune_completed_older_than = 604800 ): array {
		return [
			'stale_locks_reset' => Repository::reset_stale_locks(),
			'completed_pruned'  => Repository::prune_completed( $prune_completed_older_than ),
		];
	}

	private static function worker_id(): string {
		// Stable-ish ID for the lifetime of this PHP process.
		static $id = null;
		if ( $id === null ) {
			$id = sprintf(
				'%s-%d-%s',
				defined( 'WP_CLI' ) && WP_CLI ? 'cli' : 'web',
				function_exists( 'getmypid' ) ? getmypid() : 0,
				substr( md5( (string) microtime( true ) . random_int( 0, PHP_INT_MAX ) ), 0, 8 )
			);
		}
		return $id;
	}
}
