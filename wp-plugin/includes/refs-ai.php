<?php
/**
 * AI Assistant & Automatic Multilingual Translation for Unixpadel References.
 * Uses Google Gemini (gemini-3.5-flash-lite / gemini-2.5-flash) to:
 * 1. Generate full English drafts (title, excerpt, body, country, court types) from short notes.
 * 2. Translate English reference content into Turkish (tr), German (de), French (fr), Arabic (ar), and Russian (ru).
 * 3. Auto-translate missing languages on Save/Publish.
 * 4. Auto-detect country, court types, and auto-set main photo from gallery.
 */

if (!defined('ABSPATH')) {
	exit;
}

define('UPX_OPT_GEMINI_KEY', 'upx_gemini_key');
define('UPX_OPT_GEMINI_MODEL', 'upx_gemini_model');
define('UPX_OPT_AI_AUTO_TRANSLATE', 'upx_ai_auto_translate');
define('UPX_DEFAULT_GEMINI_KEY', '');
define('UPX_DEFAULT_GEMINI_MODEL', 'gemini-3.5-flash-lite');

const UPX_GEMINI_FALLBACK_MODELS = array(
	'gemini-3.5-flash-lite',
	'gemini-3.5-flash',
	'gemini-2.5-flash',
	'gemini-flash-latest',
);

/**
 * Returns the configured Gemini API key (wp-config constant > WP option > default key).
 */
function upx_gemini_key() {
	if (defined('UPX_GEMINI_KEY') && UPX_GEMINI_KEY !== '') {
		return (string) UPX_GEMINI_KEY;
	}
	$key = (string) get_option(UPX_OPT_GEMINI_KEY, '');
	return $key !== '' ? $key : UPX_DEFAULT_GEMINI_KEY;
}

/**
 * Returns the preferred Gemini model name.
 */
function upx_gemini_model() {
	if (defined('UPX_GEMINI_MODEL') && UPX_GEMINI_MODEL !== '') {
		return (string) UPX_GEMINI_MODEL;
	}
	$model = (string) get_option(UPX_OPT_GEMINI_MODEL, '');
	return $model !== '' ? $model : UPX_DEFAULT_GEMINI_MODEL;
}

/**
 * Whether automatic translation is enabled on publish/save.
 */
function upx_ai_auto_translate_enabled() {
	return get_option(UPX_OPT_AI_AUTO_TRANSLATE, '1') === '1';
}

/**
 * Sends a prompt to Google Gemini API with automatic model fallback, optional multimodal image parts,
 * optional responseSchema, and JSON decoding.
 *
 * @param string     $prompt
 * @param string     $system_instruction
 * @param bool       $json_mode
 * @param float      $temperature
 * @param array      $images          Optional array of ['mime_type' => string, 'data' => base64_string].
 * @param array|null $response_schema Optional JSON schema for structured output.
 * @return array|string|WP_Error
 */
