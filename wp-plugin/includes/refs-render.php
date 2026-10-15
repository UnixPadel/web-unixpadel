<?php
/**
 * References written in WP admin, rendered into the shells the Astro build ships (src/lib/upx-contract.mjs):
 * the reference page (dist/[<lang>/]upx-shell/reference/index.html), the hub and home card templates, the sitemap
 * and llms.txt. Everything here is string in, string out: the reference data comes from the index refs-store.php
 * keeps, so these functions can be tested without WordPress.
 *
 * Rule: PHP never writes markup that carries design classes. It copies the built fragments and fills their tokens.
 * One strtr() pass fills every token and slot, so text from the editor is never scanned for tokens again.
 */

if (!defined('ABSPATH')) {
	exit;
}

/* ---------- Data from the build ---------- */

/** upx-shell/data.json, or null when it is missing or from another contract version (dynamic references then stay off). */
function upx_data() {
	static $data = false;
	if ($data === false) {
		$data = null;
		$file = UPX_DIST . '/upx-shell/data.json';
		if (is_file($file)) {
			$json = json_decode((string) file_get_contents($file), true);
			if (is_array($json) && isset($json['contract']) && (int) $json['contract'] === UPX_CONTRACT && !empty($json['l10n'])) {
				$data = $json;
			}
		}
	}
	return $data;
}

function upx_shell_file($lang) {
	return UPX_DIST . ($lang === 'en' ? '' : '/' . $lang) . '/upx-shell/reference/index.html';
}

/* ---------- Escaping (same as esc_attr / esc_url for these values, but usable without WordPress) ---------- */

function upx_text($s) {
	return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', false);
}

/** Absolute http(s) URLs and site-relative paths only. */
function upx_href($url) {
	$url = trim((string) $url);
	if ($url === '' || !preg_match('#^(https?://[^\s"\'<>]+|/(?!/)[^\s"\'<>]*)$#i', $url)) {
		return '#';
	}
	return upx_text($url);
}

/* ---------- Template operations (on the trusted built HTML only) ---------- */

/** if:<name> block: keep its content (markers removed) or drop the whole block. */
function upx_tpl_if($html, $name, $keep) {
	$open  = '<!--upx:' . $name . '-->';
	$close = '<!--/upx:' . $name . '-->';
	$re    = '#' . preg_quote($open, '#') . '(.*?)' . preg_quote($close, '#') . '#s';
	return preg_replace_callback($re, function ($m) use ($keep) {
		return $keep ? $m[1] : '';
	}, $html);
}

/** each:<name> block repeated $count times; every token inside gets the suffix _<index> (UPXGSRC → UPXGSRC_0). */
function upx_tpl_each($html, $name, $count) {
	$open  = '<!--upx:' . $name . '-->';
	$close = '<!--/upx:' . $name . '-->';
	$re    = '#' . preg_quote($open, '#') . '(.*?)' . preg_quote($close, '#') . '#s';
	return preg_replace_callback($re, function ($m) use ($count) {
		$out = '';
		for ($i = 0; $i < $count; $i++) {
			$out .= preg_replace('/\b(UPX[A-Z]+)\b/', '${1}_' . $i, $m[1]);
		}
		return $out;
	}, $html);
}

/** Removes the element whose only content is $token (an empty tagline, country tag, excerpt …). */
function upx_tpl_drop($html, $token) {
	return preg_replace('#<([a-z][a-z0-9]*)\b[^>]*>\s*' . preg_quote($token, '#') . '\s*</\1>#', '', $html);
}

/** Removes every <template data-upx="…"> and returns their content by name. */
// Card templates the build places next to the lists they belong to (src/lib/upx-contract.mjs TEMPLATES).
const UPX_TEMPLATES = array('hub-card', 'hub-card-featured', 'news-item', 'home-card', 'home-card-featured', 'product-card', 'product-linktile');

function upx_tpl_templates(&$html) {
	$found = array();
	$html  = preg_replace_callback('#<template\s+data-upx="([a-z-]+)"[^>]*>(.*?)</template>#s', function ($m) use (&$found) {
		if (in_array($m[1], UPX_TEMPLATES, true)) {
			$found[$m[1]] = $m[2];
		}
		return '';
	}, $html);
	return $found;
}

function upx_tpl_strip_markers($html) {
	return preg_replace('#<!--/?upx:[^>]*-->#', '', $html);
}

/* ---------- Text helpers (ports of src/lib/seo.ts and src/lib/text.mjs) ---------- */

// WordPress provides mb_strlen / mb_substr even without the mbstring extension (wp-includes/compat.php).
function upx_len($s) {
	return mb_strlen($s, 'UTF-8');
}

function upx_sub($s, $start, $length) {
	return mb_substr($s, $start, $length, 'UTF-8');
}

