<?php
/**
 * Plugin Name: Therum OS — Content
 * Description: Post management tools: duplicate, preview/live links, Case Study CPT,
 *              card grid admin list, and post editor skin.
 *              Merged from therum-duplicate, therum-view-links, therum-case-study-cpt,
 *              therum-cards-admin, therum-editor, therum-plugin-settings (1.8.6).
 * Version: 1.9.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;


// ════════════════════════════════════════════════════════════════════════
// SHARED HELPER — word count that works for Bricks pages
// ════════════════════════════════════════════════════════════════════════

if ( ! function_exists( 'therum_post_word_count' ) ) {
	/**
	 * Return word count for a post.
	 *
	 * Bricks stores all page content in the `_bricks_page_content_2` meta
	 * (a serialised array of elements) and leaves post_content empty.
	 * str_word_count( post_content ) therefore returns 0 for every Bricks
	 * page.  This helper falls back to extracting text from the Bricks meta.
	 *
	 * @param  WP_Post|int $post
	 * @return int
	 */
	function therum_post_word_count( $post ): int {
		$post = is_object( $post ) ? $post : get_post( $post );
		if ( ! $post ) return 0;

		// Fast path: classic post_content is populated
		$plain = wp_strip_all_tags( (string) $post->post_content );
		if ( trim( $plain ) !== '' ) {
			return str_word_count( $plain );
		}

		// Bricks path: read _bricks_page_content_2 meta
		$meta = get_post_meta( $post->ID, '_bricks_page_content_2', true );
		if ( ! $meta ) return 0;

		$elements = is_array( $meta ) ? $meta : maybe_unserialize( $meta );
		if ( ! is_array( $elements ) ) return 0;

		// Keys that contain visible text in Bricks element settings
		static $text_keys = [
			'text', 'content', 'heading', 'subheading',
			'description', 'label', 'caption', 'cite',
		];
		$text = '';
		array_walk_recursive( $elements, function ( $val, $key ) use ( &$text, $text_keys ) {
			if ( is_string( $val ) && in_array( $key, $text_keys, true ) ) {
				$text .= ' ' . $val;
			}
		} );

		return str_word_count( wp_strip_all_tags( trim( $text ) ) );
	}
}


// ════════════════════════════════════════════════════════════════════════
// DUPLICATE POST — from therum-duplicate.php
// ════════════════════════════════════════════════════════════════════════


class Therum_Duplicate {

	const ACTION = 'therum_duplicate_post';
	const NONCE  = 'therum_duplicate_nonce';

	/** Meta keys that should NEVER be copied to the duplicate */
	const SKIP_META = [
		'_edit_lock',
		'_edit_last',
		'_wp_old_slug',
		'_wp_old_date',
	];

	public static function init() {
		add_action( 'admin_action_' . self::ACTION, [ __CLASS__, 'handle_single' ] );
		add_filter( 'post_row_actions', [ __CLASS__, 'row_action' ], 10, 2 );
		add_filter( 'page_row_actions', [ __CLASS__, 'row_action' ], 10, 2 );
		add_action( 'admin_init',       [ __CLASS__, 'register_bulk' ] );
		add_action( 'admin_notices',    [ __CLASS__, 'admin_notice' ] );
	}

	/* ──────────────── Row action: "Duplicate" link in the post list ─────── */

	public static function row_action( $actions, $post ) {
		if ( ! current_user_can( 'edit_post', $post->ID ) ) return $actions;
		$url = wp_nonce_url(
			admin_url( 'admin.php?action=' . self::ACTION . '&post=' . $post->ID ),
			self::NONCE,
			self::NONCE
		);
		$actions['therum_duplicate'] = '<a href="' . esc_url( $url ) . '" aria-label="Duplicate &quot;' . esc_attr( $post->post_title ) . '&quot;" title="Duplicate as draft">Duplicate</a>';
		return $actions;
	}

	/* ──────────────── Bulk action: "Duplicate" in the bulk dropdown ─────── */

	public static function register_bulk() {
		// Cover every registered post type, public + private (incl. bricks_template)
		$post_types = get_post_types( [], 'names' );
		foreach ( $post_types as $pt ) {
			add_filter( "bulk_actions-edit-$pt",        [ __CLASS__, 'add_bulk_action' ] );
			add_filter( "handle_bulk_actions-edit-$pt", [ __CLASS__, 'handle_bulk' ], 10, 3 );
		}
	}

	public static function add_bulk_action( $actions ) {
		$actions['therum_duplicate'] = 'Duplicate';
		return $actions;
	}

	public static function handle_bulk( $redirect_to, $action, $post_ids ) {
		if ( $action !== 'therum_duplicate' ) return $redirect_to;
		if ( ! current_user_can( 'edit_posts' ) ) return $redirect_to;
		$count = 0;
		foreach ( $post_ids as $id ) {
			if ( current_user_can( 'edit_post', $id ) && self::duplicate( $id ) ) {
				$count++;
			}
		}
		return add_query_arg( 'therum_duplicated', $count, $redirect_to );
	}

	/* ──────────────── Single-post handler (the row link target) ─────────── */

	public static function handle_single() {
		$post_id = isset( $_GET['post'] ) ? (int) $_GET['post'] : 0;
		if ( ! $post_id ) wp_die( 'No post specified.' );
		check_admin_referer( self::NONCE, self::NONCE );
		if ( ! current_user_can( 'edit_post', $post_id ) ) wp_die( 'Not allowed.' );

		$new_id = self::duplicate( $post_id );
		if ( ! $new_id ) wp_die( 'Duplicate failed.' );

		// Send the user straight to the edit screen of the new draft.
		wp_safe_redirect( admin_url( 'post.php?action=edit&post=' . $new_id ) );
		exit;
	}

	/* ──────────────── The duplicate logic itself ────────────────────────── */

