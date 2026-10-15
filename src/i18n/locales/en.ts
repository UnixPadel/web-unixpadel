// English: the source data in src/data/* expressed as a locale, so every language goes through the same code path.
import { UI_EN } from '../ui';
import type { LocaleData } from '../types';
import { HOME } from '../../data/home';
import { PRODUCTS, PRODUCT_FAMILY } from '../../data/products';
import { DELIVERED } from '../../data/showcase';
import { SEALS } from '../../data/seals';
import { SITE } from '../../data/site';

const en: LocaleData = {
  ui: UI_EN,
  homeUi: {
    seoTitle: 'Padel court manufacturer | Unixpadel',
    explore: 'Explore the courts',
    rangeEyebrow: 'Court range',
    rangeTitle: 'Six court structures. <mark class="hl">One engineering standard.</mark>',
    rangeText: 'Every model shares the same hot-dip galvanised steel, EN 12150-1 tempered glass and FIP-compliant dimensions. Pick the footprint and the view you need.',
    allCourts: 'All padel courts',
    padbol: 'Padbol courts',
    pickleball: 'Pickleball',
    deliveredEyebrow: 'Delivered',
    deliveredTitle: '7,800 courts in 94 countries. <mark class="hl">These are from this year.</mark>',
    deliveredText: 'Clubs, resorts and municipalities from the Gulf to the Balkans. Every court here was manufactured in Adıyaman and installed by our teams.',
    systemsEyebrow: 'Innovative systems',
    systemsTitle: 'Courts that need no <mark class="hl">concrete slab</mark>, no crane and no season.',
    seeThe: (label) => `See the ${label.replace(/padel court/i, 'court')}`,
    whyEyebrow: 'Why Unixpadel',
    whyTitle: 'What buyers check <mark class="hl">before they sign</mark>',
    factoryAria: 'Inside the Adıyaman factory',
    factoryEyebrow: 'Manufacturing',
    perDay: 'courts / day',
    capacity: 'Production capacity',
    services: 'Services',
    refsEyebrow: 'References',
    refsTitle: 'Recently delivered',
    allProjects: 'All projects',
    guideEyebrow: 'For investors and architects',
    guideTitle: 'Plan the project before you price it',
    openGuide: 'Open the guide',
  },
  home: {
    hero: HOME.hero,
    courtSpecs: HOME.courts.map((c) => c.spec),
    systems: HOME.systems.map((s) => ({ eyebrow: s.eyebrow, title: s.title, text: s.text })),
    why: HOME.why.map((w) => ({ title: w.title, text: w.text })),
    factory: { title: HOME.factory.title, text: HOME.factory.text, services: HOME.factory.services.map((s) => s.label) },
  },
  products: PRODUCTS,
  family: PRODUCT_FAMILY,
  delivered: Object.fromEntries(DELIVERED.map((d) => [d.href, { alt: d.alt, label: d.label }])),
  seals: Object.fromEntries(SEALS.map((s) => [s.mark, { ring: s.ring, label: s.label }])),
  stats: SITE.stats.map((s) => s.label),
  hq: SITE.hq.name,
  factory: SITE.factory.name,
  countries: {},
  dims: { notes: {}, captions: {}, values: {} },
  specKinds: {
    dimensions: 'Playing area',
    steel: 'Steel profile',
    glass: 'Safety glass',
    mesh: 'Mesh panels',
    lighting: 'Lighting',
    turf: 'Turf',
    anchor: 'Anchoring',
    finish: 'Finish',
    color: 'Colours',
    power: 'Power',
    compliance: 'Compliance',
    load: 'Load capacity',
    temperature: 'Temperature range',
  },
};

export default en;
