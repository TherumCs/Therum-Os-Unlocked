<?php
/**
 * Therum OS · Media — NeoRename (native batch file renamer)
 *
 * Renames media library files on disk, regenerates attachment metadata,
 * and rewrites all post_content references. No plugin dependencies.
 *
 * @package    Therum OS
 * @category   Core
 * @version    1.8.8
 * @license    Proprietary
 */

defined( 'ABSPATH' ) || exit;

// ── Kill switch ──────────────────────────────────────────────────────────────
if ( ! defined( 'THERUM_MEDIA' ) ) define( 'THERUM_MEDIA', true );
if ( ! THERUM_MEDIA ) return;

/**
 * Filter-gated module switch.
 * Override any sub-module via add_filter:
 *   add_filter( 'therum_media_on_batch_rename', '__return_false' );
 */
function thm_on( string $module, bool $default = true ): bool {
    return (bool) apply_filters( 'therum_media_on_' . $module, $default );
}

// ── AJAX hooks ───────────────────────────────────────────────────────────────
add_action( 'wp_ajax_therum_media_rename',         'thm_ajax_rename' );
add_action( 'wp_ajax_therum_media_rename_preview', 'thm_ajax_rename_preview' );
add_action( 'wp_ajax_therum_media_rename_single',  'thm_ajax_rename_single' );

// ── Admin column + inline action ─────────────────────────────────────────────
add_filter( 'manage_media_columns',       'thm_media_columns' );
add_filter( 'manage_media_custom_column', 'thm_media_column_content', 10, 2 );
add_action( 'admin_head',                 'thm_admin_styles' );
add_action( 'admin_footer',               'thm_admin_scripts' );

// ── Bulk action registration ─────────────────────────────────────────────────
add_filter( 'bulk_actions-upload',        'thm_bulk_actions' );
add_filter( 'handle_bulk_actions-upload', 'thm_handle_bulk_rename', 10, 3 );
add_action( 'admin_notices',              'thm_bulk_rename_notice' );

// ── Status filter for Performance dashboard ───────────────────────────────────
add_filter( 'therum_media_status_rows', function ( array $rows ): array {
    $rows[] = [
        'label'  => 'NeoRename',
        'status' => thm_on( 'batch_rename' ) ? 'active' : 'inactive',
        'detail' => thm_on( 'batch_rename' )
            ? 'Batch rename + single-file rename enabled'
            : 'Disabled via therum_media_on_batch_rename filter',
    ];
    return $rows;
} );

// ═══════════════════════════════════════════════════════════════════════════════
// Core rename logic
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Rename a single attachment on disk, update metadata, rewrite post_content refs.
 *
 * @param  int    $attachment_id  WP attachment post ID.
 * @param  string $new_basename   New filename without extension (raw, will be sanitised).
 * @return array{success: bool, old_file: string, new_file: string, message: string}
 */