	/**
	 * Duplicate a post including taxonomy assignments, post meta (incl. Bricks
	 * builder content), and featured image. New post is saved as draft with
	 * "(Copy)" appended to the title. Bricks template conditions are cleared
	 * on the duplicate to avoid immediate conflict with the original.
	 *
	 * @param int $source_id
	 * @return int|false New post ID on success, false on failure.
	 */
	public static function duplicate( $source_id ) {
		$source = get_post( $source_id );
		if ( ! $source ) return false;

		// 1. Insert the new post
		$new_post_data = [
			'post_title'     => $source->post_title . ' (Copy)',
			'post_content'   => $source->post_content,
			'post_excerpt'   => $source->post_excerpt,
			'post_status'    => 'draft',
			'post_type'      => $source->post_type,
			'post_author'    => get_current_user_id() ?: $source->post_author,
			'post_password'  => $source->post_password,
			'comment_status' => $source->comment_status,
			'ping_status'    => $source->ping_status,
			'menu_order'     => $source->menu_order,
		];
		$new_id = wp_insert_post( wp_slash( $new_post_data ), true );
		if ( is_wp_error( $new_id ) || ! $new_id ) return false;

		// 2. Copy taxonomy assignments (categories, tags, custom taxonomies)
		$taxonomies = get_object_taxonomies( $source->post_type );
		foreach ( $taxonomies as $tax ) {
			$term_ids = wp_get_object_terms( $source_id, $tax, [ 'fields' => 'ids' ] );
			if ( ! is_wp_error( $term_ids ) && ! empty( $term_ids ) ) {
				wp_set_object_terms( $new_id, array_map( 'intval', $term_ids ), $tax );
			}
		}

		// 3. Copy all post meta (including Bricks builder content)
		$meta = get_post_meta( $source_id );
		foreach ( $meta as $key => $values ) {
			if ( in_array( $key, self::SKIP_META, true ) ) continue;

			// Bricks template conditions — clear them on the duplicate so the
			// new template doesn't immediately compete with the original.
			if ( $key === '_bricks_template_settings' ) {
				$settings = maybe_unserialize( $values[0] );
				if ( is_array( $settings ) ) {
					$settings['templateConditions'] = [];
				}
				update_post_meta( $new_id, $key, $settings );
				continue;
			}

			foreach ( $values as $value ) {
				$value = maybe_unserialize( $value );
				update_post_meta( $new_id, $key, $value );
			}
		}

		/**
		 * After-duplicate hook so other code can react.
		 *
		 * @param int     $new_id    ID of the newly-created duplicate.
		 * @param int     $source_id ID of the post that was duplicated.
		 * @param WP_Post $source    The original post object.
		 */
		do_action( 'therum_duplicate_after', $new_id, $source_id, $source );

		return $new_id;
	}

	/* ──────────────── Admin notice on bulk-duplicate success ────────────── */

	public static function admin_notice() {
		if ( ! isset( $_GET['therum_duplicated'] ) ) return;
		$count = (int) $_GET['therum_duplicated'];
		if ( $count <= 0 ) return;
		printf(
			'<div class="notice notice-success is-dismissible"><p>Duplicated %d %s.</p></div>',
			$count,
			$count === 1 ? 'post' : 'posts'
		);
	}
}

Therum_Duplicate::init();

// ════════════════════════════════════════════════════════════════════════
// VIEW LINKS (Preview + View Live) — from therum-view-links.php
// ════════════════════════════════════════════════════════════════════════


class Therum_View_Links {

	public static function init() {
		add_filter( 'post_row_actions', [ __CLASS__, 'row_actions' ], 20, 2 );
		add_filter( 'page_row_actions', [ __CLASS__, 'row_actions' ], 20, 2 );
		add_action( 'post_submitbox_misc_actions', [ __CLASS__, 'submitbox_view_link' ] );
	}

	/* ────────────────── Row actions: Preview + View Live ─────────────────── */

	public static function row_actions( $actions, $post ) {
		if ( ! current_user_can( 'edit_post', $post->ID ) ) return $actions;

		// Bricks templates get a special treatment — find a sample post where the template applies.
		if ( $post->post_type === 'bricks_template' ) {
			$sample_url = self::resolve_template_preview_url( $post->ID );
			if ( $sample_url ) {
				$actions['therum_view_template'] = sprintf(
					'<a href="%s" target="_blank" rel="noopener" title="Open the page where this template is currently applied">View on site &nearr;</a>',
					esc_url( $sample_url )
				);
			}
			return $actions;
		}

		// For drafts/pending: add a "Preview" row action (links to draft-preview URL).
		// WP only shows "Preview" inline natively for some statuses; we add it consistently.
		if ( in_array( $post->post_status, [ 'draft', 'pending', 'auto-draft', 'future' ], true ) ) {
			$preview_url = get_preview_post_link( $post );
			if ( $preview_url ) {
				$actions['therum_preview'] = sprintf(
					'<a href="%s" target="_blank" rel="noopener">Preview &nearr;</a>',
					esc_url( $preview_url )
				);
			}
		}

		// For anything publicly-viewable, add a "View Live" link that opens in a new tab.
		// WP's native "View" link reuses the current tab; this one is explicitly new-tab.
		if ( $post->post_status === 'publish' && is_post_type_viewable( $post->post_type ) ) {
			$live_url = get_permalink( $post );
			if ( $live_url ) {
				$actions['therum_view_live'] = sprintf(
					'<a href="%s" target="_blank" rel="noopener">View Live &nearr;</a>',
					esc_url( $live_url )
				);
			}
		}

		return $actions;
	}

	/* ────────────────── Edit screen: link in the publish meta box ────────── */

	public static function submitbox_view_link( $post ) {
		if ( ! current_user_can( 'edit_post', $post->ID ) ) return;

		$lines = [];

		if ( $post->post_type === 'bricks_template' ) {
			$sample_url = self::resolve_template_preview_url( $post->ID );
			if ( $sample_url ) {
				$lines[] = sprintf(
					'<a href="%s" target="_blank" rel="noopener" class="button">View on site &nearr;</a>',
					esc_url( $sample_url )
				);
			}
		} else {
			if ( in_array( $post->post_status, [ 'draft', 'pending', 'auto-draft', 'future' ], true ) ) {
				$preview_url = get_preview_post_link( $post );
				if ( $preview_url ) {
					$lines[] = sprintf(
						'<a href="%s" target="_blank" rel="noopener" class="button">Preview &nearr;</a>',
						esc_url( $preview_url )
					);
				}
			}
			if ( $post->post_status === 'publish' && is_post_type_viewable( $post->post_type ) ) {
				$live_url = get_permalink( $post );
				if ( $live_url ) {
					$lines[] = sprintf(
						'<a href="%s" target="_blank" rel="noopener" class="button button-primary">View Live &nearr;</a>',
						esc_url( $live_url )
					);
				}
			}
		}

		if ( empty( $lines ) ) return;
		echo '<div class="misc-pub-section therum-view-links" style="display:flex;gap:8px;flex-wrap:wrap;">' . implode( ' ', $lines ) . '</div>';
	}

	/* ────────────────── Helper: find a URL to view a Bricks template ─────── */

