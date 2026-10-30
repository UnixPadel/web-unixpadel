# Instagram Reference Import — Discovery & Feasibility Notes (`IG_IMPORT_NOTES.md`)

## 1. Plugin Architecture & Coding Conventions
- **Plugin folder:** `wp-plugin/` (packaged into `wp-plugin-build/unixpadel-static-<version>.zip` via `scripts/build-wp-plugin.mjs`).
- **Main file:** `wp-plugin/unixpadel-static.php`
- **Prefix / Namespace:** Procedural + modular files in `wp-plugin/includes/` with `upx_` function/meta prefix and `UPX_` constant prefix. Isolated classes use `UPX_` prefix (e.g., `UPX_Instagram_Post_Extractor`).
- **PHP version:** Requires PHP `7.4+`, WordPress `5.5+`.
- **Editor style:** Classic Editor (`show_in_rest => false`), custom meta boxes in `wp-plugin/includes/refs-admin.php`, jQuery + `jquery-ui-sortable` + `wp.media` in `wp-plugin/assets/refs-admin.js`.

## 2. References Post Type & Meta Keys
- **Post type slug:** `upx_reference` (`UPX_REF_TYPE` in `wp-plugin/includes/refs-store.php`).
- **Core post fields (English):**
  - `post_title`: English reference title.
  - `post_name`: URL slug under `/references/{slug}/`.
  - `post_excerpt`: Short summary shown under the title and on reference cards.
  - `post_content`: English HTML article body (`p, h2, h3, strong, em, a, ul, li`).
  - `_thumbnail_id`: WordPress featured image ID ("Main photo"); alt text stored in attachment's `_wp_attachment_image_alt`.
- **Primary Reference Meta (`_upx_ref` / `UPX_META_REF`):**
  - `kind`: `'project'` (card on `/references/`, home page, and product pages) | `'news'` (company news list under projects on `/references/`) | `''` (empty before admin selection on Instagram-sourced drafts).
  - `country`: Country slug from `upx_data()['countries']` (e.g. `'turkiye'`, `'austria'`, `'united-kingdom'`, or `''` for "Other").
  - `types`: Array of up to 2 court/canopy product URL paths (e.g. `['/panoramic-padel-court/']`).
  - `seo_title`: English SEO `<title>` override (`<= 60` chars).
  - `seo_desc`: English `<meta name="description">` override (`140–155` chars).
  - `focus_keyphrase`: Focus SEO keyphrase (e.g. `"padel courts in Türkiye"`).
  - `gallery`: Array of attachment IDs (`int[]`) or legacy `/wp-content/uploads/...` paths.
  - `sort`, `image_url`, `image_alt`: Legacy migration fields.
- **Translations Meta (`_upx_l10n` / `UPX_META_L10N`):**
  - Keyed by language code (`tr`, `de`, `fr`, `ar`, `ru`), each storing `{ title, excerpt, seo_title, seo_desc, body }`.
- **Rendered HTML Cache (`_upx_html` / `UPX_META_HTML`):**
  - Cached rendered body HTML per language; cleared on `save_post_upx_reference`.
- **Instagram Import & Consistency Meta Keys:**
  - `_source`: `'instagram'`
  - `_input_mode`: `'link'` | `'manual'`
  - `_ig_shortcode`: Instagram post shortcode (e.g. `'DetpGv9EVky'`)
  - `_ig_permalink`: Normalized permalink (`https://www.instagram.com/p/{shortcode}/`)
  - `_ig_raw_caption`: Original caption text used for generation/regeneration
  - `_ai_suggested_type`: `'project'` | `'news'` | `''`
  - `_ai_suggested_country`: Country slug or `''`
  - `_ai_suggested_court_types`: Array of up to 2 product URLs
  - `_ai_suggestion_confidence`: `{ type, country, court_types }` (`'high'` | `'medium'` | `'low'`)
  - `_ai_missing_info`: Array of strings noting missing details or warnings
  - `_generated_for`: Hash (`md5(wp_json_encode(...))`) of `{ kind, country, sorted_types }` for which the text was generated or verified
- **Attachment Meta Keys (Deduplication):**
  - `_ig_shortcode`: Shortcode the image came from
  - `_ig_image_index`: Carousel index (`0, 1, ...`)
  - `_ig_source_url`: Original CDN URL

## 3. Gemini Client, Multimodal Support & Reuse
- **Client function:** `upx_gemini_call($prompt, $system_instruction, $json_mode, $temperature, $images, $response_schema)` in `wp-plugin/includes/refs-ai.php`.
- **API Key storage:** `UPX_GEMINI_KEY` constant in `wp-config.php` -> `upx_gemini_key` WP option -> `UPX_DEFAULT_GEMINI_KEY`.
- **Models & Multimodal support:** `gemini-3.5-flash-lite` (default) with automatic fallback to `gemini-3.5-flash`, `gemini-2.5-flash`, `gemini-flash-latest`. All models support multimodal `inlineData` (`image/jpeg`, `image/png`, `image/webp`) and `responseMimeType: application/json` with `responseSchema`.
- **Reused pipeline functions:** `upx_gemini_call()` and `upx_ai_translate_reference()` in `wp-plugin/includes/refs-ai.php`.

## 4. Publish Validation & Static Site Regeneration
- **Publish validation:**
  - Server-side: `upx_ref_require_fields($data, $postarr)` hooked to `wp_insert_post_data` in `wp-plugin/includes/refs-admin.php`. Forces `post_status = 'draft'` and sets `upx_publish_notice_{user_id}` transient when required fields are missing.
  - Client-side: `wp-plugin/assets/refs-admin.js`.
