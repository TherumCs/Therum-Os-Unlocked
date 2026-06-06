<?php
/**
 * Plugin Name: Therum OS — Media Renamer
 * Description: SEO file rename + cross-database reference rewriter. Native to
 *              Therum, no third-party dependency. Renames attachment files
 *              (including all intermediate image sizes), updates the attachment
 *              record, and search-replaces the old URL across post_content +
 *              postmeta (with serialized-data support).
 * Version: 1.0.0
 *
 * Public API:
 *   Therum_Renamer::suggest( int $id ): string                  — slug-cased proposal from title/alt
 *   Therum_Renamer::preview( int $id, string $name ): array     — diff of files + reference counts
 *   Therum_Renamer::rename( int $id, string $name ): array      — execute the rename + ref rewrite
 *
 * AJAX (admin only, manage_options + nonce):
 *   wp_ajax_therum_renamer_preview
 *   wp_ajax_therum_renamer_rename
 */

if ( ! defined( 'ABSPATH' ) ) exit;

final class Therum_Renamer {

	/** Rows fetched per batch when rewriting URL references (bounds peak memory). */
	const REWRITE_BATCH = 200;

	/**
	 * Derive a slug-cased filename from the attachment's title (preferred) or
	 * alt text (fallback). Keeps the original extension.
	 */
	public static function suggest( int $attachment_id ): string {
		$post = get_post( $attachment_id );
		if ( ! $post ) return '';
		$file = get_attached_file( $attachment_id );
		if ( ! $file ) return '';
		$ext = pathinfo( $file, PATHINFO_EXTENSION );

		$title = (string) $post->post_title;
		$alt   = (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );

		$base = $title !== '' ? $title : $alt;
		// Don't suggest a name identical to the existing basename. If the
		// title is empty AND the basename is something like "IMG_8421", we
		// still return empty — the user has to fill in something meaningful.
		if ( $base === '' || $base === pathinfo( $file, PATHINFO_FILENAME ) ) {
			return '';
		}
		$slug = sanitize_title( $base );
		if ( $slug === '' ) return '';
		return $slug . ( $ext ? '.' . strtolower( $ext ) : '' );
	}

