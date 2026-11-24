// Page images of the lighting products (src/content/pages*/lighting/), cut from the lighting catalogue sheets
// (Unixpadel_Lighting_Catalog_<Court>_MARGAY-<n>.pdf). They are not on WordPress, so they live in
// src/assets/pages/lighting/ and pages reference them as `asset:lighting/<file>` (src/lib/media.ts).
//
//   node scripts/build-lighting-assets.mjs <dir>
//
// <dir> holds, per sheet <tag> (pan = MARGAY-300 on the panoramic court, std = MARGAY-200 on the standard court):
//   <tag>-x50.png          the court with the isolux map (embedded image of the PDF)
//   <tag>-x20.png          the pole with two floodlights (embedded image, transparent)
//   <tag>-photometric.png  the photometric diagram panel, rendered from the PDF page
//   <tag>-sheet.png        the whole sheet, rendered from the PDF page
// Every picture is written twice: full size and `.sm` (480 px) for cards and menu thumbnails.
import path from 'node:path';
import sharp from 'sharp';

const dir = process.argv[2];
if (!dir) {
  console.error('Usage: node scripts/build-lighting-assets.mjs <dir>');
  process.exit(1);
}
const OUT = path.resolve('src/assets/pages/lighting');
const SHEETS = { pan: 'margay-300', std: 'margay-200' };
// [source suffix, output name, full width]. Names carry "diagram" / "render" / "layout": the gallery shows such
// pictures whole instead of cropping them to its tiles (src/components/Gallery.astro).
const PICTURES = [
  ['x50', 'isolux-diagram', 1600],
  ['x20', 'pole-render', 720],
  ['photometric', 'photometric-diagram', 992],
  ['sheet', 'sheet-layout', 2000],
];

for (const [tag, product] of Object.entries(SHEETS)) {
  for (const [suffix, name, width] of PICTURES) {
    // The pole is a light grey cut-out: on black it reads in both themes, like the other three.
    const source = sharp(path.join(dir, `${tag}-${suffix}.png`)).flatten({ background: '#000' });
    const full = path.join(OUT, `${product}-${name}.webp`);
    await source.clone().resize({ width, withoutEnlargement: true }).webp({ quality: 86 }).toFile(full);
    await source.clone().resize({ width: 480 }).webp({ quality: 80 }).toFile(full.replace(/\.webp$/, '.sm.webp'));
  }
}
console.log('written to', path.relative(process.cwd(), OUT));
