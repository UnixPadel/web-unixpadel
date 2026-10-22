<?php
/**
 * Updates this plugin from GitHub. .github/workflows/deploy-wp.yml builds the ZIP on every push to main, publishes it as
 * a release and calls POST /?rest_route=/unixpadel/v1/deploy, signed with the deploy secret. The plugin then downloads
 * the latest release of the repository set here (never an address from the request), checks its SHA-256 and installs it
 * over itself with WordPress's own upgrader. An hourly check picks up releases whose call never arrived.
 */

if (!defined('ABSPATH')) {
	exit;
}

define('UPX_OPT_GH_REPO', 'upx_gh_repo');
define('UPX_OPT_GH_TOKEN', 'upx_gh_token');             // or define UPX_GH_TOKEN in wp-config.php
define('UPX_OPT_DEPLOY_SECRET', 'upx_deploy_secret');   // or define UPX_DEPLOY_SECRET in wp-config.php
define('UPX_OPT_DEPLOY_AUTO', 'upx_deploy_auto');       // '1' installs new releases, '0' leaves the installed build alone
define('UPX_OPT_DEPLOY_LOG', 'upx_deploy_log');
define('UPX_OPT_DEPLOY_PREVIOUS', 'upx_deploy_previous'); // release tag of the build before the last update
define('UPX_GH_REPO_DEFAULT', 'UnixPadel/web-unixpadel');
define('UPX_DEPLOY_CRON', 'upx_deploy_check');
define('UPX_DEPLOY_LOCK', 'upx_deploy_lock');
define('UPX_DEPLOY_MAX_SKEW', 5 * MINUTE_IN_SECONDS);  // signed calls older than this are refused
define('UPX_DEPLOY_LOG_SIZE', 20);

/* ---------- Settings ---------- */

function upx_gh_repo() {
	$repo = (string) get_option(UPX_OPT_GH_REPO, UPX_GH_REPO_DEFAULT);
	return preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $repo) ? $repo : UPX_GH_REPO_DEFAULT;
}

function upx_gh_token() {
	return defined('UPX_GH_TOKEN') ? (string) UPX_GH_TOKEN : (string) get_option(UPX_OPT_GH_TOKEN, '');
}

function upx_deploy_secret() {
	if (defined('UPX_DEPLOY_SECRET')) {
		return (string) UPX_DEPLOY_SECRET;
	}
	$secret = get_option(UPX_OPT_DEPLOY_SECRET);
	if (!$secret) {
		$secret = wp_generate_password(40, false);
		update_option(UPX_OPT_DEPLOY_SECRET, $secret, false);
	}
	return $secret;
}

function upx_deploy_auto() {
	return get_option(UPX_OPT_DEPLOY_AUTO, '1') === '1';
}

/** Release tag of the installed build (the workflow tags each release wp-<version>). */
function upx_deploy_tag() {
	return 'wp-' . UPX_VERSION;
}

/**
 * Is build version $a newer than $b? Compared as digit strings, segment by segment: the build stamp
 * (yyyymmddhhmmss) is larger than a 32-bit integer, where version_compare() would see every stamp as equal.
 */
function upx_version_newer($a, $b) {
	$a = explode('.', $a);
	$b = explode('.', $b);
	for ($i = 0, $n = max(count($a), count($b)); $i < $n; $i++) {
		$x = isset($a[$i]) ? ltrim($a[$i], '0') : '';
		$y = isset($b[$i]) ? ltrim($b[$i], '0') : '';
		if (strlen($x) !== strlen($y)) {
			return strlen($x) > strlen($y);
		}
		if ($x !== $y) {
			return strcmp($x, $y) > 0;
		}
	}
	return false;
}

function upx_deploy_log($level, $message) {
	$log = get_option(UPX_OPT_DEPLOY_LOG);
	$log = is_array($log) ? $log : array();
	array_unshift($log, array('time' => time(), 'level' => $level, 'message' => $message));
	update_option(UPX_OPT_DEPLOY_LOG, array_slice($log, 0, UPX_DEPLOY_LOG_SIZE), false);
}

/* ---------- GitHub ---------- */

