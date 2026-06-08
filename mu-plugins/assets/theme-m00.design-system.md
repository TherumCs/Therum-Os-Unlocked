# Design System Reference — Theme 00 · "Default"

**Theme key:** `theme-00` · **palette/body class:** `theme-m00` · **stylesheet:** `assets/theme-m00.css`
**Direction:** Modern WordPress-admin redesign (make.wordpress.org 2023) + Behance WP admin redesign — **dark left rail + switchable light/dark content**, blue accent, flat cards, generous whitespace.

**Modes:** `light` (body.theme-m00.light) and `dark` (body.theme-m00). The sidebar stays **dark in both**. Toggle via Quick Controls → Mode; lands in **light** by default.

---

## Color Palette

### Accent
| Mode | Accent | Hover | Soft |
|------|--------|-------|------|
| Light | `#3858E9` | `#2E45C5` | `rgba(56,88,233,.10)` |
| Dark | `#5B7CFA` | `#7C97FF` | `rgba(91,124,250,.18)` |

### Sidebar (dark, both modes)
| Token | Value | Usage |
|-------|-------|-------|
| Sidebar bg | `#16181E` | `#th-sb` |
| Sidebar text | `#C7CCD6` | Nav items |
| Sidebar muted | `rgba(255,255,255,.42)` | Caps, icons |
| Active item | `rgba(255,255,255,.08)` + inset 2px accent bar | Current page |

### Content — Light mode
| Name | Hex | Usage |
|------|-----|-------|
| Canvas | `#F6F7F9` | Content background (`--bg`) |
| Surface | `#FFFFFF` | Cards (`--sf`) |
| Surface 2 / 3 | `#F1F3F5` / `#E9ECEF` | Wells / inputs |
| Text / 2 / 3 | `#1E1E1E` / `#50575E` / `#8C8F94` | Ink / gray / muted |
| Border / 2 | `#E3E5E8` / `#D2D5D9` | Hairlines |

### Content — Dark mode
| Name | Hex | Usage |
|------|-----|-------|
| Canvas | `#15161A` | Content background |
| Surface | `#1E2027` | Cards |
| Surface 2 / 3 | `#262932` / `#2F333D` | Wells / inputs |
| Text / 2 / 3 | `#F2F4F7` / `#A9AEB8` / `#6E7480` | Ink / gray / muted |
| Border / 2 | `rgba(255,255,255,.09)` / `rgba(255,255,255,.16)` | Hairlines |

### Semantic Colors
| Name | Hex |
|------|-----|
| Success | `#1E8E3E` |
| Warning | `#E6A817` |
| Error | `#D63638` (WP red) |
| Info | `#3858E9` |

---

## Typography
- **Family:** system stack — `-apple-system, BlinkMacSystemFont, Inter, "Segoe UI", Roboto, sans-serif`
- **Mono:** monospace

### Type Scale
| Name | Size | Weight | Usage |
|------|------|--------|-------|
| H1 / Page title | 28px | 700 | `.th-lp-title`, `.th-cx-title` (-.01em) |
| Card title | 16px | 700 | `.th-studio-card-name`, `.th-card h3` |
| Stat | 28px | 700 | `.th-stat-val` |
| Body | 14px | 400/500 | Body |
| Label / Nav | 13–14px | 500/600 | Nav |
| Small | 11px | 600 | Eyebrows |

---

## Spacing Scale (4px base)
| Size | Value |
|------|-------|
| xs | 4px |
| sm | 8px |
| md | 16px |
| lg | 24px |
| xl | 40px (generous whitespace) |

---

## Components
- **Sidebar:** dark `#16181E`, hairline right border; items radius 8, light text; active = subtle white bg + inset accent bar; logo = accent bg.
- **Top bar:** transparent, hairline bottom.
- **Cards:** `--sf`, 1px `--bd` border, radius 10, soft shadow (`--m00-cardshadow`, mode-aware); hero flush.
- **Primary button:** accent bg, white text; hover `--ac-h`.

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
- **Layout:** default left sidebar (no structural reflow). Dark rail + light/dark content.
- **Mode:** themes now land in their declared mode (Theme 00 = light); `mode` toggle adds/removes the `light` body class, which swaps the content palette. Sidebar unaffected (always dark).
- **Self-contained:** scoped `body.theme-m00`, owns its full palette; no legacy layer.
- **Auto-maintained by Claude Code:** Yes
