<?php
declare( strict_types=1 );

namespace Therum\Auth;

/**
 * Therum Token Registry — DB operations for capability-scoped API tokens.
 *
 * Storage: wp_therum_tokens (single table, dbDelta-managed).
 *
 * Token format: `tro_<48 base62 chars>` — 52 chars total, ~256 bits entropy.
 * Stored as SHA-256(token) in the DB; plaintext is shown ONCE at issue time
 * and never persisted. Leaked DB ≠ leaked tokens.
 *
 * @see Token       value object returned by lookups
 * @see Scopes      scope registry
 * @see Middleware  REST request guard
 */
final class TokenRegistry {

	private const TABLE       = 'therum_tokens';
	private const DB_VERSION  = 2; // v2: added expires_at column
	private const VERSION_OPT = 'therum_tokens_db_version';

	private const TOKEN_PREFIX = 'tro_';
	private const TOKEN_BODY_BYTES = 36; // 36 raw bytes → 48 base64url chars
	private const PREFIX_LENGTH = 12;    // chars stored for display

	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE;
	}

	/**
	 * Idempotent table install / migration. Safe to call on every admin_init.
	 */
	public static function install(): void {
		$installed = (int) get_option( self::VERSION_OPT, 0 );
		if ( $installed >= self::DB_VERSION ) return;

		global $wpdb;
		$table   = self::table_name();
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			name VARCHAR(120) NOT NULL DEFAULT '',
			user_id BIGINT UNSIGNED NOT NULL,
			token_hash CHAR(64) NOT NULL,
			prefix VARCHAR(16) NOT NULL DEFAULT '',
			scopes TEXT NOT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			last_used_at DATETIME NULL DEFAULT NULL,
			last_used_ip VARCHAR(45) NULL DEFAULT NULL,
			revoked_at DATETIME NULL DEFAULT NULL,
			expires_at DATETIME NULL DEFAULT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY token_hash (token_hash),
			KEY user_id (user_id),
			KEY revoked_at (revoked_at)
		) {$charset};";

		if ( ! function_exists( 'dbDelta' ) ) require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		update_option( self::VERSION_OPT, self::DB_VERSION, false );
	}

	/**
	 * Issue a new token for a user with the requested scopes.
	 *
	 * @param  int                          $user_id     WP user the token authenticates as
	 * @param  string                       $name        Human label
	 * @param  list<string>                 $scopes      Requested scopes (will be filtered by user's caps)
	 * @param  ?int                         $ttl_seconds Optional lifetime in seconds; null = never expires
	 * @return array{token: string, object: Token}      The plaintext token (show ONCE) + Token value object
	 *
	 * @throws \RuntimeException on insert failure
	 */
	public static function issue( int $user_id, string $name, array $scopes, ?int $ttl_seconds = null ): array {
		$scopes = Scopes::allowed_for_user( $scopes, $user_id );
		if ( empty( $scopes ) ) {
			throw new \RuntimeException( 'No grantable scopes for this user.' );
		}

		// Generate plaintext token: prefix + base64url-encoded random bytes
		$body     = self::base64url( random_bytes( self::TOKEN_BODY_BYTES ) );
		$plain    = self::TOKEN_PREFIX . $body;
		$prefix12 = substr( $plain, 0, self::PREFIX_LENGTH );
		$hash     = hash( 'sha256', $plain );
		$now      = current_time( 'mysql', true );
		$expires  = ( $ttl_seconds !== null && $ttl_seconds > 0 )
			? gmdate( 'Y-m-d H:i:s', time() + $ttl_seconds )
			: null;

		global $wpdb;
		$inserted = $wpdb->insert(
			self::table_name(),
			[
				'name'       => $name !== '' ? $name : 'Unnamed token',
				'user_id'    => $user_id,
				'token_hash' => $hash,
				'prefix'     => $prefix12,
				'scopes'     => implode( ' ', $scopes ),
				'created_at' => $now,
				'expires_at' => $expires,
			],
			[ '%s', '%d', '%s', '%s', '%s', '%s', '%s' ]
		);

		if ( $inserted === false ) {
			throw new \RuntimeException( 'Token insert failed: ' . $wpdb->last_error );
		}

		$id = (int) $wpdb->insert_id;

		return [
			'token'  => $plain,
			'object' => new Token(
				id:         $id,
				name:       $name,
				user_id:    $user_id,
				prefix:     $prefix12,
				scopes:     $scopes,
				created_at: $now,
				expires_at: $expires,
			),
		];
	}

	/**
	 * Look up a token by its plaintext value.
	 *
	 * Returns null if the token doesn't exist, is revoked, or the user behind
	 * it no longer exists / is disabled.
	 */
	public static function find_by_token( string $plain ): ?Token {
		if ( $plain === '' || ! str_starts_with( $plain, self::TOKEN_PREFIX ) ) return null;

		$hash = hash( 'sha256', $plain );

		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM " . self::table_name() . " WHERE token_hash = %s LIMIT 1",
			$hash
		), ARRAY_A );

		if ( ! is_array( $row ) ) return null;

		$token = Token::from_row( $row );
		if ( $token->is_revoked() ) return null;
		if ( $token->is_expired() ) return null;
		if ( ! get_userdata( $token->user_id ) ) return null;

		return $token;
	}

	public static function revoke( int $id ): bool {
		global $wpdb;
		$result = $wpdb->update(
			self::table_name(),
			[ 'revoked_at' => current_time( 'mysql', true ) ],
			[ 'id' => $id, 'revoked_at' => null ],
			[ '%s' ],
			[ '%d', '%s' ]
		);
		// Distinguish DB error from "already revoked / not found". $wpdb->update
		// returns false on DB error and an int (rows affected) otherwise. Log
		// the DB error so callers don't treat a failed write as a no-op.
		if ( $result === false ) {
			error_log( '[therum-auth] TokenRegistry::revoke DB error for token #' . $id . ': ' . $wpdb->last_error );
			return false;
		}
		return $result > 0;
	}

	public static function mark_used( int $token_id, string $ip ): void {
		global $wpdb;
		$wpdb->update(
			self::table_name(),
			[
				'last_used_at' => current_time( 'mysql', true ),
				'last_used_ip' => substr( $ip, 0, 45 ),
			],
			[ 'id' => $token_id ],
			[ '%s', '%s' ],
			[ '%d' ]
		);
	}

	/**
	 * @return list<Token>
	 */
	public static function all_for_user( int $user_id ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM " . self::table_name() . " WHERE user_id = %d ORDER BY created_at DESC",
			$user_id
		), ARRAY_A );

		if ( ! is_array( $rows ) ) return [];

		return array_map( static fn( $r ) => Token::from_row( (array) $r ), $rows );
	}

	/** RFC 4648 §5 base64url, no padding. */
	private static function base64url( string $bytes ): string {
		return rtrim( strtr( base64_encode( $bytes ), '+/', '-_' ), '=' );
	}
}
