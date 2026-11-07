---
name: flowcast-ui
description: Flowcast's design rules and locked logo. Use for ANY visual or UI work in this repo — building or changing a screen, page, component, layout, style, color, font, icon, logo, the TV queue board, the mobile app, or a Blade view people see (medical certificate PDF, emails). Load it together with frontend-design; where they disagree, this skill wins.
---

# Flowcast UI

Flowcast runs the Universidad de Dagupan (UDD) dental and medical clinic: a
head nurse at a desk, a dentist and a doctor between patients, students on
their phones, and a TV in the waiting room. People use it to answer two
questions: **who is next, and how long until I'm called.**

**Direction: quiet clinic, loud queue number.** Everything stays calm (navy,
white, one blue for actions) so the queue number and the time are always the
boldest thing on the screen. Spend boldness there and nowhere else.

Use `frontend-design` for its process: plan, check the plan against generic
defaults, build, then critique. Then apply the rules below. Where the two
disagree (e.g. "take an aesthetic risk"), these rules win: the colors, font
and logo are locked.

## 1. Tokens (no new colors)

| Token | Value | Use |
|---|---|---|
| brand | `#0b1636` navy (`bg-brand`, `text-brand`) | sidebar, top bar, headings, primary buttons on mobile |
| accent | `blue-600` / `#2a78d6` | primary actions, links, chart "actual" line |
| page | `gray-50` | page background behind content |
| surface | white | content areas |
| line | `slate-200` | the few borders we keep |
| text / muted | `slate-900` / `slate-500` | body text / secondary text |
| status | waiting `slate-100`, in consultation `blue-100`, done `green-100`, skipped/no-show `red-100` | `StatusPill`, queue rows |
| charts | `web/src/lib/theme.ts` `CHART_COLORS` | every chart; never pick chart colors inline |
| font | **Atkinson Hyperlegible Next** | everything (web, mobile, TV). The PDF uses DejaVu Sans (dompdf limit) |

Semantic colors (green/amber/red for good/warning/error) are not accents.
Use them only to show state.

## 2. Minimalist rules

- One primary (filled) button per view; everything else is outline or text.
- No icon-only buttons without a text label (the TV board is the one display-only exception).
- At most 4–5 stat tiles on a page, and only when the numbers are the point.
- **No card soup.** Don't wrap every block in `rounded-lg border shadow-sm`.
  Separate sections with space; use a border only where items really group
  (a table, a form). Shadows are for things that float: menus, dialogs, toasts.
- Tables for lists of records (patients, visits, stock), not grids of cards.
- Left-align content. Center only the TV board's "Now Serving" number and
  empty states.
- Queue numbers, times and counts use `tabular-nums` so digits don't jump.

## 3. Locked logo

The mark is an F whose middle bar flows into a wave. **It is locked.**

- Use only the generated files, through the components:
  - web: `LogoMark` in `web/src/components/Logo.tsx` (`tone="navy"` on light, `tone="white"` on navy or dark);
  - mobile: `LogoMark` in `mobile/components/Logo.tsx`;
  - PDF: `api/resources/images/logo-mark.png`, embedded as base64.
- Never redraw, recolor, rotate, stretch, outline, add shadows or gradients,
  or set it on a busy background. Navy on light backgrounds, white on navy.
- Clear space around it = half the mark's height. Minimum size 16 px; below
  32 px (favicons, app icons) use the tile form, which `brand/` already generates.
- The wordmark is the mark plus the word "Flowcast" set live in the brand font
  at `font-semibold`, never a different typeface.
- To change the logo, edit `brand/logo-mark.svg` and run `npm run build` in
  `brand/`. Every app file is regenerated from it. Never edit the outputs.

## 4. Reuse before you build

- **web** (`web/src/components/`): `AppShell`, `PageHeader`, `Button`
  (primary / outline / danger / ghost), `StatTile`, `StatusPill`, `Toast`,
  `PageLoading`, `icons.tsx`, `LogoMark`.
- **mobile** (`mobile/components/`): `ScreenHeader`, `AuthHeader`, `LogoMark`,
  `OfflineNotice`, `OptionPicker`, `MonthCalendar`, and **`Text` / `TextInput`
  from `components/Text`**.
- Shared date and status helpers live in `web/src/lib/` and `mobile/lib/`;
  don't reformat dates by hand.

## 5. Per-surface notes

- **Web**: Tailwind v4, CSS-first. Tokens live in `@theme` in
  `web/src/index.css`; there is no `tailwind.config`. The font is bundled by
  `@fontsource-variable/atkinson-hyperlegible-next` (imported in `main.tsx`):
  no font CDNs, because the offline cache must keep working.
- **Mobile**: NativeWind 4 / Tailwind 3 (`mobile/tailwind.config.js`).
  **Always import `Text` and `TextInput` from `components/Text`, never from
  `react-native`.** The wrapper turns `font-medium` / `font-semibold` /
  `font-bold` into the matching font file. Plain React Native ignores the
  weight with custom fonts, and Android fakes a smeared bold. For a
  `fontFamily` outside className (e.g. tab bar labels), use `BRAND_FONT.<weight>`
  and never `fontWeight`. The tab colors are hardcoded in `app/(tabs)/_layout.tsx`.
- **TV board** (`web/src/pages/TvBoard.tsx`): read from across a waiting room.
  Very large type, high contrast on `slate-950`, nothing interactive, nothing
  smaller than `text-lg`.
- **Certificate PDF** (dompdf): no flexbox or grid. Use tables or floats,
  DejaVu Sans, and images as base64 data. Keep it printable in black and white.
- **Email** (`api/resources/views/mail/`): inline styles only, text logo only
  (Gmail blocks data-URI images), Arial/Helvetica.

## 6. Avoid the generic AI look

None of these in Flowcast:
- gradients, glassmorphism, glows;
- emoji used as icons;
- ALL-CAPS tracked-out labels above headings;
- "→" appended to button text;
- meta strings joined with " · " where a table or line break would read better;
- `rounded-lg` + `shadow-sm` on every block;
- hover lift on every card, or fade-in on every section;
- lorem ipsum or "John Doe".

Use real clinic words: patient, queue number, booking code, visit, Dental,
Medical, head nurse. Buttons say exactly what they do ("Call next patient",
"Issue certificate"), and the toast repeats that verb ("Certificate issued").

Some older screens still break these rules (e.g. uppercase labels on the TV
board). Fix them when you're already changing that screen; don't sweep the
whole app unless asked.

## 7. Before you finish

- [ ] Only the tokens above. No new hex values, no new fonts.
- [ ] Logo used only through `LogoMark` / the generated files, in an allowed color.
- [ ] Mobile text uses `components/Text`.
- [ ] One primary action per view; no card soup.
- [ ] Web: `npm run build` and `npm run lint` pass in `web/`. Mobile: `npx tsc --noEmit` passes in `mobile/`.
- [ ] You looked at the result (a screenshot or the running app) at phone and desktop widths.
