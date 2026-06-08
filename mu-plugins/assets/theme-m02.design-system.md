# Design System Reference — Theme 02 · "Coral Finance"

**Theme key:** `theme-02` · **palette/body class:** `theme-m02` · **stylesheet:** `assets/theme-m02.css`
**Reference:** N2 Financial Dashboard — light cool-gray, white very-rounded cards + soft shadows, coral accent, top pill-nav, one dark spotlight card.

---

## Color Palette
| Name | Hex | Usage |
|------|-----|-------|
| Accent (Coral) | `#F0563E` | Buttons, active nav, highlights (`--ac`) |
| Accent Hover | `#DC4528` | `--ac-h` |
| Accent Soft | `rgba(240,86,62,.12)` | tint (`--ac-s`) |
| Dark | `#16181C` | logo, spotlight card |
| Canvas | `#EFF0F3` | page bg (`--bg`) |
| Surface | `#FFFFFF` | cards (`--sf`) |
| Surface 2 / 3 | `#F6F7F9` / `#EEEFF2` | wells / inputs |
| Text / 2 / 3 | `#1A1D21` / `#6B7178` / `#9AA0A8` | ink / secondary / muted |
| Border / 2 | `rgba(20,24,30,.08)` / `rgba(20,24,30,.14)` | hairlines |

### Semantic
Success `#16A34A` · Warning `#E6A817` · Error `#E5533D` · Info `#3B82F6`

## Typography
- **Family:** Inter (self-hosted) — `--m02-font`
- Hero/H1 30px/700 (-.02em) · Card title 16px/700 · Stat 28px/700 · Body 14px · Small 12px

## Spacing (4px base)
xs 4 · sm 8 · md 16 · lg 24 · xl 32

## Components
- **Top nav:** fixed white bar, 68px; logo (dark square) left, pill search, centered nav pills (active = coral), avatar right; sections = hover dropdowns.
- **Cards:** white, **radius 20**, soft shadow `0 1px 2px rgba(20,24,40,.04),0 12px 30px rgba(20,24,40,.06)`; last bento card = dark spotlight.
- **Buttons:** coral, **pill** (999px).
- **Inputs:** pill search; 8px fields, hairline border, soft focus ring (base).

## Radius
sm 8 · md 12 · lg 14 · card 20 · pill 999

## Usage Notes
- Sidebar relocates to a top pill-nav (collapsed nav, like the reference). Full menu stays reachable via dropdowns.
- Light theme; base palette provides a dark fallback if Mode is toggled.
- Self-contained, scoped `body.theme-m02`, palette tokens `!important` (win over base/overrides).
- Auto-maintained by Claude Code.