function upx_gh_headers($accept) {
	$headers = array(
		'Accept'               => $accept,
		'User-Agent'           => 'unixpadel-static/' . UPX_VERSION,
		'X-GitHub-Api-Version' => '2022-11-28',
	);
	if (upx_gh_token() !== '') {
		$headers['Authorization'] = 'Bearer ' . upx_gh_token();
	}
	return $headers;
}

/**
 * The latest release (or the one tagged $tag) with its plugin ZIP.
 *
 * @return array|WP_Error ['tag', 'version', 'asset' (API URL), 'sha256']
 */
function upx_gh_release($tag = null) {
	$path = $tag === null ? 'latest' : 'tags/' . rawurlencode($tag);
	$res  = wp_remote_get('https://api.github.com/repos/' . upx_gh_repo() . '/releases/' . $path, array(
		'timeout' => 20,
		'headers' => upx_gh_headers('application/vnd.github+json'),
	));
	if (is_wp_error($res)) {
		return $res;
	}
	$code = (int) wp_remote_retrieve_response_code($res);
	$data = json_decode(wp_remote_retrieve_body($res), true);
	if ($code !== 200 || !is_array($data)) {
		$why = is_array($data) && isset($data['message']) ? $data['message'] : 'no answer';
		$hint = $code === 404 ? ' (no release yet, wrong repository, or the token cannot read it)' : '';
		return new WP_Error('upx_gh_release', sprintf('GitHub answered %d: %s%s', $code, $why, $hint));
	}
	if (!isset($data['tag_name']) || !preg_match('/^wp-(\d+(?:\.\d+)+)$/', (string) $data['tag_name'], $m)) {
		return new WP_Error('upx_gh_release', 'The latest release is not a plugin build (tag wp-<version>).');
	}
	$asset = null;
	foreach (isset($data['assets']) && is_array($data['assets']) ? $data['assets'] : array() as $a) {
		if (isset($a['name'], $a['url']) && $a['name'] === 'unixpadel-static-' . $m[1] . '.zip') {
			$asset = $a['url'];
		}
	}
	if (!preg_match('/sha256:\s*([a-f0-9]{64})/i', isset($data['body']) ? (string) $data['body'] : '', $sha) || !$asset) {
		return new WP_Error('upx_gh_release', sprintf('Release %s has no unixpadel-static-%s.zip with its sha256.', $data['tag_name'], $m[1]));
	}
	return array('tag' => $data['tag_name'], 'version' => $m[1], 'asset' => $asset, 'sha256' => strtolower($sha[1]));
}

/**
 * Downloads the release ZIP to a temporary file and checks its SHA-256. GitHub answers the asset URL with a redirect
 * to a signed storage address, which is fetched without the token.
 *
 * @return string|WP_Error path of the ZIP
 */
function upx_gh_download($release) {
	require_once ABSPATH . 'wp-admin/includes/file.php';
	$tmp = wp_tempnam('unixpadel-static.zip');
	$res = wp_remote_get($release['asset'], array(
		'timeout'     => 30,
		'redirection' => 0,
		'stream'      => true,
		'filename'    => $tmp,
		'headers'     => upx_gh_headers('application/octet-stream'),
	));
	$code = is_wp_error($res) ? 0 : (int) wp_remote_retrieve_response_code($res);
	if ($code >= 300 && $code < 400) {
		$location = wp_remote_retrieve_header($res, 'location');
		$location = is_array($location) ? reset($location) : (string) $location;
		$res      = wp_remote_get($location, array('timeout' => 300, 'stream' => true, 'filename' => $tmp));
		$code     = is_wp_error($res) ? 0 : (int) wp_remote_retrieve_response_code($res);
	}
	if ($code !== 200) {
		@unlink($tmp);
		return is_wp_error($res) ? $res : new WP_Error('upx_gh_download', sprintf('Downloading %s failed (%d).', $release['tag'], $code));
	}
	if (!hash_equals($release['sha256'], (string) hash_file('sha256', $tmp))) {
		@unlink($tmp);
		return new WP_Error('upx_gh_download', sprintf('The ZIP of %s does not match its sha256: not installed.', $release['tag']));
	}
	if (class_exists('ZipArchive')) {
		$zip = new ZipArchive();
		$ok  = $zip->open($tmp) === true && $zip->locateName('unixpadel-static/unixpadel-static.php') !== false;
		if ($ok) {
			$zip->close();
		}
		if (!$ok) {
			@unlink($tmp);
			return new WP_Error('upx_gh_download', sprintf('The ZIP of %s is not the Unixpadel plugin: not installed.', $release['tag']));
		}
	}
	return $tmp;
}

