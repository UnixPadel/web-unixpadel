<?php
/**
 * Logo on served photos. Every copy the resize endpoint makes (includes/img-resize.php) gets assets/wm-logo.png
 * stamped into it before it is cached, so the picture a visitor saves, links to or finds in the network panel carries
 * the logo. It applies to every upload, old and new, with no bulk run: copies are made on first request.
 *
 * The uploads themselves are never changed. That keeps this reversible (bump UPX_WM_VERSION or delete uploads/upx-img
 * and the copies are made again), and it is also the limit: the original stays reachable at its own /wp-content/uploads
 * URL for anyone who works that URL out.
 *
 * Stamping is a second pass over the finished copy, with GD when it can read and write the format and Imagick
 * otherwise. Pages rely on it: in production src/lib/protect.ts draws no logo over a picture that comes from the
 * endpoint, so the two never show twice.
 */

if (!defined('ABSPATH')) {
	exit;
}

/** Version of the stamp; keep in sync with IMG_STAMP in src/lib/resize.mjs (it is in every copy's URL). */
const UPX_WM_VERSION = 1;
/** Copies narrower than this are thumbnails: a logo on them would be a smudge. */
const UPX_WM_MIN_WIDTH = 320;
/** Not photos; keep in sync with NOT_A_PHOTO in src/lib/protect.ts. */
const UPX_WM_SKIP = '#logo|flag|favicon|gravatar|avatar#i';

define('UPX_WM_LOGO', dirname(__DIR__) . '/assets/wm-logo.png');

/** 'gd' or 'imagick' when a copy of this type can be stamped here, else ''. */
function upx_wm_engine($mime) {
	$gd = array(
		'image/jpeg' => array('imagecreatefromjpeg', 'imagejpeg'),
		'image/png'  => array('imagecreatefrompng', 'imagepng'),
		'image/webp' => array('imagecreatefromwebp', 'imagewebp'),
	);
	if (!isset($gd[$mime]) || !is_file(UPX_WM_LOGO)) {
		return '';
	}
	if (function_exists('imagecreatefrompng') && function_exists($gd[$mime][0]) && function_exists($gd[$mime][1])) {
		return 'gd';
	}
	if (class_exists('Imagick')) {
		try {
			$format = strtoupper(substr($mime, 6));
			if (Imagick::queryFormats($format) && Imagick::queryFormats('PNG')) {
				return 'imagick';
			}
		} catch (Throwable $e) {
			return '';
		}
	}
	return '';
}

/** Should the copy of this upload at this width carry the logo? */
function upx_wm_wanted($rel, $w, $mime) {
	if ($w < UPX_WM_MIN_WIDTH || preg_match(UPX_WM_SKIP, basename((string) $rel)) || upx_wm_engine($mime) === '') {
		return false;
	}
	/** Filter: return false to leave one upload (or all) unstamped. */
	return (bool) apply_filters('upx_wm_stamp', true, $rel, $w);
}

/**
 * When the current stamp took effect: stamped copies older than this are made again (they are bare, or carry an
 * earlier stamp). Set on the first request after an update that changes UPX_WM_VERSION.
 */
function upx_wm_since() {
	$since = get_option('upx_wm_since');
	if (!is_array($since) || (int) ($since['v'] ?? 0) !== UPX_WM_VERSION) {
		$since = array('v' => UPX_WM_VERSION, 't' => time());
		update_option('upx_wm_since', $since, true);
	}
	return (int) $since['t'];
}

/**
 * Where the logo goes on a `$w`×`$h` picture: [x, y, width, height, opacity].
 * Photos get it in the bottom-left corner. A cut-out (transparent where the corner logo would sit) gets it in the
 * middle and fainter, on the object itself: a corner logo there would hang in empty space and crop away.
 */
function upx_wm_box($w, $h, $cutout) {
	$logo  = getimagesize(UPX_WM_LOGO);
	$ratio = $logo ? $logo[1] / $logo[0] : 0.36;
	$lw    = max(48, min(260, (int) round($w * ($cutout ? 0.22 : 0.14))));
	// Never taller than 40% of the picture (very wide strips).
	$lw    = (int) min($lw, floor($h * 0.4 / $ratio));
	$lh    = (int) round($lw * $ratio);
	if ($cutout) {
		return array((int) round(($w - $lw) / 2), (int) round(($h - $lh) / 2), $lw, $lh, 0.5);
	}
	return array((int) round($w * 0.035), $h - $lh - (int) round($h * 0.035), $lw, $lh, 0.8);
}

