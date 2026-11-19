<?php
// Local check of the resize endpoint (includes/img-resize.php) in WordPress Playground:
// writes a 2400×1600 PNG into uploads, then the caller requests /?upx_img=upx-test/big.png&w=320.
require_once '/wordpress/wp-load.php';
$dir = wp_get_upload_dir()['basedir'] . '/upx-test';
wp_mkdir_p($dir);
$im = imagecreatetruecolor(2400, 1600);
for ($y = 0; $y < 1600; $y += 4) {
	imagefilledrectangle($im, 0, $y, 2399, $y + 3, imagecolorallocate($im, ($y * 7) % 256, 120, 200 - ($y % 200)));
}
imagepng($im, $dir . '/big.png');
header('Content-Type: text/plain');
echo 'png ', filesize($dir . '/big.png'), ' bytes; webp support ', wp_image_editor_supports(array('mime_type' => 'image/webp')) ? 'yes' : 'no';
