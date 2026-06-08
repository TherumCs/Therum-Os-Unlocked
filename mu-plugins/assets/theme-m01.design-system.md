# Design System Reference — Theme 01 · "Warm Studio"

**Theme key:** `theme-01` · **palette/body class:** `theme-m01` · **stylesheet:** `assets/theme-m01.css`
**Reference:** Crextio HR dashboard (warm ivory, golden accent, top pill-nav, soft rounded bento).

---

## Color Palette

### Primary Colors
| Name | Hex | Usage |
|------|-----|-------|
| Accent (Gold) | `#F2C20E` | Buttons, active-icon, links, KPI highlights (`--ac`) |
| Accent Hover | `#D9A800` | Hover state of accent (`--ac-h`) |
| Accent Soft | `rgba(242,194,14,.14)` | Accent tint / selected backgrounds (`--ac-s`) |

### Secondary Colors
| Name | Hex | Usage |
|------|-----|-------|
| Ink / Dark | `#1A1916` | Active nav pill, dark spotlight card, on-accent text (`--dark`) |

### Neutrals
| Name | Hex | Usage |
|------|-----|-------|
| Canvas | `#F4ECD4` | Page background base (`--bg`) |
| Surface | `#FFFFFF` | Cards, panels, top bar (`--sf`) |
| Surface 2 | `#FBF6EA` | Hover wells, nested surface (`--sf2`) |
| Surface 3 | `#F0E8D6` | Inputs, dividers, card foot border (`--sf3`) |
| Text | `#1C1A16` | Primary text (`--tx`) |
| Text 2 | `#7A7466` | Secondary text (`--tx2`) |
| Text 3 | `#A8A292` | Muted / captions (`--tx3`) |
| Border | `rgba(40,30,10,.12)` | Hairline (`--bd`) |
| Border 2 | `rgba(40,30,10,.20)` | Stronger border (`--bd2`) |

### Canvas Gradient (customizable — Quick Controls → Canvas gradient)
| Stop | Hex | Var |
|------|-----|-----|
| From | `#F5EFE0` | `--m01-g1` |
| Mid | `#F4ECD4` | `--m01-g2` |
| To | `#F7E7AE` | `--m01-g3` |
| Angle | `165deg` | `--m01-g-angle` |

### Semantic Colors
| Name | Hex | Usage |
|------|-----|-------|
| Success | `#2F9E5E` | Positive trends, confirmations |
| Warning | `#E6A817` | Cautions |
| Error | `#E5533D` | Destructive actions, errors |
| Info | `#4F6BED` | Informational messages |

---

## Typography

### Font Families
- **Display:** Poppins (geometric) — `--m01-display` — self-hosted woff2 (600, 700)
- **Body:** Plus Jakarta Sans — `--m01-font` — self-hosted woff2 (400, 500, 600, 700)
- **Monospace:** ui-monospace, JetBrains Mono fallback

### Type Scale
| Name | Size | Weight | Letter Spacing | Family | Usage |
|------|------|--------|----------------|--------|-------|
| Display / Welcome | 40px | 600 | -.02em | Poppins | Page hero headline |
| H1 / Page title | 30px | 600 | -.01em | Poppins | `.th-lp-title` / `.th-cx-title` |
| H3 / Card title | 17–18px | 700 | -.01em | Poppins | `.th-studio-card-name`, `.th-card h3` |
| Stat value | 30px | 700 | -.02em | Poppins | `.th-stat-val` |
| Body | 14px | 400/500 | normal | Jakarta | Body, descriptions |
| Label / Nav | 14px | 600 | normal | Jakarta | Nav pills, labels |
| Small | 11–12px | 600 | .05em (caps) | Jakarta | Eyebrows, captions |

---

## Spacing Scale (4px base)
| Size | Value | Usage |
|------|-------|-------|
| xs | 4px | Icon gaps |
| sm | 8px | Tight padding, card hero inset |
| md | 12–16px | Default padding, pill padding (9×14) |
| lg | 24px | Top-bar inset, card padding |
| xl | 32–40px | Page edge padding, section gaps |

---

## Component Library

### Top Nav (relocated sidebar)
- **Height:** 66px (`--m01-navh`), fixed full-width, `rgba(255,255,255,.72)` + blur(20px)
- **Logo:** 34×34, radius 10, gold bg
- **Search:** 200px, white, 1px border, radius 10
- **Nav pills:** padding 9×14, radius 10; **active** = dark `#1A1916` bg, white text, gold icon
- **Sections:** hover dropdown (`.th-sb-section-items`, min-width 220, radius 14, shadow `0 18px 48px rgba(80,60,10,.18)`)
- **Footer:** avatar right (edit/view-frontend buttons hidden in bar)

### Buttons
#### Primary
- Bg `--ac` (#F2C20E), text `#1A1916`, no border, radius 8
- `.th-btn-primary`, `.th-cx-btn.is-primary`

### Cards
- Bg `#FFFFFF`, no border, **radius 22px**, shadow `0 10px 30px rgba(120,90,10,.08)`
- `.th-card`, `.th-studio-card`
- Hero inset: 8px margin, radius 16

### Sidebar items / children
- Radius 10 (items), 8 (children); hover bg `--sf2`

---

## Shadows & Elevation
| Level | CSS | Usage |
|-------|-----|-------|
| 1 (card) | `0 10px 30px rgba(120,90,10,.08)` | Cards, panels |
| 2 (bar) | `0 4px 16px rgba(120,90,10,.07)` | Top bar / chips |
| 3 (dropdown) | `0 18px 48px rgba(80,60,10,.18)` | Nav dropdowns, modals |

---

## Border Radius
| Size | Value | Usage |
|------|-------|-------|
| sm | 8px | Buttons, child items |
| md | 10px | Nav pills, search, logo |
| lg | 14px | Dropdowns |
| xl | 16px | Card hero |
| 2xl | 22px | Cards |
| full | 999px | Avatars |

---

## Animations & Transitions
| Property | Duration | Easing | Usage |
|----------|----------|--------|-------|
| dropdown open | 140ms | ease | Nav section flyouts |
| hover bg/color | 150ms | ease | Pills, items, buttons |

---

## Usage Notes
- **Body class gate:** all rules scoped `body.theme-m01` (palette uses `html body.theme-m01` to win specificity; no legacy layer).
- **Self-contained:** Theme 01 owns its full palette — no shared/legacy theme CSS.
- **Auto-maintained by Claude Code:** Yes
- **Reference this for:** building Theme 02+ consistently and any Theme 01 tweaks.
