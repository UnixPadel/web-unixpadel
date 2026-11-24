// One-off: wp-plugin/assets/wm-logo.png (the logo img-stamp.php puts on photos) from the site logo: navy wordmark -> white, soft
// dark halo so it reads on light photos.  node scripts/build-wm-logo.cjs <unixpadel-logo_retina.png> wp-plugin/assets/wm-logo.png <preview.png>
const sharp = require('sharp');
const fs = require('fs');
const [src, out, preview] = process.argv.slice(2);
(async () => {
  // The site logo has wide empty margins: cut them, so the stamp's width is the width of the mark itself.
  const { data, info } = await sharp(src).ensureAlpha().trim().raw().toBuffer({ resolveWithObject: true });
  for (let i = 0; i < data.length; i += 4) {
    const r = data[i], g = data[i + 1], b = data[i + 2];
    if (data[i + 3] >= 8 && b > r + 10 && b > g && r < 110) data[i] = data[i + 1] = data[i + 2] = 255;
  }
  const pad = 16, W = info.width + pad * 2, H = info.height + pad * 2;
  const white = await sharp({ create: { width: W, height: H, channels: 4, background: { r: 0, g: 0, b: 0, alpha: 0 } } })
    .composite([{ input: data, raw: info, left: pad, top: pad }]).png().toBuffer();
  const alpha = await sharp(white).extractChannel(3).blur(5).linear(0.6, 0).toColourspace('b-w').raw().toBuffer();
  const halo = Buffer.alloc(W * H * 4);
  for (let i = 0; i < W * H; i++) halo[i * 4 + 3] = alpha[i];
  const png = await sharp(halo, { raw: { width: W, height: H, channels: 4 } }).composite([{ input: white }]).png({ compressionLevel: 9 }).toBuffer();
  fs.writeFileSync(out, png);
  console.log(W, H, png.length, 'bytes');
  const tile = (bg) => sharp({ create: { width: W + 80, height: H + 40, channels: 3, background: bg } }).composite([{ input: png, left: 40, top: 20 }]).png().toBuffer();
  await sharp({ create: { width: W + 80, height: (H + 40) * 3, channels: 3, background: '#000' } })
    .composite([{ input: await tile('#ecebe6'), left: 0, top: 0 }, { input: await tile('#1b56a8'), left: 0, top: H + 40 }, { input: await tile('#c8322c'), left: 0, top: (H + 40) * 2 }])
    .png().toFile(preview);
})();
