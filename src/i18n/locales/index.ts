// Registry of the per-language copy files. Adding a language: write locales/<lang>.ts (copy tr.ts as the template),
// add it to LANGS in ../index.ts, to the collections in src/content.config.ts and to the plugin's language list.
import type { LocaleData } from '../types';
import type { UI } from '../ui';
import en from './en';
import tr from './tr';
import de from './de';
import fr from './fr';
import ar from './ar';
import ru from './ru';

export const LOCALES: Record<'en' | 'tr' | 'de' | 'fr' | 'ar' | 'ru', LocaleData> = { en, tr, de, fr, ar, ru };

/** Interface strings for a language. */
export const t = (lang: keyof typeof LOCALES): UI => LOCALES[lang].ui;
