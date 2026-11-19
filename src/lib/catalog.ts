// Catalogue flipbook behaviour (markup: src/components/CatalogViewer.astro). Loaded on demand by that component.
// Page turning is page-flip (StPageFlip) in HTML mode; this file owns sizing, the controls, zoom and deep links.
//
// The catalogue must not be downloadable. No page exists as a readable file: scripts/build-catalog-pages.py stores
// every leaf as shuffled tiles, and only this module puts them back, on a <canvas> (nothing to "save image as").
// Keep fnv()/shuffle()/fileName() in step with that script.
import { PageFlip } from 'page-flip/dist/js/page-flip.module.js';
import PACK from '../data/catalog-pack.json';

const ZOOM_STEPS = [1, 1.75, 2.5, 3.5];
type Tier = 'view' | 'zoom';
const BASE = `${import.meta.env.CATALOG_LOCAL ? '' : (import.meta.env.PUBLIC_MEDIA_ORIGIN ?? '')}/wp-content/uploads/upx-catalog/${PACK.dir}/`;

/** FNV-1a, 32 bit (ASCII input). */
function fnv(text: string): number {
  let h = 0x811c9dc5;
  for (let i = 0; i < text.length; i++) h = Math.imul(h ^ text.charCodeAt(i), 0x01000193) >>> 0;
  return h;
}
/** Fisher-Yates over 0..n-1 driven by mulberry32(seed): order[slot] = tile stored in that slot. */
function shuffle(n: number, seed: number): number[] {
  let a = seed | 0;
  const rand = () => {
    a = (a + 0x6d2b79f5) | 0;
    let t = a;
    t = Math.imul(t ^ (t >>> 15), t | 1);
    t ^= t + Math.imul(t ^ (t >>> 7), t | 61);
    return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
  };
  const order = Array.from({ length: n }, (_, i) => i);
  for (let i = n - 1; i > 0; i--) {
    const j = Math.floor(rand() * (i + 1));
    [order[i], order[j]] = [order[j], order[i]];
  }
  return order;
}
const hex = (n: number) => n.toString(16).padStart(8, '0');
const fileName = (page: number, tier: Tier) => `${hex(fnv(`${PACK.salt}:a:${page}:${tier}`))}${hex(fnv(`${PACK.salt}:b:${page}:${tier}`))}.webp`;

/** Loads a stored (shuffled) file and returns the leaf put back together at its native size. */
async function assemble(page: number, tier: Tier): Promise<HTMLCanvasElement> {
  const v = PACK.variants[tier];
  const img = new Image();
  // load, not decode(): decode() waits for the tab to render, so in a background tab the pages would never appear
  await new Promise<void>((done, fail) => {
    img.onload = () => done();
    img.onerror = () => fail(new Error('page file not loaded'));
    img.src = BASE + fileName(page, tier);
  });
  // decoded off the main thread where the browser can; the element itself works everywhere
  const pixels: CanvasImageSource & { close?: () => void } = 'createImageBitmap' in window ? await createImageBitmap(img).catch(() => img) : img;
  const tw = Math.ceil(v.width / v.cols);
  const th = Math.ceil(v.height / v.rows);
  const g = PACK.gutter;
  const out = document.createElement('canvas');
  out.width = v.width;
  out.height = v.height;
  const ctx = out.getContext('2d')!;
  const order = shuffle(v.cols * v.rows, fnv(`${PACK.salt}:${page}:${tier}`));
  order.forEach((tile, slot) => {
    const sx = (slot % v.cols) * (tw + 2 * g) + g;
    const sy = Math.floor(slot / v.cols) * (th + 2 * g) + g;
    ctx.drawImage(pixels, sx, sy, tw, th, (tile % v.cols) * tw, Math.floor(tile / v.cols) * th, tw, th);
  });
  pixels.close?.();
  img.onload = img.onerror = null;
  img.src = '';
  return out;
}
/** Header + control bar + paddings: what the book cannot use of the viewport height. Mirrors --cat-h in the CSS. */
const CHROME = 220;
const FLIP_MS = 850;
const SLIDE_MS = 480;
const clamp = (v: number, lo: number, hi: number) => Math.min(hi, Math.max(lo, v));

