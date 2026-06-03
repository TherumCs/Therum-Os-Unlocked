<?php
declare( strict_types=1 );

namespace Therum\Design;

/**
 * Token-extraction contract — Scanner feeds every (key, value) leaf in element
 * settings to every registered Extractor. Implementations are responsible for:
 *
 *   1. Deciding whether the (path, value) pair belongs to their token type
 *      (e.g. Color extractor looks for path matches like *.background, *.color)
 *   2. Parsing the value into a normalised form
 *   3. Aggregating frequency / co-occurrence
 *   4. Producing a finalised slice of the candidate kit via tokens()
 */
interface Extractor {

	/**
	 * @param string                $path             dotted settings path
	 * @param string                $value            stringified leaf value
	 * @param array<string,mixed>   $element_context  full element row
	 */
	public function accept( string $path, string $value, array $element_context ): void;

	/**
	 * The slice of the candidate kit this extractor produces.
	 * Shape is extractor-specific; merged into Candidate->data by Derive.
	 *
	 * @return array<string, mixed>
	 */
	public function tokens(): array;
}
