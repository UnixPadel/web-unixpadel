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
	add_meta_box('upx-ref-details', 'Reference details', 'upx_ref_details_box', UPX_REF_TYPE, 'normal', 'high');
	foreach (UPX_REF_LANGS as $lang) {
		add_meta_box('upx-ref-' . $lang, UPX_LANG_NAMES[$lang] . ' (' . strtoupper($lang) . ') — optional', 'upx_ref_lang_box', UPX_REF_TYPE, 'normal', 'default', array('lang' => $lang));
	}
	add_meta_box('upx-ref-pages', 'Pages and preview', 'upx_ref_pages_box', UPX_REF_TYPE, 'side', 'high');
}
add_action('add_meta_boxes_' . UPX_REF_TYPE, 'upx_ref_boxes');

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
	$slug = upx_ref_clean_slug($post->post_name);
	?>
	<div class="upx-ref">
		<p class="upx-ref__row">
			<label for="upx-slug"><strong>Address</strong></label><br>
			<code><?php echo esc_html(home_url('/references/')); ?></code><input type="text" id="upx-slug" name="post_name" value="<?php echo esc_attr($slug); ?>" class="regular-text code" pattern="[a-z0-9-]*" placeholder="made-from-the-title"><code>/</code>
			<span class="description">Letters a–z, digits and hyphens. Leave empty to make it from the title. Addresses of existing pages get a number added (oman → oman-2).</span>
		</p>
		<p class="upx-ref__row">
			<label for="upx-kind"><strong>Type</strong></label><br>
			<select id="upx-kind" name="upx_ref[kind]">
				<option value="project" <?php selected($meta['kind'], 'project'); ?>>Project (card on /references/, the home page and product pages)</option>
				<option value="news" <?php selected($meta['kind'], 'news'); ?>>Company news (list under the projects on /references/)</option>
			</select>
		</p>
		<?php if ($meta['image_url'] !== '' && !has_post_thumbnail($post)) : ?>
			<p class="upx-ref__row notice notice-info inline">Imported photo, not in the media library: <code><?php echo esc_html($meta['image_url']); ?></code>. It is shown until you set a Main photo.</p>
		<?php endif; ?>
		<p class="upx-ref__row">
			<label for="upx-country"><strong>Country</strong></label><br>
			<select id="upx-country" name="upx_ref[country]">
				<option value="">— None (filter “Other”) —</option>
				<?php foreach ($data['countries'] as $c) : ?>
					<option value="<?php echo esc_attr($c['slug']); ?>" <?php selected($meta['country'], $c['slug']); ?>><?php echo esc_html($data['l10n']['en']['countries'][$c['slug']]); ?></option>
				<?php endforeach; ?>
			</select>
			<span class="description">Shown on the card, in the facts box and as a filter on /references/ (in every language).</span>
		</p>
		<fieldset class="upx-ref__row upx-ref__types" data-max="<?php echo (int) $data['limits']['types']; ?>">
			<legend><strong>Court type</strong> <span class="description">(up to <?php echo (int) $data['limits']['types']; ?>; links to the product pages)</span></legend>
			<?php foreach ($data['products'] as $p) : ?>
				<label><input type="checkbox" name="upx_ref[types][]" value="<?php echo esc_attr($p['url']); ?>" <?php checked(in_array($p['url'], $meta['types'], true)); ?>> <?php echo esc_html($data['l10n']['en']['products'][$p['url']]['label']); ?></label>
			<?php endforeach; ?>
		</fieldset>
		<p class="upx-ref__row">
			<label for="upx-seo-title"><strong>SEO title</strong> <span class="description">(optional; else the title. “| Unixpadel” is added when it fits)</span></label><br>
			<input type="text" id="upx-seo-title" name="upx_ref[seo_title]" value="<?php echo esc_attr($meta['seo_title']); ?>" class="large-text" data-count="60">
		</p>
		<p class="upx-ref__row">
			<label for="upx-seo-desc"><strong>Meta description</strong> <span class="description">(optional; else the Excerpt box below, else the first sentence of the text)</span></label><br>
			<textarea id="upx-seo-desc" name="upx_ref[seo_desc]" rows="2" class="large-text" data-count="158"><?php echo esc_textarea($meta['seo_desc']); ?></textarea>
		</p>
		<div class="upx-ref__row">
			<strong>Gallery</strong> <span class="description">(photos shown under the text; the main photo and photos already in the text are left out). Drag to reorder.</span>
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
		<p class="description">Required to publish: English title, English text and a main photo (box “Main photo”, landscape, at least 1600 px wide). The <strong>Excerpt</strong> box is the summary under the title and on the card.</p>
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
			echo '<span class="upx-pages__none">' . ($lang === 'en' ? 'title and text missing' : 'no page') . '</span>';
		} else {
			if ($published && $slug !== '') {
				$url = home_url($data['l10n'][$lang]['hub'] . $slug . '/');
				echo '<a href="' . esc_url($url) . '" target="_blank" rel="noopener">View</a> · ';
			}
			echo '<a href="' . esc_url($preview) . '" target="_blank" rel="noopener">Preview</a>';
		}
		echo '</li>';
	}
	echo '</ul><p class="description">Preview shows the last saved version: <strong>Save Draft</strong> (or Update) first. Published changes reach visitors within about 5 minutes (browser cache).</p>';
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
	$data = upx_data();
	$in   = isset($_POST['upx_ref']) && is_array($_POST['upx_ref']) ? wp_unslash($_POST['upx_ref']) : array();
	$cap  = function ($s, $max) {
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
	$old  = upx_ref_meta($post_id);
	$meta = array(
		'country'   => in_array($country, $countries, true) ? $country : '',
		'types'     => array_slice($types, 0, $data ? (int) $data['limits']['types'] : 2),
		'seo_title' => $cap(sanitize_text_field(isset($in['seo_title']) ? $in['seo_title'] : ''), 200),
		'seo_desc'  => $cap(sanitize_textarea_field(isset($in['seo_desc']) ? $in['seo_desc'] : ''), 400),
		'gallery'   => array_slice($gallery, 0, 60),
		'kind'      => isset($in['kind']) && $in['kind'] === 'news' ? 'news' : 'project',
		// kept from the import: place in the list and the photo shown until a Main photo is set
		'sort'      => $old['sort'],
		'image_url' => has_post_thumbnail($post_id) ? '' : $old['image_url'],
		'image_alt' => $old['image_alt'],
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

/** Publishing needs the English title, English text and a main photo; otherwise the reference stays a draft. */
function upx_ref_require_fields($data, $postarr) {
	if ($data['post_type'] !== UPX_REF_TYPE || !in_array($data['post_status'], array('publish', 'future'), true)) {
		return $data;
	}
	$missing = array();
	if (trim(wp_strip_all_tags(wp_unslash($data['post_title']))) === '') {
		$missing[] = 'an English title';
	}
	if (trim(wp_strip_all_tags(wp_unslash($data['post_content']))) === '') {
		$missing[] = 'English text';
	}
	$thumb = isset($postarr['_thumbnail_id']) ? (int) $postarr['_thumbnail_id'] : (!empty($postarr['ID']) ? (int) get_post_thumbnail_id((int) $postarr['ID']) : 0);
	$kept  = !empty($postarr['ID']) && upx_ref_meta((int) $postarr['ID'])['image_url'] !== ''; // imported photo
	if ($thumb <= 0 && !$kept) {
		$missing[] = 'a main photo';
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
		printf('<div class="notice notice-warning is-dismissible"><p>Saved as a draft, not published: add %s, then publish again.</p></div>', esc_html(implode(', ', $missing)));
	}
	$slug = get_transient('upx_slug_notice_' . $user);
	if (is_array($slug)) {
		delete_transient('upx_slug_notice_' . $user);
		printf('<div class="notice notice-info is-dismissible"><p>The address <code>/references/%s/</code> already belongs to a page of the site, so this reference uses <code>/references/%s/</code>.</p></div>', esc_html($slug[0]), esc_html($slug[1]));
	}
}
add_action('admin_notices', 'upx_ref_notices');

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
		echo esc_html(upx_ref_meta($post_id)['kind'] === 'news' ? 'News' : 'Project');
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
	if (!$screen || $screen->post_type !== UPX_REF_TYPE || !in_array($hook, array('post.php', 'post-new.php'), true)) {
		return;
	}
	wp_enqueue_media();
	$base = plugin_dir_url(UPX_PLUGIN_FILE) . 'assets/';
	wp_enqueue_style('upx-refs-admin', $base . 'refs-admin.css', array(), UPX_VERSION);
	wp_enqueue_script('upx-refs-admin', $base . 'refs-admin.js', array('jquery', 'jquery-ui-sortable'), UPX_VERSION, true);
}
add_action('admin_enqueue_scripts', 'upx_ref_assets');
