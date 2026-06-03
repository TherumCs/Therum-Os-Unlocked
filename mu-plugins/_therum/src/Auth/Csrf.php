<?php
declare( strict_types=1 );

namespace Therum\Auth;

/**
 * Standardized CSRF/nonce check for Therum REST + AJAX endpoints.
 *
 * Therum has two auth modes that meet REST endpoints:
 *   - **Cookie auth** (logged-in admin browser): vulnerable to CSRF; needs a nonce.
 *   - **Token auth** (Authorization: Bearer tro_…): CSRF-immune by design — an
 *     attacker can't make the victim's browser send a custom Authorization header
 *     cross-origin.
 *
 * Csrf::check() encapsulates this. Endpoints that mutate state should call it
 * from their `permission_callback` or early in the handler. The nonce header
 * we look for is `X-WP-Nonce` (WP's canonical name); fallback to a `_wpnonce`
 * query/body param for compatibility with the existing therum-* AJAX surface.
 */
final class Csrf {

	private const NONCE_ACTION = 'wp_rest';   // matches wp_create_nonce('wp_rest')

	/**
	 * @return true|\WP_Error
	 */
	public static function check( \WP_REST_Request $request ) {
		// Token-authenticated requests are CSRF-safe by definition.
		if ( Middleware::token_for_request( $request ) !== null ) {
			return true;
		}

		// Application Password also arrives as Authorization Basic — same CSRF
		// immunity (browser doesn't send Authorization cross-origin).
		$auth_header = (string) $request->get_header( 'authorization' );
		if ( $auth_header !== '' && stripos( $auth_header, 'basic ' ) === 0 ) {
			return true;
		}

		// Cookie-authenticated request → require a nonce.
		$nonce = (string) $request->get_header( 'x_wp_nonce' );
		if ( $nonce === '' ) {
			$nonce = (string) ( $request->get_param( '_wpnonce' ) ?? '' );
		}

		if ( $nonce === '' ) {
			return new \WP_Error(
				'therum_csrf_missing',
				'Missing CSRF nonce — set the X-WP-Nonce header for cookie-authenticated requests.',
				[ 'status' => 403 ]
			);
		}

		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			return new \WP_Error(
				'therum_csrf_invalid',
				'CSRF nonce invalid or expired — refresh the page and retry.',
				[ 'status' => 403 ]
			);
		}

		return true;
	}

	/**
	 * Convenience: returns a `permission_callback` closure that chains
	 * Csrf::check() with an arbitrary capability/scope check.
	 *
	 *   register_rest_route( 'therum/v1', '/foo', [
	 *       'methods'             => 'POST',
	 *       'callback'            => …,
	 *       'permission_callback' => Csrf::guard( fn( $req ) =>
	 *           Middleware::require_scope( $req, 'therum.write' )
	 *       ),
	 *   ] );
	 */
	public static function guard( callable $inner ): \Closure {
		return function ( \WP_REST_Request $req ) use ( $inner ) {
			$csrf = self::check( $req );
			if ( $csrf instanceof \WP_Error ) return $csrf;
			return ( $inner )( $req );
		};
	}
}
