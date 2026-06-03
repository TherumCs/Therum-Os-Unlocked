<?php
declare( strict_types=1 );

namespace Therum\Auth;

/**
 * Admin page for Therum API tokens.
 *
 * Registers under Users → API Tokens (Profile-adjacent, since tokens are
 * per-user). Renders list of active tokens + issue form + revoke buttons.
 * All interaction goes through the Therum REST routes — the page itself is
 * just an inline HTML/JS shell.
 */
final class AdminPage {

	public const SLUG = 'therum-tokens';

	public static function register_menu(): void {
		add_users_page(
			'API Tokens',
			'API Tokens',
			'read',
			self::SLUG,
			[ self::class, 'render' ]
		);
	}

	public static function render(): void {
		$nonce  = wp_create_nonce( 'wp_rest' );
		$rest   = esc_url_raw( rest_url( 'therum/v1/' ) );
		?>
		<div class="wrap th-tokens-wrap">
			<h1>Therum API Tokens</h1>
			<p class="description">
				Capability-scoped tokens for the Therum API and MCP server. Use these instead of
				WordPress Application Passwords when connecting Claude Code, scripts, or other
				clients that should only have a limited subset of permissions.
			</p>

			<div class="th-tokens-issued" id="th-tokens-issued" hidden>
				<div class="notice notice-success inline">
					<p><strong>New token issued — copy it now. You won't see it again.</strong></p>
					<div class="th-token-display">
						<code id="th-tokens-new-value"></code>
						<button type="button" class="button" id="th-tokens-copy">Copy</button>
					</div>
				</div>
			</div>

			<div class="th-tokens-section">
				<h2>Active tokens</h2>
				<table class="wp-list-table widefat striped" id="th-tokens-table">
					<thead>
						<tr>
							<th>Name</th>
							<th>Prefix</th>
							<th>Scopes</th>
							<th>Created</th>
							<th>Last used</th>
							<th></th>
						</tr>
					</thead>
					<tbody>
						<tr><td colspan="6"><em>Loading…</em></td></tr>
					</tbody>
				</table>
			</div>

			<div class="th-tokens-section">
				<h2>Issue new token</h2>
				<form id="th-tokens-form" class="th-tokens-form">
					<p>
						<label for="th-tokens-name"><strong>Name</strong></label><br>
						<input type="text" id="th-tokens-name" name="name"
						       class="regular-text" required
						       placeholder="e.g. Claude Code (local)" />
					</p>
					<fieldset id="th-tokens-scopes">
						<legend><strong>Scopes</strong></legend>
						<p class="description"><em>Loading scopes…</em></p>
					</fieldset>
					<p>
						<button type="submit" class="button button-primary">Issue token</button>
					</p>
				</form>
			</div>
		</div>

		<style>
		.th-tokens-wrap { max-width: 980px; }
		.th-tokens-section { margin: 28px 0; }
		.th-token-display {
			display: flex; gap: 12px; align-items: center;
			background: #fff; border: 1px solid #c3c4c7; border-radius: 4px;
			padding: 10px 14px; margin: 12px 0;
		}
		.th-token-display code {
			flex: 1; font-size: 13px; word-break: break-all;
			background: #f6f7f7; padding: 6px 10px; border-radius: 3px;
		}
		.th-tokens-form fieldset {
			border: 1px solid #c3c4c7; border-radius: 4px; padding: 12px 16px;
			background: #fff; margin: 12px 0;
		}
		.th-tokens-form fieldset legend { padding: 0 6px; }
		.th-scope-row {
			display: flex; align-items: center; gap: 8px;
			padding: 6px 0;
		}
		.th-scope-row label { flex: 1; cursor: pointer; }
		.th-scope-row small { color: #646970; }
		.th-scope-row.is-disabled { opacity: 0.5; }
		.th-scope-cap {
			font-family: ui-monospace, monospace; font-size: 11px;
			background: #f0f0f1; padding: 1px 6px; border-radius: 3px;
		}
		.th-tokens-table-scopes {
			display: inline-flex; flex-wrap: wrap; gap: 4px;
		}
		.th-tokens-table-scopes code {
			font-size: 11px; padding: 1px 6px;
			background: #f0f0f1; border-radius: 3px;
		}
		.button-link-delete { color: #b32d2e; }
		.button-link-delete:hover { color: #8a1f1f; }
		</style>

		<script>
		(function() {
			const REST  = <?php echo wp_json_encode( $rest ); ?>;
			const NONCE = <?php echo wp_json_encode( $nonce ); ?>;

			const tableBody = document.querySelector('#th-tokens-table tbody');
			const scopesBox = document.getElementById('th-tokens-scopes');
			const form      = document.getElementById('th-tokens-form');
			const issuedBox = document.getElementById('th-tokens-issued');
			const issuedVal = document.getElementById('th-tokens-new-value');
			const copyBtn   = document.getElementById('th-tokens-copy');

			function req(path, opts = {}) {
				return fetch(REST + path, Object.assign({
					credentials: 'same-origin',
					headers: Object.assign({
						'Content-Type': 'application/json',
						'X-WP-Nonce': NONCE,
					}, opts.headers || {}),
				}, opts)).then(async r => {
					const data = await r.json().catch(() => ({}));
					if (!r.ok) throw new Error(data.error || data.message || (r.status + ' ' + r.statusText));
					return data;
				});
			}

			function fmtScopes(scopes) {
				return scopes.map(s => `<code>${s}</code>`).join(' ');
			}

			function renderRow(t) {
				const tr = document.createElement('tr');
				if (!t.is_active) tr.style.opacity = '0.5';
				tr.innerHTML = `
					<td><strong>${escapeHtml(t.name)}</strong></td>
					<td><code>${escapeHtml(t.prefix)}…</code></td>
					<td><div class="th-tokens-table-scopes">${fmtScopes(t.scopes)}</div></td>
					<td>${escapeHtml(t.created_at)}</td>
					<td>${t.last_used_at ? escapeHtml(t.last_used_at) : '—'}</td>
					<td>${t.is_active
						? '<button type="button" class="button button-link button-link-delete" data-revoke="' + t.id + '">Revoke</button>'
						: '<em>revoked</em>'}</td>`;
				return tr;
			}

			function escapeHtml(s) {
				return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({
					'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
				}[c]));
			}

			async function loadTokens() {
				try {
					const { tokens } = await req('tokens');
					tableBody.innerHTML = '';
					if (!tokens.length) {
						tableBody.innerHTML = '<tr><td colspan="6"><em>No tokens yet.</em></td></tr>';
						return;
					}
					tokens.forEach(t => tableBody.appendChild(renderRow(t)));
				} catch (e) {
					tableBody.innerHTML = '<tr><td colspan="6">Error: ' + escapeHtml(e.message) + '</td></tr>';
				}
			}

			async function loadScopes() {
				try {
					const { scopes } = await req('tokens/scopes');
					scopesBox.innerHTML = '<legend><strong>Scopes</strong></legend>';
					scopes.forEach(s => {
						const row = document.createElement('div');
						row.className = 'th-scope-row' + (s.grantable ? '' : ' is-disabled');
						const cb = document.createElement('input');
						cb.type = 'checkbox';
						cb.name = 'scopes';
						cb.value = s.id;
						cb.id = 'th-scope-' + s.id;
						cb.disabled = !s.grantable;
						const lab = document.createElement('label');
						lab.htmlFor = cb.id;
						lab.innerHTML = '<code>' + escapeHtml(s.id) + '</code> — ' + escapeHtml(s.label)
							+ ' <small>cap: <span class="th-scope-cap">' + escapeHtml(s.cap) + '</span></small>';
						row.appendChild(cb);
						row.appendChild(lab);
						scopesBox.appendChild(row);
					});
				} catch (e) {
					scopesBox.innerHTML = '<legend><strong>Scopes</strong></legend><p>Error: ' + escapeHtml(e.message) + '</p>';
				}
			}

			form.addEventListener('submit', async (e) => {
				e.preventDefault();
				const name = document.getElementById('th-tokens-name').value.trim();
				const scopes = Array.from(
					scopesBox.querySelectorAll('input[name="scopes"]:checked')
				).map(cb => cb.value);

				if (!name || scopes.length === 0) {
					alert('Provide a name and pick at least one scope.');
					return;
				}

				try {
					const r = await req('tokens', {
						method: 'POST',
						body: JSON.stringify({ name, scopes }),
					});
					issuedVal.textContent = r.token;
					issuedBox.hidden = false;
					form.reset();
					loadTokens();
				} catch (e) {
					alert('Issue failed: ' + e.message);
				}
			});

			tableBody.addEventListener('click', async (e) => {
				const btn = e.target.closest('[data-revoke]');
				if (!btn) return;
				if (!confirm('Revoke this token? Clients using it will immediately stop authenticating.')) return;
				try {
					await req('tokens/' + btn.dataset.revoke, { method: 'DELETE' });
					loadTokens();
				} catch (e) {
					alert('Revoke failed: ' + e.message);
				}
			});

			copyBtn.addEventListener('click', () => {
				navigator.clipboard.writeText(issuedVal.textContent).then(() => {
					copyBtn.textContent = 'Copied';
					setTimeout(() => copyBtn.textContent = 'Copy', 1500);
				});
			});

			loadTokens();
			loadScopes();
		})();
		</script>
		<?php
	}
}
