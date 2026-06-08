<?php
declare( strict_types=1 );

namespace Therum\MCP;

use Therum\Auth\Middleware;

/**
 * Therum MCP HTTP server.
 *
 * Implements the MCP wire protocol (JSON-RPC 2.0 over HTTP) for Therum's
 * own MCP namespace. Sibling to bricks-mcp — they coexist on different REST
 * routes and register independently with Claude Code.
 *
 * Wire endpoint: POST /wp-json/therum/v1/mcp
 *
 * Supported methods (Phase 2 v1):
 *   - initialize             handshake; returns protocolVersion + serverInfo + capabilities
 *   - notifications/initialized   client→server notification, no response
 *   - tools/list             returns the registered tool catalogue
 *   - tools/call             invokes a tool by name, returns its content array
 *   - ping                   keepalive
 *
 * SSE (server→client streaming) deferred — Phase 2.x adds it for long-running
 * tool calls. Async tools today queue via Therum\Queue and the client polls.
 *
 * Auth: handled upstream by `Therum\Auth\Middleware` (REST authentication
 * filter). Per-tool scope checks happen inside dispatch() via the tool's
 * `required_scope()`.
 */
final class Server {

	/** MCP protocol version the server speaks. */
	public const PROTOCOL_VERSION = '2025-06-18';

	public function __construct(
		private readonly ToolRegistry $tools,
	) {}

	/**
	 * REST callback for POST /wp-json/therum/v1/mcp.
	 *
	 * Accepts either a single JSON-RPC request or a batch array. Returns the
	 * same shape back. Notifications (requests with no id) get no response.
	 */
	public function handle( \WP_REST_Request $request ): \WP_REST_Response {
		$raw = $request->get_json_params();

		// Batch requests: array of request objects
		if ( is_array( $raw ) && array_is_list( $raw ) ) {
			// Hard cap batch size — a client sending {batch: [10000 calls]}
			// would otherwise run every tool serially. 32 is generous for
			// real-world MCP usage.
			$max_batch = (int) apply_filters( 'therum_mcp_max_batch', 32 );
			if ( count( $raw ) > $max_batch ) {
				return new \WP_REST_Response(
					[
						'jsonrpc' => '2.0',
						'error'   => [ 'code' => -32600, 'message' => 'Batch exceeds maximum size of ' . $max_batch ],
						'id'      => null,
					],
					413
				);
			}
			$out = [];
			foreach ( $raw as $req ) {
				$resp = $this->dispatch_one( is_array( $req ) ? $req : [], $request );
				if ( $resp !== null ) $out[] = $resp;
			}
			return new \WP_REST_Response( $out, 200 );
		}

		// Single request object
		$resp = $this->dispatch_one( is_array( $raw ) ? $raw : [], $request );
		if ( $resp === null ) {
			// Notification — protocol says return 204 No Content
			return new \WP_REST_Response( null, 204 );
		}
		return new \WP_REST_Response( $resp, 200 );
	}

	/**
	 * Dispatch a single JSON-RPC envelope. Returns the response envelope, or
	 * null for notifications (no id).
	 *
	 * @param  array<string, mixed> $req
	 * @return array<string, mixed>|null
	 */
	private function dispatch_one( array $req, \WP_REST_Request $rest_request ): ?array {
		$id     = $req['id']     ?? null;
		$method = (string) ( $req['method'] ?? '' );
		$params = is_array( $req['params'] ?? null ) ? $req['params'] : [];

		// Notifications have no `id` and never get a response back.
		$is_notification = ! array_key_exists( 'id', $req );

		try {
			$result = $this->route( $method, $params, $rest_request );

			if ( $is_notification ) return null;

			return [
				'jsonrpc' => '2.0',
				'id'      => $id,
				'result'  => $result,
			];
		} catch ( McpError $e ) {
			if ( $is_notification ) return null;
			return [
				'jsonrpc' => '2.0',
				'id'      => $id,
				'error'   => [
					'code'    => $e->getCode(),
					'message' => $e->getMessage(),
					'data'    => $e->data,
				],
			];
		} catch ( \Throwable $e ) {
			if ( $is_notification ) return null;
			// Don't leak internal exception messages / stack details to the
			// client — they can carry DB errors, paths, or other infrastructure
			// detail. Log the specifics server-side under a short reference the
			// operator can grep for, and return only that reference.
			$ref = substr( md5( $e->getMessage() . '|' . $e->getFile() . '|' . $e->getLine() ), 0, 8 );
			error_log( sprintf( '[therum-mcp] internal error ref=%s: %s in %s:%d', $ref, $e->getMessage(), $e->getFile(), $e->getLine() ) );
			return [
				'jsonrpc' => '2.0',
				'id'      => $id,
				'error'   => [
					'code'    => -32000,
					'message' => 'Internal server error (ref ' . $ref . '). Check server logs for details.',
				],
			];
		}
	}

	/**
	 * Route a JSON-RPC method to the correct handler.
	 *
	 * @param  array<string, mixed>  $params
	 * @return mixed                                  result payload (any JSON value)
	 * @throws McpError                               on protocol-level errors
	 * @throws \Throwable                             on tool-level errors
	 */
	private function route( string $method, array $params, \WP_REST_Request $rest_request ): mixed {
		switch ( $method ) {
			case 'initialize':
				return $this->initialize( $params );

			case 'notifications/initialized':
				// Client letting us know it's ready. No-op.
				return null;

			case 'ping':
				return new \stdClass();   // Empty object; MCP just expects a successful response.

			case 'tools/list':
				return $this->tools->as_tools_list();

			case 'tools/call':
				return $this->call_tool( $params, $rest_request );

			default:
				throw new McpError( -32601, "Method not found: {$method}" );
		}
	}

	/**
	 * @param  array<string, mixed> $params
	 * @return array<string, mixed>
	 */
	private function initialize( array $params ): array {
		// Client protocolVersion is in $params['protocolVersion'] — we'd ideally
		// negotiate, but for now we just announce our supported version.
		return [
			'protocolVersion' => self::PROTOCOL_VERSION,
			'capabilities'    => [
				'tools' => new \stdClass(),   // tools/list + tools/call supported
			],
			'serverInfo' => [
				'name'    => 'therum-mcp',
				'version' => defined( 'THERUM_OS_VERSION' ) ? THERUM_OS_VERSION : '0.0.0',
			],
		];
	}

	/**
	 * @param  array<string, mixed> $params
	 * @return array<string, mixed>
	 */
	private function call_tool( array $params, \WP_REST_Request $rest_request ): array {
		$name      = (string) ( $params['name'] ?? '' );
		$arguments = is_array( $params['arguments'] ?? null ) ? $params['arguments'] : [];

		if ( $name === '' ) {
			throw new McpError( -32602, 'tools/call requires a "name" parameter.' );
		}

		$tool = $this->tools->get( $name );
		if ( $tool === null ) {
			throw new McpError( -32602, "Unknown tool: {$name}" );
		}

		// Scope check — Middleware::require_scope returns true or a WP_Error.
		$scope_check = Middleware::require_scope( $rest_request, $tool->required_scope() );
		if ( $scope_check instanceof \WP_Error ) {
			throw new McpError(
				-32604,
				$scope_check->get_error_message(),
				[ 'scope' => $tool->required_scope() ]
			);
		}

		return $tool->call( $arguments );
	}
}
