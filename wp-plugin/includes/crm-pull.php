<?php
/**
 * Quote requests → sales CRM (pull API, contract v1; the CRM repository documents it in docs/runbooks/web-form.md §7).
 *
 * The quote form keeps saving every request under WP admin → Quote requests. The CRM's worker asks for the requests it
 * has not confirmed yet and confirms the ones it stored. Nothing is pushed from here, so the CRM needs no public address
 * and a request waits here (never lost) while the CRM is down.
 *
 *   POST /?rest_route=/unixpadel/v1/crm/pending  {"op":"pending","limit":50}                (limit 1–100)
 *        → 200 {"ok":true,"items":[{"submission_id":"<uuid>","payload":{…}}],"more":true|false}  oldest first
 *   POST /?rest_route=/unixpadel/v1/crm/ack      {"op":"ack","items":[{"submission_id":"<uuid>","status":"inserted"|"duplicate"}]}
 *        → 200 {"ok":true,"acked":<n>,"unknown":["<uuid>"]}                                     idempotent
 *   Errors: 401 {"ok":false,"reason":"missing|malformed|stale|mismatch"}, 400 {"ok":false,"error":"bad_request"},
 *           429 {"ok":false,"error":"rate_limited"}, 503 {"ok":false,"error":"not_configured"}
 *
 * Both calls are signed with the CRM's WEB_FORM_HMAC_KEY: X-Webform-Timestamp: <Unix seconds>, X-Webform-Signature:
 * sha256=<hex HMAC-SHA256(key, "<timestamp>." + raw body)>, at most 300 seconds off this server's clock. The key is
 * entered under Settings → Unixpadel Static → CRM connection, or define('UPX_CRM_HMAC_KEY', '…') in wp-config.php.
 *
 * Requests saved before this file existed have only their text: it is parsed back into fields and they go out with
 * import_source "wp-legacy". A request moved to the Trash is not sent (trash spam before the CRM picks it up).
 * The contract is shared with the CRM repository: never change it on one side only.
 */

if (!defined('ABSPATH')) {
	exit;
}

