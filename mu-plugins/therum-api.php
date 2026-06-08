<?php
/**
 * Plugin Name: Therum OS — API
 * Description: Integration domain — REST API, external connectors (GPTs/ChatGPT/
 *              Slack/etc.), and the Therum MCP server. Three transports, one auth
 *              model (Therum scoped tokens / WP App Passwords).
 *              Merged from therum-api, therum-connections, therum-mcp
 *              (Phase 3 Merge #5, 2026-05-29).
 * Version: 1.9.0
 *
 * Section map:
 *   1. API           — internal REST routes + admin AJAX fast-path
 *   2. CONNECTIONS   — connector tile-grid + per-connector config + GPTs hookup
 *   3. MCP SERVER    — POST /wp-json/therum/v1/mcp + tool registry + queue handlers
 *
 * Kill switches preserved per sub-module:
 *   THERUM_MCP_DISABLE        — MCP section bypassed
 *   (api + connections share no kill switch — they're core integration surface)
 */

if ( ! defined( 'ABSPATH' ) ) exit;


// ════════════════════════════════════════════════════════════════════════════
//  1. API — from therum-api.php
// ════════════════════════════════════════════════════════════════════════════

// ════════════════════════════════════════════════════════════════════════════
//  REST API HARDENING + OUTBOUND WEBHOOKS — from therum-api-engine.php
// ════════════════════════════════════════════════════════════════════════════


// ═════════════════════════════════════════════════════════════════════════════
//  1. REST API HARDENING
// ═════════════════════════════════════════════════════════════════════════════

// ─── REST API kill switch ────────────────────────────────────────────────────
add_filter( 'rest_authentication_errors', function( $result ) {
	if ( ! get_option( 'th_rest_enabled', true ) ) {
		return new WP_Error(
			'rest_disabled',
			'REST API is disabled.',
			[ 'status' => 403 ]
		);
	}

	// If we already have an error, don't override
	if ( is_wp_error( $result ) ) return $result;
	if ( $result === true || $result === false ) return $result;

	// Require auth on all reads if toggle is on
	if ( get_option( 'th_rest_require_auth', false ) ) {
		if ( ! is_user_logged_in() ) {
			return new WP_Error(
				'rest_not_authenticated',
				'Authentication required.',
				[ 'status' => 401 ]
			);
		}
	}

	return $result;
}, 99 );


// ─── CORS allowlist ──────────────────────────────────────────────────────────
add_action( 'rest_api_init', function() {
	remove_filter( 'rest_pre_serve_request', 'rest_send_cors_headers' );

	add_filter( 'rest_pre_serve_request', function( $served ) {
		$origins_raw = trim( (string) get_option( 'th_cors_origins', '' ) );
		if ( ! $origins_raw ) return $served;

		$origins = array_filter( array_map( 'trim', preg_split( '/\r\n|\r|\n/', $origins_raw ) ) );
		$incoming = isset( $_SERVER['HTTP_ORIGIN'] ) ? esc_url_raw( wp_unslash( $_SERVER['HTTP_ORIGIN'] ) ) : '';

		$allow = false;
		foreach ( $origins as $o ) {
			if ( $o === '*' ) { $allow = $incoming ?: '*'; break; }
			if ( $o === $incoming ) { $allow = $incoming; break; }
		}

		if ( $allow ) {
			header( 'Access-Control-Allow-Origin: ' . $allow );
			header( 'Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS, PATCH' );
			header( 'Access-Control-Allow-Credentials: true' );
			header( 'Access-Control-Allow-Headers: Authorization, Content-Type, X-WP-Nonce' );
			header( 'Vary: Origin' );

			if ( ! empty( $_SERVER['REQUEST_METHOD'] ) && 'OPTIONS' === $_SERVER['REQUEST_METHOD'] ) {
				exit;
			}
		}

		return $served;
	}, 15 );
}, 15 );


// ═════════════════════════════════════════════════════════════════════════════
//  3. HEADLESS MODE
// ═════════════════════════════════════════════════════════════════════════════

// When headless mode is on, all public (non-admin, non-REST) requests return a
// JSON pointer to the REST API instead of rendering the WP theme.
add_action( 'template_redirect', function() {
	if ( ! get_option( 'th_headless_mode', false ) ) return;
	if ( is_admin() ) return;
	if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) return;
	if ( wp_doing_cron() ) return;

	status_header( 200 );
	header( 'Content-Type: application/json; charset=utf-8' );
	echo wp_json_encode( [
		'mode'    => 'headless',
		'site'    => get_bloginfo( 'name' ),
		'rest'    => rest_url(),
		'therum'  => rest_url( 'therum/v1' ),
		'status'  => rest_url( 'therum/v1/status' ),
		'content' => rest_url( 'therum/v1/content' ),
	] );
	exit;
} );


// ═════════════════════════════════════════════════════════════════════════════
//  4. /therum/v1/ REST NAMESPACE
// ═════════════════════════════════════════════════════════════════════════════

add_action( 'rest_api_init', function() {

	// GET /therum/v1/status — public, no auth required
	register_rest_route( 'therum/v1', '/status', [
		'methods'             => 'GET',
		'callback'            => 'therum_rest_status',
		'permission_callback' => '__return_true',
	] );

	// GET /therum/v1/settings — manage_options required
	register_rest_route( 'therum/v1', '/settings', [
		'methods'             => 'GET',
		'callback'            => 'therum_rest_settings',
		'permission_callback' => function() {
			return current_user_can( 'manage_options' );
		},
	] );

	// GET /therum/v1/content — edit_posts required
	register_rest_route( 'therum/v1', '/content', [
		'methods'             => 'GET',
		'callback'            => 'therum_rest_content',
		'permission_callback' => function() {
			return current_user_can( 'edit_posts' );
		},
		'args' => [
			'type'     => [ 'default' => 'post',    'sanitize_callback' => 'sanitize_key' ],
			'page'     => [ 'default' => 1,          'sanitize_callback' => 'absint' ],
			'per_page' => [ 'default' => 20,         'sanitize_callback' => 'absint' ],
			'status'   => [ 'default' => 'publish',  'sanitize_callback' => 'sanitize_text_field' ],
		],
	] );

}, 15 );


function therum_rest_status() {
	return rest_ensure_response( [
		'therum_version' => defined( 'THERUM_OS_VERSION' ) ? THERUM_OS_VERSION : '1.x',
		'engine_version' => get_bloginfo( 'version' ),
		'site_name'      => get_bloginfo( 'name' ),
		'site_url'       => home_url(),
		'rest_url'       => rest_url(),
		'headless_mode'  => (bool) get_option( 'th_headless_mode', false ),
		'rest_enabled'   => (bool) get_option( 'th_rest_enabled', true ),
		'modules'        => therum_active_modules(),
		'generated_at'   => gmdate( 'c' ),
	] );
}

function therum_rest_settings() {
	$keys = defined( 'TH_SETTINGS_KEYS' ) ? TH_SETTINGS_KEYS : [];
	$out  = [];
	foreach ( $keys as $k ) {
		$out[ $k ] = get_option( $k );
	}
	return rest_ensure_response( $out );
}

function therum_rest_content( $request ) {
	$post_type = sanitize_key( $request->get_param( 'type' ) ?: 'post' );
	$page      = max( 1, (int) ( $request->get_param( 'page' ) ?: 1 ) );
	$per_page  = min( 100, max( 1, (int) ( $request->get_param( 'per_page' ) ?: 20 ) ) );
	$status    = sanitize_text_field( $request->get_param( 'status' ) ?: 'publish' );

	$query = new WP_Query( [
		'post_type'      => $post_type,
		'post_status'    => $status,
		'posts_per_page' => $per_page,
		'paged'          => $page,
		'no_found_rows'  => false,
	] );

	$items = [];
	foreach ( $query->posts as $post ) {
		$items[] = [
			'id'          => $post->ID,
			'type'        => $post->post_type,
			'status'      => $post->post_status,
			'title'       => $post->post_title,
			'slug'        => $post->post_name,
			'excerpt'     => get_the_excerpt( $post ),
			'date'        => $post->post_date_gmt,
			'modified'    => $post->post_modified_gmt,
			'author_id'   => (int) $post->post_author,
			'url'         => get_permalink( $post ),
			'thumbnail'   => get_the_post_thumbnail_url( $post, 'medium' ) ?: null,
			'card_layout' => get_post_meta( $post->ID, '_th_card_layout', true ) ?: null,
		];
	}

	return rest_ensure_response( [
		'items'       => $items,
		'total'       => (int) $query->found_posts,
		'page'        => $page,
		'per_page'    => $per_page,
		'total_pages' => (int) $query->max_num_pages,
	] );
}

function therum_active_modules() {
	$files = glob( __DIR__ . '/therum-*.php' ) ?: [];
	return array_map( function( $f ) {
		return str_replace( 'therum-', '', basename( $f, '.php' ) );
	}, $files );
}


// ═════════════════════════════════════════════════════════════════════════════
//  2. WEBHOOKS
// ═════════════════════════════════════════════════════════════════════════════
//
// Each webhook has: id, name, url, events (array), secret (for HMAC),
// enabled (bool), created, last_status, last_fired.
// Stored in option 'th_webhooks' as id-keyed array.

function therum_get_webhooks() {
	return (array) get_option( 'th_webhooks', [] );
}

function therum_save_webhook( $id, $data ) {
	$hooks = therum_get_webhooks();
	$hooks[ $id ] = $data;
	update_option( 'th_webhooks', $hooks );
}

function therum_delete_webhook( $id ) {
	$hooks = therum_get_webhooks();
	unset( $hooks[ $id ] );
	update_option( 'th_webhooks', $hooks );
}

function therum_supported_events() {
	return [
		'post.published'    => 'A post is published',
		'post.updated'      => 'A published post is updated',
		'post.deleted'      => 'A post is moved to trash or deleted',
		'page.published'    => 'A page is published',
		'comment.posted'    => 'A new comment is posted',
		'user.registered'   => 'A new user signs up',
		'user.login'        => 'A user logs in',
		'media.uploaded'    => 'New media is uploaded',
		'order.created'     => 'WooCommerce — order is created',
		'order.completed'   => 'WooCommerce — order is marked complete',
		'product.published' => 'WooCommerce — product is published',
	];
}


// ─── Event firing ────────────────────────────────────────────────────────────
function therum_fire_webhook_event( $event, $payload ) {
	$hooks = therum_get_webhooks();
	if ( empty( $hooks ) ) return;

	foreach ( $hooks as $id => $hook ) {
		if ( empty( $hook['enabled'] ) ) continue;
		$events = (array) ( $hook['events'] ?? [] );
		if ( ! in_array( $event, $events, true ) ) continue;

		// Schedule async via wp_cron so we don't block the request
		wp_schedule_single_event( time() + 1, 'therum_webhook_dispatch', [ $id, $event, $payload ] );
	}
}


// ─── Dispatcher (cron handler) ───────────────────────────────────────────────
add_action( 'therum_webhook_dispatch', function( $id, $event, $payload ) {
	$hooks = therum_get_webhooks();
	if ( ! isset( $hooks[ $id ] ) ) return;
	$hook = $hooks[ $id ];

	// Event payloads can carry PII (subscriber emails, the registrant's IP, user
	// roles). Refuse to transmit that over plaintext HTTP — require HTTPS so the
	// data is encrypted in transit regardless of whether HMAC signing is set up.
	if ( stripos( (string) ( $hook['url'] ?? '' ), 'https://' ) !== 0 ) {
		error_log( sprintf( 'Therum webhook %s skipped: endpoint must use HTTPS to receive event "%s".', $id, $event ) );
		return;
	}

	$body = wp_json_encode([
		'event'   => $event,
		'site'    => home_url(),
		'sent_at' => gmdate( 'c' ),
		'data'    => $payload,
	]);

	$headers = [
		'Content-Type'  => 'application/json',
		'User-Agent'    => 'TherumOS-Webhook/1.0',
		'X-Therum-Event'  => $event,
		'X-Therum-Hook-Id' => $id,
	];

	// HMAC signature for verification
	if ( ! empty( $hook['secret'] ) ) {
		$signature = hash_hmac( 'sha256', $body, $hook['secret'] );
		$headers['X-Therum-Signature'] = 'sha256=' . $signature;
	}

	$response = wp_remote_post( $hook['url'], [
		'method'   => 'POST',
		'timeout'  => 10,
		'blocking' => true,
		'headers'  => $headers,
		'body'     => $body,
		'sslverify'=> true,
	]);

	$status = is_wp_error( $response ) ? 'error: ' . $response->get_error_message() : (int) wp_remote_retrieve_response_code( $response );

	$hook['last_fired']  = time();
	$hook['last_status'] = $status;
	$hook['last_event']  = $event;
	therum_save_webhook( $id, $hook );
}, 10, 3 );


// ─── Event hooks — bind to WordPress + WooCommerce actions ───────────────────

// Posts
add_action( 'transition_post_status', function( $new, $old, $post ) {
	if ( $new === $old ) return;
	$type = $post->post_type;

	if ( $new === 'publish' && $old !== 'publish' ) {
		$event = ( $type === 'page' ) ? 'page.published' : (
			( $type === 'product' ) ? 'product.published' : 'post.published'
		);
		therum_fire_webhook_event( $event, therum_post_payload( $post ) );
	}
	if ( $new === 'publish' && $old === 'publish' ) {
		// Update event only fires on already-published posts (saved again)
		therum_fire_webhook_event( 'post.updated', therum_post_payload( $post ) );
	}
	if ( in_array( $new, [ 'trash' ], true ) ) {
		therum_fire_webhook_event( 'post.deleted', therum_post_payload( $post ) );
	}
}, 10, 3 );

function therum_post_payload( $post ) {
	return [
		'id'      => $post->ID,
		'type'    => $post->post_type,
		'title'   => $post->post_title,
		'slug'    => $post->post_name,
		'status'  => $post->post_status,
		'author'  => (int) $post->post_author,
		'url'     => get_permalink( $post ),
		'edit_url' => get_edit_post_link( $post->ID, 'raw' ),
		'modified' => $post->post_modified_gmt,
	];
}

// Comments
add_action( 'wp_insert_comment', function( $id, $comment ) {
	therum_fire_webhook_event( 'comment.posted', [
		'id'         => (int) $id,
		'post_id'    => (int) $comment->comment_post_ID,
		'author'     => $comment->comment_author,
		'email'      => $comment->comment_author_email,
		'content'    => wp_strip_all_tags( $comment->comment_content ),
		'approved'   => (int) $comment->comment_approved,
	]);
}, 10, 2 );

// Users
add_action( 'user_register', function( $user_id ) {
	$user = get_userdata( $user_id );
	if ( ! $user ) return;
	therum_fire_webhook_event( 'user.registered', [
		'id'         => (int) $user_id,
		'login'      => $user->user_login,
		'email'      => $user->user_email,
		'name'       => $user->display_name,
		'registered' => $user->user_registered,
		'roles'      => $user->roles,
	]);
});

