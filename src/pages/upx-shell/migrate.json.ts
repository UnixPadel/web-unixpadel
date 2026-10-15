// The references of this build (children of /references/, projects and company news) as the WordPress plugin imports
// them into WP admin → References (Settings → Unixpadel Static → Import, wp-plugin/includes/refs-import.php). Built to
// dist/upx-shell/migrate.json. Bodies are not in here: the importer takes each language's rendered body from the built
// page itself (its <article class="prose">), so imported text matches what the site shows.
import type { APIRoute } from 'astro';
import { CONTRACT } from '../../lib/upx-contract.mjs';
import { LANGS, PREFIXED } from '../../i18n';
import { allPages, cardImage, cardImageAlt, childrenOf, isTranslated, labelOf } from '../../lib/collection';
import { countryOf, countrySlug } from '../../lib/countries';
import { isNews } from '../../lib/pageType';
import { courtTypesOf } from '../../lib/references';

/** Last path segment as an a–z 0–9 slug: "/padel_center_italy/" → "padel-center-italy". */
const slugOf = (url: string) =>
  url
    .replace(/\/$/, '')
    .split('/')
    .pop()!
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-|-$/g, '');

export const GET: APIRoute = async () => {
  const en = await childrenOf('/references/');
  const local = Object.fromEntries(
    await Promise.all(PREFIXED.map(async (l) => [l, new Map((await allPages(l)).filter(isTranslated).map((p) => [p.data.url, p]))] as const)),
  );
  const items = [];
  for (const [i, p] of en.entries()) {
    const d = p.data;
    const news = isNews(d.url);
    const hero = cardImage(p);
    const inBody = (src: string) => (p.body ?? '').includes(src.replace(/#.*$/, ''));
    const gallery = d.media
      .filter((m) => m.type === 'image' && m.src !== hero && !inBody(m.src) && !/ikon|icon|gravatar|avatar|logo/i.test(m.src))
      .map((m) => ({ src: m.src.replace(/#.*$/, ''), alt: m.alt ?? '' }));
    const country = news ? null : countryOf(`${labelOf(p)} ${d.slug}`);
    const langs: Record<string, unknown> = {};
    for (const lang of LANGS) {
      const page = lang === 'en' ? p : local[lang].get(d.url);
      if (!page) continue;
      langs[lang] = {
        title: labelOf(page),
        excerpt: page.data.hero?.tagline ?? '',
        seoTitle: page.data.seo.title ?? '',
        seoDesc: page.data.seo.description ?? '',
      };
    }
    items.push({
      source: d.url,
      slug: slugOf(d.url),
      kind: news ? 'news' : 'project',
      order: i + 1,
      published: (d.published ?? d.lastmod)?.toISOString() ?? null,
      modified: d.lastmod?.toISOString() ?? null,
      country: country ? countrySlug(country) : '',
      types: (await courtTypesOf(p, 'en')).map((c) => c.url).slice(0, 2),
      image: hero ? { src: hero, alt: cardImageAlt(p) } : null,
      gallery,
      langs,
    });
  }
  return new Response(JSON.stringify({ contract: CONTRACT, items }), { headers: { 'Content-Type': 'application/json; charset=utf-8' } });
};