/** One " | Unixpadel" suffix, left off when it would push a long title past ~60 characters (seo.ts normalizeTitle). */
function upx_normalize_title($raw) {
	$t = trim((string) preg_replace('/\s+/u', ' ', (string) $raw));
	$suffix = '/\s*[-|–—]\s*(?:unix\s?padel)®?\s*$/iu';
	while (preg_match($suffix, $t)) {
		$t = (string) preg_replace($suffix, '', $t);
	}
	if ($t === '') {
		return 'Unixpadel';
	}
	if (preg_match('/unix\s?padel/i', $t)) {
		return $t;
	}
	$with = $t . ' | Unixpadel';
	return (upx_len($with) <= 60 || upx_len($t) < 40) ? $with : $t;
}

/** Cut at a word boundary with an ellipsis (seo.ts clip). */
function upx_clip($s, $max = 158) {
	$s = trim((string) $s);
	if (upx_len($s) <= $max) {
		return $s;
	}
	$cut = upx_sub($s, 0, $max - 1);
	if (preg_match('/^(.*)\s/su', $cut, $m)) {
		$cut = $m[1];
	}
	return preg_replace('/[\s,;:–-]+$/u', '', $cut) . '…';
}

/** HTML → plain text paragraphs. */
function upx_paragraphs($html) {
	$html  = preg_replace('#<(script|style)\b.*?</\1>#is', ' ', (string) $html);
	$html  = preg_replace('#</(p|div|li|h[1-6]|blockquote|figure|figcaption|tr|ul|ol)>|<br\s*/?>#i', "\n\n", $html);
	$text  = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
	$paras = array();
	foreach (preg_split('/\n\s*\n/u', $text) as $p) {
		$p = trim((string) preg_replace('/\s+/u', ' ', $p));
		if ($p !== '') {
			$paras[] = $p;
		}
	}
	return $paras;
}

/** Opening sentence of the body for a description or card excerpt (text.mjs leadText). */
function upx_lead_text($html, $max = 170) {
	foreach (upx_paragraphs($html) as $p) {
		$words = count(preg_split('/\s+/u', $p));
		if ($words < 8) {
			continue;
		}
		if (preg_match('/^.+?[.!?](?=\s+[\p{Lu}“"]|$)/u', $p, $m)) {
			$w = count(preg_split('/\s+/u', $m[0]));
			if ($w >= 8 && $w <= 30 && upx_len($m[0]) <= 180) {
				return $m[0];
			}
		}
		return upx_clip($p, $max);
	}
	return '';
}

/** "October 2026" / "Oct 2026" in the page language, from the month names the build took from ICU. */
function upx_month_year($ts, $lang, $style, $data) {
	$l = $data['l10n'][$lang];
	$m = $l['months'][$style][(int) gmdate('n', $ts) - 1];
	$y = strtr(gmdate('Y', $ts), array_combine(range(0, 9), $l['digits']));
	return str_replace(array('{m}', '{y}'), array($m, $y), $l['datePattern'][$style]);
}

function upx_iso($ts) {
	return gmdate('Y-m-d\TH:i:s.000\Z', (int) $ts);
}

/* ---------- Reference fields per language ---------- */

/** Path of a reference in a language ("/tr/references/x/"), or null when it has no page in that language. */
function upx_ref_path($ref, $lang, $data) {
	if (!isset($ref['langs'][$lang])) {
		return null;
	}
	return $data['l10n'][$lang]['hub'] . $ref['slug'] . '/';
}

/** Language whose text a card in $lang shows: its own when translated, else English. */
function upx_ref_card_lang($ref, $lang) {
	return isset($ref['langs'][$lang]) ? $lang : 'en';
}

function upx_country_label($ref, $lang, $data) {
	$slug = isset($ref['country']) ? (string) $ref['country'] : '';
	return ($slug !== '' && isset($data['l10n'][$lang]['countries'][$slug])) ? $data['l10n'][$lang]['countries'][$slug] : '';
}

/** Court types: [['label' =>, 'short' =>, 'href' =>], …] in the page language, at most limits.types. */
function upx_court_types($ref, $lang, $data) {
	$out = array();
	foreach ((array) $ref['types'] as $url) {
		if (isset($data['l10n'][$lang]['products'][$url])) {
			$out[] = $data['l10n'][$lang]['products'][$url];
		}
	}
	return array_slice($out, 0, (int) $data['limits']['types']);
}

/** src + srcset attribute pair for an image entry. */
function upx_img_attrs($img) {
	$attrs = 'src="' . upx_href($img['src']) . '"';
	if (!empty($img['srcset'])) {
		$attrs .= ' srcset="' . upx_text($img['srcset']) . '"';
	}
	return $attrs;
}

/* ---------- The reference page ---------- */