add_action( 'wp_login', function( $login, $user ) {
	if ( ! ( $user instanceof WP_User ) ) return;
	therum_fire_webhook_event( 'user.login', [
		'id'    => $user->ID,
		'login' => $login,
		'email' => $user->user_email,
		'roles' => $user->roles,
		'ip'    => isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '',
	]);
}, 10, 2 );

// Media
add_action( 'add_attachment', function( $attachment_id ) {
	$post = get_post( $attachment_id );
	therum_fire_webhook_event( 'media.uploaded', [
		'id'    => (int) $attachment_id,
		'mime'  => $post->post_mime_type,
		'title' => $post->post_title,
		'url'   => wp_get_attachment_url( $attachment_id ),
		'file'  => get_attached_file( $attachment_id ),
	]);
});

// WooCommerce
add_action( 'woocommerce_new_order', function( $order_id, $order ) {
	if ( ! $order ) $order = wc_get_order( $order_id );
	if ( ! $order ) return;
	therum_fire_webhook_event( 'order.created', therum_order_payload( $order ) );
}, 10, 2 );

add_action( 'woocommerce_order_status_completed', function( $order_id ) {
	$order = wc_get_order( $order_id );
	if ( ! $order ) return;
	therum_fire_webhook_event( 'order.completed', therum_order_payload( $order ) );
});

function therum_order_payload( $order ) {
	$items = [];
	foreach ( $order->get_items() as $item ) {
		$items[] = [
			'product_id' => $item->get_product_id(),
			'name'       => $item->get_name(),
			'qty'        => $item->get_quantity(),
			'subtotal'   => $item->get_subtotal(),
		];
	}
	return [
		'id'       => $order->get_id(),
		'status'   => $order->get_status(),
		'total'    => (float) $order->get_total(),
		'currency' => $order->get_currency(),
		'customer' => [
			'id'    => $order->get_user_id(),
			'email' => $order->get_billing_email(),
			'name'  => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
		],
		'items'    => $items,
		'edit_url' => admin_url( 'post.php?post=' . $order->get_id() . '&action=edit' ),
	];
}


// ═════════════════════════════════════════════════════════════════════════════
//  AJAX — webhook CRUD
// ═════════════════════════════════════════════════════════════════════════════

add_action( 'wp_ajax_therum_webhook_save', function() {
	if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'forbidden', 403 );
	check_ajax_referer( 'therum_webhook', 'nonce' );

	$id      = sanitize_key( $_POST['id'] ?? '' );
	$name    = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );
	$url     = esc_url_raw( wp_unslash( $_POST['url'] ?? '' ) );
	$events  = isset( $_POST['events'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['events'] ) ) : [];
	$enabled = ! empty( $_POST['enabled'] ) && $_POST['enabled'] === '1';
	$secret  = sanitize_text_field( wp_unslash( $_POST['secret'] ?? '' ) );

	if ( ! $name )   wp_send_json_error( 'name required' );
	if ( ! $url )    wp_send_json_error( 'url required' );
	// Require HTTPS: event payloads can include PII (emails, IPs, roles), so the
	// endpoint must encrypt in transit. The dispatcher enforces this too as a
	// backstop for any webhook saved before this check existed.
	if ( stripos( $url, 'https://' ) !== 0 ) wp_send_json_error( 'Webhook URL must use HTTPS.' );

	if ( ! $id ) {
		$id = 'wh_' . wp_generate_password( 8, false, false );
	}

	$existing = therum_get_webhooks();
	$prev = $existing[ $id ] ?? [];

	therum_save_webhook( $id, [
		'id'          => $id,
		'name'        => $name,
		'url'         => $url,
		'events'      => $events,
		'enabled'     => $enabled,
		'secret'      => $secret ?: ( $prev['secret'] ?? '' ),
		'created'     => $prev['created'] ?? time(),
		'last_fired'  => $prev['last_fired'] ?? null,
		'last_status' => $prev['last_status'] ?? null,
	]);

	wp_send_json_success([ 'id' => $id, 'msg' => 'Webhook saved' ]);
});


add_action( 'wp_ajax_therum_webhook_delete', function() {
	if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'forbidden', 403 );
	check_ajax_referer( 'therum_webhook', 'nonce' );
	$id = sanitize_key( $_POST['id'] ?? '' );
	if ( ! $id ) wp_send_json_error( 'no id' );
	therum_delete_webhook( $id );
	wp_send_json_success([ 'msg' => 'Webhook deleted' ]);
});


add_action( 'wp_ajax_therum_webhook_test', function() {
	if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'forbidden', 403 );
	check_ajax_referer( 'therum_webhook', 'nonce' );

	$id = sanitize_key( $_POST['id'] ?? '' );
	$hooks = therum_get_webhooks();
	if ( ! isset( $hooks[ $id ] ) ) wp_send_json_error( 'webhook not found' );
	$hook = $hooks[ $id ];

	$body = wp_json_encode([
		'event'   => 'test.ping',
		'site'    => home_url(),
		'sent_at' => gmdate( 'c' ),
		'data'    => [ 'message' => 'This is a test ping from Therum OS' ],
	]);

	$headers = [
		'Content-Type'      => 'application/json',
		'User-Agent'        => 'TherumOS-Webhook/1.0',
		'X-Therum-Event'    => 'test.ping',
		'X-Therum-Hook-Id'  => $id,
	];
	if ( ! empty( $hook['secret'] ) ) {
		$headers['X-Therum-Signature'] = 'sha256=' . hash_hmac( 'sha256', $body, $hook['secret'] );
	}

	$response = wp_remote_post( $hook['url'], [
		'method'   => 'POST',
		'timeout'  => 10,
		'headers'  => $headers,
		'body'     => $body,
	]);

	if ( is_wp_error( $response ) ) {
		wp_send_json_error( 'Network error: ' . $response->get_error_message() );
	}
	$code = wp_remote_retrieve_response_code( $response );

	$hook['last_fired']  = time();
	$hook['last_status'] = (int) $code;
	$hook['last_event']  = 'test.ping';
	therum_save_webhook( $id, $hook );

	if ( $code >= 200 && $code < 300 ) {
		wp_send_json_success([ 'status' => $code, 'msg' => 'Test delivered ('. $code .')' ]);
	}
	wp_send_json_error( 'Endpoint returned HTTP ' . $code );
});


// ═════════════════════════════════════════════════════════════════════════════
//  RENDER — replaces therum_render_api_webhooks in therum-settings.php
// ═════════════════════════════════════════════════════════════════════════════

