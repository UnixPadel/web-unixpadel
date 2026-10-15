// Plain-text helpers shared by the import scripts (scripts/body-transforms.mjs) and the templates.

const lines = (body) => String(body ?? '').replace(/\r\n/g, '\n').split('\n');

/** Comparison key: lowercase letters/digits only, brand possessive dropped. */
export const norm = (s) => String(s ?? '').toLowerCase().replace(/unixpadel(?:’s|'s)?/g, '').replace(/[^\p{L}\p{N}]+/gu, '');

// "… - Unixpadel®", "… | UNIXPADEL", "… – Unix Padel" and similar trailing brand forms from the old Yoast titles.
export const BRAND_SUFFIX = /\s*[-|–—]\s*(?:unix\s?padel)®?\s*$/i;
const EMOJI = /[\p{Extended_Pictographic}\p{Regional_Indicator}️]/gu;

/**
 * Display title: no trailing brand suffix, no emoji, and " | " separators read as " – ". Runs of ordinary
 * whitespace collapse; French no-break spaces before ":" "?" "!" (U+00A0, U+202F) are kept.
 */
export function cleanTitle(raw) {
  let t = String(raw ?? '').replace(EMOJI, '').replace(/[^\S  ]+/g, ' ').trim();
  while (BRAND_SUFFIX.test(t)) t = t.replace(BRAND_SUFFIX, '');
  return t.replace(/ +\| +/g, ' – ');
}

/** Body lines that read as running text, Markdown and emoji stripped (headings, lists, tables and labels skipped). */
function* proseLines(body, title) {
  for (const l of lines(body)) {
    const t = l.trim();
    if (!t || /^(#|!\[|\[!\[|<|[-*+•]\s?|\d+(\.\d+)*\.?\s|\|)/.test(t)) continue;
    const plain = t
      .replace(/!\[[^\]]*\]\([^)]*\)/g, '')
      .replace(/\[([^\]]*)\]\([^)]*\)/g, '$1')
      .replace(/[*_`]/g, '')
      .replace(EMOJI, '')
      .replace(/^subtitle:\s*/i, '')
      .trim();
    if (/^[\p{L} ]{1,24}:\s/u.test(plain) || norm(plain) === norm(title)) continue;
    yield plain;
  }
}

/**
 * First real sentence of the body (8–30 words, ≤ 180 chars, Markdown and emoji stripped) — a tagline
 * *candidate* only. Skips headings, lists, numbered and label lines ("Dimensions: …") and any line that
 * just repeats the title.
 */
export function firstSentence(body, title = '') {
  for (const plain of proseLines(body, title)) {
    const s = plain.match(/^.+?[.!?](?=\s+[\p{Lu}“"]|$)/u)?.[0] ?? plain;
    const words = s.split(/\s+/).length;
    if (words < 8 || words > 30 || s.length > 180 || (title && norm(s).startsWith(norm(title)))) continue;
    return s;
  }
  return null;
}

/**
 * Card excerpt from the body when a page has no description: the first sentence, else the first prose line
 * cut at a word boundary (scripts without capitals, e.g. Arabic, rarely yield a clean sentence).
 */
export function leadText(body, title = '', max = 170) {
  const sentence = firstSentence(body, title);
  if (sentence) return sentence;
  for (const plain of proseLines(body, title)) {
    if (plain.split(/\s+/).length < 8) continue;
    if (plain.length <= max) return plain;
    const cut = plain.slice(0, max);
    return `${cut.slice(0, Math.max(cut.lastIndexOf(' '), max - 20)).replace(/[\s,;:–-]+$/, '')}…`;
  }
  return null;
}
