// Curated real-installation photographs (all from the site's own reference pages) for photo bands
// and photo-backed CTA sections. All are square 1080 px unless noted.

export type ShowcaseItem = { src: string; alt: string; label: string; href: string };

export const DELIVERED: ShowcaseItem[] = [
  {
    src: '/wp-content/uploads/2026/01/oman_unixpadel_1.jpg',
    alt: 'Aerial view of Unixpadel courts in Oman',
    label: 'Oman',
    href: '/references/oman/',
  },
  {
    src: '/wp-content/uploads/2026/09/2-FIP-Approved-Black-Turf-Padel-Courts-Bosnia-HerzegovinaAnother-professional-Unixpadel-pade-1.jpg',
    alt: 'Panoramic courts with black turf in Bosnia & Herzegovina',
    label: 'Bosnia · Black turf',
    href: '/references/black-turf-padel-courts-bosnia/',
  },
  {
    src: '/wp-content/uploads/2026/01/turkey_antalya_unixpadel_1.jpg',
    alt: 'Red-turf padel courts at a resort in Antalya, Türkiye',
    label: 'Antalya · Resort',
    href: '/references/turkey-antalya/',
  },
  {
    src: '/wp-content/uploads/2026/07/arbref.jpg', // 1010×567
    alt: 'Panoramic padel court in Dammam, Saudi Arabia',
    label: 'Dammam · Panoramic',
    href: '/references/panoramic-padel-court-in-dammam-saudi-arabia/',
  },
  {
    src: '/wp-content/uploads/2026/08/greece_ref-3.jpg', // 750×750
    alt: 'Aerial view of three Standard Courts in Chalkida, Greece',
    label: 'Chalkida · 3 courts',
    href: '/references/standard-padel-court-greece-chalkida/',
  },
];

/** Photo behind the closing CTA band (duotone). */
export const CTA_PHOTO = {
  src: '/wp-content/uploads/2026/07/arbref.jpg', // Dammam, 1010×567: wide enough for a band, no overlay graphics
  alt: '',
};

/** Hero video for the home page (desktop and fast connections only; the poster is the LCP). */
export const HOME_HERO_VIDEO = '/wp-content/uploads/2026/09/Cinematic-aerial-drone-shot-16-9-of-th.mp4';
