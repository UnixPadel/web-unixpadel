// Rehype plugins applied to every Markdown body (registered in astro.config.mjs).
// They decorate the generated HTML for the design; the content files stay untouched.
import { visit } from 'unist-util-visit';
import { createRequire } from 'node:module';
import { SPECS_HEADING_RE } from './specsHeading.mjs';

const require = createRequire(import.meta.url);
const srcsets = require('../data/media-srcset.json');

const WIDTH_RE = /-(\d+)x\d+\.[a-z0-9]+$/i;
const CTA_TEXT_RE = /^(get a (custom )?quote( today)?|contact( us| our team| engineering team)?( today)?|request a quote|explore|read (the )?(guide|technical analysis)|estimate the project|identify a quality net|explore the design factors|technical specs|discover the details|discover details|view technical specs|write to us now|talk to the unixpadel team|try it now!?|check our padel courts!?|teklif alın|bize ulaşın|inceleyin|rehberi okuyun|teknik özellikler|detayları keşfedin|hemen yazın|mühendislik ekibine ulaşın|angebot anfordern|kontakt aufnehmen|mehr erfahren|leitfaden lesen|technische daten|details entdecken|jetzt schreiben|demander un devis|nous contacter|découvrir|lire le guide|caractéristiques techniques|voir les détails|écrivez-nous|اطلب عرض سعر|تواصل معنا|استكشف|اقرأ الدليل|المواصفات الفنية|اطّلع على التفاصيل|راسلنا الآن|запросить предложение|связаться с нами|подробнее|читать руководство|технические характеристики|узнать подробности|написать нам)$/i;
// Non-English entries: the fixed CTA phrases of TRANSLATION-GUIDE-<LANG>.md (tr, de, fr, ar, ru).

const textOf = (node) =>
  node.type === 'text' ? node.value : (node.children ?? []).map(textOf).join('');

/** `/wp-content/...` → absolute media origin on src / poster / srcset / href. */
export function rehypeMediaOrigin({ origin }) {
  const abs = (v) => (typeof v === 'string' && v.startsWith('/wp-content/') ? origin + v : v);
  return (tree) => {
    if (!origin) return;
    visit(tree, 'element', (node) => {
      const p = node.properties ?? {};
      for (const key of ['src', 'poster', 'href']) if (p[key]) p[key] = abs(p[key]);
      if (typeof p.srcSet === 'string') p.srcSet = p.srcSet.replace(/\/wp-content\/\S+/g, (m) => origin + m);
    });
  };
}

/**
 * Paragraphs that are only a CTA link become buttons — navigation CTAs only ("Technical specs",
 * "Read the guide", "Explore …") as secondary buttons. Quote / contact / WhatsApp CTAs stay text links:
 * every template already has the orange quote buttons (hero, quote rail, CTA band), so the body never
 * adds a competing primary action. A button repeating the previous button's target is dropped.
 */
export function rehypeCtaButtons() {
  const QUOTE_HREF_RE = /wa\.me|whatsapp|^mailto:|^tel:|^#contact|^\/contact-us/;
  const elements = (node) => (node.children ?? []).filter((c) => c.type === 'element');
  const buttonHref = (p) => {
    const a = p?.tagName === 'p' && elements(p).length === 1 ? elements(p)[0] : null;
    return a?.tagName === 'a' && (a.properties?.className ?? []).includes('btn') ? String(a.properties.href ?? '') : null;
  };
  return (tree) => {
    const drop = [];
    visit(tree, 'element', (node, index, parent) => {
      if (node.tagName !== 'p') return;
      const kids = node.children.filter((c) => !(c.type === 'text' && !c.value.trim()));
      if (kids.length !== 1 || kids[0].tagName !== 'a') return;
      const a = kids[0];
      const text = textOf(a).trim();
      if (!CTA_TEXT_RE.test(text)) return;
      const href = String(a.properties?.href ?? '');
      if (QUOTE_HREF_RE.test(href)) return;
      const prev = parent ? elements(parent)[elements(parent).indexOf(node) - 1] : null;
      if (prev && buttonHref(prev) === href) {
        drop.push([parent, node]);
        return;
      }
      a.properties = { ...a.properties, className: ['btn', 'btn--secondary'] };
      // unwrap <strong> inside the button
      a.children = a.children.flatMap((c) => (c.tagName === 'strong' ? c.children : [c]));
    });
    for (const [parent, node] of drop) parent.children.splice(parent.children.indexOf(node), 1);
  };
}

