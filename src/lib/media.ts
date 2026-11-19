// Media stays on its existing WordPress URLs; nothing is copied into this repo.
// PUBLIC_MEDIA_ORIGIN is the deploy-time knob for where /wp-content/uploads is served from.
import srcsets from '../data/media-srcset.json';
import { resizedSrcset, resizedUrl } from './resize.mjs';

export const MEDIA_ORIGIN: string = import.meta.env.PUBLIC_MEDIA_ORIGIN ?? 'https://www.unixpadel.com';

/** Absolute URL for a scraped media path (`/wp-content/...`); other values pass through. */
export function mediaUrl(path: string | null | undefined): string | null {
  if (!path) return null;
  return path.startsWith('/wp-content/') ? MEDIA_ORIGIN + path : path;
}

const WIDTH_RE = /-(\d+)x\d+\.[a-z0-9]+$/i;

/** `srcset` from the plugin's resize endpoint; the frozen WordPress size map only when resizing is off. */
export function srcsetFor(path: string | null | undefined, maxWidth?: number): string | null {
  if (!path) return null;
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
  return resizedUrl(path, width, MEDIA_ORIGIN) ?? mediaUrl(path);
}

export const isVideo = (path: string | null | undefined) => /\.(mp4|webm|mov)$/i.test(path ?? '');
