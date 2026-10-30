<?php
/**
 * Isolated Instagram Public Post Extractor (Mode A).
 *
 * HARD RULES:
 * - No Instagram/Meta Graph API, Basic Display, oEmbed, or access tokens.
 * - No login, cookies, credentials, third-party scraping services, or headless browsers.
 * - Only anonymous public HTTP GET requests for the single post URL pasted by the admin.
 */

if (!defined('ABSPATH')) {
	exit;
}

class UPX_Instagram_Post_Extractor {

	const URL_REGEX = '~^https?://(?:www\.)?(?:instagram\.com|instagr\.am)/(?:(?!p/|reel/|tv/)[A-Za-z0-9_.]+/)?(p|reel|tv)/([A-Za-z0-9_-]+)~i';
	const CACHE_TTL = 600; // 10 minutes

	/**
	 * Validates and normalizes an Instagram post/reel/tv URL.
	 *
	 * @param string $raw_url
	 * @return array|WP_Error { shortcode: string, kind: string, permalink: string }
	 */
	public static function normalize_url($raw_url) {
		$raw_url = trim((string) $raw_url);
		if ($raw_url === '') {
			return new WP_Error('upx_ig_empty_url', 'Please paste an Instagram post URL.');
		}
		if (!preg_match('~^https?://~i', $raw_url) && preg_match('~^(?:www\.)?(?:instagram\.com|instagr\.am)/~i', $raw_url)) {
			$raw_url = 'https://' . $raw_url;
		}
		if (!preg_match(self::URL_REGEX, $raw_url, $m)) {
			return new WP_Error(
				'upx_ig_invalid_url',
				'Invalid Instagram URL. Expected a post or reel link such as https://www.instagram.com/p/SHORTCODE/.'
			);
		}
		$kind      = strtolower($m[1]);
		$shortcode = $m[2];
		return array(
			'shortcode' => $shortcode,
			'kind'      => $kind,
			'permalink' => 'https://www.instagram.com/p/' . $shortcode . '/',
		);
	}

