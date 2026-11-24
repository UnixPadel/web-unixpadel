// Lights on/off view (src/components/CourtLights.astro): one render of a court by day and with its floodlights on, cut
// to a shared frame by scripts/build-court-lights.mjs. Like the turf cut-outs these live in the repo, not on WordPress.
const FILES = import.meta.glob<{ default: ImageMetadata }>('../assets/court-lights/*/*.webp', { eager: true });

export interface CourtLights {
  /** The same frame at each built width, smallest first. */
  day: ImageMetadata[];
  lit: ImageMetadata[];
}

/** Product URL → folder under src/assets/court-lights/. */
const FOLDERS: Record<string, string> = {
  '/portable-padel-court/': 'portable',
};

const frames = (folder: string, state: 'day' | 'lit') =>
  Object.entries(FILES)
    .filter(([file]) => file.includes(`/court-lights/${folder}/${state}-`))
    .map(([, mod]) => mod.default)
    .sort((a, b) => a.width - b.width);

export const courtLightsFor = (url: string): CourtLights | undefined => {
  const folder = FOLDERS[url];
  if (!folder) return undefined;
  const day = frames(folder, 'day');
  const lit = frames(folder, 'lit');
  if (!day.length || !lit.length) throw new Error(`Missing lights frames: ${folder} (run scripts/build-court-lights.mjs)`);
  return { day, lit };
};
