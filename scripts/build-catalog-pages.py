#!/usr/bin/env python3
"""Cuts the product catalogue PDF into the flipbook's page images (src/components/CatalogViewer.astro).

The PDF holds landscape A3 spreads. Each spread is rendered once and cut at the fold into two A4 leaves, so the two
halves meet without a seam. Every leaf is written twice: a reading size and a zoom size.

The output folder (catalog-export/, git-ignored) is NOT deployed: upload its files to the WordPress media library and
point `mediaBase` in src/data/catalog.ts at the upload month. `astro dev` serves the folder from that same path, so the
viewer can be worked on before the upload.

Usage: python scripts/build-catalog-pages.py [PDF] [--prefix unixpadel-catalog-2027] [--no-pdf]
Needs PyMuPDF and Pillow.
"""
import argparse
import sys
from pathlib import Path

import pymupdf
from PIL import Image

ROOT = Path(__file__).resolve().parent.parent
OUT = ROOT / 'catalog-export'
ZOOM_H = 2400  # below WordPress' 2560 px "-scaled" threshold, so the upload keeps its name
VIEW_H = 1414


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('pdf', nargs='?', help='catalogue PDF (default: the only *.pdf in the repo root)')
    ap.add_argument('--prefix', default='unixpadel-catalog-2027')
    ap.add_argument('--no-pdf', action='store_true', help='skip the reduced download PDF')
    args = ap.parse_args()

    src = Path(args.pdf) if args.pdf else None
    if src is None:
        found = sorted(ROOT.glob('*.pdf'))
        if len(found) != 1:
            sys.exit(f'Pass the PDF path: found {len(found)} PDFs in {ROOT}')
        src = found[0]

    OUT.mkdir(exist_ok=True)
    for old in OUT.glob(f'{args.prefix}-p*.webp'):
        old.unlink()

    doc = pymupdf.open(src)
    n = 0
    total = 0
    for spread in doc:
        if spread.rect.width <= spread.rect.height:
            sys.exit(f'Page {spread.number + 1} is not a landscape spread ({spread.rect.width:.0f}x{spread.rect.height:.0f} pt)')
        scale = ZOOM_H / spread.rect.height
        pix = spread.get_pixmap(matrix=pymupdf.Matrix(scale, scale), alpha=False)
        img = Image.frombytes('RGB', (pix.width, pix.height), pix.samples)
        half = img.width // 2
        # An odd pixel column at the fold would be dropped: take both halves from the centre outwards.
        for box in ((img.width // 2 - half, 0, img.width // 2, img.height), (img.width - half, 0, img.width, img.height)):
            n += 1
            leaf = img.crop(box)
            view = leaf.resize((round(leaf.width * VIEW_H / leaf.height), VIEW_H), Image.LANCZOS)
            for im, suffix, q in ((view, '', 80), (leaf, '-zoom', 78)):
                path = OUT / f'{args.prefix}-p{n:02d}{suffix}.webp'
                im.save(path, 'WEBP', quality=q, method=6)
                total += path.stat().st_size
            print(f'p{n:02d}  view {view.width}x{view.height}  zoom {leaf.width}x{leaf.height}')
    print(f'{n} pages, {n * 2} files, {total / 1048576:.1f} MB -> {OUT}')

    if not args.no_pdf:
        # Smaller copy for the "Download PDF" button: print-resolution images go down to screen resolution.
        out_pdf = OUT / f'{args.prefix}.pdf'
        try:
            doc.rewrite_images(dpi_threshold=170, dpi_target=150, quality=82)
        except Exception as e:  # older PyMuPDF: keep the images, still clean and compress the file
            print(f'(images not resampled: {e})')
        doc.save(out_pdf, garbage=4, deflate=True, clean=True)
        print(f'{out_pdf.name}: {out_pdf.stat().st_size / 1048576:.1f} MB (was {src.stat().st_size / 1048576:.1f} MB)')


if __name__ == '__main__':
    main()