	/**
	 * Extracts caption and images for a given Instagram URL or shortcode.
	 *
	 * @param string $input         URL or shortcode.
	 * @param bool   $force_refresh Bypass transient cache (used when CDN URLs expire).
	 * @return array Normalized result array. Never throws a fatal error.
	 */
	public static function extract($input, $force_refresh = false) {
		$norm = self::normalize_url($input);
		if (is_wp_error($norm)) {
			if (preg_match('/^[A-Za-z0-9_-]{5,32}$/', trim((string) $input))) {
				$shortcode = trim((string) $input);
				$norm      = array(
					'shortcode' => $shortcode,
					'kind'      => 'p',
					'permalink' => 'https://www.instagram.com/p/' . $shortcode . '/',
				);
			} else {
				return array(
					'status'        => 'failed',
					'reason'        => $norm->get_error_message(),
					'shortcode'     => '',
					'permalink'     => '',
					'caption'       => '',
					'images'        => array(),
					'media_type'    => 'unknown',
					'location_name' => '',
					'username'      => '',
				);
			}
		}

		$shortcode = $norm['shortcode'];
		$cache_key = 'upx_ig_ext_' . md5($shortcode);

		if (!$force_refresh) {
			$cached = get_transient($cache_key);
			if (is_array($cached) && isset($cached['status'])) {
				return $cached;
			}
		}

		$merged = array(
			'status'        => 'failed',
			'reason'        => '',
			'shortcode'     => $shortcode,
			'permalink'     => $norm['permalink'],
			'caption'       => '',
			'images'        => array(),
			'media_type'    => 'unknown',
			'location_name' => '',
			'username'      => '',
			'single_only'   => false,
		);

		$reasons = array();

		// Strategy 1: /p/{code}/embed/captioned/
		$s1 = self::strategy_embed_captioned($shortcode);
		self::log_debug('embed_captioned', $s1['http_code'], $s1['reason']);
		if (!empty($s1['reason'])) {
			$reasons[] = $s1['reason'];
		}
		$merged = self::merge_results($merged, $s1);

		// Stop early if Strategy 1 produced both caption and full media set (not single-image fallback on a carousel).
		$is_complete_s1 = ($merged['caption'] !== '' && !empty($merged['images']) && empty($s1['single_only']));

		// Strategy 2: /p/{code}/ (direct post page with OpenGraph & JSON-LD)
		if (!$is_complete_s1) {
			$s2 = self::strategy_direct_page($shortcode);
			self::log_debug('direct_page', $s2['http_code'], $s2['reason']);
			if (!empty($s2['reason'])) {
				$reasons[] = $s2['reason'];
			}
			$merged = self::merge_results($merged, $s2);
		}

		// Determine final status and human-readable reason.
		$has_caption = $merged['caption'] !== '';
		$img_count   = count($merged['images']);
		$has_videos  = false;
		foreach ($merged['images'] as $img) {
			if (!empty($img['is_video'])) {
				$has_videos = true;
				break;
			}
		}

		if ($has_caption && $img_count > 0 && !$merged['single_only']) {
			$merged['status'] = 'complete';
			$merged['reason'] = sprintf(
				'Caption and %d %s fetched from Instagram.%s',
				$img_count,
				$img_count === 1 ? 'image' : 'images',
				$has_videos ? ' Note: video items include thumbnail only — upload a photo if needed.' : ''
			);
		} elseif ($has_caption || $img_count > 0) {
			$merged['status'] = 'partial';
			$parts            = array();
			if ($has_caption && $img_count === 0) {
				$parts[] = 'Caption fetched, but no images could be extracted — please upload the photos below.';
			} elseif (!$has_caption && $img_count > 0) {
				$parts[] = sprintf('%d %s fetched, but the caption could not be read — please paste the caption below.', $img_count, $img_count === 1 ? 'image' : 'images');
			} elseif ($has_caption && $merged['single_only']) {
				$parts[] = 'Caption fetched. Only the first image could be fetched — upload any remaining carousel photos if needed.';
			}
			if ($has_videos) {
				$parts[] = 'Video item detected (thumbnail only — upload a photo if needed).';
			}
			$merged['reason'] = implode(' ', $parts);
		} else {
			$merged['status'] = 'failed';
			$unique_reasons   = array_values(array_unique(array_filter($reasons)));
			$detail           = !empty($unique_reasons) ? ' (' . implode('; ', $unique_reasons) . ')' : '';
			$merged['reason'] = 'Instagram blocked or did not return post data for this link' . $detail . '. Switched to manual mode — please paste the caption and upload the photos below.';
		}

		unset($merged['single_only']);

		if ($merged['status'] !== 'failed') {
			set_transient($cache_key, $merged, self::CACHE_TTL);
		}

		return $merged;
	}

