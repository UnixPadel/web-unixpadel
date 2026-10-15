# unixpadel.com — Astro site

The new unixpadel.com (en + tr/de/fr/ar/ru). The content was migrated once from the old WordPress site; the scrape
and the import pipeline have been removed, so `src/content/` and `src/data/` are now the source and are edited by hand.

## Commands

```bash
npm install
npm run dev            # http://localhost:4321
npm run build          # → dist/
npm run build:wp       # → wp-plugin-build/unixpadel-static-<version>.zip (WordPress plugin, see below)
npm run check:ui       # after build: one H1, hero eyebrow, no empty split hero, no lone grid card, no body CTA/markdown/hero-image leaks
npx astro check        # template type-check
```

Template routing (root-level products, parent overrides, news entries under /references/) lives in
`src/data/routing.json`.

After changing a rehype plugin, delete `.astro/data-store.json` and `node_modules/.astro/data-store.json`: the content
layer caches rendered Markdown and will not pick up plugin changes otherwise.

## How it fits together

| Piece | Where |
|---|---|
| Collection schema | `src/content.config.ts` |
| Routing: a page's URL comes from frontmatter `url`, never from its file path | `src/pages/[...slug].astro` (dispatcher) |
| Template per page type (hub, product, service, article, reference, contact, about, generic) | `src/lib/pageType.ts` → `src/templates/*.astro` |
| Home page (designed, data from the collection + `src/data/home.ts`) | `src/pages/index.astro` |
| Site data (contact, offices, socials, stats) | `src/data/site.ts` |
| Navigation: rendered from `menu.json`; Courts/Canopy open mega-menus, mobile uses a native `<dialog>` drawer | `src/components/Header.astro` |
| Design tokens / base / prose CSS (light + system dark theme) | `src/styles/{tokens,base,prose}.css` |
| Markdown decoration (absolute media URLs, CTA buttons, lazy images + srcset, `#specs` anchor) | `src/lib/rehype.mjs`, registered in `astro.config.mjs` |
| SEO head + JSON-LD (Organization, WebSite, Product, Article, FAQ, Breadcrumb) | `src/layouts/BaseLayout.astro`, `src/lib/jsonld.ts` |
| Redirects (legacy 301s, merged duplicates, typo URLs) | `src/data/redirects.json` → `astro.config.mjs` |

### Design system (short version)
Palette from the logo: navy `#14274E`, lime `#9DC43B`, orange `#F36F24`. Lime and orange are never small text on
white (use `--lime-700` / `--orange-700`); the primary CTA is orange with navy text. Fonts: DM Sans (body) and Barlow
Semi Condensed (display), self-hosted at build time through Astro's `fonts` API. Spacing is an 8pt scale, type a
Major Third scale. Every interactive component defines default / hover (`@media (hover: hover)`) / focus-visible /
active / disabled / loading / empty states. Motion respects `prefers-reduced-motion`.

### Visual layer v2 ("court language")
The padel court itself is the graphic language: court lines (`src/components/graphics/CourtLines.astro`), the logo's
racket arc and dot trail (`Arc.astro`), certification seals (`Seal.astro`, data in `src/data/seals.ts`) and large
technical figures (`Numeral.astro`). Lime is used as surface (stat blocks, labels, shadows), not as small text on white.
- **Photos:** real installation photos are square, so layouts are collages (`PhotoBand.astro`, curated in
  `src/data/showcase.ts`) instead of 16:9 boxes. Product → photo mapping and one-line specs: `src/data/products.ts`.
- **Product pages:** spec bullets live in frontmatter `specs` and are rendered as
  key-fact numerals (`KeyFacts.astro`, parsing in `src/lib/specs.ts`), a dimensioned court plan (`SpecDiagram.astro`,
  data in `src/data/courtDims.ts`) and material cards. Product images leave the body and become a lightbox gallery
  (`Gallery.astro`, native `<dialog>`).
- **Motion:** `src/lib/motion.ts` (position-based reveals, count-ups, header progress); everything is static with
  `prefers-reduced-motion`. The home hero video loads only on wide screens or fast connections, after idle.
- `scopedStyleStrategy: 'class'` lets a parent style the component it places (`<Arc class="hero__arc" />`).

### One H1 per page
The page title lives in frontmatter `title` and the template renders it as the hero H1; body headings start at H2.

### Media
Media keeps its WordPress URLs (`/wp-content/uploads/YYYY/MM/file`) and **is not copied into this repo**.
`PUBLIC_MEDIA_ORIGIN` (default `https://www.unixpadel.com`) makes every media path absolute, in templates
(`src/lib/media.ts`) and in Markdown bodies (`rehypeMediaOrigin`). Set it to `""` to go back to relative paths plus the
dev proxy. When the new site goes live on `www.unixpadel.com`, `/wp-content/uploads` must keep being served from
somewhere (reverse proxy to the old host, or a media subdomain) and `PUBLIC_MEDIA_ORIGIN` points there.

Responsive `srcset`s come from the WordPress size variants recorded in `src/data/media-srcset.json`.

### Quote form
`src/components/QuoteForm.astro` validates on blur and posts to the plugin's REST route
(`/?rest_route=/unixpadel/v1/quote`: email to the address set under Settings → Unixpadel Static, plus a saved copy under
WP admin → Quote requests). WhatsApp is the second button; `mailto:` appears only if the POST fails. `?product=<slug>`
pre-selects the court (product pages link to it).

