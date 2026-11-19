// Product catalogue flipbook (/catalog/). The catalogue must not be downloadable: there is no PDF anywhere, and the page
// files are shuffled tiles that only the viewer puts back (scripts/build-catalog-pages.py writes them and
// catalog-pack.json; the WordPress plugin fetches them from the GitHub release `catalog-<dir>`, see
// wp-plugin/includes/catalog-pack.php). Folder, salt and grids live in catalog-pack.json.
import PACK from './catalog-pack.json';

export const CATALOG = {
  /**
   * false: the header shows a disabled "Open Catalog (Soon)" button and the page stays a hidden preview.
   * To go live set this to true AND remove `pendingReview: true` from catalog.md in src/content/pages and every pages-<lang>
   * (that flag is what keeps the page noindex and out of the sitemap).
   */
  enabled: false,
  url: '/catalog/',
  /** Number of A4 leaves and their proportions (reading size). */
  pages: PACK.pages,
  size: { width: PACK.variants.view.width, height: PACK.variants.view.height },
  /** One entry per PDF spread (two leaves each), in reading order: the accessible names of the pages. */
  spreads: [
    'Standard Court',
    'Panoramic Court',
    'Full Panoramic Court',
    'Single Standard Court',
    'Single Panoramic Court',
    'Single Full Panoramic Court',
    'Standard Plate Floor Court',
    'Panoramic Plate Floor Court',
    'Full Panoramic Plate Floor Court',
    'Single Standard Plate Floor Court',
    'Single Panoramic Plate Floor Court',
    'Single Full Panoramic Plate Floor Court',
    'Standard Flat Floor Court',
    'Panoramic Flat Floor Court',
    'Full Panoramic Flat Floor Court',
    'Single Standard Flat Floor Court',
    'Single Panoramic Flat Floor Court',
    'Floating Floor Court',
    'Portable Court',
    'Mobil Fold Court',
    'WP Challenge Court',
    'Storm Padel Court',
    'Padel Court with Canopy',
    'Roof Top Canopy',
    'Single Profile Truss System Canopy',
    'Double Profile Truss System Canopy',
    'Sandwich Panel Canopy',
    'Retractable Roof Canopy',
    'Wheel Canopy System',
    'MobilFOLD X-TEND Court',
    'Lighting System',
    'Turf Types',
  ],
} as const;
