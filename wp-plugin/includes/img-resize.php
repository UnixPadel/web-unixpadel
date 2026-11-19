<?php
/**
 * Resized media: `/?upx_img=<path under uploads>&w=<width>` returns a cached copy of an upload at that width
 * (WebP when the image editor supports it, never upscaled). The built pages use it in every srcset
 * (src/lib/resize.mjs), so a 48px menu thumbnail no longer downloads an 8 MB original.
 *
 * Copies live in uploads/upx-img/<w>/<path>.<ext> and are made once, on the first request; later requests
 * stream the file with a one-year immutable cache header. Served through PHP on purpose: the host's web server
 * may not hand missing /wp-content files to WordPress, while a query on the home URL always reaches it.
 */

if (!defined('ABSPATH')) {
	exit;
}

/** Widths the endpoint accepts; keep in sync with IMG_WIDTHS in src/lib/resize.mjs. */
const UPX_IMG_WIDTHS = array(96, 160, 320, 480, 640, 960, 1280, 1600, 1920);
const UPX_IMG_CONTENT_WIDTHS = array(320, 480, 640, 960, 1280, 1600, 1920);

/** Absolute path of an upload from its uploads-relative path, or null when it is not a resizable upload. */
function upx_img_source($rel) {
	$rel = ltrim(str_replace('\\', '/', (string) $rel), '/');
	if ($rel === '' || strpos($rel, '..') !== false || !preg_match('#\.(jpe?g|png|webp)$#i', $rel) || strpos($rel, 'upx-img/') === 0) {
		return null;
	}
	$base = wp_get_upload_dir()['basedir'];
	$real = realpath($base . '/' . $rel);
	$root = realpath($base);
	if (!$real || !$root || strpos(str_replace('\\', '/', $real), str_replace('\\', '/', $root) . '/') !== 0 || !is_file($real)) {
		return null;
	}
	return $real;
}

/** Build (once) and return [file, mime] of the copy at `$w`, or null when the editor cannot handle the source. */
function upx_img_copy($rel, $src, $w) {
	$webp  = wp_image_editor_supports(array('mime_type' => 'image/webp'));
	$ext   = $webp ? 'webp' : strtolower(pathinfo($src, PATHINFO_EXTENSION));
	$mime  = $webp ? 'image/webp' : wp_check_filetype($src)['type'];
	$out   = wp_get_upload_dir()['basedir'] . '/upx-img/' . $w . '/' . preg_replace('#\.[a-z0-9]+$#i', '', $rel) . '.' . $ext;

	if (is_file($out) && filemtime($out) >= filemtime($src)) {
		return array($out, $mime);
	}
	wp_mkdir_p(dirname($out));
	// One request builds a copy; parallel ones for the same copy wait for it instead of decoding the original again.
	$lock_path = $out . '.lock';
	$lock      = fopen($lock_path, 'c');
	if ($lock) {
		flock($lock, LOCK_EX);
	}
	clearstatcache(true, $out);
	if (!is_file($out) || filemtime($out) < filemtime($src)) {
		wp_raise_memory_limit('image');
		$editor = wp_get_image_editor($src);
		if (is_wp_error($editor)) {
			$out = null;
		} else {
			$size = $editor->get_size();
			if (!empty($size['width']) && $size['width'] > $w) {
				$editor->resize($w, null, false);
			}
			$editor->set_quality(78);
			$saved = $editor->save($out . '.tmp', $mime);
			if (is_wp_error($saved) || !is_file($saved['path'])) {
				$out = null;
			} else {
				rename($saved['path'], $out);
			}
		}
	}
	if ($lock) {
		flock($lock, LOCK_UN);
		fclose($lock);
		@unlink($lock_path);
	}
	return $out ? array($out, $mime) : null;
}

function upx_img_serve() {
	if (!isset($_GET['upx_img'])) {
		return;
	}
	$rel = wp_unslash((string) $_GET['upx_img']);
	$w   = isset($_GET['w']) ? (int) $_GET['w'] : 0;
	$src = upx_img_source($rel);
	if (!$src || !in_array($w, UPX_IMG_WIDTHS, true)) {
		status_header(404);
		nocache_headers();
		exit;
	}
	$rel  = ltrim(str_replace('\\', '/', $rel), '/');
	$copy = upx_img_copy($rel, $src, $w);
	if (!$copy) {
		// The editor cannot read it: send the visitor to the original rather than a broken image.
		wp_safe_redirect(wp_get_upload_dir()['baseurl'] . '/' . $rel, 302);
		exit;
	}
	list($file, $mime) = $copy;
	$mtime = filemtime($file);
	$etag  = '"' . md5($file . $mtime . filesize($file)) . '"';
	header('Cache-Control: public, max-age=31536000, immutable');
	header('ETag: ' . $etag);
	header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
	header('X-Content-Type-Options: nosniff');
	if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim((string) $_SERVER['HTTP_IF_NONE_MATCH']) === $etag) {
		status_header(304);
		exit;
	}
	status_header(200);
	header('Content-Type: ' . $mime);
	header('Content-Length: ' . filesize($file));
	if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') {
		readfile($file);
	}
	exit;
}
// Before the static-page router (upx_maybe_serve, init 0) claims "/".
add_action('init', 'upx_img_serve', -10);

/** srcset through the endpoint for an upload URL or path; '' when it is not a resizable upload. */
function upx_img_srcset($url) {
	$path = (string) parse_url((string) $url, PHP_URL_PATH);
	if (!preg_match('#/wp-content/uploads/(.+)$#', $path, $m) || !upx_img_source(rawurldecode($m[1]))) {
		return '';
	}
	$origin = defined('UPX_MEDIA_ORIGIN') ? UPX_MEDIA_ORIGIN : home_url();
	$parts  = array();
	foreach (UPX_IMG_CONTENT_WIDTHS as $w) {
		$parts[] = rtrim($origin, '/') . '/?upx_img=' . str_replace('%2F', '/', rawurlencode(rawurldecode($m[1]))) . '&w=' . $w . ' ' . $w . 'w';
	}
	return implode(', ', $parts);
}