define('UPX_OPT_CRM_KEY', 'upx_crm_hmac_key');
define('UPX_OPT_CRM_LAST_PULL', 'upx_crm_last_pull'); // Unix time of the last signed /pending (settings page)
define('UPX_CRM_META_ID', '_upx_crm_submission_id');
define('UPX_CRM_META_DATA', '_upx_crm_data');         // JSON {fields, attribution} captured when the form was sent
define('UPX_CRM_META_ACKED', '_upx_crm_acked');       // UTC "Y-m-d H:i:s" when the CRM confirmed the request
define('UPX_CRM_META_STATUS', '_upx_crm_status');     // the CRM's answer: inserted | duplicate
define('UPX_CRM_MAX_SKEW', 300);
define('UPX_CRM_PER_MINUTE', 120);                    // signed calls per IP address per minute
define('UPX_CRM_MAX_ACK', 500);
// Version of the privacy text (/pdpl/) the consent box points to; recorded with every consent in the CRM.
define('UPX_CRM_CONSENT_VERSION', '2026-01');
// Statuses a saved request can have. The Trash and auto-drafts are left out on purpose.
const UPX_CRM_STATUSES = array('private', 'publish', 'pending', 'draft', 'future');
// Click ids and campaign tags read from the page address the form was sent from.
const UPX_CRM_CLICK_KEYS = array('gclid', 'gbraid', 'wbraid', 'fbclid', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content');
// The form sends the project type in the visitor's language; the CRM expects the English label.
// Keep in sync with qTypes in src/i18n/ui.ts and src/i18n/locales/*.ts (same order in every language).
const UPX_CRM_PROJECT_TYPES = array(
	'en' => array('Sports club', 'Hotel / resort', 'Municipality / public', 'Private / residential', 'Architect / consultant', 'Distributor / partner'),
	'tr' => array('Spor kulübü', 'Otel / tatil köyü', 'Belediye / kamu', 'Özel mülk / villa', 'Mimar / danışman', 'Distribütör / iş ortağı'),
	'de' => array('Sportverein', 'Hotel / Resort', 'Kommune / öffentliche Hand', 'Privat / Wohnanlage', 'Architekt / Planer', 'Händler / Partner'),
	'fr' => array('Club sportif', 'Hôtel / complexe', 'Collectivité / public', 'Particulier / résidentiel', 'Architecte / bureau d’études', 'Distributeur / partenaire'),
	'ar' => array('نادٍ رياضي', 'فندق / منتجع', 'بلدية / جهة حكومية', 'سكن خاص / فيلا', 'مهندس معماري / استشاري', 'موزّع / شريك'),
	'ru' => array('Спортивный клуб', 'Отель / курорт', 'Муниципалитет / госзаказчик', 'Частный дом / резиденция', 'Архитектор / консультант', 'Дистрибьютор / партнёр'),
);
// Lines of the saved text (upx_quote_submit) → form keys, for requests saved before the structured copy existed.
const UPX_CRM_LEGACY_LABELS = array(
	'Name'           => 'name',
	'Company / club' => 'company',
	'Email'          => 'email',
	'Phone'          => 'phone',
	'Country'        => 'country',
	'Project type'   => 'projectType',
	'Court / system' => 'product',
	'Courts'         => 'courts',
	'Language'       => 'lang',
	'Sent from'      => 'page',
);

function upx_crm_key() {
	if (defined('UPX_CRM_HMAC_KEY')) {
		return trim((string) UPX_CRM_HMAC_KEY);
	}
	return trim((string) get_option(UPX_OPT_CRM_KEY, ''));
}

/* ---------- Capture (called by upx_quote_submit after the request is saved) ---------- */

/**
 * Keeps a structured copy of the request next to the readable text, plus the ad click ids of this visit.
 *
 * @param int   $post_id saved Quote request
 * @param array $f       sanitized form fields of upx_quote_submit
 */
function upx_crm_remember($post_id, array $f) {
	$data = array(
		'v'           => 1,
		'fields'      => $f,
		'attribution' => upx_crm_attribution(isset($f['page']) ? $f['page'] : '', $_COOKIE),
	);
	// update_post_meta unslashes its value: slash it so backslashes and quotes inside the JSON survive.
	update_post_meta($post_id, UPX_CRM_META_DATA, wp_slash(wp_json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)));
	upx_crm_submission_id($post_id);
}

/**
 * Click ids from the page address, then from the cookies Google / Meta tags set in the visitor's browser (the form
 * posts same-origin, so they arrive with it).
 *
 * @return array<string,string>
 */
function upx_crm_attribution($page_url, $cookies) {
	$out   = array();
	$query = array();
	parse_str((string) wp_parse_url((string) $page_url, PHP_URL_QUERY), $query);
	foreach (UPX_CRM_CLICK_KEYS as $key) {
		if (isset($query[$key]) && is_scalar($query[$key]) && trim((string) $query[$key]) !== '') {
			$out[$key] = substr(sanitize_text_field((string) $query[$key]), 0, 300);
		}
	}
	$cookies = is_array($cookies) ? $cookies : array();
	$cookie  = function ($name) use ($cookies) {
		return isset($cookies[$name]) && is_scalar($cookies[$name]) ? substr(sanitize_text_field((string) $cookies[$name]), 0, 300) : '';
	};
	// _gcl_aw / _gcl_gb: "GCL.<timestamp>.<id>", only the id is the click id.
	foreach (array('_gcl_aw' => 'gclid', '_gcl_gb' => 'gbraid') as $name => $key) {
		$parts = explode('.', $cookie($name));
		if (empty($out[$key]) && count($parts) >= 3 && $parts[2] !== '') {
			$out[$key] = implode('.', array_slice($parts, 2));
		}
	}
	foreach (array('_fbc' => 'fbc', '_fbp' => 'fbp') as $name => $key) {
		if ($cookie($name) !== '') {
			$out[$key] = $cookie($name);
		}
	}
	return $out;
}

/**
 * The request's id towards the CRM. Name-based (UUID v3 layout from site + post id), stored on first use: the same
 * request always gets the same id, even when two pulls race on an old request.
 */
