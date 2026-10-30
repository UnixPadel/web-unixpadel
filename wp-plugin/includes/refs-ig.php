<?php
/**
 * Instagram-to-Reference Pipeline (Phases 1, 3, 4, 5, 6):
 * - Mode A (URL fetch via UPX_Instagram_Post_Extractor) & Mode B (Manual caption + images)
 * - Duplicate check by _ig_shortcode
 * - Multimodal Gemini structured JSON generation + PHP Consistency Validator (with 1 automatic retry)
 * - Media library sideloading & deduplication (unixpadel-<slug>-<n>.jpg)
 * - Draft creation with empty Type/Country/Court types + AI suggestions + _generated_for consistency hash
 * - Text regeneration ("Regenerate text") and manual verification ("I have checked the text")
 */

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Finds an existing reference post by its _ig_shortcode meta value.
 *
 * @param string $shortcode
 * @param int    $exclude_id
 * @return array|null { id, title, status, edit_url }
 */
function upx_ig_find_by_shortcode($shortcode, $exclude_id = 0) {
	$shortcode = trim((string) $shortcode);
	if ($shortcode === '') {
		return null;
	}
	$posts = get_posts(array(
		'post_type'      => UPX_REF_TYPE,
		'post_status'    => array('publish', 'draft', 'pending', 'future', 'private'),
		'posts_per_page' => 5,
		'meta_key'       => '_ig_shortcode',
		'meta_value'     => $shortcode,
		'exclude'        => $exclude_id > 0 ? array((int) $exclude_id) : array(),
	));
	if (empty($posts)) {
		return null;
	}
	$p = $posts[0];
	return array(
		'id'       => (int) $p->ID,
		'title'    => $p->post_title !== '' ? $p->post_title : '(Untitled draft #' . $p->ID . ')',
		'status'   => (string) $p->post_status,
		'edit_url' => (string) get_edit_post_link($p->ID, 'raw'),
	);
}

/**
 * Computes the canonical consistency hash for a (kind, country, court_types) combination.
 *
 * @param string   $kind
 * @param string   $country
 * @param string[] $types
 * @return string
 */
function upx_ig_selection_hash($kind, $country, $types) {
	$kind    = in_array((string) $kind, array('project', 'news'), true) ? (string) $kind : '';
	$country = sanitize_title((string) $country);
	$t_arr   = is_array($types) ? array_values(array_unique(array_filter(array_map('strval', $types)))) : array();
	sort($t_arr);
	return md5(wp_json_encode(array(
		'kind'    => $kind,
		'country' => $country,
		'types'   => $t_arr,
	)));
}

/**
 * Formats a human-readable label for a (kind, country, court_types) combination.
 *
 * @param string   $kind
 * @param string   $country
 * @param string[] $types
 * @return string
 */
function upx_ig_selection_label($kind, $country, $types) {
	$data  = upx_data();
	$parts = array();

	if ($kind === 'project') {
		$parts[] = 'Project';
	} elseif ($kind === 'news') {
		$parts[] = 'Company news';
	} else {
		$parts[] = 'No type';
	}

	if ($country !== '' && $data && isset($data['l10n']['en']['countries'][$country])) {
		$parts[] = $data['l10n']['en']['countries'][$country];
	} elseif ($country !== '') {
		$parts[] = ucfirst(str_replace('-', ' ', $country));
	} else {
		$parts[] = 'No country';
	}

	$t_labels = array();
	if (is_array($types)) {
		foreach ($types as $u) {
			if ($data && isset($data['l10n']['en']['products'][$u]['label'])) {
				$t_labels[] = $data['l10n']['en']['products'][$u]['label'];
			} elseif ($u !== '') {
				$t_labels[] = trim($u, '/');
			}
		}
	}
	$parts[] = !empty($t_labels) ? implode(' + ', $t_labels) : 'No court type';

	return implode(' · ', $parts);
}

/**
 * Checks whether an Instagram-sourced reference post has stale content relative to its current selections.
 *
 * @param int $post_id
 * @return array { is_ig: bool, is_stale: bool, generated_hash: string, current_hash: string, generated_label: string, current_label: string, missing_selections: string[] }
 */
function upx_ig_consistency_state($post_id) {
	$is_ig = get_post_meta($post_id, '_source', true) === 'instagram';
	$meta  = upx_ref_meta($post_id);

	$missing = array();
	if ($meta['kind'] !== 'project' && $meta['kind'] !== 'news') {
		$missing[] = 'Type';
	}
	if ($meta['country'] === '') {
		$missing[] = 'Country';
	}
	if (empty($meta['types'])) {
		$missing[] = 'Court type';
	}

	$gen_hash = (string) get_post_meta($post_id, '_generated_for', true);
	$gen_meta = get_post_meta($post_id, '_generated_for_meta', true);
	$gen_meta = is_array($gen_meta) ? $gen_meta : array('kind' => '', 'country' => '', 'types' => array());

	$cur_hash = upx_ig_selection_hash($meta['kind'], $meta['country'], $meta['types']);

	// Content is stale if all three fields are selected (or any field is selected) AND the hash differs from _generated_for.
	$any_selected = ($meta['kind'] !== '' || $meta['country'] !== '' || !empty($meta['types']));
	$is_stale     = $is_ig && $any_selected && $gen_hash !== '' && $cur_hash !== $gen_hash;

	return array(
		'is_ig'              => $is_ig,
		'is_stale'           => $is_stale,
		'generated_hash'     => $gen_hash,
		'current_hash'       => $cur_hash,
		'generated_label'    => upx_ig_selection_label(
			isset($gen_meta['kind']) ? $gen_meta['kind'] : '',
			isset($gen_meta['country']) ? $gen_meta['country'] : '',
			isset($gen_meta['types']) ? $gen_meta['types'] : array()
		),
		'current_label'      => upx_ig_selection_label($meta['kind'], $meta['country'], $meta['types']),
		'missing_selections' => $missing,
	);
}

/**
 * Prepares downscaled (<= 768px) base64 image payloads for Gemini multimodal analysis.
 * Limits to first 4 images to keep latency and payload size low.
 *
 * @param array $image_items Array of ['type' => 'attachment'|'url', 'id' => int, 'url' => string]
 * @return array Array of ['mime_type' => string, 'data' => base64_string]
 */
