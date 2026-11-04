// Turf colour picker (src/components/TurfColorPicker.astro): the pre-rendered cut-outs per product, view and colour, made
// by scripts/build-turf-colors.py. Unlike other media these live in the repo: they are not on the WordPress site.
const FILES = import.meta.glob<{ default: ImageMetadata }>('../assets/turf/*/*.webp', { eager: true });

export type TurfColor = { id: TurfColorId; hex: string };
export type TurfColorId = 'light-blue' | 'blue' | 'green' | 'emerald' | 'black' | 'pink' | 'terracotta' | 'purple' | 'gray' | 'red' | 'lime';
export type TurfView = 'roll' | 'sheet' | 'flat';

/** Swatch order as in the commercial offer. Hex values must match COLORS in scripts/build-turf-colors.py. */
const PALETTE: TurfColor[] = [
  { id: 'light-blue', hex: '#8fd3e8' },
  { id: 'blue', hex: '#1565c0' },
  { id: 'green', hex: '#2e8b57' },
  { id: 'emerald', hex: '#009b77' },
  { id: 'black', hex: '#181818' },
  { id: 'pink', hex: '#e5447f' },
  { id: 'terracotta', hex: '#c66a49' },
  { id: 'purple', hex: '#6a4c93' },
  { id: 'gray', hex: '#8a9099' },
];

export interface TurfPicker {
  /** Folder under src/assets/turf/. */
  slug: string;
  views: TurfView[];
  colors: TurfColor[];
  /** The colour closest to the product photos. */
  defaultColor: TurfColorId;
  /** Pile height in mm, as the product page states it: a min-max range, or the discrete options on offer. */
  heightsMm: number[];
  heightsKind: 'range' | 'options';
  /** Gallery photos the picker stands in for. */
  photos: string[];
}

const UPLOADS = '/wp-content/uploads/2023/08/';
const PICKERS: Record<string, TurfPicker> = {
  '/adlx3t/': {
    slug: 'adlx3t',
    views: ['roll', 'sheet'],
    colors: PALETTE,
    defaultColor: 'blue',
    heightsMm: [12, 13],
    heightsKind: 'range',
    photos: ['ADLX3T-2.jpg', 'ADLX3T-3.jpg'].map((f) => UPLOADS + f),
  },
  // The two below are photographed in a colour the palette has nothing close to, so that colour leads their swatches.
  '/adlx4t/': {
    slug: 'adlx4t',
    views: ['roll', 'sheet', 'flat'],
    colors: [{ id: 'red', hex: '#dc2430' }, ...PALETTE],
    defaultColor: 'red',
    heightsMm: [12, 13],
    heightsKind: 'range',
    photos: ['ADLX4T-2.jpg', 'ADLX4T-3.jpg', 'ADLX4T-4.jpg'].map((f) => UPLOADS + f),
  },
  '/cleek/': {
    slug: 'cleek',
    views: ['roll', 'sheet'],
    colors: PALETTE,
    defaultColor: 'terracotta',
    heightsMm: [12, 15],
    heightsKind: 'range',
    photos: ['Cleek-2.jpg', 'Cleek-4.jpg'].map((f) => UPLOADS + f),
  },
  '/adl-kdk/': {
    slug: 'adl-kdk',
    views: ['roll', 'sheet', 'flat'],
    colors: [{ id: 'lime', hex: '#76960f' }, ...PALETTE],
    defaultColor: 'lime',
    heightsMm: [12, 13, 15],
    heightsKind: 'options',
    photos: ['ADL-KDK-2.jpg', 'ADL-KDK-3.jpg', 'ADL-KDK-4.jpg'].map((f) => UPLOADS + f),
  },
};

export const turfPickerFor = (url: string): TurfPicker | undefined => PICKERS[url];

export const turfImage = (slug: string, view: TurfView, color: TurfColorId): ImageMetadata => {
  const file = FILES[`../assets/turf/${slug}/${view}-${color}.webp`];
  if (!file) throw new Error(`Missing turf render: ${slug}/${view}-${color}.webp (run scripts/build-turf-colors.py)`);
  return file.default;
};
