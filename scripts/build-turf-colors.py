#!/usr/bin/env python3
"""Pre-renders the turf colour picker images (src/assets/turf/<product>/) from the product photos on the live site.

For each photo: removes the white background (real alpha, white un-mixed from the fuzzy fibre edges so there is no halo
on a coloured plate), finds the turf pile, then recolours only the pile; the black backing stays as photographed.

Usage: python scripts/build-turf-colors.py [--src DIR_WITH_JPGS] [--debug DIR] [--products SLUG ...]
Needs Pillow and numpy. Colours and views must match src/data/turfColors.ts.
"""
import argparse
import urllib.request
from collections import deque
from pathlib import Path

import numpy as np
from PIL import Image, ImageFilter

ROOT = Path(__file__).resolve().parent.parent
OUT = ROOT / 'src/assets/turf'
UPLOADS = 'https://www.unixpadel.com/wp-content/uploads/2023/08/'
# view -> photo per product. 'own' adds the product's own photographed colour when the shared palette has nothing close.
PRODUCTS = {
    'adlx3t': {'views': {'roll': 'ADLX3T-2.jpg', 'sheet': 'ADLX3T-3.jpg'}},
    'adlx4t': {'views': {'roll': 'ADLX4T-2.jpg', 'sheet': 'ADLX4T-3.jpg', 'flat': 'ADLX4T-4.jpg'}, 'own': ('red', '#dc2430', 0.65), 'texture': 1.5, 'glint': 0.55},
}
# Same colours as the swatches. 'lift' is how much of the fibre sheen is kept: dark colours need more to show any pile.
COLORS = {
    'light-blue': ('#8fd3e8', 0.55),
    'blue': ('#1565c0', 0.7),
    'green': ('#2e8b57', 0.7),
    'emerald': ('#009b77', 0.7),
    'black': ('#181818', 0.55),
    'pink': ('#e5447f', 0.65),
    'terracotta': ('#c66a49', 0.65),
    'purple': ('#6a4c93', 0.7),
    'gray': ('#8a9099', 0.6),
}
WEBP_QUALITY = 82
# Already-transparent sources: no recolouring, just erase the compression specks floating in the empty
# area (only alpha connected to the main object survives) and trim the margins. Saved under OUT/<key>.webp.
CUTOUTS = {
    'guide/turf-cross-section': 'https://www.unixpadel.com/wp-content/uploads/2026/07/ffe7ca_6e8ed57e25584b9795c91f5d3ed09510mv2.avif',
}


def flood_from_border(passable):
    """Pixels reachable from the image border through `passable`: the background, not the white glints inside the pile."""
    h, w = passable.shape
    seen = np.zeros_like(passable)
    queue = deque()
    for x in range(w):
        for y in (0, h - 1):
            if passable[y, x]:
                seen[y, x] = True
                queue.append((y, x))
    for y in range(h):
        for x in (0, w - 1):
            if passable[y, x] and not seen[y, x]:
                seen[y, x] = True
                queue.append((y, x))
    while queue:
        y, x = queue.popleft()
        for ny, nx in ((y - 1, x), (y + 1, x), (y, x - 1), (y, x + 1)):
            if 0 <= ny < h and 0 <= nx < w and passable[ny, nx] and not seen[ny, nx]:
                seen[ny, nx] = True
                queue.append((ny, nx))
    return seen


def largest_component(mask):
    """Largest 4-connected region of a boolean mask."""
    h, w = mask.shape
    labels = np.zeros(mask.shape, dtype=np.int32)
    best, best_size, current = 0, 0, 0
    for sy, sx in zip(*np.nonzero(mask)):
        if labels[sy, sx]:
            continue
        current += 1
        labels[sy, sx] = current
        queue, size = deque([(sy, sx)]), 0
        while queue:
            y, x = queue.popleft()
            size += 1
            for ny, nx in ((y - 1, x), (y + 1, x), (y, x - 1), (y, x + 1)):
                if 0 <= ny < h and 0 <= nx < w and mask[ny, nx] and not labels[ny, nx]:
                    labels[ny, nx] = current
                    queue.append((ny, nx))
        if size > best_size:
            best, best_size = current, size
    return labels == best


def to_image(mask):
    return Image.fromarray((np.clip(mask, 0, 1) * 255).astype(np.uint8), 'L')


def blur(mask, radius):
    return np.asarray(to_image(mask).filter(ImageFilter.GaussianBlur(radius))).astype(float) / 255


