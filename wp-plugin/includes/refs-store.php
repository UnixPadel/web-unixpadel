<?php
/**
 * References written in WP admin (post type upx_reference): storage, the index the front end reads, and serving.
 * English is required; tr/de/fr/ar/ru are optional per reference (a language without a title and text has no page,
 * and that language's hub and home show the English card). The 42 references of the static build stay as built;
 * these go in front of them. Rendering: refs-render.php. Editor screens: refs-admin.php.
 */

if (!defined('ABSPATH')) {
	exit;
}

define('UPX_REF_TYPE', 'upx_reference');
define('UPX_OPT_REFS', 'upx_refs_index');  // published references as the front end needs them (autoload off)
define('UPX_META_REF', '_upx_ref');        // country, court types, SEO fields, gallery
define('UPX_META_L10N', '_upx_l10n');      // translations: lang => title, excerpt, seo_title, seo_desc, body
define('UPX_META_HTML', '_upx_html');      // rendered bodies: key + lang => html
define('UPX_OPT_IMPORTED', 'upx_refs_imported'); // built references moved into WP admin: English URL => post ID
define('UPX_BODY_VER', 1);                 // bump when upx_ref_render_body() changes its output
const UPX_REF_LANGS = array('tr', 'de', 'fr', 'ar', 'ru');

/* ---------- Post type ---------- */

function upx_register_reference_type() {
	register_post_type(UPX_REF_TYPE, array(
		'labels'              => array(
			'name'               => 'References',
			'singular_name'      => 'Reference',
			'add_new'            => 'Add New',
			'add_new_item'       => 'Add New Reference',
			'edit_item'          => 'Edit Reference',
			'new_item'           => 'New Reference',
			'view_item'          => 'View Reference',
			'search_items'       => 'Search References',
			'not_found'          => 'No references yet.',
			'not_found_in_trash' => 'No references in Trash.',
			'all_items'          => 'All References',
			'featured_image'     => 'Main photo',
			'set_featured_image' => 'Set main photo',
			'remove_featured_image' => 'Remove main photo',
			'use_featured_image' => 'Use as main photo',
		),
		'description'         => 'Projects shown on /references/ of the new site, in front of the built ones.',
		'public'              => false,
		'publicly_queryable'  => false,
		'show_ui'             => true,
		'show_in_menu'        => true,
		'show_in_nav_menus'   => false,
		'show_in_admin_bar'   => true,
		'show_in_rest'        => false, // classic editor: plain HTML for the site's prose styles
		'exclude_from_search' => true,
		'has_archive'         => false,
		'rewrite'             => false,
		'query_var'           => false,
		'menu_icon'           => 'dashicons-location-alt',
		'menu_position'       => 21,
		'supports'            => array('title', 'editor', 'thumbnail', 'excerpt', 'revisions'),
		'capability_type'     => 'page', // editors and administrators
		'map_meta_cap'        => true,
	));
}
add_action('init', 'upx_register_reference_type', 0);

function upx_reference_thumbnails() {
	add_theme_support('post-thumbnails', array(UPX_REF_TYPE));
}
add_action('after_setup_theme', 'upx_reference_thumbnails', 99);

/* ---------- Fields ---------- */

function upx_ref_meta($id) {
	$m = get_post_meta($id, UPX_META_REF, true);
	$m = is_array($m) ? $m : array();
	return array(
		'country'   => isset($m['country']) ? (string) $m['country'] : '',
		'types'     => isset($m['types']) && is_array($m['types']) ? array_values($m['types']) : array(),
		'seo_title' => isset($m['seo_title']) ? (string) $m['seo_title'] : '',
		'seo_desc'  => isset($m['seo_desc']) ? (string) $m['seo_desc'] : '',
		// attachment IDs; imported references may also hold /wp-content/… paths of photos not in the media library
		'gallery'   => isset($m['gallery']) && is_array($m['gallery']) ? array_values(array_map(function ($g) {
			return is_numeric($g) ? (int) $g : (string) $g;
		}, $m['gallery'])) : array(),
		'kind'      => isset($m['kind']) && $m['kind'] === 'news' ? 'news' : 'project',
		// imported references keep the order of the built list (unix time; new ones sort by their date)
		'sort'      => isset($m['sort']) ? (int) $m['sort'] : 0,
		// imported main photo that is not in the media library (a /wp-content/… path), used until a Main photo is set
		'image_url' => isset($m['image_url']) ? (string) $m['image_url'] : '',
		'image_alt' => isset($m['image_alt']) ? (string) $m['image_alt'] : '',
	);
}

