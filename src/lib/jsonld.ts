// JSON-LD builders. Each returns a plain object; BaseLayout serialises the array it receives.
import { SITE } from '../data/site';
import { mediaUrl } from './media';
import type { Crumb } from '../components/Breadcrumbs.astro';

type Page = {
  url: string;
  title: string | null;
  lastmod: Date | null;
  published?: Date | null | undefined;
  seo: { title: string | null; description: string | null; ogImage: string | null };
  hero?: { poster: string | null; image: string | null } | undefined;
  media: { type: string; src: string }[];
  faq?: { q: string; a: string }[] | undefined;
};

export const organization = () => ({
  '@context': 'https://schema.org',
  '@type': 'Organization',
  '@id': `${SITE.url}/#organization`,
  name: SITE.name,
  legalName: SITE.legalName,
  url: SITE.url,
  logo: mediaUrl(SITE.logo),
  sameAs: SITE.socials.map((s) => s.href),
  contactPoint: {
    '@type': 'ContactPoint',
    telephone: SITE.phone.replace(/\s/g, ''),
    email: SITE.email,
    contactType: 'sales',
    availableLanguage: ['en', 'tr', 'de', 'fr', 'es', 'ru', 'ar'],
  },
  address: {
    '@type': 'PostalAddress',
    streetAddress: 'Emniyettepe Mah. Ahikent Sitesi Selam Sok. No: 12',
    addressLocality: 'Eyüpsultan',
    addressRegion: 'İstanbul',
    addressCountry: 'TR',
  },
  foundingDate: SITE.foundingDate,
  slogan: SITE.tagline,
  areaServed: 'Worldwide',
  knowsAbout: ['Padel courts', 'Panoramic padel courts', 'Padel court canopies', 'Artificial turf for padel', 'Padbol courts', 'Pickleball courts'],
  // Factory and sales offices, so answer engines can tie the brand to its real locations.
  location: [
    { '@type': 'Place', name: SITE.factory.name, address: SITE.factory.address },
    ...SITE.offices.map((o) => ({ '@type': 'Place', name: `Unixpadel ${o.country}`, address: `${o.address}, ${o.country}` })),
  ],
});

export const webSite = (lang: string = 'en') => ({
  '@context': 'https://schema.org',
  '@type': 'WebSite',
  '@id': `${SITE.url}/#website`,
  name: SITE.name,
  url: lang === 'en' ? SITE.url : `${SITE.url}/${lang}/`,
  inLanguage: lang,
  publisher: { '@id': `${SITE.url}/#organization` },
});

export const breadcrumbList = (crumbs: Crumb[], site: URL | undefined) => ({
  '@context': 'https://schema.org',
  '@type': 'BreadcrumbList',
  itemListElement: crumbs.map((c, i) => ({
    '@type': 'ListItem',
    position: i + 1,
    name: c.label,
    item: new URL(c.url, site).href,
  })),
});

export const faqPage = (faq: { q: string; a: string }[]) => ({
  '@context': 'https://schema.org',
  '@type': 'FAQPage',
  mainEntity: faq.map(({ q, a }) => ({
    '@type': 'Question',
    name: q,
    acceptedAnswer: { '@type': 'Answer', text: a.replace(/\[([^\]]+)\]\([^)]*\)/g, '$1').replace(/\*\*/g, '') },
  })),
});

const imageOf = (p: Page) =>
  mediaUrl(p.seo.ogImage ?? p.hero?.poster ?? p.hero?.image ?? p.media.find((m) => m.type === 'image')?.src ?? null);

export const product = (p: Page, category = 'Padel court') => ({
  '@context': 'https://schema.org',
  '@type': 'Product',
  name: p.title,
  description: p.seo.description ?? undefined,
  image: imageOf(p) ?? undefined,
  url: new URL(p.url, SITE.url).href,
  brand: { '@type': 'Brand', name: SITE.name },
  manufacturer: { '@id': `${SITE.url}/#organization` },
  category,
});

export const article = (p: Page) => ({
  '@context': 'https://schema.org',
  '@type': 'Article',
  headline: p.title,
  description: p.seo.description ?? undefined,
  image: imageOf(p) ?? undefined,
  url: new URL(p.url, SITE.url).href,
  datePublished: (p.published ?? p.lastmod)?.toISOString(),
  dateModified: p.lastmod?.toISOString(),
  author: { '@id': `${SITE.url}/#organization` },
  publisher: { '@id': `${SITE.url}/#organization` },
});

export const service = (p: Page) => ({
  '@context': 'https://schema.org',
  '@type': 'Service',
  name: p.title,
  description: p.seo.description ?? undefined,
  image: imageOf(p) ?? undefined,
  url: new URL(p.url, SITE.url).href,
  serviceType: p.title,
  provider: { '@id': `${SITE.url}/#organization` },
  areaServed: 'Worldwide',
});

/** Hub pages: the page itself plus the ordered list of what it links to. */
export const collectionPage = (p: Page, items: { name: string; url: string }[]) => ({
  '@context': 'https://schema.org',
  '@type': 'CollectionPage',
  name: p.title,
  description: p.seo.description ?? undefined,
  url: new URL(p.url, SITE.url).href,
  isPartOf: { '@id': `${SITE.url}/#website` },
  mainEntity: {
    '@type': 'ItemList',
    numberOfItems: items.length,
    itemListElement: items.map((it, i) => ({ '@type': 'ListItem', position: i + 1, name: it.name, url: new URL(it.url, SITE.url).href })),
  },
});

/** ContactPage / AboutPage wrapper that points at the Organization node. */
export const webPage = (type: 'ContactPage' | 'AboutPage', p: Page) => ({
  '@context': 'https://schema.org',
  '@type': type,
  name: p.title,
  description: p.seo.description ?? undefined,
  url: new URL(p.url, SITE.url).href,
  isPartOf: { '@id': `${SITE.url}/#website` },
  about: { '@id': `${SITE.url}/#organization` },
});
