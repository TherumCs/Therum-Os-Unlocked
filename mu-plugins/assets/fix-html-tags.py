#!/usr/bin/env python3
"""
Strip spurious <style> / <script> wrapper tags from extracted CSS/JS asset files,
re-minify the clean content, and regenerate .gz sidecars.
"""

import re, gzip, os, shutil

ASSETS_DIR = os.path.dirname(os.path.abspath(__file__))

# ── CSS minifier (same improved version as before) ────────────────────────────

def fix_calc_ops(css):
    """Restore required whitespace around + and - inside calc()."""
    def _fix(m):
        inner = m.group(1)
        inner = re.sub(r'(?<=[0-9a-zA-Z%)])\+(?=[0-9a-zA-Z%(.-])', ' + ', inner)
        inner = re.sub(r'(?<=[0-9a-zA-Z%)])-(?=[0-9a-zA-Z%(])', ' - ', inner)
        return 'calc(' + inner + ')'
    return re.sub(r'calc\(([^)]+)\)', _fix, css)

def minify_css(css):
    css = re.sub(r'/\*.*?\*/', '', css, flags=re.DOTALL)
    css = re.sub(r'\s+', ' ', css)
    css = re.sub(r'\s*([{};:,>~])\s*', r'\1', css)
    css = re.sub(r'\s*!\s*important', '!important', css)
    css = re.sub(r'0\.(\d)', r'.\1', css)
    css = re.sub(r'([\s:])0px', r'\g<1>0', css)
    css = fix_calc_ops(css)
    return css.strip()

# ── JS minifier (light — preserve strings/regex) ─────────────────────────────

def minify_js(js):
    # Remove single-line comments (not inside strings — good-enough heuristic)
    js = re.sub(r'//[^\n]*', '', js)
    # Remove multi-line comments
    js = re.sub(r'/\*.*?\*/', '', js, flags=re.DOTALL)
    # Collapse whitespace
    js = re.sub(r'\n+', '\n', js)
    js = re.sub(r'[ \t]+', ' ', js)
    js = re.sub(r' ?([\{};,=\+\-\*/<>!&\|\?:]) ?', r'\1', js)
    js = re.sub(r'\n ?', '\n', js)
    return js.strip()

# ── Strip HTML wrapper tags ───────────────────────────────────────────────────

def strip_wrapper(content, tag):
    """Remove opening <tag ...> and closing </tag> from content."""
    content = re.sub(r'^\s*<' + tag + r'[^>]*>', '', content, count=1, flags=re.IGNORECASE)
    content = re.sub(r'</' + tag + r'>\s*$', '', content, flags=re.IGNORECASE)
    return content.strip()

# ── Gzip sidecar ─────────────────────────────────────────────────────────────

def write_gz(path, data_bytes):
    with gzip.open(path + '.gz', 'wb', compresslevel=9) as fh:
        fh.write(data_bytes)

# ── Main ─────────────────────────────────────────────────────────────────────

css_files = [
    'therum-customizer.css',
    'therum-design-pages.css',
    'therum-editor-skin.css',
    'therum-form-skin.css',
    'therum-motion.css',
    'therum-native-design.css',
    'therum-settings-content.css',
    'therum-settings-tile.css',
    'therum-settings.css',
    'therum-shell.css',
]

js_files_with_tags = [
    'therum-editor-bar.js',
    'therum-motion.js',
    'therum-native-menus-detail.js',
    'therum-native-menus-overview.js',
    'therum-native-widgets.js',
    'therum-settings-tile.js',
    'therum-settings.js',
]

results = []

for fname in css_files:
    path = os.path.join(ASSETS_DIR, fname)
    if not os.path.exists(path):
        results.append(f'SKIP (not found): {fname}')
        continue
    raw = open(path, 'r', encoding='utf-8').read()
    if not re.match(r'^\s*<style', raw, re.IGNORECASE):
        results.append(f'SKIP (no tag): {fname}')
        continue
    clean = strip_wrapper(raw, 'style')
    minified = minify_css(clean)
    encoded = minified.encode('utf-8')
    open(path, 'w', encoding='utf-8').write(minified)
    write_gz(path, encoded)
    results.append(f'FIXED {fname}: {len(raw)} → {len(minified)} bytes')

for fname in js_files_with_tags:
    path = os.path.join(ASSETS_DIR, fname)
    if not os.path.exists(path):
        results.append(f'SKIP (not found): {fname}')
        continue
    raw = open(path, 'r', encoding='utf-8').read()
    if not re.match(r'^\s*<script', raw, re.IGNORECASE):
        results.append(f'SKIP (no tag): {fname}')
        continue
    clean = strip_wrapper(raw, 'script')
    minified = minify_js(clean)
    encoded = minified.encode('utf-8')
    open(path, 'w', encoding='utf-8').write(minified)
    write_gz(path, encoded)
    results.append(f'FIXED {fname}: {len(raw)} → {len(minified)} bytes')

print('\n'.join(results))