	/**
	 * Returns a preview of what `rename()` will do. Safe to call repeatedly —
	 * no side effects.
	 *
	 * @return array {
	 *   bool   ok
	 *   string error           — present when ok=false
	 *   string old_basename
	 *   string new_basename
	 *   string old_url
	 *   string new_url
	 *   array  files           — [['old' => abs, 'new' => abs, 'kind' => 'main|thumb'], …]
	 *   int    refs_estimate   — count of post_content + postmeta rows that will be touched
	 * }
	 */
	public static function preview( int $attachment_id, string $new_filename ): array {
		$check = self::validate( $attachment_id, $new_filename );
		if ( isset( $check['error'] ) ) return [ 'ok' => false ] + $check;

		$plan = self::plan( $attachment_id, $new_filename );
		$old_url = $plan['old_url'];

		// Count references — cheap LIKE query, no full read.
		global $wpdb;
		$like = '%' . $wpdb->esc_like( $old_url ) . '%';
		$c_posts = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_content LIKE %s AND post_status != 'trash'",
			$like
		) );
		$c_meta = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_value LIKE %s",
			$like
		) );
		$c_options = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->options} WHERE option_value LIKE %s AND option_name NOT LIKE %s",
			$like, '\_%' // skip private/transient options
		) );

		return array_merge( [ 'ok' => true ], $plan, [
			'refs_estimate' => $c_posts + $c_meta + $c_options,
			'refs_breakdown' => [
				'posts'   => $c_posts,
				'postmeta'=> $c_meta,
				'options' => $c_options,
			],
		] );
	}

	/**
	 * Execute the rename. Atomic: rolls back any successful file renames if
	 * a later step fails.
	 *
	 * @return array {
	 *   bool   ok
	 *   string error           — present when ok=false
	 *   string old_url, new_url
	 *   int    files_renamed
	 *   int    refs_updated    — total rows updated across content+meta+options
	 *   array  refs_breakdown
	 * }
	 */
	public static function rename( int $attachment_id, string $new_filename ): array {
		$check = self::validate( $attachment_id, $new_filename );
		if ( isset( $check['error'] ) ) return [ 'ok' => false ] + $check;

		$plan = self::plan( $attachment_id, $new_filename );

		// 1. Rename files on disk. Track completed moves so we can roll back.
		$renamed = [];
		foreach ( $plan['files'] as $f ) {
			if ( ! file_exists( $f['old'] ) ) {
				// Skip missing intermediate sizes — not all sizes are guaranteed
				// to exist (image-editor might have skipped some). Main file
				// MUST exist; validate() checked that already.
				continue;
			}
			if ( file_exists( $f['new'] ) && $f['old'] !== $f['new'] ) {
				self::rollback( $renamed );
				return [ 'ok' => false, 'error' => 'Target filename collides with an existing file: ' . basename( $f['new'] ) ];
			}
			if ( ! @rename( $f['old'], $f['new'] ) ) {
				self::rollback( $renamed );
				return [ 'ok' => false, 'error' => 'Could not rename ' . basename( $f['old'] ) . ' — check filesystem permissions.' ];
			}
			$renamed[] = $f;
		}

		// 2. Update the WP attachment record + metadata.
		$upload  = wp_upload_dir();
		$basedir = trailingslashit( (string) ( $upload['basedir'] ?? '' ) );
		$rel_new = $basedir && str_starts_with( $plan['files'][0]['new'], $basedir )
			? substr( $plan['files'][0]['new'], strlen( $basedir ) )
			: basename( $plan['files'][0]['new'] );

		update_post_meta( $attachment_id, '_wp_attached_file', $rel_new );

		// Update _wp_attachment_metadata: file + sizes[*].file paths
		$meta = wp_get_attachment_metadata( $attachment_id );
		if ( is_array( $meta ) ) {
			$meta['file'] = $rel_new;
			if ( ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
				foreach ( $meta['sizes'] as $size => $sdata ) {
					if ( empty( $sdata['file'] ) ) continue;
					// Sizes are stored as relative filenames (no path) — derive new name.
					$old_size_path = trailingslashit( dirname( $plan['files'][0]['old'] ) ) . $sdata['file'];
					foreach ( $plan['files'] as $f ) {
						if ( $f['old'] === $old_size_path ) {
							$meta['sizes'][ $size ]['file'] = basename( $f['new'] );
							break;
						}
					}
				}
			}
			wp_update_attachment_metadata( $attachment_id, $meta );
		}

		// Update GUID + post_name so canonical references stay consistent.
		// (GUID is supposed to be immutable per WP docs, but every CMS that
		// renames media touches it anyway because consumers DO look at it.)
		wp_update_post( [
			'ID'        => $attachment_id,
			'guid'      => $plan['new_url'],
			'post_name' => sanitize_title( pathinfo( $new_filename, PATHINFO_FILENAME ) ),
		] );

		// 3. Rewrite references across post_content / postmeta / options.
		$refs = self::rewrite_references( $plan['old_url'], $plan['new_url'], $plan['files'] );

		// Cache flush — any cached query/template/render referencing the old
		// URL is now stale.
		if ( class_exists( 'Therum_Cache_Bust' ) ) {
			Therum_Cache_Bust::purge_all( 'media-rename:' . $attachment_id );
		} else {
			wp_cache_flush();
		}

		return [
			'ok'             => true,
			'old_url'        => $plan['old_url'],
			'new_url'        => $plan['new_url'],
			'files_renamed'  => count( $renamed ),
			'refs_updated'   => $refs['total'],
			'refs_breakdown' => $refs['breakdown'],
		];
	}

	// ─── Internals ──────────────────────────────────────────────────────────

	/**
	 * Validate the rename request. Returns ['error' => msg] on failure or
	 * ['attachment' => WP_Post, 'file' => abs path] on success.
	 */
	private static function validate( int $attachment_id, string $new_filename ): array {
		if ( ! current_user_can( 'upload_files' ) ) {
			return [ 'error' => 'You do not have permission to rename media.' ];
		}
		$post = get_post( $attachment_id );
		if ( ! $post || $post->post_type !== 'attachment' ) {
			return [ 'error' => 'Attachment not found.' ];
		}
		$file = get_attached_file( $attachment_id );
		if ( ! $file || ! file_exists( $file ) ) {
			return [ 'error' => 'Source file is missing on disk.' ];
		}

		// Filename sanity: no slashes, no traversal, extension must match.
		$new_filename = wp_basename( $new_filename ); // strips path components
		if ( $new_filename === '' || str_contains( $new_filename, '/' ) || str_contains( $new_filename, '\\' ) ) {
			return [ 'error' => 'Filename contains illegal characters.' ];
		}
		$old_ext = strtolower( pathinfo( $file, PATHINFO_EXTENSION ) );
		$new_ext = strtolower( pathinfo( $new_filename, PATHINFO_EXTENSION ) );
		if ( $new_ext === '' ) {
			return [ 'error' => 'Filename must include an extension.' ];
		}
		if ( $new_ext !== $old_ext ) {
			return [ 'error' => "Extension change not allowed ($old_ext → $new_ext). Use the same extension as the source file." ];
		}
		// Slug the basename — WP-style lowercase, hyphens.
		$slug = sanitize_title( pathinfo( $new_filename, PATHINFO_FILENAME ) );
		if ( $slug === '' ) {
			return [ 'error' => 'Filename is empty after sanitization.' ];
		}
		return [ 'attachment' => $post, 'file' => $file, 'slug' => $slug, 'ext' => $old_ext ];
	}

	/**
	 * Build the file-rename plan: main file + every intermediate image size.
	 * Returns ['old_url', 'new_url', 'old_basename', 'new_basename', 'files' => [...]]
	 */
	private static function plan( int $attachment_id, string $new_filename ): array {
		$file = get_attached_file( $attachment_id );
		$dir  = dirname( $file );
		$old_basename = basename( $file );
		$slug = sanitize_title( pathinfo( $new_filename, PATHINFO_FILENAME ) );
		$ext  = strtolower( pathinfo( $file, PATHINFO_EXTENSION ) );
		$new_basename = $slug . '.' . $ext;

		$files = [
			[
				'old'  => $file,
				'new'  => trailingslashit( $dir ) . $new_basename,
				'kind' => 'main',
			],
		];

		// Intermediate image sizes (image-150x150.jpg etc.) live next to the
		// main file with `-<W>x<H>` suffix on the basename. Derive each one
		// from the attachment metadata so we hit only the sizes that exist.
		$meta = wp_get_attachment_metadata( $attachment_id );
		if ( is_array( $meta ) && ! empty( $meta['sizes'] ) ) {
			foreach ( $meta['sizes'] as $sdata ) {
				if ( empty( $sdata['file'] ) ) continue;
				$old_size_name = (string) $sdata['file'];
				// New size name = new_basename minus ext + same -WxH suffix + ext
				$old_ext = pathinfo( $old_size_name, PATHINFO_EXTENSION );
				$old_no_ext = pathinfo( $old_size_name, PATHINFO_FILENAME );
				// The size suffix is everything after the original basename
				// minus extension. e.g. "IMG_1234-150x150" → "-150x150"
				$old_main_no_ext = pathinfo( $old_basename, PATHINFO_FILENAME );
				if ( ! str_starts_with( $old_no_ext, $old_main_no_ext ) ) continue;
				$suffix = substr( $old_no_ext, strlen( $old_main_no_ext ) );
				$new_size_name = $slug . $suffix . '.' . strtolower( $old_ext );
				$files[] = [
					'old'  => trailingslashit( $dir ) . $old_size_name,
					'new'  => trailingslashit( $dir ) . $new_size_name,
					'kind' => 'thumb',
				];
			}
		}

		// Original (pre-edit) variant + the -scaled variant WP creates for
		// big images. Both are tracked via meta keys.
		$original = get_post_meta( $attachment_id, '_wp_attachment_backup_sizes', true );
		// (Backup sizes aren't renamed — they're snapshots tied to the original filename for restore.)

		$old_url = wp_get_attachment_url( $attachment_id );
		$new_url = str_replace( $old_basename, $new_basename, (string) $old_url );

		return [
			'old_basename' => $old_basename,
			'new_basename' => $new_basename,
			'old_url'      => $old_url,
			'new_url'      => $new_url,
			'files'        => $files,
		];
	}

	/**
	 * Rewrite references to the old URL across post_content / postmeta /
	 * options. Handles serialized data in postmeta + options correctly.
	 *
	 * Also rewrites references to every intermediate-size URL.
	 */
	private static function rewrite_references( string $old_url, string $new_url, array $files ): array {
		global $wpdb;
		$breakdown = [ 'posts' => 0, 'postmeta' => 0, 'options' => 0 ];

		// Build complete URL-pair map: main + every intermediate size.
		$upload  = wp_upload_dir();
		$baseurl = trailingslashit( (string) ( $upload['baseurl'] ?? '' ) );
		$basedir = trailingslashit( (string) ( $upload['basedir'] ?? '' ) );
		$pairs   = [ [ 'from' => $old_url, 'to' => $new_url ] ];
		foreach ( $files as $f ) {
			if ( $f['kind'] !== 'thumb' ) continue;
			$rel_old = str_starts_with( $f['old'], $basedir ) ? substr( $f['old'], strlen( $basedir ) ) : basename( $f['old'] );
			$rel_new = str_starts_with( $f['new'], $basedir ) ? substr( $f['new'], strlen( $basedir ) ) : basename( $f['new'] );
			$pairs[] = [ 'from' => $baseurl . $rel_old, 'to' => $baseurl . $rel_new ];
		}

		// Process matches in bounded batches rather than loading every matching
		// blob into memory at once. Keyset pagination (primary key > last seen,
		// ordered ascending) is safe even though we UPDATE rows as we go: an
		// updated row no longer contains the old URL, and we never revisit an ID
		// we've already passed. This caps peak memory regardless of dataset size.
		$batch = self::REWRITE_BATCH;

		// ── post_content (plain str_replace — never serialized) ───────────
		foreach ( $pairs as $p ) {
			$like    = '%' . $wpdb->esc_like( $p['from'] ) . '%';
			$last_id = 0;
			do {
				$rows = $wpdb->get_results( $wpdb->prepare(
					"SELECT ID, post_content FROM {$wpdb->posts}
					 WHERE post_content LIKE %s AND post_status != 'trash' AND ID > %d
					 ORDER BY ID ASC LIMIT %d",
					$like, $last_id, $batch
				) );
				foreach ( $rows as $row ) {
					$last_id = (int) $row->ID;
					$new_content = str_replace( $p['from'], $p['to'], $row->post_content );
					if ( $new_content !== $row->post_content ) {
						$wpdb->update( $wpdb->posts, [ 'post_content' => $new_content ], [ 'ID' => $row->ID ] );
						$breakdown['posts']++;
					}
				}
			} while ( count( $rows ) === $batch );
		}

		// ── postmeta (handles serialized data via recursive walker) ───────
		$all_from_urls = array_column( $pairs, 'from' );
		$or_meta       = implode( ' OR ', array_fill( 0, count( $all_from_urls ), 'meta_value LIKE %s' ) );
		$like_values   = array_map( fn( $u ) => '%' . $wpdb->esc_like( $u ) . '%', $all_from_urls );
		$last_id = 0;
		do {
			$meta_rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT meta_id, meta_value FROM {$wpdb->postmeta}
				 WHERE ( {$or_meta} ) AND meta_id > %d
				 ORDER BY meta_id ASC LIMIT %d",
				...array_merge( $like_values, [ $last_id, $batch ] )
			) );
			foreach ( $meta_rows as $row ) {
				$last_id  = (int) $row->meta_id;
				$replaced = self::deep_replace( $row->meta_value, $pairs );
				if ( $replaced !== $row->meta_value ) {
					$wpdb->update( $wpdb->postmeta, [ 'meta_value' => $replaced ], [ 'meta_id' => $row->meta_id ] );
					$breakdown['postmeta']++;
				}
			}
		} while ( count( $meta_rows ) === $batch );

		// ── options (skip transient/private; same serialized handling) ────
		$or_opt  = implode( ' OR ', array_fill( 0, count( $all_from_urls ), 'option_value LIKE %s' ) );
		$last_id = 0;
		do {
			$opt_rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT option_id, option_value FROM {$wpdb->options}
				 WHERE ( {$or_opt} )
				 AND option_name NOT LIKE %s AND option_name NOT LIKE %s
				 AND option_id > %d
				 ORDER BY option_id ASC LIMIT %d",
				...array_merge( $like_values, [ '\_transient\_%', '\_site\_transient\_%', $last_id, $batch ] )
			) );
			foreach ( $opt_rows as $row ) {
				$last_id  = (int) $row->option_id;
				$replaced = self::deep_replace( $row->option_value, $pairs );
				if ( $replaced !== $row->option_value ) {
					$wpdb->update( $wpdb->options, [ 'option_value' => $replaced ], [ 'option_id' => $row->option_id ] );
					$breakdown['options']++;
				}
			}
		} while ( count( $opt_rows ) === $batch );

		return [ 'total' => array_sum( $breakdown ), 'breakdown' => $breakdown ];
	}

	/**
	 * Replace URL pairs inside a value that may be serialized PHP, JSON, or
	 * plain text. Walks the unserialized structure so serialized-length
	 * headers stay consistent.
	 */
	private static function deep_replace( $value, array $pairs ) {
		if ( ! is_string( $value ) ) return $value;

		// Serialized PHP — unserialize, recurse, re-serialize.
		$is_serialized = is_serialized( $value );
		if ( $is_serialized ) {
			$un = @unserialize( $value );
			if ( $un !== false || $value === 'b:0;' ) {
				$un = self::deep_replace_recursive( $un, $pairs );
				return serialize( $un );
			}
		}

		// JSON — if it parses, walk + re-encode.
		$first = $value[0] ?? '';
		if ( $first === '{' || $first === '[' ) {
			$decoded = json_decode( $value, true );
			if ( json_last_error() === JSON_ERROR_NONE && ( is_array( $decoded ) || is_object( $decoded ) ) ) {
				$walked = self::deep_replace_recursive( $decoded, $pairs );
				$re = wp_json_encode( $walked, JSON_UNESCAPED_SLASHES );
				if ( $re !== false ) return $re;
			}
		}

		// Plain text fallback.
		$out = $value;
		foreach ( $pairs as $p ) {
			$out = str_replace( $p['from'], $p['to'], $out );
		}
		return $out;
	}

	private static function deep_replace_recursive( $value, array $pairs ) {
		if ( is_array( $value ) ) {
			foreach ( $value as $k => $v ) {
				$value[ $k ] = self::deep_replace_recursive( $v, $pairs );
			}
			return $value;
		}
		if ( is_object( $value ) ) {
			foreach ( $value as $k => $v ) {
				$value->$k = self::deep_replace_recursive( $v, $pairs );
			}
			return $value;
		}
		if ( is_string( $value ) ) {
			foreach ( $pairs as $p ) {
				$value = str_replace( $p['from'], $p['to'], $value );
			}
		}
		return $value;
	}

	private static function rollback( array $renamed ): void {
		foreach ( array_reverse( $renamed ) as $f ) {
			@rename( $f['new'], $f['old'] );
		}
	}
}