function upx_ref_l10n($id) {
	$l = get_post_meta($id, UPX_META_L10N, true);
	return is_array($l) ? $l : array();
}

/**
 * Text fields of one language, raw. English: the post itself (or the editor's autosave for previews).
 *
 * @return array title, excerpt, seo_title, seo_desc, body
 */
function upx_ref_fields($post, $lang, $autosave = null) {
	if ($lang === 'en') {
		$src  = $autosave ? $autosave : $post;
		$meta = upx_ref_meta($post->ID);
		return array(
			'title'     => trim(wp_strip_all_tags($src->post_title)),
			'excerpt'   => trim(wp_strip_all_tags($src->post_excerpt)),
			'seo_title' => trim($meta['seo_title']),
			'seo_desc'  => trim($meta['seo_desc']),
			'body'      => (string) $src->post_content,
		);
	}
	$all = upx_ref_l10n($post->ID);
	$l   = isset($all[$lang]) && is_array($all[$lang]) ? $all[$lang] : array();
	$get = function ($k) use ($l) {
		return isset($l[$k]) ? (string) $l[$k] : '';
	};
	return array(
		'title'     => trim(wp_strip_all_tags($get('title'))),
		'excerpt'   => trim(wp_strip_all_tags($get('excerpt'))),
		'seo_title' => trim(wp_strip_all_tags($get('seo_title'))),
		'seo_desc'  => trim(wp_strip_all_tags($get('seo_desc'))),
		'body'      => $get('body'),
	);
}

/** A language has a page when it has a title and some text. */
function upx_ref_has_text($f) {
	return $f['title'] !== '' && trim(wp_strip_all_tags($f['body'])) !== '';
}

/** Image entry for the index: large src + srcset, alt, full size for og:image and the lightbox. */
function upx_ref_image($id) {
	$id = (int) $id;
	if (!$id || !wp_attachment_is_image($id)) {
		return null;
	}
	$src = wp_get_attachment_image_src($id, 'large');
	if (!$src) {
		return null;
	}
	$full = wp_get_attachment_image_url($id, 'full');
	$alt  = trim((string) get_post_meta($id, '_wp_attachment_image_alt', true));
	return array(
		'id'     => $id,
		'src'    => $src[0],
		'srcset' => (string) wp_get_attachment_image_srcset($id, 'large'),
		'alt'    => $alt,
		'full'   => $full ? $full : $src[0],
		// CAD renders and drawings sit on a white plate in the gallery (Gallery.astro isRender)
		'render' => (bool) (preg_match('/\.png$/i', $src[0]) || preg_match('/cad|render|drawing|isometric|layout|diagram|3d/i', $alt)),
	);
}

/** Image entry for a photo known only by its path (imported references whose photo is not in the media library). */
function upx_ref_image_url($path, $alt) {
	$path = (string) $path;
	if ($path === '') {
		return null;
	}
	$data = upx_data();
	$url  = strpos($path, '/') === 0 ? rtrim($data ? $data['media'] : home_url(), '/') . $path : $path;
	return array(
		'id'     => 0,
		'src'    => $url,
		'srcset' => '',
		'alt'    => (string) $alt,
		'full'   => $url,
		'render' => (bool) (preg_match('/\.png$/i', $url) || preg_match('/cad|render|drawing|isometric|layout|diagram|3d/i', (string) $alt)),
	);
}

function upx_ref_clean_slug($slug) {
	$slug = strtolower(remove_accents(rawurldecode((string) $slug)));
	return trim((string) preg_replace('/[^a-z0-9]+/', '-', $slug), '-');
}

/**
 * Index entry of a reference, or null when it has no English title and text.
 *
 * @param WP_Post      $post
 * @param WP_Post|null $autosave English text to use instead of the post's (previews)
 */
