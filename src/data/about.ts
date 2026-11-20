// Hand-authored About page copy (English source; other languages in src/i18n/locales/<lang>.ts → `about`).
// Source text: the "About Unixpadel" catalogue spread and the CEO letter (2026-11), reordered for B2B buyers.
//
// Who reads this page (25 real quote requests, 2026): sports clubs and associations, tennis clubs converting halls,
// developers / procurement groups, hotels, municipalities, architects and resellers, ~3 in 4 from Europe. They compare
// suppliers and must justify the choice to a board, an investor or a permit office. Their recurring questions:
// certified wind / snow loads for the permit, foundations, year-round cover, who manufactures and installs,
// lead time and shipping, and references of similar projects.
//
// Section order follows the persuasion sequence (each section's principle in brackets):
//   hero     – self-relevance: opens with the buyer's market, not with us ("no two markets are the same")
//   stats    – social proof in numbers (StatBand, site-wide data)
//   story    – unity + risk reduction: one company, one point of responsibility
//   answers  – objection handling: the buyers' own questions, answered before they are asked
//   proof    – authority + concreteness: standards, tests and named materials that can be checked
//   steps    – commitment ladder: the first step is small (send the site), every next step is visible
//   letter   – liking + narrative: a person signs for the company
//   cta      – reciprocity + low-friction close: layout and quote in return for the site details
// Facts only from the live site / catalogue. The catalogue's "94 countries, 800 cities" is older than the site
// stats; the page uses 96+ / 1020+ like the rest of the site.

export type AboutCopy = {
  hero: { eyebrow: string; title: string; tagline: string };
  story: { eyebrow: string; title: string; text: string[]; imageAlt: string };
  answers: { eyebrow: string; title: string; text: string; items: { q: string; a: string; link: string }[] };
  proof: { eyebrow: string; title: string; items: { title: string; text: string; points: string[] }[] };
  steps: { eyebrow: string; title: string; items: { title: string; text: string }[] };
  letter: { eyebrow: string; title: string; text: string[]; signoff: string; role: string };
  cta: { title: string; text: string; label: string };
};

/** Not translated: the order matches ABOUT.answers.items / ABOUT.proof.items. */
export const ABOUT_MEDIA = {
  story: '/wp-content/uploads/2023/07/about-us-e1689751021948.jpg',
  answerLinks: ['/designing/', '/innovative-courts/flat-floor-padel-court/', '/canopy/', '/manufacturing/', '/installation/', '/references/'],
  proofIcons: ['layers', 'factory', 'check-badge', 'globe'],
  ceo: 'Elshan Rasulov',
};

