<?php
/**
 * Therum OS — Therum_Posts_Page
 *
 * Extracted from therum-admin.php as part of the 1.9.x split. Same
 * class, same behavior; required back in from therum-admin.php at the
 * original load position to preserve declaration order.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class Therum_Posts_Page {

	public static function render(): void {
		$posts = get_posts([
			'post_type'      => 'post',
			'post_status'    => ['publish','draft','pending','future','trash'],
			'posts_per_page' => THERUM_ADMIN_LIST_CAP,
			'orderby'        => 'date',
			'order'          => 'DESC',
		]);

		$by_status = ['publish'=>0,'draft'=>0,'future'=>0,'trash'=>0];
		foreach ($posts as $p) $by_status[$p->post_status] = ($by_status[$p->post_status] ?? 0) + 1;

		$cats = get_categories(['hide_empty'=>false]);

		$site_host = parse_url(home_url(), PHP_URL_HOST);

		Therum_List_Page::render([
			'title'    => 'Posts',
			'subtitle' => "Blog posts and articles on $site_host.",
			'page_id'  => 'posts',
			'meta_pills' => [
				count($posts) . ' post' . (count($posts)===1?'':'s'),
				$by_status['draft'] . ' draft' . ($by_status['draft']===1?'':'s'),
			],
			'action_buttons' => [
				therum_export_button( 'post' ),
				['label'=>'Categories', 'icon'=>'cats', 'href'=>admin_url('edit-tags.php?taxonomy=category')],
				['label'=>'New Post', 'icon'=>'plus', 'primary'=>true, 'href'=>admin_url('post-new.php')],
			],
			'filter_pills' => [
				['key'=>'all',     'label'=>'All',       'count'=>count($posts)],
				['key'=>'publish', 'label'=>'Published', 'count'=>$by_status['publish']],
				['key'=>'draft',   'label'=>'Drafts',    'count'=>$by_status['draft']],
				['key'=>'future',  'label'=>'Scheduled', 'count'=>$by_status['future']],
				['key'=>'trash',   'label'=>'Trash',     'count'=>$by_status['trash']],
			],
			'sort_options' => [
				['key'=>'date-desc',     'label'=>'Recently published'],
				['key'=>'date-asc',      'label'=>'Oldest first'],
				['key'=>'title-asc',     'label'=>'Title A→Z'],
				['key'=>'title-desc',    'label'=>'Title Z→A'],
				['key'=>'comments-desc', 'label'=>'Most comments'],
				['key'=>'words-desc',    'label'=>'Most words'],
			],
			'search_placeholder' => 'Search posts…',
			'items'              => $posts,
			'card_renderer'      => [self::class, 'render_card'],
			'row_renderer'       => [self::class, 'render_row'],
			'row_actions'        => [self::class, 'row_actions'],
			'table_columns'      => ['Title', 'Status', 'Comments', 'Date', 'Author'],
			'empty_state'        => ['title'=>'No posts yet', 'sub'=>'Click "New Post" to publish your first.'],
		]);
	}

	public static function row_actions(\WP_Post $p): array {
		$edit   = get_edit_post_link($p->ID);
		$view   = get_permalink($p->ID);
		$delete = get_delete_post_link($p->ID);
		$dup    = wp_nonce_url(admin_url('admin-post.php?action=therum_duplicate_post&post=' . $p->ID), 'therum_dup_' . $p->ID);
		if ($p->post_status === 'trash') {
			$restore = wp_nonce_url(admin_url('post.php?action=untrash&post=' . $p->ID), 'untrash-post_' . $p->ID);
			$perm_delete = get_delete_post_link($p->ID, '', true);
			$out = [];
			if ($restore) $out[] = ['label' => 'Restore', 'href' => $restore, 'icon' => 'edit2'];
			if ($perm_delete) $out[] = ['label' => 'Delete permanently', 'href' => $perm_delete, 'icon' => 'logout', 'danger' => true];
			return $out;
		}
		$out = [];
		if ($edit) $out[] = ['label' => 'Edit', 'href' => $edit, 'icon' => 'edit2'];
		if ($view) $out[] = ['label' => 'View', 'href' => $view, 'icon' => 'external'];
		if ($dup) $out[] = ['label' => 'Duplicate', 'href' => $dup, 'icon' => 'plus'];
		if ($delete) $out[] = ['label' => 'Move to Trash', 'href' => $delete, 'icon' => 'logout', 'danger' => true];
		return $out;
	}

	public static function render_card(\WP_Post $p): void {
		$status   = $p->post_status;
		$title    = $p->post_title ?: '(untitled)';
		$excerpt  = wp_trim_words(wp_strip_all_tags((string)$p->post_content), 24);
		$words    = function_exists('th_post_word_count') ? th_post_word_count($p) : str_word_count(wp_strip_all_tags((string)$p->post_content));
		$date     = human_time_diff(strtotime((string)$p->post_date));
		$comments = (int)$p->comment_count;
		$cats     = wp_get_post_categories($p->ID, ['fields'=>'names']);
		$cat      = $cats[0] ?? '';
		$thumb_style = Therum_Card_Style::thumb_style($p);
		$layout      = Therum_Card_Style::layout($p);
		$status_label = ['publish'=>'Published','draft'=>'Draft','future'=>'Scheduled','pending'=>'Pending','trash'=>'Trash'][$status] ?? ucfirst($status);
		$tag_html = '<span class="th-lp-card-tag"><span class="th-lp-tag-dot th-lp-tag-' . esc_attr($status) . '"></span>' . esc_html($status_label) . '</span>';
		$bricks_url   = function_exists('therum_bricks_builder_url') ? therum_bricks_builder_url($p) : '';
		$edit_url     = $bricks_url ?: (string)get_edit_post_link($p->ID);
		?>
		<div class="th-lp-card th-lp-card-layout-<?php echo esc_attr($layout); ?>"
		   data-status="<?php echo esc_attr($status); ?>"
		   data-category="<?php echo esc_attr(sanitize_title($cat)); ?>"
		   data-search="<?php echo esc_attr(strtolower($title.' '.$excerpt.' '.$cat)); ?>"
		   data-sort-date="<?php echo (int)get_post_time('U', true, $p); ?>"
		   data-sort-title="<?php echo esc_attr(strtolower($title)); ?>"
		   data-sort-comments="<?php echo $comments; ?>"
		   data-sort-words="<?php echo str_word_count($excerpt); ?>"
		   data-edit-href="<?php echo esc_url($edit_url); ?>">
		  <?php if ($layout === 'compact'):
			$mod_ago = human_time_diff(get_post_modified_time('U', false, $p));
			$read_min = max(1, (int)ceil($words / 200));
		  ?>
		  <a class="th-lp-card-link" href="<?php echo esc_url($edit_url); ?>" tabindex="0">
			<div class="th-lp-card-thumb th-lp-card-cmp-thumb" style="<?php echo $thumb_style; ?>"></div>
			<div class="th-lp-card-cmp-title"><?php echo esc_html($title); ?></div>
			<div class="th-lp-card-cmp-tag">#<?php echo esc_html(strtolower($status_label)); ?></div>
			<div class="th-lp-card-cmp-cell">
			  <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
			  <span><?php echo number_format_i18n((int)$words); ?> words</span>
			</div>
			<div class="th-lp-card-cmp-cell">
			  <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
			  <span><?php echo (int)$read_min; ?> min</span>
			</div>
			<span class="th-lp-card-cmp-dot th-lp-tag-<?php echo esc_attr($status); ?>" title="<?php echo esc_attr($status_label); ?>"></span>
		  </a>
		  <?php elseif ($layout === 'compact-v1'):
			$mod_ago = human_time_diff(get_post_modified_time('U', false, $p));
		  ?>
		  <a class="th-lp-card-link" href="<?php echo esc_url($edit_url); ?>" tabindex="0">
			<div class="th-lp-card-thumb th-lp-card-cv1-thumb" style="<?php echo $thumb_style; ?>"></div>
			<div class="th-lp-card-meta">
			  <div class="th-lp-card-title"><?php echo esc_html($title); ?></div>
			  <div class="th-lp-card-cv1-sub"><?php echo esc_html($status_label); ?> · <?php echo esc_html($mod_ago); ?> ago</div>
			</div>
		  </a>
		  <?php elseif ($layout === 'compact-v2'):
			$author_id = $p->post_author;
			$author_name = get_the_author_meta('display_name', $author_id) ?: '—';
			$mod_date = wp_date('M j, Y', (int)get_post_modified_time('U', true, $p));
			$read_min = max(1, (int)ceil($words / 200));
		  ?>
		  <a class="th-lp-card-link" href="<?php echo esc_url($edit_url); ?>" tabindex="0">
			<div class="th-lp-card-thumb th-lp-card-cv2-thumb" style="<?php echo $thumb_style; ?>"></div>
			<div class="th-lp-card-meta">
			  <div class="th-lp-card-cv2-date"><?php echo esc_html($mod_date); ?></div>
			  <div class="th-lp-card-title"><?php echo esc_html($title); ?></div>
			  <div class="th-lp-card-cv2-author"><?php echo esc_html($author_name); ?></div>
			  <div class="th-lp-card-cv2-meta">
				<span class="th-lp-card-cv2-meta-item"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg><?php echo esc_html($status_label); ?></span>
				<span class="th-lp-card-cv2-meta-item"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg><?php echo (int)$read_min; ?> min</span>
			  </div>
			</div>
		  </a>
		  <?php elseif ($layout === 'card-v1'): ?>
		  <a class="th-lp-card-link" href="<?php echo esc_url($edit_url); ?>" tabindex="0">
			<div class="th-lp-card-thumb" style="<?php echo $thumb_style; ?>">
			  <?php echo $tag_html; ?>
			  <div class="th-lp-card-v1-overlay">
				<div class="th-lp-card-title"><?php echo esc_html($title); ?></div>
				<?php if ($excerpt): ?>
				<div class="th-lp-card-excerpt"><?php echo esc_html(wp_trim_words($excerpt, 22, '…')); ?></div>
				<?php endif; ?>
				<div class="th-lp-card-v1-cta">Edit <span class="th-lp-card-v1-arrow">→</span></div>
			  </div>
			</div>
		  </a>
		  <?php elseif ($layout === 'card-v2'):
			$author_id = $p->post_author;
			$author_name = get_the_author_meta('display_name', $author_id) ?: '—';
			$author_avatar = get_avatar_url($author_id, ['size'=>56]);
			$mod_ago = human_time_diff(get_post_modified_time('U', false, $p));
		  ?>
		  <a class="th-lp-card-link" href="<?php echo esc_url($edit_url); ?>" tabindex="0">
			<div class="th-lp-card-v2-img">
			  <div class="th-lp-card-thumb" style="<?php echo $thumb_style; ?>"></div>
			  <?php echo $tag_html; ?>
			  <div class="th-lp-card-v2-img-actions">
				<a class="th-lp-card-v2-iconbtn" href="<?php echo esc_url((string)get_permalink($p->ID)); ?>" target="_blank" rel="noopener" title="View live" onclick="event.stopPropagation();">
				  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
				</a>
				<span class="th-lp-card-v2-iconbtn">
				  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21l-7-5-7 5V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2z"/></svg>
				</span>
			  </div>
			</div>
			<div class="th-lp-card-meta">
			  <div class="th-lp-card-v2-headrow">
				<div class="th-lp-card-v2-primary"><span class="th-lp-card-v2-primary-num"><?php echo (int)$words; ?></span><span class="th-lp-card-v2-primary-unit">/ words</span></div>
				<div class="th-lp-card-v2-rating"><svg width="13" height="13" viewBox="0 0 24 24" fill="#f59e0b" stroke="#f59e0b" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg> <?php echo esc_html($mod_ago); ?></div>
			  </div>
			  <div class="th-lp-card-title"><?php echo esc_html($title); ?></div>
			  <?php if ($excerpt): ?>
			  <div class="th-lp-card-excerpt"><?php echo esc_html(wp_trim_words($excerpt, 14, '…')); ?></div>
			  <?php endif; ?>
			  <div class="th-lp-card-v2-features">
				<span class="th-lp-card-v2-feature"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg><?php echo (int)$words; ?> words</span>
				<span class="th-lp-card-v2-feature"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg><?php echo esc_html($mod_ago); ?></span>
				<span class="th-lp-card-v2-feature"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg><?php echo esc_html($author_name); ?></span>
			  </div>
			</div>
		  </a>
		  <div class="th-lp-card-v2-foot">
			<div class="th-lp-card-v2-author">
			  <img class="th-lp-card-v2-avatar" src="<?php echo esc_url((string)$author_avatar); ?>" alt="" />
			  <div class="th-lp-card-v2-author-info">
				<div class="th-lp-card-v2-author-name"><?php echo esc_html($author_name); ?></div>
				<div class="th-lp-card-v2-author-role">Author</div>
			  </div>
			</div>
			<a class="th-lp-card-v2-foot-cta" href="<?php echo esc_url($edit_url); ?>" title="Edit">
			  <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4z"/></svg>
			</a>
		  </div>
		  <?php elseif ($layout === 'magazine'):
			$mod_date = wp_date('M j, Y', (int)get_post_modified_time('U', true, $p));
			$ptype_obj = get_post_type_object($p->post_type);
			$ptype_label = $ptype_obj ? ($ptype_obj->labels->singular_name ?: $p->post_type) : $p->post_type;
		  ?>
		  <a class="th-lp-card-link" href="<?php echo esc_url($edit_url); ?>" tabindex="0">
			<div class="th-lp-card-mag-top">
			  <span class="th-lp-card-mag-meta-l"><?php echo esc_html($ptype_label); ?></span>
			  <span class="th-lp-card-mag-meta-r"><?php echo esc_html($status_label); ?> · <?php echo esc_html($mod_date); ?></span>
			</div>
			<h2 class="th-lp-card-title th-lp-card-mag-title"><?php echo esc_html($title); ?></h2>
			<div class="th-lp-card-mag-body">
			  <div class="th-lp-card-mag-text">
				<div class="th-lp-card-mag-rule"></div>
				<div class="th-lp-card-mag-label"><?php echo (int)$words; ?> words · <?php echo esc_html(human_time_diff(get_post_modified_time('U', false, $p))); ?> ago</div>
				<?php if ($excerpt): ?>
				<div class="th-lp-card-excerpt"><?php echo esc_html(wp_trim_words($excerpt, 32, '…')); ?></div>
				<?php endif; ?>
				<span class="th-lp-card-mag-cta">Edit Page <span class="th-lp-card-mag-cta-arrow">→</span></span>
			  </div>
			  <div class="th-lp-card-thumb th-lp-card-mag-thumb" style="<?php echo $thumb_style; ?>"></div>
			</div>
		  </a>
		  <?php else: ?>
		  <a class="th-lp-card-link" href="<?php echo esc_url((string)get_permalink($p->ID)); ?>" target="_blank" tabindex="0">
			<div class="th-lp-card-thumb" style="<?php echo $thumb_style; ?>">
			  <?php if ($layout !== 'compact' && $layout !== 'hero'): ?>
				<?php echo $tag_html; ?>
			  <?php endif; ?>
			</div>
			<div class="th-lp-card-meta">
			  <div class="th-lp-card-title"><?php echo esc_html($title); ?></div>
			  <?php if (in_array($layout, ['card','magazine','compact','hero']) && $excerpt): ?>
			  <div class="th-lp-card-excerpt"><?php echo esc_html($excerpt); ?></div>
			  <?php endif; ?>
			  <?php if ($layout === 'hero'): ?>
			  <div class="th-lp-card-pills">
				<?php echo $tag_html; ?>
				<span class="th-lp-card-pill"><?php echo (int)$words; ?> words</span>
				<span class="th-lp-card-pill"><?php echo esc_html(human_time_diff(get_post_modified_time('U', false, $p))); ?> ago</span>
			  </div>
			  <?php else: ?>
			  <div class="th-lp-card-sub">
				<span><?php echo (int)$words; ?> words</span>
			  </div>
			  <?php endif; ?>
			</div>
		  </a>
		  <div class="th-lp-card-actions">
			<a class="th-lp-card-btn" href="<?php echo esc_url((string)get_permalink($p->ID)); ?>" target="_blank">View Live</a>
			<a class="th-lp-card-btn th-lp-card-btn-primary" href="<?php echo esc_url($edit_url); ?>">Edit Page</a>
		  </div>
		  <?php endif; ?>
		  <?php Therum_List_Page::render_card_kebab(self::row_actions($p)); ?>
		</div>
		<?php
	}

	public static function render_row(\WP_Post $p, ?array $actions = null): void {
		$status   = $p->post_status;
		$title    = $p->post_title ?: '(untitled)';
		$date     = wp_date('M j, Y', strtotime((string)$p->post_date));
		$comments = (int)$p->comment_count;
		$author   = get_the_author_meta('display_name', $p->post_author) ?: '—';
		?>
		<tr class="th-lp-row"
			data-status="<?php echo esc_attr($status); ?>"
			data-search="<?php echo esc_attr(strtolower($title)); ?>"
			data-sort-date="<?php echo (int)get_post_time('U', true, $p); ?>"
			data-sort-title="<?php echo esc_attr(strtolower($title)); ?>"
			data-sort-comments="<?php echo $comments; ?>">
		  <td><a href="<?php echo esc_url((string)get_edit_post_link($p->ID)); ?>"><?php echo esc_html($title); ?></a></td>
		  <td><span class="th-lp-status th-lp-status-<?php echo esc_attr($status); ?>"><?php echo esc_html(['publish'=>'Published','draft'=>'Draft','future'=>'Scheduled','pending'=>'Pending','trash'=>'Trash'][$status] ?? ucfirst($status)); ?></span></td>
		  <td><?php echo $comments; ?></td>
		  <td><?php echo esc_html($date); ?></td>
		  <td><?php echo esc_html($author); ?></td>
		  <?php if ($actions) Therum_List_Page::render_kebab_cell($actions); ?>
		</tr>
		<?php
	}
}