	/**
	 * Performs an anonymous HTTP GET with realistic desktop headers and login-wall detection.
	 *
	 * @param string $url
	 * @return array { ok: bool, code: int, html: string, final_url: string, error: string }
	 */
	protected static function http_get($url) {
		$res = wp_remote_get($url, array(
			'timeout'     => 15,
			'redirection' => 3,
			'cookies'     => array(),
			'headers'     => array(
				'User-Agent'                => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
				'Accept'                    => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
				'Accept-Language'           => 'en-US,en;q=0.9',
				'Upgrade-Insecure-Requests' => '1',
			),
		));

		if (is_wp_error($res)) {
			return array(
				'ok'        => false,
				'code'      => 0,
				'html'      => '',
				'final_url' => $url,
				'error'     => 'HTTP request failed: ' . $res->get_error_message(),
			);
		}

		$code      = (int) wp_remote_retrieve_response_code($res);
		$html      = (string) wp_remote_retrieve_body($res);
		$final_url = $url;
		if (isset($res['http_response']) && is_object($res['http_response']) && method_exists($res['http_response'], 'get_response_object')) {
			$raw_resp = $res['http_response']->get_response_object();
			if (isset($raw_resp->url)) {
				$final_url = (string) $raw_resp->url;
			}
		}

		if ($code !== 200) {
			return array(
				'ok'        => false,
				'code'      => $code,
				'html'      => '',
				'final_url' => $final_url,
				'error'     => 'HTTP status ' . $code,
			);
		}

		// Detect login wall or Polaris error page shell (Instagram returns 200 with PolarisErrorRoot for unavailable/restricted posts).
		if (
			stripos($final_url, '/accounts/login') !== false ||
			stripos($html, '"pageID":"httpErrorPage"') !== false ||
			stripos($html, 'PolarisErrorRoot.entrypoint') !== false ||
			stripos($html, '"show_lox_redesigned_404_page":true') !== false ||
			preg_match('/<title[^>]*>\s*Login\s*[•·-]\s*Instagram/i', $html)
		) {
			$is_error_page = stripos($html, 'httpErrorPage') !== false || stripos($html, 'PolarisErrorRoot') !== false;
			return array(
				'ok'        => false,
				'code'      => $code,
				'html'      => '',
				'final_url' => $final_url,
				'error'     => $is_error_page ? 'post unavailable or embed restricted by Instagram' : 'redirected to Instagram login wall',
			);
		}

		return array(
			'ok'        => true,
			'code'      => $code,
			'html'      => $html,
			'final_url' => $final_url,
			'error'     => '',
		);
	}

