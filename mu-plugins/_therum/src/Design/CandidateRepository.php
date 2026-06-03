<?php
declare( strict_types=1 );

namespace Therum\Design;

/**
 * Storage for derived candidate kits.
 *
 * Backed by a single autoload-OFF option (`therum_design_candidates`) holding
 * a map of id → array shape. The autoload-off matters: candidate kits can be
 * large (kBs to tens of kBs) and we don't want to bloat every-request loads.
 *
 * Applied kits get a parallel record in `therum_design_kits` (the list surface
 * for BrandList tool). Candidates remain available so reapply / refinement is
 * cheap.
 */
final class CandidateRepository {

	private const OPT_CANDIDATES = 'therum_design_candidates';
	private const OPT_APPLIED    = 'therum_design_kits';

	public static function save( Candidate $c ): void {
		$all = (array) get_option( self::OPT_CANDIDATES, [] );
		$all[ $c->id ] = $c->to_array();
		update_option( self::OPT_CANDIDATES, $all, false );
	}

	public static function find( string $id ): ?Candidate {
		$all = (array) get_option( self::OPT_CANDIDATES, [] );
		if ( ! isset( $all[ $id ] ) || ! is_array( $all[ $id ] ) ) return null;
		return Candidate::from_array( $all[ $id ] );
	}

	public static function delete( string $id ): bool {
		$all = (array) get_option( self::OPT_CANDIDATES, [] );
		if ( ! isset( $all[ $id ] ) ) return false;
		unset( $all[ $id ] );
		update_option( self::OPT_CANDIDATES, $all, false );
		return true;
	}

	/**
	 * @return array<string, array<string, mixed>>  candidate-id → summary
	 */
	public static function list(): array {
		$all = (array) get_option( self::OPT_CANDIDATES, [] );
		$out = [];
		foreach ( $all as $id => $row ) {
			if ( ! is_array( $row ) ) continue;
			$out[ (string) $id ] = [
				'id'         => (string) ( $row['id'] ?? $id ),
				'label'      => (string) ( $row['label'] ?? '' ),
				'derived_at' => (string) ( $row['derived_at'] ?? '' ),
				'stats'      => (array)  ( $row['stats'] ?? [] ),
			];
		}
		return $out;
	}

	/**
	 * Record that a candidate has been APPLIED — moves it into the BrandList
	 * surface so MCP clients can see it as a known kit.
	 */
	public static function mark_applied( Candidate $c ): void {
		$applied = (array) get_option( self::OPT_APPLIED, [] );
		$applied[ $c->id ] = [
			'label'      => $c->label,
			'derived_at' => $c->derived_at,
			'applied_at' => gmdate( 'Y-m-d\TH:i:s\Z' ),
		];
		update_option( self::OPT_APPLIED, $applied, false );
	}
}
