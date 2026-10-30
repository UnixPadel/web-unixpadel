# Unixpadel Instagram-to-Reference Import System (`IG_IMPORT_README.md`)

## 1. Overview & Architecture
The Instagram-to-Reference Import System lets an administrator create a complete, internally consistent reference draft (`upx_reference`)—including English title, slug, excerpt, structured B2B HTML article, SEO fields, sideloaded Media Library photos with descriptive alt text, AI metadata suggestions, and 5 translations (`TR`, `DE`, `FR`, `AR`, `RU`)—from a **single Instagram post** in two input modes:
- **Mode A (`From Instagram link`):** Admin pastes an Instagram URL (`instagram.com/p/{code}/`, `/reel/{code}/`, `/tv/{code}/`, `/{username}/p/{code}/`, `instagr.am/...`). The server performs an anonymous public HTTP GET (`UPX_Instagram_Post_Extractor`), extracts the caption and carousel images, and presents them in the Preview step.
- **Mode B (`Caption + images`):** Admin pastes the caption and selects/uploads the photos manually. If Mode A fails or partially succeeds, the UI automatically falls back to Mode B with any fetched data pre-filled and a clear notice explaining what is missing.

### Key Files
- `wp-plugin/includes/class-upx-ig-extractor.php` — Isolated extractor class (`UPX_Instagram_Post_Extractor`) implementing URL normalization, Strategy 1 (`/p/{code}/embed/captioned/`), Strategy 2 (`/p/{code}/`), login-wall & error-shell (`PolarisErrorRoot`) detection, and 10-minute transient caching.
- `wp-plugin/includes/refs-ig.php` — AJAX endpoints (`upx_ig_fetch`, `upx_ig_generate`, `upx_ig_regenerate`, `upx_ig_ack_stale`), multimodal Gemini structured JSON prompt, PHP Consistency Validator (`upx_ig_validate_content`), Media Library sideloader with deduplication (`upx_ig_sideload_images`), and draft creation pipeline.
- `wp-plugin/includes/refs-ai.php` — Shared Gemini API client (`upx_gemini_call`) with multimodal `inlineData` and automatic model fallback, plus the multilingual translation pipeline (`upx_ai_translate_reference`).
- `wp-plugin/includes/refs-admin.php` — 3-tab UI in `AI Assistant & Quick Draft`, `"Add from Instagram"` button on the References list screen, AI suggestion badges (`[Apply]`, `"Apply all AI suggestions"`), stale content banner, and server-side Publish Gate (`upx_ref_require_fields`).
- `wp-plugin/includes/refs-store.php` — Reference metadata storage (`_upx_ref`, `_upx_l10n`) allowing `kind` (`Type`) to start empty on Instagram drafts until selected by the admin.
- `wp-plugin/assets/refs-admin.js` & `wp-plugin/assets/refs-admin.css` — Interactive Preview tray (sortable thumbnails, cover selection, WP Media uploader integration), real-time consistency hash checking, and client-side Publish Gate checklist.

---

## 2. Mode A vs. Mode B Behavior by Post Type
Because no Meta/Instagram API, login cookies, or third-party scraping services are used:
- **Public Single Image Posts (`/p/{code}/`)**:
  - **Strategy 1 (`/embed/captioned/`)** fetches both the `.Caption` text and the image (`<= 1440px`).
  - **Strategy 2 (`/p/{code}/`)** serves as fallback via `og:description` and `og:image`.
- **Public Carousel Posts (`/p/{code}/` with multiple slides)**:
  - **Strategy 1 (`/embed/captioned/`)** parses the embedded `<script>` JSON (`contextJSON` / `shortcode_media` / `edge_sidecar_to_children`) recursively to extract all carousel images (`<= 1440px` wide).
  - If Instagram withholds the embedded script JSON and only serves the first `.EmbeddedMediaImage` or `og:image`, the result is marked `partial` with the notice: *"Caption fetched. Only the first image could be fetched — upload any remaining carousel photos if needed."*
- **Public Reels / Videos (`/reel/{code}/`, `/tv/{code}/`, or video slides in carousels)**:
  - Video files are not imported as reference photos; the extractor keeps the video thumbnail image and flags it with a red **`Video thumb`** badge (`"video — thumbnail only, upload a photo if needed"`).
- **Unavailable Posts, Age/Embed-Restricted Posts, or Data-Center IP Blocks**:
  - When Instagram redirects to `/accounts/login/` or returns HTTP `200` with its Polaris error shell (`PolarisErrorRoot.entrypoint` / `httpErrorPage`), `UPX_Instagram_Post_Extractor` detects the block/error, never treats it as content, and automatically switches the UI to **Mode B (`Caption + images`)** with a clear explanation.

