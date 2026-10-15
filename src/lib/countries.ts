// Country tag for reference projects, derived from the title/slug (English or Turkish wording); returns the
// English name, which i18n/data.ts countryName() translates. Order matters (first match wins).
const RULES: [string, RegExp][] = [
  ['Türkiye', /t[uü]rk(ey|iye)|antalya|bodrum|marmaris|[cç]e[sş]me|diyarbak[iı]r|kilyos|belek|izmir|istanbul|bosphorus|adıyaman/i],
  ['Germany', /german|almanya|w[uü]rzburg|cologne|fsb/i],
  ['Austria', /austria|avusturya|viyana|vienna|hagenberg/i],
  ['Bosnia & Herzegovina', /bosnia|bosna|saraybosna|sarajevo/i],
  ['Italy', /ital|italya|bari|vicenza|vincenza/i],
  ['Greece', /greece|yunanistan|halkida|chalkida/i],
  ['Romania', /romania|romanya/i],
  ['Denmark', /denmark|danimarka/i],
  ['United Kingdom', /england|ingiltere|birleşik krallık|\buk\b|united kingdom/i],
  ['Netherlands', /netherlands|hollanda|holland/i],
  ['Lebanon', /lebanon|lübnan/i],
  ['Azerbaijan', /azerbaijan|azerbaycan|bakü|baku/i],
  ['Saudi Arabia', /saudi|suudi|dammam/i],
  ['Oman', /oman|umman/i],
  ['Northern Cyprus', /cyprus|kıbrıs/i],
  ['South Africa', /south[\s-]africa|güney afrika/i],
  ['USA', /\busa\b|united states|abd|amerika|virginia/i],
];

/** ISO 3166 code of each name above (none for Northern Cyprus): region names in other languages, WordPress country list. */
export const COUNTRY_ISO: Record<string, string | null> = {
  Türkiye: 'TR', Germany: 'DE', Austria: 'AT', 'Bosnia & Herzegovina': 'BA', Italy: 'IT', Greece: 'GR', Romania: 'RO',
  Denmark: 'DK', 'United Kingdom': 'GB', Netherlands: 'NL', Lebanon: 'LB', Azerbaijan: 'AZ', 'Saudi Arabia': 'SA',
  Oman: 'OM', 'Northern Cyprus': null, 'South Africa': 'ZA', USA: 'US',
};

/** URL-safe key of an English country name for the hub's ?country= filter ("Bosnia & Herzegovina" → "bosnia-herzegovina"). */
export const countrySlug = (en: string): string =>
  en.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');

export function countryOf(text: string): string | null {
  for (const [name, re] of RULES) if (re.test(text)) return name;
  return null;
}
