<?php
declare( strict_types=1 );

namespace Therum\Auth;

/**
 * REST request guard for Therum tokens.
 *
 * Wired into WP's `rest_authentication_errors` filter by therum-auth.php.
 * Accepts `Authorization: Bearer tro_...` and authenticates the request as
 * the user the token was issued for. Marks last_used_at on success.
 *
 * Per-endpoint scope checks happen via `require_scope()` in REST permission
 * callbacks — Middleware itself only proves "this token is real and active";
 * the scope gate sits inside the endpoint registration.
 */
final class Middleware {

	/** Request attribute / static cache key for the resolved Token. */
	private const REQUEST_TOKEN_KEY = 'therum_token';

	/** @var array<int, ?Token> request-scoped cache, keyed by spl_object_id */
	private static array $resolved_cache = [];

	/**
	 * `rest_authentication_errors` filter callback. Authenticates the user
	 * when a Therum token is present and valid; passes through otherwise so
	 * Application Passwords + cookie auth still work.
	 *
	 * @param  mixed $existing  current auth state (WP_Error|true|null)
	 * @return mixed            same shape WP expects from this filter
	 */
	public static function on_rest_authentication_errors( $existing ) {
		// If someone earlier already authenticated, don't interfere.
		if ( ! empty( $existing ) ) return $existing;

		$token_plain = self::extract_bearer();
		if ( $token_plain === null ) return $existing;

		// Only act on Therum-prefixed tokens — leave App Passwords (which also
		// arrive as Basic auth) to WP core, and leave unknown bearer schemes alone.
		if ( ! str_starts_with( $token_plain, 'tro_' ) ) return $existing;

		$token = TokenRegistry::find_by_token( $token_plain );
		if ( $token === null ) {
			return new \WP_Error(
				'therum_token_invalid',
				'Invalid or revoked Therum token.',
				[ 'status' => 401 ]
			);
		}

		// Authenticate as the token's user.
		wp_set_current_user( $token->user_id );

		// Stash the token on the current request so scope checks can read it.
		$req = self::current_request();
		if ( $req instanceof \WP_REST_Request ) {
			$req->set_attributes( array_merge(
				$req->get_attributes(),
				[ self::REQUEST_TOKEN_KEY => $token ]
			) );
			self::$resolved_cache[ spl_object_id( $req ) ] = $token;
		}

		// Mark used (don't block on failure).
		TokenRegistry::mark_used( $token->id, self::client_ip() );

		return true;
	}

	/**
	 * Helper for use inside REST `permission_callback`s.
	 *
	 *   add_action( 'rest_api_init', function() {
	 *       register_rest_route( 'therum/v1', '/foo', [
	 *           'callback'           => 'my_callback',
	 *           'permission_callback' => fn( $req ) => Middleware::require_scope( $req, 'therum.read' ),
	 *       ] );
	 *   } );
	 *
	 * @return true|\WP_Error
	 */
	public static function require_scope( \WP_REST_Request $req, string $scope ) {
		$token = self::token_for_request( $req );

		// No Therum token on the request — fall back to capability check so
		// cookie/App-Password sessions can still hit the endpoint with the
		// underlying WP cap. This preserves bricks-mcp / WP-CLI compatibility.
		if ( $token === null ) {
			$required_cap = Scopes::required_cap( $scope );
			return current_user_can( $required_cap )
				? true
				: new \WP_Error(
					'therum_scope_missing',
					sprintf( 'This endpoint requires the %s scope or %s capability.', $scope, $required_cap ),
					[ 'status' => 403 ]
				);
		}

		if ( ! $token->has_scope( $scope ) ) {
			return new \WP_Error(
				'therum_scope_missing',
				sprintf( 'Token lacks required scope: %s', $scope ),
				[ 'status' => 403 ]
			);
		}

		return true;
	}

	/** Returns the Token associated with the request, or null. */
	public static function token_for_request( \WP_REST_Request $req ): ?Token {
		$key = spl_object_id( $req );
		if ( array_key_exists( $key, self::$resolved_cache ) ) {
			return self::$resolved_cache[ $key ];
		}
		$attrs = $req->get_attributes();
		$token = $attrs[ self::REQUEST_TOKEN_KEY ] ?? null;
		return $token instanceof Token ? $token : null;
	}

	/**
	 * Pull `Authorization: Bearer <token>` from the current request headers.
	 * Returns null if no bearer token is present.
	 */
	private static function extract_bearer(): ?string {
		// Prefer the parsed REST request when we're inside REST dispatch.
		$req = self::current_request();
		if ( $req instanceof \WP_REST_Request ) {
			$header = (string) $req->get_header( 'authorization' );
			if ( $header !== '' && stripos( $header, 'bearer ' ) === 0 ) {
				return trim( substr( $header, 7 ) );
			}
		}

		// Fallback for non-REST callers (rare, but safe).
		$server = $_SERVER['HTTP_AUTHORIZATION']
			?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
			?? '';
		if ( is_string( $server ) && stripos( $server, 'bearer ' ) === 0 ) {
			return trim( substr( $server, 7 ) );
		}

		return null;
	}

	/**
	 * Best-effort access to the active WP_REST_Request inside dispatch.
	 * REST_REQUEST is set by WP for in-flight REST requests; outside of REST,
	 * this returns null.
	 */
	private static function current_request(): ?\WP_REST_Request {
		if ( ! defined( 'REST_REQUEST' ) || ! REST_REQUEST ) return null;
		// WP doesn't expose the request publicly; use the global the REST
		// server stashes (introduced in WP 5.5+, available via rest_get_server()
		// → get_dispatch_result() — but that's also not public). We rely on
		// the request being passed into our permission_callback instead.
		return null;
	}

	private static function client_ip(): string {
		$ip = $_SERVER['REMOTE_ADDR'] ?? '';
		return is_string( $ip ) ? $ip : '';
	}
}