/* ---------- Installing ---------- */

/**
 * Installs the latest release (or $tag) over this plugin if it is newer than the installed build ($rollback: any
 * other build). The plugin stays active; mode, settings and references live in the database and are kept.
 *
 * @return array|WP_Error ['from', 'to', 'changed']
 */
function upx_deploy_install($tag, $reason, $rollback = false) {
	if ($tag === '') {
		return new WP_Error('upx_deploy_previous', 'No earlier build to go back to.');
	}
	if (get_transient(UPX_DEPLOY_LOCK)) {
		return new WP_Error('upx_deploy_busy', 'Another update is running; try again in a few minutes.');
	}
	set_transient(UPX_DEPLOY_LOCK, 1, 10 * MINUTE_IN_SECONDS);
	try {
		$release = upx_gh_release($tag);
		if (is_wp_error($release)) {
			upx_deploy_log('error', $reason . ': ' . $release->get_error_message());
			return $release;
		}
		$newer = upx_version_newer($release['version'], UPX_VERSION);
		if ($release['version'] === UPX_VERSION || (!$rollback && !$newer)) {
			return array('from' => UPX_VERSION, 'to' => UPX_VERSION, 'changed' => false);
		}
		if (function_exists('set_time_limit')) {
			@set_time_limit(300);
		}
		$zip = upx_gh_download($release);
		$done = is_wp_error($zip) ? $zip : upx_deploy_package($zip);
		if (!is_wp_error($zip)) {
			@unlink($zip);
		}
		if (is_wp_error($done)) {
			upx_deploy_log('error', $reason . ': ' . $done->get_error_message());
			return $done;
		}
		// From here on the files on disk are the new build; this request finishes with the old code it loaded.
		update_option(UPX_OPT_DEPLOY_PREVIOUS, upx_deploy_tag(), false);
		upx_purge_page_caches();
		upx_deploy_log('ok', sprintf('%s: %s → %s', $reason, UPX_VERSION, $release['version']));
		return array('from' => UPX_VERSION, 'to' => $release['version'], 'changed' => true);
	} finally {
		delete_transient(UPX_DEPLOY_LOCK);
	}
}

/** Replaces the plugin folder with the ZIP, the way WordPress updates plugins (backup restored if copying fails, WP 6.3+). */
function upx_deploy_package($zip) {
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/misc.php';
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
	require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
	if (get_filesystem_method() !== 'direct' || !WP_Filesystem()) {
		return new WP_Error('upx_deploy_fs', 'WordPress cannot write the plugin folder by itself (it asks for FTP details): ask the host to allow direct file writes.');
	}
	$slug = dirname(plugin_basename(UPX_PLUGIN_FILE));
	if ($slug !== 'unixpadel-static') {
		// The ZIP unpacks into plugins/unixpadel-static/: installing it would add a second copy instead of updating this one.
		return new WP_Error('upx_deploy_folder', sprintf('This plugin is installed in plugins/%s/, not plugins/unixpadel-static/: upload the ZIP once by hand to move it.', $slug));
	}
	$skin     = new Automatic_Upgrader_Skin();
	$upgrader = new Plugin_Upgrader($skin);
	$upgrader->init();
	$upgrader->upgrade_strings();
	$upgrader->maintenance_mode(true); // a few seconds of "briefly unavailable" instead of the old theme while files are copied
	$result = $upgrader->run(array(
		'package'           => $zip,
		'destination'       => WP_PLUGIN_DIR,
		'clear_destination' => true,
		'clear_working'     => true,
		'hook_extra'        => array(
			'plugin'      => plugin_basename(UPX_PLUGIN_FILE),
			'type'        => 'plugin',
			'action'      => 'update',
			'temp_backup' => array('slug' => $slug, 'src' => WP_PLUGIN_DIR, 'dir' => 'plugins'),
		),
	));
	$upgrader->maintenance_mode(false);
	if (is_wp_error($result)) {
		return $result;
	}
	if (!$result || !is_file(WP_PLUGIN_DIR . '/' . plugin_basename(UPX_PLUGIN_FILE))) {
		$messages = array_filter(array_map('wp_strip_all_tags', $skin->get_upgrade_messages()));
		return new WP_Error('upx_deploy_install', 'Installing failed' . ($messages ? ': ' . implode(' ', $messages) : '.'));
	}
	return true;
}

