// Project globe data for the /references/ hub, built from src/data/reference-map.json (the file behind the old
// WordPress globe). The raw list (2,000+ "<City> Padel Project N" entries) never reaches the browser: entries are
// grouped per city here at build time and only the compact result is inlined in the page.
import RAW from '../data/reference-map.json';
import { localizePath, t, type Lang } from '../i18n';
import { formatCount, regionName } from '../i18n/data';
import { countrySlug } from './countries';
import { menuLabelOf } from './references';

type RawEntry = { title: string; country: string; products: string[]; lat: number; lng: number };

/** Raw country names in the JSON → ISO 3166 code + the English name the site uses. */
export const COUNTRIES: Record<string, [iso2: string, en: string]> = {
  Angola: ['AO', 'Angola'], Argentina: ['AR', 'Argentina'], Azerbaijan: ['AZ', 'Azerbaijan'], Bahrain: ['BH', 'Bahrain'],
  Belarus: ['BY', 'Belarus'], Bulgaria: ['BG', 'Bulgaria'], Canada: ['CA', 'Canada'], 'Cape Verde': ['CV', 'Cape Verde'],
  Chile: ['CL', 'Chile'], Denmark: ['DK', 'Denmark'], England: ['GB', 'United Kingdom'], Estonia: ['EE', 'Estonia'],
  Finland: ['FI', 'Finland'], France: ['FR', 'France'], Germany: ['DE', 'Germany'], Ghana: ['GH', 'Ghana'],
  Greece: ['GR', 'Greece'], Iceland: ['IS', 'Iceland'], India: ['IN', 'India'], Indonesia: ['ID', 'Indonesia'],
  Iran: ['IR', 'Iran'], Italy: ['IT', 'Italy'], 'Ivory Coast': ['CI', 'Côte d’Ivoire'], Jordan: ['JO', 'Jordan'],
  Kenya: ['KE', 'Kenya'], Kosova: ['XK', 'Kosovo'], Kuwait: ['KW', 'Kuwait'], Kyrgyzstan: ['KG', 'Kyrgyzstan'],
  Latvia: ['LV', 'Latvia'], Lesotho: ['LS', 'Lesotho'], Libya: ['LY', 'Libya'], Lithuania: ['LT', 'Lithuania'],
  Malaysia: ['MY', 'Malaysia'], Maldives: ['MV', 'Maldives'], Mexico: ['MX', 'Mexico'], Moldova: ['MD', 'Moldova'],
  Monaco: ['MC', 'Monaco'], Montenegro: ['ME', 'Montenegro'], Morocco: ['MA', 'Morocco'], Namibia: ['NA', 'Namibia'],
  Netherlands: ['NL', 'Netherlands'], 'New Zeland': ['NZ', 'New Zealand'], Nigeria: ['NG', 'Nigeria'], Norway: ['NO', 'Norway'],
  Oman: ['OM', 'Oman'], Pakistan: ['PK', 'Pakistan'], Palestine: ['PS', 'Palestine'], Panama: ['PA', 'Panama'],
  Peru: ['PE', 'Peru'], Philippines: ['PH', 'Philippines'], Portugal: ['PT', 'Portugal'], Qatar: ['QA', 'Qatar'],
  Romania: ['RO', 'Romania'], Russia: ['RU', 'Russia'], 'San Marino': ['SM', 'San Marino'], 'Saudi Arabia': ['SA', 'Saudi Arabia'],
  Singapore: ['SG', 'Singapore'], Slovakia: ['SK', 'Slovakia'], Slovenia: ['SI', 'Slovenia'], 'South Africa': ['ZA', 'South Africa'],
  Spain: ['ES', 'Spain'], Sweden: ['SE', 'Sweden'], Tanzania: ['TZ', 'Tanzania'], Tunisia: ['TN', 'Tunisia'],
  Turkiye: ['TR', 'Türkiye'], UAE: ['AE', 'United Arab Emirates'], Uganda: ['UG', 'Uganda'], Ukraine: ['UA', 'Ukraine'],
  USA: ['US', 'USA'], Venezuela: ['VE', 'Venezuela'], Zambia: ['ZM', 'Zambia'],
};

/** Product names in the JSON → the product page they link to (fixed order = tie-break order in the mix). */
const PRODUCTS: [name: string, url: string][] = [
  ['Full Panoramic Court', '/full-panoramic-padel-court/'],
  ['Single Court', '/single-padel-court/'],
  ['Standard Court', '/standard-padel-court/'],
  ['Flat Floor', '/innovative-courts/flat-floor-padel-court/'],
  ['Floating Padel Court', '/floating-floor-padel-court/'],
  ['Roof Top Canopy Court', '/roof-top-canopy/'],
  ['Half Close Canopy', '/half-close-canopy/'],
  ['Canopy', '/canopy/'],
];

const norm = (s: string) => s.toLowerCase().replace(/[^\p{L}\p{N}]+/gu, '');

type Group = { raw: string; place: string; entries: RawEntry[] };