function upx_gemini_call($prompt, $system_instruction = '', $json_mode = true, $temperature = 0.3, $images = array(), $response_schema = null) {
	$api_key = upx_gemini_key();
	if ($api_key === '') {
		return new WP_Error('upx_ai_no_key', 'Gemini API key is not configured.');
	}

	$preferred = upx_gemini_model();
	$models    = array_unique(array_merge(array($preferred), UPX_GEMINI_FALLBACK_MODELS));

	$parts = array(
		array('text' => (string) $prompt),
	);
	if (is_array($images) && !empty($images)) {
		foreach ($images as $img) {
			if (is_array($img) && !empty($img['data']) && !empty($img['mime_type'])) {
				$parts[] = array(
					'inlineData' => array(
						'mimeType' => (string) $img['mime_type'],
						'data'     => (string) $img['data'],
					),
				);
			}
		}
	}

	$payload = array(
		'contents'         => array(
			array(
				'parts' => $parts,
			),
		),
		'generationConfig' => array(
			'temperature' => (float) $temperature,
		),
	);

	if ($system_instruction !== '') {
		$payload['systemInstruction'] = array(
			'parts' => array(
				array('text' => (string) $system_instruction),
			),
		);
	}

	if ($json_mode) {
		$payload['generationConfig']['responseMimeType'] = 'application/json';
		if (is_array($response_schema) && !empty($response_schema)) {
			$payload['generationConfig']['responseSchema'] = $response_schema;
		}
	}

	$body_json   = wp_json_encode($payload);
	$last_error  = 'No model succeeded';

	foreach ($models as $model) {
		$url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model) . ':generateContent';
		$res = wp_remote_post($url, array(
			'timeout' => 60,
			'headers' => array(
				'Content-Type'   => 'application/json',
				'X-goog-api-key' => $api_key,
			),
			'body'    => $body_json,
		));

		if (is_wp_error($res)) {
			$last_error = $res->get_error_message();
			continue;
		}

		$code = (int) wp_remote_retrieve_response_code($res);
		$body = (string) wp_remote_retrieve_body($res);
		$data = json_decode($body, true);

		if ($code === 200 && is_array($data) && isset($data['candidates'][0]['content']['parts'][0]['text'])) {
			$text = (string) $data['candidates'][0]['content']['parts'][0]['text'];
			if (!$json_mode) {
				return $text;
			}

			// Clean any residual code block delimiters if present.
			$text = preg_replace('/^```(?:json)?\s*/i', '', trim($text));
			$text = preg_replace('/\s*```$/', '', $text);
			$parsed = json_decode($text, true);
			if (is_array($parsed)) {
				return $parsed;
			}
			return new WP_Error('upx_ai_json', 'Failed to parse Gemini JSON response.', array('raw' => $text));
		}

		if (is_array($data) && isset($data['error']['message'])) {
			$last_error = $model . ': ' . $data['error']['message'];
		} else {
			$last_error = $model . ' returned HTTP ' . $code;
		}
	}

	return new WP_Error('upx_ai_failed', 'Gemini API call failed: ' . $last_error);
}

/**
 * System instruction shared by Unixpadel AI tasks.
 */
function upx_ai_system_instruction() {
	return 'You are an expert copywriter, padel engineer, and multilingual localization specialist for Unixpadel (unixpadel.com). '
		. 'Unixpadel manufactures high-performance padel courts, canopies, and mobile court solutions worldwide. '
		. 'Maintain a premium, authoritative, and engaging tone. '
		. 'Preserve HTML tags (<h2>, <h3>, <p>, <ul>, <li>, <strong>, <a>), never generate <h1>. '
		. 'Preserve product names and technical terms: Panoramic Padel Court, Full Panoramic, Flat Floor, MobilFold, FIP, etc.';
}

/**
 * Generates an English draft and optional translations from short notes.
 *
 * @param string $notes
 * @param string $kind 'project' | 'news'
 * @param bool   $translate
 * @return array|WP_Error
 */