/* ---------- Signed call from GitHub Actions ---------- */

function upx_deploy_register_route() {
	register_rest_route('unixpadel/v1', '/deploy', array(
		'methods'             => 'POST',
		'callback'            => 'upx_deploy_webhook',
		'permission_callback' => 'upx_deploy_verify',
	));
}
add_action('rest_api_init', 'upx_deploy_register_route');

/** X-Upx-Signature: sha256=<HMAC of the raw body with the deploy secret>; the body's ts must be recent. */
function upx_deploy_verify(WP_REST_Request $request) {
	$body     = $request->get_body();
	$expected = 'sha256=' . hash_hmac('sha256', $body, upx_deploy_secret());
	if (!hash_equals($expected, (string) $request->get_header('x_upx_signature'))) {
		return new WP_Error('upx_deploy_signature', 'Bad signature.', array('status' => 401));
	}
	$params = json_decode($body, true);
	$ts     = is_array($params) && isset($params['ts']) ? (int) $params['ts'] : 0;
	if (abs(time() - $ts) > UPX_DEPLOY_MAX_SKEW) {
		return new WP_Error('upx_deploy_stale', 'Expired call (check the clock).', array('status' => 401));
	}
	return true;
}

/** The tag in the call is only logged: what gets installed is always the repository's latest release. */
function upx_deploy_webhook(WP_REST_Request $request) {
	$params = json_decode($request->get_body(), true);
	$tag    = is_array($params) && isset($params['tag']) && is_string($params['tag']) ? sanitize_text_field($params['tag']) : '?';
	if (!upx_deploy_auto()) {
		upx_deploy_log('info', sprintf('GitHub announced %s; automatic updates are paused, nothing installed.', $tag));
		return new WP_REST_Response(array('ok' => true, 'paused' => true, 'installed' => UPX_VERSION), 200);
	}
	$result = upx_deploy_install(null, 'GitHub push (' . $tag . ')');
	if (is_wp_error($result)) {
		return new WP_REST_Response(array('ok' => false, 'error' => $result->get_error_message()), 500);
	}
	return new WP_REST_Response(array('ok' => true) + $result, 200);
}

/* ---------- Hourly check ---------- */

function upx_deploy_schedule() {
	if (!wp_next_scheduled(UPX_DEPLOY_CRON)) {
		wp_schedule_event(time() + HOUR_IN_SECONDS, 'hourly', UPX_DEPLOY_CRON);
	}
}
add_action('init', 'upx_deploy_schedule');

function upx_deploy_cron() {
	if (upx_deploy_auto()) {
		upx_deploy_install(null, 'Hourly check');
	}
}
add_action(UPX_DEPLOY_CRON, 'upx_deploy_cron');

/**
 * The static site answers its pages and exits on init, before WordPress gets to spawn WP-Cron, so on a site that is
 * live most requests never start it. Start it here when the hourly check is due (spawn_cron runs at most once a minute).
 */
function upx_deploy_spawn_due() {
	$next = wp_next_scheduled(UPX_DEPLOY_CRON);
	if ($next && $next <= time() && !wp_doing_cron() && !(defined('DISABLE_WP_CRON') && DISABLE_WP_CRON)) {
		spawn_cron();
	}
}
add_action('init', 'upx_deploy_spawn_due', -1);

function upx_deploy_deactivate() {
	wp_clear_scheduled_hook(UPX_DEPLOY_CRON);
}
register_deactivation_hook(UPX_PLUGIN_FILE, 'upx_deploy_deactivate');

/* ---------- Settings → Unixpadel Static ---------- */