function upx_ig_prepare_multimodal_images($image_items) {
	$out   = array();
	$limit = min(4, count($image_items));

	for ($i = 0; $i < $limit; $i++) {
		$item      = $image_items[$i];
		$file_path = '';
		$temp_file = '';

		if (isset($item['type']) && $item['type'] === 'attachment' && !empty($item['id'])) {
			$att_id = absint($item['id']);
			// Prefer medium_large (768px) or medium if WP already generated it.
			$inter = image_get_intermediate_size($att_id, 'medium_large');
			if (!$inter) {
				$inter = image_get_intermediate_size($att_id, 'medium');
			}
			$uploads = wp_get_upload_dir();
			if ($inter && !empty($inter['path']) && !empty($uploads['basedir'])) {
				$candidate = trailingslashit($uploads['basedir']) . $inter['path'];
				if (is_file($candidate)) {
					$file_path = $candidate;
				}
			}
			if ($file_path === '') {
				$full_path = get_attached_file($att_id);
				if ($full_path && is_file($full_path)) {
					$file_path = $full_path;
				}
			}
		} elseif (!empty($item['url']) && strpos((string) $item['url'], 'http') === 0) {
			if (!function_exists('download_url')) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
			}
			$dl = download_url((string) $item['url'], 15);
			if (!is_wp_error($dl) && is_file($dl)) {
				$temp_file = $dl;
				$file_path = $dl;
			}
		}

		if ($file_path !== '' && is_file($file_path)) {
			$mime  = 'image/jpeg';
			$bytes = '';

			// Resize to <= 768px if image editor is available.
			$editor = wp_get_image_editor($file_path);
			if (!is_wp_error($editor)) {
				$size = $editor->get_size();
				if (is_array($size) && (!empty($size['width']) && $size['width'] > 768 || !empty($size['height']) && $size['height'] > 768)) {
					$editor->resize(768, 768, false);
				}
				$editor->set_quality(80);
				$resized_tmp = wp_tempnam('upx-gemini-img');
				$saved       = $editor->save($resized_tmp, 'image/jpeg');
				if (!is_wp_error($saved) && !empty($saved['path']) && is_file($saved['path'])) {
					$bytes = (string) file_get_contents($saved['path']);
					$mime  = !empty($saved['mime-type']) ? $saved['mime-type'] : 'image/jpeg';
					@unlink($saved['path']);
				}
				if (is_file($resized_tmp)) {
					@unlink($resized_tmp);
				}
			}

			if ($bytes === '' && filesize($file_path) <= 4 * 1024 * 1024) {
				$bytes = (string) file_get_contents($file_path);
				$info  = @getimagesize($file_path);
				if (is_array($info) && !empty($info['mime'])) {
					$mime = $info['mime'];
				}
			}

			if ($bytes !== '') {
				$out[] = array(
					'mime_type' => $mime,
					'data'      => base64_encode($bytes),
				);
			}
		}

		if ($temp_file !== '' && is_file($temp_file)) {
			@unlink($temp_file);
		}
	}

	return $out;
}

/**
 * Generates structured reference JSON from an Instagram caption and images using Gemini.
 *
 * @param string     $caption
 * @param array      $image_items
 * @param string     $location_hint
 * @param array|null $forced_selection Optional ['kind' => string, 'country' => string, 'types' => string[]] for regeneration.
 * @param string[]   $retry_errors     Validation errors from first attempt (if retrying).
 * @return array|WP_Error
 */
function upx_ig_generate_reference_json($caption, $image_items, $location_hint = '', $forced_selection = null, $retry_errors = array()) {
	$data       = upx_data();
	$img_count  = max(1, count($image_items));
	$countries  = array();
	$c_map      = array();
	$products   = array();
	$p_map      = array();

	if ($data && !empty($data['countries'])) {
		foreach ($data['countries'] as $c) {
			$slug             = $c['slug'];
			$name             = isset($data['l10n']['en']['countries'][$slug]) ? $data['l10n']['en']['countries'][$slug] : $slug;
			$countries[]      = $slug;
			$c_map[$slug]     = $name;
		}
	}
	if ($data && !empty($data['products'])) {
		foreach ($data['products'] as $p) {
			$u          = $p['url'];
			$lbl        = isset($data['l10n']['en']['products'][$u]['label']) ? $data['l10n']['en']['products'][$u]['label'] : $u;
			$products[] = $u;
			$p_map[$u]  = $lbl;
		}
	}

	$product_lines = array();
	foreach ($p_map as $u => $lbl) {
		$product_lines[] = sprintf('  - "%s" => %s', $u, $lbl);
	}

	$country_sample = array();
	foreach ($c_map as $slug => $name) {
		$country_sample[] = sprintf('"%s" (%s)', $slug, $name);
	}

	$forced_block = '';
	if (is_array($forced_selection)) {
		$f_kind    = !empty($forced_selection['kind']) ? $forced_selection['kind'] : 'project';
		$f_country = !empty($forced_selection['country']) ? $forced_selection['country'] : '';
		$f_cname   = ($f_country !== '' && isset($c_map[$f_country])) ? $c_map[$f_country] : $f_country;
		$f_types   = !empty($forced_selection['types']) && is_array($forced_selection['types']) ? $forced_selection['types'] : array();
		$f_tlabels = array();
		foreach ($f_types as $tu) {
			$f_tlabels[] = isset($p_map[$tu]) ? $p_map[$tu] . ' (' . $tu . ')' : $tu;
		}
		$forced_block = "\nMANDATORY ADMIN SELECTIONS (You MUST write the article specifically for these exact selections):\n"
			. "- suggested_type MUST be: \"" . $f_kind . "\"\n"
			. "- suggested_country MUST be: " . ($f_country !== '' ? "\"" . $f_country . "\" (" . $f_cname . ")" : "null") . "\n"
			. "- suggested_court_types MUST be: " . wp_json_encode(array_values($f_types)) . " (" . implode(', ', $f_tlabels) . ")\n"
			. "- You MUST include the country name \"" . $f_cname . "\" in `title`, `slug`, `meta_description`, and the first paragraph of `body_html`.\n"
			. "- You MUST mention and link every selected court type in `body_html` using `<a href=\"URL\">Label</a>`.\n";
	}

	$retry_block = '';
	if (!empty($retry_errors)) {
		$retry_block = "\nCRITICAL CORRECTION REQUIRED — Your previous output failed validation with these exact errors:\n"
			. "- " . implode("\n- ", $retry_errors) . "\n"
			. "Fix EVERY SINGLE ONE of these errors in your new JSON response!\n";
	}

	$prompt = "Transform this Instagram post from Unixpadel (@unixpadel) into a complete, internally consistent B2B website reference entry.\n\n"
		. "Instagram Caption:\n\"\"\"\n" . $caption . "\n\"\"\"\n"
		. ($location_hint !== '' ? "Instagram Location Tag: " . $location_hint . "\n" : '')
		. "Total images in tray: " . $img_count . "\n"
		. $forced_block
		. $retry_block
		. "\n=== STRICT FACT RULES ===\n"
		. "1. Use ONLY facts present in the caption (and location tag if provided). Attached images are ONLY for writing `image_alts` and picking `cover_suggestion_index` — NEVER read text, flags, or court counts from images.\n"
		. "2. NEVER invent: number of courts, client/club name, city, dates, turnaround time, or turf/glass/lighting specs not in the caption.\n"
		. "3. If the caption contains NO digits/numbers of courts, do NOT write any specific number of courts (like '2 courts', '4 courts', etc.) in `title` or `body_html`.\n"
		. "4. NEVER write 'FIP-approved' or 'FIP-certified' unless the caption explicitly contains 'FIP'. Safe general statement: Unixpadel manufactures padel courts in Türkiye and exports worldwide.\n"
		. "5. If the country is unclear from the caption (and not forced above), set `suggested_country` to null, add 'Country not mentioned in caption' to `missing_info`, and write location-neutral copy.\n"
		. "6. If the court model is unclear from the caption (and not forced above), set `suggested_court_types` to [], add 'Court model not specified in caption' to `missing_info`, and describe the project generally as 'padel court installation'.\n"
		. "7. List every missing detail in `missing_info`.\n\n"
		. "=== B2B STYLE & STRUCTURE RULES ===\n"
		. "1. Strip ALL hashtags (#...), @mentions (@...), emojis, and social phrasing ('Check out…', 'DM us', 'link in bio', 'We are thrilled…').\n"
		. "2. Write 250–400 words in `body_html` using ONLY allowed HTML tags: `<p>`, `<h2>`, `<h3>`, `<strong>`, `<em>`, `<a>`, `<ul>`, `<li>` (NO `<h1>`):\n"
		. "   - Opening `<p>` lead paragraph: include the country/region (if known), the court type(s) (if known), and what was delivered.\n"
		. "   - `<h2>Project overview</h2>`: `<p>` paragraph + optional `<ul><li>` list of confirmed project details.\n"
		. "   - `<h2>Court specifications & installation</h2>`: `<p>` explaining the court system and turnkey manufacturing/installation. Link each item in `suggested_court_types` ONCE to its exact product page URL (`<a href=\"/panoramic-padel-court/\">Panoramic Padel Court</a>`) with natural anchor text. Do NOT link any unselected court type.\n"
		. "   - If `suggested_country` is non-null: `<h2>Padel court supply & installation in {Country Name}</h2>` (`<p>` paragraph on Unixpadel's export & installation service in that country).\n"
		. "   - Closing `<p>` with a call to action linking to `<a href=\"/contact-us/#quote\">Request a quote</a>`.\n"
		. "3. Consistency rules:\n"
		. "   - If `suggested_country` is non-null, its English Country Name MUST appear in `title`, `slug` (slugified), `meta_description`, AND the first `<p>` paragraph of `body_html`. No other country name may appear in `body_html` (except 'Türkiye' when stating Unixpadel manufactures in Türkiye).\n"
		. "   - `title`: <= 70 characters, plain text, no emojis/hashtags.\n"
		. "   - `slug`: lowercase a-z, 0-9, and hyphens only, <= 60 characters.\n"
		. "   - `excerpt`: 1–2 sentences, <= 160 characters.\n"
		. "   - `seo_title`: <= 60 characters.\n"
		. "   - `meta_description`: MUST be between 140 and 155 characters (inclusive).\n"
		. "   - `focus_keyphrase`: e.g. 'padel courts in {Country}' or 'panoramic padel court installation'.\n"
		. "   - `image_alts`: Array of exactly " . $img_count . " descriptive English alt texts (one per image in order, 8–18 words each, descriptive, no keyword stuffing).\n"
		. "   - `cover_suggestion_index`: 0-based index (0 to " . ($img_count - 1) . ") of the best wide/clear full-court photo for the cover.\n\n"
		. "=== AVAILABLE COURT TYPE OPTIONS (pick 0 to 2 URLs for `suggested_court_types`) ===\n"
		. implode("\n", $product_lines) . "\n\n"
		. "=== AVAILABLE COUNTRY SLUGS (pick 1 slug for `suggested_country` or null) ===\n"
		. implode(', ', $country_sample) . "\n\n"
		. "Return ONLY a valid JSON object matching this exact structure:\n"
		. "{\n"
		. "  \"title\": \"string (<= 70 chars)\",\n"
		. "  \"slug\": \"string (a-z 0-9 hyphens, <= 60 chars)\",\n"
		. "  \"excerpt\": \"string (1-2 sentences, <= 160 chars)\",\n"
		. "  \"body_html\": \"string (HTML with p, h2, h3, strong, em, a, ul, li)\",\n"
		. "  \"seo_title\": \"string (<= 60 chars)\",\n"
		. "  \"meta_description\": \"string (140-155 chars)\",\n"
		. "  \"focus_keyphrase\": \"string\",\n"
		. "  \"image_alts\": [\"string\"],\n"
		. "  \"suggested_type\": \"project\" | \"news\" | null,\n"
		. "  \"suggested_country\": \"string\" | null,\n"
		. "  \"suggested_court_types\": [\"string\"],\n"
		. "  \"suggestion_confidence\": { \"type\": \"high\"|\"medium\"|\"low\", \"country\": \"high\"|\"medium\"|\"low\", \"court_types\": \"high\"|\"medium\"|\"low\" },\n"
		. "  \"cover_suggestion_index\": 0,\n"
		. "  \"missing_info\": [\"string\"]\n"
		. "}";

	$multimodal_images = empty($retry_errors) ? upx_ig_prepare_multimodal_images($image_items) : array();

	$raw = upx_gemini_call($prompt, upx_ai_system_instruction(), true, 0.25, $multimodal_images);
	if (is_wp_error($raw)) {
		return $raw;
	}

	return upx_ig_normalize_ai_output($raw, $img_count, $countries, $products, $forced_selection);
}

