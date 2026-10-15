// Court plan dimensions for the SpecDiagram, by product URL. Values come from the product pages' own text
// (e.g. "20m x 10m", "20 x 6 m", "10x6m", "6.10m x 13.41m") and the FIP / USA Pickleball standards those pages cite.

export type CourtDims = {
  length: number; // m
  width: number; // m
  lines: 'padel' | 'pickleball' | 'none';
  /** distance of the service / non-volley line from the net, m */
  serviceFromNet?: number;
  notes: { label: string; value: string }[];
  /** true when the page itself doesn't state the size and the diagram shows the FIP standard */
  standardOnly?: boolean;
  caption: string;
};

const PADEL_DOUBLES: Omit<CourtDims, 'caption'> = {
  length: 20,
  width: 10,
  lines: 'padel',
  serviceFromNet: 6.95,
  notes: [
    { label: 'Playing area', value: '20 × 10 m' },
    { label: 'Service line', value: '6.95 m from the net' },
    { label: 'Back walls', value: '3 m glass + 1 m mesh' },
    { label: 'Net height', value: '0.88 m centre · 0.92 m posts' },
  ],
};

const PADEL_SINGLE: Omit<CourtDims, 'caption'> = {
  length: 20,
  width: 6,
  lines: 'padel',
  serviceFromNet: 6.95,
  notes: [
    { label: 'Playing area', value: '20 × 6 m' },
    { label: 'Service line', value: '6.95 m from the net' },
    { label: 'Back walls', value: '3 m glass + 1 m mesh' },
    { label: 'Net height', value: '0.88 m centre · 0.92 m posts' },
  ],
};

const PADBOL: CourtDims = {
  length: 10,
  width: 6,
  lines: 'none',
  notes: [{ label: 'Playing area', value: '10 × 6 m' }],
  caption: 'Padbol court, plan view',
};

const doubles = (caption = 'FIP doubles court, plan view') => ({ ...PADEL_DOUBLES, caption });
const single = (caption = 'Single (1 vs 1) court, plan view') => ({ ...PADEL_SINGLE, caption });

export const COURT_DIMS: Record<string, CourtDims> = {
  '/standard-padel-court/': doubles(),
  '/panoramic-padel-court/': doubles(),
  '/full-panoramic-padel-court/': doubles(),
  '/wp-challenge-padel-court/': doubles(),
  '/portable-padel-court/': { ...doubles('FIP standard doubles court, plan view'), standardOnly: true },
  '/innovative-courts/flat-floor-padel-court/': doubles(),
  '/floating-floor-padel-court/': { ...doubles('FIP standard doubles court, plan view'), standardOnly: true },
  '/plate-standard-padel-court/': doubles(),
  '/panaromic-plate-padel-court/': doubles(),
  '/single-padel-court/': single(),
  '/single-panoramic-padel-court/': single(),
  '/single-full-panoramic-padel-court/': single(),
  // the padbol models state "10x6 meter play area" in their own Dimensions spec
  '/padbols/': PADBOL,
  '/standard-padbol/': PADBOL,
  '/panoramic-padbol/': PADBOL,
  '/full-panoramic-padbol/': PADBOL,
  '/portable-padbol/': PADBOL,
  '/mobilefold-padbol/': PADBOL,
  '/our-courts/pickleball-court/': {
    length: 13.41,
    width: 6.1,
    lines: 'pickleball',
    serviceFromNet: 2.13,
    notes: [
      { label: 'Court', value: '13.41 × 6.10 m (44 × 20 ft)' },
      { label: 'Recommended area', value: '18.28 × 9.14 m' },
      { label: 'Non-volley zone', value: '2.13 m from the net' },
      { label: 'Net height', value: '0.86 m centre · 0.91 m sides' },
    ],
    caption: 'Pickleball court, plan view',
  },
};
