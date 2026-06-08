<?php
/**
 * Therum OS — Therum_Users_Page
 *
 * Extracted from therum-admin.php as part of the 1.9.x split. Same
 * class, same behavior; required back in from therum-admin.php at the
 * original load position to preserve declaration order.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class Therum_Users_Page {

	public static function render(): void {
		$users = get_users(['number' => 200]);

		$by_role = [];
		foreach ($users as $u) {
			$role = $u->roles[0] ?? 'subscriber';
			$by_role[$role] = ($by_role[$role] ?? 0) + 1;
		}

		$pills = [
			['key'=>'all', 'label'=>'All', 'count'=>count($users)],
		];
		foreach (['administrator'=>'Admins','editor'=>'Editors','author'=>'Authors','contributor'=>'Contributors','subscriber'=>'Subscribers'] as $r=>$lbl) {
			if (($by_role[$r] ?? 0) > 0) {
				$pills[] = ['key'=>$r, 'label'=>$lbl, 'count'=>$by_role[$r]];
			}
		}

		Therum_List_Page::render([
			'title'    => 'Users',
			'subtitle' => 'People with accounts on this site.',
			'page_id'  => 'users',
			'meta_pills' => [
				count($users) . ' user' . (count($users)===1?'':'s'),
				($by_role['administrator'] ?? 0) . ' admin' . (($by_role['administrator'] ?? 0)===1?'':'s'),
			],
			'action_buttons' => [
				['label'=>'Add User', 'icon'=>'plus', 'primary'=>true, 'href'=>admin_url('user-new.php')],
			],
			'filter_pills' => $pills,
			'sort_options' => [
				['key'=>'date-desc',  'label'=>'Recently joined'],
				['key'=>'date-asc',   'label'=>'Earliest joined'],
				['key'=>'title-asc',  'label'=>'Name A→Z'],
				['key'=>'title-desc', 'label'=>'Name Z→A'],
				['key'=>'posts-desc', 'label'=>'Most posts'],
			],
			'search_placeholder' => 'Search users…',
			'items'              => $users,
			'card_renderer'      => [self::class, 'render_card'],
			'row_renderer'       => [self::class, 'render_row'],
			'row_actions'        => [self::class, 'row_actions'],
			'table_columns'      => ['User', 'Role', 'Posts', 'Joined', 'Email'],
			'empty_state'        => ['title'=>'No users yet', 'sub'=>'Invite teammates to get started.'],
		]);
	}

	public static function row_actions(\WP_User $u): array {
		$edit = admin_url('user-edit.php?user_id=' . $u->ID);
		$out = [
			['label' => 'Edit profile', 'href' => $edit, 'icon' => 'edit2'],
			['label' => 'Email', 'href' => 'mailto:' . $u->user_email, 'icon' => 'external'],
		];
		if (current_user_can('delete_users') && $u->ID !== get_current_user_id()) {
			$delete = wp_nonce_url(admin_url('users.php?action=delete&user=' . $u->ID), 'bulk-users');
			$out[] = ['label' => 'Delete user', 'href' => $delete, 'icon' => 'logout', 'danger' => true];
		}
		return $out;
	}

	public static function render_card(\WP_User $u): void {
		$role = $u->roles[0] ?? 'subscriber';
		$avatar = get_avatar_url($u->ID, ['size'=>96]);
		$posts = (int) count_user_posts($u->ID);
		$joined = wp_date('M Y', strtotime((string)$u->user_registered));
		$search = strtolower($u->display_name . ' ' . $u->user_email . ' ' . $u->user_login);
		?>
		<div class="th-lp-card th-lp-card-user"
		   data-status="<?php echo esc_attr($role); ?>"
		   data-search="<?php echo esc_attr($search); ?>"
		   data-sort-date="<?php echo (int)strtotime((string)$u->user_registered); ?>"
		   data-sort-title="<?php echo esc_attr(strtolower($u->display_name)); ?>"
		   data-sort-posts="<?php echo $posts; ?>">
		  <a class="th-lp-card-link" href="<?php echo esc_url(admin_url('user-edit.php?user_id=' . $u->ID)); ?>" tabindex="0">
			<div class="th-lp-user-avatar"><img src="<?php echo esc_url($avatar); ?>" alt="" /></div>
			<div class="th-lp-card-meta">
			  <div class="th-lp-card-title"><?php echo esc_html($u->display_name); ?></div>
			  <div class="th-lp-card-sub"><span class="th-lp-role-pill th-lp-role-<?php echo esc_attr($role); ?>"><?php echo esc_html(ucfirst($role)); ?></span></div>
			  <div class="th-lp-card-sub"><?php echo $posts; ?> post<?php echo $posts===1?'':'s'; ?> · joined <?php echo esc_html($joined); ?></div>
			</div>
		  </a>
		  <?php Therum_List_Page::render_card_kebab(self::row_actions($u)); ?>
		</div>
		<?php
	}

	public static function render_row(\WP_User $u, ?array $actions = null): void {
		$role = $u->roles[0] ?? 'subscriber';
		$posts = (int) count_user_posts($u->ID);
		$joined = wp_date('M j, Y', strtotime((string)$u->user_registered));
		?>
		<tr class="th-lp-row"
			data-status="<?php echo esc_attr($role); ?>"
			data-search="<?php echo esc_attr(strtolower($u->display_name . ' ' . $u->user_email)); ?>"
			data-sort-date="<?php echo (int)strtotime((string)$u->user_registered); ?>"
			data-sort-title="<?php echo esc_attr(strtolower($u->display_name)); ?>"
			data-sort-posts="<?php echo $posts; ?>">
		  <td><a href="<?php echo esc_url(admin_url('user-edit.php?user_id=' . $u->ID)); ?>"><?php echo esc_html($u->display_name); ?></a></td>
		  <td><span class="th-lp-role-pill th-lp-role-<?php echo esc_attr($role); ?>"><?php echo esc_html(ucfirst($role)); ?></span></td>
		  <td><?php echo $posts; ?></td>
		  <td><?php echo esc_html($joined); ?></td>
		  <td><?php echo esc_html($u->user_email); ?></td>
		  <?php if ($actions) Therum_List_Page::render_kebab_cell($actions); ?>
		</tr>
		<?php
	}
}
