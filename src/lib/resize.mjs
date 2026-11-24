// Resized media through the plugin's image endpoint (wp-plugin/includes/img-resize.php).
// Uploads keep their original URLs; `/?upx_img=<path under uploads>&w=<width>` returns a cached WebP at that width
// (never upscaled). Shared by media.ts (components) and rehype.mjs (Markdown bodies).
// PUBLIC_IMG_RESIZE=off falls back to plain originals, e.g. against a WordPress without the plugin.

/** Widths the endpoint accepts; keep in sync with UPX_IMG_WIDTHS in img-resize.php. */
export const IMG_WIDTHS = [96, 160, 320, 480, 640, 960, 1280, 1600, 1920];
const CONTENT_WIDTHS = [320, 480, 640, 960, 1280, 1600, 1920];
/**
 * Version of the logo the endpoint stamps on photos (UPX_WM_VERSION in wp-plugin/includes/img-stamp.php). It is in
 * every copy's URL only to get past browser and CDN caches when the stamp changes; the server does not read it.
 */
export const IMG_STAMP = 1;

const RESIZABLE = /^\/wp-content\/uploads\/(.+\.(?:jpe?g|png|webp))$/i;
const enabled = () => (import.meta.env?.PUBLIC_IMG_RESIZE ?? process.env.PUBLIC_IMG_RESIZE) !== 'off';

/** Uploads-relative path of a resizable media path or URL, else null. */
function uploadsPath(src, origin) {
  if (!src || !enabled()) return null;
  let p = String(src).split('#')[0];
  if (origin && p.startsWith(origin)) p = p.slice(origin.length);
  return p.match(RESIZABLE)?.[1] ?? null;
}

/** URL of one resized copy, or null when the source is not a resizable upload. */
export function resizedUrl(src, width, origin) {
  const rel = uploadsPath(src, origin);
  return rel ? `${origin}/?upx_img=${encodeURIComponent(rel).replace(/%2F/g, '/')}&w=${width}&s=${IMG_STAMP}` : null;
}

/** `srcset` across the content widths (optionally capped), or null. */
export function resizedSrcset(src, origin, maxWidth = 1920) {
  if (!uploadsPath(src, origin)) return null;
  return CONTENT_WIDTHS.filter((w) => w <= maxWidth)
    .map((w) => `${resizedUrl(src, w, origin)} ${w}w`)
    .join(', ');
}
