<?php
/**
 * Therum OS — Therum_OAuth
 *
 * Extracted from therum-admin.php as part of the 1.9.x split. Same
 * class, same behavior; required back in from therum-admin.php at the
 * original load position to preserve declaration order.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class Therum_OAuth {

	const APPS_OPTION = 'therum_oauth_apps';
	const STATE_TTL   = 300; // seconds

	public static function redirect_uri(): string {
		return rest_url( 'therum/v1/oauth/callback' );
	}

	public static function get_app( string $provider ): array {
		$all = (array) get_option( self::APPS_OPTION, [] );
		$row = $all[ $provider ] ?? null;
		if ( ! is_array( $row ) ) return [ 'client_id' => '', 'client_secret' => '' ];
		return [
			'client_id'     => (string) ( $row['client_id'] ?? '' ),
			'client_secret' => Therum_Connections_Page::decrypt( (string) ( $row['client_secret'] ?? '' ) ),
		];
	}

	public static function has_app( string $provider ): bool {
		$app = self::get_app( $provider );
		return $app['client_id'] !== '' && $app['client_secret'] !== '';
	}

	public static function ajax_save_app(): void {
		if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'forbidden', 403 );
		check_ajax_referer( 'therum_connections', 'nonce' );

		$provider     = sanitize_key( $_POST['provider'] ?? '' );
		$client_id    = sanitize_text_field( wp_unslash( $_POST['client_id'] ?? '' ) );
		$client_secret = sanitize_text_field( wp_unslash( $_POST['client_secret'] ?? '' ) );
		if ( ! $provider || ! $client_id || ! $client_secret ) wp_send_json_error( 'missing fields' );

		$all = (array) get_option( self::APPS_OPTION, [] );
		$all[ $provider ] = [
			'client_id'     => $client_id,
			'client_secret' => Therum_Connections_Page::encrypt( $client_secret ),
			'saved_at'      => time(),
		];
		update_option( self::APPS_OPTION, $all, false );
		wp_send_json_success( [ 'provider' => $provider, 'redirect_uri' => self::redirect_uri() ] );
	}

	/** Looks up the provider row from Therum_Connections_Page::providers(). */
	private static function find_provider( string $id ): ?array {
		$all = Therum_Connections_Page::providers();
		foreach ( $all as $cat => $list ) {
			foreach ( $list as $p ) {
				if ( ( $p['id'] ?? '' ) === $id ) return $p;
			}
		}
		return null;
	}

	/** Entry point: admin clicks "Sign in" → we build the authorize URL and 302. */
	public static function start(): void {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden', 403 );
		check_admin_referer( 'therum_oauth_start' );

		$provider = sanitize_key( $_GET['provider'] ?? '' );
		$p        = self::find_provider( $provider );
		if ( ! $p || empty( $p['oauth_authorize_url'] ) ) wp_die( 'Unknown OAuth provider: ' . esc_html( $provider ) );

		$app = self::get_app( $provider );
		if ( $app['client_id'] === '' ) wp_die( 'Set up the OAuth app for ' . esc_html( $p['name'] ) . ' first.' );

		// CSRF state — short-lived, single-use, bound to the requesting user.
		$state = wp_generate_uuid4();
		set_transient( 'therum_oauth_' . $state, [
			'provider' => $provider,
			'user_id'  => get_current_user_id(),
		], self::STATE_TTL );

		$args = [
			'client_id'     => $app['client_id'],
			'redirect_uri'  => self::redirect_uri(),
			'response_type' => 'code',
			'state'         => $state,
		];
		if ( ! empty( $p['oauth_scope'] ) ) $args['scope'] = $p['oauth_scope'];
		// Dropbox + Google require token_access_type=offline for refresh tokens.
		if ( in_array( $provider, [ 'dropbox', 'gdrive' ], true ) ) $args['token_access_type'] = 'offline';

		wp_redirect( add_query_arg( $args, $p['oauth_authorize_url'] ) );
		exit;
	}

	/** Callback: provider redirects user back here with ?code + ?state. */
	public static function callback( \WP_REST_Request $req ) {
		$state_id = sanitize_text_field( (string) $req->get_param( 'state' ) );
		$code     = sanitize_text_field( (string) $req->get_param( 'code' ) );
		$err      = sanitize_text_field( (string) $req->get_param( 'error' ) );

		$base_back = admin_url( 'admin.php?page=therum-connections' );

		if ( $err ) { wp_safe_redirect( add_query_arg( 'oauth_err', rawurlencode( $err ), $base_back ) ); exit; }
		if ( ! $state_id || ! $code ) { wp_safe_redirect( add_query_arg( 'oauth_err', 'missing-params', $base_back ) ); exit; }

		$state = get_transient( 'therum_oauth_' . $state_id );
		if ( ! $state || empty( $state['provider'] ) ) { wp_safe_redirect( add_query_arg( 'oauth_err', 'state-expired', $base_back ) ); exit; }
		delete_transient( 'therum_oauth_' . $state_id );

		// Restore the user session — REST callbacks don't carry the admin
		// cookie context, so set_current_user lets save_connection() use
		// per-user storage if it wants. (Today storage is install-wide.)
		//
		// Re-check capability: the user may have been demoted between issuing
		// the OAuth handshake and the callback firing. We refuse to persist
		// the token under an account that no longer has manage_options.
		$state_user_id = (int) ( $state['user_id'] ?? 0 );
		if ( $state_user_id <= 0 || ! user_can( $state_user_id, 'manage_options' ) ) {
			wp_safe_redirect( add_query_arg( 'oauth_err', 'cap-revoked', $base_back ) );
			exit;
		}
		wp_set_current_user( $state_user_id );

		$provider = $state['provider'];
		$p        = self::find_provider( $provider );
		if ( ! $p ) { wp_safe_redirect( add_query_arg( 'oauth_err', 'unknown-provider', $base_back ) ); exit; }

		$app = self::get_app( $provider );

		// Exchange the auth code for an access token.
		$body = [
			'code'          => $code,
			'grant_type'    => 'authorization_code',
			'client_id'     => $app['client_id'],
			'client_secret' => $app['client_secret'],
			'redirect_uri'  => self::redirect_uri(),
		];
		$res = wp_remote_post( $p['oauth_token_url'], [
			'timeout' => 20,
			'headers' => [ 'content-type' => 'application/x-www-form-urlencoded', 'accept' => 'application/json' ],
			'body'    => $body,
		] );
		if ( is_wp_error( $res ) ) { wp_safe_redirect( add_query_arg( 'oauth_err', rawurlencode( $res->get_error_message() ), $base_back ) ); exit; }
		$code_status = wp_remote_retrieve_response_code( $res );
		$data        = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( $code_status !== 200 || empty( $data['access_token'] ) ) {
			$msg = $data['error_description'] ?? $data['error'] ?? ( 'token-exchange-' . $code_status );
			wp_safe_redirect( add_query_arg( 'oauth_err', rawurlencode( (string) $msg ), $base_back ) );
			exit;
		}

		// Token in hand. Stash in the same vault as API keys so all downstream
		// helpers (Therum_Connections_Page::get_credential) work identically
		// regardless of how the credential arrived.
		$row = [
			'key'           => (string) $data['access_token'],
			'key2'          => (string) ( $data['refresh_token'] ?? '' ),
			'label'         => 'via OAuth',
			'connected_at'  => time(),
			'oauth'         => true,
			'token_type'    => (string) ( $data['token_type'] ?? 'Bearer' ),
			'expires_in'    => (int)    ( $data['expires_in'] ?? 0 ),
		];
		// Re-use the encryption path
		$reflect = $row;
		$reflect['key']  = Therum_Connections_Page::encrypt( $reflect['key'] );
		$reflect['key2'] = $reflect['key2'] !== '' ? Therum_Connections_Page::encrypt( $reflect['key2'] ) : '';
		$all = (array) get_option( 'therum_connections', [] );
		$all[ $provider ] = $reflect;
		update_option( 'therum_connections', $all, false );

		wp_safe_redirect( add_query_arg( 'oauth_ok', $provider, $base_back ) );
		exit;
	}
}