function upx_crm_submission_id($post_id) {
	$id = get_post_meta($post_id, UPX_CRM_META_ID, true);
	if (is_string($id) && $id !== '') {
		return $id;
	}
	$h  = md5('upx_quote:' . wp_parse_url(home_url(), PHP_URL_HOST) . ':' . get_current_blog_id() . ':' . (int) $post_id);
	$id = sprintf(
		'%s-%s-3%s-%x%s-%s',
		substr($h, 0, 8),
		substr($h, 8, 4),
		substr($h, 13, 3),
		(hexdec($h[16]) & 0x3) | 0x8,
		substr($h, 17, 3),
		substr($h, 20, 12)
	);
	update_post_meta($post_id, UPX_CRM_META_ID, $id);
	return $id;
}

/* ---------- Payload ---------- */

/** English label for a project type sent in any of the site's languages; unknown values pass unchanged. */
function upx_crm_project_type($value) {
	$value = trim((string) $value);
	if ($value === '') {
		return '';
	}
	$lower = function ($s) {
		return function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
	};
	foreach (UPX_CRM_PROJECT_TYPES as $labels) {
		foreach ($labels as $i => $label) {
			if ($lower($label) === $lower($value)) {
				return UPX_CRM_PROJECT_TYPES['en'][$i];
			}
		}
	}
	return $value;
}

