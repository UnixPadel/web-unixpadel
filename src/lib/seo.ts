// Head-tag decisions shared by BaseLayout and the sitemap: titles, descriptions, robots and hreflang.
import type { Page } from './collection';
import { LANGS, TRANSLATED, localizePath } from '../i18n';
import { BRAND_SUFFIX } from './text.mjs';

const BRAND = 'Unixpadel';
const MAX_TITLE = 60;
const MAX_DESC = 158;

/** One brand suffix form ("… | Unixpadel"); left off when it would push a long title further past ~60 characters. */
export function normalizeTitle(raw: string): string {
  let t = raw.trim().replace(/\s+/g, ' ');
  while (BRAND_SUFFIX.test(t)) t = t.replace(BRAND_SUFFIX, '');
  if (!t) return BRAND;
  if (/unix\s?padel/i.test(t)) return t; // brand already in the title
  const withBrand = `${t} | ${BRAND}`;
  return withBrand.length <= MAX_TITLE || t.length < 40 ? withBrand : t;
}

const clip = (s: string, max = MAX_DESC) => {
  if (s.length <= max) return s;
  const cut = s.slice(0, max - 1);
  return `${cut.slice(0, cut.lastIndexOf(' ')).replace(/[\s,;:–-]+$/, '')}…`;
};

/** Markdown → plain text, enough for a meta description. */
const plain = (md: string) =>
  md
    .replace(/<[^>]+>/g, ' ')
    .replace(/!\[[^\]]*\]\([^)]*\)/g, '')
    .replace(/\[([^\]]+)\]\([^)]*\)/g, '$1')
    .replace(/[*_`>#]/g, '')
    .replace(/\s+/g, ' ')
    .trim();

/** First real paragraph of the body (skips headings, images, lists and short label lines). */
const leadParagraph = (body: string | undefined) => {
  for (const block of (body ?? '').split(/\n\s*\n/)) {
    const b = block.trim();
    if (!b || /^(#|!\[|[-*] |\d+\. |<|\|)/.test(b)) continue;
    const text = plain(b);
    if (text.length >= 80) return text;
  }
  return null;
};

/** Meta description: the Yoast one (trimmed), else the hero tagline, else the body's lead paragraph. */
export function describe(page: Page | undefined, given?: string | null): string | null {
  const d = given ?? page?.data.seo.description ?? page?.data.hero?.tagline ?? leadParagraph(page?.body);
  return d ? clip(plain(d)) : null;
}

/** Pages still waiting for an editorial pass stay out of the index until they are reviewed. */
export const isIndexable = (page: Page | undefined) => !page?.data.pendingReview;

export const DEFAULT_ROBOTS = 'index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1';

export function robotsOf(page: Page | undefined, given?: string | null): string {
  if (!isIndexable(page)) return 'noindex, follow';
  return given ?? page?.data.seo.robots ?? DEFAULT_ROBOTS;
}

/**
 * hreflang links for a page, by its English URL: every language that has the page, plus x-default (English).
 * All six languages are rendered by this site, so the old Weglot map (src/data/hreflang.json) is no longer read.
 */
export function alternatesOf(url: string, site: URL | string): { lang: string; href: string }[] {
  const langs = LANGS.filter((l) => TRANSLATED[l].has(url) || l === 'en');
  if (langs.length < 2) return [];
  const abs = (p: string) => new URL(p, site).href;
  return [...langs.map((lang) => ({ lang, href: abs(localizePath(url, lang)) })), { lang: 'x-default', href: abs(url) }];
}
