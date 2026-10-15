// Certification / test claims made on the live site (home page "Certified Quality & Engineering" section,
// About page "Tested, Approved & Certified"). Rendered as SVG seals until the official logos are supplied.
// Ring texts stay short so they remain legible at 60–72 px.
export const SEALS = [
  { mark: 'FIP', ring: 'FIP STANDARD · APPROVED · ', label: 'Built to FIP (International Padel Federation) standards' },
  { mark: 'ISO', ring: 'ISO CERTIFIED · PRODUCTION · ', label: 'ISO-certified production' },
  { mark: 'KIWA', ring: 'KIWA · LAB APPROVED · ', label: 'Approved by the KIWA laboratory' },
  { mark: 'İTÜ', ring: 'WIND · IMPACT · TESTED · ', label: 'Wind resistance and impact tested by Istanbul Technical University' },
  { mark: 'TÜV', ring: 'TSE · TÜV · TESTED PARTS · ', label: 'Components tested to TSE and TÜV standards' },
] as const;
