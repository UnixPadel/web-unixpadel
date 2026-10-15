// Shared by the rehype plugins (build config) and the product template, so it lives apart from rehype.mjs
// (which reads JSON through Node's require and must stay out of page bundles).
/** "Technical Specifications" heading in every language (TRANSLATION-GUIDE-<LANG>.md). */
export const SPECS_HEADING_RE = /technical specifications|teknik özellikler|technische daten|caractéristiques techniques|المواصفات الفنية|технические характеристики/i;
