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

	/** @var array<int, ?Token> request-scoped cache, keyed by spl_object_id */
	private static array $resolved_cache = [];

	/** Max failed bearer-token auths per IP before throttling kicks in. */
	private const MAX_FAILED_AUTH = 20;

	/** Sliding window (seconds) over which failed attempts are counted. */
	private const FAIL_WINDOW = 900; // 15 minutes

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

		// Throttle brute-force / token-guessing: too many recent failures from
		// this IP and we refuse to even look up the token until the window cools.
		$rl_key = self::rate_key();
		if ( (int) get_transient( $rl_key ) >= self::MAX_FAILED_AUTH ) {
			return new \WP_Error(
				'therum_token_throttled',
				'Too many failed token attempts. Try again later.',
				[ 'status' => 429 ]
			);
		}

		$token = TokenRegistry::find_by_token( $token_plain );
		if ( $token === null ) {
			// Count the failure (sliding window) before rejecting.
			$fails = (int) get_transient( $rl_key );
			set_transient( $rl_key, $fails + 1, self::FAIL_WINDOW );
			return new \WP_Error(
				'therum_token_invalid',
				'Invalid or revoked Therum token.',
				[ 'status' => 401 ]
			);
		}

		// Success — clear this IP's failure counter.
		delete_transient( $rl_key );

		// Authenticate as the token's user.
		wp_set_current_user( $token->user_id );

		// NOTE: we intentionally do NOT try to stash the token on the request
		// here — this filter doesn't receive the WP_REST_Request, and there is
		// no reliable public accessor for the in-flight request at this point.
		// The scope gate (require_scope / token_for_request) re-resolves the
		// token directly from the request it IS handed, which is authoritative.

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

	/**
	 * Returns the Therum Token presented on this request, or null.
	 *
	 * Resolves the bearer token directly from the request's Authorization header
	 * — NOT from a previously-stashed attribute. The authentication filter can't
	 * reach the WP_REST_Request to stash anything, so relying on an attribute
	 * meant the scope gate always saw null and silently fell back to the user's
	 * raw capability (scope bypass). Resolving from the request the permission
	 * callback is handed is the authoritative, tamper-proof source. Result is
	 * cached per-request to avoid repeating the DB lookup.
	 */
	public static function token_for_request( \WP_REST_Request $req ): ?Token {
		$key = spl_object_id( $req );
		if ( array_key_exists( $key, self::$resolved_cache ) ) {
			return self::$resolved_cache[ $key ];
		}

		$token  = null;
		$header = (string) $req->get_header( 'authorization' );
		if ( $header !== '' && stripos( $header, 'bearer ' ) === 0 ) {
			$plain = trim( substr( $header, 7 ) );
			if ( str_starts_with( $plain, 'tro_' ) ) {
				$token = TokenRegistry::find_by_token( $plain );
			}
		}

		self::$resolved_cache[ $key ] = $token;
		return $token;
	}

	/**
	 * Pull `Authorization: Bearer <token>` from the current request headers.
	 * Returns null if no bearer token is present.
	 */
	private static function extract_bearer(): ?string {
		// This runs from the rest_authentication_errors filter, which doesn't
		// hand us the WP_REST_Request, so read the Authorization header from the
		// server superglobals. getallheaders() covers hosts that don't surface
		// HTTP_AUTHORIZATION to PHP (e.g. some FastCGI setups).
		$server = $_SERVER['HTTP_AUTHORIZATION']
			?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
			?? '';
		if ( ( ! is_string( $server ) || $server === '' ) && function_exists( 'getallheaders' ) ) {
			$headers = getallheaders();
			if ( is_array( $headers ) ) {
				foreach ( $headers as $name => $value ) {
					if ( strcasecmp( (string) $name, 'authorization' ) === 0 ) {
						$server = (string) $value;
						break;
					}
				}
			}
		}
		if ( is_string( $server ) && stripos( $server, 'bearer ' ) === 0 ) {
			return trim( substr( $server, 7 ) );
		}

		return null;
	}

	private static function client_ip(): string {
		$ip = $_SERVER['REMOTE_ADDR'] ?? '';
		return is_string( $ip ) ? $ip : '';
	}

	/** Transient key for the per-IP failed-auth counter. */
	private static function rate_key(): string {
		return 'therum_tok_fail_' . md5( self::client_ip() );
	}
}
