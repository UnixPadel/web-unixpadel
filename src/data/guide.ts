// Padel guide structure: the reading path (groups in order), related articles and the call to action each group leads to.
// Group titles and CTA copy live in src/i18n (ui.guideGroups / ui.guideCta); article order in the hub follows this file.

export type GuideGroup = 'basics' | 'plan' | 'materials' | 'operate';

export const GUIDE_GROUPS: { id: GuideGroup; urls: string[] }[] = [
  {
    id: 'basics',
    urls: ['/padel-guide/what-is-padel/', '/padel-guide/padel-court-dimensions/', '/padel-guide/ideal-ceiling-height-padel-court/'],
  },
  {
    id: 'plan',
    urls: ['/padel-guide/padel-court-design-factors/', '/padel-guide/how-to-build-a-padel-court/', '/padel-guide/how-much-does-a-padel-court-cost/'],
  },
  {
    id: 'materials',
    urls: [
      '/padel-guide/padel-court-surface/',
      '/padel-guide/padel-net-and-mesh-systems-quality-guide/',
      '/padel-guide/steel-types-for-padel-courts/',
    ],
  },
  { id: 'operate', urls: ['/padel-guide/coastal-padel-courts-construction/', '/padel-guide/padel-court-maintenance-tips/'] },
];

/** Reading order across all groups (prev/next links). */
export const GUIDE_ORDER = GUIDE_GROUPS.flatMap((g) => g.urls);

/** Two hand-picked follow-ups per article (topic neighbours, not just the next in line). */
export const GUIDE_RELATED: Record<string, [string, string]> = {
  '/padel-guide/what-is-padel/': ['/padel-guide/padel-court-dimensions/', '/padel-guide/how-much-does-a-padel-court-cost/'],
  '/padel-guide/padel-court-dimensions/': ['/padel-guide/ideal-ceiling-height-padel-court/', '/padel-guide/padel-court-design-factors/'],
  '/padel-guide/ideal-ceiling-height-padel-court/': ['/padel-guide/padel-court-dimensions/', '/padel-guide/padel-court-design-factors/'],
  '/padel-guide/padel-court-design-factors/': ['/padel-guide/how-to-build-a-padel-court/', '/padel-guide/how-much-does-a-padel-court-cost/'],
  '/padel-guide/how-to-build-a-padel-court/': ['/padel-guide/how-much-does-a-padel-court-cost/', '/padel-guide/padel-court-surface/'],
  '/padel-guide/how-much-does-a-padel-court-cost/': ['/padel-guide/how-to-build-a-padel-court/', '/padel-guide/padel-court-design-factors/'],
  '/padel-guide/padel-court-surface/': ['/padel-guide/padel-court-maintenance-tips/', '/padel-guide/how-to-build-a-padel-court/'],
  '/padel-guide/padel-net-and-mesh-systems-quality-guide/': ['/padel-guide/steel-types-for-padel-courts/', '/padel-guide/padel-court-dimensions/'],
  '/padel-guide/steel-types-for-padel-courts/': ['/padel-guide/coastal-padel-courts-construction/', '/padel-guide/padel-net-and-mesh-systems-quality-guide/'],
  '/padel-guide/coastal-padel-courts-construction/': ['/padel-guide/steel-types-for-padel-courts/', '/padel-guide/padel-court-maintenance-tips/'],
  '/padel-guide/padel-court-maintenance-tips/': ['/padel-guide/padel-court-surface/', '/padel-guide/coastal-padel-courts-construction/'],
};

export const guideGroupOf = (url: string): GuideGroup | undefined => GUIDE_GROUPS.find((g) => g.urls.includes(url))?.id;
