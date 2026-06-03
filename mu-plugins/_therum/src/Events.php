<?php
declare( strict_types=1 );

namespace Therum;

use Therum\Events\Event;

/**
 * Therum typed event bus — public facade.
 *
 * Dispatches typed Event instances to listeners registered against the event's
 * class FQCN. Bridges to WP actions so existing add_action(<event-name>, …)
 * code can subscribe via the string name; new code uses listen(EventClass, fn).
 *
 * Phase 5 Wave 3. Use for cross-module events where the payload shape matters
 * (e.g. ProductSavedEvent carrying typed product_id + status). For one-off
 * filters, keep using apply_filters directly.
 *
 * Bridge semantics:
 *   - dispatch( $event ) fires:
 *     1. all listeners registered via Events::listen( $class, $fn )
 *     2. do_action( $event::name(), $event ) so WP-style code can subscribe
 */
final class Events {

	/** @var array<string, list<callable>> class FQCN → list of listeners */
	private static array $listeners = [];

	/**
	 * Register a typed listener for an Event subclass.
	 *
	 * @param  class-string<Event> $event_class
	 * @param  callable             $listener     receives the Event instance
	 */
	public static function listen( string $event_class, callable $listener ): void {
		if ( ! is_subclass_of( $event_class, Event::class ) ) {
			throw new \InvalidArgumentException(
				"{$event_class} is not a Therum\\Events\\Event subclass."
			);
		}
		self::$listeners[ $event_class ][] = $listener;
	}

	/**
	 * Dispatch an event to all registered listeners + the WP action bridge.
	 *
	 * Listeners are invoked in registration order. Exceptions from one
	 * listener don't block subsequent listeners — they're logged and the
	 * dispatch continues. (Strict mode is opt-in via the
	 * `therum_events/strict_dispatch` filter.)
	 */
	public static function dispatch( Event $event ): Event {
		if ( $event->at === 0.0 ) {
			$event->at = microtime( true );
		}

		$strict = (bool) apply_filters( 'therum_events/strict_dispatch', false );

		// 1. Typed listeners
		foreach ( self::$listeners[ $event::class ] ?? [] as $listener ) {
			try {
				$listener( $event );
			} catch ( \Throwable $e ) {
				if ( $strict ) throw $e;
				error_log( '[therum-events] listener threw: ' . $e->getMessage() );
			}
		}

		// 2. WP action bridge
		do_action( $event::name(), $event );

		return $event;
	}

	/**
	 * @return array<string, int> event-class → listener count
	 */
	public static function stats(): array {
		$out = [];
		foreach ( self::$listeners as $class => $list ) {
			$out[ $class ] = count( $list );
		}
		return $out;
	}

	public static function clear(): void {
		self::$listeners = [];
	}
}