function upx_ai_generate_draft($notes, $kind = 'project', $translate = true) {
	$data         = upx_data();
	$products_str = '';
	if ($data && !empty($data['products'])) {
		$p_list = array();
		foreach ($data['products'] as $p) {
			$p_list[] = $p['url'];
		}
		$products_str = implode(', ', $p_list);
	}

	$prompt = "Create a complete, professional website reference entry for Unixpadel based on these notes:\n\n"
		. "User notes: \"\"\"" . $notes . "\"\"\"\n\n"
		. "Content type: " . ($kind === 'news' ? 'Company News / Announcement' : 'Client Court Installation Project') . "\n\n"
		. "Available Unixpadel Court Product URLs: " . ($products_str !== '' ? $products_str : '/panoramic-padel-court/, /full-panoramic-padel-court/, /plate-standard-padel-court/, /standard-padel-court/, /innovative-courts/flat-floor-padel-court/, /floating-floor-padel-court/, /mobilfold-padel-court/, /full-membrane-cover/, /sandwich-panel-full-covered/, /retractable-roof-cover-system/, /roof-top-canopy/, /half-close-canopy/') . "\n\n"
		. "Return a single JSON object with these EXACT keys:\n"
		. "- \"title\": Professional, clear title in English (under 70 chars)\n"
		. "- \"excerpt\": 1-2 sentence compelling summary in English (under 160 chars)\n"
		. "- \"seo_title\": SEO title in English (under 60 chars)\n"
		. "- \"seo_desc\": Meta description in English (under 158 chars)\n"
		. "- \"body\": Engaging HTML article in English. Use <h2> subheadings (e.g. Project Overview, Court Specifications, Turnkey Execution), <p> paragraphs, and <ul>/<li> for technical specs. Do NOT use <h1>.\n"
		. "- \"country\": Country slug if mentioned (e.g. \"austria\", \"germany\", \"turkiye\", \"saudi-arabia\", \"italy\", \"united-kingdom\", etc. or \"\").\n"
		. "- \"types\": Array of up to 2 product URL paths that match the court type mentioned (e.g. [\"/panoramic-padel-court/\"]).\n";

	if ($translate) {
		$prompt .= "- \"translations\": An object with keys \"tr\", \"de\", \"fr\", \"ar\", \"ru\". Each containing:\n"
			. "  - \"title\": Translated title\n"
			. "  - \"excerpt\": Translated summary\n"
			. "  - \"seo_title\": Translated SEO title\n"
			. "  - \"seo_desc\": Translated meta description\n"
			. "  - \"body\": Fully translated HTML body matching the English HTML structure\n";
	}

	return upx_gemini_call($prompt, upx_ai_system_instruction(), true, 0.4);
}

/**
 * Translates English reference content into the specified target languages.
 *
 * @param string   $title
 * @param string   $excerpt
 * @param string   $body
 * @param string[] $target_langs
 * @return array|WP_Error
 */
function upx_ai_translate_reference($title, $excerpt, $body, $target_langs = array('tr', 'de', 'fr', 'ar', 'ru')) {
	if (empty($target_langs)) {
		return array();
	}

	$langs_list = implode(', ', array_map('strtoupper', $target_langs));
	$prompt = "Translate the following Unixpadel project reference into these languages: {$langs_list}.\n\n"
		. "Source English Content:\n"
		. "Title: \"\"\"{$title}\"\"\"\n"
		. "Excerpt: \"\"\"{$excerpt}\"\"\"\n"
		. "Body HTML:\n\"\"\"{$body}\"\"\"\n\n"
		. "Instructions:\n"
		. "1. Preserve all HTML structure (<h2>, <h3>, <p>, <ul>, <li>, <strong>, <a>).\n"
		. "2. Keep padel court brand and model names natural and accurate (e.g. Panoramik Padel Kortu in TR, Panorama-Padel-Platz in DE, terrain de padel panoramique in FR, ملاعب بادل بانورامية in AR, панорамные корты для паделя in RU).\n"
		. "3. Return a JSON object with keys for each requested language code (" . implode(', ', array_map(function ($l) {
			return '"' . $l . '"';
		}, $target_langs)) . ").\n"
		. "Each language object MUST have:\n"
		. "- \"title\": Translated title\n"
		. "- \"excerpt\": Translated excerpt / summary (under 160 chars)\n"
		. "- \"seo_title\": Translated SEO title (under 60 chars)\n"
		. "- \"seo_desc\": Translated meta description (under 158 chars)\n"
		. "- \"body\": Translated HTML body\n";

	return upx_gemini_call($prompt, upx_ai_system_instruction(), true, 0.3);
}

/**
 * Heuristic country detection from text (derived from src/lib/countries.ts).
 */