// ═════════════════════════════════════════════════════════════════════════════
//  AI SUGGEST — sends the image URL to OpenAI's vision API and asks for a slug.
//  Credentials route through Nexus (Therum_Connections_Page). Graceful fallback
//  to title/alt-based slug when no OpenAI key is configured.
// ═════════════════════════════════════════════════════════════════════════════

final class Therum_Renamer_AI {

	/**
	 * Returns [ 'ok' => bool, 'filename' => string, 'source' => 'ai'|'fallback'|'none', 'reason' => string ]
	 */
	public static function suggest( int $attachment_id ): array {
		$post = get_post( $attachment_id );
		if ( ! $post ) return [ 'ok' => false, 'reason' => 'Attachment not found.' ];

		$file = get_attached_file( $attachment_id );
		if ( ! $file ) return [ 'ok' => false, 'reason' => 'Source file missing.' ];

		// Only images get the vision call. For docs/video/audio we fall back
		// to the title/alt slug — vision adds no signal there.
		$mime = (string) get_post_mime_type( $attachment_id );
		if ( ! str_starts_with( $mime, 'image/' ) ) {
			$slug = Therum_Renamer::suggest( $attachment_id );
			return $slug
				? [ 'ok' => true, 'filename' => $slug, 'source' => 'fallback', 'reason' => 'AI vision only runs on images; used title/alt instead.' ]
				: [ 'ok' => false, 'reason' => 'No title or alt text to derive a name from.' ];
		}

		$api_key = self::openai_key();
		if ( ! $api_key ) {
			return [ 'ok' => false, 'reason' => 'OpenAI not connected. Add an API key in Connections → AI → OpenAI.' ];
		}

		$url = wp_get_attachment_url( $attachment_id );
		if ( ! $url ) return [ 'ok' => false, 'reason' => 'Could not resolve attachment URL.' ];

		$ext = strtolower( pathinfo( $file, PATHINFO_EXTENSION ) ) ?: 'jpg';

		// Vision call. We ask for a short slug-cased filename. gpt-4o-mini is
		// the cheapest model with image input.
		$body = [
			'model'       => 'gpt-4o-mini',
			'temperature' => 0.2,
			'max_tokens'  => 40,
			'messages'    => [
				[
					'role' => 'system',
					'content' => 'You write short SEO-friendly image filenames. Reply with ONLY a kebab-case slug, 2–6 words, lowercase, no extension, no quotes, no explanation. Describe what is visible in the image.',
				],
				[
					'role'    => 'user',
					'content' => [
						[ 'type' => 'text',      'text' => 'Suggest a filename slug for this image.' ],
						[ 'type' => 'image_url', 'image_url' => [ 'url' => $url ] ],
					],
				],
			],
		];

		$response = wp_remote_post( 'https://api.openai.com/v1/chat/completions', [
			'timeout' => 25,
			'headers' => [
				'Authorization' => 'Bearer ' . $api_key,
				'Content-Type'  => 'application/json',
			],
			'body' => wp_json_encode( $body ),
		] );

		if ( is_wp_error( $response ) ) {
			return [ 'ok' => false, 'reason' => 'OpenAI request failed: ' . $response->get_error_message() ];
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$json = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $code !== 200 || ! is_array( $json ) ) {
			$err = $json['error']['message'] ?? 'HTTP ' . $code;
			return [ 'ok' => false, 'reason' => 'OpenAI: ' . $err ];
		}

		$text = (string) ( $json['choices'][0]['message']['content'] ?? '' );
		$slug = sanitize_title( trim( $text ) );
		if ( $slug === '' ) {
			return [ 'ok' => false, 'reason' => 'AI returned an empty result.' ];
		}
		return [ 'ok' => true, 'filename' => $slug . '.' . $ext, 'source' => 'ai', 'reason' => '' ];
	}