/**
 * Full HTML of a reference page.
 *
 * @param array  $ref     Index entry (refs-store.php upx_ref_entry()).
 * @param string $lang    Page language; the reference must have it.
 * @param string $body    Body HTML for that language (already sanitized, links localized).
 * @param array  $data    upx_data().
 * @param string $shell   Shell HTML of that language.
 * @param bool   $noindex Previews.
 * @param array  $refs    All published references (index order), for the "more projects" band.
 */
function upx_render_ref_page($ref, $lang, $body, $data, $shell, $noindex = false, $refs = array()) {
	$f       = $ref['langs'][$lang];
	$site    = rtrim($data['site'], '/');
	$path    = upx_ref_path($ref, $lang, $data);
	$url     = $site . $path;
	$news    = isset($ref['kind']) && $ref['kind'] === 'news';
	$country = upx_country_label($ref, $lang, $data);
	$types   = upx_court_types($ref, $lang, $data);
	$gallery = isset($ref['gallery']) ? array_values($ref['gallery']) : array();
	$image   = upx_ref_card_image($ref, $data);
	$desc    = $f['desc'];
	$tagline = $f['excerpt'] !== '' ? $f['excerpt'] : $f['seo_desc'];
	$band    = upx_band_items($ref, $refs);

	// Structure first (trusted template only).
	$h = $shell;
	$h = upx_tpl_if($h, 'if:cta-news', $news);
	$h = upx_tpl_if($h, 'if:cta-project', !$news);
	$h = upx_tpl_if($h, 'if:band', count($band) >= 3);
	$h = upx_tpl_if($h, 'if:band-d', count($band) >= 4);
	$h = upx_tpl_if($h, 'if:band-e', count($band) >= 5);
	$h = upx_tpl_if($h, 'if:country', $country !== '');
	$h = upx_tpl_if($h, 'if:date', true);
	$h = upx_tpl_if($h, 'if:types', count($types) > 0);
	$h = upx_tpl_if($h, 'if:ct2', count($types) > 1);
	$h = upx_tpl_if($h, 'if:gallery', count($gallery) > 0);
	$h = upx_tpl_if($h, 'if:gallery-nav', count($gallery) > 1);
	$h = upx_tpl_each($h, 'each:gallery-item', count($gallery));
	if ($tagline === '') {
		$h = upx_tpl_drop($h, 'UPXTAGLINE');
	}
	if ($desc === '') {
		$h = preg_replace('#<meta\b[^>]*content="UPXDESC"[^>]*>#', '', $h);
	}
	$robots = $noindex ? 'noindex, nofollow' : $data['defaults']['robots'];
	$h = str_replace('<meta name="robots" content="noindex, nofollow">', '<meta name="robots" content="' . upx_text($robots) . '">', $h);

	// Language versions: hreflang links (only with two or more) and the language switcher.
	$alternates = array();
	$switch     = array();
	foreach ($data['langs'] as $l) {
		$p = upx_ref_path($ref, $l, $data);
		if ($p !== null) {
			$alternates[$l] = $site . $p;
		}
		$switch[$l] = $p !== null ? $p : $data['l10n'][$l]['home'];
	}
	$hreflang  = '';
	$og_locale = '';
	if (count($alternates) > 1 && !$noindex) {
		foreach ($alternates as $l => $href) {
			$hreflang .= '<link rel="alternate" hreflang="' . $l . '" href="' . upx_href($href) . '">';
			if ($l !== $lang) {
				$og_locale .= '<meta property="og:locale:alternate" content="' . upx_text($data['l10n'][$l]['locale']['og']) . '">';
			}
		}
		$hreflang .= '<link rel="alternate" hreflang="x-default" href="' . upx_href($alternates['en']) . '">';
	}

	$og_image = $image['full'];
	$crumbs   = $data['l10n'][$lang]['crumbs'];
	$org      = $site . '/#organization';
	$ld       = array(
		array(
			'@context'        => 'https://schema.org',
			'@type'           => 'BreadcrumbList',
			'itemListElement' => array(
				array('@type' => 'ListItem', 'position' => 1, 'name' => $crumbs[0]['label'], 'item' => $site . $crumbs[0]['url']),
				array('@type' => 'ListItem', 'position' => 2, 'name' => $crumbs[1]['label'], 'item' => $site . $crumbs[1]['url']),
				array('@type' => 'ListItem', 'position' => 3, 'name' => $f['title'], 'item' => $url),
			),
		),
		array_filter(array(
			'@context'      => 'https://schema.org',
			'@type'         => 'Article',
			'headline'      => $f['title'],
			'description'   => $desc !== '' ? $desc : null,
			'image'         => $og_image,
			'url'           => $url,
			'datePublished' => upx_iso($ref['date']),
			'dateModified'  => upx_iso($ref['modified']),
			'author'        => array('@id' => $org),
			'publisher'     => array('@id' => $org),
		)),
	);
	$jsonld = '';
	foreach ($ld as $node) {
		$jsonld .= '<script type="application/ld+json">' . upx_json($node) . '</script>';
	}

	$map = array(
		'<!--upx:slot:hreflang-->'      => $hreflang,
		'<!--upx:slot:og-locale-alt-->' => $og_locale,
		'<!--upx:slot:jsonld-->'        => $jsonld,
		'<!--upx:slot:body-->'          => $body,
		'UPXSEOTITLE'                   => upx_text(upx_normalize_title($f['seo_title'] !== '' ? $f['seo_title'] : $f['title'])),
		'UPXDESC'                       => upx_text($desc),
		'UPXCANONICAL'                  => upx_href($url),
		'UPXOGIMAGE'                    => upx_href($og_image),
		'UPXPUBLISHED'                  => upx_iso($ref['date']),
		'UPXMODIFIED'                   => upx_iso($ref['modified']),
		'UPXEYEBROW'                    => upx_text($data['l10n'][$lang]['ui'][$news ? 'news' : 'referenceProject']),
		'UPXTITLE'                      => upx_text($f['title']),
		'UPXTAGLINE'                    => upx_text($tagline),
		'src="UPXHEROSRC"'              => upx_img_attrs($image),
		'UPXHEROALT'                    => upx_text($image['alt'] !== '' && $lang === 'en' ? $image['alt'] : $f['title']),
		'UPXCOUNTRY'                    => upx_text($country),
		'UPXDATE'                       => upx_text(upx_month_year($ref['date'], $lang, 'long', $data)),
		'UPXCTAHREF'                    => isset($types[0]) ? upx_href($types[0]['href']) : '#',
		'UPXCTALABEL'                   => isset($types[0]) ? upx_text($types[0]['label']) : '',
		'UPXCTBHREF'                    => isset($types[1]) ? upx_href($types[1]['href']) : '#',
		'UPXCTBLABEL'                   => isset($types[1]) ? upx_text($types[1]['label']) : '',
		'UPXGN'                         => (string) min(count($gallery), 5),
		'UPXGJSON'                      => upx_json(array_map(function ($g, $i) use ($lang, $data) {
			return array('src' => $g['full'], 'alt' => upx_gallery_alt($g, $i, $lang, $data), 'render' => !empty($g['render']));
		}, $gallery, array_keys($gallery))),
	);
	foreach ($data['langs'] as $l) {
		$map['UPXLANG' . strtoupper($l)] = upx_href($switch[$l]);
	}
	foreach ($gallery as $i => $g) {
		$map['UPXGINDEX_' . $i]      = (string) $i;
		$map['src="UPXGSRC_' . $i . '"'] = upx_img_attrs($g);
		$map['UPXGALT_' . $i]        = upx_text(upx_gallery_alt($g, $i, $lang, $data));
		$map['UPXGRENDER_' . $i]     = !empty($g['render']) ? 'gallery__item--render' : '';
	}
	foreach ($band as $i => $other) {
		$cl     = upx_ref_card_lang($other, $lang);
		$img    = upx_ref_card_image($other, $data);
		$label  = upx_country_label($other, $lang, $data);
		$letter = substr('ABCDE', $i, 1);
		$map['src="' . 'UPXB' . 'SRC' . $letter . '"'] = upx_img_attrs($img);
		$map['UPXB' . 'ALT' . $letter]   = upx_text($img['alt'] !== '' && $cl === 'en' ? $img['alt'] : $other['langs'][$cl]['title']);
		$map['UPXB' . 'LABEL' . $letter] = upx_text($label !== '' ? $label : $other['langs'][$cl]['title']);
		$map['UPXB' . 'HREF' . $letter]  = upx_href(upx_ref_path($other, $cl, $data));
	}
	return strtr(upx_tpl_strip_markers_except_slots($h), $map);
}

