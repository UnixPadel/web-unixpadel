// Scroll reveals, number count-ups and the header's scroll state. Imported once by BaseLayout.
// Reveal is position-based (checked on scroll, resize and load) rather than IntersectionObserver-only,
// so nothing can stay hidden after fast scrolls, anchor jumps, End key or full-page captures.
// With reduced motion everything is shown immediately.

const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
const root = document.documentElement;
let pending = [...document.querySelectorAll<HTMLElement>('[data-reveal]')];
let counters = [...document.querySelectorAll<HTMLElement>('[data-count]')];

// stagger siblings inside one group: --i = index among revealable siblings (max 5)
pending.forEach((el) => {
  const sibs = el.parentElement ? [...el.parentElement.children].filter((c) => c.hasAttribute('data-reveal')) : [];
  el.style.setProperty('--i', String(Math.min(sibs.indexOf(el), 5)));
});

function countUp(el: HTMLElement) {
  const target = el.dataset.count ?? '';
  const n = Number(target.replace(/[^\d]/g, ''));
  const suffix = target.replace(/^[\d,]+/, '');
  const grouped = target.includes(',');
  if (!n || reduce) return;
  const t0 = performance.now();
  const fmt = (v: number) => (grouped ? v.toLocaleString('en-US') : String(v)) + suffix;
  const step = (t: number) => {
    const p = Math.min(1, (t - t0) / 650);
    el.textContent = fmt(Math.round(n * (1 - Math.pow(1 - p, 3))));
    if (p < 1) requestAnimationFrame(step);
  };
  requestAnimationFrame(step);
}

function check() {
  const limit = window.innerHeight * 0.94;
  if (pending.length) {
    pending = pending.filter((el) => {
      if (el.getBoundingClientRect().top >= limit) return true;
      el.classList.add('is-in');
      return false;
    });
  }
  if (counters.length) {
    counters = counters.filter((el) => {
      if (el.getBoundingClientRect().top >= limit) return true;
      countUp(el);
      return false;
    });
  }
}

// Hysteresis: the sticky header shrinks 12px when scrolled, and scroll anchoring shifts scrollY by
// that amount. A single threshold makes it flip on/off forever near the top, so the on/off points
// sit further apart than the shrink.
const SCROLLED_ON = 48;
const SCROLLED_OFF = 8;

// ---- Reading progress: a court baseline along the header's bottom edge.
// Straight 3px lime fill, court marks (service ticks at 25/75%, dashed net at 50%) that light up once
// passed, and a tennis ball that hops along the line as the page scrolls. The hop is scroll-linked
// (a function of the ball's x), so it tracks the hand exactly and reverses cleanly. Behind the ball a
// short tail records its flight path and settles back into the line within TAIL_MS. When scrolling
// stops the ball drops onto the line and the CSS idle bounce (class is-idle) takes over.
const progressSvg = document.querySelector<SVGSVGElement>('[data-progress]');
const progressFill = progressSvg?.querySelector<SVGRectElement>('.progress__fill') ?? null;
const progressMarks = progressSvg?.querySelector<SVGGElement>('.progress__marks') ?? null;
const tail = progressSvg?.querySelector<SVGPathElement>('.progress__tail') ?? null;
const ball = progressSvg?.querySelector<SVGGElement>('.progress__ball') ?? null;
const ballBounce = progressSvg?.querySelector<SVGGElement>('.progress__bounce') ?? null;
const BALL_R = 4.5;
const STRIP_H = 18;
const LINE_H = 3;
const REST_Y = STRIP_H - LINE_H - BALL_R; // ball centre when sitting on the line
const LINE_MID = STRIP_H - LINE_H / 2; // tail flattens onto the fill line's centre
const HOP_LEN = 44; // px of travel per hop
const HOP_H = 6; // apex height
const TAIL_MS = 450; // how long the tail takes to settle into the line
const SAMPLE_PX = 4; // tail resolution
const MARKS = [
  { at: 0.25, net: false },
  { at: 0.5, net: true },
  { at: 0.75, net: false },
];
let progressWidth = 0;
let markEls: SVGLineElement[] = [];
let ballX = -1;
let lift = 0; // current height of the ball above the line
let moving = false;
let stopTimer = 0;
let rafId = 0;
let tailPts: { x: number; lift: number; t: number }[] = [];
let dropFrom = 0; // lift at the moment scrolling stopped
let dropAt = 0; // when it stopped
const DROP_MS = 140;

const hopAt = (x: number) => HOP_H * Math.abs(Math.sin((Math.PI * x) / HOP_LEN));