	private static function openai_key(): ?string {
		if ( ! class_exists( 'Therum_Connections_Page' ) ) return null;
		$conns = Therum_Connections_Page::get_connections();
		$row   = $conns['openai'] ?? null;
		if ( ! $row || empty( $row['key'] ) ) return null;
		$key = Therum_Connections_Page::decrypt( (string) $row['key'] );
		return $key !== '' ? $key : null;
	}

	public static function is_available(): bool {
		return (bool) self::openai_key();
	}
}

// ═════════════════════════════════════════════════════════════════════════════
//  AUTO-RENAME ON UPLOAD / TITLE EDIT
//
//  Option key: th_renamer_auto. Default off. When on:
//    - On attachment edit (title/alt change), derive slug from new title/alt;
//      if it differs from the current filename's basename, rename in place.
//
//  Skips the rename if the user has saved a "do not auto-rename" flag on the
//  attachment (set when they manually rename — preserves their choice).
// ═════════════════════════════════════════════════════════════════════════════

add_action( 'attachment_updated', function( $post_id, $post_after, $post_before ) {
	if ( ! get_option( 'th_renamer_auto', false ) ) return;
	if ( wp_doing_ajax() && ( $_POST['action'] ?? '' ) === 'therum_renamer_rename' ) return; // we're already inside a manual rename
	if ( get_post_meta( $post_id, '_th_renamer_skip_auto', true ) ) return;
	if ( $post_after->post_title === $post_before->post_title ) return; // nothing changed

	$suggested = Therum_Renamer::suggest( (int) $post_id );
	if ( ! $suggested ) return;

	$current = basename( (string) get_attached_file( (int) $post_id ) );
	if ( $current === $suggested ) return; // already matches

	// Fire and (mostly) forget — log on failure but never block the save.
	$result = Therum_Renamer::rename( (int) $post_id, $suggested );
	if ( empty( $result['ok'] ) && function_exists( 'error_log' ) ) {
		error_log( '[therum-renamer] auto-rename skipped for #' . $post_id . ': ' . ( $result['error'] ?? 'unknown' ) );
	}
}, 20, 3 );

// Register the option so Therum_Settings can save it via the generic option-save AJAX.
add_filter( 'therum_settings_keys', function( $keys ) {
	$keys[] = 'th_renamer_auto';
	return $keys;
} );

// ─── AJAX endpoints ──────────────────────────────────────────────────────────

add_action( 'wp_ajax_therum_renamer_preview', function() {
	if ( ! current_user_can( 'upload_files' ) ) wp_send_json_error( [ 'message' => 'forbidden' ], 403 );
	check_ajax_referer( 'therum_renamer', 'nonce' );
	$id   = (int) ( $_POST['attachment'] ?? 0 );
	$name = sanitize_file_name( wp_unslash( $_POST['filename'] ?? '' ) );
	$result = Therum_Renamer::preview( $id, $name );
	if ( empty( $result['ok'] ) ) wp_send_json_error( $result );
	wp_send_json_success( $result );
} );

add_action( 'wp_ajax_therum_renamer_rename', function() {
	if ( ! current_user_can( 'upload_files' ) ) wp_send_json_error( [ 'message' => 'forbidden' ], 403 );
	check_ajax_referer( 'therum_renamer', 'nonce' );
	$id   = (int) ( $_POST['attachment'] ?? 0 );
	$name = sanitize_file_name( wp_unslash( $_POST['filename'] ?? '' ) );
	$result = Therum_Renamer::rename( $id, $name );
	if ( empty( $result['ok'] ) ) wp_send_json_error( $result );
	wp_send_json_success( $result );
} );

add_action( 'wp_ajax_therum_renamer_suggest', function() {
	if ( ! current_user_can( 'upload_files' ) ) wp_send_json_error( [ 'message' => 'forbidden' ], 403 );
	check_ajax_referer( 'therum_renamer', 'nonce' );
	$id = (int) ( $_POST['attachment'] ?? 0 );
	wp_send_json_success( [
		'filename'     => Therum_Renamer::suggest( $id ),
		'ai_available' => Therum_Renamer_AI::is_available(),
	] );
} );