function upx_ref_entry($post, $autosave = null) {
	$meta  = upx_ref_meta($post->ID);
	$langs = array();
	$en    = null;
	foreach (array_merge(array('en'), UPX_REF_LANGS) as $l) {
		$f = upx_ref_fields($post, $l, $autosave);
		if (!upx_ref_has_text($f)) {
			if ($l === 'en') {
				return null;
			}
			continue;
		}
		if ($l === 'en') {
			$en = $f;
		}
		$base = $f['seo_desc'] !== '' ? $f['seo_desc'] : ($f['excerpt'] !== '' ? $f['excerpt'] : upx_lead_text($f['body']));
		$base = trim((string) preg_replace('/\s+/u', ' ', $base));
		$langs[$l] = array(
			'title'     => $f['title'],
			'excerpt'   => $f['excerpt'],
			'seo_title' => $f['seo_title'],
			'seo_desc'  => $f['seo_desc'],
			'desc'      => upx_clip($base),
			'card'      => $base,
		);
	}

	// Main photo, else the first gallery photo. Gallery: without the main photo and photos already in the text.
	$image   = upx_ref_image(get_post_thumbnail_id($post));
	$image   = $image ? $image : upx_ref_image_url($meta['image_url'], $meta['image_alt']);
	$gallery = array();
	foreach ($meta['gallery'] as $item) {
		$g = is_int($item) ? upx_ref_image($item) : upx_ref_image_url($item, '');
		if (!$g || ($image && $g['full'] === $image['full'])) {
			continue;
		}
		$file = preg_replace('/(-\d+x\d+)?\.[a-z0-9]+$/i', '', wp_basename($g['full']));
		if (($g['id'] && strpos($en['body'], 'wp-image-' . $g['id']) !== false) || ($file !== '' && strpos($en['body'], '/' . $file) !== false)) {
			continue;
		}
		$gallery[] = $g;
	}
	if (!$image && $gallery) {
		$image = array_shift($gallery);
	}

	$slug = upx_ref_clean_slug($post->post_name !== '' ? $post->post_name : $en['title']);
	$date = (int) get_post_time('U', true, $post);
	return array(
		'id'       => (int) $post->ID,
		'slug'     => $slug !== '' ? $slug : 'reference-' . $post->ID,
		'kind'     => $meta['kind'],
		'sort'     => $meta['sort'] ? $meta['sort'] : $date,
		'date'     => $date,
		'modified' => (int) get_post_modified_time('U', true, $post),
		'country'  => $meta['country'],
		'types'    => array_slice($meta['types'], 0, 2),
		'image'    => $image,
		'gallery'  => $gallery,
		'langs'    => $langs,
	);
}

/* ---------- Index ---------- */

/** Rebuilds the index of published references (newest first). */
function upx_refs_rebuild() {
	$posts = get_posts(array(
		'post_type'        => UPX_REF_TYPE,
		'post_status'      => 'publish',
		'numberposts'      => -1,
		'orderby'          => 'date',
		'order'            => 'DESC',
		'suppress_filters' => true,
	));
	$refs     = array();
	$old      = array();
	$imported = upx_refs_imported();
	foreach ($posts as $post) {
		$ref = upx_ref_entry($post);
		// A page the build has at the same address wins (the static site is served first), unless it was imported.
		$path = '/references/' . ($ref ? $ref['slug'] : '') . '/';
		if (!$ref || (is_file(UPX_DIST . $path . 'index.html') && !isset($imported[$path]))) {
			continue;
		}
		$refs[] = $ref;
		foreach ((array) get_post_meta($post->ID, '_wp_old_slug') as $was) {
			$was = upx_ref_clean_slug($was);
			if ($was !== '' && $was !== $ref['slug']) {
				$old[$was] = $ref['slug'];
			}
		}
	}
	foreach ($refs as $ref) {
		unset($old[$ref['slug']]); // a current address never redirects
	}
	// Newest first; imported references by their place in the built list.
	usort($refs, function ($a, $b) {
		return $b['sort'] - $a['sort'] ?: $b['id'] - $a['id'];
	});
	$index = array('version' => time(), 'build' => UPX_VERSION, 'refs' => $refs, 'old' => $old, 'imported' => $imported);
	update_option(UPX_OPT_REFS, $index, false);
	$GLOBALS['upx_refs_index'] = $index;
	return $index;
}

