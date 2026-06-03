<?php
declare( strict_types=1 );

namespace Therum\Auth;

/**
 * REST routes for token management.
 *
 *   GET    /wp-json/therum/v1/tokens                    list current user's tokens
 *   POST   /wp-json/therum/v1/tokens                    issue a new token
 *   DELETE /wp-json/therum/v1/tokens/(?P<id>\d+)        revoke a token by ID
 *
 * Permission model:
 *   - GET / POST / DELETE all require an authenticated user with the
 *     `read` capability minimum (i.e. any logged-in user can manage their own tokens).
 *   - DELETE additionally checks the token belongs to the current user, unless
 *     the user has `manage_options` (admin can revoke any).
 *   - All mutating endpoints go through Csrf::check().
 */
final class RestRoutes {

	public static function register(): void {
		register_rest_route( 'therum/v1', '/tokens', [
			[
				'methods'             => 'GET',
				'callback'            => [ self::class, 'list_tokens' ],
				'permission_callback' => [ self::class, 'auth' ],
			],
			[
				'methods'             => 'POST',
				'callback'            => [ self::class, 'issue_token' ],
				'permission_callback' => Csrf::guard( [ self::class, 'auth' ] ),
				'args' => [
					'name'   => [ 'type' => 'string', 'required' => true ],
					'scopes' => [ 'type' => 'array',  'required' => true ],
				],
			],
		] );

		register_rest_route( 'therum/v1', '/tokens/(?P<id>\d+)', [
			'methods'             => 'DELETE',
			'callback'            => [ self::class, 'revoke_token' ],
			'permission_callback' => Csrf::guard( [ self::class, 'auth' ] ),
			'args' => [
				'id' => [ 'type' => 'integer', 'required' => true ],
			],
		] );

		register_rest_route( 'therum/v1', '/tokens/scopes', [
			'methods'             => 'GET',
			'callback'            => [ self::class, 'list_scopes' ],
			'permission_callback' => [ self::class, 'auth' ],
		] );
	}

	public static function auth(): bool {
		return is_user_logged_in() && current_user_can( 'read' );
	}

	public static function list_tokens( \WP_REST_Request $req ): \WP_REST_Response {
		$uid    = get_current_user_id();
		$tokens = TokenRegistry::all_for_user( $uid );

		$out = array_map( static fn( Token $t ) => [
			'id'            => $t->id,
			'name'          => $t->name,
			'prefix'        => $t->prefix,
			'scopes'        => $t->scopes,
			'created_at'    => $t->created_at,
			'last_used_at'  => $t->last_used_at,
			'last_used_ip'  => $t->last_used_ip,
			'revoked_at'    => $t->revoked_at,
			'is_active'     => $t->is_active(),
		], $tokens );

		return new \WP_REST_Response( [ 'tokens' => $out ], 200 );
	}

	public static function list_scopes( \WP_REST_Request $req ): \WP_REST_Response {
		$uid    = get_current_user_id();
		$all    = Scopes::all();
		$grantable = [];
		foreach ( $all as $id => $meta ) {
			$can_grant = user_can( $uid, Scopes::required_cap( $id ) );
			$grantable[] = [
				'id'        => $id,
				'label'     => $meta['label'] ?? $id,
				'cap'       => $meta['cap']   ?? 'manage_options',
				'grantable' => (bool) $can_grant,
			];
		}
		return new \WP_REST_Response( [ 'scopes' => $grantable ], 200 );
	}

	public static function issue_token( \WP_REST_Request $req ): \WP_REST_Response {
		$uid    = get_current_user_id();
		$name   = (string) $req->get_param( 'name' );
		$scopes = (array) $req->get_param( 'scopes' );
		$scopes = array_map( 'strval', $scopes );

		try {
			$r = TokenRegistry::issue( $uid, $name, $scopes );
		} catch ( \Throwable $e ) {
			return new \WP_REST_Response( [ 'error' => $e->getMessage() ], 400 );
		}

		/** @var Token $obj */
		$obj = $r['object'];

		return new \WP_REST_Response( [
			'token' => $r['token'],          // SHOWN ONCE
			'meta'  => [
				'id'         => $obj->id,
				'name'       => $obj->name,
				'prefix'     => $obj->prefix,
				'scopes'     => $obj->scopes,
				'created_at' => $obj->created_at,
			],
		], 201 );
	}

	public static function revoke_token( \WP_REST_Request $req ): \WP_REST_Response {
		$uid = get_current_user_id();
		$id  = (int) $req['id'];

		// Find + authorize
		$all = TokenRegistry::all_for_user( $uid );
		$own = false;
		foreach ( $all as $t ) {
			if ( $t->id === $id ) { $own = true; break; }
		}

		if ( ! $own && ! current_user_can( 'manage_options' ) ) {
			return new \WP_REST_Response( [ 'error' => 'Forbidden.' ], 403 );
		}

		$ok = TokenRegistry::revoke( $id );
		if ( ! $ok ) {
			return new \WP_REST_Response( [ 'error' => 'Token not found or already revoked.' ], 404 );
		}

		return new \WP_REST_Response( [ 'revoked' => $id ], 200 );
	}
}