function therum_render_api_full() {
	$rest_enabled  = (bool) get_option( 'th_rest_enabled', true );
	$rest_auth_req = (bool) get_option( 'th_rest_require_auth', false );
	$headless_mode = (bool) get_option( 'th_headless_mode', false );
	$cors          = get_option( 'th_cors_origins', '' );
	$webhooks      = therum_get_webhooks();
	$events        = therum_supported_events();
	$nonce         = wp_create_nonce( 'therum_webhook' );

	$rest_base     = rest_url();
	$therum_v1     = rest_url( 'therum/v1' );

	// ── Connect ─────────────────────────────────────────────────────────────
	therum_settings_group( 'Connect', 'Your site\'s live API connection details.', function() use ( $rest_base, $therum_v1, $headless_mode ) {
		?>
		<div class="th-connect-grid" style="display:grid;gap:10px;margin-bottom:4px;">

			<div class="th-connect-row" style="display:flex;align-items:center;gap:10px;padding:10px 14px;background:var(--sf2);border:1px solid var(--bd);border-radius:10px;">
				<div style="flex:1;min-width:0;">
					<div style="font-size:11px;font-weight:600;color:var(--tx3);margin-bottom:2px;text-transform:uppercase;letter-spacing:.05em;">WP REST API</div>
					<code style="font-size:12px;color:var(--tx);word-break:break-all;"><?php echo esc_html( $rest_base ); ?></code>
				</div>
				<button type="button" class="th-button" style="flex-shrink:0;font-size:11px;padding:4px 10px;" onclick="navigator.clipboard.writeText('<?php echo esc_js( $rest_base ); ?>').then(function(){this.textContent='Copied';setTimeout(function(t){t.textContent='Copy'}.bind(null,this.previousSibling||this),1200)}.bind(this))">Copy</button>
				<span id="th-rest-health" style="flex-shrink:0;width:8px;height:8px;border-radius:50%;background:var(--tx3);margin-left:2px;" title="Checking..."></span>
			</div>

			<div class="th-connect-row" style="display:flex;align-items:center;gap:10px;padding:10px 14px;background:var(--sf2);border:1px solid var(--bd);border-radius:10px;">
				<div style="flex:1;min-width:0;">
					<div style="font-size:11px;font-weight:600;color:var(--tx3);margin-bottom:2px;text-transform:uppercase;letter-spacing:.05em;">Therum v1</div>
					<code style="font-size:12px;color:var(--tx);word-break:break-all;"><?php echo esc_html( $therum_v1 ); ?></code>
				</div>
				<button type="button" class="th-button" style="flex-shrink:0;font-size:11px;padding:4px 10px;" onclick="navigator.clipboard.writeText('<?php echo esc_js( $therum_v1 ); ?>').then(function(){this.textContent='Copied';setTimeout(function(t){t.textContent='Copy'}.bind(null,this.previousSibling||this),1200)}.bind(this))">Copy</button>
				<a href="<?php echo esc_url( rest_url( 'therum/v1/status' ) ); ?>" target="_blank" class="th-button" style="flex-shrink:0;font-size:11px;padding:4px 10px;text-decoration:none;">Status ↗</a>
			</div>

		</div>

		<div style="font-size:12px;color:var(--tx3);margin-top:6px;margin-bottom:14px;">
			Auth: <code style="background:var(--sf2);padding:1px 6px;border-radius:4px;">Authorization: Basic base64(username:application_password)</code>
		</div>

		<?php therum_setting_row( 'Headless mode', 'Suppresses the WP theme for all public requests. Visitors receive a JSON pointer to the REST API. Use when a decoupled frontend (Next.js, Astro, etc.) handles rendering.', therum_toggle( 'th_headless_mode', $headless_mode ) ); ?>

		<?php if ( $headless_mode ): ?>
		<div style="margin-top:8px;padding:10px 14px;background:color-mix(in srgb, var(--ac) 10%, transparent);border:1px solid color-mix(in srgb, var(--ac) 30%, transparent);border-radius:8px;font-size:12px;color:var(--tx2);">
			Headless mode is <strong>on</strong>. Public URLs return JSON. Admin and REST API are unaffected.
		</div>
		<?php endif; ?>

		<script>
		(function() {
			var dot = document.getElementById('th-rest-health');
			if (!dot) return;
			fetch('<?php echo esc_js( rest_url( 'therum/v1/status' ) ); ?>', { credentials: 'same-origin' })
				.then(function(r) {
					dot.style.background = r.ok ? 'var(--ok)' : 'var(--err)';
					dot.title = r.ok ? 'Connected' : 'Error ' + r.status;
				})
				.catch(function() {
					dot.style.background = 'var(--err)';
					dot.title = 'Unreachable';
				});
		})();
		</script>
		<?php
	} );

	// REST API hardening
	therum_settings_group( 'REST API', 'WP\'s built-in REST API at /wp-json/.', function() use ( $rest_enabled, $rest_auth_req ) {
		therum_setting_row( 'REST API enabled', 'Disable to fully lock down /wp-json/ endpoints. Bricks needs this on.', therum_toggle( 'th_rest_enabled', $rest_enabled ) );
		therum_setting_row( 'Require authentication', 'Block unauthenticated reads (anonymous calls). Recommended for private sites.', therum_toggle( 'th_rest_require_auth', $rest_auth_req ) );
	});

	therum_settings_group( 'CORS', 'Allow cross-origin requests from these domains.', function() use ( $cors ) {
		therum_setting_row( 'Allowed origins', 'One per line. Use * for wildcard (not recommended). Each origin should include scheme, e.g. https://app.example.com', '<textarea class="th-input th-textarea" data-th-text="th_cors_origins" rows="4" placeholder="https://app.example.com">' . esc_textarea( $cors ) . '</textarea>' );
	});

	therum_settings_group( 'Application passwords', 'Per-user API tokens for Bearer auth.', function() {
		?>
		<div style="font-size:13px;color:var(--tx2);line-height:1.6;">
			Generate per-user API tokens in your profile. Use them as <code style="background:var(--sf2);padding:1px 6px;border-radius:4px;">Authorization: Basic base64(user:token)</code> headers for REST API calls.
			<br><a href="<?php echo esc_url( admin_url('profile.php#application-passwords-section') ); ?>" style="color:var(--ac);margin-top:8px;display:inline-block;">Manage your tokens →</a>
		</div>
		<?php
	});

	// Webhooks
	therum_settings_group( 'Webhooks', 'Send HTTP POST to your endpoints when site events occur.', function() use ( $webhooks, $events, $nonce ) {
		?>
		<div class="th-webhooks" data-th-webhooks data-nonce="<?php echo esc_attr( $nonce ); ?>">
			<button type="button" class="th-button th-button-primary" data-webhook-new>+ New webhook</button>

			<?php if ( ! empty( $webhooks ) ): ?>
			<table class="th-roles-table" style="width:100%; margin-top:14px;">
				<thead><tr>
					<th>Name</th><th>URL</th><th style="width:70px;">Events</th><th style="width:90px;">Last fired</th><th style="width:90px;">Status</th><th style="width:80px;"></th><th style="width:60px;text-align:right;"></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $webhooks as $id => $h ):
					$last_fired = ! empty( $h['last_fired'] ) ? human_time_diff( $h['last_fired'] ) . ' ago' : '—';
					$status     = $h['last_status'] ?? '—';
					$status_color = 'var(--tx3)';
					if ( is_int( $status ) ) {
						$status_color = $status >= 200 && $status < 300 ? 'var(--ok)' : 'var(--err)';
					} elseif ( is_string( $status ) && strpos( $status, 'error' ) === 0 ) {
						$status_color = 'var(--err)';
					}
				?>
				<tr data-webhook-row="<?php echo esc_attr( $id ); ?>">
					<td>
						<strong><?php echo esc_html( $h['name'] ); ?></strong>
						<?php if ( empty( $h['enabled'] ) ): ?>
							<span style="display:inline-block;margin-left:6px;padding:2px 7px;background:var(--sf2);color:var(--tx3);border-radius:10px;font-size:10px;font-weight:500;">Disabled</span>
						<?php endif; ?>
					</td>
					<td style="font-family:monospace;font-size:11px;color:var(--tx2);max-width:280px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?php echo esc_html( $h['url'] ); ?></td>
					<td><?php echo count( $h['events'] ?? [] ); ?></td>
					<td style="font-size:11px;color:var(--tx3);"><?php echo esc_html( $last_fired ); ?></td>
					<td style="font-size:11px;font-weight:600;color:<?php echo esc_attr( $status_color ); ?>;"><?php echo esc_html( (string) $status ); ?></td>
					<td>
						<button type="button" class="th-button" data-webhook-test="<?php echo esc_attr( $id ); ?>" style="padding:4px 10px;font-size:11px;">Test</button>
					</td>
					<td style="text-align:right;">
						<button type="button" class="th-button" data-webhook-edit="<?php echo esc_attr( $id ); ?>" style="padding:4px 10px;font-size:11px;">Edit</button>
					</td>
				</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php endif; ?>

			<!-- Editor panel -->
			<div class="th-webhook-editor" data-webhook-editor hidden>
				<div class="th-webhook-editor-head">
					<h3 data-webhook-editor-title>New webhook</h3>
					<button type="button" class="th-role-close" data-webhook-cancel aria-label="Cancel">×</button>
				</div>

				<input type="hidden" data-webhook-id value="">

				<div class="th-role-field">
					<label>Name</label>
					<small>Internal label so you can identify this webhook.</small>
					<input type="text" class="th-input" data-webhook-name placeholder="Slack new-orders channel" maxlength="80">
				</div>

				<div class="th-role-field">
					<label>Endpoint URL</label>
					<small>Where to POST the event. Must be HTTPS for production.</small>
					<input type="url" class="th-input" data-webhook-url placeholder="https://hooks.example.com/abc123" style="font-family:monospace;font-size:12px;">
				</div>

				<div class="th-role-field">
					<label>Signing secret (optional)</label>
					<small>If set, requests are signed with HMAC-SHA256 in <code>X-Therum-Signature</code> header. Verify on your end to reject forgeries.</small>
					<input type="text" class="th-input" data-webhook-secret placeholder="Leave blank to skip signing" style="font-family:monospace;font-size:12px;">
				</div>

				<div class="th-role-field">
					<label>Events to fire on</label>
					<small>Pick which site events trigger this webhook. At least one is required.</small>
					<div class="th-cap-grid" style="max-height:none;">
						<?php foreach ( $events as $ev_key => $ev_label ): ?>
						<label class="th-cap-pill" data-event-pill style="font-family:var(--f);font-size:11px;">
							<input type="checkbox" data-event="<?php echo esc_attr( $ev_key ); ?>" value="<?php echo esc_attr( $ev_key ); ?>">
							<span><?php echo esc_html( $ev_label ); ?></span>
						</label>
						<?php endforeach; ?>
					</div>
				</div>

				<div class="th-role-field">
					<label style="display:flex;align-items:center;gap:10px;cursor:pointer;">
						<input type="checkbox" data-webhook-enabled checked> Enabled
					</label>
					<small>Uncheck to keep the configuration but stop firing.</small>
				</div>

				<div class="th-role-actions">
					<span class="th-role-result" data-webhook-result></span>
					<button type="button" class="th-button" data-webhook-cancel>Cancel</button>
					<button type="button" class="th-button" data-webhook-delete style="color:var(--err);border-color:color-mix(in srgb, var(--err) 30%, transparent);" hidden>Delete</button>
					<button type="button" class="th-button th-button-primary" data-webhook-save>Save webhook</button>
				</div>
			</div>
		</div>

		<?php
		$payload = [];
		foreach ( $webhooks as $k => $h ) {
			$payload[ $k ] = [
				'id'      => $k,
				'name'    => $h['name'] ?? '',
				'url'     => $h['url'] ?? '',
				'secret'  => $h['secret'] ?? '',
				'events'  => $h['events'] ?? [],
				'enabled' => ! empty( $h['enabled'] ),
			];
		}
		?>
		<script>
		(function() {
			var HOOKS = <?php echo wp_json_encode( $payload ); ?>;
			var root = document.querySelector('[data-th-webhooks]');
			if (!root) return;
			var nonce = root.getAttribute('data-nonce');
			var ajax = window.ajaxurl || '/wp-admin/admin-ajax.php';
			var editor = root.querySelector('[data-webhook-editor]');
			var resultEl = root.querySelector('[data-webhook-result]');

			function $(s, ctx) { return (ctx || root).querySelector(s); }
			function $$(s, ctx) { return Array.prototype.slice.call((ctx || root).querySelectorAll(s)); }
			function setVal(sel, v) { var el = $(sel); if (el) el.value = v == null ? '' : v; }
			function getVal(sel)    { var el = $(sel); return el ? el.value : ''; }

			function openEditor(hook) {
				editor.hidden = false;
				resultEl.textContent = '';
				var isNew = !hook;
				$('[data-webhook-editor-title]').textContent = isNew ? 'New webhook' : 'Edit: ' + hook.name;
				setVal('[data-webhook-id]', hook ? hook.id : '');
				setVal('[data-webhook-name]', hook ? hook.name : '');
				setVal('[data-webhook-url]', hook ? hook.url : '');
				setVal('[data-webhook-secret]', hook ? hook.secret : '');
				$('[data-webhook-enabled]').checked = hook ? hook.enabled : true;

				$$('[data-event]').forEach(function(cb) {
					cb.checked = hook && hook.events && hook.events.indexOf(cb.value) >= 0;
					var pill = cb.closest('[data-event-pill]');
					if (pill) pill.classList.toggle('active', cb.checked);
				});

				var del = $('[data-webhook-delete]');
				if (del) del.hidden = isNew;
				editor.scrollIntoView({ behavior: 'smooth', block: 'center' });
			}

			function closeEditor() { editor.hidden = true; resultEl.textContent = ''; }

			$('[data-webhook-new]').addEventListener('click', function() { openEditor(null); });

			document.addEventListener('click', function(e) {
				var edit = e.target.closest('[data-webhook-edit]');
				if (edit) {
					e.preventDefault();
					var k = edit.getAttribute('data-webhook-edit');
					if (HOOKS[k]) openEditor(HOOKS[k]);
				}
				var test = e.target.closest('[data-webhook-test]');
				if (test) {
					e.preventDefault();
					var k = test.getAttribute('data-webhook-test');
					test.disabled = true; test.textContent = 'Sending…';
					var fd = new FormData();
					fd.append('action', 'therum_webhook_test');
					fd.append('nonce', nonce);
					fd.append('id', k);
					fetch(ajax, { method:'POST', credentials:'same-origin', body: fd })
						.then(function(r){ return r.json(); })
						.then(function(j) {
							test.disabled = false; test.textContent = 'Test';
							if (j && j.success) { test.style.color = 'var(--ok)'; setTimeout(function(){ location.reload(); }, 600); }
							else { test.style.color = 'var(--err)'; alert((j && j.data) || 'Test failed'); }
						});
				}
			});

			$$('[data-webhook-cancel]').forEach(function(b) { b.addEventListener('click', closeEditor); });

			root.addEventListener('change', function(e) {
				var t = e.target;
				if (t.matches('[data-event]')) {
					var pill = t.closest('[data-event-pill]');
					if (pill) pill.classList.toggle('active', t.checked);
				}
			});

			$('[data-webhook-save]').addEventListener('click', function() {
				var btn = this;
				var events = $$('[data-event]:checked').map(function(c) { return c.value; });
				if (events.length === 0) { resultEl.textContent = '✗ Pick at least one event'; resultEl.style.color = 'var(--err)'; return; }

				var fd = new FormData();
				fd.append('action', 'therum_webhook_save');
				fd.append('nonce', nonce);
				fd.append('id',     getVal('[data-webhook-id]'));
				fd.append('name',   getVal('[data-webhook-name]'));
				fd.append('url',    getVal('[data-webhook-url]'));
				fd.append('secret', getVal('[data-webhook-secret]'));
				fd.append('enabled', $('[data-webhook-enabled]').checked ? '1' : '0');
				events.forEach(function(e) { fd.append('events[]', e); });

				btn.disabled = true; btn.style.opacity = '0.6';
				resultEl.textContent = 'Saving…'; resultEl.style.color = 'var(--tx2)';

				fetch(ajax, { method:'POST', credentials:'same-origin', body: fd })
					.then(function(r) { return r.json(); })
					.then(function(j) {
						btn.disabled = false; btn.style.opacity = '';
						if (j && j.success) {
							resultEl.textContent = '✓ ' + (j.data.msg || 'Saved');
							resultEl.style.color = 'var(--ok)';
							setTimeout(function(){ location.reload(); }, 700);
						} else {
							resultEl.textContent = '✗ ' + ((j && j.data) || 'Failed');
							resultEl.style.color = 'var(--err)';
						}
					});
			});

			$('[data-webhook-delete]').addEventListener('click', function() {
				var id = getVal('[data-webhook-id]');
				if (!id) return;
				if (!confirm('Delete this webhook? This cannot be undone.')) return;
				var fd = new FormData();
				fd.append('action', 'therum_webhook_delete');
				fd.append('nonce', nonce);
				fd.append('id', id);
				fetch(ajax, { method:'POST', credentials:'same-origin', body: fd })
					.then(function(r){ return r.json(); })
					.then(function(j) {
						if (j && j.success) {
							resultEl.textContent = '✓ ' + (j.data.msg || 'Deleted');
							resultEl.style.color = 'var(--ok)';
							setTimeout(function(){ location.reload(); }, 600);
						}
					});
			});
		})();
		</script>

		<style>
		.th-webhook-editor {
			background: var(--sf);
			border: 1px solid var(--bd);
			border-radius: 14px;
			padding: 24px 28px;
			margin-top: 16px;
			box-shadow: 0 4px 18px rgba(0,0,0,0.04);
		}
		.th-webhook-editor[hidden] { display: none; }
		.th-webhook-editor-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 18px; }
		.th-webhook-editor-head h3 { margin: 0; font-size: 16px; font-weight: 700; color: var(--tx); }
		</style>
		<?php
	});

	// Format reference
	therum_settings_group( 'Webhook payload format', 'What we send when an event fires.', function() {
		?>
		<div style="background:var(--sf2);border:1px solid var(--bd);border-radius:10px;padding:16px;font-family:monospace;font-size:12px;color:var(--tx);overflow-x:auto;line-height:1.5;">
<pre style="margin:0;">POST {your_url}
Content-Type: application/json
X-Therum-Event: post.published
X-Therum-Hook-Id: wh_xxxxxxxx
X-Therum-Signature: sha256=&lt;hmac&gt;  (if secret set)

{
  "event": "post.published",
  "site": "https://therum.studio",
  "sent_at": "2026-04-28T01:23:45+00:00",
  "data": { ... event-specific fields ... }
}</pre>
		</div>
		<div style="font-size:12px;color:var(--tx3);margin-top:10px;">
			Verify <code>X-Therum-Signature</code> on your endpoint by computing <code>HMAC-SHA256(body, secret)</code> and comparing.
		</div>
		<?php
	});
}


// ════════════════════════════════════════════════════════════════════════════
//  AJAX FAST-PATH — from therum-ajax-fast.php
// ════════════════════════════════════════════════════════════════════════════


// ════════════════════════════════════════════════════════════════════════════
//  2. CONNECTIONS — from therum-connections.php
// ════════════════════════════════════════════════════════════════════════════


// ═════════════════════════════════════════════════════════════════════════════
//  CONNECTOR REGISTRY
// ═════════════════════════════════════════════════════════════════════════════

