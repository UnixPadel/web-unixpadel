// Per-product presentation data that the scraped pages don't carry in a usable form:
// a one-line spec for cards and a real installation photo (square, from the site's own references).
// Photos are only listed where the image actually shows that court type.

export type ProductMeta = { spec?: string; photo?: string; photoAlt?: string; photoHref?: string };

const BOSNIA_BLACK = '/wp-content/uploads/2026/09/2-FIP-Approved-Black-Turf-Padel-Courts-Bosnia-HerzegovinaAnother-professional-Unixpadel-pade-1.jpg';

export const PRODUCTS: Record<string, ProductMeta> = {
  '/standard-padel-court/': {
    spec: '20 × 10 m · 3-pillar frame · 10 mm glass',
    photo: '/wp-content/uploads/2026/08/greece_ref-3.jpg',
    photoAlt: 'Aerial view of three Unixpadel Standard Courts in Chalkida, Greece',
    photoHref: '/references/standard-padel-court-greece-chalkida/',
  },
  '/panoramic-padel-court/': {
    spec: 'Pillar-free back walls · 360° spectator view',
    photo: BOSNIA_BLACK,
    photoAlt: 'Panoramic Unixpadel courts with FIP-approved black turf in Bosnia & Herzegovina',
    photoHref: '/references/black-turf-padel-courts-bosnia/',
  },
  '/full-panoramic-padel-court/': { spec: 'All-glass perimeter · tournament look' },
  '/single-padel-court/': { spec: '20 × 6 m · compact footprint' },
  '/single-panoramic-padel-court/': { spec: '20 × 6 m · pillar-free glass' },
  '/single-full-panoramic-padel-court/': { spec: '20 × 6 m · all-glass · 12 mm' },
  '/portable-padel-court/': { spec: 'No anchoring · beaches, rooftops, events' },
  '/wp-challenge-padel-court/': { spec: 'Reinforced for high wind and impact loads' },
  '/padel-court-cover/': { spec: 'Canopy built for a single court' },
  '/innovative-courts/flat-floor-padel-court/': {
    spec: 'Steel base · no concrete foundation',
    photo: '/wp-content/uploads/2026/08/viennapadelcourtunixpadel3.jpg',
    photoAlt: 'Unixpadel flat floor padel court installed in Vienna, Austria',
    photoHref: '/references/flat-floor-padel-court-vienna/',
  },
  '/floating-floor-padel-court/': { spec: 'Modular sub-floor · −30 °C to 70 °C' },
  '/plate-standard-padel-court/': { spec: 'Steel anchor plates · no deep foundation' },
  '/panaromic-plate-padel-court/': { spec: 'Panoramic glass on a plate base' },
  '/mobilfold-padel-court/': { spec: 'Folds for transport · sets up in hours' },
};

export const productMeta = (url: string): ProductMeta => PRODUCTS[url] ?? {};

/**
 * Hero eyebrow for products whose parent is not a product hub (root pages and orphans).
 * Products under a hub show the hub's name instead.
 */
export const PRODUCT_FAMILY: Record<string, string> = {
  '/padbols/': 'Padbol',
  '/our-courts/pickleball-court/': 'Pickleball',
  '/anti-wind-padel-court/': 'Padel Courts',
  '/full-isolated-padel-court-house/': 'Canopy Systems',
};
