<?php
/**
 * One-time import of the build's own references (dist/upx-shell/migrate.json, src/pages/upx-shell/migrate.json.ts)
 * into WP admin → References: Settings → Unixpadel Static → Import. Every language's text comes from the built page
 * itself (its <article class="prose">), photos are matched to the media library (else kept as their /wp-content path).
 * The English URL of each imported reference is recorded in option upx_refs_imported; from then on the plugin never
 * serves the built copy again (refs-store.php upx_refs_intercept, refs-render.php upx_inject_listing). Undo moves the
 * imported references to the Trash and brings the built copies back.
 */

if (!defined('ABSPATH')) {
	exit;
}

/** migrate.json of this build, or null. */
function upx_refs_migrate_data() {
	$file = UPX_DIST . '/upx-shell/migrate.json';
	if (!is_file($file)) {
		return null;
	}
	$json = json_decode((string) file_get_contents($file), true);
	return is_array($json) && isset($json['contract'], $json['items']) && (int) $json['contract'] === UPX_CONTRACT ? $json : null;
}

/** Rendered body of a built reference page in one language, on one line (the editor's wpautop would add <br> otherwise). */
function upx_import_body($source, $lang) {
	$file = UPX_DIST . ($lang === 'en' ? '' : '/' . $lang) . $source . 'index.html';
	if (!is_file($file) || !preg_match('#<article class="prose">(.*?)</article>#s', (string) file_get_contents($file), $m)) {
		return '';
	}
	return trim((string) preg_replace('/\s*\n\s*/', ' ', $m[1]));
}

/** Media library attachment of an uploads path (size variants and -scaled originals included), or 0. */
function upx_import_attachment($path) {
	if (!preg_match('#/wp-content/uploads/(.+)$#', (string) $path, $m)) {
		return 0;
	}
	$uploads = wp_get_upload_dir();
	$base    = rtrim($uploads['baseurl'], '/') . '/';
	$plain   = preg_replace('/-\d+x\d+(\.\w+)$/', '$1', $m[1]);
	foreach (array_unique(array($m[1], $plain, preg_replace('/(\.\w+)$/', '-scaled$1', $plain))) as $try) {
		$id = attachment_url_to_postid($base . $try);
		if ($id && wp_attachment_is_image($id)) {
			return (int) $id;
		}
	}
	return 0;
}

/**
 * Imports every built reference not imported yet.
 *
 * @return array|WP_Error created, skipped, photos (matched to the media library), failed (source URLs)
 */
function upx_refs_import() {
	$migrate = upx_refs_migrate_data();
	if (!$migrate || !upx_data()) {
		return new WP_Error('upx_import', 'This plugin build has no import file (upx-shell/migrate.json) for its contract version.');
	}
	if (function_exists('set_time_limit')) {
		@set_time_limit(300);
	}
	// Claim the addresses first: slugs such as "oman" belong to built pages and would otherwise get "-2".
	$map = upx_refs_imported();
	foreach ($migrate['items'] as $it) {
		if (!isset($map[$it['source']])) {
			$map[$it['source']] = 0;
		}
	}
	update_option(UPX_OPT_IMPORTED, $map, true);
	remove_filter('wp_insert_post_data', 'upx_ref_require_fields', 10);

	$result = array('created' => 0, 'skipped' => 0, 'photos' => 0, 'failed' => array());
	$sort   = PHP_INT_MAX;
	foreach ($migrate['items'] as $it) {
		// The built list order is kept: each one sorts just below the one before it, at most at its own date.
		$date = $it['published'] ? (int) strtotime($it['published']) : time();
		$sort = min($date, $sort - 60);
		$src  = $it['source'];
		if (!empty($map[$src]) && get_post((int) $map[$src])) {
			$result['skipped']++;
			continue;
		}
		$en   = $it['langs']['en'];
		$body = upx_import_body($src, 'en');
		$gmt  = gmdate('Y-m-d H:i:s', $date);
		$id   = wp_insert_post(wp_slash(array(
			'post_type'     => UPX_REF_TYPE,
			'post_status'   => 'publish',
			'post_title'    => $en['title'],
			'post_name'     => $it['slug'],
			'post_content'  => $body,
			'post_excerpt'  => $en['excerpt'],
			'post_date'     => get_date_from_gmt($gmt),
			'post_date_gmt' => $gmt,
		)), true);
		if (is_wp_error($id) || !$id) {
			$result['failed'][] = $src;
			continue;
		}

		$thumb = $it['image'] ? upx_import_attachment($it['image']['src']) : 0;
		if ($thumb) {
			set_post_thumbnail($id, $thumb);
			$result['photos']++;
		}
		$gallery = array();
		foreach ($it['gallery'] as $g) {
			$att       = upx_import_attachment($g['src']);
			$gallery[] = $att ? $att : $g['src'];
		}
		update_post_meta($id, UPX_META_REF, wp_slash(array(
			'country'   => $it['country'],
			'types'     => array_slice((array) $it['types'], 0, 2),
			'seo_title' => $en['seoTitle'],
			'seo_desc'  => $en['seoDesc'],
			'gallery'   => $gallery,
			'kind'      => $it['kind'] === 'news' ? 'news' : 'project',
			'sort'      => $sort,
			'image_url' => !$thumb && $it['image'] ? $it['image']['src'] : '',
			'image_alt' => $it['image'] ? $it['image']['alt'] : '',
		)));
		$l10n = array();
		foreach ($it['langs'] as $lang => $f) {
			if ($lang === 'en') {
				continue;
			}
			$l10n[$lang] = array(
				'title'     => $f['title'],
				'excerpt'   => $f['excerpt'],
				'seo_title' => $f['seoTitle'],
				'seo_desc'  => $f['seoDesc'],
				'body'      => upx_import_body($src, $lang),
			);
		}
		update_post_meta($id, UPX_META_L10N, wp_slash($l10n));
		update_post_meta($id, '_upx_source', $src);
		$map[$src] = (int) $id;
		update_option(UPX_OPT_IMPORTED, $map, true);
		$result['created']++;
	}

	add_filter('wp_insert_post_data', 'upx_ref_require_fields', 10, 2);
	// Addresses of failed items go back to the build.
	foreach ($result['failed'] as $src) {
		unset($map[$src]);
	}
	update_option(UPX_OPT_IMPORTED, $map, true);
	upx_refs_rebuild();
	upx_purge_page_caches();
	return $result;
}