function therum_connector_registry(): array {
	return [

		// ── CMS ──────────────────────────────────────────────────────────────
		'wordpress' => [
			'id'       => 'wordpress',
			'name'     => 'WordPress',
			'category' => 'cms',
			'color'    => '#21759B',
			'initial'  => 'WP',
			'desc'     => 'This site. Access the REST API and manage application passwords.',
			'built_in' => true,
			'fields'   => [],
			'docs'     => 'https://developer.wordpress.org/rest-api/',
		],
		'drupal' => [
			'id'       => 'drupal',
			'name'     => 'Drupal',
			'category' => 'cms',
			'color'    => '#0678BE',
			'initial'  => 'Dr',
			'desc'     => 'Connect a Drupal 9/10 site via JSON:API for content federation.',
			'built_in' => false,
			'fields'   => [
				[ 'key' => 'site_url',  'label' => 'Site URL',       'type' => 'url',      'placeholder' => 'https://yoursite.com',        'required' => true ],
				[ 'key' => 'api_path',  'label' => 'JSON:API path',  'type' => 'text',     'placeholder' => '/jsonapi',                    'required' => false ],
				[ 'key' => 'username',  'label' => 'Username',        'type' => 'text',     'placeholder' => 'api_user',                    'required' => false ],
				[ 'key' => 'api_key',   'label' => 'Password / Token','type' => 'password', 'placeholder' => '••••••••',                    'required' => false ],
			],
			'docs' => 'https://www.drupal.org/docs/core-modules-and-themes/core-modules/jsonapi-module',
		],
		'ghost' => [
			'id'       => 'ghost',
			'name'     => 'Ghost',
			'category' => 'cms',
			'color'    => '#15171A',
			'initial'  => 'Gh',
			'desc'     => 'Pull Ghost posts and pages using the Content API or Admin API.',
			'built_in' => false,
			'fields'   => [
				[ 'key' => 'admin_url',      'label' => 'Admin URL',           'type' => 'url',      'placeholder' => 'https://yoursite.ghost.io', 'required' => true ],
				[ 'key' => 'content_key',    'label' => 'Content API Key',     'type' => 'password', 'placeholder' => 'Content key from Ghost Admin', 'required' => false ],
				[ 'key' => 'admin_key',      'label' => 'Admin API Key',       'type' => 'password', 'placeholder' => 'id:secret from Ghost Admin',   'required' => false ],
			],
			'docs' => 'https://ghost.org/docs/content-api/',
		],
		'webflow' => [
			'id'       => 'webflow',
			'name'     => 'Webflow',
			'category' => 'cms',
			'color'    => '#4353FF',
			'initial'  => 'Wf',
			'desc'     => 'Read Webflow CMS collections and publish via the Data API.',
			'built_in' => false,
			'fields'   => [
				[ 'key' => 'api_token', 'label' => 'API Token',  'type' => 'password', 'placeholder' => 'API token from Webflow Dashboard', 'required' => true ],
				[ 'key' => 'site_id',   'label' => 'Site ID',    'type' => 'text',     'placeholder' => 'Site ID (optional)',               'required' => false ],
			],
			'docs' => 'https://developers.webflow.com/',
		],
		'contentful' => [
			'id'       => 'contentful',
			'name'     => 'Contentful',
			'category' => 'cms',
			'color'    => '#2478CC',
			'initial'  => 'Cf',
			'desc'     => 'Pull structured content from Contentful spaces via the Delivery API.',
			'built_in' => false,
			'fields'   => [
				[ 'key' => 'space_id',       'label' => 'Space ID',              'type' => 'text',     'placeholder' => 'xxxxxxxxxx',   'required' => true ],
				[ 'key' => 'delivery_token',  'label' => 'Delivery API Key',     'type' => 'password', 'placeholder' => '••••••••',     'required' => true ],
				[ 'key' => 'management_token','label' => 'Management API Token', 'type' => 'password', 'placeholder' => '••••••••',     'required' => false ],
				[ 'key' => 'environment',    'label' => 'Environment',           'type' => 'text',     'placeholder' => 'master',       'required' => false ],
			],
			'docs' => 'https://www.contentful.com/developers/docs/references/content-delivery-api/',
		],

		// ── Ecommerce ─────────────────────────────────────────────────────────
		'woocommerce' => [
			'id'       => 'woocommerce',
			'name'     => 'WooCommerce',
			'category' => 'ecommerce',
			'color'    => '#7F54B3',
			'initial'  => 'WC',
			'desc'     => 'Active on this install. Manage products, orders, and settings.',
			'built_in' => true,
			'fields'   => [],
			'docs'     => 'https://woocommerce.com/document/woocommerce-rest-api/',
		],
		'shopify' => [
			'id'       => 'shopify',
			'name'     => 'Shopify',
			'category' => 'ecommerce',
			'color'    => '#96BF48',
			'initial'  => 'Sh',
			'desc'     => 'Sync products, orders, and customers from a Shopify store.',
			'built_in' => false,
			'fields'   => [
				[ 'key' => 'store_domain',  'label' => 'Store Domain',            'type' => 'text',     'placeholder' => 'yourstore.myshopify.com', 'required' => true ],
				[ 'key' => 'access_token',  'label' => 'Admin API Access Token',  'type' => 'password', 'placeholder' => 'shpat_••••••••',          'required' => true ],
				[ 'key' => 'api_version',   'label' => 'API Version',             'type' => 'text',     'placeholder' => '2024-01',                 'required' => false ],
			],
			'docs' => 'https://shopify.dev/docs/api/admin-rest',
		],
		'amazon' => [
			'id'       => 'amazon',
			'name'     => 'Amazon Seller',
			'category' => 'ecommerce',
			'color'    => '#FF9900',
			'initial'  => 'Az',
			'desc'     => 'Connect your Amazon Seller Central account via SP-API.',
			'built_in' => false,
			'fields'   => [
				[ 'key' => 'client_id',      'label' => 'LWA Client ID',       'type' => 'text',     'placeholder' => 'amzn1.application-oa2-client.xxx', 'required' => true ],
				[ 'key' => 'client_secret',  'label' => 'LWA Client Secret',   'type' => 'password', 'placeholder' => '••••••••', 'required' => true ],
				[ 'key' => 'refresh_token',  'label' => 'Refresh Token',        'type' => 'password', 'placeholder' => 'Atzr|xxx', 'required' => true ],
				[ 'key' => 'marketplace_id', 'label' => 'Marketplace ID',       'type' => 'text',     'placeholder' => 'ATVPDKIKX0DER (US)', 'required' => false ],
			],
			'docs' => 'https://developer-docs.amazon.com/sp-api/',
		],
		'etsy' => [
			'id'       => 'etsy',
			'name'     => 'Etsy',
			'category' => 'ecommerce',
			'color'    => '#F56400',
			'initial'  => 'Et',
			'desc'     => 'Access listings, orders, and shop analytics via Etsy Open API v3.',
			'built_in' => false,
			'fields'   => [
				[ 'key' => 'keystring',     'label' => 'API Key (Keystring)',  'type' => 'password', 'placeholder' => 'From Etsy Developer Portal', 'required' => true ],
				[ 'key' => 'shop_id',       'label' => 'Shop ID',              'type' => 'text',     'placeholder' => 'Your numeric shop ID',       'required' => false ],
			],
			'docs' => 'https://developers.etsy.com/documentation/',
		],
		'square' => [
			'id'       => 'square',
			'name'     => 'Square',
			'category' => 'ecommerce',
			'color'    => '#3E4348',
			'initial'  => 'Sq',
			'desc'     => 'Access Square catalog, orders, and payments via the Connect API.',
			'built_in' => false,
			'fields'   => [
				[ 'key' => 'access_token',  'label' => 'Access Token',    'type' => 'password', 'placeholder' => 'EAAAxx••••••••', 'required' => true ],
				[ 'key' => 'location_id',   'label' => 'Location ID',     'type' => 'text',     'placeholder' => 'From Square Dashboard (optional)', 'required' => false ],
				[ 'key' => 'sandbox',       'label' => 'Sandbox mode',    'type' => 'checkbox', 'placeholder' => '',               'required' => false ],
			],
			'docs' => 'https://developer.squareup.com/docs',
		],

		// ── APIs ──────────────────────────────────────────────────────────────
		'printful' => [
			'id'       => 'printful',
			'name'     => 'Printful',
			'category' => 'apis',
			'color'    => '#FFD100',
			'initial'  => 'Pf',
			'desc'     => 'Print-on-demand fulfillment. Sync products and orders automatically.',
			'built_in' => false,
			'fields'   => [
				[ 'key' => 'api_key',  'label' => 'API Key',   'type' => 'password', 'placeholder' => 'From Printful Dashboard > API', 'required' => true ],
				[ 'key' => 'store_id', 'label' => 'Store ID',  'type' => 'text',     'placeholder' => 'Optional — Printful store ID',  'required' => false ],
			],
			'docs' => 'https://developers.printful.com/',
		],
		'printify' => [
			'id'       => 'printify',
			'name'     => 'Printify',
			'category' => 'apis',
			'color'    => '#28A9E1',
			'initial'  => 'Py',
			'desc'     => 'Print-on-demand via Printify. Pull catalog, push orders.',
			'built_in' => false,
			'fields'   => [
				[ 'key' => 'access_token', 'label' => 'Personal Access Token', 'type' => 'password', 'placeholder' => 'From Printify Account > Connections', 'required' => true ],
				[ 'key' => 'shop_id',      'label' => 'Shop ID',                'type' => 'text',     'placeholder' => 'Printify shop ID', 'required' => false ],
			],
			'docs' => 'https://developers.printify.com/',
		],
		'pod-partner' => [
			'id'       => 'pod-partner',
			'name'     => 'Pod Partner',
			'category' => 'apis',
			'color'    => '#E63946',
			'initial'  => 'PP',
			'desc'     => 'Connect your Pod Partner store for fulfillment and catalog sync.',
			'built_in' => false,
			'fields'   => [
				[ 'key' => 'store_url', 'label' => 'Store URL', 'type' => 'url',      'placeholder' => 'https://yourstore.podpartner.com', 'required' => true ],
				[ 'key' => 'api_key',   'label' => 'API Key',   'type' => 'password', 'placeholder' => '••••••••', 'required' => true ],
			],
			'docs' => '',
		],
		'stripe' => [
			'id'       => 'stripe',
			'name'     => 'Stripe',
			'category' => 'apis',
			'color'    => '#635BFF',
			'initial'  => 'St',
			'desc'     => 'Payments, subscriptions, and invoicing via the Stripe API.',
			'built_in' => false,
			'fields'   => [
				[ 'key' => 'publishable_key', 'label' => 'Publishable Key',    'type' => 'text',     'placeholder' => 'pk_live_••••••••', 'required' => true ],
				[ 'key' => 'secret_key',      'label' => 'Secret Key',         'type' => 'password', 'placeholder' => 'sk_live_••••••••', 'required' => true ],
				[ 'key' => 'webhook_secret',  'label' => 'Webhook Secret',     'type' => 'password', 'placeholder' => 'whsec_••••••••',   'required' => false ],
			],
			'docs' => 'https://stripe.com/docs/api',
		],
		'mailchimp' => [
			'id'       => 'mailchimp',
			'name'     => 'Mailchimp',
			'category' => 'apis',
			'color'    => '#FFE01B',
			'initial'  => 'Mc',
			'desc'     => 'Sync subscribers and trigger automations via the Mailchimp Marketing API.',
			'built_in' => false,
			'fields'   => [
				[ 'key' => 'api_key',   'label' => 'API Key',        'type' => 'password', 'placeholder' => 'xxxx-us1', 'required' => true ],
				[ 'key' => 'server',    'label' => 'Server Prefix',  'type' => 'text',     'placeholder' => 'us1',      'required' => true ],
				[ 'key' => 'list_id',   'label' => 'Audience ID',    'type' => 'text',     'placeholder' => 'Optional default audience', 'required' => false ],
			],
			'docs' => 'https://mailchimp.com/developer/marketing/api/',
		],
		'custom-api' => [
			'id'       => 'custom-api',
			'name'     => 'Custom API',
			'category' => 'apis',
			'color'    => '#6B7280',
			'initial'  => '+',
			'desc'     => 'Any REST endpoint — add a base URL, auth method, and optional headers.',
			'built_in' => false,
			'fields'   => [
				[ 'key' => 'name',        'label' => 'Connection name', 'type' => 'text',     'placeholder' => 'My API',                      'required' => true ],
				[ 'key' => 'base_url',    'label' => 'Base URL',        'type' => 'url',      'placeholder' => 'https://api.example.com/v1',  'required' => true ],
				[ 'key' => 'auth_type',   'label' => 'Auth type',       'type' => 'select',
					'options' => [ 'bearer' => 'Bearer Token', 'api-key' => 'API Key Header', 'basic' => 'Basic Auth', 'none' => 'None' ],
					'required' => false ],
				[ 'key' => 'auth_value',  'label' => 'Auth value',      'type' => 'password', 'placeholder' => 'Token or key', 'required' => false ],
				[ 'key' => 'extra_header','label' => 'Extra header',    'type' => 'text',     'placeholder' => 'X-Header: value',             'required' => false ],
			],
			'docs' => '',
		],
	];
}

function therum_connectors_by_category( string $cat ): array {
	return array_filter( therum_connector_registry(), fn( $c ) => $c['category'] === $cat );
}


// ═════════════════════════════════════════════════════════════════════════════
//  PERSISTENCE
// ═════════════════════════════════════════════════════════════════════════════

function therum_get_connector( string $id ): array {
	$raw = get_option( 'th_connector_' . sanitize_key( $id ), '' );
	if ( ! $raw ) return [];
	$data = json_decode( $raw, true );
	return is_array( $data ) ? $data : [];
}

function therum_save_connector( string $id, array $data ): void {
	update_option( 'th_connector_' . sanitize_key( $id ), wp_json_encode( $data ), false );
}

function therum_connector_is_configured( string $id ): bool {
	$data = therum_get_connector( $id );
	if ( empty( $data['config'] ) ) return false;
	foreach ( $data['config'] as $v ) {
		if ( $v !== '' ) return true;
	}
	return false;
}

function therum_connector_status( array $connector ): array {
	$id = $connector['id'];

	if ( ! empty( $connector['built_in'] ) ) {
		if ( $id === 'woocommerce' && ! class_exists( 'WooCommerce' ) ) {
			return [ 'label' => 'Not installed', 'class' => 'th-conn-status-off' ];
		}
		return [ 'label' => 'Active', 'class' => 'th-conn-status-active' ];
	}

	if ( therum_connector_is_configured( $id ) ) {
		return [ 'label' => 'Connected', 'class' => 'th-conn-status-connected' ];
	}
	return [ 'label' => 'Not configured', 'class' => 'th-conn-status-off' ];
}


// ═════════════════════════════════════════════════════════════════════════════
//  AJAX — save connector config
// ═════════════════════════════════════════════════════════════════════════════

add_action( 'wp_ajax_therum_connector_save', function() {
	if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'forbidden', 403 );
	check_ajax_referer( 'therum_connector', 'nonce' );

	$id = sanitize_key( wp_unslash( $_POST['connector'] ?? '' ) );
	if ( ! $id ) wp_send_json_error( 'no connector id' );

	$registry = therum_connector_registry();
	if ( ! isset( $registry[ $id ] ) ) wp_send_json_error( 'unknown connector' );
	$connector = $registry[ $id ];

	// Built-ins have no saveable config
	if ( ! empty( $connector['built_in'] ) ) wp_send_json_error( 'built-in connector' );

	$posted_config = isset( $_POST['config'] ) && is_array( $_POST['config'] )
		? wp_unslash( $_POST['config'] )
		: [];

	$clean = [];
	foreach ( $connector['fields'] as $field ) {
		$key = $field['key'];
		$val = $posted_config[ $key ] ?? '';

		if ( $field['type'] === 'checkbox' ) {
			$clean[ $key ] = ! empty( $val ) ? '1' : '';
		} elseif ( $field['type'] === 'url' ) {
			$clean[ $key ] = esc_url_raw( $val );
		} elseif ( $field['type'] === 'select' ) {
			$opts = array_keys( $field['options'] ?? [] );
			$clean[ $key ] = in_array( $val, $opts, true ) ? $val : ( $opts[0] ?? '' );
		} else {
			$clean[ $key ] = sanitize_text_field( $val );
		}
	}

	therum_save_connector( $id, [
		'enabled' => true,
		'config'  => $clean,
		'updated' => time(),
	] );

	wp_send_json_success( [ 'msg' => 'Saved', 'id' => $id ] );
} );

add_action( 'wp_ajax_therum_connector_delete', function() {
	if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'forbidden', 403 );
	check_ajax_referer( 'therum_connector', 'nonce' );
	$id = sanitize_key( wp_unslash( $_POST['connector'] ?? '' ) );
	if ( ! $id ) wp_send_json_error( 'no id' );
	delete_option( 'th_connector_' . $id );
	wp_send_json_success( [ 'msg' => 'Disconnected' ] );
} );


// ═════════════════════════════════════════════════════════════════════════════
//  SHARED RENDER HELPERS
// ═════════════════════════════════════════════════════════════════════════════