/**
 * "More projects" band under a reference: the next five projects with a photo, wrapping round the list
 * (src/lib/references.ts morePhotoBand). Three or more, or none.
 */
function upx_band_items($ref, $refs) {
	$projects = array_values(array_filter($refs, function ($r) {
		return $r['kind'] !== 'news' && !empty($r['image']);
	}));
	$at = -1;
	foreach ($projects as $i => $r) {
		if ($r['id'] === $ref['id']) {
			$at = $i;
			break;
		}
	}
	$ring = array_merge(array_slice($projects, $at + 1), array_slice($projects, 0, $at + 1));
	$ring = array_values(array_filter($ring, function ($r) use ($ref) {
		return $r['id'] !== $ref['id'];
	}));
	return array_slice($ring, 0, 5);
}

/** Alt text of a gallery image: its own (English only; translations have none), else "Image 3" in the page language. */
function upx_gallery_alt($g, $i, $lang, $data) {
	if ($lang === 'en' && $g['alt'] !== '') {
		return $g['alt'];
	}
	return str_replace('{n}', (string) ($i + 1), $data['l10n'][$lang]['ui']['galleryImage']);
}

function upx_tpl_strip_markers_except_slots($html) {
	return preg_replace('#<!--/?upx:(?!slot:)[a-z:-]+-->#', '', $html);
}