function upx_wm_stamp_gd($file, $mime, $quality) {
	$read = array('image/jpeg' => 'imagecreatefromjpeg', 'image/png' => 'imagecreatefrompng', 'image/webp' => 'imagecreatefromwebp');
	$im   = @$read[$mime]($file);
	$logo = @imagecreatefrompng(UPX_WM_LOGO);
	if (!$im || !$logo) {
		return false;
	}
	if (!imageistruecolor($im)) {
		imagepalettetotruecolor($im);
	}
	$w = imagesx($im);
	$h = imagesy($im);
	// GD alpha: 0 opaque … 127 transparent. Sampled where the middle of a corner logo would be.
	list($cx, $cy, $cw, $ch) = upx_wm_box($w, $h, false);
	$alpha  = (imagecolorat($im, (int) min($w - 1, $cx + $cw / 2), (int) min($h - 1, max(0, $cy + $ch / 2))) >> 24) & 0x7F;
	list($x, $y, $lw, $lh, $opacity) = upx_wm_box($w, $h, $mime !== 'image/jpeg' && $alpha > 100);
	if ($lw < 24 || $lh < 8) {
		return false;
	}

	$scaled = imagecreatetruecolor($lw, $lh);
	imagealphablending($scaled, false);
	imagesavealpha($scaled, true);
	imagecopyresampled($scaled, $logo, 0, 0, 0, 0, $lw, $lh, imagesx($logo), imagesy($logo));
	// Fade the whole logo: this filter adds to every pixel's alpha.
	imagefilter($scaled, IMG_FILTER_COLORIZE, 0, 0, 0, (int) round(127 * (1 - $opacity)));

	imagealphablending($im, true);
	imagecopy($im, $scaled, $x, $y, 0, 0, $lw, $lh);
	imagealphablending($im, false);
	imagesavealpha($im, true);

	$tmp = $file . '.wm';
	if ($mime === 'image/jpeg') {
		$ok = imagejpeg($im, $tmp, $quality);
	} elseif ($mime === 'image/webp') {
		$ok = imagewebp($im, $tmp, $quality);
	} else {
		$ok = imagepng($im, $tmp, 9);
	}
	return $ok && is_file($tmp) && filesize($tmp) > 0 && rename($tmp, $file);
}

function upx_wm_stamp_imagick($file, $quality) {
	try {
		$im   = new Imagick($file);
		$logo = new Imagick(UPX_WM_LOGO);
		list($x, $y, $lw, $lh, $opacity) = upx_wm_box($im->getImageWidth(), $im->getImageHeight(), false);
		if ($lw < 24 || $lh < 8) {
			return false;
		}
		$logo->resizeImage($lw, $lh, Imagick::FILTER_LANCZOS, 1);
		$logo->evaluateImage(Imagick::EVALUATE_MULTIPLY, $opacity, Imagick::CHANNEL_ALPHA);
		$im->compositeImage($logo, Imagick::COMPOSITE_OVER, $x, $y);
		$im->setImageCompressionQuality($quality);
		$tmp = $file . '.wm';
		$ok  = $im->writeImage($tmp) && is_file($tmp) && filesize($tmp) > 0 && rename($tmp, $file);
		$im->clear();
		$logo->clear();
		return $ok;
	} catch (Throwable $e) {
		return false;
	}
}

/** Stamp a finished copy in place, re-encoding it at `$quality`. False when it could not be stamped. */
function upx_wm_stamp($file, $mime, $quality) {
	$engine = upx_wm_engine($mime);
	$ok     = $engine === 'gd' ? upx_wm_stamp_gd($file, $mime, $quality) : ($engine === 'imagick' ? upx_wm_stamp_imagick($file, $quality) : false);
	@unlink($file . '.wm');
	return $ok;
}

/** One line for the settings page: can this server stamp the copies the pages use? */
function upx_wm_status() {
	$mime   = wp_image_editor_supports(array('mime_type' => 'image/webp')) ? 'image/webp' : 'image/jpeg';
	$engine = upx_wm_engine($mime);
	if ($engine === '') {
		return array(false, 'Logo on photos: not available on this server (neither GD nor Imagick can write ' . $mime . ', or assets/wm-logo.png is missing). Photos are served without the logo.');
	}
	return array(true, 'Logo on photos: on. Copies from ' . UPX_WM_MIN_WIDTH . ' px wide are stamped (' . ($engine === 'gd' ? 'GD' : 'Imagick') . ', ' . $mime . '); the uploads themselves are not changed.');
}