	/**
	 * Strategy 1: GET https://www.instagram.com/p/{code}/embed/captioned/
	 * Parses .Caption HTML and searches embedded <script> JSON recursively for carousel children & resources.
	 *
	 * @param string $shortcode
	 * @return array
	 */
	protected static function strategy_embed_captioned($shortcode) {
		$url  = 'https://www.instagram.com/p/' . rawurlencode($shortcode) . '/embed/captioned/';
		$resp = self::http_get($url);
		$out  = array(
			'http_code'     => $resp['code'],
			'reason'        => $resp['error'],
			'caption'       => '',
			'images'        => array(),
			'media_type'    => 'unknown',
			'location_name' => '',
			'username'      => '',
			'single_only'   => false,
		);

		if (!$resp['ok'] || $resp['html'] === '') {
			return $out;
		}

		$html = $resp['html'];

		// 1. Extract username & location from embed header if present.
		if (preg_match('/class="[^"]*\bHeaderText\b[^"]*"[\s\S]*?<span[^>]*class="[^"]*\bUsernameText\b[^"]*"[^>]*>([\s\S]*?)<\/span>/i', $html, $um)) {
			$out['username'] = trim(wp_strip_all_tags(self::decode_text($um[1])));
		}
		if (preg_match('/class="[^"]*\bLocation\b[^"]*"[^>]*>([\s\S]*?)<\/a>/i', $html, $lm)) {
			$out['location_name'] = trim(wp_strip_all_tags(self::decode_text($lm[1])));
		}

		// 2. Parse .Caption block.
		if (preg_match('/<div[^>]+class="[^"]*\bCaption\b[^"]*"[^>]*>([\s\S]*?)<\/div>/i', $html, $cm)) {
			$cap_html = $cm[1];
			// Remove leading username link inside .Caption.
			$cap_html = preg_replace('/<a[^>]+class="[^"]*\bCaptionUsername\b[^"]*"[^>]*>[\s\S]*?<\/a>/i', '', $cap_html, 1);
			// Remove trailing "View more on Instagram" comment section.
			$cap_html = preg_replace('/<div[^>]+class="[^"]*\bCaptionComments\b[^"]*"[\s\S]*$/i', '', $cap_html);
			$cap_html = preg_replace('/<br\s*\/?>/i', "\n", $cap_html);
			$out['caption'] = trim(wp_strip_all_tags(self::decode_text($cap_html)));
		}

		// 3. Parse embedded JSON in <script> tags (contextJSON or inline JSON payloads).
		$json_objects = self::extract_embedded_json_objects($html);
		foreach ($json_objects as $obj) {
			$parsed_media = self::extract_media_from_json_tree($obj);
			if ($parsed_media['caption'] !== '' && $out['caption'] === '') {
				$out['caption'] = $parsed_media['caption'];
			}
			if ($parsed_media['username'] !== '' && $out['username'] === '') {
				$out['username'] = $parsed_media['username'];
			}
			if ($parsed_media['location_name'] !== '' && $out['location_name'] === '') {
				$out['location_name'] = $parsed_media['location_name'];
			}
			if (!empty($parsed_media['images'])) {
				$out['images']     = $parsed_media['images'];
				$out['media_type'] = $parsed_media['media_type'];
				break;
			}
		}

		// 4. Fallback to .EmbeddedMediaImage in HTML if script JSON didn't yield images.
		if (empty($out['images'])) {
			$is_carousel = (stripos($html, ' Sidecar') !== false || stripos($html, 'Carousel') !== false || stripos($html, 'EmbedSidecar') !== false);
			$is_video    = (stripos($html, 'EmbeddedMediaVideo') !== false || stripos($html, 'class="Video"') !== false);
			if (preg_match_all('/<img[^>]+class="[^"]*\bEmbeddedMediaImage\b[^"]*"[^>]+src="([^"]+)"/i', $html, $im) ||
				preg_match_all('/<img[^>]+src="([^"]+)"[^>]+class="[^"]*\bEmbeddedMediaImage\b[^"]*"/i', $html, $im)) {
				foreach ($im[1] as $raw_src) {
					$clean_url = html_entity_decode($raw_src, ENT_QUOTES | ENT_HTML5, 'UTF-8');
					if (strpos($clean_url, 'http') === 0) {
						$out['images'][] = array(
							'url'      => $clean_url,
							'width'    => 1080,
							'height'   => 1080,
							'is_video' => $is_video,
						);
					}
				}
				if (!empty($out['images'])) {
					$out['media_type']  = $is_carousel ? 'carousel' : ($is_video ? 'video' : 'image');
					$out['single_only'] = $is_carousel && count($out['images']) === 1;
				}
			}
		}

		if ($out['caption'] === '' && empty($out['images'])) {
			$out['reason'] = 'embed page contained no caption or media markers';
		}

		return $out;
	}

