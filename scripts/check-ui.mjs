// Cross-page consistency checks on the built site (dist/). Run after `npm run build`.
// Each rule is a defect found in the 2026-10-02 audit; the build output must never bring one back.
// Exits non-zero when any page breaks a rule.
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const DIST = path.join(ROOT, 'dist');
const VOID = new Set(['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'source', 'track', 'wbr', 'path', 'circle', 'rect', 'line', 'use', 'polyline', 'polygon', 'ellipse', 'stop']);

const walk = (dir) => fs.readdirSync(dir, { withFileTypes: true }).flatMap((e) =>
  e.isDirectory() ? walk(path.join(dir, e.name)) : e.name === 'index.html' ? [path.join(dir, e.name)] : []);

const sizeless = (src) => String(src ?? '').replace(/^https?:\/\/[^/]+/, '').replace(/[?#].*$/, '').replace(/-\d+x\d+(\.\w+)$/, '$1');
const text = (html) => html.replace(/<script[\s\S]*?<\/script>|<style[\s\S]*?<\/style>/g, '').replace(/<[^>]+>/g, ' ');

/** The element that opens at `start` (index of its "<"), as [outerHTML, top-level child count]. */
function element(html, start) {
  const re = /<(\/?)([a-z][a-z0-9-]*)\b[^>]*?(\/?)>/gi;
  re.lastIndex = start;
  let depth = 0;
  let children = 0;
  for (let m; (m = re.exec(html)); ) {
    const [, close, tag, selfClose] = m;
    if (VOID.has(tag.toLowerCase()) || selfClose) {
      if (depth === 1) children++;
      continue;
    }
    if (close) {
      depth--;
      if (depth === 0) return [html.slice(start, re.lastIndex), children];
    } else {
      if (depth === 1) children++;
      depth++;
    }
  }
  return [html.slice(start), children];
}

const firstElement = (html, classRe) => {
  const m = html.match(new RegExp(`<[a-z]+\\b[^>]*class="[^"]*${classRe}[^"]*"`, 'i'));
  return m ? element(html, m.index)[0] : null;
};

const failures = [];
const fail = (url, rule, detail = '') => failures.push(`${url}  [${rule}]${detail ? ' ' + detail : ''}`);
let checked = 0;
const titles = new Map();

for (const file of walk(DIST)) {
  const html = fs.readFileSync(file, 'utf8');
  if (/http-equiv="refresh"/i.test(html)) continue; // redirect stubs
  if (/[\\/]upx-shell[\\/]/.test(file)) continue; // WordPress reference shells: tokens, not pages (src/lib/upx-contract.mjs)
  const url = '/' + path.relative(DIST, path.dirname(file)).replaceAll('\\', '/') + (path.dirname(file) === DIST ? '' : '/');
  checked++;

  // SEO head: title, description, canonical, robots, valid JSON-LD, hreflang only on indexable pages
  const title = html.match(/<title>([^<]*)<\/title>/)?.[1] ?? '';
  if (!title) fail(url, 'seo-title', 'no <title>');
  else if (title.length > 70) fail(url, 'seo-title', `${title.length} chars`);
  if (titles.has(title)) fail(url, 'seo-title-dup', `same as ${titles.get(title)}`);
  titles.set(title, url);
  const desc = html.match(/<meta name="description" content="([^"]*)"/)?.[1];
  if (!desc) fail(url, 'seo-description', 'missing');
  else if (desc.length > 170) fail(url, 'seo-description', `${desc.length} chars`);
  const canonical = html.match(/<link rel="canonical" href="([^"]+)"/)?.[1];
  if (!canonical || new URL(canonical).pathname !== url) fail(url, 'seo-canonical', canonical ?? 'missing');
  const noindex = /<meta name="robots" content="noindex/.test(html);
  if (noindex && /<link[^>]+hreflang=/.test(html)) // <a hreflang> is the language switcher, allowed everywhere fail(url, 'seo-hreflang', 'hreflang on a noindex page');
  for (const [, json] of html.matchAll(/<script type="application\/ld\+json">([\s\S]*?)<\/script>/g)) {
    try { JSON.parse(json); } catch { fail(url, 'seo-jsonld', 'invalid JSON-LD'); }
  }

  // one H1 per page
  const h1 = (html.match(/<h1\b/g) ?? []).length;
  if (h1 !== 1) fail(url, 'h1', `${h1} H1 elements`);

  // hero: always an eyebrow; never a split layout without media
  const hero = firstElement(html, '\\bhero\\b');
  if (!hero) fail(url, 'hero', 'no hero');
  else {
    const cls = hero.match(/class="([^"]*)"/)[1];
    if (!/class="eyebrow\b/.test(hero)) fail(url, 'hero-eyebrow', 'hero without eyebrow');
    if (/hero--split/.test(cls) && !/hero--media/.test(cls)) fail(url, 'hero-split-empty', 'split hero without media');
  }

  // article grid: two columns only next to a table of contents
  const hasToc = /class="toc[\s"]/.test(html);
  if (/article-layout--toc/.test(html) !== hasToc) fail(url, 'article-layout', hasToc ? 'TOC without the two-column grid' : 'two-column grid without a TOC');

  // prose body: no literal Markdown, no primary buttons, no copy of the hero image
  const prose = firstElement(html, '\\bprose\\b');
  if (prose) {
    if (/\*\*/.test(text(prose))) fail(url, 'raw-markdown', 'literal ** in the body');
    if (/btn--primary/.test(prose)) fail(url, 'body-primary-cta', 'orange button inside the body');
    const heroSrc = hero?.match(/class="hero__still"[^>]*?src="([^"]+)"|src="([^"]+)"[^>]*class="hero__still"/);
    const key = sizeless(heroSrc?.[1] ?? heroSrc?.[2]);
    if (key && [...prose.matchAll(/<img\b[^>]*?src="([^"]+)"/g)].some((m) => sizeless(m[1]) === key)) fail(url, 'hero-image-dup', key.split('/').pop());
  }

  // card grids: never a lone card on the last row (the reference index uses a featured 2×2 card, skipped)
  for (const m of html.matchAll(/<div\b[^>]*class="[^"]*\bcard-grid\b[^"]*"[^>]*style="--cols:(\d)"/g)) {
    if (/ref-grid/.test(m[0])) continue;
    const cols = Number(m[1]);
    const [, n] = element(html, m.index);
    if (n % cols === 1) fail(url, 'grid-orphan', `${n} cards in ${cols} columns`);
  }
}

console.log(`checked ${checked} pages`);
if (failures.length) {
  console.log(`\nFAILURES (${failures.length}):\n  ` + failures.join('\n  '));
  process.exit(1);
}
console.log('ui consistency OK');