export function initCatalog(root: HTMLElement): void {
  const q = <T extends HTMLElement>(sel: string) => root.querySelector<T>(sel)!;
  const stage = q('[data-stage]');
  const sizer = q('[data-sizer]');
  const mover = q('[data-mover]');
  const book = q('[data-book]');
  const pan = q('[data-pan]');
  const bar = q('[data-bar]');
  const count = q('[data-count]');
  const progress = q('[data-progress]');
  const zoomLabel = q('[data-zoom-label]');
  const fsBtn = root.querySelector<HTMLButtonElement>('[data-fullscreen]');
  const prevBtns = [...root.querySelectorAll<HTMLButtonElement>('[data-prev]')];
  const nextBtns = [...root.querySelectorAll<HTMLButtonElement>('[data-next]')];
  const zoomIn = q<HTMLButtonElement>('[data-zoom-in]');
  const zoomOut = q<HTMLButtonElement>('[data-zoom-out]');
  const pages = [...book.querySelectorAll<HTMLElement>('[data-page]')];
  const canvases = pages.map((p) => p.querySelector('canvas')!);
  /** What each canvas holds now, and the request in flight (a newer one wins). */
  const drawn: ({ tier: Tier; width: number } | null)[] = pages.map(() => null);
  const asked: string[] = pages.map(() => '');
  const total = pages.length;
  const pageW = PACK.variants.view.width;
  const pageH = PACK.variants.view.height;
  let zooms = ZOOM_STEPS.slice(0, 3);
  const ratio = pageW / pageH;
  const calm = matchMedia('(prefers-reduced-motion: reduce)').matches;

  let portrait = false;
  let index = 0;
  let zoom = 0; // index into zooms
  let tx = 0;
  let ty = 0;
  let bookW = 0; // full 2-page spread width at 100 %
  let viewW = 0; // visible frame width at 100 % (bookW / 2 in portrait, bookW in landscape)

  const wanted = Number(new URLSearchParams(location.search).get('p'));
  const start = Number.isInteger(wanted) && wanted >= 1 && wanted <= total ? wanted - 1 : 0;
  /** Active leaf of the current spread in portrait view: 0 = left A4 page, 1 = right A4 page. */
  let side: 0 | 1 = (start % 2) as 0 | 1;

  // The catalogue is always laid out as a two-page open spread (left + right A4 leaf). On narrow/phone viewports,
  // .cat__clip frames one A4 page at a time: navigating from left -> right slides steadily across the spread,
  // and navigating from right -> next left flips the page while panning across the spine.
  const flip = new PageFlip(book, {
    width: pageW,
    height: pageH,
    size: 'stretch',
    minWidth: 100000,
    maxWidth: 100000,
    minHeight: 100,
    maxHeight: 100000,
    startPage: start - (start % 2),
    flippingTime: calm ? 1 : FLIP_MS,
    maxShadowOpacity: 0.45,
    showCover: false,
    usePortrait: false,
    mobileScrollSupport: true,
    showPageCorners: !calm,
    swipeDistance: 30,
  });

  /** Positions the 2-page spread inside the 1-page portrait frame (no-op in landscape). */
  function frameOffset(animate: 'none' | 'slide' | 'flip' = 'none') {
    if (!portrait) {
      mover.style.transition = '';
      mover.style.transform = '';
      return;
    }
    const x = side === 1 ? -viewW : 0;
    if (animate === 'none' || calm) mover.style.transition = 'none';
    else if (animate === 'slide') mover.style.transition = `transform ${SLIDE_MS}ms cubic-bezier(0.4, 0, 0.2, 1)`;
    else mover.style.transition = `transform ${FLIP_MS}ms cubic-bezier(0.37, 0, 0.63, 1)`;
    mover.style.transform = `translate3d(${x}px, 0, 0)`;
  }

  /** Two-up when the stage is wide enough for a spread at a readable size, else one A4 leaf framed at a time. */
  let seen = '';
  function layout(force = false) {
    const full = document.fullscreenElement === root;
    // Only a real change of the space counts: zooming itself must not look like a resize and reset the zoom.
    const key = `${stage.clientWidth}|${window.innerHeight}|${full}`;
    if (key === seen && !force) return;
    seen = key;
    const availH = full ? root.clientHeight - bar.offsetHeight - 48 : clamp(window.innerHeight - CHROME, 280, 1100);
    const sidePad = stage.clientWidth >= 900 ? 72 : 0; // room for the side arrows
    const availW = stage.clientWidth - sidePad * 2;
    portrait = stage.clientWidth < 720 || availW < availH * ratio * 1.35;
    if (portrait) {
      viewW = Math.floor(Math.min(availW, availH * ratio));
      bookW = viewW * 2;
    } else {
      bookW = Math.floor(Math.min(availW, availH * ratio * 2));
      viewW = bookW;
    }
    // Zoom stops where one pixel of the zoom file would cover more than one screen pixel: beyond that it could only blur.
    const sharpest = PACK.variants.zoom.width / ((bookW / 2) * devicePixelRatio);
    zooms = ZOOM_STEPS.filter((z, i) => i < 2 || z <= sharpest);
    root.classList.toggle('is-portrait', portrait);
    // A resize while zoomed starts over at 100 %: the stage's frozen height belongs to the old size.
    zoom = 0;
    tx = ty = 0;
    applyZoom();
    sync();
  }

  /** Pages shown right now (0-based, inclusive). */
  function visible(): [number, number] {
    const left = index - (index % 2);
    if (portrait) {
      const cur = Math.min(left + side, total - 1);
      return [cur, cur];
    }
    return [left, Math.min(left + 1, total - 1)];
  }

  /** Draws leaf i at the resolution the screen needs right now: the reading file, or the zoom file once that is too small. */
  async function paint(i: number) {
    const cssW = (bookW / 2) * zooms[zoom];
    const need = Math.round(cssW * devicePixelRatio);
    const tier: Tier = need > PACK.variants.view.width * 1.1 ? 'zoom' : 'view';
    const v = PACK.variants[tier];
    const width = tier === 'view' ? v.width : Math.min(v.width, need);
    const now = drawn[i];
    if (now && now.tier === tier && Math.abs(now.width - width) < 8) return;
    const ticket = `${tier}:${width}`;
    if (asked[i] === ticket) return;
    asked[i] = ticket;
    try {
      const full = await assemble(i + 1, tier);
      if (asked[i] !== ticket) return; // a newer request (zoom changed, leaf released) took over
      const canvas = canvases[i];
      canvas.width = width;
      canvas.height = Math.round((width * v.height) / v.width);
      const ctx = canvas.getContext('2d')!;
      ctx.imageSmoothingQuality = 'high';
      ctx.drawImage(full, 0, 0, canvas.width, canvas.height);
      full.width = full.height = 0;
      drawn[i] = { tier, width };
      pages[i].classList.add('is-drawn');
    } catch {
      /* offline or the file is missing: the leaf stays blank and the next paint() tries again */
    } finally {
      if (asked[i] === ticket) asked[i] = '';
    }
  }
  /** Keeps the spreads around the open one drawn (a flip shows a hidden leaf at once) and frees the rest. */
  function warm() {
    const left = index - (index % 2);
    const spreadEnd = Math.min(left + 1, total - 1);
    const keep = 4;
    for (let i = 0; i < total; i++) {
      if (i >= left - keep && i <= spreadEnd + keep) continue;
      if (!drawn[i] && !asked[i]) continue;
      canvases[i].width = canvases[i].height = 0;
      drawn[i] = null;
      asked[i] = '';
      pages[i].classList.remove('is-drawn');
    }
    for (let i = left; i <= spreadEnd; i++) void paint(i);
    // neighbours only at reading size: pages cannot turn while zoomed
    if (zoom === 0) for (let d = 1; d <= keep; d++) for (const i of [spreadEnd + d, left - d]) if (i >= 0 && i < total) void paint(i);
  }

  function sync() {
    index = flip.getCurrentPageIndex();
    const [a, b] = visible();
    count.textContent = a === b ? `${a + 1} / ${total}` : `${a + 1}–${b + 1} / ${total}`;
    progress.style.inlineSize = `${((b + 1) / total) * 100}%`;
    for (const btn of prevBtns) btn.disabled = a === 0 || zoom > 0;
    for (const btn of nextBtns) btn.disabled = b === total - 1 || zoom > 0;
    zoomOut.disabled = zoom === 0;
    zoomIn.disabled = zoom === zooms.length - 1;
    zoomLabel.textContent = `${Math.round(zooms[zoom] * 100)}%`;
    warm();
    const url = new URL(location.href);
    if (a === 0) url.searchParams.delete('p');
    else url.searchParams.set('p', String(a + 1));
    if (url.href !== location.href) history.replaceState(history.state, '', url);
  }

  function go(dir: 1 | -1) {
    if (zoom > 0 || flip.getState() === 'flipping') return;
    if (portrait) {
      const left = flip.getCurrentPageIndex();
      if (dir > 0) {
        if (side === 0) {
          if (left + 1 >= total) return;
          side = 1;
          frameOffset('slide');
          sync();
        } else {
          if (left + 2 >= total) return;
          side = 0;
          if (calm) {
            frameOffset('none');
            flip.turnToNextPage();
          } else {
            frameOffset('flip');
            flip.flipNext();
          }
        }
      } else {
        if (side === 1) {
          side = 0;
          frameOffset('slide');
          sync();
        } else {
          if (left < 2) return;
          side = 1;
          if (calm) {
            frameOffset('none');
            flip.turnToPrevPage();
          } else {
            frameOffset('flip');
            flip.flipPrev();
          }
        }
      }
      return;
    }
    if (calm) dir > 0 ? flip.turnToNextPage() : flip.turnToPrevPage();
    else dir > 0 ? flip.flipNext() : flip.flipPrev();
  }

  // ---- zoom: the book is really laid out larger inside the clipped stage (a CSS scale would only stretch the
  // pixels already painted, so text went soft); a layer on top takes the drag so pages don't turn.
  function place() {
    const maxX = Math.max(0, (sizer.offsetWidth - stage.clientWidth) / 2);
    const maxY = Math.max(0, (sizer.offsetHeight - stage.clientHeight) / 2);
    tx = clamp(tx, -maxX, maxX);
    ty = clamp(ty, -maxY, maxY);
    mover.style.transition = 'none';
    mover.style.transform = tx || ty ? `translate3d(${Math.round(tx)}px, ${Math.round(ty)}px, 0)` : '';
  }
  function applyZoom() {
    if (zoom === 0) stage.style.blockSize = '';
    else if (!stage.style.blockSize) stage.style.blockSize = `${stage.getBoundingClientRect().height}px`; // keep the page from jumping
    const scaledBookW = Math.round(bookW * zooms[zoom]);
    if (portrait && zoom === 0) {
      sizer.style.inlineSize = `${viewW}px`;
      mover.style.inlineSize = `${bookW}px`;
    } else {
      sizer.style.inlineSize = `${scaledBookW}px`;
      mover.style.inlineSize = '';
    }
    flip.update();
    root.classList.toggle('is-zoomed', zoom > 0);
    pan.hidden = zoom === 0;
    if (zoom === 0) frameOffset('none');
    else place();
  }
  function setZoom(next: number) {
    next = clamp(next, 0, zooms.length - 1);
    if (next === zoom) return;
    if (portrait && zoom === 0 && next > 0) {
      // centre the active A4 leaf (left or right) when zooming in from single-page portrait view
      tx = (side === 0 ? 1 : -1) * ((bookW * zooms[next]) / 4);
      ty = 0;
    } else {
      // keep the point at the centre of the stage where it is
      const k = zooms[next] / zooms[zoom];
      tx *= k;
      ty *= k;
    }
    zoom = next;
    if (zoom === 0) tx = ty = 0;
    applyZoom();
    sync();
  }
  let drag: { x: number; y: number; tx: number; ty: number } | null = null;
  pan.addEventListener('pointerdown', (e) => {
    drag = { x: e.clientX, y: e.clientY, tx, ty };
    pan.setPointerCapture(e.pointerId);
    pan.classList.add('is-dragging');
  });
  pan.addEventListener('pointermove', (e) => {
    if (!drag) return;
    tx = drag.tx + e.clientX - drag.x;
    ty = drag.ty + e.clientY - drag.y;
    place();
  });
  const drop = () => {
    drag = null;
    pan.classList.remove('is-dragging');
  };
  pan.addEventListener('pointerup', drop);
  pan.addEventListener('pointercancel', drop);
  pan.addEventListener('dblclick', () => setZoom(0));
  stage.addEventListener(
    'wheel',
    (e) => {
      if (!e.ctrlKey && !e.metaKey) return; // plain wheel keeps scrolling the page
      e.preventDefault();
      setZoom(zoom + (e.deltaY < 0 ? 1 : -1));
    },
    { passive: false },
  );

  // ---- controls
  for (const btn of prevBtns) btn.addEventListener('click', () => go(-1));
  for (const btn of nextBtns) btn.addEventListener('click', () => go(1));
  zoomIn.addEventListener('click', () => setZoom(zoom + 1));
  zoomOut.addEventListener('click', () => setZoom(zoom - 1));

  if (fsBtn) {
    if (!document.fullscreenEnabled || !root.requestFullscreen) fsBtn.hidden = true;
    fsBtn.addEventListener('click', () => {
      if (document.fullscreenElement) void document.exitFullscreen();
      else void root.requestFullscreen().catch(() => {});
    });
    document.addEventListener('fullscreenchange', () => {
      const on = document.fullscreenElement === root;
      fsBtn.setAttribute('aria-pressed', String(on));
      fsBtn.setAttribute('aria-label', on ? fsBtn.dataset.labelOff! : fsBtn.dataset.labelOn!);
      fsBtn.title = fsBtn.getAttribute('aria-label')!;
      layout();
    });
  }

  document.addEventListener('keydown', (e) => {
    if (e.altKey || e.ctrlKey || e.metaKey) return;
    const el = e.target;
    if ((el instanceof Element && el.closest('input, textarea, select, [contenteditable="true"]')) || document.querySelector('dialog[open]')) return;
    const box = root.getBoundingClientRect();
    if (box.bottom < 80 || box.top > window.innerHeight - 80) return; // viewer scrolled away
    const act: Record<string, () => void> = {
      ArrowRight: () => go(1),
      ArrowLeft: () => go(-1),
      PageDown: () => go(1),
      PageUp: () => go(-1),
      Home: () => {
        if (zoom !== 0 || flip.getState() === 'flipping') return;
        side = 0;
        frameOffset('none');
        flip.turnToPage(0);
        sync();
      },
      End: () => {
        if (zoom !== 0 || flip.getState() === 'flipping') return;
        side = ((total - 1) % 2) as 0 | 1;
        frameOffset('none');
        flip.turnToPage(total - 1);
        sync();
      },
      '+': () => setZoom(zoom + 1),
      '=': () => setZoom(zoom + 1),
      '-': () => setZoom(zoom - 1),
      Escape: () => setZoom(0),
      f: () => fsBtn && !fsBtn.hidden && fsBtn.click(),
    };
    const run = act[e.key];
    // Space/Enter on a focused control must keep working; PageUp/Down/Home/End stay with the page unless the viewer has focus.
    if (!run || (/^(Page|Home|End)/.test(e.key) && !root.contains(document.activeElement))) return;
    if (e.key === 'Escape' && zoom === 0) return;
    e.preventDefault();
    run();
  });

  // ---- protection: nothing in the viewer can be saved, dragged out, selected or printed (see also the print CSS)
  for (const type of ['contextmenu', 'dragstart', 'selectstart', 'copy']) root.addEventListener(type, (e) => e.preventDefault());

  flip.on('flip', sync);
  flip.on('changeOrientation', sync);
  flip.loadFromHTML(pages);
  // A transparent sheet over the leaves: a right click (or long press) lands on it, never on a canvas. It sits inside
  // the element page-flip listens on, so turning pages by dragging still works.
  const block = book.querySelector<HTMLElement>('.stf__block');
  if (block) {
    const shield = document.createElement('div');
    shield.className = 'cat__shield';
    block.append(shield);
    // In portrait view, navigation alternates between sliding across the spread and flipping to the next spread;
    // handle horizontal swipes here and keep page-flip's direct tap/drag from flipping the wrong leaf.
    let swipe: { x: number; y: number } | null = null;
    shield.addEventListener('mousedown', (e) => {
      if (portrait && zoom === 0) e.stopPropagation();
    });
    shield.addEventListener(
      'touchstart',
      (e) => {
        if (!portrait || zoom > 0 || e.touches.length !== 1) return;
        e.stopPropagation();
        swipe = { x: e.touches[0].clientX, y: e.touches[0].clientY };
      },
      { passive: true },
    );
    shield.addEventListener(
      'touchend',
      (e) => {
        if (!swipe || !portrait || zoom > 0 || !e.changedTouches.length) {
          swipe = null;
          return;
        }
        e.stopPropagation();
        const dx = e.changedTouches[0].clientX - swipe.x;
        const dy = e.changedTouches[0].clientY - swipe.y;
        swipe = null;
        if (Math.abs(dx) >= 35 && Math.abs(dx) > Math.abs(dy) * 1.2) go(dx < 0 ? 1 : -1);
      },
      { passive: true },
    );
    shield.addEventListener('touchcancel', () => {
      swipe = null;
    });
  }
  root.classList.add('is-ready');
  for (const el of root.querySelectorAll<HTMLButtonElement>('[data-bar] button')) el.disabled = false;
  new ResizeObserver(() => layout()).observe(stage);
  window.addEventListener('resize', () => layout());
  layout(true);
}
