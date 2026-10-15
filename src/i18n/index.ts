// Language plumbing. English pages live at "/x/", every other language under its prefix ("/tr/x/", "/de/x/", …).
// Content entries of all languages share the English URL as their key (`data.url`), so lookups, routing rules and
// parent/child links stay language-neutral; only rendered hrefs carry the prefix (see localizePath and
// src/middleware.ts). Translations live in src/content/pages-<lang>/, interface copy in src/i18n/locales/<lang>.ts.

export const LANGS = ['en', 'tr', 'de', 'fr', 'ar', 'ru'] as const;
export type Lang = (typeof LANGS)[number];
/** Languages served under a path prefix (all but English). */
export const PREFIXED = LANGS.filter((l) => l !== 'en') as Exclude<Lang, 'en'>[];

/** Locale facts: Intl/Open Graph locale, native name for the switcher, text direction. */
export const LOCALE: Record<Lang, { intl: string; og: string; name: string; dir: 'ltr' | 'rtl' }> = {
  en: { intl: 'en-GB', og: 'en_US', name: 'English', dir: 'ltr' },
  tr: { intl: 'tr-TR', og: 'tr_TR', name: 'Türkçe', dir: 'ltr' },
  de: { intl: 'de-DE', og: 'de_DE', name: 'Deutsch', dir: 'ltr' },
  fr: { intl: 'fr-FR', og: 'fr_FR', name: 'Français', dir: 'ltr' },
  ar: { intl: 'ar', og: 'ar_AR', name: 'العربية', dir: 'rtl' },
  ru: { intl: 'ru-RU', og: 'ru_RU', name: 'Русский', dir: 'ltr' },
};

const PREFIX = new RegExp(`^/(${PREFIXED.join('|')})(?=/|$)`);

export const langOf = (path: string | URL): Lang =>
  (PREFIX.exec(typeof path === 'string' ? path : path.pathname)?.[1] as Lang | undefined) ?? 'en';

/** "/tr/about-us/" → "/about-us/"; English paths are returned unchanged. */
export const stripLang = (path: string): string => path.replace(PREFIX, '') || '/';

// English URLs that have a twin in src/content/pages-<lang> (read from the files' front matter, so this works in
// components and middleware alike). Every language's home page exists (src/pages/[lang]/index.astro).
const FILES = import.meta.glob<string>('../content/pages-*/**/*.md', { eager: true, query: '?raw', import: 'default' });
export const TRANSLATED = Object.fromEntries(LANGS.map((l) => [l, new Set(['/'])])) as Record<Lang, Set<string>>;
for (const [file, src] of Object.entries(FILES)) {
  const lang = /\/pages-([a-z]{2})\//.exec(file)?.[1] as Lang | undefined;
  const url = /^url:\s*['"]?([^'"\s]+)/m.exec(src)?.[1];
  if (lang && url && TRANSLATED[lang]) TRANSLATED[lang].add(url);
}

export const hasTranslation = (url: string, lang: Lang) => lang === 'en' || TRANSLATED[lang].has(url.replace(/[?#].*$/, ''));

/**
 * Internal page path for a language. Media, assets, external links and pages without a translation are
 * left alone, so a link never points at a page that doesn't exist.
 */
export function localizePath(path: string, lang: Lang): string {
  if (lang === 'en' || !path.startsWith('/') || path.startsWith('//')) return path;
  if (PREFIX.test(path) || /^\/(wp-(content|admin|includes|json)\/|_astro\/|sitemap|robots\.txt|llms\.txt|favicon)/.test(path)) return path;
  return hasTranslation(path, lang) ? `/${lang}${path}` : path;
}

/** Same page in another language: the switcher target (falls back to that language's home page). */
export function counterpartOf(url: string, lang: Lang): string {
  const key = stripLang(url);
  if (key === '/404/') return localizePath('/', lang); // error pages have no address of their own to switch to
  return hasTranslation(key, lang) ? localizePath(key, lang) : localizePath('/', lang);
}

export { t } from './locales';
export type { UI } from './ui';
