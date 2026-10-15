// Shape of one language's hand-authored copy (src/i18n/locales/<lang>.ts). English data stays the source in
// src/data/*; every other language supplies its interface strings plus overrides keyed like the English data.
import type { UI } from './ui';
import type { SpecKind } from '../lib/specs';

export type HomeUi = {
  seoTitle: string;
  explore: string;
  rangeEyebrow: string;
  /** may contain <mark class="hl">…</mark> */
  rangeTitle: string;
  rangeText: string;
  allCourts: string;
  padbol: string;
  pickleball: string;
  deliveredEyebrow: string;
  deliveredTitle: string;
  deliveredText: string;
  systemsEyebrow: string;
  systemsTitle: string;
  seeThe: (label: string) => string;
  whyEyebrow: string;
  whyTitle: string;
  factoryAria: string;
  factoryEyebrow: string;
  perDay: string;
  capacity: string;
  services: string;
  refsEyebrow: string;
  refsTitle: string;
  allProjects: string;
  guideEyebrow: string;
  guideTitle: string;
  openGuide: string;
};

/** Home page copy in the order of HOME (src/data/home.ts): same number of courts, systems, reasons and services. */
export type HomeCopy = {
  hero: { eyebrow: string; title: string; tagline: string };
  courtSpecs: string[];
  systems: { eyebrow?: string; title: string; text: string }[];
  why: { title: string; text: string }[];
  factory: { title: string; text: string; services: string[] };
};

export type LocaleData = {
  ui: UI;
  homeUi: HomeUi;
  home: HomeCopy;
  /** by product URL (src/data/products.ts) */
  products: Record<string, { spec?: string; photoAlt?: string }>;
  /** hero eyebrow by product URL (PRODUCT_FAMILY) */
  family: Record<string, string>;
  /** photo band items by href (src/data/showcase.ts) */
  delivered: Record<string, { alt: string; label: string }>;
  /** certification seals by mark (src/data/seals.ts) */
  seals: Record<string, { ring: string; label: string }>;
  /** SITE.stats labels, same order */
  stats: string[];
  /** thousands separator for the stat figures ("7,800+" → "7.800+") */
  thousands?: string;
  hq: string;
  factory: string;
  /** English country name → local name (references, offices) */
  countries: Record<string, string>;
  /** court plan diagram (src/data/courtDims.ts): English label/caption/value → local */
  dims: { notes: Record<string, string>; captions: Record<string, string>; values: Record<string, string> };
  /** short names of the spec categories (lib/specs.ts) */
  specKinds: Record<Exclude<SpecKind, 'other'>, string>;
};
