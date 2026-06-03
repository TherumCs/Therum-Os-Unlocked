<?php
declare( strict_types=1 );

namespace Therum\Queue;

/**
 * Therum queue — DB repository.
 *
 * Single table (wp_therum_jobs) backs the queue. Reserves use a row-level UPDATE
 * with a synthetic lock token so workers don't race on the same job. Stale locks
 * (workers that died mid-job) are recovered by reset_stale_locks() before each
 * reserve attempt.
 *
 * Redis backend is deferred — Phase 5.2 ships DB-only. The Repository's public
 * surface is intentionally backend-agnostic so a Redis variant can drop in later
 * without changing Therum\Queue or HandlerRegistry.
 */
final class Repository {

	private const TABLE       = 'therum_jobs';
	private const DB_VERSION  = 1;
	private const VERSION_OPT = 'therum_jobs_db_version';

	/** Stale lock threshold — workers that haven't completed in this long are presumed dead. */
	private const STALE_LOCK_SECONDS = 600; // 10 minutes

	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE;
	}

	/** Idempotent table install / migration. */
	public static function install(): void {
		$installed = (int) get_option( self::VERSION_OPT, 0 );
		if ( $installed >= self::DB_VERSION ) return;

		global $wpdb;
		$table   = self::table_name();
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			queue VARCHAR(64) NOT NULL DEFAULT 'default',
			handler VARCHAR(191) NOT NULL,
			payload LONGTEXT NOT NULL,
			status VARCHAR(16) NOT NULL DEFAULT 'pending',
			attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
			max_attempts TINYINT UNSIGNED NOT NULL DEFAULT 3,
			available_at DATETIME NOT NULL,
			locked_at DATETIME NULL DEFAULT NULL,
			locked_by VARCHAR(64) NULL DEFAULT NULL,
			completed_at DATETIME NULL DEFAULT NULL,
			failed_at DATETIME NULL DEFAULT NULL,
			last_error TEXT NULL DEFAULT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY queue_status_available (queue, status, available_at),
			KEY status (status),
			KEY locked_at (locked_at)
		) {$charset};";

		if ( ! function_exists( 'dbDelta' ) ) require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		update_option( self::VERSION_OPT, self::DB_VERSION, false );
	}

	/**
	 * Insert a new job.
	 *
	 * @param  array<string,mixed> $payload
	 * @return int  inserted job ID (0 on failure)
	 */
	public static function insert(
		string $queue,
		string $handler,
		array $payload,
		int $max_attempts,
		string $available_at
	): int {
		global $wpdb;

		$ok = $wpdb->insert(
			self::table_name(),
			[
				'queue'        => $queue !== '' ? $queue : 'default',
				'handler'      => $handler,
				'payload'      => (string) wp_json_encode( $payload ),
				'status'       => Job::STATUS_PENDING,
				'attempts'     => 0,
				'max_attempts' => max( 1, $max_attempts ),
				'available_at' => $available_at,
				'created_at'   => current_time( 'mysql', true ),
			],
			[ '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s' ]
		);

		return $ok === false ? 0 : (int) $wpdb->insert_id;
	}

	/**
	 * Atomically reserve the next available job in $queue for $worker_id.
	 *
	 * Strategy: two-step lock.
	 *   1. SELECT a candidate ID (status=pending, available_at<=NOW, not locked).
	 *   2. UPDATE the row IFF still unlocked, setting locked_at + locked_by.
	 *      If 0 rows affected (someone else won the race), retry the SELECT.
	 *
	 * Without true SELECT...FOR UPDATE (sketchy on some MySQL/SQLite combos)
	 * this is the most portable correct pattern.
	 */
	public static function reserve( string $queue, string $worker_id ): ?Job {
		global $wpdb;
		$table = self::table_name();

		$now = current_time( 'mysql', true );

		for ( $attempt = 0; $attempt < 5; $attempt++ ) {
			// 1. Pick a candidate.
			$id = $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM {$table}
				   WHERE queue = %s
				     AND status = %s
				     AND available_at <= %s
				     AND locked_at IS NULL
				   ORDER BY available_at ASC, id ASC
				   LIMIT 1",
				$queue,
				Job::STATUS_PENDING,
				$now
			) );

			if ( ! $id ) return null;

			// 2. Try to lock it.
			$updated = $wpdb->query( $wpdb->prepare(
				"UPDATE {$table}
				    SET status = %s,
				        locked_at = %s,
				        locked_by = %s,
				        attempts = attempts + 1
				  WHERE id = %d
				    AND locked_at IS NULL
				    AND status = %s",
				Job::STATUS_RUNNING,
				$now,
				$worker_id,
				(int) $id,
				Job::STATUS_PENDING
			) );

			if ( $updated === 1 ) {
				$row = $wpdb->get_row( $wpdb->prepare(
					"SELECT * FROM {$table} WHERE id = %d",
					(int) $id
				), ARRAY_A );
				return is_array( $row ) ? Job::from_row( $row ) : null;
			}

			// Lost the race; try again.
		}

		return null;
	}

	public static function complete( int $job_id ): void {
		global $wpdb;
		$wpdb->update(
			self::table_name(),
			[
				'status'       => Job::STATUS_COMPLETED,
				'completed_at' => current_time( 'mysql', true ),
				'locked_at'    => null,
				'locked_by'    => null,
				'last_error'   => null,
			],
			[ 'id' => $job_id ],
			[ '%s', '%s', '%s', '%s', '%s' ],
			[ '%d' ]
		);
	}

	/**
	 * Record a failure. If $is_dead, status becomes 'dead' (terminal);
	 * otherwise status reverts to 'pending' with $available_at as the next try.
	 */
	public static function fail( int $job_id, string $error, string $available_at, bool $is_dead = false ): void {
		global $wpdb;

		$now = current_time( 'mysql', true );

		if ( $is_dead ) {
			$wpdb->update(
				self::table_name(),
				[
					'status'      => Job::STATUS_DEAD,
					'failed_at'   => $now,
					'locked_at'   => null,
					'locked_by'   => null,
					'last_error'  => self::truncate_error( $error ),
				],
				[ 'id' => $job_id ],
				[ '%s', '%s', '%s', '%s', '%s' ],
				[ '%d' ]
			);
			return;
		}

		$wpdb->update(
			self::table_name(),
			[
				'status'       => Job::STATUS_PENDING,
				'available_at' => $available_at,
				'locked_at'    => null,
				'locked_by'    => null,
				'last_error'   => self::truncate_error( $error ),
			],
			[ 'id' => $job_id ],
			[ '%s', '%s', '%s', '%s', '%s' ],
			[ '%d' ]
		);
	}

	/**
	 * Reset any jobs whose locks are older than STALE_LOCK_SECONDS — assumed
	 * to be from dead workers. Pushes them back to pending so another worker
	 * can pick them up. Returns the number of rows reset.
	 */
	public static function reset_stale_locks( int $stale_seconds = self::STALE_LOCK_SECONDS ): int {
		global $wpdb;
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - $stale_seconds );

		$rows = $wpdb->query( $wpdb->prepare(
			"UPDATE " . self::table_name() . "
			    SET status = %s,
			        locked_at = NULL,
			        locked_by = NULL,
			        last_error = %s
			  WHERE status = %s
			    AND locked_at IS NOT NULL
			    AND locked_at < %s",
			Job::STATUS_PENDING,
			'Lock expired; worker assumed dead.',
			Job::STATUS_RUNNING,
			$cutoff
		) );

		return is_int( $rows ) ? $rows : 0;
	}

	/**
	 * Count jobs grouped by status, optionally filtered to a single queue.
	 *
	 * @return array<string,int>
	 */
	public static function counts_by_status( ?string $queue = null ): array {
		global $wpdb;
		$table = self::table_name();

		$sql = "SELECT status, COUNT(*) AS n FROM {$table}";
		if ( $queue !== null ) {
			$sql = $wpdb->prepare( $sql . " WHERE queue = %s GROUP BY status", $queue );
		} else {
			$sql .= " GROUP BY status";
		}

		$rows = $wpdb->get_results( $sql, ARRAY_A );
		$out = [
			Job::STATUS_PENDING   => 0,
			Job::STATUS_RUNNING   => 0,
			Job::STATUS_COMPLETED => 0,
			Job::STATUS_FAILED    => 0,
			Job::STATUS_DEAD      => 0,
		];
		foreach ( (array) $rows as $r ) {
			$status = (string) ( $r['status'] ?? '' );
			if ( isset( $out[ $status ] ) ) $out[ $status ] = (int) ( $r['n'] ?? 0 );
		}
		return $out;
	}

	public static function find( int $job_id ): ?Job {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM " . self::table_name() . " WHERE id = %d",
			$job_id
		), ARRAY_A );
		return is_array( $row ) ? Job::from_row( $row ) : null;
	}

	/**
	 * Force a dead/failed job back to pending with attempts reset.
	 * Returns true if a row was actually updated.
	 */
	public static function retry( int $job_id ): bool {
		global $wpdb;
		$rows = $wpdb->update(
			self::table_name(),
			[
				'status'       => Job::STATUS_PENDING,
				'attempts'     => 0,
				'available_at' => current_time( 'mysql', true ),
				'locked_at'    => null,
				'locked_by'    => null,
				'failed_at'    => null,
				'last_error'   => null,
			],
			[ 'id' => $job_id ],
			[ '%s', '%d', '%s', '%s', '%s', '%s', '%s' ],
			[ '%d' ]
		);
		return is_int( $rows ) && $rows > 0;
	}

	/**
	 * Delete jobs in terminal status older than $older_than_seconds.
	 * Used by periodic maintenance.
	 */
	public static function prune_completed( int $older_than_seconds ): int {
		global $wpdb;
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - $older_than_seconds );
		$rows = $wpdb->query( $wpdb->prepare(
			"DELETE FROM " . self::table_name() . "
			  WHERE status = %s
			    AND completed_at IS NOT NULL
			    AND completed_at < %s",
			Job::STATUS_COMPLETED,
			$cutoff
		) );
		return is_int( $rows ) ? $rows : 0;
	}

	private static function truncate_error( string $error ): string {
		// TEXT column — be generous but bounded.
		return mb_substr( $error, 0, 4000 );
	}
}
