/// <reference path="../.astro/types.d.ts" />
/// <reference types="astro/client" />

interface ImportMetaEnv {
  /** true in `astro dev` when catalog-export/ exists: catalogue images come from the dev server (astro.config.mjs). */
  readonly CATALOG_LOCAL?: boolean;
}

interface Window {
  dataLayer?: Record<string, unknown>[];
}
