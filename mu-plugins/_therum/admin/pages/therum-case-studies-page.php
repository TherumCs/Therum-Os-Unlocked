<?php
/**
 * Therum OS — Therum_Case_Studies_Page
 *
 * Extracted from therum-admin.php as part of the 1.9.x split. Same
 * class, same behavior; required back in from therum-admin.php at the
 * original load position to preserve declaration order.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class Therum_Case_Studies_Page {

	public static function render(): void {
		$posts = get_posts([
			'post_type'      => 'case_study',
			'post_status'    => ['publish','draft','pending','future','trash'],
			'posts_per_page' => THERUM_ADMIN_LIST_CAP,
			'orderby'        => 'modified',
			'order'          => 'DESC',
		]);

		$by_status = ['publish'=>0,'draft'=>0,'future'=>0,'trash'=>0];
		foreach ($posts as $p) $by_status[$p->post_status] = ($by_status[$p->post_status] ?? 0) + 1;

		$disciplines = get_terms(['taxonomy'=>'case_study_discipline','hide_empty'=>false]);
		$tags        = get_terms(['taxonomy'=>'case_study_tag','hide_empty'=>false]);

		Therum_List_Page::render([
			'title'    => 'Case Studies',
			'subtitle' => 'Portfolio entries — projects, write-ups, and shipped work.',
			'page_id'  => 'case-studies',
			'meta_pills' => [
				count($posts) . ' case stud' . (count($posts)===1?'y':'ies'),
				$by_status['draft'] . ' draft' . ($by_status['draft']===1?'':'s'),
				count($disciplines) . ' discipline' . (count($disciplines)===1?'':'s'),
				count($tags) . ' tag' . (count($tags)===1?'':'s'),
			],
			'action_buttons' => [
				therum_export_button( 'case_study' ),
				['label'=>'Categories', 'icon'=>'folder', 'href'=>admin_url('edit-tags.php?taxonomy=case_study_discipline&post_type=case_study')],
				['label'=>'Tags',       'icon'=>'tag',    'href'=>admin_url('edit-tags.php?taxonomy=case_study_tag&post_type=case_study')],
				['label'=>'New Case Study', 'icon'=>'plus', 'primary'=>true, 'href'=>admin_url('post-new.php?post_type=case_study')],
			],
			'filter_pills' => [
				['key'=>'all',     'label'=>'All',       'count'=>count($posts)],
				['key'=>'publish', 'label'=>'Published', 'count'=>$by_status['publish']],
				['key'=>'draft',   'label'=>'Drafts',    'count'=>$by_status['draft']],
				['key'=>'future',  'label'=>'Scheduled', 'count'=>$by_status['future']],
				['key'=>'trash',   'label'=>'Trash',     'count'=>$by_status['trash']],
			],
			'sort_options' => [
				['key'=>'modified-desc', 'label'=>'Recently modified'],
				['key'=>'modified-asc',  'label'=>'Oldest modified'],
				['key'=>'title-asc',     'label'=>'Title A→Z'],
				['key'=>'title-desc',    'label'=>'Title Z→A'],
			],
			'search_placeholder' => 'Search case studies…',
			'items'              => $posts,
			// Reuse Pages renderers — same card/row markup, same kebab, same picker.
			// Only the action labels differ (handled in row_actions below).
			'card_renderer'      => [self::class, 'render_card'],
			'row_renderer'       => [self::class, 'render_row'],
			'row_actions'        => [self::class, 'row_actions'],
			'table_columns'      => ['Title', 'Status', 'Words', 'Modified', 'Author'],
			'empty_state'        => ['title'=>'No case studies yet', 'sub'=>'Click "New Case Study" to create your first.'],
		]);
	}

	public static function row_actions(\WP_Post $p): array {
		// Same shape as Pages — Edit / View / Duplicate / Trash, with Restore +
		// permanent delete on trashed items. Centralizing here means future
		// portfolio-specific actions (e.g. "Set as featured") slot in one place.
		return Therum_Pages_Page::row_actions($p);
	}

	public static function render_card(\WP_Post $p): void {
		Therum_Pages_Page::render_card($p);
	}

	public static function render_row(\WP_Post $p, ?array $actions = null): void {
		Therum_Pages_Page::render_row($p, $actions);
	}
}