/** JSON for <script> blocks: "</script>" can't end the element early. */
function upx_json($value) {
	return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
}

/* ---------- Cards and lists on built pages (hub, home, product pages) ---------- */

/** One card from a template (hub, home or product card). */
function upx_render_card($tpl, $ref, $lang, $data) {
	$cl      = upx_ref_card_lang($ref, $lang);
	$f       = $ref['langs'][$cl];
	$country = upx_country_label($ref, $lang, $data);
	$types   = array_map(function ($t) {
		return $t['short'];
	}, upx_court_types($ref, $lang, $data));
	$image   = upx_ref_card_image($ref, $data);
	$excerpt = $f['card'];

	$h = $tpl;
	if ($country === '') {
		$h = upx_tpl_drop($h, 'UPXCCOUNTRY');
	}
	if (!$types) {
		$h = upx_tpl_drop($h, 'UPXCTYPES');
	}
	if ($excerpt === '') {
		$h = upx_tpl_drop($h, 'UPXCEXCERPT');
	}
	$h = preg_replace('#<p class="ref__meta[^"]*">\s*</p>#', '', $h);
	if ($cl !== $lang) {
		$h = preg_replace('#^(\s*<article\b)#', '$1 lang="' . $cl . '"', $h, 1);
	}
	return strtr($h, array(
		'UPXCHREF'      => upx_href(upx_ref_path($ref, $cl, $data)),
		'UPXCTITLE'     => upx_text($f['title']),
		'src="UPXCSRC"' => upx_img_attrs($image),
		'UPXCALT'       => upx_text($image['alt'] !== '' && $cl === 'en' ? $image['alt'] : $f['title']),
		'UPXCKEY'       => upx_text($ref['country'] !== '' ? $ref['country'] : 'other'),
		'UPXCCOUNTRY'   => upx_text($country),
		'UPXCTYPES'     => upx_text(implode(' · ', $types)),
		'UPXCISODATE'   => upx_iso($ref['date']),
		'UPXCDATE'      => upx_text(upx_month_year($ref['date'], $lang, 'short', $data)),
		'UPXCEXCERPT'   => upx_text($excerpt),
	));
}

/** A reference's photo, else the photo of its first court type, else the site logo (cards and the page hero). */
function upx_ref_card_image($ref, $data) {
	if (!empty($ref['image'])) {
		return $ref['image'];
	}
	foreach ((array) $ref['types'] as $url) {
		foreach ($data['products'] as $p) {
			if ($p['url'] === $url && !empty($p['image'])) {
				return array('src' => $p['image'], 'srcset' => '', 'alt' => '', 'full' => $p['image'], 'render' => false);
			}
		}
	}
	return array('src' => $data['defaults']['ogImage'], 'srcset' => '', 'alt' => '', 'full' => $data['defaults']['ogImage'], 'render' => false);
}

/** One company news item (hub list). */
function upx_render_news_item($tpl, $ref, $lang, $data) {
	$cl = upx_ref_card_lang($ref, $lang);
	$h  = $cl !== $lang ? preg_replace('#^(\s*<li\b)#', '$1 lang="' . $cl . '"', $tpl, 1) : $tpl;
	return strtr($h, array(
		'UPXNHREF'    => upx_href(upx_ref_path($ref, $cl, $data)),
		'UPXNTITLE'   => upx_text($ref['langs'][$cl]['title']),
		'UPXNISODATE' => upx_iso($ref['date']),
		'UPXNDATE'    => upx_text(upx_month_year($ref['date'], $lang, 'short', $data)),
	));
}

/**
 * Region <!--upx:$name-->…<!--/upx:$name--> of $html: [start, end (after the close marker), inner] or null.
 */
function upx_region($html, $name) {
	$open  = '<!--upx:' . $name . '-->';
	$close = '<!--/upx:' . $name . '-->';
	$a     = strpos($html, $open);
	$b     = $a === false ? false : strpos($html, $close, $a);
	if ($b === false) {
		return null;
	}
	return array($a, $b + strlen($close), substr($html, $a + strlen($open), $b - $a - strlen($open)));
}

/**
 * Built items of a region, split on their markers (<!--upx:card /url/--> or <!--upx:news /url/-->), without the ones
 * imported into WP admin: [lead, [[url, html], …]].
 */
function upx_built_items($inner, $mark, $imported) {
	$parts = preg_split('#<!--upx:' . $mark . ' (/[^ >]*)-->#', $inner, -1, PREG_SPLIT_DELIM_CAPTURE);
	$lead  = array_shift($parts);
	$items = array();
	for ($i = 0; $i + 1 < count($parts); $i += 2) {
		if (!isset($imported[$parts[$i]])) {
			$items[] = array($parts[$i], $parts[$i + 1]);
		}
	}
	return array($lead, $items);
}