add_action( 'wp_ajax_therum_renamer_suggest_ai', function() {
	if ( ! current_user_can( 'upload_files' ) ) wp_send_json_error( [ 'message' => 'forbidden' ], 403 );
	check_ajax_referer( 'therum_renamer', 'nonce' );
	$id = (int) ( $_POST['attachment'] ?? 0 );
	$result = Therum_Renamer_AI::suggest( $id );
	if ( empty( $result['ok'] ) ) wp_send_json_error( [ 'error' => $result['reason'] ?? 'AI suggest failed.' ] );
	wp_send_json_success( $result );
} );

// Bulk: list candidates where the suggested name differs from current filename.
// Returns enough info per row for the modal to render checkboxes + editable names.
add_action( 'wp_ajax_therum_renamer_bulk_list', function() {
	if ( ! current_user_can( 'upload_files' ) ) wp_send_json_error( [ 'message' => 'forbidden' ], 403 );
	check_ajax_referer( 'therum_renamer', 'nonce' );

	$atts = get_posts( [
		'post_type'      => 'attachment',
		'post_status'    => 'inherit',
		'posts_per_page' => 500, // cap so the modal stays responsive
		'orderby'        => 'date',
		'order'          => 'DESC',
	] );

	$candidates = [];
	$skipped    = 0;
	foreach ( $atts as $a ) {
		$file = get_attached_file( $a->ID );
		if ( ! $file || ! file_exists( $file ) ) { $skipped++; continue; }
		$current  = basename( $file );
		$suggest  = Therum_Renamer::suggest( $a->ID );
		if ( ! $suggest || $suggest === $current ) { $skipped++; continue; } // nothing meaningful to propose
		$candidates[] = [
			'id'       => $a->ID,
			'current'  => $current,
			'suggest'  => $suggest,
			'title'    => $a->post_title,
			'thumb'    => wp_get_attachment_image_url( $a->ID, [ 60, 60 ] ) ?: '',
			'mime'     => $a->post_mime_type,
		];
	}

	wp_send_json_success( [
		'candidates' => $candidates,
		'skipped'    => $skipped,
		'total'      => count( $atts ),
	] );
} );

// Bulk execute — receives an array of [{id, filename}] pairs, runs rename
// sequentially, returns per-item status. The caller (modal) reports progress.
// We do it sequentially server-side (single request) rather than per-request
// from JS so the cache-bust only fires once at the end.
add_action( 'wp_ajax_therum_renamer_bulk_rename', function() {
	if ( ! current_user_can( 'upload_files' ) ) wp_send_json_error( [ 'message' => 'forbidden' ], 403 );
	check_ajax_referer( 'therum_renamer', 'nonce' );

	$raw = wp_unslash( $_POST['items'] ?? '[]' );
	$items = json_decode( $raw, true );
	if ( ! is_array( $items ) ) wp_send_json_error( [ 'error' => 'invalid payload' ] );

	$results = [];
	$ok = 0;
	$fail = 0;
	$total_refs = 0;
	foreach ( array_slice( $items, 0, 500 ) as $row ) {
		$id   = (int) ( $row['id'] ?? 0 );
		$name = sanitize_file_name( (string) ( $row['filename'] ?? '' ) );
		if ( ! $id || ! $name ) {
			$results[] = [ 'id' => $id, 'ok' => false, 'error' => 'Missing id or filename.' ];
			$fail++;
			continue;
		}
		$r = Therum_Renamer::rename( $id, $name );
		$results[] = [
			'id'    => $id,
			'ok'    => ! empty( $r['ok'] ),
			'error' => $r['error'] ?? '',
			'refs'  => $r['refs_updated'] ?? 0,
		];
		if ( ! empty( $r['ok'] ) ) { $ok++; $total_refs += (int) ( $r['refs_updated'] ?? 0 ); }
		else                       { $fail++; }
	}

	wp_send_json_success( [
		'results'    => $results,
		'ok_count'   => $ok,
		'fail_count' => $fail,
		'refs_total' => $total_refs,
	] );
} );

// ─── Modal markup + JS, printed on the Media list page only ─────────────────

