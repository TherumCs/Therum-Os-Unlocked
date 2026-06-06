<?php
declare( strict_types=1 );

namespace Therum\MCP\Tools;

use Therum\MCP\McpError;
use Therum\MCP\Tool;
use Therum\Queue;

/**
 * therum.source.rebuild — compile source files into Bricks.
 *
 * Mirrors the local workflow: `php rebuild.php`. Because the rebuild can take
 * minutes on a large site, this tool ALWAYS enqueues — never runs sync. The
 * MCP response returns the job ID + a hint about how the client can poll.
 *
 * The actual work happens in the queue handler registered by therum-mcp.php
 * (`therum.source.rebuild` handler). On the VPS, the worker runs under
 * systemd; locally, run `wp therum queue work --queue=mcp` in a terminal.
 *
 * Scope: mcp.source.rebuild (or any wildcard that covers it).
 */
final class SourceRebuild extends Tool {

	public const HANDLER_ID = 'therum.source.rebuild';
	public const QUEUE_NAME = 'mcp';

	public function name(): string {
		return 'therum.source.rebuild';
	}

	public function description(): string {
		return 'Compile source files into Bricks via rebuild.php. Runs async — returns a job ID; track progress with therum.queue.status (coming in Phase 2.x). On the VPS this runs under systemd; locally, ensure `wp therum queue work --queue=mcp` is running.';
	}

	public function input_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'site' => [
					'type'        => 'string',
					'description' => 'Site identifier (e.g. "site-demo"). Defaults to the canonical rebuild script.',
				],
				'reason' => [
					'type'        => 'string',
					'description' => 'Free-text reason for this rebuild, recorded in the job payload for audit.',
				],
			],
			'required'   => [],
		];
	}

	public function required_scope(): string {
		return 'mcp.source.rebuild';
	}

	public function call( array $arguments ): array {
		$site   = (string) ( $arguments['site']   ?? 'site-demo' );
		$reason = (string) ( $arguments['reason'] ?? '' );

		// Hard-validate the site identifier BEFORE it touches a filesystem path.
		// This tool require()s the resolved file, so an unsanitized value with
		// "../" would be arbitrary-PHP execution. Allow only a safe slug charset.
		if ( ! preg_match( '/^[A-Za-z0-9_-]{1,64}$/', $site ) ) {
			throw new McpError(
				-32602,
				'Invalid site identifier — use only letters, digits, hyphen and underscore.'
			);
		}

		$rebuild_path = self::resolve_rebuild_path( $site );
		if ( ! is_file( $rebuild_path ) || ! self::is_path_allowed( $rebuild_path ) ) {
			throw new McpError(
				-32000,
				"rebuild.php not found for site '{$site}'",
				[ 'expected_path' => $rebuild_path ]
			);
		}

		$job_id = Queue::push(
			self::HANDLER_ID,
			[
				'site'         => $site,
				'rebuild_path' => $rebuild_path,
				'reason'       => $reason,
				'queued_by'    => get_current_user_id(),
			],
			[ 'queue' => self::QUEUE_NAME, 'max_attempts' => 1 ]
		);

		if ( $job_id === 0 ) {
			throw new McpError( -32000, 'Failed to enqueue rebuild job.' );
		}

		return [
			'content' => [
				[
					'type' => 'text',
					'text' => sprintf(
						"Rebuild queued for '%s'.\nJob ID: %d\nQueue: %s\n\nThe worker will pick it up shortly. On the VPS this runs under systemd; locally, ensure `wp therum queue work --queue=%s` is running.",
						$site,
						$job_id,
						self::QUEUE_NAME,
						self::QUEUE_NAME
					),
				],
			],
			'structuredContent' => [
				'job_id' => $job_id,
				'queue'  => self::QUEUE_NAME,
				'site'   => $site,
			],
		];
	}

	/**
	 * Resolve the rebuild.php path for a given site identifier.
	 *
	 * The bam-leon working repo keeps rebuild.php under
	 * wp-content/uploads/bricks-temp/<site>/rebuild.php. Filter to override
	 * for sites that put it elsewhere.
	 */
	private static function resolve_rebuild_path( string $site ): string {
		$default = self::rebuild_base_dir() . '/' . $site . '/rebuild.php';
		return (string) apply_filters( 'therum_mcp/source_rebuild_path', $default, $site );
	}

	/** Canonical base directory that rebuild scripts must live under. */
	private static function rebuild_base_dir(): string {
		return WP_CONTENT_DIR . '/uploads/bricks-temp';
	}

	/**
	 * Confine an arbitrary rebuild path to the bricks-temp base and require the
	 * exact filename `rebuild.php`. realpath() collapses any `../` segments and
	 * resolves symlinks, so a traversal attempt or a symlinked decoy can't escape
	 * the base. This is the execution-safety backstop behind the site-slug regex
	 * — it also constrains anything the source_rebuild_path filter returns.
	 */
	private static function is_path_allowed( string $path ): bool {
		$base = realpath( self::rebuild_base_dir() );
		$real = realpath( $path );
		if ( $base === false || $real === false ) {
			return false;
		}
		return str_starts_with( $real, $base . DIRECTORY_SEPARATOR )
			&& basename( $real ) === 'rebuild.php';
	}

	/**
	 * Queue handler invoked by `wp therum queue work --queue=mcp`.
	 *
	 * Registered by therum-mcp.php so it's available both at MCP-request time
	 * (when the tool enqueues) and at worker time (when the queue runs it).
	 *
	 * @param array<string, mixed> $payload
	 */
	public static function handler( array $payload ): void {
		$path = (string) ( $payload['rebuild_path'] ?? '' );
		// Re-validate confinement at execution time — never require() a path on
		// the payload's say-so alone (defense in depth if the queue row were
		// ever tampered with between enqueue and run).
		if ( ! is_file( $path ) || ! self::is_path_allowed( $path ) ) {
			throw new \RuntimeException( "rebuild.php missing or outside the allowed directory at runtime: {$path}" );
		}

		// Run rebuild.php in an output buffer. The script writes to stdout
		// during normal operation; capture it for the job log.
		ob_start();
		try {
			require $path;
		} finally {
			$output = (string) ob_get_clean();
			if ( $output !== '' ) {
				error_log( "[therum.source.rebuild] " . $output );
			}
		}
	}
}