/** The index (read once per request), rebuilt when missing or written by another plugin build. */
function upx_refs_index() {
	if (!isset($GLOBALS['upx_refs_index'])) {
		$index = get_option(UPX_OPT_REFS);
		if (!is_array($index) || !isset($index['build'], $index['refs']) || $index['build'] !== UPX_VERSION) {
			$index = upx_refs_rebuild();
		}
		$GLOBALS['upx_refs_index'] = $index;
	}
	return $GLOBALS['upx_refs_index'];
}

/** Changes are collected during the request and the index is rebuilt once at the end. */
function upx_refs_mark_dirty() {
	$GLOBALS['upx_refs_dirty'] = true;
}

function upx_refs_flush() {
	if (empty($GLOBALS['upx_refs_dirty'])) {
		return;
	}
	$GLOBALS['upx_refs_dirty'] = false;
	upx_refs_rebuild();
	upx_purge_page_caches();
}
add_action('shutdown', 'upx_refs_flush', 0);

function upx_refs_on_status($new, $old, $post) {
	if ($post && $post->post_type === UPX_REF_TYPE && ($new === 'publish' || $old === 'publish')) {
		upx_refs_mark_dirty();
	}
}
add_action('transition_post_status', 'upx_refs_on_status', 10, 3);

function upx_refs_on_save($post_id, $post) {
	if ($post->post_status === 'publish') {
		upx_refs_mark_dirty();
	}
}
add_action('save_post_' . UPX_REF_TYPE, 'upx_refs_on_save', 20, 2);

/** The row is gone by now, so the post type comes from the post object WordPress passes along (WP 5.5+). */
function upx_refs_on_delete($post_id, $post = null) {
	if ($post instanceof WP_Post && $post->post_type === UPX_REF_TYPE) {
		upx_refs_mark_dirty();
	}
}
add_action('deleted_post', 'upx_refs_on_delete', 10, 2);

/** Photos are part of the index (sizes, alt text): any change to one can matter. */
function upx_refs_on_attachment() {
	$index = get_option(UPX_OPT_REFS);
	if (is_array($index) && !empty($index['refs'])) {
		upx_refs_mark_dirty();
	}
}
add_action('edit_attachment', 'upx_refs_on_attachment');
add_action('delete_attachment', 'upx_refs_on_attachment');

/** Page caches keep serving the old hub and home page otherwise. Only plugins that are installed are called. */
function upx_purge_page_caches() {
	do_action('litespeed_purge_all');       // LiteSpeed Cache
	if (function_exists('rocket_clean_domain')) {
		rocket_clean_domain();              // WP Rocket
	}
	if (function_exists('w3tc_flush_all')) {
		w3tc_flush_all();                   // W3 Total Cache
	}
	if (function_exists('wp_cache_clear_cache')) {
		wp_cache_clear_cache();             // WP Super Cache
	}
	if (function_exists('sg_cachepress_purge_cache')) {
		sg_cachepress_purge_cache();        // SiteGround Optimizer
	}
	do_action('wpfc_clear_all_cache');      // WP Fastest Cache
	do_action('breeze_clear_all_cache');    // Breeze (Cloudways)
	do_action('wphb_clear_page_cache');     // Hummingbird
	do_action('rt_nginx_helper_purge_all'); // Nginx Helper
}

/* ---------- Addresses ---------- */

/** English URLs of built references imported into WP admin → post ID (0 while an import is running). Permanent. */
function upx_refs_imported() {
	$map = get_option(UPX_OPT_IMPORTED);
	return is_array($map) ? $map : array();
}

/** Addresses the build already uses (its pages under /references/ and its redirects), unless imported into WP admin. */
function upx_ref_slug_reserved($slug) {
	$path      = '/references/' . $slug . '/';
	$redirects = upx_redirects();
	$imported  = upx_refs_imported();
	if (isset($imported[$path])) {
		return false;
	}
	return is_file(UPX_DIST . $path . 'index.html') || isset($redirects[$path]);
}