function upx_ai_detect_country($text) {
	$rules = array(
		'turkiye'               => '/t[uü]rk(ey|iye)|antalya|bodrum|marmaris|[cç]e[sş]me|diyarbak[iı]r|kilyos|belek|izmir|istanbul|bosphorus|adıyaman/i',
		'germany'               => '/german|almanya|w[uü]rzburg|cologne|fsb/i',
		'austria'               => '/austria|avusturya|viyana|vienna|hagenberg/i',
		'bosnia-herzegovina'    => '/bosnia|bosna|saraybosna|sarajevo/i',
		'italy'                 => '/ital|italya|bari|vicenza|vincenza/i',
		'greece'                => '/greece|yunanistan|halkida|chalkida/i',
		'romania'               => '/romania|romanya/i',
		'denmark'               => '/denmark|danimarka/i',
		'united-kingdom'        => '/england|ingiltere|birleşik krallık|\buk\b|united kingdom/i',
		'netherlands'           => '/netherlands|hollanda|holland/i',
		'lebanon'               => '/lebanon|lübnan/i',
		'azerbaijan'            => '/azerbaijan|azerbaycan|bakü|baku/i',
		'saudi-arabia'          => '/saudi|suudi|dammam/i',
		'oman'                  => '/oman|umman/i',
		'northern-cyprus'       => '/cyprus|kıbrıs/i',
		'south-africa'          => '/south[\s-]africa|güney afrika/i',
		'usa'                   => '/\busa\b|united states| abd |amerika|virginia/i',
	);

	foreach ($rules as $slug => $re) {
		if (preg_match($re, (string) $text)) {
			return $slug;
		}
	}
	return '';
}

/**
 * Heuristic court type detection from text (derived from src/lib/references.ts).
 */
function upx_ai_detect_court_types($text) {
	$words = array(
		'/single-full-panoramic-padel-court/'        => '/single[\s-]full[\s-]panoramic/i',
		'/single-panoramic-padel-court/'             => '/single[\s-]panoramic/i',
		'/full-panoramic-padel-court/'               => '/full[\s-]panoramic/i',
		'/panaromic-plate-padel-court/'              => '/panoramic plate/i',
		'/plate-standard-padel-court/'               => '/\bplate standard\b|\bplate\b/i',
		'/panoramic-padel-court/'                    => '/panoramic/i',
		'/wp-challenge-padel-court/'                 => '/wp[\s-]challenge/i',
		'/padel-court-cover/'                        => '/padel[\s-]court[\s-]cover/i',
		'/portable-padel-court/'                     => '/portable/i',
		'/innovative-courts/flat-floor-padel-court/' => '/flat[\s-]floor/i',
		'/floating-floor-padel-court/'               => '/floating/i',
		'/mobilfold-padel-court/'                    => '/mobil\s?fold/i',
		'/standard-padel-court/'                     => '/standard (?:padel )?courts?/i',
		'/single-padel-court/'                       => '/single (?:padel )?courts?/i',
		'/padbols/'                                  => '/padbol/i',
		'/our-courts/pickleball-court/'              => '/pickleball/i',
		'/full-membrane-cover/'                      => '/membrane/i',
		'/sandwich-panel-full-covered/'              => '/sandwich[\s-]panel/i',
		'/retractable-roof-cover-system/'            => '/retractable/i',
		'/roof-top-canopy/'                          => '/roof[\s-]?top/i',
		'/half-close-canopy/'                        => '/half[\s–-]+close/i',
	);

	$covers = array(
		'/single-full-panoramic-padel-court/' => array('/single-padel-court/', '/single-panoramic-padel-court/', '/panoramic-padel-court/', '/full-panoramic-padel-court/'),
		'/single-panoramic-padel-court/'      => array('/single-padel-court/', '/panoramic-padel-court/'),
		'/full-panoramic-padel-court/'        => array('/panoramic-padel-court/'),
		'/panaromic-plate-padel-court/'       => array('/panoramic-padel-court/', '/plate-standard-padel-court/'),
		'/plate-standard-padel-court/'        => array('/standard-padel-court/'),
	);

	$hits = array();
	foreach ($words as $url => $re) {
		if (preg_match($re, (string) $text)) {
			$hits[] = $url;
		}
	}

	$filtered = array();
	foreach ($hits as $u) {
		$covered = false;
		foreach ($hits as $h) {
			if (isset($covers[$h]) && in_array($u, $covers[$h], true)) {
				$covered = true;
				break;
			}
		}
		if (!$covered && !in_array($u, $filtered, true)) {
			$filtered[] = $u;
		}
	}

	return array_slice($filtered, 0, 2);
}

