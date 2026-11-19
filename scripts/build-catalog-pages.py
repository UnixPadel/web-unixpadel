#!/usr/bin/env python3
"""Builds the protected page files of the catalogue flipbook (src/components/CatalogViewer.astro) from the print PDF.

The PDF holds landscape A3 spreads. Each spread is rendered once at 300 dpi and cut at the fold into two A4 leaves, so
the halves meet without a seam. Every leaf gets a faint watermark and is written twice: a reading size and a zoom size
(the PDF's own image resolution: anything larger would only be upsampling).

The catalogue must not be downloadable, so no file is a readable page: each image is cut into tiles that are stored in
a shuffled order (with a few repeated edge pixels around every tile, cropped again by the viewer, so lossy compression
leaves no line where tiles meet). Only the viewer, which knows the seed, puts them back, on a canvas. File names are
derived from the same secret, so they cannot be guessed from the page number.

Output (catalog-export/, git-ignored, never deployed with the site):
  pack/<dir>/<name>.webp        the shuffled files; `astro dev` serves them from the live path
  catalog-pack-<dir>-N.zip      the same files in parts; uploaded to the GitHub release `catalog-<dir>`, from where the
                                WordPress plugin fetches them (wp-plugin/includes/catalog-pack.php)
and src/data/catalog-pack.json (committed): folder, salt, grids and the parts with their sha256.

Usage: python scripts/build-catalog-pages.py PDF [--check]
Needs PyMuPDF and Pillow. Keep shuffle()/fnv() in step with src/lib/catalog.ts.
"""
import argparse
import hashlib
import json
import secrets
import shutil
import sys
import zipfile
from pathlib import Path

import pymupdf
from PIL import Image, ImageChops, ImageDraw, ImageFont

ROOT = Path(__file__).resolve().parent.parent
OUT = ROOT / 'catalog-export'
MANIFEST = ROOT / 'src/data/catalog-pack.json'
GUTTER = 8  # repeated pixels around every stored tile
# variant -> leaf height in px, tile grid (columns, rows), WebP quality
VARIANTS = {'view': (1414, (6, 8), 80), 'zoom': (3508, (10, 14), 78)}
PART_BYTES = 14 * 1024 * 1024
WATERMARK = 'unixpadel.com'


def fnv(text):
    """FNV-1a, 32 bit."""
    h = 0x811C9DC5
    for b in text.encode('utf-8'):
        h = ((h ^ b) * 0x01000193) & 0xFFFFFFFF
    return h


def shuffle(n, seed):
    """Fisher-Yates over range(n) driven by mulberry32(seed): order[slot] = tile stored in that slot."""
    state = seed & 0xFFFFFFFF

    def rand():
        nonlocal state
        state = (state + 0x6D2B79F5) & 0xFFFFFFFF
        t = state
        t = ((t ^ (t >> 15)) * (t | 1)) & 0xFFFFFFFF
        t ^= (t + (((t ^ (t >> 7)) * (t | 61)) & 0xFFFFFFFF)) & 0xFFFFFFFF
        return ((t ^ (t >> 14)) & 0xFFFFFFFF) / 4294967296

    order = list(range(n))
    for i in range(n - 1, 0, -1):
        j = int(rand() * (i + 1))
        order[i], order[j] = order[j], order[i]
    return order


def file_name(salt, page, variant):
    key = f'{page}:{variant}'
    return f'{fnv(f"{salt}:a:{key}"):08x}{fnv(f"{salt}:b:{key}"):08x}.webp'


