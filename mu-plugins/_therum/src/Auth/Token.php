<?php
declare( strict_types=1 );

namespace Therum\Auth;

/**
 * Therum API Token — value object.
 *
 * A capability-scoped API token. Replaces WordPress Application Passwords for
 * Therum's own REST surface (MCP, internal admin AJAX). Stored hashed in
 * wp_therum_tokens; the plaintext value is shown exactly once at issue time
 * and never persisted.
 *
 * @see TokenRegistry  for issue / lookup / revoke
 * @see Scopes         for the scope registry
 * @see Middleware     for the REST request guard
 */
final class Token {

	/**
	 * @param int           $id            Primary key in wp_therum_tokens.
	 * @param string        $name          Human-readable label (e.g. "Claude Code — local").
	 * @param int           $user_id       WP user this token authenticates as.
	 * @param string        $prefix        First 12 chars of the plaintext token, kept for display.
	 * @param list<string>  $scopes        Granted scopes (see Scopes::all()).
	 * @param string        $created_at    MySQL DATETIME, UTC.
	 * @param ?string       $last_used_at  MySQL DATETIME or null if never used.
	 * @param ?string       $last_used_ip  IPv4/IPv6 or null.
	 * @param ?string       $revoked_at    MySQL DATETIME or null if active.
	 * @param ?string       $expires_at    MySQL DATETIME (UTC) or null = never expires.
	 */
	public function __construct(
		public readonly int $id,
		public readonly string $name,
		public readonly int $user_id,
		public readonly string $prefix,
		public readonly array $scopes,
		public readonly string $created_at,
		public readonly ?string $last_used_at = null,
		public readonly ?string $last_used_ip = null,
		public readonly ?string $revoked_at = null,
		public readonly ?string $expires_at = null,
	) {}

	/**
	 * Does this token carry the requested scope?
	 *
	 * Wildcards: a scope grant of `mcp.*` covers `mcp.read`, `mcp.write`, etc.
	 * A grant of `*` covers everything (only ever issued to admin-level tokens).
	 */
	public function has_scope( string $required ): bool {
		foreach ( $this->scopes as $granted ) {
			if ( $granted === $required || $granted === '*' ) return true;
			// Wildcard suffix: "mcp.*" matches "mcp.read", "mcp.write", "mcp.foo.bar"
			if ( str_ends_with( $granted, '.*' ) ) {
				$prefix = substr( $granted, 0, -1 ); // "mcp."
				if ( str_starts_with( $required, $prefix ) ) return true;
			}
		}
		return false;
	}

	public function is_revoked(): bool {
		return $this->revoked_at !== null;
	}

	/** True once the (optional) expiry timestamp has passed. */
	public function is_expired(): bool {
		if ( $this->expires_at === null || $this->expires_at === '' ) return false;
		return strtotime( $this->expires_at . ' UTC' ) < time();
	}

	public function is_active(): bool {
		return ! $this->is_revoked() && ! $this->is_expired();
	}

	/**
	 * Build a Token from a row returned by $wpdb->get_row( ..., ARRAY_A ).
	 *
	 * @param array<string,mixed> $row
	 */
	public static function from_row( array $row ): self {
		return new self(
			id:           (int) ( $row['id'] ?? 0 ),
			name:         (string) ( $row['name'] ?? '' ),
			user_id:      (int) ( $row['user_id'] ?? 0 ),
			prefix:       (string) ( $row['prefix'] ?? '' ),
			scopes:       array_values( array_filter( explode( ' ', (string) ( $row['scopes'] ?? '' ) ) ) ),
			created_at:   (string) ( $row['created_at'] ?? '' ),
			last_used_at: isset( $row['last_used_at'] ) ? (string) $row['last_used_at'] : null,
			last_used_ip: isset( $row['last_used_ip'] ) ? (string) $row['last_used_ip'] : null,
			revoked_at:   isset( $row['revoked_at'] )   ? (string) $row['revoked_at']   : null,
			expires_at:   isset( $row['expires_at'] ) && $row['expires_at'] !== null ? (string) $row['expires_at'] : null,
		);
	}
}