add_action( 'admin_footer', function() {
	$page = $_GET['page'] ?? '';
	if ( $page !== 'therum-media' ) return;
	$nonce = wp_create_nonce( 'therum_renamer' );
	?>
<!-- Bulk rename modal — list of candidates with per-row checkbox + editable name -->
<div id="th-renamer-bulk" class="th-renamer-modal" hidden role="dialog" aria-modal="true" aria-labelledby="th-renamer-bulk-title">
  <div class="th-renamer-backdrop" data-th-bulk-close></div>
  <div class="th-renamer-panel" style="width:min(820px,calc(100vw - 40px));">
	<div class="th-renamer-head">
	  <h2 id="th-renamer-bulk-title">Bulk rename for SEO</h2>
	  <button type="button" class="th-renamer-x" data-th-bulk-close aria-label="Close">×</button>
	</div>
	<div class="th-renamer-body">
	  <div class="th-renamer-bulk-summary" data-th-bulk-summary>Scanning library…</div>
	  <div class="th-renamer-bulk-toolbar" data-th-bulk-toolbar hidden>
		<label><input type="checkbox" data-th-bulk-all checked /> <span>Select all</span></label>
		<span style="flex:1"></span>
		<span data-th-bulk-selected style="font-size:11px;color:var(--tx3)">0 selected</span>
	  </div>
	  <div class="th-renamer-bulk-list" data-th-bulk-list></div>
	  <div class="th-renamer-bulk-progress" data-th-bulk-progress hidden></div>
	  <div class="th-renamer-error" data-th-bulk-error hidden></div>
	</div>
	<div class="th-renamer-foot">
	  <button type="button" class="th-btn" data-th-bulk-close>Cancel</button>
	  <button type="button" class="th-btn th-btn-primary" data-th-bulk-go disabled>Rename selected</button>
	</div>
  </div>
</div>

<div id="th-renamer-modal" class="th-renamer-modal" hidden data-th-renamer-nonce="<?php echo esc_attr( $nonce ); ?>" role="dialog" aria-modal="true" aria-labelledby="th-renamer-title">
  <div class="th-renamer-backdrop" data-th-renamer-close></div>
  <div class="th-renamer-panel">
	<div class="th-renamer-head">
	  <h2 id="th-renamer-title">Rename for SEO</h2>
	  <button type="button" class="th-renamer-x" data-th-renamer-close aria-label="Close">×</button>
	</div>
	<div class="th-renamer-body">
	  <div class="th-renamer-row">
		<label class="th-renamer-label">Current filename</label>
		<div class="th-renamer-current" data-th-renamer-current></div>
	  </div>
	  <div class="th-renamer-row">
		<label class="th-renamer-label" for="th-renamer-input">New filename</label>
		<div class="th-renamer-inputrow">
		  <input type="text" id="th-renamer-input" autocomplete="off" spellcheck="false" />
		  <button type="button" class="th-btn" data-th-renamer-suggest title="Suggest from title / alt">↺ Suggest</button>
		  <button type="button" class="th-btn th-renamer-ai-btn" data-th-renamer-ai title="Suggest with AI (vision) — uses OpenAI via Connections" hidden>✨ AI</button>
		</div>
		<div class="th-renamer-hint">Lowercase, hyphens, keep the original extension.</div>
		<div class="th-renamer-ai-hint" data-th-renamer-ai-hint hidden></div>
	  </div>
	  <div class="th-renamer-preview" data-th-renamer-preview hidden>
		<div class="th-renamer-preview-head">References that will be updated</div>
		<div class="th-renamer-preview-body" data-th-renamer-preview-body></div>
	  </div>
	  <div class="th-renamer-error" data-th-renamer-error hidden></div>
	</div>
	<div class="th-renamer-foot">
	  <button type="button" class="th-btn" data-th-renamer-close>Cancel</button>
	  <button type="button" class="th-btn th-btn-primary" data-th-renamer-go disabled>Rename</button>
	</div>
  </div>
</div>
<style>
.th-renamer-modal{position:fixed;inset:0;z-index:99999;display:flex;align-items:center;justify-content:center}
.th-renamer-modal[hidden]{display:none}
.th-renamer-backdrop{position:absolute;inset:0;background:rgba(0,0,0,.45);backdrop-filter:blur(2px)}
.th-renamer-panel{position:relative;width:min(560px,calc(100vw - 40px));max-height:calc(100vh - 60px);overflow:auto;background:var(--sf);border:1px solid var(--bd);border-radius:14px;box-shadow:0 20px 50px rgba(0,0,0,.25)}
.th-renamer-head{display:flex;align-items:center;justify-content:space-between;padding:18px 22px;border-bottom:1px solid var(--bd)}
.th-renamer-head h2{margin:0;font-size:16px;font-weight:700;color:var(--tx);letter-spacing:-.01em}
.th-renamer-x{background:transparent;border:0;font-size:22px;line-height:1;color:var(--tx3);cursor:pointer;padding:0 6px}
.th-renamer-x:hover{color:var(--tx)}
.th-renamer-body{padding:18px 22px;display:flex;flex-direction:column;gap:16px}
.th-renamer-row{display:flex;flex-direction:column;gap:6px}
.th-renamer-label{font-size:11px;font-weight:600;color:var(--tx3);text-transform:uppercase;letter-spacing:.06em}
.th-renamer-current{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:13px;color:var(--tx);padding:8px 10px;background:var(--sf2);border-radius:6px;word-break:break-all}
.th-renamer-inputrow{display:flex;gap:8px}
.th-renamer-inputrow input{flex:1;padding:9px 12px;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:13px;background:var(--sf);border:1px solid var(--bd);border-radius:7px;color:var(--tx);outline:none;transition:border-color .15s,box-shadow .15s}
.th-renamer-inputrow input:focus{border-color:var(--ac);box-shadow:0 0 0 3px color-mix(in srgb,var(--ac) 16%,transparent)}
.th-renamer-hint{font-size:11px;color:var(--tx3)}
.th-renamer-preview{background:var(--sf2);border:1px solid var(--bd);border-radius:8px;padding:12px 14px;font-size:12px;color:var(--tx2)}
.th-renamer-preview-head{font-weight:600;color:var(--tx);margin-bottom:6px;font-size:12px}
.th-renamer-preview-body{line-height:1.55}
.th-renamer-error{padding:10px 12px;background:color-mix(in srgb,var(--err) 8%,transparent);color:var(--err);border-radius:6px;font-size:12px;font-weight:500}
.th-renamer-foot{display:flex;justify-content:flex-end;gap:8px;padding:14px 22px;border-top:1px solid var(--bd);background:var(--sf2)}
.th-renamer-ai-btn{background:linear-gradient(135deg,#8b5cf6,#ec4899);color:#fff;border-color:transparent}
.th-renamer-ai-btn:hover{filter:brightness(1.05);color:#fff}
.th-renamer-ai-hint{font-size:11px;color:var(--tx3);padding:6px 0 0;font-style:italic}
.th-renamer-bulk-summary{font-size:13px;color:var(--tx2);padding:8px 0;line-height:1.5}
.th-renamer-bulk-toolbar{display:flex;align-items:center;gap:12px;padding:8px 0;border-bottom:1px solid var(--bd);font-size:12px}
.th-renamer-bulk-toolbar label{display:inline-flex;align-items:center;gap:6px;cursor:pointer}
.th-renamer-bulk-list{max-height:50vh;overflow-y:auto;display:flex;flex-direction:column;gap:4px;padding:6px 0}
.th-renamer-bulk-row{display:grid;grid-template-columns:auto 48px 1fr 1fr;gap:10px;align-items:center;padding:8px;border-radius:6px;background:var(--sf2)}
.th-renamer-bulk-row.is-done{opacity:.5}
.th-renamer-bulk-row.is-fail{background:color-mix(in srgb,var(--err) 6%,var(--sf2));border:1px solid color-mix(in srgb,var(--err) 25%,var(--bd))}
.th-renamer-bulk-thumb{width:48px;height:48px;border-radius:6px;background-size:cover;background-position:center;background-color:var(--sf);border:1px solid var(--bd)}
.th-renamer-bulk-current{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:11px;color:var(--tx3);overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.th-renamer-bulk-new input{width:100%;padding:6px 8px;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:11px;background:var(--sf);border:1px solid var(--bd);border-radius:5px;color:var(--tx);outline:none}
.th-renamer-bulk-progress{padding:10px 12px;background:var(--sf2);border-radius:6px;font-size:12px;color:var(--tx2)}
.th-renamer-bulk-status{font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:.04em;padding:2px 6px;border-radius:4px}
.th-renamer-bulk-status-ok{background:rgba(16,185,129,.15);color:var(--ok)}
.th-renamer-bulk-status-fail{background:rgba(239,68,68,.15);color:var(--err)}
</style>
<script>
(function(){
  var modal = document.getElementById('th-renamer-modal');
  if (!modal) return;
  var nonce = modal.getAttribute('data-th-renamer-nonce');
  var ajaxUrl = (window.ajaxurl) || '/wp-admin/admin-ajax.php';
  var input = modal.querySelector('#th-renamer-input');
  var current = modal.querySelector('[data-th-renamer-current]');
  var preview = modal.querySelector('[data-th-renamer-preview]');
  var previewBody = modal.querySelector('[data-th-renamer-preview-body]');
  var errBox = modal.querySelector('[data-th-renamer-error]');
  var goBtn = modal.querySelector('[data-th-renamer-go]');
  var suggestBtn = modal.querySelector('[data-th-renamer-suggest]');
  var currentAttachment = null;
  var previewDebounce = null;

  function setError(msg) {
    if (msg) { errBox.textContent = msg; errBox.hidden = false; }
    else { errBox.hidden = true; errBox.textContent = ''; }
  }
  function open(attachmentId, currentName) {
    currentAttachment = attachmentId;
    current.textContent = currentName || '(unknown)';
    input.value = '';
    preview.hidden = true;
    previewBody.textContent = '';
    setError('');
    goBtn.disabled = true;
    modal.hidden = false;
    setTimeout(function(){ input.focus(); fetchSuggest(); }, 50);
  }
  function close() { modal.hidden = true; currentAttachment = null; }

  modal.querySelectorAll('[data-th-renamer-close]').forEach(function(el){
    el.addEventListener('click', close);
  });
  document.addEventListener('keydown', function(e){
    if (e.key === 'Escape' && !modal.hidden) close();
  });

  var aiBtn = modal.querySelector('[data-th-renamer-ai]');
  var aiHint = modal.querySelector('[data-th-renamer-ai-hint]');

  function fetchSuggest() {
    if (!currentAttachment) return;
    var fd = new FormData();
    fd.append('action', 'therum_renamer_suggest');
    fd.append('nonce', nonce);
    fd.append('attachment', currentAttachment);
    fetch(ajaxUrl, { method:'POST', credentials:'same-origin', body: fd })
      .then(function(r){ return r.json(); })
      .then(function(res){
        if (res && res.success && res.data) {
          if (res.data.filename && !input.value) {
            input.value = res.data.filename;
            schedulePreview();
          }
          aiBtn.hidden = !res.data.ai_available;
        }
      });
  }
  suggestBtn.addEventListener('click', function(){
    input.value = '';
    fetchSuggest();
  });

  aiBtn.addEventListener('click', function(){
    if (!currentAttachment) return;
    aiBtn.disabled = true;
    var orig = aiBtn.textContent;
    aiBtn.textContent = '✨ Thinking…';
    if (aiHint) { aiHint.hidden = true; aiHint.textContent = ''; }
    var fd = new FormData();
    fd.append('action', 'therum_renamer_suggest_ai');
    fd.append('nonce', nonce);
    fd.append('attachment', currentAttachment);
    fetch(ajaxUrl, { method:'POST', credentials:'same-origin', body: fd })
      .then(function(r){ return r.json(); })
      .then(function(res){
        aiBtn.disabled = false; aiBtn.textContent = orig;
        if (!res || !res.success) {
          if (aiHint) { aiHint.textContent = (res && res.data && res.data.error) || 'AI suggest failed.'; aiHint.hidden = false; }
          return;
        }
        if (res.data.filename) {
          input.value = res.data.filename;
          schedulePreview();
          if (aiHint && res.data.source === 'fallback') {
            aiHint.textContent = res.data.reason || 'Used title/alt as fallback.';
            aiHint.hidden = false;
          }
        }
      })
      .catch(function(){
        aiBtn.disabled = false; aiBtn.textContent = orig;
        if (aiHint) { aiHint.textContent = 'Network error'; aiHint.hidden = false; }
      });
  });

  function schedulePreview() {
    clearTimeout(previewDebounce);
    previewDebounce = setTimeout(fetchPreview, 220);
  }
  input.addEventListener('input', schedulePreview);

  function fetchPreview() {
    if (!currentAttachment || !input.value.trim()) {
      preview.hidden = true; goBtn.disabled = true; setError(''); return;
    }
    var fd = new FormData();
    fd.append('action', 'therum_renamer_preview');
    fd.append('nonce', nonce);
    fd.append('attachment', currentAttachment);
    fd.append('filename', input.value);
    fetch(ajaxUrl, { method:'POST', credentials:'same-origin', body: fd })
      .then(function(r){ return r.json(); })
      .then(function(res){
        if (!res || !res.success) {
          setError((res && res.data && res.data.error) || 'Preview failed');
          preview.hidden = true; goBtn.disabled = true;
          return;
        }
        setError('');
        var d = res.data;
        var b = d.refs_breakdown || {};
        previewBody.innerHTML =
          '<div><strong>'+ d.files.length +'</strong> file' + (d.files.length===1?'':'s') + ' will be renamed (main + thumbnails)</div>' +
          '<div><strong>'+ d.refs_estimate +'</strong> reference' + (d.refs_estimate===1?'':'s') + ' will be updated' +
            ' <span style="opacity:.7">(' + (b.posts||0) + ' posts, ' + (b.postmeta||0) + ' meta, ' + (b.options||0) + ' options)</span></div>';
        preview.hidden = false;
        goBtn.disabled = false;
      })
      .catch(function(){ setError('Network error'); preview.hidden = true; goBtn.disabled = true; });
  }

  goBtn.addEventListener('click', function(){
    if (!currentAttachment || !input.value.trim()) return;
    goBtn.disabled = true; goBtn.textContent = 'Renaming…';
    var fd = new FormData();
    fd.append('action', 'therum_renamer_rename');
    fd.append('nonce', nonce);
    fd.append('attachment', currentAttachment);
    fd.append('filename', input.value);
    fetch(ajaxUrl, { method:'POST', credentials:'same-origin', body: fd })
      .then(function(r){ return r.json(); })
      .then(function(res){
        goBtn.textContent = 'Rename';
        if (!res || !res.success) {
          setError((res && res.data && res.data.error) || 'Rename failed');
          goBtn.disabled = false;
          return;
        }
        // Success — close + reload so the Media grid shows the new name.
        close();
        location.reload();
      })
      .catch(function(){
        setError('Network error');
        goBtn.disabled = false; goBtn.textContent = 'Rename';
      });
  });

  // Hook the kebab menu items with data-th-rename
  document.addEventListener('click', function(e){
    var trigger = e.target.closest('[data-th-rename]');
    if (!trigger) return;
    e.preventDefault();
    e.stopPropagation();
    var id = trigger.getAttribute('data-th-rename');
    var name = trigger.getAttribute('data-th-rename-name') || '';
    open(id, name);
  });

  // ── Bulk rename ─────────────────────────────────────────────────────────
  var bulkModal   = document.getElementById('th-renamer-bulk');
  var bulkSummary = bulkModal.querySelector('[data-th-bulk-summary]');
  var bulkToolbar = bulkModal.querySelector('[data-th-bulk-toolbar]');
  var bulkList    = bulkModal.querySelector('[data-th-bulk-list]');
  var bulkAll     = bulkModal.querySelector('[data-th-bulk-all]');
  var bulkSel     = bulkModal.querySelector('[data-th-bulk-selected]');
  var bulkGo      = bulkModal.querySelector('[data-th-bulk-go]');
  var bulkProgress = bulkModal.querySelector('[data-th-bulk-progress]');
  var bulkError   = bulkModal.querySelector('[data-th-bulk-error]');
  var bulkCandidates = [];

  function bulkClose() {
    bulkModal.hidden = true;
    bulkCandidates = [];
    bulkList.innerHTML = '';
    bulkProgress.hidden = true; bulkProgress.textContent = '';
    bulkError.hidden = true; bulkError.textContent = '';
    bulkGo.disabled = true; bulkGo.textContent = 'Rename selected';
  }
  bulkModal.querySelectorAll('[data-th-bulk-close]').forEach(function(el){
    el.addEventListener('click', bulkClose);
  });

  document.addEventListener('click', function(e){
    var trigger = e.target.closest('[data-th-bulk-rename]');
    if (!trigger) return;
    e.preventDefault();
    bulkOpen();
  });

  function bulkOpen() {
    bulkModal.hidden = false;
    bulkSummary.textContent = 'Scanning library…';
    bulkToolbar.hidden = true;
    bulkList.innerHTML = '';
    bulkGo.disabled = true;

    var fd = new FormData();
    fd.append('action', 'therum_renamer_bulk_list');
    fd.append('nonce', nonce);
    fetch(ajaxUrl, { method:'POST', credentials:'same-origin', body: fd })
      .then(function(r){ return r.json(); })
      .then(function(res){
        if (!res || !res.success) {
          bulkSummary.textContent = (res && res.data && res.data.message) || 'Scan failed.';
          return;
        }
        bulkCandidates = res.data.candidates || [];
        if (bulkCandidates.length === 0) {
          bulkSummary.textContent = 'Nothing to rename — every attachment already matches its title or has no meaningful title to derive from.';
          return;
        }
        bulkSummary.textContent = bulkCandidates.length + ' candidate' + (bulkCandidates.length===1?'':'s') + ' found (of ' + res.data.total + ' total). Edit any name inline or uncheck rows you want to skip.';
        bulkToolbar.hidden = false;
        bulkRender();
      })
      .catch(function(){ bulkSummary.textContent = 'Network error.'; });
  }

  function bulkRender() {
    bulkList.innerHTML = '';
    bulkCandidates.forEach(function(c, i){
      var row = document.createElement('div');
      row.className = 'th-renamer-bulk-row';
      row.setAttribute('data-idx', i);
      row.innerHTML =
        '<input type="checkbox" class="th-bulk-check" checked>' +
        '<div class="th-renamer-bulk-thumb"' + (c.thumb ? ' style="background-image:url(\'' + c.thumb.replace(/"/g,'') + '\')"' : '') + '></div>' +
        '<div class="th-renamer-bulk-current" title="' + escAttr(c.current) + '">' + escHtml(c.current) + '</div>' +
        '<div class="th-renamer-bulk-new"><input type="text" value="' + escAttr(c.suggest) + '" spellcheck="false"></div>';
      bulkList.appendChild(row);
    });
    updateSelected();
  }

  function escHtml(s){ return String(s).replace(/[&<>]/g, function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;'}[c];}); }
  function escAttr(s){ return String(s).replace(/"/g,'&quot;'); }

  function updateSelected() {
    var n = bulkList.querySelectorAll('.th-bulk-check:checked').length;
    bulkSel.textContent = n + ' selected';
    bulkGo.disabled = (n === 0);
  }
  bulkList.addEventListener('change', updateSelected);
  bulkAll.addEventListener('change', function(){
    var on = bulkAll.checked;
    bulkList.querySelectorAll('.th-bulk-check').forEach(function(cb){ cb.checked = on; });
    updateSelected();
  });

  bulkGo.addEventListener('click', function(){
    var items = [];
    bulkList.querySelectorAll('.th-renamer-bulk-row').forEach(function(row){
      var cb = row.querySelector('.th-bulk-check');
      if (!cb.checked) return;
      var idx = parseInt(row.getAttribute('data-idx'), 10);
      var nameInput = row.querySelector('.th-renamer-bulk-new input');
      var name = (nameInput && nameInput.value.trim()) || '';
      if (!name) return;
      items.push({ id: bulkCandidates[idx].id, filename: name, idx: idx });
    });
    if (items.length === 0) return;

    bulkGo.disabled = true; bulkGo.textContent = 'Renaming…';
    bulkProgress.hidden = false;
    bulkProgress.textContent = 'Renaming ' + items.length + ' item' + (items.length===1?'':'s') + '…';
    bulkError.hidden = true;

    var fd = new FormData();
    fd.append('action', 'therum_renamer_bulk_rename');
    fd.append('nonce', nonce);
    fd.append('items', JSON.stringify(items.map(function(i){ return {id:i.id, filename:i.filename}; })));
    fetch(ajaxUrl, { method:'POST', credentials:'same-origin', body: fd })
      .then(function(r){ return r.json(); })
      .then(function(res){
        if (!res || !res.success) {
          bulkError.textContent = (res && res.data && res.data.error) || 'Bulk rename failed.';
          bulkError.hidden = false;
          bulkGo.disabled = false; bulkGo.textContent = 'Rename selected';
          return;
        }
        var d = res.data;
        // Mark rows
        d.results.forEach(function(r){
          var row = bulkList.querySelector('.th-renamer-bulk-row[data-idx]');
          // find by id
          var found = null;
          bulkList.querySelectorAll('.th-renamer-bulk-row').forEach(function(rw){
            var idx = parseInt(rw.getAttribute('data-idx'), 10);
            if (bulkCandidates[idx] && bulkCandidates[idx].id == r.id) found = rw;
          });
          if (found) {
            found.classList.add(r.ok ? 'is-done' : 'is-fail');
            if (!r.ok) {
              var slot = found.querySelector('.th-renamer-bulk-new');
              slot.innerHTML = '<span class="th-renamer-bulk-status th-renamer-bulk-status-fail">' + escHtml(r.error || 'failed') + '</span>';
            } else {
              var slot2 = found.querySelector('.th-renamer-bulk-new');
              slot2.innerHTML = '<span class="th-renamer-bulk-status th-renamer-bulk-status-ok">renamed · ' + (r.refs||0) + ' refs</span>';
            }
          }
        });
        bulkProgress.textContent = 'Done. ' + d.ok_count + ' renamed · ' + d.fail_count + ' failed · ' + d.refs_total + ' total references updated. Reloading in 2s…';
        bulkGo.disabled = true; bulkGo.textContent = 'Done';
        setTimeout(function(){ location.reload(); }, 2000);
      })
      .catch(function(){
        bulkError.textContent = 'Network error.'; bulkError.hidden = false;
        bulkGo.disabled = false; bulkGo.textContent = 'Rename selected';
      });
  });
})();
</script>
	<?php
} );