---

## 3. PHP Consistency Validator & 1-Retry Loop
Before saving generated content, `upx_ig_validate_content()` in `wp-plugin/includes/refs-ig.php` validates:
1. **Country Consistency:** If `suggested_country` is non-null, its English country name (or recognized variant such as `Türkiye`/`Turkey`, `United Kingdom`/`UK`, `USA`/`United States`) must appear in `title`, `slug`, `meta_description`, and the first `<p>` paragraph of `body_html`.
2. **Court Type Internal Linking:** Every item in `suggested_court_types` must be linked (`href="/.../"`) inside `body_html`, and no unselected product URL may be linked.
3. **No Invented Court Counts:** If the caption contains no digits or number words (`one`–`ten`), the generated `title` and `body_html` must not contain any specific court count (e.g. `"4 courts"`).
4. **No Unverified FIP Claims:** `"FIP-approved"` or `"FIP-certified"` is prohibited unless `"FIP"` appears in the caption.
5. **Length & Cleanliness:** `title <= 70` chars, `seo_title <= 60` chars, `meta_description` `140–155` chars, `slug` matches `^[a-z0-9-]+$` (`<= 60` chars), and zero `#hashtags` or emojis.

If any rule fails, the exact error list is fed back to Gemini for **1 automatic retry**, followed by deterministic PHP sanitization (`upx_ig_sanitize_final_output()`).

---

## 4. Cover Image Cropping & Orientation Behavior
- **Reference Detail Hero (`src/components/Hero.astro`):** Uses `aspect-ratio: 1 / 1` on desktop (`>= 960px`) and `aspect-ratio: 4 / 3` on mobile (`< 960px`) with `object-fit: cover`.
- **Reference Cards (`src/components/ReferenceCard.astro`):** Uses `aspect-ratio: 1 / 1` on the home page and product pages, and `aspect-ratio: 4 / 3` on the `/references/` hub with `object-fit: cover`.
- **Handling Instagram Aspect Ratios:** Instagram posts are frequently square (`1080×1080`) or portrait (`1080×1350`). Because all front-end containers use `object-fit: cover`, square and portrait photos do not break the layout. For `_source = instagram`, images `>= 1080px` wide of any orientation are accepted, and if the chosen cover photo is square or portrait (`width <= height`), a non-blocking review note is added reminding the admin to verify the `4:3` card crop in Preview.

---

## 5. Manual Test Walkthrough
1. **Open Add from Instagram:**
   - Go to **WP Admin → References** and click **Add from Instagram** (or open **Add New Reference** where the **From Instagram link** tab is active).
2. **Test Mode A (Link Fetch) & Automatic Fallback to Mode B:**
   - Paste `https://www.instagram.com/p/DetpGv9EVky/` and click **Fetch**.
   - If the server can reach the post, the caption and images populate the Preview tray. If Instagram blocks anonymous access or the post is unavailable, the UI automatically activates **Caption + images (Mode B)** and prompts you to paste the caption and select photos.
3. **Approve in Preview Step:**
   - Ensure the caption textarea is filled and at least 1 photo is in the tray (use **Add images** to pick/upload photos, drag thumbnails to reorder, or click **Set as cover**).
   - Click **Generate reference**.
4. **Review Draft & AI Suggestions (Phase 5):**
   - The page reloads into the created draft (`post.php?post=ID&action=edit`).
   - Scroll to **Reference details**: notice that **Type**, **Country**, and **Court type** start **EMPTY**, each displaying `AI suggestion: <value> (<confidence>) [Apply]`.
   - Click **Apply all AI suggestions** (or click `[Apply]` on individual fields).
5. **Test Stale Detection & Regeneration:**
   - Change **Country** or **Court type** to a different value than what the AI suggested.
   - The red **Content is stale** banner appears immediately: *"Text was generated for X; you selected Y."*
   - Click **Regenerate text** to rewrite the English article and all 5 translations for your new selection (or click **I have checked the text** to confirm manually).
6. **Test Publish Gate (Phase 6):**
   - Try clicking **Publish** while **Type**, **Country**, or **Court type** is empty, or while the **Content is stale** banner is active: client-side validation alerts you and server-side validation (`upx_ref_require_fields`) keeps the post as a `draft`.
   - Once all 4 checklist items in **Publish Readiness Checklist** are green (`✓`), click **Publish** — the reference goes live across EN, TR, DE, FR, AR, and RU!