/**
 * Normalizes the raw Gemini JSON response into a predictable structure and validates enum values.
 *
 * @param array      $raw
 * @param int        $img_count
 * @param string[]   $valid_countries
 * @param string[]   $valid_products
 * @param array|null $forced_selection
 * @return array
 */
function upx_ig_normalize_ai_output($raw, $img_count, $valid_countries, $valid_products, $forced_selection = null) {
	$str = function ($k) use ($raw) {
		return isset($raw[$k]) && is_scalar($raw[$k]) ? trim((string) $raw[$k]) : '';
	};

	$sug_type = isset($raw['suggested_type']) && in_array($raw['suggested_type'], array('project', 'news'), true)
		? (string) $raw['suggested_type']
		: null;

	$sug_country = isset($raw['suggested_country']) && is_string($raw['suggested_country']) && in_array($raw['suggested_country'], $valid_countries, true)
		? $raw['suggested_country']
		: null;

	$sug_types = array();
	if (isset($raw['suggested_court_types']) && is_array($raw['suggested_court_types'])) {
		foreach ($raw['suggested_court_types'] as $u) {
			$u = (string) $u;
			if (in_array($u, $valid_products, true) && !in_array($u, $sug_types, true)) {
				$sug_types[] = $u;
			}
		}
		$sug_types = array_slice($sug_types, 0, 2);
	}

	// Enforce forced selections when regenerating text in Phase 5.
	if (is_array($forced_selection)) {
		if (!empty($forced_selection['kind']) && in_array($forced_selection['kind'], array('project', 'news'), true)) {
			$sug_type = $forced_selection['kind'];
		}
		$sug_country = (!empty($forced_selection['country']) && in_array($forced_selection['country'], $valid_countries, true))
			? $forced_selection['country']
			: null;
		if (isset($forced_selection['types']) && is_array($forced_selection['types'])) {
			$sug_types = array_slice(array_values(array_intersect($forced_selection['types'], $valid_products)), 0, 2);
		}
	}

	$conf_raw = isset($raw['suggestion_confidence']) && is_array($raw['suggestion_confidence']) ? $raw['suggestion_confidence'] : array();
	$norm_c   = function ($k) use ($conf_raw) {
		$v = isset($conf_raw[$k]) ? strtolower((string) $conf_raw[$k]) : 'medium';
		return in_array($v, array('high', 'medium', 'low'), true) ? $v : 'medium';
	};

	$alts = array();
	if (isset($raw['image_alts']) && is_array($raw['image_alts'])) {
		foreach ($raw['image_alts'] as $a) {
			if (is_scalar($a)) {
				$alts[] = trim((string) $a);
			}
		}
	}
	while (count($alts) < $img_count) {
		$alts[] = 'Unixpadel padel court installation project photo ' . (count($alts) + 1);
	}
	$alts = array_slice($alts, 0, $img_count);

	$missing = array();
	if (isset($raw['missing_info']) && is_array($raw['missing_info'])) {
		foreach ($raw['missing_info'] as $m) {
			if (is_scalar($m) && trim((string) $m) !== '') {
				$missing[] = trim((string) $m);
			}
		}
	}

	$cover_idx = isset($raw['cover_suggestion_index']) ? (int) $raw['cover_suggestion_index'] : 0;
	if ($cover_idx < 0 || $cover_idx >= $img_count) {
		$cover_idx = 0;
	}

	return array(
		'title'                  => $str('title'),
		'slug'                   => $str('slug'),
		'excerpt'                => $str('excerpt'),
		'body_html'              => $str('body_html') !== '' ? $str('body_html') : $str('body'),
		'seo_title'              => $str('seo_title'),
		'meta_description'       => $str('meta_description') !== '' ? $str('meta_description') : $str('seo_desc'),
		'focus_keyphrase'        => $str('focus_keyphrase'),
		'image_alts'             => $alts,
		'suggested_type'         => $sug_type,
		'suggested_country'      => $sug_country,
		'suggested_court_types'  => $sug_types,
		'suggestion_confidence'  => array(
			'type'        => $norm_c('type'),
			'country'     => $norm_c('country'),
			'court_types' => $norm_c('court_types'),
		),
		'cover_suggestion_index' => $cover_idx,
		'missing_info'           => $missing,
	);
}