/* ---------- AJAX Handlers ---------- */

/**
 * AJAX: Generate Quick Draft from short notes.
 */
function upx_ajax_ai_draft() {
	check_ajax_referer('upx_ai_action', 'nonce');
	if (!current_user_can('edit_posts')) {
		wp_send_json_error(array('message' => 'Unauthorized'), 403);
	}

	$notes     = isset($_POST['notes']) ? sanitize_textarea_field(wp_unslash($_POST['notes'])) : '';
	$kind      = isset($_POST['kind']) && $_POST['kind'] === 'news' ? 'news' : 'project';
	$translate = !empty($_POST['translate']);

	if (strlen(trim($notes)) < 3) {
		wp_send_json_error(array('message' => 'Please provide some brief notes about the reference.'), 400);
	}

	$result = upx_ai_generate_draft($notes, $kind, $translate);
	if (is_wp_error($result)) {
		wp_send_json_error(array('message' => $result->get_error_message()), 500);
	}

	// Double-check country and court type heuristics if Gemini returned empty.
	if (empty($result['country'])) {
		$result['country'] = upx_ai_detect_country($notes . ' ' . $result['title']);
	}
	if (empty($result['types'])) {
		$result['types'] = upx_ai_detect_court_types($notes . ' ' . $result['title'] . ' ' . $result['body']);
	}

	wp_send_json_success($result);
}
add_action('wp_ajax_upx_ai_draft', 'upx_ajax_ai_draft');

/**
 * AJAX: Translate current English content to TR, DE, FR, AR, RU.
 */
function upx_ajax_ai_translate() {
	check_ajax_referer('upx_ai_action', 'nonce');
	if (!current_user_can('edit_posts')) {
		wp_send_json_error(array('message' => 'Unauthorized'), 403);
	}

	$title   = isset($_POST['title']) ? sanitize_text_field(wp_unslash($_POST['title'])) : '';
	$excerpt = isset($_POST['excerpt']) ? sanitize_textarea_field(wp_unslash($_POST['excerpt'])) : '';
	$body    = isset($_POST['body']) ? wp_kses_post(wp_unslash($_POST['body'])) : '';
	$langs   = isset($_POST['langs']) && is_array($_POST['langs']) ? array_map('sanitize_key', $_POST['langs']) : UPX_REF_LANGS;
	$langs   = array_values(array_intersect($langs, UPX_REF_LANGS));

	if ($title === '' || trim(wp_strip_all_tags($body)) === '') {
		wp_send_json_error(array('message' => 'English title and text must be filled before translating.'), 400);
	}

	$result = upx_ai_translate_reference($title, $excerpt, $body, $langs);
	if (is_wp_error($result)) {
		wp_send_json_error(array('message' => $result->get_error_message()), 500);
	}

	wp_send_json_success(array('translations' => $result));
}
add_action('wp_ajax_upx_ai_translate', 'upx_ajax_ai_translate');

/**
 * AJAX: Test connection with Gemini API.
 */
function upx_ajax_ai_test() {
	check_ajax_referer('upx_ai_test', 'nonce');
	if (!current_user_can('manage_options')) {
		wp_send_json_error(array('message' => 'Unauthorized'), 403);
	}

	$key   = isset($_POST['key']) ? sanitize_text_field(wp_unslash($_POST['key'])) : '';
	$model = isset($_POST['model']) ? sanitize_text_field(wp_unslash($_POST['model'])) : '';

	if ($key !== '') {
		update_option(UPX_OPT_GEMINI_KEY, $key);
	}
	if ($model !== '') {
		update_option(UPX_OPT_GEMINI_MODEL, $model);
	}

	$test = upx_gemini_call('Say OK', '', false, 0.1);
	if (is_wp_error($test)) {
		wp_send_json_error(array('message' => $test->get_error_message()));
	}

	wp_send_json_success(array('message' => 'Connection successful! Gemini response: ' . esc_html(trim((string) $test))));
}
add_action('wp_ajax_upx_ai_test', 'upx_ajax_ai_test');