function upx_deploy_section() {
	$notice = get_transient('upx_deploy_notice');
	delete_transient('upx_deploy_notice');
	$log      = get_option(UPX_OPT_DEPLOY_LOG);
	$log      = is_array($log) ? $log : array();
	$previous = (string) get_option(UPX_OPT_DEPLOY_PREVIOUS, '');
	if (!function_exists('get_filesystem_method')) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
	}
	$format = get_option('date_format') . ' ' . get_option('time_format');
	?>
	<h2 id="upx-deploy">GitHub deploy</h2>
	<?php if (is_array($notice)) : ?>
		<div class="notice notice-<?php echo esc_attr($notice['type']); ?> inline"><p><?php echo esc_html($notice['text']); ?></p></div>
	<?php endif; ?>
	<p>Every push to <code>main</code> of <a href="<?php echo esc_url('https://github.com/' . upx_gh_repo()); ?>"><?php echo esc_html(upx_gh_repo()); ?></a> is built by GitHub Actions and installed here within minutes.
		Installed: <code><?php echo esc_html(upx_deploy_tag()); ?></code>.</p>
	<?php if (upx_gh_token() === '') : ?>
		<p class="notice notice-warning inline">No GitHub token: the repository is private, so WordPress cannot download its releases yet.</p>
	<?php endif; ?>
	<?php if (get_filesystem_method() !== 'direct') : ?>
		<p class="notice notice-error inline">WordPress cannot write files on this host by itself (it asks for FTP details), so updates cannot be installed automatically.</p>
	<?php endif; ?>
	<?php if (!upx_deploy_auto()) : ?>
		<p class="notice notice-warning inline">Automatic updates are paused: new releases are not installed until you turn them back on.</p>
	<?php endif; ?>
	<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
		<input type="hidden" name="action" value="upx_deploy">
		<input type="hidden" name="upx_do" value="save">
		<?php wp_nonce_field('upx_deploy'); ?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="upx-gh-repo">Repository</label></th>
				<td><input type="text" id="upx-gh-repo" class="regular-text code" name="upx_gh_repo" value="<?php echo esc_attr(upx_gh_repo()); ?>"></td>
			</tr>
			<tr>
				<th scope="row"><label for="upx-gh-token">GitHub token</label></th>
				<td>
					<?php if (defined('UPX_GH_TOKEN')) : ?>
						<p>Set in wp-config.php (UPX_GH_TOKEN).</p>
					<?php else : ?>
						<input type="password" id="upx-gh-token" class="regular-text code" name="upx_gh_token" value="" autocomplete="off" placeholder="<?php echo upx_gh_token() !== '' ? esc_attr('Saved (leave empty to keep it)') : 'github_pat_…'; ?>">
						<p class="description">Fine-grained token for this repository only, permission <em>Contents: Read-only</em>.</p>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th scope="row">Automatic updates</th>
				<td><label><input type="checkbox" name="upx_deploy_auto" value="1" <?php checked(upx_deploy_auto()); ?>> Install new releases from GitHub by themselves</label></td>
			</tr>
			<tr>
				<th scope="row">For GitHub</th>
				<td>
					<p>Repository → Settings → Secrets and variables → Actions → secret <code>WP_DEPLOY_SECRET</code>:</p>
					<?php if (defined('UPX_DEPLOY_SECRET')) : ?>
						<p>Set in wp-config.php (UPX_DEPLOY_SECRET).</p>
					<?php else : ?>
						<p><input type="text" class="large-text code" readonly value="<?php echo esc_attr(upx_deploy_secret()); ?>" onclick="this.select()"></p>
					<?php endif; ?>
					<p class="description">The workflow calls <code><?php echo esc_html(home_url('/?rest_route=/unixpadel/v1/deploy')); ?></code>. If that address differs from https://www.unixpadel.com/…, also add it as secret <code>WP_DEPLOY_URL</code>.</p>
				</td>
			</tr>
		</table>
		<?php submit_button('Save deploy settings', 'primary', 'submit', false); ?>
	</form>
	<p>
		<?php upx_deploy_button('check', 'Check for an update now', ''); ?>
		<?php if ($previous !== '' && $previous !== upx_deploy_tag()) : ?>
			<?php upx_deploy_button('rollback', 'Roll back to ' . $previous, 'Install ' . $previous . ' again and pause automatic updates?'); ?>
		<?php endif; ?>
		<?php if (!defined('UPX_DEPLOY_SECRET')) : ?>
			<?php upx_deploy_button('secret', 'New secret', 'GitHub keeps using the old secret until you paste the new one there. Continue?'); ?>
		<?php endif; ?>
	</p>
	<?php if ($log) : ?>
		<table class="widefat striped" style="max-width:900px">
			<tbody>
				<?php foreach (array_slice($log, 0, 10) as $row) : ?>
					<tr>
						<td style="white-space:nowrap"><?php echo esc_html(wp_date($format, (int) $row['time'])); ?></td>
						<td><?php echo $row['level'] === 'error' ? '<strong>Error</strong>' : esc_html($row['level'] === 'ok' ? 'Updated' : 'Info'); ?></td>
						<td><?php echo esc_html($row['message']); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
	<?php
}