	/**
	 * Given a Bricks template ID, look at its conditions and return a permalink
	 * to a real post where it's being applied (so clicking "View" actually
	 * shows the template in context). Returns null if no match.
	 */
	public static function resolve_template_preview_url( $template_id ) {
		$settings = get_post_meta( $template_id, '_bricks_template_settings', true );
		if ( ! is_array( $settings ) || empty( $settings['templateConditions'] ) ) return null;

		foreach ( $settings['templateConditions'] as $cond ) {
			if ( ! isset( $cond['main'] ) ) continue;

			// Specific post IDs
			if ( $cond['main'] === 'ids' && ! empty( $cond['ids'] ) ) {
				foreach ( $cond['ids'] as $id ) {
					$url = get_permalink( (int) $id );
					if ( $url ) return $url;
				}
			}

			// Specific post types — return the most recent published post of any type listed
			if ( $cond['main'] === 'postType' && ! empty( $cond['postType'] ) ) {
				$posts = get_posts( [
					'post_type'      => $cond['postType'],
					'post_status'    => 'publish',
					'posts_per_page' => 1,
					'orderby'        => 'date',
					'order'          => 'DESC',
				] );
				if ( ! empty( $posts ) ) {
					$url = get_permalink( $posts[0] );
					if ( $url ) return $url;
				}
			}

			// Specific terms — return a post tagged with the term
			if ( $cond['main'] === 'terms' && ! empty( $cond['terms'] ) ) {
				foreach ( $cond['terms'] as $tax_term ) {
					$parts    = explode( '::', $tax_term );
					$taxonomy = $parts[0] ?? '';
					$term_id  = (int) ( $parts[1] ?? 0 );
					if ( ! $taxonomy || ! $term_id ) continue;
					$posts = get_posts( [
						'post_status'    => 'publish',
						'posts_per_page' => 1,
						'tax_query'      => [ [ 'taxonomy' => $taxonomy, 'field' => 'term_id', 'terms' => $term_id ] ],
					] );
					if ( ! empty( $posts ) ) {
						$url = get_permalink( $posts[0] );
						if ( $url ) return $url;
					}
				}
			}
		}

		return null;
	}
}

Therum_View_Links::init();

// ════════════════════════════════════════════════════════════════════════
// CASE STUDY CPT — from therum-case-study-cpt.php
// ════════════════════════════════════════════════════════════════════════


add_action( 'init', function () {

	// ── Case Study CPT ──────────────────────────────────────────────────
	register_post_type( 'case_study', [
		'labels' => [
			'name'                  => 'Case Studies',
			'singular_name'         => 'Case Study',
			'menu_name'             => 'Case Studies',
			'add_new'               => 'Add Case Study',
			'add_new_item'          => 'Add New Case Study',
			'edit_item'             => 'Edit Case Study',
			'new_item'              => 'New Case Study',
			'view_item'             => 'View Case Study',
			'view_items'            => 'View Case Studies',
			'all_items'             => 'All Case Studies',
			'search_items'          => 'Search Case Studies',
			'not_found'             => 'No case studies found.',
			'not_found_in_trash'    => 'No case studies found in Trash.',
			'featured_image'        => 'Hero image',
			'set_featured_image'    => 'Set hero image',
			'remove_featured_image' => 'Remove hero image',
			'use_featured_image'    => 'Use as hero image',
		],
		'description'  => 'Long-form case studies — used by the Bricks "Case Study" Single template.',
		'public'       => true,
		'show_ui'      => true,
		'show_in_menu' => true,
		'show_in_rest' => true,
		'menu_position'=> 5,
		'menu_icon'    => 'dashicons-portfolio',
		'has_archive'  => false,                // index page (Selected) is a regular WP page
		'rewrite'      => [ 'slug' => 'case-study', 'with_front' => false ],
		'capability_type' => 'post',
		'supports'     => [ 'title', 'editor', 'thumbnail', 'excerpt', 'custom-fields', 'revisions' ],
	] );

	// ── Discipline taxonomy ─────────────────────────────────────────────
	register_taxonomy( 'case_study_discipline', [ 'case_study' ], [
		'labels' => [
			'name'              => 'Disciplines',
			'singular_name'     => 'Discipline',
			'menu_name'         => 'Disciplines',
			'all_items'         => 'All Disciplines',
			'search_items'      => 'Search Disciplines',
			'add_new_item'      => 'Add New Discipline',
		],
		'public'             => true,
		'hierarchical'       => false,
		'show_ui'            => true,
		'show_in_rest'       => true,
		'show_admin_column'  => true,
		'rewrite'            => [ 'slug' => 'discipline' ],
	] );

	// ── Tags taxonomy (cross-cutting, lighter than disciplines) ────────
	register_taxonomy( 'case_study_tag', [ 'case_study' ], [
		'labels' => [
			'name'          => 'Project Tags',
			'singular_name' => 'Project Tag',
			'menu_name'     => 'Tags',
		],
		'public'            => true,
		'hierarchical'      => false,
		'show_ui'           => true,
		'show_in_rest'      => true,
		'show_admin_column' => true,
		'rewrite'           => [ 'slug' => 'project-tag' ],
	] );
} );

// ── Pre-seed the 6 disciplines on first load ────────────────────────────
add_action( 'init', function () {
	$terms = [ 'Product', 'Design Systems', 'Brand', 'Art Direction', 'Motion', 'Engineering' ];
	foreach ( $terms as $name ) {
		if ( ! term_exists( $name, 'case_study_discipline' ) ) {
			wp_insert_term( $name, 'case_study_discipline' );
		}
	}
}, 20 );

// ════════════════════════════════════════════════════════════════════════
// CARDS ADMIN (card grid for edit.php screens) — from therum-cards-admin.php
// ════════════════════════════════════════════════════════════════════════


class Therum_Cards_Admin {

	/** Post types that get the card view (filterable) */
	public static function post_types() {
		return apply_filters( 'therum_cards_post_types', [ 'page', 'post', 'case_study' ] );
	}

	public static function init() {
		add_action( 'current_screen', [ __CLASS__, 'maybe_activate' ] );
	}

	public static function maybe_activate( $screen ) {
		if ( ! $screen || $screen->base !== 'edit' ) return;
		if ( ! in_array( $screen->post_type, self::post_types(), true ) ) return;

		// Active for this screen — hook the rendering bits.
		add_action( 'admin_head',     [ __CLASS__, 'inline_css' ] );
		add_action( 'admin_notices',  [ __CLASS__, 'render_cards' ], 99 );
		add_action( 'admin_footer',   [ __CLASS__, 'inline_js' ] );
	}

	/* ────────────────── CSS: hide standard table, style the cards ────────── */