/**
 * Phase 4.2 — PHP Consistency Validator.
 * Checks all consistency, fact, length, and formatting rules before saving.
 *
 * @param array  $res     Normalized Gemini output from upx_ig_normalize_ai_output().
 * @param string $caption Original caption text.
 * @return string[] List of validation error messages (empty array if all passed).
 */
function upx_ig_validate_content($res, $caption) {
	$errors = array();
	$data   = upx_data();

	$title     = $res['title'];
	$slug      = $res['slug'];
	$excerpt   = $res['excerpt'];
	$body      = $res['body_html'];
	$seo_title = $res['seo_title'];
	$meta_desc = $res['meta_description'];

	// Extract first paragraph text from body_html.
	$first_p = '';
	if (preg_match('/<p[^>]*>([\s\S]*?)<\/p>/i', $body, $pm)) {
		$first_p = wp_strip_all_tags($pm[1]);
	} else {
		$first_p = wp_strip_all_tags($body);
	}

	// 1. Country consistency check.
	$c_slug = $res['suggested_country'];
	if ($c_slug !== null && $c_slug !== '' && $data && isset($data['l10n']['en']['countries'][$c_slug])) {
		$c_name = $data['l10n']['en']['countries'][$c_slug];
		// Variants (e.g. Türkiye / Turkey, United Kingdom / UK, USA / United States).
		$variants = array($c_name, str_replace('-', ' ', $c_slug));
		if ($c_slug === 'turkiye') {
			$variants[] = 'Turkey';
			$variants[] = 'Turkiye';
		} elseif ($c_slug === 'united-kingdom') {
			$variants[] = 'UK';
			$variants[] = 'England';
			$variants[] = 'Britain';
		} elseif ($c_slug === 'usa') {
			$variants[] = 'United States';
			$variants[] = 'America';
		}

		$contains_country = function ($haystack) use ($variants) {
			foreach ($variants as $v) {
				if ($v !== '' && mb_stripos($haystack, $v, 0, 'UTF-8') !== false) {
					return true;
				}
			}
			return false;
		};

		if (!$contains_country($title)) {
			$errors[] = sprintf('Country "%s" is missing from `title`.', $c_name);
		}
		$slug_variants = array($c_slug, sanitize_title($c_name));
		$slug_has_c    = false;
		foreach ($slug_variants as $sv) {
			if ($sv !== '' && strpos($slug, $sv) !== false) {
				$slug_has_c = true;
				break;
			}
		}
		if (!$slug_has_c) {
			$errors[] = sprintf('Country slug "%s" is missing from `slug`.', $c_slug);
		}
		if (!$contains_country($meta_desc)) {
			$errors[] = sprintf('Country "%s" is missing from `meta_description`.', $c_name);
		}
		if (!$contains_country($first_p)) {
			$errors[] = sprintf('Country "%s" is missing from the first paragraph of `body_html`.', $c_name);
		}
	}

	// 2. Court types consistency check.
	if (!empty($res['suggested_court_types'])) {
		foreach ($res['suggested_court_types'] as $prod_url) {
			$label = ($data && isset($data['l10n']['en']['products'][$prod_url]['label']))
				? $data['l10n']['en']['products'][$prod_url]['label']
				: trim($prod_url, '/');
			if (strpos($body, $prod_url) === false) {
				$errors[] = sprintf('Selected court type "%s" must be linked in `body_html` with href="%s".', $label, $prod_url);
			}
		}
	}

	// Check that no unselected court type product URL is linked in body_html.
	if ($data && !empty($data['products'])) {
		foreach ($data['products'] as $p) {
			$u = $p['url'];
			if (!in_array($u, $res['suggested_court_types'], true) && strpos($body, 'href="' . $u . '"') !== false) {
				$errors[] = sprintf('Unselected court type URL "%s" was linked in `body_html` — remove that link.', $u);
			}
		}
	}

	// 3. No invented court count if caption has no digits/number words.
	$caption_has_number = (bool) preg_match('/\d|\b(one|two|three|four|five|six|seven|eight|nine|ten|single|double)\b/i', $caption);
	if (!$caption_has_number) {
		$combined_text = $title . ' ' . wp_strip_all_tags($body);
		if (preg_match('/\b(\d+|two|three|four|five|six|seven|eight|nine|ten)\s+(?:[a-z-]+\s+){0,2}courts?\b/i', $combined_text, $cm)) {
			$errors[] = sprintf('Caption contains no court numbers, but generated text invented a specific court count ("%s"). Remove any specific number of courts.', $cm[0]);
		}
	}

	// 4. "FIP-approved" / "FIP-certified" prohibited unless "FIP" is in the caption.
	if (stripos($caption, 'fip') === false) {
		$combined_text = $title . ' ' . $excerpt . ' ' . wp_strip_all_tags($body) . ' ' . $meta_desc;
		if (preg_match('/\bfip[\s-]*(approved|certified|standard|compliant)\b/i', $combined_text, $fm)) {
			$errors[] = sprintf('Caption does not mention FIP, so "%s" is prohibited. Remove all FIP-approved/certified claims.', $fm[0]);
		}
	}

	// 5. Length & format constraints.
	if (mb_strlen($title, 'UTF-8') > 70) {
		$errors[] = sprintf('`title` is %d chars (must be <= 70 chars).', mb_strlen($title, 'UTF-8'));
	}
	if (mb_strlen($seo_title, 'UTF-8') > 60) {
		$errors[] = sprintf('`seo_title` is %d chars (must be <= 60 chars).', mb_strlen($seo_title, 'UTF-8'));
	}
	$md_len = mb_strlen($meta_desc, 'UTF-8');
	if ($md_len < 140 || $md_len > 155) {
		$errors[] = sprintf('`meta_description` is %d chars (must be between 140 and 155 chars).', $md_len);
	}
	if (!preg_match('/^[a-z0-9-]+$/', $slug) || strlen($slug) > 60) {
		$errors[] = '`slug` must match ^[a-z0-9-]+$ and be <= 60 chars.';
	}

	// 6. No hashtags, @mentions, or emojis in output text.
	$all_output = $title . ' ' . $excerpt . ' ' . wp_strip_all_tags($body) . ' ' . $seo_title . ' ' . $meta_desc;
	if (preg_match('/(?:\s|^)#[A-Za-z0-9_]{2,}/u', $all_output)) {
		$errors[] = 'Generated text still contains #hashtags. Strip all hashtags.';
	}
	if (preg_match('/[\x{1F300}-\x{1F9FF}\x{2600}-\x{26FF}\x{2700}-\x{27BF}]/u', $all_output)) {
		$errors[] = 'Generated text contains emojis. Strip all emojis.';
	}

	return $errors;
}

