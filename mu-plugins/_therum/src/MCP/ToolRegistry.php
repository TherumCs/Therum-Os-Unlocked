<?php
declare( strict_types=1 );

namespace Therum\MCP;

/**
 * In-memory registry of MCP tools, keyed by tool name.
 *
 * Tools register themselves at boot via the `therum_mcp_register_tools`
 * action — therum-mcp.php hooks `init` and fires this action, then collects
 * the registered tools and serves them through Server::dispatch().
 *
 * Other mu-plugins can register additional tools:
 *
 *   add_action( 'therum_mcp_register_tools', function( ToolRegistry $r ): void {
 *       $r->register( new MyMcpTool() );
 *   } );
 */
final class ToolRegistry {

	/** @var array<string, Tool> */
	private array $tools = [];

	public function register( Tool $tool ): void {
		$name = $tool->name();
		if ( $name === '' ) {
			throw new \InvalidArgumentException( 'MCP tool name may not be empty.' );
		}
		$this->tools[ $name ] = $tool;
	}

	public function get( string $name ): ?Tool {
		return $this->tools[ $name ] ?? null;
	}

	/**
	 * @return array<string, Tool>
	 */
	public function all(): array {
		return $this->tools;
	}

	public function has( string $name ): bool {
		return isset( $this->tools[ $name ] );
	}

	public function unregister( string $name ): void {
		unset( $this->tools[ $name ] );
	}

	/**
	 * Render the registry as an MCP `tools/list` response payload.
	 *
	 * @return array{tools: list<array{name: string, description: string, inputSchema: array<string,mixed>}>}
	 */
	public function as_tools_list(): array {
		$out = [];
		foreach ( $this->tools as $tool ) {
			$out[] = [
				'name'        => $tool->name(),
				'description' => $tool->description(),
				'inputSchema' => $tool->input_schema(),
			];
		}
		return [ 'tools' => $out ];
	}
}
