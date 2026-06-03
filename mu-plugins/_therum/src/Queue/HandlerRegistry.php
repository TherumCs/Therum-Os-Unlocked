<?php
declare( strict_types=1 );

namespace Therum\Queue;

/**
 * In-memory registry of queue job handlers.
 *
 * Handlers are registered at boot via Therum\Queue::register_handler() and
 * looked up by ID when a worker reserves a job. Handlers receive (payload, Job)
 * and may throw to trigger retry semantics.
 *
 * Handlers are NOT persisted across PHP processes — every worker process must
 * register the handlers it intends to serve before calling Therum\Queue::work().
 * In practice this means handler registration happens in mu-plugin load order,
 * which runs identically in CLI workers (`wp therum queue work`) and web requests.
 */
final class HandlerRegistry {

	/** @var array<string, callable> */
	private static array $handlers = [];

	public static function register( string $handler_id, callable $callable ): void {
		if ( $handler_id === '' ) {
			throw new \InvalidArgumentException( 'Queue handler ID may not be empty.' );
		}
		self::$handlers[ $handler_id ] = $callable;
	}

	public static function get( string $handler_id ): ?callable {
		return self::$handlers[ $handler_id ] ?? null;
	}

	/**
	 * @return array<string, callable>
	 */
	public static function all(): array {
		return self::$handlers;
	}

	public static function unregister( string $handler_id ): void {
		unset( self::$handlers[ $handler_id ] );
	}

	public static function has( string $handler_id ): bool {
		return isset( self::$handlers[ $handler_id ] );
	}
}