/**
 * Deterministic post-sanitizer applied after Gemini generation (and retry) to guarantee clean HTML,
 * valid slug, no hashtags/emojis, and allowed tags.
 *
 * @param array  $res
 * @param string $caption
 * @return array
 */
function upx_ig_sanitize_final_output($res, $caption) {
	$strip_social = function ($s) {
		$s = (string) $s;
		// Remove hashtags and emojis.
		$s = preg_replace('/(?:\s|^)#[A-Za-z0-9_\p{L}]+/u', '', $s);
		$s = preg_replace('/[\x{1F300}-\x{1F9FF}\x{2600}-\x{26FF}\x{2700}-\x{27BF}]/u', '', $s);
		return trim(preg_replace('/\s{2,}/u', ' ', $s));
	};

	$res['title']            = mb_substr($strip_social($res['title']), 0, 70, 'UTF-8');
	$res['excerpt']          = mb_substr($strip_social($res['excerpt']), 0, 160, 'UTF-8');
	$res['seo_title']        = mb_substr($strip_social($res['seo_title']), 0, 60, 'UTF-8');
	$res['meta_description'] = mb_substr($strip_social($res['meta_description']), 0, 158, 'UTF-8');
	$res['focus_keyphrase']  = mb_substr($strip_social($res['focus_keyphrase']), 0, 100, 'UTF-8');

	$slug = upx_ref_clean_slug($res['slug'] !== '' ? $res['slug'] : $res['title']);
	$res['slug'] = substr($slug, 0, 60);

	// Strip FIP claims if not in caption.
	if (stripos($caption, 'fip') === false) {
		$res['body_html']        = preg_replace('/\bFIP[\s-]*(approved|certified)\s*/i', '', $res['body_html']);
		$res['excerpt']          = preg_replace('/\bFIP[\s-]*(approved|certified)\s*/i', '', $res['excerpt']);
		$res['meta_description'] = preg_replace('/\bFIP[\s-]*(approved|certified)\s*/i', '', $res['meta_description']);
	}

	// Restrict body_html to allowed tags: p, h2, h3, strong, em, a, ul, li.
	$allowed = array(
		'p'      => array(),
		'h2'     => array(),
		'h3'     => array(),
		'strong' => array(),
		'em'     => array(),
		'ul'     => array(),
		'li'     => array(),
		'a'      => array(
			'href'   => true,
			'title'  => true,
			'target' => true,
			'rel'    => true,
		),
	);
	$body = wp_kses($res['body_html'], $allowed);
	$body = preg_replace('/(?:\s|^)#[A-Za-z0-9_\p{L}]+/u', '', $body);
	$body = preg_replace('/[\x{1F300}-\x{1F9FF}\x{2600}-\x{26FF}\x{2700}-\x{27BF}]/u', '', $body);
	$res['body_html'] = trim($body);

	return $res;
}

/**
 * Phase 4.3 — Sideloads remote Instagram images and/or attaches existing Media Library IDs.
 * Deduplicates by (_ig_shortcode, _ig_image_index) and re-runs the extractor once if a CDN URL expired.
 *
 * @param array    $image_items Array of ['type' => 'url'|'attachment', 'url' => string, 'id' => int, 'orig_index' => int, 'is_video' => bool]
 * @param string   $slug        Reference slug for naming files `unixpadel-<slug>-<n>.jpg`
 * @param string[] $image_alts  Descriptive alt texts generated by Gemini
 * @param string   $shortcode   Instagram shortcode (if Mode A)
 * @param int      $post_id     Parent reference post ID
 * @return array { attachment_ids: int[], warnings: string[] }
 */
function upx_ig_sideload_images($image_items, $slug, $image_alts, $shortcode = '', $post_id = 0) {
	if (!function_exists('media_handle_sideload')) {
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
	}

	$clean_slug     = upx_ref_clean_slug($slug !== '' ? $slug : 'reference');
	$attachment_ids = array();
	$warnings       = array();
	$refreshed_map  = null; // Populated at most once if a CDN download fails.

	foreach ($image_items as $idx => $item) {
		$alt        = isset($image_alts[$idx]) && $image_alts[$idx] !== '' ? sanitize_text_field($image_alts[$idx]) : 'Unixpadel padel court installation ' . ($idx + 1);
		$orig_index = isset($item['orig_index']) ? (int) $item['orig_index'] : $idx;

		// Case 1: Already a WordPress Media Library attachment (Mode B or manually added in Preview tray).
		if (isset($item['type']) && $item['type'] === 'attachment' && !empty($item['id'])) {
			$att_id = absint($item['id']);
			if ($att_id > 0 && wp_attachment_is_image($att_id)) {
				$existing_alt = trim((string) get_post_meta($att_id, '_wp_attachment_image_alt', true));
				if ($existing_alt === '') {
					update_post_meta($att_id, '_wp_attachment_image_alt', wp_slash($alt));
				}
				$attachment_ids[] = $att_id;
				continue;
			}
		}

		// Case 2: Remote URL from Instagram (Mode A).
		$url = isset($item['url']) ? trim((string) $item['url']) : '';
		if ($url === '' || strpos($url, 'http') !== 0) {
			continue;
		}

		// Deduplication check: if an attachment already exists with the same _ig_shortcode and _ig_image_index.
		if ($shortcode !== '') {
			$existing_att = get_posts(array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => 1,
				'meta_query'     => array(
					'relation' => 'AND',
					array('key' => '_ig_shortcode', 'value' => $shortcode),
					array('key' => '_ig_image_index', 'value' => (string) $orig_index),
				),
			));
			if (!empty($existing_att)) {
				$att_id = (int) $existing_att[0]->ID;
				update_post_meta($att_id, '_wp_attachment_image_alt', wp_slash($alt));
				$attachment_ids[] = $att_id;
				continue;
			}
		}

		// Download remote URL; if it fails and we have a shortcode, re-run extractor once with force_refresh = true.
		$tmp = download_url($url, 25);
		if (is_wp_error($tmp) && $shortcode !== '' && $refreshed_map === null) {
			$refreshed     = UPX_Instagram_Post_Extractor::extract($shortcode, true);
			$refreshed_map = isset($refreshed['images']) && is_array($refreshed['images']) ? $refreshed['images'] : array();
			if (isset($refreshed_map[$orig_index]['url'])) {
				$url = $refreshed_map[$orig_index]['url'];
				$tmp = download_url($url, 25);
			}
		}

		if (is_wp_error($tmp)) {
			$warnings[] = sprintf('Image #%d could not be downloaded from Instagram (%s).', $idx + 1, $tmp->get_error_message());
			continue;
		}

		// Validate MIME & dimensions.
		$img_info = @getimagesize($tmp);
		if (!is_array($img_info) || empty($img_info[0]) || empty($img_info[1])) {
			@unlink($tmp);
			$warnings[] = sprintf('Image #%d was not a valid image file.', $idx + 1);
			continue;
		}

		$width  = (int) $img_info[0];
		$height = (int) $img_info[1];
		$mime   = isset($img_info['mime']) ? $img_info['mime'] : 'image/jpeg';
		$ext    = 'jpg';
		if ($mime === 'image/png') {
			$ext = 'png';
		} elseif ($mime === 'image/webp') {
			$ext = 'webp';
		}

		if ($width < 1080) {
			$warnings[] = sprintf('Image #%d is %dx%d px (below the recommended 1080 px width). Consider replacing it with a higher-resolution photo.', $idx + 1, $width, $height);
		}

		$file_array = array(
			'name'     => sprintf('unixpadel-%s-%d.%s', $clean_slug, $idx + 1, $ext),
			'tmp_name' => $tmp,
		);

		$att_id = media_handle_sideload($file_array, $post_id, $alt);
		if (is_wp_error($att_id)) {
			@unlink($tmp);
			$warnings[] = sprintf('Image #%d failed media library import (%s).', $idx + 1, $att_id->get_error_message());
			continue;
		}

		update_post_meta($att_id, '_wp_attachment_image_alt', wp_slash($alt));
		if ($shortcode !== '') {
			update_post_meta($att_id, '_ig_shortcode', wp_slash($shortcode));
			update_post_meta($att_id, '_ig_image_index', (string) $orig_index);
		}
		update_post_meta($att_id, '_ig_source_url', esc_url_raw($url));

		$attachment_ids[] = (int) $att_id;
	}

	return array(
		'attachment_ids' => $attachment_ids,
		'warnings'       => $warnings,
	);
}