/** Undo: imported references go to the Trash and the built copies are served again. */
function upx_refs_import_undo() {
	$map = upx_refs_imported();
	$n   = 0;
	foreach ($map as $id) {
		if ($id && get_post((int) $id) && wp_trash_post((int) $id)) {
			$n++;
		}
	}
	delete_option(UPX_OPT_IMPORTED);
	upx_refs_rebuild();
	upx_purge_page_caches();
	return $n;
}

function upx_refs_import_action() {
	if (!current_user_can('manage_options')) {
		wp_die('Not allowed.', 403);
	}
	check_admin_referer('upx_refs_import');
	$undo   = isset($_POST['upx_undo']);
	$result = $undo ? upx_refs_import_undo() : upx_refs_import();
	if (is_wp_error($result)) {
		set_transient('upx_import_notice', array('error' => $result->get_error_message()), 300);
	} else {
		set_transient('upx_import_notice', $undo ? array('undone' => $result) : $result, 300);
	}
	wp_safe_redirect(admin_url('options-general.php?page=unixpadel-static#upx-import'));
	exit;
}
add_action('admin_post_upx_refs_import', 'upx_refs_import_action');

/** Import section of Settings → Unixpadel Static. */
function upx_refs_import_section() {
	$migrate = upx_refs_migrate_data();
	if (!$migrate) {
		return;
	}
	$map      = upx_refs_imported();
	$imported = count(array_filter($map));
	$total    = count($migrate['items']);
	$notice   = get_transient('upx_import_notice');
	delete_transient('upx_import_notice');
	?>
	<h2 id="upx-import">Built references → WP admin</h2>
	<?php if (is_array($notice)) : ?>
		<?php if (isset($notice['error'])) : ?>
			<div class="notice notice-error inline"><p><?php echo esc_html($notice['error']); ?></p></div>
		<?php elseif (isset($notice['undone'])) : ?>
			<div class="notice notice-success inline"><p><?php echo (int) $notice['undone']; ?> imported references moved to the Trash; the built copies are shown again.</p></div>
		<?php else : ?>
			<div class="notice notice-success inline"><p>
				<?php echo (int) $notice['created']; ?> imported, <?php echo (int) $notice['skipped']; ?> were already there.
				<?php echo (int) $notice['photos']; ?> main photos were found in the media library; the others keep showing their photo from its address (set a Main photo to replace it).
				<?php if ($notice['failed']) : ?><br>Not imported: <?php echo esc_html(implode(', ', $notice['failed'])); ?><?php endif; ?>
			</p></div>
		<?php endif; ?>
	<?php endif; ?>
	<p>This build has <?php echo (int) $total; ?> references of its own (projects and company news, all languages); <?php echo (int) $imported; ?> are in WP admin.
		Importing creates them under <a href="<?php echo esc_url(admin_url('edit.php?post_type=' . UPX_REF_TYPE)); ?>">References</a> with their text in every language, photos, country and court types.
		From then on they are edited there, and the built copies are no longer shown anywhere (the 8 references outside /references/ move to /references/&lt;name&gt;/ with a 301 from the old address).</p>
	<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline">
		<input type="hidden" name="action" value="upx_refs_import">
		<?php wp_nonce_field('upx_refs_import'); ?>
		<?php submit_button($imported ? 'Import the missing ones' : 'Import built references', $imported >= $total ? 'secondary' : 'primary', 'submit', false, $imported >= $total ? array('disabled' => 'disabled') : array()); ?>
	</form>
	<?php if ($map) : ?>
		<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;margin-left:8px" onsubmit="return confirm('Move the imported references to the Trash and show the built copies again? Changes made to them in WP admin are kept in the Trash.');">
			<input type="hidden" name="action" value="upx_refs_import">
			<input type="hidden" name="upx_undo" value="1">
			<?php wp_nonce_field('upx_refs_import'); ?>
			<?php submit_button('Undo import', 'secondary', 'submit', false); ?>
		</form>
	<?php endif; ?>
	<?php
}
