<?php
/**
 * Local check of wp-plugin/includes/catalog-pack.php (run by blueprint.json after the seed, WordPress Playground only):
 * unpacking keeps page files and nothing else, the "complete" mark and the clean-up of older folders work. The download
 * from GitHub is not exercised here (it needs the private repository's token). Result: /wp-content/uploads/upx-catalog-test.txt
 */

if (!defined('ABSPATH') || !function_exists('upx_catalog_unpack')) {
	exit('Load WordPress with the unixpadel-static plugin active first.');
}

require_once ABSPATH . 'wp-admin/includes/file.php'; // wp_tempnam()

$uploads = wp_upload_dir(null, false);
$report  = trailingslashit($uploads['basedir']) . 'upx-catalog-test.txt';
$out     = array();
try {
$note = function ($ok, $what) use (&$out) {
	$out[] = ($ok ? 'PASS' : 'FAIL') . '  ' . $what;
};

$manifest = upx_catalog_manifest();
$note((bool) $manifest, 'catalog.php is read and valid: dir ' . ($manifest ? $manifest['dir'] : '-') . ', ' . ($manifest ? count($manifest['parts']) : 0) . ' parts');
$note(!upx_catalog_ready(), 'not ready before any file is there');

$root = upx_catalog_root();
$dest = $root . '/' . ($manifest ? $manifest['dir'] : '0000000000000000');
$old  = $root . '/aaaaaaaaaaaaaaaa';
wp_mkdir_p($dest);
wp_mkdir_p($old);
file_put_contents($old . '/0123456789abcdef.webp', 'old');
file_put_contents($old . '/.ready', '1');

$zip_path = wp_tempnam('catalog-test.zip');
$zip      = new ZipArchive();
$zip->open($zip_path, ZipArchive::OVERWRITE);
$zip->addFromString('0123456789abcdef.webp', 'page one');
$zip->addFromString('fedcba9876543210.webp', 'page two');
$zip->addFromString('evil.php', '<?php echo 1;');
$zip->addFromString('../escape.webp', 'x');
$zip->addFromString('sub/0123456789abcdef.webp', 'x');
$zip->addFromString('0123456789ABCDEF.webp', 'x');
$zip->close();

$written = upx_catalog_unpack($zip_path, $dest);
@unlink($zip_path);
$note($written === 2, 'unpack wrote exactly the 2 page files (got ' . (is_wp_error($written) ? $written->get_error_message() : $written) . ')');
$note(file_get_contents($dest . '/0123456789abcdef.webp') === 'page one', 'file content intact');
$note(!file_exists($dest . '/evil.php') && !file_exists($root . '/escape.webp') && !is_dir($dest . '/sub'), 'nothing else from the ZIP was written');

$bad = wp_tempnam('catalog-bad.zip');
file_put_contents($bad, 'not a zip');
$note(is_wp_error(upx_catalog_unpack($bad, $dest)), 'a broken ZIP is refused');
@unlink($bad);

file_put_contents($dest . '/.ready', '1');
$note(upx_catalog_ready(), 'ready once the mark is there');
$done = upx_catalog_fetch(); // ready: no download, only the clean-up of older folders
$note($done === true && !is_dir($old), 'older edition folder removed, fetch returns true without downloading');
$note(is_dir($dest) && is_file($dest . '/fedcba9876543210.webp'), 'current folder untouched');

// Leave the site as a fresh install would be: nothing fetched yet.
upx_catalog_remove_dir($dest);
$note(!is_dir($dest), 'remove_dir empties and removes a folder');

} catch (Throwable $e) {
	$out[] = 'ERROR ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine();
}
file_put_contents($report, implode("\n", $out) . "\n");
