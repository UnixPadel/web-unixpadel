// @ts-check
import { defineConfig, fontProviders } from 'astro/config';
import sitemap from '@astrojs/sitemap';
import { unified } from '@astrojs/markdown-remark';
import fs from 'node:fs';
import path from 'node:path';
import { parse as parseYaml } from 'yaml';
import redirects from './src/data/redirects.json' with { type: 'json' };
import { rehypeMediaOrigin, rehypeCtaButtons, rehypeMedia, rehypeSpecsId, rehypeHeadingOrder, rehypeHeadingColons } from './src/lib/rehype.mjs';

// Media keeps its WordPress URLs (/wp-content/uploads/YYYY/MM/file) and is served from the live site.
// Nothing is copied into this repo; PUBLIC_MEDIA_ORIGIN decides where /wp-content comes from at deploy time
// (set it to "" to go back to relative paths + the dev proxy).
const MEDIA_ORIGIN = process.env.PUBLIC_MEDIA_ORIGIN ?? 'https://www.unixpadel.com';
// Set by `npm run build:wp`: inside the WordPress plugin, /_astro is served from the plugin folder
// (wp-content/plugins/unixpadel-static/dist/_astro), not the site root.
const ASSETS_PREFIX = process.env.ASSETS_PREFIX || undefined;

const SITE_URL = 'https://www.unixpadel.com';

// Frontmatter the sitemap needs (astro:content isn't available in the config): lastmod and review state per URL.
const walkMd = (dir) =>
  fs.readdirSync(dir, { withFileTypes: true }).flatMap((e) =>
    e.isDirectory() ? walkMd(path.join(dir, e.name)) : e.name.endsWith('.md') ? [path.join(dir, e.name)] : []);
const PAGE_META = new Map(
  walkMd('./src/content/pages').map((file) => {
    const fm = parseYaml(fs.readFileSync(file, 'utf8').split(/^---\s*$/m)[1]);
    return [fm.url, { lastmod: fm.lastmod ? new Date(fm.lastmod) : null, pendingReview: !!fm.pendingReview }];
  }),
);
// Languages served under a prefix (keep in sync with LANGS in src/i18n/index.ts) and the English URLs each one has a
// translation for (src/content/pages-<lang>, same front matter `url`); every language has a home page.
const LANGS = ['tr', 'de', 'fr', 'ar', 'ru'];
const TRANSLATED = Object.fromEntries(
  LANGS.map((lang) => {
    const dir = `./src/content/pages-${lang}`;
    const urls = fs.existsSync(dir) ? walkMd(dir).map((/** @type {string} */ file) => parseYaml(fs.readFileSync(file, 'utf8').split(/^---\s*$/m)[1]).url) : [];
    return [lang, new Set(['/', ...urls])];
  }),
);
const pathOf = (page) => new URL(page).pathname;
const LANG_PREFIX = new RegExp(`^/(${LANGS.join('|')})(?=/)`);
/** "/de/x/" → "/x/": sitemap entries of every language share the English page's metadata. */
const keyOf = (/** @type {string} */ pathname) => pathname.replace(LANG_PREFIX, '');

export default defineConfig({
  site: SITE_URL,
  trailingSlash: 'always',
  build: { format: 'directory', assetsPrefix: ASSETS_PREFIX },
  // 'class' passes the parent's scope class through a component's `class` prop, so a page can style
  // the child it places (e.g. <Arc class="hero__arc" />, <Icon class="range__arrow" />).
  scopedStyleStrategy: 'class',
  redirects,
  integrations: [
    sitemap({
      // Unreviewed pages carry noindex (src/lib/seo.ts), so they stay out of the sitemap too. The translated 404s are
      // regular routes (/de/404/), not Astro's special 404.html: keep them out too, like the WordPress reference shells
      // (/upx-shell/, src/lib/upx-contract.mjs).
      filter: (page) =>
        !PAGE_META.get(keyOf(pathOf(page)))?.pendingReview && !/^\/[a-z]{2}\/404\/$/.test(pathOf(page)) && !/^\/(?:[a-z]{2}\/)?upx-shell\//.test(pathOf(page)),
      serialize(item) {
        const url = keyOf(pathOf(item.url));
        const meta = PAGE_META.get(url);
        if (meta?.lastmod) item.lastmod = meta.lastmod.toISOString();
        // Same alternates as the page head (src/lib/seo.ts): every language that has the page, x-default = English.
        const langs = LANGS.filter((lang) => TRANSLATED[lang].has(url));
        if (langs.length) {
          const en = new URL(url, SITE_URL).href;
          item.links = [
            { lang: 'en', url: en },
            ...langs.map((lang) => ({ lang, url: new URL(url === '/' ? `/${lang}/` : `/${lang}${url}`, SITE_URL).href })),
            { lang: 'x-default', url: en },
          ];
        }
        return item;
      },
    }),
  ],
  // Self-hosted at build time (dist/_astro), with metric-matched fallbacks to avoid layout shift.
  fonts: [
    {
      provider: fontProviders.google(),
      name: 'DM Sans',
      cssVariable: '--font-sans',
      weights: [400, 500, 700],
      styles: ['normal'],
      subsets: ['latin', 'latin-ext'],
      fallbacks: ['Arial', 'Helvetica', 'sans-serif'],
    },
    {
      provider: fontProviders.google(),
      name: 'Barlow Semi Condensed',
      cssVariable: '--font-display',
      weights: [600, 700],
      styles: ['normal'],
      subsets: ['latin', 'latin-ext'],
      fallbacks: ['Arial Narrow', 'Arial', 'sans-serif'],
    },
    // DM Sans and Barlow have no Cyrillic or Arabic glyphs: /ru/ and /ar/ pages load these instead
    // (BaseLayout renders their <Font> only on those pages; base.css swaps the variables by html[lang]).
    {
      provider: fontProviders.google(),
      name: 'Manrope',
      cssVariable: '--font-cyrillic',
      weights: [400, 500, 700],
      styles: ['normal'],
      subsets: ['cyrillic', 'latin'],
      fallbacks: ['Arial', 'Helvetica', 'sans-serif'],
    },
    {
      provider: fontProviders.google(),
      name: 'Roboto Condensed',
      cssVariable: '--font-cyrillic-display',
      weights: [600, 700],
      styles: ['normal'],
      subsets: ['cyrillic', 'latin'],
      fallbacks: ['Arial Narrow', 'Arial', 'sans-serif'],
    },
    {
      provider: fontProviders.google(),
      name: 'IBM Plex Sans Arabic',
      cssVariable: '--font-arabic',
      weights: [400, 500, 700],
      styles: ['normal'],
      subsets: ['arabic', 'latin'],
      fallbacks: ['Tahoma', 'Arial', 'sans-serif'],
    },
  ],
  markdown: {
    // Astro 7 defaults to the Sätteri processor; rehype plugins need the unified one.
    processor: unified({
      rehypePlugins: [
        [rehypeMediaOrigin, { origin: MEDIA_ORIGIN }],
        rehypeCtaButtons,
        [rehypeMedia, { origin: MEDIA_ORIGIN }],
        rehypeSpecsId,
        rehypeHeadingOrder,
        rehypeHeadingColons,
      ],
    }),
  },
  vite: {
    define: { 'import.meta.env.PUBLIC_MEDIA_ORIGIN': JSON.stringify(MEDIA_ORIGIN) },
    server: {
      proxy: MEDIA_ORIGIN ? undefined : { '/wp-content': { target: 'https://www.unixpadel.com', changeOrigin: true } },
    },
  },
});
