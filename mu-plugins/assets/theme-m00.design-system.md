# Design System Reference — Theme 00 · "Default"

**Theme key:** `theme-00` · **palette/body class:** `theme-m00` · **stylesheet:** `assets/theme-m00.css`
**Direction:** Modern WordPress-admin redesign (make.wordpress.org 2023 explorations) + Behance WP admin redesign — clean, light, app-like, clear sidebar/content contrast.

---

## Color Palette

### Primary Colors
| Name | Hex | Usage |
|------|-----|-------|
| Accent (Blueberry) | `#3858E9` | Buttons, active nav, links (`--ac`) |
| Accent Hover | `#2E45C5` | Hover state (`--ac-h`) |
| Accent Soft | `rgba(56,88,233,.10)` | Active-item tint (`--ac-s`) |

### Neutrals
| Name | Hex | Usage |
|------|-----|-------|
| Canvas | `#F6F7F9` | Content background, cool light gray (`--bg`) |
| Surface | `#FFFFFF` | Sidebar, cards, top bar (`--sf`) |
| Surface 2 | `#F1F3F5` | Hover wells (`--sf2`) |
| Surface 3 | `#E9ECEF` | Inputs, tracks (`--sf3`) |
| Text | `#1E1E1E` | Primary text (`--tx`) |
| Text 2 | `#50575E` | Secondary (`--tx2`) |
| Text 3 | `#8C8F94` | Muted (`--tx3`) |
| Border | `#E3E5E8` | Hairline (`--bd`) |
| Border 2 | `#D2D5D9` | Stronger border (`--bd2`) |

### Semantic Colors
| Name | Hex | Usage |
|------|-----|-------|
| Success | `#1E8E3E` | Confirmations |
| Warning | `#E6A817` | Cautions |
| Error | `#D63638` | Destructive (WP red) |
| Info | `#3858E9` | Informational |

---

## Typography
- **Family:** system stack — `-apple-system, BlinkMacSystemFont, Inter, "Segoe UI", Roboto, sans-serif`
- **Mono:** monospace

### Type Scale
| Name | Size | Weight | Usage |
|------|------|--------|-------|
| H1 / Page title | 28px | 700 | `.th-lp-title`, `.th-cx-title` (letter-spacing -.01em) |
| Card title | 16px | 700 | `.th-studio-card-name`, `.th-card h3` |
| Stat | 28px | 700 | `.th-stat-val` |
| Body | 14px | 400/500 | Body |
| Label / Nav | 13–14px | 500/600 | Nav items |
| Small | 11px | 600 | Eyebrows, captions |

---

## Spacing Scale (4px base)
| Size | Value | Usage |
|------|-------|-------|
| xs | 4px | Icon gaps |
| sm | 8px | Tight padding |
| md | 16px | Default padding |
| lg | 24px | Card padding |
| xl | 40px | Page edge / section spacing (generous whitespace) |

---

## Component Library

### Sidebar (default left layout)
- **Surface:** white `#FFFFFF`, hairline right border → clear contrast vs gray canvas
- **Items:** radius 8, color `--tx2`, weight 500; hover bg `--sf2`
- **Active item:** `--ac-s` bg, `--ac` text, weight 600, inset 2px accent bar (left) — WP current-item highlight
- **Logo:** accent bg, white mark
- **Search:** `--sf2` bg, hairline border

### Top bar
- Translucent white `rgba(255,255,255,.85)` + blur, hairline bottom

### Cards (flat-modern)
- White, **1px `--bd` border**, **radius 10**, soft shadow `0 1px 2px rgba(16,24,40,.04),0 4px 12px rgba(16,24,40,.04)`
- `.th-card`, `.th-studio-card` (hero flush, no inset)

### Buttons
- **Primary:** accent `#3858E9` bg, white text, no border; hover `--ac-h`

---

## Shadows & Elevation
| Level | CSS | Usage |
|-------|-----|-------|
| 1 (card) | `0 1px 2px rgba(16,24,40,.04),0 4px 12px rgba(16,24,40,.04)` | Cards |

---

## Border Radius
| Size | Value | Usage |
|------|-------|-------|
| sm | 6px | Inputs |
| md | 8px | Nav items, buttons |
| lg | 10px | Cards |
| full | 999px | Avatars |

---

## Usage Notes
- **Layout:** default left sidebar kept (this is the base chrome) — no structural reflow.
- **Contrast:** white sidebar on a gray canvas, deliberately addressing the WP-redesign feedback about weak sidebar/content separation.
- **Self-contained:** scoped `body.theme-m00`, owns its full palette; no legacy layer.
- **Auto-maintained by Claude Code:** Yes
