// Project globe on the /references/ hub (ProjectGlobe.astro): an orthographic d3-geo projection drawn on a canvas.
// No WebGL and no textures: ocean disc, land outline (world-atlas 110m), faint graticule and lime pin spikes.
// Pins are not tabbable; the country <select> and the zoom buttons are the keyboard (and non-drag) alternative.
import { geoDistance, geoGraticule10, geoInterpolate, geoOrthographic, geoPath } from 'd3-geo';
import { feature } from 'topojson-client';
import type { GeometryCollection, Topology } from 'topojson-specification';
import land110 from 'world-atlas/land-110m.json';

/** Inlined by referenceMap.ts: products, countries, city groups. */
type Data = {
  v: 1;
  p: [label: string, href: string][];
  c: [label: string, slug: string | null, showLabel: string | null, biggest: number][];
  g: [lng: number, lat: number, country: number, place: string | null, n: number, countText: string, mix: number[]][];
};
type Colors = { ocean: string; land: string; edge: string; grid: string; rim: string; pin: string; pick: string; halo: string };

const TOPO = land110 as unknown as Topology<{ land: GeometryCollection }>;
const LAND = feature(TOPO, TOPO.objects.land);
const GRID = geoGraticule10();
const SPHERE = { type: 'Sphere' } as const;
const DEG = Math.PI / 180;
const ZOOMS = [1, 1.8, 3.2];
const SPIN = 4; // degrees per second
const NEARBY = 7 * DEG;