function upx_deploy_button($do, $label, $confirm) {
	?>
	<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;margin-right:8px"<?php echo $confirm !== '' ? ' onsubmit="return confirm(' . esc_attr(wp_json_encode($confirm)) . ');"' : ''; ?>>
		<input type="hidden" name="action" value="upx_deploy">
		<input type="hidden" name="upx_do" value="<?php echo esc_attr($do); ?>">
		<?php wp_nonce_field('upx_deploy'); ?>
		<?php submit_button($label, 'secondary', 'submit', false); ?>
	</form>
	<?php
}

function upx_deploy_action() {
	if (!current_user_can('manage_options')) {
		wp_die('Not allowed.', 403);
	}
	check_admin_referer('upx_deploy');
	$do     = isset($_POST['upx_do']) ? sanitize_key(wp_unslash($_POST['upx_do'])) : '';
	$notice = null;
	if ($do === 'save') {
		$repo = isset($_POST['upx_gh_repo']) ? trim(sanitize_text_field(wp_unslash($_POST['upx_gh_repo']))) : '';
		update_option(UPX_OPT_GH_REPO, preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $repo) ? $repo : UPX_GH_REPO_DEFAULT);
		$token = isset($_POST['upx_gh_token']) ? trim((string) wp_unslash($_POST['upx_gh_token'])) : '';
		if ($token !== '') {
			update_option(UPX_OPT_GH_TOKEN, preg_replace('/[^A-Za-z0-9_]/', '', $token), false);
		}
		update_option(UPX_OPT_DEPLOY_AUTO, empty($_POST['upx_deploy_auto']) ? '0' : '1');
		$notice = array('type' => 'success', 'text' => 'Deploy settings saved.');
	} elseif ($do === 'check' || $do === 'rollback') {
		$rollback = $do === 'rollback';
		$tag      = $rollback ? (string) get_option(UPX_OPT_DEPLOY_PREVIOUS, '') : null;
		$result   = upx_deploy_install($tag, $rollback ? 'Rollback' : 'Manual check', $rollback);
		if (is_wp_error($result)) {
			$notice = array('type' => 'error', 'text' => $result->get_error_message());
		} elseif (!$result['changed']) {
			$notice = array('type' => 'info', 'text' => 'Already up to date (' . $result['to'] . ').');
		} else {
			if ($rollback) {
				update_option(UPX_OPT_DEPLOY_AUTO, '0'); // otherwise the next check reinstalls the newer build
			}
			$notice = array('type' => 'success', 'text' => sprintf('Installed %s (was %s).%s', $result['to'], $result['from'], $rollback ? ' Automatic updates are paused.' : ''));
		}
	} elseif ($do === 'secret') {
		update_option(UPX_OPT_DEPLOY_SECRET, wp_generate_password(40, false), false);
		$notice = array('type' => 'success', 'text' => 'New secret created: paste it into GitHub as WP_DEPLOY_SECRET.');
	}
	if ($notice) {
		set_transient('upx_deploy_notice', $notice, MINUTE_IN_SECONDS);
	}
	wp_safe_redirect(admin_url('options-general.php?page=unixpadel-static#upx-deploy'));
	exit;
}
add_action('admin_post_upx_deploy', 'upx_deploy_action');
