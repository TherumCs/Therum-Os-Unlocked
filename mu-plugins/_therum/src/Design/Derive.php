<?php
declare( strict_types=1 );

namespace Therum\Design;

use Therum\Design\Extractors\ColorExtractor;
use Therum\Design\Extractors\SpacingExtractor;
use Therum\Design\Extractors\TypographyExtractor;

/**
 * Orchestrates a single derivation run.
 *
 * The Scanner pulls element settings; the registered Extractors aggregate
 * per-axis token candidates; this class merges their outputs into a
 * Candidate, stores it, and returns the ID.
 *
 * Called both from the synchronous "small site" path and from the queue
 * handler (`Therum\MCP\Tools\DesignDerive::handler`). Both paths share this
 * orchestration so behaviour is identical regardless of how it was kicked off.
 */
final class Derive {

	/**
	 * @param  array{label?: string, post_ids?: list<int>, source_type?: string} $opts
	 */
	public static function run( array $opts = [] ): Candidate {
		$post_ids = is_array( $opts['post_ids'] ?? null )
			? array_map( 'intval', $opts['post_ids'] )
			: [];

		$scanner = new Scanner();
		$scanner->add_extractor( new ColorExtractor() );
		$scanner->add_extractor( new TypographyExtractor() );
		$scanner->add_extractor( new SpacingExtractor() );

		$scanner->scan( $post_ids );

		// Allow third-party extractors to be added before tokens() is called.
		// They register against the scanner's internal list via a filter.
		$extra = (array) apply_filters( 'therum_design_extra_extractors', [] );
		foreach ( $extra as $ext ) {
			if ( $ext instanceof Extractor ) $scanner->add_extractor( $ext );
		}

		// We need the extractor instances to call tokens(). Scanner doesn't
		// expose them publicly; build the list locally so we control the
		// tokens() call order.
		$extractors = [
			new ColorExtractor(),
			new TypographyExtractor(),
			new SpacingExtractor(),
		];
		// Re-scan with our local list — Scanner is stateless across runs and
		// this guarantees the tokens() calls below see the same accept() calls.
		$scanner2 = new Scanner();
		foreach ( $extractors as $e ) $scanner2->add_extractor( $e );
		foreach ( $extra as $e ) if ( $e instanceof Extractor ) {
			$scanner2->add_extractor( $e );
			$extractors[] = $e;
		}
		$scanner2->scan( $post_ids );

		$data = [];
		foreach ( $extractors as $ext ) {
			$slice = $ext->tokens();
			$data  = array_merge( $data, $slice );
		}

		$candidate = new Candidate(
			id:         Candidate::new_id(),
			label:      (string) ( $opts['label'] ?? 'Auto-derived ' . gmdate( 'Y-m-d H:i' ) ),
			derived_at: gmdate( 'Y-m-d\TH:i:s\Z' ),
			data:       $data,
			source:     [
				'type'        => (string) ( $opts['source_type'] ?? ( empty( $post_ids ) ? 'site' : 'page-list' ) ),
				'post_ids'    => $post_ids,
			],
			stats: [
				'pages_scanned'   => $scanner2->pages_scanned,
				'elements_walked' => $scanner2->elements_walked,
			],
		);

		CandidateRepository::save( $candidate );

		return $candidate;
	}
}