function upx_ref_slug_taken($slug, $post_id) {
	global $wpdb;
	if (upx_ref_slug_reserved($slug)) {
		return true;
	}
	return (bool) $wpdb->get_var($wpdb->prepare(
		"SELECT ID FROM $wpdb->posts WHERE post_type = %s AND post_name = %s AND ID != %d LIMIT 1",
		UPX_REF_TYPE,
		$slug,
		$post_id
	));
}

/** Plain a–z 0–9 slugs, never one the build or another reference already uses (oman → oman-2). */
function upx_ref_unique_slug($slug, $post_id, $status, $type) {
	if ($type !== UPX_REF_TYPE) {
		return $slug;
	}
	$base = upx_ref_clean_slug($slug);
	$base = $base !== '' ? $base : 'reference-' . (int) $post_id;
	$candidate = $base;
	for ($n = 2; upx_ref_slug_taken($candidate, $post_id); $n++) {
		$candidate = $base . '-' . $n;
	}
	if ($candidate !== $base && upx_ref_slug_reserved($base)) {
		set_transient('upx_slug_notice_' . get_current_user_id(), array($base, $candidate), 120);
	}
	return $candidate;
}
add_filter('wp_unique_post_slug', 'upx_ref_unique_slug', 10, 4);

/** Front-end address of a reference ("View" links in the admin). */
function upx_ref_link($post_link, $post) {
	if ($post->post_type !== UPX_REF_TYPE) {
		return $post_link;
	}
	$slug = upx_ref_clean_slug($post->post_name);
	return $slug !== '' ? home_url('/references/' . $slug . '/') : $post_link;
}
add_filter('post_type_link', 'upx_ref_link', 10, 2);

/* ---------- Body ---------- */

/**
 * Editor HTML → page HTML: captions, embeds (YouTube, Vimeo, Google Maps), paragraphs, sanitizing, WordPress image
 * sizes, one H1 per page and internal links to the page language.
 */
function upx_ref_render_body($raw, $lang, $post_id) {
	global $wp_embed, $post;
	$data = upx_data();

	$html = (string) $raw;
	add_filter('img_caption_shortcode_width', '__return_zero'); // no fixed-width inline style
	$html = preg_replace_callback('/' . get_shortcode_regex(array('caption', 'wp_caption')) . '/', 'do_shortcode_tag', $html);
	remove_filter('img_caption_shortcode_width', '__return_zero');
	$html = strip_shortcodes($html); // the new site has no theme shortcodes

	if ($wp_embed instanceof WP_Embed) {
		$saved = $post;
		$post  = get_post($post_id); // oEmbed results are cached in this post's meta
		$html  = $wp_embed->autoembed($html);
		$post  = $saved;
	}
	$html = shortcode_unautop(wpautop($html));

	$allowed           = wp_kses_allowed_html('post');
	$allowed['iframe'] = array('src' => true, 'title' => true, 'width' => true, 'height' => true, 'allow' => true, 'allowfullscreen' => true, 'loading' => true, 'referrerpolicy' => true, 'frameborder' => true);
	// Imported bodies carry the build's videos and responsive images.
	$allowed['video']  = array('src' => true, 'poster' => true, 'controls' => true, 'autoplay' => true, 'muted' => true, 'loop' => true, 'playsinline' => true, 'preload' => true, 'width' => true, 'height' => true, 'class' => true);
	$allowed['source'] = array('src' => true, 'type' => true);
	$allowed['img']    = array_merge(isset($allowed['img']) ? $allowed['img'] : array(), array('srcset' => true, 'sizes' => true, 'loading' => true, 'decoding' => true));
	$html              = wp_kses($html, $allowed);
	$html              = preg_replace_callback('#<iframe\b[^>]*>.*?</iframe>#is', function ($m) {
		if (!preg_match('#\ssrc="(https://[^"]+)"#i', $m[0], $src)) {
			return '';
		}
		$host = strtolower((string) wp_parse_url(html_entity_decode($src[1]), PHP_URL_HOST));
		return preg_match('/(^|\.)(youtube\.com|youtube-nocookie\.com|vimeo\.com|google\.com)$/', $host) ? $m[0] : '';
	}, $html);
	$html = preg_replace('#<p>\s*(<iframe\b.*?</iframe>)\s*</p>#is', '$1', $html);
	$html = preg_replace('#<iframe\b.*?</iframe>#is', '<div class="embed">$0</div>', $html);

	$sizes = function () {
		return '(min-width: 1024px) 65ch, 100vw';
	};
	add_filter('wp_calculate_image_sizes', $sizes);
	$html = wp_filter_content_tags($html, 'upx_reference');
	remove_filter('wp_calculate_image_sizes', $sizes);

	$html = preg_replace('#<(/?)h1\b#i', '<$1h2', $html);
	$html = preg_replace('#<p>(?:\s|&nbsp;|<br\s*/?>)*</p>#i', '', $html);

	if ($lang !== 'en' && $data) {
		$translated = $data['l10n'][$lang]['translated'];
		foreach (upx_refs_index()['refs'] as $ref) {
			if (isset($ref['langs'][$lang])) {
				$translated[] = '/references/' . $ref['slug'] . '/';
			}
		}
		$html = upx_localize_links($html, $lang, $translated, $data['site']);
	}
	return trim($html);
}

