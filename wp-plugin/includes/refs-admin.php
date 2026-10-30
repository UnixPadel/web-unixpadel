<?php
/**
 * WP admin screens for references (post type upx_reference): details box (address, country, court types, SEO,
 * gallery), one box per translation, preview links, publish checks and list columns. Storage: refs-store.php.
 */

if (!defined('ABSPATH')) {
	exit;
}

const UPX_LANG_NAMES = array('en' => 'English', 'tr' => 'Türkçe', 'de' => 'Deutsch', 'fr' => 'Français', 'ar' => 'العربية', 'ru' => 'Русский');

/* ---------- Boxes ---------- */

function upx_ref_boxes($post) {
	remove_meta_box('slugdiv', UPX_REF_TYPE, 'normal'); // the address field lives in the details box
	add_meta_box('upx-ref-ai', 'AI Assistant & Quick Draft', 'upx_ref_ai_box', UPX_REF_TYPE, 'normal', 'high');
	add_meta_box('upx-ref-details', 'Reference details', 'upx_ref_details_box', UPX_REF_TYPE, 'normal', 'high');
	foreach (UPX_REF_LANGS as $lang) {
		add_meta_box('upx-ref-' . $lang, UPX_LANG_NAMES[$lang] . ' (' . strtoupper($lang) . ') — optional', 'upx_ref_lang_box', UPX_REF_TYPE, 'normal', 'default', array('lang' => $lang));
	}
	add_meta_box('upx-ref-pages', 'Pages and preview', 'upx_ref_pages_box', UPX_REF_TYPE, 'side', 'high');
}
add_action('add_meta_boxes_' . UPX_REF_TYPE, 'upx_ref_boxes');