/** The class and image sizes a featured card differs from a plain one by: [[featured, plain], …]. */
function upx_featured_pairs($featured_tpl, $plain_tpl) {
	$pairs = array();
	foreach (array('#<article class="([^"]*)"#', '#\ssizes="([^"]*)"#') as $re) {
		if (preg_match($re, $featured_tpl, $x) && preg_match($re, $plain_tpl, $y) && $x[1] !== $y[1]) {
			$pairs[] = array($x[0], $y[0]);
		}
	}
	return $pairs;
}

function upx_swap_first($html, $pairs, $to_plain) {
	foreach ($pairs as $p) {
		$from = $to_plain ? $p[0] : $p[1];
		$to   = $to_plain ? $p[1] : $p[0];
		$pos  = strpos($html, $from);
		if ($pos !== false) {
			$html = substr_replace($html, $to, $pos, strlen($from));
		}
	}
	return $html;
}

/** Column count of a card grid (src/lib/grid.ts columnsFor, max 3). */
function upx_columns_for($n) {
	if ($n <= 4) {
		return max(2, min($n, 4));
	}
	for ($c = 3; $c >= 2; $c--) {
		$rest = $n % $c;
		if ($rest === 0 || ($rest > 1 && $rest >= (int) ceil($c / 2))) {
			return $c;
		}
	}
	return 2;
}

/**
 * Fills a built page that lists references: the hub cards and company news, the home cards, the "installed projects"
 * of a product page. WP admin references (newest first) go in front; built items imported into WP admin are dropped;
 * the first card of a grid is the featured one. Templates and markers are always removed. Null when the page lists
 * nothing (the caller sends the file as built).
 *
 * @param array  $refs     Published references, sorted (index order).
 * @param array  $imported English URL → post ID of built references imported into WP admin.
 * @param string $page     English URL of the page (product pages: their court type).
 */
function upx_inject_listing($html, $lang, $refs, $data, $imported, $page) {
	if (strpos($html, '<!--upx:') === false) {
		return null;
	}
	$tpls     = upx_tpl_templates($html);
	$projects = array_values(array_filter($refs, function ($r) {
		return $r['kind'] !== 'news';
	}));
	$news     = array_values(array_filter($refs, function ($r) {
		return $r['kind'] === 'news';
	}));
	$dynamic  = array(); // hub/home kind → references shown in front

	// Card grids: hub (all projects) and home (limits.home in all).
	foreach (array('hub', 'home') as $kind) {
		$region = upx_region($html, $kind . '-cards');
		$feat   = $kind . '-card-featured';
		$flat   = $kind . '-card';
		if (!$region || !isset($tpls[$feat], $tpls[$flat])) {
			continue;
		}
		list($start, $end, $inner) = $region;
		list($lead, $items) = upx_built_items($inner, 'card', $imported);
		$limit = (int) $data['limits']['home'];
		$dyn   = $kind === 'home' ? array_slice($projects, 0, $limit) : $projects;
		$pairs = upx_featured_pairs($tpls[$feat], $tpls[$flat]);
		if ($items && $pairs) {
			// Exactly the first card of the grid is featured: the built one if nothing goes in front of it.
			$featured = strpos($items[0][1], $pairs[0][0]) !== false;
			if ($dyn && $featured) {
				$items[0][1] = upx_swap_first($items[0][1], $pairs, true);
			} elseif (!$dyn && !$featured) {
				$items[0][1] = upx_swap_first($items[0][1], $pairs, false);
			}
		}
		if ($kind === 'home') {
			$items = array_slice($items, 0, max(0, $limit - count($dyn)));
		}
		$cards = '';
		foreach ($dyn as $i => $ref) {
			$cards .= upx_render_card($i === 0 ? $tpls[$feat] : $tpls[$flat], $ref, $lang, $data);
		}
		foreach ($items as $it) {
			$cards .= $it[1];
		}
		$html = substr($html, 0, $start) . $lead . $cards . substr($html, $end);
		$dynamic[$kind] = $dyn;
	}

	// Company news (hub): WP admin news first, then the built ones not imported; hidden when there is none.
	$section = upx_region($html, 'news-section');
	$list    = upx_region($html, 'news-items');
	if ($section && $list && isset($tpls['news-item'])) {
		list($start, $end, $inner) = $list;
		list($lead, $items) = upx_built_items($inner, 'news', $imported);
		$out = $lead;
		foreach ($news as $ref) {
			$out .= upx_render_news_item($tpls['news-item'], $ref, $lang, $data);
		}
		foreach ($items as $it) {
			$out .= $it[1];
		}
		$html    = substr($html, 0, $start) . $out . substr($html, $end);
		$section = upx_region($html, 'news-section');
		$body    = $section[2];
		if ($news || $items) {
			$body = preg_replace('#^(\s*<section\b[^>]*?)\shidden(?:="[^"]*")?#', '$1', $body, 1);
		} else {
			$body = '';
		}
		$html = substr($html, 0, $section[0]) . $body . substr($html, $section[1]);
		$dynamic['news'] = $news;
	}

	// Installed projects on a product page: references with this court type, then the built ones not imported.
	$section = upx_region($html, 'product-refs');
	$cards   = upx_region($html, 'product-cards');
	if ($section && $cards && isset($tpls['product-card'])) {
		$limit = 3;
		$dyn   = array_slice(array_values(array_filter($projects, function ($r) use ($page) {
			return in_array($page, (array) $r['types'], true);
		})), 0, $limit);
		list($start, $end, $inner) = $cards;
		list($lead, $items) = upx_built_items($inner, 'card', $imported);
		$items = array_slice($items, 0, max(0, $limit - count($dyn)));
		$out   = $lead;
		foreach ($dyn as $ref) {
			$out .= upx_render_card($tpls['product-card'], $ref, $lang, $data);
		}
		foreach ($items as $it) {
			$out .= $it[1];
		}
		$html  = substr($html, 0, $start) . $out . substr($html, $end);
		$total = count($dyn) + count($items);
		$tile  = upx_region($html, 'product-tile');
		if ($tile) {
			$html = substr($html, 0, $tile[0]) . ($total === 1 && isset($tpls['product-linktile']) ? $tpls['product-linktile'] : '') . substr($html, $tile[1]);
		}
		$section = upx_region($html, 'product-refs');
		$body    = $section[2];
		if ($total > 0) {
			$body = preg_replace('#^(\s*<section\b[^>]*?)\shidden(?:="[^"]*")?#', '$1', $body, 1);
			$body = preg_replace('#style="--cols:\d"#', 'style="--cols:' . upx_columns_for($total) . '"', $body, 1);
		} else {
			$body = '';
		}
		$html = substr($html, 0, $section[0]) . $body . substr($html, $section[1]);
	}

	if (isset($dynamic['hub'])) {
		$html = upx_inject_collection_ld($html, array_merge($dynamic['hub'], isset($dynamic['news']) ? $dynamic['news'] : array()), $lang, $data, $imported);
	}
	return upx_tpl_strip_markers($html);
}

