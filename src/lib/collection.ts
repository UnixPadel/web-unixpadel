// Helpers over the `pages` collection shared by templates and components.
import { getCollection, type CollectionEntry } from 'astro:content';
import type { Lang } from '../i18n';
import { cleanTitle } from './text.mjs';

/** Content collection of each translated language (src/content.config.ts). */
export const COLLECTION = { tr: 'pagesTr', de: 'pagesDe', fr: 'pagesFr', ar: 'pagesAr', ru: 'pagesRu' } as const;
type TranslatedCollection = (typeof COLLECTION)[keyof typeof COLLECTION];

export type Page = CollectionEntry<'pages'> | CollectionEntry<TranslatedCollection>;

const cache: Partial<Record<Lang, Promise<Page[]>>> = {};

/** English pages with a language's translations swapped in (untranslated pages fall back to English). */
async function loadLang(lang: Exclude<Lang, 'en'>): Promise<Page[]> {
  const local = new Map(((await getCollection(COLLECTION[lang])) as Page[]).map((p) => [p.data.url, p]));
  return (await getCollection('pages')).map((p) => local.get(p.data.url) ?? p);
}

/** All pages of a language, loaded once per build. */
export const allPages = (lang: Lang = 'en'): Promise<Page[]> => (cache[lang] ??= lang === 'en' ? getCollection('pages') : loadLang(lang));

/** True when the entry is a translation (not the English original or an English fallback). */
export const isTranslated = (p: Page) => p.collection !== 'pages';

const byOrder = (a: Page, b: Page) => {
  const ao = a.data.order ?? Number.MAX_SAFE_INTEGER;
  const bo = b.data.order ?? Number.MAX_SAFE_INTEGER;
  return ao - bo || labelOf(a).localeCompare(labelOf(b));
};

/** Direct children of a hub URL, in editorial order (the English order, so both languages list alike). */
export async function childrenOf(url: string, lang: Lang = 'en'): Promise<Page[]> {
  const en = (await allPages()).filter((p) => p.data.parent === url).sort(byOrder);
  if (lang === 'en') return en;
  const local = new Map((await allPages(lang)).map((p) => [p.data.url, p]));
  return en.map((p) => local.get(p.data.url) ?? p);
}

export async function pageByUrl(url: string, lang: Lang = 'en'): Promise<Page | undefined> {
  return (await allPages(lang)).find((p) => p.data.url === url);
}

/**
 * Display name: title first (navLabel can carry scraped junk), then nav label, then the SEO title's first segment.
 * cleanTitle() drops the SEO leftovers some reference titles carry (" | Unixpadel", emoji, " | " separators).
 */
export const labelOf = (p: Page): string =>
  cleanTitle(p.data.title ?? p.data.navLabel ?? p.data.seo.title?.split(/\s+[-|–]\s+/)[0] ?? p.data.slug);

/** Short name for menus and cards (nav label when it exists). */
export const shortLabelOf = (p: Page): string => p.data.navLabel ?? labelOf(p);

/** Icons, flags and the WordPress author avatar never make a good card image. */
const NOT_A_PHOTO = /ikon|icon|gravatar|\/avatar\/|-v1\.png$/i;

/** Best image for a card: OG image, hero poster/banner, else the first content photo. */
export const cardImage = (p: Page): string | null =>
  p.data.seo.ogImage ??
  p.data.hero?.poster ??
  p.data.hero?.image ??
  p.data.media.find((m) => m.type === 'image' && !NOT_A_PHOTO.test(m.src))?.src ??
  null;

export const cardImageAlt = (p: Page): string => {
  const src = cardImage(p);
  return p.data.media.find((m) => m.src === src)?.alt || labelOf(p);
};

export const excerptOf = (p: Page): string | null => p.data.hero?.tagline ?? p.data.seo.description ?? null;

/** Rough reading time from the raw Markdown body. */
export const readingTime = (p: Page): number => Math.max(1, Math.round((p.body ?? '').split(/\s+/).length / 220));