function therum_conn_styles(): void {
	static $printed = false;
	if ( $printed ) return;
	$printed = true;
	?>
	<style>
	.th-conn-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:12px;margin-top:4px}
	.th-conn-card{background:var(--sf);border:1px solid var(--bd);border-radius:14px;overflow:hidden;transition:box-shadow var(--e)}
	.th-conn-card:hover{box-shadow:0 4px 20px rgba(0,0,0,.06)}
	.th-conn-card-head{display:flex;align-items:flex-start;gap:14px;padding:18px 20px 16px}
	.th-conn-badge{width:44px;height:44px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:13px;color:#fff;flex-shrink:0;letter-spacing:-.02em}
	.th-conn-info{flex:1;min-width:0}
	.th-conn-name{font-size:14px;font-weight:700;color:var(--tx);margin-bottom:2px;display:flex;align-items:center;gap:8px}
	.th-conn-desc{font-size:12px;color:var(--tx2);line-height:1.5;margin-top:2px}
	.th-conn-status{display:inline-flex;align-items:center;gap:5px;font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:.06em;padding:3px 8px;border-radius:20px}
	.th-conn-status::before{content:'';width:6px;height:6px;border-radius:50%;background:currentColor;opacity:.7}
	.th-conn-status-active{background:color-mix(in srgb,#22c55e 12%,transparent);color:#16a34a}
	.th-conn-status-connected{background:color-mix(in srgb,var(--ac) 12%,transparent);color:var(--ac)}
	.th-conn-status-off{background:var(--sf2);color:var(--tx3)}
	.th-conn-foot{display:flex;align-items:center;justify-content:space-between;padding:0 20px 16px;gap:8px}
	.th-conn-foot-meta{font-size:11px;color:var(--tx3)}
	.th-conn-foot-actions{display:flex;gap:6px}
	.th-conn-form{border-top:1px solid var(--bd);padding:18px 20px;display:flex;flex-direction:column;gap:12px;background:var(--sf2)}
	.th-conn-form[hidden]{display:none}
	.th-conn-field{display:flex;flex-direction:column;gap:4px}
	.th-conn-field label{font-size:12px;font-weight:600;color:var(--tx2)}
	.th-conn-field small{font-size:11px;color:var(--tx3);line-height:1.4}
	.th-conn-field-row{display:grid;grid-template-columns:1fr 1fr;gap:10px}
	.th-conn-form-actions{display:flex;align-items:center;justify-content:space-between;padding-top:4px}
	.th-conn-result{font-size:12px;font-weight:500}
	.th-conn-built-in{padding:14px 20px;border-top:1px solid var(--bd);background:var(--sf2);font-size:12px;color:var(--tx2);line-height:1.6}
	.th-conn-built-in code{background:var(--sf);padding:1px 6px;border-radius:4px;font-size:11px}
	.th-conn-docs{font-size:11px;color:var(--tx3);text-decoration:none}
	.th-conn-docs:hover{color:var(--ac)}
	</style>
	<?php
}

function therum_conn_js( string $nonce ): void {
	static $printed = false;
	if ( $printed ) return;
	$printed = true;
	?>
	<script>
	(function() {
		var AJAX  = window.ajaxurl || '/wp-admin/admin-ajax.php';
		var NONCE = <?php echo wp_json_encode( $nonce ); ?>;

		document.addEventListener('click', function(e) {
			// Toggle config form
			var btn = e.target.closest('[data-conn-toggle]');
			if (btn) {
				var card  = btn.closest('[data-connector]');
				var form  = card ? card.querySelector('[data-conn-form]') : null;
				if (!form) return;
				var open = !form.hidden;
				form.hidden = open;
				btn.textContent = open ? (btn.getAttribute('data-label-open') || 'Configure') : 'Cancel';
				if (!open) {
					var first = form.querySelector('input,select,textarea');
					if (first) first.focus();
				}
			}

			// Save
			var save = e.target.closest('[data-conn-save]');
			if (save) {
				var card = save.closest('[data-connector]');
				var id   = card ? card.getAttribute('data-connector') : '';
				if (!id) return;
				var form   = card.querySelector('[data-conn-form]');
				var result = card.querySelector('[data-conn-result]');
				var inputs = form ? form.querySelectorAll('[data-field]') : [];

				var fd = new FormData();
				fd.append('action', 'therum_connector_save');
				fd.append('nonce', NONCE);
				fd.append('connector', id);
				inputs.forEach(function(el) {
					if (el.type === 'checkbox') {
						fd.append('config[' + el.getAttribute('data-field') + ']', el.checked ? '1' : '');
					} else {
						fd.append('config[' + el.getAttribute('data-field') + ']', el.value);
					}
				});

				save.disabled = true;
				save.textContent = 'Saving…';
				if (result) { result.textContent = ''; result.style.color = ''; }

				fetch(AJAX, { method: 'POST', credentials: 'same-origin', body: fd })
					.then(function(r) { return r.json(); })
					.then(function(j) {
						save.disabled = false;
						save.textContent = 'Save';
						if (j && j.success) {
							if (result) { result.textContent = '✓ Saved'; result.style.color = 'var(--ok)'; }
							var dot = card.querySelector('[data-conn-status]');
							if (dot) {
								dot.textContent = 'Connected';
								dot.className = 'th-conn-status th-conn-status-connected';
							}
							setTimeout(function() { if (result) result.textContent = ''; }, 2000);
						} else {
							if (result) { result.textContent = '✗ ' + ((j && j.data) || 'Error'); result.style.color = 'var(--err)'; }
						}
					});
			}

			// Disconnect
			var disc = e.target.closest('[data-conn-disconnect]');
			if (disc) {
				var card = disc.closest('[data-connector]');
				var id   = card ? card.getAttribute('data-connector') : '';
				if (!id || !confirm('Disconnect ' + id + '? Saved credentials will be removed.')) return;

				var fd = new FormData();
				fd.append('action', 'therum_connector_delete');
				fd.append('nonce', NONCE);
				fd.append('connector', id);

				fetch(AJAX, { method: 'POST', credentials: 'same-origin', body: fd })
					.then(function(r) { return r.json(); })
					.then(function(j) {
						if (j && j.success) {
							var dot = card.querySelector('[data-conn-status]');
							if (dot) { dot.textContent = 'Not configured'; dot.className = 'th-conn-status th-conn-status-off'; }
							var form = card.querySelector('[data-conn-form]');
							if (form) { form.querySelectorAll('input[type="password"],input[type="text"],input[type="url"]').forEach(function(i){ i.value=''; }); form.hidden = true; }
							var toggle = card.querySelector('[data-conn-toggle]');
							if (toggle) toggle.textContent = toggle.getAttribute('data-label-open') || 'Configure';
							disc.hidden = true;
						}
					});
			}
		});
	})();
	</script>
	<?php
}

function therum_conn_render_cards( array $connectors, string $nonce ): void {
	therum_conn_styles();
	therum_conn_js( $nonce );
	?>
	<div class="th-conn-grid">
	<?php foreach ( $connectors as $connector ):
		$id      = $connector['id'];
		$status  = therum_connector_status( $connector );
		$saved   = therum_get_connector( $id );
		$config  = $saved['config'] ?? [];
		$is_configured = therum_connector_is_configured( $id );
		$is_builtin    = ! empty( $connector['built_in'] );
		$updated = ! empty( $saved['updated'] ) ? human_time_diff( $saved['updated'] ) . ' ago' : null;
	?>
	<div class="th-conn-card" data-connector="<?php echo esc_attr( $id ); ?>">

		<div class="th-conn-card-head">
			<div class="th-conn-badge" style="background:<?php echo esc_attr( $connector['color'] ); ?>">
				<?php echo esc_html( $connector['initial'] ); ?>
			</div>
			<div class="th-conn-info">
				<div class="th-conn-name">
					<?php echo esc_html( $connector['name'] ); ?>
					<span class="th-conn-status <?php echo esc_attr( $status['class'] ); ?>" data-conn-status>
						<?php echo esc_html( $status['label'] ); ?>
					</span>
				</div>
				<div class="th-conn-desc"><?php echo esc_html( $connector['desc'] ); ?></div>
			</div>
		</div>

		<?php if ( $is_builtin ): ?>

		<div class="th-conn-built-in">
			<?php if ( $id === 'wordpress' ): ?>
				REST API: <code><?php echo esc_html( rest_url() ); ?></code><br>
				<a href="<?php echo esc_url( admin_url( 'profile.php#application-passwords-section' ) ); ?>" style="color:var(--ac)">Manage application passwords →</a>
			<?php elseif ( $id === 'woocommerce' && class_exists( 'WooCommerce' ) ): ?>
				Version: <code><?php echo esc_html( WC()->version ); ?></code> &nbsp;|&nbsp;
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=wc-settings&tab=advanced&section=keys' ) ); ?>" style="color:var(--ac)">REST API keys →</a>
			<?php endif; ?>
		</div>

		<?php else: ?>

		<div class="th-conn-foot">
			<span class="th-conn-foot-meta">
				<?php if ( $updated ): ?>Updated <?php echo esc_html( $updated ); ?><?php endif; ?>
				<?php if ( ! empty( $connector['docs'] ) ): ?>
					<a href="<?php echo esc_url( $connector['docs'] ); ?>" target="_blank" class="th-conn-docs" style="margin-left:<?php echo $updated ? '8px' : '0'; ?>">Docs ↗</a>
				<?php endif; ?>
			</span>
			<div class="th-conn-foot-actions">
				<?php if ( $is_configured ): ?>
					<button type="button" class="th-button" style="font-size:11px;padding:4px 10px;color:var(--err);border-color:color-mix(in srgb,var(--err) 30%,transparent)" data-conn-disconnect>Disconnect</button>
				<?php endif; ?>
				<button type="button" class="th-button <?php echo $is_configured ? '' : 'th-button-primary'; ?>" style="font-size:11px;padding:4px 10px" data-conn-toggle data-label-open="<?php echo $is_configured ? 'Edit' : 'Connect'; ?>">
					<?php echo $is_configured ? 'Edit' : 'Connect'; ?>
				</button>
			</div>
		</div>

		<div class="th-conn-form" data-conn-form hidden>
			<?php foreach ( $connector['fields'] as $field ):
				$val = $config[ $field['key'] ] ?? '';
			?>
			<div class="th-conn-field">
				<label><?php echo esc_html( $field['label'] ); ?><?php if ( ! empty( $field['required'] ) ) echo ' <span style="color:var(--err)">*</span>'; ?></label>
				<?php if ( $field['type'] === 'select' ): ?>
					<select class="th-input" data-field="<?php echo esc_attr( $field['key'] ); ?>">
						<?php foreach ( $field['options'] as $opt_val => $opt_label ): ?>
							<option value="<?php echo esc_attr( $opt_val ); ?>" <?php selected( $val, $opt_val ); ?>><?php echo esc_html( $opt_label ); ?></option>
						<?php endforeach; ?>
					</select>
				<?php elseif ( $field['type'] === 'checkbox' ): ?>
					<label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-weight:400">
						<input type="checkbox" data-field="<?php echo esc_attr( $field['key'] ); ?>" <?php checked( $val, '1' ); ?>>
						<?php echo esc_html( $field['placeholder'] ?: $field['label'] ); ?>
					</label>
				<?php else: ?>
					<input
						type="<?php echo esc_attr( $field['type'] ); ?>"
						class="th-input"
						data-field="<?php echo esc_attr( $field['key'] ); ?>"
						value="<?php echo $field['type'] === 'password' ? ( $val ? '••••••••' : '' ) : esc_attr( $val ); ?>"
						placeholder="<?php echo esc_attr( $field['placeholder'] ?? '' ); ?>"
						autocomplete="off"
					>
				<?php endif; ?>
			</div>
			<?php endforeach; ?>

			<div class="th-conn-form-actions">
				<span class="th-conn-result" data-conn-result></span>
				<div style="display:flex;gap:6px">
					<button type="button" class="th-button" style="font-size:12px" data-conn-toggle data-label-open="<?php echo $is_configured ? 'Edit' : 'Connect'; ?>">Cancel</button>
					<button type="button" class="th-button th-button-primary" style="font-size:12px" data-conn-save>Save</button>
				</div>
			</div>
		</div>

		<?php endif; ?>
	</div>
	<?php endforeach; ?>
	</div>
	<?php
}


// ═════════════════════════════════════════════════════════════════════════════
//  RENDER — three settings sections
// ═════════════════════════════════════════════════════════════════════════════

function therum_render_connections_cms(): void {
	$nonce = wp_create_nonce( 'therum_connector' );
	therum_settings_group( 'CMS Connections', 'Connect content management platforms to federate or migrate data.', function() use ( $nonce ) {
		therum_conn_render_cards( therum_connectors_by_category( 'cms' ), $nonce );
	} );
}

function therum_render_connections_ecommerce(): void {
	$nonce = wp_create_nonce( 'therum_connector' );
	therum_settings_group( 'Ecommerce Connections', 'Link storefronts for product sync, order management, and fulfillment routing.', function() use ( $nonce ) {
		therum_conn_render_cards( therum_connectors_by_category( 'ecommerce' ), $nonce );
	} );
}

function therum_render_connections_apis(): void {
	$nonce = wp_create_nonce( 'therum_connector' );
	therum_settings_group( 'API Connections', 'Third-party services — fulfillment, payments, email, and custom endpoints.', function() use ( $nonce ) {
		therum_conn_render_cards( therum_connectors_by_category( 'apis' ), $nonce );
	} );
}


// ═════════════════════════════════════════════════════════════════════════════
//  REGISTER SETTINGS SECTIONS
// ═════════════════════════════════════════════════════════════════════════════

// Connect-X entries moved out of Settings into Connections > Connectors. The
// live registry is driven by therum_cn_render_{ai,apis,payments,apps} (see
// the Tab Registrations block below) plus therum_render_connections_{cms,
// ecommerce} for the legacy Settings-side surface. State for each card comes
// from the `therum_connectors` option via therum_cn_apply_saved_state().

/**
 * AI connectors renderer. Wraps the real card-grid renderer
 * therum_cn_render_ai() in a settings group so it can mount under the legacy
 * Settings → Connections → AI surface. Card state is driven by the saved
 * `therum_connectors` option via therum_cn_apply_saved_state(); the cards
 * themselves are defined in therum_cn_render_ai().
 */
function therum_render_connections_ai(): void {
	therum_settings_group(
		'AI Connections',
		'Frontier language models, voice synthesis, and local runtimes. Connect a provider to make it available across Therum surfaces (Copilot, Studio, content generation).',
		function() {
			therum_cn_render_ai( 'ai', [ 'desc' => '' ] );
		}
	);
}

// On WP 7.0+, hide WP's top-level "Connectors" menu since the registry is
// absorbed into Therum Settings → Connections → AI. Best-effort slug match
// — refined after GA confirms the exact menu_slug WP ships with.
add_action( 'admin_menu', function() {
	$wp_version = function_exists( 'get_bloginfo' ) ? get_bloginfo( 'version' ) : '0';
	if ( version_compare( $wp_version, '7.0', '<' ) ) return;
	foreach ( [ 'wp-connectors', 'connectors', 'wp_connectors' ] as $slug ) {
		remove_menu_page( $slug );
	}
}, 999 );


// ═════════════════════════════════════════════════════════════════════════════
//  THERUM_CONNECTIONS_PAGE — top-level admin route `?page=therum-connections`
//
//  Mirrors Therum_Customization shape: registry + inner-nav router + tab
//  renderers. Restructures the connector surface into 4 buckets + 3 manage
//  pages per the 1.8.6 preview. Settings-side connector sections (the older
//  cms/ecommerce/apis/ai registrations above) remain registered for users
//  who navigate there directly — but the canonical surface is this page.
// ═════════════════════════════════════════════════════════════════════════════

// @deprecated — this class body is gated by class_exists() and is never the
// active one. The live Therum_Connections_Page lives in therum-admin.php and
// drives the ?page=therum-connections surface via providers() / categories().
// The Therum_Connections_Page::register() calls below + therum_render_connections_*
// renderers are orphan code retained for backward compat with any external
// plugin that may have hooked them. Schedule full removal once verified safe.

// ═════════════════════════════════════════════════════════════════════════════
//  ADMIN ROUTE + ASSETS  — REMOVED (was duplicating therum-admin.php's
//  registration of therum-connections, which already wires Therum_Connections_Page).
//  See therum-admin.php around line 4682. Kept the section header so the merge
//  history stays legible.
// ═════════════════════════════════════════════════════════════════════════════

// ═════════════════════════════════════════════════════════════════════════════
//  TAB REGISTRATIONS (Phase 3 connectors + Phase 4 manage stubs)
// ═════════════════════════════════════════════════════════════════════════════

add_action( 'init', function() {
	if ( ! class_exists( 'Therum_Connections_Page' ) ) return;

	Therum_Connections_Page::register( 'cms', [
		'label'    => 'Connect CMS',
		'section'  => 'connectors',
		'priority' => 5,
		'dot'      => '#0ea5e9',
		'desc'     => 'WordPress, Drupal, Ghost, Webflow, Contentful — Therum reads + writes content through these adapters.',
		'render'   => 'therum_render_connections_cms',
	] );

	Therum_Connections_Page::register( 'ai', [
		'label'    => 'AI Tools',
		'section'  => 'connectors',
		'priority' => 10,
		'count'    => '2 / 12',
		'dot'      => '#10a37f',
		'desc'     => 'Language models, voice, image, and code copilots you can query from the dashboard or any Therum page. Bring your own API key or OAuth credentials.',
		'render'   => 'therum_cn_render_ai',
	] );

	Therum_Connections_Page::register( 'apis', [
		'label'    => 'APIs',
		'section'  => 'connectors',
		'priority' => 20,
		'count'    => '1 / 12',
		'dot'      => '#1a82e2',
		'desc'     => 'General-purpose service endpoints — email, SMS, push, webhooks, custom REST. Add as many as you need.',
		'render'   => 'therum_cn_render_apis',
	] );

	Therum_Connections_Page::register( 'ecommerce', [
		'label'    => 'Connect Ecommerce',
		'section'  => 'connectors',
		'priority' => 25,
		'dot'      => '#f59e0b',
		'desc'     => 'WooCommerce, Shopify, BigCommerce, Amazon, Etsy, Square, Wix — pull orders, products, customers, inventory into Therum.',
		'render'   => 'therum_render_connections_ecommerce',
	] );

	Therum_Connections_Page::register( 'payments', [
		'label'    => 'Payment Gateways',
		'section'  => 'connectors',
		'priority' => 30,
		'count'    => '1 / 12',
		'dot'      => '#635bff',
		'desc'     => 'Money in / money out. Account dashboards embed directly inside Therum once OAuth completes.',
		'render'   => 'therum_cn_render_payments',
	] );

	Therum_Connections_Page::register( 'apps', [
		'label'    => 'External Apps',
		'section'  => 'connectors',
		'priority' => 40,
		'count'    => '1 / 18',
		'dot'      => '#a855f7',
		'desc'     => 'Productivity, design, dev, and collaboration. Therum reads + renders their data inside the chrome via OAuth.',
		'render'   => 'therum_cn_render_apps',
	] );

	// Phase-4 tabs (keys / webhooks / audit) were previously registered here
	// with stub renderers (render_stub() emits "Phase 4 · scaffold — Lands in
	// Phase 4 — port from previews/…"). Hidden from the live nav until the
	// real renderers ship. To re-enable, register with an explicit `render`
	// callback pointing at the implementation.

	// Merged in from Settings > API & Webhooks. Renderer lives in therum-api.php
	// (therum_render_api_full) and covers REST API kill switch, auth, CORS,
	// headless mode, and outbound webhooks builder (HMAC-signed, 11 events).
	Therum_Connections_Page::register( 'rest', [
		'label'    => 'API & Webhooks',
		'section'  => 'manage',
		'priority' => 50,
		'desc'     => 'REST API surface, headless mode, CORS origins, outbound webhooks.',
		'render'   => function_exists( 'therum_render_api_full' ) ? 'therum_render_api_full' : 'therum_render_api_webhooks',
	] );
}, 20 );

// ═════════════════════════════════════════════════════════════════════════════
//  CONNECTOR CARD RENDERERS — Phase 3
// ═════════════════════════════════════════════════════════════════════════════

function therum_cn_render_ai( string $tab_id, array $tab ): void {
	therum_cn_page_head( 'AI Tools', $tab['desc'], '＋ Add AI provider' );
	therum_cn_render_card_grid( 'ai', [
		// Language models — frontier
		[ 'id' => 'anthropic',  'name' => 'Anthropic · Claude',   'icon' => 'A', 'bg' => '#cc785c', 'desc' => 'claude-sonnet-4.5 · 200k context · function calling',          'state' => 'not_connected', 'meta' => '', 'auth' => 'API key' ],
		[ 'id' => 'openai',     'name' => 'OpenAI · ChatGPT',     'icon' => 'O', 'bg' => '#10a37f', 'desc' => 'gpt-5-turbo · 128k context · vision · code interpreter',     'state' => 'not_connected', 'meta' => '', 'auth' => 'API key' ],
		[ 'id' => 'google-ai',  'name' => 'Google AI · Gemini',   'icon' => 'G', 'bg' => '#4285f4', 'desc' => 'gemini-2.5-pro · 2M context · multimodal native',           'state' => 'not_connected', 'meta' => '', 'auth' => 'OAuth' ],
		[ 'id' => 'xai',        'name' => 'xAI · Grok',           'icon' => 'X', 'bg' => '#000000', 'desc' => 'grok-4 · 256k context · real-time X integration',           'state' => 'not_connected', 'meta' => '', 'auth' => 'API key' ],
		[ 'id' => 'mistral',    'name' => 'Mistral',              'icon' => 'M', 'bg' => '#ff7000', 'desc' => 'mistral-large · open-weight EU host · code + general',      'state' => 'not_connected', 'meta' => '', 'auth' => 'API key' ],
		[ 'id' => 'deepseek',   'name' => 'DeepSeek',             'icon' => 'D', 'bg' => '#4d6bfe', 'desc' => 'deepseek-v3 · 671B MoE · strong reasoning at low cost',     'state' => 'not_connected', 'meta' => '', 'auth' => 'API key' ],
		[ 'id' => 'perplexity', 'name' => 'Perplexity',           'icon' => 'P', 'bg' => '#20b8cd', 'desc' => 'Online-grounded answers · citations · search-aware LLM',    'state' => 'not_connected', 'meta' => '', 'auth' => 'API key' ],
		[ 'id' => 'cohere',     'name' => 'Cohere',               'icon' => 'C', 'bg' => '#ff7759', 'desc' => 'Command R+ · enterprise RAG · embedding · rerank',          'state' => 'not_connected', 'meta' => '', 'auth' => 'API key' ],
		[ 'id' => 'groq',       'name' => 'Groq',                 'icon' => 'Q', 'bg' => '#f55036', 'desc' => 'LPU inference · 500+ tok/s · llama, mixtral, qwen',         'state' => 'not_connected', 'meta' => '', 'auth' => 'API key' ],
		// Local + media
		[ 'id' => 'ollama',     'name' => 'Local · Ollama',       'icon' => 'L', 'bg' => 'linear-gradient(135deg,#8b5cf6,#3b82f6)', 'desc' => 'llama · mistral · qwen — runs on the same host as Therum', 'state' => 'not_connected', 'meta' => '', 'auth' => 'localhost' ],
		[ 'id' => 'elevenlabs', 'name' => 'ElevenLabs · Voice',   'icon' => 'V', 'bg' => '#0a0a0a', 'desc' => 'TTS · voice clones · multilingual · audio for posts',       'state' => 'not_connected', 'meta' => '', 'auth' => 'API key' ],
		[ 'id' => 'huggingface','name' => 'Hugging Face',         'icon' => 'H', 'bg' => '#ffd21e', 'color' => '#000', 'desc' => 'Inference API · custom model endpoints · datasets',   'state' => 'not_connected', 'meta' => '', 'auth' => 'API key' ],
	] );
}

function therum_cn_render_apis( string $tab_id, array $tab ): void {
	therum_cn_page_head( 'APIs', $tab['desc'], '＋ Add custom API' );
	therum_cn_render_card_grid( 'apis', [
		// Email — transactional + marketing
		[ 'id' => 'mailchimp', 'name' => 'Mailchimp', 'icon' => 'M', 'bg' => '#ffe01b', 'color' => '#000', 'desc' => 'Audiences · campaigns · automations.', 'state' => 'not_connected', 'meta' => '', 'auth' => 'API key' ],
		[ 'id' => 'sendgrid',  'name' => 'SendGrid',                  'icon' => 'S', 'bg' => '#1a82e2', 'desc' => 'Transactional email · templates · delivery analytics',           'state' => 'not_connected', 'meta' => '', 'auth' => 'API key' ],
		[ 'id' => 'postmark',  'name' => 'Postmark',                  'icon' => 'P', 'bg' => '#ffde00', 'color' => '#000', 'desc' => 'Fastest transactional delivery · separate streams',  'state' => 'not_connected', 'meta' => '', 'auth' => 'API key' ],
		[ 'id' => 'resend',    'name' => 'Resend',                    'icon' => 'R', 'bg' => '#000000', 'desc' => 'Developer-first email · React templates · webhooks',             'state' => 'not_connected', 'meta' => '', 'auth' => 'API key' ],
		[ 'id' => 'mailgun',   'name' => 'Mailgun',                   'icon' => 'M', 'bg' => '#f06b66', 'desc' => 'Sending + receiving · routing · validation',                     'state' => 'not_connected', 'meta' => '', 'auth' => 'API key' ],
		[ 'id' => 'brevo',     'name' => 'Brevo · Sendinblue',        'icon' => 'B', 'bg' => '#0b996e', 'desc' => 'Email + SMS + chat · CRM-aware campaigns',                       'state' => 'not_connected', 'meta' => '', 'auth' => 'API key' ],
		// SMS / voice
		[ 'id' => 'twilio',    'name' => 'Twilio',                    'icon' => 'T', 'bg' => '#f22f46', 'desc' => 'SMS · voice · WhatsApp · two-factor codes',                      'state' => 'not_connected', 'meta' => '', 'auth' => 'API key' ],
		[ 'id' => 'vonage',    'name' => 'Vonage',                    'icon' => 'V', 'bg' => '#000000', 'desc' => 'Global SMS · voice · verify · video API',                        'state' => 'not_connected', 'meta' => '', 'auth' => 'API key' ],
		// Push / chat
		[ 'id' => 'onesignal', 'name' => 'OneSignal',                 'icon' => 'O', 'bg' => '#e54b4d', 'desc' => 'Push notifications · in-app · email · SMS — one API',           'state' => 'not_connected', 'meta' => '', 'auth' => 'API key' ],
		[ 'id' => 'discord',   'name' => 'Discord webhook',           'icon' => 'D', 'bg' => '#5865f2', 'desc' => 'Post to a channel · embed events · zero auth flow',              'state' => 'not_connected', 'meta' => '', 'auth' => 'Webhook URL' ],
		[ 'id' => 'telegram',  'name' => 'Telegram bot',              'icon' => 'T', 'bg' => '#26a5e4', 'desc' => 'Direct message · group post · inline keyboards',                 'state' => 'not_connected', 'meta' => '', 'auth' => 'Bot token' ],
		[ 'id' => 'custom',    'name' => 'Custom endpoint',           'icon' => '＋', 'bg' => 'transparent', 'border' => true, 'desc' => 'Wire any REST / webhook URL. HMAC signing · retry · headers.',  'state' => 'not_connected', 'meta' => '', 'auth' => 'URL + key' ],
	] );
}

function therum_cn_render_payments( string $tab_id, array $tab ): void {
	therum_cn_page_head( 'Payment Gateways', $tab['desc'], '＋ Add gateway' );
	therum_cn_render_card_grid( 'payments', [
		// Cards + accounts
		[ 'id' => 'stripe',       'name' => 'Stripe',          'icon' => 'S', 'bg' => '#635bff', 'desc' => 'Stripe Connect · balance · payouts',                                'state' => 'not_connected', 'meta' => '', 'auth' => 'OAuth' ],
		[ 'id' => 'plaid',        'name' => 'Plaid',           'icon' => 'P', 'bg' => '#000000', 'desc' => 'Bank account linking · ACH · balance lookups.',                     'state' => 'not_connected', 'meta' => '', 'auth' => 'OAuth' ],
		[ 'id' => 'square',       'name' => 'Square',          'icon' => 'S', 'bg' => '#000000', 'border' => true, 'desc' => 'Seller dashboard · in-person + online payments',  'state' => 'not_connected', 'meta' => '', 'auth' => 'OAuth' ],
		[ 'id' => 'paypal',       'name' => 'PayPal',          'icon' => 'P', 'bg' => '#0070ba', 'desc' => 'Standard checkout · subscriptions · payouts',                      'state' => 'not_connected', 'meta' => '', 'auth' => 'OAuth' ],
		[ 'id' => 'braintree',    'name' => 'Braintree',       'icon' => 'B', 'bg' => '#0070ba', 'desc' => 'Full-stack payments by PayPal · cards · Venmo · Apple/Google Pay', 'state' => 'not_connected', 'meta' => '', 'auth' => 'OAuth' ],
		[ 'id' => 'adyen',        'name' => 'Adyen',           'icon' => 'A', 'bg' => '#0abf53', 'desc' => 'Global enterprise gateway · 250+ payment methods',                  'state' => 'not_connected', 'meta' => '', 'auth' => 'API key' ],
		[ 'id' => 'authorize',    'name' => 'Authorize.Net',   'icon' => 'A', 'bg' => '#1b3a6b', 'desc' => 'Legacy US gateway · ACH · recurring billing',                      'state' => 'not_connected', 'meta' => '', 'auth' => 'API key' ],
		[ 'id' => 'mollie',       'name' => 'Mollie',          'icon' => 'M', 'bg' => '#0e1c2b', 'desc' => 'EU-first · iDEAL · SEPA · Bancontact · Klarna handoff',             'state' => 'not_connected', 'meta' => '', 'auth' => 'API key' ],
		// BNPL
		[ 'id' => 'klarna',       'name' => 'Klarna',          'icon' => 'K', 'bg' => '#ffaeb5', 'color' => '#000', 'desc' => 'Pay in 4 · BNPL · in-checkout financing',           'state' => 'not_connected', 'meta' => '', 'auth' => 'OAuth' ],
		[ 'id' => 'affirm',       'name' => 'Affirm',          'icon' => 'A', 'bg' => '#0a0a23', 'desc' => 'Larger-ticket installments · soft credit pull at checkout',         'state' => 'not_connected', 'meta' => '', 'auth' => 'OAuth' ],
		// Crypto + alternative
		[ 'id' => 'coinbase',     'name' => 'Coinbase Commerce', 'icon' => 'C', 'bg' => '#0052ff', 'desc' => 'Accept BTC · ETH · USDC · settle in fiat or crypto',              'state' => 'not_connected', 'meta' => '', 'auth' => 'API key' ],
		[ 'id' => 'razorpay',     'name' => 'Razorpay',        'icon' => 'R', 'bg' => '#02042a', 'desc' => 'India-first · UPI · cards · netbanking · payouts',                 'state' => 'not_connected', 'meta' => '', 'auth' => 'API key' ],
	] );
}

function therum_cn_render_apps( string $tab_id, array $tab ): void {
	therum_cn_page_head( 'External Apps', $tab['desc'], '＋ Add custom app' );
	therum_cn_render_card_grid( 'apps', [
		// Docs + workspace
		[ 'id' => 'notion',     'name' => 'Notion',            'icon' => 'N', 'bg' => '#ffffff', 'color' => '#000', 'desc' => 'Workspaces · databases · pages — rendered natively in Therum', 'state' => 'not_connected', 'meta' => '', 'auth' => 'OAuth' ],
		[ 'id' => 'airtable',   'name' => 'Airtable',          'icon' => 'A', 'bg' => '#fcb400', 'color' => '#000', 'desc' => 'Bases · tables · views — embed any base as a Therum block',    'state' => 'not_connected', 'meta' => '', 'auth' => 'OAuth' ],
		[ 'id' => 'gdrive',     'name' => 'Google Drive',      'icon' => 'G', 'bg' => '#4285f4', 'desc' => 'Docs · Sheets · Slides · files — read into Therum surfaces',     'state' => 'not_connected', 'meta' => '', 'auth' => 'OAuth' ],
		[ 'id' => 'dropbox',    'name' => 'Dropbox',           'icon' => 'D', 'bg' => '#0061ff', 'desc' => 'Files · Paper · sign — pull assets into Therum media',           'state' => 'not_connected', 'meta' => '', 'auth' => 'OAuth' ],
		// Communication
		[ 'id' => 'slack',      'name' => 'Slack',             'icon' => 'S', 'bg' => '#4a154b', 'desc' => 'Notifications · channel posting · slash commands',               'state' => 'not_connected', 'meta' => '', 'auth' => 'OAuth' ],
		[ 'id' => 'teams',      'name' => 'Microsoft Teams',   'icon' => 'T', 'bg' => '#5059c9', 'desc' => 'Channel posts · adaptive cards · bot replies',                   'state' => 'not_connected', 'meta' => '', 'auth' => 'OAuth' ],
		[ 'id' => 'discord-app','name' => 'Discord',           'icon' => 'D', 'bg' => '#5865f2', 'desc' => 'Server · channels · slash commands · presence',                  'state' => 'not_connected', 'meta' => '', 'auth' => 'OAuth' ],
		[ 'id' => 'zoom',       'name' => 'Zoom',              'icon' => 'Z', 'bg' => '#2d8cff', 'desc' => 'Meetings · webinars · recording links',                          'state' => 'not_connected', 'meta' => '', 'auth' => 'OAuth' ],
		// Design + dev
		[ 'id' => 'figma',      'name' => 'Figma',             'icon' => 'F', 'bg' => '#0d0d0d', 'desc' => 'Files · frames · variables — design tokens sync',                'state' => 'not_connected', 'meta' => '', 'auth' => 'OAuth' ],
		[ 'id' => 'github',     'name' => 'GitHub',            'icon' => 'G', 'bg' => '#0d1117', 'desc' => 'Repos · issues · PRs · Actions · releases',                      'state' => 'not_connected', 'meta' => '', 'auth' => 'OAuth' ],
		[ 'id' => 'gitlab',     'name' => 'GitLab',            'icon' => 'G', 'bg' => '#fc6d26', 'desc' => 'Repos · pipelines · merge requests',                             'state' => 'not_connected', 'meta' => '', 'auth' => 'OAuth' ],
		// Project mgmt
		[ 'id' => 'linear',     'name' => 'Linear',            'icon' => 'L', 'bg' => '#5e6ad2', 'desc' => 'Issues · projects · cycles — embed assigned issues on dashboard', 'state' => 'not_connected', 'meta' => '', 'auth' => 'OAuth' ],
		[ 'id' => 'asana',      'name' => 'Asana',             'icon' => 'A', 'bg' => '#f06a6a', 'desc' => 'Tasks · projects · timeline — sync into Therum lists',           'state' => 'not_connected', 'meta' => '', 'auth' => 'OAuth' ],
		[ 'id' => 'clickup',    'name' => 'ClickUp',           'icon' => 'C', 'bg' => '#7b68ee', 'desc' => 'Workspaces · spaces · lists · docs · automations',               'state' => 'not_connected', 'meta' => '', 'auth' => 'OAuth' ],
		// Scheduling + CRM
		[ 'id' => 'calendly',   'name' => 'Calendly',          'icon' => 'C', 'bg' => '#006bff', 'desc' => 'Bookings · event types · embed scheduler in Therum pages',       'state' => 'not_connected', 'meta' => '', 'auth' => 'OAuth' ],
		[ 'id' => 'hubspot',    'name' => 'HubSpot',           'icon' => 'H', 'bg' => '#ff7a59', 'desc' => 'Contacts · deals · marketing · service hub',                     'state' => 'not_connected', 'meta' => '', 'auth' => 'OAuth' ],
		// Automation
		[ 'id' => 'zapier',     'name' => 'Zapier',            'icon' => 'Z', 'bg' => '#ff4a00', 'desc' => 'Trigger Zaps on Therum events · 6,000+ apps downstream',          'state' => 'not_connected', 'meta' => '', 'auth' => 'Webhook' ],
		[ 'id' => 'make',       'name' => 'Make · Integromat', 'icon' => 'M', 'bg' => '#6d00cc', 'desc' => 'Visual scenarios · branching · scheduled flows',                  'state' => 'not_connected', 'meta' => '', 'auth' => 'Webhook' ],
		[ 'id' => 'request',    'name' => 'Request a connector', 'icon' => '＋', 'bg' => 'transparent', 'border' => true, 'desc' => "Need an app we don't have yet? Drop the URL — we'll wire it up.", 'state' => 'not_connected', 'meta' => '', 'auth' => 'Form' ],
	] );
}

/**
 * Shared page-head renderer used by every Connections tab.
 */
function therum_cn_page_head( string $title, string $desc, string $action_label ): void {
	?>
	<div class="th-cx-page-head">
		<div>
			<div class="th-cx-page-eyebrow">Connections · <?php echo esc_html( $title ); ?></div>
			<h2 class="th-cx-page-title"><?php echo esc_html( $title ); ?></h2>
			<p class="th-cx-page-sub"><?php echo esc_html( $desc ); ?></p>
		</div>
		<div style="display:flex;gap:8px">
			<label class="th-cx-search">
				<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
				<input type="search" placeholder="Search…" data-th-cn-search />
			</label>
			<button type="button" class="th-cx-btn is-primary"><?php echo esc_html( $action_label ); ?></button>
		</div>
	</div>
	<?php
}

/**
 * Mark cards whose connector id appears in the saved connections map.
 * Saved map shape: [ bucket => [ id => [ 'auth' => ..., 'value' => ..., 'ts' => ... ] ] ].
 */
function therum_cn_apply_saved_state( string $bucket, array $cards ): array {
	$saved = (array) get_option( 'therum_connectors', [] );
	$bucket_map = $saved[ $bucket ] ?? [];
	foreach ( $cards as &$c ) {
		$id = $c['id'] ?? '';
		if ( $id && isset( $bucket_map[ $id ] ) ) {
			$c['state'] = 'connected';
			if ( empty( $c['meta'] ) ) {
				$ts = (int) ( $bucket_map[ $id ]['ts'] ?? 0 );
				$c['meta'] = $ts ? 'linked ' . human_time_diff( $ts, time() ) . ' ago' : 'connected';
			}
		}
	}
	unset( $c );
	return $cards;
}

/**
 * Render a 4–18 card grid of connectors for one bucket. Cards become
 * clickable — clicking a non-connected card opens the auth modal.
 */
function therum_cn_render_card_grid( string $bucket, array $cards ): void {
	$cards = therum_cn_apply_saved_state( $bucket, $cards );
	?>
	<div class="th-cn-grid" data-th-cn-bucket="<?php echo esc_attr( $bucket ); ?>">
		<?php foreach ( $cards as $c ):
			$state = $c['state'] ?? 'not_connected';
			$cls   = 'th-cn-card';
			if ( $state === 'connected' ) $cls .= ' is-connected';
			if ( $state === 'reauth' )    $cls .= ' has-error';
			$icon_style = 'background:' . ( $c['bg'] ?? '#444' ) . ';';
			if ( ! empty( $c['color'] ) ) $icon_style .= 'color:' . $c['color'] . ';';
			if ( ! empty( $c['border'] ) ) $icon_style .= 'border:1px dashed rgba(255,255,255,.18);color:var(--tx3);';
		?>
		<div class="<?php echo esc_attr( $cls ); ?>" data-th-cn-card data-th-cn-id="<?php echo esc_attr( $c['id'] ?? '' ); ?>" data-th-cn-bucket="<?php echo esc_attr( $bucket ); ?>" data-th-cn-auth="<?php echo esc_attr( $c['auth'] ?? '' ); ?>" data-name="<?php echo esc_attr( strtolower( $c['name'] ) ); ?>">
			<span class="th-cn-card-status">
				<span class="th-cn-card-status-dot"></span>
				<?php
					switch ( $state ) {
						case 'connected':    echo 'Connected'; break;
						case 'reauth':       echo 'Reauth needed'; break;
						default:             echo 'Not connected';
					}
				?>
			</span>
			<div class="th-cn-card-icon" style="<?php echo esc_attr( $icon_style ); ?>"><?php echo esc_html( $c['icon'] ); ?></div>
			<h3 class="th-cn-card-name"><?php echo esc_html( $c['name'] ); ?></h3>
			<p class="th-cn-card-desc"><?php echo esc_html( $c['desc'] ?? '' ); ?></p>
			<div class="th-cn-card-foot">
				<?php
					$cta = $state === 'connected' ? 'Manage →' : ( $state === 'reauth' ? 'Reconnect →' : ( $c['name'] === 'Request a connector' ? 'Request →' : 'Connect →' ) );
				?>
				<span class="th-cn-card-cta"><?php echo esc_html( $cta ); ?></span>
				<span class="th-cn-card-meta"><?php echo esc_html( $c['meta'] ?: ( $c['auth'] ?? '' ) ); ?></span>
			</div>
		</div>
		<?php endforeach; ?>
	</div>
	<?php
}


// ═════════════════════════════════════════════════════════════════════════════
//  PHASE 4 — MANAGE PAGES (Vault · Webhooks · Audit)
//
//  Renderers receive demo data for Phase 4; Phase 5/6 wires them to real
//  storage (encrypted options for keys, transient log buffer for webhooks,
//  custom table for audit). Visual surfaces are ship-ready against either.
// ═════════════════════════════════════════════════════════════════════════════

// Wire the renderers into the existing tab registrations
add_action( 'init', function() {
	if ( ! class_exists( 'Therum_Connections_Page' ) ) return;
	if ( isset( Therum_Connections_Page::tabs()['keys'] ) ) {
		// Re-register with renderer attached (priority preserved via merge)
		Therum_Connections_Page::register( 'keys',     [ 'render' => 'therum_cn_render_keys',     'label' => 'API keys vault', 'section' => 'manage', 'priority' => 60, 'count' => '11',   'desc' => 'Encrypted credential vault — masked, audited, rotatable.' ] );
		Therum_Connections_Page::register( 'webhooks', [ 'render' => 'therum_cn_render_webhooks', 'label' => 'Webhooks log',   'section' => 'manage', 'priority' => 70, 'count' => '2.3k', 'desc' => 'Inbound + outbound webhook event stream with replay.' ] );
		Therum_Connections_Page::register( 'audit',    [ 'render' => 'therum_cn_render_audit',    'label' => 'Audit log',      'section' => 'manage', 'priority' => 80, 'count' => '488',  'desc' => 'Tamper-evident connector + credential lifecycle log.' ] );
	}
}, 21 );

function therum_cn_render_keys( string $tab_id, array $tab ): void {
	?>
	<div class="th-cx-page-head">
		<div>
			<div class="th-cx-page-eyebrow">Connections · Vault</div>
			<h2 class="th-cx-page-title">API keys vault</h2>
			<p class="th-cx-page-sub">Every credential Therum holds — encrypted with the WP <code>SECURE_AUTH_KEY</code>, masked on display, never logged.</p>
		</div>
		<button type="button" class="th-cx-btn is-primary">＋ Add key</button>
	</div>

	<div class="th-cn-stats">
		<div class="th-cn-stat"><div class="th-cn-stat-l">Total credentials</div><div class="th-cn-stat-n">11</div><div class="th-cn-stat-d">all encrypted at rest</div></div>
		<div class="th-cn-stat"><div class="th-cn-stat-l">Rotated this month</div><div class="th-cn-stat-n">3</div><div class="th-cn-stat-d is-ok">↻ healthy cadence</div></div>
		<div class="th-cn-stat"><div class="th-cn-stat-l">Expiring soon</div><div class="th-cn-stat-n">2</div><div class="th-cn-stat-d is-wrn">within 30 days</div></div>
		<div class="th-cn-stat"><div class="th-cn-stat-l">Failed reads</div><div class="th-cn-stat-n">0</div><div class="th-cn-stat-d is-ok">last 7 days</div></div>
	</div>

	<table class="th-cn-table">
		<thead><tr><th>Provider</th><th>Type</th><th>Key</th><th>Created</th><th>Last used</th><th>Expires</th><th style="text-align:right">Actions</th></tr></thead>
		<tbody>
			<?php foreach ( therum_cn_demo_keys() as $k ):
				$exp_style = '';
				if ( $k['expires_state'] === 'wrn' ) $exp_style = 'color:var(--wrn,#f59e0b)';
				if ( $k['expires_state'] === 'err' ) $exp_style = 'color:#ef4444';
			?>
			<tr>
				<td><span class="th-cn-tbl-prov"><span class="th-cn-tbl-ico" style="background:<?php echo esc_attr( $k['bg'] ); ?><?php if ( ! empty( $k['color'] ) ) echo ';color:' . esc_attr( $k['color'] ); ?>"><?php echo esc_html( $k['icon'] ); ?></span><?php echo esc_html( $k['provider'] ); ?></span></td>
				<td><span class="th-cn-pill is-<?php echo esc_attr( $k['type_class'] ); ?>"><?php echo esc_html( $k['type'] ); ?></span></td>
				<td class="th-cn-tbl-key"><?php echo esc_html( $k['key'] ); ?></td>
				<td><?php echo esc_html( $k['created'] ); ?></td>
				<td><?php echo esc_html( $k['last_used'] ); ?></td>
				<td<?php if ( $exp_style ): ?> style="<?php echo esc_attr( $exp_style ); ?>"<?php endif; ?>><?php echo esc_html( $k['expires'] ); ?></td>
				<td class="th-cn-tbl-actions"><button class="th-cn-iconbtn" title="Copy">⎘</button><button class="th-cn-iconbtn" title="Rotate">↻</button><button class="th-cn-iconbtn is-danger" title="Revoke">✕</button></td>
			</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
	<?php
}

function therum_cn_render_webhooks( string $tab_id, array $tab ): void {
	?>
	<div class="th-cx-page-head">
		<div>
			<div class="th-cx-page-eyebrow">Connections · Webhooks</div>
			<h2 class="th-cx-page-title">Webhooks log</h2>
			<p class="th-cx-page-sub">Every webhook fired or received — inbound from providers, outbound to your endpoints. Replay any 4xx, search by payload, inspect signatures.</p>
		</div>
		<button type="button" class="th-cx-btn">⬇ Export CSV</button>
	</div>

	<div class="th-cn-stats">
		<div class="th-cn-stat"><div class="th-cn-stat-l">Events · 30d</div><div class="th-cn-stat-n">2,341</div><div class="th-cn-stat-d">~78 / day</div></div>
		<div class="th-cn-stat"><div class="th-cn-stat-l">Success rate</div><div class="th-cn-stat-n">98.4%</div><div class="th-cn-stat-d is-ok">↑ 0.6%</div></div>
		<div class="th-cn-stat"><div class="th-cn-stat-l">Failures · 24h</div><div class="th-cn-stat-n">4</div><div class="th-cn-stat-d is-wrn">2 retrying</div></div>
		<div class="th-cn-stat"><div class="th-cn-stat-l">Avg latency</div><div class="th-cn-stat-n">124ms</div><div class="th-cn-stat-d">p95: 380ms</div></div>
	</div>

	<table class="th-cn-table">
		<thead><tr><th>Status</th><th>Direction</th><th>Provider · Event</th><th>Endpoint</th><th>Latency</th><th>Time</th><th style="text-align:right">Actions</th></tr></thead>
		<tbody>
			<?php foreach ( therum_cn_demo_webhooks() as $e ): ?>
			<tr>
				<td><span class="th-cn-pill is-<?php echo esc_attr( $e['status_class'] ); ?>"><?php echo esc_html( $e['status'] ); ?></span></td>
				<td><?php echo $e['direction'] === 'in' ? '← in' : '→ out'; ?></td>
				<td><span class="th-cn-tbl-prov"><span class="th-cn-tbl-ico" style="background:<?php echo esc_attr( $e['bg'] ); ?><?php if ( ! empty( $e['color'] ) ) echo ';color:' . esc_attr( $e['color'] ); ?>"><?php echo esc_html( $e['icon'] ); ?></span><?php echo esc_html( $e['event'] ); ?></span></td>
				<td class="th-cn-tbl-key"><?php echo esc_html( $e['endpoint'] ); ?></td>
				<td><?php echo esc_html( $e['latency'] ); ?></td>
				<td><?php echo esc_html( $e['time'] ); ?></td>
				<td class="th-cn-tbl-actions"><button class="th-cn-iconbtn" title="Inspect">👁</button><button class="th-cn-iconbtn" title="Replay">↻</button></td>
			</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
	<?php
}

function therum_cn_render_audit( string $tab_id, array $tab ): void {
	?>
	<div class="th-cx-page-head">
		<div>
			<div class="th-cx-page-eyebrow">Connections · Audit</div>
			<h2 class="th-cx-page-title">Audit log</h2>
			<p class="th-cx-page-sub">Tamper-evident chronological log of every connector + credential lifecycle event. User · action · resource · IP · timestamp.</p>
		</div>
		<button type="button" class="th-cx-btn">⬇ Export</button>
	</div>

	<div class="th-cn-stats">
		<div class="th-cn-stat"><div class="th-cn-stat-l">Total entries</div><div class="th-cn-stat-n">488</div><div class="th-cn-stat-d">7-day window</div></div>
		<div class="th-cn-stat"><div class="th-cn-stat-l">Unique users</div><div class="th-cn-stat-n">3</div><div class="th-cn-stat-d">bam · editor · ops-bot</div></div>
		<div class="th-cn-stat"><div class="th-cn-stat-l">Connector changes</div><div class="th-cn-stat-n">12</div><div class="th-cn-stat-d">5 add · 4 rotate · 3 revoke</div></div>
		<div class="th-cn-stat"><div class="th-cn-stat-l">Failed actions</div><div class="th-cn-stat-n">2</div><div class="th-cn-stat-d is-wrn">permission denied</div></div>
	</div>

	<table class="th-cn-table">
		<thead><tr><th>When</th><th>User</th><th>Action</th><th>Resource</th><th>IP</th><th style="text-align:right">Detail</th></tr></thead>
		<tbody>
			<?php foreach ( therum_cn_demo_audit() as $a ): ?>
			<tr>
				<td><?php echo esc_html( $a['when'] ); ?></td>
				<td><span class="th-cn-tbl-prov"><span class="th-cn-tbl-ico" style="background:<?php echo esc_attr( $a['user_bg'] ); ?>"><?php echo esc_html( $a['user_initial'] ); ?></span><?php echo esc_html( $a['user'] ); ?></span></td>
				<td><span class="th-cn-pill is-<?php echo esc_attr( $a['action_class'] ); ?>"><?php echo esc_html( $a['action'] ); ?></span></td>
				<td><?php echo esc_html( $a['resource'] ); ?></td>
				<td class="th-cn-tbl-key"><?php echo esc_html( $a['ip'] ); ?></td>
				<td class="th-cn-tbl-actions"><button class="th-cn-iconbtn">👁</button></td>
			</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
	<?php
}

// Demo data — replace with real readers in Phase 6 (encrypted options for
// keys, transient buffer for webhooks, custom table for audit).
function therum_cn_demo_keys(): array {
	return [
		[ 'provider' => 'Anthropic · Claude', 'icon' => 'A', 'bg' => '#cc785c', 'type' => 'API KEY', 'type_class' => '2xx', 'key' => 'sk-ant-•••••••••••••••a9F2', 'created' => 'Apr 28', 'last_used' => '2m ago', 'expires' => '—', 'expires_state' => '' ],
		[ 'provider' => 'OpenAI · ChatGPT',   'icon' => 'O', 'bg' => '#10a37f', 'type' => 'API KEY', 'type_class' => '2xx', 'key' => 'sk-proj-•••••••••••••••8x12','created' => 'Apr 26', 'last_used' => '14m ago','expires' => '—', 'expires_state' => '' ],
		[ 'provider' => 'Stripe · live',      'icon' => 'S', 'bg' => '#635bff', 'type' => 'OAUTH',   'type_class' => '3xx', 'key' => 'acct_•••••••••••••••2K7p',  'created' => 'Mar 14', 'last_used' => '8m ago', 'expires' => 'Refresh: never', 'expires_state' => '' ],
		[ 'provider' => 'Stripe · webhook secret', 'icon' => 'S', 'bg' => '#635bff', 'type' => 'HMAC', 'type_class' => '4xx', 'key' => 'whsec_•••••••••••••••B3vQ', 'created' => 'Mar 14', 'last_used' => '1h ago', 'expires' => 'Jun 11 · 29d', 'expires_state' => 'wrn' ],
		[ 'provider' => 'Plaid · sandbox',    'icon' => 'P', 'bg' => '#000', 'color' => '#fff', 'type' => 'OAUTH', 'type_class' => '3xx', 'key' => 'access-•••••••••••••••EXPIRED', 'created' => 'Feb 02', 'last_used' => '14d ago', 'expires' => 'Expired May 11', 'expires_state' => 'err' ],
		[ 'provider' => 'Mailchimp',          'icon' => 'M', 'bg' => '#ffe01b', 'color' => '#000', 'type' => 'API KEY', 'type_class' => '2xx', 'key' => '•••••••••••••••us21-9K', 'created' => 'Jan 18', 'last_used' => '32m ago', 'expires' => '—', 'expires_state' => '' ],
		[ 'provider' => 'Custom · Internal API', 'icon' => '＋', 'bg' => 'transparent', 'color' => 'var(--tx3)', 'type' => 'HMAC', 'type_class' => '4xx', 'key' => 'th_secret_•••••••••••••••', 'created' => 'Apr 03', 'last_used' => '3h ago', 'expires' => 'Jun 03 · 21d', 'expires_state' => 'wrn' ],
	];
}

function therum_cn_demo_webhooks(): array {
	return [
		[ 'status' => '200', 'status_class' => '2xx', 'direction' => 'in',  'event' => 'Stripe · charge.succeeded',     'icon' => 'S', 'bg' => '#635bff', 'endpoint' => '/wp-json/therum/v1/stripe', 'latency' => '89ms',  'time' => '2m ago' ],
		[ 'status' => '200', 'status_class' => '2xx', 'direction' => 'out', 'event' => 'Slack · post.published',        'icon' => 'S', 'bg' => '#4a154b', 'endpoint' => 'hooks.slack.com/T7K…',       'latency' => '142ms', 'time' => '14m ago' ],
		[ 'status' => '201', 'status_class' => '2xx', 'direction' => 'in',  'event' => 'Mailchimp · subscribe',         'icon' => 'M', 'bg' => '#ffe01b', 'color' => '#000', 'endpoint' => '/wp-json/therum/v1/mc',       'latency' => '67ms',  'time' => '38m ago' ],
		[ 'status' => '422', 'status_class' => '4xx', 'direction' => 'out', 'event' => 'Zapier · order.completed',      'icon' => 'Z', 'bg' => '#ff4a00', 'endpoint' => 'hooks.zapier.com/9k…',       'latency' => '2.1s',  'time' => '1h ago' ],
		[ 'status' => '503', 'status_class' => '5xx', 'direction' => 'out', 'event' => 'Custom · user.registered',      'icon' => '＋', 'bg' => 'transparent', 'color' => 'var(--tx3)', 'endpoint' => 'api.internal.com/u…',         'latency' => '10s · timeout', 'time' => '2h ago' ],
		[ 'status' => '200', 'status_class' => '2xx', 'direction' => 'in',  'event' => 'Plaid · transaction.updated',   'icon' => 'P', 'bg' => '#000', 'color' => '#fff', 'endpoint' => '/wp-json/therum/v1/plaid',   'latency' => '104ms', 'time' => '3h ago' ],
		[ 'status' => '200', 'status_class' => '2xx', 'direction' => 'out', 'event' => 'Anthropic · prompt.completed',  'icon' => 'A', 'bg' => '#cc785c', 'endpoint' => 'webhook.therum.local',       'latency' => '32ms',  'time' => '4h ago' ],
	];
}

function therum_cn_demo_audit(): array {
	return [
		[ 'when' => '2m ago',  'user' => 'bam',      'user_initial' => 'B', 'user_bg' => '#3b82f6', 'action' => 'connect',  'action_class' => '2xx', 'resource' => 'Anthropic · Claude',        'ip' => '10.0.1.4' ],
		[ 'when' => '14m ago', 'user' => 'bam',      'user_initial' => 'B', 'user_bg' => '#3b82f6', 'action' => 'rotate',   'action_class' => '3xx', 'resource' => 'Stripe · webhook secret',   'ip' => '10.0.1.4' ],
		[ 'when' => '1h ago',  'user' => 'editor',   'user_initial' => 'E', 'user_bg' => '#10b981', 'action' => 'view',     'action_class' => '2xx', 'resource' => 'Mailchimp audiences',       'ip' => '192.168.5.21' ],
		[ 'when' => '2h ago',  'user' => 'editor',   'user_initial' => 'E', 'user_bg' => '#10b981', 'action' => 'denied',   'action_class' => '4xx', 'resource' => 'API keys vault · raw read', 'ip' => '192.168.5.21' ],
		[ 'when' => '5h ago',  'user' => 'ops-bot',  'user_initial' => '⚙', 'user_bg' => '#888',    'action' => 'refresh',  'action_class' => '2xx', 'resource' => 'Plaid · OAuth token',       'ip' => 'cron' ],
		[ 'when' => '1d ago',  'user' => 'bam',      'user_initial' => 'B', 'user_bg' => '#3b82f6', 'action' => 'revoke',   'action_class' => '5xx', 'resource' => 'Old Stripe key · sk_••E1',  'ip' => '10.0.1.4' ],
		[ 'when' => '2d ago',  'user' => 'bam',      'user_initial' => 'B', 'user_bg' => '#3b82f6', 'action' => 'connect',  'action_class' => '2xx', 'resource' => 'OpenAI · ChatGPT',          'ip' => '10.0.1.4' ],
	];
}


// ════════════════════════════════════════════════════════════════════════════
//  3. MCP SERVER — from therum-mcp.php
// ════════════════════════════════════════════════════════════════════════════

// Wrapped in a kill-switch block (was a top-level `return` when therum-mcp.php
// stood alone — that would now exit the entire merged file prematurely).

if ( ! ( defined( 'THERUM_MCP_DISABLE' ) && THERUM_MCP_DISABLE ) ) {

// Wait until therum-core.php has registered the autoloader (alphabetically
// admin-* and api come first; core is the 7th file loaded).
add_action( 'plugins_loaded', function(): void {
	if ( ! class_exists( \Therum\MCP\Server::class ) ) {
		error_log( '[therum-mcp] Therum\MCP\Server not autoloadable — is _therum/src/ present?' );
		return;
	}

	// ── Register the REST route ───────────────────────────────────────────
	add_action( 'rest_api_init', function(): void {
		register_rest_route(
			'therum/v1',
			'/mcp',
			[
				[
					'methods'             => 'POST',
					'callback'            => function ( \WP_REST_Request $req ): \WP_REST_Response {
						$registry = therum_mcp_build_registry();
						$server   = new \Therum\MCP\Server( $registry );
						return $server->handle( $req );
					},
					// We do NOT enforce a scope at the route level — different tools
					// require different scopes, and the Server checks per-tool via
					// Middleware::require_scope() during tools/call.
					//
					// We still require AN authenticated user (cookie / App Password
					// / Therum token) for ALL MCP requests including handshake; this
					// prevents anonymous probing of the catalogue.
					'permission_callback' => function (): bool {
						return is_user_logged_in();
					},
				],
			]
		);
	} );

	// ── Register the bundled Therum tools ─────────────────────────────────
	add_action( 'therum_mcp_register_tools', function ( \Therum\MCP\ToolRegistry $r ): void {
		$r->register( new \Therum\MCP\Tools\SourceRebuild() );
		$r->register( new \Therum\MCP\Tools\PreviewUrl() );
		$r->register( new \Therum\MCP\Tools\BrandList() );
		$r->register( new \Therum\MCP\Tools\QueueStatus() );
		// Phase 2.5 design pipeline (registered when classes exist)
		if ( class_exists( \Therum\MCP\Tools\DesignDerive::class ) ) {
			$r->register( new \Therum\MCP\Tools\DesignDerive() );
		}
		if ( class_exists( \Therum\MCP\Tools\DesignReview::class ) ) {
			$r->register( new \Therum\MCP\Tools\DesignReview() );
		}
		if ( class_exists( \Therum\MCP\Tools\DesignApply::class ) ) {
			$r->register( new \Therum\MCP\Tools\DesignApply() );
		}
		if ( class_exists( \Therum\MCP\Tools\BrandApply::class ) ) {
			$r->register( new \Therum\MCP\Tools\BrandApply() );
		}
	} );

	// ── Register queue handlers for async tools ───────────────────────────
	// These have to register at boot in EVERY process (web + cli worker) so
	// the queue worker can invoke them. The handler-id strings match the
	// constants in each Tools\* class.
	if ( class_exists( \Therum\Queue::class ) ) {
		\Therum\Queue::register_handler(
			\Therum\MCP\Tools\SourceRebuild::HANDLER_ID,
			[ \Therum\MCP\Tools\SourceRebuild::class, 'handler' ]
		);
		// Phase 2.5 design.derive handler (registered when class exists)
		if ( class_exists( \Therum\MCP\Tools\DesignDerive::class ) ) {
			\Therum\Queue::register_handler(
				\Therum\MCP\Tools\DesignDerive::HANDLER_ID,
				[ \Therum\MCP\Tools\DesignDerive::class, 'handler' ]
			);
		}
	}
}, 5 );

// ── Helper: build the tool registry by firing the registration action ────
// Called per-request (lightweight — registration is a few array inserts).
// Cached per-request via static, since multiple JSON-RPC requests in a batch
// shouldn't fire the action multiple times.
function therum_mcp_build_registry(): \Therum\MCP\ToolRegistry {
	static $registry = null;
	if ( $registry instanceof \Therum\MCP\ToolRegistry ) return $registry;

	$registry = new \Therum\MCP\ToolRegistry();
	do_action( 'therum_mcp_register_tools', $registry );
	return $registry;
}

} // end MCP section