/** Hub CollectionPage JSON-LD: WP admin references first, imported built ones out. Left alone if it can't be read. */
function upx_inject_collection_ld($html, $refs, $lang, $data, $imported) {
	$site = rtrim($data['site'], '/');
	return preg_replace_callback('#(<script type="application/ld\+json">)(.*?)(</script>)#s', function ($m) use ($refs, $lang, $data, $imported, $site) {
		$node = json_decode($m[2], true);
		if (!is_array($node) || !isset($node['@type']) || $node['@type'] !== 'CollectionPage' || !isset($node['mainEntity']['itemListElement']) || !is_array($node['mainEntity']['itemListElement'])) {
			return $m[0];
		}
		$items = array();
		foreach ($refs as $ref) {
			$cl      = upx_ref_card_lang($ref, $lang);
			$items[] = array('@type' => 'ListItem', 'name' => $ref['langs'][$cl]['title'], 'url' => $site . upx_ref_path($ref, $cl, $data));
		}
		foreach ($node['mainEntity']['itemListElement'] as $item) {
			$path = isset($item['url']) ? upx_english_path((string) parse_url($item['url'], PHP_URL_PATH)) : '';
			if (!isset($imported[$path])) {
				$items[] = $item;
			}
		}
		foreach ($items as $i => &$item) {
			$item['position'] = $i + 1;
		}
		unset($item);
		$node['mainEntity']['itemListElement'] = $items;
		$node['mainEntity']['numberOfItems']   = count($items);
		return $m[1] . upx_json($node) . $m[3];
	}, $html);
}

/** "/tr/references/x/" → "/references/x/": the English URL every language version shares. */
function upx_english_path($path) {
	$path = (string) preg_replace('#^/(tr|de|fr|ar|ru)(?=/)#', '', $path);
	return $path === '' ? '/' : $path;
}

/* ---------- Sitemap and llms.txt ---------- */

function upx_inject_sitemap_index($xml, $refs, $data) {
	if (!$refs || strpos($xml, '</sitemapindex>') === false) {
		return $xml;
	}
	$last = 0;
	foreach ($refs as $ref) {
		$last = max($last, (int) $ref['modified']);
	}
	$entry = '<sitemap><loc>' . upx_text(rtrim($data['site'], '/') . '/sitemap-references.xml') . '</loc><lastmod>' . upx_iso($last) . '</lastmod></sitemap>';
	return str_replace('</sitemapindex>', $entry . '</sitemapindex>', $xml);
}

