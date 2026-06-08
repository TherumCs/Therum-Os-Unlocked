<?php
/**
 * Therum OS — Therum_Connections_Page
 *
 * Extracted from therum-admin.php as part of the 1.9.x split. Same
 * class, same behavior; required back in from therum-admin.php at the
 * original load position to preserve declaration order.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class Therum_Connections_Page {

	const OPTION_KEY = 'therum_connections';

	/**
	 * Stub tab registry — compatibility with the newer Therum_Connections_Page
	 * shape in therum-connections.php (which is guarded by class_exists() and
	 * therefore skipped because this class loads first alphabetically). The
	 * tabs collected here can be retrieved via tabs() but are otherwise
	 * inert; the legacy provider UI on this class remains the active surface.
	 */
	private static $tabs = [];
	public static function register( string $id, array $args ): void {
		self::$tabs[ $id ] = wp_parse_args( $args, [
			'label'    => ucfirst( $id ),
			'section'  => 'connectors',
			'icon'     => 'dot',
			'priority' => 100,
			'render'   => null,
			'desc'     => '',
			'count'    => '',
		] );
	}
	public static function tabs(): array {
		$tabs = apply_filters( 'therum_connections_page_tabs', self::$tabs );
		uasort( $tabs, fn( $a, $b ) => (int)( $a['priority'] ?? 100 ) <=> (int)( $b['priority'] ?? 100 ) );
		return $tabs;
	}

	// ── Provider registry ─────────────────────────────────────────────────────
	public static function categories(): array {
		return [
			'ai-tools'    => [ 'label' => 'AI Tools',           'icon' => 'feather',  'desc' => 'Language models you can query from the dashboard or any Therum page. Bring your own API key or OAuth credentials.' ],
			'apis'        => [ 'label' => 'APIs',               'icon' => 'webhook',  'desc' => 'External REST APIs for data, email, messaging, and more.' ],
			'ecommerce'   => [ 'label' => 'Connect Ecommerce',  'icon' => 'store',    'desc' => 'Storefront and inventory platforms to sync with WooCommerce.' ],
			'payment'     => [ 'label' => 'Payment Gateways',   'icon' => 'payments', 'desc' => 'Accept cards, wallets, and bank transfers on your store.' ],
			'external'    => [ 'label' => 'External Apps',      'icon' => 'external', 'desc' => 'Automation and collaboration tools — Zapier, Slack, Notion, and more.' ],
		];
	}

	public static function providers(): array {
		$built = self::_builtin_providers();
		// Merge user-defined custom providers per-category. Each carries
		// 'custom' => true so the UI can route Edit/Delete actions and the
		// vault can show them alongside built-ins. Credential storage is
		// shared with built-ins (same OPTION_KEY, same encryption path).
		foreach ( self::get_custom_providers() as $slug => $row ) {
			$cat = $row['category'] ?? '';
			if ( ! isset( $built[ $cat ] ) || ! is_array( $row ) ) continue;
			$row['id']     = $slug;
			$row['custom'] = true;
			$built[ $cat ][] = $row;
		}
		return $built;
	}

	private static function _builtin_providers(): array {
		return [
			// ── AI Tools — 13 providers ──────────────────────────────────────
			'ai-tools' => [
				[ 'id'=>'odysseus',     'name'=>'Therum · Odysseus',   'letter'=>'Ω', 'color'=>'#1e40af', 'meta'=>'Bundled AI workspace · chat router · agents · memory — runs on this host', 'auth'=>'base_url', 'auth_label'=>'Base URL', 'auth_hint'=>'http://localhost:8000', 'url'=>'https://github.com/pewdiepie-archdaemon/odysseus' ],
				[ 'id'=>'anthropic',    'name'=>'Anthropic · Claude',  'letter'=>'A', 'color'=>'#cc785c', 'meta'=>'claude-sonnet-4.5 · 200k context · function calling',      'auth'=>'api_key',  'auth_label'=>'API Key',  'auth_hint'=>'sk-ant-…', 'url'=>'https://console.anthropic.com/settings/keys' ],
				[ 'id'=>'openai',       'name'=>'OpenAI · ChatGPT',    'letter'=>'O', 'color'=>'#10a37f', 'meta'=>'gpt-5-turbo · 128k context · vision · code interpreter',  'auth'=>'api_key',  'auth_label'=>'API Key',  'auth_hint'=>'sk-…',    'url'=>'https://platform.openai.com/api-keys' ],
				[ 'id'=>'google-gemini','name'=>'Google AI · Gemini',  'letter'=>'G', 'color'=>'#4285f4', 'meta'=>'gemini-2.5-pro · 2M context · multimodal native',          'auth'=>'api_key',  'auth_label'=>'API Key',  'auth_hint'=>'AIza…',   'url'=>'https://aistudio.google.com/app/apikey' ],
				[ 'id'=>'xai',          'name'=>'xAI · Grok',          'letter'=>'X', 'color'=>'#000000', 'meta'=>'grok-4 · 256k context · real-time X integration',          'auth'=>'api_key',  'auth_label'=>'API Key',  'auth_hint'=>'xai-…',   'url'=>'https://console.x.ai' ],
				[ 'id'=>'mistral',      'name'=>'Mistral AI',          'letter'=>'M', 'color'=>'#ff7000', 'meta'=>'mistral-large · open-weight EU host · function calling',  'auth'=>'api_key',  'auth_label'=>'API Key',  'auth_hint'=>'…',       'url'=>'https://console.mistral.ai/api-keys' ],
				[ 'id'=>'deepseek',     'name'=>'DeepSeek',            'letter'=>'D', 'color'=>'#4d6bfe', 'meta'=>'deepseek-v3 · 671B MoE · strong reasoning at low cost',    'auth'=>'api_key',  'auth_label'=>'API Key',  'auth_hint'=>'sk-…',    'url'=>'https://platform.deepseek.com/api_keys' ],
				[ 'id'=>'perplexity',   'name'=>'Perplexity',          'letter'=>'P', 'color'=>'#20b8cd', 'meta'=>'Online-grounded answers · citations · search-aware LLM',   'auth'=>'api_key',  'auth_label'=>'API Key',  'auth_hint'=>'pplx-…',  'url'=>'https://www.perplexity.ai/settings/api' ],
				[ 'id'=>'cohere',       'name'=>'Cohere',              'letter'=>'C', 'color'=>'#ff7759', 'meta'=>'command-r-plus · enterprise RAG · embedding · rerank',     'auth'=>'api_key',  'auth_label'=>'API Key',  'auth_hint'=>'…',       'url'=>'https://dashboard.cohere.com/api-keys' ],
				[ 'id'=>'groq',         'name'=>'Groq',                'letter'=>'Q', 'color'=>'#f55036', 'meta'=>'LPU inference · 500+ tok/s · llama · mixtral · qwen',      'auth'=>'api_key',  'auth_label'=>'API Key',  'auth_hint'=>'gsk_…',   'url'=>'https://console.groq.com/keys' ],
				[ 'id'=>'ollama',       'name'=>'Local · Ollama',      'letter'=>'L', 'color'=>'#7c3aed', 'meta'=>'llama · mistral · qwen — runs on the same host',           'auth'=>'base_url', 'auth_label'=>'Base URL', 'auth_hint'=>'http://localhost:11434', 'url'=>'https://ollama.com' ],
				[ 'id'=>'elevenlabs',   'name'=>'ElevenLabs · Voice',  'letter'=>'V', 'color'=>'#0a0a0a', 'meta'=>'TTS · voice clones · multilingual · audio for posts',      'auth'=>'api_key',  'auth_label'=>'API Key',  'auth_hint'=>'sk_…',    'url'=>'https://elevenlabs.io/app/settings/api-keys' ],
				[ 'id'=>'huggingface',  'name'=>'Hugging Face',        'letter'=>'H', 'color'=>'#ffd21e', 'meta'=>'Inference API · custom model endpoints · datasets',        'auth'=>'api_key',  'auth_label'=>'Access Token','auth_hint'=>'hf_…',  'url'=>'https://huggingface.co/settings/tokens' ],
			],

			// ── APIs — 12 providers (email · SMS · push · maps · realtime) ──
			'apis' => [
				[ 'id'=>'mailchimp',   'name'=>'Mailchimp',         'letter'=>'M', 'color'=>'#ffe01b', 'meta'=>'Email marketing · audiences · automations',           'auth'=>'api_key',   'auth_label'=>'API Key',          'auth_hint'=>'…-us1',     'url'=>'https://mailchimp.com/developer/marketing/guides/quick-start/' ],
				[ 'id'=>'sendgrid',    'name'=>'SendGrid',          'letter'=>'S', 'color'=>'#1a82e2', 'meta'=>'Transactional email · templates · delivery analytics','auth'=>'api_key',   'auth_label'=>'API Key',          'auth_hint'=>'SG.…',      'url'=>'https://app.sendgrid.com/settings/api_keys' ],
				[ 'id'=>'postmark',    'name'=>'Postmark',          'letter'=>'P', 'color'=>'#ffde00', 'meta'=>'Fastest transactional delivery · separate streams',   'auth'=>'api_key',   'auth_label'=>'Server Token',     'auth_hint'=>'…',         'url'=>'https://account.postmarkapp.com/api_tokens' ],
				[ 'id'=>'resend',      'name'=>'Resend',            'letter'=>'R', 'color'=>'#000000', 'meta'=>'Developer-first email · React templates · webhooks',  'auth'=>'api_key',   'auth_label'=>'API Key',          'auth_hint'=>'re_…',      'url'=>'https://resend.com/api-keys' ],
				[ 'id'=>'mailgun',     'name'=>'Mailgun',           'letter'=>'M', 'color'=>'#f06b66', 'meta'=>'Sending + receiving · routing · validation',          'auth'=>'api_key',   'auth_label'=>'API Key',          'auth_hint'=>'key-…',     'url'=>'https://app.mailgun.com/app/account/security/api_keys' ],
				[ 'id'=>'brevo',       'name'=>'Brevo · Sendinblue','letter'=>'B', 'color'=>'#0b996e', 'meta'=>'Email + SMS + chat · CRM-aware campaigns',            'auth'=>'api_key',   'auth_label'=>'API Key',          'auth_hint'=>'xkeysib-…', 'url'=>'https://app.brevo.com/settings/keys/api' ],
				[ 'id'=>'twilio',      'name'=>'Twilio',            'letter'=>'T', 'color'=>'#f22f46', 'meta'=>'SMS · voice · WhatsApp · two-factor codes',           'auth'=>'sid_token', 'auth_label'=>'Account SID',      'auth_label2'=>'Auth Token', 'auth_hint'=>'AC…', 'auth_hint2'=>'…', 'url'=>'https://console.twilio.com' ],
				[ 'id'=>'vonage',      'name'=>'Vonage',            'letter'=>'V', 'color'=>'#000000', 'meta'=>'Global SMS · voice · verify · video API',             'auth'=>'sid_token', 'auth_label'=>'API Key',          'auth_label2'=>'API Secret', 'auth_hint'=>'…', 'auth_hint2'=>'…', 'url'=>'https://dashboard.nexmo.com/getting-started/your-api-key' ],
				[ 'id'=>'onesignal',   'name'=>'OneSignal',         'letter'=>'O', 'color'=>'#e54b4d', 'meta'=>'Push notifications · in-app · email · SMS',           'auth'=>'sid_token', 'auth_label'=>'App ID',           'auth_label2'=>'REST API Key', 'auth_hint'=>'…', 'auth_hint2'=>'…', 'url'=>'https://dashboard.onesignal.com' ],
				[ 'id'=>'telegram',    'name'=>'Telegram bot',      'letter'=>'T', 'color'=>'#26a5e4', 'meta'=>'Direct message · group post · inline keyboards',      'auth'=>'api_key',   'auth_label'=>'Bot Token',        'auth_hint'=>'1234567890:ABC-…', 'url'=>'https://core.telegram.org/bots#how-do-i-create-a-bot' ],
				[ 'id'=>'mapbox',      'name'=>'Mapbox',            'letter'=>'M', 'color'=>'#1da1f2', 'meta'=>'Maps · geocoding · directions API',                   'auth'=>'api_key',   'auth_label'=>'Access Token',     'auth_hint'=>'pk.…',      'url'=>'https://account.mapbox.com/access-tokens' ],
				[ 'id'=>'pusher',      'name'=>'Pusher',            'letter'=>'P', 'color'=>'#300d4f', 'meta'=>'Real-time WebSocket channels',                        'auth'=>'sid_token', 'auth_label'=>'App Key',          'auth_label2'=>'App Secret', 'auth_hint'=>'…', 'auth_hint2'=>'…', 'url'=>'https://dashboard.pusher.com' ],
			],

			// ── Ecommerce — 8 platforms ──────────────────────────────────────
			'ecommerce' => [
				[ 'id'=>'shopify',     'name'=>'Shopify',             'letter'=>'S', 'color'=>'#96bf48', 'meta'=>'Sync products · orders · inventory',                  'auth'=>'api_key',  'auth_label'=>'Admin API Key',   'auth_hint'=>'shpat_…',   'url'=>'https://shopify.dev/docs/api/admin-rest' ],
				[ 'id'=>'bigcommerce', 'name'=>'BigCommerce',         'letter'=>'B', 'color'=>'#34313f', 'meta'=>'Catalog sync · order webhooks',                        'auth'=>'api_key',  'auth_label'=>'API Token',       'auth_hint'=>'…',         'url'=>'https://developer.bigcommerce.com' ],
				[ 'id'=>'etsy',        'name'=>'Etsy',                'letter'=>'E', 'color'=>'#f56400', 'meta'=>'Handmade & vintage marketplace sync',                  'auth'=>'api_key',  'auth_label'=>'API Key',         'auth_hint'=>'…',         'url'=>'https://www.etsy.com/developers' ],
				[ 'id'=>'amazon-sp',   'name'=>'Amazon Seller',       'letter'=>'A', 'color'=>'#ff9900', 'meta'=>'SP-API · listings · orders · FBA',                    'auth'=>'api_key',  'auth_label'=>'Refresh Token',   'auth_hint'=>'Atzr|…',    'url'=>'https://sellercentral.amazon.com' ],
				[ 'id'=>'magento',     'name'=>'Magento · Adobe Commerce','letter'=>'M','color'=>'#ee672f','meta'=>'Enterprise commerce · headless · multi-store',       'auth'=>'api_key',  'auth_label'=>'Integration Token','auth_hint'=>'…',        'url'=>'https://developer.adobe.com/commerce/' ],
				[ 'id'=>'wix',         'name'=>'Wix Stores',          'letter'=>'W', 'color'=>'#0c6ebd', 'meta'=>'Wix Stores REST · cart · orders · members',           'auth'=>'api_key',  'auth_label'=>'API Key',         'auth_hint'=>'…',         'url'=>'https://dev.wix.com/api/rest' ],
				[ 'id'=>'squarespace', 'name'=>'Squarespace Commerce','letter'=>'S', 'color'=>'#000000', 'meta'=>'Inventory · orders · transactions API',               'auth'=>'api_key',  'auth_label'=>'API Key',         'auth_hint'=>'…',         'url'=>'https://developers.squarespace.com' ],
				[ 'id'=>'lemon',       'name'=>'Lemon Squeezy',       'letter'=>'L', 'color'=>'#ffc232', 'meta'=>'Merchant-of-record SaaS billing · digital goods',     'auth'=>'api_key',  'auth_label'=>'API Key',         'auth_hint'=>'eyJ0…',     'url'=>'https://app.lemonsqueezy.com/settings/api' ],
			],

			// ── Payment Gateways — 12 (cards · BNPL · crypto · regional) ────
			'payment' => [
				[ 'id'=>'stripe',      'name'=>'Stripe',              'letter'=>'S', 'color'=>'#635bff', 'meta'=>'Cards · wallets · bank transfers · subscriptions',    'auth'=>'api_key',  'auth_label'=>'Secret Key',       'auth_hint'=>'sk_live_…',  'url'=>'https://dashboard.stripe.com/apikeys' ],
				[ 'id'=>'paypal',      'name'=>'PayPal',              'letter'=>'P', 'color'=>'#0070ba', 'meta'=>'PayPal · Venmo · Pay Later',                          'auth'=>'sid_token','auth_label'=>'Client ID',        'auth_label2'=>'Client Secret', 'auth_hint'=>'…', 'auth_hint2'=>'…', 'url'=>'https://developer.paypal.com/dashboard' ],
				[ 'id'=>'square-pay',  'name'=>'Square',              'letter'=>'S', 'color'=>'#000000', 'meta'=>'In-person · online · invoicing',                       'auth'=>'api_key',  'auth_label'=>'Access Token',     'auth_hint'=>'EAAAl…',      'url'=>'https://developer.squareup.com/apps' ],
				[ 'id'=>'braintree',   'name'=>'Braintree',           'letter'=>'B', 'color'=>'#0070ba', 'meta'=>'Full-stack payments by PayPal · cards · Venmo · wallets','auth'=>'sid_token','auth_label'=>'Merchant ID',    'auth_label2'=>'Private Key',   'auth_hint'=>'…', 'auth_hint2'=>'…', 'url'=>'https://developer.paypal.com/braintree/' ],
				[ 'id'=>'adyen',       'name'=>'Adyen',               'letter'=>'A', 'color'=>'#0abf53', 'meta'=>'Global enterprise gateway · 250+ payment methods',     'auth'=>'api_key',  'auth_label'=>'API Key',          'auth_hint'=>'AQE…',        'url'=>'https://docs.adyen.com/development-resources/api-credentials/' ],
				[ 'id'=>'authorize',   'name'=>'Authorize.Net',       'letter'=>'A', 'color'=>'#1b3a6b', 'meta'=>'Legacy US gateway · ACH · recurring billing',          'auth'=>'sid_token','auth_label'=>'API Login ID',     'auth_label2'=>'Transaction Key','auth_hint'=>'…', 'auth_hint2'=>'…', 'url'=>'https://account.authorize.net' ],
				[ 'id'=>'mollie',      'name'=>'Mollie',              'letter'=>'M', 'color'=>'#0e1c2b', 'meta'=>'EU-first · iDEAL · SEPA · Bancontact · Klarna handoff','auth'=>'api_key',  'auth_label'=>'API Key',          'auth_hint'=>'live_…',      'url'=>'https://www.mollie.com/dashboard/developers/api-keys' ],
				[ 'id'=>'klarna',      'name'=>'Klarna',              'letter'=>'K', 'color'=>'#ffaeb5', 'meta'=>'Pay in 4 · BNPL · in-checkout financing',              'auth'=>'sid_token','auth_label'=>'Username',         'auth_label2'=>'Password',       'auth_hint'=>'PK…',  'auth_hint2'=>'…', 'url'=>'https://portal.klarna.com' ],
				[ 'id'=>'affirm',      'name'=>'Affirm',              'letter'=>'A', 'color'=>'#0a0a23', 'meta'=>'Larger-ticket installments · soft credit pull',        'auth'=>'sid_token','auth_label'=>'Public API Key',   'auth_label2'=>'Private API Key','auth_hint'=>'…', 'auth_hint2'=>'…', 'url'=>'https://docs.affirm.com/affirm-developers/' ],
				[ 'id'=>'coinbase',    'name'=>'Coinbase Commerce',   'letter'=>'C', 'color'=>'#0052ff', 'meta'=>'Accept BTC · ETH · USDC · settle in fiat or crypto',   'auth'=>'api_key',  'auth_label'=>'API Key',          'auth_hint'=>'…',           'url'=>'https://beta.commerce.coinbase.com/settings/security' ],
				[ 'id'=>'razorpay',    'name'=>'Razorpay',            'letter'=>'R', 'color'=>'#02042a', 'meta'=>'India-first · UPI · cards · netbanking · payouts',     'auth'=>'sid_token','auth_label'=>'Key ID',           'auth_label2'=>'Key Secret',     'auth_hint'=>'rzp_live_…','auth_hint2'=>'…', 'url'=>'https://dashboard.razorpay.com/app/keys' ],
				[ 'id'=>'plaid',       'name'=>'Plaid',               'letter'=>'P', 'color'=>'#000000', 'meta'=>'Bank account linking · ACH · balance lookups',         'auth'=>'sid_token','auth_label'=>'Client ID',        'auth_label2'=>'Secret',         'auth_hint'=>'…', 'auth_hint2'=>'…', 'url'=>'https://dashboard.plaid.com/team/keys' ],
			],

			// ── External Apps — 18 (docs · design · dev · PM · automation) ──
			'external' => [
				[ 'id'=>'zapier',      'name'=>'Zapier',              'letter'=>'Z', 'color'=>'#ff4a00', 'meta'=>'Automate workflows · 7,000+ app integrations',         'auth'=>'api_key',  'auth_label'=>'Webhook URL',      'auth_hint'=>'https://hooks.zapier.com/…', 'url'=>'https://zapier.com/app/zaps' ],
				[ 'id'=>'make',        'name'=>'Make · Integromat',   'letter'=>'M', 'color'=>'#6d00cc', 'meta'=>'Visual scenarios · branching · scheduled flows',       'auth'=>'api_key',  'auth_label'=>'Webhook URL',      'auth_hint'=>'https://hook.make.com/…',     'url'=>'https://www.make.com/en/help/tools/webhooks' ],
				[ 'id'=>'slack',       'name'=>'Slack',               'letter'=>'S', 'color'=>'#4a154b', 'meta'=>'Post messages to channels · receive slash commands',   'auth'=>'api_key',  'auth_label'=>'Incoming Webhook', 'auth_hint'=>'https://hooks.slack.com/…',  'url'=>'https://api.slack.com/messaging/webhooks',
					'auth_methods'=>['api_key','oauth'], 'oauth_authorize_url'=>'https://slack.com/oauth/v2/authorize', 'oauth_token_url'=>'https://slack.com/api/oauth.v2.access', 'oauth_scope'=>'chat:write channels:read' ],
				[ 'id'=>'teams',       'name'=>'Microsoft Teams',     'letter'=>'T', 'color'=>'#5059c9', 'meta'=>'Channel posts · adaptive cards · bot replies',         'auth'=>'api_key',  'auth_label'=>'Webhook URL',      'auth_hint'=>'https://outlook.office.com/…', 'url'=>'https://learn.microsoft.com/en-us/microsoftteams/platform/webhooks-and-connectors/' ],
				[ 'id'=>'discord',     'name'=>'Discord',             'letter'=>'D', 'color'=>'#5865f2', 'meta'=>'Post to Discord channels via webhooks',                'auth'=>'api_key',  'auth_label'=>'Webhook URL',      'auth_hint'=>'https://discord.com/api/webhooks/…', 'url'=>'https://discord.com/developers/docs/resources/webhook' ],
				[ 'id'=>'zoom',        'name'=>'Zoom',                'letter'=>'Z', 'color'=>'#2d8cff', 'meta'=>'Meetings · webinars · recording links',                'auth'=>'api_key',  'auth_label'=>'Server-to-Server Token','auth_hint'=>'…',     'url'=>'https://marketplace.zoom.us/develop/create' ],
				[ 'id'=>'calendly',    'name'=>'Calendly',            'letter'=>'C', 'color'=>'#006bff', 'meta'=>'Bookings · event types · embed scheduler',             'auth'=>'api_key',  'auth_label'=>'Personal Access Token','auth_hint'=>'eyJraWQi…','url'=>'https://calendly.com/integrations/api_webhooks' ],
				[ 'id'=>'notion',      'name'=>'Notion',              'letter'=>'N', 'color'=>'#000000', 'meta'=>'Sync database records · push updates to pages',        'auth'=>'api_key',  'auth_label'=>'Integration Token','auth_hint'=>'secret_…',                 'url'=>'https://www.notion.so/my-integrations' ],
				[ 'id'=>'airtable',    'name'=>'Airtable',            'letter'=>'A', 'color'=>'#18bfff', 'meta'=>'Read / write Airtable bases via API',                  'auth'=>'api_key',  'auth_label'=>'Personal Access Token','auth_hint'=>'pat…',                 'url'=>'https://airtable.com/create/tokens' ],
				[ 'id'=>'gdrive',      'name'=>'Google Drive',        'letter'=>'G', 'color'=>'#4285f4', 'meta'=>'Docs · Sheets · Slides · files — read into Therum',    'auth'=>'sid_token','auth_label'=>'Client ID',        'auth_label2'=>'Client Secret','auth_hint'=>'…apps.googleusercontent.com','auth_hint2'=>'…', 'url'=>'https://console.cloud.google.com/apis/credentials',
					'auth_methods'=>['api_key','oauth'], 'oauth_authorize_url'=>'https://accounts.google.com/o/oauth2/v2/auth', 'oauth_token_url'=>'https://oauth2.googleapis.com/token', 'oauth_scope'=>'https://www.googleapis.com/auth/drive.readonly https://www.googleapis.com/auth/drive.file' ],
				[ 'id'=>'dropbox',     'name'=>'Dropbox',             'letter'=>'D', 'color'=>'#0061ff', 'meta'=>'Files · Paper · sign — pull assets into Therum media', 'auth'=>'api_key',  'auth_label'=>'Access Token',     'auth_hint'=>'sl.…',         'url'=>'https://www.dropbox.com/developers/apps',
					'auth_methods'=>['api_key','oauth'], 'oauth_authorize_url'=>'https://www.dropbox.com/oauth2/authorize', 'oauth_token_url'=>'https://api.dropbox.com/oauth2/token', 'oauth_scope'=>'files.content.read files.content.write files.metadata.read' ],
				[ 'id'=>'figma',       'name'=>'Figma',               'letter'=>'F', 'color'=>'#0d0d0d', 'meta'=>'Files · frames · variables — design tokens sync',      'auth'=>'api_key',  'auth_label'=>'Personal Access Token','auth_hint'=>'figd_…',     'url'=>'https://www.figma.com/developers/api#access-tokens' ],
				[ 'id'=>'github',      'name'=>'GitHub',              'letter'=>'G', 'color'=>'#0d1117', 'meta'=>'Repos · issues · PRs · Actions · releases',            'auth'=>'api_key',  'auth_label'=>'Personal Access Token','auth_hint'=>'ghp_…',      'url'=>'https://github.com/settings/tokens' ],
				[ 'id'=>'gitlab',      'name'=>'GitLab',              'letter'=>'G', 'color'=>'#fc6d26', 'meta'=>'Repos · pipelines · merge requests',                   'auth'=>'api_key',  'auth_label'=>'Access Token',     'auth_hint'=>'glpat-…',      'url'=>'https://gitlab.com/-/user_settings/personal_access_tokens' ],
				[ 'id'=>'linear',      'name'=>'Linear',              'letter'=>'L', 'color'=>'#5e6ad2', 'meta'=>'Issues · projects · cycles — embed on dashboard',      'auth'=>'api_key',  'auth_label'=>'API Key',          'auth_hint'=>'lin_api_…',    'url'=>'https://linear.app/settings/api' ],
				[ 'id'=>'asana',       'name'=>'Asana',               'letter'=>'A', 'color'=>'#f06a6a', 'meta'=>'Tasks · projects · timeline — sync into Therum lists', 'auth'=>'api_key',  'auth_label'=>'Personal Access Token','auth_hint'=>'…',          'url'=>'https://app.asana.com/0/my-apps' ],
				[ 'id'=>'clickup',     'name'=>'ClickUp',             'letter'=>'C', 'color'=>'#7b68ee', 'meta'=>'Workspaces · spaces · lists · docs · automations',     'auth'=>'api_key',  'auth_label'=>'Personal Token',   'auth_hint'=>'pk_…',         'url'=>'https://app.clickup.com/settings/apps' ],
				[ 'id'=>'hubspot',     'name'=>'HubSpot',             'letter'=>'H', 'color'=>'#ff7a59', 'meta'=>'Contacts · deals · marketing · service hub',           'auth'=>'api_key',  'auth_label'=>'Private App Token','auth_hint'=>'pat-na1-…',    'url'=>'https://app.hubspot.com/private-apps' ],
			],
		];
	}

	public static function get_connections(): array {
		return (array) get_option( self::OPTION_KEY, [] );
	}

	// ── At-rest encryption (AES-256-GCM) ───────────────────────────────────
	// Credentials are encrypted with a 32-byte key derived from SECURE_AUTH_KEY
	// + a domain-scoped HKDF info string. Each value gets a fresh random IV
	// and an authentication tag — tamper / corruption is detected on decrypt.
	//
	// Caveat: if SECURE_AUTH_KEY ever rotates (e.g. `wp config shuffle-salts`),
	// every stored credential becomes unrecoverable. This is the same
	// trade-off WP itself makes for cookies — documented in the connection
	// modal as "stored encrypted; re-enter if you rotate WP salts."
	private static function crypto_key(): string {
		$salt = defined( 'SECURE_AUTH_KEY' ) && SECURE_AUTH_KEY ? SECURE_AUTH_KEY : 'therum-fallback-salt-do-not-use-in-prod';
		return hash( 'sha256', $salt . '|therum-connections|v1', true );
	}
	public static function encrypt( string $plain ): string {
		if ( $plain === '' ) return '';
		$iv  = random_bytes( 12 ); // GCM standard IV length
		$tag = '';
		$ct  = openssl_encrypt( $plain, 'aes-256-gcm', self::crypto_key(), OPENSSL_RAW_DATA, $iv, $tag );
		if ( $ct === false ) return '';
		return 'v1:' . base64_encode( $iv . $tag . $ct );
	}
	public static function decrypt( string $blob ): string {
		if ( $blob === '' ) return '';
		if ( strpos( $blob, 'v1:' ) !== 0 ) return ''; // unknown / legacy format → caller asks user to re-enter
		$raw = base64_decode( substr( $blob, 3 ), true );
		if ( $raw === false || strlen( $raw ) < 28 ) return '';
		$iv  = substr( $raw, 0, 12 );
		$tag = substr( $raw, 12, 16 );
		$ct  = substr( $raw, 28 );
		$pt  = openssl_decrypt( $ct, 'aes-256-gcm', self::crypto_key(), OPENSSL_RAW_DATA, $iv, $tag );
		return $pt === false ? '' : $pt;
	}

	private static function save_connection( string $id, array $data ): void {
		$all = self::get_connections();
		// Encrypt the secret fields at rest. Label + timestamp stay plain.
		// key3/key4 are only populated for `multi` auth shape (added in 1.9.16+).
		foreach ( [ 'key', 'key2', 'key3', 'key4' ] as $k ) {
			if ( isset( $data[ $k ] ) && $data[ $k ] !== '' ) {
				$data[ $k ] = self::encrypt( (string) $data[ $k ] );
			}
		}
		$all[ $id ] = $data;
		update_option( self::OPTION_KEY, $all, false );
	}

	private static function remove_connection( string $id ): void {
		$all = self::get_connections();
		unset( $all[ $id ] );
		update_option( self::OPTION_KEY, $all, false );
	}

	// ── Custom (user-defined) providers ───────────────────────────────────────
	// Stored in a separate option so the built-in registry stays a pure code
	// constant. Each row is keyed by user-supplied slug and carries the same
	// shape as a built-in provider row (name/letter/color/auth/etc.) plus a
	// 'category' field so providers() knows where to merge it. Credentials
	// for custom providers go in the same OPTION_KEY map as built-ins.
	const OPTION_CUSTOM = 'therum_connections_custom';

	public static function get_custom_providers(): array {
		return (array) get_option( self::OPTION_CUSTOM, [] );
	}

	private static function save_custom_provider( string $slug, array $row ): void {
		$all = self::get_custom_providers();
		$all[ $slug ] = $row;
		update_option( self::OPTION_CUSTOM, $all, false );
	}

	private static function remove_custom_provider( string $slug ): void {
		$all = self::get_custom_providers();
		unset( $all[ $slug ] );
		update_option( self::OPTION_CUSTOM, $all, false );
		// Provider gone → orphan credential goes with it.
		self::remove_connection( $slug );
	}

	/**
	 * Decrypt and return the live credential for a connector. The downstream
	 * Therum integrations (Anthropic, OpenAI, etc.) call this to load the
	 * stored key on each request. Returns ['key' => '', 'key2' => ''] when
	 * the connector hasn't been configured.
	 */
	public static function get_credential( string $id ): array {
		$all = self::get_connections();
		$row = $all[ sanitize_key( $id ) ] ?? null;
		if ( ! is_array( $row ) ) return [ 'key' => '', 'key2' => '', 'key3' => '', 'key4' => '' ];
		return [
			'key'   => self::decrypt( (string) ( $row['key']  ?? '' ) ),
			'key2'  => self::decrypt( (string) ( $row['key2'] ?? '' ) ),
			'key3'  => self::decrypt( (string) ( $row['key3'] ?? '' ) ),
			'key4'  => self::decrypt( (string) ( $row['key4'] ?? '' ) ),
			'label' => (string) ( $row['label'] ?? '' ),
		];
	}

	public static function ajax_connect(): void {
		if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'forbidden', 403 );
		check_ajax_referer( 'therum_connections', 'nonce' );

		// Real at-rest encryption shipped in 1.9.1 (see crypto_key/encrypt/
		// decrypt above) — the dev-only gate is gone. SECURE_AUTH_KEY is the
		// root salt; rotating it invalidates every stored credential.
		if ( ! function_exists( 'openssl_encrypt' ) ) {
			wp_send_json_error( [ 'message' => 'OpenSSL extension is required to store credentials securely. Install php-openssl on this host.' ] );
		}

		$id    = sanitize_key( $_POST['provider_id'] ?? '' );
		$key   = sanitize_text_field( wp_unslash( $_POST['key']  ?? '' ) );
		$key2  = sanitize_text_field( wp_unslash( $_POST['key2'] ?? '' ) );
		$key3  = sanitize_text_field( wp_unslash( $_POST['key3'] ?? '' ) );
		$key4  = sanitize_text_field( wp_unslash( $_POST['key4'] ?? '' ) );
		$label = sanitize_text_field( wp_unslash( $_POST['label'] ?? '' ) );

		if ( ! $id || ! $key ) wp_send_json_error( 'missing fields' );

		self::save_connection( $id, [
			'key'          => $key,
			'key2'         => $key2,
			'key3'         => $key3,
			'key4'         => $key4,
			'label'        => $label,
			'connected_at' => time(),
		] );

		self::audit_log( 'connected', $id );
		wp_send_json_success( [ 'id' => $id, 'connected_at' => human_time_diff( time() ) ] );
	}

	/**
	 * Append a connect/disconnect entry to the rolling audit log (last 200).
	 * Called from inside the already-authorized ajax_connect / ajax_disconnect
	 * handlers — NOT a standalone AJAX action — so it inherits their nonce +
	 * capability checks rather than running unauthenticated.
	 *
	 * @param string $action 'connected' or 'disconnected'
	 * @param string $id     Provider id (already sanitized by the caller)
	 */
	private static function audit_log( string $action, string $id ): void {
		$entries   = (array) get_option( 'therum_conn_audit_log', [] );
		$entries[] = [
			'time'     => time(),
			'action'   => $action,
			'provider' => $id,
			'user'     => wp_get_current_user()->user_login,
		];
		$entries = array_slice( $entries, -200 );
		update_option( 'therum_conn_audit_log', $entries, false );
		update_option( 'therum_conn_audit_count', count( $entries ), false );
	}

	/**
	 * Providers that have a real test endpoint implementation below. Used
	 * to gate the Test button in the modal — the button is hidden for any
	 * provider not on this list so users don't click into a stub.
	 *
	 * To add a new testable provider:
	 *   1. Add a case to ajax_test() that makes a real auth-validating request
	 *      (prefer GET /models or GET /user — cheaper than chat completions)
	 *   2. Add its id here
	 *   3. Done — the button shows automatically
	 */
	const TESTABLE = [
		'anthropic', 'openai', 'google-gemini', 'xai',
		'mistral', 'deepseek', 'groq', 'cohere',
		'huggingface', 'github', 'gitlab', 'elevenlabs',
	];

	public static function is_testable( string $id ): bool {
		return in_array( $id, self::TESTABLE, true );
	}

	/**
	 * Helper: OpenAI-compatible providers. They all expose GET /v1/models
	 * (or close) with Bearer auth. Doesn't burn tokens — just validates the
	 * key. Calls wp_send_json_{success,error} directly.
	 */
	private static function test_openai_compat( string $base_url, string $models_path, string $key, string $name ): void {
		$res = wp_remote_get( rtrim( $base_url, '/' ) . $models_path, [
			'timeout' => 12,
			'headers' => [ 'authorization' => 'Bearer ' . $key ],
		] );
		if ( is_wp_error( $res ) ) wp_send_json_error( [ 'message' => $res->get_error_message() ] );
		$code = (int) wp_remote_retrieve_response_code( $res );
		if ( $code === 200 ) {
			$body = json_decode( wp_remote_retrieve_body( $res ), true );
			$n    = is_array( $body['data'] ?? null ) ? count( $body['data'] ) : null;
			wp_send_json_success( [ 'message' => $name . ' authenticated' . ( $n !== null ? ' · ' . $n . ' models visible' : '' ) . '.' ] );
		}
		$body = json_decode( wp_remote_retrieve_body( $res ), true );
		$msg  = $body['error']['message'] ?? wp_remote_retrieve_response_message( $res );
		wp_send_json_error( [ 'message' => $name . ' returned ' . $code . ' — ' . $msg ] );
	}

	/**
	 * Helper: token-authenticated GET against an identity endpoint (whoami).
	 * Used for GitHub, GitLab, HuggingFace, ElevenLabs — each takes a
	 * slightly different header name, so callers pass the headers map.
	 */
	private static function test_whoami( string $url, array $headers, string $name, ?string $identity_field = null ): void {
		$headers['accept']     = 'application/json';
		$headers['user-agent'] = 'Therum-OS/' . THERUM_OS_VERSION;
		$res = wp_remote_get( $url, [ 'timeout' => 12, 'headers' => $headers ] );
		if ( is_wp_error( $res ) ) wp_send_json_error( [ 'message' => $res->get_error_message() ] );
		$code = (int) wp_remote_retrieve_response_code( $res );
		$body = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( $code === 200 ) {
			$who = $identity_field && is_array( $body ) ? ( $body[ $identity_field ] ?? '' ) : '';
			wp_send_json_success( [ 'message' => $name . ' authenticated' . ( $who ? ' as ' . $who : '' ) . '.' ] );
		}
		$msg = $body['message'] ?? $body['error']['message'] ?? wp_remote_retrieve_response_message( $res );
		wp_send_json_error( [ 'message' => $name . ' returned ' . $code . ' — ' . $msg ] );
	}

	/**
	 * Live test of a stored credential. Reaches out to the provider's API
	 * with the stored key and reports the result. Only providers on the
	 * TESTABLE list get past the early-return guard — everything else
	 * never sees the button (gated in the modal JS via testableIds).
	 */
	public static function ajax_test(): void {
		if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'forbidden', 403 );
		check_ajax_referer( 'therum_connections', 'nonce' );

		$id = sanitize_key( $_POST['provider_id'] ?? '' );
		if ( ! $id ) wp_send_json_error( 'missing id' );

		// Refuse cleanly for providers without a real test implementation,
		// even though the JS hides the button — defense in depth against
		// someone POSTing directly.
		if ( ! self::is_testable( $id ) ) {
			wp_send_json_error( [ 'message' => 'Live test isn\'t implemented for this provider yet. Open an issue if you want it added.' ] );
		}

		$cred = self::get_credential( $id );
		if ( $cred['key'] === '' ) wp_send_json_error( [ 'message' => 'No credential on file. Connect first.' ] );

		switch ( $id ) {
			case 'anthropic':
				$res = wp_remote_post( 'https://api.anthropic.com/v1/messages', [
					'timeout' => 15,
					'headers' => [
						'x-api-key'         => $cred['key'],
						'anthropic-version' => '2023-06-01',
						'content-type'      => 'application/json',
					],
					'body' => wp_json_encode( [
						'model'      => 'claude-haiku-4-5-20251001',
						'max_tokens' => 8,
						'messages'   => [ [ 'role' => 'user', 'content' => 'Reply with the single word: ok' ] ],
					] ),
				] );
				if ( is_wp_error( $res ) ) wp_send_json_error( [ 'message' => $res->get_error_message() ] );
				$code = wp_remote_retrieve_response_code( $res );
				if ( $code !== 200 ) {
					$body = json_decode( wp_remote_retrieve_body( $res ), true );
					$msg  = $body['error']['message'] ?? wp_remote_retrieve_response_message( $res );
					wp_send_json_error( [ 'message' => 'Anthropic returned ' . $code . ' — ' . $msg ] );
				}
				$body = json_decode( wp_remote_retrieve_body( $res ), true );
				$out  = $body['content'][0]['text'] ?? '';
				wp_send_json_success( [ 'message' => 'Claude replied: ' . trim( $out ) ] );

			// ── OpenAI-compatible providers — GET /v1/models doesn't burn tokens
			case 'openai':       self::test_openai_compat( 'https://api.openai.com',    '/v1/models', $cred['key'], 'OpenAI' );      break;
			case 'xai':          self::test_openai_compat( 'https://api.x.ai',          '/v1/models', $cred['key'], 'xAI' );         break;
			case 'mistral':      self::test_openai_compat( 'https://api.mistral.ai',    '/v1/models', $cred['key'], 'Mistral' );     break;
			case 'deepseek':     self::test_openai_compat( 'https://api.deepseek.com',  '/models',    $cred['key'], 'DeepSeek' );    break;
			case 'groq':         self::test_openai_compat( 'https://api.groq.com',      '/openai/v1/models', $cred['key'], 'Groq' ); break;
			case 'cohere':       self::test_openai_compat( 'https://api.cohere.com',    '/v1/models', $cred['key'], 'Cohere' );      break;

			// ── Google Gemini — key as query param, not header
			case 'google-gemini':
				$res = wp_remote_get( 'https://generativelanguage.googleapis.com/v1beta/models?key=' . rawurlencode( $cred['key'] ), [ 'timeout' => 12 ] );
				if ( is_wp_error( $res ) ) wp_send_json_error( [ 'message' => $res->get_error_message() ] );
				$code = (int) wp_remote_retrieve_response_code( $res );
				$body = json_decode( wp_remote_retrieve_body( $res ), true );
				if ( $code === 200 ) {
					$n = is_array( $body['models'] ?? null ) ? count( $body['models'] ) : null;
					wp_send_json_success( [ 'message' => 'Gemini authenticated' . ( $n !== null ? ' · ' . $n . ' models visible' : '' ) . '.' ] );
				}
				wp_send_json_error( [ 'message' => 'Gemini returned ' . $code . ' — ' . ( $body['error']['message'] ?? wp_remote_retrieve_response_message( $res ) ) ] );

			// ── Identity endpoints (whoami-style) — different header names per provider
			case 'github':
				self::test_whoami( 'https://api.github.com/user', [
					'authorization' => 'Bearer ' . $cred['key'],
				], 'GitHub', 'login' );
				break;
			case 'gitlab':
				self::test_whoami( 'https://gitlab.com/api/v4/user', [
					'private-token' => $cred['key'],
				], 'GitLab', 'username' );
				break;
			case 'huggingface':
				self::test_whoami( 'https://huggingface.co/api/whoami-v2', [
					'authorization' => 'Bearer ' . $cred['key'],
				], 'Hugging Face', 'name' );
				break;
			case 'elevenlabs':
				self::test_whoami( 'https://api.elevenlabs.io/v1/user', [
					'xi-api-key' => $cred['key'],
				], 'ElevenLabs', null );
				break;
			default:
				// is_testable() above only allows known ids through, but a
				// new entry on TESTABLE without a matching switch case must
				// not hang the test button — fail loud so the gap is caught.
				wp_send_json_error( [ 'message' => 'No test implementation for "' . $id . '".' ] );
		}
	}

	public static function ajax_disconnect(): void {
		if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'forbidden', 403 );
		check_ajax_referer( 'therum_connections', 'nonce' );

		$id = sanitize_key( $_POST['provider_id'] ?? '' );
		if ( ! $id ) wp_send_json_error( 'missing id' );

		self::remove_connection( $id );
		self::audit_log( 'disconnected', $id );
		wp_send_json_success( [ 'id' => $id ] );
	}

	// ── Add / edit a user-defined custom provider ─────────────────────────────
	// Upsert keyed by slug. Same handler covers Add (no editing_slug) and
	// Edit (editing_slug == slug, or rename when they differ). Optionally
	// also writes the credential row in one call, so the user can define
	// the provider AND paste their key in a single modal.
	public static function ajax_add_custom(): void {
		if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'forbidden', 403 );
		check_ajax_referer( 'therum_connections', 'nonce' );

		$category = sanitize_key( $_POST['category'] ?? '' );
		$cats     = self::categories();
		if ( ! isset( $cats[ $category ] ) ) wp_send_json_error( [ 'message' => 'Unknown category.' ] );

		$name = trim( sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) ) );
		if ( $name === '' ) wp_send_json_error( [ 'message' => 'Name is required.' ] );

		$orig_slug = sanitize_key( wp_unslash( $_POST['slug'] ?? '' ) );
		$slug      = $orig_slug !== '' ? $orig_slug : sanitize_title( $name );
		if ( $slug === '' ) wp_send_json_error( [ 'message' => 'Could not derive a slug from that name.' ] );

		$editing_slug = sanitize_key( wp_unslash( $_POST['editing_slug'] ?? '' ) );

		// Collision check: built-ins win, you can't shadow them. Custom-vs-custom
		// collisions are fine only when you're editing the same row.
		$builtin_ids = [];
		foreach ( self::_builtin_providers() as $list ) {
			foreach ( $list as $p ) $builtin_ids[ $p['id'] ] = true;
		}
		if ( isset( $builtin_ids[ $slug ] ) ) {
			wp_send_json_error( [ 'message' => 'A built-in provider already uses the id "' . $slug . '". Pick a different slug.' ] );
		}
		$existing_custom = self::get_custom_providers();
		if ( isset( $existing_custom[ $slug ] ) && $slug !== $editing_slug ) {
			wp_send_json_error( [ 'message' => 'A custom provider with the id "' . $slug . '" already exists.' ] );
		}

		$auth = sanitize_key( wp_unslash( $_POST['auth'] ?? 'api_key' ) );
		// `multi` = 1–4 user-defined credential fields (key, secret, auth secret, workspace id, …).
		// Existing api_key/sid_token/base_url shapes stay unchanged for backward compat.
		if ( ! in_array( $auth, [ 'api_key', 'sid_token', 'base_url', 'multi' ], true ) ) $auth = 'api_key';

		$color = sanitize_text_field( wp_unslash( $_POST['color'] ?? '' ) );
		if ( ! preg_match( '/^#[0-9a-fA-F]{6}$/', $color ) ) {
			$color = '#' . substr( md5( $slug ), 0, 6 );
		}

		$letter = strtoupper( mb_substr( $name, 0, 1 ) );
		if ( $letter === '' ) $letter = '?';

		$row = [
			'category'   => $category,
			'name'       => $name,
			'letter'     => $letter,
			'color'      => $color,
			'meta'       => sanitize_text_field( wp_unslash( $_POST['meta'] ?? '' ) ),
			'auth'       => $auth,
			'auth_label' => sanitize_text_field( wp_unslash( $_POST['auth_label'] ?? ( $auth === 'base_url' ? 'Base URL' : 'API Key' ) ) ),
			'auth_hint'  => sanitize_text_field( wp_unslash( $_POST['auth_hint'] ?? '' ) ),
			'url'        => esc_url_raw( wp_unslash( $_POST['url'] ?? '' ) ),
		];
		if ( $auth === 'sid_token' ) {
			$row['auth_label2'] = sanitize_text_field( wp_unslash( $_POST['auth_label2'] ?? 'Token' ) );
			$row['auth_hint2']  = sanitize_text_field( wp_unslash( $_POST['auth_hint2'] ?? '' ) );
		}

		// `multi` shape — copy 1–4 label+type pairs onto the provider row so
		// the Connect modal can render the right inputs and the credential
		// vault knows which slots are filled. Row 1's label is required;
		// rows 2–4 are skipped when blank.
		if ( $auth === 'multi' ) {
			$labels = [];
			$types  = [];
			for ( $i = 1; $i <= 4; $i++ ) {
				$lbl = trim( sanitize_text_field( wp_unslash( $_POST[ 'cred_label_' . $i ] ?? '' ) ) );
				if ( $lbl === '' ) continue;
				$typ = sanitize_key( wp_unslash( $_POST[ 'cred_type_' . $i ] ?? 'password' ) );
				if ( ! in_array( $typ, [ 'password', 'text' ], true ) ) $typ = 'password';
				$labels[] = $lbl;
				$types[]  = $typ;
			}
			if ( empty( $labels ) ) {
				wp_send_json_error( [ 'message' => 'At least one credential field is required for "Multiple fields" auth.' ] );
			}
			// Reuse auth_label / auth_label2 for the first two so existing
			// downstream consumers (vault display, connect modal) keep working.
			$row['auth_label']  = $labels[0];
			$row['auth_type']   = $types[0];
			if ( isset( $labels[1] ) ) { $row['auth_label2'] = $labels[1]; $row['auth_type2'] = $types[1]; }
			if ( isset( $labels[2] ) ) { $row['auth_label3'] = $labels[2]; $row['auth_type3'] = $types[2]; }
			if ( isset( $labels[3] ) ) { $row['auth_label4'] = $labels[3]; $row['auth_type4'] = $types[3]; }
			$row['auth_count']  = count( $labels );
		}

		// Rename path: editing an existing custom and the slug changed. Move
		// the credential (if any) to the new id, then drop the old row.
		if ( $editing_slug && $editing_slug !== $slug ) {
			$conns = self::get_connections();
			if ( isset( $conns[ $editing_slug ] ) ) {
				$conns[ $slug ] = $conns[ $editing_slug ];
				unset( $conns[ $editing_slug ] );
				update_option( self::OPTION_KEY, $conns, false );
			}
			$cust = self::get_custom_providers();
			unset( $cust[ $editing_slug ] );
			update_option( self::OPTION_CUSTOM, $cust, false );
		}

		self::save_custom_provider( $slug, $row );

		// Optional inline credential capture. Encryption handled by save_connection().
		$key   = sanitize_text_field( wp_unslash( $_POST['key']   ?? '' ) );
		$key2  = sanitize_text_field( wp_unslash( $_POST['key2']  ?? '' ) );
		$key3  = sanitize_text_field( wp_unslash( $_POST['key3']  ?? '' ) );
		$key4  = sanitize_text_field( wp_unslash( $_POST['key4']  ?? '' ) );
		$label = sanitize_text_field( wp_unslash( $_POST['label'] ?? '' ) );
		if ( $key !== '' ) {
			self::save_connection( $slug, [
				'key'          => $key,
				'key2'         => $key2,
				'key3'         => $key3,
				'key4'         => $key4,
				'label'        => $label,
				'connected_at' => time(),
			] );
		}

		wp_send_json_success( [
			'id'      => $slug,
			'editing' => (bool) $editing_slug,
		] );
	}

	public static function ajax_delete_custom(): void {
		if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'forbidden', 403 );
		check_ajax_referer( 'therum_connections', 'nonce' );

		$slug = sanitize_key( wp_unslash( $_POST['provider_id'] ?? '' ) );
		if ( ! $slug ) wp_send_json_error( 'missing id' );

		$custom = self::get_custom_providers();
		if ( ! isset( $custom[ $slug ] ) ) {
			wp_send_json_error( [ 'message' => 'Built-in providers can\'t be deleted.' ] );
		}

		self::remove_custom_provider( $slug ); // also clears the credential
		wp_send_json_success( [ 'id' => $slug ] );
	}

	// ── Render ────────────────────────────────────────────────────────────────
	public static function render(): void {
		$all_providers  = self::providers();
		$categories     = self::categories();
		$connections    = self::get_connections();
		$nonce          = wp_create_nonce( 'therum_connections' );

		// Active category from URL, default to first
		$tab = sanitize_key( $_GET['tab'] ?? '' );
		if ( ! isset( $categories[ $tab ] ) ) $tab = array_key_first( $categories );

		$base_url = admin_url( 'admin.php?page=therum-connections' );

		// Count connected per category
		$cat_counts = [];
		foreach ( $all_providers as $cat => $provs ) {
			$total     = count( $provs );
			$connected = 0;
			foreach ( $provs as $p ) { if ( isset( $connections[ $p['id'] ] ) ) $connected++; }
			$cat_counts[ $cat ] = [ 'connected' => $connected, 'total' => $total ];
		}

		$providers = $all_providers[ $tab ] ?? [];
		$cat       = $categories[ $tab ];

		// Management tabs (static, no providers)
		$manage_tabs = [
			'api-webhooks'  => 'API &amp; Webhooks',
			'api-vault'     => 'API keys vault',
			'webhooks-log'  => 'Webhooks log',
			'audit-log'     => 'Audit log',
		];
		$is_manage = isset( $manage_tabs[ $tab ] );
		?>
<style>
.thc-wrap{display:grid;grid-template-columns:240px 1fr;min-height:calc(100vh - var(--topbar-h,72px));gap:0}
.thc-sidebar{padding:20px 12px;border-right:1px solid var(--bd);position:sticky;top:0;align-self:start}
.thc-sidebar-group-label{font-size:10px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--tx3);padding:14px 10px 6px}
.thc-nav-item{display:flex;align-items:center;gap:8px;padding:8px 12px;border-radius:7px;color:var(--tx2);font-size:13px;font-weight:500;text-decoration:none;cursor:pointer;transition:all var(--e,.15s ease);margin-bottom:2px}
.thc-nav-item:hover{background:var(--sf2);color:var(--tx)}
.thc-nav-item.active{background:rgba(37,99,235,.08);color:var(--ac)}
.thc-nav-dot{width:7px;height:7px;border-radius:50%;background:var(--bd2);flex-shrink:0}
.thc-nav-dot.connected{background:var(--ok)}
.thc-nav-dot.partial{background:var(--wrn)}
.thc-nav-count{margin-left:auto;font-size:11px;font-weight:600;color:var(--tx3)}
.thc-nav-item.active .thc-nav-count{color:var(--ac)}
.thc-content{padding:32px 36px 80px}
.thc-breadcrumb{font-size:10px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:var(--tx3);margin-bottom:10px}
.thc-title{font-size:22px;font-weight:700;color:var(--tx);margin:0 0 6px}
.thc-desc{font-size:13px;color:var(--tx2);margin:0 0 24px;line-height:1.6}
.thc-toolbar{display:flex;align-items:center;gap:10px;margin-bottom:24px}
.thc-search{display:flex;align-items:center;gap:8px;padding:8px 12px;background:var(--sf2);border:1px solid var(--bd);border-radius:8px;flex:1;max-width:320px}
.thc-search svg{color:var(--tx3);flex-shrink:0}
.thc-search input{border:none;outline:none;background:transparent;font-size:13px;color:var(--tx);width:100%}
.thc-search input::placeholder{color:var(--tx3)}
.thc-providers{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:14px}
.thc-card{background:var(--sf);border:1px solid var(--bd);border-radius:12px;padding:20px;display:flex;flex-direction:column;gap:0;transition:border-color var(--e,.15s ease)}
.thc-card:hover{border-color:var(--bd2)}
.thc-card-head{display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:14px}
.thc-card-logo{width:44px;height:44px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:18px;color:#fff;flex-shrink:0}
.thc-card-status{font-size:10px;font-weight:700;letter-spacing:.08em;display:flex;align-items:center;gap:5px}
.thc-card-status-dot{width:6px;height:6px;border-radius:50%;background:var(--bd2)}
.thc-card-status-dot.ok{background:var(--ok)}
.thc-card-name{font-size:15px;font-weight:700;color:var(--tx);margin-bottom:4px}
.thc-card-meta{font-size:12px;color:var(--tx3);line-height:1.5;margin-bottom:16px;flex:1}
.thc-card-foot{display:flex;align-items:center;justify-content:space-between;padding-top:14px;border-top:1px solid var(--bd)}
.thc-card-action{font-size:13px;font-weight:600;color:var(--tx);text-decoration:none;cursor:pointer;background:transparent;border:none;padding:0;display:inline-flex;align-items:center;gap:4px;transition:color var(--e)}
.thc-card-action:hover{color:var(--ac)}
.thc-card-action.connected{color:var(--ac)}
.thc-card-detail{font-size:11px;color:var(--tx3)}
/* Connect modal */
.thc-modal-backdrop{position:fixed;inset:0;background:rgba(0,0,0,.5);backdrop-filter:blur(4px);z-index:9990;display:flex;align-items:center;justify-content:center}
.thc-modal{background:var(--sf);border:1px solid var(--bd);border-radius:16px;padding:28px;width:440px;max-width:calc(100vw - 40px);box-shadow:0 24px 64px rgba(0,0,0,.25)}
.thc-modal-head{display:flex;align-items:center;gap:14px;margin-bottom:20px}
.thc-modal-logo{width:44px;height:44px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:18px;color:#fff;flex-shrink:0}
.thc-modal-title{font-size:17px;font-weight:700;color:var(--tx)}
.thc-modal-sub{font-size:12px;color:var(--tx3);margin-top:2px}
.thc-modal-close{margin-left:auto;width:28px;height:28px;border-radius:6px;background:transparent;border:1px solid var(--bd);cursor:pointer;display:flex;align-items:center;justify-content:center;color:var(--tx3);flex-shrink:0}
.thc-modal-close:hover{border-color:var(--tx2);color:var(--tx)}
.thc-field{margin-bottom:16px}
.thc-field label{display:block;font-size:12px;font-weight:600;color:var(--tx2);margin-bottom:6px}
.thc-field input{width:100%;padding:9px 12px;background:var(--sf2);border:1px solid var(--bd);border-radius:8px;font-size:13px;color:var(--tx);outline:none;box-sizing:border-box;transition:border-color .2s ease}
.thc-field input:focus{border-color:var(--ac)}
.thc-field .thc-field-hint{font-size:11px;color:var(--tx3);margin-top:4px}
.thc-field a{color:var(--ac);text-decoration:none;font-size:11px}
.thc-modal-actions{display:flex;gap:8px;justify-content:flex-end;margin-top:20px}
.thc-modal-err{font-size:12px;color:var(--err);margin-top:10px;display:none}
.thc-modal-step[hidden]{display:none!important}
.thc-modal-back{background:none;border:none;color:var(--tx3);font-size:12px;font-weight:500;cursor:pointer;padding:0 0 14px;font-family:var(--f)}
.thc-modal-back:hover{color:var(--tx)}
.thc-method-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.thc-method{text-align:left;background:var(--sf2);border:1px solid var(--bd);border-radius:12px;padding:18px;cursor:pointer;font-family:var(--f);transition:all .15s ease;display:flex;flex-direction:column;gap:6px;min-height:140px}
.thc-method:hover{border-color:var(--ac);background:var(--sf);transform:translateY(-1px);box-shadow:0 6px 20px rgba(0,0,0,.06)}
.thc-method-icon{font-size:22px;line-height:1;margin-bottom:6px}
.thc-method-name{font-size:14px;font-weight:600;color:var(--tx)}
.thc-method-desc{font-size:12px;color:var(--tx3);line-height:1.45}
.thc-method{position:relative}
.thc-method-badge{position:absolute;top:10px;right:10px;font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;padding:3px 7px;border-radius:999px;background:color-mix(in srgb,var(--wrn) 16%,transparent);color:var(--wrn);border:1px solid color-mix(in srgb,var(--wrn) 40%,transparent)}
.thc-method-badge.is-ready{background:color-mix(in srgb,var(--ok) 16%,transparent);color:var(--ok);border-color:color-mix(in srgb,var(--ok) 40%,transparent)}
.thc-signin-steps{background:var(--sf2);border:1px solid var(--bd);border-radius:10px;padding:14px 16px;margin-bottom:14px;font-size:13px;color:var(--tx2);line-height:1.55}
.thc-signin-steps ol{margin:0;padding-left:18px;display:flex;flex-direction:column;gap:6px}
.thc-signin-steps a{color:var(--ac);text-decoration:none;font-weight:600}
.thc-signin-steps a:hover{text-decoration:underline}
.thc-signin-cta{display:inline-flex;align-items:center;gap:6px;background:var(--tx);color:var(--sf);border:1px solid var(--tx);border-radius:8px;padding:8px 14px;font:600 12px/1 var(--f);text-decoration:none;margin-top:10px}
.thc-signin-cta:hover{background:var(--ac);border-color:var(--ac);color:#fff}
/* Manage panel */
.thc-manage-panel{background:var(--sf2);border:1px solid var(--bd);border-radius:12px;padding:20px;margin-top:14px;display:none}
.thc-manage-panel.open{display:block}
/* Custom provider chrome */
.thc-card.is-custom{border-style:dashed}
.thc-card-badge-custom{font-size:9px;font-weight:700;letter-spacing:.08em;padding:2px 6px;border-radius:999px;background:color-mix(in srgb,var(--ac) 12%,transparent);color:var(--ac);border:1px solid color-mix(in srgb,var(--ac) 30%,transparent);margin-right:6px}
.thc-card-customops{display:inline-flex;align-items:center;gap:4px}
.thc-card-mini{background:none;border:none;cursor:pointer;font:600 11px/1.2 var(--f);color:var(--tx3);padding:2px 4px;border-radius:4px;transition:color var(--e),background var(--e)}
.thc-card-mini:hover{color:var(--tx);background:var(--sf2)}
.thc-card-mini-danger{color:var(--err)}
.thc-card-mini-danger:hover{color:var(--err);background:color-mix(in srgb,var(--err) 10%,transparent)}
.thc-card-mini-sep{color:var(--bd2);font-size:10px}
/* Add-custom modal: a wider variant of .thc-modal */
#thc-modal-addcustom .thc-field-row{display:grid;grid-template-columns:1fr 120px;gap:10px}
#thc-modal-addcustom .thc-field-color input{height:36px;width:100%;padding:0;border-radius:8px;border:1px solid var(--bd);background:var(--sf2);cursor:pointer}
.thc-ac-divider{border:none;border-top:1px solid var(--bd);margin:18px 0}
.thc-ac-section-label{font-size:10px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--tx3);margin-bottom:8px}
</style>

<div class="thc-wrap">

  <!-- Sidebar -->
  <nav class="thc-sidebar">
    <div class="thc-sidebar-group-label">Connections</div>
    <?php foreach ( $categories as $cat_key => $cat_data ):
      $cc      = $cat_counts[ $cat_key ] ?? [ 'connected' => 0, 'total' => 0 ];
      $dot_cls = $cc['connected'] === 0 ? '' : ( $cc['connected'] < $cc['total'] ? 'partial' : 'connected' );
      $is_active = $tab === $cat_key;
    ?>
    <a href="<?php echo esc_url( add_query_arg( 'tab', $cat_key, $base_url ) ); ?>"
       class="thc-nav-item <?php echo $is_active ? 'active' : ''; ?>">
      <span class="thc-nav-dot <?php echo esc_attr( $dot_cls ); ?>"></span>
      <?php echo esc_html( $cat_data['label'] ); ?>
      <?php if ( $cc['total'] > 0 ): ?>
      <span class="thc-nav-count"><?php echo (int) $cc['connected']; ?> / <?php echo (int) $cc['total']; ?></span>
      <?php endif; ?>
    </a>
    <?php endforeach; ?>

    <div class="thc-sidebar-group-label" style="margin-top:16px">Manage</div>
    <?php foreach ( $manage_tabs as $mt_key => $mt_label ):
      $is_active = $tab === $mt_key;
    ?>
    <a href="<?php echo esc_url( add_query_arg( 'tab', $mt_key, $base_url ) ); ?>"
       class="thc-nav-item <?php echo $is_active ? 'active' : ''; ?>">
      <span class="thc-nav-dot"></span>
      <?php echo $mt_label; ?>
      <?php if ( $mt_key === 'api-vault' ): ?>
      <span class="thc-nav-count"><?php echo count( $connections ); ?></span>
      <?php endif; ?>
      <?php if ( $mt_key === 'audit-log' ): ?>
      <span class="thc-nav-count"><?php echo (int) get_option('therum_conn_audit_count', 0); ?></span>
      <?php endif; ?>
    </a>
    <?php endforeach; ?>
  </nav>

  <!-- Content -->
  <div class="thc-content">

    <?php
    // OAuth result banner — set by the callback handler via redirect.
    $oauth_ok  = sanitize_key( $_GET['oauth_ok']  ?? '' );
    $oauth_err = sanitize_text_field( $_GET['oauth_err'] ?? '' );
    if ( $oauth_ok ) {
      $name = '';
      foreach ( $all_providers as $list ) { foreach ( $list as $pp ) { if ( $pp['id'] === $oauth_ok ) { $name = $pp['name']; break 2; } } }
      echo '<div style="background:color-mix(in srgb, var(--ok) 12%, transparent);border:1px solid var(--ok);color:var(--tx);border-radius:10px;padding:12px 16px;margin-bottom:16px;font-size:13px;font-weight:500">✓ Signed in to ' . esc_html( $name ?: $oauth_ok ) . ' — token stored encrypted, ready to use.</div>';
    } elseif ( $oauth_err ) {
      echo '<div style="background:color-mix(in srgb, var(--err) 12%, transparent);border:1px solid var(--err);color:var(--tx);border-radius:10px;padding:12px 16px;margin-bottom:16px;font-size:13px;font-weight:500">✗ OAuth failed: ' . esc_html( $oauth_err ) . '</div>';
    }
    ?>

    <?php if ( $is_manage ): ?>
      <?php self::render_manage_tab( $tab, $connections, $nonce ); ?>

    <?php else:
      $cat_data = $categories[ $tab ];
    ?>
    <div class="thc-breadcrumb">CONNECTIONS · <?php echo esc_html( strtoupper( $cat_data['label'] ) ); ?></div>
    <h1 class="thc-title"><?php echo esc_html( $cat_data['label'] ); ?></h1>
    <p class="thc-desc"><?php echo esc_html( $cat_data['desc'] ); ?></p>

    <div class="thc-toolbar">
      <div class="thc-search">
        <?php echo therum_i('search'); ?>
        <input type="text" id="thc-search-input" placeholder="Search <?php echo esc_attr( strtolower( $cat_data['label'] ) ); ?> providers&hellip;" autocomplete="off" />
      </div>
      <span class="thc-toolbar-hint" style="font-size:11px;color:var(--tx3)">Click any card to connect &middot; or add your own</span>
      <button type="button" class="th-btn th-btn-primary" style="margin-left:auto" data-thc-add-custom="<?php echo esc_attr( $tab ); ?>">＋ Add custom</button>
    </div>

    <div class="thc-providers" id="thc-providers">
      <?php foreach ( $providers as $p ):
        $conn = $connections[ $p['id'] ] ?? null;
        $is_connected = ! empty( $conn );
        $connected_at = $is_connected ? human_time_diff( (int) ($conn['connected_at'] ?? 0) ) . ' ago' : '';
        $auth_type  = $p['auth'] ?? 'api_key';
        $search_val = strtolower( $p['name'] . ' ' . ( $p['meta'] ?? '' ) );
      ?>
      <?php $is_custom = ! empty( $p['custom'] ); ?>
      <div class="thc-card<?php echo $is_custom ? ' is-custom' : ''; ?>" data-provider="<?php echo esc_attr( $p['id'] ); ?>" data-search="<?php echo esc_attr($search_val); ?>"<?php echo $is_custom ? ' data-custom="1"' : ''; ?>>
        <div class="thc-card-head">
          <div class="thc-card-logo" style="background:<?php echo esc_attr( $p['color'] ); ?>"><?php echo esc_html( $p['letter'] ); ?></div>
          <div class="thc-card-status">
            <?php if ( $is_custom ): ?>
            <span class="thc-card-badge-custom">CUSTOM</span>
            <?php endif; ?>
            <span class="thc-card-status-dot <?php echo $is_connected ? 'ok' : ''; ?>"></span>
            <?php echo $is_connected ? 'CONNECTED' : 'NOT CONNECTED'; ?>
          </div>
        </div>
        <div class="thc-card-name"><?php echo esc_html( $p['name'] ); ?></div>
        <div class="thc-card-meta"><?php echo esc_html( $p['meta'] ?? '' ); ?></div>
        <div class="thc-card-foot">
          <?php if ( $is_connected ): ?>
          <button type="button" class="thc-card-action connected" data-thc-manage="<?php echo esc_attr($p['id']); ?>">Manage →</button>
          <?php else: ?>
          <button type="button" class="thc-card-action" data-thc-connect="<?php echo esc_attr($p['id']); ?>">Connect →</button>
          <?php endif; ?>

          <?php if ( $is_custom ): ?>
          <span class="thc-card-detail thc-card-customops">
            <button type="button" class="thc-card-mini" data-thc-edit-custom="<?php echo esc_attr($p['id']); ?>">Edit</button>
            <span class="thc-card-mini-sep">·</span>
            <button type="button" class="thc-card-mini thc-card-mini-danger" data-thc-delete-custom="<?php echo esc_attr($p['id']); ?>">Delete</button>
          </span>
          <?php elseif ( $is_connected ): ?>
          <span class="thc-card-detail">linked <?php echo esc_html( $connected_at ); ?></span>
          <?php else: ?>
          <span class="thc-card-detail"><?php echo esc_html( $auth_type === 'base_url' ? 'localhost' : 'API key' ); ?></span>
          <?php endif; ?>
        </div>

        <!-- Manage panel (inline, shown below card) -->
        <?php if ( $is_connected ): ?>
        <div class="thc-manage-panel" id="thc-manage-<?php echo esc_attr($p['id']); ?>">
          <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px">
            <div style="font-size:13px;font-weight:600;color:var(--tx)">Connected <?php echo esc_html($connected_at); ?></div>
            <button type="button" class="thc-card-action" style="font-size:12px;color:var(--err)" data-thc-disconnect="<?php echo esc_attr($p['id']); ?>">Disconnect</button>
          </div>
          <?php if ( !empty($conn['label']) ): ?>
          <div style="font-size:12px;color:var(--tx3);margin-bottom:8px">Label: <strong style="color:var(--tx)"><?php echo esc_html($conn['label']); ?></strong></div>
          <?php endif; ?>
          <div style="font-size:12px;color:var(--tx3)">Key: <span style="font-family:monospace;color:var(--tx2)"><?php echo esc_html( substr( $conn['key'] ?? '', 0, 8 ) . str_repeat('•', 12) ); ?></span></div>
          <div style="margin-top:14px;display:flex;gap:8px">
            <button type="button" class="th-btn" style="font-size:12px" data-thc-rekey="<?php echo esc_attr($p['id']); ?>">Update key</button>
          </div>
        </div>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

  </div><!-- /.thc-content -->
</div><!-- /.thc-wrap -->

<!-- Connect modal -->
<div id="thc-modal" class="thc-modal-backdrop" style="display:none" role="dialog" aria-modal="true">
  <div class="thc-modal">
    <div class="thc-modal-head">
      <div class="thc-modal-logo" id="thc-modal-logo"></div>
      <div>
        <div class="thc-modal-title" id="thc-modal-title"></div>
        <div class="thc-modal-sub" id="thc-modal-sub"></div>
      </div>
      <button type="button" class="thc-modal-close" id="thc-modal-close"><?php echo therum_i('x'); ?></button>
    </div>
    <!-- Picker + Sign-in path are gated by per-provider auth_methods. The
         current registry has every provider as API-key-only (none of the
         AI tools, payment gateways, or apps have OAuth wired yet), so the
         picker stays hidden and the form renders directly. When a real
         OAuth handoff lands for a provider (Slack / GitHub / Google Drive
         / Stripe Connect / Notion / etc.), flip its `auth_methods` to
         `['api_key','oauth']` and the picker re-appears for that provider
         only. -->
    <div id="thc-modal-picker" class="thc-modal-step" hidden>
      <p class="thc-modal-sub" style="margin-bottom:18px">How do you want to connect?</p>
      <div class="thc-method-grid">
        <button type="button" class="thc-method" data-method="api_key">
          <div class="thc-method-icon">🔑</div>
          <div class="thc-method-name">API key</div>
          <div class="thc-method-desc">Paste a key from the provider&rsquo;s console. Stored encrypted on this install.</div>
        </button>
        <button type="button" class="thc-method" data-method="oauth">
          <div class="thc-method-icon">↪</div>
          <div class="thc-method-name">Sign in</div>
          <div class="thc-method-desc" id="thc-method-oauth-desc">Redirect to the provider, sign in there, come back authorized.</div>
          <span class="thc-method-badge" id="thc-method-oauth-badge" hidden>Setup needed</span>
        </button>
      </div>
    </div>

    <div id="thc-modal-setup" class="thc-modal-step">
      <button type="button" class="thc-modal-back" id="thc-modal-back" hidden>← Back</button>
      <div id="thc-modal-signin-steps" hidden></div>
      <div id="thc-modal-fields"></div>
      <div class="thc-modal-err" id="thc-modal-err"></div>
      <div class="thc-modal-actions">
        <button type="button" class="th-btn" id="thc-modal-cancel">Cancel</button>
        <button type="button" class="th-btn" id="thc-modal-test" hidden>Test</button>
        <button type="button" class="th-btn th-btn-primary" id="thc-modal-save">Connect</button>
      </div>
    </div>

    <!-- Add / Edit a user-defined custom provider. Same step covers both
         flows: Add starts blank, Edit pre-fills from providerData and uses
         a hidden editing_slug to support rename. The credential block at
         the bottom is optional — define-only is allowed, the card just
         shows as Not connected until the user comes back through Connect. -->
    <div id="thc-modal-addcustom" class="thc-modal-step" hidden>
      <input type="hidden" id="thc-ac-category" value="" />
      <input type="hidden" id="thc-ac-editing-slug" value="" />

      <div class="thc-ac-section-label">Provider</div>
      <div class="thc-field"><label>Display name</label><input type="text" id="thc-ac-name" placeholder="e.g. Acme AI" autocomplete="off" /></div>
      <div class="thc-field-row">
        <div class="thc-field"><label>Slug <span style="font-weight:400;color:var(--tx3)">(lowercase, hyphens)</span></label><input type="text" id="thc-ac-slug" placeholder="acme-ai" autocomplete="off" /></div>
        <div class="thc-field thc-field-color"><label>Brand color</label><input type="color" id="thc-ac-color" value="#6366f1" /></div>
      </div>
      <div class="thc-field"><label>Short description <span style="font-weight:400;color:var(--tx3)">(optional)</span></label><input type="text" id="thc-ac-meta" placeholder="What this provider does" autocomplete="off" /></div>

      <hr class="thc-ac-divider" />
      <div class="thc-ac-section-label">Authentication</div>
      <div class="thc-field"><label>Auth type</label>
        <select id="thc-ac-auth" style="width:100%;padding:9px 12px;background:var(--sf2);border:1px solid var(--bd);border-radius:8px;font-size:13px;color:var(--tx);outline:none;box-sizing:border-box;font-family:var(--f)">
          <option value="api_key">API key (single field)</option>
          <option value="sid_token">SID + Token (two fields)</option>
          <option value="multi">Multiple fields (up to 4 — key, secret, auth secret, …)</option>
          <option value="base_url">Base URL only (self-hosted)</option>
        </select>
      </div>
      <div class="thc-field"><label>Credentials URL <span style="font-weight:400;color:var(--tx3)">(optional; powers the "Get credentials ↗" link in Connect)</span></label><input type="url" id="thc-ac-url" placeholder="https://…" autocomplete="off" /></div>

      <!-- `multi` definition surface — visible only when Auth type === "multi". Defines
           1–4 label+type pairs that become the inputs on the Connect modal for this
           provider. Row 1 is required; rows 2–4 are skipped server-side when blank. -->
      <div id="thc-ac-multi-wrap" hidden>
        <hr class="thc-ac-divider" />
        <div class="thc-ac-section-label">Credential fields <span style="font-weight:400;color:var(--tx3);text-transform:none;letter-spacing:0">— up to 4 · row 1 required · blank label = skip</span></div>
        <div id="thc-ac-cred-rows">
          <?php
          $thc_cred_defaults = [
            [ 'label' => 'API Key',       'type' => 'password' ],
            [ 'label' => '',              'type' => 'password' ],
            [ 'label' => '',              'type' => 'password' ],
            [ 'label' => '',              'type' => 'text'     ],
          ];
          $thc_cred_placeholders = [ 'API Key', 'Client Secret', 'Auth Secret', 'Workspace ID' ];
          foreach ( $thc_cred_defaults as $thc_i => $thc_cd ):
            $thc_idx = $thc_i + 1;
          ?>
          <div class="thc-ac-cred-row" data-cred-row="<?php echo (int) $thc_idx; ?>" style="display:grid;grid-template-columns:1fr 130px;gap:8px;margin-bottom:6px">
            <input type="text" class="thc-ac-cred-label" id="thc-ac-cred-label-<?php echo (int) $thc_idx; ?>"
              placeholder="<?php echo esc_attr( $thc_cred_placeholders[ $thc_i ] ); ?>"
              value="<?php echo esc_attr( $thc_cd['label'] ); ?>"
              autocomplete="off"
              style="height:36px;padding:0 11px;background:var(--sf2);border:1px solid var(--bd);border-radius:8px;font-size:13px;font-family:var(--f);color:var(--tx)" />
            <select class="thc-ac-cred-type" id="thc-ac-cred-type-<?php echo (int) $thc_idx; ?>"
              style="height:36px;padding:0 11px;background:var(--sf2);border:1px solid var(--bd);border-radius:8px;font-size:13px;font-family:var(--f);color:var(--tx)">
              <option value="password" <?php selected( $thc_cd['type'], 'password' ); ?>>Secret</option>
              <option value="text"     <?php selected( $thc_cd['type'], 'text' );     ?>>Plain</option>
            </select>
          </div>
          <?php endforeach; ?>
        </div>
      </div>

      <hr class="thc-ac-divider" />
      <div class="thc-ac-section-label">Credential <span style="font-weight:400;color:var(--tx3);text-transform:none;letter-spacing:0">— optional, you can save the key now or later</span></div>
      <div class="thc-field"><label id="thc-ac-key-label">API key</label><input type="password" id="thc-ac-key" autocomplete="new-password" /></div>
      <div class="thc-field" id="thc-ac-key2-wrap" hidden><label id="thc-ac-key2-label">Token</label><input type="password" id="thc-ac-key2" autocomplete="new-password" /></div>
      <div class="thc-field"><label>Label <span style="font-weight:400;color:var(--tx3)">(optional, e.g. Production)</span></label><input type="text" id="thc-ac-label" autocomplete="off" /></div>

      <div class="thc-modal-err" id="thc-ac-err"></div>
      <div class="thc-modal-actions">
        <button type="button" class="th-btn" id="thc-ac-cancel">Cancel</button>
        <button type="button" class="th-btn th-btn-primary" id="thc-ac-save">Add provider</button>
      </div>
    </div>
  </div>
</div>

<div id="thc-toast" hidden style="position:fixed;bottom:28px;right:28px;background:var(--sf);border:1px solid var(--ok);border-radius:10px;padding:12px 18px;font-size:13px;font-weight:500;color:var(--ok);box-shadow:0 8px 24px rgba(0,0,0,.15);z-index:9999"></div>

<script>
(function(){
var ajaxUrl = window.ajaxurl || '/wp-admin/admin-ajax.php';
var nonce   = <?php echo wp_json_encode( $nonce ); ?>;

// Provider data for modal
var providerData = <?php echo wp_json_encode(
	array_reduce( $providers, function( $carry, $p ) {
		$carry[ $p['id'] ] = $p;
		return $carry;
	}, [] )
); ?>;

// Providers with a real test endpoint implementation. The Test button
// is hidden for any provider not in this set — see Therum_Connections_Page::TESTABLE.
var testableIds = <?php echo wp_json_encode( Therum_Connections_Page::TESTABLE ); ?>;

// Toast
var toast = document.getElementById('thc-toast');
function showToast(msg, ok) {
  toast.textContent = msg;
  toast.style.borderColor = ok ? 'var(--ok)' : 'var(--err)';
  toast.style.color        = ok ? 'var(--ok)' : 'var(--err)';
  toast.hidden = false;
  clearTimeout(toast._t);
  toast._t = setTimeout(function(){ toast.hidden = true; }, 3500);
}

// Search
var searchInput = document.getElementById('thc-search-input');
if (searchInput) {
  searchInput.addEventListener('input', function(){
    var q = this.value.toLowerCase().trim();
    document.querySelectorAll('.thc-card').forEach(function(card){
      card.style.display = (!q || (card.dataset.search||'').indexOf(q) !== -1) ? '' : 'none';
    });
  });
}

// Modal
var modal     = document.getElementById('thc-modal');
var modalLogo = document.getElementById('thc-modal-logo');
var modalTitle= document.getElementById('thc-modal-title');
var modalSub  = document.getElementById('thc-modal-sub');
var modalFields=document.getElementById('thc-modal-fields');
var modalErr  = document.getElementById('thc-modal-err');
var saveBtn   = document.getElementById('thc-modal-save');
var testBtn   = document.getElementById('thc-modal-test');
var pickerEl  = document.getElementById('thc-modal-picker');
var setupEl   = document.getElementById('thc-modal-setup');
var backBtn   = document.getElementById('thc-modal-back');
var signinEl  = document.getElementById('thc-modal-signin-steps');
var currentProvider = null;
var currentMethod   = null;

// Which providers are already connected (from the live page state). Used
// to flip the modal into "manage" mode where the Test button appears.
var connectedIds = <?php echo wp_json_encode( array_keys( $connections ) ); ?>;

// Which providers already have their OAuth app credentials configured
// on THIS install. Sign in skips the app-setup step for these.
var oauthAppReady = <?php
	$apps = (array) get_option( 'therum_oauth_apps', [] );
	$ready = [];
	foreach ( $apps as $pid => $row ) {
		if ( ! empty( $row['client_id'] ) ) $ready[] = $pid;
	}
	echo wp_json_encode( $ready );
?>;

// Build a one-shot URL that takes the user through real OAuth.
// admin-post action redirects to provider's authorize URL → user authorizes
// → provider redirects to /wp-json/therum/v1/oauth/callback?provider=X&code=...
// → our callback swaps code for token → stashes in vault → redirects back here.
var oauthStartNonce = <?php echo wp_json_encode( wp_create_nonce( 'therum_oauth_start' ) ); ?>;
var oauthRedirectUri = <?php echo wp_json_encode( rest_url( 'therum/v1/oauth/callback' ) ); ?>;
function thOAuthStartUrl(providerId) {
  return <?php echo wp_json_encode( admin_url( 'admin-post.php' ) ); ?> +
    '?action=therum_oauth_start&provider=' + encodeURIComponent(providerId) +
    '&_wpnonce=' + encodeURIComponent(oauthStartNonce);
}

function openModal(id) {
  var p = providerData[id];
  if (!p) return;
  currentProvider = p;
  currentMethod   = null;
  modalLogo.style.background = p.color;
  modalLogo.textContent = p.letter;
  modalTitle.textContent = p.name;
  var isConnected = connectedIds.indexOf(id) !== -1;
  modalSub.textContent = isConnected ? 'Already connected. Test the live credential or replace it.' : '';
  modalErr.style.display = 'none';
  modalErr.style.color = 'var(--err)';

  // The picker only shows when a provider actually supports both API key
  // and OAuth. Sign-in for an API-only provider is misleading and we're
  // not doing that. Today's OAuth providers: Dropbox, Slack, Google Drive.
  var methods = (p.auth_methods && p.auth_methods.length) ? p.auth_methods : ['api_key'];
  if (isConnected || methods.length < 2) {
    renderSetup('api_key', isConnected);
  } else {
    // Reflect OAuth-app-ready state on the Sign in tile so users know
    // whether it's one click or needs one-time setup first.
    var badge = document.getElementById('thc-method-oauth-badge');
    var desc  = document.getElementById('thc-method-oauth-desc');
    var ready = oauthAppReady.indexOf(p.id) !== -1;
    if (badge) {
      badge.hidden = false;
      badge.textContent = ready ? 'Ready' : 'Setup needed';
      badge.classList.toggle('is-ready', ready);
    }
    if (desc) {
      desc.textContent = ready
        ? 'One click to ' + p.name + '. Authorize there, come back signed in.'
        : 'First time: enter your ' + p.name + ' OAuth app credentials. After that, one click.';
    }
    pickerEl.hidden = false;
    setupEl.hidden  = true;
  }
  modal.style.display = 'flex';
}

function renderSetup(method, isConnected) {
  currentMethod = method;
  pickerEl.hidden = true;
  setupEl.hidden  = false;
  // Back button only when we actually came from the picker (multi-method
  // provider). Single-method providers shouldn't see a back link to a
  // screen they never visited.
  var p = currentProvider;
  var methods = (p.auth_methods && p.auth_methods.length) ? p.auth_methods : ['api_key'];
  backBtn.hidden = methods.length < 2 || isConnected;
  modalErr.style.display = 'none';
  modalErr.style.color = 'var(--err)';

  // OAuth path. Two phases:
  //   A) App not configured yet on this install → show a small form to
  //      enter client_id + client_secret + (read-only) redirect URI to
  //      copy into the provider's app settings. Save → moves to phase B.
  //   B) App configured → button "Sign in with {Provider}" that kicks
  //      off the real redirect handoff via admin-post.php.
  if (method === 'oauth') {
    var hasApp = oauthAppReady.indexOf(p.id) !== -1;
    testBtn.hidden = true;

    if (!hasApp) {
      // Phase A — first-time app setup for this provider on this install.
      signinEl.hidden = false;
      signinEl.className = 'thc-signin-steps';
      signinEl.innerHTML =
        'One-time setup. Register Therum as an OAuth app in the <a href="' + escapeAttr(p.url || '#') + '" target="_blank" rel="noopener">' + escapeHtml(p.name) + ' developer dashboard</a>, copy the credentials here, and Sign in becomes a real handoff.';
      modalFields.innerHTML =
        '<div class="thc-field"><label>Redirect URI <span style="font-weight:400;color:var(--tx3)">(paste this into the provider&rsquo;s app settings)</span></label>' +
          '<input type="text" readonly value="' + escapeAttr(oauthRedirectUri) + '" style="font-family:monospace;font-size:12px" onclick="this.select()" /></div>' +
        '<div class="thc-field"><label>Client ID</label><input type="text" id="thc-oauth-cid" placeholder="from the provider" autocomplete="off" /></div>' +
        '<div class="thc-field"><label>Client Secret</label><input type="password" id="thc-oauth-csec" placeholder="from the provider" autocomplete="new-password" /></div>';
      saveBtn.style.display = '';
      saveBtn.textContent = 'Save app · then sign in';
    } else {
      // Phase B — app ready, show the real handoff.
      signinEl.hidden = false;
      signinEl.className = 'thc-signin-steps';
      signinEl.innerHTML = 'Click below to sign in at ' + escapeHtml(p.name) + '. You&rsquo;ll be redirected there to authorize Therum, then back here.';
      modalFields.innerHTML =
        '<a class="thc-signin-cta" href="' + escapeAttr(thOAuthStartUrl(p.id)) + '" style="display:block;text-align:center;padding:12px 18px;font-size:14px">' +
          'Sign in with ' + escapeHtml(p.name) + ' ↗' +
        '</a>' +
        '<div class="thc-field" style="margin-top:14px"><div class="thc-field-hint" style="text-align:center"><button type="button" id="thc-oauth-reset" class="button-link" style="background:none;border:none;color:inherit;cursor:pointer;padding:0;font:inherit;text-decoration:underline">Reconfigure OAuth app</button></div></div>';
      saveBtn.style.display = 'none';
      setTimeout(function(){
        var r = document.getElementById('thc-oauth-reset');
        if (r) r.addEventListener('click', function(e){ e.preventDefault(); oauthAppReady = oauthAppReady.filter(function(x){return x !== p.id;}); renderSetup('oauth', false); });
      }, 0);
    }
    return;
  }
  signinEl.hidden = true;
  signinEl.innerHTML = '';
  saveBtn.style.display = '';

  // The actual input field — same for both paths.
  var fields = '';
  if (p.auth === 'multi') {
    // `multi` shape — up to 4 labeled credential inputs. The provider row
    // carries auth_label / auth_label2 / auth_label3 / auth_label4 plus
    // parallel auth_type entries (password|text). Inputs use ids
    // thc-f-key / thc-f-key2 / thc-f-key3 / thc-f-key4 so the save handler
    // POSTs them as key/key2/key3/key4 — matches save_connection's shape.
    var multiSlots = [
      { lbl: p.auth_label,  typ: p.auth_type,  id: 'thc-f-key'  },
      { lbl: p.auth_label2, typ: p.auth_type2, id: 'thc-f-key2' },
      { lbl: p.auth_label3, typ: p.auth_type3, id: 'thc-f-key3' },
      { lbl: p.auth_label4, typ: p.auth_type4, id: 'thc-f-key4' }
    ];
    multiSlots.forEach(function(s, i){
      if (!s.lbl) return;
      var t = s.typ === 'text' ? 'text' : 'password';
      var ac = t === 'password' ? 'new-password' : 'off';
      fields += '<div class="thc-field"><label>' + escapeHtml(s.lbl) + (i===0?' <span style="color:var(--err)">*</span>':'') + '</label><input type="' + t + '" id="' + s.id + '" autocomplete="' + ac + '" /></div>';
    });
  } else if (p.auth === 'sid_token') {
    fields += '<div class="thc-field"><label>' + escapeHtml(p.auth_label||'ID') + '</label><input type="text" id="thc-f-key" placeholder="' + escapeAttr(p.auth_hint||'') + '" autocomplete="off" /></div>';
    fields += '<div class="thc-field"><label>' + escapeHtml(p.auth_label2||'Token') + '</label><input type="password" id="thc-f-key2" placeholder="' + escapeAttr(p.auth_hint2||'') + '" autocomplete="new-password" /></div>';
  } else {
    fields += '<div class="thc-field"><label>' + escapeHtml(p.auth_label||'API Key') + '</label><input type="' + (p.auth==='base_url'?'url':'password') + '" id="thc-f-key" placeholder="' + escapeAttr(p.auth_hint||'') + '" autocomplete="new-password" /></div>';
  }
  fields += '<div class="thc-field"><label>Label <span style="font-weight:400;color:var(--tx3)">(optional)</span></label><input type="text" id="thc-f-label" placeholder="e.g. Production" /></div>';
  if (p.url && method !== 'signin') {
    fields += '<div class="thc-field"><div class="thc-field-hint"><a href="' + escapeAttr(p.url) + '" target="_blank" rel="noopener">Get credentials ↗</a></div></div>';
  }
  modalFields.innerHTML = fields;
  saveBtn.textContent = isConnected ? 'Update' : 'Connect';
  saveBtn.removeAttribute('data-loading');
  // Test only shows when (a) connection exists AND (b) we actually have a
  // real test endpoint for this provider. Avoids exposing a button that
  // would just return "not implemented" on click.
  testBtn.hidden = !isConnected || testableIds.indexOf(p.id) === -1;
  testBtn.removeAttribute('data-loading');
  setTimeout(function(){ var f = document.getElementById('thc-f-key'); if(f) f.focus(); }, 50);
}

function escapeHtml(s) { return String(s).replace(/[&<>"']/g, function(c){ return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c]; }); }
function escapeAttr(s) { return escapeHtml(s); }

// Picker tile click → expand the chosen path
pickerEl.querySelectorAll('[data-method]').forEach(function(btn){
  btn.addEventListener('click', function(){
    renderSetup(btn.getAttribute('data-method'), false);
  });
});

backBtn.addEventListener('click', function(){
  setupEl.hidden = true;
  pickerEl.hidden = false;
  modalErr.style.display = 'none';
});

function closeModal() {
  modal.style.display = 'none';
  currentProvider = null;
  currentMethod = null;
  modalFields.innerHTML = '';
  signinEl.innerHTML = '';
  signinEl.hidden = true;
  pickerEl.hidden = false;
  setupEl.hidden  = true;
  // The Add Custom step lives in the same modal — reset it too so the
  // next opener (whichever flow) starts clean.
  var ac = document.getElementById('thc-modal-addcustom');
  if (ac) ac.hidden = true;
}

document.getElementById('thc-modal-close').addEventListener('click', closeModal);
document.getElementById('thc-modal-cancel').addEventListener('click', closeModal);
modal.addEventListener('click', function(e){ if (e.target === modal) closeModal(); });
document.addEventListener('keydown', function(e){ if (e.key === 'Escape' && modal.style.display !== 'none') closeModal(); });

// Save connection
saveBtn.addEventListener('click', function(){
  if (!currentProvider) return;

  // OAuth app-setup branch: we're in Phase A (cid/csec form). Save app
  // creds, then redirect immediately into the real OAuth handoff so the
  // user lands at the provider's sign-in screen in one click.
  if (currentMethod === 'oauth') {
    var cid  = (document.getElementById('thc-oauth-cid')  || {}).value || '';
    var csec = (document.getElementById('thc-oauth-csec') || {}).value || '';
    cid = cid.trim(); csec = csec.trim();
    if (!cid || !csec) { modalErr.textContent = 'Enter both Client ID and Client Secret.'; modalErr.style.display = 'block'; return; }
    saveBtn.setAttribute('data-loading','');
    var fdA = new FormData();
    fdA.append('action','therum_oauth_app_save');
    fdA.append('nonce', nonce);
    fdA.append('provider', currentProvider.id);
    fdA.append('client_id', cid);
    fdA.append('client_secret', csec);
    fetch(ajaxUrl, {method:'POST',credentials:'same-origin',body:fdA})
      .then(function(r){ return r.json(); })
      .then(function(res){
        saveBtn.removeAttribute('data-loading');
        if (res && res.success) {
          // Straight to the provider — no second click.
          window.location.href = thOAuthStartUrl(currentProvider.id);
        } else {
          modalErr.textContent = (res && res.data) ? String(res.data) : 'Could not save OAuth app.';
          modalErr.style.display = 'block';
        }
      })
      .catch(function(){
        saveBtn.removeAttribute('data-loading');
        modalErr.textContent = 'Network error — try again.';
        modalErr.style.display = 'block';
      });
    return;
  }

  var keyEl   = document.getElementById('thc-f-key');
  var key2El  = document.getElementById('thc-f-key2');
  var key3El  = document.getElementById('thc-f-key3');
  var key4El  = document.getElementById('thc-f-key4');
  var labelEl = document.getElementById('thc-f-label');
  var key     = keyEl  ? keyEl.value.trim()  : '';
  var key2    = key2El ? key2El.value.trim() : '';
  var key3    = key3El ? key3El.value.trim() : '';
  var key4    = key4El ? key4El.value.trim() : '';
  var label   = labelEl ? labelEl.value.trim() : '';

  if (!key) { modalErr.textContent = 'Please enter the required credential.'; modalErr.style.display='block'; return; }

  saveBtn.setAttribute('data-loading','');
  modalErr.style.display = 'none';

  var fd = new FormData();
  fd.append('action','therum_connection_connect');
  fd.append('nonce', nonce);
  fd.append('provider_id', currentProvider.id);
  fd.append('key',  key);
  fd.append('key2', key2);
  fd.append('key3', key3);
  fd.append('key4', key4);
  fd.append('label', label);

  fetch(ajaxUrl, {method:'POST',credentials:'same-origin',body:fd})
    .then(function(r){ return r.json(); })
    .then(function(res){
      saveBtn.removeAttribute('data-loading');
      if (res && res.success) {
        closeModal();
        showToast(currentProvider ? currentProvider.name + ' connected' : 'Connected', true);
        setTimeout(function(){ location.reload(); }, 800);
      } else {
        modalErr.textContent = (res && res.data) ? String(res.data) : 'Save failed';
        modalErr.style.display = 'block';
      }
    })
    .catch(function(){
      saveBtn.removeAttribute('data-loading');
      modalErr.textContent = 'Network error — try again.';
      modalErr.style.display = 'block';
    });
});

// Live test of the stored credential — hits the provider's real endpoint
// for Anthropic / OpenAI, returns the response text on success or a server
// error message on failure. Costs ~$0.0001 per click.
testBtn.addEventListener('click', function(){
  if (!currentProvider) return;
  testBtn.setAttribute('data-loading','');
  testBtn.textContent = 'Testing…';
  modalErr.style.display = 'none';

  var fd = new FormData();
  fd.append('action','therum_connection_test');
  fd.append('nonce', nonce);
  fd.append('provider_id', currentProvider.id);

  fetch(ajaxUrl, {method:'POST',credentials:'same-origin',body:fd})
    .then(function(r){ return r.json(); })
    .then(function(res){
      testBtn.removeAttribute('data-loading');
      testBtn.textContent = 'Test';
      var msg = (res && res.data && res.data.message) ? res.data.message : (res && res.success ? 'OK' : 'Test failed');
      if (res && res.success) {
        modalErr.style.color = 'var(--ok)';
        modalErr.textContent = '✓ ' + msg;
      } else {
        modalErr.style.color = 'var(--err)';
        modalErr.textContent = '✗ ' + msg;
      }
      modalErr.style.display = 'block';
    })
    .catch(function(){
      testBtn.removeAttribute('data-loading');
      testBtn.textContent = 'Test';
      modalErr.style.color = 'var(--err)';
      modalErr.textContent = '✗ Network error — try again.';
      modalErr.style.display = 'block';
    });
});

// Disconnect
function disconnect(id) {
  if (!confirm('Disconnect this provider? The stored key will be deleted.')) return;
  var fd = new FormData();
  fd.append('action','therum_connection_disconnect');
  fd.append('nonce', nonce);
  fd.append('provider_id', id);
  fetch(ajaxUrl, {method:'POST',credentials:'same-origin',body:fd})
    .then(function(r){ return r.json(); })
    .then(function(res){
      if (res && res.success) {
        showToast('Disconnected', true);
        setTimeout(function(){ location.reload(); }, 600);
      } else {
        showToast('Disconnect failed', false);
      }
    })
    .catch(function(){ showToast('Network error', false); });
}

// ─── Add / Edit a custom provider ───────────────────────────────────────────
// Same modal step (#thc-modal-addcustom) covers both. Edit pre-fills from
// providerData and stashes the original slug in editing-slug so the server
// can do a rename (move credential + drop old row) when the slug changes.

var acStep      = document.getElementById('thc-modal-addcustom');
var acName      = document.getElementById('thc-ac-name');
var acSlug      = document.getElementById('thc-ac-slug');
var acColor     = document.getElementById('thc-ac-color');
var acMeta      = document.getElementById('thc-ac-meta');
var acAuth      = document.getElementById('thc-ac-auth');
var acUrl       = document.getElementById('thc-ac-url');
var acKey       = document.getElementById('thc-ac-key');
var acKeyLabel  = document.getElementById('thc-ac-key-label');
var acKey2      = document.getElementById('thc-ac-key2');
var acKey2Wrap  = document.getElementById('thc-ac-key2-wrap');
var acKey2Label = document.getElementById('thc-ac-key2-label');
var acLabelF    = document.getElementById('thc-ac-label');
var acCat       = document.getElementById('thc-ac-category');
var acEditing   = document.getElementById('thc-ac-editing-slug');
var acErr       = document.getElementById('thc-ac-err');
var acSave      = document.getElementById('thc-ac-save');
var acCancel    = document.getElementById('thc-ac-cancel');

// Auto-slug while the user types Name, unless they've manually edited Slug.
var acSlugDirty = false;
acSlug.addEventListener('input', function(){ acSlugDirty = true; });
function slugify(s) {
  return String(s||'').toLowerCase()
    .replace(/[^a-z0-9]+/g,'-')
    .replace(/^-+|-+$/g,'')
    .substring(0, 48);
}
acName.addEventListener('input', function(){
  if (!acSlugDirty) acSlug.value = slugify(acName.value);
});

// Auth-type swap: relabel the credential fields, show/hide key2 row, toggle
// the `multi` definition surface. In `multi` mode the inline credential
// capture is hidden — the user defines fields here, then connects later from
// the provider card (the Connect modal renders the 1–4 inputs).
var acMultiWrap = document.getElementById('thc-ac-multi-wrap');
var acInlineKey  = acKey ? acKey.closest('.thc-field') : null;
var acInlineKey2 = acKey2Wrap;
var acInlineLabel = acLabelF ? acLabelF.closest('.thc-field') : null;
acAuth.addEventListener('change', updateAuthFields);
function updateAuthFields() {
  var a = acAuth.value;
  if (a === 'multi') {
    if (acMultiWrap)   acMultiWrap.hidden = false;
    if (acInlineKey)   acInlineKey.style.display   = 'none';
    if (acInlineKey2)  acInlineKey2.hidden = true;
    if (acInlineLabel) acInlineLabel.style.display = 'none';
    return;
  }
  if (acMultiWrap)   acMultiWrap.hidden = true;
  if (acInlineKey)   acInlineKey.style.display   = '';
  if (acInlineLabel) acInlineLabel.style.display = '';
  if (a === 'sid_token') {
    acKeyLabel.textContent  = 'Account SID / ID';
    acKey2Label.textContent = 'Auth Token / Secret';
    acKey2Wrap.hidden = false;
    acKey.type = 'text';
  } else if (a === 'base_url') {
    acKeyLabel.textContent  = 'Base URL';
    acKey2Wrap.hidden = true;
    acKey.type = 'url';
  } else {
    acKeyLabel.textContent  = 'API key';
    acKey2Wrap.hidden = true;
    acKey.type = 'password';
  }
}

// Helpers for the multi cred-row grid — keep DOM lookups out of the hot paths.
function acGetCredRows() {
  var out = [];
  for (var i = 1; i <= 4; i++) {
    var lbl = document.getElementById('thc-ac-cred-label-' + i);
    var typ = document.getElementById('thc-ac-cred-type-'  + i);
    if (lbl && typ) out.push({ idx: i, lbl: lbl, typ: typ });
  }
  return out;
}
function acResetCredRows(labels, types) {
  acGetCredRows().forEach(function(r, i){
    r.lbl.value = (labels && labels[i] != null) ? labels[i] : '';
    r.typ.value = (types  && types[i]  === 'text') ? 'text' : 'password';
  });
}

function openAddCustomModal(category, editSlug) {
  // Hide the other steps; show ours.
  pickerEl.hidden  = true;
  setupEl.hidden   = true;
  acStep.hidden    = false;
  acErr.style.display = 'none';
  acErr.style.color = 'var(--err)';

  var editing = editSlug ? providerData[editSlug] : null;
  acCat.value     = (editing && editing.category) ? editing.category : (category || '');
  acEditing.value = editing ? editSlug : '';

  if (editing) {
    modalLogo.style.background = editing.color || '#6366f1';
    modalLogo.textContent      = editing.letter || (editing.name||'?').charAt(0).toUpperCase();
    modalTitle.textContent     = 'Edit ' + editing.name;
    modalSub.textContent       = 'Update this custom provider. Renaming the slug moves the saved credential along with it.';
    acName.value      = editing.name  || '';
    acSlug.value      = editSlug;
    acSlugDirty       = true;
    acColor.value     = (editing.color && /^#[0-9a-fA-F]{6}$/.test(editing.color)) ? editing.color : '#6366f1';
    acMeta.value      = editing.meta  || '';
    acAuth.value      = (editing.auth && ['api_key','sid_token','base_url','multi'].indexOf(editing.auth) !== -1) ? editing.auth : 'api_key';
    acUrl.value       = editing.url   || '';
    acKey.value       = '';
    acKey2.value      = '';
    acLabelF.value    = '';
    // Pre-fill the multi cred rows from the provider def (auth_label..auth_label4).
    acResetCredRows(
      [ editing.auth_label || '', editing.auth_label2 || '', editing.auth_label3 || '', editing.auth_label4 || '' ],
      [ editing.auth_type  || '', editing.auth_type2  || '', editing.auth_type3  || '', editing.auth_type4  || '' ]
    );
    acSave.textContent = 'Save changes';
  } else {
    modalLogo.style.background = '#6366f1';
    modalLogo.textContent      = '＋';
    modalTitle.textContent     = 'Add custom provider';
    modalSub.textContent       = 'Wire up a provider that isn’t in the built-in registry — internal endpoints, niche tools, anything with an API key.';
    acName.value      = '';
    acSlug.value      = '';
    acSlugDirty       = false;
    acColor.value     = '#6366f1';
    acMeta.value      = '';
    acAuth.value      = 'api_key';
    acUrl.value       = '';
    acKey.value       = '';
    acKey2.value      = '';
    acLabelF.value    = '';
    acResetCredRows([ 'API Key', '', '', '' ], [ 'password', 'password', 'password', 'text' ]);
    acSave.textContent = 'Add provider';
  }
  updateAuthFields();
  modal.style.display = 'flex';
  setTimeout(function(){ acName.focus(); }, 50);
}

acCancel.addEventListener('click', closeModal);

acSave.addEventListener('click', function(){
  var name = acName.value.trim();
  if (!name) { acErr.textContent = 'Name is required.'; acErr.style.display='block'; acName.focus(); return; }

  acSave.setAttribute('data-loading','');
  acErr.style.display = 'none';

  var fd = new FormData();
  fd.append('action','therum_connection_add_custom');
  fd.append('nonce', nonce);
  fd.append('category', acCat.value);
  fd.append('editing_slug', acEditing.value);
  fd.append('name', name);
  fd.append('slug', acSlug.value.trim());
  fd.append('color', acColor.value);
  fd.append('meta', acMeta.value.trim());
  fd.append('auth', acAuth.value);
  fd.append('url',  acUrl.value.trim());
  fd.append('key',  acKey.value.trim());
  fd.append('key2', acKey2.value.trim());
  fd.append('label',acLabelF.value.trim());
  // `multi` mode — server expects cred_label_1..4 + cred_type_1..4.
  if (acAuth.value === 'multi') {
    acGetCredRows().forEach(function(r){
      fd.append('cred_label_' + r.idx, r.lbl.value.trim());
      fd.append('cred_type_'  + r.idx, r.typ.value);
    });
  }

  fetch(ajaxUrl, {method:'POST',credentials:'same-origin',body:fd})
    .then(function(r){ return r.json(); })
    .then(function(res){
      acSave.removeAttribute('data-loading');
      if (res && res.success) {
        closeModal();
        showToast(acEditing.value ? 'Provider updated' : 'Custom provider added', true);
        setTimeout(function(){ location.reload(); }, 700);
      } else {
        var msg = (res && res.data && res.data.message) ? res.data.message
                : (res && res.data) ? String(res.data)
                : 'Save failed';
        acErr.textContent = msg;
        acErr.style.display = 'block';
      }
    })
    .catch(function(){
      acSave.removeAttribute('data-loading');
      acErr.textContent = 'Network error — try again.';
      acErr.style.display = 'block';
    });
});

function deleteCustom(id) {
  var p = providerData[id];
  var label = p ? p.name : id;
  if (!confirm('Delete the custom provider "' + label + '"? This also removes any saved credential. Built-in providers are not affected.')) return;
  var fd = new FormData();
  fd.append('action','therum_connection_delete_custom');
  fd.append('nonce', nonce);
  fd.append('provider_id', id);
  fetch(ajaxUrl, {method:'POST',credentials:'same-origin',body:fd})
    .then(function(r){ return r.json(); })
    .then(function(res){
      if (res && res.success) {
        showToast('Custom provider deleted', true);
        setTimeout(function(){ location.reload(); }, 500);
      } else {
        var msg = (res && res.data && res.data.message) ? res.data.message
                : (res && res.data) ? String(res.data)
                : 'Delete failed';
        showToast(msg, false);
      }
    })
    .catch(function(){ showToast('Network error', false); });
}

// Click delegation
document.addEventListener('click', function(e){
  var btn = e.target.closest('[data-thc-connect]');
  if (btn) { openModal(btn.getAttribute('data-thc-connect')); return; }

  var mb = e.target.closest('[data-thc-manage]');
  if (mb) {
    var id = mb.getAttribute('data-thc-manage');
    var panel = document.getElementById('thc-manage-' + id);
    if (panel) panel.classList.toggle('open');
    return;
  }

  var db = e.target.closest('[data-thc-disconnect]');
  if (db) { disconnect(db.getAttribute('data-thc-disconnect')); return; }

  var rb = e.target.closest('[data-thc-rekey]');
  if (rb) { openModal(rb.getAttribute('data-thc-rekey')); return; }

  var ab = e.target.closest('[data-thc-add-custom]');
  if (ab) { openAddCustomModal(ab.getAttribute('data-thc-add-custom'), ''); return; }

  var eb = e.target.closest('[data-thc-edit-custom]');
  if (eb) { openAddCustomModal('', eb.getAttribute('data-thc-edit-custom')); return; }

  var xb = e.target.closest('[data-thc-delete-custom]');
  if (xb) { deleteCustom(xb.getAttribute('data-thc-delete-custom')); return; }
});

})();
</script>
		<?php
	}

	private static function render_manage_tab( string $tab, array $connections, string $nonce ): void {
		if ( $tab === 'api-vault' ) {
			echo '<div class="thc-breadcrumb">CONNECTIONS · MANAGE</div>';
			echo '<h1 class="thc-title">API Keys Vault</h1>';
			echo '<p class="thc-desc">All stored provider credentials. Click Disconnect to revoke a key.</p>';

			if ( empty( $connections ) ) {
				echo '<div style="padding:40px 0;text-align:center;color:var(--tx3);font-size:14px">No connections yet. Pick a provider category and click <strong>Connect</strong>, or use <strong>＋ Add custom</strong> to wire up your own.</div>';
				return;
			}

			echo '<div class="th-settings-group"><div class="th-settings-group-body">';
			foreach ( $connections as $id => $data ) {
				$connected_at = human_time_diff( (int)($data['connected_at'] ?? 0) ) . ' ago';
				$key_preview  = substr( $data['key'] ?? '', 0, 8 ) . str_repeat( '•', 12 );
				echo '<div style="display:flex;align-items:center;justify-content:space-between;padding:14px 0;border-bottom:1px solid var(--bd);gap:12px">';
				echo '<div>';
				echo '<div style="font-weight:600;font-size:13px;color:var(--tx)">' . esc_html( $id ) . ( !empty($data['label']) ? ' <span style="color:var(--tx3);font-weight:400">· ' . esc_html($data['label']) . '</span>' : '' ) . '</div>';
				echo '<div style="font-size:12px;color:var(--tx3);font-family:monospace;margin-top:2px">' . esc_html($key_preview) . '</div>';
				echo '<div style="font-size:11px;color:var(--tx3);margin-top:2px">Connected ' . esc_html($connected_at) . '</div>';
				echo '</div>';
				echo '<button type="button" class="th-btn" style="font-size:12px;color:var(--err)" data-thc-disconnect="' . esc_attr($id) . '">Disconnect</button>';
				echo '</div>';
			}
			echo '</div></div>';

			// Include the disconnect JS
			echo '<script>
(function(){
var ajaxUrl=window.ajaxurl||"/wp-admin/admin-ajax.php";
var nonce=' . wp_json_encode($nonce) . ';
var toast=document.createElement("div");
toast.style="position:fixed;bottom:28px;right:28px;background:var(--sf);border:1px solid var(--ok);border-radius:10px;padding:12px 18px;font-size:13px;font-weight:500;color:var(--ok);box-shadow:0 8px 24px rgba(0,0,0,.15);z-index:9999;display:none";
document.body.appendChild(toast);
function showToast(msg,ok){toast.textContent=msg;toast.style.borderColor=ok?"var(--ok)":"var(--err)";toast.style.color=ok?"var(--ok)":"var(--err)";toast.style.display="block";clearTimeout(toast._t);toast._t=setTimeout(function(){toast.style.display="none";},3500);}
document.addEventListener("click",function(e){
  var db=e.target.closest("[data-thc-disconnect]");
  if(!db)return;
  if(!confirm("Disconnect this provider? The stored key will be deleted."))return;
  var id=db.getAttribute("data-thc-disconnect");
  var fd=new FormData();fd.append("action","therum_connection_disconnect");fd.append("nonce",nonce);fd.append("provider_id",id);
  fetch(ajaxUrl,{method:"POST",credentials:"same-origin",body:fd}).then(function(r){return r.json();}).then(function(res){if(res&&res.success){showToast("Disconnected",true);setTimeout(function(){location.reload();},600);}else{showToast("Failed",false);}}).catch(function(){showToast("Network error",false);});
});
})();
</script>';

		} elseif ( $tab === 'webhooks-log' ) {
			echo '<div class="thc-breadcrumb">CONNECTIONS · MANAGE</div>';
			echo '<h1 class="thc-title">Webhooks Log</h1>';
			echo '<p class="thc-desc">Incoming and outgoing webhook events logged by Therum OS.</p>';
			$log = (array) get_option('therum_webhooks_log', []);
			if (empty($log)) {
				echo '<div style="padding:40px 0;text-align:center;color:var(--tx3);font-size:14px">No webhook events recorded yet.</div>';
			} else {
				echo '<div class="th-settings-group"><div class="th-settings-group-body">';
				foreach (array_reverse(array_slice($log, -50)) as $entry) {
					echo '<div style="padding:10px 0;border-bottom:1px solid var(--bd);font-size:12px;display:flex;gap:12px;align-items:center">';
					echo '<span style="color:var(--tx3);flex-shrink:0">' . esc_html(wp_date('M j g:ia', $entry['time'] ?? 0)) . '</span>';
					echo '<span style="color:var(--tx);font-weight:500">' . esc_html($entry['event'] ?? '') . '</span>';
					echo '<span style="color:var(--tx3)">' . esc_html($entry['source'] ?? '') . '</span>';
					echo '</div>';
				}
				echo '</div></div>';
			}

		} elseif ( $tab === 'audit-log' ) {
			echo '<div class="thc-breadcrumb">CONNECTIONS · MANAGE</div>';
			echo '<h1 class="thc-title">Audit Log</h1>';
			echo '<p class="thc-desc">Connection events — who connected, changed, or disconnected a provider.</p>';
			$log = (array) get_option('therum_conn_audit_log', []);
			if (empty($log)) {
				echo '<div style="padding:40px 0;text-align:center;color:var(--tx3);font-size:14px">No audit events recorded yet.</div>';
			} else {
				echo '<div class="th-settings-group"><div class="th-settings-group-body">';
				foreach (array_reverse(array_slice($log, -100)) as $entry) {
					echo '<div style="padding:10px 0;border-bottom:1px solid var(--bd);font-size:12px;display:flex;gap:12px;align-items:center">';
					echo '<span style="color:var(--tx3);flex-shrink:0">' . esc_html(wp_date('M j g:ia', $entry['time'] ?? 0)) . '</span>';
					echo '<span style="color:var(--tx);font-weight:500">' . esc_html($entry['action'] ?? '') . '</span>';
					echo '<span style="color:var(--tx3)">' . esc_html($entry['provider'] ?? '') . '</span>';
					echo '<span style="color:var(--tx3)">' . esc_html($entry['user'] ?? '') . '</span>';
					echo '</div>';
				}
				echo '</div></div>';
			}

		} elseif ( $tab === 'api-webhooks' ) {
			echo '<div class="thc-breadcrumb">CONNECTIONS · MANAGE</div>';
			echo '<h1 class="thc-title">API &amp; Webhooks</h1>';
			echo '<p class="thc-desc">Therum REST API credentials and incoming webhook endpoints.</p>';
			$api_key = get_option('therum_rest_api_key', '');
			if (!$api_key) {
				$api_key = wp_generate_password(40, false);
				update_option('therum_rest_api_key', $api_key);
			}
			$webhook_url = rest_url('therum/v1/hook');
			echo '<div class="th-settings-group"><div class="th-settings-group-body">';
			echo '<div style="margin-bottom:18px"><div style="font-size:12px;font-weight:600;color:var(--tx2);margin-bottom:6px">REST API Key</div>';
			echo '<div style="display:flex;gap:8px"><input type="text" value="' . esc_attr($api_key) . '" readonly style="flex:1;padding:9px 12px;background:var(--sf2);border:1px solid var(--bd);border-radius:8px;font-size:12px;font-family:monospace;color:var(--tx)"><button class="th-btn" onclick="navigator.clipboard.writeText(this.previousElementSibling.value);this.textContent=\'Copied!\';setTimeout(()=>{this.textContent=\'Copy\'},1500)" style="font-size:12px">Copy</button></div></div>';
			echo '<div><div style="font-size:12px;font-weight:600;color:var(--tx2);margin-bottom:6px">Incoming Webhook URL</div>';
			echo '<div style="display:flex;gap:8px"><input type="text" value="' . esc_url($webhook_url) . '" readonly style="flex:1;padding:9px 12px;background:var(--sf2);border:1px solid var(--bd);border-radius:8px;font-size:12px;font-family:monospace;color:var(--tx)"><button class="th-btn" onclick="navigator.clipboard.writeText(this.previousElementSibling.value);this.textContent=\'Copied!\';setTimeout(()=>{this.textContent=\'Copy\'},1500)" style="font-size:12px">Copy</button></div></div>';
			echo '</div></div>';
		}
	}
}
