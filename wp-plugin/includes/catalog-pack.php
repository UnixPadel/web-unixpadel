<?php
/**
 * Page files of the catalogue flipbook (/catalog/). They are not in the plugin ZIP, not in the media library and not
 * uploaded by hand: scripts/build-catalog-pages.py packs them into ZIP parts that are attached to the GitHub release
 * `catalog-<dir>`, and catalog.php (written by scripts/build-wp-plugin.mjs) names that folder and the parts with their
 * SHA-256. When the folder this build expects is missing, the plugin downloads the parts in the background, checks
 * them and unpacks them to wp-content/uploads/upx-catalog/<dir>/, then removes the folders of older editions.
 *
 * The files are shuffled tiles that only the viewer puts back together, with names that cannot be guessed, so having
 * them reachable under /wp-content/uploads does not make the catalogue downloadable. Nothing here can take the site
 * down: every failure is logged (Settings → Unixpadel Static) and tried again later.
 */

if (!defined('ABSPATH')) {
	exit;
}

define('UPX_OPT_CATALOG', 'upx_catalog_pack'); // ['dir', 'done' => part names, 'error', 'time']
define('UPX_CATALOG_CRON', 'upx_catalog_fetch');
define('UPX_CATALOG_LOCK', 'upx_catalog_lock');
define('UPX_CATALOG_FILE', '/^[a-f0-9]{16}\.webp$/');

/** What this build expects: ['dir' => 16 hex, 'parts' => [['name', 'sha256', 'files'], …]], or [] when there is no catalogue. */
function upx_catalog_manifest() {
	static $manifest = null;
	if ($manifest === null) {
		$file     = dirname(__DIR__) . '/catalog.php';
		$manifest = is_file($file) ? (require $file) : array();
		$valid    = is_array($manifest) && isset($manifest['dir'], $manifest['parts']) && is_string($manifest['dir'])
			&& preg_match('/^[a-f0-9]{16}$/', $manifest['dir']) && is_array($manifest['parts']) && $manifest['parts'];
		if ($valid) {
			foreach ($manifest['parts'] as $part) {
				$valid = $valid && is_array($part) && isset($part['name'], $part['sha256'])
					&& preg_match('/^catalog-pack-[a-f0-9]{16}-\d+\.zip$/', (string) $part['name']) && preg_match('/^[a-f0-9]{64}$/', (string) $part['sha256']);
			}
		}
		$manifest = $valid ? $manifest : array();
	}
	return $manifest;
}

function upx_catalog_root() {
	$uploads = wp_upload_dir(null, false);
	return trailingslashit($uploads['basedir']) . 'upx-catalog';
}

/** True when there is nothing to fetch: no catalogue in this build, or its folder is complete. */
function upx_catalog_ready() {
	$manifest = upx_catalog_manifest();
	return !$manifest || is_file(upx_catalog_root() . '/' . $manifest['dir'] . '/.ready');
}

function upx_catalog_state() {
	$manifest = upx_catalog_manifest();
	$state    = get_option(UPX_OPT_CATALOG);
	if (!is_array($state) || !isset($state['dir']) || !$manifest || $state['dir'] !== $manifest['dir']) {
		$state = array('dir' => $manifest ? $manifest['dir'] : '', 'done' => array(), 'error' => '', 'time' => 0);
	}
	$state['done'] = isset($state['done']) && is_array($state['done']) ? $state['done'] : array();
	return $state;
}

/** API URLs of the assets of release catalog-<dir>, by file name. */
function upx_catalog_assets($dir) {
	$res = wp_remote_get('https://api.github.com/repos/' . upx_gh_repo() . '/releases/tags/catalog-' . $dir, array(
		'timeout' => 20,
		'headers' => upx_gh_headers('application/vnd.github+json'),
	));
	if (is_wp_error($res)) {
		return $res;
	}
	$code = (int) wp_remote_retrieve_response_code($res);
	$data = json_decode(wp_remote_retrieve_body($res), true);
	if ($code !== 200 || !is_array($data)) {
		return new WP_Error('upx_catalog', sprintf('GitHub answered %d for release catalog-%s (missing release, or the token cannot read it).', $code, $dir));
	}
	$assets = array();
	foreach (isset($data['assets']) && is_array($data['assets']) ? $data['assets'] : array() as $asset) {
		if (isset($asset['name'], $asset['url'])) {
			$assets[(string) $asset['name']] = (string) $asset['url'];
		}
	}
	return $assets;
}