/* ---------- AJAX Endpoints ---------- */

/**
 * AJAX Phase 1 & 2: Fetch Instagram Post by URL (Mode A) + Duplicate Shortcode Check.
 */
function upx_ajax_ig_fetch() {
	check_ajax_referer('upx_ai_action', 'nonce');
	if (!current_user_can('edit_posts')) {
		wp_send_json_error(array('message' => 'Unauthorized'), 403);
	}

	$raw_url           = isset($_POST['url']) ? trim( sanitize_text_field(wp_unslash($_POST['url'])) ) : '';
	$post_id           = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;
	$confirm_duplicate = !empty($_POST['confirm_duplicate']);

	$norm = UPX_Instagram_Post_Extractor::normalize_url($raw_url);
	if (is_wp_error($norm)) {
		wp_send_json_error(array(
			'code'    => $norm->get_error_code(),
			'message' => $norm->get_error_message(),
		), 400);
	}

	// Duplicate shortcode check.
	$existing = upx_ig_find_by_shortcode($norm['shortcode'], $post_id);
	if ($existing && !$confirm_duplicate) {
		wp_send_json_success(array(
			'duplicate' => true,
			'shortcode' => $norm['shortcode'],
			'permalink' => $norm['permalink'],
			'existing'  => $existing,
		));
	}

	$extracted = UPX_Instagram_Post_Extractor::extract($norm['permalink']);
	$extracted['duplicate'] = false;
	$extracted['existing']  = $existing;

	wp_send_json_success($extracted);
}
add_action('wp_ajax_upx_ig_fetch', 'upx_ajax_ig_fetch');

/**
 * AJAX Phase 4: Generate complete reference draft from Preview step (Mode A or Mode B).
 */
