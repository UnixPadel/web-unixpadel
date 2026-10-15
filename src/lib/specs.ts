// Turns scraped spec bullets ({label, value}) into presentation data: a category (icon + short name)
// and, when the value contains a measurable figure, a short numeral for the key-facts strip.
// Nothing is invented: a fact only exists when its number is present in the page's own text.
import type { Lang } from '../i18n';
import { LOCALES } from '../i18n/locales';

export type Spec = { label: string; value: string };
export type SpecKind = 'dimensions' | 'steel' | 'glass' | 'mesh' | 'lighting' | 'turf' | 'anchor' | 'finish' | 'color' | 'power' | 'compliance' | 'load' | 'temperature' | 'other';

// kind, label regex (English plus the translated labels: tr, de, fr, ru, ar), icon. Names come from the locale files.
const KINDS: [Exclude<SpecKind, 'other'>, RegExp, string][] = [
  // first: "Температурная стойкость" would otherwise hit steel (стойк)
  ['temperature', /temperature|sıcaklık|temperatur|température|температур|حرارة/i, 'thermometer'],
  ['dimensions', /dimension|size|playing area|ölçü|boyut|oyun alanı|abmessung|maße|spielfläche|größe|dimension|surface de jeu|taille|размер|габарит|игров|أبعاد|المقاس|مساحة اللعب/i, 'ruler'],
  ['steel', /steel|frame|structure|pillar|column|profile|çelik|konstrüksiyon|çerçeve|dikme|kolon|profil|stahl|rahmen|konstruktion|stütze|säule|acier|cadre|poteau|ossature|сталь|стальн|каркас|конструкц|стойк|профил|فولاذ|هيكل|إطار|أعمدة|مقطع/i, 'columns'],
  ['glass', /glass|cam|glas|verre|vitr|стекл|زجاج/i, 'glass'],
  ['mesh', /mesh|net|örgü|file|gitter|netz|grillag|treillis|filet|сетк|сетч|شبك/i, 'mesh'],
  ['lighting', /light|aydınlatma|ışık|projektör|beleucht|licht|leuchte|flutlicht|éclairage|lumi|projecteur|освещ|свет|прожектор|إضاءة|كشاف/i, 'light'],
  ['turf', /turf|yarn|grass|infill|pile|dtex|surface|floor|çim|iplik|dolgu|hav|yüzey|zemin kaplama|rasen|faser|garn|verfüll|belag|boden|gazon|fibre|fil |remplissage|\bsol\b|газон|трав|волокн|ворс|засыпк|покрыт|عشب|ألياف|حشو|أرضية/i, 'turf'],
  ['anchor', /anchor|baseplate|foot ?plate|foundation|ankraj|taban plaka|temel|anker|fußplatte|fundament|ancrage|platine|fondation|анкер|опорн|фундамент|تثبيت|أساس|لوح القاعدة/i, 'anchor'],
  ['finish', /finish|coating|galvan|kaplama|boya|beschicht|verzink|oberfläche|revêtement|finition|peinture|покрыти|окраск|отделк|цинк|طلاء|تشطيب|جلفن/i, 'shield'],
  ['color', /colou?r|ral|renk|farb|couleur|цвет|ألوان|لون/i, 'palette'],
  ['power', /power|güç|leistung|stromversorg|puissance|alimentation|мощност|электроснабж|قدرة|طاقة/i, 'bolt'],
  ['compliance', /compliance|certif|standard|quality|flammab|safety|uygunluk|sertifika|standart|kalite|yanmaz|güvenlik|norm|zertif|qualität|sicherheit|conformité|qualité|sécurité|соответств|сертиф|стандарт|качеств|безопасн|مطابقة|شهادة|معايير|جودة|سلامة/i, 'check-badge'],
  ['load', /load|capacity|yük|kapasite|last|tragfähig|charge|capacité|нагрузк|грузоподъ|несущ|حمل|سعة/i, 'layers'],
];

/** Category of a spec label; `name` is the short category name in the page language. */
export function kindOf(label: string, lang: Lang = 'en'): { kind: SpecKind; name: string; icon: string } {
  for (const [kind, re, icon] of KINDS) if (re.test(label)) return { kind, name: LOCALES[lang].specKinds[kind], icon };
  return { kind: 'other', name: label, icon: 'check' };
}

const num = (s: string) => s.replace(',', '.').replace(/\.0+$/, '').replace(/(\.\d*?)0+$/, '$1');

/** A short figure from a spec value, or null when the value has no measurable number. */
export function factOf(spec: Spec): { value: string; unit: string } | null {
  const v = spec.value;
  let m: RegExpMatchArray | null;
  if ((m = v.match(/(\d+(?:[.,]\d+)?)\s*m?\s*(?:\([^)]*\))?\s*[x×]\s*(\d+(?:[.,]\d+)?)\s*m\b/i))) return { value: `${num(m[1])} × ${num(m[2])}`, unit: 'm' };
  if ((m = v.match(/(\d+)\s*(?:mm)?\s*[x×]\s*(\d+)\s*(?:mm)?\s*[x×]\s*(\d+)\s*mm/i))) return { value: `${m[1]}×${m[2]}×${m[3]}`, unit: 'mm' };
  if ((m = v.match(/(\d+)\s*(?:[-–]|to|ile|bis|à|до|إلى)\s*(\d+)\s*lux/i))) return { value: `${m[1]}–${m[2]}`, unit: 'lux' };
  if ((m = v.match(/(-?\d+)\s*°C\s*(?:to|ile|bis|à|до|إلى|–|-)\s*(-?\d+)\s*°C/i))) return { value: `${m[1]} → ${m[2]}`, unit: '°C' };
  if ((m = v.match(/(\d+)\s*mm\s*(?:or|veya|oder|ou|или|أو|\/)\s*(\d+)\s*mm/i))) return { value: `${m[1]}/${m[2]}`, unit: 'mm' };
  if ((m = v.match(/(\d+)\s*mm\s*[-–]\s*(\d+)\s*mm/i))) return { value: `${m[1]}–${m[2]}`, unit: 'mm' };
  if ((m = v.match(/(\d+)\s*km\/h/i))) return { value: m[1], unit: 'km/h' };
  if ((m = v.match(/(\d{1,3}(?:[.,]\d+)?)\s*mm\b/i))) return { value: num(m[1]), unit: 'mm' };
  if ((m = v.match(/(\d{3,5})\s*dtex/i))) return { value: m[1], unit: 'dtex' };
  return null;
}

const FACT_PRIORITY: SpecKind[] = ['dimensions', 'steel', 'glass', 'lighting', 'mesh', 'turf', 'temperature', 'load'];

/** Up to `max` key facts in a fixed priority order (one per kind). */
export function keyFacts(specs: Spec[] | undefined, max = 4, lang: Lang = 'en') {
  if (!specs?.length) return [];
  const out: { label: string; value: string; unit: string; icon: string }[] = [];
  for (const kind of FACT_PRIORITY) {
    const spec = specs.find((s) => kindOf(s.label).kind === kind && factOf(s));
    if (!spec) continue;
    const f = factOf(spec)!;
    out.push({ label: kindOf(spec.label, lang).name, ...f, icon: kindOf(spec.label).icon });
    if (out.length === max) break;
  }
  return out;
}