def tile_size(width, height, grid):
    cols, rows = grid
    return -(-width // cols), -(-height // rows)


def extend(img, width, height, pad):
    """The image on a canvas of width x height plus `pad` on every side, its edge pixels repeated outwards."""
    out = Image.new('RGB', (width + 2 * pad, height + 2 * pad))
    out.paste(img, (pad, pad))
    w, h = img.size
    out.paste(img.crop((w - 1, 0, w, h)).resize((width - w + pad, h)), (pad + w, pad))
    out.paste(img.crop((0, 0, 1, h)).resize((pad, h)), (0, pad))
    band = out.crop((0, pad, out.width, pad + 1)).resize((out.width, pad))
    out.paste(band, (0, 0))
    bottom = out.crop((0, pad + h - 1, out.width, pad + h)).resize((out.width, out.height - pad - h))
    out.paste(bottom, (0, pad + h))
    return out


def scramble(img, grid, order):
    cols, rows = grid
    tw, th = tile_size(*img.size, grid)
    src = extend(img, cols * tw, rows * th, GUTTER)
    sw, sh = tw + 2 * GUTTER, th + 2 * GUTTER
    out = Image.new('RGB', (cols * sw, rows * sh))
    for slot, tile in enumerate(order):
        x, y = (tile % cols) * tw, (tile // cols) * th
        out.paste(src.crop((x, y, x + sw, y + sh)), ((slot % cols) * sw, (slot // cols) * sh))
    return out


def unscramble(stored, size, grid, order):
    """What the viewer does; used by --check."""
    cols, rows = grid
    tw, th = tile_size(*size, grid)
    sw, sh = tw + 2 * GUTTER, th + 2 * GUTTER
    out = Image.new('RGB', (cols * tw, rows * th))
    for slot, tile in enumerate(order):
        x, y = (slot % cols) * sw + GUTTER, (slot // cols) * sh + GUTTER
        out.paste(stored.crop((x, y, x + tw, y + th)), ((tile % cols) * tw, (tile // cols) * th))
    return out.crop((0, 0, *size))


def watermark_layer(size):
    """Faint repeated diagonal wordmark, as a mask for the whole leaf."""
    w, h = size
    font = None
    for name in ('arialbd.ttf', 'DejaVuSans-Bold.ttf', 'Arial Bold.ttf'):
        try:
            font = ImageFont.truetype(name, round(w * 0.03))
            break
        except OSError:
            continue
    if font is None:
        sys.exit('No bold TrueType font found for the watermark')
    side = int((w * w + h * h) ** 0.5) + 200
    mask = Image.new('L', (side, side), 0)
    draw = ImageDraw.Draw(mask)
    step_x, step_y = round(w * 0.42), round(w * 0.2)
    for row, y in enumerate(range(0, side, step_y)):
        for x in range(-step_x, side, step_x):
            draw.text((x + (row % 2) * step_x // 2, y), WATERMARK, font=font, fill=255)
    mask = mask.rotate(30, resample=Image.BICUBIC)
    left, top = (side - w) // 2, (side - h) // 2
    return mask.crop((left, top, left + w, top + h))


def apply_watermark(leaf, mask, strength=0.045):
    """Lightens dark areas and darkens light ones a little, so the mark reads on the black pages and on photos."""
    light = Image.new('RGB', leaf.size, (255, 255, 255))
    lum = leaf.convert('L')
    on_dark = Image.composite(light, leaf, mask.point(lambda v: int(v * strength)))
    dark = Image.new('RGB', leaf.size, (0, 0, 0))
    on_light = Image.composite(dark, leaf, mask.point(lambda v: int(v * strength)))
    return Image.composite(on_light, on_dark, lum.point(lambda v: 255 if v > 150 else 0))


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('pdf', help='catalogue PDF (landscape A3 spreads)')
    ap.add_argument('--check', action='store_true', help='also put the first leaf back together and compare')
    args = ap.parse_args()
    src = Path(args.pdf)
    if not src.is_file():
        sys.exit(f'Not found: {src}')

    folder = secrets.token_hex(8)
    salt = secrets.token_hex(12)
    if OUT.exists():
        shutil.rmtree(OUT)  # the previous edition's files must not linger next to the new ones
    pack = OUT / 'pack' / folder
    pack.mkdir(parents=True)

    doc = pymupdf.open(src)
    zoom_h = VARIANTS['zoom'][0]
    page = 0
    sizes = {}
    mask = None
    for spread in doc:
        if spread.rect.width <= spread.rect.height:
            sys.exit(f'Page {spread.number + 1} is not a landscape spread')
        scale = zoom_h / spread.rect.height
        pix = spread.get_pixmap(matrix=pymupdf.Matrix(scale, scale), alpha=False)
        img = Image.frombytes('RGB', (pix.width, pix.height), pix.samples)
        half = img.width // 2
        # An odd pixel column at the fold would be dropped: take both halves from the centre outwards.
        for box in ((img.width // 2 - half, 0, img.width // 2, img.height), (img.width - half, 0, img.width, img.height)):
            page += 1
            leaf = img.crop(box)
            if mask is None or mask.size != leaf.size:
                mask = watermark_layer(leaf.size)
            leaf = apply_watermark(leaf, mask)
            for variant, (height, grid, quality) in VARIANTS.items():
                im = leaf if height == leaf.height else leaf.resize((round(leaf.width * height / leaf.height), height), Image.LANCZOS)
                if sizes.setdefault(variant, im.size) != im.size:
                    sys.exit(f'Leaf {page} ({variant}) is {im.size}, expected {sizes[variant]}: mixed page sizes')
                order = shuffle(grid[0] * grid[1], fnv(f'{salt}:{page}:{variant}'))
                stored = scramble(im, grid, order)
                path = pack / file_name(salt, page, variant)
                stored.save(path, 'WEBP', quality=quality, method=5)
                if args.check and page == 1:
                    back = unscramble(Image.open(path).convert('RGB'), im.size, grid, order)
                    diff = ImageChops.difference(back, im).convert('L')
                    hist = diff.histogram()
                    mean = sum(i * c for i, c in enumerate(hist)) / (im.width * im.height)
                    print(f'  check {variant}: mean abs difference {mean:.2f} / 255 (compression only), max {diff.getextrema()[1]}')
                    back.save(OUT / f'check-{variant}.png')
                    stored.save(OUT / f'check-{variant}-stored.png')
        print(f'spread {spread.number + 1}/{len(doc)} -> leaves {page - 1}, {page}', flush=True)

    files = sorted(pack.iterdir())
    parts, current, size = [], [], 0
    for f in files:
        if current and size + f.stat().st_size > PART_BYTES:
            parts.append(current)
            current, size = [], 0
        current.append(f)
        size += f.stat().st_size
    parts.append(current)
    listed = []
    for i, group in enumerate(parts, 1):
        name = f'catalog-pack-{folder}-{i}.zip'
        with zipfile.ZipFile(OUT / name, 'w', zipfile.ZIP_STORED) as z:
            for f in group:
                z.write(f, f.name)
        data = (OUT / name).read_bytes()
        listed.append({'name': name, 'sha256': hashlib.sha256(data).hexdigest(), 'bytes': len(data), 'files': len(group)})

    manifest = {
        'dir': folder,
        'salt': salt,
        'pages': page,
        'gutter': GUTTER,
        'variants': {v: {'width': sizes[v][0], 'height': sizes[v][1], 'cols': g[0], 'rows': g[1]} for v, (_, g, _) in VARIANTS.items()},
        'parts': listed,
    }
    MANIFEST.write_text(json.dumps(manifest, indent=2) + '\n', encoding='utf-8')
    total = sum(p['bytes'] for p in listed)
    print(f'{page} leaves, {len(files)} files, {total / 1048576:.1f} MB in {len(listed)} part(s) -> {OUT}')
    print(f'manifest: {MANIFEST.relative_to(ROOT)}  |  release tag: catalog-{folder}')


if __name__ == '__main__':
    main()
