// Hand-authored home page copy (the old WordPress home was a flat block dump; see home.md for the source).
// Facts come from the live site: 94+ countries, 800+ cities, 7,800+ courts, 12 courts/day, Adıyaman factory.

export const HOME = {
  hero: {
    eyebrow: 'Padel court manufacturer · Türkiye',
    title: 'Padel courts engineered, built and installed by *one factory*.',
    tagline:
      'Steel structures, tempered glass, turf and canopies made in-house in Adıyaman, shipped to clubs, resorts and municipalities in 94+ countries.',
    // Çeşme coastal project, cinematic drone shot (16:9)
    video: '/wp-content/uploads/2026/09/Cinematic-aerial-drone-shot-16-9-of-th.mp4',
    // 2560px / 329 KB and no WordPress size variants: upload a ~1600px version to improve mobile LCP.
    poster: '/wp-content/uploads/2023/04/padel-slider-scaled.jpg',
  },
  /** The six courts shown on the old home page, with the one-line spec a buyer scans for. */
  courts: [
    { url: '/standard-padel-court/', spec: '20 × 10 m · 3-pillar frame · 10 mm glass', icon: '/wp-content/uploads/2023/05/standart-court_3-.png' },
    { url: '/panoramic-padel-court/', spec: 'Pillar-free back walls · 360° spectator view', icon: '/wp-content/uploads/2023/05/panoramic_3-.png' },
    { url: '/full-panoramic-padel-court/', spec: 'All-glass perimeter · tournament look', icon: '/wp-content/uploads/2023/05/full-panoramic_3.png' },
    { url: '/single-padel-court/', spec: '20 × 6 m · compact footprint', icon: '/wp-content/uploads/2023/07/single-court-vva.png' },
    { url: '/portable-padel-court/', spec: 'No anchoring · beaches, rooftops, events', icon: '/wp-content/uploads/2023/07/portable-court-ikon.png' },
    { url: '/wp-challenge-padel-court/', spec: 'Reinforced for high wind and impact loads', icon: '/wp-content/uploads/2023/07/wp-challenge-courtafadf.png' },
  ],
  /** Innovative systems: alternating image/text rows */
  systems: [
    {
      url: '/mobilfold-padel-court/',
      eyebrow: 'MobilFOLD',
      title: 'A court that folds, moves and sets up in hours',
      text: 'The mobile padel court packs into a transportable unit. Clubs use it for events and pop-up venues; developers use it to test demand before building.',
    },
    {
      url: '/innovative-courts/flat-floor-padel-court/',
      eyebrow: 'Flat Floor (MobilFLOD)',
      title: 'No concrete foundation required',
      text: 'A certified steel base replaces the slab. Faster installation, lower groundwork cost, and the court can be relocated later.',
    },
    {
      url: '/retractable-roof-cover-system/',
      eyebrow: 'Retractable roof',
      title: 'Open-air in summer, covered in winter',
      text: 'Roof and side systems that open and close so a club keeps courts bookable through rain, snow and heat.',
    },
  ],
  why: [
    { icon: 'globe', title: 'Global track record', text: 'Exported to 94+ countries and 800+ cities; trusted by five-star hotels, governments, sports clubs and FIP tournaments.' },
    { icon: 'factory', title: 'Everything made in-house', text: 'Our own factory in Adıyaman controls steel, tempered glass, turf and paint. Capacity: 12 courts a day.' },
    { icon: 'shield', title: 'Certified engineering', text: 'Built to FIP standards, KIWA approved, wind and impact tested by Istanbul Technical University, TSE and TÜV tested components.' },
    { icon: 'layers', title: 'Designs you will not find elsewhere', text: 'MobilFLOD flat-floor courts, MobilFOLD X-TEND self-supported canopies, retractable roofs, modular and floating options.' },
    { icon: 'coins', title: 'Factory-direct pricing', text: 'No middlemen. Packages for municipalities, clubs and resorts, with fast shipping from Türkiye to every region.' },
    { icon: 'headset', title: 'After-sales that answers', text: 'A network of technicians and partners, spare parts in stock, and support in English, French, Spanish, Turkish, Russian and Arabic.' },
  ],
  factory: {
    video: '/wp-content/uploads/2023/06/Adiyaman-Manufacturing.mp4',
    title: 'The Adıyaman factory',
    text: 'An advanced machinery park produces up to 12 courts a day, so standard and custom projects ship on schedule without trading away quality. Design, manufacturing, installation, consulting and repair are all handled by Unixpadel teams.',
    services: [
      { label: 'Designing', url: '/designing/' },
      { label: 'Manufacturing', url: '/manufacturing/' },
      { label: 'Installation', url: '/installation/' },
      { label: 'Consulting', url: '/consulting/' },
      { label: 'Repair & accessories', url: '/repair-and-accessories/' },
    ],
  },
  guides: ['/padel-guide/padel-court-dimensions/', '/padel-guide/how-to-build-a-padel-court/', '/padel-guide/how-much-does-a-padel-court-cost/'],
} as const;
