// /llms.txt (llmstxt.org): a curated, plain-text map of the site for AI answer engines. Replaces Yoast's
// auto-generated list (which included popups and missed the hubs) when the plugin is live.
import type { APIRoute } from 'astro';
import { SITE } from '../data/site';
import { HUBS, pageTypeOf, isNews } from '../lib/pageType';
import { allPages, childrenOf, labelOf, type Page } from '../lib/collection';
import { describe, isIndexable } from '../lib/seo';
import { LLMS_MAX_REFS } from '../lib/upx-contract.mjs';

const SECTION_TITLES: Record<string, string> = {
  '/our-courts/': 'Padel courts',
  '/innovative-courts/': 'Innovative court systems',
  '/canopy/': 'Canopies and covers',
  '/innovative-canopy/': 'Innovative canopy systems',
  '/turfs/': 'Artificial turf',
  '/our-services/': 'Services',
  '/padel-guide/': 'Padel guide (articles)',
  '/references/': 'References (delivered projects)',
};

export const GET: APIRoute = async ({ site }) => {
  const abs = (p: string) => new URL(p, site).href;
  const line = (p: Page) => {
    // Guide articles carry an answer-first summary, which suits answer engines better than the meta description.
    const d = p.data.summary ?? describe(p);
    return `- [${labelOf(p)}](${abs(p.data.url)})${d ? `: ${d}` : ''}`;
  };
  const pages = (await allPages()).filter(isIndexable);
  const byUrl = new Map(pages.map((p) => [p.data.url, p]));

  const out: string[] = [
    `# ${SITE.name}`,
    '',
    `> ${SITE.name} (${SITE.legalName}) is a Turkish padel court manufacturer founded in ${SITE.foundingDate}. It designs, manufactures and installs padel courts, padbol and pickleball courts, canopies and artificial turf from its own factory in Adıyaman, Türkiye, and has delivered ${SITE.stats[2].value} courts to ${SITE.stats[0].value} countries.`,
    '',
    '## Key facts',
    ...SITE.stats.map((s) => `- ${s.label}: ${s.value}`),
    `- Certifications and tests: ${SITE.certifications.join(', ')}`,
    `- Head office: ${SITE.hq.address}`,
    `- Factory: ${SITE.factory.address}`,
    `- Sales offices: ${SITE.offices.map((o) => `${o.country} (${o.address})`).join('; ')}`,
    `- Contact: ${SITE.email}, ${SITE.phone} (WhatsApp), quote form ${abs('/contact-us/')}`,
    '',
  ];

  for (const hub of HUBS) {
    const hubPage = byUrl.get(hub);
    if (!hubPage) continue;
    let children = (await childrenOf(hub)).filter(isIndexable);
    // The WordPress plugin adds references written in WP admin after the hub line and keeps the list this long.
    if (hub === '/references/') children = children.filter((p) => !isNews(p.data.url)).slice(0, LLMS_MAX_REFS);
    out.push(`## ${SECTION_TITLES[hub] ?? labelOf(hubPage)}`, line(hubPage), ...children.map(line), '');
  }

  const company = ['/about-us/', '/contact-us/'].flatMap((u) => (byUrl.get(u) ? [byUrl.get(u)!] : []));
  out.push('## Company', ...company.map(line), '');

  // Products that sit outside the hubs (padbol, pickleball, root-level court pages).
  const listed = new Set(out.join('\n').match(/\]\(([^)]+)\)/g)?.map((m) => m.slice(2, -1)));
  const extra = pages.filter((p) => pageTypeOf(p.data) === 'product' && !listed.has(abs(p.data.url)));
  if (extra.length) out.push('## Optional', ...extra.map(line), '');

  return new Response(out.join('\n'), { headers: { 'Content-Type': 'text/plain; charset=utf-8' } });
};