/** Fields of a request saved before the structured copy existed, read back from its text. */
function upx_crm_parse_legacy(WP_Post $post) {
	$text    = str_replace("\r\n", "\n", (string) $post->post_content);
	$message = '';
	$at      = strpos($text, "\nProject details:\n");
	if ($at !== false) {
		$message = trim(substr($text, $at + strlen("\nProject details:\n")));
		$text    = substr($text, 0, $at);
	}
	$decode = function ($s) {
		return trim(html_entity_decode((string) $s, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
	};
	$fields = array('message' => $decode($message));
	$extra  = array();
	foreach (explode("\n", $text) as $line) {
		$sep = strpos($line, ': ');
		if ($sep === false) {
			continue;
		}
		$label = trim(substr($line, 0, $sep));
		$value = $decode(substr($line, $sep + 2));
		if ($value === '') {
			continue;
		}
		if (array_key_exists($label, UPX_CRM_LEGACY_LABELS)) {
			$fields[UPX_CRM_LEGACY_LABELS[$label]] = $value;
		} elseif ($label !== '' && sanitize_key(str_replace(array(' ', '/'), '_', $label)) !== '') {
			$extra[sanitize_key(str_replace(array(' ', '/'), '_', $label))] = $value;
		}
	}
	return array(
		'fields'      => $fields,
		'extra'       => $extra,
		'attribution' => upx_crm_attribution(isset($fields['page']) ? $fields['page'] : '', array()),
	);
}

/** Contract v1 payload of one request (submission_id stays outside, on the item). */
function upx_crm_payload(WP_Post $post) {
	$raw    = get_post_meta($post->ID, UPX_CRM_META_DATA, true);
	$data   = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
	$legacy = !is_array($data) || !isset($data['fields']) || !is_array($data['fields']);
	if ($legacy) {
		$data = upx_crm_parse_legacy($post);
	}
	$f   = $data['fields'];
	$get = function ($key) use ($f) {
		return isset($f[$key]) && is_scalar($f[$key]) ? trim((string) $f[$key]) : '';
	};

	$fields = array();
	// Extra lines of old texts first, so a known field of the same name always wins.
	if (!empty($data['extra']) && is_array($data['extra'])) {
		$fields = $data['extra'];
	}
	$known = array(
		'name'             => $get('name'),
		'company'          => $get('company'),
		'email'            => $get('email'),
		'phone'            => $get('phone'),
		'product_interest' => $get('product'),
		'product_slug'     => $get('productSlug'),
		'project_type'     => upx_crm_project_type($get('projectType')),
		'court_count'      => $get('courts'),
		'message'          => $get('message'),
		'page_language'    => $get('lang'),
	);
	foreach ($known as $key => $value) {
		if ($value !== '') {
			$fields[$key] = $value;
		}
	}
	// The form's country box is free text: an ISO code goes to "country", the text always to "country_input" (the
	// CRM resolves names in six languages and falls back to the phone's country).
	$country = $get('country');
	if ($country !== '') {
		if (preg_match('/^[A-Za-z]{2}$/', $country)) {
			$fields['country'] = strtoupper($country);
		}
		$fields['country_input'] = $country;
	}
	if ($legacy) {
		$fields['import_source'] = 'wp-legacy';
	}

	$page        = $get('page');
	$query       = array();
	parse_str((string) wp_parse_url($page, PHP_URL_QUERY), $query);
	$attribution = isset($data['attribution']) && is_array($data['attribution']) ? $data['attribution'] : array();

	return array(
		'form_id'      => 'quote',
		'submitted_at' => gmdate('Y-m-d\TH:i:s\Z', (int) get_post_time('U', true, $post)),
		'page_url'     => $page,
		// Test sends: open the form page with ?upx_test=1 (the CRM shows them only under "Test leads").
		'is_test'      => isset($query['upx_test']) && $query['upx_test'] === '1',
		'honeypot'     => '', // bots never reach the saved requests (upx_quote_submit drops them)
		'consents'     => array(array('type' => 'kvkk', 'granted' => true, 'text_version' => UPX_CRM_CONSENT_VERSION)),
		'attribution'  => (object) $attribution,
		'fields'       => (object) $fields,
	);
}

/* ---------- REST: /crm/pending and /crm/ack ---------- */

function upx_crm_register_routes() {
	foreach (array('pending' => 'upx_crm_pending', 'ack' => 'upx_crm_ack') as $path => $callback) {
		register_rest_route('unixpadel/v1', '/crm/' . $path, array(
			'methods'             => 'POST',
			'callback'            => $callback,
			'permission_callback' => '__return_true', // signed with the shared key, checked in upx_crm_guard
		));
	}
}
add_action('rest_api_init', 'upx_crm_register_routes');

function upx_crm_reply($status, array $body) {
	$response = new WP_REST_Response($body, $status);
	$response->header('Cache-Control', 'no-store');
	return $response;
}

/**
 * Key, rate, signature and op checks shared by both routes.
 *
 * @return array|WP_REST_Response the decoded body, or the error answer
 */
function upx_crm_guard(WP_REST_Request $request, $op) {
	$key = upx_crm_key();
	if ($key === '') {
		return upx_crm_reply(503, array('ok' => false, 'error' => 'not_configured'));
	}

	$ip   = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';
	$rate = 'upx_crm_rate_' . md5($ip);
	$hits = (int) get_transient($rate);
	if ($hits >= UPX_CRM_PER_MINUTE) {
		return upx_crm_reply(429, array('ok' => false, 'error' => 'rate_limited'));
	}
	set_transient($rate, $hits + 1, MINUTE_IN_SECONDS);

	$ts  = trim((string) $request->get_header('x-webform-timestamp'));
	$sig = trim((string) $request->get_header('x-webform-signature'));
	if ($ts === '' || $sig === '') {
		return upx_crm_reply(401, array('ok' => false, 'reason' => 'missing'));
	}
	if (!ctype_digit($ts) || !preg_match('/^sha256=([0-9a-f]{64})$/i', $sig, $m)) {
		return upx_crm_reply(401, array('ok' => false, 'reason' => 'malformed'));
	}
	if (abs(time() - (int) $ts) > UPX_CRM_MAX_SKEW) {
		return upx_crm_reply(401, array('ok' => false, 'reason' => 'stale'));
	}
	$raw = (string) $request->get_body();
	if (!hash_equals(hash_hmac('sha256', $ts . '.' . $raw, $key), strtolower($m[1]))) {
		return upx_crm_reply(401, array('ok' => false, 'reason' => 'mismatch'));
	}

	$body = json_decode($raw, true);
	if (!is_array($body) || !isset($body['op']) || $body['op'] !== $op) {
		return upx_crm_reply(400, array('ok' => false, 'error' => 'bad_request'));
	}
	return $body;
}

function upx_crm_pending(WP_REST_Request $request) {
	$body = upx_crm_guard($request, 'pending');
	if ($body instanceof WP_REST_Response) {
		return $body;
	}
	$limit = 50;
	if (array_key_exists('limit', $body)) {
		if (!is_int($body['limit']) || $body['limit'] < 1 || $body['limit'] > 100) {
			return upx_crm_reply(400, array('ok' => false, 'error' => 'bad_request'));
		}
		$limit = $body['limit'];
	}

	$posts = get_posts(array(
		'post_type'        => UPX_QUOTE_POST_TYPE,
		'post_status'      => UPX_CRM_STATUSES,
		'meta_query'       => array(array('key' => UPX_CRM_META_ACKED, 'compare' => 'NOT EXISTS')),
		'orderby'          => array('date' => 'ASC', 'ID' => 'ASC'),
		'posts_per_page'   => $limit + 1,
		'no_found_rows'    => true,
		'suppress_filters' => true,
	));
	$more  = count($posts) > $limit;
	$items = array();
	foreach (array_slice($posts, 0, $limit) as $post) {
		$items[] = array(
			'submission_id' => upx_crm_submission_id($post->ID),
			'payload'       => upx_crm_payload($post),
		);
	}
	update_option(UPX_OPT_CRM_LAST_PULL, time(), false);
	return upx_crm_reply(200, array('ok' => true, 'items' => $items, 'more' => $more));
}

function upx_crm_ack(WP_REST_Request $request) {
	$body = upx_crm_guard($request, 'ack');
	if ($body instanceof WP_REST_Response) {
		return $body;
	}
	$items = isset($body['items']) ? $body['items'] : null;
	if (!is_array($items) || count($items) > UPX_CRM_MAX_ACK) {
		return upx_crm_reply(400, array('ok' => false, 'error' => 'bad_request'));
	}
	foreach ($items as $item) {
		if (
			!is_array($item)
			|| !isset($item['submission_id'], $item['status'])
			|| !is_string($item['submission_id']) || $item['submission_id'] === ''
			|| !in_array($item['status'], array('inserted', 'duplicate'), true)
		) {
			return upx_crm_reply(400, array('ok' => false, 'error' => 'bad_request'));
		}
	}

	$acked   = 0;
	$unknown = array();
	foreach ($items as $item) {
		$ids = get_posts(array(
			'post_type'        => UPX_QUOTE_POST_TYPE,
			'post_status'      => array_merge(UPX_CRM_STATUSES, array('trash')),
			'meta_key'         => UPX_CRM_META_ID,
			'meta_value'       => $item['submission_id'],
			'fields'           => 'ids',
			'posts_per_page'   => 1,
			'no_found_rows'    => true,
			'suppress_filters' => true,
		));
		if (!$ids) {
			$unknown[] = $item['submission_id'];
			continue;
		}
		if (!get_post_meta($ids[0], UPX_CRM_META_ACKED, true)) {
			update_post_meta($ids[0], UPX_CRM_META_ACKED, gmdate('Y-m-d H:i:s'));
			update_post_meta($ids[0], UPX_CRM_META_STATUS, $item['status']);
		}
		$acked++;
	}
	return upx_crm_reply(200, array('ok' => true, 'acked' => $acked, 'unknown' => $unknown));
}

/* ---------- Admin: CRM column, box on the request, settings ---------- */

function upx_crm_status_html($post_id) {
	$acked = get_post_meta($post_id, UPX_CRM_META_ACKED, true);
	if ($acked) {
		return sprintf(
			'<span style="color:#008a20">&#10003; In CRM</span><br><small>%s</small>',
			esc_html(get_date_from_gmt($acked, get_option('date_format') . ' ' . get_option('time_format')))
		);
	}
	if (get_post_status($post_id) === 'trash') {
		return '<span style="color:#646970">Not sent (in Trash)</span>';
	}
	return upx_crm_key() === ''
		? '<span style="color:#646970">Waiting (CRM not connected)</span>'
		: '<span style="color:#996800">Waiting for the CRM</span>';
}

function upx_crm_columns($columns) {
	$out = array();
	foreach ($columns as $key => $label) {
		if ($key === 'date') {
			$out['upx_crm'] = 'CRM';
		}
		$out[$key] = $label;
	}
	if (!isset($out['upx_crm'])) {
		$out['upx_crm'] = 'CRM';
	}
	return $out;
}
add_filter('manage_' . UPX_QUOTE_POST_TYPE . '_posts_columns', 'upx_crm_columns');

function upx_crm_column($column, $post_id) {
	if ($column === 'upx_crm') {
		echo upx_crm_status_html($post_id); // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts
	}
}
add_action('manage_' . UPX_QUOTE_POST_TYPE . '_posts_custom_column', 'upx_crm_column', 10, 2);

function upx_crm_meta_box() {
	add_meta_box('upx-crm', 'Sales CRM', function ($post) {
		echo '<p>' . upx_crm_status_html($post->ID) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput
		$id = get_post_meta($post->ID, UPX_CRM_META_ID, true);
		if ($id) {
			printf('<p class="description">Request id <code>%s</code></p>', esc_html($id));
		}
	}, UPX_QUOTE_POST_TYPE, 'side', 'high');
}
add_action('add_meta_boxes_' . UPX_QUOTE_POST_TYPE, 'upx_crm_meta_box');

function upx_crm_register_setting() {
	register_setting('upx_static', UPX_OPT_CRM_KEY, array(
		'type'              => 'string',
		'default'           => '',
		'sanitize_callback' => function ($value) {
			return trim(sanitize_text_field((string) $value));
		},
	));
}
add_action('admin_init', 'upx_crm_register_setting');

/** Number of saved requests the CRM has / has not confirmed yet. */
function upx_crm_count($acked) {
	$query = new WP_Query(array(
		'post_type'        => UPX_QUOTE_POST_TYPE,
		'post_status'      => UPX_CRM_STATUSES,
		'meta_query'       => array(array('key' => UPX_CRM_META_ACKED, 'compare' => $acked ? 'EXISTS' : 'NOT EXISTS')),
		'fields'           => 'ids',
		'posts_per_page'   => 1,
		'suppress_filters' => true,
	));
	return (int) $query->found_posts;
}

/** "CRM connection" part of Settings → Unixpadel Static (inside its settings form). */
function upx_crm_settings_section() {
	$last = (int) get_option(UPX_OPT_CRM_LAST_PULL, 0);
	?>
	<h2>Sales CRM connection</h2>
	<p>The CRM fetches the quote requests from this site every two minutes and confirms the ones it stored
		(<?php echo (int) upx_crm_count(true); ?> in the CRM, <?php echo (int) upx_crm_count(false); ?> waiting).
		Requests in the Trash are never sent. Last fetch:
		<strong><?php echo $last ? esc_html(wp_date(get_option('date_format') . ' ' . get_option('time_format'), $last)) : 'never'; ?></strong>.</p>
	<table class="form-table" role="presentation">
		<tr>
			<th scope="row">Address for the CRM</th>
			<td><input type="text" class="large-text code" readonly onclick="this.select()" value="<?php echo esc_attr(home_url('/?rest_route=/unixpadel/v1/crm')); ?>">
				<p class="description">The CRM worker's <code>WEB_PULL_URL</code>.</p></td>
		</tr>
		<tr>
			<th scope="row"><label for="upx-crm-key">Shared key</label></th>
			<td>
				<?php if (defined('UPX_CRM_HMAC_KEY')) : ?>
					<p>Configured in <code>wp-config.php</code> (UPX_CRM_HMAC_KEY).</p>
				<?php else : ?>
					<input type="password" id="upx-crm-key" class="large-text code" name="<?php echo esc_attr(UPX_OPT_CRM_KEY); ?>" value="<?php echo esc_attr(upx_crm_key()); ?>" autocomplete="off">
					<p class="description">The same value as the CRM worker's <code>WEB_FORM_HMAC_KEY</code>. Empty: the CRM gets nothing and requests wait here.</p>
				<?php endif; ?>
			</td>
		</tr>
	</table>
	<?php
}
