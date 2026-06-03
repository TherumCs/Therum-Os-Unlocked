<?php
declare( strict_types=1 );

namespace Therum\Queue;

/**
 * Therum queue job — value object.
 *
 * Represents a single row in wp_therum_jobs. Returned by Repository::reserve()
 * to handlers; never instantiated directly by user code.
 */
final class Job {

	/** @var string */ public const STATUS_PENDING   = 'pending';
	/** @var string */ public const STATUS_RUNNING   = 'running';
	/** @var string */ public const STATUS_COMPLETED = 'completed';
	/** @var string */ public const STATUS_FAILED    = 'failed';
	/** @var string */ public const STATUS_DEAD      = 'dead';

	/**
	 * @param array<string,mixed> $payload Job-specific data passed to the handler.
	 */
	public function __construct(
		public readonly int $id,
		public readonly string $queue,
		public readonly string $handler,
		public readonly array $payload,
		public readonly string $status,
		public readonly int $attempts,
		public readonly int $max_attempts,
		public readonly string $available_at,
		public readonly ?string $locked_at = null,
		public readonly ?string $locked_by = null,
		public readonly ?string $completed_at = null,
		public readonly ?string $failed_at = null,
		public readonly ?string $last_error = null,
		public readonly string $created_at = '',
	) {}

	public function is_terminal(): bool {
		return in_array( $this->status, [ self::STATUS_COMPLETED, self::STATUS_DEAD ], true );
	}

	public function remaining_attempts(): int {
		return max( 0, $this->max_attempts - $this->attempts );
	}

	/**
	 * Build a Job from a wp_therum_jobs row.
	 *
	 * @param array<string,mixed> $row
	 */
	public static function from_row( array $row ): self {
		$payload_raw = (string) ( $row['payload'] ?? '' );
		$payload     = $payload_raw === '' ? [] : (array) json_decode( $payload_raw, true );

		return new self(
			id:           (int) ( $row['id'] ?? 0 ),
			queue:        (string) ( $row['queue'] ?? 'default' ),
			handler:      (string) ( $row['handler'] ?? '' ),
			payload:      $payload,
			status:       (string) ( $row['status'] ?? self::STATUS_PENDING ),
			attempts:     (int) ( $row['attempts'] ?? 0 ),
			max_attempts: (int) ( $row['max_attempts'] ?? 3 ),
			available_at: (string) ( $row['available_at'] ?? '' ),
			locked_at:    isset( $row['locked_at'] )    ? (string) $row['locked_at']    : null,
			locked_by:    isset( $row['locked_by'] )    ? (string) $row['locked_by']    : null,
			completed_at: isset( $row['completed_at'] ) ? (string) $row['completed_at'] : null,
			failed_at:    isset( $row['failed_at'] )    ? (string) $row['failed_at']    : null,
			last_error:   isset( $row['last_error'] )   ? (string) $row['last_error']   : null,
			created_at:   (string) ( $row['created_at'] ?? '' ),
		);
	}
}
