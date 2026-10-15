// Translated pages share the English content model, so Markdown bodies, FAQ answers and cards still carry English
// page links ("/panoramic-padel-court/"). This pass rewrites every internal <a href> of a /tr/, /de/, … page to its
// twin in that language once, at build time (prerendered pages run middleware during the build). Links marked `data-no-localize`
// or carrying `hreflang` (the language switcher) are left alone; media, assets and untranslated pages too.
import { defineMiddleware } from 'astro:middleware';
import { SITE } from './data/site';
import { langOf, localizePath, type Lang } from './i18n';

const SITE_ORIGIN = new RegExp(`^${SITE.url.replace(/[.]/g, '\\.')}(?=/)`);
const ANCHOR = /<a\b[^>]*>/g;
const HREF = /\shref="([^"]*)"/;

export function localizeHtml(html: string, lang: Lang): string {
  return html.replace(ANCHOR, (tag) => {
    if (/\s(hreflang|data-no-localize)\b/.test(tag)) return tag;
    const m = HREF.exec(tag);
    if (!m) return tag;
    // Absolute links to this site's own pages count as internal.
    const href = m[1].replace(SITE_ORIGIN, '');
    const local = localizePath(href, lang);
    return local === href ? tag : tag.replace(m[0], ` href="${local}"`);
  });
}

export const onRequest = defineMiddleware(async (context, next) => {
  const response = await next();
  const lang = langOf(context.url);
  if (lang === 'en' || !response.headers.get('content-type')?.includes('text/html')) return response;
  const html = localizeHtml(await response.text(), lang);
  return new Response(html, { status: response.status, statusText: response.statusText, headers: response.headers });
});