function thm_rename_attachment( int $attachment_id, string $new_basename ): array {
    if ( ! thm_on( 'batch_rename' ) ) {
        return [ 'success' => false, 'message' => 'NeoRename is disabled.' ];
    }

    $new_basename = pathinfo( sanitize_file_name( $new_basename ), PATHINFO_FILENAME );
    if ( empty( $new_basename ) ) {
        return [ 'success' => false, 'message' => 'New filename is empty after sanitisation.' ];
    }

    $old_file = get_attached_file( $attachment_id );
    if ( ! $old_file || ! file_exists( $old_file ) ) {
        return [ 'success' => false, 'message' => 'Attachment file not found on disk.' ];
    }

    $dir      = trailingslashit( dirname( $old_file ) );
    $old_ext  = '.' . strtolower( pathinfo( $old_file, PATHINFO_EXTENSION ) );
    $new_file = $dir . $new_basename . $old_ext;

    if ( file_exists( $new_file ) && $new_file !== $old_file ) {
        return [ 'success' => false, 'message' => 'A file with that name already exists.' ];
    }

    if ( $new_file === $old_file ) {
        return [ 'success' => true, 'old_file' => $old_file, 'new_file' => $new_file, 'message' => 'Filename unchanged.' ];
    }

    if ( ! rename( $old_file, $new_file ) ) {
        return [ 'success' => false, 'message' => 'Could not rename file — check filesystem permissions.' ];
    }

    // Rename generated image sizes
    foreach ( thm_collect_size_files( $attachment_id, $old_file ) as $old_size_path => $size_suffix ) {
        $new_size_path = $dir . $new_basename . $size_suffix;
        if ( file_exists( $old_size_path ) ) rename( $old_size_path, $new_size_path );
    }

    $old_url = wp_get_attachment_url( $attachment_id );
    update_attached_file( $attachment_id, $new_file );

    $meta = wp_get_attachment_metadata( $attachment_id );
    if ( is_array( $meta ) ) {
        if ( isset( $meta['file'] ) ) {
            $meta['file'] = str_replace( basename( $old_file ), $new_basename . $old_ext, $meta['file'] );
        }
        if ( ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
            $old_stem = pathinfo( $old_file, PATHINFO_FILENAME );
            foreach ( $meta['sizes'] as $size_key => $size_data ) {
                if ( ! empty( $size_data['file'] ) ) {
                    $sz_stem     = pathinfo( $size_data['file'], PATHINFO_FILENAME );
                    $sz_ext_full = substr( $size_data['file'], strlen( $sz_stem ) );
                    $new_sz_stem = preg_replace( '/^' . preg_quote( $old_stem, '/' ) . '/', $new_basename, $sz_stem );
                    $meta['sizes'][ $size_key ]['file'] = $new_sz_stem . $sz_ext_full;
                }
            }
        }
        wp_update_attachment_metadata( $attachment_id, $meta );
    }

    wp_update_post( [
        'ID'         => $attachment_id,
        'post_title' => $new_basename,
        'post_name'  => sanitize_title( $new_basename ),
    ] );

    $new_url = wp_get_attachment_url( $attachment_id );
    if ( $old_url && $new_url && $old_url !== $new_url ) {
        thm_rewrite_content_urls( $old_url, $new_url, $attachment_id );
    }

    return [
        'success'  => true,
        'old_file' => $old_file,
        'new_file' => $new_file,
        'old_url'  => $old_url,
        'new_url'  => $new_url,
        'message'  => 'Renamed successfully.',
    ];
}

/**
 * Collect all generated size file paths so we can rename them too.
 *
 * @return array<string,string>  Maps old_full_path → size_suffix (e.g. "-150x150.jpg")
 */
function thm_collect_size_files( int $attachment_id, string $primary_path ): array {
    $result   = [];
    $meta     = wp_get_attachment_metadata( $attachment_id );
    $dir      = trailingslashit( dirname( $primary_path ) );
    $old_stem = pathinfo( $primary_path, PATHINFO_FILENAME );

    if ( empty( $meta['sizes'] ) || ! is_array( $meta['sizes'] ) ) return $result;

    foreach ( $meta['sizes'] as $size_data ) {
        if ( empty( $size_data['file'] ) ) continue;
        $sz_stem   = pathinfo( $size_data['file'], PATHINFO_FILENAME );
        $sz_ext    = substr( $size_data['file'], strlen( $sz_stem ) );
        $suffix    = substr( $sz_stem, strlen( $old_stem ) ) . $sz_ext;
        $result[ $dir . $size_data['file'] ] = $suffix;
    }
    return $result;
}

/**
 * Rewrite all post_content occurrences of old URL → new URL.
 * Also stores old URL as rollback reference in postmeta.
 */
function thm_rewrite_content_urls( string $old_url, string $new_url, int $attachment_id ): void {
    if ( ! thm_on( 'rewrite_post_content' ) ) return;

    global $wpdb;

    $like  = '%' . $wpdb->esc_like( $old_url ) . '%';
    $posts = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT ID, post_content FROM {$wpdb->posts} WHERE post_status != 'trash' AND post_content LIKE %s",
            $like
        )
    );

    foreach ( $posts as $post ) {
        $updated = str_replace( $old_url, $new_url, $post->post_content );
        if ( $updated !== $post->post_content ) {
            $wpdb->update( $wpdb->posts, [ 'post_content' => $updated ], [ 'ID' => (int) $post->ID ], [ '%s' ], [ '%d' ] );
        }
    }

    update_post_meta( $attachment_id, '_therum_media_old_url', $old_url );
}

// ═══════════════════════════════════════════════════════════════════════════════
// AJAX handlers
// ═══════════════════════════════════════════════════════════════════════════════

