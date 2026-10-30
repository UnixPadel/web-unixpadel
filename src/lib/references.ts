// Reference-project helpers shared by the /references/ hub cards, the project pages and the hub globe.
import MENU from '../data/menu.json';
import { allPages, cardImage, cardImageAlt, childrenOf, labelOf, type Page } from './collection';
import { countryOf } from './countries';
import { isNews, pageTypeOf } from './pageType';
import { leadText } from './text.mjs';
import { countryName } from '../i18n/data';
import { localizePath, t, type Lang } from '../i18n';
import type { ShowcaseItem } from '../data/showcase';

type MenuItem = { label: string; url: string | null; children?: MenuItem[] };
const MENU_LABELS = new Map<string, string>();
(function walk(items: MenuItem[]) {
  for (const i of items) {
    if (i.url && !MENU_LABELS.has(i.url)) MENU_LABELS.set(i.url, i.label);
    if (i.children) walk(i.children);
  }
})(MENU as MenuItem[]);

const pagesByUrl: Partial<Record<Lang, Promise<Map<string, Page>>>> = {};
const byUrl = (lang: Lang) => (pagesByUrl[lang] ??= allPages(lang).then((all) => new Map(all.map((p) => [p.data.url, p]))));

/** Short product name as the menu shows it ("Panoramic", "Flat Floor"), in the page language (Header.astro rule). */
export async function menuLabelOf(url: string, lang: Lang): Promise<string> {
  const page = (await byUrl(lang)).get(url);
  const en = MENU_LABELS.get(url) ?? (page ? labelOf(page) : url);
  return lang === 'en' ? en : (t(lang).menu[en] ?? page?.data.navLabel ?? (page ? labelOf(page) : en));
}

// Court types named in a project's English title/description (else the opening of its body). Most project texts
// never link to the product page, so links alone miss them. Specific names come before the generic ones they contain.
const COURT_WORDS: [RegExp, string][] = [
  // Specific / compound court models first
  [/single[\s-]full[\s-]panoramic/i, '/single-full-panoramic-padel-court/'],
  [/single[\s-]panoramic/i, '/single-panoramic-padel-court/'],
  [/full[\s-]panoramic/i, '/full-panoramic-padel-court/'],
  [/panoramic plate/i, '/panaromic-plate-padel-court/'],
  [/\bplate standard\b|\bplate\b/i, '/plate-standard-padel-court/'],
  [/panoramic/i, '/panoramic-padel-court/'],
  [/wp[\s-]challenge/i, '/wp-challenge-padel-court/'],
  [/padel[\s-]court[\s-]cover/i, '/padel-court-cover/'],
  [/portable/i, '/portable-padel-court/'],
  [/flat[\s-]floor/i, '/innovative-courts/flat-floor-padel-court/'],
  [/floating/i, '/floating-floor-padel-court/'],
  [/mobil\s?fold/i, '/mobilfold-padel-court/'],
  [/standard (?:padel )?courts?/i, '/standard-padel-court/'],
  [/single (?:padel )?courts?/i, '/single-padel-court/'],
  [/padbol/i, '/padbols/'],
  [/pickleball/i, '/our-courts/pickleball-court/'],
  // Canopy / Cover systems
  [/membrane/i, '/full-membrane-cover/'],
  [/sandwich[\s-]panel/i, '/sandwich-panel-full-covered/'],
  [/retractable/i, '/retractable-roof-cover-system/'],
  [/roof[\s-]?top/i, '/roof-top-canopy/'],
  [/half[\s–-]+close/i, '/half-close-canopy/'],
];
/** Product pages a project can name as its court type (the WordPress reference editor offers these). */
export const COURT_TYPE_URLS = [...new Set(COURT_WORDS.map(([, url]) => url))];

// A specific match makes its generic sibling redundant ("Full Panoramic" already says "Panoramic").
const COVERS: Record<string, string[]> = {
  '/single-full-panoramic-padel-court/': ['/single-padel-court/', '/single-panoramic-padel-court/', '/panoramic-padel-court/', '/full-panoramic-padel-court/'],
  '/single-panoramic-padel-court/': ['/single-padel-court/', '/panoramic-padel-court/'],
  '/full-panoramic-padel-court/': ['/panoramic-padel-court/'],
  '/panaromic-plate-padel-court/': ['/panoramic-padel-court/', '/plate-standard-padel-court/'],
  '/plate-standard-padel-court/': ['/standard-padel-court/'],
};

function namedCourts(en: Page): string[] {
  const find = (text: string) => {
    const hits = COURT_WORDS.filter(([re]) => re.test(text)).map(([, url]) => url);
    return hits.filter((u) => !hits.some((h) => COVERS[h]?.includes(u)));
  };
  const head = find(`${en.data.title ?? ''} ${en.data.seo.description ?? ''}`);
  return head.length ? head : find((en.body ?? '').replace(/!\[[^\]]*\]\([^)]*\)/g, '').slice(0, 700));
}

export type CourtType = { label: string; short: string; url: string; page: Page };

/** Court types of a project: product pages its body links to, else the ones its English text names. */
export async function courtTypesOf(page: Page, lang: Lang): Promise<CourtType[]> {
  const pages = await byUrl(lang);
  const products = [...pages.values()].filter((p) => pageTypeOf(p.data) === 'product');
  const body = page.body ?? '';
  let urls = products.filter((p) => body.includes(`](${p.data.url})`)).map((p) => p.data.url);
  if (!urls.length) {
    const en = (await byUrl('en')).get(page.data.url) ?? page;
    urls = namedCourts(en).filter((u) => pages.has(u));
  }
  return Promise.all(
    urls.map(async (url) => {
      const p = pages.get(url)!;
      return { label: labelOf(p), short: await menuLabelOf(url, lang), url: localizePath(url, lang), page: p };
    }),
  );
}

/** Card photo: the project's own, else the photo of the court type it names (video-only pages have none). */
export async function refCardImage(page: Page, lang: Lang): Promise<{ src: string; alt: string } | null> {
  const own = cardImage(page);
  if (own) return { src: own, alt: cardImageAlt(page) };
  for (const { page: product } of await courtTypesOf(page, lang)) {
    const src = cardImage(product);
    if (src) return { src, alt: cardImageAlt(product) };
  }
  return null;
}

/** Card excerpt: the SEO description, else the opening of the body. */
export const refExcerpt = (page: Page): string | null => page.data.seo.description ?? leadText(page.body ?? '', labelOf(page));

/**
 * "More projects" band under a reference: the next five delivered projects with a photo, wrapping round the list
 * (the newest five when `after` is not a static reference, e.g. the WordPress shell).
 */
export async function morePhotoBand(lang: Lang, after?: string): Promise<ShowcaseItem[]> {
  const all = await childrenOf('/references/', lang);
  const idx = all.findIndex((p) => p.data.url === after);
  return [...all.slice(idx + 1), ...all.slice(0, idx + 1)]
    .filter((p) => p.data.url !== after && !isNews(p.data.url) && cardImage(p))
    .slice(0, 5)
    .map((p) => ({
      src: cardImage(p)!,
      alt: cardImageAlt(p),
      label: countryName(countryOf(`${labelOf(p)} ${p.data.slug}`), lang) ?? labelOf(p),
      href: localizePath(p.data.url, lang),
    }));
}