/** Downloads one part to a temporary file and checks its SHA-256 (same two-step fetch as upx_gh_download()). */
function upx_catalog_download($url, $sha256, $name) {
	$tmp = wp_tempnam($name);
	$res = wp_remote_get($url, array(
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
		return is_wp_error($res) ? $res : new WP_Error('upx_catalog', sprintf('Downloading %s failed (%d).', $name, $code));
	}
	if (!hash_equals($sha256, (string) hash_file('sha256', $tmp))) {
		@unlink($tmp);
		return new WP_Error('upx_catalog', sprintf('%s does not match its sha256: not unpacked.', $name));
	}
	return $tmp;
}

/**
 * Unpacks a checked part into $dest. Only flat entries named like a page file are written; anything else in the ZIP
 * is ignored, so a ZIP can never place a file outside the folder or one that the server would run.
 *
 * @return int|WP_Error number of files written
 */
function upx_catalog_unpack($zip_path, $dest) {
	if (!class_exists('ZipArchive')) {
		return new WP_Error('upx_catalog', 'This server has no ZipArchive: the catalogue files cannot be unpacked.');
	}
	$zip = new ZipArchive();
	if ($zip->open($zip_path) !== true) {
		return new WP_Error('upx_catalog', 'A catalogue part could not be opened as a ZIP.');
	}
	$written = 0;
	for ($i = 0; $i < $zip->numFiles; $i++) {
		$name = (string) $zip->getNameIndex($i);
		if (!preg_match(UPX_CATALOG_FILE, $name)) {
			continue;
		}
		$data = $zip->getFromIndex($i);
		if ($data === false || file_put_contents($dest . '/' . $name, $data) === false) {
			$zip->close();
			return new WP_Error('upx_catalog', sprintf('Could not write %s (disk full or uploads not writable?).', $name));
		}
		$written++;
	}
	$zip->close();
	return $written;
}

/** Deletes a folder of page files (only the file types this plugin put there). */
function upx_catalog_remove_dir($dir) {
	$entries = is_dir($dir) ? scandir($dir) : array();
	foreach (is_array($entries) ? $entries : array() as $entry) {
		if (preg_match(UPX_CATALOG_FILE, $entry) || $entry === '.ready' || $entry === 'index.html') {
			@unlink($dir . '/' . $entry);
		}
	}
	@rmdir($dir);
}

/**
 * Fetches whatever parts are still missing. Safe to call again: finished parts are remembered.
 *
 * @return true|WP_Error
 */
function upx_catalog_fetch() {
	$manifest = upx_catalog_manifest();
	if (!$manifest) {
		return true;
	}
	$root = upx_catalog_root();
	$dest = $root . '/' . $manifest['dir'];
	if (!is_file($dest . '/.ready')) {
		if (get_transient(UPX_CATALOG_LOCK)) {
			return new WP_Error('upx_catalog', 'The catalogue files are being downloaded right now.');
		}
		set_transient(UPX_CATALOG_LOCK, 1, 15 * MINUTE_IN_SECONDS);
		if (function_exists('set_time_limit')) {
			@set_time_limit(600);
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		$state  = upx_catalog_state();
		$result = true;
		if (!wp_mkdir_p($dest)) {
			$result = new WP_Error('upx_catalog', 'Could not create wp-content/uploads/upx-catalog.');
		} else {
			// No directory listing, whatever the server's setting.
			foreach (array($root, $dest) as $folder) {
				if (!is_file($folder . '/index.html')) {
					@file_put_contents($folder . '/index.html', '');
				}
			}
			$assets = upx_catalog_assets($manifest['dir']);
			$result = is_wp_error($assets) ? $assets : true;
		}
		$expected = 0;
		foreach ($manifest['parts'] as $part) {
			$expected += isset($part['files']) ? (int) $part['files'] : 0;
			if ($result !== true || in_array($part['name'], $state['done'], true)) {
				continue;
			}
			if (!isset($assets[$part['name']])) {
				$result = new WP_Error('upx_catalog', sprintf('Release catalog-%s has no %s.', $manifest['dir'], $part['name']));
				continue;
			}
			$tmp = upx_catalog_download($assets[$part['name']], strtolower($part['sha256']), $part['name']);
			if (is_wp_error($tmp)) {
				$result = $tmp;
				continue;
			}
			$written = upx_catalog_unpack($tmp, $dest);
			@unlink($tmp);
			if (is_wp_error($written)) {
				$result = $written;
				continue;
			}
			$state['done'][] = $part['name'];
			update_option(UPX_OPT_CATALOG, $state, false); // a later attempt continues after this part
		}
		if ($result === true) {
			$found = count((array) glob($dest . '/*.webp'));
			if ($expected && $found < $expected) {
				$state['done'] = array(); // something was removed meanwhile: start over next time
				$result        = new WP_Error('upx_catalog', sprintf('Only %d of %d catalogue files are in place.', $found, $expected));
			} elseif (@file_put_contents($dest . '/.ready', (string) time()) === false) {
				$result = new WP_Error('upx_catalog', 'Could not mark the catalogue folder as complete.');
			}
		}
		$state['error'] = is_wp_error($result) ? $result->get_error_message() : '';
		$state['time']  = time();
		update_option(UPX_OPT_CATALOG, $state, false);
		delete_transient(UPX_CATALOG_LOCK);
		if (is_wp_error($result)) {
			upx_deploy_log('error', 'Catalogue files: ' . $result->get_error_message());
			return $result;
		}
		upx_deploy_log('info', sprintf('Catalogue files in place (%d files, folder %s).', $found, $manifest['dir']));
	}
	// Older editions are of no use once the current one is complete.
	$entries = is_dir($root) ? scandir($root) : array();
	foreach (is_array($entries) ? $entries : array() as $entry) {
		if ($entry !== $manifest['dir'] && preg_match('/^[a-f0-9]{16}$/', $entry) && is_dir($root . '/' . $entry)) {
			upx_catalog_remove_dir($root . '/' . $entry);
		}
	}
	return true;
}

/* ---------- Background: fetch when this build's folder is missing ---------- */

function upx_catalog_cron() {
	try {
		upx_catalog_fetch();
	} catch (Throwable $e) {
		delete_transient(UPX_CATALOG_LOCK);
		error_log('Unixpadel catalogue: ' . $e->getMessage());
	}
}
add_action(UPX_CATALOG_CRON, 'upx_catalog_cron');

/**
 * Runs before the static site answers (init, priority 0) and exits: one file check per request while everything is in
 * place. A missing folder queues a fetch; after a failure the next attempt waits 15 minutes.
 */
function upx_catalog_schedule() {
	if (upx_catalog_ready() || wp_doing_cron()) {
		return;
	}
	$next = wp_next_scheduled(UPX_CATALOG_CRON);
	if (!$next) {
		$state = upx_catalog_state();
		$wait  = $state['error'] !== '' && $state['time'] > time() - 15 * MINUTE_IN_SECONDS ? 15 * MINUTE_IN_SECONDS : 0;
		wp_schedule_single_event(time() + $wait, UPX_CATALOG_CRON);
		$next = time() + $wait;
	}
	if ($next <= time() && !(defined('DISABLE_WP_CRON') && DISABLE_WP_CRON)) {
		spawn_cron();
	}
}
add_action('init', 'upx_catalog_schedule', -1);

function upx_catalog_deactivate() {
	wp_clear_scheduled_hook(UPX_CATALOG_CRON);
}
register_deactivation_hook(UPX_PLUGIN_FILE, 'upx_catalog_deactivate');

/* ---------- Settings → Unixpadel Static ---------- */

function upx_catalog_section() {
	$manifest = upx_catalog_manifest();
	if (!$manifest) {
		return;
	}
	$state  = upx_catalog_state();
	$notice = get_transient('upx_catalog_notice');
	delete_transient('upx_catalog_notice');
	?>
	<h2 id="upx-catalog">Catalogue files</h2>
	<?php if (is_array($notice)) : ?>
		<div class="notice notice-<?php echo esc_attr($notice['type']); ?> inline"><p><?php echo esc_html($notice['text']); ?></p></div>
	<?php endif; ?>
	<p>
		<?php if (upx_catalog_ready()) : ?>
			<strong>In place.</strong> The page files of the catalogue viewer are in <code>uploads/upx-catalog/</code>.
		<?php elseif ($state['error'] !== '') : ?>
			<strong>Not complete:</strong> <?php echo esc_html($state['error']); ?> The plugin tries again on its own every 15 minutes.
		<?php else : ?>
			<strong>Downloading</strong> (<?php echo (int) count($state['done']); ?> of <?php echo (int) count($manifest['parts']); ?> parts so far). This happens in the background after an update.
		<?php endif; ?>
	</p>
	<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
		<input type="hidden" name="action" value="upx_catalog_fetch">
		<?php wp_nonce_field('upx_catalog_fetch'); ?>
		<?php submit_button(upx_catalog_ready() ? 'Download the catalogue files again' : 'Download the catalogue files now', 'secondary', 'submit', false); ?>
		<span class="description">They come from the GitHub release named in this build; the GitHub token below must be able to read it.</span>
	</form>
	<?php
}

function upx_catalog_action() {
	if (!current_user_can('manage_options')) {
		wp_die('Not allowed.', 403);
	}
	check_admin_referer('upx_catalog_fetch');
	$manifest = upx_catalog_manifest();
	if ($manifest) {
		// "Again" really fetches again: forget the finished parts and the completion mark.
		@unlink(upx_catalog_root() . '/' . $manifest['dir'] . '/.ready');
		delete_option(UPX_OPT_CATALOG);
		delete_transient(UPX_CATALOG_LOCK);
	}
	try {
		$result = upx_catalog_fetch();
	} catch (Throwable $e) {
		delete_transient(UPX_CATALOG_LOCK);
		$result = new WP_Error('upx_catalog', $e->getMessage());
	}
	set_transient('upx_catalog_notice', is_wp_error($result)
		? array('type' => 'error', 'text' => $result->get_error_message())
		: array('type' => 'success', 'text' => 'The catalogue files are in place.'), MINUTE_IN_SECONDS);
	wp_safe_redirect(admin_url('options-general.php?page=unixpadel-static#upx-catalog'));
	exit;
}
add_action('admin_post_upx_catalog_fetch', 'upx_catalog_action');
