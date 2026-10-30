// Site-wide data that is not part of the scraped content (contact details, offices, social links, stats).
// Addresses and numbers mirror the live contact page as of 2026-10-01.

export const SITE = {
  name: 'Unixpadel',
  legalName: 'Unixpadel Kort ve Zeminleri Üretimi İnşaat ve Sanayi A.Ş.',
  url: 'https://www.unixpadel.com',
  tagline: 'Padel court manufacturer',
  phone: '+90 546 665 36 56',
  phoneHref: 'tel:+905466653656',
  whatsappNumber: '905466653656',
  email: 'info@unixpadel.com',
  logo: '/wp-content/uploads/2025/05/unixpadel-logo.png',
  logoRetina: '/wp-content/uploads/2025/05/unixpadel-logo_retina.png',
  logoSvg: '/wp-content/uploads/2023/04/logo1.svg',
  // WordPress site icon (Settings → General), same files the old theme printed.
  favicon32: '/wp-content/uploads/2025/05/Untitled-1-75x75.png',
  appleTouchIcon: '/wp-content/uploads/2025/05/Untitled-1-300x300.png',
  foundingDate: '2015',
  socials: [
    { name: 'Instagram', icon: 'instagram', href: 'https://www.instagram.com/unixpadel/' },
    { name: 'LinkedIn', icon: 'linkedin', href: 'https://www.linkedin.com/company/unixpadel/' },
    { name: 'Facebook', icon: 'facebook', href: 'https://www.facebook.com/unixpadel' },
  ],
  stats: [
    { value: '94+', label: 'Countries exported' },
    { value: '800+', label: 'Cities installed' },
    { value: '7,800+', label: 'Courts delivered' },
    { value: '12', label: 'Courts produced per day' },
  ],
  certifications: ['FIP approved', 'ISO certified', 'KIWA tested', 'ITU wind & impact tested', 'TSE / TÜV components'],
  hq: {
    name: 'Head office — Istanbul',
    address: 'Emniyettepe Mah. Ahikent Sitesi Selam Sok. No: 12, Eyüpsultan / İstanbul, Türkiye',
    mapEmbed:
      'https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d3008.515838413256!2d28.963996715415572!3d41.05771577929583!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x14cab7398b9a206b%3A0x425d5105b4971f0c!2zVU7EsFhQQURFTCBLT1JUIFZFIFpFTcSwTkxFUsSw!5e0!3m2!1str!2str!4v1683115283879!5m2!1str!2str',
  },
  factory: {
    name: 'Factory — Adıyaman',
    address: 'Petrol Mah. Adıyaman Organize Sanayi Bölgesi 17. Cad. No: 31-1A, Merkez / Adıyaman, Türkiye',
  },
  offices: [
    {
      country: 'France',
      address: '233 Rue du Faubourg Saint-Martin, 75010 Paris',
      flag: '/wp-content/uploads/2023/07/197-france-v1.png',
      mapEmbed:
        'https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d2623.7383877770594!2d2.3634216770914627!3d48.88226357133544!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x47e66e76f4bb5d73%3A0x2df304ba7831972d!2s233%20Rue%20du%20Faubourg%20Saint-Martin%2C%2075010%20Paris%2C%20Fransa!5e0!3m2!1str!2str!4v1690382926062!5m2!1str!2str',
    },
    {
      country: 'Netherlands',
      address: 'Achterwetering 7b, 2871 RK Schoonhoven',
      flag: '/wp-content/uploads/2023/07/077-netherlands-v1.png',
      mapEmbed:
        'https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d2459.2676255320575!2d4.862552577186482!3d51.94731097191677!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x47c67e46a2085c07%3A0xeb87a10a0f6163f4!2sAchterwetering%207B%2C%202871%20RK%20Schoonhoven%2C%20Hollanda!5e0!3m2!1str!2str!4v1690737859249!5m2!1str!2str',
    },
    {
      country: 'Azerbaijan',
      address: 'Palishir Navai Str. 3, Baku',
      flag: '/wp-content/uploads/2023/07/137-azerbaijan-v1.png',
      mapEmbed:
        'https://www.google.com/maps/embed?pb=!1m21!1m12!1m3!1d268.57117720328745!2d49.923040012863375!3d40.39697503122211!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!4m6!3e0!4m0!4m3!3m2!1d40.3971385!2d49.922895399999994!5e0!3m2!1str!2str!4v1690964543719!5m2!1str!2str',
    },
    {
      country: 'India',
      address: 'Ratan Tower, 1st Floor, Plot C-6/5, Jagriti Vihar, opp. Medical College, Garh Road, Meerut (U.P.)',
      flag: '/wp-content/uploads/2023/07/055-india-v1.png',
      mapEmbed:
        'https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d3490.964008827639!2d77.74708007627932!3d28.95879197548824!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x390c7b52148221fd%3A0x9669831960e48b8f!2sRatan%20Tower!5e0!3m2!1str!2str!4v1690738719961!5m2!1str!2str',
    },
    {
      country: 'USA',
      address: '3350 Hill Forest Trail NW, Acworth, GA 30101',
      contact: 'Mike Byrne · unixpadelusa.com',
      href: 'http://www.unixpadelusa.com/',
      flag: '/wp-content/uploads/2023/07/186-united-states-v1.png',
      mapEmbed:
        'https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d3306.215120333897!2d-84.70699282370923!3d34.03835251846255!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x88f538a7415cabcb%3A0xfb92100ff3532557!2s3350%20Hill%20Forest%20Trail%2C%20Acworth%2C%20GA%2030101%2C%20Amerika%20Birle%C5%9Fik%20Devletleri!5e0!3m2!1str!2str!4v1753794739136!5m2!1str!2str',
    },
    {
      country: 'Romania',
      address: 'Strada Moşoaia No. 45A, Sector 4, Bucharest',
      flag: '/wp-content/uploads/2023/07/213-romania-v1.png',
      mapEmbed:
        'https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d2851.649680010145!2d26.12778217684162!3d44.378783671076924!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x40b1fe0c24d7fd05%3A0xedf2b587af391637!2sStrada%20Mo%C8%99oaia%2045a%2C%20Bucure%C8%99ti%20077160%2C%20Romanya!5e0!3m2!1str!2str!4v1690738897524!5m2!1str!2str',
    },
  ],
} as const;

// Search Console verification tag and Google Tag Manager container ID.
export const TRACKING = {
  gtmId: 'GTM-WFKTCNC4',
  googleSiteVerification: 'nCVyt-yTQVCrQkloc9QqfAs3d20osGhIiNlg809B2bw',
} as const;

/** Pre-filled WhatsApp chat link. */
export const whatsappUrl = (text?: string) =>
  `https://wa.me/${SITE.whatsappNumber}${text ? `?text=${encodeURIComponent(text)}` : ''}`;

/** Quote page link, optionally pre-selecting a product by its URL slug. */
export const quoteUrl = (productUrl?: string | null) =>
  productUrl ? `/contact-us/?product=${encodeURIComponent(productUrl.replace(/^\/|\/$/g, ''))}#quote` : '/contact-us/#quote';