/** One coordinate per group: the most frequent point (2 decimals); ties go to the most central of them. */
function anchor(entries: RawEntry[]): [number, number] {
  const counts = new Map<string, number>();
  for (const e of entries) {
    const k = `${e.lng.toFixed(2)},${e.lat.toFixed(2)}`;
    counts.set(k, (counts.get(k) ?? 0) + 1);
  }
  const max = Math.max(...counts.values());
  const tied = [...counts].filter(([, n]) => n === max).map(([k]) => k.split(',').map(Number) as [number, number]);
  const spread = ([x, y]: [number, number]) => entries.reduce((s, e) => s + (e.lng - x) ** 2 + (e.lat - y) ** 2, 0);
  return tied.sort((a, b) => spread(a) - spread(b))[0];
}

export type GlobeData = {
  /** inlined for the client script */
  json: string;
  /** server-rendered country <select> options: label + index of the country's biggest group */
  options: { label: string; group: number }[];
  projects: number;
  countries: number;
  caption: string;
  label: string;
};

const cache = new Map<string, Promise<GlobeData>>();

/**
 * Globe payload in the page language. `stories` maps the English country names that have reference stories on
 * the hub (lib/countries.ts) to their count, so the pin card can offer "View <country> references".
 */
export function globeDataFor(lang: Lang, stories: ReadonlyMap<string, number>): Promise<GlobeData> {
  const key = `${lang}|${[...stories].join(',')}`;
  let hit = cache.get(key);
  if (!hit) cache.set(key, (hit = build(lang, stories)));
  return hit;
}

async function build(lang: Lang, stories: ReadonlyMap<string, number>): Promise<GlobeData> {
  const ui = t(lang);
  const raw = RAW as RawEntry[];
  const products = await Promise.all(PRODUCTS.map(async ([, url]) => [await menuLabelOf(url, lang), localizePath(url, lang)] as [string, string]));
  const productIndex = new Map(PRODUCTS.map(([name], i) => [name, i]));

  const groups = new Map<string, Group>();
  for (const e of raw) {
    if (!COUNTRIES[e.country]) throw new Error(`reference-map.json: unknown country "${e.country}" (${e.title})`);
    for (const p of e.products) if (!productIndex.has(p)) throw new Error(`reference-map.json: unknown product "${p}" (${e.title})`);
    if (!Number.isFinite(e.lat) || !Number.isFinite(e.lng)) throw new Error(`reference-map.json: bad coordinates (${e.title})`);
    const place = e.title.replace(/\s+Padel Project\s+\d+\s*$/i, '').trim();
    const k = `${e.country}|${place}`;
    let g = groups.get(k);
    if (!g) groups.set(k, (g = { raw: e.country, place, entries: [] }));
    g.entries.push(e);
  }

  const collator = new Intl.Collator(lang === 'en' ? 'en-GB' : lang);
  const countryLabel = (rawName: string) => regionName(COUNTRIES[rawName][1], COUNTRIES[rawName][0], lang);
  const sorted = [...groups.values()].sort(
    (a, b) => collator.compare(countryLabel(a.raw), countryLabel(b.raw)) || b.entries.length - a.entries.length || collator.compare(a.place, b.place),
  );

  const countryRows: [string, string | null, string | null, number][] = [];
  const countryAt = new Map<string, number>();
  const rows = sorted.map((g, gi) => {
    const [, en] = COUNTRIES[g.raw];
    let ci = countryAt.get(g.raw);
    if (ci === undefined) {
      const label = countryLabel(g.raw);
      const hasStories = (stories.get(en) ?? 0) > 0;
      countryAt.set(g.raw, (ci = countryRows.length));
      countryRows.push([label, hasStories ? countrySlug(en) : null, hasStories ? ui.globeShowCountry(label) : null, gi]);
    }
    const mix = new Map<number, number>();
    for (const e of g.entries) for (const p of e.products) mix.set(productIndex.get(p)!, (mix.get(productIndex.get(p)!) ?? 0) + 1);
    const flatMix = [...mix].sort((a, b) => b[1] - a[1] || a[0] - b[0]).flat();
    const [lng, lat] = anchor(g.entries);
    const isCountryOnly = [norm(g.raw), norm(en)].includes(norm(g.place));
    return [lng, lat, ci, isCountryOnly ? null : g.place, g.entries.length, ui.nProjects(g.entries.length), flatMix] as const;
  });

  const total = rows.reduce((s, r) => s + r[4], 0);
  if (total !== raw.length) throw new Error(`reference-map: grouped ${total} of ${raw.length} entries`);

  const json = JSON.stringify({ v: 1, p: products, c: countryRows, g: rows }).replace(/</g, '\\u003c');
  if (json.length > 16_000) throw new Error(`reference-map: inline payload grew to ${json.length} bytes`);

  const projects = formatCount(raw.length, lang);
  const countries = formatCount(countryRows.length, lang);
  return {
    json,
    options: countryRows.map(([label, , , group]) => ({ label, group })),
    projects: raw.length,
    countries: countryRows.length,
    caption: ui.globeCaption(projects, countries),
    label: ui.globeLabel(projects, countries),
  };
}
