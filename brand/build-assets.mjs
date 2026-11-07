// Builds every Flowcast logo file from the one hand-drawn source, logo-mark.svg.
// Run from this folder:  npm install  (first time)  then  npm run build
//
// Never edit the generated files by hand; edit logo-mark.svg and re-run this.

import { Resvg } from '@resvg/resvg-js';
import { readFileSync, writeFileSync, mkdirSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const root = resolve(here, '..');

const NAVY = '#0b1636';
const WHITE = '#ffffff';

// --- Read the source mark ---------------------------------------------------
const source = readFileSync(resolve(here, 'logo-mark.svg'), 'utf8');
const viewBox = source.match(/viewBox="([^"]+)"/)[1];
const [vx, vy, vw, vh] = viewBox.split(/\s+/).map(Number);
// The drawing itself (everything inside <svg>, minus comments and <title>).
const inner = source
  .replace(/^[\s\S]*?<svg[^>]*>/, '')
  .replace(/<\/svg>\s*$/, '')
  .replace(/<!--[\s\S]*?-->/g, '')
  .replace(/<title>[\s\S]*?<\/title>/g, '')
  .trim();

const recolor = (color) => inner.replaceAll(NAVY, color);

// The bare mark, in a color, on a transparent square.
const mark = (color) =>
  `<svg xmlns="http://www.w3.org/2000/svg" viewBox="${viewBox}" width="64" height="64">` +
  `<title>Flowcast</title>${recolor(color)}</svg>`;

// The mark scaled into a square tile.
//   scale  - mark size relative to the tile (0..1)
//   radius - corner radius in tile units (0 = full-bleed, the OS rounds it)
//   bg     - tile color, or null for transparent
const tile = ({ color, bg, scale, radius = 0 }) => {
  const cx = vx + vw / 2;
  const cy = vy + vh / 2;
  const back = bg ? `<rect width="64" height="64" rx="${radius}" fill="${bg}"/>` : '';
  return (
    `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64" width="64" height="64">` +
    `<title>Flowcast</title>${back}` +
    `<g transform="translate(32 32) scale(${scale}) translate(${-cx} ${-cy})">${recolor(color)}</g></svg>`
  );
};

// --- Writers ----------------------------------------------------------------
const out = (rel) => {
  const path = resolve(root, rel);
  mkdirSync(dirname(path), { recursive: true });
  return path;
};
const svg = (rel, markup) => {
  writeFileSync(out(rel), markup + '\n');
  console.log('svg ', rel);
};
const png = (rel, markup, size) => {
  const image = new Resvg(markup, { fitTo: { mode: 'width', value: size } }).render();
  writeFileSync(out(rel), image.asPng());
  console.log('png ', rel, `${size}px`);
};

// --- Brand folder (reference copies) ---------------------------------------
svg('brand/logo-mark-white.svg', mark(WHITE));
svg('brand/logo-tile.svg', tile({ color: WHITE, bg: NAVY, scale: 0.72, radius: 15 }));

// --- Web --------------------------------------------------------------------
svg('web/src/assets/logo-mark.svg', mark(NAVY));
svg('web/src/assets/logo-mark-white.svg', mark(WHITE));
// Browser tab: the rounded tile reads far better than the bare mark at 16px.
svg('web/public/favicon.svg', tile({ color: WHITE, bg: NAVY, scale: 0.72, radius: 15 }));

// --- Mobile -----------------------------------------------------------------
// In-app logo; React Native picks the @2x/@3x file for sharper screens.
png('mobile/assets/logo.png', mark(NAVY), 96);
png('mobile/assets/logo@2x.png', mark(NAVY), 192);
png('mobile/assets/logo@3x.png', mark(NAVY), 288);
// App icon: full-bleed navy square; iOS and Android round the corners themselves.
png('mobile/assets/icon.png', tile({ color: WHITE, bg: NAVY, scale: 0.62 }), 1024);
// Android adaptive icon: transparent foreground over the navy background set in
// app.json. The launcher may crop to the middle 66%, so the mark stays small.
png('mobile/assets/android-icon-foreground.png', tile({ color: WHITE, bg: null, scale: 0.46 }), 1024);
// Splash: navy mark on the white splash background.
png('mobile/assets/splash-icon.png', tile({ color: NAVY, bg: null, scale: 0.9 }), 600);
// Expo web favicon.
png('mobile/assets/favicon.png', tile({ color: WHITE, bg: NAVY, scale: 0.72, radius: 15 }), 48);

// --- API (PDF certificate) --------------------------------------------------
png('api/resources/images/logo-mark.png', mark(NAVY), 256);

console.log('done');