function upx_ajax_ig_generate() {
	check_ajax_referer('upx_ai_action', 'nonce');
	if (!current_user_can('edit_posts')) {
		wp_send_json_error(array('message' => 'Unauthorized'), 403);
	}

	$post_id       = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;
	$input_mode    = isset($_POST['input_mode']) && $_POST['input_mode'] === 'link' ? 'link' : 'manual';
	$shortcode     = isset($_POST['shortcode']) ? sanitize_text_field(wp_unslash($_POST['shortcode'])) : '';
	$permalink     = isset($_POST['permalink']) ? esc_url_raw(wp_unslash($_POST['permalink'])) : '';
	$location_hint = isset($_POST['location_name']) ? sanitize_text_field(wp_unslash($_POST['location_name'])) : '';
	$caption       = isset($_POST['caption']) ? sanitize_textarea_field(wp_unslash($_POST['caption'])) : '';
	$cover_index   = isset($_POST['cover_index']) ? max(0, (int) $_POST['cover_index']) : 0;
	$raw_images    = isset($_POST['images']) && is_array($_POST['images']) ? wp_unslash($_POST['images']) : array();

	if (trim($caption) === '') {
		wp_send_json_error(array('message' => 'Caption is required to generate a reference.'), 400);
	}
	if (empty($raw_images)) {
		wp_send_json_error(array('message' => 'At least 1 image is required to generate a reference.'), 400);
	}

	// Normalize image list from preview tray, putting the admin's chosen cover image first (index 0).
	$image_items = array();
	foreach ($raw_images as $idx => $im) {
		if (!is_array($im)) {
			continue;
		}
		$type = isset($im['type']) && $im['type'] === 'attachment' ? 'attachment' : 'url';
		$image_items[] = array(
			'type'       => $type,
			'id'         => isset($im['id']) ? absint($im['id']) : 0,
			'url'        => isset($im['url']) ? esc_url_raw((string) $im['url']) : '',
			'orig_index' => isset($im['orig_index']) ? (int) $im['orig_index'] : (int) $idx,
			'is_video'   => !empty($im['is_video']),
		);
	}
	if (empty($image_items)) {
		wp_send_json_error(array('message' => 'No valid images provided.'), 400);
	}

	// Move chosen cover image to index 0 so cover is always the first item in gallery / featured image.
	if ($cover_index > 0 && isset($image_items[$cover_index])) {
		$cover_item = $image_items[$cover_index];
		unset($image_items[$cover_index]);
		array_unshift($image_items, $cover_item);
		$image_items = array_values($image_items);
	}

	// 4.1 Generate structured JSON via Gemini + 4.2 PHP Consistency Validator (with 1 automatic retry if validation fails).
	$ai_res = upx_ig_generate_reference_json($caption, $image_items, $location_hint);
	if (is_wp_error($ai_res)) {
		wp_send_json_error(array('message' => $ai_res->get_error_message()), 500);
	}

	$val_errors = upx_ig_validate_content($ai_res, $caption);
	if (!empty($val_errors)) {
		$retry_res = upx_ig_generate_reference_json($caption, $image_items, $location_hint, null, $val_errors);
		if (!is_wp_error($retry_res)) {
			$ai_res     = $retry_res;
			$val_errors = upx_ig_validate_content($ai_res, $caption);
		}
	}

	$ai_res = upx_ig_sanitize_final_output($ai_res, $caption);
	if (!empty($val_errors)) {
		foreach ($val_errors as $ve) {
			$ai_res['missing_info'][] = 'Note: ' . $ve;
		}
	}

	// Ensure we have a valid draft post to attach media and meta to.
	if ($post_id > 0 && get_post_type($post_id) === UPX_REF_TYPE && current_user_can('edit_post', $post_id)) {
		wp_update_post(array(
			'ID'           => $post_id,
			'post_title'   => $ai_res['title'],
			'post_name'    => $ai_res['slug'],
			'post_excerpt' => $ai_res['excerpt'],
			'post_content' => $ai_res['body_html'],
			'post_status'  => 'draft',
		));
	} else {
		$post_id = wp_insert_post(array(
			'post_type'    => UPX_REF_TYPE,
			'post_title'   => $ai_res['title'],
			'post_name'    => $ai_res['slug'],
			'post_excerpt' => $ai_res['excerpt'],
			'post_content' => $ai_res['body_html'],
			'post_status'  => 'draft',
		), true);
		if (is_wp_error($post_id)) {
			wp_send_json_error(array('message' => $post_id->get_error_message()), 500);
		}
	}

	// 4.3 Sideload images into Media Library & set cover as featured image.
	$sideload = upx_ig_sideload_images($image_items, $ai_res['slug'], $ai_res['image_alts'], $shortcode, $post_id);
	$att_ids  = $sideload['attachment_ids'];
	foreach ($sideload['warnings'] as $w) {
		$ai_res['missing_info'][] = $w;
	}

	if (empty($att_ids)) {
		wp_send_json_error(array('message' => 'None of the images could be imported into the Media Library. Please upload the images manually in Mode B.'), 500);
	}

	$cover_att_id = $att_ids[0];
	set_post_thumbnail($post_id, $cover_att_id);

	// Check if cover image is non-landscape (square/portrait) and add a non-blocking warning.
	$cover_meta = wp_get_attachment_metadata($cover_att_id);
	if (is_array($cover_meta) && !empty($cover_meta['width']) && !empty($cover_meta['height'])) {
		if ((int) $cover_meta['width'] <= (int) $cover_meta['height']) {
			$ai_res['missing_info'][] = sprintf(
				'Cover photo is %dx%d px (%s). Cards on /references/ use a 4:3 crop (object-fit: cover), so please check the preview to confirm vertical cropping looks good.',
				(int) $cover_meta['width'],
				(int) $cover_meta['height'],
				$cover_meta['width'] == $cover_meta['height'] ? 'square' : 'portrait'
			);
		}
	}

	// 4.4 Save provenance & AI suggestion meta. Leave Type, Country, and Court types EMPTY until admin selects them!
	update_post_meta($post_id, '_source', 'instagram');
	update_post_meta($post_id, '_input_mode', $input_mode);
	update_post_meta($post_id, '_ig_shortcode', wp_slash($shortcode));
	update_post_meta($post_id, '_ig_permalink', esc_url_raw($permalink));
	update_post_meta($post_id, '_ig_raw_caption', wp_slash($caption));

	$sug_type    = $ai_res['suggested_type'] ? $ai_res['suggested_type'] : 'project';
	$sug_country = $ai_res['suggested_country'] ? $ai_res['suggested_country'] : '';
	$sug_types   = is_array($ai_res['suggested_court_types']) ? $ai_res['suggested_court_types'] : array();

	update_post_meta($post_id, '_ai_suggested_type', wp_slash($sug_type));
	update_post_meta($post_id, '_ai_suggested_country', wp_slash($sug_country));
	update_post_meta($post_id, '_ai_suggested_court_types', wp_slash($sug_types));
	update_post_meta($post_id, '_ai_suggestion_confidence', wp_slash($ai_res['suggestion_confidence']));
	update_post_meta($post_id, '_ai_missing_info', wp_slash(array_values(array_unique($ai_res['missing_info']))));
	update_post_meta($post_id, '_ai_cover_suggestion_index', (int) $ai_res['cover_suggestion_index']);

	$gen_hash = upx_ig_selection_hash($sug_type, $sug_country, $sug_types);
	update_post_meta($post_id, '_generated_for', $gen_hash);
	update_post_meta($post_id, '_generated_for_meta', array(
		'kind'    => $sug_type,
		'country' => $sug_country,
		'types'   => $sug_types,
	));

	// Store _upx_ref with Type (''), Country (''), and Court types ([]) left EMPTY!
	$ref_meta = array(
		'country'         => '',
		'types'           => array(),
		'seo_title'       => $ai_res['seo_title'],
		'seo_desc'        => $ai_res['meta_description'],
		'focus_keyphrase' => $ai_res['focus_keyphrase'],
		'gallery'         => $att_ids,
		'kind'            => '',
		'sort'            => 0,
		'image_url'       => '',
		'image_alt'       => '',
	);
	update_post_meta($post_id, UPX_META_REF, wp_slash($ref_meta));

	// Run the existing translation pipeline for TR, DE, FR, AR, RU.
	$translations = upx_ai_translate_reference($ai_res['title'], $ai_res['excerpt'], $ai_res['body_html'], UPX_REF_LANGS);
	$l10n         = array();
	$trans_status = array();

	if (is_array($translations)) {
		foreach (UPX_REF_LANGS as $lang) {
			if (isset($translations[$lang]) && is_array($translations[$lang]) && !empty($translations[$lang]['title']) && !empty($translations[$lang]['body'])) {
				$t = $translations[$lang];
				$l10n[$lang] = array(
					'title'     => mb_substr(sanitize_text_field($t['title']), 0, 300, 'UTF-8'),
					'excerpt'   => mb_substr(sanitize_textarea_field(isset($t['excerpt']) ? $t['excerpt'] : ''), 0, 1000, 'UTF-8'),
					'seo_title' => mb_substr(sanitize_text_field(isset($t['seo_title']) ? $t['seo_title'] : ''), 0, 200, 'UTF-8'),
					'seo_desc'  => mb_substr(sanitize_textarea_field(isset($t['seo_desc']) ? $t['seo_desc'] : ''), 0, 400, 'UTF-8'),
					'body'      => wp_kses_post($t['body']),
				);
				$trans_status[$lang] = 'completed';
			} else {
				$trans_status[$lang] = 'failed';
			}
		}
	} else {
		foreach (UPX_REF_LANGS as $lang) {
			$trans_status[$lang] = 'failed';
		}
	}

	update_post_meta($post_id, UPX_META_L10N, wp_slash($l10n));
	update_post_meta($post_id, '_ai_translation_status', $trans_status);
	delete_post_meta($post_id, UPX_META_HTML);

	set_transient('upx_ig_generated_notice_' . get_current_user_id(), array(
		'post_id'      => $post_id,
		'missing_info' => array_values(array_unique($ai_res['missing_info'])),
		'trans_status' => $trans_status,
	), 300);

	$edit_url = get_edit_post_link($post_id, 'raw');
	if (!$edit_url) {
		$edit_url = admin_url('post.php?post=' . $post_id . '&action=edit');
	}

	wp_send_json_success(array(
		'post_id'      => $post_id,
		'edit_url'     => $edit_url,
		'missing_info' => $ai_res['missing_info'],
		'trans_status' => $trans_status,
	));
}
add_action('wp_ajax_upx_ig_generate', 'upx_ajax_ig_generate');

/**
 * AJAX Phase 5: Regenerate text when admin changes Type, Country, or Court types (keeps images & selections).
 */