/* ---------- Hooks: Save Post Auto-Translate & Fallbacks ---------- */

/**
 * Automatically translates missing languages on Publish/Save if auto-translation is enabled.
 */
function upx_ai_save_post($post_id, $post) {
	if ($post->post_type !== UPX_REF_TYPE) {
		return;
	}
	if ((defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) || wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
		return;
	}
	if (!current_user_can('edit_post', $post_id)) {
		return;
	}

	// 1. Auto-assign Main Photo if missing but gallery has photos.
	if (!has_post_thumbnail($post_id)) {
		$meta = upx_ref_meta($post_id);
		if (!empty($meta['gallery'])) {
			foreach ($meta['gallery'] as $item) {
				if (is_int($item) && $item > 0 && wp_attachment_is_image($item)) {
					set_post_thumbnail($post_id, $item);
					break;
				}
			}
		}
	}

	// 2. Auto-detect country and court types if empty (except for Instagram-sourced drafts where admin selection is required).
	$is_ig   = get_post_meta($post_id, '_source', true) === 'instagram';
	$meta    = upx_ref_meta($post_id);
	$updated = false;
	if (!$is_ig && empty($meta['country'])) {
		$detected_country = upx_ai_detect_country($post->post_title . ' ' . $post->post_content);
		if ($detected_country !== '') {
			$meta['country'] = $detected_country;
			$updated         = true;
		}
	}
	if (!$is_ig && empty($meta['types'])) {
		$detected_types = upx_ai_detect_court_types($post->post_title . ' ' . $post->post_content);
		if (!empty($detected_types)) {
			$meta['types'] = $detected_types;
			$updated       = true;
		}
	}
	if ($updated) {
		update_post_meta($post_id, UPX_META_REF, wp_slash($meta));
	}

	// 3. Auto-translate missing languages if enabled.
	if (!upx_ai_auto_translate_enabled()) {
		return;
	}

	// Avoid re-entrancy
	static $running = false;
	if ($running) {
		return;
	}

	$en_title = trim(wp_strip_all_tags($post->post_title));
	$en_body  = trim((string) $post->post_content);
	if ($en_title === '' || trim(wp_strip_all_tags($en_body)) === '') {
		return;
	}

	$current_l10n = upx_ref_l10n($post_id);
	$missing      = array();
	foreach (UPX_REF_LANGS as $lang) {
		$f = isset($current_l10n[$lang]) && is_array($current_l10n[$lang]) ? $current_l10n[$lang] : array();
		if (empty($f['title']) || empty($f['body']) || trim(wp_strip_all_tags($f['body'])) === '') {
			$missing[] = $lang;
		}
	}

	if (empty($missing)) {
		return;
	}

	$running = true;
	$translations = upx_ai_translate_reference($en_title, $post->post_excerpt, $en_body, $missing);
	$running = false;

	if (is_array($translations) && !empty($translations)) {
		foreach ($missing as $lang) {
			if (isset($translations[$lang]) && is_array($translations[$lang])) {
				$t = $translations[$lang];
				$current_l10n[$lang] = array(
					'title'     => sanitize_text_field(isset($t['title']) ? $t['title'] : ''),
					'excerpt'   => sanitize_textarea_field(isset($t['excerpt']) ? $t['excerpt'] : ''),
					'seo_title' => sanitize_text_field(isset($t['seo_title']) ? $t['seo_title'] : ''),
					'seo_desc'  => sanitize_textarea_field(isset($t['seo_desc']) ? $t['seo_desc'] : ''),
					'body'      => wp_kses_post(isset($t['body']) ? $t['body'] : ''),
				);
			}
		}
		update_post_meta($post_id, UPX_META_L10N, wp_slash($current_l10n));
		delete_post_meta($post_id, UPX_META_HTML);
		upx_refs_mark_dirty();
	}
}
add_action('save_post_' . UPX_REF_TYPE, 'upx_ai_save_post', 25, 2);