export function initGlobe(root: HTMLElement) {
  const data: Data = JSON.parse(root.querySelector('[data-globe-data]')!.textContent!);
  const stage = root.querySelector<HTMLElement>('[data-stage]')!;
  const canvas = root.querySelector<HTMLCanvasElement>('[data-canvas]')!;
  const ctx = canvas.getContext('2d');
  if (!ctx) return;
  const tooltip = root.querySelector<HTMLElement>('[data-tooltip]')!;
  const card = root.querySelector<HTMLElement>('[data-card]')!;
  const jump = root.querySelector<HTMLSelectElement>('[data-jump]')!;
  const spinBtn = root.querySelector<HTMLButtonElement>('[data-rotate]')!;
  const status = root.querySelector<HTMLElement>('[data-globe-status]')!;
  const reduce = matchMedia('(prefers-reduced-motion: reduce)');
  const canHover = matchMedia('(hover: hover)');

  const projection = geoOrthographic().clipAngle(90).precision(0.6);
  const path = geoPath(projection, ctx);
  const nMax = Math.max(...data.g.map((g) => g[4]));

  let rotation: [number, number] = [-22, -28]; // starts over Europe / the Gulf, where most pins are
  let zoom = 0;
  let scale = 1;
  let size = 0;
  let dpr = 1;
  let colors = readColors();
  let selected: number | null = null;
  let hovered: number | null = null;
  let spinning = !reduce.matches;
  let onScreen = true;
  let frame = 0;
  let last = 0;
  let tween: { t0: number; ms: number; rot?: (t: number) => [number, number]; scale?: [number, number] } | null = null;
  let shown: { i: number; x: number; y: number }[] = [];

  function readColors(): Colors {
    const s = getComputedStyle(root);
    const v = (name: string) => s.getPropertyValue(`--globe-${name}`).trim();
    return { ocean: v('ocean'), land: v('land'), edge: v('edge'), grid: v('grid'), rim: v('rim'), pin: v('pin'), pick: v('pick'), halo: v('halo') };
  }

  // ---- sizing
  function resize() {
    const w = stage.clientWidth;
    if (!w) return;
    size = w;
    dpr = Math.min(window.devicePixelRatio || 1, 2);
    canvas.width = Math.round(w * dpr);
    canvas.height = Math.round(w * dpr);
    canvas.style.inlineSize = `${w}px`;
    canvas.style.blockSize = `${w}px`;
    // Resizing clears the canvas: repaint now rather than on the next animation frame, which some browsers hold back
    // (window occluded, background tab, efficiency modes); otherwise only the placeholder disc shows.
    draw();
    request();
  }

  // ---- drawing
  const radius = () => size * 0.42 * scale;
  function draw() {
    const R = radius();
    projection.scale(R).translate([size / 2, size / 2]).rotate([rotation[0], rotation[1], 0]);
    ctx!.setTransform(dpr, 0, 0, dpr, 0, 0);
    ctx!.clearRect(0, 0, size, size);

    ctx!.beginPath();
    path(SPHERE);
    ctx!.fillStyle = colors.ocean;
    ctx!.fill();
    ctx!.beginPath();
    path(GRID);
    ctx!.strokeStyle = colors.grid;
    ctx!.lineWidth = 0.6;
    ctx!.stroke();
    ctx!.beginPath();
    path(LAND);
    ctx!.fillStyle = colors.land;
    ctx!.fill();
    ctx!.strokeStyle = colors.edge;
    ctx!.lineWidth = 0.7;
    ctx!.stroke();
    ctx!.beginPath();
    path(SPHERE);
    ctx!.strokeStyle = colors.rim;
    ctx!.lineWidth = 1.5;
    ctx!.stroke();

    // Pins: far side culled, fade near the rim, drawn far-to-near. In an orthographic view a point lifted by h sits
    // at centre + (p âˆ’ centre)Â·(1 + h), so spikes lengthen towards the rim and shrink to their base dot mid-disc.
    const centre: [number, number] = [-rotation[0], -rotation[1]];
    const c = size / 2;
    const list: { i: number; d: number; x: number; y: number }[] = [];
    data.g.forEach((g, i) => {
      const d = geoDistance([g[0], g[1]], centre);
      if (d >= Math.PI / 2) return;
      const p = projection([g[0], g[1]]);
      if (p) list.push({ i, d, x: p[0], y: p[1] });
    });
    list.sort((a, b) => b.d - a.d);
    for (const { i, d, x, y } of list) {
      const n = data.g[i][4];
      const alpha = Math.min(1, (Math.PI / 2 - d) / (15 * DEG));
      const h = 0.015 + 0.1 * Math.sqrt(n / nMax);
      const tx = c + (x - c) * (1 + h);
      const ty = c + (y - c) * (1 + h);
      const active = i === selected || i === hovered;
      ctx!.globalAlpha = alpha;
      ctx!.strokeStyle = active ? colors.pick : colors.pin;
      ctx!.lineWidth = active ? 3 : 2;
      ctx!.lineCap = 'round';
      ctx!.beginPath();
      ctx!.moveTo(x, y);
      ctx!.lineTo(tx, ty);
      ctx!.stroke();
      ctx!.beginPath();
      ctx!.arc(x, y, (n >= 20 ? 3.5 : 2.5) + (active ? 1.5 : 0), 0, Math.PI * 2);
      ctx!.fillStyle = active ? colors.pick : colors.pin;
      ctx!.fill();
      if (i === selected) {
        ctx!.beginPath();
        ctx!.arc(x, y, 9, 0, Math.PI * 2);
        ctx!.strokeStyle = colors.halo;
        ctx!.lineWidth = 2;
        ctx!.stroke();
      }
    }
    ctx!.globalAlpha = 1;
    shown = list.map(({ i, x, y }) => ({ i, x, y }));
    root.classList.add('is-ready');
  }

  // ---- loop: runs only while something moves and the globe is on screen
  function request() {
    if (!frame) frame = requestAnimationFrame(tick);
  }
  function tick(now: number) {
    frame = 0;
    const dt = last ? Math.min(now - last, 64) / 1000 : 0;
    last = now;
    if (tween) {
      const t = Math.min(1, (now - tween.t0) / tween.ms);
      const e = 1 - (1 - t) ** 3;
      if (tween.rot) rotation = tween.rot(e);
      if (tween.scale) scale = tween.scale[0] + (tween.scale[1] - tween.scale[0]) * e;
      if (t >= 1) tween = null;
    } else if (spinning && !dragging) {
      rotation = [rotation[0] + SPIN * dt, rotation[1]];
    }
    draw();
    if (onScreen && !document.hidden && (tween || (spinning && !dragging))) request();
    else last = 0;
  }

  function animate(next: { rot?: [number, number]; scale?: number }, ms = 700) {
    if (reduce.matches) {
      if (next.rot) rotation = next.rot;
      if (next.scale) scale = next.scale;
      tween = null;
      return request();
    }
    const from: [number, number] = [-rotation[0], -rotation[1]];
    const interp = next.rot ? geoInterpolate(from, [-next.rot[0], -next.rot[1]]) : null;
    tween = {
      t0: performance.now(),
      ms,
      rot: interp ? (t) => { const [lng, lat] = interp(t); return [-lng, -lat]; } : undefined,
      scale: next.scale ? [scale, next.scale] : undefined,
    };
    last = 0;
    request();
  }

  function setSpinning(on: boolean) {
    spinning = on;
    spinBtn.setAttribute('aria-pressed', String(on));
    last = 0;
    request();
  }

  // ---- picking
  function pick(x: number, y: number, touch: boolean): number | null {
    const r = touch ? 20 : 12;
    let best: number | null = null;
    let bestD = r * r;
    for (const s of shown) {
      const d = (s.x - x) ** 2 + (s.y - y) ** 2;
      if (d <= bestD) {
        bestD = d;
        best = s.i;
      }
    }
    return best;
  }
  const local = (e: PointerEvent | MouseEvent) => {
    const b = canvas.getBoundingClientRect();
    return [e.clientX - b.left, e.clientY - b.top] as const;
  };

  // ---- pin card
  const title = card.querySelector<HTMLElement>('[data-card-title]')!;
  const country = card.querySelector<HTMLElement>('[data-card-country]')!;
  const count = card.querySelector<HTMLElement>('[data-card-count]')!;
  const mix = card.querySelector<HTMLElement>('[data-card-mix]')!;
  const nearbyWrap = card.querySelector<HTMLElement>('[data-card-nearby-wrap]')!;
  const nearby = card.querySelector<HTMLElement>('[data-card-nearby]')!;
  const show = card.querySelector<HTMLButtonElement>('[data-card-show]')!;

  const placeOf = (i: number) => data.g[i][3] ?? data.c[data.g[i][2]][0];

  function fillCard(i: number) {
    const [lng, lat, ci, place, , countText, flat] = data.g[i];
    const [countryLabel, slug, showLabel] = data.c[ci];
    title.textContent = place ?? countryLabel;
    country.textContent = place ? countryLabel : '';
    count.textContent = countText;
    mix.replaceChildren(
      ...Array.from({ length: Math.min(flat.length / 2, 4) }, (_, k) => {
        const [label, href] = data.p[flat[k * 2]];
        const li = document.createElement('li');
        const a = document.createElement('a');
        a.href = href;
        a.textContent = label;
        const n = document.createElement('span');
        n.textContent = String(flat[k * 2 + 1]);
        li.append(a, n);
        return li;
      }),
    );
    const near = data.g
      .map((g, k) => ({ k, d: geoDistance([g[0], g[1]], [lng, lat]) }))
      .filter(({ k, d }) => k !== i && d < NEARBY)
      .sort((a, b) => a.d - b.d)
      .slice(0, 4);
    nearbyWrap.hidden = !near.length;
    nearby.replaceChildren(
      ...near.map(({ k }) => {
        const b = document.createElement('button');
        b.type = 'button';
        b.className = 'globe__chip';
        b.textContent = placeOf(k);
        b.addEventListener('click', () => select(k));
        return b;
      }),
    );
    show.hidden = !slug;
    show.textContent = showLabel ?? '';
    show.dataset.slug = slug ?? '';
  }

  function select(i: number, { announce = true } = {}) {
    selected = i;
    setSpinning(false);
    fillCard(i);
    card.hidden = false;
    // Aim a little south of the pin so it sits above the card that covers the lower part of the disc.
    const [lng, lat] = data.g[i];
    animate({ rot: [-lng, -Math.max(-80, lat - 22 / ZOOMS[zoom])] });
    if (announce) {
      const [countryLabel] = data.c[data.g[i][2]];
      status.textContent = [data.g[i][3], countryLabel, data.g[i][5]].filter(Boolean).join(', ');
    }
  }

  function closeCard() {
    const hadFocus = card.contains(document.activeElement);
    card.hidden = true;
    selected = null;
    request();
    if (hadFocus) jump.focus();
  }

  // ---- input
  let dragging = false;
  let down: { x: number; y: number; rot: [number, number]; id: number; touch: boolean } | null = null;

  canvas.addEventListener('pointerdown', (e) => {
    if (e.button !== 0) return;
    const [x, y] = local(e);
    down = { x, y, rot: [...rotation], id: e.pointerId, touch: e.pointerType === 'touch' };
  });
  canvas.addEventListener('pointermove', (e) => {
    const [x, y] = local(e);
    if (down && e.pointerId === down.id) {
      const dx = x - down.x;
      const dy = y - down.y;
      if (!dragging && Math.hypot(dx, dy) > 4) {
        dragging = true;
        canvas.setPointerCapture(e.pointerId);
        root.classList.add('is-dragging');
        setSpinning(false);
        tween = null;
      }
      if (dragging) {
        const k = 180 / (Math.PI * radius());
        // Touch drags turn the globe left/right only; vertical swipes stay page scrolls (touch-action: pan-y).
        const lat = down.touch ? down.rot[1] : Math.max(-80, Math.min(80, down.rot[1] - dy * k));
        rotation = [down.rot[0] + dx * k, lat];
        request();
      }
      return;
    }
    if (!canHover.matches) return;
    const hit = pick(x, y, false);
    if (hit !== hovered) {
      hovered = hit;
      canvas.style.cursor = hit === null ? '' : 'pointer';
      if (hit === null) tooltip.hidden = true;
      else {
        const [countryLabel] = data.c[data.g[hit][2]];
        tooltip.textContent = data.g[hit][3] ? `${data.g[hit][3]} Â· ${countryLabel}` : countryLabel;
        tooltip.hidden = false;
      }
      request();
    }
    if (hit !== null) {
      tooltip.style.transform = `translate(${Math.min(x + 14, size - tooltip.offsetWidth - 4)}px, ${Math.max(y - 34, 4)}px)`;
    }
  });
  const end = (e: PointerEvent) => {
    if (!down || e.pointerId !== down.id) return;
    const wasDrag = dragging;
    const start = down;
    down = null;
    dragging = false;
    root.classList.remove('is-dragging');
    if (e.type === 'pointercancel' || wasDrag) return request();
    const [x, y] = local(e);
    const hit = pick(x, y, start.touch);
    if (hit !== null) select(hit);
    else if (!card.hidden) closeCard();
  };
  canvas.addEventListener('pointerup', end);
  canvas.addEventListener('pointercancel', end);
  canvas.addEventListener('pointerleave', () => {
    if (hovered !== null) {
      hovered = null;
      tooltip.hidden = true;
      request();
    }
  });
  canvas.addEventListener('dblclick', (e) => {
    const [x, y] = local(e);
    const geo = projection.invert?.([x, y]);
    setZoom(zoom + 1 < ZOOMS.length ? zoom + 1 : 0, geo ? [-geo[0], -geo[1]] : undefined);
  });

  function setZoom(z: number, rot?: [number, number]) {
    zoom = Math.max(0, Math.min(ZOOMS.length - 1, z));
    root.querySelector<HTMLButtonElement>('[data-zoom="-1"]')!.disabled = zoom === 0;
    root.querySelector<HTMLButtonElement>('[data-zoom="1"]')!.disabled = zoom === ZOOMS.length - 1;
    animate({ scale: ZOOMS[zoom], rot }, 350);
  }
  root.querySelectorAll<HTMLButtonElement>('[data-zoom]').forEach((b) => b.addEventListener('click', () => setZoom(zoom + Number(b.dataset.zoom))));
  spinBtn.addEventListener('click', () => setSpinning(!spinning));
  // Chrome on Windows fires `change` for every arrow key in a closed <select>: select the place, keep focus here.
  jump.addEventListener('change', () => {
    if (jump.value !== '') select(Number(jump.value));
  });
  card.querySelector('[data-close]')!.addEventListener('click', closeCard);
  show.addEventListener('click', () => document.dispatchEvent(new CustomEvent('refs:country', { detail: { slug: show.dataset.slug } })));
  root.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && !card.hidden) {
      e.stopPropagation();
      closeCard();
    }
  });
  root.addEventListener('focusin', (e) => {
    // Keyboard users exploring the controls get a still globe (WCAG 2.2.2); the toggle restarts it.
    if (e.target !== spinBtn && spinning) setSpinning(false);
  });

  // ---- environment
  const recolor = () => {
    colors = readColors();
    request();
  };
  // Safari < 14 has no MediaQueryList.addEventListener; throwing here would stop the globe before its first frame.
  const onMedia = (mq: MediaQueryList, fn: () => void) => (mq.addEventListener ? mq.addEventListener('change', fn) : mq.addListener(fn));
  new MutationObserver(recolor).observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] });
  onMedia(matchMedia('(prefers-color-scheme: dark)'), recolor);
  onMedia(matchMedia('(forced-colors: active)'), recolor);
  onMedia(reduce, () => reduce.matches && setSpinning(false));
  // A GPU reset (driver update, sleep/resume) wipes the canvas; a still globe would otherwise stay blank.
  canvas.addEventListener('contextrestored', () => draw());
  if (typeof ResizeObserver === 'function') new ResizeObserver(resize).observe(stage);
  else window.addEventListener('resize', resize);
  if (typeof IntersectionObserver === 'function') {
    new IntersectionObserver(([entry]) => {
      onScreen = entry.isIntersecting;
      if (onScreen) request();
    }).observe(stage);
  }
  document.addEventListener('visibilitychange', () => !document.hidden && request());

  spinBtn.setAttribute('aria-pressed', String(spinning));
  root.querySelector<HTMLButtonElement>('[data-zoom="-1"]')!.disabled = true;
  resize();
}
