// Media stays on its existing WordPress URLs; nothing is copied into this repo.
// PUBLIC_MEDIA_ORIGIN is the deploy-time knob for where /wp-content/uploads is served from.
//
// The exception: pages whose pictures were never on WordPress (the lighting products). Their frontmatter names
// `asset:<path under src/assets/pages>` and the three functions below resolve it to the bundled file, so cards, menus,
// galleries and heroes need no special case. Each such picture has a `.sm` sibling (480 px) for small uses.
import srcsets from '../data/media-srcset.json';
import { resizedSrcset, resizedUrl } from './resize.mjs';

export const MEDIA_ORIGIN: string = import.meta.env.PUBLIC_MEDIA_ORIGIN ?? 'https://www.unixpadel.com';

const ASSETS = import.meta.glob<{ default: ImageMetadata }>('../assets/pages/**/*.{webp,png,jpg}', { eager: true });
const ASSET_PREFIX = 'asset:';

/** Bundled picture behind an `asset:` path, with its small sibling (itself when there is none); null for other paths. */
function pageAsset(path: string): { full: ImageMetadata; small: ImageMetadata } | null {
  if (!path.startsWith(ASSET_PREFIX)) return null;
  const rel = path.slice(ASSET_PREFIX.length).replace(/#.*$/, '');
  const full = ASSETS[`../assets/pages/${rel}`]?.default;
  if (!full) throw new Error(`Missing page picture: src/assets/pages/${rel}`);
  return { full, small: ASSETS[`../assets/pages/${rel.replace(/(\.\w+)$/, '.sm$1')}`]?.default ?? full };
}

/** Absolute URL for a scraped media path (`/wp-content/...`) or a bundled `asset:` picture; other values pass through. */
export function mediaUrl(path: string | null | undefined): string | null {
  if (!path) return null;
  const asset = pageAsset(path);
  if (asset) return asset.full.src;
  return path.startsWith('/wp-content/') ? MEDIA_ORIGIN + path : path;
}

const WIDTH_RE = /-(\d+)x\d+\.[a-z0-9]+$/i;

/** `srcset` from the plugin's resize endpoint; the frozen WordPress size map only when resizing is off. */
export function srcsetFor(path: string | null | undefined, maxWidth?: number): string | null {
  if (!path) return null;
  const asset = pageAsset(path);
  if (asset) return asset.small === asset.full ? null : `${asset.small.src} ${asset.small.width}w, ${asset.full.src} ${asset.full.width}w`;
  const resized = resizedSrcset(path, MEDIA_ORIGIN, maxWidth);
  if (resized) return resized;
  const variants = (srcsets as Record<string, string[]>)[path];
  if (!variants?.length) return null;
  const parts = variants
    .map((v) => ({ v, w: Number(v.match(WIDTH_RE)?.[1] ?? 0) }))
    .filter((x) => x.w > 0)
    .sort((a, b) => a.w - b.w)
    .map(({ v, w }) => `${mediaUrl(v)} ${w}w`);
  if (!parts.length) return null;
  // The original is the largest candidate; WP never reports its width, so use a generous value.
  parts.push(`${mediaUrl(path)} 1920w`);
  return parts.join(', ');
}

/** One resized copy (e.g. a 48px menu thumbnail at 2x); the original when the path cannot be resized. */
export function sizedUrl(path: string | null | undefined, width: number): string | null {
  if (!path) return null;
  const asset = pageAsset(path);
  if (asset) return (width <= asset.small.width ? asset.small : asset.full).src;
  return resizedUrl(path, width, MEDIA_ORIGIN) ?? mediaUrl(path);
}

export const isVideo = (path: string | null | undefined) => /\.(mp4|webm|mov)$/i.test(path ?? '');