- **SEO storage:** Front-end templates (`wp-plugin/includes/refs-store.php` and `wp-plugin/includes/refs-render.php`) read `seo_title` and `seo_desc` directly from `_upx_ref` and `_upx_l10n[$lang]` (not Yoast `_yoast_wpseo_*`).
- **Static site regeneration:** `upx_refs_mark_dirty()` schedules `upx_refs_flush()` on `shutdown`, which rebuilds `upx_refs_index` (`upx_refs_rebuild()`) and purges LiteSpeed / WP Rocket / W3TC / WP Fastest Cache / SG Optimizer / Nginx Helper (`upx_purge_page_caches()`).

## 5. Option Lists & CTA URL
- **Type (`kind`):** `project`, `news`.
- **Country (`country`):** Loaded dynamically from `upx_data()['countries']` (ISO country list with slugs and localized names in `upx-shell/data.json`).
- **Court type (`types`):** 21 product URLs in `upx_data()['products']` (16 court models + 5 canopy/roof systems):
  - `/panoramic-padel-court/` — Panoramic Padel Court
  - `/full-panoramic-padel-court/` — Full Panoramic Padel Court
  - `/single-padel-court/` — Single Padel Court
  - `/single-panoramic-padel-court/` — Single Panoramic Padel Court
  - `/single-full-panoramic-padel-court/` — Single Full Panoramic Padel Court
  - `/standard-padel-court/` — Standard Padel Court
  - `/plate-standard-padel-court/` — Plate Standard Padel Court
  - `/panaromic-plate-padel-court/` — Panoramic Plate Padel Court
  - `/wp-challenge-padel-court/` — WP Challenge Padel Court
  - `/padel-court-cover/` — Padel Court Cover
  - `/portable-padel-court/` — Portable Padel Court
  - `/innovative-courts/flat-floor-padel-court/` — Flat Floor Padel Court
  - `/floating-floor-padel-court/` — Floating Floor Padel Court
  - `/mobilfold-padel-court/` — MobilFold Padel Court
  - `/padbols/` — Padbol Court
  - `/our-courts/pickleball-court/` — Pickleball Court
  - `/full-membrane-cover/` — Full Membrane Cover
  - `/sandwich-panel-full-covered/` — Sandwich Panel Cover
  - `/retractable-roof-cover-system/` — Retractable Roof Cover
  - `/roof-top-canopy/` — Roof-Top Canopy
  - `/half-close-canopy/` — Half-Close Canopy
- **"Request a quote" CTA URL:** `/contact-us/#quote` (defined in `src/data/site.ts`).

## 6. Cover Image Cropping & Orientation Analysis
- **Reference detail hero (`src/components/Hero.astro`, `layout="split"`):** `.hero--split .hero__media` uses `aspect-ratio: 1 / 1` on desktop (`>=960px`) and `aspect-ratio: 4 / 3` on mobile (`<960px`) with `object-fit: cover`.
- **Reference cards (`src/components/ReferenceCard.astro`):** `.ref__media` uses `aspect-ratio: 1 / 1` on home/product pages and `aspect-ratio: 4 / 3` on `/references/` hub (`.ref--hub .ref__media`) with `object-fit: cover`.
- **Conclusion:** Square (`1080×1080`) and portrait (`1080×1350`) Instagram photos render cleanly without layout breakage via `object-fit: cover`, though portrait covers crop top/bottom on `4:3` hub cards. Therefore `_source = instagram` accepts any orientation `>= 1080px` wide and displays a non-blocking warning when the cover is not landscape.

## 7. Server Feasibility Test Results (`https://www.instagram.com/p/DetpGv9EVky/`)
Tested anonymous HTTP GET requests with desktop browser `User-Agent` and `Accept-Language: en-US,en;q=0.9`, no cookies:
1. **Strategy 1 (`https://www.instagram.com/p/DetpGv9EVky/embed/captioned/`):**
   - **HTTP Status:** `200 OK`
   - **Final URL:** `https://www.instagram.com/p/DetpGv9EVky/embed/captioned/` (0 redirects, `506,261` bytes)
   - **Payload findings:** Returned Instagram's Polaris error shell (`"entryPoint":{"__dr":"PolarisErrorRoot.entrypoint"}`, `"pageID":"httpErrorPage"`, `"show_lox_redesigned_404_page":true`). Caption parsed: `No`. Image URLs found: `0`.
2. **Strategy 2 (`https://www.instagram.com/p/DetpGv9EVky/`):**
   - **HTTP Status:** `200 OK`
   - **Final URL:** `https://www.instagram.com/p/DetpGv9EVky/` (0 redirects, `506,224` bytes)
   - **Payload findings:** Same `PolarisErrorRoot.entrypoint` / `httpErrorPage` shell; no `og:description` or `og:image` tags emitted.
3. **Takeaway:**
   - Instagram serves HTTP `200` with `PolarisErrorRoot.entrypoint` / `httpErrorPage` for unavailable/restricted posts or blocked anonymous embeds instead of HTTP `404`.
   - `UPX_Instagram_Post_Extractor` explicitly detects `/accounts/login`, `PolarisErrorRoot`, `httpErrorPage`, and missing caption/media markers so it never treats error shells or login walls as content, and seamlessly transitions the UI to **Mode B (Caption + images)** with pre-filled partial data whenever Mode A is blocked or partial.
