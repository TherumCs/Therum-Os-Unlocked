<?php
declare( strict_types=1 );

namespace Therum\MCP\Tools;

use Therum\MCP\McpError;
use Therum\MCP\Tool;

/**
 * therum.preview.url — return a frontend preview URL for a page/post.
 *
 * Wraps the `therum_view_links` filter pattern used by the existing
 * therum-content.php view-links surface. If a page is draft or password-
 * protected, generates a signed preview link via WP's standard
 * `get_preview_post_link()`. Public pages just return the permalink.
 *
 * Scope: mcp.read
 */
final class PreviewUrl extends Tool {

	public function name(): string {
		return 'therum.preview.url';
	}

	public function description(): string {
		return 'Return the frontend URL for a page or post. Returns the canonical permalink for published content, or a signed preview link for drafts/pending/password-protected content.';
	}

	public function input_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'id'   => [
					'type'        => 'integer',
					'description' => 'Post ID (mutually exclusive with slug).',
				],
				'slug' => [
					'type'        => 'string',
					'description' => 'Post slug (mutually exclusive with id).',
				],
				'post_type' => [
					'type'        => 'string',
					'description' => 'Post type when resolving by slug. Default: any.',
				],
			],
			'required'   => [],
		];
	}

	public function required_scope(): string {
		return 'mcp.read';
	}

	public function call( array $arguments ): array {
		$id   = isset( $arguments['id'] ) ? (int) $arguments['id'] : 0;
		$slug = (string) ( $arguments['slug'] ?? '' );
		$type = (string) ( $arguments['post_type'] ?? '' );

		$post = null;

		if ( $id > 0 ) {
			$post = get_post( $id );
		} elseif ( $slug !== '' ) {
			$args = [
				'name'        => $slug,
				'post_status' => [ 'publish', 'draft', 'pending', 'private', 'future' ],
				'numberposts' => 1,
			];
			if ( $type !== '' ) $args['post_type'] = $type;
			$found = get_posts( $args );
			$post  = $found[0] ?? null;
		} else {
			throw new McpError( -32602, 'Provide either "id" or "slug".' );
		}

		if ( ! $post instanceof \WP_Post ) {
			throw new McpError( -32602, 'No post found matching the given criteria.' );
		}

		// Per-object authorization. mcp.read alone must not mint working preview
		// links for unpublished/private content the caller can't actually read.
		// Published, non-password-protected posts are world-readable, so only
		// gate the non-public ones on read_post for the resolved post.
		$is_public = in_array( $post->post_status, [ 'publish' ], true ) && empty( $post->post_password );
		if ( ! $is_public && ! current_user_can( 'read_post', $post->ID ) ) {
			throw new McpError(
				-32604,
				'Not authorized to preview this post.',
				[ 'post_id' => $post->ID ]
			);
		}

		$url = $is_public
			? (string) get_permalink( $post )
			: (string) get_preview_post_link( $post );

		return [
			'content' => [
				[
					'type' => 'text',
					'text' => sprintf(
						"%s\n\nID: %d\nType: %s\nStatus: %s\nTitle: %s",
						$url,
						$post->ID,
						$post->post_type,
						$post->post_status,
						$post->post_title
					),
				],
			],
			'structuredContent' => [
				'url'         => $url,
				'post_id'     => $post->ID,
				'post_type'   => $post->post_type,
				'status'      => $post->post_status,
				'title'       => $post->post_title,
				'is_preview'  => ! $is_public,
			],
		];
	}
}