function buildProgress() {
  if (!progressSvg || !progressMarks) return;
  const w = progressSvg.clientWidth;
  if (!w || w === progressWidth) return;
  progressWidth = w;
  progressSvg.setAttribute('viewBox', `0 0 ${w} ${STRIP_H}`);
  progressMarks.replaceChildren();
  markEls = MARKS.map(({ at, net }) => {
    const line = document.createElementNS('http://www.w3.org/2000/svg', 'line');
    const x = (at * w).toFixed(1);
    const h = net ? 9 : 5; // the net stands taller than the service ticks
    line.setAttribute('class', `progress__mark${net ? ' progress__mark--net' : ''}`);
    line.setAttribute('x1', x);
    line.setAttribute('x2', x);
    line.setAttribute('y1', String(STRIP_H - LINE_H - h));
    line.setAttribute('y2', String(STRIP_H - LINE_H));
    progressMarks.appendChild(line);
    return line;
  });
}

function renderBall() {
  if (!ball) return;
  const spin = reduce ? 0 : ballX * 6; // one turn per 60px of travel
  ball.setAttribute('transform', `translate(${ballX.toFixed(2)} ${(REST_Y - lift).toFixed(2)}) rotate(${spin.toFixed(1)})`);
}

function renderTail(now: number) {
  if (!tail) return;
  tailPts = tailPts.filter((pt) => now - pt.t < TAIL_MS);
  if (!tailPts.length) {
    tail.setAttribute('d', '');
    return;
  }
  // each point sinks towards the line as it ages (ease-in, so fresh points keep their height)
  const d = tailPts
    .map((pt, i) => {
      const age = (now - pt.t) / TAIL_MS;
      const y = LINE_MID - pt.lift * (1 - age * age);
      return `${i ? 'L' : 'M'}${pt.x.toFixed(1)} ${y.toFixed(2)}`;
    })
    .join(' ');
  tail.setAttribute('d', d);
}

function tick(now: number) {
  rafId = 0;
  if (!moving && lift > 0) {
    // scrolling stopped mid-air: fall onto the line (time-based, accelerating like gravity)
    const t = Math.min(1, (now - dropAt) / DROP_MS);
    lift = dropFrom * (1 - t * t);
    renderBall();
  }
  if (!moving && lift === 0 && ballBounce && !reduce) ballBounce.classList.add('is-idle');
  renderTail(now);
  if (moving || lift > 0 || tailPts.length) rafId = requestAnimationFrame(tick);
}

const ensureTick = () => {
  if (!rafId) rafId = requestAnimationFrame(tick);
};

function placeBall(p: number) {
  if (!progressSvg || !progressFill || !ball || !progressWidth) return;
  const x = BALL_R + p * (progressWidth - 2 * BALL_R);
  progressFill.setAttribute('width', (p * progressWidth).toFixed(1));
  markEls.forEach((m, i) => m.classList.toggle('is-passed', p >= MARKS[i].at));
  progressSvg.classList.toggle('is-live', p > 0.004);

  if (ballX < 0) {
    ballX = x; // first paint: already at rest
    if (ballBounce && !reduce) ballBounce.classList.add('is-idle');
  } else if (x !== ballX) {
    moving = true;
    ballBounce?.classList.remove('is-idle');
    if (!reduce) {
      // sample the flight path between the previous and the new position so fast scrolls still draw arcs
      const now = performance.now();
      const steps = Math.min(60, Math.ceil(Math.abs(x - ballX) / SAMPLE_PX));
      for (let i = 1; i <= steps; i++) {
        const sx = ballX + ((x - ballX) * i) / steps;
        tailPts.push({ x: sx, lift: hopAt(sx), t: now });
      }
      if (tailPts.length > 80) tailPts = tailPts.slice(-80);
      lift = hopAt(x);
      renderTail(now); // draw in this frame, not the next
    }
    ballX = x;
    clearTimeout(stopTimer);
    stopTimer = window.setTimeout(() => {
      moving = false;
      dropFrom = lift;
      dropAt = performance.now();
      ensureTick();
    }, 120);
    ensureTick();
  }
  renderBall();
}

function onScroll() {
  const max = root.scrollHeight - root.clientHeight;
  const y = window.scrollY;
  const was = root.hasAttribute('data-scrolled');
  if (was ? y < SCROLLED_OFF : y > SCROLLED_ON) root.toggleAttribute('data-scrolled', !was);
  const p = max > 0 ? Math.min(1, y / max) : 0;
  root.style.setProperty('--progress', String(p));
  buildProgress();
  placeBall(p);
  check();
}

if (reduce) {
  pending.forEach((el) => el.classList.add('is-in'));
  pending = [];
}

let ticking = false;
const schedule = () => {
  if (ticking) return;
  ticking = true;
  requestAnimationFrame(() => {
    ticking = false;
    onScroll();
  });
};
window.addEventListener('scroll', schedule, { passive: true });
window.addEventListener('resize', schedule, { passive: true });
window.addEventListener('load', schedule);
window.addEventListener('beforeprint', () => pending.forEach((el) => el.classList.add('is-in')));
onScroll();
