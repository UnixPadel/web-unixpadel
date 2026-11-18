// Product catalogue flipbook (/catalog/). The page images are cut from the print PDF by
// scripts/build-catalog-pages.py and live in the WordPress media library, like all other media.

export const CATALOG = {
  /**
   * false: the header shows a disabled "Open Catalog (Soon)" button and the page stays a hidden preview.
   * To go live set this to true AND remove `pendingReview: true` from catalog.md in src/content/pages and every pages-<lang>
   * (that flag is what keeps the page noindex and out of the sitemap).
   */
  enabled: false,
  url: '/catalog/',
  /** Upload folder of the page images (WordPress: /wp-content/uploads/<year>/<month>/). */
  mediaBase: '/wp-content/uploads/2026/11/',
  /** File name stem: <prefix>-p01.webp (reading size) and <prefix>-p01-zoom.webp. */
  prefix: 'unixpadel-catalog-2027',
  /** Path of the reduced PDF once it is uploaded (e.g. mediaBase + 'unixpadel-catalog-2027.pdf'); '' hides the button. */
  pdf: '',
  /** Pixel size of the reading and zoom images (A4 leaf). */
  size: { width: 1000, height: 1414 },
  zoomSize: { width: 1697, height: 2400 },
  /** One entry per A4 leaf, in reading order (two per PDF spread). Used for the image alt texts. */
  pages: [
    'MobilFOLD X-TEND Court: overview and covering',
    'MobilFOLD X-TEND: technical specifications',
    'Retractable Roof Canopy: performance and durability',
    'Retractable Roof Canopy: technical specifications',
    'Roof Top Canopy: introduction and membrane options',
    'Rooftop Canopy: structural specifications',
    'Sandwich Panel Canopy: introduction',
    'Sandwich Panel Canopy: technical specifications and performance',
    'WP Challenge: overview and lighting',
    'WP Challenge: steel structure',
    'Double Profile Truss System Canopy: membrane options',
    'Double Profile Truss System Canopy: technical specifications',
    'Wheel Canopy System: membrane covering',
    'Wheel Canopy System: steel structure specifications',
  ],
} as const;

const no = (n: number) => String(n).padStart(2, '0');
/** Site-relative path of a page image (1-based page number). */
export const catalogPagePath = (n: number, zoom = false) => `${CATALOG.mediaBase}${CATALOG.prefix}-p${no(n)}${zoom ? '-zoom' : ''}.webp`;
