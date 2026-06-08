<?php
/**
 * Therum OS — Therum_Media_Page
 *
 * Extracted from therum-admin.php as part of the 1.9.x split. Same
 * class, same behavior; required back in from therum-admin.php at the
 * original load position to preserve declaration order.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class Therum_Media_Page {

	public static function render(): void {
		$attachments = get_posts([
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'posts_per_page' => 200,
			'orderby'        => 'date',
			'order'          => 'DESC',
		]);

		$by_kind = ['image'=>0,'video'=>0,'audio'=>0,'doc'=>0];
		foreach ($attachments as $a) {
			$by_kind[self::mime_kind($a->post_mime_type)] = ($by_kind[self::mime_kind($a->post_mime_type)] ?? 0) + 1;
		}

		Therum_List_Page::render([
			'title'    => 'Media',
			'subtitle' => 'Images, video, audio and documents in the media library.',
			'page_id'  => 'media',
			'meta_pills' => [
				count($attachments) . ' file' . (count($attachments)===1?'':'s'),
				size_format(self::total_size($attachments)),
			],
			'action_buttons' => [
				['label'=>'Upload', 'icon'=>'import', 'primary'=>true, 'href'=>admin_url('admin.php?page=therum-media-upload')],
				['label'=>'Bulk rename', 'icon'=>'edit2', 'href'=>'#', 'attrs' => ['data-th-bulk-rename' => '1']],
				['label'=>'Regenerate thumbnails', 'icon'=>'media', 'href'=>'#', 'attrs' => ['data-th-regen-all' => '1']],
				['label'=>'Download library', 'icon'=>'export', 'href'=>wp_nonce_url( admin_url('admin-ajax.php?action=therum_media_download_zip'), 'therum_media_zip' )],
			],
			'density_slider'  => true,
			'density_default' => (int) therum_pref( 'media_density', 5 ),
			'view_default'    => (string) therum_pref( 'media_view_mode', 'grid' ),
			'extra_views'     => [
				[ 'key' => 'masonry', 'label' => 'Masonry view', 'svg' => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="9"/><rect x="14" y="3" width="7" height="5"/><rect x="14" y="12" width="7" height="9"/><rect x="3" y="16" width="7" height="5"/></svg>' ],
				[ 'key' => 'metro',   'label' => 'Metro tile view', 'svg' => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="11" height="11"/><rect x="16" y="3" width="5" height="5"/><rect x="16" y="10" width="5" height="11"/><rect x="3" y="16" width="11" height="5"/></svg>' ],
			],
			'filter_pills' => [
				['key'=>'all',   'label'=>'All',       'count'=>count($attachments)],
				['key'=>'image', 'label'=>'Images',    'count'=>$by_kind['image']],
				['key'=>'video', 'label'=>'Video',     'count'=>$by_kind['video']],
				['key'=>'audio', 'label'=>'Audio',     'count'=>$by_kind['audio']],
				['key'=>'doc',   'label'=>'Documents', 'count'=>$by_kind['doc']],
			],
			'sort_options' => [
				['key'=>'date-desc', 'label'=>'Newest first'],
				['key'=>'date-asc',  'label'=>'Oldest first'],
				['key'=>'title-asc', 'label'=>'Name A→Z'],
				['key'=>'title-desc','label'=>'Name Z→A'],
				['key'=>'size-desc', 'label'=>'Largest first'],
				['key'=>'size-asc',  'label'=>'Smallest first'],
			],
			'search_placeholder' => 'Search media…',
			'items'              => $attachments,
			'card_renderer'      => [self::class, 'render_card'],
			'row_renderer'       => [self::class, 'render_row'],
			'row_actions'        => [self::class, 'row_actions'],
			'table_columns'      => ['File', 'Type', 'Size', 'Uploaded'],
			'empty_state'        => ['title'=>'Media library is empty', 'sub'=>'Upload your first file to get started.'],
		]);
	}

	public static function row_actions(\WP_Post $a): array {
		$edit  = get_edit_post_link($a->ID);
		$url   = wp_get_attachment_url($a->ID);
		$delete= get_delete_post_link($a->ID, '', true);
		$file  = get_attached_file($a->ID);
		$basename = $file ? basename($file) : '';
		$out = [];
		if ($edit) $out[] = ['label' => 'Edit details', 'href' => $edit, 'icon' => 'edit2'];
		// Rename for SEO — opens the renamer modal (markup printed by therum-renamer.php
		// in the admin_footer of the Media list page).
		if ($basename) {
			$out[] = [
				'label'  => 'Rename for SEO',
				'button' => true,
				'icon'   => 'edit2',
				'data'   => [
					'th-rename'      => $a->ID,
					'th-rename-name' => $basename,
				],
			];
		}
		if ($url) {
			$out[] = ['label' => 'Download',  'href' => $url, 'icon' => 'import', 'download' => $basename ?: basename($url)];
			$out[] = ['label' => 'View file', 'href' => $url, 'icon' => 'external', 'target' => '_blank'];
			$out[] = ['label' => 'Copy URL',  'copy' => $url, 'icon' => 'export'];
		}
		// Regenerate thumbnails (images only)
		if ( wp_attachment_is_image( $a->ID ) ) {
			$out[] = [
				'label'  => 'Regenerate thumbnails',
				'button' => true,
				'icon'   => 'media',
				'data'   => [ 'th-regen' => (string) $a->ID ],
			];
		}
		if ($delete) $out[] = ['label' => 'Delete permanently', 'href' => $delete, 'icon' => 'logout', 'danger' => true];
		return $out;
	}

	private static function mime_kind(string $mime): string {
		if (str_starts_with($mime, 'image/')) return 'image';
		if (str_starts_with($mime, 'video/')) return 'video';
		if (str_starts_with($mime, 'audio/')) return 'audio';
		return 'doc';
	}

	private static function total_size(array $atts): int {
		$total = 0;
		foreach ($atts as $a) {
			$f = get_attached_file($a->ID);
			if ($f && file_exists($f)) $total += filesize($f);
		}
		return $total;
	}

	public static function render_card(\WP_Post $a): void {
		$kind = self::mime_kind((string)$a->post_mime_type);
		$thumb = wp_get_attachment_image_url($a->ID, 'medium') ?: '';
		$title = $a->post_title ?: basename((string)get_attached_file($a->ID));
		$size  = file_exists((string)get_attached_file($a->ID)) ? size_format(filesize((string)get_attached_file($a->ID))) : '';
		$date  = wp_date('M j', strtotime((string)$a->post_date));
		$thumb_style = $kind === 'image' && $thumb
			? "background-image:url('".esc_url($thumb)."');background-size:cover;background-position:center;"
			: 'background:var(--sf2);display:flex;align-items:center;justify-content:center;color:var(--tx3);font-size:28px;';
		?>
		<div class="th-lp-card th-lp-card-media"
		   data-status="<?php echo esc_attr($kind); ?>"
		   data-search="<?php echo esc_attr(strtolower($title)); ?>"
		   data-sort-date="<?php echo (int)get_post_time('U', true, $a); ?>"
		   data-sort-title="<?php echo esc_attr(strtolower($title)); ?>"
		   data-sort-size="<?php echo file_exists((string)get_attached_file($a->ID)) ? filesize((string)get_attached_file($a->ID)) : 0; ?>">
		  <a class="th-lp-card-link" href="<?php echo esc_url((string)get_edit_post_link($a->ID)); ?>" tabindex="0">
			<div class="th-lp-card-thumb th-lp-card-thumb-square" style="<?php echo $thumb_style; ?>">
			  <?php if ($kind !== 'image'): ?><span><?php echo strtoupper(substr($kind,0,3)); ?></span><?php endif; ?>
			</div>
			<div class="th-lp-card-meta">
			  <div class="th-lp-card-title"><?php echo esc_html($title); ?></div>
			  <div class="th-lp-card-sub"><?php echo esc_html($size . ($size?' · ':'') . $date); ?></div>
			</div>
		  </a>
		  <?php Therum_List_Page::render_card_kebab(self::row_actions($a)); ?>
		</div>
		<?php
	}

	public static function mime_icon(string $kind): string {
		$icons = [
			'video' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><polygon points="23 7 16 12 23 17 23 7"/><rect x="1" y="5" width="15" height="14" rx="2" ry="2"/></svg>',
			'audio' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M9 18V5l12-2v13"/><circle cx="6" cy="18" r="3"/><circle cx="18" cy="16" r="3"/></svg>',
			'document' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>',
			'archive' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><polyline points="21 8 21 21 3 21 3 8"/><rect x="1" y="3" width="22" height="5"/><line x1="10" y1="12" x2="14" y2="12"/></svg>',
			'image' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>',
			'other' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>',
		];
		return $icons[$kind] ?? $icons['other'];
	}

	public static function render_row(\WP_Post $a, ?array $actions = null): void {
		$kind = self::mime_kind((string)$a->post_mime_type);
		$title = $a->post_title ?: basename((string)get_attached_file($a->ID));
		$size  = file_exists((string)get_attached_file($a->ID)) ? size_format(filesize((string)get_attached_file($a->ID))) : '—';
		$date  = wp_date('M j, Y', strtotime((string)$a->post_date));
		?>
		<tr class="th-lp-row"
			data-status="<?php echo esc_attr($kind); ?>"
			data-search="<?php echo esc_attr(strtolower($title)); ?>"
			data-sort-date="<?php echo (int)get_post_time('U', true, $a); ?>"
			data-sort-title="<?php echo esc_attr(strtolower($title)); ?>">
		  <td>
			<a class="th-media-row-link" href="<?php echo esc_url((string)get_edit_post_link($a->ID)); ?>">
			  <?php
			  $thumb_url = '';
			  if ($kind === 'image') {
				  $thumb_url = wp_get_attachment_image_url($a->ID, [40, 40]) ?: wp_get_attachment_url($a->ID);
			  }
			  ?>
			  <?php if ($thumb_url): ?>
				<span class="th-media-row-thumb" style="background-image:url('<?php echo esc_url($thumb_url); ?>');"></span>
			  <?php else: ?>
				<span class="th-media-row-thumb th-media-row-thumb-icon th-media-row-thumb-<?php echo esc_attr($kind); ?>">
				  <?php echo self::mime_icon($kind); ?>
				</span>
			  <?php endif; ?>
			  <span class="th-media-row-title"><?php echo esc_html($title); ?></span>
			</a>
		  </td>
		  <td><?php echo esc_html(ucfirst($kind)); ?></td>
		  <td><?php echo esc_html($size); ?></td>
		  <td><?php echo esc_html($date); ?></td>
		  <?php if ($actions) Therum_List_Page::render_kebab_cell($actions); ?>
		</tr>
		<?php
	}
}
