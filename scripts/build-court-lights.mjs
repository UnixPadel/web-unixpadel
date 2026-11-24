// Builds the two frames of the lights on/off view (src/components/CourtLights.astro) for the portable court.
// The uploads are the same render on different canvases, and the lit one exists only as a 30 MB PNG with no WordPress
// size variants, so both are cut to one shared frame here and saved as WebP in src/assets/court-lights/.
//
//   node scripts/build-court-lights.mjs <Portable_Court-scaled.png> <Portable_Court_Light-2.png>
//
// Sources: /wp-content/uploads/2026/11/Portable_Court-scaled.png and /wp-content/uploads/2026/11/Portable_Court_Light-2.png
import fs from 'node:fs';
import path from 'node:path';
import sharp from 'sharp';

const [dayFile, litFile] = process.argv.slice(2);
if (!dayFile || !litFile) {
  console.error('Usage: node scripts/build-court-lights.mjs <day.png> <lit.png>');
  process.exit(1);
}

const OUT = path.resolve('src/assets/court-lights/portable');
// All numbers are pixels of the lit original (9714x5117). The day render (9026x3955 before WordPress scaled it) sits
// at DAY.x/DAY.y inside it.
const DAY = { x: 148, y: 1161, width: 9026, height: 3955 };
const LIT = await sharp(litFile, { limitInputPixels: false }).metadata();
// The frame drops the empty sky, adds ground below the court's feet and pads the left side with black until the court
// is centred: the original has 540 px of room on the right of the court and only 148 px on the left.
const FRAME = { top: 700, ground: 180, padLeft: LIT.width - DAY.x - DAY.width - DAY.x };
FRAME.width = LIT.width + FRAME.padLeft;
FRAME.height = LIT.height - FRAME.top + FRAME.ground;
// The lamp haze runs into the original's left, right and top edges and would end there in a visible line. It is faded
// to pure black before it gets there, so the lit frame has no edge on the black page (--black-900 is #000 for this).
const FADE = { left: [0, 140], right: [LIT.width - 30, LIT.width - 500], top: [FRAME.top, FRAME.top + 480] };
const WIDTHS = [1024, 1920];

const smooth = (t) => {
  const c = Math.min(1, Math.max(0, t));
  return c * c * (3 - 2 * c);
};
const ramp = (v, [from, to]) => smooth((v - from) / (to - from));

fs.mkdirSync(OUT, { recursive: true });
for (const width of WIDTHS) {
  const k = width / FRAME.width;
  const height = Math.round(FRAME.height * k);
  const padPx = Math.round(FRAME.padLeft * k);
  const groundPx = Math.round(FRAME.ground * k);

  // Lit: opaque. Its lower edge is transparent in the upload, so it is flattened to black.
  // (sharp resizes before it extends, whatever the call order, so the padding is added to the already scaled picture.)
  const lit = await sharp(litFile, { limitInputPixels: false })
    .extract({ left: 0, top: FRAME.top, width: LIT.width, height: LIT.height - FRAME.top })
    .resize(width - padPx, height - groundPx, { fit: 'fill' })
    .flatten({ background: '#000' })
    .extend({ left: padPx, bottom: groundPx, background: '#000' })
    .png()
    .toBuffer();
  // Black veil whose opacity is the inverse of the edge fade.
  const veil = Buffer.alloc(width * height * 4);
  for (let y = 0; y < height; y++) {
    const oy = y / k + FRAME.top;
    for (let x = 0; x < width; x++) {
      const ox = x / k - FRAME.padLeft;
      const keep = ramp(ox, FADE.left) * ramp(ox, FADE.right) * ramp(oy, FADE.top);
      veil[(y * width + x) * 4 + 3] = Math.round((1 - keep) * 255);
    }
  }
  await sharp(lit)
    .composite([{ input: veil, raw: { width, height, channels: 4 } }])
    .removeAlpha()
    .webp({ quality: 84 })
    .toFile(path.join(OUT, `lit-${width}.webp`));

  // Day: transparent cut-out, placed at twice the size first so its position lands within a quarter pixel.
  const k2 = k * 2;
  const court = await sharp(dayFile)
    .resize(Math.round(DAY.width * k2), Math.round(DAY.height * k2), { fit: 'fill' })
    .png()
    .toBuffer();
  const canvas = await sharp({ create: { width: width * 2, height: height * 2, channels: 4, background: { r: 0, g: 0, b: 0, alpha: 0 } } })
    .composite([{ input: court, left: Math.round((DAY.x + FRAME.padLeft) * k2), top: Math.round((DAY.y - FRAME.top) * k2) }])
    .png()
    .toBuffer();
  await sharp(canvas).resize(width, height, { fit: 'fill' }).webp({ quality: 86, alphaQuality: 90 }).toFile(path.join(OUT, `day-${width}.webp`));

  console.log(`${width}x${height}`);
}
