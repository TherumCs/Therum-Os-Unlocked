<?php
declare( strict_types=1 );

namespace Therum\Events;

/**
 * Base class for typed Therum events.
 *
 * Subclass with readonly properties for the event payload — listeners receive
 * the typed Event instance and can pull data without dictionary indirection.
 *
 *   final class ProductSavedEvent extends Event {
 *       public function __construct(
 *           public readonly int $product_id,
 *           public readonly string $status,
 *       ) {}
 *       public static function name(): string { return 'product.saved'; }
 *   }
 *
 *   Therum\Events::dispatch( new ProductSavedEvent( 123, 'publish' ) );
 *
 *   Therum\Events::listen( ProductSavedEvent::class, function( ProductSavedEvent $e ): void {
 *       // typed access — $e->product_id is int, $e->status is string
 *   } );
 */
abstract class Event {

	/**
	 * Stable event name — used as the WP-action bridge name and the listener
	 * registry key. Defaults to the class FQCN, but subclasses can override
	 * for shorter / more public names.
	 */
	public static function name(): string {
		return static::class;
	}

	/**
	 * Microsecond timestamp the event was created — useful for replay /
	 * correlation. Set by Events::dispatch() if the subclass doesn't.
	 */
	public float $at = 0.0;
}
