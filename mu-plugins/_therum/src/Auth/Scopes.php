<?php
declare( strict_types=1 );

namespace Therum\Auth;

/**
 * Scope registry for Therum API tokens.
 *
 * Scopes are dotted strings with optional wildcard suffix: `mcp.*` covers all
 * scopes starting with `mcp.`. The `*` scope covers everything (admin tokens
 * only). Scopes map to minimum WP capabilities — issuing a scope requires the
 * issuer's WP user to already have the underlying capability.
 *
 * Phase 5.1 ships an initial set; downstream code (Phase 2 therum-mcp, etc.)
 * registers more via the `therum_token_scopes` filter.
 */
final class Scopes {

	/**
	 * Phase 5.1 initial scope catalogue.
	 *
	 * Shape: scope-id => [ 'label' => human label, 'cap' => required WP cap ].
	 *
	 * @var array<string, array{label: string, cap: string}>
	 */
	private const INITIAL = [
		// Therum admin surface
		'therum.read'      => [ 'label' => 'Therum — read',          'cap' => 'read' ],
		'therum.write'     => [ 'label' => 'Therum — write',         'cap' => 'edit_posts' ],
		'therum.dangerous' => [ 'label' => 'Therum — dangerous (code/script writes)', 'cap' => 'unfiltered_html' ],

		// MCP surface (used by Phase 2 therum-mcp.php; reserved here so the
		// scope is recognised even before the MCP module ships)
		'mcp.read'         => [ 'label' => 'MCP — read',             'cap' => 'read' ],
		'mcp.write'        => [ 'label' => 'MCP — write',            'cap' => 'edit_posts' ],
		'mcp.dangerous'    => [ 'label' => 'MCP — dangerous',        'cap' => 'unfiltered_html' ],
		'mcp.source.rebuild' => [ 'label' => 'MCP — source.rebuild', 'cap' => 'manage_options' ],
		'mcp.design.derive'  => [ 'label' => 'MCP — design.derive',  'cap' => 'edit_theme_options' ],

		// Special: admin-only catch-all
		'*'                => [ 'label' => 'All scopes (admin token)', 'cap' => 'manage_options' ],
	];

	/**
	 * The full known scope catalogue, after filter expansion.
	 *
	 * Other modules may register scopes via:
	 *   add_filter( 'therum_token_scopes', function( $scopes ) {
	 *       $scopes['my.scope'] = [ 'label' => 'My scope', 'cap' => 'edit_posts' ];
	 *       return $scopes;
	 *   } );
	 *
	 * @return array<string, array{label: string, cap: string}>
	 */
	public static function all(): array {
		/** @var array<string, array{label: string, cap: string}> $scopes */
		$scopes = (array) apply_filters( 'therum_token_scopes', self::INITIAL );
		return $scopes;
	}

	public static function is_valid( string $scope ): bool {
		return array_key_exists( $scope, self::all() );
	}

	/**
	 * The minimum WP capability required to issue a token carrying this scope.
	 */
	public static function required_cap( string $scope ): string {
		$all = self::all();
		return (string) ( $all[ $scope ]['cap'] ?? 'manage_options' );
	}

	public static function label( string $scope ): string {
		$all = self::all();
		return (string) ( $all[ $scope ]['label'] ?? $scope );
	}

	/**
	 * Filter the user-supplied scope list down to ones the issuer is allowed
	 * to grant. Unknown scopes and scopes above the issuer's cap are dropped.
	 *
	 * @param  list<string> $requested
	 * @return list<string>
	 */
	public static function allowed_for_user( array $requested, int $user_id ): array {
		$out = [];
		foreach ( array_unique( $requested ) as $scope ) {
			if ( ! is_string( $scope ) || ! self::is_valid( $scope ) ) continue;
			if ( ! user_can( $user_id, self::required_cap( $scope ) ) ) continue;
			$out[] = $scope;
		}
		return array_values( $out );
	}
}
