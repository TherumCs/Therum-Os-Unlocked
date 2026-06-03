<?php
declare( strict_types=1 );

namespace Therum\MCP;

/**
 * Base class for a Therum MCP tool.
 *
 * Tools are the unit of MCP capability — each one exposes a `name`, a JSON
 * Schema for its inputs, and a `call()` that does the work. Server::dispatch()
 * routes `tools/call` JSON-RPC requests to the registered tool by name and
 * enforces the tool's `required_scope()` before invoking call().
 *
 * For tools that mutate state, prefer enqueuing via Therum\Queue and returning
 * a job reference rather than blocking the MCP request. See SourceRebuild.
 */
abstract class Tool {

	/**
	 * Stable tool ID — used by clients and by `tools/list`. Namespace it
	 * (e.g. `therum.source.rebuild`) so tools from different modules don't
	 * collide with the generic tool names used by bricks-mcp.
	 */
	abstract public function name(): string;

	/** Human-readable description, returned in `tools/list`. */
	abstract public function description(): string;

	/**
	 * JSON Schema (draft-07-ish, MCP-compatible) for the tool's arguments.
	 *
	 * @return array<string, mixed>
	 */
	abstract public function input_schema(): array;

	/**
	 * Therum auth scope required to invoke this tool.
	 *
	 * @see \Therum\Auth\Scopes
	 */
	abstract public function required_scope(): string;

	/**
	 * Execute the tool.
	 *
	 * Return shape: MCP content array — a list of { type: text|image|resource, ... }.
	 * For most tools, a single text block is enough:
	 *
	 *   return [ 'content' => [ [ 'type' => 'text', 'text' => '...' ] ] ];
	 *
	 * Throwing a \Throwable here turns into a JSON-RPC error response with
	 * code -32000 (server error). Don't catch domain errors silently — let
	 * them bubble so the client sees them.
	 *
	 * @param  array<string, mixed> $arguments  validated against input_schema
	 * @return array<string, mixed>             MCP tool-call response
	 */
	abstract public function call( array $arguments ): array;
}
