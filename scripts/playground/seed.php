<?php
/**
 * Sample references for a local WordPress (WordPress Playground, see README "Dynamic references"): run once by
 * blueprint.json after the plugin is activated. Photos are plain generated JPEGs, never real site media.
 *
 *  - Doha: new country (Qatar), two court types, three gallery photos, Turkish translation, a link to a product page.
 *  - Vienna: English only, no country; its address changes once (the old one must 301).
 *  - A draft (never listed).
 *  - "Oman": /references/oman/ belongs to a built page, so the reference gets /references/oman-2/.
 */

if (!defined('ABSPATH') || !defined('UPX_REF_TYPE')) {
	exit('Load WordPress with the unixpadel-static plugin active first.');
}
require_once ABSPATH . 'wp-admin/includes/image.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/media.php';

// Pretty permalinks: with plain ones WordPress answers unknown paths with the home page instead of a 404.
global $wp_rewrite;
$wp_rewrite->set_permalink_structure('/%postname%/');
flush_rewrite_rules();
wp_set_current_user(1);

function upx_seed_image($name, $rgb) {
	$im = imagecreatetruecolor(1600, 1000);
	imagefill($im, 0, 0, imagecolorallocate($im, $rgb[0], $rgb[1], $rgb[2]));
	$line = imagecolorallocate($im, 255, 255, 255);
	imagesetthickness($im, 8);
	imagerectangle($im, 160, 120, 1440, 880, $line);
	imageline($im, 800, 120, 800, 880, $line);
	imageline($im, 160, 500, 1440, 500, $line);
	ob_start();
	imagejpeg($im, null, 85);
	$upload = wp_upload_bits($name . '.jpg', null, ob_get_clean());
	$id     = wp_insert_attachment(array('post_mime_type' => 'image/jpeg', 'post_title' => $name, 'post_status' => 'inherit'), $upload['file']);
	wp_update_attachment_metadata($id, wp_generate_attachment_metadata($id, $upload['file']));
	update_post_meta($id, '_wp_attachment_image_alt', ucfirst(str_replace('-', ' ', $name)));
	return $id;
}

function upx_seed_ref($post, $meta, $l10n = array(), $thumb = 0) {
	$id = wp_insert_post(array_merge(array('post_type' => UPX_REF_TYPE, 'post_status' => 'publish'), $post), true);
	if (is_wp_error($id)) {
		exit($id->get_error_message());
	}
	update_post_meta($id, UPX_META_REF, wp_slash($meta));
	update_post_meta($id, UPX_META_L10N, wp_slash($l10n));
	if ($thumb) {
		set_post_thumbnail($id, $thumb);
	}
	return $id;
}

$court   = upx_seed_image('doha-panoramic-court', array(20, 39, 78));
$gallery = array(upx_seed_image('doha-court-at-night', array(11, 19, 38)), upx_seed_image('doha-court-from-above', array(78, 107, 21)), upx_seed_image('doha-clubhouse', array(184, 74, 12)));
$inline  = upx_seed_image('doha-court-detail', array(60, 60, 60));
$inline_html = wp_get_attachment_image($inline, 'large', false, array('class' => 'aligncenter size-large wp-image-' . $inline));

upx_seed_ref(
	array(
		'post_title'   => 'Four Panoramic Padel Courts for a Club in Doha',
		'post_name'    => 'doha-panoramic-padel-club',
		'post_excerpt' => 'Unixpadel delivered four panoramic padel courts with LED lighting for a members’ club in Doha, Qatar.',
		'post_content' => "Unixpadel delivered four <a href=\"/panoramic-padel-court/\">panoramic padel courts</a> for a members’ club in Doha. The courts were installed in three weeks.\n\n<h1>The project</h1>\n\nThe club wanted courts that keep their shape in the summer heat. Every frame was galvanised and powder-coated in our factory.\n\n[caption id=\"\" align=\"aligncenter\" width=\"1024\"]" . $inline_html . " The finished court[/caption]\n\n<h2>Delivered</h2>\n<ul>\n<li>4 panoramic courts</li>\n<li>LED lighting</li>\n</ul>\n\nhttps://www.youtube.com/watch?v=dQw4w9WgXcQ",
	),
	array('country' => 'qatar', 'types' => array('/panoramic-padel-court/', '/full-panoramic-padel-court/'), 'seo_title' => '', 'seo_desc' => '', 'gallery' => array_merge($gallery, array($court))),
	array('tr' => array(
		'title'     => 'Doha’da Bir Kulübe Dört Panoramik Padel Kortu',
		'excerpt'   => 'Unixpadel, Katar’ın Doha kentindeki bir üye kulübüne LED aydınlatmalı dört panoramik padel kortu teslim etti.',
		'seo_title' => '',
		'seo_desc'  => '',
		'body'      => "Unixpadel, Doha’daki bir üye kulübüne dört <a href=\"/panoramic-padel-court/\">panoramik padel kortu</a> teslim etti.\n\n<h2>Teslim edilenler</h2>\n<ul>\n<li>4 panoramik kort</li>\n</ul>",
	)),
	$court
);

$vienna = upx_seed_ref(
	array(
		'post_title'   => 'Rooftop Padel Courts in Vienna',
		'post_name'    => 'rooftop-padel-courts-in-vienna',
		'post_date'    => gmdate('Y-m-d H:i:s', time() - DAY_IN_SECONDS),
		'post_content' => 'Two padel courts on the roof of an office building in Vienna, with a roof-top canopy against the wind and the rain.',
	),
	array('country' => '', 'types' => array('/roof-top-canopy/'), 'seo_title' => '', 'seo_desc' => '', 'gallery' => array()),
	array(),
	upx_seed_image('vienna-rooftop', array(40, 70, 120))
);
wp_update_post(array('ID' => $vienna, 'post_name' => 'vienna-rooftop-padel')); // the old address now redirects

upx_seed_ref(
	array('post_title' => 'Draft Project', 'post_status' => 'draft', 'post_content' => 'Not published yet.'),
	array('country' => 'germany', 'types' => array(), 'seo_title' => '', 'seo_desc' => '', 'gallery' => array())
);

upx_seed_ref(
	array('post_title' => 'Oman', 'post_name' => 'oman', 'post_date' => gmdate('Y-m-d H:i:s', time() - 2 * DAY_IN_SECONDS), 'post_content' => 'A second project in Oman: the address of the built Oman page is taken, so this one gets a number.'),
	array('country' => 'oman', 'types' => array('/standard-padel-court/'), 'seo_title' => '', 'seo_desc' => 'Standard padel courts in Muscat.', 'gallery' => array()),
	array(),
	upx_seed_image('muscat-courts', array(120, 90, 40))
);

upx_refs_rebuild();
echo 'Seeded ' . count(upx_refs_index()['refs']) . " published references.\n";