function upx_ajax_ig_regenerate() {
	check_ajax_referer('upx_ai_action', 'nonce');
	$post_id = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;
	if (!$post_id || !current_user_can('edit_post', $post_id)) {
		wp_send_json_error(array('message' => 'Unauthorized or invalid post ID.'), 403);
	}

	$data     = upx_data();
	$products = $data ? wp_list_pluck($data['products'], 'url') : array();
	$countries = $data ? wp_list_pluck($data['countries'], 'slug') : array();

	$kind    = isset($_POST['kind']) && in_array($_POST['kind'], array('project', 'news'), true) ? sanitize_key($_POST['kind']) : '';
	$country = isset($_POST['country']) ? sanitize_title(wp_unslash($_POST['country'])) : '';
	$types   = isset($_POST['types']) && is_array($_POST['types']) ? array_values(array_intersect(array_map('strval', wp_unslash($_POST['types'])), $products)) : array();

	if ($kind === '' || $country === '' || empty($types)) {
		wp_send_json_error(array('message' => 'Please select Type, Country, and at least one Court type before regenerating the text.'), 400);
	}

	$caption = (string) get_post_meta($post_id, '_ig_raw_caption', true);
	if (trim($caption) === '') {
		$post    = get_post($post_id);
		$caption = $post ? ($post->post_title . "\n\n" . wp_strip_all_tags($post->post_content)) : '';
	}

	$forced = array(
		'kind'    => $kind,
		'country' => in_array($country, $countries, true) ? $country : '',
		'types'   => array_slice($types, 0, 2),
	);

	$meta = upx_ref_meta($post_id);
	$dummy_images = array(array('type' => 'attachment', 'id' => (int) get_post_thumbnail_id($post_id)));

	$ai_res = upx_ig_generate_reference_json($caption, $dummy_images, '', $forced);
	if (is_wp_error($ai_res)) {
		wp_send_json_error(array('message' => $ai_res->get_error_message()), 500);
	}

	$val_errors = upx_ig_validate_content($ai_res, $caption);
	if (!empty($val_errors)) {
		$retry_res = upx_ig_generate_reference_json($caption, $dummy_images, '', $forced, $val_errors);
		if (!is_wp_error($retry_res)) {
			$ai_res = $retry_res;
		}
	}
	$ai_res = upx_ig_sanitize_final_output($ai_res, $caption);

	// Update post and meta while preserving images & admin selections.
	wp_update_post(array(
		'ID'           => $post_id,
		'post_title'   => $ai_res['title'],
		'post_name'    => $ai_res['slug'],
		'post_excerpt' => $ai_res['excerpt'],
		'post_content' => $ai_res['body_html'],
	));

	$meta['kind']            = $forced['kind'];
	$meta['country']         = $forced['country'];
	$meta['types']           = $forced['types'];
	$meta['seo_title']       = $ai_res['seo_title'];
	$meta['seo_desc']        = $ai_res['meta_description'];
	$meta['focus_keyphrase'] = $ai_res['focus_keyphrase'];
	update_post_meta($post_id, UPX_META_REF, wp_slash($meta));

	$new_hash = upx_ig_selection_hash($forced['kind'], $forced['country'], $forced['types']);
	update_post_meta($post_id, '_generated_for', $new_hash);
	update_post_meta($post_id, '_generated_for_meta', $forced);

	// Re-run translations for all 5 languages so translations stay consistent with the new selections.
	$translations = upx_ai_translate_reference($ai_res['title'], $ai_res['excerpt'], $ai_res['body_html'], UPX_REF_LANGS);
	$l10n         = upx_ref_l10n($post_id);
	if (is_array($translations)) {
		foreach (UPX_REF_LANGS as $lang) {
			if (isset($translations[$lang]) && is_array($translations[$lang]) && !empty($translations[$lang]['title']) && !empty($translations[$lang]['body'])) {
				$t = $translations[$lang];
				$l10n[$lang] = array(
					'title'     => mb_substr(sanitize_text_field($t['title']), 0, 300, 'UTF-8'),
					'excerpt'   => mb_substr(sanitize_textarea_field(isset($t['excerpt']) ? $t['excerpt'] : ''), 0, 1000, 'UTF-8'),
					'seo_title' => mb_substr(sanitize_text_field(isset($t['seo_title']) ? $t['seo_title'] : ''), 0, 200, 'UTF-8'),
					'seo_desc'  => mb_substr(sanitize_textarea_field(isset($t['seo_desc']) ? $t['seo_desc'] : ''), 0, 400, 'UTF-8'),
					'body'      => wp_kses_post($t['body']),
				);
			}
		}
		update_post_meta($post_id, UPX_META_L10N, wp_slash($l10n));
	}
	delete_post_meta($post_id, UPX_META_HTML);
	upx_refs_mark_dirty();

	wp_send_json_success(array(
		'title'           => $ai_res['title'],
		'slug'            => $ai_res['slug'],
		'excerpt'         => $ai_res['excerpt'],
		'body_html'       => $ai_res['body_html'],
		'seo_title'       => $ai_res['seo_title'],
		'seo_desc'        => $ai_res['meta_description'],
		'focus_keyphrase' => $ai_res['focus_keyphrase'],
		'translations'    => $l10n,
		'generated_for'   => $new_hash,
		'generated_label' => upx_ig_selection_label($forced['kind'], $forced['country'], $forced['types']),
	));
}
add_action('wp_ajax_upx_ig_regenerate', 'upx_ajax_ig_regenerate');

/**
 * AJAX Phase 5: Explicit "I have checked the text" acknowledgement to clear stale flag.
 */
function upx_ajax_ig_ack_stale() {
	check_ajax_referer('upx_ai_action', 'nonce');
	$post_id = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;
	if (!$post_id || !current_user_can('edit_post', $post_id)) {
		wp_send_json_error(array('message' => 'Unauthorized or invalid post ID.'), 403);
	}

	$data      = upx_data();
	$products  = $data ? wp_list_pluck($data['products'], 'url') : array();
	$countries = $data ? wp_list_pluck($data['countries'], 'slug') : array();

	$kind    = isset($_POST['kind']) && in_array($_POST['kind'], array('project', 'news'), true) ? sanitize_key($_POST['kind']) : '';
	$country = isset($_POST['country']) ? sanitize_title(wp_unslash($_POST['country'])) : '';
	$types   = isset($_POST['types']) && is_array($_POST['types']) ? array_values(array_intersect(array_map('strval', wp_unslash($_POST['types'])), $products)) : array();

	if ($kind === '' || $country === '' || empty($types)) {
		wp_send_json_error(array('message' => 'Please select Type, Country, and at least one Court type first.'), 400);
	}

	$forced = array(
		'kind'    => $kind,
		'country' => in_array($country, $countries, true) ? $country : '',
		'types'   => array_slice($types, 0, 2),
	);

	$new_hash = upx_ig_selection_hash($forced['kind'], $forced['country'], $forced['types']);
	update_post_meta($post_id, '_generated_for', $new_hash);
	update_post_meta($post_id, '_generated_for_meta', $forced);

	// Also persist the selections into _upx_ref so the post is ready to publish immediately.
	$meta            = upx_ref_meta($post_id);
	$meta['kind']    = $forced['kind'];
	$meta['country'] = $forced['country'];
	$meta['types']   = $forced['types'];
	update_post_meta($post_id, UPX_META_REF, wp_slash($meta));

	wp_send_json_success(array(
		'generated_for'   => $new_hash,
		'generated_label' => upx_ig_selection_label($forced['kind'], $forced['country'], $forced['types']),
	));
}
add_action('wp_ajax_upx_ig_ack_stale', 'upx_ajax_ig_ack_stale');