function thm_ajax_rename(): void {
    check_ajax_referer( 'therum_media_rename', 'nonce' );
    if ( ! current_user_can( 'upload_files' ) ) wp_send_json_error( [ 'message' => 'Insufficient permissions.' ], 403 );

    $renames = isset( $_POST['renames'] ) ? (array) $_POST['renames'] : [];
    if ( empty( $renames ) ) wp_send_json_error( [ 'message' => 'No renames provided.' ] );

    $results = [];
    foreach ( $renames as $item ) {
        $id           = (int) ( $item['id'] ?? 0 );
        $new_basename = sanitize_text_field( $item['new_basename'] ?? '' );
        if ( ! $id || ! $new_basename ) {
            $results[] = [ 'id' => $id, 'success' => false, 'message' => 'Missing id or new_basename.' ];
            continue;
        }
        $results[] = array_merge( [ 'id' => $id ], thm_rename_attachment( $id, $new_basename ) );
    }

    $success_count = count( array_filter( $results, fn( $r ) => $r['success'] ?? false ) );
    wp_send_json_success( [ 'renamed' => $success_count, 'total' => count( $results ), 'results' => $results ] );
}

function thm_ajax_rename_preview(): void {
    check_ajax_referer( 'therum_media_rename', 'nonce' );
    if ( ! current_user_can( 'upload_files' ) ) wp_send_json_error( [ 'message' => 'Insufficient permissions.' ], 403 );

    $items   = isset( $_POST['items'] ) ? (array) $_POST['items'] : [];
    $preview = [];
    foreach ( $items as $item ) {
        $id           = (int) ( $item['id'] ?? 0 );
        $new_basename = sanitize_text_field( $item['new_basename'] ?? '' );
        if ( ! $id ) continue;
        $old_file = get_attached_file( $id );
        if ( ! $old_file ) continue;
        $old_filename = basename( $old_file );
        $ext          = '.' . strtolower( pathinfo( $old_file, PATHINFO_EXTENSION ) );
        $clean        = pathinfo( sanitize_file_name( $new_basename ), PATHINFO_FILENAME );
        $new_filename = $clean . $ext;
        $conflict     = ( $new_filename !== $old_filename ) && file_exists( dirname( $old_file ) . '/' . $new_filename );
        $preview[]    = [
            'id'           => $id,
            'old_filename' => $old_filename,
            'new_filename' => $new_filename,
            'unchanged'    => $new_filename === $old_filename,
            'conflict'     => $conflict,
        ];
    }
    wp_send_json_success( [ 'preview' => $preview ] );
}

function thm_ajax_rename_single(): void {
    check_ajax_referer( 'therum_media_rename', 'nonce' );
    if ( ! current_user_can( 'upload_files' ) ) wp_send_json_error( [ 'message' => 'Insufficient permissions.' ], 403 );

    $id           = (int) ( $_POST['id'] ?? 0 );
    $new_basename = sanitize_text_field( $_POST['new_basename'] ?? '' );
    if ( ! $id || ! $new_basename ) wp_send_json_error( [ 'message' => 'Missing id or new_basename.' ] );

    $result = thm_rename_attachment( $id, $new_basename );
    if ( $result['success'] ) {
        wp_send_json_success( $result );
    } else {
        wp_send_json_error( $result );
    }
}

// ═══════════════════════════════════════════════════════════════════════════════
// Bulk action
// ═══════════════════════════════════════════════════════════════════════════════

function thm_bulk_actions( array $actions ): array {
    if ( thm_on( 'batch_rename' ) ) $actions['therum_rename'] = 'Rename files (Therum)';
    return $actions;
}

function thm_handle_bulk_rename( string $redirect, string $action, array $ids ): string {
    if ( $action !== 'therum_rename' ) return $redirect;
    return add_query_arg( [ 'therum_rename' => implode( ',', array_map( 'intval', $ids ) ) ], admin_url( 'upload.php' ) );
}

function thm_bulk_rename_notice(): void {
    if ( empty( $_GET['therum_rename_done'] ) ) return;
    $n = (int) $_GET['therum_rename_done'];
    printf(
        '<div class="notice notice-success is-dismissible"><p>%s</p></div>',
        esc_html( sprintf( _n( '%d file renamed by Therum NeoRename.', '%d files renamed by Therum NeoRename.', $n, 'therum' ), $n ) )
    );
}

// ═══════════════════════════════════════════════════════════════════════════════
// Admin column
// ═══════════════════════════════════════════════════════════════════════════════

function thm_media_columns( array $columns ): array {
    if ( thm_on( 'batch_rename' ) ) $columns['therum_rename'] = 'Rename';
    return $columns;
}

function thm_media_column_content( string $column, int $post_id ): void {
    if ( $column !== 'therum_rename' ) return;
    printf(
        '<a href="#" class="thm-rename-link" data-id="%d" data-name="%s">Rename</a>',
        esc_attr( $post_id ),
        esc_attr( get_the_title( $post_id ) )
    );
}