def dilate(mask, size):
    return np.asarray(to_image(mask).filter(ImageFilter.MaxFilter(size))) > 127


def erode(mask, size):
    return np.asarray(to_image(mask).filter(ImageFilter.MinFilter(size))) > 127


def cut_out(rgb):
    """Returns (alpha, foreground) with the white background un-mixed."""
    darkest = rgb.min(axis=2)
    background = flood_from_border(darkest > 215)
    solid = ~background
    rim = solid & ~erode(solid, 5)
    alpha = np.ones(darkest.shape)
    # Pile and backing both have a dark red channel, so how far a rim pixel sits from white in its darkest channel
    # says how much object is in it.
    alpha[background] = 0
    rim_or_bg = background | rim
    coverage = np.clip((250 - darkest) / 120, 0, 1)
    alpha[rim_or_bg] = coverage[rim_or_bg]
    alpha = np.where(alpha < 0.04, 0, alpha)

    a = np.maximum(alpha, 1e-3)[..., None]
    foreground = np.clip((rgb - (1 - a) * 255) / a, 0, 255)
    return alpha, foreground


def pile_mask(rgb, alpha):
    """Soft mask of the turf pile: everything of the object but the neutral black backing and its pale seam."""
    # How much of the pile's own hue each pixel has. Measured along that hue, not as plain saturation: the backing can
    # carry a faint colour cast of its own (bluish under the Cleek pile), which must not count as pile.
    tint = rgb - rgb.mean(axis=2, keepdims=True)
    colored = (rgb.max(axis=2) - rgb.min(axis=2) > 60) & (alpha > 0)
    hue = np.median(tint[colored], axis=0)
    hue /= np.linalg.norm(hue)
    in_hue = (tint @ hue > 12) & (rgb.max(axis=2) > 38)
    # Glints inside the pile are pale but surrounded by pile; the backing is a large area with none of that hue.
    closed = erode(dilate(in_hue, 7), 7)
    neutral = (alpha > 0) & ~closed
    backing = largest_component(neutral) if neutral.sum() > 2000 else np.zeros_like(neutral)
    mask = (alpha > 0) & ~backing
    # The closing above is blocky: right next to the backing, trust each pixel's own colour so the seam stays neutral.
    # Strong colours bleed onto the pale seam through JPEG chroma, hence saturation as well: a shaded fibre has plenty.
    near_backing = dilate(backing, 15)
    saturation = (rgb.max(axis=2) - rgb.min(axis=2)) / np.maximum(rgb.max(axis=2), 1)
    mask = np.where(near_backing, in_hue & (saturation > 0.45) & (alpha > 0), mask)
    # Deep inside the backing any tint is noise, not pile.
    mask = mask & ~erode(backing, 9)
    return blur(mask.astype(float), 0.7), backing


def hex_rgb(value):
    return np.array([int(value[i:i + 2], 16) for i in (1, 3, 5)], dtype=float)


def whiteness_of(fg):
    return np.clip(fg.min(axis=2) / np.maximum(fg.max(axis=2), 1), 0, 1)


def recolor(fg, mask, alpha, target, lift, shade, ref_white, glint_scale=1.0):
    """Moves the pile to `target`, keeping the fibre shading and sheen (how white each fibre is)."""
    # Sheen counts from the pile's own base: a muted photo (Cleek's brick red) isn't all glint, or every colour washes out.
    whiteness = np.clip((whiteness_of(fg) - ref_white) / (1 - ref_white), 0, 1)
    # Rim pixels are partly background, so their brightness is not sheen: damp it or the cut-out gets a pale outline.
    inner = blur(erode(alpha >= 1, 5).astype(float), 1.5)
    whiteness = whiteness * inner
    shade = np.minimum(shade, 1 + (shade - 1) * inner)
    t = target / 255
    # Darker than the pile's middle tone: scale towards black. Lighter: roll off towards white so nothing clips flat.
    dark = t * np.minimum(shade, 1)[..., None] ** 1.1
    light = t + (1 - t) * (np.maximum(shade - 1, 0)[..., None] * lift * 0.5)
    out = np.where(shade[..., None] < 1, dark, light)
    glint = (whiteness[..., None] ** 1.4) * lift * glint_scale
    out = out + (np.minimum(t + 0.45, 1) * np.maximum(shade, 0.35)[..., None] - out) * glint
    out = np.clip(out, 0, 1) * 255
    neutral = fg.mean(axis=2, keepdims=True).repeat(3, axis=2)  # backing and fringe: none of the photo's hue left behind
    m = mask[..., None]
    return out * m + neutral * (1 - m)