### Redirects
In a static build, Astro writes each redirect as a meta-refresh page. That works on any host, but it isn't a real HTTP 301.
Once hosting is chosen, add its adapter (Netlify, Vercel or Cloudflare) or generate its `_redirects` file from `src/data/redirects.json` to get true 301s.

### Publishing through WordPress (plugin ZIP)
Only the WP admin is available, so the site ships as a plugin. `npm run build:wp` builds into `dist-wp/` with
`ASSETS_PREFIX` pointing /_astro at `/wp-content/plugins/unixpadel-static/dist`, turns `redirects.json` into
`redirects.php` (real 301s) and zips it with `wp-plugin/unixpadel-static.php` (the plugin source; edit that, not the ZIP copy).

On each request (`init`, GET/HEAD only) the plugin serves `dist/<path>/index.html` (English and the /tr/ /de/ /fr/
/ar/ /ru/ translations) or a 301, adds the references written in WP admin (next section), and lets WordPress answer
everything else: /wp-admin, /wp-content, /wp-json, feeds, WP query strings (?p=, ?s=, ?elementor-preview…) and any
path the new site doesn't have.

Install: Plugins → Add New → Upload Plugin → activate. Settings → Unixpadel Static sets the mode: **Off**, **Preview**
(default; logged-in admins plus browsers that opened the secret `?upx_preview=` link) or **Live**. Purge the page cache
after switching modes. Update: upload the new ZIP and choose "Replace current with uploaded"; the mode is kept.
Rollback: mode Off, or deactivate the plugin.

### References written in WordPress (no rebuild)
New projects are added under **WP admin → References** (post type `upx_reference`, plugin files
`wp-plugin/includes/refs-*.php`); the editor's how-to in Turkish is `REFERANS-EKLEME.md`. English title, text and a
main photo are required; tr/de/fr/ar/ru are optional boxes, and a language gets a page only when its title and text
are filled (otherwise that language's hub shows the English card). Each reference is a **Project** (card on the hub,
home and product pages) or **Company news** (list under the hub). The globe keeps its own data.

**The build's own 42 references** are imported once with Settings → Unixpadel Static → *Import built references*
(`refs-import.php`, data from `upx-shell/migrate.json`, built by `src/pages/upx-shell/migrate.json.ts`; each language's
body is taken from the built page). Option `upx_refs_imported` maps their English URLs to the new posts, and from then
on the built copies are never shown: the address is answered by the WP admin copy (the 8 outside `/references/` 301 to
`/references/<slug>/`), built cards and news items are dropped by their `<!--upx:card /url/-->` markers, and their
entries leave `sitemap-0.xml` and `llms.txt`. Photos are matched to the media library; the ones that aren't keep
their `/wp-content` path. They keep the built list's order (meta `sort`); new references sort by date. *Undo import*
trashes the imported posts and brings the built copies back. The built reference pages stay in the ZIP only as that
fallback; `src/content/pages*/references` must not be edited for content any more.

How it works: the build renders **shells**, the real templates with tokens where the content goes
(`src/lib/upx-contract.mjs`):
- `/upx-shell/reference/` and `/<lang>/upx-shell/reference/` (`src/templates/ReferenceShell.astro`): the reference
  page. Noindex, not in the sitemap, and the plugin answers those URLs with a 404.
- `<template data-upx>` card templates plus region markers: `hub-cards` and `news-items` on the hub, `home-cards` on
  the home page, `product-refs` / `product-cards` ("installed projects", filled by court type) on product pages.
- `upx-shell/data.json` (`src/pages/upx-shell/data.json.ts`): country and court-type choices with their names in every
  language, month names, breadcrumb labels and which pages each language has.

The plugin fills them per request from an index of the published references (option `upx_refs_index`, rebuilt on
every save, page caches purged): `/references/<slug>/` and `/<lang>/references/<slug>/`, the cards on `/references/`
and the home page (all languages), `/sitemap-references.xml` (listed in `/sitemap-index.xml`) and `/llms.txt`. A
built page always wins over a WordPress reference at the same address; the editor gets `-2` instead. The hub script
recounts the country chips and "Show all" from the cards in the page, so a new country gets its chip.

**When you change the design**, keep `ReferenceShell.astro` in step with `ReferencePage.astro` (they share
`ReferenceFacts`, `Gallery`, `PhotoBand`, `Hero`) and `ReferenceCard` usage on the hub and home in step with the
templates next to it. `npm run build:wp` checks the contract (every token and marker in the build output and in the PHP,
the first built card matching the featured template, data.json complete) and stops on a mismatch. Bump `CONTRACT` when
a token, marker or data.json field changes meaning; a plugin build with another contract turns the WordPress
references off rather than rendering them wrong. Live pages carry `Cache-Control: max-age=300`, so a new reference can
take up to five minutes to show in a browser that already had the page.

**Local test** (WordPress Playground, needs network for the first `npx` download):
```bash
UPX_ASSETS_ORIGIN=http://127.0.0.1:9400 npm run build:wp -- --unpacked
```
then start the `wp` entry of `.claude/launch.json` (`npx @wp-playground/cli server --port=9400 …`): it mounts
`wp-plugin-build/unixpadel-static/`, sets the mode to Live and runs `scripts/playground/seed.php` (sample references:
new country with a Turkish translation, English only, draft, and an address that collides with a built page). Open
http://127.0.0.1:9400/references/. Run `npm run build:wp` again (without the variable) before making the ZIP for the
live site; the script refuses to zip a build with another assets origin.

