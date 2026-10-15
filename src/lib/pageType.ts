// Picks a template from frontmatter alone (url / parent / postType), so re-imports can't break the routing.
// Product hubs and root-level products come from src/data/routing.json, shared with the import pipeline.
import ROUTING from '../data/routing.json';
export type PageType =
  | 'home'
  | 'hub'
  | 'product'
  | 'service'
  | 'article'
  | 'reference'
  | 'contact'
  | 'about'
  | 'generic';

export const HUBS = [
  '/our-courts/',
  '/innovative-courts/',
  '/canopy/',
  '/innovative-canopy/',
  '/turfs/',
  '/our-services/',
  '/padel-guide/',
  '/references/',
] as const;

const PRODUCT_PARENTS = new Set<string>(ROUTING.productParents);
const PRODUCT_URLS = new Set<string>(ROUTING.productUrls);
const NEWS_URLS = new Set<string>(ROUTING.newsUrls);

/** Entries under /references/ that are company news rather than delivered projects. */
export const isNews = (url: string) => NEWS_URLS.has(url);

const BY_URL: Record<string, PageType> = {
  '/': 'home',
  '/contact-us/': 'contact',
  '/about-us/': 'about',
};

export function pageTypeOf(d: { url: string; parent: string | null; postType: 'page' | 'post' }): PageType {
  if (BY_URL[d.url]) return BY_URL[d.url];
  if ((HUBS as readonly string[]).includes(d.url)) return 'hub';
  if (PRODUCT_URLS.has(d.url) || (d.parent && PRODUCT_PARENTS.has(d.parent))) return 'product';
  if (d.parent === '/our-services/') return 'service';
  if (d.parent === '/padel-guide/' || d.postType === 'post') return 'article';
  if (d.parent === '/references/') return 'reference';
  return 'generic';
}

/** Product-like pages that are not under a product hub (orphans named like products) still get the product template. */
export const isProductLike = (d: { url: string; parent: string | null; postType: 'page' | 'post'; hero?: { video: string | null } | undefined; faq?: unknown[] | undefined }) =>
  pageTypeOf(d) === 'product' || (pageTypeOf(d) === 'generic' && (!!d.hero?.video || !!d.faq?.length));