export const ABOUT: AboutCopy = {
  hero: {
    eyebrow: 'About Unixpadel',
    title: 'No two markets are the same. *Neither are our courts.*',
    tagline:
      'Since 2015 we have engineered padel courts and canopies in Istanbul, built them in our own factory in Adıyaman and installed them in 96+ countries, each one designed for its site, its climate and its regulations.',
  },
  story: {
    eyebrow: 'Who we are',
    title: 'One company, from the first drawing <mark class="hl">to the last bolt</mark>',
    text: [
      'Unixpadel has been engineering and manufacturing padel courts since 2015. Design and engineering are based in Istanbul; production runs in our own factory in Adıyaman, Türkiye. From there we deliver padel courts, canopy systems and complete sports facilities.',
      'Because we design, manufacture and install under one roof, you work with one team and one point of responsibility, not a chain of subcontractors passing your questions along. A single private court and a multi-court club get the same engineering.',
    ],
    imageAlt: 'Aerial view of the Unixpadel production facility',
  },
  answers: {
    eyebrow: 'Before you choose a supplier',
    title: 'What clubs, investors and architects <mark class="hl">ask us first</mark>',
    text: 'These questions come straight from the project requests we receive. Here are the short answers.',
    items: [
      {
        q: 'Will the structure get through my building permit?',
        a: 'Each project is designed for your local wind and snow loads and the engineering norms that apply there. Our systems are backed by structural tests at Istanbul Technical University, and we provide static analysis when your authority asks for it.',
        link: 'Design and engineering',
      },
      {
        q: 'Do we need a concrete foundation?',
        a: 'Not necessarily. Flat Floor and Floating Court systems stand on a certified steel base instead of a poured slab: less groundwork, faster installation, and a court you can move later. The smart foundation alternative.',
        link: 'Flat Floor court',
      },
      {
        q: 'Can we play all year round?',
        a: 'Yes. Canopies, retractable roofs and MobilFOLD X-TEND self-supported covers keep courts bookable through rain, snow and summer heat, so the club earns in every season.',
        link: 'Canopy systems',
      },
      {
        q: 'Who actually makes it?',
        a: 'We do. Steel, tempered glass, turf and paint are controlled in our own Adıyaman factory, which produces up to 12 courts a day. No trading company in between.',
        link: 'Manufacturing',
      },
      {
        q: 'Who installs it in my country?',
        a: 'Our installation teams and partners, coordinated from Istanbul and supported by our offices in France, the Netherlands, Romania, Azerbaijan, India and the USA.',
        link: 'Installation',
      },
      {
        q: 'Have you built something like this before?',
        a: 'Most likely. Our courts are in play in more than 1020 cities, from coastal resorts to city rooftops. Ask us for references close to your project, your climate and your court type.',
        link: 'See references',
      },
    ],
  },
  proof: {
    eyebrow: 'How we work',
    title: 'Engineering and quality <mark class="hl">you can check</mark>',
    items: [
      {
        title: 'Innovation and engineering',
        text: 'Court and canopy systems that adapt to different ground conditions, climates and installation requirements, built on modular construction and structural performance.',
        points: ['Flat Floor and Floating Court', 'MobilFOLD X-TEND', 'Retractable roof systems', 'Modular, relocatable structures'],
      },
      {
        title: 'Manufacturing and quality',
        text: 'Every court system is made in our own factory in Adıyaman, where steel fabrication, quality control and project-specific manufacturing happen on one site.',
        points: ['S235 / S355 structural steel', 'ISO 1461 hot-dip galvanising', 'EN 12150 tempered safety glass', 'FIP-compliant, KIWA-approved turf'],
      },
      {
        title: 'Testing and certification',
        text: 'International engineering standards and independent testing back the safety, durability and performance of every court we ship.',
        points: ['Wind and impact tested by Istanbul Technical University', 'Built to FIP standards', 'ISO-certified production', 'TSE and TÜV tested components'],
      },
      {
        title: 'Global presence',
        text: 'From Türkiye to international markets: production, technical teams and partners working as one, from single courts to complete padel facilities.',
        points: ['Exports to 96+ countries', 'Installations in 1020+ cities', 'Offices in six countries', 'Support in six languages'],
      },
    ],
  },
  steps: {
    eyebrow: 'Working with us',
    title: 'What happens <mark class="hl">after your first message</mark>',
    items: [
      { title: 'Tell us about your site', text: 'Location, available space, number of courts and whether you want a cover. A sketch or a few photos are enough to start.' },
      { title: 'Layout and engineering', text: 'We recommend the right court and cover, send layout drawings and check the structure against your local loads.' },
      { title: 'One all-inclusive quote', text: 'Courts, cover, delivery and installation in one offer, so you can compare suppliers on the full cost.' },
      { title: 'Made in Adıyaman', text: 'Your project is produced in our own factory, with quality control at each stage before it ships.' },
      { title: 'Installation and after-sales', text: 'Our teams and partners install the courts. Replacement glass, mesh panels, turf and nets stay available for the life of the club.' },
    ],
  },
  letter: {
    eyebrow: 'A note from our CEO',
    title: 'Understanding the bigger picture',
    text: [
      'Every year brings its own mix of challenges and opportunities. Some we can see coming and plan for; others we simply have to meet as well as we can. What matters is not avoiding change, but being ready for it.',
      'At Unixpadel, much of our progress comes from our people’s ability to understand how the world fits together. It is not only about our customers and our sport. It is also about the forces that shape both: global economics, trade and politics, changing cities and populations, diversity and sustainability.',
      'No two markets are the same. Each has its own climate, its own regulations and its own way of bringing people together around a court. That understanding is what has carried our courts to more than 96 countries and over a thousand cities. Behind each of those courts is a decision made by someone who trusted us to understand their market as well as our own product.',
      'Some call it seeing the big picture. We call it understanding our future.',
    ],
    signoff: 'Best regards,',
    role: 'CEO, Unixpadel',
  },
  cta: {
    title: 'Start with your site, *not a sales pitch*',
    text: 'Send us the location, the space you have and how many courts you plan. Our engineers reply with a recommended layout and an all-inclusive quote.',
    label: 'Send your project details',
  },
};
