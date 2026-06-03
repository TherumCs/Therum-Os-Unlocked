# Therum OS — `_therum/` library

Namespaced PHP classes under the `Therum\` namespace, PSR-4 mapped to `src/`.

## Structure

```
_therum/
├── composer.json     # PSR-4 + future deps
├── src/              # Therum\* namespace classes
└── vendor/           # composer-managed deps (gitignored, optional)
```

## Autoload

`therum-core.php` registers a built-in SPL autoloader for `Therum\` that maps
directly to `src/`. If you run `composer install` inside `_therum/`, Composer's
autoloader is loaded in addition and takes precedence for any package it knows
about. The SPL fallback covers Therum's own classes without external tooling.

You **don't have to** run `composer install` to use Therum's own classes. Composer
becomes necessary only when we `require` a third-party library — at which point
running `composer install` in this directory will pull `vendor/` and the
classes-in-vendor become autoloadable.

## Why namespaced?

Phase 5 of the Therum roadmap absorbs the "Composer-first / typed APIs" parts of
the "WP-Next" thesis without forking core. The `Therum\` namespace gives a clean
target for typed kernel classes:

- `Therum\Auth\Token` — capability-scoped API tokens (Phase 5.1)
- `Therum\Queue` — persistent job queue with retry semantics (Phase 5.2)
- `Therum\Settings` — typed schema-driven settings registry (Phase 5.3 audit)
- `Therum\Events` — typed event bus (Phase 5.3, optional)

Existing global-function mu-plugins continue to work unchanged. New Therum
kernel work goes under `Therum\` here.
