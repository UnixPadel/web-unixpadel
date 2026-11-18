// Catalogue flipbook behaviour (markup: src/components/CatalogViewer.astro). Loaded on demand by that component.
// Page turning is page-flip (StPageFlip) in HTML mode; this file owns sizing, the controls, zoom and deep links.
import { PageFlip } from 'page-flip/dist/js/page-flip.module.js';

const ZOOMS = [1, 1.75, 2.5];
/** Header + control bar + paddings: what the book cannot use of the viewport height. Mirrors --cat-h in the CSS. */
const CHROME = 220;
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
  const imgs = pages.map((p) => p.querySelector('img')!);
  const total = pages.length;
  const pageW = Number(root.dataset.w);
  const pageH = Number(root.dataset.h);
  const ratio = pageW / pageH;
  const calm = matchMedia('(prefers-reduced-motion: reduce)').matches;

  let portrait = false;
  let index = 0;
  let zoom = 0; // index into ZOOMS
  let tx = 0;
  let ty = 0;

  const wanted = Number(new URLSearchParams(location.search).get('p'));
  const start = Number.isInteger(wanted) && wanted >= 1 && wanted <= total ? wanted - 1 : 0;

  // The library decides portrait/landscape from `minWidth`. An unreachable minimum hands that decision to
  // `usePortrait`, which layout() sets from the space the stage really has (its inline min sizes are reset in CSS).
  const flip = new PageFlip(book, {
    width: pageW,
    height: pageH,
    size: 'stretch',
    minWidth: 100000,
    maxWidth: 100000,
    minHeight: 100,
    maxHeight: 100000,
    startPage: start,
    flippingTime: calm ? 1 : 850,
    maxShadowOpacity: 0.45,
    showCover: false,
    usePortrait: false,
    mobileScrollSupport: true,
    showPageCorners: !calm,
    swipeDistance: 30,
  });

  /** Two-up when the stage is wide enough for a spread at a readable size, else one page at a time. */
  function layout() {
    const full = document.fullscreenElement === root;
    const availH = full ? root.clientHeight - bar.offsetHeight - 48 : clamp(window.innerHeight - CHROME, 280, 1100);
    const side = stage.clientWidth >= 900 ? 72 : 0; // room for the side arrows
    const availW = stage.clientWidth - side * 2;
    portrait = stage.clientWidth < 720 || availW < availH * ratio * 1.35;
    const w = Math.min(availW, availH * ratio * (portrait ? 1 : 2));
    sizer.style.inlineSize = `${Math.floor(w)}px`;
    root.classList.toggle('is-portrait', portrait);
    flip.getSettings().usePortrait = portrait;
    flip.update();
    applyZoom();
    sync();
  }

  /** Pages shown right now (0-based, inclusive). */
  function visible(): [number, number] {
    if (portrait) return [index, index];
    const left = index - (index % 2);
    return [left, Math.min(left + 1, total - 1)];
  }

  /** Hidden leaves are display:none, so lazy images would only start loading mid-flip: warm the neighbours. */
  function warm() {
    const [a, b] = visible();
    for (let i = Math.max(0, a - 2); i <= Math.min(total - 1, b + 2); i++) imgs[i].loading = 'eager';
    if (zoom > 0) for (let i = a; i <= b; i++) if (imgs[i].dataset.zoom && imgs[i].src !== imgs[i].dataset.zoom) imgs[i].src = imgs[i].dataset.zoom!;
  }

  function sync() {
    index = flip.getCurrentPageIndex();
    const [a, b] = visible();
    count.textContent = a === b ? `${a + 1} / ${total}` : `${a + 1}–${b + 1} / ${total}`;
    progress.style.inlineSize = `${((b + 1) / total) * 100}%`;
    for (const btn of prevBtns) btn.disabled = a === 0 || zoom > 0;
    for (const btn of nextBtns) btn.disabled = b === total - 1 || zoom > 0;
    zoomOut.disabled = zoom === 0;
    zoomIn.disabled = zoom === ZOOMS.length - 1;
    zoomLabel.textContent = `${Math.round(ZOOMS[zoom] * 100)}%`;
    warm();
    const url = new URL(location.href);
    if (a === 0) url.searchParams.delete('p');
    else url.searchParams.set('p', String(a + 1));
    if (url.href !== location.href) history.replaceState(history.state, '', url);
  }

  function go(dir: 1 | -1) {
    if (zoom > 0) return;
    if (calm) dir > 0 ? flip.turnToNextPage() : flip.turnToPrevPage();
    else dir > 0 ? flip.flipNext() : flip.flipPrev();
  }

  // ---- zoom: scale the book inside the clipped stage; a layer on top takes the drag so pages don't turn.
  function applyZoom() {
    const s = ZOOMS[zoom];
    const maxX = Math.max(0, (sizer.offsetWidth * s - stage.clientWidth) / 2);
    const maxY = Math.max(0, (sizer.offsetHeight * s - stage.clientHeight) / 2);
    tx = clamp(tx, -maxX, maxX);
    ty = clamp(ty, -maxY, maxY);
    mover.style.transform = s === 1 ? '' : `translate(${tx}px, ${ty}px) scale(${s})`;
    root.classList.toggle('is-zoomed', zoom > 0);
    pan.hidden = zoom === 0;
  }
  function setZoom(next: number) {
    next = clamp(next, 0, ZOOMS.length - 1);
    if (next === zoom) return;
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
    applyZoom();
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
      Home: () => zoom === 0 && flip.turnToPage(0),
      End: () => zoom === 0 && flip.turnToPage(total - 1),
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

  flip.on('flip', sync);
  flip.on('changeOrientation', sync);
  flip.loadFromHTML(pages);
  root.classList.add('is-ready');
  for (const el of root.querySelectorAll<HTMLButtonElement>('[data-bar] button')) el.disabled = false;
  new ResizeObserver(layout).observe(stage);
  window.addEventListener('resize', layout);
  layout();
}