	public static function inline_css() { ?>
		<style id="therum-cards-css">
		/* Hide the WP standard list bits — we replace them entirely */
		body.post-type-<?php echo esc_attr( get_current_screen()->post_type ); ?> #posts-filter,
		body.post-type-<?php echo esc_attr( get_current_screen()->post_type ); ?> .subsubsub,
		body.post-type-<?php echo esc_attr( get_current_screen()->post_type ); ?> .search-box,
		body.post-type-<?php echo esc_attr( get_current_screen()->post_type ); ?> .tablenav { display: none !important; }

		/* ── Layout ───────────────────────────────────────────────────────── */
		.therum-cards { padding: 24px 0 60px; max-width: 1600px; }
		.therum-cards__head {
			display: flex;
			justify-content: space-between;
			align-items: center;
			margin-bottom: 24px;
			gap: 16px;
			flex-wrap: wrap;
		}
		.therum-cards__count {
			font-family: ui-monospace, "SF Mono", Menlo, monospace;
			font-size: 12px;
			letter-spacing: 0.04em;
			text-transform: uppercase;
			color: #6b6b6b;
		}
		.therum-cards__filter {
			display: inline-flex;
			gap: 4px;
			background: rgba(0,0,0,0.04);
			border-radius: 999px;
			padding: 4px;
		}
		.therum-cards__filter a {
			padding: 6px 14px;
			border-radius: 999px;
			color: #555;
			text-decoration: none;
			font-size: 12px;
			font-weight: 500;
			letter-spacing: 0.02em;
			text-transform: uppercase;
		}
		.therum-cards__filter a.is-active {
			background: #1a1a1a;
			color: #fff;
		}
		.therum-cards__search input[type=search] {
			padding: 8px 14px;
			border: 1px solid #ddd;
			border-radius: 999px;
			min-width: 240px;
			font-size: 13px;
		}

		.therum-cards__grid {
			display: grid;
			grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
			gap: 18px;
		}

		/* ── Card ─────────────────────────────────────────────────────────── */
		.therum-card {
			position: relative;
			aspect-ratio: 4 / 5;
			border-radius: 14px;
			overflow: visible;
			background: #1a1a1a;
			color: #fff;
			cursor: pointer;
			transition: transform 250ms cubic-bezier(0.16, 1, 0.3, 1),
			            box-shadow 250ms cubic-bezier(0.16, 1, 0.3, 1);
			box-shadow: 0 1px 3px rgba(0,0,0,0.06);
		}
		.therum-card:hover {
			transform: translateY(-3px);
			box-shadow: 0 8px 24px rgba(0,0,0,0.15);
		}

		.therum-card__media {
			position: absolute;
			inset: 0;
			border-radius: 14px;
			overflow: hidden;
			background-size: cover;
			background-position: center;
			background-color: #1a1a1a;
		}
		/* Color the placeholder bg by post title hash */
		.therum-card[data-hue="0"]   .therum-card__media { background-image: linear-gradient(135deg, #1a1a4a, #2d1f5c, #0a0a1a); }
		.therum-card[data-hue="1"]   .therum-card__media { background-image: linear-gradient(135deg, #1a3a3a, #1f5c4d, #0a1a14); }
		.therum-card[data-hue="2"]   .therum-card__media { background-image: linear-gradient(135deg, #4a1a1a, #5c2d1f, #1a0a0a); }
		.therum-card[data-hue="3"]   .therum-card__media { background-image: linear-gradient(135deg, #1a4a3a, #2d5c4d, #0a1a14); }
		.therum-card[data-hue="4"]   .therum-card__media { background-image: linear-gradient(135deg, #4a4a1a, #5c5c2d, #1a1a0a); }
		.therum-card[data-hue="5"]   .therum-card__media { background-image: linear-gradient(135deg, #4a1a4a, #5c2d5c, #1a0a1a); }
		.therum-card[data-hue="6"]   .therum-card__media { background-image: linear-gradient(135deg, #1a1a1a, #2d2d2d, #0a0a0a); }

		.therum-card__media::after {
			content: "";
			position: absolute;
			inset: 0;
			background: linear-gradient(180deg, rgba(0,0,0,0) 50%, rgba(0,0,0,0.6) 100%);
			pointer-events: none;
		}

		/* Status badge */
		.therum-card__status {
			position: absolute;
			top: 14px;
			left: 14px;
			z-index: 2;
			display: inline-flex;
			align-items: center;
			gap: 6px;
			padding: 5px 10px 5px 8px;
			background: rgba(20, 20, 20, 0.8);
			backdrop-filter: blur(12px);
			-webkit-backdrop-filter: blur(12px);
			border-radius: 999px;
			color: #fff;
			font-size: 11px;
			font-weight: 500;
			letter-spacing: 0.02em;
			line-height: 1;
		}
		.therum-card__status::before {
			content: "";
			display: inline-block;
			width: 6px;
			height: 6px;
			border-radius: 50%;
			background: #4ade80;
		}
		.therum-card__status[data-status="draft"]::before    { background: #fbbf24; }
		.therum-card__status[data-status="pending"]::before  { background: #f97316; }
		.therum-card__status[data-status="future"]::before   { background: #60a5fa; }
		.therum-card__status[data-status="private"]::before  { background: #a78bfa; }
		.therum-card__status[data-status="trash"]::before    { background: #ef4444; }

		/* 3-dot menu trigger */
		.therum-card__menu-trigger {
			position: absolute;
			top: 12px;
			right: 12px;
			z-index: 3;
			width: 32px;
			height: 32px;
			border-radius: 50%;
			background: rgba(20, 20, 20, 0.7);
			backdrop-filter: blur(12px);
			-webkit-backdrop-filter: blur(12px);
			border: 0;
			color: #fff;
			cursor: pointer;
			display: inline-flex;
			align-items: center;
			justify-content: center;
			padding: 0;
			transition: background 200ms ease;
		}
		.therum-card__menu-trigger:hover { background: rgba(255, 255, 255, 0.18); }
		.therum-card__menu-trigger svg { width: 14px; height: 14px; }

		/* The floating popover menu — sits above everything */
		.therum-card__menu {
			position: absolute;
			top: 50px;
			right: 12px;
			z-index: 100;
			min-width: 220px;
			background: rgba(255, 255, 255, 0.92);
			backdrop-filter: blur(20px) saturate(180%);
			-webkit-backdrop-filter: blur(20px) saturate(180%);
			border: 1px solid rgba(0, 0, 0, 0.06);
			border-radius: 12px;
			padding: 6px;
			box-shadow: 0 16px 40px rgba(0, 0, 0, 0.18);
			opacity: 0;
			pointer-events: none;
			transform: translateY(-4px) scale(0.96);
			transform-origin: top right;
			transition: opacity 180ms ease, transform 220ms cubic-bezier(0.16, 1, 0.3, 1);
		}
		.therum-card.is-menu-open .therum-card__menu {
			opacity: 1;
			pointer-events: auto;
			transform: translateY(0) scale(1);
		}
		.therum-card__menu a,
		.therum-card__menu button {
			display: flex;
			align-items: center;
			gap: 10px;
			padding: 9px 12px;
			border-radius: 8px;
			color: #1a1a1a;
			text-decoration: none;
			font-size: 13px;
			font-weight: 500;
			width: 100%;
			background: none;
			border: 0;
			cursor: pointer;
			text-align: left;
			line-height: 1.3;
		}
		.therum-card__menu a:hover,
		.therum-card__menu button:hover { background: rgba(0, 0, 0, 0.05); }
		.therum-card__menu .menu-divider {
			height: 1px;
			background: rgba(0, 0, 0, 0.08);
			margin: 4px 6px;
		}
		.therum-card__menu .danger { color: #dc2626; }
		.therum-card__menu .danger:hover { background: rgba(220, 38, 38, 0.08); }

		/* Title + meta sit at the bottom of the card */
		.therum-card__body {
			position: absolute;
			bottom: 16px;
			left: 16px;
			right: 16px;
			z-index: 2;
			pointer-events: none;
		}
		.therum-card__title {
			font-size: 22px;
			font-weight: 600;
			letter-spacing: -0.02em;
			line-height: 1.1;
			color: #fff;
			margin: 0 0 6px;
			text-shadow: 0 1px 2px rgba(0,0,0,0.3);
			text-overflow: ellipsis;
			overflow: hidden;
			display: -webkit-box;
			-webkit-line-clamp: 2;
			-webkit-box-orient: vertical;
		}
		.therum-card__sub {
			display: block;
			font-family: ui-monospace, "SF Mono", Menlo, monospace;
			font-size: 11px;
			color: rgba(255, 255, 255, 0.7);
			letter-spacing: 0.02em;
		}

		/* Card click area — wraps title region; menu overrides */
		.therum-card__link {
			position: absolute;
			inset: 0;
			z-index: 1;
			text-decoration: none;
		}

		/* ── Add New CTA card ─────────────────────────────────────────────── */
		.therum-card--new {
			background: transparent;
			border: 2px dashed rgba(0, 0, 0, 0.15);
			cursor: pointer;
			color: #555;
		}
		.therum-card--new:hover {
			border-color: rgba(0, 0, 0, 0.4);
			background: rgba(0, 0, 0, 0.02);
			transform: translateY(-3px);
		}
		.therum-card--new .therum-card__media,
		.therum-card--new .therum-card__media::after { display: none; }
		.therum-card--new .therum-card__body {
			position: absolute;
			inset: 0;
			display: flex;
			flex-direction: column;
			align-items: center;
			justify-content: center;
			color: inherit;
			pointer-events: none;
		}
		.therum-card--new .therum-card__title {
			color: inherit;
			text-shadow: none;
			font-size: 16px;
			font-weight: 500;
			margin: 8px 0 0;
		}
		.therum-card--new .therum-card__plus {
			width: 44px;
			height: 44px;
			border-radius: 50%;
			background: rgba(0, 0, 0, 0.05);
			display: grid;
			place-items: center;
			font-size: 22px;
			line-height: 1;
		}
		.therum-card--new .therum-card__link { z-index: 2; }
		</style>
	<?php }

	/* ────────────────── Render the card grid ─────────────────────────────── */

	public static function render_cards() {
		$screen    = get_current_screen();
		$post_type = $screen->post_type;
		$pto       = get_post_type_object( $post_type );

		// Honor the current view filter (publish / draft / trash / etc.) if set
		$status_filter = isset( $_GET['post_status'] ) ? sanitize_key( $_GET['post_status'] ) : 'any';
		$query_args = [
			'post_type'      => $post_type,
			'post_status'    => $status_filter === 'any' ? [ 'publish', 'draft', 'pending', 'future', 'private' ] : [ $status_filter ],
			'posts_per_page' => 60,
			'orderby'        => 'modified',
			'order'          => 'DESC',
		];
		if ( ! empty( $_GET['s'] ) ) {
			$query_args['s'] = sanitize_text_field( wp_unslash( $_GET['s'] ) );
		}
		$q     = new WP_Query( $query_args );
		$total = $q->found_posts;

		// Status counts for the filter chips
		$counts = wp_count_posts( $post_type );

		$base_url = admin_url( 'edit.php?post_type=' . $post_type );

		?>
		<div class="therum-cards" data-post-type="<?php echo esc_attr( $post_type ); ?>">

			<div class="therum-cards__head">
				<div style="display:flex;align-items:center;gap:14px;flex-wrap:wrap;">
					<span class="therum-cards__count"><?php echo number_format_i18n( $total ); ?> <?php echo esc_html( _n( 'item', 'items', $total ) ); ?></span>
					<div class="therum-cards__filter">
						<a href="<?php echo esc_url( $base_url ); ?>" class="<?php echo $status_filter === 'any' ? 'is-active' : ''; ?>">All</a>
						<?php foreach ( [ 'publish' => 'Published', 'draft' => 'Drafts', 'pending' => 'Pending', 'future' => 'Scheduled', 'private' => 'Private' ] as $s => $label ) :
							$count = isset( $counts->$s ) ? (int) $counts->$s : 0;
							if ( $count <= 0 ) continue;
						?>
							<a href="<?php echo esc_url( add_query_arg( 'post_status', $s, $base_url ) ); ?>" class="<?php echo $status_filter === $s ? 'is-active' : ''; ?>"><?php echo esc_html( $label ); ?> <span style="opacity:0.6;font-weight:400;">(<?php echo $count; ?>)</span></a>
						<?php endforeach; ?>
					</div>
				</div>
				<form class="therum-cards__search" method="get" action="<?php echo esc_url( admin_url( 'edit.php' ) ); ?>">
					<input type="hidden" name="post_type" value="<?php echo esc_attr( $post_type ); ?>">
					<input type="search" name="s" value="<?php echo esc_attr( wp_unslash( $_GET['s'] ?? '' ) ); ?>" placeholder="Search <?php echo esc_attr( strtolower( $pto->labels->name ) ); ?>…">
				</form>
			</div>

			<div class="therum-cards__grid">

				<?php /* "Add new" card always first */ ?>
				<a class="therum-card therum-card--new" href="<?php echo esc_url( admin_url( 'post-new.php?post_type=' . $post_type ) ); ?>">
					<div class="therum-card__body">
						<span class="therum-card__plus">+</span>
						<h2 class="therum-card__title">New <?php echo esc_html( $pto->labels->singular_name ); ?></h2>
					</div>
					<span class="therum-card__link"></span>
				</a>

				<?php if ( $q->have_posts() ) : while ( $q->have_posts() ) : $q->the_post();
					$post = get_post();
					$thumb = get_the_post_thumbnail_url( $post, 'medium_large' );
					$hue   = abs( crc32( $post->post_title ) ) % 7;
					$edit  = get_edit_post_link( $post->ID );
					$bricks_edit = function_exists( 'therum_bricks_builder_url' ) ? therum_bricks_builder_url( $post ) : '';
					$preview = get_preview_post_link( $post );
					$live    = $post->post_status === 'publish' && is_post_type_viewable( $post->post_type ) ? get_permalink( $post ) : '';
					$dup_url = wp_nonce_url(
						admin_url( 'admin.php?action=therum_duplicate_post&post=' . $post->ID ),
						'therum_duplicate_nonce',
						'therum_duplicate_nonce'
					);
					$trash_url = get_delete_post_link( $post->ID );
					$word_count = therum_post_word_count( $post );
					$timeago    = human_time_diff( get_post_modified_time( 'U', false, $post ), current_time( 'U' ) );
				?>
					<div class="therum-card" data-hue="<?php echo esc_attr( $hue ); ?>" data-id="<?php echo esc_attr( $post->ID ); ?>">

						<div class="therum-card__media"<?php echo $thumb ? ' style="background-image:url(\'' . esc_url( $thumb ) . '\')"' : ''; ?>></div>

						<span class="therum-card__status" data-status="<?php echo esc_attr( $post->post_status ); ?>"><?php echo esc_html( ucfirst( $post->post_status ) ); ?></span>

						<button type="button" class="therum-card__menu-trigger" data-therum-card-menu aria-label="Actions">
							<svg viewBox="0 0 16 4" fill="currentColor"><circle cx="2" cy="2" r="1.6"/><circle cx="8" cy="2" r="1.6"/><circle cx="14" cy="2" r="1.6"/></svg>
						</button>

						<div class="therum-card__menu">
							<a href="<?php echo esc_url( $edit ); ?>">Edit</a>
							<?php if ( $bricks_edit ) : ?>
								<a href="<?php echo esc_url( $bricks_edit ); ?>" target="_blank">Edit with Bricks ↗</a>
							<?php endif; ?>
							<a href="<?php echo esc_url( $dup_url ); ?>">Duplicate</a>
							<div class="menu-divider"></div>
							<?php if ( $preview && in_array( $post->post_status, [ 'draft', 'pending', 'auto-draft', 'future' ], true ) ) : ?>
								<a href="<?php echo esc_url( $preview ); ?>" target="_blank">Preview ↗</a>
							<?php endif; ?>
							<?php if ( $live ) : ?>
								<a href="<?php echo esc_url( $live ); ?>" target="_blank">View Live ↗</a>
							<?php endif; ?>
							<div class="menu-divider"></div>
							<a class="danger" href="<?php echo esc_url( $trash_url ); ?>" onclick="return confirm('Move <?php echo esc_js( $post->post_title ); ?> to trash?')">Move to trash</a>
						</div>

						<div class="therum-card__body">
							<h2 class="therum-card__title"><?php echo esc_html( $post->post_title ?: '(no title)' ); ?></h2>
							<span class="therum-card__sub"><?php echo number_format_i18n( $word_count ); ?> words · <?php echo esc_html( $timeago ); ?> ago</span>
						</div>

						<a class="therum-card__link" href="<?php echo esc_url( $edit ); ?>" aria-label="<?php echo esc_attr( $post->post_title ); ?>"></a>
					</div>
				<?php endwhile; wp_reset_postdata(); else : ?>
					<div class="therum-card" style="aspect-ratio:auto;padding:60px;text-align:center;color:#888;background:transparent;border:1px dashed rgba(0,0,0,0.1);">
						<div class="therum-card__body" style="position:static;">
							<h2 class="therum-card__title" style="color:#888;text-shadow:none;">No <?php echo esc_html( strtolower( $pto->labels->name ) ); ?> match.</h2>
							<span class="therum-card__sub" style="color:#aaa;">Try a different filter or search term.</span>
						</div>
					</div>
				<?php endif; ?>

			</div>
		</div>
		<?php
	}

	/* ────────────────── JS: open/close menu, click outside closes ────────── */

	public static function inline_js() { ?>
		<script>
		(function () {
			var openMenuCard = null;
			document.addEventListener('click', function (e) {
				var trigger = e.target.closest('[data-therum-card-menu]');
				if (trigger) {
					e.preventDefault();
					e.stopPropagation();
					var card = trigger.closest('.therum-card');
					if (openMenuCard && openMenuCard !== card) openMenuCard.classList.remove('is-menu-open');
					var nowOpen = !card.classList.contains('is-menu-open');
					card.classList.toggle('is-menu-open', nowOpen);
					openMenuCard = nowOpen ? card : null;
					return;
				}
				// Click anywhere else: close any open menu
				if (openMenuCard && !e.target.closest('.therum-card__menu')) {
					openMenuCard.classList.remove('is-menu-open');
					openMenuCard = null;
				}
			});
			// ESC also closes
			document.addEventListener('keydown', function (e) {
				if (e.key === 'Escape' && openMenuCard) {
					openMenuCard.classList.remove('is-menu-open');
					openMenuCard = null;
				}
			});
		})();
		</script>
	<?php }
}

Therum_Cards_Admin::init();

// ════════════════════════════════════════════════════════════════════════
// EDITOR SKIN (post.php chrome) — from therum-editor.php
// ════════════════════════════════════════════════════════════════════════


// ─────────────────────────────────────────────────────────────────────────────
//  PAGES → BRICKS AUTO-REDIRECT
// ─────────────────────────────────────────────────────────────────────────────
add_action( 'load-post.php', function() {
	$screen = get_current_screen();
	if ( ! $screen || $screen->base !== 'post' ) return;
	if ( $screen->post_type !== 'page' ) return;

	if ( ! class_exists( '\\Bricks\\Helpers' ) ) return;
	if ( ! empty( $_GET['editor_mode'] ) && $_GET['editor_mode'] === 'wordpress' ) return;

	$post_id = isset( $_GET['post'] ) ? (int) $_GET['post'] : 0;
	if ( $post_id <= 0 ) return;

	$builder_url = \Bricks\Helpers::get_builder_edit_link( $post_id );
	if ( $builder_url ) {
		wp_safe_redirect( $builder_url );
		exit;
	}
}, 1 );

/**
 * Build a usable Bricks builder URL for any post.
 *
 * Bricks::Helpers::get_builder_edit_link() relies on get_permalink(), which
 * returns an empty/preview URL for auto-drafts and for post types like
 * bricks_template that aren't publicly queryable. In those cases we construct
 * the URL manually — Bricks just needs `?bricks=run` plus enough info
 * (`p` + `post_type`) to identify the post.
 */
function therum_bricks_builder_url( $post ): string {
	if ( ! $post || ! class_exists( '\\Bricks\\Helpers' ) ) return '';

	// Bricks must support this post type (Settings → Builder → Post types)
	if ( method_exists( '\\Bricks\\Helpers', 'is_post_type_supported' ) ) {
		if ( ! \Bricks\Helpers::is_post_type_supported( $post->ID ) ) return '';
	}

	$param = defined( 'BRICKS_BUILDER_PARAM' ) ? BRICKS_BUILDER_PARAM : 'bricks';

	// Try the helper first — it's correct for published, public post types.
	$url = \Bricks\Helpers::get_builder_edit_link( $post->ID );
	$looks_valid = $url
		&& strpos( $url, $param . '=run' ) !== false
		&& filter_var( $url, FILTER_VALIDATE_URL );
	if ( $looks_valid ) return $url;

	// Fallback for auto-drafts / non-public post types — point at the preview
	// permalink so Bricks resolves the post via p= + post_type=.
	$args = [ 'p' => $post->ID, $param => 'run' ];
	if ( $post->post_type !== 'post' && $post->post_type !== 'page' ) {
		$args['post_type'] = $post->post_type;
	} elseif ( $post->post_type === 'page' ) {
		$args = [ 'page_id' => $post->ID, $param => 'run' ];
	}
	return add_query_arg( $args, home_url( '/' ) );
}

// ─────────────────────────────────────────────────────────────────────────────
//  EDITOR TOOLBAR — Internal (TinyMCE Visual) | Code (TinyMCE Text) | Bricks
//  Internal/Code switch in-place via TinyMCE's built-in tabs (no reload).
//  Bricks opens the full-screen builder. The inline Save button persists via
//  the therum_save_post AJAX endpoint so the user never leaves this page to save.
// ─────────────────────────────────────────────────────────────────────────────
add_action( 'edit_form_top', function( $post ) {
	if ( ! $post ) return;
	if ( ! current_user_can( 'edit_post', $post->ID ) ) return;

	$builder_url = therum_bricks_builder_url( $post );
	$nonce = wp_create_nonce( 'therum_save_post' );
	?>
	<div class="th-editor-bar" data-th-editor-bar data-post-id="<?php echo (int) $post->ID; ?>" data-nonce="<?php echo esc_attr( $nonce ); ?>">
		<div class="th-editor-modes">
			<button type="button" class="th-em is-active" data-mode="visual" title="Default editor (visual)">
				<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7z"/></svg>
				<span>Default Editor</span>
			</button>
			<button type="button" class="th-em" data-mode="code" title="Code editor (HTML)">
				<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/></svg>
				<span>Code Editor</span>
			</button>
			<?php if ( $builder_url ): ?>
			<a class="th-em th-em-bricks" href="<?php echo esc_url( $builder_url ); ?>" title="Edit in Bricks (full-screen builder)">
				<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
				<span>Edit with Bricks</span>
			</a>
			<?php endif; ?>
		</div>
		<button type="button" class="th-editor-save" data-th-save>
			<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
			<span>Save</span>
		</button>
	</div>
	<?php
}, 5 );

// If the post type doesn't natively support a content editor (e.g. bricks_template),
// inject a TinyMCE instance below the title so the Default/Code toolbar buttons
// have something to act on. Saves go to post_content via therum_save_post.
add_action( 'edit_form_after_title', function( $post ) {
	if ( ! $post || ! current_user_can( 'edit_post', $post->ID ) ) return;
	if ( post_type_supports( $post->post_type, 'editor' ) ) return; // WP renders its own
	echo '<div id="postdivrich" class="postarea th-rendered-editor">';
	wp_editor( $post->post_content, 'content', [
		'media_buttons'    => true,
		'textarea_rows'    => 18,
		'textarea_name'    => 'content',
		'editor_class'     => 'th-content-editor',
		'tinymce'          => true,
		'quicktags'        => true,
		'drag_drop_upload' => true,
	] );
	echo '</div>';
}, 5 );

// Persist post_content from our injected editor on standard WP form submit
// (covers Save Draft / Publish in the right column when editor isn't native).
add_action( 'save_post', function( $post_id, $post ) {
	// Static re-entrancy guard. The previous version called
	// remove_action('save_post', __FUNCTION__, 10) — but inside a closure
	// __FUNCTION__ resolves to '{closure}', not the actual callable, so the
	// removal silently no-op'd and a wp_update_post() below would re-fire
	// save_post into the same closure on installs where the post type didn't
	// hit the early-return guards above.
	static $reentry = false;
	if ( $reentry ) return;
	if ( wp_is_post_revision( $post_id ) ) return;
	if ( post_type_supports( $post->post_type, 'editor' ) ) return; // WP handles
	if ( ! current_user_can( 'edit_post', $post_id ) ) return;
	if ( ! isset( $_POST['content'] ) ) return;
	$reentry = true;
	try {
		wp_update_post( [
			'ID'           => $post_id,
			'post_content' => wp_kses_post( wp_unslash( $_POST['content'] ) ),
		] );
	} finally {
		$reentry = false;
	}
}, 10, 2 );

// Reposition meta boxes that hang in the main column for non-editor post types
// (e.g. bricks_template's Author box) — push them to the side context so the
// layout stays clean instead of orphaning a half-width card below the grid.
add_action( 'add_meta_boxes', function( $post_type, $post ) {
	if ( post_type_supports( $post_type, 'editor' ) ) return; // editor post types are fine
	$move = [
		'authordiv'        => 'Author',
		'pageparentdiv'    => 'Page Attributes',
		'commentstatusdiv' => 'Discussion',
		'slugdiv'          => 'Slug',
	];
	foreach ( $move as $id => $label ) {
		if ( remove_meta_box( $id, $post_type, 'normal' ) || remove_meta_box( $id, $post_type, 'advanced' ) ) {
			$cb = $id === 'authordiv' ? 'post_author_meta_box'
				: ( $id === 'pageparentdiv' ? 'page_attributes_meta_box'
				: ( $id === 'commentstatusdiv' ? 'post_comment_status_meta_box'
				: 'post_slug_meta_box' ) );
			add_meta_box( $id, $label, $cb, $post_type, 'side', 'low' );
		}
	}
}, 100, 2 );

// AJAX save — title + content for the current post, no page reload.
add_action( 'wp_ajax_therum_save_post', function() {
	check_ajax_referer( 'therum_save_post', 'nonce' );
	$post_id = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
	if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
		wp_send_json_error( [ 'message' => 'No permission' ], 403 );
	}
	$update = [ 'ID' => $post_id ];
	if ( isset( $_POST['title'] ) )   $update['post_title']   = sanitize_text_field( wp_unslash( $_POST['title'] ) );
	if ( isset( $_POST['content'] ) ) $update['post_content'] = wp_kses_post( wp_unslash( $_POST['content'] ) );
	$r = wp_update_post( $update, true );
	if ( is_wp_error( $r ) ) wp_send_json_error( [ 'message' => $r->get_error_message() ] );
	wp_send_json_success( [ 'id' => $r ] );
} );

// ─────────────────────────────────────────────────────────────────────────────
//  POST EDITOR SKIN — Therum-styled chrome, matching internal page conventions
// ─────────────────────────────────────────────────────────────────────────────
add_action( 'admin_head-post.php', 'therum_editor_skin' );
add_action( 'admin_head-post-new.php', 'therum_editor_skin' );
function therum_editor_skin() {
	$screen = get_current_screen();
	if ( ! $screen || $screen->base !== 'post' ) return;
	$path = __DIR__ . '/assets/therum-editor-skin.css';
	wp_enqueue_style( 'therum-editor-skin', plugins_url( 'assets/therum-editor-skin.css', __FILE__ ), [], file_exists( $path ) ? filemtime( $path ) : null );
}

// Hide help tabs on post screens
add_action( 'admin_head', function() {
	$screen = get_current_screen();
	if ( ! $screen || $screen->base !== 'post' ) return;
	$screen->remove_help_tabs();
} );

// ─────────────────────────────────────────────────────────────────────────────
//  USER + PROFILE + GENERIC FORM SCREENS — Therum chrome
// ─────────────────────────────────────────────────────────────────────────────
add_action( 'admin_head-user-new.php',        'therum_form_skin' );
add_action( 'admin_head-user-edit.php',       'therum_form_skin' );
add_action( 'admin_head-profile.php',         'therum_form_skin' );
add_action( 'admin_head-options-general.php', 'therum_form_skin' );
add_action( 'admin_head-edit-tags.php',       'therum_form_skin' );
add_action( 'admin_head-term.php',            'therum_form_skin' );
add_action( 'admin_head-nav-menus.php',       'therum_form_skin' );
add_action( 'admin_head-edit-comments.php',   'therum_form_skin' );
add_action( 'admin_head-comment.php',         'therum_form_skin' );
add_action( 'admin_head-tools.php',           'therum_form_skin' );
add_action( 'admin_head-export.php',          'therum_form_skin' );
add_action( 'admin_head-import.php',          'therum_form_skin' );
function therum_form_skin() {
	$path = __DIR__ . '/assets/therum-form-skin.css';
	wp_enqueue_style( 'therum-form-skin', plugins_url( 'assets/therum-form-skin.css', __FILE__ ), [], file_exists( $path ) ? filemtime( $path ) : null );
}

// ─────────────────────────────────────────────────────────────────────────────
//  EDITOR TOOLBAR JS — mode switch + AJAX save
// ─────────────────────────────────────────────────────────────────────────────
add_action( 'admin_footer-post.php',     'therum_editor_bar_js' );
add_action( 'admin_footer-post-new.php', 'therum_editor_bar_js' );
function therum_editor_bar_js() {
	$screen = get_current_screen();
	if ( ! $screen || $screen->base !== 'post' ) return;
	$path = __DIR__ . '/assets/therum-editor-bar.js';
	wp_enqueue_script( 'therum-editor-bar', plugins_url( 'assets/therum-editor-bar.js', __FILE__ ), [], file_exists( $path ) ? filemtime( $path ) : null, true );
}


// ════════════════════════════════════════════════════════════════════════════
//  PLUGIN SETTINGS EMBEDDING — from therum-plugin-settings.php
// ════════════════════════════════════════════════════════════════════════════


// ════════════════════════════════════════════════════════════════════════════
//  CASE-STUDY READING-MODE PILL — from therum-cs-modes.php
// ════════════════════════════════════════════════════════════════════════════
// Floating Simple/Detailed mode pill on every case_study singular page.
// JS in customScriptsBodyFooter wires the click handler, localStorage
// persistence, and the scroll-collapse behavior. CSS lives in
// therum-island.css under .therum-cs-modes.
//
// Pairs with the cs-utils column on the left side (theme + back-to-top).
// Same glass-pill aesthetic, mirrored to the right edge.
//
// Merged from therum-cs-modes.php (Phase 3 collapse, 2026-05-27).

add_action( 'wp_footer', function () {
	if ( is_admin() ) return;
	if ( ! is_singular( 'case_study' ) ) return;
	if ( function_exists( 'bricks_is_builder' ) && bricks_is_builder() ) return;
	?>
	<div class="therum-cs-modes" data-therum-cs-modes role="group" aria-label="Reading mode">
		<button class="therum-cs-modes__btn" type="button" data-cs-mode-btn="simple" aria-pressed="false" aria-label="Simple reading mode &mdash; overview only">
			<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><rect x="3" y="3" width="8" height="8" rx="1.4"/><rect x="13" y="3" width="8" height="8" rx="1.4"/><rect x="3" y="13" width="8" height="8" rx="1.4"/><rect x="13" y="13" width="8" height="8" rx="1.4"/></svg>
			<span class="therum-cs-modes__label">Simple</span>
		</button>
		<button class="therum-cs-modes__btn" type="button" data-cs-mode-btn="detailed" aria-pressed="true" aria-label="Detailed reading mode &mdash; full case study">
			<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><line x1="4" y1="6" x2="20" y2="6"/><line x1="4" y1="11" x2="20" y2="11"/><line x1="4" y1="16" x2="14" y2="16"/><line x1="4" y1="21" x2="20" y2="21"/></svg>
			<span class="therum-cs-modes__label">Detailed</span>
		</button>
	</div>
	<?php
}, 30 );
