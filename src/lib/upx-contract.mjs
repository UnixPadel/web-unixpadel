// Contract between the Astro build and the WordPress plugin for references kept in WP admin (wp-plugin/includes/).
// The build emits "shells": the real reference page, hub/home/product cards and the news list rendered with the
// tokens and markers below. The plugin fills them in at request time, so a reference needs no rebuild. PHP only
// copies these built fragments; it never writes markup that carries design classes. scripts/build-wp-plugin.mjs
// checks that every token and marker is present in the build output and in the PHP sources. Bump CONTRACT when a
// token, marker or field of upx-shell/data.json / migrate.json changes meaning (the plugin compares it with UPX_CONTRACT).
//
// The references of the build itself can be imported into WP admin (upx-shell/migrate.json, Settings → Unixpadel
// Static → Import). Every built card and news item carries its page URL in its marker, so once a reference is
// imported the plugin drops its built copy everywhere (hub, home, product pages, sitemap, llms.txt).

export const CONTRACT = 2;

/** English URL of the reference shell page; /<lang>/upx-shell/reference/ for the other languages. */
export const SHELL_PATH = '/upx-shell/reference/';
export const SHELL_ROBOTS = 'noindex, nofollow';

/** Hub cards shown before "Show all", cards on the home page and per product page, llms.txt references, court types. */
export const VISIBLE = 11;
export const HOME_REFS = 3;
export const PRODUCT_REFS = 3;
export const LLMS_MAX_REFS = 15;
export const MAX_TYPES = 2;
export const BAND_ITEMS = 5;

/** Tokens: "UPX" + capitals only, so Astro's escaping and the /<lang>/ link rewriting leave them alone. */
export const T = {
  // head
  SEO_TITLE: 'UPXSEOTITLE',
  DESC: 'UPXDESC',
  CANONICAL: 'UPXCANONICAL',
  OG_IMAGE: 'UPXOGIMAGE',
  PUBLISHED: 'UPXPUBLISHED',
  MODIFIED: 'UPXMODIFIED',
  // page
  EYEBROW: 'UPXEYEBROW',
  TITLE: 'UPXTITLE',
  TAGLINE: 'UPXTAGLINE',
  HERO_SRC: 'UPXHEROSRC',
  HERO_ALT: 'UPXHEROALT',
  COUNTRY: 'UPXCOUNTRY',
  DATE: 'UPXDATE',
  CT1_HREF: 'UPXCTAHREF',
  CT1_LABEL: 'UPXCTALABEL',
  CT2_HREF: 'UPXCTBHREF',
  CT2_LABEL: 'UPXCTBLABEL',
  // gallery (item tokens are suffixed _0, _1, … per image by the plugin)
  G_N: 'UPXGN',
  G_INDEX: 'UPXGINDEX',
  G_SRC: 'UPXGSRC',
  G_ALT: 'UPXGALT',
  G_RENDER: 'UPXGRENDER',
  G_JSON: 'UPXGJSON',
  // cards
  C_HREF: 'UPXCHREF',
  C_TITLE: 'UPXCTITLE',
  C_SRC: 'UPXCSRC',
  C_ALT: 'UPXCALT',
  C_KEY: 'UPXCKEY',
  C_COUNTRY: 'UPXCCOUNTRY',
  C_TYPES: 'UPXCTYPES',
  C_DATE_ISO: 'UPXCISODATE', // no token is a prefix of another (the plugin matches whole words)
  C_DATE: 'UPXCDATE',
  C_EXCERPT: 'UPXCEXCERPT',
  // company news list item (hub)
  N_HREF: 'UPXNHREF',
  N_TITLE: 'UPXNTITLE',
  N_DATE_ISO: 'UPXNISODATE',
  N_DATE: 'UPXNDATE',
};

/** Language switcher target per language: UPXLANGEN, UPXLANGTR, … */
export const langToken = (lang) => `UPXLANG${lang.toUpperCase()}`;

/** "More projects" band tile i (0–4) on the reference shell: UPXBSRCA, UPXBALTA, UPXBLABELA, UPXBHREFA, … */
export const bandToken = (field, i) => `UPXB${field}${'ABCDE'[i]}`;
export const BAND_FIELDS = ['SRC', 'ALT', 'LABEL', 'HREF'];

/**
 * Markers (HTML comments, emitted with <Fragment set:html> because the compiler drops hand-written comments).
 * Shell blocks: see BLOCKS. Slots: see SLOTS.
 * Regions on built pages: hub-cards, home-cards, product-cards (cards), news-items (hub news list), news-section,
 * product-refs (the whole "installed projects" section). Each built card / news item is preceded by
 * <!--upx:card /url/--> / <!--upx:news /url/--> with its English page URL.
 * Templates: <template data-upx="…"> (TEMPLATES).
 */
export const M = {
  open: (name) => `<!--upx:${name}-->`,
  close: (name) => `<!--/upx:${name}-->`,
  slot: (name) => `<!--upx:slot:${name}-->`,
  card: (url) => `<!--upx:card ${url}-->`,
  news: (url) => `<!--upx:news ${url}-->`,
};

export const BLOCKS = [
  'if:country', 'if:date', 'if:types', 'if:ct2', 'if:gallery', 'if:gallery-nav', 'each:gallery-item',
  'if:cta-project', 'if:cta-news', 'if:band', 'if:band-d', 'if:band-e',
];
export const SLOTS = ['hreflang', 'og-locale-alt', 'jsonld', 'body'];
export const TEMPLATES = {
  hub: ['hub-card', 'hub-card-featured', 'news-item'],
  home: ['home-card', 'home-card-featured'],
  product: ['product-card', 'product-linktile'],
};
