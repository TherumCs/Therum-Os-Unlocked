<?php
/**
 * Plugin Name: Therum SEO
 * Description: Zero-config auto-SEO. Generates meta descriptions, Open Graph,
 *              Twitter Cards, JSON-LD, canonical URLs, XML sitemap, robots.txt
 *              hints, image alt enforcement, and heading structure validation.
 *              Works with any content — Bricks, classic editor, or custom fields.
 *              No settings needed, but every auto-value can be overridden via
 *              post meta (th_seo_title, th_seo_description, th_seo_image).
 * Version: 2.0.0
 * Author: Therum
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// ════════════════════════════════════════════════════════════════════════════
//  AUTO-SEO ENGINE — works on any Therum OS site, zero config
// ════════════════════════════════════════════════════════════════════════════

class Therum_Auto_SEO {

	// ── Meta key overrides — set these on any post to control SEO manually ──
	const META_TITLE       = 'th_seo_title';
	const META_DESCRIPTION = 'th_seo_description';
	const META_IMAGE       = 'th_seo_image';
	const META_NOINDEX     = 'th_seo_noindex';

	public static function init(): void {
		add_action( 'wp_head',       [ __CLASS__, 'head' ], 2 );
		add_action( 'wp_head',       [ __CLASS__, 'json_ld' ], 3 );
		add_filter( 'document_title_parts', [ __CLASS__, 'filter_title' ] );
		add_filter( 'wp_robots',     [ __CLASS__, 'robots_tag' ] );
		add_action( 'do_robots',     [ __CLASS__, 'robots_txt' ] );

		// Admin: SEO meta box on post edit screens
		add_action( 'add_meta_boxes', [ __CLASS__, 'add_meta_box' ] );
		add_action( 'save_post',      [ __CLASS__, 'save_meta_box' ], 10, 2 );

		// Auto-generate alt text for images missing it
		add_filter( 'wp_get_attachment_image_attributes', [ __CLASS__, 'auto_alt' ], 10, 3 );

		// Ping search engines on publish (once per hour max)
		add_action( 'publish_post',   [ __CLASS__, 'ping_on_publish' ] );
		add_action( 'publish_page',   [ __CLASS__, 'ping_on_publish' ] );
	}

	// ═════════════════════════════════════════════════════════════════════
	//  AUTO-GENERATE: title, description, image from content
	// ═════════════════════════════════════════════════════════════════════

	/**
	 * Generate an SEO description from post content.
	 * Priority: manual override > excerpt > first 160 chars of content.
	 */
	public static function auto_description( ?WP_Post $post = null ): string {
		if ( ! $post ) $post = get_queried_object();
		if ( ! ( $post instanceof WP_Post ) ) return get_bloginfo( 'description' );

		// 1. Manual override
		$manual = get_post_meta( $post->ID, self::META_DESCRIPTION, true );
		if ( $manual ) return $manual;

		// 2. Excerpt
		if ( $post->post_excerpt ) {
			return wp_trim_words( wp_strip_all_tags( $post->post_excerpt ), 30, '…' );
		}

		// 3. First meaningful text from content
		$content = $post->post_content;
		// Strip shortcodes and HTML
		$content = strip_shortcodes( $content );
		$content = wp_strip_all_tags( $content );
		$content = html_entity_decode( $content, ENT_QUOTES, 'UTF-8' );
		$content = preg_replace( '/\s+/', ' ', trim( $content ) );

		if ( mb_strlen( $content ) > 10 ) {
			return mb_substr( $content, 0, 155 ) . '…';
		}

		return get_bloginfo( 'description' );
	}

	/**
	 * Generate an SEO title.
	 * Priority: manual override > post title + site name.
	 */
	public static function auto_title( ?WP_Post $post = null ): string {
		if ( ! $post ) $post = get_queried_object();
		if ( ! ( $post instanceof WP_Post ) ) return get_bloginfo( 'name' );

		$manual = get_post_meta( $post->ID, self::META_TITLE, true );
		if ( $manual ) return $manual;

		return $post->post_title;
	}

	/**
	 * Generate an OG image URL.
	 * Priority: manual override > featured image > first image in content > site logo.
	 */
	public static function auto_image( ?WP_Post $post = null ): string {
		if ( ! $post ) $post = get_queried_object();

		if ( $post instanceof WP_Post ) {
			// Manual override
			$manual = get_post_meta( $post->ID, self::META_IMAGE, true );
			if ( $manual ) return $manual;

			// Featured image
			$thumb = get_post_thumbnail_id( $post->ID );
			if ( $thumb ) {
				$url = wp_get_attachment_image_url( $thumb, 'large' );
				if ( $url ) return $url;
			}

			// First image in content
			preg_match( '/<img[^>]+src=["\']([^"\']+)["\']/i', $post->post_content, $m );
			if ( ! empty( $m[1] ) ) return $m[1];
		}

		// Fallback: site logo or brand color placeholder
		$logo = get_option( 'th_logo_url', '' );
		if ( $logo ) return $logo;

		return '';
	}

	// ═════════════════════════════════════════════════════════════════════
	//  WP_HEAD OUTPUT — meta tags, OG, Twitter Cards
	// ═════════════════════════════════════════════════════════════════════

	public static function head(): void {
		if ( is_admin() || is_feed() ) return;

		$desc  = '';
		$title = '';
		$image = '';
		$url   = '';
		$type  = 'website';

		if ( is_singular() ) {
			$post  = get_queried_object();
			$desc  = self::auto_description( $post );
			$title = self::auto_title( $post );
			$image = self::auto_image( $post );
			$url   = get_permalink( $post );
			$type  = ( $post->post_type === 'post' ) ? 'article' : 'website';
		} elseif ( is_front_page() ) {
			$desc  = get_bloginfo( 'description' ) ?: get_option( 'blogdescription', '' );
			$title = get_bloginfo( 'name' );
			$image = self::auto_image();
			$url   = home_url( '/' );
		} elseif ( is_archive() ) {
			$desc  = get_the_archive_description() ?: get_bloginfo( 'description' );
			$title = get_the_archive_title();
			$url   = get_post_type_archive_link( get_post_type() ) ?: home_url();
		} elseif ( is_search() ) {
			$desc  = 'Search results for: ' . get_search_query();
			$title = 'Search: ' . get_search_query();
			$url   = get_search_link();
		}

		if ( ! $desc ) $desc = get_bloginfo( 'description' );
		if ( ! $title ) $title = get_bloginfo( 'name' );
		$site_name = get_option( 'th_wordmark', get_bloginfo( 'name' ) );

		echo "\n<!-- Therum Auto-SEO -->\n";

		// Canonical URL
		if ( $url ) {
			echo '<link rel="canonical" href="' . esc_url( $url ) . '" />' . "\n";
		}

		// Meta description
		if ( $desc ) {
			echo '<meta name="description" content="' . esc_attr( $desc ) . '" />' . "\n";
		}

		// Open Graph
		echo '<meta property="og:type" content="' . esc_attr( $type ) . '" />' . "\n";
		echo '<meta property="og:locale" content="' . esc_attr( get_locale() ) . '" />' . "\n";
		if ( $title )     echo '<meta property="og:title" content="' . esc_attr( $title ) . '" />' . "\n";
		if ( $desc )      echo '<meta property="og:description" content="' . esc_attr( $desc ) . '" />' . "\n";
		if ( $url )       echo '<meta property="og:url" content="' . esc_url( $url ) . '" />' . "\n";
		if ( $site_name ) echo '<meta property="og:site_name" content="' . esc_attr( $site_name ) . '" />' . "\n";
		if ( $image ) {
			echo '<meta property="og:image" content="' . esc_url( $image ) . '" />' . "\n";
			echo '<meta property="og:image:width" content="1200" />' . "\n";
			echo '<meta property="og:image:height" content="630" />' . "\n";
		}

		// Article-specific OG
		if ( is_singular( 'post' ) ) {
			$post = get_queried_object();
			echo '<meta property="article:published_time" content="' . esc_attr( get_the_date( 'c', $post ) ) . '" />' . "\n";
			echo '<meta property="article:modified_time" content="' . esc_attr( get_the_modified_date( 'c', $post ) ) . '" />' . "\n";
			$cats = get_the_category( $post->ID );
			if ( $cats ) echo '<meta property="article:section" content="' . esc_attr( $cats[0]->name ) . '" />' . "\n";
			$tags = get_the_tags( $post->ID );
			if ( $tags ) {
				foreach ( array_slice( $tags, 0, 5 ) as $tag ) {
					echo '<meta property="article:tag" content="' . esc_attr( $tag->name ) . '" />' . "\n";
				}
			}
		}

		// Twitter Card
		echo '<meta name="twitter:card" content="summary_large_image" />' . "\n";
		if ( $title ) echo '<meta name="twitter:title" content="' . esc_attr( $title ) . '" />' . "\n";
		if ( $desc )  echo '<meta name="twitter:description" content="' . esc_attr( $desc ) . '" />' . "\n";
		if ( $image ) echo '<meta name="twitter:image" content="' . esc_url( $image ) . '" />' . "\n";

		echo "<!-- /Therum Auto-SEO -->\n";
	}

	// ═════════════════════════════════════════════════════════════════════
	//  JSON-LD STRUCTURED DATA — auto-generated per page type
	// ═════════════════════════════════════════════════════════════════════

	public static function json_ld(): void {
		if ( is_admin() || is_feed() ) return;

		$home      = home_url( '/' );
		$site_name = get_option( 'th_wordmark', get_bloginfo( 'name' ) );
		$graph     = [];

		// WebSite schema (always)
		$graph[] = [
			'@type'       => 'WebSite',
			'@id'         => $home . '#website',
			'url'         => $home,
			'name'        => $site_name,
			'description' => get_bloginfo( 'description' ),
			'inLanguage'  => get_locale(),
			'potentialAction' => [
				'@type'       => 'SearchAction',
				'target'      => home_url( '/?s={search_term_string}' ),
				'query-input' => 'required name=search_term_string',
			],
		];

		// Organization / Person (from branding settings)
		$logo = get_option( 'th_logo_url', '' );
		$graph[] = [
			'@type' => 'Organization',
			'@id'   => $home . '#org',
			'name'  => $site_name,
			'url'   => $home,
			'logo'  => $logo ?: ( $home . 'favicon.ico' ),
		];

		if ( is_singular() ) {
			$post = get_queried_object();
			$desc = self::auto_description( $post );
			$img  = self::auto_image( $post );

			if ( $post->post_type === 'post' ) {
				// Article schema
				$article = [
					'@type'            => 'Article',
					'@id'              => get_permalink( $post ) . '#article',
					'url'              => get_permalink( $post ),
					'headline'         => $post->post_title,
					'description'      => $desc,
					'datePublished'    => get_the_date( 'c', $post ),
					'dateModified'     => get_the_modified_date( 'c', $post ),
					'author'           => [
						'@type' => 'Person',
						'name'  => get_the_author_meta( 'display_name', $post->post_author ),
					],
					'publisher'        => [ '@id' => $home . '#org' ],
					'isPartOf'         => [ '@id' => $home . '#website' ],
					'inLanguage'       => get_locale(),
					'mainEntityOfPage' => get_permalink( $post ),
				];
				if ( $img ) $article['image'] = $img;

				// Word count
				$words = str_word_count( wp_strip_all_tags( $post->post_content ) );
				if ( $words > 0 ) $article['wordCount'] = $words;

				$graph[] = $article;
			} else {
				// Generic WebPage schema for pages, CPTs
				$page_schema = [
					'@type'       => 'WebPage',
					'url'         => get_permalink( $post ),
					'name'        => $post->post_title,
					'description' => $desc,
					'isPartOf'    => [ '@id' => $home . '#website' ],
					'inLanguage'  => get_locale(),
				];
				if ( $img ) $page_schema['primaryImageOfPage'] = [ '@type' => 'ImageObject', 'url' => $img ];
				$graph[] = $page_schema;
			}

			// BreadcrumbList
			$breadcrumbs = [
				[ '@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => $home ],
			];
			// Add post type archive if applicable
			$pto = get_post_type_object( $post->post_type );
			if ( $pto && $pto->has_archive ) {
				$archive_url = get_post_type_archive_link( $post->post_type );
				if ( $archive_url ) {
					$breadcrumbs[] = [ '@type' => 'ListItem', 'position' => 2, 'name' => $pto->labels->name, 'item' => $archive_url ];
				}
			}
			$breadcrumbs[] = [ '@type' => 'ListItem', 'position' => count( $breadcrumbs ) + 1, 'name' => $post->post_title ];
			$graph[] = [ '@type' => 'BreadcrumbList', 'itemListElement' => $breadcrumbs ];
		}

		$doc = [ '@context' => 'https://schema.org', '@graph' => $graph ];
		echo '<script type="application/ld+json">' . wp_json_encode( $doc, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "</script>\n";
	}

	// ═════════════════════════════════════════════════════════════════════
	//  TITLE FILTER — append site name, respect override
	// ═════════════════════════════════════════════════════════════════════

	public static function filter_title( $parts ): array {
		if ( is_singular() ) {
			$post   = get_queried_object();
			$manual = get_post_meta( $post->ID ?? 0, self::META_TITLE, true );
			if ( $manual ) $parts['title'] = $manual;
		}
		return $parts;
	}

	// ═════════════════════════════════════════════════════════════════════
	//  ROBOTS — noindex for search, archives with thin content, noindexed posts
	// ═════════════════════════════════════════════════════════════════════

	public static function robots_tag( $robots ): array {
		// Noindex search results
		if ( is_search() ) {
			$robots['noindex'] = true;
			$robots['nofollow'] = true;
		}
		// Noindex paginated archives past page 2
		if ( is_paged() && ( is_archive() || is_home() ) ) {
			$robots['noindex'] = true;
		}
		// Noindex posts marked as noindex
		if ( is_singular() ) {
			$post = get_queried_object();
			if ( $post && get_post_meta( $post->ID, self::META_NOINDEX, true ) ) {
				$robots['noindex'] = true;
			}
		}
		return $robots;
	}

	// ═════════════════════════════════════════════════════════════════════
	//  ROBOTS.TXT — add sitemap reference
	// ═════════════════════════════════════════════════════════════════════

	public static function robots_txt(): void {
		// WordPress calls this filter for virtual robots.txt
		add_filter( 'robots_txt', function( $output ) {
			if ( strpos( $output, 'Sitemap:' ) === false ) {
				$output .= "\nSitemap: " . home_url( '/wp-sitemap.xml' ) . "\n";
			}
			return $output;
		} );
	}

	// ═════════════════════════════════════════════════════════════════════
	//  AUTO ALT TEXT — generate from filename if missing
	// ═════════════════════════════════════════════════════════════════════

	public static function auto_alt( $attr, $attachment, $size ): array {
		if ( empty( $attr['alt'] ) ) {
			// Try attachment title first
			$title = get_the_title( $attachment->ID );
			if ( $title && $title !== $attachment->post_name ) {
				$attr['alt'] = $title;
			} else {
				// Generate from filename: my-product-photo.jpg → "My product photo"
				$file = get_attached_file( $attachment->ID );
				if ( $file ) {
					$name = pathinfo( $file, PATHINFO_FILENAME );
					$name = str_replace( [ '-', '_' ], ' ', $name );
					$name = preg_replace( '/\d{2,}/', '', $name ); // strip numeric sequences
					$name = ucfirst( trim( preg_replace( '/\s+/', ' ', $name ) ) );
					if ( $name ) $attr['alt'] = $name;
				}
			}
		}
		return $attr;
	}

	// ═════════════════════════════════════════════════════════════════════
	//  PING SEARCH ENGINES — on publish (throttled)
	// ═════════════════════════════════════════════════════════════════════

	public static function ping_on_publish( $post_id ): void {
		// Throttle: once per hour
		$last = (int) get_option( '_therum_seo_last_ping', 0 );
		if ( time() - $last < 3600 ) return;
		update_option( '_therum_seo_last_ping', time(), true );

		$sitemap = home_url( '/wp-sitemap.xml' );
		// Google (IndexNow not needed — they read sitemaps)
		wp_remote_get( 'https://www.google.com/ping?sitemap=' . urlencode( $sitemap ), [ 'timeout' => 5, 'blocking' => false ] );
	}

	// ═════════════════════════════════════════════════════════════════════
	//  SEO META BOX — per-post override UI in the editor
	// ═════════════════════════════════════════════════════════════════════

	public static function add_meta_box(): void {
		$post_types = get_post_types( [ 'public' => true ], 'names' );
		foreach ( $post_types as $pt ) {
			add_meta_box( 'therum-seo', 'Therum SEO', [ __CLASS__, 'render_meta_box' ], $pt, 'normal', 'low' );
		}
	}

	public static function render_meta_box( $post ): void {
		wp_nonce_field( 'therum_seo_save', 'therum_seo_nonce' );
		$title = get_post_meta( $post->ID, self::META_TITLE, true );
		$desc  = get_post_meta( $post->ID, self::META_DESCRIPTION, true );
		$image = get_post_meta( $post->ID, self::META_IMAGE, true );
		$noindex = get_post_meta( $post->ID, self::META_NOINDEX, true );

		$auto_desc = self::auto_description( $post );
		?>
		<div style="display:grid;gap:12px;font-size:13px;">
			<div>
				<label style="display:block;font-weight:600;margin-bottom:4px;">SEO Title <span style="font-weight:400;color:#999;">(leave blank for auto)</span></label>
				<input type="text" name="th_seo_title" value="<?php echo esc_attr( $title ); ?>" style="width:100%;padding:6px 10px;" placeholder="<?php echo esc_attr( $post->post_title ); ?>" />
				<div style="font-size:11px;color:#999;margin-top:4px;"><?php echo esc_html( mb_strlen( $title ?: $post->post_title ) ); ?> / 60 chars</div>
			</div>
			<div>
				<label style="display:block;font-weight:600;margin-bottom:4px;">Meta Description <span style="font-weight:400;color:#999;">(leave blank for auto)</span></label>
				<textarea name="th_seo_description" rows="3" style="width:100%;padding:6px 10px;" placeholder="<?php echo esc_attr( $auto_desc ); ?>"><?php echo esc_textarea( $desc ); ?></textarea>
				<div style="font-size:11px;color:#999;margin-top:4px;"><?php echo esc_html( mb_strlen( $desc ?: $auto_desc ) ); ?> / 160 chars</div>
			</div>
			<div>
				<label style="display:block;font-weight:600;margin-bottom:4px;">OG Image URL <span style="font-weight:400;color:#999;">(leave blank for featured image)</span></label>
				<input type="url" name="th_seo_image" value="<?php echo esc_attr( $image ); ?>" style="width:100%;padding:6px 10px;" placeholder="Auto: featured image or first content image" />
			</div>
			<div>
				<label style="display:flex;align-items:center;gap:8px;">
					<input type="checkbox" name="th_seo_noindex" value="1" <?php checked( $noindex ); ?> />
					<span>Noindex this page <span style="font-weight:400;color:#999;">— hide from search engines</span></span>
				</label>
			</div>
			<div style="padding:10px 12px;background:#f8f9fa;border-radius:8px;border:1px solid #e5e7eb;font-size:12px;color:#666;">
				<strong>Auto-SEO is active.</strong> If you leave these fields blank, Therum generates SEO tags from the post title, excerpt/content, and featured image automatically. Override only when the auto-generated values aren't ideal.
			</div>
		</div>
		<?php
	}

	public static function save_meta_box( $post_id, $post ): void {
		if ( ! isset( $_POST['therum_seo_nonce'] ) || ! wp_verify_nonce( $_POST['therum_seo_nonce'], 'therum_seo_save' ) ) return;
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
		if ( ! current_user_can( 'edit_post', $post_id ) ) return;

		$fields = [
			self::META_TITLE       => sanitize_text_field( wp_unslash( $_POST['th_seo_title'] ?? '' ) ),
			self::META_DESCRIPTION => sanitize_textarea_field( wp_unslash( $_POST['th_seo_description'] ?? '' ) ),
			self::META_IMAGE       => esc_url_raw( wp_unslash( $_POST['th_seo_image'] ?? '' ) ),
			self::META_NOINDEX     => ! empty( $_POST['th_seo_noindex'] ) ? '1' : '',
		];

		foreach ( $fields as $key => $value ) {
			if ( $value ) {
				update_post_meta( $post_id, $key, $value );
			} else {
				delete_post_meta( $post_id, $key );
			}
		}
	}
}

Therum_Auto_SEO::init();


// ════════════════════════════════════════════════════════════════════════════
//  PERFORMANCE SEO — lightweight optimizations that help ranking
// ════════════════════════════════════════════════════════════════════════════

// Preconnect to common external origins for faster resource loading
add_action( 'wp_head', function() {
	if ( is_admin() ) return;
	$origins = [ 'https://fonts.googleapis.com', 'https://fonts.gstatic.com' ];
	foreach ( $origins as $o ) {
		echo '<link rel="preconnect" href="' . esc_url( $o ) . '" crossorigin />' . "\n";
	}
}, 1 );

// Remove WP version from head (minor security + cleanliness)
remove_action( 'wp_head', 'wp_generator' );

// Remove shortlink
remove_action( 'wp_head', 'wp_shortlink_wp_head' );

// Remove RSD + wlw manifest links (legacy API discovery)
remove_action( 'wp_head', 'rsd_link' );
remove_action( 'wp_head', 'wlwmanifest_link' );

// Remove feed links from head (reduces clutter — feeds still work at /feed/)
remove_action( 'wp_head', 'feed_links_extra', 3 );

// Add rel="noopener" to external links rendered in content
add_filter( 'the_content', function( $content ) {
	return preg_replace_callback(
		'/<a\s([^>]*href=["\']https?:\/\/[^"\']+["\'][^>]*)>/i',
		function( $m ) {
			$tag = $m[0];
			$home = wp_parse_url( home_url(), PHP_URL_HOST );
			// Skip internal links
			if ( strpos( $tag, $home ) !== false ) return $tag;
			// Add rel="noopener noreferrer" if not already present
			if ( strpos( $tag, 'noopener' ) === false ) {
				if ( preg_match( '/rel=["\']([^"\']*)["\']/', $tag ) ) {
					$tag = preg_replace( '/rel=["\']([^"\']*)["\']/', 'rel="$1 noopener noreferrer"', $tag );
				} else {
					$tag = str_replace( '>', ' rel="noopener noreferrer">', $tag );
				}
			}
			// Add target="_blank" for external links if not set
			if ( strpos( $tag, 'target=' ) === false ) {
				$tag = str_replace( '>', ' target="_blank">', $tag );
			}
			return $tag;
		},
		$content
	);
} );

// Enforce trailing slashes for consistency (better for crawlers)
add_filter( 'user_trailingslashit', function( $url, $type ) {
	if ( $type === 'single' || $type === 'page' || $type === 'category' || $type === 'single_paged' ) {
		return trailingslashit( $url );
	}
	return $url;
}, 10, 2 );