def save(rgb, alpha, box, path):
    data = np.dstack([np.clip(rgb, 0, 255), np.clip(alpha, 0, 1) * 255]).astype(np.uint8)
    Image.fromarray(data, 'RGBA').crop(box).save(path, 'WEBP', quality=WEBP_QUALITY, alpha_quality=90, method=6, exact=False)


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--src', type=Path, help='folder that already holds the original JPGs')
    parser.add_argument('--debug', type=Path, help='also write the alpha and pile masks here')
    parser.add_argument('--products', nargs='*', help='only rebuild these product slugs')
    args = parser.parse_args()
    if not args.products:
        cutouts(args)
    for product, config in PRODUCTS.items():
        if args.products and product not in args.products:
            continue
        out = OUT / product
        out.mkdir(parents=True, exist_ok=True)
        colors = dict(COLORS)
        if 'own' in config:
            own_id, own_hex, own_lift = config['own']
            colors[own_id] = (own_hex, own_lift)

        for view, name in config['views'].items():
            if args.src:
                path = args.src / name
            else:
                path = OUT / name
                urllib.request.urlretrieve(UPLOADS + name, path)
            rgb = np.asarray(Image.open(path).convert('RGB')).astype(float)
            if not args.src:
                path.unlink()

            alpha, fg = cut_out(rgb)
            mask, backing = pile_mask(rgb, alpha)
            ys, xs = np.nonzero(alpha > 0.04)
            pad = 4
            box = (max(xs.min() - pad, 0), max(ys.min() - pad, 0), min(xs.max() + pad + 1, rgb.shape[1]), min(ys.max() + pad + 1, rgb.shape[0]))
            core = (mask > 0.9) & (alpha > 0.9)
            ref_v = np.median(fg.max(axis=2)[core]) / 255
            texture = config.get('texture')
            if texture:
                # A saturated single-hue photo (ADLX4T's scarlet) pegs its max channel on nearly every lit fibre, so
                # value-based shading comes out flat and neon. Luminance still carries the relief: shade from luma
                # instead, raised to `texture` for contrast.
                lum = fg @ np.array([0.299, 0.587, 0.114])
                s = np.clip(lum / max(float(np.median(lum[core])), 1.0), 0.05, 2.0)
                # Contrast goes into the shadows only; pushed highlights would snow the pile with white specks.
                shade = np.where(s < 1, s ** texture, 1 + (s - 1) * 0.5)
            else:
                shade = fg.max(axis=2) / 255 / ref_v
            ref_white = np.median(whiteness_of(fg)[core])
            pile_color = np.median(fg[core], axis=0).astype(int)
            print(product, view, 'size', int(box[2] - box[0]), int(box[3] - box[1]), 'pile', '#%02x%02x%02x' % tuple(pile_color), 'value', round(float(ref_v), 3), 'backing px', int(backing.sum()))

            if args.debug:
                args.debug.mkdir(parents=True, exist_ok=True)
                to_image(mask).save(args.debug / f'{product}-{view}-pile.png')

            for color, (hex_value, lift) in colors.items():
                save(recolor(fg, mask, alpha, hex_rgb(hex_value), lift, shade, ref_white, config.get('glint', 1.0)), alpha, box, out / f'{view}-{color}.webp')


def cutouts(args):
    for rel, url in CUTOUTS.items():
        name = url.rsplit('/', 1)[1]
        path = (args.src / name) if args.src else OUT / name
        if not args.src:
            urllib.request.urlretrieve(url, path)
        im = np.asarray(Image.open(path).convert('RGBA')).astype(float)
        if not args.src:
            path.unlink()
        alpha = im[..., 3] / 255
        keep = dilate(largest_component(alpha > 0.1), 51)
        alpha = np.where(keep, alpha, 0)
        ys, xs = np.nonzero(alpha > 0.02)
        box = (max(int(xs.min()) - 4, 0), max(int(ys.min()) - 4, 0), min(int(xs.max()) + 5, im.shape[1]), min(int(ys.max()) + 5, im.shape[0]))
        dest = OUT / f'{rel}.webp'
        dest.parent.mkdir(parents=True, exist_ok=True)
        save(im[..., :3], alpha, box, dest)
        print('cutout', rel, 'size', int(box[2] - box[0]), int(box[3] - box[1]))


if __name__ == '__main__':
    main()