/** Body of a published reference, rendered once per change and kept in post meta. */
function upx_ref_body($post, $lang) {
	$index = upx_refs_index();
	$key   = UPX_VERSION . '|' . UPX_BODY_VER . '|' . $post->post_modified_gmt . '|' . $index['version'];
	$cache = get_post_meta($post->ID, UPX_META_HTML, true);
	if (is_array($cache) && isset($cache['key'], $cache[$lang]) && $cache['key'] === $key) {
		return $cache[$lang];
	}
	$cache = is_array($cache) && isset($cache['key']) && $cache['key'] === $key ? $cache : array('key' => $key);
	$f     = upx_ref_fields($post, $lang);
	$cache[$lang] = upx_ref_render_body($f['body'], $lang, $post->ID);
	update_post_meta($post->ID, UPX_META_HTML, $cache);
	return $cache[$lang];
}

/* ---------- Serving (called from upx_maybe_serve) ---------- */

/** Published references the front end shows, newest first. */
function upx_refs() {
	return upx_data() ? upx_refs_index()['refs'] : array();
}

/**
 * Dynamic answers for paths the build has no file for.
 *
 * @return array|null ['body', $content, $content_type, $mtime] | ['redirect', $target] | ['notfound'] | null (not ours)
 */
function upx_refs_route($path) {
	$sitemap = $path === '/sitemap-references.xml';
	if (!$sitemap && !preg_match('#^/(?:(tr|de|fr|ar|ru)/)?references/([a-z0-9]+(?:-[a-z0-9]+)*)(/?)$#', $path, $m)) {
		return null;
	}
	$data = upx_data();
	if (!$data) {
		return null;
	}
	if ($sitemap) {
		$refs = upx_refs();
		return $refs ? array('body', upx_render_sitemap($refs, $data), 'application/xml; charset=utf-8', upx_refs_index()['version']) : null;
	}
	$lang  = $m[1] !== '' ? $m[1] : 'en';
	$index = upx_refs_index();
	$ref   = null;
	foreach ($index['refs'] as $r) {
		if ($r['slug'] === $m[2]) {
			$ref = $r;
			break;
		}
	}
	if (!$ref) {
		if (isset($index['old'][$m[2]])) {
			return array('redirect', $data['l10n'][$lang]['hub'] . $index['old'][$m[2]] . '/');
		}
		return null;
	}
	if (!isset($ref['langs'][$lang])) {
		return array('notfound'); // no page in this language: that language's 404 page
	}
	if ($m[3] === '') {
		return array('redirect', $path . '/');
	}
	$post  = get_post($ref['id']);
	$shell = upx_shell_file($lang);
	if (!$post || !is_file($shell)) {
		return null;
	}
	$html = upx_render_ref_page($ref, $lang, upx_ref_body($post, $lang), $data, (string) file_get_contents($shell), false, $index['refs']);
	return array('body', $html, 'text/html; charset=utf-8', max((int) filemtime($shell), (int) $ref['modified']));
}

