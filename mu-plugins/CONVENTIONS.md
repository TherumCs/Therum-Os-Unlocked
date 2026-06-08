# Therum OS mu-plugins · Naming conventions

Single canonical prefix as of 2026-06-08. Every internal helper that used to
be `th_*` or `thm_*` has been renamed to `therum_*`; the matching AJAX action
`th_save_post` was renamed alongside its handler.

## Functions

All functions are `therum_*`. No exceptions.

```php
function therum_render_dashboard() { … }
function therum_settings_group( $name, $desc, $cb ) { … }
function therum_media_rename_attachment( int $attachment_id, string $new_basename ): array { … }
```

The Pure runtime under `Therum OS/pure/runtime/therum/` uses PSR-4 namespaces
(`Therum\…`); function-prefix rules don't apply there.

The desktop-mode plugin keeps its own `desktop_mode_*` prefix — separate
plugin, separate API.

## Classes

Always `Therum_PascalCase`. No exceptions.

```php
class Therum_Settings { … }
class Therum_Connections_Page { … }
```

## Constants

Always `THERUM_SCREAMING_SNAKE_CASE`.

```php
define( 'THERUM_OS_VERSION', '1.9.0' );
const PAGE_SLUG = 'therum-wizard'; // class const — local scope is fine
```

## Options + post meta

| Layer | Prefix | Example |
|---|---|---|
| Site options | `therum_*` | `therum_connectors`, `therum_wizard_complete` |
| User meta | `therum_*` | `therum_user_pinned_pages` |
| Post meta — public | `therum_*` | `therum_seo_title` |
| Post meta — internal | `_therum_*` | `_therum_revision_lock` |

## Hooks

Reverse-domain dotted form preferred for new hooks:

```php
apply_filters( 'therum/wizard/enabled', true );
do_action( 'therum/connections/refresh' );
```

The older `therum_*_event` flat form stays valid for back-compat.

## AJAX actions

`wp_ajax_therum_*` only. The `wp_ajax_thw_*` (wizard) action names are
grandfathered in — don't add new ones; if a wizard endpoint needs to be
renamed, do it alongside the JS that calls it.

## File names

| Layer | Pattern | Example |
|---|---|---|
| Top-level mu-plugin | `therum-<area>.php` | `therum-admin.php`, `therum-wizard.php` |
| Extracted page class | `_therum/admin/pages/therum-<name>.php` | `_therum/admin/pages/therum-settings.php` |
| Extracted design subsystem | `_therum/design/therum-<name>.php` | `_therum/design/therum-themes.php` |
| Asset | `assets/therum-<name>.<ext>` | `assets/therum-shell.css` |