// ═══════════════════════════════════════════════════════════════════════════════
// Admin CSS + JS (upload.php only)
// ═══════════════════════════════════════════════════════════════════════════════

function thm_admin_styles(): void {
    $screen = get_current_screen();
    if ( ! $screen || $screen->base !== 'upload' ) return;
    ?>
    <style>
    .thm-rename-link{font-size:12px;color:#2271b1;text-decoration:none}
    .thm-rename-link:hover{text-decoration:underline}
    #thm-rename-modal{display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:9999;align-items:center;justify-content:center}
    #thm-rename-modal.is-open{display:flex}
    .thm-rename-box{background:#fff;border-radius:8px;padding:24px;width:380px;max-width:90vw;box-shadow:0 8px 32px rgba(0,0,0,.18)}
    .thm-rename-box h3{margin:0 0 12px;font-size:15px}
    .thm-rename-box input{width:100%;padding:7px 10px;border:1px solid #c3c4c7;border-radius:4px;font-size:13px;box-sizing:border-box;margin-bottom:6px}
    .thm-rename-box small{display:block;color:#646970;font-size:11px;margin-bottom:14px}
    .thm-rename-box-actions{display:flex;gap:8px;justify-content:flex-end}
    .thm-rename-save{padding:6px 16px;background:#2271b1;color:#fff;border:none;border-radius:4px;cursor:pointer;font-size:13px}
    .thm-rename-cancel{padding:6px 14px;background:transparent;border:1px solid #c3c4c7;border-radius:4px;cursor:pointer;font-size:13px}
    </style>
    <?php
}

function thm_admin_scripts(): void {
    $screen = get_current_screen();
    if ( ! $screen || $screen->base !== 'upload' ) return;
    $nonce    = wp_create_nonce( 'therum_media_rename' );
    $ajax_url = admin_url( 'admin-ajax.php' );
    ?>
    <div id="thm-rename-modal">
      <div class="thm-rename-box">
        <h3>Rename file</h3>
        <input type="text" id="thm-rename-input" placeholder="New filename (without extension)" />
        <small>Extension is preserved. Spaces → hyphens, uppercase → lowercase.</small>
        <div class="thm-rename-box-actions">
          <button class="thm-rename-cancel" id="thm-rename-cancel">Cancel</button>
          <button class="thm-rename-save" id="thm-rename-save">Rename</button>
        </div>
      </div>
    </div>
    <script>
    (function(){
      var modal  = document.getElementById('thm-rename-modal');
      var inp    = document.getElementById('thm-rename-input');
      var save   = document.getElementById('thm-rename-save');
      var cancel = document.getElementById('thm-rename-cancel');
      var activeId = 0;
      function openModal(id, name){ activeId=id; inp.value=name; modal.classList.add('is-open'); setTimeout(function(){ inp.focus(); inp.select(); },50); }
      function closeModal(){ modal.classList.remove('is-open'); activeId=0; }
      document.addEventListener('click', function(e){
        var link = e.target.closest('.thm-rename-link');
        if (!link) return;
        e.preventDefault();
        openModal(parseInt(link.dataset.id,10), link.dataset.name||'');
      });
      if (cancel) cancel.addEventListener('click', closeModal);
      modal.addEventListener('click', function(e){ if (e.target===modal) closeModal(); });
      if (save) save.addEventListener('click', function(){
        if (!activeId) return;
        var newName = inp.value.trim();
        if (!newName) return;
        save.disabled = true; save.textContent = 'Renaming…';
        var fd = new FormData();
        fd.append('action','therum_media_rename_single');
        fd.append('nonce','<?php echo esc_js( $nonce ); ?>');
        fd.append('id', activeId);
        fd.append('new_basename', newName);
        fetch('<?php echo esc_js( $ajax_url ); ?>', {method:'POST',body:fd})
          .then(function(r){ return r.json(); })
          .then(function(data){
            save.disabled=false; save.textContent='Rename';
            if (data.success){ closeModal(); window.location.reload(); }
            else { alert('Rename failed: '+(data.data&&data.data.message?data.data.message:'Unknown error')); }
          })
          .catch(function(){ save.disabled=false; save.textContent='Rename'; alert('Network error.'); });
      });
      inp.addEventListener('keydown', function(e){
        if (e.key==='Enter') save.click();
        if (e.key==='Escape') closeModal();
      });
    })();
    </script>
    <?php
}
