// robots.txt for the live static site (served by the WordPress plugin in live mode; WordPress answers it otherwise).
import type { APIRoute } from 'astro';

export const GET: APIRoute = ({ site }) => {
  const abs = (p: string) => new URL(p, site).href;
  const body = [
    'User-agent: *',
    'Disallow: /wp-admin/',
    'Allow: /wp-admin/admin-ajax.php',
    'Disallow: /?s=',
    'Disallow: /wp-content/uploads/wpo/wpo-plugins-tables-list.json',
    '',
    // One sitemap for all six languages (each URL carries its hreflang alternates); the old Weglot/Yoast
    // language sitemaps are retired.
    `Sitemap: ${abs('/sitemap-index.xml')}`,
    '',
  ].join('\n');
  return new Response(body, { headers: { 'Content-Type': 'text/plain; charset=utf-8' } });
};
