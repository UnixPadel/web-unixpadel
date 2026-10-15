// Hand-authored site data (src/data/*) in the visitor's language. English stays the source in src/data; each
// locale file (src/i18n/locales/<lang>.ts) supplies its overrides keyed the same way.
import { SITE } from '../data/site';
import { HOME } from '../data/home';
import { PRODUCTS, type ProductMeta } from '../data/products';
import { DELIVERED, type ShowcaseItem } from '../data/showcase';
import { SEALS } from '../data/seals';
import { COURT_DIMS, type CourtDims } from '../data/courtDims';
import { LOCALES } from './locales';
import { LOCALE, type Lang } from './index';

/** Home page copy merged onto HOME (URLs, icons and media stay English-side). */
export function homeFor(lang: Lang) {
  const h = LOCALES[lang].home;
  return {
    ...HOME,
    hero: { ...HOME.hero, ...h.hero },
    courts: HOME.courts.map((c, i) => ({ ...c, spec: h.courtSpecs[i] ?? c.spec })),
    systems: HOME.systems.map((s, i) => ({ ...s, ...h.systems[i] })),
    why: HOME.why.map((w, i) => ({ ...w, ...h.why[i] })),
    factory: {
      ...HOME.factory,
      title: h.factory.title,
      text: h.factory.text,
      services: HOME.factory.services.map((s, i) => ({ ...s, label: h.factory.services[i] ?? s.label })),
    },
  };
}

export const homeUiFor = (lang: Lang) => LOCALES[lang].homeUi;

export const productMetaFor = (url: string, lang: Lang): ProductMeta => ({ ...PRODUCTS[url], ...LOCALES[lang].products[url] });

export const productFamilyFor = (url: string, lang: Lang): string | undefined => LOCALES[lang].family[url];

export const deliveredFor = (lang: Lang): ShowcaseItem[] => DELIVERED.map((d) => ({ ...d, ...LOCALES[lang].delivered[d.href] }));

export const sealsFor = (lang: Lang) => SEALS.map((s) => ({ ...s, ...LOCALES[lang].seals[s.mark] }));

/** Country name (English key from lib/countries.ts or SITE.offices) in the page language. */
export const countryName = (name: string | null, lang: Lang): string | null => (name ? (LOCALES[lang].countries[name] ?? name) : name);

// Region names ICU gets wrong for this site ("Palestinian Territories").
const REGION_NAMES: Record<string, Partial<Record<Lang, string>>> = {
  PS: { tr: 'Filistin', de: 'Palästina', fr: 'Palestine', ar: 'فلسطين', ru: 'Палестина' },
};
const regionNames: Partial<Record<Lang, Intl.DisplayNames>> = {};

/**
 * Country name for any ISO 3166 code: the site's own translation of the English name when it has one,
 * else the build's ICU region name. English keeps the English name the site uses ("USA", "Türkiye").
 */
export function regionName(en: string, iso2: string, lang: Lang): string {
  const own = LOCALES[lang].countries[en];
  if (own) return own;
  if (lang === 'en') return en;
  if (REGION_NAMES[iso2]?.[lang]) return REGION_NAMES[iso2][lang]!;
  return (regionNames[lang] ??= new Intl.DisplayNames([LOCALE[lang].intl], { type: 'region' })).of(iso2) ?? en;
}

/** Whole number with the page language's thousands separator (same rule as the stats). */
export const formatCount = (n: number, lang: Lang): string => {
  const sep = LOCALES[lang].thousands;
  return String(n).replace(/\B(?=(\d{3})+(?!\d))/g, sep ?? ',');
};

export function siteFor(lang: Lang) {
  const l = LOCALES[lang];
  const figure = (v: string) => (l.thousands ? v.replace(/(\d),(\d{3})/g, `$1${l.thousands}$2`) : v);
  return {
    stats: SITE.stats.map((s, i) => ({ value: figure(s.value), label: l.stats[i] ?? s.label })),
    hq: { ...SITE.hq, name: l.hq },
    factory: { ...SITE.factory, name: l.factory },
    offices: SITE.offices.map((o) => ({ ...o, country: countryName(o.country, lang)! })),
  };
}

export function courtDimsFor(url: string, lang: Lang): CourtDims | undefined {
  const d = COURT_DIMS[url];
  if (!d) return d;
  const { notes, captions, values } = LOCALES[lang].dims;
  return {
    ...d,
    caption: captions[d.caption] ?? d.caption,
    notes: d.notes.map((n) => ({ label: notes[n.label] ?? n.label, value: values[n.value] ?? n.value })),
  };
}
