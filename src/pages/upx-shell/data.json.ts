// Data the WordPress plugin needs to render references written in WP admin (src/lib/upx-contract.mjs): the country
// and court-type choices with their names in every language, month names and date patterns (so PHP needs no intl
// extension), breadcrumb labels and the pages each language has. Built to dist/upx-shell/data.json.
import type { APIRoute } from 'astro';
import { SITE } from '../../data/site';
import { CONTRACT, HOME_REFS, LLMS_MAX_REFS, MAX_TYPES, VISIBLE } from '../../lib/upx-contract.mjs';
import { LANGS, LOCALE, TRANSLATED, localizePath, t, type Lang } from '../../i18n';
import { countryName, regionName } from '../../i18n/data';
import { COUNTRY_ISO, countrySlug } from '../../lib/countries';
import { COUNTRIES as MAP_COUNTRIES } from '../../lib/referenceMap';
import { COURT_TYPE_URLS, menuLabelOf } from '../../lib/references';
import { cardImage, labelOf, pageByUrl } from '../../lib/collection';
import { MEDIA_ORIGIN, mediaUrl } from '../../lib/media';
import { DEFAULT_ROBOTS } from '../../lib/seo';

// Two-letter codes that are not countries (EU, UN, private-use and exceptional reservations) or are retired codes ICU
// still names after their successor (DD → Germany, SU → Russia, YU → Serbia …).
const NOT_COUNTRIES = /^(AA|AC|CP|DG|EA|EU|EZ|IC|QM|QN|QO|QP|QQ|QR|QS|QT|QU|QV|QW|QX|QY|QZ|TA|UN|X[A-JL-Z]|ZZ|AN|BU|CS|DD|DY|FX|HV|NH|RH|SU|TP|UK|VD|YD|YU|ZR)$/;
const SENTINEL = 987654;

/** English name the site uses → ISO code: the reference countries, the globe's list, then every other ICU region. */
function countryList(): { name: string; iso: string | null }[] {
  // countries.ts names first: they are what static references are tagged with ("USA", "Türkiye").
  const byIso = new Map<string, string>();
  const noIso: string[] = [];
  for (const [name, iso] of Object.entries(COUNTRY_ISO)) (iso ? byIso.set(iso, name) : noIso.push(name));
  for (const [iso, name] of Object.values(MAP_COUNTRIES)) if (!byIso.has(iso)) byIso.set(iso, name);
  const en = new Intl.DisplayNames(['en'], { type: 'region', fallback: 'none' });
  const named = new Set([...byIso.values(), ...noIso].map(countrySlug));
  for (let a = 65; a <= 90; a++)
    for (let b = 65; b <= 90; b++) {
      const iso = String.fromCharCode(a, b);
      if (NOT_COUNTRIES.test(iso) || byIso.has(iso)) continue;
      const name = en.of(iso);
      if (!name || name === iso || named.has(countrySlug(name))) continue;
      named.add(countrySlug(name));
      byIso.set(iso, name);
    }
  return [...[...byIso].map(([iso, name]) => ({ name, iso })), ...noIso.map((name) => ({ name, iso: null }))].sort((x, y) =>
    x.name.localeCompare(y.name, 'en'),
  );
}

/** Month names and the "{m} {y}" pattern for a date style, from ICU (Russian "октябрь 2026 г.", Arabic digits …). */
function monthsOf(lang: Lang, month: 'long' | 'short') {
  const fmt = new Intl.DateTimeFormat(LOCALE[lang].intl, { month, year: 'numeric', timeZone: 'UTC' });
  const names = Array.from({ length: 12 }, (_, m) => fmt.formatToParts(new Date(Date.UTC(2026, m, 15))).find((p) => p.type === 'month')!.value);
  const pattern = fmt
    .formatToParts(new Date(Date.UTC(2026, 0, 15)))
    .map((p) => (p.type === 'month' ? '{m}' : p.type === 'year' ? '{y}' : p.value))
    .join('');
  return { names, pattern };
}

const template = (fn: (n: number) => string) => fn(SENTINEL).replace(String(SENTINEL), '{n}');

export const GET: APIRoute = async () => {
  const countries = countryList();
  const products = [];
  for (const url of COURT_TYPE_URLS) {
    const page = await pageByUrl(url);
    if (page) products.push({ url, image: mediaUrl(cardImage(page)) });
  }

  const l10n: Record<string, unknown> = {};
  for (const lang of LANGS) {
    const ui = t(lang);
    const hub = await pageByUrl('/references/', lang);
    const long = monthsOf(lang, 'long');
    const short = monthsOf(lang, 'short');
    const digits = new Intl.NumberFormat(LOCALE[lang].intl, { useGrouping: false });
    const productNames: Record<string, { label: string; short: string; href: string }> = {};
    for (const { url } of products) {
      const page = await pageByUrl(url, lang);
      productNames[url] = { label: labelOf(page!), short: await menuLabelOf(url, lang), href: localizePath(url, lang) };
    }
    l10n[lang] = {
      locale: { intl: LOCALE[lang].intl, og: LOCALE[lang].og, dir: LOCALE[lang].dir, name: LOCALE[lang].name },
      home: localizePath('/', lang),
      hub: localizePath('/references/', lang),
      crumbs: [
        { label: ui.home, url: localizePath('/', lang) },
        { label: hub ? (hub.data.navLabel ?? labelOf(hub)) : ui.allReferences, url: localizePath('/references/', lang) },
      ],
      countries: Object.fromEntries(countries.map((c) => [countrySlug(c.name), c.iso ? regionName(c.name, c.iso, lang) : countryName(c.name, lang)])),
      products: productNames,
      months: { long: long.names, short: short.names },
      datePattern: { long: long.pattern, short: short.pattern },
      digits: Array.from({ length: 10 }, (_, d) => digits.format(d)),
      ui: { galleryImage: template(ui.galleryImage), other: ui.other, news: ui.news, referenceProject: ui.referenceProject },
      translated: [...TRANSLATED[lang]].sort(),
    };
  }

  const data = {
    contract: CONTRACT,
    site: SITE.url,
    media: MEDIA_ORIGIN, // origin of /wp-content/uploads paths (PUBLIC_MEDIA_ORIGIN)
    langs: LANGS,
    defaults: {
      robots: DEFAULT_ROBOTS,
      ogImage: mediaUrl(SITE.logoRetina),
    },
    limits: { visible: VISIBLE, home: HOME_REFS, llms: LLMS_MAX_REFS, types: MAX_TYPES, title: 60, desc: 158 },
    countries: countries.map((c) => ({ slug: countrySlug(c.name), name: c.name, iso: c.iso })),
    products,
    l10n,
  };
  return new Response(JSON.stringify(data), { headers: { 'Content-Type': 'application/json; charset=utf-8' } });
};
