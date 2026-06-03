<?php
declare( strict_types=1 );

namespace Therum\Queue;

use Therum\Queue;

/**
 * WP-CLI subcommands for the Therum queue.
 *
 *   wp therum queue work [--queue=<name>] [--max=<n>] [--sleep=<seconds>] [--once]
 *   wp therum queue status [--queue=<name>] [--format=<table|json|csv>]
 *   wp therum queue retry <id>
 *   wp therum queue prune [--older-than=<seconds>]
 *
 * Registered via therum-core.php when WP_CLI is loaded.
 */
final class CliCommands {

	public static function register(): void {
		if ( ! ( defined( 'WP_CLI' ) && \WP_CLI ) ) return;
		if ( ! class_exists( '\WP_CLI' ) ) return;
		\WP_CLI::add_command( 'therum queue', self::class );
	}

	/**
	 * Run a worker loop: reserve and process jobs until --max is reached, or
	 * forever (until Ctrl-C / SIGTERM). Sleeps $sleep seconds between drains.
	 *
	 * ## OPTIONS
	 *
	 * [--queue=<name>]
	 * : Queue to process. Default: default.
	 *
	 * [--max=<n>]
	 * : Total jobs to process before exiting. 0 = run forever. Default: 0.
	 *
	 * [--sleep=<seconds>]
	 * : Seconds to sleep when the queue is empty before polling again. Default: 1.
	 *
	 * [--once]
	 * : Process whatever's currently available, then exit (don't poll).
	 *
	 * ## EXAMPLES
	 *
	 *     wp therum queue work
	 *     wp therum queue work --queue=design --max=10
	 *     wp therum queue work --once
	 *
	 * @when after_wp_load
	 */
	public function work( $args, $assoc_args ): void {
		$queue = (string) ( $assoc_args['queue'] ?? 'default' );
		$max   = (int)    ( $assoc_args['max']   ?? 0 );
		$sleep = (int)    ( $assoc_args['sleep'] ?? 1 );
		$once  = isset( $assoc_args['once'] );

		\WP_CLI::log( sprintf( '[therum-queue] worker starting · queue=%s max=%d once=%s', $queue, $max, $once ? 'yes' : 'no' ) );

		$processed_total = 0;

		while ( true ) {
			$batch = Queue::work( $queue, $max > 0 ? ( $max - $processed_total ) : 0 );
			$processed_total += $batch;

			if ( $batch > 0 ) {
				\WP_CLI::log( sprintf( '[therum-queue] processed %d jobs (total %d)', $batch, $processed_total ) );
			}

			if ( $once ) break;
			if ( $max > 0 && $processed_total >= $max ) break;

			sleep( max( 1, $sleep ) );
		}

		\WP_CLI::success( sprintf( 'Worker exited cleanly. Processed %d jobs.', $processed_total ) );
	}

	/**
	 * Show queue statistics.
	 *
	 * ## OPTIONS
	 *
	 * [--queue=<name>]
	 * : Filter to a single queue.
	 *
	 * [--format=<format>]
	 * : Output format. Default: table. One of: table, json, csv, yaml.
	 *
	 * @when after_wp_load
	 */
	public function status( $args, $assoc_args ): void {
		$queue  = isset( $assoc_args['queue'] ) ? (string) $assoc_args['queue'] : null;
		$format = (string) ( $assoc_args['format'] ?? 'table' );

		$counts = Queue::stats( $queue );

		$rows = [];
		foreach ( $counts as $status => $n ) {
			$rows[] = [ 'status' => $status, 'jobs' => $n ];
		}

		\WP_CLI\Utils\format_items( $format, $rows, [ 'status', 'jobs' ] );
	}

	/**
	 * Force-retry a single job (resets attempts to 0, status to pending).
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Job ID.
	 *
	 * @when after_wp_load
	 */
	public function retry( $args, $assoc_args ): void {
		$id = (int) ( $args[0] ?? 0 );
		if ( $id <= 0 ) \WP_CLI::error( 'Missing job ID.' );

		$ok = Queue::retry( $id );
		if ( $ok ) {
			\WP_CLI::success( sprintf( 'Job %d reset to pending.', $id ) );
		} else {
			\WP_CLI::error( sprintf( 'Job %d not found.', $id ) );
		}
	}

	/**
	 * Prune completed jobs older than N seconds.
	 *
	 * ## OPTIONS
	 *
	 * [--older-than=<seconds>]
	 * : Delete completed jobs older than this many seconds. Default: 604800 (7 days).
	 *
	 * @when after_wp_load
	 */
	public function prune( $args, $assoc_args ): void {
		$older = (int) ( $assoc_args['older-than'] ?? 604800 );
		$result = Queue::maintenance( $older );

		\WP_CLI::success( sprintf(
			'Maintenance complete · stale locks reset: %d · completed pruned: %d',
			$result['stale_locks_reset'],
			$result['completed_pruned']
		) );
	}
}
