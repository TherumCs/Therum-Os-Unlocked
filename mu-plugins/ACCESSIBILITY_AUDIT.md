# Therum OS — Accessibility Audit (WCAG 2.1 AA)

Scope: admin chrome + Theme 00 (Default) and Theme 01 (Warm Studio) + Studio/Quick-Controls UI.
Method: automated contrast computation (WCAG formula) + code review. Screen-reader and AT testing still pending (see Known Issues).

---

## 1. Color & Contrast — measured ratios (after fixes)

| Theme / pair | Foreground | Background | Ratio | Pass (4.5:1) |
|---|---|---|---|---|
| **T00 light** body text | #1E1E1E | #FFFFFF | 16.7 | ✅ |
| body on canvas | #1E1E1E | #F6F7F9 | 15.6 | ✅ |
| secondary | #50575E | #FFFFFF | 7.3 | ✅ |
| muted *(was 3.24 ✗ → fixed)* | #6B7177 | #FFFFFF | 4.9 | ✅ |
| accent button text | #FFFFFF | #3858E9 | 5.6 | ✅ |
| link | #3858E9 | #FFFFFF | 5.6 | ✅ |
| **T00 dark** body text | #F2F4F7 | #15161A | 16.4 | ✅ |
| secondary | #A9AEB8 | #1E2027 | 7.3 | ✅ |
| muted *(was 3.46 ✗ → fixed)* | #8A909C | #1E2027 | 5.1 | ✅ |
| accent button text *(was white 3.68 ✗ → dark ink)* | #0E1016 | #5B7CFA | 5.2 | ✅ |
| accent (links/icons) | #5B7CFA | #15161A | 4.9 | ✅ |
| dark rail text | #C7CCD6 | #16181E | 11.0 | ✅ |
| **T01** body text | #1C1A16 | #FFFFFF | 17.4 | ✅ |
| secondary | #7A7466 | #FFFFFF | 4.65 | ✅ |
| muted *(was 2.55 ✗ → fixed)* | #736D5C | #FFFFFF | 5.2 | ✅ |
| accent button text | #1A1916 | #F2C20E | 10.5 | ✅ |

**Fixes applied:** darkened/lightened every `--tx3` (muted) token to ≥4.5:1; Theme 00 dark primary-button text switched white→dark ink (`--m00-btn-ink`) so it clears 4.5:1 on the lighter dark-mode accent.
**Note:** gold `#F2C20E` is only ever used as a *button background* (with dark text, 10.5:1) — never as text on white. Accent is never the sole signal (active states also use a pill/inset bar + weight).

---

## 2. Keyboard & Focus

- ✅ **Visible focus** added OS-wide (`therum-controls.css`): `:focus-visible` → 2px accent outline, 2px offset, never removed. Sidebar/nav items use inset outline. Meets 2.4.7 + 3:1.
- ✅ Interactive elements are real `<a>`/`<button>`/`<input>`/`<select>` (Studio controls, gradient picker, behavior/advanced forms).
- ⚠️ **Wide-mode hover sidebar** reveals on `:hover` — also reveals on `:focus-within` for keyboard users in Theme 01; Theme 00 wide reveal should add `:focus-within` (see Known Issues).
- ⚠️ Skip-link to main content: not yet present (Known Issues).

## 3. Motion

- ✅ `prefers-reduced-motion: reduce` honored OS-wide (animations/transitions reduced to ~0ms).

## 4. Semantics / ARIA

- ✅ Landmarks: `#th-sb` is `<aside>`, nav is `<nav>`, content `<main class="th-cx-main">`.
- ✅ Tabs use `role="tablist"/"tab"` with `aria-selected` (theme store source tabs).
- ⚠️ **Icon-only topbar buttons** (theme toggle, desktop, external, avatar) need `aria-label`s — pending in markup.
- ⚠️ Color inputs in the gradient control have visible text labels (From/Mid/To) but should be wired as `<label for>` pairs.

## 5. Touch / Text

- Quick Controls includes a **Large targets** toggle (forces ≥44px). Default control heights are 36px; enabling large-targets meets 44×44 (2.5.5).
- ✅ Body text 14px, line-height ≥1.45 (base design system). No `user-scalable=no`.

---

## Known Issues / TODO
1. ✅ DONE — `aria-label` added to icon-only topbar buttons (theme toggle, desktop, view-site) + sidebar search.
2. ✅ DONE — skip-to-content link added (`.th-skip-link` → `#th-content`, visible on focus).
3. ✅ DONE — Theme 00 Wide sidebar reveals on `:focus-within` (keyboard parity with hover).
4. ✅ DONE — gradient color inputs + angle slider given explicit `aria-label`s (also implicitly labelled).
5. ⏳ Manual AT passes still recommended: NVDA / VoiceOver, axe DevTools, 200% zoom, color-blindness sim (cannot be automated here).

## Status
- **Contrast (1.4.3):** PASS for all theme text tokens after fixes.
- **Focus visible (2.4.7):** PASS (baseline added).
- **Reduced motion (2.3.3 / 2.2.2):** PASS.
- **Remaining:** the 5 items above before a clean axe/AT run.
- **Last reviewed:** 2026-06-08 (automated contrast + code review).
