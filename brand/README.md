# Flowcast brand

The official Flowcast mark is an F whose middle bar flows into a wave: patients
moving through the clinic queue. It is drawn with one 8-unit round stroke on a
64-unit grid in navy `#0b1636`.

## Files

| File | What it is |
|---|---|
| `logo-mark.svg` | **The source.** The only file you edit by hand. |
| `logo-mark-white.svg` | White version, for navy or dark backgrounds (generated) |
| `logo-tile.svg` | White mark on a rounded navy tile, for small sizes (generated) |
| `build-assets.mjs` | Builds every logo file the apps use from `logo-mark.svg` |

## Changing the logo

```sh
cd brand
npm install      # first time only
npm run build
```

This rewrites, from `logo-mark.svg`:

- **web:** `web/src/assets/logo-mark*.svg` and the tab icon `web/public/favicon.svg`;
- **mobile:** `mobile/assets/` logo (1x/2x/3x), app icon, Android adaptive icon, splash and favicon;
- **PDF certificate:** `api/resources/images/logo-mark.png`.

Never edit those outputs by hand.

## Rules

- **Colors:** navy on white or light backgrounds; white on navy or dark backgrounds. No other colors.
- **Clear space:** keep at least half the mark's height empty around it.
- **Minimum size:** 16 px. Below 32 px, use the tile version.
- **Don't** stretch, rotate, outline, add shadows, glows or gradients, or put it on photos or busy backgrounds.
- **Wordmark:** the mark, then the word "Flowcast" in Atkinson Hyperlegible Next SemiBold.
- **Font:** Atkinson Hyperlegible Next. It is free under the SIL Open Font License, bundled in the web and mobile apps, and chosen because 0/O and 1/l/I look different.

Full design rules: `.claude/skills/flowcast-ui/SKILL.md`.