/** The build's sitemap without the pages of references imported into WP admin (sitemap-references.xml lists them). */
function upx_filter_sitemap($xml, $imported) {
	if (!$imported) {
		return $xml;
	}
	return preg_replace_callback('#<url>\s*<loc>([^<]+)</loc>.*?</url>#s', function ($m) use ($imported) {
		return isset($imported[upx_english_path((string) parse_url(html_entity_decode($m[1]), PHP_URL_PATH))]) ? '' : $m[0];
	}, $xml);
}

/** Every language version of every reference, with the same alternates as the page head (astro.config.mjs serialize). */
function upx_render_sitemap($refs, $data) {
	$site = rtrim($data['site'], '/');
	$out  = '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">';
	foreach ($refs as $ref) {
		$alts = array();
		foreach ($data['langs'] as $l) {
			$p = upx_ref_path($ref, $l, $data);
			if ($p !== null) {
				$alts[$l] = $site . $p;
			}
		}
		$links = '';
		if (count($alts) > 1) {
			foreach ($alts as $l => $href) {
				$links .= '<xhtml:link rel="alternate" hreflang="' . $l . '" href="' . upx_text($href) . '"/>';
			}
			$links .= '<xhtml:link rel="alternate" hreflang="x-default" href="' . upx_text($alts['en']) . '"/>';
		}
		foreach ($alts as $href) {
			$out .= '<url><loc>' . upx_text($href) . '</loc><lastmod>' . upx_iso($ref['modified']) . '</lastmod>' . $links . '</url>';
		}
	}
	return $out . '</urlset>';
}

/**
 * llms.txt references block: WP admin projects first, built lines of imported references out, limits.llms lines.
 */
function upx_inject_llms($text, $refs, $data, $imported = array()) {
	$site  = rtrim($data['site'], '/');
	$lines = explode("\n", $text);
	$hub   = null;
	foreach ($lines as $i => $line) {
		if (strpos($line, '](' . $site . '/references/)') !== false) {
			$hub = $i;
			break;
		}
	}
	if ($hub === null) {
		return $text;
	}
	$end = $hub + 1;
	while ($end < count($lines) && strpos($lines[$end], '- ') === 0) {
		$end++;
	}
	$max   = (int) $data['limits']['llms'];
	$clean = function ($s) {
		return trim(str_replace(array("\r", "\n", '[', ']'), array(' ', ' ', '(', ')'), $s));
	};
	$added = array();
	foreach ($refs as $ref) {
		if ($ref['kind'] === 'news' || count($added) >= $max) {
			continue;
		}
		$f       = $ref['langs']['en'];
		$added[] = '- [' . $clean($f['title']) . '](' . $site . upx_ref_path($ref, 'en', $data) . ')' . ($f['desc'] !== '' ? ': ' . $clean($f['desc']) : '');
	}
	$kept = array();
	foreach (array_slice($lines, $hub + 1, $end - $hub - 1) as $line) {
		if (preg_match('#\]\(' . preg_quote($site, '#') . '(/[^)]*)\)#', $line, $m) && isset($imported[$m[1]])) {
			continue;
		}
		$kept[] = $line;
	}
	$kept = array_slice($kept, 0, max(0, $max - count($added)));
	array_splice($lines, $hub + 1, $end - $hub - 1, array_merge($added, $kept));
	return implode("\n", $lines);
}

/* ---------- Body HTML ---------- */

/**
 * Internal links of a translated page point at that language's page when it exists (src/middleware.ts localizeHtml):
 * $translated = English paths that have a page in $lang (data.json plus references with that translation).
 */
function upx_localize_links($html, $lang, $translated, $site) {
	if ($lang === 'en') {
		return $html;
	}
	$set    = array_flip($translated);
	$origin = '#^' . preg_quote(rtrim($site, '/'), '#') . '(?=/)#';
	return preg_replace_callback('#<a\b[^>]*>#i', function ($m) use ($lang, $set, $origin) {
		$tag = $m[0];
		if (preg_match('#\s(hreflang|data-no-localize)\b#i', $tag) || !preg_match('#\shref="([^"]*)"#i', $tag, $h)) {
			return $tag;
		}
		$href = preg_replace($origin, '', $h[1]);
		if ($href === '' || $href[0] !== '/' || strpos($href, '//') === 0
			|| preg_match('#^/(tr|de|fr|ar|ru)(/|$)#', $href)
			|| preg_match('#^/(wp-(content|admin|includes|json)/|_astro/|sitemap|robots\.txt|llms\.txt|favicon)#', $href)) {
			return $tag;
		}
		$key = preg_replace('/[?#].*$/', '', $href);
		if (!isset($set[$key])) {
			return $tag;
		}
		return str_replace($h[0], ' href="/' . $lang . $href . '"', $tag);
	}, $html);
}