/**
 * Addresses of built references imported into WP admin, in every language: the WP admin copy answers (its own
 * address), the old address 301s to it (moved references: /cesme-…/ → /references/cesme-…/), and a reference that is
 * no longer published, or has no page in that language, is a 404. The built copy is never served again.
 *
 * @return array|null upx_refs_route() result, or null when the path is not an imported reference
 */
function upx_refs_intercept($path) {
	$imported = upx_refs_imported();
	if (!$imported) {
		return null;
	}
	$slashed = substr($path, -1) === '/' ? $path : $path . '/';
	$key     = upx_english_path($slashed);
	if (empty($imported[$key]) || !upx_data()) {
		return null; // not imported (or the import is still running): the build answers
	}
	$data = upx_data();
	$lang = preg_match('#^/(tr|de|fr|ar|ru)/#', $slashed, $m) ? $m[1] : 'en';
	$ref  = null;
	foreach (upx_refs_index()['refs'] as $r) {
		if ($r['id'] === (int) $imported[$key]) {
			$ref = $r;
			break;
		}
	}
	if (!$ref || !isset($ref['langs'][$lang])) {
		return array('notfound');
	}
	$target = $data['l10n'][$lang]['hub'] . $ref['slug'] . '/';
	return $target === $path ? upx_refs_route($path) : array('redirect', $target);
}

/**
 * Built files that list references (hub and home in every language, sitemap index, llms.txt) with the WordPress
 * references added. Null when the file is served as built.
 */
function upx_refs_filter_file($path, $file) {
	$data = upx_data();
	if (!$data) {
		return null;
	}
	if ($path === '/sitemap-index.xml') {
		$refs = upx_refs();
		return $refs ? upx_inject_sitemap_index((string) file_get_contents($file), $refs, $data) : null;
	}
	if ($path === '/sitemap-0.xml') {
		$imported = upx_refs_imported();
		return $imported ? upx_filter_sitemap((string) file_get_contents($file), $imported) : null;
	}
	if ($path === '/llms.txt') {
		$refs     = upx_refs();
		$imported = upx_refs_imported();
		return $refs || $imported ? upx_inject_llms((string) file_get_contents($file), $refs, $data, $imported) : null;
	}
	if (substr($file, -11) !== '/index.html') {
		return null;
	}
	// Pages that list references carry upx markers (hub, home, product pages): always through upx_inject_listing,
	// which also removes the templates and markers.
	$html = (string) file_get_contents($file);
	if (strpos($html, '<!--upx:') === false) {
		return null;
	}
	$slashed = substr($path, -1) === '/' ? $path : $path . '/';
	$lang    = preg_match('#^/(tr|de|fr|ar|ru)/#', $slashed, $m) ? $m[1] : 'en';
	return upx_inject_listing($html, $lang, upx_refs(), $data, upx_refs_imported(), upx_english_path($slashed));
}

/** Preview of a reference (draft or published) for editors: ?upx_ref_preview=<id>&upx_lang=<lang>. */
function upx_ref_preview_html($id, $lang) {
	$data = upx_data();
	$post = get_post($id);
	if (!$data || !$post || $post->post_type !== UPX_REF_TYPE || !current_user_can('edit_post', $post->ID)) {
		return null;
	}
	$lang     = in_array($lang, $data['langs'], true) ? $lang : 'en';
	$autosave = wp_get_post_autosave($post->ID, get_current_user_id());
	$autosave = $autosave && strtotime($autosave->post_modified_gmt) >= strtotime($post->post_modified_gmt) ? $autosave : null;
	$ref      = upx_ref_entry($post, $autosave);
	$shell    = upx_shell_file($lang);
	if (!$ref || !isset($ref['langs'][$lang]) || !is_file($shell)) {
		return '<!doctype html><meta charset="utf-8"><title>Preview</title><p style="font:16px sans-serif;margin:2rem">'
			. esc_html($lang === 'en' ? 'Add an English title and text, then preview again.' : 'This language has no title and text yet: its page does not exist.')
			. '</p>';
	}
	$f = upx_ref_fields($post, $lang, $autosave);
	return upx_render_ref_page($ref, $lang, upx_ref_render_body($f['body'], $lang, $post->ID), $data, (string) file_get_contents($shell), true, upx_refs());
}