/** Lazy images with srcset, playsinline videos, lazy titled iframes wrapped for aspect-ratio. */
export function rehypeMedia({ origin }) {
  const abs = (v) => (v.startsWith('/wp-content/') ? origin + v : v);
  return (tree) => {
    visit(tree, 'element', (node, index, parent) => {
      const p = (node.properties ??= {});
      if (node.tagName === 'img') {
        p.loading ??= 'lazy';
        p.decoding ??= 'async';
        // The scrape has no dimensions; a 16:9 hint reserves space (CSS keeps block-size: auto, so no distortion).
        if (!p.width && !p.height) {
          p.width = 1200;
          p.height = 675;
        }
        const key = String(p.src ?? '').replace(origin, '');
        const variants = srcsets[key];
        if (variants?.length && !p.srcSet) {
          const parts = variants
            .map((v) => ({ v, w: Number(v.match(WIDTH_RE)?.[1] ?? 0) }))
            .filter((x) => x.w > 0)
            .sort((a, b) => a.w - b.w)
            .map(({ v, w }) => `${abs(v)} ${w}w`);
          if (parts.length) {
            parts.push(`${abs(key)} 1920w`);
            p.srcSet = parts.join(', ');
            p.sizes = '(min-width: 1024px) 65ch, 100vw';
          }
        }
      } else if (node.tagName === 'video') {
        p.playsInline = true;
        if (!p.controls) p.controls = true;
      } else if (node.tagName === 'iframe' && parent && parent.tagName !== 'div') {
        p.loading = 'lazy';
        p.referrerPolicy ??= 'no-referrer-when-downgrade';
        p.title ??= /google\.com\/maps/.test(String(p.src)) ? 'Map' : 'Embedded content';
        parent.children[index] = { type: 'element', tagName: 'div', properties: { className: ['embed'] }, children: [node] };
      }
    });
  };
}

/**
 * Editors skipped heading levels (H2 → H5); rebuild the outline without gaps. Each heading nests one level
 * under the nearest preceding heading of a smaller source level, so siblings stay siblings
 * (H4, H4, H4 → H2, H2, H2 — not a staircase H2, H3, H4).
 */
export function rehypeHeadingOrder() {
  return (tree) => {
    const stack = []; // open ancestors: { source, level }; the layout renders the H1
    visit(tree, 'element', (node) => {
      const m = /^h([2-6])$/.exec(node.tagName);
      if (!m) return;
      const source = Number(m[1]);
      while (stack.length && stack.at(-1).source >= source) stack.pop();
      const level = stack.length ? stack.at(-1).level + 1 : 2;
      node.tagName = `h${level}`;
      stack.push({ source, level });
    });
  };
}

/**
 * WordPress headings often end in a colon ("Premium Accessories:", French "Nous contacter :"). Dropped at render time
 * so every language and every re-import is covered without editing the content files.
 */
export function rehypeHeadingColons() {
  const TRAILING_COLON_RE = /[\s  ]*[:：][\s  ]*$/;
  const lastText = (node) => {
    for (let i = (node.children ?? []).length - 1; i >= 0; i--) {
      const c = node.children[i];
      if (c.type === 'text' && c.value.trim()) return c;
      const inner = c.type === 'element' ? lastText(c) : null;
      if (inner) return inner;
    }
    return null;
  };
  return (tree) => {
    visit(tree, 'element', (node) => {
      if (!/^h[1-6]$/.test(node.tagName)) return;
      const text = lastText(node);
      if (text) text.value = text.value.replace(TRAILING_COLON_RE, '');
    });
  };
}

/** The "Technical Specifications" ("Teknik Özellikler") heading gets id="specs" so hero/rail links can target it. */
export function rehypeSpecsId() {
  return (tree) => {
    let done = false;
    visit(tree, 'element', (node) => {
      if (done || !/^h[2-4]$/.test(node.tagName)) return;
      if (SPECS_HEADING_RE.test(textOf(node))) {
        node.properties = { ...node.properties, id: 'specs' };
        done = true;
      }
    });
  };
}