	/**
	 * Strategy 2: GET https://www.instagram.com/p/{code}/
	 * Parses OpenGraph meta tags (og:description, og:image) and JSON-LD if present.
	 *
	 * @param string $shortcode
	 * @return array
	 */
	protected static function strategy_direct_page($shortcode) {
		$url  = 'https://www.instagram.com/p/' . rawurlencode($shortcode) . '/';
		$resp = self::http_get($url);
		$out  = array(
			'http_code'     => $resp['code'],
			'reason'        => $resp['error'],
			'caption'       => '',
			'images'        => array(),
			'media_type'    => 'unknown',
			'location_name' => '',
			'username'      => '',
			'single_only'   => false,
		);

		if (!$resp['ok'] || $resp['html'] === '') {
			return $out;
		}

		$html = $resp['html'];

		// 1. Check JSON-LD scripts if present.
		if (preg_match_all('/<script[^>]+type=["\']application\/ld\+json["\'][^>]*>([\s\S]*?)<\/script>/i', $html, $ld_matches)) {
			foreach ($ld_matches[1] as $ld_raw) {
				$ld = json_decode(trim($ld_raw), true);
				if (!is_array($ld)) {
					continue;
				}
				if (isset($ld['articleBody']) && is_string($ld['articleBody']) && $out['caption'] === '') {
					$out['caption'] = trim(self::decode_text($ld['articleBody']));
				} elseif (isset($ld['caption']) && is_string($ld['caption']) && $out['caption'] === '') {
					$out['caption'] = trim(self::decode_text($ld['caption']));
				}
				if (isset($ld['author']['alternateName']) && is_string($ld['author']['alternateName'])) {
					$out['username'] = ltrim(trim($ld['author']['alternateName']), '@');
				}
				if (isset($ld['contentLocation']['name']) && is_string($ld['contentLocation']['name'])) {
					$out['location_name'] = trim(self::decode_text($ld['contentLocation']['name']));
				}
				if (isset($ld['image'])) {
					$imgs = is_array($ld['image']) ? $ld['image'] : array($ld['image']);
					foreach ($imgs as $item) {
						$u = is_array($item) && isset($item['url']) ? $item['url'] : (is_string($item) ? $item : '');
						if (is_string($u) && strpos($u, 'http') === 0) {
							$out['images'][] = array(
								'url'      => html_entity_decode($u, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
								'width'    => isset($item['width']) ? (int) $item['width'] : 1080,
								'height'   => isset($item['height']) ? (int) $item['height'] : 1080,
								'is_video' => false,
							);
						}
					}
				}
			}
		}

		// 2. Parse og:description / meta description.
		$og_desc = self::extract_meta_content($html, 'property', 'og:description');
		if ($og_desc === '') {
			$og_desc = self::extract_meta_content($html, 'name', 'description');
		}
		if ($og_desc !== '' && $out['caption'] === '') {
			$parsed = self::clean_og_description($og_desc);
			if ($parsed['caption'] !== '') {
				$out['caption'] = $parsed['caption'];
			}
			if ($parsed['username'] !== '' && $out['username'] === '') {
				$out['username'] = $parsed['username'];
			}
		}

		// 3. Parse og:image (first image only).
		if (empty($out['images'])) {
			$og_img   = self::extract_meta_content($html, 'property', 'og:image');
			$og_video = self::extract_meta_content($html, 'property', 'og:video');
			if ($og_img !== '' && strpos($og_img, 'http') === 0) {
				$out['images'][] = array(
					'url'      => html_entity_decode($og_img, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
					'width'    => (int) self::extract_meta_content($html, 'property', 'og:image:width') ?: 1080,
					'height'   => (int) self::extract_meta_content($html, 'property', 'og:image:height') ?: 1080,
					'is_video' => ($og_video !== ''),
				);
				$out['media_type']  = ($og_video !== '') ? 'video' : 'image';
				$out['single_only'] = true; // og:image only ever provides the first image
			}
		}

		if ($out['caption'] === '' && empty($out['images'])) {
			$out['reason'] = 'direct post page contained no OpenGraph or JSON-LD post data';
		}

		return $out;
	}

	/**
	 * Extracts JSON objects embedded in <script> tags (such as contextJSON in Instagram embeds).
	 *
	 * @param string $html
	 * @return array
	 */
	protected static function extract_embedded_json_objects($html) {
		$objects = array();

		// 1. Look for "contextJSON":"..." escaped JSON string inside embed scripts.
		if (preg_match_all('/"contextJSON"\s*:\s*"((?:\\\\.|[^"\\\\])*)"/s', $html, $matches)) {
			foreach ($matches[1] as $escaped) {
				$unquoted = json_decode('"' . $escaped . '"', true);
				if (is_string($unquoted)) {
					$decoded = json_decode($unquoted, true);
					if (is_array($decoded)) {
						$objects[] = $decoded;
					}
				}
			}
		}

		// 2. Look for <script type="application/json"> or inline JSON with shortcode_media / edge_sidecar_to_children.
		if (preg_match_all('/<script[^>]*>([\s\S]*?)<\/script>/i', $html, $scripts)) {
			foreach ($scripts[1] as $script) {
				if (strpos($script, 'shortcode_media') === false && strpos($script, 'display_resources') === false && strpos($script, 'edge_sidecar_to_children') === false) {
					continue;
				}
				$trimmed = trim($script);
				if (strpos($trimmed, '{') === 0) {
					$decoded = json_decode($trimmed, true);
					if (is_array($decoded)) {
						$objects[] = $decoded;
						continue;
					}
				}
				if (preg_match('/"shortcode_media"\s*:\s*(\{[\s\S]*\})\s*\}/', $script, $sm)) {
					$decoded = json_decode($sm[1], true);
					if (is_array($decoded)) {
						$objects[] = array('shortcode_media' => $decoded);
					}
				}
			}
		}

		return $objects;
	}

	/**
	 * Recursively searches a decoded JSON tree for caption, username, location, and carousel/single media nodes.
	 * Picks the largest resource <= 1440px wide per item.
	 *
	 * @param array $tree
	 * @return array { caption, username, location_name, images, media_type }
	 */
	protected static function extract_media_from_json_tree($tree) {
		$result = array(
			'caption'       => '',
			'username'      => '',
			'location_name' => '',
			'images'        => array(),
			'media_type'    => 'unknown',
		);

		$media_root = self::find_key_recursive($tree, 'shortcode_media');
		if (!is_array($media_root)) {
			$media_root = self::find_key_recursive($tree, 'media');
		}
		if (!is_array($media_root)) {
			$media_root = $tree;
		}

		// Caption from edge_media_to_caption or caption field.
		$cap_edges = self::find_key_recursive($media_root, 'edge_media_to_caption');
		if (is_array($cap_edges) && isset($cap_edges['edges'][0]['node']['text'])) {
			$result['caption'] = trim(self::decode_text((string) $cap_edges['edges'][0]['node']['text']));
		} elseif (isset($media_root['caption']['text']) && is_string($media_root['caption']['text'])) {
			$result['caption'] = trim(self::decode_text($media_root['caption']['text']));
		} elseif (isset($media_root['caption']) && is_string($media_root['caption'])) {
			$result['caption'] = trim(self::decode_text($media_root['caption']));
		}

		// Username & location.
		if (isset($media_root['owner']['username']) && is_string($media_root['owner']['username'])) {
			$result['username'] = trim($media_root['owner']['username']);
		}
		if (isset($media_root['location']['name']) && is_string($media_root['location']['name'])) {
			$result['location_name'] = trim(self::decode_text($media_root['location']['name']));
		}

		// Check for carousel children (edge_sidecar_to_children or carousel_media or children).
		$sidecar = self::find_key_recursive($media_root, 'edge_sidecar_to_children');
		if (is_array($sidecar) && !empty($sidecar['edges']) && is_array($sidecar['edges'])) {
			foreach ($sidecar['edges'] as $edge) {
				$node = isset($edge['node']) && is_array($edge['node']) ? $edge['node'] : $edge;
				$img  = self::pick_best_image_from_node($node);
				if ($img) {
					$result['images'][] = $img;
				}
			}
			if (!empty($result['images'])) {
				$result['media_type'] = 'carousel';
				return $result;
			}
		}

		$carousel_media = self::find_key_recursive($media_root, 'carousel_media');
		if (is_array($carousel_media)) {
			foreach ($carousel_media as $node) {
				if (is_array($node)) {
					$img = self::pick_best_image_from_node($node);
					if ($img) {
						$result['images'][] = $img;
					}
				}
			}
			if (!empty($result['images'])) {
				$result['media_type'] = 'carousel';
				return $result;
			}
		}

		// Single media item on root node.
		$single = self::pick_best_image_from_node($media_root);
		if ($single) {
			$result['images'][]   = $single;
			$result['media_type'] = $single['is_video'] ? 'video' : 'image';
		}

		return $result;
	}

	/**
	 * Selects the best image resource <= 1440px wide from a media node.
	 *
	 * @param array $node
	 * @return array|null { url, width, height, is_video }
	 */
	protected static function pick_best_image_from_node($node) {
		if (!is_array($node)) {
			return null;
		}
		$is_video = !empty($node['is_video']) ||
			(isset($node['__typename']) && stripos((string) $node['__typename'], 'Video') !== false) ||
			(isset($node['media_type']) && (int) $node['media_type'] === 2);

		$candidates = array();
		if (!empty($node['display_resources']) && is_array($node['display_resources'])) {
			foreach ($node['display_resources'] as $res) {
				if (is_array($res) && !empty($res['src'])) {
					$candidates[] = array(
						'url'    => (string) $res['src'],
						'width'  => isset($res['config_width']) ? (int) $res['config_width'] : (isset($res['width']) ? (int) $res['width'] : 1080),
						'height' => isset($res['config_height']) ? (int) $res['config_height'] : (isset($res['height']) ? (int) $res['height'] : 1080),
					);
				}
			}
		}
		if (!empty($node['image_versions2']['candidates']) && is_array($node['image_versions2']['candidates'])) {
			foreach ($node['image_versions2']['candidates'] as $res) {
				if (is_array($res) && !empty($res['url'])) {
					$candidates[] = array(
						'url'    => (string) $res['url'],
						'width'  => isset($res['width']) ? (int) $res['width'] : 1080,
						'height' => isset($res['height']) ? (int) $res['height'] : 1080,
					);
				}
			}
		}

		$chosen = null;
		if (!empty($candidates)) {
			// Sort ascending by width, pick the largest <= 1440px (or smallest > 1440px if all exceed 1440px).
			usort($candidates, function ($a, $b) {
				return $a['width'] <=> $b['width'];
			});
			foreach ($candidates as $c) {
				if ($c['width'] <= 1440) {
					$chosen = $c;
				}
			}
			if (!$chosen) {
				$chosen = $candidates[0];
			}
		} elseif (!empty($node['display_url']) && is_string($node['display_url'])) {
			$chosen = array(
				'url'    => $node['display_url'],
				'width'  => isset($node['dimensions']['width']) ? (int) $node['dimensions']['width'] : 1080,
				'height' => isset($node['dimensions']['height']) ? (int) $node['dimensions']['height'] : 1080,
			);
		} elseif (!empty($node['thumbnail_src']) && is_string($node['thumbnail_src'])) {
			$chosen = array(
				'url'    => $node['thumbnail_src'],
				'width'  => 1080,
				'height' => 1080,
			);
		}

		if (!$chosen || strpos($chosen['url'], 'http') !== 0) {
			return null;
		}

		return array(
			'url'      => html_entity_decode($chosen['url'], ENT_QUOTES | ENT_HTML5, 'UTF-8'),
			'width'    => (int) $chosen['width'],
			'height'   => (int) $chosen['height'],
			'is_video' => (bool) $is_video,
		);
	}

	/**
	 * Recursively finds the first value for $target_key in an associative array.
	 *
	 * @param mixed  $arr
	 * @param string $target_key
	 * @return mixed|null
	 */
	protected static function find_key_recursive($arr, $target_key) {
		if (!is_array($arr)) {
			return null;
		}
		if (array_key_exists($target_key, $arr)) {
			return $arr[$target_key];
		}
		foreach ($arr as $val) {
			if (is_array($val)) {
				$found = self::find_key_recursive($val, $target_key);
				if ($found !== null) {
					return $found;
				}
			}
		}
		return null;
	}

	/**
	 * Extracts content attribute from a <meta> tag.
	 *
	 * @param string $html
	 * @param string $attr_name
	 * @param string $attr_val
	 * @return string
	 */
	protected static function extract_meta_content($html, $attr_name, $attr_val) {
		$pattern1 = '/<meta[^>]+' . preg_quote($attr_name, '/') . '=["\']' . preg_quote($attr_val, '/') . '["\'][^>]+content=["\']([^"\']*)["\']/i';
		$pattern2 = '/<meta[^>]+content=["\']([^"\']*)["\'][^>]+' . preg_quote($attr_name, '/') . '=["\']' . preg_quote($attr_val, '/') . '["\']/i';
		if (preg_match($pattern1, $html, $m) || preg_match($pattern2, $html, $m)) {
			return self::decode_text($m[1]);
		}
		return '';
	}

	/**
	 * Strips the leading "N likes, N comments - username on <date>: " prefix and wrapping quotes from og:description.
	 *
	 * @param string $raw
	 * @return array { caption: string, username: string }
	 */
	public static function clean_og_description($raw) {
		$text     = trim(self::decode_text($raw));
		$username = '';

		// Pattern: "123 likes, 4 comments - unixpadel on October 12, 2024: \"Caption...\""
		if (preg_match('/^[\d,.]+[KMBkmb]?\s+likes?,\s*[\d,.]+[KMBkmb]?\s+comments?\s*-\s*([A-Za-z0-9_.]+)\s+on\s+[^:]+:\s*([\s\S]*)$/iu', $text, $m)) {
			$username = trim($m[1]);
			$text     = trim($m[2]);
		} elseif (preg_match('/^[^:]{5,80}\s+on\s+Instagram:\s*([\s\S]*)$/iu', $text, $m)) {
			$text = trim($m[1]);
		}

		// Strip wrapping quotes ("..." or “...”) and trailing period added by Instagram.
		if (preg_match('/^(?:"|“|”)(.*)(?:"|“|”)\.?\s*$/su', $text, $qm)) {
			$text = trim($qm[1]);
		}

		return array(
			'caption'  => $text,
			'username' => $username,
		);
	}

	/**
	 * Decodes HTML entities and unicode escapes while preserving line breaks.
	 *
	 * @param string $str
	 * @return string
	 */
	public static function decode_text($str) {
		$str = (string) $str;
		$str = html_entity_decode($str, ENT_QUOTES | ENT_HTML5, 'UTF-8');
		if (strpos($str, '\\u') !== false) {
			$str = preg_replace_callback('/\\\\u([0-9a-fA-F]{4})/', function ($m) {
				return mb_convert_encoding(pack('H*', $m[1]), 'UTF-8', 'UCS-2BE');
			}, $str);
		}
		$str = str_replace(array("\r\n", "\r"), "\n", $str);
		return trim($str);
	}

	/**
	 * Merges partial results from strategies, preferring non-empty captions and larger image lists.
	 *
	 * @param array $base
	 * @param array $next
	 * @return array
	 */
	protected static function merge_results($base, $next) {
		if ($base['caption'] === '' && !empty($next['caption'])) {
			$base['caption'] = $next['caption'];
		}
		if ($base['username'] === '' && !empty($next['username'])) {
			$base['username'] = $next['username'];
		}
		if ($base['location_name'] === '' && !empty($next['location_name'])) {
			$base['location_name'] = $next['location_name'];
		}
		if (count($next['images']) > count($base['images'])) {
			$base['images']      = $next['images'];
			$base['media_type']  = $next['media_type'];
			$base['single_only'] = !empty($next['single_only']);
		}
		return $base;
	}

	/**
	 * Logs extractor events when WP_DEBUG is enabled (never logs full HTML).
	 *
	 * @param string $strategy
	 * @param int    $code
	 * @param string $reason
	 */
	protected static function log_debug($strategy, $code, $reason) {
		if (defined('WP_DEBUG') && WP_DEBUG) {
			error_log(sprintf(
				'[UPX_Instagram_Post_Extractor] strategy=%s status=%d reason=%s',
				$strategy,
				(int) $code,
				$reason !== '' ? $reason : 'ok'
			));
		}
	}
}