function upx_ref_ai_box($post) {
	wp_nonce_field('upx_ai_action', 'upx_ai_nonce');
	$is_ig      = get_post_meta($post->ID, '_source', true) === 'instagram';
	$raw_cap    = (string) get_post_meta($post->ID, '_ig_raw_caption', true);
	$permalink  = (string) get_post_meta($post->ID, '_ig_permalink', true);
	$shortcode  = (string) get_post_meta($post->ID, '_ig_shortcode', true);
	$active_tab = (isset($_GET['upx_mode']) && $_GET['upx_mode'] === 'ig_manual') ? 'ig-manual' : 'ig-link';
	?>
	<div class="upx-ai-box" data-post-id="<?php echo (int) $post->ID; ?>">
		<div class="upx-ai-tabs" role="tablist">
			<button type="button" class="upx-ai-tab <?php echo $active_tab === 'ig-link' ? 'is-active' : ''; ?>" data-tab="ig-link" role="tab">
				<span class="dashicons dashicons-instagram"></span> From Instagram link
			</button>
			<button type="button" class="upx-ai-tab <?php echo $active_tab === 'ig-manual' ? 'is-active' : ''; ?>" data-tab="ig-manual" role="tab">
				<span class="dashicons dashicons-format-gallery"></span> Caption + images
			</button>
			<button type="button" class="upx-ai-tab" data-tab="quick-notes" role="tab">
				<span class="dashicons dashicons-edit"></span> Quick notes
			</button>
		</div>

		<!-- TAB 1: Mode A — From Instagram link -->
		<div class="upx-ai-pane <?php echo $active_tab === 'ig-link' ? 'is-active' : ''; ?>" id="upx-pane-ig-link">
			<p class="description" style="margin-top:0;font-size:13px">
				Paste a public Instagram post or reel link (e.g. <code>https://www.instagram.com/p/DetpGv9EVky/</code>). The system will fetch the caption and carousel photos for your review. If Instagram blocks anonymous access, it will automatically fall back to manual input below.
			</p>
			<div class="upx-ig-url-bar">
				<input type="url" id="upx-ig-url" class="large-text code" value="<?php echo esc_attr($permalink); ?>" placeholder="https://www.instagram.com/p/DetpGv9EVky/">
				<button type="button" class="button button-primary" id="upx-ig-fetch-btn">
					<span class="dashicons dashicons-download" style="vertical-align:text-bottom;margin-right:2px"></span> Fetch
				</button>
				<span id="upx-ig-fetch-status" class="upx-ai-status"></span>
			</div>
			<div id="upx-ig-duplicate-notice" class="notice notice-warning inline" style="display:none;margin:10px 0;padding:8px 12px">
				<p style="margin:0">
					<strong>Duplicate detected:</strong> A reference with this Instagram shortcode already exists:
					<a href="#" id="upx-ig-dup-link" target="_blank" rel="noopener"></a>
					(<span id="upx-ig-dup-status"></span>).
					<button type="button" class="button button-small" id="upx-ig-dup-continue" style="margin-left:8px">Continue anyway</button>
				</p>
			</div>
		</div>

		<!-- TAB 2: Mode B — Caption + images header -->
		<div class="upx-ai-pane <?php echo $active_tab === 'ig-manual' ? 'is-active' : ''; ?>" id="upx-pane-ig-manual">
			<p class="description" style="margin-top:0;font-size:13px">
				Paste the Instagram caption and select or upload the post photos below. Reorder or pick the cover image, then click <strong>Generate reference</strong>.
			</p>
		</div>

		<!-- SHARED PREVIEW / APPROVAL STEP (shown for Mode A after fetch & always for Mode B) -->
		<div class="upx-ig-preview" id="upx-ig-preview" style="<?php echo ($active_tab === 'ig-manual' || $raw_cap !== '') ? '' : 'display:none;'; ?>">
			<div id="upx-ig-preview-banner" class="upx-ig-banner" style="display:none"></div>
			<input type="hidden" id="upx-ig-shortcode" value="<?php echo esc_attr($shortcode); ?>">
			<input type="hidden" id="upx-ig-permalink" value="<?php echo esc_attr($permalink); ?>">
			<input type="hidden" id="upx-ig-location" value="">
			<input type="hidden" id="upx-ig-input-mode" value="<?php echo $active_tab === 'ig-link' ? 'link' : 'manual'; ?>">

			<div class="upx-ig-preview__field">
				<label for="upx-ig-caption"><strong>Instagram Caption</strong> <span class="description" style="display:inline">(editable — Gemini uses ONLY facts present in this caption)</span></label>
				<textarea id="upx-ig-caption" class="large-text" rows="5" placeholder="Paste the Instagram post caption here..."><?php echo esc_textarea($raw_cap); ?></textarea>
			</div>

			<div class="upx-ig-preview__field">
				<div class="upx-ig-tray-header">
					<strong>Images</strong> <span class="description" style="display:inline">(drag to reorder · click “Set as cover” to pick the Main photo · minimum 1 image)</span>
					<button type="button" class="button button-small" id="upx-ig-add-images" style="margin-left:auto">
						<span class="dashicons dashicons-plus-alt2" style="vertical-align:text-bottom"></span> Add images
					</button>
				</div>
				<ul class="upx-ig-tray" id="upx-ig-tray"></ul>
				<p id="upx-ig-tray-empty" class="description" style="margin:6px 0">No images in tray yet. Fetch from an Instagram link or click <strong>Add images</strong> to upload/select photos.</p>
			</div>

			<div class="upx-ai-actions" style="margin-top:10px">
				<button type="button" class="button button-primary button-hero" id="upx-ig-generate-btn" disabled>
					<span class="dashicons dashicons-sparkles" style="vertical-align:text-bottom;margin-right:4px"></span>
					Generate reference
				</button>
				<span id="upx-ig-generate-status" class="upx-ai-status"></span>
			</div>
		</div>

		<!-- TAB 3: Quick notes (existing behaviour, unchanged) -->
		<div class="upx-ai-pane" id="upx-pane-quick-notes">
			<p class="description" style="margin-top:0;font-size:13px">
				Enter a few quick notes (in <strong>Turkish or English</strong>). Gemini AI will write an engaging English title, structured article with specs, detect the country and court type, and translate everything into <strong>Turkish, German, French, Arabic, and Russian</strong>.
			</p>
			<p style="margin:8px 0">
				<textarea id="upx-ai-notes" class="large-text" rows="3" placeholder="e.g. Vienna, Austria: 4 Panoramic Padel courts for Vienna Padel Club. Premium blue turf, 200W LED lighting, high wind resistance, turnkey build completed on schedule..."></textarea>
			</p>
			<div class="upx-ai-actions">
				<button type="button" class="button button-primary" id="upx-ai-btn-draft">
					<span class="dashicons dashicons-sparkles" style="vertical-align:text-bottom;margin-right:2px"></span>
					Generate Draft & All Languages
				</button>
				<button type="button" class="button" id="upx-ai-btn-english-only" style="margin-left:6px">
					Generate English Only
				</button>
				<span id="upx-ai-draft-status" class="upx-ai-status"></span>
			</div>
		</div>
	</div>
	<?php
}

/** Translation boxes start closed: most references are added in English first. */
function upx_ref_closed_boxes($closed) {
	$closed = is_array($closed) ? $closed : array();
	foreach (UPX_REF_LANGS as $lang) {
		$closed[] = 'upx-ref-' . $lang;
	}
	return array_unique($closed);
}

function upx_ref_default_closed() {
	$user = get_current_user_id();
	if ($user && get_user_meta($user, 'closedpostboxes_' . UPX_REF_TYPE, true) === '') {
		update_user_meta($user, 'closedpostboxes_' . UPX_REF_TYPE, upx_ref_closed_boxes(array()));
	}
}
add_action('load-post-new.php', 'upx_ref_default_closed');
add_action('load-post.php', 'upx_ref_default_closed');

function upx_ref_details_box($post) {
	$data = upx_data();
	$meta = upx_ref_meta($post->ID);
	wp_nonce_field('upx_ref_save', 'upx_ref_nonce');
	if (!$data) {
		echo '<p class="notice notice-error inline">This plugin build has no reference templates (upx-shell/data.json). Rebuild the plugin with <code>npm run build:wp</code>.</p>';
		return;
	}
	$slug        = upx_ref_clean_slug($post->post_name);
	$is_ig       = get_post_meta($post->ID, '_source', true) === 'instagram';
	$ig_mode     = (string) get_post_meta($post->ID, '_input_mode', true);
	$ig_link     = (string) get_post_meta($post->ID, '_ig_permalink', true);
	$ig_code     = (string) get_post_meta($post->ID, '_ig_shortcode', true);
	$sug_type    = (string) get_post_meta($post->ID, '_ai_suggested_type', true);
	$sug_country = (string) get_post_meta($post->ID, '_ai_suggested_country', true);
	$sug_types   = get_post_meta($post->ID, '_ai_suggested_court_types', true);
	$sug_types   = is_array($sug_types) ? $sug_types : array();
	$sug_conf    = get_post_meta($post->ID, '_ai_suggestion_confidence', true);
	$sug_conf    = is_array($sug_conf) ? $sug_conf : array('type' => 'high', 'country' => 'medium', 'court_types' => 'medium');
	$missing_inf = get_post_meta($post->ID, '_ai_missing_info', true);
	$missing_inf = is_array($missing_inf) ? $missing_inf : array();
	$consistency = upx_ig_consistency_state($post->ID);

	$sug_type_label    = $sug_type === 'news' ? 'Company news' : ($sug_type === 'project' ? 'Project' : '');
	$sug_country_label = ($sug_country !== '' && isset($data['l10n']['en']['countries'][$sug_country])) ? $data['l10n']['en']['countries'][$sug_country] : '';
	$sug_types_labels  = array();
	foreach ($sug_types as $tu) {
		if (isset($data['l10n']['en']['products'][$tu]['label'])) {
			$sug_types_labels[] = $data['l10n']['en']['products'][$tu]['label'];
		}
	}
	$has_any_suggestion = ($sug_type !== '' || $sug_country !== '' || !empty($sug_types));
	?>
	<div class="upx-ref" data-is-ig="<?php echo $is_ig ? '1' : '0'; ?>" data-generated-for="<?php echo esc_attr($consistency['generated_hash']); ?>">
		<?php if ($is_ig) : ?>
			<div class="upx-ig-source-bar">
				<div class="upx-ig-source-bar__info">
					<span class="dashicons dashicons-instagram"></span>
					<strong>Imported from Instagram</strong>
					(<?php echo $ig_mode === 'link' ? 'Link mode' : 'Manual caption + images'; ?>)
					<?php if ($ig_link !== '') : ?>
						· <a href="<?php echo esc_url($ig_link); ?>" target="_blank" rel="noopener"><code><?php echo esc_html($ig_code !== '' ? $ig_code : $ig_link); ?></code> ↗</a>
					<?php endif; ?>
				</div>
				<?php if ($has_any_suggestion) : ?>
					<button type="button" class="button button-primary button-small" id="upx-ig-apply-all"
						data-type="<?php echo esc_attr($sug_type); ?>"
						data-country="<?php echo esc_attr($sug_country); ?>"
						data-types="<?php echo esc_attr(wp_json_encode($sug_types)); ?>">
						<span class="dashicons dashicons-yes-alt" style="vertical-align:text-bottom"></span> Apply all AI suggestions
					</button>
				<?php endif; ?>
			</div>

			<?php if (!empty($missing_inf)) : ?>
				<div class="upx-ig-missing-box">
					<strong><span class="dashicons dashicons-info-outline" style="vertical-align:text-bottom"></span> AI Review Notes &amp; Missing Info:</strong>
					<ul>
						<?php foreach ($missing_inf as $mi) : ?>
							<li><?php echo esc_html($mi); ?></li>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php endif; ?>

			<div id="upx-ig-stale-bar" class="upx-ig-stale-bar" style="<?php echo $consistency['is_stale'] ? '' : 'display:none;'; ?>">
				<div class="upx-ig-stale-bar__text">
					<strong><span class="dashicons dashicons-warning" style="vertical-align:text-bottom;color:#b32d2e"></span> Content is stale:</strong>
					Text was generated for <strong id="upx-ig-stale-gen-label"><?php echo esc_html($consistency['generated_label']); ?></strong>;
					you selected <strong id="upx-ig-stale-cur-label"><?php echo esc_html($consistency['current_label']); ?></strong>.
				</div>
				<div class="upx-ig-stale-bar__actions">
					<button type="button" class="button button-primary" id="upx-ig-btn-regenerate">
						<span class="dashicons dashicons-update" style="vertical-align:text-bottom"></span> Regenerate text (keep images &amp; selections)
					</button>
					<button type="button" class="button" id="upx-ig-btn-ack-stale">
						I have checked the text
					</button>
					<span id="upx-ig-stale-status" class="upx-ai-status"></span>
				</div>
			</div>
		<?php endif; ?>

		<p class="upx-ref__row">
			<label for="upx-slug"><strong>Address</strong></label><br>
			<code><?php echo esc_html(home_url('/references/')); ?></code><input type="text" id="upx-slug" name="post_name" value="<?php echo esc_attr($slug); ?>" class="regular-text code" pattern="[a-z0-9-]*" placeholder="made-from-the-title"><code>/</code>
			<span class="description">Letters a–z, digits and hyphens. Leave empty to make it from the title. Addresses of existing pages get a number added (oman → oman-2).</span>
		</p>
		<div class="upx-ref__row">
			<label for="upx-kind"><strong>Type</strong> <?php if ($is_ig) : ?><span class="upx-req">*</span><?php endif; ?></label>
			<?php if ($is_ig && $sug_type_label !== '') : ?>
				<span class="upx-ai-suggestion" id="upx-sug-badge-type">
					AI suggestion: <strong><?php echo esc_html($sug_type_label); ?></strong>
					<span class="upx-ai-conf upx-ai-conf--<?php echo esc_attr($sug_conf['type']); ?>"><?php echo esc_html($sug_conf['type']); ?></span>
					<button type="button" class="button-link upx-apply-single" data-field="type" data-value="<?php echo esc_attr($sug_type); ?>">[Apply]</button>
				</span>
			<?php endif; ?>
			<br>
			<select id="upx-kind" name="upx_ref[kind]">
				<?php if ($is_ig) : ?>
					<option value="" <?php selected($meta['kind'], ''); ?>>— Select type (required to publish) —</option>
				<?php endif; ?>
				<option value="project" <?php selected($meta['kind'], 'project'); ?>>Project (card on /references/, the home page and product pages)</option>
				<option value="news" <?php selected($meta['kind'], 'news'); ?>>Company news (list under the projects on /references/)</option>
			</select>
		</div>
		<?php if ($meta['image_url'] !== '' && !has_post_thumbnail($post)) : ?>
			<p class="upx-ref__row notice notice-info inline">Imported photo, not in the media library: <code><?php echo esc_html($meta['image_url']); ?></code>. It is shown until you set a Main photo.</p>
		<?php endif; ?>
		<div class="upx-ref__row">
			<label for="upx-country"><strong>Country</strong> <?php if ($is_ig) : ?><span class="upx-req">*</span><?php endif; ?></label>
			<?php if ($is_ig && $sug_country_label !== '') : ?>
				<span class="upx-ai-suggestion" id="upx-sug-badge-country">
					AI suggestion: <strong><?php echo esc_html($sug_country_label); ?></strong>
					<span class="upx-ai-conf upx-ai-conf--<?php echo esc_attr($sug_conf['country']); ?>"><?php echo esc_html($sug_conf['country']); ?></span>
					<button type="button" class="button-link upx-apply-single" data-field="country" data-value="<?php echo esc_attr($sug_country); ?>">[Apply]</button>
				</span>
			<?php elseif ($is_ig) : ?>
				<span class="upx-ai-suggestion upx-ai-suggestion--none">AI suggestion: <em>None detected in caption — select manually</em></span>
			<?php endif; ?>
			<br>
			<select id="upx-country" name="upx_ref[country]">
				<option value=""><?php echo $is_ig ? '— Select country (required to publish) —' : '— None (filter “Other”) —'; ?></option>
				<?php foreach ($data['countries'] as $c) : ?>
					<option value="<?php echo esc_attr($c['slug']); ?>" <?php selected($meta['country'], $c['slug']); ?>><?php echo esc_html($data['l10n']['en']['countries'][$c['slug']]); ?></option>
				<?php endforeach; ?>
			</select>
			<span class="description">Shown on the card, in the facts box and as a filter on /references/ (in every language).</span>
		</div>
		<fieldset class="upx-ref__row upx-ref__types" data-max="<?php echo (int) $data['limits']['types']; ?>">
			<legend>
				<strong>Court type</strong> <?php if ($is_ig) : ?><span class="upx-req">*</span><?php endif; ?>
				<span class="description">(up to <?php echo (int) $data['limits']['types']; ?>; links to the product pages)</span>
				<?php if ($is_ig && !empty($sug_types_labels)) : ?>
					<span class="upx-ai-suggestion" id="upx-sug-badge-types">
						AI suggestion: <strong><?php echo esc_html(implode(' + ', $sug_types_labels)); ?></strong>
						<span class="upx-ai-conf upx-ai-conf--<?php echo esc_attr($sug_conf['court_types']); ?>"><?php echo esc_html($sug_conf['court_types']); ?></span>
						<button type="button" class="button-link upx-apply-single" data-field="types" data-value="<?php echo esc_attr(wp_json_encode($sug_types)); ?>">[Apply]</button>
					</span>
				<?php elseif ($is_ig) : ?>
					<span class="upx-ai-suggestion upx-ai-suggestion--none">AI suggestion: <em>None detected in caption — select manually</em></span>
				<?php endif; ?>
			</legend>
			<?php foreach ($data['products'] as $p) : ?>
				<label><input type="checkbox" name="upx_ref[types][]" value="<?php echo esc_attr($p['url']); ?>" <?php checked(in_array($p['url'], $meta['types'], true)); ?>> <?php echo esc_html($data['l10n']['en']['products'][$p['url']]['label']); ?></label>
			<?php endforeach; ?>
		</fieldset>
		<div class="upx-ref__row upx-ref__ai-bar">
			<label><strong>Translations</strong></label><br>
			<button type="button" class="button" id="upx-ai-btn-translate-all">
				<span class="dashicons dashicons-translation" style="vertical-align:text-bottom;margin-right:2px"></span>
				Translate to All Languages (TR, DE, FR, AR, RU)
			</button>
			<label style="margin-left:12px;font-size:13px">
				<input type="checkbox" id="upx-ai-overwrite" value="1"> Overwrite existing translations
			</label>
			<span id="upx-ai-translate-status" class="upx-ai-status"></span>
			<span class="description" style="margin-top:4px">Missing languages are also automatically translated when you click <strong>Publish</strong> or <strong>Save Draft</strong>.</span>
		</div>
		<details class="upx-ref__row upx-ref__advanced-seo" <?php echo ($meta['seo_title'] !== '' || $meta['seo_desc'] !== '' || $meta['focus_keyphrase'] !== '') ? 'open' : ''; ?>>
			<summary><strong>Advanced SEO</strong> <span class="description" style="display:inline">(optional — auto-generated if left empty)</span></summary>
			<div style="margin-top:8px">
				<p class="upx-ref__subrow" style="margin-bottom:8px">
					<label for="upx-seo-title"><strong>SEO title</strong> <span class="description">(“| Unixpadel” is added when it fits)</span></label><br>
					<input type="text" id="upx-seo-title" name="upx_ref[seo_title]" value="<?php echo esc_attr($meta['seo_title']); ?>" class="large-text" data-count="60">
				</p>
				<p class="upx-ref__subrow" style="margin-bottom:8px">
					<label for="upx-seo-desc"><strong>Meta description</strong> <span class="description">(140–155 chars; else Excerpt, else first sentence)</span></label><br>
					<textarea id="upx-seo-desc" name="upx_ref[seo_desc]" rows="2" class="large-text" data-count="155"><?php echo esc_textarea($meta['seo_desc']); ?></textarea>
				</p>
				<p class="upx-ref__subrow">
					<label for="upx-focus-keyphrase"><strong>Focus keyphrase</strong> <span class="description">(e.g. “padel courts in Türkiye”)</span></label><br>
					<input type="text" id="upx-focus-keyphrase" name="upx_ref[focus_keyphrase]" value="<?php echo esc_attr($meta['focus_keyphrase']); ?>" class="regular-text">
				</p>
			</div>
		</details>
		<div class="upx-ref__row">
			<strong>Gallery</strong> <span class="description">(photos shown under the text; the main photo and photos already in the text are left out. If no Main photo is set, the first photo here is used automatically). Drag to reorder.</span>
			<input type="hidden" name="upx_ref[gallery]" id="upx-gallery" value="<?php echo esc_attr(implode(',', $meta['gallery'])); ?>">
			<ul class="upx-gallery" id="upx-gallery-list">
				<?php foreach ($meta['gallery'] as $item) : ?>
					<?php
					// Imported photos that are not in the media library are kept by their /wp-content/… path.
					$thumb = is_int($item) ? wp_get_attachment_image_url($item, 'thumbnail') : (upx_data() ? rtrim(upx_data()['media'], '/') . $item : $item);
					?>
					<?php if ($thumb) : ?>
						<li data-id="<?php echo esc_attr((string) $item); ?>"><img src="<?php echo esc_url($thumb); ?>" alt=""><button type="button" class="upx-gallery__remove" aria-label="Remove">×</button></li>
					<?php endif; ?>
				<?php endforeach; ?>
			</ul>
			<button type="button" class="button" id="upx-gallery-add">Add photos</button>
		</div>
		<?php if ($is_ig) : ?>
			<div id="upx-ig-publish-gate" class="upx-ig-publish-gate"></div>
			<p class="description">Required to publish an Instagram reference: English title, English text, Main photo (at least 1080 px wide), <strong>Type</strong>, <strong>Country</strong>, at least 1 <strong>Court type</strong>, and verified text consistency (not stale).</p>
		<?php else : ?>
			<p class="description">Required to publish: English title, English text and a main photo (box “Main photo”, landscape, at least 1600 px wide). The <strong>Excerpt</strong> box is the summary under the title and on the card.</p>
		<?php endif; ?>
	</div>
	<?php
}

function upx_ref_lang_box($post, $box) {
	$lang = $box['args']['lang'];
	$all  = upx_ref_l10n($post->ID);
	$l    = isset($all[$lang]) && is_array($all[$lang]) ? $all[$lang] : array();
	$v    = function ($k) use ($l) {
		return isset($l[$k]) ? (string) $l[$k] : '';
	};
	$name = 'upx_l10n[' . $lang . ']';
	$dir  = $lang === 'ar' ? 'rtl' : 'ltr';
	?>
	<div class="upx-ref upx-ref--lang" dir="<?php echo esc_attr($dir); ?>">
		<p class="description" dir="ltr">The <?php echo esc_html(UPX_LANG_NAMES[$lang]); ?> page exists only when <strong>title and text</strong> are both filled. Otherwise /<?php echo esc_html($lang); ?>/references/ shows the English card.</p>
		<p><label><strong>Title</strong><br><input type="text" name="<?php echo esc_attr($name); ?>[title]" value="<?php echo esc_attr($v('title')); ?>" class="large-text"></label></p>
		<p><label><strong>Excerpt</strong> (summary under the title and on the card)<br><textarea name="<?php echo esc_attr($name); ?>[excerpt]" rows="2" class="large-text"><?php echo esc_textarea($v('excerpt')); ?></textarea></label></p>
		<p><label><strong>SEO title</strong> (optional)<br><input type="text" name="<?php echo esc_attr($name); ?>[seo_title]" value="<?php echo esc_attr($v('seo_title')); ?>" class="large-text" data-count="60"></label></p>
		<p><label><strong>Meta description</strong> (optional)<br><textarea name="<?php echo esc_attr($name); ?>[seo_desc]" rows="2" class="large-text" data-count="158"><?php echo esc_textarea($v('seo_desc')); ?></textarea></label></p>
		<p><strong>Text</strong></p>
		<?php
		wp_editor($v('body'), 'upxbody' . $lang, array(
			'textarea_name' => $name . '[body]',
			'textarea_rows' => 14,
			'media_buttons' => true,
			'tinymce'       => $lang === 'ar' ? array('directionality' => 'rtl') : true,
		));
		?>
	</div>
	<?php
}

function upx_ref_pages_box($post) {
	$data = upx_data();
	if (!$data) {
		return;
	}
	$published = $post->post_status === 'publish';
	$slug      = upx_ref_clean_slug($post->post_name);
	echo '<ul class="upx-pages">';
	foreach ($data['langs'] as $lang) {
		$has     = upx_ref_has_text(upx_ref_fields($post, $lang));
		$preview = add_query_arg(array('upx_ref_preview' => $post->ID, 'upx_lang' => $lang), home_url('/'));
		echo '<li><span class="upx-pages__lang">' . esc_html(strtoupper($lang)) . '</span> ';
		if (!$has) {
			echo '<span class="upx-pages__none">' . ($lang === 'en' ? 'title and text missing' : 'empty <span style="color:#2271b1">(auto-translates on publish)</span>') . '</span>';
		} else {
			if ($published && $slug !== '') {
				$url = home_url($data['l10n'][$lang]['hub'] . $slug . '/');
				echo '<a href="' . esc_url($url) . '" target="_blank" rel="noopener">View</a> · ';
			}
			echo '<a href="' . esc_url($preview) . '" target="_blank" rel="noopener">Preview</a>';
		}
		echo '</li>';
	}
	echo '</ul><p class="description">Preview shows the last saved version: <strong>Save Draft</strong> (or Update) first. Auto-translation is active: publishing automatically completes any missing languages.</p>';
}

/* ---------- Saving ---------- */

function upx_ref_save($post_id, $post) {
	if (!isset($_POST['upx_ref_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['upx_ref_nonce'])), 'upx_ref_save')) {
		return;
	}
	if ((defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) || wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
		return;
	}
	if (!current_user_can('edit_post', $post_id)) {
		return;
	}
	$data  = upx_data();
	$is_ig = get_post_meta($post_id, '_source', true) === 'instagram';
	$in    = isset($_POST['upx_ref']) && is_array($_POST['upx_ref']) ? wp_unslash($_POST['upx_ref']) : array();
	$cap   = function ($s, $max) {
		return mb_substr((string) $s, 0, $max, 'UTF-8');
	};

	$countries = $data ? wp_list_pluck($data['countries'], 'slug') : array();
	$products  = $data ? wp_list_pluck($data['products'], 'url') : array();
	$country   = isset($in['country']) ? sanitize_title((string) $in['country']) : '';
	$types     = isset($in['types']) && is_array($in['types']) ? array_values(array_intersect(array_map('strval', $in['types']), $products)) : array();
	$gallery   = array();
	foreach (explode(',', isset($in['gallery']) ? (string) $in['gallery'] : '') as $item) {
		$item = trim($item);
		if (preg_match('#^/wp-content/uploads/[A-Za-z0-9/_.\-]+$#', $item)) {
			$value = $item; // imported photo kept by its path
		} else {
			$value = absint($item);
			if (!$value || !wp_attachment_is_image($value)) {
				continue;
			}
		}
		if (!in_array($value, $gallery, true)) {
			$gallery[] = $value;
		}
	}

	// Auto-detect country and court types if left empty ONLY for non-Instagram references.
	// Instagram-sourced drafts require explicit admin review and selection in Phase 5.
	if (!$is_ig) {
		$raw_text = $post->post_title . ' ' . $post->post_content;
		if ($country === '' && function_exists('upx_ai_detect_country')) {
			$detected_c = upx_ai_detect_country($raw_text);
			if ($detected_c !== '' && in_array($detected_c, $countries, true)) {
				$country = $detected_c;
			}
		}
		if (empty($types) && function_exists('upx_ai_detect_court_types')) {
			$detected_t = upx_ai_detect_court_types($raw_text);
			if (!empty($detected_t)) {
				$types = array_values(array_intersect($detected_t, $products));
			}
		}
	}

	// Auto-set main photo from gallery if not set.
	if (!has_post_thumbnail($post_id) && !empty($gallery)) {
		foreach ($gallery as $g) {
			if (is_int($g) && $g > 0 && wp_attachment_is_image($g)) {
				set_post_thumbnail($post_id, $g);
				break;
			}
		}
	}

	$raw_kind = isset($in['kind']) ? (string) $in['kind'] : '';
	if ($raw_kind === 'news' || $raw_kind === 'project') {
		$saved_kind = $raw_kind;
	} else {
		$saved_kind = $is_ig ? '' : 'project';
	}

	$old  = upx_ref_meta($post_id);
	$meta = array(
		'country'         => in_array($country, $countries, true) ? $country : '',
		'types'           => array_slice($types, 0, $data ? (int) $data['limits']['types'] : 2),
		'seo_title'       => $cap(sanitize_text_field(isset($in['seo_title']) ? $in['seo_title'] : ''), 200),
		'seo_desc'        => $cap(sanitize_textarea_field(isset($in['seo_desc']) ? $in['seo_desc'] : ''), 400),
		'focus_keyphrase' => $cap(sanitize_text_field(isset($in['focus_keyphrase']) ? $in['focus_keyphrase'] : ''), 150),
		'gallery'         => array_slice($gallery, 0, 60),
		'kind'            => $saved_kind,
		// kept from the import: place in the list and the photo shown until a Main photo is set
		'sort'            => $old['sort'],
		'image_url'       => has_post_thumbnail($post_id) ? '' : $old['image_url'],
		'image_alt'       => $old['image_alt'],
	);
	update_post_meta($post_id, UPX_META_REF, wp_slash($meta));

	$raw  = isset($_POST['upx_l10n']) && is_array($_POST['upx_l10n']) ? wp_unslash($_POST['upx_l10n']) : array();
	$l10n = array();
	foreach (UPX_REF_LANGS as $lang) {
		$f    = isset($raw[$lang]) && is_array($raw[$lang]) ? $raw[$lang] : array();
		$get  = function ($k) use ($f) {
			return isset($f[$k]) && is_string($f[$k]) ? $f[$k] : '';
		};
		$body = $get('body');
		$l10n[$lang] = array(
			'title'     => $cap(sanitize_text_field($get('title')), 300),
			'excerpt'   => $cap(sanitize_textarea_field($get('excerpt')), 1000),
			'seo_title' => $cap(sanitize_text_field($get('seo_title')), 200),
			'seo_desc'  => $cap(sanitize_textarea_field($get('seo_desc')), 400),
			// Same rule as post content: only users allowed unfiltered HTML keep everything (it is sanitized again on output).
			'body'      => current_user_can('unfiltered_html') ? $body : wp_kses_post($body),
		);
	}
	update_post_meta($post_id, UPX_META_L10N, wp_slash($l10n));
	delete_post_meta($post_id, UPX_META_HTML);
	upx_refs_mark_dirty();
}
add_action('save_post_' . UPX_REF_TYPE, 'upx_ref_save', 10, 2);

/**
 * Phase 6 — Publish Gate:
 * Publishing needs the English title, English text, and a main photo.
 * For Instagram-sourced references (_source = instagram), publishing is ALSO blocked server-side until:
 * 1. Type is selected ('project' or 'news'),
 * 2. Country is selected (non-empty),
 * 3. At least 1 Court type is selected,
 * 4. Content is NOT stale (hash of selected Type + Country + Court types matches _generated_for).
 */
function upx_ref_require_fields($data, $postarr) {
	if ($data['post_type'] !== UPX_REF_TYPE || !in_array($data['post_status'], array('publish', 'future'), true)) {
		return $data;
	}
	$post_id = !empty($postarr['ID']) ? (int) $postarr['ID'] : 0;
	$is_ig   = $post_id > 0 && get_post_meta($post_id, '_source', true) === 'instagram';

	$missing = array();
	if (trim(wp_strip_all_tags(wp_unslash($data['post_title']))) === '') {
		$missing[] = 'an English title';
	}
	if (trim(wp_strip_all_tags(wp_unslash($data['post_content']))) === '') {
		$missing[] = 'English text';
	}
	$thumb = isset($postarr['_thumbnail_id']) ? (int) $postarr['_thumbnail_id'] : ($post_id > 0 ? (int) get_post_thumbnail_id($post_id) : 0);
	$kept  = $post_id > 0 && upx_ref_meta($post_id)['image_url'] !== ''; // imported photo

	// Auto-promote first gallery photo if main photo is missing.
	if ($thumb <= 0 && !$kept && !empty($postarr['upx_ref']['gallery'])) {
		foreach (explode(',', (string) $postarr['upx_ref']['gallery']) as $item) {
			$val = absint(trim($item));
			if ($val > 0 && wp_attachment_is_image($val)) {
				$thumb = $val;
				if ($post_id > 0) {
					set_post_thumbnail($post_id, $val);
				}
				break;
			}
		}
	}

	if ($thumb <= 0 && !$kept) {
		$missing[] = 'a main photo';
	}

	// Phase 6 checks for Instagram-sourced drafts.
	if ($is_ig) {
		$in_ref = isset($_POST['upx_ref']) && is_array($_POST['upx_ref'])
			? wp_unslash($_POST['upx_ref'])
			: (isset($postarr['upx_ref']) && is_array($postarr['upx_ref']) ? $postarr['upx_ref'] : upx_ref_meta($post_id));

		$kind    = isset($in_ref['kind']) && in_array((string) $in_ref['kind'], array('project', 'news'), true) ? (string) $in_ref['kind'] : '';
		$country = isset($in_ref['country']) ? sanitize_title((string) $in_ref['country']) : '';
		$types   = isset($in_ref['types']) && is_array($in_ref['types']) ? array_values(array_filter(array_map('strval', $in_ref['types']))) : array();

		if ($kind === '') {
			$missing[] = 'Type selection (Project or Company news)';
		}
		if ($country === '') {
			$missing[] = 'Country selection';
		}
		if (empty($types)) {
			$missing[] = 'at least one Court type selection';
		}

		$gen_hash = (string) get_post_meta($post_id, '_generated_for', true);
		$cur_hash = upx_ig_selection_hash($kind, $country, $types);
		if ($kind !== '' && $country !== '' && !empty($types) && $gen_hash !== '' && $cur_hash !== $gen_hash) {
			$missing[] = 'text consistency verification (click "Regenerate text" or "I have checked the text" in Reference details)';
		}
	}

	if ($missing) {
		$data['post_status'] = 'draft';
		set_transient('upx_publish_notice_' . get_current_user_id(), $missing, 120);
	}
	return $data;
}
add_filter('wp_insert_post_data', 'upx_ref_require_fields', 10, 2);

function upx_ref_notices() {
	$screen = function_exists('get_current_screen') ? get_current_screen() : null;
	if (!$screen || $screen->post_type !== UPX_REF_TYPE) {
		return;
	}
	$user = get_current_user_id();
	if (!upx_data()) {
		echo '<div class="notice notice-error"><p>References can’t be shown: this plugin build has no reference templates or comes from another version. Rebuild and upload the plugin (<code>npm run build:wp</code>).</p></div>';
	}
	$missing = get_transient('upx_publish_notice_' . $user);
	if ($missing) {
		delete_transient('upx_publish_notice_' . $user);
		printf('<div class="notice notice-warning is-dismissible"><p><strong>Saved as a draft, not published:</strong> please complete %s, then publish again.</p></div>', esc_html(implode(', ', $missing)));
	}
	$ig_gen = get_transient('upx_ig_generated_notice_' . $user);
	if (is_array($ig_gen)) {
		delete_transient('upx_ig_generated_notice_' . $user);
		echo '<div class="notice notice-success is-dismissible"><p><strong>Instagram Reference Draft Generated!</strong> Review the English text below, apply or choose <strong>Type</strong>, <strong>Country</strong>, and <strong>Court type</strong> in the <em>Reference details</em> box, and then click <strong>Publish</strong>.</p></div>';
	}
	$slug = get_transient('upx_slug_notice_' . $user);
	if (is_array($slug)) {
		delete_transient('upx_slug_notice_' . $user);
		printf('<div class="notice notice-info is-dismissible"><p>The address <code>/references/%s/</code> already belongs to a page of the site, so this reference uses <code>/references/%s/</code>.</p></div>', esc_html($slug[0]), esc_html($slug[1]));
	}
}
add_action('admin_notices', 'upx_ref_notices');

/**
 * Phase 1: Add "Add from Instagram" button next to "Add New Reference" on the References list screen.
 */
function upx_ref_list_add_ig_button() {
	$screen = function_exists('get_current_screen') ? get_current_screen() : null;
	if (!$screen || $screen->id !== 'edit-' . UPX_REF_TYPE) {
		return;
	}
	$ig_url = admin_url('post-new.php?post_type=' . UPX_REF_TYPE . '&upx_mode=ig_link');
	?>
	<script>
	jQuery(function($) {
		var $btn = $('.page-title-action').first();
		if ($btn.length) {
			$('<a>')
				.attr('href', <?php echo wp_json_encode($ig_url); ?>)
				.addClass('page-title-action upx-add-from-ig-btn')
				.html('<span class="dashicons dashicons-instagram" style="vertical-align:text-bottom;font-size:16px;width:16px;height:16px;margin-right:3px"></span>Add from Instagram')
				.insertAfter($btn);
		}
	});
	</script>
	<?php
}
add_action('admin_head-edit.php', 'upx_ref_list_add_ig_button');

function upx_ref_messages($messages) {
	global $post;
	$link = $post ? ' <a href="' . esc_url(get_permalink($post)) . '" target="_blank" rel="noopener">View reference</a>' : '';
	$messages[UPX_REF_TYPE] = array(
		0  => '',
		1  => 'Reference updated.' . $link,
		4  => 'Reference updated.',
		6  => 'Reference published.' . $link,
		7  => 'Reference saved.',
		8  => 'Reference submitted.',
		9  => 'Reference scheduled.',
		10 => 'Reference draft updated.',
	);
	return $messages;
}
add_filter('post_updated_messages', 'upx_ref_messages');

/* ---------- Preview and list ---------- */

function upx_ref_preview_link($link, $post) {
	return $post->post_type === UPX_REF_TYPE ? add_query_arg('upx_ref_preview', $post->ID, home_url('/')) : $link;
}
add_filter('preview_post_link', 'upx_ref_preview_link', 10, 2);

function upx_ref_columns($columns) {
	$out = array();
	foreach ($columns as $key => $label) {
		$out[$key] = $label;
		if ($key === 'title') {
			$out['upx_kind']    = 'Type';
			$out['upx_country'] = 'Country';
			$out['upx_langs']   = 'Languages';
		}
	}
	return $out;
}
add_filter('manage_' . UPX_REF_TYPE . '_posts_columns', 'upx_ref_columns');

function upx_ref_column($column, $post_id) {
	$data = upx_data();
	$post = get_post($post_id);
	if ($column === 'upx_kind') {
		$k = upx_ref_meta($post_id)['kind'];
		echo esc_html($k === 'news' ? 'News' : ($k === 'project' ? 'Project' : '—'));
	}
	if ($column === 'upx_country') {
		$slug = upx_ref_meta($post_id)['country'];
		echo esc_html($slug !== '' && $data && isset($data['l10n']['en']['countries'][$slug]) ? $data['l10n']['en']['countries'][$slug] : '—');
	}
	if ($column === 'upx_langs' && $post) {
		$langs = array();
		foreach (array_merge(array('en'), UPX_REF_LANGS) as $lang) {
			if (upx_ref_has_text(upx_ref_fields($post, $lang))) {
				$langs[] = strtoupper($lang);
			}
		}
		echo esc_html($langs ? implode(' ', $langs) : '—');
		$slug = upx_ref_clean_slug($post->post_name);
		if ($slug !== '' && $post->post_status === 'publish' && is_file(UPX_DIST . '/references/' . $slug . '/index.html')) {
			echo '<br><span style="color:#b32d2e">A built page now uses this address; change the address to show this reference.</span>';
		}
	}
}
add_action('manage_' . UPX_REF_TYPE . '_posts_custom_column', 'upx_ref_column', 10, 2);

function upx_ref_row_actions($actions, $post) {
	if ($post->post_type === UPX_REF_TYPE && $post->post_status === 'publish') {
		$actions['view'] = '<a href="' . esc_url(get_permalink($post)) . '" target="_blank" rel="noopener">View</a>';
	}
	return $actions;
}
add_filter('post_row_actions', 'upx_ref_row_actions', 10, 2);

function upx_ref_assets($hook) {
	$screen = function_exists('get_current_screen') ? get_current_screen() : null;
	if (!$screen) {
		return;
	}
	$base = plugin_dir_url(UPX_PLUGIN_FILE) . 'assets/';
	if ($screen->post_type === UPX_REF_TYPE && in_array($hook, array('post.php', 'post-new.php'), true)) {
		global $post;
		$post_id  = $post && isset($post->ID) ? (int) $post->ID : 0;
		$data     = upx_data();
		$c_labels = ($data && isset($data['l10n']['en']['countries'])) ? $data['l10n']['en']['countries'] : array();
		$p_labels = array();
		if ($data && isset($data['l10n']['en']['products'])) {
			foreach ($data['l10n']['en']['products'] as $u => $info) {
				$p_labels[$u] = isset($info['label']) ? $info['label'] : $u;
			}
		}
		$cons = $post_id > 0 ? upx_ig_consistency_state($post_id) : array(
			'is_ig'           => false,
			'is_stale'        => false,
			'generated_hash'  => '',
			'generated_label' => '',
		);
		$gen_meta = $post_id > 0 ? get_post_meta($post_id, '_generated_for_meta', true) : null;

		wp_enqueue_media();
		wp_enqueue_style('upx-refs-admin', $base . 'refs-admin.css', array('dashicons'), UPX_VERSION);
		wp_enqueue_script('upx-refs-admin', $base . 'refs-admin.js', array('jquery', 'jquery-ui-sortable'), UPX_VERSION, true);
		wp_localize_script('upx-refs-admin', 'upxAiVars', array(
			'ajaxUrl'        => admin_url('admin-ajax.php'),
			'nonce'          => wp_create_nonce('upx_ai_action'),
			'langs'          => UPX_REF_LANGS,
			'postId'         => $post_id,
			'isIg'           => $cons['is_ig'],
			'generatedFor'   => $cons['generated_hash'],
			'generatedLabel' => $cons['generated_label'],
			'generatedMeta'  => is_array($gen_meta) ? $gen_meta : null,
			'countryLabels'  => $c_labels,
			'productLabels'  => $p_labels,
		));
	} elseif ($screen->id === 'settings_page_unixpadel-static') {
		wp_enqueue_script('upx-refs-admin', $base . 'refs-admin.js', array('jquery'), UPX_VERSION, true);
		wp_localize_script('upx-refs-admin', 'upxAiVars', array(
			'ajaxUrl' => admin_url('admin-ajax.php'),
			'nonce'   => wp_create_nonce('upx_ai_action'),
			'langs'   => UPX_REF_LANGS,
		));
	}
}
add_action('admin_enqueue_scripts', 'upx_ref_assets');
