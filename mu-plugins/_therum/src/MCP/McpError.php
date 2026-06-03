<?php
declare( strict_types=1 );

namespace Therum\MCP;

/**
 * Domain-level MCP error. Use this (rather than \RuntimeException) when you
 * need to surface a specific JSON-RPC error code or attach structured `data`
 * to the error response.
 *
 * JSON-RPC error code conventions used by Server::route():
 *   -32600  Invalid Request
 *   -32601  Method not found
 *   -32602  Invalid params
 *   -32603  Internal error
 *   -32000  Server error (generic; reserved for tool throws)
 *   -32604  Insufficient scope (Therum extension)
 */
final class McpError extends \RuntimeException {

	/**
	 * @param array<string, mixed>|null $data  optional structured error data
	 */
	public function __construct(
		int $code,
		string $message,
		public readonly ?array $data = null,
	) {
		parent::__construct( $message, $code );
	}
}
