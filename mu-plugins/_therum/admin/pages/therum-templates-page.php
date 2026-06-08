<?php
/**
 * Therum OS — Therum_Templates_Page
 *
 * Extracted from therum-admin.php as part of the 1.9.x split. Same
 * class, same behavior; required back in from therum-admin.php at the
 * original load position to preserve declaration order.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class Therum_Templates_Page {

	/* Verified from Bricks v2.3.1 source: bricks/includes/setup.php */
	const TYPE_LABELS = [
		'header'              => 'Header',
		'footer'              => 'Footer',
		'content'             => 'Single',
		'section'             => 'Section',
		'popup'               => 'Popup',
		'archive'             => 'Archive',
		'search'              => 'Search results',
		'error'               => 'Error page',
		'password_protection' => 'Password protection',
	];

	/* Status palette for the template type pill (left border + bg accent) */
	const TYPE_PALETTE = [
		'header'  => ['#3b82f6', 'rgba(59,130,246,0.10)'],
		'footer'  => ['#8b5cf6', 'rgba(139,92,246,0.10)'],
		'content' => ['#10b981', 'rgba(16,185,129,0.10)'],
		'section' => ['#f59e0b', 'rgba(245,158,11,0.10)'],
		'popup'   => ['#ec4899', 'rgba(236,72,153,0.10)'],
		'archive' => ['#06b6d4', 'rgba(6,182,212,0.10)'],
		'search'  => ['#6366f1', 'rgba(99,102,241,0.10)'],
		'error'   => ['#ef4444', 'rgba(239,68,68,0.10)'],
	];

	public static function render(): void {
		$templates = get_posts([
			'post_type'      => 'bricks_template',
			'post_status'    => ['publish','draft','pending','future'],
			'posts_per_page' => THERUM_ADMIN_LIST_CAP,
			'orderby'        => 'modified',
			'order'          => 'DESC',
		]);

		$by_type = [];
		foreach ($templates as $t) {
			$type = get_post_meta($t->ID, '_bricks_template_type', true) ?: 'section';
			$by_type[$type] = ($by_type[$type] ?? 0) + 1;
		}

		$site_host = parse_url(home_url(), PHP_URL_HOST);

		// Build filter pills dynamically from what's actually in the library
		$filter_pills = [
			['key' => 'all', 'label' => 'All', 'count' => count($templates)],
		];
		foreach (self::TYPE_LABELS as $type => $label) {
			if (!empty($by_type[$type])) {
				$filter_pills[] = ['key' => $type, 'label' => $label, 'count' => $by_type[$type]];
			}
		}

		Therum_List_Page::render([
			'title'    => 'Templates',
			'subtitle' => "Header, footer, archive, and section templates for $site_host.",
			'page_id'  => 'templates',
			'meta_pills' => [
				count($templates) . ' template' . (count($templates) === 1 ? '' : 's'),
			],
			'action_buttons' => [
				['label' => 'Import',  'icon' => 'import', 'href' => admin_url('edit.php?post_type=bricks_template')],
				['label' => 'New Template', 'icon' => 'plus', 'primary' => true, 'href' => admin_url('post-new.php?post_type=bricks_template')],
			],
			'filter_pills'       => $filter_pills,
			'sort_options'       => [
				['key' => 'modified-desc', 'label' => 'Recently modified'],
				['key' => 'modified-asc',  'label' => 'Oldest modified'],
				['key' => 'title-asc',     'label' => 'Title A→Z'],
				['key' => 'title-desc',    'label' => 'Title Z→A'],
			],
			'search_placeholder' => 'Search templates…',
			'items'              => $templates,
			'card_renderer'      => [self::class, 'render_card'],
			'row_renderer'       => [self::class, 'render_row'],
			'row_actions'        => [self::class, 'row_actions'],
			'table_columns'      => ['Title', 'Type', 'Status', 'Modified', 'Author'],
			'empty_state'        => ['title' => 'No templates yet', 'sub' => 'Click "New Template" to create your first.'],
		]);
	}

	public static function render_card(\WP_Post $t): void {
		$type        = get_post_meta($t->ID, '_bricks_template_type', true) ?: 'section';
		$type_label  = self::TYPE_LABELS[$type] ?? ucfirst($type);
		$status      = $t->post_status;
		$title       = $t->post_title ?: '(untitled)';
		$modified    = human_time_diff(get_post_modified_time('U', false, $t));
		$search      = strtolower($title . ' ' . $type_label);
		$layout      = Therum_Card_Style::layout($t);
		$status_label = self::status_label($status);
		$builder_url = self::builder_url($t->ID);
		$dup_url     = wp_nonce_url(admin_url('admin-post.php?action=therum_duplicate_template&post=' . $t->ID), 'therum_dup_' . $t->ID);

		// Soft uniform purple/blue gradient — published gets the accent tint,
		// drafts fade toward neutral. Matches the Therum demo aesthetic.
		$is_live = ($status === 'publish');
		$thumb_style = $is_live
			? 'background: linear-gradient(135deg, color-mix(in srgb, var(--ac) 22%, var(--sf2)) 0%, color-mix(in srgb, #8b5cf6 16%, var(--sf2)) 100%);'
			: 'background: linear-gradient(135deg, var(--sf2) 0%, color-mix(in srgb, var(--tx3) 14%, var(--sf2)) 100%);';
		?>
		<div class="th-lp-card th-lp-card-layout-<?php echo esc_attr($layout); ?>"
		   data-status="<?php echo esc_attr($type); ?>"
		   data-post-status="<?php echo esc_attr($status); ?>"
		   data-template-type="<?php echo esc_attr($type); ?>"
		   data-search="<?php echo esc_attr($search); ?>"
		   data-sort-modified="<?php echo (int) get_post_modified_time('U', true, $t); ?>"
		   data-sort-title="<?php echo esc_attr(strtolower($title)); ?>"
		   data-edit-href="<?php echo esc_url($builder_url); ?>">
		  <a class="th-lp-card-link" href="<?php echo esc_url($builder_url); ?>" tabindex="0">
			<div class="th-lp-card-thumb" style="<?php echo esc_attr($thumb_style); ?>">
			  <div class="th-lp-tpl-type-badge"><?php echo esc_html(strtoupper($type_label)); ?></div>
			</div>
			<div class="th-lp-card-meta">
			  <div class="th-lp-card-title-row">
				<div class="th-lp-card-title"><?php echo esc_html($title); ?></div>
				<span class="th-lp-card-status-pill th-lp-card-status-<?php echo esc_attr($status); ?>">
				  <span class="dot"></span><?php echo esc_html($status_label); ?>
				</span>
			  </div>
			  <div class="th-lp-card-sub"><?php echo esc_html($type_label); ?> · Bricks · <?php echo esc_html($modified); ?> ago</div>
			</div>
		  </a>
		  <div class="th-lp-card-actions">
			<a class="th-lp-card-btn th-lp-card-btn-primary" href="<?php echo esc_url($builder_url); ?>">Edit</a>
			<a class="th-lp-card-btn" href="<?php echo esc_url($dup_url); ?>">Duplicate</a>
		  </div>
		  <?php Therum_List_Page::render_card_kebab(self::row_actions($t)); ?>
		</div>
		<?php
	}

	public static function render_row(\WP_Post $t, ?array $actions = null): void {
		$type     = get_post_meta($t->ID, '_bricks_template_type', true) ?: 'section';
		$type_label = self::TYPE_LABELS[$type] ?? ucfirst($type);
		$status   = $t->post_status;
		$title    = $t->post_title ?: '(untitled)';
		$modified = human_time_diff(get_post_modified_time('U', false, $t));
		$author   = get_the_author_meta('display_name', $t->post_author) ?: '—';
		$search   = strtolower($title . ' ' . $type_label);
		$builder_url = self::builder_url($t->ID);
		$palette  = self::TYPE_PALETTE[$type] ?? ['#999', 'rgba(0,0,0,0.06)'];
		?>
		<tr class="th-lp-row"
			data-status="<?php echo esc_attr($type); ?>"
			data-post-status="<?php echo esc_attr($status); ?>"
			data-template-type="<?php echo esc_attr($type); ?>"
			data-search="<?php echo esc_attr($search); ?>"
			data-sort-modified="<?php echo (int)get_post_modified_time('U', true, $t); ?>"
			data-sort-title="<?php echo esc_attr(strtolower($title)); ?>">
		  <td><a href="<?php echo esc_url($builder_url); ?>"><?php echo esc_html($title); ?></a></td>
		  <td><span class="th-lp-tpl-type" style="color:<?php echo esc_attr($palette[0]); ?>;background:<?php echo esc_attr($palette[1]); ?>;"><?php echo esc_html($type_label); ?></span></td>
		  <td><span class="th-lp-status th-lp-status-<?php echo esc_attr($status); ?>"><?php echo esc_html(self::status_label($status)); ?></span></td>
		  <td><?php echo esc_html($modified); ?> ago</td>
		  <td><?php echo esc_html($author); ?></td>
		  <?php if ($actions): Therum_List_Page::render_kebab_cell($actions); endif; ?>
		</tr>
		<?php
	}

	public static function row_actions(\WP_Post $t): array {
		$builder = self::builder_url($t->ID);
		$delete  = get_delete_post_link($t->ID);
		$dup     = wp_nonce_url(admin_url('admin-post.php?action=therum_duplicate_template&post=' . $t->ID), 'therum_dup_' . $t->ID);
		$out = [];
		if ($builder) $out[] = ['label' => 'Edit with Bricks', 'href' => $builder, 'icon' => 'edit2'];
		if ($dup) $out[] = ['label' => 'Duplicate', 'href' => $dup, 'icon' => 'plus'];
		if ($delete) $out[] = ['label' => 'Delete', 'href' => $delete, 'icon' => 'logout', 'danger' => true];
		return $out;
	}

	private static function status_label(string $s): string {
		return ['publish'=>'Published','draft'=>'Draft','future'=>'Scheduled','pending'=>'Pending','trash'=>'Trash'][$s] ?? ucfirst($s);
	}

	/* Build the Bricks builder URL: permalink + ?bricks=run (verified from Bricks source) */
	private static function builder_url(int $post_id): string {
		$permalink = get_permalink($post_id);
		if (!$permalink) return (string) get_edit_post_link($post_id);
		return add_query_arg('bricks', 'run', $permalink);
	}

	private static function darken(string $hex): string {
		$h = ltrim($hex, '#');
		if (strlen($h) === 3) $h = $h[0].$h[0].$h[1].$h[1].$h[2].$h[2];
		if (strlen($h) !== 6) return $hex;
		$r = max(0, hexdec(substr($h,0,2)) - 60);
		$g = max(0, hexdec(substr($h,2,2)) - 60);
		$b = max(0, hexdec(substr($h,4,2)) - 60);
		return sprintf('#%02x%02x%02x', $r, $g, $b);
	}
}
